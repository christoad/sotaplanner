<?php
/**
 * aws_stats_cron.php: saves one reading of the BRouter AWS server's stats.
 * CLI only. Runs four times a day, 4 AM, 10 AM, 4 PM and 10 PM Pacific (the server's clock is Pacific):
 *   0 4,10,16,22 * * * /usr/local/php83/bin/php /home/chrisr069/sotaplannerdotcom/aws_stats_cron.php >> /home/chrisr069/sota_logs/gpx_cron.log 2>&1
 * Shown in God Mode, AWS Server tab. Do not schedule this more often than every
 * 5 minutes; aws_stats_capture() enforces that limit too.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script may only be run from the command line.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/aws_stats_lib.php';

$result = aws_stats_capture(getDbConnection());

if ($result['ok']) {
    $mark = '✓';
} elseif (!empty($result['skipped'])) {
    $mark = '⏭';
} else {
    $mark = '✗ Error:';
}
echo '[' . date('Y-m-d H:i:s') . "] AWS server stats {$mark} {$result['message']}\n";
exit($result['ok'] ? 0 : 1);
