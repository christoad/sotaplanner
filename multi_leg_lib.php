<?php
// Multi-Activate "what happens between stops" settings, shared by multi_activate.php,
// multi_activate_pdf.php and multi_leg_save.php.
//
// Two settings are stored per saved route:
//   1. Leg mode, for each ordered pair of neighbouring summits (from_summit_id > to_summit_id):
//        'car'  go back to the car and drive to the next starting point
//        'hike' hike straight from this summit to the next one
//      No row means "automatic": hike if a shared alternate start pin exists for the pair
//      (see multi_alt_lib.php), otherwise car. Keyed by the pair, not the position, so the
//      choice survives drag-to-reorder as long as the two summits stay neighbours.
//   2. Finish address (multi_activations.finish_address_id): where the last drive ends.
//      NULL means back at the starting address (the classic round trip).
//
// "Hike direct" asks BRouter (brouter_paths_lib.php, permanently cached) for a walkable
// route between the two summits. If BRouter cannot find a complete one, we look for a route
// through the recorded GPX tracks already attached to the summits in the route (one summit's
// track often runs down onto another's trail). If neither works, the leg falls back to the car
// and the tile says it could not be calculated, so a route is never silently planned over a gap.

require_once __DIR__ . '/brouter_paths_lib.php';

/** ['fromId>toId' => 'car'|'hike'] for one saved route. Empty when the table is missing. */
function multi_leg_load(PDO $db, $multi_id) {
    $out = [];
    if (!$multi_id) return $out;
    try {
        $st = $db->prepare("SELECT from_summit_id, to_summit_id, mode FROM multi_leg_modes WHERE multi_activation_id = ?");
        $st->execute([(int)$multi_id]);
        foreach ($st->fetchAll() as $r) $out[$r['from_summit_id'] . '>' . $r['to_summit_id']] = $r['mode'];
    } catch (Exception $e) { /* migration not run yet */ }
    return $out;
}

/** Mode setting for stop $i (the leg from stop i-1 to stop i): 'car', 'hike' or null (automatic). */
function multi_leg_mode_for(array $modes, array $stops, $i) {
    if ($i < 1 || !isset($stops[$i], $stops[$i - 1])) return null;
    return $modes[$stops[$i - 1]['id'] . '>' . $stops[$i]['id']] ?? null;
}

function multi_leg_set(PDO $db, $multi_id, $from_id, $to_id, $mode) {
    if ($mode === 'auto') {
        $db->prepare("DELETE FROM multi_leg_modes WHERE multi_activation_id = ? AND from_summit_id = ? AND to_summit_id = ?")
           ->execute([(int)$multi_id, (int)$from_id, (int)$to_id]);
        return;
    }
    $db->prepare("INSERT INTO multi_leg_modes (multi_activation_id, from_summit_id, to_summit_id, mode) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE mode = VALUES(mode)")
       ->execute([(int)$multi_id, (int)$from_id, (int)$to_id, $mode]);
}

/** [summit_id => minutes] per-summit on-the-air overrides for one saved route. */
function multi_stop_times_load(PDO $db, $multi_id) {
    $out = [];
    if (!$multi_id) return $out;
    try {
        $st = $db->prepare("SELECT summit_id, activation_min FROM multi_stop_times WHERE multi_activation_id = ?");
        $st->execute([(int)$multi_id]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['summit_id']] = (int)$r['activation_min'];
    } catch (Exception $e) { /* migration not run yet */ }
    return $out;
}

/** Minutes of 0 clears the override (back to the route default). Keyed by summit, so a summit repeated in one route shares its time. */
function multi_stop_time_set(PDO $db, $multi_id, $summit_id, $minutes) {
    $minutes = (int)$minutes;
    if ($minutes <= 0) {
        $db->prepare("DELETE FROM multi_stop_times WHERE multi_activation_id = ? AND summit_id = ?")->execute([(int)$multi_id, (int)$summit_id]);
        return;
    }
    $minutes = max(5, min(480, $minutes));
    $db->prepare("INSERT INTO multi_stop_times (multi_activation_id, summit_id, activation_min) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE activation_min = VALUES(activation_min)")->execute([(int)$multi_id, (int)$summit_id, $minutes]);
}

/** [summit_id => true] for summits where the walk down continues to a NEW trailhead (default: back the way we came). */
function multi_exit_load(PDO $db, $multi_id) {
    $out = [];
    if (!$multi_id) return $out;
    try {
        $st = $db->prepare("SELECT summit_id FROM multi_stop_exit WHERE multi_activation_id = ?");
        $st->execute([(int)$multi_id]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['summit_id']] = true;
    } catch (Exception $e) { /* migration not run yet */ }
    return $out;
}

function multi_exit_set(PDO $db, $multi_id, $summit_id, $new_trailhead) {
    if ($new_trailhead) {
        $db->prepare("INSERT IGNORE INTO multi_stop_exit (multi_activation_id, summit_id) VALUES (?, ?)")->execute([(int)$multi_id, (int)$summit_id]);
    } else {
        $db->prepare("DELETE FROM multi_stop_exit WHERE multi_activation_id = ? AND summit_id = ?")->execute([(int)$multi_id, (int)$summit_id]);
    }
}

/**
 * [summit_id => 'back'|'loop'] explicit choice for how a walking chain ends, when no row in multi_stop_exit
 * sends it to the summit's own trailhead. No row means automatic: finish the loop when the recorded track allows it,
 * otherwise track back.
 */
function multi_end_load(PDO $db, $multi_id) {
    $out = [];
    if (!$multi_id) return $out;
    try {
        $st = $db->prepare("SELECT summit_id, mode FROM multi_stop_end WHERE multi_activation_id = ?");
        $st->execute([(int)$multi_id]);
        foreach ($st->fetchAll() as $r) $out[(int)$r['summit_id']] = $r['mode'];
    } catch (Exception $e) { /* migration not run yet */ }
    return $out;
}

/** $mode 'back' or 'loop' stores the choice; anything else clears it. */
function multi_end_set(PDO $db, $multi_id, $summit_id, $mode) {
    try {
        if (in_array($mode, ['back', 'loop'], true)) {
            $db->prepare("INSERT INTO multi_stop_end (multi_activation_id, summit_id, mode) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE mode = VALUES(mode)")->execute([(int)$multi_id, (int)$summit_id, $mode]);
        } else {
            $db->prepare("DELETE FROM multi_stop_end WHERE multi_activation_id = ? AND summit_id = ?")->execute([(int)$multi_id, (int)$summit_id]);
        }
    } catch (Exception $e) { /* migration not run yet */ }
}

/**
 * Where the walk to the next summit leaves the activation zone of $stop: the point of its zone
 * boundary closest to the target. Falls back to the summit's own coordinates when no zone is known.
 */
function multi_leg_zone_exit(array $stop, $to_lat, $to_lon) {
    $fallback = [(float)$stop['summit_lat'], (float)$stop['summit_lng']];
    try {
        $az = get_activation_zone_from_api($stop['ref'], $stop['summit_lat'], $stop['summit_lng'], $stop['elev_m'] ?? 0);
    } catch (Throwable $e) { return $fallback; }
    if (!$az || empty($az['polygon'])) return $fallback;
    $best = null; $best_d = INF;
    foreach (bp_rings($az['polygon']) as $ring) {
        foreach ($ring as $pt) {
            if (!is_array($pt) || count($pt) < 2) continue;
            $d = bp_dist($pt[1], $pt[0], $to_lat, $to_lon);   // GeoJSON order is [lon, lat]
            if ($d < $best_d) { $best_d = $d; $best = [(float)$pt[1], (float)$pt[0]]; }
        }
    }
    return $best ?: $fallback;
}

/** All addresses of a dashboard, with coordinates filled in (and saved) where missing. */
function multi_leg_addresses(PDO $db, $group_id) {
    $st = $db->prepare("SELECT * FROM addresses WHERE planning_group_id = ? ORDER BY id");
    $st->execute([(int)$group_id]);
    return $st->fetchAll();
}

/** Short display name for an address row. */
function multi_leg_address_label(array $a) {
    $l = trim((string)($a['label'] ?? ''));
    return $l !== '' ? $l : (string)$a['address'];
}

/** Recorded GPX files of the stops in a route (one per summit, existing files only), for multi_leg_track_route(). */
function multi_leg_track_files(array $stops) {
    $out = [];
    foreach ($stops as $s) {
        $f = $s['gpx_file'] ?? null;
        if ($f && is_readable($f)) $out[$f] = $f;
    }
    return array_values($out);
}

/** Reads a GPX file into points about 20 m apart: [[lat, lon, ele|null], ...]. */
function multi_leg_read_gpx($file) {
    $c = @file_get_contents($file);
    if (!$c) return [];
    preg_match_all('/<trkpt\b[^>]*?\blat="([\d.\-]+)"[^>]*?\blon="([\d.\-]+)"[^>]*?(?:\/>|>\s*(?:<ele>([\d.\-]+)<\/ele>)?)/i', $c, $m, PREG_SET_ORDER);
    $pts = [];
    $last = null;
    $n = count($m);
    foreach ($m as $i => $r) {
        $lat = (float)$r[1]; $lon = (float)$r[2];
        $ele = (isset($r[3]) && $r[3] !== '') ? (float)$r[3] : null;
        if ($last === null || bp_dist($last[0], $last[1], $lat, $lon) >= 20 || $i === $n - 1) {
            $pts[] = [$lat, $lon, $ele];
            $last = [$lat, $lon];
        }
    }
    return $pts;
}

// ── One recorded track through both summits ──────────────────────────────────
// A loop (or a traverse) recorded as one GPX track often passes several summits. When the two summits of a
// "Hike direct" leg are both on one recorded track of the route, that piece of the track IS the walk: no path
// finder, no stitching. The same track then also gives the first summit's exact way up and, for a loop, the
// rest of the loop back to the car.
const MULTI_LEG_MATCH_M = 200;   // a summit this close to a track counts as on it (summit coordinates are rarely exactly on the path)
const MULTI_LEG_LOOP_M  = 300;   // a track that ends this close to where the car is parked closes the loop

/** Every point of a GPX file at full resolution: [[lat, lon, ele|null], ...]. Cached per request. */
function multi_leg_full_points($file) {
    static $cache = [];
    if (!$file || !is_readable($file)) return [];
    if (isset($cache[$file])) return $cache[$file];
    $c = @file_get_contents($file);
    preg_match_all('/<trkpt\b[^>]*?\blat="([\d.\-]+)"[^>]*?\blon="([\d.\-]+)"[^>]*?(?:\/>|>\s*(?:<ele>([\d.\-]+)<\/ele>)?)/i', (string)$c, $m, PREG_SET_ORDER);
    $pts = [];
    foreach ($m as $r) $pts[] = [(float)$r[1], (float)$r[2], (isset($r[3]) && $r[3] !== '') ? (float)$r[3] : null];
    return $cache[$file] = $pts;
}

/** Index of the closest point of each pass the track makes within MULTI_LEG_MATCH_M of ($lat, $lng). */
function multi_leg_passes(array $pts, $lat, $lng) {
    $out = []; $best = null; $best_d = INF;
    foreach ($pts as $k => $p) {
        $d = bp_dist($p[0], $p[1], $lat, $lng);
        if ($d <= MULTI_LEG_MATCH_M) {
            if ($d < $best_d) { $best_d = $d; $best = $k; }
        } elseif ($best !== null) {
            $out[] = $best; $best = null; $best_d = INF;
        }
    }
    if ($best !== null) $out[] = $best;
    return $out;
}

/** Distance (mi), climb (ft) and drop (ft) walking $pts from index $a to $b ($a <= $b), same smoothing as the GPX analysis. */
function multi_leg_span(array $pts, $a, $b) {
    $mi = 0.0; $eles = [];
    for ($k = $a; $k <= $b; $k++) {
        if ($k > $a) $mi += bp_dist($pts[$k - 1][0], $pts[$k - 1][1], $pts[$k][0], $pts[$k][1]) / 1609.344;
        if ($pts[$k][2] !== null) $eles[] = $pts[$k][2];
    }
    [$g, $l] = gpx_smoothed_gain_loss($eles);
    return [$mi, $g * 3.28084, $l * 3.28084];
}

/**
 * Looks for one recorded track (of any summit in the route) that passes $prev's summit and then $cur's summit.
 * The track may be walked in its recorded direction or backwards (a loop done the other way round); the
 * recorded direction is preferred. Returns null, or:
 *   ['file', 'rev' => walked backwards, 'pts' => the whole track in walking order, 'a' / 'b' => indexes of the
 *    two summits in 'pts' (a < b), 'mi', 'gain_ft', 'loss_ft' for the piece from a to b]
 * $track_files lists the route's GPX files; $prev / $cur may carry 'gpx_file' so their own tracks are tried first.
 */
function multi_leg_track_match(array $prev, array $cur, array $track_files) {
    $files = array_values(array_unique(array_filter(array_merge([$prev['gpx_file'] ?? null, $cur['gpx_file'] ?? null], $track_files))));
    $straight = bp_dist($prev['summit_lat'], $prev['summit_lng'], $cur['summit_lat'], $cur['summit_lng']);
    $best = null; $best_score = INF;
    foreach ($files as $f) {
        $pts = multi_leg_full_points($f);
        $n = count($pts);
        if ($n < 2) continue;
        $pa = multi_leg_passes($pts, $prev['summit_lat'], $prev['summit_lng']);
        $pb = multi_leg_passes($pts, $cur['summit_lat'], $cur['summit_lng']);
        foreach ($pa as $a) foreach ($pb as $b) {
            if ($a === $b) continue;
            $rev = $b < $a;
            [$mi, $g, $l] = $rev ? multi_leg_span($pts, $b, $a) : multi_leg_span($pts, $a, $b);
            if ($rev) [$g, $l] = [$l, $g];
            $m = $mi * 1609.344;
            // Same rule as the path finder: a piece far longer than the gap is not a sensible "direct" hike
            if ($straight > 0 && $m > $straight * 2.5 && $m - $straight > 1200) continue;
            $score = $mi * ($rev ? 1.05 : 1.0);
            if ($score < $best_score) {
                $best_score = $score;
                $best = ['file' => $f, 'rev' => $rev, 'a' => $rev ? $n - 1 - $a : $a, 'b' => $rev ? $n - 1 - $b : $b,
                         'mi' => $mi, 'gain_ft' => $g, 'loss_ft' => $l];
            }
        }
    }
    if ($best) {
        $pts = multi_leg_full_points($best['file']);
        $best['pts'] = $best['rev'] ? array_reverse($pts) : $pts;
    }
    return $best;
}

/** Points of $pts from index $a to $b as route coordinates [lat, lon, 1 (on a recorded track), ele]. */
function multi_leg_track_coords(array $pts, $a, $b) {
    $out = [];
    for ($k = $a; $k <= $b; $k++) $out[] = [$pts[$k][0], $pts[$k][1], 1, $pts[$k][2]];
    return $out;
}

/**
 * Fallback for "Hike direct" when BRouter finds no complete path: a walk built only from recorded GPX tracks.
 * Every track becomes a line of points; two tracks are joined wherever they come within 40 m of each other
 * (the trails meet or cross), then the shortest way from the edge of the previous summit's activation zone to
 * the next summit is found along them. This is what lets "Summit A's track runs down onto Summit B's trail"
 * count as a route. Returns the same shape as brouter_route_cached() plus 'method' => 'tracks', or null.
 *
 * @param array $files  GPX file paths (see multi_leg_track_files)
 * @param array $from   [lat, lon] where the walk starts
 */
function multi_leg_track_route(array $cur, array $files, array $from, array $extra_line = []) {
    $JOIN_M = 40; $SNAP_M = 300;
    $nodes = []; $adj = [];
    $add_edge = function ($a, $b, $w) use (&$adj) { $adj[$a][$b] = $w; $adj[$b][$a] = $w; };
    // Lines to put in the graph: every recorded track, plus (optionally) a BRouter route. The BRouter line costs a
    // little more per metre, so wherever it runs along a recorded track the proven track wins, and BRouter is
    // used only for the stretches no recorded track covers.
    $lines = [];
    foreach ($files as $fi => $f) {
        $pts = multi_leg_read_gpx($f);
        if (count($pts) >= 2) $lines[] = ['trk' => $fi, 'pts' => bp_thin($pts, 2500), 'k' => 1.0];
    }
    if (count($extra_line) >= 2) $lines[] = ['trk' => 'brouter', 'pts' => $extra_line, 'k' => 1.15];
    foreach ($lines as $ln) {
        $prev_i = null;
        foreach ($ln['pts'] as $p) {
            $i = count($nodes);
            $nodes[] = ['lat' => $p[0], 'lon' => $p[1], 'ele' => $p[2] ?? null, 'trk' => $ln['trk']];
            $adj[$i] = $adj[$i] ?? [];
            if ($prev_i !== null) $add_edge($prev_i, $i, $ln['k'] * bp_dist($nodes[$prev_i]['lat'], $nodes[$prev_i]['lon'], $p[0], $p[1]));
            $prev_i = $i;
        }
    }
    $n = count($nodes);
    if ($n < 2) return null;

    // Grid of about 60 m cells so only nearby points are compared
    $cell_lat = 0.00054;
    $cell_lon = 0.00054 / max(0.2, cos(deg2rad($nodes[0]['lat'])));
    $grid = [];
    $cell_of = fn($lat, $lon) => [(int)floor($lat / $cell_lat), (int)floor($lon / $cell_lon)];
    foreach ($nodes as $i => $nd) { [$cx, $cy] = $cell_of($nd['lat'], $nd['lon']); $grid[$cx . ',' . $cy][] = $i; }
    $near = function ($lat, $lon, $max_m, $other_trk = null) use ($grid, $nodes, $cell_of) {
        [$cx, $cy] = $cell_of($lat, $lon);
        $out = [];
        for ($dx = -1; $dx <= 1; $dx++) for ($dy = -1; $dy <= 1; $dy++) {
            foreach ($grid[($cx + $dx) . ',' . ($cy + $dy)] ?? [] as $j) {
                if ($other_trk !== null && $nodes[$j]['trk'] === $other_trk) continue;
                $d = bp_dist($lat, $lon, $nodes[$j]['lat'], $nodes[$j]['lon']);
                if ($d <= $max_m) $out[$j] = $d;
            }
        }
        return $out;
    };
    // Join different tracks where they meet
    foreach ($nodes as $i => $nd) {
        foreach ($near($nd['lat'], $nd['lon'], $JOIN_M, $nd['trk']) as $j => $d) {
            if ($j > $i) $add_edge($i, $j, $d + 5);   // small penalty so a track is preferred over hopping between two
        }
    }

    // Start and target: the closest track point to each
    $closest = function ($lat, $lon) use ($nodes, $SNAP_M) {
        $best = null; $bd = $SNAP_M;
        foreach ($nodes as $i => $nd) {
            if (abs($nd['lat'] - $lat) > 0.004 || abs($nd['lon'] - $lon) > 0.005) continue;
            $d = bp_dist($lat, $lon, $nd['lat'], $nd['lon']);
            if ($d <= $bd) { $bd = $d; $best = $i; }
        }
        return $best;
    };
    $src = $closest($from[0], $from[1]);
    $dst = $closest($cur['summit_lat'], $cur['summit_lng']);
    if ($src === null || $dst === null) return null;

    // Shortest way along the tracks
    $dist = array_fill(0, $n, INF); $prev = array_fill(0, $n, -1);
    $dist[$src] = 0;
    $q = new SplPriorityQueue();
    $q->insert($src, 0);
    while (!$q->isEmpty()) {
        $u = $q->extract();
        if ($u === $dst) break;
        foreach ($adj[$u] as $v => $w) {
            $nd = $dist[$u] + $w;
            if ($nd < $dist[$v]) { $dist[$v] = $nd; $prev[$v] = $u; $q->insert($v, -$nd); }
        }
    }
    if (!is_finite($dist[$dst])) return null;
    $path = [];
    for ($v = $dst; $v !== -1; $v = $prev[$v]) $path[] = $v;
    $path = array_reverse($path);

    $coords = []; $eles = [];
    // Third value: 1 where the point is on a recorded (proven) track, 0 where it comes from BRouter or a short connecting step
    foreach ($path as $i) {
        $coords[] = [$nodes[$i]['lat'], $nodes[$i]['lon'], $nodes[$i]['trk'] === 'brouter' ? 0 : 1, $nodes[$i]['ele']];
        $eles[] = $nodes[$i]['ele'];
    }
    // A short step from the start point / to the summit when the tracks stop just short of them
    if (bp_dist($from[0], $from[1], $coords[0][0], $coords[0][1]) > 30) array_unshift($coords, [(float)$from[0], (float)$from[1], 0, null]);
    $lastc = end($coords);
    if (bp_dist($cur['summit_lat'], $cur['summit_lng'], $lastc[0], $lastc[1]) > 30) $coords[] = [(float)$cur['summit_lat'], (float)$cur['summit_lng'], 0, !empty($cur['elev_m']) ? (float)$cur['elev_m'] : null];

    $dist_m = 0.0;
    for ($k = 1; $k < count($coords); $k++) $dist_m += bp_dist($coords[$k - 1][0], $coords[$k - 1][1], $coords[$k][0], $coords[$k][1]);
    // Climb and drop, ignoring GPS wobble under 3 m
    $up = 0.0; $down = 0.0; $ref_e = null; $with_ele = 0;
    foreach ($eles as $e) {
        if ($e === null) continue;
        $with_ele++;
        if ($ref_e === null) { $ref_e = $e; continue; }
        if ($e - $ref_e >= 3) { $up += $e - $ref_e; $ref_e = $e; }
        elseif ($ref_e - $e >= 3) { $down += $ref_e - $e; $ref_e = $e; }
    }
    if ($with_ele < count($eles) / 2) { $up = 0.0; $down = 0.0; }   // too little elevation data to trust
    $uses_tracks = false; $uses_bro = false;
    foreach ($path as $i) { if ($nodes[$i]['trk'] === 'brouter') $uses_bro = true; else $uses_tracks = true; }
    return ['ok' => true, 'coords' => $coords, 'distance_m' => $dist_m, 'ascend_m' => $up, 'descend_m' => $down,
            'cached' => false, 'method' => $uses_bro ? 'merged' : 'tracks', 'uses_tracks' => $uses_tracks];
}

/**
 * Asks the path finder for a walk from the edge of $prev's activation zone to $cur's summit and checks it is a
 * complete, sensible hike. Used by the page and by the save endpoint, so a "Hike direct" choice that cannot be
 * planned is refused instead of being saved. $prev / $cur need: ref, summit_lat, summit_lng, elev_m.
 * Returns ['ok' => true, 'route' => <brouter result>, 'from' => [lat, lon]] or ['ok' => false, 'error' => text].
 */
function multi_leg_route_pair(array $prev, array $cur, array $track_files = []) {
    if ($prev['ref'] === $cur['ref']) return ['ok' => false, 'error' => 'These two stops are the same summit.'];
    // Best case: one recorded track already walks from this summit to the next (a loop or traverse through both)
    if ($match = multi_leg_track_match($prev, $cur, $track_files)) {
        $route = ['ok' => true, 'coords' => multi_leg_track_coords($match['pts'], $match['a'], $match['b']),
                  'distance_m' => $match['mi'] * 1609.344, 'ascend_m' => $match['gain_ft'] / 3.28084,
                  'descend_m' => $match['loss_ft'] / 3.28084, 'cached' => false, 'method' => 'recorded',
                  'uses_tracks' => true, 'match' => $match];
        return ['ok' => true, 'route' => $route, 'from' => [(float)$prev['summit_lat'], (float)$prev['summit_lng']], 'method' => 'recorded'];
    }
    // Start where the previous summit's activation zone ends nearest the next summit, finish on the next summit
    $from = multi_leg_zone_exit($prev, $cur['summit_lat'], $cur['summit_lng']);
    $r = brouter_route_cached($from[0], $from[1], $cur['summit_lat'], $cur['summit_lng']);
    $why = null;   // set when BRouter has no usable answer
    if (!$r['ok']) {
        $why = multi_leg_friendly_error($r['error'] ?? 'unknown error');
    } else {
        // A route that begins or ends far from where we asked is a partial path: the path finder snapped to
        // the nearest trail it could find, which does not actually connect the two summits.
        $co = $r['coords'];
        $first = $co[0] ?? null; $last = $co ? $co[count($co) - 1] : null;
        $short_start = $first ? bp_dist($first[0], $first[1], $from[0], $from[1]) : 99999;
        $short_end   = $last  ? bp_dist($last[0], $last[1], $cur['summit_lat'], $cur['summit_lng']) : 99999;
        // 600 m allows for a trail that ends a short walk from an off-trail summit (Mount Lewis is about 480 m)
        // while still refusing real gaps (Jupiter Mountain to Bee Benchmark misses by about 1 km).
        $straight = bp_dist($prev['summit_lat'], $prev['summit_lng'], $cur['summit_lat'], $cur['summit_lng']);
        if ($short_start > 600 || $short_end > 600) {
            $worst = max($short_start, $short_end);
            $why = 'The closest the path finder could get is about ' . round($worst / 1609.344, 1) . ' mi from the '
                . ($short_start >= $short_end ? 'start' : 'end') . ' point.';
        } elseif ($straight > 0 && $r['distance_m'] > $straight * 2.5 && $r['distance_m'] - $straight > 1200) {
            // A "path" that wanders far more than the straight line is not a sensible hike
            $why = 'The only route found on the map is ' . round($r['distance_m'] / 1609.344, 1) . ' mi for a '
                . round($straight / 1609.344, 1) . ' mi gap, which is too indirect to count as a hike.';
        }
    }
    if ($why === null) {
        // BRouter's route starts at the edge of the activation zone, and the map joins that to the summit with a
        // straight line. When that hop is long and recorded tracks give a connected walk from the summit itself
        // that is no longer overall, the recorded tracks are the better answer: real trail the whole way.
        $hop = bp_dist($prev['summit_lat'], $prev['summit_lng'], $r['coords'][0][0], $r['coords'][0][1]);
        if ($track_files) {
            // Merge BRouter's route with the recorded tracks: wherever it meets or runs along a proven track, the
            // walk switches to that track (e.g. Summit A's own trail for a while, then a stretch BRouter found that
            // links up with Summit B's trail). Tried from the previous summit itself, then from the zone edge.
            foreach ([[(float)$prev['summit_lat'], (float)$prev['summit_lng']], $from] as $start) {
                $t = multi_leg_track_route($cur, $track_files, $start, $r['coords']);
                if ($t && $t['distance_m'] <= ($r['distance_m'] + $hop) * 1.15) {
                    $t['method'] = $t['uses_tracks'] ? 'merged' : 'brouter';
                    return ['ok' => true, 'route' => $t, 'from' => $from, 'method' => $t['method']];
                }
            }
        }
        return ['ok' => true, 'route' => $r, 'from' => $from, 'method' => 'brouter'];
    }

    // BRouter has no complete path: see whether recorded tracks already attached to the route get there
    if ($track_files) {
        // Start at the previous summit itself: its recorded track ends there, so the walk follows the real trail down
        // instead of a straight hop to the nearest one
        $t = multi_leg_track_route($cur, $track_files, [(float)$prev['summit_lat'], (float)$prev['summit_lng']]);
        if ($t) return ['ok' => true, 'route' => $t, 'from' => $from, 'method' => 'tracks'];
    }
    return ['ok' => false, 'error' => 'This hike could not be calculated. No complete walkable path was found on the map, and no recorded GPX tracks in this route connect the two summits. (' . $why . ')'];
}

/**
 * Turns the "hike direct" legs into on-foot chains using BRouter.
 *
 * @param array $stops      the stop list after multi_alt_apply() (modified in place)
 * @param array $want_hike  [i => true] for stops reached by an explicit "hike direct" choice
 *                          that no shared alternate start pin already covers
 * @param float $pace       dashboard pace multiplier
 * Adds to a stop that is reached this way: chained=true, alt=['method'=>'brouter', ...],
 * 'route' (thinned [[lat,lon],...] for the map), 'leg_dist_mi', 'leg_gain_ft'.
 * Adds to a stop where it failed: 'leg_error' (message). That stop is left as a car leg.
 * @param array $exits      [summit_id => true]: chain ends that walk on to their own starting point (multi_exit_load)
 * @param array $ends       [summit_id => 'back'|'loop']: explicit choice at a chain end (multi_end_load)
 */
function multi_leg_apply_brouter(array &$stops, array $want_hike, $pace, array $exits = [], array $ends = []) {
    $track_files = multi_leg_track_files($stops);   // recorded tracks, for when BRouter finds no complete path
    foreach ($want_hike as $i => $_) {
        if ($i < 1 || !isset($stops[$i]) || $stops[$i]['chained'] || $stops[$i]['is_drive_up']) continue;
        $prev = $stops[$i - 1];
        $cur  = $stops[$i];
        if ($prev['ref'] === $cur['ref']) { $stops[$i]['leg_error'] = 'These two stops are the same summit.'; continue; }

        $pair = multi_leg_route_pair($prev, $cur, $track_files);
        if (!$pair['ok']) { $stops[$i]['leg_error'] = $pair['error']; continue; }
        $r = $pair['route'];
        $mi = $r['distance_m'] / 1609.344;
        $gain_ft = $r['ascend_m'] * 3.28084;
        $stops[$i]['up_min'] = max(1, (int)calculateHikeTime($mi, $gain_ft, $pace));
        $stops[$i]['dist_up'] = $mi;
        $stops[$i]['elev_ft'] = $gain_ft;
        $stops[$i]['chained'] = true;
        $stops[$i]['alt'] = ['lat' => (float)$prev['summit_lat'], 'lng' => (float)$prev['summit_lng'], 'method' => 'brouter',
                             'off_m' => null, 'set_by' => null, 'mode' => 'brouter'];
        // Coordinates of merged / tracks-only / one-recorded-track walks carry [lat, lon, on a recorded track, ele]
        $has_tr = in_array($pair['method'] ?? '', ['tracks', 'merged', 'recorded'], true);
        if ($has_tr) $stops[$i]['alt']['method'] = $pair['method'];
        if (($pair['method'] ?? '') === 'recorded') {
            $tm = $r['match'];
            $stops[$i]['track_match'] = ['file' => $tm['file'], 'rev' => $tm['rev'], 'a' => $tm['a'], 'b' => $tm['b']];
        }
        $thin = bp_thin($r['coords'], 250);
        $stops[$i]['route'] = array_map(fn($c) => [round($c[0], 5), round($c[1], 5)], $thin);
        // Which points sit on a recorded track (drawn solid on the map)
        $stops[$i]['route_proven'] = $has_tr ? array_map(fn($c) => (int)($c[2] ?? 0), $thin) : null;
        // Elevation along the route (metres) for the elevation profile
        $stops[$i]['route_ele'] = array_map(fn($c) => $has_tr ? ($c[3] ?? null) : ($c[2] ?? null), $thin);
        $stops[$i]['leg_dist_mi'] = $mi;
        $stops[$i]['leg_gain_ft'] = $gain_ft;
        $stops[$i]['leg_descend_ft'] = $r['descend_m'] * 3.28084;   // the climb when this leg is walked in reverse
        $stops[$i]['leg_cached'] = !empty($r['cached']);
        $stops[$i - 1]['down_min'] = 0;
        $stops[$i - 1]['dist_down'] = $stops[$i - 1]['dist_down'] === null ? null : 0;
        $stops[$i - 1]['continues'] = true;
    }

    // The first summit of a walking chain whose next leg follows the first summit's OWN recorded track (a loop
    // through both summits): its way up is the part of that track before the summit, not the usual 60% share
    // of the whole recorded hike (which for a loop includes the other summits and the way back).
    foreach ($stops as $i => $s) {
        $tm = $s['track_match'] ?? null;
        if (!$tm || $i < 1) continue;
        $h = $i - 1;
        $head = $stops[$h];
        if (!empty($head['chained']) || $tm['file'] !== ($head['gpx_file'] ?? null)) continue;
        $pts = multi_leg_full_points($tm['file']);
        if ($tm['rev']) $pts = array_reverse($pts);
        // The walk must begin where the car is (a track walked backwards from a far end does not)
        if (!empty($head['has_trailhead']) && bp_dist($pts[0][0], $pts[0][1], (float)$head['lat'], (float)$head['lng']) > MULTI_LEG_LOOP_M) continue;
        [$mi, $g, $l] = multi_leg_span($pts, 0, $tm['a']);
        $stops[$h]['up_min'] = max(1, (int)calculateHikeTime($mi, $g, $pace));
        $stops[$h]['dist_up'] = $mi;
        $stops[$h]['elev_ft'] = $g;
        $stops[$h]['up_loss_ft'] = $l;   // the climb if this part is walked back down
        $stops[$h]['up_from_track'] = true;
        $stops[$h]['hike_min'] = $stops[$h]['up_min'] + $stops[$h]['down_min'];
        $stops[$h]['dist_mi'] = $mi + ($stops[$h]['dist_down'] ?? 0);
    }

    // The last summit of a walking chain (summits reached on foot, one after another):
    //   loop: every leg followed one recorded track, and that track carries on back to where the car is parked:
    //         finish it. This is the default whenever it is possible.
    //   back: walk back the way we came. Each leg is walked in reverse (same distance, the climb is the
    //         original descent), summit by summit, and then the FIRST summit's own descent to the start.
    //   new:  carry on down this summit's own trail to its own starting point, where a ride is waiting.
    foreach ($stops as $k => $s) {
        if (empty($s['chained']) || !empty($s['continues'])) continue;
        $j = $k;
        while ($j > 0 && !empty($stops[$j]['chained'])) $j--;
        $stops[$k]['chain_end'] = true;
        $stops[$k]['chain_head'] = $j;
        $loop = multi_leg_loop_finish($stops, $j, $k, $pace);
        if ($loop) $stops[$k]['loop_ok'] = ['min' => $loop['min'], 'mi' => $loop['mi']];
        if (!empty($exits[$s['id']])) {
            $stops[$k]['exit_new'] = true;
            $stops[$k]['down_min'] = (int)$s['own_down_min'];
            $stops[$k]['dist_down'] = $s['own_dist_down'];
            continue;
        }
        if (($s['alt']['mode'] ?? '') === 'return') continue;   // reached on the way down: nothing extra to walk
        if ($loop && ($ends[$s['id']] ?? '') !== 'back') {
            $stops[$k]['down_min'] = max(1, $loop['min']);
            $stops[$k]['dist_down'] = $loop['mi'];
            $stops[$k]['elev_ft'] = (float)($stops[$k]['elev_ft'] ?? 0) + $loop['gain_ft'];   // the loop's last stretch has real climbing too
            $stops[$k]['loop_finish'] = true;
            $thin = bp_thin($loop['coords'], 250);
            $stops[$k]['loop_route'] = array_map(fn($c) => [round($c[0], 5), round($c[1], 5)], $thin);
            $stops[$k]['loop_ele'] = array_map(fn($c) => $c[3] ?? null, $thin);
            continue;
        }
        $t = 0.0; $d = 0.0; $via = [];
        for ($m = $k; $m > $j; $m--) {
            $via[] = $m;   // 1-based number of the summit walked back through (stop index m-1)
            $leg = $stops[$m];
            if (($leg['alt']['mode'] ?? '') === 'return') continue;
            $mi = $leg['leg_dist_mi'] ?? ($leg['dist_up'] ?? 0);
            $t += calculateHikeTime($mi, $leg['leg_descend_ft'] ?? 0, $pace);
            $d += $mi;
        }
        $head = $stops[$j];
        if (!empty($head['up_from_track'])) {
            // Its way up came from the recorded track, so the way back down is that same stretch reversed
            $t += calculateHikeTime($head['dist_up'], $head['up_loss_ft'] ?? 0, $pace);
            $d += (float)$head['dist_up'];
        } else {
            $t += (int)$head['own_down_min'];
            $d += (float)($head['own_dist_down'] ?? 0);
        }
        $stops[$k]['down_min'] = max(1, (int)round($t));
        $stops[$k]['dist_down'] = $d;
        $stops[$k]['back_routed'] = true;
        $stops[$k]['back_via'] = $via;
    }

    foreach ($stops as $k => &$s) {
        $s['hike_min'] = $s['up_min'] + $s['down_min'];
        if ($s['dist_up'] !== null) $s['dist_mi'] = $s['dist_up'] + ($s['dist_down'] ?? 0);
        $s['leg_error'] = $s['leg_error'] ?? null;
    }
    unset($s);
}

/**
 * "Finish the loop" for the walking chain from stop $j (where the car is) to stop $k: possible when every leg of
 * the chain followed the same recorded track in the same direction, summit after summit, and that track ends
 * back where the car is parked. Returns null or ['mi', 'gain_ft', 'min', 'coords'] for the rest of the track.
 */
function multi_leg_loop_finish(array $stops, $j, $k, $pace) {
    $file = null; $rev = null; $last_b = -1;
    for ($m = $j + 1; $m <= $k; $m++) {
        $tm = $stops[$m]['track_match'] ?? null;
        if (!$tm) return null;
        if ($file === null) { $file = $tm['file']; $rev = $tm['rev']; }
        elseif ($tm['file'] !== $file || $tm['rev'] !== $rev || $tm['a'] < $last_b) return null;
        $last_b = $tm['b'];
    }
    if ($file === null) return null;
    $pts = multi_leg_full_points($file);
    if ($rev) $pts = array_reverse($pts);
    $n = count($pts);
    if ($last_b >= $n - 1) return null;
    // Where the car is: the first summit's starting point, or the start of the track when its way up came from it
    $head = $stops[$j];
    $cars = [];
    if (!empty($head['has_trailhead'])) $cars[] = [(float)$head['lat'], (float)$head['lng']];
    if (!empty($head['up_from_track'])) $cars[] = [$pts[0][0], $pts[0][1]];
    $home = false;
    foreach ($cars as $c) if (bp_dist($pts[$n - 1][0], $pts[$n - 1][1], $c[0], $c[1]) <= MULTI_LEG_LOOP_M) $home = true;
    if (!$home) return null;
    [$mi, $g] = multi_leg_span($pts, $last_b, $n - 1);
    return ['mi' => $mi, 'gain_ft' => $g, 'min' => (int)calculateHikeTime($mi, $g, $pace), 'coords' => multi_leg_track_coords($pts, $last_b, $n - 1)];
}

/**
 * Estimated trail from a stop's starting point up to its summit, for the map, used only when the stop has no
 * recorded GPX track. Cached like every other BRouter route. Returns [[lat, lon], ...] or null.
 */
function multi_leg_up_route(array $stop) {
    if (($stop['lat'] ?? null) === null || ($stop['lng'] ?? null) === null) return null;
    $r = brouter_route_cached((float)$stop['lat'], (float)$stop['lng'], $stop['summit_lat'], $stop['summit_lng']);
    if (!$r['ok'] || empty($r['coords'])) return null;
    $co = $r['coords'];
    $last = $co[count($co) - 1];
    if (bp_dist($last[0], $last[1], $stop['summit_lat'], $stop['summit_lng']) > 600) return null;   // never reaches the summit
    $straight = bp_dist((float)$stop['lat'], (float)$stop['lng'], $stop['summit_lat'], $stop['summit_lng']);
    if ($straight > 0 && $r['distance_m'] > $straight * 2.5 && $r['distance_m'] - $straight > 1200) return null;
    return array_map(fn($c) => [round($c[0], 5), round($c[1], 5)], bp_thin($co, 250));
}

/** BRouter error text turned into something a hiker can read. */
function multi_leg_friendly_error($msg) {
    $m = strtolower((string)$msg);
    if (strpos($m, 'not mapped') !== false || strpos($m, 'no track found') !== false || strpos($m, 'cannot') !== false
        || strpos($m, 'not found') !== false || strpos($m, 'no route') !== false) {
        return 'No walkable path found between these summits.';
    }
    if (strpos($m, 'not set') !== false || strpos($m, 'could not reach') !== false || strpos($m, 'timed out') !== false) {
        return 'The path finder is not answering right now.';
    }
    return 'No walkable path found (' . trim((string)$msg) . ').';
}
