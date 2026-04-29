<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';
date_default_timezone_set('Europe/Moscow');

// Все ответы — plain text, JS читает их напрямую
header('Content-Type: text/plain; charset=utf-8');

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Метод не разрешён");
}

// Сессия истекла или не авторизован
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die("SESSION_EXPIRED");
}

$lot_id    = isset($_POST['lot_id'])     ? (int)$_POST['lot_id']       : 0;
$new_price = isset($_POST['bid_amount']) ? (float)$_POST['bid_amount'] : 0;
$user_id   = (int)$_SESSION['user_id'];

if ($lot_id <= 0 || $new_price <= 0) {
    http_response_code(400);
    die("Некорректные данные");
}

try {
    $pdo->beginTransaction();

    // FOR UPDATE — блокируем строку на время транзакции
    $stmt = $pdo->prepare(
        "SELECT price, last_bid_user, end_time, started_at
         FROM lots WHERE id = ? FOR UPDATE"
    );
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lot) {
        $pdo->rollBack();
        die("Лот не найден");
    }

    if (strtotime($lot['end_time']) <= time()) {
        $pdo->rollBack();
        die("Торги завершены");
    }

    if ((int)$lot['last_bid_user'] === $user_id) {
        $pdo->rollBack();
        die("Вы уже лидируете");
    }

    if ($new_price <= (float)$lot['price']) {
        $pdo->rollBack();
        die("Ставка должна быть больше " . number_format($lot['price'], 0, '.', ' ') . " ₽");
    }

    // Авто-продление: +5 минут от текущего момента
    $new_end = date('Y-m-d H:i:s', time() + 300);

    // Если первая ставка — фиксируем started_at
    $started_at = empty($lot['started_at']) ? date('Y-m-d H:i:s') : $lot['started_at'];

    $s1 = $pdo->prepare(
        "UPDATE lots SET price = ?, last_bid_user = ?, end_time = ?, started_at = ?
         WHERE id = ?"
    );
    $s1->execute([$new_price, $user_id, $new_end, $started_at, $lot_id]);

    $s2 = $pdo->prepare("INSERT INTO bids (lot_id, user_id, bid_amount) VALUES (?, ?, ?)");
    $s2->execute([$lot_id, $user_id, $new_price]);

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("send_bid error: " . $e->getMessage());
    http_response_code(500);
    die("Ошибка сервера, попробуйте ещё раз");
}

// Лог в файл — вне транзакции
try {
    $stmt_u = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $username = $stmt_u->fetchColumn() ?: "user_{$user_id}";
    $username = preg_replace('/[^\w\-а-яёА-ЯЁ ]/u', '', $username);

    if (!is_dir('logs')) {
        mkdir('logs', 0755, true);
    }

    $log_line = "[" . date('d.m.Y H:i:s') . "] "
              . $username . " поставил "
              . number_format($new_price, 0, '.', ' ')
              . " ₽\n";

    file_put_contents("logs/lot_{$lot_id}.txt", $log_line, FILE_APPEND | LOCK_EX);

} catch (Exception $e) {
    error_log("send_bid log error: " . $e->getMessage());
}

echo "success";
