<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'header.php';

// Берём язык из сессии — так же, как в header.php
$lang = $_SESSION['lang'] ?? 'ru';

$content = [
    'ru' => [
        'title'         => 'Торги нового поколения',
        'subtitle'      => 'Профессиональная площадка ООО «Форсаж»',
        'btn_start'     => 'НАЧАТЬ РАБОТУ',
        'tile1_h'       => 'УЧАСТНИК',
        'tile1_p'       => 'Поиск актуальных процедур и подача заявок онлайн.',
        'tile2_h'       => 'РЕГИСТРАЦИЯ',
        'tile2_p'       => 'Аккредитация в системе в кратчайшие сроки.',
        'tile3_h'       => 'ОРГАНИЗАТОР',
        'tile3_p'       => 'Управление торгами и эффективная реализация активов.',
        'btn_tariffs'   => 'ТАРИФЫ',
        'btn_reglament' => 'РЕГЛАМЕНТ',
    ],
    'en' => [
        'title'         => 'NEXT-GEN AUCTIONS',
        'subtitle'      => 'Professional solutions for your business',
        'btn_start'     => 'QUICK START',
        'tile1_h'       => 'BIDDER',
        'tile1_p'       => 'Search for current procedures and apply online.',
        'tile2_h'       => 'SIGN UP',
        'tile2_p'       => 'Get accredited in the system in no time.',
        'tile3_h'       => 'ORGANIZER',
        'tile3_p'       => 'Bidding management and efficient sales process.',
        'btn_tariffs'   => 'SERVICE RATES',
        'btn_reglament' => 'REGULATIONS',
    ],
];
$cur = $content[$lang];
?>

<style>
    *, *::before, *::after { box-sizing: border-box; }

    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
        width: 100%;
    }

    .hero-content {
        text-align: center;
        margin-bottom: 32px;
        max-width: 680px;
        width: 100%;
    }

    .hero-content h1 {
        font-size: clamp(24px, 4vw, 44px);
        font-weight: 900;
        color: #0f172a;
        text-transform: uppercase;
        margin: 0 0 10px;
        letter-spacing: -0.5px;
        line-height: 1.15;
    }

    .hero-content .subtitle {
        color: #64748b;
        font-size: clamp(13px, 1.6vw, 15px);
        margin: 0;
        line-height: 1.5;
    }

    .tiles-row {
        display: flex;
        justify-content: center;
        align-items: stretch;
        width: 100%;
        max-width: 900px;
        gap: 20px;
        margin-bottom: 32px;
    }

    .tile-square {
        flex: 1 1 0;
        min-width: 0;
        background: #0a0a0a;
        border-radius: 18px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 32px 20px;
        text-align: center;
        transition: transform 0.3s, background 0.3s, box-shadow 0.3s;
        box-shadow: 0 8px 24px rgba(0,0,0,0.10);
        cursor: default;
    }

    .tile-square:hover {
        transform: translateY(-5px);
        background: #000;
        box-shadow: 0 14px 32px rgba(0,136,204,0.18);
    }

    .tile-square h2 {
        font-size: clamp(13px, 1.6vw, 17px);
        color: #0088cc;
        margin: 0 0 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .tile-square p {
        font-size: clamp(11px, 1.2vw, 13px);
        color: #94a3b8;
        line-height: 1.5;
        margin: 0;
    }

    .cta-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
        width: 100%;
        max-width: 420px;
    }

    .btn-action {
        background: #0088cc;
        color: #fff;
        border: none;
        padding: 16px 40px;
        border-radius: 12px;
        font-weight: 800;
        font-size: clamp(13px, 1.8vw, 15px);
        cursor: pointer;
        transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
        box-shadow: 0 8px 18px rgba(0,136,204,0.22);
        width: 100%;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        white-space: nowrap;
    }

    .btn-action:hover {
        background: #0077b3;
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(0,136,204,0.30);
    }

    .btn-action:active {
        transform: translateY(0);
    }

    .links-under-button {
        display: flex;
        justify-content: center;
        gap: 12px;
        width: 100%;
    }

    .link-under-button {
        color: #64748b;
        text-decoration: none;
        font-weight: 700;
        font-size: clamp(11px, 1.4vw, 13px);
        padding: 9px 16px;
        border-radius: 999px;
        transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.2s;
        border: 1.5px solid #e2e8f0;
        background: #fff;
        flex: 1;
        text-align: center;
        white-space: nowrap;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .link-under-button:hover {
        background: #0088cc;
        color: #fff;
        border-color: #0088cc;
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(0,136,204,0.22);
    }

    /* ── Адаптив ── */
    @media (max-width: 900px) {
        .main-content { padding: 32px 16px; }
        .tiles-row { gap: 14px; max-width: 100%; }
        .tile-square { padding: 24px 14px; }
    }

    @media (max-width: 680px) {
        .main-content {
            padding: 24px 14px;
            justify-content: flex-start;
        }
        .hero-content { margin-bottom: 22px; }
        .hero-content h1 { font-size: clamp(20px, 6vw, 30px); }
        .tiles-row {
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .tile-square {
            width: 100%;
            max-width: 420px;
            flex: none;
            padding: 20px 18px;
            border-radius: 14px;
        }
        .tile-square h2 { font-size: 15px; }
        .tile-square p  { font-size: 13px; }
        .cta-section { max-width: 420px; gap: 12px; }
        .btn-action  { padding: 14px 24px; font-size: 14px; }
        .links-under-button { gap: 10px; }
        .link-under-button  { font-size: 12px; padding: 9px 12px; }
    }

    @media (max-width: 400px) {
        .main-content { padding: 18px 10px; }
        .hero-content h1       { font-size: 20px; }
        .hero-content .subtitle { font-size: 12px; }
        .tile-square { padding: 16px 14px; }
        .btn-action  { font-size: 13px; padding: 12px 16px; }
        .links-under-button { flex-direction: column; gap: 8px; }
        .link-under-button  { font-size: 12px; padding: 10px; }
    }
</style>

<main class="main-content">

    <div class="hero-content">
        <h1><?= htmlspecialchars($cur['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="subtitle"><?= htmlspecialchars($cur['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="tiles-row">
        <div class="tile-square">
            <h2><?= htmlspecialchars($cur['tile1_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($cur['tile1_p'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="tile-square">
            <h2><?= htmlspecialchars($cur['tile2_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($cur['tile2_p'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="tile-square">
            <h2><?= htmlspecialchars($cur['tile3_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($cur['tile3_p'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>

    <div class="cta-section">
        <button class="btn-action"
                onclick="openAuth && openAuth('login')">
            <?= htmlspecialchars($cur['btn_start'], ENT_QUOTES, 'UTF-8') ?>
        </button>
        <div class="links-under-button">
            <a href="tariffs.php" class="link-under-button">
                <?= htmlspecialchars($cur['btn_tariffs'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <a href="regulations.php" class="link-under-button">
                <?= htmlspecialchars($cur['btn_reglament'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>

</main>

<?php include 'footer.php'; ?>