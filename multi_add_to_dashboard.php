<?php
// "Add this Multi-Summit to your own dashboard" button on a shared (public) multi-activation route.
// Remembers which route the visitor wants, gets them signed in if needed, then
// hands off to onboarding.php, which lets existing users pick a dashboard (or
// make a new one), walks new users through creating one (name + starting
// address), and imports the route (see multi_import_lib.php).
require_once 'config.php';
session_start();

$multi_id = (int)($_GET['id'] ?? 0);
$db = getDbConnection();
$stmt = $db->prepare("SELECT id FROM multi_activations WHERE id = ?");
$stmt->execute([$multi_id]);
if (!$stmt->fetch()) {
    header('Location: ' . (getCurrentCallsign() ? 'index.php' : 'login.php'));
    exit;
}

$_SESSION['pending_multi_import'] = $multi_id;
unset($_SESSION['import_group_id'], $_SESSION['import_choose_new']);

if (!getCurrentCallsign()) {
    // SOTA SSO only works on the production domain; everywhere else (staging)
    // falls back to the login page and its developer sign-in.
    $sso_ready = defined('SOTA_CLIENT_ID') && SOTA_CLIENT_ID !== ''
        && str_ends_with(strtolower($_SERVER['HTTP_HOST'] ?? ''), 'sotaplanner.com');
    header('Location: ' . ($sso_ready ? 'oauth_callback.php?action=login' : 'login.php'));
    exit;
}

header('Location: onboarding.php');
exit;
