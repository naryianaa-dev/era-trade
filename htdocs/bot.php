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
