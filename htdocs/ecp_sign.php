<?php
/**
 * ecp_sign.php
 *
 * Регистрирует ЭЦП-подпись для произвольной операции (заявка/ставка/документ).
 * Используется параллельно с обычным сабмитом — сначала клиент подписывает
 * canonical-JSON через КриптоПро Plug-in, затем шлёт сюда signature + cert.
 *
 * Принимает POST:
 *   target_type   — 'proposal_offer' | 'quotation_offer' | 'bid' | 'closed_bid' | 'commission_offer' | 'document'
 *   target_id     — ID соответствующей сущности (после её создания)
 *   payload_json  — каноничный JSON, который был подписан (для аудита)
 *   signature_b64 — base64 подпись
 *   cert_pem      — сертификат подписанта
 *
 * Возвращает {success:true, sig_id:N, verified:bool}.
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

$user_id      = (int)$_SESSION['user_id'];
$target_type  = trim((string)($_POST['target_type'] ?? ''));
$target_id    = isset($_POST['target_id']) ? (int)$_POST['target_id'] : null;
$payload_json = (string)($_POST['payload_json'] ?? '');
$signature_b64= trim((string)($_POST['signature_b64'] ?? ''));
$cert_pem     = trim((string)($_POST['cert_pem'] ?? ''));

$allowed = ['proposal_offer','quotation_offer','bid','closed_bid','commission_offer','document'];
if (!in_array($target_type, $allowed, true)) fail('Неизвестный target_type');
if ($signature_b64 === '' || $cert_pem === '') fail('Подпись или сертификат пусты');

/* TODO[ECP]: на боевой версии заменить на реальную CMS/CAdES verify. */
$verified = ecp_verify_signature($payload_json, $signature_b64, $cert_pem);

$sig_id = ecp_log_signature(
    $pdo, $user_id, $target_type, $target_id,
    null, $signature_b64, $cert_pem, $verified
);

/* Помечаем целевую сущность как подписанную, если это bid/offer и таблица есть. */
try {
    if ($target_type === 'bid' && $target_id) {
        $pdo->prepare("UPDATE bids SET ecp_signed = 1, ecp_sig_id = ? WHERE id = ?")
            ->execute([$sig_id, $target_id]);
    } elseif (in_array($target_type, ['proposal_offer','quotation_offer','commission_offer'], true) && $target_id) {
        $pdo->prepare("UPDATE offers SET ecp_signed = 1, ecp_sig_id = ? WHERE id = ?")
            ->execute([$sig_id, $target_id]);
    }
} catch (Throwable $e) {
    /* Не критично — запись в ecp_signatures уже есть. */
}

echo json_encode([
    'success'  => true,
    'sig_id'   => $sig_id,
    'verified' => $verified,
], JSON_UNESCAPED_UNICODE);
