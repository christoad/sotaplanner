<?php
/**
 * admin_batch_gpx.php
 *
 * Batch-imports SOTAmaps GPX tracks into the global GPX library (global_gpx_tracks),
 * one SOTA association at a time. Admin (KI6CR) only. Run on production only.
 *
 * When a track is imported, it is also retroactively linked to any existing
 * summit rows in planning groups that don't yet have a track.
 *
 * JSON actions:
 *   associations            — list of all associations with summit counts + library coverage
 *   stats?association=W7O   — counts for one association
 *   queue?association=W7O   — sota_refs in that association not yet in library
 *   process?sota_ref=W7O/NC-001 — fetch + import best SOTAmaps track into global library
 */

require_once 'config.php';
require_once 'sota_cache_helper.php';
require_once 'gpx_import_lib.php';
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
    die('<b>Error:</b> This tool cannot run on the staging server — staging shares the production database. Deploy and run on <b>sotaplanner.com</b> only.');
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function _read_cache_associations(): array {
    $cache_file = __DIR__ . '/sota_cache.csv.gz';
    $assoc = [];
    if (!file_exists($cache_file)) return [];
    $gz = @gzopen($cache_file, 'rb');
    if (!$gz) return [];
    while (!gzeof($gz)) {
        $line = gzgets($gz, 512);
        if (!$line) continue;
        $s = explode('|', rtrim($line, "\r\n"), 2);
        if (count($s) < 2) continue;
        $slash = strpos($s[0], '/');
        if ($slash === false) continue;
        $code = substr($s[0], 0, $slash);
        $assoc[$code] = ($assoc[$code] ?? 0) + 1;
    }
    gzclose($gz);
    ksort($assoc);
    return $assoc;
}

function _get_refs_for_assoc(string $assoc): array {
    $safe    = preg_replace('/[^A-Za-z0-9]/', '', $assoc);
    $cache_f = __DIR__ . '/assoc_refs_cache_' . $safe . '.json';

    // Serve from 24-hour file cache when available
    if (file_exists($cache_f) && (time() - filemtime($cache_f)) < 86400) {
        return json_decode(file_get_contents($cache_f), true) ?: [];
    }

    $prefix = $assoc . '/';
    $refs   = [];

    // Primary: read from local sota_cache.csv.gz (built by rebuild_sota_cache.php)
    // Format: code|name|norm_name|points|alt_ft — filter by association prefix
    if (file_exists(SOTA_CACHE_FILE)) {
        $gz = @gzopen(SOTA_CACHE_FILE, 'rb');
        if ($gz) {
            while (!gzeof($gz)) {
                $line = gzgets($gz, 512);
                if (!$line) continue;
                $parts = explode('|', rtrim($line, "\r\n"), 2);
                if (isset($parts[0]) && strpos($parts[0], $prefix) === 0) {
                    $refs[] = $parts[0];
                }
            }
            gzclose($gz);
        }
    }

    // Fallback: download full SOTA summit list and stream-parse (same method as rebuild_sota_cache.php)
    if (empty($refs)) {
        set_time_limit(300);
        $tmp = tempnam(sys_get_temp_dir(), 'sota_');
        $fp  = fopen($tmp, 'wb');
        if ($fp) {
            $ch = curl_init('https://api2.sota.org.uk/api/summits/search?term=x');
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: SOTAPlanner/2.0'],
            ]);
            curl_exec($ch);
            $curl_err  = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if (!$curl_err && $http_code === 200) {
                $fh = fopen($tmp, 'rb');
                $buf = ''; $depth = 0; $in_str = false; $escaped = false; $in_obj = false;
                while (!feof($fh)) {
                    $chunk = fread($fh, 65536);
                    if ($chunk === false || $chunk === '') continue;
                    for ($i = 0, $len = strlen($chunk); $i < $len; $i++) {
                        $c = $chunk[$i];
                        if (!$in_obj) { if ($c === '{') { $in_obj = true; $buf = '{'; $depth = 1; } continue; }
                        $buf .= $c;
                        if ($escaped) { $escaped = false; continue; }
                        if ($in_str)  { if ($c === '\\') $escaped = true; elseif ($c === '"') $in_str = false; continue; }
                        if ($c === '"') { $in_str = true; continue; }
                        if ($c === '{') { $depth++; continue; }
                        if ($c === '}') {
                            $depth--;
                            if ($depth > 0) continue;
                            $s = json_decode($buf, true);
                            $buf = ''; $in_obj = false;
                            if (!$s) continue;
                            $code = trim($s['summitCode'] ?? '');
                            if ($code && strpos($code, $prefix) === 0) $refs[] = $code;
                        }
                    }
                }
                fclose($fh);
            }
            @unlink($tmp);
        }
    }

    if (!empty($refs)) {
        file_put_contents($cache_f, json_encode($refs));
    }
    return $refs;
}

// ── JSON API ──────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';

// Quick API structure test — visit ?action=api_test in browser
if ($action === 'api_test') {
    header('Content-Type: text/plain');
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: SOTAPlanner/1.0\r\n"]]);

    echo "=== Regions for W6 ===\n";
    $raw = @file_get_contents('https://api2.sota.org.uk/api/regions/W6', false, $ctx);
    echo "Bytes: " . strlen($raw) . "\n";
    echo "Raw: " . $raw . "\n";
    $regions = json_decode($raw, true);
    echo "Count: " . count((array)$regions) . "\n";
    if (!empty($regions[0])) {
        echo "Keys: " . implode(', ', array_keys($regions[0])) . "\n";
        echo "First: " . json_encode($regions[0]) . "\n\n";

        $regionCode = $regions[0]['regionCode'] ?? $regions[0]['code'] ?? null;
        echo "=== Summits for W6/{$regionCode} ===\n";
        $raw2 = @file_get_contents("https://api2.sota.org.uk/api/summits/W6/{$regionCode}", false, $ctx);
        echo "Bytes: " . strlen($raw2) . "\n";
        $summits = json_decode($raw2, true);
        echo "Count: " . count((array)$summits) . "\n";
        if (!empty($summits[0])) {
            echo "Keys: " . implode(', ', array_keys($summits[0])) . "\n";
            echo "First: " . json_encode($summits[0]) . "\n";
        }
    }
    exit;
}

// Debug endpoint — visit ?action=debug in browser to see raw diagnostic info
if ($action === 'debug') {
    header('Content-Type: text/plain');
    echo "=== admin_batch_gpx.php debug ===\n\n";

    // 1. Can we reach the SOTA API?
    echo "1. Testing SOTA API (https://api2.sota.org.uk/api/associations)...\n";
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: SOTAPlanner/1.0\r\n"]]);
    $t0  = microtime(true);
    $raw = @file_get_contents('https://api2.sota.org.uk/api/associations', false, $ctx);
    $elapsed = round((microtime(true) - $t0) * 1000);
    if ($raw === false) {
        echo "   FAILED — file_get_contents returned false ({$elapsed}ms)\n";
        echo "   Error: " . print_r(error_get_last(), true) . "\n";
    } else {
        echo "   OK — got " . strlen($raw) . " bytes in {$elapsed}ms\n";
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            echo "   JSON decode FAILED. First 200 chars: " . substr($raw, 0, 200) . "\n";
        } else {
            echo "   Decoded " . count($data) . " associations\n";
            echo "   First item keys: " . implode(', ', array_keys($data[0] ?? [])) . "\n";
            echo "   Sample: " . json_encode($data[0] ?? []) . "\n";
        }
    }

    // 2. Local cache file
    echo "\n2. Local SOTA cache file (" . SOTA_CACHE_FILE . ")...\n";
    if (file_exists(SOTA_CACHE_FILE)) {
        echo "   Exists — " . round(filesize(SOTA_CACHE_FILE)/1024) . " KB\n";
    } else {
        echo "   MISSING\n";
    }

    // 3. DB connection
    echo "\n3. Database global_gpx_tracks count...\n";
    try {
        $n = $db->query("SELECT COUNT(*) FROM global_gpx_tracks")->fetchColumn();
        echo "   OK — {$n} rows\n";
    } catch (Exception $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }

    echo "\nDone.\n";
    exit;
}

if ($action === 'associations') {
    header('Content-Type: application/json');

    $cache_file = __DIR__ . '/assoc_list_cache.json';
    $max_age    = 7 * 86400; // re-fetch from SOTA API at most once a week

    // Serve from local cache if it's fresh
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $max_age) {
        echo file_get_contents($cache_file);
        exit;
    }

    // Fetch from SOTA API and cache the result
    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'ignore_errors' => true,
        'header'        => "User-Agent: SOTAPlanner/1.0\r\n",
    ]]);
    $raw  = @file_get_contents('https://api2.sota.org.uk/api/associations', false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;

    if (is_array($data) && count($data) > 0) {
        $result = [];
        foreach ($data as $a) {
            $code = $a['associationCode'] ?? $a['code'] ?? null;
            $name = $a['associationName'] ?? $a['name'] ?? $code;
            if (!$code) continue;
            $result[] = ['code' => $code, 'name' => $name];
        }
        usort($result, fn($a, $b) => strcmp($a['code'], $b['code']));
        $json = json_encode($result);
        file_put_contents($cache_file, $json); // save for next time
        echo $json;
    } else {
        // SOTA API failed — fall back to local summit cache
        $assocs = _read_cache_associations();
        if (empty($assocs)) {
            echo json_encode(['error' => 'SOTA API unreachable and no local cache found']);
            exit;
        }
        $result = [];
        foreach ($assocs as $code => $cnt) {
            $result[] = ['code' => $code, 'name' => $code];
        }
        echo json_encode($result);
    }
    exit;
}

if ($action === 'assoc_stats') {
    header('Content-Type: application/json');
    $rows = $db->query("
        SELECT SUBSTRING_INDEX(sota_ref,'/',1) AS assoc,
               COUNT(*) AS track_count,
               SUM(CASE WHEN sotamaps_track_count > 1 THEN 1 ELSE 0 END) AS multi_track_count,
               MAX(imported_at) AS last_import
        FROM global_gpx_tracks
        GROUP BY assoc
        ORDER BY assoc
    ")->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['assoc']] = [
            'count'             => (int)$row['track_count'],
            'multi_track_count' => (int)$row['multi_track_count'],
            'last_import'       => $row['last_import'],
            'last_run'          => $row['last_import'],
        ];
    }
    // Merge in run records (catches associations scanned but with zero tracks imported)
    $run_rows = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'gpx_run_%'")->fetchAll();
    foreach ($run_rows as $r) {
        $assoc = substr($r['setting_key'], strlen('gpx_run_'));
        $data  = json_decode($r['setting_value'], true) ?? [];
        $ts    = $data['last_run'] ?? null;
        if (!isset($result[$assoc])) {
            $result[$assoc] = ['count' => 0, 'multi_track_count' => 0, 'last_import' => null, 'last_run' => $ts];
        } else {
            $result[$assoc]['last_run'] = $ts;
        }
    }
    echo json_encode($result);
    exit;
}

if ($action === 'record_run') {
    header('Content-Type: application/json');
    $assoc    = preg_replace('/[^A-Za-z0-9]/', '', $_GET['association'] ?? '');
    $imported = (int)($_GET['imported'] ?? 0);
    if ($assoc) {
        $key = 'gpx_run_' . $assoc;
        $val = json_encode(['last_run' => date('Y-m-d H:i:s'), 'imported' => $imported]);
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
           ->execute([$key, $val]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['error' => 'missing association']);
    }
    exit;
}

if ($action === 'queue') {
    header('Content-Type: application/json');
    try {
        $assoc = preg_replace('/[^A-Za-z0-9]/', '', $_GET['association'] ?? '');
        if (!$assoc) { echo json_encode(['summits' => [], 'total' => 0, 'in_library' => 0]); exit; }

        $refs  = _get_refs_for_assoc($assoc);
        $total = count($refs);

        if ($total === 0) {
            echo json_encode(['summits' => [], 'total' => 0, 'in_library' => 0]);
            exit;
        }

        $in_lib = [];
        foreach (array_chunk($refs, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT sota_ref FROM global_gpx_tracks WHERE sota_ref IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) $in_lib[$r] = true;
        }

        $need = array_values(array_filter($refs, fn($r) => !isset($in_lib[$r])));
        echo json_encode(['summits' => $need, 'total' => $total, 'in_library' => count($in_lib)]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    }
    exit;
}

if ($action === 'process') {
    header('Content-Type: application/json');
    $sota_ref = trim($_GET['sota_ref'] ?? '');

    if (!$sota_ref || !preg_match('/^[A-Z0-9]+\/[A-Z0-9]+-\d+$/i', $sota_ref)) {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid sota_ref']);
        exit;
    }

    echo json_encode(import_sotamaps_track($db, $sota_ref));
    exit;
}

// ── HTML PAGE ────────────────────────────────────────────────────────────────
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
  --accent:oklch(52% 0.13 50);
  --green:oklch(52% 0.13 155);
  --red:oklch(52% 0.16 22);
  --orange:oklch(62% 0.14 58);
  --blue:oklch(52% 0.12 240);
  --surface:#fff; --border:#E5E2DA; --border-2:#D4D0C8;
  --font-sans:'DM Sans',system-ui,sans-serif; --font-mono:'DM Mono','Courier New',monospace;
  --r-md:8px; --r-lg:12px;
  --shadow-sm:0 1px 3px rgba(28,27,25,.07);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-sans);background:var(--bg);color:var(--ink);line-height:1.5}
.page{padding:2rem;max-width:960px;margin:0 auto}
h1{font-size:1.4rem;font-weight:600;margin-bottom:.25rem}
.subtitle{color:var(--ink-3);font-size:.875rem;margin-bottom:1.5rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.5rem;margin-bottom:1.5rem;box-shadow:var(--shadow-sm)}
h2{font-size:.95rem;font-weight:600;margin-bottom:1rem;color:var(--ink-2)}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem}
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
.progress-bar{height:100%;background:var(--accent);border-radius:100px;transition:width .3s;width:0}
.progress-label{font-size:.8rem;color:var(--ink-3);display:flex;justify-content:space-between}
.log{background:#1a1a1a;border-radius:var(--r-md);font-family:var(--font-mono);font-size:.78rem;line-height:1.7;padding:1rem;height:440px;overflow-y:auto}
.log-ok{color:#4ade80} .log-none{color:#888} .log-skip{color:#facc15} .log-err{color:#f87171} .log-info{color:#60a5fa}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-top:1rem}
.sum-box{background:var(--bg-2);border-radius:var(--r-md);padding:.75rem 1rem;text-align:center}
.sum-val{font-size:1.5rem;font-weight:600}
.sum-lbl{font-size:.7rem;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em}
.delay-row{display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:var(--ink-3)}
.delay-row input{width:60px;padding:.25rem .4rem;border:1px solid var(--border);border-radius:4px;font-family:var(--font-mono);font-size:.8rem;text-align:center}
.assoc-select{padding:.45rem .7rem;border:1px solid var(--border);border-radius:var(--r-md);font-family:var(--font-mono);font-size:.9rem;color:var(--ink);background:var(--surface);outline:none;cursor:pointer;min-width:220px}
.assoc-select:focus{border-color:var(--accent)}
</style>
</head>
<body>
<div class="page">
  <p style="margin-bottom:.75rem"><a href="god_mode.php?tab=data" style="color:var(--ink-3);font-size:.875rem">&larr; Data Tools</a></p>
  <h1>Batch GPX Import</h1>
  <p class="subtitle">Fetches the best community track from <strong>SOTA Mapping Project</strong> for each summit in the selected association and adds it to the global GPX library. When any user nominates these summits, maps and trail stats will be pre-populated automatically. Tracks already in the library are skipped.</p>

  <!-- Association picker -->
  <div class="card">
    <h2>Select Association</h2>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <select id="assoc-select" class="assoc-select" onchange="onAssocChange()">
        <option value="">— Loading associations… —</option>
      </select>
      <span id="assoc-load-status" style="font-size:.82rem;color:var(--ink-3)"></span>
    </div>
  </div>

  <div class="card">
    <h2 id="stats-heading">Stats</h2>
    <div class="stats-grid">
      <div class="stat"><div class="stat-val ink" id="stat-total">—</div><div class="stat-lbl" id="stat-total-lbl">Summits in association</div></div>
      <div class="stat"><div class="stat-val green" id="stat-have">—</div><div class="stat-lbl">In GPX library</div></div>
      <div class="stat"><div class="stat-val orange" id="stat-need">—</div><div class="stat-lbl">Need import</div></div>
    </div>
    <p id="queue-status" style="font-size:.85rem;color:var(--ink-3)">Choose an association above to begin.</p>
    <p id="last-import-notice" style="display:none;margin-top:.5rem;font-size:.82rem;color:var(--ink-3)"></p>
  </div>

  <div class="card">
    <h2>Controls</h2>
    <div class="controls">
      <button class="btn btn-primary" id="btn-start" onclick="startImport()" disabled>Start Import</button>
      <button class="btn btn-ghost"   id="btn-pause" onclick="pauseImport()" disabled>Pause</button>
      <button class="btn btn-danger"  id="btn-stop"  onclick="stopImport()"  disabled>Stop</button>
    </div>
    <div class="delay-row">
      <span>Delay between requests:</span>
      <input type="number" id="delay-ms" value="500" min="200" max="3000"> ms
      <span style="color:var(--ink-4)">(be polite to the SOTA API)</span>
    </div>
  </div>

  <div class="card">
    <h2>Progress</h2>
    <div class="progress-wrap"><div class="progress-bar" id="progress-bar"></div></div>
    <div class="progress-label">
      <span id="progress-text">Waiting…</span>
      <span id="progress-pct"></span>
    </div>
    <div id="summary-box" style="display:none;margin-top:1rem">
      <div class="summary-grid">
        <div class="sum-box"><div class="sum-val" id="sum-imported" style="color:var(--green)">0</div><div class="sum-lbl">Imported</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-none"     style="color:var(--ink-3)">0</div><div class="sum-lbl">No tracks</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-skip"     style="color:var(--orange)">0</div><div class="sum-lbl">Already had</div></div>
        <div class="sum-box"><div class="sum-val" id="sum-err"      style="color:var(--red)">0</div><div class="sum-lbl">Errors</div></div>
      </div>
    </div>
  </div>

  <div class="card" style="padding:0">
    <div style="padding:.75rem 1.25rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <span style="font-size:.875rem;font-weight:600;color:var(--ink-2)">Live Log</span>
      <button class="btn btn-ghost" style="height:28px;font-size:.75rem" onclick="clearLog()">Clear</button>
    </div>
    <div class="log" id="log"><div style="color:#555">Select an association to load the queue.</div></div>
  </div>

</div>

<script>
let queue       = [];
let idx         = 0;
let paused      = false;
let stopped     = false;
let counts      = { imported: 0, none: 0, skip: 0, err: 0 };
let loadingQueue = false;
let assocStats  = {};

function getAssoc() {
  return document.getElementById('assoc-select').value;
}

// Load associations list on page load
(async () => {
  const sel    = document.getElementById('assoc-select');
  const status = document.getElementById('assoc-load-status');
  try {
    const [assocData, statsData] = await Promise.all([
      fetch('admin_batch_gpx.php?action=associations').then(r => r.json()),
      fetch('admin_batch_gpx.php?action=assoc_stats').then(r => r.json()),
    ]);
    if (assocData.error) throw new Error(assocData.error);
    assocStats = statsData;

    const importedCount = assocData.filter(a => statsData[a.code]).length;

    sel.innerHTML = '<option value="">— Choose an association —</option>';
    assocData.forEach(a => {
      const opt = document.createElement('option');
      opt.value = a.code;
      const s = statsData[a.code];
      if (s) {
        const dateStr = s.last_run || s.last_import;
        const dt      = new Date(dateStr);
        const fmt     = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const multi   = s.multi_track_count > 0 ? ` · ${s.multi_track_count} with alt routes` : '';
        const label   = s.count > 0 ? `${s.count.toLocaleString()} tracks` : 'no tracks found';
        opt.textContent = `${a.code} — ${a.name}  [${label} · ${fmt}${multi}]`;
      } else {
        opt.textContent = `${a.code} — ${a.name}`;
      }
      sel.appendChild(opt);
    });

    status.textContent = `${assocData.length} associations · ${importedCount} imported`;
  } catch (e) {
    status.textContent = 'Error loading associations: ' + e.message;
    status.style.color = 'var(--red)';
  }
})();

async function onAssocChange() {
  const assoc = getAssoc();
  if (!assoc) return;
  if (loadingQueue) return;

  // Reset import state if switching mid-import
  stopped = true;
  queue   = [];
  idx     = 0;

  document.getElementById('btn-start').disabled = true;
  document.getElementById('btn-pause').disabled = true;
  document.getElementById('btn-stop').disabled  = true;
  document.getElementById('stat-total').textContent = '…';
  document.getElementById('stat-have').textContent  = '…';
  document.getElementById('stat-need').textContent  = '…';
  document.getElementById('stats-heading').textContent  = `Stats — ${assoc}`;
  document.getElementById('stat-total-lbl').textContent = `Summits in ${assoc}`;
  document.getElementById('queue-status').textContent   = `Loading ${assoc} summit list…`;
  document.getElementById('queue-status').style.color   = 'var(--ink-3)';
  const notice = document.getElementById('last-import-notice');
  if (assocStats[assoc]) {
    const s       = assocStats[assoc];
    const dateStr = s.last_run || s.last_import;
    const dt      = new Date(dateStr);
    const fmt     = dt.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    if (s.count > 0) {
      notice.textContent = `⚠ Previously imported: ${s.count.toLocaleString()} tracks as of ${fmt}. Only missing summits will be re-queued.`;
    } else {
      notice.textContent = `ℹ Previously scanned ${fmt} — no tracks were found on SOTAmaps.`;
    }
    notice.style.display = 'block';
  } else {
    notice.style.display = 'none';
  }
  document.getElementById('progress-bar').style.width   = '0';
  document.getElementById('progress-text').textContent  = 'Waiting…';
  document.getElementById('progress-pct').textContent   = '';
  document.getElementById('summary-box').style.display  = 'none';
  document.getElementById('log').innerHTML = '';

  loadingQueue = true;
  try {
    const r = await fetch(`admin_batch_gpx.php?action=queue&association=${encodeURIComponent(assoc)}`);
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch (je) { throw new Error('Bad JSON — ' + text.substring(0, 200)); }

    queue = d.summits || [];
    document.getElementById('stat-total').textContent = d.total;
    document.getElementById('stat-have').textContent  = d.in_library;
    document.getElementById('stat-need').textContent  = queue.length;
    document.getElementById('queue-status').textContent =
      queue.length > 0
        ? `Ready — ${queue.length} summit(s) to import. Press Start.`
        : `✓ All ${assoc} summits already in the library.`;
    document.getElementById('btn-start').disabled = (queue.length === 0);
    log(`Queue ready: ${d.total} total ${assoc} summits, ${d.in_library} already in library, ${queue.length} to import.`, 'info');
    stopped = false;
  } catch (e) {
    document.getElementById('queue-status').textContent = 'Error loading queue: ' + e.message;
    document.getElementById('queue-status').style.color = 'var(--red)';
    log('Error loading queue: ' + e.message, 'err');
  }
  loadingQueue = false;
}

function startImport() {
  const assoc = getAssoc();
  if (!assoc || queue.length === 0) return;
  document.getElementById('btn-start').disabled  = true;
  document.getElementById('btn-pause').disabled  = false;
  document.getElementById('btn-stop').disabled   = false;
  document.getElementById('assoc-select').disabled = true;
  document.getElementById('summary-box').style.display = 'none';
  counts  = { imported: 0, none: 0, skip: 0, err: 0 };
  paused  = false;
  stopped = false;
  idx     = 0;
  log(`Starting import of ${queue.length} ${assoc} summits…`, 'info');
  runNext();
}

function runNext() {
  const assoc = getAssoc();
  if (stopped) { finish(); return; }
  if (paused)  { setTimeout(runNext, 300); return; }
  if (idx >= queue.length) { finish(); return; }

  const sota_ref = queue[idx++];
  setProgress(idx, queue.length);

  fetch(`admin_batch_gpx.php?action=process&sota_ref=${encodeURIComponent(sota_ref)}`)
    .then(r => r.json())
    .then(d => {
      if (d.status === 'imported') {
        counts.imported++;
        let msg = `✓ ${sota_ref} — ${d.dist_mi} mi, ${d.gain_ft} ft gain (${d.points} pts)`;
        if (d.backfilled > 0) msg += ` [backfilled ${d.backfilled}]`;
        log(msg, 'ok');
      } else if (d.status === 'none') {
        counts.none++;
        log(`· ${sota_ref} — no tracks on SOTAmaps`, 'none');
      } else if (d.status === 'skip') {
        counts.skip++;
        log(`⏭ ${sota_ref} — already in library`, 'skip');
      } else {
        counts.err++;
        log(`✗ ${sota_ref} — ${d.msg}`, 'err');
      }
      updateSummary();
    })
    .catch(e => {
      counts.err++;
      log(`✗ ${sota_ref} — ${e.message}`, 'err');
      updateSummary();
    })
    .finally(() => {
      const delay = parseInt(document.getElementById('delay-ms').value) || 500;
      setTimeout(runNext, delay);
    });
}

function pauseImport() {
  paused = !paused;
  document.getElementById('btn-pause').textContent = paused ? 'Resume' : 'Pause';
  log(paused ? '⏸ Paused' : '▶ Resumed', 'skip');
}
function stopImport() { stopped = true; }

function playDone() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    [[523, 0], [659, 0.12], [784, 0.24]].forEach(([freq, t]) => {
      const osc = ctx.createOscillator(), gain = ctx.createGain();
      osc.connect(gain); gain.connect(ctx.destination);
      osc.type = 'sine'; osc.frequency.value = freq;
      const s = ctx.currentTime + t;
      gain.gain.setValueAtTime(0, s);
      gain.gain.linearRampToValueAtTime(0.28, s + 0.02);
      gain.gain.linearRampToValueAtTime(0, s + 0.22);
      osc.start(s); osc.stop(s + 0.25);
    });
  } catch(e) {}
}

function finish() {
  document.getElementById('btn-start').disabled    = true;
  document.getElementById('btn-pause').disabled    = true;
  document.getElementById('btn-stop').disabled     = true;
  document.getElementById('assoc-select').disabled = false;
  document.getElementById('summary-box').style.display = 'block';
  updateSummary();
  log(`Done. Imported: ${counts.imported}  No tracks: ${counts.none}  Skipped: ${counts.skip}  Errors: ${counts.err}`, 'ok');
  setProgress(queue.length, queue.length);
  playDone();
  const assoc = getAssoc();
  if (assoc) {
    fetch(`admin_batch_gpx.php?action=record_run&association=${encodeURIComponent(assoc)}&imported=${counts.imported}`)
      .then(r => r.json())
      .then(() => {
        // Refresh assocStats so the dropdown reflects the run
        fetch('admin_batch_gpx.php?action=assoc_stats').then(r => r.json()).then(d => { assocStats = d; });
      })
      .catch(() => {});
  }
}

function setProgress(done, total) {
  const pct = total > 0 ? Math.round(done / total * 100) : 0;
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
  line.className = `log-${type}`;
  line.textContent = msg;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
}
function clearLog() { document.getElementById('log').innerHTML = ''; }
</script>
</body>
</html>

