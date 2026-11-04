<?php
require_once 'config.php';

$db = getDbConnection();

if (!isset($_GET['id'])) {
    http_response_code(400);
    die('Missing id parameter.');
}

$pa_id = (int)$_GET['id'];

$stmt = $db->prepare("
    SELECT pa.id as pa_id, pa.planned_date, pa.hike_start_time,
           pa.callsigns, pa.activation_duration_min, pa.invitation_message,
           pa.travel_notes,
           s.name as summit_name, s.sota_ref, s.region,
           s.latitude, s.longitude, s.trailhead_lat, s.trailhead_lng,
           s.hike_distance_mi, s.hike_elevation_gain_ft, s.difficulty,
           pg.pace_multiplier,
           pg.name as group_name
    FROM planned_activations pa
    JOIN summits s ON s.id = pa.summit_id
    JOIN planning_groups pg ON pg.id = pa.planning_group_id
    WHERE pa.id = ?
");
$stmt->execute([$pa_id]);
$pa = $stmt->fetch();

if (!$pa) {
    http_response_code(404);
    die('Invitation not found.');
}

// Hike time: same logic as summit_detail.php / activation_invite.php (GPX if enabled, else
// Naismith from the manual numbers; Drive Up has no hike). Stored hike_time_*_min columns are stale.
$stmt2 = $db->prepare("SELECT hiking_time, total_distance, elevation_gain, elevation_loss, track_type, use_for_hike_time, use_for_elevation FROM gpx_tracks WHERE summit_id = (SELECT summit_id FROM planned_activations WHERE id = ?) AND planning_group_id = (SELECT planning_group_id FROM planned_activations WHERE id = ?)");
$stmt2->execute([$pa_id, $pa_id]);
$gpx = $stmt2->fetch();

$track_type = $gpx ? ($gpx['track_type'] ?? 'round-trip') : 'round-trip';
$one_way    = ($track_type === 'ascent' || $track_type === 'descent');
$has_ts     = $gpx && $gpx['hiking_time'] > 0;

if ($gpx && $gpx['use_for_elevation'] && ($gpx['elevation_gain'] ?? 0) > 0) {
    $elev_ft = ($track_type === 'descent' ? $gpx['elevation_loss'] : $gpx['elevation_gain']) * 3.28084;
} else {
    $elev_ft = $pa['hike_elevation_gain_ft'];
}
if ($gpx && $gpx['use_for_hike_time'] && $gpx['total_distance'] > 0) {
    $dist_mi = round($gpx['total_distance'] * ($one_way ? 2 : 1) * 0.621371, 2);
} else {
    $dist_mi = $pa['hike_distance_mi'];
}
if ($gpx && $gpx['use_for_hike_time'] && $has_ts) {
    $total_hike_min = round($gpx['hiking_time'] * ($one_way ? 2 : 1) / 60);
} else {
    $total_hike_min = ($dist_mi || $elev_ft) ? calculateHikeTime($dist_mi ?? 0, $elev_ft ?? 0, (float)($pa['pace_multiplier'] ?? 1.0)) : 0;
}
if (($pa['difficulty'] ?? '') === 'drive-up') $total_hike_min = 0;
$hike_up_min   = (int)round($total_hike_min * 0.6);
$hike_down_min = (int)($total_hike_min - $hike_up_min);

$activation_min = (int)$pa['activation_duration_min'];

// Event starts at hike start, ends after hike down from summit
$dtstart_ts = strtotime($pa['planned_date'] . ' ' . $pa['hike_start_time']);
$dtend_ts   = $dtstart_ts + (($hike_up_min + $activation_min + $hike_down_min) * 60);

// Format timestamps for ICS (UTC — DreamHost servers use UTC or local; use date_create for safety)
function icsDate($timestamp) {
    return gmdate('Ymd\THis\Z', $timestamp);
}

$now = icsDate(time());
$dtstart = icsDate($dtstart_ts);
$dtend   = icsDate($dtend_ts);

// Location: trailhead coordinates only (lets map apps pin the right spot)
$trailhead_lat = $pa['trailhead_lat'] ?: $pa['latitude'];
$trailhead_lng = $pa['trailhead_lng'] ?: $pa['longitude'];
// Comma in LOCATION must be escaped as \, per RFC 5545 so parsers don't split it
$location = ($trailhead_lat && $trailhead_lng) ? $trailhead_lat . '\,' . $trailhead_lng : '';
// GEO uses semicolon separator and is the proper ICS field for coordinates
$geo = ($trailhead_lat && $trailhead_lng) ? $trailhead_lat . ';' . $trailhead_lng : null;

// Build description
$callsigns = $pa['callsigns'];
$desc_lines = [
    'SOTA Activation: ' . $pa['sota_ref'] . ' ' . $pa['summit_name'],
    'Operators: ' . $callsigns,
];
if ($pa['invitation_message']) {
    $desc_lines[] = '';
    $desc_lines[] = $pa['invitation_message'];
}
if ($pa['travel_notes']) {
    $desc_lines[] = '';
    $desc_lines[] = 'Hike Notes: ' . $pa['travel_notes'];
}
$desc_lines[] = '';
$invite_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . '/activation_invite.php?id=' . $pa_id;
$desc_lines[] = 'Invitation page: ' . $invite_url;

// ICS text fold helper — lines must be ≤75 octets, continuation lines start with a space
function icsFold($line) {
    $out = '';
    while (strlen($line) > 75) {
        $out .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    return $out . $line;
}

function icsEscape($str) {
    // Backslashes must be escaped FIRST, before we introduce new backslashes
    // via the \n and \; sequences — otherwise those get double-escaped.
    $str = str_replace('\\', '\\\\', $str);
    $str = str_replace(["\r\n", "\r", "\n"], '\\n', $str);
    $str = str_replace(';', '\\;', $str);
    return $str;
}

$summary = 'SOTA: ' . $pa['summit_name'];
if ($pa['sota_ref']) $summary .= ' (' . $pa['sota_ref'] . ')';

$description = icsEscape(implode("\n", $desc_lines));

$uid = 'sota-activation-' . $pa_id . '@sotaplanner';

$ics = "BEGIN:VCALENDAR\r\n"
     . "VERSION:2.0\r\n"
     . "PRODID:-//SOTA Planner//Activation Invite//EN\r\n"
     . "CALSCALE:GREGORIAN\r\n"
     . "METHOD:PUBLISH\r\n"
     . "BEGIN:VEVENT\r\n"
     . icsFold("UID:" . $uid) . "\r\n"
     . icsFold("DTSTAMP:" . $now) . "\r\n"
     . icsFold("DTSTART:" . $dtstart) . "\r\n"
     . icsFold("DTEND:" . $dtend) . "\r\n"
     . icsFold("SUMMARY:" . icsEscape($summary)) . "\r\n"
     . icsFold("DESCRIPTION:" . $description) . "\r\n"
     . ($location ? icsFold("LOCATION:" . $location) . "\r\n" : '')
     . ($geo      ? icsFold("GEO:" . $geo) . "\r\n" : '')
     . "END:VEVENT\r\n"
     . "END:VCALENDAR\r\n";

$filename = 'sota-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($pa['summit_name'])) . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');
echo $ics;
