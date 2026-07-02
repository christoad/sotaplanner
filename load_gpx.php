<?php
/**
 * load_gpx.php
 * Serves GPX files for map display
 */

require_once 'config.php';
session_start();

$db = getDbConnection();

// Public bypass: allow unauthenticated download when a valid planned activation ID is provided
// (activation invite pages are public, but load_gpx.php normally requires login)
$authed = isset($_SESSION['sota_callsign']);
if (!$authed && !empty($_GET['pa_id']) && !empty($_GET['id'])) {
    $s = $db->prepare("
        SELECT 1 FROM gpx_tracks gt
        JOIN planned_activations pa ON pa.summit_id = gt.summit_id AND pa.planning_group_id = gt.planning_group_id
        WHERE gt.id = ? AND pa.id = ?
    ");
    $s->execute([(int)$_GET['id'], (int)$_GET['pa_id']]);
    if ($s->fetch()) $authed = true;
}
if (!$authed) requireLogin();

// Get GPX track ID
$gpx_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$gpx_id) {
    header('HTTP/1.0 400 Bad Request');
    die('No GPX ID provided');
}

// Get GPX record
$stmt = $db->prepare("SELECT file_path FROM gpx_tracks WHERE id = ?");
$stmt->execute([$gpx_id]);
$gpx = $stmt->fetch();

if (!$gpx) {
    header('HTTP/1.0 404 Not Found');
    die('GPX track not found');
}

// Check if file exists
if (!file_exists($gpx['file_path'])) {
    header('HTTP/1.0 404 Not Found');
    die('GPX file not found on disk');
}

// Serve the file
header('Content-Type: application/gpx+xml');
header('Content-Disposition: inline; filename="track.gpx"');
readfile($gpx['file_path']);
?>
