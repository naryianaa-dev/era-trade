<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'msg' => 'Необходима авторизация']));
}

$user_id = (int)$_SESSION['user_id'];
$action  = trim($_POST['action'] ?? '');

if ($action === 'topup') {
    $amount = (int)$_POST['amount'];
    $method = in_array($_POST['payment_method'] ?? '', ['qr','receipt']) ? $_POST['payment_method'] : 'qr';

    if ($amount < 100) {
        die(json_encode(['success' => false, 'msg' => 'Минимальная сумма 100 ₽']));
    }
    if ($amount > 500000) {
        die(json_encode(['success' => false, 'msg' => 'Максимальная сумма 500 000 ₽']));
    }

    try {
        $pdo->prepare(
            "INSERT INTO balance_topups (user_id, amount, payment_method) VALUES (?, ?, ?)"
        )->execute([$user_id, $amount, $method]);

        echo json_encode(['success' => true, 'topup_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        error_log("topup error: " . $e->getMessage());
        echo json_encode(['success' => false, 'msg' => 'Ошибка сервера']);
    }
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Неизвестное действие']);
