<?php
$amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
$sum_kopeks = $amount * 100; // Для QR банковского стандарта иногда нужны копейки
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Квитанция на оплату - ООО Форсаж</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; padding: 20px; color: #333; background: #fff; }
        .receipt-box { max-width: 700px; margin: 0 auto; border: 1px solid #000; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .qr-side { text-align: center; width: 160px; }
        .qr-side img { width: 140px; height: 140px; }
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
            <h2>Квитанция №<?=time()?></h2>
            <p>Получатель: ООО «Форсаж»</p>
            <p>Дата: <?=date('d.m.Y')?></p>
        </div>
        <div class="qr-side">
            <?php
            // Формируем строку по ГОСТу с суммой (Sum в копейках)
            $payload = "ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=$sum_kopeks";
            $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($payload);
            ?>
            <img src="<?=$qr_url?>" alt="QR">
            <div style="font-size:9px; margin-top:5px;">ОПЛАТИТЬ ЧЕРЕЗ БАНК-ОНЛАЙН</div>
        </div>
    </div>

    <table>
        <tr><td class="bold">ИНН / КПП</td><td>7728282160 / 773001001</td></tr>
        <tr><td class="bold">Расчетный счет</td><td>40702810101500033019</td></tr>
        <tr><td class="bold">БИК / Корр.счет</td><td>044525104 / 30101810745374525104</td></tr>
        <tr><td class="bold">Назначение</td><td>Оплата услуг ЭТП ЭРА. Регистрация личного кабинета.</td></tr>
        <tr class="total-row">
            <td class="bold" style="color:#000;">ИТОГО К ОПЛАТЕ</td>
            <td><?=number_format($amount, 2, '.', ' ')?> руб.</td>
        </tr>
    </table>
</div>

<button class="print-btn" onclick="window.print()">Распечатать в PDF</button>

</body>
</html>