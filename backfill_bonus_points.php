<?php
// One-time (but safe to re-run) backfill: sets summits.bonus_points for every already-
// nominated summit by looking it up in sota_cache.csv.gz (which must be rebuilt from the
// official SOTA CSV first — see rebuild_sota_cache.php).
// Run via CLI: php backfill_bonus_points.php
// Run from browser: backfill_bonus_points.php?password=sota
// Delete this file from the server after running.

ini_set('max_execution_time', 300);

require_once 'config.php';
require_once 'sota_cache_helper.php';

$is_cli = (php_sapi_name() === 'cli');
$password = $is_cli ? 'sota' : ($_GET['password'] ?? '');
if ($password !== 'sota') {
    if (!$is_cli) http_response_code(403);
    echo "Unauthorized. Add ?password=sota to the URL.\n";
    exit;
}

if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    while (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);
}

if (!file_exists(SOTA_CACHE_FILE)) {
    echo "ERROR: sota_cache.csv.gz not found. Run rebuild_sota_cache.php first.\n";
    exit(1);
}

$db = getDbConnection();
$rows = $db->query("SELECT id, sota_ref, bonus_points FROM summits")->fetchAll();
echo "Checking " . count($rows) . " summits against the cache...\n";
flush();

$upd = $db->prepare("UPDATE summits SET bonus_points = ? WHERE id = ?");
$updated = 0;
$unchanged = 0;
$not_found = 0;

foreach ($rows as $row) {
    $cached = get_sota_cache_summit($row['sota_ref']);
    if ($cached === null) { $not_found++; continue; }

    if ((int)$cached['bonus'] !== (int)$row['bonus_points']) {
        $upd->execute([$cached['bonus'], $row['id']]);
        $updated++;
    } else {
        $unchanged++;
    }
}

echo "Done! Updated {$updated} summits, {$unchanged} already correct, {$not_found} not found in cache.\n";
echo "\nAll done. Delete this file from the server.\n";
