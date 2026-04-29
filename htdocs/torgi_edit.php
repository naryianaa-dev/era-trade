<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['usertype'] ?? '', ['admin', 'organizer'])) {
    header('Location: index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: torgi_list.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM torgi WHERE id = ?");
$stmt->execute([$id]);
$lot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lot) die('Лот не найден');

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $region = trim($_POST['region'] ?? '');
    $lot_type = trim($_POST['lot_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'open';
    
    $pdo->prepare("UPDATE torgi SET title=?, price=?, region=?, lot_type=?, description=?, status=? WHERE id=?")
        ->execute([$title, $price, $region, $lot_type, $description, $status, $id]);
    
    // ========== УПРАВЛЕНИЕ ФОТОГРАФИЯМИ ==========
    // 1. Получаем текущий JSON изображений
    $images = [];
    if (!empty($lot['images'])) {
        $decoded = json_decode($lot['images'], true);
        if (is_array($decoded)) $images = array_values(array_filter($decoded, fn($img) => is_string($img) && trim($img) !== ''));
    }
    
    // 2. Удаление отмеченных фото
    if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
        foreach ($_POST['delete_images'] as $idx) {
            $i = (int)$idx;
            if (isset($images[$i])) {
                $path = $images[$i];
                if ($path && file_exists($path)) @unlink($path);
                unset($images[$i]);
            }
        }
        $images = array_values($images); // переиндексация
    }
    
    // 3. Добавление новых фото
    if (isset($_FILES['new_images']) && !empty($_FILES['new_images']['name'][0])) {
        $uploadDir = 'uploads/torgi/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $total = count($_FILES['new_images']['name']);
        for ($i=0; $i<$total; $i++) {
            if ($_FILES['new_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $size = $_FILES['new_images']['size'][$i];
            if ($size > 5*1024*1024) continue; // 5MB max
            $ext = strtolower(pathinfo($_FILES['new_images']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) continue;
            $filename = 'lot_' . $id . '_' . time() . '_' . $i . '.' . $ext;
            $target = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['new_images']['tmp_name'][$i], $target)) {
                $images[] = $target;
            }
        }
    }
    
    // 4. Выбор основного фото (перестановка)
    if (isset($_POST['main_image_index']) && $_POST['main_image_index'] !== '') {
        $mainIdx = (int)$_POST['main_image_index'];
        if ($mainIdx >= 0 && $mainIdx < count($images)) {
            $mainImage = $images[$mainIdx];
            unset($images[$mainIdx]);
            array_unshift($images, $mainImage); // ставим на первое место
        }
    }
    
    // 5. Сохраняем JSON обратно
    $pdo->prepare("UPDATE torgi SET images = ? WHERE id = ?")->execute([json_encode($images, JSON_UNESCAPED_UNICODE), $id]);
    
    // ========== УПРАВЛЕНИЕ PDF (без изменений) ==========
    // Удаление PDF
    if (isset($_POST['delete_pdf']) && is_array($_POST['delete_pdf'])) {
        foreach ($_POST['delete_pdf'] as $file_id) {
            $stmt = $pdo->prepare("SELECT file_path FROM lot_files WHERE id = ? AND lot_id = ?");
            $stmt->execute([$file_id, $id]);
            $file = $stmt->fetch();
            if ($file && file_exists($file['file_path'])) unlink($file['file_path']);
            $pdo->prepare("DELETE FROM lot_files WHERE id = ? AND lot_id = ?")->execute([$file_id, $id]);
        }
    }
    
    // Обновление доступа к PDF
    if (isset($_POST['pdf_access']) && is_array($_POST['pdf_access'])) {
        foreach ($_POST['pdf_access'] as $file_id => $access) {
            if (in_array($access, ['public', 'paid'])) {
                $pdo->prepare("UPDATE lot_files SET access_level = ? WHERE id = ? AND lot_id = ?")
                    ->execute([$access, $file_id, $id]);
            }
        }
    }
    
    // Загрузка новых PDF
    if (isset($_FILES['pdf_files']) && !empty($_FILES['pdf_files']['name'][0])) {
        $upload_dir = 'uploads/lot_files/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        foreach ($_FILES['pdf_files']['tmp_name'] as $i => $tmp_name) {
            if ($_FILES['pdf_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig_name = basename($_FILES['pdf_files']['name'][$i]);
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') continue;
            $new_name = 'lot_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $target = $upload_dir . $new_name;
            if (move_uploaded_file($tmp_name, $target)) {
                $access = $_POST['pdf_access_new'][$i] ?? 'public';
                $pdo->prepare("INSERT INTO lot_files (lot_id, file_name, file_path, access_level, created_at) VALUES (?, ?, ?, ?, NOW())")
                    ->execute([$id, $orig_name, $target, $access]);
            }
        }
    }
    
    $msg = 'Сохранено';
    // Перезагружаем лот
    $stmt = $pdo->prepare("SELECT * FROM torgi WHERE id = ?");
    $stmt->execute([$id]);
    $lot = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Получаем PDF для этого лота
$pdf_files = $pdo->prepare("SELECT * FROM lot_files WHERE lot_id = ? ORDER BY id");
$pdf_files->execute([$id]);
$pdf_files = $pdf_files->fetchAll(PDO::FETCH_ASSOC);

// Получаем текущие изображения для отображения в форме
$current_images = [];
if (!empty($lot['images'])) {
    $decoded = json_decode($lot['images'], true);
    if (is_array($decoded)) $current_images = array_values(array_filter($decoded, fn($img) => is_string($img) && trim($img) !== ''));
}

include 'header.php';
?>
<style>
.form-group { margin-bottom: 20px; }
.form-input, .form-textarea, .form-select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
}
.btn-primary { background: #0ea5e9; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; }
.pdf-item, .image-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 8px 0;
    padding: 6px;
    background: #f8fafc;
    border-radius: 8px;
    flex-wrap: wrap;
}
.pdf-upload-group, .image-upload-group {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-top: 8px;
}
.btn-secondary {
    background: #e2e8f0;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    border: none;
}
.form-note { font-size: 12px; color: #64748b; margin-top: 4px; }
.image-thumb { max-width: 80px; max-height: 60px; border-radius: 6px; overflow: hidden; }
.image-thumb img { width: 100%; height: auto; }
.radio-group { margin-left: 10px; }
</style>
<main style="padding: 20px; max-width: 900px; margin: 0 auto;">
    <h1>Редактирование лота #<?= $id ?></h1>
    <?php if ($msg): ?>
        <div style="background: #d1fae5; padding: 12px; border-radius: 8px; margin-bottom: 16px;"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Название</label>
            <input type="text" name="title" value="<?= htmlspecialchars($lot['title']) ?>" class="form-input" required>
        </div>
        <div class="form-group">
            <label>Цена (₽)</label>
            <input type="number" name="price" value="<?= (float)$lot['price'] ?>" step="1" class="form-input" required>
        </div>
        <div class="form-group">
            <label>Регион</label>
            <input type="text" name="region" value="<?= htmlspecialchars($lot['region']) ?>" class="form-input">
        </div>
        <div class="form-group">
            <label>Категория</label>
            <input type="text" name="lot_type" value="<?= htmlspecialchars($lot['lot_type']) ?>" class="form-input">
        </div>
        <div class="form-group">
            <label>Описание</label>
            <textarea name="description" rows="6" class="form-textarea"><?= htmlspecialchars($lot['description']) ?></textarea>
        </div>
        <div class="form-group">
            <label>Статус</label>
            <select name="status" class="form-select">
                <option value="open" <?= $lot['status'] === 'open' ? 'selected' : '' ?>>Открыт для предложений</option>
                <option value="closed" <?= $lot['status'] === 'closed' ? 'selected' : '' ?>>Сделка завершена</option>
            </select>
        </div>
        
        <!-- ========== БЛОК УПРАВЛЕНИЯ ФОТОГРАФИЯМИ С ВЫБОРОМ ОСНОВНОГО ФОТО ========== -->
        <div class="form-group">
            <label>Фотографии лота</label>
            <div id="images-list">
                <?php if (empty($current_images)): ?>
                    <div style="color:#94a3b8;">Нет загруженных фото</div>
                <?php else: ?>
                    <?php foreach ($current_images as $idx => $img): ?>
                    <div class="image-item">
                        <div class="image-thumb"><img src="<?= htmlspecialchars($img) ?>" alt="Фото"></div>
                        <span><?= basename($img) ?></span>
                        <label><input type="checkbox" name="delete_images[]" value="<?= $idx ?>"> Удалить</label>
                        <label class="radio-group">
                            <input type="radio" name="main_image_index" value="<?= $idx ?>" <?= $idx === 0 ? 'checked' : '' ?>> Основное фото
                        </label>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div id="new-images-container">
                <div class="image-upload-group">
                    <input type="file" name="new_images[]" accept="image/jpeg,image/png">
                </div>
            </div>
            <button type="button" id="add-more-images" class="btn-secondary">+ Добавить ещё фото</button>
            <div class="form-note">Поддерживаются JPG, PNG. Максимальный размер 5 МБ на файл. Отметьте радиокнопку «Основное фото» — это изображение будет показываться на карточке и первым на странице лота.</div>
        </div>
        
        <!-- ========== БЛОК УПРАВЛЕНИЯ PDF ========== -->
        <div class="form-group">
            <label>PDF-файлы (отчёты, документы)</label>
            <div id="pdf-files-list">
                <?php foreach ($pdf_files as $pdf): ?>
                <div class="pdf-item">
                    <span><?= htmlspecialchars($pdf['file_name']) ?></span>
                    <select name="pdf_access[<?= $pdf['id'] ?>]">
                        <option value="public" <?= $pdf['access_level'] === 'public' ? 'selected' : '' ?>>Публичный</option>
                        <option value="paid" <?= $pdf['access_level'] === 'paid' ? 'selected' : '' ?>>Только для купивших отчёт</option>
                    </select>
                    <label><input type="checkbox" name="delete_pdf[]" value="<?= $pdf['id'] ?>"> Удалить</label>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="new-pdfs-container">
                <div class="pdf-upload-group">
                    <input type="file" name="pdf_files[]" accept=".pdf">
                    <select name="pdf_access_new[]">
                        <option value="public">Публичный</option>
                        <option value="paid">Только для купивших отчёт</option>
                    </select>
                </div>
            </div>
            <button type="button" id="add-more-pdf" class="btn-secondary">+ Добавить ещё PDF</button>
            <div class="form-note">Поддерживаются только PDF. Максимальный размер: 10 МБ.</div>
        </div>
        
        <button type="submit" class="btn-primary">Сохранить изменения</button>
    </form>
</main>

<script>
// Добавление полей для новых фото
document.getElementById('add-more-images').addEventListener('click', function() {
    var container = document.getElementById('new-images-container');
    var newGroup = document.createElement('div');
    newGroup.className = 'image-upload-group';
    newGroup.style.marginTop = '8px';
    newGroup.innerHTML = '<input type="file" name="new_images[]" accept="image/jpeg,image/png">';
    container.appendChild(newGroup);
});
// Добавление полей для новых PDF
document.getElementById('add-more-pdf').addEventListener('click', function() {
    var container = document.getElementById('new-pdfs-container');
    var newGroup = document.createElement('div');
    newGroup.className = 'pdf-upload-group';
    newGroup.style.marginTop = '8px';
    newGroup.innerHTML = '<input type="file" name="pdf_files[]" accept=".pdf"><select name="pdf_access_new[]"><option value="public">Публичный</option><option value="paid">Только для купивших отчёт</option></select>';
    container.appendChild(newGroup);
});
</script>
<?php include 'footer.php'; ?>