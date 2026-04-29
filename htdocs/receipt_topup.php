<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

$amount = isset($_GET['amount']) ? (int)$_GET['amount'] : 0;
$lang   = ($_SESSION['lang'] ?? 'ru') === 'en' ? 'en' : 'ru';

if ($amount < 7000 || $amount % 500 !== 0) {
    die($lang === 'en'
        ? 'Invalid amount. Minimum 7,000 RUB, multiples of 500.'
        : 'Неверная сумма. Минимум 7 000 ₽, кратно 500.');
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$is_auth   = $user_id > 0;
$username  = $_SESSION['username'] ?? '';
$profile_email = '';
if ($is_auth) {
    $se = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
    $se->execute([$user_id]);
    $row = $se->fetch(PDO::FETCH_ASSOC) ?: [];
    $profile_email = (string)($row['email'] ?? '');
    if ($username === '') $username = (string)($row['username'] ?? '');
}

$company = "ООО «Форсаж»";
$bank    = "ООО «Банк Точка»";
$account = "40702810101500033019";
$corr    = "30101810745374525104";
$bik     = "044525104";
$inn     = "7728282160";
$kpp     = "773001001";

$vat = (int)round($amount * 22 / 122);
$tariff_name = $lang === 'en' ? 'Account top-up' : 'Пополнение баланса';
$purpose = $lang === 'en'
    ? "Account top-up ID{$user_id} {$username}, RUB {$amount}, incl. VAT 22% RUB {$vat}"
    : "Пополнение счёта ID{$user_id} {$username}, {$amount} руб., в т.ч. НДС 22% — {$vat} руб.";

$sum_kopeks = (int)round($amount * 100);
$qr_data = "ST00012|Name=ООО Форсаж|PersonalAcc={$account}|BankName={$bank}|BIC={$bik}|CorrespAcc={$corr}|PayeeINN={$inn}|KPP={$kpp}|Sum={$sum_kopeks}|Purpose=" . urlencode($purpose);
$qr_url  = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qr_data);

/* Soft-create balance_topups table for receipt-pay flow */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS balance_topups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        payment_method ENUM('qr','receipt','cash','admin') NOT NULL DEFAULT 'receipt',
        status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
        proof_file VARCHAR(500) NULL,
        comment TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        confirmed_at DATETIME NULL,
        INDEX (user_id), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { error_log('topup table: ' . $e->getMessage()); }

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    if (!$is_auth) {
        $error = $lang === 'en' ? 'Please sign in to confirm payment.' : 'Войдите в аккаунт для подтверждения оплаты.';
    } elseif (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        $error = $lang === 'en' ? 'Please attach a payment proof file.' : 'Прикрепите файл подтверждения оплаты.';
    } else {
        $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
            $error = $lang === 'en' ? 'Allowed formats: JPG, PNG, PDF.' : 'Допустимые форматы: JPG, PNG, PDF.';
        } elseif ($_FILES['payment_proof']['size'] > 2 * 1024 * 1024) {
            $error = $lang === 'en' ? 'File must be 2 MB or smaller.' : 'Файл не более 2 МБ.';
        } else {
            $upload_dir = 'uploads/topups/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'topup_' . $user_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $dest)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO balance_topups (user_id, amount, payment_method, status, proof_file, comment, created_at) VALUES (?, ?, 'receipt', 'pending', ?, ?, NOW())");
                    $stmt->execute([$user_id, $amount, $dest, trim($_POST['comment'] ?? '')]);
                    $success = $lang === 'en'
                        ? 'Payment proof uploaded and sent for review. Your balance will be credited after confirmation.'
                        : 'Подтверждение оплаты загружено и отправлено на проверку. Баланс будет пополнен после подтверждения.';
                } catch (Throwable $e) {
                    error_log('topup insert: ' . $e->getMessage());
                    $error = $lang === 'en' ? 'Database error.' : 'Ошибка базы данных.';
                }
            } else {
                $error = $lang === 'en' ? 'Could not save the file.' : 'Не удалось сохранить файл.';
            }
        }
    }
}

$L = function ($ru, $en) use ($lang) { return $lang === 'en' ? $en : $ru; };
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $L('Квитанция на пополнение — ООО «Форсаж»', 'Top-up receipt — Forsage LLC') ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; background: #f8fafc; color: #333; margin: 0; }
        .receipt-box { max-width: 700px; margin: 0 auto; border: 1px solid #000; padding: 20px; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; gap:14px; }
        .qr-side { text-align: center; width: 160px; flex-shrink: 0; }
        .qr-side img { width: 140px; height: 140px; border: 1px solid #ddd; }
        .info-side h2 { margin: 0 0 8px; font-size: 20px; }
        .info-side p { margin: 4px 0; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        td { border: 1px solid #ccc; padding: 8px; font-size: 13px; }
        .bold { font-weight: bold; background: #f5f5f5; width: 35%; }
        .total-row td { font-size: 16px; font-weight: 800; color: #0088cc; }
        .total-row .bold { color: #000; }
        .print-btn { background: #0088cc; color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin-top: 20px; font-size: 14px; }
        .upload-section { margin-top: 24px; border-top: 2px dashed #cbd5e1; padding-top: 20px; }
        .upload-toggle-btn { background: #16a34a; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 700; }
        .upload-form { display: none; margin-top: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; }
        .f-group { margin-bottom: 14px; }
        .f-label { display: block; font-weight: 700; font-size: 13px; margin-bottom: 5px; color: #0f172a; }
        .f-input { width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .f-note { font-size: 12px; color: #64748b; margin-top: 4px; }
        .submit-btn { background: #0ea5e9; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 700; width: 100%; }
        .submit-btn:hover { background: #0284c7; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; font-weight: 600; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        @media print { .print-btn, .upload-section { display: none; } body { background: #fff; padding: 0; } }
    </style>
</head>
<body>
<div class="receipt-box">

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="header">
        <div class="info-side">
            <h2><?= $L('Квитанция №', 'Receipt #') ?>TOPUP_<?= $user_id ?>_<?= date('Ymd_His') ?></h2>
            <p><strong><?= $L('Получатель', 'Payee') ?>:</strong> <?= $company ?></p>
            <p><strong><?= $L('Услуга', 'Service') ?>:</strong> <?= htmlspecialchars($tariff_name) ?></p>
            <p><strong><?= $L('Плательщик', 'Payer') ?>:</strong> ID<?= $user_id ?> <?= htmlspecialchars($username) ?></p>
            <p><strong><?= $L('Дата', 'Date') ?>:</strong> <?= date('d.m.Y H:i') ?></p>
        </div>
        <div class="qr-side">
            <img src="<?= $qr_url ?>" alt="QR">
            <div style="font-size:9px; margin-top:5px; color:#64748b;"><?= $L('Сканируйте в банк-онлайн', 'Scan in your banking app') ?></div>
        </div>
    </div>

    <table>
        <tr><td class="bold"><?= $L('ИНН / КПП', 'INN / KPP') ?></td><td><?= $inn ?> / <?= $kpp ?></td></tr>
        <tr><td class="bold"><?= $L('Расчётный счёт', 'Settlement account') ?></td><td><?= $account ?></td></tr>
        <tr><td class="bold"><?= $L('Банк', 'Bank') ?></td><td><?= $bank ?></td></tr>
        <tr><td class="bold"><?= $L('БИК / Корр. счёт', 'BIC / Corr. account') ?></td><td><?= $bik ?> / <?= $corr ?></td></tr>
        <tr><td class="bold"><?= $L('Назначение', 'Purpose') ?></td><td><?= htmlspecialchars($purpose) ?></td></tr>
        <tr class="total-row"><td class="bold"><?= $L('ИТОГО К ОПЛАТЕ', 'TOTAL') ?></td><td><?= number_format($amount, 2, '.', ' ') ?> <?= $L('руб.', 'RUB') ?></td></tr>
    </table>

    <button class="print-btn" onclick="window.print()">🖨️ <?= $L('Распечатать / Сохранить PDF', 'Print / Save PDF') ?></button>

    <?php if (!$success && $is_auth): ?>
    <div class="upload-section">
        <button class="upload-toggle-btn" onclick="document.getElementById('uploadForm').style.display='block'; this.style.display='none';">
            ✅ <?= $L('Я оплатил(а) — загрузить подтверждение', 'I have paid — upload proof') ?>
        </button>
        <div class="upload-form" id="uploadForm">
            <form method="POST" enctype="multipart/form-data" action="?amount=<?= $amount ?>">
                <input type="hidden" name="confirm_payment" value="1">

                <div class="f-group">
                    <span class="f-label"><?= $L('Email для уведомлений', 'Notification email') ?></span>
                    <p style="margin:4px 0; font-size:14px;"><?= htmlspecialchars($profile_email ?: '—') ?></p>
                    <div class="f-note"><?= $L('После проверки баланс пополнится автоматически.', 'Your balance will be credited once verified.') ?></div>
                </div>

                <div class="f-group">
                    <label class="f-label" for="payment_proof"><?= $L('Файл подтверждения (JPG, PNG, PDF до 2 МБ)', 'Payment proof (JPG, PNG, PDF up to 2 MB)') ?> *</label>
                    <input type="file" id="payment_proof" name="payment_proof" class="f-input" accept="image/jpeg,image/png,application/pdf" required>
                </div>

                <div class="f-group">
                    <label class="f-label" for="comment"><?= $L('Комментарий (необязательно)', 'Comment (optional)') ?></label>
                    <textarea id="comment" name="comment" class="f-input" rows="2" style="resize:vertical;"></textarea>
                </div>

                <button type="submit" class="submit-btn"><?= $L('Отправить на проверку', 'Submit for review') ?></button>
            </form>
        </div>
    </div>
    <?php elseif (!$is_auth): ?>
    <div class="upload-section">
        <p style="color:#64748b;font-size:13px;"><?= $L('Чтобы загрузить подтверждение оплаты и пополнить баланс, ', 'To upload your payment proof and credit your balance, ') ?>
            <a href="login.php?redirect=profile.php" style="color:#0088cc;font-weight:700;"><?= $L('войдите в личный кабинет', 'sign in to your account') ?></a>.</p>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
