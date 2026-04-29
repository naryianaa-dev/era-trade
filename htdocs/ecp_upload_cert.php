<?php
/**
 * ecp_upload_cert.php
 *
 * Ручная привязка/отвязка ЭЦП-сертификата к личному кабинету.
 * Принимает POST с полем `cert_file` (multipart) или `cert_pem` (текст)
 * либо action=detach для очистки полей.
 *
 * Поддерживает: .cer, .crt, .pem, .p7b, .p7c, .der (бинарь автоматически
 * конвертируется в base64 и оборачивается в PEM-блок).
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) @session_start();

require_once 'db.php';
require_once 'ecp_helpers.php';

header('Content-Type: application/json; charset=utf-8');

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
if (empty($_SESSION['user_id']))           fail('Необходима авторизация', 401);

$user_id = (int)$_SESSION['user_id'];
$action  = $_POST['action'] ?? 'attach';

/* Открепление сертификата. */
if ($action === 'detach') {
    $pdo->prepare(
        "UPDATE users
            SET ecp_cert_serial = NULL,
                ecp_subject     = NULL,
                ecp_cert_pem    = NULL,
                ecp_attached_at = NULL
          WHERE id = ?"
    )->execute([$user_id]);
    echo json_encode(['success' => true, 'detached' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Привязка: либо файл, либо вставка текста сертификата. */
$cert_pem = '';

if (!empty($_FILES['cert_file']['tmp_name'])) {
    $tmp = $_FILES['cert_file']['tmp_name'];
    if ($_FILES['cert_file']['size'] > 200 * 1024) fail('Файл слишком большой (макс 200 КБ)');
    $raw = file_get_contents($tmp);
    if ($raw === false) fail('Не удалось прочитать файл');

    /* Если это уже PEM (содержит BEGIN CERTIFICATE) — берём как есть. */
    if (strpos($raw, '-----BEGIN') !== false) {
        $cert_pem = $raw;
    } else {
        /* Бинарь (.der/.cer-DER/.p7b) → оборачиваем в PEM. */
        $b64 = chunk_split(base64_encode($raw), 64, "\n");
        $cert_pem = "-----BEGIN CERTIFICATE-----\n" . $b64 . "-----END CERTIFICATE-----\n";
    }
} elseif (!empty($_POST['cert_pem'])) {
    $raw = trim((string)$_POST['cert_pem']);
    if (strpos($raw, '-----BEGIN') === false) {
        /* Голый base64 → оборачиваем в PEM. */
        $clean = preg_replace('/\s+/', '', $raw);
        $b64 = chunk_split($clean, 64, "\n");
        $cert_pem = "-----BEGIN CERTIFICATE-----\n" . $b64 . "-----END CERTIFICATE-----\n";
    } else {
        $cert_pem = $raw;
    }
} else {
    fail('Сертификат не передан (файл или PEM-текст)');
}

/* Минимальная проверка длины. */
if (strlen($cert_pem) < 200) fail('Сертификат слишком короткий — похоже на ошибку');

/* Парсим Subject и Serial. Если openssl парсер ничего не вернул (часто
   для ГОСТ-сертификатов) — берём fingerprint как идентификатор. */
[$subject, $serial] = ecp_parse_cert_pem($cert_pem);
if (!$serial) {
    /* Уникальный отпечаток для идентификации юзера в БД. */
    $serial = strtoupper(substr(hash('sha256', $cert_pem), 0, 40));
}
if (!$subject) {
    $subject = 'Subject parsing unavailable (raw certificate)';
}

/* Запрет привязки одного сертификата к двум разным юзерам. */
$st = $pdo->prepare("SELECT id FROM users WHERE ecp_cert_serial = ? AND id <> ? LIMIT 1");
$st->execute([$serial, $user_id]);
if ($st->fetch()) fail('Этот сертификат уже привязан к другому аккаунту', 409);

$pdo->prepare(
    "UPDATE users
        SET ecp_cert_serial = ?,
            ecp_subject     = ?,
            ecp_cert_pem    = ?,
            ecp_attached_at = NOW()
      WHERE id = ?"
)->execute([$serial, $subject, $cert_pem, $user_id]);

/* Парсим бизнес-поля и применяем к пустым колонкам пользователя.
   apply=0 в POST → только распарсить и вернуть, без UPDATE'а. По умолчанию
   apply=1 (auto-fill), чтобы при первой загрузке сразу подставить ФИО/ИНН/email/etc. */
$cert_fields = ecp_extract_user_fields($cert_pem);
$applied = [];
$apply_flag = isset($_POST['apply']) ? (int)$_POST['apply'] : 1;
if ($apply_flag === 1) {
    $applied = ecp_fill_user_from_cert($pdo, $user_id, $cert_fields);
}

echo json_encode([
    'success'     => true,
    'serial'      => $serial,
    'subject'     => $subject,
    'attached_at' => date('Y-m-d H:i:s'),
    'cert_fields' => $cert_fields,
    'applied'     => $applied,
], JSON_UNESCAPED_UNICODE);
