<?php
session_start();
require_once 'db.php';

$message = "";
$is_auth = !empty($_SESSION['user_id']);

/* Поле «Стоимость отчёта» доступно только админу или одобренному организатору.
   Пустое значение = системный дефолт 1390 ₽ из purchase_report.php. */
$can_set_report_price = false;
if ($is_auth) {
    $st = $pdo->prepare("SELECT user_type, role FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    $ut = $u['user_type'] ?? '';
    $rl = $u['role'] ?? '';
    $can_set_report_price = in_array($ut, ['admin', 'organizer'], true)
        || in_array($rl, ['admin', 'organizer'], true);
}

// Создаём таблицу lot_files если нет
$pdo->exec("CREATE TABLE IF NOT EXISTS lot_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lot_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    access_level ENUM('public','paid') NOT NULL DEFAULT 'public',
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_auth) {
    $title       = trim($_POST['title'] ?? '');
    $region      = trim($_POST['region'] ?? '');
    $price       = (float)($_POST['price'] ?? 0);
    $lot_type    = trim($_POST['lot_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $errors      = [];
    $imagePaths  = [];

    /* Стоимость отчёта по лоту: принимаем только от админа/организатора. */
    $report_price = null;
    if ($can_set_report_price) {
        $rp_raw = $_POST['report_price'] ?? '';
        if ($rp_raw !== '' && is_numeric($rp_raw)) {
            $report_price = max(0, (int)$rp_raw);
        }
    }

    if ($title === '' || $region === '' || $price <= 0) {
        $errors[] = 'Заполните название, регион и цену.';
    }

    // Загрузка фотографий
    if (!empty($_FILES['images']['name'][0])) {
        $totalSize = 0;
        $uploadDir = __DIR__ . '/uploads/torgi/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $allowed = ['jpg', 'jpeg', 'png'];

        foreach ($_FILES['images']['name'] as $i => $name) {
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $size = (int)$_FILES['images']['size'][$i];
            $totalSize += $size;
            if ($totalSize > 5 * 1024 * 1024) { $errors[] = 'Общий размер фото не должен превышать 5 МБ.'; break; }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) { $errors[] = 'Фото: допустимые форматы jpg, jpeg, png.'; break; }
            $newName = 'torg_' . time() . '_' . $i . '.' . $ext;
            if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $uploadDir . $newName)) {
                $imagePaths[] = 'uploads/torgi/' . $newName;
            }
        }
    }

    if (empty($errors)) {
        try {
            $imagesJson = !empty($imagePaths) ? json_encode($imagePaths, JSON_UNESCAPED_UNICODE) : null;
            $stmt = $pdo->prepare("INSERT INTO torgi (title, price, region, status, lot_type, description, images, report_price, date_created) VALUES (?, ?, ?, 'open', ?, ?, ?, ?, NOW())");
            $stmt->execute([$title, $price, $region, $lot_type, $description, $imagesJson, $report_price]);
            $lot_id = (int)$pdo->lastInsertId();

            // Загрузка PDF-документов
            $pdfDir = __DIR__ . '/uploads/lot_files/';
            if (!is_dir($pdfDir)) mkdir($pdfDir, 0777, true);
            $allowedPdf = ['pdf', 'doc', 'docx'];

            foreach (['doc_public', 'doc_paid'] as $field) {
                $access = ($field === 'doc_public') ? 'public' : 'paid';
                if (empty($_FILES[$field]['name'][0])) continue;

                foreach ($_FILES[$field]['name'] as $i => $name) {
                    if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($_FILES[$field]['size'][$i] > 10 * 1024 * 1024) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedPdf, true)) continue;

                    $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($name, PATHINFO_FILENAME));
                    $filename = 'lot_' . $lot_id . '_' . $access . '_' . time() . '_' . $i . '.' . $ext;
                    $target = $pdfDir . $filename;

                    if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], $target)) {
                        $stmt2 = $pdo->prepare("INSERT INTO lot_files (lot_id, file_name, file_path, access_level, sort_order) VALUES (?, ?, ?, ?, ?)");
                        $stmt2->execute([$lot_id, $name, 'uploads/lot_files/' . $filename, $access, $i]);
                    }
                }
            }

            header('Location: torgi_view.php?id=' . $lot_id);
            exit;
        } catch (PDOException $e) {
            $message = "<div class=\'alert error\'>Ошибка: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
        }
    } else {
        $message = "<div class=\'alert error\'>" . implode('<br>', array_map('htmlspecialchars', $errors)) . "</div>";
    }
}

include 'header.php';
?>
<style>
    *, *::before, *::after { box-sizing: border-box; }
    .commission-wrap { flex:1; display:flex; align-items:center; justify-content:center; padding:20px; min-height:calc(100vh - 60px); background:#f8fafc; }
    .form-card { background:#fff; padding:32px; border-radius:24px; width:100%; max-width:640px; box-shadow:0 20px 60px rgba(15,23,42,0.2); max-height:calc(100vh - 40px); overflow-y:auto; }
    .form-card h2 { color:#0f172a; margin:0 0 8px; font-size:22px; font-weight:800; }
    .form-card .subtitle { margin-bottom:24px; color:#64748b; font-size:14px; }
    .f-group { margin-bottom:20px; display:flex; flex-direction:column; text-align:left; }
    .f-group label { font-size:13px; font-weight:700; color:#334155; margin-bottom:6px; }
    .f-group input, .f-group select, .f-group textarea { padding:12px 14px; border:1.5px solid #e2e8f0; border-radius:12px; font-size:15px; outline:none; transition:border-color 0.3s; font-family:inherit; width:100%; background:#fff; }
    .f-group textarea { resize:vertical; min-height:80px; }
    .f-group input:focus, .f-group select:focus, .f-group textarea:focus { border-color:#0ea5e9; box-shadow:0 0 0 2px rgba(14,165,233,0.1); }
    .f-group .hint { font-size:12px; color:#64748b; margin-top:4px; }
    .f-row { display:flex; gap:16px; }
    .f-row .f-group { flex:1; min-width:0; }
    .section-title { font-size:14px; font-weight:800; color:#0f172a; margin:24px 0 12px; padding-top:20px; border-top:1px solid #e2e8f0; }
    .access-block { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px; }
    .access-block-title { font-size:13px; font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:6px; }
    .access-block-desc { font-size:12px; color:#64748b; margin-bottom:10px; }
    .badge-public { background:#dcfce7; color:#166534; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; }
    .badge-paid { background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; }
    .submit-btn { width:100%; padding:14px; background:#0ea5e9; color:#fff; border:none; border-radius:12px; font-weight:700; cursor:pointer; transition:0.2s; margin-top:8px; font-size:15px; }
    .submit-btn:hover { background:#0284c7; transform:translateY(-1px); }
    .alert { padding:12px 16px; border-radius:12px; margin-bottom:20px; font-weight:600; font-size:14px; }
    .alert.error { background:#fee2e2; color:#991b1b; }
    .alert.success { background:#dcfce7; color:#166534; }
    @media (max-width:768px) { .commission-wrap { padding:16px; } .form-card { padding:20px; } .f-row { flex-direction:column; gap:0; } }
    @media (max-width:480px) { .form-card { padding:16px; } }
</style>

<main class="commission-wrap">
    <div class="form-card">
        <h2><?= $lang === 'en' ? 'List a lot' : 'Выставить лот' ?></h2>
        <p class="subtitle"><?= $lang === 'en' ? 'Fill in the details to publish the lot in the commission-sales registry' : 'Заполните данные для размещения лота в реестре комиссионной продажи' ?></p>

        <?= $message ?>

        <?php if (!$is_auth): ?>
            <div class="alert error">
                <?= $lang === 'en' ? 'To list a lot you need to' : 'Для подачи объявления необходимо' ?>
                <a href="#" onclick="openAuth && openAuth('login'); return false;" style="color:inherit;font-weight:800;"><?= $lang === 'en' ? 'sign in' : 'войти в систему' ?></a>.
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="f-group">
                    <label><?= $lang === 'en' ? 'Lot title' : 'Название лота' ?></label>
                    <input type="text" name="title" placeholder="<?= $lang === 'en' ? 'For example: Warehouse complex 500m²' : 'Например: Складской комплекс 500м²' ?>" required>
                </div>
                <div class="f-row">
                    <div class="f-group">
                        <label><?= $lang === 'en' ? 'Category' : 'Категория' ?></label>
                        <select name="lot_type" required>
                            <option value="Недвижимость"><?= $lang === 'en' ? 'Real estate' : 'Недвижимость' ?></option>
                            <option value="Транспорт"><?= $lang === 'en' ? 'Transport' : 'Транспорт' ?></option>
                            <option value="Оборудование"><?= $lang === 'en' ? 'Equipment' : 'Оборудование' ?></option>
                            <option value="Прочее"><?= $lang === 'en' ? 'Other' : 'Прочее' ?></option>
                        </select>
                    </div>
                    <div class="f-group">
                        <label><?= $lang === 'en' ? 'Price (₽)' : 'Цена (₽)' ?></label>
                        <input type="number" name="price" placeholder="500000" required>
                    </div>
                </div>

                <?php if ($can_set_report_price): ?>
                    <!-- Стоимость отчёта по лоту: видна только админу/организатору. -->
                    <div class="f-group">
                        <label><?= $lang === 'en' ? 'Vehicle report price (₽)' : 'Стоимость отчёта по лоту (₽)' ?></label>
                        <input type="number" name="report_price" step="1" min="0" placeholder="1390">
                        <div style="font-size:12px; color:#64748b; margin-top:4px;">
                            <?= $lang === 'en'
                                ? 'Leave empty to use default 1390 ₽. Charged to buyers who request the inspection report.'
                                : 'Оставьте пустым для значения по умолчанию 1390 ₽. Списывается с покупателей при заказе отчёта.' ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="f-group">
                    <label><?= $lang === 'en' ? 'Region' : 'Регион' ?></label>
                    <input type="text" name="region" placeholder="<?= $lang === 'en' ? 'e.g. Samara' : 'г. Самара' ?>" required>
                </div>
                <div class="f-group">
                    <label><?= $lang === 'en' ? 'Description' : 'Описание' ?></label>
                    <textarea name="description" rows="4" placeholder="<?= $lang === 'en' ? 'Main features, condition, highlights...' : 'Основные характеристики, состояние, особенности...' ?>"></textarea>
                </div>
                <div class="f-group">
                    <label><?= $lang === 'en' ? 'Lot photos' : 'Фотографии лота' ?></label>
                    <input type="file" name="images[]" accept=".jpg,.jpeg,.png" multiple>
                    <span class="hint"><?= $lang === 'en' ? 'You can upload several images (jpg, jpeg, png). Total size up to 5 MB.' : 'Можно загрузить несколько изображений (jpg, jpeg, png). Общий размер до 5 МБ.' ?></span>
                </div>

                <div class="section-title">📄 <?= $lang === 'en' ? 'Lot documents' : 'Документы к лоту' ?></div>

                <div class="access-block">
                    <div class="access-block-title">
                        <span class="badge-public"><?= $lang === 'en' ? 'Public' : 'Публичный' ?></span> <?= $lang === 'en' ? 'Documents for everyone' : 'Документы для всех' ?>
                    </div>
                    <div class="access-block-desc"><?= $lang === 'en' ? 'Visible to all visitors with no signup or payment. E.g. general description, photo-passport, technical conditions.' : 'Видны всем посетителям без регистрации и оплаты. Например: общее описание, фото-паспорт, техусловия.' ?></div>
                    <div class="f-group" style="margin-bottom:0;">
                        <input type="file" name="doc_public[]" accept=".pdf,.doc,.docx" multiple>
                        <span class="hint">PDF, DOC, DOCX. <?= $lang === 'en' ? 'Up to 10 MB per file. Multiple files allowed.' : 'До 10 МБ на файл. Можно несколько.' ?></span>
                    </div>
                </div>

                <div class="access-block">
                    <div class="access-block-title">
                        <span class="badge-paid"><?= $lang === 'en' ? 'Paid' : 'Платный' ?></span> <?= $lang === 'en' ? 'Documents for buyers of the report' : 'Документы для оплативших отчёт' ?>
                    </div>
                    <div class="access-block-desc"><?= $lang === 'en' ? 'Visible only after purchasing the “Lot report” plan (1,390 ₽). E.g. valuation report, technical passport, legal opinion.' : 'Видны только после оплаты тарифа «Отчёт по лоту» (1 390 ₽). Например: оценочный отчёт, технический паспорт, юридическая экспертиза.' ?></div>
                    <div class="f-group" style="margin-bottom:0;">
                        <input type="file" name="doc_paid[]" accept=".pdf,.doc,.docx" multiple>
                        <span class="hint">PDF, DOC, DOCX. До 10 МБ на файл. Можно несколько.</span>
                    </div>
                </div>

                <button type="submit" class="submit-btn"><?= $lang === 'en' ? 'Publish lot' : 'Опубликовать лот' ?></button>
            </form>
        <?php endif; ?>
    </div>
</main>

<?php include 'footer.php'; ?>
