<?php
/**
 * htdocs/esia/oidc_client.php
 *
 * Клиент OpenID Connect для ЕСИА.  Реализует:
 *   1. Сборку URL авторизации /aas/oauth2/v3/ac (redirect пользователя).
 *   2. Обмен authorization code на access_token + id_token (POST /te
 *      с client_assertion=JWT по private_key_jwt).
 *   3. Получение профиля субъекта /rs/prns/{oid} с Bearer access_token.
 *   4. Логирование всех запросов в esia.log (без чувствительных полей).
 *
 * Регламент: OpenID Connect 1.0 + Технологический регламент ЕСИА v2.53.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/signer.php';

/**
 * Инициировать OAuth2 authorization code flow.  Сохраняет state в сессии,
 * возвращает URL, на который надо редиректнуть пользователя.
 *
 * @return array{url: string, state: string} URL и сгенерированный state
 */
function esia_build_authorize_url(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $cfg  = esia_config();
    $eps  = esia_endpoints($cfg);

    $state    = bin2hex(random_bytes(16));
    $nonce    = bin2hex(random_bytes(16));
    $now      = time();
    $scopeStr = implode(' ', $cfg['scopes']);

    // Подписываем сами параметры запроса (требование ЕСИА для access_type=online).
    // client_secret — это base64(GOST-подпись от $signingInput).
    $signingInput = $cfg['client_id']
        . '&' . $scopeStr
        . '&' . gmdate('Y.m.d H:i:s O', $now)
        . '&' . $cfg['client_id']
        . '&' . $state;
    $clientSecret = base64_encode(esia_signer_sign($signingInput));

    $params = [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $clientSecret,
        'redirect_uri'  => $cfg['redirect_uri'],
        'scope'         => $scopeStr,
        'response_type' => 'code',
        'state'         => $state,
        'nonce'         => $nonce,
        'timestamp'     => gmdate('Y.m.d H:i:s O', $now),
        'access_type'   => 'online',
    ];

    $_SESSION['esia_state']      = $state;
    $_SESSION['esia_nonce']      = $nonce;
    $_SESSION['esia_state_time'] = $now;

    esia_log('AUTHORIZE-REDIRECT', [
        'state'     => $state,
        'scopes'    => $scopeStr,
        'redirect'  => $cfg['redirect_uri'],
    ]);

    return [
        'url'   => $eps['authorize'] . '?' . http_build_query($params),
        'state' => $state,
    ];
}

/**
 * Обменять authorization code на access_token + id_token.
 *
 * @param string $code  код из callback
 * @param string $state state из callback (должен совпасть с сессионным)
 *
 * @return array{access_token: string, id_token: string, expires_in: int, token_type: string, oid: ?string}
 *
 * @throws RuntimeException при ошибке валидации/обмена
 */
function esia_exchange_code(string $code, string $state): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $cfg = esia_config();
    $eps = esia_endpoints($cfg);

    $sessionState = $_SESSION['esia_state'] ?? '';
    $stateTime    = (int) ($_SESSION['esia_state_time'] ?? 0);

    if ($state === '' || $state !== $sessionState) {
        throw new RuntimeException('ESIA state mismatch (CSRF guard)');
    }
    if (time() - $stateTime > $cfg['state_ttl']) {
        throw new RuntimeException('ESIA state expired');
    }

    $now      = time();
    $scopeStr = implode(' ', $cfg['scopes']);
    $signingInput = $cfg['client_id']
        . '&' . $scopeStr
        . '&' . gmdate('Y.m.d H:i:s O', $now)
        . '&' . $cfg['client_id']
        . '&' . $state;
    $clientSecret = base64_encode(esia_signer_sign($signingInput));

    $body = [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $clientSecret,
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'state'         => $state,
        'scope'         => $scopeStr,
        'timestamp'     => gmdate('Y.m.d H:i:s O', $now),
        'redirect_uri'  => $cfg['redirect_uri'],
        'token_type'    => 'Bearer',
    ];

    $resp = esia_http_post($eps['token'], http_build_query($body), [
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    if ($resp['code'] !== 200) {
        esia_log('TOKEN-ERROR', [
            'http' => $resp['code'],
            'body' => mb_substr($resp['body'], 0, 500),
        ]);
        throw new RuntimeException(
            "ESIA token endpoint HTTP {$resp['code']}: " . mb_substr($resp['body'], 0, 300)
        );
    }

    $j = json_decode($resp['body'], true);
    if (!is_array($j) || !isset($j['access_token'], $j['id_token'])) {
        throw new RuntimeException('ESIA token endpoint malformed response');
    }

    $oid = null;
    try {
        [, $idPayload] = esia_parse_jwt($j['id_token']);
        $oid = $idPayload['urn:esia:sbj_id'] ?? $idPayload['sub'] ?? null;
        if (isset($idPayload['nonce']) && ($_SESSION['esia_nonce'] ?? null) !== $idPayload['nonce']) {
            throw new RuntimeException('ESIA id_token nonce mismatch');
        }
    } catch (Throwable $e) {
        esia_log('ID-TOKEN-PARSE-WARN', ['msg' => $e->getMessage()]);
    }

    unset($_SESSION['esia_state'], $_SESSION['esia_nonce'], $_SESSION['esia_state_time']);

    return [
        'access_token' => (string) $j['access_token'],
        'id_token'     => (string) $j['id_token'],
        'expires_in'   => (int) ($j['expires_in'] ?? 3600),
        'token_type'   => (string) ($j['token_type'] ?? 'Bearer'),
        'oid'          => $oid !== null ? (string) $oid : null,
    ];
}

/**
 * Получить профиль субъекта по OID + access_token.
 *
 * Возвращает агрегированный массив с базовыми полями (firstName, lastName,
 * birthDate, gender, snils, inn, trusted, email, mobile).  Дополнительные
 * запросы /ctts (контакты), /docs (документы), /orgs (организации) делаются
 * по необходимости.
 *
 * @return array<string, mixed>
 */
function esia_fetch_profile(string $accessToken, string $oid): array
{
    $cfg = esia_config();
    $eps = esia_endpoints($cfg);

    $base = str_replace('{oid}', urlencode($oid), $eps['rs_prns']);
    $main = esia_http_get($base, ["Authorization: Bearer $accessToken"]);
    if ($main['code'] !== 200) {
        throw new RuntimeException(
            "ESIA /rs/prns HTTP {$main['code']}: " . mb_substr($main['body'], 0, 300)
        );
    }
    $person = json_decode($main['body'], true) ?: [];

    $contactsUrl = str_replace('{oid}', urlencode($oid), $eps['rs_ctts']);
    $contacts = esia_http_get($contactsUrl, ["Authorization: Bearer $accessToken"]);
    $email    = '';
    $mobile   = '';
    if ($contacts['code'] === 200) {
        $cj = json_decode($contacts['body'], true) ?: [];
        foreach (($cj['elements'] ?? []) as $el) {
            if (!empty($el['type']) && !empty($el['value'])) {
                if ($el['type'] === 'EML' && $email === '') {
                    $email = (string) $el['value'];
                } elseif ($el['type'] === 'MBT' && $mobile === '') {
                    $mobile = (string) $el['value'];
                }
            }
        }
    }

    return [
        'oid'        => $oid,
        'firstName'  => (string) ($person['firstName']  ?? ''),
        'lastName'   => (string) ($person['lastName']   ?? ''),
        'middleName' => (string) ($person['middleName'] ?? ''),
        'birthDate'  => (string) ($person['birthDate']  ?? ''), // dd.mm.YYYY
        'gender'     => (string) ($person['gender']     ?? ''), // M | F
        'snils'      => (string) ($person['snils']      ?? ''),
        'inn'        => (string) ($person['inn']        ?? ''),
        'trusted'    => (bool)   ($person['trusted']    ?? false),
        'email'      => $email,
        'mobile'     => $mobile,
        'raw_person' => $person,
    ];
}

/**
 * Логаут: отзываем access_token в ЕСИА (best effort, ошибки не критичны).
 */
function esia_revoke_token(string $accessToken): void
{
    $cfg = esia_config();
    $eps = esia_endpoints($cfg);
    $resp = esia_http_post(
        $eps['revoke'],
        http_build_query([
            'client_id' => $cfg['client_id'],
            'token'     => $accessToken,
        ]),
        ['Content-Type: application/x-www-form-urlencoded']
    );
    esia_log('REVOKE', ['http' => $resp['code']]);
}

/* ------------------------------------------------------------------ */
/*  internal: HTTP + log                                              */
/* ------------------------------------------------------------------ */

function esia_http_post(string $url, string $body, array $headers): array
{
    return esia_http_request('POST', $url, $body, $headers);
}

function esia_http_get(string $url, array $headers): array
{
    return esia_http_request('GET', $url, null, $headers);
}

function esia_http_request(string $method, string $url, ?string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        esia_log('HTTP-ERROR', ['method' => $method, 'url' => $url, 'err' => $err]);
        return ['code' => 0, 'body' => '', 'error' => $err];
    }
    esia_log('HTTP', ['method' => $method, 'url' => $url, 'http' => $code]);
    return ['code' => $code, 'body' => (string) $resp];
}

function esia_log(string $event, array $ctx = []): void
{
    $cfg = esia_config();
    $line = sprintf(
        "%s [%s] %s %s\n",
        gmdate('Y-m-d\TH:i:s\Z'),
        $event,
        $cfg['env'],
        json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $path = $cfg['log_path'];
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
