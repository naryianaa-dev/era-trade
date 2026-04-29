<?php
session_start();
require_once 'db.php';

$lot_id = (int)($_GET['id'] ?? 0);
if (!$lot_id) {
    header('Location: commission_lots.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT cl.*, u.username as seller_name, u.email as seller_email, u.phone as seller_phone
    FROM commission_lots cl
    LEFT JOIN users u ON cl.user_id = u.id
    WHERE cl.id = ? AND cl.status = 'approved'
");
$stmt->execute([$lot_id]);
$lot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lot) {
    die("Лот не найден или недоступен.");
}

$user_id = $_SESSION['user_id'] ?? 0;
$is_owner = ($user_id && $lot['user_id'] == $user_id);

$user_data = [];
if ($user_id) {
    $stmt = $pdo->prepare("SELECT username, email, phone FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

$msg = '';
$msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_interest') {
        $name = trim($_POST['name'] ?? '');
        $contact_value = trim($_POST['contact_value'] ?? '');
        $contact_method = $_POST['contact_method'] ?? 'email';
        $want_inspection = isset($_POST['want_inspection']) ? 1 : 0;
        $inspection_date = !empty($_POST['inspection_date']) ? $_POST['inspection_date'] : null;
        $message = trim($_POST['message'] ?? '');
        
        if (!$name || !$contact_value) {
            $msg = 'Заполните имя и контактные данные.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO commission_interests 
                (lot_id, user_id, name, contact_value, contact_method, want_inspection, inspection_date, message, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())
            ");
            $stmt->execute([$lot_id, $user_id ?: null, $name, $contact_value, $contact_method, $want_inspection, $inspection_date, $message]);
            $msg = 'Ваша заявка отправлена! Продавец свяжется с вами.';
            $msg_type = 'success';
        }
    } elseif ($action === 'make_offer') {
        $offered_price = (float)($_POST['price'] ?? 0);
        $offer_message = trim($_POST['offer_message'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $contact_value = trim($_POST['contact_value'] ?? '');
        $contact_method = $_POST['contact_method'] ?? 'email';
        
        if (!$name || !$contact_value) {
            $msg = 'Заполните имя и контактные данные.';
            $msg_type = 'error';
        } elseif ($offered_price <= 0) {
            $msg = 'Укажите корректную цену.';
            $msg_type = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO commission_offers (lot_id, user_id, offered_price, message, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$lot_id, $user_id ?: null, $offered_price, $offer_message]);
            $msg = 'Ваше предложение отправлено продавцу!';
            $msg_type = 'success';
        }
    }
    
    if ($msg_type === 'success') {
        header("Location: commission_lot.php?id=$lot_id&msg=" . urlencode($msg));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
    $msg_type = 'success';
}

include 'header.php';
?>

<style>
    .lot-page { max-width: 1000px; margin: 40px auto; background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .lot-image { height: 400px; background-size: cover; background-position: center; background-color: #f1f5f9; }
    .lot-content { padding: 32px; }
    .lot-title { font-size: 28px; font-weight: 800; color: #0f172a; margin-bottom: 12px; }
    .lot-price { font-size: 32px; font-weight: 800; color: #3b82f6; margin-bottom: 20px; }
    .lot-details { display: flex; gap: 24px; margin-bottom: 20px; color: #64748b; font-size: 14px; flex-wrap: wrap; }
    .lot-description { background: #f8fafc; padding: 20px; border-radius: 16px; margin: 20px 0; line-height: 1.6; }
    .seller-info { background: #f1f5f9; padding: 16px; border-radius: 16px; margin: 20px 0; }
    .tabs { display: flex; gap: 8px; border-bottom: 1px solid #e2e8f0; margin-bottom: 24px; }
    .tab-btn { padding: 12px 24px; background: none; border: none; font-weight: 600; color: #64748b; cursor: pointer; font-size: 14px; }
    .tab-btn.active { color: #3b82f6; border-bottom: 2px solid #3b82f6; }
    .tab-pane { display: none; padding: 20px 0; }
    .tab-pane.active { display: block; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
    .form-control { width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
    .form-control:focus { outline: none; border-color: #3b82f6; }
    .btn-submit { background: #3b82f6; color: white; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 700; cursor: pointer; }
    .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #dcfce7; color: #166534; }
    .alert-error { background: #fee2e2; color: #991b1b; }
</style>

<main style="flex:1;">
    <div class="lot-page">
        <div class="lot-image" style="background-image: url('<?= !empty($lot['image']) ? htmlspecialchars($lot['image']) : 'https://via.placeholder.com/1000x400?text=Изображение+отсутствует' ?>')"></div>
        <div class="lot-content">
            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?>"><?= $msg ?></div>
            <?php endif; ?>
            
            <div class="lot-title"><?= htmlspecialchars($lot['title']) ?></div>
            <div class="lot-price"><?= number_format($lot['price'], 0, '.', ' ') ?> ₽</div>
            <div class="lot-details">
                <span>📁 <?= htmlspecialchars($lot['category'] ?? 'Без категории') ?></span>
                <span>📍 <?= htmlspecialchars($lot['region'] ?? 'Регион не указан') ?></span>
                <span>📅 Добавлен: <?= date('d.m.Y', strtotime($lot['created_at'])) ?></span>
            </div>
            
            <?php if (!empty($lot['description'])): ?>
                <div class="lot-description">
                    <?= nl2br(htmlspecialchars($lot['description'])) ?>
                </div>
            <?php endif; ?>
            
            <div class="seller-info">
                <strong>Продавец:</strong> <?= htmlspecialchars($lot['seller_name'] ?? '—') ?><br>
                <?php if (!empty($lot['seller_email'])): ?>
                    <strong>Email:</strong> <?= htmlspecialchars($lot['seller_email']) ?><br>
                <?php endif; ?>
                <?php if (!empty($lot['seller_phone'])): ?>
                    <strong>Телефон:</strong> <?= htmlspecialchars($lot['seller_phone']) ?>
                <?php endif; ?>
            </div>
            
            <?php if (!$is_owner): ?>
                <div class="tabs">
                    <button class="tab-btn active" data-tab="interest">✉️ Запрос / Осмотр</button>
                    <button class="tab-btn" data-tab="offer">💰 Предложить цену</button>
                </div>
                
                <div id="tab-interest" class="tab-pane active">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_interest">
                        <div class="form-group">
                            <label>Ваше имя *</label>
                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($user_data['username'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Способ связи *</label>
                            <select name="contact_method" class="form-control">
                                <option value="email">Email</option>
                                <option value="phone">Телефон</option>
                                <option value="telegram">Telegram</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Контактные данные *</label>
                            <input type="text" name="contact_value" class="form-control" required placeholder="email или телефон" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="want_inspection" value="1"> Хочу осмотреть товар
                            </label>
                        </div>
                        <div class="form-group" id="inspection_date_group" style="display:none;">
                            <label>Желаемая дата осмотра</label>
                            <input type="date" name="inspection_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Сообщение или вопрос</label>
                            <textarea name="message" class="form-control" rows="3" placeholder="Напишите ваш вопрос..."></textarea>
                        </div>
                        <button type="submit" class="btn-submit">Отправить запрос</button>
                    </form>
                </div>
                
                <div id="tab-offer" class="tab-pane">
                    <form method="POST">
                        <input type="hidden" name="action" value="make_offer">
                        <div class="form-group">
                            <label>Ваше имя *</label>
                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($user_data['username'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Способ связи *</label>
                            <select name="contact_method" class="form-control">
                                <option value="email">Email</option>
                                <option value="phone">Телефон</option>
                                <option value="telegram">Telegram</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Контактные данные *</label>
                            <input type="text" name="contact_value" class="form-control" required placeholder="email или телефон" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Ваше предложение (₽) *</label>
                            <input type="number" name="price" class="form-control" required min="1" step="100" placeholder="Введите сумму">
                            <small style="color:#64748b;">Цена продавца: <?= number_format($lot['price'], 0, '.', ' ') ?> ₽</small>
                        </div>
                        <div class="form-group">
                            <label>Комментарий к предложению</label>
                            <textarea name="offer_message" class="form-control" rows="3" placeholder="Обоснуйте ваше предложение..."></textarea>
                        </div>
                        <button type="submit" class="btn-submit">Отправить предложение</button>
                    </form>
                </div>
                
                <script>
                    document.querySelectorAll('.tab-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const tab = btn.dataset.tab;
                            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');
                            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
                            document.getElementById(`tab-${tab}`).classList.add('active');
                        });
                    });
                    document.querySelector('input[name="want_inspection"]').addEventListener('change', function() {
                        document.getElementById('inspection_date_group').style.display = this.checked ? 'block' : 'none';
                    });
                </script>
            <?php elseif ($is_owner): ?>
                <div class="alert alert-success">
                    Вы являетесь продавцом этого лота. 
                    <a href="commission_offers.php?lot_id=<?= $lot_id ?>" style="color:#3b82f6;">Просмотреть поступившие предложения</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>