<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
date_default_timezone_set('Europe/Moscow');

if (empty($_SESSION['user_id'])) {
    die('Требуется авторизация');
}
$user_id = $_SESSION['user_id'];
$amount = isset($_GET['amount']) ? (int)$_GET['amount'] : 18680;

$company = "ООО «Форсаж»";
$address = "121059, г.Москва, ул.Киевская, д.14, оф.2а";
$bank = "ООО «Банк Точка»";
$account = "40702810101500033019";
$corr = "30101810745374525104";
$bik = "044525104";
$inn = "7728282160";
$kpp = "773001001";
$purpose = "Оплата услуг по информационно-техническому обслуживанию на ЭТП, в том числе НДС 22%";

$sum_kopeks = (int)round($amount * 100);
$qr_data = "ST00012|Name=ООО Форсаж|PersonalAcc={$account}|BankName={$bank}|BIC={$bik}|CorrespAcc={$corr}|PayeeINN={$inn}|KPP={$kpp}|Sum={$sum_kopeks}|Purpose=" . urlencode($purpose);
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qr_data);

$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
        $max_size = 2 * 1024 * 1024;
        if ($_FILES['payment_proof']['size'] <= $max_size) {
            $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','pdf'])) {
                $upload_dir = "uploads/payments/";
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $filename = "pack_{$user_id}_".time().".$ext";
                move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir.$filename);
                $success = "Подтверждение отправлено. После проверки пакет будет добавлен.";
            } else {
                $error = "Разрешены только JPG, PNG, PDF";
            }
        } else {
            $error = "Файл не более 2 Мб";
        }
    } else {
        $error = "Выберите файл подтверждения";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Оплата пакета ставок</title>
    <style>
        body { background: #0f172a; color: #fff; font-family: sans-serif; padding: 20px; }
        .container { max-width: 600px; margin: auto; background: #1e293b; border-radius: 24px; padding: 24px; }
        h2 { text-align: center; margin-top: 0; }
        .alert { padding: 12px; border-radius: 12px; margin-bottom: 16px; }
        .alert-success { background: #166534; }
        .alert-error { background: #991b1b; }
        .qr { text-align: center; margin: 20px 0; }
        .qr img { width: 180px; height: 180px; background: #fff; padding: 10px; border-radius: 12px; }
        .receipt { background: #0f172a; padding: 16px; border-radius: 12px; font-size: 13px; line-height: 1.6; margin-top: 16px; }
        .btn { background: #f59e0b; color: #000; font-weight: bold; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; margin-top: 10px; display: inline-block; }
        .btn-print { background: #3b82f6; }
        .file-input { width: 100%; margin-top: 10px; padding: 8px; background: #0f172a; border: 1px solid #334155; color: #fff; border-radius: 8px; }
        @media print {
            body { background: white; color: black; }
            .container { background: white; }
            .no-print { display: none; }
            .receipt { background: white; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>
<div class="container">
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <h2>📦 Покупка пакета 10 ставок<br>на сумму <?= number_format($amount, 0, '.', ' ') ?> ₽</h2>
    <div class="qr">
        <img src="<?= $qr_url ?>" alt="QR-код для оплаты">
    </div>
    <div class="receipt">
        <strong>КВИТАНЦИЯ</strong><br><br>
        Получатель: <?= $company ?><br>
        Юр. адрес: <?= $address ?><br>
        ИНН <?= $inn ?> / КПП <?= $kpp ?><br>
        Банк: <?= $bank ?><br>
        р/с <?= $account ?><br>
        к/с <?= $corr ?><br>
        БИК <?= $bik ?><br>
        Назначение: <?= $purpose ?><br>
        Сумма: <?= number_format($amount, 2, '.', ' ') ?> ₽<br>
        Дата: <?= date('d.m.Y H:i:s') ?>
    </div>
    <div style="text-align:center; margin-top:20px;">
        <button class="btn btn-print no-print" onclick="window.print()">🖨️ Распечатать / PDF</button>
        <button class="btn" id="showUploadBtn" style="background:#10b981;">✅ Я оплатил</button>
    </div>
    <div id="uploadForm" style="display: none; margin-top: 20px;">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="payment_proof" accept="image/jpeg,image/png,application/pdf" class="file-input" required>
            <button type="submit" name="confirm_payment" value="1" class="btn" style="background:#28a745; margin-top:10px;">Отправить подтверждение</button>
        </form>
        <p style="font-size:12px; color:#94a3b8;">Максимум 2 Мб. Форматы: JPG, PNG, PDF</p>
    </div>
</div>
<script>
    document.getElementById('showUploadBtn')?.addEventListener('click', function() {
        document.getElementById('uploadForm').style.display = 'block';
    });
</script>
</body>
</html>