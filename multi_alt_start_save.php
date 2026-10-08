<?php
// AJAX endpoint for Multi-Activate alternate hike starts (see multi_alt_lib.php).
// POST: action = set | reset | restore | dismiss, from_id, to_id (summit ids in the current
// dashboard), plus lat/lng for "set".
require_once 'config.php';
require_once 'multi_alt_lib.php';
session_start();
requireLogin();

header('Content-Type: application/json');

function alt_fail($msg) {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') alt_fail('POST only');

$db = getDbConnection();
$group = getCurrentPlanningGroup($db);
if (!$group) alt_fail('No dashboard selected');

$action  = $_POST['action'] ?? '';
$from_id = (int)($_POST['from_id'] ?? 0);
$to_id   = (int)($_POST['to_id'] ?? 0);
if (!in_array($action, ['set', 'reset', 'restore', 'dismiss'], true) || !$from_id || !$to_id || $from_id === $to_id) {
    alt_fail('Bad request');
}

$stmt = $db->prepare("SELECT id, sota_ref, name, latitude, longitude FROM summits WHERE planning_group_id = ? AND id IN (?, ?)");
$stmt->execute([$group['id'], $from_id, $to_id]);
$rows = [];
foreach ($stmt->fetchAll() as $r) $rows[(int)$r['id']] = $r;
if (!isset($rows[$from_id], $rows[$to_id])) alt_fail('Summit not found in this dashboard');

$from = $rows[$from_id]['sota_ref'];
$to   = $rows[$to_id]['sota_ref'];
$me   = getCurrentCallsign();

if ($action === 'set') {
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0)) alt_fail('Invalid coordinates');
    $d = haversine_miles($lat, $lng, (float)$rows[$to_id]['latitude'], (float)$rows[$to_id]['longitude']);
    if ($d > MULTI_ALT_MAX_FROM_SUMMIT_MI) {
        alt_fail('That point is ' . round($d) . ' miles from ' . $rows[$to_id]['name'] . '. Pick a spot on or near its trail.');
    }
    multi_alt_set($db, $from, $to, round($lat, 7), round($lng, 7), $me);
    logActivity($db, 'Multi alt start set', "$from > $to", "$lat,$lng", $group['name']);
    echo json_encode(['ok' => true]);
} elseif ($action === 'reset') {
    $res = multi_alt_reset($db, $from, $to, $me);
    logActivity($db, 'Multi alt start reset', "$from > $to", $res, $group['name']);
    echo json_encode(['ok' => true, 'result' => $res]);
} elseif ($action === 'dismiss') {
    multi_alt_dismiss($db, $from, $to, $me);
    echo json_encode(['ok' => true]);
} else {
    multi_alt_restore($db, $from, $to, $me);
    echo json_encode(['ok' => true]);
}
