<?php
/**
 * ecp_login_verify.php
 *
 * Шаг 2 авторизации через ЭЦП: принимает подпись от клиента, проверяет nonce,
 * формально валидирует подпись (TODO[ECP] заменить на реальную крипту),
 * ищет/создаёт пользователя по cert_serial, кладёт user_id в сессию.
 *
 * Принимает POST application/json:
 *   nonce         — выданный ecp_login_init
 *   signature_b64 — подпись data_to_sign (base64)
 *   cert_pem      — сертификат подписанта (PEM или base64)
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) @session_start();

require_once 'db.php';
require_once 'ecp_helpers.php';

header('Content-Type: application/json; charset=utf-8');

/* Глобальный rubber stop. */
if (!ECP_ENABLED) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Функционал ЭЦП в разработке.', 'status' => 'coming_soon'], JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Метод не разрешён', 405);

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    /* Допускаем и form-data на случай если фронт пришлёт обычным POST. */
    $input = $_POST;
}

$nonce         = trim((string)($input['nonce'] ?? ''));
$signature_b64 = trim((string)($input['signature_b64'] ?? ''));
$cert_pem      = trim((string)($input['cert_pem'] ?? ''));

if ($nonce === '' || $signature_b64 === '' || $cert_pem === '') {
    fail('Не хватает данных подписи');
}

$ch = ecp_consume_challenge($pdo, $nonce, 'login');
if (!$ch) fail('Challenge истёк или уже использован', 410);

$data_to_sign = "ERA-ETP-LOGIN:" . $nonce . ":" . ($_SERVER['HTTP_HOST'] ?? 'forsage.ct.ws');

/* TODO[ECP]: при подключении реальной криптовалидации убедиться что
   cryptcp или CAdES-парсер возвращает true ТОЛЬКО для подписи именно
   $data_to_sign (а не любой произвольной строки). Сейчас стоит заглушка. */
$verified = ecp_verify_signature($data_to_sign, $signature_b64, $cert_pem);

[$subject, $serial] = ecp_parse_cert_pem($cert_pem);
$serial = $serial ?: substr(hash('sha256', $cert_pem), 0, 32); // fallback fingerprint

/* Ищем пользователя по сертификату. */
$st = $pdo->prepare("SELECT * FROM users WHERE ecp_cert_serial = ? LIMIT 1");
$st->execute([$serial]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    /* Распарсиваем все доступные поля из сертификата. */
    $cert_fields = ecp_extract_user_fields($cert_pem);

    $full_name = $cert_fields['full_name'] ?? '';
    $username  = 'ecp_' . substr($serial, 0, 12);

    /* Защита от коллизии username. */
    $i = 0;
    do {
        $try = $username . ($i ? "_$i" : '');
        $stc = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stc->execute([$try]);
        $exists = $stc->fetch();
        $i++;
    } while ($exists && $i < 50);
    $username = $try;

    $pdo->prepare(
        "INSERT INTO users (username, password, full_name, status, ecp_cert_serial, ecp_subject, ecp_cert_pem, ecp_attached_at, created_at)
         VALUES (?, '', ?, 'pending', ?, ?, ?, NOW(), NOW())"
    )->execute([$username, $full_name, $serial, $subject, $cert_pem]);
    $user_id = (int)$pdo->lastInsertId();

    /* Сразу применяем остальные распарсенные поля (email, ИНН, ОГРН, СНИЛС,
       компания, должность, адрес регистрации, entity_type) — но только в пустые
       колонки (insert выше создал юзера с большинством пустых полей). */
    ecp_fill_user_from_cert($pdo, $user_id, $cert_fields);

    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$user_id]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
}

ecp_log_signature(
    $pdo, (int)$user['id'], 'login', null, $nonce,
    $signature_b64, $cert_pem, $verified
);

/* Если запись существует, но pem ещё не сохранён — обновим. */
if (empty($user['ecp_cert_pem'])) {
    $pdo->prepare("UPDATE users SET ecp_cert_pem = ?, ecp_subject = ?, ecp_attached_at = NOW() WHERE id = ?")
        ->execute([$cert_pem, $subject, (int)$user['id']]);
}

$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['ecp_login'] = 1;
session_write_close();

echo json_encode([
    'success'  => true,
    'verified' => $verified,
    'user_id'  => (int)$user['id'],
    'status'   => $user['status'] ?? 'active',
    'redirect' => ($user['status'] ?? 'active') === 'active' ? 'profile.php' : 'profile_onboarding.php',
], JSON_UNESCAPED_UNICODE);
