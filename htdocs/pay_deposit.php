<?php
session_start();
require_once 'db.php';

// Если сессия слетела — подхватываем Dealer1
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 2;
}

$lot_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];

if ($lot_id > 0) {
    try {
        // Простейшая вставка без лишних колонок
        // Если запись уже есть, INSERT IGNORE её просто пропустит
        $stmt = $pdo->prepare("INSERT IGNORE INTO lot_participants (lot_id, user_id) VALUES (?, ?)");
        $stmt->execute([$lot_id, $user_id]);
        
        // Сразу летим обратно в лот
        header("Location: lot_details.php?id=$lot_id");
        exit();
    } catch (Exception $e) {
        die("Ошибка базы: " . $e->getMessage());
    }
} else {
    die("ID лота не получен");
}