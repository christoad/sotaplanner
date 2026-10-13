<?php
define('SOTA_CACHE_FILE', __DIR__ . '/sota_cache.csv.gz');
// Uncompressed copy with the normalized name first (norm|code|name|points|alt_ft|lat|lon|bonus),
// written by sota_index_rebuild_from_gz(). Lets keyword search run as one in-memory pattern match.
define('SOTA_NORM_FILE', __DIR__ . '/sota_cache_norm.txt');

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

// ── Indexed summit table (fast path) ─────────────────────────────────────────
// The gz file above is the source of truth (rebuild_sota_cache.php writes it, the cron jobs read
// it), but unzipping and scanning all ~181k lines on every search is slow. The same data is
// mirrored into the sota_summit_index table, where lookups are indexed. Every function below
// uses the table when it exists and falls back to scanning the gz file when it doesn't.

function sota_index_ddl($table) {
    return "CREATE TABLE IF NOT EXISTS `$table` (
        code   VARCHAR(24)  NOT NULL,
        name   VARCHAR(255) NOT NULL,
        norm   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        points TINYINT      NOT NULL DEFAULT 0,
        alt_ft INT          NOT NULL DEFAULT 0,
        lat    DECIMAL(9,6) NOT NULL DEFAULT 0,
        lon    DECIMAL(9,6) NOT NULL DEFAULT 0,
        bonus  TINYINT      NOT NULL DEFAULT 0,
        PRIMARY KEY (code),
        KEY idx_latlon (lat, lon)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

// PDO handle if the indexed table exists and has data, otherwise null (callers fall back to the gz).
function sota_index_db() {
    static $db = null, $checked = false;
    if ($checked) return $db;
    $checked = true;
    if (!function_exists('getDbConnection')) return null;
    try {
        $c = getDbConnection();
        if ($c->query("SELECT 1 FROM sota_summit_index LIMIT 1")->fetchColumn() !== false) $db = $c;
    } catch (Throwable $e) { $db = null; }
    return $db;
}

// Rebuild sota_summit_index from sota_cache.csv.gz. Loads into a scratch table and swaps it in
// atomically, so searches never see a half-filled table. Returns the number of summits loaded.
function sota_index_rebuild_from_gz(PDO $db) {
    if (!file_exists(SOTA_CACHE_FILE)) throw new Exception('sota_cache.csv.gz not found');
    $gz = @gzopen(SOTA_CACHE_FILE, 'rb');
    if (!$gz) throw new Exception('cannot open sota_cache.csv.gz');

    $db->exec(sota_index_ddl('sota_summit_index'));
    $db->exec("DROP TABLE IF EXISTS sota_summit_index_new");
    $db->exec(sota_index_ddl('sota_summit_index_new'));

    $norm_tmp = SOTA_NORM_FILE . '.tmp';
    $norm_fh  = @fopen($norm_tmp, 'wb');

    $cols  = '(code, name, norm, points, alt_ft, lat, lon, bonus)';
    $batch = [];
    $total = 0;
    $flush = function () use ($db, $cols, &$batch, &$total) {
        if (!$batch) return;
        $ph = implode(',', array_fill(0, count($batch) / 8, '(?,?,?,?,?,?,?,?)'));
        $db->prepare("INSERT IGNORE INTO sota_summit_index_new $cols VALUES $ph")->execute($batch);
        $total += count($batch) / 8;
        $batch = [];
    };

    while (!gzeof($gz)) {
        $line = gzgets($gz, 512);
        if (!$line) continue;
        $f = explode('|', rtrim($line, "\r\n"));
        if (count($f) < 7) continue;
        if ($norm_fh) fwrite($norm_fh, "$f[2]|$f[0]|$f[1]|$f[3]|$f[4]|$f[5]|$f[6]|" . ($f[7] ?? 0) . "\n");
        array_push($batch,
            mb_substr($f[0], 0, 24), mb_substr($f[1], 0, 255), mb_substr($f[2], 0, 255),
            (int)$f[3], (int)$f[4], (float)$f[5], (float)$f[6], (int)($f[7] ?? 0));
        if (count($batch) >= 4000) $flush();   // 500 rows per insert
    }
    $flush();
    gzclose($gz);

    $n = (int)$db->query("SELECT COUNT(*) FROM sota_summit_index_new")->fetchColumn();
    if ($n < 1000) {   // refuse to replace a good table with a suspiciously small one
        $db->exec("DROP TABLE sota_summit_index_new");
        if ($norm_fh) { fclose($norm_fh); @unlink($norm_tmp); }
        throw new Exception("only $n rows loaded, keeping the existing table");
    }
    $db->exec("DROP TABLE IF EXISTS sota_summit_index_old");
    $db->exec("RENAME TABLE sota_summit_index TO sota_summit_index_old, sota_summit_index_new TO sota_summit_index");
    $db->exec("DROP TABLE sota_summit_index_old");
    if ($norm_fh) { fclose($norm_fh); @rename($norm_tmp, SOTA_NORM_FILE); }
    return $n;
}

// Keyword search over the uncompressed norm file. Same matching rules as the gz scan: every
// keyword must appear somewhere in the normalized name. Returns null if the file is unavailable
// or the pattern engine errors, so the caller can fall back to the table.
function sota_norm_file_search(array $keywords, $limit) {
    static $blob = null;
    if ($blob === null) $blob = is_file(SOTA_NORM_FILE) ? (string)@file_get_contents(SOTA_NORM_FILE) : '';
    if ($blob === '') return null;
    @ini_set('pcre.jit', '1');   // off by default on this host; makes the pattern scan several times faster

    $re  = '/^' . implode('', array_map(fn($k) => '(?=[^|\n]*' . preg_quote($k, '/') . ')', $keywords)) . '[^\n]*/m';
    $cmp = fn($a, $b) => $b[2] !== $a[2] ? $b[2] - $a[2] : strcmp($a[1], $b[1]);
    $rows = [];
    $off = 0;
    $len = strlen($blob);
    while ($off < $len && preg_match($re, $blob, $m, PREG_OFFSET_CAPTURE, $off)) {
        $off = $m[0][1] + strlen($m[0][0]) + 1;
        $f = explode('|', $m[0][0]);
        if (count($f) < 8) continue;
        $rows[] = [$f[1], $f[2], (int)$f[3], (int)$f[4], (int)$f[7], (float)$f[5]];
        if (count($rows) >= 4000) { usort($rows, $cmp); $rows = array_slice($rows, 0, $limit); }
    }
    if (preg_last_error() !== PREG_NO_ERROR) return null;
    usort($rows, $cmp);
    return array_map(fn($r) => [
        'ref' => $r[0], 'name' => $r[1], 'points' => $r[2], 'altFt' => $r[3], 'bonus' => $r[4], 'lat' => $r[5],
    ], array_slice($rows, 0, $limit));
}

// Search the cache file. Returns array of [ref, name, points, altFt, bonus] sorted by points desc.
// If the query contains '/', it is treated as a SOTA reference prefix (e.g. "W6/CT-") and
// matched against the code column. Otherwise, keyword search on the name column is used.
function search_sota_cache($query, $limit = 20) {
    $raw = trim($query);
    if (strpos($raw, '/') === false) {
        $kw = array_values(array_filter(preg_split('/\s+/', normalize_for_search($raw))));
        if (empty($kw)) return [];
        $fast = sota_norm_file_search($kw, max(1, (int)$limit));
        if ($fast !== null) return $fast;
    }
    if ($idx = sota_index_db()) {
        $limit = max(1, (int)$limit);
        if (strpos($raw, '/') !== false) {
            // SOTA reference prefix, e.g. "W6/CT-": range scan on the primary key
            $st = $idx->prepare("SELECT code, name, points, alt_ft, bonus, lat FROM sota_summit_index
                                 WHERE code LIKE ? ORDER BY code COLLATE utf8mb4_bin LIMIT $limit");
            $st->execute([addcslashes(strtoupper($raw), '%_\\') . '%']);
        } else {
            $keywords = array_values(array_filter(preg_split('/\s+/', normalize_for_search($raw))));
            if (empty($keywords)) return [];
            $where  = implode(' AND ', array_fill(0, count($keywords), 'norm LIKE ?'));
            $params = array_map(fn($kw) => '%' . addcslashes($kw, '%_\\') . '%', $keywords);
            $st = $idx->prepare("SELECT code, name, points, alt_ft, bonus, lat FROM sota_summit_index
                                 WHERE $where ORDER BY points DESC, name COLLATE utf8mb4_bin LIMIT $limit");
            $st->execute($params);
        }
        return array_map(fn($r) => [
            'ref' => $r['code'], 'name' => $r['name'], 'points' => (int)$r['points'],
            'altFt' => (int)$r['alt_ft'], 'bonus' => (int)$r['bonus'], 'lat' => (float)$r['lat'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }
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
        // Sort alphabetically by ref, gives natural numerical order within an association
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
    $ref = strtoupper(trim($ref));
    if ($idx = sota_index_db()) {
        $st = $idx->prepare("SELECT code, name, points, alt_ft, lat, lon, bonus FROM sota_summit_index WHERE code = ?");
        $st->execute([$ref]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['ref' => $r['code'], 'name' => $r['name'], 'points' => (int)$r['points'],
                     'altFt' => (int)$r['alt_ft'], 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'],
                     'bonus' => (int)$r['bonus']] : null;
    }
    if (!file_exists(SOTA_CACHE_FILE)) return null;

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
    // Bounding box pre-filter to avoid running Haversine on every row
    $lat_delta = $radius_miles / 69.0;
    $lon_delta = $radius_miles / max(1.0, 69.0 * cos(deg2rad($center_lat)));
    $min_lat = $center_lat - $lat_delta;
    $max_lat = $center_lat + $lat_delta;
    $min_lon = $center_lon - $lon_delta;
    $max_lon = $center_lon + $lon_delta;

    if ($idx = sota_index_db()) {
        $st = $idx->prepare("SELECT code, name, points, alt_ft, lat, lon, bonus FROM sota_summit_index
                             WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? AND points >= ?");
        $st->execute([$min_lat, $max_lat, $min_lon, $max_lon, (int)$min_points]);
        $results = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lat = (float)$r['lat']; $lon = (float)$r['lon'];
            if (!$lat && !$lon) continue;
            $dist = haversine_miles($center_lat, $center_lon, $lat, $lon);
            if ($dist > $radius_miles) continue;
            $results[] = [
                'ref' => $r['code'], 'name' => $r['name'], 'points' => (int)$r['points'],
                'altFt' => (int)$r['alt_ft'], 'lat' => $lat, 'lon' => $lon,
                'dist_mi' => round($dist, 1), 'bonus' => (int)$r['bonus'],
            ];
        }
        usort($results, fn($a, $b) => $a['dist_mi'] <=> $b['dist_mi']);
        return $results;
    }

    if (!file_exists(SOTA_CACHE_FILE)) return ['_no_cache' => true];

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
