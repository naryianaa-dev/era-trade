<?php
// Заголовки, запрещающие кэширование самого редиректа
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Добавляем случайный параметр, чтобы браузер не кэшировал этот редирект
header("HTTP/1.1 302 Found");
header("Location: torgi_view_v2.php?id=" . $id . "&_=" . time());
exit;