<?php
/**
 * Drive legs for Multi-Activate (screen, PDF and route import): time, distance AND the road line from ONE Google call,
 * so the number and the drawn route can never disagree. Google's Routes API knows about road closures; the free OSRM
 * demo server this replaced did not (it drew Highway 2 through the Angeles Crest closure).
 *
 * Every leg is cached in drive_leg_cache by rounded start and end point and re-fetched after DRIVE_LEG_TTL_DAYS, so a
 * closure that starts or ends later is picked up without anyone doing anything. Uses the server key
 * (GOOGLE_MAPS_API_KEY), which needs the Routes API enabled in Google Cloud. If Routes is unavailable it falls back to
 * the old Distance Matrix time with no line (the caller then draws a dashed straight line), and never to OSRM.
 */
require_once __DIR__ . '/config.php';

const DRIVE_LEG_TTL_DAYS = 30;

/** "lat,lng" string -> [lat, lng] floats, or null. */
function drive_leg_parse($s) {
    $p = explode(',', (string)$s);
    if (count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])) return null;
    return [(float)$p[0], (float)$p[1]];
}

/** Cache key for a point: rounded to 4 decimals (about 11 m), so a tiny coordinate edit still hits the cache. */
function drive_leg_key(array $pt) { return number_format($pt[0], 4, '.', '') . ',' . number_format($pt[1], 4, '.', ''); }

/** Decode a Google encoded polyline into [[lat,lng],...]. */
function drive_leg_decode($enc) {
    $pts = []; $i = 0; $n = strlen($enc); $lat = 0; $lng = 0;
    while ($i < $n) {
        foreach ([0, 1] as $which) {
            $shift = 0; $res = 0;
            do { $b = ord($enc[$i++]) - 63; $res |= ($b & 0x1f) << $shift; $shift += 5; } while ($b >= 0x20 && $i < $n);
            $d = ($res & 1) ? ~($res >> 1) : ($res >> 1);
            if ($which === 0) $lat += $d; else $lng += $d;
        }
        $pts[] = [$lat / 1e5, $lng / 1e5];
    }
    return $pts;
}

/** Evenly thin a line to about $max points (always keeps the last point). */
function drive_leg_thin(array $pts, $max = 700) {
    $n = count($pts);
    if ($n <= $max) return $pts;
    $out = [];
    for ($i = 0; $i < $max; $i++) $out[] = $pts[(int)floor($i * $n / $max)];
    $out[] = $pts[$n - 1];
    return $out;
}

/** Next Saturday 8 am Pacific as an RFC 3339 UTC time: a typical activation start, so the route uses typical weekend
 *  traffic rather than whatever the roads look like at the moment someone happens to open the page. */
function drive_leg_departure() {
    try {
        $d = new DateTime('next saturday 08:00', new DateTimeZone('America/Los_Angeles'));
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) { return null; }
}

/** One Routes API call. Returns ['min','distance_m','polyline'] or null (reason in $err). */
function drive_leg_fetch_routes(array $from, array $to, &$err = null) {
    static $disabled = null;   // once the API is refused this request, do not try again for every other leg
    if ($disabled !== null) { $err = $disabled; return null; }
    if (!defined('GOOGLE_MAPS_API_KEY') || GOOGLE_MAPS_API_KEY === 'YOUR_API_KEY_HERE') { $err = 'no API key'; return null; }
    $body = [
        'origin'      => ['location' => ['latLng' => ['latitude' => $from[0], 'longitude' => $from[1]]]],
        'destination' => ['location' => ['latLng' => ['latitude' => $to[0], 'longitude' => $to[1]]]],
        'travelMode'  => 'DRIVE',
        'routingPreference' => 'TRAFFIC_AWARE',
        'units' => 'IMPERIAL',
    ];
    $dep = drive_leg_departure();
    if ($dep) $body['departureTime'] = $dep;
    // Google now and then answers 200 with an empty body, or 429/5xx, for a pair it routed fine a moment before:
    // one short retry, then give up for this request (the caller falls back and the next page load tries again).
    $http = 0; $j = null; $resp = null;
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . GOOGLE_MAPS_API_KEY,
                'X-Goog-FieldMask: routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline',
            ],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 403 || $http === 401) {
            $err = "Routes API refused the key (HTTP $http): is it enabled for the server key?";
            $disabled = $err;
            error_log("drive_leg: $err");
            return null;
        }
        $j = $resp ? json_decode($resp, true) : null;
        $r = $j['routes'][0] ?? null;
        if ($http === 200 && $r && !empty($r['polyline']['encodedPolyline']) && isset($r['duration'])) break;
        if ($try === 0) usleep(600000);
    }
    $r = $j['routes'][0] ?? null;
    if ($http !== 200 || !$r || empty($r['polyline']['encodedPolyline']) || !isset($r['duration'])) {
        $msg = $j['error']['message'] ?? '';
        $err = 'Routes API HTTP ' . $http . ($msg !== '' ? ': ' . $msg : '');
        error_log("drive_leg: $err from=" . drive_leg_key($from) . " to=" . drive_leg_key($to) . ' body=' . substr((string)$resp, 0, 200));
        return null;
    }
    return [
        'min'        => (int)round(((float)rtrim($r['duration'], 's')) / 60),
        'distance_m' => (int)($r['distanceMeters'] ?? 0),
        'polyline'   => $r['polyline']['encodedPolyline'],
    ];
}

/**
 * Time, distance and road line for one drive. $from and $to are "lat,lng" strings.
 * Returns ['min' => int|null, 'mi' => float|null, 'line' => [[lat,lng],...]|null, 'source' => 'routes'|'routes_rev'|'matrix'|'none'].
 */
function drive_leg_get($from, $to) {
    $f = drive_leg_parse($from); $t = drive_leg_parse($to);
    if (!$f || !$t) return ['min' => null, 'mi' => null, 'line' => null, 'source' => 'none'];
    $db = getDbConnection();
    $fk = drive_leg_key($f); $tk = drive_leg_key($t);

    $row = null;
    try {
        $st = $db->prepare("SELECT duration_min, distance_m, polyline, source, fetched_at FROM drive_leg_cache WHERE from_key = ? AND to_key = ?");
        $st->execute([$fk, $tk]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { /* table not migrated yet: behave as if there is no cache */ }

    $fresh = $row && strtotime($row['fetched_at']) > time() - DRIVE_LEG_TTL_DAYS * 86400;
    if (!$fresh) {
        $err = null;
        $api = drive_leg_fetch_routes($f, $t, $err);
        $src = 'routes';
        if (!$api && $err !== null && stripos($err, 'refused the key') === false) {
            // Google sometimes has no route that STARTS at a point on a closed or restricted road but can end there
            // (typically a trailhead). Use the same road the other way round, line reversed, and say so in the cache.
            $api = drive_leg_fetch_routes($t, $f, $err);
            if ($api) $src = 'routes_rev';
        }
        if ($api) {
            try {
                $db->prepare("INSERT INTO drive_leg_cache (from_key, to_key, duration_min, distance_m, polyline, source, fetched_at)
                              VALUES (?,?,?,?,?,?, NOW())
                              ON DUPLICATE KEY UPDATE duration_min = VALUES(duration_min), distance_m = VALUES(distance_m),
                                  polyline = VALUES(polyline), source = VALUES(source), fetched_at = NOW()")
                   ->execute([$fk, $tk, $api['min'], $api['distance_m'], $api['polyline'], $src]);
            } catch (Throwable $e) { error_log('drive_leg cache write failed: ' . $e->getMessage()); }
            $row = ['duration_min' => $api['min'], 'distance_m' => $api['distance_m'], 'polyline' => $api['polyline'], 'source' => $src];
        } elseif (!$row) {
            // Nothing cached and Routes unavailable: the old Distance Matrix time, no line (not cached, so it upgrades itself later)
            $mi = null;
            $min = calculateDriveTimeBetween($from, $to, $mi);
            return ['min' => $min, 'mi' => $mi, 'line' => null, 'source' => $min === null ? 'none' : 'matrix'];
        }
        // else: Routes failed but an older cached copy exists: better than nothing, use it
    }
    $line = $row['polyline'] ? drive_leg_decode($row['polyline']) : null;
    if ($line && ($row['source'] ?? '') === 'routes_rev') $line = array_reverse($line);
    return [
        'min'    => (int)$row['duration_min'],
        'mi'     => round($row['distance_m'] / 1609.344, 2),
        'line'   => $line ? drive_leg_thin($line) : null,
        'source' => $row['source'] ?? 'routes',
    ];
}

/**
 * Round-trip travel minutes from a dashboard starting address to a summit's starting point (trailhead), using the same
 * closure-aware Google Routes leg as Multi-Activate. Falls back to the summit's own coordinates when the trailhead
 * has no drivable route. Returns ['rt' => int|null, 'source' => string].
 */
function drive_leg_summit_rt($db, $summit, $addr) {
    if (!$addr) return ['rt' => null, 'source' => 'none'];
    $origin = addressOrigin($db, $addr);
    [$dlat, $dlng] = driveDest($summit);
    $leg = drive_leg_get($origin, $dlat . ',' . $dlng);
    if ($leg['min'] === null && !empty($summit['trailhead_lat']) && ($dlat != $summit['latitude'] || $dlng != $summit['longitude'])) {
        $leg = drive_leg_get($origin, $summit['latitude'] . ',' . $summit['longitude']);
    }
    return ['rt' => $leg['min'] === null ? null : $leg['min'] * 2, 'source' => $leg['source']];
}
