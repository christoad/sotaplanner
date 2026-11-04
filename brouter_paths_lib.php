<?php
/**
 * BRouter path tools, built on brouter_lib.php.
 *
 *  1. brouter_route_cached()   Permanent cache around brouter_route() for two-point routes.
 *  2. brouter_route_summits()  Route between two SOTA summits, with hike time each way.
 *  3. bp_discover()            Find mapped footpaths inside a summit's activation zone and
 *                              follow each one outwards until it reaches a road, a trailhead
 *                              or a parking lot. Each distinct way out becomes a "candidate"
 *                              saved in brouter_path_candidates for manual review.
 *  4. bp_refine_candidate()    Ask BRouter for the real route (distance, climb, drop) of one saved candidate.
 *
 * Tables come from db_migrate.php (brouter_route_cache, brouter_path_candidates).
 * Discovery reads OpenStreetMap through Overpass (one query per summit, results are saved, so
 * it is never repeated unless asked). Not for any SOTA API; summit data comes from our own cache.
 */

require_once __DIR__ . '/brouter_lib.php';
require_once __DIR__ . '/sota_cache_helper.php';

/* ───────────────────────── small geometry helpers ───────────────────────── */

/** Fast distance in metres (equirectangular; plenty accurate under 20 km). */
function bp_dist($lat1, $lon1, $lat2, $lon2) {
    $x = deg2rad($lon2 - $lon1) * cos(deg2rad(($lat1 + $lat2) / 2));
    $y = deg2rad($lat2 - $lat1);
    return 6371000 * sqrt($x * $x + $y * $y);
}

function bp_key($lat, $lon) {
    return sprintf('%.4f,%.4f', $lat, $lon);   // about 11 m grid
}

/** Keep at most $max points, always including the first and last. */
function bp_thin(array $pts, $max = 500) {
    $n = count($pts);
    if ($n <= $max) return $pts;
    $out = [];
    $step = ($n - 1) / ($max - 1);
    for ($i = 0; $i < $max; $i++) $out[] = $pts[(int)round($i * $step)];
    return $out;
}

/** Ray casting. $ring is [[lon, lat], ...] (GeoJSON order). */
function bp_in_ring($lat, $lon, array $ring) {
    $in = false;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $ring[$i][0]; $yi = $ring[$i][1];
        $xj = $ring[$j][0]; $yj = $ring[$j][1];
        if ((($yi > $lat) !== ($yj > $lat)) && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
            $in = !$in;
        }
    }
    return $in;
}

/** Normalise any GeoJSON coordinates shape (ring, polygon, multipolygon) to a list of outer rings. */
function bp_rings($coords) {
    if (!is_array($coords) || !$coords) return [];
    $d = $coords;
    $depth = 0;
    while (is_array($d) && $d && !is_numeric($d[array_key_first($d)])) { $d = $d[array_key_first($d)]; $depth++; }
    // depth 1 = ring of points, 2 = polygon (rings), 3 = multipolygon
    if ($depth === 1) return [$coords];
    if ($depth === 2) return [$coords[0]];
    if ($depth === 3) { $out = []; foreach ($coords as $poly) $out[] = $poly[0]; return $out; }
    return [];
}

/* ─────────────────────── 1 & 2. cached routes between points ─────────────────────── */

/**
 * Two-point route with a permanent cache. Same return shape as brouter_route(), plus 'cached'.
 */
function brouter_route_cached($lat1, $lon1, $lat2, $lon2, $profile = 'hiking-mountain') {
    $db = getDbConnection();
    $fk = bp_key($lat1, $lon1);
    $tk = bp_key($lat2, $lon2);
    try {
        $st = $db->prepare("SELECT * FROM brouter_route_cache WHERE from_key = ? AND to_key = ? AND profile = ?");
        $st->execute([$fk, $tk, $profile]);
        if ($row = $st->fetch()) {
            return ['ok' => true, 'cached' => true, 'distance_m' => (float)$row['distance_m'],
                    'ascend_m' => (float)$row['ascend_m'], 'descend_m' => (float)$row['descend_m'],
                    'time_s' => 0, 'coords' => json_decode($row['coords_json'], true) ?: [], 'profile' => $profile, 'ms' => 0];
        }
    } catch (Exception $e) { /* table missing: fall through and just route */ }

    $r = brouter_route([[$lat1, $lon1], [$lat2, $lon2]], $profile);
    $r['cached'] = false;
    if ($r['ok']) {
        try {
            $st = $db->prepare("INSERT IGNORE INTO brouter_route_cache (from_key, to_key, profile, distance_m, ascend_m, descend_m, coords_json)
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$fk, $tk, $profile, $r['distance_m'], $r['ascend_m'], $r['descend_m'],
                          json_encode(array_map(fn($c) => [round($c[0], 5), round($c[1], 5), $c[2] === null ? null : round($c[2])], bp_thin($r['coords'], 600)))]);
        } catch (Exception $e) { /* ignore cache write problems */ }
    }
    return $r;
}

/**
 * Route between two SOTA summits (by reference). Returns the route plus summit info and the
 * hike time in each direction (the drop going A to B is the climb going B to A).
 */
function brouter_route_summits($refA, $refB, $profile = 'hiking-mountain') {
    $a = get_sota_cache_summit($refA);
    $b = get_sota_cache_summit($refB);
    if (!$a || $a['lat'] === null) return ['ok' => false, 'error' => 'Unknown summit: ' . $refA, 'http' => 0];
    if (!$b || $b['lat'] === null) return ['ok' => false, 'error' => 'Unknown summit: ' . $refB, 'http' => 0];
    $r = brouter_route_cached($a['lat'], $a['lon'], $b['lat'], $b['lon'], $profile);
    $r['a'] = $a;
    $r['b'] = $b;
    $r['straight_m'] = bp_dist($a['lat'], $a['lon'], $b['lat'], $b['lon']);
    if ($r['ok']) {
        $mi  = $r['distance_m'] / 1609.344;
        $r['time_ab_min'] = calculateHikeTime($mi, $r['ascend_m'] * 3.28084);
        $r['time_ba_min'] = calculateHikeTime($mi, $r['descend_m'] * 3.28084);
    }
    return $r;
}

/* ───────────────────────────── 3. path discovery ───────────────────────────── */

/** One Overpass call. Returns decoded JSON or null. Backs off and tries a second public server on failure. */
function bp_overpass($query) {
    // Test hook: a callable in $GLOBALS['bp_overpass_override'] replaces the network call.
    if (!empty($GLOBALS['bp_overpass_override']) && is_callable($GLOBALS['bp_overpass_override'])) {
        return ($GLOBALS['bp_overpass_override'])($query);
    }
    $urls = ['https://overpass-api.de/api/interpreter', 'https://overpass.private.coffee/api/interpreter', 'https://overpass-api.de/api/interpreter'];
    $wait = 3;
    foreach ($urls as $url) {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SOTAPlanner-PathFinder/1.0 (cr@christopherreddick.com)\r\n",
            'content'       => 'data=' . urlencode($query),
            'timeout'       => 70,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw) {
            $j = json_decode($raw, true);
            if (isset($j['elements']) && is_array($j['elements'])) return $j;
        }
        sleep($wait);
        $wait *= 2;   // 502 "at capacity" means back off, not hammer
    }
    return null;
}

/**
 * Find ways out of a summit's activation zone.
 *
 * @return array ['ok'=>bool, 'error'?, 'summit'=>[...], 'zone_source', 'zone'=>[[lat,lon]...],
 *                'entries'=>int, 'entries_in_zone'=>bool, 'saved'=>int, 'candidates'=>[...saved rows]]
 */
function bp_discover($ref, $radius_m = 5000) {
    @set_time_limit(280);
    $radius_m = max(1500, min(8000, (int)$radius_m));
    $ref = strtoupper(trim($ref));
    $s = get_sota_cache_summit($ref);
    if (!$s || $s['lat'] === null) return ['ok' => false, 'error' => 'Unknown summit: ' . $ref];
    $slat = $s['lat']; $slon = $s['lon'];
    $ele_m = $s['altFt'] / 3.28084;

    // Activation zone polygon (cached permanently by config.php); fall back to a 150 m circle.
    $zone_source = 'circle';
    $rings = [];
    $az = get_activation_zone_from_api($ref, $slat, $slon, $ele_m);
    if ($az && !empty($az['polygon'])) { $rings = bp_rings($az['polygon']); $zone_source = $az['source']; }
    if (!$rings) {
        $ring = [];
        for ($i = 0; $i <= 24; $i++) {
            $ang = 2 * M_PI * $i / 24;
            $ring[] = [$slon + 150 * sin($ang) / (111320 * cos(deg2rad($slat))), $slat + 150 * cos($ang) / 111320];
        }
        $rings = [$ring];
    }
    $in_zone = function ($lat, $lon) use ($rings) {
        foreach ($rings as $r) if (bp_in_ring($lat, $lon, $r)) return true;
        return false;
    };

    // One Overpass query: footpaths, roads, trailheads, parking.
    $path_re = '^(path|footway|track|steps|bridleway)$';
    $road_re = '^(residential|unclassified|tertiary|tertiary_link|secondary|secondary_link|primary|primary_link|trunk|living_street|service|road)$';
    $a = "around:{$radius_m},{$slat},{$slon}";
    // Geometry only for footpaths (the heavy part); roads come back as node lists + tags, and only
    // the roads that actually touch one of those paths. That keeps the answer small for a busy public server.
    $q = "[out:json][timeout:50];
      way[\"highway\"~\"{$path_re}\"][\"access\"!~\"^(private|no)$\"][\"foot\"!=\"no\"][\"footway\"!~\"^(sidewalk|crossing)$\"]({$a})->.p;
      node(w.p)->.pn;
      way(bn.pn)[\"highway\"~\"{$road_re}\"][\"access\"!~\"^(private|no)$\"][\"service\"!=\"driveway\"]->.r;
      (node[\"highway\"=\"trailhead\"]({$a});node[\"tourism\"=\"trailhead\"]({$a});
       way[\"amenity\"=\"parking\"][\"access\"!~\"^(private|no)$\"]({$a});node[\"amenity\"=\"parking\"][\"access\"!~\"^(private|no)$\"]({$a}););->.o;
      .p out body geom;
      .r out body;
      .o out body geom;";
    $j = bp_overpass($q);
    if (!$j) return ['ok' => false, 'error' => 'OpenStreetMap (Overpass) did not answer. Try again in a minute.'];

    $coord = [];        // node id => [lat, lon]
    $adj = [];          // node id => [[nbr id, metres], ...]
    $road_name = [];    // node id => road name (nodes that sit on a road)
    $pois = [];         // [lat, lon, type, name]
    $path_ways = 0;

    foreach ($j['elements'] as $el) {
        $t = $el['tags'] ?? [];
        $type = $el['type'] ?? '';
        if ($type === 'node') {
            if (($t['highway'] ?? '') === 'trailhead' || ($t['tourism'] ?? '') === 'trailhead') {
                $pois[] = [$el['lat'], $el['lon'], 'trailhead', $t['name'] ?? ''];
            } elseif (($t['amenity'] ?? '') === 'parking') {
                $pois[] = [$el['lat'], $el['lon'], 'parking', $t['name'] ?? ''];
            }
            continue;
        }
        if ($type !== 'way' || empty($el['nodes'])) continue;
        $hw = $t['highway'] ?? '';
        if (preg_match('/' . $road_re . '/', $hw) && empty($el['geometry'])) {
            $nm = $t['name'] ?? ($t['ref'] ?? ucfirst($hw));
            foreach ($el['nodes'] as $nid) $road_name[$nid] = $nm;
            continue;
        }
        if (empty($el['geometry'])) continue;
        if (($t['amenity'] ?? '') === 'parking') {
            $sx = 0; $sy = 0; $c = count($el['geometry']);
            foreach ($el['geometry'] as $g) { $sx += $g['lat']; $sy += $g['lon']; }
            $pois[] = [$sx / $c, $sy / $c, 'parking', $t['name'] ?? ''];
            foreach ($el['geometry'] as $g) $pois[] = [$g['lat'], $g['lon'], 'parking', $t['name'] ?? ''];
            continue;
        }
        if (preg_match('/' . $path_re . '/', $hw)) {
            $path_ways++;
            $ids = $el['nodes']; $geo = $el['geometry'];
            for ($i = 0; $i < count($ids); $i++) {
                if (!isset($geo[$i])) break;
                $coord[$ids[$i]] = [$geo[$i]['lat'], $geo[$i]['lon']];
                if ($i > 0 && isset($geo[$i - 1])) {
                    $d = bp_dist($geo[$i - 1]['lat'], $geo[$i - 1]['lon'], $geo[$i]['lat'], $geo[$i]['lon']);
                    $adj[$ids[$i - 1]][] = [$ids[$i], $d];
                    $adj[$ids[$i]][] = [$ids[$i - 1], $d];
                }
            }
        } elseif (preg_match('/' . $road_re . '/', $hw)) {
            $nm = $t['name'] ?? ($t['ref'] ?? ucfirst($hw));
            foreach ($el['nodes'] as $nid) $road_name[$nid] = $nm;
        }
    }

    if (!$adj) {
        return ['ok' => true, 'summit' => $s, 'zone_source' => $zone_source, 'zone' => bp_zone_latlon($rings),
                'entries' => 0, 'entries_in_zone' => false, 'saved' => 0, 'path_ways' => 0,
                'note' => 'OpenStreetMap has no mapped footpaths or tracks within ' . round($radius_m / 1000, 1) . ' km of this summit.',
                'candidates' => bp_load_candidates($ref)];
    }

    // Entry nodes: path nodes inside the zone (check edge midpoints too, since OSM nodes can be sparse).
    $entries = [];
    foreach ($adj as $id => $nbrs) {
        [$la, $lo] = $coord[$id];
        if ($in_zone($la, $lo)) { $entries[$id] = true; continue; }
        foreach ($nbrs as $nb) {
            [$lb, $ob] = $coord[$nb[0]];
            if ($in_zone(($la + $lb) / 2, ($lo + $ob) / 2)) { $entries[$id] = true; break; }
        }
    }
    $entries_in_zone = (bool)$entries;
    if (!$entries) {
        // No mapped path touches the zone: start from path nodes within 250 m of the summit instead.
        foreach ($adj as $id => $_) {
            if (bp_dist($slat, $slon, $coord[$id][0], $coord[$id][1]) <= 250) $entries[$id] = true;
        }
    }
    if (!$entries) {
        return ['ok' => true, 'summit' => $s, 'zone_source' => $zone_source, 'zone' => bp_zone_latlon($rings),
                'entries' => 0, 'entries_in_zone' => false, 'saved' => 0, 'path_ways' => $path_ways,
                'note' => 'There are ' . $path_ways . ' mapped paths nearby, but none inside the zone or within 250 m of the summit. This summit looks truly trail-less on the map.',
                'candidates' => bp_load_candidates($ref)];
    }

    // Is this node a way out? Returns [type, name] or null.
    $poi_cache = [];
    $exit_of = function ($id) use (&$coord, &$road_name, &$pois, &$poi_cache) {
        if (array_key_exists($id, $poi_cache)) return $poi_cache[$id];
        [$la, $lo] = $coord[$id];
        $res = null;
        foreach ($pois as $p) {
            if (abs($p[0] - $la) > 0.0004 || abs($p[1] - $lo) > 0.0006) continue;   // about 45 m box
            if (bp_dist($la, $lo, $p[0], $p[1]) <= 35) {
                if ($res === null || ($p[2] === 'trailhead' && $res[0] !== 'trailhead')) $res = [$p[2], $p[3]];
            }
        }
        if ($res === null && isset($road_name[$id])) $res = ['road', $road_name[$id]];
        return $poi_cache[$id] = $res;
    };

    // Multi-source Dijkstra from every entry node. Exits are terminal: a branch stops where it
    // meets a road, trailhead or parking lot.
    $dist = []; $prev = []; $src = [];
    $pq = new SplPriorityQueue();
    $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    foreach ($entries as $id => $_) { $dist[$id] = 0.0; $src[$id] = $id; $pq->insert($id, 0); }
    $found = [];
    while (!$pq->isEmpty()) {
        $top = $pq->extract();
        $u = $top['data'];
        $du = -$top['priority'];
        if ($du > ($dist[$u] ?? INF) + 1e-9) continue;
        if ($du > $radius_m) break;
        $ex = $exit_of($u);
        if ($ex !== null) { $found[$u] = $ex; continue; }
        foreach ($adj[$u] ?? [] as [$v, $w]) {
            $nd = $du + $w;
            if ($nd < ($dist[$v] ?? INF)) {
                $dist[$v] = $nd; $prev[$v] = $u; $src[$v] = $src[$u];
                $pq->insert($v, -$nd);
            }
        }
    }

    // Build candidate paths, shortest first, and drop ones that mostly retrace an earlier path.
    $order = array_keys($found);
    usort($order, fn($x, $y) => $dist[$x] <=> $dist[$y]);
    $kept = []; $used = [];
    foreach ($order as $xid) {
        $chain = [];
        for ($n = $xid; $n !== null; $n = $prev[$n] ?? null) { $chain[] = $n; if (!isset($prev[$n])) break; }
        $chain = array_reverse($chain);   // entry ... exit
        $len = count($chain);
        $shared = 0;
        foreach ($chain as $n) if (isset($used[$n])) $shared++;
        if ($len > 2 && $shared / $len > 0.6) continue;
        foreach ($chain as $n) $used[$n] = true;
        $kept[] = ['exit' => $xid, 'chain' => $chain];
        if (count($kept) >= 10) break;
    }

    // Save (INSERT IGNORE keeps anything already reviewed). Drop old unreviewed rows first so a re-run is clean.
    $db = getDbConnection();
    $db->prepare("DELETE FROM brouter_path_candidates WHERE sota_ref = ? AND status = 'new'")->execute([$ref]);
    $ins = $db->prepare("INSERT IGNORE INTO brouter_path_candidates
        (sota_ref, exit_key, exit_type, exit_name, exit_lat, exit_lon, entry_lat, entry_lon, entry_in_zone, graph_dist_m, osm_coords_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $saved = 0;
    foreach ($kept as $k) {
        $xid = $k['exit'];
        [$xt, $xn] = $found[$xid];
        $eid = $k['chain'][0];
        $line = array_map(fn($n) => [round($coord[$n][0], 5), round($coord[$n][1], 5)], $k['chain']);
        $ins->execute([$ref, bp_key($coord[$xid][0], $coord[$xid][1]), $xt, mb_substr((string)$xn, 0, 160),
                       $coord[$xid][0], $coord[$xid][1], $coord[$eid][0], $coord[$eid][1],
                       $entries_in_zone ? 1 : 0, round($dist[$xid]), json_encode(bp_thin($line, 300))]);
        $saved += $ins->rowCount();
    }

    $note = '';
    if (!$kept) $note = 'Paths touch the zone, but none reaches a road, trailhead or parking lot within ' . round($radius_m / 1000, 1) . ' km. Try a bigger search radius.';
    elseif (!$entries_in_zone) $note = 'No mapped path enters the activation zone itself. These start at the nearest paths within 250 m of the summit.';

    return ['ok' => true, 'summit' => $s, 'zone_source' => $zone_source, 'zone' => bp_zone_latlon($rings),
            'entries' => count($entries), 'entries_in_zone' => $entries_in_zone, 'saved' => $saved,
            'path_ways' => $path_ways, 'note' => $note, 'candidates' => bp_load_candidates($ref)];
}

function bp_zone_latlon(array $rings) {
    $out = [];
    foreach ($rings as $r) $out[] = array_map(fn($p) => [$p[1], $p[0]], bp_thin($r, 200));
    return $out;
}

/** Saved candidates for a summit, ready for JSON. */
function bp_load_candidates($ref) {
    $db = getDbConnection();
    $st = $db->prepare("SELECT * FROM brouter_path_candidates WHERE sota_ref = ? ORDER BY (status='rejected'), graph_dist_m");
    $st->execute([strtoupper(trim($ref))]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $mi = $r['distance_m'] !== null ? $r['distance_m'] / 1609.344 : null;
        $rows[] = [
            'id' => (int)$r['id'], 'type' => $r['exit_type'], 'name' => $r['exit_name'],
            'exit' => [(float)$r['exit_lat'], (float)$r['exit_lon']],
            'entry' => [(float)$r['entry_lat'], (float)$r['entry_lon']], 'in_zone' => (bool)$r['entry_in_zone'],
            'graph_m' => (float)$r['graph_dist_m'],
            'osm' => json_decode($r['osm_coords_json'], true) ?: [],
            'refined' => $r['distance_m'] !== null,
            'dist_m' => $r['distance_m'] === null ? null : (float)$r['distance_m'],
            'gain_m' => $r['ascend_m'] === null ? null : (float)$r['ascend_m'],
            'drop_m' => $r['descend_m'] === null ? null : (float)$r['descend_m'],
            'route' => $r['coords_json'] ? json_decode($r['coords_json'], true) : null,
            'time_min' => $mi === null ? null : calculateHikeTime($mi, $r['ascend_m'] * 3.28084),
            'note' => $r['refine_note'], 'status' => $r['status'],
        ];
    }
    return $rows;
}

/**
 * Ask BRouter for the real route of one saved candidate: exit, then entry point, then summit.
 * If the summit itself cannot be reached from the entry point (nothing routable within BRouter's
 * snap distance) it falls back to exit to entry and says so in the note.
 */
function bp_refine_candidate($id) {
    $db = getDbConnection();
    $st = $db->prepare("SELECT * FROM brouter_path_candidates WHERE id = ?");
    $st->execute([(int)$id]);
    $c = $st->fetch();
    if (!$c) return ['ok' => false, 'error' => 'Candidate not found'];
    $s = get_sota_cache_summit($c['sota_ref']);
    $ex = [(float)$c['exit_lat'], (float)$c['exit_lon']];
    $en = [(float)$c['entry_lat'], (float)$c['entry_lon']];
    $note = '';
    $r = null;
    if ($s && $s['lat'] !== null) {
        $r = brouter_route([$ex, $en, [$s['lat'], $s['lon']]], 'hiking-mountain');
        if (!$r['ok']) { $note = 'Last leg to the summit point could not be routed (' . $r['error'] . '); route ends at the zone entry.'; $r = null; usleep(250000); }
    }
    if (!$r) {
        $r = brouter_route([$ex, $en], 'hiking-mountain');
        if (!$r['ok']) {
            $db->prepare("UPDATE brouter_path_candidates SET refine_note = ? WHERE id = ?")->execute([mb_substr($r['error'], 0, 200), $id]);
            return ['ok' => false, 'error' => $r['error']];
        }
    }
    $coords = array_map(fn($p) => [round($p[0], 5), round($p[1], 5), $p[2] === null ? null : round($p[2])], bp_thin($r['coords'], 500));
    $db->prepare("UPDATE brouter_path_candidates SET distance_m = ?, ascend_m = ?, descend_m = ?, coords_json = ?, refine_note = ? WHERE id = ?")
       ->execute([$r['distance_m'], $r['ascend_m'], $r['descend_m'], json_encode($coords), $note ?: null, (int)$id]);
    return ['ok' => true];
}
