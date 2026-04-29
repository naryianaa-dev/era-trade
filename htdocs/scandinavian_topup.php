<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'db.php';
require_once 'bid_config.php'; // чтобы понимать user_type, если нужно

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'msg' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Необходима авторизация'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userid = (int)$_SESSION['user_id'];
$mode   = $_POST['mode'] ?? '';
$lot_id = isset($_POST['lot_id']) ? (int)$_POST['lot_id'] : 0;

// норма суммы пополнения баланса: минимум 7000, кратно 500, вверх
function normalize_topup_amount($raw) {
    $amount = (int)round($raw);
    if ($amount < 7000) {
        return 7000;
    }
    $rem = $amount % 500;
    if ($rem === 0) {
        return $amount;
    }
    return $amount + (500 - $rem);
}

try {
    // чтобы убедиться, что лот существует (на будущее — для привязки платежа)
    if ($lot_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM lots WHERE id = ?");
        $stmt->execute([$lot_id]);
        $lot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lot) {
            echo json_encode(['success' => false, 'msg' => 'Лот не найден'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $tariff = '';
    $amount = 0;

    if ($mode === 'balance') {
        $raw = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        if ($raw <= 0) {
            echo json_encode(['success' => false, 'msg' => 'Некорректная сумма'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $amount = normalize_topup_amount($raw);
        $tariff = 'scandi_balance_topup';

    } elseif ($mode === 'pack') {
        // цена пакета 20 ставок — считаем так же, как в сканди (pack_prices['per_bid'] * 20)
        // тут можно жёстко захардкодить или посчитать по user_type
        $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
        $stmt->execute([$userid]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_type = $u['user_type'] ?? 'respected';

        $pack_prices = getPackPrice($user_type); // как в сканди
        $per_bid     = (float)$pack_prices['per_bid'];
        $amount      = $per_bid * 20; // пакет 20 ставок
        $tariff      = 'scandi_bid_pack_20';

    } else {
        echo json_encode(['success' => false, 'msg' => 'Неверный режим'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'msg' => 'Нулевая сумма'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // создаём запись paymentreceipts, как в комиссионке
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS paymentreceipts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            userid INT UNSIGNED NOT NULL,
            lotid INT UNSIGNED DEFAULT NULL,
            amount DECIMAL(15,2) NOT NULL,
            tariff VARCHAR(100) NOT NULL,
            comment TEXT,
            filepath VARCHAR(500) NOT NULL,
            status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
            createdat DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(userid),
            INDEX(lotid),
            INDEX(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // здесь мы пока не загружаем файл, так что ставим пустой filepath
    $filepath = '';

    $stmt = $pdo->prepare("
        INSERT INTO paymentreceipts (userid, lotid, amount, tariff, comment, filepath, status, createdat)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $comment = $mode === 'balance'
        ? 'Пополнение баланса ЛК для скандинавского аукциона'
        : 'Пакет 20 ставок для скандинавского аукциона';

    $stmt->execute([
        $userid,
        $lot_id > 0 ? $lot_id : null,
        $amount,
        $tariff,
        $comment,
        $filepath
    ]);

    // Пока возвращаем тип 'qr', дальше можешь разделить на qr/receipt как в torgiactions.php
    echo json_encode([
        'success' => true,
        'type'    => 'qr',
        'amount'  => (float)$amount,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('scandinavian_topup error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'msg' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
}
