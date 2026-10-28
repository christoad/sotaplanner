<?php
// One-off migration: add the shared_summits table (public share links for a summit page).
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

echo "\nDone. Delete this file from the server now.\n";
