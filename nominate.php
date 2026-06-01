<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'sota_cache_helper.php';
session_start();
requireLogin();

// Link a global GPX library track to a newly-created summit row.
// Creates a gpx_tracks row pointing at the shared file and fills in
// hike distance, elevation gain, and trailhead on the summit.
function _link_global_gpx(PDO $db, int $summit_id, int $group_id, string $sota_ref): void {
    $st = $db->prepare("SELECT * FROM global_gpx_tracks WHERE sota_ref = ?");
    $st->execute([$sota_ref]);
    $g = $st->fetch();
    if (!$g || !file_exists($g['file_path'])) return;

    // Don't add a second gpx_tracks row if one already exists
    $chk = $db->prepare("SELECT id FROM gpx_tracks WHERE summit_id = ? LIMIT 1");
    $chk->execute([$summit_id]);
    if ($chk->fetch()) return;

    $db->prepare("
        INSERT IGNORE INTO gpx_tracks (
            summit_id, planning_group_id, filename, file_path,
            total_time, hiking_time, activation_time, rest_break_time,
            total_distance, hiking_distance, max_elevation, min_elevation,
            elevation_gain, elevation_loss, avg_speed, hiking_speed,
            num_points, summit_lat, summit_lon, using_api,
            activation_zone_polygon, activation_zone_method,
            use_for_hike_time, use_for_elevation, from_global_library
        ) VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, NULL, 'none', 1, 1, 1)
    ")->execute([
        $summit_id, $group_id, $g['filename'], $g['file_path'],
        $g['total_distance'], $g['total_distance'],
        $g['max_elevation'], $g['min_elevation'],
        $g['elevation_gain'], $g['elevation_loss'],
        $g['num_points'], $g['summit_lat'], $g['summit_lon'],
    ]);

    // Auto-fill summit fields only if not already set
    $updates = [];
    $params  = [];
    if ($g['elevation_gain'] && $g['elevation_gain'] > 0) {
        $updates[] = "hike_elevation_gain_ft = COALESCE(hike_elevation_gain_ft, ?)";
        $params[]  = round($g['elevation_gain'] * 3.28084);
    }
    if ($g['total_distance'] && $g['total_distance'] > 0) {
        $updates[] = "hike_distance_mi = COALESCE(hike_distance_mi, ?)";
        $params[]  = round($g['total_distance'] * 2 * 0.621371, 2);
    }
    // Trailhead coordinates are not derived from GPX data — set by the OSM trailhead lookup tool.
    if ($updates) {
        $params[] = $summit_id;
        $db->prepare("UPDATE summits SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
    }
}

// ── AJAX search endpoint ─────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $hits = search_sota_cache($q);
    if (isset($hits['_no_cache'])) {
        echo json_encode(['error' => 'Summit search cache not built yet. Please contact the site admin.']);
    } else {
        echo json_encode($hits);
    }
    exit;
}

$db = getDbConnection();

$message = '';
$error = '';

// Get current planning group
$current_group = getCurrentPlanningGroup($db);

// Handle direct nomination
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nominate'])) {
    $sota_ref = strtoupper(trim($_POST['sota_ref']));
    
    $api_url = "https://api2.sota.org.uk/api/summits/" . $sota_ref;
    
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $summit_data = json_decode($response, true);
        
        if ($summit_data) {
            $name = $summit_data['name'] ?? $summit_data['summitName'] ?? 'Unknown';
            $region = $summit_data['regionName'] ?? $summit_data['region'] ?? '';
            $points = $summit_data['points'] ?? 1;
            $elevation_m = $summit_data['altM'] ?? $summit_data['altitude'] ?? 0;
            $elevation_ft = $summit_data['altFt'] ?? round($elevation_m * 3.28084);
            $latitude = $summit_data['latitude'] ?? $summit_data['lat'] ?? 0;
            $longitude = $summit_data['longitude'] ?? $summit_data['lng'] ?? $summit_data['long'] ?? 0;
            
            try {
                // Check if this group already nominated this summit
                $stmt = $db->prepare("SELECT id FROM summits WHERE sota_ref = ? AND planning_group_id = ?");
                $stmt->execute([$sota_ref, $current_group['id']]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // Already nominated by this group - redirect to it
                    header("Location: summit_detail.php?id=" . $existing['id'] . "&group=" . $current_group['id']);
                    exit;
                }
                
                // Check if ANY other group has researched this summit (has trail data)
                $stmt = $db->prepare("
                    SELECT * FROM summits 
                    WHERE sota_ref = ? 
                    AND planning_group_id != ? 
                    AND (trail_link IS NOT NULL OR hike_distance_mi IS NOT NULL)
                    LIMIT 1
                ");
                $stmt->execute([$sota_ref, $current_group['id']]);
                $source_summit = $stmt->fetch();
                
                // Create new nomination for THIS group
                $stmt = $db->prepare("
                    INSERT INTO summits 
                    (planning_group_id, source_group_id, uses_shared_data, sota_ref, name, region, points, 
                     elevation_m, elevation_ft, latitude, longitude, nominated_date, sotlas_link, status,
                     trail_link, hike_distance_mi, hike_elevation_gain_ft, difficulty,
                     trailhead_lat, trailhead_lng)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'nominated', ?, ?, ?, ?, ?, ?)
                ");

                $sotlas_link = "https://sotl.as/summits/" . $sota_ref;

                if ($source_summit) {
                    // Use existing research as starting point
                    $stmt->execute([
                        $current_group['id'],
                        $source_summit['planning_group_id'], // Track source
                        true, // Using shared data
                        $sota_ref, $name, $region, $points, $elevation_m, $elevation_ft,
                        $latitude, $longitude, $sotlas_link,
                        $source_summit['trail_link'],
                        $source_summit['hike_distance_mi'],
                        $source_summit['hike_elevation_gain_ft'],
                        $source_summit['difficulty'],
                        $source_summit['trailhead_lat'],
                        $source_summit['trailhead_lng'],
                    ]);
                    
                    $summit_id = $db->lastInsertId();

                    // Copy GPX track from source group if one exists
                    $stmt = $db->prepare("SELECT * FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
                    $stmt->execute([$source_summit['id'], $source_summit['planning_group_id']]);
                    $source_gpx = $stmt->fetch();

                    if ($source_gpx && file_exists($source_gpx['file_path'])) {
                        $upload_dir = __DIR__ . '/gpx_files';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $new_filename = $summit_id . '_grp' . $current_group['id'] . '_' . time() . '.gpx';
                        $new_filepath = $upload_dir . '/' . $new_filename;

                        if (copy($source_gpx['file_path'], $new_filepath)) {
                            $stmt = $db->prepare("
                                INSERT INTO gpx_tracks (
                                    summit_id, planning_group_id, filename, file_path,
                                    total_time, hiking_time, activation_time, rest_break_time,
                                    total_distance, hiking_distance, max_elevation, min_elevation,
                                    elevation_gain, elevation_loss, avg_speed, hiking_speed,
                                    num_points, summit_lat, summit_lon, using_api,
                                    activation_zone_polygon, activation_zone_method,
                                    use_for_hike_time, use_for_elevation, track_type
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $summit_id, $current_group['id'], $new_filename, $new_filepath,
                                $source_gpx['total_time'], $source_gpx['hiking_time'],
                                $source_gpx['activation_time'], $source_gpx['rest_break_time'],
                                $source_gpx['total_distance'], $source_gpx['hiking_distance'],
                                $source_gpx['max_elevation'], $source_gpx['min_elevation'],
                                $source_gpx['elevation_gain'], $source_gpx['elevation_loss'],
                                $source_gpx['avg_speed'], $source_gpx['hiking_speed'],
                                $source_gpx['num_points'], $source_gpx['summit_lat'], $source_gpx['summit_lon'],
                                $source_gpx['using_api'], $source_gpx['activation_zone_polygon'],
                                $source_gpx['activation_zone_method'],
                                $source_gpx['use_for_hike_time'], $source_gpx['use_for_elevation'],
                                $source_gpx['track_type'] ?? 'round-trip',
                            ]);
                        }
                    }

                    // If source group had no GPX, fall through to global library check below
                    if (!($source_gpx && file_exists($source_gpx['file_path']))) {
                        _link_global_gpx($db, $summit_id, $current_group['id'], $sota_ref);
                    }

                    // Show message about using shared data
                    header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&shared_data=1");
                    exit;
                } else {
                    // No existing research - start fresh
                    $stmt->execute([
                        $current_group['id'],
                        null, // No source
                        false, // Original research
                        $sota_ref, $name, $region, $points, $elevation_m, $elevation_ft,
                        $latitude, $longitude, $sotlas_link,
                        null, null, null, null, null, null
                    ]);
                    
                    $summit_id = $db->lastInsertId();

                    // Try to import data from SOTLAS
                    $api_url_sotlas = "https://api.sotl.as/summits/" . urlencode($sota_ref);
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 10,
                            'ignore_errors' => true,
                            'header' => 'User-Agent: SOTA-Planner/1.0'
                        ]
                    ]);
                    
                    $sotlas_response = @file_get_contents($api_url_sotlas, false, $context);
                    $sotlas_imported = [];
                    
                    if ($sotlas_response) {
                        $sotlas_data = json_decode($sotlas_response, true);
                        
                        if ($sotlas_data && isset($sotlas_data['routes']) && !empty($sotlas_data['routes'])) {
                            $route = $sotlas_data['routes'][0];
                            
                            // Import trail data
                            $updates = [];
                            if (isset($route['distance'])) {
                                $distance_km = floatval($route['distance']);
                                $updates[] = "hike_distance_mi = " . round($distance_km * 2 * 0.621371, 2);
                                $sotlas_imported[] = 'distance';
                            }
                            if (isset($route['ascent'])) {
                                $gain_m = floatval($route['ascent']);
                                $updates[] = "hike_elevation_gain_ft = " . round($gain_m * 3.28084);
                                $sotlas_imported[] = 'elevation_gain';
                            }
                            if (isset($route['difficulty'])) {
                                $diff_map = ['easy' => 'easy', 'moderate' => 'moderate', 'hard' => 'hard', 'very_hard' => 'very hard'];
                                $diff = strtolower($route['difficulty']);
                                if (isset($diff_map[$diff])) {
                                    $updates[] = "difficulty = '" . $diff_map[$diff] . "'";
                                    $sotlas_imported[] = 'difficulty';
                                }
                            }
                            if (isset($route['start_point']['latitude']) && isset($route['start_point']['longitude'])) {
                                $updates[] = "trailhead_lat = " . floatval($route['start_point']['latitude']);
                                $updates[] = "trailhead_lng = " . floatval($route['start_point']['longitude']);
                                $sotlas_imported[] = 'trailhead';
                            }
                            
                            if (!empty($updates)) {
                                $updates[] = "data_source = 'sotlas'";
                                $updates[] = "sotlas_data_fetched = 1";
                                $sql = "UPDATE summits SET " . implode(', ', $updates) . " WHERE id = $summit_id";
                                $db->exec($sql);
                            }
                        }
                    }
                    
                    // Link global GPX library track if one exists (fills in map, elevation, trailhead)
                    _link_global_gpx($db, $summit_id, $current_group['id'], $sota_ref);

                    // If no GPX was found in the library, signal summit_detail to fetch one async
                    $gpx_chk = $db->prepare("SELECT id FROM gpx_tracks WHERE summit_id = ? LIMIT 1");
                    $gpx_chk->execute([$summit_id]);
                    $has_gpx = (bool)$gpx_chk->fetchColumn();

                    logActivity($db, 'Summit nominated', $sota_ref, $summit_data['name'] ?? '', $current_group['name'] ?? '');
                    $qs = $has_gpx ? '&nominated=1' : '&nominated=1&fetch_gpx=1';
                    header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . $qs);
                    exit;
                }
            } catch (PDOException $e) {
                $error = "Error saving summit: " . $e->getMessage();
            }
        }
    } else {
        $error = "Summit '$sota_ref' not found. Check the reference format (e.g., W6/CT-225).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nominate Summit — SOTA Planner</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg:            #F7F6F3;
      --bg-2:          #EFEDE8;
      --bg-3:          #E5E2DA;
      --ink:           #1C1B19;
      --ink-2:         #4A4844;
      --ink-3:         #8C8A86;
      --ink-4:         #B8B5B0;
      --accent:        oklch(52% 0.13 50);
      --accent-2:      oklch(44% 0.13 50);
      --accent-bg:     oklch(96% 0.04 65);
      --accent-border: oklch(84% 0.08 65);
      --green:         oklch(52% 0.13 155);
      --green-bg:      oklch(95% 0.04 155);
      --red:           oklch(52% 0.16 22);
      --red-bg:        oklch(96% 0.04 22);
      --surface:       #FFFFFF;
      --border:        #E5E2DA;
      --border-2:      #D4D0C8;
      --font-sans:     'DM Sans', system-ui, sans-serif;
      --font-mono:     'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
      --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }
    body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

    /* Topbar */
    .topbar {
      background: var(--surface); border-bottom: 1px solid var(--border);
      height: 56px; display: flex; align-items: center;
      padding: 0 2rem; gap: 1rem; position: sticky; top: 0; z-index: 100;
    }
    .topbar-logo {
      display: flex; align-items: center; gap: 0.75rem;
      text-decoration: none; color: var(--ink);
      font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0;
    }
    .topbar-logo:hover { color: var(--ink); text-decoration: none; }
    .topbar-logo .logo-mark { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
    .topbar-nav { display: flex; align-items: center; gap: 0.25rem; }
    .topbar-nav a {
      color: var(--ink-3); font-size: 0.875rem; font-weight: 500;
      padding: 0.5rem 0.75rem; border-radius: var(--r-sm);
      transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap;
    }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }
    .topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; flex-shrink: 0; }
    .user-chip {
      position: relative; display: flex; align-items: center; gap: 0.35rem;
      cursor: pointer; padding: 0.25rem 0.6rem; border-radius: var(--r-sm);
      font-size: 0.8rem; font-weight: 600; color: var(--ink-2);
      border: 1px solid var(--border); background: var(--bg); user-select: none; white-space: nowrap;
    }
    .user-chip:hover { background: var(--bg-2); }
    .user-chip-chevron { transition: transform 0.15s; flex-shrink: 0; }
    .user-chip.open .user-chip-chevron { transform: rotate(180deg); }
    .user-dropdown {
      display: none; position: absolute; top: calc(100% + 6px); right: 0;
      background: #fff; border: 1px solid var(--border); border-radius: var(--r-sm);
      box-shadow: 0 4px 16px rgba(0,0,0,0.10); min-width: 130px; overflow: hidden; z-index: 200;
    }
    .user-chip.open .user-dropdown { display: block; }
    .user-dropdown a {
      display: block; padding: 0.6rem 1rem;
      font-size: 0.82rem; font-weight: 500; color: var(--ink-2); text-decoration: none;
    }
    .user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

    /* Page layout */
    .page { padding: 2rem; max-width: 620px; margin: 0 auto; }

    /* Card */
    .card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--r-lg); padding: 1.5rem; box-shadow: var(--shadow-sm);
      margin-bottom: 1.25rem;
    }

    /* Messages */
    .msg {
      display: flex; align-items: center; gap: 1rem;
      padding: 0.75rem 1rem; border-radius: var(--r-md);
      font-size: 0.875rem; font-weight: 500; margin-bottom: 1rem;
    }
    .msg-success { background: var(--green-bg); color: var(--green); border: 1px solid oklch(85% 0.07 155); }
    .msg-error   { background: var(--red-bg);   color: var(--red);   border: 1px solid oklch(85% 0.08 22); }

    /* Form */
    .form-label {
      display: block; font-size: 0.75rem; font-weight: 600; color: var(--ink-3);
      margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em;
    }
    .form-input {
      width: 100%; padding: 0.6rem 0.75rem;
      border: 1px solid var(--border); border-radius: var(--r-md);
      font-family: var(--font-sans); font-size: 0.9375rem; color: var(--ink);
      background: var(--surface); transition: border-color 0.15s; outline: none;
    }
    .form-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-bg); }
    .form-hint { font-size: 0.775rem; color: var(--ink-3); margin-top: 0.35rem; line-height: 1.4; min-height: 1.1rem; }

    /* Buttons */
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
      padding: 0 1rem; height: 36px; border-radius: var(--r-md);
      font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500;
      cursor: pointer; border: none; transition: background 0.15s, opacity 0.15s;
      text-decoration: none; white-space: nowrap; line-height: 1;
    }
    .btn:active { transform: scale(0.98); }
    .btn-primary { background: var(--ink); color: #fff; width: 100%; height: 40px; font-size: 0.9rem; }
    .btn-primary:hover:not(:disabled) { background: var(--ink-2); }
    .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }

    /* Group badge */
    .group-badge {
      display: inline-flex; align-items: center; gap: 0.4rem;
      background: var(--accent-bg); border: 1px solid var(--accent-border);
      border-radius: 100px; padding: 0.3rem 0.75rem;
      font-size: 0.8rem; font-weight: 600; color: var(--accent-2);
      margin-bottom: 1.25rem;
    }

    /* Search results */
    .result-item {
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      padding: 0.7rem 0.875rem; border: 1px solid var(--border); border-radius: var(--r-md);
      background: var(--surface); cursor: pointer;
      transition: background 0.12s, border-color 0.12s; margin-bottom: 0.3rem;
    }
    .result-item:hover { background: var(--accent-bg); border-color: var(--accent-border); }
    .result-name { font-size: 0.875rem; font-weight: 600; color: var(--ink); }
    .result-ref  { font-family: var(--font-mono); font-size: 0.75rem; color: var(--ink-3); margin-top: 2px; }
    .result-meta { font-size: 0.775rem; color: var(--ink-3); text-align: right; white-space: nowrap; flex-shrink: 0; }
    .result-pts  { font-weight: 700; color: var(--accent); }
    .search-status { padding: 0.75rem 0; color: var(--ink-3); font-size: 0.85rem; }
    .search-count  { font-size: 0.75rem; color: var(--ink-4); margin-bottom: 0.5rem; }

    /* Selected summit */
    .selected-box {
      display: none; background: var(--green-bg); border: 1px solid oklch(85% 0.07 155);
      border-radius: var(--r-md); padding: 0.875rem 1rem; margin-bottom: 1rem;
    }
    .selected-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--green); margin-bottom: 0.3rem; }
    .selected-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .selected-name { font-size: 0.9375rem; font-weight: 600; color: var(--ink); }
    .selected-ref  { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-3); margin-left: 0.5rem; }
    .clear-btn { background: none; border: none; color: var(--ink-4); cursor: pointer; font-size: 1rem; line-height: 1; padding: 0.2rem; border-radius: var(--r-sm); }
    .clear-btn:hover { color: var(--ink-2); background: var(--bg-2); }

    /* Info panel */
    .info-panel {
      background: var(--bg-2); border: 1px solid var(--border);
      border-radius: var(--r-lg); padding: 1rem 1.25rem;
    }
    .info-panel p { font-size: 0.8rem; color: var(--ink-3); line-height: 1.6; }
    .info-panel strong { color: var(--ink-2); }

    @media (max-width: 640px) {
      .topbar { padding: 0 var(--sp-4); }
      .topbar-nav { display: none; }
      .page { padding: var(--sp-4); }
    }
  </style>
</head>
<body>

<nav class="topbar">
  <a href="index.php" class="topbar-logo">
    <span class="logo-mark"><img src="sota-planner-logo.svg" width="32" height="32" alt=""></span>
    <span>SOTAplanner</span>
  </a>
  <div class="topbar-divider"></div>
  <div class="topbar-nav">
    <a href="index.php">Dashboard</a>
    <a href="planning_groups.php">Groups</a>
  </div>
  <div class="topbar-right">
    <div class="user-chip" onclick="this.classList.toggle('open')" id="userChip">
      <span><?= htmlspecialchars($_SESSION['sota_callsign'] ?? '') ?></span>
      <svg class="user-chip-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none">
        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <div class="user-dropdown">
        <?php if (getCurrentCallsign() === 'KI6CR' || !empty($_SESSION['_god_mode_real_callsign'])): ?>
          <a href="god_mode.php">God Mode</a>
        <?php endif; ?>
        <a href="logout.php">Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page">

  <div style="margin-bottom: 1.5rem;">
    <h1 style="font-size: 1.375rem; font-weight: 600; letter-spacing: -0.02em; color: var(--ink); margin-bottom: 0.25rem;">Nominate a Summit</h1>
    <p style="font-size: 0.875rem; color: var(--ink-3);">Add a summit to your planning group to start researching it.</p>
  </div>

  <?php if ($message): ?>
    <div class="msg msg-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="msg msg-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="group-badge">
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><circle cx="4" cy="3.5" r="1.5" stroke="currentColor" stroke-width="1.2"/><circle cx="8" cy="3.5" r="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1 10c0-1.657 1.343-3 3-3s3 1.343 3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M8 7c1.105 0 2 1.343 2 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
    Nominating for: <strong><?= htmlspecialchars($current_group['name']) ?></strong>
  </div>

  <div class="card">
    <div style="font-size: 0.8rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 1rem;">Find a Summit</div>
    <form method="POST" id="nominate-form">
      <input type="hidden" id="sota_ref" name="sota_ref">
      <div style="margin-bottom: 1rem;">
        <label class="form-label" for="summit_search">Summit Name or Reference</label>
        <input
          type="text"
          class="form-input"
          id="summit_search"
          placeholder="Search summit name or designator"
          autocomplete="off"
          autofocus
        >
        <div class="form-hint" id="search-hint">Type a name to search, or paste a SOTA reference directly.</div>
      </div>

      <div id="search-results" style="display:none; margin-bottom:1rem;"></div>

      <div class="selected-box" id="selected-summit">
        <div class="selected-label">Selected Summit</div>
        <div class="selected-row">
          <div>
            <span class="selected-name" id="selected-name"></span>
            <span class="selected-ref" id="selected-ref"></span>
          </div>
          <button type="button" class="clear-btn" onclick="clearSelection()">✕</button>
        </div>
      </div>

      <button type="submit" name="nominate" id="nominate-btn" class="btn btn-primary" disabled>Nominate Summit</button>
    </form>
  </div>

  <div class="info-panel">
    <p>
      <strong>How it works:</strong> Type a name like "Mount Adams" or a reference like "W7O/NC-001".
      Select a summit from the results, then click Nominate.
      If another group already researched this summit, you'll inherit their trail data automatically.
    </p>
  </div>

</div>

<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:var(--ink-4); font-size:0.78rem;">
  SOTA Planner &nbsp;·&nbsp;
  <a href="changelog.php" style="color:var(--ink-4); text-decoration:none;">v<?= APP_VERSION ?></a>
  &nbsp;·&nbsp;
  <a href="https://sotaplanner.com" style="color:var(--ink-4); text-decoration:none;">sotaplanner.com</a>
</footer>

<script>
document.addEventListener('click', function(e) {
  var chip = document.getElementById('userChip');
  if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});

// ── Summit search ─────────────────────────────────────────────────────────────
const searchInput  = document.getElementById('summit_search');
const refInput     = document.getElementById('sota_ref');
const resultsBox   = document.getElementById('search-results');
const selectedBox  = document.getElementById('selected-summit');
const selectedName = document.getElementById('selected-name');
const selectedRef  = document.getElementById('selected-ref');
const nominateBtn  = document.getElementById('nominate-btn');
const searchHint   = document.getElementById('search-hint');

// Matches a complete SOTA reference like W6/CT-225 or W7O/NC-001 (requires 3+ digits)
const refPattern = /^[A-Za-z0-9]{1,6}\/[A-Za-z0-9]{1,6}-\d{3,}$/;

let debounceTimer = null;

searchInput.addEventListener('input', function() {
    const val = this.value.trim();
    clearTimeout(debounceTimer);

    if (!val) {
        hideResults();
        setNominateEnabled(false);
        searchHint.textContent = 'Type a summit name to search, or enter a SOTA reference directly (e.g., W6/CT-225).';
        return;
    }

    if (refPattern.test(val)) {
        // Looks like a direct reference — select it immediately, then look up the name
        hideResults();
        const ref = val.toUpperCase();
        refInput.value = ref;
        selectedName.textContent = ref;
        selectedRef.textContent = '';
        selectedBox.style.display = 'block';
        setNominateEnabled(true);
        searchHint.textContent = 'Looking up summit name...';

        fetch('nominate.php?action=search&q=' + encodeURIComponent(ref))
            .then(r => r.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    const match = data.find(s => s.ref.toUpperCase() === ref) || data[0];
                    if (match) {
                        selectedName.textContent = match.name;
                        selectedRef.textContent = ref;
                    }
                }
                searchHint.textContent = 'Looks like a SOTA reference — ready to nominate.';
            })
            .catch(() => {
                searchHint.textContent = 'Looks like a SOTA reference — ready to nominate.';
            });
        return;
    }

    if (val.length < 2) return;

    searchHint.textContent = 'Searching...';
    debounceTimer = setTimeout(() => doSearch(val), 380);
});

function doSearch(q) {
    resultsBox.style.display = 'block';
    resultsBox.innerHTML = '<div class="search-status">Searching summit cache...</div>';

    fetch('nominate.php?action=search&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                resultsBox.innerHTML = '<div class="search-status">' + data.error + '</div>';
                searchHint.textContent = '';
                return;
            }
            if (!Array.isArray(data) || data.length === 0) {
                resultsBox.innerHTML = '<div class="search-status">No summits found matching "' + escHtml(q) + '".</div>';
                searchHint.textContent = 'Try different spelling or enter a SOTA reference directly.';
                return;
            }
            renderResults(data, q);
        })
        .catch(() => {
            resultsBox.innerHTML = '<div class="search-status">Search failed. Try entering a SOTA reference directly.</div>';
        });
}

function renderResults(results, q) {
    const more = results.length === 20 ? ' (showing top 20)' : '';
    let html = '<div class="search-count">' + results.length + ' summit' + (results.length !== 1 ? 's' : '') + ' found' + more + '</div>';
    results.forEach(s => {
        const alt = s.altFt ? (s.altFt.toLocaleString() + ' ft') : '';
        html += `<div class="result-item" onclick="selectSummit('${escAttr(s.ref)}','${escAttr(s.name)}')">
            <div>
                <div class="result-name">${escHtml(s.name)}</div>
                <div class="result-ref">${escHtml(s.ref)}</div>
            </div>
            <div class="result-meta">
                <div class="result-pts">${s.points} pt${s.points !== 1 ? 's' : ''}</div>
                ${alt ? '<div>' + alt + '</div>' : ''}
            </div>
        </div>`;
    });
    resultsBox.innerHTML = html;
    searchHint.textContent = 'Click a summit to select it.';
}

function selectSummit(ref, name) {
    refInput.value = ref;
    selectedName.textContent = name;
    selectedRef.textContent  = ref !== name ? ref : '';
    selectedBox.style.display = 'block';
    hideResults();
    setNominateEnabled(true);
    searchHint.textContent = '';
}

function clearSelection() {
    refInput.value = '';
    selectedBox.style.display = 'none';
    setNominateEnabled(false);
    searchInput.value = '';
    searchInput.focus();
    searchHint.textContent = 'Type a summit name to search, or enter a SOTA reference directly (e.g., W6/CT-225).';
}

function hideResults() { resultsBox.style.display = 'none'; resultsBox.innerHTML = ''; }
function setNominateEnabled(on) { nominateBtn.disabled = !on; nominateBtn.style.opacity = on ? '1' : '0.45'; }

function escHtml(s)  { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s)  { return String(s).replace(/'/g,"\\'").replace(/"/g,'&quot;'); }

// Handle form submit validation
document.getElementById('nominate-form').addEventListener('submit', function(e) {
    if (!refInput.value.trim()) {
        e.preventDefault();
        alert('Please select a summit first.');
    }
});
</script>
</body>
</html>
