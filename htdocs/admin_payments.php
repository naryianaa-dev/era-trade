<?php
require_once __DIR__ . '/admin_only.php';
session_start();
require_once 'db.php';

// Только для админов
if (empty($_SESSION['user_id']) || ($_SESSION['usertype'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

// Создаём таблицу если нет
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    lot_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL,
    tariff VARCHAR(100) NOT NULL,
    comment TEXT NULL,
    file_path VARCHAR(500) NOT NULL,
    user_email VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (lot_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE payment_receipts ADD COLUMN user_email VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}

$msg = '';
$msg_type = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receipt_id'], $_POST['action'])) {
    $receipt_id = (int)$_POST['receipt_id'];
    $action     = $_POST['action'];

    if (!in_array($action, ['confirm', 'reject', 'restore'], true)) {
        $msg = 'Неверное действие'; $msg_type = 'error';
    } else {
        if ($action === 'confirm') {
            $new_status = 'confirmed';
            // отправка письма (код остаётся как был, см. ниже)
        } elseif ($action === 'reject') {
            $new_status = 'rejected';
        } elseif ($action === 'restore') {
            $new_status = 'pending';
        }
        $pdo->prepare("UPDATE payment_receipts SET status = ? WHERE id = ?")->execute([$new_status, $receipt_id]);

        if ($action === 'confirm') {
            // Получаем данные заявки
            $stmt = $pdo->prepare("
                SELECT pr.*, t.title as lot_title, u.email as user_email_profile
                FROM payment_receipts pr
                LEFT JOIN torgi t ON t.id = pr.lot_id
                LEFT JOIN users u ON u.id = pr.user_id
                WHERE pr.id = ?
            ");
            $stmt->execute([$receipt_id]);
            $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

            $send_to = $receipt['user_email'] ?: $receipt['user_email_profile'] ?? '';

            if ($send_to) {
                // Получаем платные PDF для этого лота
                $stmt_pdf = $pdo->prepare("SELECT * FROM lot_files WHERE lot_id = ? AND access_level = 'paid' ORDER BY sort_order, id");
                $stmt_pdf->execute([$receipt['lot_id']]);
                $pdf_files = $stmt_pdf->fetchAll(PDO::FETCH_ASSOC);

                $lot_title  = $receipt['lot_title'] ?? ('Лот №' . $receipt['lot_id']);
                $tariff     = $receipt['tariff'];
                $base_url   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

                $pdf_links = '';
                foreach ($pdf_files as $pdf) {
                    $url = $base_url . '/' . ltrim($pdf['file_path'], '/');
                    $pdf_links .= "- " . $pdf['file_name'] . ": " . $url . "\n";
                }
                if (!$pdf_links) $pdf_links = "(документы будут добавлены администратором)\n";

                $subject = "Оплата подтверждена — доступ к отчёту по лоту «{$lot_title}»";
                $body    = "Здравствуйте!\n\n"
                         . "Ваша оплата по тарифу «{$tariff}» для лота «{$lot_title}» подтверждена.\n\n"
                         . "Ссылки на документы:\n"
                         . $pdf_links . "\n"
                         . "Для просмотра PDF онлайн на сайте необходима регистрация.\n\n"
                         . "С уважением,\nООО «Форсаж» — ERA ETP\n"
                         . $base_url;

                $headers  = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

                $sent = @mail($send_to, $subject, $body, $headers);
                $msg = $sent
                    ? "✅ Оплата подтверждена, письмо отправлено на {$send_to}"
                    : "✅ Оплата подтверждена, но письмо не удалось отправить на {$send_to}";
            } else {
                $msg = '✅ Оплата подтверждена (email не указан — письмо не отправлено)';
            }
            $msg_type = 'success';
        } elseif ($action === 'reject') {
            $msg = '❌ Заявка отклонена';
            $msg_type = 'error';
        } elseif ($action === 'restore') {
            $msg = '🔄 Заявка восстановлена (статус: Ожидает)';
            $msg_type = 'success';
        }
    }
}

// Фильтр по статусу
$filter = $_GET['status'] ?? 'pending';
$allowed_filters = ['pending', 'confirmed', 'rejected', 'all'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'pending';

$where = $filter !== 'all' ? "WHERE pr.status = ?" : "WHERE 1";
$params = $filter !== 'all' ? [$filter] : [];

$stmt = $pdo->prepare("
    SELECT pr.*, t.title as lot_title, u.username, u.email as user_email_profile
    FROM payment_receipts pr
    LEFT JOIN torgi t ON t.id = pr.lot_id
    LEFT JOIN users u ON u.id = pr.user_id
    {$where}
    ORDER BY pr.created_at DESC
");
$stmt->execute($params);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>
<style>
.payments-wrap { max-width: 1100px; margin: 0 auto; padding: 30px 20px; }
.payments-wrap h1 { font-size: 24px; font-weight: 800; margin-bottom: 20px; }
.filter-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.filter-tab { padding: 7px 16px; border-radius: 999px; border: 1px solid #e2e8f0; font-size: 13px; font-weight: 600; text-decoration: none; color: #64748b; background: #fff; }
.filter-tab.active { background: #0f172a; color: #fff; border-color: #0f172a; }
.alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
.alert.success { background: #dcfce7; color: #166534; }
.alert.error { background: #fee2e2; color: #991b1b; }
/* Таблица – обычный вид на десктопе */
.receipts-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.receipts-table th { background: #f8fafc; padding: 10px 14px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid #e2e8f0; }
.receipts-table td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.receipts-table tr:last-child td { border-bottom: none; }
.status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.status-pending { background: #fef3c7; color: #92400e; }
.status-confirmed { background: #dcfce7; color: #166534; }
.status-rejected { background: #fee2e2; color: #991b1b; }
.btn-confirm, .btn-reject, .btn-restore { border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 700; margin-right: 4px; }
.btn-confirm { background: #16a34a; color: #fff; }
.btn-confirm:hover { background: #15803d; }
.btn-reject { background: #dc2626; color: #fff; }
.btn-reject:hover { background: #b91c1c; }
.btn-restore { background: #f59e0b; color: #fff; }
.btn-restore:hover { background: #d97706; }
.file-link { color: #0ea5e9; text-decoration: none; font-size: 12px; }
.file-link:hover { text-decoration: underline; }
.empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
.email-cell { font-size: 12px; color: #64748b; }
.email-cell strong { color: #0f172a; font-size: 13px; }

/* ===== АДАПТИВ ДЛЯ МОБИЛЬНЫХ ===== */
@media (max-width: 768px) {
    .payments-wrap { padding: 20px 12px; }
    /* Таблица превращается в блоки */
    .receipts-table, .receipts-table thead, .receipts-table tbody, .receipts-table th, .receipts-table td, .receipts-table tr { display: block; }
    .receipts-table thead { display: none; }
    .receipts-table tr { margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; }
    .receipts-table td { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid #f1f5f9; gap: 10px; flex-wrap: wrap; }
    .receipts-table td:last-child { border-bottom: none; }
    .receipts-table td:before {
        content: attr(data-label);
        font-weight: 700;
        color: #64748b;
        font-size: 12px;
        text-transform: uppercase;
        flex: 0 0 120px;
    }
    /* Для ячеек, у которых нет data-label, зададим отдельно через style или скрипт – но проще добавить атрибуты прямо в php */
    .btn-confirm, .btn-reject, .btn-restore { margin-top: 4px; }
}
</style>

<main style="flex:1; background:#f8fafc;">
<div class="payments-wrap">
    <h1>💳 Заявки на оплату</h1>

    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="filter-tabs">
        <a href="?status=pending"   class="filter-tab <?= $filter === 'pending'   ? 'active' : '' ?>">⏳ Ожидают (<?= (function() use ($pdo) { return $pdo->query("SELECT COUNT(*) FROM payment_receipts WHERE status='pending'")->fetchColumn(); })() ?>)</a>
        <a href="?status=confirmed" class="filter-tab <?= $filter === 'confirmed' ? 'active' : '' ?>">✅ Подтверждённые</a>
        <a href="?status=rejected"  class="filter-tab <?= $filter === 'rejected'  ? 'active' : '' ?>">❌ Отклонённые</a>
        <a href="?status=all"       class="filter-tab <?= $filter === 'all'       ? 'active' : '' ?>">Все</a>
    </div>

    <?php if (empty($receipts)): ?>
        <div class="empty-state">Нет заявок</div>
    <?php else: ?>
    <table class="receipts-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Лот</th>
                <th>Тариф / Сумма</th>
                <th>Email для отчёта</th>
                <th>Файл</th>
                <th>Дата</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($receipts as $r):
            $email_to_use = $r['user_email'] ?: $r['user_email_profile'] ?? '';
        ?>
            <tr>
                <td data-label="#"><?= $r['id'] ?></td>
                <td data-label="Лот">
                    <a href="torgi_view.php?id=<?= $r['lot_id'] ?>" style="color:#0ea5e9;text-decoration:none;font-weight:700;">
                        <?= htmlspecialchars($r['lot_title'] ?? 'Лот #' . $r['lot_id']) ?>
                    </a><br>
                    <small style="color:#94a3b8;">ID <?= $r['lot_id'] ?></small>
                </td>
                <td data-label="Тариф / Сумма">
                    <strong><?= htmlspecialchars($r['tariff']) ?></strong><br>
                    <span style="color:#0ea5e9;font-weight:700;"><?= number_format($r['amount'], 0, '.', ' ') ?> ₽</span>
                    <?php if ($r['comment']): ?>
                        <br><small style="color:#64748b;"><?= htmlspecialchars($r['comment']) ?></small>
                    <?php endif; ?>
                </td>
                <td data-label="Email" class="email-cell">
                    <?php if ($email_to_use): ?>
                        <strong><?= htmlspecialchars($email_to_use) ?></strong>
                        <?php if ($r['user_email'] && $r['user_email_profile'] && $r['user_email'] !== $r['user_email_profile']): ?>
                            <br><span style="color:#94a3b8;">профиль: <?= htmlspecialchars($r['user_email_profile']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#ef4444;">не указан</span>
                    <?php endif; ?>
                    <?php if ($r['username']): ?>
                        <br><small>@<?= htmlspecialchars($r['username']) ?></small>
                    <?php endif; ?>
                </td>
                <td data-label="Файл">
                    <?php if ($r['file_path']): ?>
                        <a href="<?= htmlspecialchars($r['file_path']) ?>" target="_blank" class="file-link">📎 Открыть</a>
                    <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </td>
                <td data-label="Дата" style="white-space:nowrap; color:#64748b; font-size:12px;">
                    <?= date('d.m.Y H:i', strtotime($r['created_at'])) ?>
                </td>
                <td data-label="Статус">
                    <span class="status-badge status-<?= $r['status'] ?>">
                        <?= ['pending'=>'Ожидает','confirmed'=>'Подтверждено','rejected'=>'Отклонено'][$r['status']] ?? $r['status'] ?>
                    </span>
                </td>
                <td data-label="Действия">
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Подтвердить оплату и отправить отчёт на <?= htmlspecialchars($email_to_use ?: 'email не указан') ?>?');">
                            <input type="hidden" name="receipt_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn-confirm">✅ Подтвердить</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Отклонить заявку?');">
                            <input type="hidden" name="receipt_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn-reject">❌ Отклонить</button>
                        </form>
                    <?php elseif ($r['status'] === 'confirmed'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Отменить подтверждение и перевести заявку в статус «Отклонено»?');">
                            <input type="hidden" name="receipt_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn-reject">❌ Отклонить</button>
                        </form>
                    <?php elseif ($r['status'] === 'rejected'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Восстановить заявку (статус «Ожидает»)?');">
                            <input type="hidden" name="receipt_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="action" value="restore">
                            <button type="submit" class="btn-restore">🔄 Восстановить</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</main>

<?php include 'footer.php'; ?>
