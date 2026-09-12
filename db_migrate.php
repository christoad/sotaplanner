<?php
if (($_GET['pw'] ?? '') !== 'sota') die('Unauthorized');

require_once 'config.php';
$db = getDbConnection();

echo "<pre style='font-family:monospace; background:#111; color:#0f0; padding:1rem;'>";
echo "=== db_migrate.php: add winter bonus points column to summits ===\n\n";

$col = $db->query("SHOW COLUMNS FROM summits LIKE 'bonus_points'")->fetchAll();
if (empty($col)) {
    $db->exec("ALTER TABLE summits ADD COLUMN bonus_points SMALLINT NOT NULL DEFAULT 0 AFTER points");
    echo "✓ Added summits.bonus_points (SMALLINT, default 0)\n";
} else {
    echo "— summits.bonus_points already exists, skipped\n";
}

echo "\nAll done. Delete this file from the server.\n</pre>";
