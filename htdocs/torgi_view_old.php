<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

/**
 * 1. Обработчик загрузки квитанции оплаты
 * action = submit_receipt
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_receipt') {
    if (empty($_SESSION['user_id'])) {
        header('Location: torgi_view.php?id='.(int)($_GET['id'] ?? 0));
        exit;
    }

    $user_id  = (int)$_SESSION['user_id'];
    $lot_id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $tariff   = trim($_POST['tariff'] ?? '');
    $amount   = (float)($_POST['amount'] ?? 0);
    $comment  = trim($_POST['comment'] ?? '');
    $file_path = '';

    if ($lot_id <= 0 || $tariff === '' || $amount <= 0) {
        $_SESSION['lot_msg'] = 'Ошибка: не выбраны лот или тариф';
        header('Location: torgi_view.php?id='.$lot_id);
        exit;
    }

    if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/receipts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if ($_FILES['receipt_file']['size'] > 5 * 1024 * 1024) {
            $_SESSION['lot_msg'] = 'Файл должен быть не более 5 МБ';
            header('Location: torgi_view.php?id='.$lot_id);
            exit;
        }

        $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','pdf'];
        if (!in_array($ext, $allowed, true)) {
            $_SESSION['lot_msg'] = 'Разрешены файлы JPG, PNG, PDF';
            header('Location: torgi_view.php?id='.$lot_id);
            exit;
        }

        $filename = 'receipt_'.$user_id.'_'.time().'.'.$ext;
        $target   = $upload_dir.$filename;
        if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $target)) {
            $file_path = $target;
        }
    }

    if (!$file_path) {
        $_SESSION['lot_msg'] = 'Не удалось загрузить файл';
        header('Location: torgi_view.php?id='.$lot_id);
        exit;
    }


    // Таблица оплат
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_receipts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        lot_id INT UNSIGNED DEFAULT NULL,
        amount DECIMAL(15,2) NOT NULL,
        tariff VARCHAR(100) NOT NULL,
        comment TEXT,
        file_path VARCHAR(500) NOT NULL,
        status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id), INDEX (lot_id), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $stmt = $pdo->prepare("INSERT INTO payment_receipts
            (user_id, lot_id, amount, tariff, comment, file_path, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$user_id, $lot_id, $amount, $tariff, $comment, $file_path]);
        $_SESSION['lot_msg'] = 'Квитанция загружена и отправлена на проверку';
    } catch (Exception $e) {
        error_log('payment_receipt error: '.$e->getMessage());
        $_SESSION['lot_msg'] = 'Ошибка при сохранении квитанции';
    }

    header('Location: torgi_view.php?id='.$lot_id);
    exit;
}
// Предложение цены по лоту
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'make_offer') {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['lot_msg'] = 'Необходима авторизация для отправки предложения';
        header('Location: torgi_view.php?id='.(int)($_GET['id'] ?? 0));
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];
    $lot_id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    $price_raw   = trim($_POST['price'] ?? '0');
    $offer_price = (float)str_replace([' ', ','], ['', '.'], $price_raw);
    $comment     = trim($_POST['comment'] ?? '');
    $file_path   = '';

    if ($lot_id <= 0 || $offer_price <= 0) {
        $_SESSION['lot_msg'] = 'Укажите корректную цену';
        header('Location: torgi_view.php?id='.$lot_id);
        exit;
    }

    // Опциональный файл
    if (!empty($_FILES['offer_file']['name']) && $_FILES['offer_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/offers/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if ($_FILES['offer_file']['size'] <= 3 * 1024 * 1024) {
            $ext = strtolower(pathinfo($_FILES['offer_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','pdf','doc','docx','xls','xlsx'], true)) {
                $filename = 'offer_'.$lot_id.'_'.time().'.'.$ext;
                $target   = $upload_dir.$filename;
                if (move_uploaded_file($_FILES['offer_file']['tmp_name'], $target)) {
                    $file_path = $target;
                }
            }
        }
    }

    // Таблица offers (минимальный набор полей)
    $pdo->exec("CREATE TABLE IF NOT EXISTS offers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lot_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        price DECIMAL(15,2) NOT NULL,
        status ENUM('pending','accepted','rejected') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (lot_id), INDEX (user_id), INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO offers (lot_id, user_id, price, status, created_at)
             VALUES (?, ?, ?, "pending", NOW())'
        );
        $stmt->execute([$lot_id, $user_id, $offer_price]);
        $_SESSION['lot_msg'] = 'Ваше предложение отправлено продавцу';
    } catch (Exception $e) {
        $_SESSION['lot_msg'] = 'Ошибка при отправке предложения: ' . $e->getMessage();
    }

    header('Location: torgi_view.php?id='.$lot_id);
    exit;
}

// Заявка на осмотр лота ("Интересует")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'interest') {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['lot_msg'] = 'Необходима авторизация для отправки заявки на осмотр';
        header('Location: torgi_view.php?id='.(int)($_GET['id'] ?? 0));
        exit;
    }

    $user_id         = (int)$_SESSION['user_id'];
    $lot_id          = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $message         = trim($_POST['message'] ?? '');
    $contact_type    = $_POST['contact_type'] ?? 'email';
    $contact_value   = trim($_POST['contact_value'] ?? '');
    $inspection_date = $_POST['inspection_date'] ?? '';
    $full_name       = trim($_POST['full_name'] ?? '');
    $reg_address     = trim($_POST['registration_address'] ?? '');
    $file_path       = '';

    if ($lot_id <= 0 || $message === '' || $contact_value === '' || $full_name === '' || $reg_address === '') {
        $_SESSION['lot_msg'] = 'Заполните ФИО, адрес, сообщение и контактные данные';
        header('Location: torgi_view.php?id='.$lot_id);
        exit;
    }

    // Опциональный файл
    if (!empty($_FILES['interest_file']['name']) && $_FILES['interest_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/interests/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if ($_FILES['interest_file']['size'] <= 3 * 1024 * 1024) {
            $ext = strtolower(pathinfo($_FILES['interest_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','pdf','doc','docx'], true)) {
                $filename = 'interest_'.$lot_id.'_'.time().'.'.$ext;
                $target   = $upload_dir.$filename;
                if (move_uploaded_file($_FILES['interest_file']['tmp_name'], $target)) {
                    $file_path = $target;
                }
            }
        }
    }

    // Таблица interests (если нет)
    $pdo->exec("CREATE TABLE IF NOT EXISTS interests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lot_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        full_name VARCHAR(255) NULL,
        registration_address TEXT NULL,
        message TEXT NOT NULL,
        contact_type VARCHAR(50) NOT NULL,
        contact_value VARCHAR(255) NOT NULL,
        inspection_date DATETIME NULL,
        file_path VARCHAR(500),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (lot_id), INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO interests
             (lot_id, user_id, full_name, registration_address, message, contact_type, contact_value, inspection_date, file_path, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $lot_id,
            $user_id,
            $full_name,
            $reg_address,
            $message,
            $contact_type,
            $contact_value,
            $inspection_date ?: null,
            $file_path
        ]);
        $_SESSION['lot_msg'] = 'Ваша заявка на осмотр отправлена и находится на рассмотрении. Ожидайте, спасибо.';
    } catch (Exception $e) {
        $_SESSION['lot_msg'] = 'Ошибка при отправке заявки: '.$e->getMessage();
    }

    header('Location: torgi_view.php?id='.$lot_id);
    exit;
}
// Связаться с продавцом
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact_seller') {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['lot_msg'] = 'Необходима авторизация для отправки сообщения продавцу';
        header('Location: torgi_view.php?id='.(int)($_GET['id'] ?? 0));
        exit;
    }

    $from_user_id = (int)$_SESSION['user_id'];
    $lot_id       = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $message      = trim($_POST['message'] ?? '');
    $contact      = trim($_POST['contact'] ?? '');
    $file_path    = '';

    if ($lot_id <= 0 || $message === '' || $contact === '') {
        $_SESSION['lot_msg'] = 'Заполните сообщение и контакт для обратной связи';
        header('Location: torgi_view.php?id='.$lot_id);
        exit;
    }

    // файл (опционально)
    if (!empty($_FILES['contact_file']['name']) && $_FILES['contact_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/contacts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if ($_FILES['contact_file']['size'] <= 3 * 1024 * 1024) {
            $ext = strtolower(pathinfo($_FILES['contact_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','pdf','doc','docx'], true)) {
                $filename = 'contact_'.$lot_id.'_'.time().'.'.$ext;
                $target   = $upload_dir.$filename;
                if (move_uploaded_file($_FILES['contact_file']['tmp_name'], $target)) {
                    $file_path = $target;
                }
            }
        }
    }

    // таблица contacts
    $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lot_id INT UNSIGNED NOT NULL,
        from_user_id INT UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        contact VARCHAR(255) NOT NULL,
        file_path VARCHAR(500),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (lot_id), INDEX (from_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO contacts
             (lot_id, from_user_id, message, contact, file_path, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $lot_id,
            $from_user_id,
            $message,
            $contact,
            $file_path
        ]);
        $_SESSION['lot_msg'] = 'Ваше сообщение отправлено продавцу. Ожидайте ответа.';
    } catch (Exception $e) {
        $_SESSION['lot_msg'] = 'Ошибка при отправке сообщения: '.$e->getMessage();
    }

    header('Location: torgi_view.php?id='.$lot_id);
    exit;
}

/**
 * 2. Загрузка лота
 */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    include 'header.php';
    echo "<main style='padding:40px 20px;'><div style=\"max-width:800px;margin:0 auto;\"><div class='alert error'>Неверный идентификатор лота.</div></div></main>";
    include 'footer.php';
    exit;
}

$sql = "SELECT id, title, price, region, lot_type, description, images, date_created, status
        FROM torgi
        WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$lot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lot) {
    include 'header.php';
    echo "<main style='padding:40px 20px;'><div style=\"max-width:800px;margin:0 auto;\"><div class='alert error'>Лот не найден.</div></div></main>";
    include 'footer.php';
    exit;
}

$session_id = (int)($_SESSION['user_id'] ?? 0);
$usertype   = $_SESSION['usertype'] ?? 'user';
$lot_dealer_id = (int)($lot['dealer_id'] ?? 0);

// Право на редактирование — ТОЛЬКО admin
$can_edit = !empty($_SESSION['user_id']) && (
    ($_SESSION['usertype'] ?? '') === 'admin'
);

$images = [];
if (!empty($lot['images'])) {
    $tmp = json_decode($lot['images'], true);
    if (is_array($tmp)) {
        $images = $tmp;
    }
}

$msg = $_SESSION['lot_msg'] ?? null;
if ($msg !== null) {
    unset($_SESSION['lot_msg']);
}

include 'header.php';
?>
<main style="flex:1; padding:30px 20px;">
    <div style="max-width:1100px; margin:0 auto;">
        <a href="torgi_list.php" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#64748b; text-decoration:none; margin-bottom:16px;">
            ← Вернуться к списку
        </a>

        <?php if ($msg): ?>
            <div class="alert" style="background:#d1fae5;color:#065f46;">
                <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap:24px; align-items:flex-start;">

            <!-- Левая колонка: фото + кнопки -->
            <div>
                <?php if (!empty($images)): ?>
                    <div style="width:100%; border-radius:16px; overflow:hidden; background:#e5e7eb; margin-bottom:10px; position:relative;">
                        <button type="button"
                                onclick="changeTorgiImage(-1)"
                                style="position:absolute;left:8px;top:50%;transform:translateY(-50%);border:none;border-radius:999px;width:32px;height:32px;background:rgba(255,255,255,0.8);cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;">
                            ‹
                        </button>

                        <img id="torgiMainImage"
                             src="<?= htmlspecialchars($images[0]) ?>" alt=""
                             style="width:100%; max-height:340px; object-fit:cover; cursor:pointer;"
                             onclick="openImageModalFromMain()">

                        <button type="button"
                                onclick="changeTorgiImage(1)"
                                style="position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;border-radius:999px;width:32px;height:32px;background:rgba(255,255,255,0.8);cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;">
                            ›
                        </button>
                    </div>
                    <?php if (count($images) > 1): ?>
                        <div id="torgiThumbs" style="display:flex; gap:8px; overflow-x:auto; padding-bottom:4px; margin-bottom:8px;">
                            <?php foreach ($images as $idx => $img): ?>
                                <div data-index="<?= $idx ?>"
                                     onclick="setTorgiImage(<?= $idx ?>)"
                                     style="width:80px; height:60px; border-radius:8px; overflow:hidden; background:#e5e7eb; flex-shrink:0; border:2px solid <?= $idx === 0 ? '#0ea5e9' : 'transparent' ?>; cursor:pointer;">
                                    <img src="<?= htmlspecialchars($img) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="width:100%; height:260px; border-radius:16px; background:#e5e7eb; display:flex; align-items:center; justify-content:center; color:#9ca3af; margin-bottom:10px;">
                        Фото ещё не загружены
                    </div>
                <?php endif; ?>

                <!-- Кнопки под каруселью -->
                <div style="margin-top:6px; padding:6px 8px; border-radius:10px; background:#f8fafc; border:1px solid #e2e8f0;">
                    <div style="font-size:12px; color:#64748b; margin-bottom:4px;">
                        Действия с лотом
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:4px; text-align:center;">
                        <button type="button"
        onclick="openModal('offerModal')"
        style="border:none; cursor:pointer; padding:4px 2px; border-radius:8px;
               background:#0ea5e9; color:#0f172a; font-size:10px; font-weight:600;
               display:flex; flex-direction:column; align-items:center; gap:1px;">
    <span style="font-size:14px;">₽</span>
    <span>Предложить цену</span>
</button>
                        <button type="button"
        onclick="openModal('interestModal')"
        style="border:none; cursor:pointer; padding:4px 2px; border-radius:8px;
               background:#e0f2fe; color:#0f172a; font-size:10px; font-weight:600;
               display:flex; flex-direction:column; align-items:center; gap:1px;">
    <span style="font-size:14px;">★</span>
    <span>Интересует</span>
</button>
                        <button type="button"
        onclick="openModal('contactSellerModal')"
        style="border:none; cursor:pointer; padding:4px 2px; border-radius:8px;
               background:#e5e7eb; color:#0f172a; font-size:10px; font-weight:600;
               display:flex; flex-direction:column; align-items:center; gap:1px;">
    <span style="font-size:14px;">✉</span>
    <span>Связаться</span>
</button>
                        <button type="button"
                                onclick="openModal('upgradeModal')"
                                style="border:1px solid #0ea5e9; cursor:pointer; padding:4px 2px; border-radius:8px;
                                       background:#ffffff; color:#0ea5e9; font-size:10px; font-weight:600;
                                       display:flex; flex-direction:column; align-items:center; gap:1px;">
                            <span style="font-size:14px;">ℹ</span>
                            <span>Подробности</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: цена + карточка лота -->
            <div>
                <div style="padding:16px 18px; border-radius:16px; background:#ffffff; border:1px solid #e2e8f0; margin-bottom:16px;">
                    <div style="font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#94a3b8; margin-bottom:6px;">
                        Цена
                    </div>
                    <div style="font-size:26px; font-weight:900; color:#0ea5e9; margin-bottom:4px;">
                        <?= number_format($lot['price'], 0, '.', ' ') ?> ₽
                    </div>
                    <?php
                    $status = $lot['status'] ?? '';
                    $status_text = 'Статус: ' . htmlspecialchars($status);
                    $status_color = '#64748b';
                    if ($status === 'open') {
                        $status_text = 'Открыт для предложений';
                        $status_color = '#16a34a';
                    } elseif ($status === 'closed') {
                        $status_text = 'Сделка завершена';
                        $status_color = '#dc2626';
                    }
                    ?>
                    <div style="font-size:12px; font-weight:600; margin-bottom:4px; color:<?= $status_color ?>;">
                        <?= $status_text ?>
                    </div>
                    <div style="font-size:12px; color:#64748b;">
                        <?= htmlspecialchars($lot['region']) ?>, <?= date('d.m.Y', strtotime($lot['date_created'])) ?>
                    </div>
                </div>

                <div style="padding:16px 18px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0; margin-bottom:16px;">

                    <?php if ($can_edit): ?>
                        <a href="torgi_edit.php?id=<?= $lot['id'] ?>"
                           style="display:inline-flex;align-items:center;gap:6px;font-size:12px;
                                  padding:6px 10px;border-radius:999px;border:1px solid #e2e8f0;
                                  color:#0f172a;text-decoration:none;margin-bottom:10px;">
                            ✏ Редактировать лот
                        </a>
                    <?php endif; ?>

                    <h1 style="margin:0 0 10px; font-size:20px; font-weight:800; color:#0f172a;">
                        <?= htmlspecialchars($lot['title']) ?>
                    </h1>
                    <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; font-size:13px; color:#64748b;">
                        <div>
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.08em;">Категория</div>
                            <div style="color:#0f172a; font-weight:600;"><?= htmlspecialchars($lot['lot_type']) ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.08em;">Регион</div>
                            <div style="color:#0f172a;"><?= htmlspecialchars($lot['region']) ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.08em;">Создан</div>
                            <div><?= date('d.m.Y', strtotime($lot['date_created'])) ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.08em;">ID</div>
                            <div>#<?= $lot['id'] ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($lot['description'])): ?>
                    <div style="padding:16px 18px; border-radius:16px; background:#ffffff; border:1px solid #e2e8f0;">
                        <div style="font-size:14px; font-weight:700; color:#0f172a; margin-bottom:8px;">Описание лота</div>
                        <div style="font-size:14px; color:#334155; white-space:pre-line;">
                            <?= nl2br(htmlspecialchars($lot['description'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Модалка увеличения фото -->
<div id="imageModal" class="modal">
    <div class="modal-content" style="max-width:100%; max-height:100%; background:rgba(0,0,0,0.7); position:relative; padding:0; box-shadow:none; display:flex;align-items:center;justify-content:center;">
        <button type="button"
                onclick="closeModal('imageModal')"
                style="position:absolute; top:10px; right:10px; background:white; border-radius:50%; width:36px; height:36px; border:none; cursor:pointer; z-index:3;">
            ×
        </button>

        <button type="button"
                onclick="changeModalImage(-1)"
                style="position:absolute;left:20px;top:50%;transform:translateY(-50%);border:none;border-radius:999px;width:40px;height:40px;background:rgba(15,23,42,0.7);color:#e5e7eb;font-size:24px;cursor:pointer;z-index:3;">
            ‹
        </button>

        <img src="" id="fullImage" style="max-width:95vw; max-height:90vh; width:auto; height:auto; display:block;">

        <button type="button"
                onclick="changeModalImage(1)"
                style="position:absolute;right:20px;top:50%;transform:translateY(-50%);border:none;border-radius:999px;width:40px;height:40px;background:rgba(15,23,42,0.7);color:#e5e7eb;font-size:24px;cursor:pointer;z-index:3;">
            ›
        </button>
    </div>
</div>
<!-- Модалка "Предложить цену" -->
<div id="offerModal" class="modal">
  <div class="modal-content" style="max-width:460px; width:100%; background:#ffffff; border-radius:20px; padding:20px; max-height:90vh; overflow-y:auto; position:relative;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
      <h3 style="margin:0; font-size:17px; font-weight:800; color:#0f172a;">Предложить свою цену</h3>
      <button type="button" onclick="closeModal('offerModal')" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">×</button>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="make_offer">

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
          Ваша цена, ₽
        </label>
        <input type="text" name="price" required
               placeholder="<?= number_format($lot['price'], 0, '.', ' ') ?>"
               style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:14px;">
        <div style="font-size:11px; color:#64748b; margin-top:3px;">
          Текущая цена: <?= number_format($lot['price'], 0, '.', ' ') ?> ₽
        </div>
      </div>

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
          Комментарий (опционально)
        </label>
        <textarea name="comment" rows="3"
                  style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;"></textarea>
      </div>

      <div class="form-group" style="margin-bottom:12px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
          Файл (необязательно)
        </label>
        <input type="file" name="offer_file"
               accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
               style="width:100%; padding:6px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
        <div style="font-size:11px; color:#64748b; margin-top:3px;">
          До 3 МБ, фото/документы.
        </div>
      </div>

      <button type="submit"
              style="width:100%; padding:10px; border-radius:10px; border:none; background:#0ea5e9; color:#fff; font-weight:700; cursor:pointer; font-size:14px;">
        Отправить предложение
      </button>
    </form>
  </div>
</div>

<!-- Модалка "Заявка на осмотр / Интересует" -->
<div id="interestModal" class="modal">
  <div class="modal-content" style="max-width:480px; width:100%; background:#ffffff; border-radius:20px; padding:20px; max-height:90vh; overflow-y:auto; position:relative;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
      <h3 style="margin:0; font-size:17px; font-weight:800; color:#0f172a;">Заявка на осмотр лота</h3>
      <button type="button" onclick="closeModal('interestModal')" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">×</button>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="interest">

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">ФИО</label>
        <input type="text" name="full_name" required
               placeholder="Фамилия Имя Отчество"
               style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
      </div>

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Адрес регистрации</label>
        <textarea name="registration_address" rows="2" required
                  placeholder="Индекс, город, улица, дом, квартира"
                  style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;"></textarea>
      </div>

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Желаемая дата и время осмотра</label>
          <input type="datetime-local" name="inspection_date" id="inspection_date"
         style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
      </div>

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Сообщение продавцу</label>
        <textarea name="message" rows="3" required
                  placeholder="Кратко опишите интерес и вопросы по лоту"
                  style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;"></textarea>
      </div>

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Как с вами связаться</label>
        <div style="display:flex; gap:8px; margin-bottom:6px; font-size:12px;">
          <label><input type="radio" name="contact_type" value="email" checked> Email</label>
          <label><input type="radio" name="contact_type" value="phone"> Телефон</label>
          <label><input type="radio" name="contact_type" value="telegram"> Telegram</label>
        </div>
        <input type="text" name="contact_value" required
               placeholder="Ваш email / телефон / @telegram"
               style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
      </div>

      <div class="form-group" style="margin-bottom:12px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Файл (необязательно)</label>
        <input type="file" name="interest_file"
               accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
               style="width:100%; padding:6px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
        <div style="font-size:11px; color:#64748b; margin-top:3px;">До 3 МБ.</div>
      </div>

      <button type="submit"
              style="width:100%; padding:10px; border-radius:10px; border:none; background:#22c55e; color:#fff; font-weight:700; cursor:pointer; font-size:14px;">
        Отправить заявку
      </button>
    </form>
  </div>
</div>
<!-- Модалка "Связаться с продавцом" -->
<div id="contactSellerModal" class="modal">
  <div class="modal-content" style="max-width:460px; width:100%; background:#ffffff; border-radius:20px; padding:20px; max-height:90vh; overflow-y:auto; position:relative;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
      <h3 style="margin:0; font-size:17px; font-weight:800; color:#0f172a;">Связаться с продавцом</h3>
      <button type="button" onclick="closeModal('contactSellerModal')" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">×</button>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="contact_seller">

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Сообщение</label>
        <textarea name="message" rows="4" required
                  placeholder="Напишите ваш вопрос или комментарий по лоту"
                  style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;"></textarea>
      </div>

      <div class="form-group" style="margin-bottom:10px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Как с вами связаться</label>
        <input type="text" name="contact" required
               placeholder="Email, телефон или @telegram"
               style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
      </div>

      <div class="form-group" style="margin-bottom:12px;">
        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Файл (необязательно)</label>
        <input type="file" name="contact_file"
               accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"
               style="width:100%; padding:6px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
        <div style="font-size:11px; color:#64748b; margin-top:3px;">До 3 МБ.</div>
      </div>

      <button type="submit"
              style="width:100%; padding:10px; border-radius:10px; border:none; background:#0ea5e9; color:#fff; font-weight:700; cursor:pointer; font-size:14px;">
        Отправить сообщение
      </button>
    </form>
  </div>
</div>

<!-- Модалка оплаты / апгрейда -->
<div id="upgradeModal" class="modal">
  <div class="modal-content" style="max-width:500px; width:100%; background:#ffffff; border-radius:20px; padding:24px; max-height:90vh; overflow-y:auto; position:relative;">
    <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
      <h3 style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">Выберите вариант</h3>
      <button type="button" onclick="closeModal('upgradeModal')" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">×</button>
    </div>

    <div class="tariff-card" onclick="selectTariff(this)" data-tariff="details"
         style="background:#f8fafc; border:2px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:12px; cursor:pointer;">
      <h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">Отчет по лоту</h3>
      <div class="tariff-price" style="font-size:20px; font-weight:800; color:#0ea5e9; margin-bottom:4px;">
        1 390 <small style="font-size:11px; font-weight:400; color:#64748b;">₽, в т.ч. НДС 22%</small>
      </div>
      <ul style="margin:0; padding-left:18px; font-size:13px; color:#475569;">
        <li>Подробный отчет</li>
        <li>Рекомендации эксперта</li>
        <li>PDF на почту</li>
      </ul>
    </div>

    <div class="tariff-card" onclick="selectTariff(this)" data-tariff="responsible"
         style="background:#f8fafc; border:2px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:12px; cursor:pointer;">
      <h3 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0f172a;">Повысить статус</h3>
      <div class="tariff-price" style="font-size:20px; font-weight:800; color:#0ea5e9; margin-bottom:4px;">
        8 000 <small style="font-size:11px; font-weight:400; color:#64748b;">₽, в т.ч. НДС 22%</small>
      </div>
      <ul style="margin:0; padding-left:18px; font-size:13px; color:#475569;">
        <li>Статус «Ответственный»</li>
        <li>Приоритет в сделках</li>
        <li>Личные рекомендации</li>
      </ul>
    </div>

    <div id="paymentDetails" style="display:none; background:#f8fafc; padding:10px 12px; border-radius:10px; margin:12px 0; font-size:13px; color:#334155;"></div>

    <div class="payment-methods" id="paymentMethods" style="display:none; margin:12px 0;">
      <div style="font-size:12px; color:#64748b; margin-bottom:6px;">Способ оплаты</div>
      <div class="payment-buttons" style="display:flex; gap:8px;">
        <button type="button" onclick="selectPaymentMethod('qr')" id="paymentqr"
                class="payment-btn selected"
                style="flex:1; padding:8px 10px; background:#0f172a; border:2px solid #334155; border-radius:8px; color:#e5e7eb; cursor:pointer; font-size:12px;">
          QR / СБП
        </button>
        <button type="button" onclick="selectPaymentMethod('receipt')" id="paymentreceipt"
                class="payment-btn"
                style="flex:1; padding:8px 10px; background:#0f172a; border:2px solid #334155; border-radius:8px; color:#e5e7eb; cursor:pointer; font-size:12px;">
          Квитанция
        </button>
      </div>
    </div>

    <div id="qrblock" class="qr-reg-block" style="display:none; background:#ffffff; padding:16px; border-radius:10px; text-align:center; border:1px solid #e2e8f0; margin-bottom:10px;">
      <img id="qrimage" src="" style="width:180px; height:180px; display:block; margin:0 auto 8px;">
      <div style="font-size:12px; color:#64748b;">ИНН 7728282160</div>
    </div>

    <div id="receiptblock" class="receipt-reg-block" style="display:none; background:#0f172a; padding:16px; border-radius:10px; color:#cbd5e1; margin-bottom:10px; font-size:13px;">
      <p style="margin:0 0 8px;">Скачайте квитанцию, оплатите в банке или приложении и затем загрузите чек.</p>
      <button type="button" class="receipt-generate-btn"
              onclick="generateReceipt()"
              style="width:100%; padding:10px; background:#0ea5e9; color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
        Сформировать квитанцию
      </button>
    </div>

    <div id="receiptFormBlock" style="margin-top:10px; border-top:1px solid #e2e8f0; padding-top:12px; display:none;">
      <p style="font-weight:600; margin:0 0 8px; font-size:13px;">Загрузите квитанцию об оплате</p>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="submit_receipt">
        <input type="hidden" name="tariff" id="receipttariff" value="">
        <input type="hidden" name="amount" id="receiptamount" value="">
        <div class="form-group" style="margin-bottom:10px;">
          <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Файл (JPG, PNG, PDF до 5 МБ)</label>
          <input type="file" name="receipt_file" accept="image/*,application/pdf" required
                 style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
        </div>
        <div class="form-group" style="margin-bottom:10px;">
          <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Комментарий</label>
          <textarea name="comment" rows="2"
                    style="width:100%; padding:8px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;"></textarea>
        </div>
        <button type="submit" class="btn btn-primary"
                style="width:100%; padding:10px; border-radius:10px; border:none; background:#0ea5e9; color:#fff; font-weight:700; cursor:pointer;">
          Отправить на проверку
        </button>
      </form>
    </div>

    <div style="display:flex; gap:8px; margin-top:14px;" id="actionButtons">
      <button type="button"
              onclick="closeModal('upgradeModal')"
              style="flex:1; padding:8px; border-radius:10px; border:1px solid #e2e8f0; background:#f9fafb; cursor:pointer; font-size:13px;">
        Отмена
      </button>
      <button type="button"
              onclick="markAsPaid()"
              style="flex:1; padding:8px; border-radius:10px; border:none; background:#0ea5e9; color:#fff; cursor:pointer; font-size:13px; font-weight:600;">
        Я оплатил(а)
      </button>
    </div>
  </div>
</div>

<script>
<?php if (!empty($images)): ?>
let torgiImages = <?= json_encode($images, JSON_UNESCAPED_UNICODE) ?>;
let torgiCurrent = 0;

function setTorgiImage(idx) {
    if (idx < 0 || idx >= torgiImages.length) return;
    torgiCurrent = idx;
    const mainImg = document.getElementById('torgiMainImage');
    if (mainImg) mainImg.src = torgiImages[torgiCurrent];
    document.querySelectorAll('#torgiThumbs div').forEach(function(el, i) {
        el.style.borderColor = (i === torgiCurrent) ? '#0ea5e9' : 'transparent';
    });
}

function changeTorgiImage(dir) {
    if (!torgiImages.length) return;
    let next = torgiCurrent + dir;
    if (next < 0) next = torgiImages.length - 1;
    if (next >= torgiImages.length) next = 0;
    setTorgiImage(next);
}

function openImageModalFromMain() {
    const img = document.getElementById('fullImage');
    if (!img) return;
    img.src = torgiImages[torgiCurrent];
    openModal('imageModal');
}

function changeModalImage(dir) {
    changeTorgiImage(dir);
    const img = document.getElementById('fullImage');
    if (img) img.src = torgiImages[torgiCurrent];
}
<?php endif; ?>

function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('active');
    document.body.style.overflow = '';
}

document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m) closeModal(m.id);
    });
});

// Тарифы, QR, квитанция
let selectedTariff = null;
let currentAmount = 0;
let currentTariffName = '';

function selectTariff(el) {
    document.querySelectorAll('.tariff-card').forEach(c => {
        c.style.borderColor = '#e2e8f0';
        c.style.background = '#f8fafc';
    });
    el.style.borderColor = '#0ea5e9';
    el.style.background = '#eff6ff';

    selectedTariff = el.dataset.tariff;
    if (selectedTariff === 'details') {
        currentAmount = 1390;
        currentTariffName = 'Отчет по лоту';
    } else {
        currentAmount = 8000;
        currentTariffName = 'Статус Ответственный';
    }
    const vat = Math.round(currentAmount * 22 / 122);

    const pd = document.getElementById('paymentDetails');
    pd.style.display = 'block';
    pd.innerHTML =
        '<div style="font-weight:600;">' + currentTariffName + '</div>' +
        '<div>' + currentAmount.toLocaleString('ru-RU') + ' ₽, в т.ч. НДС ' +
        vat.toLocaleString('ru-RU') + ' ₽ (22%)</div>';

    document.getElementById('paymentMethods').style.display = 'block';
    document.getElementById('receipttariff').value = currentTariffName;
    document.getElementById('receiptamount').value = currentAmount;

    const qrData =
        'ST00012' +
        '|Name=ООО «Форсаж»' +
        '|PersonalAcc=40702810101500033019' +
        '|BankName=ООО «Банк Точка»' +
        '|BIC=044525104' +
        '|CorrespAcc=30101810745374525104' +
        '|PayeeINN=7728282160' +
        '|KPP=773001001' +
        '|Sum=' + (currentAmount * 100) +
        '|Purpose=Оплата услуг по лоту, сумма ' +
        currentAmount +
        ' руб., в т.ч. НДС 22%';

    document.getElementById('qrimage').src =
        'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(qrData);

    document.getElementById('qrblock').style.display = 'block';
    document.getElementById('receiptblock').style.display = 'none';
    document.getElementById('receiptFormBlock').style.display = 'none';
    document.getElementById('actionButtons').style.display = 'flex';
}

function selectPaymentMethod(method) {
    document.getElementById('paymentqr').classList.remove('selected');
    document.getElementById('paymentreceipt').classList.remove('selected');
    document.getElementById('payment' + method).classList.add('selected');

    document.getElementById('qrblock').style.display = (method === 'qr') ? 'block' : 'none';
    document.getElementById('receiptblock').style.display = (method === 'receipt') ? 'block' : 'none';
}

function generateReceipt() {
    if (!selectedTariff || !currentAmount) return;

    const vat = Math.round(currentAmount * 22 / 122);
    const qrData =
        'ST00012' +
        '|Name=ООО «Форсаж»' +
        '|PersonalAcc=40702810101500033019' +
        '|BankName=ООО «Банк Точка»' +
        '|BIC=044525104' +
        '|CorrespAcc=30101810745374525104' +
        '|PayeeINN=7728282160' +
        '|KPP=773001001' +
        '|Sum=' + (currentAmount * 100) +
        '|Purpose=Оплата услуг по лоту, сумма ' +
        currentAmount +
        ' руб., в т.ч. НДС 22%';

    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(qrData);

    const w = window.open('', '_blank', 'width=700,height=800,scrollbars=yes,resizable=yes');
    if (!w) return;

    w.document.write(
        '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Квитанция</title>' +
        '<style>body{font-family:Inter,Arial,sans-serif;padding:40px;background:#f8fafc;margin:0;}' +
        '.receipt{max-width:520px;margin:0 auto;background:#fff;border-radius:20px;padding:26px;' +
        'box-shadow:0 10px 25px rgba(0,0,0,0.1);border:1px solid #e2e8f0;}' +
        'h1{font-size:22px;font-weight:800;margin:0 0 18px;}' +
        '.details{margin:14px 0;line-height:1.6;color:#334155;font-size:13px;}' +
        '.qr{text-align:center;margin:18px 0;}' +
        '.footer{font-size:11px;color:#64748b;margin-top:16px;text-align:center;}' +
        'button{display:block;width:100%;padding:10px;background:#0ea5e9;color:#fff;border:none;' +
        'border-radius:8px;font-weight:600;cursor:pointer;margin-top:18px;}</style></head><body>' +
        '<div class="receipt">' +
        '<h1>Квитанция на оплату</h1>' +
        '<div class="details">' +
        '<p><strong>Получатель:</strong> ООО «Форсаж»</p>' +
        '<p><strong>Юр./почт. адрес:</strong> 121059, г. Москва, ул. Киевская, д.14, оф.2а</p>' +
        '<p><strong>ИНН / КПП:</strong> 7728282160 / 773001001</p>' +
        '<p><strong>Р/с:</strong> 40702810101500033019 в ООО «Банк Точка»</p>' +
        '<p><strong>Корр. счёт:</strong> 30101810745374525104</p>' +
        '<p><strong>БИК:</strong> 044525104</p>' +
        '<p><strong>Услуга:</strong> ' + currentTariffName + '</p>' +
        '<p><strong>Сумма:</strong> ' +
        currentAmount.toLocaleString('ru-RU') +
        ' ₽, в т.ч. НДС ' +
        vat.toLocaleString('ru-RU') +
        ' ₽ (22%)</p>' +
        '</div>' +
        '<div class="qr"><img src="' + qrUrl + '" style="max-width:200px;"><p>QR для оплаты через банк</p></div>' +
        '<div class="footer"><p>' + (new Date()).toLocaleDateString('ru-RU') + '</p></div>' +
        '<button onclick="window.print()">Распечатать</button>' +
        '</div></body></html>'
    );
    w.document.close();
}

function markAsPaid() {
    if (!selectedTariff || !currentAmount) {
        alert('Сначала выберите тариф');
        return;
    }
    document.getElementById('qrblock').style.display = 'none';
    document.getElementById('paymentMethods').style.display = 'none';
    document.getElementById('paymentDetails').style.display = 'none';
    document.getElementById('actionButtons').style.display = 'none';
    document.getElementById('receiptFormBlock').style.display = 'block';
}
function addBusinessDays(date, days) {
    const result = new Date(date);
    let added = 0;
    while (added < days) {
        result.setDate(result.getDate() + 1);
        const day = result.getDay(); // 0 - вс, 6 - сб
        if (day !== 0 && day !== 6) {
            added++;
        }
    }
    return result;
}

function toDatetimeLocalString(date) {
    const pad = n => n.toString().padStart(2, '0');
    return date.getFullYear() + '-' +
        pad(date.getMonth() + 1) + '-' +
        pad(date.getDate()) + 'T' +
        pad(date.getHours()) + ':' +
        pad(date.getMinutes());
}

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('inspection_date');
    if (!input) return;

    const now = new Date();
    const minDate = addBusinessDays(now, 3);
    const minStr = toDatetimeLocalString(minDate);

    input.min = minStr;

    input.addEventListener('change', () => {
        if (!input.value) return;
        const chosen = new Date(input.value);
        // запретить выходные
        const day = chosen.getDay();
        if (day === 0 || day === 6 || chosen < minDate) {
            alert('Выберите дату не раньше чем через 3 рабочих дня, без выходных.');
            input.value = '';
        }
    });
});
</script>

<style>
.alert {
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-weight: 600;
    font-size: 14px;
}
.error {
    background: #fee2e2;
    color: #991b1b;
}

.modal {
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.5);
    z-index:2000;
    justify-content:center;
    align-items:center;
    backdrop-filter:blur(4px);
    padding:15px;
}
.modal.active {
    display:flex;
}
</style>

<?php include 'footer.php'; ?>
