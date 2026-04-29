<?php
// process_upgrade.php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$target_status = $_POST['target_status'] ?? '';
$express = !empty($_POST['express']);
$payment_method = $_POST['payment_method'] ?? 'qr';

// Проверка корректности статуса
if (!in_array($target_status, ['responsible', 'organizer'])) {
    echo json_encode(['success' => false, 'error' => 'Некорректный статус']);
    exit;
}

// Цены
$prices = [
    'responsible' => 8000,
    'organizer'   => 0,
];

$base_price = $prices[$target_status];
$express_fee = $express ? 7000 : 0;
$total = $base_price + $express_fee;

try {
    // Получаем данные пользователя
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
        exit;
    }
    
    // Создаём запись о запросе на повышение статуса
    $stmt = $pdo->prepare("
        INSERT INTO status_upgrades 
        (user_id, target_status, base_price, express_fee, total_amount, payment_method, requested_at, status)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending')
    ");
    $stmt->execute([
        $user_id,
        $target_status,
        $base_price,
        $express_fee,
        $total,
        $payment_method
    ]);
    
    $upgrade_id = $pdo->lastInsertId();
    
    // Обновляем пользователя
    $pdo->prepare("UPDATE users SET status_upgrade_requested_at = NOW() WHERE id = ?")
        ->execute([$user_id]);
    
    if ($payment_method === 'qr') {
        // Генерируем страницу с QR-кодом
        $qr_page_url = "upgrade_qr.php?id={$upgrade_id}";
        echo json_encode([
            'success' => true,
            'qr_page_url' => $qr_page_url,
            'upgrade_id' => $upgrade_id
        ]);
    } else {
        // Генерируем квитанцию
        $receipt_url = "upgrade_receipt.php?id={$upgrade_id}";
        echo json_encode([
            'success' => true,
            'receipt_url' => $receipt_url,
            'upgrade_id' => $upgrade_id
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
