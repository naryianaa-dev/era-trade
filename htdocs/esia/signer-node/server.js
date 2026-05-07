/**
 * ESIA signer sidecar.
 *
 * Поднимает HTTP-сервер на http://127.0.0.1:8401 (по умолчанию) с одним
 * endpoint POST /sign, который принимает JSON `{"data": "<base64>"}`,
 * подписывает данные ГОСТ Р 34.10-2012-256 и возвращает
 * `{"signature": "<base64>"}`.
 *
 * Используется в режиме ESIA_SIGNER_MODE=sidecar.
 *
 * ----------------------------------------------------------------------------
 * РЕАЛИЗАЦИЯ ПОДПИСИ
 * ----------------------------------------------------------------------------
 * Готового npm-пакета с сертифицированной ГОСТ-подписью под open-source-PHP
 * стека нет.  Этот сидекар поддерживает 2 backend-а, выбираемых через
 * переменную окружения SIGNER_BACKEND:
 *
 *   1. SIGNER_BACKEND=gostcrypto  (по умолчанию для локальной разработки)
 *      Использует чистый JS-пакет `gostcrypto` (или `node-gost-crypto`).
 *      Подходит для прохождения тестового стенда ЕСИА; для прод-ЕСИА
 *      Минцифра требует сертифицированный СКЗИ — переключиться на «openssl-gost».
 *
 *   2. SIGNER_BACKEND=openssl-gost
 *      Шеллится в `openssl dgst -engine gost -sign key.pem`.  Требует
 *      OpenSSL 1.1+ с собранным GOST-engine; на Debian/Ubuntu — пакет
 *      `libengine-gost-openssl1.1`.  Ключ — в формате PEM (PKCS#8) с
 *      ГОСТ-2012-256 параметрами.
 *
 * Конфигурация:
 *   SIGNER_PORT          (default 8401)
 *   SIGNER_BACKEND       (gostcrypto|openssl-gost)  default: gostcrypto
 *   SIGNER_KEY_PEM_PATH  путь к key.pem (для openssl-gost)
 *   SIGNER_KEY_PASSWORD  пароль ключа (если есть)
 *   SIGNER_KEY_HEX       hex-приватный ключ (для gostcrypto)
 *
 * Запуск:
 *   node server.js
 *   PORT=8401 SIGNER_BACKEND=openssl-gost SIGNER_KEY_PEM_PATH=./era-trade.key.pem node server.js
 */

const http = require('node:http');
const { spawnSync } = require('node:child_process');
const fs   = require('node:fs');
const path = require('node:path');

const PORT     = parseInt(process.env.SIGNER_PORT || '8401', 10);
const BACKEND  = (process.env.SIGNER_BACKEND || 'gostcrypto').toLowerCase();
const KEY_PEM  = process.env.SIGNER_KEY_PEM_PATH || '';
const KEY_PWD  = process.env.SIGNER_KEY_PASSWORD || '';
const KEY_HEX  = process.env.SIGNER_KEY_HEX || '';

function logLine(level, msg, ctx = {}) {
  const line = JSON.stringify({ ts: new Date().toISOString(), level, msg, ...ctx });
  process.stdout.write(line + '\n');
}

/**
 * Backend 1: чистый JS через gostcrypto.
 */
function signGostCrypto(data) {
  let gost;
  try {
    gost = require('gostcrypto');
  } catch (e) {
    throw new Error(
      'Пакет gostcrypto не установлен. Запустите: npm install gostcrypto'
    );
  }
  if (!KEY_HEX) {
    throw new Error('SIGNER_KEY_HEX не задан (hex-приватный ключ ГОСТ-2012-256)');
  }
  const signer = gost.signature({
    name:    'GOST R 34.10',
    version: 2012,
    length:  256,
    mode:    'SIGN',
    procreator: 'CP',
    keySize: 32,
    hash:    'GOST R 34.11',
  });
  signer.sign({ value: Buffer.from(KEY_HEX, 'hex') }, data);
  return signer.signature;
}

/**
 * Backend 2: openssl с gost-engine.
 */
function signOpensslGost(data) {
  if (!KEY_PEM || !fs.existsSync(KEY_PEM)) {
    throw new Error(`SIGNER_KEY_PEM_PATH не найден: ${KEY_PEM}`);
  }
  const args = [
    'dgst', '-engine', 'gost',
    '-md_gost12_256',
    '-sign', KEY_PEM,
  ];
  if (KEY_PWD) {
    args.push('-passin', `pass:${KEY_PWD}`);
  }
  const r = spawnSync('openssl', args, {
    input: data,
    encoding: 'buffer',
    timeout: 5000,
  });
  if (r.status !== 0) {
    throw new Error(
      'openssl gost sign failed: ' + (r.stderr ? r.stderr.toString() : `rc=${r.status}`)
    );
  }
  return r.stdout;
}

function sign(data) {
  switch (BACKEND) {
    case 'openssl-gost': return signOpensslGost(data);
    case 'gostcrypto':   return signGostCrypto(data);
    default:
      throw new Error(`Unknown SIGNER_BACKEND: ${BACKEND}`);
  }
}

const server = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ ok: true, backend: BACKEND }));
  }
  if (req.method !== 'POST' || req.url !== '/sign') {
    res.writeHead(404);
    return res.end('Not Found');
  }
  let body = '';
  req.on('data', (c) => { body += c.toString('utf8'); });
  req.on('end', () => {
    try {
      const j = JSON.parse(body);
      if (typeof j.data !== 'string') {
        throw new Error('field "data" (base64) is required');
      }
      const buf = Buffer.from(j.data, 'base64');
      const sig = sign(buf);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ signature: Buffer.from(sig).toString('base64') }));
      logLine('info', 'sign-ok', { bytes: buf.length, sigBytes: sig.length });
    } catch (e) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: e.message }));
      logLine('error', 'sign-fail', { err: e.message });
    }
  });
});

server.listen(PORT, '127.0.0.1', () => {
  logLine('info', 'esia-signer started', { port: PORT, backend: BACKEND });
});
