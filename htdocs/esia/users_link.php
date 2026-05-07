<?php
/**
 * htdocs/esia/users_link.php
 *
 * Связывание профиля ЕСИА с записью в таблице users.
 *   — Если есть пользователь с users.esia_oid = $profile['oid'] → возвращаем его id.
 *   — Иначе если есть пользователь с тем же email/snils → линкуем (заполняем esia_oid).
 *   — Иначе создаём нового пользователя.
 *
 * Структура users расширяется один раз при первом обращении —
 * см. esia_ensure_user_columns().
 */

declare(strict_types=1);

require_once __DIR__ . '/migrate.php';

/**
 * @param array<string,mixed> $profile  результат esia_fetch_profile()
 *
 * @return int  внутренний users.id
 */
function esia_link_or_create_user(PDO $pdo, array $profile): int
{
    esia_ensure_user_columns($pdo);

    $oid    = (string) $profile['oid'];
    $email  = (string) ($profile['email']  ?? '');
    $snils  = preg_replace('/\D+/', '', (string) ($profile['snils'] ?? '')) ?? '';
    $inn    = preg_replace('/\D+/', '', (string) ($profile['inn']   ?? '')) ?? '';
    $mobile = (string) ($profile['mobile'] ?? '');

    $first  = (string) ($profile['firstName']  ?? '');
    $last   = (string) ($profile['lastName']   ?? '');
    $middle = (string) ($profile['middleName'] ?? '');
    $fullName = trim("$last $first $middle");

    $bd = (string) ($profile['birthDate'] ?? '');
    $birthSql = null;
    if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})$~', $bd, $m)) {
        $birthSql = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    $gender = (string) ($profile['gender'] ?? '');
    if (!in_array($gender, ['M', 'F'], true)) {
        $gender = '';
    }

    // 1. По esia_oid
    $stmt = $pdo->prepare('SELECT id FROM users WHERE esia_oid = ? LIMIT 1');
    $stmt->execute([$oid]);
    $row = $stmt->fetch();
    if ($row) {
        esia_update_user_profile($pdo, (int) $row['id'], [
            'snils'      => $snils,
            'inn'        => $inn,
            'birthdate'  => $birthSql,
            'gender'     => $gender,
            'full_name'  => $fullName,
            'email'      => $email,
            'mobile'     => $mobile,
            'esia_oid'   => $oid,
            'esia_trusted' => !empty($profile['trusted']) ? 1 : 0,
        ]);
        return (int) $row['id'];
    }

    // 2. По email/snils — связываем существующего
    if ($email !== '') {
        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE email = ? AND (esia_oid IS NULL OR esia_oid = "") LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row) {
            esia_update_user_profile($pdo, (int) $row['id'], [
                'esia_oid'   => $oid,
                'snils'      => $snils,
                'inn'        => $inn,
                'birthdate'  => $birthSql,
                'gender'     => $gender,
                'full_name'  => $fullName,
                'mobile'     => $mobile,
                'esia_trusted' => !empty($profile['trusted']) ? 1 : 0,
            ]);
            return (int) $row['id'];
        }
    }

    if ($snils !== '') {
        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE snils = ? AND (esia_oid IS NULL OR esia_oid = "") LIMIT 1'
        );
        $stmt->execute([$snils]);
        $row = $stmt->fetch();
        if ($row) {
            esia_update_user_profile($pdo, (int) $row['id'], [
                'esia_oid'   => $oid,
                'inn'        => $inn,
                'birthdate'  => $birthSql,
                'gender'     => $gender,
                'full_name'  => $fullName,
                'email'      => $email,
                'mobile'     => $mobile,
                'esia_trusted' => !empty($profile['trusted']) ? 1 : 0,
            ]);
            return (int) $row['id'];
        }
    }

    // 3. Новый пользователь
    $username = 'esia_' . $oid;
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, email, password, full_name, balance, user_type, '
        . 'esia_oid, snils, inn, birthdate, gender, mobile, esia_trusted, created_at) '
        . 'VALUES (?, ?, "", ?, 0, "user", ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $username,
        $email ?: null,
        $fullName ?: null,
        $oid,
        $snils ?: null,
        $inn ?: null,
        $birthSql,
        $gender ?: null,
        $mobile ?: null,
        !empty($profile['trusted']) ? 1 : 0,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Обновляет только непустые поля профиля.
 *
 * @param array<string, mixed> $fields
 */
function esia_update_user_profile(PDO $pdo, int $userId, array $fields): void
{
    $sets = [];
    $vals = [];
    foreach ($fields as $col => $val) {
        if ($val === null || $val === '' || $val === 0) {
            // 0 для esia_trusted допускаем явно
            if ($col !== 'esia_trusted') {
                continue;
            }
        }
        $sets[] = "`$col` = ?";
        $vals[] = $val;
    }
    if (!$sets) {
        return;
    }
    $vals[] = $userId;
    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($vals);
}
