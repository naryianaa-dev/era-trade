<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $lot_id = (int)$_POST['lot_id'];
    $user_id = (int)$_SESSION['user_id'];
    $step = 1000;

    /* Проверка бана (hard/soft). */
    require_once 'finances.php';
    $_ban = checkUserBan($pdo, $user_id);
    if (!empty($_ban['banned'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        $reason = $_ban['reason'] ?? '';
        if (($_ban['type'] ?? '') === 'hard') {
            die("BANNED: " . ($reason ?: 'Ваш аккаунт заблокирован'));
        }
        $until = !empty($_ban['ban_until']) ? ' (до ' . $_ban['ban_until'] . ')' : '';
        die("BANNED_SOFT: " . ($reason ?: 'Временное ограничение') . $until);
    } 

    try {
        $pdo->beginTransaction();

        // 1. Узнаем текущую макс. ставку
        $stmt = $pdo->prepare("SELECT MAX(bid_amount) FROM bids WHERE lot_id = ? FOR UPDATE");
        $stmt->execute([$lot_id]);
        $current_max = $stmt->fetchColumn() ?: 1000000;
        $new_bid = $current_max + $step;

        // 2. Записываем ставку в протокол
        $insert = $pdo->prepare("INSERT INTO bids (lot_id, user_id, bid_amount, bid_time) VALUES (?, ?, ?, NOW())");
        $insert->execute([$lot_id, $user_id, $new_bid]);

        // 3. ОБНОВЛЯЕМ ЦЕНУ И ПРОДЛЕВАЕМ ТАЙМЕР НА 4 МИНУТЫ
        // Используем DATE_ADD(NOW(), INTERVAL 4 MINUTE)
        $update_lot = $pdo->prepare("
            UPDATE lots 
            SET price = ?, 
                end_time = DATE_ADD(NOW(), INTERVAL 4 MINUTE) 
            WHERE id = ?
        ");
        $update_lot->execute([$new_bid, $lot_id]);

        $pdo->commit();
        
        header("Location: lot_details.php?id=" . $lot_id);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("Ошибка: " . $e->getMessage());
    }
}