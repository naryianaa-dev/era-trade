# Интеграция ERATRADE с ЕСИА (Госуслуги)

Реализует **OpenID Connect 1.0** клиент к ЕСИА согласно «Технологическому регламенту взаимодействия с ЕСИА» v2.53.

## Состав

```
htdocs/esia/
├── config.php          # загрузка .env, scope-ы, endpoints
├── jwt.php             # сборка/парсинг JWT (header.payload.signature)
├── signer.php          # абстракция подписи (cryptopro|sidecar|mock)
├── oidc_client.php     # authorize, exchange code, fetch profile
├── login.php           # /esia/login.php — initiation endpoint
├── callback.php        # /esia/callback.php — redirect_uri (1-в-1 как в форме!)
├── logout.php          # /esia/logout.php — revoke + session_destroy
├── users_link.php      # маппинг профиля ЕСИА в users
├── migrate.php         # ленивая миграция users (esia_oid, snils, …)
├── .env.example        # шаблон конфига (реальный .env в .gitignore)
└── signer-node/        # локальный node.js-микросервис подписи
    ├── server.js
    └── package.json
```

## Endpoint-ы (для регламента)

| Назначение | URL |
|---|---|
| Иницирование входа | `https://eraetp.app/esia/login.php` |
| Redirect URI       | `https://eraetp.app/esia/callback.php` |
| Logout / Revoke    | `https://eraetp.app/esia/logout.php` |

`redirect_uri` обязан совпадать **1-в-1** с тем, что указан в Приложении Г, поданном в Минцифру.

## Настройка после получения тестовых credentials

1. После одобрения SCR#6142301 Минцифра пришлёт `client_id` (мнемонику) и тестовое окружение.
2. Скопируй шаблон:
   ```bash
   cp htdocs/esia/.env.example htdocs/esia/.env
   ```
3. Заполни `ESIA_CLIENT_ID=FORSAGE` (или то что выдала Минцифра).
4. Выбери режим подписи:

   - **Локальная разработка / тестовая ЕСИА**: `ESIA_SIGNER_MODE=sidecar`
     ```bash
     cd htdocs/esia/signer-node
     npm install
     SIGNER_KEY_HEX=<hex-приватный-ключ-ГОСТ-2012-256> npm start
     ```
     Откроет HTTP на `127.0.0.1:8401`.

   - **Прод-сервер с КриптоПро CSP**: `ESIA_SIGNER_MODE=cryptopro`
     - Установи КриптоПро CSP 5.0 R2 для Linux (~9 500 ₽/год):
       https://cryptopro.ru/products/csp/downloads
     - Импортируй контейнер с приватным ключом:
       ```bash
       /opt/cprocsp/bin/amd64/csptest -keyset -container '\\.\HDIMAGE\era-trade' -addcontainer
       ```
     - Скопируй сертификат в хранилище «My»:
       ```bash
       certmgr -inst -store uMy -file era-trade.cer
       ```
     - В `.env` укажи `ESIA_CERT_THUMBPRINT=<sha1>`.

5. Прогнать миграцию users:
   ```bash
   php htdocs/esia/migrate.php
   ```
   (либо она применится сама при первом callback)

6. Отдельно сконфигурируй `htdocs/logs/` — должен быть writable для `www-data`,
   но недоступен через web (`.htaccess` уже положен).

## Тестовый сценарий (после получения credentials)

1. Открой `https://eraetp.app/esia/login.php`.
2. Тебя редиректит на `esia-portal1.test.gosuslugi.ru/aas/oauth2/v3/ac?...`.
3. Логинишься тестовым пользователем (Минцифра пришлёт логин/пароль вида
   `9999990001 / Test1234!`).
4. Подтверждаешь согласие на передачу данных.
5. Возвращаешься на `eraetp.app/esia/callback.php?code=...&state=...`.
6. Проверь:
   - В БД появилась запись в `users` с заполненным `esia_oid`.
   - В `htdocs/logs/esia.log` есть строки `AUTHORIZE-REDIRECT`, `HTTP`, `LOGIN-OK`.
   - В сессии заведены `user_id`, `user_name`, `esia_access_token`, `esia_oid`.

## Безопасность

- `state` — рандомные 32 hex-символа, проверяется в callback (CSRF-защита).
- `nonce` — рандомные 32 hex-символа, сохраняется в сессии и сверяется с `id_token.nonce`.
- TLS-проверка peer/host включена (`CURLOPT_SSL_VERIFY*`).
- Токены и `oid` хранятся **только в сессии**, не в URL и не в логах.
- `esia.log` записывает HTTP-метаданные (метод, URL, код), но не тело запросов
  (там подпись и токены).

## Что нужно для прохождения автотестов ЕСИА

См. полный чек-лист в [`docs/ESIA_HANDOFF.md`](../../docs/ESIA_HANDOFF.md).
Базовое:

- [x] HTTPS на eraetp.app (есть).
- [x] redirect_uri в Приложении Г = `https://eraetp.app/esia/callback.php` (заявлен).
- [x] scope из формы передаются 1-в-1 в `scope=` параметре (8 шт).
- [x] state + nonce обязательны и проверяются.
- [x] timestamp в формате `Y.m.d H:i:s O` (UTC).
- [x] Алгоритм подписи `GOST3410_2012_256`.
- [x] Журналирование запросов в `esia.log` (30 дней).
- [ ] Реальный сертификат СКЗИ в подписи (КриптоПро CSP, лицензия).
- [ ] Подача отчёта о прохождении автотестов в Минцифру (Приложение И).
