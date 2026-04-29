<?php
session_start();
require_once 'db.php';
include 'header.php';

// Берём все лоты с типом "commission"
$sql = "SELECT id, title, price, created_at 
        FROM lots 
        WHERE auction_type = 'commission' 
          AND auction_status = 'published'
        ORDER BY created_at DESC";

$stmt = $pdo->query($sql);
$lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<main style="flex: 1; padding: 40px 20px;">
    <div style="max-width: 1000px; margin: 0 auto;">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h1 style="font-size: 26px; font-weight: 800; color: #0f172a; margin: 0;">
                Комиссионная продажа
            </h1>
            <a href="create_commission_lot.php" 
               style="padding: 10px 18px; border-radius: 999px; background:#0088cc; color:#fff; text-decoration:none; font-weight:700; font-size:14px;">
                + Выставить лот
            </a>
        </div>

        <?php if (empty($lots)): ?>
            <div class="alert error" style="background:#f1f5f9; color:#475569;">
                Пока нет ни одного комиссионного лота.
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px;">
                <?php foreach ($lots as $lot): ?>
                    <a href="commissionlot.php?id=<?= (int)$lot['id'] ?>" 
                       style="display:block; padding:16px; border-radius:18px; background:#ffffff; text-decoration:none; color:#0f172a; box-shadow:0 8px 20px rgba(15,23,42,0.16);">
                        <div style="font-weight:700; margin-bottom:8px;"><?= htmlspecialchars($lot['title']) ?></div>
                        <div style="font-size:18px; font-weight:800; color:#0088cc; margin-bottom:4px;">
                            <?= number_format($lot['price'], 0, '.', ' ') ?> ₽
                        </div>
                        <div style="font-size:12px; color:#64748b;">
                            <?= date('d.m.Y', strtotime($lot['created_at'])) ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'footer.php'; ?>
