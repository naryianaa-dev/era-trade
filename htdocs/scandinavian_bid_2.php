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
    die(json_encode(['error'=>'SESSION_EXPIRED','msg'=>'Сессия истекла'], JSON_UNESCAPED_UNICODE));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error'=>'METHOD','msg'=>'Только POST'], JSON_UNESCAPED_UNICODE));
}

$lot_id         = isset($_POST['lot_id'])         ? (int)$_POST['lot_id']         : 0;
$payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'cash';
$user_id        = (int)$_SESSION['user_id'];

if ($lot_id <= 0 || !in_array($payment_method, ['balance','cash','pack'], true)) {
    http_response_code(400);
    die(json_encode(['error'=>'INVALID','msg'=>'Некорректные данные'], JSON_UNESCAPED_UNICODE));
}

// ── Проверка бана ─────────────────────────────────────
$ban = checkUserBan($pdo, $user_id);

if (!empty($ban['banned']) && $ban['type'] === 'hard') {
    http_response_code(403);
    die(json_encode(['error'=>'BANNED','msg'=>'Ваш аккаунт заблокирован'], JSON_UNESCAPED_UNICODE));
}

$soft_restricted = !empty($ban['soft_restricted']) || (!empty($ban['banned']) && $ban['type'] === 'soft');
if ($soft_restricted) {
    $bid_limit  = isset($ban['soft_bid_limit'])  ? (int)$ban['soft_bid_limit']  : null;
    $dead_msg   = $ban['soft_ban_msg'] ?? 'Проверьте соединение с интернетом';

    if ($bid_limit !== null) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM bids WHERE lot_id=? AND user_id=?");
        $s->execute([$lot_id, $user_id]);
        $user_bids_count = (int)$s->fetchColumn();

        if ($user_bids_count >= $bid_limit) {
            die(json_encode([
                'error' => 'SOFT_DEAD',
                'msg'   => $dead_msg,
                'dead'  => true,
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

try {
    $pdo->beginTransaction();

    // Лот с блокировкой
    $stmt = $pdo->prepare(
        "SELECT l.*, 
                COUNT(b.id)                 AS real_bid_count,
                COALESCE(SUM(b.bid_cost),0) AS total_bid_revenue
         FROM lots l
         LEFT JOIN bids b ON b.lot_id = l.id
         WHERE l.id = ?
         FOR UPDATE"
    );
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lot) {
        $pdo->rollBack();
        die(json_encode(['error'=>'NOT_FOUND','msg'=>'Лот не найден'], JSON_UNESCAPED_UNICODE));
    }
    if ($lot['auction_type'] !== 'scandinavian') {
        $pdo->rollBack();
        die(json_encode(['error'=>'WRONG_TYPE','msg'=>'Не скандинавский аукцион'], JSON_UNESCAPED_UNICODE));
    }

    $now = time();
    if (strtotime($lot['end_time']) <= $now) {
        $pdo->rollBack();
        die(json_encode(['error'=>'ENDED','msg'=>'Торги завершены'], JSON_UNESCAPED_UNICODE));
    }
    if (!empty($lot['max_end_time']) && strtotime($lot['max_end_time']) <= $now) {
        $pdo->rollBack();
        die(json_encode(['error'=>'ENDED','msg'=>'Время торгов истекло'], JSON_UNESCAPED_UNICODE));
    }
    if (!empty($lot['auction_status']) && $lot['auction_status'] !== 'active') {
        $pdo->rollBack();
        die(json_encode(['error'=>'ENDED','msg'=>'Торги завершены'], JSON_UNESCAPED_UNICODE));
    }

    // Пользователь
    $stmt_u = $pdo->prepare("SELECT id, balance, user_type, bid_pack_remaining FROM users WHERE id = ? FOR UPDATE");
    $stmt_u->execute([$user_id]);
    $user = $stmt_u->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $pdo->rollBack();
        die(json_encode(['error'=>'USER_NOT_FOUND','msg'=>'Пользователь не найден'], JSON_UNESCAPED_UNICODE));
    }

    $user_type = $user['user_type'] ?? 'respected';
    
    // ── ПРАВИЛЬНЫЕ ТАРИФЫ ДЛЯ СКАНДИНАВСКИХ АУКЦИОНОВ ───
    // Для "cash" (безналичные) - это тариф за ставку
    $tariff_prices = [
        'respected'   => 2690,  // Уважаемый
        'responsible' => 1990   // Ответственный
    ];
    
    // Определяем стоимость ставки
    if ($payment_method === 'cash') {
        $actual_cost = $tariff_prices[$user_type] ?? 2690;
    } elseif ($payment_method === 'balance') {
        // При оплате с баланса используем стандартные цены
        $actual_cost = getBidCost($user_type, 'balance');
    } elseif ($payment_method === 'pack') {
        // При использовании пакета используем цену пакета
        $pack_prices = getPackPrice($user_type);
        $actual_cost = $pack_prices['per_bid'] ?? 1000;
    }

    // ── Оплата за ставку ───────────────────────────────
    if ($payment_method === 'balance') {
        if ((int)$user['balance'] < $actual_cost) {
            $pdo->rollBack();
            die(json_encode([
                'error'   => 'NO_BALANCE',
                'msg'     => 'Недостаточно средств. Нужно '.number_format($actual_cost,0,'.',' ').' ₽',
                'balance' => (int)$user['balance'],
                'need'    => $actual_cost,
            ], JSON_UNESCAPED_UNICODE));
        }
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
            ->execute([$actual_cost, $user_id]);

    } elseif ($payment_method === 'pack') {
        if ((int)$user['bid_pack_remaining'] <= 0) {
            $pdo->rollBack();
            die(json_encode(['error'=>'NO_PACK','msg'=>'Нет доступных ставок в пакете'], JSON_UNESCAPED_UNICODE));
        }
        $pdo->prepare("UPDATE users SET bid_pack_remaining = bid_pack_remaining - 1 WHERE id = ?")
            ->execute([$user_id]);

    } elseif ($payment_method === 'cash') {
        // При безналичной оплате ставка принимается сразу
        // Оплата будет происходить отдельно
    }

    // ── Шаг аукциона ───────────────────────────────────
    $start_price = isset($lot['start_price']) && $lot['start_price'] > 0
                   ? (float)$lot['start_price'] : (float)$lot['price'];
    $step_min = (int)round($start_price * 0.005);
    $step_max = (int)round($start_price * 0.05);

    if (isset($_POST['bid_step'])) {
        $bid_step = max($step_min, min($step_max, (int)$_POST['bid_step']));
    } else {
        $bid_step = isset($lot['bid_step']) && $lot['bid_step'] > 0
                    ? (int)$lot['bid_step']
                    : (int)round($start_price * 0.02);
    }

    $new_bid_count = (int)$lot['real_bid_count'] + 1;

    // ── ПРАВИЛЬНАЯ ФОРМУЛА: Цена растёт на ШАГ + ТАРИФ ──
    // Если оплата с баланса, то используем тариф для "cash"
    $tariff_for_price = $tariff_prices[$user_type] ?? 2690;
    $new_price = (int)$lot['price'] + $bid_step + $tariff_for_price;

    // Продление таймера
    $new_end    = date('Y-m-d H:i:s', $now + (int)$lot['timer_add']);
    $started_at = empty($lot['started_at']) ? date('Y-m-d H:i:s', $now) : $lot['started_at'];

    if (!empty($lot['max_end_time']) && strtotime($new_end) > strtotime($lot['max_end_time'])) {
        $new_end = $lot['max_end_time'];
    }

    // Обновляем лот
    $pdo->prepare(
        "UPDATE lots
         SET price = ?, last_bid_user = ?, end_time = ?, started_at = ?, total_bids = total_bids + 1, bid_step = ?
         WHERE id = ?"
    )->execute([$new_price, $user_id, $new_end, $started_at, $bid_step, $lot_id]);

    // Пишем ставку
    $pdo->prepare(
        "INSERT INTO bids (lot_id, user_id, bid_amount, bid_cost, payment_method, bid_time)
         VALUES (?,?,?,?,?,NOW())"
    )->execute([$lot_id, $user_id, $new_price, $actual_cost, $payment_method]);

    $pdo->commit();

    // Лог в файл
    try {
        $stmt_n = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt_n->execute([$user_id]);
        $uname = preg_replace('/[^\w\-а-яёА-ЯЁ ]/u', '', $stmt_n->fetchColumn() ?: "user_{$user_id}");
        if (!is_dir('logs')) mkdir('logs', 0755, true);
        $ml = ['balance'=>'баланс','cash'=>'безнал','pack'=>'пакет'];
        file_put_contents(
            "logs/lot_{$lot_id}.txt",
            "[".date('d.m.Y H:i:s')."] {$uname} ставка #{$new_bid_count} → {$new_price} ₽ [шаг {$bid_step} ₽ + тариф {$tariff_for_price} ₽ / {$ml[$payment_method]}]\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Exception $e) {}

    // Обновлённые баланс/пакет
    $stmt_r = $pdo->prepare("SELECT balance, bid_pack_remaining FROM users WHERE id = ?");
    $stmt_r->execute([$user_id]);
    $updated = $stmt_r->fetch(PDO::FETCH_ASSOC);

    // Итоговая сумма ставок S = total_bid_revenue + текущая оплата
    $total_bids_revenue = (float)$lot['total_bid_revenue'] + $actual_cost;

    echo json_encode([
        'success'            => true,
        'pending'            => ($payment_method === 'cash'),
        'msg'                => 'Ставка принята!',
        'new_price'          => $new_price,
        'bid_count'          => $new_bid_count,
        'end_ms'             => strtotime($new_end) * 1000,
        'bid_cost'           => $actual_cost,
        'bid_step'           => $bid_step,
        'balance'            => (int)$updated['balance'],
        'pack_remaining'     => (int)$updated['bid_pack_remaining'],
        'price_increase'     => $bid_step + $tariff_for_price,
        'total_bids_revenue' => $total_bids_revenue,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("scandinavian_bid: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'SERVER','msg'=>'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
