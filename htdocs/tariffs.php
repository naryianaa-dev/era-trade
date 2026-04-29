<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'header.php';

$lang = $_SESSION['lang'] ?? 'ru';
if (!in_array($lang, ['ru','en'], true)) $lang = 'ru';

$content = [
    'ru' => [
        'title'    => 'Статусы участников',
        'subtitle' => 'Выберите подходящий статус для работы на платформе',
        's1_title'  => 'Уважаемый',
        's1_sub'    => 'Базовый статус для всех участников',
        's1_price'  => 'Бесплатно',
        's1_period' => '',
        's1_f1'     => 'Просмотр лотов',
        's1_f2'     => 'Подача заявок на участие',
        's1_f3'     => 'Личный кабинет',
        's1_f4'     => 'Участие в открытых торгах',
        's1_btn'    => 'Зарегистрироваться',
        's2_badge'  => 'Популярный',
        's2_title'  => 'Ответственный',
        's2_sub'    => 'Расширенный статус для активных участников',
        's2_price'  => '8 000 ₽',
        's2_period' => 'в т.ч. НДС 22%',
        's2_f1'     => 'Все возможности «Уважаемого»',
        's2_f2'     => 'Участие в скандинавских аукционах',
        's2_f3'     => 'Пакеты ставок со скидкой',
        's2_f4'     => 'Приоритетная поддержка',
        's2_f5'     => 'PDF-протоколы торгов',
        's2_btn'    => 'Получить статус',
        's3_title'  => 'Организатор',
        's3_sub'    => 'Для организаторов торговых процедур',
        's3_price'  => 'Бесплатно',
        's3_period' => 'на 12 месяцев',
        's3_f1'     => 'Все возможности «Ответственного»',
        's3_f2'     => 'Размещение лотов без ограничений',
        's3_f3'     => 'Управление торговыми процедурами',
        's3_f4'     => 'Персональный менеджер',
        's3_f5'     => 'Полная документация и отчётность',
        's3_btn'    => 'Стать организатором',
    ],
    'en' => [
        'title'    => 'Participant Statuses',
        'subtitle' => 'Choose the right status for your work on the platform',
        's1_title'  => 'Respected',
        's1_sub'    => 'Basic status for all participants',
        's1_price'  => 'Free',
        's1_period' => '',
        's1_f1'     => 'Browse lots',
        's1_f2'     => 'Submit participation requests',
        's1_f3'     => 'Personal account',
        's1_f4'     => 'Open auction participation',
        's1_btn'    => 'Register',
        's2_badge'  => 'Popular',
        's2_title'  => 'Responsible',
        's2_sub'    => 'Extended status for active participants',
        's2_price'  => '8 000 ₽',
        's2_period' => 'incl. VAT 22%',
        's2_f1'     => 'All Respected features',
        's2_f2'     => 'Scandinavian auction participation',
        's2_f3'     => 'Discounted bid packs',
        's2_f4'     => 'Priority support',
        's2_f5'     => 'PDF auction protocols',
        's2_btn'    => 'Get status',
        's3_title'  => 'Organizer',
        's3_sub'    => 'For auction procedure organizers',
        's3_price'  => 'Free',
        's3_period' => 'for 12 months',
        's3_f1'     => 'All Responsible features',
        's3_f2'     => 'Unlimited lot placement',
        's3_f3'     => 'Manage trading procedures',
        's3_f4'     => 'Personal manager',
        's3_f5'     => 'Full documentation & reporting',
        's3_btn'    => 'Become an organizer',
    ],
];
$c = $content[$lang];
?>

<style>
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0;min-height:100%}
body{background:#f8fafc;font-family:'Inter',system-ui,sans-serif;display:flex;flex-direction:column}
.page-content{flex:1;padding:40px 5%;width:100%}
.content-container{max-width:1100px;margin:0 auto}
.page-content h1{font-size:32px;font-weight:900;color:#1e293b;margin:0 0 6px;text-align:center;text-transform:uppercase;letter-spacing:-0.5px}
.page-content .subtitle{color:#64748b;font-size:15px;margin:0 0 32px;text-align:center}
.tariffs-grid{display:flex;justify-content:center;align-items:stretch;gap:24px;width:100%;flex-wrap:wrap}
.tariff-card{flex:0 1 300px;background:#fff;border-radius:20px;padding:28px 22px;box-shadow:0 8px 24px rgba(0,0,0,0.06);border:2px solid #e2e8f0;display:flex;flex-direction:column;cursor:pointer;transition:transform 0.25s,box-shadow 0.25s,border-color 0.25s}
.tariff-card:hover{transform:translateY(-4px);box-shadow:0 16px 32px rgba(0,136,204,0.12);border-color:#0088cc}
.tariff-card.popular{border-color:#0088cc;box-shadow:0 10px 28px rgba(0,136,204,0.14)}
.popular-badge{background:#0088cc;color:#fff;padding:4px 14px;border-radius:999px;font-size:11px;font-weight:700;display:inline-block;margin-bottom:10px;width:fit-content;text-transform:uppercase;letter-spacing:0.05em}
.tariff-title{font-size:24px;font-weight:900;color:#1e293b;margin-bottom:4px}
.tariff-subtitle{font-size:13px;color:#64748b;margin-bottom:16px;min-height:36px;line-height:1.4}
.tariff-price-block{margin-bottom:16px}
.tariff-price{font-size:28px;font-weight:900;color:#0088cc;line-height:1.1}
.tariff-period{font-size:12px;color:#64748b;margin-top:3px}
.tariff-features{list-style:none;margin:0 0 20px;padding:0;flex:1}
.tariff-features li{margin-bottom:9px;display:flex;align-items:flex-start;gap:8px;color:#475569;font-size:13px;line-height:1.4}
.tariff-features li::before{content:'✓';color:#22c55e;font-weight:800;flex-shrink:0;margin-top:1px}
.tariff-btn{width:100%;padding:13px;background:#0088cc;color:#fff;border:none;border-radius:999px;font-weight:700;font-size:14px;cursor:pointer;transition:background 0.2s,transform 0.2s;text-align:center;margin-top:auto;text-decoration:none;display:block;letter-spacing:0.02em;font-family:inherit}
.tariff-btn:hover{background:#0077b3;transform:translateY(-1px)}
@media(max-width:900px){.page-content{padding:28px 4%}.tariffs-grid{gap:16px}.tariff-card{flex:0 1 280px;padding:22px 18px}}
@media(max-width:680px){.page-content{padding:24px 14px}.page-content h1{font-size:24px}.page-content .subtitle{font-size:13px;margin-bottom:22px}.tariffs-grid{flex-direction:column;align-items:center;gap:14px}.tariff-card{flex:none;width:100%;max-width:420px}.tariff-title{font-size:20px}.tariff-price{font-size:24px}}
@media(max-width:400px){.page-content{padding:18px 10px}.tariff-card{padding:18px 14px;border-radius:16px}.tariff-btn{font-size:13px;padding:11px}}
</style>
<main class="page-content">
    <div class="content-container">
        <h1><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="subtitle"><?= htmlspecialchars($c['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>

        <div class="tariffs-grid">

            <!-- Уважаемый -->
            <div class="tariff-card" onclick="openAuth && openAuth('register')">
                <div class="tariff-title"><?= htmlspecialchars($c['s1_title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tariff-subtitle"><?= htmlspecialchars($c['s1_sub'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tariff-price-block">
                    <div class="tariff-price"><?= htmlspecialchars($c['s1_price'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <ul class="tariff-features">
                    ><?= htmlspecialchars($c['s1_f1'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s1_f2'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s1_f3'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s1_f4'], ENT_QUOTES, 'UTF-8') ?></li>
                </ul>
                <button class="tariff-btn"
                        onclick="event.stopPropagation(); openAuth && openAuth('register')">
                    <?= htmlspecialchars($c['s1_btn'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>

            <!-- Ответственный -->
            <div class="tariff-card popular" onclick="openAuth && openAuth('register')">
                <div class="popular-badge"><?= htmlspecialchars($c['s2_badge'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tariff-title"><?= htmlspecialchars($c['s2_title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tariff-subtitle"><?= htmlspecialchars($c['s2_sub'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tariff-price-block">
                    <div class="tariff-price"><?= htmlspecialchars($c['s2_price'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="tariff-period"><?= htmlspecialchars($c['s2_period'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <ul class="tariff-features">
                    ><?= htmlspecialchars($c['s2_f1'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s2_f2'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s2_f3'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s2_f4'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s2_f5'], ENT_QUOTES, 'UTF-8') ?></li>
                </ul>
                <button class="tariff-btn"
                        onclick="event.stopPropagation(); openAuth && openAuth('register')">
                    <?= htmlspecialchars($c['s2_btn'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>

            <!-- Организатор -->
            <div class="tariff-card" onclick="openAuth && openAuth('register')">
                <div class="tariff-title"><?= htmlspecialchars($c['s3_title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tariff-subtitle"><?= htmlspecialchars($c['s3_sub'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="tariff-price-block">
                    <div class="tariff-price"><?= htmlspecialchars($c['s3_price'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="tariff-period"><?= htmlspecialchars($c['s3_period'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <ul class="tariff-features">
                    ><?= htmlspecialchars($c['s3_f1'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s3_f2'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s3_f3'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s3_f4'], ENT_QUOTES, 'UTF-8') ?></li>
                    ><?= htmlspecialchars($c['s3_f5'], ENT_QUOTES, 'UTF-8') ?></li>
                </ul>
                <button class="tariff-btn"
                        onclick="event.stopPropagation(); openAuth && openAuth('register')">
                    <?= htmlspecialchars($c['s3_btn'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>

        </div>
    </div>
</main>
<?php include 'footer.php'; ?>