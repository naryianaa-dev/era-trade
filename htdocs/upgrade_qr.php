<?php
// upgrade_qr.php — QR для оплаты повышения статуса без скролла

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    die('Некорректный ID заявки');
}

$stmt = $pdo->prepare("
    SELECT su.*, u.username, u.full_name
    FROM status_upgrades su
    JOIN users u ON u.id = su.user_id
    WHERE su.id = ?
");
$stmt->execute([$id]);
$upgrade = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$upgrade) {
    die('Заявка на повышение статуса не найдена');
}

$userId    = (int)$upgrade['user_id'];
$username  = $upgrade['username'] ?: ('ID'.$userId);
$fullName  = $upgrade['full_name'] ?: $username;
$amount    = (float)$upgrade['total_amount'];
$vat       = round($amount * 22 / 122, 2); // НДС 22% из суммы с НДС 22%[web:501]

$targetStatus = $upgrade['target_status'] === 'organizer'
    ? 'Организатор'
    : 'Ответственный';

$purpose = "Повышение статуса {$targetStatus} ID{$userId} {$username}";
$sumKopecks = (int)round($amount * 100);

// реквизиты
$companyName = "ООО Форсаж";
$companyNameHuman = "ООО «Форсаж»";
$companyAddr = "121059, г.Москва, ул.Киевская, д.14, оф.2а";
$bankName    = "ООО Банк Точка";
$personalAcc = "40702810101500033019";
$correspAcc  = "30101810745374525104";
$bic         = "044525104";
$inn         = "7728282160";
$kpp         = "773001001";

// строка для QR ST00012[web:498][web:497]
$qrData = "ST00012"
    . "|Name={$companyName}"
    . "|PersonalAcc={$personalAcc}"
    . "|BankName={$bankName}"
    . "|BIC={$bic}"
    . "|CorrespAcc={$correspAcc}"
    . "|PayeeINN={$inn}"
    . "|KPP={$kpp}"
    . "|Sum={$sumKopecks}"
    . "|Purpose={$purpose}";

$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=420x420&data=' . urlencode($qrData);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Оплата по QR — повышение статуса</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden; /* вообще запрещаем скролл */
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #111827, #020617);
            color: #e5e7eb;
        }

        .viewport {
            height: 100%;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px; /* небольшой отступ по краям */
        }

        .card {
            width: 100%;
            max-width: 520px;
            max-height: 100%;
            background: rgba(15,23,42,0.96);
            border-radius: 22px;
            border: 1px solid rgba(148,163,184,0.4);
            padding: 14px 16px 12px;
            box-shadow: 0 20px 50px rgba(15,23,42,0.7);
            display: flex;
            flex-direction: column;
        }

        .header {
            margin-bottom: 6px;
        }

        h1 {
            margin: 0;
            font-size: 18px;
        }

        .sub {
            margin-top: 2px;
            font-size: 12px;
            color: #9ca3af;
        }

        .qr-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 6px 0;
        }

        .qr-box {
            background: #020617;
            border-radius: 16px;
            padding: 8px;
            display: inline-flex;
        }

        .qr-box img {
            display: block;
            width: 100%;
            height: auto;
            max-width: 360px;
            max-height: 60vh; /* чтобы не вылезало за экран */
        }

        .amount-block {
            font-size: 14px;
            margin-top: 2px;
        }

        .amount-block b {
            font-size: 19px;
        }

        .vat {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .purpose {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }

        .req {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 6px;
            line-height: 1.5;
        }

        .buttons {
            display: flex;
            gap: 6px;
            margin-top: 8px;
        }

        .btn {
            flex: 1;
            min-width: 0;
            padding: 8px 10px;
            border-radius: 9px;
            border: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-main {
            background: #22c55e;
            color: #022c22;
        }

        .btn-main:hover {
            background: #16a34a;
        }

        .btn-secondary {
            background: transparent;
            color: #e5e7eb;
            border: 1px solid rgba(148,163,184,0.6);
        }

        .btn-secondary:hover {
            border-color: #e5e7eb;
        }

        @media (max-width: 480px) {
            .card {
                border-radius: 16px;
                padding: 10px 10px 8px;
            }
            h1 {
                font-size: 17px;
            }
            .qr-box img {
                max-width: 300px;
                max-height: 55vh;
            }
        }
    </style>
</head>
<body>
<div class="viewport">
    <div class="card">
        <div class="header">
            <h1>Оплата повышения статуса</h1>
            <div class="sub">
                <?= htmlspecialchars($fullName ?: $username, ENT_QUOTES, 'UTF-8') ?>
                (ID<?= $userId ?>), статус: <?= htmlspecialchars($targetStatus, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <div class="qr-wrap">
            <div class="qr-box">
                <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR для оплаты">
            </div>
        </div>

        <div class="amount-block">
            Сумма к оплате: <b><?= number_format($amount, 2, ',', ' ') ?> ₽</b>
            <div class="vat">
                В т.ч. НДС 22%: <?= number_format($vat, 2, ',', ' ') ?> ₽
            </div>
        </div>

        <div class="purpose">
            Назначение: <?= htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="req">
            Получатель: <?= htmlspecialchars($companyNameHuman, ENT_QUOTES, 'UTF-8') ?>,
            ИНН <?= $inn ?>, КПП <?= $kpp ?>.<br>
            Адрес: <?= htmlspecialchars($companyAddr, ENT_QUOTES, 'UTF-8') ?>.<br>
            Банк: <?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?>,
            БИК <?= $bic ?>, р/с <?= $personalAcc ?>, к/с <?= $correspAcc ?>.
        </div>

        <div class="buttons">
            <button class="btn btn-main" onclick="window.print();">🖨 Распечатать / PDF</button>
            <button class="btn btn-secondary" onclick="window.close();">Закрыть</button>
        </div>
    </div>
</div>
</body>
</html>
