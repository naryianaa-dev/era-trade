<?php
// header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Получаем баланс пользователя, если он авторизован
$user_balance = null;
$username = null;
$user_type = null;

if (!empty($_SESSION['user_id'])) {
    require_once 'db.php';
    $stmt = $pdo->prepare("SELECT username, balance, user_type FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_data) {
        $username = $user_data['username'];
        $user_balance = $user_data['balance'];
        $user_type = $user_data['user_type'];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERA ETP — Электронная торговая площадка</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Шапка сайта */
        .site-header {
            background: #0f172a;
            border-bottom: 1px solid #1e293b;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo-icon i {
            width: 24px;
            height: 24px;
            color: white;
        }
        
        .logo-text {
            font-size: 20px;
            font-weight: 800;
            color: white;
        }
        
        .logo-text span {
            color: #3b82f6;
        }
        
        /* Навигация */
        .nav-menu {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .nav-link {
            padding: 8px 16px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-link i {
            width: 18px;
            height: 18px;
        }
        
        .nav-link:hover {
            background: #1e293b;
            color: white;
        }
        
        .nav-link.active {
            background: #1e293b;
            color: #3b82f6;
        }
        
        /* Блок пользователя */
        .user-section {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .balance-card {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-radius: 12px;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #334155;
        }
        
        .balance-icon {
            background: #22c55e20;
            padding: 6px;
            border-radius: 8px;
        }
        
        .balance-icon i {
            width: 20px;
            height: 20px;
            color: #22c55e;
        }
        
        .balance-info {
            text-align: right;
        }
        
        .balance-label {
            font-size: 10px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .balance-amount {
            font-size: 18px;
            font-weight: 800;
            color: #22c55e;
            line-height: 1.2;
        }
        
        .balance-currency {
            font-size: 12px;
            font-weight: 500;
            color: #94a3b8;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #1e293b;
            padding: 6px 12px;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .user-menu:hover {
            background: #334155;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #3b82f6, #1e40af);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-avatar i {
            width: 18px;
            height: 18px;
            color: white;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: white;
        }
        
        .user-type {
            font-size: 10px;
            color: #94a3b8;
        }
        
        .logout-btn {
            padding: 8px 16px;
            background: #ef4444;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn i {
            width: 16px;
            height: 16px;
        }
        
        .logout-btn:hover {
            background: #dc2626;
        }
        
        .login-btn {
            padding: 10px 24px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .login-btn:hover {
            background: #2563eb;
        }
        
        /* Адаптивность */
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                padding: 12px 16px;
            }
            
            .nav-menu {
                order: 3;
                width: 100%;
                justify-content: center;
            }
            
            .user-section {
                width: 100%;
                justify-content: space-between;
            }
            
            .balance-card {
                flex: 1;
            }
            
            .balance-amount {
                font-size: 16px;
            }
        }
        
        @media (max-width: 480px) {
            .user-section {
                flex-wrap: wrap;
            }
            
            .balance-card {
                width: 100%;
                justify-content: space-between;
            }
            
            .user-menu {
                flex: 1;
                justify-content: center;
            }
        }
        
        /* Основной контент */
        main {
            flex: 1;
        }
    </style>
</head>
<body>

<header class="site-header">
    <div class="header-container">
        <a href="index.php" class="logo">
            <div class="logo-icon">
                <i data-lucide="zap"></i>
            </div>
            <div class="logo-text">ERA <span>ETP</span></div>
        </a>
        
        <nav class="nav-menu">
            <a href="index.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="home"></i> Главная
            </a>
            <a href="reestr.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reestr.php' ? 'active' : '' ?>">
                <i data-lucide="gavel"></i> Торги
            </a>
            <a href="profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>">
                <i data-lucide="user"></i> Кабинет
            </a>
            <?php if (!empty($_SESSION['user_id']) && in_array($user_type, ['organizer', 'admin'])): ?>
            <a href="add_lot.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'add_lot.php' ? 'active' : '' ?>">
                <i data-lucide="plus-circle"></i> Создать лот
            </a>
            <?php endif; ?>
        </nav>
        
        <div class="user-section">
            <?php if (!empty($_SESSION['user_id']) && $user_balance !== null): ?>
                <div class="balance-card">
                    <div class="balance-icon">
                        <i data-lucide="wallet"></i>
                    </div>
                    <div class="balance-info">
                        <div class="balance-label">Баланс</div>
                        <div class="balance-amount">
                            <?= number_format($user_balance, 0, '.', ' ') ?>
                            <span class="balance-currency">₽</span>
                        </div>
                    </div>
                </div>
                
                <div class="user-menu" onclick="window.location.href='profile.php'">
                    <div class="user-avatar">
                        <i data-lucide="user"></i>
                    </div>
                    <div>
                        <div class="user-name"><?= htmlspecialchars($username) ?></div>
                        <div class="user-type">
                            <?php
                            $type_labels = [
                                'admin' => 'Администратор',
                                'organizer' => 'Организатор',
                                'responsible' => 'Ответственный',
                                'уважаемый' => 'Уважаемый'
                            ];
                            echo $type_labels[$user_type] ?? 'Пользователь';
                            ?>
                        </div>
                    </div>
                </div>
                
                <a href="logout.php" class="logout-btn">
                    <i data-lucide="log-out"></i> Выйти
                </a>
                
            <?php else: ?>
                <a href="login.php" class="login-btn">
                    <i data-lucide="log-in"></i> Войти
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<script>
    lucide.createIcons();
</script>