<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h3>Проверка подключения:</h3>";

if (!file_exists('db.php')) {
    die("<b style='color:red;'>ОШИБКА: Файл db.php не найден в корне сайта!</b>");
}

require_once 'db.php';

if (!isset($pdo)) {
    die("<b style='color:red;'>ОШИБКА: Переменная \$pdo не найдена. Проверь db.php!</b>");
}

try {
    $stmt = $pdo->query("SELECT 1");
    echo "<b style='color:green;'>УСПЕХ: База данных отвечает!</b>";
} catch (Exception $e) {
    echo "<b style='color:red;'>ОШИБКА БАЗЫ:</b> " . $e->getMessage();
}