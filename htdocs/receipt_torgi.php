<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

$lot_id = isset($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;
$tariff = isset($_GET['tariff']) ? trim($_GET['tariff']) : '';
$amount = isset($_GET['amount']) ? (int)$_GET['amount'] : 0;

if (!$lot_id || !$tariff || !$amount) die('Неверные параметры');

$stmt = $pdo->prepare("SELECT title FROM torgi WHERE id = ?");
$stmt->execute([$lot_id]);
$lot_title = $stmt->fetchColumn() ?: "Лот №{$lot_id}";

$company = "ООО «Форсаж»";
$bank    = "ООО «Банк Точка»";
$account = "40702810101500033019";
$corr    = "30101810745374525104";
$bik     = "044525104";
$inn     = "7728282160";
$kpp     = "773001001";
$purpose = "Оплата услуг по лоту №{$lot_id} «{$lot_title}», тариф «{$tariff}», сумма {$amount} руб., в т.ч. НДС 22%";

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

// Создаём таблицу и добавляем колонку если нет
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
        $error = 'Укажите email для получения отчёта';
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
            $filename = 'receipt_' . $lot_id . '_' . ($user_id ?: 'guest') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $filename)) {
                $stmt = $pdo->prepare("INSERT INTO payment_receipts (user_id, lot_id, amount, tariff, comment, file_path, user_email, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                $stmt->execute([$user_id ?: null, $lot_id, $amount, $tariff, $comment, $upload_dir . $filename, $user_email ?: null]);
                $success = 'Подтверждение отправлено на проверку. После одобрения отчёт будет отправлен на ' . htmlspecialchars($user_email ?: 'ваш email') . '.';
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
    <title>Квитанция на оплату — ООО Форсаж</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; background: #f8fafc; color: #333; margin: 0; }
        .receipt-box { max-width: 700px; margin: 0 auto; border: 1px solid #000; padding: 20px; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .qr-side { text-align: center; width: 160px; flex-shrink: 0; }
        .qr-side img { width: 140px; height: 140px; border: 1px solid #ddd; }
        .info-side h2 { margin: 0 0 8px; font-size: 20px; }
        .info-side p { margin: 4px 0; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        td { border: 1px solid #ccc; padding: 8px; font-size: 13px; }
        .bold { font-weight: bold; background: #f5f5f5; width: 30%; }
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
            <h2>Квитанция №<?= $lot_id ?>_<?= date('Ymd') ?></h2>
            <p><strong>Получатель:</strong> <?= $company ?></p>
            <p><strong>Лот:</strong> <?= htmlspecialchars($lot_title) ?></p>
            <p><strong>Тариф:</strong> <?= htmlspecialchars($tariff) ?></p>
            <p><strong>Дата:</strong> <?= date('d.m.Y H:i') ?></p>
        </div>
        <div class="qr-side">
            <img src="<?= $qr_url ?>" alt="QR-код для оплаты">
            <div style="font-size:9px; margin-top:5px; color:#64748b;">Сканируйте в банк-онлайн</div>
        </div>
    </div>

    <table>
        <tr><td class="bold">ИНН / КПП</td><td><?= $inn ?> / <?= $kpp ?></td></tr>
        <tr><td class="bold">Расчётный счёт</td><td><?= $account ?></td></tr>
        <tr><td class="bold">БИК / Корр. счёт</td><td><?= $bik ?> / <?= $corr ?></td></tr>
        <tr><td class="bold">Назначение</td><td><?= htmlspecialchars($purpose) ?></td></tr>
        <tr class="total-row"><td class="bold">ИТОГО К ОПЛАТЕ</td><td><?= number_format($amount, 2, '.', ' ') ?> руб.</td></tr>
    </table>

    <button class="print-btn" onclick="window.print()">🖨️ Распечатать / Сохранить PDF</button>

    <?php if (!$success): ?>
    <div class="upload-section">
        <button class="upload-toggle-btn" onclick="document.getElementById('uploadForm').style.display='block'; this.style.display='none';">
            ✅ Я оплатил(а) — загрузить подтверждение
        </button>
        <div class="upload-form" id="uploadForm">
            <form method="POST" enctype="multipart/form-data" action="?lot_id=<?= $lot_id ?>&tariff=<?= urlencode($tariff) ?>&amount=<?= $amount ?>">
                <input type="hidden" name="confirm_payment" value="1">

                <?php if ($is_auth): ?>
                    <div class="f-group">
                        <span class="f-label">Email для отчёта</span>
                        <p style="margin:4px 0; font-size:14px;"><?= htmlspecialchars($profile_email) ?></p>
                        <div class="f-note">Отчёт придёт на email из профиля после подтверждения оплаты.</div>
                        <input type="hidden" name="user_email" value="<?= htmlspecialchars($profile_email) ?>">
                    </div>
                <?php else: ?>
                    <div class="f-group">
                        <label class="f-label" for="user_email">Email для получения отчёта *</label>
                        <input type="email" id="user_email" name="user_email" class="f-input" placeholder="example@mail.ru" required>
                        <div class="f-note">После подтверждения оплаты отчёт придёт на этот email. Для онлайн-доступа к PDF нужна <a href="register.php" target="_blank">регистрация</a>.</div>
                    </div>
                <?php endif; ?>

                <div class="f-group">
                    <label class="f-label" for="payment_proof">Файл подтверждения (JPG, PNG, PDF до 2 МБ) *</label>
                    <input type="file" id="payment_proof" name="payment_proof" class="f-input" accept="image/jpeg,image/png,application/pdf" required>
                </div>

                <div class="f-group">
                    <label class="f-label" for="comment">Комментарий (необязательно)</label>
                    <textarea id="comment" name="comment" class="f-input" rows="2" style="resize:vertical;"></textarea>
                </div>

                <button type="submit" class="submit-btn">Отправить на проверку</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
