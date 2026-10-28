<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(180);

require_once 'config.php';
require_once __DIR__ . '/lib/fpdf.php';
require_once __DIR__ . '/multi_alt_lib.php';
require_once __DIR__ . '/pdf_map_lib.php';
require_once __DIR__ . '/weather_lib.php';
session_start();
requireLogin();

$db = getDbConnection();
$current_group = getCurrentPlanningGroup($db);
if (!$current_group) { header('Location: index.php'); exit; }
$user_units = getUserUnits($db);

// ── Resolve which summits, in what order (read-only, this page never mutates
// the saved route; it always renders exactly the ids/order passed in) ──
$multi_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$multi_name = '';
if ($multi_id) {
    $stmt = $db->prepare("SELECT name FROM multi_activations WHERE id = ? AND planning_group_id = ?");
    $stmt->execute([$multi_id, $current_group['id']]);
    $row = $stmt->fetch();
    if ($row) $multi_name = $row['name'] ?? '';
}

$ids_param = $_GET['ids'] ?? '';
$summit_ids = array_values(array_filter(array_map('intval', explode(',', $ids_param))));
if (count($summit_ids) < 2) { header('Location: index.php'); exit; }

$placeholders = implode(',', array_fill(0, count($summit_ids), '?'));
$stmt = $db->prepare("
    SELECT s.*,
           g.hiking_time AS gpx_hiking_time, g.elevation_gain AS gpx_elevation_gain,
           g.elevation_loss AS gpx_elevation_loss, g.total_distance AS gpx_total_distance,
           g.track_type, g.use_for_hike_time, g.use_for_elevation, g.file_path AS gpx_file_path
    FROM summits s
    LEFT JOIN gpx_tracks g ON g.summit_id = s.id AND g.planning_group_id = ?
    WHERE s.id IN ($placeholders) AND s.planning_group_id = ?
");
$stmt->execute(array_merge([$current_group['id']], $summit_ids, [$current_group['id']]));
$summits_by_id = [];
foreach ($stmt->fetchAll() as $r) $summits_by_id[$r['id']] = $r;
$summit_ids = array_values(array_filter($summit_ids, fn($id) => isset($summits_by_id[$id])));
if (count($summit_ids) < 2) { header('Location: index.php'); exit; }

if (!isset($_SESSION['multi_drive_cache']) || !is_array($_SESSION['multi_drive_cache'])) $_SESSION['multi_drive_cache'] = [];
if (!isset($_SESSION['multi_directions_cache']) || !is_array($_SESSION['multi_directions_cache'])) $_SESSION['multi_directions_cache'] = [];

$selected_address = getSelectedAddress($db);
$origin_geo = null;
if ($selected_address) {
    $origin_geo = addressGeo($db, $selected_address);
}

$activation_time_min = isset($_GET['activation_time']) ? max(10, min(240, (int)$_GET['activation_time'])) : 30;
$start_time_min = isset($_GET['start_time']) ? (((int)$_GET['start_time'] % 1440) + 1440) % 1440 : 7 * 60;

// ── Stops (mirrors multi_activate.php's build) ──
$stops = [];
foreach ($summit_ids as $sid) {
    $s = $summits_by_id[$sid];
    $track_type = $s['track_type'] ?? 'round-trip';
    $one_way = ($track_type === 'ascent' || $track_type === 'descent');
    $has_ts = ($s['gpx_hiking_time'] ?? 0) > 0;

    if ($s['use_for_elevation'] && $s['gpx_elevation_gain']) {
        $elev = ($track_type === 'descent') ? ($s['gpx_elevation_loss'] ?? 0) * 3.28084 : $s['gpx_elevation_gain'] * 3.28084;
    } else {
        $elev = $s['hike_elevation_gain_ft'];
    }
    if ($s['use_for_hike_time'] && ($s['gpx_total_distance'] ?? 0) > 0) {
        $dist = $s['gpx_total_distance'] * ($one_way ? 2 : 1) * 0.621371;
    } else {
        $dist = $s['hike_distance_mi'];
    }
    if ($s['use_for_hike_time'] && $has_ts) {
        $secs = $s['gpx_hiking_time'] * ($one_way ? 2 : 1);
        $hike_min = round($secs / 60);
    } else {
        $hike_min = ($dist || $elev) ? calculateHikeTime($dist ?? 0, $elev ?? 0, $current_group['pace_multiplier'] ?? 1.0) : 0;
    }
    $is_drive_up = ($s['difficulty'] === 'drive-up');
    if ($is_drive_up) $hike_min = 0;

    $lat = $s['trailhead_lat'] ?? $s['latitude'];
    $lng = $s['trailhead_lng'] ?? $s['longitude'];

    $stops[] = [
        'id' => (int)$s['id'], 'name' => $s['name'], 'ref' => $s['sota_ref'],
        'points' => (int)$s['points'], 'difficulty' => $s['difficulty'], 'status' => $s['status'],
        'lat' => $lat, 'lng' => $lng,
        'summit_lat' => (float)$s['latitude'], 'summit_lng' => (float)$s['longitude'],
        'elevation_m' => $s['elevation_m'],
        'hike_min' => (int)$hike_min, 'dist_mi' => $dist, 'elev_ft' => $elev,
        'is_drive_up' => $is_drive_up,
        'gpx_file' => $s['gpx_file_path'] ?? null, 'track_type' => $track_type,
        'has_trailhead' => ($s['trailhead_lat'] !== null && $s['trailhead_lng'] !== null),
    ];
}

// Alternate hike starts (see multi_alt_lib.php): rewrites hike numbers in place
$alt_lookup = multi_alt_lookup($db, $stops, getCurrentCallsign());
multi_alt_apply($stops, $alt_lookup['active'], $current_group['pace_multiplier'] ?? 1.0);

// ── Per-leg drive times + distances: reuses the same session cache the
// interactive page warms, so regenerating the PDF right after viewing the
// route costs no extra Distance Matrix calls. ──
$drive_cache = &$_SESSION['multi_drive_cache'];
$get_leg = function ($fromKey, $fromCoord, $toKey, $toCoord) use (&$drive_cache) {
    if ($fromCoord === null || $toCoord === null) return ['min' => null, 'mi' => null];
    $k = $fromKey . '>' . $toKey;
    if (array_key_exists($k, $drive_cache) && is_array($drive_cache[$k])) return $drive_cache[$k];
    $distance_mi = null;
    $t = calculateDriveTimeBetween($fromCoord, $toCoord, $distance_mi);
    $result = ['min' => $t, 'mi' => $distance_mi];
    $drive_cache[$k] = $result;
    return $result;
};

$origin_coord = $origin_geo ? ($origin_geo['lat'] . ',' . $origin_geo['lng']) : null;
$origin_key   = $selected_address ? ('addr:' . $current_group['id']) : 'noaddr';

$leg_times = []; $leg_distances_mi = []; $leg_coords = [];
$prev_key = $origin_key; $prev_coord = $origin_coord;
$prev_name = $selected_address['label'] ?? $selected_address['address'] ?? 'Start';
$leg_from_name = [];
foreach ($stops as $i => $stop) {
    $leg_from_name[$i] = $prev_name;
    if ($stop['chained']) {
        // Reached on foot from the previous summit: the car doesn't move
        $leg_times[$i] = 0; $leg_distances_mi[$i] = 0;
        $leg_coords[$i] = ['from' => null, 'to' => null, 'from_key' => null, 'to_key' => null];
        continue;
    }
    $to_coord = ($stop['lat'] !== null && $stop['lng'] !== null) ? ($stop['lat'] . ',' . $stop['lng']) : null;
    $leg = $get_leg($prev_key, $prev_coord, 'summit:' . $stop['id'], $to_coord);
    $leg_times[$i] = $leg['min'];
    $leg_distances_mi[$i] = $leg['mi'];
    $leg_coords[$i] = ['from' => $prev_coord, 'to' => $to_coord, 'from_key' => $prev_key, 'to_key' => 'summit:' . $stop['id']];
    $prev_key = 'summit:' . $stop['id'];
    $prev_coord = $to_coord;
    $prev_name = $stop['name'];
}
$return_leg = $get_leg($prev_key, $prev_coord, $origin_key, $origin_coord);
$return_leg_time = $return_leg['min'];
$return_leg_distance_mi = $return_leg['mi'];
$return_leg_coords = ['from' => $prev_coord, 'to' => $origin_coord, 'from_key' => $prev_key, 'to_key' => $origin_key];

// ── Timeline (segments + milestones), same shape as the interactive Gantt ──
$LEG_COLORS = ['#2B5CA0', '#7B4FA0', '#1B8A8A', '#C08A20', '#B03A6B', '#4A56C4', '#8A5A2E', '#5C7080', '#7A7A2E', '#D46A35', '#2E9FBF', '#A83E5C'];
$leg_color_i = 0;
$legs = []; // ordered list of drive legs for the Directions section + overview map path
$milestones = [['short' => 'Depart', 'full' => 'Depart', 'min' => 0]];
$elapsed = 0;
foreach ($stops as $i => $stop) {
    if ($leg_times[$i]) {
        $color = $LEG_COLORS[$leg_color_i % count($LEG_COLORS)]; $leg_color_i++;
        $from_label = $leg_from_name[$i];
        $legs[] = [
            'label' => $from_label . ' → ' . $stop['name'], 'color' => $color,
            'from' => $leg_coords[$i]['from'], 'to' => $leg_coords[$i]['to'],
            'from_key' => $leg_coords[$i]['from_key'], 'to_key' => $leg_coords[$i]['to_key'],
            'min' => $leg_times[$i], 'mi' => $leg_distances_mi[$i], 'stop_i' => $i,
        ];
        $elapsed += $leg_times[$i];
    }
    $milestones[] = ['short' => 'Arrive', 'full' => 'Arrive at ' . $stop['name'], 'min' => $elapsed, 'stop_i' => $i];
    if ($stop['up_min'] > 0) {
        $elapsed += $stop['up_min'];
        $milestones[] = ['short' => 'On Summit', 'full' => 'On summit: ' . $stop['name'], 'min' => $elapsed, 'stop_i' => $i];
    }
    $elapsed += $activation_time_min;
    $elapsed += $stop['down_min'];
    $milestones[] = ['short' => 'Depart', 'full' => 'Depart ' . $stop['name'], 'min' => $elapsed, 'stop_i' => $i];
}
if ($return_leg_time) {
    $color = $LEG_COLORS[$leg_color_i % count($LEG_COLORS)]; $leg_color_i++;
    $legs[] = [
        'label' => $prev_name . ' → Home', 'color' => $color,
        'from' => $return_leg_coords['from'], 'to' => $return_leg_coords['to'],
        'from_key' => $return_leg_coords['from_key'], 'to_key' => $return_leg_coords['to_key'],
        'min' => $return_leg_time, 'mi' => $return_leg_distance_mi, 'stop_i' => null,
    ];
    $elapsed += $return_leg_time;
}
$milestones[] = ['short' => 'Home', 'full' => 'Arrive home', 'min' => $elapsed, 'stop_i' => null];
$total_min = $elapsed;

$total_points_sum = array_sum(array_column($stops, 'points'));
$total_dist_mi_sum = array_sum(array_map(fn($s) => $s['dist_mi'] ?? 0, $stops));
$total_elev_ft_sum = array_sum(array_map(fn($s) => $s['elev_ft'] ?? 0, $stops));
$total_drive_dist_mi_sum = array_sum(array_filter($leg_distances_mi)) + ($return_leg_distance_mi ?: 0);

// ── Map data shared by the overview and per-summit maps (same sources as the
// on-screen pages: GPX trail, activation zone, OSRM road geometry) ──
foreach ($stops as $i => $stop) {
    // A summit only gets its community GPX linked once its detail page is opened,
    // so fall back to the shared library track (read-only) for the map.
    $gpx_file = $stop['gpx_file'];
    if (!$gpx_file && !empty($stop['ref'])) {
        $gl = $db->prepare("SELECT file_path FROM global_gpx_tracks WHERE sota_ref = ?");
        $gl->execute([$stop['ref']]);
        $gpx_file = $gl->fetchColumn() ?: null;
    }
    $stops[$i]['gpx_pts'] = pdfmap_gpx_points($gpx_file, 1500);
    $az_row = get_activation_zone_from_api($stop['ref'], $stop['summit_lat'], $stop['summit_lng'], $stop['elevation_m']);
    $stops[$i]['az'] = $az_row;
    $stops[$i]['az_ring'] = ($az_row && !empty($az_row['polygon'])) ? pdfmap_az_ring($az_row['polygon']) : [];
}
foreach ($legs as $li => $leg) {
    $legs[$li]['geom'] = pdfmap_osrm_route($leg['from'], $leg['to']);
}

function multi_clock_label($minutes_from_midnight) {
    $m = (($minutes_from_midnight % 1440) + 1440) % 1440;
    $days = intdiv($minutes_from_midnight, 1440);
    $label = date('g:i A', mktime(0, $m, 0));
    if ($days > 0) $label .= ' (+' . $days . 'd)';
    return $label;
}

// ══════════════════════════════════════════════════════════════════════════
// PDF-only helpers
// ══════════════════════════════════════════════════════════════════════════

// FPDF's core fonts only support Windows-1252, so transliterate UTF-8 input
// (summit names, addresses, arrows) down to that so nothing throws or garbles.
function pdftext($s) {
    if ($s === null) return '';
    $s = str_replace(["\xE2\x86\x92" /* → */], '->', $s);
    $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $s);
}

// Weather block for one summit's close-up page: today's conditions, an hour-by-hour table across the
// planned time on that summit, and the next three days. Same Open-Meteo data (elevation adjusted)
// as the weather widget on the summit page. Draws nothing if no forecast is available.
function pdf_weather_block($pdf, $page_w, $w, $units, $arrive_clock_min, $depart_clock_min) {
    $d = $w['daily'] ?? null; $h = $w['hourly'] ?? null;
    if (!$d || !$h || empty($d['time'])) return;

    $date = $d['time'][0];
    // Hours of today covering the planned time on the summit (clock minutes since midnight), at least 3 rows
    $from_h = max(0, min(23, intdiv((int)$arrive_clock_min, 60)));
    $to_h   = max($from_h + 2, min(23, intdiv((int)$depart_clock_min, 60)));
    $to_h   = min(23, $to_h);
    $rows = [];
    foreach ($h['time'] as $k => $t) {
        if (substr($t, 0, 10) !== $date) continue;
        $hr = (int)substr($t, 11, 2);
        if ($hr >= $from_h && $hr <= $to_h) $rows[] = $k;
    }

    $need = 1.7 + 0.2 * (count($rows) + 1);
    if ($pdf->GetY() + $need > 10.3) $pdf->AddPage();

    $pdf->Ln(0.12);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 0.2, 'Weather at summit', 0, 1);

    [$label] = weather_wmo($d['weather_code'][0]);
    $snow = $d['snowfall_sum'][0] ?? 0;
    $storm = false;
    foreach ($rows as $k) if (($h['cape'][$k] ?? 0) >= 500 || $h['weather_code'][$k] >= 95) $storm = true;
    $pp = $d['precipitation_probability_max'][0] ?? null;

    $pdf->SetFont('Helvetica', '', 9);
    $line1 = 'Today (' . date('D M j', strtotime($date)) . '): ' . $label . ', high ' . weather_temp($d['temperature_2m_max'][0], $units)
        . ', low ' . weather_temp($d['temperature_2m_min'][0], $units)
        . '. Wind up to ' . weather_speed($d['wind_speed_10m_max'][0], $units) . ', gusts ' . weather_speed($d['wind_gusts_10m_max'][0], $units) . '.';
    $pdf->MultiCell($page_w, 0.19, pdftext($line1));
    $line2 = ($pp !== null ? 'Rain chance ' . (int)$pp . '%, ' : '') . 'precipitation ' . weather_precip($d['precipitation_sum'][0], $units)
        . ($snow > 0.1 ? ', snow ' . weather_snow($snow, $units) : '');
    if (!empty($d['sunrise'][0]) && !empty($d['sunset'][0])) {
        $line2 .= '. Sunrise ' . date('g:i A', strtotime($d['sunrise'][0])) . ', sunset ' . date('g:i A', strtotime($d['sunset'][0]));
    }
    $pdf->MultiCell($page_w, 0.19, pdftext($line2 . '.'));
    if ($storm) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(192, 48, 48);
        $pdf->Cell(0, 0.2, 'Storm risk during this window. Check the latest forecast before you go.', 0, 1);
        $pdf->SetTextColor(28, 27, 25);
    }

    if ($rows) {
        $pdf->Ln(0.05);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(140, 138, 134);
        $pdf->Cell(0, 0.18, pdftext('Hour by hour across your planned time on this summit, if you go today (local summit time).'), 0, 1);
        $pdf->SetTextColor(28, 27, 25);
        $cols = [['Time', 0.8], ['Conditions', 1.5], ['Temp', 0.7], ['Feels', 0.7], ['Wind', 0.85], ['Gusts', 0.85], ['Rain %', 0.7], ['Precip', $page_w - 6.1]];
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(239, 237, 232);
        foreach ($cols as $c) $pdf->Cell($c[1], 0.2, $c[0], 0, 0, 'L', true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', 8.5);
        foreach ($rows as $k) {
            [$hl] = weather_wmo($h['weather_code'][$k]);
            $cells = [
                date('g A', strtotime($h['time'][$k])), $hl,
                weather_temp($h['temperature_2m'][$k], $units), weather_temp($h['apparent_temperature'][$k], $units),
                weather_speed($h['wind_speed_10m'][$k], $units), weather_speed($h['wind_gusts_10m'][$k], $units),
                isset($h['precipitation_probability'][$k]) ? (int)$h['precipitation_probability'][$k] . '%' : '-',
                weather_precip($h['precipitation'][$k], $units),
            ];
            foreach ($cells as $ci => $txt) $pdf->Cell($cols[$ci][1], 0.2, pdftext($txt));
            $pdf->Ln();
        }
    }

    // Next three days, same short outlook as the widget
    $pdf->Ln(0.08);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(239, 237, 232);
    foreach ([['Outlook', 0.8], ['Conditions', 1.5], ['High / Low', 1.4], ['Wind', 1.3], ['Gusts', $page_w - 5.0]] as $c) $pdf->Cell($c[1], 0.2, $c[0], 0, 0, 'L', true);
    $pdf->Ln();
    $pdf->SetFont('Helvetica', '', 8.5);
    for ($i = 1; $i <= 3 && $i < count($d['time']); $i++) {
        [$dl] = weather_wmo($d['weather_code'][$i]);
        $cells = [
            date('D M j', strtotime($d['time'][$i])), $dl,
            weather_temp($d['temperature_2m_max'][$i], $units) . ' / ' . weather_temp($d['temperature_2m_min'][$i], $units),
            weather_speed($d['wind_speed_10m_max'][$i] ?? $d['wind_gusts_10m_max'][$i] ?? 0, $units),
            weather_speed($d['wind_gusts_10m_max'][$i] ?? 0, $units),
        ];
        $wids = [0.8, 1.5, 1.4, 1.3, $page_w - 5.0];
        foreach ($cells as $ci => $txt) $pdf->Cell($wids[$ci], 0.2, pdftext($txt));
        $pdf->Ln();
    }
    $pdf->SetFont('Helvetica', 'I', 7.5);
    $pdf->SetTextColor(140, 138, 134);
    $pdf->Cell(0, 0.18, 'Weather data by Open-Meteo.com. Forecast as of ' . date('M j, g:i A') . '; adjusted to the summit elevation.', 0, 1);
    $pdf->SetTextColor(28, 27, 25);
}

function hex2rgb($hex) {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

// Single alphanumeric marker label Static Maps will accept: 1-9 then A,B for
// the rare 10th/11th stop (MULTI_MAX is 11).
function leg_label_char($zero_based_index) {
    $n = $zero_based_index + 1;
    return $n <= 9 ? (string)$n : chr(ord('A') + ($n - 10));
}

// GeoJSON polygon coordinates come as [[lon,lat],...] optionally wrapped in a
// ring array, so normalize to a flat, downsampled list of "lat,lng" pairs.
function az_ring_to_latlng($polygon, $max_points = 40) {
    if (!$polygon) return [];
    $ring = (isset($polygon[0][0]) && is_array($polygon[0][0])) ? $polygon[0] : $polygon;
    if (!is_array($ring) || count($ring) < 3) return [];
    $stride = max(1, (int)ceil(count($ring) / $max_points));
    $pairs = [];
    foreach ($ring as $i => $pt) {
        if ($i % $stride !== 0) continue;
        if (!isset($pt[0], $pt[1])) continue;
        $pairs[] = round($pt[1], 6) . ',' . round($pt[0], 6); // GeoJSON is [lon,lat] -> flip
    }
    return $pairs;
}

// Fetches a Google Static Maps image to a temp file. $markers/$paths are
// already-encoded parameter strings (one array entry per repeated param).
// Returns a local file path (caller must unlink) or null on any failure.
$PDF_TMP_FILES = [];
function fetch_static_map($markers, $paths, $size = '640x400', $scale = 2, $maptype = 'roadmap') {
    global $PDF_TMP_FILES;
    if (!defined('GOOGLE_MAPS_API_KEY') || GOOGLE_MAPS_API_KEY === 'YOUR_API_KEY_HERE') return null;
    $qs = 'size=' . urlencode($size) . '&scale=' . $scale . '&maptype=' . urlencode($maptype) . '&format=png';
    foreach ($markers as $m) $qs .= '&markers=' . urlencode($m);
    foreach ($paths as $p) $qs .= '&path=' . urlencode($p);
    $qs .= '&key=' . GOOGLE_MAPS_API_KEY;
    $url = 'https://maps.googleapis.com/maps/api/staticmap?' . $qs;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $bytes = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($http_code !== 200 || !$bytes || strpos((string)$content_type, 'image/') !== 0) {
        error_log("SOTA Maps Static Maps failed: http=$http_code type=$content_type");
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'sotapdf_') . '.png';
    file_put_contents($tmp, $bytes);
    $PDF_TMP_FILES[] = $tmp;
    return $tmp;
}

// The Maps Static API silently caps each dimension of the "size" param at
// 640px on our plan. A request like size=1280x456 comes back as a real
// 640x456 PNG with no error, a different aspect ratio than we asked for. So
// rather than trust the requested size, measure the file we actually got and
// derive the height that keeps it undistorted at the width we're drawing it.
function static_map_display_height($img_path, $display_w, $fallback_h) {
    $info = @getimagesize($img_path);
    if ($info && $info[0] > 0 && $info[1] > 0) {
        return $display_w * $info[1] / $info[0];
    }
    return $fallback_h;
}

function cleanup_pdf_tmp_files() {
    global $PDF_TMP_FILES;
    foreach ($PDF_TMP_FILES as $f) { if (file_exists($f)) @unlink($f); }
}
register_shutdown_function('cleanup_pdf_tmp_files');

// Turn-by-turn directions for one leg, cached per session so re-downloading
// the PDF doesn't re-bill the Directions API for legs that haven't changed.
function get_leg_directions($from_key, $from_coord, $to_key, $to_coord) {
    if (!$from_coord || !$to_coord) return null;
    $cache = &$_SESSION['multi_directions_cache'];
    $k = $from_key . '>' . $to_key;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $result = getDirectionsSteps($from_coord, $to_coord);
    $cache[$k] = $result;
    return $result;
}

// ══════════════════════════════════════════════════════════════════════════
// PDF document
// ══════════════════════════════════════════════════════════════════════════

class SotaPDF extends FPDF {
    public $docTitle = '';
    function Header() {
        if ($this->PageNo() === 1) return; // cover page has its own custom banner
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(140, 138, 134);
        $this->Cell(0, 0.25, pdftext('SOTAplanner · ' . $this->docTitle), 0, 1, 'R');
        $this->SetDrawColor(229, 226, 218);
        $this->Line(0.6, 0.55, $this->GetPageWidth() - 0.6, 0.55);
        $this->SetY(0.7);
    }
    function Footer() {
        $this->SetY(-0.55);
        $this->SetDrawColor(229, 226, 218);
        $this->Line(0.6, $this->GetY(), $this->GetPageWidth() - 0.6, $this->GetY());
        $this->SetY(-0.45);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(140, 138, 134);
        $this->Cell(0, 0.25, pdftext('Offline reference only. Verify conditions before you go.'), 0, 0, 'L');
        $this->Cell(0, 0.25, 'Page ' . $this->PageNo() . ' / {nb}', 0, 0, 'R');
    }
}

$route_title = $multi_name ?: (count($stops) . '-Summit Route');
$pdf = new SotaPDF('P', 'in', 'Letter');
$pdf->docTitle = $route_title;
$pdf->AliasNbPages();
$pdf->SetMargins(0.6, 0.6, 0.6);
$pdf->SetAutoPageBreak(true, 0.7);
$page_w = 8.5 - 1.2; // printable width between margins

// ── Cover page ──────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->SetFillColor(28, 27, 25);
$pdf->Rect(0, 0, 8.5, 1.35, 'F');
$pdf->SetY(0.35);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 20);
$pdf->Cell(0, 0.4, pdftext($route_title), 0, 1, 'L');
$pdf->SetX(0.6);
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(220, 218, 214);
$subtitle = count($stops) . ' summits · ' . formatTime($total_min) . ' total, door-to-door';
$pdf->Cell(0, 0.3, pdftext($subtitle), 0, 1, 'L');
$pdf->SetX(0.6);
$pdf->SetFont('Helvetica', '', 8.5);
$pdf->Cell(0, 0.25, pdftext('Generated ' . date('F j, Y \a\t g:i A') . ' · ' . $current_group['name']), 0, 1, 'L');

$pdf->SetY(1.65);
$pdf->SetTextColor(28, 27, 25);
if ($selected_address) {
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(1.1, 0.22, 'Starting from:');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 0.22, pdftext($selected_address['label'] ?? $selected_address['address']), 0, 1);
} else {
    $pdf->SetFont('Helvetica', 'I', 9);
    $pdf->SetTextColor(192, 112, 32);
    $pdf->Cell(0, 0.22, pdftext('No starting address on file. Travel times below are between summits only.'), 0, 1);
    $pdf->SetTextColor(28, 27, 25);
}
$pdf->Ln(0.1);

// Stat tiles
$stat_labels = ['Points', 'Hike Distance', 'Drive Distance', 'Total Time'];
$stat_vals = [
    (string)($total_points_sum ?: '-'),
    $total_dist_mi_sum ? number_format(convertDistance($total_dist_mi_sum, $user_units), 1) . ' ' . getDistanceUnit($user_units) : '-',
    $total_drive_dist_mi_sum ? number_format(convertDistance($total_drive_dist_mi_sum, $user_units), 1) . ' ' . getDistanceUnit($user_units) : '-',
    $total_min ? formatTime($total_min) : '-',
];
$stat_subs = [
    count($stops) . ' summit' . (count($stops) !== 1 ? 's' : ''),
    $total_elev_ft_sum ? number_format(convertElevation($total_elev_ft_sum, $user_units)) . ' ' . getElevationUnit($user_units) . ' gain' : '',
    'round trip',
    'doorstep to doorstep',
];
$tile_w = $page_w / 4;
$tile_y = $pdf->GetY();
for ($i = 0; $i < 4; $i++) {
    $x = 0.6 + $tile_w * $i;
    $pdf->SetXY($x, $tile_y);
    $pdf->SetDrawColor(229, 226, 218);
    $pdf->Rect($x, $tile_y, $tile_w - 0.08, 0.85);
    $pdf->SetXY($x + 0.08, $tile_y + 0.08);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(140, 138, 134);
    $pdf->Cell($tile_w - 0.2, 0.15, strtoupper(pdftext($stat_labels[$i])));
    $pdf->SetXY($x + 0.08, $tile_y + 0.28);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(28, 27, 25);
    $pdf->Cell($tile_w - 0.2, 0.25, pdftext($stat_vals[$i]));
    $pdf->SetXY($x + 0.08, $tile_y + 0.58);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(140, 138, 134);
    $pdf->Cell($tile_w - 0.2, 0.2, pdftext($stat_subs[$i]));
}
$pdf->SetY($tile_y + 1.0);

// Overview map: markers for the address (if any) + every stop, auto-fit by
// Static Maps to whatever bounding box contains them; one colored path per leg.
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->SetTextColor(28, 27, 25);
$pdf->Cell(0, 0.25, 'Route Overview', 0, 1);
// Same picture as the Multi-Activate page: road routes, GPX trails, activation
// zones and numbered stops on OpenStreetMap. Falls back to a Google Static Maps
// image (straight lines) only if the map tiles can't be fetched.
$overview_h_in = 4.4;
$overview_img = null;
{
    $fit = []; $lines = []; $polys = []; $mk = [];
    if ($origin_geo) { $fit[] = [(float)$origin_geo['lat'], (float)$origin_geo['lng']]; $mk[] = ['lat' => (float)$origin_geo['lat'], 'lng' => (float)$origin_geo['lng'], 'color' => '#1C1B19', 'text' => 'H']; }
    foreach ($stops as $stop) {
        if (!empty($stop['az_ring'])) $polys[] = ['pts' => $stop['az_ring'], 'color' => '#CC2200', 'fill' => 0.15, 'weight' => 2];
        if (!empty($stop['gpx_pts'])) $lines[] = ['pts' => $stop['gpx_pts'], 'color' => '#2d7a4f', 'weight' => 3];
        if (!empty($stop['alt']) && ($stop['alt']['mode'] ?? '') !== 'return') {
            $lines[] = ['pts' => [[$stop['alt']['lat'], $stop['alt']['lng']], [$stop['summit_lat'], $stop['summit_lng']]], 'color' => '#2D8653', 'weight' => 3, 'dash' => true];
        }
    }
    foreach ($legs as $leg) {
        if (!$leg['from'] || !$leg['to']) continue;
        $straight = [array_map('floatval', explode(',', $leg['from'])), array_map('floatval', explode(',', $leg['to']))];
        $pts = !empty($leg['geom']) ? $leg['geom'] : $straight;
        $lines[] = ['pts' => $pts, 'color' => $leg['color'], 'weight' => 4, 'dash' => empty($leg['geom'])];
        foreach ($pts as $p) $fit[] = $p;
    }
    // One marker per distinct summit; a summit visited twice shows "1 & 3"
    $groups = [];
    foreach ($stops as $i => $stop) {
        $chained = !empty($stop['chained']);
        $mlat = $chained ? $stop['summit_lat'] : $stop['lat'];
        $mlng = $chained ? $stop['summit_lng'] : $stop['lng'];
        if ($mlat === null || $mlng === null) continue;
        $groups[$stop['id']]['orders'][] = $i + 1;
        $groups[$stop['id']]['lat'] = (float)$mlat; $groups[$stop['id']]['lng'] = (float)$mlng;
        $groups[$stop['id']]['color'] = $LEG_COLORS[$i % count($LEG_COLORS)];
        $fit[] = [(float)$mlat, (float)$mlng];
        if (!empty($stop['alt']) && ($stop['alt']['mode'] ?? '') !== 'return') {
            $fit[] = [(float)$stop['alt']['lat'], (float)$stop['alt']['lng']];
            $mk[] = ['lat' => (float)$stop['alt']['lat'], 'lng' => (float)$stop['alt']['lng'], 'color' => '#2D8653', 'text' => (string)($i + 1), 'r' => 11];
        }
    }
    foreach ($groups as $g) $mk[] = ['lat' => $g['lat'], 'lng' => $g['lng'], 'color' => '#1C1B19', 'text' => implode(' & ', $g['orders'])];
    $ov_w = 1200;
    $overview_path = $fit ? pdfmap_render([
        'w' => $ov_w, 'h' => (int)round($ov_w * $overview_h_in / $page_w), 'style' => 'street', 'fit' => $fit,
        'min_pad' => 50, 'max_zoom' => 15, 'polygons' => $polys, 'lines' => $lines, 'markers' => $mk,
    ]) : null;
    if ($overview_path) { $PDF_TMP_FILES[] = $overview_path; $overview_img = $overview_path; }
}
$overview_type = 'JPG';
if (!$overview_img) {
    $markers = []; $paths = [];
    if ($origin_geo) $markers[] = 'color:0x1C1B19|label:H|' . $origin_geo['lat'] . ',' . $origin_geo['lng'];
    foreach ($stops as $i => $stop) {
        if ($stop['lat'] === null || $stop['lng'] === null) continue;
        $markers[] = 'color:' . static_map_marker_hex($LEG_COLORS[$i % count($LEG_COLORS)]) . '|label:' . leg_label_char($i) . '|' . $stop['lat'] . ',' . $stop['lng'];
    }
    foreach ($legs as $leg) {
        if (!$leg['from'] || !$leg['to']) continue;
        $paths[] = 'color:' . static_map_hex($leg['color']) . '|weight:4|' . $leg['from'] . '|' . $leg['to'];
    }
    $overview_size_w = 640;
    $overview_img = fetch_static_map($markers, $paths, $overview_size_w . 'x' . (int)round($overview_size_w * $overview_h_in / $page_w), 2, 'roadmap');
    $overview_type = 'PNG';
}
if ($overview_img) {
    $overview_draw_h = static_map_display_height($overview_img, $page_w, $overview_h_in);
    $pdf->Image($overview_img, 0.6, $pdf->GetY(), $page_w, $overview_draw_h, $overview_type);
    $pdf->SetY($pdf->GetY() + $overview_draw_h + 0.05);
    $pdf->SetFont('Helvetica', 'I', 7.5);
    $pdf->SetTextColor(140, 138, 134);
    $pdf->Cell(0, 0.16, pdftext('Colored lines are the driving legs, green lines are hiking trails, red areas are activation zones.'), 0, 1);
    $pdf->SetTextColor(28, 27, 25);
    $pdf->Ln(0.05);
} else {
    $pdf->SetFont('Helvetica', 'I', 9);
    $pdf->SetTextColor(192, 48, 48);
    $pdf->MultiCell($page_w, 0.22, pdftext('Map image unavailable.'));
    $pdf->SetTextColor(28, 27, 25);
    $pdf->Ln(0.1);
}

// Leg legend
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 0.2, 'Legs', 0, 1);
$pdf->SetFont('Helvetica', '', 8.5);
foreach ($legs as $leg) {
    [$r, $g, $b] = hex2rgb($leg['color']);
    $pdf->SetFillColor($r, $g, $b);
    $y = $pdf->GetY();
    $pdf->Rect(0.6, $y + 0.03, 0.14, 0.1, 'F');
    $pdf->SetX(0.85);
    $detail = ($leg['mi'] ? number_format(convertDistance($leg['mi'], $user_units), 1) . ' ' . getDistanceUnit($user_units) . ', ' : '') . formatTime($leg['min']);
    $pdf->Cell($page_w - 0.25, 0.18, pdftext($leg['label'] . '   (' . $detail . ')'), 0, 1);
}

// ── Function: static map color helper (defined after use above via hoisting is
// not available in PHP for closures assigned to vars, but plain functions
// declared anywhere in the file are hoisted, see below) ──
// Path colors accept an 8-digit hex with alpha; marker colors accept only a
// plain 6-digit hex (no alpha). Static Maps rejects/ignores the marker style
// if given 8 digits, so these are deliberately separate helpers.
function static_map_hex($hex) {
    return '0x' . ltrim($hex, '#') . 'FF';
}
function static_map_marker_hex($hex) {
    return '0x' . ltrim($hex, '#');
}

// ── Schedule table ─────────────────────────────────────────────────────────
$pdf->AddPage();
$pdf->SetFont('Helvetica', 'B', 14);
$pdf->Cell(0, 0.3, 'Schedule', 0, 1);
$pdf->SetFont('Helvetica', 'I', 8.5);
$pdf->SetTextColor(140, 138, 134);
$pdf->MultiCell($page_w, 0.18, pdftext('Clock times assume a ' . multi_clock_label($start_time_min) . ' start and ' . $activation_time_min . ' min on the air per summit. Treat them as planning estimates, not a guarantee.'));
$pdf->SetTextColor(28, 27, 25);
$pdf->Ln(0.05);

function schedule_header($pdf, $page_w) {
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetFillColor(239, 237, 232);
    $cols = [
        ['#', 0.35], ['Summit', $page_w - 0.35 - 0.7 - 1.0 - 1.15 - 1.15], ['Pts', 0.7],
        ['Arrive', 1.15], ['Depart', 1.15],
    ];
    foreach ($cols as $c) $pdf->Cell($c[1], 0.22, $c[0], 0, 0, 'L', true);
    $pdf->Ln();
}
schedule_header($pdf, $page_w);
$pdf->SetFont('Helvetica', '', 8.5);
foreach ($stops as $i => $stop) {
    if ($pdf->GetY() > 10.0) { $pdf->AddPage(); schedule_header($pdf, $page_w); $pdf->SetFont('Helvetica', '', 8.5); }
    $arrive_min = null; $depart_min = null;
    foreach ($milestones as $m) {
        if (($m['stop_i'] ?? null) === $i && $m['short'] === 'Arrive') $arrive_min = $m['min'];
        if (($m['stop_i'] ?? null) === $i && $m['short'] === 'Depart') $depart_min = $m['min'];
    }
    $name_col = $page_w - 0.35 - 0.7 - 1.0 - 1.15 - 1.15;
    $pdf->Cell(0.35, 0.24, (string)($i + 1));
    $pdf->Cell($name_col, 0.24, pdftext($stop['name'] . ' (' . $stop['ref'] . ')'));
    $pdf->Cell(0.7, 0.24, (string)$stop['points']);
    $pdf->Cell(1.15, 0.24, $arrive_min !== null ? multi_clock_label($start_time_min + $arrive_min) : '-');
    $pdf->Cell(1.15, 0.24, $depart_min !== null ? multi_clock_label($start_time_min + $depart_min) : '-');
    $pdf->Ln();
}
$pdf->Ln(0.15);
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetFillColor(239, 237, 232);
foreach ([['#', 0.35], ['Summit', $page_w - 0.35 - 1.1 - 1.1 - 1.4], ['Hike (RT)', 1.1], ['Difficulty', 1.1], ['Drive here', 1.4]] as $c) {
    $pdf->Cell($c[1], 0.22, $c[0], 0, 0, 'L', true);
}
$pdf->Ln();
$pdf->SetFont('Helvetica', '', 8.5);
foreach ($stops as $i => $stop) {
    if ($pdf->GetY() > 10.0) { $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 8.5); }
    $name_col = $page_w - 0.35 - 1.1 - 1.1 - 1.4;
    $pdf->Cell(0.35, 0.24, (string)($i + 1));
    $pdf->Cell($name_col, 0.24, pdftext($stop['name']));
    $pdf->Cell(1.1, 0.24, pdftext($stop['is_drive_up'] ? 'Drive-up' : ($stop['hike_min'] ? formatTime($stop['hike_min']) : '-')));
    $pdf->Cell(1.1, 0.24, pdftext($stop['difficulty'] ? ucwords(str_replace('-', ' ', $stop['difficulty'])) : '-'));
    $pdf->Cell(1.4, 0.24, pdftext($leg_times[$i] ? formatTime($leg_times[$i]) . ($leg_distances_mi[$i] ? ' / ' . number_format(convertDistance($leg_distances_mi[$i], $user_units), 1) . ' ' . getDistanceUnit($user_units) : '') : '-'));
    $pdf->Ln();
}

// ── Driving directions ──────────────────────────────────────────────────────
foreach ($legs as $leg) {
    $pdf->AddPage();
    [$r, $g, $b] = hex2rgb($leg['color']);
    $pdf->SetFillColor($r, $g, $b);
    $pdf->Rect(0.6, $pdf->GetY(), $page_w, 0.05, 'F');
    $pdf->Ln(0.15);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(28, 27, 25);
    $pdf->Cell(0, 0.28, pdftext($leg['label']), 0, 1);
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(74, 72, 68);
    $detail = ($leg['mi'] ? number_format(convertDistance($leg['mi'], $user_units), 1) . ' ' . getDistanceUnit($user_units) . ', ' : '') . 'about ' . formatTime($leg['min']);
    $pdf->Cell(0, 0.22, pdftext($detail), 0, 1);
    $pdf->Ln(0.05);

    $directions = get_leg_directions($leg['from_key'], $leg['from'], $leg['to_key'], $leg['to']);
    if ($directions && !empty($directions['steps'])) {
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(28, 27, 25);
        foreach ($directions['steps'] as $n => $step) {
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(0.3, 0.2, ($n + 1) . '.');
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->SetX(0.9);
            $pdf->MultiCell($page_w - 0.3, 0.2, pdftext($step['instruction']));
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(140, 138, 134);
            $pdf->SetX(0.9);
            $sub = trim($step['distance'] . ($step['distance'] && $step['duration'] ? ', ' : '') . $step['duration']);
            if ($sub !== '') $pdf->Cell(0, 0.18, pdftext($sub), 0, 1);
            $pdf->SetTextColor(28, 27, 25);
            $pdf->Ln(0.06);
        }
    } else {
        $pdf->SetFont('Helvetica', 'I', 9.5);
        $pdf->SetTextColor(140, 138, 134);
        $note = $directions === null
            ? 'Turn-by-turn directions unavailable (the Directions API may not be enabled yet on the SOTAplanner server key). Use the distance/time above with your GPS or a road atlas.'
            : 'No turn-by-turn steps returned for this leg.';
        $pdf->MultiCell($page_w, 0.2, pdftext($note));
        $pdf->SetTextColor(28, 27, 25);
    }
    $pdf->Ln(0.1);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(74, 72, 68);
    $from_txt = $leg['from'] ? str_replace(',', ', ', $leg['from']) : 'unknown';
    $to_txt = $leg['to'] ? str_replace(',', ', ', $leg['to']) : 'unknown';
    $pdf->Cell(0, 0.2, pdftext('GPS coordinates: from ' . $from_txt . ', to ' . $to_txt), 0, 1);
    $pdf->SetTextColor(28, 27, 25);
}

// ── Per-summit close-up reference pages ─────────────────────────────────────
foreach ($stops as $i => $stop) {
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 0.28, pdftext('#' . ($i + 1) . '  ' . $stop['name']), 0, 1);
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(74, 72, 68);
    $meta = $stop['ref'] . '  ·  ' . $stop['points'] . ' pts' . ($stop['difficulty'] ? '  ·  ' . ucwords(str_replace('-', ' ', $stop['difficulty'])) : '');
    $pdf->Cell(0, 0.22, pdftext($meta), 0, 1);
    $pdf->SetTextColor(28, 27, 25);
    $pdf->Ln(0.1);

    // Same view as the Summit Detail page: GPX trail in brown, activation zone,
    // red summit dot, green starting-point dot, fitted to the trail.
    $az = $stop['az'];
    $map_h = 4.4;
    $fit = [[$stop['summit_lat'], $stop['summit_lng']]];
    $mk = [['lat' => $stop['summit_lat'], 'lng' => $stop['summit_lng'], 'color' => '#C03030', 'r' => 8, 'label' => 'Summit']];
    $start_pt = null;
    if ($stop['chained']) $start_pt = [(float)$stop['alt']['lat'], (float)$stop['alt']['lng']];
    elseif ($stop['has_trailhead']) $start_pt = [(float)$stop['lat'], (float)$stop['lng']];
    if ($start_pt) {
        $fit[] = $start_pt;
        $mk[] = ['lat' => $start_pt[0], 'lng' => $start_pt[1], 'color' => '#2D8653', 'r' => 7, 'label' => 'Start', 'side' => 'left'];
    }
    $lines = [];
    if (!empty($stop['gpx_pts'])) {
        $lines[] = ['pts' => $stop['gpx_pts'], 'color' => '#9B6328', 'weight' => 3];
        foreach ($stop['gpx_pts'] as $p) $fit[] = $p;
    } elseif ($start_pt) {
        $lines[] = ['pts' => [$start_pt, [$stop['summit_lat'], $stop['summit_lng']]], 'color' => '#2D8653', 'weight' => 3, 'dash' => true];
    }
    $polys = [];
    if (!empty($stop['az_ring'])) {
        $polys[] = ['pts' => $stop['az_ring'], 'color' => '#CC2200', 'fill' => 0.18, 'weight' => 2];
        if (empty($stop['gpx_pts'])) foreach ($stop['az_ring'] as $p) $fit[] = $p;
    }
    $cu_w = 1200;
    $img = pdfmap_render([
        'w' => $cu_w, 'h' => (int)round($cu_w * $map_h / $page_w), 'style' => 'topo', 'fit' => $fit,
        'min_pad' => 60, 'max_zoom' => 16, 'polygons' => $polys, 'lines' => $lines, 'markers' => $mk,
    ]);
    $img_type = 'JPG';
    if ($img) {
        $PDF_TMP_FILES[] = $img;
    } else {
        // Tiles unavailable: fall back to the old Google Static Maps image
        $markers = []; $paths = [];
        $color = static_map_marker_hex($LEG_COLORS[$i % count($LEG_COLORS)]);
        if ($start_pt) $markers[] = 'color:0x2D8653|label:T|' . $start_pt[0] . ',' . $start_pt[1];
        $markers[] = 'color:' . $color . '|label:' . leg_label_char($i) . '|' . $stop['summit_lat'] . ',' . $stop['summit_lng'];
        if (!empty($stop['az_ring'])) {
            $ring = az_ring_to_latlng($az['polygon']);
            if (count($ring) >= 3) $paths[] = 'color:0xCC220090|weight:2|fillcolor:0xCC22002E|' . implode('|', $ring);
        }
        $img = fetch_static_map($markers, $paths, '640x' . (int)round(640 * $map_h / $page_w), 2, 'terrain');
        $img_type = 'PNG';
    }
    if ($img) {
        $closeup_draw_h = static_map_display_height($img, $page_w, $map_h);
        $pdf->Image($img, 0.6, $pdf->GetY(), $page_w, $closeup_draw_h, $img_type);
        $pdf->SetY($pdf->GetY() + $closeup_draw_h + 0.1);
    } else {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(192, 48, 48);
        $pdf->MultiCell($page_w, 0.22, pdftext('Map image unavailable.'));
        $pdf->SetTextColor(28, 27, 25);
        $pdf->Ln(0.1);
    }

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 0.2, 'Coordinates', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    if ($stop['chained'] && (($stop['alt']['mode'] ?? '') === 'return')) {
        $pdf->Cell(0, 0.2, pdftext('Reached on foot on the way down from #' . $i . ', no separate hike up.'), 0, 1);
    } elseif ($stop['chained']) {
        $pdf->Cell(0, 0.2, pdftext('Hike starts at (on foot from #' . $i . '): ' . round($stop['alt']['lat'], 5) . ', ' . round($stop['alt']['lng'], 5)), 0, 1);
    } elseif ($stop['has_trailhead']) {
        $pdf->Cell(0, 0.2, pdftext('Trailhead / start point: ' . round($stop['lat'], 5) . ', ' . round($stop['lng'], 5)), 0, 1);
    }
    $pdf->Cell(0, 0.2, pdftext('Summit: ' . round($stop['summit_lat'], 5) . ', ' . round($stop['summit_lng'], 5)), 0, 1);
    $pdf->Ln(0.1);

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 0.2, 'Hike', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    if ($stop['is_drive_up']) {
        $pdf->Cell(0, 0.2, pdftext('Drive-up summit, no hike required.'), 0, 1);
    } elseif ($stop['dist_mi'] || $stop['elev_ft']) {
        $trip_word = $stop['chained'] ? 'including the walk back to the car' : ($stop['continues'] ? 'up only, you continue on to the next summit' : 'round trip');
        $hike_line = ($stop['dist_mi'] ? number_format(convertDistance($stop['dist_mi'], $user_units), 1) . ' ' . getDistanceUnit($user_units) . ' ' . $trip_word : '')
            . ($stop['elev_ft'] ? ', ' . number_format(convertElevation($stop['elev_ft'], $user_units)) . ' ' . getElevationUnit($user_units) . ' gain' : '')
            . ($stop['hike_min'] ? ', about ' . formatTime($stop['hike_min']) . ' ' . ($stop['chained'] || $stop['continues'] ? '' : 'round trip') : '');
        $pdf->Cell(0, 0.2, pdftext(trim($hike_line, " ,")), 0, 1);
    } else {
        $pdf->SetTextColor(140, 138, 134);
        $pdf->Cell(0, 0.2, 'No hike data on file for this summit.', 0, 1);
        $pdf->SetTextColor(28, 27, 25);
    }
    if ($az && !empty($az['polygon'])) {
        $pdf->Ln(0.05);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(140, 138, 134);
        $pdf->MultiCell($page_w, 0.18, pdftext('Shaded area on the map is the approximate activation zone (source: ' . $az['source'] . ').'));
        $pdf->SetTextColor(28, 27, 25);
    }

    // Weather at the summit (best effort: a slow or missing forecast just leaves this block out)
    $arrive_min = null;
    foreach ($milestones as $m) {
        if (($m['stop_i'] ?? null) === $i && ($m['short'] === 'On Summit' || ($m['short'] === 'Arrive' && $arrive_min === null))) $arrive_min = $m['min'];
    }
    $wx_elev_m = (int)($stop['elevation_m'] ?? 0);
    $wx = weather_fetch($stop['summit_lat'], $stop['summit_lng'], $wx_elev_m);
    if ($wx) {
        $a = $start_time_min + ($arrive_min ?? 0);
        $dpt = $a + $activation_time_min;
        pdf_weather_block($pdf, $page_w, $wx, $user_units, min($a, 1439), min($dpt, 1439));
    }
}

$fname = 'sotaplanner-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($route_title)) . '-' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $fname);
