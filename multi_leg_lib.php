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
// route between the two summits. If BRouter cannot find one, the leg falls back to the car
// and the tile shows the reason, so a route is never silently planned over a gap.

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
 */
function multi_leg_apply_brouter(array &$stops, array $want_hike, $pace, array $exits = []) {
    foreach ($want_hike as $i => $_) {
        if ($i < 1 || !isset($stops[$i]) || $stops[$i]['chained'] || $stops[$i]['is_drive_up']) continue;
        $prev = $stops[$i - 1];
        $cur  = $stops[$i];
        if ($prev['ref'] === $cur['ref']) { $stops[$i]['leg_error'] = 'These two stops are the same summit.'; continue; }

        // Start where the previous summit's activation zone ends nearest the next summit, finish on the next summit
        $from = multi_leg_zone_exit($prev, $cur['summit_lat'], $cur['summit_lng']);
        $r = brouter_route_cached($from[0], $from[1], $cur['summit_lat'], $cur['summit_lng']);
        if (!$r['ok']) {
            $stops[$i]['leg_error'] = multi_leg_friendly_error($r['error'] ?? 'unknown error');
            continue;
        }
        // A "path" that wanders far more than the straight line is not a sensible hike
        $straight = bp_dist($prev['summit_lat'], $prev['summit_lng'], $cur['summit_lat'], $cur['summit_lng']);
        if ($straight > 0 && $r['distance_m'] > $straight * 2.5 && $r['distance_m'] - $straight > 1200) {
            $stops[$i]['leg_error'] = 'The only route found is ' . round($r['distance_m'] / 1609.344, 1) . ' mi for a '
                . round($straight / 1609.344, 1) . ' mi gap, which is too indirect to count as a hike.';
            continue;
        }
        $mi = $r['distance_m'] / 1609.344;
        $gain_ft = $r['ascend_m'] * 3.28084;
        $stops[$i]['up_min'] = max(1, (int)calculateHikeTime($mi, $gain_ft, $pace));
        $stops[$i]['dist_up'] = $mi;
        $stops[$i]['elev_ft'] = $gain_ft;
        $stops[$i]['chained'] = true;
        $stops[$i]['alt'] = ['lat' => (float)$prev['summit_lat'], 'lng' => (float)$prev['summit_lng'], 'method' => 'brouter',
                             'off_m' => null, 'set_by' => null, 'mode' => 'brouter'];
        $stops[$i]['route'] = array_map(fn($c) => [round($c[0], 5), round($c[1], 5)], bp_thin($r['coords'], 250));
        $stops[$i]['leg_dist_mi'] = $mi;
        $stops[$i]['leg_gain_ft'] = $gain_ft;
        $stops[$i]['leg_descend_ft'] = $r['descend_m'] * 3.28084;   // the climb when this leg is walked in reverse
        $stops[$i]['leg_cached'] = !empty($r['cached']);
        $stops[$i - 1]['down_min'] = 0;
        $stops[$i - 1]['dist_down'] = $stops[$i - 1]['dist_down'] === null ? null : 0;
        $stops[$i - 1]['continues'] = true;
    }

    // The last summit of a walking chain (summits reached on foot, one after another):
    //   back: walk back the way we came. Each leg is walked in reverse (same distance, the climb is the
    //         original descent), summit by summit, and then the FIRST summit's own descent to the start.
    //   new:  carry on down this summit's own trail to its own starting point, where a ride is waiting.
    foreach ($stops as $k => $s) {
        if (empty($s['chained']) || !empty($s['continues'])) continue;
        $j = $k;
        while ($j > 0 && !empty($stops[$j]['chained'])) $j--;
        $stops[$k]['chain_end'] = true;
        $stops[$k]['chain_head'] = $j;
        if (!empty($exits[$s['id']])) {
            $stops[$k]['exit_new'] = true;
            $stops[$k]['down_min'] = (int)$s['own_down_min'];
            $stops[$k]['dist_down'] = $s['own_dist_down'];
            continue;
        }
        if (($s['alt']['mode'] ?? '') === 'return') continue;   // reached on the way down: nothing extra to walk
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
        $t += (int)$head['own_down_min'];
        $d += (float)($head['own_dist_down'] ?? 0);
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
