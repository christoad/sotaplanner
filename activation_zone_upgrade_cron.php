<?php
/**
 * activation_zone_upgrade_cron.php — CLI cron worker that quietly re-checks
 * SOTLAS for summits currently stuck on the lower-resolution Activation.Zone
 * fallback polygon. SOTLAS is adding high-precision (~1m) boundaries to more
 * associations over time, so a summit with no SOTLAS boundary today may have
 * one next month. When found, the cache row is upgraded in place.
 *
 * Usage:
 *   php activation_zone_upgrade_cron.php [--limit=200] [--delay=1000] [--retry-days=30]
 *
 * Suggested DreamHost cron job (set up via panel.dreamhost.com → Cron Jobs):
 *   Weekly  4:00 AM   php /home/chrisr069/sotaplannerdotcom/activation_zone_upgrade_cron.php --limit=500
 *   (redirect to: >> /home/chrisr069/sota_logs/gpx_cron.log 2>&1)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script may only be run from the command line.');
}

$opts       = getopt('', ['limit:', 'delay:', 'retry-days:']);
$limit      = max(1, (int)($opts['limit']      ?? 200));
$delay_ms   = max(500, (int)($opts['delay']    ?? 1000));
$retry_days = max(1, (int)($opts['retry-days'] ?? 30));

set_time_limit(0);

require_once __DIR__ . '/config.php';

$db = getDbConnection();

_azlog("Activation zone upgrade cron starting — limit={$limit} delay={$delay_ms}ms retry-days={$retry_days}");

$cutoff = date('Y-m-d H:i:s', strtotime("-{$retry_days} days"));
$rows = $db->prepare("
    SELECT sota_ref FROM activation_zone_cache
    WHERE source = 'activation_zone'
      AND (last_sotlas_check IS NULL OR last_sotlas_check < ?)
    ORDER BY last_sotlas_check ASC
    LIMIT ?
");
$rows->bindValue(1, $cutoff);
$rows->bindValue(2, $limit, PDO::PARAM_INT);
$rows->execute();
$queue = $rows->fetchAll(PDO::FETCH_COLUMN);

if (empty($queue)) {
    _azlog("Nothing to re-check. All done.");
    _azlog(str_repeat('-', 60));
    exit(0);
}

$total = count($queue);
_azlog("{$total} summit(s) still on the Activation.Zone fallback, last checked more than {$retry_days} days ago (or never)");

$counts = ['upgraded' => 0, 'still_none' => 0];

foreach ($queue as $i => $sota_ref) {
    $n = $i + 1;
    $result = get_sotlas_az_polygon($sota_ref);

    if ($result && isset($result['polygon'])) {
        $stmt = $db->prepare("
            UPDATE activation_zone_cache
            SET polygon = ?, source = 'sotlas', fetched_at = NOW(), last_sotlas_check = NOW()
            WHERE sota_ref = ?
        ");
        $stmt->execute([json_encode($result['polygon']), $sota_ref]);
        $counts['upgraded']++;
        _azlog("[{$n}/{$total}] ↑ {$sota_ref} — upgraded to SOTLAS high-precision boundary");
    } else {
        $stmt = $db->prepare("UPDATE activation_zone_cache SET last_sotlas_check = NOW() WHERE sota_ref = ?");
        $stmt->execute([$sota_ref]);
        $counts['still_none']++;
        _azlog("[{$n}/{$total}] · {$sota_ref} — no SOTLAS boundary yet, still on fallback");
    }

    if ($i < $total - 1) {
        usleep($delay_ms * 1000);
    }
}

_azlog("Done — upgraded:{$counts['upgraded']}  still_none:{$counts['still_none']}");
_azlog(str_repeat('-', 60));
exit(0);

function _azlog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    flush();
}
