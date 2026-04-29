<?php
// telegram_auth.php - Авторизация через Telegram
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// ДАННЫЕ БОТА
$bot_token = '8229318891:AAF-7oKGc6x37WC-vZc7NMJ9dySnaYDjRm8';
$bot_username = 'era_etp_bot';

// Проверка данных от Telegram
function checkTelegramAuthorization($auth_data, $bot_token) {
    $check_hash = $auth_data['hash'];
    unset($auth_data['hash']);
    
    $data_check_arr = [];
    foreach ($auth_data as $key => $value) {
        $data_check_arr[] = $key . '=' . $value;
    }
    sort($data_check_arr);
    
    $data_check_string = implode("\n", $data_check_arr);
    $secret_key = hash('sha256', $bot_token, true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);
    
    if (strcmp($hash, $check_hash) !== 0) {
        return false;
    }
    
    if ((time() - $auth_data['auth_date']) > 86400) {
        return false;
    }
    
    return true;
}

// Обработка callback от Telegram
if (isset($_GET['id']) && isset($_GET['hash'])) {
    $auth_data = [
        'id' => $_GET['id'],
        'first_name' => $_GET['first_name'] ?? '',
        'last_name' => $_GET['last_name'] ?? '',
        'username' => $_GET['username'] ?? '',
        'photo_url' => $_GET['photo_url'] ?? '',
        'auth_date' => $_GET['auth_date'],
        'hash' => $_GET['hash']
    ];
    
    if (!checkTelegramAuthorization($auth_data, $bot_token)) {
        die('Ошибка проверки данных Telegram');
    }
    
    try {
        $telegram_id = (int)$auth_data['id'];
        $telegram_username = $auth_data['username'] ?: 'user_' . $telegram_id;
        $full_name = trim(($auth_data['first_name'] ?? '') . ' ' . ($auth_data['last_name'] ?? ''));
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            $pdo->prepare("UPDATE users SET telegram_username = ?, last_login = NOW() WHERE id = ?")
                ->execute([$telegram_username, $user['id']]);
            
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO users 
                (username, telegram_id, telegram_username, full_name, entity_type, 
                 user_status, balance, created_at, last_login)
                VALUES (?, ?, ?, ?, 'individual', 'base', 0, NOW(), NOW())
            ");
            $stmt->execute([
                $telegram_username,
                $telegram_id,
                $telegram_username,
                $full_name
            ]);
            
            $new_user_id = $pdo->lastInsertId();
            
            $_SESSION['user_id'] = $new_user_id;
            $_SESSION['username'] = $telegram_username;
        }
        
        header('Location: profile.php?telegram_auth=success');
        exit;
        
    } catch (Exception $e) {
        die('Ошибка БД: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход через Telegram</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 24px;
            padding: 40px;
            max-width: 400px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        h1 {
            font-size: 24px;
            margin-bottom: 8px;
            color: #1e293b;
        }
        p {
            color: #64748b;
            margin-bottom: 32px;
            font-size: 14px;
        }
        .telegram-widget {
            margin: 0 auto 20px;
        }
        .back-link {
            display: inline-block;
            color: #3b82f6;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .back-link:hover {
            background: #eff6ff;
        }
    </style>
    <script async src="https://telegram.org/js/telegram-widget.js?22"
            data-telegram-login="era_etp_bot"
            data-size="large"
            data-auth-url="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"
            data-request-access="write">
    </script>
</head>
<body>
    <div class="container">
        <h1>🔐 Вход через Telegram</h1>
        <p>Нажмите кнопку ниже для авторизации</p>
        
        <div class="telegram-widget"></div>
        
        <a href="index.php" class="back-link">← Вернуться на главную</a>
    </div>
</body>
</html>
