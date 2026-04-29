<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

include 'db.php';
include 'bid_config.php';
require_once 'finances.php';
date_default_timezone_set('Europe/Moscow');

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error'=>'SESSION_EXPIRED','msg'=>'Сессия истекла']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error'=>'METHOD','msg'=>'Только POST']));
}

$lot_id         = (int)($_POST['lot_id'] ?? 0);
$payment_method = $_POST['payment_method'] ?? 'cash';
$user_id        = (int)$_SESSION['user_id'];

if ($lot_id <= 0 || !in_array($payment_method, ['balance','cash','pack'])) {
    die(json_encode(['error'=>'INVALID','msg'=>'Некорректные данные']));
}

$ban = checkUserBan($pdo, $user_id);
if (!empty($ban['banned']) && $ban['type'] === 'hard') {
    die(json_encode(['error'=>'BANNED','msg'=>'Аккаунт заблокирован']));
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT l.*, COUNT(b.id) AS real_bid_count
        FROM lots l
        LEFT JOIN bids b ON b.lot_id = l.id
        WHERE l.id = ? FOR UPDATE
    ");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot || $lot['auction_type'] !== 'scandinavian') {
        throw new Exception('Неверный лот');
    }

    $now = time();
    $end_ts = strtotime($lot['end_time']);
    $max_end_ts = !empty($lot['max_end_time']) ? strtotime($lot['max_end_time']) : 0;
    if ($end_ts <= $now || ($max_end_ts && $max_end_ts <= $now)) {
        if ($lot['auction_status'] === 'active') {
            $total = $pdo->prepare("SELECT SUM(bid_cost) FROM bids WHERE lot_id = ?");
            $total->execute([$lot_id]);
            $revenue = (float)$total->fetchColumn();
            if ($revenue > 0) {
                $bonus = round($revenue * 0.15, 2);
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$bonus, $lot['owner_id']]);
            }
            $pdo->prepare("UPDATE lots SET auction_status = 'finished' WHERE id = ?")->execute([$lot_id]);
        }
        $pdo->commit();
        die(json_encode(['error'=>'ENDED','msg'=>'Торги завершены']));
    }

    /*
     * САМОПЕРЕБОЙ В СКАНДИНАВСКОМ ЗАПРЕЩЁН — это намеренная логика, не баг.
     *
     * Если текущий лидер (last_bid_user) попробует поставить ещё раз, он бы
     * платил тариф (1990/2690 ₽) за ставку, которая ничего не меняет —
     * он и так лидирует. Это очевидное вытягивание денег, поэтому платформа
     * блокирует такой сценарий.
     *
     * ВАЖНО: в ЗАКРЫТОМ аукционе самоперебой РАЗРЕШЁН (см. send_closed_bid.php),
     * там тарифа за ставку нет и участник может повышать свою же цену в режиме
     * реального времени. Не переносите эту проверку в send_closed_bid.php
     * без явной отмены спецификации от организатора.
     */
    if ((int)$lot['last_bid_user'] === $user_id) {
        $pdo->rollBack();
        die(json_encode(['error'=>'OWN_BID','msg'=>'Вы уже лидируете — нельзя перебить свою ставку']));
    }

    $stmt_u = $pdo->prepare("SELECT id, balance, user_type, bid_pack_remaining, credit_bids_remaining FROM users WHERE id = ? FOR UPDATE");
    $stmt_u->execute([$user_id]);
    $user = $stmt_u->fetch();
    if (!$user) throw new Exception('Пользователь не найден');

    $user_type = $user['user_type'] ?? 'respected';
    $tariff = ($user_type === 'responsible') ? 1990 : 2690;
    $bid_step_amount = isset($lot['bid_step']) && $lot['bid_step'] > 0 ? (int)$lot['bid_step'] : (int)round((float)$lot['start_price'] * 0.02);
    $bid_step_cost = $tariff;

    if ($payment_method === 'cash') {
        $actual_cost = $bid_step_cost;
    } elseif ($payment_method === 'balance') {
        $actual_cost = $bid_step_cost;
    } else {
        $pack_prices = getPackPrice($user_type);
        $actual_cost = $pack_prices['per_bid'] ?? 1868;
    }

    $penalty = 0;
    $new_balance = $user['balance'];

    if ($payment_method === 'balance') {
        if ($user['balance'] >= $actual_cost) {
            $new_balance = $user['balance'] - $actual_cost;
            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$new_balance, $user_id]);
        } else {
            if ($user['credit_bids_remaining'] > 0) {
                $penalty_percent = ($user_type === 'responsible') ? 10 : 15;
                $penalty = (int)round($bid_step_amount * $penalty_percent / 100);
                $new_balance = $user['balance'] - $actual_cost;
                $pdo->prepare("UPDATE users SET credit_bids_remaining = credit_bids_remaining - 1, balance = ? WHERE id = ?")
                    ->execute([$new_balance, $user_id]);
            } else {
                $pdo->rollBack();
                die(json_encode(['error'=>'NO_CREDIT','msg'=>'Лимит долговых ставок исчерпан']));
            }
        }
    } elseif ($payment_method === 'pack') {
        if ($user['bid_pack_remaining'] <= 0) {
            $pdo->rollBack();
            die(json_encode(['error'=>'NO_PACK','msg'=>'Нет доступных пакетов. Купите пакет ставок.']));
        }
        $pdo->prepare("UPDATE users SET bid_pack_remaining = bid_pack_remaining - 1 WHERE id = ?")->execute([$user_id]);
    }

    $new_bid_count = (int)$lot['real_bid_count'] + 1;
    $new_price = (int)$lot['price'] + $bid_step_amount + $bid_step_cost + $penalty;
    $new_end = date('Y-m-d H:i:s', $now + (int)$lot['timer_add']);
    if ($max_end_ts && strtotime($new_end) > $max_end_ts) $new_end = date('Y-m-d H:i:s', $max_end_ts);

    $started_at = $lot['started_at'];
    if ($new_bid_count == 1 && empty($lot['started_at'])) {
        $started_at = date('Y-m-d H:i:s', $now);
    }
    $pdo->prepare("UPDATE lots SET price = ?, last_bid_user = ?, end_time = ?, started_at = ?, total_bids = total_bids + 1 WHERE id = ?")
        ->execute([$new_price, $user_id, $new_end, $started_at, $lot_id]);

    $pdo->prepare("INSERT INTO bids (lot_id, user_id, bid_amount, bid_cost, payment_method, bid_time, penalty_amount)
                   VALUES (?,?,?,?,?,NOW(),?)")
        ->execute([$lot_id, $user_id, $new_price, $actual_cost, $payment_method, $penalty]);

    @unlink(__DIR__ . "/start_time_lot_{$lot_id}.txt");

    $pdo->commit();

    $stmt_n = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt_n->execute([$user_id]);
    $uname = $stmt_n->fetchColumn() ?: "user_{$user_id}";
    $uname = preg_replace('/[^\w\-а-яёА-ЯЁ ]/u', '', $uname);
    if (!is_dir('logs')) mkdir('logs', 0755, true);
    $ml = ['balance'=>'баланс','cash'=>'безнал','pack'=>'пакет'];
    $penalty_log = $penalty ? " +штраф {$penalty}₽" : "";
    file_put_contents(
        "logs/lot_{$lot_id}.txt",
        "[".date('d.m.Y H:i:s')."] {$uname} ставка #{$new_bid_count} → {$new_price} ₽ [шаг {$bid_step_amount}₽ + тариф {$bid_step_cost}₽{$penalty_log} / {$ml[$payment_method]}]\n",
        FILE_APPEND | LOCK_EX
    );

    $stmt_r = $pdo->prepare("SELECT balance, bid_pack_remaining, credit_bids_remaining FROM users WHERE id = ?");
    $stmt_r->execute([$user_id]);
    $updated = $stmt_r->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'            => true,
        'pending'            => ($payment_method === 'cash'),
        'msg'                => $penalty ? "Ставка принята со штрафом +{$penalty} ₽" : 'Ставка принята!',
        'new_price'          => $new_price,
        'bid_count'          => $new_bid_count,
        'end_ms'             => strtotime($new_end) * 1000,
        'bid_cost'           => $actual_cost,
        'balance'            => (int)$updated['balance'],
        'pack_remaining'     => (int)$updated['bid_pack_remaining'],
        'credit_bids_left'   => (int)$updated['credit_bids_remaining'],
        'penalty'            => $penalty,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("scandinavian_bid: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'SERVER','msg'=>'Ошибка сервера']);
}