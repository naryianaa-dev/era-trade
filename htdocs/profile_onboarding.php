<?php
/* profile_onboarding.php
 *
 * Минималистичный кабинет «онбординг» для юзеров со status='pending'.
 * Пускает в систему, но даёт только:
 *   - анкету с обязательными личными данными (ФИО, ДР, адрес и т.д.)
 *   - загрузку 3-х документов и фото профиля
 *
 * Все остальные функции (аукционы, ставки, лоты) недоступны до тех пор,
 * пока админ не одобрит юзера и не переведёт status='active' →
 * после этого юзер автоматически попадает в полный profile.php.
 *
 * Если юзер сюда зашёл, а его status уже не 'pending' — редирект.
 */
ob_start();
@session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

if (!isset($_SESSION['lang'])) {
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'ru';
    $_SESSION['lang'] = substr($accept, 0, 2) === 'ru' ? 'ru' : 'en';
}
if (isset($_GET['lang'])) $_SESSION['lang'] = $_GET['lang'] === 'en' ? 'en' : 'ru';
$lang = $_SESSION['lang'];

/* Идемпотентные миграции — те же, что и в admin_user_edit.php. */
foreach ([
    "ALTER TABLE users ADD COLUMN date_of_birth DATE NULL",
    "ALTER TABLE users ADD COLUMN registration_address VARCHAR(500) NULL",
    "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(500) NULL",
] as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

$uid = (int)$_SESSION['user_id'];
$st  = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([$uid]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) { session_destroy(); header('Location: index.php'); exit; }

/* Если уже одобрен — отправляем в полный кабинет. Если заблокирован —
   на страницу с сообщением. */
$status = $user['status'] ?? 'pending';
if ($status === 'active') { header('Location: profile.php'); exit; }
if ($status === 'blocked') {
    header('Location: tg_pending.php?r=blocked&u=' . urlencode($user['username'] ?? ''));
    exit;
}

/* Helpers — те же, что в admin_user_edit.php (немного дублируем, но
   зато profile_onboarding.php самодостаточен). */
function save_uploaded_file_ob($key, $user_id) {
    if (empty($_FILES[$key]['name']) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) return null;
    $dir = __DIR__ . '/uploads/users/' . (int)$user_id;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ext  = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp','heic','pdf','doc','docx','xls','xlsx','txt'];
    if (!in_array($ext, $allowed, true)) return null;
    if ($_FILES[$key]['size'] > 10 * 1024 * 1024) return null; /* 10 MB */
    $name = $key . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs  = $dir . '/' . $name;
    if (!move_uploaded_file($_FILES[$key]['tmp_name'], $abs)) return null;
    return 'uploads/users/' . (int)$user_id . '/' . $name;
}
function delete_file_safe_ob($rel) {
    if (!$rel) return;
    $abs  = __DIR__ . '/' . ltrim($rel, '/');
    $real = realpath($abs);
    $base = realpath(__DIR__ . '/uploads');
    if ($real && $base && strpos($real, $base) === 0 && is_file($real)) @unlink($real);
}

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    $set = [];
    $params = [];
    $simple = [
        'full_name', 'fullname', 'email', 'phone',
        'date_of_birth', 'registration_address',
        'company', 'inn', 'kpp', 'ogrn', 'snils', 'position', 'entity_type',
    ];
    foreach ($simple as $k) {
        $v = trim($_POST[$k] ?? '');
        if ($k === 'date_of_birth' && $v === '') $v = null;
        $set[] = "`$k` = ?";
        $params[] = $v;
    }

    /* Файлы: 3 документа + фото профиля. */
    foreach (['file1','file2','file3','profile_photo'] as $ff) {
        $new_path = save_uploaded_file_ob($ff, $uid);
        if ($new_path !== null) {
            delete_file_safe_ob($user[$ff] ?? null);
            $set[]    = "`$ff` = ?";
            $params[] = $new_path;
        }
    }

    $params[] = $uid;
    try {
        $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?")
            ->execute($params);
        $flash = $lang === 'en'
            ? 'Saved. Your profile is on review.'
            : 'Сохранено. Профиль отправлен на проверку.';
        /* перетягиваем юзера, чтобы форма показала свежие значения. */
        $st->execute([$uid]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $flash = ($lang === 'en' ? 'DB error: ' : 'Ошибка БД: ') . $e->getMessage();
    }
}

$T = $lang === 'en' ? [
    'banner_title' => 'Your account is awaiting approval',
    'banner_text'  => 'Fill out the form below and upload your documents. An admin will review your data and activate your account — usually within a few minutes to one business day. Until then, the auctions section is not available.',
    'personal'     => 'Personal information',
    'docs'         => 'Documents and photo',
    'fullname'     => 'Full name (passport)',
    'email'        => 'Email',
    'phone'        => 'Phone',
    'dob'          => 'Date of birth',
    'addr'         => 'Registration address',
    'entity'       => 'Subject',
    'individual'   => 'Individual',
    'legal'        => 'Legal entity',
    'company'      => 'Company',
    'inn'          => 'INN (Tax ID)',
    'kpp'          => 'KPP',
    'photo'        => 'Profile photo',
    'doc1'         => 'Document #1 (passport scan, front)',
    'doc2'         => 'Document #2 (passport scan, registration)',
    'doc3'         => 'Document #3 (other)',
    'save'         => 'Save and submit for review',
    'logout'       => 'Sign out',
    'home'         => 'Back to homepage',
    'commission'   => 'Commission sales (open to everyone)',
    'current'      => 'Current file:',
    'replace'      => 'Replace:',
    'tip'          => 'jpg, png, pdf, doc, xls — up to 10 MB.',
] : [
    'banner_title' => 'Ваш аккаунт ожидает подтверждения',
    'banner_text'  => 'Заполните форму ниже и загрузите документы. Администратор проверит данные и активирует аккаунт — обычно от нескольких минут до одного рабочего дня. До этого момента раздел аукционов недоступен.',
    'personal'     => 'Личные данные',
    'docs'         => 'Документы и фото',
    'fullname'     => 'ФИО (как в паспорте)',
    'email'        => 'Email',
    'phone'        => 'Телефон',
    'dob'          => 'Дата рождения',
    'addr'         => 'Адрес регистрации',
    'entity'       => 'Тип субъекта',
    'individual'   => 'Физ. лицо',
    'legal'        => 'Юр. лицо',
    'company'      => 'Компания',
    'inn'          => 'ИНН',
    'kpp'          => 'КПП',
    'photo'        => 'Фото профиля',
    'doc1'         => 'Документ №1 (скан паспорта, главный разворот)',
    'doc2'         => 'Документ №2 (скан паспорта, прописка)',
    'doc3'         => 'Документ №3 (иной документ)',
    'save'         => 'Сохранить и отправить на проверку',
    'logout'       => 'Выйти',
    'home'         => 'На главную',
    'commission'   => 'Комиссионные продажи (доступны всем)',
    'current'      => 'Текущий файл:',
    'replace'      => 'Заменить:',
    'tip'          => 'jpg, png, pdf, doc, xls — до 10 МБ.',
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= htmlspecialchars($T['banner_title']) ?> — ЭРА ЭТП</title>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0f172a">
<style>
*{box-sizing:border-box}
body{font-family:'Inter',system-ui,Arial,sans-serif;background:radial-gradient(circle at top right,#1e293b,#0f172a);color:#f1f5f9;margin:0;min-height:100vh;padding:24px;padding-top:max(24px,env(safe-area-inset-top));padding-bottom:max(24px,env(safe-area-inset-bottom))}
.wrap{max-width:780px;margin:0 auto}
.banner{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1e293b;border-radius:16px;padding:20px 22px;margin-bottom:20px;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.banner h1{margin:0 0 6px;font-size:20px;font-weight:800}
.banner p{margin:0;font-size:14px;line-height:1.5}
.flash{background:#0c4a6e;border:1px solid #0284c7;color:#e0f2fe;padding:12px 16px;border-radius:10px;margin-bottom:18px}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between;align-items:center;margin-bottom:18px}
.toolbar .who{font-size:13px;color:#94a3b8}
.toolbar .who b{color:#f1f5f9}
.linklike{color:#7dd3fc;text-decoration:none;font-size:13px;margin-left:14px}
.linklike:hover{text-decoration:underline}
.card{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:20px 22px;margin-bottom:18px}
.card h2{margin:0 0 16px;font-size:15px;color:#7dd3fc;letter-spacing:.5px;text-transform:uppercase;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:12px;color:#94a3b8;font-weight:600;letter-spacing:.3px;text-transform:uppercase}
.field input,.field select,.field textarea{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 12px;color:#f1f5f9;font-size:14px;font-family:inherit;width:100%}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:#0088cc;box-shadow:0 0 0 3px rgba(0,136,204,.18)}
.field textarea{resize:vertical;min-height:60px}
.file-row{display:flex;align-items:center;gap:12px;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:10px;margin-bottom:10px;flex-wrap:wrap}
.file-row .meta{flex:1;min-width:180px;font-size:13px}
.file-row .meta a{color:#7dd3fc;text-decoration:none;font-weight:600}
.file-row .meta .none{color:#64748b;font-style:italic}
.file-row .lbl{font-weight:700;font-size:13px;color:#f1f5f9}
.thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid #334155}
.btn{background:#0088cc;color:#fff;border:0;border-radius:10px;padding:12px 22px;font-size:14px;font-weight:800;cursor:pointer;font-family:inherit;letter-spacing:.3px}
.btn:hover{background:#0077b3}
.btn-save-row{text-align:right;margin-top:14px}
.tip{font-size:12px;color:#94a3b8;margin-top:8px}
.cta{display:inline-block;background:rgba(56,189,248,.12);color:#7dd3fc;border:1px solid rgba(56,189,248,.3);padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:700;font-size:13px;margin-top:10px}
.cta:hover{background:rgba(56,189,248,.2)}
</style>
</head>
<body>
<div class="wrap">

    <div class="toolbar">
        <div class="who">
            <?= $lang === 'en' ? 'Logged in as' : 'Вы вошли как' ?>
            <b>@<?= htmlspecialchars($user['username'] ?? '') ?></b>
        </div>
        <div>
            <a class="linklike" href="<?= $lang === 'en' ? '?lang=ru' : '?lang=en' ?>"><?= $lang === 'en' ? 'RU' : 'EN' ?></a>
            <a class="linklike" href="index.php"><?= htmlspecialchars($T['home']) ?></a>
            <a class="linklike" href="logout.php"><?= htmlspecialchars($T['logout']) ?></a>
        </div>
    </div>

    <div class="banner">
        <h1>⏳ <?= htmlspecialchars($T['banner_title']) ?></h1>
        <p><?= htmlspecialchars($T['banner_text']) ?></p>
        <a class="cta" href="torgi_list.php">📑 <?= htmlspecialchars($T['commission']) ?></a>
    </div>

    <?php if ($flash): ?>
        <div class="flash"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">

        <div class="card">
            <h2><?= htmlspecialchars($T['personal']) ?></h2>
            <div class="grid">
                <div class="field" style="grid-column:1/-1">
                    <label><?= htmlspecialchars($T['fullname']) ?></label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label><?= htmlspecialchars($T['email']) ?></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= htmlspecialchars($T['phone']) ?></label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= htmlspecialchars($T['dob']) ?></label>
                    <input type="date" name="date_of_birth" value="<?= htmlspecialchars($user['date_of_birth'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= htmlspecialchars($T['entity']) ?></label>
                    <select name="entity_type">
                        <option value="individual" <?= ($user['entity_type'] ?? '') === 'individual' ? 'selected' : '' ?>><?= htmlspecialchars($T['individual']) ?></option>
                        <option value="legal" <?= ($user['entity_type'] ?? '') === 'legal' ? 'selected' : '' ?>><?= htmlspecialchars($T['legal']) ?></option>
                    </select>
                </div>
                <div class="field" style="grid-column:1/-1">
                    <label><?= htmlspecialchars($T['addr']) ?></label>
                    <textarea name="registration_address" rows="2"><?= htmlspecialchars($user['registration_address'] ?? '') ?></textarea>
                </div>
                <input type="hidden" name="fullname" value="<?= htmlspecialchars($user['fullname'] ?? '') ?>">

                <div class="field">
                    <label><?= htmlspecialchars($T['company']) ?></label>
                    <input type="text" name="company" value="<?= htmlspecialchars($user['company'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= htmlspecialchars($T['inn']) ?></label>
                    <input type="text" name="inn" value="<?= htmlspecialchars($user['inn'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= htmlspecialchars($T['kpp']) ?></label>
                    <input type="text" name="kpp" value="<?= htmlspecialchars($user['kpp'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= $lang === 'en' ? 'OGRN / OGRNIP' : 'ОГРН / ОГРНИП' ?></label>
                    <input type="text" name="ogrn" value="<?= htmlspecialchars($user['ogrn'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= $lang === 'en' ? 'SNILS' : 'СНИЛС' ?></label>
                    <input type="text" name="snils" value="<?= htmlspecialchars($user['snils'] ?? '') ?>">
                </div>
                <div class="field">
                    <label><?= $lang === 'en' ? 'Position' : 'Должность' ?></label>
                    <input type="text" name="position" value="<?= htmlspecialchars($user['position'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <h2><?= htmlspecialchars($T['docs']) ?></h2>
            <?php
            $file_specs = [
                'profile_photo' => $T['photo'],
                'file1'         => $T['doc1'],
                'file2'         => $T['doc2'],
                'file3'         => $T['doc3'],
            ];
            foreach ($file_specs as $key => $label):
                $cur = $user[$key] ?? '';
                $isImg = $cur && preg_match('/\.(jpe?g|png|gif|webp|heic)$/i', $cur);
            ?>
                <div class="file-row">
                    <div class="lbl" style="min-width:120px"><?= htmlspecialchars($label) ?></div>
                    <div class="meta">
                        <?php if ($cur): ?>
                            <?= htmlspecialchars($T['current']) ?>
                            <?php if ($isImg): ?>
                                <img class="thumb" src="<?= htmlspecialchars($cur) ?>" alt="">
                            <?php endif; ?>
                            <a href="<?= htmlspecialchars($cur) ?>" target="_blank">📎 <?= htmlspecialchars(basename($cur)) ?></a>
                        <?php else: ?>
                            <span class="none"><?= $lang === 'en' ? '— no file uploaded —' : '— файл не загружен —' ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?= htmlspecialchars($T['replace']) ?>
                        <input type="file" name="<?= $key ?>">
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="tip"><?= htmlspecialchars($T['tip']) ?></p>
        </div>

        <div class="btn-save-row">
            <button class="btn" type="submit">💾 <?= htmlspecialchars($T['save']) ?></button>
        </div>
    </form>

    <!-- ЭЦП-сертификат. На время разработки — заглушка «В разработке».
         Когда боевая крипта подключится, поставить $ecp_disabled = false и UI вернётся. -->
    <?php $ecp_disabled = true; /* TODO[ECP]: переключить, когда подключим КриптоПро. */ ?>
    <?php if ($ecp_disabled): ?>
    <div class="card" style="margin-top:20px; text-align:center; padding:30px 20px; background:#f1f5f9;">
        <div style="font-size:48px; margin-bottom:8px;">🔐</div>
        <h2 style="margin:0 0 8px;">
            <?= $lang === 'en' ? 'ЭЦП certificate — in development' : 'Сертификат ЭЦП — в разработке' ?>
        </h2>
        <p style="color:#475569; max-width:480px; margin:0 auto 12px;">
            <?= $lang === 'en'
                ? 'Attaching ESIA / digital signature certificate to the profile is currently in development.'
                : 'Привязка сертификата ЭЦП / Госуслуг к личному кабинету находится в разработке.' ?>
        </p>
        <span style="display:inline-block; padding:5px 14px; background:#fbbf24; color:#0f172a; border-radius:12px; font-weight:600; font-size:12px;">
            <?= $lang === 'en' ? 'IN DEVELOPMENT' : 'В РАЗРАБОТКЕ' ?>
        </span>
    </div>
    <?php else: ?>
    <div class="card" style="margin-top:20px;">
        <h2>🔐 <?= $lang === 'en' ? 'Digital signature (ЭЦП) certificate' : 'Сертификат электронной цифровой подписи (ЭЦП)' ?></h2>
        <?php if (!empty($user['ecp_cert_serial'])): ?>
            <p style="color:#16a34a;">
                ✅ <?= $lang === 'en' ? 'Certificate already attached.' : 'Сертификат уже привязан.' ?>
            </p>
            <p style="font-size:13px; color:#475569; word-break:break-all;">
                <strong>Subject:</strong> <?= htmlspecialchars($user['ecp_subject'] ?? '') ?><br>
                <strong>Serial:</strong> <code><?= htmlspecialchars($user['ecp_cert_serial'] ?? '') ?></code><br>
                <strong>Привязан:</strong> <?= htmlspecialchars($user['ecp_attached_at'] ?? '') ?>
            </p>
            <button type="button" class="btn" style="background:#dc2626;" onclick="ecpDetachOnboard()">
                <?= $lang === 'en' ? 'Detach' : 'Открепить' ?>
            </button>
        <?php else: ?>
            <p style="font-size:14px; color:#475569;">
                <?= $lang === 'en'
                    ? 'Optionally attach your ЭЦП certificate file (.cer / .crt / .pem / .p7b) to use it for signing offers and bids.'
                    : 'По желанию прикрепите файл сертификата вашей ЭЦП (.cer / .crt / .pem / .p7b) — он будет использоваться для подписи офферов и ставок.' ?>
            </p>
        <?php endif; ?>

        <form id="ecpUploadFormOnb" enctype="multipart/form-data" style="margin-top:14px;">
            <input type="hidden" name="action" value="attach">
            <div class="field">
                <label><?= $lang === 'en' ? 'Certificate file' : 'Файл сертификата' ?></label>
                <input type="file" name="cert_file" accept=".cer,.crt,.pem,.p7b,.p7c,.der">
            </div>
            <div class="field">
                <label><?= $lang === 'en' ? 'Or paste PEM/Base64' : 'Или вставьте PEM/Base64' ?></label>
                <textarea name="cert_pem" rows="4" style="width:100%; font-family:monospace; font-size:12px;"></textarea>
            </div>
            <button type="submit" class="btn" style="background:#0ea5e9;">
                <?= $lang === 'en' ? 'Save certificate' : 'Сохранить сертификат' ?>
            </button>
            <span id="ecpUploadOnbMsg" style="margin-left:12px; font-size:13px;"></span>
        </form>
    </div>
    <?php endif; /* $ecp_disabled */ ?>
    <script>
    (function(){
        const f = document.getElementById('ecpUploadFormOnb');
        if (!f) return;
        f.addEventListener('submit', function(e) {
            e.preventDefault();
            const msg = document.getElementById('ecpUploadOnbMsg');
            const fd = new FormData(f);
            if (msg) { msg.textContent = 'Загружаю...'; msg.style.color = '#475569'; }
            fetch('ecp_upload_cert.php', { method:'POST', body: fd, credentials:'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        let t = 'Сохранено.';
                        if (d.applied && Object.keys(d.applied).length) {
                            t += ' Из сертификата заполнены поля: ' + Object.keys(d.applied).join(', ') + '.';
                        }
                        t += ' Перезагрузка...';
                        if (msg) { msg.textContent = t; msg.style.color = '#16a34a'; }
                        setTimeout(() => location.reload(), 1500);
                    } else if (msg) {
                        msg.textContent = 'Ошибка: ' + (d.error || ''); msg.style.color = '#dc2626';
                    }
                })
                .catch(err => { if (msg) { msg.textContent = 'Ошибка сети: ' + err.message; msg.style.color = '#dc2626'; }});
        });
    })();
    function ecpDetachOnboard() {
        if (!confirm('Открепить сертификат ЭЦП?')) return;
        const fd = new FormData();
        fd.append('action', 'detach');
        fetch('ecp_upload_cert.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(r => r.json())
            .then(d => { if (d.success) location.reload(); else alert('Ошибка: ' + (d.error || '')); })
            .catch(err => alert('Ошибка сети: ' + err.message));
    }
    </script>

</div>
</body>
</html>
