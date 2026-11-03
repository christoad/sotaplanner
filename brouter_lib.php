<?php
/**
 * BRouter client (hiking path checker).
 *
 * Talks to our self-hosted BRouter routing server (https://hikepath.sotaplanner.com/brouter).
 * PHP only, never from the browser, so the API key never reaches a page.
 *
 * Needs two constants in sotaplanner_secrets.php:
 *   BROUTER_URL      e.g. 'https://hikepath.sotaplanner.com/brouter'
 *   BROUTER_API_KEY  the shared secret sent as the X-Api-Key header
 *
 * Everything returns plain arrays and never throws, so callers can show a message.
 * No caching yet (test phase). The planned permanent cache table keys on the rounded
 * start and end coordinates plus the profile.
 */

/** True when the server URL and key are both configured. */
function brouter_configured() {
    return defined('BROUTER_URL') && BROUTER_URL !== '' && defined('BROUTER_API_KEY') && BROUTER_API_KEY !== '';
}

/** Profiles we allow. The names are files in /opt/brouter/profiles on the server. */
function brouter_profiles() {
    return ['hiking-mountain', 'trekking'];
}

/**
 * Route through two or more points.
 *
 * @param array  $points  [[lat, lon], [lat, lon], ...] (at least 2, at most 10)
 * @param string $profile BRouter profile name (see brouter_profiles())
 * @return array On success: ['ok'=>true, 'distance_m', 'ascend_m', 'descend_m',
 *               'time_s' (BRouter's own bike-style time, do not use for hiking),
 *               'coords' => [[lat, lon, ele_m], ...], 'profile', 'ms'].
 *               On failure: ['ok'=>false, 'error'=>string, 'http'=>int].
 */
function brouter_route(array $points, $profile = 'hiking-mountain') {
    if (!brouter_configured()) {
        return ['ok' => false, 'error' => 'BROUTER_URL / BROUTER_API_KEY are not set in sotaplanner_secrets.php', 'http' => 0];
    }
    if (!preg_match('/^[a-z0-9_-]{1,40}$/i', (string)$profile)) {
        return ['ok' => false, 'error' => 'Invalid profile name', 'http' => 0];
    }
    if (count($points) < 2 || count($points) > 10) {
        return ['ok' => false, 'error' => 'Need between 2 and 10 points', 'http' => 0];
    }
    $pairs = [];
    foreach ($points as $p) {
        $lat = (float)($p[0] ?? 999);
        $lon = (float)($p[1] ?? 999);
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return ['ok' => false, 'error' => 'Coordinates out of range', 'http' => 0];
        }
        // BRouter wants lon,lat order, pipe separated.
        $pairs[] = round($lon, 6) . ',' . round($lat, 6);
    }
    $url = BROUTER_URL . '?' . http_build_query([
        'lonlats'        => implode('|', $pairs),
        'profile'        => $profile,
        'alternativeidx' => 0,
        'format'         => 'geojson',
    ]);

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . BROUTER_API_KEY],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 75,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Could not reach routing server: ' . $cerr, 'http' => 0];
    }
    if ($http === 401) {
        return ['ok' => false, 'error' => 'Routing server rejected the API key', 'http' => 401];
    }
    if ($http === 429 || $http === 503) {
        return ['ok' => false, 'error' => 'Routing server is busy, try again shortly', 'http' => $http];
    }
    if ($http !== 200) {
        // BRouter sends a short plain-text reason, e.g. "no track found" or a missing map tile.
        return ['ok' => false, 'error' => trim(substr(strip_tags($body), 0, 300)) ?: ('HTTP ' . $http), 'http' => $http];
    }
    $j = json_decode($body, true);
    $f = $j['features'][0] ?? null;
    if (!$f || empty($f['geometry']['coordinates'])) {
        return ['ok' => false, 'error' => 'Unexpected response from routing server', 'http' => $http];
    }

    $coords = [];
    $descend = 0.0;
    $ref_ele = null;
    foreach ($f['geometry']['coordinates'] as $c) {
        $ele = isset($c[2]) ? (float)$c[2] : null;
        $coords[] = [(float)$c[1], (float)$c[0], $ele];
        // Drop with a 3 m hysteresis so small terrain-data wiggles are not counted.
        if ($ele !== null) {
            if ($ref_ele === null) { $ref_ele = $ele; }
            elseif ($ele - $ref_ele >= 3.0) { $ref_ele = $ele; }
            elseif ($ref_ele - $ele >= 3.0) { $descend += $ref_ele - $ele; $ref_ele = $ele; }
        }
    }
    $props = $f['properties'] ?? [];
    return [
        'ok'         => true,
        'distance_m' => (float)($props['track-length'] ?? 0),
        'ascend_m'   => (float)($props['filtered ascend'] ?? 0),
        'descend_m'  => round($descend, 1),   // our own 3 m filtered drop (BRouter only reports ascend)
        'time_s'     => (int)($props['total-time'] ?? 0),
        'coords'     => $coords,
        'profile'    => $profile,
        'ms'         => $ms,
    ];
}

/** Convenience: route from one point to another. */
function brouter_route_pair($lat1, $lon1, $lat2, $lon2, $profile = 'hiking-mountain') {
    return brouter_route([[$lat1, $lon1], [$lat2, $lon2]], $profile);
}

/**
 * Read a GPX file's track points as [[lat, lon, ele_m|null], ...], thinned to at most
 * $max points (always keeping the first and last) so it is cheap to draw on a map.
 */
function brouter_read_gpx_points($gpx_path, $max = 600) {
    if (!is_readable($gpx_path)) return [];
    $xml = @simplexml_load_file($gpx_path);
    if (!$xml) return [];
    $xml->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
    $pts = $xml->xpath('//g:trkpt');
    if (!$pts) $pts = $xml->xpath('//trkpt');
    if (!$pts) return [];
    $out = [];
    foreach ($pts as $p) {
        $out[] = [(float)$p['lat'], (float)$p['lon'], isset($p->ele) ? (float)$p->ele : null];
    }
    $n = count($out);
    if ($max > 0 && $n > $max) {
        $step = $n / $max;
        $thin = [];
        for ($i = 0; $i < $max; $i++) $thin[] = $out[(int)floor($i * $step)];
        $thin[] = $out[$n - 1];
        $out = $thin;
    }
    return $out;
}
