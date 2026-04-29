<!-- upgrade_status_modal.php -->
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    session_start();
    require_once 'db.php';

    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'msg' => 'Неавторизованный пользователь']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'upgrade') {
        $method  = $_POST['method'] ?? 'qr';
        $amount  = max(0, (int)($_POST['amount'] ?? 0));
        $express = max(0, (int)($_POST['express'] ?? 0));
        $total   = $amount + $express;

        // Если таблица status_upgrades не существует, просто возвращаем успех.
        try {
            $stmt = $pdo->prepare("INSERT INTO status_upgrades (user_id, status_to, amount, express_fee, total_amount, payment_method, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], 'responsible', $amount, $express, $total, $method]);
            $upgrade_id = $pdo->lastInsertId();
        } catch (Exception $e) {
            // Игнорируем, если запроса нет, но продолжаем
            $upgrade_id = 0;
        }

        echo json_encode(['success' => true, 'total' => $total, 'id' => $upgrade_id, 'method' => $method]);
        exit;
    }

    echo json_encode(['success' => false, 'msg' => 'Неверное действие']);
    exit;
}

session_start();
// Этот файл подключается в profile.php через include
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$upgrade_prices = [
    'responsible' => 8000,
    'organizer'   => 0, // Бесплатно на 12 месяцев
];

$express_fee = 7000; // Экспресс-обработка за 24 часа
?>

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
    animation: fadeIn 0.2s;
}
.modal-overlay.active {
    display: flex;
}

.upgrade-modal {
    background: #1e293b;
    border-radius: 24px;
    max-width: 560px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    animation: slideUp 0.3s;
}

.modal-header {
    padding: 24px;
    border-bottom: 1px solid #334155;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    font-size: 20px;
    color: #f1f5f9;
}

.modal-close {
    background: none;
    border: none;
    color: #64748b;
    font-size: 28px;
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
    background: #334155;
    color: #f1f5f9;
}

.modal-body {
    padding: 24px;
}

.status-options {
    display: grid;
    gap: 16px;
    margin-bottom: 24px;
}

.status-option {
    border: 2px solid #334155;
    border-radius: 16px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.status-option:hover {
    border-color: #3b82f6;
    background: #0f172a;
}

.status-option.selected {
    border-color: #3b82f6;
    background: #1e3a8a22;
}

.status-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.status-option-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.status-option-title {
    font-size: 18px;
    font-weight: 700;
    color: #f1f5f9;
}

.status-option-price {
    font-size: 20px;
    font-weight: 800;
    color: #3b82f6;
}

.status-option-price.free {
    color: #22c55e;
}

.status-option-desc {
    font-size: 13px;
    color: #94a3b8;
    line-height: 1.5;
}

.status-option-features {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #334155;
}

.feature-item {
    font-size: 12px;
    color: #cbd5e1;
    margin: 6px 0;
    padding-left: 20px;
    position: relative;
}

.feature-item::before {
    content: '✓';
    position: absolute;
    left: 0;
    color: #22c55e;
    font-weight: bold;
}

.express-option {
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 24px;
}

.express-checkbox {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
}

.express-checkbox input {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: #3b82f6;
}

.express-checkbox label {
    font-size: 14px;
    color: #f1f5f9;
    cursor: pointer;
    flex: 1;
}

.express-price {
    font-size: 16px;
    font-weight: 700;
    color: #f59e0b;
}

.total-section {
    background: #0f172a;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.total-row:last-child {
    margin-bottom: 0;
    padding-top: 12px;
    border-top: 1px solid #334155;
}

.total-label {
    font-size: 14px;
    color: #94a3b8;
}

.total-value {
    font-size: 16px;
    font-weight: 700;
    color: #f1f5f9;
}

.total-value.final {
    font-size: 20px;
    color: #3b82f6;
}

.tax-note {
    font-size: 11px;
    color: #64748b;
    margin-top: 8px;
    text-align: center;
}

.payment-buttons {
    display: grid;
    gap: 12px;
}

.payment-btn {
    padding: 16px;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.payment-btn.qr {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff;
}

.payment-btn.qr:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(59, 130, 246, 0.4);
}

.payment-btn.receipt {
    background: #334155;
    color: #f1f5f9;
}

.payment-btn.receipt:hover {
    background: #475569;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<div class="modal-overlay" id="upgradeModal">
    <div class="upgrade-modal">
        <div class="modal-header">
            <h2>⬆️ Повысить статус</h2>
            <button class="modal-close" onclick="closeUpgradeModal()">&times;</button>
        </div>
        
        <div class="modal-body">
            <form id="upgradeForm" method="POST" action="process_upgrade.php">
                <div class="status-options">
                    <label class="status-option" onclick="selectStatus('responsible')">
                        <input type="radio" name="target_status" value="responsible" required>
                        <div class="status-option-header">
                            <div class="status-option-title">✅ Ответственный</div>
                            <div class="status-option-price"><?= number_format($upgrade_prices['responsible'], 0, '.', ' ') ?> ₽</div>
                        </div>
                        <div class="status-option-desc">
                            Расширенные возможности для профессиональной работы на платформе
                        </div>
                        <div class="status-option-features">
                            <div class="feature-item">Пониженная комиссия за ставки</div>
                            <div class="feature-item">Приоритетная поддержка</div>
                            <div class="feature-item">Доступ к расширенной статистике</div>
                            <div class="feature-item">Возможность участия в закрытых торгах</div>
                        </div>
                    </label>
                    
                    <label class="status-option" onclick="selectStatus('organizer')">
                        <input type="radio" name="target_status" value="organizer" required>
                        <div class="status-option-header">
                            <div class="status-option-title">🏆 Организатор</div>
                            <div class="status-option-price free">Бесплатно на 12 мес.</div>
                        </div>
                        <div class="status-option-desc">
                            Полный функционал для организации и проведения торгов
                        </div>
                        <div class="status-option-features">
                            <div class="feature-item">Создание и управление лотами</div>
                            <div class="feature-item">Рассмотрение заявок участников</div>
                            <div class="feature-item">Формирование протоколов</div>
                            <div class="feature-item">Аналитика и отчётность</div>
                            <div class="feature-item">Все возможности статуса "Ответственный"</div>
                        </div>
                    </label>
                </div>
                
                <div class="express-option">
                    <div class="express-checkbox">
                        <input type="checkbox" id="expressCheckbox" name="express" value="1" onchange="updateTotal()">
                        <label for="expressCheckbox">
                            ⚡ Экспресс-обработка за 24 часа
                        </label>
                        <span class="express-price">+<?= number_format($express_fee, 0, '.', ' ') ?> ₽</span>
                    </div>
                </div>
                
                <div class="total-section" id="totalSection">
                    <div class="total-row">
                        <span class="total-label">Статус:</span>
                        <span class="total-value" id="statusName">Не выбран</span>
                    </div>
                    <div class="total-row" id="expressRow" style="display:none;">
                        <span class="total-label">Экспресс-обработка:</span>
                        <span class="total-value"><?= number_format($express_fee, 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div class="total-row">
                        <span class="total-label">Итого:</span>
                        <span class="total-value final" id="totalAmount">0 ₽</span>
                    </div>
                    <div class="tax-note">Все цены указаны с НДС 22%</div>
                </div>
                
                <div class="payment-buttons">
                    <button type="button" class="payment-btn qr" onclick="payByQR()">
                        📱 Оплатить по QR-коду (СБП)
                    </button>
                    <button type="button" class="payment-btn receipt" onclick="generateReceipt()">
                        🧾 Сгенерировать квитанцию
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const prices = {
    responsible: <?= $upgrade_prices['responsible'] ?>,
    organizer: <?= $upgrade_prices['organizer'] ?>,
    express: <?= $express_fee ?>
};

const statusNames = {
    responsible: 'Ответственный',
    organizer: 'Организатор'
};

let selectedStatus = null;

function openUpgradeModal() {
    document.getElementById('upgradeModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeUpgradeModal() {
    document.getElementById('upgradeModal').classList.remove('active');
    document.body.style.overflow = '';
}

function selectStatus(status) {
    selectedStatus = status;
    
    // Обновляем визуальное выделение
    document.querySelectorAll('.status-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    event.currentTarget.classList.add('selected');
    
    // Обновляем радиокнопку
    document.querySelector(`input[value="${status}"]`).checked = true;
    
    updateTotal();
}

function updateTotal() {
    if (!selectedStatus) {
        document.getElementById('statusName').textContent = 'Не выбран';
        document.getElementById('totalAmount').textContent = '0 ₽';
        return;
    }
    
    const basePrice = prices[selectedStatus];
    const expressChecked = document.getElementById('expressCheckbox').checked;
    const expressPrice = expressChecked ? prices.express : 0;
    const total = basePrice + expressPrice;
    
    document.getElementById('statusName').textContent = statusNames[selectedStatus];
    document.getElementById('expressRow').style.display = expressChecked ? 'flex' : 'none';
    document.getElementById('totalAmount').textContent = total.toLocaleString('ru-RU') + ' ₽';
}

function payByQR() {
    if (!selectedStatus) {
        alert('Выберите статус для повышения');
        return;
    }
    
    const form = document.getElementById('upgradeForm');
    const formData = new FormData(form);
    formData.append('payment_method', 'qr');
    
    // Отправляем на сервер для генерации QR
    fetch('process_upgrade.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Открываем страницу с QR-кодом
            window.open(data.qr_page_url, '_blank');
        } else {
            alert(data.error || 'Ошибка создания платежа');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ошибка соединения с сервером');
    });
}

function generateReceipt() {
    if (!selectedStatus) {
        alert('Выберите статус для повышения');
        return;
    }
    
    const form = document.getElementById('upgradeForm');
    const formData = new FormData(form);
    formData.append('payment_method', 'receipt');
    
    // Отправляем на сервер для генерации квитанции
    fetch('process_upgrade.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Открываем квитанцию в новой вкладке
            window.open(data.receipt_url, '_blank');
        } else {
            alert(data.error || 'Ошибка создания квитанции');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ошибка соединения с сервером');
    });
}

// Закрытие по клику вне модального окна
document.getElementById('upgradeModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeUpgradeModal();
    }
});
</script>
