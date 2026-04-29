<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($lang)) $lang = $_SESSION['lang'] ?? 'ru';
?>
<footer style="background:#1e293b;color:#94a3b8;padding:20px 5%;font-family:'Inter',sans-serif;width:100%;">
    <div style="max-width:1200px;margin:0 auto;display:flex;flex-wrap:wrap;gap:20px;align-items:center;justify-content:space-between;">
        <!-- Логотип слева + копирайт -->
        <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:flex-start;gap:6px;">
            <img src="logo-forsage-modified.png" alt="Форсаж" style="height:40px;width:auto;opacity:0.9;">
            <div style="font-size:10px;color:#64748b;"><?= $lang === 'en' ? '© ' . date('Y') . ' Forsage LLC' : '© ' . date('Y') . ' ООО «Форсаж»' ?></div>
        </div>
        
        <!-- Центр: ИНН/ОГРН и адрес -->
        <div style="flex:1;text-align:center;font-size:11px;display:flex;flex-direction:column;gap:4px;">
            <div style="font-weight:600;"><?= $lang === 'en' ? 'TIN 7728282160 | OGRN 1037728010396' : 'ИНН 7728282160 | ОГРН 1037728010396' ?></div>
            <div><?= $lang === 'en' ? 'Moscow, Kievskaya St. 14, office 2A' : 'г. Москва, ул. Киевская, д. 14, оф. 2а' ?></div>
        </div>
        
        <!-- Кнопка звонка справа -->
        <div style="flex-shrink:0;">
            <a href="tel:+79265894191" style="width:50px;height:50px;background:linear-gradient(135deg,#10b981,#059669);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(16,185,129,0.4);text-decoration:none;transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                <svg width="24" height="24" fill="white" viewBox="0 0 24 24"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56a.977.977 0 00-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.01 21c.71 0 .99-.63.99-1.18v-3.45c0-.54-.45-.99-.99-.99z"/></svg>
            </a>
        </div>
    </div>
    <div style="max-width:1200px;margin:8px auto 0;padding-top:12px;border-top:1px solid #334155;display:flex;flex-wrap:wrap;gap:16px;font-size:12px;">
        <a href="user_agreement.php" style="color:#64748b;text-decoration:none;" onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#64748b'"><?= $lang === 'en' ? 'Terms of Service' : 'Пользовательское соглашение' ?></a>
        <a href="personal_data.php" style="color:#64748b;text-decoration:none;" onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#64748b'"><?= $lang === 'en' ? 'Personal Data Processing' : 'Обработка персональных данных' ?></a>
        <a href="cookie_policy.php" style="color:#64748b;text-decoration:none;" onmouseover="this.style.color='#94a3b8'" onmouseout="this.style.color='#64748b'"><?= $lang === 'en' ? 'Cookie Policy' : 'Политика Cookie' ?></a>
        <span style="margin-left:auto;color:#475569;"><?= $lang === 'en' ? '© 2024–2026 Forsage LLC · ERA ETP · FZ-152' : '© 2024–2026 ООО «Форсаж» · ERA ETP · ФЗ-152' ?></span>
    </div>
</footer>
