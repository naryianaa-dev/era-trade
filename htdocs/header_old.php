<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Язык
if (!isset($_SESSION['lang'])) {
    $accept_lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'ru';
    $_SESSION['lang'] = (substr($accept_lang, 0, 2) === 'ru') ? 'ru' : 'en';
}
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'ru';
}
$lang = $_SESSION['lang'];

$t = [
    'ru' => [
        'login' => 'ВХОД', 
        'logout' => 'ВЫЙТИ', 
        'msc' => 'МСК', 
        'main' => 'ГЛАВНАЯ', 
        'auctions' => 'ТОРГИ', 
        'comm' => 'КОМИССИОННАЯ ПРОДАЖА'
    ],
    'en' => [
        'login' => 'SIGN IN', 
        'logout' => 'SIGN OUT', 
        'msc' => 'MSK', 
        'main' => 'HOME', 
        'auctions' => 'AUCTIONS', 
        'comm' => 'COMMISSION SALES'
    ],
];

$is_auth    = !empty($_SESSION['user_id']);
$user_name  = $_SESSION['user_name'] ?? '';
$user_bal   = $_SESSION['user_balance'] ?? 0;
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background: #fff; 
            display: flex; 
            flex-direction: column;
            padding-top: 100px; /* Для fixed header */
        }
        
        @media (max-width: 768px) {
            body { padding-top: 80px; }
        }
        
        @media (max-width: 480px) {
            body { padding-top: 70px; }
        }
        
        /* Шапка */
        header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 100px; 
            background: #e5e4e2; 
            display: flex;
            justify-content: space-between; 
            align-items: center;
            padding: 0 5%; 
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            z-index: 1000;
            flex-shrink: 0;
        }
        
        .logo-img { height: 45px; }
        
        .nav-menu { display: flex; gap: 25px; align-items: center; }
        .nav-link { 
            text-decoration: none; 
            color: #1e293b; 
            font-weight: 800; 
            font-size: 13px; 
            cursor: pointer; 
            white-space: nowrap;
        }
        .nav-link:hover { color: #0088cc; }
        .nav-link.active { color: #0088cc; }
        
        .header-right { display: flex; align-items: center; gap: 15px; }
        
        .msc-box {
            display: flex; align-items: center; gap: 8px;
            background: #fff; padding: 6px 14px; border-radius: 50px;
            border: 1px solid #d1d5db; font-weight: 800; font-size: 13px;
        }
        .dot {
            width: 7px; height: 7px; background: #22c55e; border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.5; } }
        
        .lang-switcher { display: flex; gap: 4px; }
        .lang-btn {
            text-decoration: none; color: #64748b; font-size: 11px; font-weight: 800;
            padding: 5px 10px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff;
        }
        .lang-btn.active { background: #1e293b; color: #fff; }
        
        .btn-login {
            background: #0088cc; color: #fff; border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 900;
            cursor: pointer; font-size: 13px;
        }
        .btn-login:hover { background: #0077b3; }
        
        .burger-trigger { display: none; cursor: pointer; }
        
        @media (max-width: 1100px) {
            .nav-menu { display: none; }
            .burger-trigger { display: block; }
            .lang-switcher { display: none; } /* Скрыть в хедере на моб */
        }
        
        @media (max-width: 768px) {
            header {
                height: 80px;
                padding: 0 3%;
            }
            .logo-img { height: 35px; }
            .msc-box { font-size: 11px; padding: 4px 10px; }
            .btn-login { padding: 8px 16px; font-size: 12px; }
            .header-right { gap: 8px; }
        }
        
        @media (max-width: 480px) {
            header {
                height: 70px;
                padding: 0 2%;
            }
            .logo-img { height: 30px; }
            .msc-box { font-size: 10px; padding: 3px 8px; }
            .btn-login { padding: 6px 12px; font-size: 11px; }
        }
        
        /* МОДАЛКА С ЗАГРУЗКОЙ ФАЙЛОВ */
        #auth-overlay {
            display: none; 
            position: fixed; 
            inset: 0;
            background: rgba(0,0,0,0.75); 
            z-index: 2000;
            justify-content: center; 
            align-items: center;
            backdrop-filter: blur(6px); 
            padding: 16px;
        }
        #auth-overlay.open { display: flex; }

        .auth-box {
            background: #fff; 
            width: 100%; 
            max-width: 500px;
            border-radius: 20px; 
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.25);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .auth-tabs {
            display: flex; 
            border-bottom: 2px solid #f1f5f9;
            flex-shrink: 0;
        }
        .auth-tab-btn {
            flex: 1; 
            padding: 18px; 
            border: none; 
            background: transparent;
            font-weight: 800; 
            font-size: 14px; 
            cursor: pointer;
            color: #94a3b8; 
            border-bottom: 3px solid transparent;
        }
        .auth-tab-btn.active { color: #0088cc; border-bottom-color: #0088cc; }

        .auth-body { 
            padding: 24px; 
            overflow-y: auto;
        }

        .auth-close {
            position: absolute; 
            top: 16px; 
            right: 20px;
            background: none; 
            border: none; 
            font-size: 22px;
            color: #94a3b8; 
            cursor: pointer; 
            z-index: 10;
        }

        #auth-msg { 
            min-height: 20px; 
            font-size: 13px; 
            font-weight: 700; 
            text-align: center; 
            margin-bottom: 14px; 
            color: #ef4444; 
        }

        .auth-field, .auth-file {
            width: 100%; 
            padding: 12px 16px; 
            border-radius: 10px;
            border: 1.5px solid #e2e8f0; 
            background: #f8fafc;
            font-size: 14px; 
            margin-bottom: 12px; 
            outline: none;
        }
        .auth-field:focus { border-color: #0088cc; }

        /* БЛОК ЗАГРУЗКИ ФАЙЛОВ - ТУТ ОНИ ЕСТЬ, БЛЯДЬ! */
        .file-section {
            background: #f1f5f9; 
            padding: 16px; 
            border-radius: 12px;
            margin: 15px 0; 
            border: 2px dashed #0088cc;
        }
        .file-section-title {
            display: flex; 
            align-items: center; 
            gap: 5px; 
            margin-bottom: 15px;
            font-weight: 800; 
            color: #0088cc; 
            font-size: 13px;
        }
        .file-label {
            display: block; 
            font-weight: 700; 
            font-size: 12px;
            color: #1e293b; 
            margin-bottom: 5px;
        }
        .file-hint {
            font-size: 11px; 
            color: #64748b; 
            margin-top: 10px;
            text-align: right;
        }
        .auth-file {
            background: #fff; 
            padding: 10px; 
            cursor: pointer;
            margin-bottom: 15px;
        }
        .auth-file:hover { border-color: #0088cc; }

        .auth-submit {
            width: 100%; 
            padding: 14px; 
            background: #0088cc;
            color: #fff; 
            border: none; 
            border-radius: 10px;
            font-weight: 900; 
            font-size: 15px; 
            cursor: pointer;
        }
        .auth-submit:hover { background: #0077b3; }

        /* Мобильное меню */
        #mobileMenu {
            position: fixed; 
            top: 0; 
            right: -100%; 
            width: 100%; 
            height: 100%;
            background: #fff; 
            z-index: 2001; 
            transition: right 0.4s;
            display: flex; 
            flex-direction: column; 
            align-items: center;
            justify-content: center; 
            gap: 20px;
        }
        #mobileMenu.open { right: 0; }
    </style>
</head>
<body>

<header>
    <a href="index.php"><img src="logo-forsage-modified.png" class="logo-img" alt="Форсаж"></a>

    <nav class="nav-menu">
    <a href="index.php" class="nav-link <?= $current_page == 'index.php' ? 'active' : '' ?>"><?= $t[$lang]['main'] ?></a>
    <a href="reestr.php" class="nav-link <?= $current_page == 'reestr.php' ? 'active' : '' ?>"><?= $t[$lang]['auctions'] ?></a>
    <a href="torgi_list.php" class="nav-link <?= $current_page == 'torgi_list.php' ? 'active' : '' ?>"><?= $t[$lang]['comm'] ?></a>
</nav>

    <div class="header-right">
        <div class="msc-box">
            <span class="dot"></span>
            <span id="msc_val">00:00:00</span>
            <?= $t[$lang]['msc'] ?>
        </div>

        <div style="display:flex;gap:10px;align-items:center;">
            <div class="lang-switcher">
                <a href="?lang=ru" class="lang-btn <?= $lang === 'ru' ? 'active' : '' ?>">RU</a>
                <a href="?lang=en" class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>">EN</a>
            </div>

            <?php if ($is_auth): ?>
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="text-align:right;">
                        <div style="font-weight:800; font-size:13px;"><?= htmlspecialchars($user_name) ?></div>
                        <div style="font-size:11px; color:#22c55e;"><?= number_format($user_bal, 2) ?> ₽</div>
                    </div>
                    <a href="profile.php"><i data-lucide="user" size="18" color="#0088cc"></i></a>
                    <a href="logout.php"><i data-lucide="log-out" size="18" color="#ef4444"></i></a>
                </div>
            <?php else: ?>
                <button class="btn-login" onclick="openAuth()"><?= $t[$lang]['login'] ?></button>
            <?php endif; ?>

            <div class="burger-trigger" onclick="toggleMobileMenu()">
                <i data-lucide="menu" size="30"></i>
            </div>
        </div>
    </div>
</header>

<!-- Мобильное меню -->
<div id="mobileMenu">
    <span onclick="toggleMobileMenu()" style="position:absolute;top:30px;right:30px;cursor:pointer;">
        <i data-lucide="x" size="40"></i>
    </span>

    <div class="lang-switcher" style="margin-bottom:15px;">
        <a href="?lang=ru" class="lang-btn <?= $lang === 'ru' ? 'active' : '' ?>" style="font-size:16px;padding:10px 20px;">RU</a>
        <a href="?lang=en" class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>" style="font-size:16px;padding:10px 20px;">EN</a>
    </div>

    <a href="index.php" class="nav-link" style="font-size:22px;" onclick="toggleMobileMenu()"><?= $t[$lang]['main'] ?></a>
    <a href="reestr.php" class="nav-link" style="font-size:22px;" onclick="toggleMobileMenu()"><?= $t[$lang]['auctions'] ?></a>
    <a href="commission.php" class="nav-link" style="font-size:22px;" onclick="toggleMobileMenu()"><?= $t[$lang]['comm'] ?></a>

    <?php if ($is_auth): ?>
        <div style="text-align:center;margin-top:20px;border-top:1px solid #eee;padding-top:20px;width:80%;">
            <div style="font-size:24px;font-weight:900;"><?= htmlspecialchars($user_name) ?></div>
            <div style="color:#22c55e;font-size:20px;margin-bottom:25px;"><?= number_format($user_bal, 2) ?> ₽</div>
            <a href="profile.php" style="color:#0088cc;font-size:18px;border:2px solid #0088cc20;padding:12px 40px;border-radius:12px;display:inline-block;margin-right:10px;">ЛК</a>
            <a href="logout.php" style="color:#ef4444;font-size:18px;border:2px solid #fee2e2;padding:12px 40px;border-radius:12px;display:inline-block;">ВЫЙТИ</a>
        </div>
    <?php else: ?>
        <button class="btn-login" style="padding:15px 50px;font-size:18px;margin-top:10px;"
                onclick="toggleMobileMenu(); setTimeout(openAuth, 300);"><?= $t[$lang]['login'] ?></button>
    <?php endif; ?>
</div>

<?php include 'auth_modal.php'; ?>

<script>
// Часы МСК
(function tickMSK() {
    const el = document.getElementById('msc_val');
    if (el) {
        const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Moscow' }));
        el.textContent = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`;
        setTimeout(tickMSK, 1000);
    }
})();

function toggleMobileMenu() {
    document.getElementById('mobileMenu').classList.toggle('open');
}

lucide.createIcons();
</script>
