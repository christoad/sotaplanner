<?php
// CLI: php trail_discovery_misses.php <site dir> <run> ...  One row per summit: where the community started compared with what we found.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = rtrim($argv[1], '/'); chdir($site);
require_once $site . '/config.php';
require_once $site . '/trail_discovery_lib.php';
echo implode("\t", ['run', 'ref', 'shown_m', 'any_m', 'their_km', 'our_max_km', 'pk_m', 'pk_kind', 'pk_name', 'lot_probe', 'lot_err', 'nearest_cand_cls', 'their_time', 'their_dist_km', 'n_cands', 'n_shown_paved', 'n_dirt']) . "\n";
foreach (array_slice($argv, 2) as $run) {
    foreach (glob(td_store_dir() . '/runs/' . (int)$run . '/*.json.gz') as $f) {
        $d = json_decode(gzdecode(file_get_contents($f)), true);
        if (!$d || empty($d['known']['start'])) continue;
        $s = $d['summit']; $k = $d['known']; $ks = $k['start'];
        $shown = null; $any = null; $anyCls = '';
        foreach ($d['candidates'] as $i => $c) {
            $pts = [[$c['lat'], $c['lon']]];
            if (!empty($c['closed'])) $pts[] = [$c['closed']['lat'], $c['closed']['lon']];
            foreach ($pts as $p) {
                $dd = td_dist($ks[0], $ks[1], $p[0], $p[1]);
                if ($any === null || $dd < $any) { $any = $dd; $anyCls = $c['cls']; }
                if (in_array($i, $d['chosen'], true) && ($shown === null || $dd < $shown)) $shown = $dd;
            }
        }
        $pk = pk_near($ks[0], $ks[1], 1500);
        $n = $pk['points'][0] ?? null;
        // Did we route from a lot within 150 m of their start, and what happened?
        $lotSt = ''; $lotErr = '';
        foreach ($d['probes'] as $p) {
            if (empty($p['src'])) continue;
            if (td_dist($ks[0], $ks[1], $p['lat'], $p['lon']) <= 150) { $lotSt = $p['st']; $lotErr = substr($p['err'] ?? '', 0, 60); break; }
        }
        $ourMax = 0;
        foreach ($d['candidates'] as $c) $ourMax = max($ourMax, td_dist($s['lat'], $s['lon'], $c['lat'], $c['lon']));
        echo implode("\t", [$run, $s['ref'], $shown === null ? '' : (int)$shown, $any === null ? '' : (int)$any,
            round(td_dist($s['lat'], $s['lon'], $ks[0], $ks[1]) / 1000, 2), round($ourMax / 1000, 2),
            $n ? $n['d'] : '', $n ? $n['k'] : '', $n ? str_replace("\t", ' ', $n['name']) : '', $lotSt, $lotErr, $anyCls,
            $k['time_min'], round($k['dist_m'] / 1000, 2), count($d['candidates']),
            count(array_filter($d['chosen'], fn($i) => $d['candidates'][$i]['cls'] === 'paved')),
            count(array_filter($d['candidates'], fn($c) => $c['cls'] === 'dirt'))]) . "\n";
    }
}
