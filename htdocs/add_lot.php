<?php
// add_lot.php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
require_once __DIR__ . '/db_schema_extra.php';
date_default_timezone_set('Europe/Moscow');

// Проверка авторизации
if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Проверка прав (только organizer и admin)
$stmt = $pdo->prepare("SELECT user_type, username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!in_array($current_user['user_type'], ['organizer', 'admin'])) {
    $_SESSION['error_msg'] = 'Доступ запрещен. Только организаторы и администраторы могут создавать лоты.';
    header("Location: index.php");
    exit;
}

$msg = '';
$msg_color = '#22c55e';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $start_price = (float)($_POST['start_price'] ?? 0);
    $deposit = (float)($_POST['deposit'] ?? 0);
    $auction_type = $_POST['auction_type'] ?? 'classic';
    $description = trim($_POST['description'] ?? '');
    
    // Параметры для разных типов аукционов
    $bid_step = (int)($_POST['bid_step'] ?? 1000);
    $timer_start = (int)($_POST['timer_start'] ?? 240);
    $timer_add = (int)($_POST['timer_add'] ?? 240);
    $duration = (int)($_POST['duration'] ?? 24);
    $max_duration = isset($_POST['max_duration']) && $_POST['max_duration'] > 0 ? (int)$_POST['max_duration'] : 0;
    
    // Параметры для закрытого аукциона
    $sealed_bid_deadline = !empty($_POST['sealed_bid_deadline']) ? $_POST['sealed_bid_deadline'] : null;
    
    // Параметры для аукциона на понижение
    $descending_step = (float)($_POST['descending_step'] ?? 1000);
    $descending_interval = (int)($_POST['descending_interval'] ?? 60);
    $reserve_price = (float)($_POST['reserve_price'] ?? 0);
    
    // Параметры для запроса котировок
    $quotation_deadline = !empty($_POST['quotation_deadline']) ? $_POST['quotation_deadline'] : null;
    $max_quotation_price = (float)($_POST['max_quotation_price'] ?? 0);

    // Параметры запроса предложений (продажа товара, побеждает max цена)
    $proposal_deadline = !empty($_POST['proposal_deadline']) ? $_POST['proposal_deadline'] : null;

    // Параметры закрытого аукциона (real-time на повышение, фиксированный таймер)
    $closed_duration_min = (int)($_POST['closed_duration_min'] ?? 0);

    // Временные метки
    $start_at = !empty($_POST['start_at']) ? $_POST['start_at'] : null;
    $time_before_start = (int)($_POST['time_before_start'] ?? 0);

    /* Стоимость отчёта по лоту. NULL = использовать дефолт 1390 ₽.
       Поле может задавать только organizer или admin (форма уже под этой
       проверкой выше, см. user_type guard). */
    $report_price_raw = $_POST['report_price'] ?? '';
    $report_price = ($report_price_raw !== '' && is_numeric($report_price_raw))
        ? max(0, (int)$report_price_raw) : null;
    
    // Валидация
    $errors = [];
    if (empty($title)) $errors[] = 'Введите название лота';
    if ($start_price <= 0) $errors[] = 'Укажите начальную цену (больше 0)';
    if ($auction_type === 'classic' && $bid_step < 100) $errors[] = 'Шаг аукциона должен быть не менее 100 ₽';
    if ($auction_type === 'descending' && $reserve_price >= $start_price) $errors[] = 'Цена отсечения должна быть ниже начальной цены';

    // Закрытый аукцион: продолжительность обязательна.
    if ($auction_type === 'closed' && $closed_duration_min <= 0) {
        $errors[] = 'Укажите продолжительность закрытого аукциона (в минутах)';
    }
    // Запрос котировок / запрос предложений: дедлайн обязателен и должен быть в будущем.
    if ($auction_type === 'quotation') {
        $ts = !empty($quotation_deadline) ? strtotime($quotation_deadline) : 0;
        if ($ts <= 0) $errors[] = 'Укажите дедлайн подачи котировок';
        elseif ($ts <= time()) $errors[] = 'Дедлайн подачи котировок должен быть в будущем';
    }
    if ($auction_type === 'proposal') {
        $ts = !empty($proposal_deadline) ? strtotime($proposal_deadline) : 0;
        if ($ts <= 0) $errors[] = 'Укажите дедлайн подачи предложений';
        elseif ($ts <= time()) $errors[] = 'Дедлайн подачи предложений должен быть в будущем';
    }
    
    // ── ВАЛИДАЦИЯ ЗАДАТКА ДЛЯ СКАНДИНАВСКИХ АУКЦИОНОВ ──
    if ($auction_type === 'scandinavian') {
        $is_admin = ($current_user['user_type'] === 'admin');
        $min_deposit_percent = 10; // Минимум 10%
        $min_deposit = $start_price * $min_deposit_percent / 100;
        
        // Для админа задаток необязателен, для организатора - минимум 10%
        if (!$is_admin) {
            if ($deposit <= 0) {
                $errors[] = 'Для скандинавского аукциона требуется задаток (минимум 10% от начальной цены)';
            } elseif ($deposit < $min_deposit) {
                $errors[] = 'Задаток должен быть не менее ' . number_format($min_deposit, 0, '.', ' ') . ' ₽ (10% от начальной цены)';
            }
        }
        
        // Если задаток указан, проверяем что он не больше начальной цены
        if ($deposit > $start_price) {
            $errors[] = 'Задаток не может превышать начальную цену';
        }
    }
    
    if (empty($errors)) {
        try {
            $base_time = $start_at ? strtotime($start_at) : time();
            $end_time = date("Y-m-d H:i:s", $base_time + ($duration * 3600));
            $max_end_time = $max_duration > 0 ? date("Y-m-d H:i:s", $base_time + ($max_duration * 3600)) : null;
            $started_at = $start_at ? date("Y-m-d H:i:s", strtotime($start_at)) : null;
            
            // Подготовка дополнительных параметров в JSON
            $extra_params = json_encode([
                'descending_step' => $descending_step,
                'descending_interval' => $descending_interval,
                'reserve_price' => $reserve_price,
                'sealed_bid_deadline' => $sealed_bid_deadline,
                'quotation_deadline' => $quotation_deadline,
                'max_quotation_price' => $max_quotation_price,
                'proposal_deadline' => $proposal_deadline,
                'closed_duration_min' => $closed_duration_min
            ]);

            // Для закрытого аукциона: end_time считается по продолжительности,
            // заданной организатором. Таймер фиксированный (без авто-продления).
            if ($auction_type === 'closed' && $closed_duration_min > 0) {
                $end_time = date('Y-m-d H:i:s', $base_time + ($closed_duration_min * 60));
                $max_end_time = $end_time;
            }

            // Для запроса котировок/предложений end_time равен дедлайну.
            if ($auction_type === 'quotation' && !empty($quotation_deadline)) {
                $end_time = date('Y-m-d H:i:s', strtotime($quotation_deadline));
                $max_end_time = $end_time;
            }
            if ($auction_type === 'proposal' && !empty($proposal_deadline)) {
                $end_time = date('Y-m-d H:i:s', strtotime($proposal_deadline));
                $max_end_time = $end_time;
            }
            
            $sql = "INSERT INTO lots (
                title, start_price, price, deposit, description,
                end_time, owner_id, auction_type, bid_step, timer_start, timer_add,
                max_end_time, started_at, time_before_start, extra_params,
                report_price,
                auction_status, trade_status, published_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'active', NOW())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $title,
                $start_price,
                $start_price,
                $deposit,
                $description,
                $end_time,
                $_SESSION['user_id'],
                $auction_type,
                $bid_step,
                $timer_start,
                $timer_add,
                $max_end_time,
                $started_at,
                $time_before_start,
                $extra_params,
                $report_price
            ]);
            
            $new_id = $pdo->lastInsertId();
            
            // Перенаправление в зависимости от типа
            $redirect_url = "lot_details.php?id={$new_id}";
            if ($auction_type === 'scandinavian') {
                $redirect_url = "lot_scandinavian.php?id={$new_id}";
            } elseif ($auction_type === 'closed') {
                $redirect_url = "lot_closed.php?id={$new_id}";
            } elseif ($auction_type === 'descending') {
                $redirect_url = "lot_descending.php?id={$new_id}";
            } elseif ($auction_type === 'quotation') {
                $redirect_url = "lot_quotation.php?id={$new_id}";
            } elseif ($auction_type === 'proposal') {
                $redirect_url = "lot_proposal.php?id={$new_id}";
            }
            
            header("Location: {$redirect_url}");
            exit;
            
        } catch (Exception $e) {
            $msg = 'Ошибка БД: ' . $e->getMessage();
            $msg_color = '#ef4444';
        }
    } else {
        $msg = implode('<br>', $errors);
        $msg_color = '#ef4444';
    }
}

// Получение списка категорий (если есть)
$categories = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM commission_lots WHERE category IS NOT NULL AND category != '' LIMIT 20");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Таблица может не существовать
}

include 'header.php';
?>

<main style="flex:1; padding: 40px 20px;">
    <style>
        .add-lot-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .form-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            padding: 24px 32px;
            color: white;
        }
        
        .form-header h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 8px;
        }
        
        .form-header p {
            font-size: 14px;
            color: #94a3b8;
            margin: 0;
        }
        
        .form-body {
            padding: 32px;
        }
        
        .form-section {
            margin-bottom: 32px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 24px;
        }
        
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title i {
            width: 24px;
            height: 24px;
            color: #3b82f6;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        
        .form-group label .required {
            color: #ef4444;
            margin-left: 4px;
        }
        
        .form-group .hint {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        
        .type-selector {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .type-option {
            flex: 1;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            background: white;
        }
        
        .type-option:hover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        
        .type-option.selected {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        
        .type-option .icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .type-option .name {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        
        .type-option .desc {
            font-size: 11px;
            color: #64748b;
        }
        
        .dynamic-params {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            border: none;
            border-radius: 14px;
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 24px;
        }
        
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59,130,246,0.4);
        }
        
        .alert {
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        @media (max-width: 640px) {
            .form-body {
                padding: 20px;
            }
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
            .type-selector {
                flex-direction: column;
            }
        }
    </style>
    
    <div class="add-lot-container">
        <div class="form-card">
            <div class="form-header">
                <h1>➕ <?= $lang === 'en' ? 'Create a new lot' : 'Создание нового лота' ?></h1>
                <p><?= $lang === 'en' ? 'Fill in lot information to publish it for bidding' : 'Заполните информацию о лоте для участия в торгах' ?></p>
            </div>
            
            <div class="form-body">
                <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_color === '#22c55e' ? 'success' : 'error' ?>">
                    <?= $msg ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="lotForm">
                    <!-- Основная информация -->
                    <div class="form-section">
                        <div class="section-title">
                            <i data-lucide="info"></i>
                            <?= $lang === 'en' ? 'General information' : 'Основная информация' ?>
                        </div>
                        
                        <div class="form-group">
                            <label><?= $lang === 'en' ? 'Lot title' : 'Название лота' ?> <span class="required">*</span></label>
                            <input type="text" name="title" placeholder="<?= $lang === 'en' ? 'For example: Tesla Model S 2023' : 'Например: Автомобиль Tesla Model S 2023' ?>" required>
                        </div>
                        
                        <div class="grid-2">
                            <div class="form-group">
                                <label><?= $lang === 'en' ? 'Starting price (₽)' : 'Начальная цена (₽)' ?> <span class="required">*</span></label>
                                <input type="number" name="start_price" id="start_price" step="1" min="1" required>
                            </div>
                            <div class="form-group">
                                <label><?= $lang === 'en' ? 'Deposit (₽)' : 'Задаток (₽)' ?></label>
                                <input type="number" name="deposit" step="1" min="0" value="0">
                                <div class="hint"><?= $lang === 'en' ? 'Typically 5–10% of the starting price' : 'Обычно 5-10% от начальной цены' ?></div>
                            </div>
                        </div>

                        <!-- Стоимость отчёта по лоту. Видна организатору/админу
                             на форме публикации; пусто = системный дефолт 1390 ₽. -->
                        <div class="form-group">
                            <label><?= $lang === 'en' ? 'Vehicle report price (₽)' : 'Стоимость отчёта по лоту (₽)' ?></label>
                            <input type="number" name="report_price" step="1" min="0" placeholder="1390">
                            <div class="hint"><?= $lang === 'en'
                                ? 'Leave empty to use default 1390 ₽. Charged to buyers who request the inspection report.'
                                : 'Оставьте пустым для значения по умолчанию 1390 ₽. Списывается с покупателей при заказе отчёта.' ?></div>
                        </div>

                        <div class="form-group">
                            <label><?= $lang === 'en' ? 'Lot description' : 'Описание лота' ?></label>
                            <textarea name="description" rows="4" placeholder="<?= $lang === 'en' ? 'Detailed description of the item, specifications, condition...' : 'Подробное описание товара, характеристики, состояние...' ?>"></textarea>
                        </div>
                    </div>
                    
                    <!-- Тип аукциона -->
                    <div class="form-section">
                        <div class="section-title">
                            <i data-lucide="gavel"></i>
                            <?= $lang === 'en' ? 'Auction type' : 'Тип торгов' ?>
                        </div>
                        
                        <div class="type-selector" id="typeSelector">
                            <div class="type-option" data-type="classic">
                                <div class="icon">🔨</div>
                                <div class="name"><?= $lang === 'en' ? 'Open auction' : 'Открытый аукцион' ?></div>
                                <div class="desc"><?= $lang === 'en' ? 'Price rises; the highest bid wins' : 'Цена растёт, побеждает максимальная ставка' ?></div>
                            </div>
                            <div class="type-option" data-type="scandinavian">
                                <div class="icon">🔥</div>
                                <div class="name"><?= $lang === 'en' ? 'Scandinavian' : 'Скандинавский' ?></div>
                                <div class="desc"><?= $lang === 'en' ? 'Paid bids; the last bidder wins' : 'Платные ставки, победитель — последний' ?></div>
                            </div>
                            <div class="type-option" data-type="closed">
                                <div class="icon">🔒</div>
                                <div class="name"><?= $lang === 'en' ? 'Closed auction' : 'Закрытый аукцион' ?></div>
                                <div class="desc"><?= $lang === 'en' ? 'Real-time, only the best bid is visible, manual admission' : 'Real-time, видна только лучшая ставка, ручной допуск' ?></div>
                            </div>
                            <div class="type-option" data-type="descending">
                                <div class="icon">📉</div>
                                <div class="name"><?= $lang === 'en' ? 'Reverse (Dutch) auction' : 'Аукцион на понижение' ?></div>
                                <div class="desc"><?= $lang === 'en' ? 'Price drops until the first bid' : 'Цена снижается до первой ставки' ?></div>
                            </div>
                            <div class="type-option" data-type="quotation">
                                <div class="icon">📋</div>
                                <div class="name"><?= $lang === 'en' ? 'Request for quotations' : 'Запрос котировок' ?></div>
                                <div class="desc"><?= $lang === 'en' ? 'Procurement: the lowest price wins' : 'Закупка: побеждает наименьшая цена' ?></div>
                            </div>
                            <div class="type-option" data-type="proposal">
                                <div class="icon">📨</div>
                                <div class="name"><?= $lang === 'en' ? 'Request for proposals' : 'Запрос предложений' ?></div>
                                <div class="desc"><?= $lang === 'en' ? 'Sale: the highest price wins' : 'Продажа: побеждает наибольшая цена' ?></div>
                            </div>
                        </div>
                        <input type="hidden" name="auction_type" id="auction_type" value="classic">
                    </div>
                    
                    <!-- Временные параметры -->
                    <div class="form-section">
                        <div class="section-title">
                            <i data-lucide="clock"></i>
                            <?= $lang === 'en' ? 'Timing parameters' : 'Временные параметры' ?>
                        </div>
                        
                        <div class="grid-2">
                            <div class="form-group">
                                <label><?= $lang === 'en' ? 'Auction start' : 'Начало торгов' ?></label>
                                <input type="datetime-local" name="start_at">
                                <div class="hint"><?= $lang === 'en' ? 'Leave empty for immediate start' : 'Оставьте пустым для немедленного старта' ?></div>
                            </div>
                            <div class="form-group">
                                <label><?= $lang === 'en' ? 'Duration (hours)' : 'Длительность (часов)' ?></label>
                                <input type="number" name="duration" value="24" min="1" max="720">
                            </div>
                        </div>
                        
                        <div class="grid-2">
                            <div class="form-group">
                                <label><?= $lang === 'en' ? 'Minutes before start' : 'Минут перед началом' ?></label>
                                <input type="number" name="time_before_start" value="0" min="0" max="60">
                                <div class="hint"><?= $lang === 'en' ? 'Shown as “Starts in N minutes”' : 'Отображается как "Начинается через N минут"' ?></div>
                            </div>
                            <div class="form-group" id="maxDurationGroup">
                                <label><?= $lang === 'en' ? 'Max duration (hours)' : 'Макс. продолжительность (часов)' ?></label>
                                <input type="number" name="max_duration" placeholder="<?= $lang === 'en' ? 'Unlimited' : 'Не ограничено' ?>" min="1" max="720">
                                <div class="hint"><?= $lang === 'en' ? 'Hard close after expiration' : 'Жёсткое закрытие после истечения' ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Динамические параметры для разных типов -->
                    <div id="dynamicParams"></div>
                    
                    <button type="submit" class="btn-submit"><?= $lang === 'en' ? 'Publish lot' : 'Опубликовать лот' ?></button>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
lucide.createIcons();

// Переключение типа аукциона
const typeOptions = document.querySelectorAll('.type-option');
const auctionTypeInput = document.getElementById('auction_type');
const dynamicParamsDiv = document.getElementById('dynamicParams');
const maxDurationGroup = document.getElementById('maxDurationGroup');

const templates = {
    classic: `
        <div class="dynamic-params">
            <div class="section-title" style="margin-bottom: 16px;">
                <i data-lucide="settings"></i>
                <?= $lang === 'en' ? 'Open auction parameters' : 'Параметры открытого аукциона' ?>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Bid step (₽)' : 'Шаг аукциона (₽)' ?></label>
                    <input type="number" name="bid_step" value="1000" min="100" step="100">
                    <div class="hint"><?= $lang === 'en' ? 'Minimum bid increment' : 'Минимальное повышение ставки' ?></div>
                </div>
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Bid timer (sec)' : 'Таймер ставки (сек)' ?></label>
                    <input type="number" name="timer_start" value="240" min="60" step="30">
                    <div class="hint"><?= $lang === 'en' ? 'Time to make a decision' : 'Время на принятие решения' ?></div>
                </div>
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Extension on bid (sec)' : 'Продление при ставке (сек)' ?></label>
                    <input type="number" name="timer_add" value="240" min="60" step="30">
                    <div class="hint"><?= $lang === 'en' ? '+ time after each bid' : '+ время после каждой ставки' ?></div>
                </div>
            </div>
        </div>
    `,
    scandinavian: `
        <div class="dynamic-params">
            <div class="section-title" style="margin-bottom: 16px;">
                <i data-lucide="flame"></i>
                <?= $lang === 'en' ? 'Scandinavian auction parameters' : 'Параметры скандинавского аукциона' ?>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Шаг аукциона (₽)</label>
                    <input type="number" name="bid_step" value="1000" min="100" step="100">
                    <div class="hint"><?= $lang === 'en' ? 'Price increase per bid' : 'Увеличение цены при каждой ставке' ?></div>
                </div>
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Initial timer (sec)' : 'Начальный таймер (сек)' ?></label>
                    <input type="number" name="timer_start" value="240" min="60" step="30">
                </div>
                <div class="form-group">
                    <label>Продление при ставке (сек)</label>
                    <input type="number" name="timer_add" value="240" min="60" step="30">
                </div>
            </div>
            <div class="alert alert-success" style="background:#eff6ff; color:#1e40af;">
                💡 <?= $lang === 'en' ? 'Bid cost: step + 2490 ₽ for Respected, +1890 ₽ for Responsible' : 'Стоимость ставки: шаг + 2490 ₽ для уважаемых, +1890 ₽ для ответственных' ?>
            </div>
        </div>
    `,
    closed: `
        <div class="dynamic-params">
            <div class="section-title" style="margin-bottom: 16px;">
                <i data-lucide="lock"></i>
                <?= $lang === 'en' ? 'Closed auction parameters' : 'Параметры закрытого аукциона' ?>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Auction duration (min)' : 'Продолжительность торгов (мин)' ?></label>
                    <input type="number" name="closed_duration_min" value="30" min="1" step="1">
                    <div class="hint"><?= $lang === 'en' ? 'Fixed timer; not extended by bids' : 'Таймер фиксированный, не продлевается при ставках' ?></div>
                </div>
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Minimum step (₽)' : 'Минимальный шаг (₽)' ?></label>
                    <input type="number" name="bid_step" value="1000" min="100" step="100">
                    <div class="hint">Минимальное повышение ставки</div>
                </div>
            </div>
            <div class="hint" style="margin-top: 8px;line-height:1.5;">
                🔒 <?= $lang === 'en' ? 'Real-time auction: bids are placed as in an open auction.' : 'Real-time торги: ставки подаются как в открытом аукционе.' ?><br>
                <?= $lang === 'en' ? 'During the auction only the best price is shown; participant data is hidden.' : 'В процессе видна только лучшая цена; данные участников скрыты.' ?><br>
                <?= $lang === 'en' ? 'Admission is granted manually by the organizer/admin via the admin panel.' : 'Допуск к торгам — вручную организатором/админом через админку.' ?>
            </div>
        </div>
    `,
    descending: `
        <div class="dynamic-params">
            <div class="section-title" style="margin-bottom: 16px;">
                <i data-lucide="trending-down"></i>
                <?= $lang === 'en' ? 'Reverse auction parameters' : 'Параметры аукциона на понижение' ?>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Step-down (₽)' : 'Шаг снижения (₽)' ?></label>
                    <input type="number" name="descending_step" value="1000" min="100" step="100">
                </div>
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Step-down interval (sec)' : 'Интервал снижения (сек)' ?></label>
                    <input type="number" name="descending_interval" value="60" min="10" step="10">
                </div>
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Reserve price (₽)' : 'Цена отсечения (₽)' ?></label>
                    <input type="number" name="reserve_price" value="0" min="0">
                    <div class="hint"><?= $lang === 'en' ? 'Minimum price below which the auction does not proceed' : 'Минимальная цена, ниже которой торги не идут' ?></div>
                </div>
            </div>
        </div>
    `,
    quotation: `
        <div class="dynamic-params">
            <div class="section-title" style="margin-bottom: 16px;">
                <i data-lucide="file-text"></i>
                <?= $lang === 'en' ? 'Request-for-quotations parameters' : 'Параметры запроса котировок' ?>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Submission deadline' : 'Дедлайн подачи предложений' ?></label>
                    <input type="datetime-local" name="quotation_deadline">
                    <div class="hint"><?= $lang === 'en' ? 'Bidders may revise their offer until the deadline' : 'Участники могут менять своё предложение до дедлайна' ?></div>
                </div>
                <div class="form-group">
                    <label><?= $lang === 'en' ? 'Maximum price (₽)' : 'Максимальная цена (₽)' ?></label>
                    <input type="number" name="max_quotation_price" value="0" min="0">
                    <div class="hint"><?= $lang === 'en' ? 'Cap on the maximum price' : 'Ограничение по максимальной цене' ?></div>
                </div>
            </div>
            <div class="hint" style="margin-top: 8px;">
                📋 <?= $lang === 'en' ? 'Procurement: the bidder with the lowest price wins.' : 'Закупка: победитель — участник с наименьшей ценой.' ?>
            </div>
        </div>
    `,
    proposal: `
        <div class="dynamic-params">
            <div class="section-title" style="margin-bottom: 16px;">
                <i data-lucide="mail"></i>
                <?= $lang === 'en' ? 'Request-for-proposals parameters' : 'Параметры запроса предложений' ?>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Дедлайн подачи предложений</label>
                    <input type="datetime-local" name="proposal_deadline">
                    <div class="hint">Участники могут менять своё предложение до дедлайна</div>
                </div>
            </div>
            <div class="hint" style="margin-top: 8px;">
                📨 <?= $lang === 'en' ? 'Sale: the bidder with the highest price wins.' : 'Продажа: победитель — участник с наибольшей ценой за товар.' ?>
            </div>
        </div>
    `
};

function updateTypeParams(type) {
    auctionTypeInput.value = type;
    dynamicParamsDiv.innerHTML = templates[type] || '';
    
    // Показываем/скрываем maxDurationGroup для некоторых типов
    if (type === 'closed' || type === 'quotation' || type === 'proposal') {
        maxDurationGroup.style.display = 'none';
    } else {
        maxDurationGroup.style.display = 'block';
    }
    
    lucide.createIcons();
}

typeOptions.forEach(opt => {
    opt.addEventListener('click', () => {
        typeOptions.forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
        const type = opt.dataset.type;
        updateTypeParams(type);
    });
});

// Выбираем классический по умолчанию
document.querySelector('.type-option[data-type="classic"]').classList.add('selected');
updateTypeParams('classic');

// Обновление подсказки по задатку
const startPriceInput = document.getElementById('start_price');
const depositInput = document.querySelector('input[name="deposit"]');

startPriceInput.addEventListener('change', () => {
    const price = parseFloat(startPriceInput.value) || 0;
    const recommendedDeposit = Math.round(price * 0.05);
    if (depositInput.value === '0' || depositInput.value === '') {
        depositInput.placeholder = `Рекомендуемый задаток: ${recommendedDeposit.toLocaleString('ru-RU')} ₽`;
    }
});
</script>

<?php include 'footer.php'; ?>