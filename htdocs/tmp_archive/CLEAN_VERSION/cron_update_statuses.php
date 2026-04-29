<?php
// cron_update_statuses.php - Обновление статусов через cron (альтернатива MySQL событиям)
// Вызывать каждые 5 минут через cron или веб-запрос

// Проверка токена безопасности (опционально)
$token = $_GET['token'] ?? '';
$expected_token = 'your_secret_token_here'; // Измените на свой секретный токен

if ($token !== $expected_token) {
    die('Unauthorized');
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

try {
    // 1. Обновляем статусы лотов
    $pdo->exec("CALL update_all_lot_statuses()");
    
    // 2. Истекаем бронирования
    $pdo->exec("CALL expire_reservations()");
    
    // Логируем успех
    $log = date('Y-m-d H:i:s') . " - Status update completed\n";
    file_put_contents(__DIR__ . '/logs/cron.log', $log, FILE_APPEND);
    
    echo json_encode([
        'success' => true,
        'message' => 'Statuses updated successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Логируем ошибку
    $error = date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    file_put_contents(__DIR__ . '/logs/cron.log', $error, FILE_APPEND);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
