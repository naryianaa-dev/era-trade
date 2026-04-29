<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод должен быть POST']);
    exit;
}

$username  = trim($_POST['username']  ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$email     = trim($_POST['email']     ?? '');
$password  = $_POST['password'] ?? '';

/* Выбранный статус и способ оплаты приходят из модального окна регистрации.
   Допустимые статусы:
     - 'respected'   — Уважаемый, бесплатно, активируется сразу;
     - 'responsible' — Ответственный, 8000 ₽, регистрируем как 'respected'
                       и создаём заявку на повышение статуса (status_upgrades),
                       затем перенаправляем на страницу оплаты QR / квитанции;
     - 'organizer'   — Организатор, бесплатно на 12 месяцев, активируется сразу,
                       срок действия записывается в users.organizer_until. */
$requested_type   = $_POST['user_type']      ?? 'respected';
$payment_method   = $_POST['payment_method'] ?? 'qr';
$express          = isset($_POST['express']) ? (int)$_POST['express'] : 0;

$agree_regulations   = !empty($_POST['agree_regulations']);
$agree_personal_data = !empty($_POST['agree_personal_data']);

if (!$username || !$full_name || !$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
    exit;
}

if (!$agree_regulations) {
    echo json_encode(['success' => false, 'message' => 'Необходимо принять условия Регламента площадки']);
    exit;
}
if (!$agree_personal_data) {
    echo json_encode(['success' => false, 'message' => 'Необходимо согласие на обработку персональных данных']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат email']);
    exit;
}
if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Пароль минимум 6 символов']);
    exit;
}

if (!in_array($requested_type, ['respected', 'responsible', 'organizer'], true)) {
    $requested_type = 'respected';
}
if (!in_array($payment_method, ['qr', 'receipt'], true)) {
    $payment_method = 'qr';
}

/* Тип, под которым реально создаётся запись в users.
   Для "Ответственного" сначала регистрируем как "Уважаемый",
   статус повышается администратором после подтверждения оплаты. */
$user_type_to_save = ($requested_type === 'responsible') ? 'respected' : $requested_type;

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким логином или email уже существует']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    /* Лениво обеспечиваем наличие колонки organizer_until — нужна для
       фиксации 12-месячного бесплатного срока статуса "Организатор". */
    try {
        $st = $pdo->query("SHOW COLUMNS FROM users LIKE 'organizer_until'");
        if ($st && !$st->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN organizer_until DATETIME NULL");
        }
    } catch (Exception $migrErr) {
        error_log('register_handler (organizer_until migration) error: ' . $migrErr->getMessage());
    }

    $stmt = $pdo->prepare('INSERT INTO users (username, full_name, email, password, user_type, balance) VALUES (?, ?, ?, ?, ?, ?)');
    $balance = 0;
    if (!$stmt->execute([$username, $full_name, $email, $hash, $user_type_to_save, $balance])) {
        throw new Exception('Не удалось создать пользователя');
    }

    $user_id = (int)$pdo->lastInsertId();

    /* Для "Организатора" сразу проставляем 12 месяцев бесплатного срока. */
    if ($requested_type === 'organizer') {
        try {
            $pdo->prepare("UPDATE users SET organizer_until = DATE_ADD(NOW(), INTERVAL 12 MONTH) WHERE id = ?")
                ->execute([$user_id]);
        } catch (Exception $orgErr) {
            error_log('register_handler (organizer_until set) error: ' . $orgErr->getMessage());
        }
    }

    // Сохранить прикрепленные документы (если есть)
    $uploadDir = __DIR__ . '/uploads/docs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    foreach (['file1','file2','file3'] as $index => $input) {
        if (!empty($_FILES[$input]['tmp_name']) && $_FILES[$input]['error'] === UPLOAD_ERR_OK) {
            $target = $uploadDir . $user_id . '_' . ($index + 1) . '_' . basename($_FILES[$input]['name']);
            move_uploaded_file($_FILES[$input]['tmp_name'], $target);
        }
    }

    // Журнал согласий — для соблюдения 152-ФЗ. Таблица создаётся лениво.
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_consents (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id     INT UNSIGNED NOT NULL,
                consent_type VARCHAR(64) NOT NULL,
                ip_address  VARCHAR(64) NULL,
                user_agent  VARCHAR(255) NULL,
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id),
                INDEX (consent_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
        $consentStmt = $pdo->prepare("INSERT INTO user_consents (user_id, consent_type, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        foreach (['regulations', 'personal_data'] as $ct) {
            $consentStmt->execute([$user_id, $ct, $ip, $ua]);
        }
    } catch (Exception $consentErr) {
        error_log('register_handler consent log error: ' . $consentErr->getMessage());
    }

    $_SESSION['user_id']      = $user_id;
    $_SESSION['user_name']    = $full_name;
    $_SESSION['user_balance'] = $balance;
    $_SESSION['usertype']     = $user_type_to_save;

    /* Платный статус "Ответственный" — создаём pending-заявку и
       возвращаем URL страницы оплаты (QR-код или квитанция),
       по аналогии с process_upgrade.php / upgrade_qr.php. */
    if ($requested_type === 'responsible') {
        try {
            $base_price  = 8000;
            $express_fee = ($express === 1) ? 7000 : 0;
            $total       = $base_price + $express_fee;

            $stmt = $pdo->prepare("
                INSERT INTO status_upgrades
                (user_id, target_status, base_price, express_fee, total_amount, payment_method, requested_at, status)
                VALUES (?, 'responsible', ?, ?, ?, ?, NOW(), 'pending')
            ");
            $stmt->execute([$user_id, $base_price, $express_fee, $total, $payment_method]);
            $upgrade_id = (int)$pdo->lastInsertId();

            try {
                $pdo->prepare("UPDATE users SET status_upgrade_requested_at = NOW() WHERE id = ?")
                    ->execute([$user_id]);
            } catch (Exception $tsErr) {
                error_log('register_handler (status_upgrade_requested_at) error: ' . $tsErr->getMessage());
            }

            $payment_url = ($payment_method === 'receipt')
                ? "upgrade_receipt.php?id={$upgrade_id}"
                : "upgrade_qr.php?id={$upgrade_id}";

            /* Готовим данные для встраивания QR прямо в модалку регистрации,
               чтобы пользователь не уходил со страницы (как в profile.php).
               ST00012 — российский стандарт QR для СБП-переводов. */
            $purpose    = "Повышение статуса Ответственный ID{$user_id} {$username}" . ($express_fee > 0 ? " (экспресс)" : "");
            $sumKopecks = (int)round($total * 100);
            $qrPayload  = "ST00012"
                . "|Name=ООО Форсаж"
                . "|PersonalAcc=40702810101500033019"
                . "|BankName=ООО Банк Точка"
                . "|BIC=044525104"
                . "|CorrespAcc=30101810745374525104"
                . "|PayeeINN=7728282160"
                . "|KPP=773001001"
                . "|Sum={$sumKopecks}"
                . "|Purpose={$purpose}";
            $qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data='
                . urlencode($qrPayload);

            echo json_encode([
                'success'      => true,
                'message'      => 'Регистрация успешна. Оплатите статус «Ответственный».',
                'user_id'      => $user_id,
                'upgrade_id'   => $upgrade_id,
                'payment_url'  => $payment_url,
                'payment_method' => $payment_method,
                'qr_image_url' => $qr_image_url,
                'qr_purpose'   => $purpose,
                'qr_amount'    => $total,
                'qr_inn'       => '7728282160',
                'qr_account'   => '40702810101500033019',
                'qr_bank'      => 'ООО Банк Точка',
                'qr_bic'       => '044525104',
            ]);
            exit;
        } catch (Exception $upErr) {
            /* Учётная запись уже создана как "Уважаемый", поэтому возвращаем
               успех регистрации, но просим оплатить статус из ЛК. */
            error_log('register_handler (status_upgrades insert) error: ' . $upErr->getMessage());
            echo json_encode([
                'success' => true,
                'message' => 'Регистрация успешна. Заявку на статус "Ответственный" можно оформить из личного кабинета.',
                'user_id' => $user_id,
            ]);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Регистрация успешна',
        'user_id' => $user_id,
    ]);
} catch (Exception $e) {
    error_log('register_handler error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка регистрации. Попробуйте ещё раз.']);
}
