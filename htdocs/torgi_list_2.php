<?php
session_start();
require_once 'db.php';

$user_id   = $_SESSION['user_id'] ?? 0;
$user_type = null;
if ($user_id) {
    $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_type = $stmt->fetchColumn();
}

// Пагинация
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 9;
$offset   = ($page - 1) * $per_page;

// Всего лотов
$total_sql = "SELECT COUNT(*) FROM torgi WHERE status != 'deleted'";
$total = $pdo->query($total_sql)->fetchColumn();
$pages = max(1, ceil($total / $per_page));

// Получаем лоты
$sql = "SELECT id, title, price, region, lot_type, status, images, date_created, dealer_id
        FROM torgi
        WHERE status != 'deleted'
        ORDER BY date_created DESC
        LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$per_page, $offset]);
$lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>
<main style="flex:1; padding:30px 20px; background:#f8fafc;">
    <div style="max-width:1200px; margin:0 auto;">

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
            <h1 style="margin:0; font-size:26px; font-weight:800; color:#0f172a;">🏢 Комиссионная продажа</h1>

            <?php if (in_array($user_type, ['organizer', 'admin'], true)): ?>
                <a href="commission.php"
                   style="display:inline-flex; align-items:center; gap:6px;
                          padding:8px 18px; border-radius:999px;
                          background:#0ea5e9; color:#ffffff;
                          font-size:14px; font-weight:600;
                          text-decoration:none;">
                    ➕ Опубликовать лот
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($lots)): ?>
            <div style="padding:40px; text-align:center; background:#ffffff; border-radius:20px; border:1px solid #e2e8f0;">
                <p style="color:#64748b;">Пока нет комиссионных лотов.</p>
                <?php if (in_array($user_type, ['organizer', 'admin'], true)): ?>
                    <a href="commission.php" style="display:inline-block; margin-top:16px; background:#0ea5e9; color:white; padding:10px 20px; border-radius:30px; text-decoration:none;">➕ Добавить первый лот</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="
                display:grid;
                grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
                gap:24px;
            ">
                <?php foreach ($lots as $row):
                    $thumb = '';
                    if (!empty($row['images'])) {
                        $imgs = json_decode($row['images'], true);
                        if (is_array($imgs) && !empty($imgs[0])) {
                            $thumb = $imgs[0];
                        }
                    }
                    $status_label = $row['status'] ?? 'Прием заявок';
                    $status_color = ($status_label === 'Прием заявок') ? '#16a34a' : '#64748b';
                ?>
                <div style="background:#ffffff; border-radius:20px; border:1px solid #e2e8f0; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                    <a href="torgi_view.php?id=<?= $row['id'] ?>" style="display:block; width:100%; height:200px; background:#e5e7eb; overflow:hidden;">
                        <?php if ($thumb): ?>
                            <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:13px;color:#9ca3af;">Нет фото</div>
                        <?php endif; ?>
                    </a>
                    <div style="padding:16px;">
                        <a href="torgi_view.php?id=<?= $row['id'] ?>" style="font-size:18px;font-weight:700;color:#0f172a;text-decoration:none;display:block;margin-bottom:6px;">
                            <?= htmlspecialchars($row['title']) ?>
                        </a>
                        <div style="font-size:13px;color:#64748b;margin-bottom:8px;">
                            <?= htmlspecialchars($row['lot_type'] ?? 'Без категории') ?> •
                            <?= htmlspecialchars($row['region'] ?? 'Регион не указан') ?>
                        </div>
                        <div style="font-size:24px;font-weight:800;color:#0ea5e9;margin-bottom:8px;">
                            <?= number_format($row['price'], 0, '.', ' ') ?> ₽
                        </div>
                        <div style="font-size:12px;color:#94a3b8;margin-bottom:12px;">
                            👤 Продавец: <?= htmlspecialchars($row['dealer_id'] ? 'Пользователь #'.$row['dealer_id'] : '—') ?>
                        </div>
                        <div style="margin-top:12px;">
                            <a href="torgi_view.php?id=<?= $row['id'] ?>" style="display:inline-block; padding:8px 16px; border-radius:999px; background:#0ea5e9; color:#ffffff; text-decoration:none; font-weight:600;">Подробнее</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <div style="margin-top:32px; display:flex; gap:6px; flex-wrap:wrap; justify-content:center;">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <a href="?page=<?= $p ?>" style="padding:6px 12px; border-radius:999px; border:1px solid #e2e8f0; font-size:13px; text-decoration:none; <?= $p == $page ? 'background:#0ea5e9; color:#fff; border-color:#0ea5e9;' : 'color:#64748b;' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php include 'footer.php'; ?>