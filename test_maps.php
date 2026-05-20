<?php
require_once 'config.php';

$key = GOOGLE_MAPS_API_KEY;

// Test Distance Matrix API (used for drive time)
$dm_url = "https://maps.googleapis.com/maps/api/distancematrix/json?" . http_build_query([
    'origins'       => 'Portland, OR',
    'destinations'  => 'Mount Hood, OR',
    'mode'          => 'driving',
    'key'           => $key,
]);
$dm_raw  = file_get_contents($dm_url);
$dm_json = json_decode($dm_raw, true);
$dm_status = $dm_json['status'] ?? 'NO_STATUS';
$dm_ok = $dm_status === 'OK';

// Test Geocoding API (used for address → lat/lng)
$geo_url = "https://maps.googleapis.com/maps/api/geocode/json?" . http_build_query([
    'address' => 'Portland, OR',
    'key'     => $key,
]);
$geo_raw  = file_get_contents($geo_url);
$geo_json = json_decode($geo_raw, true);
$geo_status = $geo_json['status'] ?? 'NO_STATUS';
$geo_ok = $geo_status === 'OK';

function statusBadge(bool $ok, string $status): string {
    if ($ok) return '<span style="color:#16a34a;font-weight:700;">✓ OK</span>';
    return '<span style="color:#dc2626;font-weight:700;">✗ ' . htmlspecialchars($status) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Google Maps API Test — SOTA Planner</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 780px; margin: 2rem auto; padding: 0 1rem; background: #f8f7f4; color: #1c1b19; }
h1 { font-size: 1.3rem; margin-bottom: 0.25rem; }
.subtitle { color: #888; font-size: 0.85rem; margin-bottom: 2rem; }
.card { background: #fff; border: 1px solid #e5e2da; border-radius: 10px; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; }
.card h2 { font-size: 0.95rem; font-weight: 700; margin-bottom: 0.75rem; color: #333; }
.row { display: flex; justify-content: space-between; align-items: baseline; padding: 0.3rem 0; border-bottom: 1px solid #f0ede8; font-size: 0.875rem; }
.row:last-child { border-bottom: none; }
.label { color: #666; }
.key-box { font-family: monospace; font-size: 0.82rem; background: #f0ede8; padding: 0.25rem 0.5rem; border-radius: 4px; word-break: break-all; }
pre { background: #f4f2ee; border-radius: 6px; padding: 0.75rem 1rem; font-size: 0.78rem; overflow-x: auto; margin-top: 0.5rem; white-space: pre-wrap; word-break: break-word; }
.maps-test { margin-top: 0.5rem; }
#map { width: 100%; height: 300px; border-radius: 6px; border: 1px solid #e5e2da; margin-top: 0.75rem; }
</style>
</head>
<body>

<h1>Google Maps API Diagnostics</h1>
<p class="subtitle">SOTA Planner · Key in use: <span class="key-box"><?= htmlspecialchars(substr($key,0,8)) ?>…<?= htmlspecialchars(substr($key,-4)) ?></span></p>

<div class="card">
    <h2>Distance Matrix API <small style="font-weight:400;color:#888;">(drive time calculation)</small></h2>
    <div class="row"><span class="label">Status</span><?= statusBadge($dm_ok, $dm_status) ?></div>
    <?php if (!$dm_ok): ?>
    <div class="row"><span class="label">Error message</span><span><?= htmlspecialchars($dm_json['error_message'] ?? 'none') ?></span></div>
    <?php else: ?>
    <div class="row"><span class="label">Test route</span><span>Portland, OR → Mount Hood, OR</span></div>
    <div class="row"><span class="label">Duration</span><span><?= htmlspecialchars($dm_json['rows'][0]['elements'][0]['duration']['text'] ?? '—') ?></span></div>
    <div class="row"><span class="label">Distance</span><span><?= htmlspecialchars($dm_json['rows'][0]['elements'][0]['distance']['text'] ?? '—') ?></span></div>
    <?php endif; ?>
    <details style="margin-top:0.6rem;"><summary style="font-size:0.8rem;color:#888;cursor:pointer;">Raw response</summary><pre><?= htmlspecialchars(json_encode($dm_json, JSON_PRETTY_PRINT)) ?></pre></details>
</div>

<div class="card">
    <h2>Geocoding API <small style="font-weight:400;color:#888;">(address → lat/lng)</small></h2>
    <div class="row"><span class="label">Status</span><?= statusBadge($geo_ok, $geo_status) ?></div>
    <?php if (!$geo_ok): ?>
    <div class="row"><span class="label">Error message</span><span><?= htmlspecialchars($geo_json['error_message'] ?? 'none') ?></span></div>
    <?php else: ?>
    <div class="row"><span class="label">Test address</span><span>Portland, OR</span></div>
    <div class="row"><span class="label">Lat/Lng</span><span><?= htmlspecialchars($geo_json['results'][0]['geometry']['location']['lat'] ?? '—') ?>, <?= htmlspecialchars($geo_json['results'][0]['geometry']['location']['lng'] ?? '—') ?></span></div>
    <?php endif; ?>
    <details style="margin-top:0.6rem;"><summary style="font-size:0.8rem;color:#888;cursor:pointer;">Raw response</summary><pre><?= htmlspecialchars(json_encode($geo_json, JSON_PRETTY_PRINT)) ?></pre></details>
</div>

<div class="card">
    <h2>Maps JavaScript API <small style="font-weight:400;color:#888;">(interactive maps)</small></h2>
    <p style="font-size:0.85rem;color:#666;margin-bottom:0.5rem;">If the map renders below, the JS API and key are working. Check the browser console for any errors.</p>
    <div id="map"></div>
    <script>
    function initMap() {
        var map = new google.maps.Map(document.getElementById('map'), {
            center: { lat: 45.3733, lng: -121.6959 },
            zoom: 10,
            mapTypeId: 'terrain'
        });
        new google.maps.Marker({ position: { lat: 45.3733, lng: -121.6959 }, map: map, title: 'Mount Hood' });
    }
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($key) ?>&callback=initMap" async defer
        onerror="document.getElementById('map').innerHTML='<p style=\'color:red;padding:1rem;\'>❌ JS API failed to load — check browser console</p>'"></script>
</div>

</body>
</html>
