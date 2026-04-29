<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

include 'db.php';
include 'bid_config.php';
date_default_timezone_set('Europe/Moscow');

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// AJAX
if (isset($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->prepare("
            SELECT l.price, l.end_time, l.last_bid_user, l.started_at, l.total_bids,
                   l.timer_add, l.max_end_time, l.start_price, l.bid_step,
                   u.balance, u.user_type, u.bid_pack_remaining, u.credit_bids_remaining
            FROM lots l
            LEFT JOIN users u ON u.id = ?
            WHERE l.id = ?
        ");
        $stmt->execute([$user_id ?: 0, $id]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$l) { echo json_encode(['error' => 'Лот не найден']); exit; }

        $stmt_b = $pdo->prepare("
            SELECT b.bid_amount, b.bid_cost, b.payment_method, u.username
            FROM bids b JOIN users u ON b.user_id = u.id
            WHERE b.lot_id = ? ORDER BY b.id DESC LIMIT 8
        ");
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
        $max_end_ts = !empty($l['max_end_time']) ? (int)strtotime($l['max_end_time']) : 0;
        $is_over    = ($end_ts && $end_ts <= time()) || ($max_end_ts && $max_end_ts <= time());
        
        $started_ms = 0;
        if ((int)$l['total_bids'] > 0) {
            $started_ms = !empty($l['started_at']) ? strtotime($l['started_at']) * 1000 : 0;
        } else {
            $start_file = __DIR__ . "/start_time_lot_{$id}.txt";
            if (file_exists($start_file)) {
                $start_time = (int)file_get_contents($start_file);
                $started_ms = $start_time * 1000;
            }
        }

        $user_type    = $l['user_type'] ?? 'respected';
        $cash_cost    = getBidCost($user_type, 'cash');
        $balance_cost = getBidCost($user_type, 'balance');
        $pack_prices  = getPackPrice($user_type);

        echo json_encode([
            'price'        => (int)$l['price'],
            'total_bids'   => (int)$l['total_bids'],
            'end'          => $end_ts * 1000,
            'max_end'      => $max_end_ts * 1000,
            'server_ts'    => time() * 1000,
            'started_ms'   => $started_ms,
            'html'         => $h ?: "<div class='no-bids'>Ставок пока нет</div>",
            'leader'       => ($user_id > 0 && (int)$l['last_bid_user'] === $user_id),
            'is_over'      => $is_over,
            'max_end_ms'   => $max_end_ts * 1000,
            'bid_step'     => isset($l['bid_step']) ? (int)$l['bid_step'] : 0,
            'log_exists'   => $is_over && file_exists("logs/lot_{$id}.txt"),
            'balance'        => $user_id > 0 ? (int)$l['balance'] : null,
            'pack_remaining' => $user_id > 0 ? (int)$l['bid_pack_remaining'] : 0,
            'credit_bids_left' => $user_id > 0 ? (int)$l['credit_bids_remaining'] : 0,
            'bid_cost_cash'  => $cash_cost,
            'bid_cost_bal'   => $balance_cost,
            'bid_cost_pack'  => $pack_prices['per_bid'],
            'price_increase' => (isset($l['bid_step']) ? (int)$l['bid_step'] : 0) + $cash_cost,
            'start_price'    => isset($l['start_price']) && $l['start_price'] > 0 ? (int)$l['start_price'] : (int)$l['price'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Ошибка сервера']);
    }
    exit;
}

// HTML
try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.balance, u.user_type, u.bid_pack_remaining, u.credit_bids_remaining
        FROM lots l LEFT JOIN users u ON u.id = ? WHERE l.id = ?
    ");
    $stmt->execute([$user_id ?: 0, $id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot) { http_response_code(404); die("Лот не найден."); }
} catch (Exception $e) {
    http_response_code(500); die("Ошибка БД.");
}

$now = time();
$end_ts     = (int)strtotime($lot['end_time']);
$max_end_ts = !empty($lot['max_end_time']) ? (int)strtotime($lot['max_end_time']) : 0;
$is_active  = ($end_ts > $now) && (!$max_end_ts || $max_end_ts > $now);
$is_leader  = $user_id > 0 && (int)$lot['last_bid_user'] === $user_id;

$started_ts = 0;
if ((int)$lot['total_bids'] > 0) {
    $started_ts = !empty($lot['started_at']) ? (int)strtotime($lot['started_at']) : 0;
} else {
    $start_file = __DIR__ . "/start_time_lot_{$id}.txt";
    if (file_exists($start_file)) {
        $started_ts = (int)file_get_contents($start_file);
    }
}

$user_type    = $lot['user_type'] ?? 'respected';
$cash_cost    = getBidCost($user_type, 'cash');
$balance_cost = getBidCost($user_type, 'balance');
$pack_prices  = getPackPrice($user_type);
$pack_cost    = $pack_prices['per_bid'];
$pack_10_cost = $pack_prices['pack_10'] ?? 18680;
$user_balance = $user_id > 0 ? (int)$lot['balance'] : 0;
$pack_remaining = $user_id > 0 ? (int)$lot['bid_pack_remaining'] : 0;
$credit_bids_left = $user_id > 0 ? (int)$lot['credit_bids_remaining'] : 0;

$type_label   = ['respected' => '🤝 Уважаемый', 'responsible' => '✅ Ответственный'];

$bid_step_amount = isset($lot['bid_step']) && $lot['bid_step'] > 0 ? (int)$lot['bid_step'] : (int)round((float)$lot['start_price'] * 0.02);
$tariff_prices = ['respected' => 2690, 'responsible' => 1990];
$tariff = $tariff_prices[$user_type] ?? 2690;
$total_price_increase = $bid_step_amount + $tariff;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>🔥 <?= htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8') ?> — Скандинавский аукцион</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { background: #0a0f1e; color: #fff; font-family: sans-serif; margin: 0; padding: 20px 16px 40px; display: flex; flex-direction: column; align-items: center; }
        .page { width: 100%; max-width: 480px; }
        .lot-header { text-align: center; margin-bottom: 20px; }
        .lot-badge { font-size: 11px; color: #f59e0b; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; }
        .lot-title { font-size: 20px; font-weight: 900; margin: 6px 0; }
        .lot-type { display: inline-block; background: #f59e0b22; color: #f59e0b; border: 1px solid #f59e0b55; border-radius: 20px; padding: 3px 12px; font-size: 11px; font-weight: bold; }
        .price-box { background: linear-gradient(135deg, #1e293b, #0f172a); border: 1px solid #334155; border-radius: 20px; padding: 24px; text-align: center; margin-bottom: 16px; }
        .price-label { font-size: 11px; color: #64748b; text-transform: uppercase; }
        .price-val { font-size: 56px; font-weight: 900; margin: 8px 0; }
        .price-bids { font-size: 13px; color: #64748b; }
        .price-bids b { color: #f59e0b; }
        .timers { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        .timer-box { background: #0f172a; border: 1px solid #1e293b; border-radius: 14px; padding: 14px; text-align: center; }
        .timer-box .t-label { font-size: 10px; color: #64748b; text-transform: uppercase; margin-bottom: 6px; }
        .timer-box .t-val { font-size: 28px; font-weight: 900; font-family: monospace; color: #f87171; letter-spacing: 2px; }
        .timer-box .t-val.green { color: #4ade80; }
        .bid-box { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 14px; margin-bottom: 12px; }
        .bid-box-title { font-size: 12px; color: #64748b; text-transform: uppercase; margin-bottom: 14px; }
        .pay-methods { display: flex; flex-direction: column; gap: 7px; margin-bottom: 10px; }
        .pay-option { display: flex; align-items: center; justify-content: space-between; background: #0f172a; border: 1.5px solid #334155; border-radius: 10px; padding: 10px 14px; cursor: pointer; }
        .pay-option.selected { border-color: #3b82f6; background: #1e3a5f; }
        .pay-option.disabled { opacity: 0.4; cursor: not-allowed; }
        .pay-left { display: flex; align-items: center; gap: 10px; }
        .pay-icon { font-size: 18px; }
        .pay-name { font-size: 13px; font-weight: bold; }
        .pay-desc { font-size: 11px; color: #64748b; }
        .pay-price { text-align: right; }
        .pay-price .pp-val { font-size: 16px; font-weight: 900; color: #fff; }
        .balance-bar { display: flex; justify-content: space-between; background: #0f172a; border-radius: 8px; padding: 7px 12px; margin-bottom: 10px; font-size: 13px; }
        .btn-bid { width: 100%; padding: 15px; border: none; border-radius: 12px; background: linear-gradient(135deg, #f59e0b, #ef4444); color: #fff; font-size: 16px; font-weight: 900; cursor: pointer; }
        .btn-bid:disabled { background: #334155; cursor: not-allowed; }
        .status-bar { display: flex; justify-content: space-between; margin-top: 10px; font-size: 12px; }
        .history { background: #0f172a; border-radius: 14px; margin-bottom: 16px; }
        .history-title { font-size: 11px; color: #64748b; padding: 10px 14px 6px; }
        .hrow { display: flex; padding: 9px 14px; border-bottom: 1px solid #1e293b; gap: 8px; font-size: 13px; }
        .hu { color: #94a3b8; flex:1; }
        .hc { color: #f59e0b; }
        .dur-box { display: flex; justify-content: space-between; background: #0f172a; border-radius: 10px; padding: 10px 14px; font-size: 12px; margin-bottom: 12px; }
        .dur-box .dv { font-family: monospace; font-weight: bold; color: #22c55e; }
        .info-cost { background: #0f172a; border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 12px; text-align: center; border-left: 3px solid #f59e0b; }
        .info-cost span { color: #f59e0b; font-weight: bold; }
        .registry-link { display: block; text-align: center; background: #0f172a; border: 1px solid #1e293b; border-radius: 12px; padding: 12px; color: #64748b; text-decoration: none; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 100; justify-content: center; align-items: center; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #1e293b; border-radius: 20px; padding: 28px; width: 100%; max-width: 380px; text-align: center; }
        .qr-placeholder { width: 180px; height: 180px; background: #fff; border-radius: 12px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; color: #0f172a; }
        .download-link { color: #f59e0b; text-decoration: none; font-size: 13px; display: inline-block; margin-top: 8px; }
        #download-wrap { text-align: center; margin-bottom: 12px; }
        .buy-pack-btn { background: #10b981; margin-top: 8px; }
    </style>
</head>
<body>
<div class="page">
    <div class="lot-header">
        <div class="lot-badge">Лот №<?= $id ?></div>
        <div class="lot-title"><?= htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="lot-type">🔥 СКАНДИНАВСКИЙ АУКЦИОН</div>
    </div>

    <div class="price-box">
        <div class="price-label">Текущая цена лота</div>
        <div class="price-val" id="pr"><?= number_format((float)$lot['price'], 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
        <div class="price-bids">Сделано ставок: <b id="bid-count"><?= (int)$lot['total_bids'] ?></b></div>
        <div style="font-size:12px;color:#f59e0b;margin-top:6px;">Каждая ставка = шаг торгов + ваш тариф</div>
    </div>

    <div class="timers">
        <div class="timer-box">
            <div class="t-label">До завершения</div>
            <div class="t-val<?= $is_active ? '' : ' gray' ?>" id="tm"><?= $is_active ? '--:--' : 'ЗАВЕРШЕНО' ?></div>
        </div>
        <div class="timer-box">
            <div class="t-label">Время торгов</div>
            <div class="t-val green" id="dur-val">
                <?php if ($started_ts): ?>
                    <?php $e = time() - $started_ts; printf('%02d:%02d:%02d', floor($e/3600), floor(($e%3600)/60), $e%60); ?>
                <?php else: ?>00:00:00<?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($max_end_ts > 0): ?>
    <div style="background:#1a0a00;border:1px solid #7c2d12;border-radius:12px;padding:10px 16px; margin-bottom:16px; display:flex; justify-content:space-between;">
        <span style="color:#94a3b8;">⏰ Жёсткий дедлайн</span>
        <span style="color:#f97316;font-weight:bold;" id="max-end-val">
            <?php $diff = $max_end_ts - time(); if ($diff > 0) printf('%02d:%02d:%02d', floor($diff/3600), floor(($diff%3600)/60), $diff%60); else echo 'ИСТЁК'; ?>
        </span>
    </div>
    <?php endif; ?>

    <?php if ($is_active): ?>
    <div class="bid-box">
        <div class="bid-box-title">Сделать ставку</div>
        <?php if ($user_id > 0): ?>
        <div class="balance-bar">
            <span class="bl">Баланс ЛК</span>
            <span class="bv" id="balance-val"><?= number_format($user_balance, 0, '.', "\u{00A0}") ?>&nbsp;₽</span>
            <?php if ($credit_bids_left > 0): ?>
            <span class="bl">💳 Кредитных ставок: <span style="color:#f59e0b;"><?= $credit_bids_left ?></span></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="pay-methods" id="pay-methods">
            <!-- Баланс ЛК – во время аукциона disabled -->
            <div class="pay-option <?= $is_active ? 'disabled' : '' ?>" id="opt-balance" onclick="selectMethod('balance')" data-method="balance">
                <div class="pay-left"><span class="pay-icon">💳</span><div><div class="pay-name">Баланс ЛК</div><div class="pay-desc">Моментально</div></div></div>
                <div class="pay-price"><div class="pp-val"><?= number_format($balance_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div><div class="pp-disc">выгоднее</div><div class="pp-orig"><?= number_format($cash_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div></div>
            </div>
            <!-- Пакет ставок – активен всегда -->
            <div class="pay-option" id="opt-pack" onclick="usePack()" data-method="pack">
                <div class="pay-left"><span class="pay-icon">📦</span><div><div class="pay-name">Пакет ставок</div><div class="pay-desc" id="pack-desc"><?php if ($pack_remaining > 0): ?>Осталось: <b style="color:#f59e0b;"><?= $pack_remaining ?></b> шт.<?php else: ?>Нет пакетов<?php endif; ?></div></div></div>
                <div class="pay-price"><div class="pp-val"><?= number_format($pack_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div><div class="pp-disc">−<?= $user_type === 'responsible' ? '40' : '25' ?>%</div><div class="pp-orig"><?= number_format($cash_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div></div>
            </div>
            <!-- QR/Квитанция – недоступна во время аукциона -->
            <div class="pay-option <?= $is_active ? 'disabled' : '' ?>" id="opt-cash" onclick="selectMethod('cash')" data-method="cash">
                <div class="pay-left"><span class="pay-icon">📱🧾</span><div><div class="pay-name">QR-код / Квитанция</div><div class="pay-desc">Оплата после аукциона</div></div></div>
                <div class="pay-price"><div class="pp-val"><?= number_format($cash_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div></div>
            </div>
        </div>
        <button class="btn-bid buy-pack-btn" onclick="window.open('topup.php?pack_only=1&amount=18680', '_blank')">📦 Купить пакет 10 ставок (18 680 ₽)</button>

        <?php if ($is_active): ?>
        <div class="info-cost" style="background:#1e1b2a; border-left-color:#ef4444;">
            ⚠️ Во время торгов пополнение баланса и оплата по QR/квитанции недоступны. Используйте пакет ставок.
        </div>
        <?php endif; ?>

        <?php
        $start_price = isset($lot['start_price']) && $lot['start_price'] > 0 ? (int)$lot['start_price'] : (int)$lot['price'];
        $step_cur = isset($lot['bid_step']) && $lot['bid_step'] > 0 ? (int)$lot['bid_step'] : (int)round($start_price * 0.02);
        $step_pct = $start_price > 0 ? round($step_cur / $start_price * 100, 1) : 2;
        ?>
        <div id="step-selector" style="background:#0f172a;border-radius:10px;padding:12px 14px;margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                <span style="font-size:11px;color:#64748b;">Шаг торгов</span>
                <span style="font-weight:bold;color:#f59e0b;"><span id="step-pct-show"><?= $step_pct ?>%</span> = <span id="step-rub-show"><?= number_format($step_cur, 0, '.', ' ') ?></span> ₽</span>
            </div>
            <input type="range" id="step-slider" min="0.5" max="5" step="0.5" value="<?= $step_pct ?>" style="width:100%;" oninput="updateStepDisplay(this.value)">
        </div>

        <div class="info-cost">
            💡 Стоимость ставки: <span><?= number_format($bid_step_amount, 0, '.', ' ') ?> ₽ (шаг)</span> + 
            <span><?= number_format($tariff, 0, '.', ' ') ?> ₽ (тариф)</span> = 
            <span><?= number_format($total_price_increase, 0, '.', ' ') ?> ₽</span><br>
            <small>Для «Уважаемых» тариф 2690₽, для «Ответственных» — 1990₽. Кредитные ставки (+15% / +10% от шага) в долг.</small>
        </div>

        <div id="msg"></div>
        <button class="btn-bid" id="bid-btn" onclick="makeBid()">🔥 СДЕЛАТЬ СТАВКУ</button>
        <div class="status-bar">
            <div id="leader-status">
                <?php if (!$user_id): ?><span style="color:#64748b;">Войдите для участия</span>
                <?php elseif ($is_leader): ?><span style="color:#4ade80;">● Вы лидируете</span>
                <?php else: ?><span style="color:#f87171;">○ Ставка перебита</span><?php endif; ?>
            </div>
            <div id="user-status-badge"><?= $type_label[$user_type] ?? '🤝 Уважаемый' ?></div>
        </div>
    </div>
    <?php else: ?>
    <div style="background:#1e293b;border-radius:20px;padding:20px;text-align:center;">
        <div style="font-size:32px;">🏁</div>
        <div style="font-size:18px;font-weight:bold;">Торги завершены</div>
        <?php if ($lot['last_bid_user'] == $user_id && $user_id > 0): ?>
        <div style="color:#4ade80;margin-top:8px;">🎉 Вы победили!</div>
        <?php endif; ?>
        <?php if ($lot['owner_id'] == $user_id && $user_id > 0): ?>
            <?php
            $stmt_bonus = $pdo->prepare("SELECT SUM(bid_cost) as total FROM bids WHERE lot_id = ?");
            $stmt_bonus->execute([$id]);
            $total_revenue = (float)$stmt_bonus->fetchColumn();
            $bonus = round($total_revenue * 0.15, 2);
            ?>
            <div style="background:#1e3a5f; border:1px solid #f59e0b; border-radius:12px; padding:12px; margin-top:16px;">
                🎁 Ваше вознаграждение (15% от ставок): <strong style="color:#f59e0b;"><?= number_format($bonus, 2, '.', ' ') ?> ₽</strong><br>
                <small>Зачислено на баланс организатора</small>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="dur-box <?= $started_ts > 0 ? ($is_active ? 'active' : 'ended') : '' ?>" id="dur-box">
        <span>Продолжительность</span>
        <span class="dv" id="dur-display">
            <?php if ($started_ts > 0): ?>
                <?php $e = $is_active ? time() - $started_ts : (int)(strtotime($lot['end_time']) - $started_ts);
                      printf('%02d:%02d:%02d', floor($e/3600), floor(($e%3600)/60), $e%60); ?>
            <?php else: ?>00:00:00<?php endif; ?>
        </span>
    </div>

    <div class="history"><div class="history-title">Ход торгов</div><div id="hist"></div></div>
    <div id="download-wrap">
        <?php if (!$is_active && file_exists("logs/lot_{$id}.txt")): ?>
        <a class="download-link" href="logs/lot_<?= $id ?>.txt" download>↓ Скачать протокол торгов</a>
        <?php endif; ?>
    </div>
    <a class="registry-link" href="reestr.php">← Вернуться в реестр</a>
</div>

<div class="modal-overlay" id="modal-qr"><div class="modal-box"><h3>📱 Оплата по QR-коду</h3><div class="qr-placeholder">QR-код</div><p>Сумма: <b id="qr-amount"></b></p><button class="modal-close" onclick="closeModal('modal-qr')">Закрыть</button></div></div>
<div class="modal-overlay" id="modal-receipt"><div class="modal-box"><h3>🧾 Оплата по квитанции</h3><div>Получатель: ООО «Форсаж»</div><div>Сумма: <b id="receipt-amount"></b></div><button onclick="closeModal('modal-receipt')">Закрыть</button></div></div>

<script>
const LOT_ID = <?= (int)$id ?>;
const SERVER_OFFSET = <?= time() ?> * 1000 - Date.now();
const IS_AUTH = <?= $user_id > 0 ? 'true' : 'false' ?>;
let endTime = <?= $end_ts * 1000 ?>;
let maxEndMs = <?= $max_end_ts * 1000 ?>;
let startedMs = <?= $started_ts ?> * 1000;
let auctionEnded = <?= $is_active ? 'false' : 'true' ?>;
let tickTimer = null, syncTimer = null;
let selectedMethod = 'balance';
const START_PRICE = <?= isset($lot['start_price']) && $lot['start_price'] > 0 ? (int)$lot['start_price'] : (int)$lot['price'] ?>;
let packRemaining = <?= $pack_remaining ?>;

function updateStepDisplay(pct) {
    const step = START_PRICE > 0 ? Math.round(START_PRICE * parseFloat(pct) / 100) : 0;
    document.getElementById('step-pct-show').textContent = pct + '%';
    document.getElementById('step-rub-show').textContent = step.toLocaleString('ru-RU');
}
updateStepDisplay(document.getElementById('step-slider')?.value || <?= round($step_cur / $start_price * 100, 1) ?>);

function selectMethod(method) {
    let opt = document.getElementById('opt-' + method);
    if (opt && opt.classList.contains('disabled')) return;
    selectedMethod = method;
    ['balance','pack','cash'].forEach(m => {
        let el = document.getElementById('opt-' + m);
        if (el && !el.classList.contains('disabled')) el.classList.toggle('selected', m === method);
    });
}

function usePack() {
    if (packRemaining > 0) {
        selectedMethod = 'pack';
        makeBid();
    } else {
        window.open('topup.php?pack_only=1&amount=18680', '_blank');
    }
}

function tick() {
    const serverNow = Date.now() + SERVER_OFFSET;
    let targetEnd = endTime;
    if (maxEndMs > 0 && maxEndMs < targetEnd) targetEnd = maxEndMs;
    const diff = targetEnd - serverNow;
    const el = document.getElementById('tm');

    if (startedMs && !auctionEnded) {
        const elapsed = serverNow - startedMs;
        document.getElementById('dur-val').textContent = fmtMs(elapsed);
        document.getElementById('dur-display').textContent = fmtMs(elapsed);
    } else if (!startedMs && !auctionEnded) {
        document.getElementById('dur-val').textContent = '00:00:00';
        document.getElementById('dur-display').textContent = '00:00:00';
    }

    if (maxEndMs > 0) {
        const maxDiff = maxEndMs - serverNow;
        const maxEl = document.getElementById('max-end-val');
        if (maxEl) {
            if (maxDiff <= 0) {
                maxEl.textContent = 'ИСТЁК';
                if (!auctionEnded) endAuction();
            } else {
                maxEl.textContent = maxDiff < 3600000 ? fmtMSS(maxDiff) : fmtMs(maxDiff);
            }
        }
    }

    if (diff <= 0 && !auctionEnded) endAuction();
    else el.textContent = diff < 3600000 ? fmtMSS(diff) : fmtMs(diff);
}

function endAuction() {
    auctionEnded = true;
    clearInterval(tickTimer); clearInterval(syncTimer);
    document.getElementById('tm').textContent = 'ЗАВЕРШЕНО';
    const btn = document.getElementById('bid-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'ТОРГИ ЗАВЕРШЕНЫ'; }
    sync();
}

function fmtMs(ms) { let t=Math.max(0,ms); return `${String(Math.floor(t/3600000)).padStart(2,'0')}:${String(Math.floor(t%3600000/60000)).padStart(2,'0')}:${String(Math.floor(t%60000/1000)).padStart(2,'0')}`; }
function fmtMSS(ms) { let t=Math.max(0,ms); return `${String(Math.floor(t/60000)).padStart(2,'0')}:${String(Math.floor(t%60000/1000)).padStart(2,'0')}`; }

function sync() {
    fetch(`lot_scandinavian.php?id=${LOT_ID}&ajax=1&t=${Date.now()}`)
        .then(r => r.json())
        .then(d => {
            if (d.error) return;
            document.getElementById('pr').textContent = d.price.toLocaleString('ru-RU') + ' ₽';
            document.getElementById('bid-count').textContent = d.total_bids;
            document.getElementById('hist').innerHTML = d.html;
            if (d.end) endTime = d.end;
            if (d.max_end) maxEndMs = d.max_end;
            if (d.started_ms && !startedMs) {
                startedMs = d.started_ms;
                document.getElementById('dur-box').classList.add('active');
            }
            if (d.balance !== null) {
                let bEl = document.getElementById('balance-val');
                if (bEl) bEl.textContent = d.balance.toLocaleString('ru-RU') + ' ₽';
            }
            if (typeof d.pack_remaining !== 'undefined') {
                packRemaining = d.pack_remaining;
                let packDesc = document.getElementById('pack-desc');
                if (packRemaining > 0) {
                    packDesc.innerHTML = 'Осталось: <b style="color:#f59e0b;">' + packRemaining + '</b> шт.';
                } else {
                    packDesc.innerHTML = 'Нет пакетов';
                }
            }
            let ls = document.getElementById('leader-status');
            if (ls && !auctionEnded) ls.innerHTML = d.leader ? "<span style='color:#4ade80;'>● Вы лидируете</span>" : "<span style='color:#f87171;'>○ Ставка перебита</span>";
            let btn = document.getElementById('bid-btn');
            if (btn && !auctionEnded) { btn.disabled = d.leader; btn.textContent = d.leader ? '🏆 ВЫ ЛИДИРУЕТЕ' : '🔥 СДЕЛАТЬ СТАВКУ'; }
            let wrap = document.getElementById('download-wrap');
            if (wrap) {
                if (d.is_over && d.log_exists) {
                    if (!wrap.querySelector('a')) {
                        wrap.innerHTML = '<a class="download-link" href="logs/lot_'+LOT_ID+'.txt" download>↓ Скачать протокол торгов</a>';
                    }
                } else {
                    wrap.innerHTML = '';
                }
            }
        }).catch(()=>{});
}

function makeBid() {
    if (!IS_AUTH) { alert('Войдите'); return; }
    let btn = document.getElementById('bid-btn');
    btn.disabled = true; btn.textContent = 'Отправка…';
    let fd = new FormData();
    fd.append('lot_id', LOT_ID);
    fd.append('payment_method', selectedMethod);
    let slider = document.getElementById('step-slider');
    if (slider && START_PRICE > 0) fd.append('bid_step', Math.round(START_PRICE * parseFloat(slider.value) / 100));
    fetch('scandinavian_bid.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success && !d.pending) {
                showMsg('✅ ' + d.msg, '#4ade80');
                sync();
            } else if (d.success && d.pending) {
                document.getElementById('qr-amount').textContent = d.bid_cost.toLocaleString('ru-RU') + ' ₽';
                openModal('modal-qr');
                showMsg('⏳ Оплатите ставку', '#f59e0b');
            } else {
                showMsg(d.msg || 'Ошибка', '#f87171');
            }
            btn.disabled = false; btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
        }).catch(()=>{ showMsg('Ошибка связи', '#f87171'); btn.disabled = false; btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ'; });
}

function showMsg(text, color) { let m = document.getElementById('msg'); if(m){ m.textContent=text; m.style.color=color; } }
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

if (!auctionEnded) { tickTimer = setInterval(tick, 500); syncTimer = setInterval(sync, 2000); tick(); sync(); } else sync();
</script>
<?php include 'auth_modal.php'; ?>
</body>
</html>
<?php ob_end_flush();