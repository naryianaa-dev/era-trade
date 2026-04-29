<?php
// upgrade_qr.php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

$upgrade_id = (int)($_GET['id'] ?? 0);

if (!$upgrade_id) {
    die('Некорректный ID');
}

try {
    $stmt = $pdo->prepare("
        SELECT su.*, u.username, u.email, u.full_name
        FROM status_upgrades su
        JOIN users u ON u.id = su.user_id
        WHERE su.id = ?
    ");
    $stmt->execute([$upgrade_id]);
    $upgrade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$upgrade) {
        die('Заказ не найден');
    }
    
} catch (Exception $e) {
    die('Ошибка БД: ' . $e->getMessage());
}

$amount = (float)$upgrade['total_amount'];
$vat    = round($amount * 22 / 122, 2);

$status_names = [
    'responsible' => 'Статус "Ответственный"',
    'organizer'   => 'Статус "Организатор"'
];

$purpose = "Повышение статуса #{$upgrade['user_id']}";
if ($upgrade['express_fee'] > 0) {
    $purpose .= " + экспресс";
}

// Данные для QR
$org_name = "ООО Форсаж";
$org_account = "40702810101500033019";
$org_bank = "ООО Банк Точка";
$org_bic = "044525104";
$org_corr = "30101810745374525104";
$org_inn = "7728282160";
$org_kpp = "773001001";

$qr_content = "ST00012|Name={$org_name}|PersonalAcc={$org_account}|BankName={$org_bank}|BIC={$org_bic}|CorrespAcc={$org_corr}|PayeeINN={$org_inn}|KPP={$org_kpp}|Sum=" . ($upgrade['total_amount'] * 100) . "|Purpose={$purpose}";
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qr_content);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата повышения статуса</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .payment-card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 24px;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .header .subtitle {
            color: #64748b;
            font-size: 14px;
        }
        
        .qr-container {
            background: #f8fafc;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            margin-bottom: 25px;
        }
        
        .qr-container img {
            border: 4px solid white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .amount-display {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 25px;
        }
        
        .amount-label {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        
        .amount-value {
            font-size: 36px;
            font-weight: 800;
        }
        
        .info-section {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #64748b;
            font-size: 14px;
        }
        
        .info-value {
            color: #1e293b;
            font-weight: 600;
            font-size: 14px;
        }
        
        .instructions {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        
        .instructions-title {
            font-weight: 600;
            color: #92400e;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .instructions ol {
            margin-left: 20px;
            color: #78350f;
            font-size: 13px;
            line-height: 1.6;
        }
        
        .instructions li {
            margin: 6px 0;
        }
        
        .button-group {
            display: grid;
            gap: 12px;
        }
        
        .btn {
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        @media (max-width: 480px) {
            .payment-card {
                padding: 24px;
            }
            
            .amount-value {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="payment-card">
        <div class="header">
            <h1>Оплата через СБП</h1>
            <div class="subtitle">Система быстрых платежей</div>
        </div>
        
        <div class="qr-container">
            <img src="<?= $qr_url ?>" alt="QR-код для оплаты" width="300" height="300">
        </div>
        
        <div class="amount-display">
            <div class="amount-label">К оплате</div>
            <div class="amount-value"><?= number_format($upgrade['total_amount'], 0, '.', ' ') ?> ₽</div>
        </div>
        <div>Сумма: <?= number_format($amount, 2, ',', ' ') ?> ₽</div>
<div>В т.ч. НДС 22%: <?= number_format($vat, 2, ',', ' ') ?> ₽</div>
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">Заказ:</span>
                <span class="info-value">#<?= str_pad($upgrade_id, 6, '0', STR_PAD_LEFT) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Услуга:</span>
                <span class="info-value"><?= $status_names[$upgrade['target_status']] ?></span>
            </div>
            <?php if ($upgrade['express_fee'] > 0): ?>
            <div class="info-row">
                <span class="info-label">Доп. опция:</span>
                <span class="info-value">⚡ Экспресс-обработка</span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label">Получатель:</span>
                <span class="info-value">ООО «Форсаж»</span>
            </div>
        </div>
        
        <div class="instructions">
            <div class="instructions-title">📱 Как оплатить:</div>
            <ol>
                <li>Откройте мобильное приложение вашего банка</li>
                <li>Найдите раздел «Платежи по QR» или «СБП»</li>
                <li>Наведите камеру на QR-код выше</li>
                <li>Проверьте сумму и подтвердите платёж</li>
            </ol>
        </div>
        
        <?php if ($upgrade['status'] === 'pending'): ?>
        <div class="status-badge">
            ⏳ Ожидает оплаты
        </div>
        <?php endif; ?>
        
        <div class="button-group">
            <a href="upgrade_receipt.php?id=<?= $upgrade_id ?>" class="btn btn-secondary" target="_blank">
                🧾 Скачать квитанцию
            </a>
            <button onclick="window.close()" class="btn btn-secondary">
                ← Вернуться в личный кабинет
            </button>
        </div>
    </div>
    
    <script>
    // Проверка статуса оплаты каждые 5 секунд
    let checkInterval = setInterval(() => {
        fetch('check_upgrade_status.php?id=<?= $upgrade_id ?>')
            .then(r => r.json())
            .then(data => {
                if (data.status === 'paid') {
                    clearInterval(checkInterval);
                    alert('Оплата получена! Ваш статус будет повышен в ближайшее время.');
                    window.location.href = 'profile.php';
                }
            })
            .catch(e => console.error(e));
    }, 5000);
    </script>
</body>
</html>
