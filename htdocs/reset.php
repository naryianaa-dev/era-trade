<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
include 'db.php';
date_default_timezone_set('Europe/Moscow');

$lot_id      = 6;
$start_price = 15000;
$duration    = '+24 hours';

try {
    $new_end_time = date('Y-m-d H:i:s', strtotime($duration));
    $now_php = date('Y-m-d H:i:s');
    
    $pdo->prepare("
        UPDATE lots 
        SET 
            price = :price,
            start_price = :price,
            last_bid_user = NULL,
            winner_id = NULL,
            winner_price = NULL,
            total_bids = 0,
            end_time = :end_time,
            started_at = :started_at,
            auction_status = 'active'
        WHERE id = :id
    ")->execute([
        ':price' => $start_price,
        ':end_time' => $new_end_time,
        ':started_at' => $now_php,
        ':id' => $lot_id
    ]);
    
    $pdo->prepare("DELETE FROM bids WHERE lot_id = ?")->execute([$lot_id]);
    $pdo->prepare("UPDATE users SET credit_bids_remaining = 5")->execute(); // Сброс кредитного лимита
    
    @unlink(__DIR__ . "/logs/lot_{$lot_id}.txt");
    @unlink(__DIR__ . "/start_time_lot_{$lot_id}.txt");
    file_put_contents(__DIR__ . "/start_time_lot_{$lot_id}.txt", time());
    $success = true;
} catch (Exception $e) {
    $success = false;
}
?>
<!DOCTYPE html>
<html>
<head><title>Сброс аукциона</title></head>
<body style="background:#0f172a;color:#fff;font-family:sans-serif;text-align:center;padding-top:50px;">
<?php if ($success): ?>
    <h2 style="color:#4ade80;">✅ Лот №<?= $lot_id ?> перезапущен</h2>
    <p>Начальная цена: <?= number_format($start_price,0,'.',' ') ?> ₽</p>
    <p><a href="lot_scandinavian.php?id=<?= $lot_id ?>" style="color:#f59e0b;">Перейти к лоту</a></p>
<?php else: ?>
    <h2 style="color:#ef4444;">❌ Ошибка сброса</h2>
<?php endif; ?>
</body>
</html>