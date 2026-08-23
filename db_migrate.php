<?php
if (($_GET['pw'] ?? '') !== 'sota') die('Unauthorized');

require_once 'config.php';
$db = getDbConnection();

echo "<pre style='font-family:monospace; background:#111; color:#0f0; padding:1rem;'>";
echo "=== db_migrate.php: switch Community Growth to trail-data-completeness metric ===\n\n";

// Drop the old 'ready' log — replaced by trail_data_growth_log
$db->exec("DROP TABLE IF EXISTS global_ready_summits");
echo "✓ Dropped old global_ready_summits table\n";

$tables = $db->query("SHOW TABLES LIKE 'trail_data_growth_log'")->fetchAll();
if (empty($tables)) {
    $db->exec("
        CREATE TABLE trail_data_growth_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sota_ref VARCHAR(20) NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            points INT DEFAULT NULL,
            latitude DECIMAL(10,6) DEFAULT NULL,
            longitude DECIMAL(10,6) DEFAULT NULL,
            source VARCHAR(20) NOT NULL,
            first_seen_date DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_sota_ref (sota_ref)
        )
    ");
    echo "✓ Created table trail_data_growth_log\n";
} else {
    echo "— Table trail_data_growth_log already exists, skipped\n";
}

// Match the collation summits.sota_ref actually uses — the DB's default collation
// has changed over time, so a freshly created table can mismatch older tables.
$db->exec("ALTER TABLE trail_data_growth_log MODIFY sota_ref VARCHAR(20) COLLATE utf8mb4_general_ci NOT NULL");
echo "✓ Aligned sota_ref collation with summits table\n";

$gpx_cols = array_column($db->query("SHOW COLUMNS FROM global_gpx_tracks")->fetchAll(), 'Field');
$date_expr = in_array('imported_at', $gpx_cols) ? "MIN(g.imported_at)" : "NOW()";

// Bucket 1: global GPX library tracks with a trailhead — the vast majority of the
// 13,588 figure, and the only bucket with a real historical timestamp (imported_at)
$n1 = $db->exec("
    INSERT IGNORE INTO trail_data_growth_log (sota_ref, name, points, latitude, longitude, source, first_seen_date)
    SELECT g.sota_ref, MAX(s.name), MAX(s.points), MAX(g.summit_lat), MAX(g.summit_lon), 'gpx_track', $date_expr
    FROM global_gpx_tracks g
    LEFT JOIN summits s ON s.sota_ref = g.sota_ref
    WHERE g.trailhead_lat IS NOT NULL AND g.trailhead_lon IS NOT NULL
    GROUP BY g.sota_ref
");
echo "✓ Bucket 1 (GPX library w/ trailhead): $n1 summit(s)\n";

// Bucket 2: drive-up summits not already covered by bucket 1 — dated by nominated_date
// (approximate; drive-up status doesn't carry its own timestamp)
$n2 = $db->exec("
    INSERT IGNORE INTO trail_data_growth_log (sota_ref, name, points, latitude, longitude, source, first_seen_date)
    SELECT sota_ref, MAX(name), MAX(points), MAX(latitude), MAX(longitude), 'drive_up', COALESCE(MIN(nominated_date), NOW())
    FROM summits
    WHERE difficulty = 'drive-up' AND sota_ref IS NOT NULL AND sota_ref != ''
      AND CONVERT(sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci NOT IN
          (SELECT CONVERT(sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci FROM trail_data_growth_log)
    GROUP BY sota_ref
");
echo "✓ Bucket 2 (drive-up): $n2 summit(s)\n";

// Bucket 3: manually-researched summits (trailhead + some hike data) not in buckets 1/2
$n3 = $db->exec("
    INSERT IGNORE INTO trail_data_growth_log (sota_ref, name, points, latitude, longitude, source, first_seen_date)
    SELECT sota_ref, MAX(name), MAX(points), MAX(latitude), MAX(longitude), 'manual_research', COALESCE(MIN(nominated_date), NOW())
    FROM summits
    WHERE sota_ref IS NOT NULL AND sota_ref != ''
      AND trailhead_lat IS NOT NULL AND trailhead_lng IS NOT NULL
      AND (hike_distance_mi IS NOT NULL OR hike_time_up_min IS NOT NULL OR hike_elevation_gain_ft IS NOT NULL)
      AND difficulty != 'drive-up'
      AND CONVERT(sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci NOT IN
          (SELECT CONVERT(sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci FROM trail_data_growth_log)
    GROUP BY sota_ref
");
echo "✓ Bucket 3 (manual research): $n3 summit(s)\n";

$total = (int)$db->query("SELECT COUNT(*) FROM trail_data_growth_log")->fetchColumn();
echo "\nTotal in trail_data_growth_log: $total (should match the login page's community trail data count)\n";

echo "\nAll done. Delete this file from the server.\n</pre>";
