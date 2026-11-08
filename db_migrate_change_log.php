<?php
// One-off migration: per-summit change log table (additive, safe to run twice).
// Delete this file from the server after running.
require_once 'config.php';

if (($_GET['password'] ?? '') !== 'sota') {
    http_response_code(403);
    die('Forbidden');
}

$db = getDbConnection();
header('Content-Type: text/plain');

try {
    $db->exec("CREATE TABLE IF NOT EXISTS summit_change_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        summit_id INT NOT NULL,
        sota_ref VARCHAR(30) NULL,
        planning_group_id INT NULL,
        callsign VARCHAR(20) NOT NULL,
        field_label VARCHAR(80) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_summit (summit_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] summit_change_log ready\n";
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}

echo "\nDone. Delete this file from the server now.\n";
