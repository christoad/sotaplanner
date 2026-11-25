<?php
/**
 * Discovered routes: the gated read and write endpoint behind the summit page panel (dr_ui.js).
 * Everything here answers only when the launch switch is on or the user is God Mode (dr_can_see()).
 *
 *   GET  ?ref=W6/CT-031&summit_id=N          routes, summit confidence, votes, mapped parking near the starts
 *   POST action=vote     ref, run, route, verdict (viable | not_viable | clear)
 *   POST action=use      summit_id, ref, run, route   (writes the dashboard's own GPX and fills in hike data)
 *   POST action=confirm  summit_id, ref, run, route   ("Looks right": clears "review required")
 *   GET  ?action=mode&on=0|1&back=URL         God Mode's Discovery mode switch, then back to the page
 */
require_once 'config.php';
require_once 'summit_changelog_lib.php';
require_once 'parking_lib.php';
require_once 'discovered_routes_lib.php';
session_start();
requireLogin();
$callsign = getCurrentCallsign();
$db = getDbConnection();
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

// Discovery mode switch (God Mode only): a plain link in the user chip menu.
if ($action === 'mode') {
    session_write_close();
    if (isGodMode()) dr_set_mode(($_GET['on'] ?? '') === '1');
    $back = (string)($_GET['back'] ?? 'index.php');
    // Only ever go back to a page of this site
    if (!preg_match('#^/[^/\\\\]#', $back) && !preg_match('#^[a-z_]+\.php#i', $back)) $back = 'index.php';
    header('Location: ' . $back);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
$current_group = getCurrentPlanningGroup($db);
session_write_close();

function dr_out($a) { echo json_encode($a); exit; }
function dr_fail($msg, $code = 400) { http_response_code($code); dr_out(['ok' => false, 'error' => $msg]); }

if (!dr_can_see()) dr_fail('Not found', 404);

$ref = strtoupper(trim((string)($_POST['ref'] ?? $_GET['ref'] ?? '')));
if (!preg_match('~^[A-Z0-9]{1,8}/[A-Z0-9]{2}-\d{3}$~', $ref)) dr_fail('Bad summit reference');

/** The summit row, only when it is on the user's current dashboard. */
function dr_own_summit(PDO $db, $summit_id, $ref, $current_group) {
    $st = $db->prepare("SELECT * FROM summits WHERE id = ?");
    $st->execute([(int)$summit_id]);
    $s = $st->fetch();
    if (!$s || strtoupper((string)$s['sota_ref']) !== $ref) dr_fail('Summit not found', 404);
    if (!$current_group || (int)$s['planning_group_id'] !== (int)$current_group['id']) dr_fail('This summit is not on your current dashboard', 403);
    return $s;
}

/* ───────────────────────── read ───────────────────────── */

if ($action === 'get') {
    $pay = dr_summit_payload($db, $ref, (float)($current_group['pace_multiplier'] ?? 1.0));
    if (!$pay) dr_out(['ok' => true, 'status' => 'none_published', 'routes' => []]);
    if (!empty($pay['routes'])) {
        $pay['votes'] = dr_votes($db, $ref, $pay['run_id'], $callsign, $current_group['id'] ?? 0);
        // Mapped parking lots and trailheads around each start and gate-closed spot (OpenStreetMap list on the BRouter box,
        // cached on our server). Kept small: within 600 m, at most 25 per start.
        $seen = []; $parking = []; $pkErr = null;
        foreach ($pay['routes'] as &$r) {
            $spots = [[$r['lat'], $r['lon']]];
            if ($r['closed']) $spots[] = [$r['closed']['lat'], $r['closed']['lon']];
            foreach ($spots as $si => $sp) {
                $res = pk_near((float)$sp[0], (float)$sp[1], 600, 25);
                if (!$res['ok'] && $res['error']) $pkErr = $res['error'];
                // A mapped lot or trailhead close to a start that is not itself at mapped parking: say so on the card
                // (trail discovery can merge a lot start into a slightly quicker start nearby; see the design notes).
                if ($si === 0 && !in_array($r['kind'], ['lot', 'trailhead'], true)) {
                    foreach ($res['points'] as $p) {
                        if ($p['d'] < 40) break;   // effectively at the start already
                        if ($p['d'] <= 500 && (!isset($p['access']) || !in_array($p['access'], ['private', 'no'], true))) {
                            $r['near_lot'] = ['k' => $p['k'], 'name' => (string)($p['name'] ?? ''), 'd' => (int)$p['d']];
                            break;
                        }
                    }
                }
                foreach ($res['points'] as $p) {
                    $key = $p['k'] . round($p['lat'], 5) . ',' . round($p['lon'], 5);
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $parking[] = ['k' => $p['k'], 'lat' => (float)$p['lat'], 'lon' => (float)$p['lon'], 'name' => (string)($p['name'] ?? ''),
                                  'access' => (string)($p['access'] ?? ''), 'fee' => (string)($p['fee'] ?? '')];
                }
            }
        }
        unset($r);
        $pay['parking'] = $parking;
        $pay['parking_error'] = $pkErr;
    }
    $pay['ok'] = true;
    $pay['god'] = isGodMode();
    dr_out($pay);
}

/* ───────────────────────── writes ───────────────────────── */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') dr_fail('POST required', 405);
$run = (int)($_POST['run'] ?? 0);
$no  = (int)($_POST['route'] ?? -1);

// The route must be the one currently published for this summit (route numbers change when a newer run is published)
$st = $db->prepare("SELECT r.*, s.file AS summit_file FROM discovered_routes r JOIN discovered_summits s ON s.sota_ref = r.sota_ref
                    WHERE r.sota_ref = ? AND r.route_no = ? AND r.run_id = ? AND s.run_id = ?");
$st->execute([$ref, $no, $run, $run]);
$route = $st->fetch();
if (!$route) dr_fail('These routes were updated. Reload the page.', 409);

if ($action === 'vote') {
    $verdict = (string)($_POST['verdict'] ?? '');
    if (!in_array($verdict, ['viable', 'not_viable', 'clear'], true)) dr_fail('Bad vote');
    $db->prepare("DELETE FROM discovered_route_votes WHERE sota_ref = ? AND run_id = ? AND route_no = ? AND callsign = ? AND verdict IN ('viable', 'not_viable')")
       ->execute([$ref, $run, $no, $callsign]);
    if ($verdict !== 'clear') {
        $db->prepare("INSERT INTO discovered_route_votes (sota_ref, run_id, route_no, start_lat, start_lon, callsign, planning_group_id, verdict)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$ref, $run, $no, $route['start_lat'], $route['start_lon'], $callsign, $current_group['id'] ?? null, $verdict]);
    }
    dr_out(['ok' => true, 'votes' => dr_votes($db, $ref, $run, $callsign, $current_group['id'] ?? 0)]);
}

if ($action === 'confirm') {
    $s = dr_own_summit($db, $_POST['summit_id'] ?? 0, $ref, $current_group);
    $db->prepare("INSERT INTO discovered_route_votes (sota_ref, run_id, route_no, start_lat, start_lon, callsign, planning_group_id, verdict)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed')")
       ->execute([$ref, $run, $no, $route['start_lat'], $route['start_lon'], $callsign, $current_group['id']]);
    summit_log_event($db, (int)$s['id'], 'Discovered route', 'Review required', 'Route ' . ($route['rank_shown'] ?: $no + 1) . ' confirmed');
    dr_out(['ok' => true, 'votes' => dr_votes($db, $ref, $run, $callsign, $current_group['id'])]);
}

if ($action === 'use') {
    $s = dr_own_summit($db, $_POST['summit_id'] ?? 0, $ref, $current_group);
    $gid = (int)$current_group['id'];
    $pub = dr_load_published($route['summit_file']);
    $c = $pub['candidates'][$no] ?? null;
    if (!$pub || !$c) dr_fail('Route file missing', 500);

    // The dashboard's own GPX file (never a shared library file), same folder as uploads
    $dir = __DIR__ . '/gpx_files';
    if (!is_dir($dir)) dr_fail('GPX folder missing', 500);
    $filename = 'disc_' . (int)$s['id'] . '_grp' . $gid . '_' . time() . '.gpx';
    $path = $dir . '/' . $filename;
    if (@file_put_contents($path, dr_route_gpx($pub, $c, (int)($route['rank_shown'] ?: $no + 1) - 1)) === false) dr_fail('Could not write the GPX file', 500);
    $g = analyze_gpx_track($path, null);
    if (!$g) { @unlink($path); dr_fail('Could not read the route back', 500); }

    summit_log_watch($db, (int)$s['id']);   // records the hike data and starting point changes when this request ends
    try {
        $db->beginTransaction();
        $old = $db->prepare("SELECT filename, file_path, from_global_library FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
        $old->execute([(int)$s['id'], $gid]);
        $old = $old->fetch();
        $db->prepare("DELETE FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?")->execute([(int)$s['id'], $gid]);
        $db->prepare("
            INSERT INTO gpx_tracks (
                summit_id, planning_group_id, filename, file_path,
                total_time, hiking_time, activation_time, rest_break_time,
                total_distance, hiking_distance, max_elevation, min_elevation,
                elevation_gain, elevation_loss, avg_speed, hiking_speed,
                num_points, summit_lat, summit_lon, using_api,
                activation_zone_polygon, activation_zone_method,
                use_for_hike_time, use_for_elevation, track_type, from_global_library
            ) VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, 1, 1, 'ascent', 0)
        ")->execute([
            (int)$s['id'], $gid, $filename, $path,
            $g['total_distance'], $g['hiking_distance'], $g['max_elevation'], $g['min_elevation'],
            $g['elevation_gain'], $g['elevation_loss'], $g['num_points'], $g['summit_lat'], $g['summit_lon'],
            $g['using_api'] ? 1 : 0, $g['activation_zone_polygon'], $g['activation_zone_method'],
        ]);
        // Fill in the hike data straight away (Chris, 2026-11-24); the panel shows "review required" until confirmed.
        $db->prepare("UPDATE summits SET trailhead_lat = ?, trailhead_lng = ?, trailhead_manual = 0,
                          hike_distance_mi = ?, hike_elevation_gain_ft = ?, gpx_source = 'discovered', gpx_opted_out = 0
                      WHERE id = ?")
           ->execute([$route['start_lat'], $route['start_lon'], round(2 * $g['total_distance'] * 0.621371, 2),
                      round($g['elevation_gain'] * 3.28084), (int)$s['id']]);
        $db->prepare("INSERT INTO discovered_route_votes (sota_ref, run_id, route_no, start_lat, start_lon, callsign, planning_group_id, verdict)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 'used')")
           ->execute([$ref, $run, $no, $route['start_lat'], $route['start_lon'], $callsign, $gid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        @unlink($path);
        dr_fail('Database error: ' . $e->getMessage(), 500);
    }
    // Only an earlier discovered route's file is removed. Uploaded tracks and library files are left on disk.
    if ($old && empty($old['from_global_library']) && strpos((string)$old['filename'], 'disc_') === 0 && is_file($old['file_path'])) {
        @unlink($old['file_path']);
    }
    $label = 'Discovered route ' . ($route['rank_shown'] ?: $no + 1) . ' (' . $route['start_name'] . ')';
    summit_log_event($db, (int)$s['id'], 'GPX track', $old ? 'Track present' : '', $label);
    logActivity($db, 'Discovered route used', $s['name'], $ref, $current_group['name'] ?? '');
    dr_out(['ok' => true]);
}

dr_fail('Unknown action');
