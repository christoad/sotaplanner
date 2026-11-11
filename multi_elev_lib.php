<?php
/**
 * Elevation profiles for Multi-Activate (page and PDF).
 *
 * One profile per hiking trip: the walk from a starting point, through any summits reached on foot, and back
 * down. Each leg of the trip is its own colored segment (same colors as the map's hike lines). Elevations come
 * from the recorded GPX track where one is used, and from BRouter's route (which carries elevation) where the
 * walk is estimated. Needs brouter_paths_lib.php (bp_dist, bp_thin) and multi_leg_lib.php.
 */

const MULTI_HIKE_COLORS = ['#6E8155', '#4F8085', '#9A7B3F', '#7A5E8A', '#8F5B50', '#3F6B52', '#7C7A3A', '#8A5F72', '#5C7F96', '#A0805A', '#4F7A66', '#85704F'];

/** Fills missing elevations from neighbours. Input [[lat, lon, ele|null], ...]; returns [] when under half have data. */
function multi_elev_fill(array $pts) {
    $n = count($pts);
    if ($n < 2) return [];
    $have = 0;
    foreach ($pts as $p) if (($p[2] ?? null) !== null) $have++;
    if ($have < max(2, $n / 2)) return [];
    $last = null;
    foreach ($pts as $i => $p) {                       // forward fill
        if (($p[2] ?? null) !== null) $last = $p[2]; elseif ($last !== null) $pts[$i][2] = $last;
    }
    $next = null;
    for ($i = $n - 1; $i >= 0; $i--) {                 // back fill the leading gap
        if (($pts[$i][2] ?? null) !== null) $next = $pts[$i][2]; elseif ($next !== null) $pts[$i][2] = $next;
    }
    return $pts;
}

/** Points to [[dist_m, ele_m], ...] with distance counted from $d0, lightly smoothed. */
function multi_elev_series(array $pts, $d0 = 0.0) {
    $pts = multi_elev_fill($pts);
    if (count($pts) < 2) return [];
    $out = []; $d = $d0;
    foreach ($pts as $i => $p) {
        if ($i > 0) $d += bp_dist($pts[$i - 1][0], $pts[$i - 1][1], $p[0], $p[1]);
        $out[] = [$d, (float)$p[2]];
    }
    // 3-point smoothing takes the GPS wobble out without moving the summits much
    $sm = $out;
    for ($i = 1; $i < count($out) - 1; $i++) $sm[$i][1] = ($out[$i - 1][1] + $out[$i][1] * 2 + $out[$i + 1][1]) / 4;
    return bp_thin($sm, 160);
}

/** A stop's own recorded track as [[lat, lon, ele], ...] from its start to the point nearest the summit. */
function multi_elev_own_up(array $stop) {
    $f = $stop['gpx_file'] ?? null;
    if ($f && is_readable($f)) {
        $pts = multi_leg_read_gpx($f);
        if (count($pts) >= 2) {
            $bi = 0; $bd = INF;
            foreach ($pts as $i => $p) {
                $d = bp_dist($p[0], $p[1], $stop['summit_lat'], $stop['summit_lng']);
                if ($d < $bd) { $bd = $d; $bi = $i; }
            }
            // A track often goes up and comes back down; keep the way up
            return array_slice($pts, 0, $bi + 1);
        }
    }
    // No recorded track: the estimated trail from the starting point, when there is one
    if (!empty($stop['has_trailhead']) && ($stop['lat'] ?? null) !== null) {
        $r = brouter_route_cached((float)$stop['lat'], (float)$stop['lng'], $stop['summit_lat'], $stop['summit_lng']);
        if (!empty($r['ok']) && !empty($r['coords'])) {
            $co = $r['coords']; $last = $co[count($co) - 1];
            if (bp_dist($last[0], $last[1], $stop['summit_lat'], $stop['summit_lng']) <= 600) return $co;
        }
    }
    return [];
}

/** The walk to a stop reached on foot: previous summit, then the route (route_ele carries the elevations). */
function multi_elev_direct(array $stops, $i) {
    $s = $stops[$i]; $prev = $stops[$i - 1] ?? null;
    if (!$prev || empty($s['route'])) return [];
    $pts = [[$prev['summit_lat'], $prev['summit_lng'], $prev['elev_m'] ?: null]];
    $ele = $s['route_ele'] ?? [];
    foreach ($s['route'] as $k => $c) $pts[] = [$c[0], $c[1], $ele[$k] ?? null];
    return $pts;
}

/**
 * Profiles for a route. Returns a list of trips:
 *   ['title' => ..., 'segments' => [['label', 'color', 'kind', 'est' => bool, 'start_m', 'pts' => [[dist_m, ele_m], ...],
 *                                    'summit' => ['n' => 2, 'name' => ..] | null], ...], 'total_m' => ...]
 * Distances and elevations are metric; callers convert for display.
 */
function multi_elev_trips(array $stops) {
    $trips = []; $cur = null; $ci = 0;
    $close = function () use (&$trips, &$cur) {
        if ($cur && $cur['segments']) {
            $cur['total_m'] = end($cur['segments'])['end_m'];
            $trips[] = $cur;
        }
        $cur = null;
    };
    // One color per hike line, in the same order as the map's hike lines
    $next_color = function () use (&$ci) { return MULTI_HIKE_COLORS[$ci++ % count(MULTI_HIKE_COLORS)]; };
    $add = function ($label, $kind, $pts, $est, $summit_idx, $color) use (&$cur) {
        if (!$pts) return;
        $d0 = $cur ? end($cur['segments'])['end_m'] : 0.0;
        if (!$cur) $cur = ['title' => '', 'segments' => []];
        $ser = multi_elev_series($pts, $d0);
        if (count($ser) < 2) return;
        $cur['segments'][] = ['label' => $label, 'color' => $color, 'kind' => $kind, 'est' => $est, 'start_m' => $ser[0][0],
                              'end_m' => end($ser)[0], 'pts' => $ser, 'summit' => $summit_idx];
    };

    foreach ($stops as $i => $s) {
        $n = $i + 1;
        if (!empty($s['is_drive_up']) && ($s['up_min'] ?? 0) <= 0 && ($s['down_min'] ?? 0) <= 0) continue;
        if (($s['up_min'] ?? 0) > 0) {
            $col = $next_color();
            if (!empty($s['chained'])) {
                $m = $s['alt']['method'] ?? 'brouter';
                $add("Hike to #$n", 'direct', multi_elev_direct($stops, $i), $m !== 'tracks', $i, $col);
            } else {
                $close();
                $add("Hike up #$n", 'up', multi_elev_own_up($s), empty($s['gpx_file']), $i, $col);
            }
        }
        if (($s['down_min'] ?? 0) > 0) {
            $col = $next_color();
            if (!empty($s['back_routed']) && ($s['chain_head'] ?? null) !== null) {
                // Track back: each leg in reverse, then the first summit's own way down
                for ($m = $i; $m > $s['chain_head']; $m--) {
                    $pts = array_reverse(multi_elev_direct($stops, $m));
                    $add('Track back from #' . ($m + 1), 'back', $pts, ($stops[$m]['alt']['method'] ?? '') !== 'tracks', null, $col);
                }
                $head = $stops[$s['chain_head']];
                $add('Down from #' . ($s['chain_head'] + 1), 'down', array_reverse(multi_elev_own_up($head)), empty($head['gpx_file']), null, $col);
            } else {
                $add(!empty($s['exit_new']) ? "Down to #$n trailhead" : "Hike down #$n", 'down', array_reverse(multi_elev_own_up($s)), empty($s['gpx_file']), null, $col);
            }
            $close();
        }
    }
    $close();
    foreach ($trips as $k => &$t) {
        $first = $t['segments'][0]['label'];
        $t['title'] = count($trips) > 1 ? 'Hike ' . ($k + 1) . ': ' . $first : 'Hiking profile';
    }
    return $trips;
}
