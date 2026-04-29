<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($lang)) $lang = $_SESSION['lang'] ?? 'ru';

/* Telegram Login Widget config — read from tg_config.php (gitignored) so the
   real bot creds never end up in the public repo. Falls back to env vars. */
$tg_bot_token    = '';
$tg_bot_username = '';
$tg_cfg = __DIR__ . '/tg_config.php';
if (is_file($tg_cfg)) {
    require $tg_cfg;
    if (isset($bot_token))    { $tg_bot_token    = $bot_token; }
    if (isset($bot_username)) { $tg_bot_username = $bot_username; }
}
if (!$tg_bot_token)    { $tg_bot_token    = getenv('TELEGRAM_BOT_TOKEN')    ?: ''; }
if (!$tg_bot_username) { $tg_bot_username = getenv('TELEGRAM_BOT_USERNAME') ?: ''; }
$tg_configured = $tg_bot_token && $tg_bot_username
    && $tg_bot_token    !== 'YOUR_BOT_TOKEN'
    && $tg_bot_username !== 'YOUR_BOT_USERNAME';
/* Bot id (numeric prefix of the token) is needed for the Telegram OAuth
   fallback URL used when the widget iframe fails to render (e.g. iOS Safari). */
$tg_bot_id = '';
if ($tg_configured && strpos($tg_bot_token, ':') !== false) {
    $tg_bot_id = substr($tg_bot_token, 0, strpos($tg_bot_token, ':'));
}
$tg_auth_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
    . $_SERVER['HTTP_HOST'] . '/telegram_auth.php';
?>

<style>
#auth-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.85);
    z-index: 9999;
    justify-content: center;
    align-items: flex-start;
    backdrop-filter: blur(6px);
    padding: 16px;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
#auth-modal-overlay.active { display: flex; }

#auth-modal-content {
    background: #1e293b;
    width: 100%;
    max-width: 480px;
    border-radius: 24px;
    border: 1px solid #334155;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6);
    position: relative;
    margin: 20px auto;
    /* Окно не выходит за высоту экрана даже на самых низких устройствах —
       внутри включён собственный скролл. dvh лучше vh, потому что учитывает
       реальный viewport без адресной строки браузера. */
    max-height: calc(100vh - 40px);
    max-height: calc(100dvh - 40px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#auth-modal-close {
    position: absolute;
    top: 14px; right: 16px;
    background: none; border: none;
    color: #64748b; font-size: 28px;
    cursor: pointer; line-height: 1;
    z-index: 1; width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; transition: all 0.2s;
}
#auth-modal-close:hover { background: rgba(100,116,139,0.2); }

.auth-tabs {
    display: flex;
    background: #0f172a;
    padding: 5px; gap: 4px;
}
.auth-tab-btn {
    flex: 1; padding: 14px; border: none;
    background: transparent; color: #64748b;
    cursor: pointer; font-weight: bold;
    border-radius: 10px; font-size: 14px;
    transition: all 0.2s;
}
.auth-tab-btn.active { background: #1e293b; color: #fff; }

.auth-content {
    padding: 28px;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    flex: 1 1 auto;
}

.auth-input {
    width: 100%; padding: 14px;
    border-radius: 10px;
    background: #0f172a;
    border: 1.5px solid #334155;
    color: #fff; font-size: 15px;
    margin-bottom: 10px;
    box-sizing: border-box;
    outline: none; transition: border-color 0.2s;
}
.auth-input:focus { border-color: #3b82f6; }

.auth-label {
    display: block;
    font-size: 12px; font-weight: 600;
    color: #64748b; margin-bottom: 4px;
    text-transform: uppercase; letter-spacing: 0.05em;
}

.auth-file-block {
    background: #0f172a;
    border: 1.5px dashed #334155;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 10px;
}
.auth-file-block:hover { border-color: #3b82f6; }
.auth-file-input {
    width: 100%; font-size: 13px;
    color: #94a3b8; cursor: pointer;
    background: none; border: none; outline: none;
    padding: 4px 0;
}
.auth-file-hint {
    font-size: 11px; color: #475569;
    margin-top: 4px;
}

.auth-checkbox-row {
    display: flex; align-items: flex-start;
    gap: 10px; margin: 12px 0;
    color: #94a3b8; font-size: 13px;
    cursor: pointer;
}
.auth-checkbox-row input[type="checkbox"] {
    width: 16px; height: 16px;
    accent-color: #3b82f6;
    margin-top: 2px; flex-shrink: 0;
}

.auth-express-block {
    background: rgba(59,130,246,0.08);
    border: 1.5px solid #3b82f6;
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 12px;
    font-size: 12px; color: #60a5fa;
}

.auth-btn {
    width: 100%; padding: 15px; border: none;
    border-radius: 12px; color: #fff;
    font-weight: bold; cursor: pointer;
    font-size: 16px; transition: all 0.2s;
}
.auth-btn-primary { background: #3b82f6; }
.auth-btn-primary:hover { background: #2563eb; }
.auth-btn-telegram {
    background: linear-gradient(135deg, #2AABEE, #229ED9);
    display: flex; align-items: center;
    justify-content: center; gap: 8px;
}
.auth-btn-telegram:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(42,171,238,0.3);
}

.auth-divider {
    display: flex; align-items: center;
    gap: 12px; margin: 16px 0;
}
.auth-divider-line { flex: 1; height: 1px; background: #334155; }
.auth-divider-text { color: #64748b; font-size: 13px; }

#auth-msg {
    min-height: 20px; font-size: 13px;
    font-weight: bold; margin-bottom: 14px;
    text-align: center; padding: 10px;
    border-radius: 8px;
}
#auth-msg.success { background: #d1fae5; color: #065f46; }
#auth-msg.error   { background: #fee2e2; color: #991b1b; }

@media(max-width:768px) {
    #auth-modal-content { max-width: 95%; margin: 10px; }
    .auth-content { padding: 24px 20px; }
    .auth-tab-btn { padding: 12px; font-size: 13px; }
    .auth-input { font-size: 16px; padding: 12px; }
    .auth-btn { font-size: 15px; padding: 14px; }
}
@media(max-width:480px) {
    #auth-modal-overlay { padding: 0; align-items: stretch; }
    #auth-modal-content {
        max-width: 100%; width: 100%;
        border-radius: 20px 20px 0 0;
        position: fixed; bottom: 0; top: auto;
        left: 0; right: 0; margin: 0;
        /* Боттом-шит прячется за экран, если не ограничить высоту. */
        max-height: 92vh;
        max-height: 92dvh;
    }
    #auth-modal-close { top: 12px; right: 12px; width: 32px; height: 32px; font-size: 24px; }
    .auth-content { padding: 20px 16px; padding-bottom: max(20px, env(safe-area-inset-bottom)); }
    .auth-input { font-size: 16px; padding: 13px; }
    .auth-btn { padding: 13px; font-size: 15px; }
}
@media(max-width:375px) {
    .auth-content { padding: 16px 12px; }
    .auth-tab-btn { padding: 10px; font-size: 12px; }
    .auth-input { padding: 11px; font-size: 15px; }
    .auth-btn { padding: 12px; font-size: 14px; }
}
@media(max-height:600px) and (orientation:landscape) {
    #auth-modal-content { max-height: 92vh; max-height: 92dvh; border-radius: 16px; position: relative; bottom: auto; }
    .auth-content { padding: 16px; }
    .auth-tab-btn { padding: 10px; }
    .auth-input { padding: 10px; margin-bottom: 8px; }
    .auth-btn { padding: 11px; }
    .auth-divider { margin: 10px 0; }
}

/* Карточки выбора статуса в форме регистрации */
.auth-status-block {
    margin-bottom: 14px;
    background: rgba(15,23,42,0.6);
    border: 1px solid #334155;
    border-radius: 12px;
    padding: 14px;
}
.auth-status-title {
    font-size: 12px; font-weight: 700;
    color: #94a3b8; text-transform: uppercase;
    letter-spacing: 0.05em; margin-bottom: 10px;
}
.auth-status-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.auth-status-card {
    cursor: pointer;
    padding: 12px 8px;
    border-radius: 10px;
    border: 1.5px solid #334155;
    background: #0f172a;
    color: #cbd5e1;
    text-align: center;
    transition: all 0.18s;
    display: flex; flex-direction: column;
    align-items: center; gap: 4px;
    position: relative;
}
.auth-status-card:hover { border-color: #3b82f6; }
.auth-status-card.selected {
    border-color: #3b82f6;
    background: rgba(59,130,246,0.12);
    color: #fff;
}
.auth-status-card .icon { font-size: 22px; line-height: 1; }
.auth-status-card .name { font-size: 12px; font-weight: 700; }
.auth-status-card .price { font-size: 11px; color: #94a3b8; }
.auth-status-card.selected .price { color: #60a5fa; }
.auth-status-card input[type="radio"] { display: none; }

/* Блок выбора способа оплаты (появляется при выборе Ответственный). */
.auth-payment-block {
    display: none;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed #334155;
    font-size: 12px;
    color: #cbd5e1;
}
.auth-payment-block.visible { display: block; }
.auth-payment-row {
    display: flex; align-items: center;
    gap: 8px; padding: 8px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s;
}
.auth-payment-row:hover { background: rgba(59,130,246,0.08); }
.auth-payment-row input[type="radio"] { accent-color: #3b82f6; }
.auth-payment-info {
    margin-top: 10px;
    background: rgba(34,197,94,0.08);
    border: 1px solid rgba(34,197,94,0.4);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 11px;
    color: #86efac;
    line-height: 1.5;
}

/* Блок СОГЛАСИЯ ОРГАНИЗАТОРА (12 мес бесплатно). */
.auth-organizer-info {
    display: none;
    margin-top: 10px;
    background: rgba(168,85,247,0.08);
    border: 1px solid rgba(168,85,247,0.4);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 11px;
    color: #c4b5fd;
    line-height: 1.5;
}
.auth-organizer-info.visible { display: block; }

/* Блок «спасибо + QR» — показывается прямо в модалке после успешной регистрации
   с платным статусом. Никакого редиректа: пользователь видит QR-код, реквизиты
   и кнопку «Открыть квитанцию» (откроется в новом окне). */
.auth-pay-result { display: none; padding: 6px 4px 4px; }
.auth-pay-result.visible { display: block; }
.auth-pay-result h3 {
    margin: 0 0 6px; color: #f8fafc; font-size: 16px; font-weight: 700;
}
.auth-pay-result .auth-pay-sub {
    margin: 0 0 14px; color: #94a3b8; font-size: 12px;
}
.auth-pay-qr {
    background: #fff; padding: 14px; border-radius: 14px; text-align: center;
    margin: 0 auto 12px; max-width: 280px;
}
.auth-pay-qr img { width: 100%; height: auto; display: block; }
.auth-pay-details {
    background: rgba(15,23,42,0.7); border: 1px solid #334155;
    border-radius: 10px; padding: 12px 14px; font-size: 12px;
    color: #cbd5e1; line-height: 1.7;
}
.auth-pay-details b { color: #f1f5f9; }
.auth-pay-actions { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.auth-pay-actions button, .auth-pay-actions a {
    flex: 1; min-width: 130px; padding: 10px 14px;
    border-radius: 10px; font-size: 13px; font-weight: 700;
    border: none; cursor: pointer; text-align: center; text-decoration: none;
    transition: transform .15s ease, box-shadow .15s ease;
}
.auth-pay-actions .btn-receipt {
    background: linear-gradient(135deg, #0088cc, #38bdf8); color: #fff;
}
.auth-pay-actions .btn-done {
    background: rgba(34,197,94,0.15); color: #4ade80;
    border: 1px solid rgba(34,197,94,0.4);
}
.auth-pay-actions button:hover, .auth-pay-actions a:hover { transform: translateY(-1px); }

@media(max-width:380px) {
    .auth-status-grid { grid-template-columns: 1fr; }
    .auth-status-card { flex-direction: row; justify-content: flex-start; padding: 10px 12px; gap: 10px; text-align: left; }
    .auth-status-card .name { flex: 1; }
}
@media(min-width:1024px) { #auth-modal-content { max-width: 520px; } }

/* === Live-payment в модалке регистрации (статус «Ответственный») ========== */
.auth-pay-tabs {
    display: flex;
    gap: 4px;
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 10px;
    padding: 4px;
    margin-bottom: 12px;
}
.auth-pay-tab {
    flex: 1;
    padding: 10px;
    background: transparent;
    color: #94a3b8;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
    transition: all .18s;
}
.auth-pay-tab:hover { color: #cbd5e1; }
.auth-pay-tab.active {
    background: #1e293b;
    color: #fff;
    box-shadow: 0 0 0 1px #3b82f6 inset;
}
.auth-pay-tab-panel { animation: payPanelIn .2s ease; }
@keyframes payPanelIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

.auth-pay-qr-box {
    background: #fff;
    border-radius: 12px;
    padding: 12px;
    text-align: center;
    margin: 0 auto 10px;
    max-width: 260px;
}
.auth-pay-qr-box img {
    display: block;
    width: 100%;
    height: auto;
    border-radius: 6px;
}

.auth-pay-hint {
    font-size: 11px;
    color: #94a3b8;
    line-height: 1.55;
    background: rgba(15,23,42,.55);
    border: 1px solid #334155;
    border-radius: 8px;
    padding: 8px 10px;
}

.auth-pay-receipt-btn {
    margin-top: 4px;
    background: linear-gradient(135deg, #0088cc, #38bdf8);
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.auth-pay-receipt-btn:hover { background: linear-gradient(135deg, #0077b3, #2bb0ed); }

.auth-pay-details-mini {
    margin-top: 10px;
    font-size: 11px;
    color: #cbd5e1;
    line-height: 1.7;
    background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.4);
    border-radius: 8px;
    padding: 8px 10px;
}
.auth-pay-details-mini b { color: #f1f5f9; }
</style>

<div id="auth-modal-overlay">
    <div id="auth-modal-content">
        <button id="auth-modal-close" onclick="closeAuth()" aria-label="Закрыть">×</button>

        <div class="auth-tabs">
            <button id="auth-tab-btn-login" class="auth-tab-btn active" onclick="authTab('login')">
                <?= $lang === 'en' ? 'SIGN IN' : 'ВХОД' ?>
            </button>
            <button id="auth-tab-btn-register" class="auth-tab-btn" onclick="authTab('register')">
                <?= $lang === 'en' ? 'REGISTER' : 'РЕГИСТРАЦИЯ' ?>
            </button>
        </div>

        <div class="auth-content">
            <div id="auth-msg"></div>

            <!-- ВХОД -->
            <div id="auth-tab-login">
                <input id="auth-l-user" type="text" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Username' : 'Логин' ?>"
                       autocomplete="username"
                       onkeydown="if(event.key==='Enter')authDoLogin()">
                <input id="auth-l-pass" type="password" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Password' : 'Пароль' ?>"
                       autocomplete="current-password"
                       onkeydown="if(event.key==='Enter')authDoLogin()"
                       style="margin-bottom:18px;">
                <button class="auth-btn auth-btn-primary" onclick="authDoLogin()" style="margin-bottom:12px;">
                    <?= $lang === 'en' ? 'SIGN IN' : 'ВОЙТИ' ?>
                </button>
                <div class="auth-divider">
                    <div class="auth-divider-line"></div>
                    <span class="auth-divider-text"><?= $lang === 'en' ? 'or' : 'или' ?></span>
                    <div class="auth-divider-line"></div>
                </div>
                <?php if ($tg_configured): ?>
                <!-- Контейнер для официального Telegram Login Widget. Виджет
                     инжектится динамически в openAuth() — это нужно для iOS
                     Safari, где iframe виджета не рендерится корректно, если
                     скрипт исполняется внутри display:none-родителя. -->
                <div id="auth-tg-widget"
                     class="auth-tg-widget"
                     style="display:flex;justify-content:center;min-height:46px;"
                     data-tg-bot="<?= htmlspecialchars($tg_bot_username) ?>"
                     data-tg-bot-id="<?= htmlspecialchars($tg_bot_id) ?>"
                     data-tg-auth-url="<?= htmlspecialchars($tg_auth_url) ?>"></div>
                <!-- Резервная кнопка для случаев, когда виджет всё-таки
                     не загрузился (старый iOS, отключённый JS-iframe и т.п.). -->
                <noscript>
                    <a class="auth-btn auth-btn-telegram"
                       href="https://oauth.telegram.org/auth?bot_id=<?= htmlspecialchars($tg_bot_id) ?>&origin=<?= htmlspecialchars(urlencode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'])) ?>&request_access=write&return_to=<?= htmlspecialchars(urlencode($tg_auth_url)) ?>"
                       style="text-decoration:none;">
                        <?= $lang === 'en' ? 'Sign in with Telegram' : 'Войти через Telegram' ?>
                    </a>
                </noscript>
                <?php else: ?>
                <button class="auth-btn auth-btn-telegram" onclick="alert('Telegram \u0432\u0445\u043e\u0434 \u043f\u043e\u043a\u0430 \u043d\u0435 \u043d\u0430\u0441\u0442\u0440\u043e\u0435\u043d')" type="button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/>
                    </svg>
                    <?= $lang === 'en' ? 'Sign in with Telegram' : 'Войти через Telegram' ?>
                </button>
                <?php endif; ?>

                <!-- Вход через ЭЦП. На время разработки кнопка отключена и
                     помечена «Скоро». Когда подключим боевую крипту (КриптоПро CSP
                     на сервере + плагин у юзера) — поменять disabled-стиль на
                     активный и вернуть onclick="ECP.login()". -->
                <button type="button" class="auth-btn" disabled
                        title="<?= $lang === 'en' ? 'In development' : 'В разработке' ?>"
                        style="margin-top:10px; background:#1e293b; color:#94a3b8; border:1px dashed #475569; cursor:not-allowed; opacity:0.7; position:relative;">
                    🔐 <?= $lang === 'en' ? 'Sign in with ЭЦП' : 'Войти через ЭЦП' ?>
                    <span style="margin-left:8px; padding:2px 8px; background:#fbbf24; color:#0f172a; border-radius:10px; font-size:10px; font-weight:600; white-space:nowrap;">
                        <?= $lang === 'en' ? 'IN DEV' : 'В РАЗРАБОТКЕ' ?>
                    </span>
                </button>
            </div>

            <!-- РЕГИСТРАЦИЯ -->
            <div id="auth-tab-register" style="display:none;">

                <!-- Выбор статуса -->
                <div class="auth-status-block">
                    <div class="auth-status-title">
                        <?= $lang === 'en' ? 'Choose your status' : 'Выберите ваш статус' ?>
                    </div>
                    <div class="auth-status-grid">
                        <label class="auth-status-card selected" data-utype="respected" onclick="authSelectStatus('respected')">
                            <input type="radio" name="auth-r-utype" value="respected" checked>
                            <span class="icon">🤝</span>
                            <span class="name"><?= $lang === 'en' ? 'Respected' : 'Уважаемый' ?></span>
                            <span class="price"><?= $lang === 'en' ? 'Free' : 'Бесплатно' ?></span>
                        </label>
                        <label class="auth-status-card" data-utype="responsible" onclick="authSelectStatus('responsible')">
                            <input type="radio" name="auth-r-utype" value="responsible">
                            <span class="icon">🛡️</span>
                            <span class="name"><?= $lang === 'en' ? 'Responsible' : 'Ответственный' ?></span>
                            <span class="price">8 000 ₽</span>
                        </label>
                        <label class="auth-status-card" data-utype="organizer" onclick="authSelectStatus('organizer')">
                            <input type="radio" name="auth-r-utype" value="organizer">
                            <span class="icon">👔</span>
                            <span class="name"><?= $lang === 'en' ? 'Organizer' : 'Организатор' ?></span>
                            <span class="price"><?= $lang === 'en' ? 'Free 12 months' : 'Бесплатно 12 мес' ?></span>
                        </label>
                    </div>

                    <!-- Способ оплаты для статуса «Ответственный» — оплата доступна
                         сразу при выборе статуса, без ожидания регистрации. Два
                         режима: QR-код (показывается прямо в модалке) и квитанция
                         с реквизитами (открывается в отдельном окне с печатью,
                         QR-кодом и кнопкой «Закрыть»). -->
                    <div id="auth-payment-block" class="auth-payment-block">
                        <div style="font-weight:600; margin-bottom:8px; color:#cbd5e1;">
                            <span id="auth-pay-title"><?= $lang === 'en' ? 'Payment for «Responsible»' : 'Оплата статуса «Ответственный»' ?></span>
                            <span id="auth-pay-total" style="float:right; color:#60a5fa; font-weight:700;">8 000 ₽</span>
                        </div>

                        <!-- Переключатель способов оплаты -->
                        <div class="auth-pay-tabs">
                            <button type="button" class="auth-pay-tab active"
                                    data-method="qr"
                                    onclick="authSelectPayMethod('qr')">
                                <?= $lang === 'en' ? 'QR-code' : 'QR-код' ?>
                            </button>
                            <button type="button" class="auth-pay-tab"
                                    data-method="receipt"
                                    onclick="authSelectPayMethod('receipt')">
                                <?= $lang === 'en' ? 'Receipt' : 'Квитанция' ?>
                            </button>
                        </div>
                        <input type="hidden" name="auth-r-payment" id="auth-r-payment" value="qr">

                        <!-- QR-код: показывается сразу при выборе «Ответственный» -->
                        <div id="auth-pay-qr-tab" class="auth-pay-tab-panel">
                            <div class="auth-pay-qr-box">
                                <img id="auth-pay-qr-preview"
                                     alt="QR ST00012"
                                     src="<?php
                                        $sumKopecks = 8000 * 100;
                                        $purpose = 'Povyshenie statusa Otvetstvenny 8000 RUB';
                                        $qrPayload = 'ST00012'
                                            . '|Name=ООО Форсаж'
                                            . '|PersonalAcc=40702810101500033019'
                                            . '|BankName=ООО Банк Точка'
                                            . '|BIC=044525104'
                                            . '|CorrespAcc=30101810745374525104'
                                            . '|PayeeINN=7728282160'
                                            . '|KPP=773001001'
                                            . '|Sum=' . $sumKopecks
                                            . '|Purpose=' . $purpose;
                                        echo 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data='
                                            . urlencode($qrPayload);
                                     ?>">
                            </div>
                            <div class="auth-pay-hint">
                                <?= $lang === 'en'
                                    ? 'Scan the QR with any banking app (SBP-compatible). After payment please complete registration below — the administrator activates the status once the payment is verified.'
                                    : 'Отсканируйте QR любым банковским приложением (СБП). После оплаты заполните форму регистрации ниже — администратор активирует статус после подтверждения платежа.' ?>
                            </div>
                        </div>

                        <!-- Квитанция: открыть/распечатать в новом окне -->
                        <div id="auth-pay-receipt-tab" class="auth-pay-tab-panel" style="display:none;">
                            <div class="auth-pay-hint" style="margin-bottom:10px;">
                                <?= $lang === 'en'
                                    ? 'A printable receipt with full bank details and QR-code will open in a new window. Use the «Print» button to print it or «Close» to return.'
                                    : 'Откроется печатная квитанция с реквизитами получателя, QR-кодом, кнопками «Печать» и «Закрыть».' ?>
                            </div>
                            <button type="button"
                                    class="auth-btn auth-btn-primary auth-pay-receipt-btn"
                                    onclick="authOpenReceipt()">
                                🧾 <?= $lang === 'en' ? 'Open printable receipt' : 'Открыть квитанцию' ?>
                            </button>
                        </div>

                        <div class="auth-pay-details-mini">
                            <div><b><?= $lang === 'en' ? 'Recipient:' : 'Получатель:' ?></b> ООО «Форсаж» · ИНН 7728282160</div>
                            <div><b><?= $lang === 'en' ? 'Account:' : 'Счёт:' ?></b> 40702810101500033019, ООО Банк Точка, БИК 044525104</div>
                            <div><b><?= $lang === 'en' ? 'Amount:' : 'Сумма:' ?></b> <span id="auth-pay-amount-mini">8 000 ₽</span> (<?= $lang === 'en' ? 'incl. VAT 22%' : 'в т.ч. НДС 22%' ?>)</div>
                        </div>
                    </div>

                    <!-- Информация по статусу "Организатор" -->
                    <div id="auth-organizer-info" class="auth-organizer-info">
                        <?= $lang === 'en'
                            ? 'Organizer status is granted free of charge for 12 months. After the period ends you can prolong the status from your profile.'
                            : 'Статус «Организатор» предоставляется бесплатно на 12 месяцев. По истечении срока продлить статус можно из личного кабинета.' ?>
                    </div>
                </div>

                <input id="auth-r-fullname" type="text" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Full Name / Company Name' : 'ФИО / Наименование организации' ?>">

                <input id="auth-r-user" type="text" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Username' : 'Логин' ?>"
                       autocomplete="username">

                <input id="auth-r-email" type="email" class="auth-input"
                       placeholder="Email" autocomplete="email">

                <input id="auth-r-pass" type="password" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Password' : 'Пароль' ?>"
                       autocomplete="new-password">

                <!-- Файл 1 -->
                <label class="auth-label">
                    <?= $lang === 'en' ? 'Document 1 (passport / registration)' : 'Документ 1 (паспорт / свидетельство)' ?>
                </label>
                <div class="auth-file-block">
                    <input type="file" id="auth-r-file1" class="auth-file-input"
                           accept=".jpg,.jpeg,.png,.pdf">
                    <div class="auth-file-hint">JPG, PNG, PDF · <?= $lang === 'en' ? 'max 5 MB' : 'макс. 5 МБ' ?></div>
                </div>

                <!-- Файл 2 -->
                <label class="auth-label">
                    <?= $lang === 'en' ? 'Document 2 (INN / OGRN)' : 'Документ 2 (ИНН / ОГРН)' ?>
                </label>
                <div class="auth-file-block">
                    <input type="file" id="auth-r-file2" class="auth-file-input"
                           accept=".jpg,.jpeg,.png,.pdf">
                    <div class="auth-file-hint">JPG, PNG, PDF · <?= $lang === 'en' ? 'max 5 MB' : 'макс. 5 МБ' ?></div>
                </div>

                <!-- Файл 3 -->
                <!-- Файл 3 -->
                <label class="auth-label">
                    <?= $lang === 'en' ? 'Document 3 (power of attorney / other)' : 'Документ 3 (доверенность / иное)' ?>
                </label>
                <div class="auth-file-block">
                    <input type="file" id="auth-r-file3" class="auth-file-input"
                           accept=".jpg,.jpeg,.png,.pdf">
                    <div class="auth-file-hint">JPG, PNG, PDF · <?= $lang === 'en' ? 'max 5 MB' : 'макс. 5 МБ' ?></div>
                </div>

                <!-- Экспресс регистрация -->
                <div class="auth-express-block">
                    ⚡ <?= $lang === 'en' ? 'Express registration activates your account within 24 hours' : 'Экспресс-регистрация активирует аккаунт в течение 24 часов' ?>
                </div>

                <label class="auth-checkbox-row">
                    <input type="checkbox" id="auth-r-express" onchange="authRefreshPayment()">
                    <span><?= $lang === 'en' ? 'Express registration (24 hours)' : 'Экспресс-регистрация за 24 часа' ?></span>
                </label>

                <label class="auth-checkbox-row">
                    <input type="checkbox" id="auth-r-agree">
                    <span><?= $lang === 'en'
                        ? 'I agree to the <a href="regulations.php" target="_blank" style="color:#0ea5e9;">Regulations</a>'
                        : 'Согласен с <a href="regulations.php" target="_blank" style="color:#0ea5e9;">Регламентом</a> площадки' ?></span>
                </label>

                <label class="auth-checkbox-row">
                    <input type="checkbox" id="auth-r-pd">
                    <span><?= $lang === 'en'
                        ? 'I consent to the <a href="personal_data.php" target="_blank" style="color:#0ea5e9;">processing of personal data</a>'
                        : 'Согласен на <a href="personal_data.php" target="_blank" style="color:#0ea5e9;">обработку персональных данных</a>' ?></span>
                </label>

                <button id="auth-r-submit" class="auth-btn auth-btn-primary" onclick="authDoRegister()" style="margin-top:6px;">
                    <?= $lang === 'en' ? 'CREATE ACCOUNT' : 'ЗАРЕГИСТРИРОВАТЬСЯ' ?>
                </button>

                <!-- Блок «оплата прямо в модалке» — показываем после успешной
                     регистрации с платным статусом «Ответственный». -->
                <div id="auth-pay-result" class="auth-pay-result">
                    <h3 id="auth-pay-title">
                        <?= $lang === 'en' ? 'Pay for Responsible status' : 'Оплата статуса «Ответственный»' ?>
                    </h3>
                    <p class="auth-pay-sub" id="auth-pay-subtitle">
                        <?= $lang === 'en'
                            ? 'Your account is created. To activate the Responsible status pay 8 000 ₽ via SBP-QR or by receipt.'
                            : 'Аккаунт создан. Для активации статуса «Ответственный» оплатите 8 000 ₽ по QR или квитанции.' ?>
                    </p>
                    <div id="auth-pay-qr-wrap" class="auth-pay-qr" style="display:none;">
                        <img id="auth-pay-qr-img" src="" alt="QR ST00012">
                    </div>
                    <div class="auth-pay-details">
                        <div><b><?= $lang === 'en' ? 'Recipient:' : 'Получатель:' ?></b> ООО «Форсаж»</div>
                        <div><b>ИНН / КПП:</b> 7728282160 / 773001001</div>
                        <div><b><?= $lang === 'en' ? 'Account:' : 'Счёт:' ?></b> 40702810101500033019</div>
                        <div><b><?= $lang === 'en' ? 'Bank:' : 'Банк:' ?></b> ООО Банк Точка, БИК 044525104</div>
                        <div><b><?= $lang === 'en' ? 'Amount:' : 'Сумма:' ?></b> 8 000 ₽ (<?= $lang === 'en' ? 'incl. VAT 22%' : 'в т.ч. НДС 22%' ?>)</div>
                        <div><b><?= $lang === 'en' ? 'Purpose:' : 'Назначение:' ?></b> <span id="auth-pay-purpose">—</span></div>
                    </div>
                    <div class="auth-pay-actions">
                        <a id="auth-pay-receipt-btn" class="btn-receipt" href="#" target="_blank" rel="noopener">
                            🧾 <?= $lang === 'en' ? 'Open receipt' : 'Открыть квитанцию' ?>
                        </a>
                        <button type="button" class="btn-done" onclick="authFinishPayResult()">
                            ✅ <?= $lang === 'en' ? 'Done' : 'Готово' ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* Лениво монтируем Telegram Login Widget при первом открытии модалки.
   Если скрипт виджета исполняется, пока контейнер скрыт (display:none) —
   iOS Safari часто не рендерит iframe. После открытия модалки контейнер
   виден, и скрипт корректно создаёт iframe рядом с собой. */
/* iOS Safari (включая Chrome/Firefox/Yandex на iOS — все они на WebKit)
   некорректно рендерит iframe официального Telegram Login Widget — окно
   получается белым/пустым. Также проблема воспроизводится в in-app
   браузерах (Telegram, Instagram, FB и т.п.), которые блокируют
   third-party iframes. Поэтому на этих средах сразу подставляем прямую
   ссылку на oauth.telegram.org — это официальная альтернатива виджету. */
function isIOSorWebView() {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/i.test(ua)) return true;
    /* iPadOS 13+ маскируется под Mac, но имеет touch */
    if (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) return true;
    /* In-app браузеры */
    if (/(FBAN|FBAV|Instagram|Line|MicroMessenger|TelegramBot|TwitterAndroid)/i.test(ua)) return true;
    return false;
}

function buildTelegramOAuthUrl(botId, authUrl) {
    var origin = window.location.protocol + '//' + window.location.host;
    return 'https://oauth.telegram.org/auth'
        + '?bot_id=' + encodeURIComponent(botId)
        + '&origin=' + encodeURIComponent(origin)
        + '&request_access=write'
        + '&return_to=' + encodeURIComponent(authUrl);
}

function renderTelegramFallbackLink(box, botId, authUrl) {
    box.innerHTML = '';
    var a = document.createElement('a');
    a.href = buildTelegramOAuthUrl(botId, authUrl);
    a.className = 'auth-btn auth-btn-telegram';
    a.style.textDecoration = 'none';
    a.style.width = '100%';
    a.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="margin-right:8px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>'
               + (document.documentElement.lang === 'en' ? 'Sign in with Telegram' : 'Войти через Telegram');
    box.appendChild(a);
}

function ensureTelegramWidget() {
    var box = document.getElementById('auth-tg-widget');
    if (!box || box.dataset.loaded === '1') return;
    var bot     = box.dataset.tgBot;
    var botId   = box.dataset.tgBotId;
    var authUrl = box.dataset.tgAuthUrl;
    if (!bot) return;
    box.dataset.loaded = '1';

    /* На iOS / WebKit / in-app браузерах сразу показываем прямую ссылку,
       не пытаясь грузить iframe — он всё равно отрендерится белым. */
    if (isIOSorWebView() && botId) {
        renderTelegramFallbackLink(box, botId, authUrl);
        return;
    }

    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://telegram.org/js/telegram-widget.js?22';
    s.setAttribute('data-telegram-login', bot);
    s.setAttribute('data-size', 'large');
    s.setAttribute('data-radius', '10');
    s.setAttribute('data-auth-url', authUrl);
    s.setAttribute('data-request-access', 'write');
    box.appendChild(s);

    /* Финальный страховочный фолбэк: если на десктопе по какой-то причине
       iframe не появился за 2.5 сек — тоже подставляем прямую ссылку. */
    setTimeout(function() {
        var iframe = box.querySelector('iframe');
        if (!iframe && botId) {
            renderTelegramFallbackLink(box, botId, authUrl);
        }
    }, 2500);
}

function openAuth(tab) {
    tab = tab || 'login';
    document.getElementById('auth-modal-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    authTab(tab);
    /* Монтируем виджет уже после того, как модалка стала видимой,
       иначе iOS Safari не отрисует iframe виджета. */
    requestAnimationFrame(ensureTelegramWidget);
}

function closeAuth() {
    document.getElementById('auth-modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('auth-msg').textContent = '';
    document.getElementById('auth-msg').className = '';
}

function authTab(tab) {
    var isLogin = tab === 'login';
    document.getElementById('auth-tab-login').style.display    = isLogin ? 'block' : 'none';
    document.getElementById('auth-tab-register').style.display = isLogin ? 'none'  : 'block';
    document.getElementById('auth-tab-btn-login').classList.toggle('active',  isLogin);
    document.getElementById('auth-tab-btn-register').classList.toggle('active', !isLogin);
    document.getElementById('auth-msg').textContent = '';
    document.getElementById('auth-msg').className   = '';
}

function authViaTelegram() {
    window.location.href = 'telegram_auth.php';
}

function authDoLogin() {
    var user = document.getElementById('auth-l-user').value.trim();
    var pass = document.getElementById('auth-l-pass').value;
    var msg  = document.getElementById('auth-msg');

    if (!user || !pass) {
        msg.textContent = '<?= $lang === "en" ? "Fill in all fields" : "Заполните все поля" ?>';
        msg.className = 'error';
        return;
    }

    fetch('login_ajax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'username=' + encodeURIComponent(user) + '&password=' + encodeURIComponent(pass)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.success) {
            msg.textContent = '✅ ' + (data.message || '<?= $lang === "en" ? "Signed in!" : "Вход выполнен!" ?>');
            msg.className = 'success';
            setTimeout(function(){ location.reload(); }, 800);
        } else {
            msg.textContent = '❌ ' + (data.error || '<?= $lang === "en" ? "Login error" : "Ошибка входа" ?>');
            msg.className = 'error';
        }
    })
    .catch(function(){
        msg.textContent = '❌ <?= $lang === "en" ? "Connection error" : "Ошибка соединения" ?>';
        msg.className = 'error';
    });
}

/* Выбор статуса в форме регистрации — подсветить выбранную карточку
   и показать/скрыть блоки способа оплаты / информации для Организатора.
   Для «Ответственного» оплата (QR + квитанция) становится доступна
   немедленно — без ожидания нажатия «Зарегистрироваться». */
function authSelectStatus(value) {
    var cards = document.querySelectorAll('.auth-status-card');
    cards.forEach(function(c){
        var isMatch = c.getAttribute('data-utype') === value;
        c.classList.toggle('selected', isMatch);
        var radio = c.querySelector('input[type="radio"]');
        if (radio) radio.checked = isMatch;
    });
    var info = document.getElementById('auth-organizer-info');
    if (info) info.classList.toggle('visible', value === 'organizer');
    authRefreshPayment();
    authSelectPayMethod('qr');
}

/* Переключение между QR-кодом и квитанцией внутри модалки регистрации. */
function authSelectPayMethod(method) {
    var qrTab    = document.getElementById('auth-pay-qr-tab');
    var recTab   = document.getElementById('auth-pay-receipt-tab');
    var hidden   = document.getElementById('auth-r-payment');
    var btns     = document.querySelectorAll('.auth-pay-tab');
    if (qrTab)  qrTab.style.display  = (method === 'qr')      ? 'block' : 'none';
    if (recTab) recTab.style.display = (method === 'receipt') ? 'block' : 'none';
    if (hidden) hidden.value = method;
    btns.forEach(function(b){
        b.classList.toggle('active', b.getAttribute('data-method') === method);
    });
}

/* Открыть печатную квитанцию в новом окне. Параметры status/sum
   передаются через query-string — upgrade_receipt.php их читает и
   рендерит квитанцию даже без авторизации. */
function authOpenReceipt() {
    var c = authComputeTotal();
    if (c.total <= 0) return;
    var isEN2 = (document.documentElement.lang || '').toLowerCase().startsWith('en');
    var statusLabel = isEN2
        ? (c.status === 'responsible' ? 'Responsible'
           : (c.status === 'organizer' ? 'Organizer' : 'Respected'))
        : (c.status === 'responsible' ? 'Ответственный'
           : (c.status === 'organizer' ? 'Организатор' : 'Уважаемый'));
    if (c.express) statusLabel += isEN2 ? ' (Express)' : ' (экспресс)';
    var url = 'upgrade_receipt.php?status=' + encodeURIComponent(statusLabel)
            + '&sum=' + c.total + '&express=' + (c.express ? '1' : '0') + '&close=1';
    /* Open in a regular new tab (no popup window — no width/height/features). */
    var w = window.open(url, '_blank', 'noopener,noreferrer');
    if (!w) {
        window.location.href = url;
    }
}

/* Живое обновление суммы/QR в модалке: базовая цена + экспресс 7000₽. */
function authComputeTotal() {
    var statusEl = document.querySelector('input[name="auth-r-utype"]:checked');
    var status   = statusEl ? statusEl.value : 'respected';
    var expressEl = document.getElementById('auth-r-express');
    var express  = !!(expressEl && expressEl.checked);
    var base     = (status === 'responsible') ? 8000 : 0;
    var fee      = express ? 7000 : 0;
    return { status: status, base: base, fee: fee, express: express, total: base + fee };
}

function authFormatRub(n) {
    try {
        return n.toLocaleString('ru-RU') + ' ₽';
    } catch (e) {
        return n + ' ₽';
    }
}

function authBuildQrUrl(total, status, express) {
    var sumKopecks = total * 100;
    var statusName = status === 'responsible' ? 'Otvetstvenny'
                   : (status === 'organizer' ? 'Organizator' : 'Uvazhaemy');
    var purpose = 'Registracia status ' + statusName + (express ? ' express' : '') + ' ' + total + ' RUB';
    var payload = 'ST00012'
        + '|Name=ООО Форсаж'
        + '|PersonalAcc=40702810101500033019'
        + '|BankName=ООО Банк Точка'
        + '|BIC=044525104'
        + '|CorrespAcc=30101810745374525104'
        + '|PayeeINN=7728282160'
        + '|KPP=773001001'
        + '|Sum=' + sumKopecks
        + '|Purpose=' + purpose;
    return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' + encodeURIComponent(payload);
}

function authRefreshPayment() {
    var c = authComputeTotal();
    var pay = document.getElementById('auth-payment-block');
    if (pay) pay.classList.toggle('visible', c.total > 0);
    if (c.total <= 0) return;
    var titleEl = document.getElementById('auth-pay-title');
    if (titleEl) {
        var isEN = (document.documentElement.lang || '').toLowerCase().startsWith('en');
        if (c.status === 'responsible') {
            titleEl.textContent = isEN
                ? ('Payment for «Responsible»' + (c.express ? ' (Express)' : ''))
                : ('Оплата статуса «Ответственный»' + (c.express ? ' (экспресс)' : ''));
        } else {
            titleEl.textContent = isEN ? 'Express registration fee' : 'Оплата экспресс-регистрации';
        }
    }
    var totalEl = document.getElementById('auth-pay-total');
    if (totalEl) totalEl.textContent = authFormatRub(c.total);
    var amtMini = document.getElementById('auth-pay-amount-mini');
    if (amtMini) amtMini.textContent = authFormatRub(c.total);
    var qrEl = document.getElementById('auth-pay-qr-preview');
    if (qrEl) qrEl.src = authBuildQrUrl(c.total, c.status, c.express);
}

function authDoRegister() {
    var fullname = document.getElementById('auth-r-fullname').value.trim();
    var user     = document.getElementById('auth-r-user').value.trim();
    var email    = document.getElementById('auth-r-email').value.trim();
    var pass     = document.getElementById('auth-r-pass').value;
    var agree    = document.getElementById('auth-r-agree').checked;
    var pd       = document.getElementById('auth-r-pd') ? document.getElementById('auth-r-pd').checked : false;
    var express  = document.getElementById('auth-r-express').checked;
    var msg      = document.getElementById('auth-msg');

    /* Считываем выбранный статус и способ оплаты. */
    var utypeEl  = document.querySelector('input[name="auth-r-utype"]:checked');
    var utype    = utypeEl ? utypeEl.value : 'respected';
    /* Способ оплаты теперь хранится в hidden input #auth-r-payment
       (переключается вкладками «QR-код» / «Квитанция»). Сохраняем
       обратную совместимость со старым radio[name=auth-r-payment]. */
    var paymentEl = document.getElementById('auth-r-payment')
                 || document.querySelector('input[name="auth-r-payment"]:checked');
    var payment   = paymentEl ? paymentEl.value : 'qr';

    if (!fullname || !user || !email || !pass) {
        msg.textContent = '<?= $lang === "en" ? "Fill in all fields" : "Заполните все поля" ?>';
        msg.className = 'error';
        return;
    }
    if (!agree) {
        msg.textContent = '<?= $lang === "en" ? "Please accept the platform regulations" : "Примите условия Регламента площадки" ?>';
        msg.className = 'error';
        return;
    }
    if (!pd) {
        msg.textContent = '<?= $lang === "en" ? "Please consent to the processing of personal data" : "Необходимо согласие на обработку персональных данных" ?>';
        msg.className = 'error';
        return;
    }

    var fd = new FormData();
    fd.append('agree_regulations',   '1');
    fd.append('agree_personal_data', '1');
    fd.append('full_name',     fullname);
    fd.append('username',      user);
    fd.append('email',         email);
    fd.append('password',      pass);
    fd.append('express',       express ? '1' : '0');
    fd.append('user_type',     utype);
    fd.append('payment_method', payment);

    var f1 = document.getElementById('auth-r-file1').files[0];
    var f2 = document.getElementById('auth-r-file2').files[0];
    var f3 = document.getElementById('auth-r-file3').files[0];
    if (f1) fd.append('file1', f1);
    if (f2) fd.append('file2', f2);
    if (f3) fd.append('file3', f3);

    msg.textContent = '<?= $lang === "en" ? "Sending..." : "Отправляем..." ?>';
    msg.className = '';

    fetch('register_handler.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.success) {
            msg.textContent = '✅ ' + (data.message || '<?= $lang === "en" ? "Registration successful!" : "Регистрация отправлена!" ?>');
            msg.className = 'success';
            /* Платный статус «Ответственный» — показываем QR/квитанцию прямо
               в модалке, как в profile.php. Никакого редиректа: пользователь
               остаётся на странице, видит реквизиты, может открыть квитанцию
               в новом окне или закрыть модалку. */
            if (data.payment_url && (data.qr_image_url || data.payment_method)) {
                authShowPayResult(data);
            } else if (data.payment_url) {
                /* Бэкенд по какой-то причине не вернул QR-данные — старый путь,
                   с переходом на отдельную страницу. */
                setTimeout(function(){ location.href = data.payment_url; }, 1000);
            } else {
                setTimeout(function(){ location.reload(); }, 1200);
            }
        } else {
            msg.textContent = '❌ ' + (data.error || data.message || '<?= $lang === "en" ? "Registration error" : "Ошибка регистрации" ?>');
            msg.className = 'error';
        }
    })
    .catch(function(){
        msg.textContent = '❌ <?= $lang === "en" ? "Connection error" : "Ошибка соединения" ?>';
        msg.className = 'error';
    });
}

/* Показать блок оплаты внутри модалки. Скрывает форму регистрации, оставляет
   только заголовок и блок «оплата». QR-картинку показываем всегда (даже если
   пользователь выбрал «квитанцию» — пусть видит, что можно оплатить и так). */
function authShowPayResult(data) {
    var fields = [
        'auth-status-grid','auth-payment-block','auth-organizer-info',
        'auth-r-fullname','auth-r-user','auth-r-email','auth-r-pass',
        'auth-r-file1','auth-r-file2','auth-r-file3','auth-r-express',
        'auth-r-submit'
    ];
    fields.forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    /* Скрываем все label и checkbox-ряды формы регистрации. */
    var regTab = document.getElementById('auth-tab-register');
    if (regTab) {
        var labels = regTab.querySelectorAll('.auth-label, .auth-file-block, .auth-checkbox-row, .auth-status-block-title');
        labels.forEach(function(el){ el.style.display = 'none'; });
    }
    var qrWrap = document.getElementById('auth-pay-qr-wrap');
    var qrImg  = document.getElementById('auth-pay-qr-img');
    if (data.qr_image_url && qrWrap && qrImg) {
        qrImg.src = data.qr_image_url;
        qrWrap.style.display = 'block';
    }
    var purposeEl = document.getElementById('auth-pay-purpose');
    if (purposeEl && data.qr_purpose) purposeEl.textContent = data.qr_purpose;
    /* Кнопка «Открыть квитанцию» — целевая ссылка на upgrade_receipt.php?id=N
       с target="_blank" (откроется в новом окне). */
    var rec = document.getElementById('auth-pay-receipt-btn');
    if (rec && data.upgrade_id) {
        rec.href = 'upgrade_receipt.php?id=' + data.upgrade_id;
    }
    var resBlock = document.getElementById('auth-pay-result');
    if (resBlock) resBlock.classList.add('visible');
}

/* «Готово» — закрываем модалку и перезагружаем страницу, чтобы шапка
   обновилась под нового авторизованного пользователя. */
function authFinishPayResult() {
    closeAuth();
    setTimeout(function(){ location.reload(); }, 200);
}

document.getElementById('auth-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeAuth();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' &&
        document.getElementById('auth-modal-overlay').classList.contains('active')) {
        closeAuth();
    }
});

/* Auto-open modal when redirected here from login.php / register.php */
(function(){
    try {
        var p = new URLSearchParams(location.search);
        var m = p.get('modal') || p.get('auth');
        if (m === 'login' || m === 'register') {
            openAuth(m);
        }
    } catch (e) {}
})();
</script>