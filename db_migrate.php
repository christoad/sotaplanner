<?php
/**
 * db_migrate.php — Add global_gpx_checked table
 *
 * Tracks every summit that has been queried against SOTAmaps, including ones
 * where no tracks were found. Used by batch_gpx_cron.php to avoid re-querying
 * summits unnecessarily and to schedule periodic retries for "no tracks" summits.
 *
 * Password: sota
 * Delete this file from the server after running.
 */

if (($_POST['pw'] ?? '') !== 'sota') {
?><!DOCTYPE html>
<html><head><title>DB Migrate</title></head>
<body style="font-family:sans-serif;max-width:500px;margin:3rem auto;padding:1rem">
<h2>DB Migration — global_gpx_checked</h2>
<p>Creates <code>global_gpx_checked</code> and backfills it from existing <code>global_gpx_tracks</code> rows.</p>
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
echo "<h2>DB Migration — global_gpx_checked</h2>";

// ── Step 1: Create global_gpx_checked ────────────────────────────────────────
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS global_gpx_checked (
            sota_ref      VARCHAR(20)  NOT NULL,
            last_checked  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tracks_found  SMALLINT     NOT NULL DEFAULT 0,
            PRIMARY KEY (sota_ref),
            INDEX idx_retry (tracks_found, last_checked)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p style='color:green'>✓ global_gpx_checked table created (or already exists)</p>";
    $ok++;
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Create table: " . htmlspecialchars($e->getMessage()) . "</p>";
    $err++;
}

// ── Step 2: Backfill from global_gpx_tracks ───────────────────────────────────
// Every row in global_gpx_tracks was already checked and found to have tracks.
// Inserting them into global_gpx_checked prevents the cron from re-querying them.
try {
    $inserted = $db->exec("
        INSERT IGNORE INTO global_gpx_checked (sota_ref, last_checked, tracks_found)
        SELECT sota_ref,
               COALESCE(imported_at, NOW()),
               COALESCE(sotamaps_track_count, 1)
        FROM global_gpx_tracks
    ");
    echo "<p style='color:green'>✓ Backfilled {$inserted} rows from global_gpx_tracks</p>";
    $ok++;
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Backfill: " . htmlspecialchars($e->getMessage()) . "</p>";
    $err++;
}

// ── Step 3: Show counts ───────────────────────────────────────────────────────
try {
    $total   = $db->query("SELECT COUNT(*) FROM global_gpx_checked")->fetchColumn();
    $with    = $db->query("SELECT COUNT(*) FROM global_gpx_checked WHERE tracks_found > 0")->fetchColumn();
    $without = $db->query("SELECT COUNT(*) FROM global_gpx_checked WHERE tracks_found = 0")->fetchColumn();
    echo "<p style='color:#555'>global_gpx_checked now has <strong>{$total}</strong> rows: {$with} with tracks, {$without} with no tracks</p>";
} catch (Exception $e) { /* non-fatal */ }

echo "<hr><p><strong>Done.</strong> OK: $ok &nbsp; Skipped: $skip &nbsp; Errors: $err</p>";
echo "<p style='color:#888;font-size:.85em'>Delete this file from the server after running.</p>";
echo "</body></html>";
