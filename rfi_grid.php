<?php
// Relays one block of KM6AM's national RFI field grid (km6am.com/summitRFI) to the summit map.
// Each block is fetched from his server once per grid version and served from our cache after that.
require_once 'config.php';
require_once 'rfi_lib.php';
session_start();
requireLogin();
session_write_close();

$layer = (int)($_GET['l'] ?? -1);
$k     = (int)($_GET['k'] ?? -1);
$key   = (string)($_GET['b'] ?? '');
if ($k < 0 || $k > 12 || !preg_match('/^-?\d{1,3}_-?\d{1,3}$/', $key)) {
    http_response_code(400);
    exit;
}

$ix = rfi_grid_index();
$bytes = $ix ? rfi_grid_block($ix, $layer, $k, $key) : null;
if ($bytes === null) {
    http_response_code(404);
    exit;
}
// Still gzip-compressed on purpose: the browser unpacks it (DecompressionStream), so no Content-Encoding
header('Content-Type: application/octet-stream');
header('Content-Length: ' . strlen($bytes));
// URLs carry the grid version (&v=), so a cached copy never goes stale
header('Cache-Control: private, max-age=2592000, immutable');
echo $bytes;
