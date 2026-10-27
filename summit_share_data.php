<?php
// Public data feeds for the shared summit page (summit_share.php), keyed by share slug.
// No login required, but every request must name a real share link, so this cannot be
// used as an open proxy. what = gpx | weather | resources | alerts | activations | zone
require_once 'config.php';
require_once 'share_lib.php';
require_once 'weather_lib.php';
require_once 'sota_resources_lib.php';
require_once 'sota_alerts_lib.php';
session_start();
session_write_close();

$db = getDbConnection();
$s = sharePublicSummit($db, (string)($_GET['s'] ?? ''));
if (!$s) {
    http_response_code(404);
    exit;
}
$what = $_GET['what'] ?? '';

if ($what === 'gpx') {
    $path = false;
    if ($s['id']) {
        $stmt = $db->prepare("SELECT file_path FROM gpx_tracks WHERE summit_id = ? ORDER BY use_for_hike_time DESC, id DESC LIMIT 1");
        $stmt->execute([$s['id']]);
        $path = $stmt->fetchColumn();
    }
    if (!$path && empty($s['gpx_opted_out']) && !empty($s['sota_ref'])) {
        $stmt = $db->prepare("SELECT file_path FROM global_gpx_tracks WHERE sota_ref = ?");
        $stmt->execute([$s['sota_ref']]);
        $path = $stmt->fetchColumn();
    }
    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/gpx+xml');
    if (!empty($_GET['download'])) {
        $fname = preg_replace('/[^a-zA-Z0-9]+/', '-', $s['name'] ?? 'summit') . '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', $s['sota_ref'] ?? '') . '.gpx';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
    }
    readfile($path);
    exit;
}

header('Content-Type: application/json');

if ($what === 'weather') {
    $units = 'imperial';
    $w = weather_fetch((float)$s['latitude'], (float)$s['longitude'], (int)round(($s['elevation_ft'] ?? 0) * 0.3048));
    echo json_encode(weather_compact($w, $units) ?: ['error' => true]);
    exit;
}

if ($what === 'resources') {
    if (!preg_match('~^[A-Za-z0-9]+/[A-Za-z0-9]+-\d+$~', $s['sota_ref'] ?? '')) {
        echo json_encode(['ok' => false]);
        exit;
    }
    echo json_encode(['ok' => true, 'items' => sota_resources_for_ref($db, $s['sota_ref'])]);
    exit;
}

if ($what === 'activations') {
    $all = fetchSotaActivations($db, $s['sota_ref']);
    if (!is_array($all)) { echo json_encode(['ok' => false]); exit; }
    usort($all, fn($a, $b) => strcmp($b['activationDate'] ?? '', $a['activationDate'] ?? ''));
    $last = $all ? ['date' => gmdate('M j, Y', strtotime($all[0]['activationDate'] ?? 'now')), 'callsign' => strtoupper(trim($all[0]['ownCallsign'] ?? ''))] : null;
    echo json_encode(['ok' => true, 'count' => count($all), 'last' => $last]);
    exit;
}

if ($what === 'alerts') {
    if (!preg_match('~^[A-Za-z0-9]+/[A-Za-z0-9]+-\d+$~', $s['sota_ref'] ?? '')) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $alerts = sota_alerts_for_ref($db, $s['sota_ref'], true);
    echo json_encode(['ok' => true, 'alerts' => array_map(fn($a) => [
        'ts'        => strtotime($a['dateActivated']),
        'callsign'  => $a['activatingCallsign'] ?? '',
        'name'      => $a['activatorName'] ?? '',
        'frequency' => $a['frequency'] ?? '',
        'comments'  => $a['comments'] ?? '',
    ], $alerts)]);
    exit;
}

if ($what === 'zone') {
    $result = get_activation_zone_from_api($s['sota_ref'], $s['latitude'], $s['longitude'], $s['elevation_m']);
    if ($result && isset($result['polygon'])) {
        echo json_encode(['polygon' => $result['polygon'], 'source' => $result['source']]);
    } else {
        echo json_encode(['error' => 'none']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'bad request']);
