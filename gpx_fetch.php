<?php
/**
 * gpx_fetch.php — on-demand SOTAmaps GPX fetch for a single summit.
 * POST  summit_id  (int)
 * Returns JSON: { status: 'found'|'none'|'skip'|'error', track_type, trailhead_lat, trailhead_lon, ... }
 */

set_time_limit(90);
require_once 'config.php';
session_start();
requireLogin();

header('Content-Type: application/json');

// Catch any uncaught exceptions and return them as JSON so the JS can log them
set_exception_handler(function(Throwable $e) {
    error_log('gpx_fetch.php exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    exit;
});

$summit_id = (int)($_POST['summit_id'] ?? 0);
if (!$summit_id) {
    echo json_encode(['status' => 'error', 'msg' => 'Missing summit_id']);
    exit;
}

$db = getDbConnection();

// Inline migration: add sotamaps_track_count if missing
try { $db->exec("ALTER TABLE global_gpx_tracks ADD COLUMN sotamaps_track_count INT NULL DEFAULT NULL"); } catch (PDOException $e) {}

// Load summit
$stmt = $db->prepare("SELECT id, sota_ref, planning_group_id, hike_distance_mi, hike_elevation_gain_ft, trailhead_lat, trailhead_lng FROM summits WHERE id = ?");
$stmt->execute([$summit_id]);
$summit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$summit) {
    echo json_encode(['status' => 'error', 'msg' => 'Summit not found']);
    exit;
}

$sota_ref  = $summit['sota_ref'];
$group_id  = (int)$summit['planning_group_id'];

// Skip if GPX already linked to this summit
$chk = $db->prepare("SELECT id FROM gpx_tracks WHERE summit_id = ? LIMIT 1");
$chk->execute([$summit_id]);
if ($chk->fetch()) {
    echo json_encode(['status' => 'skip', 'msg' => 'GPX already linked']);
    exit;
}

// Check global library — may have been populated since nomination
$global_stmt = $db->prepare("SELECT * FROM global_gpx_tracks WHERE sota_ref = ?");
$global_stmt->execute([$sota_ref]);
$global = $global_stmt->fetch(PDO::FETCH_ASSOC);

if (!$global) {
    // ── Fetch from SOTAmaps ───────────────────────────────────────────────────
    $parts   = explode('/', $sota_ref, 2);
    $api_url = 'https://api-db.sota.org.uk/smp/gpx/summit/'
             . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1] ?? '');

    $ctx = stream_context_create(['http' => [
        'timeout'       => 20,
        'ignore_errors' => true,
        'header'        => "User-Agent: SOTAPlanner/1.0\r\n",
    ]]);
    $raw = @file_get_contents($api_url, false, $ctx);

    if (!$raw) {
        echo json_encode(['status' => 'none', 'msg' => 'No response from SOTAmaps']);
        exit;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || count($data) === 0) {
        echo json_encode(['status' => 'none', 'msg' => 'No tracks on SOTAmaps for this summit']);
        exit;
    }

    // Pick the shortest-distance track (best for planning; avoids selecting a long wandering route)
    $best = null;
    $best_dist = PHP_FLOAT_MAX;
    foreach ($data as $t) {
        $pts = $t['points'] ?? [];
        if (count($pts) < 2) continue;
        $dist = _gpxfetch_track_distance($pts);
        if ($dist < $best_dist) { $best = $t; $best_dist = $dist; }
    }
    if (!$best) {
        echo json_encode(['status' => 'none', 'msg' => 'No usable tracks on SOTAmaps']);
        exit;
    }

    $points = $best['points'] ?? [];
    if (count($points) < 2) {
        echo json_encode(['status' => 'none', 'msg' => 'Track has too few points']);
        exit;
    }
    usort($points, fn($a, $b) => intval($a['pt_index']) - intval($b['pt_index']));

    // Build GPX XML
    $gpx_xml = _build_gpx($points, $best['track_title'] ?? $sota_ref, $best['callsign'] ?? '');

    // Save file
    $upload_dir = __DIR__ . '/gpx_files/global';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $safe_ref  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sota_ref);
    $filename  = $safe_ref . '_sotamaps_' . intval($best['hdr_id'] ?? time()) . '.gpx';
    $filepath  = $upload_dir . '/' . $filename;

    if (file_put_contents($filepath, $gpx_xml) === false) {
        echo json_encode(['status' => 'error', 'msg' => 'Could not write GPX file to disk']);
        exit;
    }

    // Analyse track: detects route type + low-elevation trailhead endpoint
    $gpx_stats = analyze_gpx_track($filepath, null);
    if (!$gpx_stats) {
        @unlink($filepath);
        echo json_encode(['status' => 'error', 'msg' => 'GPX analysis failed']);
        exit;
    }

    // OSM trailhead lookup — 400 m around the GPX-derived trailhead point
    $trailhead_lat = $gpx_stats['trailhead_lat'];
    $trailhead_lon = $gpx_stats['trailhead_lon'];
    $osm_source    = false;
    if (!empty($trailhead_lat) && !empty($trailhead_lon)) {
        $osm = _osm_trailhead((float)$trailhead_lat, (float)$trailhead_lon, 400);
        if ($osm) {
            $trailhead_lat = $osm['lat'];
            $trailhead_lon = $osm['lon'];
            $osm_source    = true;
        }
    }

    // Store in global library (with track count for the swap UI)
    $ins = $db->prepare("
        INSERT IGNORE INTO global_gpx_tracks (
            sota_ref, filename, file_path, source, source_callsign, source_track_title,
            total_distance, max_elevation, min_elevation, elevation_gain, elevation_loss,
            num_points, summit_lat, summit_lon, trailhead_lat, trailhead_lon, sotamaps_track_count
        ) VALUES (?, ?, ?, 'sotamaps', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $sota_ref, $filename, $filepath,
        $best['callsign'] ?? '', $best['track_title'] ?? $sota_ref,
        $gpx_stats['total_distance'], $gpx_stats['max_elevation'], $gpx_stats['min_elevation'],
        $gpx_stats['elevation_gain'], $gpx_stats['elevation_loss'],
        $gpx_stats['num_points'], $gpx_stats['summit_lat'], $gpx_stats['summit_lon'],
        $trailhead_lat, $trailhead_lon, count($data),
    ]);

    $global_stmt->execute([$sota_ref]);
    $global = $global_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$global) {
        echo json_encode(['status' => 'error', 'msg' => 'Failed to store global track']);
        exit;
    }

    $track_type = $gpx_stats['track_type'] ?? 'round-trip';
} else {
    // Already in global library — derive track type from the file if present
    $track_type = 'round-trip';
    if (!empty($global['file_path']) && file_exists($global['file_path'])) {
        $gpx_stats  = analyze_gpx_track($global['file_path'], null);
        $track_type = $gpx_stats['track_type'] ?? 'round-trip';
    }
    $trailhead_lat = $global['trailhead_lat'];
    $trailhead_lon = $global['trailhead_lon'];
    $osm_source    = false;
}

// ── Link global track to this summit ─────────────────────────────────────────
if (!empty($global['file_path']) && file_exists($global['file_path'])) {
    $db->prepare("
        INSERT IGNORE INTO gpx_tracks (
            summit_id, planning_group_id, filename, file_path,
            total_time, hiking_time, activation_time, rest_break_time,
            total_distance, hiking_distance, max_elevation, min_elevation,
            elevation_gain, elevation_loss, avg_speed, hiking_speed,
            num_points, summit_lat, summit_lon, using_api,
            activation_zone_polygon, activation_zone_method,
            use_for_hike_time, use_for_elevation, from_global_library, track_type
        ) VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, NULL, 'none', 0, 1, 1, ?)
    ")->execute([
        $summit_id, $group_id, $global['filename'], $global['file_path'],
        $global['total_distance'], $global['total_distance'],
        $global['max_elevation'], $global['min_elevation'],
        $global['elevation_gain'], $global['elevation_loss'],
        $global['num_points'], $global['summit_lat'], $global['summit_lon'],
        $track_type,
    ]);
}

// ── Update summit hike stats + trailhead if not already set ──────────────────
$updates = [];
$params  = [];

if (!empty($global['elevation_gain']) && $global['elevation_gain'] > 0) {
    $updates[] = "hike_elevation_gain_ft = COALESCE(hike_elevation_gain_ft, ?)";
    $params[]  = round($global['elevation_gain'] * 3.28084);
}
if (!empty($global['total_distance']) && $global['total_distance'] > 0) {
    $updates[] = "hike_distance_mi = COALESCE(hike_distance_mi, ?)";
    $params[]  = round($global['total_distance'] * 2 * 0.621371, 2);
}
if (!empty($trailhead_lat) && !empty($trailhead_lon)) {
    $updates[] = "trailhead_lat = COALESCE(trailhead_lat, ?)";
    $updates[] = "trailhead_lng = COALESCE(trailhead_lng, ?)";
    $params[]  = $trailhead_lat;
    $params[]  = $trailhead_lon;
}

if ($updates) {
    $params[] = $summit_id;
    $db->prepare("UPDATE summits SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
}

echo json_encode([
    'status'        => 'found',
    'track_type'    => $track_type,
    'trailhead_lat' => $trailhead_lat,
    'trailhead_lon' => $trailhead_lon,
    'osm_source'    => $osm_source,
]);
exit;


// ── Helpers ───────────────────────────────────────────────────────────────────

function _gpxfetch_track_distance(array $points): float {
    $total = 0.0;
    $prev  = null;
    foreach ($points as $pt) {
        if ($prev !== null) {
            $total += haversine_distance(
                floatval($prev['latitude']), floatval($prev['longitude']),
                floatval($pt['latitude']),  floatval($pt['longitude'])
            );
        }
        $prev = $pt;
    }
    return $total;
}

function _build_gpx(array $points, string $title, string $callsign): string {
    $safe_title    = htmlspecialchars($title,    ENT_XML1);
    $safe_callsign = htmlspecialchars($callsign, ENT_XML1);
    $trkpts = '';
    foreach ($points as $pt) {
        $lat = floatval($pt['latitude']);
        $lon = floatval($pt['longitude']);
        $ele = floatval($pt['altitude']);
        if ($ele > 0 && $ele < 9) $ele *= 1000; // km → m guard
        $trkpts .= sprintf(
            "    <trkpt lat=\"%.7f\" lon=\"%.7f\"><ele>%.1f</ele></trkpt>\n",
            $lat, $lon, $ele
        );
    }
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="SOTAPlanner"
     xmlns="http://www.topografix.com/GPX/1/1">
  <metadata><name>{$safe_title}</name>
    <desc>Imported from SOTA Mapping Project. Submitted by {$safe_callsign}</desc>
  </metadata>
  <trk><name>{$safe_title}</name><trkseg>
{$trkpts}  </trkseg></trk>
</gpx>
XML;
}

function _osm_trailhead(float $lat, float $lon, int $radius_m): ?array {
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

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SOTAPlanner-TrailheadLookup/1.0\r\n",
        'content'       => 'data=' . urlencode($q),
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);

    $raw  = @file_get_contents('https://overpass-api.de/api/interpreter', false, $ctx);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (!isset($data['elements'])) return null;

    $candidates = [];
    foreach ($data['elements'] as $el) {
        $elat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $elon = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if ($elat === null || $elon === null) continue;

        $tags    = $el['tags'] ?? [];
        $highway = $tags['highway'] ?? '';
        $tourism = $tags['tourism'] ?? '';
        $amenity = $tags['amenity'] ?? '';

        $score = ($highway === 'trailhead' || $tourism === 'trailhead') ? 0 : 1;
        $dist  = haversine_distance($lat, $lon, (float)$elat, (float)$elon);
        $candidates[] = ['lat' => (float)$elat, 'lon' => (float)$elon, 'dist' => $dist, 'score' => $score];
    }

    if (empty($candidates)) return null;
    usort($candidates, fn($a, $b) => $a['score'] !== $b['score'] ? $a['score'] - $b['score'] : $a['dist'] <=> $b['dist']);
    return $candidates[0];
}
