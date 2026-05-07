<?php
/**
 * htdocs/esia/logout.php
 *
 * Корректный выход из ЕСИА: best-effort вызов /aas/oauth2/v3/revoke
 * с сохранённым access_token, очистка локальной сессии, редирект на «/».
 */

declare(strict_types=1);

session_start();
require_once __DIR__ . '/oidc_client.php';

try {
    $accessToken = (string) ($_SESSION['esia_access_token'] ?? '');
    if ($accessToken !== '') {
        try {
            esia_revoke_token($accessToken);
        } catch (Throwable $e) {
            error_log('[esia/logout] revoke failed: ' . $e->getMessage());
        }
    }
} finally {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }
    session_destroy();
    header('Location: /', true, 302);
    exit;
}
