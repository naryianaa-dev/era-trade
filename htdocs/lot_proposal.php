<?php
/**
 * lot_proposal.php — страница запроса предложений (продажа товара,
 * выигрывает максимальная цена). Участник может подать и менять
 * своё предложение до дедлайна.
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
        $stmt = $pdo->prepare("SELECT id, title, start_price, extra_params, owner_id FROM lots WHERE id = ? AND auction_type = 'proposal'");
        $stmt->execute([$id]);
        $lot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lot) { echo json_encode(['error' => 'not_found']); exit; }

        $extra = json_decode($lot['extra_params'] ?? '{}', true) ?: [];
        $deadline_ts = !empty($extra['proposal_deadline']) ? strtotime($extra['proposal_deadline']) : 0;
        $is_over = $deadline_ts > 0 && $deadline_ts <= time();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM lot_offers WHERE lot_id = ? AND offer_type = 'proposal'");
        $countStmt->execute([$id]);
        $offers_count = (int)$countStmt->fetchColumn();

        $my_offer = null;
        if ($user_id > 0) {
            $stmt = $pdo->prepare("SELECT price, comment, updated_at FROM lot_offers WHERE lot_id = ? AND user_id = ? AND offer_type = 'proposal'");
            $stmt->execute([$id, $user_id]);
            $my_offer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $winner = null;
        if ($is_over) {
            $stmt = $pdo->prepare("
                SELECT lo.price, lo.comment, u.username
                FROM lot_offers lo
                JOIN users u ON u.id = lo.user_id
                WHERE lo.lot_id = ? AND lo.offer_type = 'proposal'
                ORDER BY lo.price DESC, lo.updated_at ASC
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $winner = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        echo json_encode([
            'deadline_ms'  => $deadline_ts * 1000,
            'server_ts'    => time() * 1000,
            'is_over'      => $is_over,
            'offers_count' => $offers_count,
            'my_offer'     => $my_offer,
            'winner'       => $winner,
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
        WHERE l.id = ? AND l.auction_type = 'proposal'
    ");
    $stmt->execute([$id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lot) { http_response_code(404); die('Лот не найден.'); }
} catch (Exception $e) {
    http_response_code(500); die('Ошибка БД');
}

$extra = json_decode($lot['extra_params'] ?? '{}', true) ?: [];
$deadline_ts = !empty($extra['proposal_deadline']) ? strtotime($extra['proposal_deadline']) : 0;
$is_over     = $deadline_ts > 0 && $deadline_ts <= time();
$is_owner    = $user_id > 0 && (int)$lot['owner_id'] === $user_id;

$my_offer = null;
if ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT price, comment, updated_at FROM lot_offers WHERE lot_id = ? AND user_id = ? AND offer_type = 'proposal'");
    $stmt->execute([$id, $user_id]);
    $my_offer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM lot_offers WHERE lot_id = ? AND offer_type = 'proposal'");
$count_stmt->execute([$id]);
$offers_count = (int)$count_stmt->fetchColumn();

$winner = null;
if ($is_over) {
    $stmt = $pdo->prepare("
        SELECT lo.price, lo.comment, u.username
        FROM lot_offers lo
        JOIN users u ON u.id = lo.user_id
        WHERE lo.lot_id = ? AND lo.offer_type = 'proposal'
        ORDER BY lo.price DESC, lo.updated_at ASC
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $winner = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

include 'header.php';
?>
<style>
.proposal-wrap{max-width:780px;margin:0 auto;padding:24px 16px;}
.p-card{background:#fff;border-radius:20px;box-shadow:0 4px 16px rgba(15,23,42,.08);overflow:hidden;}
.p-header{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:22px 26px;}
.p-badge{display:inline-flex;align-items:center;gap:6px;background:#f59e0b;color:#0f172a;font-size:11px;font-weight:700;padding:5px 10px;border-radius:999px;letter-spacing:.5px;text-transform:uppercase;}
.p-title{font-size:22px;font-weight:800;margin:10px 0 6px;}
.p-sub{color:#94a3b8;font-size:13px;}
.p-body{padding:24px 26px;}
.p-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #e2e8f0;font-size:14px;}
.p-row:last-of-type{border-bottom:none;}
.p-row .lbl{color:#64748b;}
.p-row .val{color:#0f172a;font-weight:700;}
.p-timer{font-family:monospace;font-size:36px;font-weight:900;text-align:center;color:#0f172a;margin:18px 0 6px;}
.p-deadline{text-align:center;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:1px;}
.p-form{margin-top:22px;background:#fff7ed;border:1.5px solid #fed7aa;border-radius:14px;padding:18px;}
.p-form h3{font-size:16px;color:#0f172a;margin:0 0 12px;}
.p-form label{display:block;font-size:12px;font-weight:600;color:#334155;margin:10px 0 4px;}
.p-form input,.p-form textarea{width:100%;padding:12px 14px;border:1.5px solid #fed7aa;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;}
.p-form input:focus,.p-form textarea:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.15);}
.p-form .hint{font-size:11px;color:#92400e;margin-top:4px;}
.p-form button{width:100%;margin-top:14px;background:#f59e0b;color:#0f172a;border:0;border-radius:12px;padding:13px;font-size:15px;font-weight:800;cursor:pointer;}
.p-form button:disabled{opacity:.6;cursor:not-allowed;}
.p-msg{margin-top:10px;padding:10px 12px;border-radius:10px;font-size:13px;display:none;}
.p-msg.ok{background:#dcfce7;color:#166534;display:block;}
.p-msg.err{background:#fee2e2;color:#991b1b;display:block;}
.p-finished{background:#fffbeb;border:1.5px solid #fde68a;color:#92400e;border-radius:14px;padding:16px;margin-top:18px;text-align:center;}
.p-finished b{display:block;font-size:22px;color:#0f172a;margin:6px 0;}
.p-info{background:#eff6ff;border:1.5px solid #bfdbfe;color:#1e40af;border-radius:14px;padding:14px;margin-top:14px;font-size:13px;}
.p-back{display:inline-block;margin-bottom:14px;color:#64748b;font-size:13px;text-decoration:none;}
.p-back:hover{color:#0f172a;}
@media(max-width:640px){
    .proposal-wrap{padding:14px 10px;}
    .p-header,.p-body{padding:18px;}
    .p-title{font-size:18px;}
    .p-timer{font-size:28px;}
}
</style>

<main class="proposal-wrap">
    <a href="reestr.php" class="p-back"><?= $lang === 'en' ? '← To registry' : '← К реестру' ?></a>
    <div class="p-card">
        <div class="p-header">
            <span class="p-badge">📨 Запрос предложений</span>
            <div class="p-title"><?= htmlspecialchars($lot['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="p-sub"><?= $lang === 'en' ? 'Lot №' : 'Лот №' ?><?= (int)$lot['id'] ?> · <?= $lang === 'en' ? 'Winner' : 'Победитель' ?> — наибольшая цена</div>
        </div>
        <div class="p-body">
            <div class="p-row">
                <span class="lbl"><?= $lang === 'en' ? 'Starting (minimum) price' : 'Начальная (минимальная) цена' ?></span>
                <span class="val"><?= number_format((float)$lot['start_price'], 0, '.', ' ') ?> ₽</span>
            </div>
            <div class="p-row">
                <span class="lbl"><?= $lang === 'en' ? 'Offers submitted' : 'Подано предложений' ?></span>
                <span class="val" id="offersCount"><?= $offers_count ?></span>
            </div>

            <?php if ($deadline_ts): ?>
            <div class="p-deadline"><?= $lang === 'en' ? 'Time left for submissions' : 'До окончания приёма предложений' ?></div>
            <div class="p-timer" id="pTimer">--:--:--</div>
            <div style="text-align:center;font-size:12px;color:#94a3b8;">
                <?= $lang === 'en' ? 'Deadline:' : 'Дедлайн:' ?> <?= date('d.m.Y H:i', $deadline_ts) ?>
            </div>
            <?php endif; ?>

            <?php if ($is_over): ?>
                <?php if ($winner): ?>
                <div class="p-finished">
                    <span style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#92400e;">Победитель</span>
                    <b><?= number_format((float)$winner['price'], 0, '.', ' ') ?> ₽</b>
                    <div style="color:#475569;"><?= $lang === 'en' ? 'Bidder:' : 'Участник:' ?> <?= htmlspecialchars($winner['username']) ?></div>
                </div>
                <?php else: ?>
                <div class="p-finished"><?= $lang === 'en' ? 'Submission deadline passed; no offers were received.' : 'Срок подачи истёк, предложений не поступило.' ?></div>
                <?php endif; ?>
            <?php elseif ($is_owner): ?>
                <div class="p-info">Вы являетесь владельцем лота — приём предложений идёт. Имена участников и цены будут раскрыты после дедлайна.</div>
            <?php elseif ($user_id <= 0): ?>
                <div class="p-info"><?= $lang === 'en' ? 'To submit an offer,' : 'Чтобы подать предложение,' ?> <a href="login.php" style="color:#1e40af;text-decoration:underline;"><?= $lang === 'en' ? 'sign in' : 'войдите в аккаунт' ?></a>.</div>
            <?php else: ?>
                <form id="pForm" class="p-form" autocomplete="off">
                    <h3><?= $my_offer ? 'Изменить ваше предложение' : 'Подать предложение' ?></h3>
                    <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
                    <label><?= $lang === 'en' ? 'Your price (₽)' : 'Ваша цена (₽)' ?> <span style="color:#ef4444;">*</span></label>
                    <input type="number" name="price" id="pPrice" step="1" min="<?= (int)$lot['start_price'] ?>"
                           value="<?= $my_offer ? (int)$my_offer['price'] : (int)$lot['start_price'] ?>" required>
                    <div class="hint"><?= $lang === 'en' ? 'Price must be at least the starting price (' : 'Цена должна быть не ниже начальной (' ?><?= number_format((float)$lot['start_price'],0,'.',' ') ?> <?= $lang === 'en' ? '₽). The bidder with the highest price wins.' : '₽). Победителем станет участник с наибольшей ценой.' ?></div>
                    <label><?= $lang === 'en' ? 'Comment' : 'Комментарий' ?></label>
                    <textarea name="comment" rows="3" placeholder="Условия оплаты, сроки и т. п."><?= $my_offer ? htmlspecialchars($my_offer['comment'] ?? '') : '' ?></textarea>
                    <button type="submit" id="pSubmit"><?= $my_offer ? 'Сохранить изменение' : 'Отправить предложение' ?></button>
                    <div id="pMsg" class="p-msg"></div>
                    <?php if ($my_offer): ?>
                    <div class="hint" style="margin-top:8px;">
                        <?= $lang === 'en' ? 'Your current offer:' : 'Текущее предложение:' ?> <b><?= number_format((float)$my_offer['price'], 0, '.', ' ') ?> ₽</b>,
                        <?= $lang === 'en' ? 'updated' : 'обновлено' ?> <?= date('d.m.Y H:i', strtotime($my_offer['updated_at'])) ?>.
                        <?= $lang === 'en' ? 'You can revise it until the deadline.' : 'Вы можете изменять его до дедлайна.' ?>
                    </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if (!empty($lot['description'])): ?>
            <div style="margin-top:22px;padding-top:16px;border-top:1px solid #e2e8f0;">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;"><?= $lang === 'en' ? 'Description' : 'Описание' ?></div>
                <div style="color:#0f172a;font-size:14px;line-height:1.6;white-space:pre-line;"><?= htmlspecialchars($lot['description']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
(function(){
    const DEADLINE_MS = <?= (int)$deadline_ts ?> * 1000;
    let isOver = <?= $is_over ? 'true' : 'false' ?>;

    function tick(){
        const timer = document.getElementById('pTimer');
        if (!timer || !DEADLINE_MS) return;
        const diff = DEADLINE_MS - Date.now();
        if (diff <= 0) {
            timer.textContent = '00:00:00';
            timer.style.color = '#ef4444';
            return;
        }
        const h = String(Math.floor(diff/3600000)).padStart(2,'0');
        const m = String(Math.floor(diff%3600000/60000)).padStart(2,'0');
        const s = String(Math.floor(diff%60000/1000)).padStart(2,'0');
        timer.textContent = h+':'+m+':'+s;
    }
    setInterval(tick, 1000); tick();

    function sync(){
        fetch('lot_proposal.php?ajax=1&id=<?= (int)$lot['id'] ?>&t='+Date.now())
            .then(r=>r.json()).then(d=>{
                if (d.error) return;
                const oc = document.getElementById('offersCount');
                if (oc) oc.textContent = d.offers_count;
                if (d.is_over && !isOver) {
                    isOver = true;
                    location.reload();
                }
            }).catch(()=>{});
    }
    setInterval(sync, 5000);

    const form = document.getElementById('pForm');
    if (form) {
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const btn = document.getElementById('pSubmit');
            const msg = document.getElementById('pMsg');
            btn.disabled = true; btn.textContent = 'Отправка…';
            msg.className = 'p-msg'; msg.textContent = '';

            const fd = new FormData(form);
            fetch('send_proposal_offer.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        msg.className = 'p-msg ok';
                        msg.textContent = d.message || 'Предложение сохранено';
                        setTimeout(()=>location.reload(), 900);
                    } else {
                        msg.className = 'p-msg err';
                        msg.textContent = d.error || 'Ошибка отправки';
                        btn.disabled = false;
                        btn.textContent = '<?= $my_offer ? "Сохранить изменение" : "Отправить предложение" ?>';
                    }
                })
                .catch(()=>{
                    msg.className = 'p-msg err';
                    msg.textContent = 'Ошибка связи';
                    btn.disabled = false;
                    btn.textContent = '<?= $my_offer ? "Сохранить изменение" : "Отправить предложение" ?>';
                });
        });
    }
})();
</script>

<?php include 'footer.php'; ?>
