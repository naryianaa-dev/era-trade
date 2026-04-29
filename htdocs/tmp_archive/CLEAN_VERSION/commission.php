<?php 
include 'header.php'; 
require_once 'db.php';

$message = "";

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_logged'])) {
    $title = trim($_POST['title']);
    $region = trim($_POST['region']);
    $price = floatval($_POST['price']);
    $category = $_POST['category'];

    if (!empty($title) && $price > 0) {
        try {
            // Найди строку $sql = "INSERT INTO lots... и замени её на:
$sql = "INSERT INTO lots (title, region, price, category, status, end_date, user_id) 
        VALUES (?, ?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 30 DAY), ?)";
$stmt = $pdo->prepare($sql);
$stmt->execute([$title, $region, $price, $category, $_SESSION['user_id']]);
            $message = "<div class='alert success'>Объявление успешно опубликовано!</div>";
        } catch (PDOException $e) {
            $message = "<div class='alert error'>Ошибка: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='alert error'>Пожалуйста, заполните все поля правильно.</div>";
    }
}
?>

<main style="flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 20px;">
    <div class="form-card">
        <h2>Выставить на продажу</h2>
        <p style="margin-bottom: 30px; color: #64748b;">Заполните данные о лоте для размещения в реестре</p>
        
        <?= $message ?>

        <?php if(!isset($_SESSION['user_logged'])): ?>
            <div class="alert error">Для подачи объявления необходимо <a href="#" onclick="toggleModal('login')" style="color: inherit; font-weight: 800;">войти в систему</a>.</div>
        <?php else: ?>
            <form method="POST">
                <div class="f-group">
                    <label>Название лота</label>
                    <input type="text" name="title" placeholder="Например: Складской комплекс 500м²" required>
                </div>
                <div class="f-row">
                    <div class="f-group">
                        <label>Категория</label>
                        <select name="category">
                            <option value="Недвижимость">Недвижимость</option>
                            <option value="Транспорт">Транспорт</option>
                            <option value="Оборудование">Оборудование</option>
                        </select>
                    </div>
                    <div class="f-group">
                        <label>Цена (₽)</label>
                        <input type="number" name="price" placeholder="500000" required>
                    </div>
                </div>
                <div class="f-group">
                    <label>Регион</label>
                    <input type="text" name="region" placeholder="г. Самара" required>
                </div>
                <button type="submit" class="submit-btn">ОПУБЛИКОВАТЬ ЛОТ</button>
            </form>
        <?php endif; ?>
    </div>
</main>

<style>
    .form-card { background: #fff; padding: 40px; border-radius: 30px; width: 100%; max-width: 600px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
    .form-card h2 { color: #1e293b; margin-bottom: 10px; text-transform: uppercase; }
    .f-group { margin-bottom: 20px; display: flex; flex-direction: column; text-align: left; }
    .f-group label { font-size: 13px; font-weight: 700; color: #64748b; margin-bottom: 8px; }
    .f-group input, .f-group select { padding: 14px; border: 2px solid #f1f5f9; border-radius: 12px; font-size: 15px; outline: none; transition: 0.3s; }
    .f-group input:focus { border-color: #0088cc; }
    .f-row { display: flex; gap: 20px; }
    .f-row .f-group { flex: 1; }
    .submit-btn { width: 100%; padding: 18px; background: #0088cc; color: #fff; border: none; border-radius: 15px; font-weight: 800; cursor: pointer; transition: 0.3s; margin-top: 10px; }
    .submit-btn:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,136,204,0.3); }
    .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
    .success { background: #dcfce7; color: #166534; }
    .error { background: #fee2e2; color: #991b1b; }
</style>

<?php include 'footer.php'; ?>