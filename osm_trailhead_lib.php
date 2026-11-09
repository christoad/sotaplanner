<?php
/**
 * OpenStreetMap trailhead lookup for one point (Overpass API), used by the SOTAmaps import on the summit page.
 * Looks within $radius_m for a tagged trailhead or an open parking area and returns the best one:
 * dedicated trailhead tags first, then the nearest parking. Returns ['lat','lon','dist','score'] or null.
 * (gpx_fetch.php and trailhead_osm_cron.php carry their own copies of this query; same rules.)
 */
require_once __DIR__ . '/config.php';

function osm_find_trailhead(float $lat, float $lon, int $radius_m = 400): ?array {
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
    // Anything that still fails returns null and the caller keeps the track's own starting point.
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
    if ($data === null) return null;

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
