<?php
// Summit RFI data from KM6AM's Summit RFI project (https://km6am.com/summitRFI/), used with his
// permission (SOTA NA Slack, Nov 2026). He asked to be cited as the source wherever it appears.
//
// Two things come from his site, both cached on our server so user page loads never hit his droplet:
//  1. The summit index /rf/qrm_index.json (risk, affected bands, report card link for ~3,600 US
//     summits). Checked at most once a day (If-Modified-Since) and split into one small file per summit.
//  2. The national ~250 m field grid /summitRFI/national/v/ (index.json plus gzip uint8 blocks).
//     rfi_grid.php relays each block once per grid version; the browser draws it (rfi_layer.js).

const RFI_BASE        = 'https://km6am.com';
const RFI_INDEX_TTL   = 86400;  // re-check his summit index and grid index once a day
const RFI_FAIL_WAIT   = 3600;   // after a failed fetch, serve what we have for an hour before retrying

// One cache shared by production and every staging site (outside the web roots), so each file is
// fetched from his server once, not once per site. Falls back to a folder next to the code locally.
function rfi_cache_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;
    $shared = '/home/chrisr069/sota_rfi_cache';
    if (is_dir($shared) || (is_dir(dirname($shared)) && @mkdir($shared, 0755, true))) {
        $dir = $shared;
    } else {
        $dir = __DIR__ . '/rfi_cache';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }
    return $dir;
}

function rfi_http_get($url, $if_modified_since = null, $timeout = 20) {
    $ch = curl_init($url);
    $headers = [];
    if ($if_modified_since) $headers[] = 'If-Modified-Since: ' . gmdate('D, d M Y H:i:s', $if_modified_since) . ' GMT';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'SOTAplanner (sotaplanner.com) summit RFI layer',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $body];
}

function rfi_write_atomic($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $data) === false) return false;
    return @rename($tmp, $file);
}

function rfi_summit_file($ref) {
    return rfi_cache_dir() . '/summits/' . preg_replace('/[^A-Z0-9_-]/', '_', strtoupper($ref)) . '.json';
}

// Refresh the per-summit files from his summit index when the daily check is due. Cheap when fresh.
function rfi_refresh_summits() {
    $dir   = rfi_cache_dir();
    $meta  = "$dir/summits_meta.json";
    $fail  = "$dir/summits.fail";
    $have  = is_file($meta);
    if ($have && time() - filemtime($meta) < RFI_INDEX_TTL) return;
    if (is_file($fail) && time() - filemtime($fail) < RFI_FAIL_WAIT) return;

    // Only one request at a time does the refresh; others carry on with the current files
    $lock = @fopen("$dir/summits.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;

    $m = $have ? json_decode(@file_get_contents($meta), true) : null;
    [$code, $body] = rfi_http_get(RFI_BASE . '/rf/qrm_index.json', $m['fetched'] ?? null, 30);
    if ($code === 304 && $have) {
        @touch($meta);
    } elseif ($code === 200 && ($j = json_decode($body, true)) && !empty($j['summits'])) {
        foreach ($j['summits'] as $ref => $s) {
            $s['ref'] = $ref;
            rfi_write_atomic(rfi_summit_file($ref), json_encode($s));
        }
        rfi_write_atomic($meta, json_encode([
            'fetched'    => time(),
            'generated'  => $j['generated'] ?? null,
            'count'      => $j['count'] ?? count($j['summits']),
            'provenance' => $j['provenance'] ?? [],
        ]));
        @unlink($fail);
    } else {
        @touch($fail);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

function rfi_summits_meta() {
    $f = rfi_cache_dir() . '/summits_meta.json';
    return is_file($f) ? json_decode(@file_get_contents($f), true) : null;
}

// His summit entry (risk, bands, report link), or null when his index has no RF sources for it
function rfi_summit($ref) {
    rfi_refresh_summits();
    $f = rfi_summit_file($ref);
    return is_file($f) ? json_decode(@file_get_contents($f), true) : null;
}

// Grid index (which blocks exist, grid version). Returns the decoded array, or null.
function rfi_grid_index() {
    $dir  = rfi_cache_dir();
    $file = "$dir/grid_index.json";
    $fail = "$dir/grid_index.fail";
    $have = is_file($file);
    if (!$have || time() - filemtime($file) >= RFI_INDEX_TTL) {
        if (!is_file($fail) || time() - filemtime($fail) >= RFI_FAIL_WAIT) {
            [$code, $body] = rfi_http_get(RFI_BASE . '/summitRFI/national/v/index.json');
            $j = ($code === 200) ? json_decode($body, true) : null;
            if ($j && !empty($j['created']) && !empty($j['keys'])) {
                $old = $have ? json_decode(@file_get_contents($file), true) : null;
                rfi_write_atomic($file, $body);
                @unlink($fail);
                // New grid version: drop the blocks of the old one
                if ($old && ($old['created'] ?? '') !== $j['created']) rfi_rmdir("$dir/grid/" . rfi_version_dir($old['created']));
                $have = true;
            } else {
                @touch($fail);
            }
        }
    }
    return $have ? json_decode(@file_get_contents($file), true) : null;
}

function rfi_version_dir($created) {
    return preg_replace('/[^0-9A-Za-z]/', '', (string)$created);
}

function rfi_rmdir($path) {
    if (!is_dir($path) || strpos($path, rfi_cache_dir() . '/grid/') !== 0) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($path);
}

// Does his national grid cover this point? (lower 48 only)
function rfi_grid_covers($ix, $lat, $lon) {
    if (!$ix || empty($ix['keys']['0'])) return false;
    return in_array(floor($lat) . '_' . floor($lon), $ix['keys']['0'], true);
}

// Raw gzip bytes of one grid block, from our cache or (once) from his server. null if unavailable.
function rfi_grid_block($ix, $layer, $k, $key) {
    if (!isset($ix['keys'][(string)$k]) || !in_array($key, $ix['keys'][(string)$k], true)) return null;
    if ($layer < 0 || $layer >= count($ix['layers'] ?? [])) return null;
    $file = rfi_cache_dir() . '/grid/' . rfi_version_dir($ix['created']) . "/$layer/$k/$key.bin";
    if (is_file($file)) return file_get_contents($file);
    [$code, $body] = rfi_http_get(RFI_BASE . "/summitRFI/national/v/$layer/$k/$key.bin?v=" . rawurlencode($ix['created']));
    // gzip magic number check, so an error page never gets cached as data
    if ($code !== 200 || strlen($body) < 18 || substr($body, 0, 2) !== "\x1f\x8b") return null;
    rfi_write_atomic($file, $body);
    return $body;
}

// Full report card on his site, or null if the entry's link is missing or not the expected shape
function rfi_report_url($s) {
    $r = $s['report'] ?? '';
    return preg_match('#^reports/[A-Za-z0-9_.-]+\.html$#', $r) ? RFI_BASE . '/rf/' . $r : null;
}
