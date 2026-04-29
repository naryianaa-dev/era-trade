<?php
require_once __DIR__ . '/admin_only.php';
session_start();
if (!isset($_SESSION['admin'])) { header("Location: login.php"); exit; }

$file = 'bids.json';
$bids = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
krsort($bids);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заявки от клиентов</title>
    <style>
        body { background: #020617; color: white; font-family: sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; background: #0f172a; border-radius: 10px; overflow: hidden; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #1e293b; }
        th { background: #1e293b; color: #94a3b8; font-size: 12px; text-transform: uppercase; }
    </style>
</head>
<body>
    <div style="max-width: 1000px; margin: 0 auto;">
        <header style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h1>Новые заявки</h1>
            <a href="reestr.php" style="color:#38bdf8; text-decoration:none;">← В реестр</a>
        </header>

        <table>
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Лот</th>
                    <th>Имя</th>
                    <th>Телефон</th>
                    <th>Предложение</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bids as $bid): ?>
                <tr>
                    <td><?=$bid['date']?></td>
                    <td style="color:#eab308;"><?=$bid['lot_name']?></td>
                    <td><?=$bid['user_name']?></td>
                    <td><?=$bid['user_phone']?></td>
                    <td style="font-weight:bold; color:#10b981;"><?=number_format($bid['offer_price'], 0, '', ' ')?> ₽</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
