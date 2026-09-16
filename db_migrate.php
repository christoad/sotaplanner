<?php
// One-off migration: add multi_activations.is_expanded so the dashboard can
// remember each route's expand/collapse state instead of always defaulting
// to open. Delete this file after running.
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
        echo "[OK] $label" . ($result ? " — $result" : '') . "\n";
    } catch (Exception $e) {
        echo "[ERROR] $label — " . $e->getMessage() . "\n";
    }
}

step('Check multi_activations.is_expanded column', function () use ($db) {
    $cols = $db->query("SHOW COLUMNS FROM multi_activations LIKE 'is_expanded'")->fetchAll();
    if ($cols) return 'already exists, skipped';
    $db->exec("ALTER TABLE multi_activations ADD COLUMN is_expanded TINYINT(1) NOT NULL DEFAULT 1 AFTER activation_time_min");
    return 'column added';
});

echo "\nDone. Delete this file from the server now.\n";
