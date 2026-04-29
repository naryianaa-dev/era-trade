<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';
include 'bid_config.php';
date_default_timezone_set('Europe/Moscow');

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// ── AJAX ──────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $stmt = $pdo->prepare(
            "SELECT l.price, l.end_time, l.last_bid_user, l.started_at, l.total_bids,
                    l.timer_add, l.max_end_time, l.start_price, l.bid_step, l.status,
                    u.balance, u.user_type, u.bid_pack_remaining
             FROM lots l
             LEFT JOIN users u ON u.id = ?
             WHERE l.id = ?"
        );
        $stmt->execute([$user_id ?: 0, $id]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$l) { echo json_encode(['error' => 'Лот не найден']); exit; }

        // История последних ставок
        $stmt_b = $pdo->prepare(
            "SELECT b.bid_amount, b.bid_cost, b.payment_method, u.username
             FROM bids b
             JOIN users u ON b.user_id = u.id
             WHERE b.lot_id = ?
             ORDER BY b.id DESC LIMIT 8"
        );
        $stmt_b->execute([$id]);
        $bids = $stmt_b->fetchAll(PDO::FETCH_ASSOC);

        $h = '';
        foreach ($bids as $r) {
            $uname  = htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8');
            $masked = mb_substr($uname, 0, 1) . '***' . mb_substr($uname, -1);
            $amt    = number_format((float)$r['bid_amount'], 0, '.', "\u{00A0}");
            $cost   = number_format((float)$r['bid_cost'],   0, '.', "\u{00A0}");
            $icon   = $r['payment_method'] === 'balance' ? '💳' : ($r['payment_method'] === 'pack' ? '📦' : '📱🧾');
            $h .= "<div class='hrow'><span class='hu'>{$masked}</span><span class='hc'>{$icon} -{$cost} ₽</span><span class='ha'>{$amt} ₽</span></div>";
        }

        $end_ts     = $l['end_time'] ? (int)strtotime($l['end_time']) : 0;
        $now        = time();
        $is_over    = $end_ts > 0 && $end_ts <= $now;
        $started_ms = !empty($l['started_at']) ? strtotime($l['started_at']) * 1000 : 0;

        // Стоимость ставки для текущего пользователя
        $user_type    = $l['user_type'] ?? 'respected';
        $cash_cost    = getBidCost($user_type, 'cash');
        $balance_cost = getBidCost($user_type, 'balance');
        $pack_prices  = getPackPrice($user_type);

        echo json_encode([
            'price'        => (int)$l['price'],
            'total_bids'   => (int)$l['total_bids'],
            'end'          => $end_ts * 1000,
            'server_ts'    => $now * 1000,
            'started_ms'   => $started_ms,
            'html'         => $h ?: "<div class='no-bids'>Ставок пока нет</div>",
            'leader'       => ($user_id > 0 && (int)$l['last_bid_user'] === $user_id),
            'is_over'      => $is_over,
            'status'       => $l['status'] ?? 'active',
            'soft_restricted' => $user_id > 0 && isset($l['soft_bid_limit']),
            'soft_bid_limit'  => $user_id > 0 ? (isset($l['soft_bid_limit']) ? (int)$l['soft_bid_limit'] : null) : null,
            'soft_bid_window' => $user_id > 0 ? (int)($l['soft_bid_window'] ?? 44) : 44,
            'soft_ban_msg'    => $user_id > 0 ? ($l['soft_ban_msg'] ?: 'Проверьте соединение с интернетом') : '',
            'max_end_ms'   => !empty($l['max_end_time']) ? strtotime($l['max_end_time']) * 1000 : 0,
            'bid_step'     => isset($l['bid_step']) ? (int)$l['bid_step'] : 0,
            'log_exists'   => $is_over && file_exists("logs/lot_{$id}.txt"),
            'balance'        => $user_id > 0 ? (int)$l['balance'] : null,
            'pack_remaining' => $user_id > 0 ? (int)$l['bid_pack_remaining'] : 0,
            'bid_cost_cash'  => $cash_cost,
            'bid_cost_bal'   => $balance_cost,
            'bid_cost_pack'  => $pack_prices['per_bid'],
            'price_increase' => (isset($l['bid_step']) ? (int)$l['bid_step'] : 0) + $cash_cost,
            'start_price'    => isset($l['start_price']) && $l['start_price'] > 0 ? (int)$l['start_price'] : (int)$l['price'],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log('lot_scandinavian ajax: ' . $e->getMessage());
        echo json_encode(['error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── HTML ──────────────────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT l.*, u.balance, u.user_type, u.bid_pack_remaining
         FROM lots l
         LEFT JOIN users u ON u.id = ?
         WHERE l.id = ?"
    );
    $stmt->execute([$user_id ?: 0, $id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot) { http_response_code(404); die("Лот не найден."); }
} catch (Exception $e) {
    http_response_code(500); die("Ошибка БД.");
}

$end_ts     = (int)strtotime($lot['end_time']);
$now        = time();
$is_active  = $end_ts > $now && ($lot['status'] ?? 'active') === 'active';
$is_over    = $end_ts <= $now || ($lot['status'] ?? 'active') === 'ended';
$is_leader  = $user_id > 0 && (int)$lot['last_bid_user'] === $user_id;
$started_ts = !empty($lot['started_at']) ? (int)strtotime($lot['started_at']) : 0;

$user_type    = $lot['user_type'] ?? 'respected';
$cash_cost    = getBidCost($user_type, 'cash');
$balance_cost = getBidCost($user_type, 'balance');
$pack_prices  = getPackPrice($user_type);
$pack_cost    = $pack_prices['per_bid'];
$user_balance = $user_id > 0 ? (int)$lot['balance'] : 0;
$pack_remaining = $user_id > 0 ? (int)$lot['bid_pack_remaining'] : 0;

$type_label   = ['respected' => '🤝 Уважаемый', 'responsible' => '✅ Ответственный'];

// Определяем отображаемый статус
$status_display = 'Активен';
$status_class = 'active';
if ($is_over) {
    $status_display = 'Завершён';
    $status_class = 'ended';
} elseif (($lot['status'] ?? 'active') === 'pending') {
    $status_display = 'Ожидает';
    $status_class = 'pending';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🔥 <?= htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8') ?> — Скандинавский аукцион</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            background: #0a0f1e;
            color: #fff;
            font-family: sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 20px 16px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .page { width: 100%; max-width: 480px; }

        /* Шапка лота */
        .lot-header { text-align: center; margin-bottom: 20px; }
        .lot-badge  { font-size: 11px; color: #f59e0b; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        .lot-title  { font-size: 20px; font-weight: 900; margin: 6px 0; }
        .lot-type   { display: inline-block; background: #f59e0b22; color: #f59e0b; border: 1px solid #f59e0b55; border-radius: 20px; padding: 3px 12px; font-size: 11px; font-weight: bold; letter-spacing: 1px; }

        /* Статус аукциона */
        .auction-status {
            text-align: center;
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .auction-status.active {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .auction-status.ended {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: #e5e7eb;
        }
        .auction-status.pending {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
        }

        /* Цена */
        .price-box {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            margin-bottom: 16px;
            position: relative;
            overflow: hidden;
        }
        .price-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .price-val   { font-size: 56px; font-weight: 900; line-height: 1; margin: 8px 0; color: #fff; }
        .price-bids  { font-size: 13px; color: #64748b; }
        .price-bids b { color: #f59e0b; }

        /* Таймеры */
        .timers {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .timer-box {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 14px;
            padding: 14px;
            text-align: center;
        }
        .timer-box.active {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-color: #334155;
        }
        .timer-box.ended {
            background: #0f172a;
            border-color: #1e293b;
            opacity: 0.6;
        }
        .timer-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .timer-val   { font-size: 22px; font-weight: 900; color: #fff; }
        .timer-val.gray { color: #64748b; }

        /* История ставок */
        .history-box {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .history-title { font-size: 13px; font-weight: 700; color: #64748b; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px; }
        .hrow {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #1e293b;
        }
        .hrow:last-child { border-bottom: none; }
        .hu { font-size: 13px; font-weight: 600; color: #94a3b8; }
        .hc { font-size: 12px; color: #64748b; }
        .ha { font-size: 14px; font-weight: 700; color: #f59e0b; }
        .no-bids { text-align: center; color: #64748b; font-size: 13px; padding: 12px 0; }

        /* Кнопка ставки */
        .bid-section { margin-bottom: 20px; }
        .btn-bid {
            width: 100%;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            border: none;
            border-radius: 16px;
            padding: 18px;
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.4);
        }
        .btn-bid:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.5);
        }
        .btn-bid:disabled {
            background: #1e293b;
            color: #64748b;
            cursor: not-allowed;
            box-shadow: none;
        }
        .msg { text-align: center; margin: 12px 0; font-size: 14px; font-weight: 600; }

        /* Методы оплаты */
        .payment-methods { margin-bottom: 16px; }
        .pm-title { font-size: 13px; color: #64748b; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
        .pm-grid { display: grid; gap: 10px; }
        .pm-option {
            background: #0f172a;
            border: 2px solid #1e293b;
            border-radius: 12px;
            padding: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .pm-option:hover { border-color: #334155; }
        .pm-option.selected {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-color: #f59e0b;
        }
        .pm-left { display: flex; align-items: center; gap: 10px; }
        .pm-icon { font-size: 20px; }
        .pm-info { text-align: left; }
        .pm-name { font-size: 13px; font-weight: 700; color: #fff; }
        .pm-sub { font-size: 11px; color: #64748b; }
        .pm-cost { font-size: 14px; font-weight: 700; color: #f59e0b; }

        /* Модальные окна */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 20px;
            padding: 24px;
            max-width: 400px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-title { font-size: 18px; font-weight: 900; margin-bottom: 16px; text-align: center; }
        .modal-close {
            background: #1e293b;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
            width: 100%;
            margin-top: 16px;
        }
    </style>
</head>
<body>

<div class="page">
    <!-- Шапка лота -->
    <div class="lot-header">
        <div class="lot-badge">СКАНДИНАВСКИЙ АУКЦИОН</div>
        <div class="lot-title"><?= htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8') ?></div>
        <span class="lot-type"><?= $type_label[$user_type] ?? '🤝 Уважаемый' ?></span>
    </div>

    <!-- Статус аукциона -->
    <div class="auction-status <?= $status_class ?>">
        <?= $status_display ?>
    </div>

    <!-- Цена -->
    <div class="price-box">
        <div class="price-label">Текущая цена</div>
        <div class="price-val" id="price"><?= number_format((int)$lot['price'], 0, '.', ' ') ?></div>
        <div class="price-bids">Ставок: <b id="bids"><?= (int)$lot['total_bids'] ?></b></div>
    </div>

    <!-- Таймеры -->
    <div class="timers">
        <div class="timer-box <?= $is_over ? 'ended' : 'active' ?>" id="tm-box">
            <div class="timer-label">До конца</div>
            <div class="timer-val <?= $is_over ? 'gray' : '' ?>" id="tm">
                <?= $is_over ? 'ЗАВЕРШЕНО' : '00:00:00' ?>
            </div>
        </div>
        <div class="timer-box <?= $is_over ? 'ended' : 'active' ?>" id="dur-box">
            <div class="timer-label">Время торгов</div>
            <div class="timer-val" id="dur-val">00:00:00</div>
        </div>
    </div>

    <!-- История ставок -->
    <div class="history-box">
        <div class="history-title">История ставок</div>
        <div id="history">
            <div class="no-bids">Загрузка...</div>
        </div>
    </div>

    <?php if ($user_id > 0 && $is_active): ?>
    <!-- Методы оплаты -->
    <div class="payment-methods">
        <div class="pm-title">Метод оплаты</div>
        <div class="pm-grid">
            <div class="pm-option selected" data-method="cash" onclick="selectMethod('cash')">
                <div class="pm-left">
                    <div class="pm-icon">📱</div>
                    <div class="pm-info">
                        <div class="pm-name">QR-код</div>
                        <div class="pm-sub">СБП</div>
                    </div>
                </div>
                <div class="pm-cost"><?= $cash_cost ?> ₽</div>
            </div>
            <div class="pm-option" data-method="balance" onclick="selectMethod('balance')">
                <div class="pm-left">
                    <div class="pm-icon">💳</div>
                    <div class="pm-info">
                        <div class="pm-name">Баланс</div>
                        <div class="pm-sub">Доступно: <?= number_format($user_balance, 0, '.', ' ') ?> ₽</div>
                    </div>
                </div>
                <div class="pm-cost"><?= $balance_cost ?> ₽</div>
            </div>
            <?php if ($pack_remaining > 0): ?>
            <div class="pm-option" data-method="pack" onclick="selectMethod('pack')">
                <div class="pm-left">
                    <div class="pm-icon">📦</div>
                    <div class="pm-info">
                        <div class="pm-name">Пакет</div>
                        <div class="pm-sub">Осталось: <?= $pack_remaining ?> ставок</div>
                    </div>
                </div>
                <div class="pm-cost"><?= $pack_cost ?> ₽</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Кнопка ставки -->
    <div class="bid-section">
        <button class="btn-bid" id="bid-btn" onclick="makeBid()">🔥 СДЕЛАТЬ СТАВКУ</button>
        <div class="msg" id="msg"></div>
    </div>
    <?php elseif (!$user_id): ?>
    <div class="bid-section">
        <button class="btn-bid" onclick="if(typeof openAuth === 'function') openAuth('login')">
            🔥 ВОЙТИ ДЛЯ УЧАСТИЯ
        </button>
    </div>
    <?php endif; ?>
</div>

<script>
const LOT_ID = <?= $id ?>;
const USER_ID = <?= $user_id ?: 0 ?>;
const START_PRICE = <?= isset($lot['start_price']) && $lot['start_price'] > 0 ? (int)$lot['start_price'] : (int)$lot['price'] ?>;

let endTime = <?= $end_ts * 1000 ?>;
let startedMs = <?= $started_ts * 1000 ?>;
let auctionEnded = <?= $is_over ? 'true' : 'false' ?>;
let selectedMethod = 'cash';
let tickTimer = null;
let syncTimer = null;

// Мягкий бан
const SOFT_RESTRICTED = <?= !empty($lot['soft_bid_limit']) ? 'true' : 'false' ?>;
const SOFT_BID_LIMIT = <?= !empty($lot['soft_bid_limit']) ? (int)$lot['soft_bid_limit'] : 'null' ?>;
const SOFT_BID_WINDOW = <?= (int)($lot['soft_bid_window'] ?? 44) ?>;
const SOFT_BAN_MSG = <?= json_encode($lot['soft_ban_msg'] ?: 'Проверьте соединение с интернетом', JSON_UNESCAPED_UNICODE) ?>;
let softBidsMade = 0;
let softDead = false;
let softDeadTimer = null;

function selectMethod(m) {
    selectedMethod = m;
    document.querySelectorAll('.pm-option').forEach(el => el.classList.remove('selected'));
    document.querySelector(`[data-method="${m}"]`)?.classList.add('selected');
}

function fmtMs(ms) {
    if (ms <= 0) return '00:00:00';
    const sec = Math.floor(ms / 1000);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return [h, m, s].map(v => String(v).padStart(2, '0')).join(':');
}

function tick() {
    if (auctionEnded) {
        clearInterval(tickTimer);
        return;
    }

    const now = Date.now();
    const left = endTime - now;

    // Обновляем таймер до конца
    const tmEl = document.getElementById('tm');
    const tmBox = document.getElementById('tm-box');
    if (left <= 0) {
        if (tmEl) {
            tmEl.textContent = 'ЗАВЕРШЕНО';
            tmEl.classList.add('gray');
        }
        if (tmBox) tmBox.classList.add('ended');
        auctionEnded = true;
        clearInterval(tickTimer);
        
        // Обновляем статус на странице
        const statusEl = document.querySelector('.auction-status');
        if (statusEl) {
            statusEl.textContent = 'Завершён';
            statusEl.className = 'auction-status ended';
        }
        
        // Блокируем кнопку ставки
        const btn = document.getElementById('bid-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'ТОРГИ ЗАВЕРШЕНЫ';
        }
        
        sync(); // Финальная синхронизация
        return;
    }
    if (tmEl) tmEl.textContent = fmtMs(left);

    // Обновляем продолжительность торгов (только если торги идут)
    if (startedMs > 0) {
        const dur = now - startedMs;
        const elDurVal = document.getElementById('dur-val');
        if (elDurVal) elDurVal.textContent = fmtMs(dur);
    }
}

function sync() {
    fetch(`?id=${LOT_ID}&ajax=1`)
        .then(r => r.json())
        .then(d => {
            if (d.error) return;

            // Обновляем цену и количество ставок
            const priceEl = document.getElementById('price');
            const bidsEl = document.getElementById('bids');
            if (priceEl) priceEl.textContent = d.price.toLocaleString('ru-RU');
            if (bidsEl) bidsEl.textContent = d.total_bids;

            // Обновляем историю
            const histEl = document.getElementById('history');
            if (histEl) histEl.innerHTML = d.html;

            // Проверяем статус завершения
            if (d.is_over && !auctionEnded) {
                auctionEnded = true;
                endTime = d.end;
                
                // Останавливаем таймеры
                clearInterval(tickTimer);
                clearInterval(syncTimer);
                
                // Обновляем UI
                const tmEl = document.getElementById('tm');
                const tmBox = document.getElementById('tm-box');
                const durBox = document.getElementById('dur-box');
                
                if (tmEl) {
                    tmEl.textContent = 'ЗАВЕРШЕНО';
                    tmEl.classList.add('gray');
                }
                if (tmBox) {
                    tmBox.classList.remove('active');
                    tmBox.classList.add('ended');
                }
                if (durBox) {
                    durBox.classList.remove('active');
                    durBox.classList.add('ended');
                }
                
                // Фиксируем финальную продолжительность
                if (startedMs && d.end) {
                    const finalDur = d.end - startedMs;
                    const elDurVal = document.getElementById('dur-val');
                    if (elDurVal) elDurVal.textContent = fmtMs(finalDur);
                }
                
                // Обновляем статус
                const statusEl = document.querySelector('.auction-status');
                if (statusEl) {
                    statusEl.textContent = 'Завершён';
                    statusEl.className = 'auction-status ended';
                }
                
                // Блокируем кнопку
                const btn = document.getElementById('bid-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'ТОРГИ ЗАВЕРШЕНЫ';
                }
            } else if (!d.is_over && auctionEnded) {
                // Торги возобновились (например, после продления)
                auctionEnded = false;
                endTime = d.end;
                
                // Перезапускаем таймеры
                if (!tickTimer) tickTimer = setInterval(tick, 500);
                if (!syncTimer) syncTimer = setInterval(sync, 2000);
                
                const tmEl = document.getElementById('tm');
                const tmBox = document.getElementById('tm-box');
                const durBox = document.getElementById('dur-box');
                
                if (tmEl) tmEl.classList.remove('gray');
                if (tmBox) {
                    tmBox.classList.remove('ended');
                    tmBox.classList.add('active');
                }
                if (durBox) {
                    durBox.classList.remove('ended');
                    durBox.classList.add('active');
                }
                
                const statusEl = document.querySelector('.auction-status');
                if (statusEl) {
                    statusEl.textContent = 'Активен';
                    statusEl.className = 'auction-status active';
                }
                
                const btn = document.getElementById('bid-btn');
                if (btn && !softDead) {
                    btn.disabled = false;
                    btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
                }
            }

            // Обновляем время окончания если изменилось
            if (d.end && d.end !== endTime) {
                endTime = d.end;
            }

            // Обновляем время старта если изменилось
            if (d.started_ms && d.started_ms !== startedMs) {
                startedMs = d.started_ms;
            }
        })
        .catch(err => console.error('Sync error:', err));
}

function makeBid() {
    if (!USER_ID) {
        showMsg('Войдите для участия', '#f87171');
        if (typeof openAuth === 'function') openAuth('login');
        return;
    }

    if (auctionEnded) {
        showMsg('Торги завершены', '#f87171');
        return;
    }

    const btn = document.getElementById('bid-btn');
    btn.disabled = true;
    btn.textContent = 'Отправка…';
    showMsg('', '');

    const fd = new FormData();
    fd.append('lot_id', LOT_ID);
    fd.append('payment_method', selectedMethod);

    fetch('scandinavian_bid.php', { method: 'POST', body: fd })
        .then(r => {
            if (r.status === 401) {
                showMsg('Сессия истекла — войдите снова', '#f87171');
                if (typeof openAuth === 'function') openAuth('login');
                btn.disabled = false;
                btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
                return null;
            }
            if (r.status === 403) {
                showHardBan();
                return null;
            }
            return r.json();
        })
        .then(d => {
            if (!d) return;
            if (d.dead) {
                showSoftDead(d.msg);
                btn.disabled = false;
                btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
                return;
            }
            if (d.success && !d.pending) {
                softBidsMade++;
                if (SOFT_RESTRICTED && SOFT_BID_LIMIT !== null && softBidsMade >= SOFT_BID_LIMIT) {
                    setTimeout(() => showSoftDead(SOFT_BAN_MSG), 800);
                }
                showMsg('✅ Ставка принята! −' + d.bid_cost.toLocaleString('ru-RU') + ' ₽', '#4ade80');
                sync();
            } else if (d.success && d.pending) {
                const amount = d.bid_cost.toLocaleString('ru-RU') + ' ₽';
                showMsg('⏳ Оплатите ставку', '#f59e0b');
            } else {
                showMsg(d.msg || 'Ошибка', '#f87171');
            }
            btn.disabled = false;
            btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
        })
        .catch(() => {
            showMsg('Ошибка связи', '#f87171');
            btn.disabled = false;
            btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
        });
}

function showMsg(text, color) {
    const m = document.getElementById('msg');
    if (!m) return;
    m.textContent = text;
    m.style.color = color;
}

function showSoftDead(msg) {
    if (softDead) return;
    softDead = true;
    if (softDeadTimer) clearTimeout(softDeadTimer);

    const overlay = document.getElementById('sys-warning-overlay');
    const msgEl = document.getElementById('sys-warn-msg');
    if (msgEl) msgEl.textContent = msg || SOFT_BAN_MSG;
    if (overlay) overlay.style.display = 'flex';

    const btn = document.getElementById('bid-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'СДЕЛАТЬ СТАВКУ'; }

    if (syncTimer) clearInterval(syncTimer);
}

function showHardBan() {
    const overlay = document.getElementById('sys-error-overlay');
    if (overlay) overlay.style.display = 'flex';
    const btn = document.getElementById('bid-btn');
    if (btn) btn.disabled = true;
}

// Инициализация
if (!auctionEnded) {
    tickTimer = setInterval(tick, 500);
    syncTimer = setInterval(sync, 2000);
    tick();
    sync();
} else {
    // Фиксируем финальную продолжительность при загрузке
    if (startedMs && endTime) {
        const fin = endTime - startedMs;
        if (fin > 0) {
            const elDurVal = document.getElementById('dur-val');
            if (elDurVal) elDurVal.textContent = fmtMs(fin);
        }
    }
    sync();
}

// Запускаем таймер экрана смерти если soft_restricted
if (SOFT_RESTRICTED && !auctionEnded) {
    if (SOFT_BID_LIMIT === 0) {
        softDeadTimer = setTimeout(() => showSoftDead(SOFT_BAN_MSG), SOFT_BID_WINDOW * 1000);
    }
}
</script>

<?php include 'auth_modal.php'; ?>

<!-- Системное окно — мягкий бан -->
<div id="sys-warning-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);
            z-index:9999;justify-content:center;align-items:center;padding:16px;">
    <div style="background:#fff;color:#1e293b;border-radius:12px;padding:28px 32px;
                width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,0.4);
                font-family:'Segoe UI',Arial,sans-serif;text-align:center;">
        <div style="font-size:52px;margin-bottom:12px;">⚠️</div>
        <div style="font-size:17px;font-weight:600;margin-bottom:8px;color:#1e293b;"
             id="sys-warn-title">Нет подключения к интернету</div>
        <div style="font-size:13px;color:#64748b;margin-bottom:24px;line-height:1.6;"
             id="sys-warn-msg">Проверьте соединение с интернетом</div>
        <button onclick="document.getElementById('sys-warning-overlay').style.display='none'"
                style="background:#e2e8f0;border:none;border-radius:8px;padding:10px 28px;
                       font-size:14px;font-weight:600;cursor:pointer;color:#1e293b;">ОК</button>
    </div>
</div>

<!-- Системное окно — жёсткий бан -->
<div id="sys-error-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);
            z-index:9999;justify-content:center;align-items:center;padding:16px;">
    <div style="background:#fff;color:#1e293b;border-radius:12px;padding:28px 32px;
                width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,0.4);
                font-family:'Segoe UI',Arial,sans-serif;text-align:center;">
        <div style="font-size:52px;margin-bottom:12px;">⛔</div>
        <div style="font-size:17px;font-weight:600;margin-bottom:8px;color:#1e293b;">
            Действие недоступно
        </div>
        <div style="font-size:13px;color:#64748b;margin-bottom:24px;line-height:1.6;">
            Обратитесь в службу поддержки
        </div>
        <button onclick="document.getElementById('sys-error-overlay').style.display='none'"
                style="background:#e2e8f0;border:none;border-radius:8px;padding:10px 28px;
                       font-size:14px;font-weight:600;cursor:pointer;color:#1e293b;">ОК</button>
    </div>
</div>

</body>
</html>