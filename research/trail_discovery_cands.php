<?php
// CLI: php trail_discovery_cands.php <site dir> <run> ...  One row per candidate route: confidence, class, time, chosen, and distance to the community start.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = rtrim($argv[1], '/'); chdir($site);
require_once $site . '/config.php';
require_once $site . '/trail_discovery_lib.php';
echo "run\tref\ti\tcls\ttime\tconf\tchosen\tlot\tdriveup\td_m\n";
foreach (array_slice($argv, 2) as $run) {
    foreach (glob(td_store_dir() . '/runs/' . (int)$run . '/*.json.gz') as $f) {
        $d = json_decode(gzdecode(file_get_contents($f)), true);
        if (!$d || empty($d['known']['start'])) continue;
        $ks = $d['known']['start'];
        foreach ($d['candidates'] as $i => $c) {
            $dd = td_dist($ks[0], $ks[1], $c['lat'], $c['lon']);
            if (!empty($c['closed'])) $dd = min($dd, td_dist($ks[0], $ks[1], $c['closed']['lat'], $c['closed']['lon']));
            echo implode("\t", [$run, $d['summit']['ref'], $i, $c['cls'], $c['time_min'], $c['confidence'], in_array($i, $d['chosen'], true) ? 1 : 0,
                                empty($c['lot']) ? 0 : 1, empty($c['driveup']) ? 0 : 1, (int)$dd]) . "\n";
        }
    }
}
