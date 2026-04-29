<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// ================= МИГРАЦИЯ СТАРЫХ ЛОТОВ DRAFT -> ACTIVE =================
// До правок add_lot.php лоты создавались со статусом 'draft' и не отображались
// в реестре ни под одним фильтром. Этот UPDATE приводит их к 'active', чтобы
// фильтры различали этап жизненного цикла по started_at/end_time.
try {
    $pdo->exec("UPDATE lots SET auction_status = 'active' WHERE auction_status = 'draft'");
} catch (Exception $e) {
    error_log('Draft migration error: ' . $e->getMessage());
}

// ================= ПРИНУДИТЕЛЬНОЕ ЗАВЕРШЕНИЕ ПРОСРОЧЕННЫХ ЛОТОВ =================
try {
    $pdo->exec("
        UPDATE lots 
        SET auction_status = 'finished' 
        WHERE auction_status = 'active' 
          AND (
              (end_time IS NOT NULL AND end_time != '0000-00-00 00:00:00' AND end_time <= NOW())
              OR (max_end_time IS NOT NULL AND max_end_time != '0000-00-00 00:00:00' AND max_end_time <= NOW())
          )
    ");
    
    $pdo->exec("
        UPDATE lots l
        SET l.auction_status = 'failed'
        WHERE l.auction_type = 'scandinavian'
          AND l.auction_status = 'finished'
          AND (SELECT COUNT(*) FROM bids b WHERE b.lot_id = l.id) < 2
    ");
} catch (Exception $e) {
    error_log('Auto finish error: ' . $e->getMessage());
}

// ================= НАЧИСЛЕНИЕ БОНУСОВ ОРГАНИЗАТОРАМ =================
try {
    $pdo->exec("ALTER TABLE lots ADD COLUMN IF NOT EXISTS owner_bonus_paid DATETIME NULL");
} catch (Exception $e) {}

$stmt = $pdo->query("
    SELECT id, owner_id FROM lots 
    WHERE auction_type = 'scandinavian' 
      AND auction_status = 'finished' 
      AND owner_bonus_paid IS NULL
      AND owner_id IS NOT NULL
");
$finished = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($finished as $lot) {
    $lot_id = $lot['id'];
    $owner_id = (int)$lot['owner_id'];
    if ($owner_id <= 0) continue;
    
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(bid_cost), 0) FROM bids WHERE lot_id = ?");
    $stmt2->execute([$lot_id]);
    $revenue = (float)$stmt2->fetchColumn();
    
    if ($revenue > 0) {
        $bonus = round($revenue * 0.15, 2);
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$bonus, $owner_id]);
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            type ENUM('bonus','payment','refund') NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'bonus', ?, NOW())")
            ->execute([$owner_id, $bonus, "Бонус 15% от аукциона №{$lot_id} (выручка: " . number_format($revenue, 2, '.', ' ') . " ₽)"]);
    }
    $pdo->prepare("UPDATE lots SET owner_bonus_paid = NOW() WHERE id = ?")->execute([$lot_id]);
}

// ================= ФИЛЬТРЫ =================
$filter_status = $_GET['status'] ?? 'active';
$filter_type   = $_GET['type']   ?? '';

$where = [];
$params = [];

switch ($filter_status) {
    case 'accepting': $where[] = "l.auction_status = 'active' AND l.started_at IS NULL"; break;
    case 'reviewing': $where[] = "l.auction_status = 'active' AND l.started_at IS NOT NULL AND l.end_time > NOW()"; break;
    case 'results':   $where[] = "l.auction_status IN ('finished','single')"; break;
    case 'failed':    $where[] = "l.auction_status = 'failed'"; break;
    case 'archive':   $where[] = "l.auction_status IN ('finished','failed','cancelled','single')"; break;
    case 'single':    $where[] = "l.auction_status = 'single'"; break;
    default:          $where[] = "l.auction_status = 'active'"; break;
}
if ($filter_type !== '') {
    $where[] = "l.auction_type = ?";
    $params[] = $filter_type;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ================= ЗАГРУЗКА ЛОТОВ =================
$lots = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.username AS organizer_name,
               (SELECT COUNT(*) FROM bids b WHERE b.lot_id = l.id) AS total_bids
        FROM lots l
        LEFT JOIN users u ON u.id = l.owner_id
        $where_sql
        ORDER BY l.id DESC
    ");
    $stmt->execute($params);
    $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lots = [];
    error_log('reestr load error: ' . $e->getMessage());
}

$type_labels = [
    'classic'      => '🔨 Открытый аукцион',
    'scandinavian' => '🔥 Скандинавский',
    'closed'       => '🔒 Закрытый аукцион',
    'descending'   => '📉 На понижение',
    'quotation'    => '📋 Запрос котировок',
    'proposal'     => '📨 Запрос предложений',
];

include 'header.php';
?>
<main style="flex:1;">
<style>
.registry-page{max-width:1100px;margin:20px auto 40px;padding:0 16px}
.registry-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px}
.registry-header h1{margin:0;font-size:22px;font-weight:800;color:#0f172a}
.count-pill{display:inline-flex;align-items:center;justify-content:center;padding:4px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;margin-left:8px}
.registry-new-lot{padding:8px 16px;border-radius:999px;background:#0f172a;color:#fff;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap}
.registry-new-lot:hover{background:#1e293b}
.registry-filters{background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:14px 16px;margin-bottom:18px}
.filter-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px}
.filter-row:last-child{margin-bottom:0}
.filter-label{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-right:4px;white-space:nowrap}
.filter-btn{padding:6px 12px;border-radius:999px;border:1.5px solid #e2e8f0;background:#fff;font-size:12px;font-weight:600;color:#64748b;text-decoration:none;white-space:nowrap;cursor:pointer;transition:.15s}
.filter-btn:hover{border-color:#3b82f6;color:#1d4ed8}
.filter-btn.active{background:#3b82f6;border-color:#3b82f6;color:#fff}
.filter-btn.scand{background:linear-gradient(135deg,#f59e0b,#ef4444);border-color:transparent;color:#fff}
.filter-btn.scand.active{box-shadow:0 4px 14px rgba(248,113,113,.6)}
.filter-divider{width:1px;height:24px;background:#e2e8f0;margin:0 4px}
.registry-table{width:100%;border-collapse:collapse;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.08)}
.registry-table th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e2e8f0;font-weight:700}
.registry-table td{padding:11px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
.registry-table tr:last-child td{border-bottom:none}
.registry-table tbody tr:hover td{background:#f8fafc}
.lot-title{font-size:14px;font-weight:700;color:#0f172a}
.lot-sub{font-size:11px;color:#94a3b8;margin-top:2px}
.type-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.type-classic{background:#eff6ff;color:#2563eb}
.type-scandinavian{background:linear-gradient(135deg,#fef3c7,#fee2e2);color:#b45309}
.type-closed{background:#f5f3ff;color:#7c3aed}
.type-descending{background:#fef2f2;color:#dc2626}
.type-quotation{background:#f0fdf4;color:#16a34a}
.type-proposal{background:#fff7ed;color:#c2410c}
.status-badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
.price-main{font-size:15px;font-weight:800;color:#0f172a}
.price-sub{font-size:11px;color:#94a3b8}
.timer-val{font-family:monospace;font-weight:700;font-size:13px}
.timer-val.ended{color:#94a3b8}
.timer-val.soon{color:#ef4444}
.timer-val.ok{color:#22c55e}
.btn-view{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:8px;background:#3b82f6;color:#fff;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap}
.btn-view:hover{background:#2563eb}
.btn-view.scand{background:linear-gradient(135deg,#f97316,#ef4444)}
.empty-state{margin-top:40px;padding:50px 20px;text-align:center;color:#94a3b8;font-size:14px}
.empty-state-icon{font-size:40px;margin-bottom:8px}
@media(max-width:800px){
    .registry-table thead{display:none}
    .registry-table tr{display:block;border-bottom:1px solid #e5e7eb}
    .registry-table td{display:block;border:none;padding:6px 12px}
}
</style>
<div class="registry-page">
    <div class="registry-header">
        <h1>Реестр торгов <span class="count-pill"><?= count($lots) ?></span></h1>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <a href="add_lot.php" class="registry-new-lot">+ Разместить лот</a>
        <?php endif; ?>
    </div>

    <div class="registry-filters">
        <div class="filter-row">
            <span class="filter-label">Статус</span>
            <?php
            $statuses = [
                'active'    => 'Актуальные',
                'accepting' => 'Приём заявок',
                'reviewing' => 'Рассмотрение',
                'results'   => 'Итоги',
                'failed'    => 'Несостоявшиеся',
                'archive'   => '📁 Архив',
            ];
            foreach ($statuses as $code => $label):
                $active = $filter_status === $code ? 'active' : '';
                $url = '?status='.$code.($filter_type ? '&type='.$filter_type : '');
            ?>
                <a href="<?= $url ?>" class="filter-btn <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
        <div class="filter-row">
            <span class="filter-label">Тип</span>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>"
               class="filter-btn <?= $filter_type==='' ? 'active' : '' ?>">Все</a>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>&type=classic"
               class="filter-btn <?= $filter_type==='classic' ? 'active' : '' ?>">🔨 Открытый</a>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>&type=closed"
               class="filter-btn <?= $filter_type==='closed' ? 'active' : '' ?>">🔒 Закрытый</a>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>&type=descending"
               class="filter-btn <?= $filter_type==='descending' ? 'active' : '' ?>">📉 На понижение</a>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>&type=quotation"
               class="filter-btn <?= $filter_type==='quotation' ? 'active' : '' ?>">📋 Котировки</a>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>&type=proposal"
               class="filter-btn <?= $filter_type==='proposal' ? 'active' : '' ?>">📨 Предложения</a>
            <div class="filter-divider"></div>
            <a href="?status=<?= htmlspecialchars($filter_status) ?>&type=scandinavian"
               class="filter-btn scand <?= $filter_type==='scandinavian' ? 'active' : '' ?>">🔥 Скандинавский</a>
        </div>
    </div>

    <?php if (!$lots): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📋</div>
            Лоты не найдены под выбранные фильтры
        </div>
    <?php else: ?>
        <table class="registry-table">
            <thead>
                <tr>
                    <th>№ / Лот</th>
                    <th>Тип</th>
                    <th>Цена</th>
                    <th>Статус</th>
                    <th>До завершения</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lots as $lot):
                $id    = (int)$lot['id'];
                $title = $lot['title'] ?? ('Лот №'.$id);
                $atype = $lot['auction_type'] ?? 'classic';
                $type_label = $type_labels[$atype] ?? $atype;

                $end_ts      = $lot['end_time'] ? strtotime($lot['end_time']) : 0;
                $max_end_ts  = !empty($lot['max_end_time']) ? strtotime($lot['max_end_time']) : 0;
                $started_ts  = !empty($lot['started_at']) ? strtotime($lot['started_at']) : 0;
                $now_ts      = time();
                
                $is_finished = in_array($lot['auction_status'] ?? '', ['finished', 'single', 'failed', 'cancelled'])
                               || ($end_ts && $end_ts <= $now_ts)
                               || ($max_end_ts && $max_end_ts <= $now_ts);
                
                $is_started = ($started_ts && $started_ts <= $now_ts);
                
                if (!$is_finished) {
                    $diff = PHP_INT_MAX;
                    if ($end_ts > $now_ts) $diff = min($diff, $end_ts - $now_ts);
                    if ($max_end_ts > $now_ts) $diff = min($diff, $max_end_ts - $now_ts);
                    if ($diff == PHP_INT_MAX) $diff = 0;
                    
                    if ($diff > 86400) $tval = floor($diff/86400).' дн.';
                    elseif ($diff > 3600) $tval = floor($diff/3600).' ч '.floor(($diff%3600)/60).' мин';
                    else $tval = floor($diff/60).':'.str_pad($diff%60,2,'0',STR_PAD_LEFT);
                    $tcls = ($diff < 3600) ? 'soon' : 'ok';
                } else {
                    $tval = 'Завершён';
                    $tcls = 'ended';
                }
                
                $total_bids = (int)($lot['total_bids'] ?? 0);
                $st_code = $lot['auction_status'] ?? 'active';
                
                if ($is_finished) {
                    if ($st_code === 'failed') {
                        $display_status = 'Несостоявшийся';
                        $status_color = '#f87171';
                        $status_bg = '#450a0a';
                    } elseif ($st_code === 'cancelled') {
                        $display_status = 'Отменён';
                        $status_color = '#94a3b8';
                        $status_bg = '#1e293b';
                    } elseif ($st_code === 'single') {
                        $display_status = 'Единственный уч.';
                        $status_color = '#f59e0b';
                        $status_bg = '#451a03';
                    } else {
                        $display_status = 'Завершён';
                        $status_color = '#64748b';
                        $status_bg = '#1e293b';
                    }
                } else {
                    if (!$is_started) {
                        $display_status = 'Приём заявок';
                        $status_color = '#f59e0b';
                        $status_bg = '#451a03';
                    } else {
                        $display_status = 'Идут торги';
                        $status_color = '#22c55e';
                        $status_bg = '#14532d';
                    }
                }
                
                $price       = (float)($lot['price'] ?? 0);
                $start_price = (float)($lot['start_price'] ?? 0);

                $is_scand = ($atype === 'scandinavian');
                
                // ----- КНОПКА ДЛЯ НЕАВТОРИЗОВАННЫХ (вызов openAuthModal) -----
                if (empty($_SESSION['user_id'])) {
                    $btn_url = '#';
                    $onclick = 'openAuthModal(); return false;';
                } else {
                    if ($is_scand) {
                        $btn_url = "lot_scandinavian.php?id=$id";
                    } elseif ($atype === 'closed') {
                        $btn_url = "lot_closed.php?id=$id";
                    } elseif ($atype === 'quotation') {
                        $btn_url = "lot_quotation.php?id=$id";
                    } elseif ($atype === 'proposal') {
                        $btn_url = "lot_proposal.php?id=$id";
                    } elseif ($atype === 'descending') {
                        $btn_url = "lot_descending.php?id=$id";
                    } else {
                        $btn_url = "lot_details.php?id=$id";
                    }
                    $onclick = '';
                }
                // ---------------------------------------------------------------
            ?>
                <tr>
                    <td>
                        <div class="lot-title"><?= htmlspecialchars($title) ?></div>
                        <div class="lot-sub">
                            №<?= $id ?>
                            <?php if (!empty($lot['description'])):
                                $short = mb_substr(strip_tags($lot['description']),0,40,'UTF-8');
                            ?>· <?= htmlspecialchars($short) ?>…<?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="type-badge type-<?= htmlspecialchars($atype) ?>">
                            <?= $type_label ?>
                            <?php if ($is_scand): ?>
                                <span title="Уникальный формат ERA ETP" style="cursor:help;">⭐</span>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <div class="price-main"><?= number_format($price,0,'.',' ') ?>&nbsp;₽</div>
                        <?php if ($start_price && $start_price != $price): ?>
                            <div class="price-sub">Старт: <?= number_format($start_price,0,'.',' ') ?>&nbsp;₽</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge" style="background:<?= $status_bg ?>;color:<?= $status_color ?>;">
                            <?= $display_status ?>
                        </span>
                        <?php if (!$is_finished && $end_ts && !empty($lot['total_bids'])): ?>
                            <div class="price-sub" style="margin-top:2px;"><?= (int)$lot['total_bids'] ?> ставок</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="timer-val <?= $tcls ?>"><?= $tval ?></span>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars($btn_url) ?>"
                           class="btn-view <?= $is_scand ? 'scand' : '' ?>"
                           <?= $onclick ? 'onclick="'.$onclick.'"' : '' ?>>
                            <?= $is_scand ? '🔥 Участвовать' : 'Подробнее' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</main>
<?php include 'footer.php'; ?>