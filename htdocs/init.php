<?php
$dirs = ['uploads', 'cheks'];
foreach($dirs as $d) { if(!is_dir($d)) mkdir($d, 0777); }
if(!file_exists('lots.json')) file_put_contents('lots.json', json_encode([]));
echo "Готово! Удали этот файл.";
?>