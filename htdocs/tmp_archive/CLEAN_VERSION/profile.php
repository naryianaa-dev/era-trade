<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Поддержка обоих форматов сессии
if (empty($_SESSION['user_id']) && !empty($_SESSION['user_logged'])) {
    // Старая сессия — найдём user_id по username
    require_once 'db.php';
    $s = $pdo->prepare("SELECT id, username, balance, user_type, bid_pack_remaining FROM users WHERE username = ?");
    $s->execute([$_SESSION['user_logged']]);
    $u = $s->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $_SESSION['user_id']   = $u['id'];
        $_SESSION['user_name'] = $u['username'];
    }
}

if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

$user_id = (int)$_SESSION['user_id'];

// Получаем свежие данные из БД
$stmt = $pdo->prepare(
    "SELECT id, username, balance, user_type, bid_pack_remaining, email
     FROM users WHERE id = ?"
);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// История пополнений
$stmt_t = $pdo->prepare(
    "SELECT amount, payment_method, status, created_at
     FROM balance_topups WHERE user_id = ? ORDER BY id DESC LIMIT 8"
);
$stmt_t->execute([$user_id]);
$topups = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

// История ставок
$stmt_b = $pdo->prepare(
    "SELECT b.bid_amount, b.bid_cost, b.payment_method, b.bid_time, l.title
     FROM bids b
     LEFT JOIN lots l ON b.lot_id = l.id
     WHERE b.user_id = ? ORDER BY b.id DESC LIMIT 10"
);
$stmt_b->execute([$user_id]);
$bids_history = $stmt_b->fetchAll(PDO::FETCH_ASSOC);

$type_label  = ['respected' => '🤝 Уважаемый', 'responsible' => '✅ Ответственный'];
$status_icon = ['pending' => '⏳', 'confirmed' => '✅', 'rejected' => '❌'];
$status_color = ['pending' => '#f59e0b', 'confirmed' => '#4ade80', 'rejected' => '#f87171'];
$method_icon  = ['balance' => '💳', 'cash' => '📱🧾', 'pack' => '📦', 'qr' => '📱', 'receipt' => '🧾'];

// QR-строка для СБП
$qr_amount  = 5000; // минимальное пополнение по умолчанию
$qr_content = "ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum={$qr_amount}00|Purpose=Пополнение счета ID{$user_id} {$user['username']}";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет — <?= htmlspecialchars($user['username']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root {
            --accent: #0088cc;
            --bg:     #070b14;
            --card:   rgba(30,41,59,0.6);
            --border: rgba(255,255,255,0.08);
            --dim:    #94a3b8;
        }
        body {
            background: radial-gradient(circle at top right, #1e293b, var(--bg));
            background-attachment: fixed;
            font-family: 'Inter', sans-serif;
            margin: 0; color: #f1f5f9;
            display: flex; min-height: 100vh;
        }

        /* ── Сайдбар ─────────────────────────────── */
        .sidebar {
            width: 240px; background: #0f172a; padding: 28px 16px;
            display: flex; flex-direction: column;
            height: 100vh; position: fixed; left: 0; top: 0;
            border-right: 1px solid var(--border);
        }
        .logo {
            display: flex; align-items: center; gap: 10px;
            font-weight: 800; font-size: 20px; color: #fff;
            text-decoration: none; margin-bottom: 40px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; color: var(--dim);
            text-decoration: none; border-radius: 10px;
            margin-bottom: 4px; font-weight: 500; font-size: 14px;
            transition: background 0.2s, color 0.2s;
        }
        .nav-item:hover, .nav-item.active {
            background: rgba(0,136,204,0.12); color: var(--accent);
        }
        .nav-item.danger { color: #ef4444; margin-top: auto; }
        .nav-item.danger:hover { background: rgba(239,68,68,0.1); }

        @media (max-width: 900px) {
            .sidebar { width: 64px; padding: 20px 8px; }
            .sidebar .label, .logo span { display: none; }
            .main { margin-left: 64px; padding: 20px 16px; }
        }

        /* ── Контент ─────────────────────────────── */
        .main { margin-left: 240px; flex: 1; padding: 36px 40px; }

        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 32px;
        }
        .topbar h2 { margin: 0; font-size: 22px; }
        .online-badge {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 16px;
            font-size: 13px; display: flex; align-items: center; gap: 6px;
        }
        .dot-green { width: 7px; height: 7px; background: #22c55e; border-radius: 50%; }

        /* ── Карточки ─────────────────────────────── */
        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 20px; padding: 28px;
            backdrop-filter: blur(12px);
        }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-bottom: 24px; }

        @media (max-width: 1100px) { .grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 700px)  { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

        /* Баланс */
        .balance-card { background: linear-gradient(135deg, #1e3a5f, #0f172a); border: 1px solid #3b82f6; border-radius: 20px; padding: 28px; }
        .bal-label { font-size: 11px; color: var(--dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .bal-val   { font-size: 48px; font-weight: 800; color: #4ade80; line-height: 1; }
        .bal-sub   { font-size: 13px; color: var(--dim); margin-top: 6px; }

        /* Статус */
        .stat-card { border-radius: 16px; padding: 20px; background: var(--card); border: 1px solid var(--border); }
        .stat-label { font-size: 11px; color: var(--dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .stat-val   { font-size: 22px; font-weight: 800; }

        /* Кнопки */
        .btn {
            padding: 14px 24px; border: none; border-radius: 12px;
            font-weight: 700; cursor: pointer; font-size: 14px;
            transition: background 0.2s, transform 0.1s;
        }
        .btn:hover:not(:disabled) { transform: translateY(-1px); }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #0077b3; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--dim); }
        .btn-outline:hover { border-color: #64748b; color: #fff; }

        /* Таблица истории */
        .history-row {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid var(--border);
            font-size: 13px;
        }
        .history-row:last-child { border-bottom: none; }
        .hr-icon { font-size: 20px; width: 32px; text-align: center; flex-shrink: 0; }
        .hr-main { flex: 1; }
        .hr-title { font-weight: 600; color: #f1f5f9; }
        .hr-sub   { font-size: 12px; color: var(--dim); margin-top: 2px; }
        .hr-amount { font-weight: 800; white-space: nowrap; }

        /* Пополнение */
        .amounts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .amt-btn {
            padding: 10px 16px; border: 1.5px solid var(--border); border-radius: 10px;
            background: #0f172a; color: #fff; font-weight: 700; font-size: 14px;
            cursor: pointer; transition: border-color 0.2s, background 0.2s;
        }
        .amt-btn:hover    { border-color: var(--accent); }
        .amt-btn.selected { border-color: var(--accent); background: #1e3a5f; color: #60a5fa; }

        .field {
            width: 100%; padding: 12px 16px; border-radius: 10px;
            background: #0f172a; border: 1.5px solid var(--border);
            color: #fff; font-size: 15px; margin-bottom: 12px; outline: none;
        }
        .field:focus { border-color: var(--accent); }

        /* Модалка QR */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.85); z-index: 9999;
            justify-content: center; align-items: center;
            backdrop-filter: blur(6px); padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff; color: #000;
            border-radius: 24px; padding: 36px;
            width: 100%; max-width: 400px; text-align: center;
        }
        .modal-box h3 { margin: 0 0 8px; font-size: 20px; }
        .modal-box .qr-wrap { margin: 20px auto; }
        .modal-close {
            width: 100%; padding: 14px; margin-top: 16px;
            background: #f1f5f9; border: none; border-radius: 12px;
            font-weight: 700; cursor: pointer; font-size: 14px;
        }

        #topup-msg { min-height: 20px; font-size: 13px; font-weight: bold; margin-bottom: 8px; }
    </style>
</head>
<body>

<!-- ── Сайдбар ──────────────────────────────────────── -->
<aside class="sidebar">
    <a href="index.php" class="logo">
        <i data-lucide="zap" style="color:var(--accent)"></i>
        <span>ERA ETP</span>
    </a>
    <nav>
        <a href="profile.php" class="nav-item active">
            <i data-lucide="layout-dashboard"></i> <span class="label">Кабинет</span>
        </a>
        <a href="reestr.php" class="nav-item">
            <i data-lucide="gavel"></i> <span class="label">Торги</span>
        </a>
        <a href="lot_scandinavian.php?id=6" class="nav-item">
            <i data-lucide="flame"></i> <span class="label">Скандинавский</span>
        </a>
        <a href="#" class="nav-item">
            <i data-lucide="file-text"></i> <span class="label">Документы</span>
        </a>
    </nav>
    <a href="logout.php" class="nav-item danger" style="margin-top:auto;">
        <i data-lucide="log-out"></i> <span class="label">Выйти</span>
    </a>
</aside>

<!-- ── Основной контент ──────────────────────────────── -->
<main class="main">

    <div class="topbar">
        <div>
            <h2>Добро пожаловать, <?= htmlspecialchars($user['username']) ?>!</h2>
            <p style="color:var(--dim);margin:4px 0 0;font-size:14px;">
                <?= $type_label[$user['user_type']] ?? '🤝 Уважаемый' ?>
                &nbsp;·&nbsp; <?= date('d.m.Y') ?>
            </p>
        </div>
        <div class="online-badge">
            <span class="dot-green"></span> Онлайн
        </div>
    </div>

    <!-- Баланс + статистика -->
    <div class="grid-2" style="margin-bottom:24px;">
        <div class="balance-card">
            <div class="bal-label">Баланс личного кабинета</div>
            <div class="bal-val" id="balance-display">
                <?= number_format((int)$user['balance'], 0, '.', "\u{00A0}") ?>&nbsp;₽
            </div>
            <div class="bal-sub">
                Пакет ставок: <b style="color:#f59e0b;"><?= (int)$user['bid_pack_remaining'] ?> шт.</b>
            </div>
        </div>
        <div class="card" style="display:flex;flex-direction:column;gap:16px;">
            <div class="stat-card">
                <div class="stat-label">Статус</div>
                <div class="stat-val"><?= $type_label[$user['user_type']] ?? '🤝 Уважаемый' ?></div>
            </div>
            
            <!-- Кнопка повышения статуса -->
            <button onclick="openUpgradeModal()" 
                    style="width:100%;padding:14px 24px;background:linear-gradient(135deg,#f59e0b,#ef4444);
                           color:#fff;border:none;border-radius:12px;font-weight:700;
                           cursor:pointer;font-size:14px;transition:all 0.2s;">
                ⬆️ Повысить статус
            </button>
            
            <div class="stat-card">
                <div class="stat-label">Сделано ставок всего</div>
                <div class="stat-val"><?= count($bids_history) ?>+</div>
            </div>
        </div>
    </div>

    <!-- Пополнение баланса -->
    <div class="card" style="margin-bottom:24px;">
        <h3 style="margin:0 0 20px;">💰 Пополнение баланса</h3>

        <div class="amounts" id="amounts-row">
            <?php foreach ([1000,3000,5000,10000,25000,50000] as $a): ?>
            <button class="amt-btn" onclick="selectAmt(<?= $a ?>)">
                <?= number_format($a, 0, '.', "\u{00A0}") ?>&nbsp;₽
            </button>
            <?php endforeach; ?>
        </div>

        <input class="field" type="number" id="custom-amount"
               placeholder="Или введите сумму" min="100" step="100"
               oninput="deselectAmts()" style="max-width:300px;">

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">
            <button class="btn btn-primary" onclick="topupQR()">
                📱 Оплатить по QR / СБП
            </button>
            <button class="btn btn-outline" onclick="topupReceipt()">
                🧾 Получить квитанцию
            </button>
        </div>
        <div id="topup-msg" style="margin-top:10px;"></div>
    </div>

    <div class="grid-2">
        <!-- История пополнений -->
        <div class="card">
            <h3 style="margin:0 0 16px;">История пополнений</h3>
            <?php if (empty($topups)): ?>
                <div style="color:var(--dim);font-size:14px;">Пополнений пока нет</div>
            <?php else: ?>
                <?php foreach ($topups as $t): ?>
                <div class="history-row">
                    <div class="hr-icon"><?= $status_icon[$t['status']] ?? '❓' ?></div>
                    <div class="hr-main">
                        <div class="hr-title"><?= $t['payment_method'] === 'qr' ? '📱 QR / СБП' : '🧾 Квитанция' ?></div>
                        <div class="hr-sub"><?= date('d.m.y H:i', strtotime($t['created_at'])) ?></div>
                    </div>
                    <div class="hr-amount" style="color:<?= $status_color[$t['status']] ?? '#fff' ?>">
                        +<?= number_format((int)$t['amount'], 0, '.', "\u{00A0}") ?>&nbsp;₽
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- История ставок -->
        <div class="card">
            <h3 style="margin:0 0 16px;">Последние ставки</h3>
            <?php if (empty($bids_history)): ?>
                <div style="color:var(--dim);font-size:14px;">Ставок пока нет</div>
            <?php else: ?>
                <?php foreach ($bids_history as $b): ?>
                <div class="history-row">
                    <div class="hr-icon"><?= $method_icon[$b['payment_method']] ?? '💸' ?></div>
                    <div class="hr-main">
                        <div class="hr-title"><?= htmlspecialchars($b['title'] ?? 'Лот') ?></div>
                        <div class="hr-sub">
                            Ставка: <?= number_format((int)$b['bid_amount'], 0, '.', "\u{00A0}") ?>&nbsp;₽
                            &nbsp;·&nbsp;
                            <?= $b['bid_time'] ? date('d.m.y H:i', strtotime($b['bid_time'])) : '' ?>
                        </div>
                    </div>
                    <div class="hr-amount" style="color:#f87171;">
                        −<?= number_format((int)$b['bid_cost'], 0, '.', "\u{00A0}") ?>&nbsp;₽
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- ── Модалка QR ─────────────────────────────────────── -->
<div class="modal-overlay" id="modal-qr" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <h3>📱 Оплата по QR-коду / СБП</h3>
        <p style="color:#666;font-size:13px;margin:0 0 4px;">Получатель: <b>ООО «Форсаж»</b></p>
        <p style="color:#666;font-size:13px;margin:0 0 0;">Сумма: <b id="qr-sum-label" style="color:#0088cc;"></b></p>
        <div class="qr-wrap">
            <img id="qr-img" src="" style="width:220px;height:220px;border-radius:12px;">
        </div>
        <p style="font-size:12px;color:#888;margin:0 0 4px;">
            Назначение: <b id="qr-purpose"></b>
        </p>
        <p style="font-size:12px;color:#888;">
            После оплаты нажмите «Я оплатил» — администратор подтвердит зачисление.
        </p>
        <button class="btn btn-primary" style="width:100%;padding:14px;font-size:15px;" onclick="confirmTopup()">
            ✅ Я оплатил
        </button>
        <button class="modal-close" onclick="document.getElementById('modal-qr').classList.remove('open')">
            Закрыть
        </button>
    </div>
</div>

<!-- ── Модалка квитанция ──────────────────────────────── -->
<div class="modal-overlay" id="modal-receipt" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <h3>🧾 Реквизиты для перевода</h3>
        <div style="background:#f8fafc;border-radius:12px;padding:16px;text-align:left;
                    font-size:13px;line-height:2.2;margin:16px 0;">
            <div>Получатель: <b>ООО «Форсаж»</b></div>
            <div>ИНН: <b>7728282160</b></div>
            <div>Банк: <b>ООО Банк Точка</b></div>
            <div>БИК: <b>044525104</b></div>
            <div>Счёт: <b>40702810101500033019</b></div>
            <div>Назначение: <b id="rec-purpose"></b></div>
            <div>Сумма: <b id="rec-sum" style="color:#0088cc;font-size:16px;"></b></div>
        </div>
        <button class="btn btn-primary" style="width:100%;padding:14px;font-size:15px;" onclick="confirmTopup()">
            ✅ Отправил платёж
        </button>
        <button class="modal-close" onclick="document.getElementById('modal-receipt').classList.remove('open')">
            Закрыть
        </button>
    </div>
</div>

<script>
lucide.createIcons();

const USER_ID  = <?= (int)$user_id ?>;
const USERNAME = '<?= addslashes($user['username']) ?>';

let selectedAmt    = 0;
let currentMethod  = 'qr';
const AMOUNTS_LIST = [1000,3000,5000,10000,25000,50000];

function selectAmt(val) {
    selectedAmt = val;
    document.getElementById('custom-amount').value = '';
    document.querySelectorAll('.amt-btn').forEach((b, i) => {
        b.classList.toggle('selected', AMOUNTS_LIST[i] === val);
    });
}
function deselectAmts() {
    selectedAmt = 0;
    document.querySelectorAll('.amt-btn').forEach(b => b.classList.remove('selected'));
}

function getAmount() {
    const custom = parseInt(document.getElementById('custom-amount').value, 10);
    return selectedAmt || custom || 0;
}

function setMsg(text, color) {
    const m = document.getElementById('topup-msg');
    m.textContent = text; m.style.color = color || '#ef4444';
}

function topupQR() {
    const amount = getAmount();
    if (!amount || amount < 100) { setMsg('Укажите сумму от 100 ₽'); return; }
    currentMethod = 'qr';
    createTopup(amount, 'qr');
}

function topupReceipt() {
    const amount = getAmount();
    if (!amount || amount < 100) { setMsg('Укажите сумму от 100 ₽'); return; }
    currentMethod = 'receipt';
    createTopup(amount, 'receipt');
}

function createTopup(amount, method) {
    setMsg('', '');
    const fd = new FormData();
    fd.append('action',         'topup');
    fd.append('amount',         amount);
    fd.append('payment_method', method);

    fetch('topup_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showPayModal(amount, method);
            } else {
                setMsg(d.msg || 'Ошибка');
            }
        })
        .catch(() => setMsg('Ошибка связи'));
}

function showPayModal(amount, method) {
    const purpose = `Пополнение счета ID${USER_ID} ${USERNAME}`;
    const amtFmt  = amount.toLocaleString('ru-RU') + ' ₽';

    if (method === 'qr') {
        const qrData = `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${amount}00|Purpose=${purpose}`;
        document.getElementById('qr-img').src      = 'https://api.qrserver.com/v1/create-qr-code/?size=440x440&data=' + encodeURIComponent(qrData);
        document.getElementById('qr-sum-label').textContent = amtFmt;
        document.getElementById('qr-purpose').textContent   = purpose;
        document.getElementById('modal-qr').classList.add('open');
    } else {
        document.getElementById('rec-sum').textContent     = amtFmt;
        document.getElementById('rec-purpose').textContent = purpose;
        document.getElementById('modal-receipt').classList.add('open');
    }
}

function confirmTopup() {
    // Уведомление в Telegram
    const token   = 'AAF-7oKGc6x37WC-vZc7NMJ9dySnaYDjRm8';
    const chat_id = '<?= addslashes($_SESSION['user_id'] ?? '') ?>';
    const amount  = getAmount();
    const msg     = `💰 ЗАЯВКА НА ПОПОЛНЕНИЕ\nПользователь: ${USERNAME} (ID${USER_ID})\nСумма: ${amount?.toLocaleString('ru-RU')} ₽\nСпособ: ${currentMethod === 'qr' ? 'QR/СБП' : 'Квитанция'}`;

    fetch(`https://api.telegram.org/bot${token}/sendMessage?chat_id=257524397&text=${encodeURIComponent(msg)}`, { mode: 'no-cors' })
        .catch(() => {});

    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
    setMsg('✅ Заявка отправлена! Баланс будет зачислен после подтверждения.', '#4ade80');
}
</script>

<?php include 'upgrade_status_modal.php'; ?>

</body>
</html>
