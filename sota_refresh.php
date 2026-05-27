<?php
/**
 * Background endpoint: refresh SOTA activation data for one summit.
 * Called via fetch() from the dashboard on page load — silent, non-blocking.
 *
 * GET params: summit_id, group_id
 * Returns JSON: { ok, updated, last_activated_date, activated_by }
 */
require_once 'config.php';
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

// Check 24-hour cache
$cache_key  = 'sota_activations_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $summit['sota_ref']);
$cache_stmt = $db->prepare("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = ?");
$cache_stmt->execute([$cache_key]);
$cache_row  = $cache_stmt->fetch();

$all_activations = null;
if ($cache_row && (time() - strtotime($cache_row['updated_at'])) < 86400) {
    $all_activations = json_decode($cache_row['setting_value'], true);
} else {
    $ref_parts = explode('/', $summit['sota_ref'], 2);
    if (count($ref_parts) === 2) {
        $api_url = 'https://api2.sota.org.uk/api/activations/'
                 . urlencode($ref_parts[0]) . '/' . urlencode($ref_parts[1]);
        $ctx = stream_context_create(['http' => [
            'timeout'       => 8,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: SOTAplanner/1.0\r\n",
        ]]);
        $raw = @file_get_contents($api_url, false, $ctx);
        if ($raw !== false) {
            $fetched = json_decode($raw, true);
            if (is_array($fetched)) {
                $all_activations = $fetched;
                $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at)
                              VALUES (?, ?, NOW())
                              ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()")
                   ->execute([$cache_key, json_encode($all_activations)]);
            }
        }
    }
}

if (!is_array($all_activations)) {
    echo json_encode(['ok' => false, 'error' => 'api_unavailable']);
    exit;
}

// Get group member callsigns
$member_stmt = $db->prepare("
    SELECT callsign FROM planning_group_members WHERE planning_group_id = ?
    UNION
    SELECT owner_callsign FROM planning_groups WHERE id = ?
");
$member_stmt->execute([$group_id, $group_id]);
$group_callsigns = array_map('strtoupper', array_column($member_stmt->fetchAll(), 'callsign'));

// Filter to group members and find most recent
$most_recent_date     = null;
$most_recent_callsign = null;
foreach ($all_activations as $act) {
    $act_cs   = strtoupper(trim($act['ownCallsign'] ?? ''));
    $act_date = $act['activationDate'] ?? '';
    if (!in_array($act_cs, $group_callsigns) || !$act_date) continue;
    $act_date_fmt = date('Y-m-d', strtotime($act_date));
    if ($most_recent_date === null || $act_date_fmt > $most_recent_date) {
        $most_recent_date     = $act_date_fmt;
        $most_recent_callsign = $act_cs;
    }
}

// Update the summits table if we have data
$updated = false;
if ($most_recent_date) {
    $db->prepare("UPDATE summits SET last_activated_date = ?, activated_by = ?, status = 'activated'
                  WHERE id = ? AND (last_activated_date IS NULL OR last_activated_date < ?)")
       ->execute([$most_recent_date, $most_recent_callsign, $summit_id, $most_recent_date]);
    $updated = $db->rowCount() > 0;
}

echo json_encode([
    'ok'                  => true,
    'updated'             => $updated,
    'last_activated_date' => $most_recent_date,
    'activated_by'        => $most_recent_callsign,
]);
