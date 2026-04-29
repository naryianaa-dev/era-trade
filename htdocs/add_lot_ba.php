<?php
// add_lot.php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

if (empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

// Только организатор или админ
$stmt = $pdo->prepare("SELECT user_type, username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!in_array($current_user['user_type'], ['organizer', 'admin'])) {
    $_SESSION['error_msg'] = 'Доступ запрещён. Требуется статус организатора.';
    header('Location: index.php'); exit;
}

$msg      = '';
$msgcolor = '#22c55e';

// Добавляем колонку bid_step_cost если её нет (патч)
try {
    $pdo->exec("ALTER TABLE lots ADD COLUMN bid_step_cost INT UNSIGNED DEFAULT 2690 COMMENT 'Стоимость ставки для организатора'");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title              = trim($_POST['title'] ?? '');
    $start_price        = (float)($_POST['start_price'] ?? 0);
    $deposit            = (float)($_POST['deposit'] ?? 0);
    $auction_type       = $_POST['auction_type'] ?? 'classic';
    $description        = trim($_POST['description'] ?? '');
    $bid_step           = (int)($_POST['bid_step'] ?? 1000);
    $timer_start        = (int)($_POST['timer_start'] ?? 240);
    $timer_add          = (int)($_POST['timer_add'] ?? 240);
    $duration           = (int)($_POST['duration'] ?? 24);
    $max_duration       = isset($_POST['max_duration']) && $_POST['max_duration'] > 0
                            ? (int)$_POST['max_duration'] : 0;
    $sealed_bid_deadline= !empty($_POST['sealed_bid_deadline']) ? $_POST['sealed_bid_deadline'] : null;
    $descending_step    = (float)($_POST['descending_step'] ?? 1000);
    $descending_interval= (int)($_POST['descending_interval'] ?? 60);
    $reserve_price      = (float)($_POST['reserve_price'] ?? 0);
    $quotation_deadline = !empty($_POST['quotation_deadline']) ? $_POST['quotation_deadline'] : null;
    $max_quotation_price= (float)($_POST['max_quotation_price'] ?? 0);
    $start_at           = !empty($_POST['start_at']) ? $_POST['start_at'] : null;
    $time_before_start  = (int)($_POST['time_before_start'] ?? 0);

    // Патч: стоимость ставки для скандинавского аукциона
    $bid_step_cost = isset($_POST['bid_step_cost']) && $_POST['bid_step_cost'] > 0
                        ? (int)$_POST['bid_step_cost'] : 2690;

    $errors = [];
    if (empty($title))      $errors[] = 'Укажите название лота.';
    if ($start_price <= 0)  $errors[] = 'Начальная цена должна быть больше 0.';
    if ($auction_type === 'classic' && $bid_step < 100)
        $errors[] = 'Шаг ставки не может быть меньше 100 ₽.';
    if ($auction_type === 'descending' && $reserve_price >= $start_price)
        $errors[] = 'Резервная цена должна быть меньше начальной.';

    if ($auction_type === 'scandinavian') {
        $is_admin = $current_user['user_type'] === 'admin';
        $min_deposit_percent = 10;
        $min_deposit = $start_price * $min_deposit_percent / 100;
        if (!$is_admin) {
            if ($deposit <= 0) {
                $errors[] = 'Укажите залог (минимум 10% от начальной цены).';
            } elseif ($deposit < $min_deposit) {
                $errors[] = 'Залог не может быть меньше ' . number_format($min_deposit, 0, '.', ' ') . ' ₽ (10% от начальной цены).';
            }
        }
        if ($deposit > $start_price) $errors[] = 'Залог не может превышать начальную цену.';
    }

    if (empty($errors)) {
        try {
            $base_time  = $start_at ? strtotime($start_at) : time();
            $end_time   = date('Y-m-d H:i:s', $base_time + $duration * 3600);
            $max_end_time = $max_duration > 0
                            ? date('Y-m-d H:i:s', $base_time + $max_duration * 3600)
                            : null;
            $started_at = $start_at ? date('Y-m-d H:i:s', strtotime($start_at)) : null;

            $extra_params = json_encode([
                'descending_step'     => $descending_step,
                'descending_interval' => $descending_interval,
                'reserve_price'       => $reserve_price,
                'sealed_bid_deadline' => $sealed_bid_deadline,
                'quotation_deadline'  => $quotation_deadline,
                'max_quotation_price' => $max_quotation_price,
            ]);

            // Патч: добавлен bid_step_cost в INSERT
            $sql = "INSERT INTO lots
                (title, start_price, price, deposit, description, end_time,
                 owner_id, auction_type, bid_step, bid_step_cost,
                 timer_start, timer_add, max_end_time, started_at,
                 time_before_start, extra_params, auction_status, trade_status, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'draft', NOW())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $title, $start_price, $start_price, $deposit, $description,
                $end_time, $_SESSION['user_id'], $auction_type,
                $bid_step, $bid_step_cost,
                $timer_start, $timer_add, $max_end_time, $started_at,
                $time_before_start, $extra_params,
            ]);

            $new_id = $pdo->lastInsertId();

            $redirect_url = 'lot_details.php?id=' . $new_id;
            if ($auction_type === 'scandinavian') $redirect_url = 'lot_scandinavian.php?id=' . $new_id;
            elseif ($auction_type === 'closed')   $redirect_url = 'lot_closed.php?id='       . $new_id;
            elseif ($auction_type === 'descending')$redirect_url = 'lot_descending.php?id='  . $new_id;
            elseif ($auction_type === 'quotation') $redirect_url = 'lot_quotation.php?id='   . $new_id;

            header('Location: ' . $redirect_url); exit;

        } catch (Exception $e) {
            $msg      = 'Ошибка создания лота: ' . $e->getMessage();
            $msgcolor = '#ef4444';
        }
    } else {
        $msg      = implode('<br>', $errors);
        $msgcolor = '#ef4444';
    }
}

// Категории для подсказок
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM commission_lots WHERE category IS NOT NULL AND category != '' LIMIT 20");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $categories = [];
}

include 'header.php';
?>
<main style="flex:1; padding:40px 20px;">
<style>
.add-lot-container{max-width:800px;margin:0 auto}
.form-card{background:white;border-radius:24px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);overflow:hidden}
.form-header{background:linear-gradient(135deg,#0f172a,#1e293b);padding:24px 32px;color:white}
.form-header h1{font-size:24px;font-weight:800;margin:0 0 8px}
.form-header p{font-size:14px;color:#94a3b8;margin:0}
.form-body{padding:32px}
.form-section{margin-bottom:32px;border-bottom:1px solid #e2e8f0;padding-bottom:24px}
.form-section:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
.section-title{font-size:18px;font-weight:700;color:#0f172a;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.section-title i{width:24px;height:24px;color:#3b82f6}
.form-group{margin-bottom:20px}
.form-group label{display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px}
.form-group label .required{color:#ef4444;margin-left:4px}
.form-group .hint{font-size:11px;color:#94a3b8;margin-top:4px}
input,select,textarea{width:100%;padding:12px 16px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:14px;font-family:inherit;transition:all 0.2s}
input:focus,select:focus,textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,0.1)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.type-selector{display:flex;gap:12px;flex-wrap:wrap}
.type-option{flex:1;padding:16px;border:2px solid #e2e8f0;border-radius:16px;cursor:pointer;text-align:center;transition:all 0.2s;background:white}
.type-option:hover{border-color:#3b82f6;background:#eff6ff}
.type-option.selected{border-color:#3b82f6;background:#eff6ff}
.type-option .icon{font-size:28px;margin-bottom:8px}
.type-option .name{font-weight:700;color:#0f172a;margin-bottom:4px}
.type-option .desc{font-size:11px;color:#64748b}
.dynamic-params{background:#f8fafc;border-radius:16px;padding:20px;margin-top:20px}
.btn-submit{width:100%;padding:16px;background:linear-gradient(135deg,#3b82f6,#1e40af);border:none;border-radius:14px;color:white;font-size:16px;font-weight:700;cursor:pointer;transition:all 0.2s;margin-top:24px}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(59,130,246,0.4)}
.alert{padding:14px 20px;border-radius:12px;margin-bottom:24px;font-size:14px}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #86efac}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
@media(max-width:640px){
    .form-body{padding:20px}
    .grid-2,.grid-3{grid-template-columns:1fr}
    .type-selector{flex-direction:column}
}
</style>

<div class="add-lot-container">
    <div class="form-card">
        <div class="form-header">
            <h1>➕ Создать лот</h1>
            <p>Заполните параметры торговой процедуры</p>
        </div>
        <div class="form-body">
            <?php if ($msg): ?>
            <div class="alert <?= strpos($msg,'Ошибка') !== false || $msgcolor === '#ef4444' ? 'alert-error' : 'alert-success' ?>">
                <?= $msg ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="lotForm">

                <!-- Основная информация -->
                <div class="form-section">
                    <div class="section-title">
                        <i data-lucide="info"></i> Основная информация
                    </div>
                    <div class="form-group">
                        abel>Название лота <span class="required">*</span></label>
                        <input type="text" name="title" placeholder="Например: Tesla Model S 2023" required>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            abel>Начальная цена (₽) <span class="required">*</span></label>
                            <input type="number" name="start_price" id="start_price" step="1" min="1" required>
                        </div>
                        <div class="form-group">
                            abel>Залог (₽)</label>
                            <input type="number" name="deposit" step="1" min="0" value="0">
                            <div class="hint">Рекомендуется 5–10% от начальной цены</div>
                        </div>
                    </div>
                    <div class="form-group">
                        abel>Описание</label>
                        <textarea name="description" rows="4" placeholder="Характеристики, состояние, особенности..."></textarea>
                    </div>
                </div>

                <!-- Тип аукциона -->
                <div class="form-section">
                    <div class="section-title">
                        <i data-lucide="gavel"></i> Тип аукциона
                    </div>
                    <div class="type-selector" id="typeSelector">
                        <div class="type-option" data-type="classic">
                            <div class="icon">🔨</div>
                            <div class="name">Открытый</div>
                            <div class="desc">Классические торги на повышение</div>
                        </div>
                        <div class="type-option" data-type="scandinavian">
                            <div class="icon">🔥</div>
                            <div class="name">Скандинавский</div>
                            <div class="desc">Каждая ставка платная, побеждает последний</div>
                        </div>
                        <div class="type-option" data-type="closed">
                            <div class="icon">🔒</div>
                            <div class="name">Закрытый</div>
                            <div class="desc">Участники не видят ставки друг друга</div>
                        </div>
                        <div class="type-option" data-type="descending">
                            <div class="icon">📉</div>
                            <div class="name">На понижение</div>
                            <div class="desc">Цена снижается до резервной</div>
                        </div>
                        <div class="type-option" data-type="quotation">
                            <div class="icon">📋</div>
                            <div class="name">Запрос котировок</div>
                            <div class="desc">Сбор ценовых предложений</div>
                        </div>
                    </div>
                    <input type="hidden" name="auction_type" id="auction_type" value="classic">
                </div>

                <!-- Время проведения -->
                <div class="form-section">
                    <div class="section-title">
                        <i data-lucide="clock"></i> Время проведения
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            abel>Дата и время начала</label>
                            <input type="datetime-local" name="start_at">
                            <div class="hint">Если не указано — начинается немедленно</div>
                        </div>
                        <div class="form-group">
                            abel>Продолжительность (часов)</label>
                            <input type="number" name="duration" value="24" min="1" max="720">
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            abel>Задержка перед стартом (мин)</label>
                            <input type="number" name="time_before_start" value="0" min="0" max="60">
                            <div class="hint">N минут после регистрации участника</div>
                        </div>
                        <div class="form-group" id="maxDurationGroup">
                            abel>Жёсткий дедлайн (часов)</label>
                            <input type="number" name="max_duration" placeholder="Не ограничен" min="1" max="720">
                            <div class="hint">Максимальное время вне зависимости от ставок</div>
                        </div>
                    </div>
                </div>

                <!-- Динамические параметры -->
                <div id="dynamicParams"></div>

                <button type="submit" class="btn-submit">🚀 Создать лот</button>
            </form>
        </div>
    </div>
</div>
</main>

<script>
lucide.createIcons();

const typeOptions    = document.querySelectorAll('.type-option');
const auctionTypeInput = document.getElementById('auction_type');
const dynamicParamsDiv = document.getElementById('dynamicParams');
const maxDurationGroup = document.getElementById('maxDurationGroup');

const templates = {
    classic: `
        <div class="form-section">
            <div class="section-title"><i data-lucide="settings"></i> Параметры торгов</div>
            <div class="grid-2">
                <div class="form-group">
                    abel>Шаг ставки (₽)</label>
                    <input type="number" name="bid_step" value="1000" min="100" step="100">
                    <div class="hint">Минимальный шаг повышения цены</div>
                </div>
                <div class="form-group">
                    abel>Таймер начала (сек)</label>
                    <input type="number" name="timer_start" value="240" min="60" step="30">
                    <div class="hint">Время до старта после регистрации</div>
                </div>
                <div class="form-group">
                    abel>Добавление времени (сек)</label>
                    <input type="number" name="timer_add" value="240" min="60" step="30">
                    <div class="hint">Добавляется при каждой ставке</div>
                </div>
            </div>
        </div>`,

    scandinavian: `
        <div class="form-section">
            <div class="section-title"><i data-lucide="flame"></i> Параметры скандинавского аукциона</div>
            <div class="grid-2">
                <div class="form-group">
                    abel>Шаг торгов (₽)</label>
                    <input type="number" name="bid_step" value="1000" min="100" step="100">
                    <div class="hint">На сколько растёт цена при каждой ставке</div>
                </div>
                <div class="form-group">
                    abel>Таймер начала (сек)</label>
                    <input type="number" name="timer_start" value="240" min="60" step="30">
                </div>
                <div class="form-group">
                    abel>Добавление времени (сек)</label>
                    <input type="number" name="timer_add" value="240" min="60" step="30">
                </div>
                <div class="form-group" id="bidStepCostGroup">
                    abel>Стоимость ставки (₽)</label>
                    <input type="number" name="bid_step_cost" id="bid_step_cost"
                           min="1000" max="5000" step="100" value="2690">
                    <small style="color:#64748b;font-size:11px;">
                        Уважаемый: 2 490 ₽, Ответственный: 1 890 ₽ — укажите базовую стоимость
                    </small>
                </div>
            </div>
            <div class="alert alert-success" style="background:#eff6ff;color:#1e40af;margin-top:8px;">
                💡 Каждая ставка платная. Победитель — последний сделавший ставку до истечения таймера.
            </div>
        </div>`,

    closed: `
        <div class="form-section">
            <div class="section-title"><i data-lucide="lock"></i> Параметры закрытого аукциона</div>
            <div class="grid-2">
                <div class="form-group">
                    abel>Дедлайн приёма заявок</label>
                    <input type="datetime-local" name="sealed_bid_deadline">
                    <div class="hint">До этого момента принимаются закрытые ставки</div>
                </div>
                <div class="form-group">
                    abel>Шаг ставки (₽)</label>
                    <input type="number" name="bid_step" value="1000" min="100" step="100">
                </div>
            </div>
            <div class="hint" style="margin-top:8px;">Участники не видят ставки друг друга до завершения</div>
        </div>`,

    descending: `
        <div class="form-section">
            <div class="section-title"><i data-lucide="trending-down"></i> Параметры голландского аукциона</div>
            <div class="grid-2">
                <div class="form-group">
                    abel>Шаг снижения (₽)</label>
                    <input type="number" name="descending_step" value="1000" min="100" step="100">
                </div>
                <div class="form-group">
                    abel>Интервал снижения (сек)</label>
                    <input type="number" name
                    <input type="number" name="descending_interval" value="60" min="10" step="10">
                </div>
                <div class="form-group">
                    abel>Резервная цена (₽)</label>
                    <input type="number" name="reserve_price" value="0" min="0">
                    <div class="hint">Минимальная цена, ниже которой торги останавливаются</div>
                </div>
            </div>
        </div>`,

    quotation: `
        <div class="form-section">
            <div class="section-title"><i data-lucide="file-text"></i> Параметры запроса котировок</div>
            <div class="grid-2">
                <div class="form-group">
                    abel>Дедлайн приёма котировок</label>
                    <input type="datetime-local" name="quotation_deadline">
                </div>
                <div class="form-group">
                    abel>Максимальная цена (₽)</label>
                    <input type="number" name="max_quotation_price" value="0" min="0">
                    <div class="hint">0 — без ограничений</div>
                </div>
                <div class="form-group">
                    abel>Шаг ставки (₽)</label>
                    <input type="number" name="bid_step" value="1000" min="100" step="100">
                </div>
            </div>
        </div>`
};

function updateTypeParams(type) {
    auctionTypeInput.value = type;
    dynamicParamsDiv.innerHTML = templates[type] || '';

    if (type === 'closed' || type === 'quotation') {
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
        updateTypeParams(opt.dataset.type);
    });
});

// По умолчанию выбираем classic
document.querySelector('.type-option[data-type="classic"]').classList.add('selected');
updateTypeParams('classic');

// Авто-подстановка залога
const startPriceInput = document.getElementById('start_price');
const depositInput    = document.querySelector('input[name="deposit"]');

startPriceInput.addEventListener('change', () => {
    const price = parseFloat(startPriceInput.value) || 0;
    const recommended = Math.round(price * 0.05);
    if (depositInput && depositInput.value == 0) {
        depositInput.value = recommended;
        depositInput.placeholder = recommended.toLocaleString('ru-RU');
    }
});
</script>

<?php include 'footer.php'; ?>