<?php
// CLI only: php trail_discovery_rescore.php <site dir> <run> [<run> ...]
// Re-scores saved trail discovery results with the current td_confidence() without searching again:
// updates each route's confidence and reasons, the summit confidence in the saved file, and
// trail_disc_results.confidence. Community evidence and probe hits are reused as saved.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = rtrim($argv[1] ?? '', '/');
if (!$site || !is_file($site . '/trail_discovery_lib.php')) { fwrite(STDERR, "usage: php trail_discovery_rescore.php <site dir> <run> ...\n"); exit(1); }
chdir($site);
require_once $site . '/config.php';
require_once $site . '/trail_discovery_lib.php';
$db = getDbConnection();
$upd = $db->prepare("UPDATE trail_disc_results SET confidence = ? WHERE run_id = ? AND sota_ref = ?");
foreach (array_slice($argv, 2) as $run) {
    $n = 0;
    foreach (glob(td_store_dir() . '/runs/' . (int)$run . '/*.json.gz') as $f) {
        $d = json_decode(gzdecode(file_get_contents($f)), true);
        if (!$d || empty($d['ok']) || !isset($d['candidates'])) continue;
        $s = $d['summit'];
        td_score_candidates($d['candidates'], $s['lat'], $s['lon'], $d['settings']['max_km'] ?? 8);
        $conf = function ($idx) use ($d) {
            if (!$idx) return null;
            $q = 1.0;
            foreach ($idx as $i) $q *= 1 - $d['candidates'][$i]['confidence'] / 100;
            return (int)round(100 * (1 - $q));
        };
        $d['confidence'] = $conf($d['chosen']);
        $d['confidence_alt'] = $conf($d['chosen_alt'] ?? []);
        $d['band'] = td_conf_band($d['confidence']);
        $d['rescored'] = date('c');
        file_put_contents($f, gzencode(json_encode($d), 6));
        $upd->execute([$d['confidence'], (int)$run, $s['ref']]);
        $n++;
    }
    echo "run $run: rescored $n summits\n";
}
