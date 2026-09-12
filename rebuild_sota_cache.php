<?php
// Rebuilds the local SOTA summit name cache from SOTA's official summit list CSV.
// Run via CLI: php rebuild_sota_cache.php
// Run from browser: rebuild_sota_cache.php?password=sota
// Safe to run multiple times. Takes ~30-60 seconds.
//
// Source: https://storage.sota.org.uk/summitslist.csv — SOTA's official worldwide
// summit list, published for import into third-party logging/planning tools. This
// is the only source that includes each summit's winter BonusPoints value; the
// JSON API (api2.sota.org.uk) does not expose it.

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

echo "Step 1: Downloading official SOTA summit list CSV (~25 MB)...\n";
flush();

// Download to a temp file to avoid loading the whole file into PHP memory
$tmp = tempnam(sys_get_temp_dir(), 'sota_');
$fp  = fopen($tmp, 'wb');
if (!$fp) { echo "ERROR: Cannot create temp file.\n"; exit(1); }

$ch = curl_init('https://storage.sota.org.uk/summitslist.csv');
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => ['User-Agent: SOTAPlanner/2.0'],
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
echo "Downloaded {$size_mb} MB. Step 2: Parsing CSV...\n";
flush();

$fh = fopen($tmp, 'rb');
if (!$fh) { echo "ERROR: Cannot read temp file.\n"; exit(1); }

// First line is a title line, e.g. "SOTA Summits List (Date=11/09/2026)" — skip it.
fgets($fh);

// Second line is the real header row — map column names to indexes so we're not
// dependent on SOTA never reordering columns.
$header = fgetcsv($fh);
if (!$header) { echo "ERROR: Could not read CSV header row.\n"; exit(1); }
$col = array_flip($header);

$required = ['SummitCode', 'SummitName', 'Points', 'BonusPoints', 'AltFt', 'Latitude', 'Longitude', 'ValidTo'];
foreach ($required as $r) {
    if (!isset($col[$r])) { echo "ERROR: CSV is missing expected column '$r'.\n"; exit(1); }
}

$gz = @gzopen(SOTA_CACHE_FILE, 'wb6');
if (!$gz) {
    echo "ERROR: Cannot write to " . SOTA_CACHE_FILE . "\n";
    @unlink($tmp);
    exit(1);
}

$now = time();
$written = 0;
$skipped = 0;

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < count($header)) { $skipped++; continue; }

    $code = trim($row[$col['SummitCode']] ?? '');
    $name = trim($row[$col['SummitName']] ?? '');
    if (!$code || !$name) { $skipped++; continue; }

    // ValidTo is DD/MM/YYYY (UK date format) — skip summits no longer valid for activation
    $valid_to_raw = trim($row[$col['ValidTo']] ?? '');
    if ($valid_to_raw) {
        $vt = DateTime::createFromFormat('d/m/Y', $valid_to_raw);
        if ($vt && $vt->getTimestamp() < $now) { $skipped++; continue; }
    }

    $norm   = normalize_for_search($name);
    $points = (int)($row[$col['Points']] ?? 0);
    $bonus  = (int)($row[$col['BonusPoints']] ?? 0);
    $alt_ft = (int)($row[$col['AltFt']] ?? 0);
    $lat    = round((float)($row[$col['Latitude']]  ?? 0), 6);
    $lon    = round((float)($row[$col['Longitude']] ?? 0), 6);

    // Pipe-delimited: code|original_name|normalized_name|points|alt_ft|lat|lon|bonus_points
    gzwrite($gz, "$code|$name|$norm|$points|$alt_ft|$lat|$lon|$bonus\n");
    $written++;
}

fclose($fh);
gzclose($gz);
@unlink($tmp);

$size_kb = round(filesize(SOTA_CACHE_FILE) / 1024);
echo "Done! Wrote {$written} summits to cache ({$size_kb} KB gzipped).\n";
if ($skipped) echo "Skipped {$skipped} malformed/expired entries.\n";
echo "Summit search is now active on the Nominate page, and winter bonus points are available for backfill_bonus_points.php.\n";
