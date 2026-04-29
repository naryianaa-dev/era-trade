<?php
session_start();
// Включаем отображение ошибок, чтобы если что-то пойдет не так, мы видели причину, а не просто 500
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';

if (!isset($_GET['id'])) {
    die("ID лота не указан");
}

$id = (int)$_GET['id'];

try {
    // 1. Получаем название лота
    $stmt_lot = $pdo->prepare("SELECT title FROM lots WHERE id = ?");
    $stmt_lot->execute([$id]);
    $lot_title = $stmt_lot->fetchColumn();

    // 2. Получаем все ставки. ВАЖНО: используем bid_time (как на твоем скрине)
    $stmt_bids = $pdo->prepare("SELECT * FROM bids WHERE lot_id = ? ORDER BY id ASC");
    $stmt_bids->execute([$id]);
    $bids = $stmt_bids->fetchAll();

    // Настраиваем заголовки для скачивания файла
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="protocol_lot_'.$id.'.txt"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo "ПРОТОКОЛ ХОДА ТОРГОВ\r\n";
    echo "Лот: " . ($lot_title ?: "Tesla Model S (Test)") . "\r\n";
    echo "------------------------------------------\r\n";

    if (empty($bids)) {
        echo "Ставок по данному лоту не зафиксировано.\r\n";
    } else {
        foreach ($bids as $bid) {
            // Если вдруг bid_time пустой, ставим текущее время, чтобы не было ошибки
            $time_val = !empty($bid['bid_time']) ? $bid['bid_time'] : date('Y-m-d H:i:s');
            $formatted_time = date('d.m.Y H:i:s', strtotime($time_val));
            
            echo "[" . $formatted_time . "] Юзер ID: " . $bid['user_id'] . " --- Ставка: " . number_format($bid['bid_amount'], 0, '', ' ') . " руб.\r\n";
        }
    }

    echo "------------------------------------------\r\n";
    echo "Конец протокола. Дата выгрузки: " . date('d.m.Y H:i:s');

} catch (Exception $e) {
    // Если упадет — напишет причину прямо в файл или на экран
    echo "ОШИБКА ГЕНЕРАЦИИ ПРОТОКОЛА: " . $e->getMessage();
}