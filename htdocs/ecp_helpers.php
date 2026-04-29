<?php
/**
 * ecp_helpers.php
 *
 * Утилиты для работы с ЭЦП (электронная цифровая подпись).
 * ВАЖНО: реальная криптовалидация ПОКА НЕ ВКЛЮЧЕНА. Все verify-функции
 * выполняют только формальные проверки (формат base64, минимальная длина,
 * наличие сертификата). См. ECP_INTEGRATION.md о том, какие места заменить.
 *
 * Глобальный флаг доступности UI ЭЦП. На время разработки = false:
 * - кнопка «Войти через ЭЦП» в auth_modal становится disabled с пометкой «Скоро»
 * - вкладка ЭЦП в profile.php показывает заглушку
 * - чекбокс «Подписать ЭЦП» на офферах скрыт
 * Когда подключим боевую крипту — выставить true и UI оживёт без правок кода.
 *
 * Целевая боевая криптография:
 *   - КриптоПро ЭЦП Browser Plug-in (клиент) для подписи данных GOST R 34.10-2012.
 *   - На сервере — `cryptcp` от КриптоПро (Linux, через shell_exec) или
 *     phpseclib + кастомный verifier для CAdES-BES.
 *   - Цепочка доверия — корневой сертификат УЦ, выдавшего пользователю ЭЦП.
 *
 * См. функции ниже, помеченные TODO[ECP].
 */

if (!defined('ECP_ENABLED')) define('ECP_ENABLED', false);

if (!function_exists('ecp_generate_challenge')) {

/* Генерирует одноразовый nonce и сохраняет в ecp_challenges.
   Возвращает строку nonce (hex, 32 байта). */
function ecp_generate_challenge(PDO $pdo, string $purpose = 'login'): string {
    $nonce = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO ecp_challenges (nonce, purpose) VALUES (?, ?)")
        ->execute([$nonce, $purpose]);
    /* Уборка старых (>10 минут) и использованных. */
    $pdo->exec("DELETE FROM ecp_challenges
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                   OR used_at IS NOT NULL");
    return $nonce;
}

/* Проверяет что nonce существует, не использован, не истёк. Помечает used.
   Возвращает массив записи из ecp_challenges или null если нет. */
function ecp_consume_challenge(PDO $pdo, string $nonce, string $purpose = 'login'): ?array {
    $st = $pdo->prepare(
        "SELECT * FROM ecp_challenges
         WHERE nonce = ? AND purpose = ? AND used_at IS NULL
           AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
         LIMIT 1"
    );
    $st->execute([$nonce, $purpose]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $pdo->prepare("UPDATE ecp_challenges SET used_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    return $row;
}

/* Извлекает Subject CN и Serial из PEM-сертификата.
   Возвращает массив [subject, serial] или [null, null].
   TODO[ECP]: при реальном внедрении использовать openssl_x509_parse() для X.509
   обычной криптой и отдельный CAdES-парсер для ГОСТ-сертификатов. */
function ecp_parse_cert_pem(string $cert_pem): array {
    $subject = null;
    $serial  = null;

    // Попытка через openssl (работает для не-ГОСТ сертификатов и ряда ГОСТ-овых).
    if (function_exists('openssl_x509_parse')) {
        $parsed = @openssl_x509_parse($cert_pem);
        if (is_array($parsed)) {
            if (!empty($parsed['subject'])) {
                $subject = is_array($parsed['subject'])
                    ? implode(', ', array_map(fn($k, $v) => "$k=" . (is_array($v) ? implode('|', $v) : $v),
                        array_keys($parsed['subject']), array_values($parsed['subject'])))
                    : (string)$parsed['subject'];
            }
            $serial = $parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? null);
        }
    }
    return [$subject, $serial];
}

/* Извлекает «бизнес-поля» из российского ЭЦП-сертификата.
   Возвращает ассоциативный массив с ключами:
     full_name, surname, given_name, email, phone,
     organization, position, inn, ogrn, snils,
     country, region, city, street, entity_type ('individual' | 'legal').
   Все поля nullable. Подстановка делается ТОЛЬКО для непустых значений
   и ТОЛЬКО в пустые поля юзера (см. ecp_fill_user_from_cert).

   Российские OID'ы:
     1.2.643.3.131.1.1 — ИНН физлица (12 знаков)
     1.2.643.100.4     — ИНН юрлица (10 знаков)
     1.2.643.100.1     — ОГРН (13 знаков)
     1.2.643.100.5     — ОГРНИП (15 знаков)
     1.2.643.100.3     — СНИЛС (11 цифр)

   openssl_x509_parse возвращает их под этими же OID-строками в массиве subject.
   Для ГОСТ-овых сертификатов парсер может частично сломаться — тогда падаем
   обратно к regex'ам по DN-строке. */
function ecp_extract_user_fields(string $cert_pem): array {
    $out = [
        'full_name'     => null,
        'surname'       => null,
        'given_name'    => null,
        'email'         => null,
        'phone'         => null,
        'organization'  => null,
        'position'      => null,
        'inn'           => null,
        'ogrn'          => null,
        'snils'         => null,
        'country'       => null,
        'region'        => null,
        'city'          => null,
        'street'        => null,
        'entity_type'   => null,
    ];

    if (!function_exists('openssl_x509_parse')) return $out;

    $parsed = @openssl_x509_parse($cert_pem);
    if (!is_array($parsed)) return $out;

    $subj = $parsed['subject'] ?? [];

    /* Маппинг известных ключей. openssl возвращает либо строки ('CN', 'O',
       'emailAddress'), либо OID-строки для нестандартных атрибутов. */
    $get = function(array $arr, array $keys) {
        foreach ($keys as $k) {
            if (isset($arr[$k])) {
                $v = $arr[$k];
                /* Бывает массив (несколько значений с одинаковым ключом). */
                if (is_array($v)) $v = $v[0] ?? null;
                if ($v !== null && $v !== '') return (string)$v;
            }
        }
        return null;
    };

    $cn       = $get($subj, ['CN', 'commonName']);
    $surname  = $get($subj, ['SN', 'surname', '2.5.4.4']);
    $given    = $get($subj, ['G', 'GN', 'givenName', '2.5.4.42']);
    $email    = $get($subj, ['emailAddress', 'E', '1.2.840.113549.1.9.1']);
    $org      = $get($subj, ['O', 'organizationName']);
    $orgUnit  = $get($subj, ['OU', 'organizationalUnitName']);
    $title    = $get($subj, ['T', 'title', '2.5.4.12']);
    $country  = $get($subj, ['C', 'countryName']);
    $region   = $get($subj, ['ST', 'stateOrProvinceName']);
    $city     = $get($subj, ['L', 'localityName']);
    $street   = $get($subj, ['street', 'streetAddress', '2.5.4.9']);

    $inn_phys = $get($subj, ['INN', '1.2.643.3.131.1.1']);
    $inn_legl = $get($subj, ['INNLE', '1.2.643.100.4']);
    /* openssl при наличии OID-меток в /etc/ssl/openssl.cnf отдаёт OGRN под именем,
       при отсутствии — под голым OID. Перебираем оба варианта. */
    $ogrn_le  = $get($subj, ['OGRN', '1.2.643.100.1']);
    $ogrn_ip  = $get($subj, ['OGRNIP', '1.2.643.100.5']);
    $snils    = $get($subj, ['SNILS', '1.2.643.100.3']);

    /* Если openssl не достал OID'ы — пробуем regex по DN-строке. */
    $dn_str = '';
    foreach ($subj as $k => $v) {
        if (is_array($v)) $v = implode('|', $v);
        $dn_str .= "$k=$v, ";
    }
    if (!$inn_phys && preg_match('/1\.2\.643\.3\.131\.1\.1\s*=\s*([0-9]{10,12})/', $dn_str, $m)) $inn_phys = $m[1];
    if (!$inn_legl && preg_match('/1\.2\.643\.100\.4\s*=\s*([0-9]{10,12})/',     $dn_str, $m)) $inn_legl = $m[1];
    if (!$ogrn_le  && preg_match('/1\.2\.643\.100\.1\s*=\s*([0-9]{13})/',         $dn_str, $m)) $ogrn_le  = $m[1];
    if (!$ogrn_ip  && preg_match('/1\.2\.643\.100\.5\s*=\s*([0-9]{15})/',         $dn_str, $m)) $ogrn_ip  = $m[1];
    if (!$snils    && preg_match('/1\.2\.643\.100\.3\s*=\s*([0-9\-\s]{11,16})/',  $dn_str, $m)) $snils    = trim($m[1]);

    /* ФИО: предпочитаем CN, fallback на склейку SN + G. */
    if ($cn) {
        $out['full_name'] = $cn;
    } elseif ($surname || $given) {
        $out['full_name'] = trim(($surname ?? '') . ' ' . ($given ?? ''));
    }
    $out['surname']    = $surname;
    $out['given_name'] = $given;
    $out['email']      = $email;
    $out['organization'] = $org;
    $out['position']   = $title ?: $orgUnit;
    $out['country']    = $country;
    $out['region']     = $region;
    $out['city']       = $city;
    $out['street']     = $street;

    /* ИНН: физик имеет 12 знаков, юрик 10. */
    if ($inn_phys && preg_match('/^\d{10,12}$/', $inn_phys)) {
        $out['inn'] = $inn_phys;
        $out['entity_type'] = (strlen($inn_phys) === 12) ? 'individual' : 'legal';
    } elseif ($inn_legl && preg_match('/^\d{10,12}$/', $inn_legl)) {
        $out['inn'] = $inn_legl;
        $out['entity_type'] = (strlen($inn_legl) === 10) ? 'legal' : 'individual';
    }

    if ($ogrn_le)  $out['ogrn'] = $ogrn_le;
    elseif ($ogrn_ip) $out['ogrn'] = $ogrn_ip;

    if ($snils) $out['snils'] = preg_replace('/\s+/', '', $snils);

    /* Если есть Organization — почти наверняка это юр.лицо/ИП. */
    if (!$out['entity_type'] && $org) $out['entity_type'] = 'legal';

    /* Адрес регистрации — склейка из частей, если есть. */
    $addr_parts = array_filter([$out['country'], $out['region'], $out['city'], $out['street']]);
    if ($addr_parts) {
        $out['registration_address'] = implode(', ', $addr_parts);
    }

    return $out;
}

/* Применяет распарсенные поля к users: только те, что заполнены в серте,
   и только в те колонки, что пусты у юзера (не затирает ручной ввод). */
function ecp_fill_user_from_cert(PDO $pdo, int $user_id, array $fields): array {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$user_id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return [];

    /* Карта cert-field => users-column. */
    $map = [
        'full_name'             => 'full_name',
        'email'                 => 'email',
        'organization'          => 'company',
        'position'              => 'position',
        'inn'                   => 'inn',
        'ogrn'                  => 'ogrn',
        'snils'                 => 'snils',
        'entity_type'           => 'entity_type',
        'registration_address'  => 'registration_address',
    ];
    $applied = [];
    foreach ($map as $cert_key => $col) {
        $val = $fields[$cert_key] ?? null;
        if ($val === null || $val === '') continue;
        if (!array_key_exists($col, $u)) continue; // колонки нет в схеме
        if (!empty($u[$col])) continue;            // уже заполнено руками — не трогаем
        $pdo->prepare("UPDATE users SET `$col` = ? WHERE id = ?")
            ->execute([$val, $user_id]);
        $applied[$col] = $val;
    }
    /* fullname (другая колонка, синхронизируем с full_name если пуста). */
    if (!empty($fields['full_name']) && empty($u['fullname'])) {
        $pdo->prepare("UPDATE users SET fullname = ? WHERE id = ?")
            ->execute([$fields['full_name'], $user_id]);
        $applied['fullname'] = $fields['full_name'];
    }
    return $applied;
}

/* Формальная проверка подписи: base64 + минимальная длина + наличие cert.
   В боевом режиме здесь должен быть запуск cryptcp/CAdES verify против
   данных $data и сертификата $cert_pem.
   TODO[ECP]: ЗАМЕНИТЬ на реальную проверку CMS/CAdES-BES.
   Возвращает true (всегда сейчас, если формальные проверки прошли). */
function ecp_verify_signature(string $data, string $signature_b64, string $cert_pem): bool {
    if ($signature_b64 === '' || $cert_pem === '') return false;
    $sig_bin = base64_decode($signature_b64, true);
    if ($sig_bin === false || strlen($sig_bin) < 16) return false;
    if (strpos($cert_pem, 'BEGIN CERTIFICATE') === false &&
        strpos($cert_pem, 'BEGIN CERTIFICATE') === false) {
        // Допускаем также «голые» base64 без BEGIN/END (плагин КриптоПро так и отдаёт).
        $cert_b = base64_decode(str_replace(["\r","\n"," "], '', $cert_pem), true);
        if ($cert_b === false || strlen($cert_b) < 64) return false;
    }
    // TODO[ECP]: shell_exec('cryptcp -verify -dn ...') или phpseclib CMS verify.
    return true;
}

/* Записывает подпись в ecp_signatures и возвращает её id. */
function ecp_log_signature(
    PDO $pdo,
    ?int $user_id,
    string $target_type,
    ?int $target_id,
    ?string $challenge,
    string $signature_b64,
    ?string $cert_pem,
    bool $verified
): int {
    [$subject, $serial] = $cert_pem ? ecp_parse_cert_pem($cert_pem) : [null, null];
    $st = $pdo->prepare(
        "INSERT INTO ecp_signatures
            (user_id, target_type, target_id, challenge, signature_b64,
             cert_pem, cert_serial, cert_subject, verified)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $st->execute([
        $user_id, $target_type, $target_id, $challenge,
        $signature_b64, $cert_pem, $serial, $subject, $verified ? 1 : 0
    ]);
    return (int)$pdo->lastInsertId();
}

} // end if function_exists guard
