<?php
/**
 * db_schema_extra.php
 *
 * Создаёт дополнительные таблицы для:
 *  - запроса котировок и запроса предложений (lot_offers)
 *  - закрытого аукциона (closed_participants)
 *
 * Подключается из соответствующих страниц/обработчиков. Использует
 * CREATE TABLE IF NOT EXISTS, поэтому безопасно вызывать многократно.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/db.php';
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lot_offers (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lot_id        INT UNSIGNED NOT NULL,
            user_id       INT UNSIGNED NOT NULL,
            offer_type    ENUM('quotation','proposal') NOT NULL,
            price         DECIMAL(15,2) NOT NULL,
            comment       TEXT NULL,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_lot_user (lot_id, user_id),
            INDEX idx_lot (lot_id),
            INDEX idx_user (user_id),
            INDEX idx_type (offer_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Расширяем enum auction_type значением 'proposal', если его ещё нет
    try {
        $st = $pdo->query("SHOW COLUMNS FROM lots LIKE 'auction_type'");
        $row = $st ? $st->fetch() : null;
        if ($row && stripos($row['Type'], "'proposal'") === false) {
            $pdo->exec("ALTER TABLE lots MODIFY auction_type ENUM('classic','scandinavian','closed','descending','quotation','proposal','commission') NOT NULL DEFAULT 'classic'");
        }
    } catch (Exception $e) {
        error_log('db_schema_extra (alter enum) error: ' . $e->getMessage());
    }

    // Добавляем недостающие колонки в lots (миграция со старых деплоев)
    $missing_cols = [
        'time_before_start' => 'INT DEFAULT 0',
        'extra_params'      => 'TEXT NULL',
    ];
    foreach ($missing_cols as $col => $def) {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM lots LIKE " . $pdo->quote($col));
            if ($st && !$st->fetch()) {
                $pdo->exec("ALTER TABLE lots ADD COLUMN `$col` $def");
            }
        } catch (Exception $e) {
            error_log("db_schema_extra (add lots.$col) error: " . $e->getMessage());
        }
    }

    // Миграция: старые лоты, оставшиеся со статусом 'draft' от прошлых
    // версий add_lot.php, не появлялись в реестре ни под одним фильтром.
    // Переводим их в 'active' — фильтры reestr.php различают этап жизненного
    // цикла по started_at и end_time, а не по 'draft'.
    try {
        $pdo->exec("UPDATE lots SET auction_status = 'active' WHERE auction_status = 'draft'");
    } catch (Exception $e) {
        error_log('db_schema_extra (draft -> active migration) error: ' . $e->getMessage());
    }

        /* Favorites: per-user starred lots, both auction lots and torgi lots. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_favorites (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            lot_type    ENUM('lot','torgi') NOT NULL,
            lot_id      INT UNSIGNED NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY u_user_lot (user_id, lot_type, lot_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

$pdo->exec("
        CREATE TABLE IF NOT EXISTS closed_participants (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lot_id           INT UNSIGNED NOT NULL,
            user_id          INT UNSIGNED NOT NULL,
            application_text TEXT NULL,
            status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            decided_at       DATETIME NULL,
            decided_by       INT UNSIGNED NULL,
            decision_comment TEXT NULL,
            UNIQUE KEY u_lot_user (lot_id, user_id),
            INDEX idx_lot (lot_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('db_schema_extra error: ' . $e->getMessage());
}
