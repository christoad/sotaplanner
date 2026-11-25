<?php
// CLI: php td_extract.php <slot dir> <run> [<run> ...]  > routes.tsv
// One row per discovered route with the signals for the confidence score and the label
// (route start, or its gate-closed parking spot, within 400 m of the community track's start).
$slot = rtrim($argv[1], '/');
chdir($slot);
require_once $slot . '/config.php';
require_once $slot . '/trail_discovery_lib.php';
require_once $slot . '/parking_lib.php';
$runs = array_slice($argv, 2);
echo implode("\t", ['run', 'ref', 'i', 'label', 'chosen', 'cls', 'rank', 'driveup', 'hits', 'time_min', 'rel_time', 'gap_m',
                    'ev_start_m', 'ev_trail_pct', 'ev_tracks', 'pk_m', 'pkT_m', 'closed_pk_m', 'old_conf', 'n_ranked', 'lot_k', 'lot_named', 'first_ring_km', 'sac', 'gain_m', 'dist_m']) . "\n";
foreach ($runs as $run) {
    foreach (glob(td_store_dir() . '/runs/' . (int)$run . '/*.json.gz') as $f) {
        $d = json_decode(gzdecode(file_get_contents($f)), true);
        if (!$d || empty($d['candidates']) || empty($d['known']['start'])) continue;
        $ks = $d['known']['start'];
        $s = $d['summit'];
        $pk = pk_near($s['lat'], $s['lon'], 14000);
        $pts = $pk['points'];
        $ranked = array_values(array_filter($d['candidates'], fn($c) => empty($c['driveup'])));
        $fastest = $ranked ? min(array_map(fn($c) => max(1, (int)$c['time_min']), $ranked)) : 1;
        $rank = 0;
        foreach ($d['candidates'] as $i => $c) {
            $driveup = !empty($c['driveup']);
            $r = $driveup ? 99 : $rank++;
            $dd = td_dist($ks[0], $ks[1], $c['lat'], $c['lon']);
            if (!empty($c['closed'])) $dd = min($dd, td_dist($ks[0], $ks[1], $c['closed']['lat'], $c['closed']['lon']));
            $n  = pk_nearest($pts, $c['lat'], $c['lon'], null, 5000);
            $nt = pk_nearest($pts, $c['lat'], $c['lon'], 'T', 5000);
            $nc = !empty($c['closed']) ? pk_nearest($pts, $c['closed']['lat'], $c['closed']['lon'], null, 5000) : null;
            echo implode("\t", [$run, $s['ref'], $i, $dd <= 400 ? 1 : 0, in_array($i, $d['chosen'], true) ? 1 : 0, $c['cls'], $r, $driveup ? 1 : 0,
                (int)$c['hits'], (int)$c['time_min'], round(max(1, (int)$c['time_min']) / $fastest, 3), (int)($c['summit_gap_m'] ?? 0),
                $c['ev_start_m'] ?? '', $c['ev_trail_pct'] ?? '', $c['ev_tracks'] ?? '',
                $n ? $n['d'] : 5000, $nt ? $nt['d'] : 5000, $nc ? $nc['d'] : '', $c['confidence'] ?? '', count($ranked),
                $c['lot']['k'] ?? '', (!empty($c['lot']['name'])) ? 1 : 0, $c['first_ring_km'] ?? '', $c['sac'] ?? '', (int)($c['gain_m'] ?? 0), (int)($c['dist_m'] ?? 0)]) . "\n";
        }
    }
}
