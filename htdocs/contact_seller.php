<?php
// contact_seller.php - Обработчик формы связи с продавцом
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Необходима авторизация']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$lot_id = (int)($input['lot_id'] ?? 0);
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$message = trim($input['message'] ?? '');

if (!$lot_id || !$name || !$email || !$message) {
    echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный email']);
    exit;
}

try {
    // Проверяем лот и получаем данные продавца
    $stmt = $pdo->prepare("
        SELECT l.*, u.username AS seller_name, u.email AS seller_email
        FROM lots l
        JOIN users u ON u.id = l.owner_id
        WHERE l.id = ? AND l.auction_type = 'commission'
    ");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lot) {
        echo json_encode(['success' => false, 'error' => 'Товар не найден']);
        exit;
    }
    
    if ($lot['owner_id'] == $user_id) {
        echo json_encode(['success' => false, 'error' => 'Вы не можете написать самому себе']);
        exit;
    }
    
    // Сохраняем сообщение
    $stmt = $pdo->prepare("
        INSERT INTO seller_messages 
        (lot_id, sender_id, seller_id, sender_name, sender_email, message, created_at, status)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 'unread')
    ");
    $stmt->execute([
        $lot_id,
        $user_id,
        $lot['owner_id'],
        $name,
        $email,
        $message
    ]);
    
    $message_id = $pdo->lastInsertId();
    
    // Логируем
    if (function_exists('logAction')) {
        logAction($pdo, $user_id, 'seller_message', "Сообщение продавцу по товару #{$lot_id}");
    }
    
    // TODO: Отправить email продавцу
    // mail($lot['seller_email'], "Новое сообщение по товару: {$lot['title']}", ...);
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'message' => 'Сообщение отправлено'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
