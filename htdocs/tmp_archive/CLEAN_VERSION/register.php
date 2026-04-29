<?php
require 'db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    if (!empty($user) && !empty($pass)) {
        // Хешируем пароль для безопасности
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        
        try {
            // Указываем роль явно, чтобы база не ругалась
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')");
            if ($stmt->execute([$user, $hash])) {
                $_SESSION['user_id'] = $pdo->lastInsertId();
                header("Location: index.php"); // Укажи свою главную страницу
                exit;
            }
        } catch (PDOException $e) {
            die("Ошибка регистрации: " . $e->getMessage());
        }
    }
}
?>