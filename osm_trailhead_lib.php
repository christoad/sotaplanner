<?php
/**
 * OpenStreetMap trailhead lookup for one point (Overpass API), used by the SOTAmaps import on the summit page.
 * Looks within $radius_m for a tagged trailhead or an open parking area and returns the best one:
 * dedicated trailhead tags first, then the nearest parking. Returns ['lat','lon','dist','score'], null when nothing is
 * mapped nearby, or false when the lookup itself failed (Overpass busy or down), so callers can retry later.
 * (gpx_fetch.php and trailhead_osm_cron.php carry their own copies of this query; same rules.)
 */
require_once __DIR__ . '/config.php';

function osm_find_trailhead(float $lat, float $lon, int $radius_m = 400) {
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

    // The main public Overpass server is often at capacity (HTTP 502 or an empty reply). Try it with a short
    // timeout, then a community mirror, so a person waiting on an import is held for 35 seconds at most.
    // Anything that still fails returns false and the caller keeps the track's own starting point and retries later.
    $attempts = [
        ['https://overpass-api.de/api/interpreter', 10],
        ['https://maps.mail.ru/osm/tools/overpass/api/interpreter', 25],
    ];
    $data = null;
    foreach ($attempts as $i => [$url, $timeout]) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'data=' . urlencode($q),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT      => 'SOTAPlanner-TrailheadLookup/1.0',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = ($http === 200 && $raw) ? json_decode($raw, true) : null;
        if (is_array($j) && isset($j['elements'])) { $data = $j; break; }
    }
    if ($data === null) return false;

    $candidates = [];
    foreach ($data['elements'] as $el) {
        $elat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $elon = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if ($elat === null || $elon === null) continue;
        $tags  = $el['tags'] ?? [];
        $score = (($tags['highway'] ?? '') === 'trailhead' || ($tags['tourism'] ?? '') === 'trailhead') ? 0 : 1;
        $candidates[] = ['lat' => (float)$elat, 'lon' => (float)$elon,
                         'dist' => haversine_distance($lat, $lon, (float)$elat, (float)$elon), 'score' => $score];
    }
    if (!$candidates) return null;
    usort($candidates, fn($a, $b) => $a['score'] !== $b['score'] ? $a['score'] - $b['score'] : $a['dist'] <=> $b['dist']);
    return $candidates[0];
}

// ── Background start-point snap (queue, retry and log) ────────────────────────
// After a SOTA Maps import the summit gets the track's own low end as its starting point at once. The nicer
// OpenStreetMap trailhead or parking spot is looked up afterwards, off the import request, retried on later page
// views and by the nightly trailhead cron, and every attempt is logged where God Mode's Cron Activity Log shows it.

const TRAILHEAD_SNAP_MAX_ATTEMPTS = 6;

function trailhead_snap_log($msg) {
    $dir = file_exists(dirname(__DIR__) . '/sota_logs') ? dirname(__DIR__) . '/sota_logs' : dirname(dirname(__DIR__)) . '/sota_logs';
    @file_put_contents($dir . '/gpx_cron.log', '[' . date('Y-m-d H:i:s') . '] Start point lookup: ' . $msg . "\n", FILE_APPEND);
}

/** Remember that this summit's starting point still needs its OpenStreetMap snap. $lat/$lon is the point we set. */
function trailhead_snap_queue($db, $summit_id, $sota_ref, $lat, $lon) {
    try {
        $db->prepare("INSERT INTO trailhead_snap_queue (summit_id, sota_ref, origin_lat, origin_lon, status, attempts, last_attempt, note, created_at)
                      VALUES (?,?,?,?, 'pending', 0, NULL, 'queued after import', NOW())
                      ON DUPLICATE KEY UPDATE sota_ref = VALUES(sota_ref), origin_lat = VALUES(origin_lat), origin_lon = VALUES(origin_lon),
                          status = 'pending', attempts = 0, last_attempt = NULL, note = 'queued after import', created_at = NOW()")
           ->execute([$summit_id, $sota_ref, round($lat, 7), round($lon, 7)]);
        trailhead_snap_log("$sota_ref queued (summit $summit_id)");
    } catch (Throwable $e) { error_log('trailhead_snap_queue: ' . $e->getMessage()); }
}

/** True when this summit has a pending snap that has not been tried in the last 60 seconds. */
function trailhead_snap_due($db, $summit_id) {
    try {
        $st = $db->prepare("SELECT 1 FROM trailhead_snap_queue WHERE summit_id = ? AND status = 'pending' AND attempts < ?
                            AND (last_attempt IS NULL OR last_attempt < (NOW() - INTERVAL 60 SECOND))");
        $st->execute([$summit_id, TRAILHEAD_SNAP_MAX_ATTEMPTS]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** Try one pending snap. Returns 'moved', 'kept', 'retry', 'gave_up', 'skipped' or 'none'. */
function trailhead_snap_run($db, $summit_id) {
    try {
        $st = $db->prepare("SELECT * FROM trailhead_snap_queue WHERE summit_id = ? AND status = 'pending'");
        $st->execute([$summit_id]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) return 'none';
        $ref = $q['sota_ref'];
        // Mark the attempt first so a second tab or the cron does not run the same lookup at the same moment
        $claim = $db->prepare("UPDATE trailhead_snap_queue SET attempts = attempts + 1, last_attempt = NOW()
                               WHERE summit_id = ? AND status = 'pending' AND (last_attempt IS NULL OR last_attempt < (NOW() - INTERVAL 60 SECOND))");
        $claim->execute([$summit_id]);
        if ($claim->rowCount() === 0) return 'none';
        $attempt = (int)$q['attempts'] + 1;

        $cur = $db->prepare("SELECT trailhead_lat, trailhead_lng FROM summits WHERE id = ?");
        $cur->execute([$summit_id]);
        $s = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$s || abs((float)$s['trailhead_lat'] - (float)$q['origin_lat']) > 0.00002 || abs((float)$s['trailhead_lng'] - (float)$q['origin_lon']) > 0.00002) {
            $db->prepare("UPDATE trailhead_snap_queue SET status='skipped', note='starting point was changed by hand' WHERE summit_id = ?")->execute([$summit_id]);
            trailhead_snap_log("$ref skipped: starting point was changed by hand");
            return 'skipped';
        }
        $osm = osm_find_trailhead((float)$q['origin_lat'], (float)$q['origin_lon'], 400);
        if ($osm === false) {
            if ($attempt >= TRAILHEAD_SNAP_MAX_ATTEMPTS) {
                $db->prepare("UPDATE trailhead_snap_queue SET status='gave_up', note='OpenStreetMap unavailable, kept the track start' WHERE summit_id = ?")->execute([$summit_id]);
                trailhead_snap_log("$ref gave up after $attempt attempts, kept the track's own start");
                return 'gave_up';
            }
            $db->prepare("UPDATE trailhead_snap_queue SET note='OpenStreetMap unavailable, will retry' WHERE summit_id = ?")->execute([$summit_id]);
            trailhead_snap_log("$ref attempt $attempt: OpenStreetMap unavailable, will retry");
            return 'retry';
        }
        if ($osm === null) {
            $db->prepare("UPDATE trailhead_snap_queue SET status='done', note='no trailhead or parking mapped within 400 m, kept the track start' WHERE summit_id = ?")->execute([$summit_id]);
            trailhead_snap_log("$ref done: nothing mapped within 400 m, kept the track's own start");
            return 'kept';
        }
        $db->prepare("UPDATE summits SET trailhead_lat = ?, trailhead_lng = ? WHERE id = ?")
           ->execute([round($osm['lat'], 8), round($osm['lon'], 8), $summit_id]);
        $kind = $osm['score'] === 0 ? 'trailhead' : 'parking';
        $db->prepare("UPDATE trailhead_snap_queue SET status='done', note=? WHERE summit_id = ?")
           ->execute(["moved to OpenStreetMap $kind " . round($osm['dist']) . ' m away', $summit_id]);
        trailhead_snap_log("$ref done: moved to OpenStreetMap $kind " . round($osm['dist']) . ' m away');
        return 'moved';
    } catch (Throwable $e) {
        error_log('trailhead_snap_run: ' . $e->getMessage());
        return 'none';
    }
}

/** Nightly cron: work through pending snaps, politely paced, stopping early if OpenStreetMap is down. */
function trailhead_snap_retry_pending($db, $limit = 20, $delay_ms = 2000) {
    try {
        $st = $db->prepare("SELECT summit_id FROM trailhead_snap_queue WHERE status = 'pending' AND attempts < ? ORDER BY created_at LIMIT " . (int)$limit);
        $st->execute([TRAILHEAD_SNAP_MAX_ATTEMPTS]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return 0; }
    $fails = 0;
    foreach ($ids as $i => $id) {
        $r = trailhead_snap_run($db, (int)$id);
        if ($r === 'retry' || $r === 'gave_up') { if (++$fails >= 3) break; } else { $fails = 0; }
        if ($i < count($ids) - 1) usleep($delay_ms * 1000);
    }
    return count($ids);
}
