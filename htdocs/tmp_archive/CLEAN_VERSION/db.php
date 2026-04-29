<?php
$host = 'sql102.infinityfree.com'; // Проверь этот адрес в панели хостинга!
$db   = 'if0_41359384_era';
$user = 'if0_41359384';
$pass = 'wd41sm3f'; // Тот, что в панели управления хостингом

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
} catch (PDOException $e) {
    die("Ошибка базы: " . $e->getMessage());
}


if (!function_exists('logAction')) {
    function logAction($pdo, $user_id, $type, $desc) {
        try {
            $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action_type, description) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $type, $desc]);
        } catch (Exception $e) {}
    }
}
?>