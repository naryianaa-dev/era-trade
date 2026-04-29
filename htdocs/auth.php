<?php
session_start();
require_once 'db.php';

// Определяем тип ответа
$response_type = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false 
    ? 'json' 
    : 'text';

// Функция для отправки ответа
function sendResponse($message, $success = false, $response_type = 'text') {
    if ($response_type === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        if ($success) {
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            echo json_encode(['success' => false, 'message' => $message]);
        }
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $success ? 'success' : $message;
    }
    exit;
}

// Определяем действие
$action = trim($_POST['action'] ?? $_GET['action'] ?? 'login');

// ============================================
// ВХОД (LOGIN)
// ============================================
if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!$username || !$password) {
        sendResponse('Заполните все поля', false, $response_type);
    }
    
    try {
        // Ищем пользователя по username или email (добавили user_type в выборку)
        $stmt = $pdo->prepare("
            SELECT id, username, email, password, ban_type, balance, full_name, user_type 
            FROM users 
            WHERE username = ? OR email = ?
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            sendResponse('Неверный логин или пароль', false, $response_type);
        }
        
        // Проверка блокировки
        if (isset($user['ban_type']) && $user['ban_type'] === 'hard') {
            sendResponse('Ваш аккаунт заблокирован. Обратитесь в службу поддержки.', false, $response_type);
        }
        
        // Проверка пароля (поддерживаем старые незахешированные пароли)
        $valid = false;
        if (password_verify($password, $user['password'])) {
            $valid = true;
        } elseif ($password === $user['password'] && strpos($user['password'], '$2') !== 0) {
            // Старый незахешированный пароль - обновляем
            $valid = true;
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        
        if (!$valid) {
            sendResponse('Неверный логин или пароль', false, $response_type);
        }
        
        // Устанавливаем сессию (единый формат)
        $_SESSION['user_id']        = $user['id'];
        $_SESSION['user_name']      = $user['full_name'] ?: $user['username'];
        $_SESSION['user_username']  = $user['username'];
        $_SESSION['user_email']     = $user['email'] ?? '';
        $_SESSION['user_balance']   = $user['balance'] ?? 0;

        // Роль/тип пользователя
        $_SESSION['usertype'] = $user['user_type'] ?? 'user';

        $_SESSION['auth']          = true;              // для обратной совместимости
        $_SESSION['user_logged']   = $user['username']; // для обратной совместимости
        
        sendResponse('Вход выполнен успешно', true, $response_type);
        
    } catch (PDOException $e) {
        sendResponse('Ошибка базы данных: ' . $e->getMessage(), false, $response_type);
    }
}

// ============================================
// РЕГИСТРАЦИЯ (REGISTER)
// ============================================
if ($action === 'register') {
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    
    // Валидация
    if (!$username || !$password) {
        sendResponse('Заполните все поля', false, $response_type);
    }
    
    if (strlen($password) < 6) {
        sendResponse('Пароль минимум 6 символов', false, $response_type);
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse('Некорректный email', false, $response_type);
    }
    
    try {
        // Проверка уникальности
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR (email = ? AND email != '')");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            sendResponse('Логин или email уже заняты', false, $response_type);
        }
        
        // Обработка загруженных файлов
        $documents = [];
        if (!empty($_FILES)) {
            $upload_dir = 'uploads/documents/' . date('Y/m/d/');
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            foreach ($_FILES as $key => $file) {
                if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= 5 * 1024 * 1024) {
                    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $new_name = uniqid() . '.' . $ext;
                    $path     = $upload_dir . $new_name;
                    if (move_uploaded_file($file['tmp_name'], $path)) {
                        $documents[] = $path;
                    }
                }
            }
        }
        
        // Сохраняем пользователя (по умолчанию обычный тип)
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, full_name, documents, balance, user_type, created_at) 
            VALUES (?, ?, ?, ?, ?, 0, 'user', NOW())
        ");
        $stmt->execute([
            $username, 
            $email ?: null, 
            password_hash($password, PASSWORD_DEFAULT),
            $full_name ?: null,
            json_encode($documents)
        ]);
        
        sendResponse('Регистрация успешна', true, $response_type);
        
    } catch (PDOException $e) {
        sendResponse('Ошибка регистрации: ' . $e->getMessage(), false, $response_type);
    }
}

// ============================================
// ВЫХОД (LOGOUT)
// ============================================
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    sendResponse('Выход выполнен', true, $response_type);
}

// ============================================
// ПРОВЕРКА АВТОРИЗАЦИИ (CHECK)
// ============================================
if ($action === 'check') {
    if (isset($_SESSION['user_id'])) {
        sendResponse([
            'authenticated' => true,
            'user_id'       => $_SESSION['user_id'],
            'user_name'     => $_SESSION['user_name'] ?? '',
            'user_balance'  => $_SESSION['user_balance'] ?? 0,
            'usertype'      => $_SESSION['usertype'] ?? 'user',
        ], true, 'json');
    } else {
        sendResponse(['authenticated' => false], true, 'json');
    }
}

// ============================================
// TELEGRAM АВТОРИЗАЦИЯ
// ============================================
if ($action === 'telegram') {
    $telegram_data = json_decode($_POST['telegram_data'] ?? '', true);
    
    if (!$telegram_data || !isset($telegram_data['id'])) {
        sendResponse('Некорректные данные Telegram', false, $response_type);
    }
    
    try {
        // Ищем пользователя по telegram_id
        $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegram_data['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Существующий пользователь
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['user_name']     = $user['full_name'] ?: $user['username'];
            $_SESSION['user_username'] = $user['username'];
            $_SESSION['user_balance']  = $user['balance'] ?? 0;
            $_SESSION['usertype']      = $user['user_type'] ?? 'user';
        } else {
            // Создаем нового
            $username  = 'tg_' . $telegram_data['id'];
            $full_name = $telegram_data['first_name'] ?? '';
            if (isset($telegram_data['last_name'])) {
                $full_name .= ' ' . $telegram_data['last_name'];
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO users (username, full_name, telegram_id, balance, user_type, created_at) 
                VALUES (?, ?, ?, 0, 'user', NOW())
            ");
            $stmt->execute([$username, trim($full_name), $telegram_data['id']]);
            
            $_SESSION['user_id']       = $pdo->lastInsertId();
            $_SESSION['user_name']     = trim($full_name) ?: $username;
            $_SESSION['user_username'] = $username;
            $_SESSION['user_balance']  = 0;
            $_SESSION['usertype']      = 'user';
        }
        
        // Общие данные сессии
        $_SESSION['auth']        = true;
        $_SESSION['user_logged'] = $_SESSION['user_username'];
        
        sendResponse('Telegram авторизация успешна', true, $response_type);
        
    } catch (PDOException $e) {
        sendResponse('Ошибка Telegram авторизации', false, $response_type);
    }
}

// Если действие не найдено
sendResponse('Неизвестное действие', false, $response_type);
