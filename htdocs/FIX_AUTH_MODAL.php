<?php
// FIX_AUTH_MODAL.php - ИСПРАВЛЯЕТ auth_modal.php НА СЕРВЕРЕ
// Загрузите на сервер и откройте в браузере ОДИН РАЗ

$file = 'auth_modal.php';

if (!file_exists($file)) {
    die("❌ Файл $file не найден!");
}

// Читаем файл
$content = file_get_contents($file);

// СТАРЫЙ JavaScript который делает alert
$old_js = <<<'OLD'
        .then(data => {
            if (data.trim() === 'success') {
                alert('Логин: ' + document.getElementById('auth-l-user').value);
                location.reload();
            } else {
                msg.textContent = data;
            }
        });
OLD;

// НОВЫЙ JavaScript который делает редирект
$new_js = <<<'NEW'
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                msg.textContent = '✅ ' + (data.message || 'Вход выполнен!');
                msg.style.color = '#10b981';
                setTimeout(() => location.reload(), 800);
            } else {
                msg.textContent = '❌ ' + (data.error || 'Ошибка входа');
                msg.style.color = '#ef4444';
            }
        })
        .catch(() => {
            msg.textContent = '❌ Ошибка соединения';
            msg.style.color = '#ef4444';
        });
NEW;

// Заменяем
$new_content = str_replace($old_js, $new_js, $content);

if ($new_content === $content) {
    echo "<div style='background:#fef3c7;color:#92400e;padding:20px;border-radius:10px;'>";
    echo "<h2>⚠️ Не найдено</h2>";
    echo "<p>Старый код не найден в файле. Возможно уже исправлено?</p>";
    echo "</div>";
} else {
    // Сохраняем
    file_put_contents($file, $new_content);
    
    echo "<div style='background:#d1fae5;color:#065f46;padding:20px;border-radius:10px;'>";
    echo "<h2>✅ ИСПРАВЛЕНО!</h2>";
    echo "<p>Файл auth_modal.php обновлён.</p>";
    echo "<p>Теперь при входе НЕ будет alert, а сразу редирект!</p>";
    echo "</div>";
    
    echo "<div style='margin-top:20px;padding:20px;background:#eff6ff;border-radius:10px;'>";
    echo "<h3>🔐 Попробуйте войти:</h3>";
    echo "<p><strong>Логин:</strong> admin</p>";
    echo "<p><strong>Пароль:</strong> admin123</p>";
    echo "<p><a href='index.php' style='color:#3b82f6;font-weight:bold;'>→ Перейти на главную</a></p>";
    echo "</div>";
}

echo "<hr>";
echo "<p style='color:red;font-weight:bold;'>⚠️ УДАЛИТЕ ЭТОТ ФАЙЛ после использования!</p>";
?>
