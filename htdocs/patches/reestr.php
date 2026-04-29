<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db.php';
date_default_timezone_set('Europe/Moscow');

// ── НОВОЕ: Автоматическое обновление статусов лотов ─────────────────
try {
    // Завершаем лоты, время которых истекло
    $update_sql = "UPDATE lots 
                   SET auction_status = 'finished' 
                   WHERE auction_status = 'active' 
                   AND end_time IS NOT NULL 
                   AND end_time <= NOW()
                   AND id NOT IN (
                       SELECT DISTINCT lot_id FROM bids 
                       WHERE status = 'winning'
                   )";
    $pdo->exec($update_sql);
    
    // Помечаем как 'single' лоты, где была единственная ставка
    $single_sql = "UPDATE lots 
                   SET auction_status = 'single' 
                   WHERE auction_status = 'active' 
                   AND end_time IS NOT NULL 
                   AND end_time <= NOW()
                   AND id IN (
                       SELECT lot_id FROM (
                           SELECT lot_id, COUNT(*) as bid_count 
                           FROM bids 
                           WHERE status = 'winning' 
                           GROUP BY lot_id 
                           HAVING bid_count = 1
                       ) AS sub
                   )";
    $pdo->exec($single_sql);
} catch (Exception $e) {
    // Логирование ошибки, но не блокируем страницу
    error_log("Auto-status update error: " . $e->getMessage());
}

// ────────────────────────────────────────────────────────────────────

// ── Фильтры ───────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'active';
$filter_type   = $_GET['type']   ?? '';

$where   = [];
$params  = [];

// По умолчанию — все кроме архива
if ($filter_status === 'active') {
    $where[] = "l.auction_status != 'finished' AND (l.end_time IS NULL OR l.end_time > NOW())";
} elseif ($filter_status === 'archive') {
    $where[] = "(l.auction_status = 'finished' OR l.auction_status = 'failed' OR l.auction_status = 'cancelled' OR (l.end_time IS NOT NULL AND l.end_time <= NOW()))";
} elseif ($filter_status === 'accepting') {
    $where[] = "l.auction_status = 'active' AND l.started_at IS NULL";
} elseif ($filter_status === 'reviewing') {
    $where[] = "l.auction_status = 'active' AND l.started_at IS NOT NULL AND l.end_time > NOW()";
} elseif ($filter_status === 'results') {
    $where[] = "l.auction_status IN ('finished','single')";
} elseif ($filter_status === 'single') {
    $where[] = "l.auction_status = 'single'";
} elseif ($filter_status === 'failed') {
    $where[] = "l.auction_status = 'failed'";
}

if ($filter_type) {
    $where[] = "l.auction_type = ?";
    $params[] = $filter_type;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $stmt = $pdo->prepare(
        "SELECT l.*, u.username AS organizer_name
         FROM lots l
         LEFT JOIN users u ON u.id = l.owner_id
         {$where_sql}
         ORDER BY l.id DESC"
    );
    $stmt->execute($params);
    $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lots = [];
}

$type_labels = [
    'classic'       => '🔨 Открытый аукцион',
    'scandinavian'  => '🔥 Скандинавский',
    'closed'        => '🔒 Закрытый аукцион',
    'descending'    => '📉 На понижение',
    'quotation'     => '📋 Запрос котировок',
];

$status_labels = [
    'active'    => ['Идут торги',        '#22c55e', '#14532d'],
    'finished'  => ['Завершён',          '#64748b', '#1e293b'],
    'single'    => ['Единственный уч.',  '#f59e0b', '#451a03'],
    'failed'    => ['Несостоявшийся',    '#f87171', '#450a0a'],
    'cancelled' => ['Отменён',           '#94a3b8', '#1e293b'],
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Реестр торгов — ERA ETP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: sans-serif; background: #f1f5f9; margin: 0; padding: 0; }

        .top-bar {
            background: #fff; border-bottom: 1px solid #e2e8f0;
            padding: 12px 24px; display: flex;
            justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 10;
        }
        .top-bar .logo { font-weight: 900; font-size: 18px; color: #1e293b; text-decoration: none; }
        .top-bar .user-info { font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 12px; }
        .top-bar .user-info b { color: #1e293b; }
        .top-bar a { color: #3b82f6; text-decoration: none; font-size: 13px; }

        .page { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .page-header h1 { margin: 0; font-size: 22px; color: #1e293b; }

        /* Фильтры */
        .filters { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 20px; margin-bottom: 20px; }
        .filter-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .filter-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-right: 4px; white-space: nowrap; }
        .filter-btn {
            padding: 7px 14px; border: 1.5px solid #e2e8f0; border-radius: 20px;
            background: #fff; color: #64748b; font-size: 13px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: all 0.15s; white-space: nowrap;
        }
        .filter-btn:hover { border-color: #3b82f6; color: #3b82f6; }
        .filter-btn.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }
        .filter-btn.scand { background: linear-gradient(135deg, #f59e0b, #ef4444); border-color: transparent; color: #fff; }
        .filter-btn.scand.active { box-shadow: 0 4px 12px rgba(245,158,11,0.4); }

        .filter-divider { width: 1px; height: 28px; background: #e2e8f0; margin: 0 4px; }

        /* Таблица */
        .registry-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.07); }
        .registry-table th {
            background: #f8fafc; padding: 11px 14px; text-align: left;
            font-size: 11px; color: #64748b; text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 700; border-bottom: 1px solid #e2e8f0;
        }
        .registry-table td { padding: 13px 14px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
        .registry-table tr:last-child td { border-bottom: none; }
        .registry-table tr:hover td { background: #f8fafc; }

        .lot-title { font-weight: 700; color: #1e293b; font-size: 14px; }
        .lot-num   { font-size: 11px; color: #94a3b8; margin-top: 2px; }

        .type-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
            white-space: nowrap;
        }
        .type-classic      { background: #eff6ff; color: #2563eb; }
        .type-scandinavian { background: linear-gradient(135deg, #fef3c7, #fee2e2); color: #b45309; }
        .type-closed       { background: #f5f3ff; color: #7c3aed; }
        .type-descending   { background: #fef2f2; color: #dc2626; }
        .type-quotation    { background: #f0fdf4; color: #16a34a; }

        .status-badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }

        .price-val { font-weight: 800; font-size: 15px; color: #1e293b; }
        .price-start { font-size: 11px; color: #94a3b8; }

        .timer-val { font-family: monospace; font-weight: bold; font-size: 13px; }
        .timer-val.ended { color: #94a3b8; }
        .timer-val.soon  { color: #ef4444; }
        .timer-val.ok    { color: #22c55e; }

        .btn-view {
            padding: 7px 16px; background: #3b82f6; color: #fff;
            border-radius: 8px; text-decoration: none; font-weight: 700;
            font-size: 12px; white-space: nowrap; transition: background 0.15s;
        }
        .btn-view:hover { background: #2563eb; }
        .btn-view.scand { background: linear-gradient(135deg, #f59e0b, #ef4444); }

        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }

        .count-badge { background: #eff6ff; color: #3b82f6; border-radius: 20px; padding: 2px 10px; font-size: 12px; font-weight: bold; }

        @media (max-width: 768px) {
            .registry-table thead { display: none; }
            .registry-table td { display: block; padding: 6px 14px; border: none; }
            .registry-table tr { border-bottom: 1px solid #e2e8f0; display: block; padding: 10px 0; }
        }
    </style>
</head>
<body>

<!-- Топ-бар -->
<div class="top-bar">
    <a href="index.php" class="logo">ERA ETP</a>
    <div class="user-info">
        <?php if (!empty($_SESSION['user_id'])): ?>
            Вы вошли как <b><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></b>
            &nbsp;·&nbsp;
            <a href="profile.php">Кабинет</a>
            &nbsp;·&nbsp;
            <a href="logout.php">Выйти</a>
        <?php else: ?>
            <a href="index.php" onclick="openAuth && openAuth('login'); return false;">Войти</a>
        <?php endif; ?>
    </div>
</div>

<div class="page">
    <div class="page-header">
        <h1>Реестр торгов <span class="count-badge"><?= count($lots) ?></span></h1>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="create_lot.php" style="background:#1e293b;color:#fff;padding:9px 18px;border-radius:10px;text-decoration:none;font-weight:700;font-size:13px;">
            + Разместить лот
        </a>
        <?php endif; ?>
    </div>

    <!-- Фильтры -->
    <div class="filters">
        <div class="filter-row" style="margin-bottom:10px;">
            <span class="filter-label">Статус:</span>
            <?php
            $statuses = [
                'active'    => 'Актуальные',
                'accepting' => 'Приём заявок',
                'reviewing' => 'Рассмотрение',
                'results'   => 'Итоги',
                'failed'    => 'Несостоявшиеся',
                'archive'   => '📁 Архив',
            ];
            foreach ($statuses as $s => $label):
                $active = $filter_status === $s ? 'active' : '';
                $url = '?status=' . $s . ($filter_type ? '&type='.$filter_type : '');
            ?>
            <a href="<?= $url ?>" class="filter-btn <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
        <div class="filter-row">
            <span class="filter-label">Тип:</span>
            <a href="?status=<?= $filter_status ?>" class="filter-btn <?= !$filter_type ? 'active' : '' ?>">Все</a>
            <a href="?status=<?= $filter_status ?>&type=classic"      class="filter-btn <?= $filter_type==='classic' ? 'active' : '' ?>">🔨 Открытый</a>
            <a href="?status=<?= $filter_status ?>&type=closed"       class="filter-btn <?= $filter_type==='closed' ? 'active' : '' ?>">🔒 Закрытый</a>
            <a href="?status=<?= $filter_status ?>&type=descending"   class="filter-btn <?= $filter_type==='descending' ? 'active' : '' ?>">📉 На понижение</a>
            <a href="?status=<?= $filter_status ?>&type=quotation"    class="filter-btn <?= $filter_type==='quotation' ? 'active' : '' ?>">📋 Котировки</a>
            <div class="filter-divider"></div>
            <a href="?status=<?= $filter_status ?>&type=scandinavian" class="filter-btn scand <?= $filter_type==='scandinavian' ? 'active' : '' ?>">🔥 Скандинавский</a>
        </div>
    </div>

    <!-- Таблица -->
    <?php if (empty($lots)): ?>
    <div class="empty-state">
        <div class="icon">📋</div>
        <div>Лотов не найдено</div>
    </div>
    <?php else: ?>
    <table class="registry-table">
        <thead>
            <tr>
                <th>№ / Лот</th>
                <th>Тип торгов</th>
                <th>Начальная / Текущая цена</th>
                <th>Статус</th>
                <th>До завершения</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lots as $lot):
            $end_ts   = $lot['end_time'] ? strtotime($lot['end_time']) : 0;
            $diff     = $end_ts ? $end_ts - time() : 0;
            $is_over  = $end_ts && $diff <= 0;
            $is_scand = $lot['auction_type'] === 'scandinavian';

            // Timer class
            if ($is_over || !$end_ts)        $tcls = 'ended';
            elseif ($diff < 3600)            $tcls = 'soon';
            else                             $tcls = 'ok';

            // Timer text
            if ($is_over)           $tval = 'Завершён';
            elseif (!$end_ts)       $tval = '—';
            elseif ($diff > 86400)  $tval = floor($diff/86400) . ' дн.';
            elseif ($diff > 3600)   $tval = floor($diff/3600) . ' ч. ' . floor(($diff%3600)/60) . ' мин.';
            else                    $tval = floor($diff/60) . ':' . str_pad($diff%60,2,'0',STR_PAD_LEFT);

            $st    = $lot['auction_status'] ?? 'active';
            $stl   = $status_labels[$st] ?? ['Активен','#22c55e','#14532d'];
            $atype = $lot['auction_type'] ?? 'classic';
        ?>
        <tr>
            <td>
                <div class="lot-title"><?= htmlspecialchars($lot['title']) ?></div>
                <div class="lot-num"><?= $lot['id'] ?><?= !empty($lot['description']) ? ' · ' . mb_substr(strip_tags($lot['description']),0,40) . '…' : '' ?></div>
            </td>
            <td>
                <span class="type-badge type-<?= $atype ?>">
                    <?= $type_labels[$atype] ?? $atype ?>
                    <?php if ($is_scand): ?>
                    <span title="Уникальный формат ERA ETP" style="cursor:help;">⭐</span>
                    <?php endif; ?>
                </span>
            </td>
            <td>
                <div class="price-val"><?= number_format((float)$lot['price'], 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
                <?php if (!empty($lot['start_price']) && $lot['start_price'] != $lot['price']): ?>
                <div class="price-start">Старт: <?= number_format((float)$lot['start_price'], 0, '.', "\u{00A0}") ?>&nbsp;₽</div>
                <?php endif; ?>
            </td>
            <td>
                <span class="status-badge" style="background:<?= $stl[2] ?>;color:<?= $stl[1] ?>;">
                    <?= $stl[0] ?>
                </span>
                <?php if (!$is_over && $end_ts && $lot['total_bids'] > 0): ?>
                <div style="font-size:11px;color:#64748b;margin-top:2px;"><?= (int)$lot['total_bids'] ?> ставок</div>
                <?php endif; ?>
            </td>
            <td>
                <span class="timer-val <?= $tcls ?>"><?= $tval ?></span>
            </td>
            <td>
                <?php
                $url = $is_scand
                    ? "lot_scandinavian.php?id={$lot['id']}"
                    : "lot_details.php?id={$lot['id']}";
                ?>
                <a href="<?= $url ?>" class="btn-view <?= $is_scand ? 'scand' : '' ?>">
                    <?= $is_scand ? '🔥 Участвовать' : 'Подробнее' ?>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</body>
</html>
