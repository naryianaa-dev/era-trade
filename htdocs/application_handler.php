<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    
    if (!$user_id || !$app_id) {
        echo json_encode(['success' => false, 'msg' => 'Ошибка']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ? AND user_id = ?");
    $stmt->execute([$app_id, $user_id]);
    
    echo json_encode(['success' => true]);
}