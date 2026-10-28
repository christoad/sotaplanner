<?php
// "Login" button on a shared summit page (summit_share.php). Signs the visitor in,
// then returns them to the same shared page (no summit is saved unless they press
// "Save this summit"). The return target is only ever a share slug, never a free-form URL.
require_once 'config.php';
require_once 'share_lib.php';
session_start();

$db = getDbConnection();
$slug = strtolower(trim((string)($_GET['s'] ?? '')));
$summit = sharePublicSummit($db, $slug);
if (!$summit) {
    header('Location: login.php');
    exit;
}

if (getCurrentCallsign()) {
    header('Location: summit_share.php?s=' . urlencode($summit['slug']));
    exit;
}

$_SESSION['pending_share_return'] = $summit['slug'];
unset($_SESSION['pending_summit_import'], $_SESSION['pending_multi_import'], $_SESSION['import_group_id'], $_SESSION['import_choose_new']);

$sso_ready = defined('SOTA_CLIENT_ID') && SOTA_CLIENT_ID !== '' && isProductionSite();
header('Location: ' . ($sso_ready ? 'oauth_callback.php?action=login' : 'login.php'));
exit;
