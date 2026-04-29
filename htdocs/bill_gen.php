<?php
$sum = $_GET['sum'] ?? 0;
$inn_user = $_GET['inn'] ?? '0000000000';
$name_user = $_GET['name'] ?? 'Участник';

$qr_data = "ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|Sum=" . ($sum * 100) . "|Purpose=Регистрационный взнос на ЭТП. ИНН $inn_user|PayeeINN=7728282160|KPP=773001001";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Квитанция — ООО Форсаж</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #fff; color: #000; }
        .bill-box { max-width: 750px; margin: 0 auto; border: 2px solid #000; padding: 25px; }
        .qr-code { float: right; text-align: center; }
        .amount { font-size: 24px; font-weight: 900; border: 1px solid #000; padding: 10px; display: inline-block; margin: 20px 0; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="bill-box">
        <div class="qr-code">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?=urlencode($qr_data)?>">
            <p style="font-size:10px">ОПЛАТА ПО QR</p>
        </div>
        <h2>ООО «ФОРСАЖ»</h2>
        <p>ИНН 7728282160 / КПП 773001001</p>
        <p>Адрес: 121059, г.Москва, ул.Киевская, д.14, оф.2а</p>
        <hr>
        <p><strong>Банк:</strong> ООО «Банк Точка»</p>
        <p><strong>Р/С:</strong> 40702810101500033019</p>
        <p><strong>БИК:</strong> 044525104 | <strong>К/С:</strong> 30101810745374525104</p>
        <hr>
        <p><strong>Плательщик:</strong> <?=$name_user?> (ИНН <?=$inn_user?>)</p>
        <div class="amount">ИТОГО: <?=number_format($sum, 2, '.', ' ')?> руб.</div>
        <p style="font-size:12px">Назначение: Регистрационный взнос за участие в торгах. Без НДС.</p>
    </div>
    <div style="text-align:center; margin-top:20px;" class="no-print">
        <button onclick="window.print()" style="padding:10px 20px; cursor:pointer;">ПЕЧАТЬ КВИТАНЦИИ</button>
    </div>
</body>
</html>