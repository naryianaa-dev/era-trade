<?php
session_start();

// Если Telegram прислал данные
if (isset($_GET['first_name'])) {
    // Собираем имя (Имя + Фамилия)
    $name = $_GET['first_name'];
    if (isset($_GET['last_name'])) {
        $name .= ' ' . $_GET['last_name'];
    }

    // Записываем в сессию и кидаем в профиль
    $_SESSION['user_logged'] = htmlspecialchars($name);
    header('Location: profile.php');
    exit;
} else {
    // Если зашли в файл просто так — выкидываем на логин
    header('Location: login.php');
    exit;
}
?>