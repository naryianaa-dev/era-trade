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
                COUNT(b.id) AS real_bid_count,
                COALESCE(SUM(b.bid_cost), 0) AS total_bid_revenue
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
    
    // Определяем, начался ли аукцион
    $is_first_bid = empty($lot['started_at']) || $lot['started_at'] == '0000-00-00 00:00:00';
    
    // Для первой ставки устанавливаем начальное время end_time (таймер старта)
    if ($is_first_bid) {
        // Начальный таймер (обычно 240 секунд = 4 минуты)
        $timer_start = isset($lot['timer_start']) && $lot['timer_start'] > 0 ? (int)$lot['timer_start'] : 240;
        $initial_end_time = $now + $timer_start;
        $end_time_db = date('Y-m-d H:i:s', $initial_end_time);
    } else {
        $end_time_db = $lot['end_time'];
    }
    
    $end_ts = strtotime($end_time_db);
    $is_auction_ended = $end_ts <= $now;
    
    // Проверяем завершение аукциона
    if ($is_auction_ended && !$is_first_bid) {
        // ═══════════════════════════════════════════════════════════
        // АВТОМАТИЧЕСКОЕ НАЧИСЛЕНИЕ БОНУСА ПРИ ЗАВЕРШЕНИИ
        // ═══════════════════════════════════════════════════════════
        
        // Проверяем, не начислен ли уже бонус
        $check_bonus = $pdo->prepare("SELECT id FROM organizer_bonuses WHERE lot_id = ?");
        $check_bonus->execute([$lot_id]);
        
        if (!$check_bonus->fetch() && $lot['total_bid_revenue'] > 0) {
            // Начисляем бонус 15%
            $bonus_amount = round($lot['total_bid_revenue'] * 0.15, 2);
            $owner_id = (int)$lot['owner_id'];
            
            if ($owner_id > 0) {
                // Начисляем на баланс
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
                    ->execute([$bonus_amount, $owner_id]);
                
                // Записываем транзакцию
                $pdo->prepare(
                    "INSERT INTO transactions (user_id, amount, type, description, related_lot_id, created_at)
                     VALUES (?, ?, 'bonus', ?, ?, NOW())"
                )->execute([
                    $owner_id,
                    $bonus_amount,
                    "Бонус организатора 15% от аукциона №{$lot_id}",
                    $lot_id
                ]);
                
                // Записываем в историю бонусов
                $pdo->prepare(
                    "INSERT INTO organizer_bonuses (lot_id, organizer_id, revenue_amount, bonus_amount, created_at)
                     VALUES (?, ?, ?, ?, NOW())"
                )->execute([$lot_id, $owner_id, $lot['total_bid_revenue'], $bonus_amount]);
                
                error_log("Auto-bonus: Lot #{$lot_id} - {$bonus_amount} RUB to owner #{$owner_id}");
            }
        }
        
        // Обновляем статус лота
        $pdo->prepare("UPDATE lots SET auction_status = 'finished' WHERE id = ?")
            ->execute([$lot_id]);
        
        $pdo->commit();
        die(json_encode(['error'=>'ENDED','msg'=>'Торги завершены'], JSON_UNESCAPED_UNICODE));
    }
    
    if (!empty($lot['max_end_time']) && strtotime($lot['max_end_time']) <= $now) {
        $pdo->commit();
        die(json_encode(['error'=>'ENDED','msg'=>'Время торгов истекло'], JSON_UNESCAPED_UNICODE));
    }
    if (!empty($lot['auction_status']) && $lot['auction_status'] !== 'active') {
        $pdo->commit();
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
    
    // ══════════════════════════════════════════════════════════════
    // СТОИМОСТЬ СТАВКИ (задаётся организатором в bid_step_cost)
    // ══════════════════════════════════════════════════════════════
    $bid_step_cost = isset($lot['bid_step_cost']) && $lot['bid_step_cost'] > 0 
        ? (int)$lot['bid_step_cost'] 
        : ($user_type === 'responsible' ? 1990 : 2690);
    
    // ══════════════════════════════════════════════════════════════
    // ШАГ АУКЦИОНА (задаётся организатором)
    // ══════════════════════════════════════════════════════════════
    $start_price = isset($lot['start_price']) && $lot['start_price'] > 0
                   ? (float)$lot['start_price'] : (float)$lot['price'];
    
    if (isset($_POST['bid_step']) && $_POST['bid_step'] > 0) {
        $bid_step = (int)$_POST['bid_step'];
    } elseif (isset($lot['bid_step']) && $lot['bid_step'] > 0) {
        $bid_step = (int)$lot['bid_step'];
    } else {
        $bid_step = (int)round($start_price * 0.02); // 2% по умолчанию
    }

    // ══════════════════════════════════════════════════════════════
    // ЛОГИКА ОПЛАТЫ И РОСТА ЦЕНЫ
    // ══════════════════════════════════════════════════════════════
    
    $actual_cost = 0;
    $price_increase = $bid_step + $bid_step_cost; // Базовое увеличение
    $insufficient_balance = false;
    
    if ($payment_method === 'balance') {
        $actual_cost = $bid_step_cost;
        
        // Проверяем баланс
        if ((int)$user['balance'] < $actual_cost) {
            // НЕДОСТАТОЧНО СРЕДСТВ
            $insufficient_balance = true;
            
            // Дополнительно +10% от стоимости шага к цене лота
            $penalty = round($bid_step_cost * 0.10);
            $price_increase += $penalty;
            
            // Ставка всё равно проходит, но участник должен пополнить баланс
            $actual_cost = 0; // Не списываем, т.к. нет средств
        } else {
            // Списываем с баланса
            $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
                ->execute([$actual_cost, $user_id]);
        }

    } elseif ($payment_method === 'pack') {
        if ((int)$user['bid_pack_remaining'] <= 0) {
            $pdo->rollBack();
            die(json_encode(['error'=>'NO_PACK','msg'=>'Нет доступных ставок в пакете'], JSON_UNESCAPED_UNICODE));
        }
        
        $pack_prices = getPackPrice($user_type);
        $actual_cost = $pack_prices['per_bid'] ?? 1000;
        
        $pdo->prepare("UPDATE users SET bid_pack_remaining = bid_pack_remaining - 1 WHERE id = ?")
            ->execute([$user_id]);

    } elseif ($payment_method === 'cash') {
        $actual_cost = $bid_step_cost;
        // При безнале ставка принимается сразу, оплата отдельно
    }

    $new_bid_count = (int)$lot['real_bid_count'] + 1;
    $new_price = (int)$lot['price'] + $price_increase;

    // Продление таймера (для последующих ставок)
    if (!$is_first_bid) {
        $new_end_time = $now + (int)$lot['timer_add'];
        $new_end = date('Y-m-d H:i:s', $new_end_time);
    } else {
        $new_end = $end_time_db;
    }
    
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
        $penalty_str = $insufficient_balance ? " [!НЕДОСТАТОК БАЛАНСА +10%]" : "";
        file_put_contents(
            "logs/lot_{$lot_id}.txt",
            "[".date('d.m.Y H:i:s')."] {$uname} ставка #{$new_bid_count} → {$new_price} ₽ [шаг {$bid_step} ₽ + тариф {$bid_step_cost} ₽ / {$ml[$payment_method]}]{$penalty_str}\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Exception $e) {}

    // Обновлённые баланс/пакет
    $stmt_r = $pdo->prepare("SELECT balance, bid_pack_remaining FROM users WHERE id = ?");
    $stmt_r->execute([$user_id]);
    $updated = $stmt_r->fetch(PDO::FETCH_ASSOC);

    $total_bids_revenue = (float)$lot['total_bid_revenue'] + $actual_cost;

    $response = [
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
        'price_increase'     => $price_increase,
        'total_bids_revenue' => $total_bids_revenue,
        'is_first_bid'       => $is_first_bid,
    ];
    
    if ($insufficient_balance) {
        $response['warning'] = 'Недостаточно средств на балансе. Ставка принята с дополнительной надбавкой +10%. Пополните баланс через покупку пакета ставок.';
        $response['insufficient_balance'] = true;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("scandinavian_bid: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'SERVER','msg'=>'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}