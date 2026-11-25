<?php
/**
 * Discovered routes on the summit page (branch discovered-routes, started 2026-11-24).
 * Design notes: research/discovered-routes-picker.md.
 *
 * Trail discovery (trail_discovery_lib.php, God Mode) saves every candidate route of a test run as a gzip file.
 * "Publishing" a run (discovered_publish.php) copies its summits into discovered_summits / discovered_routes and
 * writes a slim copy of each file to sota_discovered/published/. The summit page panel reads them through
 * discovered_routes.php.
 *
 * Who sees it:
 *  - Launch switch app_settings 'discovered_routes_public' (OFF until Four Days in May 2027): when on, everyone.
 *  - Before launch: God Mode only, and on the summit page only while God Mode's "Discovery mode" is switched on
 *    (app_settings 'discovery_mode_<CALLSIGN>'), so the regular summit page stays exactly as it is otherwise.
 *
 * This file never edits trail discovery itself; it only reads the run files.
 */

const DR_SHOWN = 3;   // routes offered on the summit page

/* ───────────────────────── switches ───────────────────────── */

function dr_setting($key) {
    try {
        $st = getDbConnection()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $st->execute([$key]);
        return $st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** The launch switch. Off until Four Days in May 2027. */
function dr_public() {
    static $v = null;
    if ($v === null) $v = dr_setting('discovered_routes_public') === '1';
    return $v;
}

/** May this user read discovered routes at all? */
function dr_can_see() {
    return dr_public() || isGodMode();
}

/** God Mode's personal "Discovery mode" switch (remembered per callsign). */
function dr_mode_on() {
    static $v = null;
    if ($v === null) $v = isGodMode() && dr_setting('discovery_mode_' . getCurrentCallsign()) === '1';
    return $v;
}

function dr_set_mode($on) {
    $db = getDbConnection();
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
       ->execute(['discovery_mode_' . getCurrentCallsign(), $on ? '1' : '0']);
}

/** Does the summit page show the panel? Public after launch; before that God Mode with Discovery mode on. */
function dr_panel_on() {
    return dr_public() || dr_mode_on();
}

/** User chip menu item for God Mode: switches Discovery mode on or off and comes back to this page. */
function dr_menu_link() {
    if (!isGodMode()) return '';
    $on = dr_mode_on();
    $back = $_SERVER['REQUEST_URI'] ?? 'index.php';
    $href = 'discovered_routes.php?action=mode&on=' . ($on ? '0' : '1') . '&back=' . rawurlencode($back);
    return '<a href="' . htmlspecialchars($href) . '">Discovery mode: ' . ($on ? 'On' : 'Off') . '</a>';
}

/* ───────────────────────── files ───────────────────────── */

/** Private folder shared by production and staging, one level above the web roots (same as trail discovery). */
function dr_store_dir() {
    $home = file_exists(dirname(__DIR__) . '/sotaplanner_secrets.php') ? dirname(__DIR__) : dirname(dirname(__DIR__));
    return $home . '/sota_discovered';
}

function dr_ref_file($ref) {
    return preg_replace('/[^A-Z0-9_-]/', '_', strtoupper($ref)) . '.json.gz';
}

/** A trail discovery run file ('runs/<run>/<REF>.json.gz'), read only. */
function dr_load_run_file($rel) {
    if (!preg_match('#^runs/\d+/[A-Z0-9_-]+\.json\.gz$#', (string)$rel)) return null;
    $raw = @file_get_contents(dr_store_dir() . '/' . $rel);
    if ($raw === false) return null;
    $j = json_decode((string)@gzdecode($raw), true);
    return is_array($j) ? $j : null;
}

function dr_load_published($rel) {
    if (!preg_match('#^published/[A-Z0-9_-]+\.json\.gz$#', (string)$rel)) return null;
    $raw = @file_get_contents(dr_store_dir() . '/' . $rel);
    if ($raw === false) return null;
    $j = json_decode((string)@gzdecode($raw), true);
    return is_array($j) ? $j : null;
}

/* ───────────────────────── labels ───────────────────────── */

/** paved, dirt, lot or trailhead (a mapped lot wins over the road it sits on). */
function dr_start_kind(array $c) {
    if (!empty($c['lot'])) return ($c['lot']['k'] ?? '') === 'T' ? 'trailhead' : 'lot';
    return in_array($c['cls'] ?? '', ['paved', 'dirt'], true) ? $c['cls'] : 'walk';
}

/** Plain-English name of a route's starting point. */
function dr_start_label(array $c) {
    $lotName = trim((string)($c['lot']['name'] ?? ''));
    switch (dr_start_kind($c)) {
        case 'trailhead': return $lotName !== '' ? 'Trailhead: ' . $lotName : 'Mapped trailhead';
        case 'lot':       return $lotName !== '' ? 'Parking: ' . $lotName : 'Mapped parking lot';
        case 'dirt':      return 'Dirt road' . dr_road_suffix($c['road'] ?? '');
        case 'paved':     return 'Paved road' . dr_road_suffix($c['road'] ?? '');
    }
    return 'Path end';
}

/** "unclassified, asphalt" → " (unclassified)": just the road class, which is what people recognise. */
function dr_road_suffix($road) {
    $first = trim(explode(',', (string)$road)[0]);
    $names = ['residential' => 'residential street', 'unclassified' => 'minor road', 'track' => 'track', 'service' => 'service road',
              'tertiary' => 'local road', 'secondary' => 'main road', 'primary' => 'highway', 'trunk' => 'highway'];
    return $first !== '' ? ' (' . ($names[$first] ?? str_replace('_', ' ', $first)) . ')' : '';
}

/** Three levels (Chris, 2026-11-24): same cut points as trail discovery's summit bands. */
function dr_band($conf) {
    if ($conf === null) return null;
    return $conf >= 70 ? 'high' : ($conf >= 40 ? 'medium' : 'low');
}

/* ───────────────────────── publishing ───────────────────────── */

/**
 * The 3 routes to show, highest confidence first. Uses the run's own pick when it has one,
 * otherwise the 3 highest confidence routes.
 */
function dr_pick(array $d) {
    $cands = $d['candidates'] ?? [];
    $pick = array_values(array_filter((array)($d['chosen'] ?? []), fn($i) => isset($cands[$i])));
    if (!$pick) {
        $pick = array_keys($cands);
        usort($pick, fn($a, $b) => ($cands[$b]['confidence'] ?? 0) <=> ($cands[$a]['confidence'] ?? 0));
    }
    usort($pick, fn($a, $b) => ($cands[$b]['confidence'] ?? 0) <=> ($cands[$a]['confidence'] ?? 0) ?: $a <=> $b);
    return array_slice($pick, 0, DR_SHOWN);
}

/**
 * Publish every summit of one trail discovery run. A summit published earlier (from any run) is replaced;
 * the run files themselves are never touched, so they remain the history.
 * Returns ['published' => n, 'replaced' => n, 'none' => n, 'skipped' => n, 'errors' => [..]].
 */
function dr_publish_run(PDO $db, $run_id, $by, ?callable $progress = null) {
    $run_id = (int)$run_id;
    $out = ['published' => 0, 'replaced' => 0, 'none' => 0, 'skipped' => 0, 'routes' => 0, 'errors' => []];
    $dir = dr_store_dir() . '/published';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { $out['errors'][] = 'Cannot create ' . $dir; return $out; }

    $rows = $db->prepare("SELECT sota_ref, file, created_at FROM trail_disc_results WHERE run_id = ? AND status = 'ok' AND file IS NOT NULL ORDER BY sota_ref");
    $rows->execute([$run_id]);
    $rows = $rows->fetchAll();
    $settings = $db->prepare("SELECT settings_json FROM trail_disc_runs WHERE id = ?");
    $settings->execute([$run_id]);
    $settings = $settings->fetchColumn() ?: null;

    $had = $db->prepare("SELECT 1 FROM discovered_summits WHERE sota_ref = ?");
    $delR = $db->prepare("DELETE FROM discovered_routes WHERE sota_ref = ?");
    $insS = $db->prepare("REPLACE INTO discovered_summits (sota_ref, run_id, summit_conf, band, n_routes, status, searched_at, settings_json, file, published_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insR = $db->prepare("INSERT INTO discovered_routes (sota_ref, route_no, run_id, start_lat, start_lon, start_kind, start_name, dist_m, gain_m,
                          time_min, confidence, rank_shown, drive_up, has_closed_fallback, file) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($rows as $n => $row) {
        $d = dr_load_run_file($row['file']);
        if (!$d || empty($d['ok'])) { $out['skipped']++; continue; }
        $ref = strtoupper($d['summit']['ref'] ?? $row['sota_ref']);
        $cands = array_values($d['candidates'] ?? []);
        $d['candidates'] = $cands;
        $shown = dr_pick($d);
        $slim = [];
        foreach ($cands as $c) {
            $slim[] = array_intersect_key($c, array_flip(['cls', 'lat', 'lon', 'road', 'hits', 'dist_m', 'gain_m', 'drop_m', 'time_min',
                'summit_gap_m', 'offtrail_m', 'sac', 'route', 'lot', 'closed', 'driveup', 'pk_m', 'pkT_m', 'confidence', 'reasons']));
        }
        $conf = isset($d['confidence']) ? (int)$d['confidence'] : null;
        $pub = [
            'ref' => $ref, 'run_id' => $run_id, 'summit' => $d['summit'], 'settings' => $d['settings'] ?? null,
            'confidence' => $conf, 'band' => $d['band'] ?? dr_band($conf), 'shown' => $shown, 'candidates' => $slim,
            'searched_at' => $row['created_at'], 'published_at' => date('c'),
        ];
        $rel = 'published/' . dr_ref_file($ref);
        if (@file_put_contents(dr_store_dir() . '/' . $rel, gzencode(json_encode($pub), 6)) === false) {
            $out['errors'][] = $ref . ': could not write file'; continue;
        }
        try {
            $db->beginTransaction();
            $had->execute([$ref]);
            $wasThere = (bool)$had->fetchColumn();
            $delR->execute([$ref]);
            $insS->execute([$ref, $run_id, $conf, $pub['band'], count($cands), $cands ? 'found' : 'none', $row['created_at'],
                            $settings, $rel, $by]);
            foreach ($cands as $i => $c) {
                $rank = array_search($i, $shown, true);
                $insR->execute([$ref, $i, $run_id, round((float)$c['lat'], 6), round((float)$c['lon'], 6), dr_start_kind($c),
                    mb_substr(dr_start_label($c), 0, 200), (int)$c['dist_m'], (int)$c['gain_m'], (int)$c['time_min'],
                    isset($c['confidence']) ? (int)$c['confidence'] : null, $rank === false ? null : $rank + 1,
                    !empty($c['driveup']) ? 1 : 0, !empty($c['closed']) ? 1 : 0, $rel]);
            }
            $db->commit();
            $out['published']++;
            $out['routes'] += count($cands);
            if ($wasThere) $out['replaced']++;
            if (!$cands) $out['none']++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $out['errors'][] = $ref . ': ' . $e->getMessage();
        }
        if ($progress) $progress($n + 1, count($rows), $ref);
    }
    return $out;
}

/* ───────────────────────── summit page ───────────────────────── */

/** Is this GPX row one we wrote from a discovered route? */
function dr_is_discovered_gpx($gpx_data) {
    return $gpx_data && strpos((string)($gpx_data['filename'] ?? ''), 'disc_') === 0;
}

/**
 * The panel on summit_detail.php. Renders a placeholder; dr_ui.js loads the routes from discovered_routes.php
 * after the page has loaded, so the summit page is never slowed down by it.
 */
function dr_render_panel(PDO $db, array $summit, $gpx_data, $current_group, $units) {
    if (!dr_panel_on() || empty($summit['sota_ref'])) return;
    $st = $db->prepare("SELECT run_id, n_routes, status FROM discovered_summits WHERE sota_ref = ?");
    try { $st->execute([strtoupper($summit['sota_ref'])]); $ds = $st->fetch(); }
    catch (Throwable $e) { $ds = false; }   // tables not migrated yet

    $ownRoute = $gpx_data && !dr_is_discovered_gpx($gpx_data);
    // After launch the panel is only for summits with no hike data of their own; God Mode in Discovery mode sees it anyway.
    $hasOwnData = $ownRoute || (!$gpx_data && !empty($summit['hike_distance_mi']));
    if ($hasOwnData && !dr_mode_on()) return;

    $v = fn($f) => @filemtime(__DIR__ . '/' . $f);
    echo '<link rel="stylesheet" href="dr_ui.css?v=' . $v('dr_ui.css') . '">';
    if (!$ds) {
        echo '<div class="dr-panel dr-panel-empty"><span class="dr-mode-tag">Discovery mode</span> No discovered routes for this summit yet. '
           . 'Publish a trail discovery run that includes it (God Mode, <a href="discovered_publish.php">Publish discovered routes</a>).</div>';
        return;
    }
    // A summit that already has a route of its own: draw it in gray on the panel map for comparison (God Mode testing).
    $compare = null;
    if ($ownRoute && !empty($gpx_data['file_path']) && file_exists($gpx_data['file_path'])) {
        $path = extractGpxPath($gpx_data['file_path'], 300);
        if (is_array($path)) {
            $pts = isset($path['points']) ? $path['points'] : $path;
            $compare = [];
            foreach ($pts as $p) {
                $lat = $p['lat'] ?? $p[0] ?? null; $lon = $p['lon'] ?? $p['lng'] ?? $p[1] ?? null;
                if ($lat !== null && $lon !== null) $compare[] = [round((float)$lat, 5), round((float)$lon, 5)];
            }
        }
    }
    $cfg = [
        'ref' => strtoupper($summit['sota_ref']), 'summitId' => (int)$summit['id'], 'groupId' => (int)($current_group['id'] ?? 0),
        'lat' => (float)$summit['latitude'], 'lon' => (float)$summit['longitude'], 'units' => $units === 'metric' ? 'metric' : 'imperial',
        'pace' => (float)($current_group['pace_multiplier'] ?? 1.0), 'ownRoute' => $ownRoute,
        'usingDiscovered' => dr_is_discovered_gpx($gpx_data), 'compare' => $compare,
        'godMode' => isGodMode(), 'public' => dr_public(),
    ];
    ?>
<div class="dr-panel" id="dr-panel">
  <div class="dr-head">
    <div class="dr-title-row">
      <h3 class="dr-title">Possible routes</h3>
      <span class="dr-sub">Computer generated, not verified</span>
      <?php if (!dr_public()): ?><span class="dr-mode-tag" title="Only God Mode sees this until the launch switch is turned on">Discovery mode</span><?php endif; ?>
    </div>
    <div class="dr-summit-conf" id="dr-summit-conf"></div>
  </div>
  <div id="dr-notice"></div>
  <div id="dr-map" class="dr-map"></div>
  <div id="dr-legend" class="dr-legend"></div>
  <div id="dr-cards" class="dr-cards"><div class="dr-loading">Loading discovered routes...</div></div>
  <div class="dr-more">
    <span>None of these fit?</span>
    <button type="button" class="btn btn-ghost btn-sm" disabled title="Next build step">Find more options</button>
    <button type="button" class="btn btn-ghost btn-sm" disabled title="Comes with the GPX editor">Edit or create my own</button>
    <span class="dr-more-hint">Until then: upload your own GPX below, or set the Starting Point on the map.</span>
  </div>
  <div class="dr-credit">Routes computed with BRouter from OpenStreetMap data. Parking and trailheads &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a> (ODbL).</div>
</div>
<script>window.DR_CFG = <?= json_encode($cfg) ?>;</script>
<script src="dr_ui.js?v=<?= $v('dr_ui.js') ?>"></script>
<?php
}

/* ───────────────────────── reading for the endpoint ───────────────────────── */

/** The 3 shown routes of a summit with everything the panel needs. Null when nothing is published. */
function dr_summit_payload(PDO $db, $ref, $pace = 1.0) {
    $st = $db->prepare("SELECT * FROM discovered_summits WHERE sota_ref = ?");
    $st->execute([$ref]);
    $ds = $st->fetch();
    if (!$ds) return null;
    $pub = $ds['file'] ? dr_load_published($ds['file']) : null;
    if (!$pub) return ['ref' => $ref, 'status' => 'missing_file', 'routes' => []];
    $routes = [];
    foreach ($pub['shown'] as $rank => $i) {
        $c = $pub['candidates'][$i] ?? null;
        if (!$c) continue;
        $route = $c['route'];
        // Off-trail finish: no mapped way reaches the top, so the last stretch is a straight line to the summit.
        $offtrail = (int)($c['offtrail_m'] ?? 0) > 0;
        $rt_mi = 2 * $c['dist_m'] / 1609.344;
        $routes[] = [
            'no' => $i, 'rank' => $rank + 1, 'kind' => dr_start_kind($c), 'label' => dr_start_label($c),
            'lat' => $c['lat'], 'lon' => $c['lon'], 'dist_m' => (int)$c['dist_m'], 'gain_m' => (int)$c['gain_m'],
            'up_min' => (int)$c['time_min'],
            // Same formula and pace as the summit page, on the round trip, so "Use this route" shows the same time afterwards
            'rt_min' => (int)calculateHikeTime($rt_mi, $c['gain_m'] * 3.28084, $pace),
            'offtrail_m' => (int)($c['offtrail_m'] ?? 0), 'sac' => (int)($c['sac'] ?? 0), 'driveup' => !empty($c['driveup']),
            'confidence' => isset($c['confidence']) ? (int)$c['confidence'] : null, 'band' => dr_band($c['confidence'] ?? null),
            'reasons' => $c['reasons'] ?? [], 'route' => $route, 'offtrail' => $offtrail,
            'closed' => !empty($c['closed']) ? [
                'lat' => $c['closed']['lat'], 'lon' => $c['closed']['lon'], 'label' => 'Paved road' . dr_road_suffix($c['closed']['road'] ?? ''),
                'dist_m' => (int)$c['closed']['dist_m'], 'gain_m' => (int)$c['closed']['gain_m'],
                'extra_min' => (int)$c['closed']['extra_min'], 'route' => $c['closed']['route'],
            ] : null,
        ];
    }
    return [
        'ref' => $ref, 'status' => $ds['status'], 'run_id' => (int)$ds['run_id'], 'summit' => $pub['summit'],
        'confidence' => $ds['summit_conf'] === null ? null : (int)$ds['summit_conf'], 'band' => $ds['band'] ?: dr_band($ds['summit_conf']),
        'n_routes' => (int)$ds['n_routes'], 'searched_at' => $ds['searched_at'], 'routes' => $routes,
    ];
}

/** Votes on one summit's routes: counts for everyone, plus this user's own and this dashboard's use. */
function dr_votes(PDO $db, $ref, $run_id, $callsign, $group_id) {
    $out = ['counts' => [], 'mine' => [], 'used' => null, 'confirmed' => false];
    $st = $db->prepare("SELECT route_no, verdict, callsign, planning_group_id, created_at FROM discovered_route_votes WHERE sota_ref = ? AND run_id = ? ORDER BY id");
    $st->execute([$ref, $run_id]);
    foreach ($st->fetchAll() as $v) {
        $no = (int)$v['route_no'];
        if (in_array($v['verdict'], ['viable', 'not_viable'], true)) {
            $out['counts'][$no][$v['verdict']] = ($out['counts'][$no][$v['verdict']] ?? 0) + 1;
            if (strcasecmp($v['callsign'], $callsign) === 0) $out['mine'][$no] = $v['verdict'];
        } elseif ((int)$v['planning_group_id'] === (int)$group_id) {
            if ($v['verdict'] === 'used') { $out['used'] = ['no' => $no, 'by' => $v['callsign'], 'at' => $v['created_at']]; $out['confirmed'] = false; }
            if ($v['verdict'] === 'confirmed' && $out['used'] && $out['used']['no'] === $no) $out['confirmed'] = ['by' => $v['callsign'], 'at' => $v['created_at']];
        }
    }
    return $out;
}

/** GPX 1.1 text for one discovered route (route only, no timestamps). */
function dr_route_gpx(array $pub, array $c, $no) {
    $pts = $c['route'];
    $s = $pub['summit'];
    if ((int)($c['offtrail_m'] ?? 0) > 0) {
        $ele = !empty($s['alt_ft']) ? (int)round($s['alt_ft'] / 3.28084) : null;
        $pts[] = [(float)$s['lat'], (float)$s['lon'], $ele];
    }
    $name = 'Discovered route ' . ($no + 1) . ' to ' . $s['ref'] . (!empty($s['name']) ? ' ' . $s['name'] : '');
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<gpx version="1.1" creator="SOTAplanner trail discovery" xmlns="http://www.topografix.com/GPX/1/1">' . "\n"
       . '<metadata><name>' . htmlspecialchars($name, ENT_XML1) . '</name>'
       . '<desc>Computer generated, not verified. Routed with BRouter on OpenStreetMap data (c) OpenStreetMap contributors, ODbL. Run '
       . (int)$pub['run_id'] . '.</desc></metadata>' . "\n"
       . '<trk><name>' . htmlspecialchars($name, ENT_XML1) . '</name><trkseg>' . "\n";
    foreach ($pts as $p) {
        $x .= '<trkpt lat="' . round((float)$p[0], 6) . '" lon="' . round((float)$p[1], 6) . '">'
            . (isset($p[2]) && $p[2] !== null ? '<ele>' . (int)$p[2] . '</ele>' : '') . "</trkpt>\n";
    }
    return $x . "</trkseg></trk>\n</gpx>\n";
}
