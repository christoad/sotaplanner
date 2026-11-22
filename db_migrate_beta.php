<?php
// One-off migration for the beta tester list (God Mode, Beta Testers tab).
// Additive and safe to run twice. Delete this file from the server after running.
require_once 'config.php';

if (($_GET['password'] ?? '') !== 'sota') {
    http_response_code(403);
    die('Forbidden');
}

$db = getDbConnection();
header('Content-Type: text/plain');

try {
    $db->exec("CREATE TABLE IF NOT EXISTS beta_testers (
        callsign VARCHAR(20) NOT NULL PRIMARY KEY,
        email VARCHAR(255) NULL,
        note VARCHAR(500) NULL,
        added_by VARCHAR(20) NOT NULL,
        added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $n = (int)$db->query("SELECT COUNT(*) FROM beta_testers")->fetchColumn();
    echo "[OK] Create beta_testers: ready ($n rows)\n";
} catch (Exception $e) {
    echo "[ERROR] Create beta_testers: " . $e->getMessage() . "\n";
}

echo "\nDone. Delete db_migrate_beta.php from the server now.\n";
