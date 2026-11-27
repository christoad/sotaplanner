<?php
// CLI only: php trail_discovery_carcheck.php <site dir> <run> [<run> ...]
// Applies the car check (td_car_check, 2026-11-27) to the saved results of each run without searching again,
// and saves the outcome as a NEW run (settings of the old run plus car_check, note "Car check on run N"), so the
// old run stays as it was for comparison. Per summit: car check of the dirt starts (BRouter car profile),
// merge, community evidence, scores, pick, summit confidence and the comparison with the community track.
// Prints the new run id for each old run.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = rtrim($argv[1] ?? '', '/');
if (!$site || !is_file($site . '/trail_discovery_lib.php')) { fwrite(STDERR, "usage: php trail_discovery_carcheck.php <site dir> <run> ...\n"); exit(1); }
chdir($site);
require_once $site . '/config.php';
require_once $site . '/brouter_lib.php';
require_once $site . '/parking_lib.php';
require_once $site . '/trail_discovery_lib.php';
$db = getDbConnection();
foreach (array_slice($argv, 2) as $src) {
    $src = (int)$src;
    $st = $db->prepare("SELECT settings_json FROM trail_disc_runs WHERE id = ?"); $st->execute([$src]);
    $j = $st->fetchColumn();
    if ($j === false) { echo "run $src: unknown\n"; continue; }
    $set = td_clean_settings((json_decode($j, true) ?: []) + ['pick' => 'paved_first', 'source' => 'probes']);
    $set['car_check'] = true;
    $db->prepare("INSERT INTO trail_disc_runs (created_by, settings_json, note) VALUES (?, ?, ?)")
       ->execute(['KI6CR', json_encode($set), "Car check on run $src (saved results, no new search)"]);
    $dst = (int)$db->lastInsertId();
    $n = 0; $calls = 0; $blocked = 0; $dropped = 0; $t0 = microtime(true);
    $files = glob(td_store_dir() . '/runs/' . $src . '/*.json.gz');
    foreach ($files as $f) {
        $d = json_decode(gzdecode(file_get_contents($f)), true);
        if (!$d || empty($d['ok']) || !isset($d['candidates'])) continue;
        $s = $d['summit'];
        $t1 = microtime(true);
        $before = count($d['candidates']);
        $c = td_car_check($d['candidates'], $s['lat'], $s['lon']);
        $calls += $c;
        $blocked += count(array_filter($d['candidates'], fn($x) => ($x['car'] ?? null) === 'blocked'));
        $dropped += $before - count($d['candidates']);
        $d['candidates'] = td_merge_cands($d['candidates']);
        try { td_community_evidence($db, $d['candidates'], $s['lat'], $s['lon'], $s['ref'], $site . '/gpx_files/global'); } catch (Throwable $e) {}
        td_score_candidates($d['candidates'], $s['lat'], $s['lon'], $set['max_km']);
        [$d['chosen'], $d['chosen_alt']] = td_pick($d['candidates'], $set['pick']);
        $d['confidence'] = td_summit_conf($d['candidates'], $d['chosen']);
        $d['confidence_alt'] = td_summit_conf($d['candidates'], $d['chosen_alt']);
        $d['band'] = td_conf_band($d['confidence']);
        $d['settings'] = $set;
        $d['stats']['calls'] = ($d['stats']['calls'] ?? 0) + $c;
        $d['stats']['seconds'] = round(($d['stats']['seconds'] ?? 0) + microtime(true) - $t1, 1);
        $d['car_check_of_run'] = $src;
        unset($d['known']);
        $row = td_result_row($db, $dst, $s['ref'], $d, $site);
        $cols = array_keys($row);
        $db->prepare("REPLACE INTO trail_disc_results (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
           ->execute(array_values($row));
        $n++;
    }
    printf("run %d -> new run %d: %d summits, %d car calls, %d starts moved to the paved road, %d dropped, %.0f s\n",
        $src, $dst, $n, $calls, $blocked, $dropped, microtime(true) - $t0);
}
