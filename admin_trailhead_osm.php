<?php
/**
 * admin_trailhead_osm.php
 *
 * For every summit in the DB that has no trailhead coordinates, queries the
 * OpenStreetMap Overpass API for tagged trailheads and parking areas within
 * 5 km of the summit. Stores the nearest result as the summit's trailhead.
 *
 * Once a summit has a trailhead, drive time calculations unlock automatically.
 * Admin (KI6CR) only. Run on production only.
 *
 * JSON actions (GET ?action=):
 *   stats   — counts of total / have trailhead / need trailhead
 *   queue   — list of summits needing a trailhead lookup
 *   lookup  — query Overpass for one summit, store result
 */

require_once 'config.php';
session_start();
requireLogin();

if (!isGodMode()) {
    http_response_code(403);
    die('Admin only.');
}

$db = getDbConnection();

// Safety: refuse to run on staging
$host = $_SERVER['HTTP_HOST'] ?? '';
if (str_contains($host, 'christopherreddick.com')) {
    http_response_code(403);
    die('<b>Error:</b> This tool cannot run on the staging server. Deploy and run on <b>sotaplanner.com</b> only.');
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371000;
    $dlat = deg2rad($lat2 - $lat1);
    $dlon = deg2rad($lon2 - $lon1);
    $a = sin($dlat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon/2)**2;
    return $r * 2 * asin(sqrt($a));
}

function overpass_trailhead(float $lat, float $lon, int $radius_m = 5000): ?array {
    $q = <<<OPQ
[out:json][timeout:25];
(
  node["highway"="trailhead"](around:{$radius_m},{$lat},{$lon});
  node["tourism"="trailhead"](around:{$radius_m},{$lat},{$lon});
  node["amenity"="parking"]["access"!="private"]["access"!="no"](around:{$radius_m},{$lat},{$lon});
  way["highway"="trailhead"](around:{$radius_m},{$lat},{$lon});
  way["tourism"="trailhead"](around:{$radius_m},{$lat},{$lon});
  way["amenity"="parking"]["access"!="private"]["access"!="no"](around:{$radius_m},{$lat},{$lon});
);
out center;
OPQ;

    $url = 'https://overpass-api.de/api/interpreter';
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SOTAPlanner-TrailheadLookup/1.0\r\n",
        'content'       => 'data=' . urlencode($q),
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    if (!isset($data['elements']) || !is_array($data['elements'])) return null;

    $candidates = [];
    foreach ($data['elements'] as $el) {
        // Ways use center; nodes use lat/lon directly
        $elat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $elon = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if ($elat === null || $elon === null) continue;

        $tags    = $el['tags'] ?? [];
        $highway = $tags['highway'] ?? '';
        $tourism = $tags['tourism'] ?? '';
        $amenity = $tags['amenity'] ?? '';

        // Score: prefer dedicated trailhead tags over generic parking
        if ($highway === 'trailhead' || $tourism === 'trailhead') {
            $score = 0;
        } elseif ($amenity === 'parking') {
            $score = 1;
        } else {
            $score = 2;
        }

        $dist = haversine_m($lat, $lon, (float)$elat, (float)$elon);
        $candidates[] = [
            'lat'   => (float)$elat,
            'lon'   => (float)$elon,
            'dist'  => $dist,
            'score' => $score,
            'name'  => $tags['name'] ?? '',
            'type'  => $highway ?: $tourism ?: $amenity,
        ];
    }

    if (empty($candidates)) return null;

    // Sort by score first, then distance
    usort($candidates, fn($a, $b) =>
        $a['score'] !== $b['score'] ? $a['score'] - $b['score'] : $a['dist'] <=> $b['dist']
    );

    return $candidates[0];
}

// ── JSON API ──────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';

if ($action === 'stats') {
    header('Content-Type: application/json');
    $total = (int)$db->query("SELECT COUNT(DISTINCT sota_ref) FROM summits WHERE sota_ref != '' AND latitude != 0")->fetchColumn();
    $have  = (int)$db->query("SELECT COUNT(DISTINCT sota_ref) FROM summits WHERE sota_ref != '' AND latitude != 0 AND trailhead_lat IS NOT NULL AND trailhead_lat != 0")->fetchColumn();
    echo json_encode(['total' => $total, 'have' => $have, 'need' => max(0, $total - $have)]);
    exit;
}

if ($action === 'queue') {
    header('Content-Type: application/json');
    $rows = $db->query("
        SELECT MIN(s.id) AS id, s.sota_ref, MIN(s.name) AS name,
               MIN(s.latitude) AS lat, MIN(s.longitude) AS lon
        FROM summits s
        WHERE s.sota_ref IS NOT NULL AND s.sota_ref != ''
          AND s.latitude IS NOT NULL AND s.latitude != 0
          AND (s.trailhead_lat IS NULL OR s.trailhead_lat = 0)
        GROUP BY s.sota_ref
        ORDER BY s.sota_ref
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['summits' => $rows]);
    exit;
}

if ($action === 'lookup') {
    header('Content-Type: application/json');
    $summit_id = (int)($_GET['summit_id'] ?? 0);
    $sota_ref  = trim($_GET['sota_ref']   ?? '');
    $lat       = (float)($_GET['lat']     ?? 0);
    $lon       = (float)($_GET['lon']     ?? 0);

    if (!$summit_id || !$sota_ref || !$lat || !$lon) {
        echo json_encode(['status' => 'error', 'msg' => 'Missing parameters']);
        exit;
    }

    // Skip if trailhead already set on any summit with this ref
    $chk = $db->prepare("SELECT COUNT(*) FROM summits WHERE sota_ref = ? AND trailhead_lat IS NOT NULL AND trailhead_lat != 0");
    $chk->execute([$sota_ref]);
    if ((int)$chk->fetchColumn() > 0) {
        echo json_encode(['status' => 'skip', 'msg' => 'Trailhead already set']);
        exit;
    }

    $result = overpass_trailhead($lat, $lon);

    if (!$result) {
        echo json_encode(['status' => 'none', 'msg' => 'No OSM trailhead or parking found within 5 km']);
        exit;
    }

    // Write to all summit rows with this sota_ref that lack a trailhead
    $upd = $db->prepare("
        UPDATE summits
        SET trailhead_lat = ?, trailhead_lng = ?
        WHERE sota_ref = ? AND (trailhead_lat IS NULL OR trailhead_lat = 0)
    ");
    $upd->execute([$result['lat'], $result['lon'], $sota_ref]);
    $affected = $upd->rowCount();

    echo json_encode([
        'status'   => 'found',
        'lat'      => $result['lat'],
        'lon'      => $result['lon'],
        'dist_m'   => round($result['dist']),
        'type'     => $result['type'],
        'name'     => $result['name'],
        'updated'  => $affected,
    ]);
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OSM Trailhead Lookup — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F7F6F3; --bg-2:#EFEDE8; --ink:#1C1B19; --ink-2:#4A4844; --ink-3:#8C8A86; --ink-4:#B8B5B0;
  --accent:oklch(52% 0.13 50); --accent-bg:oklch(96% 0.04 65); --accent-border:oklch(84% 0.08 65);
  --green:oklch(52% 0.13 155); --green-bg:oklch(95% 0.04 155);
  --red:oklch(52% 0.16 22); --red-bg:oklch(96% 0.04 22);
  --orange:oklch(62% 0.14 58); --orange-bg:oklch(96% 0.05 58);
  --blue:oklch(52% 0.12 240); --blue-bg:oklch(95% 0.04 240);
  --surface:#fff; --border:#E5E2DA; --border-2:#D4D0C8;
  --font-sans:'DM Sans',system-ui,sans-serif; --font-mono:'DM Mono','Courier New',monospace;
  --r-md:8px; --r-lg:12px;
  --shadow-sm:0 1px 3px rgba(28,27,25,.07);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-sans);background:var(--bg);color:var(--ink);line-height:1.5}
.page{padding:2rem;max-width:960px;margin:0 auto}
h1{font-size:1.4rem;font-weight:600;margin-bottom:.25rem}
.subtitle{color:var(--ink-3);font-size:.875rem;margin-bottom:1.5rem;line-height:1.6}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.5rem;margin-bottom:1.5rem;box-shadow:var(--shadow-sm)}
h2{font-size:.95rem;font-weight:600;margin-bottom:1rem;color:var(--ink-2)}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1rem 1.25rem}
.stat-val{font-size:2rem;font-weight:600;line-height:1;margin-bottom:.2rem}
.stat-lbl{font-size:.75rem;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em}
.stat-val.green{color:var(--green)} .stat-val.orange{color:var(--orange)} .stat-val.ink{color:var(--ink)}
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:0 1rem;height:36px;border-radius:var(--r-md);font-family:var(--font-sans);font-size:.875rem;font-weight:500;cursor:pointer;border:none;transition:background .15s}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-primary{background:var(--ink);color:#fff} .btn-primary:hover:not(:disabled){background:var(--ink-2)}
.btn-ghost{background:transparent;color:var(--ink-2);border:1px solid var(--border)} .btn-ghost:hover:not(:disabled){background:var(--bg-2)}
.btn-danger{background:var(--red);color:#fff}
.controls{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1.25rem}
.progress-wrap{background:var(--bg-2);border-radius:100px;height:10px;overflow:hidden;margin-bottom:.5rem}
.progress-bar{height:100%;background:var(--green);border-radius:100px;transition:width .3s;width:0}
.progress-label{font-size:.8rem;color:var(--ink-3);display:flex;justify-content:space-between}
.log{background:#1a1a1a;border-radius:var(--r-md);font-family:var(--font-mono);font-size:.78rem;line-height:1.7;padding:1rem;height:420px;overflow-y:auto}
.log-ok{color:#4ade80} .log-none{color:#888} .log-skip{color:#facc15} .log-err{color:#f87171} .log-info{color:#60a5fa}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-top:1rem}
.sum-box{background:var(--bg-2);border-radius:var(--r-md);padding:.75rem 1rem;text-align:center}
.sum-val{font-size:1.5rem;font-weight:600}
.sum-lbl{font-size:.7rem;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em}
.delay-row{display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:var(--ink-3);margin-top:.75rem}
.delay-row input{width:70px;padding:.25rem .4rem;border:1px solid var(--border);border-radius:4px;font-family:var(--font-mono);font-size:.8rem;text-align:center}
.note-box{background:var(--blue-bg);border:1px solid oklch(84% 0.08 240);border-radius:var(--r-md);padding:.75rem 1rem;font-size:.8rem;color:var(--blue);margin-bottom:1.25rem;line-height:1.6}
</style>
</head>
<body>
<div class="page">
  <h1>OSM Trailhead Lookup</h1>
  <p class="subtitle">Queries <strong>OpenStreetMap</strong> (via Overpass API) for tagged trailheads and public parking areas within 5 km of each summit that has no trailhead location set. The nearest result (prioritising <code>highway=trailhead</code> and <code>tourism=trailhead</code> over generic parking) is stored as the trailhead. This unlocks drive-time calculations for those summits.</p>

  <div class="note-box">
    Results are written directly to the <code>summits</code> table. OSM data quality varies by region — denser areas like W6/W7 are well-tagged; remote international associations may have little parking data. Manually-set trailheads are never overwritten.
  </div>

  <div class="stats-grid">
    <div class="stat"><div class="stat-val ink" id="stat-total">—</div><div class="stat-lbl">Unique summits</div></div>
    <div class="stat"><div class="stat-val green" id="stat-have">—</div><div class="stat-lbl">Have trailhead</div></div>
    <div class="stat"><div class="stat-val orange" id="stat-need">—</div><div class="stat-lbl">Need lookup</div></div>
  </div>

  <div class="card">
    <h2>Controls</h2>
    <div class="controls">
      <button class="btn btn-primary" id="btn-start" onclick="startLookup()">Start Lookup</button>
      <button class="btn btn-ghost"   id="btn-pause" onclick="pauseLookup()" disabled>Pause</button>
      <button class="btn btn-danger"  id="btn-stop"  onclick="stopLookup()"  disabled>Stop</button>
    </div>
    <div class="delay-row">
      <span>Delay between requests:</span>
      <input type="number" id="delay-ms" value="1500" min="500" max="5000"> ms
      <span style="color:var(--ink-4)">(Overpass API is a public service — be considerate)</span>
    </div>
  </div>

  <div class="card">
    <h2>Progress</h2>
    <div class="progress-wrap"><div class="progress-bar" id="progress-bar"></div></div>
    <div class="progress-label">
      <span id="progress-text">Not started</span>
      <span id="progress-pct"></span>
    </div>
    <div id="summary-box" style="display:none">
      <div class="summary-grid">
        <div class="sum-box"><div class="sum-val" id="sum-found"  style="color:var(--green)">0</div><div class="sum-lbl">Trailhead found</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-none"   style="color:var(--ink-3)">0</div><div class="sum-lbl">None in OSM</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-skip"   style="color:var(--orange)">0</div><div class="sum-lbl">Already set</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-err"    style="color:var(--red)">0</div><div class="sum-lbl">Errors</div></div>
      </div>
    </div>
  </div>

  <div class="card" style="padding:0">
    <div style="padding:.75rem 1.25rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <span style="font-size:.875rem;font-weight:600;color:var(--ink-2)">Live Log</span>
      <button class="btn btn-ghost" style="height:28px;font-size:.75rem" onclick="clearLog()">Clear</button>
    </div>
    <div class="log" id="log"><div style="color:#555">Waiting to start…</div></div>
  </div>

  <p style="margin-top:1rem"><a href="admin.php" style="color:var(--ink-3);font-size:.875rem">&larr; Back to admin</a></p>
</div>

<script>
let queue   = [];
let idx     = 0;
let paused  = false;
let stopped = false;
let counts  = { found: 0, none: 0, skip: 0, err: 0 };

async function loadStats() {
  const r = await fetch('admin_trailhead_osm.php?action=stats');
  const d = await r.json();
  document.getElementById('stat-total').textContent = d.total;
  document.getElementById('stat-have').textContent  = d.have;
  document.getElementById('stat-need').textContent  = d.need;
}
loadStats();

async function startLookup() {
  document.getElementById('btn-start').disabled = true;
  document.getElementById('btn-pause').disabled = false;
  document.getElementById('btn-stop').disabled  = false;
  document.getElementById('summary-box').style.display = 'none';
  counts  = { found: 0, none: 0, skip: 0, err: 0 };
  paused  = false;
  stopped = false;
  idx     = 0;

  log('🔄 Loading queue…', 'info');
  const r = await fetch('admin_trailhead_osm.php?action=queue');
  const d = await r.json();
  queue = d.summits || [];

  if (queue.length === 0) {
    log('✓ All summits already have trailhead coordinates.', 'ok');
    finish();
    return;
  }
  log(`Queue: ${queue.length} summit(s) to look up`, 'info');
  runNext();
}

function runNext() {
  if (stopped) { finish(); return; }
  if (paused)  { setTimeout(runNext, 300); return; }
  if (idx >= queue.length) { finish(); return; }

  const s   = queue[idx++];
  const url = `admin_trailhead_osm.php?action=lookup`
            + `&summit_id=${s.id}`
            + `&sota_ref=${encodeURIComponent(s.sota_ref)}`
            + `&lat=${s.lat}&lon=${s.lon}`;

  setProgress(idx, queue.length);

  fetch(url)
    .then(r => r.json())
    .then(d => {
      if (d.status === 'found') {
        counts.found++;
        const typeLabel = d.name ? `${d.type} — "${d.name}"` : d.type;
        log(`✓ ${s.sota_ref}  ${d.dist_m} m away  [${typeLabel}]`, 'ok');
      } else if (d.status === 'none') {
        counts.none++;
        log(`· ${s.sota_ref} — ${d.msg}`, 'none');
      } else if (d.status === 'skip') {
        counts.skip++;
        log(`⏭ ${s.sota_ref} — already has trailhead`, 'skip');
      } else {
        counts.err++;
        log(`✗ ${s.sota_ref} — ${d.msg}`, 'err');
      }
      updateSummary();
    })
    .catch(e => {
      counts.err++;
      log(`✗ ${s.sota_ref} — network error: ${e.message}`, 'err');
      updateSummary();
    })
    .finally(() => {
      const delay = parseInt(document.getElementById('delay-ms').value) || 1500;
      setTimeout(runNext, delay);
    });
}

function pauseLookup() {
  paused = !paused;
  document.getElementById('btn-pause').textContent = paused ? 'Resume' : 'Pause';
  log(paused ? '⏸ Paused' : '▶ Resumed', 'skip');
}
function stopLookup() {
  stopped = true;
  log('⏹ Stopped by user', 'err');
}
function finish() {
  document.getElementById('btn-start').disabled = false;
  document.getElementById('btn-pause').disabled = true;
  document.getElementById('btn-stop').disabled  = true;
  document.getElementById('summary-box').style.display = 'block';
  updateSummary();
  if (!stopped) {
    log(`✓ Done. Found: ${counts.found}  Not in OSM: ${counts.none}  Already set: ${counts.skip}  Errors: ${counts.err}`, 'ok');
    setProgress(queue.length, queue.length);
  }
  loadStats();
}

function setProgress(done, total) {
  const pct = total > 0 ? Math.round((done / total) * 100) : 0;
  document.getElementById('progress-bar').style.width  = pct + '%';
  document.getElementById('progress-text').textContent = `${done} / ${total}`;
  document.getElementById('progress-pct').textContent  = pct + '%';
}
function updateSummary() {
  document.getElementById('sum-found').textContent = counts.found;
  document.getElementById('sum-none').textContent  = counts.none;
  document.getElementById('sum-skip').textContent  = counts.skip;
  document.getElementById('sum-err').textContent   = counts.err;
  document.getElementById('summary-box').style.display = 'block';
}
function log(msg, type) {
  const box  = document.getElementById('log');
  const line = document.createElement('div');
  line.className = `log-${type}`;
  line.textContent = msg;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
}
function clearLog() {
  document.getElementById('log').innerHTML = '';
}
</script>
</body>
</html>
