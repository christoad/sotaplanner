<?php
require_once 'config.php';
session_start();
requireLogin();
// Read-only session use; QRZ calls can be slow, so release the lock.
$sess_callsign = $_SESSION['sota_callsign'] ?? '';
$sess_real     = $_SESSION['_god_mode_real_callsign'] ?? '';
session_write_close();

header('Content-Type: application/json');

if ($sess_callsign !== 'KI6CR' && $sess_real !== 'KI6CR') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

require_once 'qrz_lib.php';

// The callsign list is computed here, never taken from the browser, so this can't be used to
// look up arbitrary callsigns: only people on a dashboard who haven't completed SSO.
$db = getDbConnection();
$calls = $db->query("
    SELECT DISTINCT cs.callsign
    FROM (SELECT callsign FROM planning_group_members UNION SELECT owner_callsign FROM planning_groups) cs
    LEFT JOIN users u ON u.callsign = cs.callsign AND u.sso_sub IS NOT NULL
    WHERE u.callsign IS NULL
    ORDER BY cs.callsign
")->fetchAll(PDO::FETCH_COLUMN);

[$key, $err, $subexp] = qrz_login();
if (!$key) {
    echo json_encode(['ok' => false, 'error' => $err]);
    exit;
}

$results = [];
foreach ($calls as $cs) {
    // QRZ wants the bare callsign: drop any /P, /M etc.
    $base = strtoupper(explode('/', $cs)[0]);
    $results[$cs] = qrz_lookup($key, $base);
    usleep(300000);
}

echo json_encode(['ok' => true, 'subscription' => $subexp, 'results' => $results]);
