<?php
// One-off migration for discovered routes on the summit page (branch discovered-routes, 2026-11-24).
// Additive and safe to run twice: three new tables plus the launch switch (created OFF).
// Run once in the browser with ?password=sota, then delete this file from the server.
require_once 'config.php';

if (($_GET['password'] ?? '') !== 'sota') {
    http_response_code(403);
    die('Forbidden');
}

$db = getDbConnection();
header('Content-Type: text/plain');

function step($label, callable $fn) {
    try {
        echo "[OK] $label: " . $fn() . "\n";
    } catch (Throwable $e) {
        echo "[ERROR] $label: " . $e->getMessage() . "\n";
    }
}

// One row per summit that has been published from a trail discovery run.
step('Create discovered_summits', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS discovered_summits (
        sota_ref VARCHAR(20) NOT NULL PRIMARY KEY,
        run_id INT NOT NULL,
        summit_conf TINYINT NULL,
        band VARCHAR(8) NULL,
        n_routes SMALLINT NOT NULL DEFAULT 0,
        status VARCHAR(8) NOT NULL DEFAULT 'found',
        searched_at DATETIME NULL,
        settings_json TEXT NULL,
        file VARCHAR(255) NULL,
        published_by VARCHAR(20) NULL,
        published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_run (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready (' . (int)$db->query("SELECT COUNT(*) FROM discovered_summits")->fetchColumn() . ' rows)';
});

// One row per route (every candidate, not only the top 3). route_no is the route's index in the published file.
step('Create discovered_routes', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS discovered_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sota_ref VARCHAR(20) NOT NULL,
        route_no SMALLINT NOT NULL,
        run_id INT NOT NULL,
        start_lat DECIMAL(9,6) NOT NULL,
        start_lon DECIMAL(9,6) NOT NULL,
        start_kind VARCHAR(10) NOT NULL,
        start_name VARCHAR(200) NULL,
        dist_m INT NOT NULL,
        gain_m INT NOT NULL,
        time_min INT NOT NULL,
        confidence TINYINT NULL,
        rank_shown TINYINT NULL,
        drive_up TINYINT(1) NOT NULL DEFAULT 0,
        has_closed_fallback TINYINT(1) NOT NULL DEFAULT 0,
        file VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ref_route (sota_ref, route_no),
        KEY idx_run (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready (' . (int)$db->query("SELECT COUNT(*) FROM discovered_routes")->fetchColumn() . ' rows)';
});

// Votes and uses: the ground truth for refitting the confidence score. run_id + route_no identify the route
// (route numbers change when a summit is republished from a newer run); the start point is kept too.
step('Create discovered_route_votes', function () use ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS discovered_route_votes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sota_ref VARCHAR(20) NOT NULL,
        run_id INT NOT NULL,
        route_no SMALLINT NOT NULL,
        start_lat DECIMAL(9,6) NULL,
        start_lon DECIMAL(9,6) NULL,
        callsign VARCHAR(20) NOT NULL,
        planning_group_id INT NULL,
        verdict VARCHAR(12) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ref_run (sota_ref, run_id),
        KEY idx_callsign (callsign)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return 'ready (' . (int)$db->query("SELECT COUNT(*) FROM discovered_route_votes")->fetchColumn() . ' rows)';
});

// Launch switch: stays OFF until Four Days in May 2027. INSERT IGNORE never changes an existing value.
step('Launch switch discovered_routes_public', function () use ($db) {
    $db->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('discovered_routes_public', '0')");
    $v = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'discovered_routes_public'")->fetchColumn();
    return 'value is ' . ($v === '1' ? '1 (ON, check this!)' : '0 (off)');
});

echo "\nDone. Delete db_migrate_discovered.php from the server now.\n";
