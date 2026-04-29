<?php
/* receipt_status.php — серверная квитанция на повышение статуса.
   Аналог receipt_torgi.php, но без привязки к лоту: только тариф + сумма.
   Открывается в новой вкладке из profile.php при клике «Сформировать квитанцию». */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

$tariff = isset($_GET['tariff']) ? trim($_GET['tariff']) : '';
$amount = isset($_GET['amount']) ? (int)$_GET['amount'] : 0;

if ($tariff === '' || $amount <= 0) die('Неверные параметры');

$company = "ООО «Форсаж»";
$bank    = "ООО «Банк Точка»";
$account = "40702810101500033019";
$corr    = "30101810745374525104";
$bik     = "044525104";
$inn     = "7728282160";
$kpp     = "773001001";
$purpose = "Оплата услуг по тарифу «{$tariff}», сумма {$amount} руб., в т.ч. НДС 22%";

$sum_kopeks = (int)round($amount * 100);
$qr_data = "ST00012|Name=ООО Форсаж|PersonalAcc={$account}|BankName={$bank}|BIC={$bik}|CorrespAcc={$corr}|PayeeINN={$inn}|KPP={$kpp}|Sum={$sum_kopeks}|Purpose=" . urlencode($purpose);
$qr_url  = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qr_data);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$is_auth = $user_id > 0;

$profile_email = '';
if ($is_auth) {
    $se = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $se->execute([$user_id]);
    $profile_email = (string)$se->fetchColumn();
}

/* Таблица та же, что и для лотовых квитанций. */
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    lot_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL,
    tariff VARCHAR(100) NOT NULL,
    comment TEXT NULL,
    file_path VARCHAR(500) NOT NULL,
    user_email VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (lot_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE payment_receipts ADD COLUMN user_email VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $user_email = trim($_POST['user_email'] ?? $profile_email);
    $comment    = trim($_POST['comment'] ?? '');

    if (!$is_auth && $user_email === '') {
        $error = 'Укажите email для уведомления';
    } elseif (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Выберите файл подтверждения оплаты';
    } else {
        $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
            $error = 'Допустимые форматы: JPG, PNG, PDF';
        } elseif ($_FILES['payment_proof']['size'] > 2 * 1024 * 1024) {
            $error = 'Файл не более 2 МБ';
        } else {
            $upload_dir = 'uploads/receipts/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'status_' . ($user_id ?: 'guest') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $filename)) {
                $stmt = $pdo->prepare("INSERT INTO payment_receipts (user_id, lot_id, amount, tariff, comment, file_path, user_email, status, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, 'pending', NOW())");
                $stmt->execute([$user_id ?: null, $amount, $tariff, $comment, $upload_dir . $filename, $user_email ?: null]);
                $success = 'Подтверждение отправлено на проверку. После одобрения статус будет повышен; уведомление придёт на ' . htmlspecialchars($user_email ?: 'ваш email') . '.';
            } else {
                $error = 'Не удалось сохранить файл';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Квитанция на оплату — <?= htmlspecialchars($tariff) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; background: #f8fafc; color: #333; margin: 0; }
        .receipt-box { max-width: 700px; margin: 0 auto; border: 1px solid #000; padding: 20px; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .qr-side { text-align: center; width: 160px; flex-shrink: 0; }
        .qr-side img { width: 140px; height: 140px; border: 1px solid #ddd; }
        .info-side { flex: 1; padding-right: 20px; }
        .info-side h2 { margin: 0 0 8px; font-size: 20px; }
        .info-side p { margin: 4px 0; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        td { border: 1px solid #ccc; padding: 8px; font-size: 13px; }
        td.label { background: #f1f5f9; font-weight: 600; width: 35%; }
        .amount { font-size: 22px; font-weight: 800; color: #0ea5e9; text-align: right; padding: 10px; background: #eff6ff; border-radius: 6px; margin-top: 14px; }
        .actions { margin-top: 20px; text-align: center; }
        .actions button { padding: 10px 22px; background: #0ea5e9; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; margin: 0 4px; }
        .actions button.print { background: #16a34a; }
        .upload-block { margin-top: 24px; padding: 18px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; }
        .upload-block h3 { margin: 0 0 10px; font-size: 16px; }
        .upload-block input, .upload-block textarea { width: 100%; padding: 8px; margin: 6px 0; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-family: inherit; }
        .upload-block button { padding: 10px 18px; background: #16a34a; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .msg-success { background: #dcfce7; color: #14532d; padding: 12px; border-radius: 6px; margin: 10px 0; font-size: 14px; }
        .msg-error { background: #fee2e2; color: #7f1d1d; padding: 12px; border-radius: 6px; margin: 10px 0; font-size: 14px; }
        @media print {
            body { padding: 0; background: #fff; }
            .receipt-box { border: none; padding: 0; }
            .actions, .upload-block { display: none; }
        }
    </style>
</head>
<body>
<div class="receipt-box">
    <div class="header">
        <div class="info-side">
            <h2>Квитанция на оплату</h2>
            <p><strong>Получатель:</strong> <?= htmlspecialchars($company) ?></p>
            <p><strong>ИНН:</strong> <?= $inn ?> &nbsp; <strong>КПП:</strong> <?= $kpp ?></p>
            <p><strong>Расчётный счёт:</strong> <?= $account ?></p>
            <p><strong>Банк:</strong> <?= htmlspecialchars($bank) ?></p>
            <p><strong>БИК:</strong> <?= $bik ?> &nbsp; <strong>Корр.счёт:</strong> <?= $corr ?></p>
        </div>
        <div class="qr-side">
            <img src="<?= htmlspecialchars($qr_url) ?>" alt="QR СБП">
            <p style="font-size: 11px; margin: 6px 0;">Отсканируйте в приложении банка</p>
        </div>
    </div>

    <table>
        <tr><td class="label">Тариф</td><td><?= htmlspecialchars($tariff) ?></td></tr>
        <tr><td class="label">Назначение платежа</td><td><?= htmlspecialchars($purpose) ?></td></tr>
        <tr><td class="label">Сумма</td><td><strong><?= number_format($amount, 2, ',', ' ') ?> ₽</strong>, в т.ч. НДС 22%</td></tr>
        <tr><td class="label">Дата формирования</td><td><?= date('d.m.Y H:i') ?> МСК</td></tr>
    </table>

    <div class="amount">К оплате: <?= number_format($amount, 2, ',', ' ') ?> ₽</div>

    <div class="actions">
        <button class="print" onclick="window.print()">🖨 Распечатать</button>
        <button onclick="window.close()">Закрыть</button>
    </div>

    <div class="upload-block">
        <h3>Подтверждение оплаты</h3>
        <p style="font-size: 13px; color: #475569;">После оплаты загрузите чек / скриншот для подтверждения. Статус будет повышен после ручной проверки администратором.</p>

        <?php if ($success): ?>
            <div class="msg-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="msg-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="confirm_payment" value="1">
            <?php if (!$is_auth): ?>
                <label>Email для уведомления</label>
                <input type="email" name="user_email" required placeholder="example@mail.ru">
            <?php else: ?>
                <input type="hidden" name="user_email" value="<?= htmlspecialchars($profile_email) ?>">
                <p style="font-size: 12px; color: #64748b; margin: 4px 0;">Уведомление придёт на email из профиля: <strong><?= htmlspecialchars($profile_email ?: '—') ?></strong></p>
            <?php endif; ?>

            <label>Файл подтверждения (JPG, PNG или PDF, до 2 МБ)</label>
            <input type="file" name="payment_proof" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Комментарий (необязательно)</label>
            <textarea name="comment" rows="2" placeholder="Например: оплачено с карты *5678"></textarea>

            <button type="submit">📎 Отправить на проверку</button>
        </form>
    </div>
</div>
</body>
</html>
