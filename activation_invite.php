<?php
require_once 'config.php';

$db = getDbConnection();

if (!isset($_GET['id'])) {
    http_response_code(404);
    die('<h1>Invitation not found.</h1>');
}

$pa_id = (int)$_GET['id'];

$stmt = $db->prepare("
    SELECT pa.id as pa_id, pa.summit_id, pa.planning_group_id, pa.planned_date,
           pa.hike_start_time, pa.callsigns, pa.activation_duration_min, pa.invitation_message, pa.travel_notes, pa.location_link,
           s.name as summit_name, s.sota_ref, s.region, s.points,
           s.elevation_ft, s.elevation_m,
           s.latitude, s.longitude, s.trailhead_lat, s.trailhead_lng,
           s.hike_distance_mi, s.hike_elevation_gain_ft,
           s.hike_time_up_min, s.hike_time_down_min,
           s.drive_time_min, s.difficulty, s.cell_service,
           s.trail_link, s.sotlas_link,
           pg.name as group_name, pg.units, pg.owner_callsign, pg.pace_multiplier,
           COALESCE(us.units, pg.units) AS owner_units
    FROM planned_activations pa
    JOIN summits s ON s.id = pa.summit_id
    JOIN planning_groups pg ON pg.id = pa.planning_group_id
    LEFT JOIN user_settings us ON us.user_callsign = pg.owner_callsign
    WHERE pa.id = ?
");
$stmt->execute([$pa_id]);
$pa = $stmt->fetch();

if (!$pa) {
    http_response_code(404);
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Invitation Not Found</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600&display=swap" rel="stylesheet">
<style>body{font-family:'DM Sans',sans-serif;text-align:center;padding:4rem;color:#1C1B19;background:#F7F6F3;}</style>
</head>
<body>
    <h1>Invitation Not Found</h1>
    <p>This invitation may have expired or the link may be incorrect.</p>
</body>
</html>
<?php exit; }

// Auto-archive if date has passed
if ($pa['planned_date'] < date('Y-m-d')) {
    $db->prepare("INSERT INTO activations (summit_id, planning_group_id, activation_date, callsigns, notes) VALUES (?, ?, ?, ?, ?)")
       ->execute([$pa['summit_id'], $pa['planning_group_id'], $pa['planned_date'], $pa['callsigns'], null]);
    $db->prepare("UPDATE summits SET last_activated_date = ?, activated_by = ?, status = 'activated' WHERE id = ? AND (last_activated_date IS NULL OR last_activated_date < ?)")
       ->execute([$pa['planned_date'], $pa['callsigns'], $pa['summit_id'], $pa['planned_date']]);
    $db->prepare("DELETE FROM planned_activations WHERE id = ?")->execute([$pa_id]);
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Activation Complete</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<style>
body{font-family:'DM Sans',sans-serif;text-align:center;padding:4rem;background:#F7F6F3;color:#1C1B19;}
h1{font-family:'DM Serif Display',Georgia,serif;font-size:2rem;font-style:italic;font-weight:400;margin-bottom:1rem;}
</style>
</head>
<body>
    <h1>This activation has already taken place</h1>
    <p><?= htmlspecialchars($pa['callsigns']) ?> completed
       <strong><?= htmlspecialchars($pa['summit_name']) ?></strong>
       on <?= date('F j, Y', strtotime($pa['planned_date'])) ?>.</p>
</body>
</html>
<?php exit; }

// Get GPX track data - prefer tracks that have activation zone polygon stored
$stmt = $db->prepare("SELECT * FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ? ORDER BY (activation_zone_polygon IS NOT NULL) DESC, use_for_hike_time DESC, id DESC");
$stmt->execute([$pa['summit_id'], $pa['planning_group_id']]);
$gpx = $stmt->fetch();

// Activation zone polygon — use stored one if available, else fetch live from API
$az_polygon_json = null;
if ($gpx && $gpx['activation_zone_polygon']) {
    $az_polygon_json = $gpx['activation_zone_polygon'];
} elseif ($pa['sota_ref'] && $pa['latitude'] && $pa['longitude']) {
    $elev_m = $pa['elevation_m'] ?: ($pa['elevation_ft'] ? round($pa['elevation_ft'] / 3.28084) : 0);
    $az_result = get_activation_zone_from_api($pa['sota_ref'], $pa['latitude'], $pa['longitude'], $elev_m);
    if ($az_result && isset($az_result['polygon'])) {
        $az_polygon_json = json_encode($az_result['polygon']);
    }
}

// GPX download filename
$gpx_download_name = preg_replace('/[^a-zA-Z0-9]+/', '-', $pa['summit_name'] ?? 'summit')
    . ($pa['sota_ref'] ? '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', $pa['sota_ref']) : '')
    . '.gpx';

// Parse GPX server-side so map and elevation work for unauthenticated guests
// (load_gpx.php requires login, so the JS fetch fails for anyone not logged in)
$gpx_map_coords = [];
$gpx_map_elev   = [];
if ($gpx && !empty($gpx['file_path']) && file_exists($gpx['file_path'])) {
    $raw = @file_get_contents($gpx['file_path']);
    if ($raw) {
        $raw = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $raw);
        $xml = @simplexml_load_string($raw);
        if ($xml) {
            $pts = $xml->xpath('//trkpt') ?: ($xml->xpath('//rtept') ?: []);
            foreach ($pts as $pt) {
                $lat = (float)$pt['lat'];
                $lon = (float)$pt['lon'];
                $gpx_map_coords[] = [$lat, $lon];
                if (isset($pt->ele)) {
                    $gpx_map_elev[] = [$lat, $lon, (float)$pt->ele];
                }
            }
            // Decimate large tracks to keep inline JSON reasonable
            if (count($gpx_map_coords) > 1000) {
                $step = (int)ceil(count($gpx_map_coords) / 1000);
                $last = count($gpx_map_coords) - 1;
                $gpx_map_coords = array_values(array_filter($gpx_map_coords, fn($v, $i) => $i % $step === 0 || $i === $last, ARRAY_FILTER_USE_BOTH));
            }
            if (count($gpx_map_elev) > 500) {
                $step = (int)ceil(count($gpx_map_elev) / 500);
                $last = count($gpx_map_elev) - 1;
                $gpx_map_elev = array_values(array_filter($gpx_map_elev, fn($v, $i) => $i % $step === 0 || $i === $last, ARRAY_FILTER_USE_BOTH));
            }
        }
    }
}

// Timeline math
$units         = $pa['owner_units'] ?? $pa['units'];
$drive_one_way  = round(($pa['drive_time_min'] ?? 0) / 2);
$activation_min = (int)$pa['activation_duration_min'];
$pace_multiplier = (float)($pa['pace_multiplier'] ?? 1.0);

// Mirror summit_detail hike time logic exactly
$track_type     = $gpx ? ($gpx['track_type'] ?? 'round-trip') : 'round-trip';
$one_way        = ($track_type === 'ascent' || $track_type === 'descent');
$has_timestamps = $gpx && $gpx['hiking_time'] > 0;

$hike_time_total = 0;
if ($gpx && $gpx['use_for_hike_time']) {
    if ($has_timestamps) {
        $secs = $gpx['hiking_time'];
        if ($one_way) $secs *= 2;
        $hike_time_total = round($secs / 60);
    } else {
        $dist_km = (float)($gpx['total_distance'] ?? 0);
        if ($one_way) $dist_km *= 2;
        $dist_mi = $dist_km * 0.621371;
        $elev_ft = (float)($gpx['elevation_gain'] ?? 0) * 3.28084;
        $hike_time_total = ($dist_mi || $elev_ft) ? calculateHikeTime($dist_mi, $elev_ft, $pace_multiplier) : 0;
    }
}

// Distance/elevation for display — mirror summit_detail.php's source-of-truth logic
// (prefer live GPX track over the stale/manual summits.hike_distance_mi column,
// applying the same one-way doubling used for hike time above)
if ($gpx && $gpx['use_for_hike_time'] && $gpx['total_distance'] > 0) {
    $dist_km_display = $gpx['total_distance'];
    if ($one_way) $dist_km_display *= 2;
    $distance_display_mi = round($dist_km_display * 0.621371, 2);
} else {
    $distance_display_mi = $pa['hike_distance_mi'];
}
if ($gpx && $gpx['use_for_elevation']) {
    $elevation_gain_display = ($track_type === 'descent')
        ? $gpx['elevation_loss'] * 3.28084
        : $gpx['elevation_gain'] * 3.28084;
} else {
    $elevation_gain_display = $pa['hike_elevation_gain_ft'];
}

if ($hike_time_total > 0) {
    $hike_up_min   = intval(round($hike_time_total * 0.6));
    $hike_down_min = $hike_time_total - $hike_up_min;
} elseif ($pa['hike_time_up_min'] && $pa['hike_time_down_min']) {
    $hike_up_min   = (int)$pa['hike_time_up_min'];
    $hike_down_min = (int)$pa['hike_time_down_min'];
} else {
    $hike_up_min = $hike_down_min = 0;
}

$hike_start_ts    = strtotime($pa['planned_date'] . ' ' . $pa['hike_start_time']);
$leave_home_ts    = $drive_one_way > 0 ? $hike_start_ts - ($drive_one_way * 60) : null;
$at_summit_ts     = $hike_start_ts + ($hike_up_min * 60);
$radio_done_ts    = $at_summit_ts + ($activation_min * 60);
$back_trailhead_ts = $radio_done_ts + ($hike_down_min * 60);
$back_home_ts     = $drive_one_way > 0 ? $back_trailhead_ts + ($drive_one_way * 60) : null;

$total_min = ($drive_one_way * 2) + $hike_up_min + $activation_min + $hike_down_min;
if ($total_min < 1) $total_min = 1;

$drive_pct      = round($drive_one_way / $total_min * 100, 1);
$hike_up_pct    = round($hike_up_min   / $total_min * 100, 1);
$activation_pct = round($activation_min / $total_min * 100, 1);
$hike_down_pct  = round($hike_down_min  / $total_min * 100, 1);

// Guest drive time calculation
$guest_drive_min   = null;
$guest_address     = '';
$guest_leave_ts    = null;
$guest_directions_url = '';
$guest_error       = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(trim($_POST['guest_address'] ?? ''))) {
    $guest_address = trim($_POST['guest_address']);
    $dest_lat = $pa['trailhead_lat'] ?: $pa['latitude'];
    $dest_lng = $pa['trailhead_lng'] ?: $pa['longitude'];
    $guest_drive_min = calculateDriveTime($guest_address, $dest_lat, $dest_lng);
    if ($guest_drive_min !== null) {
        $guest_leave_ts = $hike_start_ts - ($guest_drive_min * 60);
        $guest_directions_url = "https://www.google.com/maps/dir/?api=1&origin=" .
            urlencode($guest_address) . "&destination=" . $dest_lat . "," . $dest_lng . "&travelmode=driving";
    } else {
        $guest_error = "Couldn't calculate drive time for that address. Try a full street address including city and state.";
    }
}

// Gantt: only include drive segments once guest address is entered
$gantt_drive    = $guest_drive_min ?? 0;
$show_drive     = $guest_drive_min !== null;
$total_min      = ($gantt_drive * 2) + $hike_up_min + $activation_min + $hike_down_min;
if ($total_min < 1) $total_min = 1;
$drive_pct      = $show_drive ? round($gantt_drive   / $total_min * 100, 1) : 0;
$hike_up_pct    = round($hike_up_min   / $total_min * 100, 1);
$activation_pct = round($activation_min / $total_min * 100, 1);
$hike_down_pct  = round($hike_down_min  / $total_min * 100, 1);

// Milestone leave/return times only available once guest address entered
$gantt_leave_ts     = $guest_leave_ts;
$gantt_back_home_ts = $guest_drive_min !== null ? $back_trailhead_ts + ($guest_drive_min * 60) : null;

// Trailhead coordinates for directions button
$trailhead_lat = $pa['trailhead_lat'] ?: $pa['latitude'];
$trailhead_lng = $pa['trailhead_lng'] ?: $pa['longitude'];

// Callsigns as display list
$callsign_list = array_filter(array_map('trim', explode(',', $pa['callsigns'])));

// Cell service label
$cell_labels = [
    'full'         => ['Full Coverage Expected',      '📶', 'oklch(50% 0.13 155)', 'var(--green-bg)',  'oklch(82% 0.07 155)'],
    'intermittent' => ['Intermittent Signal',          '📶', 'oklch(56% 0.14 58)',  'oklch(96% 0.05 58)', 'oklch(88% 0.08 58)'],
    'summit_only'  => ['Coverage at Summit Only',      '📶', 'var(--accent)',        'var(--accent-bg)', 'var(--accent-border)'],
    'none'         => ['No Cell Service Expected',     '📵', 'var(--red)',           'var(--red-bg)',    'oklch(85% 0.06 22)'],
];
$cell_info = isset($cell_labels[$pa['cell_service']]) ? $cell_labels[$pa['cell_service']] : null;

// Distance/elevation display
if ($units === 'metric') {
    $dist_display = $distance_display_mi ? round($distance_display_mi * 1.60934, 1) . ' km' : null;
    $gain_display = $elevation_gain_display ? round($elevation_gain_display * 0.3048) . ' m gain' : null;
    $elev_display = $pa['elevation_m'] ? number_format($pa['elevation_m']) . ' m' : null;
} else {
    $dist_display = $distance_display_mi ? $distance_display_mi . ' mi' : null;
    $gain_display = $elevation_gain_display ? number_format($elevation_gain_display) . ' ft gain' : null;
    $elev_display = $pa['elevation_ft'] ? number_format($pa['elevation_ft']) . ' ft' : null;
}

$difficulty_labels = [
    'drive-up' => 'Drive-Up',
    'easy'     => 'Easy',
    'moderate' => 'Moderate',
    'hard'     => 'Hard / Strenuous',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're Invited — <?= htmlspecialchars($pa['summit_name']) ?> SOTA Activation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        /* ── Design tokens ── */
        :root {
            --bg:        #F7F6F3;
            --bg-2:      #EFEDE8;
            --bg-3:      #E5E2DA;
            --ink:       #1C1B19;
            --ink-2:     #4A4844;
            --ink-3:     #8C8A86;
            --ink-4:     #B8B5B0;
            --surface:   #FFFFFF;
            --border:    #E5E2DA;
            --border-2:  #D4D0C8;
            --accent:    oklch(52% 0.13 50);
            --accent-2:  oklch(44% 0.13 50);
            --accent-bg: oklch(96% 0.04 65);
            --accent-border: oklch(84% 0.08 65);
            --green:     oklch(50% 0.13 155);
            --green-bg:  oklch(95% 0.04 155);
            --green-border: oklch(82% 0.07 155);
            --amber:     oklch(68% 0.16 75);
            --amber-bg:  oklch(96% 0.05 80);
            --red:       oklch(50% 0.16 22);
            --red-bg:    oklch(96% 0.04 22);
            --font-sans: 'DM Sans', system-ui, sans-serif;
            --font-mono: 'DM Mono', monospace;
            --font-serif:'DM Serif Display', Georgia, serif;
            --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px; --r-2xl: 24px;
            --shadow-sm: 0 1px 3px rgba(28,27,25,.07), 0 1px 2px rgba(28,27,25,.05);
            --shadow-md: 0 4px 12px rgba(28,27,25,.08), 0 2px 4px rgba(28,27,25,.05);
            --shadow-lg: 0 8px 24px rgba(28,27,25,.10), 0 4px 8px rgba(28,27,25,.06);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { -webkit-font-smoothing: antialiased; }
        body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Hero ── */
        .hero {
            background: var(--ink);
            color: #fff;
            padding: 3.5rem 2rem 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: "";
            position: absolute; inset: 0;
            background: repeating-linear-gradient(
                -55deg,
                transparent, transparent 40px,
                rgba(255,255,255,.018) 40px, rgba(255,255,255,.018) 41px
            );
            pointer-events: none;
        }
        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 0.72rem; font-weight: 600; letter-spacing: 0.14em;
            text-transform: uppercase; color: rgba(255,255,255,.5);
            margin-bottom: 1.25rem;
        }
        .hero-eyebrow-dot {
            width: 4px; height: 4px; border-radius: 50%;
            background: var(--accent); display: inline-block;
        }
        .hero h1 {
            font-family: var(--font-serif);
            font-size: clamp(2.25rem, 6vw, 3.75rem);
            font-weight: 400;
            font-style: italic;
            line-height: 1.05;
            letter-spacing: -0.01em;
            color: #fff;
            margin-bottom: 0.5rem;
        }
        .hero-ref {
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: rgba(255,255,255,.4);
            margin-bottom: 1.75rem;
            letter-spacing: 0.04em;
        }
        .hero-date-pill {
            display: inline-flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 100px;
            padding: 0.55rem 1.5rem;
            font-size: 1rem; font-weight: 500;
            color: rgba(255,255,255,.9);
            margin-bottom: 1.75rem;
        }
        .hero-date-sep { color: rgba(255,255,255,.25); }
        .callsigns {
            display: flex; justify-content: center; flex-wrap: wrap; gap: 8px;
            margin-bottom: 2rem;
        }
        .callsign-tag {
            font-family: var(--font-mono);
            font-size: 0.875rem; font-weight: 500;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: var(--r-md);
            padding: 0.35rem 0.875rem;
            color: #fff;
            letter-spacing: 0.06em;
        }
        .hero-message {
            max-width: 560px; margin: 0 auto 2rem;
            font-size: 0.95rem; color: rgba(255,255,255,.65);
            line-height: 1.7;
        }
        .hero-actions {
            display: flex; justify-content: center; flex-wrap: wrap; gap: 8px;
            margin-bottom: 2.5rem;
        }
        .hero-btn {
            display: inline-flex; align-items: center; gap: 6px;
            height: 38px; padding: 0 1.25rem;
            border-radius: 100px;
            font-family: var(--font-sans); font-size: 0.82rem; font-weight: 500;
            cursor: pointer; border: none; text-decoration: none;
            transition: background .15s, transform .1s;
        }
        .hero-btn:hover { text-decoration: none; transform: translateY(-1px); }
        .hero-btn-primary { background: #fff; color: var(--ink); }
        .hero-btn-primary:hover { background: rgba(255,255,255,.88); }
        .hero-btn-outline { background: rgba(255,255,255,.1); color: #fff; border: 1px solid rgba(255,255,255,.2); }
        .hero-btn-outline:hover { background: rgba(255,255,255,.18); }
        .hero-wave {
            display: block;
            width: 100%; height: 48px;
            margin-bottom: -2px;
        }

        /* ── Page body ── */
        .page { max-width: 760px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }

        /* ── Section cards ── */
        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r-xl);
            padding: 1.75rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        .section-title {
            font-size: 0.72rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.1em;
            color: var(--ink-3);
            margin-bottom: 1.25rem;
            padding-bottom: 0.875rem;
            border-bottom: 1px solid var(--border);
        }

        /* ── Travel notes card ── */
        .travel-note {
            background: var(--surface);
            border: 1px solid var(--accent-border);
            border-radius: var(--r-xl);
            padding: 1.5rem 1.75rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        .travel-note-label {
            font-size: 0.72rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.1em;
            color: var(--accent); margin-bottom: 0.625rem;
        }
        .travel-note-body { font-size: 0.9rem; line-height: 1.7; color: var(--ink-2); }

        /* ── Fact strip ── */
        .fact-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 1px;
            background: var(--border);
            border: 1px solid var(--border);
            border-radius: var(--r-lg);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .fact-cell {
            background: var(--surface);
            padding: 1rem 0.875rem;
            text-align: center;
            cursor: default;
        }
        .fact-cell.cta {
            background: var(--accent-bg);
            cursor: pointer;
            transition: background .12s;
        }
        .fact-cell.cta:hover { background: oklch(93% 0.06 65); }
        .fact-cell.personalized { background: var(--green-bg); }
        .fact-label {
            font-size: 0.65rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.08em;
            color: var(--ink-3); margin-bottom: 5px;
        }
        .fact-val { font-size: 0.95rem; font-weight: 600; color: var(--ink); line-height: 1.2; }
        .fact-val-cta { font-size: 0.8rem; font-weight: 500; color: var(--accent); line-height: 1.3; }
        .fact-val-pers { font-size: 0.95rem; font-weight: 600; color: var(--green); line-height: 1.2; }

        /* ── Gantt ── */
        .gantt-bar {
            display: flex; height: 44px;
            border-radius: var(--r-md);
            overflow: hidden;
            margin-bottom: 0.625rem;
        }
        .gantt-seg {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            overflow: hidden; transition: flex .3s;
            min-width: 0;
        }
        .gantt-seg .seg-label { font-size: 0.65rem; font-weight: 600; white-space: nowrap; line-height: 1.2; }
        .gantt-seg .seg-time  { font-size: 0.6rem; opacity: 0.75; white-space: nowrap; margin-top: 1px; }
        .seg-drive     { background: oklch(55% 0.04 50);  color: #fff; }
        .seg-hike-up   { background: var(--green);         color: #fff; }
        .seg-radio     { background: var(--ink);            color: #fff; }
        .seg-hike-down { background: oklch(60% 0.10 155);  color: #fff; }
        .seg-drive-back{ background: oklch(55% 0.04 50);   color: #fff; }

        /* ── Milestones ── */
        .gantt-milestones-container {
            position: relative;
            height: 50px;
            margin-top: 0.25rem;
        }
        .milestone {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            transform: translateX(-50%);
        }
        .milestone-dot {
            width: 1px; height: 6px;
            background: var(--border-2);
            margin: 0 auto 3px;
        }
        .milestone-time {
            font-size: 0.72rem; font-weight: 600;
            color: var(--ink); font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .milestone-label { color: var(--ink-3); font-size: 0.6rem; white-space: nowrap; text-align: center; }

        /* ── Gantt legend ── */
        .gantt-legend {
            display: flex; flex-wrap: wrap; gap: 0.5rem 1rem;
            margin-top: 1rem; padding-top: 0.875rem;
            border-top: 1px solid var(--border);
        }
        .legend-item { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: var(--ink-2); }
        .legend-dot { width: 10px; height: 10px; border-radius: 2px; flex-shrink: 0; }

        /* ── Stats row ── */
        .stats-row {
            display: flex; flex-wrap: wrap; gap: 0.5rem;
            margin-top: 1.25rem; padding-top: 1.125rem;
            border-top: 1px solid var(--border);
        }
        .stat-chip {
            display: inline-flex; align-items: center; gap: 4px;
            height: 28px; padding: 0 0.75rem;
            background: var(--bg-2); border: 1px solid var(--border);
            border-radius: 100px;
            font-size: 0.775rem; font-weight: 500; color: var(--ink-2);
        }
        .stat-chip-label { color: var(--ink-4); font-weight: 400; margin-right: 2px; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            height: 38px; padding: 0 1.125rem;
            border-radius: var(--r-md); font-family: var(--font-sans);
            font-size: 0.875rem; font-weight: 500;
            cursor: pointer; border: none; transition: background .15s;
            text-decoration: none; white-space: nowrap; flex-shrink: 0;
        }
        .btn:hover { text-decoration: none; }
        .btn-primary { background: var(--ink); color: #fff; }
        .btn-primary:hover { background: var(--ink-2); color: #fff; }
        .btn-accent { background: var(--accent); color: #fff; }
        .btn-accent:hover { background: var(--accent-2); color: #fff; }
        .btn-ghost { background: var(--bg-2); color: var(--ink); border: 1px solid var(--border); }
        .btn-ghost:hover { background: var(--bg-3); }
        .btn-green { background: var(--green); color: #fff; }
        .btn-green:hover { background: oklch(44% 0.13 155); color: #fff; }

        /* ── Drive form ── */
        .drive-form {
            background: var(--accent-bg);
            border: 1px solid var(--accent-border);
            border-radius: var(--r-lg);
            padding: 1.25rem;
        }
        .drive-form-label {
            font-size: 0.8rem; font-weight: 500;
            color: var(--ink-2); margin-bottom: 0.5rem; display: block;
        }
        .drive-input-row { display: flex; gap: 0.625rem; }
        .drive-input {
            flex: 1; min-width: 0;
            padding: 0.625rem 0.875rem;
            background: var(--surface); border: 1px solid var(--border-2);
            border-radius: var(--r-md); font-family: var(--font-sans);
            font-size: 0.9rem; color: var(--ink); outline: none;
            transition: border-color .15s;
        }
        .drive-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px oklch(85% 0.07 55 / .3); }
        .drive-input::placeholder { color: var(--ink-4); }
        .drive-result {
            margin-top: 1rem;
            padding: 0.875rem 1rem;
            background: var(--green-bg);
            border: 1px solid var(--green-border);
            border-radius: var(--r-md);
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
        }
        .drive-result-text { font-size: 0.875rem; font-weight: 500; color: var(--green); }

        /* ── Cell coverage banner ── */
        .cell-banner {
            display: flex; align-items: flex-start; gap: 0.875rem;
            padding: 1rem 1.125rem;
            border-radius: var(--r-lg);
            margin-bottom: 1rem;
        }
        .cell-banner-icon { font-size: 1.25rem; flex-shrink: 0; line-height: 1.3; }
        .cell-banner-text { font-size: 0.875rem; line-height: 1.6; }
        .cell-banner-title { font-weight: 600; display: block; margin-bottom: 2px; }

        /* ── Safety notice ── */
        .safety-box {
            background: oklch(97% 0.025 75);
            border: 1px solid oklch(88% 0.07 75);
            border-radius: var(--r-lg);
            padding: 1.125rem 1.25rem;
            display: flex; gap: 0.875rem; align-items: flex-start;
        }
        .safety-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }
        .safety-text { font-size: 0.835rem; line-height: 1.65; color: var(--ink-2); }
        .safety-text strong { color: var(--ink); }

        /* ── Info box (location link etc.) ── */
        .info-box {
            background: var(--bg-2);
            border: 1px solid var(--border);
            border-radius: var(--r-lg);
            padding: 1rem 1.125rem;
            display: flex; gap: 0.875rem; align-items: flex-start;
            margin-top: 0.75rem;
        }
        .info-box-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }
        .info-box-text { font-size: 0.875rem; line-height: 1.6; color: var(--ink-2); }
        .info-box-text strong { color: var(--ink); display: block; margin-bottom: 2px; }

        /* ── Map controls ── */
        .map-controls {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin-bottom: 0.75rem; align-items: center;
        }
        .map-ctrl-label { font-size: 0.72rem; color: var(--ink-4); }
        .map-sep { color: var(--border-2); font-size: 0.8rem; margin: 0 2px; }
        .map-btn {
            padding: 0.25rem 0.75rem; border-radius: 100px;
            border: 1px solid var(--border-2);
            background: var(--surface); color: var(--ink-2);
            font-weight: 600; font-size: 0.75rem;
            cursor: pointer; transition: all 0.15s;
            font-family: var(--font-sans);
        }

        /* ── Elevation wrap ── */
        .elev-wrap {
            background: var(--bg-2);
            border-radius: var(--r-md);
            border: 1px solid var(--border);
            padding: 0.6rem 0.75rem 0.4rem;
            margin-top: 0.75rem;
        }
        .elev-label {
            font-size: 0.68rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.08em;
            color: var(--ink-3); margin-bottom: 0.375rem;
        }

        /* ── SOTA Modal ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(28,27,25,.5); z-index: 200;
            align-items: center; justify-content: center;
            backdrop-filter: blur(3px); padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: var(--surface); border-radius: var(--r-2xl);
            padding: 2rem; max-width: 500px; width: 100%;
            max-height: 90vh; overflow-y: auto;
            position: relative; box-shadow: var(--shadow-lg);
        }
        .modal-close {
            position: absolute; top: 1rem; right: 1rem;
            background: var(--bg-2); border: 1px solid var(--border);
            border-radius: var(--r-md); width: 30px; height: 30px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--ink-3); font-size: 1rem;
            transition: background .1s;
        }
        .modal-close:hover { background: var(--bg-3); color: var(--ink); }
        .explainer-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
            margin-top: 1rem;
        }
        .explainer-card {
            background: var(--bg-2); border-radius: var(--r-md); padding: 1rem;
        }
        .explainer-card h4 { font-size: 0.85rem; font-weight: 600; margin-bottom: 4px; color: var(--ink); }
        .explainer-card p { font-size: 0.8rem; color: var(--ink-2); line-height: 1.55; margin: 0; }

        /* ── Footer ── */
        .invite-footer {
            text-align: center; padding: 2rem 1rem;
            font-size: 0.75rem; color: var(--ink-4);
            border-top: 1px solid var(--border);
        }
        .invite-footer a { color: var(--ink-3); }

        /* ── Mobile ── */
        @media (max-width: 600px) {
            .hero { padding: 2.5rem 1.25rem 0; }
            .hero h1 { font-size: 2.25rem; }
            .hero-date-pill { flex-direction: column; gap: 4px; padding: 0.6rem 1.25rem; }
            .hero-date-sep { display: none; }
            .page { padding: 1.25rem 1rem 3rem; }
            .section { padding: 1.25rem; border-radius: var(--r-lg); }
            .explainer-grid { grid-template-columns: 1fr; }
            .drive-input-row { flex-direction: column; }
            .gantt-seg .seg-label { display: none; }
            .gantt-seg .seg-time  { display: none; }
            .gantt-bar { height: 36px; border-radius: var(--r-sm); }
            .fact-strip { grid-template-columns: repeat(3, 1fr); }

            /* Milestones: vertical list on mobile */
            .gantt-milestones-container {
                position: static;
                height: auto;
                margin-top: 0.75rem;
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
                padding: 0.6rem 0.75rem;
                background: var(--bg-2);
                border-radius: var(--r-md);
            }
            .milestone {
                position: static !important;
                transform: none !important;
                flex-direction: row;
                gap: 0.55rem;
                align-items: center;
                justify-content: flex-start;
            }
            .milestone-dot {
                width: 6px; height: 6px; border-radius: 50%;
                background: var(--border-2); margin: 0; flex-shrink: 0;
            }
            .milestone-time { font-size: 0.85rem; min-width: 70px; }
            .milestone-label { font-size: 0.78rem; color: var(--ink-3); white-space: nowrap; }

            /* Hide cell carrier toggles on mobile */
            .map-cell-section { display: none !important; }
        }
        @media (max-width: 390px) {
            .hero h1 { font-size: 1.9rem; }
            .fact-strip { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- HERO -->
<div class="hero">
    <div class="hero-eyebrow">
        <span class="hero-eyebrow-dot"></span>
        You're Invited
        <span class="hero-eyebrow-dot"></span>
    </div>
    <h1><?= htmlspecialchars($pa['summit_name']) ?></h1>
    <div class="hero-ref">
        <?= htmlspecialchars($pa['sota_ref']) ?>
        <?php if ($pa['region']): ?> &nbsp;·&nbsp; <?= htmlspecialchars($pa['region']) ?><?php endif; ?>
    </div>
    <div class="hero-date-pill">
        <span><?= date('l, F j, Y', strtotime($pa['planned_date'])) ?></span>
        <span class="hero-date-sep">·</span>
        <span>Hike starts <?= date('g:i A', strtotime($pa['hike_start_time'])) ?></span>
    </div>
    <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.1em; text-transform:uppercase; color:rgba(255,255,255,.45); margin-bottom:0.5rem;">Hosting Amateur Radio Operator<?= count($callsign_list) > 1 ? 's' : '' ?></div>
    <div class="callsigns">
        <?php foreach ($callsign_list as $cs): ?>
            <span class="callsign-tag"><?= htmlspecialchars($cs) ?></span>
        <?php endforeach; ?>
    </div>
    <?php if ($pa['invitation_message']): ?>
    <p class="hero-message"><?= nl2br(htmlspecialchars($pa['invitation_message'])) ?></p>
    <?php else: ?>
    <p class="hero-message">
        You're invited to join us for a day on the mountain! We'll be hiking to the summit and making
        amateur radio contacts with stations around the world — no radio license needed to tag along.
        Lace up your hiking boots and come enjoy the views.
    </p>
    <?php endif; ?>
    <div class="hero-actions">
        <a href="activation_ics.php?id=<?= $pa['pa_id'] ?>" class="hero-btn hero-btn-primary">
            Add to Calendar
        </a>
        <?php if ($trailhead_lat && $trailhead_lng): ?>
            <a href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode($trailhead_lat . ',' . $trailhead_lng) ?>" target="_blank" class="hero-btn hero-btn-primary">
                Get Directions
            </a>
        <?php endif; ?>
        <button class="hero-btn hero-btn-primary" onclick="document.getElementById('sota-modal').classList.add('open')">
            What is SOTA?
        </button>
    </div>
    <!-- Wave separator -->
    <svg class="hero-wave" viewBox="0 0 1200 48" preserveAspectRatio="none" fill="var(--bg)">
        <path d="M0,48 C300,0 900,0 1200,48 L1200,48 L0,48 Z" />
    </svg>
</div>

<div class="page">

<?php if ($pa['travel_notes']): ?>
    <div class="travel-note">
        <div class="travel-note-label">Parking &amp; Travel Info</div>
        <div class="travel-note-body"><?= nl2br(htmlspecialchars($pa['travel_notes'])) ?></div>
    </div>
<?php endif; ?>

<!-- DAY AT A GLANCE -->
<div class="section">
    <div class="section-title">Day at a Glance</div>

    <?php
    $guest_back_home_ts = $guest_drive_min !== null ? $back_trailhead_ts + ($guest_drive_min * 60) : null;
    ?>

    <!-- Fact strip -->
    <div class="fact-strip">

        <?php if ($guest_leave_ts): ?>
            <div class="fact-cell personalized">
                <div class="fact-label">Your Leave Time</div>
                <div class="fact-val-pers"><?= date('g:i A', $guest_leave_ts) ?></div>
                <a href="<?= htmlspecialchars($guest_directions_url) ?>" target="_blank"
                   style="display:inline-block; margin-top:0.4rem; font-size:0.68rem; font-weight:600;
                          background:var(--green); color:white; padding:0.2rem 0.5rem;
                          border-radius:var(--r-sm); text-decoration:none;">Directions</a>
            </div>
        <?php else: ?>
            <div class="fact-cell cta"
                 onclick="document.getElementById('drive-section').scrollIntoView({behavior:'smooth'}); document.getElementById('guest-address-input').focus({preventScroll:true});">
                <div class="fact-label">Your Leave Time</div>
                <div class="fact-val-cta">Add your address →</div>
            </div>
        <?php endif; ?>

        <div class="fact-cell">
            <div class="fact-label">Hike Start</div>
            <div class="fact-val"><?= date('g:i A', $hike_start_ts) ?></div>
        </div>

        <?php if ($hike_up_min > 0): ?>
        <div class="fact-cell">
            <div class="fact-label">At Summit</div>
            <div class="fact-val"><?= date('g:i A', $at_summit_ts) ?></div>
        </div>
        <?php endif; ?>

        <div class="fact-cell">
            <div class="fact-label">Radio Time</div>
            <div class="fact-val" style="font-size:0.82rem;"><?= date('g:i A', $at_summit_ts) ?>–<?= date('g:i A', $radio_done_ts) ?></div>
        </div>

        <?php if ($hike_down_min > 0): ?>
        <div class="fact-cell">
            <div class="fact-label">Back at Trailhead</div>
            <div class="fact-val"><?= date('g:i A', $back_trailhead_ts) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($guest_back_home_ts): ?>
            <div class="fact-cell personalized">
                <div class="fact-label">Your Return</div>
                <div class="fact-val-pers">~<?= date('g:i A', $guest_back_home_ts) ?></div>
            </div>
        <?php else: ?>
            <div class="fact-cell cta"
                 onclick="document.getElementById('drive-section').scrollIntoView({behavior:'smooth'}); document.getElementById('guest-address-input').focus({preventScroll:true});">
                <div class="fact-label">Your Return</div>
                <div class="fact-val-cta">Add your address →</div>
            </div>
        <?php endif; ?>

    </div>

    <?php if ($guest_leave_ts): ?>
        <p style="font-size:0.78rem; color:var(--green); margin-bottom:1.25rem; font-weight:500;">
            Showing your personalized times based on: <?= htmlspecialchars($guest_address) ?>
        </p>
    <?php endif; ?>

    <!-- Gantt bar -->
    <?php if ($total_min > 0): ?>
    <div>
        <div class="gantt-bar">
            <?php if ($drive_pct > 0): ?>
            <div class="gantt-seg seg-drive" style="flex: <?= $drive_pct ?>;">
                <span class="seg-label">Drive</span>
                <span class="seg-time"><?= $gantt_drive ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($hike_up_pct > 0): ?>
            <div class="gantt-seg seg-hike-up" style="flex: <?= $hike_up_pct ?>;">
                <span class="seg-label">Hike Up</span>
                <span class="seg-time"><?= $hike_up_min ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($activation_pct > 0): ?>
            <div class="gantt-seg seg-radio" style="flex: <?= $activation_pct ?>;">
                <span class="seg-label">Radio</span>
                <span class="seg-time"><?= $activation_min ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($hike_down_pct > 0): ?>
            <div class="gantt-seg seg-hike-down" style="flex: <?= $hike_down_pct ?>;">
                <span class="seg-label">Hike Down</span>
                <span class="seg-time"><?= $hike_down_min ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($drive_pct > 0): ?>
            <div class="gantt-seg seg-drive-back" style="flex: <?= $drive_pct ?>;">
                <span class="seg-label">Drive</span>
                <span class="seg-time"><?= $gantt_drive ?>m</span>
            </div>
            <?php endif; ?>
        </div>

        <?php
        $m = [];
        $cursor = 0;
        if ($gantt_leave_ts) { $m[] = [$cursor, date('g:i A', $gantt_leave_ts), 'Leave Home']; }
        $cursor += $drive_pct;
        $m[] = [$cursor, date('g:i A', $hike_start_ts), 'Trailhead'];
        $cursor += $hike_up_pct;
        if ($hike_up_min > 0) { $m[] = [$cursor, date('g:i A', $at_summit_ts), 'Summit']; }
        $cursor += $activation_pct;
        $m[] = [$cursor, date('g:i A', $radio_done_ts), 'Radio Done'];
        $cursor += $hike_down_pct;
        if ($hike_down_min > 0) { $m[] = [$cursor, date('g:i A', $back_trailhead_ts), 'Trailhead']; }
        $cursor += $drive_pct;
        if ($gantt_back_home_ts) { $m[] = [$cursor, date('g:i A', $gantt_back_home_ts), 'Home']; }
        ?>
        <div class="gantt-milestones-container">
            <?php foreach ($m as $milestone): ?>
            <div class="milestone" style="left: <?= min($milestone[0], 98) ?>%;">
                <div class="milestone-dot"></div>
                <div class="milestone-time"><?= $milestone[1] ?></div>
                <div class="milestone-label"><?= $milestone[2] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="gantt-legend">
        <?php if ($show_drive): ?>
        <div class="legend-item"><div class="legend-dot" style="background:oklch(55% 0.04 50);"></div> Drive (<?= $gantt_drive ?> min each way — your estimate)</div>
        <?php endif; ?>
        <?php if ($hike_up_pct > 0): ?>
        <div class="legend-item"><div class="legend-dot" style="background:var(--green);"></div> Hike Up (<?= $hike_up_min ?> min)</div>
        <?php endif; ?>
        <div class="legend-item"><div class="legend-dot" style="background:var(--ink);"></div> Radio / SOTA Activation (<?= $activation_min ?> min)</div>
        <?php if ($hike_down_pct > 0): ?>
        <div class="legend-item"><div class="legend-dot" style="background:oklch(60% 0.10 155);"></div> Hike Down (<?= $hike_down_min ?> min)</div>
        <?php endif; ?>
        <?php if ($show_drive): ?>
        <div class="legend-item" style="color:var(--ink-3);">Total Day: ~<?= formatTime($total_min) ?></div>
        <?php else: ?>
        <div class="legend-item" style="color:var(--accent); cursor:pointer;"
             onclick="document.getElementById('drive-section').scrollIntoView({behavior:'smooth'}); document.getElementById('guest-address-input').focus({preventScroll:true});">
            <em>Add your address below to include drive time</em>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Hike stats -->
    <?php if ($elev_display || $dist_display || $gain_display || $pa['difficulty'] || $pa['points']): ?>
    <div class="stats-row">
        <?php if ($elev_display): ?>
            <span class="stat-chip"><span class="stat-chip-label">Elevation</span><?= $elev_display ?></span>
        <?php endif; ?>
        <?php if ($dist_display): ?>
            <span class="stat-chip"><span class="stat-chip-label">Round-trip</span><?= $dist_display ?></span>
        <?php endif; ?>
        <?php if ($gain_display): ?>
            <span class="stat-chip"><span class="stat-chip-label">Gain</span><?= $gain_display ?></span>
        <?php endif; ?>
        <?php if ($pa['difficulty'] && isset($difficulty_labels[$pa['difficulty']])): ?>
            <span class="stat-chip"><span class="stat-chip-label">Difficulty</span><?= $difficulty_labels[$pa['difficulty']] ?></span>
        <?php endif; ?>
        <?php if ($pa['points']): ?>
            <span class="stat-chip"><span class="stat-chip-label">SOTA pts</span><?= $pa['points'] ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Summit links -->
    <?php if ($pa['trail_link'] || $pa['sotlas_link'] || ($pa['latitude'] && $pa['longitude'])): ?>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:1rem;">
        <?php if ($pa['trail_link']): ?>
            <a href="<?= htmlspecialchars($pa['trail_link']) ?>" target="_blank" class="btn btn-ghost" style="font-size:0.82rem;">Trail Info ↗</a>
        <?php endif; ?>
        <?php if ($pa['latitude'] && $pa['longitude']): ?>
            <a href="https://www.google.com/maps/search/?api=1&query=<?= $pa['latitude'] ?>,<?= $pa['longitude'] ?>" target="_blank" class="btn btn-ghost" style="font-size:0.82rem;">Summit Map ↗</a>
        <?php endif; ?>
        <?php if ($pa['sotlas_link']): ?>
            <a href="<?= htmlspecialchars($pa['sotlas_link']) ?>" target="_blank" class="btn btn-ghost" style="font-size:0.82rem;">SOTA Info ↗</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- YOUR DRIVE TIME -->
<div class="section" id="drive-section">
    <div class="section-title">Calculate Your Drive Time</div>
    <p style="font-size:0.875rem; color:var(--ink-2); margin-bottom:1rem; line-height:1.6;">
        Add your starting address to personalize the timeline above with your leave time and estimated return.
    </p>

    <div class="drive-form">
        <form method="POST">
            <label class="drive-form-label" for="guest-address-input">Your starting address</label>
            <div class="drive-input-row">
                <input type="text" name="guest_address" id="guest-address-input"
                       class="drive-input"
                       value="<?= htmlspecialchars($guest_address) ?>"
                       placeholder="1234 Main St, Los Angeles, CA">
                <button type="submit" class="btn btn-accent">Calculate</button>
            </div>
        </form>
    </div>

    <?php if ($guest_error): ?>
        <p style="color:var(--red); margin-top:0.875rem; font-size:0.875rem; font-weight:500;">
            <?= htmlspecialchars($guest_error) ?>
        </p>
    <?php endif; ?>

    <?php if ($guest_drive_min !== null): ?>
        <div class="drive-result">
            <span class="drive-result-text">
                <?= formatTime($guest_drive_min) ?> drive — your times are updated above.
            </span>
            <a href="<?= htmlspecialchars($guest_directions_url) ?>" target="_blank" class="btn btn-green" style="font-size:0.82rem;">
                Get Directions ↗
            </a>
        </div>
    <?php endif; ?>

    <p style="font-size:0.75rem; color:var(--ink-4); margin-top:0.75rem;">
        Drive times calculated via Google Maps. Use nearby cross streets for privacy.
    </p>
</div>

<?php if ($gpx): ?>
<!-- HIKE ROUTE -->
<div class="section">
    <div class="section-title">Hike Route</div>

    <!-- Map controls -->
    <div class="map-controls">
        <span class="map-ctrl-label">Map:</span>
        <button onclick="switchBase('street')" id="btn-base-street" class="map-btn" style="background:#1C1B19;color:#fff;border-color:#1C1B19;">Street</button>
        <button onclick="switchBase('topo')"   id="btn-base-topo"   class="map-btn">Topo</button>
        <button onclick="switchBase('satellite')" id="btn-base-satellite" class="map-btn">Satellite</button>

        <?php if ($az_polygon_json): ?>
        <span class="map-sep">|</span>
        <button onclick="zoomToActivationZone()" id="btn-actzone"
                class="map-btn" style="border-color:#CC2200;color:#CC2200;background:#fff;">
            Zoom to Activation Zone
        </button>
        <?php endif; ?>

        <span class="map-cell-section" style="display:contents;">
            <span class="map-sep">|</span>
            <span class="map-ctrl-label">Cell Coverage:</span>
            <button onclick="toggleCarrier('tmobile')" id="btn-tmobile" class="map-btn" style="border-color:#E91E8C;color:#E91E8C;">T-Mobile</button>
            <button onclick="toggleCarrier('verizon')" id="btn-verizon" class="map-btn" style="border-color:#CD040B;color:#CD040B;">Verizon</button>
            <button onclick="toggleCarrier('att')"     id="btn-att"     class="map-btn" style="border-color:#00A8E0;color:#00A8E0;">AT&amp;T</button>
            <span style="font-size:0.72rem; color:var(--ink-4);">Cell data may be optimistic in mountainous terrain</span>
        </span>

        <span class="map-sep">|</span>
        <a href="load_gpx.php?id=<?= $gpx['id'] ?>&pa_id=<?= $pa_id ?>" download="<?= htmlspecialchars($gpx_download_name) ?>"
           class="btn btn-ghost" style="font-size:0.75rem; height:28px; padding:0 0.75rem;">
            Download GPX ↓
        </a>
    </div>

    <div id="gpx-map" style="height: 400px; border-radius: var(--r-lg); border: 1px solid var(--border);"></div>
    <p style="font-size:0.72rem; color:var(--ink-4); margin-top:0.5rem;">
        Coverage data: FCC Form 477 filings (2021), via ArcGIS public tile service. Carrier-reported estimates — actual signal in mountainous terrain may differ.
    </p>

    <!-- Elevation Profile -->
    <div class="elev-wrap">
        <div class="elev-label">Elevation Profile</div>
        <canvas id="elev-canvas" style="width:100%; height:100px; display:block;"></canvas>
        <div id="elev-note" style="font-size:0.72rem; color:var(--ink-4); margin-top:0.25rem; text-align:center; display:none;"></div>
    </div>

    <script>
    // ── Base tile layers ──────────────────────────────────────────────────────
    const streetLayer    = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 19 });
    const topoLayer      = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',  { attribution: '© OpenStreetMap contributors, SRTM | Map style: © OpenTopoMap', maxZoom: 17 });
    const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles © Esri', maxZoom: 19 });

    const map = L.map('gpx-map').setView([<?= $gpx['summit_lat'] ?>, <?= $gpx['summit_lon'] ?>], 13);
    streetLayer.addTo(map);
    let currentBase = streetLayer;
    let gpxPolyline = null;

    function switchBase(name) {
        const bases = { street: streetLayer, topo: topoLayer, satellite: satelliteLayer };
        map.removeLayer(currentBase);
        currentBase = bases[name];
        currentBase.addTo(map);
        if (gpxPolyline) gpxPolyline.bringToFront();
        if (activationZoneLayer) activationZoneLayer.bringToFront();
        Object.values(carrierLayers).forEach(l => { if (map.hasLayer(l)) l.bringToFront(); });
        ['street','topo','satellite'].forEach(n => {
            const b = document.getElementById('btn-base-' + n);
            if (!b) return;
            b.style.background = n === name ? '#1C1B19' : '#fff';
            b.style.color      = n === name ? '#fff'    : '#4A4844';
            b.style.borderColor= n === name ? '#1C1B19' : '#D4D0C8';
        });
    }

    // ── Carrier LTE coverage layers (FCC Form 477 / ArcGIS) ──────────────────
    const tileOpts = { opacity: 0.5, maxNativeZoom: 13, maxZoom: 20, crossOrigin: true, attribution: 'FCC Form 477 / ArcGIS' };
    const carrierLayers = {
        tmobile: L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/TMobile_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts),
        verizon: L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/Verizon_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts),
        att:     L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/ATT_Mobility_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts),
    };
    const carrierActive = { tmobile: false, verizon: false, att: false };
    const carrierColors = { tmobile: '#E91E8C', verizon: '#CD040B', att: '#00A8E0' };

    function toggleCarrier(name) {
        const btn = document.getElementById('btn-' + name);
        if (carrierActive[name]) {
            map.removeLayer(carrierLayers[name]);
            carrierActive[name] = false;
            btn.style.background = '#fff';
            btn.style.color = carrierColors[name];
        } else {
            carrierLayers[name].addTo(map);
            carrierActive[name] = true;
            btn.style.background = carrierColors[name];
            btn.style.color = 'white';
        }
    }

    // ── Activation zone ───────────────────────────────────────────────────────
    let activationZoneLayer = null;

    <?php if ($az_polygon_json): ?>
    (function() {
        const poly = <?= $az_polygon_json ?>;
        let coords;
        if (Array.isArray(poly[0]) && Array.isArray(poly[0][0]) && Array.isArray(poly[0][0][0])) {
            coords = poly[0][0].map(c => [c[1], c[0]]); // triple nested
        } else if (Array.isArray(poly[0]) && Array.isArray(poly[0][0])) {
            coords = poly[0].map(c => [c[1], c[0]]);    // double nested
        } else {
            coords = poly.map(c => [c[1], c[0]]);        // single level
        }
        activationZoneLayer = L.polygon(coords, {
            color: '#CC2200', fillColor: '#CC2200', fillOpacity: 0.15, weight: 2
        }).bindPopup('Activation Zone').addTo(map);
    })();
    <?php endif; ?>

    function zoomToActivationZone() {
        if (activationZoneLayer) {
            map.fitBounds(activationZoneLayer.getBounds(), {padding: [30, 30]});
        }
    }

    // ── GPX track (coords inlined server-side — no auth needed for guests) ──────
    (function() {
        const coords  = <?= json_encode($gpx_map_coords) ?>;
        const elevPts = <?= json_encode($gpx_map_elev) ?>;

        if (!coords || coords.length === 0) return;

        gpxPolyline = L.polyline(coords, {color: '#9B6328', weight: 4, opacity: 0.85}).addTo(map);

        // Trailhead marker
        L.marker(coords[0], {
            icon: L.divIcon({
                html: '<div style="background:var(--green,#2E7D32);color:white;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:15px;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3)">P</div>',
                iconSize:[28,28], className:''
            })
        }).bindPopup('Trailhead').addTo(map);

        // Summit marker
        L.marker([<?= $gpx['summit_lat'] ?>, <?= $gpx['summit_lon'] ?>], {
            icon: L.divIcon({
                html: '<div style="background:#1C1B19;color:white;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:15px;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3)">▲</div>',
                iconSize:[32,32], className:''
            })
        }).bindPopup('<?= htmlspecialchars(addslashes($pa['summit_name'])) ?><br><?= htmlspecialchars($pa['sota_ref']) ?>').addTo(map);

        map.fitBounds(L.polyline(coords).getBounds(), {padding:[40,40]});

        const elevState = drawElevationProfile(elevPts);
        if (elevState) setupElevMapHover(elevState, map);
    })();

    function drawElevationProfile(elevPts) {
        const canvas = document.getElementById('elev-canvas');
        const note = document.getElementById('elev-note');
        if (!canvas) return null;
        if (elevPts.length < 2) {
            if (note) { note.style.display = ''; note.textContent = 'No elevation data in this track.'; }
            return null;
        }

        const rect = canvas.getBoundingClientRect();
        const W = Math.floor(rect.width) || 600;
        const H = 100;
        const dpr = window.devicePixelRatio || 1;
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        let pts = elevPts;
        if (pts.length > 500) {
            const step = Math.ceil(pts.length / 500);
            pts = pts.filter((_, i) => i % step === 0 || i === pts.length - 1);
        }

        function hDist(lat1, lon1, lat2, lon2) {
            const R = 6371, r = Math.PI / 180;
            const dLat = (lat2 - lat1) * r, dLon = (lon2 - lon1) * r;
            const a = Math.sin(dLat/2)**2 + Math.cos(lat1*r)*Math.cos(lat2*r)*Math.sin(dLon/2)**2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        const useMetric = <?= $pa['units'] === 'metric' ? 'true' : 'false' ?>;
        const eleConv = useMetric ? 1 : 3.28084;
        const distConv = useMetric ? 1 : 0.621371;
        const eleUnit = useMetric ? 'm' : 'ft';
        const distUnit = useMetric ? 'km' : 'mi';

        const data = [];
        let cumD = 0;
        for (let i = 0; i < pts.length; i++) {
            if (i > 0) cumD += hDist(pts[i-1][0], pts[i-1][1], pts[i][0], pts[i][1]);
            data.push({ d: cumD * distConv, e: pts[i][2] * eleConv, lat: pts[i][0], lon: pts[i][1] });
        }

        const eles = data.map(p => p.e);
        const minE = Math.min(...eles), maxE = Math.max(...eles);
        const maxD = data[data.length - 1].d;

        const pad = {top: 8, right: 12, bottom: 24, left: 46};
        const plotW = W - pad.left - pad.right;
        const plotH = H - pad.top - pad.bottom;
        const xS = d => pad.left + (d / maxD) * plotW;
        const yS = e => pad.top + (1 - (e - minE) / ((maxE - minE) || 1)) * plotH;

        const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + plotH);
        grad.addColorStop(0, 'rgba(74,144,164,0.4)');
        grad.addColorStop(1, 'rgba(74,144,164,0.03)');
        ctx.beginPath();
        ctx.moveTo(xS(data[0].d), pad.top + plotH);
        for (const p of data) ctx.lineTo(xS(p.d), yS(p.e));
        ctx.lineTo(xS(maxD), pad.top + plotH);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(xS(data[0].d), yS(data[0].e));
        for (let i = 1; i < data.length; i++) ctx.lineTo(xS(data[i].d), yS(data[i].e));
        ctx.strokeStyle = '#9B6328';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        ctx.font = '10px sans-serif';
        ctx.textAlign = 'right';
        for (let i = 0; i <= 3; i++) {
            const e = minE + (maxE - minE) * i / 3;
            const y = yS(e);
            ctx.strokeStyle = '#efefef'; ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + plotW, y); ctx.stroke();
            ctx.fillStyle = '#B8B5B0';
            ctx.fillText(Math.round(e), pad.left - 3, y + 3);
        }
        ctx.textAlign = 'center'; ctx.fillStyle = '#B8B5B0';
        const nX = Math.min(5, Math.floor(maxD) || 1);
        for (let i = 0; i <= nX; i++) {
            const d = maxD * i / nX;
            ctx.fillText(d.toFixed(1), xS(d), H - 5);
        }
        ctx.fillStyle = '#D4D0C8';
        ctx.textAlign = 'left';  ctx.fillText(eleUnit, 2, pad.top + 8);
        ctx.textAlign = 'right'; ctx.fillText(distUnit, W - 2, H - 5);

        const baseImage = ctx.getImageData(0, 0, canvas.width, canvas.height);
        return { data, pad, plotW, plotH, W, H, dpr, maxD, minE, maxE,
                 baseImage, canvas, ctx, xS, yS, eleUnit, distUnit };
    }

    function setupElevMapHover(state, gpxMap) {
        const { data, pad, plotW, plotH, dpr, maxD,
                baseImage, canvas, ctx, xS, yS, eleUnit, distUnit } = state;
        const note = document.getElementById('elev-note');
        let hoverMarker = null;

        function drawCrosshair(idx) {
            const p = data[idx];
            ctx.putImageData(baseImage, 0, 0);
            const cx = xS(p.d), cy = yS(p.e);
            ctx.save();
            ctx.setLineDash([3, 3]);
            ctx.strokeStyle = 'rgba(28,27,25,0.4)';
            ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.moveTo(cx, pad.top); ctx.lineTo(cx, pad.top + plotH); ctx.stroke();
            ctx.restore();
            ctx.beginPath(); ctx.arc(cx, cy, 4, 0, Math.PI * 2);
            ctx.fillStyle = '#1C1B19'; ctx.fill();
            ctx.strokeStyle = 'white'; ctx.lineWidth = 1.5; ctx.stroke();
            if (note) {
                note.style.display = '';
                note.style.color = '#8C8A86';
                note.textContent = '\u2191 ' + Math.round(p.e) + '\u202f' + eleUnit
                                 + '   \u21a6 ' + p.d.toFixed(2) + '\u202f' + distUnit;
            }
        }

        function clearCrosshair() {
            ctx.putImageData(baseImage, 0, 0);
            if (note) note.style.display = 'none';
        }

        function nearestByDist(targetD) {
            let best = 0, bestDiff = Infinity;
            for (let i = 0; i < data.length; i++) {
                const dd = Math.abs(data[i].d - targetD);
                if (dd < bestDiff) { bestDiff = dd; best = i; }
            }
            return best;
        }

        function nearestByLatLon(lat, lon) {
            let best = 0, bestD2 = Infinity;
            for (let i = 0; i < data.length; i++) {
                const dlat = data[i].lat - lat, dlng = data[i].lon - lon;
                const d2 = dlat*dlat + dlng*dlng;
                if (d2 < bestD2) { bestD2 = d2; best = i; }
            }
            return best;
        }

        canvas.style.cursor = 'crosshair';
        canvas.addEventListener('mousemove', e => {
            const rect = canvas.getBoundingClientRect();
            const mx = (e.clientX - rect.left) * (canvas.width / rect.width) / dpr;
            const frac = Math.max(0, Math.min(1, (mx - pad.left) / plotW));
            const idx = nearestByDist(frac * maxD);
            drawCrosshair(idx);
            const p = data[idx];
            if (!hoverMarker) {
                hoverMarker = L.circleMarker([p.lat, p.lon], {
                    radius: 6, color: '#1C1B19', fillColor: '#1C1B19', fillOpacity: 0.9, weight: 2
                }).addTo(gpxMap);
            } else {
                hoverMarker.setLatLng([p.lat, p.lon]);
            }
        });
        canvas.addEventListener('mouseleave', () => {
            clearCrosshair();
            if (hoverMarker) { gpxMap.removeLayer(hoverMarker); hoverMarker = null; }
        });

        gpxMap.on('mousemove', e => {
            const idx = nearestByLatLon(e.latlng.lat, e.latlng.lng);
            const nearPx = gpxMap.latLngToContainerPoint([data[idx].lat, data[idx].lon]);
            const pixDist = Math.hypot(nearPx.x - e.containerPoint.x, nearPx.y - e.containerPoint.y);
            if (pixDist < 40) {
                drawCrosshair(idx);
            } else {
                clearCrosshair();
            }
        });
        gpxMap.on('mouseout', () => clearCrosshair());
    }
    </script>
</div>
<?php endif; ?>

<!-- CELL COVERAGE & SAFETY -->
<div class="section">
    <div class="section-title">Cell Coverage &amp; Safety</div>

    <?php if ($cell_info): ?>
    <div class="cell-banner" style="background:<?= $cell_info[4] ?>20; border:1px solid <?= $cell_info[4] ?>;">
        <span class="cell-banner-icon"><?= $cell_info[1] ?></span>
        <div class="cell-banner-text">
            <span class="cell-banner-title" style="color:<?= $cell_info[2] ?>;"><?= $cell_info[0] ?></span>
            <span style="color:var(--ink-2);">Let someone know your plans before heading out.</span>
        </div>
    </div>
    <?php else: ?>
    <div class="cell-banner" style="background:var(--bg-2); border:1px solid var(--border);">
        <span class="cell-banner-icon">📵</span>
        <div class="cell-banner-text">
            <span style="color:var(--ink-2);">Cell service coverage is unknown for this route. Let someone know your plans before heading out.</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="safety-box">
        <span class="safety-icon">⚠️</span>
        <p class="safety-text">
            <strong>Hiking &amp; Mountaineering Involves Real Risk.</strong>
            Hiking to mountain summits is physically demanding and inherently dangerous. Conditions can change
            rapidly — weather, terrain, and altitude are serious factors. <strong>Please do not join if you are
            sick, injured, or in poor health.</strong> Know your limits and come prepared with appropriate gear,
            water, and clothing for the conditions.<br><br>
            Always let someone at home know your plans — where you're going, who you're with, and when
            to expect you back. <strong>Share this invitation page</strong> with a friend or family member as
            your trip plan so they know where to look if needed.
        </p>
    </div>

    <?php if (!empty($pa['location_link'])): ?>
    <div class="info-box">
        <span class="info-box-icon">📡</span>
        <div class="info-box-text">
            <strong>Live Group Location</strong>
            The hiking group will be sharing their real-time location for the duration of this trip.
            Share this link with anyone who may need to know where the group is:<br>
            <a href="<?= htmlspecialchars($pa['location_link']) ?>" target="_blank"
               style="font-weight:600; word-break:break-all;"><?= htmlspecialchars($pa['location_link']) ?></a>
        </div>
    </div>
    <?php endif; ?>
</div>

<div style="text-align:center; padding:0.5rem 0 1.5rem; font-size:0.78rem; color:var(--ink-4);">
    Planned by <?= htmlspecialchars($pa['group_name']) ?>
</div>

</div><!-- /page -->

<script>
function openTrailheadDirections(lat, lng) {
    var isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    if (isIOS) {
        window.location = 'maps://maps.apple.com/?daddr=' + lat + ',' + lng + '&dirflg=d';
    } else {
        window.open('https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng + '&travelmode=driving', '_blank');
    }
}
</script>

<!-- WHAT IS SOTA MODAL -->
<div class="modal-overlay" id="sota-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('sota-modal').classList.remove('open')" aria-label="Close">✕</button>
        <h2 style="font-size:1.3rem; font-weight:600; color:var(--ink); margin-bottom:0.25rem;">What is Summits on the Air?</h2>
        <p style="font-size:0.85rem; color:var(--ink-3); margin-bottom:1.25rem;">A worldwide amateur radio activity program</p>
        <p style="font-size:0.9rem; color:var(--ink-2); line-height:1.7; margin-bottom:1.25rem;">
            <strong style="color:var(--ink);">Summits on the Air (SOTA)</strong> is an international amateur radio program where licensed
            operators hike to designated mountain summits and make radio contacts with other stations around the world —
            using only portable, battery-powered equipment they carry up themselves.
        </p>
        <div class="explainer-grid">
            <div class="explainer-card">
                <h4>No license needed to hike</h4>
                <p>You don't need a radio license to join the hike — just come along for the mountain views and fresh air.</p>
            </div>
            <div class="explainer-card">
                <h4>What we do on top</h4>
                <p>We set up a lightweight portable radio and antenna, then make contact with stations across the country — sometimes across oceans.</p>
            </div>
            <div class="explainer-card">
                <h4>Points &amp; awards</h4>
                <p>Each summit is worth points based on elevation. Operators accumulate points toward international awards.</p>
            </div>
            <div class="explainer-card">
                <h4>A global community</h4>
                <p>Chasers around the world listen for activators and make contact. It's like geocaching, but with radio waves.</p>
            </div>
        </div>
        <?php if ($pa['sota_ref']): ?>
        <div style="margin-top:1.25rem; display:flex; gap:8px; flex-wrap:wrap;">
            <a href="https://sotl.as/summits/<?= str_replace('%2F', '/', rawurlencode($pa['sota_ref'])) ?>" target="_blank"
               class="btn btn-ghost" style="font-size:0.82rem;">View on SOTLas ↗</a>
            <a href="https://www.sotadata.org.uk/en/summit/<?= urlencode($pa['sota_ref']) ?>" target="_blank"
               class="btn btn-ghost" style="font-size:0.82rem;">SOTA Database ↗</a>
            <button class="btn btn-primary" style="font-size:0.82rem;" onclick="document.getElementById('sota-modal').classList.remove('open')">Got it</button>
        </div>
        <?php else: ?>
        <div style="margin-top:1.25rem;">
            <button class="btn btn-primary" style="font-size:0.82rem;" onclick="document.getElementById('sota-modal').classList.remove('open')">Got it</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<footer class="invite-footer">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>
</body>
</html>
