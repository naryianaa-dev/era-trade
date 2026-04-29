<?php
include 'db.php';

$lot_id = isset($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;

try {
    // 1. Получаем инфу о лоте (используем title вместо name)
    $stmt = $pdo->prepare("SELECT title, currency FROM lots WHERE id = ?");
    $stmt->execute([$lot_id]);
    $lot = $stmt->fetch();

    if (!$lot) die("Лот не найден.");

    // Настройка значка валюты
    $symbols = ['RUB' => '₽', 'USD' => '$', 'AMD' => '֏', 'USDT' => '₮'];
    $cur = isset($symbols[$lot['currency']]) ? $symbols[$lot['currency']] : '₽';

    // 2. Получаем все ставки
    $stmt_bids = $pdo->prepare("SELECT * FROM bids WHERE lot_id = ? ORDER BY id ASC");
    $stmt_bids->execute([$lot_id]);
    $bids = $stmt_bids->fetchAll();

    // 3. Формируем текст файла
    $content = "ПРОТОКОЛ ТОРГОВ ПО ЛОТУ №{$lot_id}\r\n";
    $content .= "Объект: " . $lot['title'] . "\r\n";
    $content .= "Дата выгрузки: " . date('d.m.Y H:i:s') . "\r\n";
    $content .= "------------------------------------------\r\n\r\n";

    if (empty($bids)) {
        $content .= "Ставок по данному лоту не зафиксировано.\r\n";
    } else {
        foreach ($bids as $index => $bid) {
            $num = $index + 1;
            $amount = number_format($bid['bid_amount'], 0, '', ' ');
            $time = date('H:i:s', strtotime($bid['bid_time']));
            $content .= "Ставка #{$num}: {$amount} {$cur} | Время: {$time}\r\n";
        }
    }

    // 4. Отдаем файл браузеру
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_lot_'.$lot_id.'.txt"');
    
    echo $content;

} catch (Exception $e) {
    die("Ошибка генерации: " . $e->getMessage());
}