<?php
// AJAX: SOTA summits inside a map view, for Multi-Activate's "Show All Summits".
// GET south, north, west, east (degrees). Reads only our own summit index (sota_summit_index, built from SOTA's
// published summit list), never the SOTA API. Returns at most MAX summits, highest points first, and refuses
// views that are too wide so a zoomed-out map never pulls thousands of rows.
require_once 'config.php';
require_once 'sota_cache_helper.php';
session_start();
requireLogin();
session_write_close();

header('Content-Type: application/json');
$MAX = 300;
$MAX_SPAN_DEG = 1.2;   // widest view (in degrees of latitude or longitude) that is answered

$s = isset($_GET['south']) ? (float)$_GET['south'] : null;
$n = isset($_GET['north']) ? (float)$_GET['north'] : null;
$w = isset($_GET['west'])  ? (float)$_GET['west']  : null;
$e = isset($_GET['east'])  ? (float)$_GET['east']  : null;
if ($s === null || $n === null || $w === null || $e === null || $s > $n || $w > $e
    || $s < -90 || $n > 90 || $w < -180 || $e > 180) {
    echo json_encode(['ok' => false, 'error' => 'Bad view']);
    exit;
}
if (($n - $s) > $MAX_SPAN_DEG || ($e - $w) > $MAX_SPAN_DEG * 1.6) {
    echo json_encode(['ok' => true, 'too_wide' => true, 'summits' => []]);
    exit;
}

$idx = sota_index_db();
if (!$idx) { echo json_encode(['ok' => false, 'error' => 'Summit index not available']); exit; }

$st = $idx->prepare("SELECT code, name, points, alt_ft, lat, lon FROM sota_summit_index
                     WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?
                     ORDER BY points DESC, alt_ft DESC LIMIT " . ($MAX + 1));
$st->execute([$s, $n, $w, $e]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$more = count($rows) > $MAX;
if ($more) array_pop($rows);

echo json_encode([
    'ok' => true, 'too_wide' => false, 'more' => $more,
    'summits' => array_map(fn($r) => [
        'ref' => $r['code'], 'name' => $r['name'], 'points' => (int)$r['points'],
        'alt_ft' => (int)$r['alt_ft'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lon'],
    ], $rows),
], JSON_UNESCAPED_UNICODE);
