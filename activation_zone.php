<?php
/**
 * activation_zone.php
 * PHP proxy for activation.zone API — avoids browser CORS restrictions.
 * Returns GeoJSON polygon coordinates for a given SOTA summit reference.
 *
 * Usage: GET ?sota_ref=W6/CT-170
 */

require_once 'config.php';
session_start();
if (!isset($_SESSION['sota_callsign'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

$sota_ref = trim($_GET['sota_ref'] ?? '');
if (!$sota_ref) {
    echo json_encode(['error' => 'Missing sota_ref']);
    exit;
}

$db = getDbConnection();
$current_group = getCurrentPlanningGroup($db);

// Look up summit coordinates — the Activation.Zone fallback API requires lat/lon/alt
$stmt = $db->prepare("SELECT latitude, longitude, elevation_m FROM summits WHERE sota_ref = ? LIMIT 1");
$stmt->execute([$sota_ref]);
$summit = $stmt->fetch();

if (!$summit || !$summit['latitude']) {
    echo json_encode(['error' => 'Summit coordinates not found for ' . htmlspecialchars($sota_ref)]);
    exit;
}

// Tries SOTLAS's high-precision boundary first, then Activation.Zone; result is
// permanently cached in activation_zone_cache (see get_activation_zone_from_api()).
$result = get_activation_zone_from_api(
    $sota_ref,
    $summit['latitude'],
    $summit['longitude'],
    $summit['elevation_m']
);

if ($result && isset($result['polygon'])) {
    echo json_encode(['polygon' => $result['polygon'], 'source' => $result['source']]);
    exit;
}

// Last resort — a polygon stored from a previous GPX import for this summit
if ($current_group) {
    $stmt = $db->prepare("
        SELECT gt.activation_zone_polygon
        FROM gpx_tracks gt
        JOIN summits s ON s.id = gt.summit_id
        WHERE s.sota_ref = ?
          AND gt.planning_group_id = ?
          AND gt.using_api = 1
          AND gt.activation_zone_polygon IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$sota_ref, $current_group['id']]);
    $row = $stmt->fetch();
    if ($row) {
        echo json_encode(['polygon' => json_decode($row['activation_zone_polygon']), 'source' => 'cache']);
        exit;
    }
}

echo json_encode(['error' => 'No activation zone boundary found for ' . htmlspecialchars($sota_ref)]);
