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
                    l.timer_add, l.max_end_time, l.start_price, l.bid_step,
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
        $is_over    = $end_ts > 0 && $end_ts <= time();
        $started_ms = !empty($l['started_at']) ? strtotime($l['started_at']) * 1000 : 0;

        // Стоимость ставки для текущего пользователя
        $user_type    = $l['user_type'] ?? 'respected';
        $cash_cost    = getBidCost($user_type, 'cash');
        $balance_cost = getBidCost($user_type, 'balance');
        $pack_prices  = getPackPrice($user_type); // -20%

        echo json_encode([
            'price'        => (int)$l['price'],
            'total_bids'   => (int)$l['total_bids'],
            'end'          => $end_ts * 1000,
            'server_ts'    => time() * 1000,
            'started_ms'   => $started_ms,
            'html'         => $h ?: "<div class='no-bids'>Ставок пока нет</div>",
            'leader'       => ($user_id > 0 && (int)$l['last_bid_user'] === $user_id),
            'is_over'      => $is_over,
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
            'bid_step'       => isset($l['bid_step']) ? (int)$l['bid_step'] : 0,
            'price_increase' => (isset($l['bid_step']) ? (int)$l['bid_step'] : 0) + $cash_cost,
            'max_end_ms'     => !empty($l['max_end_time']) ? strtotime($l['max_end_time']) * 1000 : 0,
            'start_price'    => isset($l['start_price']) && $l['start_price'] > 0 ? (int)$l['start_price'] : (int)$l['price'],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log('lot_scandinavian ajax: ' . $e->getMessage());
        echo json_encode(['error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── HTML ──────────────────────────────────────────────
// Подключаем header.php для получения функции openAuth()
// и единого стиля шапки сайта
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
$is_active  = $end_ts > time();
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
        .timer-box .t-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .timer-box .t-val   { font-size: 28px; font-weight: 900; font-family: monospace; color: #f87171; letter-spacing: 2px; }
        .timer-box .t-val.green { color: #4ade80; }
        .timer-box .t-val.gray  { color: #475569; font-size: 16px; letter-spacing: 0; }

        /* Блок ставки */
        .bid-box {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .bid-box-title { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 14px; }

        /* Способы оплаты */
        .pay-methods { display: flex; flex-direction: column; gap: 7px; margin-bottom: 10px; }
        .pay-option  {
            display: flex; align-items: center; justify-content: space-between;
            background: #0f172a; border: 1.5px solid #334155; border-radius: 10px;
            padding: 10px 14px; cursor: pointer; transition: border-color 0.2s, background 0.2s;
            user-select: none;
        }
        .pay-option:hover   { border-color: #64748b; }
        .pay-option.selected { border-color: #3b82f6; background: #1e3a5f; }
        .pay-option.disabled { opacity: 0.4; cursor: not-allowed; }

        .pay-left  { display: flex; align-items: center; gap: 10px; }
        .pay-icon  { font-size: 18px; }
        .pay-name  { font-size: 13px; font-weight: bold; }
        .pay-desc  { font-size: 11px; color: #64748b; margin-top: 2px; }
        .pay-price { text-align: right; }
        .pay-price .pp-val  { font-size: 16px; font-weight: 900; color: #fff; }
        .pay-price .pp-disc { font-size: 11px; color: #4ade80; font-weight: bold; }
        .pay-price .pp-orig { font-size: 11px; color: #64748b; text-decoration: line-through; }

        /* Баланс */
        .balance-bar {
            display: flex; justify-content: space-between; align-items: center;
            background: #0f172a; border-radius: 8px; padding: 7px 12px;
            margin-bottom: 10px; font-size: 13px;
        }
        .balance-bar .bl    { color: #64748b; }
        .balance-bar .bv    { font-weight: bold; color: #4ade80; }
        .balance-bar .bv.low { color: #f87171; }

        /* Кнопка ставки */
        .btn-bid {
            width: 100%; padding: 15px;
            border: none; border-radius: 12px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            color: #fff; font-size: 16px; font-weight: 900;
            cursor: pointer; letter-spacing: 1px;
            transition: opacity 0.2s, transform 0.1s;
            box-shadow: 0 6px 18px rgba(245,158,11,0.3);
        }
        .btn-bid:hover:not(:disabled) { opacity: 0.9; transform: translateY(-1px); }
        .btn-bid:active:not(:disabled) { transform: translateY(0); }
        .btn-bid:disabled { background: #334155; color: #64748b; cursor: not-allowed; box-shadow: none; transform: none; }

        /* Статус */
        .status-bar {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; margin-top: 10px; padding: 0 4px;
        }
        #leader-status { font-weight: bold; }
        #user-status-badge {
            background: #1e293b; border: 1px solid #334155;
            border-radius: 20px; padding: 3px 10px;
            font-size: 11px; color: #94a3b8;
        }

        /* Сообщение */
        #msg { min-height: 24px; text-align: center; font-size: 13px; font-weight: bold; margin-bottom: 8px; }

        /* История */
        .history { background: #0f172a; border-radius: 14px; overflow: hidden; margin-bottom: 16px; }
        .history-title { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; padding: 10px 14px 6px; }
        .hrow { display: flex; align-items: center; padding: 9px 14px; border-bottom: 1px solid #1e293b; font-size: 13px; gap: 8px; }
        .hrow:last-child { border-bottom: none; }
        .hu   { color: #94a3b8; flex: 1; }
        .hc   { color: #f59e0b; font-size: 12px; white-space: nowrap; }
        .ha   { font-weight: bold; white-space: nowrap; }
        .no-bids { padding: 14px; color: #475569; text-align: center; font-size: 13px; }

        /* Продолжительность */
        .dur-box {
            display: flex; justify-content: space-between; align-items: center;
            background: #0f172a; border-radius: 10px; padding: 10px 14px;
            font-size: 12px; color: #64748b; margin-bottom: 12px;
        }
        .dur-box .dv { font-family: monospace; font-size: 15px; font-weight: bold; color: #94a3b8; }
        .dur-box.active .dv { color: #22c55e; }
        .dur-box.ended  .dv { color: #475569; }

        /* Лог и реестр */
        #download-wrap { text-align: center; margin-bottom: 12px; min-height: 24px; }
        .download-link { color: #475569; font-size: 11px; text-decoration: none; text-transform: uppercase; }
        .download-link:hover { color: #94a3b8; }
        .registry-link {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            padding: 12px; border-radius: 12px; background: #0f172a;
            border: 1px solid #1e293b; color: #64748b; font-size: 13px;
            text-decoration: none; transition: border-color 0.2s, color 0.2s;
        }
        .registry-link:hover { border-color: #334155; color: #fff; }

        /* Модалка QR/квитанция */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.85); z-index: 100;
            justify-content: center; align-items: center;
            backdrop-filter: blur(5px); padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #1e293b; border: 1px solid #334155;
            border-radius: 20px; padding: 28px;
            width: 100%; max-width: 380px; text-align: center;
        }
        .modal-box h3 { margin: 0 0 8px; font-size: 18px; }
        .modal-box p  { color: #94a3b8; font-size: 13px; margin: 0 0 20px; }
        .qr-placeholder {
            width: 180px; height: 180px; background: #fff;
            border-radius: 12px; margin: 0 auto 20px;
            display: flex; align-items: center; justify-content: center;
            color: #0f172a; font-size: 12px; font-weight: bold;
        }
        .modal-close {
            width: 100%; padding: 14px; background: #334155;
            border: none; border-radius: 12px; color: #94a3b8;
            font-size: 15px; cursor: pointer; transition: background 0.2s;
        }
        .modal-close:hover { background: #3d5068; color: #fff; }
    </style>
</head>
<body>
<div class="page">

    <!-- Шапка -->
    <div class="lot-header">
        <div class="lot-badge">Лот №<?= $id ?></div>
        <div class="lot-title"><?= htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="lot-type">🔥 СКАНДИНАВСКИЙ АУКЦИОН</div>
    </div>

    <!-- Цена -->
    <div class="price-box">
        <div class="price-label">Текущая цена лота</div>
        <div class="price-val" id="pr"><?= number_format((float)$lot['price'], 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
        <div class="price-bids">Сделано ставок: <b id="bid-count"><?= (int)$lot['total_bids'] ?></b></div>
        <div style="font-size:12px;color:#f59e0b;margin-top:6px;">Каждая ставка = шаг торгов + ваш тариф</div>
    </div>

    <!-- Таймеры -->
    <div class="timers">
        <div class="timer-box">
            <div class="t-label">До завершения</div>
            <div class="t-val<?= $is_active ? '' : ' gray' ?>" id="tm">
                <?= $is_active ? '--:--' : 'ЗАВЕРШЕНО' ?>
            </div>
        </div>
        <div class="timer-box">
            <div class="t-label">Время торгов</div>
            <div class="t-val green" id="dur-val">
                <?php if ($started_ts): ?>
                    <?php $e = time() - $started_ts; printf('%02d:%02d:%02d', floor($e/3600), floor(($e%3600)/60), $e%60); ?>
                <?php else: ?>--:--:--<?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($max_end_ts > 0): ?>
    <div style="background:#1a0a00;border:1px solid #7c2d12;border-radius:12px;padding:10px 16px;
                margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;font-size:13px;">
        <span style="color:#94a3b8;">⏰ Жёсткий дедлайн</span>
        <span style="color:#f97316;font-weight:bold;font-family:monospace;" id="max-end-val">
            <?php
                $diff = $max_end_ts - time();
                if ($diff > 0) printf('%02d:%02d:%02d', floor($diff/3600), floor(($diff%3600)/60), $diff%60);
                else echo 'ИСТЁК';
            ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Блок ставки -->
    <?php if ($is_active): ?>
    <div class="bid-box">
        <div class="bid-box-title">Сделать ставку</div>

        <!-- Баланс -->
        <?php if ($user_id > 0): ?>
        <div class="balance-bar">
            <span class="bl">Баланс ЛК</span>
            <span class="bv <?= $user_balance < $balance_cost ? 'low' : '' ?>" id="balance-val">
                <?= number_format($user_balance, 0, '.', "\u{00A0}") ?>&nbsp;₽
            </span>
        </div>
        <?php endif; ?>

        <!-- Способы оплаты -->
        <div class="pay-methods" id="pay-methods">

            <!-- Баланс ЛК -->
            <div class="pay-option <?= $user_balance >= $balance_cost ? '' : 'disabled' ?>"
                 id="opt-balance" onclick="selectMethod('balance')" data-method="balance">
                <div class="pay-left">
                    <span class="pay-icon">💳</span>
                    <div>
                        <div class="pay-name">Баланс ЛК</div>
                        <div class="pay-desc">Моментально · <a href="topup.php" style="color:#60a5fa;">пополнить</a></div>
                    </div>
                </div>
                <div class="pay-price">
                    <div class="pp-val"><?= number_format($balance_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
                    <div class="pp-disc">выгоднее</div>
                    <div class="pp-orig"><?= number_format($cash_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
                </div>
            </div>

            <!-- Пакет ставок -->
            <div class="pay-option <?= $pack_remaining > 0 ? '' : 'disabled' ?>"
                 id="opt-pack" onclick="selectMethod('pack')" data-method="pack">
                <div class="pay-left">
                    <span class="pay-icon">📦</span>
                    <div>
                        <div class="pay-name">Пакет ставок</div>
                        <div class="pay-desc">
                            <?php if ($pack_remaining > 0): ?>
                                Осталось: <b style="color:#f59e0b;"><?= $pack_remaining ?></b> шт.
                            <?php else: ?>
                                <a href="topup.php#pack" style="color:#60a5fa;">Купить пакет</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="pay-price">
                    <div class="pp-val"><?= number_format($pack_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
                    <div class="pp-disc">−<?= $user_type === 'responsible' ? '40' : '25' ?>%</div>
                    <div class="pp-orig"><?= number_format($cash_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
                </div>
            </div>

            <!-- QR / Квитанция -->
            <div class="pay-option selected" id="opt-cash" onclick="selectMethod('cash')" data-method="cash">
                <div class="pay-left">
                    <span class="pay-icon">📱🧾</span>
                    <div>
                        <div class="pay-name">QR-код / Квитанция</div>
                        <div class="pay-desc">Оплата после аукциона</div>
                    </div>
                </div>
                <div class="pay-price">
                    <div class="pp-val"><?= number_format($cash_cost, 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
                </div>
            </div>

        </div>

        <!-- Выбор шага -->
        <?php
        $start_price = isset($lot['start_price']) && $lot['start_price'] > 0
            ? (int)$lot['start_price'] : (int)$lot['price'];
        $step_min = (int)round($start_price * 0.005);
        $step_max = (int)round($start_price * 0.05);
        $step_cur = isset($lot['bid_step']) && $lot['bid_step'] > 0
            ? (int)$lot['bid_step'] : (int)round($start_price * 0.02);
        $step_pct = $start_price > 0 ? round($step_cur / $start_price * 100, 1) : 2;
        $bid_locked = false; // Шаг выбирается при каждой ставке
        ?>
        <div id="step-selector" style="background:#0f172a;border-radius:10px;padding:12px 14px;margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:1px;">Шаг торгов</span>
                <span style="font-weight:bold;color:#f59e0b;">
                    <span id="step-pct-show"><?= $step_pct ?>%</span>
                    &nbsp;=&nbsp;<span id="step-rub-show"><?= number_format($step_cur, 0, '.', ' ') ?></span>&thinsp;₽
                </span>
            </div>
            <input type="range" id="step-slider"
                   min="0.5" max="5" step="0.5"
                   value="<?= $step_pct ?>"
                   style="width:100%;accent-color:#f59e0b;"
                   oninput="updateStepDisplay(this.value)">
            <div style="display:flex;justify-content:space-between;font-size:10px;color:#475569;margin-top:3px;">
                <span>0.5%</span><span>5%</span>
            </div>
        </div>

        <div id="msg"></div>

        <button class="btn-bid" id="bid-btn" onclick="makeBid()">
            🔥 СДЕЛАТЬ СТАВКУ
        </button>

        <div class="status-bar">
            <div id="leader-status">
                <?php if (!$user_id): ?>
                    <span style="color:#64748b;">Войдите для участия</span>
                <?php elseif ($is_leader): ?>
                    <span style="color:#4ade80;">● Вы лидируете</span>
                <?php else: ?>
                    <span style="color:#f87171;">○ Ставка перебита</span>
                <?php endif; ?>
            </div>
            <div id="user-status-badge"><?= $type_label[$user_type] ?? '🤝 Уважаемый' ?></div>
        </div>
    </div>
    <?php else: ?>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:20px;padding:20px;text-align:center;margin-bottom:16px;">
        <div style="font-size:32px;margin-bottom:8px;">🏁</div>
        <div style="font-size:18px;font-weight:bold;color:#475569;">Торги завершены</div>
        <?php if ($lot['last_bid_user'] == $user_id && $user_id > 0): ?>
        <div style="color:#4ade80;font-weight:bold;margin-top:8px;">🎉 Вы победили!</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Продолжительность -->
    <div class="dur-box <?= $started_ts > 0 ? ($is_active ? 'active' : 'ended') : '' ?>" id="dur-box">
        <span>Продолжительность</span>
        <span class="dv" id="dur-display">
            <?php if ($started_ts > 0): ?>
                <?php $e = $is_active ? time() - $started_ts : (int)(strtotime($lot['end_time']) - $started_ts);
                      printf('%02d:%02d:%02d', floor($e/3600), floor(($e%3600)/60), $e%60); ?>
            <?php else: ?>--:--:--<?php endif; ?>
        </span>
    </div>

    <!-- История -->
    <div class="history">
        <div class="history-title">Ход торгов</div>
        <div id="hist"></div>
    </div>

    <!-- Лог -->
    <div id="download-wrap">
        <?php if (!$is_active && file_exists("logs/lot_{$id}.txt")): ?>
        <a class="download-link" href="logs/lot_<?= $id ?>.txt" download>↓ Скачать протокол торгов</a>
        <?php endif; ?>
    </div>

    <a class="registry-link" href="reestr.php">← Вернуться в реестр</a>
</div>

<!-- Модалка QR -->
<div class="modal-overlay" id="modal-qr">
    <div class="modal-box">
        <h3>📱 Оплата по QR-коду</h3>
        <p>Отсканируйте код и оплатите ставку. После подтверждения оплаты ставка будет автоматически засчитана.</p>
        <div class="qr-placeholder">QR-код<br>платежа</div>
        <p style="font-size:12px;color:#64748b;margin-bottom:20px;">Сумма: <b id="qr-amount" style="color:#fff;"></b></p>
        <button class="modal-close" onclick="closeModal('modal-qr')">Закрыть</button>
    </div>
</div>

<!-- Модалка квитанция -->
<div class="modal-overlay" id="modal-receipt">
    <div class="modal-box">
        <h3>🧾 Оплата по квитанции</h3>
        <p>Переведите средства по реквизитам и загрузите подтверждение. Ставка будет засчитана после проверки.</p>
        <div style="background:#0f172a;border-radius:10px;padding:14px;text-align:left;margin-bottom:16px;font-size:13px;line-height:2;">
            <div>Получатель: <b>ООО «Форсаж»</b></div>
            <div>ИНН: <b>1234567890</b></div>
            <div>Счёт: <b>40702810000000000000</b></div>
            <div>Назначение: <b>Ставка лот №<?= $id ?></b></div>
            <div>Сумма: <b id="receipt-amount" style="color:#f59e0b;"></b></div>
        </div>
        <button class="modal-close" onclick="closeModal('modal-receipt')">Закрыть</button>
    </div>
</div>

<script>
const LOT_ID        = <?= (int)$id ?>;
const SERVER_OFFSET = <?= time() ?> * 1000 - Date.now();
const IS_AUTH       = <?= $user_id > 0 ? 'true' : 'false' ?>;

let endTime      = <?= $end_ts * 1000 ?>;
let startedMs    = <?= $started_ts ?> * 1000;
const maxEndMs   = <?= $max_end_ts > 0 ? $max_end_ts * 1000 : 0 ?>;
let auctionEnded = <?= $is_active ? 'false' : 'true' ?>;
let tickTimer    = null;
let syncTimer    = null;
let selectedMethod = <?= $pack_remaining > 0 ? "'pack'" : ($user_balance >= $balance_cost ? "'balance'" : "'cash'") ?>;
const PACK_REMAINING_INIT = <?= (int)$pack_remaining ?>;

// Мягкий бан
const SOFT_RESTRICTED  = <?= ($user_id > 0 && isset($lot['soft_bid_limit'])) ? 'true' : 'false' ?>;
const SOFT_BID_LIMIT   = <?= ($user_id > 0 && isset($lot['soft_bid_limit'])) ? (int)$lot['soft_bid_limit'] : 'null' ?>;
const SOFT_BID_WINDOW  = <?= (int)($lot['soft_bid_window'] ?? 44) ?>;
const SOFT_BAN_MSG     = <?= json_encode($lot['soft_ban_msg'] ?? 'Проверьте соединение с интернетом', JSON_UNESCAPED_UNICODE) ?>;
let   softBidsMade     = 0;
let   softDeadTimer    = null;
let   softDead         = false;

/* ── Шаг аукциона ───────────────────────────────────── */
const START_PRICE   = <?= $start_price ?>;
const BID_LOCKED    = <?= $bid_locked ? 'true' : 'false' ?>;
const TARIFF_COST   = <?= (int)$cash_cost ?>;

function updateStepDisplay(pct) {
    const p    = parseFloat(pct);
    const step = START_PRICE > 0 ? Math.round(START_PRICE * p / 100) : 0;
    const el_pct = document.getElementById('step-pct-show');
    const el_rub = document.getElementById('step-rub-show');
    if (el_pct) el_pct.textContent = p + '%';
    if (el_rub) el_rub.textContent = step.toLocaleString('ru-RU');
}

// Инициализация
updateStepDisplay(document.getElementById('step-slider')?.value || <?= $step_pct ?>);

/* ── Выбор способа оплаты ────────────────────────────── */
function selectMethod(method) {
    const opt = document.getElementById('opt-' + method);
    if (opt && opt.classList.contains('disabled')) return;

    selectedMethod = method;
    ['balance', 'pack', 'cash'].forEach(m => {
        const el = document.getElementById('opt-' + m);
        if (el && !el.classList.contains('disabled'))
            el.classList.toggle('selected', m === method);
    });
    showMsg('', '');
}

/* ── Таймер обратного отсчёта ────────────────────────── */
function tick() {
    const serverNow = Date.now() + SERVER_OFFSET;
    const diff      = endTime - serverNow;
    const el        = document.getElementById('tm');

    // Обновляем продолжительность
    if (startedMs && !auctionEnded) {
        const elapsed = serverNow - startedMs;
        document.getElementById('dur-val').textContent    = fmtMs(elapsed);
        document.getElementById('dur-display').textContent = fmtMs(elapsed);
    }

    // Жёсткий дедлайн
    if (maxEndMs > 0) {
        const maxDiff = maxEndMs - serverNow;
        const el = document.getElementById('max-end-val');
        if (el) {
            if (maxDiff <= 0) {
                el.textContent = 'ИСТЁК';
                el.style.color = '#ef4444';
                if (!auctionEnded) {
                    auctionEnded = true;
                    clearInterval(tickTimer);
                    clearInterval(syncTimer);
                    document.getElementById('tm').textContent = 'ЗАВЕРШЕНО';
                    document.getElementById('tm').classList.add('gray');
                    const btn = document.getElementById('bid-btn');
                    if (btn) { btn.disabled = true; btn.textContent = 'ТОРГИ ЗАВЕРШЕНЫ'; }
                }
            } else {
                el.textContent = maxDiff < 3600000 ? fmtMSS(maxDiff) : fmtMs(maxDiff);
                el.style.color = maxDiff < 300000 ? '#ef4444' : '#f97316';
            }
        }
    }

    if (diff <= 0) {
        if (!auctionEnded) {
            auctionEnded = true;
            clearInterval(tickTimer);
            clearInterval(syncTimer);
            el.textContent = 'ЗАВЕРШЕНО';
            el.classList.add('gray');

            // Финальная продолжительность
            if (startedMs) {
                const fin = endTime - startedMs;
                document.getElementById('dur-val').textContent    = fmtMs(fin);
                document.getElementById('dur-display').textContent = fmtMs(fin);
                document.getElementById('dur-box').classList.remove('active');
                document.getElementById('dur-box').classList.add('ended');
            }

            const btn = document.getElementById('bid-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'ТОРГИ ЗАВЕРШЕНЫ'; }
            sync(); // Финальный sync
        }
        return;
    }

    // Формат MM:SS если меньше часа, иначе HH:MM:SS
    el.textContent = diff < 3600000 ? fmtMSS(diff) : fmtMs(diff);

    // Красный мигающий таймер когда < 30 сек
    el.style.color = diff < 30000 ? (Math.floor(Date.now() / 500) % 2 ? '#ff3333' : '#f87171') : '#f87171';
}

function fmtMs(ms) {
    const t = Math.max(0, ms);
    const h = String(Math.floor(t/3600000)).padStart(2,'0');
    const m = String(Math.floor(t%3600000/60000)).padStart(2,'0');
    const s = String(Math.floor(t%60000/1000)).padStart(2,'0');
    return `${h}:${m}:${s}`;
}
function fmtMSS(ms) {
    const t = Math.max(0, ms);
    const m = String(Math.floor(t/60000)).padStart(2,'0');
    const s = String(Math.floor(t%60000/1000)).padStart(2,'0');
    return `${m}:${s}`;
}

/* ── Синхронизация ───────────────────────────────────── */
function sync() {
    fetch(`lot_scandinavian.php?id=${LOT_ID}&ajax=1&t=${Date.now()}`)
        .then(r => r.json())
        .then(d => {
            if (d.error) return;

            document.getElementById('pr').textContent        = d.price.toLocaleString('ru-RU') + '\u00A0₽';
            document.getElementById('bid-count').textContent = d.total_bids;
            if (d.price_increase) {
                const elInc  = document.getElementById('price-inc-val');
                const elStep = document.getElementById('step-val');
                const elTar  = document.getElementById('tariff-val');
                if (elInc)  elInc.textContent  = d.price_increase.toLocaleString('ru-RU');
                if (elStep) elStep.textContent  = d.bid_step.toLocaleString('ru-RU');
                if (elTar)  elTar.textContent   = d.bid_cost_cash.toLocaleString('ru-RU');
            }
            document.getElementById('hist').innerHTML        = d.html;

            if (d.end) endTime = d.end;
            if (d.started_ms && !startedMs) {
                startedMs = d.started_ms;
                document.getElementById('dur-box').classList.add('active');
            }

            // Баланс
            if (d.balance !== null && d.balance !== undefined) {
                const bEl = document.getElementById('balance-val');
                if (bEl) {
                    bEl.textContent = d.balance.toLocaleString('ru-RU') + '\u00A0₽';
                    bEl.className   = 'bv' + (d.balance < d.bid_cost_bal ? ' low' : '');
                }
                const optBal = document.getElementById('opt-balance');
                if (optBal) {
                    if (d.balance >= d.bid_cost_bal) {
                        optBal.classList.remove('disabled');
                    } else {
                        optBal.classList.add('disabled');
                        if (selectedMethod === 'balance') selectMethod('cash');
                    }
                }
            }
            // Пакет ставок
            if (typeof d.pack_remaining !== 'undefined') {
                const optPack = document.getElementById('opt-pack');
                const packDesc = optPack?.querySelector('.pay-desc');
                if (d.pack_remaining > 0) {
                    optPack?.classList.remove('disabled');
                    if (packDesc) packDesc.innerHTML = 'Осталось: <b style="color:#f59e0b;">' + d.pack_remaining + '</b> шт.';
                } else {
                    optPack?.classList.add('disabled');
                    if (selectedMethod === 'pack') selectMethod('cash');
                }
            }

            // Статус лидера
            const ls = document.getElementById('leader-status');
            if (ls && !auctionEnded) {
                ls.innerHTML = d.leader
                    ? "<span style='color:#4ade80;'>● Вы лидируете</span>"
                    : "<span style='color:#f87171;'>○ Ставка перебита</span>";
            }

            // Кнопка
            const btn = document.getElementById('bid-btn');
            if (btn && !auctionEnded) {
                btn.disabled    = d.leader;
                btn.textContent = d.leader ? '🏆 ВЫ ЛИДИРУЕТЕ' : '🔥 СДЕЛАТЬ СТАВКУ';
            }

            // Лог после завершения
            if (d.is_over && d.log_exists) {
                const wrap = document.getElementById('download-wrap');
                if (wrap && !wrap.querySelector('a')) {
                    wrap.innerHTML = `<a class="download-link" href="logs/lot_${LOT_ID}.txt" download>↓ Скачать протокол торгов</a>`;
                }
            }
        })
        .catch(() => {});
}

/* ── Ставка ──────────────────────────────────────────── */
function makeBid() {
    if (!IS_AUTH) {
        showMsg('Войдите в систему для участия', '#f87171');
        if (typeof openAuth === 'function') openAuth('login');
        return;
    }

    const btn = document.getElementById('bid-btn');
    btn.disabled    = true;
    btn.textContent = 'Отправка…';
    showMsg('', '');

    const fd = new FormData();
    fd.append('lot_id',         LOT_ID);
    fd.append('payment_method', selectedMethod);
    const slider = document.getElementById('step-slider');
    if (slider && START_PRICE > 0) {
        const stepRub = Math.round(START_PRICE * parseFloat(slider.value) / 100);
        fd.append('bid_step', stepRub);
    }

    fetch('scandinavian_bid.php', { method: 'POST', body: fd })
        .then(r => {
            if (r.status === 401) {
                showMsg('Сессия истекла — войдите снова', '#f87171');
                if (typeof openAuth === 'function') openAuth('login');
                btn.disabled    = false;
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
            // Экран смерти от сервера
            if (d.dead) {
                showSoftDead(d.msg);
                btn.disabled    = false;
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
                // Безнал — показываем реквизиты
                const amount = d.bid_cost.toLocaleString('ru-RU') + ' ₽';
                document.getElementById('qr-amount').textContent = amount;
                openModal('modal-qr');
                showMsg('⏳ Оплатите ставку', '#f59e0b');
            } else {
                showMsg(d.msg || 'Ошибка', '#f87171');
            }
            btn.disabled    = false;
            btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
        })
        .catch(() => {
            showMsg('Ошибка связи с сервером', '#f87171');
            btn.disabled    = false;
            btn.textContent = '🔥 СДЕЛАТЬ СТАВКУ';
        });
}

function showMsg(text, color) {
    const m = document.getElementById('msg');
    if (!m) return;
    m.textContent = text;
    m.style.color = color;
}

/* ── Модалки ─────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

/* ── Старт ───────────────────────────────────────────── */
if (!auctionEnded) {
    tickTimer = setInterval(tick, 500);
    syncTimer = setInterval(sync, 2000);
    tick();
    sync();
} else {
    sync();
}
</script>
<?php include 'auth_modal.php'; ?>

<!-- Системное окно — мягкий бан (жёлтый треугольник) -->
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

<!-- Системное окно — жёсткий бан (красный кружок) -->
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

<script>
/* ── Мягкий бан — экран смерти ───────────────────────── */
function showSoftDead(msg) {
    if (softDead) return;
    softDead = true;
    if (softDeadTimer) clearTimeout(softDeadTimer);

    // Системное окно — жёлтый треугольник
    const overlay = document.getElementById('sys-warning-overlay');
    const msgEl   = document.getElementById('sys-warn-msg');
    if (msgEl)    msgEl.textContent = msg || SOFT_BAN_MSG;
    if (overlay)  overlay.style.display = 'flex';

    // После закрытия окна — кнопка заблокирована навсегда
    const btn = document.getElementById('bid-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'СДЕЛАТЬ СТАВКУ'; }

    if (syncTimer) clearInterval(syncTimer);
}

function showHardBan() {
    const overlay = document.getElementById('sys-error-overlay');
    if (overlay) overlay.style.display = 'flex';
    const btn = document.getElementById('bid-btn');
    if (btn) { btn.disabled = true; }
}

// Запускаем таймер экрана смерти если soft_restricted
if (SOFT_RESTRICTED && !auctionEnded) {
    if (SOFT_BID_LIMIT === 0) {
        // Лимит 0 — через окно показываем экран
        softDeadTimer = setTimeout(() => showSoftDead(SOFT_BAN_MSG), SOFT_BID_WINDOW * 1000);
    }
}
</script>
</body>
</html>
