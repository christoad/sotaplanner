<?php
/**
 * db_migrate.php — Allow duplicate group names across different users
 *
 * Changes the UNIQUE constraint on planning_groups.name from a global unique
 * to a composite unique on (name, owner_callsign), so two different users
 * can have groups with the same name.
 *
 * Password: sota
 * Delete this file from the server after running.
 */

if (($_POST['pw'] ?? '') !== 'sota') {
?><!DOCTYPE html>
<html><head><title>DB Migrate</title></head>
<body style="font-family:sans-serif;max-width:500px;margin:3rem auto;padding:1rem">
<h2>DB Migration — Group name uniqueness per owner</h2>
<p>Drops the global unique index on <code>planning_groups.name</code> and replaces it with a composite unique on <code>(name, owner_callsign)</code>.</p>
<form method="post">
  <label>Password: <input type="password" name="pw" autofocus></label>
  <button type="submit" style="margin-left:.5rem">Run Migration</button>
</form>
</body></html>
<?php
    exit;
}

require_once 'config.php';
$db = getDbConnection();

echo "<!DOCTYPE html><html><head><title>DB Migrate</title></head>";
echo "<body style='font-family:sans-serif;max-width:700px;margin:3rem auto;padding:1rem'>";
echo "<h2>DB Migration — Group name uniqueness per owner</h2>";

$ok = 0; $err = 0;

// Step 1: Drop the existing global unique key on name, if present
try {
    $idx = $db->query("SHOW INDEX FROM planning_groups WHERE Column_name = 'name' AND Non_unique = 0");
    $rows = $idx->fetchAll();
    if ($rows) {
        $key_name = $rows[0]['Key_name'];
        if ($key_name !== 'PRIMARY') {
            $db->exec("ALTER TABLE planning_groups DROP INDEX `$key_name`");
            echo "<p style='color:green'>✓ Dropped old unique index '$key_name' on name</p>";
            $ok++;
        }
    } else {
        echo "<p style='color:#888'>— No global unique index on name found (may already be updated)</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Drop index: " . htmlspecialchars($e->getMessage()) . "</p>";
    $err++;
}

// Step 2: Add composite unique key on (name, owner_callsign)
try {
    $existing = $db->query("SHOW INDEX FROM planning_groups WHERE Key_name = 'name_owner'")->fetchAll();
    if (!$existing) {
        $db->exec("ALTER TABLE planning_groups ADD UNIQUE KEY name_owner (name, owner_callsign)");
        echo "<p style='color:green'>✓ Added unique key name_owner (name, owner_callsign)</p>";
        $ok++;
    } else {
        echo "<p style='color:#888'>— Unique key name_owner already exists, skipping</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Add composite index: " . htmlspecialchars($e->getMessage()) . "</p>";
    $err++;
}

echo "<hr><p><strong>Done.</strong> OK: $ok &nbsp; Errors: $err</p>";
echo "<p style='color:#888;font-size:.85em'>Delete this file from the server after running.</p>";
echo "</body></html>";
