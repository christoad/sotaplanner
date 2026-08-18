<?php
/**
 * sync_dashboard_activations.php
 *
 * Background sync fired once per session/day from index.php. Checks every
 * "green" (not-yet-activated-this-year) summit in the current dashboard
 * against the official SOTA activation API, filtered to this dashboard's
 * member callsigns, and flips any it finds newly activated this year.
 * Real API calls are capped per run — the 24h cache (fetchSotaActivations)
 * means the rest catch up on later runs at no extra cost.
 */

require_once 'config.php';
session_start();
if (!isset($_SESSION['sota_callsign'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

$db = getDbConnection();
$current_group = getCurrentPlanningGroup($db);

if (!$current_group) {
    echo json_encode(['updated' => [], 'checked' => 0]);
    exit;
}

$group_callsigns = getGroupHomeCallsigns($db, $current_group['id']);

// Candidates are exactly the summits shown "green" (ready) on the dashboard —
// the only ones whose row would visibly change if a new activation is found.
$stmt = $db->prepare("
    SELECT id, sota_ref, last_activated_date
    FROM summits
    WHERE planning_group_id = ?
      AND sota_ref IS NOT NULL AND sota_ref != ''
      AND (status != 'activated' OR last_activated_date IS NULL OR YEAR(last_activated_date) < YEAR(UTC_TIMESTAMP()))
");
$stmt->execute([$current_group['id']]);
$candidates = $stmt->fetchAll();

$updated = [];
$checked = 0;
$live_fetches = 0;
$max_live_fetches = 25;

foreach ($candidates as $s) {
    $fresh = sotaActivationsCacheIsFresh($db, $s['sota_ref']);
    if (!$fresh && $live_fetches >= $max_live_fetches) {
        continue; // rate-limit hit — this one catches up on the next run
    }
    if (!$fresh) $live_fetches++;

    $acts = fetchSotaActivations($db, $s['sota_ref']);
    $checked++;
    if (!is_array($acts)) continue;

    $member_acts = [];
    foreach ($acts as $act) {
        $cs = strtoupper(trim($act['ownCallsign'] ?? ''));
        if (sotaCallsignMatchesHome($cs, $group_callsigns) !== null) {
            $member_acts[] = ['date' => $act['activationDate'] ?? '', 'callsign' => $cs];
        }
    }
    if (empty($member_acts)) continue;
    usort($member_acts, fn($a, $b) => strcmp($b['date'], $a['date']));
    $most_recent = $member_acts[0];
    $new_date = gmdate('Y-m-d', strtotime($most_recent['date']));

    if (!empty($s['last_activated_date']) && $new_date <= $s['last_activated_date']) continue;

    $db->prepare("UPDATE summits SET last_activated_date = ?, activated_by = ?, status = 'activated'
                  WHERE id = ? AND (last_activated_date IS NULL OR last_activated_date < ?)")
       ->execute([$new_date, $most_recent['callsign'], $s['id'], $new_date]);

    // Only report it back if this flips the row's visible color (this-year activation).
    if (date('Y', strtotime($new_date)) == gmdate('Y')) {
        $updated[] = [
            'id'                     => (int)$s['id'],
            'last_activated_display' => date('M j, Y', strtotime($new_date)),
            'activated_by'           => $most_recent['callsign'],
            'year'                   => date('Y', strtotime($new_date)),
        ];
    }
}

echo json_encode(['updated' => $updated, 'checked' => $checked]);
