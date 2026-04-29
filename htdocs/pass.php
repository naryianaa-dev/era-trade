<?php
include 'db.php';

// Генерируем чистый хэш для '123' прямо на сервере
$new_hash = password_hash('123', PASSWORD_DEFAULT);

// Записываем его в базу для Admin
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'Admin'");
if ($stmt->execute([$new_hash])) {
    echo "✅ ХЭШ ОБНОВЛЕН! Теперь пароль точно '123'.<br>";
    echo "<a href='auth.php'>Иди пробовать логиниться!</a>";
} else {
    echo "❌ Ошибка при обновлении базы.";
}
?>