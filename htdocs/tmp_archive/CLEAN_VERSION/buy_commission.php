<?php
// buy_commission.php - Страница оформления покупки
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

if (empty($_SESSION['user_id'])) {
    header('Location: index.php?action=login');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$lot_id = (int)($_GET['id'] ?? 0);

if (!$lot_id) {
    die('Некорректный ID товара');
}

try {
    // Получаем данные лота
    $stmt = $pdo->prepare("
        SELECT l.*, u.username AS seller_name, u.email AS seller_email
        FROM lots l
        JOIN users u ON u.id = l.owner_id
        WHERE l.id = ? AND l.auction_type = 'commission'
    ");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lot) {
        die('Товар не найден');
    }
    
    if ($lot['owner_id'] == $user_id) {
        die('Вы не можете купить свой товар');
    }
    
    if ($lot['auction_status'] === 'finished') {
        die('Товар уже продан');
    }
    
    // Получаем данные покупателя
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $buyer = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    die('Ошибка БД: ' . $e->getMessage());
}

// Обработка покупки
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? 'balance';
    
    try {
        $pdo->beginTransaction();
        
        if ($payment_method === 'balance') {
            // Проверяем баланс
            if ($buyer['balance'] < $lot['price']) {
                throw new Exception('Недостаточно средств на балансе');
            }
            
            // Списываем с баланса покупателя
            $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
                ->execute([$lot['price'], $user_id]);
            
            // Зачисляем продавцу (минус комиссия, например 10%)
            $commission = $lot['price'] * 0.10;
            $seller_amount = $lot['price'] - $commission;
            
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
                ->execute([$seller_amount, $lot['owner_id']]);
        }
        
        // Создаём транзакцию
        $stmt = $pdo->prepare("
            INSERT INTO commission_transactions 
            (lot_id, buyer_id, seller_id, amount, commission, payment_method, created_at, status)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $lot_id,
            $user_id,
            $lot['owner_id'],
            $lot['price'],
            $commission ?? 0,
            $payment_method,
            $payment_method === 'balance' ? 'completed' : 'pending'
        ]);
        
        $transaction_id = $pdo->lastInsertId();
        
        // Обновляем статус лота
        if ($payment_method === 'balance') {
            $pdo->prepare("UPDATE lots SET auction_status = 'finished', winner_id = ? WHERE id = ?")
                ->execute([$user_id, $lot_id]);
        }
        
        // Логируем
        if (function_exists('logAction')) {
            logAction($pdo, $user_id, 'commission_purchase', "Покупка товара #{$lot_id} за {$lot['price']} ₽");
        }
        
        $pdo->commit();
        
        if ($payment_method === 'balance') {
            header('Location: commission_success.php?transaction=' . $transaction_id);
            exit;
        } else {
            header('Location: commission_payment.php?transaction=' . $transaction_id);
            exit;
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оформление покупки — <?= htmlspecialchars($lot['title']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        h1 {
            font-size: 28px;
            margin-bottom: 8px;
            color: #1e293b;
        }
        
        .subtitle {
            color: #64748b;
            margin-bottom: 32px;
        }
        
        .product-info {
            background: #f8fafc;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        
        .product-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .product-price {
            font-size: 32px;
            font-weight: 800;
            color: #3b82f6;
            margin-bottom: 16px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #64748b;
        }
        
        .info-value {
            font-weight: 600;
        }
        
        .payment-methods {
            margin-bottom: 24px;
        }
        
        .payment-method {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .payment-method:hover {
            border-color: #3b82f6;
        }
        
        .payment-method.selected {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        
        .payment-method input[type="radio"] {
            margin-right: 12px;
            accent-color: #3b82f6;
        }
        
        .payment-method label {
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .error {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛒 Оформление покупки</h1>
        <p class="subtitle">Проверьте информацию перед оплатой</p>
        
        <?php if (isset($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="product-info">
            <div class="product-title"><?= htmlspecialchars($lot['title']) ?></div>
            <div class="product-price"><?= number_format($lot['price'], 0, '.', ' ') ?> ₽</div>
            
            <div class="info-row">
                <span class="info-label">Продавец:</span>
                <span class="info-value"><?= htmlspecialchars($lot['seller_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Ваш баланс:</span>
                <span class="info-value"><?= number_format($buyer['balance'], 0, '.', ' ') ?> ₽</span>
            </div>
        </div>
        
        <form method="POST">
            <div class="payment-methods">
                <h3 style="margin-bottom: 16px;">Способ оплаты</h3>
                
                <div class="payment-method selected" onclick="selectPayment('balance')">
                    <input type="radio" name="payment_method" value="balance" id="pm-balance" checked>
                    <label for="pm-balance">💳 С баланса аккаунта</label>
                </div>
                
                <div class="payment-method" onclick="selectPayment('qr')">
                    <input type="radio" name="payment_method" value="qr" id="pm-qr">
                    <label for="pm-qr">📱 Оплата по QR (СБП)</label>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
                Подтвердить покупку
            </button>
        </form>
    </div>
    
    <script>
    function selectPayment(method) {
        document.querySelectorAll('.payment-method').forEach(el => {
            el.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
        document.getElementById('pm-' + method).checked = true;
    }
    </script>
</body>
</html>
