<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';
date_default_timezone_set('Europe/Moscow');

$id      = 6;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

/* ── AJAX-эндпоинт ───────────────────────────────────── */
if (isset($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');

    try {
        $stmt = $pdo->prepare("SELECT price, end_time, last_bid_user, started_at FROM lots WHERE id = ?");
        $stmt->execute([$id]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$l) {
            echo json_encode(['error' => 'Лот не найден']);
            exit;
        }

        $stmt_b = $pdo->prepare(
            "SELECT b.bid_amount, u.username
             FROM bids b
             JOIN users u ON b.user_id = u.id
             WHERE b.lot_id = ?
             ORDER BY b.id DESC
             LIMIT 5"
        );
        $stmt_b->execute([$id]);
        $bids = $stmt_b->fetchAll(PDO::FETCH_ASSOC);

        $h = '';
        foreach ($bids as $r) {
            $uname  = htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8');
            $masked = mb_substr($uname, 0, 1) . '***' . mb_substr($uname, -1);
            $amt    = number_format((float)$r['bid_amount'], 0, '.', "\u{00A0}");
            $h .= "<div style='display:flex;justify-content:space-between;padding:8px;border-bottom:1px solid #334155;'>"
                . "<span style='color:#94a3b8;'>{$masked}</span>"
                . "<b style='color:#fff;'>{$amt}&nbsp;&#8381;</b>"
                . "</div>";
        }

        $end_ts  = $l['end_time'] ? (int)strtotime($l['end_time']) : 0;
        $is_over = $end_ts > 0 && $end_ts <= time();

        $started_ms = !empty($l['started_at']) ? strtotime($l['started_at']) * 1000 : 0;

        echo json_encode([
            'price'      => (int)$l['price'],
            'end'        => $end_ts * 1000,
            'server_ts'  => time() * 1000,
            'started_ms' => $started_ms,
            'html'       => $h ?: "<div style='padding:10px;color:#64748b;'>Ставок пока нет</div>",
            'leader'     => ($user_id > 0 && (int)$l['last_bid_user'] === $user_id),
            'is_over'    => $is_over,
            'log_exists' => $is_over && file_exists("logs/lot_{$id}.txt"),
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log('lot_details ajax error: ' . $e->getMessage());
        echo json_encode(['error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ── HTML-страница ───────────────────────────────────── */
try {
    $stmt = $pdo->prepare("SELECT price, end_time, last_bid_user, started_at FROM lots WHERE id = ?");
    $stmt->execute([$id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot) { http_response_code(404); die("Лот не найден."); }
} catch (Exception $e) {
    http_response_code(500);
    die("Ошибка БД.");
}

$end_ts    = (int)strtotime($lot['end_time']);
$is_active = $end_ts > time();
$is_leader = $user_id > 0 && (int)$lot['last_bid_user'] === $user_id;
$min_bid    = (int)$lot['price'] + 1000;
$started_ts = !empty($lot['started_at']) ? (int)strtotime($lot['started_at']) : 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forsage LIVE — Лот №<?= $id ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            background: #0f172a;
            color: #fff;
            font-family: sans-serif;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            margin: 0;
            padding: 40px 16px;
        }

        .card {
            background: #1e293b;
            padding: 32px;
            border-radius: 24px;
            border: 1px solid #334155;
            width: 100%;
            max-width: 440px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }

        .lot-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .lot-title { font-size: 20px; font-weight: bold; margin: 6px 0 16px; color: #e2e8f0; }
        .price     { font-size: 56px; font-weight: 900; margin: 0 0 20px; line-height: 1; }

        .info-box {
            background: #0f172a;
            padding: 16px;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .info-meta {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #64748b;
            margin-bottom: 10px;
        }
        .timer-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .timer {
            font-size: 40px;
            font-weight: bold;
            font-family: monospace;
            color: #f87171;
            letter-spacing: 3px;
            line-height: 1.1;
        }
        .timer.ended { color: #475569; font-size: 20px; letter-spacing: 0; }

        .leader-status { font-size: 13px; margin-top: 10px; min-height: 20px; }

        .duration-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #0f172a;
            border-radius: 10px;
            padding: 10px 16px;
            margin-bottom: 16px;
            font-size: 12px;
            color: #64748b;
        }
        .duration-box .dur-val {
            font-family: monospace;
            font-size: 15px;
            font-weight: bold;
            color: #94a3b8;
        }
        .duration-box.active .dur-val { color: #22c55e; }
        .duration-box.ended  .dur-val { color: #475569; }

        .btn {
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 12px;
            font-weight: bold;
            cursor: pointer;
            font-size: 17px;
            transition: background 0.2s;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #2563eb; }
        .btn-primary:disabled { background: #334155; color: #64748b; cursor: not-allowed; }

        .input {
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #fff;
            font-size: 22px;
            text-align: center;
            margin-bottom: 10px;
            outline: none;
            transition: border-color 0.2s;
        }
        .input:focus { border-color: #3b82f6; }

        #msg {
            min-height: 22px;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .history {
            margin-top: 24px;
            text-align: left;
            background: rgba(0,0,0,0.2);
            border-radius: 12px;
            overflow: hidden;
        }
        .history-title {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 12px 6px;
        }

        #download-wrap { min-height: 28px; margin-top: 14px; }
        .download-link {
            display: block;
            color: #475569;
            font-size: 11px;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 0;
        }
        .download-link:hover { color: #94a3b8; }

        .registry-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            padding: 10px 20px;
            border-radius: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #94a3b8;
            font-size: 13px;
            text-decoration: none;
            transition: border-color 0.2s, color 0.2s;
        }
        .registry-link:hover { border-color: #64748b; color: #fff; }
    </style>
</head>
<body>
<div class="card">
    <div class="lot-label">Лот №<?= $id ?></div>
    <div class="lot-title">Tesla Model S (RESTART)</div>

    <div class="price" id="pr"><?= number_format((float)$lot['price'], 0, '.', "\u{00A0}") ?>&nbsp;₽</div>

    <div class="info-box">
        <div class="info-meta">
            <span>Сервер: <span id="server-time"><?= date('H:i:s') ?></span></span>
            <span id="live-badge" style="color:<?= $is_active ? '#22c55e' : '#475569' ?>;">
                <?= $is_active ? '● В ЭФИРЕ' : '● ЗАВЕРШЕНО' ?>
            </span>
        </div>
        <div class="timer-label">До завершения</div>
        <div class="timer<?= $is_active ? '' : ' ended' ?>" id="tm">
            <?= $is_active ? '--:--:--' : 'ЗАВЕРШЕНО' ?>
        </div>
        <div class="leader-status" id="leader-status">
            <?php if ($is_leader): ?>
                <span style="color:#4ade80;">● Ваша ставка лучшая</span>
            <?php elseif ($user_id > 0): ?>
                <span style="color:#f87171;">○ Ваша ставка перебита</span>
            <?php else: ?>
                <span style="color:#64748b;">○ Войдите, чтобы участвовать</span>
            <?php endif; ?>
        </div>
    </div>

    <div id="msg"></div>

    <input type="number" id="in" class="input"
           min="<?= $min_bid ?>" step="1000" value="<?= $min_bid ?>"
           <?= (!$is_active || $is_leader) ? 'disabled' : '' ?>>

    <div id="bid-hint" style="font-size:12px;color:#64748b;margin:-4px 0 10px;text-align:center;">
        Мин. ставка: <span id="hint-min"><?= number_format($min_bid, 0, '.', "\u{00A0}") ?></span>&nbsp;₽
        &nbsp;·&nbsp; Шаг: <b style="color:#94a3b8;">1 000 ₽</b>
    </div>

    <button id="bt" class="btn btn-primary"
        <?= (!$is_active || $is_leader) ? 'disabled' : '' ?>>
        <?php
            if (!$is_active)    echo 'ТОРГИ ЗАВЕРШЕНЫ';
            elseif ($is_leader) echo 'ВЫ ЛИДИРУЕТЕ';
            else                echo 'СДЕЛАТЬ СТАВКУ';
        ?>
    </button>

    <!-- Счётчик продолжительности торгов -->
    <div class="duration-box<?= $started_ts > 0 ? ($is_active ? ' active' : ' ended') : '' ?>" id="dur-box">
        <span>Продолжительность торгов</span>
        <span class="dur-val" id="dur-val">
            <?php if ($started_ts > 0): ?>
                <?php
                    $elapsed = time() - $started_ts;
                    $dh = str_pad(floor($elapsed / 3600), 2, '0', STR_PAD_LEFT);
                    $dm = str_pad(floor(($elapsed % 3600) / 60), 2, '0', STR_PAD_LEFT);
                    $ds = str_pad($elapsed % 60, 2, '0', STR_PAD_LEFT);
                    echo "$dh:$dm:$ds";
                ?>
            <?php else: ?>
                --:--:--
            <?php endif; ?>
        </span>
    </div>

    <div class="history">
        <div class="history-title">Последние ставки</div>
        <div id="hist"><div style="padding:10px;color:#64748b;text-align:center;font-size:13px;">Загрузка…</div></div>
    </div>

    <div id="download-wrap">
        <?php if (!$is_active && file_exists("logs/lot_{$id}.txt")): ?>
            <a class="download-link" href="logs/lot_<?= $id ?>.txt" download>
                ↓ Скачать историю торгов (.txt)
            </a>
        <?php endif; ?>
    </div>

    <a class="registry-link" href="https://forsage.ct.ws/reestr.php">
        ← Вернуться в реестр лотов
    </a>
</div>

<script>
const LOT_ID        = <?= (int)$id ?>;
const SERVER_OFFSET = <?= time() ?> * 1000 - Date.now();
let   startedMs     = <?= $started_ts ?> * 1000;

let endTime      = <?= $end_ts * 1000 ?>;
let auctionEnded = <?= $is_active ? 'false' : 'true' ?>;
let tickTimer    = null;
let syncTimer    = null;

/* ── Серверное время ─────────────────────────────────── */
function updateServerTime() {
    const now = new Date(Date.now() + SERVER_OFFSET);
    const pad = n => String(n).padStart(2, '0');
    const el  = document.getElementById('server-time');
    if (el) el.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}

/* ── Счётчик продолжительности торгов ───────────────── */
function updateDuration() {
    const box = document.getElementById('dur-box');
    const val = document.getElementById('dur-val');
    if (!val) return;

    if (!startedMs) {
        val.textContent = '--:--:--';
        return;
    }

    const serverNow = Date.now() + SERVER_OFFSET;
    // Если торги завершены — показываем финальное время (endTime - startedMs)
    const elapsed = auctionEnded
        ? Math.max(0, endTime - startedMs)
        : Math.max(0, serverNow - startedMs);

    const h = String(Math.floor(elapsed / 3600000)).padStart(2, '0');
    const m = String(Math.floor(elapsed % 3600000 / 60000)).padStart(2, '0');
    const s = String(Math.floor(elapsed % 60000  / 1000)).padStart(2, '0');
    val.textContent = `${h}:${m}:${s}`;

    if (box) {
        box.classList.toggle('active', !!startedMs && !auctionEnded);
        box.classList.toggle('ended',  !!startedMs && auctionEnded);
    }
}

/* ── Таймер обратного отсчёта ────────────────────────── */
function tick() {
    updateServerTime();
    updateDuration();

    const diff = endTime - (Date.now() + SERVER_OFFSET);
    const el   = document.getElementById('tm');

    if (diff <= 0) {
        if (!auctionEnded) {
            auctionEnded = true;
            clearInterval(tickTimer);

            el.textContent = 'ЗАВЕРШЕНО';
            el.classList.add('ended');

            const badge = document.getElementById('live-badge');
            if (badge) { badge.textContent = '● ЗАВЕРШЕНО'; badge.style.color = '#475569'; }

            const btn = document.getElementById('bt');
            if (btn) { btn.disabled = true; btn.textContent = 'ТОРГИ ЗАВЕРШЕНЫ'; }

            const inp = document.getElementById('in');
            if (inp) inp.disabled = true;
        }
        return;
    }

    const h = String(Math.floor(diff / 3600000)).padStart(2, '0');
    const m = String(Math.floor(diff % 3600000 / 60000)).padStart(2, '0');
    const s = String(Math.floor(diff % 60000  / 1000)).padStart(2, '0');
    el.textContent = `${h}:${m}:${s}`;
}

/* ── Синхронизация с сервером ────────────────────────── */
function sync() {
    fetch('lot_details.php?ajax=1&t=' + Date.now())
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(d => {
            if (d.error) { showMsg(d.error, '#ef4444'); return; }

            // Цена
            document.getElementById('pr').textContent =
                d.price.toLocaleString('ru-RU') + '\u00A0₽';

            // История
            document.getElementById('hist').innerHTML = d.html;

            // Обновляем время окончания (авто-продление меняет его)
            if (d.end) endTime = d.end;

            // Обновляем время старта (появляется после первой ставки)
            if (d.started_ms && !startedMs) {
                startedMs = d.started_ms;
                updateDuration();
            }

            // Кнопка и статус лидера
            const btn = document.getElementById('bt');
            if (btn && !auctionEnded) {
                btn.disabled    = d.leader;
                btn.textContent = d.leader ? 'ВЫ ЛИДИРУЕТЕ' : 'СДЕЛАТЬ СТАВКУ';
            }

            const statusEl = document.getElementById('leader-status');
            if (statusEl && !auctionEnded) {
                if (d.leader) {
                    statusEl.innerHTML = "<span style='color:#4ade80;'>● Ваша ставка лучшая</span>";
                } else {
                    statusEl.innerHTML = "<span style='color:#f87171;'>○ Ваша ставка перебита</span>";
                }
            }

            // Поле ввода
            const inp = document.getElementById('in');
            const minBid = d.price + 1000;
            if (inp && !auctionEnded && !d.leader) {
                inp.min = minBid;
                if (!inp.value || parseInt(inp.value) < minBid) {
                    inp.value = minBid;
                }
                inp.disabled = false;
            } else if (inp && d.leader) {
                inp.disabled = true;
            }
            // Обновляем подсказку
            const hintMin = document.getElementById('hint-min');
            if (hintMin) hintMin.textContent = minBid.toLocaleString('ru-RU');

            // Ссылка на лог появляется после завершения
            if (d.is_over && d.log_exists) {
                const wrap = document.getElementById('download-wrap');
                if (wrap && !wrap.querySelector('a')) {
                    wrap.innerHTML =
                        `<a class="download-link" href="logs/lot_${LOT_ID}.txt" download>`
                        + `↓ Скачать историю торгов (.txt)</a>`;
                }
            }

            // Торги завершились — останавливаем sync
            if (d.is_over && !auctionEnded) {
                clearInterval(syncTimer);
            }
        })
        .catch(() => showMsg('Ошибка связи с сервером', '#ef4444'));
}

/* ── Ставка ──────────────────────────────────────────── */
function bid() {
    const inp    = document.getElementById('in');
    const amount = parseInt(inp?.value, 10);
    const minBid = parseInt(inp?.min, 10) || 0;

    if (!amount || amount < minBid) {
        showMsg('Минимальная ставка: ' + minBid.toLocaleString('ru-RU') + ' ₽', '#ef4444');
        return;
    }

    const btn = document.getElementById('bt');
    btn.disabled    = true;
    btn.textContent = 'Отправка…';
    showMsg('', '');

    const fd = new FormData();
    fd.append('lot_id',     LOT_ID);
    fd.append('bid_amount', amount);

    fetch('send_bid.php', { method: 'POST', body: fd })
        .then(r => {
            if (r.status === 401) {
                showMsg('Сессия истекла — войдите снова', '#f87171');
                if (typeof openAuthModal === 'function') openAuthModal();
                btn.disabled    = false;
                btn.textContent = 'СДЕЛАТЬ СТАВКУ';
                return null;
            }
            return r.text();
        })
        .then(res => {
            if (res === null) return;
            if (res.trim() === 'success') {
                showMsg('СТАВКА ПРИНЯТА!', '#22c55e');
                sync();
            } else {
                showMsg(res.trim() || 'Неизвестная ошибка', '#ef4444');
                btn.disabled    = false;
                btn.textContent = 'СДЕЛАТЬ СТАВКУ';
            }
        })
        .catch(() => {
            showMsg('Ошибка связи с сервером', '#ef4444');
            btn.disabled    = false;
            btn.textContent = 'СДЕЛАТЬ СТАВКУ';
        });
}

function showMsg(text, color) {
    const m = document.getElementById('msg');
    if (!m) return;
    m.textContent = text;
    m.style.color = color;
}

document.getElementById('bt').onclick = bid;

/* ── Запуск ──────────────────────────────────────────── */
if (!auctionEnded) {
    tickTimer = setInterval(tick, 1000);
    syncTimer = setInterval(sync, 2000);
    tick();
    sync();
} else {
    setInterval(updateServerTime, 1000);
    updateServerTime();
    sync();
}
</script>

<!-- ── Модальное окно авторизации / регистрации ─────── -->
<div id="auth-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:1000;justify-content:center;align-items:center;backdrop-filter:blur(5px);">
    <div style="background:#1e293b;width:100%;max-width:440px;border-radius:24px;border:1px solid #334155;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);margin:16px;">

        <!-- Вкладки -->
        <div style="display:flex;background:#0f172a;padding:5px;gap:4px;">
            <button id="auth-tab-btn-login" onclick="authSwitchTab('login')"
                style="flex:1;padding:14px;border:none;background:#1e293b;color:#fff;cursor:pointer;font-weight:bold;border-radius:10px;font-size:14px;transition:background 0.2s;">
                ВХОД
            </button>
            <button id="auth-tab-btn-register" onclick="authSwitchTab('register')"
                style="flex:1;padding:14px;border:none;background:transparent;color:#64748b;cursor:pointer;font-weight:bold;border-radius:10px;font-size:14px;transition:background 0.2s;">
                РЕГИСТРАЦИЯ
            </button>
        </div>

        <div style="padding:28px;">

            <!-- Сообщение об ошибке/успехе -->
            <div id="auth-msg" style="min-height:20px;font-size:13px;font-weight:bold;margin-bottom:12px;text-align:center;"></div>

            <!-- Вкладка: Вход -->
            <div id="auth-tab-login">
                <input id="auth-login-username" type="text" placeholder="Логин"
                    style="width:100%;padding:14px;border-radius:10px;background:#0f172a;border:1px solid #334155;color:#fff;font-size:15px;margin-bottom:10px;box-sizing:border-box;outline:none;"
                    onkeydown="if(event.key==='Enter')authSubmitLogin()">
                <input id="auth-login-password" type="password" placeholder="Пароль"
                    style="width:100%;padding:14px;border-radius:10px;background:#0f172a;border:1px solid #334155;color:#fff;font-size:15px;margin-bottom:18px;box-sizing:border-box;outline:none;"
                    onkeydown="if(event.key==='Enter')authSubmitLogin()">
                <button onclick="authSubmitLogin()" id="auth-login-btn"
                    style="width:100%;padding:15px;background:#3b82f6;border:none;border-radius:12px;color:#fff;font-weight:bold;cursor:pointer;font-size:16px;transition:background 0.2s;">
                    ВОЙТИ
                </button>
            </div>

            <!-- Вкладка: Регистрация -->
            <div id="auth-tab-register" style="display:none;">
                <input id="auth-reg-username" type="text" placeholder="Логин (только буквы и цифры)"
                    style="width:100%;padding:14px;border-radius:10px;background:#0f172a;border:1px solid #334155;color:#fff;font-size:15px;margin-bottom:10px;box-sizing:border-box;outline:none;">
                <input id="auth-reg-email" type="email" placeholder="Email"
                    style="width:100%;padding:14px;border-radius:10px;background:#0f172a;border:1px solid #334155;color:#fff;font-size:15px;margin-bottom:10px;box-sizing:border-box;outline:none;">
                <input id="auth-reg-password" type="password" placeholder="Пароль (мин. 6 символов)"
                    style="width:100%;padding:14px;border-radius:10px;background:#0f172a;border:1px solid #334155;color:#fff;font-size:15px;margin-bottom:10px;box-sizing:border-box;outline:none;">
                <input id="auth-reg-password2" type="password" placeholder="Повторите пароль"
                    style="width:100%;padding:14px;border-radius:10px;background:#0f172a;border:1px solid #334155;color:#fff;font-size:15px;margin-bottom:18px;box-sizing:border-box;outline:none;"
                    onkeydown="if(event.key==='Enter')authSubmitRegister()">
                <button onclick="authSubmitRegister()" id="auth-reg-btn"
                    style="width:100%;padding:15px;background:#3b82f6;border:none;border-radius:12px;color:#fff;font-weight:bold;cursor:pointer;font-size:16px;transition:background 0.2s;">
                    ЗАРЕГИСТРИРОВАТЬСЯ
                </button>
            </div>

            <button onclick="closeAuthModal()"
                style="width:100%;margin-top:12px;padding:12px;background:transparent;border:1px solid #334155;border-radius:12px;color:#64748b;cursor:pointer;font-size:14px;transition:border-color 0.2s;">
                Отмена
            </button>
        </div>
    </div>
</div>

<script>
/* ── Авторизация / Регистрация ───────────────────────── */
function openAuthModal(tab) {
    authSwitchTab(tab || 'login');
    setAuthMsg('', '');
    document.getElementById('auth-modal-overlay').style.display = 'flex';
}

function closeAuthModal() {
    document.getElementById('auth-modal-overlay').style.display = 'none';
}

document.getElementById('auth-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeAuthModal();
});

function authSwitchTab(tab) {
    const isLogin = tab === 'login';
    document.getElementById('auth-tab-login').style.display    = isLogin ? 'block' : 'none';
    document.getElementById('auth-tab-register').style.display = isLogin ? 'none'  : 'block';

    const btnLogin = document.getElementById('auth-tab-btn-login');
    const btnReg   = document.getElementById('auth-tab-btn-register');
    btnLogin.style.background = isLogin ? '#1e293b'     : 'transparent';
    btnLogin.style.color      = isLogin ? '#fff'        : '#64748b';
    btnReg.style.background   = isLogin ? 'transparent' : '#1e293b';
    btnReg.style.color        = isLogin ? '#64748b'     : '#fff';

    setAuthMsg('', '');
}

function setAuthMsg(text, color) {
    const m = document.getElementById('auth-msg');
    m.textContent = text;
    m.style.color = color || '#ef4444';
}

function authSetLoading(btnId, loading, defaultText) {
    const btn = document.getElementById(btnId);
    btn.disabled    = loading;
    btn.textContent = loading ? 'Загрузка…' : defaultText;
}

function authSubmitLogin() {
    const username = document.getElementById('auth-login-username').value.trim();
    const password = document.getElementById('auth-login-password').value;

    if (!username || !password) {
        setAuthMsg('Заполните все поля');
        return;
    }

    authSetLoading('auth-login-btn', true, 'ВОЙТИ');
    setAuthMsg('', '');

    const fd = new FormData();
    fd.append('action',   'login');
    fd.append('username', username);
    fd.append('password', password);

    fetch('auth.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => {
            if (res.trim() === 'success') {
                setAuthMsg('Вход выполнен! Обновляем…', '#22c55e');
                setTimeout(() => location.reload(), 800);
            } else {
                setAuthMsg(res.trim() || 'Неверный логин или пароль');
                authSetLoading('auth-login-btn', false, 'ВОЙТИ');
            }
        })
        .catch(() => {
            setAuthMsg('Ошибка связи с сервером');
            authSetLoading('auth-login-btn', false, 'ВОЙТИ');
        });
}

function authSubmitRegister() {
    const username  = document.getElementById('auth-reg-username').value.trim();
    const email     = document.getElementById('auth-reg-email').value.trim();
    const password  = document.getElementById('auth-reg-password').value;
    const password2 = document.getElementById('auth-reg-password2').value;

    if (!username || !email || !password || !password2) {
        setAuthMsg('Заполните все поля');
        return;
    }
    if (!/^[a-zA-Z0-9_а-яёА-ЯЁ]+$/.test(username)) {
        setAuthMsg('Логин содержит недопустимые символы');
        return;
    }
    if (password.length < 6) {
        setAuthMsg('Пароль должен быть не менее 6 символов');
        return;
    }
    if (password !== password2) {
        setAuthMsg('Пароли не совпадают');
        return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        setAuthMsg('Некорректный email');
        return;
    }

    authSetLoading('auth-reg-btn', true, 'ЗАРЕГИСТРИРОВАТЬСЯ');
    setAuthMsg('', '');

    const fd = new FormData();
    fd.append('action',   'register');
    fd.append('username', username);
    fd.append('email',    email);
    fd.append('password', password);

    fetch('auth.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => {
            if (res.trim() === 'success') {
                setAuthMsg('Регистрация успешна! Войдите в аккаунт.', '#22c55e');
                setTimeout(() => authSwitchTab('login'), 1200);
            } else {
                setAuthMsg(res.trim() || 'Ошибка регистрации');
            }
            authSetLoading('auth-reg-btn', false, 'ЗАРЕГИСТРИРОВАТЬСЯ');
        })
        .catch(() => {
            setAuthMsg('Ошибка связи с сервером');
            authSetLoading('auth-reg-btn', false, 'ЗАРЕГИСТРИРОВАТЬСЯ');
        });
}
</script>

</body>
</html>
