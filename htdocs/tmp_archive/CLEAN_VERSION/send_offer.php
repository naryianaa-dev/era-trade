<?php
session_start();
include 'db.php';

// Устанавливаем часовой пояс в PHP
date_default_timezone_set('Europe/Moscow');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['lot_id']) && isset($_SESSION['user_id'])) {
    
    $lot_id = (int)$_POST['lot_id'];
    $user_id = (int)$_SESSION['user_id'];
    $step = 10000; 

    try {
        // Устанавливаем часовой пояс в самой БД перед транзакцией
        $pdo->exec("SET time_zone = '+03:00'");

        $pdo->beginTransaction();

        // 1. Берем текущую цену (FOR UPDATE блокирует строку, чтобы никто другой не вклинился)
        $stmt = $pdo->prepare("SELECT price FROM lots WHERE id = ? FOR UPDATE");
        $stmt->execute([$lot_id]);
        $current_price = $stmt->fetchColumn();
        
        // Если лот не найден, прекращаем
        if ($current_price === false) {
            throw new Exception("Лот не найден");
        }

        $new_price = $current_price + $step;

        // 2. ОБНОВЛЯЕМ ЦЕНУ И ВРЕМЯ (Автопродление на 5 минут)
        // Используем NOW(), чтобы сервер базы сам поставил время
        $update_lot = $pdo->prepare("UPDATE lots SET 
            price = ?, 
            end_time = DATE_ADD(NOW(), INTERVAL 5 MINUTE) 
            WHERE id = ?");
        $update_lot->execute([$new_price, $lot_id]);

        // 3. Записываем ставку в историю
        // Добавляем bid_time = NOW(), чтобы в протоколе было четкое время
        $insert_bid = $pdo->prepare("INSERT INTO bids (lot_id, user_id, bid_amount, bid_time) VALUES (?, ?, ?, NOW())");
        $insert_bid->execute([$lot_id, $user_id, $new_price]);

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Ошибка: " . $e->getMessage());
    }

    // Возвращаем на страницу лота
    header("Location: lot_details.php?id=" . $lot_id);
    exit();
} else {
    // Если зашли просто так — на главную
    header("Location: index.php");
    exit();
}