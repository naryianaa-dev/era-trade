<?php
session_start();
require_once 'db.php';

// Проверка: только Александр может списывать деньги
if (!isset($_SESSION['user_name']) || $_SESSION['user_name'] !== 'Александр') {
    die("У вас нет прав для проведения этой операции.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $u_id = (int)$_POST['user_id'];
    $amount = (int)$_POST['amount'];

    if ($amount > 0) {
        // 1. Списываем сумму с баланса
        $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amount, $u_id]);

        // 2. ЗАПИСЫВАЕМ В ЛОГ (МЕСТО В КОДЕ: Сразу после успешного списания)
        $log_text = "Списан оброк/штраф в размере $amount ₽ с пользователя ID $u_id";
        logAction($pdo, $_SESSION['user_id'], 'FINE', $log_text);

        // 3. Возвращаемся обратно с уведомлением
        header("Location: users_control.php?msg=success_fine");
        exit();
    }
}

// Если что-то пошло не так, просто возвращаем назад
header("Location: users_control.php");
exit();