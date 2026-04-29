<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) && !empty($_SESSION['user_logged'])) {
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

// Загрузка квитанции для повышения статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upgrade_status_receipt') {
    $uid     = (int)($_SESSION['user_id'] ?? 0);
    $amount  = (float)($_POST['amount'] ?? 0);
    $tariff  = trim($_POST['tariff'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    $file_path = '';

    if ($uid <= 0 || $amount <= 0 || $tariff === '') {
        $_SESSION['profile_msg'] = 'Ошибка: не указаны сумма или тариф.';
        header('Location: profile.php');
        exit;
    }

    if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/receipts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if ($_FILES['receipt_file']['size'] > 5 * 1024 * 1024) {
            $_SESSION['profile_msg'] = 'Файл должен быть не более 5 МБ.';
            header('Location: profile.php');
            exit;
        }

        $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','pdf'];
        if (!in_array($ext, $allowed, true)) {
            $_SESSION['profile_msg'] = 'Разрешены файлы JPG, PNG, PDF.';
            header('Location: profile.php');
            exit;
        }

        $filename = 'status_receipt_'.$uid.'_'.time().'.'.$ext;
        $target   = $upload_dir.$filename;
        if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $target)) {
            $file_path = $target;
        }
    }

    if (!$file_path) {
        $_SESSION['profile_msg'] = 'Не удалось загрузить файл квитанции.';
        header('Location: profile.php');
        exit;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_receipts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED DEFAULT NULL,
        amount DECIMAL(15,2) NOT NULL,
        tariff VARCHAR(100) NOT NULL,
        comment TEXT,
        file_path VARCHAR(500) NOT NULL,
        status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (lot_id), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $stmt = $pdo->prepare("INSERT INTO payment_receipts
            (user_id, lot_id, amount, tariff, comment, file_path, status, created_at)
            VALUES (?, NULL, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$uid, $amount, $tariff, $comment, $file_path]);
        $_SESSION['profile_msg'] = 'Квитанция загружена и отправлена на проверку.';
    } catch (Exception $e) {
        error_log('upgrade_status_receipt: '.$e->getMessage());
        $_SESSION['profile_msg'] = 'Ошибка при сохранении квитанции.';
    }

    header('Location: profile.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

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

$profile_msg = $_SESSION['profile_msg'] ?? null;
if ($profile_msg !== null) unset($_SESSION['profile_msg']);

// ИСПРАВЛЕНО: убраны алиасы
$stmt_t = $pdo->prepare(
    "SELECT amount, payment_method, status, created_at
     FROM balance_topups WHERE user_id = ? ORDER BY id DESC LIMIT 8"
);
$stmt_t->execute([$user_id]);
$topups = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

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
$method_icon = ['balance' => '💳', 'cash' => '📱🧾', 'pack' => '📦', 'qr' => '📱', 'receipt' => '🧾'];

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
        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 20px; padding: 28px;
            backdrop-filter: blur(12px);
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        @media (max-width: 700px)  { .grid-2 { grid-template-columns: 1fr; } }
        .balance-card { background: linear-gradient(135deg, #1e3a5f, #0f172a); border: 1px solid #3b82f6; border-radius: 20px; padding: 28px; }
        .bal-label { font-size: 11px; color: var(--dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .bal-val   { font-size: 48px; font-weight: 800; color: #4ade80; line-height: 1; }
        .bal-sub   { font-size: 13px; color: var(--dim); margin-top: 6px; }
        .stat-card { border-radius: 16px; padding: 20px; background: var(--card); border: 1px solid var(--border); }
        .stat-label { font-size: 11px; color: var(--dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .stat-val   { font-size: 22px; font-weight: 800; }
        .btn {
            padding: 14px 24px; border: none; border-radius: 12px;
            font-weight: 700; cursor: pointer; font-size: 14px;
            transition: background 0.2s, transform 0.1s;
        }
        .btn:hover:not(:disabled) { transform: translateY(-1px); }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--dim); }
        .btn-outline:hover { border-color: #64748b; color: #fff; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
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
        .amounts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .amt-btn {
            padding: 10px 16px; border: 1.5px solid var(--border); border-radius: 10px;
            background: #0f172a; color: #fff; font-weight: 700; font-size: 14px;
            cursor: pointer; transition: border-color 0.2s, background 0.2s;
        }
        .amt-btn.selected { border-color: var(--accent); background: #1e3a5f; color: #60a5fa; }
        .field {
            width: 100%; padding: 12px 16px; border-radius: 10px;
            background: #0f172a; border: 1.5px solid var(--border);
            color: #fff; font-size: 15px; margin-bottom: 12px; outline: none;
        }
        .modal-overlay {
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.85);
            z-index:9999;
            justify-content:center;
            align-items:center;
            backdrop-filter:blur(6px);
            padding:16px;
        }
        .modal-overlay.open {
            display:flex;
        }
        .modal { display:none; position:fixed; inset:0; z-index:9998; background:rgba(15,23,42,0.65); justify-content:center; align-items:center; padding:16px; overflow-y:auto; }
        .modal.active {
            display:flex;
        }
        .modal-box {
            background:#fff;
            color:#000;
            border-radius:24px;
            padding:24px;
            width:100%;
            max-width:400px;
            max-height:90vh;
            overflow-y:auto;
            text-align:center;
        }
        .modal-close {
            width:100%;
            padding:14px;
            margin-top:16px;
            background:#f1f5f9;
            border:none;
            border-radius:12px;
            font-weight:700;
            cursor:pointer;
            font-size:14px;
        }
        #topup-msg {
            min-height:20px;
            font-size:13px;
            font-weight:bold;
            margin-bottom:8px;
        }
        .status-section { display:flex; gap:16px; align-items:flex-start; }
        .status-info { flex:1; }
        .upgrade-btn { padding:10px 20px; align-self:flex-start; font-size:13px; }
    </style>
</head>
<body>

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

    <?php if (!empty($profile_msg)): ?>
    <div style="margin-bottom:16px;padding:10px 14px;border-radius:10px;
                background:#d1fae5;color:#065f46;font-size:13px;font-weight:600;">
        <?= htmlspecialchars($profile_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

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
            <div class="status-section">
                <div class="status-info">
                    <div class="stat-label">Статус</div>
                    <div class="stat-val"><?= $type_label[$user['user_type']] ?? '🤝 Уважаемый' ?></div>
                    <div class="bal-sub" style="margin-top:6px;">
                        <?php if ($user['user_type'] === 'responsible'): ?>
                            💎 Уже максимальный статус
                        <?php else: ?>
                            Повысьте до <b>✅ Ответственного</b>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($user['user_type'] !== 'responsible'): ?>
<button class="btn btn-success upgrade-btn" onclick="openModal('upgradeModal')">
    ⭐ Повысить<br><span style="font-size:11px;font-weight:bold;">8000 ₽ (НДС 22%)</span>
</button>
<?php endif; ?>
            </div>
            <?php if ($user['user_type'] === 'respected'): ?>
            <button class="btn btn-outline upgrade-btn"
                    style="margin-top:8px;font-size:12px;padding:8px 14px;"
                    onclick="chooseOrganizerFree()">
                🧾 Выбрать как Организатора
            </button>
            <?php endif; ?>
            <div class="stat-card">
                <div class="stat-label">Сделано ставок всего</div>
                <div class="stat-val"><?= count($bids_history) ?>+</div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:24px;">
        <h3 style="margin:0 0 20px;">💰 Пополнение баланса</h3>

        <div class="amounts" id="amounts-row">
            <?php foreach ([1000,3000,5000,10000,25000,50000] as $a): ?>
            <button class="amt-btn" onclick="selectAmt(<?= $a ?>)"><?= number_format($a, 0, '.', "\u{00A0}") ?>&nbsp;₽</button>
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

<!-- Модалка пополнения по QR -->
<div id="modal-qr" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <h3>📱 Оплата по QR / СБП</h3>
        <div style="margin:16px 0;">
            <img id="qr-img" src="" alt="QR" style="max-width:100%;border-radius:16px;">
        </div>
        <div style="font-size:13px;color:#555;">
            Назначение: <b id="qr-purpose"></b><br>
            Сумма: <b id="qr-sum-label"></b>
        </div>
        <button class="modal-close" onclick="document.getElementById('modal-qr').classList.remove('open')">
            Закрыть
        </button>
    </div>
</div>

<!-- Модалка реквизитов -->
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

<!-- Модалка повышения статуса -->
<div id="upgradeModal" class="modal">
  <div class="modal-content" style="max-width:500px;width:100%;background:#ffffff;border-radius:20px;padding:24px;position:relative;max-height:90vh;overflow-y:auto;">
    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
      <h3 style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">Повышение статуса</h3>
      <button type="button" onclick="closeModal('upgradeModal')" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">×</button>
    </div>

    <div class="tariff-card" onclick="selectTariff(this)" data-tariff="details"
         style="background:#f8fafc; border:2px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:12px; cursor:pointer;">
      <h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">Отчет по лоту</h3>
      <div class="tariff-price" style="font-size:20px; font-weight:800; color:#0ea5e9; margin-bottom:4px;">
        1 390 <small style="font-size:11px; font-weight:400; color:#64748b;">₽, в т.ч. НДС 22%</small>
      </div>
      <ul style="margin:0; padding-left:18px; font-size:13px; color:#475569;">
        <li>Подробный отчет</li>
        <li>Рекомендации эксперта</li>
        <li>PDF на почту</li>
      </ul>
    </div>

    <div class="tariff-card" onclick="selectTariff(this)" data-tariff="responsible"
         style="background:#f8fafc; border:2px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:12px; cursor:pointer;">
      <h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">Повысить статус</h3>
      <div class="tariff-price" style="font-size:20px; font-weight:800; color:#0ea5e9; margin-bottom:4px;">
        8 000 <small style="font-size:11px; font-weight:400; color:#64748b;">₽, в т.ч. НДС 22%</small>
      </div>
      <ul style="margin:0; padding-left:18px; font-size:13px; color:#475569;">
        <li>Статус «Ответственный»</li>
        <li>Приоритет в сделках</li>
        <li>Личные рекомендации</li>
      </ul>
    </div>

    <div id="paymentDetails" style="display:none; background:#f8fafc; padding:10px 12px; border-radius:10px; margin:12px 0; font-size:13px; color:#334155;"></div>

    <div class="payment-methods" id="paymentMethods" style="display:none; margin:12px 0;">
      <div style="font-size:12px; color:#64748b; margin-bottom:6px;">Способ оплаты</div>
      <div class="payment-buttons" style="display:flex; gap:8px;">
        <button type="button" onclick="selectPaymentMethod('qr')" id="paymentqr"
                class="payment-btn selected"
                style="flex:1; padding:8px 10px; background:#0f172a; border:2px solid #334155; border-radius:8px; color:#e5e7eb; cursor:pointer; font-size:12px;">
          QR / СБП
        </button>
        <button type="button" onclick="selectPaymentMethod('receipt')" id="paymentreceipt"
                class="payment-btn"
                style="flex:1; padding:8px 10px; background:#0f172a; border:2px solid #334155; border-radius:8px; color:#e5e7eb; cursor:pointer; font-size:12px;">
          Квитанция
        </button>
      </div>
    </div>

    <div id="qrblock" class="qr-reg-block" style="display:none;background:#ffffff;padding:16px;border-radius:10px;text-align:center;border:1px solid #e2e8f0;margin-bottom:10px;">
    <img id="qrimage" src="" style="width:180px;height:180px;display:block;margin:0 auto 8px;">
    <div style="font-size:12px;color:#64748b;">ИНН: 7728282160</div>
    <button type="button" onclick="markAsPaid()"
            style="margin-top:12px;width:100%;padding:10px;border:none;border-radius:8px;background:#0ea5e9;color:#fff;font-weight:700;font-size:13px;cursor:pointer;">
        ✅ Я оплатил — загрузить подтверждение
    </button>
</div>

    <div id="receiptblock" class="receipt-reg-block" style="display:none; background:#0f172a; padding:16px; border-radius:10px; color:#cbd5e1; margin-bottom:10px; font-size:13px;">
      <p style="margin:0 0 8px;">Сформируем красивую квитанцию с QR-кодом и всеми реквизитами.</p>
      <button type="button" class="receipt-generate-btn" onclick="generateReceipt()" style="width:100%; padding:10px; background:#0ea5e9; color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
        🧾 Сформировать квитанцию
      </button>
    </div>

    <div id="receiptFormBlock" style="margin-top:10px; border-top:1px solid #e2e8f0; padding-top:12px; display:none;">
      <p style="font-weight:600; margin:0 0 8px; font-size:13px;">Загрузите чек об оплате</p>
      <form id="upgradeReceiptForm" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upgrade_status_receipt">
        <input type="hidden" name="tariff" id="receipttariff" value="">
        <input type="hidden" name="amount" id="receiptamount" value="">

        <input type="file" name="receipt_file" accept=".jpg,.jpeg,.png,.pdf"
               style="width:100%; padding:6px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px; margin-bottom:6px;">
        <textarea name="comment" rows="2" placeholder="Комментарий (необязательно)"
                  style="width:100%; padding:6px 8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px; margin-bottom:8px;"></textarea>

        <button type="submit" style="width:100%; padding:9px 10px; border:none; border-radius:8px; background:#16a34a; color:#fff; font-weight:700; font-size:13px; cursor:pointer;">
          📎 Отправить квитанцию
        </button>
      </form>
    </div>

    <div id="upgradeSuccessBlock" style="display:none; text-align:center; padding:20px 10px;">
      <div style="width:64px;height:64px;border-radius:999px;margin:0 auto 12px;background:#dcfce7;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:32px;">
        ✓
      </div>
      <div style="font-weight:700;font-size:16px;margin-bottom:6px;color:#022c22;">
        Спасибо!
      </div>
      <div style="font-size:14px;color:#334155;margin-bottom:16px;">
        Ваша заявка рассматривается.
      </div>
      <button type="button" onclick="closeModal('upgradeModal')" style="padding:9px 16px;border-radius:999px;border:none;background:#0ea5e9;color:#fff;font-weight:600;cursor:pointer;font-size:14px;">
        Закрыть
      </button>
    </div>

    <div style="display:flex;gap:8px;margin-top:14px;" id="actionButtons">
      <button type="button" onclick="closeModal('upgradeModal')" style="flex:1;padding:8px;border-radius:10px;border:1px solid #e2e8f0;background:#f9fafb;cursor:pointer;font-size:13px;">
        Отмена
      </button>
      <button type="button" onclick="markAsPaid()" style="flex:1;padding:8px;border-radius:10px;border:none;background:#0ea5e9;color:#fff;cursor:pointer;font-size:13px;font-weight:600;">
        ✅ Я оплатил
      </button>
    </div>
  </div>
</div>

<script>
lucide.createIcons();
document.addEventListener('DOMContentLoaded', function () {
    const upgradeForm = document.getElementById('upgradeReceiptForm');
    if (!upgradeForm) return;

    upgradeForm.addEventListener('submit', function (e) {
        const file = upgradeForm.querySelector('input[type="file"][name="receipt_file"]');
        const errP = document.querySelector('#receiptFormBlock p');

        if (!file || !file.files || file.files.length === 0) {
            e.preventDefault();

            if (file) {
                file.style.border = '2px solid #dc2626';
                file.focus();
            }

            if (errP) {
                errP.textContent      = '⚠️ Прикрепите файл квитанции — без него отправка невозможна.';
                errP.style.color      = '#dc2626';
                errP.style.fontWeight = '700';
            }

            return false;
        }

        if (file) file.style.border = '1px solid #e2e8f0';
        if (errP) {
            errP.textContent      = 'Загрузите скриншот или фото квитанции об оплате.';
            errP.style.color      = '#374151';
            errP.style.fontWeight = '600';
        }
    });
});
const USER_ID  = <?= (int)$user_id ?>;
const USERNAME = '<?= addslashes($user['username']) ?>';

let selectedAmt    = 0;
let currentMethod  = 'qr';
const AMOUNTS_LIST = [1000,3000,5000,10000,25000,50000];
const UPGRADE_COST = 8000;

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
    if (!m) return;
    m.textContent = text;
    m.style.color = color || '#ef4444';
}

function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('active');
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

let selectedTariff = null;
let currentAmount  = 0;
let currentTariffName = '';

function selectTariff(el) {
    document.querySelectorAll('.tariff-card').forEach(c => {
        c.style.borderColor = '#e2e8f0';
        c.style.background  = '#f8fafc';
    });
    el.style.borderColor = '#0ea5e9';
    el.style.background  = '#eff6ff';

    selectedTariff = el.dataset.tariff;
    if (selectedTariff === 'details') {
        currentAmount     = 1390;
        currentTariffName = 'Отчет по лоту';
    } else {
        currentAmount     = 8000;
        currentTariffName = 'Статус Ответственный';
    }

    const vat = Math.round(currentAmount * 22 / 122);
    const pd  = document.getElementById('paymentDetails');
    pd.style.display = 'block';
    pd.innerHTML = `
        <div style="font-weight:600;">${currentTariffName}</div>
        <div>${currentAmount.toLocaleString('ru-RU')} ₽, в т.ч. НДС ${vat.toLocaleString('ru-RU')} ₽ (22%)</div>
    `;

    document.getElementById('paymentMethods').style.display = 'block';
    document.getElementById('receipttariff').value = currentTariffName;
    document.getElementById('receiptamount').value = currentAmount;

    const qrData = `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${currentAmount}00|Purpose=${currentTariffName}, ${currentAmount} ₽, в т.ч. НДС 22%`;
    document.getElementById('qrimage').src =
        'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(qrData);

    document.getElementById('qrblock').style.display        = 'block';
    document.getElementById('receiptblock').style.display   = 'none';
    document.getElementById('receiptFormBlock').style.display = 'none';
    document.getElementById('actionButtons').style.display  = 'flex';
}

function selectPaymentMethod(method) {
    document.getElementById('paymentqr').classList.remove('selected');
    document.getElementById('paymentreceipt').classList.remove('selected');
    document.getElementById('payment' + method).classList.add('selected');
    document.getElementById('qrblock').style.display      = method === 'qr' ? 'block' : 'none';
    document.getElementById('receiptblock').style.display = method === 'receipt' ? 'block' : 'none';
}

function generateReceipt() {
    if (!selectedTariff || !currentAmount) return;
    const vat = Math.round(currentAmount * 22 / 122);
    const qrData = `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${currentAmount}00|Purpose=${currentTariffName}, ${currentAmount} ₽, в т.ч. НДС 22%`;
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(qrData);

    const w = window.open('', 'blank', 'width=700,height=800,scrollbars=yes,resizable=yes');
    if (!w) return;
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Квитанция</title>
<style>
body{font-family:Inter,Arial,sans-serif;padding:40px;background:#f8fafc;margin:0;}
.receipt{max-width:520px;margin:0 auto;background:#fff;border-radius:20px;padding:26px;
box-shadow:0 10px 25px rgba(0,0,0,0.1);border:1px solid #e2e8f0;}
h1{font-size:22px;font-weight:800;margin:0 0 18px;}
.details{margin:14px 0;line-height:1.6;color:#334155;font-size:13px;}
.qr{text-align:center;margin:18px 0;}
.footer{font-size:11px;color:#64748b;margin-top:16px;text-align:center;}
button{display:block;width:100%;padding:10px;background:#0ea5e9;color:#fff;border:none;
border-radius:8px;font-weight:600;cursor:pointer;margin-top:18px;}
</style></head><body>
<div class="receipt">
<h1>Квитанция об оплате</h1>
<div class="details">
<p><strong>Получатель:</strong> ООО «Форсаж»</p>
<p><strong>Адрес:</strong> 121059, г. Москва, наб. Тараса Шевченко, д.23А, стр.2</p>
<p><strong>ИНН / КПП:</strong> 7728282160 / 773001001</p>
<p><strong>Счёт:</strong> 40702810101500033019</p>
<p><strong>Корр. счёт:</strong> 30101810745374525104</p>
<p><strong>БИК:</strong> 044525104</p>
<p><strong>Назначение платежа:</strong> ${currentTariffName}</p>
<p><strong>Сумма:</strong> ${currentAmount.toLocaleString('ru-RU')} ₽, в т.ч. НДС ${vat.toLocaleString('ru-RU')} ₽ (22%)</p>
</div>
<div class="qr">
<img src="${qrUrl}" style="max-width:200px;"><p>QR для оплаты</p>
</div>
<div class="footer"><p>${new Date().toLocaleDateString('ru-RU')}</p></div>
<button onclick="window.print()">Распечатать</button>
</div></body></html>`);
    w.document.close();
}

function markAsPaid() {
    if (!selectedTariff || !currentAmount) {
        alert('Выберите тариф.');
        return;
    }

    const fileInput = document.querySelector('#upgradeReceiptForm input[type="file"][name="receipt_file"]');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        if (fileInput) fileInput.style.border = '2px solid #dc2626';
        const errP = document.querySelector('#receiptFormBlock p');
        if (errP) {
            errP.textContent = '⚠️ Прикрепите файл квитанции — без него отправка невозможна.';
            errP.style.color = '#dc2626';
            errP.style.fontWeight = '700';
        }
        document.getElementById('qrblock').style.display = 'none';
        document.getElementById('paymentMethods').style.display = 'none';
        document.getElementById('paymentDetails').style.display = 'none';
        document.getElementById('actionButtons').style.display = 'none';
        document.getElementById('receiptFormBlock').style.display = 'block';
        return;
    }

    document.getElementById('qrblock').style.display = 'none';
    document.getElementById('paymentMethods').style.display = 'none';
    document.getElementById('paymentDetails').style.display = 'none';
    document.getElementById('actionButtons').style.display = 'none';
    document.getElementById('receiptFormBlock').style.display = 'block';
}

/* Пополнение */
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
    const vat    = Math.round(amount * 22 / 122);
    const purpose = `Пополнение счета ID${USER_ID} ${USERNAME}, ${amount.toLocaleString('ru-RU')} ₽, в т.ч. НДС ${vat.toLocaleString('ru-RU')} ₽ (22%)`;

    const amtFmt  = amount.toLocaleString('ru-RU') + ' ₽';
    if (method === 'qr') {
        const qrData =
            `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${amount}00|Purpose=${purpose}`;
        document.getElementById('qr-img').src =
            'https://api.qrserver.com/v1/create-qr-code/?size=440x440&data=' + encodeURIComponent(qrData);
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
    const amount = getAmount();
    if (!amount || amount < 7000) {
        setMsg('Укажите сумму от 7000 ₽', '#ef4444');
        return;
    }

    const vat = Math.round(amount * 22 / 122);
    const purpose = `Пополнение счета ID${USER_ID} ${USERNAME}, ${amount.toLocaleString('ru-RU')} ₽, в т.ч. НДС ${vat.toLocaleString('ru-RU')} ₽ (22%)`;
    const qrData =
        `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${amount}00|Purpose=${purpose}`;
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(qrData);

    const w = window.open('', '_blank', 'width=800,height=900,scrollbars=yes,resizable=yes');
    if (!w) return;

    w.document.write(`<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">
<title>Квитанция на оплату</title>
<style>
body{font-family:Inter,system-ui,Arial,sans-serif;background:#0f172a;margin:0;padding:24px;color:#e5e7eb;}
.wrap{max-width:720px;margin:0 auto;background:#020617;border-radius:20px;padding:24px 28px;border:1px solid #1e293b;}
h1{margin:0 0 12px;font-size:20px;font-weight:800;}
.block{margin:10px 0 14px;font-size:13px;line-height:1.8;}
.label{color:#9ca3af;font-size:11px;text-transform:uppercase;letter-spacing:.06em;}
.value{font-weight:600;}
.row{display:flex;gap:32px;flex-wrap:wrap;margin-top:10px;}
.qr-box{background:#020617;border-radius:16px;border:1px solid #1e293b;padding:16px;text-align:center;}
.qr-box img{max-width:220px;display:block;margin:0 auto 8px;}
.btn-print{margin-top:18px;padding:10px 18px;border:none;border-radius:999px;background:#3b82f6;color:#fff;font-weight:600;font-size:14px;cursor:pointer;}
</style></head><body>
<div class="wrap">
  <h1>Квитанция на пополнение баланса</h1>
  <div class="block">
    <div class="label">Получатель</div>
    <div class="value">ООО «Форсаж»</div>
    <div>ИНН 7728282160, КПП 773001001</div>
    <div>Банк: ООО Банк Точка, БИК 044525104</div>
    <div>Счёт: 40702810101500033019</div>
    <div>Корр. счёт: 30101810745374525104</div>
  </div>
  <div class="row">
    <div class="block" style="flex:1 1 260px;">
      <div class="label">Плательщик</div>
      <div class="value">ID${USER_ID} ${USERNAME}</div>
      <div class="label" style="margin-top:10px;">Назначение платежа</div>
      <div>${purpose}</div>
      <div class="label" style="margin-top:10px;">Сумма</div>
      <div class="value" style="font-size:18px;">${amount.toLocaleString('ru-RU')} ₽</div>
      <div style="color:#9ca3af;font-size:12px;">В т.ч. НДС ${vat.toLocaleString('ru-RU')} ₽ (22%)</div>
      <div style="margin-top:10px;font-size:12px;color:#9ca3af;">Дата формирования: ${new Date().toLocaleDateString('ru-RU')}</div>
    </div>
    <div class="qr-box">
      <img src="${qrUrl}" alt="QR-код для оплаты">
      <div style="font-size:12px;color:#9ca3af;">Отсканируйте в приложении банка для оплаты</div>
    </div>
  </div>
  <button class="btn-print" onclick="window.print()">Печать</button>
</div>
</body></html>`);
    w.document.close();
}

</script>

</body>
</html>
<?php
ob_end_flush();