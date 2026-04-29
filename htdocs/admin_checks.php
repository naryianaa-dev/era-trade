<?php
require_once __DIR__ . '/admin_only.php';
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') { die("Доступ только для БОССА!"); }

$checks_dir = 'cheks/';
$files = is_dir($checks_dir) ? array_diff(scandir($checks_dir), array('.', '..')) : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление чеками — ФОРСАЖ</title>
    <style>
        body { background: #0f172a; color: white; font-family: sans-serif; padding: 20px; }
        .nav { background: #1e293b; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #334155; }
        .check-card { background: #1e293b; padding: 20px; border-radius: 10px; margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; border: 1px solid #334155; }
        .check-img { width: 100px; height: 100px; object-fit: cover; border-radius: 5px; cursor: pointer; border: 2px solid #38bdf8; }
        .info { flex-grow: 1; margin-left: 20px; }
        .btn-confirm { background: #10b981; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .btn-delete { background: #ef4444; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px; }
    </style>
</head>
<body>
    <div class="nav">
        <a href="reestr.php" style="color: #38bdf8; text-decoration: none;">← В реестр</a> | 
        <b>ПАНЕЛЬ ПРОВЕРКИ ЧЕКОВ</b>
    </div>

    <h2>Новые поступления</h2>

    <?php if (empty($files)): ?>
        <p style="color: #94a3b8;">Чеков пока нет. Ждем профит!</p>
    <?php else: ?>
        <?php foreach ($files as $file): ?>
            <div class="check-card">
                <a href="<?=$checks_dir . $file?>" target="_blank">
                    <img src="<?=$checks_dir . $file?>" class="check-img" title="Нажми, чтобы увеличить">
                </a>
                <div class="info">
                    <div style="font-size: 18px; font-weight: bold;"><?=$file?></div>
                    <div style="color: #94a3b8; font-size: 14px;">Дата: <?=date("d.m.Y H:i", filemtime($checks_dir . $file))?></div>
                </div>
                <div>
                    <button class="btn-confirm" onclick="alert('Бабки зачислены! (Функция в разработке)')">ПОДТВЕРДИТЬ</button>
                    <button class="btn-delete" onclick="alert('Удалено!')">УДАЛИТЬ</button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>
