<?php
/**
 * aws_stats_lib.php: once-a-day health reading for the BRouter AWS server.
 *
 * K3MGM exposes AWS's own metrics at a private URL, stored as AWS_STATS_URL in
 * sotaplanner_secrets.php (not in git). The data only changes every 5 minutes
 * and he asked us not to call it more often than that, so every call goes
 * through aws_stats_capture(), which refuses to run within 5 minutes of the
 * previous attempt. Pages only ever read our own table; they never call the URL.
 */

const AWS_STATS_MIN_SECONDS = 300;
const AWS_STATS_STATUS_KEY  = 'aws_stats_status';

function aws_stats_status(PDO $db): array {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $stmt->execute([AWS_STATS_STATUS_KEY]);
        $status = json_decode((string)$stmt->fetchColumn(), true);
        return is_array($status) ? $status : [];
    } catch (Exception $e) {
        return [];
    }
}

function aws_stats_save_status(PDO $db, array $status): void {
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
       ->execute([AWS_STATS_STATUS_KEY, json_encode($status)]);
}

function aws_stats_pacific(string $time = 'now'): ?DateTime {
    try {
        $when = new DateTime($time);
        $when->setTimezone(new DateTimeZone('America/Los_Angeles'));
        return $when;
    } catch (Exception $e) {
        return null;
    }
}

function aws_stats_number(array $values, string $key): ?float {
    return (isset($values[$key]) && is_numeric($values[$key])) ? (float)$values[$key] : null;
}

/**
 * Fetches the current reading and stores it as a new row (time in Pacific).
 * Returns ['ok' => bool, 'message' => string], plus 'skipped' => true if held back by the limit.
 */
function aws_stats_capture(PDO $db): array {
    if (!defined('AWS_STATS_URL') || AWS_STATS_URL === '') {
        return ['ok' => false, 'message' => 'AWS_STATS_URL is not set in the secrets file'];
    }

    $status = aws_stats_status($db);
    $since_last = time() - (int)($status['attempted_at'] ?? 0);
    if ($since_last < AWS_STATS_MIN_SECONDS) {
        return ['ok' => false, 'skipped' => true, 'message' => "last check was only {$since_last} seconds ago"];
    }

    // Recorded before the call so a failed attempt also counts toward the limit.
    $status['attempted_at'] = time();
    aws_stats_save_status($db, $status);

    $ch = curl_init(AWS_STATS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'SOTAplanner server stats',
    ]);
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $data = is_string($body) ? json_decode($body, true) : null;
    if ($code !== 200 || !is_array($data) || !is_array($data['latest'] ?? null)) {
        $reason = $error !== '' ? $error : "HTTP {$code}, no usable data returned";
        $status['ok'] = false;
        $status['error'] = substr($reason, 0, 200);
        aws_stats_save_status($db, $status);
        return ['ok' => false, 'message' => $status['error']];
    }

    $latest = $data['latest'];
    $volume = $data['volumes'][0] ?? [];
    $disk   = is_array($volume['latest'] ?? null) ? $volume['latest'] : [];

    $checks = array_filter([
        aws_stats_number($latest, 'status_check_failed'),
        aws_stats_number($latest, 'status_check_failed_instance'),
        aws_stats_number($latest, 'status_check_failed_system'),
    ], function ($value) { return $value !== null; });
    $failed = $checks ? (max($checks) > 0 ? 1 : 0) : null;

    $metrics_at = !empty($latest['cpu_pct_at']) ? aws_stats_pacific((string)$latest['cpu_pct_at']) : null;
    $now = aws_stats_pacific();

    $db->prepare("INSERT IGNORE INTO aws_server_readings
        (fetched_at, metrics_at, status_failed, cpu_pct, cpu_credit_balance,
         net_in_bps, net_out_bps, disk_read_bps, disk_write_bps, disk_size_gb, raw_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([
           $now->format('Y-m-d H:i:s'),
           $metrics_at ? $metrics_at->format('Y-m-d H:i:s') : null,
           $failed,
           aws_stats_number($latest, 'cpu_pct'),
           aws_stats_number($latest, 'cpu_credit_balance'),
           aws_stats_number($latest, 'net_in_bps'),
           aws_stats_number($latest, 'net_out_bps'),
           aws_stats_number($disk, 'read_bps'),
           aws_stats_number($disk, 'write_bps'),
           isset($volume['size_gb']) && is_numeric($volume['size_gb']) ? (int)$volume['size_gb'] : null,
           $body,
       ]);

    $status['ok'] = true;
    unset($status['error']);
    aws_stats_save_status($db, $status);

    return ['ok' => true, 'message' => 'reading saved at ' . $now->format('Y-m-d H:i')];
}

/** Readings from the last $days days, newest first. Empty if the table is missing or has no rows. */
function aws_stats_history(PDO $db, int $days = 30): array {
    try {
        $cutoff = aws_stats_pacific("-{$days} days");
        $stmt = $db->prepare("SELECT fetched_at, metrics_at, status_failed, cpu_pct,
                                     cpu_credit_balance, net_in_bps, net_out_bps,
                                     disk_read_bps, disk_write_bps, disk_size_gb
                              FROM aws_server_readings WHERE fetched_at >= ?
                              ORDER BY fetched_at DESC");
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function aws_stats_rate($value): string {
    if ($value === null) return '-';
    $value = (float)$value;
    if ($value >= 1000000) return number_format($value / 1000000, 1) . ' Mbps';
    if ($value >= 1000)    return number_format($value / 1000, 1) . ' Kbps';
    return number_format($value, 1) . ' bps';
}
