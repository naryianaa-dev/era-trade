<?php
/* 403_admin_only.php — заглушка для не-админов, попавших на админский URL.
   Возвращаем 403 (а не 200) чтобы поисковики не индексировали эту страницу
   и чтобы клиентский JS видел корректный статус. */
@session_start();
http_response_code(403);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Доступ ограничен</title>
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<link rel="manifest" href="manifest.json">
<style>
:root {
    --bg: #0f172a;
    --bg-card: #1e293b;
    --border: #334155;
    --text: #e2e8f0;
    --text-dim: #94a3b8;
    --accent: #fbbf24;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    min-height: 100dvh;
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    line-height: 1.6;
}
body {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    padding-bottom: calc(24px + env(safe-area-inset-bottom));
}
.card {
    width: 100%;
    max-width: 480px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 40px 32px;
    text-align: center;
}
.icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(251, 191, 36, 0.12);
    border: 1px solid rgba(251, 191, 36, 0.3);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
}
.icon svg { width: 28px; height: 28px; stroke: var(--accent); }
h1 {
    font-size: 22px;
    font-weight: 600;
    margin-bottom: 10px;
    color: #fff;
}
.sub {
    color: var(--text-dim);
    font-size: 15px;
    margin-bottom: 24px;
}
.note {
    font-size: 13px;
    color: var(--text-dim);
    background: rgba(15, 23, 42, 0.5);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 24px;
    text-align: left;
}
.btn {
    display: inline-block;
    padding: 12px 28px;
    background: #2563eb;
    color: #fff;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 15px;
    transition: background 0.15s ease;
}
.btn:hover { background: #1d4ed8; }
.btn-secondary {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text-dim);
    margin-left: 8px;
}
.btn-secondary:hover { border-color: var(--text-dim); color: var(--text); }
.code {
    margin-top: 18px;
    font-size: 11px;
    color: var(--text-dim);
    letter-spacing: 0.04em;
}
</style>
</head>
<body>
<div class="card">
    <div class="icon">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
    </div>
    <h1>Доступ ограничен</h1>
    <p class="sub">Раздел доступен только администраторам платформы.</p>
    <div class="note">
        Если вы считаете, что у вас должен быть доступ — обратитесь
        к администратору. Ваше посещение этой страницы зафиксировано.
    </div>
    <a class="btn" href="index.php">На главную</a>
    <a class="btn btn-secondary" href="profile.php">В кабинет</a>
    <div class="code">HTTP 403 · Forbidden</div>
</div>
</body>
</html>
