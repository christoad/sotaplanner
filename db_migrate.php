<?php
// One-off migration for the BRouter path tools: two new tables (additive, safe to run twice).
//   brouter_route_cache      permanent cache of every routed pair of points
//   brouter_path_candidates  hiking paths discovered for a summit, kept for manual review
// Delete this file after running.
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

echo "\nDone. Delete this file from the server now.\n";
