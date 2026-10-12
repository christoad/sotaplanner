<?php
// One-off migration: feature voting + daily visit tracking.
//   feature_votes   one row per (feature, callsign) "I'd use this" vote
//   user_visits     one row per (callsign, day) the user was active; powers the God Mode
//                   returning-users dashboard. Seeded from activity_log history.
// Safe to run twice. Delete this file after running.
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

step('Create feature_votes', function () use ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS feature_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            feature_key VARCHAR(64) NOT NULL,
            callsign VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_vote (feature_key, callsign)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'table ready';
});

step('Create user_visits', function () use ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_visits (
            callsign VARCHAR(20) NOT NULL,
            visit_date DATE NOT NULL,
            PRIMARY KEY (callsign, visit_date),
            KEY idx_date (visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'table ready';
});

step('Seed user_visits from activity_log', function () use ($db) {
    $n = $db->exec("
        INSERT IGNORE INTO user_visits (callsign, visit_date)
        SELECT DISTINCT callsign, DATE(event_time)
        FROM activity_log
        WHERE callsign IS NOT NULL AND callsign <> '' AND callsign <> '?'
    ");
    return "$n rows added";
});

echo "\nDone. Delete db_migrate.php from the server now.\n";
