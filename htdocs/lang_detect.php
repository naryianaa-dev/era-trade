<?php
session_start();
if (!isset($_SESSION['lang'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en', 0, 2);
    $_SESSION['lang'] = ($browser_lang == 'ru') ? 'ru' : 'en';
}
$lang = $_SESSION['lang'];

$text = [
    'ru' => [
        'hero' => 'ТОРГИ НОВОГО ПОКОЛЕНИЯ',
        'sub' => 'ООО «ФОРСАЖ» — надежный доступ к активам',
        'btn' => 'НАЧАТЬ РАБОТУ',
        'auth' => 'УЧАСТНИК',
        'reg' => 'РЕГЛАМЕНТ',
        'tar' => 'ТАРИФЫ',
        'tile1_h' => 'РЕЕСТР<br>ЛОТОВ',
        'tile2_h' => 'БЕЗОПАСНАЯ<br>СДЕЛКА',
        'tile3_h' => 'БЫСТРЫЙ<br>СТАРТ',
    ],
    'en' => [
        'hero' => 'NEXT-GEN AUCTIONS',
        'sub' => 'FORSAGE LLC — reliable access to assets',
        'btn' => 'GET STARTED',
        'auth' => 'SIGN IN',
        'reg' => 'REGULATIONS',
        'tar' => 'TARIFFS',
        'tile1_h' => 'LOT<br>REGISTRY',
        'tile2_h' => 'SECURE<br>DEALS',
        'tile3_h' => 'QUICK<br>START',
    ]
];
$t = $text[$lang];
?>