<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';
date_default_timezone_set('Europe/Moscow');

$lot_id      = 6;
$start_price = 15000;
$duration    = "+24 hours";

// Простая защита — только для авторизованных (опционально раскомментировать)
// if (empty($_SESSION['user_id'])) { http_response_code(403); die("Нет доступа"); }

try {
    $new_end_time = date("Y-m-d H:i:s", strtotime($duration));

    // Сбрасываем лот: цена, время, лидер, и обнуляем started_at
    $stmt = $pdo->prepare(
        "UPDATE lots
         SET price = ?, last_bid_user = NULL, end_time = ?, started_at = NULL
         WHERE id = ?"
    );
    $stmt->execute([$start_price, $new_end_time, $lot_id]);

    // Удаляем все ставки этого лота
    $pdo->prepare("DELETE FROM bids WHERE lot_id = ?")->execute([$lot_id]);

    // Очищаем лог-файл
    $log_file = "logs/lot_{$lot_id}.txt";
    if (file_exists($log_file)) {
        unlink($log_file);
    }

    $success = true;
} catch (Exception $e) {
    error_log("reset.php error: " . $e->getMessage());
    $success = false;
    $error   = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сброс торгов</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            background: #0f172a;
            color: #fff;
            font-family: sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .card {
            background: #1e293b;
            padding: 40px 48px;
            border-radius: 24px;
            border: 1px solid #334155;
            text-align: center;
            max-width: 420px;
            width: 100%;
        }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 10px; }
        p  { color: #94a3b8; font-size: 14px; margin: 0 0 24px; line-height: 1.6; }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 15px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-danger  { background: #ef4444; color: #fff; }
        .btn-danger:hover  { background: #dc2626; }
        .btn-gray    { background: #334155; color: #94a3b8; margin-left: 10px; }
        .btn-gray:hover { background: #3d5068; color: #fff; }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #334155;
            font-size: 13px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-box {
            background: #0f172a;
            border-radius: 12px;
            padding: 4px 16px;
            margin-bottom: 24px;
            text-align: left;
        }
    </style>
</head>
<body>
<div class="card">
    <?php if ($success): ?>
        <div class="icon">✅</div>
        <h1>Торги перезапущены</h1>
        <div class="info-box">
            <div class="info-row">
                <span style="color:#64748b;">Лот</span>
                <span>№<?= $lot_id ?></span>
            </div>
            <div class="info-row">
                <span style="color:#64748b;">Начальная цена</span>
                <span><?= number_format($start_price, 0, '.', ' ') ?> ₽</span>
            </div>
            <div class="info-row">
                <span style="color:#64748b;">Длительность</span>
                <span>24 часа</span>
            </div>
            <div class="info-row">
                <span style="color:#64748b;">Ставки</span>
                <span style="color:#4ade80;">Очищены</span>
            </div>
            <div class="info-row">
                <span style="color:#64748b;">История торгов</span>
                <span style="color:#4ade80;">Удалена</span>
            </div>
        </div>
        <a class="btn btn-primary" href="lot_details.php?id=<?= $lot_id ?>">Перейти к лоту</a>
        <a class="btn btn-gray" href="reestr.php">Реестр</a>
    <?php else: ?>
        <div class="icon">❌</div>
        <h1>Ошибка сброса</h1>
        <p>Не удалось перезапустить торги. Проверьте логи сервера.</p>
        <a class="btn btn-gray" href="reestr.php">Вернуться в реестр</a>
    <?php endif; ?>
</div>
</body>
</html>
