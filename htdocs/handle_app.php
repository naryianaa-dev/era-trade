<?php
session_start();
date_default_timezone_set('Europe/Moscow');
if (!isset($_SESSION['user'])) die("Auth error");

$user = $_SESSION['user']['username'];
$lot_id = $_POST['lot_id'];
$action = $_POST['action'];

$file = 'applications.json';
$apps = json_decode(file_get_contents($file), true) ?: [];

if ($action == 'apply') {
    $apps[] = ["lot_id" => $lot_id, "user" => $user, "status" => "pending", "time" => date("d.m.Y H:i:s")];
} else {
    foreach($apps as $k => $a) { if($a['lot_id'] == $lot_id && $a['user'] == $user) { unset($apps[$k]); break; } }
}

file_put_contents($file, json_encode(array_values($apps), JSON_UNESCAPED_UNICODE));
header("Location: lot.php?id=$lot_id");