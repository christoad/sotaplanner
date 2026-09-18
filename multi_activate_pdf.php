<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(180);

require_once 'config.php';
require_once __DIR__ . '/lib/fpdf.php';
session_start();
requireLogin();

$db = getDbConnection();
$current_group = getCurrentPlanningGroup($db);
if (!$current_group) { header('Location: index.php'); exit; }
$user_units = getUserUnits($db);

// ── Resolve which summits, in what order (read-only — this page never mutates
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
    $geo_key = 'geo:' . $current_group['id'] . ':' . md5($selected_address['address']);
    if (array_key_exists($geo_key, $_SESSION['multi_drive_cache'])) {
        $origin_geo = $_SESSION['multi_drive_cache'][$geo_key];
    } else {
        $origin_geo = geocodeAddress($selected_address['address']);
        $_SESSION['multi_drive_cache'][$geo_key] = $origin_geo;
    }
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
        'has_trailhead' => ($s['trailhead_lat'] !== null && $s['trailhead_lng'] !== null),
    ];
}

// ── Per-leg drive times + distances — reuses the same session cache the
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
foreach ($stops as $i => $stop) {
    $to_coord = ($stop['lat'] !== null && $stop['lng'] !== null) ? ($stop['lat'] . ',' . $stop['lng']) : null;
    $leg = $get_leg($prev_key, $prev_coord, 'summit:' . $stop['id'], $to_coord);
    $leg_times[$i] = $leg['min'];
    $leg_distances_mi[$i] = $leg['mi'];
    $leg_coords[$i] = ['from' => $prev_coord, 'to' => $to_coord, 'from_key' => $prev_key, 'to_key' => 'summit:' . $stop['id']];
    $prev_key = 'summit:' . $stop['id'];
    $prev_coord = $to_coord;
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
        $from_label = $i === 0 ? ($selected_address['label'] ?? $selected_address['address'] ?? 'Start') : $stops[$i - 1]['name'];
        $legs[] = [
            'label' => $from_label . ' → ' . $stop['name'], 'color' => $color,
            'from' => $leg_coords[$i]['from'], 'to' => $leg_coords[$i]['to'],
            'from_key' => $leg_coords[$i]['from_key'], 'to_key' => $leg_coords[$i]['to_key'],
            'min' => $leg_times[$i], 'mi' => $leg_distances_mi[$i], 'stop_i' => $i,
        ];
        $elapsed += $leg_times[$i];
    }
    $milestones[] = ['short' => 'Arrive', 'full' => 'Arrive at ' . $stop['name'], 'min' => $elapsed, 'stop_i' => $i];
    if ($stop['hike_min'] > 0) {
        $up = intval(round($stop['hike_min'] * 0.6));
        $elapsed += $up;
        $milestones[] = ['short' => 'On Summit', 'full' => 'On summit: ' . $stop['name'], 'min' => $elapsed, 'stop_i' => $i];
    }
    $elapsed += $activation_time_min;
    if ($stop['hike_min'] > 0) {
        $down = $stop['hike_min'] - $up;
        $elapsed += $down;
    }
    $milestones[] = ['short' => 'Depart', 'full' => 'Depart ' . $stop['name'], 'min' => $elapsed, 'stop_i' => $i];
}
if ($return_leg_time) {
    $color = $LEG_COLORS[$leg_color_i % count($LEG_COLORS)]; $leg_color_i++;
    $last_stop = end($stops);
    $legs[] = [
        'label' => $last_stop['name'] . ' → Home', 'color' => $color,
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

// FPDF's core fonts only support Windows-1252 — transliterate UTF-8 input
// (summit names, addresses, arrows) down to that so nothing throws or garbles.
function pdftext($s) {
    if ($s === null) return '';
    $s = str_replace(["\xE2\x86\x92" /* → */], '->', $s);
    $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $s);
}

function hex2rgb($hex) {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

// Single alphanumeric marker label Static Maps will accept — 1-9 then A,B for
// the rare 10th/11th stop (MULTI_MAX is 11).
function leg_label_char($zero_based_index) {
    $n = $zero_based_index + 1;
    return $n <= 9 ? (string)$n : chr(ord('A') + ($n - 10));
}

// GeoJSON polygon coordinates come as [[lon,lat],...] optionally wrapped in a
// ring array — normalize to a flat, downsampled list of "lat,lng" pairs.
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
        $this->Cell(0, 0.25, pdftext('SOTAplanner — ' . $this->docTitle), 0, 1, 'R');
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
        $this->Cell(0, 0.25, pdftext('Offline reference only — verify conditions before you go'), 0, 0, 'L');
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
$subtitle = count($stops) . ' summits — ' . formatTime($total_min) . ' total, door-to-door';
$pdf->Cell(0, 0.3, pdftext($subtitle), 0, 1, 'L');
$pdf->SetX(0.6);
$pdf->SetFont('Helvetica', '', 8.5);
$pdf->Cell(0, 0.25, pdftext('Generated ' . date('F j, Y \a\t g:i A') . ' — ' . $current_group['name']), 0, 1, 'L');

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
    $pdf->Cell(0, 0.22, pdftext('No starting address on file — travel times below are between summits only.'), 0, 1);
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

// Overview map — markers for the address (if any) + every stop, auto-fit by
// Static Maps to whatever bounding box contains them; one colored path per leg.
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->SetTextColor(28, 27, 25);
$pdf->Cell(0, 0.25, 'Route Overview', 0, 1);
$markers = []; $paths = [];
if ($origin_geo) $markers[] = 'color:0x1C1B19|label:H|' . $origin_geo['lat'] . ',' . $origin_geo['lng'];
foreach ($stops as $i => $stop) {
    if ($stop['lat'] === null || $stop['lng'] === null) continue;
    $color = static_map_marker_hex($LEG_COLORS[$i % count($LEG_COLORS)]);
    $markers[] = 'color:' . $color . '|label:' . leg_label_char($i) . '|' . $stop['lat'] . ',' . $stop['lng'];
}
foreach ($legs as $leg) {
    if (!$leg['from'] || !$leg['to']) continue;
    $paths[] = 'color:' . static_map_hex($leg['color']) . '|weight:4|' . $leg['from'] . '|' . $leg['to'];
}
$overview_img = fetch_static_map($markers, $paths, '1280x500', 1, 'roadmap');
if ($overview_img) {
    $pdf->Image($overview_img, 0.6, $pdf->GetY(), $page_w, 2.6, 'PNG');
    $pdf->SetY($pdf->GetY() + 2.65);
} else {
    $pdf->SetFont('Helvetica', 'I', 9);
    $pdf->SetTextColor(192, 48, 48);
    $pdf->MultiCell($page_w, 0.22, pdftext('Map image unavailable — the Maps Static API may not be enabled yet on the SOTAplanner server key.'));
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
    $detail = ($leg['mi'] ? number_format(convertDistance($leg['mi'], $user_units), 1) . ' ' . getDistanceUnit($user_units) . ' — ' : '') . formatTime($leg['min']);
    $pdf->Cell($page_w - 0.25, 0.18, pdftext($leg['label'] . '   (' . $detail . ')'), 0, 1);
}

// ── Function: static map color helper (defined after use above via hoisting is
// not available in PHP for closures assigned to vars, but plain functions
// declared anywhere in the file are hoisted — see below) ──
// Path colors accept an 8-digit hex with alpha; marker colors accept only a
// plain 6-digit hex (no alpha) — Static Maps rejects/ignores the marker style
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
$pdf->MultiCell($page_w, 0.18, pdftext('Clock times assume a ' . multi_clock_label($start_time_min) . ' start and ' . $activation_time_min . ' min on the air per summit — treat them as planning estimates, not a guarantee.'));
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
    $detail = ($leg['mi'] ? number_format(convertDistance($leg['mi'], $user_units), 1) . ' ' . getDistanceUnit($user_units) . ' — ' : '') . 'about ' . formatTime($leg['min']);
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
            $sub = trim($step['distance'] . ($step['distance'] && $step['duration'] ? ' — ' : '') . $step['duration']);
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
    $pdf->Cell(0, 0.2, pdftext('GPS coordinates — from: ' . $from_txt . '   to: ' . $to_txt), 0, 1);
    $pdf->SetTextColor(28, 27, 25);
}

// ── Per-summit close-up reference pages ─────────────────────────────────────
foreach ($stops as $i => $stop) {
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 0.28, pdftext('#' . ($i + 1) . '  ' . $stop['name']), 0, 1);
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(74, 72, 68);
    $meta = $stop['ref'] . '  —  ' . $stop['points'] . ' pts' . ($stop['difficulty'] ? '  —  ' . ucwords(str_replace('-', ' ', $stop['difficulty'])) : '');
    $pdf->Cell(0, 0.22, pdftext($meta), 0, 1);
    $pdf->SetTextColor(28, 27, 25);
    $pdf->Ln(0.1);

    $markers = []; $paths = [];
    $label = leg_label_char($i);
    $color = static_map_marker_hex($LEG_COLORS[$i % count($LEG_COLORS)]);
    if ($stop['has_trailhead']) {
        $markers[] = 'color:0x2D8653|label:T|' . $stop['lat'] . ',' . $stop['lng'];
        $markers[] = 'color:' . $color . '|label:' . $label . '|' . $stop['summit_lat'] . ',' . $stop['summit_lng'];
    } else {
        $markers[] = 'color:' . $color . '|label:' . $label . '|' . $stop['summit_lat'] . ',' . $stop['summit_lng'];
    }
    $az = get_activation_zone_from_api($stop['ref'], $stop['summit_lat'], $stop['summit_lng'], $stop['elevation_m']);
    if ($az && !empty($az['polygon'])) {
        $ring = az_ring_to_latlng($az['polygon']);
        if (count($ring) >= 3) $paths[] = 'color:0xCC220090|weight:2|fillcolor:0xCC22002E|' . implode('|', $ring);
    }
    $img = fetch_static_map($markers, $paths, '1000x700', 1, 'terrain');
    $map_h = 3.6;
    if ($img) {
        $pdf->Image($img, 0.6, $pdf->GetY(), $page_w, $map_h, 'PNG');
        $pdf->SetY($pdf->GetY() + $map_h + 0.1);
    } else {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(192, 48, 48);
        $pdf->MultiCell($page_w, 0.22, pdftext('Map image unavailable — the Maps Static API may not be enabled yet on the SOTAplanner server key.'));
        $pdf->SetTextColor(28, 27, 25);
        $pdf->Ln(0.1);
    }

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 0.2, 'Coordinates', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    if ($stop['has_trailhead']) {
        $pdf->Cell(0, 0.2, pdftext('Trailhead / start point: ' . round($stop['lat'], 5) . ', ' . round($stop['lng'], 5)), 0, 1);
    }
    $pdf->Cell(0, 0.2, pdftext('Summit: ' . round($stop['summit_lat'], 5) . ', ' . round($stop['summit_lng'], 5)), 0, 1);
    $pdf->Ln(0.1);

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 0.2, 'Hike', 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    if ($stop['is_drive_up']) {
        $pdf->Cell(0, 0.2, pdftext('Drive-up summit — no hike required.'), 0, 1);
    } elseif ($stop['dist_mi'] || $stop['elev_ft']) {
        $hike_line = ($stop['dist_mi'] ? number_format(convertDistance($stop['dist_mi'], $user_units), 1) . ' ' . getDistanceUnit($user_units) . ' round trip' : '')
            . ($stop['elev_ft'] ? ' — ' . number_format(convertElevation($stop['elev_ft'], $user_units)) . ' ' . getElevationUnit($user_units) . ' gain' : '')
            . ($stop['hike_min'] ? ' — about ' . formatTime($stop['hike_min']) . ' round trip' : '');
        $pdf->Cell(0, 0.2, pdftext(trim($hike_line, " —")), 0, 1);
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
}

$fname = 'sotaplanner-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($route_title)) . '-' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $fname);
