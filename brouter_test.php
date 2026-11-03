<?php
/**
 * BRouter test bench (God Mode only). Standalone; nothing else links here.
 *
 * Mode 1: pick a summit that already has a recorded community GPX track, route the same
 *         trailhead-to-summit trip with BRouter, and compare distance, climb and path.
 * Mode 2: quick route between any two coordinates.
 */
require_once 'config.php';
require_once 'brouter_lib.php';
session_start();
requireLogin();
session_write_close();

$callsign = $_SESSION['sota_callsign'] ?? '';
$real     = $_SESSION['_god_mode_real_callsign'] ?? '';
if ($callsign !== 'KI6CR' && $real !== 'KI6CR') {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();
$bkey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : '';
$profiles = brouter_profiles();

function bt_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Metres along a point list [[lat,lon,ele],...] between index $from and $to inclusive. */
function bt_path_len(array $pts, $from, $to) {
    $d = 0.0;
    for ($i = $from + 1; $i <= $to; $i++) {
        $d += haversine_distance($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
    }
    return $d;
}

/** Climb and drop in metres with a hysteresis so GPS elevation noise is not counted. */
function bt_climb(array $pts, $from, $to, $threshold = 3.0) {
    $gain = 0.0; $loss = 0.0; $ref = null;
    for ($i = $from; $i <= $to; $i++) {
        $e = $pts[$i][2];
        if ($e === null) continue;
        if ($ref === null) { $ref = $e; continue; }
        if ($e - $ref >= $threshold)      { $gain += $e - $ref; $ref = $e; }
        elseif ($ref - $e >= $threshold)  { $loss += $ref - $e; $ref = $e; }
    }
    return [$gain, $loss];
}

/** Index of the point in $pts nearest to lat/lon. */
function bt_nearest(array $pts, $lat, $lon) {
    $best = 0; $bd = PHP_FLOAT_MAX;
    foreach ($pts as $i => $p) {
        $d = haversine_distance($lat, $lon, $p[0], $p[1]);
        if ($d < $bd) { $bd = $d; $best = $i; }
    }
    return $best;
}

/** How closely route $a follows reference line $ref: mean and max distance (m) from each
 *  vertex of $a to the nearest vertex of $ref, and the share of $a within 50 m of it. */
function bt_follow(array $a, array $ref) {
    if (!$a || !$ref) return null;
    $sum = 0; $max = 0; $near = 0; $n = 0;
    $step = max(1, (int)floor(count($a) / 300));
    for ($i = 0; $i < count($a); $i += $step) {
        $bd = PHP_FLOAT_MAX;
        foreach ($ref as $r) {
            $d = haversine_distance($a[$i][0], $a[$i][1], $r[0], $r[1]);
            if ($d < $bd) $bd = $d;
        }
        $sum += $bd; $n++;
        if ($bd > $max) $max = $bd;
        if ($bd <= 50) $near++;
    }
    return ['mean_m' => $sum / $n, 'max_m' => $max, 'pct50' => 100 * $near / $n];
}

// Summits that have a community track, a trailhead, and sit inside the 10 map tiles on the server.
$cands = $db->query("
    SELECT sota_ref, total_distance, elevation_gain, num_points, source_callsign
    FROM global_gpx_tracks
    WHERE trailhead_lat IS NOT NULL AND summit_lat BETWEEN 30.6 AND 49.4 AND summit_lon BETWEEN -124.4 AND -115.6
      AND total_distance BETWEEN 2.4 AND 16 AND num_points > 150   -- stored in km
    ORDER BY num_points DESC LIMIT 60
")->fetchAll();

$mode    = $_GET['mode'] ?? 'compare';
if (!in_array($mode, ['compare', 'quick', 'batch'], true)) $mode = 'compare';

// Batch mode worker: one summit per request, JSON out. The page's JS calls this in a loop.
if ($mode === 'batch' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    @set_time_limit(180);
    $aref = trim($_GET['ref'] ?? '');
    $stmt = $db->prepare("SELECT * FROM global_gpx_tracks WHERE sota_ref = ? LIMIT 1");
    $stmt->execute([$aref]);
    $trk = $stmt->fetch();
    $out = ['ref' => $aref, 'error' => ''];
    if (!$trk) { $out['error'] = 'No community track'; echo json_encode($out); exit; }
    $all = brouter_read_gpx_points(__DIR__ . '/gpx_files/global/' . basename($trk['filename']), 0);
    if (count($all) < 10) { $out['error'] = 'GPX file not readable'; echo json_encode($out); exit; }
    $n    = count($all);
    $sidx = bt_nearest($all, (float)$trk['summit_lat'], (float)$trk['summit_lon']);
    [$gain, $loss] = bt_climb($all, 0, $sidx);
    $upleg = array_slice($all, 0, $sidx + 1);
    $out['rec'] = ['dist_m' => bt_path_len($all, 0, $sidx), 'gain_m' => $gain, 'points' => $n,
                   'round_trip' => haversine_distance($all[0][0], $all[0][1], $all[$n - 1][0], $all[$n - 1][1]) < 150,
                   'by' => $trk['source_callsign']];
    $out['profiles'] = [];
    foreach ($profiles as $pf) {
        $r = brouter_route([[$all[0][0], $all[0][1]], [$all[$sidx][0], $all[$sidx][1]]], $pf);
        if (!$r['ok']) { $out['profiles'][$pf] = ['err' => $r['error']]; continue; }
        $f = bt_follow($r['coords'], $upleg);
        $out['profiles'][$pf] = ['dist_m' => $r['distance_m'], 'gain_m' => $r['ascend_m'], 'ms' => $r['ms'],
                                 'pct50' => $f['pct50'], 'mean_m' => $f['mean_m'], 'max_m' => $f['max_m']];
        usleep(250000);
    }
    echo json_encode($out);
    exit;
}
$ref     = trim($_GET['ref'] ?? ($cands[0]['sota_ref'] ?? ''));
$go      = isset($_GET['go']);
$error   = '';
$layers  = [];     // for the map: [name, color, [[lat,lon],...], weight]
$markers = [];     // [lat, lon, label]
$rows    = [];     // comparison table rows
$info    = [];     // facts about the recorded track
$calls   = 0;

if ($go && $mode === 'compare') {
    $stmt = $db->prepare("SELECT * FROM global_gpx_tracks WHERE sota_ref = ? LIMIT 1");
    $stmt->execute([$ref]);
    $trk = $stmt->fetch();
    if (!$trk) {
        $error = 'No community track for ' . $ref;
    } else {
        $path = __DIR__ . '/gpx_files/global/' . basename($trk['filename']);
        $all  = brouter_read_gpx_points($path, 0);
        if (count($all) < 10) {
            $error = 'GPX file not readable here (' . basename($trk['filename']) . '). It lives on the server, so run this on staging.';
        } else {
            $n    = count($all);
            $sidx = bt_nearest($all, (float)$trk['summit_lat'], (float)$trk['summit_lon']);
            // Out and back? Then the first leg (start to summit) is the fair comparison.
            $loop_gap = haversine_distance($all[0][0], $all[0][1], $all[$n - 1][0], $all[$n - 1][1]);
            $round_trip = $loop_gap < 150;
            $up_m   = bt_path_len($all, 0, $sidx);
            [$up_gain, $up_loss] = bt_climb($all, 0, $sidx);
            $tot_m  = bt_path_len($all, 0, $n - 1);
            $info = [
                'file'       => $trk['filename'],
                'by'         => $trk['source_callsign'],
                'points'     => $n,
                'round_trip' => $round_trip,
                'total_mi'   => $tot_m / 1609.344,
                'summit_idx' => $sidx,
            ];
            $upleg = array_slice($all, 0, $sidx + 1);
            $start = $all[0];
            $end   = $all[$sidx];

            $rows[] = [
                'name' => 'Recorded track (start to summit)', 'color' => '#2563eb',
                'dist_m' => $up_m, 'gain_m' => $up_gain, 'loss_m' => $up_loss, 'ms' => null, 'follow' => null, 'err' => '',
            ];
            $thin = brouter_read_gpx_points($path, 500);
            $layers[] = ['Recorded track (whole)', '#2563eb', array_map(fn($p) => [$p[0], $p[1]], $thin), 4, 0.9];

            $colors = ['hiking-mountain' => '#ea580c', 'trekking' => '#16a34a'];
            foreach ($profiles as $pf) {
                $r = brouter_route([[$start[0], $start[1]], [$end[0], $end[1]]], $pf);
                $calls++;
                if (!$r['ok']) {
                    $rows[] = ['name' => 'BRouter: ' . $pf, 'color' => $colors[$pf] ?? '#888', 'err' => $r['error']];
                    continue;
                }
                $rows[] = [
                    'name' => 'BRouter: ' . $pf, 'color' => $colors[$pf] ?? '#888',
                    'dist_m' => $r['distance_m'], 'gain_m' => $r['ascend_m'], 'loss_m' => $r['descend_m'],
                    'ms' => $r['ms'], 'follow' => bt_follow($r['coords'], $upleg), 'err' => '',
                ];
                $layers[] = ['BRouter: ' . $pf, $colors[$pf] ?? '#888', array_map(fn($c) => [$c[0], $c[1]], $r['coords']), 3, 0.95];
            }
            $markers[] = [$start[0], $start[1], 'Start (track begins)'];
            $markers[] = [$end[0], $end[1], 'Summit ' . $ref];
        }
    }
} elseif ($go && $mode === 'quick') {
    $a = array_map('trim', explode(',', $_GET['from'] ?? ''));
    $b = array_map('trim', explode(',', $_GET['to'] ?? ''));
    if (count($a) !== 2 || count($b) !== 2 || !is_numeric($a[0]) || !is_numeric($a[1]) || !is_numeric($b[0]) || !is_numeric($b[1])) {
        $error = 'Enter each point as "lat, lon", for example 33.7201, -117.544';
    } else {
        $colors = ['hiking-mountain' => '#ea580c', 'trekking' => '#16a34a'];
        foreach ($profiles as $pf) {
            $r = brouter_route([[(float)$a[0], (float)$a[1]], [(float)$b[0], (float)$b[1]]], $pf);
            $calls++;
            if (!$r['ok']) {
                $rows[] = ['name' => 'BRouter: ' . $pf, 'color' => $colors[$pf] ?? '#888', 'err' => $r['error']];
                continue;
            }
            $rows[] = ['name' => 'BRouter: ' . $pf, 'color' => $colors[$pf] ?? '#888',
                'dist_m' => $r['distance_m'], 'gain_m' => $r['ascend_m'], 'loss_m' => $r['descend_m'],
                'ms' => $r['ms'], 'follow' => null, 'err' => ''];
            $layers[] = ['BRouter: ' . $pf, $colors[$pf] ?? '#888', array_map(fn($c) => [$c[0], $c[1]], $r['coords']), 4, 0.95];
        }
        $markers[] = [(float)$a[0], (float)$a[1], 'From'];
        $markers[] = [(float)$b[0], (float)$b[1], 'To'];
    }
}

$base = $rows[0] ?? null;   // recorded track row in compare mode
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BRouter Test | SOTA Planner</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F6F3; --bg-2:#EFEDE8; --bg-3:#E5E2DA; --ink:#1C1B19; --ink-2:#4A4844; --ink-3:#8C8A86; --ink-4:#B8B5B0;
  --accent:oklch(52% 0.13 50); --accent-bg:oklch(96% 0.04 65); --accent-border:oklch(84% 0.08 65);
  --green:oklch(52% 0.13 155); --green-bg:oklch(95% 0.04 155); --red:oklch(52% 0.16 22); --red-bg:oklch(96% 0.04 22);
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
.page{padding:2rem;max-width:1200px;margin:0 auto}
h1{font-size:1.4rem;letter-spacing:-.01em;margin-bottom:.25rem}
.sub{color:var(--ink-3);font-size:.9rem;margin-bottom:1.25rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1rem}
.tabs{display:flex;gap:.5rem;margin-bottom:1rem}
.tab{padding:.4rem .9rem;border-radius:100px;border:1px solid var(--border-2);background:var(--surface);color:var(--ink-2);font-size:.8rem;font-weight:500;text-decoration:none}
.tab.active{background:var(--ink);border-color:var(--ink);color:#fff}
.row{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end}
label{display:block;font-size:.72rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem}
select,input[type=text]{padding:.55rem .75rem;border:1px solid var(--border);border-radius:var(--r-md);font-family:var(--font-sans);font-size:.875rem;background:var(--surface);color:var(--ink);min-width:220px}
.btn{height:36px;padding:0 1rem;border-radius:var(--r-md);border:none;background:var(--ink);color:#fff;font-family:var(--font-sans);font-size:.875rem;font-weight:500;cursor:pointer}
.btn:hover{background:var(--ink-2)}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);padding:.5rem .6rem;border-bottom:1px solid var(--border)}
td{padding:.6rem;border-bottom:1px solid var(--bg-3);vertical-align:top}
td.num,th.num{text-align:right;font-family:var(--font-mono);white-space:nowrap}
.sw{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:.5rem;vertical-align:-1px}
.delta{color:var(--ink-3);font-size:.75rem;display:block}
.good{color:var(--green)} .bad{color:var(--red)}
.msg-error{background:var(--red-bg);color:var(--red);border:1px solid oklch(85% 0.08 22);padding:.75rem 1rem;border-radius:var(--r-md);margin-bottom:1rem;font-size:.875rem}
.note{font-size:.8rem;color:var(--ink-3);margin-top:.6rem}
#map{height:520px;border-radius:var(--r-lg);border:1px solid var(--border)}
</style>
</head>
<body>
<nav class="topbar">
    <a class="logo" href="index.php"><img src="sota-planner-logo.svg" width="32" height="32" alt=""><span>SOTAplanner</span></a>
    <nav><a href="god_mode.php">God Mode</a></nav>
</nav>
<div class="page">
    <h1>BRouter test bench</h1>
    <p class="sub">Compares our routing server's answer with a real recorded hike. Server: <?= bt_h(defined('BROUTER_URL') ? BROUTER_URL : 'not configured') ?>
        <?= brouter_configured() ? '' : '<strong class="bad">(BROUTER_URL / BROUTER_API_KEY missing)</strong>' ?></p>

    <div class="tabs">
        <a class="tab <?= $mode === 'compare' ? 'active' : '' ?>" href="?mode=compare">Compare with a recorded hike</a>
        <a class="tab <?= $mode === 'batch' ? 'active' : '' ?>" href="?mode=batch">Run all summits (statistics)</a>
        <a class="tab <?= $mode === 'quick' ? 'active' : '' ?>" href="?mode=quick">Quick route between two points</a>
    </div>

    <div class="card">
    <?php if ($mode === 'compare'): ?>
        <form method="get" class="row">
            <input type="hidden" name="mode" value="compare"><input type="hidden" name="go" value="1">
            <div><label>Summit with a recorded track (West Coast tiles only)</label>
                <select name="ref" style="min-width:380px">
                <?php foreach ($cands as $c): ?>
                    <option value="<?= bt_h($c['sota_ref']) ?>" <?= $c['sota_ref'] === $ref ? 'selected' : '' ?>>
                        <?= bt_h($c['sota_ref']) ?> | <?= number_format((float)$c['total_distance'] / 1.609344, 1) ?> mi whole track, <?= number_format((float)$c['elevation_gain'] * 3.28084) ?> ft climb, <?= (int)$c['num_points'] ?> pts<?= $c['source_callsign'] ? ' | ' . bt_h($c['source_callsign']) : '' ?>
                    </option>
                <?php endforeach; ?>
                </select></div>
            <button class="btn" type="submit">Run comparison</button>
        </form>
    <?php elseif ($mode === 'batch'): ?>
        <div class="row">
            <div><strong><?= count($cands) ?> summits</strong> with a recorded track and a trailhead, each routed with both profiles (start of track to summit).
                One summit at a time, a short pause between calls.</div>
            <button class="btn" id="batchGo" type="button">Run all</button>
            <button class="btn" id="batchStop" type="button" style="display:none;background:var(--red)">Stop</button>
            <button class="btn" id="batchCsv" type="button" style="display:none">Download CSV</button>
        </div>
        <p class="note" id="batchStatus"></p>
    <?php else: ?>
        <form method="get" class="row">
            <input type="hidden" name="mode" value="quick"><input type="hidden" name="go" value="1">
            <div><label>From (lat, lon)</label><input type="text" name="from" value="<?= bt_h($_GET['from'] ?? '33.7201, -117.544') ?>"></div>
            <div><label>To (lat, lon)</label><input type="text" name="to" value="<?= bt_h($_GET['to'] ?? '33.7107, -117.5342') ?>"></div>
            <button class="btn" type="submit">Route it</button>
        </form>
    <?php endif; ?>
    </div>

    <?php if ($error): ?><div class="msg-error"><?= bt_h($error) ?></div><?php endif; ?>

    <?php if ($mode === 'batch'): ?>
    <div class="card" id="statsCard" style="display:none"><h2 style="font-size:1rem;margin-bottom:.75rem">Summary</h2>
        <table id="statsTable"></table>
        <p class="note">Distance error = BRouter minus the recorded start-to-summit leg, as a percent of the recording. Climb uses BRouter's filtered ascend vs the recording smoothed with a 3 m filter.
            "Follows the path" is the share of BRouter's route within 50 m of the recorded line. Recordings that wander off trail will score low on that even when BRouter is right.</p></div>
    <div class="card"><table id="batchTable">
        <tr><th>Summit</th><th class="num">Recorded</th><th class="num">hiking-mountain</th><th class="num">trekking</th></tr>
    </table></div>
    <?php endif; ?>

    <?php if ($rows): ?>
    <div class="card">
        <?php if ($info): ?>
        <p class="note" style="margin:0 0 .75rem">
            Track <strong><?= bt_h($info['file']) ?></strong><?= $info['by'] ? ' by ' . bt_h($info['by']) : '' ?>,
            <?= (int)$info['points'] ?> points, <?= number_format($info['total_mi'], 2) ?> mi in total.
            <?= $info['round_trip'] ? 'It ends where it started (out and back), so only the leg from the start to the summit is compared.' : 'It does not end where it started, so only the leg from the start to the summit point is compared.' ?>
        </p>
        <?php endif; ?>
        <table>
            <tr><th>Route</th><th class="num">Distance</th><th class="num">Climb</th><th class="num">Drop</th><?php if ($info): ?><th class="num">Hike time*</th><th class="num">Follows the real path</th><?php endif; ?><th class="num">Server time</th></tr>
            <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><span class="sw" style="background:<?= bt_h($r['color']) ?>"></span><?= bt_h($r['name']) ?></td>
                <?php if ($r['err']): ?>
                    <td colspan="<?= $info ? 6 : 4 ?>" class="bad"><?= bt_h($r['err']) ?></td>
                <?php else:
                    $mi = $r['dist_m'] / 1609.344; $ft = $r['gain_m'] * 3.28084; $lf = $r['loss_m'] * 3.28084;
                    $cmp = ($info && $i > 0 && $base && empty($base['err']));
                ?>
                    <td class="num"><?= number_format($mi, 2) ?> mi
                        <?php if ($cmp): $p = 100 * ($r['dist_m'] - $base['dist_m']) / max(1, $base['dist_m']); ?>
                            <span class="delta"><?= ($p >= 0 ? '+' : '') . number_format($p, 0) ?>% vs track</span><?php endif; ?></td>
                    <td class="num"><?= number_format($ft) ?> ft
                        <?php if ($cmp): $p = 100 * ($r['gain_m'] - $base['gain_m']) / max(1, $base['gain_m']); ?>
                            <span class="delta"><?= ($p >= 0 ? '+' : '') . number_format($p, 0) ?>% vs track</span><?php endif; ?></td>
                    <td class="num"><?= number_format($lf) ?> ft</td>
                    <?php if ($info): ?>
                    <td class="num"><?= formatTime(calculateHikeTime($mi, $ft)) ?></td>
                    <td class="num"><?php if ($r['follow']): $f = $r['follow']; ?>
                        <span class="<?= $f['pct50'] >= 80 ? 'good' : 'bad' ?>"><?= number_format($f['pct50'], 0) ?>% within 50 m</span>
                        <span class="delta">avg <?= number_format($f['mean_m']) ?> m, worst <?= number_format($f['max_m']) ?> m off</span>
                        <?php else: ?>reference<?php endif; ?></td>
                    <?php endif; ?>
                    <td class="num"><?= $r['ms'] !== null ? number_format($r['ms']) . ' ms' : '' ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="note">*Hike time uses the same pace formula as the rest of SOTAplanner (distance and climb), applied to each route, so the routes can be compared fairly. BRouter's own time figure is bike-style and is not used.
            Recorded climb is smoothed with a 3 m filter; BRouter climb is its own filtered figure.</p>
    </div>
    <div id="map"></div>
    <?php endif; ?>
</div>

<?php if ($rows && $layers && $bkey): ?>
<script>
const LAYERS  = <?= json_encode($layers, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const MARKERS = <?= json_encode($markers, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
function initMap() {
    const map = new google.maps.Map(document.getElementById('map'), { mapTypeId: 'terrain', mapTypeControl: true, streetViewControl: false });
    const b = new google.maps.LatLngBounds();
    LAYERS.forEach(L => {
        const path = L[2].map(p => ({lat: p[0], lng: p[1]}));
        path.forEach(p => b.extend(p));
        new google.maps.Polyline({ map, path, strokeColor: L[1], strokeWeight: L[3], strokeOpacity: L[4] });
    });
    MARKERS.forEach(m => {
        const pos = {lat: m[0], lng: m[1]}; b.extend(pos);
        new google.maps.Marker({ map, position: pos, title: m[2] });
    });
    map.fitBounds(b);
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= bt_h($bkey) ?>&callback=initMap&loading=async" async defer></script>
<?php endif; ?>

<?php if ($mode === 'batch'): ?>
<script>
const REFS = <?= json_encode(array_column($cands, 'sota_ref')) ?>;
const PROFILES = <?= json_encode($profiles) ?>;
const results = []; let stop = false;
const $ = id => document.getElementById(id);
const mi = m => (m / 1609.344).toFixed(2), ft = m => Math.round(m * 3.28084).toLocaleString();
const pct = (a, b) => b > 0 ? 100 * (a - b) / b : null;
const sgn = v => (v >= 0 ? '+' : '') + v.toFixed(0) + '%';
function median(a) { if (!a.length) return null; const s = [...a].sort((x, y) => x - y), m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; }
function cell(r, p) {
  const x = r.profiles && r.profiles[p];
  if (!x) return '<td class="num">-</td>';
  if (x.err) return '<td class="num bad">' + x.err.replace(/</g, '&lt;') + '</td>';
  const d = pct(x.dist_m, r.rec.dist_m), g = pct(x.gain_m, r.rec.gain_m);
  return '<td class="num">' + mi(x.dist_m) + ' mi <span class="delta">' + (d === null ? '' : sgn(d)) + '</span>' + ft(x.gain_m) + ' ft <span class="delta">' + (g === null ? '' : sgn(g)) +
    '</span><span class="' + (x.pct50 >= 80 ? 'good' : 'bad') + '">' + x.pct50.toFixed(0) + '% on path</span></td>';
}
function stats() {
  const ok = results.filter(r => !r.error);
  let h = '<tr><th>Profile</th><th class="num">Routed</th><th class="num">Distance: median error</th><th class="num">Distance: within 15%</th><th class="num">Climb: median error</th><th class="num">Climb: within 25%</th><th class="num">Follows path: median</th><th class="num">80%+ on path</th></tr>';
  PROFILES.forEach(p => {
    const v = ok.map(r => ({r, x: r.profiles[p]})).filter(o => o.x && !o.x.err);
    const de = v.map(o => pct(o.x.dist_m, o.r.rec.dist_m)).filter(n => n !== null);
    const ge = v.map(o => pct(o.x.gain_m, o.r.rec.gain_m)).filter(n => n !== null);
    const fo = v.map(o => o.x.pct50);
    const c = (arr, f) => arr.length ? Math.round(100 * arr.filter(f).length / arr.length) + '%' : '-';
    h += '<tr><td><strong>' + p + '</strong></td><td class="num">' + v.length + ' of ' + results.length + '</td>' +
      '<td class="num">' + (de.length ? sgn(median(de)) + ' (typical miss ' + median(de.map(Math.abs)).toFixed(0) + '%)' : '-') + '</td>' +
      '<td class="num">' + c(de, n => Math.abs(n) <= 15) + '</td>' +
      '<td class="num">' + (ge.length ? sgn(median(ge)) + ' (typical miss ' + median(ge.map(Math.abs)).toFixed(0) + '%)' : '-') + '</td>' +
      '<td class="num">' + c(ge, n => Math.abs(n) <= 25) + '</td>' +
      '<td class="num">' + (fo.length ? median(fo).toFixed(0) + '%' : '-') + '</td>' +
      '<td class="num">' + c(fo, n => n >= 80) + '</td></tr>';
  });
  $('statsTable').innerHTML = h; $('statsCard').style.display = '';
}
async function run() {
  $('batchGo').style.display = 'none'; $('batchStop').style.display = ''; stop = false;
  for (let i = 0; i < REFS.length && !stop; i++) {
    $('batchStatus').textContent = 'Running ' + (i + 1) + ' of ' + REFS.length + ': ' + REFS[i];
    let r;
    try { r = await (await fetch('?mode=batch&ajax=1&ref=' + encodeURIComponent(REFS[i]))).json(); }
    catch (e) { r = {ref: REFS[i], error: 'Request failed'}; }
    results.push(r);
    const t = $('batchTable'), row = t.insertRow(-1);
    row.innerHTML = '<td>' + r.ref + '</td>' + (r.error ? '<td colspan="3" class="bad">' + r.error + '</td>' :
      '<td class="num">' + mi(r.rec.dist_m) + ' mi<br>' + ft(r.rec.gain_m) + ' ft<span class="delta">' + (r.rec.round_trip ? 'out and back' : 'one way') + '</span></td>' + cell(r, PROFILES[0]) + cell(r, PROFILES[1]));
    stats();
  }
  $('batchStatus').textContent = (stop ? 'Stopped after ' : 'Finished ') + results.length + ' of ' + REFS.length + ' summits.';
  $('batchStop').style.display = 'none'; $('batchGo').style.display = ''; $('batchCsv').style.display = '';
}
$('batchGo').onclick = () => { if (!results.length) run(); else { results.length = 0; document.querySelectorAll('#batchTable tr:not(:first-child)').forEach(r => r.remove()); run(); } };
$('batchStop').onclick = () => { stop = true; };
$('batchCsv').onclick = () => {
  const lines = [['summit','rec_mi','rec_gain_ft','profile','brouter_mi','brouter_gain_ft','dist_err_pct','gain_err_pct','pct_within_50m','mean_off_m','max_off_m'].join(',')];
  results.filter(r => !r.error).forEach(r => PROFILES.forEach(p => { const x = r.profiles[p]; if (!x || x.err) return;
    lines.push([r.ref, mi(r.rec.dist_m), ft(r.rec.gain_m).replace(',', ''), p, mi(x.dist_m), ft(x.gain_m).replace(',', ''),
      (pct(x.dist_m, r.rec.dist_m) ?? '').toString().slice(0, 6), (pct(x.gain_m, r.rec.gain_m) ?? '').toString().slice(0, 6), x.pct50.toFixed(0), x.mean_m.toFixed(0), x.max_m.toFixed(0)].join(',')); }));
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([lines.join('\n')], {type: 'text/csv'})); a.download = 'brouter-comparison.csv'; a.click();
};
</script>
<?php endif; ?>
</body>
</html>
