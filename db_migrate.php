<?php
if (($_GET['pw'] ?? '') !== 'sota') die('Unauthorized');

require_once 'config.php';
$db = getDbConnection();

echo "<pre style='font-family:monospace; background:#111; color:#0f0; padding:1rem;'>";
echo "=== db_migrate.php: callsign confirmation columns ===\n\n";

$cols = array_column($db->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');

if (!in_array('callsign_confirmed', $cols)) {
    $db->exec("ALTER TABLE users ADD COLUMN callsign_confirmed tinyint(1) NOT NULL DEFAULT 0");
    echo "✓ Added users.callsign_confirmed\n";
} else {
    echo "— users.callsign_confirmed already exists, skipped\n";
}

if (!in_array('additional_callsigns', $cols)) {
    $db->exec("ALTER TABLE users ADD COLUMN additional_callsigns varchar(500) DEFAULT NULL");
    echo "✓ Added users.additional_callsigns\n";
} else {
    echo "— users.additional_callsigns already exists, skipped\n";
}

if (!in_array('sso_sub', $cols)) {
    $db->exec("ALTER TABLE users ADD COLUMN sso_sub varchar(64) DEFAULT NULL");
    echo "✓ Added users.sso_sub\n";
} else {
    echo "— users.sso_sub already exists, skipped\n";
}

echo "\nAll done. Delete this file from the server.\n</pre>";
