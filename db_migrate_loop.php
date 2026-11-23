<?php
// One-time migration for Multi-Activate "Finish the loop": remembers how a walking chain ends
// ('back' = track back, 'loop' = finish the recorded loop). Safe to run twice. Delete after running.
// Ran once on 2026-11-21 (shared database), then deleted from the server.
require_once 'config.php';
if (($_GET['password'] ?? '') !== 'sota') { http_response_code(403); exit('Password required'); }
header('Content-Type: text/plain');
$db = getDbConnection();
try {
    $exists = $db->query("SHOW TABLES LIKE 'multi_stop_end'")->fetch();
    $db->exec("CREATE TABLE IF NOT EXISTS multi_stop_end (
        multi_activation_id INT NOT NULL,
        summit_id INT NOT NULL,
        mode VARCHAR(8) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (multi_activation_id, summit_id)
    )");
    echo $exists ? "SKIP multi_stop_end already exists\n" : "OK created multi_stop_end\n";
} catch (Exception $e) {
    echo "ERROR " . $e->getMessage() . "\n";
}
echo "Done. Delete this file from the server.\n";
