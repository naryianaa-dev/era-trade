<?php
/* admin_only.php
 *
 * Guard для всех админских страниц. Подключается в самом начале файла
 * сразу после @session_start() и require 'db.php':
 *
 *     require_once __DIR__ . '/admin_only.php';
 *
 * Логика:
 *  - Если юзер не залогинен → redirect на index.php (как было раньше).
 *  - Если залогинен, но users.user_type !== 'admin' (и role !== 'admin')
 *    → redirect на 403_admin_only.php (заглушка).
 *  - Если admin → ничего не делает, выполнение продолжается.
 *
 * Зачем отдельный файл, а не inline-проверка в каждой странице:
 *  - Единая точка изменения политики доступа.
 *  - Никто из новых разработчиков не сможет случайно «забыть» проверку.
 *  - Можно добавить логирование/аудит-лог в одном месте.
 */

if (session_status() === PHP_SESSION_NONE) @session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

/* db.php должен быть подключен раньше — там создаётся $pdo. Если его нет,
   подключаем сами (идемпотентно через require_once). */
if (!isset($pdo)) {
    require_once __DIR__ . '/db.php';
}

try {
    $st = $pdo->prepare("SELECT user_type, role FROM users WHERE id = ? LIMIT 1");
    $st->execute([(int)$_SESSION['user_id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    /* Если БД недоступна — лучше отказать в доступе, чем пустить. */
    $row = null;
}

$is_admin =
    $row &&
    (
        ($row['user_type'] ?? '') === 'admin' ||
        ($row['role']      ?? '') === 'admin'
    );

if (!$is_admin) {
    header('Location: 403_admin_only.php');
    exit;
}
