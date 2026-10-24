<?php
/**
 * Background endpoint: current SOTAwatch alerts for one summit, refetching from SOTAwatch if
 * the shared copy is over a minute old. Called via fetch() from summit_detail.php after page load.
 *
 * GET params: ref (SOTA reference, e.g. W6/CT-001)
 * Returns JSON: { ok, alerts: [{ts, callsign, name, frequency, comments}] }
 */
require_once 'config.php';
require_once 'sota_alerts_lib.php';
session_start();
requireLogin();
session_write_close();

header('Content-Type: application/json');

$ref = trim($_GET['ref'] ?? '');
if (!preg_match('~^[A-Za-z0-9]+/[A-Za-z0-9]+-\d+$~', $ref)) {
    echo json_encode(['ok' => false, 'error' => 'bad ref']);
    exit;
}

$alerts = sota_alerts_for_ref(getDbConnection(), $ref, true);
echo json_encode(['ok' => true, 'alerts' => array_map(fn($a) => [
    'ts'        => strtotime($a['dateActivated']),
    'callsign'  => $a['activatingCallsign'] ?? '',
    'name'      => $a['activatorName'] ?? '',
    'frequency' => $a['frequency'] ?? '',
    'comments'  => $a['comments'] ?? '',
], $alerts)]);
