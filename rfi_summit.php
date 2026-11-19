<?php
// JSON for the RFI layer on summit_detail.php: does KM6AM's national grid cover this summit,
// and what does his Summit RFI report card say about it? (Data: KM6AM, km6am.com/summitRFI)
require_once 'config.php';
require_once 'rfi_lib.php';
session_start();
requireLogin();
session_write_close();
header('Content-Type: application/json');

$ref = strtoupper(trim($_GET['ref'] ?? ''));
$lat = (float)($_GET['lat'] ?? 0);
$lon = (float)($_GET['lon'] ?? 0);
if (!preg_match('#^[A-Z0-9]{1,8}/[A-Z0-9]{1,4}-[0-9]{1,4}$#', $ref) || abs($lat) > 90 || abs($lon) > 180) {
    echo json_encode(['error' => 'bad request']);
    exit;
}

$ix = rfi_grid_index();
$s  = rfi_summit($ref);
$meta = rfi_summits_meta();

$out = [
    'covered' => rfi_grid_covers($ix, $lat, $lon),
    'grid'    => $ix ? ['created' => $ix['created'], 'levels' => (int)$ix['levels'], 'layers' => $ix['layers'], 'keys' => $ix['keys']] : null,
    'data_date' => $meta['generated'] ?? null,
    'summit'  => null,
];
if ($s) {
    $top = [];
    foreach (array_slice($s['top'] ?? [], 0, 3) as $t) {
        // [owner, service, frequencies, ERP watts, distance m]
        $top[] = ['owner' => (string)($t[0] ?? ''), 'freqs' => (string)($t[2] ?? ''), 'erp_w' => (float)($t[3] ?? 0), 'dist_m' => (float)($t[4] ?? 0)];
    }
    $out['summit'] = [
        'risk'       => strtoupper((string)($s['risk'] ?? '')),
        'high'       => array_values(array_map('strval', $s['qrm_levels']['high'] ?? [])),
        'moderate'   => array_values(array_map('strval', $s['qrm_levels']['moderate'] ?? [])),
        'total_erp_w' => (float)($s['total_erp_w'] ?? 0),
        'sources'    => (int)($s['n'] ?? 0),
        'top'        => $top,
        'report_url' => rfi_report_url($s),
    ];
}
echo json_encode($out);
