<?php
session_start();
$file_archive = 'archive.json';
$archive = file_exists($file_archive) ? json_decode(file_get_contents($file_archive), true) : [];

// Логика восстановления
if (isset($_GET['restore'])) {
    $id = $_GET['restore'];
    $file_active = 'lots.json';
    $lots = json_decode(file_get_contents($file_active), true) ?: [];
    
    if (isset($archive[$id])) {
        $lots[$id] = $archive[$id];
        unset($lots[$id]['deleted_at']);
        unset($archive[$id]);
        
        file_put_contents($file_active, json_encode($lots, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($file_archive, json_encode($archive, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header("Location: reestr.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Корзина (Архив)</title>
    <style>
        body { background: #020617; color: white; font-family: sans-serif; padding: 20px; }
        .item { background: #1e293b; padding: 15px; border-radius: 10px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .btn-restore { background: #10b981; color: white; text-decoration: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>🗑️ Корзина удаленных лотов</h1>
    <a href="reestr.php" style="color: #64748b; text-decoration: none;">← Вернуться в реестр</a>
    <hr style="border-color: #334155; margin: 20px 0;">

    <?php if (empty($archive)): ?>
        <p style="color: #64748b;">В корзине пока пусто.</p>
    <?php else: ?>
        <?php foreach ($archive as $id => $lot): ?>
            <div class="item">
                <div>
                    <strong>#<?=$id?> <?=$lot['name']?></strong><br>
                    <small style="color: #ef4444;">Удален: <?=$lot['deleted_at']?></small>
                </div>
                <a href="?restore=<?=$id?>" class="btn-restore">Восстановить</a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>