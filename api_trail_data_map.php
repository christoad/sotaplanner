<?php
require_once 'config.php';
session_start();

$db = getDbConnection();
reconcileTrailDataGrowthLog($db);
header('Content-Type: application/json');

$stmt = $db->query("
    SELECT sota_ref, name, points, latitude, longitude
    FROM trail_data_growth_log
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
");

$out = [];
while ($row = $stmt->fetch()) {
    $m = [
        'r' => $row['sota_ref'],
        'a' => (float)$row['latitude'],
        'o' => (float)$row['longitude'],
    ];
    if ($row['name'])   $m['n'] = $row['name'];
    if ($row['points']) $m['p'] = (int)$row['points'];
    $out[] = $m;
}

echo json_encode($out);
