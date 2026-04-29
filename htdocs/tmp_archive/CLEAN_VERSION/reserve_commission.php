<?php
// reserve_commission.php - Обработчик бронирования
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

if (!$lot_id) {
    echo json_encode(['success' => false, 'error' => 'Некорректный ID товара']);
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
        echo json_encode(['success' => false, 'error' => 'Вы не можете забронировать свой товар']);
        exit;
    }
    
    // Проверяем, не забронирован ли уже
    $stmt = $pdo->prepare("
        SELECT * FROM commission_reservations 
        WHERE lot_id = ? AND status = 'active' AND expires_at > NOW()
    ");
    $stmt->execute([$lot_id]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Товар уже забронирован другим покупателем']);
        exit;
    }
    
    // Создаём бронь на 24 часа
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $stmt = $pdo->prepare("
        INSERT INTO commission_reservations (lot_id, user_id, created_at, expires_at, status)
        VALUES (?, ?, NOW(), ?, 'active')
    ");
    $stmt->execute([$lot_id, $user_id, $expires_at]);
    
    $reservation_id = $pdo->lastInsertId();
    
    // Обновляем статус лота
    $pdo->prepare("UPDATE lots SET auction_status = 'reserved' WHERE id = ?")
        ->execute([$lot_id]);
    
    // Логируем
    if (function_exists('logAction')) {
        logAction($pdo, $user_id, 'commission_reserve', "Бронирование товара #{$lot_id}");
    }
    
    echo json_encode([
        'success' => true,
        'reservation_id' => $reservation_id,
        'expires_at' => $expires_at,
        'message' => 'Товар забронирован на 24 часа'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
