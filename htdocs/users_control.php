<?php
require_once __DIR__ . '/admin_only.php';
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db.php';
require_once 'finances.php';
date_default_timezone_set('Europe/Moscow');

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

// Простая проверка — только Admin (id=1) или добавь роль admin в users
$admin_id = (int)$_SESSION['user_id'];
$me = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
$me->execute([$admin_id]);
$admin = $me->fetch(PDO::FETCH_ASSOC);

// ── AJAX-действия ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $action  = trim($_POST['action'] ?? '');
    $user_id = (int)($_POST['user_id'] ?? 0);

    if (!$user_id) die(json_encode(['error' => 'Не указан пользователь']));

    if ($action === 'soft_ban') {
        $reason   = trim($_POST['reason'] ?? 'Нарушение правил');
        $days     = max(1, (int)($_POST['days'] ?? 7));
        $ban_until = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $pdo->prepare("UPDATE users SET ban_type='soft', ban_reason=?, ban_until=? WHERE id=?")
            ->execute([$reason, $ban_until, $user_id]);
        $pdo->prepare("INSERT INTO ban_log (user_id, admin_id, ban_type, reason, ban_until) VALUES (?,?,'soft',?,?)")
            ->execute([$user_id, $admin_id, $reason, $ban_until]);

        echo json_encode(['success' => true, 'msg' => "Мягкий бан на {$days} дн."]);

    } elseif ($action === 'hard_ban') {
        $reason = trim($_POST['reason'] ?? 'Грубое нарушение правил');

        $pdo->prepare("UPDATE users SET ban_type='hard', ban_reason=?, ban_until=NULL WHERE id=?")
            ->execute([$reason, $user_id]);
        $pdo->prepare("INSERT INTO ban_log (user_id, admin_id, ban_type, reason) VALUES (?,?,'hard',?)")
            ->execute([$user_id, $admin_id, $reason]);

        echo json_encode(['success' => true, 'msg' => 'Жёсткий бан применён']);

    } elseif ($action === 'soft_restrict') {
        // Мягкое ограничение ставок (без бана — просто лимит)
        $limit  = isset($_POST['limit']) && $_POST['limit'] !== '' ? (int)$_POST['limit'] : null;
        $window = max(1, (int)($_POST['window'] ?? 44));
        $msg    = trim($_POST['msg'] ?? '') ?: null;

        $pdo->prepare("UPDATE users SET soft_bid_limit=?, soft_bid_window=?, soft_ban_msg=? WHERE id=?")
            ->execute([$limit, $window, $msg, $user_id]);
        $pdo->prepare("INSERT INTO ban_log (user_id, admin_id, ban_type, reason) VALUES (?,?,'soft',?)")
            ->execute([$user_id, $admin_id, 'Лимит ставок: '.($limit ?? 0).' / окно: '.$window.'с']);
        echo json_encode(['success' => true, 'msg' => 'Ограничение применено']);

    } elseif ($action === 'remove_restrict') {
        $pdo->prepare("UPDATE users SET soft_bid_limit=NULL, soft_ban_msg=NULL WHERE id=?")
            ->execute([$user_id]);
        echo json_encode(['success' => true, 'msg' => 'Ограничение снято']);

    } elseif ($action === 'unban') {
        $pdo->prepare("UPDATE users SET ban_type=NULL, ban_reason=NULL, ban_until=NULL WHERE id=?")
            ->execute([$user_id]);
        echo json_encode(['success' => true, 'msg' => 'Бан снят']);

    } elseif ($action === 'set_type') {
        /* Смена типа юзера: respected / responsible / admin.
           Админ НЕ может разадминить сам себя — иначе потеряет доступ. */
        $allowed = ['respected', 'responsible', 'admin'];
        $type = $_POST['user_type'] ?? '';
        if (!in_array($type, $allowed, true)) {
            echo json_encode(['error' => 'Неверный тип']); exit;
        }
        if ($user_id === $admin_id && $type !== 'admin') {
            echo json_encode(['error' => 'Нельзя разадминить самого себя']); exit;
        }
        $pdo->prepare("UPDATE users SET user_type=? WHERE id=?")->execute([$type, $user_id]);
        $labels = ['respected' => '🤝 Уважаемый', 'responsible' => '✅ Ответственный', 'admin' => '👑 Админ'];
        echo json_encode(['success' => true, 'msg' => 'Тип: ' . $labels[$type]]);

    } elseif ($action === 'set_commission') {
        $rate    = max(0, min(50, (float)($_POST['rate'] ?? 5)));
        $lot_id  = (int)($_POST['lot_id_comm'] ?? 0);
        $for_uid = (int)($_POST['for_user_id'] ?? 0);

        $existing = $pdo->prepare(
            "SELECT id FROM commission_settings WHERE " .
            ($lot_id > 0 ? "lot_id=?" : ($for_uid > 0 ? "user_id=? AND lot_id IS NULL" : "user_id IS NULL AND lot_id IS NULL"))
        );
        $existing->execute($lot_id > 0 ? [$lot_id] : ($for_uid > 0 ? [$for_uid] : []));

        if ($existing->fetch()) {
            if ($lot_id > 0) {
                $pdo->prepare("UPDATE commission_settings SET rate_pct=? WHERE lot_id=?")->execute([$rate, $lot_id]);
            } elseif ($for_uid > 0) {
                $pdo->prepare("UPDATE commission_settings SET rate_pct=? WHERE user_id=? AND lot_id IS NULL")->execute([$rate, $for_uid]);
            } else {
                $pdo->prepare("UPDATE commission_settings SET rate_pct=? WHERE user_id IS NULL AND lot_id IS NULL")->execute([$rate]);
            }
        } else {
            $pdo->prepare("INSERT INTO commission_settings (user_id, lot_id, rate_pct) VALUES (?,?,?)")
                ->execute([$for_uid ?: null, $lot_id ?: null, $rate]);
        }
        echo json_encode(['success' => true, 'msg' => "Комиссия {$rate}% сохранена"]);

    } else {
        echo json_encode(['error' => 'Неизвестное действие']);
    }
    exit;
}

// ── HTML ──────────────────────────────────────────────
$users = $pdo->query(
    "SELECT u.id, u.username, u.email, u.user_type, u.balance, u.bid_pack_remaining,
            u.ban_type, u.ban_reason, u.ban_until, u.soft_bid_limit,
            (SELECT COUNT(*) FROM bids b WHERE b.user_id = u.id) AS total_bids
     FROM users u ORDER BY u.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

/*
 * Единое место настройки комиссии оператора для всех типов торгов
 * (аукцион/скандинавский/на понижение/закрытый/котировки/предложения
 * и комиссионные продажи). getCommissionRate() в finances.php сначала
 * ищет override на лот, потом на организатора, и, если их нет,
 * возвращает глобальное значение отсюда (дефолт 5%).
 */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        lot_id INT NULL,
        rate_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
        UNIQUE KEY uniq_global (user_id, lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Seed глобального значения, если его ещё нет.
    $existing = $pdo->query("SELECT id FROM commission_settings WHERE user_id IS NULL AND lot_id IS NULL LIMIT 1")->fetchColumn();
    if (!$existing) {
        $pdo->exec("INSERT INTO commission_settings (user_id, lot_id, rate_pct) VALUES (NULL, NULL, 5.00)");
    }
} catch (Throwable $e) {
    error_log('users_control.commission_settings bootstrap: ' . $e->getMessage());
}

$global_comm = $pdo->query(
    "SELECT rate_pct FROM commission_settings WHERE user_id IS NULL AND lot_id IS NULL LIMIT 1"
)->fetchColumn();
$global_comm = ($global_comm === false || $global_comm === null) ? 5 : (float)$global_comm;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Управление пользователями — ERA ETP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { background:#0f172a; color:#fff; font-family:sans-serif; margin:0; padding:24px 16px; }
        h2 { font-size:20px; margin:0 0 24px; }

        .global-comm {
            background:#1e293b; border:1px solid #334155; border-radius:14px;
            padding:16px 20px; margin-bottom:24px;
            display:flex; align-items:center; gap:16px; flex-wrap:wrap;
        }
        .global-comm label { font-size:13px; color:#94a3b8; }
        .global-comm input { width:80px; padding:8px; border-radius:8px; background:#0f172a; border:1px solid #334155; color:#fff; font-size:15px; text-align:center; }
        .btn { padding:9px 18px; border:none; border-radius:8px; font-weight:bold; cursor:pointer; font-size:13px; transition:background 0.2s; }
        .btn-blue   { background:#3b82f6; color:#fff; }
        .btn-blue:hover { background:#2563eb; }
        .btn-red    { background:#ef4444; color:#fff; }
        .btn-red:hover { background:#dc2626; }
        .btn-orange { background:#f59e0b; color:#000; }
        .btn-orange:hover { background:#d97706; }
        .btn-green  { background:#22c55e; color:#000; }
        .btn-green:hover { background:#16a34a; }
        .btn-gray   { background:#334155; color:#94a3b8; }
        .btn-gray:hover { background:#3d5068; color:#fff; }
        .btn-sm { padding:6px 12px; font-size:12px; }

        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { background:#0f172a; padding:10px 12px; text-align:left; color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:1px; }
        td { padding:10px 12px; border-bottom:1px solid #1e293b; vertical-align:middle; }
        tr:hover td { background:#1a2540; }

        .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:bold; }
        .badge-respected   { background:#1e3a5f; color:#60a5fa; }
        .badge-responsible { background:#14532d; color:#4ade80; }
        .badge-soft-ban    { background:#451a1a; color:#f87171; }
        .badge-hard-ban    { background:#7f1d1d; color:#fca5a5; }
        .badge-admin       { background:#3a2c0a; color:#fbbf24; }
        .status-select     { padding:6px 8px; border-radius:6px; background:#0f172a; color:#fff; border:1px solid #334155; font-size:12px; cursor:pointer; }
        .status-select:focus { border-color:#3b82f6; outline:none; }

        .actions { display:flex; gap:6px; flex-wrap:wrap; }

        /* Модалка бана */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:100; justify-content:center; align-items:center; padding:16px; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:#1e293b; border:1px solid #334155; border-radius:16px; padding:24px; width:100%; max-width:400px; }
        .modal-box h3 { margin:0 0 16px; font-size:16px; }
        .field { width:100%; padding:10px 14px; border-radius:8px; background:#0f172a; border:1px solid #334155; color:#fff; font-size:14px; margin-bottom:10px; outline:none; }
        .field:focus { border-color:#3b82f6; }
        #action-msg { min-height:20px; font-size:13px; font-weight:bold; text-align:center; margin-top:8px; }

        .back-link { display:inline-block; margin-bottom:20px; color:#64748b; font-size:13px; text-decoration:none; }
        .back-link:hover { color:#94a3b8; }
    </style>
</head>
<body>

<a class="back-link" href="reestr.php">← Реестр</a>
<h2>👥 Управление пользователями</h2>

<!-- Глобальная комиссия (для всех типов торгов) -->
<div class="global-comm">
    <label>Глобальная комиссия оператора (для всех типов торгов):</label>
    <input type="number" id="global-rate" value="<?= htmlspecialchars((string)$global_comm) ?>" min="0" max="50" step="0.5">
    <span style="color:#64748b;">%</span>
    <button class="btn btn-blue btn-sm" onclick="setGlobalComm()">Сохранить</button>
    <span id="comm-msg" style="font-size:12px;color:#4ade80;"></span>
</div>
<div style="margin:-16px 0 20px;color:#64748b;font-size:12px;line-height:1.5;">
    Применяется к аукциону, скандинавскому аукциону, аукциону на понижение,
    закрытому аукциону, запросу котировок/предложений и комиссионным продажам.
    По умолчанию 5%. Ставка по конкретному лоту или организатору имеет приоритет
    над этим значением.
</div>

<!-- Таблица пользователей -->
<div style="background:#1e293b;border:1px solid #334155;border-radius:16px;overflow:hidden;">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Логин</th>
                <th>Статус</th>
                <th>Баланс</th>
                <th>Ставок</th>
                <th>Бан</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td style="color:#64748b;"><?= $u['id'] ?></td>
            <td>
                <b><?= htmlspecialchars($u['username']) ?></b>
                <?php if ($u['email']): ?>
                <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($u['email']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $type_labels = [
                    'respected'   => ['🤝 Уважаемый',   'badge-respected'],
                    'responsible' => ['✅ Ответственный', 'badge-responsible'],
                    'admin'       => ['👑 Админ',        'badge-admin'],
                ];
                $tt = $u['user_type'] ?? 'respected';
                [$lbl, $cls] = $type_labels[$tt] ?? $type_labels['respected'];
                ?>
                <span class="badge <?= $cls ?>"><?= $lbl ?></span>
            </td>
            <td>
                <?= number_format((int)$u['balance'], 0, '.', ' ') ?>&nbsp;₽
                <?php if ($u['bid_pack_remaining'] > 0): ?>
                <div style="font-size:11px;color:#f59e0b;">📦 <?= $u['bid_pack_remaining'] ?> ставок</div>
                <?php endif; ?>
            </td>
            <td><?= $u['total_bids'] ?></td>
            <td>
                <?php if ($u['ban_type'] === 'hard'): ?>
                    <span class="badge badge-hard-ban">🔴 Жёсткий</span>
                    <div style="font-size:11px;color:#f87171;margin-top:3px;"><?= htmlspecialchars(mb_substr($u['ban_reason'],0,40)) ?></div>
                <?php elseif ($u['ban_type'] === 'soft'): ?>
                    <span class="badge badge-soft-ban">🟡 Мягкий</span>
                    <div style="font-size:11px;color:#fbbf24;margin-top:3px;">до <?= date('d.m.y', strtotime($u['ban_until'])) ?></div>
                <?php else: ?>
                    <span style="color:#4ade80;font-size:12px;">✓ Активен</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="actions">
                    <!-- Смена статуса юзера прямо из таблицы:
                         respected (Уважаемый) / responsible (Ответственный) / admin (Админ).
                         Серверный set_type не даёт админу разадминить себя. -->
                    <?php $cur_type = $u['user_type'] ?? 'respected'; ?>
                    <select class="status-select" data-uid="<?= $u['id'] ?>" data-was="<?= htmlspecialchars($cur_type) ?>"
                            onchange="setStatus(this)">
                        <option value="respected"   <?= $cur_type === 'respected'   ? 'selected' : '' ?>>🤝 Уважаемый</option>
                        <option value="responsible" <?= $cur_type === 'responsible' ? 'selected' : '' ?>>✅ Ответственный</option>
                        <option value="admin"       <?= $cur_type === 'admin'       ? 'selected' : '' ?>>👑 Админ</option>
                    </select>

                    <?php if ($u['ban_type']): ?>
                    <button class="btn btn-green btn-sm" onclick="doUnban(<?= $u['id'] ?>)">Снять бан</button>
                    <?php else: ?>
                    <button class="btn btn-orange btn-sm" onclick="openBan(<?= $u['id'] ?>,'soft')">Жёсткий бан (срочный)</button>
                    <button class="btn btn-red btn-sm"    onclick="openBan(<?= $u['id'] ?>,'hard')">Заблокировать</button>
                    <?php endif; ?>
                    <button class="btn btn-gray btn-sm"
                        onclick="openRestrict(<?= $u['id'] ?>, <?= isset($u['soft_bid_limit']) ? (int)$u['soft_bid_limit'] : 'null' ?>)">
                        🎯 Лимит ставок
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Модалка бана -->
<div class="modal-overlay" id="ban-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <h3 id="ban-title">Бан пользователя</h3>
        <input type="hidden" id="ban-uid">
        <input type="hidden" id="ban-type-val">
        <textarea class="field" id="ban-reason" rows="3" placeholder="Причина бана..."></textarea>
        <div id="soft-days-row">
            <label style="font-size:12px;color:#64748b;">Срок (дней):</label>
            <input class="field" type="number" id="ban-days" value="7" min="1" max="365">
        </div>
        <div style="display:flex;gap:10px;margin-top:4px;">
            <button class="btn btn-red" style="flex:1;" onclick="submitBan()">Применить</button>
            <button class="btn btn-gray" style="flex:1;" onclick="document.getElementById('ban-modal').classList.remove('open')">Отмена</button>
        </div>
        <div id="action-msg"></div>
    </div>
</div>

<script>
function post(data, cb) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    fetch('users_control.php', {method:'POST', body:fd})
        .then(r=>r.json()).then(cb).catch(()=>cb({error:'Ошибка связи'}));
}

function setGlobalComm() {
    const rate = document.getElementById('global-rate').value;
    post({action:'set_commission', user_id:1, rate, for_user_id:'', lot_id_comm:''}, d => {
        const m = document.getElementById('comm-msg');
        m.textContent = d.success ? '✅ Сохранено' : ('❌ ' + (d.error||d.msg));
        m.style.color = d.success ? '#4ade80' : '#f87171';
    });
}

function setStatus(sel) {
    const uid = sel.dataset.uid;
    const was = sel.dataset.was;
    const now = sel.value;
    if (was === now) return;
    const labels = { respected: 'Уважаемый', responsible: 'Ответственный', admin: 'Админ' };
    if (!confirm(`Сменить статус пользователя на «${labels[now]}»?`)) {
        sel.value = was;
        return;
    }
    post({action:'set_type', user_id:uid, user_type:now}, d => {
        if (d.success) {
            sel.dataset.was = now;
            location.reload();
        } else {
            sel.value = was;
            alert(d.error || d.msg || 'Ошибка');
        }
    });
}

function openBan(uid, type) {
    document.getElementById('ban-uid').value      = uid;
    document.getElementById('ban-type-val').value = type;
    document.getElementById('ban-title').textContent = type === 'hard' ? '🔴 Жёсткий бан' : '🟡 Мягкий бан';
    document.getElementById('soft-days-row').style.display = type === 'soft' ? 'block' : 'none';
    document.getElementById('ban-reason').value   = '';
    document.getElementById('action-msg').textContent = '';
    document.getElementById('ban-modal').classList.add('open');
}

function submitBan() {
    const uid    = document.getElementById('ban-uid').value;
    const type   = document.getElementById('ban-type-val').value;
    const reason = document.getElementById('ban-reason').value.trim();
    const days   = document.getElementById('ban-days').value;
    if (!reason) { document.getElementById('action-msg').textContent = 'Укажите причину'; return; }
    const data = {action: type+'_ban', user_id: uid, reason};
    if (type === 'soft') data.days = days;
    post(data, d => {
        if (d.success) { location.reload(); }
        else { document.getElementById('action-msg').textContent = d.error || d.msg; }
    });
}

function doUnban(uid) {
    if (!confirm('Снять бан?')) return;
    post({action:'unban', user_id:uid}, d => {
        if (d.success) location.reload();
        else alert(d.error);
    });
}
</script>
<!-- Модалка лимита ставок -->
<div class="modal-overlay" id="restrict-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <h3>🎯 Лимит ставок в аукционе</h3>
        <p style="font-size:13px;color:#94a3b8;margin:0 0 14px;">
            Участник сможет сделать не более N ставок в течение первых X секунд торгов.
            После — увидит экран с указанным сообщением.
        </p>
        <input type="hidden" id="restrict-uid">
        <label style="font-size:12px;color:#64748b;">Лимит ставок (0 = ни одной):</label>
        <input class="field" type="number" id="restrict-limit" value="0" min="0" max="100">
        <label style="font-size:12px;color:#64748b;">Окно (сек от начала торгов):</label>
        <input class="field" type="number" id="restrict-window" value="44" min="1">
        <label style="font-size:12px;color:#64748b;">Сообщение пользователю:</label>
        <input class="field" type="text" id="restrict-msg" placeholder="Проверьте соединение с интернетом">
        <div style="display:flex;gap:10px;margin-top:4px;">
            <button class="btn btn-orange" style="flex:1;" onclick="submitRestrict()">Применить</button>
            <button class="btn btn-gray"   style="flex:1;" onclick="removeRestrict()">Снять ограничение</button>
        </div>
        <button class="btn btn-gray" style="width:100%;margin-top:8px;"
                onclick="document.getElementById('restrict-modal').classList.remove('open')">Отмена</button>
        <div id="restrict-msg-out" style="min-height:20px;font-size:13px;font-weight:bold;text-align:center;margin-top:8px;"></div>
    </div>
</div>

<script>
function openRestrict(uid, currentLimit) {
    document.getElementById('restrict-uid').value   = uid;
    document.getElementById('restrict-limit').value = currentLimit !== null ? currentLimit : 0;
    document.getElementById('restrict-window').value = 44;
    document.getElementById('restrict-msg').value   = '';
    document.getElementById('restrict-msg-out').textContent = '';
    document.getElementById('restrict-modal').classList.add('open');
}

function submitRestrict() {
    const uid    = document.getElementById('restrict-uid').value;
    const limit  = document.getElementById('restrict-limit').value;
    const window_ = document.getElementById('restrict-window').value;
    const msg    = document.getElementById('restrict-msg').value;
    post({action:'soft_restrict', user_id:uid, limit, window:window_, msg}, d => {
        const m = document.getElementById('restrict-msg-out');
        m.textContent = d.success ? '✅ ' + d.msg : '❌ ' + (d.error||d.msg);
        m.style.color = d.success ? '#4ade80' : '#f87171';
        if (d.success) setTimeout(() => location.reload(), 1000);
    });
}

function removeRestrict() {
    const uid = document.getElementById('restrict-uid').value;
    post({action:'remove_restrict', user_id:uid}, d => {
        if (d.success) location.reload();
    });
}
</script>
</body>
</html>
