<?php
/**
 * SOTAwatch alerts for one summit, from a shared copy of the full alerts list in app_settings.
 * $allow_fetch = false reads the stored copy only (never touches the network), so page loads stay fast.
 * $allow_fetch = true refetches when the copy is over 60 seconds old.
 */
function sota_alerts_for_ref(PDO $db, string $sota_ref, bool $allow_fetch): array {
    $key = 'sota_all_alerts';
    $ac = $db->prepare("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = ?");
    $ac->execute([$key]);
    $cache = $ac->fetch();
    $all = $cache ? json_decode($cache['setting_value'], true) : null;

    $age = $cache ? time() - strtotime($cache['updated_at']) : PHP_INT_MAX;
    if ($allow_fetch && $age >= 60) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: SOTAplanner/1.0\r\n"]]);
        $raw = @file_get_contents('https://api2.sota.org.uk/api/alerts', false, $ctx);
        $fetched = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($fetched)) {
            $all = $fetched;
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at)
                          VALUES (?, ?, NOW())
                          ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()")
               ->execute([$key, json_encode($all)]);
        } elseif ($cache) {
            // SOTAwatch slow or down: keep the stale copy and retry in ~15 minutes
            $db->prepare("UPDATE app_settings SET updated_at = NOW() - INTERVAL 2700 SECOND WHERE setting_key = ?")
               ->execute([$key]);
        }
    }

    $out = [];
    if (!is_array($all)) return $out;
    $parts = explode('/', $sota_ref, 2);
    $assoc = $parts[0] ?? '';
    $code  = $parts[1] ?? '';
    $now = time();
    foreach ($all as $alert) {
        if (strcasecmp($alert['associationCode'] ?? '', $assoc) === 0
            && strcasecmp($alert['summitCode'] ?? '', $code) === 0
            && strtotime($alert['dateActivated'] ?? '') > $now) {
            $out[] = $alert;
        }
    }
    usort($out, fn($a, $b) => strcmp($a['dateActivated'], $b['dateActivated']));
    return $out;
}
