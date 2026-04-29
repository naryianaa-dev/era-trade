<?php
session_start();
if (!isset($_SESSION['user'])) { header("Location: index.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['msg'])) {
    $chat_file = 'chat.txt';
    $message = "[" . date("d.m H:i") . "] " . $_SESSION['user']['username'] . ": " . htmlspecialchars($_POST['msg']) . "\n";
    file_put_contents($chat_file, $message, FILE_APPEND);
    $success = "Сообщение отправлено админу!";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Поддержка — ФОРСАЖ</title>
    <style>
        body { background: #0f172a; color: white; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .chat-box { background: #1e293b; padding: 30px; border-radius: 15px; border: 1px solid #334155; width: 400px; text-align: center; }
        textarea { width: 100%; height: 100px; background: #0f172a; border: 1px solid #334155; color: white; padding: 10px; border-radius: 8px; box-sizing: border-box; resize: none; }
        .btn { width: 100%; padding: 12px; background: #38bdf8; color: #0f172a; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="chat-box">
        <h2 style="color: #38bdf8;">ТЕХПОДДЕРЖКА</h2>
        <p style="color: #94a3b8;">Напишите свой вопрос, и админ ответит вам в ближайшее время.</p>
        <?php if(isset($success)) echo "<p style='color:#10b981'>$success</p>"; ?>
        <form method="POST">
            <textarea name="msg" placeholder="Ваше сообщение..." required></textarea>
            <button type="submit" class="btn">ОТПРАВИТЬ</button>
        </form>
        <br><a href="reestr.php" style="color: #94a3b8; text-decoration: none; font-size: 14px;">← Назад</a>
    </div>
</body>
</html>