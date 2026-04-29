<?php
/**
 * ecp_login_init.php
 *
 * Шаг 1 авторизации через ЭЦП: сервер выдаёт одноразовый nonce.
 * Клиент подпишет его своим закрытым ключом через КриптоПро Browser Plug-in
 * и отправит подпись + сертификат в ecp_login_verify.php.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) @session_start();

require_once 'db.php';
require_once 'ecp_helpers.php';

header('Content-Type: application/json; charset=utf-8');

/* Глобальный rubber stop, пока боевая крипта не подключена. */
if (!ECP_ENABLED) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'Функционал ЭЦП в разработке.',
        'status'  => 'coming_soon',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $nonce = ecp_generate_challenge($pdo, 'login');
    echo json_encode([
        'success' => true,
        'nonce'   => $nonce,
        /* Текст, который клиент должен подписать целиком: nonce + контекст.
           Привязка к домену защищает от replay на чужой инсталляции. */
        'data_to_sign' => "ERA-ETP-LOGIN:" . $nonce . ":" . ($_SERVER['HTTP_HOST'] ?? 'forsage.ct.ws'),
        'expires_in'   => 600,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
