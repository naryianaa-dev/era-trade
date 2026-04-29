<?php
$host = 'sql102.infinityfree.com';
$db   = 'if0_41359384_era';
$user = 'if0_41359384';
$pass = 'wd41sm3f';

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Ошибка базы: ' . $e->getMessage());
}
