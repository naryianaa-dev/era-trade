<?php
/**
 * send_proposal_offer.php
 *
 * Создаёт или обновляет предложение участника по запросу предложений
 * (продажа товара — выигрывает наибольшая предложенная цена).
 *
 * Участник может изменить своё предложение в любой момент
 * до дедлайна — используется UPSERT (UNIQUE на lot_id+user_id).
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
$price   = (float)($_POST['price']  ?? 0);
$comment = trim((string)($_POST['comment'] ?? ''));

if ($lot_id <= 0 || $price <= 0) jerr('Некорректные данные');

/* Проверка бана (hard/soft). */
require_once 'finances.php';
$_ban = checkUserBan($pdo, $user_id);
if (!empty($_ban['banned'])) {
    $reason = $_ban['reason'] ?? '';
    if (($_ban['type'] ?? '') === 'hard') {
        jerr('Аккаунт заблокирован' . ($reason ? ': ' . $reason : ''), 403);
    }
    $until = !empty($_ban['ban_until']) ? ' до ' . $_ban['ban_until'] : '';
    jerr('Временное ограничение' . $until . ($reason ? ': ' . $reason : ''), 403);
}

try {
    $stmt = $pdo->prepare("SELECT id, owner_id, auction_type, extra_params, start_price FROM lots WHERE id = ?");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot)                                  jerr('Лот не найден', 404);
    if ($lot['auction_type'] !== 'proposal')    jerr('Этот лот не является запросом предложений');
    if ((int)$lot['owner_id'] === $user_id)     jerr('Нельзя подавать предложения на собственный лот');

    $extra = json_decode($lot['extra_params'] ?? '{}', true) ?: [];
    $deadline = !empty($extra['proposal_deadline']) ? strtotime($extra['proposal_deadline']) : 0;
    if ($deadline > 0 && time() > $deadline)    jerr('Срок подачи предложений истёк');

    /* В запросе предложений выигрывает максимальная цена, поэтому проверяем
       только нижнюю границу (начальную цену лота). */
    $start = (float)$lot['start_price'];
    if ($start > 0 && $price < $start) {
        jerr('Цена не может быть ниже начальной (' . number_format($start, 0, '.', ' ') . ' ₽)');
    }

    $sql = "
        INSERT INTO lot_offers (lot_id, user_id, offer_type, price, comment)
        VALUES (?, ?, 'proposal', ?, ?)
        ON DUPLICATE KEY UPDATE
            price = VALUES(price),
            comment = VALUES(comment),
            updated_at = CURRENT_TIMESTAMP
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lot_id, $user_id, $price, $comment !== '' ? $comment : null]);

    jok([
        'message' => 'Предложение сохранено. Вы можете изменять его до окончания срока.',
        'price'   => $price,
    ]);
} catch (Exception $e) {
    error_log('send_proposal_offer error: ' . $e->getMessage());
    jerr('Ошибка сервера. Попробуйте ещё раз.', 500);
}
