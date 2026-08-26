<?php
/**
 * Background endpoint: refresh SOTA activation data for one summit.
 * Called via fetch() from the dashboard on page load — silent, non-blocking.
 *
 * GET params: summit_id, group_id
 * Returns JSON: { ok, updated, last_activated_date, activated_by }
 */
require_once 'config.php';
session_start();
requireLogin();

header('Content-Type: application/json');

$summit_id = (int)($_GET['summit_id'] ?? 0);
$group_id  = (int)($_GET['group_id']  ?? 0);

if (!$summit_id || !$group_id) {
    echo json_encode(['ok' => false, 'error' => 'missing params']);
    exit;
}

$db = getDbConnection();

// Verify this summit belongs to this group (or is shared from it)
$stmt = $db->prepare("SELECT id, sota_ref FROM summits WHERE id = ? AND planning_group_id = ?");
$stmt->execute([$summit_id, $group_id]);
$summit = $stmt->fetch();
if (!$summit || empty($summit['sota_ref'])) {
    echo json_encode(['ok' => false, 'error' => 'not found']);
    exit;
}

// Verify caller is a member of this group
$cs = strtoupper(getCurrentCallsign());
$mem = $db->prepare("SELECT 1 FROM planning_group_members WHERE planning_group_id = ? AND callsign = ?
                     UNION SELECT 1 FROM planning_groups WHERE id = ? AND owner_callsign = ?");
$mem->execute([$group_id, $cs, $group_id, $cs]);
if (!$mem->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// 24-hour cache handled by the shared helper (also used by sync_dashboard_activations.php)
$all_activations = fetchSotaActivations($db, $summit['sota_ref']);

if (!is_array($all_activations)) {
    echo json_encode(['ok' => false, 'error' => 'api_unavailable']);
    exit;
}

// Get group member callsigns (including their additional callsigns)
$group_callsigns = getGroupHomeCallsigns($db, $group_id);

// Filter to group members and find most recent
$most_recent_date     = null;
$most_recent_callsign = null;
foreach ($all_activations as $act) {
    $act_cs   = strtoupper(trim($act['ownCallsign'] ?? ''));
    $act_date = $act['activationDate'] ?? '';
    if (sotaCallsignMatchesHome($act_cs, $group_callsigns) === null || !$act_date) continue;
    $act_date_fmt = date('Y-m-d', strtotime($act_date));
    if ($most_recent_date === null || $act_date_fmt > $most_recent_date) {
        $most_recent_date     = $act_date_fmt;
        $most_recent_callsign = $act_cs;
    }
}

// Update the summits table if we have data
$updated = false;
if ($most_recent_date) {
    $update_stmt = $db->prepare("UPDATE summits SET last_activated_date = ?, activated_by = ?, status = 'activated'
                  WHERE id = ? AND (last_activated_date IS NULL OR last_activated_date < ?)");
    $update_stmt->execute([$most_recent_date, $most_recent_callsign, $summit_id, $most_recent_date]);
    $updated = $update_stmt->rowCount() > 0;
}

echo json_encode([
    'ok'                  => true,
    'updated'             => $updated,
    'last_activated_date' => $most_recent_date,
    'activated_by'        => $most_recent_callsign,
]);
