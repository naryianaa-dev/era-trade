<?php
// reg_handler.php - Обработчик регистрации с поддержкой новых полей
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$full_name   = trim($input['full_name'] ?? '');
$entity_type = in_array($input['entity_type'] ?? '', ['individual', 'legal']) 
               ? $input['entity_type'] : 'individual';
$username    = trim($input['username'] ?? '');
$email       = trim($input['email'] ?? '');
$password    = $input['password'] ?? '';

// Валидация
if (!$full_name) {
    echo json_encode(['success' => false, 'error' => 'Введите ФИО / Наименование']);
    exit;
}

if (!$username || !$email || !$password) {
    echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
    exit;
}

if (strlen($username) < 3) {
    echo json_encode(['success' => false, 'error' => 'Логин должен быть не менее 3 символов']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    echo json_encode(['success' => false, 'error' => 'Логин может содержать только буквы, цифры и _']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный email']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'error' => 'Пароль должен быть не менее 6 символов']);
    exit;
}

try {
    // Проверка на существование пользователя
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Пользователь с таким логином или email уже существует']);
        exit;
    }
    
    // Хешируем пароль
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Создаём пользователя
    $stmt = $pdo->prepare("
        INSERT INTO users 
        (username, email, password, full_name, entity_type, user_type, balance, created_at)
        VALUES (?, ?, ?, ?, ?, 'respected', 0, NOW())
    ");
    
    $stmt->execute([
        $username,
        $email,
        $password_hash,
        $full_name,
        $entity_type
    ]);
    
    $user_id = $pdo->lastInsertId();
    
    // Логируем действие
    if (function_exists('logAction')) {
        logAction($pdo, $user_id, 'registration', "Регистрация пользователя {$username}");
    }
    
    // Автоматически авторизуем пользователя
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $username;
    $_SESSION['user_type'] = 'respected';
    
    echo json_encode([
        'success' => true,
        'message' => 'Регистрация прошла успешно',
        'user_id' => $user_id
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}
