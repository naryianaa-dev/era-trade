<?php
// check_upgrade_status.php - Проверка статуса оплаты повышения статуса
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
header('Content-Type: application/json');

$upgrade_id = (int)($_GET['id'] ?? 0);

if (!$upgrade_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT status, paid_at, target_status 
        FROM status_upgrades 
        WHERE id = ?
    ");
    $stmt->execute([$upgrade_id]);
    $upgrade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$upgrade) {
        echo json_encode(['status' => 'error', 'message' => 'Not found']);
        exit;
    }
    
    echo json_encode([
        'status' => $upgrade['status'],
        'paid_at' => $upgrade['paid_at'],
        'target_status' => $upgrade['target_status']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
