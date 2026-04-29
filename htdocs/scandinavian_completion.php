<?php
/**
 * Скрипт для начисления бонуса организатору при завершении скандинавского аукциона
 * Вызывается при завершении торгов
 */

// Включаем отображение ошибок для отладки (можно убрать после исправления)
error_reporting(E_ALL);
ini_set('display_errors', 1);

function processScandinavianAuctionCompletion($pdo, $lot_id) {
    try {
        // Проверяем, существует ли таблица transactions, и создаём при необходимости
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            type ENUM('bonus','payment','refund') NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->beginTransaction();
        
        // Получаем информацию о лоте (исправленный запрос без ONLY_FULL_GROUP_BY)
        $stmt = $pdo->prepare(
            "SELECT 
                l.id, 
                l.owner_id, 
                l.auction_type, 
                l.price, 
                l.start_price,
                (SELECT COALESCE(SUM(b.bid_cost), 0) FROM bids b WHERE b.lot_id = l.id) AS total_bids_revenue,
                (SELECT COUNT(b.id) FROM bids b WHERE b.lot_id = l.id) AS total_bids_count
            FROM lots l
            WHERE l.id = ?
            FOR UPDATE"
        );
        $stmt->execute([$lot_id]);
        $lot = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lot || $lot['auction_type'] !== 'scandinavian') {
            $pdo->rollBack();
            return ['success' => false, 'msg' => 'Не скандинавский аукцион'];
        }
        
        $owner_id = (int)$lot['owner_id'];
        $total_bids_revenue = (float)$lot['total_bids_revenue'];
        
        if ($total_bids_revenue <= 0) {
            $pdo->rollBack();
            return ['success' => true, 'msg' => 'Нет ставок для начисления бонуса'];
        }
        
        // Бонус 15% от стоимости проданных ставок
        $bonus_percent = 15;
        $bonus_amount = round($total_bids_revenue * $bonus_percent / 100, 2);
        
        // Начисляем бонус организатору
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
            ->execute([$bonus_amount, $owner_id]);
        
        // Записываем транзакцию бонуса
        $pdo->prepare(
            "INSERT INTO transactions (user_id, amount, type, description, created_at)
             VALUES (?, ?, 'bonus', ?, NOW())"
        )->execute([
            $owner_id,
            $bonus_amount,
            "Бонус организатора 15% от скандинавского аукциона №{$lot_id} (выручка от ставок: " . 
            number_format($total_bids_revenue, 2, '.', ' ') . " ₽)"
        ]);
        
        // Обновляем статус лота
        $pdo->prepare("UPDATE lots SET auction_status = 'finished' WHERE id = ?")
            ->execute([$lot_id]);
        
        $pdo->commit();
        
        error_log("Scandinavian auction #{$lot_id}: Bonus {$bonus_amount} RUB credited to owner #{$owner_id}");
        
        return [
            'success' => true,
            'bonus_amount' => $bonus_amount,
            'total_bids_revenue' => $total_bids_revenue,
            'owner_id' => $owner_id,
            'msg' => "Бонус {$bonus_amount} ₽ начислен организатору"
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("processScandinavianAuctionCompletion error: " . $e->getMessage());
        return ['success' => false, 'msg' => 'Ошибка начисления бонуса: ' . $e->getMessage()];
    }
}

/**
 * Крон-задача для автоматического завершения скандинавских аукционов
 * Запускается раз в минуту
 */
function autoCompleteScandinavianAuctions($pdo) {
    try {
        // Находим завершённые скандинавские аукционы, которые ещё не обработаны
        $stmt = $pdo->prepare(
            "SELECT id FROM lots 
             WHERE auction_type = 'scandinavian' 
               AND auction_status = 'active'
               AND end_time <= NOW()
               AND (max_end_time IS NULL OR max_end_time <= NOW())"
        );
        $stmt->execute();
        $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $processed = 0;
        foreach ($lots as $lot) {
            $result = processScandinavianAuctionCompletion($pdo, $lot['id']);
            if ($result['success']) {
                $processed++;
            } else {
                error_log("Failed to process auction #{$lot['id']}: " . $result['msg']);
            }
        }
        
        if ($processed > 0) {
            error_log("Auto-completed {$processed} scandinavian auctions");
        }
        
        return ['success' => true, 'processed' => $processed];
        
    } catch (Exception $e) {
        error_log("autoCompleteScandinavianAuctions error: " . $e->getMessage());
        return ['success' => false, 'msg' => $e->getMessage()];
    }
}

// Если скрипт запущен напрямую (крон)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/db.php';
    
    // Проверяем, что $pdo определён
    if (!isset($pdo) || !$pdo) {
        die("Ошибка: PDO не инициализирован в db.php");
    }
    
    $result = autoCompleteScandinavianAuctions($pdo);
    if (!$result['success']) {
        echo "Ошибка: " . $result['msg'] . PHP_EOL;
    } else {
        echo "Обработано аукционов: " . $result['processed'] . PHP_EOL;
    }
}