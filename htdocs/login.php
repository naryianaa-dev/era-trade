<?php
/* Standalone login page is deprecated. Auth happens via the modal on the
   home page (openAuth('login') in auth_modal.php). We redirect here so any
   legacy link, header.Location call, or external bookmark works. */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$redirect = isset($_GET['redirect']) ? trim($_GET['redirect']) : '';
$qs = 'modal=login';
if ($redirect !== '') {
    $qs .= '&redirect=' . urlencode($redirect);
}
header('Location: index.php?' . $qs);
exit;
