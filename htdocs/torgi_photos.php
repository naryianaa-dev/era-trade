<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
header('Content-Type: text/html; charset=utf-8');

require_once 'db.php';

$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET CHARACTER SET utf8mb4");

// Только админ
if (empty($_SESSION['user_id']) || ($_SESSION['usertype'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$lotId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($lotId <= 0) {
    echo "Неверный ID лота";
    exit;
}

// Загрузка лота
$stmt = $pdo->prepare("SELECT id, title, images FROM torgi WHERE id = ?");
$stmt->execute([$lotId]);
$lot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lot) {
    echo "Лот не найден";
    exit;
}

// Текущие изображения
$images = [];
if (!empty($lot['images'])) {
    $tmp = json_decode($lot['images'], true);
    if (is_array($tmp)) {
        $images = $tmp;
    }
}

// ОБРАБОТКА POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) удаление отмеченных
    if (!empty($_POST['delete_images']) && is_array($_POST['delete_images'])) {
        foreach ($_POST['delete_images'] as $idx) {
            $i = (int)$idx;
            if (isset($images[$i])) {
                $path = $images[$i];
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
                unset($images[$i]);
            }
        }
        $images = array_values($images);
    }

    // 2) добавление новых (общий лимит 5 МБ)
    if (!empty($_FILES['new_images']['name'][0])) {
        $uploadDir = 'uploads/torgi';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $total = count($_FILES['new_images']['name']);

        // суммарный размер
        $totalSize = 0;
        for ($i = 0; $i < $total; $i++) {
            if ($_FILES['new_images']['error'][$i] === UPLOAD_ERR_OK) {
                $totalSize += (int)$_FILES['new_images']['size'][$i];
            }
        }

        if ($totalSize > 5 * 1024 * 1024) {
            $_SESSION['admin_msg'] = 'Суммарный размер новых фото превышает 5 МБ. Уберите лишние файлы.';
        } else {
            for ($i = 0; $i < $total; $i++) {
                if ($_FILES['new_images']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                if ($_FILES['new_images']['size'][$i] > 5 * 1024 * 1024) {
                    continue;
                }

                $ext = strtolower(pathinfo($_FILES['new_images']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png'], true)) {
                    continue;
                }

                $filename = 'lot_' . $lotId . '_' . time() . '_' . $i . '.' . $ext;
                $target   = $uploadDir . '/' . $filename;

                if (move_uploaded_file($_FILES['new_images']['tmp_name'][$i], $target)) {
                    $images[] = $target;
                }
            }

            if (empty($_SESSION['admin_msg'])) {
                $_SESSION['admin_msg'] = 'Фото лота обновлены';
            }
        }
    }

    // 3) сохраняем JSON в torgi и уходим обратно
    $stmt = $pdo->prepare("UPDATE torgi SET images = ? WHERE id = ?");
    $stmt->execute([json_encode($images, JSON_UNESCAPED_UNICODE), $lotId]);

    header('Location: torgi_photos.php?id=' . $lotId);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Фото лота #<?= (int)$lot['id'] ?></title>
    <style>
        body {
            background:#0f172a;
            color:#e2e8f0;
            font-family: sans-serif;
            padding:30px 20px;
        }
        .wrap {
            max-width: 900px;
            margin:0 auto;
            background:#020617;
            border-radius:16px;
            padding:20px 20px 24px;
            border:1px solid #1e293b;
        }
        h1 { margin-bottom:10px; }
        a.back {
            display:inline-flex;
            align-items:center;
            gap:6px;
            font-size:13px;
            color:#94a3b8;
            text-decoration:none;
            margin-bottom:12px;
        }
        .msg {
            background:#1e293b;
            border-left:4px solid #3b82f6;
            padding:10px 14px;
            border-radius:8px;
            margin-bottom:15px;
            font-size:13px;
        }
        .grid {
            display:grid;
            grid-template-columns: repeat(auto-fill,minmax(140px,1fr));
            gap:12px;
            margin:15px 0;
        }
        .item {
            background:#020617;
            border-radius:10px;
            border:1px solid #1e293b;
            padding:6px;
            display:flex;
            flex-direction:column;
            gap:4px;
            font-size:12px;
        }
        .thumb {
            width:100%;
            height:90px;
            border-radius:8px;
            overflow:hidden;
            background:#111827;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .thumb img {
            width:100%;
            height:100%;
            object-fit:cover;
        }
        .path {
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            color:#9ca3af;
        }
        .controls {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:6px;
        }
        .btn {
            padding:8px 14px;
            border-radius:999px;
            border:none;
            cursor:pointer;
            font-size:13px;
            font-weight:600;
        }
        .btn-primary { background:#3b82f6; color:#fff; }
        .btn-secondary { background:#1e293b; color:#e5e7eb; }
        input[type=file] {
            width:100%;
            padding:8px;
            border-radius:8px;
            border:1px solid #1e293b;
            background:#020617;
            color:#e5e7eb;
            font-size:13px;
        }
        label small { color:#9ca3af; font-size:11px; }
    </style>
</head>
<body>
<a href="admin.php?tab=commission" class="back">← Назад в админку</a>

<div class="wrap">
    <h1>Фото лота #<?= (int)$lot['id'] ?></h1>
    <div style="margin-bottom:8px; font-size:14px; color:#cbd5f5;">
        <?= htmlspecialchars($lot['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </div>

    <?php if (!empty($_SESSION['admin_msg'])): ?>
        <div class="msg">
            <?= htmlspecialchars($_SESSION['admin_msg'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php unset($_SESSION['admin_msg']); ?>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php if (empty($images)): ?>
            <div style="font-size:13px; color:#9ca3af; margin:10px 0 15px;">
                Фото ещё не загружены.
            </div>
        <?php else: ?>
            <div style="font-size:13px; color:#9ca3af;">Текущие фото:</div>
            <div class="grid">
                <?php foreach ($images as $idx => $img): ?>
                    <div class="item">
                        <div class="thumb">
                            <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        </div>
                        <div class="path" title="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="controls">
                            <label style="font-size:11px;cursor:pointer;color:#f97373;">
                                <input type="checkbox" name="delete_images[]" value="<?= $idx ?>" style="margin-right:4px;">
                                удалить
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <hr style="border-color:#1e293b; margin:12px 0;">

        <label style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">
            Добавить новые фото<br>
            <small>JPG/PNG, общий размер не более 5 МБ</small>
        </label>
        <input type="file" name="new_images[]" multiple accept=".jpg,.jpeg,.png">

        <div style="margin-top:16px; display:flex; gap:10px; justify-content:flex-end;">
            <a href="admin.php?tab=commission" class="btn btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">
                Отмена
            </a>
            <button type="submit" class="btn btn-primary">
                Сохранить фото
            </button>
        </div>
    </form>
</div>
</body>
</html>
