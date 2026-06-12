<?php
require_once 'config.php';
session_start();
requireLogin();

$callsign = $_SESSION['sota_callsign'] ?? '';
$real     = $_SESSION['_god_mode_real_callsign'] ?? '';
if ($callsign !== 'KI6CR' && $real !== 'KI6CR') {
    http_response_code(403);
    exit;
}

$db = getDbConnection();
header('Content-Type: application/json');

$stmt = $db->query("
    SELECT
        g.sota_ref,
        g.summit_lat,
        g.summit_lon,
        ROUND(g.total_distance * 0.621371, 1)  AS dist_mi,
        ROUND(g.max_elevation * 3.28084)        AS elev_ft,
        ROUND(g.elevation_gain * 3.28084)       AS gain_ft,
        g.trailhead_lat,
        g.trailhead_lon,
        MIN(s.id)                AS summit_id,
        MIN(s.planning_group_id) AS group_id,
        MAX(s.name)              AS summit_name
    FROM global_gpx_tracks g
    LEFT JOIN summits s ON s.sota_ref = g.sota_ref
    WHERE g.summit_lat IS NOT NULL AND g.summit_lon IS NOT NULL
    GROUP BY g.sota_ref, g.summit_lat, g.summit_lon,
             g.total_distance, g.max_elevation, g.elevation_gain,
             g.trailhead_lat, g.trailhead_lon
");

$out = [];
while ($row = $stmt->fetch()) {
    $m = [
        'r' => $row['sota_ref'],
        'a' => (float)$row['summit_lat'],
        'o' => (float)$row['summit_lon'],
    ];
    if ($row['dist_mi'] !== null)  $m['d'] = (float)$row['dist_mi'];
    if ($row['elev_ft'] !== null)  $m['e'] = (int)$row['elev_ft'];
    if ($row['gain_ft'] !== null)  $m['g'] = (int)$row['gain_ft'];
    if ($row['summit_name'])       $m['n'] = $row['summit_name'];
    if ($row['summit_id'])         $m['i'] = (int)$row['summit_id'];
    if ($row['group_id'])          $m['p'] = (int)$row['group_id'];
    if ($row['trailhead_lat'])     $m['t'] = 1;
    $out[] = $m;
}

echo json_encode($out);
