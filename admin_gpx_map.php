<?php
require_once 'config.php';
session_start();
requireLogin();

$callsign = $_SESSION['sota_callsign'] ?? '';
$real     = $_SESSION['_god_mode_real_callsign'] ?? '';
if ($callsign !== 'KI6CR' && $real !== 'KI6CR') {
    header('Location: index.php');
    exit;
}

$db = getDbConnection();

$total_routes   = (int)$db->query("SELECT COUNT(*) FROM global_gpx_tracks WHERE summit_lat IS NOT NULL AND summit_lon IS NOT NULL")->fetchColumn();
$total_trail    = (int)$db->query("SELECT COUNT(*) FROM global_gpx_tracks WHERE trailhead_lat IS NOT NULL AND trailhead_lon IS NOT NULL")->fetchColumn();
$total_in_plans = (int)$db->query("SELECT COUNT(DISTINCT g.sota_ref) FROM global_gpx_tracks g INNER JOIN summits s ON s.sota_ref = g.sota_ref")->fetchColumn();

$bkey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GPX Coverage Map — SOTA Planner</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:            #F7F6F3;
  --bg-2:          #EFEDE8;
  --bg-3:          #E5E2DA;
  --ink:           #1C1B19;
  --ink-2:         #4A4844;
  --ink-3:         #8C8A86;
  --ink-4:         #B8B5B0;
  --accent:        oklch(52% 0.13 50);
  --accent-2:      oklch(44% 0.13 50);
  --accent-bg:     oklch(96% 0.04 65);
  --accent-border: oklch(84% 0.08 65);
  --green:         oklch(52% 0.13 155);
  --green-bg:      oklch(95% 0.04 155);
  --blue:          oklch(52% 0.12 240);
  --blue-bg:       oklch(95% 0.04 240);
  --surface:       #FFFFFF;
  --border:        #E5E2DA;
  --border-2:      #D4D0C8;
  --font-sans:     'DM Sans', system-ui, sans-serif;
  --font-mono:     'DM Mono', 'Courier New', monospace;
  --r-sm: 4px; --r-md: 8px; --r-lg: 12px;
  --sp-2: 0.5rem; --sp-3: 0.75rem; --sp-4: 1rem; --sp-6: 1.5rem; --sp-8: 2rem;
  --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font-sans); background: var(--bg); color: var(--ink); -webkit-font-smoothing: antialiased; }

.topbar {
  background: var(--surface); border-bottom: 1px solid var(--border);
  height: 56px; display: flex; align-items: center; padding: 0 var(--sp-8);
  gap: var(--sp-4); position: sticky; top: 0; z-index: 200; flex-shrink: 0;
}
.topbar-logo { display: flex; align-items: center; gap: var(--sp-3); text-decoration: none; color: var(--ink); font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0; }
.topbar-logo:hover { color: var(--ink); }
.topbar-logo .logo-mark { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
.topbar-crumb { font-size: 0.875rem; color: var(--ink-3); }
.topbar-crumb a { color: var(--ink-3); text-decoration: none; }
.topbar-crumb a:hover { color: var(--ink); }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: var(--sp-3); flex-shrink: 0; }

.stat-bar {
  background: var(--surface); border-bottom: 1px solid var(--border);
  padding: 0.6rem var(--sp-8); display: flex; align-items: center;
  gap: var(--sp-6); flex-shrink: 0;
}
.stat-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; }
.stat-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.stat-dot-green  { background: #2e7d52; }
.stat-dot-blue   { background: #1a6bb5; }
.stat-dot-orange { background: #b87a1a; }
.stat-num { font-weight: 700; color: var(--ink); }
.stat-label { color: var(--ink-3); }

.stat-bar-right { margin-left: auto; font-size: 0.78rem; color: var(--ink-3); }

#map-wrap { flex: 1; position: relative; min-height: 0; }
#map { width: 100%; height: 100%; }

.page-layout { display: flex; flex-direction: column; height: 100vh; }

#loading-overlay {
  position: absolute; inset: 0; background: rgba(247,246,243,0.85);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  z-index: 10; gap: 1rem;
}
.spinner {
  width: 36px; height: 36px; border: 3px solid var(--border);
  border-top-color: var(--accent); border-radius: 50%;
  animation: spin 0.75s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
#loading-text { font-size: 0.875rem; color: var(--ink-3); font-weight: 500; }

/* Info window overrides */
.gm-style .gm-style-iw-c { border-radius: var(--r-lg) !important; padding: 0 !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important; }
.gm-style .gm-style-iw-d { overflow: hidden !important; }
.gm-style .gm-style-iw-tc::after { background: #fff !important; }

.iw-body { padding: 1rem 1.1rem 0.85rem; min-width: 220px; max-width: 280px; font-family: var(--font-sans); }
.iw-ref { font-size: 0.72rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.2rem; font-family: var(--font-mono); }
.iw-name { font-size: 0.95rem; font-weight: 600; color: var(--ink); line-height: 1.3; margin-bottom: 0.6rem; }
.iw-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem 0.75rem; margin-bottom: 0.75rem; }
.iw-stat { font-size: 0.78rem; }
.iw-stat-val { font-weight: 600; color: var(--ink); }
.iw-stat-lbl { color: var(--ink-3); }
.iw-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.iw-btn {
  display: inline-flex; align-items: center; height: 28px; padding: 0 0.65rem;
  border-radius: var(--r-md); font-size: 0.775rem; font-weight: 500; text-decoration: none;
  transition: background 0.12s, color 0.12s;
}
.iw-btn-primary { background: var(--ink); color: #fff; }
.iw-btn-primary:hover { background: var(--ink-2); color: #fff; }
.iw-btn-ghost { background: var(--bg-2); color: var(--ink-2); border: 1px solid var(--border); }
.iw-btn-ghost:hover { background: var(--bg-3); color: var(--ink); }
.iw-trailhead { font-size: 0.72rem; color: var(--green); font-weight: 500; margin-top: 0.4rem; }
</style>
</head>
<body>
<div class="page-layout">

  <nav class="topbar">
    <a href="index.php" class="topbar-logo">
      <span class="logo-mark"><img src="sota-planner-logo.svg" width="32" height="32" alt=""></span>
      <span>SOTAplanner</span>
    </a>
    <div class="topbar-divider"></div>
    <span class="topbar-crumb">
      <a href="god_mode.php?tab=data">God Mode &rsaquo; Data Tools</a>
      &rsaquo; GPX Coverage Map
    </span>
    <div class="topbar-right">
      <a href="god_mode.php?tab=data" style="font-size:0.82rem; color:var(--ink-3); text-decoration:none; padding:0.3rem 0.6rem; border:1px solid var(--border); border-radius:var(--r-sm); background:var(--bg);">&larr; Back to Data Tools</a>
    </div>
  </nav>

  <div class="stat-bar">
    <div class="stat-item">
      <span class="stat-dot stat-dot-green"></span>
      <span class="stat-num"><?= number_format($total_routes) ?></span>
      <span class="stat-label">summits with community routes</span>
    </div>
    <div class="stat-item">
      <span class="stat-dot stat-dot-blue"></span>
      <span class="stat-num"><?= number_format($total_trail) ?></span>
      <span class="stat-label">have trailhead coordinates</span>
    </div>
    <div class="stat-item">
      <span class="stat-dot stat-dot-orange"></span>
      <span class="stat-num"><?= number_format($total_in_plans) ?></span>
      <span class="stat-label">nominated in a planning group</span>
    </div>
    <div class="stat-bar-right" id="zoom-hint">Zoom in to see individual summits &nbsp;&rsaquo;</div>
  </div>

  <div id="map-wrap">
    <div id="loading-overlay">
      <div class="spinner"></div>
      <div id="loading-text">Loading <?= number_format($total_routes) ?> summits&hellip;</div>
    </div>
    <div id="map"></div>
  </div>

</div>

<script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>
<script>
// All raw data — plain JS objects, no Marker objects created upfront
let allData = [];
let map, clusterer, infoWindow;
let activeMarkers = [];
let idleTimer = null;
const MAX_VISIBLE = 1500; // max markers rendered at once

function initMap() {
  map = new google.maps.Map(document.getElementById('map'), {
    zoom: 3,
    center: { lat: 30, lng: 10 },
    mapTypeId: 'terrain',
    mapTypeControl: true,
    mapTypeControlOptions: { position: google.maps.ControlPosition.TOP_RIGHT },
    streetViewControl: false,
    fullscreenControl: true,
    gestureHandling: 'greedy',
    styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }]
  });

  infoWindow = new google.maps.InfoWindow({ maxWidth: 300 });

  // Rebuild markers after every pan/zoom settles (idle fires once movement stops)
  map.addListener('idle', () => {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(rebuildVisible, 150);
  });

  loadData();
}

async function loadData() {
  try {
    const res  = await fetch('api_gpx_map_data.php');
    allData = await res.json();

    document.getElementById('loading-overlay').style.display = 'none';

    // Fit to data bounds once
    const bounds = new google.maps.LatLngBounds();
    for (const m of allData) bounds.extend({ lat: m.a, lng: m.o });
    if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 40 });
    // fitBounds triggers idle → rebuildVisible automatically

  } catch (e) {
    document.getElementById('loading-text').textContent = 'Failed to load data. Please refresh.';
    document.getElementById('loading-overlay').querySelector('.spinner').style.display = 'none';
  }
}

function rebuildVisible() {
  // Tear down previous markers and clusterer
  if (clusterer) { clusterer.clearMarkers(); clusterer = null; }
  for (const m of activeMarkers) m.setMap(null);
  activeMarkers = [];
  infoWindow.close();

  const zoom   = map.getZoom();
  const bounds = map.getBounds();
  if (!bounds || zoom < 4) {
    updateHint(zoom, 0);
    return;
  }

  // Expand bounds slightly so markers near edges don't pop in/out
  const ne = bounds.getNorthEast(), sw = bounds.getSouthWest();
  const latPad = (ne.lat() - sw.lat()) * 0.1;
  const lngPad = (ne.lng() - sw.lng()) * 0.1;

  const visible = allData.filter(m =>
    m.a >= sw.lat() - latPad && m.a <= ne.lat() + latPad &&
    m.o >= sw.lng() - lngPad && m.o <= ne.lng() + lngPad
  );

  const toRender = visible.slice(0, MAX_VISIBLE);

  for (const m of toRender) {
    const marker = new google.maps.Marker({
      position: { lat: m.a, lng: m.o },
      title: m.n || m.r,
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 5,
        fillColor: m.t ? '#1a6bb5' : '#2e7d52',
        fillOpacity: 0.85,
        strokeColor: '#fff',
        strokeWeight: 1.5,
      },
      optimized: true,
    });
    marker.addListener('click', () => showInfo(marker, m));
    activeMarkers.push(marker);
  }

  clusterer = new markerClusterer.MarkerClusterer({
    map,
    markers: activeMarkers,
    algorithm: new markerClusterer.SuperClusterAlgorithm({ radius: 55, maxZoom: 10 }),
  });

  updateHint(zoom, visible.length);
}

function updateHint(zoom, visibleCount) {
  const el = document.getElementById('zoom-hint');
  if (zoom < 4) {
    el.textContent = 'Zoom in to start exploring summits  ›';
  } else if (visibleCount > MAX_VISIBLE) {
    el.textContent = `Showing ${MAX_VISIBLE.toLocaleString()} of ${visibleCount.toLocaleString()} in view — zoom in for more`;
  } else if (zoom >= 8) {
    el.textContent = visibleCount > 0 ? `${visibleCount.toLocaleString()} summits in view — click any dot for details` : 'No summits with routes in this area';
  } else {
    el.textContent = 'Zoom in to see individual summits  ›';
  }
}

function showInfo(marker, m) {
  const sotaRef   = m.r;
  const name      = m.n || sotaRef;
  const sotlasUrl = `https://sotlas.com/summit/${encodeURIComponent(sotaRef)}`;

  let statsHtml = '';
  if (m.e) statsHtml += `<div class="iw-stat"><span class="iw-stat-val">${m.e.toLocaleString()} ft</span><br><span class="iw-stat-lbl">Elevation</span></div>`;
  if (m.d) statsHtml += `<div class="iw-stat"><span class="iw-stat-val">${m.d} mi</span><br><span class="iw-stat-lbl">Route dist.</span></div>`;
  if (m.g) statsHtml += `<div class="iw-stat"><span class="iw-stat-val">${m.g.toLocaleString()} ft</span><br><span class="iw-stat-lbl">Gain</span></div>`;

  let actionHtml = '';
  if (m.i && m.p) {
    actionHtml += `<a class="iw-btn iw-btn-primary" href="summit_detail.php?id=${m.i}&group=${m.p}" target="_blank">Summit Detail</a>`;
  }
  actionHtml += `<a class="iw-btn iw-btn-ghost" href="${sotlasUrl}" target="_blank">SOTLAS ↗</a>`;

  const trailHtml = m.t ? `<div class="iw-trailhead">✓ Trailhead coordinates available</div>` : '';

  infoWindow.setContent(`
    <div class="iw-body">
      <div class="iw-ref">${sotaRef}</div>
      <div class="iw-name">${escHtml(name)}</div>
      ${statsHtml ? `<div class="iw-stats">${statsHtml}</div>` : ''}
      <div class="iw-actions">${actionHtml}</div>
      ${trailHtml}
    </div>
  `);
  infoWindow.open(map, marker);
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($bkey) ?>&callback=initMap&loading=async" async defer></script>
</body>
</html>
