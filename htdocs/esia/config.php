<?php
/**
 * htdocs/esia/config.php
 *
 * Конфигурация интеграции с ЕСИА (Госуслуги, OpenID Connect).
 *
 * Реальные значения client_id, client_secret и пути к ключу ЭП храним в
 * /htdocs/esia/.env  ИЛИ  в переменных окружения сервера.  Файл .env лежит
 * в .gitignore — НЕ коммитим.
 *
 * Регламент: «Технологический регламент взаимодействия с ЕСИА» v2.53,
 * протокол OpenID Connect.  Алгоритм подписи — ГОСТ Р 34.10-2012-256.
 */

if (!function_exists('esia_load_dotenv')) {
    function esia_load_dotenv(string $file): void
    {
        if (!is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $v = trim($v, " \t\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
}

esia_load_dotenv(__DIR__ . '/.env');

/**
 * Возвращает полную конфигурацию ЕСИА с дефолтами для тестового окружения.
 *
 * @return array<string, mixed>
 */
function esia_config(): array
{
    $env = static fn (string $k, string $default = ''): string =>
        (string) (getenv($k) ?: $default);

    $environment = $env('ESIA_ENV', 'test'); // test | prod
    $isProd = ($environment === 'prod');

    $hostDefault = $isProd
        ? 'https://esia.gosuslugi.ru'
        : 'https://esia-portal1.test.gosuslugi.ru';

    return [
        'env'             => $environment,
        'host'            => rtrim($env('ESIA_HOST', $hostDefault), '/'),
        'client_id'       => $env('ESIA_CLIENT_ID', ''),       // мнемоника ИС, напр. FORSAGE
        'client_secret'   => $env('ESIA_CLIENT_SECRET', ''),   // если выдан, иначе подпись JWT
        'redirect_uri'    => $env('ESIA_REDIRECT_URI', 'https://eraetp.app/esia/callback.php'),
        'scopes'          => array_filter(array_map('trim', explode(' ', $env(
            'ESIA_SCOPES',
            'openid fullname birthdate gender snils inn email mobile'
        )))),
        // Подпись запросов:
        //   - 'cryptopro'  : через cryptcp/csptest (требует КриптоПро CSP на сервере)
        //   - 'sidecar'    : через локальный Node.js-микросервис на :8401 (gostcrypto)
        //   - 'mock'       : псевдоподпись для unit-тестов (НЕ для реального ЕСИА)
        'signer_mode'     => $env('ESIA_SIGNER_MODE', 'sidecar'),
        'signer_url'      => $env('ESIA_SIGNER_URL', 'http://127.0.0.1:8401'),
        // Путь к контейнеру / .pfx / .p12 / папке КриптоПро / thumbprint
        'cert_thumbprint' => $env('ESIA_CERT_THUMBPRINT', ''),
        'cert_pem_path'   => $env('ESIA_CERT_PEM_PATH', ''),
        'key_pem_path'    => $env('ESIA_KEY_PEM_PATH', ''),
        'cert_password'   => $env('ESIA_CERT_PASSWORD', ''),
        // Куда писать журнал взаимодействия (требование регламента — 30 дней)
        'log_path'        => $env('ESIA_LOG_PATH', __DIR__ . '/../logs/esia.log'),
        // Принудительно проверять подпись ID Token из ЕСИА (рекомендовано, но требует
        // публичный ключ ЕСИА). Если false — токены принимаем по доверию к TLS-каналу.
        'verify_id_token' => filter_var($env('ESIA_VERIFY_ID_TOKEN', '0'), FILTER_VALIDATE_BOOL),
        // Время жизни state в секундах (защита от CSRF)
        'state_ttl'       => (int) $env('ESIA_STATE_TTL', '300'),
    ];
}

/**
 * URL-ы тестового и прод-окружения ЕСИА (OpenID Connect v2 endpoints).
 */
function esia_endpoints(?array $cfg = null): array
{
    $cfg = $cfg ?? esia_config();
    $base = $cfg['host'];
    return [
        'authorize'  => $base . '/aas/oauth2/v3/ac',
        'token'      => $base . '/aas/oauth2/v3/te',
        'logout'     => $base . '/idp/ext/Logout',
        'revoke'     => $base . '/aas/oauth2/v3/revoke',
        // Получение профиля субъекта (ОИД пользователя берётся из id_token)
        'rs_prns'    => $base . '/rs/prns/{oid}',
        // Контактные данные, документы, организации
        'rs_ctts'    => $base . '/rs/prns/{oid}/ctts',
        'rs_docs'    => $base . '/rs/prns/{oid}/docs',
        'rs_orgs'    => $base . '/rs/prns/{oid}/orgs',
        // OIDC discovery (для отладки)
        'discovery'  => $base . '/.well-known/openid-configuration',
    ];
}
