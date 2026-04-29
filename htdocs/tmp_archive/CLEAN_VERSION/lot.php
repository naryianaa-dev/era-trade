<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В продакшене убрать display_errors
// ini_set('display_errors', 0);

include 'db.php';
date_default_timezone_set('Europe/Moscow');

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 6;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

try {
    $stmt = $pdo->prepare("SELECT id, title, price, end_time, last_bid_user FROM lots WHERE id = ?");
    $stmt->execute([$id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lot) {
        http_response_code(404);
        die("Лот №" . $id . " не найден.");
    }

    $end_ts        = (int)strtotime($lot['end_time']);
    $is_active     = $end_ts > time();
    $last_bid_user = (int)$lot['last_bid_user'];
    $is_leader     = $user_id > 0 && $last_bid_user === $user_id;
    $min_bid       = (int)$lot['price'] + 1000;

    // История ставок
    $stmt_b = $pdo->prepare(
        "SELECT b.bid_amount, u.username
         FROM bids b
         JOIN users u ON b.user_id = u.id
         WHERE b.lot_id = ?
         ORDER BY b.id DESC
         LIMIT 10"
    );
    $stmt_b->execute([$id]);
    $bids = $stmt_b->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    http_response_code(500);
    die("Ошибка БД.");
}

$title_safe = htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8');
$price_fmt  = number_format((float)$lot['price'], 0, '.', "\u{00A0}");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forsage — <?= $title_safe ?></title>
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
        }

        .lot-label  { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .lot-title  { font-size: 22px; font-weight: bold; margin: 6px 0 16px; }
        .price      { font-size: 52px; font-weight: 900; margin: 0 0 20px; line-height: 1; }

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
            margin-bottom: 8px;
        }
        .timer-label { font-size: 11px; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .timer {
            font-size: 36px;
            font-weight: bold;
            font-family: monospace;
            color: #f87171;
            letter-spacing: 2px;
            transition: color 0.4s;
        }
        .timer.ended { color: #475569; font-size: 20px; letter-spacing: 0; }

        .leader-status { font-size: 13px; margin-top: 10px; }

        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-weight: bold;
            cursor: pointer;
            font-size: 17px;
            transition: background 0.2s, opacity 0.2s;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #2563eb; }
        .btn-primary:disabled { background: #334155; color: #64748b; cursor: not-allowed; }

        /* Модальное окно ставки */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 100;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 28px;
            width: 340px;
            text-align: center;
        }
        .modal h2 { margin: 0 0 8px; font-size: 18px; }
        .modal p  { color: #94a3b8; font-size: 13px; margin: 0 0 18px; }
        .modal input {
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #fff;
            font-size: 22px;
            text-align: center;
            margin-bottom: 14px;
            outline: none;
        }
        .modal input:focus { border-color: #3b82f6; }
        .modal-actions { display: flex; gap: 10px; }
        .modal-actions .btn { flex: 1; padding: 13px; font-size: 15px; }
        .btn-cancel { background: #334155; color: #94a3b8; }
        .btn-cancel:hover { background: #3d5068; }
        #modal-msg {
            min-height: 20px;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        /* История ставок */
        .history { margin-top: 24px; text-align: left; border-radius: 12px; overflow: hidden; background: rgba(0,0,0,0.2); }
        .history-title { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; padding: 10px 12px 6px; }
        .history-row {
            display: flex;
            justify-content: space-between;
            padding: 9px 12px;
            border-bottom: 1px solid #1e293b;
            font-size: 14px;
        }
        .history-row:last-child { border-bottom: none; }
        .history-empty { padding: 12px; color: #475569; font-size: 13px; text-align: center; }

        .download-link {
            display: block;
            margin-top: 12px;
            color: #475569;
            font-size: 11px;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .download-link:hover { color: #94a3b8; }

        .registry-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 20px;
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
    <h1 class="lot-title"><?= $title_safe ?></h1>

    <div class="price" id="price-display"><?= $price_fmt ?>&nbsp;₽</div>

    <div class="info-box">
        <div class="info-meta">
            <span>Сервер: <span id="server-time"><?= date('H:i:s') ?></span></span>
            <span id="live-badge" style="color:#22c55e;">● В ЭФИРЕ</span>
        </div>
        <div class="timer-label">До завершения</div>
        <div class="timer<?= $is_active ? '' : ' ended' ?>" id="countdown">
            <?= $is_active ? '--:--:--' : 'ЗАВЕРШЕНО' ?>
        </div>
        <div class="leader-status">
            <?php if ($is_leader): ?>
                <span style="color:#4ade80;">● Ваша ставка лучшая</span>
            <?php elseif ($user_id > 0): ?>
                <span style="color:#f87171;">○ Ваша ставка перебита</span>
            <?php else: ?>
                <span style="color:#64748b;">○ Вы не авторизованы</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_active): ?>
        <button id="bid-btn" class="btn btn-primary"
            <?= $is_leader ? 'disabled' : '' ?>>
            <?= $is_leader ? 'ВЫ ЛИДИРУЕТЕ' : 'СДЕЛАТЬ СТАВКУ' ?>
        </button>
    <?php else: ?>
        <button class="btn btn-primary" disabled>ТОРГИ ЗАВЕРШЕНЫ</button>
    <?php endif; ?>

    <!-- История ставок -->
    <div class="history">
        <div class="history-title">Последние ставки</div>
        <?php if (empty($bids)): ?>
            <div class="history-empty">Ставок пока нет</div>
        <?php else: ?>
            <?php foreach ($bids as $row):
                $uname  = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
                $masked = mb_substr($uname, 0, 1) . '***' . mb_substr($uname, -1);
                $amt    = number_format((float)$row['bid_amount'], 0, '.', "\u{00A0}");
            ?>
            <div class="history-row">
                <span style="color:#94a3b8;"><?= $masked ?></span>
                <b><?= $amt ?>&nbsp;₽</b>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Ссылка на скачивание — только после завершения торгов -->
    <?php if (!$is_active): ?>
    <a class="download-link"
       href="logs/lot_<?= $id ?>.txt"
       download>↓ Скачать историю торгов (.txt)</a>
    <?php else: ?>
    <div id="download-placeholder" style="min-height:28px;"></div>
    <?php endif; ?>

    <a class="registry-link" href="https://forsage.ct.ws/reestr.php">
        ← Вернуться в реестр лотов
    </a>
</div>

<!-- Модальное окно ставки -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <h2>Сделать ставку</h2>
        <p>Минимальная ставка: <b><?= number_format($min_bid, 0, '.', "\u{00A0}") ?>&nbsp;₽</b></p>
        <div id="modal-msg"></div>
        <input type="number"
               id="bid-input"
               min="<?= $min_bid ?>"
               step="1000"
               value="<?= $min_bid ?>"
               placeholder="Сумма ставки">
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" id="confirm-btn" onclick="submitBid()">Подтвердить</button>
        </div>
    </div>
</div>

<script>
const LOT_ID     = <?= (int)$id ?>;
const END_TIME   = <?= $end_ts * 1000 ?>;
const SERVER_OFFSET = <?= time() ?> * 1000 - Date.now(); // разница серверного и клиентского времени
let   auctionEnded = <?= $is_active ? 'false' : 'true' ?>;
let   tickInterval = null;

/* ── Серверное время ─────────────────────────────────── */
function updateServerTime() {
    const serverNow = new Date(Date.now() + SERVER_OFFSET);
    const h = String(serverNow.getHours()).padStart(2, '0');
    const m = String(serverNow.getMinutes()).padStart(2, '0');
    const s = String(serverNow.getSeconds()).padStart(2, '0');
    const el = document.getElementById('server-time');
    if (el) el.textContent = `${h}:${m}:${s}`;
}

/* ── Таймер обратного отсчёта ────────────────────────── */
function tick() {
    updateServerTime();

    const diff = END_TIME - (Date.now() + SERVER_OFFSET);
    const el   = document.getElementById('countdown');

    if (diff <= 0) {
        if (!auctionEnded) {
            auctionEnded = true;
            clearInterval(tickInterval);

            el.textContent = 'ЗАВЕРШЕНО';
            el.classList.add('ended');

            const badge = document.getElementById('live-badge');
            if (badge) { badge.textContent = '● ЗАВЕРШЕНО'; badge.style.color = '#475569'; }

            const btn = document.getElementById('bid-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'ТОРГИ ЗАВЕРШЕНЫ'; }

            // Показываем ссылку на скачивание лога
            const ph = document.getElementById('download-placeholder');
            if (ph) {
                ph.innerHTML = `<a class="download-link"
                    href="logs/lot_<?= $id ?>.txt"
                    download>↓ Скачать историю торгов (.txt)</a>`;
            }
        }
        return;
    }

    const h = String(Math.floor(diff / 3600000)).padStart(2, '0');
    const m = String(Math.floor(diff % 3600000 / 60000)).padStart(2, '0');
    const s = String(Math.floor(diff % 60000  / 1000)).padStart(2, '0');
    el.textContent = `${h}:${m}:${s}`;
}

if (!auctionEnded) {
    tickInterval = setInterval(tick, 1000);
    tick();
} else {
    // Торги уже завершены при загрузке — всё равно тикаем серверное время
    setInterval(updateServerTime, 1000);
    updateServerTime();
}

/* ── Модальное окно ──────────────────────────────────── */
function openModal() {
    document.getElementById('modal').classList.add('open');
    document.getElementById('bid-input').focus();
    setModalMsg('', '');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('bid-input')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') submitBid();
    if (e.key === 'Escape') closeModal();
});

const bidBtn = document.getElementById('bid-btn');
if (bidBtn) bidBtn.onclick = openModal;

/* ── Отправка ставки ─────────────────────────────────── */
function setModalMsg(text, color) {
    const m = document.getElementById('modal-msg');
    m.textContent  = text;
    m.style.color  = color;
}

function submitBid() {
    const input  = document.getElementById('bid-input');
    const amount = parseInt(input.value, 10);
    const minBid = parseInt(input.min, 10);

    if (!amount || amount < minBid) {
        setModalMsg(`Минимальная ставка: ${minBid.toLocaleString('ru-RU')} ₽`, '#ef4444');
        input.focus();
        return;
    }

    const confirmBtn = document.getElementById('confirm-btn');
    confirmBtn.disabled  = true;
    confirmBtn.textContent = 'Отправка…';
    setModalMsg('', '');

    const fd = new FormData();
    fd.append('lot_id',     LOT_ID);
    fd.append('bid_amount', amount);

    fetch('send_bid.php', { method: 'POST', body: fd })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(res => {
            if (res.trim() === 'success') {
                closeModal();
                location.reload();
            } else {
                setModalMsg(res.trim() || 'Неизвестная ошибка', '#ef4444');
                confirmBtn.disabled     = false;
                confirmBtn.textContent  = 'Подтвердить';
            }
        })
        .catch(() => {
            setModalMsg('Ошибка связи с сервером', '#ef4444');
            confirmBtn.disabled     = false;
            confirmBtn.textContent  = 'Подтвердить';
        });
}
</script>

</body>
</html>
