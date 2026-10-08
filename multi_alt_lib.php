<?php
// Multi-Activate "alternate hike start" support, shared by multi_activate.php,
// multi_activate_pdf.php and multi_alt_start_save.php.
//
// An alternate start belongs to an ORDERED PAIR of summits ("#2 when it comes
// right after #1"), never to a single summit, so it can't disturb anyone's solo
// trailhead data. It means: after #1 the hiker keeps going on foot to #2 instead
// of returning to the car. The ripple effect:
//   - #2: no drive (the car stays put); its hike up starts at the pin, not the trailhead
//   - #1: its hike down is dropped (the hiker continues instead of descending)
//   - the last stop of a chain still descends fully back to the car
//
// Pins are shared ("community"): anyone who builds the same pair in the same
// order gets the same start. A user can opt out for themselves, and the person
// who placed a pin can delete it outright. Every change is logged.

require_once __DIR__ . '/sota_cache_helper.php'; // haversine_miles()

const MULTI_ALT_MAX_TRACK_OFFSET_M = 200;   // pin farther than this from the GPX track: use the straight-line estimate
const MULTI_ALT_MAX_FROM_SUMMIT_MI = 15;    // sanity limit when saving a pin
const MULTI_ALT_DETECT_RADIUS_M = 200;      // previous summit this close to the next summit's trail counts as "on the trail" (summit coordinates are rarely exactly on the path)
const MULTI_ALT_TRAIL_FACTOR = 1.3;         // straight line to trail distance, for the no-GPX estimate

// ── GPX points with elevation: [[lat, lon, ele_m], ...] ──────────────────────
function multi_gpx_points($path) {
    static $cache = [];
    if (!$path || !file_exists($path)) return null;
    if (array_key_exists($path, $cache)) return $cache[$path];
    $cache[$path] = null;
    $xml = @simplexml_load_string((string)@file_get_contents($path));
    if (!$xml) return null;
    $nodes = $xml->xpath("//*[local-name()='trkpt']");
    if (!$nodes || count($nodes) < 2) $nodes = $xml->xpath("//*[local-name()='rtept']");
    if (!$nodes || count($nodes) < 2) return null;
    $pts = [];
    foreach ($nodes as $n) {
        $pts[] = [(float)$n['lat'], (float)$n['lon'], (float)$n->ele];
    }
    return $cache[$path] = $pts;
}

// Shortest distance in meters from a point to the line segment between track points $a and $b
// ([lat, lon, ...]), and how far along the segment (0..1) the closest spot is.
function multi_point_segment_m($plat, $plng, $a, $b) {
    $kx = cos(deg2rad($plat)) * 111320; $ky = 110540;
    $ax = ($a[1] - $plng) * $kx; $ay = ($a[0] - $plat) * $ky;
    $bx = ($b[1] - $plng) * $kx; $by = ($b[0] - $plat) * $ky;
    $dx = $bx - $ax; $dy = $by - $ay;
    $len2 = $dx * $dx + $dy * $dy;
    $t = $len2 > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $len2)) : 0;
    $cx = $ax + $t * $dx; $cy = $ay + $t * $dy;
    return [sqrt($cx * $cx + $cy * $cy), $t];
}

// Distance (miles) and elevation gain (feet) walking along $pts from index $a to $b
function multi_track_span($pts, $a, $b) {
    $dist = 0.0; $gain = 0.0;
    for ($k = $a + 1; $k <= $b; $k++) {
        $dist += haversine_miles($pts[$k - 1][0], $pts[$k - 1][1], $pts[$k][0], $pts[$k][1]);
        $de = $pts[$k][2] - $pts[$k - 1][2];
        if ($de > 0) $gain += $de * 3.28084;
    }
    return [$dist, $gain];
}

// ── Database access ──────────────────────────────────────────────────────────

// Looks up the pin (if any) for each position i >= 1 in $stops.
// Returns ['active' => [i => row], 'hidden' => [i => row]]: "hidden" means a
// shared pin exists but this user opted out of it.
function multi_alt_lookup(PDO $db, array $stops, $callsign) {
    $out = ['active' => [], 'hidden' => [], 'optout' => []];
    $pairs = [];
    for ($i = 1; $i < count($stops); $i++) {
        $f = $stops[$i - 1]['ref']; $t = $stops[$i]['ref'];
        if ($f === $t || $stops[$i]['is_drive_up']) continue;
        $pairs[$i] = [$f, $t];
    }
    if (!$pairs) return $out;

    $where = []; $params = [];
    foreach ($pairs as [$f, $t]) { $where[] = '(from_ref = ? AND to_ref = ?)'; array_push($params, $f, $t); }
    $stmt = $db->prepare("SELECT * FROM multi_alt_starts WHERE " . implode(' OR ', $where));
    $stmt->execute($params);
    $found = [];
    foreach ($stmt->fetchAll() as $r) $found[$r['from_ref'] . '>' . $r['to_ref']] = $r;

    $optouts = [];
    if ($callsign) {
        $stmt = $db->prepare("SELECT from_ref, to_ref FROM multi_alt_start_optouts WHERE callsign = ?");
        $stmt->execute([$callsign]);
        foreach ($stmt->fetchAll() as $r) $optouts[$r['from_ref'] . '>' . $r['to_ref']] = true;
    }

    foreach ($pairs as $i => [$f, $t]) {
        $k = $f . '>' . $t;
        // Opted out of this pair (hid the shared pin, or dismissed the suggestion)
        if (isset($optouts[$k])) $out['optout'][$i] = true;
        if (!isset($found[$k])) continue;
        if (isset($optouts[$k])) $out['hidden'][$i] = $found[$k];
        else $out['active'][$i] = $found[$k];
    }
    return $out;
}

function multi_alt_log(PDO $db, $from, $to, $action, $lat, $lng, $by) {
    $db->prepare("INSERT INTO multi_alt_start_log (from_ref, to_ref, action, lat, lng, by_callsign) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$from, $to, $action, $lat, $lng, $by]);
}

function multi_alt_set(PDO $db, $from, $to, $lat, $lng, $by) {
    $db->prepare("
        INSERT INTO multi_alt_starts (from_ref, to_ref, lat, lng, set_by) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), set_by = VALUES(set_by)
    ")->execute([$from, $to, $lat, $lng, $by]);
    $db->prepare("DELETE FROM multi_alt_start_optouts WHERE callsign = ? AND from_ref = ? AND to_ref = ?")->execute([$by, $from, $to]);
    multi_alt_log($db, $from, $to, 'set', $lat, $lng, $by);
}

// The person who placed the shared pin removes it for everyone; anyone else
// just opts themselves out of it.
function multi_alt_reset(PDO $db, $from, $to, $by) {
    $stmt = $db->prepare("SELECT set_by FROM multi_alt_starts WHERE from_ref = ? AND to_ref = ?");
    $stmt->execute([$from, $to]);
    $row = $stmt->fetch();
    if (!$row) return 'none';
    if (strcasecmp($row['set_by'], $by) === 0) {
        $db->prepare("DELETE FROM multi_alt_starts WHERE from_ref = ? AND to_ref = ?")->execute([$from, $to]);
        multi_alt_log($db, $from, $to, 'delete', null, null, $by);
        return 'deleted';
    }
    $db->prepare("INSERT IGNORE INTO multi_alt_start_optouts (callsign, from_ref, to_ref) VALUES (?, ?, ?)")->execute([$by, $from, $to]);
    multi_alt_log($db, $from, $to, 'optout', null, null, $by);
    return 'optout';
}

// Dismissing the auto-detected suggestion is stored as an opt-out for the pair.
function multi_alt_dismiss(PDO $db, $from, $to, $by) {
    $db->prepare("INSERT IGNORE INTO multi_alt_start_optouts (callsign, from_ref, to_ref) VALUES (?, ?, ?)")->execute([$by, $from, $to]);
    multi_alt_log($db, $from, $to, 'dismiss', null, null, $by);
}

function multi_alt_restore(PDO $db, $from, $to, $by) {
    $db->prepare("DELETE FROM multi_alt_start_optouts WHERE callsign = ? AND from_ref = ? AND to_ref = ?")->execute([$by, $from, $to]);
    multi_alt_log($db, $from, $to, 'restore', null, null, $by);
}

// ── Hike math ────────────────────────────────────────────────────────────────

// Splits each stop's round-trip hike into an up part and a down part (the same
// 60/40 split the timeline has always used), then applies any alternate starts.
// Adds to every stop: up_min, down_min, dist_up, dist_down, chained, alt.
// Rewrites hike_min / dist_mi / elev_ft to match, so everything downstream
// (tiles, totals, saved summary) automatically sees the adjusted numbers.
// $stops[i]['gpx_file'] is the full path of the stop's GPX file, if any.
function multi_alt_apply(array &$stops, array $alts, $pace) {
    foreach ($stops as $i => &$s) {
        $s['up_min']   = (int)round($s['hike_min'] * 0.6);
        $s['down_min'] = (int)$s['hike_min'] - $s['up_min'];
        $s['dist_up']  = is_numeric($s['dist_mi']) ? $s['dist_mi'] / 2 : null;
        $s['dist_down'] = $s['dist_up'];
        $s['chained'] = false;
        $s['alt'] = null;
    }
    unset($s);

    foreach ($alts as $i => $pin) {
        if ($i < 1 || !isset($stops[$i]) || $stops[$i]['is_drive_up']) continue;
        $s = $stops[$i];
        $plat = (float)$pin['lat']; $plng = (float)$pin['lng'];

        // A pin right at this stop's own summit means it is reached on the way DOWN
        // from the previous summit (this summit sits on that summit's trail): no hike
        // up for this stop, and the previous stop only descends as far as this summit.
        if (haversine_miles($plat, $plng, $s['summit_lat'], $s['summit_lng']) * 1609.344 <= MULTI_ALT_DETECT_RADIUS_M) {
            $prev = $stops[$i - 1];
            $frac = null; $method = 'estimate';
            $ppts = multi_gpx_points($prev['gpx_file'] ?? null);
            if ($ppts) {
                if (($prev['track_type'] ?? 'round-trip') === 'descent') $ppts = array_reverse($ppts);
                $hit = multi_track_hit($ppts, [$prev['summit_lat'], $prev['summit_lng']], $s['summit_lat'], $s['summit_lng'], false);
                if ($hit) {
                    [$full_d] = multi_track_span($ppts, 0, $hit['idx_s']);
                    [$part_d] = multi_track_span($ppts, $hit['idx_p'], $hit['idx_s']);
                    if ($full_d > 0) { $frac = $part_d / $full_d; $method = 'track'; }
                }
            }
            if ($frac === null) {
                $d_ps = haversine_miles($prev['summit_lat'], $prev['summit_lng'], $s['summit_lat'], $s['summit_lng']);
                $d_th = ($prev['has_trailhead'] ?? false) ? haversine_miles($prev['lat'], $prev['lng'], $prev['summit_lat'], $prev['summit_lng']) : 0;
                $frac = $d_th > 0.05 ? min(1, $d_ps / $d_th) : 0.5;
            }
            $stops[$i]['up_min'] = 0;
            $stops[$i]['dist_up'] = $stops[$i]['dist_up'] === null ? null : 0;
            $stops[$i]['elev_ft'] = $stops[$i]['elev_ft'] === null ? null : 0;
            $stops[$i]['chained'] = true;
            $stops[$i]['alt'] = ['lat' => $plat, 'lng' => $plng, 'method' => $method, 'off_m' => null, 'set_by' => $pin['set_by'] ?? null, 'mode' => 'return'];
            $stops[$i - 1]['down_min'] = (int)round($prev['down_min'] * $frac);
            $stops[$i - 1]['dist_down'] = $prev['dist_down'] === null ? null : $prev['dist_down'] * $frac;
            $stops[$i - 1]['descends_to_next'] = true;
            continue;
        }
        $up = null; $dist_up = $s['dist_up']; $elev = $s['elev_ft']; $method = null; $off_m = null;

        // Preferred: measure along the stop's own GPX track, scaled to its recorded pace
        $pts = multi_gpx_points($s['gpx_file'] ?? null);
        if ($pts) {
            if (($s['track_type'] ?? 'round-trip') === 'descent') $pts = array_reverse($pts);
            $idx_s = 0; $best = INF;
            foreach ($pts as $k => $p) {
                $d = haversine_miles($p[0], $p[1], $s['summit_lat'], $s['summit_lng']);
                if ($d < $best) { $best = $d; $idx_s = $k; }
            }
            $idx_p = 0; $best_p = INF;
            for ($k = 0; $k <= $idx_s; $k++) {
                $d = haversine_miles($pts[$k][0], $pts[$k][1], $plat, $plng);
                if ($d < $best_p) { $best_p = $d; $idx_p = $k; }
            }
            $off_m = $best_p * 1609.344;
            if ($idx_s > 0 && $off_m <= MULTI_ALT_MAX_TRACK_OFFSET_M) {
                [$fd, $fg] = multi_track_span($pts, 0, $idx_s);
                [$pd, $pg] = multi_track_span($pts, $idx_p, $idx_s);
                $full_t = calculateHikeTime($fd, $fg, 1.0);
                $part_t = calculateHikeTime($pd, $pg, 1.0);
                $ratio = $full_t > 0 ? $part_t / $full_t : 0;
                $up = (int)round($s['up_min'] * $ratio);
                $dist_up = $pd; $elev = $pg; $method = 'track';
            }
        }

        // Fallback: straight-line estimate (flagged as an estimate on the tile)
        if ($method === null) {
            $d_pin = haversine_miles($plat, $plng, $s['summit_lat'], $s['summit_lng']);
            $d_th  = ($s['has_trailhead'] ?? false) ? haversine_miles($s['lat'], $s['lng'], $s['summit_lat'], $s['summit_lng']) : 0;
            if ($s['hike_min'] > 0 && $d_th > 0.05) {
                $ratio = min(1.5, $d_pin / $d_th);
                $up = (int)round($s['up_min'] * $ratio);
                if ($s['dist_up'] !== null) $dist_up = $s['dist_up'] * $ratio;
                if ($s['elev_ft'] !== null) $elev = $s['elev_ft'] * $ratio;
            } else {
                $dist_up = $d_pin * MULTI_ALT_TRAIL_FACTOR;
                $up = (int)calculateHikeTime($dist_up, 0, $pace);
            }
            $method = 'estimate';
        }

        $stops[$i]['up_min'] = max(0, $up);
        $stops[$i]['dist_up'] = $dist_up;
        $stops[$i]['elev_ft'] = $elev;
        $stops[$i]['chained'] = true;
        $stops[$i]['alt'] = [
            'lat' => $plat, 'lng' => $plng, 'method' => $method, 'off_m' => $off_m,
            'set_by' => $pin['set_by'] ?? null, 'mode' => 'forward',
        ];
        // The hiker continues on foot from the previous summit instead of descending
        $stops[$i - 1]['down_min'] = 0;
        $stops[$i - 1]['dist_down'] = $stops[$i - 1]['dist_down'] === null ? null : 0;
        $stops[$i - 1]['continues'] = true;
    }

    foreach ($stops as $i => &$s) {
        $s['hike_min'] = $s['up_min'] + $s['down_min'];
        if ($s['dist_up'] !== null) $s['dist_mi'] = $s['dist_up'] + ($s['dist_down'] ?? 0);
        $s['continues'] = $s['continues'] ?? false;
        $s['descends_to_next'] = $s['descends_to_next'] ?? false;
    }
    unset($s);
}


// ── Auto-detect: do two adjacent summits share a trail? ──────────────────────
// $pts is a track already oriented trailhead to summit; $owner is that track's own
// summit [lat, lng]. Finds where ($lat, $lng) comes closest to the way up, measured
// to the line between points. Returns null unless within MULTI_ALT_DETECT_RADIUS_M
// and (when $partway) genuinely partway along, not at the trailhead or at $owner.
// Otherwise ['idx_s', 'idx_p', 'off_m', 'near' => [lat, lng]].
function multi_track_hit(array $pts, array $owner, $lat, $lng, $partway = true) {
    $idx_s = 0; $best = INF;
    foreach ($pts as $k => $p) {
        $d = haversine_miles($p[0], $p[1], $owner[0], $owner[1]);
        if ($d < $best) { $best = $d; $idx_s = $k; }
    }
    if ($idx_s < 2) return null;

    $off_m = INF; $idx_p = 0; $near = null;
    for ($k = 1; $k <= $idx_s; $k++) {
        [$d, $t] = multi_point_segment_m($lat, $lng, $pts[$k - 1], $pts[$k]);
        if ($d < $off_m) {
            $off_m = $d; $idx_p = $t < 0.5 ? $k - 1 : $k;
            $near = [$pts[$k - 1][0] + $t * ($pts[$k][0] - $pts[$k - 1][0]), $pts[$k - 1][1] + $t * ($pts[$k][1] - $pts[$k - 1][1])];
        }
    }
    if ($near === null || $off_m > MULTI_ALT_DETECT_RADIUS_M) return null;

    if ($partway) {
        [$total] = multi_track_span($pts, 0, $idx_s);
        [$before] = multi_track_span($pts, 0, $idx_p);
        if ($total <= 0 || $before / $total < 0.08 || $before / $total > 0.97) return null;
    }
    return ['idx_s' => $idx_s, 'idx_p' => $idx_p, 'off_m' => $off_m, 'near' => $near];
}

// A stop's track oriented trailhead to summit, or null
function multi_stop_ascent_points(array $stop) {
    $pts = multi_gpx_points($stop['gpx_file'] ?? null);
    if (!$pts) return null;
    return (($stop['track_type'] ?? 'round-trip') === 'descent') ? array_reverse($pts) : $pts;
}

// For each adjacent pair (i-1, i) not in $skip, suggests an alternate start when
// the two summits share a trail, in either direction:
//   forward: the previous summit is on this stop's trail up  ("hike on from it")
//   return:  this stop's summit is on the previous summit's trail ("stop here on the way down")
// Returns [i => ['mode', 'lat', 'lng', 'off_m', 'prev_name', 'name']].
function multi_alt_detect(array $stops, array $skip) {
    $found = [];
    for ($i = 1; $i < count($stops); $i++) {
        if (isset($skip[$i])) continue;
        $prev = $stops[$i - 1]; $cur = $stops[$i];
        if ($cur['is_drive_up'] || $prev['ref'] === $cur['ref']) continue;

        $hit = null; $mode = null;
        $cpts = multi_stop_ascent_points($cur);
        if ($cpts) {
            $hit = multi_track_hit($cpts, [$cur['summit_lat'], $cur['summit_lng']], $prev['summit_lat'], $prev['summit_lng']);
            if ($hit) $mode = 'forward';
        }
        if (!$hit && !$prev['is_drive_up']) {
            $ppts = multi_stop_ascent_points($prev);
            if ($ppts) {
                $hit = multi_track_hit($ppts, [$prev['summit_lat'], $prev['summit_lng']], $cur['summit_lat'], $cur['summit_lng']);
                if ($hit) $mode = 'return';
            }
        }
        if (!$hit) continue;

        $found[$i] = [
            'mode' => $mode,
            'lat' => round($hit['near'][0], 7), 'lng' => round($hit['near'][1], 7),
            'off_m' => round($hit['off_m']), 'prev_name' => $prev['name'], 'name' => $cur['name'],
        ];
    }
    return $found;
}
