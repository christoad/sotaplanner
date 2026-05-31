<?php
/**
 * import_sotamaps_gpx.php
 *
 * Handles two actions via ?action=:
 *   list   — fetch available tracks for a summit from api-db.sota.org.uk
 *   import — download a specific track, synthesize GPX, analyze, and save
 */

require_once 'config.php';
session_start();
if (!isset($_SESSION['sota_callsign'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

$db = getDbConnection();

// Inline migration: add sotamaps_track_count if missing
try { $db->exec("ALTER TABLE global_gpx_tracks ADD COLUMN sotamaps_track_count INT NULL DEFAULT NULL"); } catch (PDOException $e) {}

$current_group = getCurrentPlanningGroup($db);

if (!$current_group) {
    echo json_encode(['error' => 'No planning group selected']);
    exit;
}

$action    = $_GET['action'] ?? $_POST['action'] ?? '';
$sota_ref  = trim($_GET['sota_ref'] ?? $_POST['sota_ref'] ?? '');
$summit_id = intval($_GET['summit_id'] ?? $_POST['summit_id'] ?? 0);

if (!$sota_ref || !$summit_id) {
    echo json_encode(['error' => 'Missing sota_ref or summit_id']);
    exit;
}

// Verify summit belongs to current group
$stmt = $db->prepare("SELECT id, sota_ref, name FROM summits WHERE id = ? AND planning_group_id = ?");
$stmt->execute([$summit_id, $current_group['id']]);
$summit = $stmt->fetch();
if (!$summit) {
    echo json_encode(['error' => 'Summit not found']);
    exit;
}

// Build API URL preserving the slash between association and summit code
function smp_api_url(string $sota_ref): string {
    $parts = explode('/', $sota_ref, 2);
    return 'https://api-db.sota.org.uk/smp/gpx/summit/'
         . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1] ?? '');
}

// ─── FETCH TRACK LIST ────────────────────────────────────────────────────────

if ($action === 'list') {
    $api_url = smp_api_url($sota_ref);

    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'ignore_errors' => true,
        'header'        => "User-Agent: SOTAPlanner/1.0\r\n"
    ]]);

    $raw = @file_get_contents($api_url, false, $ctx);
    $resp_headers = $http_response_header ?? [];

    if ($raw === false || $raw === '') {
        echo json_encode([
            'error'        => 'Could not reach SOTA Mapping Project API',
            'debug_url'    => $api_url,
            'debug_status' => $resp_headers[0] ?? 'no response',
        ]);
        exit;
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        echo json_encode([
            'error'        => 'Unexpected response from SOTA Mapping Project API',
            'debug_url'    => $api_url,
            'debug_status' => $http_response_header[0] ?? 'no headers',
            'debug_raw'    => substr($raw, 0, 500),
        ]);
        exit;
    }

    // Store track count in global library (lazy backfill for cached summits)
    $track_count = count($data);
    $db->prepare("UPDATE global_gpx_tracks SET sotamaps_track_count = ? WHERE sota_ref = ?")
       ->execute([$track_count, $sota_ref]);

    if ($track_count === 0) {
        echo json_encode(['tracks' => [], 'count' => 0, 'message' => 'No tracks found for this summit on SOTA Maps']);
        exit;
    }

    $tracks = [];
    foreach ($data as $t) {
        $pts  = $t['points'] ?? [];
        $dist = _sum_track_distance($pts);
        $tracks[] = [
            'hdr_id'       => $t['hdr_id'],
            'callsign'     => $t['callsign']    ?? '',
            'title'        => $t['track_title'] ?? '(untitled)',
            'notes'        => $t['track_notes'] ?? '',
            'posted_date'  => $t['posted_date'] ?? '',
            'point_count'  => count($pts),
            'distance_km'  => round($dist / 1000, 2),
        ];
    }

    // Sort shortest first so the user sees the most efficient route at the top
    usort($tracks, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);

    echo json_encode(['tracks' => $tracks, 'count' => $track_count]);
    exit;
}

// ─── IMPORT A SPECIFIC TRACK ─────────────────────────────────────────────────

if ($action === 'import') {
    $hdr_id = intval($_POST['hdr_id'] ?? 0);
    if (!$hdr_id) {
        echo json_encode(['error' => 'Missing track ID']);
        exit;
    }

    // Fetch track list again to get the specific track's points
    $api_url = smp_api_url($sota_ref);
    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'ignore_errors' => true,
        'header'        => "User-Agent: SOTAPlanner/1.0\r\n"
    ]]);

    $raw = @file_get_contents($api_url, false, $ctx);
    if ($raw === false) {
        echo json_encode(['error' => 'Could not reach SOTA Mapping Project API']);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['error' => 'Unexpected API response']);
        exit;
    }

    // Find the requested track
    $track = null;
    foreach ($data as $t) {
        if (intval($t['hdr_id']) === $hdr_id) {
            $track = $t;
            break;
        }
    }

    if (!$track) {
        echo json_encode(['error' => 'Track not found in API response']);
        exit;
    }

    $points = $track['points'] ?? [];
    if (count($points) < 2) {
        echo json_encode(['error' => 'Track has insufficient points']);
        exit;
    }

    // Sort points by pt_index to ensure correct order
    usort($points, fn($a, $b) => intval($a['pt_index']) - intval($b['pt_index']));

    // Build GPX XML — no timestamps (route only)
    $gpx_xml = build_gpx_from_points($points, $track['track_title'] ?? $sota_ref, $track['callsign'] ?? '');

    // Save to disk
    $upload_dir = __DIR__ . '/gpx_files';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $safe_ref = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sota_ref);
    $filename = $safe_ref . '_sotamaps_' . $hdr_id . '_' . time() . '.gpx';
    $filepath = $upload_dir . '/' . $filename;

    if (file_put_contents($filepath, $gpx_xml) === false) {
        echo json_encode(['error' => 'Could not write GPX file to disk']);
        exit;
    }

    // Analyze with existing pipeline (no SOTA ref — route only, no activation zone needed)
    $gpx_stats = analyze_gpx_track($filepath, null);

    if (!$gpx_stats) {
        @unlink($filepath);
        echo json_encode(['error' => 'GPX analysis failed — track may be malformed']);
        exit;
    }

    try {
        // Remove any existing GPX for this summit/group first
        $stmt = $db->prepare("SELECT file_path FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
        $stmt->execute([$summit_id, $current_group['id']]);
        $old = $stmt->fetch();
        if ($old && file_exists($old['file_path'])) {
            unlink($old['file_path']);
        }
        $db->prepare("DELETE FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?")
           ->execute([$summit_id, $current_group['id']]);

        // Route-only (no timestamps) — don't auto-enable hike time
        $use_for_hike = 0;

        $stmt = $db->prepare("
            INSERT INTO gpx_tracks (
                summit_id, planning_group_id, filename, file_path,
                total_time, hiking_time, activation_time, rest_break_time,
                total_distance, hiking_distance, max_elevation, min_elevation,
                elevation_gain, elevation_loss, avg_speed, hiking_speed,
                num_points, summit_lat, summit_lon, using_api,
                activation_zone_polygon, activation_zone_method,
                use_for_hike_time, use_for_elevation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $summit_id, $current_group['id'], $filename, $filepath,
            $gpx_stats['total_time'],     $gpx_stats['hiking_time'],
            $gpx_stats['activation_time'],$gpx_stats['rest_break_time'],
            $gpx_stats['total_distance'], $gpx_stats['hiking_distance'],
            $gpx_stats['max_elevation'],  $gpx_stats['min_elevation'],
            $gpx_stats['elevation_gain'], $gpx_stats['elevation_loss'],
            $gpx_stats['avg_speed'],      $gpx_stats['hiking_speed'],
            $gpx_stats['num_points'],     $gpx_stats['summit_lat'],
            $gpx_stats['summit_lon'],     $gpx_stats['using_api'] ? 1 : 0,
            $gpx_stats['activation_zone_polygon'], $gpx_stats['activation_zone_method'],
            $use_for_hike, $use_for_hike
        ]);

        // Compute stats for response (no auto-update of summit — user must enable GPS data manually)
        $elevation_gain_ft = round($gpx_stats['elevation_gain'] * 3.28084);
        $distance_mi = round($gpx_stats['total_distance'] * 0.621371, 2);

        echo json_encode([
            'success'       => true,
            'title'         => $track['track_title'] ?? $sota_ref,
            'callsign'      => $track['callsign'] ?? '',
            'point_count'   => count($points),
            'distance_mi'   => $distance_mi,
            'elevation_gain_ft' => $elevation_gain_ft,
            'elevation_gain_m'  => round($gpx_stats['elevation_gain']),
        ]);

    } catch (Exception $e) {
        @unlink($filepath);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action']);
exit;

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function _sum_track_distance(array $points): float {
    $total = 0.0;
    $prev  = null;
    foreach ($points as $pt) {
        if ($prev !== null) {
            $total += haversine_distance(
                floatval($prev['latitude']),  floatval($prev['longitude']),
                floatval($pt['latitude']),    floatval($pt['longitude'])
            );
        }
        $prev = $pt;
    }
    return $total; // metres
}

/**
 * Build a minimal GPX 1.1 file from the SMP points array.
 * No timestamps — treated as a route/track for map display only.
 */
function build_gpx_from_points(array $points, string $title, string $callsign): string {
    $safe_title    = htmlspecialchars($title,    ENT_XML1);
    $safe_callsign = htmlspecialchars($callsign, ENT_XML1);

    $trkpts = '';
    foreach ($points as $pt) {
        $lat = floatval($pt['latitude']);
        $lon = floatval($pt['longitude']);
        $ele = floatval($pt['altitude']);

        // Some SMP entries store altitude in km — correct to meters
        if ($ele > 0 && $ele < 9) {
            $ele *= 1000;
        }

        $trkpts .= sprintf(
            "    <trkpt lat=\"%.7f\" lon=\"%.7f\"><ele>%.1f</ele></trkpt>\n",
            $lat, $lon, $ele
        );
    }

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="SOTAPlanner/sotamaps-import"
     xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <name>{$safe_title}</name>
    <desc>Imported from SOTA Mapping Project. Submitted by {$safe_callsign}</desc>
  </metadata>
  <trk>
    <name>{$safe_title}</name>
    <trkseg>
{$trkpts}  </trkseg>
  </trk>
</gpx>
XML;
}
