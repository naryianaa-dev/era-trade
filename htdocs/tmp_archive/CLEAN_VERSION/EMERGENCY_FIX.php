<?php
// EMERGENCY_FIX.php - ЭКСТРЕННЫЙ СБРОС ПАРОЛЯ
// Загрузите этот файл в /htdocs/ и откройте в браузере

require_once 'db.php';

echo "<h1>🔧 ЭКСТРЕННОЕ ИСПРАВЛЕНИЕ</h1>";

// СОЗДАЁМ ТЕСТОВОГО ПОЛЬЗОВАТЕЛЯ
$test_username = 'admin';
$test_password = 'admin123';
$test_email = 'admin@test.com';

// Хешируем пароль
$hashed = password_hash($test_password, PASSWORD_DEFAULT);

try {
    // Удаляем если уже есть
    $pdo->exec("DELETE FROM users WHERE username = 'admin'");
    
    // Создаём нового
    $stmt = $pdo->prepare("
        INSERT INTO users 
        (username, password, email, user_status, balance, created_at) 
        VALUES (?, ?, ?, 'base', 0, NOW())
    ");
    $stmt->execute([$test_username, $hashed, $test_email]);
    
    echo "<div style='background:#d1fae5;color:#065f46;padding:20px;border-radius:10px;margin:20px 0;'>";
    echo "<h2>✅ ТЕСТОВЫЙ ПОЛЬЗОВАТЕЛЬ СОЗДАН!</h2>";
    echo "<p><strong>Логин:</strong> admin</p>";
    echo "<p><strong>Пароль:</strong> admin123</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background:#fee2e2;color:#991b1b;padding:20px;border-radius:10px;margin:20px 0;'>";
    echo "<h2>❌ ОШИБКА:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}

// ПОКАЗЫВАЕМ ВСЕ ЛОГИНЫ В БАЗЕ
echo "<h2>📋 Все пользователи в базе:</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Создан</th></tr>";

try {
    $stmt = $pdo->query("SELECT id, username, email, created_at FROM users ORDER BY id DESC LIMIT 20");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td><strong>{$row['username']}</strong></td>";
        echo "<td>{$row['email']}</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
} catch (Exception $e) {
    echo "<tr><td colspan='4'>Ошибка: " . $e->getMessage() . "</td></tr>";
}

echo "</table>";

echo "<hr>";
echo "<h2>🔐 СБРОСИТЬ ПАРОЛЬ СУЩЕСТВУЮЩЕМУ ЮЗЕРУ:</h2>";
echo "<form method='POST'>";
echo "Username: <input type='text' name='reset_user' required><br><br>";
echo "Новый пароль: <input type='text' name='new_pass' required><br><br>";
echo "<button type='submit' name='reset'>СБРОСИТЬ ПАРОЛЬ</button>";
echo "</form>";

if (isset($_POST['reset'])) {
    $reset_user = $_POST['reset_user'];
    $new_pass = $_POST['new_pass'];
    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
        $stmt->execute([$new_hash, $reset_user]);
        
        if ($stmt->rowCount() > 0) {
            echo "<div style='background:#d1fae5;color:#065f46;padding:20px;border-radius:10px;margin:20px 0;'>";
            echo "<h3>✅ ПАРОЛЬ ИЗМЕНЁН!</h3>";
            echo "<p><strong>Логин:</strong> $reset_user</p>";
            echo "<p><strong>Новый пароль:</strong> $new_pass</p>";
            echo "</div>";
        } else {
            echo "<div style='background:#fef3c7;color:#92400e;padding:20px;border-radius:10px;margin:20px 0;'>";
            echo "⚠️ Пользователь '$reset_user' не найден";
            echo "</div>";
        }
    } catch (Exception $e) {
        echo "<div style='background:#fee2e2;color:#991b1b;padding:20px;border-radius:10px;margin:20px 0;'>";
        echo "❌ Ошибка: " . $e->getMessage();
        echo "</div>";
    }
}

echo "<hr>";
echo "<p style='color:red;font-weight:bold;'>⚠️ УДАЛИТЕ ЭТОТ ФАЙЛ ПОСЛЕ ИСПОЛЬЗОВАНИЯ!</p>";
?>
