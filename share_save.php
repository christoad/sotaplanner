<?php
// "Save this summit" button on a shared summit page (summit_share.php).
// Remembers which summit the visitor wants, gets them signed in if needed, then hands off
// to onboarding.php: existing users pick a dashboard (or make a new one), brand-new users
// get the first-dashboard walkthrough and tour, and the summit is copied in at the end
// (see importSharedSummitIntoGroup() in share_lib.php).
require_once 'config.php';
require_once 'share_lib.php';
session_start();

$db = getDbConnection();
$slug = strtolower(trim((string)($_GET['s'] ?? '')));
$summit = sharePublicSummit($db, $slug);
if (!$summit) {
    header('Location: ' . (getCurrentCallsign() ? 'index.php' : 'login.php'));
    exit;
}

$_SESSION['pending_summit_import'] = $summit['sota_ref'];
$_SESSION['pending_share_return'] = $summit['slug'];
unset($_SESSION['pending_multi_import'], $_SESSION['import_group_id'], $_SESSION['import_choose_new']);

if (!getCurrentCallsign()) {
    // SOTA SSO only works on the production domain; everywhere else (staging)
    // falls back to the login page and its developer sign-in.
    $sso_ready = defined('SOTA_CLIENT_ID') && SOTA_CLIENT_ID !== '' && isProductionSite();
    header('Location: ' . ($sso_ready ? 'oauth_callback.php?action=login' : 'login.php'));
    exit;
}

header('Location: onboarding.php');
exit;
