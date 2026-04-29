<?php
include 'db.php';

// Устанавливаем заголовок, что это JSON, а не просто текст
header('Content-Type: application/json');

try {
    // Вытаскиваем все курсы из таблицы
    $stmt = $pdo->query("SELECT currency_code, rate_to_rub FROM exchange_rates");
    $rates = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Сделает массив вида ['USD' => 91.5, 'AMD' => 0.23]

    echo json_encode([
        'status' => 'success',
        'data' => $rates
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}