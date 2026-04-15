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
           pg.name as group_name, pg.units
    FROM planned_activations pa
    JOIN summits s ON s.id = pa.summit_id
    JOIN planning_groups pg ON pg.id = pa.planning_group_id
    WHERE pa.id = ?
");
$stmt->execute([$pa_id]);
$pa = $stmt->fetch();

if (!$pa) {
    http_response_code(404);
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Invitation Not Found</title>
<style>body{font-family:sans-serif;text-align:center;padding:4rem;color:#333;}</style>
</head>
<body>
    <h1>⛰️ Invitation Not Found</h1>
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
<link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
<style>
body{font-family:'Overpass',sans-serif;text-align:center;padding:4rem;background:#F5F5F0;color:#1E3A5F;}
h1{font-size:2rem;margin-bottom:1rem;}
</style>
</head>
<body>
    <h1>⛰️ This activation has already taken place!</h1>
    <p>Thanks for your interest — <?= htmlspecialchars($pa['callsigns']) ?> completed
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

// Timeline math
$units         = $pa['units'];
$drive_one_way = round(($pa['drive_time_min'] ?? 0) / 2);
$hike_up_min   = (int)($pa['hike_time_up_min'] ?? 0);
$hike_down_min = (int)($pa['hike_time_down_min'] ?? 0);
$activation_min = (int)$pa['activation_duration_min'];

if ($gpx && $gpx['use_for_hike_time'] && $gpx['hiking_time'] > 0) {
    $total_gpx_min = round($gpx['hiking_time'] / 60);
    $hike_up_min   = round($total_gpx_min * 0.6);
    $hike_down_min = $total_gpx_min - $hike_up_min;
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
// drive back = same as drive there

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

// Callsigns as display list
$callsign_list = array_filter(array_map('trim', explode(',', $pa['callsigns'])));

// Cell service label
$cell_labels = [
    'full'         => ['Full Coverage Expected', '📶', '#2E7D32', '#E8F5E9'],
    'intermittent' => ['Intermittent Signal',    '📶', '#E65100', '#FFF3E0'],
    'summit_only'  => ['Coverage at Summit Only', '📶', '#1565C0', '#E3F2FD'],
    'none'         => ['No Cell Service Expected','📵', '#C62828', '#FDECEA'],
];
$cell_info = isset($cell_labels[$pa['cell_service']]) ? $cell_labels[$pa['cell_service']] : null;

// Distance/elevation display
if ($units === 'metric') {
    $dist_display = $pa['hike_distance_mi'] ? round($pa['hike_distance_mi'] * 1.60934, 1) . ' km' : null;
    $gain_display = $pa['hike_elevation_gain_ft'] ? round($pa['hike_elevation_gain_ft'] * 0.3048) . ' m gain' : null;
    $elev_display = $pa['elevation_m'] ? number_format($pa['elevation_m']) . ' m' : null;
} else {
    $dist_display = $pa['hike_distance_mi'] ? $pa['hike_distance_mi'] . ' mi' : null;
    $gain_display = $pa['hike_elevation_gain_ft'] ? number_format($pa['hike_elevation_gain_ft']) . ' ft gain' : null;
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
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
            --gold: #E6B84A;
            --tan:  #D4A574;
            --snow: #F5F5F0;
            --green: #2E7D32;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, var(--snow) 0%, #E8E4D8 100%);
            color: var(--navy);
            min-height: 100vh;
        }

        /* ---- HERO ---- */
        .hero {
            background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%);
            color: white;
            padding: 3rem 2rem 2.5rem;
            text-align: center;
        }
        .hero-eyebrow {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            opacity: 0.8;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }
        .hero h1 {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 0.5rem;
        }
        .hero-sub {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }
        .hero-date {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 50px;
            padding: 0.6rem 1.5rem;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        .callsigns-row {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 0.5rem;
        }
        .callsign-badge {
            background: var(--gold);
            color: var(--navy);
            border-radius: 6px;
            padding: 0.4rem 0.9rem;
            font-weight: 800;
            font-size: 1rem;
            letter-spacing: 0.05em;
        }

        /* ---- LAYOUT ---- */
        .container {
            max-width: 860px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        .card {
            background: white;
            border-radius: 14px;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .card h2 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--snow);
        }

        /* ---- STATS ROW ---- */
        .stats-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .stat-pill {
            background: var(--snow);
            border-radius: 8px;
            padding: 0.6rem 1.1rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--navy);
            white-space: nowrap;
        }
        .stat-pill span { opacity: 0.65; font-weight: 400; margin-right: 0.35rem; }

        /* ---- GANTT ---- */
        .gantt-wrap { margin-bottom: 1rem; }
        .gantt-bar {
            display: flex;
            border-radius: 8px;
            overflow: hidden;
            height: 52px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }
        .gantt-seg {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            transition: flex 0.3s;
            padding: 0 4px;
        }
        .gantt-seg .seg-label { font-size: 0.7rem; font-weight: 700; text-align: center; line-height: 1.2; white-space: nowrap; }
        .gantt-seg .seg-time  { font-size: 0.65rem; opacity: 0.85; margin-top: 2px; white-space: nowrap; }

        .seg-drive    { background: #8D6E63; color: white; }
        .seg-hike-up  { background: #43A047; color: white; }
        .seg-radio    { background: var(--navy); color: white; }
        .seg-hike-down{ background: #66BB6A; color: white; }
        .seg-drive-back{ background: #8D6E63; color: white; }

        .gantt-milestones {
            display: flex;
            position: relative;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #555;
        }
        .milestone {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            transform: translateX(-50%);
        }
        .milestone-dot {
            width: 8px; height: 8px;
            background: var(--navy);
            border-radius: 50%;
            margin-bottom: 3px;
        }
        .milestone-time { font-weight: 700; color: var(--navy); font-size: 0.8rem; }
        .milestone-label { color: #666; font-size: 0.7rem; text-align: center; white-space: nowrap; }
        .gantt-milestones-container {
            position: relative;
            height: 55px;
            margin-top: 0.25rem;
        }

        /* ---- LEGEND ---- */
        .gantt-legend {
            display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem;
            margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #eee;
        }
        .legend-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; }
        .legend-dot { width: 12px; height: 12px; border-radius: 3px; }

        /* ---- GUEST DRIVE FORM ---- */
        .guest-form { background: #E8F4F8; border-radius: 10px; padding: 1.25rem; border: 2px solid var(--teal); }
        .guest-form input[type="text"] {
            width: 100%; padding: 0.7rem; border: 2px solid #ccc;
            border-radius: 6px; font-family: 'Overpass', sans-serif;
            font-size: 1rem; margin: 0.5rem 0 0.75rem;
        }
        .btn {
            display: inline-block; padding: 0.7rem 1.5rem;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: white; border: none; border-radius: 6px;
            font-weight: 700; cursor: pointer; font-size: 0.9rem;
            text-decoration: none; font-family: 'Overpass', sans-serif;
            transition: all 0.2s;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .btn-green { background: linear-gradient(135deg, #43A047, #1B5E20); }
        .btn-gold  { background: linear-gradient(135deg, var(--gold), var(--tan)); color: var(--navy); }

        /* ---- CELL SERVICE BANNER ---- */
        .cell-banner {
            display: flex; align-items: center; gap: 0.75rem;
            border-radius: 8px; padding: 0.85rem 1.1rem;
            font-weight: 600; font-size: 0.95rem; margin-bottom: 1rem;
        }

        /* ---- WHAT IS SOTA MODAL ---- */
        .sota-explainer { background: #E8F4F8; border-radius: 10px; padding: 1.25rem; }
        .sota-explainer p { line-height: 1.7; color: #333; font-size: 0.95rem; }
        .modal-backdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 1000;
            align-items: center; justify-content: center; padding: 1rem;
        }
        .modal-backdrop.open { display: flex; }
        .modal-box {
            background: white; border-radius: 14px; padding: 2rem;
            max-width: 620px; width: 100%; max-height: 90vh; overflow-y: auto;
            position: relative; box-shadow: 0 8px 40px rgba(0,0,0,0.3);
        }
        .modal-close {
            position: absolute; top: 1rem; right: 1rem;
            background: none; border: none; font-size: 1.4rem;
            cursor: pointer; color: #999; line-height: 1;
        }
        .modal-close:hover { color: #333; }
        .btn-outline-white {
            display: inline-block; padding: 0.45rem 1.1rem;
            border: 2px solid rgba(255,255,255,0.7); border-radius: 50px;
            color: white; font-weight: 700; font-size: 0.82rem;
            cursor: pointer; background: rgba(255,255,255,0.1);
            font-family: 'Overpass', sans-serif; letter-spacing: 0.03em;
            transition: all 0.2s; text-decoration: none;
        }
        .btn-outline-white:hover { background: rgba(255,255,255,0.25); }

        /* ---- QUICK FACTS ---- */
        .quick-facts {
            display: flex; flex-wrap: nowrap; gap: 0.75rem;
            overflow-x: auto; padding-bottom: 0.25rem;
        }
        .fact-box {
            background: var(--snow); border-radius: 8px; padding: 1rem;
            text-align: center; border-top: 3px solid var(--teal);
            flex: 1; min-width: 110px;
        }
        .fact-box.clickable {
            cursor: pointer; border-top-color: var(--gold);
            transition: all 0.2s;
        }
        .fact-box.clickable:hover { background: #FFF8E1; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .fact-box.guest-calculated { border-top-color: #43A047; background: #F1F8F1; }
        .fact-icon { font-size: 1.6rem; margin-bottom: 0.3rem; }
        .fact-value { font-size: 1.1rem; font-weight: 800; color: var(--navy); }
        .fact-label { font-size: 0.72rem; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.2rem; }

        /* ---- RESULT BOX ---- */
        .result-box {
            background: #E8F5E9; border-radius: 10px; padding: 1.25rem;
            border: 2px solid #43A047; margin-top: 1rem;
        }
        .result-box h3 { color: #1B5E20; margin-bottom: 0.75rem; }

        @media (max-width: 600px) {
            .hero h1 { font-size: 1.75rem; }
            .gantt-seg .seg-label { font-size: 0.6rem; }
            .gantt-seg .seg-time  { display: none; }
            .milestone-label { display: none; }
            .milestone-time { font-size: 0.7rem; }
        }
    </style>
</head>
<body>

<!-- HERO -->
<div class="hero">
    <div class="hero-eyebrow">You're Invited</div>
    <h1>⛰️ <?= htmlspecialchars($pa['summit_name']) ?></h1>
    <div class="hero-sub">
        <?= htmlspecialchars($pa['sota_ref']) ?>
        <?php if ($pa['region']): ?> &nbsp;·&nbsp; <?= htmlspecialchars($pa['region']) ?><?php endif; ?>
    </div>
    <div class="hero-date">
        <?= date('l, F j, Y', strtotime($pa['planned_date'])) ?>
        &nbsp;·&nbsp; Hike starts <?= date('g:i A', strtotime($pa['hike_start_time'])) ?>
    </div>
    <div style="margin-bottom:1.5rem; display:flex; justify-content:center; flex-wrap:wrap; gap:0.6rem;">
        <a href="activation_ics.php?id=<?= $pa['pa_id'] ?>" class="btn-outline-white" style="font-size:0.85rem;">
            📅 Add to Calendar
        </a>
        <?php if ($guest_directions_url): ?>
            <a href="<?= htmlspecialchars($guest_directions_url) ?>" target="_blank" class="btn-outline-white" style="font-size:0.85rem; background:rgba(67,160,71,0.35); border-color:rgba(255,255,255,0.9);">
                🗺️ Get Directions
            </a>
        <?php else: ?>
            <button class="btn-outline-white" style="font-size:0.85rem;" onclick="document.getElementById('drive-section').scrollIntoView({behavior:'smooth'}); setTimeout(()=>document.getElementById('guest-address-input').focus({preventScroll:true}),400);">
                🗺️ Get Directions
            </button>
        <?php endif; ?>
    </div>
    <div style="margin-bottom: 0.5rem; font-size: 0.9rem; opacity: 0.8;">Activating operators:</div>
    <div class="callsigns-row">
        <?php foreach ($callsign_list as $cs): ?>
            <span class="callsign-badge"><?= htmlspecialchars($cs) ?></span>
        <?php endforeach; ?>
    </div>
    <p style="margin-top: 1.25rem; font-size: 1rem; opacity: 0.88; max-width: 560px; margin-left: auto; margin-right: auto; line-height: 1.6;">
        You're invited to join us for a day on the mountain! We'll be hiking to the summit and making
        amateur radio contacts with stations around the world — no radio license needed to tag along.
        Lace up your hiking boots and come enjoy the views.
    </p>
    <div style="margin-top: 1.25rem;">
        <button class="btn-outline-white" onclick="document.getElementById('sota-modal').classList.add('open')">
            📻 What is Summits on the Air?
        </button>
    </div>
</div>

<div class="container">

<?php if ($pa['invitation_message'] || $pa['travel_notes']): ?>
    <!-- Custom Invitation Message + Travel Notes -->
    <div class="card" style="border-left: 5px solid var(--gold); background: #FFFDE7;">
        <?php if ($pa['invitation_message']): ?>
        <p style="font-size: 1.05rem; line-height: 1.75; color: #333; margin-bottom: <?= $pa['travel_notes'] ? '1rem' : '0' ?>;">
            <?= nl2br(htmlspecialchars($pa['invitation_message'])) ?>
        </p>
        <?php endif; ?>
        <?php if ($pa['travel_notes']): ?>
        <div style="<?= $pa['invitation_message'] ? 'border-top: 1px solid #f0d060; padding-top: 0.85rem;' : '' ?>">
            <div style="font-weight:700; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:#7a6000; margin-bottom:0.4rem;">🅿️ Parking &amp; Travel Info</div>
            <div style="font-size:0.95rem; line-height:1.7; color:#333;"><?= nl2br(htmlspecialchars($pa['travel_notes'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- DAY AT A GLANCE -->
<div class="card">
    <h2>🗓️ Day at a Glance</h2>

    <!-- Quick facts — always one row -->
    <div class="quick-facts" style="margin-bottom: 1rem;">

        <?php
        $guest_back_home_ts = $guest_drive_min !== null ? $back_trailhead_ts + ($guest_drive_min * 60) : null;
        ?>

        <!-- Leave Home tile: guest calculated > CTA -->
        <?php if ($guest_leave_ts): ?>
            <div class="fact-box guest-calculated">
                <div class="fact-icon">🚗</div>
                <div class="fact-value"><?= date('g:i A', $guest_leave_ts) ?></div>
                <div class="fact-label">Your Leave Time</div>
                <a href="<?= htmlspecialchars($guest_directions_url) ?>" target="_blank"
                   style="display:inline-block; margin-top:0.4rem; font-size:0.7rem; font-weight:700;
                          background:#1B5E20; color:white; padding:0.2rem 0.5rem; border-radius:4px;
                          text-decoration:none;">🗺️ Directions</a>
            </div>
        <?php else: ?>
            <div class="fact-box clickable" onclick="document.getElementById('drive-section').scrollIntoView({behavior:'smooth'}); document.getElementById('guest-address-input').focus({preventScroll:true});">
                <div class="fact-icon">🚗</div>
                <div class="fact-value" style="font-size:0.85rem; color: var(--teal);">Calculate<br>My Drive</div>
                <div class="fact-label">Tap to personalize</div>
            </div>
        <?php endif; ?>

        <div class="fact-box">
            <div class="fact-icon">🥾</div>
            <div class="fact-value"><?= date('g:i A', $hike_start_ts) ?></div>
            <div class="fact-label">Hike Start</div>
        </div>

        <?php if ($hike_up_min > 0): ?>
        <div class="fact-box">
            <div class="fact-icon">⛰️</div>
            <div class="fact-value"><?= date('g:i A', $at_summit_ts) ?></div>
            <div class="fact-label">At Summit</div>
        </div>
        <?php endif; ?>

        <div class="fact-box">
            <div class="fact-icon">📻</div>
            <div class="fact-value"><?= date('g:i A', $at_summit_ts) ?>–<?= date('g:i A', $radio_done_ts) ?></div>
            <div class="fact-label">Radio Time</div>
        </div>

        <!-- Back Home tile: guest calculated > CTA -->
        <?php if ($guest_back_home_ts): ?>
            <div class="fact-box guest-calculated">
                <div class="fact-icon">🏠</div>
                <div class="fact-value">~<?= date('g:i A', $guest_back_home_ts) ?></div>
                <div class="fact-label">Your Return</div>
            </div>
        <?php else: ?>
            <div class="fact-box clickable" onclick="document.getElementById('drive-section').scrollIntoView({behavior:'smooth'}); document.getElementById('guest-address-input').focus({preventScroll:true});">
                <div class="fact-icon">🏠</div>
                <div class="fact-value" style="font-size:0.85rem; color: var(--teal);">Calculate<br>My Drive</div>
                <div class="fact-label">Tap to personalize</div>
            </div>
        <?php endif; ?>

    </div>
    <?php if ($guest_leave_ts): ?>
        <p style="font-size: 0.8rem; color: #2E7D32; margin-bottom: 1.25rem; font-weight: 600;">✓ Showing your personalized times based on: <?= htmlspecialchars($guest_address) ?></p>
    <?php endif; ?>

    <!-- GANTT BAR -->
    <?php if ($total_min > 0): ?>
    <div class="gantt-wrap">
        <div class="gantt-bar">
            <?php if ($drive_pct > 0): ?>
            <div class="gantt-seg seg-drive" style="flex: <?= $drive_pct ?>;">
                <span class="seg-label">🚗 Drive</span>
                <span class="seg-time"><?= $gantt_drive ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($hike_up_pct > 0): ?>
            <div class="gantt-seg seg-hike-up" style="flex: <?= $hike_up_pct ?>;">
                <span class="seg-label">🥾 Hike Up</span>
                <span class="seg-time"><?= $hike_up_min ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($activation_pct > 0): ?>
            <div class="gantt-seg seg-radio" style="flex: <?= $activation_pct ?>;">
                <span class="seg-label">📻 Radio</span>
                <span class="seg-time"><?= $activation_min ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($hike_down_pct > 0): ?>
            <div class="gantt-seg seg-hike-down" style="flex: <?= $hike_down_pct ?>;">
                <span class="seg-label">🥾 Hike Down</span>
                <span class="seg-time"><?= $hike_down_min ?>m</span>
            </div>
            <?php endif; ?>
            <?php if ($drive_pct > 0): ?>
            <div class="gantt-seg seg-drive-back" style="flex: <?= $drive_pct ?>;">
                <span class="seg-label">🚗 Drive</span>
                <span class="seg-time"><?= $gantt_drive ?>m</span>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Milestone positions (cumulative %)
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
        <div class="legend-item"><div class="legend-dot" style="background:#8D6E63;"></div> Drive (<?= $gantt_drive ?> min each way — your estimate)</div>
        <?php endif; ?>
        <?php if ($hike_up_pct > 0): ?>
        <div class="legend-item"><div class="legend-dot" style="background:#43A047;"></div> Hike Up (<?= $hike_up_min ?> min)</div>
        <?php endif; ?>
        <div class="legend-item"><div class="legend-dot" style="background:var(--navy);"></div> Radio / SOTA Activation (<?= $activation_min ?> min)</div>
        <?php if ($hike_down_pct > 0): ?>
        <div class="legend-item"><div class="legend-dot" style="background:#66BB6A;"></div> Hike Down (<?= $hike_down_min ?> min)</div>
        <?php endif; ?>
        <?php if ($show_drive): ?>
        <div class="legend-item"><div class="legend-dot" style="background:#555;"></div> Total Day: ~<?= formatTime($total_min) ?></div>
        <?php else: ?>
        <div class="legend-item" style="color:var(--teal); cursor:pointer;" onclick="document.getElementById('drive-section').scrollIntoView({behavior:'smooth'}); document.getElementById('guest-address-input').focus({preventScroll:true});">
            🚗 <em>Add your address below to include drive time</em>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Hike Stats -->
    <?php if ($elev_display || $dist_display || $gain_display || $pa['difficulty'] || $pa['points']): ?>
    <div class="stats-row" style="margin-top:1.25rem; padding-top:1.1rem; border-top:1px solid #eee;">
        <?php if ($elev_display): ?>
            <div class="stat-pill"><span>Elevation</span><?= $elev_display ?></div>
        <?php endif; ?>
        <?php if ($dist_display): ?>
            <div class="stat-pill"><span>Round-trip</span><?= $dist_display ?></div>
        <?php endif; ?>
        <?php if ($gain_display): ?>
            <div class="stat-pill"><span>Gain</span><?= $gain_display ?></div>
        <?php endif; ?>
        <?php if ($pa['difficulty'] && isset($difficulty_labels[$pa['difficulty']])): ?>
            <div class="stat-pill"><span>Difficulty</span><?= $difficulty_labels[$pa['difficulty']] ?></div>
        <?php endif; ?>
        <?php if ($pa['points']): ?>
            <div class="stat-pill"><span>SOTA pts</span><?= $pa['points'] ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Summit links -->
    <?php if ($pa['trail_link'] || $pa['sotlas_link'] || ($pa['latitude'] && $pa['longitude'])): ?>
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
        <?php if ($pa['trail_link']): ?>
            <a href="<?= htmlspecialchars($pa['trail_link']) ?>" target="_blank" class="btn btn-gold">🥾 Trail Info</a>
        <?php endif; ?>
        <?php if ($pa['latitude'] && $pa['longitude']): ?>
            <a href="https://www.google.com/maps/search/?api=1&query=<?= $pa['latitude'] ?>,<?= $pa['longitude'] ?>" target="_blank" class="btn btn-gold">🗺️ Summit Map</a>
        <?php endif; ?>
        <?php if ($pa['sotlas_link']): ?>
            <a href="<?= htmlspecialchars($pa['sotlas_link']) ?>" target="_blank" class="btn btn-gold">📡 SOTA Info</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- GUEST DRIVE TIME -->
<div class="card" id="drive-section">
    <h2>🏠 Your Drive Time</h2>
    <p style="color:#555; margin-bottom:1rem;">Enter your address to get a personalized departure time and driving directions to the trailhead using Google Maps</p>

    <div class="guest-form">
        <form method="POST">
            <label style="font-weight:700; font-size:0.9rem;">Your starting address (be specific for the best results)</label>
            <input type="text" name="guest_address" id="guest-address-input"
                   value="<?= htmlspecialchars($guest_address) ?>"
                   placeholder="1234 Main St, Los Angeles, CA">
            <button type="submit" class="btn">Calculate My Drive Time</button>
        </form>
    </div>

    <?php if ($guest_error): ?>
        <div style="color:#C62828; margin-top:1rem; font-weight:600;">⚠️ <?= htmlspecialchars($guest_error) ?></div>
    <?php endif; ?>

    <?php if ($guest_drive_min !== null): ?>
        <div style="margin-top:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
            <span style="font-size:0.95rem; color:#1B5E20; font-weight:600;">
                ✅ <?= formatTime($guest_drive_min) ?> drive — your times are updated above.
            </span>
            <a href="<?= htmlspecialchars($guest_directions_url) ?>" target="_blank" class="btn btn-green">
                🗺️ Get Driving Directions
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- CELL COVERAGE & SAFETY -->
<div class="card">
    <h2>📶 Cell Coverage &amp; Safety Info</h2>

    <?php if ($cell_info): ?>
    <div class="cell-banner" style="background: <?= $cell_info[3] ?>; color: <?= $cell_info[2] ?>;">
        <span style="font-size:1.3rem;"><?= $cell_info[1] ?></span>
        <div>
            <strong>Cell Service:</strong> <?= $cell_info[0] ?><br>
            <span style="font-size:0.82rem; font-weight:400;">Let someone know your plans before heading out.</span>
        </div>
    </div>
    <?php else: ?>
    <div class="cell-banner" style="background:#F5F5F0; color:#555;">
        <span style="font-size:1.3rem;">📵</span>
        <div>Cell service coverage is unknown for this route. Let someone know your plans before heading out.</div>
    </div>
    <?php endif; ?>

    <div style="background:#FFF8E1; border-radius:8px; padding:1rem 1.1rem; border-left:4px solid #F9A825; display:flex; gap:0.85rem; align-items:flex-start;">
        <span style="font-size:1.4rem; flex-shrink:0;">⚠️</span>
        <div style="font-size:0.9rem; line-height:1.6; color:#1a1a2e;">
            <strong>Safety: Hiking &amp; Mountaineering Involves Real Risk</strong><br>
            Hiking to mountain summits is physically demanding and inherently dangerous. Conditions can change
            rapidly — weather, terrain, and altitude are serious factors. <strong>Please do not join if you are
            sick, injured, or in poor health.</strong> Know your limits and come prepared with appropriate gear,
            water, and clothing for the conditions.<br><br>
            Always let someone at home know your plans — where you're going, who you're with, and when
            to expect you back. <strong>Share this invitation page</strong> with a friend or family member as
            your trip plan so they know where to look if needed.
        </div>
    </div>

    <?php if (!empty($pa['location_link'])): ?>
    <div style="background:#EEF4FB; border-radius:8px; padding:1rem 1.1rem; border-left:4px solid #1565C0; display:flex; gap:0.85rem; align-items:flex-start; margin-top:0.75rem;">
        <span style="font-size:1.4rem; flex-shrink:0;">📡</span>
        <div style="font-size:0.9rem; line-height:1.6; color:#1a1a2e;">
            <strong>Live Group Location</strong><br>
            The hiking group will be sharing their real-time location for the duration of this trip.
            Share this link with anyone who may need to know where the group is:<br>
            <a href="<?= htmlspecialchars($pa['location_link']) ?>" target="_blank"
               style="color:#1565C0; font-weight:700; word-break:break-all;"><?= htmlspecialchars($pa['location_link']) ?></a>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($gpx): ?>
<!-- HIKE MAP -->
<div class="card">
    <h2>🗺️ Hike Route</h2>

    <!-- Map controls -->
    <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.75rem; align-items:center;">
        <span style="font-size:0.75rem; color:#888;">Map:</span>
        <button onclick="switchBase('street')" id="btn-base-street"
                style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #1E3A5F;
                       background:#1E3A5F; color:white; font-weight:700; font-size:0.78rem;
                       cursor:pointer; transition:all 0.2s;">Street</button>
        <button onclick="switchBase('topo')" id="btn-base-topo"
                style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #1E3A5F;
                       background:white; color:#1E3A5F; font-weight:700; font-size:0.78rem;
                       cursor:pointer; transition:all 0.2s;">Topo</button>
        <button onclick="switchBase('satellite')" id="btn-base-satellite"
                style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #1E3A5F;
                       background:white; color:#1E3A5F; font-weight:700; font-size:0.78rem;
                       cursor:pointer; transition:all 0.2s;">Satellite</button>

        <?php if ($az_polygon_json): ?>
        <span style="color:#ddd; font-size:0.75rem;">|</span>
        <button onclick="zoomToActivationZone()" id="btn-actzone"
                style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #CC2200;
                       background:#CC2200; color:white; font-weight:700; font-size:0.78rem;
                       cursor:pointer; transition:all 0.2s;">🏔 Zoom to Activation Zone</button>
        <?php endif; ?>

        <span style="color:#ddd; font-size:0.75rem;">|</span>
        <span style="font-size:0.75rem; color:#888;">Cell Coverage:</span>
        <button onclick="toggleCarrier('tmobile')" id="btn-tmobile"
                style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #E91E8C;
                       background:white; color:#E91E8C; font-weight:700; font-size:0.78rem;
                       cursor:pointer; transition:all 0.2s;">T-Mobile</button>
        <button onclick="toggleCarrier('verizon')" id="btn-verizon"
                style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #CD040B;
                       background:white; color:#CD040B; font-weight:700; font-size:0.78rem;
                       cursor:pointer; transition:all 0.2s;">Verizon</button>
        <button onclick="toggleCarrier('att')" id="btn-att"
                style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #00A8E0;
                       background:white; color:#00A8E0; font-weight:700; font-size:0.78rem;
                       cursor:pointer; transition:all 0.2s;">AT&T</button>
        <span style="font-size:0.72rem; color:#999; margin-left:0.1rem;">Cell data may be optimistic in mountainous terrain</span>

        <?php if ($gpx): ?>
        <span style="color:#ddd; font-size:0.75rem;">|</span>
        <a href="load_gpx.php?id=<?= $gpx['id'] ?>" download="<?= htmlspecialchars($gpx_download_name) ?>"
           style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #4A7C59;
                  background:#4A7C59; color:white; font-weight:700; font-size:0.78rem;
                  text-decoration:none; display:inline-block; line-height:1.4;">⬇ Download GPX</a>
        <?php endif; ?>
    </div>

    <div id="gpx-map" style="height: 420px; border-radius: 10px; border: 2px solid #ddd;"></div>
    <p style="font-size:0.72rem; color:#aaa; margin-top:0.5rem;">
        Coverage data: FCC Form 477 filings (2021), via ArcGIS public tile service. Carrier-reported estimates — actual signal in mountainous terrain may differ.
    </p>

    <!-- Elevation Profile -->
    <div style="background:#f8f9fa; border-radius:8px; padding:0.6rem 0.75rem 0.4rem; margin-top:0.75rem; border:1px solid #e8e8e8;">
        <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#aaa; font-weight:600; margin-bottom:0.3rem;">Elevation Profile</div>
        <canvas id="elev-canvas" style="width:100%; height:120px; display:block;"></canvas>
        <div id="elev-note" style="font-size:0.72rem; color:#bbb; margin-top:0.25rem; text-align:center; display:none;"></div>
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
        // Keep carrier overlays on top
        Object.values(carrierLayers).forEach(l => { if (map.hasLayer(l)) l.bringToFront(); });
        ['street','topo','satellite'].forEach(n => {
            const b = document.getElementById('btn-base-' + n);
            if (!b) return;
            b.style.background = n === name ? '#1E3A5F' : 'white';
            b.style.color      = n === name ? 'white'   : '#1E3A5F';
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
            btn.style.background = 'white';
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

    // ── GPX track ─────────────────────────────────────────────────────────────
    fetch('load_gpx.php?id=<?= $gpx['id'] ?>')
        .then(r => r.text())
        .then(gpxText => {
            const parser = new DOMParser();
            const gpxDoc = parser.parseFromString(gpxText, 'text/xml');
            const pts = gpxDoc.querySelectorAll('trkpt, rtept');
            const coords = [];
            const elevPts = []; // [lat, lon, ele_m]
            pts.forEach(pt => {
                const lat = parseFloat(pt.getAttribute('lat'));
                const lon = parseFloat(pt.getAttribute('lon'));
                coords.push([lat, lon]);
                const ele = pt.querySelector('ele');
                if (ele) elevPts.push([lat, lon, parseFloat(ele.textContent)]);
            });

            if (coords.length === 0) return;

            gpxPolyline = L.polyline(coords, {color: '#4A90A4', weight: 4, opacity: 0.85}).addTo(map);

            // Trailhead marker
            L.marker(coords[0], {
                icon: L.divIcon({
                    html: '<div style="background:#43A047;color:white;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:15px;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3)">🅿️</div>',
                    iconSize:[28,28], className:''
                })
            }).bindPopup('Trailhead').addTo(map);

            // Summit marker
            L.marker([<?= $gpx['summit_lat'] ?>, <?= $gpx['summit_lon'] ?>], {
                icon: L.divIcon({
                    html: '<div style="background:#E6B84A;color:white;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:18px;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3)">⛰️</div>',
                    iconSize:[32,32], className:''
                })
            }).bindPopup('<?= htmlspecialchars(addslashes($pa['summit_name'])) ?><br><?= htmlspecialchars($pa['sota_ref']) ?>').addTo(map);

            map.fitBounds(L.polyline(coords).getBounds(), {padding:[40,40]});

            const elevState = drawElevationProfile(elevPts);
            if (elevState) setupElevMapHover(elevState, map);
        });

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
        const H = 120;
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
        grad.addColorStop(0, 'rgba(74,144,164,0.5)');
        grad.addColorStop(1, 'rgba(74,144,164,0.04)');
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
        ctx.strokeStyle = '#4A90A4';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        ctx.font = '10px sans-serif';
        ctx.textAlign = 'right';
        for (let i = 0; i <= 3; i++) {
            const e = minE + (maxE - minE) * i / 3;
            const y = yS(e);
            ctx.strokeStyle = '#efefef'; ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + plotW, y); ctx.stroke();
            ctx.fillStyle = '#aaa';
            ctx.fillText(Math.round(e), pad.left - 3, y + 3);
        }
        ctx.textAlign = 'center'; ctx.fillStyle = '#aaa';
        const nX = Math.min(5, Math.floor(maxD) || 1);
        for (let i = 0; i <= nX; i++) {
            const d = maxD * i / nX;
            ctx.fillText(d.toFixed(1), xS(d), H - 5);
        }
        ctx.fillStyle = '#ccc';
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
            ctx.strokeStyle = 'rgba(230,160,32,0.9)';
            ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.moveTo(cx, pad.top); ctx.lineTo(cx, pad.top + plotH); ctx.stroke();
            ctx.restore();
            ctx.beginPath(); ctx.arc(cx, cy, 4, 0, Math.PI * 2);
            ctx.fillStyle = '#E6A020'; ctx.fill();
            ctx.strokeStyle = 'white'; ctx.lineWidth = 1.5; ctx.stroke();
            if (note) {
                note.style.display = '';
                note.style.color = '#555';
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
                    radius: 6, color: '#E6A020', fillColor: '#E6A020', fillOpacity: 0.9, weight: 2
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

<!-- FOOTER -->
<div style="text-align:center; color:#999; font-size:0.8rem; padding: 1rem 0 2rem;">
    Planned by <?= htmlspecialchars($pa['group_name']) ?> &nbsp;·&nbsp; SOTA Planner
</div>

</div><!-- /container -->

<!-- WHAT IS SOTA MODAL -->
<div class="modal-backdrop" id="sota-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('sota-modal').classList.remove('open')" aria-label="Close">✕</button>
        <h2 style="font-size:1.4rem; font-weight:800; color:var(--navy); margin-bottom:1.25rem;">📻 What is SOTA?</h2>
        <div class="sota-explainer">
            <p>
                <strong>Summits on the Air (SOTA)</strong> is an international amateur radio program where licensed
                operators hike to designated mountain summits and make radio contacts with other stations around the world —
                using only portable, battery-powered equipment they carry up themselves.
            </p>
            <p style="margin-top:0.75rem;">
                When you join this hike, you'll get to witness the radio operation at the summit!
                The operators will set up a lightweight antenna, then call out over the airwaves and log contacts
                from other amateur radio enthusiasts who are listening and responding.
                On a clear day, contacts can be made hundreds or even thousands of miles away.
            </p>
            <p style="margin-top:0.75rem;">
                <strong>You don't need to be a licensed ham operator to tag along</strong> — just bring good hiking boots,
                water, snacks, layers for the summit, and a sense of adventure!
            </p>
        </div>
        <?php if ($pa['sota_ref']): ?>
        <p style="margin-top:1rem; font-size:0.85rem; color:#666;">
            Learn more about this summit at
            <a href="https://sotl.as/summits/<?= str_replace('%2F', '/', rawurlencode($pa['sota_ref'])) ?>" target="_blank"
               style="color:var(--teal); font-weight:600;">SOTLas</a>
            or the
            <a href="https://www.sotadata.org.uk/en/summit/<?= urlencode($pa['sota_ref']) ?>" target="_blank"
               style="color:var(--teal); font-weight:600;">SOTA database</a>.
        </p>
        <?php endif; ?>
    </div>
</div>

<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:#aaa; font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:#aaa; text-decoration:none;">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:#aaa; text-decoration:none;">sotaplanner.com</a>
</footer>
</body>
</html>
