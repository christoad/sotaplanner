<?php
// JSON for the compact weather row on summit_detail.php (loaded after page render so a slow forecast never blocks the page)
require_once 'config.php';
require_once 'weather_lib.php';
session_start();
requireLogin();
$db = getDbConnection();
// Same source as the rest of summit_detail.php: the viewer's own units setting
$units = getUserUnits($db);
session_write_close();
header('Content-Type: application/json');

$w = weather_fetch($_GET['lat'] ?? 0, $_GET['lon'] ?? 0, $_GET['elev_m'] ?? 0);
$c = weather_compact($w, $units);
echo json_encode($c ?: ['error' => true]);
