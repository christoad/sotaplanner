<?php
/**
 * Parking and trailhead list (OpenStreetMap), for trail discovery and later the GPX editor.
 *
 * The list lives on the BRouter box and is served by nginx at
 *   https://hikepath.sotaplanner.com/parking/<i>_<j>.tsv   (i = floor(lat/0.2), j = floor(lon/0.2))
 * one file per 0.2 degree map square, same X-Api-Key header as BRouter. Built by the scripts in
 * research/osmindex/. A missing square answers 404 and simply means no points there.
 * Columns: kind (P parking, T trailhead), lat, lon, osm_id, access, fee, parking, name, km to nearest summit.
 *
 * PHP only (the key never reaches a page). Every square is cached on our server per list version
 * (sota_parking_cache/, shared by production and staging), so each one is fetched from the box at
 * most once per version. Everything returns plain arrays and never throws.
 * Data: OpenStreetMap contributors (ODbL). Credit them wherever these points are shown.
 */

const PK_CELL_DEG = 0.2;

function pk_configured() {
    return defined('BROUTER_URL') && BROUTER_URL !== '' && defined('BROUTER_API_KEY') && BROUTER_API_KEY !== '';
}

function pk_base_url() {
    return preg_replace('#/brouter/?$#', '', BROUTER_URL) . '/parking/';
}

/** Private cache folder one level above the web roots (same rule as td_store_dir()). */
function pk_cache_dir() {
    $home = file_exists(dirname(__DIR__) . '/sotaplanner_secrets.php') ? dirname(__DIR__) : dirname(dirname(__DIR__));
    return $home . '/sota_parking_cache';
}

/** GET one file from the box. Returns [http_code, body]; code 0 means it could not connect. */
function pk_http($name) {
    $ch = curl_init(pk_base_url() . $name);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-Api-Key: ' . BROUTER_API_KEY],
        CURLOPT_ENCODING => '', CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body === false ? 0 : $code, $body === false ? '' : $body];
}

/** The list's version (its build date). index.json is re-checked at most once a day; a stale copy is used if the box is down. */
function pk_version() {
    static $ver = null;
    if ($ver !== null) return $ver;
    $dir = pk_cache_dir();
    $f = $dir . '/index.json';
    $idx = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!$idx || filemtime($f) < time() - 86400) {
        [$code, $body] = pk_http('index.json');
        $new = $code === 200 ? json_decode($body, true) : null;
        if ($new && !empty($new['version'])) {
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            @file_put_contents($f, $body);
            if ($idx && ($idx['version'] ?? '') !== $new['version']) pk_drop_version($idx['version']);   // the list was rebuilt
            $idx = $new;
        } elseif ($idx) {
            @touch($f);   // box unreachable: keep using what we have, try again tomorrow
        }
    }
    $ver = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($idx['version'] ?? '')) ?: 'unknown';
    return $ver;
}

function pk_drop_version($v) {
    $d = pk_cache_dir() . '/' . preg_replace('/[^0-9A-Za-z_-]/', '', (string)$v);
    if ($v === '' || !is_dir($d)) return;
    foreach (glob($d . '/*.tsv') ?: [] as $x) @unlink($x);
    @rmdir($d);
}

/**
 * One map square as rows. Returns null when the box could not be reached (nothing cached then,
 * so the next call tries again); an empty array when the square has no points.
 */
function pk_cell($i, $j) {
    $ver = pk_version();
    $name = (int)$i . '_' . (int)$j . '.tsv';
    $f = pk_cache_dir() . '/' . $ver . '/' . $name;
    if (is_file($f)) {
        $body = (string)@file_get_contents($f);
    } else {
        [$code, $body] = pk_http($name);
        if ($code === 404) $body = '';
        elseif ($code !== 200) return null;
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
        @file_put_contents($f, $body);
    }
    $rows = [];
    foreach (explode("\n", $body) as $line) {
        if ($line === '') continue;
        $p = explode("\t", $line);
        if (count($p) < 3) continue;
        $rows[] = ['k' => $p[0], 'lat' => (float)$p[1], 'lon' => (float)$p[2], 'id' => $p[3] ?? '',
                   'access' => $p[4] ?? '', 'fee' => $p[5] ?? '', 'type' => $p[6] ?? '', 'name' => $p[7] ?? ''];
    }
    return $rows;
}

function pk_dist($lat1, $lon1, $lat2, $lon2) {
    $dl = deg2rad($lat2 - $lat1); $dn = deg2rad($lon2 - $lon1);
    $a = sin($dl / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dn / 2) ** 2;
    return 12742000 * asin(min(1, sqrt($a)));
}

/**
 * Parking lots and trailheads within $radius_m of a point, nearest first, at most $limit.
 * Returns ['ok' => bool, 'points' => [[k, lat, lon, id, access, fee, type, name, d (m)], ...], 'error' => string|null].
 */
function pk_near($lat, $lon, $radius_m, $limit = 3000) {
    if (!pk_configured()) return ['ok' => false, 'points' => [], 'error' => 'BROUTER_URL / BROUTER_API_KEY are not set'];
    $dLat = $radius_m / 111200;
    $dLon = $radius_m / (111200 * max(0.05, cos(deg2rad($lat))));
    $pts = []; $missing = 0;
    for ($i = (int)floor(($lat - $dLat) / PK_CELL_DEG); $i <= (int)floor(($lat + $dLat) / PK_CELL_DEG); $i++) {
        for ($j = (int)floor(($lon - $dLon) / PK_CELL_DEG); $j <= (int)floor(($lon + $dLon) / PK_CELL_DEG); $j++) {
            $rows = pk_cell($i, $j);
            if ($rows === null) { $missing++; continue; }
            foreach ($rows as $r) {
                if (abs($r['lat'] - $lat) > $dLat || abs($r['lon'] - $lon) > $dLon) continue;
                $d = pk_dist($lat, $lon, $r['lat'], $r['lon']);
                if ($d <= $radius_m) { $r['d'] = (int)round($d); $pts[] = $r; }
            }
        }
    }
    usort($pts, fn($a, $b) => $a['d'] <=> $b['d']);
    return ['ok' => $missing === 0, 'points' => array_slice($pts, 0, $limit),
            'error' => $missing ? $missing . ' map square(s) could not be loaded from the parking list server' : null];
}

/**
 * Nearest point in a list to a location. $kind 'T' for trailheads only, null for any.
 * Returns ['d' => metres, 'k', 'name', 'lat', 'lon', 'access', 'fee'] or null when nothing within $max_m.
 */
function pk_nearest(array $points, $lat, $lon, $kind = null, $max_m = 1000) {
    $best = null;
    foreach ($points as $p) {
        if ($kind !== null && $p['k'] !== $kind) continue;
        $d = pk_dist($lat, $lon, $p['lat'], $p['lon']);
        if ($d <= $max_m && ($best === null || $d < $best['d'])) {
            $best = ['d' => (int)round($d), 'k' => $p['k'], 'name' => $p['name'], 'lat' => $p['lat'], 'lon' => $p['lon'],
                     'access' => $p['access'], 'fee' => $p['fee']];
        }
    }
    return $best;
}
