<?php
// make_offer.php - Обработчик предложения цены
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
$offered_price = (float)($input['offered_price'] ?? 0);

if (!$lot_id || $offered_price <= 0) {
    echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
    exit;
}

try {
    // Проверяем лот
    $stmt = $pdo->prepare("SELECT * FROM lots WHERE id = ? AND auction_type = 'commission' AND auction_status != 'finished'");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lot) {
        echo json_encode(['success' => false, 'error' => 'Товар не найден или уже продан']);
        exit;
    }
    
    if ($lot['owner_id'] == $user_id) {
        echo json_encode(['success' => false, 'error' => 'Вы не можете делать предложение на свой товар']);
        exit;
    }
    
    // Сохраняем предложение
    $stmt = $pdo->prepare("
        INSERT INTO commission_offers (lot_id, user_id, offered_price, created_at, status)
        VALUES (?, ?, ?, NOW(), 'pending')
    ");
    $stmt->execute([$lot_id, $user_id, $offered_price]);
    
    $offer_id = $pdo->lastInsertId();
    
    // Логируем
    if (function_exists('logAction')) {
        logAction($pdo, $user_id, 'commission_offer', "Предложение {$offered_price} ₽ на товар #{$lot_id}");
    }
    
    // TODO: Отправить уведомление продавцу
    
    echo json_encode([
        'success' => true,
        'offer_id' => $offer_id,
        'message' => 'Предложение отправлено продавцу'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
