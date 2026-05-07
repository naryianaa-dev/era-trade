<?php
/**
 * htdocs/esia/migrate.php
 *
 * Идемпотентная миграция БД для интеграции с ЕСИА.  Добавляет в таблицу
 * users колонки, в которые маппятся данные из профиля Госуслуг.  Безопасна
 * к многократному вызову; вызывается лениво из users_link.php при первом
 * успешном callback.
 *
 * Также пригодна как standalone CLI:  php htdocs/esia/migrate.php
 */

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../db.php';
}

/**
 * Структура колонок, которые нужно гарантированно добавить.  Ключ —
 * имя колонки, значение — DDL-фрагмент.
 *
 * @return array<string,string>
 */
function esia_user_columns_def(): array
{
    return [
        'esia_oid'      => 'VARCHAR(64) NULL',
        'esia_trusted'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'snils'         => 'VARCHAR(20) NULL',
        'inn'           => 'VARCHAR(15) NULL',
        'birthdate'     => 'DATE NULL',
        'gender'        => 'CHAR(1) NULL',
        'mobile'        => 'VARCHAR(20) NULL',
        'email_verified' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'mobile_verified' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
}

function esia_ensure_user_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    foreach (esia_user_columns_def() as $col => $def) {
        try {
            $st = $pdo->query('SHOW COLUMNS FROM users LIKE ' . $pdo->quote($col));
            if ($st && !$st->fetch()) {
                $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def");
            }
        } catch (Throwable $e) {
            error_log('[esia/migrate] ' . $col . ': ' . $e->getMessage());
        }
    }
    // Уникальный индекс на esia_oid, если получится
    try {
        $st = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_esia_oid'");
        if ($st && !$st->fetch()) {
            $pdo->exec('CREATE UNIQUE INDEX idx_users_esia_oid ON users (esia_oid)');
        }
    } catch (Throwable $e) {
        error_log('[esia/migrate] idx_users_esia_oid: ' . $e->getMessage());
    }
    $done = true;
}

// CLI-режим
if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    require_once __DIR__ . '/../db.php';
    /** @var PDO $pdo */
    esia_ensure_user_columns($pdo);
    echo "users columns ensured.\n";
}
