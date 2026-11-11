<?php
// Background endpoint: tries the pending OpenStreetMap start-point snap for one summit (see osm_trailhead_lib.php).
// Called by summit_detail.php after the page has loaded, so a slow or busy OpenStreetMap never holds up a page.
require_once 'config.php';
require_once 'osm_trailhead_lib.php';
session_start();
requireLogin();
$current_group = null;
$db = getDbConnection();
$current_group = getCurrentPlanningGroup($db);
session_write_close();   // read-only session use; do not hold the lock through the slow lookup

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['result' => 'none']); exit; }

$summit_id = (int)($_POST['summit_id'] ?? 0);
if ($summit_id <= 0) { echo json_encode(['result' => 'none']); exit; }

// The summit must belong to the caller's current dashboard (same check as import_sotamaps_gpx.php)
$chk = $db->prepare("SELECT 1 FROM summits WHERE id = ? AND planning_group_id = ?");
$chk->execute([$summit_id, $current_group['id'] ?? 0]);
if (!$chk->fetchColumn()) { echo json_encode(['result' => 'none']); exit; }

echo json_encode(['result' => trailhead_snap_run($db, $summit_id)]);
