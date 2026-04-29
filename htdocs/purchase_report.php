<?php
// purchase_report.php - Обработчик покупки отчёта
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Необходима авторизация']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$lot_id = (int)($input['lot_id'] ?? 0);

if (!$lot_id) {
    echo json_encode(['success' => false, 'error' => 'Некорректный ID товара']);
    exit;
}

try {
    // Проверяем лот
    $stmt = $pdo->prepare("SELECT * FROM lots WHERE id = ? AND auction_type = 'commission'");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lot) {
        echo json_encode(['success' => false, 'error' => 'Товар не найден']);
        exit;
    }

    /* Стоимость отчёта: индивидуальная (lot.report_price) или дефолт 1390 ₽. */
    $report_price = (isset($lot['report_price']) && $lot['report_price'] !== null && $lot['report_price'] !== '')
        ? (int)$lot['report_price']
        : 1390;
    
    // Проверяем, не покупал ли уже этот пользователь отчёт по этому лоту
    $stmt = $pdo->prepare("
        SELECT * FROM vehicle_reports 
        WHERE lot_id = ? AND buyer_id = ? AND status IN ('paid', 'delivered')
    ");
    $stmt->execute([$lot_id, $user_id]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Вы уже приобрели отчёт по этому автомобилю']);
        exit;
    }
    
    // Получаем данные пользователя
    $stmt = $pdo->prepare("SELECT balance, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Проверяем баланс
    if ($user['balance'] >= $report_price) {
        // Оплата с баланса
        $pdo->beginTransaction();
        
        // Списываем с баланса
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
            ->execute([$report_price, $user_id]);
        
        // Создаём заказ отчёта
        $stmt = $pdo->prepare("
            INSERT INTO vehicle_reports 
            (lot_id, buyer_id, price, payment_method, status, created_at, paid_at)
            VALUES (?, ?, ?, 'balance', 'paid', NOW(), NOW())
        ");
        $stmt->execute([$lot_id, $user_id, $report_price]);
        
        $report_id = $pdo->lastInsertId();
        
        // Логируем
        if (function_exists('logAction')) {
            logAction($pdo, $user_id, 'report_purchase', "Покупка отчёта для лота #{$lot_id}");
        }
        
        $pdo->commit();
        
        // TODO: Отправить email с отчётом или уведомлением
        
        echo json_encode([
            'success' => true,
            'report_id' => $report_id,
            'message' => 'Отчёт оплачен с баланса'
        ]);
        
    } else {
        // Недостаточно средств - создаём заказ и перенаправляем на оплату
        $stmt = $pdo->prepare("
            INSERT INTO vehicle_reports 
            (lot_id, buyer_id, price, payment_method, status, created_at)
            VALUES (?, ?, ?, 'pending', 'pending', NOW())
        ");
        $stmt->execute([$lot_id, $user_id, $report_price]);
        
        $report_id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'report_id' => $report_id,
            'payment_url' => "report_payment.php?id={$report_id}",
            'message' => 'Перенаправление на оплату'
        ]);
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
