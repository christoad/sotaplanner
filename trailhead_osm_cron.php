<?php
/**
 * trailhead_osm_cron.php — CLI cron worker for OSM trailhead lookup.
 *
 * Finds global_gpx_tracks rows that have a GPX file but no trailhead coordinates,
 * then queries OpenStreetMap (Overpass API) within 400m of the GPX low-elevation
 * endpoint. Falls back to the GPX endpoint itself if no qualifying OSM result.
 * Results are written to both global_gpx_tracks and the summits table.
 *
 * Usage:
 *   php trailhead_osm_cron.php [--limit=100] [--delay=2000]
 *
 * Suggested DreamHost cron (daily at 1:05 AM, after the GPX cron):
 *   php /home/chrisr069/sotaplannerdotcom/trailhead_osm_cron.php >> /home/chrisr069/logs/gpx_cron.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script may only be run from the command line.');
}

$opts     = getopt('', ['limit:', 'delay:']);
$limit    = max(1, (int)($opts['limit']  ?? 100));
$delay_ms = max(1000, (int)($opts['delay'] ?? 2000));

set_time_limit(0);
require_once __DIR__ . '/config.php';

$db = getDbConnection();

_th_clog("Trailhead cron starting — limit={$limit} delay={$delay_ms}ms");

// Find global GPX tracks that are missing a trailhead
$rows = $db->prepare("
    SELECT id AS track_id, sota_ref, file_path
    FROM global_gpx_tracks
    WHERE file_path IS NOT NULL AND file_path != ''
      AND (trailhead_lat IS NULL OR trailhead_lat = 0)
    ORDER BY imported_at DESC
    LIMIT ?
");
$rows->execute([$limit]);
$queue = $rows->fetchAll(PDO::FETCH_ASSOC);
$total = count($queue);

_th_clog("{$total} track(s) need trailhead lookup");

if ($total === 0) {
    _th_clog("Nothing to do. Exiting.");
    _th_clog(str_repeat('-', 60));
    exit(0);
}

$counts = ['found' => 0, 'err' => 0];

foreach ($queue as $i => $row) {
    $n         = $i + 1;
    $track_id  = $row['track_id'];
    $sota_ref  = $row['sota_ref'];
    $file_path = $row['file_path'];

    if (!file_exists($file_path)) {
        $counts['err']++;
        _th_clog("[{$n}/{$total}] ✗ {$sota_ref} — GPX file not on disk");
        continue;
    }

    $gpx_stats = analyze_gpx_track($file_path, null);
    if (!$gpx_stats || empty($gpx_stats['trailhead_lat']) || empty($gpx_stats['trailhead_lon'])) {
        $counts['err']++;
        _th_clog("[{$n}/{$total}] · {$sota_ref} — GPX yielded no low-elevation endpoint");
        continue;
    }

    $osm = _th_overpass((float)$gpx_stats['trailhead_lat'], (float)$gpx_stats['trailhead_lon'], 400);

    $use_osm = false;
    if ($osm) {
        $is_dedicated = ($osm['type'] === 'trailhead');
        $use_osm = $is_dedicated || $osm['dist'] <= 100;
    }

    if ($use_osm) {
        $th_lat = $osm['lat'];
        $th_lon = $osm['lon'];
        $src    = 'osm:' . $osm['type'] . ' ' . round($osm['dist']) . 'm away';
    } else {
        $th_lat = (float)$gpx_stats['trailhead_lat'];
        $th_lon = (float)$gpx_stats['trailhead_lon'];
        $src    = 'gpx endpoint';
    }

    $db->prepare("UPDATE global_gpx_tracks SET trailhead_lat = ?, trailhead_lon = ? WHERE id = ?")
       ->execute([$th_lat, $th_lon, $track_id]);

    $upd = $db->prepare("
        UPDATE summits SET trailhead_lat = ?, trailhead_lng = ?
        WHERE sota_ref = ? AND (trailhead_lat IS NULL OR trailhead_lat = 0)
    ");
    $upd->execute([$th_lat, $th_lon, $sota_ref]);
    $backfilled = $upd->rowCount();

    $counts['found']++;
    $extra = $backfilled > 0 ? " +{$backfilled} summit(s)" : '';
    _th_clog("[{$n}/{$total}] ✓ {$sota_ref} [{$src}]{$extra}");

    if ($i < $total - 1) {
        usleep($delay_ms * 1000);
    }
}

_th_clog("Done — found:{$counts['found']}  err:{$counts['err']}");
_th_clog(str_repeat('-', 60));
exit(0);

// ── Helpers ───────────────────────────────────────────────────────────────────

function _th_clog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    flush();
}

function _th_overpass(float $lat, float $lon, int $radius_m): ?array {
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
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SOTAPlanner-TrailheadCron/1.0\r\n",
        'content'       => 'data=' . urlencode($q),
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://overpass-api.de/api/interpreter', false, $ctx);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    if (!isset($data['elements']) || !is_array($data['elements'])) return null;

    $candidates = [];
    foreach ($data['elements'] as $el) {
        $elat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $elon = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if ($elat === null || $elon === null) continue;

        $tags    = $el['tags'] ?? [];
        $highway = $tags['highway'] ?? '';
        $tourism = $tags['tourism'] ?? '';
        $amenity = $tags['amenity'] ?? '';
        $score   = ($highway === 'trailhead' || $tourism === 'trailhead') ? 0 : 1;
        $dist    = haversine_distance($lat, $lon, (float)$elat, (float)$elon);

        $candidates[] = ['lat' => (float)$elat, 'lon' => (float)$elon, 'dist' => $dist, 'score' => $score, 'type' => $highway ?: $tourism ?: $amenity];
    }

    if (empty($candidates)) return null;
    usort($candidates, fn($a, $b) => $a['score'] !== $b['score'] ? $a['score'] - $b['score'] : $a['dist'] <=> $b['dist']);
    return $candidates[0];
}
