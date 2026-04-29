<?php
session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user = $_POST['username'];
    $pass = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$user]);
    $res = $stmt->fetch();

    if ($res && password_verify($pass, $res['password'])) {
        $_SESSION['user_id'] = $res['id'];
        $_SESSION['username'] = $res['username'];
        // Возвращаемся на лот
        header("Location: lot_details.php?id=6");
        exit;
    } else {
        // Если неверно - кидаем обратно на лот с ошибкой для модалки
        header("Location: lot_details.php?id=6&auth=wrong");
        exit;
    }
}
?>