<?php
session_start();
date_default_timezone_set('Europe/Moscow');

$file = 'lots.json';
$lots = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$id = $_GET['id'] ?? null;

// Если лот не найден, возвращаем в реестр
if (!$id || !isset($lots[$id])) {
    header("Location: reestr.php");
    exit;
}

$lot = $lots[$id];

// ЛОГИКА УДАЛЕНИЯ КОНКРЕТНОГО ФОТО
if (isset($_GET['delete_photo'])) {
    $p_idx = $_GET['delete_photo'];
    if (isset($lots[$id]['files'][$p_idx])) {
        unset($lots[$id]['files'][$p_idx]);
        $lots[$id]['files'] = array_values($lots[$id]['files']); // Пересобираем индексы
        file_put_contents($file, json_encode($lots, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header("Location: edit_lot.php?id=" . $id);
        exit;
    }
}

// ЛОГИКА ОБНОВЛЕНИЯ ДАННЫХ ЛОТА
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lot'])) {
    $lots[$id]['name'] = htmlspecialchars($_POST['name']);
    $lots[$id]['type'] = $_POST['type'];
    $lots[$id]['start_price'] = floatval($_POST['start_price']);
    $lots[$id]['step'] = !empty($_POST['step']) ? floatval($_POST['step']) : 0;
    $lots[$id]['desc'] = htmlspecialchars($_POST['desc']);

    // Загрузка новых файлов, если они выбраны
    if (!empty($_FILES['media']['name'][0])) {
        if (!is_dir('uploads/lots')) mkdir('uploads/lots', 0777, true);
        foreach ($_FILES['media']['name'] as $k => $name) {
            if ($_FILES['media']['error'][$k] === 0) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $fname = "lot_{$id}_" . uniqid() . "." . $ext;
                if (move_uploaded_file($_FILES['media']['tmp_name'][$k], "uploads/lots/" . $fname)) {
                    $lots[$id]['files'][] = ["name" => $fname, "type" => $ext];
                }
            }
        }
    }

    file_put_contents($file, json_encode($lots, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header("Location: reestr.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Правка лота #<?=$id?></title>
    <style>
        body { background: #020617; color: white; font-family: sans-serif; padding: 20px; display: flex; justify-content: center; }
        .card { background: #0f172a; padding: 25px; border-radius: 12px; border: 1px solid #1e293b; width: 100%; max-width: 500px; }
        label { display: block; font-size: 12px; color: #94a3b8; margin-top: 15px; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        input, select, textarea { width: 100%; padding: 12px; background: #1e293b; border: 1px solid #334155; color: white; border-radius: 8px; box-sizing: border-box; font-size: 14px; }
        input:focus { border-color: #38bdf8; outline: none; }
        
        /* Галерея текущих фото */
        .photo-grid { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; background: #020617; padding: 10px; border-radius: 8px; border: 1px dashed #334155; }
        .photo-item { position: relative; width: 80px; height: 80px; }
        .photo-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; }
        .del-btn { position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; text-decoration: none; border-radius: 50%; width: 20px; height: 20px; text-align: center; font-size: 14px; line-height: 18px; font-weight: bold; border: 2px solid #0f172a; }

        .btn-save { background: #eab308; color: #020617; border: none; padding: 15px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 25px; font-size: 16px; transition: 0.2s; }
        .btn-save:hover { background: #facc15; transform: translateY(-1px); }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #64748b; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>

<div class="card">
    <h2 style="color:#eab308; margin: 0 0 5px 0;">Редактирование лота</h2>
    <span style="color: #64748b; font-size: 14px;">ID объекта: #<?=$id?></span>

    <form method="POST" enctype="multipart/form-data">
        <label>Название объекта</label>
        <input type="text" name="name" value="<?= htmlspecialchars($lot['name']) ?>" required>
        
        <label>Тип размещения</label>
        <select name="type">
            <option value="auction" <?=$lot['type']=='auction'?'selected':''?>>Торги (Аукцион)</option>
            <option value="commission" <?=$lot['type']=='commission'?'selected':''?>>Комиссионная продажа</option>
        </select>

        <div style="display: flex; gap: 15px;">
            <div style="flex: 1;">
                <label>Текущая цена (₽)</label>
                <input type="number" name="start_price" value="<?=$lot['start_price']?>" required>
            </div>
            <div style="flex: 1;">
                <label>Шаг ставки (₽)</label>
                <input type="number" name="step" value="<?=$lot['step']?>">
            </div>
        </div>

        <label>Описание и характеристики</label>
        <textarea name="desc" rows="5"><?= htmlspecialchars($lot['desc']) ?></textarea>

        <label>Управление изображениями</label>
        <div class="photo-grid">
            <?php if (!empty($lot['files'])): ?>
                <?php foreach ($lot['files'] as $f_idx => $file): ?>
                    <div class="photo-item">
                        <img src="uploads/lots/<?=$file['name']?>">
                        <a href="?id=<?=$id?>&delete_photo=<?=$f_idx?>" class="del-btn" onclick="return confirm('Удалить это фото?')">×</a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="font-size: 12px; color: #64748b; padding: 10px;">Нет загруженных фото</span>
            <?php endif; ?>
        </div>

        <label>Добавить новые фото</label>
        <input type="file" name="media[]" multiple style="background: none; border: none; padding: 0;">

        <button type="submit" name="update_lot" class="btn-save">СОХРАНИТЬ ИЗМЕНЕНИЯ</button>
        
        <a href="reestr.php" class="back-link">← Отмена и выход</a>
    </form>
</div>

</body>
</html>