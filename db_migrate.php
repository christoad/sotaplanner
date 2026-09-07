<?php
if (($_GET['pw'] ?? '') !== 'sota') die('Unauthorized');

require_once 'config.php';
$db = getDbConnection();

echo "<pre style='font-family:monospace; background:#111; color:#0f0; padding:1rem;'>";
echo "=== db_migrate.php: add last_sotlas_check to activation_zone_cache ===\n\n";

$cols = array_column($db->query("SHOW COLUMNS FROM activation_zone_cache")->fetchAll(), 'Field');
if (!in_array('last_sotlas_check', $cols, true)) {
    $db->exec("ALTER TABLE activation_zone_cache ADD COLUMN last_sotlas_check TIMESTAMP NULL DEFAULT NULL AFTER fetched_at");
    echo "✓ Added column last_sotlas_check\n";
} else {
    echo "— Column last_sotlas_check already exists, skipped\n";
}

// Backfill existing rows so the upgrade cron doesn't treat every pre-existing
// row as never-checked (they were all checked at fetch time already).
$n = $db->exec("UPDATE activation_zone_cache SET last_sotlas_check = fetched_at WHERE last_sotlas_check IS NULL");
echo "✓ Backfilled last_sotlas_check on $n existing row(s)\n";

echo "\nAll done. Delete this file from the server.\n</pre>";
