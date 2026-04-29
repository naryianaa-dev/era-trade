<?php
/**
 * send_closed_bid.php
 *
 * Ставка в закрытом аукционе на повышение. Особенности:
 *   - таймер фиксирован (без авто-продления);
 *   - подавать ставки могут только допущенные организатором/админом участники;
 *   - САМОПЕРЕБОЙ РАЗРЕШЁН — участник может повышать свою же ставку
 *     (проверки `last_bid_user === $user_id` НЕТ и быть не должно).
 *
 * Самоперебой разрешён намеренно, по спецификации заказчика:
 *   «Участник закрытого аукциона может перебить свою же ставку».
 *
 * Это отличает закрытый аукцион от скандинавского: в скандинавском
 * самоперебой запрещён (см. scandinavian_bid.php), потому что там за каждую
 * ставку списывается тариф 1990/2690 ₽, и повторный клик лидера = оплата
 * без смены состояния. В закрытом тарифа за ставку нет, поэтому повышение
 * собственной цены — это нормальный сценарий торгов.
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_schema_extra.php';

date_default_timezone_set('Europe/Moscow');
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); die('Метод не разрешён'); }
if (empty($_SESSION['user_id']))            { http_response_code(401); die('SESSION_EXPIRED'); }

$user_id   = (int)$_SESSION['user_id'];
$lot_id    = (int)($_POST['lot_id']     ?? 0);
$new_price = (float)($_POST['bid_amount'] ?? 0);

if ($lot_id <= 0 || $new_price <= 0) { http_response_code(400); die('Некорректные данные'); }

/* Проверка бана (hard/soft). */
require_once 'finances.php';
$_ban = checkUserBan($pdo, $user_id);
if (!empty($_ban['banned'])) {
    http_response_code(403);
    $reason = $_ban['reason'] ?? '';
    if (($_ban['type'] ?? '') === 'hard') {
        die("BANNED: " . ($reason ?: 'Ваш аккаунт заблокирован'));
    }
    $until = !empty($_ban['ban_until']) ? ' (до ' . $_ban['ban_until'] . ')' : '';
    die("BANNED_SOFT: " . ($reason ?: 'Временное ограничение') . $until);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, owner_id, auction_type, price, bid_step, end_time
        FROM lots WHERE id = ? FOR UPDATE
    ");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot)                                { $pdo->rollBack(); die('Лот не найден'); }
    if ($lot['auction_type'] !== 'closed')    { $pdo->rollBack(); die('Этот лот не является закрытым аукционом'); }
    if ((int)$lot['owner_id'] === $user_id)   { $pdo->rollBack(); die('Нельзя торговаться по собственному лоту'); }
    if (strtotime($lot['end_time']) <= time()){ $pdo->rollBack(); die('Торги завершены'); }

    /* Проверка допуска (организатор/админ должны были одобрить заявку). */
    $stmt = $pdo->prepare("SELECT status FROM closed_participants WHERE lot_id = ? AND user_id = ?");
    $stmt->execute([$lot_id, $user_id]);
    $part_status = $stmt->fetchColumn();
    if ($part_status !== 'approved') { $pdo->rollBack(); die('Вы не допущены к торгам'); }

    $current_price = (float)$lot['price'];
    $step = (int)$lot['bid_step'] ?: 1000;

    if ($new_price < $current_price + $step) {
        $pdo->rollBack();
        die('Минимальная ставка: ' . number_format($current_price + $step, 0, '.', ' ') . ' ₽');
    }

    /* В закрытом аукционе таймер ФИКСИРОВАН и не продлевается. */
    $stmt = $pdo->prepare("UPDATE lots SET price = ?, last_bid_user = ? WHERE id = ?");
    $stmt->execute([$new_price, $user_id, $lot_id]);

    $stmt = $pdo->prepare("INSERT INTO bids (lot_id, user_id, bid_amount) VALUES (?, ?, ?)");
    $stmt->execute([$lot_id, $user_id, $new_price]);

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('send_closed_bid error: ' . $e->getMessage());
    http_response_code(500);
    die('Ошибка сервера');
}

echo 'success';
