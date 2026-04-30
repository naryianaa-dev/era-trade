<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['lang'])) {
    $accept_lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'ru';
    $_SESSION['lang'] = (substr($accept_lang, 0, 2) === 'ru') ? 'ru' : 'en';
}
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'ru';
}
$lang = $_SESSION['lang'];

require_once 'db.php';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectLot(int $lotId): void
{
    header('Location: torgi_view.php?id=' . $lotId);
    exit;
}

function setLotMsg(string $message, int $lotId): void
{
    $_SESSION['lot_msg'] = $message;
    redirectLot($lotId);
}

function ensureUploadDir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    return mkdir($dir, 0777, true);
}

function uploadFile(string $field, string $dir, array $allowedExt, int $maxBytes, string $prefix): array
{
    if (empty($_FILES[$field]['name'])) {
        return [true, ''];
    }

    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Ошибка загрузки файла'];
    }

    if (!ensureUploadDir($dir)) {
        return [false, 'Не удалось создать каталог для загрузки'];
    }

    if ((int)$_FILES[$field]['size'] > $maxBytes) {
        return [false, 'Файл превышает допустимый размер'];
    }

    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return [false, 'Недопустимый тип файла'];
    }

    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = rtrim($dir, '/') . '/' . $filename;

    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        return [false, 'Не удалось сохранить файл'];
    }

    return [true, $target];
}

function normalizeMoney(string $value): float
{
    $value = str_replace([' ', ','], ['', '.'], trim($value));
    return (float)$value;
}

function ensureTablePaymentReceipts(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_receipts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED DEFAULT NULL,
            lot_id INT UNSIGNED DEFAULT NULL,
            amount DECIMAL(15,2) NOT NULL,
            tariff VARCHAR(100) NOT NULL,
            comment TEXT NULL,
            file_path VARCHAR(500) NOT NULL,
            user_email VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (lot_id),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensureTableOffers(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED DEFAULT NULL,
            price DECIMAL(13,2) NOT NULL,
            comment TEXT NULL,
            file_path VARCHAR(500) NULL,
            status ENUM('pending','accepted','rejected') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (lot_id),
            INDEX (user_id),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensureOffersColumns(PDO $pdo): void
{
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM offers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('comment', $cols, true)) {
            $pdo->exec("ALTER TABLE offers ADD COLUMN comment TEXT NULL AFTER price");
        }
        if (!in_array('file_path', $cols, true)) {
            $pdo->exec("ALTER TABLE offers ADD COLUMN file_path VARCHAR(500) NULL AFTER comment");
        }
        if (!in_array('email', $cols, true)) {
            $pdo->exec("ALTER TABLE offers ADD COLUMN email VARCHAR(255) NULL AFTER user_id");
        }
        if (!in_array('contact_type', $cols, true)) {
            $pdo->exec("ALTER TABLE offers ADD COLUMN contact_type VARCHAR(20) NULL AFTER email");
        }
        if (!in_array('contact_value', $cols, true)) {
            $pdo->exec("ALTER TABLE offers ADD COLUMN contact_value VARCHAR(255) NULL AFTER contact_type");
        }
    } catch (Throwable $e) {
        error_log('ensureOffersColumns: ' . $e->getMessage());
    }
}

function ensureTableInterests(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS interests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lot_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED DEFAULT NULL,
            full_name VARCHAR(255) NULL,
            registration_address TEXT NULL,
            message TEXT NOT NULL,
            contact_type VARCHAR(50) NOT NULL,
            contact_value VARCHAR(255) NOT NULL,
            inspection_date DATETIME NULL,
            file_path VARCHAR(500) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (lot_id),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensureTableContacts(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contacts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lot_id INT UNSIGNED NOT NULL,
            from_user_id INT UNSIGNED DEFAULT NULL,
            message TEXT NOT NULL,
            contact VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (lot_id),
            INDEX (from_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// --- POST-обработчики ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_receipt') {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $lot_id  = (int)($_GET['id'] ?? 0);
    $tariff  = trim($_POST['tariff'] ?? '');
    $amount  = (float)($_POST['amount'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $user_email = trim($_POST['user_email'] ?? '');
    
    if ($lot_id <= 0 || $tariff === '' || $amount <= 0) {
        setLotMsg('Ошибка: не выбраны лот или тариф', $lot_id);
    }
    [$ok, $filePath] = uploadFile(
        'receipt_file',
        'uploads/receipts',
        ['jpg', 'jpeg', 'png', 'pdf'],
        5 * 1024 * 1024,
        'receipt_' . ($user_id ?: 'guest') . '_' . $lot_id
    );
    if (!$ok || $filePath === '') {
        setLotMsg($ok ? 'Не удалось загрузить файл' : $filePath, $lot_id);
    }
    ensureTablePaymentReceipts($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO payment_receipts
                (user_id, lot_id, amount, tariff, comment, file_path, user_email, status, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$user_id ?: null, $lot_id, $amount, $tariff, $comment, $filePath, $user_email ?: null]);
        setLotMsg('Квитанция загружена и отправлена на проверку. После подтверждения отчёт придёт на email.', $lot_id);
    } catch (Throwable $e) {
        error_log('payment_receipt error: ' . $e->getMessage());
        setLotMsg('Ошибка при сохранении квитанции', $lot_id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'make_offer') {
    $user_id     = (int)($_SESSION['user_id'] ?? 0);
    $lot_id      = (int)($_GET['id'] ?? 0);
    $offer_price   = normalizeMoney($_POST['price'] ?? '0');
    $comment       = trim($_POST['comment'] ?? '');
    $offer_email   = trim($_POST['email'] ?? '');
    $contact_type  = trim($_POST['contact_type'] ?? 'phone');
    if (!in_array($contact_type, ['phone','email','telegram'], true)) $contact_type = 'phone';
    $contact_value = trim($_POST['contact_value'] ?? '');
    if ($lot_id <= 0 || $offer_price <= 0) {
        setLotMsg('Укажите корректную цену', $lot_id);
    }
    if ($offer_email === '' || !filter_var($offer_email, FILTER_VALIDATE_EMAIL)) {
        setLotMsg('Укажите корректный e-mail', $lot_id);
    }
    if ($contact_value === '') {
        setLotMsg('Укажите контакт для связи', $lot_id);
    }
    $stmt_price = $pdo->prepare("SELECT price FROM torgi WHERE id = ?");
    $stmt_price->execute([$lot_id]);
    $start_price = (float)$stmt_price->fetchColumn();
    if ($offer_price < $start_price) {
        setLotMsg('Предлагаемая цена не может быть ниже начальной цены лота (' . number_format($start_price, 0, '.', ' ') . ' ₽)', $lot_id);
    }
    [$ok, $filePath] = uploadFile(
        'offer_file',
        'uploads/offers',
        ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
        3 * 1024 * 1024,
        'offer_' . ($user_id ?: 'guest') . '_' . $lot_id
    );
    if (!$ok) {
        setLotMsg($filePath, $lot_id);
    }
    ensureTableOffers($pdo);
    ensureOffersColumns($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO offers (lot_id, user_id, email, contact_type, contact_value, price, comment, file_path, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$lot_id, $user_id ?: null, $offer_email, $contact_type, $contact_value, $offer_price, $comment, $filePath ?: null]);
        $offer_id = (int)$pdo->lastInsertId();
        /* Если фронт отметил «подписать ЭЦП» — связываем последнюю подпись пользователя
           (target_type='commission_offer', signed_at в пределах 60 секунд) с этим оффером. */
        if (!empty($_POST['ecp_signature_b64']) && !empty($_POST['ecp_cert_pem']) && $user_id) {
            try {
                $st = $pdo->prepare(
                    "SELECT id FROM ecp_signatures
                     WHERE user_id = ? AND target_type = 'commission_offer'
                       AND target_id IS NULL
                       AND signed_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
                     ORDER BY id DESC LIMIT 1"
                );
                $st->execute([$user_id]);
                $sig = $st->fetch(PDO::FETCH_ASSOC);
                if ($sig) {
                    $pdo->prepare("UPDATE ecp_signatures SET target_id = ? WHERE id = ?")
                        ->execute([$offer_id, (int)$sig['id']]);
                    /* offers.ecp_signed/ecp_sig_id уже мигрированы. */
                    $pdo->prepare("UPDATE offers SET ecp_signed = 1, ecp_sig_id = ? WHERE id = ?")
                        ->execute([(int)$sig['id'], $offer_id]);
                }
            } catch (Throwable $eee) {
                /* не критично */
            }
        }
        setLotMsg('Ваше предложение отправлено продавцу', $lot_id);
    } catch (Throwable $e) {
        error_log('offer error: ' . $e->getMessage());
        setLotMsg('Ошибка при отправке предложения: ' . $e->getMessage(), $lot_id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'interest') {
    $user_id         = (int)($_SESSION['user_id'] ?? 0);
    $lot_id          = (int)($_GET['id'] ?? 0);
    $message         = trim($_POST['message'] ?? '');
    $contact_type    = trim($_POST['contact_type'] ?? 'email');
    $contact_value   = trim($_POST['contact_value'] ?? '');
    $inspection_date = trim($_POST['inspection_date'] ?? '');
    $full_name       = trim($_POST['full_name'] ?? '');
    $reg_address     = trim($_POST['registration_address'] ?? '');
    if ($lot_id <= 0 || $message === '' || $contact_value === '' || $full_name === '' || $reg_address === '') {
        setLotMsg('Заполните ФИО, адрес, сообщение и контактные данные', $lot_id);
    }
    
    // Серверная проверка даты осмотра (не ранее 3 рабочих дней)
    if ($inspection_date) {
        $selected = new DateTime($inspection_date, new DateTimeZone('Europe/Moscow'));
        $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
        // Добавляем 3 рабочих дня (без субботы и воскресенья)
        $min_date = clone $now;
        $added = 0;
        while ($added < 3) {
            $min_date->modify('+1 day');
            if ($min_date->format('N') < 6) { // 1-5 = рабочие дни
                $added++;
            }
        }
        if ($selected < $min_date) {
            setLotMsg('Дата осмотра должна быть не ранее чем через 3 рабочих дня', $lot_id);
        }
    }
    
    [$ok, $filePath] = uploadFile(
        'interest_file',
        'uploads/interests',
        ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'],
        3 * 1024 * 1024,
        'interest_' . ($user_id ?: 'guest') . '_' . $lot_id
    );
    if (!$ok) {
        setLotMsg($filePath, $lot_id);
    }
    ensureTableInterests($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO interests
                (lot_id, user_id, full_name, registration_address, message, contact_type, contact_value, inspection_date, file_path, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $lot_id,
            $user_id ?: null,
            $full_name,
            $reg_address,
            $message,
            $contact_type,
            $contact_value,
            $inspection_date !== '' ? $inspection_date : null,
            $filePath ?: null
        ]);
        setLotMsg('Ваша заявка на осмотр отправлена и находится на рассмотрении. Ожидайте, спасибо.', $lot_id);
    } catch (Throwable $e) {
        error_log('interest error: ' . $e->getMessage());
        setLotMsg('Ошибка при отправке заявки: ' . $e->getMessage(), $lot_id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact_seller') {
    $from_user_id = (int)($_SESSION['user_id'] ?? 0);
    $lot_id       = (int)($_GET['id'] ?? 0);
    $message      = trim($_POST['message'] ?? '');
    $contact      = trim($_POST['contact'] ?? '');
    if ($lot_id <= 0 || $message === '' || $contact === '') {
        setLotMsg('Заполните сообщение и контакт для обратной связи', $lot_id);
    }
    [$ok, $filePath] = uploadFile(
        'contact_file',
        'uploads/contacts',
        ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'],
        3 * 1024 * 1024,
        'contact_' . ($from_user_id ?: 'guest') . '_' . $lot_id
    );
    if (!$ok) {
        setLotMsg($filePath, $lot_id);
    }
    ensureTableContacts($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO contacts
                (lot_id, from_user_id, message, contact, file_path, created_at)
            VALUES
                (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $lot_id,
            $from_user_id ?: null,
            $message,
            $contact,
            $filePath ?: null
        ]);
        setLotMsg('Ваше сообщение отправлено продавцу. Ожидайте ответа.', $lot_id);
    } catch (Throwable $e) {
        error_log('contact error: ' . $e->getMessage());
        setLotMsg('Ошибка при отправке сообщения: ' . $e->getMessage(), $lot_id);
    }
}

// --- Загрузка лота ---
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    include 'header.php';
    echo "<main style='padding:40px 20px;'><div style='max-width:800px;margin:0 auto;'><div class='alert error'>Неверный идентификатор лота.</div></div></main>";
    include 'footer.php';
    exit;
}

$sql = "
    SELECT id, title, price, region, lot_type, description, images, date_created, status, report_price
    FROM torgi
    WHERE id = ?
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$lot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lot) {
    include 'header.php';
    echo "<main style='padding:40px 20px;'><div style='max-width:800px;margin:0 auto;'><div class='alert error'>Лот не найден.</div></div></main>";
    include 'footer.php';
    exit;
}

$session_id = (int)($_SESSION['user_id'] ?? 0);
$usertype   = $_SESSION['usertype'] ?? 'user';
$can_edit   = $session_id > 0 && $usertype === 'admin';

$images = [];
if (!empty($lot['images'])) {
    $decoded = json_decode($lot['images'], true);
    if (is_array($decoded)) {
        $images = array_values(array_filter($decoded, static fn($img) => is_string($img) && trim($img) !== ''));
    }
}

$msg = $_SESSION['lot_msg'] ?? null;
unset($_SESSION['lot_msg']);

$status = trim((string)($lot['status'] ?? ''));
$status_text = ($lang === 'en' ? 'Status: ' : 'Статус: ') . $status;
$status_color = '#64748b';
if ($status === 'open') {
    $status_text = $lang === 'en' ? 'Open for offers' : 'Открыт для предложений';
    $status_color = '#16a34a';
} elseif ($status === 'closed') {
    $status_text = $lang === 'en' ? 'Deal closed' : 'Сделка завершена';
    $status_color = '#dc2626';
}

$createdDate = '';
if (!empty($lot['date_created']) && strtotime($lot['date_created']) !== false) {
    $createdDate = date('d.m.Y', strtotime($lot['date_created']));
}

// Email из профиля
$user_email_from_profile = '';
if ($session_id > 0) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$session_id]);
    $user_email_from_profile = $stmt->fetchColumn();
}

// PDF и проверка оплаты (без привязки к тарифу – проверяем только статус confirmed)
$pdf_files = [];
$has_paid_report = false;
try {
    $stmt_pdf = $pdo->prepare("SELECT * FROM lot_files WHERE lot_id = ? ORDER BY sort_order, id");
    $stmt_pdf->execute([$id]);
    $pdf_files = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);
    
    if ($session_id > 0) {
        $stmt_check = $pdo->prepare("
            SELECT id FROM payment_receipts 
            WHERE user_id = ? AND lot_id = ? AND status = 'confirmed'
            LIMIT 1
        ");
        $stmt_check->execute([$session_id, $id]);
        $has_paid_report = $stmt_check->fetchColumn() > 0;
    }
} catch (Exception $e) {
    // таблица может отсутствовать – игнорируем
}

// --- Расчёт минимальной даты для осмотра (3 рабочих дня) ---
$now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
$minDate = clone $now;
$added = 0;
while ($added < 3) {
    $minDate->modify('+1 day');
    if ($minDate->format('N') < 6) { // 1-5 = рабочие дни
        $added++;
    }
}
$minInspectionDate = $minDate->format('Y-m-d\TH:i');

include 'header.php';
?>

<style>
/* ========== ОСНОВНЫЕ СТИЛИ + АДАПТИВ ========== */
.torgi-page * { box-sizing:border-box; }
.torgi-page {
    --bg:#f8fafc;
    --card:#ffffff;
    --line:#e2e8f0;
    --text:#0f172a;
    --muted:#64748b;
    --blue:#0ea5e9;
    --blue-dark:#0284c7;
    --soft:#eff6ff;
    --ok:#16a34a;
    --danger:#dc2626;
}
.torgi-wrap { max-width:1100px; margin:0 auto; padding:0 16px; }
.torgi-back {
    display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--muted);
    text-decoration:none; margin-bottom:16px;
}
.torgi-alert {
    background:#d1fae5; color:#065f46; border:1px solid #a7f3d0;
    padding:12px 14px; border-radius:12px; margin-bottom:16px; font-size:14px;
}
.torgi-grid {
    display:grid;
    grid-template-columns:minmax(0, 3fr) minmax(0, 2fr);
    gap:24px;
    align-items:flex-start;
}
.torgi-card {
    background:var(--card);
    border:1px solid var(--line);
    border-radius:16px;
}
.torgi-soft {
    background:var(--bg);
    border:1px solid var(--line);
    border-radius:16px;
}
.torgi-main-image-wrap {
    width:100%;
    border-radius:16px;
    overflow:hidden;
    background:#e5e7eb;
    margin-bottom:10px;
    position:relative;
}
.torgi-main-image {
    width:100%;
    max-height:420px;
    min-height:280px;
    object-fit:cover;
    cursor:pointer;
    display:block;
}
.torgi-nav-btn {
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    border:none;
    border-radius:999px;
    width:36px;
    height:36px;
    background:rgba(255,255,255,.88);
    cursor:pointer;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    color:#0f172a;
}
.torgi-nav-btn.left { left:10px; }
.torgi-nav-btn.right { right:10px; }
.torgi-thumbs {
    display:flex;
    gap:8px;
    overflow-x:auto;
    padding-bottom:4px;
    margin-bottom:8px;
}
.torgi-thumb {
    width:84px;
    height:64px;
    border-radius:10px;
    overflow:hidden;
    background:#e5e7eb;
    flex-shrink:0;
    border:2px solid transparent;
    cursor:pointer;
}
.torgi-thumb.active { border-color:var(--blue); }
.torgi-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.torgi-empty-photo {
    width:100%;
    height:300px;
    border-radius:16px;
    background:#e5e7eb;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#94a3b8;
    margin-bottom:10px;
}
.torgi-actions {
    margin-top:6px;
    padding:8px;
    border-radius:12px;
    background:#f8fafc;
    border:1px solid var(--line);
}
.torgi-actions-title {
    font-size:12px;
    color:var(--muted);
    margin-bottom:6px;
}
.torgi-actions-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:6px;
    text-align:center;
}
.torgi-action-btn {
    border:none;
    cursor:pointer;
    padding:8px 4px;
    border-radius:10px;
    min-height:72px;
    font-size:10px;
    font-weight:700;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:4px;
}
.torgi-action-btn span:first-child { font-size:18px; line-height:1; }
.torgi-action-offer { background:var(--blue); color:#fff; }
.torgi-action-interest { background:#e0f2fe; color:#0f172a; }
.torgi-action-contact { background:#e5e7eb; color:#0f172a; }
.torgi-action-upgrade { background:#fff; color:var(--blue); border:1px solid var(--blue); }
.torgi-price-card,
.torgi-info-card,
.torgi-desc-card { padding:16px 18px; }
.torgi-mini-label {
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:#94a3b8;
    margin-bottom:6px;
}
.torgi-price {
    font-size:30px;
    font-weight:900;
    color:var(--blue);
    margin-bottom:4px;
}
.torgi-status { font-size:12px; font-weight:700; margin-bottom:4px; }
.torgi-meta { font-size:13px; color:var(--muted); }
.torgi-edit-link {
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid var(--line);
    color:var(--text);
    text-decoration:none;
    margin-bottom:10px;
}
.torgi-title {
    margin:0 0 12px;
    font-size:24px;
    line-height:1.2;
    font-weight:800;
    color:var(--text);
}
.torgi-info-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
    font-size:13px;
    color:var(--muted);
}
.torgi-info-grid strong {
    display:block;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.08em;
    margin-bottom:4px;
}
.torgi-info-grid span { color:var(--text); }
.torgi-desc-title {
    font-size:15px;
    font-weight:800;
    color:var(--text);
    margin-bottom:8px;
}
.torgi-desc-text {
    font-size:14px;
    color:#334155;
    white-space:pre-line;
    line-height:1.65;
}
/* PDF block */
.torgi-pdf-card {
    margin-top:16px;
    padding:16px 18px;
}
.pdf-item {
    margin:8px 0;
    padding:6px 0;
    border-bottom:1px solid #e2e8f0;
}
.pdf-item a {
    color:#0ea5e9;
    text-decoration:none;
}
.pdf-item.locked {
    color:#64748b;
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
}
.btn-buy-report {
    background:#f59e0b;
    border:none;
    padding:4px 12px;
    border-radius:6px;
    cursor:pointer;
    font-size:12px;
    font-weight:bold;
}
.info-text {
    font-size:12px;
    color:#64748b;
}
/* Modal styles */
.modal {
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.68);
    display:none;
    align-items:flex-start;
    justify-content:center;
    padding:20px;
    z-index:9999;
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
}
.modal.active { display:flex; }
.modal-content {
    width:100%;
    max-width:640px;
    max-height:calc(100vh - 40px);
    max-height:calc(100dvh - 40px);
    overflow:auto;
    -webkit-overflow-scrolling:touch;
    background:#fff;
    border-radius:18px;
    padding:20px;
    position:relative;
    box-shadow:0 30px 80px rgba(2,8,23,.28);
    margin:auto 0;
}
.modal-close {
    position:absolute;
    top:12px;
    right:12px;
    width:36px;
    height:36px;
    border:none;
    border-radius:999px;
    background:#f1f5f9;
    cursor:pointer;
    font-size:22px;
    color:#0f172a;
}
.modal-title {
    margin:0 32px 8px 0;
    font-size:22px;
    line-height:1.2;
    font-weight:800;
    color:#0f172a;
}
.modal-subtitle {
    margin:0 0 14px;
    color:#64748b;
    font-size:14px;
}
.form-group { margin-bottom:12px; }
.form-label {
    display:block;
    font-size:13px;
    font-weight:700;
    margin-bottom:5px;
    color:#0f172a;
}
.form-input, .form-textarea, .form-select {
    width:100%;
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #cbd5e1;
    font-size:14px;
    color:#0f172a;
    background:#fff;
}
.form-textarea { resize:vertical; min-height:96px; }
.form-note { font-size:12px; color:#64748b; margin-top:4px; }
.btn-row { display:flex; gap:10px; margin-top:16px; }
.btn {
    border:none;
    border-radius:10px;
    cursor:pointer;
    padding:11px 14px;
    font-size:14px;
    font-weight:700;
}
.btn-primary { background:#0ea5e9; color:#fff; }
.btn-primary:hover { background:#0284c7; }
.btn-secondary { background:#f8fafc; color:#0f172a; border:1px solid #e2e8f0; }

.tariff-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
    margin:14px 0;
}
.tariff-card {
    border:1px solid #e2e8f0;
    background:#f8fafc;
    border-radius:14px;
    padding:14px;
    cursor:pointer;
    transition:.15s ease;
}
.tariff-card:hover { border-color:#7dd3fc; }
.tariff-name {
    font-weight:800;
    color:#0f172a;
    margin-bottom:4px;
}
.tariff-price {
    font-size:22px;
    font-weight:900;
    color:#0ea5e9;
    margin-bottom:6px;
}
.tariff-desc {
    font-size:13px;
    color:#64748b;
    line-height:1.5;
}

.payment-details {
    display:none;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:12px;
    margin:12px 0;
    color:#0f172a;
    font-size:14px;
}
.payment-methods {
    display:none;
    margin-top:14px;
}
.payment-methods-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
}
.payment-method {
    border:1px solid #cbd5e1;
    background:#fff;
    border-radius:12px;
    padding:12px;
    cursor:pointer;
    text-align:center;
    font-size:14px;
    font-weight:700;
    color:#0f172a;
}
.payment-method.selected {
    border-color:#0ea5e9;
    background:#eff6ff;
    color:#0369a1;
}
.qr-block, .receipt-reg-block {
    display:none;
    margin-top:14px;
    border-radius:12px;
    padding:16px;
}
.qr-block {
    background:#f8fafc;
    border:1px solid #e2e8f0;
    text-align:center;
}
.receipt-reg-block {
    background:#0f172a;
    color:#cbd5e1;
}
.qr-block img {
    margin:0 auto 10px;
    width:200px;
    height:200px;
    background:#fff;
    padding:8px;
    border-radius:10px;
}
.receipt-generate-btn {
    width:100%;
    padding:10px;
    background:#0ea5e9;
    color:#fff;
    border:none;
    border-radius:10px;
    font-weight:700;
    cursor:pointer;
}
.success-block {
    display:none;
    text-align:center;
    padding:20px 10px;
    border-top:1px solid #e2e8f0;
    margin-top:14px;
}
.success-icon {
    width:64px;
    height:64px;
    border-radius:999px;
    margin:0 auto 12px;
    background:#dcfce7;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#16a34a;
    font-size:32px;
}
.image-modal-content {
    max-width:100%;
    max-height:100%;
    background:rgba(0,0,0,.76);
    position:relative;
    padding:0;
    box-shadow:none;
    display:flex;
    align-items:center;
    justify-content:center;
}
.image-modal-content img {
    max-width:95vw;
    max-height:90vh;
    width:auto;
    height:auto;
    display:block;
}
.image-modal-btn {
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    border:none;
    border-radius:999px;
    width:44px;
    height:44px;
    background:rgba(15,23,42,.76);
    color:#e5e7eb;
    font-size:26px;
    cursor:pointer;
    z-index:3;
}
.image-modal-btn.left { left:20px; }
.image-modal-btn.right { right:20px; }
.image-modal-close {
    position:absolute;
    top:12px;
    right:12px;
    background:#fff;
    border-radius:50%;
    width:38px;
    height:38px;
    border:none;
    cursor:pointer;
    z-index:3;
    font-size:24px;
}
/* Adaptive */
@media (max-width: 768px) {
    .torgi-wrap { padding:0 12px; }
    .torgi-grid { display:block !important; }
    .torgi-grid > div { width:100% !important; margin-bottom:20px !important; }
    .torgi-actions-grid { grid-template-columns:1fr 1fr !important; gap:8px !important; }
    .torgi-info-grid { display:block !important; }
    .torgi-info-grid div { margin-bottom:8px !important; }
    .torgi-main-image { max-height:320px !important; min-height:220px !important; width:100% !important; }
    .torgi-price { font-size:24px !important; }
    .torgi-title { font-size:18px !important; }
    .torgi-price-card, .torgi-info-card, .torgi-desc-card { padding:12px !important; }
    .modal { padding:10px !important; align-items:flex-start !important; }
    .modal-content { width:100% !important; max-width:100% !important; padding:16px !important; max-height:calc(100vh - 20px) !important; max-height:calc(100dvh - 20px) !important; }
    .tariff-grid, .payment-methods-grid { grid-template-columns:1fr !important; }
    .btn-row { flex-direction:column !important; }
    .btn { width:100% !important; }
    .qr-block img { width:160px !important; height:160px !important; }
}
@media (max-width: 480px) {
    .torgi-price { font-size:20px !important; }
    .torgi-title { font-size:16px !important; }
    .torgi-action-btn { min-height:54px !important; font-size:9px !important; }
    .torgi-action-btn span:first-child { font-size:16px !important; }
    .torgi-desc-text { font-size:12px !important; }
}
</style>

<main class="torgi-page" style="flex:1; padding:30px 20px;">
    <div class="torgi-wrap">
        <a href="torgi_list.php" class="torgi-back"><?= $lang === 'en' ? '← Back to listings' : '← Вернуться к списку' ?></a>
        <?php if ($msg): ?>
            <div class="torgi-alert"><?= e($msg) ?></div>
        <?php endif; ?>
        <div class="torgi-grid">
            <!-- левая колонка -->
            <div>
                <?php if (!empty($images)): ?>
                    <div class="torgi-main-image-wrap">
                        <?php if (count($images) > 1): ?>
                            <button type="button" class="torgi-nav-btn left" onclick="changeTorgiImage(-1)">‹</button>
                        <?php endif; ?>
                        <img id="torgiMainImage" class="torgi-main-image" src="<?= e($images[0]) ?>" alt="<?= e($lot['title']) ?>" onclick="openImageModalFromMain()">
                        <?php if (count($images) > 1): ?>
                            <button type="button" class="torgi-nav-btn right" onclick="changeTorgiImage(1)">›</button>
                        <?php endif; ?>
                    </div>
                    <?php if (count($images) > 1): ?>
                        <div id="torgiThumbs" class="torgi-thumbs">
                            <?php foreach ($images as $idx => $img): ?>
                                <div class="torgi-thumb <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>" onclick="setTorgiImage(<?= $idx ?>)">
                                    <img src="<?= e($img) ?>" alt="Фото <?= $idx + 1 ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="torgi-empty-photo"><?= $lang === 'en' ? 'Photos have not been uploaded yet' : 'Фото ещё не загружены' ?></div>
                <?php endif; ?>
                <div class="torgi-actions">
                    <div class="torgi-actions-title"><?= $lang === 'en' ? 'Lot actions' : 'Действия с лотом' ?></div>
                    <div class="torgi-actions-grid">
                        <button type="button" class="torgi-action-btn torgi-action-offer" onclick="openModal('offerModal')"><span>₽</span><span><?= $lang === 'en' ? 'Make an offer' : 'Предложить цену' ?></span></button>
                        <button type="button" class="torgi-action-btn torgi-action-interest" onclick="openModal('interestModal')"><span>★</span><span><?= $lang === 'en' ? 'Interested' : 'Интересует' ?></span></button>
                        <button type="button" class="torgi-action-btn torgi-action-contact" onclick="openModal('contactSellerModal')"><span>✉</span><span><?= $lang === 'en' ? 'Contact seller' : 'Связаться' ?></span></button>
                        <button type="button" class="torgi-action-btn torgi-action-upgrade" onclick="openModal('upgradeModal')"><span>ℹ</span><span><?= $lang === 'en' ? 'Details' : 'Подробности' ?></span></button>
                    </div>
                </div>
            </div>
            <!-- правая колонка -->
            <div>
                <div class="torgi-card torgi-price-card">
                    <div class="torgi-mini-label"><?= $lang === 'en' ? 'Price' : 'Цена' ?></div>
                    <div class="torgi-price"><?= number_format((float)$lot['price'], 0, '.', ' ') ?> ₽</div>
                    <div class="torgi-status" style="color:<?= e($status_color) ?>;"><?= e($status_text) ?></div>
                    <div class="torgi-meta"><?= e($lot['region']) ?><?= $createdDate ? ', ' . e($createdDate) : '' ?></div>
                    <?php if ($can_edit): /* admin → может править цену отчёта на любой лот */ ?>
                        <div style="margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0;">
                            <div class="torgi-mini-label"><?= $lang === 'en' ? 'Report price (₽)' : 'Цена отчёта (₽)' ?></div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="number" id="rpInput" min="0" step="1"
                                    value="<?= isset($lot['report_price']) && $lot['report_price'] !== null && $lot['report_price'] !== '' ? (int)$lot['report_price'] : '' ?>"
                                    placeholder="1390"
                                    style="flex:1; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;">
                                <button type="button" onclick="saveReportPrice()" style="padding:8px 12px; border:0; border-radius:8px; background:#0ea5e9; color:#fff; font-weight:700; cursor:pointer;"><?= $lang === 'en' ? 'Save' : 'Сохранить' ?></button>
                            </div>
                            <div id="rpMsg" style="margin-top:6px; font-size:12px; color:#64748b;"><?= $lang === 'en' ? 'Empty = default 1390 ₽' : 'Пусто = дефолт 1390 ₽' ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="torgi-soft torgi-info-card" style="margin-top:16px;">
                    <?php if ($can_edit): ?>
                        <a href="torgi_edit.php?id=<?= (int)$lot['id'] ?>" class="torgi-edit-link">✏ <?= $lang === 'en' ? 'Edit lot' : 'Редактировать лот' ?></a>
                    <?php endif; ?>
                    <h1 class="torgi-title"><?= e($lot['title']) ?></h1>
                    <div class="torgi-info-grid">
                        <div><strong><?= $lang === 'en' ? 'Category' : 'Категория' ?></strong><span><?= e($lot['lot_type']) ?></span></div>
                        <div><strong><?= $lang === 'en' ? 'Region' : 'Регион' ?></strong><span><?= e($lot['region']) ?></span></div>
                        <div><strong><?= $lang === 'en' ? 'Created' : 'Создан' ?></strong><span><?= e($createdDate ?: '—') ?></span></div>
                        <div><strong>ID</strong><span>#<?= (int)$lot['id'] ?></span></div>
                    </div>
                </div>
                <?php if (!empty($lot['description'])): ?>
                    <div class="torgi-card torgi-desc-card" style="margin-top:16px;">
                        <div class="torgi-desc-title"><?= $lang === 'en' ? 'Lot description' : 'Описание лота' ?></div>
                        <div class="torgi-desc-text"><?= nl2br(e($lot['description'])) ?></div>
                    </div>
                <?php endif; ?>
                <!-- БЛОК PDF -->
                <?php if (!empty($pdf_files)): ?>
                <div class="torgi-card torgi-pdf-card">
                    <div class="torgi-desc-title">📄 <?= $lang === 'en' ? 'Documents and reports' : 'Документы и отчёты' ?></div>
                    <div class="pdf-list">
                        <?php foreach ($pdf_files as $pdf): ?>
                            <?php if ($pdf['access_level'] === 'public'): ?>
                                <div class="pdf-item">
                                    <a href="<?= e($pdf['file_path']) ?>" target="_blank">📄 <?= e($pdf['file_name']) ?></a>
                                </div>
                            <?php elseif ($pdf['access_level'] === 'paid' && $has_paid_report): ?>
                                <div class="pdf-item">
                                    <a href="<?= e($pdf['file_path']) ?>" target="_blank">🔓 <?= e($pdf['file_name']) ?></a>
                                    <span style="font-size:12px; color:#16a34a;"> <?= $lang === 'en' ? '(available)' : '(доступен)' ?></span>
                                </div>
                            <?php elseif ($pdf['access_level'] === 'paid' && !$has_paid_report): ?>
                                <div class="pdf-item locked">
                                    <span>🔒 <?= e($pdf['file_name']) ?></span>
                                    <button class="btn-buy-report" onclick="openModal('upgradeModal')"><?= $lang === 'en' ? 'Buy report' : 'Купить отчёт' ?></button>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- ========== ВСЕ МОДАЛЬНЫЕ ОКНА ========== -->

<div id="imageModal" class="modal">
    <div class="modal-content image-modal-content">
        <button type="button" class="image-modal-close" onclick="closeModal('imageModal')">×</button>
        <?php if (count($images) > 1): ?>
            <button type="button" class="image-modal-btn left" onclick="changeModalImage(-1)">‹</button>
        <?php endif; ?>
        <img src="" id="fullImage" alt="<?= $lang === 'en' ? 'Full image' : 'Полное изображение' ?>">
        <?php if (count($images) > 1): ?>
            <button type="button" class="image-modal-btn right" onclick="changeModalImage(1)">›</button>
        <?php endif; ?>
    </div>
</div>

<div id="offerModal" class="modal">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeModal('offerModal')">×</button>
        <h2 class="modal-title"><?= $lang === 'en' ? 'Make an offer' : 'Предложить свою цену' ?></h2>
        <p class="modal-subtitle"><?= $lang === 'en' ? 'Send your offer to the seller. The price cannot be lower than the starting price (' : 'Отправьте продавцу своё предложение по этому лоту. Цена должна быть не ниже начальной (' ?><span id="startPriceDisplay"><?= number_format((float)$lot['price'], 0, '.', ' ') ?></span> ₽).</p>
        <form method="POST" enctype="multipart/form-data" id="offerForm">
            <input type="hidden" name="action" value="make_offer">
            <input type="hidden" name="ecp_signature_b64" id="offerEcpSig">
            <input type="hidden" name="ecp_cert_pem"      id="offerEcpCert">
            <div class="form-group">
                <label class="form-label" for="offer_price"><?= $lang === 'en' ? 'Your price, ₽' : 'Ваша цена, ₽' ?></label>
                <input class="form-input" type="text" id="offer_price" name="price" placeholder="Например, <?= number_format((float)$lot['price'] + 1000, 0, '.', ' ') ?>" required>
                <div class="form-note" id="priceError" style="color:#dc2626; display:none;"><?= $lang === 'en' ? 'Price cannot be lower than the starting price' : 'Цена не может быть ниже начальной' ?></div>
            </div>
            <div class="form-group"><label class="form-label" for="offer_email"><?= $lang === 'en' ? 'E-mail' : 'E-mail' ?></label><input class="form-input" type="email" id="offer_email" name="email" placeholder="<?= $lang === 'en' ? 'you@example.com' : 'you@example.com' ?>" required></div>
            <div class="form-group"><label class="form-label" for="offer_contact_type"><?= $lang === 'en' ? 'Preferred contact method' : 'Удобный способ связи' ?></label><select class="form-select" id="offer_contact_type" name="contact_type" onchange="updateOfferContactPlaceholder()"><option value="phone"><?= $lang === 'en' ? 'Phone' : 'Телефон' ?></option><option value="email">E-mail</option><option value="telegram">Telegram</option></select></div>
            <div class="form-group"><label class="form-label" for="offer_contact_value"><?= $lang === 'en' ? 'Contact details' : 'Контакт для связи' ?></label><input class="form-input" type="text" id="offer_contact_value" name="contact_value" placeholder="+7..." required></div>
            <div class="form-group"><label class="form-label" for="offer_comment"><?= $lang === 'en' ? 'Comment' : 'Комментарий' ?></label><textarea class="form-textarea" id="offer_comment" name="comment" placeholder="<?= $lang === 'en' ? 'Specify the terms, timeline, details of your offer' : 'Уточните условия, сроки, детали предложения' ?>"></textarea></div>
            <div class="form-group"><label class="form-label" for="offer_file"><?= $lang === 'en' ? 'File (optional)' : 'Файл (необязательно)' ?></label><input class="form-input" type="file" id="offer_file" name="offer_file" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"><div class="form-note"><?= $lang === 'en' ? 'Up to 3 MB.' : 'До 3 МБ.' ?></div></div>
            <!-- Опциональная подпись офера через ЭЦП. На время разработки скрыта.
                 TODO[ECP]: убрать display:none когда подключим боевую крипту. -->
            <div style="margin:8px 0; padding:10px; background:#f1f5f9; border-radius:8px; font-size:13px; opacity:0.6;">
                <label style="display:flex; align-items:center; gap:8px; cursor:not-allowed;">
                    <input type="checkbox" id="offerSignEcp" disabled>
                    <span style="color:#64748b;">
                        <?= $lang === 'en' ? 'Sign offer with ЭЦП' : 'Подписать предложение ЭЦП' ?>
                        <span style="margin-left:6px; padding:1px 6px; background:#fbbf24; color:#0f172a; border-radius:8px; font-size:10px; font-weight:600; white-space:nowrap;">
                            <?= $lang === 'en' ? 'in dev' : 'в разработке' ?>
                        </span>
                    </span>
                </label>
                <div id="offerEcpStatus" style="font-size:12px; color:#64748b; margin-top:4px;"></div>
            </div>
            <div class="btn-row">
                <button type="button" class="btn btn-secondary" onclick="closeModal('offerModal')"><?= $lang === 'en' ? 'Cancel' : 'Отмена' ?></button>
                <button type="submit" class="btn btn-primary" id="offerSubmitBtn"><?= $lang === 'en' ? 'Send offer' : 'Отправить предложение' ?></button>
            </div>
        </form>
    </div>
</div>

<div id="interestModal" class="modal">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeModal('interestModal')">×</button>
        <h2 class="modal-title"><?= $lang === 'en' ? 'Inspection request' : 'Заявка на осмотр' ?></h2>
        <p class="modal-subtitle"><?= $lang === 'en' ? 'Provide your details and a preferred way to reach you. The desired inspection date must be at least 3 business days away (excluding weekends).' : 'Укажите свои данные и удобный способ связи. Желаемая дата осмотра – не ранее чем через 3 рабочих дня (без учёта выходных).' ?></p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="interest">
            <div class="form-group"><label class="form-label" for="interest_full_name"><?= $lang === 'en' ? 'Full name' : 'ФИО' ?></label><input class="form-input" type="text" id="interest_full_name" name="full_name" required></div>
            <div class="form-group"><label class="form-label" for="interest_reg_address"><?= $lang === 'en' ? 'Registration address' : 'Адрес регистрации' ?></label><textarea class="form-textarea" id="interest_reg_address" name="registration_address" required></textarea></div>
            <div class="form-group"><label class="form-label" for="interest_message"><?= $lang === 'en' ? 'Message' : 'Сообщение' ?></label><textarea class="form-textarea" id="interest_message" name="message" placeholder="<?= $lang === 'en' ? 'Tell us when you would like to inspect the lot' : 'Напишите, когда хотите осмотреть лот' ?>" required></textarea></div>
            <div class="form-group"><label class="form-label" for="interest_contact_type"><?= $lang === 'en' ? 'Contact type' : 'Тип контакта' ?></label><select class="form-select" id="interest_contact_type" name="contact_type"><option value="email">Email</option><option value="phone"><?= $lang === 'en' ? 'Phone' : 'Телефон' ?></option><option value="telegram">Telegram</option><option value="whatsapp">WhatsApp</option></select></div>
            <div class="form-group"><label class="form-label" for="interest_contact_value"><?= $lang === 'en' ? 'Contact details' : 'Контактные данные' ?></label><input class="form-input" type="text" id="interest_contact_value" name="contact_value" required></div>
            <div class="form-group"><label class="form-label" for="inspection_date"><?= $lang === 'en' ? 'Preferred inspection date and time' : 'Желаемая дата и время осмотра' ?></label>
                <input class="form-input" type="datetime-local" id="inspection_date" name="inspection_date" min="<?= $minInspectionDate ?>">
            </div>
            <div class="form-group"><label class="form-label" for="interest_file"><?= $lang === 'en' ? 'File (optional)' : 'Файл (необязательно)' ?></label><input class="form-input" type="file" id="interest_file" name="interest_file" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"><div class="form-note"><?= $lang === 'en' ? 'Up to 3 MB.' : 'До 3 МБ.' ?></div></div>
            <div class="btn-row">
                <button type="button" class="btn btn-secondary" onclick="closeModal('interestModal')"><?= $lang === 'en' ? 'Cancel' : 'Отмена' ?></button>
                <button type="submit" class="btn btn-primary"><?= $lang === 'en' ? 'Submit request' : 'Отправить заявку' ?></button>
            </div>
        </form>
    </div>
</div>

<div id="contactSellerModal" class="modal">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeModal('contactSellerModal')">×</button>
        <h2 class="modal-title"><?= $lang === 'en' ? 'Contact the seller' : 'Связаться с продавцом' ?></h2>
        <p class="modal-subtitle"><?= $lang === 'en' ? 'Leave a message and contact for follow-up.' : 'Оставьте сообщение и контакт для обратной связи.' ?></p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="contact_seller">
            <div class="form-group"><label class="form-label" for="contact_message"><?= $lang === 'en' ? 'Message' : 'Сообщение' ?></label><textarea class="form-textarea" id="contact_message" name="message" required></textarea></div>
            <div class="form-group"><label class="form-label" for="contact_value_main"><?= $lang === 'en' ? 'Your contact' : 'Ваш контакт' ?></label><input class="form-input" type="text" id="contact_value_main" name="contact" placeholder="<?= $lang === 'en' ? 'Phone, email, Telegram' : 'Телефон, email, Telegram' ?>" required></div>
            <div class="form-group"><label class="form-label" for="contact_file"><?= $lang === 'en' ? 'File (optional)' : 'Файл (необязательно)' ?></label><input class="form-input" type="file" id="contact_file" name="contact_file" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"><div class="form-note"><?= $lang === 'en' ? 'Up to 3 MB.' : 'До 3 МБ.' ?></div></div>
            <div class="btn-row">
                <button type="button" class="btn btn-secondary" onclick="closeModal('contactSellerModal')"><?= $lang === 'en' ? 'Cancel' : 'Отмена' ?></button>
                <button type="submit" class="btn btn-primary"><?= $lang === 'en' ? 'Send message' : 'Отправить сообщение' ?></button>
            </div>
        </form>
    </div>
</div>

<div id="upgradeModal" class="modal">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeModal('upgradeModal')">×</button>
        <h2 class="modal-title"><?= $lang === 'en' ? 'Get lot details' : 'Получить подробности по лоту' ?></h2>
        <p class="modal-subtitle"><?= $lang === 'en' ? 'Choose a plan and a payment method. After paying, upload the receipt for review.' : 'Выберите тариф и способ оплаты. После оплаты загрузите квитанцию для проверки.' ?></p>
        <div class="tariff-grid">
            <div class="tariff-card" data-tariff="details" onclick="selectTariff(this)">
                <div class="tariff-name"><?= $lang === 'en' ? 'Lot report' : 'Отчёт по лоту' ?></div>
                <div class="tariff-price">1 390 ₽</div>
                <div class="tariff-desc"><?= $lang === 'en' ? 'Extended information' : 'Расширенная информация' ?></div>
            </div>
            <div class="tariff-card" data-tariff="responsible" onclick="selectTariff(this)">
                <div class="tariff-name"><?= $lang === 'en' ? '“Responsible” status' : 'Статус «Ответственный»' ?></div>
                <div class="tariff-price">8 000 ₽</div>
                <div class="tariff-desc"><?= $lang === 'en' ? 'Priority handling' : 'Приоритетное сопровождение' ?></div>
            </div>
        </div>
        <div id="paymentDetails" class="payment-details"></div>
        <div id="emailFieldBlock" style="display: none; margin:12px 0;">
            <label class="form-label" for="user_email"><?= $lang === 'en' ? 'Email to receive the report' : 'Email для получения отчёта' ?></label>
            <input type="email" id="user_email" class="form-input" value="<?= e($user_email_from_profile) ?>" placeholder="example@mail.ru" <?= $session_id > 0 ? 'readonly' : 'required' ?>>
            <?php if ($session_id > 0): ?>
                <div class="form-note"><?= $lang === 'en' ? 'The report will be sent to the email from your profile.' : 'Отчёт будет отправлен на email из профиля.' ?></div>
            <?php else: ?>
                <div class="form-note"><?= $lang === 'en' ? 'We will send the report to this email after confirmation.' : 'На этот email отправим отчёт после подтверждения.' ?></div>
            <?php endif; ?>
        </div>
        <div id="paymentMethods" class="payment-methods">
            <div class="payment-methods-grid">
                <div id="paymentqr" class="payment-method selected" onclick="selectPaymentMethod('qr')"><?= $lang === 'en' ? 'Pay by QR' : 'Оплата по QR' ?></div>
                <div id="paymentreceipt" class="payment-method" onclick="selectPaymentMethod('receipt')"><?= $lang === 'en' ? 'Download receipt' : 'Скачать квитанцию' ?></div>
            </div>
            <div id="qrblock" class="qr-block"><img id="qrimage" src=""><div><?= $lang === 'en' ? 'Scan the QR code in your banking app' : 'Отсканируйте QR в приложении' ?></div></div>
            <div id="receiptblock" class="receipt-reg-block"><button class="receipt-generate-btn" onclick="generateReceipt()"><?= $lang === 'en' ? 'Generate receipt' : 'Сформировать квитанцию' ?></button></div>
            <div id="receiptFormBlock" style="display:none; margin-top:12px; border-top:1px solid #e2e8f0; padding-top:14px;">
                <form id="upgradeReceiptForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="submit_receipt">
                    <input type="hidden" name="tariff" id="receipttariff" value="">
                    <input type="hidden" name="amount" id="receiptamount" value="">
                    <input type="hidden" name="user_email" id="receipt_email" value="">
                    <div class="form-group"><label for="receipt_file"><?= $lang === 'en' ? 'Receipt file' : 'Файл квитанции' ?></label><input type="file" id="receipt_file" name="receipt_file" accept="image/*,application/pdf" required></div>
                    <div class="form-group"><label for="receipt_comment"><?= $lang === 'en' ? 'Comment' : 'Комментарий' ?></label><textarea id="receipt_comment" name="comment" rows="2"></textarea></div>
                    <button type="submit" class="btn btn-primary" style="width:100%"><?= $lang === 'en' ? 'Submit for review' : 'Отправить на проверку' ?></button>
                </form>
                <div id="upgradeSuccessBlock" class="success-block">...</div>
            </div>
            <div class="btn-row" id="actionButtons">
                <button type="button" class="btn btn-secondary" onclick="closeModal('upgradeModal')"><?= $lang === 'en' ? 'Cancel' : 'Отмена' ?></button>
                <button type="button" class="btn btn-primary" onclick="markAsPaid()"><?= $lang === 'en' ? 'I have paid' : 'Я оплатил(а)' ?></button>
            </div>
        </div>
    </div>
</div>

<script>
<?php if (!empty($images)): ?>
let torgiImages = <?= json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let torgiCurrent = 0;
function setTorgiImage(idx) { if (idx<0 || idx>=torgiImages.length) return; torgiCurrent=idx; const main=document.getElementById('torgiMainImage'); if(main) main.src=torgiImages[torgiCurrent]; document.querySelectorAll('#torgiThumbs .torgi-thumb').forEach((el,i)=>el.classList.toggle('active',i===torgiCurrent)); }
function changeTorgiImage(dir) { if(!torgiImages.length) return; let next=torgiCurrent+dir; if(next<0) next=torgiImages.length-1; if(next>=torgiImages.length) next=0; setTorgiImage(next); }
function openImageModalFromMain() { const img=document.getElementById('fullImage'); if(img) img.src=torgiImages[torgiCurrent]; openModal('imageModal'); }
function changeModalImage(dir) { changeTorgiImage(dir); const img=document.getElementById('fullImage'); if(img) img.src=torgiImages[torgiCurrent]; }
<?php endif; ?>

function openModal(id) { const m=document.getElementById(id); if(m) { m.classList.add('active'); document.body.style.overflow='hidden'; } }
function closeModal(id) { const m=document.getElementById(id); if(m) { m.classList.remove('active'); document.body.style.overflow=''; } }
document.querySelectorAll('.modal').forEach(m=>{ m.addEventListener('click',e=>{ if(e.target===m) closeModal(m.id); }); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.modal.active').forEach(m=>closeModal(m.id)); });

function updateOfferContactPlaceholder() {
    const sel = document.getElementById('offer_contact_type');
    const inp = document.getElementById('offer_contact_value');
    if (!sel || !inp) return;
    const map = { phone: '+7 (___) ___-__-__', email: 'you@example.com', telegram: '@username' };
    inp.placeholder = map[sel.value] || '';
}
document.addEventListener('DOMContentLoaded', updateOfferContactPlaceholder);

let selectedTariff=null, currentAmount=0, currentTariffName='';

function selectTariff(el) {
    document.querySelectorAll('.tariff-card').forEach(c=>{c.style.borderColor='#e2e8f0';c.style.background='#f8fafc';});
    el.style.borderColor='#0ea5e9'; el.style.background='#eff6ff';
    selectedTariff=el.dataset.tariff;
    if(selectedTariff==='details'){
        const rpInputEl = document.getElementById('rpInput');
        const liveRp = rpInputEl && rpInputEl.value !== '' ? parseInt(rpInputEl.value, 10) : NaN;
        currentAmount = (!isNaN(liveRp) && liveRp >= 0) ? liveRp : <?= (isset($lot['report_price']) && $lot['report_price'] !== null && $lot['report_price'] !== '') ? (int)$lot['report_price'] : 1390 ?>;
        currentTariffName='Отчёт по лоту';
    }
    else { currentAmount=8000; currentTariffName='Статус Ответственный'; }
    let vat=Math.round(currentAmount*22/122);
    let pd=document.getElementById('paymentDetails');
    pd.style.display='block';
    pd.innerHTML='<div style="font-weight:700">'+currentTariffName+'</div><div>'+currentAmount.toLocaleString()+' ₽, в т.ч. НДС '+vat.toLocaleString()+' ₽ (22%)</div>';
    document.getElementById('paymentMethods').style.display='block';
    document.getElementById('receipttariff').value=currentTariffName;
    document.getElementById('receiptamount').value=currentAmount;
    let qrData='ST00012|Name=ООО «Форсаж»|PersonalAcc=40702810101500033019|BankName=ООО «Банк Точка»|BIC=044525104|CorrespAcc=30101810745374525104|PayeeINN=7728282160|KPP=773001001|Sum='+(currentAmount*100)+'|Purpose=Оплата услуг по лоту, сумма '+currentAmount+' руб., в т.ч. НДС 22%';
    document.getElementById('qrimage').src='https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='+encodeURIComponent(qrData);
    document.getElementById('qrblock').style.display='block';
    document.getElementById('receiptblock').style.display='none';
    document.getElementById('receiptFormBlock').style.display='none';
    document.getElementById('actionButtons').style.display='flex';
    let eb=document.getElementById('emailFieldBlock');
    if(selectedTariff==='details') eb.style.display='block';
    else eb.style.display='none';
}
function selectPaymentMethod(m){
    document.getElementById('paymentqr').classList.remove('selected');
    document.getElementById('paymentreceipt').classList.remove('selected');
    document.getElementById('payment'+m).classList.add('selected');
    document.getElementById('qrblock').style.display=m==='qr'?'block':'none';
    document.getElementById('receiptblock').style.display=m==='receipt'?'block':'none';
}
function generateReceipt(){
    if(!selectedTariff||!currentAmount){alert('Выберите тариф');return;}
    window.open('receipt_torgi.php?lot_id=<?=$id?>&tariff='+encodeURIComponent(currentTariffName)+'&amount='+currentAmount,'_blank','width=700,height=800');
}
function markAsPaid(){
    if(!selectedTariff||!currentAmount){alert('Выберите тариф');return;}
    let email=document.getElementById('user_email')?document.getElementById('user_email').value:'';
    document.getElementById('receipt_email').value=email;
    document.getElementById('receiptFormBlock').style.display='block';
    document.getElementById('actionButtons').style.display='none';
}
document.getElementById('upgradeReceiptForm')?.addEventListener('submit',function(e){
    if(!document.getElementById('receipttariff').value||!document.getElementById('receiptamount').value){
        alert('Сначала выберите тариф');
        e.preventDefault();
    }
});

// ========== ВАЛИДАЦИЯ ПРЕДЛОЖЕНИЯ ЦЕНЫ ==========
const startPrice = <?= (float)$lot['price'] ?>;
const offerPriceInput = document.getElementById('offer_price');
const priceErrorSpan = document.getElementById('priceError');
const offerSubmitBtn = document.getElementById('offerSubmitBtn');
function validateOfferPrice() {
    if (offerPriceInput) {
        let value = parseFloat(offerPriceInput.value.replace(/\s/g, ''));
        if (isNaN(value)) value = 0;
        if (value < startPrice) {
            priceErrorSpan.style.display = 'block';
            offerSubmitBtn.disabled = true;
        } else {
            priceErrorSpan.style.display = 'none';
            offerSubmitBtn.disabled = false;
        }
    }
}
if (offerPriceInput) {
    offerPriceInput.addEventListener('input', validateOfferPrice);
    offerPriceInput.addEventListener('blur', validateOfferPrice);
}

/* Перехват сабмита оффера: если включён чекбокс «Подписать ЭЦП», сначала подписываем
   payload (lot_id+price+comment+user) через ECP.sign и кладём signature/cert в hidden-поля,
   только потом сабмитим форму. Если плагин не сработал — отправляем без подписи. */
(function(){
    const f = document.getElementById('offerForm');
    if (!f) return;
    f.addEventListener('submit', async function(e){
        const cb = document.getElementById('offerSignEcp');
        if (!cb || !cb.checked) return; // обычная отправка
        if (!window.ECP) return;
        e.preventDefault();
        const status = document.getElementById('offerEcpStatus');
        if (status) status.textContent = 'Подписываю ЭЦП...';
        const payload = {
            lot_id: <?= (int)$lot['id'] ?>,
            price:  document.getElementById('offer_price') ? document.getElementById('offer_price').value : '',
            comment:document.getElementById('offer_comment') ? document.getElementById('offer_comment').value : '',
            ts:     Date.now()
        };
        try {
            /* Лог отдельной подписью; target_id назначим позже на сервере при INSERT'е оффера. */
            const r = await ECP.sign('commission_offer', null, payload);
            if (r && r.success) {
                /* Кладём в hidden-поля для прикрепления к INSERT'у. */
                /* Заметка: реальная подпись произойдёт через cadesplugin при подключении.
                   Сейчас ECP.sign шлёт DEMO. Для сервера это ОК — он формально
                   валидирует и запишет в ecp_signatures. */
            } else if (status) {
                status.textContent = 'ЭЦП: ' + (r && r.error ? r.error : 'ошибка');
            }
            /* Дублируем подпись через hidden-поля (на всякий случай, если хотим
               записать связку оффера и подписи в один INSERT). */
            const sigEl  = document.getElementById('offerEcpSig');
            const certEl = document.getElementById('offerEcpCert');
            if (sigEl)  sigEl.value  = (r && r.success) ? '1' : '';
            if (certEl) certEl.value = (r && r.success) ? '1' : '';
        } catch (err) {
            if (status) status.textContent = 'ЭЦП ошибка: ' + (err.message || err);
        }
        f.submit();
    });
})();

/* Сохранение цены отчёта по лоту (только админ — `torgi`-таблица). */
function saveReportPrice() {
    const inp = document.getElementById('rpInput');
    const msg = document.getElementById('rpMsg');
    if (!inp || !msg) return;
    const fd = new FormData();
    fd.append('lot_id', '<?= (int)$lot['id'] ?>');
    fd.append('table', 'torgi');
    fd.append('report_price', inp.value);
    msg.textContent = 'Сохраняю...';
    msg.style.color = '#64748b';
    fetch('update_report_price.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                msg.textContent = 'Сохранено: ' + d.effective.toLocaleString('ru-RU') + ' ₽';
                msg.style.color = '#16a34a';
            } else {
                msg.textContent = 'Ошибка: ' + (d.error || 'неизвестно');
                msg.style.color = '#dc2626';
            }
        })
        .catch(() => {
            msg.textContent = 'Ошибка сети';
            msg.style.color = '#dc2626';
        });
}
</script>

<?php include 'footer.php'; ?>