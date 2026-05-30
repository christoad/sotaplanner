<?php
/**
 * admin_batch_gpx.php
 *
 * Batch-imports SOTAmaps GPX tracks for every summit in the DB that has no
 * GPX track yet.  Admin (KI6CR) only.
 *
 * JSON actions (GET ?action=):
 *   stats   — counts of total / already-have-GPX / still-need-GPX summits
 *   queue   — full list of (id, sota_ref, name, group_id) needing import
 *   process — fetch + import best SOTAmaps track for one summit
 */

require_once 'config.php';
session_start();
requireLogin();

if (!isGodMode()) {
    http_response_code(403);
    die('Admin only.');
}

$db = getDbConnection();

// Safety: refuse to run on staging — staging shares the production DB, so
// imported file paths would point to the staging filesystem and corrupt records.
$host = $_SERVER['HTTP_HOST'] ?? '';
if (str_contains($host, 'christopherreddick.com')) {
    http_response_code(403);
    die('<b>Error:</b> This tool cannot run on the staging server — staging shares the production database. Deploy and run on <b>sotaplanner.com</b> only.');
}

// ── JSON API ──────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';

if ($action === 'stats') {
    header('Content-Type: application/json');
    $total    = $db->query("SELECT COUNT(DISTINCT sota_ref) FROM summits WHERE sota_ref != ''")->fetchColumn();
    $with_gpx = $db->query("SELECT COUNT(DISTINCT s.sota_ref) FROM summits s INNER JOIN gpx_tracks g ON g.summit_id = s.id")->fetchColumn();
    echo json_encode([
        'total'    => (int)$total,
        'with_gpx' => (int)$with_gpx,
        'need_gpx' => (int)$total - (int)$with_gpx,
    ]);
    exit;
}

if ($action === 'queue') {
    header('Content-Type: application/json');
    // One row per unique sota_ref that has NO gpx_tracks record on any of its summit rows
    $rows = $db->query("
        SELECT MIN(s.id) AS id, s.sota_ref, MIN(s.name) AS name, MIN(s.planning_group_id) AS group_id
        FROM summits s
        WHERE s.sota_ref IS NOT NULL AND s.sota_ref != ''
          AND NOT EXISTS (SELECT 1 FROM gpx_tracks g WHERE g.summit_id = s.id)
        GROUP BY s.sota_ref
        ORDER BY s.sota_ref
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['summits' => $rows]);
    exit;
}

if ($action === 'process') {
    header('Content-Type: application/json');
    $summit_id = (int)($_GET['summit_id'] ?? 0);
    $group_id  = (int)($_GET['group_id']  ?? 0);
    $sota_ref  = trim($_GET['sota_ref']   ?? '');

    if (!$summit_id || !$sota_ref) {
        echo json_encode(['status' => 'error', 'msg' => 'Missing parameters']);
        exit;
    }

    // Skip if a track already exists for this summit (any group)
    $already = $db->prepare("SELECT id, file_path FROM gpx_tracks WHERE summit_id = ?");
    $already->execute([$summit_id]);
    $existing = $already->fetch();
    if ($existing && file_exists($existing['file_path'])) {
        echo json_encode(['status' => 'skip', 'msg' => 'Already has a track']);
        exit;
    }

    // Fetch track list from SOTA Mapping Project API
    $parts   = explode('/', $sota_ref, 2);
    $api_url = 'https://api-db.sota.org.uk/smp/gpx/summit/'
             . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1] ?? '');

    $ctx = stream_context_create(['http' => [
        'timeout'       => 20,
        'ignore_errors' => true,
        'header'        => "User-Agent: SOTAPlanner-BatchImport/1.0\r\n",
    ]]);
    $raw = @file_get_contents($api_url, false, $ctx);

    if ($raw === false || $raw === '') {
        echo json_encode(['status' => 'error', 'msg' => 'API unreachable']);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || count($data) === 0) {
        echo json_encode(['status' => 'none', 'msg' => 'No tracks on SOTAmaps']);
        exit;
    }

    // Pick the track with the most points (most detailed)
    $best = null;
    foreach ($data as $t) {
        $pts = isset($t['points']) ? count($t['points']) : 0;
        if (!$best || $pts > count($best['points'] ?? [])) {
            $best = $t;
        }
    }

    $points = $best['points'] ?? [];
    if (count($points) < 2) {
        echo json_encode(['status' => 'error', 'msg' => 'Best track has too few points (' . count($points) . ')']);
        exit;
    }

    // Sort points by index
    usort($points, fn($a, $b) => intval($a['pt_index']) - intval($b['pt_index']));

    // Build GPX XML (no timestamps — route only)
    $gpx_xml = _batch_build_gpx($points, $best['track_title'] ?? $sota_ref, $best['callsign'] ?? '');

    // Save to disk
    $upload_dir = __DIR__ . '/gpx_files';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $safe_ref = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sota_ref);
    $filename = $safe_ref . '_sotamaps_' . intval($best['hdr_id']) . '.gpx';
    $filepath = $upload_dir . '/' . $filename;

    if (file_put_contents($filepath, $gpx_xml) === false) {
        echo json_encode(['status' => 'error', 'msg' => 'Could not write file to disk']);
        exit;
    }

    // Analyse with existing pipeline
    $gpx_stats = analyze_gpx_track($filepath, null);
    if (!$gpx_stats) {
        @unlink($filepath);
        echo json_encode(['status' => 'error', 'msg' => 'GPX analysis failed']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO gpx_tracks (
                summit_id, planning_group_id, filename, file_path,
                total_time, hiking_time, activation_time, rest_break_time,
                total_distance, hiking_distance, max_elevation, min_elevation,
                elevation_gain, elevation_loss, avg_speed, hiking_speed,
                num_points, summit_lat, summit_lon, using_api,
                activation_zone_polygon, activation_zone_method,
                use_for_hike_time, use_for_elevation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->execute([
            $summit_id, $group_id, $filename, $filepath,
            $gpx_stats['total_time'],      $gpx_stats['hiking_time'],
            $gpx_stats['activation_time'], $gpx_stats['rest_break_time'],
            $gpx_stats['total_distance'],  $gpx_stats['hiking_distance'],
            $gpx_stats['max_elevation'],   $gpx_stats['min_elevation'],
            $gpx_stats['elevation_gain'],  $gpx_stats['elevation_loss'],
            $gpx_stats['avg_speed'],       $gpx_stats['hiking_speed'],
            $gpx_stats['num_points'],      $gpx_stats['summit_lat'],
            $gpx_stats['summit_lon'],      $gpx_stats['using_api'] ? 1 : 0,
            $gpx_stats['activation_zone_polygon'], $gpx_stats['activation_zone_method'],
        ]);

        echo json_encode([
            'status'       => 'imported',
            'title'        => $best['track_title'] ?? $sota_ref,
            'callsign'     => $best['callsign'] ?? '',
            'track_count'  => count($data),
            'points'       => count($points),
            'dist_mi'      => round($gpx_stats['total_distance'] * 0.621371, 2),
            'gain_ft'      => round($gpx_stats['elevation_gain'] * 3.28084),
        ]);

    } catch (Exception $e) {
        @unlink($filepath);
        echo json_encode(['status' => 'error', 'msg' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Batch GPX Import — Admin</title>
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
.page{padding:2rem;max-width:900px;margin:0 auto}
h1{font-size:1.4rem;font-weight:600;margin-bottom:.25rem}
.subtitle{color:var(--ink-3);font-size:.875rem;margin-bottom:2rem}
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
.controls{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1.5rem}
.progress-wrap{background:var(--bg-2);border-radius:100px;height:10px;overflow:hidden;margin-bottom:.5rem}
.progress-bar{height:100%;background:var(--accent);border-radius:100px;transition:width .3s;width:0}
.progress-label{font-size:.8rem;color:var(--ink-3);display:flex;justify-content:space-between}
.log{background:#1a1a1a;border-radius:var(--r-md);font-family:var(--font-mono);font-size:.78rem;line-height:1.7;padding:1rem;height:400px;overflow-y:auto}
.log-entry{display:flex;gap:.75rem;align-items:baseline}
.log-ref{color:#888;flex-shrink:0;min-width:120px}
.log-ok{color:#4ade80} .log-none{color:#888} .log-skip{color:#facc15} .log-err{color:#f87171}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-top:1rem}
.sum-box{background:var(--bg-2);border-radius:var(--r-md);padding:.75rem 1rem;text-align:center}
.sum-val{font-size:1.5rem;font-weight:600}
.sum-lbl{font-size:.7rem;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em}
.delay-row{display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:var(--ink-3)}
.delay-row input{width:60px;padding:.25rem .4rem;border:1px solid var(--border);border-radius:4px;font-family:var(--font-mono);font-size:.8rem;text-align:center}
</style>
</head>
<body>
<div class="page">
  <h1>Batch GPX Import</h1>
  <p class="subtitle">Fetches the best community track from <strong>SOTA Mapping Project</strong> for every summit that has no GPX yet. Route-only (no timestamps) — hike time will not be auto-enabled, but map, elevation profile, and distance will populate.</p>

  <div class="stats-grid">
    <div class="stat"><div class="stat-val ink" id="stat-total">—</div><div class="stat-lbl">Unique summits in DB</div></div>
    <div class="stat"><div class="stat-val green" id="stat-have">—</div><div class="stat-lbl">Already have a track</div></div>
    <div class="stat"><div class="stat-val orange" id="stat-need">—</div><div class="stat-lbl">Need a track</div></div>
  </div>

  <div class="card">
    <h2>Controls</h2>
    <div class="controls">
      <button class="btn btn-primary" id="btn-start" onclick="startImport()">Start Import</button>
      <button class="btn btn-ghost"   id="btn-pause" onclick="pauseImport()" disabled>Pause</button>
      <button class="btn btn-danger"  id="btn-stop"  onclick="stopImport()"  disabled>Stop</button>
    </div>
    <div class="delay-row">
      <span>Delay between requests:</span>
      <input type="number" id="delay-ms" value="400" min="100" max="2000"> ms
      <span style="color:var(--ink-4)">(be polite to the SOTA API)</span>
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
        <div class="sum-box"><div class="sum-val" id="sum-imported" style="color:var(--green)">0</div><div class="sum-lbl">Imported</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-none"     style="color:var(--ink-3)">0</div><div class="sum-lbl">No tracks found</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-skip"     style="color:var(--orange)">0</div><div class="sum-lbl">Skipped</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-err"      style="color:var(--red)">0</div><div class="sum-lbl">Errors</div></div>
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
let queue    = [];
let idx      = 0;
let paused   = false;
let stopped  = false;
let counts   = { imported: 0, none: 0, skip: 0, err: 0 };

// ── Load stats on page load ──────────────────────────────────────────────────
async function loadStats() {
  const r = await fetch('admin_batch_gpx.php?action=stats');
  const d = await r.json();
  document.getElementById('stat-total').textContent = d.total;
  document.getElementById('stat-have').textContent  = d.with_gpx;
  document.getElementById('stat-need').textContent  = d.need_gpx;
}
loadStats();

// ── Start ────────────────────────────────────────────────────────────────────
async function startImport() {
  document.getElementById('btn-start').disabled = true;
  document.getElementById('btn-pause').disabled = false;
  document.getElementById('btn-stop').disabled  = false;
  document.getElementById('summary-box').style.display = 'none';
  counts = { imported: 0, none: 0, skip: 0, err: 0 };
  paused = false;
  stopped = false;
  idx = 0;

  log('🔄 Loading queue…', 'none');
  const r = await fetch('admin_batch_gpx.php?action=queue');
  const d = await r.json();
  queue = d.summits || [];

  if (queue.length === 0) {
    log('✓ All summits already have tracks. Nothing to do.', 'ok');
    finish();
    return;
  }

  log(`Queue: ${queue.length} summit(s) to process`, 'none');
  runNext();
}

// ── Main loop ────────────────────────────────────────────────────────────────
function runNext() {
  if (stopped) { finish(); return; }
  if (paused)  { setTimeout(runNext, 300); return; }
  if (idx >= queue.length) { finish(); return; }

  const s   = queue[idx++];
  const url = `admin_batch_gpx.php?action=process&summit_id=${s.id}&group_id=${s.group_id}&sota_ref=${encodeURIComponent(s.sota_ref)}`;

  setProgress(idx, queue.length);

  fetch(url)
    .then(r => r.json())
    .then(d => {
      if (d.status === 'imported') {
        counts.imported++;
        log(`✓ ${s.sota_ref} — ${d.dist_mi} mi, ${d.gain_ft} ft gain (${d.points} pts, by ${d.callsign || '?'})`, 'ok');
      } else if (d.status === 'none') {
        counts.none++;
        log(`· ${s.sota_ref} — no tracks on SOTAmaps`, 'none');
      } else if (d.status === 'skip') {
        counts.skip++;
        log(`⏭ ${s.sota_ref} — already has track`, 'skip');
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
      const delay = parseInt(document.getElementById('delay-ms').value) || 400;
      setTimeout(runNext, delay);
    });
}

// ── Controls ─────────────────────────────────────────────────────────────────
function pauseImport() {
  paused = !paused;
  document.getElementById('btn-pause').textContent = paused ? 'Resume' : 'Pause';
  if (!paused) log('▶ Resumed', 'skip');
  else         log('⏸ Paused', 'skip');
}
function stopImport() {
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
    log(`✓ Done. Imported: ${counts.imported}  No tracks: ${counts.none}  Errors: ${counts.err}`, 'ok');
    setProgress(queue.length, queue.length);
  }
  loadStats(); // refresh counts
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setProgress(done, total) {
  const pct = total > 0 ? Math.round((done / total) * 100) : 0;
  document.getElementById('progress-bar').style.width  = pct + '%';
  document.getElementById('progress-text').textContent = `${done} / ${total}`;
  document.getElementById('progress-pct').textContent  = pct + '%';
}
function updateSummary() {
  document.getElementById('sum-imported').textContent = counts.imported;
  document.getElementById('sum-none').textContent     = counts.none;
  document.getElementById('sum-skip').textContent     = counts.skip;
  document.getElementById('sum-err').textContent      = counts.err;
  document.getElementById('summary-box').style.display = 'block';
}
function log(msg, type) {
  const box  = document.getElementById('log');
  const line = document.createElement('div');
  line.className = 'log-entry';
  line.innerHTML = `<span class="log-${type}">${escHtml(msg)}</span>`;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
}
function clearLog() {
  document.getElementById('log').innerHTML = '';
}
function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>

<?php
// ── Build GPX XML from SMP points array ──────────────────────────────────────
function _batch_build_gpx(array $points, string $title, string $callsign): string {
    $safe_title    = htmlspecialchars($title,    ENT_XML1);
    $safe_callsign = htmlspecialchars($callsign, ENT_XML1);
    $trkpts = '';
    foreach ($points as $pt) {
        $lat = floatval($pt['latitude']);
        $lon = floatval($pt['longitude']);
        $ele = floatval($pt['altitude']);
        if ($ele > 0 && $ele < 9) $ele *= 1000; // some SMP entries store km, not m
        $trkpts .= sprintf(
            "    <trkpt lat=\"%.7f\" lon=\"%.7f\"><ele>%.1f</ele></trkpt>\n",
            $lat, $lon, $ele
        );
    }
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="SOTAPlanner-BatchImport"
     xmlns="http://www.topografix.com/GPX/1/1">
  <metadata><name>{$safe_title}</name>
    <desc>Batch-imported from SOTA Mapping Project. Submitted by {$safe_callsign}</desc>
  </metadata>
  <trk><name>{$safe_title}</name><trkseg>
{$trkpts}  </trkseg></trk>
</gpx>
XML;
}
