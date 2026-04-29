<?php
/* tg_pending.php
 *
 * Страница «заявка на модерации» / «доступ заблокирован» для тех, кто
 * вошёл через Telegram, но ещё не одобрен (или заблокирован) админом.
 * Сессия с user_id здесь НЕ выставляется, поэтому даже если юзер пытается
 * перейти на /profile.php — его выбросит обратно на index.php.
 */
$reason = $_GET['r']  ?? 'pending';
$user   = $_GET['u']  ?? '';

$titles = [
    'new'     => 'Заявка отправлена админу',
    'pending' => 'Ваш аккаунт ожидает подтверждения',
    'blocked' => 'Доступ к аккаунту заблокирован',
];
$messages = [
    'new'     => 'Спасибо, что вошли через Telegram. Ваша заявка отправлена администратору ЭРА ЭТП. Как только её одобрят — вы получите доступ ко всем функциям платформы. Обычно это занимает от нескольких минут до 1 рабочего дня.',
    'pending' => 'Ваш аккаунт ещё не подтверждён администратором. Когда модерация завершится, вы сможете войти. Если это занимает слишком много времени — свяжитесь с поддержкой.',
    'blocked' => 'Доступ к этому аккаунту был заблокирован администратором. Если вы считаете, что это ошибка — свяжитесь с поддержкой.',
];
$emojis = [
    'new'     => '⏳',
    'pending' => '⏳',
    'blocked' => '⛔',
];

$title = $titles[$reason]   ?? $titles['pending'];
$msg   = $messages[$reason] ?? $messages['pending'];
$emoji = $emojis[$reason]   ?? '⏳';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= htmlspecialchars($title) ?> — ЭРА ЭТП</title>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0f172a">
<style>
:root{--bg:#0f172a;--accent:#0088cc}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:radial-gradient(circle at top right,#1e293b,#0f172a);font-family:'Inter',system-ui,sans-serif;color:#f1f5f9;display:flex;align-items:center;justify-content:center;padding:24px;padding-top:max(24px,env(safe-area-inset-top));padding-bottom:max(24px,env(safe-area-inset-bottom))}
.card{max-width:520px;width:100%;background:#0f172a;border:1px solid rgba(56,189,248,.25);border-radius:20px;padding:36px 32px;box-shadow:0 12px 40px rgba(0,0,0,.45);text-align:center}
.emoji{font-size:64px;line-height:1;margin-bottom:16px}
h1{font-size:22px;font-weight:800;margin:0 0 12px;color:#f1f5f9;line-height:1.25}
p{font-size:15px;color:#cbd5e1;line-height:1.55;margin:0 0 24px}
.user-pill{display:inline-block;background:rgba(56,189,248,.12);color:#7dd3fc;border:1px solid rgba(56,189,248,.3);padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;margin-bottom:20px}
.btn{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;font-weight:700;padding:12px 28px;border-radius:12px;font-size:15px;letter-spacing:.3px;transition:background .2s}
.btn:hover{background:#0077b3}
.muted{display:block;margin-top:14px;font-size:12px;color:#64748b}
</style>
</head>
<body>
<div class="card">
    <div class="emoji"><?= $emoji ?></div>
    <h1><?= htmlspecialchars($title) ?></h1>
    <?php if ($user !== ''): ?>
        <div class="user-pill">@<?= htmlspecialchars($user) ?></div>
    <?php endif; ?>
    <p><?= htmlspecialchars($msg) ?></p>
    <a href="index.php" class="btn">На главную</a>
    <span class="muted">ЭРА ЭТП — Электронная торговая платформа</span>
</div>
</body>
</html>
