<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'header.php';

$lang = $_SESSION['lang'] ?? 'ru';
if (!in_array($lang, ['ru','en'], true)) $lang = 'ru';

$content = [
    'ru' => [
        'title'    => 'Регламент',
        'subtitle' => 'Правила работы на электронной торговой площадке',
        's1_h' => '1. Общие положения',
        's1_p' => '1.1. Настоящий регламент определяет порядок проведения торгов на платформе ООО «Форсаж».<br>
                   1.2. Участие в торгах означает полное согласие с условиями настоящего регламента.<br>
                   1.3. Платформа оставляет за собой право вносить изменения в регламент с уведомлением участников.',
        's2_h' => '2. Условия участия',
        's2_p' => '2.1. К участию в торгах допускаются физические и юридические лица, прошедшие аккредитацию.<br>
                   2.2. Участник обязан предоставить достоверные сведения при регистрации.<br>
                   2.3. Один участник не может иметь более одного аккаунта на платформе.',
        's3_h' => '3. Тарифы и оплата',
        's3_p' => '3.1. Стоимость участия в скандинавском аукционе составляет от 8 000 ₽ за тариф.<br>
                   3.2. Оплата производится до начала участия в торгах.<br>
                   3.3. Все суммы указаны с учётом НДС 22%.',
        's4_h' => '4. Порядок проведения торгов',
        's4_p' => '4.1. Торги проводятся в электронной форме на платформе ООО «Форсаж».<br>
                   4.2. Победителем признаётся участник, сделавший последнюю ставку до окончания таймера.<br>
                   4.3. Результаты торгов фиксируются в протоколе и являются окончательными.',
        's5_h' => '5. Ответственность сторон',
        's5_p' => '5.1. Платформа не несёт ответственности за технические сбои на стороне участника.<br>
                   5.2. Участник несёт полную ответственность за сохранность своих учётных данных.<br>
                   5.3. Все спорные ситуации разрешаются в соответствии с законодательством РФ.',
        's6_h' => '6. Конфиденциальность',
        's6_p' => '6.1. Персональные данные участников обрабатываются в соответствии с ФЗ-152.<br>
                   6.2. Платформа обязуется не передавать данные участников третьим лицам без их согласия.<br>
                   6.3. Участник соглашается на обработку персональных данных при регистрации.',
    ],
    'en' => [
        'title'    => 'Regulations',
        'subtitle' => 'Rules of operation on the electronic trading platform',
        's1_h' => '1. General Terms and conditions',
        's1_p' => '1.1. These regulations define the procedure for conducting auctions on the Forsazh LLC platform.<br>
                   1.2. Participation in auctions means full agreement with the terms of these regulations.<br>
                   1.3. The platform reserves the right to amend the regulations with participant notification.',
        's2_h' => '2. Participation Conditions',
        's2_p' => '2.1. Individuals and legal entities who have completed accreditation are admitted to auctions.<br>
                   2.2. The participant must provide accurate information during registration.<br>
                   2.3. One participant cannot have more than one account on the platform.',
        's3_h' => '3. Tariffs and Payment',
        's3_p' => '3.1. The cost of participating in a Scandinavian auction starts from 8,000 ₽ per subscription.<br>
                   3.2. Payment is made before the start of participation in auctions.<br>
                   3.3. All amounts include 22% VAT.',
        's4_h' => '4. Auction Procedure',
        's4_p' => '4.1. Auctions are conducted electronically on the Forsazh LLC platform.<br>
                   4.2. The winner is the participant who made the last bid before the timer expired.<br>
                   4.3. Auction results are recorded in the protocol and are final.',
        's5_h' => '5. Liability',
        's5_p' => '5.1. The platform is not responsible for technical failures on the participant\'s side.<br>
                   5.2. The participant bears full responsibility for the security of their credentials.<br>
                   5.3. All disputes are resolved in accordance with the legislation of the Russian Federation.',
        's6_h' => '6. Confidentiality',
        's6_p' => '6.1. Participants\' personal data is processed in accordance with Federal Law No. 152.<br>
                   6.2. The platform undertakes not to transfer participants\' data to third parties without their consent.<br>
                   6.3. The participant agrees to the processing of personal data upon registration.',
    ],
];
$c = $content[$lang];
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($c['title']) ?> | ERA ETP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            flex-direction: column;
        }
        /* ГЛАВНОЕ: увеличенный отступ сверху, чтобы хедер не перекрывал */
        .page-content {
            flex: 1;
            width: 100%;
            padding: 120px 20px 40px; /* 120px – запас, хедер точно ниже */
        }
        .content-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .page-content h1 {
            font-size: 2rem;
            font-weight: 900;
            color: #1e293b;
            margin: 0 0 6px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: -0.5px;
        }
        .page-content .subtitle {
            color: #64748b;
            font-size: 1rem;
            margin: 0 0 32px;
            text-align: center;
            line-height: 1.5;
        }
        .reglament-card {
            background: #ffffff;
            border-radius: 1rem;
            padding: 2rem 2.25rem;
            box-shadow: 0 8px 20px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 1.25rem;
            transition: transform 0.1s ease, box-shadow 0.2s ease;
        }
        .reglament-card:last-child {
            margin-bottom: 0;
        }
        .reglament-card:hover {
            box-shadow: 0 12px 24px rgba(0,0,0,0.05);
        }
        .reglament-card h2 {
            font-size: 1.35rem;
            font-weight: 800;
            color: #0088cc;
            margin: 0 0 1rem;
            line-height: 1.3;
        }
        .reglament-card p {
            color: #334155;
            line-height: 1.75;
            margin: 0;
            font-size: 0.95rem;
        }

        @media (max-width: 900px) {
            .page-content { padding: 105px 16px 32px; }
            .reglament-card { padding: 1.5rem 1.75rem; }
            .reglament-card h2 { font-size: 1.25rem; }
        }
        @media (max-width: 680px) {
            .page-content { padding: 95px 12px 24px; }
            .page-content h1 { font-size: 1.75rem; }
            .page-content .subtitle { font-size: 0.85rem; margin-bottom: 24px; }
            .reglament-card { padding: 1.25rem 1rem; border-radius: 0.875rem; margin-bottom: 1rem; }
            .reglament-card h2 { font-size: 1.1rem; margin-bottom: 0.75rem; }
            .reglament-card p { font-size: 0.85rem; line-height: 1.65; }
        }
        @media (max-width: 480px) {
            .page-content { padding: 85px 10px 20px; }
            .page-content h1 { font-size: 1.5rem; }
            .reglament-card { padding: 1rem 0.875rem; }
            .reglament-card h2 { font-size: 1rem; }
            .reglament-card p { font-size: 0.8rem; line-height: 1.6; }
        }
    </style>
</head>
<body>
<main class="page-content">
    <div class="content-container">
        <h1><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="subtitle"><?= htmlspecialchars($c['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>

        <div class="reglament-card">
            <h2><?= htmlspecialchars($c['s1_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= $c['s1_p'] ?></p>
        </div>
        <div class="reglament-card">
            <h2><?= htmlspecialchars($c['s2_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= $c['s2_p'] ?></p>
        </div>
        <div class="reglament-card">
            <h2><?= htmlspecialchars($c['s3_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= $c['s3_p'] ?></p>
        </div>
        <div class="reglament-card">
            <h2><?= htmlspecialchars($c['s4_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= $c['s4_p'] ?></p>
        </div>
        <div class="reglament-card">
            <h2><?= htmlspecialchars($c['s5_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= $c['s5_p'] ?></p>
        </div>
        <div class="reglament-card">
            <h2><?= htmlspecialchars($c['s6_h'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= $c['s6_p'] ?></p>
        </div>
    </div>
</main>

<?php include 'footer.php'; ?>