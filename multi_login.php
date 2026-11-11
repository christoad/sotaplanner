<?php
// "Login" button on a shared (public) multi-activation route. Signs the visitor in, then returns
// them to the same route page (nothing is saved unless they press "Save this route"). The return
// target is only ever a numeric route id, never a free-form URL.
require_once 'config.php';
session_start();

$multi_id = (int)($_GET['id'] ?? 0);
$db = getDbConnection();
$stmt = $db->prepare("SELECT id FROM multi_activations WHERE id = ?");
$stmt->execute([$multi_id]);
if (!$stmt->fetch()) {
    header('Location: login.php');
    exit;
}

if (getCurrentCallsign()) {
    header('Location: multi_activate.php?id=' . $multi_id);
    exit;
}

$_SESSION['pending_multi_return'] = $multi_id;
unset($_SESSION['pending_summit_import'], $_SESSION['pending_multi_import'], $_SESSION['pending_share_return'], $_SESSION['import_group_id'], $_SESSION['import_choose_new']);

$sso_ready = defined('SOTA_CLIENT_ID') && SOTA_CLIENT_ID !== '' && isProductionSite();
header('Location: ' . ($sso_ready ? 'oauth_callback.php?action=login' : 'login.php'));
exit;
