<?php
// login_ajax.php - ИСПРАВЛЕННАЯ ВЕРСИЯ
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (!$username || !$password) {
    echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, username, password, ban_type FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Неверный логин или пароль']);
        exit;
    }
    
    // Проверка пароля
    $valid = password_verify($password, $user['password']) || $password === $user['password'];
    
    if (!$valid) {
        echo json_encode(['success' => false, 'error' => 'Неверный логин или пароль']);
        exit;
    }
    
    // Проверка бана
    if ($user['ban_type'] === 'hard') {
        echo json_encode(['success' => false, 'error' => 'Ваш аккаунт заблокирован']);
        exit;
    }
    
    // Обновляем старый пароль на хешированный
    if ($password === $user['password'] && strpos($user['password'], '$2') !== 0) {
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    
    // Устанавливаем сессию
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_logged'] = $user['username'];
    $_SESSION['auth'] = true;
    
    echo json_encode([
        'success' => true, 
        'message' => 'Вход выполнен успешно!'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
