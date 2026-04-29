<?php
/**
 * apply_closed.php
 *
 * Подача заявки на участие в закрытом аукционе.
 * Заявки рассматриваются организатором/админом вручную.
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_schema_extra.php';

date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json; charset=utf-8');

function jerr($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function jok($data = []) {
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Метод не разрешён', 405);
if (empty($_SESSION['user_id']))           jerr('Необходима авторизация', 401);

$user_id = (int)$_SESSION['user_id'];
$lot_id  = (int)($_POST['lot_id'] ?? 0);
$text    = trim((string)($_POST['application_text'] ?? ''));

if ($lot_id <= 0) jerr('Некорректный лот');

try {
    $stmt = $pdo->prepare("SELECT id, owner_id, auction_type FROM lots WHERE id = ?");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot)                                jerr('Лот не найден', 404);
    if ($lot['auction_type'] !== 'closed')    jerr('Этот лот не является закрытым аукционом');
    if ((int)$lot['owner_id'] === $user_id)   jerr('Нельзя подавать заявку на собственный лот');

    $stmt = $pdo->prepare("SELECT id, status FROM closed_participants WHERE lot_id = ? AND user_id = ?");
    $stmt->execute([$lot_id, $user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'rejected') jerr('Ваша заявка на этот лот уже была отклонена');
        if ($existing['status'] === 'pending') {
            $stmt = $pdo->prepare("UPDATE closed_participants SET application_text = ?, created_at = NOW() WHERE id = ?");
            $stmt->execute([$text !== '' ? $text : null, $existing['id']]);
            jok(['message' => 'Заявка обновлена и ожидает рассмотрения', 'status' => 'pending']);
        }
        jok(['message' => 'Вы уже допущены к торгам', 'status' => $existing['status']]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO closed_participants (lot_id, user_id, application_text, status)
        VALUES (?, ?, ?, 'pending')
    ");
    $stmt->execute([$lot_id, $user_id, $text !== '' ? $text : null]);

    jok(['message' => 'Заявка подана. Ожидайте решения организатора.', 'status' => 'pending']);
} catch (Exception $e) {
    error_log('apply_closed error: ' . $e->getMessage());
    jerr('Ошибка сервера. Попробуйте ещё раз.', 500);
}
