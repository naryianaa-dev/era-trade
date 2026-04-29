<?php
/**
 * lot_closed.php — закрытый аукцион на повышение в реальном времени.
 *
 * Особенности:
 *   - подача ставок как в открытом аукционе на повышение
 *   - таймер фиксирован, не продлевается при ставках
 *   - длительность задаётся организатором при создании лота
 *   - в процессе торгов видно только лучшее предложение,
 *     данные об участниках и история не раскрываются
 *   - доступ к торгам — только для участников, допущенных
 *     организатором/админом вручную через админку
 *   - участник может перебить свою же ставку
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['lang'])) {
    $accept_lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'ru';
    $_SESSION['lang'] = (substr($accept_lang, 0, 2) === 'ru') ? 'ru' : 'en';
}
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'ru';
}
$lang = $_SESSION['lang'];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_schema_extra.php';

date_default_timezone_set('Europe/Moscow');

$id      = (int)($_GET['id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

/* ─── AJAX опрос состояния ──────────────────────────────────── */
if (isset($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->prepare("SELECT id, owner_id, price, end_time, auction_type, bid_step FROM lots WHERE id = ? AND auction_type = 'closed'");
        $stmt->execute([$id]);
        $lot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lot) { echo json_encode(['error' => 'not_found']); exit; }

        $end_ts  = (int)strtotime($lot['end_time']);
        $is_over = $end_ts > 0 && $end_ts <= time();

        /* Статус участия пользователя. Имя текущего лидера НЕ раскрываем. */
        $part_status = null;
        if ($user_id > 0) {
            if ((int)$lot['owner_id'] === $user_id) {
                $part_status = 'owner';
            } else {
                $stmt = $pdo->prepare("SELECT status FROM closed_participants WHERE lot_id = ? AND user_id = ?");
                $stmt->execute([$id, $user_id]);
                $part_status = $stmt->fetchColumn() ?: null;
            }
        }

        $approved_count = (int)$pdo->query("SELECT COUNT(*) FROM closed_participants WHERE lot_id = ".(int)$id." AND status = 'approved'")->fetchColumn();

        echo json_encode([
            'price'         => (int)$lot['price'],
            'bid_step'      => (int)$lot['bid_step'],
            'end_ms'        => $end_ts * 1000,
            'server_ts'     => time() * 1000,
            'is_over'       => $is_over,
            'part_status'   => $part_status,
            'approved_cnt'  => $approved_count,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ─── Серверный рендер ──────────────────────────────────────── */
try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.username AS owner_name
        FROM lots l
        LEFT JOIN users u ON u.id = l.owner_id
        WHERE l.id = ? AND l.auction_type = 'closed'
    ");
    $stmt->execute([$id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot) { http_response_code(404); die('Лот не найден.'); }
} catch (Exception $e) {
    http_response_code(500); die('Ошибка БД');
}

$end_ts   = (int)strtotime($lot['end_time']);
$is_over  = $end_ts > 0 && $end_ts <= time();
$is_owner = $user_id > 0 && (int)$lot['owner_id'] === $user_id;
$step     = (int)$lot['bid_step'] ?: 1000;
$price    = (int)$lot['price'];
$min_bid  = $price + $step;

/* Статус участия. */
$part_status = null;
if ($user_id > 0 && !$is_owner) {
    $stmt = $pdo->prepare("SELECT status FROM closed_participants WHERE lot_id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
    $part_status = $stmt->fetchColumn() ?: null;
}

/* <?= $lang === 'en' ? 'Winner' : 'Победитель' ?> — только после окончания торгов. */
$winner_name = null;
$winner_price = null;
if ($is_over && (int)$lot['last_bid_user'] > 0) {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([(int)$lot['last_bid_user']]);
    $winner_name = $stmt->fetchColumn() ?: null;
    $winner_price = (float)$lot['price'];
}

include 'header.php';
?>
<style>
.closed-wrap{max-width:780px;margin:0 auto;padding:24px 16px;}
.cl-card{background:#0f172a;color:#fff;border-radius:24px;border:1px solid #334155;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,.4);}
.cl-header{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:22px 26px;}
.cl-badge{display:inline-flex;align-items:center;gap:6px;background:#a855f7;color:#fff;font-size:11px;font-weight:700;padding:5px 10px;border-radius:999px;letter-spacing:.5px;text-transform:uppercase;}
.cl-title{font-size:22px;font-weight:800;margin:10px 0 6px;color:#fff;}
.cl-sub{color:#c4b5fd;font-size:13px;}
.cl-body{padding:24px 26px;}
.cl-best-label{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;text-align:center;}
.cl-best{font-size:48px;font-weight:900;text-align:center;color:#fff;line-height:1.1;margin:6px 0 16px;}
.cl-timer-label{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;text-align:center;}
.cl-timer{font-family:monospace;font-size:36px;font-weight:900;text-align:center;color:#a78bfa;margin:4px 0 14px;}
.cl-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #334155;font-size:14px;}
.cl-row:last-of-type{border-bottom:none;}
.cl-row .lbl{color:#94a3b8;}
.cl-row .val{color:#fff;font-weight:700;}
.cl-form{margin-top:18px;background:#1e293b;border:1px solid #334155;border-radius:14px;padding:18px;}
.cl-form label{display:block;font-size:12px;font-weight:600;color:#cbd5e1;margin-bottom:6px;}
.cl-form input,.cl-form textarea{width:100%;padding:12px 14px;border:1.5px solid #334155;border-radius:10px;font-size:15px;background:#0f172a;color:#fff;font-family:inherit;}
.cl-form input:focus,.cl-form textarea:focus{outline:none;border-color:#a855f7;box-shadow:0 0 0 3px rgba(168,85,247,.18);}
.cl-form .hint{font-size:11px;color:#94a3b8;margin-top:4px;}
.cl-form button{width:100%;margin-top:14px;background:#a855f7;color:#fff;border:0;border-radius:12px;padding:14px;font-size:16px;font-weight:800;cursor:pointer;letter-spacing:.5px;}
.cl-form button:disabled{opacity:.6;cursor:not-allowed;background:#475569;}
.cl-msg{margin-top:10px;padding:10px 12px;border-radius:10px;font-size:13px;display:none;}
.cl-msg.ok{background:#14532d;color:#86efac;display:block;}
.cl-msg.err{background:#7f1d1d;color:#fca5a5;display:block;}
.cl-finished{background:#1e1b4b;border:1px solid #4c1d95;color:#c4b5fd;border-radius:14px;padding:18px;margin-top:18px;text-align:center;}
.cl-finished b{display:block;font-size:24px;color:#fff;margin:6px 0;}
.cl-info{background:#1e293b;border:1px solid #334155;color:#cbd5e1;border-radius:14px;padding:14px;margin-top:14px;font-size:13px;line-height:1.5;}
.cl-state-pending{color:#fbbf24;}
.cl-state-rejected{color:#f87171;}
.cl-back{display:inline-block;margin-bottom:14px;color:#94a3b8;font-size:13px;text-decoration:none;}
.cl-back:hover{color:#fff;}
@media(max-width:640px){
    .closed-wrap{padding:14px 10px;}
    .cl-header,.cl-body{padding:18px;}
    .cl-title{font-size:18px;}
    .cl-best{font-size:38px;}
    .cl-timer{font-size:28px;}
}
</style>

<main class="closed-wrap">
    <a href="reestr.php" class="cl-back"><?= $lang === 'en' ? '← To registry' : '← К реестру' ?></a>
    <div class="cl-card">
        <div class="cl-header">
            <span class="cl-badge">🔒 Закрытый аукцион</span>
            <div class="cl-title"><?= htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="cl-sub"><?= $lang === 'en' ? 'Lot №' : 'Лот №' ?><?= (int)$lot['id'] ?> <?= $lang === 'en' ? '· Only the best bid is visible during the auction' : '· Только лучшая ставка видна в процессе' ?></div>
        </div>
        <div class="cl-body">
            <div class="cl-best-label"><?= $lang === 'en' ? 'Best bid' : 'Лучшее предложение' ?></div>
            <div class="cl-best" id="clBest"><?= number_format($price, 0, '.', ' ') ?> ₽</div>

            <div class="cl-timer-label"><?= $lang === 'en' ? 'Time left' : 'До окончания торгов' ?></div>
            <div class="cl-timer" id="clTimer">--:--:--</div>
            <div style="text-align:center;font-size:11px;color:#64748b;">
                <?= $lang === 'en' ? 'Ends:' : 'Окончание:' ?> <?= date('d.m.Y H:i', $end_ts) ?> <?= $lang === 'en' ? '· Fixed timer; not extended' : '· Таймер фиксированный, не продлевается' ?>
            </div>

            <div style="height:14px;"></div>
            <div class="cl-row">
                <span class="lbl"><?= $lang === 'en' ? 'Starting price' : 'Стартовая цена' ?></span>
                <span class="val"><?= number_format((float)$lot['start_price'], 0, '.', ' ') ?> ₽</span>
            </div>
            <div class="cl-row">
                <span class="lbl"><?= $lang === 'en' ? 'Bid step' : 'Шаг повышения' ?></span>
                <span class="val"><?= number_format($step, 0, '.', ' ') ?> ₽</span>
            </div>
            <div class="cl-row">
                <span class="lbl"><?= $lang === 'en' ? 'Admitted bidders' : 'Допущено участников' ?></span>
                <span class="val" id="approvedCnt">…</span>
            </div>

            <?php if ($is_over): ?>
                <div class="cl-finished">
                    <span style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#a78bfa;"><?= $lang === 'en' ? 'Auction ended' : 'Аукцион завершён' ?></span>
                    <?php if ($winner_name): ?>
                        <b><?= number_format((float)$winner_price, 0, '.', ' ') ?> ₽</b>
                        <div style="color:#cbd5e1;"><?= $lang === 'en' ? 'Winner:' : 'Победитель:' ?> <?= htmlspecialchars($winner_name) ?></div>
                    <?php else: ?>
                        <b style="font-size:18px;color:#cbd5e1;"><?= $lang === 'en' ? 'No bids were placed' : 'Ставок не было' ?></b>
                    <?php endif; ?>
                </div>
            <?php elseif ($is_owner): ?>
                <div class="cl-info">
                    <?= $lang === 'en' ? 'You are the organizer.' : 'Вы являетесь организатором.' ?> <a href="admin.php?tab=closed_admit&lot_id=<?= (int)$lot['id'] ?>" style="color:#a78bfa;"><?= $lang === 'en' ? 'Manage participant admission' : 'Управлять допуском участников' ?></a>.
                    В процессе торгов имена и ставки участников вам не видны — только текущая лучшая цена.
                </div>
            <?php elseif ($user_id <= 0): ?>
                <div class="cl-info"><?= $lang === 'en' ? 'To apply for participation,' : 'Чтобы подать заявку на участие,' ?> <a href="login.php" style="color:#a78bfa;"><?= $lang === 'en' ? 'sign in' : 'войдите в аккаунт' ?></a>.</div>
            <?php elseif ($part_status === null): ?>
                <form id="applyForm" class="cl-form">
                    <h3 style="margin:0 0 10px;font-size:16px;color:#fff;"><?= $lang === 'en' ? 'Apply for participation' : 'Подать заявку на участие' ?></h3>
                    <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
                    <label><?= $lang === 'en' ? 'About you (optional)' : 'Информация о вас (необязательно)' ?></label>
                    <textarea name="application_text" rows="3" placeholder="Краткая информация о компании, опыт работы, контакты и т. п."></textarea>
                    <div class="hint">Заявку рассмотрит организатор/администратор. После одобрения вы сможете подавать ставки.</div>
                    <button type="submit" id="applySubmit">Подать заявку</button>
                    <div id="applyMsg" class="cl-msg"></div>
                </form>
            <?php elseif ($part_status === 'pending'): ?>
                <div class="cl-info cl-state-pending">
                    ⏳ Ваша заявка на участие подана и ожидает рассмотрения организатором/администратором.
                </div>
            <?php elseif ($part_status === 'rejected'): ?>
                <div class="cl-info cl-state-rejected">
                    ❌ Ваша заявка на участие отклонена.
                </div>
            <?php elseif ($part_status === 'approved'): ?>
                <form id="bidForm" class="cl-form">
                    <h3 style="margin:0 0 10px;font-size:16px;color:#fff;"><?= $lang === 'en' ? 'Place bid' : 'Сделать ставку' ?></h3>
                    <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
                    <label><?= $lang === 'en' ? 'Your bid (₽), at least' : 'Ваша ставка (₽), не менее' ?> <span id="hintMin"><?= number_format($min_bid, 0, '.', ' ') ?></span> ₽</label>
                    <input type="number" name="bid_amount" id="clAmount" step="1" min="<?= $min_bid ?>" value="<?= $min_bid ?>" required>
                    <div class="hint"><?= $lang === 'en' ? 'You have been admitted. You may outbid your own bid.' : 'Вы допущены к торгам. Можно перебивать в том числе свою же ставку.' ?></div>
                    <button type="submit" id="clBidBtn">Сделать ставку</button>
                    <div id="clMsg" class="cl-msg"></div>
                </form>
            <?php endif; ?>

            <?php if (!empty($lot['description'])): ?>
            <div style="margin-top:22px;padding-top:16px;border-top:1px solid #334155;">
                <div style="font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;"><?= $lang === 'en' ? 'Description' : 'Описание' ?></div>
                <div style="color:#e2e8f0;font-size:14px;line-height:1.6;white-space:pre-line;"><?= htmlspecialchars($lot['description']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
(function(){
    const LOT_ID = <?= (int)$lot['id'] ?>;
    let END_MS  = <?= (int)$end_ts ?> * 1000;
    let isOver  = <?= $is_over ? 'true' : 'false' ?>;

    function fmt(n){ return n.toLocaleString('ru-RU'); }

    function tick(){
        const timer = document.getElementById('clTimer');
        if (!timer) return;
        const diff = END_MS - Date.now();
        if (diff <= 0) {
            timer.textContent = '00:00:00';
            timer.style.color = '#ef4444';
            if (!isOver) { isOver = true; setTimeout(()=>location.reload(), 800); }
            return;
        }
        const h = String(Math.floor(diff/3600000)).padStart(2,'0');
        const m = String(Math.floor(diff%3600000/60000)).padStart(2,'0');
        const s = String(Math.floor(diff%60000/1000)).padStart(2,'0');
        timer.textContent = h+':'+m+':'+s;
    }
    setInterval(tick, 1000); tick();

    function sync(){
        fetch('lot_closed.php?ajax=1&id='+LOT_ID+'&t='+Date.now())
            .then(r=>r.json()).then(d=>{
                if (d.error) return;
                /* Обновляем только лучшую цену и счётчик допущенных. */
                const best = document.getElementById('clBest');
                if (best) best.textContent = fmt(d.price) + ' ₽';

                const ac = document.getElementById('approvedCnt');
                if (ac) ac.textContent = d.approved_cnt;

                /* Минимальная ставка — текущая цена + шаг (для допущенных). */
                const inp = document.getElementById('clAmount');
                if (inp) {
                    const min = (d.price + d.bid_step);
                    inp.min = min;
                    if (!inp.dataset.dirty || parseInt(inp.value, 10) < min) {
                        inp.value = min;
                    }
                    const hm = document.getElementById('hintMin');
                    if (hm) hm.textContent = fmt(min);
                }

                if (d.is_over && !isOver) { isOver = true; location.reload(); }
            }).catch(()=>{});
    }
    setInterval(sync, 2000); sync();

    /* Обработка заявки на участие. */
    const applyForm = document.getElementById('applyForm');
    if (applyForm) {
        applyForm.addEventListener('submit', function(e){
            e.preventDefault();
            const btn = document.getElementById('applySubmit');
            const msg = document.getElementById('applyMsg');
            btn.disabled = true; btn.textContent = 'Отправка…';
            msg.className = 'cl-msg'; msg.textContent = '';

            const fd = new FormData(applyForm);
            fetch('apply_closed.php', { method:'POST', body: fd })
                .then(r=>r.json()).then(d=>{
                    if (d.success) {
                        msg.className = 'cl-msg ok';
                        msg.textContent = d.message || 'Заявка подана';
                        setTimeout(()=>location.reload(), 900);
                    } else {
                        msg.className = 'cl-msg err';
                        msg.textContent = d.error || 'Ошибка';
                        btn.disabled = false; btn.textContent = 'Подать заявку';
                    }
                }).catch(()=>{
                    msg.className = 'cl-msg err';
                    msg.textContent = 'Ошибка связи';
                    btn.disabled = false; btn.textContent = 'Подать заявку';
                });
        });
    }

    /* Обработка ставки. */
    const bidForm = document.getElementById('bidForm');
    if (bidForm) {
        const inp = document.getElementById('clAmount');
        if (inp) inp.addEventListener('input', ()=>{ inp.dataset.dirty = '1'; });

        bidForm.addEventListener('submit', function(e){
            e.preventDefault();
            const btn = document.getElementById('clBidBtn');
            const msg = document.getElementById('clMsg');
            btn.disabled = true; btn.textContent = 'Отправка…';
            msg.className = 'cl-msg'; msg.textContent = '';

            const fd = new FormData(bidForm);
            fetch('send_closed_bid.php', { method:'POST', body: fd })
                .then(r=>r.text()).then(text=>{
                    if (text.trim() === 'success') {
                        msg.className = 'cl-msg ok';
                        msg.textContent = 'Ставка принята';
                        if (inp) inp.dataset.dirty = '';
                        sync();
                        setTimeout(()=>{ btn.disabled = false; btn.textContent = 'Сделать ставку'; }, 600);
                    } else {
                        msg.className = 'cl-msg err';
                        msg.textContent = text.trim() || 'Ошибка';
                        btn.disabled = false; btn.textContent = 'Сделать ставку';
                    }
                }).catch(()=>{
                    msg.className = 'cl-msg err';
                    msg.textContent = 'Ошибка связи';
                    btn.disabled = false; btn.textContent = 'Сделать ставку';
                });
        });
    }
})();
</script>

<?php include 'footer.php'; ?>
