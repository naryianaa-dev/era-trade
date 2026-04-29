<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'header.php';
?>
<style>
.doc-wrap { max-width: 860px; margin: 0 auto; padding: 40px 20px 60px; }
.doc-wrap h1 { font-size: 26px; font-weight: 900; color: #0f172a; margin-bottom: 6px; }
.doc-wrap .doc-date { font-size: 13px; color: #94a3b8; margin-bottom: 32px; }
.doc-wrap h2 { font-size: 17px; font-weight: 800; color: #0f172a; margin: 28px 0 10px; }
.doc-wrap p, .doc-wrap li { font-size: 14px; color: #334155; line-height: 1.75; }
.doc-wrap ul { padding-left: 20px; margin: 8px 0; }
.doc-wrap table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 13px; }
.doc-wrap td, .doc-wrap th { border: 1px solid #e2e8f0; padding: 8px 12px; text-align: left; }
.doc-wrap th { background: #f8fafc; font-weight: 700; }
</style>
<main style="flex:1; background:#f8fafc;">
<div class="doc-wrap">
    <h1>Политика использования файлов Cookie</h1>
    <div class="doc-date">Редакция от 25 апреля 2026 г.</div>
    <h2>1. Что такое Cookie</h2>
    <p>Cookie — небольшие текстовые файлы, сохраняемые браузером на устройстве Пользователя при посещении сайта. Они позволяют сайту «запомнить» действия и предпочтения Пользователя.</p>
    <h2>2. Какие Cookie мы используем</h2>
    <table><tr><th>Тип</th><th>Название</th><th>Цель</th><th>Срок</th></tr>
    <tr><td>Обязательные</td><td>PHPSESSID</td><td>Поддержание пользовательской сессии (авторизация)</td><td>Сессия</td></tr>
    <tr><td>Функциональные</td><td>lang</td><td>Сохранение языка интерфейса</td><td>30 дней</td></tr>
    </table>
    <h2>3. Управление Cookie</h2>
    <p>Вы можете отключить Cookie в настройках браузера. Обратите внимание: отключение обязательных Cookie приведёт к невозможности авторизации на сайте.</p>
    <h2>4. Контакты</h2>
    <p>По вопросам использования Cookie: info@forsage.ru</p>
</div>
</main>
<?php include 'footer.php'; ?>
