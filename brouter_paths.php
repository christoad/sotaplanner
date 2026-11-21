<?php
/**
 * BRouter Path Finder (God Mode only; linked from God Mode > Data Tools).
 *
 * Routes between two summits (cached permanently, hike time each way).
 * The old "discover paths" tab (Overpass footpaths, bp_discover) was dropped on 2026-11-21 at Chris's
 * request: it depended on the public Overpass service and is replaced by trail_discovery.php.
 * Old ?mode=discover links forward there.
 */
require_once 'config.php';
require_once 'brouter_paths_lib.php';
session_start();
requireLogin();
session_write_close();

if (!isGodMode() && ($_SESSION['_god_mode_real_callsign'] ?? '') !== 'KI6CR') {
    header('Location: index.php');
    exit;
}

// The discover tab is gone: send old links to Trail Discovery (with the summit, if one was given)
if (($_GET['mode'] ?? '') === 'discover') {
    $r = strtoupper(trim($_GET['ref'] ?? ''));
    header('Location: trail_discovery.php' . ($r !== '' ? '?ref=' . rawurlencode($r) : ''));
    exit;
}

$bkey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : '';

/* ───────── AJAX ───────── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    @set_time_limit(290);
    try {
        if ($_GET['ajax'] === 'route') {
            $profile = in_array($_GET['profile'] ?? '', brouter_profiles(), true) ? $_GET['profile'] : 'hiking-mountain';
            $r = brouter_route_summits($_GET['a'] ?? '', $_GET['b'] ?? '', $profile);
            if ($r['ok']) $r['coords'] = array_map(fn($c) => [$c[0], $c[1]], bp_thin($r['coords'], 500));
            echo json_encode($r);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

function bpp_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Path Finder | SOTA Planner</title>
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
.sub a{color:var(--accent)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1rem}
.row{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end}
label{display:block;font-size:.72rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem}
select,input[type=text]{padding:.55rem .75rem;border:1px solid var(--border);border-radius:var(--r-md);font-family:var(--font-sans);font-size:.875rem;background:var(--surface);color:var(--ink)}
input[type=text]{min-width:170px}
.btn{height:36px;padding:0 1rem;border-radius:var(--r-md);border:none;background:var(--ink);color:#fff;font-family:var(--font-sans);font-size:.875rem;font-weight:500;cursor:pointer}
.btn:hover{background:var(--ink-2)} .btn:disabled{opacity:.5;cursor:default}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);padding:.5rem .6rem;border-bottom:1px solid var(--border)}
td{padding:.6rem;border-bottom:1px solid var(--bg-3);vertical-align:top}
td.num,th.num{text-align:right;font-family:var(--font-mono);white-space:nowrap}
.delta{color:var(--ink-3);font-size:.75rem;display:block}
.bad{color:var(--red)}
.msg-error{background:var(--red-bg);color:var(--red);border:1px solid oklch(85% 0.08 22);padding:.75rem 1rem;border-radius:var(--r-md);margin-bottom:1rem;font-size:.875rem}
.msg-info{background:var(--accent-bg);color:var(--ink-2);border:1px solid var(--accent-border);padding:.75rem 1rem;border-radius:var(--r-md);margin-bottom:1rem;font-size:.875rem}
.note{font-size:.8rem;color:var(--ink-3);margin-top:.6rem}
#map{height:540px;border-radius:var(--r-lg);border:1px solid var(--border);margin-bottom:1rem}
</style>
</head>
<body>
<nav class="topbar">
    <a class="logo" href="index.php"><img src="sota-planner-logo.svg" width="32" height="32" alt=""><span>SOTAplanner</span></a>
    <nav><a href="god_mode.php?tab=data">God Mode</a><a href="trail_discovery.php">Trail Discovery</a><a href="brouter_test.php">Test bench</a></nav>
</nav>
<div class="page">
    <h1>Path Finder</h1>
    <p class="sub">Routes between two summits with our BRouter server (worldwide map tiles, refreshed weekly from OpenStreetMap).
        To find hiking routes and starting points for one summit, use <a href="trail_discovery.php">Trail Discovery</a>.
        <?= brouter_configured() ? '' : '<strong class="bad">(BROUTER_URL / BROUTER_API_KEY missing)</strong>' ?></p>

    <div class="card">
        <div class="row">
            <div><label>From summit</label><input type="text" id="refA" value="<?= bpp_h($_GET['a'] ?? 'W6/CT-001') ?>" placeholder="W6/CT-001"></div>
            <div><label>To summit</label><input type="text" id="refB" value="<?= bpp_h($_GET['b'] ?? 'W6/CT-002') ?>" placeholder="W6/CT-002"></div>
            <div><label>Profile</label><select id="profile"><?php foreach (brouter_profiles() as $p): ?><option><?= bpp_h($p) ?></option><?php endforeach; ?></select></div>
            <button class="btn" id="goRoute" type="button">Route it</button>
        </div>
        <p class="note">Every routed pair is cached permanently, so asking again is instant and free. Hike time uses the standard SOTAplanner pace formula (distance and climb), not BRouter's bike-style time.</p>
    </div>
    <div id="msg"></div>
    <div id="result" style="display:none" class="card"></div>
    <div id="map"></div>
</div>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const mi = m => (m / 1609.344).toFixed(2), ft = m => Math.round(m * 3.28084).toLocaleString();
const fmtT = m => m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
let map = null, overlays = [];
let mapReady = false, mapQueue = [];
function initMap() { mapReady = true; map = new google.maps.Map($('map'), {mapTypeId:'terrain', center:{lat:36,lng:-119}, zoom:6, streetViewControl:false}); mapQueue.forEach(f => f()); mapQueue = []; }
function whenMap(f) { mapReady ? f() : mapQueue.push(f); }
function clearMap() { overlays.forEach(o => o.setMap(null)); overlays = []; }
function line(pts, color, w, op) {
  const o = new google.maps.Polyline({map, path: pts.map(p => ({lat:p[0], lng:p[1]})), strokeColor: color, strokeWeight: w, strokeOpacity: op});
  overlays.push(o); return o;
}
function pin(p, title, color) {
  const o = new google.maps.Marker({map, position:{lat:p[0], lng:p[1]}, title, icon:{path:google.maps.SymbolPath.CIRCLE, scale:7, fillColor:color, fillOpacity:1, strokeColor:'#fff', strokeWeight:2}});
  overlays.push(o); return o;
}
function fit(pts) { const b = new google.maps.LatLngBounds(); pts.forEach(p => b.extend({lat:p[0], lng:p[1]})); map.fitBounds(b); }
function msg(html, cls) { $('msg').innerHTML = html ? '<div class="' + (cls || 'msg-info') + '">' + html + '</div>' : ''; }

/* Fetch with a 2 minute limit, so a stuck request always ends in a message. */
async function api(q) {
  const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), 120000);
  try {
    const res = await fetch('?ajax=' + q, {signal: ctl.signal});
    const text = await res.text();
    try { return JSON.parse(text); }
    catch (e) { return {ok:false, error:'The server sent something unexpected (HTTP ' + res.status + '). If you were signed out, reload the page.'}; }
  } catch (e) {
    return {ok:false, error: e.name === 'AbortError' ? 'No answer after 2 minutes. The BRouter server may be down or busy.' : 'Request failed: ' + e.message};
  } finally { clearTimeout(timer); }
}

$('goRoute').onclick = async () => {
  const a = $('refA').value.trim(), b = $('refB').value.trim();
  if (!a || !b) { msg('Enter both summits.', 'msg-error'); return; }
  $('goRoute').disabled = true; $('result').style.display = 'none';
  const t0 = Date.now();
  const tick = () => msg('Routing ' + esc(a) + ' to ' + esc(b) + ' with BRouter... ' + Math.round((Date.now() - t0) / 1000) + ' s');
  tick(); const ticker = setInterval(tick, 1000);
  const r = await api('route&a=' + encodeURIComponent(a) + '&b=' + encodeURIComponent(b) + '&profile=' + encodeURIComponent($('profile').value));
  clearInterval(ticker);
  $('goRoute').disabled = false;
  if (!r.ok) { msg(esc(r.error || 'Routing failed'), 'msg-error'); return; }
  msg('');
  const circ = (r.distance_m / r.straight_m).toFixed(2);
  $('result').style.display = '';
  $('result').innerHTML = '<table><tr><th>Route</th><th class="num">Distance</th><th class="num">Climb</th><th class="num">Drop</th><th class="num">Hike time</th><th class="num">As the crow flies</th></tr>' +
    '<tr><td>' + esc(r.a.ref) + ' to ' + esc(r.b.ref) + '<span class="delta">' + esc(r.a.name) + ' to ' + esc(r.b.name) + '</span></td>' +
    '<td class="num">' + mi(r.distance_m) + ' mi</td><td class="num">' + ft(r.ascend_m) + ' ft</td><td class="num">' + ft(r.descend_m) + ' ft</td>' +
    '<td class="num">' + fmtT(r.time_ab_min) + '</td><td class="num">' + mi(r.straight_m) + ' mi<span class="delta">route is ' + circ + 'x longer</span></td></tr>' +
    '<tr><td>' + esc(r.b.ref) + ' to ' + esc(r.a.ref) + ' (reverse)</td><td class="num">' + mi(r.distance_m) + ' mi</td><td class="num">' + ft(r.descend_m) + ' ft</td><td class="num">' + ft(r.ascend_m) + ' ft</td>' +
    '<td class="num">' + fmtT(r.time_ba_min) + '</td><td></td></tr></table>' +
    '<p class="note">' + (r.cached ? 'Served from the permanent cache.' : 'Fresh route from the server (' + r.ms + ' ms), now cached.') +
    ' If either summit is more than about 250 m from any mapped path, BRouter reports it cannot place that point.</p>';
  whenMap(() => { clearMap(); line(r.coords, '#ea580c', 4, .95); pin(r.coords[0], r.a.ref, '#2563eb'); pin(r.coords[r.coords.length - 1], r.b.ref, '#16a34a'); fit(r.coords); });
};
</script>
<?php if ($bkey): ?><script src="https://maps.googleapis.com/maps/api/js?key=<?= bpp_h($bkey) ?>&callback=initMap&loading=async" async defer></script><?php endif; ?>
</body>
</html>
