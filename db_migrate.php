<?php
// One-off migration: add summits.activation_count (total times SOTA says the summit has been
// activated; NULL = not fetched yet, filled in by the dashboard's background refresh).
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

step('Add summits.activation_count', function () use ($db) {
    $has = $db->query("SHOW COLUMNS FROM summits LIKE 'activation_count'")->fetch();
    if ($has) return 'already exists, skipped';
    $db->exec("ALTER TABLE summits ADD COLUMN activation_count INT NULL DEFAULT NULL");
    return 'column added';
});

echo "\nDone. Delete this file from the server now.\n";
