<?php
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<footer style="background:#1e293b;color:#94a3b8;padding:20px 5%;font-family:'Inter',sans-serif;width:100%;">
    <div style="max-width:1200px;margin:0 auto;display:flex;flex-wrap:wrap;gap:20px;align-items:center;justify-content:space-between;">
        <div style="display:flex;flex-direction:column;gap:6px;min-width:220px;">
            <img src="logo-forsage-modified.png" alt="Форсаж" style="height:35px;opacity:0.9;">
            <span style="font-size:12px;">© <?= date('Y') ?> ООО «Форсаж». Все права защищены.</span>
            <span style="font-size:11px;color:#94a3b8;">ИНН 7728282160 | ОГРН 1037728010396</span>
        </div>
        <div style="font-size:11px;color:#94a3b8;min-width:220px;text-align:right;">
            г. Москва, ул. Киевская, д. 14, оф. 2а
        </div>
    </div>
</footer>

<a href="tel:+79265894111" style="position:fixed;bottom:20px;right:20px;width:60px;height:60px;background:linear-gradient(135deg,#10b981,#059669);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(16,185,129,0.4);z-index:9999;text-decoration:none;transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"><svg width="28" height="28" fill="white" viewBox="0 0 24 24"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56a.977.977 0 00-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.01 21c.71 0 .99-.63.99-1.18v-3.45c0-.54-.45-.99-.99-.99z"/></svg></a>
