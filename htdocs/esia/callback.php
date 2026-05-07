<?php
/**
 * htdocs/esia/callback.php
 *
 * Возврат с ЕСИА: ?code=...&state=...  →
 *   1. Обмен code на access_token+id_token (esia_exchange_code).
 *   2. Получение профиля субъекта (esia_fetch_profile).
 *   3. Поиск пользователя в БД по esia_oid → если есть, логиним;
 *      если нет — создаём нового пользователя и связываем.
 *   4. Редирект на $_SESSION['esia_next'] либо на «/».
 *
 * Этот redirect_uri должен быть указан 1-в-1 в Приложении Г, поданном в
 * Минцифру (https://eraetp.app/esia/callback.php).
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/oidc_client.php';
require_once __DIR__ . '/users_link.php';

function esia_callback_die(string $msg, int $http = 400): void
{
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>ЕСИА — ошибка</title>';
    echo '<h1 style="font-family:sans-serif">Ошибка авторизации через Госуслуги</h1>';
    echo '<p style="font-family:sans-serif">'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="/">На главную</a></p>';
    exit;
}

try {
    if (!empty($_GET['error'])) {
        $err = (string) $_GET['error'];
        $desc = (string) ($_GET['error_description'] ?? '');
        esia_log('CALLBACK-ERROR', ['error' => $err, 'desc' => $desc]);
        esia_callback_die("ЕСИА вернула ошибку: $err. $desc", 400);
    }

    $code  = (string) ($_GET['code']  ?? '');
    $state = (string) ($_GET['state'] ?? '');
    if ($code === '' || $state === '') {
        esia_callback_die('Не получены code и state от ЕСИА.', 400);
    }

    $tok  = esia_exchange_code($code, $state);
    if ($tok['oid'] === null) {
        esia_callback_die('Не удалось извлечь OID субъекта из id_token.', 502);
    }

    $profile = esia_fetch_profile($tok['access_token'], $tok['oid']);

    /** @var PDO $pdo */
    $userId = esia_link_or_create_user($pdo, $profile);

    // Подтягиваем актуальные данные сессии так же, как в auth.php
    $stmt = $pdo->prepare(
        'SELECT id, username, email, full_name, balance, user_type FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    $_SESSION['user_id']        = $u['id'];
    $_SESSION['user_name']      = $u['full_name'] ?: $u['username'];
    $_SESSION['user_username']  = $u['username'];
    $_SESSION['user_email']     = $u['email'] ?? '';
    $_SESSION['user_balance']   = $u['balance'] ?? 0;
    $_SESSION['usertype']       = $u['user_type'] ?? 'user';
    $_SESSION['auth']           = true;
    $_SESSION['user_logged']    = $u['username'];
    // Сохраняем токены (на случай logout/revoke и для отладки)
    $_SESSION['esia_access_token'] = $tok['access_token'];
    $_SESSION['esia_oid']          = $tok['oid'];

    esia_log('LOGIN-OK', [
        'user_id' => $userId,
        'oid'     => $tok['oid'],
        'trusted' => $profile['trusted'] ?? null,
    ]);

    $next = (string) ($_SESSION['esia_next'] ?? '/');
    unset($_SESSION['esia_next']);
    header('Location: ' . $next, true, 302);
    exit;
} catch (Throwable $e) {
    esia_log('CALLBACK-EXCEPTION', ['msg' => $e->getMessage()]);
    error_log('[esia/callback] ' . $e->getMessage());
    esia_callback_die($e->getMessage(), 500);
}
