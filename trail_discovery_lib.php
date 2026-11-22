<?php
/**
 * Trail discovery (God Mode test phase, started 2026-11-15).
 *
 * Finds hiking routes from a summit down to a place an activator can drive to, using only our own
 * BRouter server (no Overpass, so it can later run on every summit).
 *
 * How it works:
 *  1. Probe points are dropped on rings around the summit (an outward spiral, one ring at a time).
 *  2. Each probe is routed to the summit with the hiking profile. BRouter snaps the probe to the
 *     nearest mapped way and labels every stretch of the route with its OSM way tags.
 *  3. Each route is read from the summit outward. The first stretch a car can use marks a starting
 *     point. Paved roads are preferred; dirt roads are the fallback tier. Roads closed to vehicles
 *     (gated fire roads, access=no) count as part of the hike.
 *  4. Starting points within TD_CLUSTER_M of each other are grouped; each group is re-routed
 *     directly to the summit for clean numbers, scored for confidence and ranked by hike time.
 *
 * Results of test runs: tables trail_disc_runs / trail_disc_results (db_migrate.php), route shapes as
 * gzipped JSON files in a private folder outside the web roots (td_store_dir()).
 */

require_once __DIR__ . '/brouter_lib.php';
require_once __DIR__ . '/sota_cache_helper.php';
require_once __DIR__ . '/parking_lib.php';

const TD_CLUSTER_M      = 150;   // starting points closer than this are the same place
const TD_CONCURRENCY    = 3;     // BRouter runs 3 threads
const TD_MIN_START_GAP  = 0.25;  // seconds between request starts (server allows 10/s, burst 40, since 2026-11-21)
const TD_RING_STEPS_KM  = [0.75, 1.5, 2.5, 4, 6, 8, 10, 12];
const TD_MAX_LOTS       = 160;   // parking source: most lots and trailheads routed per summit
const TD_LOT_M          = 400;   // parking source: the lot is the start when the walking begins this close to it

/* ───────────────────────── settings ───────────────────────── */

function td_default_settings() {
    return ['max_km' => 8, 'spacing_km' => 1.0, 'stop' => 'full', 'profile' => 'hiking-mountain', 'pick' => 'paved_first', 'region' => 'W6/CT', 'source' => 'probes'];
}

function td_clean_settings(array $in) {
    $d = td_default_settings();
    $max = (float)($in['max_km'] ?? $d['max_km']);
    $sp  = (float)($in['spacing_km'] ?? $d['spacing_km']);
    return [
        'max_km'     => in_array($max, [4, 6, 8, 10, 12], false) ? $max : $d['max_km'],
        'spacing_km' => in_array($sp, [0.75, 1.0, 1.5], false) ? $sp : $d['spacing_km'],
        'stop'       => in_array($in['stop'] ?? '', ['full', 'auto2'], true) ? $in['stop'] : $d['stop'],
        'profile'    => in_array($in['profile'] ?? '', brouter_profiles(), true) ? $in['profile'] : $d['profile'],
        'pick'       => in_array($in['pick'] ?? '', ['paved_first', 'mixed'], true) ? $in['pick'] : $d['pick'],
        // Where the search starts from (2026-11-21): rings of probe points, the mapped parking lots and
        // trailheads around the summit (OpenStreetMap list, parking_lib.php), or both.
        'source'     => in_array($in['source'] ?? '', ['probes', 'parking', 'both'], true) ? $in['source'] : $d['source'],
        // Test region: summits in this SOTA association/region that have a community track
        'region'     => preg_match('~^[A-Z0-9]{1,8}/[A-Z0-9]{2}$~', strtoupper((string)($in['region'] ?? ''))) ? strtoupper($in['region']) : $d['region'],
    ];
}

/* ───────────────────────── geometry ───────────────────────── */

function td_dist($lat1, $lon1, $lat2, $lon2) {
    $x = deg2rad($lon2 - $lon1) * cos(deg2rad(($lat1 + $lat2) / 2));
    $y = deg2rad($lat2 - $lat1);
    return 6371000 * sqrt($x * $x + $y * $y);
}

/** Point at a distance and bearing from a start point (flat approximation, fine under 20 km). */
function td_offset($lat, $lon, $dist_m, $bearing_deg) {
    $b = deg2rad($bearing_deg);
    return [$lat + ($dist_m * cos($b)) / 111320,
            $lon + ($dist_m * sin($b)) / (111320 * cos(deg2rad($lat)))];
}

/** Cumulative distance along a coords list [[lat, lon, ele], ...]. */
function td_cum(array $c) {
    $cum = [0.0];
    for ($i = 1, $n = count($c); $i < $n; $i++) {
        $cum[$i] = $cum[$i - 1] + td_dist($c[$i - 1][0], $c[$i - 1][1], $c[$i][0], $c[$i][1]);
    }
    return $cum;
}

/** Climb and drop along coords with a 5 m hysteresis (same filter for our routes and community tracks). */
function td_climb(array $c) {
    $up = 0.0; $down = 0.0; $ref = null;
    foreach ($c as $p) {
        $e = $p[2] ?? null;
        if ($e === null) continue;
        if ($ref === null) { $ref = $e; continue; }
        if ($e - $ref >= 5) { $up += $e - $ref; $ref = $e; }
        elseif ($ref - $e >= 5) { $down += $ref - $e; $ref = $e; }
    }
    return [$up, $down];
}

/** Distance from a point to a segment, in metres (local flat projection). */
function td_point_seg($plat, $plon, $alat, $alon, $blat, $blon) {
    $k = cos(deg2rad($plat)) * 111320;
    $ax = ($alon - $plon) * $k; $ay = ($alat - $plat) * 111320;
    $bx = ($blon - $plon) * $k; $by = ($blat - $plat) * 111320;
    $dx = $bx - $ax; $dy = $by - $ay;
    $l2 = $dx * $dx + $dy * $dy;
    $t = $l2 > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $l2)) : 0;
    $x = $ax + $t * $dx; $y = $ay + $t * $dy;
    return sqrt($x * $x + $y * $y);
}

/** Share (0 to 100) of the points in $pts that lie within $tol metres of the polyline $line. */
function td_overlap_pct(array $pts, array $line, $tol = 50) {
    if (!$pts || count($line) < 2) return 0;
    $minLat = INF; $maxLat = -INF; $minLon = INF; $maxLon = -INF;
    foreach ($line as $q) { $minLat = min($minLat, $q[0]); $maxLat = max($maxLat, $q[0]); $minLon = min($minLon, $q[1]); $maxLon = max($maxLon, $q[1]); }
    $padLat = $tol / 111320; $padLon = $tol / (111320 * cos(deg2rad(($minLat + $maxLat) / 2)));
    $near = 0;
    $n = count($line);
    foreach ($pts as $p) {
        if ($p[0] < $minLat - $padLat || $p[0] > $maxLat + $padLat || $p[1] < $minLon - $padLon || $p[1] > $maxLon + $padLon) continue;
        for ($i = 1; $i < $n; $i++) {
            $a = $line[$i - 1]; $b = $line[$i];
            if (max($a[0], $b[0]) < $p[0] - $padLat || min($a[0], $b[0]) > $p[0] + $padLat) continue;
            if (max($a[1], $b[1]) < $p[1] - $padLon || min($a[1], $b[1]) > $p[1] + $padLon) continue;
            if (td_point_seg($p[0], $p[1], $a[0], $a[1], $b[0], $b[1]) <= $tol) { $near++; break; }
        }
    }
    return (int)round(100 * $near / count($pts));
}

function td_thin(array $pts, $max = 300) {
    $n = count($pts);
    if ($n <= $max) return $pts;
    $out = [];
    $step = ($n - 1) / ($max - 1);
    for ($i = 0; $i < $max; $i++) $out[] = $pts[(int)round($i * $step)];
    return $out;
}

/* ───────────────────────── way classification ───────────────────────── */

function td_parse_tags($s) {
    $t = [];
    foreach (preg_split('/\s+/', trim((string)$s)) as $kv) {
        if ($kv === '' || strpos($kv, '=') === false) continue;
        [$k, $v] = explode('=', $kv, 2);
        $t[$k] = $v;
    }
    return $t;
}

/**
 * What a stretch of way means for an activator:
 *   'paved' a public road a normal car can use
 *   'dirt'  an unpaved road or graded track a car can use (fallback tier)
 *   'walk'  part of the hike: paths, rough 4x4 tracks, roads closed to vehicles
 */
function td_way_class(array $t) {
    $hw = $t['highway'] ?? '';
    $closed = ['no', 'private', 'agricultural', 'forestry', 'permit', 'military'];
    foreach (['access', 'vehicle', 'motor_vehicle', 'motorcar'] as $k) {
        if (isset($t[$k]) && in_array($t[$k], $closed, true)) return 'walk';
    }
    // A hiking grade on a way means people walk it, whatever the highway tag says (test run 1: San Gabriel Peak).
    if (isset($t['sac_scale']) || isset($t['trail_visibility'])) return 'walk';
    // Fire roads: a track or service road that lists walkers, cyclists or riders as allowed but says
    // nothing about motor vehicles is usually closed to cars (test run 1: Verdugo Mountains).
    if (in_array($hw, ['track', 'service'], true)
        && (($t['foot'] ?? '') === 'yes' || ($t['bicycle'] ?? '') === 'yes' || ($t['horse'] ?? '') === 'yes')
        && !isset($t['motor_vehicle']) && !isset($t['motorcar']) && !isset($t['vehicle'])
        && !in_array($t['access'] ?? '', ['yes', 'permissive', 'destination'], true)) {
        return 'walk';
    }
    $unpaved = ['unpaved', 'dirt', 'gravel', 'fine_gravel', 'compacted', 'ground', 'earth', 'sand', 'grass', 'mud', 'rock', 'pebblestone', 'woodchips'];
    $paved   = ['asphalt', 'paved', 'concrete', 'concrete:plates', 'paving_stones', 'chipseal', 'sett'];
    $surface = $t['surface'] ?? '';
    $roads = ['motorway', 'motorway_link', 'trunk', 'trunk_link', 'primary', 'primary_link', 'secondary', 'secondary_link',
              'tertiary', 'tertiary_link', 'residential', 'living_street', 'unclassified', 'road', 'service'];
    if (in_array($hw, $roads, true)) {
        if ($hw === 'service' && in_array($t['service'] ?? '', ['driveway', 'emergency_access'], true)) return 'walk';
        // Service roads up mountains are mostly utility or tower roads: paved only when the map says so.
        if ($hw === 'service' && ($t['service'] ?? '') !== 'parking_aisle' && !in_array($surface, $paved, true)) return 'dirt';
        return in_array($surface, $unpaved, true) ? 'dirt' : 'paved';
    }
    if ($hw === 'track') {
        $g = $t['tracktype'] ?? '';
        if ($g === 'grade4' || $g === 'grade5') return 'walk';
        if (in_array($surface, ['asphalt', 'paved', 'concrete'], true)) return 'paved';
        return 'dirt';
    }
    return 'walk';
}

/* ───────────────────────── BRouter, many at once ───────────────────────── */

/** Turn a BRouter geojson answer into coords plus classified segments. */
function td_parse_route($body) {
    $j = json_decode((string)$body, true);
    $f = $j['features'][0] ?? null;
    if (!$f || empty($f['geometry']['coordinates'])) return ['ok' => false, 'error' => 'Unexpected answer'];
    $coords = [];
    foreach ($f['geometry']['coordinates'] as $c) $coords[] = [(float)$c[1], (float)$c[0], isset($c[2]) ? (float)$c[2] : null];
    $n = count($coords);
    $cum = td_cum($coords);

    // Each message row describes the stretch that ENDS at its lon/lat (microdegrees).
    $msgs = $f['properties']['messages'] ?? [];
    $segs = [];
    $gates = [];   // coords indexes of barriers a car cannot pass (NodeTags apply to the point at the end of a row)
    $from = 0;
    $acc = 0.0;
    for ($r = 1, $m = count($msgs); $r < $m; $r++) {
        $row = $msgs[$r];
        $lon = ((int)$row[0]) / 1e6; $lat = ((int)$row[1]) / 1e6;
        $acc += (float)$row[3];
        $to = null;
        for ($i = $from; $i < $n; $i++) {
            if (abs($coords[$i][0] - $lat) < 2.5e-6 && abs($coords[$i][1] - $lon) < 2.5e-6) { $to = $i; break; }
        }
        if ($to === null) {   // fall back to matching by distance along the route
            for ($i = $from; $i < $n && $cum[$i] < $acc - 1; $i++);
            $to = min($n - 1, $i);
        }
        if ($r === $m - 1) $to = $n - 1;
        $tags = td_parse_tags($row[9] ?? '');
        $segs[] = ['a' => $from, 'b' => $to, 'tags' => $tags, 'cls' => td_way_class($tags), 'node' => (string)($row[10] ?? '')];
        $nt = td_parse_tags($row[10] ?? '');
        if (in_array($nt['barrier'] ?? '', ['gate', 'lift_gate', 'swing_gate', 'chain', 'bollard', 'block', 'jersey_barrier'], true)
            && !in_array($nt['motor_vehicle'] ?? ($nt['access'] ?? ''), ['yes', 'permissive', 'destination'], true)) {
            $gates[$to] = true;
        }
        $from = $to;
    }
    if (!$segs) $segs[] = ['a' => 0, 'b' => $n - 1, 'tags' => [], 'cls' => 'walk', 'node' => ''];
    return ['ok' => true, 'coords' => $coords, 'cum' => $cum, 'segs' => $segs, 'gates' => $gates,
            'distance_m' => (float)($f['properties']['track-length'] ?? end($cum))];
}

/**
 * Route many point pairs in parallel. $jobs = [key => [[lat, lon], [lat, lon]]].
 * Returns [key => parsed route or ['ok' => false, 'error' => ...]].
 */
function td_route_many(array $jobs, $profile = 'hiking-mountain') {
    $out = [];
    if (!brouter_configured()) {
        foreach ($jobs as $k => $_) $out[$k] = ['ok' => false, 'error' => 'BRouter is not configured'];
        return $out;
    }
    $queue = [];
    foreach ($jobs as $k => $pp) $queue[] = [$k, $pp, 0];
    $mh = curl_multi_init();
    $live = [];
    $lastStart = 0.0;
    $make = function ($pp) use ($profile) {
        $pairs = array_map(fn($p) => round($p[1], 6) . ',' . round($p[0], 6), $pp);
        $ch = curl_init(BROUTER_URL . '?' . http_build_query(['lonlats' => implode('|', $pairs), 'profile' => $profile,
                                                            'alternativeidx' => 0, 'format' => 'geojson']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Api-Key: ' . BROUTER_API_KEY],
                                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 75]);
        return $ch;
    };
    while ($queue || $live) {
        while ($queue && count($live) < TD_CONCURRENCY) {
            $wait = TD_MIN_START_GAP - (microtime(true) - $lastStart);
            if ($wait > 0) usleep((int)($wait * 1e6));
            [$k, $pp, $tries] = array_shift($queue);
            $ch = $make($pp);
            curl_multi_add_handle($mh, $ch);
            $live[(int)$ch] = [$ch, $k, $pp, $tries];
            $lastStart = microtime(true);
        }
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.2);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            [, $k, $pp, $tries] = $live[(int)$ch];
            unset($live[(int)$ch]);
            $body = curl_multi_getcontent($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            if (in_array($http, [429, 502, 503, 504, 0], true) && $tries < 2) {
                usleep(1500000);
                $queue[] = [$k, $pp, $tries + 1];
                continue;
            }
            if ($http === 200) {
                $out[$k] = td_parse_route($body);
            } else {
                $out[$k] = ['ok' => false, 'http' => $http, 'error' => trim(substr(strip_tags((string)$body), 0, 160)) ?: ('HTTP ' . $http)];
            }
        }
    }
    curl_multi_close($mh);
    return $out;
}

/* ───────────────────────── reading a route ───────────────────────── */

/**
 * Walk a probe-to-summit route from the summit outward.
 * Returns ['first' => start or null, 'paved' => start or null, 'open' => bool]
 * where a start is ['idx', 'cls', 'tags', 'hike_m'] and idx is the coords index of the starting point.
 */
function td_read_route(array $r) {
    $segs  = $r['segs'];
    $cum   = $r['cum'];
    $gates = $r['gates'] ?? [];
    $total = end($cum);
    $first = null; $paved = null;
    $mk = function ($j) use ($segs, $cum, $total) {
        $idx = $segs[$j]['b'];   // the end of this stretch nearest the summit
        return ['idx' => $idx, 'cls' => $segs[$j]['cls'], 'tags' => $segs[$j]['tags'], 'hike_m' => $total - $cum[$idx]];
    };
    $s = count($segs) - 1;
    while ($s >= 0) {
        if ($segs[$s]['cls'] === 'walk') { $s--; continue; }
        // One unbroken stretch of drivable road, from segment $t (outer end) up to $s (summit end)
        $t = $s;
        while ($t > 0 && $segs[$t - 1]['cls'] !== 'walk') $t--;
        // A gate on it: the road on the summit side is part of the hike, you park at the outermost gate.
        $g = null;
        foreach ($gates as $gi => $_) {
            if ($gi >= $segs[$t]['a'] && $gi <= $segs[$s]['b'] && ($g === null || $gi < $g)) $g = $gi;
        }
        $top = $s;
        if ($g !== null) {
            $top = null;
            for ($j = $s; $j >= $t; $j--) { if ($segs[$j]['b'] <= $g) { $top = $j; break; } }
            if ($top === null) { $s = $t - 1; continue; }   // the whole stretch is behind the gate
        }
        if ($first === null) $first = $mk($top);
        for ($j = $top; $j >= $t; $j--) {
            if ($segs[$j]['cls'] === 'paved') { $paved = $mk($j); break 2; }
        }
        $s = $t - 1;
    }
    return ['first' => $first, 'paved' => $paved, 'open' => $first === null];
}

/* ───────────────────────── the spiral search ───────────────────────── */

/**
 * Discover starting points and routes for one summit.
 * @return array ['ok', 'error'?, 'summit', 'settings', 'rings' => [...], 'probes' => [...], 'candidates' => [...],
 *                'chosen' => [indexes into candidates], 'stats' => [...]]
 */
function td_discover($ref, array $settings) {
    @set_time_limit(280);
    $t0 = microtime(true);
    $set = td_clean_settings($settings);
    $ref = strtoupper(trim($ref));
    $s = get_sota_cache_summit($ref);
    if (!$s || $s['lat'] === null) return ['ok' => false, 'error' => 'Unknown summit: ' . $ref];
    $slat = (float)$s['lat']; $slon = (float)$s['lon'];
    $summit_ele_m = !empty($s['altFt']) ? $s['altFt'] / 3.28084 : null;

    $clusters = [];   // each: lat, lon, cls, tags, hits, hike_m, first_ring_km, via, lot
    $probes = [];
    $rings = [];
    $emptyStreak = 0;
    $calls = 0;
    // Add one routed probe's starting point(s) to $clusters. $lot (parking source only): the mapped lot or
    // trailhead the route came from; when the walking starts within TD_LOT_M of it, the lot itself is the start.
    $absorb = function (array $r, $rk, $lot = null) use (&$clusters) {
        $read = td_read_route($r);
        $total = end($r['cum']);
        if ($lot && ($read['open'] || ($read['first'] && $total - $read['first']['hike_m'] <= TD_LOT_M))) {
            // Park at the lot: the whole route from the lot is the hike.
            $cls = $read['first']['cls'] ?? 'paved';
            $tags = $read['first']['tags'] ?? ['highway' => 'parking'];
            $starts = [['pt' => [$lot['lat'], $lot['lon']], 'st' => ['cls' => $cls, 'tags' => $tags, 'hike_m' => $total]]];
        } elseif ($read['open']) {
            return 'open';
        } else {
            $starts = [];
            foreach (array_filter([$read['first'], ($read['paved'] && $read['paved'] !== $read['first']) ? $read['paved'] : null]) as $st) {
                $starts[] = ['pt' => $r['coords'][$st['idx']], 'st' => $st];
            }
            $lot = null;
        }
        $new = 0;
        $dirtCi = null;   // cluster of this probe's dirt start, to note the paved road beyond it
        foreach ($starts as $x) {
            $pt = $x['pt']; $st = $x['st'];
            $hit = null;
            foreach ($clusters as $ci => $c) {
                if ($c['cls'] === $st['cls'] && td_dist($c['lat'], $c['lon'], $pt[0], $pt[1]) <= TD_CLUSTER_M) { $hit = $ci; break; }
            }
            if ($hit === null) {
                $clusters[] = ['lat' => $pt[0], 'lon' => $pt[1], 'cls' => $st['cls'], 'tags' => $st['tags'], 'hits' => 1,
                               'hike_m' => $st['hike_m'], 'first_ring_km' => $rk, 'via' => null, 'lot' => $lot];
                $hit = count($clusters) - 1;
                $new++;
            } else {
                $clusters[$hit]['hits']++;
                if ($lot && !$clusters[$hit]['lot']) {   // a mapped lot here: park at the lot
                    $clusters[$hit]['lot'] = $lot; $clusters[$hit]['lat'] = $pt[0]; $clusters[$hit]['lon'] = $pt[1];
                } elseif (!$clusters[$hit]['lot'] && $st['hike_m'] < $clusters[$hit]['hike_m']) {
                    $clusters[$hit]['hike_m'] = $st['hike_m'];
                    $clusters[$hit]['lat'] = $pt[0]; $clusters[$hit]['lon'] = $pt[1]; $clusters[$hit]['tags'] = $st['tags'];
                }
                if ($rk < $clusters[$hit]['first_ring_km']) $clusters[$hit]['first_ring_km'] = $rk;
            }
            if ($st['cls'] === 'dirt') $dirtCi = $hit;
            // "Gate closed" fallback: the paved road this probe reached beyond the dirt start (nearest one wins).
            elseif ($dirtCi !== null && ($clusters[$dirtCi]['via'] === null || $st['hike_m'] < $clusters[$dirtCi]['via']['hike_m'])) {
                $clusters[$dirtCi]['via'] = ['lat' => $pt[0], 'lon' => $pt[1], 'tags' => $st['tags'], 'hike_m' => $st['hike_m']];
            }
        }
        return ['found', $new];
    };
    foreach ($set['source'] === 'parking' ? [] : TD_RING_STEPS_KM as $ri => $rk) {
        if ($rk > $set['max_km'] + 1e-9) break;
        $n = (int)max(8, min(32, round(2 * M_PI * $rk / $set['spacing_km'])));
        $offset = ($ri % 2) ? 180 / $n : 0;   // stagger alternate rings so probes spiral rather than line up
        $jobs = [];
        for ($i = 0; $i < $n; $i++) {
            $p = td_offset($slat, $slon, $rk * 1000, $offset + 360 * $i / $n);
            $jobs[$ri . ':' . $i] = [$p, [$slat, $slon]];
        }
        $res = td_route_many($jobs, $set['profile']);
        $calls += count($jobs);
        $newHere = 0; $okHere = 0;
        foreach ($jobs as $k => $pp) {
            $r = $res[$k] ?? ['ok' => false, 'error' => 'missing'];
            $probe = ['lat' => round($pp[0][0], 5), 'lon' => round($pp[0][1], 5), 'ring' => $rk, 'st' => 'fail'];
            if (!$r['ok']) { $probe['err'] = $r['error'] ?? ''; $probes[] = $probe; continue; }
            $okHere++;
            $a = $absorb($r, $rk);
            $probe['st'] = $a === 'open' ? 'open' : 'found';
            $probes[] = $probe;
            if ($a !== 'open') $newHere += $a[1];
        }
        $rings[] = ['km' => $rk, 'probes' => $n, 'ok' => $okHere, 'new' => $newHere];
        $emptyStreak = ($newHere === 0 && $clusters) ? $emptyStreak + 1 : 0;
        if ($set['stop'] === 'auto2' && $emptyStreak >= 2) break;
    }

    // Backwards from the places people drive to: every mapped parking lot and trailhead within the search
    // distance (lots closer than TD_CLUSTER_M to one already taken count once; trailheads first, then the
    // nearest lots, at most TD_MAX_LOTS), each routed to the summit.
    $lotsUsed = 0; $lotsFound = null;
    if ($set['source'] !== 'probes') {
        $pk = pk_near($slat, $slon, (int)($set['max_km'] * 1000), 20000);
        if (!$pk['ok'] && !$pk['points']) return ['ok' => false, 'error' => 'Parking list not available: ' . ($pk['error'] ?? 'unknown')];
        $pts = $pk['points'];
        usort($pts, fn($a, $b) => ($a['k'] === 'T' ? 0 : 1) <=> ($b['k'] === 'T' ? 0 : 1) ?: $a['d'] <=> $b['d']);
        $sites = [];
        foreach ($pts as $p) {
            foreach ($sites as $q) if (td_dist($q['lat'], $q['lon'], $p['lat'], $p['lon']) <= TD_CLUSTER_M) continue 2;
            $sites[] = $p;
            if (count($sites) >= TD_MAX_LOTS) break;
        }
        $lotsFound = count($pts);
        $jobs = [];
        foreach ($sites as $i => $p) $jobs['p' . $i] = [[$p['lat'], $p['lon']], [$slat, $slon]];
        $res = $jobs ? td_route_many($jobs, $set['profile']) : [];
        $calls += count($jobs);
        $newHere = 0; $okHere = 0;
        foreach ($sites as $i => $p) {
            $r = $res['p' . $i] ?? ['ok' => false, 'error' => 'missing'];
            $rk = 0.75;   // report each lot in the ring band its distance falls in, like a probe
            foreach (TD_RING_STEPS_KM as $step) { $rk = $step; if ($p['d'] <= $step * 1000) break; }
            $probe = ['lat' => round($p['lat'], 5), 'lon' => round($p['lon'], 5), 'ring' => $rk, 'st' => 'fail', 'src' => $p['k'], 'name' => $p['name']];
            if (!$r['ok']) { $probe['err'] = $r['error'] ?? ''; $probes[] = $probe; continue; }
            $okHere++;
            $a = $absorb($r, $rk, ['k' => $p['k'], 'name' => $p['name'], 'lat' => $p['lat'], 'lon' => $p['lon']]);
            $probe['st'] = $a === 'open' ? 'open' : 'found';
            $probes[] = $probe;
            if ($a !== 'open') $newHere += $a[1];
        }
        $lotsUsed = count($sites);
        $rings[] = ['km' => $set['max_km'], 'probes' => $lotsUsed, 'ok' => $okHere, 'new' => $newHere, 'kind' => 'parking'];
    }

    // Re-route the best few groups of each tier straight from the starting point to the summit.
    $byTier = ['paved' => [], 'dirt' => []];
    foreach ($clusters as $ci => $c) $byTier[$c['cls']][] = $ci;
    $refineIds = [];
    foreach ($byTier as $tier => $ids) {
        usort($ids, fn($a, $b) => $clusters[$a]['hike_m'] <=> $clusters[$b]['hike_m']);
        foreach (array_slice($ids, 0, 6) as $ci) $refineIds[] = $ci;
    }
    $jobs = [];
    foreach ($refineIds as $ci) $jobs['c' . $ci] = [[$clusters[$ci]['lat'], $clusters[$ci]['lon']], [$slat, $slon]];
    $res = $jobs ? td_route_many($jobs, $set['profile']) : [];
    $calls += count($jobs);

    $cands = [];
    foreach ($refineIds as $ci) {
        $c = $clusters[$ci];
        $r = $res['c' . $ci] ?? null;
        if (!$r || !$r['ok']) continue;
        // The direct route may meet a usable road sooner than the probe route did: start there instead.
        $read = td_read_route($r);
        $startIdx = 0;
        $cls = $c['cls']; $tags = $c['tags'];
        $want = $cls === 'paved' ? $read['paved'] : $read['first'];
        if (!$c['lot'] && $want && $want['idx'] > 0) { $startIdx = $want['idx']; $cls = $want['cls']; $tags = $want['tags']; }
        $route = array_slice($r['coords'], $startIdx);
        if (count($route) < 2) $route = [$r['coords'][0], end($r['coords'])];
        $m = td_measure($route, $slat, $slon, $summit_ele_m);
        $cand = [
            'cls' => $cls, 'lat' => round($route[0][0], 6), 'lon' => round($route[0][1], 6),
            'road' => td_tags_label($tags), 'hits' => $c['hits'], 'first_ring_km' => $c['first_ring_km'],
            'dist_m' => $m['dist_m'], 'gain_m' => $m['gain_m'], 'drop_m' => $m['drop_m'], 'time_min' => $m['time_min'],
            'summit_gap_m' => $m['gap_m'], 'offtrail_m' => $m['offtrail_m'], 'sac' => td_max_sac($r, $startIdx),
            'straight_m' => round(td_dist($route[0][0], $route[0][1], $slat, $slon)),
            'route' => $m['route'],
            'via' => $cls === 'dirt' ? $c['via'] : null,
            'lot' => $c['lot'] ? ['k' => $c['lot']['k'], 'name' => $c['lot']['name']] : null,
        ];
        $cands[] = $cand;
    }

    // Dirt roads are often gated (seasonally, or right where the trail leaves the road), and no map knows
    // when. For every dirt start, also measure the hike from the paved road beyond it, along the dirt road.
    $jobs = [];
    foreach ($cands as $i => $c) {
        if ($c['cls'] === 'dirt' && $c['via']) $jobs['g' . $i] = [[$c['via']['lat'], $c['via']['lon']], [$c['lat'], $c['lon']], [$slat, $slon]];
    }
    $res = $jobs ? td_route_many($jobs, $set['profile']) : [];
    $calls += count($jobs);
    foreach ($cands as $i => &$c) {
        $via = $c['via']; unset($c['via']);
        if ($c['cls'] !== 'dirt') continue;
        $r = $res['g' . $i] ?? null;
        if (!$via || !$r || !$r['ok']) { $c['closed'] = null; continue; }   // no paved road found beyond it
        $m = td_measure($r['coords'], $slat, $slon, $summit_ele_m);
        $c['closed'] = [
            'lat' => round($r['coords'][0][0], 6), 'lon' => round($r['coords'][0][1], 6), 'road' => td_tags_label($via['tags']),
            'dist_m' => $m['dist_m'], 'gain_m' => $m['gain_m'], 'time_min' => $m['time_min'],
            'extra_min' => max(0, $m['time_min'] - $c['time_min']), 'route' => $m['route'],
        ];
    }
    unset($c);

    // Merge starts that became the same place after re-routing, then drop routes that retrace a shorter one.
    usort($cands, fn($a, $b) => $a['time_min'] <=> $b['time_min'] ?: $a['dist_m'] <=> $b['dist_m']);
    $kept = [];
    foreach ($cands as $c) {
        $dup = false;
        foreach ($kept as &$k) {
            if ($k['cls'] !== $c['cls']) continue;
            $same = td_dist($k['lat'], $k['lon'], $c['lat'], $c['lon']) <= TD_CLUSTER_M
                 || td_overlap_pct($c['route'], $k['route'], 30) >= 85;
            if ($same) { $k['hits'] += $c['hits']; $dup = true; break; }
        }
        unset($k);
        if (!$dup) $kept[] = $c;
    }
    // Confidence (model fitted on test run 3, 2026-11-17): needs each route's speed rank and the
    // community evidence from neighboring summits' tracks.
    if (function_exists('getDbConnection')) {
        try { td_community_evidence(getDbConnection(), $kept, $slat, $slon, $s['ref'] ?? $ref, __DIR__ . '/gpx_files/global'); }
        catch (Throwable $e) { /* no evidence: the score then rests on the route itself */ }
    }
    td_score_candidates($kept, $slat, $slon, $set['max_km']);

    // Up to 3 routes ($kept is sorted quickest first).
    //  paved_first (Chris's rule, 2026-11-15): paved starts only; dirt only when no paved start was found.
    //  mixed: the 3 quickest whatever the road, but always keep the quickest paved start if there is one.
    $paved = array_keys(array_filter($kept, fn($c) => $c['cls'] === 'paved'));
    $dirt  = array_keys(array_filter($kept, fn($c) => $c['cls'] === 'dirt'));
    $mixed = array_slice(array_keys($kept), 0, 3);
    if ($paved && $mixed && !array_intersect($mixed, $paved)) $mixed[count($mixed) - 1] = $paved[0];
    $pavedFirst = array_slice($paved ?: $dirt, 0, 3);
    // Both are kept so one test run can compare the rules; 'chosen' is the run's rule.
    $chosen     = array_values($set['pick'] === 'mixed' ? $mixed : $pavedFirst);
    $chosen_alt = array_values($set['pick'] === 'mixed' ? $pavedFirst : $mixed);

    $found = count(array_filter($probes, fn($p) => $p['st'] === 'found'));
    // Summit confidence: the chance that the routes shown include the way people actually go.
    $summitConf = function ($idx) use ($kept) {
        if (!$idx) return null;
        $q = 1.0;
        foreach ($idx as $i) $q *= 1 - $kept[$i]['confidence'] / 100;
        return (int)round(100 * (1 - $q));
    };
    return [
        'ok' => true, 'summit' => ['ref' => $s['ref'] ?? $ref, 'name' => $s['name'] ?? '', 'lat' => $slat, 'lon' => $slon, 'alt_ft' => $s['altFt'] ?? null],
        'settings' => $set, 'rings' => $rings, 'probes' => $probes, 'candidates' => $kept, 'chosen' => $chosen, 'chosen_alt' => $chosen_alt,
        'confidence' => $summitConf($chosen), 'confidence_alt' => $summitConf($chosen_alt), 'band' => td_conf_band($summitConf($chosen)),
        'stats' => ['calls' => $calls, 'probes' => count($probes), 'probes_found' => $found, 'lots_routed' => $lotsUsed, 'lots_found' => $lotsFound,
                    'seconds' => round(microtime(true) - $t0, 1), 'clusters' => count($clusters)],
    ];
}

/**
 * Distance, climb and hike time of a route that ends near the summit. If no mapped way reaches the top,
 * BRouter stops at the nearest way: the rest counts as off trail (straight line, climbing to the summit's
 * listed height), so a "0 minute" hike cannot hide it.
 */
function td_measure(array $route, $slat, $slon, $summit_ele_m) {
    $cum = td_cum($route);
    [$up, $down] = td_climb($route);
    $dist = end($cum);
    $end = end($route);
    $gap = td_dist($end[0], $end[1], $slat, $slon);
    $offtrail = 0;
    if ($gap > 40) {
        $offtrail = $gap;
        $dist += $gap;
        if ($summit_ele_m && $end[2] !== null && $summit_ele_m > $end[2]) $up += $summit_ele_m - $end[2];
    }
    return [
        'dist_m' => round($dist), 'gain_m' => round($up), 'drop_m' => round($down),
        'time_min' => (int)calculateHikeTime($dist / 1609.344, $up * 3.28084),
        'gap_m' => round($gap), 'offtrail_m' => round($offtrail),
        'route' => array_map(fn($p) => [round($p[0], 5), round($p[1], 5), $p[2] === null ? null : (int)round($p[2])], td_thin($route, 400)),
    ];
}

function td_tags_label(array $t) {
    $parts = [];
    foreach (['highway', 'surface', 'tracktype', 'service'] as $k) if (isset($t[$k])) $parts[] = $k === 'highway' ? $t[$k] : $t[$k];
    return implode(', ', $parts);
}

/** Hardest SAC hiking scale on the hike part of a route (0 = none tagged, 1 = hiking ... 6). */
function td_max_sac(array $r, $startIdx) {
    $scale = ['hiking' => 1, 'mountain_hiking' => 2, 'demanding_mountain_hiking' => 3, 'alpine_hiking' => 4,
              'demanding_alpine_hiking' => 5, 'difficult_alpine_hiking' => 6];
    $max = 0;
    foreach ($r['segs'] as $sg) {
        if ($sg['b'] <= $startIdx) continue;
        $max = max($max, $scale[$sg['tags']['sac_scale'] ?? ''] ?? 0);
    }
    return $max;
}

/**
 * Score every route of one summit (sorted quickest first). Adds 'driveup', 'pk_m' / 'pkT_m' (nearest mapped
 * parking or trailhead, OpenStreetMap list via parking_lib.php; 5000 = none within 5 km), 'confidence' and 'reasons'.
 * Used by td_discover() and to rescore saved runs without searching again.
 */
function td_score_candidates(array &$kept, $slat, $slon, $max_km = 8) {
    $pk = pk_near($slat, $slon, (int)(($max_km + 3) * 1000));
    $fastest = null;
    foreach ($kept as $k) {
        $du = $k['cls'] === 'dirt' && $k['dist_m'] < 400;
        if (!$du) $fastest = $fastest === null ? max(1, (int)$k['time_min']) : min($fastest, max(1, (int)$k['time_min']));
    }
    $rank = 0;
    foreach ($kept as &$k) {   // dirt roads that reach the summit are not ranked
        $k['driveup'] = $k['cls'] === 'dirt' && $k['dist_m'] < 400;
        if ($pk['ok']) {
            $n  = pk_nearest($pk['points'], $k['lat'], $k['lon'], null, 5000);
            $nt = pk_nearest($pk['points'], $k['lat'], $k['lon'], 'T', 5000);
            $k['pk_m'] = $n ? $n['d'] : 5000; $k['pkT_m'] = $nt ? $nt['d'] : 5000;
        } else {
            $k['pk_m'] = null; $k['pkT_m'] = null;   // parking list unreachable: the score leaves it out
        }
        $rel = $fastest ? max(1, (int)$k['time_min']) / $fastest : 1;
        [$k['confidence'], $k['reasons']] = td_confidence($k, $k['driveup'] ? 99 : $rank++, $rel);
    }
    unset($k);
}

/**
 * Route confidence (0 to 100): the estimated chance that this is a route people actually use, with
 * plain-English reasons. Logistic model refitted 2026-11-21 on test runs 5 and 6 (194 summits in W6/CT and
 * W4C/WM, 1,164 routes; label: our start, or its gate-closed parking spot, within 400 m of the community
 * track's start). Cross-validated AUC 0.85; fitted on one region it scores 0.84 / 0.88 on the other.
 * Changes from the 2026-11-17 model: mapped parking and trailheads are a signal, and a long hike only
 * counts against a route when it is also much slower than the quickest route for that summit (big
 * mountains, where every route is long, are no longer marked down). Fitting script: research/trail_discovery_fit.py.
 * $rank: 0 = quickest route found, 1, 2 = next quickest, 99 = not ranked (dirt road to the summit).
 * $rel: this route's hike time divided by the quickest route's for the summit.
 */
function td_confidence(array $c, $rank = 3, $rel = 1.0) {
    $why = [];
    $z = -3.32;
    if ($rank === 0)      { $z += 1.16; $why[] = '+ quickest route found'; }
    elseif ($rank === 1)  { $z += 1.09; $why[] = '+ second quickest route'; }
    elseif ($rank === 2)  { $z += 0.16; $why[] = 'third quickest route'; }
    elseif ($rank === 99) { $z += 1.00; $why[] = 'dirt road reaches the summit: road access unknown'; }
    $h = (int)$c['hits'];
    $z += 1.40 * log(1 + min($h, 200)) / 5;
    if ($h >= 10) $why[] = '+ ' . $h . ' probes led here';
    elseif ($h <= 2) $why[] = '- only ' . $h . ' probe' . ($h === 1 ? '' : 's') . ' led here';
    $ev = $c['ev_start_m'] ?? null;
    if ($ev !== null && $ev < 100) { $z += 1.11; $why[] = '+ a community track for a nearby summit starts here'; }
    if (($c['ev_trail_pct'] ?? 0) >= 75) { $z += 0.38; $why[] = '+ most of the hike follows community tracks'; }
    if (!isset($c['ev_tracks']) || !$c['ev_tracks']) $why[] = 'no community tracks nearby to compare with';
    $pkm = $c['pk_m'] ?? null; $pkt = $c['pkT_m'] ?? null;
    if ($pkm !== null) {
        if ($pkm <= 150) {
            $z += 1.39; $why[] = '+ starts at mapped ' . ($pkt !== null && $pkt <= 150 ? 'trailhead' : 'parking') . ' (' . $pkm . ' m)';
            if ($pkt !== null && $pkt <= 150) $z += 0.29;
        } elseif ($pkm <= 400) {
            $z += 0.64; $why[] = '+ mapped parking ' . $pkm . ' m away';
        } else {
            $why[] = 'no mapped parking within 400 m';
        }
    }
    if ($c['cls'] === 'paved') $z += 0.06;
    if ((int)($c['summit_gap_m'] ?? 0) > 120) $z -= 0.03;
    if ((int)$c['time_min'] < 45) { $z += 0.32; $why[] = '+ short hike'; }
    if ($rel > 2.5)      { $z -= 1.14; $why[] = '- more than 2.5 times as long as the quickest route here'; }
    elseif ($rel > 1.5)  { $z -= 0.62; $why[] = '- over 1.5 times as long as the quickest route here'; }
    if ((int)$c['time_min'] > 240 && $rel > 1.2) { $z -= 1.44; $why[] = '- over 4 hours and much slower than the quickest route here'; }
    return [(int)round(100 / (1 + exp(-$z))), $why];
}

/** Summit confidence band: high (pre-fill hike data), medium (review required), low (stays Needs Research). */
function td_conf_band($conf) {
    if ($conf === null) return null;
    return $conf >= 70 ? 'high' : ($conf >= 40 ? 'medium' : 'low');
}

/* ───────────────────────── comparing with a community track ───────────────────────── */

/**
 * The uphill part of a community track: from its start to the point nearest the summit.
 * Tracks recorded summit-first are flipped.
 */
function td_known_up(array $pts, $slat, $slon) {
    $n = count($pts);
    if ($n < 2) return [];
    $best = INF;
    foreach ($pts as $p) $best = min($best, td_dist($p[0], $p[1], $slat, $slon));
    $k = 0;
    foreach ($pts as $i => $p) { if (td_dist($p[0], $p[1], $slat, $slon) <= $best + 20) { $k = $i; break; } }
    if ($k < 3) {   // recorded from the summit down: take summit to the far end, flipped
        $far = 0; $fd = -1;
        foreach ($pts as $i => $p) { $d = td_dist($p[0], $p[1], $slat, $slon); if ($d > $fd) { $fd = $d; $far = $i; } }
        return array_reverse(array_slice($pts, 0, $far + 1));
    }
    return array_slice($pts, 0, $k + 1);
}

/**
 * Score a discovery result against the community track for the same summit.
 * Returns null when there is no usable track.
 */
function td_compare_known(array $disc, array $known_pts) {
    $s = $disc['summit'];
    $up = td_known_up($known_pts, $s['lat'], $s['lon']);
    if (count($up) < 5) return null;
    $cum = td_cum($up);
    [$kup] = td_climb($up);
    $kdist = end($cum);
    $start = $up[0];
    $upThin = td_thin($up, 400);

    $best = null;
    foreach ($disc['candidates'] as $i => $c) {
        // A dirt start also counts if the community parked at the paved road (gate closed).
        $variants = [[$c, false]];
        if (!empty($c['closed'])) $variants[] = [$c['closed'] + ['summit_gap_m' => $c['summit_gap_m'] ?? 0], true];
        foreach ($variants as [$v, $closed]) {
            // Include the off-trail finish (straight line to the summit) so cross-country tops still compare.
            $line = $v['route'];
            if (($v['summit_gap_m'] ?? 0) > 40) $line[] = [$s['lat'], $s['lon']];
            $ov = td_overlap_pct($upThin, $line, 50);
            $sd = td_dist($start[0], $start[1], $v['lat'], $v['lon']);
            $score = $ov - min(100, $sd / 20);   // overlap matters most, start distance breaks ties
            if ($best === null || $score > $best['score']) $best = ['i' => $i, 'overlap' => $ov, 'start_m' => (int)round($sd), 'score' => $score, 'closed' => $closed];
        }
    }
    $out = [
        'start' => [round($start[0], 6), round($start[1], 6)],
        'dist_m' => (int)round($kdist), 'gain_m' => (int)round($kup),
        'time_min' => (int)calculateHikeTime($kdist / 1609.344, $kup * 3.28084),
        'route' => array_map(fn($p) => [round($p[0], 5), round($p[1], 5)], td_thin($up, 400)),
        'match_i' => null, 'overlap' => 0, 'start_m' => null, 'in_chosen' => false, 'verdict' => 'miss',
    ];
    if ($best) {
        $out['match_i'] = $best['i'];
        $out['overlap'] = $best['overlap'];
        $out['start_m'] = $best['start_m'];
        $out['in_chosen'] = in_array($best['i'], $disc['chosen'], true);
        $out['in_chosen_alt'] = in_array($best['i'], $disc['chosen_alt'] ?? [], true);
        $out['via_closed'] = $best['closed'];   // matched our "gate closed" start, not the open dirt road
        if ($best['overlap'] >= 70 && $best['start_m'] <= 400) $out['verdict'] = 'match';
        elseif ($best['overlap'] >= 40 || $best['start_m'] <= 1000) $out['verdict'] = 'partial';
    }
    // Nearest start among the routes shown (open or gate-closed parking) to where the community started
    foreach (['chosen' => 'shown_start_m', 'chosen_alt' => 'alt_start_m'] as $set => $key) {
        $m = null;
        foreach ($disc[$set] ?? [] as $i) {
            $c = $disc['candidates'][$i];
            foreach (array_filter([[$c['lat'], $c['lon']], !empty($c['closed']) ? [$c['closed']['lat'], $c['closed']['lon']] : null]) as $p) {
                $dd = td_dist($start[0], $start[1], $p[0], $p[1]);
                if ($m === null || $dd < $m) $m = $dd;
            }
        }
        $out[$key] = $m === null ? null : (int)round($m);
    }
    return $out;
}

/* ───────────────────────── community evidence nearby ───────────────────────── */

/**
 * Evidence from OTHER summits' community tracks near this one (the summit's own track is never used,
 * so the test against it stays fair). Works anywhere the global GPX library has tracks.
 * Adds to each candidate: 'ev_start_m' (nearest community track end to our start, null if none nearby),
 * 'ev_trail_pct' (% of our hike that runs along a community track), 'ev_tracks' (tracks considered).
 * A gate-closed fallback gets 'ev_start_m' too.
 */
function td_community_evidence($db, array &$cands, $slat, $slon, $exclude_ref, $gpx_dir, $radius_km = 15) {
    $dLat = $radius_km / 111.0;
    $dLon = $radius_km / (111.0 * max(0.2, cos(deg2rad($slat))));
    $st = $db->prepare("SELECT sota_ref, filename, trailhead_lat, trailhead_lon FROM global_gpx_tracks
                        WHERE summit_lat BETWEEN ? AND ? AND summit_lon BETWEEN ? AND ? AND sota_ref <> ? AND filename IS NOT NULL");
    $st->execute([$slat - $dLat, $slat + $dLat, $slon - $dLon, $slon + $dLon, $exclude_ref]);
    $ends = []; $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $pts = brouter_read_gpx_points($gpx_dir . '/' . basename($t['filename']), 600);
        if (count($pts) < 5) continue;
        $ends[] = [$pts[0][0], $pts[0][1]];
        $ends[] = [end($pts)[0], end($pts)[1]];
        if ($t['trailhead_lat'] !== null) $ends[] = [(float)$t['trailhead_lat'], (float)$t['trailhead_lon']];
        $la = array_column($pts, 0); $lo = array_column($pts, 1);
        $lines[] = ['pts' => $pts, 'box' => [min($la) - 0.0006, max($la) + 0.0006, min($lo) - 0.0008, max($lo) + 0.0008]];
    }
    $nearest = function ($lat, $lon) use ($ends) {
        $best = null;
        foreach ($ends as $e) { $d = td_dist($lat, $lon, $e[0], $e[1]); if ($best === null || $d < $best) $best = $d; }
        return $best === null ? null : (int)round($best);
    };
    foreach ($cands as &$c) {
        $c['ev_tracks'] = count($lines);
        $c['ev_start_m'] = $nearest($c['lat'], $c['lon']);
        if (!empty($c['closed'])) $c['closed']['ev_start_m'] = $nearest($c['closed']['lat'], $c['closed']['lon']);
        // Share of our hike (ignoring the last 150 m, which every route to this summit shares) on a community track
        $route = td_thin($c['route'], 120);
        $tot = 0; $on = 0;
        foreach ($route as $p) {
            if (td_dist($p[0], $p[1], $slat, $slon) < 150) continue;
            $tot++;
            foreach ($lines as $L) {
                [$a, $b, $x, $y] = $L['box'];
                if ($p[0] < $a || $p[0] > $b || $p[1] < $x || $p[1] > $y) continue;
                if (td_overlap_pct([$p], $L['pts'], 50) > 0) { $on++; break; }
            }
        }
        $c['ev_trail_pct'] = $tot ? (int)round(100 * $on / $tot) : null;
    }
    unset($c);
}

/* ───────────────────────── activation zone ───────────────────────── */

/**
 * Activation zone outline(s) for the map, as [[[lat, lon], ...], ...]. Uses the permanent
 * activation_zone_cache (SOTLAS first, activation.zone fallback) via config.php.
 */
function td_zone_rings($ref, $lat, $lon, $alt_ft) {
    if (!function_exists('get_activation_zone_from_api') || !$alt_ft) return ['source' => null, 'rings' => []];
    $az = get_activation_zone_from_api($ref, $lat, $lon, $alt_ft / 3.28084);
    if (!$az || empty($az['polygon'])) return ['source' => null, 'rings' => []];
    // GeoJSON coordinates of any depth (ring, polygon, multipolygon): collect the outer rings.
    $c = $az['polygon'];
    $depth = 0;
    for ($d = $c; is_array($d) && $d && is_array(reset($d)); $d = reset($d)) $depth++;
    $outer = $depth === 1 ? [$c] : ($depth === 2 ? [$c[0]] : ($depth === 3 ? array_map(fn($p) => $p[0], $c) : []));
    $rings = [];
    foreach ($outer as $ring) $rings[] = array_map(fn($p) => [round((float)$p[1], 6), round((float)$p[0], 6)], td_thin($ring, 300));
    return ['source' => $az['source'] ?? null, 'rings' => $rings];
}

/* ───────────────────────── storage ───────────────────────── */

/** Private folder for route shapes, one level above the web roots (shared by production and staging). */
function td_store_dir() {
    $home = file_exists(dirname(__DIR__) . '/sotaplanner_secrets.php') ? dirname(__DIR__) : dirname(dirname(__DIR__));
    return $home . '/sota_discovered';
}

function td_save_file($run_id, $ref, array $payload) {
    $dir = td_store_dir() . '/runs/' . (int)$run_id;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return null;
    $name = preg_replace('/[^A-Z0-9_-]/', '_', strtoupper($ref)) . '.json.gz';
    $ok = @file_put_contents($dir . '/' . $name, gzencode(json_encode($payload), 6));
    return $ok === false ? null : 'runs/' . (int)$run_id . '/' . $name;
}

function td_load_file($rel) {
    if (!preg_match('#^runs/\d+/[A-Z0-9_-]+\.json\.gz$#', (string)$rel)) return null;
    $raw = @file_get_contents(td_store_dir() . '/' . $rel);
    if ($raw === false) return null;
    return json_decode(gzdecode($raw), true);
}
