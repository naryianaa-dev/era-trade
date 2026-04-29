<?php
$lot_id  = isset($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;
$tariff  = isset($_GET['tariff']) ? trim($_GET['tariff']) : '';
$amount  = isset($_GET['amount']) ? (int)$_GET['amount'] : 0;

if (!$lot_id || !$tariff || !$amount) {
    die('Неверные параметры');
}

$company   = "ООО «Форсаж»";
$address   = "121059, г.Москва, ул.Киевская, д.14, оф.2а";
$bank      = "ООО «Банк Точка»";
$account   = "40702810101500033019";
$corr      = "30101810745374525104";
$bik       = "044525104";
$inn       = "7728282160";
$kpp       = "773001001";
$purpose   = "Оплата услуг по лоту №{$lot_id}, тариф «{$tariff}», сумма {$amount} руб., в т.ч. НДС 22%";

$sum_kopeks = (int)round($amount * 100);
$qr_data = "ST00012|Name=ООО Форсаж|PersonalAcc={$account}|BankName={$bank}|BIC={$bik}|CorrespAcc={$corr}|PayeeINN={$inn}|KPP={$kpp}|Sum={$sum_kopeks}|Purpose=" . urlencode($purpose);
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qr_data);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Квитанция на оплату — ООО Форсаж</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; background: #fff; color: #333; margin: 0; }
        .receipt-box { max-width: 700px; margin: 0 auto; border: 1px solid #000; padding: 20px; background: #fff; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .qr-side { text-align: center; width: 160px; }
        .qr-side img { width: 140px; height: 140px; border: 1px solid #ddd; }
        .info-side h2 { margin: 0; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        td { border: 1px solid #ccc; padding: 8px; font-size: 13px; }
        .bold { font-weight: bold; background: #f5f5f5; width: 30%; }
        .total-row { font-size: 18px; color: #0088cc; font-weight: 800; }
        .print-btn { background: #0088cc; color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; display: block; margin: 20px auto; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
<div class="receipt-box">
    <div class="header">
        <div class="info-side">
            <h2>Квитанция №<?= $lot_id . '_' . time() ?></h2>
            <p>Получатель: <?= $company ?></p>
            <p>Дата: <?= date('d.m.Y H:i:s') ?></p>
        </div>
        <div class="qr-side">
            <img src="<?= $qr_url ?>" alt="QR-код для оплаты">
            <div style="font-size:9px; margin-top:5px;">Оплатите через банк-онлайн</div>
        </div>
    </div>

    <table>
        <tr><td class="bold">ИНН / КПП</td><td><?= $inn ?> / <?= $kpp ?></td></tr>
        <tr><td class="bold">Расчетный счет</td><td><?= $account ?></td></tr>
        <tr><td class="bold">БИК / Корр. счет</td><td><?= $bik ?> / <?= $corr ?></td></tr>
        <tr><td class="bold">Назначение</td><td><?= htmlspecialchars($purpose) ?></td></tr>
        <tr class="total-row"><td class="bold" style="color:#000;">ИТОГО К ОПЛАТЕ</td><td><?= number_format($amount, 2, '.', ' ') ?> руб.</td></tr>
    </table>

    <button class="print-btn" onclick="window.print()">🖨️ Распечатать / Сохранить PDF</button>
</div>
</body>
</html>