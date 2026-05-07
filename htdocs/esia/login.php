<?php
/**
 * htdocs/esia/login.php
 *
 * Точка входа: принимает GET, формирует authorize-URL и редиректит
 * пользователя на ЕСИА (Госуслуги).  Не возвращает HTML.
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/oidc_client.php';

try {
    $cfg = esia_config();
    if ($cfg['client_id'] === '') {
        throw new RuntimeException(
            'ESIA_CLIENT_ID не задан. Заполните /htdocs/esia/.env после '
            . 'получения ответа Минцифры по SCR#6142301.'
        );
    }

    // Запоминаем «куда вернуться после авторизации»
    $next = (string) ($_GET['next'] ?? '/');
    if (!preg_match('~^/[A-Za-z0-9_./?&=#-]*$~', $next)) {
        $next = '/';
    }
    $_SESSION['esia_next'] = $next;

    $a = esia_build_authorize_url();
    header('Location: ' . $a['url'], true, 302);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Ошибка инициации входа через Госуслуги</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/">На главную</a></p>';
    error_log('[esia/login] ' . $e->getMessage());
}
