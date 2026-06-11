<?php
/**
 * batch_gpx_cron.php — CLI cron worker for the global GPX library.
 *
 * Two modes (set via --mode):
 *   new    (default) — import tracks for summits in the SOTA cache that have
 *                      never been checked against SOTAmaps at all
 *   retry            — re-check summits that previously had no tracks, in case
 *                      community routes have since been uploaded
 *
 * Usage:
 *   php batch_gpx_cron.php [--mode=new|retry] [--limit=300] [--delay=1500] [--retry-days=30]
 *
 * Suggested DreamHost cron jobs (set up via panel.dreamhost.com → Cron Jobs):
 *   Daily   3:05 AM   php /home/chrisr069/sotaplannerdotcom/batch_gpx_cron.php --mode=new --limit=500
 *   Weekly  3:30 AM   php /home/chrisr069/sotaplannerdotcom/batch_gpx_cron.php --mode=retry --limit=500
 *   (redirect both to: >> /home/chrisr069/logs/gpx_cron.log 2>&1)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script may only be run from the command line.');
}

$opts       = getopt('', ['mode:', 'limit:', 'delay:', 'retry-days:']);
$mode       = $opts['mode']         ?? 'new';
$limit      = max(1, (int)($opts['limit']      ?? 300));
$delay_ms   = max(500, (int)($opts['delay']    ?? 1500));
$retry_days = max(1, (int)($opts['retry-days'] ?? 30));

if (!in_array($mode, ['new', 'retry'], true)) {
    fwrite(STDERR, "Unknown mode '{$mode}'. Use --mode=new or --mode=retry\n");
    exit(1);
}

set_time_limit(0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sota_cache_helper.php';
require_once __DIR__ . '/gpx_import_lib.php';

$db = getDbConnection();

_clog("GPX cron starting — mode={$mode} limit={$limit} delay={$delay_ms}ms retry-days={$retry_days}");

// ── Build the work queue ──────────────────────────────────────────────────────

$queue = [];

if ($mode === 'new') {
    _clog("Loading SOTA summit cache…");
    $cache_refs  = _cron_load_cache_refs();
    $total_cache = count($cache_refs);
    _clog("Cache contains {$total_cache} summits");

    // Load all refs we've already dealt with from either table
    $done = [];
    foreach ($db->query("SELECT sota_ref FROM global_gpx_checked")->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $done[$r] = true;
    }
    foreach ($db->query("SELECT sota_ref FROM global_gpx_tracks")->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $done[$r] = true;
    }

    foreach ($cache_refs as $ref) {
        if (!isset($done[$ref])) $queue[] = $ref;
    }

    $total_new = count($queue);
    _clog("{$total_new} summits have never been checked on SOTAmaps");

    if ($total_new > $limit) {
        $queue = array_slice($queue, 0, $limit);
        _clog("Capped to {$limit} for this run — " . ($total_new - $limit) . " remaining for future runs");
    }

} else { // retry
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$retry_days} days"));
    $rows   = $db->prepare("
        SELECT sota_ref FROM global_gpx_checked
        WHERE tracks_found = 0 AND last_checked < ?
        ORDER BY last_checked ASC
        LIMIT ?
    ");
    $rows->execute([$cutoff, $limit]);
    $queue = $rows->fetchAll(PDO::FETCH_COLUMN);
    _clog(count($queue) . " stale no-track summits to retry (last checked more than {$retry_days} days ago)");
}

if (empty($queue)) {
    _clog("Nothing to process. All done.");
    _clog(str_repeat('-', 60));
    exit(0);
}

// ── Process queue ─────────────────────────────────────────────────────────────

$counts = ['imported' => 0, 'none' => 0, 'skip' => 0, 'err' => 0];
$total  = count($queue);

foreach ($queue as $i => $sota_ref) {
    $n      = $i + 1;
    $result = import_sotamaps_track($db, $sota_ref);

    switch ($result['status']) {
        case 'imported':
            $counts['imported']++;
            $extra = ($result['backfilled'] ?? 0) > 0 ? " [backfilled {$result['backfilled']}]" : '';
            _clog("[{$n}/{$total}] ✓ {$sota_ref} — {$result['dist_mi']} mi, {$result['gain_ft']} ft gain{$extra}");
            break;
        case 'none':
            $counts['none']++;
            _clog("[{$n}/{$total}] · {$sota_ref} — no tracks on SOTAmaps");
            break;
        case 'skip':
            $counts['skip']++;
            _clog("[{$n}/{$total}] ⏭ {$sota_ref} — already in library");
            break;
        default:
            $counts['err']++;
            _clog("[{$n}/{$total}] ✗ {$sota_ref} — {$result['msg']}");
    }

    if ($i < $total - 1) {
        usleep($delay_ms * 1000);
    }
}

_clog("Done — imported:{$counts['imported']}  none:{$counts['none']}  skip:{$counts['skip']}  err:{$counts['err']}");

if ($mode === 'retry') {
    $summary = json_encode(['ts' => time(), 'processed' => $total, 'found' => $counts['imported']]);
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('last_retry_run', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([$summary]);
}

_clog(str_repeat('-', 60));
exit(0);

// ── Helpers ───────────────────────────────────────────────────────────────────

function _clog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    flush();
}

function _cron_load_cache_refs(): array {
    if (!file_exists(SOTA_CACHE_FILE)) return [];
    $gz   = @gzopen(SOTA_CACHE_FILE, 'rb');
    if (!$gz) return [];
    $refs = [];
    while (!gzeof($gz)) {
        $line = gzgets($gz, 512);
        if (!$line) continue;
        $parts = explode('|', rtrim($line, "\r\n"), 2);
        if (isset($parts[0]) && strpos($parts[0], '/') !== false) {
            $refs[] = $parts[0];
        }
    }
    gzclose($gz);
    return $refs;
}
