<?php
// One-off migration: per-address travel time cache.
//   summit_drive_times  one row per (summit, starting address) holding the round-trip drive
//                       minutes plus the origin/destination coordinates it was calculated for,
//                       so switching the "From" address can reuse earlier results.
// Additive and safe to run twice. Delete this file after running.
require_once 'config.php';

if (($_GET['password'] ?? '') !== 'sota') {
    http_response_code(403);
    die('Forbidden');
}

$db = getDbConnection();

header('Content-Type: text/plain');

function step($label, $fn) {
    try {
        $result = $fn();
        echo "[OK] $label" . ($result ? ": $result" : '') . "\n";
    } catch (Exception $e) {
        echo "[ERROR] $label: " . $e->getMessage() . "\n";
    }
}

step('Create summit_drive_times', function () use ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS summit_drive_times (
            summit_id INT NOT NULL,
            address_id INT NOT NULL,
            drive_time_min INT NULL,
            origin_lat DOUBLE NULL,
            origin_lng DOUBLE NULL,
            dest_lat DOUBLE NOT NULL,
            dest_lng DOUBLE NOT NULL,
            calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (summit_id, address_id),
            KEY idx_address (address_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'table ready';
});

step('Seed from current travel times', function () use ($db) {
    // Each group's summits currently hold times for that group's selected address.
    $rows = $db->query("
        SELECT s.id AS summit_id, s.drive_time_min,
               COALESCE(NULLIF(s.trailhead_lat, 0), s.latitude) AS dlat,
               COALESCE(NULLIF(s.trailhead_lng, 0), s.longitude) AS dlng,
               a.id AS address_id, a.lat AS alat, a.lng AS alng
        FROM summits s
        JOIN app_settings st ON st.setting_key = CONCAT('selected_address_group_', s.planning_group_id)
        JOIN addresses a ON a.id = st.setting_value AND a.planning_group_id = s.planning_group_id
        WHERE s.drive_time_min > 0 AND s.latitude IS NOT NULL AND a.lat IS NOT NULL
    ")->fetchAll();
    $ins = $db->prepare("INSERT IGNORE INTO summit_drive_times
        (summit_id, address_id, drive_time_min, origin_lat, origin_lng, dest_lat, dest_lng)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $ins->execute([$r['summit_id'], $r['address_id'], $r['drive_time_min'], $r['alat'], $r['alng'], $r['dlat'], $r['dlng']]);
    }
    return count($rows) . ' rows';
});

echo "\nDone. Delete db_migrate.php from the server now.\n";
