<?php include 'header.php'; ?>

<?php
$content = [
    'ru' => [
        'title' => 'Торги нового поколения',
        'subtitle' => 'Профессиональная площадка ООО «Форсаж»',
        'btn_start' => 'НАЧАТЬ РАБОТУ',
        'tile1_h' => 'УЧАСТНИК',
        'tile1_p' => 'Поиск актуальных процедур и подача заявок онлайн.',
        'tile2_h' => 'РЕГИСТРАЦИЯ',
        'tile2_p' => 'Аккредитация в системе в кратчайшие сроки.',
        'tile3_h' => 'ОРГАНИЗАТОР',
        'tile3_p' => 'Управление торгами и эффективная реализация активов.'
    ],
    'en' => [
        'title' => 'NEXT-GEN AUCTIONS',
        'subtitle' => 'Professional solutions for your business',
        'btn_start' => 'START WORKING',
        'tile1_h' => 'PARTICIPANT',
        'tile1_p' => 'Search for current procedures and apply online.',
        'tile2_h' => 'REGISTRATION',
        'tile2_p' => 'Get accredited in the system in no time.',
        'tile3_h' => 'ORGANIZER',
        'tile3_p' => 'Bidding management and effective asset sales.'
    ]
];
$cur = $content[$lang];
?>

<div class="main-content">
    <div class="hero-content">
        <h1><?= $cur['title'] ?></h1>
        <p class="subtitle"><?= $cur['subtitle'] ?></p>
    </div>

    <div class="tiles-row">
        <div class="tile-square">
            <h2><?= $cur['tile1_h'] ?></h2>
            <p><?= $cur['tile1_p'] ?></p>
        </div>
        <div class="tile-square">
            <h2><?= $cur['tile2_h'] ?></h2>
            <p><?= $cur['tile2_p'] ?></p>
        </div>
        <div class="tile-square">
            <h2><?= $cur['tile3_h'] ?></h2>
            <p><?= $cur['tile3_p'] ?></p>
        </div>
    </div>

    <div class="cta-section">
        <button class="btn-action" onclick="openAuth()"><?= $cur['btn_start'] ?></button>
        
        <!-- ТАРИФЫ И РЕГЛАМЕНТ ПРЯМО ПОД КНОПКОЙ -->
        <div class="links-under-button">
            <a href="tariffs.php" class="link-under-button">ТАРИФЫ</a>
            <a href="regulations.php" class="link-under-button">РЕГЛАМЕНТ</a>
        </div>
    </div>
</div>

<!-- ФУТЕР НА МЕСТЕ, БЛЯДЬ! -->
<?php include 'footer.php'; ?>

<style>
    html, body {
        height: 100%;
        margin: 0;
        padding: 0;
        overflow-y: auto; /* Разрешить скролл для fixed header */
    }
    
    body {
        display: flex;
        flex-direction: column;
        font-family: 'Inter', sans-serif;
    }

    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px 5vw;
        overflow-y: auto; /* СКРОЛЛ ТОЛЬКО ЕСЛИ ОЧЕНЬ НАДО, НО НЕ БУДЕТ */
    }

    .hero-content { 
        text-align: center; 
        margin-bottom: 3vh;
    }
    
    .hero-content h1 {
        font-size: clamp(24px, 4vh, 42px);
        font-weight: 900;
        color: #1e293b;
        text-transform: uppercase;
        margin: 0 0 10px;
        letter-spacing: -1px;
        line-height: 1.2;
    }
    
    .subtitle {
        color: #64748b;
        font-size: clamp(13px, 1.8vh, 15px);
        margin: 0;
    }

    .tiles-row {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
        max-width: 1000px;
        gap: clamp(15px, 3vw, 30px);
        margin: 2vh 0;
    }

    .tile-square {
        flex: 0 1 min(250px, 25vw);
        aspect-ratio: 1 / 1;
        background: #0a0a0a;
        border-radius: 16px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: clamp(15px, 3vh, 25px);
        text-align: center;
        transition: transform 0.3s ease, background 0.3s ease;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }

    .tile-square:hover {
        transform: translateY(-5px);
        background: #000;
        box-shadow: 0 15px 35px rgba(0, 136, 204, 0.2);
    }

    .tile-square h2 {
        font-size: clamp(14px, 2vh, 18px);
        color: #0088cc;
        margin: 0 0 10px;
        font-weight: 800;
    }

    .tile-square p {
        font-size: clamp(10px, 1.4vh, 13px);
        color: #94a3b8;
        line-height: 1.4;
        margin: 0;
    }

    .cta-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 15px;
        margin-top: 2vh;
        width: 100%;
        max-width: 400px;
    }
    
    .btn-action {
        background: #0088cc;
        color: #fff;
        border: none;
        padding: clamp(12px, 2vh, 16px) clamp(30px, 5vw, 50px);
        border-radius: 10px;
        font-weight: 800;
        font-size: clamp(14px, 1.8vh, 16px);
        cursor: pointer;
        transition: 0.2s;
        box-shadow: 0 8px 15px rgba(0, 136, 204, 0.2);
        width: 100%;
        white-space: nowrap;
    }
    .btn-action:hover { 
        transform: scale(1.03); 
        background: #0077b3; 
    }
    
    /* ССЫЛКИ ПОД КНОПКОЙ */
    .links-under-button {
        display: flex;
        justify-content: center;
        gap: 15px;
        width: 100%;
    }
    
    .link-under-button {
        color: #64748b;
        text-decoration: none;
        font-weight: 700;
        font-size: clamp(12px, 1.6vh, 14px);
        padding: 8px 15px;
        border-radius: 25px;
        transition: all 0.2s;
        border: 1px solid #e2e8f0;
        background: white;
        flex: 1;
        text-align: center;
        white-space: nowrap;
    }
    
    .link-under-button:hover {
        background: #0088cc;
        color: white;
        border-color: #0088cc;
        transform: translateY(-2px);
        box-shadow: 0 5px 12px rgba(0, 136, 204, 0.2);
    }

    @media (max-width: 768px) {
        .main-content {
            padding: 15px 3vw;
        }
        .hero-content {
            margin-bottom: 2vh;
        }
        .tiles-row {
            flex-direction: column;
            gap: 15px;
            max-width: 300px;
        }
        .tile-square {
            flex: none;
            width: 100%;
            max-width: 300px;
        }
        .cta-section {
            gap: 12px;
            margin-top: 1vh;
        }
        .links-under-button {
            flex-direction: column;
            gap: 10px;
        }
    }

    @media (max-width: 480px) {
        .main-content {
            padding: 10px 2vw;
        }
        .hero-content h1 {
            font-size: 28px;
        }
        .subtitle {
            font-size: 12px;
        }
        .tile-square {
            padding: 15px;
        }
        .tile-square h2 {
            font-size: 16px;
        }
        .tile-square p {
            font-size: 12px;
        }
        .btn-action {
            font-size: 14px;
            padding: 12px 20px;
        }
    }
</style>