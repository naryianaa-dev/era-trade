<?php
session_start();

// Принудительно ставим данные в сессию
$_SESSION['user_id'] = 2; 
$_SESSION['user_name'] = 'Dealer1';
$_SESSION['role'] = 'dealer';

echo "<div style='text-align:center; margin-top:100px; font-family:sans-serif;'>";
echo "<h1 style='color:green;'>✅ АВТОРИЗАЦИЯ УСПЕШНА!</h1>";
echo "<p>Теперь ты официально <b>Dealer1</b>.</p>";
echo "<a href='reestr.php' style='display:inline-block; padding:15px 30px; background:#2563eb; color:#fff; text-decoration:none; border-radius:10px; font-weight:bold;'>ИДТИ В РЕЕСТР И СТАВИТЬ БАБКИ</a>";
echo "</div>";