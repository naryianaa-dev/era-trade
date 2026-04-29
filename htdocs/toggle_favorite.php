<?php
/**
 * toggle_favorite.php
 *
 * AJAX-эндпоинт переключения «звёздочки» (избранного).
 *
 * POST: lot_type=lot|torgi, lot_id=<int>
 * Ответ: JSON { ok, in_favorites, count }
 *
 * Для гостя (нет $_SESSION['user_id']) возвращает auth_required: фронт
 * вызывает openAuth('login') и предлагает залогиниться.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_schema_extra.php';

header('Content-Type: application/json; charset=utf-8');

$send = function (array $payload, int $http = 200): void {
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $send(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    $send(['ok' => false, 'error' => 'auth_required'], 401);
}

$lot_type = (string)($_POST['lot_type'] ?? '');
$lot_id   = (int)($_POST['lot_id'] ?? 0);

if (!in_array($lot_type, ['lot', 'torgi'], true)) {
    $send(['ok' => false, 'error' => 'invalid_lot_type'], 400);
}
if ($lot_id <= 0) {
    $send(['ok' => false, 'error' => 'invalid_lot_id'], 400);
}

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare("
        SELECT id FROM user_favorites
        WHERE user_id = ? AND lot_type = ? AND lot_id = ?
        LIMIT 1
    ");
    $check->execute([$user_id, $lot_type, $lot_id]);
    $existing = $check->fetchColumn();

    if ($existing) {
        $del = $pdo->prepare("DELETE FROM user_favorites WHERE id = ?");
        $del->execute([(int)$existing]);
        $in_favorites = false;
    } else {
        $ins = $pdo->prepare("
            INSERT INTO user_favorites (user_id, lot_type, lot_id)
            VALUES (?, ?, ?)
        ");
        $ins->execute([$user_id, $lot_type, $lot_id]);
        $in_favorites = true;
    }

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM user_favorites WHERE user_id = ?");
    $cnt->execute([$user_id]);
    $count = (int)$cnt->fetchColumn();

    $pdo->commit();

    $send([
        'ok'           => true,
        'in_favorites' => $in_favorites,
        'count'        => $count,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('toggle_favorite error: ' . $e->getMessage());
    $send(['ok' => false, 'error' => 'server_error'], 500);
}
