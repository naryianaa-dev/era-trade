<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Данные пользователя из сессии
$userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$fullName = isset($_SESSION['user_name']) ? trim($_SESSION['user_name']) : 'Плательщик';


// Что покупаем и на какую сумму — прилетает из личного кабинета через GET
$targetStatus = isset($_GET['status']) ? trim($_GET['status']) : 'Ответственный';
$amount       = isset($_GET['sum']) ? (float)$_GET['sum'] : 8000.00;

// Расчёт НДС 22% внутри суммы
$vat      = round($amount * 22 / 122, 2);
$username = $fullName;
$purpose  = "Повышение статуса {$targetStatus} ID{$userId} {$username}";

// Реквизиты получателя
$companyNameMachine = "ООО Форсаж";
$companyNameHuman   = "ООО \"Форсаж\"";
$companyAddr = "121059, г.Москва, ул.Киевская, д.14, оф.2а";
$bankName    = "ООО Банк Точка";
$personalAcc = "40702810101500033019";
$correspAcc  = "30101810745374525104";
$bic         = "044525104";
$inn         = "7728282160";
$kpp         = "773001001";

// QR ST00012 с суммой в копейках
$sumKopecks = (int)round($amount * 100);
$qrData = "ST00012"
    . "|Name={$companyNameMachine}"
    . "|PersonalAcc={$personalAcc}"
    . "|BankName={$bankName}"
    . "|BIC={$bic}"
    . "|CorrespAcc={$correspAcc}"
    . "|PayeeINN={$inn}"
    . "|KPP={$kpp}"
    . "|Sum={$sumKopecks}"
    . "|Purpose={$purpose}";

$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($qrData);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Квитанция на оплату</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{box-sizing:border-box;}
        html,body{
            margin:0;
            padding:0;
            height:100%;
            background:#ffffff;
            color:#000000;
            overflow:hidden;
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }
        .page{
            height:100%;
            width:100%;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:16px;
        }
        .receipt{
            width:100%;
            max-width:780px;
            background:#ffffff;
            border:1px solid #e5e7eb;
            border-radius:8px;
            padding:20px 24px 16px;
        }
        .header{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            margin-bottom:12px;
        }
        .header-left{
            font-size:14px;
        }
        .header-right{
            text-align:right;
            font-size:12px;
            color:#4b5563;
        }
        h1{
            margin:0 0 6px;
            font-size:18px;
            font-weight:700;
        }
        .section-title{
            font-size:13px;
            font-weight:600;
            margin:10px 0 4px;
            border-bottom:1px solid #e5e7eb;
            padding-bottom:2px;
        }
        .row{
            display:flex;
            justify-content:space-between;
            gap:16px;
        }
        .col{
            flex:1;
            font-size:13px;
            line-height:1.5;
        }
        .label{
            font-weight:600;
        }
        .qr-box{
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            padding:8px;
            border:1px solid #e5e7eb;
            border-radius:6px;
        }
        .qr-box img{
            display:block;
            width:220px;
            height:220px;
        }
        .sum{
            margin-top:8px;
            font-size:14px;
        }
        .sum b{
            font-size:18px;
        }
        .purpose{
            margin-top:6px;
            font-size:13px;
        }
        .footer{
            margin-top:16px;
            font-size:12px;
            display:flex;
            justify-content:space-between;
            gap:16px;
        }
        .btn-row{
            margin-top:16px;
            text-align:right;
        }
        .btn-print{
            display:inline-block;
            padding:10px 18px;
            border-radius:6px;
            border:none;
            background:#0d6efd;
            color:#ffffff;
            font-size:13px;
            font-weight:600;
            cursor:pointer;
        }
        .btn-print:hover{
            background:#0b5ed7;
        }
        .btn-close{
            display:inline-block;
            margin-left:8px;
            padding:10px 18px;
            border-radius:6px;
            border:1px solid #d1d5db;
            background:#ffffff;
            color:#111827;
            font-size:13px;
            font-weight:600;
            cursor:pointer;
        }
        .btn-close:hover{
            background:#f3f4f6;
            border-color:#9ca3af;
        }
        @media(max-width:768px){
            .receipt{padding:14px 12px;}
            .row{flex-direction:column;}
            .qr-box img{width:200px;height:200px;}
        }
        @media print {
            .btn-row{display:none;}
            html,body{
                height:auto;
                overflow:visible;
            }
            .page{
                padding:0;
                align-items:flex-start;
            }
            .receipt{
                border:none;
                border-radius:0;
                width:100%;
                max-width:none;
            }
        }
    </style>
</head>
<body>
<div class="page">
  <div class="receipt">
    <div class="header">
      <div class="header-left">
        <h1>Квитанция на оплату</h1>
        <div>Получатель: <?= htmlspecialchars($companyNameHuman, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="header-right">
        <div>Дата: <?= date('d.m.Y') ?></div>
        <?php if ($userId): ?>
          <div>ID плательщика: <?= $userId ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="section-title">Данные плательщика</div>
    <div class="row">
      <div class="col">
        <div><span class="label">ФИО:</span> <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></div>
        <div><span class="label">Статус:</span> <?= htmlspecialchars($targetStatus, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>

    <div class="section-title">Реквизиты получателя</div>
    <div class="row">
      <div class="col">
        <div><span class="label">Организация:</span> <?= htmlspecialchars($companyNameHuman, ENT_QUOTES, 'UTF-8') ?></div>
        <div><span class="label">Адрес:</span> <?= htmlspecialchars($companyAddr, ENT_QUOTES, 'UTF-8') ?></div>
        <div><span class="label">ИНН / КПП:</span> <?= $inn ?> / <?= $kpp ?></div>
        <div><span class="label">Банк:</span> <?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?></div>
        <div><span class="label">БИК:</span> <?= $bic ?></div>
        <div><span class="label">Р/с:</span> <?= $personalAcc ?></div>
        <div><span class="label">К/с:</span> <?= $correspAcc ?></div>
      </div>
      <div class="col" style="max-width:260px;">
        <div class="qr-box">
          <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR для оплаты">
          <div style="font-size:11px;margin-top:6px;color:#4b5563;">
            QR-код для оплаты через интернет-банк
          </div>
        </div>
      </div>
    </div>

    <div class="section-title">Сумма и назначение платежа</div>
    <div class="sum">
      Сумма к оплате: <b><?= number_format($amount, 2, ',', ' ') ?> ₽</b><br>
      В том числе НДС 22%: <?= number_format($vat, 2, ',', ' ') ?> ₽
    </div>
    <div class="purpose">
      Назначение платежа: <?= htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') ?>
    </div>

    <div class="btn-row">
      <button class="btn-print" onclick="window.print();" type="button">🖨️ Печать квитанции</button>
      <button class="btn-close" onclick="receiptClose();" type="button">✕ Закрыть</button>
    </div>
  </div>
</div>
<script>
/* Закрытие окна квитанции: если страница была открыта через window.open
   (например, из модалки регистрации) — закрываем окно. Иначе — пытаемся
   вернуться назад в истории; если истории нет, переходим на главную. */
function receiptClose() {
    try {
        if (window.opener && !window.opener.closed) {
            window.close();
            /* Некоторые браузеры игнорируют window.close() для окон, открытых
               пользователем; страхуемся редиректом, если окно ещё открыто. */
            setTimeout(function(){
                if (!window.closed) {
                    if (history.length > 1) history.back();
                    else window.location.href = '/';
                }
            }, 150);
            return;
        }
    } catch (_) {}
    if (history.length > 1) history.back();
    else window.location.href = '/';
}
</script>
</body>
</html>
