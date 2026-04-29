<?php
/**
 * update_report_price.php
 *
 * Обновление стоимости отчёта на уже опубликованном лоте.
 * Доступно: admin (на любой лот) или organizer-владелец (owner_id = self).
 *
 * Принимает POST:
 *   lot_id       (int)        — ID лота
 *   table        (str)        — 'lots' (реестр) или 'torgi' (комиссионка)
 *   report_price (int|empty)  — новая цена в ₽; пусто = NULL = дефолт 1390 ₽
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function jok(array $data = []): void {
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Метод не разрешён', 405);
if (empty($_SESSION['user_id']))           jerr('Необходима авторизация', 401);

$user_id = (int)$_SESSION['user_id'];
$lot_id  = (int)($_POST['lot_id'] ?? 0);
$table   = ($_POST['table'] ?? 'lots') === 'torgi' ? 'torgi' : 'lots';
$rp_raw  = $_POST['report_price'] ?? '';
$report_price = ($rp_raw !== '' && is_numeric($rp_raw)) ? max(0, (int)$rp_raw) : null;

if ($lot_id <= 0) jerr('Некорректный ID лота');

/* Право: admin → любой лот; organizer → только свои (owner_id == user_id).
   У `torgi` нет owner_id, поэтому правит только админ. */
$st = $_SESSION['_uc_user_cache'] ?? null;
$st = $pdo->prepare("SELECT user_type, role FROM users WHERE id = ?");
$st->execute([$user_id]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$is_admin     = in_array(($me['user_type'] ?? ''), ['admin'], true)
             || in_array(($me['role']      ?? ''), ['admin'], true);
$is_organizer = in_array(($me['user_type'] ?? ''), ['organizer'], true)
             || in_array(($me['role']      ?? ''), ['organizer'], true);

if (!$is_admin && !$is_organizer) jerr('Недостаточно прав', 403);

if ($table === 'lots') {
    $st = $pdo->prepare("SELECT id, owner_id FROM lots WHERE id = ?");
    $st->execute([$lot_id]);
    $lot = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lot) jerr('Лот не найден', 404);

    if (!$is_admin && (int)$lot['owner_id'] !== $user_id) {
        jerr('Можно менять цену только на своих лотах', 403);
    }
    $pdo->prepare("UPDATE lots SET report_price = ? WHERE id = ?")
        ->execute([$report_price, $lot_id]);
} else {
    /* `torgi` — у комиссионных лотов нет владельца в текущей схеме,
       поэтому правит только админ. */
    if (!$is_admin) jerr('Только администратор может менять цену в комиссионке', 403);

    $st = $pdo->prepare("SELECT id FROM torgi WHERE id = ?");
    $st->execute([$lot_id]);
    if (!$st->fetch()) jerr('Лот не найден', 404);

    $pdo->prepare("UPDATE torgi SET report_price = ? WHERE id = ?")
        ->execute([$report_price, $lot_id]);
}

jok([
    'lot_id'       => $lot_id,
    'table'        => $table,
    'report_price' => $report_price,
    'effective'    => $report_price ?? 1390,
]);
