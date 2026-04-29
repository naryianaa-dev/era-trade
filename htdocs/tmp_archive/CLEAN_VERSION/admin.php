<?php
session_start();
require_once 'db.php';

// Проверка: только Александр может зайти сюда
if (!isset($_SESSION['auth']) || $_SESSION['user_name'] !== 'Александр') {
    die("Доступ только для администратора.");
}

$message = '';

// Логика добавления лота
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $region = trim($_POST['region']);
    $price = $_POST['price'];

    if (!empty($title) && !empty($price)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO lots (title, region, price, status) VALUES (?, ?, ?, 'ACTIVE')");
            $stmt->execute([$title, $region, $price]);
            $message = '<div class="alert alert-success">Лот успешно добавлен!</div>';
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Ошибка базы: ' . $e->getMessage() . '</div>';
        }
    }
}

include 'header.php'; 
?>

<main style="flex: 1; padding: 40px 20px;">
    <div style="max-width: 600px; margin: 0 auto;">
        <h1 style="color: #fff; margin-bottom: 30px; text-transform: uppercase;">Добавить новый лот</h1>
        
        <div style="background: #fff; border-radius: 20px; padding: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
            <?= $message ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label text-muted" style="font-size: 12px; font-weight: bold;">НАЗВАНИЕ</label>
                    <input type="text" name="title" class="form-control" placeholder="Напр: BMW M5" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-muted" style="font-size: 12px; font-weight: bold;">РЕГИОН</label>
                    <input type="text" name="region" class="form-control" placeholder="г. Москва" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-muted" style="font-size: 12px; font-weight: bold;">НАЧАЛЬНАЯ ЦЕНА (₽)</label>
                    <input type="number" name="price" class="form-control" placeholder="1000000" required>
                </div>
                
                <button type="submit" class="btn btn-primary w-100" style="background: #3b82f6; border: none; padding: 12px; font-weight: bold; border-radius: 10px;">
                    ОПУБЛИКОВАТЬ В РЕЕСТРЕ
                </button>
            </form>
            
            <div class="mt-4 text-center">
                <a href="reestr.php" style="color: #64748b; text-decoration: none; font-size: 14px;">← Вернуться в реестр</a>
            </div>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>