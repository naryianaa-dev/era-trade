<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: auth.php"); exit; }

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    if ($stmt->execute([$new_pass, $_SESSION['user_id']])) {
        $message = "<p style='color:green;'>Пароль успешно изменен!</p>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Смена пароля</title>
    <style>
        body { background: #0f172a; color: white; font-family: sans-serif; display: flex; justify-content: center; padding: 50px; }
        .card { background: #1e293b; padding: 30px; border-radius: 15px; width: 350px; }
        input { width: 100%; padding: 10px; margin: 10px 0; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #3b82f6; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Новый пароль</h2>
        <?= $message ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Введите новый пароль" required>
            <button type="submit">ОБНОВИТЬ</button>
        </form>
        <br><a href="profile.php" style="color:#94a3b8; text-decoration:none;">← Назад</a>
    </div>
</body>
</html>