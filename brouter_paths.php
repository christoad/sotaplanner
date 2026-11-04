<?php
/**
 * BRouter Path Finder (God Mode only; linked from God Mode > Data Tools).
 *
 * Tab 1: route between two summits (cached permanently, hike time each way).
 * Tab 2: discover hiking paths for a summit with no recorded track. Finds mapped footpaths
 *        inside the activation zone, follows each to a road / trailhead / parking lot, saves every
 *        distinct way out, and lets you accept or reject each one.
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

$db   = getDbConnection();
$me   = getCurrentCallsign();
$bkey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : '';

/* ───────── AJAX endpoints ───────── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    @set_time_limit(290);
    $act = $_GET['ajax'];
    try {
        if ($act === 'route') {
            $profile = in_array($_GET['profile'] ?? '', brouter_profiles(), true) ? $_GET['profile'] : 'hiking-mountain';
            $r = brouter_route_summits($_GET['a'] ?? '', $_GET['b'] ?? '', $profile);
            if ($r['ok']) $r['coords'] = array_map(fn($c) => [$c[0], $c[1]], bp_thin($r['coords'], 500));
            echo json_encode($r);
        } elseif ($act === 'discover') {
            echo json_encode(bp_discover($_GET['ref'] ?? '', (int)($_GET['radius'] ?? 5000)));
        } elseif ($act === 'list') {
            $ref = strtoupper(trim($_GET['ref'] ?? ''));
            $s = get_sota_cache_summit($ref);
            echo json_encode(['ok' => (bool)$s, 'summit' => $s, 'candidates' => $s ? bp_load_candidates($ref) : []]);
        } elseif ($act === 'refine') {
            $r = bp_refine_candidate((int)($_GET['id'] ?? 0));
            echo json_encode($r);
        } elseif ($act === 'review') {
            $status = $_GET['status'] ?? '';
            if (!in_array($status, ['new', 'accepted', 'rejected'], true)) { echo json_encode(['ok' => false]); exit; }
            $db->prepare("UPDATE brouter_path_candidates SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
               ->execute([$status, $me, (int)($_GET['id'] ?? 0)]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage() . ' (has db_migrate.php been run?)']);
    }
    exit;
}

$mode = ($_GET['mode'] ?? 'discover') === 'summits' ? 'summits' : 'discover';
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
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.25rem;box-shadow:var(--shadow-sm);margin-bottom:1rem}
.tabs{display:flex;gap:.5rem;margin-bottom:1rem}
.tab{padding:.4rem .9rem;border-radius:100px;border:1px solid var(--border-2);background:var(--surface);color:var(--ink-2);font-size:.8rem;font-weight:500;text-decoration:none}
.tab.active{background:var(--ink);border-color:var(--ink);color:#fff}
.row{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end}
label{display:block;font-size:.72rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem}
select,input[type=text]{padding:.55rem .75rem;border:1px solid var(--border);border-radius:var(--r-md);font-family:var(--font-sans);font-size:.875rem;background:var(--surface);color:var(--ink)}
input[type=text]{min-width:170px}
.btn{height:36px;padding:0 1rem;border-radius:var(--r-md);border:none;background:var(--ink);color:#fff;font-family:var(--font-sans);font-size:.875rem;font-weight:500;cursor:pointer}
.btn:hover{background:var(--ink-2)} .btn:disabled{opacity:.5;cursor:default}
.btn-sm{height:28px;padding:0 .7rem;font-size:.78rem}
.btn-green{background:var(--green)} .btn-red{background:var(--red)} .btn-ghost{background:transparent;color:var(--ink-2);border:1px solid var(--border-2)} .btn-ghost:hover{background:var(--bg-2)}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);padding:.5rem .6rem;border-bottom:1px solid var(--border)}
td{padding:.6rem;border-bottom:1px solid var(--bg-3);vertical-align:top}
td.num,th.num{text-align:right;font-family:var(--font-mono);white-space:nowrap}
.sw{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:.5rem;vertical-align:-1px}
.delta{color:var(--ink-3);font-size:.75rem;display:block}
.good{color:var(--green)} .bad{color:var(--red)}
.msg-error{background:var(--red-bg);color:var(--red);border:1px solid oklch(85% 0.08 22);padding:.75rem 1rem;border-radius:var(--r-md);margin-bottom:1rem;font-size:.875rem}
.msg-info{background:var(--accent-bg);color:var(--ink-2);border:1px solid var(--accent-border);padding:.75rem 1rem;border-radius:var(--r-md);margin-bottom:1rem;font-size:.875rem}
.note{font-size:.8rem;color:var(--ink-3);margin-top:.6rem}
.pill{display:inline-block;font-size:.65rem;font-weight:600;border-radius:var(--r-sm);padding:.1rem .4rem;text-transform:uppercase;letter-spacing:.05em;background:var(--bg-2);color:var(--ink-2)}
.pill.accepted{background:var(--green-bg);color:var(--green)} .pill.rejected{background:var(--red-bg);color:var(--red)}
tr.sel td{background:var(--accent-bg)} tr.rej td{opacity:.5}
#map{height:540px;border-radius:var(--r-lg);border:1px solid var(--border);margin-bottom:1rem}
</style>
</head>
<body>
<nav class="topbar">
    <a class="logo" href="index.php"><img src="sota-planner-logo.svg" width="32" height="32" alt=""><span>SOTAplanner</span></a>
    <nav><a href="god_mode.php?tab=data">God Mode</a><a href="brouter_test.php">Test bench</a></nav>
</nav>
<div class="page">
    <h1>Path Finder</h1>
    <p class="sub">Uses our BRouter server and OpenStreetMap. Map tiles on the server currently cover the West Coast only (W125 and W120, N30 to N50).
        <?= brouter_configured() ? '' : '<strong class="bad">(BROUTER_URL / BROUTER_API_KEY missing)</strong>' ?></p>

    <div class="tabs">
        <a class="tab <?= $mode === 'discover' ? 'active' : '' ?>" href="?mode=discover">Discover paths for a summit</a>
        <a class="tab <?= $mode === 'summits' ? 'active' : '' ?>" href="?mode=summits">Route between two summits</a>
    </div>

<?php if ($mode === 'summits'): ?>
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
<?php else: ?>
    <div class="card">
        <div class="row">
            <div><label>Summit (no recorded track)</label><input type="text" id="ref" value="<?= bpp_h($_GET['ref'] ?? '') ?>" placeholder="W6/CT-170"></div>
            <div><label>Search radius</label><select id="radius"><option value="3000">3 km</option><option value="5000" selected>5 km</option><option value="8000">8 km</option></select></div>
            <button class="btn" id="goFind" type="button">Find paths</button>
            <button class="btn btn-ghost" id="goSaved" type="button">Show saved only</button>
        </div>
        <p class="note">Looks in OpenStreetMap for footpaths and tracks inside the activation zone, then follows each one outwards until it meets a road, a trailhead or a parking lot.
            Each distinct way out is saved for review, and BRouter then works out its real distance and climb. Re-running keeps anything you already accepted or rejected.</p>
    </div>
    <div id="msg"></div>
    <div id="map"></div>
    <div class="card" id="listCard" style="display:none"></div>
<?php endif; ?>
</div>

<script>
const MODE = <?= json_encode($mode) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const mi = m => (m / 1609.344).toFixed(2), ft = m => Math.round(m * 3.28084).toLocaleString();
const fmtT = m => m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
const COLORS = ['#ea580c','#2563eb','#16a34a','#9333ea','#db2777','#0891b2','#ca8a04','#475569','#dc2626','#0d9488'];
let map = null, overlays = [];
let mapReady = false, mapQueue = [];
function initMap() { mapReady = true; map = new google.maps.Map($('map'), {mapTypeId:'terrain', center:{lat:36,lng:-119}, zoom:6, streetViewControl:false}); mapQueue.forEach(f => f()); mapQueue = []; }
function whenMap(f) { mapReady ? f() : mapQueue.push(f); }
function clearMap() { overlays.forEach(o => o.setMap(null)); overlays = []; }
function line(pts, color, w, op, dash) {
  const o = new google.maps.Polyline({map, path: pts.map(p => ({lat:p[0], lng:p[1]})), strokeColor: color, strokeWeight: w, strokeOpacity: op});
  overlays.push(o); return o;
}
function pin(p, title, color) {
  const o = new google.maps.Marker({map, position:{lat:p[0], lng:p[1]}, title, icon:{path:google.maps.SymbolPath.CIRCLE, scale:7, fillColor:color, fillOpacity:1, strokeColor:'#fff', strokeWeight:2}});
  overlays.push(o); return o;
}
function fit(pts) { const b = new google.maps.LatLngBounds(); pts.forEach(p => b.extend({lat:p[0], lng:p[1]})); map.fitBounds(b); }
function msg(html, cls) { $('msg').innerHTML = html ? '<div class="' + (cls || 'msg-info') + '">' + html + '</div>' : ''; }
async function api(q) { try { return await (await fetch('?ajax=' + q)).json(); } catch (e) { return {ok:false, error:'Request failed: ' + e.message}; } }

<?php if ($mode === 'summits'): ?>
$('goRoute').onclick = async () => {
  const a = $('refA').value.trim(), b = $('refB').value.trim();
  $('goRoute').disabled = true; msg('Routing...'); $('result').style.display = 'none';
  const r = await api('route&a=' + encodeURIComponent(a) + '&b=' + encodeURIComponent(b) + '&profile=' + encodeURIComponent($('profile').value));
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
<?php else: ?>
let cands = [], sel = null, zone = [], summit = null;
function draw() {
  whenMap(() => {
    clearMap(); const all = [];
    zone.forEach(z => { const o = new google.maps.Polygon({map, paths:z.map(p => ({lat:p[0], lng:p[1]})), strokeColor:'#9333ea', strokeWeight:2, fillColor:'#9333ea', fillOpacity:.12}); overlays.push(o); z.forEach(p => all.push(p)); });
    if (summit) { pin([summit.lat, summit.lon], summit.ref, '#1C1B19'); all.push([summit.lat, summit.lon]); }
    cands.forEach((c, i) => {
      if (c.status === 'rejected' && sel !== c.id) return;
      const col = COLORS[i % COLORS.length], on = sel === null || sel === c.id;
      const pts = (c.route && c.route.length) ? c.route : c.osm;
      line(pts, col, on ? 5 : 2, on ? .95 : .4);
      pin(c.exit, c.type + (c.name ? ': ' + c.name : ''), col);
      pts.forEach(p => all.push(p));
    });
    if (all.length) fit(all);
  });
}
function render() {
  if (!cands.length) { $('listCard').style.display = 'none'; return; }
  let h = '<table><tr><th>#</th><th>Way out</th><th class="num">Distance</th><th class="num">Climb</th><th class="num">Hike up</th><th>Review</th></tr>';
  cands.forEach((c, i) => {
    const col = COLORS[i % COLORS.length];
    h += '<tr data-id="' + c.id + '" class="' + (sel === c.id ? 'sel ' : '') + (c.status === 'rejected' ? 'rej' : '') + '" style="cursor:pointer">' +
      '<td><span class="sw" style="background:' + col + '"></span>' + (i + 1) + '</td>' +
      '<td><strong>' + esc(c.type) + '</strong>' + (c.name ? ': ' + esc(c.name) : '') + ' <span class="pill ' + c.status + '">' + c.status + '</span>' +
        '<span class="delta">' + (c.in_zone ? 'enters the zone' : 'nearest path to the zone') + ', ' + mi(c.graph_m) + ' mi along the mapped path (' + c.exit[0].toFixed(5) + ', ' + c.exit[1].toFixed(5) + ')' + (c.note ? '<br>' + esc(c.note) : '') + '</span></td>' +
      (c.refined ? '<td class="num">' + mi(c.dist_m) + ' mi</td><td class="num">' + ft(c.gain_m) + ' ft</td><td class="num">' + fmtT(c.time_min) + '</td>'
                 : '<td class="num" colspan="3"><span class="delta">' + (c.note ? 'not routed' : 'routing...') + '</span></td>') +
      '<td style="white-space:nowrap"><button class="btn btn-sm btn-green" data-act="accepted">Accept</button> <button class="btn btn-sm btn-red" data-act="rejected">Reject</button> <button class="btn btn-sm btn-ghost" data-act="new">Reset</button></td></tr>';
  });
  h += '</table><p class="note">Click a row to highlight it on the map. Distance and climb are BRouter\'s numbers for the whole way from the road or trailhead to the summit. Hike time is the standard SOTAplanner pace. Accepted and rejected choices are saved for later use.</p>';
  $('listCard').innerHTML = h; $('listCard').style.display = '';
  $('listCard').querySelectorAll('tr[data-id]').forEach(tr => tr.onclick = async ev => {
    const id = +tr.dataset.id, b = ev.target.closest('button');
    if (b) { await api('review&id=' + id + '&status=' + b.dataset.act); const c = cands.find(x => x.id === id); c.status = b.dataset.act; }
    else sel = (sel === id ? null : id);
    render(); draw();
  });
}
async function refineAll() {
  for (const c of cands) {
    if (c.refined || c.note) continue;
    msg('Routing candidate ' + (cands.indexOf(c) + 1) + ' of ' + cands.length + ' with BRouter...');
    await api('refine&id=' + c.id);
    const l = await api('list&ref=' + encodeURIComponent($('ref').value.trim()));
    if (l.ok) { const keep = sel; cands = l.candidates; sel = keep; render(); draw(); }
    await new Promise(r => setTimeout(r, 300));
  }
  msg('');
}
async function show(r, note) {
  cands = r.candidates || []; sel = null; if (r.zone) zone = r.zone; if (r.summit) summit = r.summit;
  render(); draw();
  const parts = [];
  if (r.summit) parts.push('<strong>' + esc(r.summit.ref) + ' ' + esc(r.summit.name) + '</strong>' + (r.zone_source ? ' (zone from ' + esc(r.zone_source) + ')' : ''));
  if (note) parts.push(esc(note));
  if (r.saved !== undefined) parts.push(r.entries + ' path points at the zone, ' + (r.path_ways || 0) + ' mapped paths nearby, ' + cands.length + ' ways out saved.');
  msg(parts.join('<br>'), cands.length ? 'msg-info' : 'msg-error');
  if (cands.some(c => !c.refined && !c.note)) await refineAll();
}
$('goFind').onclick = async () => {
  const ref = $('ref').value.trim(); if (!ref) return;
  $('goFind').disabled = true; msg('Reading the zone and OpenStreetMap paths. This can take up to a minute...');
  const r = await api('discover&ref=' + encodeURIComponent(ref) + '&radius=' + $('radius').value);
  $('goFind').disabled = false;
  if (!r.ok) { msg(esc(r.error || 'Failed'), 'msg-error'); return; }
  await show(r, r.note);
};
$('goSaved').onclick = async () => {
  const ref = $('ref').value.trim(); if (!ref) return;
  const r = await api('list&ref=' + encodeURIComponent(ref));
  if (!r.ok) { msg('Unknown summit', 'msg-error'); return; }
  r.zone = []; await show(r, r.candidates.length ? '' : 'Nothing saved for this summit yet.');
};
<?php endif; ?>
</script>
<?php if ($bkey): ?><script src="https://maps.googleapis.com/maps/api/js?key=<?= bpp_h($bkey) ?>&callback=initMap&loading=async" async defer></script><?php endif; ?>
</body>
</html>
