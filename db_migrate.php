<?php
// One-off migration: add planned_activations.created_by (callsign of whoever set up the invitation;
// NULL for older rows, which fall back to the dashboard owner).
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

step('Add planned_activations.created_by', function () use ($db) {
    $has = $db->query("SHOW COLUMNS FROM planned_activations LIKE 'created_by'")->fetch();
    if ($has) return 'already exists, skipped';
    $db->exec("ALTER TABLE planned_activations ADD COLUMN created_by VARCHAR(20) NULL DEFAULT NULL");
    return 'column added';
});

echo "\nDone. Delete this file from the server now.\n";
