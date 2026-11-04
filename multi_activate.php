<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'sota_cache_helper.php'; // haversine_miles()
require_once 'multi_alt_lib.php';     // alternate hike starts (see file header)
require_once 'multi_leg_lib.php';     // car/hike between summits + finish address (BRouter)
session_start();

$db = getDbConnection();

// ── Public (shared) view ────────────────────────────────────────────────────
// A saved route's link (multi_activate.php?id=N) opens for anyone. Members of
// the route's dashboard get the normal editable page; everyone else (signed
// out, or signed in to a different dashboard) gets a read-only copy with an
// "Add this Multi-Summit to your own dashboard" button. The public copy never shows the owner's
// starting address: no home marker, and drive legs run between summits only.
$public_view = false;
$multi_row = null;
if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM multi_activations WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $multi_row = $stmt->fetch();
    if (!$multi_row) { header('Location: ' . (getCurrentCallsign() ? 'index.php' : 'login.php')); exit; }

    $viewer = getCurrentCallsign();
    $is_member = false;
    if ($viewer) {
        $stmt = $db->prepare("
            SELECT 1 FROM planning_groups pg
            LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
            WHERE pg.id = ? AND (pg.owner_callsign = ? OR pgm.callsign = ?)
            LIMIT 1
        ");
        $stmt->execute([$multi_row['planning_group_id'], $viewer, $viewer]);
        $is_member = (bool)$stmt->fetch();
    }
    if ($is_member) {
        setCurrentPlanningGroup((int)$multi_row['planning_group_id']);
    } else {
        $public_view = true;
    }
} else {
    requireLogin();
}

if ($public_view) {
    $stmt = $db->prepare("SELECT * FROM planning_groups WHERE id = ?");
    $stmt->execute([$multi_row['planning_group_id']]);
    $current_group = $stmt->fetch();
    if (!$current_group) { header('Location: login.php'); exit; }
} else {
    $current_group = getCurrentPlanningGroup($db);
    if (!$current_group) { header('Location: index.php'); exit; }
}
$user_units = getUserUnits($db);

$MULTI_MAX = 11;

// ── POST actions ──────────────────────────────────────────────────────────
// Saving the route itself is automatic (see the auto-save block below) — the
// only remaining mutating POST action is dropping the whole route.
if (!$public_view && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_multi'])) {
        $mid = (int)($_POST['multi_id'] ?? 0);
        $db->prepare("DELETE FROM multi_activations WHERE id = ? AND planning_group_id = ?")->execute([$mid, $current_group['id']]);
        logActivity($db, 'Multi-activation deleted', '', "id=$mid", $current_group['name']);
        header("Location: index.php?group={$current_group['id']}");
        exit;
    }
}

// ── Resolve which summits, and in what order ────────────────────────────────
$multi_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$multi_name = '';
$is_saved = false;
$saved_activation_time = 30;

if ($multi_id) {
    $is_saved = true;
    $multi_name = $multi_row['name'] ?? '';
    $saved_activation_time = (int)$multi_row['activation_time_min'];

    if ($public_view) {
        // Always the route as saved; visitors can't reorder or edit it
        $stmt = $db->prepare("SELECT summit_id FROM multi_activation_summits WHERE multi_activation_id = ? ORDER BY sort_order");
        $stmt->execute([$multi_id]);
        $summit_ids = array_map('intval', array_column($stmt->fetchAll(), 'summit_id'));
    } elseif (!isset($_GET['ids'])) {
        $stmt = $db->prepare("SELECT summit_id FROM multi_activation_summits WHERE multi_activation_id = ? ORDER BY sort_order");
        $stmt->execute([$multi_id]);
        $stored_ids = array_column($stmt->fetchAll(), 'summit_id');
        $qs = ['id' => $multi_id, 'ids' => implode(',', $stored_ids), 'group' => $current_group['id']];
        if (isset($_GET['activation_time'])) $qs['activation_time'] = $_GET['activation_time'];
        header('Location: multi_activate.php?' . http_build_query($qs));
        exit;
    } else {
        $summit_ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids']))));
    }
} else {
    $ids_param = $_GET['ids'] ?? '';
    $summit_ids = array_values(array_filter(array_map('intval', explode(',', $ids_param))));
    if (count($summit_ids) < 2) { header('Location: index.php'); exit; }
    $summit_ids = array_slice($summit_ids, 0, $MULTI_MAX);
}

// Load the summit rows (must belong to this group)
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
if (count($summit_ids) < 2) { header('Location: ' . ($public_view && !getCurrentCallsign() ? 'login.php' : 'index.php')); exit; }

if (!isset($_SESSION['multi_drive_cache']) || !is_array($_SESSION['multi_drive_cache'])) {
    $_SESSION['multi_drive_cache'] = [];
}

$selected_address = $public_view ? null : getSelectedAddress($db);
$origin_geo = null;
if ($selected_address) {
    $origin_geo = addressGeo($db, $selected_address);
}

// Light-effort auto-order (nearest neighbor) — only on first arrival for a brand-new plan
if (!$multi_id && !isset($_GET['ordered'])) {
    if ($origin_geo) {
        $start_lat = $origin_geo['lat']; $start_lng = $origin_geo['lng'];
    } else {
        $first = $summits_by_id[$summit_ids[0]];
        $start_lat = $first['trailhead_lat'] ?? $first['latitude'];
        $start_lng = $first['trailhead_lng'] ?? $first['longitude'];
    }
    $remaining = $summit_ids;
    $ordered = [];
    $cur_lat = $start_lat; $cur_lng = $start_lng;
    while (!empty($remaining)) {
        $best_id = null; $best_dist = INF;
        foreach ($remaining as $sid) {
            $s = $summits_by_id[$sid];
            $lat = $s['trailhead_lat'] ?? $s['latitude'];
            $lng = $s['trailhead_lng'] ?? $s['longitude'];
            $d = haversine_miles($cur_lat, $cur_lng, $lat, $lng);
            if ($d < $best_dist) { $best_dist = $d; $best_id = $sid; }
        }
        $ordered[] = $best_id;
        $s = $summits_by_id[$best_id];
        $cur_lat = $s['trailhead_lat'] ?? $s['latitude'];
        $cur_lng = $s['trailhead_lng'] ?? $s['longitude'];
        $remove_at = array_search($best_id, $remaining, true);
        unset($remaining[$remove_at]);
        $remaining = array_values($remaining);
    }
    $qs = ['ids' => implode(',', $ordered), 'group' => $current_group['id'], 'ordered' => 1];
    if (isset($_GET['activation_time'])) $qs['activation_time'] = $_GET['activation_time'];
    header('Location: multi_activate.php?' . http_build_query($qs));
    exit;
}

// Manual "recalculate travel times" — clear the session's cached leg times once, then reload clean
if (isset($_GET['recalc'])) {
    unset($_SESSION['multi_drive_cache']);
    $qs = $_GET; unset($qs['recalc']);
    header('Location: multi_activate.php?' . http_build_query($qs));
    exit;
}

$activation_time_min = isset($_GET['activation_time'])
    ? max(10, min(240, (int)$_GET['activation_time']))
    : $saved_activation_time;

// ── Build the ordered stop list with hike stats (mirrors index.php's logic) ──
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
        'summit_lat' => (float)$s['latitude'], 'summit_lng' => (float)$s['longitude'], 'elev_m' => (float)($s['elevation_m'] ?? 0),
        'hike_min' => (int)$hike_min, 'dist_mi' => $dist, 'elev_ft' => $elev,
        'is_drive_up' => $is_drive_up, 'gpx_path' => extractGpxPath($s['gpx_file_path'] ?? null),
        'gpx_file' => $s['gpx_file_path'] ?? null, 'track_type' => $track_type,
        'has_trailhead' => ($s['trailhead_lat'] !== null && $s['trailhead_lng'] !== null),
    ];
}

// ── Alternate hike starts: where the hike to a stop begins when it follows the
// previous summit on foot (see multi_alt_lib.php). Rewrites hike numbers in place.
$alt_lookup = multi_alt_lookup($db, $stops, $public_view ? null : getCurrentCallsign());

// Explicit "back to the car" / "hike direct" choices for each pair of neighbouring summits
// (multi_leg_lib.php). "Car" overrides a shared alternate start pin; "hike" uses the pin if there
// is one, otherwise asks BRouter for a walkable route between the two summits.
$leg_modes = multi_leg_load($db, $multi_id);
$want_hike = [];
$leg_set_idx = [];
foreach ($stops as $i => $_st) {
    $m = multi_leg_mode_for($leg_modes, $stops, $i);
    if ($m === null) { unset($alt_lookup['active'][$i]); continue; }   // default: return to the hike start point
    $leg_set_idx[$i] = true;
    if ($m === 'car') unset($alt_lookup['active'][$i]);
    elseif ($m === 'hike' && !$_st['is_drive_up']) { unset($alt_lookup['active'][$i]); $want_hike[$i] = true; }   // explicit choice: BRouter, not a pin
}
// Per-summit on-the-air time: a stop's own setting wins over the route-wide default
$stop_times = multi_stop_times_load($db, $multi_id);
foreach ($stops as $i => $_st) {
    $stops[$i]['act_min'] = $stop_times[$_st['id']] ?? $activation_time_min;
    $stops[$i]['act_custom'] = isset($stop_times[$_st['id']]);
}
$pace_mult = $current_group['pace_multiplier'] ?? 1.0;
multi_alt_apply($stops, $alt_lookup['active'], $pace_mult);
$exit_new = multi_exit_load($db, $multi_id);
multi_leg_apply_brouter($stops, $want_hike, $pace_mult, $exit_new);

// Suggest an alternate start where the previous summit sits on the next one's trail
// (skipped for pairs that already have a pin, that this user opted out of / dismissed, or that have an explicit choice)
$alt_suggestions = []; // shared start pins are no longer applied automatically; Hike direct uses the path finder
$_unused_alt_suggestions = $public_view ? [] : multi_alt_detect($stops, $alt_lookup['active'] + $alt_lookup['hidden'] + $alt_lookup['optout'] + $leg_set_idx);

// ── Finish address: where the last drive ends. Default is back at the starting address;
// a route can instead finish at any other address saved on this dashboard (a road trip).
// Never shown on the public view, same as the starting address.
$dash_addresses = $public_view ? [] : multi_leg_addresses($db, $current_group['id']);
$finish_addr = null; $finish_geo = null; $finish_warn = '';
$finish_id = (int)($multi_row['finish_address_id'] ?? 0);
if (!$public_view && $finish_id && (!$selected_address || (int)$selected_address['id'] !== $finish_id)) {
    foreach ($dash_addresses as $_a) if ((int)$_a['id'] === $finish_id) $finish_addr = $_a;
    if ($finish_addr) {
        $finish_geo = addressGeo($db, $finish_addr);
        if (!$finish_geo) { $finish_warn = 'Google could not locate "' . multi_leg_address_label($finish_addr) . '", so the route ends back at the start for now.'; $finish_addr = null; }
    }
}
$end_other = (bool)$finish_geo;
$end_label = $end_other ? multi_leg_address_label($finish_addr) : 'Home';

// ── Google Maps directions link. Origin is the group's starting address if one is set,
// otherwise the first stop. Destination is the finish address, or the origin again
// (a round trip) when no other finish is chosen. Waypoints are each stop's trailhead (or summit
// coordinate as a fallback), in route order. ──
$gmaps_url = null;
$gmaps_coords = array_values(array_filter(array_map(
    fn($s) => ((!$s['chained'] || !empty($s['exit_new'])) && $s['lat'] !== null && $s['lng'] !== null) ? $s['lat'] . ',' . $s['lng'] : null,
    $stops
)));
if (!empty($gmaps_coords)) {
    if ($origin_geo) {
        $gmaps_endpoint = $origin_geo['lat'] . ',' . $origin_geo['lng'];
        $gmaps_waypoints = $gmaps_coords;
    } else {
        $gmaps_endpoint = array_shift($gmaps_coords);
        $gmaps_waypoints = $gmaps_coords;
    }
    $gmaps_dest = $end_other ? ($finish_geo['lat'] . ',' . $finish_geo['lng']) : $gmaps_endpoint;
    $gmaps_params = ['api' => 1, 'origin' => $gmaps_endpoint, 'destination' => $gmaps_dest, 'travelmode' => 'driving'];
    if (!empty($gmaps_waypoints)) $gmaps_params['waypoints'] = implode('|', $gmaps_waypoints);
    $gmaps_url = 'https://www.google.com/maps/dir/?' . http_build_query($gmaps_params);
}

// ── Per-leg drive times + distances, cached per directed pair for the life of
// the session. Distance is cached alongside time (one Distance Matrix call per
// leg, not two) — total drive distance is the EV-range-anxiety stat below.
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

$leg_times = [];
$leg_distances_mi = [];
$leg_coords = []; // per-stop arrival leg: ['from' => 'lat,lng'|null, 'to' => 'lat,lng'|null]
$prev_key = $origin_key;
$prev_coord = $origin_coord;
$prev_name = 'Start';   // where the car is now, for leg labels
$leg_from_name = [];
foreach ($stops as $i => $stop) {
    $leg_from_name[$i] = $prev_name;
    if ($stop['chained']) {
        // Reached on foot from the previous summit: the car doesn't move
        $leg_times[$i] = 0;
        $leg_distances_mi[$i] = 0;
        $leg_coords[$i] = ['from' => null, 'to' => null];
        if (!empty($stop['exit_new']) && $stop['lat'] !== null && $stop['lng'] !== null) {
            // Walked down to this summit's own trailhead: the next drive starts there
            $prev_key = 'summit:' . $stop['id'];
            $prev_coord = $stop['lat'] . ',' . $stop['lng'];
            $prev_name = $stop['name'];
        }
        continue;
    }
    $to_coord = ($stop['lat'] !== null && $stop['lng'] !== null) ? ($stop['lat'] . ',' . $stop['lng']) : null;
    $leg = $get_leg($prev_key, $prev_coord, 'summit:' . $stop['id'], $to_coord);
    $leg_times[$i] = $leg['min'];
    $leg_distances_mi[$i] = $leg['mi'];
    $leg_coords[$i] = ['from' => $prev_coord, 'to' => $to_coord];
    $prev_key = 'summit:' . $stop['id'];
    $prev_coord = $to_coord;
    $prev_name = $stop['name'];
}
$end_coord = $end_other ? ($finish_geo['lat'] . ',' . $finish_geo['lng']) : $origin_coord;
$end_key   = $end_other ? ('faddr:' . $finish_addr['id']) : $origin_key;
$return_leg = $get_leg($prev_key, $prev_coord, $end_key, $end_coord);
$return_leg_time = $return_leg['min'];
$return_leg_distance_mi = $return_leg['mi'];
$return_leg_coords = ['from' => $prev_coord, 'to' => $end_coord];

// ── Master timeline: segments (for the gantt bar) + milestones (arrival points) ──
// Each drive leg gets its own color (cycled from this palette) so the map can
// draw outbound and return trips over the same road in visibly different colors,
// and so hovering a leg on the map or the gantt can highlight its counterpart.
$LEG_COLORS = ['#2B5CA0', '#7B4FA0', '#1B8A8A', '#C08A20', '#B03A6B', '#4A56C4', '#8A5A2E', '#5C7080', '#7A7A2E', '#D46A35', '#2E9FBF', '#A83E5C'];
$leg_color_i = 0;
$map_legs = [];
$segments = [];
$milestones = [['short' => 'Depart', 'full' => 'Depart', 'min' => 0]];
$elapsed = 0;
foreach ($stops as $i => $stop) {
    $n = $i + 1;
    if ($leg_times[$i]) {
        $leg_key = 'stop-' . $i;
        $color = $LEG_COLORS[$leg_color_i % count($LEG_COLORS)]; $leg_color_i++;
        $segments[] = ['type' => 'drive', 'label' => "Drive to #$n", 'minutes' => $leg_times[$i], 'stop' => $i, 'leg_key' => $leg_key, 'color' => $color];
        $elapsed += $leg_times[$i];
        $from_label = $leg_from_name[$i];
        $map_legs[] = ['key' => $leg_key, 'color' => $color, 'from' => $leg_coords[$i]['from'], 'to' => $leg_coords[$i]['to'], 'label' => $from_label . ' → ' . $stop['name']];
    }
    if (!$stop['chained'] || $stop['up_min'] <= 0) {
        $milestones[] = ['short' => 'Arrive', 'full' => 'Arrive at ' . $stop['name'], 'min' => $elapsed];
    }
    if ($stop['up_min'] > 0) {
        $segments[] = ['type' => 'hike', 'label' => ($stop['chained'] ? "Hike to #$n" : "Hike Up #$n"), 'minutes' => $stop['up_min'], 'stop' => $i];
        $elapsed += $stop['up_min'];
        $milestones[] = ['short' => 'On Summit', 'full' => 'On summit: ' . $stop['name'], 'min' => $elapsed];
    }
    $segments[] = ['type' => 'radio', 'label' => "Radio #$n", 'minutes' => $stop['act_min'], 'stop' => $i];
    $elapsed += $stop['act_min'];
    if ($stop['down_min'] > 0) {
        if (!empty($stop['back_routed'])) {
            $down_label = 'Return to #' . ($stop['chain_head'] + 1) . (count($stop['back_via'] ?? []) > 1 ? ' via ' . implode(', ', array_map(fn($v) => '#' . $v, array_slice($stop['back_via'], 0, -1))) : '');
        } elseif (!empty($stop['exit_new'])) {
            $down_label = "Down to #$n trailhead";
        } else {
            $down_label = "Hike Down #$n";
        }
        $segments[] = ['type' => 'hike', 'label' => $down_label, 'minutes' => $stop['down_min'], 'stop' => $i];
        $elapsed += $stop['down_min'];
    }
}
if ($return_leg_time) {
    $leg_key = 'return';
    $color = $LEG_COLORS[$leg_color_i % count($LEG_COLORS)]; $leg_color_i++;
    $segments[] = ['type' => 'drive', 'label' => $end_other ? 'Drive to finish' : 'Drive home', 'minutes' => $return_leg_time, 'stop' => null, 'leg_key' => $leg_key, 'color' => $color];
    $elapsed += $return_leg_time;
    $map_legs[] = ['key' => $leg_key, 'color' => $color, 'from' => $return_leg_coords['from'], 'to' => $return_leg_coords['to'], 'label' => $prev_name . ' → ' . $end_label];
}
$milestones[] = $public_view
    ? ['short' => 'Finish', 'full' => 'Last activation done', 'min' => $elapsed]
    : ($end_other ? ['short' => 'Finish', 'full' => 'Arrive at ' . $end_label, 'min' => $elapsed]
                  : ['short' => 'Home', 'full' => 'Arrive home', 'min' => $elapsed]);
// With no drive to the first stop (no starting address, or the public view),
// "Depart" and the first "Arrive" land on the same spot; keep just the later one.
$milestones = array_values(array_filter($milestones, fn($m, $k) => !isset($milestones[$k + 1]) || $milestones[$k + 1]['min'] !== $m['min'], ARRAY_FILTER_USE_BOTH));
$total_min = $elapsed;
foreach ($milestones as &$m) { $m['pct'] = $total_min > 0 ? round($m['min'] / $total_min * 100, 1) : 0; }
unset($m);
$segments = array_values(array_filter($segments, fn($s) => $s['minutes'] > 0));

// ── Per-summit leg spans: which portion of the bar (drive-there + hike + radio)
// belongs to each stop, so the timeline can draw one line per summit instead of
// a row of ambiguous dots. Segments for a given stop are already contiguous.
$stop_spans = [];
$cursor = 0;
foreach ($segments as $seg) {
    $seg_start = $cursor;
    $cursor += $seg['minutes'];
    if ($seg['stop'] === null) continue;
    $i = $seg['stop'];
    if (!isset($stop_spans[$i])) {
        $stop_spans[$i] = ['n' => $i + 1, 'name' => $stops[$i]['name'], 'start' => $seg_start, 'end' => $cursor];
    } else {
        $stop_spans[$i]['end'] = $cursor;
    }
}
foreach ($stop_spans as &$sp) {
    $sp['pct_start'] = $total_min > 0 ? round($sp['start'] / $total_min * 100, 2) : 0;
    $sp['pct_end']   = $total_min > 0 ? round($sp['end']   / $total_min * 100, 2) : 0;
}
unset($sp);
$stop_spans = array_values($stop_spans);

// Default start-of-day time used for the server-rendered clock times (before
// JS applies the user's pulldown choice / their saved localStorage preference).
$DEFAULT_START_MIN = 7 * 60; // 7:00 AM
function multi_clock_label($minutes_from_midnight) {
    $m = (($minutes_from_midnight % 1440) + 1440) % 1440;
    $days = intdiv($minutes_from_midnight, 1440);
    $label = date('g:i A', mktime(0, $m, 0));
    if ($days > 0) $label .= ' (+' . $days . 'd)';
    return $label;
}

$SEG_COLORS = ['drive' => '#7A6858', 'hike' => '#6E8155', 'radio' => '#C07840'];

// Aggregate stats snapshot — persisted on save so the dashboard can show a
// summary row for the whole route without recomputing hike/drive times live.
$total_points_sum = array_sum(array_column($stops, 'points'));
$total_hike_min_sum = array_sum(array_column($stops, 'hike_min'));
$total_drive_min_sum = array_sum(array_filter($leg_times)) + ($return_leg_time ?: 0);
$total_dist_mi_sum = array_sum(array_map(fn($s) => $s['dist_mi'] ?? 0, $stops));
$total_elev_ft_sum = array_sum(array_map(fn($s) => $s['elev_ft'] ?? 0, $stops));
// Driving distance only (not persisted — a live stat-tile figure, same as the hike stats above it)
$total_drive_dist_mi_sum = array_sum(array_filter($leg_distances_mi)) + ($return_leg_distance_mi ?: 0);

// ── Auto-save: a multi-activation is always saved, from the moment you land on
// this page — reorder/duplicate/remove/add-summit links all just navigate here
// with a new "ids" list, and every full render re-syncs that composition (and
// the totals snapshot) to the DB. Naming is the only separate, explicit action
// (a small GET form in the header) — see $_GET['name'] below.
$is_saved = true;
$renaming = !$public_view && array_key_exists('name', $_GET);
if ($renaming) $multi_name = trim($_GET['name']);
$was_new = !$multi_id;

if ($public_view) {
    // Read-only: nothing to save
} elseif ($multi_id) {
    $db->prepare("
        UPDATE multi_activations
        SET name = ?, activation_time_min = ?, total_points = ?, total_hike_min = ?,
            total_drive_min = ?, total_time_min = ?, total_dist_mi = ?, total_elev_ft = ?
        WHERE id = ?
    ")->execute([$multi_name ?: null, $activation_time_min, $total_points_sum, $total_hike_min_sum,
                 $total_drive_min_sum, $total_min, $total_dist_mi_sum, $total_elev_ft_sum, $multi_id]);
    $db->prepare("DELETE FROM multi_activation_summits WHERE multi_activation_id = ?")->execute([$multi_id]);
} else {
    $stmt = $db->prepare("
        INSERT INTO multi_activations
            (planning_group_id, name, activation_time_min, created_by,
             total_points, total_hike_min, total_drive_min, total_time_min, total_dist_mi, total_elev_ft)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$current_group['id'], $multi_name ?: null, $activation_time_min, getCurrentCallsign(),
                     $total_points_sum, $total_hike_min_sum, $total_drive_min_sum, $total_min, $total_dist_mi_sum, $total_elev_ft_sum]);
    $multi_id = (int)$db->lastInsertId();
    logActivity($db, 'Multi-activation saved', $multi_name ?: (count($summit_ids) . '-summit route'), count($summit_ids) . ' summits', $current_group['name']);
}
if (!$public_view) {
    $ins = $db->prepare("INSERT INTO multi_activation_summits (multi_activation_id, summit_id, sort_order, leg_drive_time_min) VALUES (?, ?, ?, ?)");
    foreach ($summit_ids as $i => $sid) {
        $ins->execute([$multi_id, $sid, $i, $leg_times[$i] ?? null]);
    }
}

// Canonicalize the URL after a fresh save or a rename, so a reload/bookmark
// never re-triggers either — everything else (reorder etc.) renders in place.
if ($renaming || $was_new) {
    $qs = ['id' => $multi_id, 'ids' => implode(',', $summit_ids), 'group' => $current_group['id'], 'activation_time' => $activation_time_min];
    if ($was_new) $qs['new'] = 1;
    header('Location: multi_activate.php?' . http_build_query($qs));
    exit;
}

// ── URL builder for reorder/remove links ──
$url_base = ['group' => $current_group['id'], 'activation_time' => $activation_time_min];
if ($multi_id) $url_base['id'] = $multi_id;
function multi_url($base, $ids) {
    return 'multi_activate.php?' . http_build_query(array_merge($base, ['ids' => implode(',', $ids), 'ordered' => 1]));
}

// Map data for JS
$map_stops = [];
foreach ($stops as $i => $stop) {
    $map_stops[] = [
        'order' => $i + 1, 'id' => $stop['id'], 'name' => $stop['name'], 'ref' => $stop['ref'],
        'lat' => (float)$stop['lat'], 'lng' => (float)$stop['lng'],
        'summit_lat' => $stop['summit_lat'], 'summit_lng' => $stop['summit_lng'],
        // A summit reached by a BRouter walk is not hiked on its own recorded track (unless the walk
        // down uses it), so drawing that track would show a route that is not part of the plan
        'gpx_path' => (!empty($stop['chained']) && (($stop['alt']['method'] ?? '') === 'brouter') && empty($stop['exit_new'])) ? null : $stop['gpx_path'],
        'chained' => $stop['chained'],
        'route' => $stop['route'] ?? null,
        'alt' => $stop['alt'] ? ['lat' => $stop['alt']['lat'], 'lng' => $stop['alt']['lng'], 'ret' => (($stop['alt']['mode'] ?? '') === 'return')] : null,
        'can_alt' => !$public_view && $i > 0 && !$stop['is_drive_up'] && $stops[$i - 1]['ref'] !== $stop['ref'],
        'prev_id' => $i > 0 ? $stops[$i - 1]['id'] : null,
    ];
}
$map_origin = $origin_geo ? ['lat' => $origin_geo['lat'], 'lng' => $origin_geo['lng'], 'label' => $selected_address['label'] ?? $selected_address['address']] : null;
$CURRENT_IDS = array_values($summit_ids);

// Per-leg coordinates for the map's color-coded route lines (see $LEG_COLORS above)
$MAP_LEGS = [];
foreach ($map_legs as $leg) {
    if (!$leg['from'] || !$leg['to']) continue;
    $MAP_LEGS[] = [
        'key' => $leg['key'], 'color' => $leg['color'], 'label' => $leg['label'],
        'from' => array_map('floatval', explode(',', $leg['from'])),
        'to' => array_map('floatval', explode(',', $leg['to'])),
    ];
}

// Every other summit on this dashboard — for the map's "Show All Summits" toggle,
// so the user can spot a nearby summit and add it to the route without leaving the page.
// Not shown on the public view: that's the owner's private wishlist.
$other_summits = [];
if (!$public_view):
$other_ph = implode(',', array_fill(0, count($summit_ids), '?'));
$stmt = $db->prepare("
    SELECT id, name, sota_ref, points, latitude, longitude, trailhead_lat, trailhead_lng
    FROM summits
    WHERE planning_group_id = ? AND id NOT IN ($other_ph)
");
$stmt->execute(array_merge([$current_group['id']], $summit_ids));
$other_summits = [];
foreach ($stmt->fetchAll() as $s) {
    $lat = $s['trailhead_lat'] ?? $s['latitude'];
    $lng = $s['trailhead_lng'] ?? $s['longitude'];
    if (!$lat || !$lng) continue;
    $other_summits[] = [
        'id' => (int)$s['id'], 'name' => $s['name'], 'ref' => $s['sota_ref'], 'points' => (int)$s['points'],
        'lat' => (float)$lat, 'lng' => (float)$lng,
    ];
}
endif;

$share_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'sotaplanner.com') . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/multi_activate.php?id=' . $multi_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Multi-Activation | SOTAplanner</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
:root {
  --bg: #F7F6F3; --bg-2: #EFEDE8; --bg-3: #E5E2DA;
  --ink: #1C1B19; --ink-2: #4A4844; --ink-3: #8C8A86; --ink-4: #B8B5B0;
  --accent: oklch(52% 0.13 50); --accent-2: oklch(44% 0.13 50);
  --accent-bg: oklch(96% 0.04 65); --accent-border: oklch(84% 0.08 65);
  --green: #2D8653; --green-bg: #EBF5EF;
  --orange: #C07020; --orange-bg: #FDF3E7;
  --red: #C03030; --red-bg: #FBE9E9;
  --blue: #2B5CA0; --blue-bg: #E8EFF9;
  --gray-badge: #6B7280; --gray-bg: #F3F4F6;
  --surface: #FFFFFF; --border: #E5E2DA; --border-2: #D4D0C8;
  --font-sans: 'DM Sans', system-ui, sans-serif;
  --font-mono: 'DM Mono', 'Courier New', monospace;
  --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
  --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
  --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-font-smoothing: antialiased; }
body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }
h1,h2,h3,h4,h5 { font-family: var(--font-sans); font-weight: 600; line-height: 1.2; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

.topbar { background: var(--surface); border-bottom: 1px solid var(--border); height: 56px; display: flex; align-items: center; padding: 0 2rem; gap: 1.5rem; position: sticky; top: 0; z-index: 1001; }
.topbar-logo { display: flex; align-items: center; gap: 0.75rem; text-decoration: none; color: var(--ink); font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0; }
.topbar-logo:hover { text-decoration: none; color: var(--ink); }
.logo-mark { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
.topbar-nav { display: flex; align-items: center; gap: 0.25rem; flex: 1; }
.topbar-nav a { color: var(--ink-3); font-size: 0.875rem; font-weight: 500; padding: 0.5rem 0.75rem; border-radius: var(--r-sm); transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap; }
.topbar-nav a:hover { color: var(--ink); background: var(--bg-2); text-decoration: none; }
.topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; }
.user-chip { position: relative; display: flex; align-items: center; gap: 0.35rem; cursor: pointer; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: var(--ink-2); border: 1px solid var(--border); background: var(--bg); user-select: none; white-space: nowrap; }
.user-chip:hover { background: var(--bg-2); }
.user-chip-chevron { transition: transform 0.15s; flex-shrink: 0; }
.user-chip.open .user-chip-chevron { transform: rotate(180deg); }
.user-dropdown { display: none; position: absolute; top: calc(100% + 6px); right: 0; background: #fff; border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); min-width: 130px; overflow: hidden; z-index: 200; }
.user-chip.open .user-dropdown { display: block; }
.user-dropdown a { display: block; padding: 0.6rem 1rem; font-size: 0.82rem; font-weight: 500; color: var(--ink-2); text-decoration: none; }
.user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

.page { padding: 2rem; max-width: 1400px; margin: 0 auto; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.page-title { font-size: 1.4rem; font-weight: 600; letter-spacing: -0.02em; color: var(--ink); }
.page-subtitle { font-size: 0.875rem; color: var(--ink-3); margin-top: 0.25rem; }
.page-header-right { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0 1rem; height: 36px; border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; transition: background 0.15s, box-shadow 0.15s, transform 0.1s; text-decoration: none; white-space: nowrap; line-height: 1; }
.btn:hover { text-decoration: none; }
.btn:active { transform: scale(0.98); }
.btn-primary { background: var(--ink); color: #fff; }
.btn-primary:hover { background: var(--ink-2); color: #fff; }
.btn-primary:disabled { background: var(--ink-4); cursor: not-allowed; }
.btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
.btn-danger { background: var(--red-bg); color: var(--red); border: 1px solid #e8baba; }
.btn-danger:hover { background: #f5d5d5; }
.btn-secondary { background: var(--bg-2); color: var(--ink); border: 1px solid var(--border); }
.btn-secondary:hover { background: var(--bg-3); color: var(--ink); }
.btn-map-active { background: #6B6865; color: #fff; }
.btn-map-active:hover { background: #5C5956; color: #fff; }
.btn-sm { height: 30px; padding: 0 0.75rem; font-size: 0.8rem; }
.map-buttons { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.75rem; }

.form-label { display: block; font-size: 0.72rem; font-weight: 600; color: var(--ink-3); margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em; }
.form-input, .form-select { display: block; width: 100%; padding: 0.5rem 0.75rem; background: var(--surface); border: 1px solid var(--border-2); border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; color: var(--ink); outline: none; -webkit-appearance: none; }
.form-input:focus, .form-select:focus { border-color: var(--accent); }
.form-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238C8A86' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 2rem; cursor: pointer; }

.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); margin-bottom: 1.5rem; }
.card-title { font-size: 0.95rem; font-weight: 600; margin-bottom: 1rem; }

/* Stat tiles — matches the 4-cell grid on the summit detail page */
.stat-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--r-lg); overflow: hidden; margin-bottom: 1.5rem; }
.stat-cell { background: var(--surface); padding: 1rem 1.25rem; }
.stat-cell-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-3); margin-bottom: 5px; }
.stat-cell-val   { font-size: 1.1rem; font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; line-height: 1.2; }
.stat-cell-sub   { font-size: 0.72rem; color: var(--ink-3); margin-top: 3px; }
@media (max-width: 640px) { .stat-grid-4 { grid-template-columns: 1fr 1fr; } }

.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 100px; font-size: 0.68rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap; }
.badge-easy { background: var(--green-bg); color: var(--green); }
.badge-moderate { background: var(--orange-bg); color: var(--orange); }
.badge-hard { background: var(--red-bg); color: var(--red); }
.badge-drive-up { background: var(--blue-bg); color: var(--blue); }
.badge-nominated { background: var(--blue-bg); color: var(--blue); }
.badge-researched { background: var(--orange-bg); color: var(--orange); }
.badge-ready { background: var(--green-bg); color: var(--green); }
.badge-activated { background: var(--gray-bg); color: var(--gray-badge); }

.msg { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1rem; border-radius: var(--r-md); font-size: 0.875rem; font-weight: 500; margin-bottom: 1rem; }
.msg-success { background: var(--green-bg); color: var(--green); border: 1px solid #b8d9c9; }
.msg-dismiss { background: none; border: none; cursor: pointer; color: inherit; opacity: 0.5; font-size: 1.1rem; padding: 0; line-height: 1; flex-shrink: 0; }
.msg-dismiss:hover { opacity: 1; }

.footer { text-align: center; padding: 2rem 1rem 1.5rem; color: var(--ink-4); font-size: 0.78rem; border-top: 1px solid var(--border); margin-top: 3rem; }
.footer a { color: var(--ink-3); }

/* Tiles */
.tiles-row { display: flex; gap: 0; align-items: stretch; overflow-x: auto; padding: 4px 4px 0.4rem; }
.stop-col { flex: 0 0 220px; display: flex; flex-direction: column; }
.stop-col .tile { flex: none; width: 100%; }
.flow-down { flex: 1; min-height: 28px; display: flex; align-items: center; justify-content: center; color: var(--ink-4); font-size: 1.1rem; }
.after-box { background: var(--bg-2); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 0.7rem 0.75rem; }
.after-box.tile-next-err { border-color: var(--red); }
.after-pick { font-size: 0.8rem; font-weight: 600; color: var(--ink); }
.after-drive { font-size: 0.74rem; color: var(--ink-2); margin-top: 0.4rem; }
.tile-next-msg.warn { color: var(--orange); background: var(--orange-bg); }
.conn { flex: 0 0 44px; position: relative; --cc: var(--ink-4); }
.conn-foot { --cc: var(--green); }
.conn i { position: absolute; display: block; }
.conn .c-h1 { left: 0; width: 50%; height: 2px; bottom: 44px; background: var(--cc); }
.conn .c-v { left: calc(50% - 1px); width: 2px; top: 27px; bottom: 44px; background: var(--cc); }
.conn .c-h2 { left: 50%; right: 7px; height: 2px; top: 27px; background: var(--cc); }
.conn .c-head { right: 0; top: 21px; width: 0; height: 0; border-left: 8px solid var(--cc); border-top: 7px solid transparent; border-bottom: 7px solid transparent; }
.tile-end { flex: 0 0 220px; align-self: stretch; display: flex; flex-direction: column; justify-content: center; gap: 0.9rem; }
.tile { flex: 0 0 220px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 1rem; position: relative; box-shadow: var(--shadow-sm); transition: box-shadow 0.15s, border-color 0.15s, opacity 0.15s; }
.tile-drag-handle, .tile-movable { cursor: grab; }
.tile-movable button, .tile-movable select, .tile-movable a, .tile-movable input { cursor: pointer; }
.tile-movable.dragging, .tile.dragging .tile-order { cursor: grabbing; }
.tile.dragging .tile-drag-handle { cursor: grabbing; }
.tile.dragging { opacity: 0.35; }
.tile.drag-over { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-border); }
.tile-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; }
.tile-order { width: 26px; height: 26px; border-radius: 50%; background: var(--ink); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 700; flex-shrink: 0; }
.tile-drag-handle { color: var(--ink-4); font-size: 0.85rem; letter-spacing: -1px; user-select: none; line-height: 1; }
.tile-name { font-weight: 600; font-size: 0.92rem; margin: 0 0 0.1rem; }
.tile-ref { font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-3); margin-bottom: 0.5rem; }
.tile-stats { display: flex; flex-direction: column; gap: 3px; font-size: 0.78rem; color: var(--ink-2); margin-bottom: 0.6rem; }
.tile-stats span.lbl { color: var(--ink-3); }
.tile-controls { display: flex; align-items: center; justify-content: space-between; gap: 0.4rem; border-top: 1px solid var(--border); padding-top: 0.6rem; }
.tile-reorder { display: flex; gap: 4px; }
.tile-reorder a, .tile-reorder span { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: var(--r-sm); border: 1px solid var(--border-2); color: var(--ink-2); background: var(--bg); cursor: pointer; font-size: 0.75rem; text-decoration: none; }
.tile-reorder a:hover { background: var(--bg-2); color: var(--ink); text-decoration: none; }
.tile-reorder span { opacity: 0.3; cursor: default; }
.tile-actions { display: flex; align-items: center; gap: 10px; }
.tile-arrow-foot { color: var(--green); font-weight: 700; }
.tile-alt { display: flex; align-items: center; gap: 0.5rem; margin: 0 0 0.6rem; font-size: 0.72rem; white-space: nowrap; }
.tile-alt-label { color: var(--green); font-weight: 600; background: var(--green-bg); border-radius: var(--r-sm); padding: 0.15rem 0.45rem; }
.tile-alt-link { font-family: var(--font-sans); font-size: 0.72rem; color: var(--ink-3); background: none; border: none; padding: 0; cursor: pointer; text-decoration: underline; }
.tile-alt-link:hover { color: var(--ink); }
.tile-alt-btn { font-family: var(--font-sans); font-size: 0.72rem; font-weight: 500; color: var(--ink-2); background: var(--surface); border: 1px solid var(--border-2); border-radius: var(--r-sm); padding: 0.2rem 0.5rem; cursor: pointer; }
.tile-alt-btn:hover { background: var(--bg-2); color: var(--ink); }
.alt-suggest { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem 1rem; flex-wrap: wrap; background: var(--green-bg); border: 1px solid #cfe6d8; border-radius: var(--r-md); padding: 0.65rem 0.85rem; margin-bottom: 0.75rem; font-size: 0.85rem; color: var(--ink-2); }
.alt-suggest-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.alt-help { font-size: 0.8rem; color: var(--ink-3); line-height: 1.5; margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px solid var(--border); }
.alt-banner { display: none; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; background: var(--accent-bg); border: 1px solid var(--accent-border); color: var(--accent-2); border-radius: var(--r-md); padding: 0.6rem 0.85rem; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.75rem; }
.alt-banner.on { display: flex; }
.tile-next { margin: 0 0 0.6rem; padding-top: 0.55rem; border-top: 1px dashed var(--border-2); }
.tile-next-label { display: block; font-size: 0.68rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
.tile-next-select { width: 100%; font-family: var(--font-sans); font-size: 0.78rem; color: var(--ink); background: var(--surface); border: 1px solid var(--border-2); border-radius: var(--r-sm); padding: 0.3rem 0.4rem; }
.tile-next-msg { font-size: 0.72rem; line-height: 1.35; color: var(--red); background: var(--red-bg); border-radius: var(--r-sm); padding: 0.3rem 0.45rem; margin-top: 0.35rem; white-space: normal; }
.act-stat { display: flex; align-items: center; flex-wrap: wrap; gap: 0.3rem; }
.act-stat .lbl { white-space: nowrap; }
.act-input { width: 52px; }
.act-custom { font-size: 0.62rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--accent); background: var(--accent-bg); border-radius: var(--r-sm); padding: 0.05rem 0.3rem; margin-left: 0.2rem; }
.act-edit { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border: none; background: transparent; color: var(--ink-3); border-radius: var(--r-sm); cursor: pointer; }
.act-edit:hover { background: var(--bg-2); color: var(--ink); }
.act-input { width: 58px; font-family: var(--font-sans); font-size: 0.8rem; border: 1px solid var(--accent); border-radius: var(--r-sm); padding: 0.1rem 0.3rem; }
.act-btn { font-family: var(--font-sans); font-size: 0.72rem; font-weight: 600; border: 1px solid var(--border-2); background: var(--surface); color: var(--ink-2); border-radius: var(--r-sm); padding: 0.12rem 0.45rem; cursor: pointer; }
.act-btn.primary { background: var(--ink); border-color: var(--ink); color: #fff; }
.act-link { font-size: 0.7rem; color: var(--ink-3); background: none; border: none; text-decoration: underline; cursor: pointer; padding: 0; }
.seg { display: flex; flex-direction: column; gap: 0.3rem; }
.seg button { display: flex; align-items: center; gap: 0.5rem; width: 100%; text-align: left; font-family: var(--font-sans); font-size: 0.78rem; font-weight: 500; line-height: 1.25; padding: 0.42rem 0.55rem; border: 1px solid var(--border-2); border-radius: var(--r-md); background: var(--surface); color: var(--ink-2); cursor: pointer; transition: background 0.12s, border-color 0.12s, color 0.12s; }
.seg button::before { content: ''; flex: 0 0 14px; height: 14px; border-radius: 50%; border: 1.5px solid var(--ink-4); background: var(--surface); box-sizing: border-box; }
.seg button:hover:not(.on):not(:disabled) { border-color: var(--accent-border); background: var(--accent-bg); color: var(--ink); }
.seg button.on { background: var(--accent-bg); border-color: var(--accent); color: var(--accent-2); font-weight: 600; cursor: default; }
.seg button.on::before { border-color: var(--accent); background: radial-gradient(circle, var(--accent) 0 40%, var(--surface) 45%); }
.seg button.on[data-auto] { cursor: pointer; }
.seg button:disabled { color: var(--ink-4); background: var(--bg-2); cursor: not-allowed; }
.seg button:disabled::before { border-color: var(--border-2); }
.tile-next-msg.warn { color: var(--orange); background: var(--orange-bg); }
.conn { flex: 0 0 44px; position: relative; --cc: var(--ink-4); }
.conn-foot { --cc: var(--green); }
.conn i { position: absolute; display: block; }
.conn .c-h1 { left: 0; width: 50%; height: 2px; bottom: 44px; background: var(--cc); }
.conn .c-v { left: calc(50% - 1px); width: 2px; top: 27px; bottom: 44px; background: var(--cc); }
.conn .c-h2 { left: 50%; right: 7px; height: 2px; top: 27px; background: var(--cc); }
.conn .c-head { right: 0; top: 21px; width: 0; height: 0; border-left: 8px solid var(--cc); border-top: 7px solid transparent; border-bottom: 7px solid transparent; }
.tile-end { flex: 0 0 220px; align-self: stretch; display: flex; flex-direction: column; justify-content: center; gap: 0.9rem; }
.tile { flex: 0 0 220px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 1rem; position: relative; box-shadow: var(--shadow-sm); transition: box-shadow 0.15s, border-color 0.15s, opacity 0.15s; }
.tile-drag-handle, .tile-movable { cursor: grab; }
.tile-movable button, .tile-movable select, .tile-movable a, .tile-movable input { cursor: pointer; }
.tile-movable.dragging, .tile.dragging .tile-order { cursor: grabbing; }
.tile.dragging .tile-drag-handle { cursor: grabbing; }
.tile.dragging { opacity: 0.35; }
.tile.drag-over { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-border); }
.tile-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; }
.tile-order { width: 26px; height: 26px; border-radius: 50%; background: var(--ink); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 700; flex-shrink: 0; }
.tile-drag-handle { color: var(--ink-4); font-size: 0.85rem; letter-spacing: -1px; user-select: none; line-height: 1; }
.tile-name { font-weight: 600; font-size: 0.92rem; margin: 0 0 0.1rem; }
.tile-ref { font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-3); margin-bottom: 0.5rem; }
.tile-stats { display: flex; flex-direction: column; gap: 3px; font-size: 0.78rem; color: var(--ink-2); margin-bottom: 0.6rem; }
.tile-stats span.lbl { color: var(--ink-3); }
.tile-controls { display: flex; align-items: center; justify-content: space-between; gap: 0.4rem; border-top: 1px solid var(--border); padding-top: 0.6rem; }
.tile-reorder { display: flex; gap: 4px; }
.tile-reorder a, .tile-reorder span { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: var(--r-sm); border: 1px solid var(--border-2); color: var(--ink-2); background: var(--bg); cursor: pointer; font-size: 0.75rem; text-decoration: none; }
.tile-reorder a:hover { background: var(--bg-2); color: var(--ink); text-decoration: none; }
.tile-reorder span { opacity: 0.3; cursor: default; }
.tile-actions { display: flex; align-items: center; gap: 10px; }
.tile-arrow-foot { color: var(--green); font-weight: 700; }
.tile-alt { display: flex; align-items: center; gap: 0.5rem; margin: 0 0 0.6rem; font-size: 0.72rem; white-space: nowrap; }
.tile-alt-label { color: var(--green); font-weight: 600; background: var(--green-bg); border-radius: var(--r-sm); padding: 0.15rem 0.45rem; }
.tile-alt-link { font-family: var(--font-sans); font-size: 0.72rem; color: var(--ink-3); background: none; border: none; padding: 0; cursor: pointer; text-decoration: underline; }
.tile-alt-link:hover { color: var(--ink); }
.tile-alt-btn { font-family: var(--font-sans); font-size: 0.72rem; font-weight: 500; color: var(--ink-2); background: var(--surface); border: 1px solid var(--border-2); border-radius: var(--r-sm); padding: 0.2rem 0.5rem; cursor: pointer; }
.tile-alt-btn:hover { background: var(--bg-2); color: var(--ink); }
.alt-suggest { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem 1rem; flex-wrap: wrap; background: var(--green-bg); border: 1px solid #cfe6d8; border-radius: var(--r-md); padding: 0.65rem 0.85rem; margin-bottom: 0.75rem; font-size: 0.85rem; color: var(--ink-2); }
.alt-suggest-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.alt-help { font-size: 0.8rem; color: var(--ink-3); line-height: 1.5; margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px solid var(--border); }
.alt-banner { display: none; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; background: var(--accent-bg); border: 1px solid var(--accent-border); color: var(--accent-2); border-radius: var(--r-md); padding: 0.6rem 0.85rem; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.75rem; }
.alt-banner.on { display: flex; }
.tile-next { margin: 0 0 0.6rem; padding-top: 0.55rem; border-top: 1px dashed var(--border-2); }
.tile-next-label { display: block; font-size: 0.68rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
.tile-next-select { width: 100%; font-family: var(--font-sans); font-size: 0.78rem; color: var(--ink); background: var(--surface); border: 1px solid var(--border-2); border-radius: var(--r-sm); padding: 0.3rem 0.4rem; }
.tile-next-msg { font-size: 0.72rem; line-height: 1.35; color: var(--red); background: var(--red-bg); border-radius: var(--r-sm); padding: 0.3rem 0.45rem; margin-top: 0.35rem; white-space: normal; }
.act-stat { display: flex; align-items: center; flex-wrap: wrap; gap: 0.3rem; }
.act-stat .lbl { white-space: nowrap; }
.act-input { width: 52px; }
.act-custom { font-size: 0.62rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--accent); background: var(--accent-bg); border-radius: var(--r-sm); padding: 0.05rem 0.3rem; margin-left: 0.2rem; }
.act-edit { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border: none; background: transparent; color: var(--ink-3); border-radius: var(--r-sm); cursor: pointer; }
.act-edit:hover { background: var(--bg-2); color: var(--ink); }
.act-input { width: 58px; font-family: var(--font-sans); font-size: 0.8rem; border: 1px solid var(--accent); border-radius: var(--r-sm); padding: 0.1rem 0.3rem; }
.act-btn { font-family: var(--font-sans); font-size: 0.72rem; font-weight: 600; border: 1px solid var(--border-2); background: var(--surface); color: var(--ink-2); border-radius: var(--r-sm); padding: 0.12rem 0.45rem; cursor: pointer; }
.act-btn.primary { background: var(--ink); border-color: var(--ink); color: #fff; }
.act-link { font-size: 0.7rem; color: var(--ink-3); background: none; border: none; text-decoration: underline; cursor: pointer; padding: 0; }
.tile-end-add { display: flex; flex-direction: column; align-items: center; gap: 0.2rem; text-decoration: none; color: var(--ink-3); padding: 0.6rem; border: 1px dashed var(--border-2); border-radius: var(--r-md); }
.tile-end-add:hover { color: var(--ink); background: var(--bg-2); }
.tile-end-finish { padding-bottom: 0.2rem; }
.finish-val { font-size: 0.85rem; font-weight: 500; }
.finish-edit { display: flex; flex-direction: column; align-items: flex-start; gap: 0.35rem; }
.finish-edit[hidden] { display: none; }
.tile-or { display: flex; align-items: center; gap: 0.6rem; color: var(--ink-4); font-size: 0.68rem; font-weight: 700; letter-spacing: 0.08em; }
.tile-or::before, .tile-or::after { content: ''; flex: 1; height: 1px; background: var(--border-2); }
.tile-next-msg.ok { color: var(--green); background: var(--green-bg); }
#multi-map.picking,
#multi-map.picking .leaflet-interactive,
#multi-map.picking.leaflet-grab,
#multi-map.picking.leaflet-dragging,
#multi-map.picking .leaflet-grab,
#multi-map.picking .leaflet-marker-icon { cursor: crosshair !important; }
/* While choosing a point, clicks on stop markers fall through to the map */
#multi-map.picking .leaflet-marker-icon { pointer-events: none; }
.lmap-flag { width: 22px; height: 22px; border-radius: 50%; background: var(--green); color: #fff; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,0.35); display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; }
.tile-dup { font-size: 0.72rem; color: var(--ink-3); text-decoration: none; }
.tile-dup:hover { color: var(--ink); text-decoration: underline; }
.tile-remove {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; margin-left: auto; border-radius: var(--r-sm);
  color: var(--red); text-decoration: none;
}
.tile-remove:hover { background: var(--red-bg); }
.tile-warn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 17px; height: 17px; border-radius: 50%;
  background: var(--red-bg); color: var(--red); border: 1px solid oklch(85% 0.08 22);
  font-size: 0.68rem; font-weight: 800; text-decoration: none; flex-shrink: 0;
}
.tile-warn:hover { background: var(--red); color: #fff; }
.tile-arrow { display: flex; align-items: center; color: var(--ink-4); font-size: 1.1rem; flex-shrink: 0; padding: 0 2px; }
.tile-add {
  flex: 0 0 220px; display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 6px; min-height: 168px; border: 2px dashed var(--border-2); border-radius: var(--r-lg);
  color: var(--ink-3); text-decoration: none; transition: all 0.15s;
}
.tile-add:hover { border-color: var(--accent); color: var(--accent-2); background: var(--accent-bg); text-decoration: none; }
.tile-add-plus { font-size: 1.9rem; font-weight: 300; line-height: 1; }
.tile-add-label { font-size: 0.8rem; font-weight: 600; }

/* Map */
.map-wrap { position: relative; border-radius: var(--r-lg); overflow: hidden; border: 1px solid var(--border); height: 420px; }
#multi-map { width: 100%; height: 100%; }
.lmap-num { width: 26px; height: 26px; border-radius: 50%; background: var(--ink); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; box-shadow: 0 1px 4px rgba(0,0,0,0.35); }
/* Wider pill instead of a circle when a summit is visited more than once in the
   route (e.g. re-activated across UTC midnight for double points) — shows every
   visit's stop number instead of the later marker hiding the earlier one. */
.lmap-num-multi { width: auto; min-width: 34px; padding: 0 6px; border-radius: 13px; font-size: 0.68rem; letter-spacing: -0.02em; white-space: nowrap; }
.lmap-home { width: 26px; height: 26px; border-radius: 50%; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; box-shadow: 0 1px 4px rgba(0,0,0,0.35); }
.lmap-other { width: 18px; height: 18px; border-radius: 50%; background: #fff; border: 2px solid var(--ink-4); color: var(--ink-2); display: flex; align-items: center; justify-content: center; font-size: 0.6rem; font-weight: 700; box-shadow: 0 1px 3px rgba(0,0,0,0.25); }
#btn-show-all-summits.active { background: var(--ink); color: #fff; border-color: var(--ink); }
.lmap-add-btn { cursor: pointer; padding: 4px 10px; border-radius: 6px; border: none; background: var(--ink); color: #fff; font-size: 0.78rem; font-family: var(--font-sans); }
.lmap-add-btn:hover { background: var(--ink-2); }
.lmap-coords { font-family: monospace; font-size: 0.82rem; margin-bottom: 8px; user-select: all; }
.map-legend { display: flex; gap: 1.1rem; flex-wrap: wrap; margin-top: 0.6rem; font-size: 0.76rem; color: var(--ink-3); }
.map-legend span { display: inline-flex; align-items: center; gap: 5px; }
.legend-swatch { display: inline-block; width: 14px; height: 3px; border-radius: 2px; }
.legend-swatch.legend-dashed { height: 0; border-top: 2px dashed; width: 14px; background: none; }
.legend-chip { cursor: pointer; padding: 2px 6px; border-radius: 4px; transition: background 0.12s, color 0.12s; }
.legend-chip:hover, .legend-chip.hl-active { background: var(--bg-2); color: var(--ink); font-weight: 600; }

/* Map loading overlay (recalculating routes on reorder / initial load) — same
   mountain-trace mark used elsewhere on the site (see summit_detail.php). */
.map-loading-overlay {
  position: absolute; inset: 0; z-index: 10;
  background: rgba(20,19,18,0.48);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  border-radius: inherit;
  transition: opacity 0.4s;
}
.map-loading-overlay.hidden { opacity: 0; pointer-events: none; }
.map-loading-card {
  background: #fff; border-radius: var(--r-xl); padding: 1.5rem 2rem;
  display: flex; flex-direction: column; align-items: center; gap: 0.65rem;
  box-shadow: 0 8px 32px rgba(0,0,0,0.22);
}
.map-loading-svg { width: 72px; height: 72px; overflow: visible; }
.map-logo-path { stroke-dasharray: 116; stroke-dashoffset: 116; animation: map-path-draw 3s ease-in-out infinite; }
@keyframes map-path-draw {
  0%   { stroke-dashoffset: 116; opacity: 0; }
  7%   { stroke-dashoffset: 116; opacity: 1; }
  62%  { stroke-dashoffset: 0;   opacity: 1; }
  80%  { stroke-dashoffset: 0;   opacity: 1; }
  94%  { stroke-dashoffset: 0;   opacity: 0; }
  100% { stroke-dashoffset: 116; opacity: 0; }
}
.map-logo-dot { fill: var(--red); transform-box: fill-box; transform-origin: center; animation: map-dot-pop 3s ease-in-out 1.2s infinite; opacity: 0; }
@keyframes map-dot-pop {
  0%   { transform: scale(0);   opacity: 0; }
  15%  { transform: scale(1.4); opacity: 1; }
  30%  { transform: scale(1);   opacity: 1; }
  72%  { transform: scale(1);   opacity: 1; }
  90%  { transform: scale(0.4); opacity: 0; }
  100% { transform: scale(0);   opacity: 0; }
}
.map-logo-ring1 { stroke: var(--red); transform-box: fill-box; transform-origin: center; animation: map-ring-pulse 3s ease-out 1.2s infinite; opacity: 0; }
.map-logo-ring2 { stroke: var(--red); transform-box: fill-box; transform-origin: center; animation: map-ring-pulse 3s ease-out 1.5s infinite; opacity: 0; }
@keyframes map-ring-pulse {
  0%   { transform: scale(0.5); opacity: 0; }
  10%  { opacity: 0.5; }
  68%  { transform: scale(2.4); opacity: 0; }
  100% { transform: scale(2.4); opacity: 0; }
}
.map-loading-label { font-size: 0.85rem; font-weight: 500; color: var(--ink-2); text-align: center; line-height: 1.4; }

/* Cross-highlight (map leg ⇄ gantt segment ⇄ tile) hover states */
.marker-hl { transform: scale(1.35) !important; box-shadow: 0 0 0 3px #fff, 0 2px 10px rgba(0,0,0,0.5) !important; z-index: 1000 !important; }
.lmap-num { transition: transform 0.12s, box-shadow 0.12s; }
.time-bar-seg { transition: opacity 0.12s, box-shadow 0.12s; }
.time-bar-seg.hl-dim { opacity: 0.35; }
.time-bar-seg.hl-active { box-shadow: inset 0 0 0 2px rgba(255,255,255,0.9), 0 0 0 2px rgba(0,0,0,0.2); }
.leg-span { transition: opacity 0.12s; }
.leg-span.hl-dim { opacity: 0.35; }
.leg-span.hl-active { border-top-color: var(--accent); border-top-width: 3px; }
.leg-span.hl-active .leg-span-label { color: var(--accent-2); font-weight: 700; }
.tile.hl-dim { opacity: 0.45; }
.tile.hl-active { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-border); }

/* Gantt */
.time-bar { height: 44px; border-radius: var(--r-md); overflow: hidden; display: flex; gap: 2px; margin-bottom: 6px; }
.time-bar-seg { display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden; min-width: 0; padding: 0 6px; }
.time-bar-label { color: rgba(255,255,255,0.95); font-size: 0.66rem; font-weight: 600; white-space: nowrap; line-height: 1.25; }
.time-bar-time { color: rgba(255,255,255,0.8); font-size: 0.62rem; font-weight: 400; white-space: nowrap; line-height: 1.25; }

/* Leg-association row: one horizontal line per summit, spanning the drive-there
   + hike + radio portion of the bar, with the summit name centered on the line —
   makes it obvious at a glance which blocks below belong to which summit. */
.leg-row { position: relative; height: 24px; margin-bottom: 4px; }
.leg-span { position: absolute; top: 50%; height: 0; border-top: 2px solid var(--border-2); }
.leg-span::before, .leg-span::after { content: ''; position: absolute; top: -4px; width: 1px; height: 8px; background: var(--ink-4); }
.leg-span::before { left: 0; }
.leg-span::after { right: 0; }
.leg-span-label { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); background: var(--surface); padding: 0 8px; font-size: 0.72rem; font-weight: 600; color: var(--ink-2); white-space: nowrap; }

/* Time-tick row below the bar: clock times at each milestone, driven by the
   Start Time pulldown (see updateStartTime() in JS). */
.tick-row { position: relative; height: 34px; margin-top: 6px; }
.tick { position: absolute; top: 0; transform: translateX(-50%); text-align: center; white-space: nowrap; }
.tick-mark { width: 1px; height: 6px; background: var(--ink-4); margin: 0 auto 3px; }
.tick-type { font-size: 0.62rem; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.04em; }
.tick-time { font-size: 0.7rem; color: var(--ink); font-weight: 600; font-variant-numeric: tabular-nums; }

/* Start-time / activation-time pulldowns, left-aligned under the card title */
.timeline-controls { display: flex; align-items: flex-end; gap: 0.75rem; margin-bottom: 1.25rem; }

/* Inset the whole leg-row/time-bar/tick-row assembly from the card edges so the
   first and last tick labels (Depart / Home) have room to breathe — all three
   rows share this narrower coordinate space so their percentage positions stay
   aligned with each other. */
.timeline-track { margin: 0 2rem; }

/* Compact rename control + delete, in the page header next to the activation-time pulldown */
.save-inline { display: flex; align-items: center; gap: 0.4rem; }
.save-inline .form-input { width: 150px; padding: 0.4rem 0.6rem; font-size: 0.8rem; }
.btn-icon { width: 36px; padding: 0; flex-shrink: 0; }
.import-cta { display: flex; justify-content: center; margin: 0.5rem 0 1.5rem; }
.import-cta-btn { height: auto; padding: 0.9rem 2rem; font-size: 1.05rem; font-weight: 600; border-radius: var(--r-md); box-shadow: 0 4px 12px rgba(28,27,25,0.18); text-align: center; white-space: normal; }
.import-cta-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(28,27,25,0.24); }

@media (max-width: 768px) {
  .page { padding: 1rem; }
  .topbar { padding: 0 1rem; }
  .topbar-nav { display: none; }
  .page-header { flex-direction: column; }
  .map-wrap { height: 300px; }
}
</style>
</head>
<body>

<nav class="topbar">
  <a href="index.php" class="topbar-logo">
    <div class="logo-mark"><img src="sota-planner-logo.svg" width="32" height="32" alt=""></div>
    <span>SOTAplanner</span>
  </a>
  <div class="topbar-divider"></div>
  <div class="topbar-nav">
    <?php if (getCurrentCallsign()): ?>
    <a href="index.php">Dashboard</a>
    <a href="planning_groups.php">Manage Dashboards</a>
    <?php endif; ?>
    <a href="about.php">About</a>
  </div>
  <div class="topbar-right">
    <?php if (getCurrentCallsign()): ?>
    <div class="user-chip" id="userChip" onclick="this.classList.toggle('open')">
      <?= htmlspecialchars(getCurrentCallsign()) ?>
      <svg class="user-chip-chevron" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="2,3.5 5,6.5 8,3.5"/></svg>
      <div class="user-dropdown"><a href="logout.php">Sign Out</a></div>
    </div>
    <?php else: ?>
    <a href="login.php" class="btn btn-ghost btn-sm">Sign In</a>
    <?php endif; ?>
  </div>
</nav>

<div class="page">

  <?php if (!$public_view && !empty($_GET['new'])): ?>
  <div class="msg msg-success">
    <span>This route is saved automatically as you go. It's now nested under the first summit on your dashboard. Use the trashcan to drop it.</span>
    <button class="msg-dismiss" onclick="this.parentElement.remove()">×</button>
  </div>
  <?php endif; ?>

  <div class="page-header">
    <div>
      <div class="page-title"><?= htmlspecialchars($multi_name ?: 'Multi-Activation Route') ?></div>
      <div class="page-subtitle">
        <?= count($stops) ?> summits · <?= $total_min ? formatTime($total_min) : '—' ?> total<?= $public_view ? "" : ", door-to-door" ?>
        <?php if ($public_view): ?>
          <?php if (!empty($multi_row['created_by'])): ?> · Shared by <?= htmlspecialchars($multi_row['created_by']) ?><?php endif; ?>
          · <span style="color:var(--ink-3)">Drive times shown between summits only. Add it to your own dashboard for times from your starting point.</span>
        <?php elseif (!$selected_address): ?> · <span style="color:var(--orange)">No starting address selected. Add one for travel times</span><?php endif; ?>
      </div>
    </div>
    <div class="page-header-right">
      <?php if ($public_view): ?>
      <?php if ($gmaps_url): ?>
        <a href="<?= htmlspecialchars($gmaps_url) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" title="Open a driving route through every stop in Google Maps">Directions ↗</a>
      <?php endif; ?>
      <?php else: ?>
      <button type="button" class="btn btn-ghost btn-sm" onclick="copyShareLink(this)" title="Copy a public link to this route that anyone can open">Share Link</button>
      <a href="<?= htmlspecialchars('multi_activate.php?' . http_build_query(array_merge($url_base, ['ids' => implode(',', $summit_ids), 'ordered' => 1, 'recalc' => 1]))) ?>" class="btn btn-ghost btn-sm">Recalculate Travel Times</a>
      <?php if ($gmaps_url): ?>
        <a href="<?= htmlspecialchars($gmaps_url) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" title="Open a round-trip driving route through every stop in Google Maps">Directions ↗</a>
      <?php endif; ?>
      <a href="#" id="download-pdf-link" class="btn btn-ghost btn-sm" onclick="return downloadMultiPdf(this)" title="Download a printable PDF with the summit list, driving directions, and offline reference maps">Download PDF</a>
      <form method="GET" action="multi_activate.php" class="save-inline">
        <input type="hidden" name="id" value="<?= $multi_id ?>">
        <input type="hidden" name="ids" value="<?= htmlspecialchars(implode(',', $summit_ids)) ?>">
        <input type="hidden" name="group" value="<?= $current_group['id'] ?>">
        <input type="hidden" name="activation_time" value="<?= $activation_time_min ?>">
        <input type="text" name="name" class="form-input" placeholder="Route name (optional)" value="<?= htmlspecialchars($multi_name) ?>">
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
      </form>
      <form method="POST" id="delete-form" style="display:none">
        <input type="hidden" name="delete_multi" value="1">
        <input type="hidden" name="multi_id" value="<?= $multi_id ?>">
      </form>
      <button type="button" class="btn btn-danger btn-sm btn-icon" title="Delete this route"
              onclick="if(confirm('Delete this saved multi-activation route? The individual summits will stay on your dashboard.')) document.getElementById('delete-form').submit();">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5.5 4V2.5A1 1 0 0 1 6.5 1.5h3a1 1 0 0 1 1 1V4M6.5 7.5v4M9.5 7.5v4M3.5 4l.7 8.4A1 1 0 0 0 5.2 13.5h5.6a1 1 0 0 0 1-1.1L12.5 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($public_view): ?>
  <div class="import-cta">
    <a href="multi_add_to_dashboard.php?id=<?= (int)$multi_id ?>" class="btn btn-primary import-cta-btn">Add this Multi-Summit to your own dashboard</a>
  </div>
  <?php endif; ?>

  <!-- Stat tiles -->
  <div class="stat-grid-4">
    <div class="stat-cell">
      <div class="stat-cell-label">Points</div>
      <div class="stat-cell-val"><?= $total_points_sum ?: '—' ?></div>
      <div class="stat-cell-sub"><?= count($stops) ?> summit<?= count($stops) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="stat-cell">
      <div class="stat-cell-label">Hike Distance</div>
      <?php if ($total_dist_mi_sum): ?>
        <div class="stat-cell-val"><?= number_format(convertDistance($total_dist_mi_sum, $user_units), 2) ?> <span style="font-size:0.7rem; font-weight:500; color:var(--ink-3);"><?= getDistanceUnit($user_units) ?></span></div>
        <div class="stat-cell-sub"><?= number_format(convertElevation($total_elev_ft_sum, $user_units)) ?> <?= getElevationUnit($user_units) ?> gain</div>
      <?php else: ?>
        <div class="stat-cell-val" style="color:var(--ink-3); font-size:0.875rem;">—</div>
        <div class="stat-cell-sub">No hike data yet</div>
      <?php endif; ?>
    </div>
    <div class="stat-cell">
      <div class="stat-cell-label">Drive Distance</div>
      <?php if ($total_drive_dist_mi_sum): ?>
        <div class="stat-cell-val"><?= number_format(convertDistance($total_drive_dist_mi_sum, $user_units), 2) ?> <span style="font-size:0.7rem; font-weight:500; color:var(--ink-3);"><?= getDistanceUnit($user_units) ?></span></div>
        <div class="stat-cell-sub"><?= $public_view ? 'between summits' : ($end_other ? 'start to finish' : 'round trip') ?></div>
      <?php else: ?>
        <div class="stat-cell-val" style="color:var(--ink-3); font-size:0.875rem;">—</div>
        <div class="stat-cell-sub"><?= $selected_address ? 'No route yet' : 'Add a starting address' ?></div>
      <?php endif; ?>
    </div>
    <div class="stat-cell" style="background:#6B6865;">
      <div class="stat-cell-label" style="color:rgba(255,255,255,0.45);">Total Time</div>
      <?php if ($total_min): ?>
        <div class="stat-cell-val" style="color:#fff;"><?= formatTime($total_min) ?></div>
        <div class="stat-cell-sub" style="color:rgba(255,255,255,0.4);"><?= $public_view ? 'first trailhead to last' : 'doorstep to doorstep' ?></div>
      <?php else: ?>
        <div class="stat-cell-val" style="color:rgba(255,255,255,0.35); font-size:0.875rem;">—</div>
        <div class="stat-cell-sub" style="color:rgba(255,255,255,0.3);">add distances first</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tiles: reorder (drag or arrows) / remove -->
  <div class="card">
    <div class="card-title">Route Order<?php if (!$public_view): ?> <span id="reorder-hint" style="font-weight:400; color:var(--ink-3); font-size:0.78rem;">(drag tiles to reorder)</span><?php endif; ?></div>
    <?php foreach ($alt_suggestions as $si => $sg): ?>
    <div class="alt-suggest">
      <div class="alt-suggest-text"><?php if (($sg['mode'] ?? '') === 'return'): ?>
        <strong><?= htmlspecialchars($sg['name']) ?></strong> is on the way back down from <strong><?= htmlspecialchars($sg['prev_name']) ?></strong>. Stop there on your descent instead of returning to the car in between?
        <?php else: ?>
        <strong><?= htmlspecialchars($sg['prev_name']) ?></strong> is on the trail to <strong><?= htmlspecialchars($sg['name']) ?></strong>. Hike on from <?= htmlspecialchars($sg['prev_name']) ?> instead of returning to the car in between?
        <?php endif; ?></div>
      <div class="alt-suggest-actions">
        <button type="button" class="btn btn-primary btn-sm" onclick="acceptAltSuggestion(<?= (int)$si ?>, <?= $sg['lat'] ?>, <?= $sg['lng'] ?>)">Yes, use it as the alternate start</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="altAction('dismiss', <?= (int)$si ?>)">No thanks</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php
      // Finish label: the address nickname, or the address itself when it has none
      $finish_text = $end_other ? multi_leg_address_label($finish_addr)
          : ($selected_address ? multi_leg_address_label($selected_address) : 'the last trailhead');
      $EDIT_SVG = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5l2 2L5 13H3v-2l8.5-8.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>';
    ?>
    <div class="tiles-row" id="tiles-row">
      <?php foreach ($stops as $i => $stop):
          $up_ids = $summit_ids;
          if ($i > 0) { [$up_ids[$i - 1], $up_ids[$i]] = [$up_ids[$i], $up_ids[$i - 1]]; }
          $down_ids = $summit_ids;
          if ($i < count($summit_ids) - 1) { [$down_ids[$i], $down_ids[$i + 1]] = [$down_ids[$i + 1], $down_ids[$i]]; }
          // Remove/duplicate operate on this tile's POSITION, not the summit id: a summit can appear
          // more than once (e.g. re-activating after UTC midnight for double points)
          $remove_ids = $summit_ids;
          unset($remove_ids[$i]);
          $remove_ids = array_values($remove_ids);
          $dup_ids = $summit_ids;
          array_splice($dup_ids, $i + 1, 0, [$stop['id']]);

          $on_foot = !empty($stop['chained']);
          $alt_ret = $on_foot && (($stop['alt']['mode'] ?? '') === 'return');
          $has_next = $i < count($stops) - 1;
          $nx = $has_next ? $stops[$i + 1] : null;
          $nx_hike = $has_next && !empty($nx['chained']);
          $nx_method = $nx['alt']['method'] ?? null;
          $nx_can_hike = $has_next && !$nx['is_drive_up'] && $nx['ref'] !== $stop['ref'];
          $is_new = !empty($stop['exit_new']);
          $sel = $nx_hike ? 'hike' : ($is_new ? 'new' : 'back');
          // Wording follows the flow: a drive-up summit has no hike to come back from, so the choice is
          // simply what happens next
          if (!empty($stop['is_drive_up'])) $back_label = $has_next ? 'Drive on to #' . ($i + 2) : ($end_other ? 'Drive to finish' : 'Drive home');
          else $back_label = $on_foot ? 'Back the way we came' : 'Return to hike start point';
          $can_alt = !$public_view && $has_next && !$nx['is_drive_up'] && $nx['ref'] !== $stop['ref'];
          $me_cs = getCurrentCallsign();
      ?>
      <?php if ($i > 0): ?><div class="conn<?= $on_foot ? ' conn-foot' : '' ?>" title="<?= $on_foot ? 'Hiked on foot from the previous summit' : 'Drive to the next starting point' ?>"><i class="c-h1"></i><i class="c-v"></i><i class="c-h2"></i><i class="c-head"></i></div><?php endif; ?>
      <div class="stop-col">
      <?php if ($public_view): ?>
      <div class="tile" data-pos="<?= $i ?>">
      <?php else: ?>
      <div class="tile tile-movable" data-pos="<?= $i ?>" title="Drag to reorder"
           onmousedown="this.draggable = !event.target.closest('button,input,select,a,textarea,label')"
           ondragstart="onTileDragStart(event, <?= $i ?>)"
           ondragover="onTileDragOver(event)"
           ondragleave="onTileDragLeave(event)"
           ondrop="onTileDrop(event, <?= $i ?>)"
           ondragend="onTileDragEnd(event)">
      <?php endif; ?>
        <div class="tile-head">
          <div class="tile-order"<?= $public_view ? '' : ' title="Drag to reorder" style="cursor:grab"' ?>><?= $i + 1 ?></div>
          <div style="display:flex; align-items:center; gap:6px;">
            <?php if (!$public_view && !$stop['is_drive_up'] && !$stop['has_trailhead']): ?>
              <a href="trail_research.php?id=<?= $stop['id'] ?>&group=<?= $current_group['id'] ?>" class="tile-warn" title="No starting point saved for <?= htmlspecialchars(addslashes($stop['name'])) ?>. Driving directions fall back to the summit's peak location. Add a starting point in Trail Research.">!</a>
            <?php endif; ?>
            <?php if (!$public_view): ?><div class="tile-drag-handle" title="Drag to reorder" onmousedown="this.closest('.tile').draggable = true">⠿</div><?php endif; ?>
          </div>
        </div>
        <div class="tile-name"><?= htmlspecialchars($stop['name']) ?></div>
        <div class="tile-ref"><?= htmlspecialchars($stop['ref']) ?></div>
        <div class="tile-stats">
          <div><span class="lbl">Points:</span> <?= $stop['points'] ?><?php if ($stop['difficulty']): ?> · <span class="badge badge-<?= htmlspecialchars($stop['difficulty']) ?>"><?= ucwords(str_replace('-', ' ', $stop['difficulty'])) ?></span><?php endif; ?></div>
          <div><span class="lbl">Drive here:</span> <?= $on_foot ? '-' : ($leg_times[$i] ? formatTime($leg_times[$i]) : '—') ?></div>
          <div><span class="lbl">Hike:</span> <?= $alt_ret ? 'On the way down' : ($stop['up_min'] ? formatTime($stop['up_min']) : ($stop['is_drive_up'] ? 'Drive up' : '—')) ?><?= (($stop['alt']['method'] ?? '') === 'estimate') ? ' <span title="No trail track passes through this start point, so this is a straight-line estimate." style="color:var(--orange); font-weight:600;">(est.)</span>' : '' ?><?= (($stop['alt']['method'] ?? '') === 'brouter') ? ' <span title="Timed from a walkable route found on OpenStreetMap trails by BRouter. Climb figures from map data can run low, so treat it as an estimate." style="color:var(--orange); font-weight:600;">(est.)</span>' : '' ?></div>
          <div class="act-stat" id="act-<?= $i ?>"><span class="lbl">On the air:</span> <span class="act-val"><?= (int)$stop['act_min'] ?> min<?= $stop['act_custom'] ? ' <span class="act-custom" title="Set for this summit; the rest use the route default">custom</span>' : '' ?></span><?php if (!$public_view): ?> <button type="button" class="act-edit" title="Change the time on the air at this summit" aria-label="Edit time on the air" onclick="editActTime(<?= $i ?>)"><?= $EDIT_SVG ?></button><?php endif; ?></div>
          <div><span class="lbl">Status:</span> <span class="badge badge-<?= htmlspecialchars($stop['status']) ?>"><?= ucfirst($stop['status']) ?></span></div>
        </div>
        <?php if (!$public_view): ?>
        <div class="tile-controls">
          <div class="tile-reorder">
            <?php if ($i > 0): ?><a href="<?= htmlspecialchars(multi_url($url_base, $up_ids)) ?>" title="Move earlier">◀</a><?php else: ?><span>◀</span><?php endif; ?>
            <?php if ($i < count($summit_ids) - 1): ?><a href="<?= htmlspecialchars(multi_url($url_base, $down_ids)) ?>" title="Move later">▶</a><?php else: ?><span>▶</span><?php endif; ?>
          </div>
          <div class="tile-actions">
            <?php if (count($summit_ids) < $MULTI_MAX): ?>
              <a href="<?= htmlspecialchars(multi_url($url_base, $dup_ids)) ?>" class="tile-dup" title="Add this summit again later in the route, e.g. re-activating after UTC midnight for double points">Duplicate</a>
            <?php endif; ?>
            <?php if (count($summit_ids) > 2): ?>
              <a href="<?= htmlspecialchars(multi_url($url_base, $remove_ids)) ?>" class="tile-remove" title="Remove from route" aria-label="Remove <?= htmlspecialchars(addslashes($stop['name'])) ?> from route" onclick="return confirm('Remove <?= htmlspecialchars(addslashes($stop['name'])) ?> from this route?')">
                <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5.5 4V2.5A1 1 0 0 1 6.5 1.5h3a1 1 0 0 1 1 1V4M6.5 7.5v4M9.5 7.5v4M3.5 4l.7 8.4A1 1 0 0 0 5.2 13.5h5.6a1 1 0 0 0 1-1.1L12.5 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="flow-down">&#8595;</div>

      <div class="after-box<?= ($has_next && !empty($nx['leg_error'])) ? ' tile-next-err' : '' ?>">
        <span class="tile-next-label">After this summit</span>
        <?php if (!$public_view): ?>
        <div class="seg" role="radiogroup">
          <button type="button" role="radio" aria-checked="<?= $sel === 'back' ? 'true' : 'false' ?>" class="<?= $sel === 'back' ? 'on' : '' ?>" onclick="if(!this.classList.contains('on'))setAfter(<?= $i ?>, 'back')"><?= $back_label ?></button>
          <?php if ($has_next): ?>
          <button type="button" role="radio" aria-checked="<?= $sel === 'hike' ? 'true' : 'false' ?>" class="<?= $sel === 'hike' ? 'on' : '' ?>"<?= $nx_can_hike ? '' : ' disabled' ?><?= ($sel === 'hike' && $nx_method !== 'brouter') ? ' data-auto="1" title="Currently using a shared start point. Click to plan the walk with the path finder instead."' : '' ?> onclick="if(!this.classList.contains('on')||this.dataset.auto)setAfter(<?= $i ?>, 'hike')">Hike direct to #<?= $i + 2 ?></button>
          <?php endif; ?>
          <?php if ($on_foot): ?>
          <button type="button" role="radio" aria-checked="<?= $sel === 'new' ? 'true' : 'false' ?>" class="<?= $sel === 'new' ? 'on' : '' ?>" onclick="if(!this.classList.contains('on'))setAfter(<?= $i ?>, 'new')">Down to this summit's trailhead</button>
          <?php endif; ?>
        </div>
        <div class="tile-next-msg" id="after-err-<?= $i ?>" hidden></div>
        <?php else: ?>
        <div class="after-pick"><?= $sel === 'hike' ? 'Hike direct to #' . ($i + 2) : ($sel === 'new' ? "Down to this summit's trailhead" : $back_label) ?></div>
        <?php endif; ?>

        <?php if ($sel === 'hike'): ?>
          <?php if ($nx_method === 'brouter'): ?>
            <div class="tile-next-msg ok">Hike <?= formatTime($nx['up_min']) ?> to #<?= $i + 2 ?>: <?= number_format($nx['leg_dist_mi'], 1) ?> mi, <?= number_format($nx['leg_gain_ft']) ?> ft climb, from the edge of this summit's activation zone.</div>
          <?php else: ?>
            <div class="tile-next-msg ok">Hike to #<?= $i + 2 ?> using the shared alternate start point.
              <?php if ($can_alt): ?><button type="button" class="tile-alt-link" onclick="startAltPin(<?= $i + 1 ?>)">Move</button> <button type="button" class="tile-alt-link" onclick="altAction('reset', <?= $i + 1 ?>)">Reset</button><?php endif; ?></div>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($stop['down_min'] > 0): ?>
            <div class="tile-next-msg ok">
              <?php if ($sel === 'new'): ?>Walk <?= formatTime($stop['down_min']) ?> down this summit's own trail to its starting point.
              <?php elseif (!empty($stop['back_routed'])): ?>Walk <?= formatTime($stop['down_min']) ?> (<?= number_format($stop['dist_down'], 1) ?> mi) back via <?= htmlspecialchars(implode(', ', array_map(fn($n) => '#' . $n, $stop['back_via'] ?? []))) ?> to the start.
              <?php else: ?>Hike down <?= formatTime($stop['down_min']) ?>.<?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($sel === 'new'): ?><div class="tile-next-msg warn">A ride or car needs to be waiting at this starting point.</div><?php endif; ?>
          <?php if ($has_next): ?>
            <?php if (!empty($nx['leg_error'])): ?>
              <div class="tile-next-msg"><strong>No path.</strong> <?= htmlspecialchars($nx['leg_error']) ?> Planned as a return to the hike start point instead.</div>
            <?php endif; ?>
            <?php if (!empty($leg_times[$i + 1])): ?><div class="after-drive">Then drive to #<?= $i + 2 ?>: <?= formatTime($leg_times[$i + 1]) ?></div>
            <?php elseif (empty($nx['leg_error']) && $origin_geo): ?><div class="tile-next-msg">Drive time to #<?= $i + 2 ?> could not be calculated, so the totals leave it out. Check the starting points in Trail Research.</div><?php endif; ?>
          <?php elseif ($return_leg_time && $public_view): ?>
            <div class="after-drive">Then drive to the finish: <?= formatTime($return_leg_time) ?></div>
          <?php endif; ?>
          <?php if ($has_next && !$public_view && isset($alt_lookup['hidden'][$i + 1])): ?>
            <button type="button" class="tile-alt-btn" onclick="altAction('restore', <?= $i + 1 ?>)" title="A shared alternate start exists for this pair of summits. Click to use it.">Use shared start</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$public_view):
          $add_qs = ['group' => $current_group['id'], 'multi_select' => implode(',', $summit_ids)];
          if ($multi_id) $add_qs['multi_edit_id'] = $multi_id;
          $add_summit_url = 'index.php?' . http_build_query($add_qs);
      ?>
      <div class="conn"><i class="c-h1"></i><i class="c-v"></i><i class="c-h2"></i><i class="c-head"></i></div>
      <div class="tile tile-end">
        <div class="tile-end-finish">
          <div class="tile-next-label">Finish at</div>
          <div class="act-stat" id="finish-stat"><span class="act-val finish-val"><?= htmlspecialchars($finish_text) ?></span> <button type="button" class="act-edit" title="Change where the route finishes" aria-label="Edit finish address" onclick="editFinish()"><?= $EDIT_SVG ?></button></div>
          <div class="finish-edit" id="finish-edit" hidden>
            <select class="tile-next-select" id="finish-select" onchange="finishChosen(this.value)">
              <option value="0"<?= !$end_other ? ' selected' : '' ?>><?= $selected_address ? htmlspecialchars(multi_leg_address_label($selected_address)) . ' (start address)' : 'Last trailhead (no address)' ?></option>
              <?php foreach ($dash_addresses as $_a): if ($selected_address && (int)$_a['id'] === (int)$selected_address['id']) continue; ?>
              <option value="<?= (int)$_a['id'] ?>"<?= ($end_other && (int)$finish_addr['id'] === (int)$_a['id']) ? ' selected' : '' ?>><?= htmlspecialchars(multi_leg_address_label($_a)) ?></option>
              <?php endforeach; ?>
              <option value="new">+ Add new address&hellip;</option>
            </select>
            <button type="button" class="act-link" onclick="cancelFinish()">Cancel</button>
          </div>
          <?php if ($return_leg_time): ?><div class="after-drive"><?= $end_other ? 'Drive to finish' : 'Drive home' ?>: <?= formatTime($return_leg_time) ?></div>
          <?php elseif ($origin_geo || $end_other): ?><div class="tile-next-msg">The drive <?= $end_other ? 'to the finish' : 'home' ?> could not be calculated from where this route ends (Google found no road route from that starting point), so the totals leave it out. Check the last summit's starting point in Trail Research.</div><?php endif; ?>
          <?php if ($finish_warn): ?><div class="tile-next-msg"><?= htmlspecialchars($finish_warn) ?></div><?php endif; ?>
        </div>
        <?php if (count($summit_ids) < $MULTI_MAX): ?>
        <div class="tile-or"><span>OR</span></div>
        <a href="<?= htmlspecialchars($add_summit_url) ?>" class="tile-end-add" title="Add another summit to this route">
          <span class="tile-add-plus">+</span>
          <span class="tile-add-label">Add Summit</span>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Master Gantt -->
  <div class="card">
    <div class="card-title" style="display:flex; align-items:baseline; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
      <span>Outing Timeline</span>
      <?php if (!empty($segments)): ?>
        <span style="font-size:0.78rem; font-weight:500; color:var(--ink-3);">Total: <strong style="color:var(--ink); font-weight:600;"><?= formatTime($total_min) ?></strong> <?= $public_view ? 'first trailhead to last' : ($end_other ? 'start to finish' : 'door-to-door, round trip') ?></span>
      <?php endif; ?>
    </div>
    <div class="timeline-controls">
      <div>
        <label class="form-label" for="start_time_select">Start time</label>
        <select class="form-select" id="start_time_select" onchange="updateStartTime()">
          <?php for ($h = 0; $h < 24; $h++): $val = $h * 60; ?>
            <option value="<?= $val ?>"<?= $val === $DEFAULT_START_MIN ? ' selected' : '' ?>><?= date('g:i A', mktime($h, 0, 0)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label class="form-label" for="activation_time_select">Activation time / summit</label>
        <select class="form-select" id="activation_time_select" onchange="changeActivationTime(this.value)">
          <?php foreach ([15, 20, 30, 45, 60, 90, 120] as $opt): ?>
            <option value="<?= $opt ?>"<?= $activation_time_min == $opt ? ' selected' : '' ?>><?= $opt ?> min</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php if (!empty($segments)): ?>
    <div class="timeline-track">
    <div class="leg-row">
      <?php foreach ($stop_spans as $sp): ?>
        <div class="leg-span" data-stop="<?= $sp['n'] - 1 ?>" style="left:<?= $sp['pct_start'] ?>%; width:<?= max(0, $sp['pct_end'] - $sp['pct_start']) ?>%;">
          <span class="leg-span-label">#<?= $sp['n'] ?> <?= htmlspecialchars($sp['name']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="time-bar">
      <?php foreach ($segments as $seg):
          $bg = ($seg['type'] === 'drive' && !empty($seg['color'])) ? $seg['color'] : $SEG_COLORS[$seg['type']];
          $data_attrs = '';
          if (!empty($seg['leg_key'])) $data_attrs .= ' data-leg-key="' . htmlspecialchars($seg['leg_key']) . '"';
          if ($seg['stop'] !== null) $data_attrs .= ' data-stop="' . (int)$seg['stop'] . '"';
      ?>
        <div class="time-bar-seg"<?= $data_attrs ?> style="flex:<?= max(1, $seg['minutes']) ?>; background:<?= $bg ?>;" title="<?= htmlspecialchars($seg['label']) ?>: <?= formatTime($seg['minutes']) ?>">
          <span class="time-bar-label"><?= htmlspecialchars($seg['label']) ?></span>
          <span class="time-bar-time"><?= formatTime($seg['minutes']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="tick-row">
      <?php foreach ($milestones as $m): ?>
        <div class="tick" style="left:<?= $m['pct'] ?>%" title="<?= htmlspecialchars($m['full']) ?>">
          <div class="tick-mark"></div>
          <div class="tick-type"><?= htmlspecialchars($m['short']) ?></div>
          <div class="tick-time" data-elapsed-min="<?= $m['min'] ?>"><?= htmlspecialchars(multi_clock_label($DEFAULT_START_MIN + $m['min'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    </div>
    <?php else: ?>
      <p style="color:var(--ink-3); font-size:0.875rem;">Not enough data yet to build a timeline. Add hike distance/elevation or a starting address.</p>
    <?php endif; ?>
  </div>

  <!-- Map -->
  <div class="card">
    <div class="card-title" style="display:flex; align-items:center; justify-content:space-between;">
      <span>Route Map</span>
      <div style="display:flex; gap:0.4rem;">
        <button type="button" id="btn-zoom-summits" class="btn btn-ghost btn-sm" onclick="zoomToSummits()" title="Fit the map to the summits and hikes only, leaving out the drive">Zoom to Summits</button>
      <?php if (!empty($other_summits)): ?>
        <button type="button" id="btn-show-all-summits" class="btn btn-ghost btn-sm" onclick="toggleOtherSummits()">Show All Summits</button>
      <?php endif; ?>
      </div>
    </div>
    <div class="alt-banner" id="alt-banner">
      <span id="alt-banner-text"></span>
      <button type="button" class="btn btn-ghost btn-sm" onclick="cancelAltPin()">Cancel</button>
    </div>
    <div class="map-wrap">
      <div class="map-loading-overlay" id="map-loading-overlay">
        <div class="map-loading-card">
          <svg class="map-loading-svg" viewBox="0 0 110 110" xmlns="http://www.w3.org/2000/svg">
            <circle cx="55" cy="55" r="50" fill="none" stroke="#1c1b19" stroke-width="1.5" opacity="0.2"/>
            <path class="map-logo-path" d="M26,79.5l17-30,7,8,12-20,22,42"
                  fill="none" stroke="#1c1b19" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <circle class="map-logo-ring2" cx="62" cy="35.5" r="15" fill="none" stroke-width="0.8"/>
            <circle class="map-logo-ring1" cx="62" cy="35.5" r="9"  fill="none" stroke-width="1.2"/>
            <circle class="map-logo-dot"   cx="62" cy="35.5" r="3.5"/>
          </svg>
          <div class="map-loading-label">Calculating routes…</div>
        </div>
      </div>
      <div id="multi-map"></div>
    </div>
    <div class="map-buttons">
      <button type="button" id="btn-base-street"    class="btn btn-sm btn-map-active" onclick="switchBase('street')">Street</button>
      <button type="button" id="btn-base-topo"      class="btn btn-sm btn-secondary"  onclick="switchBase('topo')">Topo</button>
      <button type="button" id="btn-base-satellite" class="btn btn-sm btn-secondary"  onclick="switchBase('satellite')">Satellite</button>
    </div>
    <div class="map-legend" id="map-legend">
      <?php foreach ($MAP_LEGS as $leg): ?>
        <span class="legend-chip" data-leg-key="<?= htmlspecialchars($leg['key']) ?>" title="<?= htmlspecialchars($leg['label']) ?>"><span class="legend-swatch" style="background:<?= htmlspecialchars($leg['color']) ?>;"></span><?= htmlspecialchars($leg['label']) ?></span>
      <?php endforeach; ?>
      <span><span class="legend-swatch" style="background:#2d7a4f;"></span>Trail (GPX)</span>
      <span><span class="legend-swatch legend-dashed" style="border-color:#CC2200;"></span>Activation zone</span>
      <?php if (!empty($other_summits)): ?>
        <span><span class="legend-swatch" style="background:none; border:2px solid var(--ink-4); border-radius:50%; width:10px; height:10px;"></span>Other dashboard summits</span>
      <?php endif; ?>
    </div>
  </div>


</div>

<footer class="footer">
  SOTAplanner &nbsp;·&nbsp; <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

<script>
const MAP_STOPS = <?= json_encode($map_stops, JSON_UNESCAPED_UNICODE) ?>;
const MAP_ORIGIN = <?= json_encode($map_origin, JSON_UNESCAPED_UNICODE) ?>;
const MAP_LEGS = <?= json_encode($MAP_LEGS, JSON_UNESCAPED_UNICODE) ?>;
const OTHER_SUMMITS = <?= json_encode($other_summits, JSON_UNESCAPED_UNICODE) ?>;
const CURRENT_IDS = <?= json_encode($CURRENT_IDS) ?>;
const URL_BASE = <?= json_encode($url_base) ?>;
const MULTI_MAX = <?= (int)$MULTI_MAX ?>;
let mapInstance = null;
let otherSummitsLayer = null;
let otherSummitsVisible = false;
let baseLayers = null;
let activeBase = 'street';

// Cross-highlight state — populated as the map builds its layers
let legLayers = {};      // legKey -> { layer: L.Polyline, color }
let stopMarkerEls = {};  // 0-based stop index -> the marker's DOM element
let stopTrails = {};     // 0-based stop index -> L.Polyline (GPX trail), if any
let hoverActive = false;

function applyHighlight(legKeys, stopIndexes) {
    hoverActive = true;
    Object.entries(legLayers).forEach(([k, l]) => {
        if (legKeys.includes(k)) {
            l.layer.setStyle({ weight: 7, opacity: 1 });
            l.layer.bringToFront();
        } else {
            l.layer.setStyle({ weight: 4, opacity: 0.2 });
        }
    });
    Object.entries(stopMarkerEls).forEach(([idx, el]) => {
        if (el) el.classList.toggle('marker-hl', stopIndexes.includes(parseInt(idx, 10)));
    });
    Object.entries(stopTrails).forEach(([idx, layer]) => {
        if (stopIndexes.includes(parseInt(idx, 10))) {
            layer.setStyle({ weight: 6, opacity: 1 });
            layer.bringToFront();
        } else {
            layer.setStyle({ weight: 3, opacity: 0.25 });
        }
    });
    document.querySelectorAll('.time-bar-seg').forEach(el => {
        const lk = el.getAttribute('data-leg-key');
        const st = el.getAttribute('data-stop');
        const match = (lk && legKeys.includes(lk)) || (st !== null && stopIndexes.includes(parseInt(st, 10)));
        el.classList.toggle('hl-active', match);
        el.classList.toggle('hl-dim', !match);
    });
    document.querySelectorAll('.leg-span').forEach(el => {
        const st = el.getAttribute('data-stop');
        const match = st !== null && stopIndexes.includes(parseInt(st, 10));
        el.classList.toggle('hl-active', match);
        el.classList.toggle('hl-dim', !match);
    });
    document.querySelectorAll('.tile[data-pos]').forEach(el => {
        const pos = el.getAttribute('data-pos');
        const match = pos !== null && stopIndexes.includes(parseInt(pos, 10));
        el.classList.toggle('hl-active', match);
        el.classList.toggle('hl-dim', !match);
    });
    document.querySelectorAll('.legend-chip[data-leg-key]').forEach(el => {
        const lk = el.getAttribute('data-leg-key');
        el.classList.toggle('hl-active', !!lk && legKeys.includes(lk));
    });
}

function clearHighlight() {
    if (!hoverActive) return;
    hoverActive = false;
    Object.values(legLayers).forEach(l => l.layer.setStyle({ color: l.color, weight: 4, opacity: 0.85 }));
    Object.values(stopMarkerEls).forEach(el => { if (el) el.classList.remove('marker-hl'); });
    Object.values(stopTrails).forEach(l => l.setStyle({ weight: 3, opacity: 0.85 }));
    document.querySelectorAll('.hl-active, .hl-dim').forEach(el => el.classList.remove('hl-active', 'hl-dim'));
}

function highlightLeg(legKey) {
    // Drive-to-a-stop legs are keyed "stop-<i>" — highlighting one of these
    // (from the map, the gantt drive block, or the legend) should also light up
    // that summit's whole trip block (drive there, hike up, radio, hike down),
    // not just the drive segment itself.
    const m = /^stop-(\d+)$/.exec(legKey);
    applyHighlight([legKey], m ? [parseInt(m[1], 10)] : []);
}
function highlightStop(stopIndex) {
    const legKey = 'stop-' + stopIndex;
    applyHighlight(legLayers[legKey] ? [legKey] : [], [stopIndex]);
}

function switchBase(name) {
    if (!baseLayers || name === activeBase) return;
    mapInstance.removeLayer(baseLayers[activeBase]);
    baseLayers[name].addTo(mapInstance);
    activeBase = name;
    ['street', 'topo', 'satellite'].forEach(n => {
        const b = document.getElementById('btn-base-' + n);
        if (!b) return;
        b.className = 'btn btn-sm ' + (n === name ? 'btn-map-active' : 'btn-secondary');
    });
}

function changeActivationTime(val) {
    const url = new URL(window.location);
    url.searchParams.set('activation_time', val);
    window.location = url.toString();
}

// ── Start-time pulldown: purely a display offset (doesn't affect any stored
// data), so it recomputes clock times in place via JS instead of reloading.
// Remembered per-browser in localStorage so it sticks across visits.
const START_TIME_STORAGE_KEY = 'multiActivateStartTimeMin';

function formatClockFromMinutes(min) {
    const m = ((min % 1440) + 1440) % 1440;
    const days = Math.floor(min / 1440);
    let h = Math.floor(m / 60), mm = m % 60;
    const ampm = h >= 12 ? 'PM' : 'AM';
    let h12 = h % 12; if (h12 === 0) h12 = 12;
    let s = h12 + ':' + String(mm).padStart(2, '0') + ' ' + ampm;
    if (days > 0) s += ' (+' + days + 'd)';
    return s;
}

function updateStartTime() {
    const sel = document.getElementById('start_time_select');
    if (!sel) return;
    const startMin = parseInt(sel.value, 10);
    try { localStorage.setItem(START_TIME_STORAGE_KEY, startMin); } catch (e) {}
    document.querySelectorAll('[data-elapsed-min]').forEach(el => {
        const elapsed = parseInt(el.getAttribute('data-elapsed-min'), 10);
        el.textContent = formatClockFromMinutes(startMin + elapsed);
    });
}

// Builds the offline-PDF link with the same start time currently shown on
// screen (localStorage, since the server never stores it — see above).
function downloadMultiPdf(link) {
    const params = new URLSearchParams({
        group: URL_BASE.group,
        ids: CURRENT_IDS.join(','),
        activation_time: URL_BASE.activation_time,
    });
    if (URL_BASE.id) params.set('id', URL_BASE.id);
    try {
        const stored = localStorage.getItem(START_TIME_STORAGE_KEY);
        if (stored !== null) params.set('start_time', stored);
    } catch (e) {}
    window.location = 'multi_activate_pdf.php?' + params.toString();
    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('start_time_select');
    if (!sel) return;
    try {
        const saved = localStorage.getItem(START_TIME_STORAGE_KEY);
        if (saved !== null && sel.querySelector('option[value="' + saved + '"]')) sel.value = saved;
    } catch (e) {}
    updateStartTime();
});

document.addEventListener('click', function(e) {
    const chip = document.getElementById('userChip');
    if (chip && !chip.contains(e.target)) chip.classList.remove('open');
});

// ── Disable native HTML5 drag on touch/coarse-pointer devices. Mobile Safari
// never fires dragstart from a single-finger touch, and worse, a draggable
// container can swallow taps on the links nested inside it (the ◀ ▶ arrows,
// Duplicate, Remove, trailhead-warning icon) — so on these devices reordering
// falls back to the arrow buttons, which are real links and always work. ──
if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
    document.querySelectorAll('.tile[draggable]').forEach(function(el) { el.removeAttribute('draggable'); });
    document.querySelectorAll('.tile-drag-handle').forEach(function(el) { el.style.display = 'none'; });
    const hint = document.getElementById('reorder-hint');
    if (hint) hint.textContent = '(tap ◀ ▶ to reorder)';
}

// ── Drag-and-drop tile reorder ──
function navigateToOrder(ids) {
    const params = new URLSearchParams(URL_BASE);
    params.set('ids', ids.join(','));
    params.set('ordered', '1');
    window.location = 'multi_activate.php?' + params.toString();
}
// Position-based, not id-based — a summit can appear more than once in the
// route (re-activated after UTC midnight for double points), so tracking the
// dragged tile by its summit id would move every copy of it. The dragged
// tile's starting index is kept in a variable rather than re-derived with
// indexOf(), which only ever finds the first matching id.
let dragFromPos = null;
// Tiles are only draggable while the grip handle is held (set on its mousedown)
document.addEventListener('mouseup', function() {
    document.querySelectorAll('.tile[draggable="true"]').forEach(function(el) { el.draggable = false; });
});
function onTileDragStart(e, pos) {
    dragFromPos = pos;
    e.dataTransfer.setData('text/plain', String(pos));
    e.dataTransfer.effectAllowed = 'move';
    e.currentTarget.classList.add('dragging');
}
function onTileDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    e.currentTarget.classList.add('drag-over');
}
function onTileDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}
function onTileDrop(e, targetPos) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    const fromPos = dragFromPos;
    if (fromPos === null || fromPos === targetPos) return;
    const ids = CURRENT_IDS.slice();
    const moved = ids.splice(fromPos, 1)[0];
    ids.splice(targetPos, 0, moved);
    navigateToOrder(ids);
}
function onTileDragEnd(e) {
    e.currentTarget.draggable = false;
    e.currentTarget.classList.remove('dragging');
    document.querySelectorAll('.tile.drag-over').forEach(function(el) { el.classList.remove('drag-over'); });
    dragFromPos = null;
}

// ── Map: driving route (OSRM), GPX tracks, activation zones ──
function normalizeAzCoords(poly) {
    if (Array.isArray(poly[0]) && Array.isArray(poly[0][0]) && Array.isArray(poly[0][0][0])) return poly[0][0].map(c => [c[1], c[0]]);
    if (Array.isArray(poly[0]) && Array.isArray(poly[0][0])) return poly[0].map(c => [c[1], c[0]]);
    return poly.map(c => [c[1], c[0]]);
}

async function fetchLegGeometry(from, to) {
    const url = 'https://router.project-osrm.org/route/v1/driving/' + from[1] + ',' + from[0] + ';' + to[1] + ',' + to[0] + '?overview=full&geometries=geojson';
    try {
        const resp = await fetch(url);
        const data = await resp.json();
        if (data.code === 'Ok' && data.routes && data.routes[0]) {
            return data.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
        }
    } catch (e) { /* fall through to straight-line fallback */ }
    return null;
}

// One color-coded polyline per leg, fetched in parallel — lets outbound and
// return trips over the same road show as visibly different colors, and lets
// hovering a leg (here or in the gantt) highlight just that leg.
async function loadLegRoutes(map) {
    if (!MAP_LEGS.length) return;
    const results = await Promise.all(MAP_LEGS.map(leg => fetchLegGeometry(leg.from, leg.to)));
    MAP_LEGS.forEach((leg, i) => {
        const driven = results[i];
        const coords = driven && driven.length > 1 ? driven : [leg.from, leg.to];
        const poly = L.polyline(coords, {
            color: leg.color, weight: 4, opacity: 0.85,
            dashArray: driven ? null : '6 6'
        }).addTo(map).bindTooltip(leg.label);
        poly.on('mouseover', () => highlightLeg(leg.key));
        poly.on('mouseout', clearHighlight);
        legLayers[leg.key] = { layer: poly, color: leg.color };
    });
}

async function initMap() {
    const el = document.getElementById('multi-map');
    if (!el) return;
    const map = L.map('multi-map', { zoomControl: true });
    mapInstance = map;
    baseLayers = {
        street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> contributors', maxZoom: 19 }),
        topo:   L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',   { attribution: '© <a href="https://opentopomap.org">OpenTopoMap</a>',      maxZoom: 17 }),
        satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '© Esri', maxZoom: 19 })
    };
    activeBase = 'street';
    baseLayers.street.addTo(map);

    const bounds = [];

    if (MAP_ORIGIN) {
        const homeIcon = L.divIcon({ className: '', html: '<div class="lmap-home">⌂</div>', iconSize: [26, 26], iconAnchor: [13, 13] });
        L.marker([MAP_ORIGIN.lat, MAP_ORIGIN.lng], { icon: homeIcon }).addTo(map).bindTooltip(MAP_ORIGIN.label || 'Starting Point');
        bounds.push([MAP_ORIGIN.lat, MAP_ORIGIN.lng]);
    }

    // Group stops that revisit the same summit (e.g. re-activated after UTC
    // midnight for double SOTA points) so their markers don't just stack on top
    // of each other — one marker shows every visit's stop number instead of the
    // later marker hiding the earlier one.
    const stopGroups = {};
    const stopGroupOrder = [];
    MAP_STOPS.forEach(function(s) {
        if (!stopGroups[s.id]) { stopGroups[s.id] = []; stopGroupOrder.push(s.id); }
        stopGroups[s.id].push(s);
    });
    stopGroupOrder.forEach(function(id) {
        const group = stopGroups[id];
        const first = group[0];
        const orders = group.map(g => g.order);
        const multi = orders.length > 1;
        const icon = L.divIcon({
            className: '',
            html: '<div class="lmap-num' + (multi ? ' lmap-num-multi' : '') + '">' + orders.join(' & ') + '</div>',
            iconSize: multi ? [34, 26] : [26, 26],
            iconAnchor: multi ? [17, 13] : [13, 13]
        });
        const tooltip = (multi ? 'Visited as #' + orders.join(' and #') : '#' + first.order) + ' ' + first.name + ' (' + first.ref + ')';
        // A stop reached on foot from the previous summit has no trailhead visit, so mark the summit itself
        const markerPos = first.chained ? [first.summit_lat, first.summit_lng] : [first.lat, first.lng];
        const marker = L.marker(markerPos, { icon: icon }).addTo(map).bindTooltip(tooltip);
        const el = marker.getElement();
        const iconEl = el ? el.querySelector('div') : null;
        orders.forEach(o => { stopMarkerEls[o - 1] = iconEl; });
        marker.on('mouseover', () => highlightStop(orders[0] - 1));
        marker.on('mouseout', clearHighlight);
    });

    MAP_STOPS.forEach(function(s) {
        bounds.push(s.chained ? [s.summit_lat, s.summit_lng] : [s.lat, s.lng]);

        // Custom hike start: a green flag, with a dashed line to the summit it leads to
        if (s.route && s.route.length > 1) {
            // Hike direct: the walkable route BRouter found from the previous summit
            L.polyline(s.route, { color: '#2D8653', weight: 4, dashArray: '8 6', opacity: 0.95, lineJoin: 'round' }).addTo(map)
                .bindTooltip('Hike direct from #' + (s.order - 1) + ' to #' + s.order + ' ' + s.name);
            s.route.forEach(function(p) { bounds.push(p); });
        } else if (s.alt && !s.alt.ret) {
            const flag = L.divIcon({ className: '', html: '<div class="lmap-flag">' + s.order + '</div>', iconSize: [22, 22], iconAnchor: [11, 11] });
            L.marker([s.alt.lat, s.alt.lng], { icon: flag }).addTo(map)
                .bindTooltip('Hike to #' + s.order + ' ' + s.name + ' starts here');
            L.polyline([[s.alt.lat, s.alt.lng], [s.summit_lat, s.summit_lng]], { color: '#2D8653', weight: 3, dashArray: '2 7', opacity: 0.9 }).addTo(map);
            bounds.push([s.alt.lat, s.alt.lng]);
        }

        // GPX track for this stop, if one exists
        if (s.gpx_path && s.gpx_path.length > 1) {
            const trail = L.polyline(s.gpx_path, { color: '#2d7a4f', weight: 3, opacity: 0.85, lineJoin: 'round', lineCap: 'round' })
                .addTo(map).bindTooltip('Trail: ' + s.name);
            stopTrails[s.order - 1] = trail;
            trail.on('mouseover', () => highlightStop(s.order - 1));
            trail.on('mouseout', clearHighlight);
        }

        // Activation zone for this stop
        fetch('activation_zone.php?sota_ref=' + encodeURIComponent(s.ref))
            .then(r => r.json())
            .then(data => {
                if (data.polygon) {
                    L.polygon(normalizeAzCoords(data.polygon), { color: '#CC2200', fillColor: '#CC2200', fillOpacity: 0.15, weight: 2, dashArray: '5,4' })
                        .addTo(map).bindTooltip('Activation Zone: ' + s.name);
                }
            })
            .catch(() => {});
    });

    // Click anywhere on the map to get that spot's GPS coordinates with a Copy button
    map.on('click', function(e) {
        if (altPinIndex !== null) {
            const lat = e.latlng.lat.toFixed(6), lng = e.latlng.lng.toFixed(6);
            const pickHtml = '<div class="lmap-coords">' + lat + ', ' + lng + '</div>'
                + '<button class="lmap-add-btn" onclick="confirmAltPin(' + lat + ', ' + lng + ')">Set hike start here</button>';
            L.popup().setLatLng(e.latlng).setContent(pickHtml).openOn(map);
            return;
        }
        const coords = e.latlng.lat.toFixed(6) + ', ' + e.latlng.lng.toFixed(6);
        const popupHtml = '<div class="lmap-coords">' + coords + '</div>'
            + '<button class="lmap-add-btn" onclick="copyMapCoords(this, \'' + coords + '\')">Copy Coordinates</button>';
        L.popup().setLatLng(e.latlng).setContent(popupHtml).openOn(map);
    });

    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [40, 40] });
    } else {
        map.setView([45, -110], 5);
    }

    // Real driving routes, one color-coded polyline per leg — falls back to a
    // dashed straight line per leg if OSRM is unavailable for that leg.
    await loadLegRoutes(map);

    const overlay = document.getElementById('map-loading-overlay');
    if (overlay) {
        overlay.classList.add('hidden');
        setTimeout(() => overlay.remove(), 500);
    }

    // Wire up gantt / tile / legend hover → map cross-highlighting
    document.querySelectorAll('.time-bar-seg').forEach(el => {
        const legKey = el.getAttribute('data-leg-key');
        const stop = el.getAttribute('data-stop');
        el.addEventListener('mouseenter', () => {
            if (legKey) highlightLeg(legKey);
            else if (stop !== null) highlightStop(parseInt(stop, 10));
        });
        el.addEventListener('mouseleave', clearHighlight);
    });
    document.querySelectorAll('.leg-span[data-stop]').forEach(el => {
        el.addEventListener('mouseenter', () => highlightStop(parseInt(el.getAttribute('data-stop'), 10)));
        el.addEventListener('mouseleave', clearHighlight);
    });
    document.querySelectorAll('.tile[data-pos]').forEach(el => {
        el.addEventListener('mouseenter', () => highlightStop(parseInt(el.getAttribute('data-pos'), 10)));
        el.addEventListener('mouseleave', clearHighlight);
    });
    document.querySelectorAll('.legend-chip[data-leg-key]').forEach(el => {
        const legKey = el.getAttribute('data-leg-key');
        el.addEventListener('mouseenter', () => highlightLeg(legKey));
        el.addEventListener('mouseleave', clearHighlight);
    });
}
initMap();

// ── "Zoom to Summits": fit the view to the summits, their starting points and the hikes,
// leaving out the home marker and the drive legs (which can span hundreds of miles) ──
function zoomToSummits() {
    if (!mapInstance) return;
    const pts = [];
    MAP_STOPS.forEach(function(s) {
        pts.push([s.summit_lat, s.summit_lng]);
        if (!s.chained && s.lat && s.lng) pts.push([s.lat, s.lng]);
        if (s.route) s.route.forEach(function(p) { pts.push(p); });
        if (s.gpx_path) s.gpx_path.forEach(function(p) { pts.push(p); });
    });
    if (pts.length) mapInstance.fitBounds(pts, { padding: [40, 40], maxZoom: 15 });
}

// ── "Show All Summits" map toggle — click a marker to add it to the route ──
function buildOtherSummitsLayer() {
    const layer = L.layerGroup();
    OTHER_SUMMITS.forEach(function(s) {
        const icon = L.divIcon({ className: '', html: '<div class="lmap-other">' + s.points + '</div>', iconSize: [18, 18], iconAnchor: [9, 9] });
        const marker = L.marker([s.lat, s.lng], { icon: icon });
        const popupHtml = '<div style="font-weight:600; font-size:0.85rem;">' + s.name + '</div>'
            + '<div style="font-family:monospace; font-size:0.72rem; color:#8C8A86; margin-bottom:8px;">' + s.ref + '</div>'
            + '<button class="lmap-add-btn" onclick="addSummitToRoute(' + s.id + ')">+ Add to Route</button>';
        marker.bindPopup(popupHtml);
        marker.addTo(layer);
    });
    return layer;
}

function toggleOtherSummits() {
    if (!mapInstance) return;
    otherSummitsVisible = !otherSummitsVisible;
    const btn = document.getElementById('btn-show-all-summits');
    if (otherSummitsVisible) {
        if (!otherSummitsLayer) otherSummitsLayer = buildOtherSummitsLayer();
        otherSummitsLayer.addTo(mapInstance);
        if (btn) { btn.classList.add('active'); btn.textContent = 'Hide All Summits'; }
    } else {
        if (otherSummitsLayer) mapInstance.removeLayer(otherSummitsLayer);
        if (btn) { btn.classList.remove('active'); btn.textContent = 'Show All Summits'; }
    }
}

const SHARE_URL = <?= json_encode($share_url) ?>;
function copyShareLink(btn) {
    const done = function() {
        btn.textContent = 'Link Copied!';
        setTimeout(() => { btn.textContent = 'Share Link'; }, 1800);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(SHARE_URL).then(done).catch(() => fallbackCopy(SHARE_URL, done));
    } else {
        fallbackCopy(SHARE_URL, done);
    }
}

function copyMapCoords(btn, text) {
    const done = function() {
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = 'Copy Coordinates'; }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
    } else {
        fallbackCopy(text, done);
    }
}

function fallbackCopy(text, done) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
    document.body.removeChild(ta);
}

// ── Alternate hike start: pick a spot on the map for the hike to stop #(i+1) ──
let altPinIndex = null;
function startAltPin(i) {
    const s = MAP_STOPS[i];
    if (!s || !mapInstance) return;
    altPinIndex = i;
    document.getElementById('multi-map').classList.add('picking');
    document.getElementById('alt-banner-text').textContent = 'Click the map where the hike to #' + s.order + ' ' + s.name + ' begins (for example, the previous summit).';
    document.getElementById('alt-banner').classList.add('on');
    document.getElementById('alt-banner').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function cancelAltPin() {
    altPinIndex = null;
    document.getElementById('multi-map').classList.remove('picking');
    document.getElementById('alt-banner').classList.remove('on');
    if (mapInstance) mapInstance.closePopup();
}
function altPost(params) {
    return fetch('multi_alt_start_save.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(params) })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { alert(d.error || 'Could not save that change.'); return; }
            window.location.reload();
        })
        .catch(() => alert('Could not save that change. Check your connection and try again.'));
}
function confirmAltPin(lat, lng) {
    const s = MAP_STOPS[altPinIndex];
    if (!s) return;
    altPost({ action: 'set', from_id: s.prev_id, to_id: s.id, lat: lat, lng: lng });
}
function acceptAltSuggestion(i, lat, lng) {
    const s = MAP_STOPS[i];
    if (!s) return;
    altPost({ action: 'set', from_id: s.prev_id, to_id: s.id, lat: lat, lng: lng });
}
function altAction(action, i) {
    const s = MAP_STOPS[i];
    if (!s) return;
    altPost({ action: action, from_id: s.prev_id, to_id: s.id });
}

// ── Leg settings: back to the car or hike direct to the next summit, and the finish address ──
const MULTI_ID = <?= (int)$multi_id ?>;
function legPost(params) {
    params.multi_id = MULTI_ID;
    return fetch('multi_leg_save.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(params) })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { alert(d.error || 'Could not save that change.'); return; }
            window.location.reload();
        })
        .catch(() => alert('Could not save that change. Check your connection and try again.'));
}
function setLegMode(i, mode) {
    const a = MAP_STOPS[i], b = MAP_STOPS[i + 1];
    if (!a || !b) return;
    if (mode === 'hike') {
        const sel = document.getElementById('legmode-' + i);
        if (sel) sel.disabled = true;
        document.body.style.cursor = 'progress';   // first time for a pair, the path finder may take a few seconds
    }
    legPost({ action: 'mode', from_id: a.id, to_id: b.id, mode: mode });
}
function editActTime(i) {
    const s = MAP_STOPS[i];
    const row = document.getElementById('act-' + i);
    if (!s || !row) return;
    const cur = parseInt(row.querySelector('.act-val').textContent, 10) || 30;
    row.innerHTML = '<span class="lbl">On the air:</span> '
        + '<input type="number" class="act-input" min="5" max="480" step="5" value="' + cur + '" aria-label="Minutes on the air"> min '
        + '<button type="button" class="act-btn primary">Save</button> '
        + '<button type="button" class="act-btn">Cancel</button>'
        + (row.querySelector('.act-custom') || row.dataset.custom ? ' <button type="button" class="act-link">Use route default</button>' : '');
    const inp = row.querySelector('input'), btns = row.querySelectorAll('button');
    inp.focus(); inp.select();
    const save = () => { const v = parseInt(inp.value, 10); if (v >= 5) { btns[0].disabled = true; setActTime(i, v); } };
    btns[0].onclick = save;
    btns[1].onclick = () => window.location.reload();
    if (btns[2]) btns[2].onclick = () => setActTime(i, 0);
    inp.onkeydown = e => { if (e.key === 'Enter') save(); else if (e.key === 'Escape') window.location.reload(); };
}
function setAfter(i, choice) {
    const a = MAP_STOPS[i], b = MAP_STOPS[i + 1];
    if (!a) return;
    const err = document.getElementById('after-err-' + i);
    if (err) err.hidden = true;
    if (choice === 'hike') document.body.style.cursor = 'progress';   // first time for a pair, the path finder may take a few seconds
    const params = { action: 'after', from_id: a.id, to_id: b ? b.id : 0, choice: choice, multi_id: MULTI_ID };
    fetch('multi_leg_save.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(params) })
        .then(r => r.json())
        .then(d => {
            document.body.style.cursor = '';
            if (d.ok) { window.location.reload(); return; }
            // Refused (for example no complete walkable path): keep the current choice and say why
            if (err) { err.textContent = d.error || 'Could not save that change.'; err.hidden = false; }
            else alert(d.error || 'Could not save that change.');
        })
        .catch(() => { document.body.style.cursor = ''; alert('Could not save that change. Check your connection and try again.'); });
}
function setExit(i, which) {
    const s = MAP_STOPS[i];
    if (!s) return;
    legPost({ action: 'exit', summit_id: s.id, exit: which });
}
function setActTime(i, minutes) {
    const s = MAP_STOPS[i];
    if (!s) return;
    legPost({ action: 'act_time', summit_id: s.id, minutes: minutes });
}
function editFinish() {
    document.getElementById('finish-stat').style.display = 'none';
    document.getElementById('finish-edit').hidden = false;
    document.getElementById('finish-select').focus();
}
function cancelFinish() {
    document.getElementById('finish-edit').hidden = true;
    document.getElementById('finish-stat').style.display = '';
}
function finishChosen(v) {
    if (v === 'new') { window.location.href = 'planning_groups.php?group=<?= (int)$current_group['id'] ?>'; return; }
    setFinish(v);
}
function setFinish(addressId) {
    legPost({ action: 'finish', address_id: addressId });
}

function addSummitToRoute(id) {
    if (CURRENT_IDS.length >= MULTI_MAX) {
        alert('Multi-activations are capped at ' + MULTI_MAX + ' summits.');
        return;
    }
    const ids = CURRENT_IDS.slice();
    ids.push(id);
    navigateToOrder(ids);
}
</script>

</body>
</html>
