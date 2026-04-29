<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод должен быть POST']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$user_type = $_POST['user_type'] ?? 'respected';
$express = isset($_POST['express_fee']) ? (int)$_POST['express_fee'] : 0;

if (!$username || !$full_name || !$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат email']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Пароль минимум 6 символов']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким логином или email уже существует']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('INSERT INTO users (username, full_name, email, password, user_type, balance) VALUES (?, ?, ?, ?, ?, ?)');
    $balance = 0;
    if (!$stmt->execute([$username, $full_name, $email, $hash, $user_type, $balance])) {
        throw new Exception('Не удалось создать пользователя');
    }

    $user_id = $pdo->lastInsertId();

    // Сохранить прикрепленные документы
    $uploadDir = __DIR__ . '/uploads/docs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    foreach (['r-file1','r-file2','r-file3'] as $index => $input) {
        if (!empty($_FILES[$input]['tmp_name'])) {
            $file = $_FILES[$input];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $target = $uploadDir . $user_id . '_' . ($index + 1) . '_' . basename($file['name']);
                move_uploaded_file($file['tmp_name'], $target);
            }
        }
    }

    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $full_name;
    $_SESSION['user_balance'] = $balance;

    echo json_encode(['success' => true, 'message' => 'Регистрация успешна', 'user_id' => $user_id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка регистрации: ' . $e->getMessage()]);
}
