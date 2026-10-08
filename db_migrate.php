<?php
// One-off migration: tables for Multi-Activate "alternate hike start" pins.
//   multi_alt_starts         shared (community) pin for an ordered summit pair
//   multi_alt_start_optouts  a user's personal "ignore the shared pin" choice
//   multi_alt_start_log      history of every set/reset, so a bad pin can be undone
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

step('Create multi_alt_starts', function () use ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS multi_alt_starts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_ref VARCHAR(32) NOT NULL,
            to_ref VARCHAR(32) NOT NULL,
            lat DECIMAL(10,7) NOT NULL,
            lng DECIMAL(10,7) NOT NULL,
            set_by VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pair (from_ref, to_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'table ready';
});

step('Create multi_alt_start_optouts', function () use ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS multi_alt_start_optouts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            callsign VARCHAR(20) NOT NULL,
            from_ref VARCHAR(32) NOT NULL,
            to_ref VARCHAR(32) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_pair (callsign, from_ref, to_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'table ready';
});

step('Create multi_alt_start_log', function () use ($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS multi_alt_start_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_ref VARCHAR(32) NOT NULL,
            to_ref VARCHAR(32) NOT NULL,
            action VARCHAR(16) NOT NULL,
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            by_callsign VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pair (from_ref, to_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'table ready';
});

echo "\nDone. Delete db_migrate.php from the server now.\n";
