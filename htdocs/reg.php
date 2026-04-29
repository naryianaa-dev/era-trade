<?php
session_start();
if (isset($_SESSION['user'])) { header("Location: reestr.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_file = 'users.json';
    $users = json_decode(file_get_contents($user_file), true);
    
    $login = trim($_POST['username']);
    $pass = $_POST['password'];
    
    // Проверяем, нет ли уже такого логина
    foreach ($users as $u) {
        if ($u['username'] === $login) {
            $error = "Этот логин уже занят!";
            break;
        }
    }
    
    if (!isset($error)) {
        $new_id = count($users) + 1;
        $users[$new_id] = [
            "username" => $login,
            "password" => $pass,
            "role" => "user",
            "balance" => 0
        ];
        file_put_contents($user_file, json_encode($users, JSON_UNESCAPED_UNICODE));
        header("Location: index.php?reg_success=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация — ФОРСАЖ</title>
    <style>
        body { background: #0f172a; color: white; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .reg-card { background: #1e293b; padding: 40px; border-radius: 15px; border: 1px solid #334155; width: 300px; text-align: center; }
        input { width: 100%; padding: 12px; margin: 10px 0; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: white; box-sizing: border-box; }
        .btn { width: 100%; padding: 12px; background: #38bdf8; color: #0f172a; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
    <div class="reg-card">
        <h2 style="color: #38bdf8;">ФОРСАЖ</h2>
        <p>Создание аккаунта</p>
        <?php if(isset($error)) echo "<p style='color:#ef4444'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Придумайте логин" required>
            <input type="password" name="password" placeholder="Придумайте пароль" required>
            <button type="submit" class="btn">ЗАРЕГИСТРИРОВАТЬСЯ</button>
        </form>
        <br>
        <a href="index.php" style="color: #94a3b8; text-decoration: none; font-size: 14px;">Уже есть аккаунт? Войти</a>
    </div>
</body>
</html>