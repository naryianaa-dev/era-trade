<?php
// commission_sale.php - Раздел комиссионной продажи (публичный доступ БЕЗ проверок авторизации)
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

// Получаем все лоты комиссионной продажи
$filter_status = $_GET['status'] ?? 'all';

$where = ["l.auction_type = 'commission'"];
$params = [];

if ($filter_status === 'active') {
    $where[] = "l.auction_status IN ('published', 'accepting', 'active')";
} elseif ($filter_status === 'sold') {
    $where[] = "l.auction_status = 'finished'";
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.username AS seller_name
        FROM lots l
        LEFT JOIN users u ON u.id = l.owner_id
        {$where_sql}
        ORDER BY l.created_at DESC
    ");
    $stmt->execute($params);
    $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lots = [];
}

$is_logged_in = !empty($_SESSION['user_id']);
$user_id = $is_logged_in ? (int)$_SESSION['user_id'] : 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Комиссионная продажа — ERA ETP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8fafc;
            margin: 0;
            padding: 0;
            color: #1e293b;
        }

        .header {
            background: linear-gradient(135deg, #1e293b, #334155);
            color: white;
            padding: 60px 20px;
            text-align: center;
        }

        .header h1 {
            margin: 0 0 12px;
            font-size: 42px;
            font-weight: 800;
        }

        .header p {
            margin: 0;
            font-size: 18px;
            opacity: 0.9;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .filters {
            display: flex;
            gap: 12px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 10px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 24px;
            background: white;
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .filter-btn:hover {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .filter-btn.active {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
        }

        .lots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }

        .lot-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
        }

        .lot-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            transform: translateY(-4px);
        }

        .lot-image {
            width: 100%;
            height: 240px;
            background: linear-gradient(135deg, #e0e7ff, #dbeafe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            position: relative;
        }

        .lot-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .status-active { color: #16a34a; }
        .status-sold { color: #64748b; }

        .lot-content {
            padding: 20px;
        }

        .lot-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 8px;
            color: #1e293b;
        }

        .lot-description {
            font-size: 14px;
            color: #64748b;
            margin: 0 0 16px;
            line-height: 1.5;
        }

        .lot-price {
            font-size: 28px;
            font-weight: 800;
            color: #3b82f6;
            margin-bottom: 20px;
        }

        .lot-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .action-btn {
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-offer {
            background: #f59e0b;
            color: white;
        }

        .btn-offer:hover {
            background: #d97706;
        }

        .btn-interest {
            background: #8b5cf6;
            color: white;
        }

        .btn-interest:hover {
            background: #7c3aed;
        }

        .btn-contact {
            background: #3b82f6;
            color: white;
        }

        .btn-contact:hover {
            background: #2563eb;
        }

        .btn-details {
            background: #10b981;
            color: white;
        }

        .btn-details:hover {
            background: #059669;
        }

        .btn-view {
            background: #f1f5f9;
            color: #475569;
            grid-column: 1 / -1;
        }

        .btn-view:hover {
            background: #e2e8f0;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .empty-state-text {
            font-size: 18px;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .header h1 { font-size: 32px; }
            .lots-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🛍️ Комиссионная продажа</h1>
        <p>Купите уникальные товары напрямую от продавцов</p>
    </div>

    <div class="container">
        <div class="filters">
            <a href="?status=all" class="filter-btn <?= $filter_status === 'all' ? 'active' : '' ?>">
                Все товары
            </a>
            <a href="?status=active" class="filter-btn <?= $filter_status === 'active' ? 'active' : '' ?>">
                В продаже
            </a>
            <a href="?status=sold" class="filter-btn <?= $filter_status === 'sold' ? 'active' : '' ?>">
                Проданные
            </a>
        </div>

        <?php if (empty($lots)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📦</div>
            <div class="empty-state-text">Товаров пока нет</div>
        </div>
        <?php else: ?>
        <div class="lots-grid">
            <?php foreach ($lots as $lot): 
                $is_sold = $lot['auction_status'] === 'finished';
                $is_owner = $is_logged_in && $lot['owner_id'] == $user_id;
            ?>
            <div class="lot-card">
                <div class="lot-image">
                    <?php if (!empty($lot['image_url'])): ?>
                        <img src="<?= htmlspecialchars($lot['image_url']) ?>" 
                             alt="<?= htmlspecialchars($lot['title']) ?>"
                             style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        📦
                    <?php endif; ?>
                    <div class="lot-status <?= $is_sold ? 'status-sold' : 'status-active' ?>">
                        <?= $is_sold ? 'Продано' : 'В продаже' ?>
                    </div>
                </div>
                
                <div class="lot-content">
                    <h3 class="lot-title"><?= htmlspecialchars($lot['title']) ?></h3>
                    <p class="lot-description">
                        <?= htmlspecialchars(mb_substr($lot['description'] ?? '', 0, 80)) ?>...
                    </p>
                    <div class="lot-price">
                        <?= number_format($lot['price'], 0, '.', ' ') ?> ₽
                    </div>
                    
                    <div class="lot-actions">
                        <?php if (!$is_sold && !$is_owner): ?>
                            <button class="action-btn btn-offer" onclick="makeOffer(<?= $lot['id'] ?>, <?= $lot['price'] ?>)">
                                💰 Предложить цену
                            </button>
                            <button class="action-btn btn-interest" onclick="showInterestForm(<?= $lot['id'] ?>)">
                                ⭐ Интересует
                            </button>
                            <button class="action-btn btn-contact" onclick="contactSeller(<?= $lot['id'] ?>)">
                                ✉️ Написать продавцу
                            </button>
                            <button class="action-btn btn-details" onclick="buyReport(<?= $lot['id'] ?>)">
                                📊 Детали (Отчёт)
                            </button>
                        <?php elseif ($is_owner): ?>
                            <a href="edit_commission.php?id=<?= $lot['id'] ?>" class="action-btn btn-view">
                                ✏️ Редактировать
                            </a>
                        <?php endif; ?>
                        <a href="commission_details.php?id=<?= $lot['id'] ?>" class="action-btn btn-view">
                            👁️ Подробнее
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно "Предложить цену" -->
    <div id="offerModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>💰 Предложить свою цену</h3>
                <button class="modal-close" onclick="closeModal('offerModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p id="offerLotTitle" style="font-size:14px;color:#64748b;margin-bottom:16px;"></p>
                <p style="font-size:13px;color:#64748b;margin-bottom:8px;">
                    Начальная цена: <strong id="offerMinPrice">0 ₽</strong>
                </p>
                <input type="number" id="offerPrice" placeholder="Ваша цена (₽)" 
                       style="width:100%;padding:14px;border-radius:10px;border:1.5px solid #e2e8f0;
                              font-size:16px;margin-bottom:16px;">
                <button onclick="submitOffer()" 
                        style="width:100%;padding:14px;background:#f59e0b;color:white;
                               border:none;border-radius:10px;font-weight:600;cursor:pointer;">
                    Отправить предложение
                </button>
            </div>
        </div>
    </div>

    <!-- Модальное окно "Интересует" -->
    <div id="interestModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>⭐ Форма обратной связи</h3>
                <button class="modal-close" onclick="closeModal('interestModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                    Оставьте свои контакты, и мы свяжемся с вами
                </p>
                
                <input type="text" id="interestName" placeholder="Ваше имя" 
                       style="width:100%;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;
                              margin-bottom:12px;">
                
                <label style="display:block;font-size:13px;color:#64748b;margin-bottom:8px;">
                    Предпочитаемый способ связи:
                </label>
                <div style="display:grid;gap:8px;margin-bottom:12px;">
                    <label style="padding:12px;border:1.5px solid #e2e8f0;border-radius:8px;
                                  cursor:pointer;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="contactMethod" value="email" checked>
                        <span>📧 Email</span>
                    </label>
                    <label style="padding:12px;border:1.5px solid #e2e8f0;border-radius:8px;
                                  cursor:pointer;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="contactMethod" value="telegram">
                        <span>✈️ Telegram</span>
                    </label>
                    <label style="padding:12px;border:1.5px solid #e2e8f0;border-radius:8px;
                                  cursor:pointer;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="contactMethod" value="phone">
                        <span>📱 Телефон</span>
                    </label>
                </div>
                
                <input type="text" id="interestContact" placeholder="Email / Telegram / Телефон" 
                       style="width:100%;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;
                              margin-bottom:12px;">
                
                <!-- Заявка на осмотр -->
                <div style="background:#f8fafc;border-radius:12px;padding:16px;margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:12px;">
                        <input type="checkbox" id="wantInspection" onchange="toggleInspectionDate()">
                        <span style="font-weight:600;color:#1e293b;">🔍 Хочу записаться на осмотр</span>
                    </label>
                    
                    <div id="inspectionDateBlock" style="display:none;">
                        <label style="display:block;font-size:13px;color:#64748b;margin-bottom:6px;">
                            Желаемая дата осмотра (минимум через 3 рабочих дня):
                        </label>
                        <input type="date" id="inspectionDate" 
                               style="width:100%;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;">
                        <p style="font-size:11px;color:#64748b;margin-top:6px;">
                            Мы свяжемся с вами для подтверждения времени осмотра
                        </p>
                    </div>
                </div>
                
                <button onclick="submitInterest()" 
                        style="width:100%;padding:14px;background:#8b5cf6;color:white;
                               border:none;border-radius:10px;font-weight:600;cursor:pointer;">
                    Отправить заявку
                </button>
            </div>
        </div>
    </div>

    <!-- Модальное окно "Написать продавцу" -->
    <div id="contactModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✉️ Написать продавцу</h3>
                <button class="modal-close" onclick="closeModal('contactModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="text" id="contactName" placeholder="Ваше имя" 
                       style="width:100%;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;
                              margin-bottom:12px;">
                
                <input type="email" id="contactEmail" placeholder="Ваш email" 
                       style="width:100%;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;
                              margin-bottom:12px;">
                
                <textarea id="contactMessage" placeholder="Ваше сообщение продавцу..." 
                          style="width:100%;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;
                                 min-height:120px;margin-bottom:16px;resize:vertical;"></textarea>
                
                <button onclick="submitContactSeller()" 
                        style="width:100%;padding:14px;background:#3b82f6;color:white;
                               border:none;border-radius:10px;font-weight:600;cursor:pointer;">
                    Отправить сообщение
                </button>
            </div>
        </div>
    </div>

    <!-- Модальное окно "Купить отчёт" -->
    <div id="reportModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📊 Отчёт о состоянии автомобиля</h3>
                <button class="modal-close" onclick="closeModal('reportModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:16px;">
                    <h4 style="margin:0 0 12px;color:#1e293b;">Что входит в отчёт:</h4>
                    <ul style="margin:0;padding-left:20px;color:#64748b;font-size:14px;line-height:1.8;">
                        <li>Проверка по VIN</li>
                        <li>История владения и ДТП</li>
                        <li>Пробег и его проверка</li>
                        <li>Техническое состояние</li>
                        <li>Юридическая чистота</li>
                        <li>Фотофиксация дефектов</li>
                    </ul>
                </div>
                
                <div style="background:linear-gradient(135deg,#10b981,#059669);
                            border-radius:12px;padding:20px;text-align:center;
                            margin-bottom:16px;">
                    <div style="font-size:14px;color:rgba(255,255,255,0.9);margin-bottom:8px;">
                        Стоимость отчёта
                    </div>
                    <div style="font-size:36px;font-weight:800;color:white;">
                        1 390 ₽
                    </div>
                    <div style="font-size:12px;color:rgba(255,255,255,0.8);margin-top:4px;">
                        в том числе НДС 22%
                    </div>
                </div>
                
                <div style="font-size:12px;color:#64748b;margin-bottom:16px;text-align:center;">
                    Отчёт будет отправлен на ваш email в течение 24 часов
                </div>
                
                <button onclick="purchaseReport()" 
                        style="width:100%;padding:16px;background:#10b981;color:white;
                               border:none;border-radius:10px;font-weight:700;font-size:16px;
                               cursor:pointer;margin-bottom:8px;">
                    Купить отчёт за 1 390 ₽
                </button>
                
                <button onclick="closeModal('reportModal')" 
                        style="width:100%;padding:12px;background:#f1f5f9;color:#64748b;
                               border:none;border-radius:10px;font-weight:600;cursor:pointer;">
                    Отмена
                </button>
            </div>
        </div>
    </div>

    <style>
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    
    .modal-overlay.active {
        display: flex;
    }
    
    .modal-content {
        background: white;
        border-radius: 16px;
        max-width: 480px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal-header {
        padding: 20px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 {
        margin: 0;
        font-size: 18px;
        color: #1e293b;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 28px;
        color: #64748b;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: all 0.2s;
    }
    
    .modal-close:hover {
        background: #f1f5f9;
    }
    
    .modal-body {
        padding: 20px;
    }
    </style>

    <script>
    let currentLotId = null;
    let currentMinPrice = 0;

    // Установка минимальной даты осмотра (через 3 рабочих дня)
    function setMinInspectionDate() {
        const today = new Date();
        let workdaysAdded = 0;
        let currentDate = new Date(today);
        
        while (workdaysAdded < 3) {
            currentDate.setDate(currentDate.getDate() + 1);
            const dayOfWeek = currentDate.getDay();
            if (dayOfWeek !== 0 && dayOfWeek !== 6) {
                workdaysAdded++;
            }
        }
        
        const minDate = currentDate.toISOString().split('T')[0];
        document.getElementById('inspectionDate').min = minDate;
    }

    function toggleInspectionDate() {
        const checked = document.getElementById('wantInspection').checked;
        const block = document.getElementById('inspectionDateBlock');
        block.style.display = checked ? 'block' : 'none';
        
        if (checked) {
            setMinInspectionDate();
        }
    }

    function makeOffer(lotId, minPrice) {
        currentLotId = lotId;
        currentMinPrice = minPrice;
        document.getElementById('offerMinPrice').textContent = minPrice.toLocaleString('ru-RU') + ' ₽';
        document.getElementById('offerPrice').min = minPrice;
        document.getElementById('offerPrice').value = '';
        openModal('offerModal');
    }

    function submitOffer() {
        const price = parseFloat(document.getElementById('offerPrice').value);
        
        if (!price || price < currentMinPrice) {
            alert('Цена не может быть ниже начальной (' + currentMinPrice.toLocaleString('ru-RU') + ' ₽)');
            return;
        }
        
        fetch('make_offer.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({lot_id: currentLotId, offered_price: price})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Ваше предложение отправлено продавцу!');
                closeModal('offerModal');
            } else {
                alert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
            }
        })
        .catch(e => alert('❌ Ошибка соединения'));
    }

    function showInterestForm(lotId) {
        currentLotId = lotId;
        openModal('interestModal');
        setMinInspectionDate();
    }

    function submitInterest() {
        const name = document.getElementById('interestName').value.trim();
        const contact = document.getElementById('interestContact').value.trim();
        const method = document.querySelector('input[name="contactMethod"]:checked').value;
        const wantInspection = document.getElementById('wantInspection').checked;
        const inspectionDate = wantInspection ? document.getElementById('inspectionDate').value : null;
        
        if (!name || !contact) {
            alert('Заполните все поля');
            return;
        }
        
        if (wantInspection && !inspectionDate) {
            alert('Выберите дату осмотра');
            return;
        }
        
        fetch('submit_interest.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lot_id: currentLotId,
                name: name,
                contact: contact,
                method: method,
                want_inspection: wantInspection,
                inspection_date: inspectionDate
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Заявка отправлена! Мы свяжемся с вами в ближайшее время.');
                closeModal('interestModal');
                document.getElementById('interestName').value = '';
                document.getElementById('interestContact').value = '';
                document.getElementById('wantInspection').checked = false;
                document.getElementById('inspectionDateBlock').style.display = 'none';
            } else {
                alert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
            }
        })
        .catch(e => alert('❌ Ошибка соединения'));
    }

    function contactSeller(lotId) {
        currentLotId = lotId;
        openModal('contactModal');
    }

    function submitContactSeller() {
        const name = document.getElementById('contactName').value.trim();
        const email = document.getElementById('contactEmail').value.trim();
        const message = document.getElementById('contactMessage').value.trim();
        
        if (!name || !email || !message) {
            alert('Заполните все поля');
            return;
        }
        
        fetch('contact_seller.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lot_id: currentLotId,
                name: name,
                email: email,
                message: message
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Сообщение отправлено продавцу!');
                closeModal('contactModal');
                document.getElementById('contactName').value = '';
                document.getElementById('contactEmail').value = '';
                document.getElementById('contactMessage').value = '';
            } else {
                alert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
            }
        })
        .catch(e => alert('❌ Ошибка соединения'));
    }

    function buyReport(lotId) {
        currentLotId = lotId;
        openModal('reportModal');
    }

    function purchaseReport() {
        if (!confirm('Купить отчёт о состоянии автомобиля за 1 390 ₽?')) {
            return;
        }
        
        fetch('purchase_report.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({lot_id: currentLotId})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.payment_url) {
                    window.location.href = data.payment_url;
                } else {
                    alert('✅ Отчёт оплачен! Мы отправим его на ваш email в течение 24 часов.');
                    closeModal('reportModal');
                }
            } else {
                alert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
            }
        })
        .catch(e => alert('❌ Ошибка соединения'));
    }

    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });
    </script>
</body>
</html>
