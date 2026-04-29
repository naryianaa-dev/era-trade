<?php
// upgrade_receipt.php
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
        SELECT su.*, u.username, u.email, u.full_name, u.entity_type
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

$status_names = [
    'responsible' => 'Статус "Ответственный"',
    'organizer'   => 'Статус "Организатор" (12 месяцев)'
];

$purpose = "Повышение статуса пользователя #{$upgrade['user_id']} до уровня \"{$status_names[$upgrade['target_status']]}\"";
if ($upgrade['express_fee'] > 0) {
    $purpose .= " + экспресс-обработка";
}

// Данные организации
$org_name = "ООО «Форсаж»";
$org_address = "121059, г.Москва, ул.Киевская, д.14, оф.2а";
$org_bank = "ООО «Банк Точка»";
$org_account = "40702810101500033019";
$org_corr_account = "30101810745374525104";
$org_bic = "044525104";
$org_inn = "7728282160";
$org_kpp = "773001001";

// Генерируем QR-код для СБП
$qr_content = "ST00012|Name={$org_name}|PersonalAcc={$org_account}|BankName={$org_bank}|BIC={$org_bic}|CorrespAcc={$org_corr_account}|PayeeINN={$org_inn}|KPP={$org_kpp}|Sum=" . ($upgrade['total_amount'] * 100) . "|Purpose={$purpose}";
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qr_content);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Квитанция на оплату #<?= $upgrade_id ?></title>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            line-height: 1.4;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        
        .receipt-header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .receipt-number {
            color: #666;
            font-size: 14px;
        }
        
        .info-section {
            margin-bottom: 25px;
        }
        
        .info-section h2 {
            font-size: 16px;
            margin-bottom: 12px;
            color: #333;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .info-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .info-label {
            color: #666;
        }
        
        .info-value {
            font-weight: 600;
        }
        
        .qr-section {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        
        .qr-section img {
            margin: 15px 0;
        }
        
        .qr-hint {
            font-size: 13px;
            color: #666;
            margin-top: 10px;
        }
        
        .amount-section {
            background: #f0f7ff;
            padding: 20px;
            margin: 25px 0;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }
        
        .amount-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 15px;
        }
        
        .amount-row.total {
            border-top: 2px solid #3b82f6;
            margin-top: 10px;
            padding-top: 15px;
            font-size: 18px;
            font-weight: 700;
        }
        
        .print-button {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 14px 32px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            display: block;
            margin: 30px auto 0;
            transition: background 0.2s;
        }
        
        .print-button:hover {
            background: #2563eb;
        }
        
        .footer-note {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h1>Квитанция на оплату</h1>
            <div class="receipt-number">№ <?= str_pad($upgrade_id, 6, '0', STR_PAD_LEFT) ?> от <?= date('d.m.Y H:i') ?></div>
        </div>
        
        <div class="info-section">
            <h2>Получатель платежа</h2>
            <div class="info-row">
                <div class="info-label">Наименование:</div>
                <div class="info-value"><?= $org_name ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Адрес:</div>
                <div class="info-value"><?= $org_address ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">ИНН/КПП:</div>
                <div class="info-value"><?= $org_inn ?> / <?= $org_kpp ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Банк:</div>
                <div class="info-value"><?= $org_bank ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Расчётный счёт:</div>
                <div class="info-value"><?= $org_account ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Корр. счёт:</div>
                <div class="info-value"><?= $org_corr_account ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">БИК:</div>
                <div class="info-value"><?= $org_bic ?></div>
            </div>
        </div>
        
        <div class="info-section">
            <h2>Плательщик</h2>
            <div class="info-row">
                <div class="info-label">Имя:</div>
                <div class="info-value"><?= htmlspecialchars($upgrade['full_name'] ?: $upgrade['username']) ?></div>
            </div>
            <?php if ($upgrade['email']): ?>
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div class="info-value"><?= htmlspecialchars($upgrade['email']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="info-section">
            <h2>Назначение платежа</h2>
            <div class="info-row">
                <div class="info-value" style="grid-column: 1 / -1;">
                    <?= htmlspecialchars($purpose) ?>
                </div>
            </div>
        </div>
        
        <div class="amount-section">
            <?php if ($upgrade['base_price'] > 0): ?>
            <div class="amount-row">
                <span>Стоимость повышения статуса:</span>
                <span><?= number_format($upgrade['base_price'], 0, '.', ' ') ?> ₽</span>
            </div>
            <?php endif; ?>
            <?php if ($upgrade['express_fee'] > 0): ?>
            <div class="amount-row">
                <span>Экспресс-обработка (24 часа):</span>
                <span><?= number_format($upgrade['express_fee'], 0, '.', ' ') ?> ₽</span>
            </div>
            <?php endif; ?>
            <div class="amount-row total">
                <span>Итого к оплате:</span>
                <span><?= number_format($upgrade['total_amount'], 0, '.', ' ') ?> ₽</span>
            </div>
            <div style="font-size: 12px; color: #666; margin-top: 8px;">
                Сумма включает НДС 22%
            </div>
        </div>
        
        <div class="qr-section">
            <div style="font-weight: 600; margin-bottom: 10px;">Оплатить через СБП (Система быстрых платежей)</div>
            <img src="<?= $qr_url ?>" alt="QR-код для оплаты" width="200" height="200">
            <div class="qr-hint">
                Отсканируйте QR-код в приложении вашего банка для быстрой оплаты
            </div>
        </div>
        
        <button class="print-button no-print" onclick="window.print()">
            🖨️ Печать квитанции
        </button>
        
        <div class="footer-note">
            Квитанция сформирована автоматически. Подпись и печать не требуются.<br>
            При оплате укажите номер квитанции <?= str_pad($upgrade_id, 6, '0', STR_PAD_LEFT) ?> в назначении платежа.
        </div>
    </div>
</body>
</html>
