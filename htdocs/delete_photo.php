<?php
$l_id = $_GET['lot_id'];
$p_idx = $_GET['photo_idx'];
$file = 'lots.json';

$lots = json_decode(file_get_contents($file), true);
if (isset($lots[$l_id]['files'][$p_idx])) {
    unset($lots[$l_id]['files'][$p_idx]);
    $lots[$l_id]['files'] = array_values($lots[$l_id]['files']); // пересборка индексов
    file_put_contents($file, json_encode($lots, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
header("Location: edit_lot.php?id=" . $l_id);