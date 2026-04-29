<?php
ob_start();

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
        $_SESSION['user_balance'] = $u['balance'];
        $_SESSION['usertype'] = $u['user_type'];
    }
}

if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

// ── Смена пароля ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $old_pass = trim($_POST['old_password'] ?? '');
    $new_pass = trim($_POST['new_password'] ?? '');
    $confirm_pass = trim($_POST['confirm_password'] ?? '');

    if (!$old_pass || !$new_pass || !$confirm_pass) {
        $_SESSION['profile_msg'] = 'Все поля обязательны для заполнения.';
    } elseif ($new_pass !== $confirm_pass) {
        $_SESSION['profile_msg'] = 'Новые пароли не совпадают.';
    } elseif (strlen($new_pass) < 6) {
        $_SESSION['profile_msg'] = 'Пароль должен быть не менее 6 символов.';
    } else {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $current_hash = $stmt->fetchColumn();
        
        if (password_verify($old_pass, $current_hash)) {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $upd->execute([$new_hash, $uid]);
            $_SESSION['profile_msg'] = 'Пароль успешно изменён.';
        } else {
            $_SESSION['profile_msg'] = 'Неверный текущий пароль.';
        }
    }

    header('Location: profile.php');
    exit;
}

// ── Привязка/отвязка Telegram ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_telegram') {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $telegram_id = trim($_POST['telegram_id'] ?? '');

    if (!$telegram_id) {
        $_SESSION['profile_msg'] = 'Введите Telegram ID.';
    } else {
        $upd = $pdo->prepare("UPDATE users SET telegram_id = ? WHERE id = ?");
        $upd->execute([$telegram_id, $uid]);
        $_SESSION['profile_msg'] = 'Telegram успешно привязан.';
    }

    header('Location: profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlink_telegram') {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $upd = $pdo->prepare("UPDATE users SET telegram_id = NULL WHERE id = ?");
    $upd->execute([$uid]);
    $_SESSION['profile_msg'] = 'Telegram отвязан.';

    header('Location: profile.php');
    exit;
}

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

if (!isset($_SESSION['lang'])) {
    $accept_lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'ru';
    $_SESSION['lang'] = (substr($accept_lang, 0, 2) === 'ru') ? 'ru' : 'en';
}
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'ru';
}
$lang = $_SESSION['lang'];

$stmt = $pdo->prepare(
    "SELECT id, username, balance, user_type, bid_pack_remaining, email, telegram_id
     FROM users WHERE id = ?"
);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Обновляем сессию для header.php
$_SESSION['user_balance'] = $user['balance'];
$_SESSION['usertype']     = $user['user_type'];
$_SESSION['user_name']    = $user['username'];

$profile_msg = $_SESSION['profile_msg'] ?? null;
if ($profile_msg !== null) unset($_SESSION['profile_msg']);

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

// ── Загрузка заявок на участие в торгах ──
$stmt_active = $pdo->prepare(
    "SELECT a.id, a.lot_id, a.created_at, a.status as application_status,
            l.title, l.auction_status as lot_status, l.end_time
     FROM applications a
     JOIN lots l ON a.lot_id = l.id
     WHERE a.user_id = ? AND l.auction_status = 'active'
     ORDER BY a.created_at DESC"
);
$stmt_active->execute([$user_id]);
$active_applications = $stmt_active->fetchAll(PDO::FETCH_ASSOC);

$stmt_drafts = $pdo->prepare(
    "SELECT a.id, a.lot_id, a.created_at, a.status as application_status,
            l.title, l.auction_status as lot_status
     FROM applications a
     JOIN lots l ON a.lot_id = l.id
     WHERE a.user_id = ? AND a.status = 'draft'
     ORDER BY a.created_at DESC"
);
$stmt_drafts->execute([$user_id]);
$draft_applications = $stmt_drafts->fetchAll(PDO::FETCH_ASSOC);

$stmt_submitted = $pdo->prepare(
    "SELECT a.id, a.lot_id, a.created_at, a.status as application_status, a.processed_at,
            l.title, l.auction_status as lot_status
     FROM applications a
     JOIN lots l ON a.lot_id = l.id
     WHERE a.user_id = ? AND a.status IN ('pending', 'approved', 'rejected')
     ORDER BY a.created_at DESC"
);
$stmt_submitted->execute([$user_id]);
$submitted_applications = $stmt_submitted->fetchAll(PDO::FETCH_ASSOC);

// ── Скандинавские аукционы, где пользователь участвует (есть ставки) ──
$stmt_scand_participant = $pdo->prepare("
    SELECT DISTINCT l.id, l.title, l.start_price, l.end_time, l.auction_status,
           (SELECT COUNT(*) FROM bids b WHERE b.lot_id = l.id) as total_bids,
           (SELECT MAX(b.bid_amount) FROM bids b WHERE b.lot_id = l.id AND b.user_id = ?) as my_last_bid,
           (SELECT MAX(b.bid_time) FROM bids b WHERE b.lot_id = l.id AND b.user_id = ?) as my_last_bid_time
    FROM bids b
    JOIN lots l ON b.lot_id = l.id
    WHERE b.user_id = ? AND l.auction_type = 'scandinavian'
    ORDER BY l.id DESC
");
$stmt_scand_participant->execute([$user_id, $user_id, $user_id]);
$scand_participant_lots = $stmt_scand_participant->fetchAll(PDO::FETCH_ASSOC);

// ── Скандинавские аукционы, опубликованные пользователем (как организатор) ──
$stmt_scand_owner = $pdo->prepare("
    SELECT l.id, l.title, l.start_price, l.end_time, l.auction_status,
           (SELECT COUNT(*) FROM bids b WHERE b.lot_id = l.id) as total_bids,
           (SELECT MAX(b.bid_amount) FROM bids b WHERE b.lot_id = l.id) as highest_bid
    FROM lots l
    WHERE l.owner_id = ? AND l.auction_type = 'scandinavian'
    ORDER BY l.id DESC
");
$stmt_scand_owner->execute([$user_id]);
$scand_owner_lots = $stmt_scand_owner->fetchAll(PDO::FETCH_ASSOC);

// ── Комиссионная продажа: мои лоты (torgi) ──
$my_commission_lots = [];
$my_interests = [];
$reserved_lots = [];

$stmt = $pdo->query("SHOW TABLES LIKE 'torgi'");
if ($stmt->rowCount() > 0) {
    $stmt_my = $pdo->prepare("
        SELECT id, title, price, lot_type, region, status, images, date_created
        FROM torgi
        WHERE dealer_id = ?
        ORDER BY date_created DESC
    ");
    $stmt_my->execute([$user_id]);
    $my_commission_lots = $stmt_my->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $pdo->query("SHOW TABLES LIKE 'commission_interests'");
if ($stmt->rowCount() > 0) {
    $stmt_int = $pdo->prepare("
        SELECT ci.*, t.title as lot_title, t.price as lot_price
        FROM commission_interests ci
        LEFT JOIN torgi t ON ci.lot_id = t.id
        WHERE ci.user_id = ?
        ORDER BY ci.created_at DESC
    ");
    $stmt_int->execute([$user_id]);
    $my_interests = $stmt_int->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $pdo->query("SHOW TABLES LIKE 'commission_reservations'");
if ($stmt->rowCount() > 0) {
    $stmt_res = $pdo->prepare("
        SELECT cr.*, t.title as lot_title, t.price as lot_price
        FROM commission_reservations cr
        LEFT JOIN torgi t ON cr.lot_id = t.id
        WHERE cr.user_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt_res->execute([$user_id]);
    $reserved_lots = $stmt_res->fetchAll(PDO::FETCH_ASSOC);
}

$type_label = $lang === 'en'
    ? ['respected' => '🤝 Respected', 'responsible' => '✅ Responsible']
    : ['respected' => '🤝 Уважаемый', 'responsible' => '✅ Ответственный'];
$role_labels = $lang === 'en' ? [
    'admin'       => 'Administrator',
    'organizer'   => 'Organizer',
    'responsible' => 'Responsible bidder',
    'уважаемый'   => 'Bidder'
] : [
    'admin'       => 'Администратор',
    'organizer'   => 'Организатор',
    'responsible' => 'Ответственный участник',
    'уважаемый'   => 'Участник'
];
$status_icon = ['pending' => '⏳', 'confirmed' => '✅', 'rejected' => '❌', 'approved' => '✅', 'draft' => '📝'];
$status_color = ['pending' => '#f59e0b', 'confirmed' => '#4ade80', 'rejected' => '#f87171', 'approved' => '#4ade80', 'draft' => '#94a3b8'];
$method_icon = ['balance' => 'ð³', 'cash' => 'ð±ð§¾', 'pack' => 'ð¦', 'qr' => 'ð±', 'receipt' => 'ð§¾'];

/* Favorites: load full lots/torgi rows the user has starred. */
require_once 'db_schema_extra.php';
require_once 'favorites_widget.php';
$fav_lots = [];
$fav_torgi = [];
try {
    $fst = $pdo->prepare("
        SELECT l.id, l.title, l.price, l.start_price, l.auction_type, l.auction_status,
               l.end_time, f.created_at AS fav_at
        FROM user_favorites f
        JOIN lots l ON l.id = f.lot_id
        WHERE f.user_id = ? AND f.lot_type = 'lot'
        ORDER BY f.created_at DESC
    ");
    $fst->execute([$user_id]);
    $fav_lots = $fst->fetchAll(PDO::FETCH_ASSOC);

    $fst2 = $pdo->prepare("
        SELECT t.id, t.title, t.price, t.region, t.lot_type, t.status,
               t.images, f.created_at AS fav_at
        FROM user_favorites f
        JOIN torgi t ON t.id = f.lot_id
        WHERE f.user_id = ? AND f.lot_type = 'torgi'
        ORDER BY f.created_at DESC
    ");
    $fst2->execute([$user_id]);
    $fav_torgi = $fst2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('profile favorites load: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?= $lang === 'en' ? 'Profile — ' : 'Личный кабинет — ' ?><?= htmlspecialchars($user['username']) ?></title>
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
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 240px;
            background: #0f172a;
            padding: 28px 16px 24px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            max-height: 100vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            position: fixed;
            left: 0;
            top: 0;
            border-right: 1px solid var(--border);
            transition: transform 0.3s ease;
            z-index: 1000;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .main {
                margin-left: 0 !important;
                padding: 20px 16px !important;
            }
        }
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 1100;
            background: rgba(15,23,42,0.8);
            backdrop-filter: blur(8px);
            border: none;
            border-radius: 12px;
            padding: 8px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            font-size: 20px;
            color: #fff;
            text-decoration: none;
            margin-bottom: 40px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--dim);
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 4px;
            font-weight: 500;
            font-size: 14px;
            transition: background 0.2s, color 0.2s;
        }
        .nav-item:hover, .nav-item.active {
            background: rgba(0,136,204,0.12);
            color: var(--accent);
        }
        .nav-item.danger { color: #ef4444; margin-top: auto; }
        .nav-item.danger:hover { background: rgba(239,68,68,0.1); }
        .main {
            margin-left: 240px;
            flex: 1;
            padding: 36px 40px;
            width: 100%;
        }
        @media (max-width: 768px) {
            .main {
                margin-left: 0;
                padding: 20px 16px;
            }
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .topbar h2 { margin: 0; font-size: 22px; }
        .online-badge {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dot-green { width: 7px; height: 7px; background: #22c55e; border-radius: 50%; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            backdrop-filter: blur(12px);
            width: 100%;
            overflow-x: auto;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 700px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
        .balance-card {
            background: linear-gradient(135deg, #1e3a5f, #0f172a);
            border: 1px solid #3b82f6;
            border-radius: 20px;
            padding: 28px;
        }
        .bal-label { font-size: 11px; color: var(--dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .bal-val   { font-size: 48px; font-weight: 800; color: #4ade80; line-height: 1; word-break: break-word; }
        .bal-sub   { font-size: 13px; color: var(--dim); margin-top: 6px; }
        .stat-card { border-radius: 16px; padding: 20px; background: var(--card); border: 1px solid var(--border); }
        .stat-label { font-size: 11px; color: var(--dim); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .stat-val   { font-size: 22px; font-weight: 800; }
        .btn {
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--dim); }
        .btn-success { background: #10b981; color: #fff; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .history-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            flex-wrap: wrap;
        }
        .hr-icon { font-size: 20px; width: 32px; text-align: center; flex-shrink: 0; }
        .hr-main { flex: 1; }
        .hr-title { font-weight: 600; color: #f1f5f9; }
        .hr-sub   { font-size: 12px; color: var(--dim); margin-top: 2px; }
        .hr-amount { font-weight: 800; white-space: nowrap; }
        .amounts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .amt-btn {
            padding: 10px 16px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            background: #0f172a;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
        }
        .amt-btn.selected { border-color: var(--accent); background: #1e3a5f; color: #60a5fa; }
        .field {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            background: #0f172a;
            border: 1.5px solid var(--border);
            color: #fff;
            font-size: 15px;
            margin-bottom: 12px;
            outline: none;
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
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
            flex-wrap: wrap;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 600;
            color: var(--dim);
            cursor: pointer;
            border-radius: 8px;
        }
        .tab-btn.active {
            color: var(--accent);
            background: rgba(0,136,204,0.1);
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; color: var(--dim); margin-bottom: 6px; font-weight: 600; }
        .form-input {
            width: 100%;
            padding: 12px 16px;
            background: #0f172a;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .status-pending { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .status-approved { background: rgba(74,222,128,0.2); color: #4ade80; }
        .status-rejected { background: rgba(248,113,113,0.2); color: #f87171; }
        .status-draft { background: rgba(148,163,184,0.2); color: #94a3b8; }
        .no-data { text-align: center; color: var(--dim); padding: 40px 20px; font-size: 14px; }
        .table-wrap { overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; min-width: 500px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--dim); font-size: 12px; font-weight: 600; }
        @media (max-width: 600px) {
            .bal-val { font-size: 32px; }
            .stat-val { font-size: 18px; }
            .btn { padding: 12px 18px; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>

<!-- Кнопка открытия меню на мобильных -->
<button class="mobile-menu-btn" id="mobileMenuToggle">
    <i data-lucide="menu" style="width: 28px; height: 28px; color: white;"></i>
</button>

<aside class="sidebar" id="sidebar">
    <a href="index.php" class="logo">
        <i data-lucide="zap" style="color:var(--accent)"></i>
        <span>ERA ETP</span>
    </a>
    <nav>
        <a href="profile.php" class="nav-item active">
            <i data-lucide="layout-dashboard"></i> <span class="label"><?= $lang === 'en' ? 'Dashboard' : 'Кабинет' ?></span>
        </a>
        <a href="reestr.php" class="nav-item">
            <i data-lucide="gavel"></i> <span class="label"><?= $lang === 'en' ? 'Auctions' : 'Торги' ?></span>
        </a>
        <a href="reestr.php?type=scandinavian&status=active" class="nav-item">
            <i data-lucide="flame"></i> <span class="label"><?= $lang === 'en' ? 'Scandinavian' : 'Скандинавский' ?></span>
        </a>
        <a href="torgi_list.php" class="nav-item">
            <i data-lucide="store"></i> <span class="label"><?= $lang === 'en' ? 'Commission' : 'Комиссионная' ?></span>
        </a>
        <a href="#" class="nav-item" id="favorites-tab-link">
            <i data-lucide="star"></i> <span class="label"><?= $lang === 'en' ? 'Favorites' : 'Избранное' ?></span>
        </a>
        <a href="#" class="nav-item" id="password-tab-link">
            <i data-lucide="lock"></i> <span class="label"><?= $lang === 'en' ? 'Password' : 'Пароль' ?></span>
        </a>
        <a href="#" class="nav-item" id="telegram-tab-link">
            <i data-lucide="message-circle"></i> <span class="label">Telegram</span>
        </a>
    </nav>
    <a href="logout.php" class="nav-item danger" style="margin-top:auto;">
        <i data-lucide="log-out"></i> <span class="label"><?= $lang === 'en' ? 'Sign out' : 'Выйти' ?></span>
    </a>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h2><?= $lang === 'en' ? 'Welcome, ' : 'Добро пожаловать, ' ?><?= htmlspecialchars($user['username']) ?>!</h2>
            <p style="color:var(--dim);margin:4px 0 0;font-size:14px;">
                <?= $type_label[$user['user_type']] ?? '🤝 Уважаемый' ?>
                &nbsp;·&nbsp; <?= date('d.m.Y') ?>
            </p>
        </div>
        <div class="online-badge">
            <span class="dot-green"></span> <?= $lang === 'en' ? 'Online' : 'Онлайн' ?>
        </div>
    </div>

    <?php if (!empty($profile_msg)): ?>
    <div style="margin-bottom:16px;padding:10px 14px;border-radius:10px;
                background:#d1fae5;color:#065f46;font-size:13px;font-weight:600;">
        <?= htmlspecialchars($profile_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Основной контент кабинета -->
    <div id="main-content">
        <div class="grid-2" style="margin-bottom:24px;">
            <div class="balance-card">
                <div class="bal-label"><?= $lang === 'en' ? 'Account balance' : 'Баланс личного кабинета' ?></div>
                <div class="bal-val" id="balance-display">
                    <?= number_format((int)$user['balance'], 0, '.', "\u{00A0}") ?>&nbsp;₽
                </div>
                <div class="bal-sub">
                    <?= $lang === 'en' ? 'Bid pack:' : 'Пакет ставок:' ?> <b style="color:#f59e0b;"><?= (int)$user['bid_pack_remaining'] ?> <?= $lang === 'en' ? 'pcs' : 'шт.' ?></b>
                </div>
            </div>
            <div class="card" style="display:flex;flex-direction:column;gap:16px;">
                <div class="status-section">
                    <div class="status-info">
                        <div class="stat-label"><?= $lang === 'en' ? 'Status' : 'Статус' ?></div>
                        <div class="stat-val"><?= $type_label[$user['user_type']] ?? '🤝 Уважаемый' ?></div>
                        <div class="bal-sub" style="margin-top:6px;">
                            <?= $lang === 'en' ? 'Role:' : 'Роль:' ?> <?= $role_labels[$user['user_type']] ?? ($lang === 'en' ? 'Bidder' : 'Участник') ?>
                        </div>
                        <div class="bal-sub" style="margin-top:6px;">
                            <?php if ($user['user_type'] === 'responsible'): ?>
                                💎 <?= $lang === 'en' ? 'Highest status reached' : 'Уже максимальный статус' ?>
                            <?php else: ?>
                                <?= $lang === 'en' ? 'Upgrade to <b>✅ Responsible</b>' : 'Повысьте до <b>✅ Ответственного</b>' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($user['user_type'] !== 'responsible'): ?>
                    <button class="btn btn-success upgrade-btn" onclick="openModal('upgradeModal')">
                        ⭐ <?= $lang === 'en' ? 'Upgrade' : 'Повысить' ?><br><span style="font-size:11px;font-weight:bold;">8000 ₽ <?= $lang === 'en' ? '(VAT 22%)' : '(НДС 22%)' ?></span>
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($user['user_type'] === 'respected'): ?>
                <button class="btn btn-outline upgrade-btn"
                        style="margin-top:8px;font-size:12px;padding:8px 14px;"
                        onclick="chooseOrganizerFree()">
                    🧾 <?= $lang === 'en' ? 'Become an Organizer' : 'Выбрать как Организатора' ?>
                </button>
                <?php endif; ?>
                <div class="stat-card">
                    <div class="stat-label"><?= $lang === 'en' ? 'Total bids placed' : 'Сделано ставок всего' ?></div>
                    <div class="stat-val"><?= count($bids_history) ?>+</div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:24px;">
            <h3 style="margin:0 0 6px;">💰 <?= $lang === 'en' ? 'Top up balance' : 'Пополнение баланса' ?></h3>
            <p style="color:var(--dim);font-size:13px;margin:0 0 16px;"><?= $lang === 'en' ? 'Minimum top-up RUB 7,000, in multiples of RUB 500.' : 'Минимальная сумма пополнения — 7 000 ₽, кратно 500 ₽.' ?></p>
            <div class="amounts" id="amounts-row">
                <?php foreach ([7000,10000,15000,25000,50000,100000] as $a): ?>
                <button class="amt-btn" onclick="selectAmt(<?= $a ?>)"><?= number_format($a, 0, '.', "\u{00A0}") ?>&nbsp;₽</button>
                <?php endforeach; ?>
            </div>
            <input class="field" type="number" id="custom-amount"
                   placeholder="<?= $lang === 'en' ? 'Or enter an amount (min 7,000, step 500)' : 'Или введите сумму (мин 7 000 ₽, шаг 500)' ?>" min="7000" step="500"
                   oninput="deselectAmts()" style="max-width:300px;">
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">
                <button class="btn btn-primary" onclick="topupOpen()">💳 <?= $lang === 'en' ? 'Pay' : 'Оплатить' ?></button>
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
        
        <!-- Блок Торги (заявки) -->
        <div class="card" style="margin-top:24px;">
            <h3 style="margin:0 0 16px;">Мои заявки на торги</h3>
            <div class="tabs">
                <button class="tab-btn active" data-tab="active">Действующие</button>
                <button class="tab-btn" data-tab="draft">Черновики</button>
                <button class="tab-btn" data-tab="submitted">Поданные</button>
            </div>
            <div class="tab-pane active" id="tab-active">
                <?php if (count($active_applications) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Лот</th><th>Дата подачи</th><th>Окончание</th><th>Действия</th></tr></thead>
                         <tbody>
                             <?php foreach ($active_applications as $app): ?>
                             <tr>
                                 <td><a href="lot_view.php?id=<?= $app['lot_id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($app['title']) ?></a></td>
                                 <td><?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></td>
                                 <td><?= $app['end_time'] ? date('d.m.Y H:i', strtotime($app['end_time'])) : '—' ?></td>
                                 <td><button class="btn btn-danger btn-sm" onclick="withdrawApplication(<?= $app['id'] ?>)">Отозвать</button></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">Нет действующих заявок</div>
                <?php endif; ?>
            </div>
            <div class="tab-pane" id="tab-draft">
                <?php if (count($draft_applications) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Лот</th><th>Создан</th><th>Статус</th><th>Действия</th></tr></thead>
                         <tbody>
                             <?php foreach ($draft_applications as $app): ?>
                             <tr>
                                 <td><a href="lot_application.php?id=<?= $app['lot_id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($app['title']) ?></a></td>
                                 <td><?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></td>
                                 <td><span class="status-badge status-draft">📝 Черновик</span></td>
                                 <td><a href="lot_application.php?id=<?= $app['lot_id'] ?>" class="btn btn-primary btn-sm">Продолжить</a></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">Нет черновиков</div>
                <?php endif; ?>
            </div>
            <div class="tab-pane" id="tab-submitted">
                <?php if (count($submitted_applications) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Лот</th><th>Дата подачи</th><th>Статус</th><th>Обработка</th></tr></thead>
                         <tbody>
                             <?php foreach ($submitted_applications as $app): ?>
                             <tr>
                                 <td><a href="lot_view.php?id=<?= $app['lot_id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($app['title']) ?></a></td>
                                 <td><?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></td>
                                 <td><span class="status-badge status-<?= $app['application_status'] ?>"><?= $status_icon[$app['application_status']] ?? '' ?> <?= $app['application_status'] === 'pending' ? 'На рассмотрении' : ($app['application_status'] === 'approved' ? 'Одобрена' : 'Отклонена') ?></span></td>
                                 <td><?= $app['processed_at'] ? date('d.m.Y H:i', strtotime($app['processed_at'])) : '—' ?></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">Нет поданных заявок</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Блок Скандинавские аукционы -->
        <div class="card" style="margin-top:24px;">
            <h3 style="margin:0 0 16px;">🔥 <?= $lang === 'en' ? 'Scandinavian auctions' : 'Скандинавские аукционы' ?></h3>
            <div class="tabs">
                <button class="tab-btn active" data-tab="scand-participant"><?= $lang === 'en' ? 'My participation' : 'Моё участие' ?></button>
                <button class="tab-btn" data-tab="scand-owner"><?= $lang === 'en' ? 'My auctions' : 'Мои аукционы' ?></button>
            </div>
            <div class="tab-pane active" id="tab-scand-participant">
                <?php if (count($scand_participant_lots) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Лот</th><th>Начальная цена</th><th>Моя ставка</th><th>Дата ставки</th><th>Всего ставок</th><th>Статус</th><th>Действия</th></tr></thead>
                         <tbody>
                             <?php foreach ($scand_participant_lots as $lot): 
                                 $end_ts = strtotime($lot['end_time']);
                                 $is_active = ($lot['auction_status'] === 'active' && $end_ts > time());
                             ?>
                             <tr>
                                 <td><a href="lot_scandinavian.php?id=<?= $lot['id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($lot['title']) ?></a></td>
                                 <td><?= number_format($lot['start_price'], 0, '.', ' ') ?> ₽</td>
                                 <td><?= $lot['my_last_bid'] ? '<span style="color:#f59e0b;">'.number_format($lot['my_last_bid'], 0, '.', ' ').' ₽</span>' : '—' ?></td>
                                 <td><?= $lot['my_last_bid_time'] ? date('d.m.Y H:i', strtotime($lot['my_last_bid_time'])) : '—' ?></td>
                                 <td><?= (int)$lot['total_bids'] ?></td>
                                 <td><?= ($lot['auction_status'] === 'active' && $end_ts > time()) ? '<span style="color:#22c55e;">Активен</span>' : '<span style="color:#64748b;">Завершён</span>' ?></td>
                                 <td><a href="lot_scandinavian.php?id=<?= $lot['id'] ?>" class="btn btn-primary btn-sm"><?= $is_active ? '🔥 Участвовать' : 'Просмотр' ?></a></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">Вы ещё не участвовали в скандинавских аукционах</div>
                <?php endif; ?>
            </div>
            <div class="tab-pane" id="tab-scand-owner">
                <?php if (count($scand_owner_lots) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Лот</th><th>Начальная цена</th><th>Макс. ставка</th><th>Всего ставок</th><th>Окончание</th><th>Статус</th><th>Действия</th></tr></thead>
                         <tbody>
                             <?php foreach ($scand_owner_lots as $lot): 
                                 $end_ts = strtotime($lot['end_time']);
                                 $is_active = ($lot['auction_status'] === 'active' && $end_ts > time());
                             ?>
                             <tr>
                                 <td><a href="lot_scandinavian.php?id=<?= $lot['id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($lot['title']) ?></a></td>
                                 <td><?= number_format($lot['start_price'], 0, '.', ' ') ?> ₽</td>
                                 <td><?= $lot['highest_bid'] ? number_format($lot['highest_bid'], 0, '.', ' ').' ₽' : '—' ?></td>
                                 <td><?= (int)$lot['total_bids'] ?></td>
                                 <td><?= date('d.m.Y H:i', strtotime($lot['end_time'])) ?></td>
                                 <td><?= ($lot['auction_status'] === 'active' && $end_ts > time()) ? '<span style="color:#22c55e;">Идёт</span>' : '<span style="color:#64748b;">Завершён</span>' ?></td>
                                 <td><a href="lot_scandinavian.php?id=<?= $lot['id'] ?>" class="btn btn-outline btn-sm">📊 Управление</a></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">Вы ещё не создавали скандинавские аукционы</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Блок Комиссионная продажа -->
        <div class="card" style="margin-top:24px;">
            <h3 style="margin:0 0 16px;">🏢 <?= $lang === 'en' ? 'Commission sales' : 'Комиссионная продажа' ?></h3>
            <div class="tabs">
                <button class="tab-btn active" data-tab="commission-my"><?= $lang === 'en' ? 'My lots' : 'Мои лоты' ?></button>
                <button class="tab-btn" data-tab="commission-interest"><?= $lang === 'en' ? 'My interests' : 'Мой интерес' ?></button>
                <button class="tab-btn" data-tab="commission-reserved"><?= $lang === 'en' ? 'Reserved' : 'Зарезервировано' ?></button>
            </div>
            <div class="tab-pane active" id="tab-commission-my">
                <?php if (count($my_commission_lots) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Название</th><th>Цена</th><th>Категория</th><th>Регион</th><th>Статус</th><th>Действия</th></tr></thead>
                         <tbody>
                             <?php foreach ($my_commission_lots as $lot): 
                                 $thumb = '';
                                 if (!empty($lot['images'])) {
                                     $imgs = json_decode($lot['images'], true);
                                     $thumb = is_array($imgs) && !empty($imgs[0]) ? $imgs[0] : '';
                                 }
                             ?>
                             <tr>
                                 <td>
                                     <?php if ($thumb): ?>
                                         <img src="<?= htmlspecialchars($thumb) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:8px;margin-right:8px;" alt="">
                                     <?php endif; ?>
                                     <a href="torgi_view.php?id=<?= $lot['id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($lot['title']) ?></a>
                                 </td>
                                 <td><?= number_format($lot['price'], 0, '.', ' ') ?> ₽</td>
                                 <td><?= htmlspecialchars($lot['lot_type'] ?? '—') ?></td>
                                 <td><?= htmlspecialchars($lot['region'] ?? '—') ?></td>
                                 <td><span style="color:<?= ($lot['status'] ?? 'Прием заявок') === 'Прием заявок' ? '#22c55e' : '#64748b' ?>;"><?= htmlspecialchars($lot['status'] ?? 'Прием заявок') ?></span></td>
                                 <td><a href="torgi_edit.php?id=<?= $lot['id'] ?>" class="btn btn-outline btn-sm">✏️ Редактировать</a> <a href="torgi_view.php?id=<?= $lot['id'] ?>" class="btn btn-primary btn-sm">Просмотр</a></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">
                    У вас нет опубликованных лотов на комиссионной продаже
                    <?php if (in_array($user['user_type'], ['organizer', 'admin'])): ?>
                        <div style="margin-top:16px;"><a href="commission.php" class="btn btn-primary btn-sm">➕ Добавить лот</a></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="tab-pane" id="tab-commission-interest">
                <?php if (count($my_interests) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Лот</th><th>Цена</th><th>Дата заявки</th><th>Статус</th><th>Действия</th></tr></thead>
                         <tbody>
                             <?php foreach ($my_interests as $interest): ?>
                             <tr>
                                 <td><a href="torgi_view.php?id=<?= $interest['lot_id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($interest['lot_title'] ?? 'Лот #'.$interest['lot_id']) ?></a></td>
                                 <td><?= number_format($interest['lot_price'] ?? 0, 0, '.', ' ') ?> ₽</td>
                                 <td><?= date('d.m.Y H:i', strtotime($interest['created_at'])) ?></td>
                                 <td><span class="status-badge status-<?= $interest['status'] ?? 'pending' ?>"><?= ucfirst($interest['status'] ?? 'Новая') ?></span></td>
                                 <td><a href="torgi_view.php?id=<?= $interest['lot_id'] ?>" class="btn btn-outline btn-sm">Просмотр</a></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">Вы ещё не оставляли заявки на комиссионные лоты</div>
                <?php endif; ?>
            </div>
            <div class="tab-pane" id="tab-commission-reserved">
                <?php if (count($reserved_lots) > 0): ?>
                <div class="table-wrap">
                     <table>
                         <thead><tr><th>Лот</th><th>Цена</th><th>Зарезервирован до</th><th>Статус</th><th>Действия</th></tr></thead>
                         <tbody>
                             <?php foreach ($reserved_lots as $res): ?>
                             <tr>
                                 <td><a href="torgi_view.php?id=<?= $res['lot_id'] ?>" style="color:var(--accent);"><?= htmlspecialchars($res['lot_title'] ?? 'Лот #'.$res['lot_id']) ?></a></td>
                                 <td><?= number_format($res['lot_price'] ?? 0, 0, '.', ' ') ?> ₽</td>
                                 <td><?= date('d.m.Y H:i', strtotime($res['expires_at'])) ?></td>
                                 <td><span class="status-badge status-<?= $res['status'] ?? 'active' ?>"><?= ucfirst($res['status'] ?? 'Активно') ?></span></td>
                                 <td><a href="torgi_view.php?id=<?= $res['lot_id'] ?>" class="btn btn-outline btn-sm">Просмотр</a></td>
                             </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                </div>
                <?php else: ?>
                <div class="no-data">У вас нет зарезервированных товаров</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<!-- Favorites section (hidden by default) -->
    <div id="favorites-content" style="display:none;">
        <div class="card">
            <h3 style="margin:0 0 16px;"><span style="color:#fbbf24;">&#9733;</span> <?= $lang === 'en' ? 'Favorites' : 'Избранное' ?></h3>
            <div class="tabs">
                <button class="tab-btn active" data-tab="fav-lots"><?= $lang === 'en' ? 'Auctions' : 'Аукционы' ?> (<?= count($fav_lots) ?>)</button>
                <button class="tab-btn" data-tab="fav-torgi"><?= $lang === 'en' ? 'Commission' : 'Комиссионные' ?> (<?= count($fav_torgi) ?>)</button>
            </div>
            <div class="tab-pane active" id="tab-fav-lots">
                <?php if (count($fav_lots) > 0): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th><?= $lang === 'en' ? 'Lot' : 'Лот' ?></th>
                            <th><?= $lang === 'en' ? 'Type' : 'Тип' ?></th>
                            <th><?= $lang === 'en' ? 'Price' : 'Цена' ?></th>
                            <th><?= $lang === 'en' ? 'Status' : 'Статус' ?></th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($fav_lots as $fl):
                            $atype = $fl['auction_type'] ?? 'classic';
                            $btn_url = match($atype) {
                                'scandinavian' => 'lot_scandinavian.php?id='.(int)$fl['id'],
                                'closed'       => 'lot_closed.php?id='.(int)$fl['id'],
                                'quotation'    => 'lot_quotation.php?id='.(int)$fl['id'],
                                'proposal'     => 'lot_proposal.php?id='.(int)$fl['id'],
                                'descending'   => 'lot_descending.php?id='.(int)$fl['id'],
                                default        => 'lot_details.php?id='.(int)$fl['id'],
                            };
                        ?>
                        <tr>
                            <td><a href="<?= htmlspecialchars($btn_url) ?>" style="color:#e2e8f0;text-decoration:none;font-weight:700;"><?= htmlspecialchars($fl['title'] ?? '—') ?></a></td>
                            <td><?= htmlspecialchars($atype) ?></td>
                            <td><?= number_format((float)($fl['price'] ?? 0), 0, '.', ' ') ?>&nbsp;₽</td>
                            <td><?= htmlspecialchars($fl['auction_status'] ?? '') ?></td>
                            <td><a href="<?= htmlspecialchars($btn_url) ?>" class="btn btn-primary" style="padding:6px 12px;font-size:12px;text-decoration:none;"><?= $lang === 'en' ? 'Open' : 'Открыть' ?></a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color:var(--dim);"><?= $lang === 'en' ? 'No favorited auctions yet. Tap the star on any lot to save it here.' : 'Пока нет отмеченных аукционов. Нажмите звёздочку на любом лоте, чтобы сохранить его здесь.' ?></p>
                <?php endif; ?>
            </div>
            <div class="tab-pane" id="tab-fav-torgi">
                <?php if (count($fav_torgi) > 0): ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th><?= $lang === 'en' ? 'Lot' : 'Лот' ?></th>
                            <th><?= $lang === 'en' ? 'Region' : 'Регион' ?></th>
                            <th><?= $lang === 'en' ? 'Price' : 'Цена' ?></th>
                            <th><?= $lang === 'en' ? 'Status' : 'Статус' ?></th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($fav_torgi as $ft): ?>
                        <tr>
                            <td><a href="torgi_view.php?id=<?= (int)$ft['id'] ?>" style="color:#e2e8f0;text-decoration:none;font-weight:700;"><?= htmlspecialchars($ft['title'] ?? '—') ?></a></td>
                            <td><?= htmlspecialchars($ft['region'] ?? '') ?></td>
                            <td><?= number_format((float)($ft['price'] ?? 0), 0, '.', ' ') ?>&nbsp;₽</td>
                            <td><?= htmlspecialchars($ft['status'] ?? '') ?></td>
                            <td><a href="torgi_view.php?id=<?= (int)$ft['id'] ?>" class="btn btn-primary" style="padding:6px 12px;font-size:12px;text-decoration:none;"><?= $lang === 'en' ? 'Open' : 'Открыть' ?></a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p style="color:var(--dim);"><?= $lang === 'en' ? 'No favorited commission lots yet.' : 'Пока нет отмеченных комиссионных лотов.' ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

        <!-- Блок смены пароля (скрыт по умолчанию) -->
    <div id="password-content" style="display:none;">
        <div class="card">
            <h3 style="margin:0 0 20px;">🔐 <?= $lang === 'en' ? 'Change password' : 'Смена пароля' ?></h3>
            <form method="POST" style="max-width:400px;">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'en' ? 'Current password' : 'Текущий пароль' ?></label>
                    <input type="password" name="old_password" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'en' ? 'New password' : 'Новый пароль' ?></label>
                    <input type="password" name="new_password" class="form-input" required minlength="6">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'en' ? 'Confirm password' : 'Подтвердите пароль' ?></label>
                    <input type="password" name="confirm_password" class="form-input" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary"><?= $lang === 'en' ? 'Change password' : 'Изменить пароль' ?></button>
            </form>
        </div>
    </div>

    <!-- Блок Telegram (скрыт по умолчанию) -->
    <div id="telegram-content" style="display:none;">
        <div class="card">
            <h3 style="margin:0 0 20px;">📱 <?= $lang === 'en' ? 'Telegram link' : 'Привязка Telegram' ?></h3>
            <?php if (!empty($user['telegram_id'])): ?>
                <p style="color:var(--dim); margin-bottom:16px;"><strong>Telegram ID:</strong> <?= htmlspecialchars($user['telegram_id']) ?></p>
                <p style="color:#4ade80; margin-bottom:20px;">✅ <?= $lang === 'en' ? 'Telegram linked successfully' : 'Telegram успешно привязан' ?></p>
                <form method="POST">
                    <input type="hidden" name="action" value="unlink_telegram">
                    <button type="submit" class="btn btn-danger"><?= $lang === 'en' ? 'Unlink Telegram' : 'Отвязать Telegram' ?></button>
                </form>
            <?php else: ?>
                <p style="color:var(--dim); margin-bottom:16px;"><?= $lang === 'en' ? 'Link Telegram to receive auction notifications.' : 'Привяжите Telegram для получения уведомлений о торгах.' ?></p>
                <ol style="color:var(--dim); margin:16px 0; padding-left:20px;">
                    <li><?= $lang === 'en' ? 'Open the bot' : 'Откройте бота' ?> <a href="https://t.me/userinfobot" target="_blank" style="color:var(--accent);">@userinfobot</a></li>
                    <li><?= $lang === 'en' ? 'Copy your Telegram ID' : 'Скопируйте ваш Telegram ID' ?></li>
                    <li><?= $lang === 'en' ? 'Paste it into the field below' : 'Вставьте его в поле ниже' ?></li>
                </ol>
                <form method="POST" style="max-width:400px;">
                    <input type="hidden" name="action" value="link_telegram">
                    <div class="form-group">
                        <label class="form-label">Telegram ID</label>
                        <input type="text" name="telegram_id" class="form-input" placeholder="123456789" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $lang === 'en' ? 'Link Telegram' : 'Привязать Telegram' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- Модалки и скрипты (сохраняем из исходного рабочего файла, без изменений) -->
<!-- Unified topup modal (mirrors torgi_view.php upgrade modal scheme) -->
<div id="modal-topup" class="modal-overlay" onclick="if(event.target===this)closeTopupModal()">
    <div class="modal-box" style="max-width:520px;width:100%;text-align:left;padding:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid #e2e8f0;">
            <h3 style="margin:0;font-size:18px;font-weight:800;color:#0f172a;">💰 <?= $lang === 'en' ? 'Top-up payment' : 'Оплата пополнения' ?></h3>
            <button type="button" onclick="closeTopupModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;line-height:1;">×</button>
        </div>
        <div style="padding:18px 22px;">
            <div id="topup-summary" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:14px;">
                <div style="font-weight:700;color:#0f172a;" id="topup-tariff-name"><?= $lang === 'en' ? 'Account top-up' : 'Пополнение баланса' ?></div>
                <div style="color:#0f172a;font-size:14px;margin-top:4px;" id="topup-amount-line">— ₽</div>
                <div style="color:#64748b;font-size:12px;margin-top:2px;" id="topup-vat-line"></div>
            </div>

            <div style="display:flex;gap:8px;margin-bottom:14px;">
                <button type="button" id="tab-topup-qr" class="tt-tab tt-active" onclick="topupSwitchTab('qr')" style="flex:1;padding:10px 12px;border:1px solid #0ea5e9;background:#eff6ff;color:#0284c7;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer;"><?= $lang === 'en' ? 'Pay by QR' : 'Оплата по QR' ?></button>
                <button type="button" id="tab-topup-receipt" class="tt-tab" onclick="topupSwitchTab('receipt')" style="flex:1;padding:10px 12px;border:1px solid #e2e8f0;background:#fff;color:#475569;border-radius:10px;font-weight:600;font-size:13px;cursor:pointer;"><?= $lang === 'en' ? 'Bank receipt' : 'Банковская квитанция' ?></button>
            </div>

            <div id="topup-pane-qr" style="text-align:center;">
                <img id="topup-qr-img" src="" alt="QR" style="max-width:220px;width:100%;border:1px solid #e2e8f0;border-radius:14px;padding:8px;background:#fff;">
                <div style="color:#64748b;font-size:12px;margin-top:8px;"><?= $lang === 'en' ? 'Scan the QR code in your banking app (SBP).' : 'Отсканируйте QR в приложении банка (СБП).' ?></div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;margin-top:12px;font-size:12px;color:#475569;text-align:left;line-height:1.6;">
                    <div><b><?= $lang === 'en' ? 'Purpose' : 'Назначение' ?>:</b> <span id="topup-qr-purpose"></span></div>
                </div>
            </div>

            <div id="topup-pane-receipt" style="display:none;text-align:center;">
                <p style="color:#475569;font-size:13px;margin:0 0 14px;"><?= $lang === 'en' ? 'Open the printable bank receipt with payment details and a QR code in a new tab.' : 'Откройте банковскую квитанцию с реквизитами и QR-кодом в новой вкладке для печати или сохранения PDF.' ?></p>
                <button type="button" class="btn btn-primary" onclick="topupOpenReceiptTab()" style="width:100%;padding:12px 14px;"><?= $lang === 'en' ? '🧾 Generate receipt' : '🧾 Сформировать квитанцию' ?></button>
            </div>

            <div style="border-top:1px solid #e2e8f0;margin-top:18px;padding-top:14px;">
                <div style="font-weight:700;color:#0f172a;font-size:13px;margin-bottom:8px;"><?= $lang === 'en' ? 'After paying, upload the receipt for review' : 'После оплаты — загрузите подтверждение' ?></div>
                <form id="topup-confirm-form" enctype="multipart/form-data" onsubmit="topupSubmitProof(event)">
                    <input type="hidden" name="action" value="confirm_topup">
                    <input type="hidden" name="amount" id="topup-confirm-amount" value="">
                    <input type="hidden" name="payment_method" id="topup-confirm-method" value="qr">
                    <input type="file" name="payment_proof" id="topup-confirm-file" accept="image/*,application/pdf" required style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;background:#fff;color:#0f172a;margin-bottom:8px;">
                    <textarea name="comment" id="topup-confirm-comment" rows="2" placeholder="<?= $lang === 'en' ? 'Comment (optional)' : 'Комментарий (необязательно)' ?>" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;background:#fff;color:#0f172a;resize:vertical;margin-bottom:10px;"></textarea>
                    <button type="submit" class="btn btn-primary" style="width:100%;padding:11px;font-size:14px;"><?= $lang === 'en' ? 'Submit for review' : 'Отправить на проверку' ?></button>
                </form>
                <div id="topup-confirm-msg" style="margin-top:8px;font-size:13px;"></div>
            </div>
        </div>
    </div>
</div>

<div id="upgradeModal" class="modal">
  <div class="modal-content" style="max-width:500px;width:100%;background:#ffffff;border-radius:20px;padding:24px;position:relative;max-height:90vh;overflow-y:auto;">
    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
      <h3 style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">Повышение статуса</h3>
      <button type="button" onclick="closeModal('upgradeModal')" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">×</button>
    </div>
    <div class="tariff-card" onclick="selectTariff(this)" data-tariff="details"
         style="background:#f8fafc; border:2px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:12px; cursor:pointer;">
      <h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">Отчет по лоту</h3>
      <div class="tariff-price" style="font-size:20px; font-weight:800; color:#0ea5e9; margin-bottom:4px;">1 390 <small style="font-size:11px; font-weight:400; color:#64748b;">₽, в т.ч. НДС 22%</small></div>
      <ul style="margin:0; padding-left:18px; font-size:13px; color:#475569;"><li>Подробный отчет</li><li>Рекомендации эксперта</li><li>PDF на почту</li></ul>
    </div>
    <div class="tariff-card" onclick="selectTariff(this)" data-tariff="responsible"
         style="background:#f8fafc; border:2px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:12px; cursor:pointer;">
      <h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">Повысить статус</h3>
      <div class="tariff-price" style="font-size:20px; font-weight:800; color:#0ea5e9; margin-bottom:4px;">8 000 <small style="font-size:11px; font-weight:400; color:#64748b;">₽, в т.ч. НДС 22%</small></div>
      <ul style="margin:0; padding-left:18px; font-size:13px; color:#475569;"><li>Статус «Ответственный»</li><li>Приоритет в сделках</li><li>Личные рекомендации</li></ul>
    </div>
    <div id="paymentDetails" style="display:none; background:#f8fafc; padding:10px 12px; border-radius:10px; margin:12px 0; font-size:13px; color:#334155;"></div>
    <div class="payment-methods" id="paymentMethods" style="display:none; margin:12px 0;">
      <div style="font-size:12px; color:#64748b; margin-bottom:6px;">Способ оплаты</div>
      <div class="payment-buttons" style="display:flex; gap:8px;">
        <button type="button" onclick="selectPaymentMethod('qr')" id="paymentqr" class="payment-btn selected" style="flex:1; padding:8px 10px; background:#0f172a; border:2px solid #334155; border-radius:8px; color:#e5e7eb; cursor:pointer; font-size:12px;">QR / СБП</button>
        <button type="button" onclick="selectPaymentMethod('receipt')" id="paymentreceipt" class="payment-btn" style="flex:1; padding:8px 10px; background:#0f172a; border:2px solid #334155; border-radius:8px; color:#e5e7eb; cursor:pointer; font-size:12px;">Квитанция</button>
      </div>
    </div>
    <div id="qrblock" class="qr-reg-block" style="display:none;background:#ffffff;padding:16px;border-radius:10px;text-align:center;border:1px solid #e2e8f0;margin-bottom:10px;">
      <img id="qrimage" src="" style="width:180px;height:180px;display:block;margin:0 auto 8px;">
      <div style="font-size:12px;color:#64748b;">ИНН: 7728282160</div>
      <button type="button" onclick="markAsPaid()" style="margin-top:12px;width:100%;padding:10px;border:none;border-radius:8px;background:#0ea5e9;color:#fff;font-weight:700;font-size:13px;cursor:pointer;">✅ Я оплатил — загрузить подтверждение</button>
    </div>
    <div id="receiptblock" class="receipt-reg-block" style="display:none; background:#0f172a; padding:16px; border-radius:10px; color:#cbd5e1; margin-bottom:10px; font-size:13px;">
      <p style="margin:0 0 8px;">Сформируем красивую квитанцию с QR-кодом и всеми реквизитами.</p>
      <button type="button" class="receipt-generate-btn" onclick="generateReceipt()" style="width:100%; padding:10px; background:#0ea5e9; color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer;">🧾 Сформировать квитанцию</button>
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
        <button type="submit" style="width:100%; padding:9px 10px; border:none; border-radius:8px; background:#16a34a; color:#fff; font-weight:700; font-size:13px; cursor:pointer;">📎 Отправить квитанцию</button>
      </form>
    </div>
    <div id="upgradeSuccessBlock" style="display:none; text-align:center; padding:20px 10px;">
      <div style="width:64px;height:64px;border-radius:999px;margin:0 auto 12px;background:#dcfce7;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:32px;">✓</div>
      <div style="font-weight:700;font-size:16px;margin-bottom:6px;color:#022c22;">Спасибо!</div>
      <div style="font-size:14px;color:#334155;margin-bottom:16px;">Ваша заявка рассматривается.</div>
      <button type="button" onclick="closeModal('upgradeModal')" style="padding:9px 16px;border-radius:999px;border:none;background:#0ea5e9;color:#fff;font-weight:600;cursor:pointer;font-size:14px;">Закрыть</button>
    </div>
    <div style="display:flex;gap:8px;margin-top:14px;" id="actionButtons">
      <button type="button" onclick="closeModal('upgradeModal')" style="flex:1;padding:8px;border-radius:10px;border:1px solid #e2e8f0;background:#f9fafb;cursor:pointer;font-size:13px;">Отмена</button>
      <button type="button" onclick="markAsPaid()" style="flex:1;padding:8px;border-radius:10px;border:none;background:#0ea5e9;color:#fff;cursor:pointer;font-size:13px;font-weight:600;">✅ Я оплатил</button>
    </div>
  </div>
</div>

<script>
lucide.createIcons();

// Открытие/закрытие мобильного сайдбара
const mobileToggle = document.getElementById('mobileMenuToggle');
const sidebar = document.getElementById('sidebar');
if (mobileToggle && sidebar) {
    mobileToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        sidebar.classList.toggle('open');
    });
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && sidebar.classList.contains('open') && !sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    });
}

// Элементы для переключения
const mainContent = document.getElementById('main-content');
const passwordContent = document.getElementById('password-content');
const telegramContent = document.getElementById('telegram-content');
const favoritesContent = document.getElementById('favorites-content');

// Обработчик для "Пароль"
const passwordLink = document.getElementById('password-tab-link');
if (passwordLink && mainContent && passwordContent && telegramContent) {
    passwordLink.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
        this.classList.add('active');
        mainContent.style.display = 'none';
        passwordContent.style.display = 'block';
        telegramContent.style.display = 'none';
        if (favoritesContent) favoritesContent.style.display = 'none';
        if (sidebar.classList.contains('open')) sidebar.classList.remove('open');
    });
}

// Обработчик для "Telegram"
const telegramLink = document.getElementById('telegram-tab-link');
if (telegramLink && mainContent && passwordContent && telegramContent) {
    telegramLink.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
        this.classList.add('active');
        mainContent.style.display = 'none';
        passwordContent.style.display = 'none';
        telegramContent.style.display = 'block';
        if (favoritesContent) favoritesContent.style.display = 'none';
        if (sidebar.classList.contains('open')) sidebar.classList.remove('open');
    });
}

// Favorites tab handler
const favoritesLink = document.getElementById('favorites-tab-link');
if (favoritesLink && mainContent && favoritesContent) {
    favoritesLink.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
        this.classList.add('active');
        mainContent.style.display = 'none';
        if (passwordContent) passwordContent.style.display = 'none';
        if (telegramContent) telegramContent.style.display = 'none';
        favoritesContent.style.display = 'block';
        if (sidebar.classList.contains('open')) sidebar.classList.remove('open');
    });
}

// Возврат к основному контенту при клике на "Кабинет"
document.querySelectorAll('.nav-item').forEach(item => {
    if (item.getAttribute('href') === 'profile.php' || (item.innerText && item.innerText.includes('Кабинет'))) {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            mainContent.style.display = 'block';
            passwordContent.style.display = 'none';
            telegramContent.style.display = 'none';
            if (favoritesContent) favoritesContent.style.display = 'none';
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            this.classList.add('active');
            if (sidebar.classList.contains('open')) sidebar.classList.remove('open');
        });
    }
});

// Переключение вкладок в блоках (торги, скандинавские, комиссионная)
document.querySelectorAll('.tabs .tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.dataset.tab;
        const parentCard = this.closest('.card');
        parentCard.querySelectorAll('.tabs .tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        parentCard.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
        parentCard.querySelector(`#tab-${tabId}`).classList.add('active');
    });
});

// Остальные функции (openModal, closeModal, selectTariff, topup и т.д.) — из исходника
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

const USER_ID = <?= (int)$user_id ?>;
const USERNAME = '<?= addslashes($user['username']) ?>';
window.__LANG = '<?= $lang === 'en' ? 'en' : 'ru' ?>';
let selectedAmt = 0;
let currentMethod = 'qr';
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
function topupOpen() {
    const amount = getAmount();
    if (!amount || amount < 7000) { setMsg(window.__LANG === 'en' ? 'Minimum top-up is RUB 7,000' : 'Минимальная сумма — 7 000 ₽'); return; }
    if (amount % 500 !== 0) { setMsg(window.__LANG === 'en' ? 'Amount must be a multiple of 500' : 'Сумма должна быть кратной 500 ₽'); return; }
    if (amount > 500000) { setMsg(window.__LANG === 'en' ? 'Maximum 500,000 RUB per top-up' : 'Максимум 500 000 ₽ за раз'); return; }
    setMsg('', '');
    // Render modal contents
    const vat = Math.round(amount * 22 / 122);
    const purpose = (window.__LANG === 'en')
        ? `Account top-up ID${USER_ID} ${USERNAME}, RUB ${amount.toLocaleString('en-US')}, incl. VAT 22% RUB ${vat.toLocaleString('en-US')}`
        : `Пополнение счёта ID${USER_ID} ${USERNAME}, ${amount.toLocaleString('ru-RU')} ₽, в т.ч. НДС 22% — ${vat.toLocaleString('ru-RU')} ₽`;
    const amtFmt = (window.__LANG === 'en')
        ? `RUB ${amount.toLocaleString('en-US')}`
        : `${amount.toLocaleString('ru-RU')} ₽`;
    document.getElementById('topup-amount-line').textContent = amtFmt;
    document.getElementById('topup-vat-line').textContent = (window.__LANG === 'en')
        ? `Incl. VAT 22%: RUB ${vat.toLocaleString('en-US')}`
        : `В т.ч. НДС 22%: ${vat.toLocaleString('ru-RU')} ₽`;
    document.getElementById('topup-qr-purpose').textContent = purpose;
    const qrData = `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${amount}00|Purpose=${encodeURIComponent(purpose)}`;
    document.getElementById('topup-qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?size=440x440&data=' + encodeURIComponent(qrData);
    document.getElementById('topup-confirm-amount').value = amount;
    document.getElementById('topup-confirm-method').value = 'qr';
    document.getElementById('topup-confirm-msg').textContent = '';
    topupSwitchTab('qr');
    document.getElementById('modal-topup').classList.add('open');
    // Persist a topup intent on the server (best-effort, mirrors old flow)
    const fd = new FormData();
    fd.append('action', 'topup');
    fd.append('amount', amount);
    fd.append('payment_method', 'qr');
    fetch('topup_handler.php', { method: 'POST', body: fd }).catch(() => {});
}
function closeTopupModal() {
    document.getElementById('modal-topup').classList.remove('open');
}
function topupSwitchTab(tab) {
    const isQR = tab === 'qr';
    const btnQR = document.getElementById('tab-topup-qr');
    const btnRC = document.getElementById('tab-topup-receipt');
    if (btnQR && btnRC) {
        btnQR.style.background = isQR ? '#eff6ff' : '#fff';
        btnQR.style.borderColor = isQR ? '#0ea5e9' : '#e2e8f0';
        btnQR.style.color = isQR ? '#0284c7' : '#475569';
        btnQR.style.fontWeight = isQR ? '700' : '600';
        btnRC.style.background = !isQR ? '#eff6ff' : '#fff';
        btnRC.style.borderColor = !isQR ? '#0ea5e9' : '#e2e8f0';
        btnRC.style.color = !isQR ? '#0284c7' : '#475569';
        btnRC.style.fontWeight = !isQR ? '700' : '600';
    }
    document.getElementById('topup-pane-qr').style.display = isQR ? 'block' : 'none';
    document.getElementById('topup-pane-receipt').style.display = isQR ? 'none' : 'block';
    document.getElementById('topup-confirm-method').value = tab;
}
function topupOpenReceiptTab() {
    const amount = parseInt(document.getElementById('topup-confirm-amount').value, 10) || getAmount();
    if (!amount || amount < 7000 || amount % 500 !== 0) {
        document.getElementById('topup-confirm-msg').textContent = (window.__LANG === 'en')
            ? 'Choose an amount: minimum 7,000 RUB, multiples of 500.'
            : 'Выберите сумму: минимум 7 000 ₽, кратно 500.';
        document.getElementById('topup-confirm-msg').style.color = '#ef4444';
        return;
    }
    const langParam = (window.__LANG === 'en') ? '&lang=en' : '';
    window.open('receipt_topup.php?amount=' + amount + langParam, '_blank', 'noopener,noreferrer');
}
function topupSubmitProof(ev) {
    ev.preventDefault();
    const form = document.getElementById('topup-confirm-form');
    const amount = parseInt(document.getElementById('topup-confirm-amount').value, 10) || 0;
    const file = document.getElementById('topup-confirm-file').files[0];
    const msg = document.getElementById('topup-confirm-msg');
    if (!amount) { msg.style.color = '#ef4444'; msg.textContent = (window.__LANG==='en')?'Choose an amount':'Выберите сумму'; return; }
    if (!file) { msg.style.color = '#ef4444'; msg.textContent = (window.__LANG==='en')?'Attach a payment proof file':'Прикрепите файл подтверждения'; return; }
    msg.style.color = '#475569';
    msg.textContent = (window.__LANG==='en') ? 'Uploading…' : 'Отправляем…';
    const fd = new FormData(form);
    fetch('topup_handler.php', { method: 'POST', body: fd })
        .then(r => r.json().catch(() => ({success:false, msg:'Bad response'})))
        .then(d => {
            if (d.success) {
                msg.style.color = '#16a34a';
                msg.textContent = (window.__LANG==='en')
                    ? 'Submitted. Your balance will be credited after review.'
                    : 'Отправлено. Баланс пополнится после проверки.';
                setTimeout(() => { closeTopupModal(); location.reload(); }, 1800);
            } else {
                msg.style.color = '#ef4444';
                msg.textContent = d.msg || ((window.__LANG==='en')?'Error':'Ошибка');
            }
        })
        .catch(() => {
            msg.style.color = '#ef4444';
            msg.textContent = (window.__LANG==='en') ? 'Network error' : 'Ошибка связи';
        });
}
function withdrawApplication(appId) {
    if (!confirm('Вы уверены, что хотите отозвать заявку?')) return;
    const fd = new FormData();
    fd.append('action', 'withdraw');
    fd.append('application_id', appId);
    fetch('application_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert('Заявка отозвана');
                location.reload();
            } else {
                alert(d.msg || 'Ошибка');
            }
        })
        .catch(() => alert('Ошибка связи'));
}
// Тарифы, QR, квитанция
let selectedTariff = null;
let currentAmount = 0;
let currentTariffName = '';
function selectTariff(el) {
    document.querySelectorAll('.tariff-card').forEach(c => { c.style.borderColor = '#e2e8f0'; c.style.background = '#f8fafc'; });
    el.style.borderColor = '#0ea5e9';
    el.style.background = '#eff6ff';
    selectedTariff = el.dataset.tariff;
    if (selectedTariff === 'details') {
        currentAmount = 1390;
        currentTariffName = 'Отчет по лоту';
    } else {
        currentAmount = 8000;
        currentTariffName = 'Статус Ответственный';
    }
    const vat = Math.round(currentAmount * 22 / 122);
    const pd = document.getElementById('paymentDetails');
    pd.style.display = 'block';
    pd.innerHTML = `<div style="font-weight:600;">${currentTariffName}</div><div>${currentAmount.toLocaleString('ru-RU')} ₽, в т.ч. НДС ${vat.toLocaleString('ru-RU')} ₽ (22%)</div>`;
    document.getElementById('paymentMethods').style.display = 'block';
    document.getElementById('receipttariff').value = currentTariffName;
    document.getElementById('receiptamount').value = currentAmount;
    const qrData = `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${currentAmount}00|Purpose=${currentTariffName}, ${currentAmount} ₽, в т.ч. НДС 22%`;
    document.getElementById('qrimage').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(qrData);
    document.getElementById('qrblock').style.display = 'block';
    document.getElementById('receiptblock').style.display = 'none';
    document.getElementById('receiptFormBlock').style.display = 'none';
    document.getElementById('actionButtons').style.display = 'flex';
}
function selectPaymentMethod(method) {
    document.getElementById('paymentqr').classList.remove('selected');
    document.getElementById('paymentreceipt').classList.remove('selected');
    document.getElementById('payment' + method).classList.add('selected');
    document.getElementById('qrblock').style.display = method === 'qr' ? 'block' : 'none';
    document.getElementById('receiptblock').style.display = method === 'receipt' ? 'block' : 'none';
}
function generateReceipt() {
    if (!selectedTariff || !currentAmount) return;
    const vat = Math.round(currentAmount * 22 / 122);
    const qrData = `ST00012|Name=ООО Форсаж|PersonalAcc=40702810101500033019|BankName=ООО Банк Точка|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum=${currentAmount}00|Purpose=${currentTariffName}, ${currentAmount} ₽, в т.ч. НДС 22%`;
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(qrData);
    const w = window.open('', 'blank', 'width=700,height=800,scrollbars=yes,resizable=yes');
    if (!w) return;
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Квитанция</title><style>body{font-family:Inter,Arial,sans-serif;padding:40px;background:#f8fafc;margin:0;}.receipt{max-width:520px;margin:0 auto;background:#fff;border-radius:20px;padding:26px;box-shadow:0 10px 25px rgba(0,0,0,0.1);border:1px solid #e2e8f0;}h1{font-size:22px;font-weight:800;margin:0 0 18px;}.details{margin:14px 0;line-height:1.6;color:#334155;font-size:13px;}.qr{text-align:center;margin:18px 0;}.footer{font-size:11px;color:#64748b;margin-top:16px;text-align:center;}button{display:block;width:100%;padding:10px;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;margin-top:18px;}</style></head><body><div class="receipt"><h1>Квитанция об оплате</h1><div class="details"><p><strong>Получатель:</strong> ООО «Форсаж»</p><p><strong>Адрес:</strong> 121059, г. Москва, ул. Киевская, д.14, офис 2А</p><p><strong>ИНН / КПП:</strong> 7728282160 / 773001001</p><p><strong>Счёт:</strong> 40702810101500033019</p><p><strong>Корр. счёт:</strong> 30101810745374525104</p><p><strong>БИК:</strong> 044525104</p><p><strong>Назначение платежа:</strong> ${currentTariffName}</p><p><strong>Сумма:</strong> ${currentAmount.toLocaleString('ru-RU')} ₽, в т.ч. НДС ${vat.toLocaleString('ru-RU')} ₽ (22%)</p></div><div class="qr"><img src="${qrUrl}" style="max-width:200px;"><p>QR для оплаты</p></div><div class="footer"><p>${new Date().toLocaleDateString('ru-RU')}</p></div><button onclick="window.print()">Распечатать</button></div></body></html>`);
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
document.addEventListener('DOMContentLoaded', function () {
    const upgradeForm = document.getElementById('upgradeReceiptForm');
    if (upgradeForm) {
        upgradeForm.addEventListener('submit', function (e) {
            const file = upgradeForm.querySelector('input[type="file"][name="receipt_file"]');
            const errP = document.querySelector('#receiptFormBlock p');
            if (!file || !file.files || file.files.length === 0) {
                e.preventDefault();
                if (file) file.style.border = '2px solid #dc2626';
                if (errP) {
                    errP.textContent = '⚠️ Прикрепите файл квитанции — без него отправка невозможна.';
                    errP.style.color = '#dc2626';
                    errP.style.fontWeight = '700';
                }
                return false;
            }
            if (file) file.style.border = '1px solid #e2e8f0';
            if (errP) {
                errP.textContent = 'Загрузите скриншот или фото квитанции об оплате.';
                errP.style.color = '#374151';
                errP.style.fontWeight = '600';
            }
        });
    }
});
</script>

</body>
</html>
<?php ob_end_flush(); ?>