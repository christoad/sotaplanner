<?php
/**
 * Background endpoint: calculate the travel time from the dashboard's selected
 * starting address to one summit and cache it (summit_drive_times).
 * Called via fetch() from the dashboard after the address is switched.
 *
 * GET params: summit_id
 * Returns JSON: { ok, minutes }
 */
require_once 'config.php';
session_start();
requireLogin();
$db = getDbConnection();
$current_group = getCurrentPlanningGroup($db);
$selected_address = $current_group ? getSelectedAddress($db) : null;
session_write_close();

header('Content-Type: application/json');

$summit_id = (int)($_GET['summit_id'] ?? 0);
if (!$summit_id || !$current_group || !$selected_address) {
    echo json_encode(['ok' => false, 'error' => 'missing params']);
    exit;
}

$stmt = $db->prepare("SELECT id, latitude, longitude, trailhead_lat, trailhead_lng FROM summits
                      WHERE id = ? AND planning_group_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL");
$stmt->execute([$summit_id, $current_group['id']]);
$summit = $stmt->fetch();
if (!$summit) {
    echo json_encode(['ok' => false, 'error' => 'not found']);
    exit;
}

$rt = calcSummitDriveRT($db, $summit, $selected_address);
saveDriveTimeCache($db, $summit, $selected_address, $rt);
$db->prepare("UPDATE summits SET drive_time_min = ? WHERE id = ?")->execute([$rt, $summit['id']]);

echo json_encode(['ok' => true, 'minutes' => $rt]);
