<?php
/**
 * htdocs/esia/jwt.php
 *
 * Сборка JWT с подписью ГОСТ-2012-256 для аутентификации клиента в ЕСИА
 * (RFC 7523 — JWT Profile for OAuth 2.0 Client Authentication).
 *
 * Структура: header.payload.signature, где signature — ГОСТ-подпись
 * SHA256("$header_b64.$payload_b64").
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/signer.php';

/**
 * Кодирование в base64url (RFC 7515 §2).
 */
function esia_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function esia_b64url_decode(string $data): string
{
    $rem = strlen($data) % 4;
    if ($rem) {
        $data .= str_repeat('=', 4 - $rem);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Собрать client_assertion JWT для метода аутентификации
 * `private_key_jwt` (RFC 7523).
 *
 * @param string             $clientId   мнемоника ИС (sub + iss)
 * @param string             $audience   `aud` — endpoint /token ЕСИА
 * @param int                $ttl        время жизни в секундах (по умолч. 300)
 * @param array<string,mixed> $extraClaims дополнительные claims (scope, redirect_uri, …)
 *
 * @return string собранный и подписанный JWT
 */
function esia_make_client_assertion(
    string $clientId,
    string $audience,
    int $ttl = 300,
    array $extraClaims = []
): string {
    $now = time();
    $jti = bin2hex(random_bytes(16));

    $header = [
        'alg' => 'GOST3410_2012_256',
        'typ' => 'JWT',
    ];

    $payload = array_merge([
        'iss' => $clientId,
        'sub' => $clientId,
        'aud' => $audience,
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $ttl,
        'jti' => $jti,
    ], $extraClaims);

    $headerB64  = esia_b64url(json_encode(
        $header,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
    $payloadB64 = esia_b64url(json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));

    $signingInput = $headerB64 . '.' . $payloadB64;
    $signature    = esia_signer_sign($signingInput);

    return $signingInput . '.' . esia_b64url($signature);
}

/**
 * Разобрать JWT (без проверки подписи) и вернуть [header, payload, signature].
 *
 * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: string}
 */
function esia_parse_jwt(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Invalid JWT format');
    }
    [$h, $p, $s] = $parts;
    return [
        json_decode(esia_b64url_decode($h), true) ?? [],
        json_decode(esia_b64url_decode($p), true) ?? [],
        esia_b64url_decode($s),
    ];
}
