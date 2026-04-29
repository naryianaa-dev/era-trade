<?php
session_start();

$id = $_GET['id'] ?? null;
$file_active = 'lots.json';
$file_archive = 'archive.json';

if ($id !== null && file_exists($file_active)) {
    $lots = json_decode(file_get_contents($file_active), true) ?: [];
    
    if (isset($lots[$id])) {
        // 1. Загружаем текущий архив (если он есть)
        $archive = file_exists($file_archive) ? json_decode(file_get_contents($file_archive), true) : [];
        
        // 2. Копируем лот в архив и добавляем дату удаления
        $lots[$id]['deleted_at'] = date('d.m.Y H:i');
        $archive[$id] = $lots[$id];
        
        // 3. Удаляем из основного списка
        unset($lots[$id]);
        
        // 4. Сохраняем оба файла
        file_put_contents($file_active, json_encode($lots, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($file_archive, json_encode($archive, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

header("Location: reestr.php");
exit;