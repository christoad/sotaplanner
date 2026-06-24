<?php
// Rebuilds the local SOTA summit name cache from the SOTA API.
// Run via CLI: php rebuild_sota_cache.php
// Run from browser: rebuild_sota_cache.php?password=sota
// Safe to run multiple times. Takes ~30-60 seconds.

ini_set('max_execution_time', 300);

require_once 'sota_cache_helper.php';

$is_cli = (php_sapi_name() === 'cli');
$password = $is_cli ? 'sota' : ($_GET['password'] ?? '');
if ($password !== 'sota') {
    if (!$is_cli) http_response_code(403);
    echo "Unauthorized. Add ?password=sota to the URL.\n";
    exit;
}

if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    // Flush output as we go
    while (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);
}

echo "Step 1: Downloading SOTA summit list (~90 MB)...\n";
flush();

// Download to a temp file to avoid loading 90MB into PHP memory
$tmp = tempnam(sys_get_temp_dir(), 'sota_');
$fp  = fopen($tmp, 'wb');
if (!$fp) { echo "ERROR: Cannot create temp file.\n"; exit(1); }

$ch = curl_init('https://api2.sota.org.uk/api/summits/search?term=x');
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: SOTAPlanner/2.0'],
]);
curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if ($curl_err || $http_code !== 200) {
    echo "ERROR: Download failed (HTTP $http_code). $curl_err\n";
    @unlink($tmp);
    exit(1);
}

$size_mb = round(filesize($tmp) / 1048576, 1);
echo "Downloaded {$size_mb} MB. Step 2: Parsing summits one at a time...\n";
flush();

// Open output gz file
$gz = @gzopen(SOTA_CACHE_FILE, 'wb6');
if (!$gz) {
    echo "ERROR: Cannot write to " . SOTA_CACHE_FILE . "\n";
    @unlink($tmp);
    exit(1);
}

// Stream-parse the JSON array — reads one {object} at a time, no full decode
$fh = fopen($tmp, 'rb');
$written  = 0;
$skipped  = 0;
$buf      = '';
$depth    = 0;
$in_str   = false;
$escaped  = false;
$in_obj   = false;
$chunk_sz = 65536;

while (!feof($fh)) {
    $chunk = fread($fh, $chunk_sz);
    if ($chunk === false || $chunk === '') continue;

    for ($i = 0, $len = strlen($chunk); $i < $len; $i++) {
        $c = $chunk[$i];

        if (!$in_obj) {
            if ($c === '{') { $in_obj = true; $buf = '{'; $depth = 1; }
            continue;
        }

        $buf .= $c;

        if ($escaped)      { $escaped = false; continue; }
        if ($in_str) {
            if ($c === '\\') $escaped = true;
            elseif ($c === '"') $in_str = false;
            continue;
        }
        if ($c === '"')  { $in_str = true;  continue; }
        if ($c === '{')  { $depth++;         continue; }
        if ($c === '}') {
            $depth--;
            if ($depth > 0) continue;

            // Complete object — decode and write
            $s = json_decode($buf, true);
            $buf = '';
            $in_obj = false;

            if (!$s) { $skipped++; continue; }

            $code = trim($s['summitCode'] ?? '');
            $name = trim($s['name'] ?? '');
            if (!$code || !$name) { $skipped++; continue; }

            // Skip expired summits
            if (isset($s['valid']) && $s['valid'] === false) { $skipped++; continue; }
            $valid_to = $s['validTo'] ?? null;
            if ($valid_to && strtotime($valid_to) < time()) { $skipped++; continue; }

            $norm   = normalize_for_search($name);
            $points = (int)($s['points'] ?? 0);
            $alt_ft = (int)($s['altFt']  ?? 0);
            $lat    = round((float)($s['latitude']  ?? $s['lat'] ?? 0), 6);
            $lon    = round((float)($s['longitude'] ?? $s['lng'] ?? $s['long'] ?? 0), 6);

            // Pipe-delimited: code|original_name|normalized_name|points|alt_ft|lat|lon
            gzwrite($gz, "$code|$name|$norm|$points|$alt_ft|$lat|$lon\n");
            $written++;
        }
    }
}
fclose($fh);
gzclose($gz);
@unlink($tmp);

$size_kb = round(filesize(SOTA_CACHE_FILE) / 1024);
echo "Done! Wrote {$written} summits to cache ({$size_kb} KB gzipped).\n";
if ($skipped) echo "Skipped {$skipped} malformed entries.\n";
echo "Summit search is now active on the Nominate page.\n";
