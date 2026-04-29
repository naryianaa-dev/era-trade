<?php
/**
 * АДАПТИВНАЯ модалка авторизации с Telegram
 * Поддержка: iPhone SE - Samsung Galaxy Z Fold
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($lang)) $lang = $_SESSION['lang'] ?? 'ru';
?>

<style>
/* Адаптивная модалка авторизации */
#auth-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(6px);
    padding: 16px;
    overflow-y: auto;
}

#auth-modal-overlay.active {
    display: flex;
}

#auth-modal-content {
    background: #1e293b;
    width: 100%;
    max-width: 480px;
    border-radius: 24px;
    border: 1px solid #334155;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
    position: relative;
    margin: 20px auto;
}

#auth-modal-close {
    position: absolute;
    top: 14px;
    right: 16px;
    background: none;
    border: none;
    color: #64748b;
    font-size: 28px;
    cursor: pointer;
    line-height: 1;
    z-index: 1;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.2s;
}

#auth-modal-close:hover {
    background: rgba(100, 116, 139, 0.2);
}

.auth-tabs {
    display: flex;
    background: #0f172a;
    padding: 5px;
    gap: 4px;
}

.auth-tab-btn {
    flex: 1;
    padding: 14px;
    border: none;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    font-weight: bold;
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.2s;
}

.auth-tab-btn.active {
    background: #1e293b;
    color: #fff;
}

.auth-content {
    padding: 28px;
}

.auth-input {
    width: 100%;
    padding: 14px;
    border-radius: 10px;
    background: #0f172a;
    border: 1.5px solid #334155;
    color: #fff;
    font-size: 15px;
    margin-bottom: 10px;
    box-sizing: border-box;
    outline: none;
    transition: border-color 0.2s;
}

.auth-input:focus {
    border-color: #3b82f6;
}

.auth-btn {
    width: 100%;
    padding: 15px;
    border: none;
    border-radius: 12px;
    color: #fff;
    font-weight: bold;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.2s;
}

.auth-btn-primary {
    background: #3b82f6;
}

.auth-btn-primary:hover {
    background: #2563eb;
}

.auth-btn-telegram {
    background: linear-gradient(135deg, #2AABEE, #229ED9);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.auth-btn-telegram:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(42, 171, 238, 0.3);
}

.auth-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 16px 0;
}

.auth-divider-line {
    flex: 1;
    height: 1px;
    background: #334155;
}

.auth-divider-text {
    color: #64748b;
    font-size: 13px;
}

.entity-types {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 10px;
}

.entity-type-option {
    padding: 14px;
    border: 1.5px solid #334155;
    border-radius: 10px;
    background: #0f172a;
    color: #94a3b8;
    cursor: pointer;
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s;
}

.entity-type-option.selected {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.1);
    color: #60a5fa;
}

#auth-msg {
    min-height: 20px;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 14px;
    text-align: center;
    padding: 10px;
    border-radius: 8px;
}

#auth-msg.success {
    background: #d1fae5;
    color: #065f46;
}

#auth-msg.error {
    background: #fee2e2;
    color: #991b1b;
}

/* ============================================
   АДАПТИВНОСТЬ ДЛЯ РАЗНЫХ УСТРОЙСТВ
   ============================================ */

/* Планшеты и маленькие ноутбуки */
@media (max-width: 768px) {
    #auth-modal-content {
        max-width: 95%;
        width: 95%;
        margin: 10px;
    }
    
    .auth-content {
        padding: 24px 20px;
    }
    
    .auth-tab-btn {
        padding: 12px;
        font-size: 13px;
    }
    
    .auth-input {
        font-size: 16px; /* Предотвращает зум на iOS */
        padding: 12px;
    }
    
    .auth-btn {
        font-size: 15px;
        padding: 14px;
    }
}

/* Смартфоны (iPhone SE, iPhone 12/13/14, стандартные Android) */
@media (max-width: 480px) {
    #auth-modal-content {
        max-width: 100%;
        width: 100%;
        border-radius: 20px 20px 0 0;
        position: fixed;
        bottom: 0;
        top: auto;
        left: 0;
        right: 0;
        margin: 0;
        transform: none;
    }
    
    #auth-modal-close {
        top: 12px;
        right: 12px;
        width: 32px;
        height: 32px;
        font-size: 24px;
    }
    
    .auth-content {
        padding: 20px 16px;
        padding-bottom: max(20px, env(safe-area-inset-bottom));
    }
    
    .auth-input {
        font-size: 16px;
        padding: 13px;
    }
    
    .auth-btn {
        padding: 13px;
        font-size: 15px;
    }
    
    .entity-types {
        gap: 8px;
    }
    
    .entity-type-option {
        padding: 12px;
        font-size: 12px;
    }
}

/* Очень маленькие экраны (iPhone SE, старые Android) */
@media (max-width: 375px) {
    .auth-content {
        padding: 16px 12px;
    }
    
    .auth-tab-btn {
        padding: 10px;
        font-size: 12px;
    }
    
    .auth-input {
        padding: 11px;
        font-size: 15px;
    }
    
    .auth-btn {
        padding: 12px;
        font-size: 14px;
    }
}

/* Складные устройства (Samsung Galaxy Z Fold - раскрытый режим) */
@media (min-width: 768px) and (max-width: 884px) {
    #auth-modal-content {
        max-width: 500px;
    }
}

/* Ландшафтная ориентация на мобильных */
@media (max-height: 600px) and (orientation: landscape) {
    #auth-modal-content {
        max-height: 90vh;
        overflow-y: auto;
        border-radius: 16px;
        position: relative;
        bottom: auto;
    }
    
    .auth-content {
        padding: 16px;
    }
    
    .auth-tab-btn {
        padding: 10px;
    }
    
    .auth-input {
        padding: 10px;
        margin-bottom: 8px;
    }
    
    .auth-btn {
        padding: 11px;
    }
    
    .auth-divider {
        margin: 10px 0;
    }
}

/* Широкие устройства (iPad Pro, Galaxy Tab) */
@media (min-width: 1024px) {
    #auth-modal-content {
        max-width: 520px;
    }
}

/* Поддержка notch/Dynamic Island (iPhone X и новее) */
@supports (padding: max(0px)) {
    .auth-content {
        padding-top: max(28px, env(safe-area-inset-top));
        padding-bottom: max(28px, env(safe-area-inset-bottom));
        padding-left: max(28px, env(safe-area-inset-left));
        padding-right: max(28px, env(safe-area-inset-right));
    }
}
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
                
                <button class="auth-btn auth-btn-telegram" onclick="authViaTelegram()" type="button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/>
                    </svg>
                    <?= $lang === 'en' ? 'Sign in with Telegram' : 'Войти через Telegram' ?>
                </button>
            </div>

            <!-- РЕГИСТРАЦИЯ -->
            <div id="auth-tab-register" style="display:none;">
                <input id="auth-r-fullname" type="text" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Full Name / Company Name' : 'ФИО / Наименование' ?>">
                
                <div style="margin-bottom:10px;color:#94a3b8;font-size:13px;font-weight:600;">
                    <?= $lang === 'en' ? 'Entity type:' : 'Тип пользователя:' ?>
                </div>
                
                <div class="entity-types">
                    <div id="entity-type-individual" class="entity-type-option selected" 
                         onclick="selectEntityType('individual')">
                        👤 <?= $lang === 'en' ? 'Individual' : 'Физ. лицо' ?>
                    </div>
                    <div id="entity-type-legal" class="entity-type-option" 
                         onclick="selectEntityType('legal')">
                        🏢 <?= $lang === 'en' ? 'Legal entity' : 'Юр. лицо' ?>
                    </div>
                </div>
                
                <input id="auth-r-user" type="text" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Username' : 'Логин' ?>"
                       autocomplete="username">
                
                <input id="auth-r-email" type="email" class="auth-input"
                       placeholder="Email"
                       autocomplete="email">
                
                <input id="auth-r-pass" type="password" class="auth-input"
                       placeholder="<?= $lang === 'en' ? 'Password' : 'Пароль' ?>"
                       autocomplete="new-password"
                       style="margin-bottom:18px;">
                
                <button class="auth-btn auth-btn-primary" onclick="authDoRegister()">
                    <?= $lang === 'en' ? 'CREATE ACCOUNT' : 'ЗАРЕГИСТРИРОВАТЬСЯ' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Функции авторизации
function openAuth(tab = 'login') {
    document.getElementById('auth-modal-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    authTab(tab);
}

function closeAuth() {
    document.getElementById('auth-modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('auth-msg').textContent = '';
    document.getElementById('auth-msg').className = '';
}

function authTab(tab) {
    const isLogin = tab === 'login';
    document.getElementById('auth-tab-login').style.display = isLogin ? 'block' : 'none';
    document.getElementById('auth-tab-register').style.display = isLogin ? 'none' : 'block';
    
    document.getElementById('auth-tab-btn-login').classList.toggle('active', isLogin);
    document.getElementById('auth-tab-btn-register').classList.toggle('active', !isLogin);
    
    document.getElementById('auth-msg').textContent = '';
    document.getElementById('auth-msg').className = '';
}

function selectEntityType(type) {
    document.getElementById('entity-type-individual').classList.toggle('selected', type === 'individual');
    document.getElementById('entity-type-legal').classList.toggle('selected', type === 'legal');
}

function authViaTelegram() {
    window.location.href = 'telegram_auth.php';
}

function authDoLogin() {
    const user = document.getElementById('auth-l-user').value.trim();
    const pass = document.getElementById('auth-l-pass').value;
    const msg = document.getElementById('auth-msg');
    
    if (!user || !pass) {
        msg.textContent = '<?= $lang === 'en' ? 'Fill in all fields' : 'Заполните все поля' ?>';
        msg.className = 'error';
        return;
    }
    
    fetch('login_ajax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `username=${encodeURIComponent(user)}&password=${encodeURIComponent(pass)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msg.textContent = '✅ ' + (data.message || 'Вход выполнен!');
            msg.className = 'success';
            setTimeout(() => location.reload(), 800);
        } else {
            msg.textContent = '❌ ' + (data.error || 'Ошибка входа');
            msg.className = 'error';
        }
    })
    .catch(() => {
        msg.textContent = '❌ Ошибка соединения';
        msg.className = 'error';
    });
}

function authDoRegister() {
    const fullname = document.getElementById('auth-r-fullname').value.trim();
    const user = document.getElementById('auth-r-user').value.trim();
    const email = document.getElementById('auth-r-email').value.trim();
    const pass = document.getElementById('auth-r-pass').value;
    const entityType = document.getElementById('entity-type-individual').classList.contains('selected') 
                       ? 'individual' : 'legal';
    const msg = document.getElementById('auth-msg');
    
    if (!fullname || !user || !email || !pass) {
        msg.textContent = '<?= $lang === 'en' ? 'Fill in all fields' : 'Заполните все поля' ?>';
        msg.className = 'error';
        return;
    }
    
    fetch('reg_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `full_name=${encodeURIComponent(fullname)}&username=${encodeURIComponent(user)}&email=${encodeURIComponent(email)}&password=${encodeURIComponent(pass)}&entity_type=${entityType}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msg.textContent = '✅ ' + (data.message || 'Регистрация успешна!');
            msg.className = 'success';
            setTimeout(() => location.reload(), 800);
        } else {
            msg.textContent = '❌ ' + (data.error || 'Ошибка регистрации');
            msg.className = 'error';
        }
    })
    .catch(() => {
        msg.textContent = '❌ Ошибка соединения';
        msg.className = 'error';
    });
}

// Закрытие по клику вне окна
document.getElementById('auth-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAuth();
    }
});

// Закрытие по ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('auth-modal-overlay').classList.contains('active')) {
        closeAuth();
    }
});
</script>
