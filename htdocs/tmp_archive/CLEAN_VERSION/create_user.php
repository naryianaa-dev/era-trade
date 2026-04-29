<?php
include 'db.php';

$username = 'admin';
$password = password_hash('123', PASSWORD_DEFAULT);

try {
    // Чистим старое, если вдруг что-то застряло
    $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
    
    // Вставляем только в те колонки, которые точно есть (id, username, password)
    $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $stmt->execute([$username, $password]);
    
    echo "ГОТОВО! Юзер 'admin' создан. Пароль: 123. Теперь иди логинься!";
} catch (Exception $e) {
    echo "Ошибка базы: " . $e->getMessage();
}
?>