<?php
session_start();
header('Content-Type: application/json');

$fullname = $_POST['fullname'] ?? 'Участник';
$inn = $_POST['inn'] ?? '0000000000';
$pay_method = $_POST['pay'] ?? 'sbp';
$total = (int)($_POST['total'] ?? 0);

if ($total > 0) {
    if ($pay_method === 'bill') {
        // Формируем ссылку на bill_gen.php с полной суммой
        $bill_url = "bill_gen.php?sum=$total&inn=$inn&name=" . urlencode($fullname);
        echo json_encode(['success' => true, 'bill_url' => $bill_url]);
    } else {
        // Ссылка на СБП
        $pay_url = "https://qr.nspk.ru/ad100000000000000000000?sum=" . ($total * 100);
        echo json_encode(['success' => true, 'pay_url' => $pay_url]);
    }
} else {
    // Бесплатная регистрация
    $_SESSION['user_logged'] = true;
    $_SESSION['username'] = $fullname;
    echo json_encode(['success' => true]);
}
exit;