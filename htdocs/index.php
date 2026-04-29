<?php
/* index-new.php — пилотный «3D-главный» сайта ЭРА ЭТП.
   Открывается по адресу forsage.ct.ws/index-new.php, существует параллельно с
   основной index.php — её мы НЕ ломаем. Если этот вариант устроит — заменим.

   Стек (всё с CDN, без сборки):
     - Three.js          — 3D-сцена (логотип-метеор, частицы, звёзды).
     - GSAP + ScrollTrig — скролл-сторителлинг по «актам».
     - Lenis             — плавный скролл.

   Адаптив:
     - На устройствах <=640px Three.js НЕ грузится (#hero-bg-canvas остаётся
       пустым, его заменяет CSS-параллакс с PNG-логотипом).
     - prefers-reduced-motion: reduce — анимации полностью выключаются.
     - deviceMemory<4 или hardwareConcurrency<4 — тоже фолбэк на статику. */
/* index-new.php подключает стандартный header.php (часы МСК, бургер, баланс,
   переключатель языка, мобильное меню — всё штатное) и переопределяет
   светлую тему хедера на тёмную, чтобы она лежала на космическом фоне. */
?>
<?php include 'header.php'; ?>
<style>
/* ---- Тёмная тема для пилотной 3D-страницы (поверх стилей header.php) ----- */
/* header.php ставит html,body{height:100%}, из-за чего скролл уходит на body
   и window.scrollY всегда 0. Возвращаем нормальный документный скролл. */
html, body { height: auto !important; min-height: 100%; }
html { overflow-x: hidden; scroll-behavior: smooth; }
body {
    background: #050913 !important;
    color: #e2e8f0 !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    overflow-x: hidden;
    overflow-y: visible !important;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

/* Штатный <header> из header.php в тёмной обёртке. */
header {
    background: rgba(5, 9, 19, 0) !important;
    box-shadow: none !important;
    transition: background .35s ease, backdrop-filter .35s ease, box-shadow .35s ease;
}
body.scrolled header {
    background: rgba(5, 9, 19, .85) !important;
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 4px 20px rgba(0,0,0,.35) !important;
}
/* Логотип в шапке — исходный PNG чёрный, делаем светлым через filter. */
header .logo-img {
    filter: brightness(0) invert(1) drop-shadow(0 0 10px rgba(56,189,248,.55));
}
header .nav-link { color: #cbd5e1 !important; }
header .nav-link.active, header .nav-link:hover { color: #38bdf8 !important; }
/* Часы МСК — белая «таблетка» превращается в стеклянную тёмную. */
header .msc-box {
    background: rgba(15, 23, 42, .65) !important;
    border-color: rgba(148,163,184,.25) !important;
    color: #e2e8f0 !important;
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
}
header .msc-box .dot { background: #38bdf8 !important; box-shadow: 0 0 8px #38bdf8; }
/* Переключатель языка. */
header .lang-btn { color: #94a3b8 !important; }
header .lang-btn.active { color: #38bdf8 !important; background: rgba(56,189,248,.1) !important; }
/* Бургер на мобильных — белая иконка. */
header .burger-trigger i,
header .burger-trigger svg { color: #ffffff !important; stroke: #ffffff !important; }
/* Кнопка «Войти». */
header .btn-login {
    background: linear-gradient(135deg, #0088cc, #38bdf8) !important;
    color: #fff !important;
    border: none !important;
    box-shadow: 0 6px 18px rgba(0,136,204,.35);
}
header .header-auth-block > div:first-child > div:first-child { color: #e2e8f0 !important; }
/* Мобильное меню в тёмной палитре. */
#mobileMenu { background: #050913 !important; }
#mobileMenu .mob-nav-link { color: #e2e8f0 !important; border-bottom-color: rgba(148,163,184,.15) !important; }
#mobileMenu .mob-close i { color: #e2e8f0 !important; }
#mobileMenu .mob-lang-btn { color: #94a3b8 !important; }
#mobileMenu .mob-lang-btn.active { color: #fff !important; background: #0088cc !important; }
#mobileMenu .mob-username { color: #e2e8f0 !important; }
#mobileMenu .mob-balance { color: #22c55e !important; }
#mobileMenu .mob-link-lk { color: #38bdf8 !important; border-color: rgba(56,189,248,.3) !important; }

/* --- Кнопки CTA внутри страницы ------------------------------------------- */
.btn-cta { display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: 10px; background: linear-gradient(135deg, #0088cc, #38bdf8); color: #fff; font-weight: 800; text-decoration: none; font-size: 13px; letter-spacing: .05em; text-transform: uppercase; box-shadow: 0 8px 24px rgba(0,136,204,.35); transition: transform .2s, box-shadow .2s; border: none; cursor: pointer; }
.btn-cta:hover { transform: translateY(-1px); box-shadow: 0 12px 32px rgba(0,136,204,.5); }
.btn-cta.outline { background: transparent; border: 1.5px solid rgba(56,189,248,.5); box-shadow: none; color: #38bdf8; }
.btn-cta.outline:hover { border-color: #38bdf8; background: rgba(56,189,248,.08); }

/* --- 3D холст ------------------------------------------------------------- */
/* pointer-events: auto — чтобы пользователь мог «крутить» 3D-сцену мышкой
   (drag-to-rotate). Контент секций имеет z-index: 2, поэтому клики по тексту,
   ссылкам и кнопкам по-прежнему доходят до них, а не до канваса. */
#hero-bg-canvas {
    position: fixed; inset: 0; z-index: 1;
    width: 100vw; height: 100vh;
    pointer-events: auto;
    cursor: grab;
}
/* When WebGL renders nebulae + stars natively, the CSS starfield overlay
   would just dim the planet. Hide it once Three.js is up. */
body.webgl-on .starfield { opacity: 0; transition: opacity .8s ease; }
#hero-bg-canvas.dragging { cursor: grabbing; }
.starfield {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background:
        radial-gradient(2px 2px at 20% 30%, rgba(255,255,255,.6), transparent 50%),
        radial-gradient(1.5px 1.5px at 70% 60%, rgba(255,255,255,.5), transparent 50%),
        radial-gradient(2px 2px at 40% 80%, rgba(255,255,255,.4), transparent 50%),
        radial-gradient(1.5px 1.5px at 90% 25%, rgba(255,255,255,.5), transparent 50%),
        radial-gradient(2px 2px at 15% 70%, rgba(255,255,255,.5), transparent 50%),
        radial-gradient(1.2px 1.2px at 60% 15%, rgba(255,255,255,.45), transparent 50%),
        radial-gradient(1.5px 1.5px at 35% 50%, rgba(255,255,255,.35), transparent 50%),
        radial-gradient(1px 1px at 80% 85%, rgba(255,255,255,.5), transparent 50%),
        radial-gradient(circle at center, #0c1426 0%, #050913 70%);
    background-size: 700px 700px, 700px 700px, 700px 700px, 700px 700px, 700px 700px, 900px 900px, 900px 900px, 900px 900px, 100% 100%;
    animation: starDrift 90s linear infinite;
}
@keyframes starDrift {
    0%   { background-position: 0 0, 0 0, 0 0, 0 0, 0 0, 0 0, 0 0, 0 0, center; }
    100% { background-position: 700px 350px, -700px 350px, 350px 700px, -350px -700px, 700px -350px, 900px 450px, -900px 450px, 450px 900px, center; }
}

/* CSS-«ядро» — ВСЕГДА видно, даже без WebGL. На него Three.js накладывается сверху. */
.hero-orb-css {
    display: none !important; /* CSS fallback rings disabled — 3D planet only. */
    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
    width: min(75vw, 720px); height: min(75vw, 720px);
    z-index: 0; pointer-events: none;
    display: flex; align-items: center; justify-content: center;
}
.hero-orb-css .core {
    position: absolute; width: 32%; height: 32%; border-radius: 50%;
    background: radial-gradient(circle at 35% 35%, #ffffff 0%, #38bdf8 28%, #0088cc 60%, transparent 80%);
    box-shadow: 0 0 90px 30px rgba(56,189,248,.55), 0 0 180px 60px rgba(0,136,204,.35);
    animation: corePulse 3.4s ease-in-out infinite;
}
.hero-orb-css .ring {
    position: absolute; border-radius: 50%; border: 1px solid rgba(56,189,248,.55);
    box-shadow: 0 0 30px rgba(56,189,248,.25) inset;
}
.hero-orb-css .ring.r1 { width: 55%; height: 55%; animation: ringSpin 22s linear infinite; border-style: dashed; }
.hero-orb-css .ring.r2 { width: 75%; height: 75%; animation: ringSpin 36s linear infinite reverse; border-color: rgba(255,255,255,.25); }
.hero-orb-css .ring.r3 { width: 95%; height: 95%; animation: ringSpin 60s linear infinite; border-color: rgba(0,136,204,.45); border-style: dotted; }
.hero-orb-css .ring.r4 { width: 115%; height: 115%; animation: ringSpin 90s linear infinite reverse; border-color: rgba(56,189,248,.18); }
.hero-orb-css .ring::before {
    content: ''; position: absolute; top: -4px; left: 50%; transform: translateX(-50%);
    width: 8px; height: 8px; border-radius: 50%; background: #38bdf8; box-shadow: 0 0 14px #38bdf8;
}
.hero-orb-css .ring.r2::before { background: #ffffff; box-shadow: 0 0 16px #ffffff; }
.hero-orb-css .ring.r3::before { background: #0088cc; box-shadow: 0 0 18px #0088cc; }
.hero-orb-css .ring.r4::before { background: #38bdf8; box-shadow: 0 0 12px #38bdf8; opacity: .7; }
@keyframes corePulse {
    0%, 100% { transform: scale(1); filter: brightness(1); }
    50%      { transform: scale(1.07); filter: brightness(1.18); }
}
@keyframes ringSpin {
    from { transform: rotate(0deg); } to { transform: rotate(360deg); }
}
/* Когда WebGL включён — прячем CSS-ядро полностью (3D-планета занимает его место). */
body.webgl-on .hero-orb-css { opacity: 0; transition: opacity .8s ease; pointer-events: none; }

.fallback-logo { display: none; } /* больше не нужен — есть hero-orb-css */

/* --- Секции / акты -------------------------------------------------------- */
section {
    position: relative; z-index: 2;
    padding: 0 5%;
}
.act-1 { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding-top: 80px; }
.eyebrow { font-size: 12px; letter-spacing: .35em; text-transform: uppercase; color: #38bdf8; font-weight: 800; margin-bottom: 18px; opacity: 0; transform: translateY(20px); }
.title-mega {
    font-size: clamp(40px, 7vw, 96px); font-weight: 900; line-height: 1; letter-spacing: -.02em; color: #fff;
    background: linear-gradient(180deg, #ffffff 0%, #94a3b8 100%); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
    margin-bottom: 24px; max-width: 1100px;
    filter: drop-shadow(0 4px 24px rgba(0,0,0,0.55));
}
.subtitle { font-size: clamp(15px, 1.5vw, 18px); color: #94a3b8; max-width: 640px; line-height: 1.6; margin-bottom: 38px; }
.hero-actions { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; margin-bottom: 60px; }
.scroll-hint { position: absolute; bottom: 24px; left: 50%; transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; gap: 8px; color: #475569; font-size: 11px; letter-spacing: .25em; text-transform: uppercase; }
.scroll-hint .mouse { width: 22px; height: 36px; border: 1.5px solid #475569; border-radius: 14px; position: relative; }
.scroll-hint .mouse::before { content: ''; position: absolute; left: 50%; top: 7px; width: 3px; height: 8px; border-radius: 2px; background: #38bdf8; transform: translateX(-50%); animation: scrollDot 1.6s ease-in-out infinite; }
@keyframes scrollDot { 0%{opacity:1; top:7px;} 70%{opacity:0; top:18px;} 100%{opacity:0;top:18px;} }

/* --- Акт 2: плитки -------------------------------------------------------- */
.act-2 { padding: 140px 5%; }
.section-head { text-align: center; margin-bottom: 70px; }
.section-eyebrow { font-size: 11px; letter-spacing: .4em; text-transform: uppercase; color: #38bdf8; font-weight: 900; margin-bottom: 14px; }
.section-title { font-size: clamp(30px, 4vw, 52px); font-weight: 900; color: #fff; letter-spacing: -.015em; }
.tiles-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; max-width: 1200px; margin: 0 auto; }
.tile {
    position: relative; padding: 38px 28px; border-radius: 22px;
    background: linear-gradient(180deg, rgba(15,23,42,.75) 0%, rgba(15,23,42,.4) 100%);
    border: 1px solid rgba(148,163,184,.12);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    overflow: hidden; cursor: pointer;
    transform-style: preserve-3d; transition: transform .25s cubic-bezier(.2,.7,.2,1), border-color .25s;
}
.tile::before { content: ''; position: absolute; top: -120%; left: -120%; width: 240%; height: 240%; background: radial-gradient(circle at 30% 30%, rgba(56,189,248,.18), transparent 50%); opacity: 0; transition: opacity .35s; }
/* .tile:hover вынесен в .tile.settled:hover (см. секцию HOVER ниже),
   чтобы не конфликтовать с keyframe-анимацией входа. */
.tile:hover::before { opacity: 1; }
.tile .tile-icon { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, #0088cc, #38bdf8); display: flex; align-items: center; justify-content: center; margin-bottom: 22px; font-size: 28px; box-shadow: 0 12px 30px rgba(0,136,204,.35); }
.tile h3 { font-size: 22px; font-weight: 900; color: #fff; margin-bottom: 10px; }
.tile p { font-size: 14px; color: #94a3b8; line-height: 1.6; margin-bottom: 22px; }
.tile .tile-cta { display: inline-flex; align-items: center; gap: 6px; color: #38bdf8; font-weight: 800; font-size: 13px; text-decoration: none; }
@media (max-width: 900px) { .tiles-grid { grid-template-columns: 1fr; } }

/* --- Акт 3: типы аукционов — сетка 3x2 (раньше был горизонтальный скролл,
   но 6 карточек по 320px не помещались в 1568px-десктоп) -------------------- */
.act-3 { padding: 140px 5%; }
.h-scroll-track {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px;
    max-width: 1200px; margin: 0 auto; padding: 0;
    overflow: visible;
}
.auc-card {
    padding: 30px 26px; border-radius: 22px;
    background: linear-gradient(160deg, var(--c1, #0f172a), var(--c2, #1e293b));
    border: 1px solid rgba(148,163,184,.15);
    box-shadow: 0 18px 48px rgba(0,0,0,.45);
    /* Чтобы 3D-перевороты выглядели объёмнее. */
    transform-style: preserve-3d;
}
.auc-card .ac-icon { font-size: 38px; margin-bottom: 18px; }
.auc-card h4 { font-size: 19px; font-weight: 900; color: #fff; margin-bottom: 12px; }
.auc-card p { font-size: 13.5px; color: rgba(255,255,255,.78); line-height: 1.55; min-height: 64px; }
.auc-card .badge { display: inline-block; margin-top: 16px; padding: 5px 11px; border-radius: 999px; background: rgba(255,255,255,.16); font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
@media (max-width: 900px) { .h-scroll-track { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .h-scroll-track { grid-template-columns: 1fr; } }

/* --- Акт 4: преимущества -------------------------------------------------- */
.act-4 { padding: 140px 5%; }
.adv-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; max-width: 1200px; margin: 0 auto; }
.adv {
    padding: 38px 28px; border-radius: 22px; text-align: center;
    background: rgba(15,23,42,.5); border: 1px solid rgba(148,163,184,.12);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
}
.adv .adv-num { font-size: 56px; font-weight: 900; line-height: 1; background: linear-gradient(135deg, #0088cc, #38bdf8); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 8px; }
.adv h4 { font-size: 19px; font-weight: 900; color: #fff; margin-bottom: 8px; }
.adv p { font-size: 14px; color: #94a3b8; line-height: 1.55; }
@media (max-width: 900px) { .adv-grid { grid-template-columns: 1fr; } }

/* --- Акт 5: финал --------------------------------------------------------- */
.act-5 { padding: 160px 5%; text-align: center; }
.act-5 .title-mega { margin-left: auto; margin-right: auto; max-width: 900px; }
.act-5 .subtitle { margin: 0 auto 40px; }

/* --- Подвал (штатный footer.php — слегка корректируем для тёмной темы) ----- */
body > footer { position: relative; z-index: 5; }
body > footer img[alt="Форсаж"] {
    filter: brightness(0) invert(1) drop-shadow(0 0 8px rgba(56,189,248,.4));
    opacity: 1 !important;
}

/* --- Кнопка «Наверх» — фикс справа внизу ---------------------------------- */
#to-top {
    position: fixed; right: 22px; bottom: 22px; z-index: 60;
    width: 52px; height: 52px; border-radius: 50%; border: 1px solid rgba(56,189,248,.5);
    background: linear-gradient(135deg, #0088cc, #38bdf8);
    color: #fff; cursor: pointer;
    box-shadow: 0 12px 30px rgba(8, 145, 178, .45), 0 0 24px rgba(56,189,248,.5);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transform: translateY(12px);
    transition: opacity .25s ease, transform .25s ease, box-shadow .25s ease;
}
#to-top.visible { opacity: 1; pointer-events: auto; transform: translateY(0); }
#to-top:hover { box-shadow: 0 16px 38px rgba(8, 145, 178, .65), 0 0 32px rgba(56,189,248,.8); transform: translateY(-3px); }
@media (max-width: 480px) { #to-top { right: 14px; bottom: 14px; width: 46px; height: 46px; } }

/* --- Подсказка про интерактивность 3D ------------------------------------ */
.drag-hint {
    position: fixed; left: 24px; bottom: 22px; z-index: 50;
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px; border-radius: 999px;
    background: rgba(15,23,42,.65);
    border: 1px solid rgba(56,189,248,.35);
    color: #cbd5e1; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    pointer-events: none;
    transition: opacity .35s ease, transform .35s ease;
}
.drag-hint .dh-dot {
    width: 6px; height: 6px; border-radius: 50%; background: #38bdf8;
    box-shadow: 0 0 10px #38bdf8;
    animation: corePulse 1.4s ease-in-out infinite;
}
.drag-hint.hidden { opacity: 0; transform: translateY(8px); }
/* На мобильной (≤480px) 3D-сцена отключена — прячем подсказку полностью,
   чтобы не вводить в заблуждение «потяните, чтобы вращать», когда вращать нечего. */
@media (max-width: 480px) { .drag-hint { display: none !important; } }

@media (prefers-reduced-motion: reduce) {
    *,*::before,*::after { animation: none !important; transition: none !important; }
}
</style>
</head>
<body>

<canvas id="hero-bg-canvas"></canvas>
<div class="starfield" aria-hidden="true"></div>
<div class="hero-orb-css" aria-hidden="true">
    <div class="ring r4"></div>
    <div class="ring r3"></div>
    <div class="ring r2"></div>
    <div class="ring r1"></div>
    <div class="core"></div>
</div>

<section class="act-1" id="act1">
    <div class="eyebrow" data-anim="eyebrow"><?= $lang === 'en' ? 'Next-generation electronic trading platform' : 'Электронная торговая площадка нового поколения' ?></div>
    <h1 class="title-mega" data-anim="title"><?= $lang === 'en' ? "Trade<br>transparently and fast" : "Торгуйте<br>прозрачно и быстро" ?></h1>
    <p class="subtitle" data-anim="subtitle">
        <?= $lang === 'en' ? 'Open, closed and Scandinavian auctions, requests for proposals and quotations — all in a single workspace, fully compliant with Federal Law 152-FZ on personal data. Five-minute sign-up, first bid in under a minute.' : 'Открытые, закрытые, скандинавские аукционы, запрос предложений и котировок — в одном кабинете, под защитой 152-ФЗ. Регистрация за пять минут, первая ставка — за минуту.' ?>
    </p>
    <div class="hero-actions" data-anim="cta">
        <button class="btn-cta" onclick="openAuth && openAuth('register')"><?= $lang === 'en' ? 'Sign up' : 'Зарегистрироваться' ?></button>
        <a class="btn-cta outline" href="/reestr.php"><?= $lang === 'en' ? 'Browse auctions →' : 'Смотреть торги →' ?></a>
    </div>
    <div class="scroll-hint"><div class="mouse"></div><?= $lang === 'en' ? 'Scroll' : 'Скролл' ?></div>
</section>

<section class="act-2" id="act2">
    <div class="section-head">
        <div class="section-eyebrow"><?= $lang === 'en' ? 'Who uses the platform' : 'Кто на площадке' ?></div>
        <h2 class="section-title"><?= $lang === 'en' ? 'Three roles, one workspace' : 'Три роли — один кабинет' ?></h2>
    </div>
    <div class="tiles-grid">
        <a class="tile" href="/reestr.php" data-tile>
            <div class="tile-icon">🎯</div>
            <h3><?= $lang === 'en' ? 'Bidder' : 'Участник' ?></h3>
            <p><?= $lang === 'en' ? 'Find the lots you need, submit applications and place bids. All six auction formats and a real-time lot registry are at your disposal.' : 'Ищите подходящие лоты, подавайте заявки, делайте ставки. Доступны все шесть типов торгов и реестр в реальном времени.' ?></p>
            <span class="tile-cta"><?= $lang === 'en' ? 'Open the registry →' : 'К реестру →' ?></span>
        </a>
        <a class="tile" href="#" onclick="openAuth && openAuth('register'); return false;" data-tile>
            <div class="tile-icon">⚡</div>
            <h3><?= $lang === 'en' ? 'Registration' : 'Регистрация' ?></h3>
            <p><?= $lang === 'en' ? 'Respected — free. Responsible — RUB 8,000. Organizer — free for 12 months. Full accreditation within 24 hours.' : 'Уважаемый — бесплатно. Ответственный — 8 000 ₽. Организатор — бесплатно на 12 месяцев. Аккредитация в системе за 24 часа.' ?></p>
            <span class="tile-cta"><?= $lang === 'en' ? 'Create an account →' : 'Создать аккаунт →' ?></span>
        </a>
        <a class="tile" href="/add_lot.php" data-tile>
            <div class="tile-icon">📊</div>
            <h3><?= $lang === 'en' ? 'Organizer' : 'Организатор' ?></h3>
            <p><?= $lang === 'en' ? 'Publish lots, pick the auction format, control bidder admission and finalise the outcome. A complete audit trail is kept for every deal.' : 'Размещайте лоты, выбирайте формат торгов, управляйте допуском участников и итогами. Полный аудит-трейл по каждой сделке.' ?></p>
            <span class="tile-cta"><?= $lang === 'en' ? 'Create a lot →' : 'Создать лот →' ?></span>
        </a>
    </div>
</section>

<section class="act-3" id="act3">
    <div class="section-head">
        <div class="section-eyebrow"><?= $lang === 'en' ? 'Six formats' : 'Шесть форматов' ?></div>
        <h2 class="section-title"><?= $lang === 'en' ? 'Any bidding logic you need' : 'Любая логика торгов' ?></h2>
    </div>
    <div class="h-scroll-track">
        <div class="auc-card" style="--c1:#0f172a;--c2:#1e3a8a;">
            <div class="ac-icon">🔨</div>
            <h4><?= $lang === 'en' ? 'Open auction' : 'Открытый аукцион' ?></h4>
            <p><?= $lang === 'en' ? 'A classic ascending-price auction. Every bid is visible to all participants and the highest price wins.' : 'Классика на повышение. Все ставки видны участникам, побеждает наибольшая цена.' ?></p>
            <div class="badge"><?= $lang === 'en' ? 'Transparent' : 'Прозрачно' ?></div>
        </div>
        <div class="auc-card" style="--c1:#1e1b4b;--c2:#7c2d12;">
            <div class="ac-icon">🔥</div>
            <h4><?= $lang === 'en' ? 'Penny (Scandinavian)' : 'Скандинавский' ?></h4>
            <p><?= $lang === 'en' ? 'Every bid carries a fixed fee and extends the timer. High excitement for bidders, steady revenue for the organizer.' : 'Каждая ставка стоит фиксированный тариф и продлевает таймер. Драйв и доход для организатора.' ?></p>
            <div class="badge"><?= $lang === 'en' ? 'High-paced' : 'Драйв' ?></div>
        </div>
        <div class="auc-card" style="--c1:#052e16;--c2:#14532d;">
            <div class="ac-icon">📉</div>
            <h4><?= $lang === 'en' ? 'Reverse (Dutch)' : 'На понижение' ?></h4>
            <p><?= $lang === 'en' ? 'The price drops in fixed steps. Whoever clicks “Buy now” first takes the lot.' : 'Цена снижается с шагом. Кто первый нажмёт «купить» — тот и забирает лот.' ?></p>
            <div class="badge"><?= $lang === 'en' ? 'Fast' : 'Скорость' ?></div>
        </div>
        <div class="auc-card" style="--c1:#1e293b;--c2:#475569;">
            <div class="ac-icon">🔒</div>
            <h4><?= $lang === 'en' ? 'Closed' : 'Закрытый' ?></h4>
            <p><?= $lang === 'en' ? 'Only pre-approved bidders can see the auction and place bids. Identities stay hidden — only the winning price is disclosed.' : 'Только допущенные участники видят торги и ставят. Имена скрыты, выигрыш — только цена.' ?></p>
            <div class="badge"><?= $lang === 'en' ? 'Confidential' : 'Конфиденциально' ?></div>
        </div>
        <div class="auc-card" style="--c1:#0c4a6e;--c2:#0e7490;">
            <div class="ac-icon">📨</div>
            <h4><?= $lang === 'en' ? 'Request for proposals' : 'Запрос предложений' ?></h4>
            <p><?= $lang === 'en' ? 'Each bidder submits a single offer, can review it at any time and is free to raise the price.' : 'Участники подают единое предложение, видят его в любой момент и могут поднять цену.' ?></p>
            <div class="badge"><?= $lang === 'en' ? 'Flexible' : 'Гибко' ?></div>
        </div>
        <div class="auc-card" style="--c1:#581c87;--c2:#a21caf;">
            <div class="ac-icon">📑</div>
            <h4><?= $lang === 'en' ? 'Request for quotations' : 'Запрос котировок' ?></h4>
            <p><?= $lang === 'en' ? 'The mirror image of an RFP — the lowest price wins. Ideal for procurement.' : 'Зеркально к запросу предложений: побеждает минимальная цена. Удобно для закупок.' ?></p>
            <div class="badge"><?= $lang === 'en' ? 'Procurement' : 'Закупки' ?></div>
        </div>
    </div>
</section>

<section class="act-4" id="act4">
    <div class="section-head">
        <div class="section-eyebrow"><?= $lang === 'en' ? 'Why ERA' : 'Почему ЭРА' ?></div>
        <h2 class="section-title"><?= $lang === 'en' ? 'The details that change everything' : 'Тонкости, которые меняют всё' ?></h2>
    </div>
    <div class="adv-grid">
        <div class="adv">
            <div class="adv-num">152-ФЗ</div>
            <h4><?= $lang === 'en' ? 'Personal data protection' : 'Защита персональных данных' ?></h4>
            <p><?= $lang === 'en' ? 'Every consent is logged with IP, timestamp and User-Agent. The operator’s policies and details are publicly available.' : 'Журнал согласий с IP, временем и User-Agent. Политики и реквизиты оператора публичны.' ?></p>
        </div>
        <div class="adv">
            <div class="adv-num">&lt;1c</div>
            <h4><?= $lang === 'en' ? 'Real-time' : 'Реальное время' ?></h4>
            <p><?= $lang === 'en' ? 'Bids and offers appear instantly. No need to refresh — the feed updates on its own.' : 'Ставки и предложения видны мгновенно. Никаких F5 — лента обновляется сама.' ?></p>
        </div>
        <div class="adv">
            <div class="adv-num">QR</div>
            <h4><?= $lang === 'en' ? 'SBP & receipts' : 'СБП и квитанции' ?></h4>
            <p><?= $lang === 'en' ? 'Pay for statuses and reports via QR-code (Russia’s SBP) or a printable bank receipt with the operator’s full details.' : 'Оплата статусов и отчётов по QR-коду или печатной квитанции с реквизитами оператора.' ?></p>
        </div>
    </div>
</section>

<section class="act-5">
    <div class="section-eyebrow" style="color:#38bdf8;margin-bottom:18px;"><?= $lang === 'en' ? 'Ready to start?' : 'Готовы начать?' ?></div>
    <h2 class="title-mega"><?= $lang === 'en' ? 'Your first lot — in five minutes.' : 'Первый лот — через пять минут.' ?></h2>
    <p class="subtitle"><?= $lang === 'en' ? 'Registration is free. The Responsible status is activated after payment. Organizer — 12 months on the house.' : 'Регистрация бесплатна. Ответственный статус активируется после оплаты. Организатор — на 12 месяцев в подарок.' ?></p>
    <div class="hero-actions">
        <button class="btn-cta" onclick="openAuth && openAuth('register')"><?= $lang === 'en' ? 'Create an account' : 'Создать аккаунт' ?></button>
        <a class="btn-cta outline" href="/reestr.php"><?= $lang === 'en' ? 'View auctions →' : 'Посмотреть торги →' ?></a>
    </div>
</section>

<?php /* Стандартный подвал сайта, как и на остальных страницах. */ ?>
<?php include 'footer.php'; ?>

<!-- Подсказка: «потяните, чтобы вращать 3D». Прячется после первого взаимодействия. -->
<div id="drag-hint" class="drag-hint" aria-hidden="true">
    <span class="dh-dot"></span>
    <span><?= $lang === 'en' ? 'Drag to rotate the scene' : 'Потяните, чтобы вращать сцену' ?></span>
</div>

<!-- Кнопка «Наверх» — фикс справа внизу, появляется после прокрутки. -->
<button id="to-top" type="button" aria-label="<?= $lang === 'en' ? 'Back to top' : 'Наверх' ?>" title="<?= $lang === 'en' ? 'Back to top' : 'Наверх' ?>">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 19V5"/><path d="M5 12l7-7 7 7"/>
    </svg>
</button>

<?php /* auth_modal.php уже подключён в header.php, не дублируем. */ ?>

<script src="https://unpkg.com/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://unpkg.com/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>

<!-- Three.js грузится как ES-модуль и кладётся в window.THREE.
     Этот вариант работает во всех современных браузерах без importmap. -->
<script type="module">
import * as THREE from 'https://unpkg.com/three@0.160.0/build/three.module.js';
window.THREE = THREE;
window.dispatchEvent(new Event('three-ready'));
</script>

<script>
/* Шапка темнеет после прокрутки на ~80px, кнопка «Наверх» — после ~400px. */
const toTopBtn = document.getElementById('to-top');
window.addEventListener('scroll', () => {
    document.body.classList.toggle('scrolled', window.scrollY > 80);
    if (toTopBtn) toTopBtn.classList.toggle('visible', window.scrollY > 400);
}, { passive: true });
if (toTopBtn) {
    toTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

/* Решаем, нужно ли вообще пытаться рисовать 3D. */
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const lowDevice    = (navigator.deviceMemory && navigator.deviceMemory < 2) ||
                     (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 2);
const skip3D       = reduceMotion || lowDevice;

/* Three.js приходит асинхронно — ждём его готовности и стартуем сцену. */
if (!skip3D) {
    if (window.THREE) {
        bootThree();
    } else {
        window.addEventListener('three-ready', bootThree, { once: true });
        /* Защита от тихого зависания загрузки модуля. */
        setTimeout(() => { if (!window.THREE) console.warn('Three.js не загрузился за 6с — остаётся CSS-ядро.'); }, 6000);
    }
}
function bootThree() {
    try {
        initThreeScene(window.THREE);
        document.body.classList.add('webgl-on');
    } catch (err) {
        console.warn('Three.js init failed, CSS orb остаётся.', err);
    }
}
/* Скролл-таймлайн запускаем в любом случае — он не требует Three.js. */
startScrollTimeline();

function initThreeScene(THREE) {
    /* Cinematic cosmos: nebulae, a gas-giant planet with a tilted Saturn-like
       ring belt, an orbiting moon, recurring comets with glowing tails, and a
       deep starfield. Reacts to cursor (parallax) and scroll (camera dolly). */
    const canvas = document.getElementById('hero-bg-canvas');
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(window.innerWidth, window.innerHeight, false);
    renderer.setClearColor(0x000000, 0);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(55, window.innerWidth / window.innerHeight, 0.1, 400);
    camera.position.set(0, 0.6, 9);

    /* === Helpers ============================================================ */
    /* Build a soft radial sprite (used for nebulae, comet tails, glow halos). */
    function radialSprite(hex, alpha = 1) {
        const c = document.createElement('canvas');
        c.width = c.height = 256;
        const g = c.getContext('2d');
        const grad = g.createRadialGradient(128, 128, 0, 128, 128, 128);
        const r = (hex >> 16) & 255, gg = (hex >> 8) & 255, b = hex & 255;
        grad.addColorStop(0,    `rgba(${r},${gg},${b},${alpha})`);
        grad.addColorStop(0.4,  `rgba(${r},${gg},${b},${alpha * 0.45})`);
        grad.addColorStop(1,    `rgba(${r},${gg},${b},0)`);
        g.fillStyle = grad;
        g.fillRect(0, 0, 256, 256);
        const tex = new THREE.CanvasTexture(c);
        tex.minFilter = THREE.LinearFilter;
        return tex;
    }

    /* === Starfield (3 layers, varied colour temperatures) =================== */
    function makeStarLayer(count, spread, size, color, opacity) {
        const geo = new THREE.BufferGeometry();
        const pos = new Float32Array(count * 3);
        for (let i = 0; i < count; i++) {
            pos[i*3+0] = (Math.random() - 0.5) * spread;
            pos[i*3+1] = (Math.random() - 0.5) * spread;
            pos[i*3+2] = (Math.random() - 0.5) * spread;
        }
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        return new THREE.Points(geo, new THREE.PointsMaterial({
            color, size, sizeAttenuation: true,
            transparent: true, opacity, depthWrite: false,
            blending: THREE.AdditiveBlending
        }));
    }
    const starsFar  = makeStarLayer(2200, 220, 0.35, 0xb6c8ff, 0.85);
    const starsMid  = makeStarLayer(900,  140, 0.55, 0xffffff, 0.95);
    const starsNear = makeStarLayer(280,   80, 0.9,  0xfff4d1, 1.00);
    starsFar.material.map  = radialSprite(0xffffff, 1);
    starsMid.material.map  = radialSprite(0xffffff, 1);
    starsNear.material.map = radialSprite(0xfff4d1, 1);
    [starsFar, starsMid, starsNear].forEach(s => scene.add(s));

    /* === Nebulae (soft glowing cloud sprites floating in the background) === */
    const nebulae = [];
    const nebulaDefs = [
        { color: 0x3b6cff, size: 38, x: -16, y:  6, z: -28, opacity: 0.55 },
        { color: 0x9d4edd, size: 32, x:  14, y: -4, z: -32, opacity: 0.45 },
        { color: 0x06b6d4, size: 46, x:  -2, y: 10, z: -40, opacity: 0.35 },
        { color: 0xff5e7e, size: 26, x:  22, y: 12, z: -36, opacity: 0.30 },
    ];
    nebulaDefs.forEach(def => {
        const sp = new THREE.Sprite(new THREE.SpriteMaterial({
            map: radialSprite(def.color, 1),
            transparent: true, opacity: def.opacity,
            depthWrite: false, blending: THREE.AdditiveBlending,
            color: 0xffffff
        }));
        sp.scale.set(def.size, def.size, 1);
        sp.position.set(def.x, def.y, def.z);
        scene.add(sp);
        nebulae.push(sp);
    });

    /* === Gas-giant planet =================================================== */
    /* A textured sphere drawn from a procedural canvas: horizontal bands of
       brand-cyan/teal that slowly drift sideways. */
    function makePlanetTexture() {
        const c = document.createElement('canvas');
        c.width = 1024; c.height = 512;
        const g = c.getContext('2d');
        // Base gradient (poles darker, equator brighter)
        const base = g.createLinearGradient(0, 0, 0, 512);
        base.addColorStop(0.00, '#062b3f');
        base.addColorStop(0.30, '#0b4f73');
        base.addColorStop(0.50, '#0e7396');
        base.addColorStop(0.70, '#0b4f73');
        base.addColorStop(1.00, '#062b3f');
        g.fillStyle = base;
        g.fillRect(0, 0, 1024, 512);
        // Bands
        const bands = 14;
        for (let i = 0; i < bands; i++) {
            const y = (i / bands) * 512 + (Math.sin(i) * 6);
            const h = 18 + Math.sin(i * 1.3) * 14;
            g.fillStyle = `rgba(${i%2 ? 56 : 125},${i%2 ? 189 : 211},${i%2 ? 248 : 252},${0.08 + (i%3)*0.04})`;
            g.fillRect(0, y, 1024, h);
        }
        // Subtle storm spot (a la Jupiter)
        const r = g.createRadialGradient(720, 300, 4, 720, 300, 70);
        r.addColorStop(0, 'rgba(255,200,200,0.85)');
        r.addColorStop(1, 'rgba(255,200,200,0)');
        g.fillStyle = r;
        g.beginPath(); g.ellipse(720, 300, 90, 50, 0, 0, Math.PI*2); g.fill();
        return new THREE.CanvasTexture(c);
    }
    const planetTex = makePlanetTexture();
    planetTex.wrapS = THREE.RepeatWrapping;

    const planetGroup = new THREE.Group();
    /* Anchored in the lower-right region of the viewport, but pulled inward
       toward the centre (above the bottom edge, away from the right edge).
       Sized to fit fully (rings included) across breakpoints. */
    function getPlanetLayout() {
        const w = window.innerWidth;
        const h = window.innerHeight;
        const aspect = w / Math.max(1, h);
        if (w <= 480) {
            /* Phone: upper-right anchor, lifted high above the eyebrow line,
               sized small enough so rings never touch any hero copy. Pushed
               deeper into perspective and given a Saturn-style axial tilt. */
            return { x: 1.4, y: 4.6, z: -7.0, s: 0.42, tilt: -0.42 };
        }
        if (w <= 900) {
            /* Tablet: upper-right anchor, well clear of header and title.
               Mobile-style tilt inherited. */
            return { x: aspect < 1.1 ? 1.6 : 2.6, y: 4.0, z: -6.5, s: 0.45, tilt: -0.42 };
        }
        if (w <= 1280) {
            /* Small desktop / 13" laptop: lower-right pulled toward centre. */
            return { x: 5.0, y: -5.5, z: -4.5, s: 0.55, tilt: 0 };
        }
        /* Large desktop: lower-right tucked toward centre, plenty of margin
           on both the right and bottom edges. */
        return { x: 8.5, y: -5.0, z: -4.5, s: 0.65, tilt: 0 };
    }
    let planetLayout = getPlanetLayout();
    planetGroup.position.set(planetLayout.x, planetLayout.y, planetLayout.z);
    planetGroup.scale.setScalar(planetLayout.s);
    /* Saturn-style axial tilt — applied only on mobile breakpoints where the
       layout config sets a non-zero tilt. */
    planetGroup.rotation.z = planetLayout.tilt || 0;
    scene.add(planetGroup);

    const planet = new THREE.Mesh(
        new THREE.SphereGeometry(1.6, 96, 64),
        new THREE.MeshBasicMaterial({ map: planetTex })
    );
    planetGroup.add(planet);

    /* Atmospheric rim glow (back-faced sphere with additive blue) */
    [{r:1.78, o:0.28}, {r:2.05, o:0.14}, {r:2.45, o:0.07}].forEach(({r, o}) => {
        const atm = new THREE.Mesh(
            new THREE.SphereGeometry(r, 64, 32),
            new THREE.MeshBasicMaterial({
                color: 0x38bdf8, transparent: true, opacity: o,
                blending: THREE.AdditiveBlending, depthWrite: false, side: THREE.BackSide
            })
        );
        planetGroup.add(atm);
    });

    /* Saturn-like ring belt (tilted) */
    function makeRingTexture() {
        const c = document.createElement('canvas');
        c.width = 1024; c.height = 32;
        const g = c.getContext('2d');
        const grad = g.createLinearGradient(0, 0, 1024, 0);
        grad.addColorStop(0.00, 'rgba(255,255,255,0)');
        grad.addColorStop(0.10, 'rgba(125,211,252,0.55)');
        grad.addColorStop(0.30, 'rgba(56,189,248,0.85)');
        grad.addColorStop(0.45, 'rgba(255,255,255,0.95)');
        grad.addColorStop(0.55, 'rgba(255,255,255,0.95)');
        grad.addColorStop(0.70, 'rgba(56,189,248,0.85)');
        grad.addColorStop(0.90, 'rgba(125,211,252,0.55)');
        grad.addColorStop(1.00, 'rgba(255,255,255,0)');
        g.fillStyle = grad; g.fillRect(0, 0, 1024, 32);
        // Cassini-style gaps
        g.globalCompositeOperation = 'destination-out';
        g.fillStyle = 'rgba(0,0,0,1)';
        [200, 480, 760, 880].forEach(x => g.fillRect(x, 0, 12, 32));
        const tex = new THREE.CanvasTexture(c);
        tex.minFilter = THREE.LinearFilter;
        return tex;
    }
    const ringTex = makeRingTexture();
    const ringGeo = new THREE.RingGeometry(2.0, 3.4, 128, 1);
    /* Re-map UVs so the gradient runs across the radius (RingGeometry's default
       UVs are not radial-friendly for stripe textures). */
    {
        const uv = ringGeo.attributes.uv;
        const pos = ringGeo.attributes.position;
        for (let i = 0; i < uv.count; i++) {
            const x = pos.getX(i), y = pos.getY(i);
            const r = Math.sqrt(x*x + y*y);
            const t = (r - 2.0) / (3.4 - 2.0);
            uv.setXY(i, t, 0.5);
        }
    }
    const planetRing = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({
        map: ringTex, side: THREE.DoubleSide,
        transparent: true, opacity: 0.85, depthWrite: false
    }));
    planetRing.rotation.x = Math.PI * 0.48;
    planetRing.rotation.z = Math.PI * 0.18;
    planetGroup.add(planetRing);

    /* Moon orbiting the planet */
    const moonOrbit = new THREE.Group();
    planetGroup.add(moonOrbit);
    const moon = new THREE.Mesh(
        new THREE.SphereGeometry(0.22, 32, 24),
        new THREE.MeshBasicMaterial({ color: 0xe2e8f0 })
    );
    moon.position.set(3.2, 0.4, 0);
    moonOrbit.add(moon);
    /* Soft glow around the moon */
    const moonGlow = new THREE.Sprite(new THREE.SpriteMaterial({
        map: radialSprite(0xffffff, 1), color: 0xbae6fd,
        transparent: true, opacity: 0.45, depthWrite: false,
        blending: THREE.AdditiveBlending
    }));
    moonGlow.scale.set(1.1, 1.1, 1);
    moon.add(moonGlow);

    /* === Comets (recurring streaks with bright nucleus and tapered tail) === */
    const COMET_COUNT = 3;
    const comets = [];
    for (let k = 0; k < COMET_COUNT; k++) {
        /* Nucleus: bright sprite */
        const nucleus = new THREE.Sprite(new THREE.SpriteMaterial({
            map: radialSprite(0xffffff, 1), color: 0xffffff,
            transparent: true, opacity: 1, depthWrite: false,
            blending: THREE.AdditiveBlending
        }));
        nucleus.scale.set(0.55, 0.55, 1);
        scene.add(nucleus);

        /* Tail: thin line of additive points trailing behind the nucleus */
        const TAIL = 36;
        const tailGeo = new THREE.BufferGeometry();
        const tailPos = new Float32Array(TAIL * 3);
        tailGeo.setAttribute('position', new THREE.BufferAttribute(tailPos, 3));
        const tailMat = new THREE.PointsMaterial({
            map: radialSprite(0x7dd3fc, 1), color: 0x7dd3fc,
            size: 0.45, sizeAttenuation: true,
            transparent: true, opacity: 0.9, depthWrite: false,
            blending: THREE.AdditiveBlending
        });
        const tail = new THREE.Points(tailGeo, tailMat);
        scene.add(tail);

        comets.push({
            nucleus, tail, tailPos, TAIL,
            history: [],
            t: Math.random() * 100,            // current life-time
            duration: 7 + Math.random() * 5,   // seconds before respawn
            seed: Math.random() * 1000,
        });
    }

    /* Spawn / respawn a comet with a fresh randomized trajectory. */
    function respawnComet(c) {
        // Origin off-screen on one side, target off-screen on the other.
        const fromLeft = Math.random() < 0.5;
        const sx = fromLeft ? -22 : 22;
        const ex =  -sx;
        const sy = (Math.random() - 0.5) * 14 + 4;
        const ey = (Math.random() - 0.5) * 14 - 2;
        const sz = -10 + (Math.random() - 0.5) * 6;
        const ez = -10 + (Math.random() - 0.5) * 6;
        c.start = new THREE.Vector3(sx, sy, sz);
        c.end   = new THREE.Vector3(ex, ey, ez);
        c.t = 0;
        c.duration = 6 + Math.random() * 6;
        c.history.length = 0;
        c.tint = (Math.random() < 0.5) ? 0x7dd3fc : 0xffe9b5;
        c.tail.material.color.setHex(c.tint);
    }
    comets.forEach(respawnComet);

    /* === Drag-to-rotate / parallax on cursor =============================== */
    const mouse = { x: 0, y: 0, raw: { x: 0, y: 0 } };
    window.addEventListener('mousemove', (e) => {
        mouse.x = (e.clientX / window.innerWidth  - 0.5) * 0.5;
        mouse.y = (e.clientY / window.innerHeight - 0.5) * 0.5;
        mouse.raw.x = e.clientX;
        mouse.raw.y = e.clientY;
    });

    const drag = { active: false, x: 0, y: 0, vx: 0, vy: 0, userYaw: 0, userPitch: 0 };
    const dragHint = document.getElementById('drag-hint');
    let dragHinted = false;
    function hideDragHint() {
        if (dragHinted || !dragHint) return;
        dragHinted = true;
        dragHint.classList.add('hidden');
        setTimeout(() => dragHint.remove(), 600);
    }
    function dragStart(e) {
        const p = (e.touches ? e.touches[0] : e);
        drag.active = true; drag.x = p.clientX; drag.y = p.clientY;
        canvas.classList.add('dragging');
        hideDragHint();
    }
    function dragMove(e) {
        if (!drag.active) return;
        const p = (e.touches ? e.touches[0] : e);
        const dx = p.clientX - drag.x;
        const dy = p.clientY - drag.y;
        drag.x = p.clientX; drag.y = p.clientY;
        drag.userYaw   += dx * 0.005;
        drag.userPitch += dy * 0.005;
        drag.vx = dx * 0.005;
        drag.vy = dy * 0.005;
    }
    function dragEnd() {
        drag.active = false;
        canvas.classList.remove('dragging');
    }
    canvas.addEventListener('mousedown', dragStart);
    window.addEventListener('mousemove', dragMove);
    window.addEventListener('mouseup',   dragEnd);
    canvas.addEventListener('touchstart', dragStart, { passive: true });
    window.addEventListener('touchmove',  dragMove,  { passive: true });
    window.addEventListener('touchend',   dragEnd);
    window.addEventListener('scroll', hideDragHint, { passive: true, once: true });

    startScrollTimeline();

    /* === Animation loop ===================================================== */
    let scrollProgress = 0;
    const docHeight = () => document.documentElement.scrollHeight - window.innerHeight;
    const clock = new THREE.Clock();

    function tick() {
        const dt = clock.getDelta();
        const tt = clock.getElapsedTime();
        scrollProgress = Math.min(1, Math.max(0, window.scrollY / Math.max(1, docHeight())));

        /* Star layers drift at slightly different speeds — parallax illusion. */
        starsFar.rotation.y  += 0.00015;
        starsMid.rotation.y  += 0.00030;
        starsNear.rotation.y += 0.00055;
        starsFar.rotation.x  += 0.00008;

        /* Nebulae breathe softly (slow opacity oscillation). */
        nebulae.forEach((n, i) => {
            const o = nebulaDefs[i].opacity;
            n.material.opacity = o * (0.85 + 0.15 * Math.sin(tt * 0.4 + i));
        });

        /* Planet & moon */
        planetTex.offset.x = (planetTex.offset.x + 0.0008) % 1;
        planet.rotation.y += 0.0015;
        moonOrbit.rotation.y += 0.006;
        moonOrbit.rotation.x = Math.sin(tt * 0.2) * 0.05;
        planetRing.rotation.z += 0.0006;

        /* Drag-driven yaw/pitch on the planet group + auto-drift. */
        if (!drag.active) {
            drag.userYaw   += drag.vx * 0.92;
            drag.userPitch += drag.vy * 0.92;
            drag.vx *= 0.94; drag.vy *= 0.94;
            drag.userYaw   *= 0.998;
            drag.userPitch *= 0.998;
        }
        planetGroup.rotation.y = drag.userYaw + tt * 0.04;
        planetGroup.rotation.x = drag.userPitch * 0.6;

        /* Scroll choreography: planet sinks further into the lower-right
           and recedes (z further) as the user scrolls — cinematic parallax
           anchored to the lower-right inset position. */
        const sp = scrollProgress;
        planetGroup.position.x = planetLayout.x + sp * 0.4 + mouse.x * 0.10;
        planetGroup.position.y = planetLayout.y - sp * 0.3 - mouse.y * 0.08;
        planetGroup.position.z = planetLayout.z - sp * 4.5;
        planetGroup.scale.setScalar(planetLayout.s);

        /* Comets: advance along their parametric path; respawn after duration. */
        for (let k = 0; k < comets.length; k++) {
            const c = comets[k];
            c.t += dt;
            if (c.t > c.duration) { respawnComet(c); }

            const u = c.t / c.duration;            // 0..1
            // Slight curve via sine perpendicular to the line.
            const lin = new THREE.Vector3().lerpVectors(c.start, c.end, u);
            const curve = Math.sin(u * Math.PI) * 1.8;
            lin.y += curve * 0.6;
            lin.z += curve * 0.4;
            c.nucleus.position.copy(lin);

            // Pulse / fade nucleus near edges of trajectory.
            const fade = Math.sin(u * Math.PI);
            c.nucleus.material.opacity = fade;
            c.nucleus.scale.setScalar(0.4 + fade * 0.4);

            // Update tail history.
            c.history.unshift(lin.clone());
            if (c.history.length > c.TAIL) c.history.length = c.TAIL;
            for (let i = 0; i < c.TAIL; i++) {
                const h = c.history[i] || lin;
                c.tailPos[i*3+0] = h.x;
                c.tailPos[i*3+1] = h.y;
                c.tailPos[i*3+2] = h.z;
            }
            c.tail.geometry.attributes.position.needsUpdate = true;
            c.tail.material.opacity = 0.9 * fade;
        }

        /* Camera: subtle parallax + scroll-based forward push. */
        camera.position.x = mouse.x * 0.8;
        camera.position.y = 0.6 - mouse.y * 0.5 + sp * 0.4;
        camera.position.z = 9 - Math.min(sp, 0.6) * 2.0;
        camera.lookAt(0.4, -0.3, -2);

        renderer.render(scene, camera);
        requestAnimationFrame(tick);
    }
    tick();

    window.addEventListener('resize', () => {
        planetLayout = getPlanetLayout();
        planetGroup.rotation.z = planetLayout.tilt || 0;
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(window.innerWidth, window.innerHeight, false);
    });
}

/* Анимации появления секций. Используем IntersectionObserver — это нативно,
   надёжно, и не зависит от scroll-движков типа Lenis. GSAP оставляем только
   для входной анимации hero (она запускается сразу, без скролла). */
function startScrollTimeline() {
    /* HERO: текст влетает снизу при загрузке страницы. */
    if (typeof gsap !== 'undefined') {
        gsap.timeline({ defaults: { ease: 'power3.out' } })
            .to('[data-anim="eyebrow"]',  { opacity: 1, y: 0, duration: 0.7 }, 0.2)
            .to('[data-anim="title"]',    { opacity: 1, y: 0, duration: 0.9 }, 0.35)
            .to('[data-anim="subtitle"]', { opacity: 1, y: 0, duration: 0.8 }, 0.55)
            .to('[data-anim="cta"]',      { opacity: 1, y: 0, duration: 0.7 }, 0.7);
    } else {
        document.querySelectorAll('[data-anim]').forEach(el => {
            el.style.opacity = 1; el.style.transform = 'none';
        });
    }

    /* СЕКЦИИ: класс .in-view запускает CSS-keyframe-анимацию входа.
       Триггер срабатывает, когда хотя бы 18% карточки находится в видимой
       зоне. После завершения keyframe навешиваем .settled — он фиксирует
       финальное состояние (opacity:1; transform:none) уже без animation,
       благодаря чему hover-наклон не «отменяет» fill-mode и плитка
       не пропадает. 3D-эффект, таким образом, играется ровно один раз. */
    const reveal = document.querySelectorAll('.section-head, .tile, .auc-card, .adv, .act-5 .title-mega, .act-5 .subtitle, .act-5 .hero-actions');
    const cardSel = ['tile','auc-card','adv'];
    const isCard = el => cardSel.some(c => el.classList.contains(c));
    const settle = el => el.classList.add('settled');

    /* Один глобальный animationend-слушатель — ловит окончания keyframe
       cardFlyLeft / cardFlyRight / cardDropDown / tileFlipIn / advRise. */
    document.addEventListener('animationend', e => {
        if (!e.target || !isCard(e.target)) return;
        if (e.animationName && /Flip|Fly|Drop|Rise|Slide/.test(e.animationName)) {
            settle(e.target);
        }
    }, true);

    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in-view');
                    io.unobserve(entry.target);
                    /* Бэкап: если animationend по какой-то причине не сработал
                       (prefers-reduced-motion, ранний hover, баг браузера) —
                       через ~2.2с принудительно переводим карточку в settled. */
                    if (isCard(entry.target)) {
                        setTimeout(() => settle(entry.target), 2200);
                    }
                }
            });
        }, { threshold: 0.18, rootMargin: '0px 0px -8% 0px' });
        reveal.forEach(el => io.observe(el));
    } else {
        reveal.forEach(el => { el.classList.add('in-view'); if (isCard(el)) settle(el); });
    }
    /* СТРАХОВКА: если элементы изначально уже частично в зоне видимости
       (короткая страница, быстрый загруз), а IO почему-то не сработал —
       форсим показ через 2 секунды. */
    setTimeout(() => {
        reveal.forEach(el => {
            if (!el.classList.contains('in-view')) {
                const r = el.getBoundingClientRect();
                if (r.top < window.innerHeight && r.bottom > 0) {
                    el.classList.add('in-view');
                    if (isCard(el)) setTimeout(() => settle(el), 2200);
                }
            }
        });
    }, 2000);
}

/* Если 3D отключён — анимации hero всё равно нужно показать сразу,
   а подсказку про вращение прячем (нечего вращать). */
if (skip3D) {
    document.querySelectorAll('[data-anim]').forEach(el => { el.style.opacity = 1; el.style.transform = 'none'; });
    const dh = document.getElementById('drag-hint');
    if (dh) dh.style.display = 'none';
}

/* Плавный скролл — через нативный CSS scroll-behavior: smooth (см. стили).
   Lenis убрали — он конфликтовал с ScrollTrigger и блокировал анимации секций. */

/* Страховка: если GSAP по любой причине не отработал за 2 с — выводим всё как есть. */
setTimeout(() => {
    if (typeof gsap === 'undefined' || !document.querySelector('.tile')) return;
    /* Ничего не делаем — GSAP уже сам поставил элементы в нужное положение.
       Но если секции остались с opacity: 0 — принудительно сбрасываем. */
    document.querySelectorAll('.tile, .auc-card, .adv').forEach(el => {
        const cs = getComputedStyle(el);
        if (parseFloat(cs.opacity) < 0.05) {
            el.style.opacity = 1; el.style.transform = 'none';
        }
    });
    if (typeof ScrollTrigger !== 'undefined') ScrollTrigger.refresh();
}, 2000);
</script>

<style>
/* Hero — текст спрятан до запуска GSAP-таймлайна. */
[data-anim] { opacity: 0; transform: translateY(24px); }

/* Глубокая 3D-перспектива — чтобы перевороты выглядели объёмными. */
.tiles-grid, .h-scroll-track, .adv-grid { perspective: 900px; perspective-origin: 50% 30%; }

/* Заголовки секций и финальный блок — простые transition-анимации. */
.section-head .section-eyebrow,
.section-head .section-title,
.act-5 .title-mega, .act-5 .subtitle, .act-5 .hero-actions {
    opacity: 0.0001;
    transform: translateY(40px);
    transition: opacity 1s cubic-bezier(.2,.7,.2,1), transform 1s cubic-bezier(.2,.7,.2,1);
    will-change: transform, opacity;
}
.in-view, .in-view .section-eyebrow, .in-view .section-title,
.act-5 .title-mega.in-view, .act-5 .subtitle.in-view, .act-5 .hero-actions.in-view {
    opacity: 1; transform: none;
}
.section-head.in-view .section-eyebrow { transition-delay: .05s; }
.section-head.in-view .section-title   { transition-delay: .15s; }
.act-5 .subtitle.in-view    { transition-delay: .15s; }
.act-5 .hero-actions.in-view{ transition-delay: .30s; }

/* === КАРТОЧКИ ФОРМАТОВ ТОРГОВ ============================================
   Анимация на @keyframes (а не transition) — гарантирует, что эффект
   проиграется ровно как задумано: карточка летит издалека, проскакивает
   чуть дальше нужной точки и пружинно возвращается. */
.auc-card, .tile, .adv {
    opacity: 0;
    transform-style: preserve-3d;
    backface-visibility: hidden;
    will-change: transform, opacity;
}
/* По умолчанию (если IO ещё не сработал) — невидимы. */

/* ===== УСТОЯВШЕЕСЯ СОСТОЯНИЕ ==============================================
   После того как keyframe-анимация входа отыграла свой 1 раз, JS вешает на
   карточку класс .settled. С этого момента карточка стабильна: opacity:1,
   transform:none, ни одна keyframe не висит. Hover работает поверх этого
   состояния как обычный transform/box-shadow без побочных эффектов. */
.auc-card.settled, .tile.settled, .adv.settled {
    opacity: 1 !important;
    transform: none;
    animation: none !important;
}

/* Появление карточек: мягкое всплытие с лёгким 3D-разворотом.
   Без резких разворотов на 90°, без блюра, без яркой вспышки —
   единый плавный приход всех карточек. */
@keyframes cardSlideLeft {
    0%   { opacity: 0; transform: translate3d(-60px, 30px, 0) rotateY(-12deg) scale(.96); }
    100% { opacity: 1; transform: translate3d(  0,    0,  0) rotateY(  0deg) scale(1); }
}
@keyframes cardSlideUp {
    0%   { opacity: 0; transform: translate3d( 0,  60px, 0) rotateX(  8deg) scale(.96); }
    100% { opacity: 1; transform: translate3d( 0,    0,  0) rotateX(  0deg) scale(1); }
}
@keyframes cardSlideRight {
    0%   { opacity: 0; transform: translate3d( 60px, 30px, 0) rotateY( 12deg) scale(.96); }
    100% { opacity: 1; transform: translate3d(  0,    0,  0) rotateY(  0deg) scale(1); }
}

.h-scroll-track > .auc-card.in-view {
    animation: cardSlideLeft 0.85s cubic-bezier(.22,.61,.36,1) both;
}
.h-scroll-track > .auc-card:nth-child(3n+2).in-view {
    animation: cardSlideUp 0.85s cubic-bezier(.22,.61,.36,1) both;
}
.h-scroll-track > .auc-card:nth-child(3n).in-view {
    animation: cardSlideRight 0.85s cubic-bezier(.22,.61,.36,1) both;
}

/* Stagger между карточками — мягкая волна. */
.h-scroll-track > .auc-card:nth-child(1).in-view { animation-delay: .00s; }
.h-scroll-track > .auc-card:nth-child(2).in-view { animation-delay: .10s; }
.h-scroll-track > .auc-card:nth-child(3).in-view { animation-delay: .20s; }
.h-scroll-track > .auc-card:nth-child(4).in-view { animation-delay: .30s; }
.h-scroll-track > .auc-card:nth-child(5).in-view { animation-delay: .40s; }
.h-scroll-track > .auc-card:nth-child(6).in-view { animation-delay: .50s; }

/* === ПЛИТКИ РОЛЕЙ ========================================================== */
@keyframes tileFlipIn {
    0%   { opacity: 0; transform: rotateX(20deg) translateY(60px) scale(.96); }
    100% { opacity: 1; transform: rotateX(0)     translateY(0)    scale(1); }
}
.tiles-grid > .tile.in-view { animation: tileFlipIn 0.85s cubic-bezier(.22,.61,.36,1) both; }
.tiles-grid > .tile:nth-child(1).in-view { animation-delay: 0s; }
.tiles-grid > .tile:nth-child(2).in-view { animation-delay: .12s; }
.tiles-grid > .tile:nth-child(3).in-view { animation-delay: .24s; }

/* === ПРЕИМУЩЕСТВА ========================================================= */
@keyframes advRise {
    0%   { opacity: 0; transform: translateY(50px) scale(.96); }
    100% { opacity: 1; transform: translateY(0)    scale(1); }
}
.adv-grid > .adv.in-view { animation: advRise 0.8s cubic-bezier(.22,.61,.36,1) both; }
.adv-grid > .adv:nth-child(1).in-view { animation-delay: .05s; }
.adv-grid > .adv:nth-child(2).in-view { animation-delay: .15s; }
.adv-grid > .adv:nth-child(3).in-view { animation-delay: .25s; }

/* === HOVER (после того, как карточка встала на место) ===================== */
/* Никаких бегущих/мигающих эффектов: hover = только статичный наклон + свечение. */
.auc-card { position: relative; isolation: isolate; }
    to   { background-position:  120% 0; }
}

.auc-card.in-view, .auc-card.settled {
    transition: box-shadow .35s ease, transform .45s cubic-bezier(.2,.9,.3,1.15), border-color .35s;
}
/* HOVER: только лёгкий 3D-наклон + подсветка. Никаких новых полётов
   (входной keyframe уже отыгран и снят классом .settled). */
.auc-card.in-view:hover, .auc-card.settled:hover {
    transform: translateY(-10px) rotateX(-6deg) rotateY(6deg) scale(1.03);
    border-color: rgba(56,189,248,.85);
    box-shadow:
        0 28px 60px rgba(0,0,0,.55),
        0 0 0 2px rgba(56,189,248,1),
        0 0 90px rgba(56,189,248,.6),
        inset 0 0 40px rgba(56,189,248,.18);
    z-index: 3;
}
.auc-card.in-view:hover .ac-icon, .auc-card.settled:hover .ac-icon { transform: translateZ(28px) scale(1.12); }
.auc-card.in-view:hover h4, .auc-card.settled:hover h4       { transform: translateZ(20px); }
.auc-card.in-view:hover p, .auc-card.settled:hover p        { transform: translateZ(10px); }
.auc-card.in-view:hover .badge, .auc-card.settled:hover .badge   { transform: translateZ(24px) scale(1.06); background: rgba(56,189,248,.4); }
.auc-card.in-view .ac-icon, .auc-card.in-view h4, .auc-card.settled .ac-icon, .auc-card.settled h4,
.auc-card.in-view p, .auc-card.in-view .badge, .auc-card.settled p, .auc-card.settled .badge { transition: transform .45s cubic-bezier(.2,.9,.3,1.15), background .35s; }

/* Hover на плитках ролей и преимуществах — только наклон + подсветка,
   без новых 3D-полётов. Срабатывает только в .settled (когда входная
   анимация уже завершена), чтобы не было «пропадания» при наведении. */
.tile.in-view, .tile.settled { transition: box-shadow .35s ease, transform .45s cubic-bezier(.2,.9,.3,1.15), border-color .35s; }
.tile.in-view:hover, .tile.settled:hover {
    transform: translateY(-8px) rotateX(-5deg) rotateY(5deg) scale(1.03);
    border-color: rgba(56,189,248,.8);
    box-shadow:
        0 22px 50px rgba(0,0,0,.55),
        0 0 0 2px rgba(56,189,248,.75),
        0 0 70px rgba(56,189,248,.5),
        inset 0 0 30px rgba(56,189,248,.12);
    z-index: 3;
}
.adv.in-view, .adv.settled { transition: box-shadow .35s ease, transform .45s cubic-bezier(.2,.9,.3,1.15), border-color .35s; }
.adv.in-view:hover, .adv.settled:hover {
    transform: translateY(-6px) rotateX(-4deg) scale(1.03);
    border-color: rgba(56,189,248,.65);
    box-shadow:
        0 18px 44px rgba(0,0,0,.5),
        0 0 0 1.5px rgba(56,189,248,.6),
        0 0 50px rgba(56,189,248,.35);
}

@media (prefers-reduced-motion: reduce) {
    [data-anim],
    .section-head .section-eyebrow,
    .section-head .section-title,
    .tile, .auc-card, .adv,
    .act-5 .title-mega, .act-5 .subtitle, .act-5 .hero-actions {
        opacity: 1 !important; transform: none !important; transition: none !important;
    }
}
</style>

</body>
</html>