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

// Search the cache file. Returns array of [ref, name, points, altFt] sorted by points desc.
function search_sota_cache($query, $limit = 20) {
    if (!file_exists(SOTA_CACHE_FILE)) {
        return ['_no_cache' => true];
    }

    $norm = normalize_for_search(trim($query));
    $keywords = array_values(array_filter(preg_split('/\s+/', $norm)));
    if (empty($keywords)) return [];

    $results = [];
    $gz = @gzopen(SOTA_CACHE_FILE, 'rb');
    if (!$gz) return [];

    while (!gzeof($gz)) {
        $line = gzgets($gz, 512);
        if (!$line) continue;
        $s = explode('|', rtrim($line, "\r\n"), 5);
        if (count($s) < 5) continue;
        // columns: code | original_name | normalized_name | points | alt_ft
        $norm_name = $s[2];
        foreach ($keywords as $kw) {
            if (strpos($norm_name, $kw) === false) continue 2;
        }
        $results[] = [$s[0], $s[1], (int)$s[3], (int)$s[4]];
    }
    gzclose($gz);

    usort($results, fn($a, $b) => $b[2] !== $a[2] ? $b[2] - $a[2] : strcmp($a[1], $b[1]));

    return array_map(fn($r) => [
        'ref'    => $r[0],
        'name'   => $r[1],
        'points' => $r[2],
        'altFt'  => $r[3],
    ], array_slice($results, 0, $limit));
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
