<?php
// Public, read-only summit page. Anyone with the link can open it (no SOTA login).
// Reached as /s/<slug> (see .htaccess) or summit_share.php?s=<slug>.
//
// Deliberately sterile: it shows the summit and its trail research, and nothing about the
// dashboard it came from (no owner, starting address, travel time, private notes, status,
// planned trips or history) and no edit controls. Signed-out visitors and signed-in users
// who do not have the summit yet get a big "Save this summit" button.
require_once 'config.php';
require_once 'share_lib.php';
require_once 'weather_lib.php';
session_start();

$db = getDbConnection();
$slug = strtolower(trim((string)($_GET['s'] ?? '')));
$summit = sharePublicSummit($db, $slug);

$callsign = getCurrentCallsign();
$user_units = $callsign ? getUserUnits($db) : null;
session_write_close();

$base_dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/';

if ($summit) $slug = $summit['slug']; // canonical link, even when the visitor used the designator only
if (!$summit) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars($base_dir) ?>"><title>Summit not found | SOTAplanner</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600&display=swap" rel="stylesheet">
    <style>body{font-family:'DM Sans',system-ui,sans-serif;background:#F7F6F3;color:#1C1B19;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:1rem}
    a{color:oklch(52% 0.13 50)}</style></head><body><div>
    <img src="sota-planner-logo.svg" width="64" height="64" alt="">
    <h1 style="font-size:1.3rem;margin:1rem 0 .5rem">We could not find that summit</h1>
    <p style="color:#4A4844">Check the SOTA reference in the link, for example w6-ct-012.</p>
    <p style="margin-top:1rem"><a href="login.php">Go to SOTAplanner</a></p></div></body></html><?php
    exit;
}

$summit_id = $summit['id'] ?: null; // a dashboard summit holding community research, if any (never shown)
$units = $user_units ?: 'imperial';

// Viewer's own copy of this summit, if they already have it on a dashboard
$viewer_existing = $callsign ? shareFindExistingForUser($db, $callsign, $summit['sota_ref']) : null;

// GPX track (the sharer's, or the community library) for stats and the map
$gpx_data = null;
if ($summit_id) {
    $stmt = $db->prepare("SELECT * FROM gpx_tracks WHERE summit_id = ? ORDER BY use_for_hike_time DESC, id DESC LIMIT 1");
    $stmt->execute([$summit_id]);
    $gpx_data = $stmt->fetch() ?: null;
}
$has_gpx_file = $gpx_data && is_file($gpx_data['file_path']);
if (!$has_gpx_file && empty($summit['gpx_opted_out']) && !empty($summit['sota_ref'])) {
    $gl = $db->prepare("SELECT file_path, total_distance, elevation_gain FROM global_gpx_tracks WHERE sota_ref = ?");
    $gl->execute([$summit['sota_ref']]);
    $glrow = $gl->fetch();
    $has_gpx_file = $glrow && is_file($glrow['file_path']);
    // No dashboard has researched this summit yet: use the library route's numbers, the same way
    // nominating a summit pre-fills distance and gain from it
    if ($has_gpx_file && !$gpx_data) {
        if (empty($summit['hike_distance_mi']) && $glrow['total_distance'] > 0) $summit['hike_distance_mi'] = round($glrow['total_distance'] * 2 * 0.621371, 2);
        if (empty($summit['hike_elevation_gain_ft']) && $glrow['elevation_gain'] > 0) $summit['hike_elevation_gain_ft'] = round($glrow['elevation_gain'] * 3.28084);
    }
}

// SOTA Maps community route details (public info): track title, contributor, and any name / notes inside the GPX file.
// Only shown for tracks that came from the community library, never for a sharer's own upload.
$maps_track = null;
if (!empty($summit['sota_ref']) && empty($summit['gpx_opted_out'])) {
    $from_lib = !$gpx_data || !empty($gpx_data['from_global_library']);
    if ($from_lib) {
        $gl = $db->prepare("SELECT file_path, source_callsign, source_track_title, sotamaps_track_count FROM global_gpx_tracks WHERE sota_ref = ?");
        $gl->execute([$summit['sota_ref']]);
        if ($row = $gl->fetch()) {
            $maps_track = ['title' => trim((string)$row['source_track_title']), 'by' => strtoupper(trim((string)$row['source_callsign'])), 'notes' => ''];
            if (is_file($row['file_path'])) {
                $head = (string)@file_get_contents($row['file_path'], false, null, 0, 30000);
                $found = [];
                foreach (['desc', 'cmt'] as $tag) {
                    if (preg_match_all('~<' . $tag . '>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</' . $tag . '>~is', $head, $mm)) {
                        foreach ($mm[1] as $t) { $t = trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8')); if ($t !== '' && !preg_match('~^Imported from SOTA Mapping Project~i', $t)) $found[$t] = true; }
                    }
                }
                if (!$maps_track['title'] && preg_match('~<name>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</name>~is', $head, $mm)) {
                    $maps_track['title'] = trim(html_entity_decode(strip_tags($mm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
                $maps_track['notes'] = mb_substr(implode("\n\n", array_keys($found)), 0, 1500);
            }
            if (!$maps_track['title'] && !$maps_track['by'] && !$maps_track['notes']) $maps_track = $maps_track + ['empty' => true];
        }
    }
}
$cell_labels = ['full' => 'Full coverage', 'intermittent' => 'Intermittent', 'summit_only' => 'Summit only', 'none' => 'No service'];
$cell_label = $cell_labels[$summit['cell_service'] ?? ''] ?? null;
$trail_link_ok = !empty($summit['trail_link']) && preg_match('~^https?://~i', $summit['trail_link']);

// Same distance / gain / hike time rules as summit_detail.php, minus anything user-specific
$track_type = $gpx_data['track_type'] ?? 'round-trip';
$one_way = ($track_type === 'ascent' || $track_type === 'descent');
$has_timestamps = $gpx_data && $gpx_data['hiking_time'] > 0;

// Track type label for the GPX card. Library routes have no stored type, so work it out once and remember it.
$track_type_label = null;
if ($has_gpx_file) {
    $tt = $gpx_data['track_type'] ?? null;
    if (!$tt && !empty($glrow['file_path'])) {
        $ck = 'share_ttype_' . preg_replace('/[^A-Za-z0-9]/', '_', $ref);
        $c = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $c->execute([$ck]);
        $tt = $c->fetchColumn() ?: null;
        if (!$tt) {
            try { $an = analyze_gpx_track($glrow['file_path'], null); $tt = $an['track_type'] ?? null; } catch (Throwable $e) { $tt = null; }
            if ($tt) $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$ck, $tt]);
        }
    }
    $track_type_label = ['ascent' => 'Ascent only', 'descent' => 'Descent only', 'round-trip' => 'Round-trip'][$tt ?? ''] ?? null;
}

if ($gpx_data && $gpx_data['use_for_elevation'] && ($gpx_data['elevation_gain'] ?? 0) > 0) {
    $gain_ft = ($track_type === 'descent' ? $gpx_data['elevation_loss'] : $gpx_data['elevation_gain']) * 3.28084;
} else {
    $gain_ft = $summit['hike_elevation_gain_ft'];
}
if ($gpx_data && $gpx_data['use_for_hike_time'] && $gpx_data['total_distance'] > 0) {
    $dist_km = $gpx_data['total_distance'] * ($one_way ? 2 : 1);
    $dist_mi = round($dist_km * 0.621371, 2);
} else {
    $dist_mi = $summit['hike_distance_mi'];
}
if ($gpx_data && $gpx_data['use_for_hike_time'] && $has_timestamps) {
    $secs = $gpx_data['hiking_time'] * ($one_way ? 2 : 1);
    $hike_min = (int)round($secs / 60);
} else {
    $hike_min = ($dist_mi || $gain_ft) ? (int)calculateHikeTime($dist_mi ?? 0, $gain_ft ?? 0, 1.0) : 0;
}

// Radio time: the signed-in viewer's own default, otherwise 60 minutes
$act_min = 60;
if ($callsign) {
    $us = $db->prepare("SELECT default_activation_time_min FROM user_settings WHERE user_callsign = ?");
    $us->execute([$callsign]);
    $act_min = (int)($us->fetchColumn() ?: 60);
}
$hike_up_min = (int)round($hike_min * 0.6);
$hike_down_min = $hike_min - $hike_up_min;
$travel_ph = max(1, (int)round(($hike_min + $act_min) * 0.22)); // placeholder width only

$ref = $summit['sota_ref'] ?? '';
$assoc_code = explode('/', $ref)[0];
$is_usa = (bool)preg_match('/^(W[0-9]|K[HL])/i', $assoc_code);
$lat = (float)$summit['latitude'];
$lng = (float)$summit['longitude'];
$th_lat = !empty($summit['trailhead_lat']) ? (float)$summit['trailhead_lat'] : null;
$th_lng = !empty($summit['trailhead_lng']) ? (float)$summit['trailhead_lng'] : null;
$dir_lat = $th_lat ?? $lat;
$dir_lng = $th_lng ?? $lng;
$elev_m = (int)round(($summit['elevation_ft'] ?? 0) * 0.3048);
$total_acts = $summit['activation_count'] ?? null;

$share_url = shareUrl($slug);
$og_desc = trim(($summit['region'] ? $summit['region'] . '. ' : '') . ($summit['points'] ? $summit['points'] . ' SOTA points. ' : '') . 'Trail research, map, weather and route on SOTAplanner.');
$jsflags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <base href="<?= htmlspecialchars($base_dir) ?>">
  <title><?= htmlspecialchars($summit['name']) ?> (<?= htmlspecialchars($ref) ?>) | SOTAplanner</title>
  <meta property="og:title" content="<?= htmlspecialchars($summit['name'] . ' (' . $ref . ')') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($og_desc) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= htmlspecialchars($share_url) ?>">
  <link rel="icon" href="sota-planner-logo.svg" type="image/svg+xml">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    :root {
      --bg: #F7F6F3; --bg-2: #EFEDE8; --bg-3: #E5E2DA;
      --ink: #1C1B19; --ink-2: #4A4844; --ink-3: #8C8A86; --ink-4: #B8B5B0;
      --accent: oklch(52% 0.13 50); --accent-2: oklch(44% 0.13 50);
      --accent-bg: oklch(96% 0.04 65); --accent-border: oklch(84% 0.08 65);
      --green: #2D8653; --green-bg: #EBF5EF; --orange: #C07020; --orange-bg: #FDF3E7;
      --red: #C03030; --red-bg: #FBE9E9; --blue: #2B5CA0; --blue-bg: #E8EFF9;
      --surface: #FFFFFF; --border: #E5E2DA; --border-2: #D4D0C8;
      --font-sans: 'DM Sans', system-ui, sans-serif; --font-mono: 'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
      --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }
    body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }
    p { line-height: 1.65; color: var(--ink-2); }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }

    .topbar { background: var(--surface); border-bottom: 1px solid var(--border); height: 56px; display: flex; align-items: center; padding: 0 2rem; gap: 1rem; position: sticky; top: 0; z-index: 1001; }
    .topbar-logo { display: flex; align-items: center; gap: 0.75rem; color: var(--ink); font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; }
    .topbar-logo:hover { text-decoration: none; }
    .topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; }
    .page { padding: 2rem; max-width: 1100px; margin: 0 auto; }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0 1rem; height: 36px; border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; transition: background 0.15s, box-shadow 0.15s, transform 0.1s; white-space: nowrap; line-height: 1; }
    .btn:hover { text-decoration: none; }
    .btn:active { transform: scale(0.98); }
    .btn-primary { background: var(--ink); color: #fff; }
    .btn-primary:hover { background: var(--ink-2); color: #fff; }
    .btn-secondary { background: var(--bg-2); color: var(--ink); border: 1px solid var(--border); }
    .btn-secondary:hover { background: var(--bg-3); color: var(--ink); }
    .btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--border); }
    .btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
    .btn-sm { height: 30px; padding: 0 0.75rem; font-size: 0.8rem; }
    .btn-map-active { background: #6B6865; color: #fff; }

    .user-chip { position: relative; display: flex; align-items: center; gap: 0.35rem; cursor: pointer; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: var(--ink-2); border: 1px solid var(--border); background: var(--bg); user-select: none; white-space: nowrap; }
    .user-chip:hover { background: var(--bg-2); }
    .user-dropdown { display: none; position: absolute; top: calc(100% + 6px); right: 0; background: #fff; border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); min-width: 150px; overflow: hidden; z-index: 200; }
    .user-chip.open .user-dropdown { display: block; }
    .user-dropdown a { display: block; padding: 0.6rem 1rem; font-size: 0.82rem; font-weight: 500; color: var(--ink-2); }
    .user-dropdown a:hover { background: var(--bg-2); color: var(--ink); text-decoration: none; }

    .save-banner { background: var(--accent-bg); border: 1px solid var(--accent-border); border-radius: var(--r-lg); padding: 0.875rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem 1.5rem; flex-wrap: wrap; }
    .save-banner-text { min-width: 0; }
    .save-banner-title { font-size: 1rem; font-weight: 600; letter-spacing: -0.01em; }
    .save-banner-sub { font-size: 0.85rem; color: var(--ink-2); margin-top: 0.1rem; }
    .save-banner-sub strong { color: var(--ink); font-weight: 700; }
    .save-banner-sub p { margin: 0; }
    .save-banner .btn-primary { flex-shrink: 0; }
    .time-bar { height: 44px; border-radius: var(--r-md); overflow: hidden; display: flex; gap: 2px; margin-bottom: 6px; }
    .time-bar-seg { display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; min-width: 0; padding: 0 6px; }
    .time-bar-label { color: rgba(255,255,255,0.95); font-size: 0.7rem; font-weight: 600; white-space: nowrap; line-height: 1.25; }
    .time-bar-time { color: rgba(255,255,255,0.8); font-size: 0.65rem; white-space: nowrap; line-height: 1.25; }
    .time-bar-seg.ph { background: var(--bg-2); border: 1px dashed var(--border-2); }
    .time-bar-seg.ph .time-bar-label, .time-bar-seg.ph .time-bar-time { color: var(--ink-4); }
    .mini-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    .mini-table th { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-3); padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--border); background: var(--bg-2); }
    .mini-table td { padding: 0.625rem 0.75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .mini-table tr:last-child td { border-bottom: none; }

    .badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 100px; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap; }
    .badge-easy { background: var(--green-bg); color: var(--green); }
    .badge-moderate { background: var(--orange-bg); color: var(--orange); }
    .badge-hard { background: var(--red-bg); color: var(--red); }
    .badge-drive-up { background: var(--blue-bg); color: var(--blue); }
    .sota-ref-designator { position: relative; font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-3); padding: 2px 5px; margin: -2px -5px; border-radius: var(--r-sm); cursor: pointer; transition: background 0.15s, color 0.15s; }
    .sota-ref-designator:hover { background: var(--bg-2); color: var(--ink); }
    .sota-ref-designator .copied-tip { position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%) translateY(4px); background: var(--ink); color: #fff; font-family: var(--font-sans); font-size: 0.7rem; font-weight: 600; padding: 0.25rem 0.55rem; border-radius: var(--r-sm); white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity 0.15s, transform 0.15s; }
    .sota-ref-designator .copied-tip.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .ref-chip { font-family: var(--font-mono); font-size: 0.78rem; font-weight: 500; background: var(--bg-2); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 1px 7px; color: var(--ink-2); }

    .page-title { font-size: 1.6rem; font-weight: 600; letter-spacing: -0.02em; }
    .page-subtitle { font-size: 0.9rem; color: var(--ink-3); margin-top: 0.25rem; }
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); }
    .card-title { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-3); margin-bottom: 0.875rem; }
    .layout { display: grid; grid-template-columns: 1fr 300px; gap: 1.5rem; align-items: start; }
    .stat-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--r-lg); overflow: hidden; margin-bottom: 1.25rem; }
    .stat-cell { background: var(--surface); padding: 1rem 1.25rem; }
    .stat-cell-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-3); margin-bottom: 5px; }
    .stat-cell-val { font-size: 1.1rem; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.2; }
    .stat-cell-sub { font-size: 0.72rem; color: var(--ink-3); margin-top: 3px; }

    .map-wrap { position: relative; z-index: 0; }
    .btn-map-expand { position: absolute; top: 10px; right: 10px; z-index: 400; background: #fff; border: 2px solid rgba(0,0,0,0.2); border-radius: 4px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; color: #333; box-shadow: 0 1px 5px rgba(0,0,0,0.4); transition: background 0.15s; }
    .btn-map-expand:hover { background: #f4f4f4; }
    .map-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.62); z-index: 88; }
    .map-backdrop.active { display: block; }
    .map-wrap.map-expanded { position: fixed; top: calc(56px + 0.75rem); left: 1rem; right: 1rem; bottom: 1rem; z-index: 90; border-radius: var(--r-lg); box-shadow: 0 24px 80px rgba(0,0,0,0.4); background: var(--surface); display: flex; flex-direction: column; overflow: hidden; }
    .map-wrap.map-expanded #summit-map { height: 0 !important; flex: 1; margin-bottom: 0; border-radius: var(--r-lg) var(--r-lg) 0 0; border: none; }
    .map-wrap.map-expanded .map-buttons { padding: 0.5rem 0.75rem; margin-bottom: 0; flex-shrink: 0; border-top: 1px solid var(--border); }
    #summit-map { height: 340px; border-radius: var(--r-lg); border: 1px solid var(--border); overflow: hidden; margin-bottom: 0.75rem; }
    .map-buttons { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; align-items: center; }
    .map-divider { width: 1px; height: 20px; background: var(--border); }
    .carrier-btn { height: 28px; padding: 0 0.625rem; font-size: 0.75rem; font-weight: 600; border-radius: var(--r-sm); border: 1.5px solid; cursor: pointer; font-family: var(--font-sans); }
    .elev-wrap { background: var(--bg-2); border: 1px solid var(--border); border-radius: var(--r-md); overflow: hidden; margin-bottom: 0.75rem; }
    #elev-canvas { display: block; width: 100%; height: 120px; cursor: crosshair; }
    .elev-note { font-size: 0.75rem; color: var(--ink-3); padding: 0.35rem 0.75rem; }

    .info-row { display: flex; justify-content: space-between; align-items: baseline; padding: 0.5rem 0; border-bottom: 1px solid var(--border); gap: 0.75rem; }
    .info-row:last-child { border-bottom: none; padding-bottom: 0; }
    .info-label { font-size: 0.8rem; color: var(--ink-3); flex-shrink: 0; }
    .info-val { font-size: 0.8rem; font-weight: 500; text-align: right; }
    .bonus-badge { font-size: 0.65rem; font-weight: 700; color: var(--orange); margin-left: 3px; vertical-align: super; line-height: 1; cursor: help; }

    .cn-box summary { list-style: none; display: flex; align-items: center; justify-content: space-between; gap: 1rem; cursor: pointer; }
    .cn-box summary::-webkit-details-marker { display: none; }
    .cn-title { font-size: 1rem; font-weight: 600; }
    .cn-res { padding: 0.65rem 0; border-bottom: 1px solid var(--border); }
    .cn-res:last-of-type { border-bottom: none; }
    .cn-res summary { justify-content: flex-start; align-items: baseline; gap: 0.5rem; }
    .cn-res-title { font-size: 0.9rem; font-weight: 600; color: var(--accent-2); }
    .cn-res-meta { font-size: 0.72rem; color: var(--ink-3); min-width: 11rem; flex-shrink: 0; white-space: nowrap; }
    .cn-res-line { display: flex; align-items: baseline; gap: 0.5rem; }
    .cn-res-body { font-size: 0.85rem; line-height: 1.55; margin: 0.5rem 0 0 1.3rem; }
    .cn-res-body p { margin-bottom: 0.5rem; color: var(--ink); }
    @media (max-width:600px) { .cn-res-meta { min-width: 0; } .cn-res summary, .cn-res-line { flex-wrap: wrap; } }

    .footer { text-align: center; padding: 2rem 1rem 1.5rem; color: var(--ink-4); font-size: 0.78rem; border-top: 1px solid var(--border); margin-top: 3rem; }
    .footer a { color: var(--ink-3); }

    @media (max-width: 768px) {
      .page { padding: 1rem; }
      .topbar { padding: 0 1rem; }
      .layout { grid-template-columns: 1fr; }
      .stat-grid { grid-template-columns: 1fr 1fr; }
      .stat-grid .stat-cell:last-child { grid-column: 1 / -1; }
      .save-banner .btn-primary { width: 100%; }
    }
  </style>
</head>
<body>

<nav class="topbar">
  <a href="login.php" class="topbar-logo">
    <img src="sota-planner-logo.svg" width="32" height="32" alt="">
    <span>SOTAplanner</span>
  </a>
  <div class="topbar-right">
    <?php if ($callsign): ?>
      <div class="user-chip" id="userChip" onclick="this.classList.toggle('open')">
        <?= htmlspecialchars($callsign) ?>
        <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="2,3.5 5,6.5 8,3.5"/></svg>
        <div class="user-dropdown">
          <a href="index.php">My Dashboard</a>
          <a href="logout.php">Sign Out</a>
        </div>
      </div>
    <?php else: ?>
      <a href="share_login.php?s=<?= urlencode($slug) ?>" class="btn btn-ghost btn-sm">Login</a>
    <?php endif; ?>
  </div>
</nav>

<div class="page">

  <?php if ($viewer_existing): ?>
    <div class="save-banner">
      <div class="save-banner-text">
        <div class="save-banner-title">You already have this summit</div>
        <div class="save-banner-sub">It is already on one of your dashboards.</div>
      </div>
      <a class="btn btn-primary" href="summit_detail.php?id=<?= (int)$viewer_existing['id'] ?>&group=<?= (int)$viewer_existing['planning_group_id'] ?>">Open it on my dashboard</a>
    </div>
  <?php else: ?>
    <div class="save-banner">
      <div class="save-banner-text">
        <div class="save-banner-title">Keep this summit for your own planning</div>
        <div class="save-banner-sub"><strong>No signup required.</strong> Just sign in with your <strong>SOTA login</strong> (SOTA SSO). Trail research, route and map come along.</div>
      </div>
      <a class="btn btn-primary" href="share_save.php?s=<?= urlencode($slug) ?>">Save this summit</a>
    </div>
  <?php endif; ?>

  <div class="page-header">
    <div>
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
        <?php if ($summit['difficulty']): ?>
          <span class="badge badge-<?= htmlspecialchars($summit['difficulty']) ?>"><?= htmlspecialchars(ucfirst($summit['difficulty'])) ?></span>
        <?php endif; ?>
        <?php if ($ref): ?>
          <span class="sota-ref-designator" title="Click to copy" onclick="copySotaRef(this, <?= htmlspecialchars(json_encode($ref), ENT_QUOTES) ?>)">
            <?= htmlspecialchars($ref) ?>
            <span class="copied-tip">Copied!</span>
          </span>
        <?php endif; ?>
      </div>
      <h1 class="page-title"><?= htmlspecialchars($summit['name']) ?></h1>
      <div class="page-subtitle">
        <?php
          $sub = [];
          if (!empty($summit['region'])) $sub[] = htmlspecialchars($summit['region']);
          if ($summit['points']) $sub[] = (int)$summit['points'] . ' pts';
          if ($total_acts !== null && $total_acts !== '') $sub[] = (int)$total_acts . ((int)$total_acts === 1 ? ' activation' : ' activations');
          echo implode(' &middot; ', $sub);
        ?>
      </div>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
      <?php if ($ref): ?>
        <a href="https://sotl.as/summits/<?= str_replace('%2F', '/', urlencode($ref)) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">SOTLAS &#8599;</a>
      <?php endif; ?>
      <?php if (!empty($summit['trail_link']) && preg_match('~^https?://~i', $summit['trail_link'])): ?>
        <a href="<?= htmlspecialchars($summit['trail_link']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Trail &#8599;</a>
      <?php endif; ?>
      <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $dir_lat ?>,<?= $dir_lng ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Directions &#8599;</a>
    </div>
  </div>

  <div class="layout">
    <div>
      <div class="stat-grid">
        <div class="stat-cell">
          <div class="stat-cell-label">Hike (RT)</div>
          <?php if ($hike_min): ?>
            <div class="stat-cell-val"><?= floor($hike_min / 60) ?>h <?= $hike_min % 60 ?>m</div>
            <div class="stat-cell-sub"><?= ($gpx_data && $has_timestamps && $gpx_data['use_for_hike_time']) ? 'From a GPS track' : 'Estimated' ?></div>
          <?php else: ?>
            <div class="stat-cell-val" style="color:var(--ink-3); font-size:0.875rem;">&mdash;</div>
            <div class="stat-cell-sub">No data yet</div>
          <?php endif; ?>
        </div>
        <div class="stat-cell">
          <div class="stat-cell-label">Distance (RT)</div>
          <?php if ($dist_mi): ?>
            <div class="stat-cell-val"><?= number_format(convertDistance($dist_mi, $units), 1) ?> <?= getDistanceUnit($units) ?></div>
          <?php else: ?>
            <div class="stat-cell-val" style="color:var(--ink-3); font-size:0.875rem;">&mdash;</div>
          <?php endif; ?>
        </div>
        <div class="stat-cell">
          <div class="stat-cell-label">Elevation Gain</div>
          <?php if ($gain_ft): ?>
            <div class="stat-cell-val"><?= number_format(convertElevation($gain_ft, $units)) ?> <?= getElevationUnit($units) ?></div>
          <?php else: ?>
            <div class="stat-cell-val" style="color:var(--ink-3); font-size:0.875rem;">&mdash;</div>
          <?php endif; ?>
        </div>
        <div class="stat-cell">
          <div class="stat-cell-label" style="display:flex;align-items:center;gap:0.3rem;">
            Summit Time
            <button type="button" onclick="startActTimeEdit()" title="Edit" style="background:none;border:none;padding:0;cursor:pointer;color:var(--ink-4);line-height:1;flex-shrink:0;opacity:0.5;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.5'">
              <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M8.5 1.5a1.207 1.207 0 0 1 1.707 1.707L3.5 9.914 1 10.5l.586-2.5L8.5 1.5z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
          <div class="stat-cell-val" id="act-time-display"><?= (int)$act_min ?>m</div>
          <div class="stat-cell-sub" id="act-time-sub">planned on the summit</div>
          <div id="act-time-edit" style="display:none;margin-top:0.25rem;">
            <input type="number" id="act-time-input" min="15" max="300" step="15" value="<?= (int)$act_min ?>"
                   style="width:70px;padding:0.2rem 0.35rem;border:1px solid var(--accent);border-radius:var(--r-sm);font-size:0.875rem;font-family:var(--font-sans);"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();saveActTime();}if(event.key==='Escape')cancelActTimeEdit();">
            <button type="button" onclick="saveActTime()" style="margin-left:0.25rem;background:var(--ink);color:#fff;border:none;border-radius:var(--r-sm);padding:0.2rem 0.5rem;font-size:0.75rem;cursor:pointer;">OK</button>
          </div>
        </div>
        <div class="stat-cell">
          <div class="stat-cell-label">SOTA Points</div>
          <div class="stat-cell-val"><?= (int)$summit['points'] ?> pts
            <?php if (!empty($summit['bonus_points']) && isWinterBonusSeasonActive($summit['latitude'])): ?>
              <span class="bonus-badge" title="<?= htmlspecialchars(winterBonusSeasonText($summit['bonus_points'], $summit['latitude'])) ?>">+<?= (int)$summit['bonus_points'] ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($hike_min): ?>
      <div style="margin-bottom:1.25rem;">
        <div class="time-bar">
          <div class="time-bar-seg ph js-travel-ph" style="flex:<?= $travel_ph ?>;" title="Travel time is calculated from your own starting address">
            <span class="time-bar-label">Travel</span>
            <span class="time-bar-time">Save for drive times</span>
          </div>
          <div class="time-bar-seg" style="flex:<?= max(1, $hike_up_min) ?>; background:#6E8155;">
            <span class="time-bar-label">Hike Up</span>
            <span class="time-bar-time"><?= formatTime($hike_up_min) ?></span>
          </div>
          <div class="time-bar-seg" id="barRadio" style="flex:<?= $act_min ?>; background:#C07840;">
            <span class="time-bar-label">Radio</span>
            <span class="time-bar-time" id="barRadioTime"><?= formatTime($act_min) ?></span>
          </div>
          <div class="time-bar-seg" style="flex:<?= max(1, $hike_down_min) ?>; background:#6E8155;">
            <span class="time-bar-label">Hike Down</span>
            <span class="time-bar-time"><?= formatTime($hike_down_min) ?></span>
          </div>
          <div class="time-bar-seg ph js-travel-ph" style="flex:<?= $travel_ph ?>;" title="Travel time is calculated from your own starting address">
            <span class="time-bar-label">Return</span>
            <span class="time-bar-time">Save for drive times</span>
          </div>
        </div>
        <div style="font-size:0.75rem; color:var(--ink-3);">
          Hike and radio: <strong style="color:var(--ink);" id="barTotal"><?= formatTime($hike_min + $act_min) ?></strong>, not including travel
        </div>
      </div>
      <?php endif; ?>

      <div class="map-wrap" id="map-wrap">
        <div id="summit-map"></div>
        <div class="map-buttons">
          <button type="button" id="btn-base-street" class="btn btn-sm btn-map-active" onclick="switchBase('street')">Street</button>
          <button type="button" id="btn-base-topo" class="btn btn-sm btn-secondary" onclick="switchBase('topo')">Topo</button>
          <button type="button" id="btn-base-satellite" class="btn btn-sm btn-secondary" onclick="switchBase('satellite')">Satellite</button>
          <?php if ($is_usa): ?>
            <span class="map-divider"></span>
            <button type="button" id="btn-tmobile" class="carrier-btn" style="border-color:#E91E8C; color:#E91E8C; background:#fff;" onclick="toggleCarrier('tmobile')">T-Mo</button>
            <button type="button" id="btn-verizon" class="carrier-btn" style="border-color:#CD040B; color:#CD040B; background:#fff;" onclick="toggleCarrier('verizon')">VZW</button>
            <button type="button" id="btn-att" class="carrier-btn" style="border-color:#00A8E0; color:#00A8E0; background:#fff;" onclick="toggleCarrier('att')">AT&amp;T</button>
          <?php endif; ?>
          <?php if ($ref): ?>
            <span class="map-divider"></span>
            <button type="button" id="btn-actzone" class="btn btn-sm btn-secondary" onclick="toggleActivationZone()" disabled style="opacity:0.4;">Activation Zone</button>
          <?php endif; ?>
          <span class="map-divider"></span>
          <a href="https://www.google.com/maps/search/?api=1&query=<?= $lat ?>,<?= $lng ?>" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">Maps &#8599;</a>
        </div>
      </div>
      <div id="map-backdrop" class="map-backdrop" onclick="toggleMapExpand()"></div>

      <?php if ($has_gpx_file): ?>
      <div class="elev-wrap">
        <canvas id="elev-canvas"></canvas>
        <div class="elev-note" id="elev-note">Hover for elevation details</div>
      </div>
      <?php endif; ?>
      <div id="az-methodology" style="font-size:0.75rem; color:var(--ink-3); margin-bottom:1rem; line-height:1.5;"></div>
    </div>

    <div>
      <div class="card" id="wxRow" style="display:none; margin-bottom:1rem; flex-direction:column; gap:0.2rem;">
        <div class="card-title" style="margin-bottom:0.5rem;">Weather at Summit &middot; <?= number_format(convertElevation($summit['elevation_ft'] ?? 0, $units)) . ' ' . getElevationUnit($units) ?></div>
        <div id="wxNow" style="font-size:0.95rem; font-weight:500; text-align:center;"></div>
        <div id="wxWind" style="font-size:0.72rem; color:var(--ink-3); text-align:center;"></div>
        <div id="wxDays" style="display:grid; grid-auto-flow:column; grid-auto-columns:1fr; gap:0.4rem; margin-top:0.6rem;"></div>
        <a id="wxMore" href="#" target="_blank" rel="noopener" style="font-size:0.74rem; font-weight:500; text-align:center; margin-top:0.4rem;">Full forecast and hourly detail &rarr;</a>
        <div style="font-size:0.55rem; color:var(--ink-4); text-align:right; margin-top:0.15rem;"><?= weather_credit_html() ?></div>
      </div>

      <div class="card" style="margin-bottom:1rem;">
        <div class="card-title">Summit Info</div>
        <div class="info-row"><span class="info-label">Elevation</span>
          <span class="info-val"><?= number_format(convertElevation($summit['elevation_ft'], $units)) ?> <?= getElevationUnit($units) ?></span></div>
        <div class="info-row"><span class="info-label">Coordinates</span>
          <span class="info-val" style="font-family:var(--font-mono); font-size:0.72rem;"><?= number_format(abs($lat), 4) ?>&deg;<?= $lat >= 0 ? 'N' : 'S' ?>, <?= number_format(abs($lng), 4) ?>&deg;<?= $lng >= 0 ? 'E' : 'W' ?></span></div>
        <?php if ($th_lat !== null): ?>
        <div class="info-row"><span class="info-label">Starting point</span>
          <span class="info-val"><a href="https://www.google.com/maps/search/?api=1&query=<?= $th_lat ?>,<?= $th_lng ?>" target="_blank" rel="noopener">Open in Maps &#8599;</a></span></div>
        <?php endif; ?>
        <?php if (!empty($summit['difficulty'])): ?>
        <div class="info-row"><span class="info-label">Difficulty</span>
          <span class="info-val"><?= htmlspecialchars(ucfirst($summit['difficulty'])) ?></span></div>
        <?php endif; ?>
        <div class="info-row"><span class="info-label">Last activated</span>
          <span class="info-val" id="lastActivated" style="color:var(--ink-4);">Loading...</span></div>
        <div class="info-row"><span class="info-label">Cell service</span>
          <span class="info-val"><?= $cell_label ? htmlspecialchars($cell_label) : '<span style="color:var(--ink-4);">Unknown</span>' ?></span></div>
        <?php if ($trail_link_ok): ?>
        <div class="info-row"><span class="info-label">Trail reference</span>
          <span class="info-val"><a href="<?= htmlspecialchars($summit['trail_link']) ?>" target="_blank" rel="noopener nofollow">Open link &#8599;</a></span></div>
        <?php endif; ?>
      </div>

      <?php if ($has_gpx_file): ?>
      <div class="card" style="margin-bottom:1rem;">
        <div class="card-title">GPX Track</div>
        <?php if ($track_type_label): ?>
        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.75rem;">
          <span style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--ink-3);">Track type</span>
          <span style="font-size:0.78rem; font-weight:600; color:var(--ink-2); background:var(--bg-2); border:1px solid var(--border); border-radius:var(--r-sm); padding:0.1rem 0.5rem;"><?= htmlspecialchars($track_type_label) ?></span>
        </div>
        <?php endif; ?>
        <a href="summit_share_data.php?s=<?= urlencode($slug) ?>&what=gpx&download=1" class="btn btn-ghost btn-sm">Download GPX</a>
      </div>
      <?php endif; ?>

      <?php if ($maps_track && empty($maps_track['empty'])): ?>
      <div class="card" style="margin-bottom:1rem;">
        <div class="card-title">Community Route</div>
        <div style="font-size:0.78rem; color:var(--blue); font-weight:500; margin-bottom:0.5rem;">
          From the <a href="https://sotamaps.org" target="_blank" rel="noopener" style="color:var(--blue);">SOTA Mapping Project</a>
        </div>
        <?php if ($maps_track['title']): ?>
          <div style="font-size:0.9rem; font-weight:600; line-height:1.35;"><?= htmlspecialchars($maps_track['title']) ?></div>
        <?php endif; ?>
        <?php if ($maps_track['by']): ?>
          <div style="font-size:0.78rem; color:var(--ink-3); margin-top:0.15rem;">Submitted by <span style="font-family:var(--font-mono); font-weight:600; color:var(--ink-2);"><?= htmlspecialchars($maps_track['by']) ?></span></div>
        <?php endif; ?>
        <?php if ($maps_track['notes']): ?>
          <div style="font-size:0.82rem; color:var(--ink-2); line-height:1.55; margin-top:0.6rem; padding-top:0.6rem; border-top:1px solid var(--border);"><?= nl2br(htmlspecialchars($maps_track['notes'])) ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($ref): ?>
  <div class="card" style="margin-top:1.5rem;" id="sotaAlertsCard">
    <div class="card-title">Upcoming Activations on SOTAwatch</div>
    <div id="sotaAlertsBody"><p style="color:var(--ink-3); font-size:0.875rem;">Loading...</p></div>
  </div>
  <div class="card" style="margin-top:1rem;">
    <details class="cn-box">
      <summary>
        <span class="cn-title">Community Notes <span style="font-size:0.7rem; font-weight:500; color:var(--ink-3); margin-left:0.4rem;">Articles and links from other users</span></span>
        <span id="cnCount" style="font-size:0.78rem; color:var(--ink-3);"></span>
      </summary>
      <div style="margin-top:1.25rem;">
        <div id="sotaResourcesBody"><p style="color:var(--ink-3); font-size:0.875rem;">Loading articles and links...</p></div>
      </div>
    </details>
  </div>
  <?php endif; ?>

  <?php if (!$viewer_existing): ?>
  <div style="text-align:center; margin-top:1.5rem;">
    <a class="btn btn-primary" href="share_save.php?s=<?= urlencode($slug) ?>">Save this summit</a>
    <div style="margin-top:0.5rem; font-size:0.82rem; color:var(--ink-2);"><strong>No signup required.</strong> Uses your SOTA login (SOTA SSO).</div>
  </div>
  <?php endif; ?>
</div>

<div class="footer">
  Shared from <a href="login.php">SOTAplanner</a>, free summit planning for SOTA activators
</div>

<script>
document.addEventListener('click', function (e) {
  var chip = document.getElementById('userChip');
  if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});

const SLUG = <?= json_encode($slug, $jsflags) ?>;
const sumLat = <?= json_encode($lat) ?>, sumLng = <?= json_encode($lng) ?>;
const trailLat = <?= json_encode($th_lat) ?>, trailLng = <?= json_encode($th_lng) ?>;
const SUMMIT_NAME = <?= json_encode($summit['name'], $jsflags) ?>;
const SUMMIT_REF = <?= json_encode($ref, $jsflags) ?>;
const HAS_GPX = <?= $has_gpx_file ? 'true' : 'false' ?>;
// ── Summit time editor (pencil on the Summit Time tile) ─────────────────────
const HIKE_MIN = <?= (int)$hike_min ?>;
const SIGNED_IN = <?= $callsign ? 'true' : 'false' ?>;
function fmtMin(m) { var h = Math.floor(m / 60), r = m % 60; return h ? (r ? h + 'h ' + r + 'm' : h + 'h') : r + 'm'; }
function applyActTime(minutes) {
  document.getElementById('act-time-display').textContent = minutes + 'm';
  document.getElementById('act-time-input').value = minutes;
  var radio = document.getElementById('barRadio');
  if (radio) {
    radio.style.flex = minutes;
    document.getElementById('barRadioTime').textContent = fmtMin(minutes);
    document.getElementById('barTotal').textContent = fmtMin(HIKE_MIN + minutes);
    var ph = Math.max(1, Math.round((HIKE_MIN + minutes) * 0.22));
    document.querySelectorAll('.js-travel-ph').forEach(function (e) { e.style.flex = ph; });
  }
}
function startActTimeEdit() {
  document.getElementById('act-time-display').style.display = 'none';
  document.getElementById('act-time-sub').style.display = 'none';
  document.getElementById('act-time-edit').style.display = 'block';
  document.getElementById('act-time-input').focus();
  document.getElementById('act-time-input').select();
}
function cancelActTimeEdit() {
  document.getElementById('act-time-display').style.display = '';
  document.getElementById('act-time-sub').style.display = '';
  document.getElementById('act-time-edit').style.display = 'none';
}
function saveActTime() {
  var minutes = parseInt(document.getElementById('act-time-input').value, 10);
  if (isNaN(minutes) || minutes < 15 || minutes > 300) return;
  applyActTime(minutes);
  cancelActTimeEdit();
  if (SIGNED_IN) {
    // Same saved preference the dashboard summit pages use
    fetch('save_activation_time.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'minutes=' + encodeURIComponent(minutes), credentials: 'same-origin' });
  } else {
    try { localStorage.setItem('sota_share_act_min', String(minutes)); } catch (e) {}
  }
}
if (!SIGNED_IN) {
  try {
    var saved = parseInt(localStorage.getItem('sota_share_act_min'), 10);
    if (saved >= 15 && saved <= 300) applyActTime(saved);
  } catch (e) {}
}

function copySotaRef(el, ref) {
  navigator.clipboard.writeText(ref).then(function () {
    const tip = el.querySelector('.copied-tip');
    if (!tip) return;
    tip.classList.add('show');
    clearTimeout(el._copyTipTimer);
    el._copyTipTimer = setTimeout(function () { tip.classList.remove('show'); }, 1200);
  });
}
function feed(what) { return 'summit_share_data.php?s=' + encodeURIComponent(SLUG) + '&what=' + what; }

// ── Map ─────────────────────────────────────────────────────────────────────
const map = L.map('summit-map').setView([sumLat, sumLng], 13);
const baseLayers = {
  street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>', maxZoom: 19 }),
  topo: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', { attribution: '© <a href="https://opentopomap.org">OpenTopoMap</a>', maxZoom: 17 }),
  satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '© Esri', maxZoom: 19 })
};
let activeBase = 'street', gpxPolyline = null, activationZoneLayer = null;
baseLayers.street.addTo(map);

// Expand button (a Leaflet control so it sits above the tiles) and full-screen map toggle
const EXPAND_ICON = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 5V1h4M9 1h4v4M13 9v4H9M5 13H1V9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const COLLAPSE_ICON = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 1v4H1M13 5V1H9M9 13v-4h4M1 9v4h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const ExpandControl = L.Control.extend({
  onAdd: function () {
    const btn = L.DomUtil.create('button', 'btn-map-expand');
    btn.id = 'btn-map-expand'; btn.type = 'button'; btn.title = 'Expand map'; btn.innerHTML = EXPAND_ICON;
    L.DomEvent.on(btn, 'click', L.DomEvent.stopPropagation);
    L.DomEvent.on(btn, 'click', function () { toggleMapExpand(); });
    return btn;
  }
});
new ExpandControl({ position: 'topright' }).addTo(map);
let _mapWrapParent = null, _mapWrapNextSib = null;
function toggleMapExpand() {
  const wrap = document.getElementById('map-wrap'), backdrop = document.getElementById('map-backdrop'), btn = document.getElementById('btn-map-expand');
  const expanded = wrap.classList.toggle('map-expanded');
  if (expanded) {
    _mapWrapParent = wrap.parentNode; _mapWrapNextSib = wrap.nextSibling;
    document.body.appendChild(wrap);
  } else if (_mapWrapParent) {
    _mapWrapParent.insertBefore(wrap, _mapWrapNextSib);
  }
  backdrop.classList.toggle('active', expanded);
  if (btn) { btn.innerHTML = expanded ? COLLAPSE_ICON : EXPAND_ICON; btn.title = expanded ? 'Collapse map' : 'Expand map'; }
  setTimeout(() => map.invalidateSize(), 60);
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && document.getElementById('map-wrap').classList.contains('map-expanded')) toggleMapExpand();
});

// Activation Zone button: zoom to the zone, press again to return to the full route
let azZoomed = false;
function toggleActivationZone() {
  const btn = document.getElementById('btn-actzone');
  if (!azZoomed) {
    if (!activationZoneLayer) return;
    map.fitBounds(activationZoneLayer.getBounds(), { padding: [40, 40] });
    azZoomed = true; btn.textContent = gpxPolyline ? 'Full GPX Track' : 'Reset View';
  } else {
    if (gpxPolyline) map.fitBounds(gpxPolyline.getBounds(), { padding: [50, 50] });
    else map.setView([sumLat, sumLng], 13);
    azZoomed = false; btn.textContent = 'Activation Zone';
  }
}
function switchBase(name) {
  if (name === activeBase) return;
  map.removeLayer(baseLayers[activeBase]);
  baseLayers[name].addTo(map);
  if (gpxPolyline) gpxPolyline.bringToFront();
  if (activationZoneLayer) activationZoneLayer.bringToFront();
  activeBase = name;
  ['street', 'topo', 'satellite'].forEach(n => {
    document.getElementById('btn-base-' + n).className = 'btn btn-sm ' + (n === name ? 'btn-map-active' : 'btn-secondary');
  });
}
L.circleMarker([sumLat, sumLng], { radius: 7, color: '#C03030', fillColor: '#C03030', fillOpacity: 0.9, weight: 2 })
  .bindPopup('<strong></strong><br>').addTo(map)
  .getPopup().setContent((function () {
    var d = document.createElement('div');
    var b = document.createElement('strong'); b.textContent = SUMMIT_NAME; d.appendChild(b);
    d.appendChild(document.createElement('br')); d.appendChild(document.createTextNode(SUMMIT_REF));
    return d;
  })());
if (trailLat !== null && trailLng !== null) {
  L.circleMarker([trailLat, trailLng], { radius: 6, color: '#2D8653', fillColor: '#2D8653', fillOpacity: 0.9, weight: 2 })
    .bindPopup('Starting Point').addTo(map);
}

const tileOpts = { opacity: 0.5, maxNativeZoom: 13, maxZoom: 20, crossOrigin: true, attribution: 'FCC Form 477' };
const carrierLayers = {
  tmobile: L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/TMobile_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts),
  verizon: L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/Verizon_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts),
  att: L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/ATT_Mobility_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts)
};
const carrierActive = { tmobile: false, verizon: false, att: false };
const carrierColors = { tmobile: '#E91E8C', verizon: '#CD040B', att: '#00A8E0' };
function toggleCarrier(name) {
  const btn = document.getElementById('btn-' + name);
  if (carrierActive[name]) {
    map.removeLayer(carrierLayers[name]); carrierActive[name] = false;
    btn.style.background = '#fff'; btn.style.color = carrierColors[name];
  } else {
    carrierLayers[name].addTo(map); carrierActive[name] = true;
    btn.style.background = carrierColors[name]; btn.style.color = '#fff';
  }
}

function initActivationZone(poly, source) {
  let coords;
  if (Array.isArray(poly[0]) && Array.isArray(poly[0][0]) && Array.isArray(poly[0][0][0])) coords = poly[0][0].map(c => [c[1], c[0]]);
  else if (Array.isArray(poly[0]) && Array.isArray(poly[0][0])) coords = poly[0].map(c => [c[1], c[0]]);
  else coords = poly.map(c => [c[1], c[0]]);
  activationZoneLayer = L.polygon(coords, { color: '#CC2200', fillColor: '#CC2200', fillOpacity: 0.18, weight: 2, dashArray: '5,4' })
    .bindPopup('<strong>SOTA Activation Zone</strong>').addTo(map);
  const zBtn = document.getElementById('btn-actzone');
  if (zBtn) { zBtn.disabled = false; zBtn.style.opacity = '1'; }
  const az = document.getElementById('az-methodology');
  if (source === 'sotlas') {
    az.innerHTML = '<strong>Activation Zone:</strong> <span style="color:var(--green);">High-precision (~1m) terrain boundary</span> from <a href="https://sotl.as" target="_blank" rel="noopener">SOTLAS</a>.';
  } else {
    az.innerHTML = '<strong>Activation Zone:</strong> <span style="color:var(--green);">Terrain-based boundary</span> from <a href="https://activation.zone" target="_blank" rel="noopener">Activation.Zone</a> by N6ARA.';
  }
}

// ── Elevation profile ───────────────────────────────────────────────────────
const useMetric = <?= $units === 'metric' ? 'true' : 'false' ?>;
function drawElevationProfile(elevPts) {
  const canvas = document.getElementById('elev-canvas'), note = document.getElementById('elev-note');
  if (!canvas) return null;
  if (elevPts.length < 2 || elevPts.every(p => p[2] === 0)) { if (note) note.textContent = 'No elevation data in track.'; return null; }
  const W = Math.floor(canvas.getBoundingClientRect().width) || 600, H = 120, dpr = window.devicePixelRatio || 1;
  canvas.width = W * dpr; canvas.height = H * dpr;
  const ctx = canvas.getContext('2d'); ctx.scale(dpr, dpr);
  let pts = elevPts;
  if (pts.length > 500) { const step = Math.ceil(pts.length / 500); pts = pts.filter((_, i) => i % step === 0 || i === pts.length - 1); }
  function hDist(lat1, lon1, lat2, lon2) {
    const R = 6371, r = Math.PI / 180, dLat = (lat2 - lat1) * r, dLon = (lon2 - lon1) * r;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }
  const eleConv = useMetric ? 1 : 3.28084, distConv = useMetric ? 1 : 0.621371;
  const eleUnit = useMetric ? 'm' : 'ft', distUnit = useMetric ? 'km' : 'mi';
  const data = []; let cumD = 0;
  for (let i = 0; i < pts.length; i++) {
    if (i > 0) cumD += hDist(pts[i - 1][0], pts[i - 1][1], pts[i][0], pts[i][1]);
    data.push({ d: cumD * distConv, e: pts[i][2] * eleConv, lat: pts[i][0], lon: pts[i][1] });
  }
  const eles = data.map(p => p.e), minE = Math.min(...eles), maxE = Math.max(...eles), maxD = data[data.length - 1].d;
  const pad = { top: 8, right: 12, bottom: 24, left: 46 }, plotW = W - pad.left - pad.right, plotH = H - pad.top - pad.bottom;
  const xS = d => pad.left + (d / maxD) * plotW, yS = e => pad.top + (1 - (e - minE) / ((maxE - minE) || 1)) * plotH;
  const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + plotH);
  grad.addColorStop(0, 'rgba(43,142,142,0.32)'); grad.addColorStop(1, 'rgba(43,142,142,0.03)');
  ctx.beginPath(); ctx.moveTo(xS(data[0].d), pad.top + plotH);
  for (const p of data) ctx.lineTo(xS(p.d), yS(p.e));
  ctx.lineTo(xS(maxD), pad.top + plotH); ctx.closePath(); ctx.fillStyle = grad; ctx.fill();
  ctx.beginPath(); ctx.moveTo(xS(data[0].d), yS(data[0].e));
  for (const p of data) ctx.lineTo(xS(p.d), yS(p.e));
  ctx.strokeStyle = 'rgba(43,142,142,0.85)'; ctx.lineWidth = 1.5; ctx.stroke();
  ctx.fillStyle = '#8C8A86'; ctx.font = '10px DM Mono,monospace'; ctx.textAlign = 'right';
  [minE, (minE + maxE) / 2, maxE].forEach(e => ctx.fillText(Math.round(e) + ' ' + eleUnit, pad.left - 4, yS(e) + 3));
  ctx.textAlign = 'center';
  [0, maxD / 2, maxD].forEach(d => ctx.fillText(d.toFixed(1) + ' ' + distUnit, xS(d), H - 4));
  return { data, pad, plotW, maxD, eleUnit, distUnit };
}
function setupElevHover(s) {
  const canvas = document.getElementById('elev-canvas'), note = document.getElementById('elev-note');
  let marker = null;
  canvas.addEventListener('mousemove', e => {
    const rect = canvas.getBoundingClientRect();
    const x = (e.clientX - rect.left) * (canvas.width / rect.width / (window.devicePixelRatio || 1));
    const d = ((x - s.pad.left) / s.plotW) * s.maxD;
    if (d < 0 || d > s.maxD) return;
    let closest = s.data[0], best = Infinity;
    for (const p of s.data) { const dd = Math.abs(p.d - d); if (dd < best) { best = dd; closest = p; } }
    note.textContent = Math.round(closest.e) + ' ' + s.eleUnit + ' @ ' + closest.d.toFixed(2) + ' ' + s.distUnit;
    if (!marker) marker = L.circleMarker([closest.lat, closest.lon], { radius: 5, color: '#9B6328', fillColor: '#9B6328', fillOpacity: 0.85, weight: 2 }).addTo(map);
    else marker.setLatLng([closest.lat, closest.lon]);
  });
  canvas.addEventListener('mouseleave', () => {
    note.textContent = 'Hover for elevation details';
    if (marker) { map.removeLayer(marker); marker = null; }
  });
}

// ── Route + activation zone ─────────────────────────────────────────────────
function loadZone() {
  if (!SUMMIT_REF) return;
  fetch(feed('zone')).then(r => r.json()).then(d => { if (d.polygon) initActivationZone(d.polygon, d.source); }).catch(() => {});
}
if (HAS_GPX) {
  fetch(feed('gpx')).then(r => { if (!r.ok) throw new Error('no gpx'); return r.text(); }).then(text => {
    const gpx = new DOMParser().parseFromString(text, 'text/xml');
    const coords = [], elevPts = [];
    gpx.querySelectorAll('trkpt, rtept').forEach(pt => {
      const la = parseFloat(pt.getAttribute('lat')), lo = parseFloat(pt.getAttribute('lon'));
      coords.push([la, lo]);
      const ele = pt.querySelector('ele');
      if (ele) elevPts.push([la, lo, parseFloat(ele.textContent)]);
    });
    if (coords.length) {
      gpxPolyline = L.polyline(coords, { color: '#9B6328', weight: 3, opacity: 0.85 }).addTo(map);
      map.fitBounds(gpxPolyline.getBounds(), { padding: [50, 50] });
    }
    const st = drawElevationProfile(elevPts);
    if (st) setupElevHover(st);
    loadZone();
  }).catch(loadZone);
} else {
  loadZone();
}

// ── Weather (Open-Meteo) ────────────────────────────────────────────────────
fetch(feed('weather')).then(r => r.json()).then(function (d) {
  if (!d || d.error || !d.now) return;
  var n = d.now;
  document.getElementById('wxNow').textContent = n.icon + ' ' + n.temp + ', ' + n.label;
  document.getElementById('wxWind').textContent = 'Feels ' + n.feels + ' · Wind ' + n.wind + ', gusts ' + n.gust;
  var days = document.getElementById('wxDays');
  var fcUrl = 'summit_forecast.php?ref=' + encodeURIComponent(SUMMIT_REF);
  d.days.slice(1).forEach(function (x) {
    var c = document.createElement('a');
    c.href = fcUrl; c.target = '_blank'; c.rel = 'noopener';
    c.style.textDecoration = 'none'; c.style.color = 'inherit';
    c.title = x.label + ', max wind ' + x.wind + ', gusts ' + x.gust;
    c.style.cssText = 'text-decoration:none; color:var(--ink); display:flex; flex-direction:column; align-items:center; gap:0.15rem; padding:0.45rem 0.25rem; background:var(--bg); border:1px solid var(--border); border-radius:var(--r-md);';
    [['font-size:0.68rem; font-weight:600; color:var(--ink-3); text-transform:uppercase;', x.day],
     ['font-size:1.1rem; line-height:1.2;', x.icon],
     ['font-size:0.74rem; font-weight:500;', x.hi + ' / ' + x.lo],
     ['font-size:0.68rem; color:var(--ink-3);', '\u{1F4A8} ' + x.wind]].forEach(function (p) {
      var s = document.createElement('span'); s.style.cssText = p[0]; s.textContent = p[1]; c.appendChild(s);
    });
    days.appendChild(c);
  });
  document.getElementById('wxMore').href = fcUrl;
  document.getElementById('wxRow').style.display = 'flex';
}).catch(function () {});

// ── Upcoming activations (SOTAwatch alerts, public) ─────────────────────────
(function () {
  var body = document.getElementById('sotaAlertsBody');
  if (!body) return;
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  fetch(feed('alerts')).then(r => r.json()).then(function (j) {
    if (!j || !j.ok) throw new Error('bad');
    if (!j.alerts.length) { body.innerHTML = '<p style="color:var(--ink-3); font-size:0.875rem;">No upcoming activations posted on SOTAwatch for this summit.</p>'; return; }
    var rows = j.alerts.map(function (a) {
      var d = new Date(a.ts * 1000);
      var date = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      var time = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });
      return '<tr><td style="white-space:nowrap; font-weight:500;">' + esc(date) + '<span style="color:var(--ink-3); font-size:0.78rem; display:block;">' + esc(time) + '</span></td>' +
        '<td style="font-family:var(--font-mono); font-size:0.82rem; font-weight:600;">' + esc(a.callsign) + (a.name ? '<span style="display:block; font-family:var(--font-sans); font-size:0.72rem; font-weight:400; color:var(--ink-3);">' + esc(a.name) + '</span>' : '') + '</td>' +
        '<td style="font-size:0.78rem; color:var(--ink-2);">' + esc(a.frequency || '\u2014') + '</td>' +
        '<td style="font-size:0.78rem; color:var(--ink-2);">' + esc(a.comments) + '</td></tr>';
    }).join('');
    body.innerHTML = '<div style="overflow-x:auto;"><table class="mini-table"><thead><tr><th>Date &amp; Time (your local time)</th><th>Callsign</th><th>Frequency / Mode</th><th>Notes</th></tr></thead><tbody>' + rows + '</tbody></table></div><p style="font-size:0.75rem; color:var(--ink-4); margin-top:0.75rem;">From SOTAwatch</p>';
  }).catch(function () { body.innerHTML = '<p style="color:var(--ink-3); font-size:0.875rem;">Could not load upcoming activations right now.</p>'; });
})();

// ── Last activated (SOTA activations, cached) ────────────────────────────────
fetch(feed('activations')).then(r => r.json()).then(function (j) {
  var el = document.getElementById('lastActivated');
  if (!el) return;
  if (!j || !j.ok) { el.textContent = ''; return; }
  el.style.color = '';
  if (j.last) {
    el.innerHTML = '';
    var cs = document.createElement('span'); cs.style.cssText = 'font-family:var(--font-mono); font-size:0.8rem;'; cs.textContent = j.last.callsign;
    var dt = document.createElement('span'); dt.style.cssText = 'color:var(--ink-3); font-size:0.78rem; margin-left:0.3rem;'; dt.textContent = j.last.date;
    el.appendChild(cs); el.appendChild(dt);
  } else { el.textContent = 'Never'; }
}).catch(function () { var el = document.getElementById('lastActivated'); if (el) el.textContent = ''; });

// ── SOTA articles and links ─────────────────────────────────────────────────
(function () {
  var body = document.getElementById('sotaResourcesBody');
  if (!body) return;
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function render(items) {
    if (!items.length) { body.innerHTML = '<p style="color:var(--ink-3); font-size:0.875rem;">No articles or links have been posted for this summit yet.</p>'; return; }
    body.innerHTML = items.map(function (r) {
      var date = r.ts ? new Date(r.ts * 1000).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
      var meta = '<span class="cn-res-meta">' + esc(date) + (r.callsign && date ? ' &middot; ' : '') + (r.callsign ? '<span style="font-family:var(--font-mono); font-weight:600;">' + esc(r.callsign) + '</span>' : '') + '</span>';
      var title = esc(r.title || 'Untitled');
      if (r.article) {
        return '<details class="cn-res"><summary>' + meta + '<span class="cn-res-title">' + title + '</span></summary>' +
          '<div class="cn-res-body">' + r.article + '<p><a href="' + esc(r.url) + '" target="_blank" rel="noopener noreferrer">View on SOTA &#8599;</a></p></div></details>';
      }
      return '<div class="cn-res"><div class="cn-res-line">' + meta + '<a class="cn-res-title" href="' + esc(r.url) + '" target="_blank" rel="noopener noreferrer">' + title + ' &#8599;</a></div></div>';
    }).join('') + '<p style="font-size:0.75rem; color:var(--ink-4); margin-top:0.75rem;">From SOTA</p>';
  }
  fetch(feed('resources')).then(r => r.json()).then(function (j) {
    if (!j || !j.ok) throw new Error('bad');
    render(j.items);
    var n = j.items.length;
    document.getElementById('cnCount').textContent = n ? n + ' article' + (n === 1 ? '' : 's') : '';
  }).catch(function () { body.innerHTML = '<p style="color:var(--ink-3); font-size:0.875rem;">Could not load articles right now. Try again later.</p>'; });
})();
</script>
</body>
</html>
