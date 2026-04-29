<?php
require_once __DIR__ . '/admin_only.php';
/* admin_user_edit.php
 *
 * Полный редактор данных пользователя для админа.
 * Доступ: только пользователи с user_type='admin' или role='admin'.
 *
 * Поддерживает редактирование:
 *  - логин (username) и сброс пароля (через bcrypt)
 *  - status (pending/active/blocked) — модерация
 *  - user_type (роль), role (legacy), entity_type
 *  - balance, bid_pack_remaining, credit_bids_remaining
 *  - email, phone, full_name, fullname (legacy), date_of_birth,
 *    registration_address, company, inn, kpp
 *  - telegram_id, telegram_username
 *  - три документа (file1/file2/file3) и аватар (profile_photo) —
 *    upload новых + чекбокс «удалить»
 *
 * Создаёт недостающие колонки в users (date_of_birth, registration_address,
 * profile_photo) автоматически — миграции идемпотентны.
 */
ob_start();
@session_start();
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

/* --- Авторизация ------------------------------------------------------
 * Используем ту же модель доступа, что и admin.php — только проверка
 * залогиненности. Полноценный role-check можно будет добавить отдельной
 * правкой по всему /admin.php сразу. */

/* --- Миграции (идемпотентные) ----------------------------------------- */
$migrations = [
    "ALTER TABLE users ADD COLUMN date_of_birth DATE NULL",
    "ALTER TABLE users ADD COLUMN registration_address VARCHAR(500) NULL",
    "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(500) NULL",
];
foreach ($migrations as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) { /* already exists */ }
}

/* --- Helpers ----------------------------------------------------------- */
function flash_redirect($id, $msg) {
    $_SESSION['admin_msg'] = $msg;
    header('Location: admin_user_edit.php?id=' . (int)$id);
    exit;
}

/* Безопасная загрузка файла. Принимает $_FILES[$key], сохраняет в
 * uploads/users/{user_id}/, возвращает относительный путь — или null,
 * если файл не загружен / невалидный. */
function save_uploaded_file($key, $user_id) {
    if (empty($_FILES[$key]['name']) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $dir = __DIR__ . '/uploads/users/' . (int)$user_id;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $orig = $_FILES[$key]['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    /* Простой allowlist расширений — никакого .php / .phtml. */
    $allowed = ['jpg','jpeg','png','gif','webp','heic','pdf','doc','docx','xls','xlsx','txt'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }
    $name = $key . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs  = $dir . '/' . $name;
    if (!move_uploaded_file($_FILES[$key]['tmp_name'], $abs)) {
        return null;
    }
    return 'uploads/users/' . (int)$user_id . '/' . $name;
}

function delete_file_safe($rel) {
    if (!$rel) return;
    $abs = __DIR__ . '/' . ltrim($rel, '/');
    /* Защита от path traversal — удаляем только внутри uploads/. */
    $real = realpath($abs);
    $base = realpath(__DIR__ . '/uploads');
    if ($real && $base && strpos($real, $base) === 0 && is_file($real)) {
        @unlink($real);
    }
}

/* --- Загрузка существующего юзера ------------------------------------- */
$user_id = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
if ($user_id <= 0) {
    die('Не указан ID пользователя.');
}

$st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([$user_id]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) { die('Пользователь не найден.'); }

/* --- Обработка POST --------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    /* Простые скалярные поля — собираем в массив key => raw value. */
    $fields = [
        'username'              => trim($_POST['username'] ?? ''),
        'email'                 => trim($_POST['email'] ?? ''),
        'phone'                 => trim($_POST['phone'] ?? ''),
        'full_name'             => trim($_POST['full_name'] ?? ''),
        'fullname'              => trim($_POST['fullname'] ?? ''),
        'date_of_birth'         => trim($_POST['date_of_birth'] ?? '') ?: null,
        'registration_address'  => trim($_POST['registration_address'] ?? ''),
        'company'               => trim($_POST['company'] ?? ''),
        'inn'                   => trim($_POST['inn'] ?? ''),
        'kpp'                   => trim($_POST['kpp'] ?? ''),
        'ogrn'                  => trim($_POST['ogrn'] ?? ''),
        'snils'                 => trim($_POST['snils'] ?? ''),
        'position'              => trim($_POST['position'] ?? ''),
        'status'                => $_POST['status'] ?? 'pending',
        'user_type'             => $_POST['user_type'] ?? 'уважаемый',
        'role'                  => $_POST['role'] ?? 'user',
        'entity_type'           => $_POST['entity_type'] ?? 'individual',
        'balance'               => (float)($_POST['balance'] ?? 0),
        'bid_pack_remaining'    => (int)($_POST['bid_pack_remaining'] ?? 0),
        'credit_bids_remaining' => (int)($_POST['credit_bids_remaining'] ?? 0),
        'telegram_id'           => trim($_POST['telegram_id'] ?? '') ?: null,
        'telegram_username'     => trim($_POST['telegram_username'] ?? '') ?: null,
    ];

    /* Уникальность username — защита от случайного дубля. */
    if ($fields['username'] !== $user['username']) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $chk->execute([$fields['username'], $user_id]);
        if ($chk->fetch()) {
            flash_redirect($user_id, '❌ Логин «' . htmlspecialchars($fields['username']) . '» уже занят');
        }
    }

    /* Сборка UPDATE динамически — иначе при добавлении поля надо править
       три места. */
    $set = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $set[] = "`$k` = ?";
        $params[] = $v;
    }

    /* Сброс пароля — отдельным шагом, только если новое поле непустое. */
    $new_pwd = (string)($_POST['new_password'] ?? '');
    if ($new_pwd !== '') {
        if (strlen($new_pwd) < 4) {
            flash_redirect($user_id, '❌ Пароль слишком короткий (мин. 4 символа)');
        }
        $set[] = "`password` = ?";
        $params[] = password_hash($new_pwd, PASSWORD_BCRYPT);
    }

    /* Файлы: чекбокс «удалить» — затирает соответствующее поле и удаляет
       файл с диска. Загрузка нового — перетирает поле и удаляет старый. */
    $file_fields = ['file1', 'file2', 'file3', 'profile_photo'];
    foreach ($file_fields as $ff) {
        $delete = !empty($_POST['delete_' . $ff]);
        $new_path = save_uploaded_file($ff, $user_id);

        if ($new_path !== null) {
            /* Старый файл — на удаление. */
            delete_file_safe($user[$ff] ?? null);
            $set[] = "`$ff` = ?";
            $params[] = $new_path;
        } elseif ($delete) {
            delete_file_safe($user[$ff] ?? null);
            $set[] = "`$ff` = NULL";
        }
    }

    $params[] = $user_id;
    $sql = "UPDATE users SET " . implode(', ', $set) . " WHERE id = ?";
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (Exception $e) {
        flash_redirect($user_id, '❌ Ошибка БД: ' . $e->getMessage());
    }

    flash_redirect($user_id, '✅ Данные пользователя обновлены');
}

/* --- Обработка POST: одобрение / отклонение / удаление --------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve','block','unblock','delete_user'], true)) {
    $a = $_POST['action'];
    if ($a === 'approve') {
        $pdo->prepare("UPDATE users SET status='active' WHERE id = ?")->execute([$user_id]);
        flash_redirect($user_id, '✅ Пользователь одобрен');
    } elseif ($a === 'block') {
        $pdo->prepare("UPDATE users SET status='blocked' WHERE id = ?")->execute([$user_id]);
        flash_redirect($user_id, '⛔ Пользователь заблокирован');
    } elseif ($a === 'unblock') {
        $pdo->prepare("UPDATE users SET status='active' WHERE id = ?")->execute([$user_id]);
        flash_redirect($user_id, '✅ Блокировка снята');
    } elseif ($a === 'delete_user') {
        foreach (['file1','file2','file3','profile_photo'] as $ff) {
            delete_file_safe($user[$ff] ?? null);
        }
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        $_SESSION['admin_msg'] = '🗑️ Пользователь #' . $user_id . ' удалён';
        header('Location: admin.php?tab=users');
        exit;
    }
}

/* После всех POST'ов — заново тянем юзера, чтобы форма показывала свежее. */
$st->execute([$user_id]);
$user = $st->fetch(PDO::FETCH_ASSOC);

$flash = $_SESSION['admin_msg'] ?? '';
unset($_SESSION['admin_msg']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Редактирование #<?= (int)$user['id'] ?> — <?= htmlspecialchars($user['username'] ?? '') ?></title>
<style>
*{box-sizing:border-box}
body{font-family:'Inter',system-ui,Arial,sans-serif;background:#0f172a;color:#f1f5f9;margin:0;padding:24px;min-height:100vh}
.wrap{max-width:980px;margin:0 auto}
.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.top h1{margin:0;font-size:22px}
.top .uid{color:#94a3b8;font-weight:400;font-size:14px;margin-left:8px}
.back{color:#7dd3fc;text-decoration:none;font-size:14px}
.back:hover{text-decoration:underline}
.card{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:22px 24px;margin-bottom:18px}
.card h2{margin:0 0 16px;font-size:16px;color:#7dd3fc;letter-spacing:.5px;text-transform:uppercase;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:12px;color:#94a3b8;font-weight:600;letter-spacing:.3px;text-transform:uppercase}
.field input,.field select,.field textarea{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 12px;color:#f1f5f9;font-size:14px;font-family:inherit;width:100%}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:#0088cc;box-shadow:0 0 0 3px rgba(0,136,204,.18)}
.field textarea{resize:vertical;min-height:60px}
.flash{background:#0c4a6e;border:1px solid #0284c7;color:#e0f2fe;padding:12px 16px;border-radius:10px;margin-bottom:18px}
.flash.err{background:#7f1d1d;border-color:#dc2626;color:#fee2e2}
.btn{background:#0088cc;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}
.btn:hover{background:#0077b3}
.btn.danger{background:#dc2626}.btn.danger:hover{background:#b91c1c}
.btn.success{background:#22c55e}.btn.success:hover{background:#16a34a}
.btn.warn{background:#f59e0b}.btn.warn:hover{background:#d97706}
.btn.ghost{background:#334155}.btn.ghost:hover{background:#475569}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.file-row{display:flex;align-items:center;gap:14px;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:10px;flex-wrap:wrap}
.file-row .meta{flex:1;min-width:200px;font-size:13px}
.file-row .meta a{color:#7dd3fc;text-decoration:none;font-weight:600}
.file-row .meta a:hover{text-decoration:underline}
.file-row .none{color:#64748b;font-style:italic}
.file-row label.del{display:flex;align-items:center;gap:6px;font-size:13px;color:#fca5a5;cursor:pointer}
.status-badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.5px;text-transform:uppercase}
.status-pending{background:rgba(251,191,36,.18);color:#fbbf24;border:1px solid rgba(251,191,36,.4)}
.status-active{background:rgba(34,197,94,.18);color:#4ade80;border:1px solid rgba(34,197,94,.4)}
.status-blocked{background:rgba(239,68,68,.18);color:#f87171;border:1px solid rgba(239,68,68,.4)}
.preview-thumb{width:64px;height:64px;border-radius:10px;object-fit:cover;border:1px solid #334155}
.danger-zone{border:1px solid #7f1d1d;background:#1f0f0f}
hr{border:none;border-top:1px solid #334155;margin:18px 0}
@media(max-width:560px){body{padding:12px}.card{padding:16px}}
</style>
</head>
<body>
<div class="wrap">

    <div class="top">
        <h1>
            ✏️ <?= htmlspecialchars($user['username'] ?? '') ?>
            <span class="uid">#<?= (int)$user['id'] ?></span>
            <span class="status-badge status-<?= htmlspecialchars($user['status'] ?? 'pending') ?>" style="margin-left:8px;vertical-align:middle;">
                <?php $st = $user['status'] ?? 'pending';
                echo $st === 'pending' ? 'Ожидает' : ($st === 'active' ? 'Активен' : 'Заблокирован'); ?>
            </span>
        </h1>
        <a href="admin.php?tab=users" class="back">← Назад к списку</a>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= str_starts_with($flash, '❌') || str_starts_with($flash, '⛔') ? 'err' : '' ?>">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Быстрые действия по статусу -->
    <div class="card">
        <h2>Модерация</h2>
        <div class="actions">
            <?php if (($user['status'] ?? '') !== 'active'): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <button class="btn success" type="submit">✅ Одобрить (Approve)</button>
                </form>
            <?php endif; ?>
            <?php if (($user['status'] ?? '') !== 'blocked'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Заблокировать пользователя?');">
                    <input type="hidden" name="action" value="block">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <button class="btn warn" type="submit">⛔ Заблокировать</button>
                </form>
            <?php else: ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="unblock">
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <button class="btn success" type="submit">🔓 Снять блокировку</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

    <!-- Учётные данные -->
    <div class="card">
        <h2>Учётные данные</h2>
        <div class="grid">
            <div class="field">
                <label>Логин (username)</label>
                <input type="text" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label>Новый пароль (оставить пустым — не менять)</label>
                <input type="text" name="new_password" placeholder="••••••••" autocomplete="new-password">
            </div>
            <div class="field">
                <label>Статус</label>
                <select name="status">
                    <?php foreach (['pending'=>'Ожидает','active'=>'Активен','blocked'=>'Заблокирован'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ($user['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Тип пользователя (user_type)</label>
                <select name="user_type">
                    <?php foreach (['уважаемый','responsible','organizer','admin'] as $k): ?>
                        <option value="<?= $k ?>" <?= ($user['user_type'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Роль (role, legacy)</label>
                <input type="text" name="role" value="<?= htmlspecialchars($user['role'] ?? 'user') ?>">
            </div>
            <div class="field">
                <label>Тип субъекта (entity_type)</label>
                <select name="entity_type">
                    <?php foreach (['individual'=>'Физ. лицо','legal'=>'Юр. лицо'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ($user['entity_type'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Личные данные -->
    <div class="card">
        <h2>Личные данные</h2>
        <div class="grid">
            <div class="field">
                <label>Полное имя (full_name)</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label>ФИО (fullname, legacy)</label>
                <input type="text" name="fullname" value="<?= htmlspecialchars($user['fullname'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Телефон</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Дата рождения</label>
                <input type="date" name="date_of_birth" value="<?= htmlspecialchars($user['date_of_birth'] ?? '') ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label>Адрес регистрации</label>
                <textarea name="registration_address" rows="2"><?= htmlspecialchars($user['registration_address'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Юр. данные -->
    <div class="card">
        <h2>Реквизиты (для юр. лиц)</h2>
        <div class="grid">
            <div class="field">
                <label>Компания</label>
                <input type="text" name="company" value="<?= htmlspecialchars($user['company'] ?? '') ?>">
            </div>
            <div class="field">
                <label>ИНН</label>
                <input type="text" name="inn" value="<?= htmlspecialchars($user['inn'] ?? '') ?>">
            </div>
            <div class="field">
                <label>КПП</label>
                <input type="text" name="kpp" value="<?= htmlspecialchars($user['kpp'] ?? '') ?>">
            </div>
            <div class="field">
                <label>ОГРН / ОГРНИП</label>
                <input type="text" name="ogrn" value="<?= htmlspecialchars($user['ogrn'] ?? '') ?>">
            </div>
            <div class="field">
                <label>СНИЛС</label>
                <input type="text" name="snils" value="<?= htmlspecialchars($user['snils'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Должность</label>
                <input type="text" name="position" value="<?= htmlspecialchars($user['position'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Финансы -->
    <div class="card">
        <h2>Финансы и лимиты</h2>
        <div class="grid">
            <div class="field">
                <label>Баланс (₽)</label>
                <input type="number" name="balance" step="0.01" value="<?= htmlspecialchars($user['balance'] ?? '0') ?>">
            </div>
            <div class="field">
                <label>Пакет ставок (bid_pack_remaining)</label>
                <input type="number" name="bid_pack_remaining" value="<?= (int)($user['bid_pack_remaining'] ?? 0) ?>">
            </div>
            <div class="field">
                <label>Кредитные ставки (credit_bids_remaining)</label>
                <input type="number" name="credit_bids_remaining" value="<?= (int)($user['credit_bids_remaining'] ?? 0) ?>">
            </div>
        </div>
    </div>

    <!-- Telegram -->
    <div class="card">
        <h2>Telegram</h2>
        <div class="grid">
            <div class="field">
                <label>Telegram ID</label>
                <input type="text" name="telegram_id" value="<?= htmlspecialchars($user['telegram_id'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Telegram username</label>
                <input type="text" name="telegram_username" value="<?= htmlspecialchars($user['telegram_username'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Документы и фото -->
    <div class="card">
        <h2>Документы и фото</h2>

        <?php
        $file_specs = [
            'profile_photo' => 'Фото профиля',
            'file1'         => 'Документ #1',
            'file2'         => 'Документ #2',
            'file3'         => 'Документ #3',
        ];
        foreach ($file_specs as $key => $label):
            $cur  = $user[$key] ?? '';
            $isImg = $cur && preg_match('/\.(jpe?g|png|gif|webp|heic)$/i', $cur);
        ?>
            <div class="file-row" style="margin-bottom:10px">
                <div style="min-width:80px"><strong><?= $label ?></strong></div>
                <div class="meta">
                    <?php if ($cur): ?>
                        <?php if ($isImg): ?>
                            <img class="preview-thumb" src="<?= htmlspecialchars($cur) ?>" alt="">
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($cur) ?>" target="_blank">📎 <?= htmlspecialchars(basename($cur)) ?></a>
                    <?php else: ?>
                        <span class="none">— файл не загружен</span>
                    <?php endif; ?>
                </div>
                <div>
                    <input type="file" name="<?= $key ?>">
                </div>
                <?php if ($cur): ?>
                    <label class="del">
                        <input type="checkbox" name="delete_<?= $key ?>" value="1"> удалить
                    </label>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p style="font-size:12px;color:#94a3b8;margin-top:10px">
            Разрешены: jpg/jpeg/png/gif/webp/heic, pdf, doc/docx, xls/xlsx, txt.
            Загрузка нового файла перезаписывает предыдущий.
        </p>
    </div>

    <div class="card" style="text-align:right">
        <button class="btn ghost" type="button" onclick="location.href='admin.php?tab=users'">Отмена</button>
        <button class="btn" type="submit">💾 Сохранить все изменения</button>
    </div>
    </form>

    <!-- Зона удаления -->
    <div class="card danger-zone">
        <h2 style="color:#fca5a5">Опасная зона</h2>
        <p style="color:#fca5a5;font-size:13px">Удаление необратимо: пользователь, его файлы и связанные сессии будут стёрты.</p>
        <form method="POST" onsubmit="return confirm('Точно удалить пользователя #<?= (int)$user['id'] ?> «<?= htmlspecialchars($user['username'] ?? '') ?>»? Это нельзя отменить.');">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <button class="btn danger" type="submit">🗑️ Удалить пользователя</button>
        </form>
    </div>

</div>
</body>
</html>
