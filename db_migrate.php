<?php
/**
 * db_migrate.php — Global GPX library migration
 *
 * Creates global_gpx_tracks table and adds from_global_library column to gpx_tracks.
 * Password: sota
 * Delete from the server after running.
 */

if (($_POST['pw'] ?? '') !== 'sota') {
?><!DOCTYPE html>
<html><head><title>DB Migrate</title></head>
<body style="font-family:sans-serif;max-width:500px;margin:3rem auto;padding:1rem">
<h2>DB Migration — Global GPX Library</h2>
<p>Creates the <code>global_gpx_tracks</code> table and adds <code>from_global_library</code> to <code>gpx_tracks</code>.</p>
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
$ok = 0; $skip = 0; $err = 0;

echo "<!DOCTYPE html><html><head><title>DB Migrate</title></head>";
echo "<body style='font-family:sans-serif;max-width:700px;margin:3rem auto;padding:1rem'>";
echo "<h2>DB Migration — Global GPX Library</h2>";

// ── Step 1: Create global_gpx_tracks table ────────────────────────────────────
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS global_gpx_tracks (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            sota_ref           VARCHAR(20) NOT NULL,
            filename           VARCHAR(255) NOT NULL,
            file_path          VARCHAR(500) NOT NULL,
            source             VARCHAR(50) NOT NULL DEFAULT 'sotamaps',
            source_callsign    VARCHAR(20) DEFAULT NULL,
            source_track_title VARCHAR(255) DEFAULT NULL,
            total_distance     DECIMAL(10,4) DEFAULT NULL,
            max_elevation      DECIMAL(10,2) DEFAULT NULL,
            min_elevation      DECIMAL(10,2) DEFAULT NULL,
            elevation_gain     DECIMAL(10,2) DEFAULT NULL,
            elevation_loss     DECIMAL(10,2) DEFAULT NULL,
            num_points         INT DEFAULT NULL,
            summit_lat         DECIMAL(10,7) DEFAULT NULL,
            summit_lon         DECIMAL(10,7) DEFAULT NULL,
            trailhead_lat      DECIMAL(10,7) DEFAULT NULL,
            trailhead_lon      DECIMAL(10,7) DEFAULT NULL,
            imported_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY sota_ref (sota_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p style='color:green'>✓ global_gpx_tracks table created (or already exists)</p>";
    $ok++;
} catch (Exception $e) {
    echo "<p style='color:red'>✗ global_gpx_tracks: " . htmlspecialchars($e->getMessage()) . "</p>";
    $err++;
}

// ── Step 2: Add from_global_library to gpx_tracks ─────────────────────────────
$cols = $db->query("SHOW COLUMNS FROM gpx_tracks LIKE 'from_global_library'")->fetchAll();
if (count($cols) === 0) {
    try {
        $db->exec("ALTER TABLE gpx_tracks ADD COLUMN from_global_library TINYINT(1) NOT NULL DEFAULT 0");
        echo "<p style='color:green'>✓ Added from_global_library column to gpx_tracks</p>";
        $ok++;
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ from_global_library column: " . htmlspecialchars($e->getMessage()) . "</p>";
        $err++;
    }
} else {
    echo "<p style='color:orange'>⚠ from_global_library column already exists in gpx_tracks (skipped)</p>";
    $skip++;
}

echo "<hr><p><strong>Done.</strong> OK: $ok &nbsp; Skipped: $skip &nbsp; Errors: $err</p>";
echo "<p style='color:#888;font-size:.85em'>Delete this file from the server after running.</p>";
echo "</body></html>";
