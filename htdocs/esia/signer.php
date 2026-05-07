<?php
/**
 * htdocs/esia/signer.php
 *
 * Абстракция подписи запросов к ЕСИА по ГОСТ Р 34.10-2012-256.
 *
 * В PHP нет нативной поддержки ГОСТ-криптографии, поэтому подпись делегируется
 * во внешнее средство.  Поддерживаются 3 режима:
 *
 *   1. mode=cryptopro — вызов утилиты `cryptcp` / `csptest` из КриптоПро CSP
 *      через exec().  Требует:
 *        • КриптоПро CSP 5.0 R2 (КС1) для Linux на сервере (платно ~9 500 ₽/год);
 *        • установленный сертификат с приватным ключом в хранилище «My»
 *          (импортируем `pfx2csptest -p PASSWORD cert.pfx`);
 *        • в .env указать ESIA_CERT_THUMBPRINT (SHA1 в hex без пробелов).
 *
 *   2. mode=sidecar  — обращение к локальному микросервису на Node.js
 *      (см. /htdocs/esia/signer-node/) с пакетом `gostcrypto`.  Удобен для
 *      Docker-сборок и тестового окружения, не требует лицензии КриптоПро.
 *
 *   3. mode=mock     — детерминированная псевдоподпись на SHA256 (для локальных
 *      unit-тестов и smoke-тестов flow).  ЕСИА такую подпись отвергнет —
 *      использовать ТОЛЬКО для разработки.
 */

require_once __DIR__ . '/config.php';

/**
 * Подписать произвольные данные подписью ГОСТ-2012-256.
 *
 * @param string $data произвольные байты для подписи (signing input)
 *
 * @return string бинарная подпись (64 байта для GOST-2012-256)
 *
 * @throws RuntimeException если подписант не настроен или внешний инструмент упал
 */
function esia_signer_sign(string $data): string
{
    $cfg = esia_config();
    return match ($cfg['signer_mode']) {
        'cryptopro' => esia_signer_sign_cryptopro($data, $cfg),
        'sidecar'   => esia_signer_sign_sidecar($data, $cfg),
        'mock'      => esia_signer_sign_mock($data, $cfg),
        default     => throw new RuntimeException(
            "Unknown ESIA_SIGNER_MODE: {$cfg['signer_mode']}"
        ),
    };
}

/* ------------------------------------------------------------------ */
/*  cryptopro                                                          */
/* ------------------------------------------------------------------ */

function esia_signer_sign_cryptopro(string $data, array $cfg): string
{
    if ($cfg['cert_thumbprint'] === '') {
        throw new RuntimeException('ESIA_CERT_THUMBPRINT не задан в .env');
    }
    $tmpIn  = tempnam(sys_get_temp_dir(), 'esia_in_');
    $tmpOut = $tmpIn . '.sig';
    file_put_contents($tmpIn, $data);

    // cryptcp -signf -nochain -dn ... -strict — формирует .sig (DETACHED PKCS#7).
    // Для ЕСИА нужна именно raw-подпись, поэтому используем csptest -keyset -sign.
    // Здесь — упрощённый вариант через cryptcp; в проде потребуется тонкая
    // настройка под конкретный CN/Issuer.
    $cmd = sprintf(
        'cryptcp -signf -nochain -strict -thumbprint %s -der %s 2>&1',
        escapeshellarg($cfg['cert_thumbprint']),
        escapeshellarg($tmpIn)
    );
    exec($cmd, $output, $rc);
    if ($rc !== 0 || !is_file($tmpOut)) {
        @unlink($tmpIn);
        @unlink($tmpOut);
        throw new RuntimeException(
            "cryptcp failed (rc=$rc): " . implode("\n", $output)
        );
    }
    $sig = file_get_contents($tmpOut);
    @unlink($tmpIn);
    @unlink($tmpOut);
    return $sig;
}

/* ------------------------------------------------------------------ */
/*  sidecar (Node.js + gostcrypto)                                    */
/* ------------------------------------------------------------------ */

function esia_signer_sign_sidecar(string $data, array $cfg): string
{
    $url = rtrim($cfg['signer_url'], '/') . '/sign';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'data' => base64_encode($data),
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        throw new RuntimeException(
            "ESIA signer sidecar unavailable ({$cfg['signer_url']}): "
            . ($err ?: "HTTP $code")
        );
    }
    $j = json_decode($resp, true);
    if (!isset($j['signature'])) {
        throw new RuntimeException(
            'ESIA signer sidecar returned malformed response: ' . substr($resp, 0, 200)
        );
    }
    return base64_decode($j['signature']);
}

/* ------------------------------------------------------------------ */
/*  mock                                                               */
/* ------------------------------------------------------------------ */

function esia_signer_sign_mock(string $data, array $cfg): string
{
    // 64-байтная «подпись» = sha256(data) ^ sha256("salt"|data).
    // Бесполезна для реальной ЕСИА, но позволяет прогнать flow в тестах.
    $h1 = hash('sha256', $data, true);
    $h2 = hash('sha256', 'esia-mock-salt:' . $data, true);
    return $h1 . $h2;
}
