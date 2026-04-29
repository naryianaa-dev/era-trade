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

    if ($amount < 7000) {
        die(json_encode(['success' => false, 'msg' => 'Минимальная сумма 7 000 ₽']));
    }
    if ($amount % 500 !== 0) {
        die(json_encode(['success' => false, 'msg' => 'Сумма должна быть кратной 500 ₽']));
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

if ($action === 'confirm_topup') {
    $amount = (int)($_POST['amount'] ?? 0);
    $method = in_array($_POST['payment_method'] ?? '', ['qr','receipt']) ? $_POST['payment_method'] : 'qr';
    if ($amount < 7000 || $amount % 500 !== 0) {
        die(json_encode(['success' => false, 'msg' => 'Неверная сумма']));
    }
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        die(json_encode(['success' => false, 'msg' => 'Прикрепите файл подтверждения оплаты']));
    }
    $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
        die(json_encode(['success' => false, 'msg' => 'Форматы: JPG, PNG, PDF']));
    }
    if ($_FILES['payment_proof']['size'] > 2 * 1024 * 1024) {
        die(json_encode(['success' => false, 'msg' => 'Файл до 2 МБ']));
    }
    $upload_dir = 'uploads/topups/';
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0777, true);
    $filename = 'topup_' . $user_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $upload_dir . $filename;
    if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $dest)) {
        die(json_encode(['success' => false, 'msg' => 'Не удалось сохранить файл']));
    }
    try {
        /* Soft-create proof_file/comment columns if balance_topups already exists w/o them */
        try { $pdo->exec("ALTER TABLE balance_topups ADD COLUMN proof_file VARCHAR(500) NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE balance_topups ADD COLUMN comment TEXT NULL"); } catch (Throwable $e) {}
        $pdo->prepare(
            "INSERT INTO balance_topups (user_id, amount, payment_method, status, proof_file, comment, created_at) VALUES (?, ?, ?, 'pending', ?, ?, NOW())"
        )->execute([$user_id, $amount, $method, $dest, trim($_POST['comment'] ?? '')]);
        echo json_encode(['success' => true, 'topup_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        error_log("confirm_topup error: " . $e->getMessage());
        echo json_encode(['success' => false, 'msg' => 'Ошибка сервера']);
    }
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Неизвестное действие']);