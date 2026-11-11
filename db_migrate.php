<?php
// One-off migration for the BRouter path tools and Multi-Activate leg settings (additive, safe to run twice).
//   drive_leg_cache          Google Routes drive legs (time, distance, road line), refreshed after 30 days
//   brouter_route_cache      permanent cache of every routed pair of points
//   brouter_path_candidates  hiking paths discovered for a summit, kept for manual review
//   multi_leg_modes          per route: car or hike between neighbouring summits
//   multi_stop_times         per route: on-the-air minutes for one summit (overrides the route default)
//   multi_stop_exit          per route: summits where the walk down goes on to a new trailhead
//   multi_activations.finish_address_id  where a route's last drive ends (NULL = starting address)
//   multi_activations.breaks             rest stops placed between summits (NULL = none)
// Also: shared_summits table (public share links for a summit page).
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

step('Create aws_server_readings', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS aws_server_readings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fetched_at DATETIME NOT NULL,
        metrics_at DATETIME NULL,
        status_failed TINYINT(1) NULL,
        cpu_pct DOUBLE NULL,
        cpu_credit_balance DOUBLE NULL,
        net_in_bps DOUBLE NULL,
        net_out_bps DOUBLE NULL,
        disk_read_bps DOUBLE NULL,
        disk_write_bps DOUBLE NULL,
        disk_size_gb INT NULL,
        raw_json TEXT NOT NULL,
        UNIQUE KEY uq_fetched (fetched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

// aws_server_stats (one row per day) was replaced the same day by aws_server_readings.
// Its rows are copied across; the old table is left in place, unused.
step('Copy readings from aws_server_stats', function () use ($db) {
    if (!$db->query("SHOW TABLES LIKE 'aws_server_stats'")->fetch()) return 'no old table (skipped)';
    $n = $db->exec("INSERT IGNORE INTO aws_server_readings
        (fetched_at, metrics_at, status_failed, cpu_pct, cpu_credit_balance, net_in_bps, net_out_bps,
         disk_read_bps, disk_write_bps, disk_size_gb, raw_json)
        SELECT fetched_at, metrics_at, status_failed, cpu_pct, cpu_credit_balance, net_in_bps, net_out_bps,
               disk_read_bps, disk_write_bps, disk_size_gb, raw_json FROM aws_server_stats");
    return "$n row(s) copied";
});

step('Create trailhead_snap_queue', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS trailhead_snap_queue (
        summit_id INT NOT NULL PRIMARY KEY,
        sota_ref VARCHAR(20) NOT NULL,
        origin_lat DECIMAL(10,7) NOT NULL,
        origin_lon DECIMAL(10,7) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        last_attempt DATETIME NULL,
        note VARCHAR(160) NULL,
        created_at DATETIME NOT NULL,
        KEY idx_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

step('Create drive_leg_cache', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS drive_leg_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_key VARCHAR(24) NOT NULL,
        to_key VARCHAR(24) NOT NULL,
        duration_min INT NOT NULL DEFAULT 0,
        distance_m INT NOT NULL DEFAULT 0,
        polyline MEDIUMTEXT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'routes',
        fetched_at DATETIME NOT NULL,
        UNIQUE KEY uq_leg (from_key, to_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

step('Create brouter_route_cache', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS brouter_route_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_key VARCHAR(24) NOT NULL,
        to_key VARCHAR(24) NOT NULL,
        profile VARCHAR(40) NOT NULL,
        distance_m DOUBLE NOT NULL DEFAULT 0,
        ascend_m DOUBLE NOT NULL DEFAULT 0,
        descend_m DOUBLE NOT NULL DEFAULT 0,
        coords_json MEDIUMTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_route (from_key, to_key, profile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

step('Create brouter_path_candidates', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS brouter_path_candidates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sota_ref VARCHAR(20) NOT NULL,
        exit_key VARCHAR(24) NOT NULL,
        exit_type VARCHAR(12) NOT NULL DEFAULT 'road',
        exit_name VARCHAR(160) NULL,
        exit_lat DOUBLE NOT NULL,
        exit_lon DOUBLE NOT NULL,
        entry_lat DOUBLE NOT NULL,
        entry_lon DOUBLE NOT NULL,
        entry_in_zone TINYINT(1) NOT NULL DEFAULT 1,
        graph_dist_m DOUBLE NOT NULL DEFAULT 0,
        osm_coords_json MEDIUMTEXT NULL,
        distance_m DOUBLE NULL,
        ascend_m DOUBLE NULL,
        descend_m DOUBLE NULL,
        coords_json MEDIUMTEXT NULL,
        refine_note VARCHAR(200) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'new',
        reviewed_by VARCHAR(20) NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cand (sota_ref, exit_key),
        KEY idx_ref (sota_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

step('Create multi_leg_modes', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS multi_leg_modes (
        multi_activation_id INT NOT NULL,
        from_summit_id INT NOT NULL,
        to_summit_id INT NOT NULL,
        mode VARCHAR(8) NOT NULL,
        PRIMARY KEY (multi_activation_id, from_summit_id, to_summit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

step('Add multi_activations.finish_address_id', function () use ($db) {
    $has = $db->query("SHOW COLUMNS FROM multi_activations LIKE 'finish_address_id'")->fetch();
    if ($has) return 'already there (skipped)';
    $db->exec("ALTER TABLE multi_activations ADD COLUMN finish_address_id INT NULL DEFAULT NULL");
    return 'added';
});

step('Create multi_stop_times', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS multi_stop_times (
        multi_activation_id INT NOT NULL,
        summit_id INT NOT NULL,
        activation_min SMALLINT NOT NULL,
        PRIMARY KEY (multi_activation_id, summit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

step('Create multi_stop_exit', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS multi_stop_exit (
        multi_activation_id INT NOT NULL,
        summit_id INT NOT NULL,
        PRIMARY KEY (multi_activation_id, summit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready';
});

step('Create shared_summits table', function () use ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS shared_summits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL,
            summit_id INT NOT NULL,
            sota_ref VARCHAR(30) NOT NULL,
            created_by VARCHAR(20) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_slug (slug),
            UNIQUE KEY uq_summit (summit_id),
            KEY idx_ref (sota_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'table ready';
});

// Multi-Activate breaks (multi_break_lib.php): "pos:min,pos:min", NULL = no breaks
step('Add multi_activations.breaks', function () use ($db) {
    $has = $db->query("SHOW COLUMNS FROM multi_activations LIKE 'breaks'")->fetch();
    if ($has) return 'already there (skipped)';
    $db->exec("ALTER TABLE multi_activations ADD COLUMN breaks VARCHAR(255) NULL DEFAULT NULL");
    return 'added';
});

echo "\nDone. Delete this file from the server now.\n";
