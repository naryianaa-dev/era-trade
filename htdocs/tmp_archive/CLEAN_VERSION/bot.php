<?php
$token = "ТВОЙ_ТОКЕН_ИЗ_ШАГА_1";
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) exit;

$chat_id = $data['message']['chat']['id'] ?? $data['callback_query']['from']['id'];
$text = $data['message']['text'] ?? '';

// Логика кнопок (меню настроек)
function getSettingsKeyboard() {
    return json_encode([
        'inline_keyboard' => [
            [['text' => '🏠 Недвижимость: ✅', 'callback_data' => 'toggle_realty']],
            [['text' => '🚗 Транспорт: ✅', 'callback_data' => 'toggle_transport']],
            [['text' => '🚜 Спецтехника: ✅', 'callback_data' => 'toggle_spec']],
            [['text' => '💾 Сохранить', 'callback_data' => 'save_settings']]
        ]
    ]);
}

if ($text == '/start') {
    $msg = "Привет! Я бот площадки ЭТП ЭРА. \nЗдесь ты будешь получать мгновенные уведомления о новых лотах.";
    sendMethod('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $msg,
        'reply_markup' => getSettingsKeyboard()
    ]);
}

// Функция для отправки запросов в ТГ
function sendMethod($method, $params) {
    global $token;
    $url = "https://api.telegram.org/bot$token/$method";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    return curl_exec($ch);
}
/* Секция промо TG */
.tg-promo {
    background: #ffffff;
    padding: 80px 0;
    text-align: center;
    border-top: 1px solid #eaeaea;
}

.tg-promo h2 {
    font-size: 28px;
    font-weight: 900;
    margin-bottom: 40px;
    color: #1a1a1a;
}

.promo-grid {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-bottom: 50px;
    flex-wrap: wrap;
}

.promo-item {
    background: #f8f9fa;
    padding: 30px 20px;
    border-radius: 20px;
    width: 180px;
    transition: all 0.3s ease;
    border: 1px solid transparent;
}

.promo-item:hover {
    transform: translateY(-10px);
    background: #fff;
    border-color: #0088cc;
    box-shadow: 0 10px 30px rgba(0, 136, 204, 0.1);
}

.promo-item .icon {
    font-size: 40px;
    margin-bottom: 15px;
}

.promo-item p {
    font-weight: 800;
    font-size: 13px;
    color: #333;
    letter-spacing: 0.5px;
}

/* Кнопка */
.btn-tg-action {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #0088cc;
    color: #fff;
    text-decoration: none;
    padding: 18px 35px;
    border-radius: 50px;
    font-weight: 800;
    font-size: 16px;
    transition: 0.3s;
    box-shadow: 0 4px 15px rgba(0, 136, 204, 0.3);
}

.btn-tg-action:hover {
    background: #0077b5;
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(0, 136, 204, 0.4);
}

/* Адаптив для мобилок */
@media (max-width: 768px) {
    .promo-item { width: 45%; }
    .tg-promo h2 { font-size: 22px; }
}
?>