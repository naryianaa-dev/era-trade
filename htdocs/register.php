<?php
/* Standalone registration page is deprecated. Registration happens via the
   modal on the home page (openAuth('register') in auth_modal.php). The real
   POST handler lives in register_handler.php. */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

header('Location: index.php?modal=register');
exit;
