<?php
define('SOTA_CACHE_FILE', __DIR__ . '/sota_cache.csv.gz');

// Expand common summit abbreviations to canonical forms so "Mt Adams" matches "Mount Adams"
function normalize_for_search($str) {
    $s = mb_strtolower($str, 'UTF-8');
    $s = preg_replace('/\bmt\.?\b/',        'mount', $s);
    $s = preg_replace('/\bmtns?\.?\b/',     'mount', $s);
    $s = preg_replace('/\bmountains?\b/',   'mount', $s);
    $s = preg_replace('/\bpks?\.?\b/',      'peak',  $s);
    $s = preg_replace('/\bpeaks?\b/',       'peak',  $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// Search the cache file. Returns array of [ref, name, points, altFt, bonus] sorted by points desc.
// If the query contains '/', it is treated as a SOTA reference prefix (e.g. "W6/CT-") and
// matched against the code column. Otherwise, keyword search on the name column is used.
function search_sota_cache($query, $limit = 20) {
    if (!file_exists(SOTA_CACHE_FILE)) {
        return ['_no_cache' => true];
    }

    $raw = trim($query);
    $is_ref_search = strpos($raw, '/') !== false;

    $results = [];
    $gz = @gzopen(SOTA_CACHE_FILE, 'rb');
    if (!$gz) return [];

    if ($is_ref_search) {
        $ref_prefix = strtoupper($raw);
        while (!gzeof($gz)) {
            $line = gzgets($gz, 512);
            if (!$line) continue;
            $s = explode('|', rtrim($line, "\r\n"));
            if (count($s) < 5) continue;
            if (strpos(strtoupper($s[0]), $ref_prefix) === 0) {
                $results[] = [$s[0], $s[1], (int)$s[3], (int)$s[4], (int)($s[7] ?? 0), isset($s[5]) ? (float)$s[5] : null];
            }
        }
        gzclose($gz);
        // Sort alphabetically by ref — gives natural numerical order within an association
        usort($results, fn($a, $b) => strcmp($a[0], $b[0]));
    } else {
        $norm = normalize_for_search($raw);
        $keywords = array_values(array_filter(preg_split('/\s+/', $norm)));
        if (empty($keywords)) { gzclose($gz); return []; }

        while (!gzeof($gz)) {
            $line = gzgets($gz, 512);
            if (!$line) continue;
            $s = explode('|', rtrim($line, "\r\n"));
            if (count($s) < 5) continue;
            $norm_name = $s[2];
            foreach ($keywords as $kw) {
                if (strpos($norm_name, $kw) === false) continue 2;
            }
            $results[] = [$s[0], $s[1], (int)$s[3], (int)$s[4], (int)($s[7] ?? 0), isset($s[5]) ? (float)$s[5] : null];
        }
        gzclose($gz);
        usort($results, fn($a, $b) => $b[2] !== $a[2] ? $b[2] - $a[2] : strcmp($a[1], $b[1]));
    }

    return array_map(fn($r) => [
        'ref'    => $r[0],
        'name'   => $r[1],
        'points' => $r[2],
        'altFt'  => $r[3],
        'bonus'  => $r[4],
        'lat'    => $r[5],
    ], array_slice($results, 0, $limit));
}

// Exact lookup of a single summit by its SOTA reference (e.g. "W7O/NC-001").
// Returns null if not found or the cache doesn't exist. Used to backfill bonus_points
// (and other cache-only fields) for a specific summit at nomination time.
function get_sota_cache_summit($ref) {
    if (!file_exists(SOTA_CACHE_FILE)) return null;
    $ref = strtoupper(trim($ref));

    $gz = @gzopen(SOTA_CACHE_FILE, 'rb');
    if (!$gz) return null;

    $found = null;
    while (!gzeof($gz)) {
        $line = gzgets($gz, 512);
        if (!$line) continue;
        $s = explode('|', rtrim($line, "\r\n"));
        if (count($s) < 5) continue;
        if (strtoupper($s[0]) === $ref) {
            $found = [
                'ref'    => $s[0],
                'name'   => $s[1],
                'points' => (int)$s[3],
                'altFt'  => (int)$s[4],
                'lat'    => isset($s[5]) ? (float)$s[5] : null,
                'lon'    => isset($s[6]) ? (float)$s[6] : null,
                'bonus'  => (int)($s[7] ?? 0),
            ];
            break;
        }
    }
    gzclose($gz);
    return $found;
}

function haversine_miles($lat1, $lon1, $lat2, $lon2) {
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
    return 3958.8 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Search the cache for summits within $radius_miles of ($center_lat, $center_lon).
// Returns an array of summit rows with an added 'dist_mi' key, sorted by distance.
// Returns ['_no_cache' => true] if the cache file is missing.
// Returns ['_no_latlon' => true] if the cache is old (no coordinates stored).
function search_sota_cache_by_radius($center_lat, $center_lon, $radius_miles, $min_points = 1) {
    if (!file_exists(SOTA_CACHE_FILE)) return ['_no_cache' => true];

    // Bounding box pre-filter to avoid running Haversine on every row
    $lat_delta = $radius_miles / 69.0;
    $lon_delta = $radius_miles / max(1.0, 69.0 * cos(deg2rad($center_lat)));
    $min_lat = $center_lat - $lat_delta;
    $max_lat = $center_lat + $lat_delta;
    $min_lon = $center_lon - $lon_delta;
    $max_lon = $center_lon + $lon_delta;

    $results   = [];
    $has_coords = false;
    $gz = @gzopen(SOTA_CACHE_FILE, 'rb');
    if (!$gz) return [];

    while (!gzeof($gz)) {
        $line = gzgets($gz, 512);
        if (!$line) continue;
        $s = explode('|', rtrim($line, "\r\n"));
        if (count($s) < 7) continue;
        $has_coords = true;

        $points = (int)$s[3];
        if ($points < $min_points) continue;

        $lat = (float)$s[5];
        $lon = (float)$s[6];
        if (!$lat && !$lon) continue;

        if ($lat < $min_lat || $lat > $max_lat || $lon < $min_lon || $lon > $max_lon) continue;

        $dist = haversine_miles($center_lat, $center_lon, $lat, $lon);
        if ($dist > $radius_miles) continue;

        $results[] = [
            'ref'     => $s[0],
            'name'    => $s[1],
            'points'  => $points,
            'altFt'   => (int)$s[4],
            'lat'     => $lat,
            'lon'     => $lon,
            'dist_mi' => round($dist, 1),
            'bonus'   => (int)($s[7] ?? 0),
        ];
    }
    gzclose($gz);

    if (!$has_coords) return ['_no_latlon' => true];

    usort($results, fn($a, $b) => $a['dist_mi'] <=> $b['dist_mi']);
    return $results;
}

function get_sota_cache_info() {
    if (!file_exists(SOTA_CACHE_FILE)) return null;
    // Count lines by streaming through the gz
    $gz = @gzopen(SOTA_CACHE_FILE, 'rb');
    if (!$gz) return null;
    $count = 0;
    while (!gzeof($gz)) { if (gzgets($gz, 512)) $count++; }
    gzclose($gz);
    return [
        'count'      => $count,
        'size_kb'    => round(filesize(SOTA_CACHE_FILE) / 1024),
        'modified'   => filemtime(SOTA_CACHE_FILE),
    ];
}
