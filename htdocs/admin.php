<?php
require_once __DIR__ . '/admin_only.php';
/* На InfinityFree session_start() кидает Notice ps_files_cleanup_dir:
   opendir(/php_sessions) failed: Permission denied — это их хостинговая
   проблема (PHP не может GC-нуть системную папку). Глушим через @,
   остальные ошибки остаются видимыми. */
error_reporting(E_ALL);
ini_set('display_errors', 1);
@session_start();
header('Content-Type: text/html; charset=utf-8');

include 'db.php';
require_once __DIR__ . '/db_schema_extra.php';

// Принудительная установка кодировки
$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET CHARACTER SET utf8mb4");

$tab = $_GET['tab'] ?? 'users';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    
    if ($_POST['action'] === 'approve') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['admin_msg'] = '✅ Пользователь активирован';
        
        } elseif ($_POST['action'] === 'reject_lot') {
        $lotId = (int)$_POST['lot_id'];
        $stmt = $pdo->prepare("UPDATE torgi SET status = 'closed' WHERE id = ?");
        $stmt->execute([$lotId]);
        $_SESSION['admin_msg'] = '✅ Лот закрыт (сделка завершена)';
        
    } elseif ($_POST['action'] === 'edit_lot') {
        // дальше твой код edit_lot...

    } elseif ($_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("SELECT file1, file2, file3 FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $files = $stmt->fetch();
        
        if ($files) {
            foreach ($files as $file) {
                if ($file && file_exists($file)) {
                    unlink($file);
                }
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['admin_msg'] = '🗑️ Пользователь удален';
        
    } elseif ($_POST['action'] === 'edit') {
        $email = $_POST['email'] ?? '';
        $status = $_POST['status'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $balance = (float)($_POST['balance'] ?? 0);
        
        $stmt = $pdo->prepare("UPDATE users SET email = ?, status = ?, role = ?, balance = ? WHERE id = ?");
        $stmt->execute([$email, $status, $role, $balance, $userId]);
        $_SESSION['admin_msg'] = '✏️ Данные обновлены';
        
    } elseif ($_POST['action'] === 'bulk_delete') {
        $ids = $_POST['user_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $_SESSION['admin_msg'] = '🗑️ Выбранные пользователи удалены';
        }
        
    } elseif ($_POST['action'] === 'accept_offer') {
        $offerId = (int)$_POST['offer_id'];
        $stmt = $pdo->prepare("UPDATE offers SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$offerId]);
        $_SESSION['admin_msg'] = '✅ Предложение принято';
        
    } elseif ($_POST['action'] === 'reject_offer') {
        $offerId = (int)$_POST['offer_id'];
        $stmt = $pdo->prepare("UPDATE offers SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$offerId]);
        $_SESSION['admin_msg'] = '❌ Предложение отклонено';

    } elseif ($_POST['action'] === 'closed_approve_participant' || $_POST['action'] === 'closed_reject_participant') {
        // Допуск/отклонение разрешён только администратору или владельцу (организатору) лота.
        $partId   = (int)($_POST['participant_id'] ?? 0);
        $newState = $_POST['action'] === 'closed_approve_participant' ? 'approved' : 'rejected';
        $authStmt = $pdo->prepare("
            SELECT cp.lot_id, l.owner_id
              FROM closed_participants cp
              JOIN lots l ON l.id = cp.lot_id
             WHERE cp.id = ?
        ");
        $authStmt->execute([$partId]);
        $authRow = $authStmt->fetch(PDO::FETCH_ASSOC);

        $roleStmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
        $roleStmt->execute([(int)$_SESSION['user_id']]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
        $isAdmin = $roleRow && ($roleRow['user_type'] === 'admin');
        $isOwner = $authRow && ((int)$authRow['owner_id'] === (int)$_SESSION['user_id']);

        if (!$authRow || (!$isAdmin && !$isOwner)) {
            $_SESSION['admin_msg'] = '⛔ Недостаточно прав для решения по этой заявке';
        } else {
            $stmt = $pdo->prepare("
                UPDATE closed_participants
                   SET status = ?, decided_at = NOW(), decided_by = ?
                 WHERE id = ?
            ");
            $stmt->execute([$newState, (int)$_SESSION['user_id'], $partId]);
            $_SESSION['admin_msg'] = $newState === 'approved'
                ? '✅ Участник допущен к закрытому аукциону'
                : '❌ Заявка участника отклонена';
        }

    } elseif ($_POST['action'] === 'approve_lot') {
        $lotId = (int)$_POST['lot_id'];
        $stmt = $pdo->prepare("UPDATE torgi SET status = 'open' WHERE id = ?");
        $stmt->execute([$lotId]);
        $_SESSION['admin_msg'] = '✅ Лот открыт (приём заявок)';
        
    } elseif ($_POST['action'] === 'reject_lot') {
        $lotId = (int)$_POST['lot_id'];
        $stmt = $pdo->prepare("UPDATE torgi SET status = 'closed' WHERE id = ?");
        $stmt->execute([$lotId]);
        $_SESSION['admin_msg'] = '✅ Лот закрыт (сделка завершена)';
        
} elseif ($_POST['action'] === 'edit_lot') {
    $lotId = (int)$_POST['lot_id'];

    // основные поля лота
    $title        = $_POST['title'] ?? '';
    $lot_type     = $_POST['category'] ?? '';
    $price        = floatval($_POST['price'] ?? 0);
    $region       = $_POST['region'] ?? '';
    $description  = $_POST['description'] ?? '';
    $status       = $_POST['status'] ?? 'open';
    $date_created = $_POST['datecreated'] ?? '';

    $sql = "UPDATE torgi 
            SET title = ?, lottype = ?, price = ?, region = ?, description = ?, status = ?";
    $params = [$title, $lot_type, $price, $region, $description, $status];

    if ($date_created !== '') {
        $sql .= ", datecreated = ?";
        $params[] = $date_created;
    }

    $sql .= " WHERE id = ?";
    $params[] = $lotId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // === РАБОТА С ФОТО ===

    // 1. забираем текущий JSON из torgi
    $stmt = $pdo->prepare("SELECT images FROM torgi WHERE id = ?");
    $stmt->execute([$lotId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $images = [];
    if (!empty($row['images'])) {
        $tmp = json_decode($row['images'], true);
        if (is_array($tmp)) {
            $images = $tmp;
        }
    }

    // 2. удаляем отмеченные фото
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
        $images = array_values($images); // переиндексация
    }

    // 3. добавляем новые фото
    if (!empty($_FILES['new_images']['name'][0])) {
        $uploadDir = 'uploads/torgi';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $total = count($_FILES['new_images']['name']);
        for ($i = 0; $i < $total; $i++) {
            if ($_FILES['new_images']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            // до 3МБ
            if ($_FILES['new_images']['size'][$i] > 3 * 1024 * 1024) {
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
    }

    // 4. сохраняем JSON обратно в torgi
    $stmt = $pdo->prepare("UPDATE torgi SET images = ? WHERE id = ?");
    $stmt->execute([json_encode($images, JSON_UNESCAPED_UNICODE), $lotId]);

    $_SESSION['admin_msg'] = 'Лот и фото обновлены';
    header("Location: admin.php?tab=commission");
    exit;
}
}
// Поиск и фильтры для пользователей
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = ["1=1"];
$params = [];

if ($filter_status !== 'all') {
    $where[] = "status = ?";
    $params[] = $filter_status;
}

if (!empty($search)) {
    $where[] = "(username LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where_sql");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $limit);

$sql = "SELECT * FROM users WHERE $where_sql ORDER BY id DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
foreach ($params as $i => $value) {
    $stmt->bindValue($i + 1, $value, PDO::PARAM_STR);
}
$stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$msg = $_SESSION['admin_msg'] ?? '';
unset($_SESSION['admin_msg']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админка — Управление</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0f172a;
            color: #e2e8f0;
            font-family: sans-serif;
            padding: 30px 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { margin-bottom: 30px; color: #fff; }
        .msg {
            background: #1e293b;
            border-left: 4px solid #3b82f6;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 8px;
        }
        .filters {
            background: #1e293b;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filters input, .filters select {
            background: #0f172a;
            border: 1px solid #334155;
            padding: 10px 15px;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            min-width: 200px;
        }
td.price-cell {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    font-size: 12px;
    max-width: 120px;
}
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-warning { background: #f59e0b; color: #0f172a; }
        .btn-approve { background: #22c55e; color: #0f172a; }
        .btn-reject { background: #ef4444; color: #fff; }
        .btn-sm { padding: 4px 10px; font-size: 11px; }
        
        .tab-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
        }
        .tab-btn:hover {
            background: #1e293b;
            color: #fff;
        }
        .tab-btn.active {
            background: #1e293b;
            color: #3b82f6;
        }
        
        table {
            width: 100%;
            background: #1e293b;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #334155;
            margin-bottom: 20px;
        }
        th {
            background: #0f172a;
            padding: 15px;
            text-align: left;
            color: #94a3b8;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        td {
            padding: 15px;
            border-bottom: 1px solid #334155;
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: #1e293b;
            padding: 30px;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            border: 1px solid #334155;
        }
        .modal-content h3 { margin-bottom: 15px; color: #fff; }
        .modal-content input,
        .modal-content select,
        .modal-content textarea {
            width: 100%;
            padding: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #fff;
            margin-bottom: 10px;
        }
        .modal-btns {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .input-field {
            width: 100%;
            padding: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #fff;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>👨‍💼 Управление</h1>
    
    <?php if ($msg): ?>
        <div class="msg"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Вкладки -->
    <div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #334155; padding-bottom: 10px;">
        <a href="admin.php?tab=users" class="tab-btn <?= $tab === 'users' ? 'active' : '' ?>">👥 Пользователи</a>
        <a href="admin.php?tab=offers" class="tab-btn <?= $tab === 'offers' ? 'active' : '' ?>">💰 Предложения</a>
        <a href="admin.php?tab=commission" class="tab-btn <?= $tab === 'commission' ? 'active' : '' ?>">🏷️ Комиссионные лоты</a>
        <a href="admin.php?tab=inspection" class="tab-btn <?= $tab === 'inspection' ? 'active' : '' ?>">🔍 Заявки на осмотр</a>
        <a href="admin.php?tab=closed_admit" class="tab-btn <?= $tab === 'closed_admit' ? 'active' : '' ?>">🔐 Закрытые аукционы</a>
        <a href="admin_payments.php" class="tab-btn">💳 Оплата отчётов</a>
    </div>

    <?php if ($tab === 'users'): ?>
        <!-- Фильтры пользователей -->
        <form method="GET" class="filters">
            <input type="hidden" name="tab" value="users">
            <input type="text" name="search" placeholder="Поиск по логину или email" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <select name="status">
                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Все статусы</option>
                <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Ожидают</option>
                <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Активные</option>
                <option value="blocked" <?= $filter_status === 'blocked' ? 'selected' : '' ?>>Заблокированные</option>
            </select>
            <button type="submit" class="btn btn-primary">Применить</button>
            <a href="admin.php?tab=users" class="btn" style="background:#334155; color:#fff;">Сбросить</a>
        </form>

        <?php if (empty($users)): ?>
            <div style="text-align: center; padding: 60px; color: #64748b;">Пользователей не найдено</div>
        <?php else: ?>
            <form method="POST" id="bulkForm">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>ID</th>
                            <th>Пользователь</th>
                            <th>Статус</th>
                            <th>Роль</th>
                            <th>Баланс</th>
                            <th>Файлы</th>
                            <th>Telegram</th>
                            <th>Дата рег.</th>
                            <th>Действия</th>
                        </td>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><input type="checkbox" name="user_ids[]" value="<?= $user['id'] ?>" class="userCheckbox"></td>
                            <td>#<?= $user['id'] ?></td>
                            <td>
                                <b><?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></b><br>
                                <small><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td>
                                <?php
                                $statusColors = [
                                    'pending' => '#fbbf24',
                                    'active' => '#22c55e',
                                    'blocked' => '#ef4444'
                                ];
                                $color = $statusColors[$user['status']] ?? '#94a3b8';
                                ?>
                                <span style="background: <?= $color ?>20; color: <?= $color ?>; padding: 4px 8px; border-radius: 12px; font-size: 11px;">
                                    <?= $user['status'] === 'pending' ? 'Ожидает' : ($user['status'] === 'active' ? 'Активен' : 'Заблокирован') ?>
                                </span>
                             </tr>
                             <tr>
                                <td><?= htmlspecialchars($user['role'] ?? 'user', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format($user['balance'] ?? 0, 2) ?> ₽</td>
                                <td>
                                    <?php
                                    $files = [];
                                    if (!empty($user['file1'])) $files[] = '<a href="'.htmlspecialchars($user['file1'], ENT_QUOTES, 'UTF-8').'" target="_blank" style="color:#3b82f6;">📄 1</a>';
                                    if (!empty($user['file2'])) $files[] = '<a href="'.htmlspecialchars($user['file2'], ENT_QUOTES, 'UTF-8').'" target="_blank" style="color:#3b82f6;">📄 2</a>';
                                    if (!empty($user['file3'])) $files[] = '<a href="'.htmlspecialchars($user['file3'], ENT_QUOTES, 'UTF-8').'" target="_blank" style="color:#3b82f6;">📄 3</a>';
                                    echo $files ? implode(' ', $files) : '—';
                                    ?>
                                </td>
                                <td><?= !empty($user['telegram_id']) ? '✅' : '❌' ?></td>
                                <td><?= date('d.m.Y', strtotime($user['created_at'] ?? 'now')) ?></td>
                                <td>
                                    <?php if (($user['status'] ?? '') === 'pending'): ?>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                            <button class="btn btn-success btn-sm" type="submit" title="Одобрить TG-юзера">✅ Одобрить</button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="admin_user_edit.php?id=<?= (int)$user['id'] ?>" class="btn btn-warning btn-sm" style="text-decoration:none">✏️ Карточка</a>
                                    <button onclick="confirmDelete(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>'); return false;" class="btn btn-danger btn-sm">🗑️ Уд.</button>
                                </td>
                             </tr>
                    <?php endforeach; ?>
                    </tbody>
                 </table>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" name="action" value="bulk_delete" class="btn btn-danger" onclick="return confirm('Удалить выбранных пользователей?')">🗑️ Удалить выбранное</button>
                </div>
            </form>

            <?php if ($totalPages > 1): ?>
            <div style="display: flex; gap: 8px; justify-content: center; margin-top: 20px;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?tab=users&page=<?= $i ?>&status=<?= $filter_status ?>&search=<?= urlencode($search) ?>" 
                       style="padding: 8px 12px; background: <?= $i === $page ? '#3b82f6' : '#1e293b' ?>; border-radius: 6px; color: #fff; text-decoration: none;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($tab === 'offers'): ?>
        <?php
        $status_filter = $_GET['offer_status'] ?? 'all';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';

        $where = ["1=1"];
        $params = [];

        if ($status_filter !== 'all') {
            $where[] = "o.status = ?";
            $params[] = $status_filter;
        }

        if (!empty($date_from)) {
            $where[] = "DATE(o.created_at) >= ?";
            $params[] = $date_from;
        }

        if (!empty($date_to)) {
            $where[] = "DATE(o.created_at) <= ?";
            $params[] = $date_to;
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT o.*, l.title as lot_title, u.username, u.email 
                FROM offers o
                JOIN lots l ON o.lot_id = l.id
                JOIN users u ON o.user_id = u.id
                WHERE $where_sql
                ORDER BY o.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $offers = $stmt->fetchAll();
        ?>
        <h3>💰 Предложения по комиссионным лотам</h3>
        <form method="GET" class="filters">
            <input type="hidden" name="tab" value="offers">
            <select name="offer_status">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Все статусы</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Ожидают</option>
                <option value="accepted" <?= $status_filter === 'accepted' ? 'selected' : '' ?>>Принятые</option>
                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Отклонённые</option>
            </select>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>" placeholder="с">
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>" placeholder="по">
            <button type="submit" class="btn btn-primary">Применить</button>
            <a href="?tab=offers" class="btn" style="background:#334155;">Сбросить</a>
        </form>

        <?php if (empty($offers)): ?>
            <div style="text-align: center; padding: 60px;">Пока нет предложений</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Лот</th>
                        <th>Покупатель</th>
                        <th>Предложенная цена</th>
                        <th>Дата</th>
                        <th>Статус</th>
                        <th>Действия</th>
                     </tr>
                </thead>
                <tbody>
                <?php foreach ($offers as $offer): ?>
                     <tr>
                        <td>#<?= $offer['id'] ?></td>
                        <td><?= htmlspecialchars($offer['lot_title'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($offer['username'] ?? '', ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars($offer['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td><b style="color:#fbbf24;"><?= number_format($offer['amount'], 2) ?> ₽</b></td>
                        <td><?= date('d.m.Y', strtotime($offer['created_at'])) ?></td>
                        <td><?= $offer['status'] === 'pending' ? 'Ожидает' : ($offer['status'] === 'accepted' ? 'Принято' : 'Отклонено') ?></td>
                        <td>
                            <?php if ($offer['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                                    <input type="hidden" name="action" value="accept_offer">
                                    <button type="submit" class="btn btn-approve btn-sm">✅ Принять</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="offer_id" value="<?= $offer['id'] ?>">
                                    <input type="hidden" name="action" value="reject_offer">
                                    <button type="submit" class="btn btn-reject btn-sm">❌ Отклонить</button>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                     </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($tab === 'commission'): ?>
        <?php
        // Берём лоты из таблицы torgi
        $stmt = $pdo->query("
    SELECT *
    FROM torgi
    ORDER BY id DESC
");
        $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <h3>🏷️ Комиссионные лоты (torgi)</h3>

        <?php if (empty($lots)): ?>
            <div style="text-align: center; padding: 60px;">Нет лотов</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Лот</th>
                        <th>Тип</th>
                        <th>Цена</th>
                        <th>Регион</th>
                        <th>Дата</th>
                        <th>Статус (как в torgi)</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lots as $lot): ?>
                    <tr>
                        <td>#<?= (int)$lot['id'] ?></td>
                        <td>
                            <b><?= htmlspecialchars($lot['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></b><br>
                            <small>
    <a href="torgi_photos.php?id=<?= (int)$lot['id'] ?>" target="_blank">📷 Фото</a>
</small>

                        </td>
                        <td><?= htmlspecialchars($lot['lottype'] ?? ($lot['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="price-cell">
    <b style="color:#fbbf24">
        <?php echo number_format($lot['price'], 0, '.', ' '); ?>&nbsp;₽
    </b>
</td>

                        <td><?= htmlspecialchars($lot['region'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
    <?php
    $d = $lot['datecreated'] ?? ($lot['date_created'] ?? ($lot['date'] ?? ''));
    echo $d ? date('d.m.Y', strtotime($d)) : '';
    ?>
</td>
                        <td>
    <?php
    $raw = strtolower(trim($lot['status'] ?? ''));
    $label = '—';
    $color = '#64748b';

    switch ($raw) {
        case 'published':
            $label = 'Новый';
            $color = '#3b82f6';
            break;
        case 'open':
            $label = 'Открыт';
            $color = '#22c55e';
            break;
        case 'pending sale':
            $label = 'Сделка';
            $color = '#f59e0b';
            break;
        case 'closed':
            $label = 'Закрыт';
            $color = '#ef4444';
            break;
        default:
            if ($raw !== '') {
                $label = $lot['status'];
            }
    }
    ?>
    <span style="display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;background:<?= $color ?>20;color:<?= $color ?>;">
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </span>
</td>

                        <td>
    <!-- Открыть -->
    <form method="POST" style="display:inline;">
        <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
        <input type="hidden" name="action" value="approve_lot">
        <button type="submit" class="btn btn-approve btn-sm">Открыть</button>
    </form>

    <!-- Закрыть -->
    <form method="POST" style="display:inline;">
        <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
        <input type="hidden" name="action" value="reject_lot">
        <button type="submit" class="btn btn-warning btn-sm">Закрыть</button>
    </form>

    <!-- Редактировать -->
    <?php
$jstitle       = addslashes($lot['title'] ?? '');
$jscategory    = addslashes($lot['lottype'] ?? ($lot['category'] ?? ''));
$jsregion      = addslashes($lot['region'] ?? '');
$jsdescription = addslashes($lot['description'] ?? '');
$jsstatus      = addslashes($lot['status'] ?? '');
$jsdate        = addslashes($lot['datecreated'] ?? ($lot['date_created'] ?? ($lot['date'] ?? '')));
?>
<button
    onclick="return openEditLotModal(
        <?= (int)$lot['id'] ?>,
        '<?= $jstitle ?>',
        '<?= $jscategory ?>',
        '<?= (float)$lot['price'] ?>',
        '<?= $jsregion ?>',
        '<?= $jsdescription ?>',
        '<?= $jsstatus ?>',
        '<?= $jsdate ?>'
    );"
    class="btn btn-warning btn-sm"
    title="Редактировать"
>
    ✎
</button>
    <!-- Фото -->
    <a href="torgi_photos.php?id=<?= (int)$lot['id'] ?>"
       class="btn btn-secondary btn-sm"
       style="text-decoration:none; margin:2px 0; display:inline-flex; align-items:center; justify-content:center;">
        📷 Фото
    </a>

    <!-- Удалить -->
    <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить лот «<?= addslashes($lot['title'] ?? '') ?>»?');">
        <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
        <input type="hidden" name="action" value="delete_lot">
        <button type="submit" class="btn btn-danger btn-sm">🗑️ Уд.</button>
    </form>
</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($tab === 'inspection'): ?>


    <?php elseif ($tab === 'inspection'): ?>
        <?php
        $stmt = $pdo->query("
            SELECT i.*, c.title as lot_title, u.username, u.email 
            FROM inspection_requests i
            JOIN commission_lots c ON i.lot_id = c.id
            LEFT JOIN users u ON i.user_id = u.id
            ORDER BY i.created_at DESC
        ");
        $inspections = $stmt->fetchAll();
        ?>
        <h3>🔍 Заявки на осмотр</h3>
        <?php if (empty($inspections)): ?>
            <div style="text-align: center; padding: 60px;">Нет заявок на осмотр</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Лот</th>
                        <th>Заявитель</th>
                        <th>ИНН</th>
                        <th>Телефон</th>
                        <th>Дата</th>
                        <th>Статус</th>
                     </tr>
                </thead>
                <tbody>
                <?php foreach ($inspections as $req): ?>
                     <tr>
                        <td>#<?= $req['id'] ?></td>
                        <td><?= htmlspecialchars($req['lot_title'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($req['fullname'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($req['inn'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($req['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= date('d.m.Y', strtotime($req['created_at'])) ?></td>
                        <td><?= $req['status'] ?? 'pending' ?></td>
                     </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($tab === 'closed_admit'): ?>
        <?php
        // Фильтр по конкретному лоту, если передан
        $only_lot = isset($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;

        // Только закрытые аукционы
        if ($only_lot > 0) {
            $closedStmt = $pdo->prepare("SELECT id, title, end_time, owner_id, price FROM lots WHERE auction_type = 'closed' AND id = ? ORDER BY id DESC");
            $closedStmt->execute([$only_lot]);
        } else {
            $closedStmt = $pdo->query("SELECT id, title, end_time, owner_id, price FROM lots WHERE auction_type = 'closed' ORDER BY id DESC");
        }
        $closedLots = $closedStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <h3>🔐 Допуск участников к закрытым аукционам</h3>
        <p style="color:#94a3b8;font-size:13px;margin:6px 0 16px;">
            В процессе торгов участникам видна только лучшая цена; данные других участников скрыты.
            Допуск к торгам подтверждается организатором/администратором вручную.
        </p>

        <?php if (empty($closedLots)): ?>
            <div style="text-align:center;padding:60px;color:#64748b;">Нет закрытых аукционов</div>
        <?php else: ?>
            <?php foreach ($closedLots as $clot): ?>
                <?php
                $partsStmt = $pdo->prepare("
                    SELECT cp.*, u.username, u.email
                      FROM closed_participants cp
                      JOIN users u ON u.id = cp.user_id
                     WHERE cp.lot_id = ?
                     ORDER BY cp.created_at DESC
                ");
                $partsStmt->execute([(int)$clot['id']]);
                $parts = $partsStmt->fetchAll(PDO::FETCH_ASSOC);

                $cnt = ['pending'=>0,'approved'=>0,'rejected'=>0];
                foreach ($parts as $p) { $cnt[$p['status']] = ($cnt[$p['status']] ?? 0) + 1; }
                ?>
                <div style="background:#1e293b;border:1px solid #334155;border-radius:12px;padding:16px;margin-bottom:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                        <div>
                            <b style="color:#fff;font-size:15px;"><?= htmlspecialchars($clot['title'] ?? '') ?></b>
                            <span style="color:#64748b;font-size:12px;">· Лот №<?= (int)$clot['id'] ?> · до <?= htmlspecialchars($clot['end_time']) ?></span>
                        </div>
                        <div style="display:flex;gap:8px;font-size:11px;">
                            <span style="background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:8px;">Ожидают: <?= (int)($cnt['pending']??0) ?></span>
                            <span style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:8px;">Допущены: <?= (int)($cnt['approved']??0) ?></span>
                            <span style="background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:8px;">Отклонены: <?= (int)($cnt['rejected']??0) ?></span>
                        </div>
                    </div>
                    <?php if (empty($parts)): ?>
                        <div style="color:#64748b;font-size:13px;padding:8px 0;">Заявок на участие пока нет</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                        <table style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th>Участник</th>
                                    <th>Информация</th>
                                    <th>Подал</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($parts as $p): ?>
                                <tr>
                                    <td>
                                        <b><?= htmlspecialchars($p['username']) ?></b><br>
                                        <small style="color:#94a3b8;"><?= htmlspecialchars($p['email'] ?? '') ?></small>
                                    </td>
                                    <td style="max-width:340px;font-size:12px;color:#cbd5e1;white-space:pre-wrap;">
                                        <?= htmlspecialchars($p['application_text'] ?? '—') ?>
                                    </td>
                                    <td style="font-size:12px;color:#94a3b8;"><?= date('d.m.Y H:i', strtotime($p['created_at'])) ?></td>
                                    <td>
                                        <?php if ($p['status']==='pending'): ?>
                                            <span style="color:#fbbf24;">Ожидает</span>
                                        <?php elseif ($p['status']==='approved'): ?>
                                            <span style="color:#4ade80;">Допущен</span>
                                        <?php else: ?>
                                            <span style="color:#f87171;">Отклонён</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <?php if ($p['status']!=='approved'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="closed_approve_participant">
                                                <input type="hidden" name="participant_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="btn btn-approve btn-sm">✅ Допустить</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($p['status']!=='rejected'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="closed_reject_participant">
                                                <input type="hidden" name="participant_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="btn btn-reject btn-sm">❌ Отклонить</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Модалка редактирования пользователя -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <h3>✏️ Редактировать пользователя</h3>
        <form method="POST">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="hidden" name="action" value="edit">
            
            <label>Email</label>
            <input type="email" name="email" id="edit_email" class="input-field" required>
            
            <label>Статус</label>
            <select name="status" id="edit_status" class="input-field">
                <option value="pending">Ожидает</option>
                <option value="active">Активный</option>
                <option value="blocked">Заблокирован</option>
            </select>
            
            <label>Роль</label>
            <select name="role" id="edit_role" class="input-field">
                <option value="user">Пользователь</option>
                <option value="admin">Администратор</option>
            </select>
            
            <label>Баланс (₽)</label>
            <input type="number" name="balance" id="edit_balance" class="input-field" step="0.01">
            
            <div class="modal-btns">
                <button type="button" onclick="closeEditModal()" class="btn" style="background:#334155;">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка удаления пользователя -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <h3>🗑️ Подтверждение удаления</h3>
        <p id="deleteMessage"></p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="user_id" id="deleteUserId">
            <input type="hidden" name="action" value="delete">
            <div class="modal-btns">
                <button type="button" onclick="closeDeleteModal()" class="btn" style="background:#334155;">Отмена</button>
                <button type="submit" class="btn btn-danger">Удалить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка редактирования комиссионного лота -->
<div id="editLotModal" class="modal">
    <div class="modal-content">
        <h3>✏️ Редактировать комиссионный лот</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="lot_id" id="edit_lot_id">
            <input type="hidden" name="action" value="edit_lot">
            
            <label>Название</label>
            <input type="text" name="title" id="edit_lot_title" class="input-field" required>
            
            <label>Категория</label>
            <input type="text" name="category" id="edit_lot_category" class="input-field" required>
            
            <label>Цена (₽)</label>
            <input type="number" name="price" id="edit_lot_price" class="input-field" step="1000" required>
            
            <label>Регион</label>
            <input type="text" name="region" id="edit_lot_region" class="input-field" required>
            
            <label>Описание</label>
            <textarea name="description" id="edit_lot_description" class="input-field" rows="3"></textarea>
            
            <label>Статус</label>
<input type="text" name="status" id="edit_lot_status" class="input-field">
<label>Дата публикации</label>
<input type="text" name="datecreated" id="edit_lot_date" class="input-field" placeholder="YYYY-MM-DD HH:MM:SS">

            <div class="modal-btns">
                <button type="button" onclick="closeEditLotModal()" class="btn" style="background:#334155;">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
// Пользователи
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    document.querySelectorAll('.userCheckbox').forEach(cb => cb.checked = e.target.checked);
});

function openEditModal(id, email, status, role, balance) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_balance').value = balance;
    document.getElementById('editModal').classList.add('active');
    return false;
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

function confirmDelete(id, username) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteMessage').innerHTML = `Удалить пользователя <b>${username}</b>?`;
    document.getElementById('deleteModal').classList.add('active');
    return false;
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

// Комиссионные лоты
document.getElementById('selectAllCommission')?.addEventListener('change', function(e) {
    document.querySelectorAll('.lotCheckbox').forEach(cb => cb.checked = e.target.checked);
});

function openEditLotModal(id, title, category, price, region, description, status, datecreated) {
    document.getElementById('edit_lot_id').value = id;
    document.getElementById('edit_lot_title').value = title;
    document.getElementById('edit_lot_category').value = category;
    document.getElementById('edit_lot_price').value = price;
    document.getElementById('edit_lot_region').value = region;
    document.getElementById('edit_lot_description').value = description;
    document.getElementById('edit_lot_status').value = status;
    var dateInput = document.getElementById('edit_lot_date');
    if (dateInput) {
        dateInput.value = datecreated || '';
    }
    document.getElementById('editLotModal').classList.add('active');
    return false;
}


function closeEditLotModal() {
    document.getElementById('editLotModal').classList.remove('active');
}

function confirmDeleteLot(id, title) {
    if (confirm(`Удалить лот "${title}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="lot_id" value="${id}">
            <input type="hidden" name="action" value="delete_lot">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    return false;
}

function bulkDeleteLots() {
    const checked = document.querySelectorAll('.lotCheckbox:checked');
    if (checked.length === 0) {
        alert('Выберите лоты для удаления');
        return;
    }
    
    if (confirm(`Удалить ${checked.length} лотов?`)) {
        const form = document.getElementById('bulkLotForm');
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'bulk_delete_lots';
        form.appendChild(actionInput);
        form.submit();
    }
}

// Закрытие модалок по клику на фон
document.getElementById('editModal')?.addEventListener('click', function(e) {
    if (e.target === this) setTimeout(() => closeEditModal(), 100);
});
document.getElementById('deleteModal')?.addEventListener('click', function(e) {
    if (e.target === this) setTimeout(() => closeDeleteModal(), 100);
});
document.getElementById('editLotModal')?.addEventListener('click', function(e) {
    if (e.target === this) setTimeout(() => closeEditLotModal(), 100);
});
</script>
</body>
</html>
