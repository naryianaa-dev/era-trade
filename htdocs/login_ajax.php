<?php
// login_ajax.php - СОВМЕСТИМЫЙ С ВАШИМ auth_modal.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$action   = trim($_POST['action'] ?? 'login');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

function json_error($msg) {
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($msg = 'OK') {
    echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'login') {
    if (!$username || !$password) {
        json_error('Заполните все поля');
    }

    try {
        // добавили user_type в выборку
        $stmt = $pdo->prepare("
            SELECT id, username, password, ban_type, user_type 
            FROM users 
            WHERE username = ?
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            json_error('Неверный логин или пароль');
        }

        $valid = password_verify($password, $user['password']) || $password === $user['password'];
        if (!$valid) {
            json_error('Неверный логин или пароль');
        }

        if (!empty($user['ban_type']) && $user['ban_type'] === 'hard') {
            json_error('Ваш аккаунт заблокирован. Обратитесь в службу поддержки.');
        }

        // обновление старых паролей
        if ($password === $user['password'] && strpos($user['password'], '$2') !== 0) {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        // сессия
        $_SESSION['user_id']      = $user['id'];
        $_SESSION['user_name']    = $user['username'];
        $_SESSION['user_logged']  = $user['username'];
        $_SESSION['auth']         = true;

        // ТИП ПОЛЬЗОВАТЕЛЯ
        $_SESSION['usertype']     = $user['user_type'] ?? 'user';

        json_ok('Вход выполнен');
    } catch (Exception $e) {
        json_error('Ошибка базы данных');
    }
}

if ($action === 'register') {
    $email       = trim($_POST['email'] ?? '');
    $full_name   = trim($_POST['full_name'] ?? '');
    $entity_type = trim($_POST['entity_type'] ?? 'individual');

    if (!$username || !$password || !$email || !$full_name) {
        json_error('Заполните все поля');
    }

    if (strlen($password) < 6) {
        json_error('Пароль минимум 6 символов');
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            json_error('Логин уже занят');
        }

        // новый юзер по умолчанию user_type = 'user'
        $pdo->prepare("
            INSERT INTO users (username, email, password, full_name, entity_type, user_type, created_at) 
            VALUES (?, ?, ?, ?, ?, 'user', NOW())
        ")->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $full_name,
            $entity_type
        ]);

        json_ok('Регистрация успешна');
    } catch (Exception $e) {
        json_error('Ошибка регистрации');
    }
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    json_ok('Вы вышли из системы');
}

json_error('Неверное действие');
