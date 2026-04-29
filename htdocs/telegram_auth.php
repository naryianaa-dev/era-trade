<?php
// telegram_auth.php — Авторизация через Telegram Login Widget.
//
// Конфигурация бота берётся из локального файла tg_config.php (он в .gitignore
// и не коммитится в публичный репозиторий) или из переменных окружения
// TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_USERNAME.
//
// Чтобы настроить:
//   1) Создайте бота через @BotFather и получите токен и @username.
//   2) В @BotFather → /setdomain → укажите домен сайта (forsage.ct.ws и т.п.).
//   3) Создайте htdocs/tg_config.php со строками:
//        <?php
//        $bot_token    = '123456:AA...';
//        $bot_username = 'my_login_bot';   // без @
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Diagnostic logging — записываем каждый callback от Telegram (а также любые
   ошибки) в файл рядом со скриптом, чтобы можно было разобрать сбои авторизации
   на iOS / в in-app браузерах. Файл нужно периодически удалять. */
$tg_log = __DIR__ . '/tg_auth.log';
$log = function($msg) use ($tg_log) {
    @file_put_contents(
        $tg_log,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
        FILE_APPEND | LOCK_EX
    );
};
$log('REQ ' . ($_SERVER['REQUEST_METHOD'] ?? '?')
    . ' ' . ($_SERVER['REQUEST_URI'] ?? '?')
    . ' UA=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120)
    . ' REF=' . substr($_SERVER['HTTP_REFERER'] ?? '', 0, 120));
if (!empty($_GET)) {
    $safe_get = $_GET;
    if (isset($safe_get['hash'])) { $safe_get['hash'] = substr($safe_get['hash'], 0, 8) . '…'; }
    $log('GET ' . json_encode($safe_get, JSON_UNESCAPED_UNICODE));
}
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($log) {
    $log("PHPERR [$errno] $errstr at $errfile:$errline");
    return false;
});
set_exception_handler(function($e) use ($log) {
    $log('EXCEPTION ' . get_class($e) . ': ' . $e->getMessage()
        . ' at ' . $e->getFile() . ':' . $e->getLine());
});

// --- Загрузка конфигурации бота ---------------------------------------------
$bot_token    = '';
$bot_username = '';

$config_path = __DIR__ . '/tg_config.php';
if (is_file($config_path)) {
    require $config_path; // ожидается, что выставит $bot_token и $bot_username
}
if (!$bot_token)    { $bot_token    = getenv('TELEGRAM_BOT_TOKEN')    ?: ''; }
if (!$bot_username) { $bot_username = getenv('TELEGRAM_BOT_USERNAME') ?: ''; }

$is_configured =
    $bot_token && $bot_username
    && $bot_token    !== 'YOUR_BOT_TOKEN'
    && $bot_username !== 'YOUR_BOT_USERNAME';

// --- Проверка подписи Telegram ----------------------------------------------
function checkTelegramAuthorization(array $auth_data, string $bot_token): bool {
    if (!isset($auth_data['hash'], $auth_data['auth_date'])) return false;
    $check_hash = $auth_data['hash'];
    unset($auth_data['hash']);

    $data_check_arr = [];
    foreach ($auth_data as $key => $value) {
        if ($value === '' || $value === null) continue;
        $data_check_arr[] = $key . '=' . $value;
    }
    sort($data_check_arr);

    $data_check_string = implode("\n", $data_check_arr);
    $secret_key = hash('sha256', $bot_token, true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);

    if (!hash_equals($hash, $check_hash)) {
        return false;
    }
    // Данные не должны быть старше 24 часов.
    if ((time() - (int)$auth_data['auth_date']) > 86400) {
        return false;
    }
    return true;
}

// --- Обработка callback от Telegram -----------------------------------------
if ($is_configured && isset($_GET['id'], $_GET['hash'], $_GET['auth_date'])) {
    require_once 'db.php';
    $auth_data = [
        'id'         => $_GET['id'],
        'first_name' => $_GET['first_name'] ?? '',
        'last_name'  => $_GET['last_name']  ?? '',
        'username'   => $_GET['username']   ?? '',
        'photo_url'  => $_GET['photo_url']  ?? '',
        'auth_date'  => $_GET['auth_date'],
        'hash'       => $_GET['hash'],
    ];

    if (!checkTelegramAuthorization($auth_data, $bot_token)) {
        $log('HASH MISMATCH for id=' . ($auth_data['id'] ?? '?'));
        http_response_code(403);
        die('Ошибка проверки данных Telegram (hash mismatch). Проверьте, что в @BotFather → /setdomain указан домен forsage.ct.ws.');
    }
    $log('HASH OK id=' . $auth_data['id'] . ' username=' . ($auth_data['username'] ?: '-'));

    /* Auto-migrate users table — add columns the Telegram flow expects if
       they don't exist yet. Each ALTER is wrapped separately so a single
       'duplicate column' error doesn't abort the whole migration on plain
       MySQL (which lacks IF NOT EXISTS for ADD COLUMN). */
    $migrations = [
        "ALTER TABLE users ADD COLUMN telegram_id BIGINT NULL",
        "ALTER TABLE users ADD COLUMN telegram_username VARCHAR(64) NULL",
        "ALTER TABLE users ADD COLUMN full_name VARCHAR(255) NULL",
        "ALTER TABLE users ADD COLUMN entity_type VARCHAR(32) NULL DEFAULT 'individual'",
        "ALTER TABLE users ADD COLUMN user_status VARCHAR(32) NULL DEFAULT 'base'",
        "ALTER TABLE users ADD COLUMN balance DECIMAL(15,2) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN last_login DATETIME NULL",
        "ALTER TABLE users ADD COLUMN created_at DATETIME NULL",
        "CREATE UNIQUE INDEX idx_users_telegram_id ON users (telegram_id)",
    ];
    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* column already exists — ignore */ }
    }

    try {
        $telegram_id       = (int)$auth_data['id'];
        $telegram_username = $auth_data['username'] ?: ('user_' . $telegram_id);
        $full_name         = trim(($auth_data['first_name'] ?? '') . ' ' . ($auth_data['last_name'] ?? ''));

        $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $pdo->prepare("UPDATE users SET telegram_username = ?, last_login = NOW() WHERE id = ?")
                ->execute([$telegram_username, $user['id']]);
            $current_status = $user['status'] ?? 'pending';
            $user_id_for_session = (int)$user['id'];
            $username_for_session = $user['username'];
            $is_new_user = false;
        } else {
            // Если username из Telegram уже занят — добавим суффикс.
            $candidate = $telegram_username;
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$candidate]);
            if ($check->fetch()) {
                $candidate = $telegram_username . '_' . $telegram_id;
            }

            /* Новые TG-юзеры создаются со status='pending' (это и так дефолт
               по схеме, но прописываем явно — чтобы не зависеть от схемы).
               До одобрения админом такой юзер в кабинет не пускается. */
            $stmt = $pdo->prepare("
                INSERT INTO users
                    (username, telegram_id, telegram_username, full_name, entity_type,
                     user_status, status, balance, created_at, last_login)
                VALUES (?, ?, ?, ?, 'individual', 'base', 'pending', 0, NOW(), NOW())
            ");
            $stmt->execute([$candidate, $telegram_id, $telegram_username, $full_name]);

            $new_user_id = (int)$pdo->lastInsertId();
            $current_status = 'pending';
            $user_id_for_session = $new_user_id;
            $username_for_session = $candidate;
            $is_new_user = true;
        }

        $log('TG AUTH FLOW user_id=' . $user_id_for_session
            . ' status=' . $current_status
            . ' new=' . ($is_new_user ? '1' : '0'));

        /* Модерация:
           - active   → полный доступ к кабинету
           - pending  → пускаем в profile.php в режиме «онбординг» —
                        там доступна только форма заполнения данных и
                        загрузки документов; остальные секции скрыты
                        до одобрения админом.
           - blocked  → доступ полностью запрещён, кидаем на tg_pending.php */
        if ($current_status === 'blocked') {
            session_write_close();
            header('Location: tg_pending.php?r=blocked&u=' . urlencode($username_for_session));
            exit;
        }

        $_SESSION['user_id']  = $user_id_for_session;
        $_SESSION['username'] = $username_for_session;

        $log('SESSION SET sid=' . session_id()
            . ' user_id=' . $_SESSION['user_id']
            . ' username=' . $_SESSION['username']);
        /* Принудительно записываем сессию ДО редиректа — иначе в редких
           случаях header() уходит к клиенту раньше, чем PHP успевает
           сериализовать $_SESSION в файл. */
        session_write_close();
        header('Location: profile.php?telegram_auth=success');
        exit;

    } catch (Exception $e) {
        $log('DB EXCEPTION ' . $e->getMessage());
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
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #1e293b;
        }
        .container {
            background: #fff;
            border-radius: 24px;
            padding: 40px;
            max-width: 460px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
        }
        h1 { font-size: 24px; margin-bottom: 8px; color: #0f172a; }
        p  { color: #475569; margin-bottom: 28px; font-size: 14px; line-height: 1.5; }
        .telegram-widget { margin: 0 auto 20px; min-height: 56px; }
        .back-link {
            display: inline-block; margin-top: 8px;
            color: #2563eb; text-decoration: none;
            font-size: 14px; font-weight: 600;
            padding: 10px 20px; border-radius: 8px;
            transition: background 0.2s;
        }
        .back-link:hover { background: #eff6ff; }
        .err {
            text-align: left; background: #fef2f2; color: #991b1b;
            border: 1px solid #fecaca; border-radius: 12px;
            padding: 16px; font-size: 13px; line-height: 1.6;
        }
        .err code { background: #fff; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Вход через Telegram</h1>

        <?php if ($is_configured): ?>
            <p>Нажмите кнопку ниже для авторизации через Telegram.</p>
            <div class="telegram-widget">
                <script async src="https://telegram.org/js/telegram-widget.js?22"
                        data-telegram-login="<?= htmlspecialchars($bot_username) ?>"
                        data-size="large"
                        data-radius="10"
                        data-auth-url="<?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']) ?>"
                        data-request-access="write"></script>
            </div>
        <?php else: ?>
            <p>Авторизация через Telegram пока не настроена.</p>
            <div class="err">
                <strong>Как включить:</strong><br>
                1) Создайте бота в <a href="https://t.me/BotFather" target="_blank">@BotFather</a>, получите токен и <code>username</code>.<br>
                2) В <code>@BotFather → /setdomain</code> укажите домен сайта.<br>
                3) Создайте файл <code>htdocs/tg_config.php</code>:<br>
                <code style="display:block;margin-top:8px;white-space:pre-wrap;">&lt;?php
$bot_token    = '123456:AA...';
$bot_username = 'my_login_bot';</code>
            </div>
        <?php endif; ?>

        <a href="index.php" class="back-link">← Вернуться на главную</a>
    </div>
</body>
</html>
