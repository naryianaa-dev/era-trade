<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';

function json_error($msg) {
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($msg) {
    echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'submit_receipt') {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $lot_id  = (int)($_POST['lot_id'] ?? 0);
    $tariff  = trim($_POST['tariff'] ?? '');
    $amount  = (float)($_POST['amount'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if (!$user_id || !$lot_id) {
        json_error('Требуется авторизация');
    }
    if (empty($tariff) || $amount <= 0) {
        json_error('Не выбран тариф или сумма');
    }

    $file_path = '';
    if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/receipts/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        if ($_FILES['receipt_file']['size'] > 5 * 1024 * 1024) {
            json_error('Файл больше 5 МБ');
        }

        $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','pdf'];
        if (!in_array($ext, $allowed, true)) {
            json_error('Допустимы только JPG, PNG или PDF');
        }

        $filename = 'receipt_'.$user_id.'_'.time().'.'.$ext;
        $target   = $upload_dir.$filename;
        if (!move_uploaded_file($_FILES['receipt_file']['tmp_name'], $target)) {
            json_error('Ошибка загрузки файла');
        }
        $file_path = $target;
    } else {
        json_error('Прикрепите файл чека');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_receipts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED DEFAULT NULL,
        amount DECIMAL(15,2) NOT NULL,
        tariff VARCHAR(100) NOT NULL,
        comment TEXT,
        file_path VARCHAR(500) NOT NULL,
        status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (lot_id), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $stmt = $pdo->prepare("
            INSERT INTO payment_receipts (user_id, lot_id, amount, tariff, comment, file_path, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$user_id, $lot_id, $amount, $tariff, $comment, $file_path]);
        json_ok('Квитанция загружена и отправлена на проверку');
    } catch (Exception $e) {
        json_error('Ошибка сохранения: ' . $e->getMessage());
    }
}

json_error('Неизвестное действие');
