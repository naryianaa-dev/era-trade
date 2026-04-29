<?php
require_once __DIR__ . '/admin_only.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "DEBUG-POINT-A<br>";
session_start();
header('Content-Type: text/html; charset=utf-8');

include 'db.php';



// Принудительная установка кодировки
$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET CHARACTER SET utf8mb4");

echo "DEBUG-POINT-B<br>";

$tab = $_GET['tab'] ?? 'users';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Обработка действий
// === ПРОСТОЙ ОБРАБОТЧИК ДЛЯ ЛОТОВ TОРГИ (КОМИССИОНКА) ===
// === ПРОСТОЙ ОБРАБОТЧИК ДЛЯ ЛОТОВ TОРГИ (КОМИССИОНКА) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['action']) && !empty($_POST['lot_id'])) {
        $lotId = (int)$_POST['lot_id'];
        $action = $_POST['action'];

        if ($lotId > 0 && $action === 'torgi_set_open') {
            $stmt = $pdo->prepare("UPDATE torgi SET status = 'open' WHERE id = ?");
            $stmt->execute([$lotId]);
            $_SESSION['admin_msg'] = '✅ Лот открыт (приём заявок)';
        }

        if ($lotId > 0 && $action === 'torgi_set_closed') {
            $stmt = $pdo->prepare("UPDATE torgi SET status = 'closed' WHERE id = ?");
            $stmt->execute([$lotId]);
            $_SESSION['admin_msg'] = '✅ Лот закрыт (сделка завершена)';
        }

        if ($lotId > 0 && $action === 'torgi_delete') {
            $stmt = $pdo->prepare("SELECT images FROM torgi WHERE id = ?");
            $stmt->execute([$lotId]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($lot && !empty($lot['images'])) {
                $images = json_decode($lot['images'], true);
                if (is_array($images)) {
                    foreach ($images as $img) {
                        if (!empty($img) && file_exists($img)) {
                            @unlink($img);
                        }
                    }
                }
            }

            $stmt = $pdo->prepare("DELETE FROM torgi WHERE id = ?");
            $stmt->execute([$lotId]);
            $_SESSION['admin_msg'] = '🗑️ Лот удалён';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
// === ПРОСТОЙ ОБРАБОТЧИК ДЛЯ ЛОТОВ TОРГИ (КОМИССИОНКА) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['action']) && !empty($_POST['lot_id'])) {
        $lotId = (int)$_POST['lot_id'];
        $action = $_POST['action'];

        if ($lotId > 0 && $action === 'torgi_set_open') {
            $stmt = $pdo->prepare("UPDATE torgi SET status = 'open' WHERE id = ?");
            $stmt->execute([$lotId]);
            $_SESSION['admin_msg'] = '✅ Лот открыт (приём заявок)';
        }

        if ($lotId > 0 && $action === 'torgi_set_closed') {
            $stmt = $pdo->prepare("UPDATE torgi SET status = 'closed' WHERE id = ?");
            $stmt->execute([$lotId]);
            $_SESSION['admin_msg'] = '✅ Лот закрыт (сделка завершена)';
        }

        if ($lotId > 0 && $action === 'torgi_delete') {
            $stmt = $pdo->prepare("SELECT images FROM torgi WHERE id = ?");
            $stmt->execute([$lotId]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}
}
$stmt = $pdo->query("
    SELECT *
    FROM torgi
    ORDER BY date_created DESC
");
$lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

function render_torgi_status_label(string $status): string {
    switch ($status) {
        case 'published':
            return 'Опубликован';
        case 'open':
            return 'Открыт (приём заявок)';
        case 'pending sale':
            return 'В сделке';
        case 'closed':
            return 'Сделка завершена';
        default:
            return $status ?: '—';
    }
}
?>
            if ($lot && !empty($lot['images'])) {
                $images = json_decode($lot['images'], true);
                if (is_array($images)) {
                    foreach ($images as $img) {
                        if ($img && file_exists($img)) {
                            @unlink($img);
                        }
                    }
                }
            }

            $stmt = $pdo->prepare("DELETE FROM torgi WHERE id = ?");
            $stmt->execute([$lotId]);
            $_SESSION['admin_msg'] = '🗑️ Лот удалён';
        }
    }
}
    
    if ($_POST['action'] === 'approve') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['admin_msg'] = '✅ Пользователь активирован';
        
    } elseif ($_POST['action'] === 'reject') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'blocked' WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['admin_msg'] = '❌ Пользователь заблокирован';
        
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
        
    } elseif ($_POST['action'] === 'approve_lot') {
        $lotId = (int)$_POST['lot_id'];
        $stmt = $pdo->prepare("UPDATE commission_lots SET status = 'approved' WHERE id = ?");
        $stmt->execute([$lotId]);
        $_SESSION['admin_msg'] = '✅ Лот одобрен';
        
    } elseif ($_POST['action'] === 'reject_lot') {
        $lotId = (int)$_POST['lot_id'];
        $stmt = $pdo->prepare("UPDATE commission_lots SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$lotId]);
        $_SESSION['admin_msg'] = '❌ Лот отклонен';
        
    } elseif ($_POST['action'] === 'edit_lot') {
        $lotId = (int)$_POST['lot_id'];
        $title = $_POST['title'] ?? '';
        $category = $_POST['category'] ?? '';
        $price = floatval($_POST['price'] ?? 0);
        $region = $_POST['region'] ?? '';
        $description = $_POST['description'] ?? '';
        $status = $_POST['status'] ?? 'pending';
        
        $stmt = $pdo->prepare("UPDATE commission_lots SET title = ?, category = ?, price = ?, region = ?, description = ?, status = ? WHERE id = ?");
        $stmt->execute([$title, $category, $price, $region, $description, $status, $lotId]);
        $_SESSION['admin_msg'] = '✏️ Лот обновлен';
        
    } elseif ($_POST['action'] === 'delete_lot') {
        $lotId = (int)$_POST['lot_id'];
        
        // Удаляем изображения
        $stmt = $pdo->prepare("SELECT image, images_json FROM commission_lots WHERE id = ?");
        $stmt->execute([$lotId]);
        $lot = $stmt->fetch();
        
        if ($lot && !empty($lot['image']) && file_exists($lot['image'])) {
            unlink($lot['image']);
        }
        
        if ($lot && !empty($lot['images_json'])) {
            $images = json_decode($lot['images_json'], true);
            if (is_array($images)) {
                foreach ($images as $img) {
                    if (file_exists($img)) unlink($img);
                }
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM commission_lots WHERE id = ?");
        $stmt->execute([$lotId]);
        $_SESSION['admin_msg'] = '🗑️ Лот удален';
        
    } elseif ($_POST['action'] === 'bulk_delete_lots') {
        $ids = $_POST['lot_ids'] ?? [];
        if (!empty($ids)) {
            foreach ($ids as $id) {
                $stmt = $pdo->prepare("SELECT image, images_json FROM commission_lots WHERE id = ?");
                $stmt->execute([$id]);
                $lot = $stmt->fetch();
                
                if ($lot && !empty($lot['image']) && file_exists($lot['image'])) {
                    unlink($lot['image']);
                }
                if ($lot && !empty($lot['images_json'])) {
                    $images = json_decode($lot['images_json'], true);
                    if (is_array($images)) {
                        foreach ($images as $img) {
                            if (file_exists($img)) unlink($img);
                        }
                    }
                }
            }
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM commission_lots WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $_SESSION['admin_msg'] = '🗑️ Выбранные лоты удалены';
        }
    }

    
    header('Location: admin.php?tab=' . $tab);
    exit;
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
                                    <button onclick="openEditModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>', '<?= $user['status'] ?>', '<?= $user['role'] ?? 'user' ?>', <?= $user['balance'] ?? 0 ?>); return false;" class="btn btn-warning btn-sm">✏️ Ред.</button>
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
    $stmt = $pdo->query("
        SELECT *
        FROM torgi
        ORDER BY date_created DESC
    ");
    $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <h3>🏷️ Комиссионные лоты (torgi)</h3>

    <?php if (empty($lots)): ?>
        <div style="text-align:center; padding:60px;">Нет лотов</div>
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
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lots as $lot): ?>
                <tr>
                    <td>#<?= (int)$lot['id'] ?></td>
                    <td>
                        <b><?= htmlspecialchars($lot['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></b><br>
                        <small><a href="torgiview.php?id=<?= (int)$lot['id'] ?>" target="_blank">👁️ Просмотр</a></small>
                    </td>
                    <td><?= htmlspecialchars($lot['lot_type'] ?? ($lot['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><b style="color:#fbbf24;"><?= number_format((float)$lot['price'], 0, '.', ' ') ?> ₽</b></td>
                    <td><?= htmlspecialchars($lot['region'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= !empty($lot['date_created']) ? date('d.m.Y', strtotime($lot['date_created'])) : '' ?></td>
                    <td><?= htmlspecialchars($lot['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
                            <input type="hidden" name="action" value="torgi_set_open">
                            <button type="submit" class="btn btn-approve btn-sm">Открыть</button>
                        </form>

                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
                            <input type="hidden" name="action" value="torgi_set_closed">
                            <button type="submit" class="btn btn-warning btn-sm">Закрыть</button>
                        </form>

                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить лот «<?= addslashes($lot['title'] ?? '') ?>»?');">
                            <input type="hidden" name="lot_id" value="<?= (int)$lot['id'] ?>">
                            <input type="hidden" name="action" value="torgi_delete">
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
        <form method="POST">
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
            <select name="status" id="edit_lot_status" class="input-field">
                <option value="pending">Ожидает</option>
                <option value="approved">Одобрен</option>
                <option value="rejected">Отклонён</option>
            </select>
            
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

function openEditLotModal(id, title, category, price, region, description, status) {
    document.getElementById('edit_lot_id').value = id;
    document.getElementById('edit_lot_title').value = title;
    document.getElementById('edit_lot_category').value = category;
    document.getElementById('edit_lot_price').value = price;
    document.getElementById('edit_lot_region').value = region;
    document.getElementById('edit_lot_description').value = description;
    document.getElementById('edit_lot_status').value = status;
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
