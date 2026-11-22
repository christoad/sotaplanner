<?php
/**
 * Trail Discovery test bench (God Mode only; linked from God Mode > Data Tools).
 *
 * Runs the BRouter spiral search (trail_discovery_lib.php) on summits that already have a community
 * track, and scores each result against that track so we can tune the method before it ever runs
 * on summits without hike data. Nothing here is visible to the public.
 */
require_once 'config.php';
require_once 'trail_discovery_lib.php';
require_once 'parking_lib.php';
session_start();
requireLogin();
session_write_close();

if (!isGodMode() && ($_SESSION['_god_mode_real_callsign'] ?? '') !== 'KI6CR') {
    header('Location: index.php');
    exit;
}

$db   = getDbConnection();
$me   = getCurrentCallsign();
$bkey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : '';

/** Test set: summits in the run's region with a community track (W6/CT first: Chris knows these as local summits). */
function td_test_set($db, $region = 'W6/CT') {
    $st = $db->prepare("SELECT sota_ref FROM global_gpx_tracks WHERE sota_ref LIKE ? AND filename IS NOT NULL ORDER BY sota_ref");
    $st->execute([$region . '-%']);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Regions with enough community tracks to test against (largest first). */
function td_test_regions($db, $min = 40) {
    return $db->query("SELECT SUBSTRING_INDEX(sota_ref, '-', 1) AS region, COUNT(*) AS n FROM global_gpx_tracks
                       WHERE filename IS NOT NULL GROUP BY region HAVING n >= " . (int)$min . " ORDER BY n DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Nearest mapped parking lot or trailhead (OpenStreetMap list on the BRouter box) to each start we found,
 * each gate-closed parking spot and the community track's start. Used for review and as a confidence signal.
 */
function td_parking_annotate(array &$d, array $pts) {
    foreach ($d['candidates'] as &$c) {
        $c['pk'] = pk_nearest($pts, $c['lat'], $c['lon'], null, 1000);
        $c['pk_t'] = pk_nearest($pts, $c['lat'], $c['lon'], 'T', 1000);
        if (!empty($c['closed'])) $c['closed']['pk'] = pk_nearest($pts, $c['closed']['lat'], $c['closed']['lon'], null, 1000);
    }
    unset($c);
    if (!empty($d['known']['start'])) $d['known']['pk'] = pk_nearest($pts, $d['known']['start'][0], $d['known']['start'][1], null, 1000);
}
/** Search radius for the parking list: the run's search area plus room for routes that bend outward. */
function td_parking_radius_m(array $d) {
    return (int)((($d['settings']['max_km'] ?? 8) + 2) * 1000);
}
/** Metres from a point to the nearest mapped parking or trailhead, capped at 5000 (5000 = none within 5 km). */
function td_parking_m(array $pts, $lat, $lon) {
    $n = pk_nearest($pts, $lat, $lon, null, 5000);
    return $n ? $n['d'] : 5000;
}

function td_run_settings($db, $run_id) {
    $st = $db->prepare("SELECT settings_json FROM trail_disc_runs WHERE id = ?");
    $st->execute([(int)$run_id]);
    $j = $st->fetchColumn();
    return $j === false ? null : td_clean_settings(json_decode($j, true) ?: []);
}

/* ───────── AJAX ───────── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    @set_time_limit(290);
    $act = $_GET['ajax'];
    try {
        if ($act === 'new_run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $set = td_clean_settings($in);
            $db->prepare("INSERT INTO trail_disc_runs (created_by, settings_json, note) VALUES (?, ?, ?)")
               ->execute([$me, json_encode($set), mb_substr(trim((string)($in['note'] ?? '')), 0, 200) ?: null]);
            echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId(), 'settings' => $set]);

        } elseif ($act === 'results') {
            $run = (int)($_GET['run'] ?? 0);
            $st = $db->prepare("SELECT * FROM trail_disc_results WHERE run_id = ? ORDER BY sota_ref");
            $st->execute([$run]);
            $w = json_decode((string)$db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'trail_disc_working'")->fetchColumn(), true);
            $working = ($w && (int)$w['run'] === $run)
                ? ['ref' => $w['ref'], 'secs' => time() - (int)$w['started'], 'done' => !empty($w['done'])] : null;
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'settings' => td_run_settings($db, $run), 'working' => $working]);

        } elseif ($act === 'one') {
            $run = (int)($_GET['run'] ?? 0);
            $ref = strtoupper(trim($_GET['ref'] ?? ''));
            $set = td_run_settings($db, $run);
            if (!$set) { echo json_encode(['ok' => false, 'error' => 'Unknown run']); exit; }
            // Tell any open copy of this page which summit is being searched right now
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('trail_disc_working', ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
               ->execute([json_encode(['run' => $run, 'ref' => $ref, 'started' => time()])]);
            $d = td_discover($ref, $set);
            if (!$d['ok'] && strpos($d['error'], 'Unknown summit') === 0) {   // typo or retired summit: say so, store nothing
                echo json_encode(['ok' => false, 'error' => $d['error'] . ' (not in the SOTA summit list)']); exit;
            }
            $row = ['run_id' => $run, 'sota_ref' => $ref, 'status' => 'error', 'error' => null, 'calls' => 0, 'probes_found' => 0,
                    'seconds' => 0, 'n_paved' => 0, 'n_dirt' => 0, 'best_tier' => null, 'best_dist_m' => null, 'best_gain_m' => null,
                    'best_time_min' => null, 'best_ring_km' => null, 'confidence' => null, 'known_dist_m' => null, 'known_gain_m' => null,
                    'known_time_min' => null, 'known_start_m' => null, 'known_overlap' => null, 'known_in_chosen' => null, 'known_in_alt' => null,
                    'known_shown_start_m' => null, 'known_alt_start_m' => null, 'best_parking_m' => null, 'known_parking_m' => null,
                    'verdict' => null, 'file' => null];
            if (!$d['ok']) {
                $row['error'] = mb_substr($d['error'], 0, 255);
            } else {
                $row['status'] = $d['chosen'] ? 'ok' : 'none';
                $row['calls'] = $d['stats']['calls'];
                $row['probes_found'] = $d['stats']['probes_found'];
                $row['seconds'] = $d['stats']['seconds'];
                foreach ($d['candidates'] as $c) $row[$c['cls'] === 'paved' ? 'n_paved' : 'n_dirt']++;
                if ($d['chosen']) {
                    $b = $d['candidates'][$d['chosen'][0]];
                    $row['best_tier'] = $b['cls']; $row['best_dist_m'] = $b['dist_m']; $row['best_gain_m'] = $b['gain_m'];
                    $row['best_time_min'] = $b['time_min']; $row['best_ring_km'] = $b['first_ring_km']; $row['confidence'] = $d['confidence'] ?? null;
                }
                // Community track for the same summit, if there is one
                $st = $db->prepare("SELECT filename FROM global_gpx_tracks WHERE sota_ref = ? LIMIT 1");
                $st->execute([$ref]);
                $fn = $st->fetchColumn();
                $known = null;
                if ($fn) {
                    $pts = brouter_read_gpx_points(__DIR__ . '/gpx_files/global/' . basename($fn), 2000);
                    $known = $pts ? td_compare_known($d, $pts) : null;
                }
                if ($known) {
                    $row['known_dist_m'] = $known['dist_m']; $row['known_gain_m'] = $known['gain_m']; $row['known_time_min'] = $known['time_min'];
                    $row['known_start_m'] = $known['start_m']; $row['known_overlap'] = $known['overlap'];
                    $row['known_in_chosen'] = $known['in_chosen'] ? 1 : 0; $row['verdict'] = $known['verdict'];
                    $row['known_in_alt'] = !empty($known['in_chosen_alt']) ? 1 : 0;
                    $row['known_shown_start_m'] = $known['shown_start_m'] ?? null; $row['known_alt_start_m'] = $known['alt_start_m'] ?? null;
                }
                $d['known'] = $known;
                // Mapped parking near our top start and near the community start (null = list not reachable)
                $pk = pk_near($d['summit']['lat'], $d['summit']['lon'], td_parking_radius_m($d) + 3000);
                if ($pk['ok']) {
                    if ($d['chosen']) { $b = $d['candidates'][$d['chosen'][0]]; $row['best_parking_m'] = td_parking_m($pk['points'], $b['lat'], $b['lon']); }
                    if ($known) $row['known_parking_m'] = td_parking_m($pk['points'], $known['start'][0], $known['start'][1]);
                }
                $row['file'] = td_save_file($run, $ref, $d);
                if (!$row['file']) $row['error'] = 'Could not write the route file to ' . td_store_dir();
            }
            $cols = array_keys($row);
            $sql = "REPLACE INTO trail_disc_results (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
            $db->prepare($sql)->execute(array_values($row));
            $db->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = 'trail_disc_working'")
               ->execute([json_encode(['run' => $run, 'ref' => $ref, 'started' => time(), 'done' => true])]);
            $st = $db->prepare("SELECT * FROM trail_disc_results WHERE run_id = ? AND sota_ref = ?");
            $st->execute([$run, $ref]);
            echo json_encode(['ok' => true, 'row' => $st->fetch(PDO::FETCH_ASSOC)]);

        } elseif ($act === 'detail') {
            $st = $db->prepare("SELECT file FROM trail_disc_results WHERE run_id = ? AND sota_ref = ?");
            $st->execute([(int)($_GET['run'] ?? 0), strtoupper(trim($_GET['ref'] ?? ''))]);
            $f = $st->fetchColumn();
            $d = $f ? td_load_file($f) : null;
            if ($d) {
                $s = $d['summit'];
                $d['zone'] = td_zone_rings($s['ref'], $s['lat'], $s['lon'], $s['alt_ft'] ?? null);
                // Parking lots and trailheads around the summit (computed now, so older runs show them too)
                $pk = pk_near($s['lat'], $s['lon'], td_parking_radius_m($d));
                td_parking_annotate($d, $pk['points']);
                $d['parking'] = array_map(fn($p) => [$p['k'], $p['lat'], $p['lon'], $p['name'], $p['access'], $p['fee'], $p['type']], $pk['points']);
                $d['parking_error'] = $pk['error'];
                $d['parking_km'] = td_parking_radius_m($d) / 1000;
            }
            echo json_encode($d ? ['ok' => true, 'd' => $d] : ['ok' => false, 'error' => 'No saved result for that summit in this run']);

        } elseif ($act === 'server') {
            // Live status of the BRouter box (status.json, rewritten every 15 s there) plus which summit is being searched now, in any run
            $st = null; $err = null;
            if (defined('BROUTER_URL') && defined('BROUTER_API_KEY')) {
                $ch = curl_init(preg_replace('#/brouter/?$#', '', BROUTER_URL) . '/status.json');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Api-Key: ' . BROUTER_API_KEY], CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6]);
                $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                $st = $code === 200 ? json_decode((string)$body, true) : null;
                if (!$st) $err = $body === false ? 'could not connect' : 'HTTP ' . $code;
            } else { $err = 'BROUTER_URL / BROUTER_API_KEY are not set'; }
            $w = json_decode((string)$db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'trail_disc_working'")->fetchColumn(), true);
            $working = $w ? ['run' => (int)$w['run'], 'ref' => $w['ref'], 'secs' => time() - (int)$w['started'], 'done' => !empty($w['done'])] : null;
            if ($st) $st['age_s'] = time() - (int)$st['updated'];
            echo json_encode(['ok' => (bool)$st, 'status' => $st, 'error' => $err, 'working' => $working]);

        } elseif ($act === 'evidence') {
            // Community evidence from neighboring summits' tracks for a saved result (for tuning the confidence score)
            $st = $db->prepare("SELECT file FROM trail_disc_results WHERE run_id = ? AND sota_ref = ?");
            $st->execute([(int)($_GET['run'] ?? 0), strtoupper(trim($_GET['ref'] ?? ''))]);
            $f = $st->fetchColumn();
            $d = $f ? td_load_file($f) : null;
            if (!$d) { echo json_encode(['ok' => false, 'error' => 'No saved result']); exit; }
            $s = $d['summit'];
            td_community_evidence($db, $d['candidates'], $s['lat'], $s['lon'], $s['ref'], __DIR__ . '/gpx_files/global');
            $out = array_map(fn($c) => ['ev_tracks' => $c['ev_tracks'], 'ev_start_m' => $c['ev_start_m'], 'ev_trail_pct' => $c['ev_trail_pct'],
                                        'closed_ev_start_m' => $c['closed']['ev_start_m'] ?? null], $d['candidates']);
            echo json_encode(['ok' => true, 'ref' => $s['ref'], 'cands' => $out]);

        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage() . ' (has db_migrate.php been run?)']);
    }
    exit;
}

/* ───────── page ───────── */
$runs = [];
$tablesReady = true;
try {
    $runs = $db->query("SELECT r.id, r.created_at, r.settings_json, r.note, COUNT(x.id) AS done
                        FROM trail_disc_runs r LEFT JOIN trail_disc_results x ON x.run_id = r.id
                        GROUP BY r.id ORDER BY r.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $tablesReady = false; }
$curRun = (int)($_GET['run'] ?? ($runs[0]['id'] ?? 0));
$curSet = td_run_settings($db, $curRun) ?: td_default_settings();
$testSet = td_test_set($db, $curSet['region']);
$regions = $tablesReady ? td_test_regions($db) : [];
function tdp_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$def = td_default_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Trail Discovery | SOTA Planner</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F6F3; --bg-2:#EFEDE8; --bg-3:#E5E2DA; --ink:#1C1B19; --ink-2:#4A4844; --ink-3:#8C8A86; --ink-4:#B8B5B0;
  --accent:oklch(52% 0.13 50); --accent-bg:oklch(96% 0.04 65); --accent-border:oklch(84% 0.08 65);
  --green:oklch(52% 0.13 155); --green-bg:oklch(95% 0.04 155); --orange:oklch(62% 0.14 58); --orange-bg:oklch(96% 0.05 58);
  --red:oklch(52% 0.16 22); --red-bg:oklch(96% 0.04 22); --blue:oklch(52% 0.12 240); --blue-bg:oklch(95% 0.04 240);
  --gray-badge:oklch(55% 0.02 200); --gray-bg:oklch(93% 0.01 200);
  --surface:#FFFFFF; --border:#E5E2DA; --border-2:#D4D0C8;
  --font-sans:'DM Sans',system-ui,sans-serif; --font-mono:'DM Mono','Courier New',monospace;
  --r-sm:4px; --r-md:8px; --r-lg:12px;
  --shadow-sm:0 1px 3px rgba(28,27,25,0.07),0 1px 2px rgba(28,27,25,0.05);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-sans);background:var(--bg);color:var(--ink);line-height:1.5;min-height:100vh;-webkit-font-smoothing:antialiased}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);height:56px;display:flex;align-items:center;padding:0 2rem;gap:1rem;position:sticky;top:0;z-index:100}
.topbar a.logo{display:flex;align-items:center;gap:.75rem;text-decoration:none;color:var(--ink);font-weight:600;font-size:.95rem}
.topbar nav a{color:var(--ink-3);font-size:.875rem;font-weight:500;padding:.5rem .75rem;border-radius:var(--r-sm);text-decoration:none}
.topbar nav a:hover{color:var(--ink);background:var(--bg-2)}
.page{padding:2rem;max-width:1400px;margin:0 auto}
h1{font-size:1.4rem;letter-spacing:-.01em;margin-bottom:.25rem}
h2{font-size:1rem;margin-bottom:.75rem}
.sub{color:var(--ink-3);font-size:.9rem;margin-bottom:1.25rem;max-width:900px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1rem}
.row{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end}
label{display:block;font-size:.72rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem}
select,input[type=text]{padding:.5rem .7rem;border:1px solid var(--border);border-radius:var(--r-md);font-family:var(--font-sans);font-size:.85rem;background:var(--surface);color:var(--ink)}
.btn{height:36px;padding:0 1rem;border-radius:var(--r-md);border:none;background:var(--ink);color:#fff;font-family:var(--font-sans);font-size:.85rem;font-weight:500;cursor:pointer;white-space:nowrap}
.btn:hover{background:var(--ink-2)} .btn:disabled{opacity:.45;cursor:default}
.btn-ghost{background:transparent;color:var(--ink-2);border:1px solid var(--border-2)} .btn-ghost:hover{background:var(--bg-2)}
.btn-sm{height:28px;padding:0 .7rem;font-size:.78rem}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem}
.tile{background:var(--bg);border:1px solid var(--border);border-radius:var(--r-md);padding:.7rem .85rem}
.tile .k{font-size:.68rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em}
.tile .v{font-size:1.35rem;font-weight:600;letter-spacing:-.01em}
.tile .d{font-size:.75rem;color:var(--ink-3)}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);padding:.45rem .5rem;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:.5rem;border-bottom:1px solid var(--bg-3);vertical-align:top}
td.num,th.num{text-align:right;font-family:var(--font-mono);white-space:nowrap}
tr.click{cursor:pointer} tr.click:hover td{background:var(--bg)} tr.sel td{background:var(--accent-bg)}
.d2{color:var(--ink-3);font-size:.72rem;display:block}
.pill{display:inline-block;font-size:.64rem;font-weight:600;border-radius:var(--r-sm);padding:.1rem .4rem;text-transform:uppercase;letter-spacing:.05em;background:var(--gray-bg);color:var(--gray-badge);white-space:nowrap}
.p-match{background:var(--green-bg);color:var(--green)} .p-partial{background:var(--orange-bg);color:var(--orange)} .p-miss{background:var(--red-bg);color:var(--red)}
.p-paved{background:var(--blue-bg);color:var(--blue)} .p-dirt{background:var(--orange-bg);color:var(--orange)} .p-chosen{background:var(--ink);color:#fff}
.c-high{color:var(--green);font-weight:600} .c-med{color:var(--orange);font-weight:600} .c-low{color:var(--red);font-weight:600}
.sw{display:inline-block;width:11px;height:11px;border-radius:3px;margin-right:.4rem;vertical-align:-1px}
.msg{padding:.7rem 1rem;border-radius:var(--r-md);margin-bottom:1rem;font-size:.85rem}
.msg-info{background:var(--accent-bg);color:var(--ink-2);border:1px solid var(--accent-border)}
.msg-error{background:var(--red-bg);color:var(--red);border:1px solid oklch(85% 0.08 22)}
.note{font-size:.78rem;color:var(--ink-3);margin-top:.6rem}
.review{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:1rem;align-items:start;margin-bottom:1rem;scroll-margin-top:68px}
@media (max-width:1000px){.review{grid-template-columns:1fr}}
#map{height:680px;border-radius:var(--r-lg);border:1px solid var(--border)}
.review-side{max-height:740px;overflow:auto;margin-bottom:0}
.revnav{display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;font-size:.78rem;color:var(--ink-3)}
.revnav .grow{flex:1}
#resultsCard h2 .d2{display:inline;margin-left:.4rem;font-weight:400}
.legend{display:flex;gap:1rem;flex-wrap:wrap;font-size:.75rem;color:var(--ink-3);margin-top:.5rem}
.legend span{display:inline-flex;align-items:center;gap:.35rem}
.dot{width:9px;height:9px;border-radius:50%;display:inline-block}
.bar{height:8px;background:var(--bg-3);border-radius:4px;overflow:hidden;margin-top:.4rem}
.bar i{display:block;height:100%;background:var(--accent);width:0}
.reasons{font-size:.72rem;color:var(--ink-3);margin-top:.2rem}
.closed{font-size:.78rem;color:var(--orange);margin-top:.25rem}
.closed a{color:inherit}
.gloss{margin-top:.9rem;border-top:1px solid var(--bg-3);padding-top:.7rem;font-size:.82rem;color:var(--ink-2)}
.gloss summary{cursor:pointer;font-weight:600;color:var(--ink);font-size:.85rem}
.gloss h3{font-size:.72rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em;margin:1rem 0 .4rem}
.gloss p{max-width:900px;margin-bottom:.4rem}
.gloss dl{display:grid;grid-template-columns:minmax(140px,200px) 1fr;gap:.35rem 1rem;max-width:1000px}
.gloss dt{font-weight:600;color:var(--ink)} .gloss dd{margin:0}
@media (max-width:700px){.gloss dl{grid-template-columns:1fr}.gloss dd{margin-bottom:.4rem}}
.srv{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem 1.1rem;font-size:.8rem;color:var(--ink-2);padding:.65rem 1rem;margin-bottom:1rem}
.srv b{font-weight:600;color:var(--ink)} .srv .lbl{font-size:.68rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.06em}
.srv .led{width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:.35rem;vertical-align:0}
.srv .warn{color:var(--red);font-weight:600}
.pk{font-size:.74rem;color:var(--ink-3);margin-top:.15rem}
.pk b{color:var(--blue);font-weight:600} .pk b.t{color:#15803d} .pk.none{color:var(--ink-4)}
.mapbar{display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:var(--ink-2);margin:0 0 .5rem}
.mapbar input{accent-color:var(--ink)}
</style>
</head>
<body>
<nav class="topbar">
    <a class="logo" href="index.php"><img src="sota-planner-logo.svg" width="32" height="32" alt=""><span>SOTAplanner</span></a>
    <nav><a href="god_mode.php?tab=data">God Mode</a><a href="brouter_paths.php">Path Finder</a><a href="brouter_test.php">Test bench</a></nav>
</nav>
<div class="page">
    <h1>Trail Discovery</h1>
    <p class="sub">Searches outward from each summit in rings of probe points, routes every probe to the summit with our BRouter server, and reads each route from the top down until it reaches a road you can drive. Paved roads come first, dirt roads only when no paved start exists. Each test run covers one region's summits that already have a community track (this run: <?= tdp_h($curSet['region']) ?>, <?= count($testSet) ?> summits) and scores each result against that track. God Mode only.</p>

<?php if (!$tablesReady): ?>
    <div class="msg msg-error">The trail discovery tables do not exist yet. Run db_migrate.php first.</div>
<?php else: ?>
    <div class="card srv" id="srv"><span class="lbl">BRouter server</span><span>checking...</span></div>
    <div class="card">
        <div class="row">
            <div><label>Test run</label>
                <select id="runSel">
                    <?php if (!$runs): ?><option value="0">No runs yet</option><?php endif; ?>
                    <?php foreach ($runs as $r): $s = json_decode($r['settings_json'], true); ?>
                        <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $curRun ? 'selected' : '' ?>>
                            Run <?= (int)$r['id'] ?>: <?= tdp_h(($s['region'] ?? 'W6/CT') . ', ' . ($s['max_km'] ?? '?') . ' km, ' . ($s['spacing_km'] ?? '?') . ' km spacing, ' . (($s['stop'] ?? '') === 'auto2' ? 'early stop' : 'full search') . ', ' . (($s['pick'] ?? '') === 'mixed' ? 'quickest 3' : 'paved first')) ?>
                            (<?= (int)$r['done'] ?> done<?= $r['note'] ? ', ' . tdp_h($r['note']) : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select></div>
            <button class="btn" id="goBatch" type="button" <?= $runs ? '' : 'disabled' ?>>Run remaining test summits</button>
            <button class="btn btn-ghost" id="goPause" type="button" disabled>Pause</button>
            <div style="margin-left:auto"><label>One summit (any)</label><input type="text" id="oneRef" value="<?= tdp_h(strtoupper(trim($_GET['ref'] ?? ''))) ?>" placeholder="<?= tdp_h($curSet['region']) ?>-001" style="width:130px"></div>
            <button class="btn btn-ghost" id="goOne" type="button" <?= $runs ? '' : 'disabled' ?>>Run it</button>
        </div>
        <div class="bar"><i id="bar"></i></div>
        <p class="note" id="batchNote"></p>
        <details style="margin-top:.75rem">
            <summary style="cursor:pointer;font-size:.85rem;font-weight:600">Start a new run with different settings</summary>
            <div class="row" style="margin-top:.75rem">
                <div><label>Search out to</label><select id="nMax"><?php foreach ([4, 6, 8, 10, 12] as $v): ?><option value="<?= $v ?>" <?= $v == $def['max_km'] ? 'selected' : '' ?>><?= $v ?> km (<?= round($v / 1.609, 1) ?> mi)</option><?php endforeach; ?></select></div>
                <div><label>Probe spacing</label><select id="nSp"><?php foreach ([0.75, 1.0, 1.5] as $v): ?><option value="<?= $v ?>" <?= $v == $def['spacing_km'] ? 'selected' : '' ?>><?= $v ?> km</option><?php endforeach; ?></select></div>
                <div><label>Stop rule</label><select id="nStop"><option value="full">Search every ring</option><option value="auto2">Stop after 2 rings with nothing new</option></select></div>
                <div><label>Routes shown</label><select id="nPick"><option value="paved_first">Paved starts first, dirt only if no paved</option><option value="mixed">Quickest 3, always one paved if found</option></select></div>
                <div><label>Region</label><select id="nRegion"><?php foreach ($regions as $g): ?><option value="<?= tdp_h($g['region']) ?>" <?= $g['region'] === $curSet['region'] ? 'selected' : '' ?>><?= tdp_h($g['region']) ?> (<?= (int)$g['n'] ?> tracks)</option><?php endforeach; ?></select></div>
                <div><label>Note</label><input type="text" id="nNote" placeholder="optional" style="width:220px"></div>
                <button class="btn" id="goNew" type="button">Create run</button>
            </div>
            <p class="note">Rings sit at 0.75, 1.5, 2.5, 4, 6, 8, 10 and 12 km from the summit, up to the limit chosen. Each ring gets one probe per spacing distance around its circle (8 to 32 probes).</p>
        </details>
    </div>

    <div id="msg"></div>
    <div class="card"><h2>Scoreboard</h2><div class="tiles" id="tiles"></div><p class="note" id="ringNote"></p>
        <details class="gloss">
            <summary>What the numbers mean</summary>
            <h3>How a summit is tested</h3>
            <p>Each test summit already has a <b>community track</b> (a real GPX recording from the SOTA Mapping Project). We search for routes without looking at it, then compare our routes with the <b>uphill part</b> of that track: from where they started to the point closest to the summit (tracks recorded top-down are flipped).</p>
            <dl>
                <dt>Overlap</dt><dd>How much of the community's uphill track runs along one of our routes: the share of their track points that lie within 50 m of our route line. 100% means every part of their hike is on our route. 50% means half of their hike follows our route and half goes another way (a different trail, a shortcut, or a different start). The route compared is whichever of ours fits best, judged mainly by overlap and then by how close the starts are.</dd>
                <dt>Start off</dt><dd>The straight-line distance between where the community track starts and where that best-fitting route of ours starts. Under 400 m means we found the same parking spot or trailhead, give or take.</dd>
                <dt>Match</dt><dd>Overlap 70% or more and the starts within 400 m: we found the route people actually use.</dd>
                <dt>Partial</dt><dd>Not a match, but overlap of at least 40% or a start within 1 km: close, often the same trail from a different parking spot, or a different trail for part of the way.</dd>
                <dt>Miss</dt><dd>Anything less: our routes and theirs are different hikes, or we found nothing near their route.</dd>
                <dt>Not in top 3</dt><dd>We found the community's route, but it is not one of the three routes a user would be shown.</dd>
            </dl>
            <h3>Scoreboard tiles</h3>
            <dl>
                <dt>Shown to users</dt><dd>Share of summits where the community's route (match or partial) is among the three routes we would show. <b>With the other rule</b>: the same if we picked the three routes the other way (paved starts first, or quickest three).</dd>
                <dt>No start found</dt><dd>Summits where no probe reached a drivable road within the search distance. These would stay in Needs Research.</dd>
                <dt>Start off by</dt><dd>The middle value (median) of Start off across all summits.</dd>
                <dt>Hike time error</dt><dd>On matches only: how far our uphill time is from the community track's uphill time, both worked out with the same SOTAplanner pace formula from distance and climb (median, in percent).</dd>
                <dt>Ours much shorter</dt><dd>Summits where our quickest route is more than 20% quicker than the community's. Usually a warning sign: a private road, an unmapped gate or a route people avoid for a reason.</dd>
                <dt>Per summit</dt><dd>Median search time and number of BRouter routing requests per summit.</dd>
                <dt>Confidence high / medium / low</dt><dd>Summits sorted by their summit confidence (below). The small line underneath checks it: the share of those summits where one of our three shown starts (or its gate-closed parking spot) is within 400 m of where the community started.</dd>
                <dt>Top start at parking</dt><dd>Share of summits where our best start is within 150 m of a parking lot or trailhead in the OpenStreetMap list.</dd>
                <dt>People park at mapped lot</dt><dd>The same check for the community track's start: how often real hikers start at a mapped lot or trailhead.</dd>
                <dt>Mapped lot we missed</dt><dd>Summits where people start at a mapped lot (within 150 m) but none of our three shown starts is within 400 m of where they start. The clearest place to improve.</dd>
                <dt>Ring line</dt><dd>How far from the summit the winning route's start was first found. Tells us how far out the search really needs to go.</dd>
            </dl>
            <h3>Table and summit detail</h3>
            <dl>
                <dt>Ours / Community</dt><dd>Uphill time, distance and climb of our best route and of the community track.</dd>
                <dt>Conf. (summit)</dt><dd>The chance that the three routes we show include the way people actually go. 70 and up = high (hike data could be filled in for users), 40 to 69 = medium (shown, review required), under 40 = low (stays in Needs Research).</dd>
                <dt>Conf. (each route)</dt><dd>That route's own chance of being one people use, from: how quick it is compared with the others (the strongest signal), how many probes led to it, whether another summit's community track starts there or follows it, paved or dirt start, an off-trail finish, and a hike over 4 hours. The reasons are listed under each route.</dd>
                <dt>Routes (p / d)</dt><dd>Number of distinct starting points found on paved (p) and dirt (d) roads.</dd>
                <dt>Probes and rings</dt><dd>Search points placed in circles (rings) 0.75 to 12 km from the summit. Each is routed to the summit; the route is then read from the top down until it reaches a road you can drive. Green probe: found a start. Gray: stayed on trail the whole way. Red: no route.</dd>
                <dt>Paved / dirt</dt><dd>The road type where the drive ends. Paved starts are preferred; dirt ones are kept when no paved start exists or as alternatives.</dd>
                <dt>If the dirt road is closed</dt><dd>For a dirt start: the hike from the nearest paved road beyond it, in case a gate is shut (dashed line and square marker on the map).</dd>
                <dt>Off trail to the summit</dt><dd>When no mapped path reaches the top, the last stretch is a straight line from the end of the path to the summit (red dashed). It is included in distance and time.</dd>
                <dt>First seen X km out</dt><dd>The ring where a probe first led to that start.</dd>
                <dt>Mapped parking / trailhead X m away</dt><dd>Distance from a start to the nearest parking lot or trailhead in the OpenStreetMap list (blue P and green T on the map).</dd>
            </dl>
        </details>
    </div>

    <div class="review" id="review">
        <div>
            <label class="mapbar" style="text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" id="pkToggle"> Show parking lots and trailheads <span id="pkCount" class="d2" style="display:inline"></span></label>
            <div id="map"></div>
            <div class="legend">
                <span><svg width="12" height="11" viewBox="-11 -12 22 20"><path d="M 0,-11 10,7 -10,7 Z" fill="#1C1B19"/></svg>Summit</span>
                <span><i class="sw" style="background:rgba(147,51,234,.3);border:2px solid #9333ea"></i>Activation zone</span>
                <span><i class="sw" style="background:#1C1B19"></i>Community track (uphill part)</span>
                <span><i class="sw" style="background:#fff;border:2px dashed #dc2626"></i>Off trail to the summit</span>
                <span><i class="dot" style="background:#16a34a"></i>Probe found a start</span>
                <span><i class="dot" style="background:#94a3b8"></i>Probe stayed on trail</span>
                <span><i class="dot" style="background:#dc2626"></i>Probe failed</span>
                <span><i class="sw" style="background:#1e40af"></i>Parking (P)</span>
                <span><svg width="12" height="12" viewBox="-9 -9 18 18"><path d="M 0,-8 8,0 0,8 -8,0 Z" fill="#15803d"/></svg>Trailhead (T)</span>
                <span>Parking and trailheads: &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener" style="color:inherit">OpenStreetMap</a> contributors</span>
            </div>
        </div>
        <div class="card review-side" id="detail"><h2>Review a summit</h2><p class="note">Pick a summit from the list below. Its map shows here on the left and its routes on this side, so you can check them together. Use Previous and Next to step through the list.</p></div>
    </div>
    <div class="card" id="resultsCard"><p class="note">No results yet.</p></div>
<?php endif; ?>
</div>

<script>
const TEST_SET = <?= json_encode($testSet) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const mi = m => m == null ? '-' : (m / 1609.344).toFixed(2);
const ft = m => m == null ? '-' : Math.round(m * 3.28084).toLocaleString();
const fmtT = m => m == null ? '-' : (m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm');
const COLORS = ['#ea580c','#2563eb','#9333ea','#db2777','#0891b2','#ca8a04','#16a34a','#475569','#dc2626','#0d9488','#7c3aed','#be123c'];
let run = +($('runSel') ? $('runSel').value : 0), rows = [], selRef = null, running = false, runSettings = null;

/* Every request ends in an answer or a clear message: 5 minute limit (a summit search may take up to about 4.5),
   and a page that is not JSON (signed out, PHP error) is reported instead of failing silently. */
async function api(q, body) {
  const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), 300000);
  try {
    const opt = body ? {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body), signal: ctl.signal} : {signal: ctl.signal};
    const res = await fetch('?ajax=' + q, opt);
    const text = await res.text();
    try { return JSON.parse(text); }
    catch (e) { return {ok:false, error:'The server sent something unexpected (HTTP ' + res.status + '). If you were signed out, reload the page. Start of the answer: ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160)}; }
  } catch (e) {
    return {ok:false, error: e.name === 'AbortError' ? 'No answer after 5 minutes. The BRouter server may be down or very busy.' : 'Request failed: ' + e.message};
  } finally { clearTimeout(timer); }
}
function msg(h, cls) { $('msg').innerHTML = h ? '<div class="msg ' + (cls || 'msg-info') + '">' + h + '</div>' : ''; }
function confCls(c, route) { return c == null ? '' : route ? (c >= 40 ? 'c-high' : c >= 15 ? 'c-med' : 'c-low') : (c >= 70 ? 'c-high' : c >= 40 ? 'c-med' : 'c-low'); }
const bandOf = c => c == null ? null : c >= 70 ? 'high' : c >= 40 ? 'medium' : 'low';
function median(a) { if (!a.length) return null; const s = [...a].sort((x, y) => x - y), m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; }

/* ───────── scoreboard and table ───────── */
function renderAll() {
  const tested = rows.filter(r => TEST_SET.includes(r.sota_ref));
  const withKnown = rows.filter(r => r.verdict);
  const c = v => withKnown.filter(r => r.verdict === v).length;
  const none = rows.filter(r => r.status === 'none').length, errs = rows.filter(r => r.status === 'error').length;
  const pct = n => withKnown.length ? Math.round(100 * n / withKnown.length) + '%' : '-';
  const inChosen = withKnown.filter(r => +r.known_in_chosen === 1 && r.verdict !== 'miss').length;
  const altRows = withKnown.filter(r => r.known_in_alt !== null && r.known_in_alt !== undefined);
  const inAlt = altRows.filter(r => +r.known_in_alt === 1 && r.verdict !== 'miss').length;
  const pickName = (runSettings && runSettings.pick === 'mixed') ? 'paved first' : 'quickest 3';
  const startM = median(withKnown.filter(r => r.known_start_m != null).map(r => +r.known_start_m));
  const tErr = median(withKnown.filter(r => r.verdict === 'match' && r.best_time_min && r.known_time_min).map(r => Math.abs(r.best_time_min - r.known_time_min) / r.known_time_min * 100));
  const secs = median(rows.filter(r => r.status !== 'error').map(r => +r.seconds));
  const calls = median(rows.filter(r => r.status !== 'error').map(r => +r.calls));
  const shorter = withKnown.filter(r => r.best_time_min && r.known_time_min && r.best_time_min < 0.8 * r.known_time_min).length;
  const tile = (k, v, d) => '<div class="tile"><div class="k">' + k + '</div><div class="v">' + v + '</div><div class="d">' + (d || '') + '</div></div>';
  $('tiles').innerHTML =
    tile('Done', rows.length, tested.length + ' of ' + TEST_SET.length + ' test summits') +
    tile('Match', c('match'), pct(c('match')) + ' found the community route') +
    tile('Partial', c('partial'), pct(c('partial')) + ' close but not the same') +
    tile('Miss', c('miss'), pct(c('miss')) + ' different route or none') +
    tile('Shown to users', pct(inChosen), 'community route is in our top 3') +
    (altRows.length ? tile('With the other rule', pct(inAlt), 'top 3 if we used ' + pickName) : '') +
    tile('No start found', none, errs ? errs + ' errors' : 'no errors') +
    tile('Start off by', startM == null ? '-' : Math.round(startM) + ' m', 'median, best match') +
    tile('Hike time error', tErr == null ? '-' : Math.round(tErr) + '%', 'median, matches only') +
    tile('Ours much shorter', shorter, 'over 20% quicker than the track') +
    tile('Per summit', secs == null ? '-' : secs + ' s', calls == null ? '' : calls + ' BRouter calls (median)') +
    ['high', 'medium', 'low'].map(b => {   // does the summit confidence mean what it says?
      const inB = withKnown.filter(r => bandOf(r.confidence == null ? null : +r.confidence) === b);
      const meas = inB.filter(r => r.known_shown_start_m != null);
      const ok = meas.filter(r => +r.known_shown_start_m <= 400).length;
      return tile('Confidence ' + b, inB.length, meas.length ? Math.round(100 * ok / meas.length) + '% show where people park' : (inB.length ? 'rerun to measure' : 'no summits'));
    }).join('') + (() => {   // mapped parking and trailheads from OpenStreetMap (measured on runs made after 2026-11-21)
      const PK = 150;
      const ours = rows.filter(r => r.best_parking_m != null), theirs = withKnown.filter(r => r.known_parking_m != null);
      if (!ours.length && !theirs.length) return tile('At mapped parking', '-', 'rerun to measure');
      const oursAt = ours.filter(r => +r.best_parking_m <= PK).length, theirsAt = theirs.filter(r => +r.known_parking_m <= PK).length;
      const missed = theirs.filter(r => +r.known_parking_m <= PK && r.known_shown_start_m != null && +r.known_shown_start_m > 400).length;
      return tile('Top start at parking', ours.length ? Math.round(100 * oursAt / ours.length) + '%' : '-', oursAt + ' of ' + ours.length + ' within ' + PK + ' m of a mapped lot or trailhead') +
             tile('People park at mapped lot', theirs.length ? Math.round(100 * theirsAt / theirs.length) + '%' : '-', theirsAt + ' of ' + theirs.length + ' community starts within ' + PK + ' m') +
             tile('Mapped lot we missed', missed, 'community parks at a mapped lot, none of our top 3 starts there');
    })();
  // Where were the winning starts first seen? Tells us how far out we really need to search.
  const ringCounts = {};
  rows.filter(r => r.best_ring_km != null).forEach(r => { const k = (+r.best_ring_km).toString(); ringCounts[k] = (ringCounts[k] || 0) + 1; });
  const ks = Object.keys(ringCounts).sort((a, b) => a - b), tot = ks.reduce((s, k) => s + ringCounts[k], 0);
  let acc = 0;
  $('ringNote').textContent = tot ? 'Ring where the top route was first found: ' + ks.map(k => { acc += ringCounts[k]; return k + ' km: ' + ringCounts[k] + ' (' + Math.round(100 * acc / tot) + '% by here)'; }).join(', ') : '';

  if (!rows.length) { $('resultsCard').innerHTML = '<p class="note">No results in this run yet.</p>'; return; }
  let h = '<h2>Summits in this run<span class="d2">' + rows.length + ', click one to review it above</span></h2><table><tr><th>Summit</th><th>Result</th><th class="num">Overlap</th><th class="num">Start off</th><th class="num">Ours</th><th class="num">Community</th><th class="num" title="Summit confidence: chance the routes shown include the way people actually go">Conf.</th><th class="num">Routes</th></tr>';
  rows.forEach(r => {
    const v = r.verdict ? '<span class="pill p-' + r.verdict + '">' + r.verdict + '</span>' : (r.status === 'error' ? '<span class="pill p-miss">error</span>' : '<span class="pill">no track</span>');
    const ours = r.best_time_min ? fmtT(+r.best_time_min) + '<span class="d2">' + mi(r.best_dist_m) + ' mi, ' + ft(r.best_gain_m) + ' ft</span>' : (r.status === 'none' ? '<span class="d2">none found</span>' : '-');
    const known = r.known_time_min ? fmtT(+r.known_time_min) + '<span class="d2">' + mi(r.known_dist_m) + ' mi, ' + ft(r.known_gain_m) + ' ft</span>' : '-';
    h += '<tr class="click' + (r.sota_ref === selRef ? ' sel' : '') + '" data-ref="' + esc(r.sota_ref) + '"><td><strong>' + esc(r.sota_ref) + '</strong>' +
      (r.best_tier ? ' <span class="pill p-' + r.best_tier + '">' + r.best_tier + '</span>' : '') + (r.error ? '<span class="d2">' + esc(r.error) + '</span>' : '') + '</td>' +
      '<td>' + v + (r.verdict && +r.known_in_chosen !== 1 && r.verdict !== 'miss' ? '<span class="d2">not in top 3</span>' : '') + '</td>' +
      '<td class="num">' + (r.known_overlap != null ? r.known_overlap + '%' : '-') + '</td>' +
      '<td class="num">' + (r.known_start_m != null ? (+r.known_start_m).toLocaleString() + ' m' : '-') + '</td>' +
      '<td class="num">' + ours + '</td><td class="num">' + known + '</td>' +
      '<td class="num ' + confCls(r.confidence) + '">' + (r.confidence ?? '-') + '</td>' +
      '<td class="num">' + r.n_paved + 'p ' + r.n_dirt + 'd<span class="d2">' + r.seconds + ' s</span></td></tr>';
  });
  h += '</table><p class="note">Overlap: share of the community track\'s uphill part within 50 m of our closest route. Start off: distance between their starting point and ours. Match = 70%+ overlap and starts within 400 m. Routes: paved (p) and dirt (d) starting points found. Click a row for the map.</p>';
  $('resultsCard').innerHTML = h;
  $('resultsCard').querySelectorAll('tr.click').forEach(tr => tr.onclick = () => { showDetail(tr.dataset.ref); $('review').scrollIntoView({behavior:'smooth', block:'start'}); });
}

/* Live progress: works whoever started the run (this page, another tab, or a script). */
let pollTimer = null;
function showProgress(w) {
  const done = rows.filter(r => TEST_SET.includes(r.sota_ref) && r.status !== 'error').length;
  $('bar').style.width = Math.round(100 * done / Math.max(1, TEST_SET.length)) + '%';
  if (running) return;   // this page's own Run button is driving the note
  const secs = median(rows.filter(r => r.status !== 'error').map(r => +r.seconds)) || 30;
  const left = TEST_SET.length - done;
  const active = w && !w.done && w.secs < 290;
  const lastDone = w && w.done ? w.ref + ' finished ' + (w.secs < 90 ? w.secs + ' s' : Math.round(w.secs / 60) + ' min') + ' ago. ' : '';
  $('batchNote').textContent = active
    ? 'BRouter is searching around ' + w.ref + ' now (' + w.secs + ' s so far). ' + done + ' of ' + TEST_SET.length + ' test summits done' +
      (left > 0 ? ', about ' + Math.max(1, Math.round(left * (secs + 2) / 60)) + ' min left.' : '.')
    : lastDone + done + ' of ' + TEST_SET.length + ' test summits done.' + (left > 0 && !lastDone ? ' Not running right now.' : '');
  return active;
}
async function loadRun() {
  clearTimeout(pollTimer);
  if (!run) { rows = []; renderAll(); return; }
  const r = await api('results&run=' + run);
  rows = r.ok ? r.rows : [];
  runSettings = r.settings || null;
  const sc = $('resultsCard').scrollTop;
  renderAll();
  $('resultsCard').scrollTop = sc;
  const active = showProgress(r.working);
  // Refresh by itself: every 10 s while a summit is being searched, every 30 s otherwise.
  pollTimer = setTimeout(() => { if (!running) loadRun(); }, active ? 10000 : 30000);
}

/* ───────── map ───────── */
let map = null, overlays = [], mapReady = false, mapQueue = [];
function initMap() { mapReady = true; map = new google.maps.Map($('map'), {mapTypeId:'terrain', center:{lat:34.2,lng:-118.1}, zoom:9, streetViewControl:false}); mapQueue.forEach(f => f()); mapQueue = []; }
function whenMap(f) { mapReady ? f() : mapQueue.push(f); }
function clearMap() { overlays.forEach(o => o.setMap(null)); overlays = []; }
function line(pts, color, w, op, dashed) {
  const o = new google.maps.Polyline({map, path: pts.map(p => ({lat:p[0], lng:p[1]})), strokeColor: color, strokeWeight: w,
    strokeOpacity: dashed ? 0 : op, icons: dashed ? [{icon:{path:'M 0,-1 0,1', strokeOpacity:op, strokeColor:color, scale:w}, offset:'0', repeat:(w * 3) + 'px'}] : null});
  overlays.push(o); return o;
}
function dot(p, color, scale, title, label) {
  const o = new google.maps.Marker({map, position:{lat:p[0], lng:p[1]}, title: title || '', zIndex: label ? 50 : 10,
    label: label ? {text: label, color:'#fff', fontSize:'11px', fontWeight:'600'} : null,
    icon:{path:google.maps.SymbolPath.CIRCLE, scale, fillColor:color, fillOpacity:1, strokeColor:'#fff', strokeWeight: label ? 2 : 1}});
  overlays.push(o); return o;
}

let cur = null, hl = null, pkMarkers = [];
function stepSummit(dir) {   // Previous / Next in the review panel, in list order
  const i = rows.findIndex(r => r.sota_ref === selRef) + dir;
  if (i >= 0 && i < rows.length) { showDetail(rows[i].sota_ref); $('detail').scrollTop = 0; }
}
let showPk = true;
try { showPk = localStorage.getItem('td_show_parking') !== '0'; } catch (e) {}
async function showDetail(ref) {
  selRef = ref; renderAll();
  const r = await api('detail&run=' + run + '&ref=' + encodeURIComponent(ref));
  if (!r.ok) { msg(esc(r.error), 'msg-error'); return; }
  msg(r.d.parking_error ? 'Parking list: ' + esc(r.d.parking_error) : ''); cur = r.d; hl = null; drawDetail(true); drawParking();
}
/* Parking lots (blue P) and trailheads (green T) from the OpenStreetMap list, drawn once per summit. */
function drawParking() {
  pkMarkers.forEach(m => m.setMap(null)); pkMarkers = [];
  const pts = (cur && cur.parking) || [];
  const n = {P: 0, T: 0}; pts.forEach(p => n[p[0]] = (n[p[0]] || 0) + 1);
  if ($('pkCount')) $('pkCount').textContent = cur ? '(' + n.P.toLocaleString() + ' parking, ' + n.T.toLocaleString() + ' trailheads within ' + cur.parking_km + ' km)' : '';
  if (!showPk) return;
  whenMap(() => pts.forEach(p => {
    const t = p[0] === 'T';
    const info = [t ? 'Trailhead' : 'Parking', p[3] || '(no name)', p[6] ? p[6].replace(/_/g, ' ') : '', p[5] ? 'fee: ' + p[5] : '', p[4] ? 'access: ' + p[4] : ''].filter(Boolean).join(', ');
    pkMarkers.push(new google.maps.Marker({map, position:{lat:p[1], lng:p[2]}, title: info, zIndex: t ? 30 : 20,
      label: {text: t ? 'T' : 'P', color:'#fff', fontSize: t ? '10px' : '9px', fontWeight:'700'},
      icon: t ? {path:'M 0,-8 8,0 0,8 -8,0 Z', fillColor:'#15803d', fillOpacity:1, strokeColor:'#fff', strokeWeight:1.5, scale:1}
              : {path:'M -6,-6 6,-6 6,6 -6,6 Z', fillColor:'#1e40af', fillOpacity:.85, strokeColor:'#fff', strokeWeight:1, scale:1}}));
  }));
}
function pkLine(pk, label) {   // "Mapped parking 35 m away: Holy Jim Trailhead"
  if (!pk) return '<div class="pk none">' + label + ': no mapped parking or trailhead within 1 km</div>';
  const kind = pk.k === 'T' ? '<b class="t">trailhead</b>' : '<b>parking</b>';
  return '<div class="pk">' + label + ': mapped ' + kind + ' ' + pk.d.toLocaleString() + ' m away' + (pk.name ? ' (' + esc(pk.name) + ')' : '') +
         (pk.fee === 'yes' ? ', fee' : '') + (pk.access && !['yes', 'public', 'permissive'].includes(pk.access) ? ', access ' + esc(pk.access) : '') + '</div>';
}
function drawDetail(fit) {
  const d = cur, s = d.summit;
  whenMap(() => {
    clearMap();
    const b = new google.maps.LatLngBounds();
    (d.rings || []).forEach(rg => { const o = new google.maps.Circle({map, center:{lat:s.lat, lng:s.lon}, radius: rg.km * 1000, strokeColor:'#64748b', strokeOpacity:.35, strokeWeight:1, fillOpacity:0, clickable:false}); overlays.push(o); });
    (d.probes || []).forEach(p => dot([p.lat, p.lon], p.st === 'found' ? '#16a34a' : p.st === 'open' ? '#94a3b8' : '#dc2626', 3, p.st + ' (' + p.ring + ' km)' + (p.err ? ': ' + p.err : '')));
    if (d.known) { line(d.known.route, '#1C1B19', 6, .55, false); d.known.route.forEach(p => b.extend({lat:p[0], lng:p[1]})); dot(d.known.start, '#1C1B19', 6, 'Community track start'); }
    d.candidates.forEach((c, i) => {
      const chosen = d.chosen.includes(i), on = hl === null || hl === i;
      line(c.route, COLORS[i % COLORS.length], on ? (chosen ? 5 : 3) : 2, on ? .95 : .25, !chosen);
      if (c.summit_gap_m > 40 && on) line([c.route[c.route.length - 1], [s.lat, s.lon]], '#dc2626', 2, .9, true);   // off-trail finish
      if (c.closed && (hl === i || (hl === null && chosen))) {   // gate closed: hike from the paved road
        line(c.closed.route, COLORS[i % COLORS.length], 2, .9, true);
        overlays.push(new google.maps.Marker({map, position:{lat:c.closed.lat, lng:c.closed.lon}, zIndex:40, title:'#' + (i + 1) + ' if the dirt road is closed: park here (' + c.closed.road + ')',
          icon:{path:'M -6,-6 6,-6 6,6 -6,6 Z', fillColor:COLORS[i % COLORS.length], fillOpacity:1, strokeColor:'#fff', strokeWeight:2}}));
        if (hl === i) c.closed.route.forEach(p => b.extend({lat:p[0], lng:p[1]}));
      }
      dot([c.lat, c.lon], COLORS[i % COLORS.length], 9, c.cls + ' start: ' + c.road, String(i + 1));
      if (chosen || on) c.route.forEach(p => b.extend({lat:p[0], lng:p[1]}));
    });
    ((d.zone && d.zone.rings) || []).forEach(z => {
      const o = new google.maps.Polygon({map, paths: z.map(p => ({lat:p[0], lng:p[1]})), strokeColor:'#9333ea', strokeWeight:2, strokeOpacity:.9,
                                         fillColor:'#9333ea', fillOpacity:.18, clickable:false, zIndex:5});
      overlays.push(o);
    });
    // Summit: a large dark triangle with a white outline, always on top
    overlays.push(new google.maps.Marker({map, position:{lat:s.lat, lng:s.lon}, zIndex:100, title: s.ref + ' ' + s.name + (s.alt_ft ? ', ' + Math.round(s.alt_ft).toLocaleString() + ' ft' : ''),
      icon:{path:'M 0,-11 10,7 -10,7 Z', fillColor:'#1C1B19', fillOpacity:1, strokeColor:'#fff', strokeWeight:2.5, scale:1.1}}));
    b.extend({lat:s.lat, lng:s.lon});
    if (fit) map.fitBounds(b);
  });
  const k = d.known;
  const pos = rows.findIndex(r => r.sota_ref === s.ref);
  let h = '<div class="revnav"><button class="btn btn-ghost btn-sm" type="button" onclick="stepSummit(-1)"' + (pos <= 0 ? ' disabled' : '') + '>&larr; Previous</button>' +
          '<button class="btn btn-ghost btn-sm" type="button" onclick="stepSummit(1)"' + (pos < 0 || pos >= rows.length - 1 ? ' disabled' : '') + '>Next &rarr;</button>' +
          '<span class="grow"></span>' + (pos >= 0 ? (pos + 1) + ' of ' + rows.length : '') + '</div>';
  const rr = rows[pos];
  h += '<h2>' + esc(s.ref) + ' ' + esc(s.name) + (rr && rr.verdict ? ' <span class="pill p-' + rr.verdict + '">' + rr.verdict + '</span>' : '') + '</h2>';
  h += '<p class="note" style="margin:0 0 .75rem">' + d.stats.calls + ' BRouter calls in ' + d.stats.seconds + ' s, ' + d.stats.probes_found + ' of ' + d.stats.probes + ' probes reached a road. Rings: ' +
       d.rings.map(r => r.km + ' km (' + r.new + ' new)').join(', ') + '.' +
       (k ? ' Community track: ' + mi(k.dist_m) + ' mi, ' + ft(k.gain_m) + ' ft, ' + fmtT(k.time_min) + ' up. Closest of ours is #' + (k.match_i == null ? '-' : k.match_i + 1) + ' (' + k.overlap + '% overlap, start ' + (k.start_m ?? '-') + ' m away).' : ' No community track to compare.') + '</p>' +
       (k && 'pk' in k ? pkLine(k.pk, 'Community start') : '');
  if (d.confidence != null) h += '<p class="note" style="margin:0 0 .75rem">Summit confidence <strong class="' + confCls(d.confidence) + '">' + d.confidence + '% (' + bandOf(d.confidence) + ')</strong>: the chance the routes shown include the way people actually go. ' +
       'High: hike data could be filled in for users. Medium: shown, review required. Low: stays in Needs Research. Each route\'s Conf. is its own chance of being a route people use.</p>';
  if (!d.candidates.length) h += '<p class="note">No starting point found within the search area. This summit stays in Needs Research.</p>';
  else {
    h += '<table><tr><th>#</th><th>Start</th><th class="num">Hike</th><th class="num">Climb</th><th class="num">Time up</th><th class="num">Probes</th><th class="num">Conf.</th></tr>';
    d.candidates.forEach((c, i) => {
      const chosen = d.chosen.includes(i);
      h += '<tr class="click' + (hl === i ? ' sel' : '') + '" data-i="' + i + '"><td><span class="sw" style="background:' + COLORS[i % COLORS.length] + '"></span>' + (i + 1) + '</td>' +
        '<td><span class="pill p-' + c.cls + '">' + c.cls + '</span> ' + (chosen ? '<span class="pill p-chosen">top 3</span> ' : '') + esc(c.road) +
        '<span class="d2"><a href="https://www.google.com/maps/search/?api=1&query=' + c.lat + ',' + c.lon + '" target="_blank" rel="noopener">' + c.lat.toFixed(5) + ', ' + c.lon.toFixed(5) + '</a>, first seen ' + c.first_ring_km + ' km out' +
        (c.summit_gap_m > 40 ? ', <strong>last ' + c.summit_gap_m + ' m to the summit off trail</strong> (included in hike)' : '') + '</span>' +
        ('pk' in c ? pkLine(c.pk, 'Start') + (c.pk_t && (!c.pk || c.pk.k !== 'T') ? pkLine(c.pk_t, 'Nearest trailhead') : '') : '') +
        (c.cls === 'dirt' ? '<div class="closed">' + (c.closed
            ? 'If the dirt road is closed: park at the paved road (' + esc(c.closed.road) + ', <a href="https://www.google.com/maps/search/?api=1&query=' + c.closed.lat + ',' + c.closed.lon + '" target="_blank" rel="noopener">map</a>), ' +
              mi(c.closed.dist_m) + ' mi, ' + ft(c.closed.gain_m) + ' ft, <strong>' + fmtT(c.closed.time_min) + '</strong> up (' + (c.closed.extra_min ? '+' + fmtT(c.closed.extra_min) : 'about the same') + ')' +
              ('pk' in c.closed ? pkLine(c.closed.pk, 'That spot') : '')
            : 'If the dirt road is closed: no paved road found within the search area') + '</div>' : '') +
        '<div class="reasons">' + c.reasons.map(esc).join('; ') + '</div></td>' +
        '<td class="num">' + mi(c.dist_m) + ' mi</td><td class="num">' + ft(c.gain_m) + ' ft</td><td class="num">' + fmtT(c.time_min) + '</td>' +
        '<td class="num">' + c.hits + '</td><td class="num ' + confCls(c.confidence, true) + '">' + c.confidence + '%</td></tr>';
    });
    h += '</table><p class="note">Solid lines are the routes users would see (' + (d.settings && d.settings.pick === 'mixed' ? 'top 3: the quickest, always including a paved start if one exists' : 'top 3: paved starts first, dirt only if no paved start') + '). Dashed lines are the other starts found. For a dirt start, the thin dashed line and square show the hike from the paved road if the dirt road is gated or closed. Click a row to highlight it. Hike time uses the standard SOTAplanner pace.</p>';
  }
  $('detail').innerHTML = h; $('detail').style.display = '';
  $('detail').querySelectorAll('tr.click').forEach(tr => tr.onclick = () => { const i = +tr.dataset.i; hl = hl === i ? null : i; drawDetail(false); });
}

/* ───────── live BRouter server status (every 10 s) ───────── */
async function loadServer() {
  const r = await api('server');
  const el = $('srv'); if (!el) return;
  const parts = ['<span class="lbl">BRouter server</span>'];
  const led = c => '<span class="led" style="background:' + c + '"></span>';
  const w = r.working, searching = w && !w.done && w.secs < 300;
  if (!r.ok || !r.status) {
    parts.push('<span class="warn">' + led('#dc2626') + 'Cannot read the server status (' + esc(r.error || 'no answer') + ')</span>');
  } else {
    const st = r.status, m1 = st.routes['1m'], h1 = st.routes['60m'];
    const up = st.brouter === 'active';
    const busy = m1.n > 0;
    parts.push(up ? (busy ? led('#16a34a') + '<b>Busy</b>' : led('#94a3b8') + '<b>Idle</b>' + (st.last_route_ago_s != null ? ', last route ' + (st.last_route_ago_s < 120 ? st.last_route_ago_s + ' s' : Math.round(st.last_route_ago_s / 60) + ' min') + ' ago' : ''))
                  : '<span class="warn">' + led('#dc2626') + 'BRouter is ' + esc(st.brouter) + '</span>');
    if (busy) parts.push('<span><b>' + m1.n.toLocaleString() + '</b> routes in the last minute, ' + (m1.avg_s != null ? m1.avg_s + ' s each on average' : '') + '</span>');
    parts.push('<span>Last hour: <b>' + h1.n.toLocaleString() + '</b>' + (h1.no_route ? ', ' + h1.no_route + ' with no route (normal for probes)' : '') + '</span>');
    if (h1.limited) parts.push('<span class="warn">' + h1.limited + ' refused by the speed limit in the last hour</span>');
    if (h1.failed) parts.push('<span class="warn">' + h1.failed + ' server errors in the last hour</span>');
    parts.push('<span>CPU load <b>' + st.load[0] + '</b> of ' + st.cpus + ', memory <b>' + st.mem_used_pct + '%</b>, disk <b>' + st.disk_used_pct + '%</b></span>');
    if (st.age_s > 90) parts.push('<span class="warn">status is ' + Math.round(st.age_s / 60) + ' min old</span>');
  }
  if (searching) parts.push('<span>Now: searching around <b>' + esc(w.ref) + '</b> (run ' + w.run + ', ' + w.secs + ' s)' + (w.run !== run ? ' <a href="?run=' + w.run + '">view run</a>' : '') + '</span>');
  el.innerHTML = parts.join('');
}
if ($('srv')) { loadServer(); setInterval(loadServer, 10000); }

/* ───────── running ───────── */
async function runOne(ref) {
  const r = await api('one&run=' + run + '&ref=' + encodeURIComponent(ref));
  if (!r.ok) return r;
  const i = rows.findIndex(x => x.sota_ref === r.row.sota_ref);
  if (i >= 0) rows[i] = r.row; else { rows.push(r.row); rows.sort((a, b) => a.sota_ref.localeCompare(b.sota_ref)); }
  renderAll();
  return r;
}
if ($('runSel')) {
  $('runSel').onchange = () => { location.href = '?run=' + $('runSel').value; };   // reload: each run has its own region and test set
  $('goOne').onclick = async () => {
    const ref = $('oneRef').value.trim().toUpperCase(); if (!ref) return;
    $('goOne').disabled = true;
    const t0 = Date.now();
    const tick = () => { const s = Math.round((Date.now() - t0) / 1000);
      msg('Searching around ' + esc(ref) + ' with BRouter: ' + s + ' s so far. Usually 15 to 60 seconds' + (s > 90 ? ', this one is taking longer (busy server or a large search area)' : '') + '...'); };
    tick(); const ticker = setInterval(tick, 1000);
    const r = await runOne(ref);
    clearInterval(ticker);
    $('goOne').disabled = false;
    if (!r.ok) { msg(esc(r.error), 'msg-error'); return; }
    if (r.row && r.row.status === 'error') { msg('Search failed for ' + esc(ref) + ': ' + esc(r.row.error || 'unknown error'), 'msg-error'); return; }
    msg(''); showDetail(ref); $('review').scrollIntoView({behavior:'smooth', block:'start'});
  };
  $('goBatch').onclick = async () => {
    const todo = TEST_SET.filter(ref => !rows.some(r => r.sota_ref === ref && r.status !== 'error'));
    if (!todo.length) { $('batchNote').textContent = 'Every test summit is done in this run. Start a new run to try different settings.'; return; }
    running = true; $('goBatch').disabled = true; $('goPause').disabled = false;
    for (let i = 0; i < todo.length && running; i++) {
      $('batchNote').textContent = 'Working on ' + todo[i] + ' (' + (i + 1) + ' of ' + todo.length + ' remaining)...';
      $('bar').style.width = Math.round(100 * i / todo.length) + '%';
      const r = await runOne(todo[i]);
      if (!r.ok) { $('batchNote').textContent = 'Stopped at ' + todo[i] + ': ' + (r.error || 'error'); running = false; break; }
    }
    if (running) { $('bar').style.width = '100%'; $('batchNote').textContent = 'Finished.'; }
    running = false; $('goBatch').disabled = false; $('goPause').disabled = true;
    loadRun();   // back to live refreshing
  };
  $('goPause').onclick = () => { running = false; $('goPause').disabled = true; $('batchNote').textContent = 'Pausing after the current summit...'; };
  $('goNew').onclick = async () => {
    const r = await api('new_run', {max_km: +$('nMax').value, spacing_km: +$('nSp').value, stop: $('nStop').value, pick: $('nPick').value, region: $('nRegion').value, note: $('nNote').value});
    if (!r.ok) { msg(esc(r.error), 'msg-error'); return; }
    location.href = '?run=' + r.id;
  };
  $('pkToggle').checked = showPk;
  $('pkToggle').onchange = () => { showPk = $('pkToggle').checked; try { localStorage.setItem('td_show_parking', showPk ? '1' : '0'); } catch (e) {} drawParking(); };
  loadRun();
}
</script>
<?php if ($bkey): ?><script src="https://maps.googleapis.com/maps/api/js?key=<?= tdp_h($bkey) ?>&callback=initMap&loading=async" async defer></script><?php endif; ?>
</body>
</html>
