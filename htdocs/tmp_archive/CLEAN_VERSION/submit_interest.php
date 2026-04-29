<?php
// submit_interest.php - Обработчик формы "Интересует"
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$lot_id = (int)($input['lot_id'] ?? 0);
$name = trim($input['name'] ?? '');
$contact = trim($input['contact'] ?? '');
$method = in_array($input['method'] ?? '', ['email', 'telegram', 'phone']) 
          ? $input['method'] : 'email';
$want_inspection = !empty($input['want_inspection']);
$inspection_date = $want_inspection ? ($input['inspection_date'] ?? null) : null;

$user_id = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

if (!$lot_id || !$name || !$contact) {
    echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
    exit;
}

if ($want_inspection && !$inspection_date) {
    echo json_encode(['success' => false, 'error' => 'Выберите дату осмотра']);
    exit;
}

try {
    // Проверяем лот
    $stmt = $pdo->prepare("SELECT * FROM lots WHERE id = ? AND auction_type = 'commission'");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lot) {
        echo json_encode(['success' => false, 'error' => 'Товар не найден']);
        exit;
    }
    
    // Сохраняем заявку
    $stmt = $pdo->prepare("
        INSERT INTO commission_interests 
        (lot_id, user_id, name, contact_value, contact_method, 
         want_inspection, inspection_date, created_at, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'new')
    ");
    $stmt->execute([$lot_id, $user_id, $name, $contact, $method, 
                    $want_inspection ? 1 : 0, $inspection_date]);
    
    $interest_id = $pdo->lastInsertId();
    
    // Логируем
    if (function_exists('logAction')) {
        logAction($pdo, $user_id ?? 0, 'commission_interest', "Интерес к товару #{$lot_id}");
    }
    
    // TODO: Отправить уведомление продавцу
    
    echo json_encode([
        'success' => true,
        'interest_id' => $interest_id,
        'message' => 'Заявка принята'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
