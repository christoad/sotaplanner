<?php
if (($_GET['pw'] ?? '') !== 'sota') die('Unauthorized');

require_once 'config.php';
$db = getDbConnection();

echo "<pre style='font-family:monospace; background:#111; color:#0f0; padding:1rem;'>";
echo "=== db_migrate.php: allow a summit to appear more than once in a multi-activation ===\n\n";

$idx = $db->query("SHOW INDEX FROM multi_activation_summits WHERE Key_name = 'uniq_multi_summit'")->fetchAll();
if (!empty($idx)) {
    $db->exec("ALTER TABLE multi_activation_summits DROP INDEX uniq_multi_summit");
    echo "✓ Dropped old uniq_multi_summit index (summit_id could only appear once per route)\n";
} else {
    echo "— uniq_multi_summit already gone, skipped\n";
}

$idx2 = $db->query("SHOW INDEX FROM multi_activation_summits WHERE Key_name = 'uniq_multi_order'")->fetchAll();
if (empty($idx2)) {
    $db->exec("ALTER TABLE multi_activation_summits ADD UNIQUE KEY uniq_multi_order (multi_activation_id, sort_order)");
    echo "✓ Added uniq_multi_order index (multi_activation_id, sort_order) — position is now what's unique, not the summit\n";
} else {
    echo "— uniq_multi_order already exists, skipped\n";
}

echo "\nAll done. Delete this file from the server.\n</pre>";
