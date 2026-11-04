<?php
// AJAX endpoint for Multi-Activate leg settings (see multi_leg_lib.php).
// POST action = mode    : multi_id, from_id, to_id, mode = car | hike | auto
// POST action = act_time: multi_id, summit_id, minutes (0 = route default)
// POST action = after   : multi_id, from_id, to_id (0 if last), choice = hike | back | new
// POST action = exit    : multi_id, summit_id, exit = back | new (walk down the way we came, or on to this summit's own trailhead)
// POST action = finish  : multi_id, address_id (0 = back at the starting address)
require_once 'config.php';
require_once 'multi_leg_lib.php';
session_start();
requireLogin();
session_write_close();

header('Content-Type: application/json');

function leg_fail($msg) {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') leg_fail('POST only');

$db = getDbConnection();
$group = getCurrentPlanningGroup($db);
if (!$group) leg_fail('No dashboard selected');

$multi_id = (int)($_POST['multi_id'] ?? 0);
$st = $db->prepare("SELECT id FROM multi_activations WHERE id = ? AND planning_group_id = ?");
$st->execute([$multi_id, $group['id']]);
if (!$st->fetch()) leg_fail('Route not found in this dashboard');

$action = $_POST['action'] ?? '';
if ($action === 'mode') {
    $from_id = (int)($_POST['from_id'] ?? 0);
    $to_id   = (int)($_POST['to_id'] ?? 0);
    $mode    = $_POST['mode'] ?? '';
    if (!$from_id || !$to_id || !in_array($mode, ['car', 'hike', 'auto'], true)) leg_fail('Bad request');
    $st = $db->prepare("SELECT COUNT(*) FROM summits WHERE planning_group_id = ? AND id IN (?, ?)");
    $st->execute([$group['id'], $from_id, $to_id]);
    if ((int)$st->fetchColumn() < ($from_id === $to_id ? 1 : 2)) leg_fail('Summit not found in this dashboard');
    multi_leg_set($db, $multi_id, $from_id, $to_id, $mode);
    logActivity($db, 'Multi leg mode', "$from_id > $to_id", $mode, $group['name']);
    echo json_encode(['ok' => true]);
} elseif ($action === 'act_time') {
    $sid = (int)($_POST['summit_id'] ?? 0);
    $st = $db->prepare("SELECT id FROM summits WHERE id = ? AND planning_group_id = ?");
    $st->execute([$sid, $group['id']]);
    if (!$sid || !$st->fetch()) leg_fail('Summit not found in this dashboard');
    multi_stop_time_set($db, $multi_id, $sid, (int)($_POST['minutes'] ?? 0));
    logActivity($db, 'Multi stop time', "route $multi_id summit $sid", (string)(int)($_POST['minutes'] ?? 0), $group['name']);
    echo json_encode(['ok' => true]);
} elseif ($action === 'after') {
    $from_id = (int)($_POST['from_id'] ?? 0);
    $to_id   = (int)($_POST['to_id'] ?? 0);   // 0 for the last summit
    $choice  = $_POST['choice'] ?? '';
    if (!$from_id || !in_array($choice, ['hike', 'back', 'new'], true) || ($choice === 'hike' && !$to_id)) leg_fail('Bad request');
    $ids = array_values(array_unique(array_filter([$from_id, $to_id])));
    $st = $db->prepare("SELECT COUNT(*) FROM summits WHERE planning_group_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute(array_merge([$group['id']], $ids));
    if ((int)$st->fetchColumn() < count($ids)) leg_fail('Summit not found in this dashboard');
    if ($choice === 'hike') {
        $rows = $db->prepare("SELECT id, sota_ref, latitude, longitude, elevation_m FROM summits WHERE planning_group_id = ? AND id IN (?, ?)");
        $rows->execute([$group['id'], $from_id, $to_id]);
        $sm = [];
        foreach ($rows->fetchAll() as $r) $sm[(int)$r['id']] = ['ref' => $r['sota_ref'], 'summit_lat' => (float)$r['latitude'], 'summit_lng' => (float)$r['longitude'], 'elev_m' => (float)$r['elevation_m']];
        $pair = multi_leg_route_pair($sm[$from_id], $sm[$to_id]);
        if (!$pair['ok']) leg_fail('Hike direct is not possible here. ' . $pair['error']);
        multi_leg_set($db, $multi_id, $from_id, $to_id, 'hike');
    } else {
        if ($to_id) multi_leg_set($db, $multi_id, $from_id, $to_id, 'car');
        multi_exit_set($db, $multi_id, $from_id, $choice === 'new');
    }
    logActivity($db, 'Multi after summit', "route $multi_id summit $from_id", $choice, $group['name']);
    echo json_encode(['ok' => true]);
} elseif ($action === 'exit') {
    $sid = (int)($_POST['summit_id'] ?? 0);
    $st = $db->prepare("SELECT id FROM summits WHERE id = ? AND planning_group_id = ?");
    $st->execute([$sid, $group['id']]);
    if (!$sid || !$st->fetch()) leg_fail('Summit not found in this dashboard');
    multi_exit_set($db, $multi_id, $sid, ($_POST['exit'] ?? '') === 'new');
    echo json_encode(['ok' => true]);
} elseif ($action === 'finish') {
    $aid = (int)($_POST['address_id'] ?? 0);
    if ($aid) {
        $st = $db->prepare("SELECT id FROM addresses WHERE id = ? AND planning_group_id = ?");
        $st->execute([$aid, $group['id']]);
        if (!$st->fetch()) leg_fail('Address not found in this dashboard');
    }
    $db->prepare("UPDATE multi_activations SET finish_address_id = ? WHERE id = ?")->execute([$aid ?: null, $multi_id]);
    logActivity($db, 'Multi finish address', "route $multi_id", $aid ? "address $aid" : 'start address', $group['name']);
    echo json_encode(['ok' => true]);
} else {
    leg_fail('Bad request');
}
