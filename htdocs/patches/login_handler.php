<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод должен быть POST']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (!$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'Заполните все поля']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_logged'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'] ?: $user['username'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_balance'] = $user['balance'] ?? 0;

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Неверный логин или пароль']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка базы: ' . $e->getMessage()]);
}
