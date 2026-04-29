<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
require_once 'db_schema_extra.php';

$filter_type = $_GET['type'] ?? '';
$filter_region = $_GET['region'] ?? '';
$filter_status = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($filter_type !== '') {
    $where[] = "lot_type = ?";
    $params[] = $filter_type;
}
if ($filter_region !== '') {
    $where[] = "region = ?";
    $params[] = $filter_region;
}
if ($filter_status !== '') {
    $where[] = "status = ?";
    $params[] = $filter_status;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT id, title, price, region, lot_type, description, images, date_created, status
    FROM torgi
    $where_sql
    ORDER BY id DESC
");
$stmt->execute($params);
$lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Preload current user's favorites in one query so the stars render filled. */
$_user_id = (int)($_SESSION['user_id'] ?? 0);
$_fav_ids = array_map(fn($l) => (int)$l['id'], $lots);
require_once 'favorites_widget.php';
$_favs = favorites_lookup($pdo, $_user_id, 'torgi', $_fav_ids);

$types = $pdo->query("SELECT DISTINCT lot_type FROM torgi WHERE lot_type IS NOT NULL AND lot_type != ''")->fetchAll(PDO::FETCH_COLUMN);
$regions = $pdo->query("SELECT DISTINCT region FROM torgi WHERE region IS NOT NULL AND region != ''")->fetchAll(PDO::FETCH_COLUMN);

include 'header.php';
favorites_render_assets($lang ?? 'ru');
?>
<style>
.torgi-list-wrap {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}
.filters {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    flex-wrap: wrap;
    background: #f8fafc;
    padding: 15px;
    border-radius: 12px;
}
.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.filter-group label {
    font-weight: 600;
    font-size: 14px;
}
.filter-group select, .filter-group input {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
}
.filter-group button {
    background: #0ea5e9;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
}
.filter-group .reset {
    background: #64748b;
}
.lots-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 24px;
}
.lot-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
    color: inherit;
    text-decoration: none;
    display: block;
    cursor: pointer;
}
.lot-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1);
}
.lot-image {
    height: 200px;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.lot-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.lot-image .no-image {
    color: #94a3b8;
    font-size: 14px;
}
.lot-info {
    padding: 16px;
}
.lot-title {
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 8px;
    color: #0f172a;
}
.lot-price {
    font-size: 22px;
    font-weight: 900;
    color: #0ea5e9;
    margin-bottom: 8px;
}
.lot-meta {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 12px;
}
.lot-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 12px;
}
.status-open {
    background: #d1fae5;
    color: #065f46;
}
.status-closed {
    background: #fee2e2;
    color: #991b1b;
}
.status-other {
    background: #e2e8f0;
    color: #475569;
}
.btn-details {
    display: inline-block;
    background: #0ea5e9;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}
.btn-details:hover {
    background: #0284c7;
}
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.page-header h1 { margin: 0; }
.btn-add-lot {
    padding: 9px 18px;
    border-radius: 999px;
    background: #0f172a;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}
.btn-add-lot:hover { background: #1e293b; }
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}
@media (max-width: 640px) {
    .filters {
        flex-direction: column;
    }
    .lots-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<main class="torgi-list-wrap">
    <div class="page-header">
        <h1><?= $lang === 'en' ? 'Commission sales' : 'Комиссионная продажа' ?></h1>
        <a href="commission.php" class="btn-add-lot"><?= $lang === 'en' ? '+ List a lot' : '+ Разместить лот' ?></a>
    </div>
    
    <form method="GET" class="filters">
        <div class="filter-group">
            <label><?= $lang === 'en' ? 'Type:' : 'Тип:' ?></label>
            <select name="type">
                <option value=""><?= $lang === 'en' ? 'All' : 'Все' ?></option>
                <?php foreach ($types as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filter_type === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><?= $lang === 'en' ? 'Region:' : 'Регион:' ?></label>
            <select name="region">
                <option value=""><?= $lang === 'en' ? 'All' : 'Все' ?></option>
                <?php foreach ($regions as $r): ?>
                <option value="<?= htmlspecialchars($r) ?>" <?= $filter_region === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><?= $lang === 'en' ? 'Status:' : 'Статус:' ?></label>
            <select name="status">
                <option value=""><?= $lang === 'en' ? 'All' : 'Все' ?></option>
                <option value="open" <?= $filter_status === 'open' ? 'selected' : '' ?>><?= $lang === 'en' ? 'Open for offers' : 'Открыт для предложений' ?></option>
                <option value="closed" <?= $filter_status === 'closed' ? 'selected' : '' ?>><?= $lang === 'en' ? 'Deal closed' : 'Сделка завершена' ?></option>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit"><?= $lang === 'en' ? 'Apply' : 'Применить' ?></button>
            <a href="torgi_list.php" class="reset" style="background:#64748b; color:white; padding:8px 16px; border-radius:8px; text-decoration:none;"><?= $lang === 'en' ? 'Reset' : 'Сбросить' ?></a>
        </div>
    </form>
    
    <?php if (empty($lots)): ?>
        <div class="empty-state">
            <p><?= $lang === 'en' ? 'No lots found' : 'Лоты не найдены' ?></p>
        </div>
    <?php else: ?>
        <div class="lots-grid">
            <?php foreach ($lots as $lot):
                $first_image = '';
                $images = json_decode($lot['images'] ?? '', true);
                if (is_array($images) && !empty($images)) {
                    $first_image = htmlspecialchars($images[0]);
                }
                $status_class = match($lot['status']) {
                    'open'         => 'status-open',
                    'closed', 'pending sale' => 'status-closed',
                    default        => 'status-other',
                };
                if ($lang === 'en') {
                    $status_text = match($lot['status']) {
                        'open'         => 'Open for offers',
                        'closed'       => 'Deal closed',
                        'pending sale' => 'Pending sale',
                        'published'    => 'Published',
                        default        => $lot['status'] ?: '—',
                    };
                } else {
                    $status_text = match($lot['status']) {
                        'open'         => 'Открыт для предложений',
                        'closed'       => 'Сделка завершена',
                        'pending sale' => 'В сделке',
                        'published'    => 'Опубликован',
                        default        => $lot['status'] ?: '—',
                    };
                }
                $view_url = 'torgi_view.php?id=' . (int)$lot['id'];
            ?>
            <div class="lot-card-wrap" style="position:relative;">
                <?= favorites_render_star('torgi', (int)$lot['id'], !empty($_favs[(int)$lot['id']]), $lang) ?>
                <a href="<?= htmlspecialchars($view_url) ?>" class="lot-card">
                <div class="lot-image">
                    <?php if ($first_image): ?>
                        <img src="<?= $first_image ?>" alt="<?= htmlspecialchars($lot['title']) ?>">
                    <?php else: ?>
                        <div class="no-image"><?= $lang === 'en' ? 'No photo' : 'Нет фото' ?></div>
                    <?php endif; ?>
                </div>
                <div class="lot-info">
                    <div class="lot-title"><?= htmlspecialchars($lot['title']) ?></div>
                    <div class="lot-price"><?= number_format($lot['price'], 0, '.', ' ') ?> ₽</div>
                    <div class="lot-meta">
                        <?= htmlspecialchars($lot['region']) ?> • <?= htmlspecialchars($lot['lot_type']) ?><br>
                        <?= $lang === 'en' ? 'Created: ' : 'Создан: ' ?><?= date('d.m.Y', strtotime($lot['date_created'])) ?>
                    </div>
                    <div class="lot-status <?= $status_class ?>"><?= $status_text ?></div>
                    <span class="btn-details"><?= $lang === 'en' ? 'View details' : 'Подробнее' ?></span>
                </div>
            </a>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php include 'footer.php'; ?>