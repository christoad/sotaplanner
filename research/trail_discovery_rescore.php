<?php
// CLI only: php trail_discovery_rescore.php <site dir> <run> [<run> ...]
// Re-scores saved trail discovery results with the current td_confidence() without searching again:
// updates each route's confidence and reasons, re-picks the routes shown when the run picks by confidence,
// recomputes the summit confidence (td_summit_conf) and the comparison with the community track, then
// rewrites the saved file and the trail_disc_results row. Community evidence and probe hits are reused as saved.
// Back up the run folders first (sota_discovered/runs/<run>).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = rtrim($argv[1] ?? '', '/');
if (!$site || !is_file($site . '/trail_discovery_lib.php')) { fwrite(STDERR, "usage: php trail_discovery_rescore.php <site dir> <run> ...\n"); exit(1); }
chdir($site);
require_once $site . '/config.php';
require_once $site . '/trail_discovery_lib.php';
require_once $site . '/parking_lib.php';
require_once $site . '/brouter_lib.php';
$db = getDbConnection();
foreach (array_slice($argv, 2) as $run) {
    $n = 0; $repicked = 0;
    foreach (glob(td_store_dir() . '/runs/' . (int)$run . '/*.json.gz') as $f) {
        $d = json_decode(gzdecode(file_get_contents($f)), true);
        if (!$d || empty($d['ok']) || !isset($d['candidates'])) continue;
        $s = $d['summit'];
        td_score_candidates($d['candidates'], $s['lat'], $s['lon'], $d['settings']['max_km'] ?? 8);
        $pick = $d['settings']['pick'] ?? 'paved_first';
        [$chosen, $alt] = td_pick($d['candidates'], $pick);
        if ($chosen !== $d['chosen']) $repicked++;
        $d['chosen'] = $chosen; $d['chosen_alt'] = $alt;
        $d['confidence'] = td_summit_conf($d['candidates'], $chosen);
        $d['confidence_alt'] = td_summit_conf($d['candidates'], $alt);
        $d['band'] = td_conf_band($d['confidence']);
        $d['rescored'] = date('c');
        $row = td_result_row($db, (int)$run, $s['ref'], $d, $site);
        $cols = array_keys($row);
        $db->prepare("REPLACE INTO trail_disc_results (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
           ->execute(array_values($row));
        $n++;
    }
    echo "run $run: rescored $n summits, shown routes changed on $repicked\n";
}
