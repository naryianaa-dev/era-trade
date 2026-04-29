<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
date_default_timezone_set('Europe/Moscow');

if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$pack_only = isset($_GET['pack_only']);

$stmt = $pdo->prepare("SELECT username, balance, user_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= $pack_only ? 'Покупка пакета ставок' : 'Пополнение баланса' ?> — Форсаж</title>
    <style>
        body { background: #0f172a; color: #fff; font-family: sans-serif; padding: 20px; }
        .container { max-width: 500px; margin: auto; background: #1e293b; border-radius: 20px; padding: 24px; }
        h2 { text-align: center; margin-top: 0; }
        .balance { background: #0f172a; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
        .balance .label { font-size: 13px; color: #94a3b8; }
        .balance .value { font-size: 32px; font-weight: bold; color: #4ade80; }
        .btn { display: block; width: 100%; padding: 14px; background: #f59e0b; color: #000; font-weight: bold; text-align: center; border: none; border-radius: 12px; cursor: pointer; font-size: 16px; margin-top: 16px; text-decoration: none; }
        .btn:hover { background: #d97706; }
        .warning { background: #854d0e; padding: 10px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; text-align: center; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #64748b; font-size: 12px; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <h2><?= $pack_only ? '📦 Покупка пакета ставок' : '💰 Пополнение баланса' ?></h2>
    <div class="balance">
        <div class="label">Текущий баланс</div>
        <div class="value"><?= number_format($user['balance'], 0, '.', ' ') ?> ₽</div>
        <div class="label"><?= htmlspecialchars($user['username']) ?></div>
    </div>

    <?php if ($pack_only): ?>
        <div class="warning">⚠️ Во время активного скандинавского аукциона обычное пополнение баланса недоступно. Вы можете приобрести только пакет ставок.</div>
        <button class="btn" id="buyPackBtn">Купить пакет 10 ставок за 18 680 ₽</button>
    <?php else: ?>
        <button class="btn" id="buyPackBtn2">Купить пакет 10 ставок за 18 680 ₽</button>
        <hr style="border-color:#334155; margin: 20px 0;">
        <p style="text-align:center;">Обычное пополнение баланса (QR, квитанция) доступно только когда нет активных скандинавских аукционов.</p>
    <?php endif; ?>
    <a class="back-link" href="reestr.php">← Вернуться в реестр</a>
</div>
<script>
    function openPackPayment() {
        window.open('pack_payment.php?amount=18680', '_blank', 'width=600,height=700');
    }
    document.getElementById('buyPackBtn')?.addEventListener('click', openPackPayment);
    document.getElementById('buyPackBtn2')?.addEventListener('click', openPackPayment);
</script>
</body>
</html>