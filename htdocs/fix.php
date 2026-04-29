<?php
session_start();
include 'db.php';

echo "<h2>Диагностика системы:</h2>";

// 1. Проверка коннекта
if ($pdo) {
    echo "✅ База подключена успешно.<br>";
}

// 2. Проверка наличия юзера
$check = $pdo->query("SELECT id, username, password FROM users WHERE username = 'Admin'")->fetch();
if ($check) {
    echo "✅ Юзер 'Admin' найден в базе (ID: " . $check['id'] . ").<br>";
    
    // Тест хэша
    if (password_verify('123', $check['password'])) {
        echo "✅ Пароль '123' подходит к хэшу в базе.<br>";
        
        // ПРИНУДИТЕЛЬНЫЙ ВХОД
        $_SESSION['user_id'] = $check['id'];
        $_SESSION['username'] = $check['username'];
        echo "🚀 <b>СЕССИЯ СОЗДАНА!</b> Сейчас попробуем редирект...<br>";
        echo "<script>setTimeout(() => { window.location.href = 'profile.php'; }, 2000);</script>";
    } else {
        echo "❌ ОШИБКА: Пароль '123' НЕ подходит к хэшу. Переделай SQL-запрос из прошлого сообщения.<br>";
    }
} else {
    echo "❌ ОШИБКА: Юзер 'Admin' НЕ найден. Проверь имя в таблице users.<br>";
}

// 3. Проверка записи сессий
$_SESSION['test'] = 'work';
if (isset($_SESSION['test'])) {
    echo "✅ Сессии работают (Test: " . $_SESSION['test'] . ").<br>";
} else {
    echo "❌ ОШИБКА: Сервер не сохраняет сессии. Пиши в поддержку хостинга или проверь папку tmp.<br>";
}
?>