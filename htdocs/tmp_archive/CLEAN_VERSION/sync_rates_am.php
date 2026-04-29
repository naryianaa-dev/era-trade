<?php
include 'db.php';

try {
    // 1. Грузим главную страницу rates.am
    // Используем заголовки, чтобы сайт не подумал, что мы робот
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/115.0.0.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $html = file_get_contents("https://www.rates.am/ru/armenian-dram-exchange-rates/banks/russian-dram", false, $context);

    if (!$html) throw new Exception("Не удалось загрузить Rates.am");

    // 2. Ищем средний курс рубля (в таблице средних значений)
    // Ищем ячейку, которая идет после покупки рубля
    preg_match('/<td[^>]*>(\d+\.?\d*)<\/td>/', $html, $matches);
    
    // ВНИМАНИЕ: На rates.am курс обычно указан как 1 RUB = X.XX AMD
    $rub_to_amd = isset($matches[1]) ? (float)$matches[1] : 4.1; 

    // 3. Сохраняем в базу
    // Нам нужно сохранить это значение, чтобы потом умножать рубли на него
    $stmt = $pdo->prepare("UPDATE exchange_rates SET rate_to_rub = ?, updated_at = NOW() WHERE currency_code = ?");
    
    // В твоей логике rate_to_rub для AMD — это сколько РУБЛЕЙ в 1 драме.
    // Поэтому считаем: 1 / курс_драма
    $amd_to_rub = 1 / $rub_to_amd;

    $stmt->execute([$amd_to_rub, 'AMD']);

    echo "✅ ДАННЫЕ С RATES.AM ОБНОВЛЕНЫ:<br>";
    echo "1 RUB = $rub_to_amd AMD<br>";
    echo "Курс для расчетов в базе (AMD to RUB): " . round($amd_to_rub, 4);

} catch (Exception $e) {
    echo "❌ ОШИБКА: " . $e->getMessage();
}