<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();

$message = '';

// Sitewide banner
$_banner_raw    = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sitewide_banner'")->fetchColumn();
$_sitewide_banner = $_banner_raw ? json_decode($_banner_raw, true) : null;

// Restore defaults from cookies if session has no group set yet
if (!getCurrentPlanningGroup($db) && !empty($_COOKIE['sota_default_group'])) {
    $cookie_group = (int)$_COOKIE['sota_default_group'];
    $callsign = getCurrentCallsign();
    // Validate the cookie group belongs to this user before restoring
    $stmt = $db->prepare("
        SELECT pg.id FROM planning_groups pg
        LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
        WHERE pg.id = ? AND (pg.owner_callsign = ? OR pgm.callsign = ?)
        LIMIT 1
    ");
    $stmt->execute([$cookie_group, $callsign, $callsign]);
    if ($stmt->fetch()) {
        setCurrentPlanningGroup($cookie_group);
    }
}

// Handle planning group selection
if (isset($_POST['select_planning_group'])) {
    $group_id = (int)$_POST['planning_group_id'];
    setCurrentPlanningGroup($group_id);
    $message = "Dashboard switched!";
}

// Handle set default group
if (isset($_POST['set_default_group'])) {
    $group_id = (int)$_POST['default_group_id'];
    setcookie('sota_default_group', $group_id, time() + 60 * 60 * 24 * 365, '/');
    $_COOKIE['sota_default_group'] = $group_id;
    setCurrentPlanningGroup($group_id);
    $message = "⭐ Default dashboard saved — this dashboard will load automatically next time.";
}

// Handle address selection
if (isset($_POST['select_address'])) {
    $address_id = (int)$_POST['address_id'];
    $current_group = getCurrentPlanningGroup($db);
    if ($current_group) {
        $setting_key = 'selected_address_group_' . $current_group['id'];
        $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$setting_key, $address_id]);
        $message = "Address updated!";
    }
}

// Handle set default address
if (isset($_POST['set_default_address'])) {
    $address_id = (int)$_POST['default_address_id'];
    $current_group = getCurrentPlanningGroup($db);
    if ($current_group) {
        $setting_key = 'selected_address_group_' . $current_group['id'];
        $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$setting_key, $address_id]);
        setcookie('sota_default_address_' . $current_group['id'], $address_id, time() + 60 * 60 * 24 * 365, '/');
        $_COOKIE['sota_default_address_' . $current_group['id']] = $address_id;
        $message = "⭐ Default address saved — this address will load automatically next time.";
    }
}

// Handle drive time calculation
if (isset($_POST['calculate_drive_times'])) {
    $current_group = getCurrentPlanningGroup($db);
    
    if (!$current_group) {
        $message = "Please select a dashboard first";
    } else {
        $selected_address = getSelectedAddress($db);
        
        if (!$selected_address) {
            $message = "Please select an address first";
        } else if (GOOGLE_MAPS_API_KEY === 'YOUR_API_KEY_HERE') {
            $message = "Please add your Google Maps API key to config.php";
        } else {
            // Only calculate for CURRENT GROUP's summits
            $stmt = $db->prepare("SELECT id, latitude, longitude, trailhead_lat, trailhead_lng FROM summits WHERE planning_group_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL");
            $stmt->execute([$current_group['id']]);
            $summits = $stmt->fetchAll();
            
            if (empty($summits)) {
                $message = "No summits found for {$current_group['name']}. Nominate some summits first!";
            } else {
                $updated = 0;
                $failed = 0;
                
                foreach ($summits as $summit) {
                    // Use trailhead coordinates if available, otherwise use summit coordinates
                    $dest_lat = $summit['trailhead_lat'] ?? $summit['latitude'];
                    $dest_lng = $summit['trailhead_lng'] ?? $summit['longitude'];
                    
                    $drive_time = calculateDriveTime($selected_address['address'], $dest_lat, $dest_lng);
                    
                    if ($drive_time !== null) {
                        // Multiply by 2 for round trip
                        $drive_time_rt = $drive_time * 2;
                        $stmt = $db->prepare("UPDATE summits SET drive_time_min = ? WHERE id = ?");
                        $stmt->execute([$drive_time_rt, $summit['id']]);
                        $updated++;
                    } else {
                        $failed++;
                    }
                    
                    // Small delay to avoid rate limiting
                    usleep(100000); // 0.1 second
                }
                
                if ($updated > 0) {
                    $message = "Travel times updated for $updated summit(s) in {$current_group['name']}!";
                    if ($failed > 0) {
                        $message .= " ($failed failed - check API key)";
                    }
                } else {
                    $message = "Failed to calculate travel times. Check your Google Maps API key and quota.";
                }
            }
        }
    }
}

// Get selected address (will be null if no group selected)
$current_group = getCurrentPlanningGroup($db);
$user_units = getUserUnits($db);
$selected_address = null;
$all_addresses = [];

// If no planning group in session, try to auto-select one from DB
if (!$current_group) {
    $callsign = getCurrentCallsign();
    $stmt = $db->prepare("
        SELECT pg.id FROM planning_groups pg
        LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
        WHERE pg.owner_callsign = ? OR pgm.callsign = ?
        ORDER BY pg.id ASC LIMIT 1
    ");
    $stmt->execute([$callsign, $callsign]);
    $auto_group = $stmt->fetch();
    if ($auto_group) {
        setCurrentPlanningGroup($auto_group['id']);
        $current_group = getCurrentPlanningGroup($db);
    } else {
        // Genuinely no groups — send to setup
        header('Location: planning_groups.php');
        exit;
    }
}

if ($multi_edit_id) {
    $stmt = $db->prepare("SELECT name FROM multi_activations WHERE id = ? AND planning_group_id = ?");
    $stmt->execute([$multi_edit_id, $current_group['id']]);
    $multi_edit_name = $stmt->fetchColumn() ?: null;
}

// Sync activation status from the SOTA API in the background, once per session/day per dashboard
$activations_sync_flag_key = 'activations_synced_group_' . $current_group['id'];
$should_sync_activations = ($_SESSION[$activations_sync_flag_key] ?? null) !== gmdate('Y-m-d');
if ($should_sync_activations) {
    $_SESSION[$activations_sync_flag_key] = gmdate('Y-m-d');
}

// Restore default address from cookie if nothing is selected yet
$cookie_addr_key = 'sota_default_address_' . $current_group['id'];
if (!getSelectedAddress($db) && !empty($_COOKIE[$cookie_addr_key])) {
    $cookie_addr = (int)$_COOKIE[$cookie_addr_key];
    $stmt = $db->prepare("SELECT id FROM addresses WHERE id = ? AND planning_group_id = ?");
    $stmt->execute([$cookie_addr, $current_group['id']]);
    if ($stmt->fetch()) {
        $setting_key = 'selected_address_group_' . $current_group['id'];
        $stmt2 = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt2->execute([$setting_key, $cookie_addr]);
    }
}

// Get selected address for current group
$selected_address = getSelectedAddress($db);

// Get all planning groups for dropdown
$all_groups = getAllPlanningGroups($db);

// Get all addresses for current planning group
$stmt = $db->prepare("SELECT * FROM addresses WHERE planning_group_id = ? ORDER BY label, address");
$stmt->execute([$current_group['id']]);
$all_addresses = $stmt->fetchAll();

// Auto-select first address if none selected (but addresses exist)
if (count($all_addresses) > 0 && !$selected_address) {
    $setting_key = 'selected_address_group_' . $current_group['id'];
    $stmt = $db->prepare("
        INSERT INTO app_settings (setting_key, setting_value) 
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$setting_key, $all_addresses[0]['id']]);
    $selected_address = $all_addresses[0];
}

// Get activation time: URL param overrides, else user's saved preference, else 60
$_us_stmt = $db->prepare("SELECT default_activation_time_min FROM user_settings WHERE user_callsign = ?");
$_us_stmt->execute([$_SESSION['sota_callsign'] ?? '']);
$_saved_activation = (int)(($_us_stmt->fetchColumn()) ?: 60);
$activation_time = isset($_GET['activation_time']) ? (int)$_GET['activation_time'] : $_saved_activation;

// Get sort parameters
$sort_by = $_GET['sort'] ?? 'nominated_date';
$sort_order = $_GET['order'] ?? 'DESC';

// Get filter parameter (comma-separated for multiple filters)
$filter_param = $_GET['filter'] ?? 'all';
$unique_only = isset($_GET['unique']) && $_GET['unique'] === '1';
$min_pts = isset($_GET['min_pts']) ? max(0, (int)$_GET['min_pts']) : 0;

// Arriving from "+ Add Summit" on the multi-activation page: pre-seed select mode
// with the route's existing members, ready for the user to check more to add.
$multi_prefill_ids = [];
$multi_edit_id = null;
$multi_edit_name = null;
if (isset($_GET['multi_select'])) {
    $multi_prefill_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_GET['multi_select'])))));
}
if (isset($_GET['multi_edit_id'])) {
    $multi_edit_id = (int)$_GET['multi_edit_id'];
}

// Parse into array - 'all' means everything, 'none' means nothing
if ($filter_param === 'all' || empty($filter_param)) {
    $active_filters = ['drive-up', 'easy', 'moderate', 'hard', 'ready', 'needs-research', 'activated-this-year'];
} elseif ($filter_param === 'none') {
    $active_filters = [];
} else {
    $active_filters = explode(',', $filter_param);
}

// Validate sort parameters
$allowed_sorts = ['name', 'sota_ref', 'points', 'elevation_ft', 'hike_distance_mi', 'hike_elevation_gain_ft', 'difficulty', 'status', 'nominated_date', 'last_activated_date', 'hike_time', 'drive_time', 'total_time'];
if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'nominated_date';
}
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

// Map sort keys that aren't direct columns to SQL expressions.
// Mirrors the PHP display logic exactly: GPS recorded time > GPS Naismith > Manual Naismith.
//
// Effective distance (miles): GPS total_distance when enabled, else manual. Doubled for one-way tracks.
$eff_dist = "CASE WHEN g.use_for_hike_time = 1 AND COALESCE(g.total_distance, 0) > 0
    THEN g.total_distance * 0.621371 * CASE WHEN g.track_type IN ('ascent','descent') THEN 2 ELSE 1 END
    ELSE COALESCE(s.hike_distance_mi, 0) END";

// Effective elevation gain (feet): GPS when enabled (elevation_loss for descent), else manual.
$eff_gain = "CASE WHEN g.use_for_elevation = 1 AND COALESCE(g.elevation_gain, 0) > 0
    THEN CASE WHEN g.track_type = 'descent'
              THEN COALESCE(g.elevation_loss, 0) * 3.28084
              ELSE g.elevation_gain * 3.28084 END
    ELSE COALESCE(s.hike_elevation_gain_ft, 0) END";

// Naismith using whichever source is active
$naismith_sql = "(($eff_dist) / 2.5 * 60 + ($eff_gain) / 1500 * 60) * 1.10";

// GPS recorded time (seconds → minutes), doubled for one-way tracks
$gps_time_sql = "ROUND(g.hiking_time * CASE WHEN g.track_type IN ('ascent','descent') THEN 2 ELSE 1 END / 60)";

// Full hike time: GPS recorded if timestamps exist, else Naismith from active source
$hike_time_sql = "CASE WHEN g.use_for_hike_time = 1 AND COALESCE(g.hiking_time, 0) > 0
    THEN $gps_time_sql
    ELSE $naismith_sql END";

$sort_expr = match($sort_by) {
    'hike_time'  => "($hike_time_sql)",
    'total_time' => "($hike_time_sql) + COALESCE(s.drive_time_min, 0)",
    'drive_time' => 's.drive_time_min',
    default      => "s.$sort_by"
};

// Get summits for CURRENT group ONLY (not all groups!)
// Also get GPX data if available for this group
// Build WHERE clause - handle difficulty and status as separate categories
$where_clause = "s.planning_group_id = ?";

// Separate filters by category
$difficulty_options = ['drive-up', 'easy', 'moderate', 'hard'];
$status_options = ['ready', 'needs-research', 'activated-this-year'];

$active_difficulties = array_intersect($active_filters, $difficulty_options);
$active_statuses = array_intersect($active_filters, $status_options);

$filter_conditions = [];

// Handle difficulty filters
if (!empty($active_difficulties)) {
    // If ANY difficulty filter is active, exclude inactive difficulties
    $inactive_difficulties = array_diff($difficulty_options, $active_difficulties);
    if (!empty($inactive_difficulties)) {
        $quoted = array_map(function($d) use ($db) { return $db->quote($d); }, $inactive_difficulties);
        $filter_conditions[] = "s.difficulty NOT IN (" . implode(',', $quoted) . ")";
    }
}
// If NO difficulty filters active, accept all difficulties (no filter)

// Handle status filters
if (!empty($active_statuses)) {
    // Build conditions for active statuses (at least one must match)
    $status_conditions = [];
    
    if (in_array('ready', $active_statuses)) {
        // "Ready" includes: ready status + any previously-activated summit (re-eligible in a new year)
        $status_conditions[] = "(s.status = 'ready' OR (s.status = 'activated' AND (s.last_activated_date IS NULL OR YEAR(CONVERT_TZ(s.last_activated_date, '+00:00', '+00:00')) < YEAR(UTC_TIMESTAMP()))))";
    }
    if (in_array('needs-research', $active_statuses)) {
        $status_conditions[] = "s.status IN ('nominated', 'researched')";
    }
    if (in_array('activated-this-year', $active_statuses)) {
        $status_conditions[] = "(s.status = 'activated' AND YEAR(CONVERT_TZ(s.last_activated_date, '+00:00', '+00:00')) = YEAR(UTC_TIMESTAMP()))";
    }
    
    if (!empty($status_conditions)) {
        $filter_conditions[] = "(" . implode(' OR ', $status_conditions) . ")";
    }
}
// If NO status filters active, accept all statuses (no filter)

// Apply all filters
if (!empty($filter_conditions)) {
    $where_clause .= " AND " . implode(' AND ', $filter_conditions);
}

// Unique summits: nobody in this group has ever activated them
if ($unique_only) {
    $where_clause .= " AND s.activated_by IS NULL";
}

// Points filter
if ($min_pts > 0) {
    $where_clause .= " AND s.points >= " . $min_pts;
}

$stmt = $db->prepare("
    SELECT s.*,
           g.hiking_time as gpx_hiking_time,
           g.elevation_gain as gpx_elevation_gain,
           g.elevation_loss as gpx_elevation_loss,
           g.total_distance as gpx_total_distance,
           g.file_path as gpx_file_path,
           g.track_type,
           g.use_for_hike_time,
           g.use_for_elevation,
           s.drive_time_min as drive_time,
           ay.this_year_callsigns
    FROM summits s
    LEFT JOIN gpx_tracks g ON s.id = g.summit_id AND g.planning_group_id = ?
    LEFT JOIN (
        SELECT summit_id,
               GROUP_CONCAT(DISTINCT callsigns ORDER BY activation_date DESC SEPARATOR ', ') AS this_year_callsigns
        FROM activations
        WHERE planning_group_id = ?
          AND YEAR(CONVERT_TZ(activation_date, '+00:00', '+00:00')) = YEAR(UTC_TIMESTAMP())
        GROUP BY summit_id
    ) ay ON ay.summit_id = s.id
    WHERE $where_clause
    ORDER BY
        CASE
             WHEN s.status = 'activated' AND YEAR(CONVERT_TZ(s.last_activated_date, '+00:00', '+00:00')) = YEAR(UTC_TIMESTAMP()) THEN 4
             WHEN s.status = 'ready' THEN 1
             WHEN s.status = 'activated' THEN 1
             WHEN s.status = 'researched' THEN 2
             ELSE 3 END,
        $sort_expr $sort_order
");
$stmt->execute([$current_group['id'], $current_group['id'], $current_group['id']]);
$summits = $stmt->fetchAll();

// Saved multi-activations for this group, keyed by the first (lead) summit's id.
// A saved route replaces its member summits' individual dashboard rows with one
// summary row (aggregate stats, precomputed on save), with the members nested
// underneath — each still a normal link through to its own summit_detail.php.
$multi_lookup = [];
$stmt = $db->prepare("
    SELECT ma.id AS multi_id, ma.name AS multi_name, ma.is_expanded,
           ma.total_points, ma.total_hike_min, ma.total_drive_min, ma.total_time_min,
           ma.total_dist_mi, ma.total_elev_ft,
           mas.summit_id, mas.sort_order, s.name AS summit_name, s.sota_ref, s.points
    FROM multi_activations ma
    JOIN multi_activation_summits mas ON mas.multi_activation_id = ma.id
    JOIN summits s ON s.id = mas.summit_id
    WHERE ma.planning_group_id = ?
    ORDER BY ma.id, mas.sort_order
");
$stmt->execute([$current_group['id']]);
$multi_groups = [];
foreach ($stmt->fetchAll() as $r) {
    $multi_groups[$r['multi_id']]['name'] ??= $r['multi_name'];
    $multi_groups[$r['multi_id']]['is_expanded'] ??= ($r['is_expanded'] === null || (int)$r['is_expanded'] === 1);
    $multi_groups[$r['multi_id']]['totals'] ??= [
        'points'    => $r['total_points'],
        'hike_min'  => $r['total_hike_min'],
        'drive_min' => $r['total_drive_min'],
        'time_min'  => $r['total_time_min'],
        'dist_mi'   => $r['total_dist_mi'],
        'elev_ft'   => $r['total_elev_ft'],
    ];
    $multi_groups[$r['multi_id']]['members'][] = $r;
}
foreach ($multi_groups as $mid => $m) {
    if (empty($m['members'])) continue;
    $lead = $m['members'][0];
    $multi_lookup[$lead['summit_id']] = [
        'multi_id'    => $mid,
        'name'        => $m['name'],
        'is_expanded' => $m['is_expanded'],
        'totals'      => $m['totals'],
        'members'     => $m['members'], // includes the lead itself at index 0
    ];
}

$summits_by_id = [];
foreach ($summits as $s) $summits_by_id[$s['id']] = $s;

// Every member of a saved multi (lead included) is hidden from the flat list —
// it renders nested under the multi's summary row instead. Only do this for
// multis whose lead actually passes the active filters (otherwise the summary
// row won't render this pass, and a member would vanish with no row at all).
$multi_member_ids = [];
foreach ($multi_lookup as $lead_id => $ml) {
    if (!isset($summits_by_id[$lead_id])) continue;
    foreach ($ml['members'] as $mem) $multi_member_ids[$mem['summit_id']] = true;
}

// A member might not itself pass the active filters — make sure its full row
// data is still available so it can render inside its multi's nested list.
$missing_member_ids = [];
foreach (array_keys($multi_member_ids) as $mid) {
    if (!isset($summits_by_id[$mid])) $missing_member_ids[] = $mid;
}
if (!empty($missing_member_ids)) {
    $ph = implode(',', array_fill(0, count($missing_member_ids), '?'));
    $stmt = $db->prepare("
        SELECT s.*,
               g.hiking_time as gpx_hiking_time,
               g.elevation_gain as gpx_elevation_gain,
               g.elevation_loss as gpx_elevation_loss,
               g.total_distance as gpx_total_distance,
               g.file_path as gpx_file_path,
               g.track_type,
               g.use_for_hike_time,
               g.use_for_elevation,
               s.drive_time_min as drive_time,
               ay.this_year_callsigns
        FROM summits s
        LEFT JOIN gpx_tracks g ON s.id = g.summit_id AND g.planning_group_id = ?
        LEFT JOIN (
            SELECT summit_id,
                   GROUP_CONCAT(DISTINCT callsigns ORDER BY activation_date DESC SEPARATOR ', ') AS this_year_callsigns
            FROM activations
            WHERE planning_group_id = ?
              AND YEAR(CONVERT_TZ(activation_date, '+00:00', '+00:00')) = YEAR(UTC_TIMESTAMP())
            GROUP BY summit_id
        ) ay ON ay.summit_id = s.id
        WHERE s.id IN ($ph) AND s.planning_group_id = ?
    ");
    $stmt->execute(array_merge([$current_group['id'], $current_group['id']], $missing_member_ids, [$current_group['id']]));
    foreach ($stmt->fetchAll() as $s) $summits_by_id[$s['id']] = $s;
}

// Renders one <tr> (+ its mobile stats cell) for a summit row — shared by both
// normal top-level rows and the rows nested under a multi-activation summary.
function render_dashboard_row($summit, $current_group, $user_units, $activation_time, $nested = false, $route_order = null, $multi_group_id = null, $multi_expanded = true) {
    $track_type    = $summit['track_type'] ?? 'round-trip';
    $one_way       = ($track_type === 'ascent' || $track_type === 'descent');
    $has_timestamps = ($summit['gpx_hiking_time'] ?? 0) > 0;

    if ($summit['use_for_elevation'] && $summit['gpx_elevation_gain']) {
        $elevation_for_display = ($track_type === 'descent')
            ? ($summit['gpx_elevation_loss'] ?? 0) * 3.28084
            : $summit['gpx_elevation_gain'] * 3.28084;
    } else {
        $elevation_for_display = $summit['hike_elevation_gain_ft'];
    }

    if ($summit['use_for_hike_time'] && ($summit['gpx_total_distance'] ?? 0) > 0) {
        $dist_km = $summit['gpx_total_distance'];
        if ($one_way) $dist_km *= 2;
        $distance_display_mi = round($dist_km * 0.621371, 2);
    } else {
        $distance_display_mi = $summit['hike_distance_mi'];
    }

    if ($summit['use_for_hike_time'] && $has_timestamps) {
        $secs = $summit['gpx_hiking_time'];
        if ($one_way) $secs *= 2;
        $hike_time_total = round($secs / 60);
    } else {
        $hike_time_total = ($distance_display_mi || $elevation_for_display)
            ? calculateHikeTime($distance_display_mi ?? 0, $elevation_for_display ?? 0, $current_group['pace_multiplier'] ?? 1.0)
            : 0;
    }

    $drive_time = $summit['drive_time'] ?? 0;
    $total_time = $hike_time_total + $drive_time + $activation_time;

    $activated_this_year = false;
    if ($summit['last_activated_date']) {
        $last_activated_year = date('Y', strtotime($summit['last_activated_date']));
        $activated_this_year = ($last_activated_year == gmdate('Y'));
    }

    if ($activated_this_year) {
        $row_class = 'row-activated';
    } elseif ($summit['status'] === 'ready' || ($summit['status'] === 'activated' && !$activated_this_year)) {
        $row_class = 'row-ready';
    } else {
        $row_class = '';
    }
    if ($nested) $row_class .= ' multi-nested-row' . ($multi_expanded ? ' is-open' : '') . ' nested-summit-row';

    $mobile_parts = array_filter([
        $hike_time_total ? 'Hike ' . formatTime($hike_time_total) : null,
        $drive_time      ? 'Travel ' . formatTime($drive_time) : null,
        $distance_display_mi ? convertDistance($distance_display_mi, $user_units) . ' ' . getDistanceUnit($user_units) : null,
        $elevation_for_display ? number_format(convertElevation($elevation_for_display, $user_units)) . ' ' . getElevationUnit($user_units) . ' gain' : null,
    ]);

    $onclick = $nested
        ? "window.location='summit_detail.php?id={$summit['id']}&group={$current_group['id']}'"
        : "handleRowClick(event, {$summit['id']}, {$current_group['id']})";
    $row_attrs = $nested ? ' data-multi-group="' . (int)$multi_group_id . '"' : '';
    ?>
    <tr class="<?= trim($row_class) ?>" data-summit-id="<?= $summit['id'] ?>" onclick="<?= $onclick ?>"<?= $row_attrs ?>>
        <td class="td-select" onclick="event.stopPropagation()">
            <input type="checkbox" class="row-select" data-id="<?= $summit['id'] ?>" onchange="toggleRowSelect(<?= $summit['id'] ?>, this.checked)">
        </td>
        <td class="td-main" onclick="event.stopPropagation()">
            <a href="summit_detail.php?id=<?= $summit['id'] ?>&group=<?= $current_group['id'] ?>" style="text-decoration:none">
                <?php if ($route_order): ?><span class="route-order-chip"><?= $route_order ?></span><?php endif; ?>
                <div class="summit-name"><?= htmlspecialchars($summit['name']) ?></div>
                <div class="summit-ref"><?= htmlspecialchars($summit['sota_ref']) ?></div>
            </a>
        </td>
        <td class="td-hide-mobile">
            <span class="points-dot"><?= $summit['points'] ?></span>
            <?php if (!empty($summit['bonus_points']) && isWinterBonusSeasonActive($summit['latitude'])): ?>
                <span class="bonus-badge" data-tip="<?= htmlspecialchars(winterBonusSeasonText($summit['bonus_points'], $summit['latitude'])) ?>">+<?= $summit['bonus_points'] ?></span>
            <?php endif; ?>
        </td>
        <td class="td-diff">
            <?php if ($summit['difficulty']): ?>
                <span class="badge badge-<?= htmlspecialchars($summit['difficulty']) ?>"><?= ucwords(str_replace('-', ' ', $summit['difficulty'])) ?></span>
            <?php else: ?>
                <span style="color:var(--ink-4)">—</span>
            <?php endif; ?>
        </td>
        <td class="td-hide-mobile">
            <?php
            $elevation = convertElevation($summit['elevation_ft'], $user_units);
            $unit = getElevationUnit($user_units);
            ?>
            <span class="stat-val"><?= number_format($elevation) ?></span> <span class="stat-unit"><?= $unit ?></span>
        </td>
        <td class="td-hide-mobile">
            <?php if ($distance_display_mi): ?>
                <span class="stat-val"><?= convertDistance($distance_display_mi, $user_units) ?></span>
                <span class="stat-unit"><?= getDistanceUnit($user_units) ?></span>
            <?php else: ?>
                <span style="color:var(--ink-4)">—</span>
            <?php endif; ?>
        </td>
        <td class="td-hide-mobile">
            <?php if ($elevation_for_display): ?>
                <span class="stat-val"><?= number_format(convertElevation($elevation_for_display, $user_units)) ?></span>
                <span class="stat-unit"><?= getElevationUnit($user_units) ?></span>
            <?php else: ?>
                <span style="color:var(--ink-4)">—</span>
            <?php endif; ?>
        </td>
        <td class="td-hide-mobile text-right">
            <span class="stat-val"><?= $hike_time_total ? formatTime($hike_time_total) : '—' ?></span>
        </td>
        <td class="td-hide-mobile text-right">
            <span class="stat-val"><?= $drive_time ? formatTime($drive_time) : '—' ?></span>
        </td>
        <td class="td-time text-right">
            <span class="total-time"><?= formatTime($total_time) ?></span>
        </td>
        <td class="td-hide-mobile td-last-activated">
            <?php if ($summit['last_activated_date']): ?>
                <div class="stat-val"><?= date('M j, Y', strtotime($summit['last_activated_date'])) ?></div>
                <?php if (!empty($summit['this_year_callsigns'])): ?>
                    <div class="last-act-callsign"><?= htmlspecialchars($summit['this_year_callsigns']) ?></div>
                <?php elseif (!empty($summit['activated_by'])): ?>
                    <div class="last-act-callsign"><?= htmlspecialchars($summit['activated_by']) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:var(--ink-4)">—</span>
            <?php endif; ?>
        </td>
        <td class="td-status">
            <?php if ($summit['status'] === 'activated'): ?>
                <?php if ($activated_this_year): ?>
                    <span class="badge badge-activated">Activated <?= date('Y', strtotime($summit['last_activated_date'])) ?></span>
                <?php else: ?>
                    <span class="badge badge-ready">Ready</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge badge-<?= htmlspecialchars($summit['status']) ?>"><?= ucfirst($summit['status']) ?></span>
            <?php endif; ?>
        </td>
        <td class="td-stats"><?= implode(' · ', $mobile_parts) ?></td>
    </tr>
    <?php
}

// Build map data
$map_summits = [];
foreach ($summits as $sm) {
    if (!$sm['latitude'] || !$sm['longitude']) continue;
    $tt = $sm['track_type'] ?? 'round-trip';
    $ow = ($tt === 'ascent' || $tt === 'descent');
    $has_ts = ($sm['gpx_hiking_time'] ?? 0) > 0;
    if ($sm['use_for_elevation'] && $sm['gpx_elevation_gain']) {
        $elev = ($tt === 'descent') ? ($sm['gpx_elevation_loss'] ?? 0) * 3.28084 : $sm['gpx_elevation_gain'] * 3.28084;
    } else {
        $elev = $sm['hike_elevation_gain_ft'];
    }
    if ($sm['use_for_hike_time'] && ($sm['gpx_total_distance'] ?? 0) > 0) {
        $dist = $sm['gpx_total_distance'] * ($ow ? 2 : 1) * 0.621371;
    } else {
        $dist = $sm['hike_distance_mi'];
    }
    if ($sm['use_for_hike_time'] && $has_ts) {
        $hike_min = round($sm['gpx_hiking_time'] * ($ow ? 2 : 1) / 60);
    } else {
        $hike_min = ($dist || $elev) ? calculateHikeTime($dist ?? 0, $elev ?? 0, $current_group['pace_multiplier'] ?? 1.0) : 0;
    }
    $drv = $sm['drive_time_min'] ?? 0;
    $tot = $hike_min + $drv + $activation_time;
    $is_drive_up = ($sm['difficulty'] ?? '') === 'drive-up';
    // Drive-up summits need no hike research — travel + activation time alone is enough to plan.
    $has_data = ($dist > 0 || $elev > 0 || $is_drive_up);
    $act_yr = $sm['last_activated_date'] && (date('Y', strtotime($sm['last_activated_date'])) == gmdate('Y'));
    if ($act_yr) $badge = 'gray';
    elseif ($sm['status'] === 'ready' || ($sm['status'] === 'activated' && !$act_yr)) $badge = 'green';
    elseif ($sm['status'] === 'researched') $badge = 'orange';
    else $badge = 'blue';
    $map_summits[] = [
        'id'       => (int)$sm['id'],
        'name'     => $sm['name'],
        'ref'      => $sm['sota_ref'],
        'lat'      => (float)$sm['latitude'],
        'lng'      => (float)$sm['longitude'],
        'label'    => $has_data ? formatTime($tot) : null,
        'drive'    => $drv > 0 ? formatTime($drv) : null,
        'hike'     => $hike_min > 0 ? formatTime($hike_min) : null,
        'points'   => (int)($sm['points'] ?? 0),
        'badge'    => $badge,
        'url'      => "summit_detail.php?id={$sm['id']}&group={$current_group['id']}",
        'path'     => extractGpxPath($sm['gpx_file_path'] ?? null),
    ];
}
$map_json = json_encode($map_summits, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOTAplanner</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
    /* === Alpine Precision Design System === */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }

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
      --green-dark:        oklch(34% 0.10 155);
      --green-dark-2:      oklch(26% 0.09 155);
      --green-dark-bg:     oklch(88% 0.07 155);
      --green-dark-border: oklch(70% 0.10 155);
      --orange:        oklch(62% 0.14 58);
      --orange-bg:     oklch(96% 0.05 58);
      --red:           oklch(52% 0.16 22);
      --red-bg:        oklch(96% 0.04 22);
      --blue:          oklch(52% 0.12 240);
      --blue-bg:       oklch(95% 0.04 240);
      --gray-badge:    oklch(55% 0.02 200);
      --gray-bg:       oklch(93% 0.01 200);
      --surface:       #FFFFFF;
      --border:        #E5E2DA;
      --border-2:      #D4D0C8;
      --font-sans:     'DM Sans', system-ui, sans-serif;
      --font-mono:     'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
      --sp-1: 0.25rem; --sp-2: 0.5rem; --sp-3: 0.75rem; --sp-4: 1rem;
      --sp-5: 1.25rem; --sp-6: 1.5rem; --sp-8: 2rem; --sp-10: 2.5rem;
      --sp-12: 3rem; --sp-16: 4rem;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
      --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
      --shadow-lg: 0 8px 24px rgba(28,27,25,0.10), 0 4px 8px rgba(28,27,25,0.06);
    }

    body {
      font-family: var(--font-sans);
      background: var(--bg);
      color: var(--ink);
      line-height: 1.5;
      min-height: 100vh;
    }

    h1, h2, h3, h4, h5 { font-family: var(--font-sans); font-weight: 600; line-height: 1.2; }
    p { line-height: 1.65; color: var(--ink-2); }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* ── Topbar ── */
    .topbar {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      height: 56px;
      display: flex;
      align-items: center;
      padding: 0 var(--sp-8);
      gap: var(--sp-4);
      position: sticky;
      top: 0;
      z-index: 1001;
    }
    .topbar-logo {
      display: flex;
      align-items: center;
      gap: var(--sp-3);
      text-decoration: none;
      color: var(--ink);
      font-weight: 600;
      font-size: 0.95rem;
      letter-spacing: -0.01em;
      flex-shrink: 0;
    }
    .topbar-logo:hover { text-decoration: none; color: var(--ink); }
    .topbar-logo .logo-mark {
      width: 32px; height: 32px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
    .topbar-context {
      display: flex; align-items: center; gap: 8px;
      font-size: 0.82rem; color: var(--ink-2);
      flex-wrap: nowrap;
    }
    .ctx-label { color: var(--ink-3); font-size: 0.75rem; white-space: nowrap; }
    .ctx-sep { color: var(--border-2); margin: 0 2px; }
    .ctx-group { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .topbar-addr { display: flex; align-items: center; gap: 8px; }
    .topbar-right {
      display: flex; align-items: center; gap: var(--sp-3);
      margin-left: auto; flex-shrink: 0;
    }
    .topbar-nav {
      display: flex; align-items: center; gap: var(--sp-1);
    }
    .topbar-nav a {
      color: var(--ink-3);
      font-size: 0.875rem; font-weight: 500;
      padding: var(--sp-2) var(--sp-3);
      border-radius: var(--r-sm);
      transition: color 0.15s, background 0.15s;
      text-decoration: none; white-space: nowrap;
    }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }

    /* ── Page ── */
    .page { padding: var(--sp-8); max-width: 1400px; margin: 0 auto; }

    /* ── Buttons ── */
    .btn {
      display: inline-flex; align-items: center; justify-content: center;
      gap: var(--sp-2); padding: 0 var(--sp-4); height: 36px;
      border-radius: var(--r-md); font-family: var(--font-sans);
      font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none;
      transition: background 0.15s, box-shadow 0.15s, transform 0.1s;
      text-decoration: none; white-space: nowrap; line-height: 1;
    }
    .btn:hover { text-decoration: none; }
    .btn:active { transform: scale(0.98); }
    .btn-primary { background: var(--ink); color: #fff; }
    .btn-primary:hover { background: var(--ink-2); color: #fff; }
    .btn-ghost {
      background: transparent; color: var(--ink-2);
      border: 1px solid var(--border);
    }
    .btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
    .btn-sm { height: 30px; padding: 0 var(--sp-3); font-size: 0.8rem; }

    /* ── User chip / logout dropdown ── */
    .user-chip {
      position: relative;
      display: flex; align-items: center; gap: 0.35rem;
      cursor: pointer;
      padding: 0.25rem 0.6rem;
      border-radius: var(--r-sm);
      font-size: 0.8rem; font-weight: 600; color: var(--ink-2);
      border: 1px solid var(--border);
      background: var(--bg);
      user-select: none;
      white-space: nowrap;
    }
    .user-chip:hover { background: var(--bg-2); }
    .user-chip-chevron { transition: transform 0.15s; flex-shrink: 0; }
    .user-chip.open .user-chip-chevron { transform: rotate(180deg); }
    .user-dropdown {
      display: none;
      position: absolute; top: calc(100% + 6px); right: 0;
      background: #fff; border: 1px solid var(--border);
      border-radius: var(--r-sm); box-shadow: 0 4px 16px rgba(0,0,0,0.1);
      min-width: 130px; overflow: hidden; z-index: 200;
    }
    .user-chip.open .user-dropdown { display: block; }
    .user-dropdown a {
      display: block; padding: 0.6rem 1rem;
      font-size: 0.82rem; font-weight: 500; color: var(--ink-2);
      text-decoration: none;
    }
    .user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

    /* ── Hamburger menu (mobile nav — topbar-nav links are hidden below 768px) ── */
    .hamburger-menu { display: none; position: relative; }
    .hamburger-btn {
      display: flex; align-items: center; justify-content: center;
      width: 34px; height: 34px; border-radius: var(--r-sm);
      border: 1px solid var(--border); background: var(--bg);
      color: var(--ink-2); cursor: pointer; flex-shrink: 0;
    }
    .hamburger-btn:hover { background: var(--bg-2); }
    .hamburger-dropdown {
      display: none;
      position: absolute; top: calc(100% + 6px); right: 0;
      background: #fff; border: 1px solid var(--border);
      border-radius: var(--r-sm); box-shadow: 0 4px 16px rgba(0,0,0,0.1);
      min-width: 190px; overflow: hidden; z-index: 200;
    }
    .hamburger-menu.open .hamburger-dropdown { display: block; }
    .hamburger-dropdown a {
      display: block; padding: 0.65rem 1rem;
      font-size: 0.85rem; font-weight: 500; color: var(--ink-2);
      text-decoration: none;
    }
    .hamburger-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

    /* ── Select inline ── */
    .select-inline {
      appearance: none; -webkit-appearance: none;
      background: var(--bg-2); border: 1px solid var(--border);
      border-radius: var(--r-sm); padding: 0.3rem 2rem 0.3rem 0.65rem;
      font-family: var(--font-sans); font-size: 0.8rem; font-weight: 500;
      color: var(--ink); cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238C8A86' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 8px center;
      outline: none; transition: border-color 0.15s; max-width: 200px;
    }
    .select-inline:focus { border-color: var(--accent); }

    /* ── Star button ── */
    .star-btn {
      background: none; border: none; cursor: pointer; padding: 2px;
      color: var(--ink-4); line-height: 1; font-size: 0.9rem;
      transition: color 0.15s;
    }
    .star-btn.active { color: oklch(72% 0.17 75); }
    .star-btn:hover { color: oklch(65% 0.17 75); }

    /* ── Messages ── */
    .msg {
      display: flex; align-items: center; justify-content: space-between;
      gap: var(--sp-4); padding: var(--sp-3) var(--sp-4);
      border-radius: var(--r-md); font-size: 0.875rem; font-weight: 500;
      margin-bottom: var(--sp-4);
    }
    .msg-info    { background: var(--accent-bg); color: var(--accent);   border: 1px solid var(--accent-border); }
    .msg-success { background: var(--green-bg);  color: var(--green);    border: 1px solid oklch(85% 0.07 155); }
    .msg-dismiss {
      background: none; border: none; cursor: pointer; color: inherit;
      opacity: 0.5; font-size: 1.1rem; padding: 0; line-height: 1; flex-shrink: 0;
    }
    .msg-dismiss:hover { opacity: 1; }

    /* ── New-summit row highlight ── */
    @keyframes rowHighlight {
      0%   { background: oklch(88% 0.10 58); }
      60%  { background: oklch(88% 0.10 58); }
      100% { background: transparent; }
    }
    tr.row-new-highlight { animation: rowHighlight 2.5s ease-out forwards; }

    /* ── Toolbar ── */
    .toolbar {
      display: flex; flex-direction: column; gap: 0;
      padding: var(--sp-3) var(--sp-4); background: var(--surface);
      border: 1px solid var(--border); border-radius: var(--r-lg);
      margin-bottom: var(--sp-3);
    }
    .toolbar-row {
      display: flex; align-items: center; gap: var(--sp-3); flex-wrap: wrap;
    }
    .toolbar-row + .toolbar-row {
      margin-top: var(--sp-2); padding-top: var(--sp-2);
      border-top: 1px solid var(--border);
    }
    .toolbar-label {
      font-size: 0.75rem; font-weight: 600; color: var(--ink-3);
      text-transform: uppercase; letter-spacing: 0.07em; white-space: nowrap;
    }
    .toolbar-sep { width: 1px; height: 16px; background: var(--border-2); flex-shrink: 0; }
    .toolbar-right { margin-left: auto; display: flex; align-items: center; gap: var(--sp-2); }
    /* While bulk-selecting, the action cluster detaches and floats at the bottom
       of the viewport so it's always reachable while scrolling through rows to
       check off — the armed button also grows a bit once there's something to act on. */
    .toolbar-right.floating {
      position: fixed; left: 50%; bottom: var(--sp-6); transform: translateX(-50%);
      margin-left: 0; background: var(--surface); border: 1px solid var(--border-2);
      border-radius: 100px; padding: var(--sp-2) var(--sp-3);
      box-shadow: var(--shadow-lg); z-index: 500;
    }
    .toolbar-right.floating .multi-btn.armed,
    .toolbar-right.floating .trash-btn.armed {
      box-shadow: var(--shadow-md);
    }
    @media (max-width: 768px) { .toolbar-right.floating { display: none !important; } }

    /* ── Filter pills ── */
    .filter-row {
      display: flex; align-items: center; gap: var(--sp-2); flex-wrap: wrap;
    }
    .filter-pill {
      display: inline-flex; align-items: center; height: 28px;
      padding: 0 var(--sp-3); border-radius: 100px; font-size: 0.775rem;
      font-weight: 500; background: var(--surface); border: 1px solid var(--border-2);
      color: var(--ink-2); cursor: pointer; transition: all 0.12s;
      font-family: var(--font-sans); white-space: nowrap;
    }
    .filter-pill:hover { border-color: var(--accent-border); color: var(--ink); background: var(--accent-bg); }
    .filter-pill.active { background: var(--ink); border-color: var(--ink); color: #fff; }
    .filter-sep { width: 1px; height: 16px; background: var(--border-2); flex-shrink: 0; }

    /* ── Unique toggle ── */
    .unique-toggle {
      display: inline-flex; align-items: center; gap: 0.3rem;
      height: 28px; padding: 0 var(--sp-3); border-radius: var(--r-md);
      font-size: 0.775rem; font-weight: 500; cursor: pointer;
      border: 1.5px dashed var(--border-2); background: var(--surface);
      color: var(--ink-3); transition: all 0.12s; font-family: var(--font-sans);
      white-space: nowrap;
    }
    .unique-toggle:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-bg); border-style: solid; }
    .unique-toggle.active { background: var(--blue-bg); border-color: var(--blue); border-style: solid; color: var(--blue); font-weight: 600; }

    /* ── Bulk-select / trash ── */
    .bulk-select-info { font-size: 0.78rem; color: var(--ink-3); white-space: nowrap; }
    .trash-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: var(--r-md);
      border: 1px solid var(--border); background: var(--surface); color: var(--ink-3);
      cursor: pointer; transition: all 0.12s; flex-shrink: 0;
    }
    .trash-btn:hover { background: var(--bg-2); color: var(--ink); }
    .trash-btn.armed { background: var(--red-bg); border-color: var(--red); color: var(--red); }
    .trash-btn.armed:hover { background: var(--red); color: #fff; }
    .multi-btn {
      display: inline-flex; align-items: center; gap: 0.35rem;
      height: 30px; padding: 0 var(--sp-3); border-radius: var(--r-md);
      border: 1px solid var(--border); background: var(--surface); color: var(--ink-2);
      font-family: var(--font-sans); font-size: 0.8rem; font-weight: 500;
      cursor: pointer; transition: all 0.12s; white-space: nowrap; flex-shrink: 0;
    }
    .multi-btn:hover { background: var(--bg-2); color: var(--ink); }
    .multi-btn.armed { background: var(--green); border-color: var(--green); color: #fff; }
    .multi-btn.armed:hover { background: var(--green-2, oklch(46% 0.13 155)); }
    #dashboard-table td.td-select, #dashboard-table th.td-select { display: none; }

    @media (max-width: 640px) {
      .toolbar { padding: var(--sp-3); }
      .toolbar-row { gap: var(--sp-2); }
      .filter-row { gap: var(--sp-1); }
      .toolbar-right { margin-left: 0; width: 100%; justify-content: flex-end; }
    }

    /* ── Number input (activation time) ── */
    .number-input-sm {
      width: 56px; padding: 0.2rem 0.35rem; font-size: 0.8rem;
      font-family: var(--font-mono); border: 1px solid var(--border-2);
      border-radius: var(--r-sm); color: var(--ink); background: var(--surface);
      text-align: center; outline: none;
    }
    .number-input-sm:focus { border-color: var(--accent); }

    /* ── Table ── */
    .table-wrap {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--r-lg); overflow: hidden; box-shadow: var(--shadow-sm);
    }
    table.data-table {
      width: 100%; border-collapse: collapse; font-size: 0.875rem;
    }
    .data-table thead {
      background: var(--bg-2); border-bottom: 1px solid var(--border);
    }
    .data-table th {
      padding: var(--sp-3) var(--sp-4); text-align: left;
      font-size: 0.7rem; font-weight: 600; text-transform: uppercase;
      letter-spacing: 0.08em; color: var(--ink-3); white-space: nowrap;
      cursor: pointer; user-select: none;
    }
    .data-table th:hover { color: var(--ink); }
    .data-table th.sorted { color: var(--ink); }
    .data-table th .sort-icon { opacity: 0.4; margin-left: 3px; }
    .data-table th.sorted .sort-icon { opacity: 1; }
    .data-table th.text-right { text-align: right; }
    .data-table tbody tr {
      border-bottom: 1px solid var(--border);
      transition: background 0.1s; cursor: pointer;
    }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody tr:hover { background: var(--bg-2); }
    .data-table tbody tr.row-ready { background: var(--green-bg); }
    .data-table tbody tr.row-ready:hover { background: oklch(93% 0.05 155); }
    .data-table tbody tr.row-activated { background: var(--gray-bg); opacity: 0.65; }
    .data-table td { padding: var(--sp-3) var(--sp-4); vertical-align: middle; }
    .data-table td.text-right { text-align: right; }

    /* ── Badges ── */
    .badge {
      display: inline-flex; align-items: center; padding: 2px 8px;
      border-radius: 100px; font-size: 0.7rem; font-weight: 600;
      letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap;
    }
    .badge-easy        { background: var(--green-bg);  color: var(--green); }
    .badge-moderate    { background: var(--orange-bg); color: var(--orange); }
    .badge-hard        { background: var(--red-bg);    color: var(--red); }
    .badge-drive-up    { background: var(--blue-bg);   color: var(--blue); }
    .badge-nominated   { background: var(--blue-bg);   color: var(--blue); }
    .badge-researched  { background: var(--orange-bg); color: var(--orange); }
    .badge-ready       { background: var(--green-bg);  color: var(--green); }
    .badge-activated   { background: var(--gray-bg);   color: var(--gray-badge); }

    /* ── Summit name cell ── */
    .data-table thead th:first-child { padding-left: var(--sp-5); }
    .td-main a { display: block; padding-left: var(--sp-5); }
    .summit-name { font-weight: 600; font-size: 0.9rem; color: var(--ink); line-height: 1.25; }
    .summit-ref { font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-3); margin-top: 2px; }

    /* ── Stat cells ── */
    .stat-val { font-variant-numeric: tabular-nums; font-size: 0.875rem; color: var(--ink); }
    .stat-unit { font-size: 0.72rem; color: var(--ink-3); margin-left: 1px; }
    .total-time { font-variant-numeric: tabular-nums; font-weight: 600; font-size: 0.9rem; color: var(--ink); }
    .last-act-callsign { font-family: var(--font-mono); font-size: 0.72rem; color: var(--ink-3); margin-top: 2px; }

    /* ── Points dot ── */
    .points-dot {
      display: inline-flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; border-radius: 50%;
      background: var(--bg-2); border: 1px solid var(--border-2);
      font-size: 0.7rem; font-weight: 700; color: var(--ink-2);
      font-variant-numeric: tabular-nums;
    }
    .bonus-badge {
      position: relative;
      font-size: 0.62rem; font-weight: 700; color: var(--orange);
      margin-left: 3px; vertical-align: super; line-height: 1;
      font-variant-numeric: tabular-nums; cursor: help;
    }
    .nested-summit-row .bonus-badge { font-size: 0.56rem; }
    .multi-summary-row .bonus-badge { font-size: 0.5rem; }
    .bonus-badge::after {
      content: attr(data-tip);
      position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
      background: var(--ink); color: #fff; font-size: 0.7rem; font-weight: 500; line-height: 1.4;
      padding: 5px 9px; border-radius: var(--r-sm); width: max-content; max-width: 200px; text-align: center;
      opacity: 0; visibility: hidden; transition: opacity 0.08s ease; pointer-events: none; z-index: 20;
      box-shadow: var(--shadow-md); text-transform: none; letter-spacing: normal;
    }
    .bonus-badge::before {
      content: ""; position: absolute; bottom: calc(100% + 1px); left: 50%; transform: translateX(-50%);
      border: 4px solid transparent; border-top-color: var(--ink);
      opacity: 0; visibility: hidden; transition: opacity 0.08s ease; pointer-events: none; z-index: 20;
    }
    .bonus-badge:hover::after, .bonus-badge:hover::before { opacity: 1; visibility: visible; }

    /* ── Multi-activation summary row + nested member rows ──
       Deliberately a darker green than the plain "Ready" rows (--green-bg),
       so a saved route reads as its own distinct-but-related status at a glance. */
    .multi-summary-row { background: var(--green-dark-bg) !important; border-left: 3px solid var(--green-dark); }
    .multi-summary-row:hover { background: oklch(83% 0.08 155) !important; }
    .multi-summary-row .summit-name { color: var(--green-dark-2); }
    .multi-expand-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 22px; height: 22px; margin-right: 4px; vertical-align: middle;
      border: none; border-radius: var(--r-sm); background: transparent;
      cursor: pointer; color: var(--green-dark-2); padding: 0; flex-shrink: 0;
    }
    .multi-expand-btn:hover { background: oklch(80% 0.09 155); }
    .multi-expand-caret { transition: transform 0.15s; display: inline-flex; line-height: 1; }
    .multi-expand-btn.open .multi-expand-caret { transform: rotate(180deg); }
    .badge-multi { background: var(--green-dark-bg); color: var(--green-dark-2); border: 1px solid var(--green-dark-border); }
    .data-table tbody tr.nested-summit-row { background: oklch(94% 0.045 155) !important; opacity: 1 !important; }
    .data-table tbody tr.nested-summit-row:hover { background: oklch(91% 0.055 155) !important; }
    .nested-summit-row .td-main { border-left: 3px solid var(--green-dark-border); }
    .nested-summit-row .td-main a { padding-left: var(--sp-8); }
    .route-order-chip {
      display: inline-flex; align-items: center; justify-content: center;
      width: 15px; height: 15px; border-radius: 50%; background: var(--green-dark); color: #fff;
      font-size: 0.58rem; font-weight: 700; margin-right: 5px; vertical-align: middle;
    }
    /* Nested member rows read as a route "header" child — ~75% of a normal row */
    .data-table tbody tr.nested-summit-row td { padding-top: var(--sp-2) !important; padding-bottom: var(--sp-2) !important; }
    .nested-summit-row .summit-name { font-size: 0.8rem; }
    .nested-summit-row .summit-ref { font-size: 0.65rem; }
    .nested-summit-row .stat-val, .nested-summit-row .total-time { font-size: 0.78rem; }
    .nested-summit-row .badge { padding: 1px 6px; font-size: 0.6rem; }
    .nested-summit-row .points-dot { width: 18px; height: 18px; font-size: 0.6rem; }

    /* The multi-summary row itself reads as a slim section header/divider above
       its nested summits, not a peer row — collapsed to one line and much
       shorter than either a normal summit row or its own nested children. */
    .data-table tbody tr.multi-summary-row td { padding-top: 3px !important; padding-bottom: 3px !important; }
    .multi-summary-row .td-main a { display: flex; align-items: baseline; gap: 6px; min-width: 0; }
    .multi-summary-row .summit-name { font-size: 0.74rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
    .multi-summary-row .summit-ref { font-size: 0.62rem; margin-top: 0; white-space: nowrap; flex-shrink: 0; }
    .multi-summary-row .stat-val, .multi-summary-row .total-time { font-size: 0.72rem; }
    .multi-summary-row .badge { padding: 0 5px; font-size: 0.56rem; }
    .multi-summary-row .points-dot { width: 15px; height: 15px; font-size: 0.55rem; }
    .multi-summary-row .multi-expand-btn { width: 17px; height: 17px; }

    /* ── Empty state ── */
    .add-summits-footer { text-align: center; padding: var(--sp-6) 0 var(--sp-2); }

    .empty { text-align: center; padding: var(--sp-16) var(--sp-8); }
    .empty-icon { font-size: 2.5rem; margin-bottom: var(--sp-4); opacity: 0.3; }
    .empty h3 { color: var(--ink); margin-bottom: var(--sp-2); }
    .empty p { color: var(--ink-3); font-size: 0.9rem; max-width: 36ch; margin: 0 auto var(--sp-6); }

    /* ── Footer ── */
    .footer {
      text-align: center; padding: var(--sp-8) var(--sp-4) var(--sp-6);
      color: var(--ink-4); font-size: 0.78rem;
      border-top: 1px solid var(--border); margin-top: var(--sp-12);
    }
    .footer a { color: var(--ink-3); }
    .footer a:hover { color: var(--ink); }

    /* ── Modal ── */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(28,27,25,0.5); z-index: 200;
      align-items: center; justify-content: center;
      backdrop-filter: blur(2px);
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
      background: var(--surface); border-radius: var(--r-xl);
      padding: var(--sp-8); max-width: 560px; width: 92%;
      position: relative; box-shadow: var(--shadow-lg);
      max-height: 90vh; overflow-y: auto;
    }
    .modal-close {
      position: absolute; top: var(--sp-4); right: var(--sp-4);
      background: none; border: none; font-size: 1.25rem; cursor: pointer;
      color: var(--ink-3); line-height: 1; padding: var(--sp-1);
      border-radius: var(--r-sm); transition: background 0.1s, color 0.1s;
    }
    .modal-close:hover { background: var(--bg-2); color: var(--ink); }
    .hiw-step { display: flex; gap: 14px; margin-bottom: 18px; align-items: flex-start; }
    .hiw-num {
      width: 28px; height: 28px; border-radius: 50%; background: var(--ink);
      color: #fff; display: flex; align-items: center; justify-content: center;
      font-size: 0.78rem; font-weight: 700; flex-shrink: 0;
    }
    .hiw-text h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 2px; }
    .hiw-text p { font-size: 0.82rem; color: var(--ink-3); margin: 0; line-height: 1.5; }
    .hiw-section {
      font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
      letter-spacing: 0.07em; color: var(--ink-3); margin: var(--sp-5) 0 var(--sp-3);
    }
    .hiw-section:first-child { margin-top: 0; }
    .hiw-list { margin: 0 0 var(--sp-4) var(--sp-5); font-size: 0.85rem; line-height: 1.7; color: var(--ink-2); }
    .hiw-list li { margin-bottom: 0.3rem; }

    /* ── Mobile responsive ── */
    @media (max-width: 768px) {
      .page { padding: var(--sp-4); }
      .topbar {
        height: auto;
        min-height: 52px;
        flex-wrap: wrap;
        padding: var(--sp-2) var(--sp-4);
        gap: var(--sp-2);
      }
      .topbar-logo { order: 1; }
      .topbar-right { order: 2; margin-left: auto; }
      nav.topbar > .topbar-divider { display: none; }
      .topbar-context {
        order: 3;
        width: 100%;
        flex-wrap: wrap;
        gap: 6px;
        border-top: 1px solid var(--border);
        padding: var(--sp-2) 0 var(--sp-1);
      }
      .topbar-nav { display: none; }
      .hamburger-menu { display: block; }
      .topbar-context { flex-wrap: nowrap; gap: 6px; }
      .topbar-addr { display: none; }
      .select-inline { max-width: 140px; }
      #btn-trash, #btn-multi, #btn-cancel-select, #bulk-select-info { display: none !important; }

      .table-wrap { border: none; background: transparent; box-shadow: none; overflow: visible; }
      .data-table thead { display: none; }
      .data-table, .data-table tbody { display: flex; flex-direction: column; gap: var(--sp-2); }
      .data-table tbody tr {
        display: grid !important;
        grid-template-columns: 1fr auto;
        background: var(--surface);
        border: 1px solid var(--border) !important;
        border-radius: var(--r-lg);
        padding: var(--sp-3);
        gap: var(--sp-2);
        cursor: pointer;
        transition: background 0.1s;
      }
      .data-table tbody tr.row-ready { background: var(--green-bg); border-color: oklch(88% 0.06 155) !important; }
      .data-table tbody tr.row-activated { background: var(--gray-bg); opacity: 0.6; }
      .data-table tbody tr.nested-summit-row { margin-left: var(--sp-6); border-left: 3px solid var(--green-dark-border) !important; }
      /* The generic "tr { display: grid !important }" above beats the desktop
         ".multi-nested-row { display: none }" rule since both are unqualified
         by specificity and !important always wins — so on mobile a collapsed
         route's members stayed visible no matter what the caret toggle did.
         This more specific selector (class + :not) restores the hide. */
      .data-table tbody tr.multi-nested-row:not(.is-open) { display: none !important; }
      .data-table td { padding: 0; border: none; }
      .td-main    { grid-column: 1; grid-row: 1; }
      .td-time    { grid-column: 2; grid-row: 1; text-align: right; align-self: center; }
      .td-diff    { grid-column: 1; grid-row: 2; align-self: center; }
      .td-status  { grid-column: 2; grid-row: 2; text-align: right; align-self: center; }
      .td-stats   { grid-column: 1 / -1; grid-row: 3; font-size: 0.72rem; color: var(--ink-3); padding-top: var(--sp-2); border-top: 1px solid var(--border); margin-top: var(--sp-1); }
      .td-hide-mobile { display: none; }
    }
    @media (min-width: 769px) {
      .td-main, .td-time, .td-diff, .td-status { display: table-cell; vertical-align: middle; }
      .td-hide-mobile { display: table-cell; }
      .td-stats { display: none; }
      #dashboard-table.select-mode td.td-select, #dashboard-table.select-mode th.td-select {
        display: table-cell; text-align: center; width: 34px;
      }
      /* A saved route can't be folded into another route — only its member
         summits are selectable while building a new multi-activation. */
      #dashboard-table.mode-multi .multi-row-select { visibility: hidden; }
    }
    .multi-nested-row { display: none; }
    .multi-nested-row.is-open { display: table-row; }

    /* ── View toggle ── */
    .view-toggle-group {
      display: flex; border: 1px solid var(--border-2); border-radius: var(--r-md); overflow: hidden;
    }
    .view-toggle-btn {
      display: inline-flex; align-items: center; gap: 5px;
      height: 30px; padding: 0 var(--sp-3); font-family: var(--font-sans);
      font-size: 0.8rem; font-weight: 500; cursor: pointer;
      border: none; background: var(--surface); color: var(--ink-3);
      transition: background 0.12s, color 0.12s; white-space: nowrap;
    }
    .view-toggle-btn:hover { background: var(--bg-2); color: var(--ink); }
    .view-toggle-btn.active { background: var(--ink); color: #fff; }
    .view-toggle-btn + .view-toggle-btn { border-left: 1px solid var(--border-2); }

    /* ── Dashboard map ── */
    #map-view {
      margin-bottom: var(--sp-4); border: 1px solid var(--border);
      border-radius: var(--r-lg); overflow: hidden; box-shadow: var(--shadow-sm);
      position: relative; isolation: isolate;
    }
    #dash-map { height: 580px; width: 100%; }
    /* "Map" base layer reuses OpenStreetMap tiles with a muted filter instead of a
       separate CDN (e.g. CartoDB), avoiding a third-party tile API dependency. */
    .sota-muted-tiles .leaflet-tile-pane { filter: grayscale(65%) brightness(1.08) contrast(0.95); }
    .map-loading {
      position: absolute; inset: 0; background: var(--bg);
      display: flex; align-items: center; justify-content: center;
      z-index: 9999; flex-direction: column; gap: 14px;
    }
    .map-loading-svg { width: 80px; height: 80px; overflow: visible; }
    .map-logo-path {
      stroke-dasharray: 116; stroke-dashoffset: 116;
      animation: map-path-draw 3s ease-in-out infinite;
    }
    @keyframes map-path-draw {
      0%   { stroke-dashoffset: 116; opacity: 0; }
      7%   { stroke-dashoffset: 116; opacity: 1; }
      62%  { stroke-dashoffset: 0;   opacity: 1; }
      80%  { stroke-dashoffset: 0;   opacity: 1; }
      94%  { stroke-dashoffset: 0;   opacity: 0; }
      100% { stroke-dashoffset: 116; opacity: 0; }
    }
    .map-logo-dot {
      fill: var(--red); transform-box: fill-box; transform-origin: center;
      animation: map-dot-pop 3s ease-in-out 1.2s infinite; opacity: 0;
    }
    @keyframes map-dot-pop {
      0%   { transform: scale(0);   opacity: 0; }
      15%  { transform: scale(1.4); opacity: 1; }
      30%  { transform: scale(1);   opacity: 1; }
      72%  { transform: scale(1);   opacity: 1; }
      90%  { transform: scale(0.4); opacity: 0; }
      100% { transform: scale(0);   opacity: 0; }
    }
    .map-logo-ring { transform-box: fill-box; transform-origin: center; opacity: 0; }
    .map-logo-ring1 { animation: map-ring-pulse 3s ease-out 1.2s infinite; }
    .map-logo-ring2 { animation: map-ring-pulse 3s ease-out 1.5s infinite; }
    @keyframes map-ring-pulse {
      0%   { transform: scale(0.5); opacity: 0; }
      10%  { opacity: 0.5; }
      68%  { transform: scale(2.4); opacity: 0; }
      100% { transform: scale(0.5); opacity: 0; }
    }
    .map-loading-text { font-size: 0.8rem; color: var(--ink-3); font-weight: 500; }

    /* ── Leaflet marker badges ── */
    .lmap-badge {
      padding: 4px 9px; border-radius: 20px; font-size: 11px; font-weight: 700;
      font-family: 'DM Sans', system-ui, sans-serif; white-space: nowrap;
      box-shadow: 0 2px 6px rgba(0,0,0,0.25); cursor: pointer;
      letter-spacing: 0.02em; border: 1.5px solid rgba(255,255,255,0.45);
      line-height: 1; user-select: none; display: block; text-align: center;
      transition: filter 0.1s, transform 0.1s;
    }
    .lmap-badge:hover { filter: brightness(1.12); transform: scale(1.06); }
    .lmap-green  { background: oklch(48% 0.13 155); color: #fff; }
    .lmap-orange { background: oklch(56% 0.14 50);  color: #fff; }
    .lmap-blue   { background: oklch(48% 0.12 240); color: #fff; }
    .lmap-gray   { background: oklch(50% 0.02 200); color: #fff; }
    .lmap-warn   { background: #888; color: #fff; font-weight: 600; }

    /* ── Base map type toggle ── */
    .dash-base-toggle {
      display: flex; background: var(--surface); border: 1px solid var(--border-2);
      border-radius: var(--r-md); overflow: hidden; box-shadow: 0 1px 4px rgba(28,27,25,0.12);
    }
    .dash-base-btn {
      display: flex; align-items: center; justify-content: center;
      height: 32px; padding: 0 12px; font-family: 'DM Sans', system-ui, sans-serif;
      font-size: 0.8rem; font-weight: 500; color: var(--ink-2);
      background: var(--surface); border: none; cursor: pointer; white-space: nowrap;
      transition: background 0.12s, color 0.12s;
    }
    .dash-base-btn + .dash-base-btn { border-left: 1px solid var(--border-2); }
    .dash-base-btn:hover { background: var(--bg-2); color: var(--ink); }
    .dash-base-btn.active { background: var(--ink); color: #fff; }

    /* ── Find-summits control button ── */
    .dash-find-btn {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--surface); border: 1px solid var(--border-2);
      border-radius: var(--r-md); padding: 0 12px; height: 32px;
      font-family: 'DM Sans', system-ui, sans-serif; font-size: 0.8rem;
      font-weight: 500; color: var(--ink-2); cursor: pointer;
      box-shadow: 0 1px 4px rgba(28,27,25,0.12);
      transition: background 0.12s, color 0.12s;
    }
    .dash-find-btn:hover { background: var(--accent-bg); color: var(--accent); border-color: var(--accent-border); }

    /* ── Leaflet tooltip override ── */
    .leaflet-tooltip.dash-tip {
      background: var(--surface); border: 1px solid var(--border-2);
      border-radius: var(--r-md); padding: 8px 12px;
      box-shadow: 0 4px 16px rgba(28,27,25,0.12); pointer-events: none;
      font-family: 'DM Sans', system-ui, sans-serif;
    }
    .leaflet-tooltip.dash-tip::before { display: none; }
    .dash-tip-points {
      display: inline-flex; align-items: center; justify-content: center;
      width: 24px; height: 24px; border-radius: 50%;
      color: #fff; font-size: 0.75rem; font-weight: 800;
      margin-bottom: 4px;
    }
    .dash-tip-ref  { font-family: 'DM Mono', monospace; font-size: 0.7rem; color: var(--ink-3); margin-bottom: 3px; }
    .dash-tip-name { font-size: 0.875rem; font-weight: 600; color: var(--ink); margin-bottom: 5px; line-height: 1.25; }
    .dash-tip-time { font-size: 0.8rem; font-weight: 700; color: var(--accent); }
    .dash-tip-breakdown { font-size: 0.7rem; color: var(--ink-3); margin-top: 3px; display: flex; align-items: center; gap: 5px; }
    .dash-tip-dot { color: var(--ink-4); }
    </style>
</head>
<body>

<nav class="topbar">
    <a href="index.php" class="topbar-logo">
        <span class="logo-mark">
            <img src="sota-planner-logo.svg" width="32" height="32" alt="">
        </span>
        <span>SOTAplanner</span>
    </a>
    <div class="topbar-divider"></div>

    <div class="topbar-context">
        <div class="ctx-group">
            <span class="ctx-label">Dashboard</span>
            <form method="POST" style="display:contents">
                <select name="planning_group_id" class="select-inline" onchange="this.form.submit()">
                    <?php foreach ($all_groups as $group): ?>
                        <option value="<?= $group['id'] ?>" <?= ($current_group && $current_group['id'] == $group['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="select_planning_group" value="1">
            </form>
            <form method="POST" style="display:contents">
                <input type="hidden" name="set_default_group" value="1">
                <input type="hidden" name="default_group_id" value="<?= $current_group['id'] ?>">
                <button type="submit"
                        class="star-btn <?= (!empty($_COOKIE['sota_default_group']) && (int)$_COOKIE['sota_default_group'] === $current_group['id']) ? 'active' : '' ?>"
                        title="Set as default dashboard">★</button>
            </form>
        </div>

        <span class="topbar-addr">
        <?php if (count($all_addresses) > 0): ?>
            <span class="ctx-sep">·</span>
            <div class="ctx-group">
            <span class="ctx-label">From</span>
            <form method="POST" style="display:contents">
                <select name="address_id" class="select-inline" onchange="this.form.submit()">
                    <?php foreach ($all_addresses as $addr): ?>
                        <option value="<?= $addr['id'] ?>" <?= ($selected_address && $selected_address['id'] == $addr['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($addr['label'] ?: $addr['address']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="select_address" value="1">
            </form>
            <?php if ($selected_address): ?>
            <form method="POST" style="display:contents">
                <input type="hidden" name="set_default_address" value="1">
                <input type="hidden" name="default_address_id" value="<?= $selected_address['id'] ?>">
                <button type="submit"
                        class="star-btn <?= (!empty($_COOKIE[$cookie_addr_key]) && (int)$_COOKIE[$cookie_addr_key] === $selected_address['id']) ? 'active' : '' ?>"
                        title="Set as default address">★</button>
            </form>
            <?php endif; ?>
            </div>
        <?php else: ?>
            <span class="ctx-sep">·</span>
            <span style="font-size:0.78rem; color:var(--ink-4)"><a href="planning_groups.php" style="color:var(--ink-4)">Add an address</a></span>
        <?php endif; ?>
        </span>
    </div>

    <div class="topbar-right">
        <nav class="topbar-nav">
            <a href="planning_groups.php">Manage Dashboards</a>
            <a href="about.php">About</a>
            <a href="#" onclick="document.getElementById('howModal').style.display='flex'; return false;">How It Works</a>
        </nav>
        <div class="hamburger-menu" id="hamburgerMenu">
            <button type="button" class="hamburger-btn" aria-label="Menu">
                <svg width="17" height="17" viewBox="0 0 17 17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="2" y1="4.5" x2="15" y2="4.5"/><line x1="2" y1="8.5" x2="15" y2="8.5"/><line x1="2" y1="12.5" x2="15" y2="12.5"/></svg>
            </button>
            <div class="hamburger-dropdown">
                <a href="planning_groups.php">Manage Dashboards</a>
                <a href="about.php">About</a>
                <a href="#" onclick="document.getElementById('howModal').style.display='flex'; document.getElementById('hamburgerMenu').classList.remove('open'); return false;">How It Works</a>
            </div>
        </div>
        <a href="nominate.php" class="btn btn-primary btn-sm">+ Add Summits</a>
        <div class="topbar-divider"></div>
        <div class="user-chip" id="userChip">
            <?= htmlspecialchars(getCurrentCallsign()) ?>
            <?php if (($_SESSION['sota_login_type'] ?? '') === 'dev'): ?>
                <span style="background:#856404; color:#fff3cd; font-size:0.65rem; padding:0.05rem 0.3rem; border-radius:3px;">DEV</span>
            <?php endif; ?>
            <svg class="user-chip-chevron" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="2,3.5 5,6.5 8,3.5"/></svg>
            <div class="user-dropdown">
                <?php if (($_SESSION['sota_callsign'] ?? '') === 'KI6CR' || !empty($_SESSION['_god_mode_real_callsign'])): ?>
                    <a href="god_mode.php">God Mode</a>
                <?php endif; ?>
                <a href="user_settings.php">Settings</a>
                <a href="logout.php">Sign Out</a>
            </div>
        </div>
    </div>
</nav>

<?php if (!empty($_SESSION['_god_mode_real_callsign'])): ?>
<div style="background:oklch(52% 0.16 22); color:#fff; text-align:center; padding:0.5rem 1rem; font-size:0.82rem; font-weight:600; display:flex; align-items:center; justify-content:center; gap:1rem;">
    ⚠️ Impersonating <strong><?= htmlspecialchars($_SESSION['sota_callsign'] ?? '') ?></strong>
    <a href="god_mode.php" style="color:#fff; background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.4); padding:0.2rem 0.75rem; border-radius:4px; font-size:0.78rem; text-decoration:none;">Return to God Mode</a>
</div>
<?php endif; ?>
<?php if (!empty($_sitewide_banner['text'])): ?>
<div style="background:var(--<?= $_sitewide_banner['type'] === 'success' ? 'green' : ($_sitewide_banner['type'] === 'error' ? 'red' : 'accent') ?>-bg); border-bottom:1px solid var(--border); padding:0.6rem var(--sp-8); font-size:0.85rem; font-weight:500; color:var(--ink-2); text-align:center;">
    <?= htmlspecialchars($_sitewide_banner['text']) ?>
</div>
<?php endif; ?>

<div class="page">

    <?php if ($message): ?>
    <div class="msg msg-info">
        <span><?= htmlspecialchars($message) ?><?php if (str_contains($message, '⭐')): ?> <span style="color:var(--accent); font-size:0.8rem; font-weight:400; opacity:0.75"> · Saved in browser cookie for 1 year.</span><?php endif; ?></span>
        <button class="msg-dismiss" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <?php if (!empty($_GET['bulk_nominated'])): ?>
    <?php $bn = (int)$_GET['bulk_nominated']; ?>
    <div class="msg msg-success" style="margin-bottom:1rem;">
        <span><?= $bn ?> summit<?= $bn !== 1 ? 's' : '' ?> added to your dashboard.</span>
        <button class="msg-dismiss" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <!-- Toolbar: filters + controls -->
    <div class="toolbar">
        <!-- Row 1: filter pills -->
        <div class="toolbar-row">
            <span class="toolbar-label">Filter</span>
            <div class="toolbar-sep"></div>
            <div class="filter-row">
                <?php
                $all_filter_keys = ['drive-up', 'easy', 'moderate', 'hard', 'ready', 'needs-research', 'activated-this-year'];
                $is_all = (count($active_filters) === count($all_filter_keys));
                ?>
                <button onclick="selectAll()" class="filter-pill <?= $is_all ? 'active' : '' ?>">All</button>
                <div class="filter-sep"></div>
                <?php
                $diff_filters = ['drive-up' => 'Drive-up', 'easy' => 'Easy', 'moderate' => 'Moderate', 'hard' => 'Hard'];
                foreach ($diff_filters as $key => $label):
                    $active = in_array($key, $active_filters);
                ?>
                    <button onclick="toggleFilter('<?= $key ?>')" class="filter-pill <?= $active ? 'active' : '' ?>"><?= $label ?></button>
                <?php endforeach; ?>
                <div class="filter-sep"></div>
                <?php
                $status_filters = ['ready' => 'Ready', 'needs-research' => 'Research Needed', 'activated-this-year' => 'Activated This Year'];
                foreach ($status_filters as $key => $label):
                    $active = in_array($key, $active_filters);
                ?>
                    <button onclick="toggleFilter('<?= $key ?>')" class="filter-pill <?= $active ? 'active' : '' ?>"><?= $label ?></button>
                <?php endforeach; ?>
            </div>
            <div class="toolbar-sep"></div>
            <span class="toolbar-label">Points</span>
            <select class="select-inline" id="min_pts_select" onchange="changeMinPts(this.value)" style="max-width:110px;">
                <option value="0"<?= $min_pts === 0 ? ' selected' : '' ?>>All</option>
                <option value="2"<?= $min_pts === 2 ? ' selected' : '' ?>>2+</option>
                <option value="4"<?= $min_pts === 4 ? ' selected' : '' ?>>4+</option>
                <option value="6"<?= $min_pts === 6 ? ' selected' : '' ?>>6+</option>
                <option value="8"<?= $min_pts === 8 ? ' selected' : '' ?>>8+</option>
                <option value="10"<?= $min_pts === 10 ? ' selected' : '' ?>>10</option>
            </select>
        </div>
        <!-- Row 2: view toggle + unique toggle + activation controls -->
        <div class="toolbar-row">
            <div class="view-toggle-group">
                <button class="view-toggle-btn active" id="btn-list-view" onclick="setDashView('list')" title="List view">
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="4.5" y1="3" x2="12" y2="3"/><line x1="4.5" y1="6.5" x2="12" y2="6.5"/><line x1="4.5" y1="10" x2="12" y2="10"/><circle cx="2" cy="3" r="0.9" fill="currentColor" stroke="none"/><circle cx="2" cy="6.5" r="0.9" fill="currentColor" stroke="none"/><circle cx="2" cy="10" r="0.9" fill="currentColor" stroke="none"/></svg>
                    List
                </button>
                <button class="view-toggle-btn" id="btn-map-view" onclick="setDashView('map')" title="Map view">
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="1,2.5 4.5,1 8.5,2.5 12,1 12,10.5 8.5,12 4.5,10.5 1,12"/><line x1="4.5" y1="1" x2="4.5" y2="10.5"/><line x1="8.5" y1="2.5" x2="8.5" y2="12"/></svg>
                    Map
                </button>
            </div>
            <div class="toolbar-sep"></div>
            <button onclick="toggleUnique()" class="unique-toggle <?= $unique_only ? 'active' : '' ?>">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="6,1 7.5,4.5 11,4.8 8.5,7 9.3,10.5 6,8.5 2.7,10.5 3.5,7 1,4.8 4.5,4.5"/></svg>
                Unique Summits
            </button>
            <div class="toolbar-sep"></div>
            <span style="font-size:0.78rem; color:var(--ink-3); white-space:nowrap">Activation</span>
            <input type="number" id="activation_time" value="<?= $activation_time ?>" min="15" max="300" step="15" class="number-input-sm">
            <span style="font-size:0.78rem; color:var(--ink-3)">min</span>
            <?php if ($selected_address && GOOGLE_MAPS_API_KEY !== 'YOUR_API_KEY_HERE'): ?>
                <div class="toolbar-sep"></div>
                <form method="POST" style="margin:0">
                    <button type="submit" name="calculate_drive_times" class="btn btn-ghost btn-sm">Recalculate Travel Times</button>
                </form>
            <?php endif; ?>
            <div class="toolbar-right">
                <span id="bulk-select-info" class="bulk-select-info" style="display:none"></span>
                <button type="button" id="btn-cancel-select" class="btn btn-ghost btn-sm" style="display:none" onclick="cancelSelectMode()">Cancel</button>
                <button type="button" id="btn-multi" class="multi-btn" onclick="onMultiClick()" title="Plan a multi-summit activation">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/></svg>
                    Multi-Activate
                </button>
                <button type="button" id="btn-trash" class="trash-btn" onclick="onTrashClick()" title="Delete summits or routes">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
            </div>
        </div>
    </div>

    <div style="font-size:0.72rem; color:var(--ink-4); margin-bottom:var(--sp-4)">
        <?= count($summits) ?> summit<?= count($summits) !== 1 ? 's' : '' ?>
        <?php if (!$is_all): ?>
            · <?= count($active_filters) ?> filter<?= count($active_filters) !== 1 ? 's' : '' ?> active
        <?php endif; ?>
        <?php if ($unique_only): ?>
            · unique only
        <?php endif; ?>
    </div>

    <!-- Map view container (hidden by default, toggled via JS) -->
    <div id="map-view" style="display:none">
        <div class="map-loading" id="map-loading-overlay">
            <svg class="map-loading-svg" viewBox="0 0 110 110" xmlns="http://www.w3.org/2000/svg">
                <circle cx="55" cy="55" r="50" fill="none" stroke="var(--border-2)" stroke-width="1.5"/>
                <path class="map-logo-path" d="M26,79.5l17-30,7,8,12-20,22,42"
                      fill="none" stroke="var(--ink)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                <circle class="map-logo-ring map-logo-ring2" cx="62" cy="35.5" r="15" fill="none" stroke="var(--red)" stroke-width="0.8"/>
                <circle class="map-logo-ring map-logo-ring1" cx="62" cy="35.5" r="9"  fill="none" stroke="var(--red)" stroke-width="1.2"/>
                <circle class="map-logo-dot" cx="62" cy="35.5" r="3.5"/>
            </svg>
            <div class="map-loading-text">Loading map…</div>
        </div>
        <div id="dash-map"></div>
    </div>

    <!-- List view container -->
    <div id="list-view">
    <?php if (empty($summits)): ?>
        <div class="empty">
            <div class="empty-icon">⛰</div>
            <h3>No summits<?= !$is_all ? ' match the current filters' : ' nominated yet' ?></h3>
            <p><?= !$is_all ? 'Try adjusting the filters above.' : 'Nominate your first summit to start planning activations.' ?></p>
            <?php if ($is_all): ?>
                <a href="nominate.php" class="btn btn-primary" style="margin-top:var(--sp-4)">Nominate Your First Summit</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table" id="dashboard-table">
                <thead>
                    <tr>
                        <th class="td-select" onclick="event.stopPropagation()">
                            <input type="checkbox" id="select-all-rows" onclick="event.stopPropagation()" onchange="toggleSelectAllRows(this.checked)">
                        </th>
                        <th class="<?= $sort_by === 'name' ? 'sorted' : '' ?>" onclick="sortTable('name')">
                            Summit <span class="sort-icon"><?= $sort_by === 'name' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'points' ? 'sorted' : '' ?> td-hide-mobile" onclick="sortTable('points')">
                            Pts <span class="sort-icon"><?= $sort_by === 'points' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'difficulty' ? 'sorted' : '' ?>" onclick="sortTable('difficulty')">
                            Difficulty <span class="sort-icon"><?= $sort_by === 'difficulty' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'elevation_ft' ? 'sorted' : '' ?> td-hide-mobile" onclick="sortTable('elevation_ft')">
                            Elev. <span class="sort-icon"><?= $sort_by === 'elevation_ft' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'hike_distance_mi' ? 'sorted' : '' ?> td-hide-mobile" onclick="sortTable('hike_distance_mi')">
                            Distance <span class="sort-icon"><?= $sort_by === 'hike_distance_mi' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'hike_elevation_gain_ft' ? 'sorted' : '' ?> td-hide-mobile" onclick="sortTable('hike_elevation_gain_ft')">
                            Gain <span class="sort-icon"><?= $sort_by === 'hike_elevation_gain_ft' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'hike_time' ? 'sorted' : '' ?> td-hide-mobile text-right" onclick="sortTable('hike_time')">
                            Hike (RT) <span class="sort-icon"><?= $sort_by === 'hike_time' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'drive_time' ? 'sorted' : '' ?> td-hide-mobile text-right" onclick="sortTable('drive_time')">
                            Travel (RT) <span class="sort-icon"><?= $sort_by === 'drive_time' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'total_time' ? 'sorted' : '' ?> text-right" onclick="sortTable('total_time')">
                            Total Time <span class="sort-icon"><?= $sort_by === 'total_time' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'last_activated_date' ? 'sorted' : '' ?> td-hide-mobile" onclick="sortTable('last_activated_date')">
                            Last Activated <span class="sort-icon"><?= $sort_by === 'last_activated_date' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                        <th class="<?= $sort_by === 'status' ? 'sorted' : '' ?>" onclick="sortTable('status')">
                            Status <span class="sort-icon"><?= $sort_by === 'status' ? ($sort_order === 'ASC' ? '↑' : '↓') : '↕' ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summits as $summit):
                        $sid = $summit['id'];

                        // Lead of a saved multi: render its summary row + nested member rows instead
                        if (isset($multi_lookup[$sid])):
                            $ml = $multi_lookup[$sid];
                            $t = $ml['totals'];
                            $t_points = (int)($t['points'] ?? 0);
                            $t_hike   = (int)($t['hike_min'] ?? 0);
                            $t_drive  = (int)($t['drive_min'] ?? 0);
                            $t_total  = (int)($t['time_min'] ?? 0);
                            $t_dist   = (float)($t['dist_mi'] ?? 0);
                            $t_elev   = (int)($t['elev_ft'] ?? 0);
                            $route_label = $ml['name'] ?: (count($ml['members']) . '-Summit Route');
                            $multi_mobile_parts = array_filter([
                                $t_hike  ? 'Hike ' . formatTime($t_hike) : null,
                                $t_drive ? 'Travel ' . formatTime($t_drive) : null,
                                $t_dist  ? convertDistance($t_dist, $user_units) . ' ' . getDistanceUnit($user_units) : null,
                                $t_elev  ? number_format(convertElevation($t_elev, $user_units)) . ' ' . getElevationUnit($user_units) . ' gain' : null,
                            ]);
                    ?>
                    <tr class="multi-summary-row" data-multi-id="<?= $ml['multi_id'] ?>" onclick="window.location='multi_activate.php?id=<?= $ml['multi_id'] ?>&group=<?= $current_group['id'] ?>'">
                        <td class="td-select" onclick="event.stopPropagation()">
                            <input type="checkbox" class="multi-row-select" data-multi-id="<?= $ml['multi_id'] ?>" onchange="toggleMultiRowSelect(<?= $ml['multi_id'] ?>, this.checked)">
                        </td>
                        <td class="td-main" onclick="event.stopPropagation()">
                            <button type="button" class="multi-expand-btn<?= $ml['is_expanded'] ? ' open' : '' ?>" id="multi-toggle-<?= $ml['multi_id'] ?>" onclick="toggleMultiNested(<?= $ml['multi_id'] ?>)" title="Expand/collapse summits">
                                <span class="multi-expand-caret"><svg width="11" height="7" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            </button>
                            <a href="multi_activate.php?id=<?= $ml['multi_id'] ?>&group=<?= $current_group['id'] ?>" style="text-decoration:none">
                                <div class="summit-name"><?= htmlspecialchars($route_label) ?></div>
                                <div class="summit-ref"><?= count($ml['members']) ?> summits</div>
                            </a>
                        </td>
                        <td class="td-hide-mobile">
                            <span class="points-dot"><?= $t_points ?></span>
                        </td>
                        <td class="td-diff">
                            <span class="badge badge-multi">Multi-Route</span>
                        </td>
                        <td class="td-hide-mobile"><span style="color:var(--ink-4)">—</span></td>
                        <td class="td-hide-mobile">
                            <?php if ($t_dist): ?>
                                <span class="stat-val"><?= convertDistance($t_dist, $user_units) ?></span>
                                <span class="stat-unit"><?= getDistanceUnit($user_units) ?></span>
                            <?php else: ?><span style="color:var(--ink-4)">—</span><?php endif; ?>
                        </td>
                        <td class="td-hide-mobile">
                            <?php if ($t_elev): ?>
                                <span class="stat-val"><?= number_format(convertElevation($t_elev, $user_units)) ?></span>
                                <span class="stat-unit"><?= getElevationUnit($user_units) ?></span>
                            <?php else: ?><span style="color:var(--ink-4)">—</span><?php endif; ?>
                        </td>
                        <td class="td-hide-mobile text-right">
                            <span class="stat-val"><?= $t_hike ? formatTime($t_hike) : '—' ?></span>
                        </td>
                        <td class="td-hide-mobile text-right">
                            <span class="stat-val"><?= $t_drive ? formatTime($t_drive) : '—' ?></span>
                        </td>
                        <td class="td-time text-right">
                            <span class="total-time"><?= $t_total ? formatTime($t_total) : '—' ?></span>
                        </td>
                        <td class="td-hide-mobile td-last-activated"><span style="color:var(--ink-4)">—</span></td>
                        <td class="td-status"><span class="badge badge-multi">Planned Route</span></td>
                        <td class="td-stats"><?= implode(' · ', $multi_mobile_parts) ?></td>
                    </tr>
                    <?php foreach ($ml['members'] as $i => $mem):
                        $mem_summit = $summits_by_id[$mem['summit_id']] ?? null;
                        if (!$mem_summit) continue;
                        render_dashboard_row($mem_summit, $current_group, $user_units, $activation_time, true, $i + 1, $ml['multi_id'], $ml['is_expanded']);
                    endforeach; ?>
                    <?php
                            continue;
                        endif;

                        // Non-lead member of a saved multi: already rendered nested above, skip here
                        if (isset($multi_member_ids[$sid])) continue;

                        render_dashboard_row($summit, $current_group, $user_units, $activation_time, false);
                    endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="add-summits-footer">
        <a href="nominate.php" class="btn btn-primary">+ Add Summits</a>
    </div>
    </div><!-- /#list-view -->

</div><!-- /.page -->

<footer class="footer">
    SOTAplanner &nbsp;·&nbsp; <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

<!-- How It Works Modal -->
<div id="howModal" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-box" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="document.getElementById('howModal').style.display='none'">×</button>
        <h2 style="margin-bottom:var(--sp-5)">How SOTA Planner Works</h2>

        <p class="hiw-section">What This Tool Does</p>
        <p style="font-size:0.9rem; color:var(--ink-2); margin-bottom:var(--sp-5); line-height:1.7">SOTA Planner helps you plan activations from door to door — not just the hike. It combines travel time, hiking time, and radio time into a single total-day estimate so you can compare summits and pick the right one for your available time.</p>

        <p class="hiw-section">The Summit List</p>
        <ul class="hiw-list">
            <li><strong>Pts</strong> — SOTA points awarded for activating this summit.</li>
            <li><strong>Distance</strong> — round-trip hiking distance. From GPS track if one is loaded, otherwise manually entered.</li>
            <li><strong>Gain</strong> — total elevation gained on the approach.</li>
            <li><strong>Hike Time</strong> — round-trip hiking time. From GPS timestamps if available; otherwise Naismith's rule.</li>
            <li><strong>Travel Time</strong> — round-trip travel from your selected address to the starting point, via Google Maps.</li>
            <li><strong>Total Time</strong> — hike + drive + your planned activation time. Full door-to-door estimate.</li>
        </ul>

        <p class="hiw-section">Filters &amp; Settings</p>
        <ul class="hiw-list">
            <li><strong>Activation time</strong> — how long you plan to operate from the summit. Adjusts Total Time for all summits.</li>
            <li><strong>Status filters</strong> — show/hide summits by workflow stage.</li>
            <li><strong>Recalculate Travel Times</strong> — re-query Google Maps for all summits in your list.</li>
        </ul>

        <p class="hiw-section">Dashboards</p>
        <p style="font-size:0.9rem; color:var(--ink-2); margin-bottom:var(--sp-5); line-height:1.7">Each dashboard has its own summit list, addresses, and settings. Switch dashboards in the top bar. Dashboards let different clubs or styles maintain separate lists while sharing the same tool.</p>

        <p class="hiw-section">Adding Summits</p>
        <p style="font-size:0.9rem; color:var(--ink-2); line-height:1.7">Click <strong>+ Add Summits</strong> to add a summit by SOTA reference. Coordinates and points pull from the SOTA database automatically. Use the summit detail page to add trail info, upload a GPX track, and log activations.</p>

        <button class="btn btn-primary" style="width:100%; margin-top:var(--sp-6)" onclick="document.getElementById('howModal').style.display='none'">Got it</button>
    </div>
</div>

<script>
    document.getElementById('activation_time').addEventListener('change', function() {
        const minutes = this.value;
        fetch('save_activation_time.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'minutes=' + encodeURIComponent(minutes)
        }).then(() => {
            const url = new URL(window.location);
            url.searchParams.set('activation_time', minutes);
            window.location = url.toString();
        });
    });

    function sortTable(column) {
        const url = new URL(window.location);
        const currentSort = url.searchParams.get('sort');
        const currentOrder = url.searchParams.get('order') || 'DESC';
        let newOrder = 'DESC';
        if (currentSort === column && currentOrder === 'DESC') {
            newOrder = 'ASC';
        }
        url.searchParams.set('sort', column);
        url.searchParams.set('order', newOrder);
        window.location = url.toString();
    }

    function toggleFilter(filterKey) {
        const urlParams = new URLSearchParams(window.location.search);
        let currentFilter = urlParams.get('filter') || 'all';
        let filters;
        if (currentFilter === 'all' || currentFilter === '') {
            filters = ['drive-up', 'easy', 'moderate', 'hard', 'ready', 'needs-research', 'activated-this-year'];
        } else {
            filters = currentFilter.split(',');
        }
        const index = filters.indexOf(filterKey);
        if (index > -1) {
            filters.splice(index, 1);
        } else {
            filters.push(filterKey);
        }
        if (filters.length === 0) {
            urlParams.set('filter', 'none');
            window.location.search = urlParams.toString();
            return;
        }
        urlParams.set('filter', filters.join(','));
        window.location.search = urlParams.toString();
    }

    function selectAll() {
        const urlParams = new URLSearchParams(window.location.search);
        const current = urlParams.get('filter') || 'all';
        urlParams.set('filter', current === 'all' || current === '' ? 'none' : 'all');
        window.location.search = urlParams.toString();
    }

    function toggleUnique() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('unique') === '1') {
            urlParams.delete('unique');
        } else {
            urlParams.set('unique', '1');
        }
        window.location.search = urlParams.toString();
    }

    function changeMinPts(val) {
        const urlParams = new URLSearchParams(window.location.search);
        if (val === '0') {
            urlParams.delete('min_pts');
        } else {
            urlParams.set('min_pts', val);
        }
        window.location.search = urlParams.toString();
    }

    // ── Bulk select / delete / multi-activate ──
    const CURRENT_GROUP_ID = <?= (int)$current_group['id'] ?>;
    const MULTI_MAX = 11;
    const MULTI_PREFILL_IDS = <?= json_encode($multi_prefill_ids) ?>;
    const MULTI_EDIT_NAME = <?= json_encode($multi_edit_name) ?>;
    let multiEditId = <?= json_encode($multi_edit_id) ?>;
    let selectMode = false;
    let actionMode = null; // 'trash' | 'multi'
    const selectedIds = new Set();
    const selectedMultiIds = new Set();
    let _flashTimer = null;

    function onTrashClick() {
        if (!selectMode) {
            enterSelectMode('trash');
        } else if (selectedIds.size > 0 || selectedMultiIds.size > 0) {
            performBulkDelete();
        } else {
            cancelSelectMode();
        }
    }

    function onMultiClick() {
        if (!selectMode) {
            enterSelectMode('multi');
        } else if (selectedIds.size >= 2) {
            const ids = Array.from(selectedIds);
            let url = 'multi_activate.php?ids=' + ids.join(',') + '&group=' + CURRENT_GROUP_ID;
            if (multiEditId) url += '&id=' + multiEditId;
            window.location = url;
        }
    }

    function enterSelectMode(mode) {
        selectMode = true;
        actionMode = mode;
        document.getElementById('dashboard-table')?.classList.add('select-mode', 'mode-' + mode);
        document.getElementById('btn-cancel-select').style.display = '';
        document.getElementById('btn-trash').style.display = (mode === 'trash') ? '' : 'none';
        document.getElementById('btn-multi').style.display = (mode === 'multi') ? '' : 'none';
        document.querySelector('.toolbar-right')?.classList.add('floating');
        const allCb = document.getElementById('select-all-rows');
        if (allCb) allCb.disabled = (mode === 'multi');
        updateActionUI();
    }

    function cancelSelectMode() {
        selectMode = false;
        const prevMode = actionMode;
        actionMode = null;
        multiEditId = null;
        selectedIds.clear();
        selectedMultiIds.clear();
        document.querySelectorAll('.row-select, .multi-row-select').forEach(cb => cb.checked = false);
        const allCb = document.getElementById('select-all-rows');
        if (allCb) { allCb.checked = false; allCb.indeterminate = false; allCb.disabled = false; }
        document.getElementById('dashboard-table')?.classList.remove('select-mode', 'mode-' + prevMode);
        document.getElementById('btn-cancel-select').style.display = 'none';
        document.getElementById('btn-trash').style.display = '';
        document.getElementById('btn-multi').style.display = '';
        document.querySelector('.toolbar-right')?.classList.remove('floating');
        updateActionUI();
    }

    function toggleRowSelect(id, checked) {
        if (checked && actionMode === 'multi' && selectedIds.size >= MULTI_MAX) {
            const cb = document.querySelector('.row-select[data-id="' + id + '"]');
            if (cb) cb.checked = false;
            flashActionInfo('Multi-activations are capped at ' + MULTI_MAX + ' summits');
            return;
        }
        if (checked) selectedIds.add(id); else selectedIds.delete(id);
        const allBoxes = document.querySelectorAll('.row-select');
        const allCb = document.getElementById('select-all-rows');
        if (allCb && actionMode !== 'multi') {
            allCb.checked = allBoxes.length > 0 && selectedIds.size === allBoxes.length;
            allCb.indeterminate = selectedIds.size > 0 && selectedIds.size < allBoxes.length;
        }
        updateActionUI();
    }

    function toggleMultiRowSelect(multiId, checked) {
        if (checked) selectedMultiIds.add(multiId); else selectedMultiIds.delete(multiId);
        updateActionUI();
    }

    function toggleSelectAllRows(checked) {
        if (actionMode === 'multi') return;
        document.querySelectorAll('.row-select').forEach(cb => {
            cb.checked = checked;
            const id = parseInt(cb.dataset.id, 10);
            if (checked) selectedIds.add(id); else selectedIds.delete(id);
        });
        updateActionUI();
    }

    function flashActionInfo(msg) {
        const info = document.getElementById('bulk-select-info');
        if (!info) return;
        clearTimeout(_flashTimer);
        info.textContent = msg;
        info.style.color = 'var(--red)';
        _flashTimer = setTimeout(() => { info.style.color = ''; updateActionUI(); }, 1800);
    }

    function updateActionUI() {
        const info = document.getElementById('bulk-select-info');
        const trashBtn = document.getElementById('btn-trash');
        const multiBtn = document.getElementById('btn-multi');
        if (!selectMode) {
            trashBtn.classList.remove('armed');
            trashBtn.title = 'Delete summits';
            multiBtn.classList.remove('armed');
            multiBtn.title = 'Plan a multi-summit activation';
            info.style.display = 'none';
            return;
        }
        info.style.display = '';
        info.style.color = '';
        if (actionMode === 'trash') {
            const total = selectedIds.size + selectedMultiIds.size;
            if (total > 0) {
                trashBtn.classList.add('armed');
                trashBtn.title = 'Delete ' + total + ' selected item' + (total !== 1 ? 's' : '');
                info.textContent = total + ' selected';
            } else {
                trashBtn.classList.remove('armed');
                trashBtn.title = 'Select summits or routes to delete';
                info.textContent = 'Select summits or routes to delete';
            }
        } else if (actionMode === 'multi') {
            const editPrefix = multiEditId ? ('Editing "' + (MULTI_EDIT_NAME || 'route') + '" — ') : '';
            if (selectedIds.size >= 2) {
                multiBtn.classList.add('armed');
                multiBtn.title = multiEditId ? 'Update this route' : ('Plan a route for ' + selectedIds.size + ' summits');
                info.textContent = editPrefix + selectedIds.size + ' selected (up to ' + MULTI_MAX + ')';
            } else {
                multiBtn.classList.remove('armed');
                multiBtn.title = 'Select at least 2 summits';
                info.textContent = editPrefix + 'Select at least 2 summits (up to ' + MULTI_MAX + ')';
            }
        }
    }

    function handleRowClick(event, summitId, groupId) {
        if (selectMode) {
            event.stopPropagation();
            const cb = document.querySelector('.row-select[data-id="' + summitId + '"]');
            if (cb) {
                cb.checked = !cb.checked;
                toggleRowSelect(summitId, cb.checked);
            }
            return;
        }
        window.location = 'summit_detail.php?id=' + summitId + '&group=' + groupId;
    }

    function toggleMultiNested(multiId) {
        const rows = document.querySelectorAll('.multi-nested-row[data-multi-group="' + multiId + '"]');
        if (!rows.length) return;
        const nowOpen = !rows[0].classList.contains('is-open');
        rows.forEach(function(r) { r.classList.toggle('is-open', nowOpen); });
        const btn = document.getElementById('multi-toggle-' + multiId);
        if (btn) btn.classList.toggle('open', nowOpen);
        fetch('toggle_multi_expand.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'multi_id=' + encodeURIComponent(multiId) + '&expanded=' + (nowOpen ? '1' : '0')
        }).catch(function() {});
    }

    function performBulkDelete() {
        const ids = Array.from(selectedIds);
        const multiIds = Array.from(selectedMultiIds);
        if (ids.length === 0 && multiIds.length === 0) return;
        const parts = [];
        if (ids.length) parts.push(ids.length + ' summit' + (ids.length !== 1 ? 's' : ''));
        if (multiIds.length) parts.push(multiIds.length + ' multi-activation route' + (multiIds.length !== 1 ? 's' : ''));
        if (!confirm('Delete ' + parts.join(' and ') + '? This cannot be undone.')) return;
        const btn = document.getElementById('btn-trash');
        btn.disabled = true;
        fetch('bulk_delete_summits.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ids=' + encodeURIComponent(ids.join(',')) + '&multi_ids=' + encodeURIComponent(multiIds.join(','))
        }).then(r => r.json()).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.error || 'Failed to delete. Please try again.');
                btn.disabled = false;
            }
        }).catch(() => {
            alert('Failed to delete. Please try again.');
            btn.disabled = false;
        });
    }

    // Arriving from "+ Add Summit" on the multi-activation page — jump straight
    // into multi-select mode with the route's current members pre-included.
    if (MULTI_PREFILL_IDS.length > 0) {
        enterSelectMode('multi');
        MULTI_PREFILL_IDS.forEach(function(id) {
            selectedIds.add(id);
            const cb = document.querySelector('.row-select[data-id="' + id + '"]');
            if (cb) cb.checked = true;
        });
        updateActionUI();
    }

    // User chip dropdown
    (function() {
        const chip = document.getElementById('userChip');
        if (!chip) return;
        chip.addEventListener('click', function(e) { e.stopPropagation(); this.classList.toggle('open'); });
        document.addEventListener('click', function() { chip.classList.remove('open'); });
    })();

    // Mobile hamburger nav menu
    (function() {
        const menu = document.getElementById('hamburgerMenu');
        if (!menu) return;
        const btn = menu.querySelector('.hamburger-btn');
        btn.addEventListener('click', function(e) { e.stopPropagation(); menu.classList.toggle('open'); });
        document.addEventListener('click', function() { menu.classList.remove('open'); });
    })();
</script>

<script>
// Auto-launch tour if the user checked "show again next time"
if (!new URLSearchParams(window.location.search).has('tour') &&
    localStorage.getItem('sota_tour_pending') === '1') {
    localStorage.removeItem('sota_tour_pending');
    var u = new URL(window.location);
    u.searchParams.set('tour', '1');
    window.location.replace(u.toString());
}
</script>

<?php if (isset($_GET['tour']) && $_GET['tour'] === '1'): ?>
<!-- ═══ NEW USER TOUR ═══ -->
<style>
#tour-svg {
    position: fixed; inset: 0; z-index: 900;
    width: 100vw; height: 100vh;
    pointer-events: none;
}
#tour-card {
    position: fixed; z-index: 910;
    width: min(380px, calc(100vw - 32px));
    background: var(--surface);
    border-radius: var(--r-xl);
    box-shadow: 0 16px 48px rgba(28,27,25,0.28), 0 4px 16px rgba(28,27,25,0.14);
    padding: 1.5rem;
    opacity: 0;
    transition: opacity 0.18s ease;
}
#tour-card.visible { opacity: 1; }
.tdots { display: flex; align-items: center; gap: 5px; margin-bottom: 1rem; }
.tdot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--border-2); transition: all 0.25s;
}
.tdot.active { background: var(--ink); width: 22px; border-radius: 4px; }
.tdot.done   { background: var(--accent); }
#tour-title {
    font-size: 1.05rem; font-weight: 600; color: var(--ink);
    margin-bottom: 0.5rem; line-height: 1.3;
}
#tour-body {
    font-size: 0.875rem; color: var(--ink-2); line-height: 1.65;
    margin-bottom: 1.25rem;
}
.tour-actions {
    display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
}
.tbtn {
    display: inline-flex; align-items: center;
    padding: 0 0.875rem; height: 34px;
    border-radius: var(--r-md); font-family: var(--font-sans);
    font-size: 0.82rem; font-weight: 500; cursor: pointer;
    border: none; line-height: 1; transition: background 0.12s;
}
.tbtn-primary { background: var(--ink); color: #fff; }
.tbtn-primary:hover { background: var(--ink-2); }
.tbtn-ghost { background: var(--bg-2); color: var(--ink-2); border: 1px solid var(--border); }
.tbtn-ghost:hover { background: var(--bg-3); }
.tbtn-skip {
    background: none; border: none; color: var(--ink-4);
    font-size: 0.8rem; cursor: pointer; font-family: var(--font-sans);
    text-decoration: underline; text-underline-offset: 2px; padding: 0;
}
.tbtn-skip:hover { color: var(--ink-2); }
</style>

<svg id="tour-svg" xmlns="http://www.w3.org/2000/svg">
    <defs>
        <mask id="tour-mask">
            <rect width="100%" height="100%" fill="white"/>
            <rect id="tour-hole" rx="10" ry="10" fill="black" x="-9999" y="-9999" width="1" height="1"/>
        </mask>
    </defs>
    <rect width="100%" height="100%" fill="rgba(28,27,25,0.78)" mask="url(#tour-mask)"/>
    <rect id="tour-ring" rx="12" ry="12" fill="none"
          stroke="rgba(255,255,255,0.4)" stroke-width="2"
          x="-9999" y="-9999" width="1" height="1"/>
</svg>

<div id="tour-card">
    <div class="tdots" id="tdots"></div>
    <div id="tour-title"></div>
    <p id="tour-body"></p>
    <label id="tour-show-again-wrap" style="display:none;align-items:center;gap:0.5rem;font-size:0.82rem;color:var(--ink-3);margin-bottom:1rem;cursor:pointer;line-height:1.4;">
        <input type="checkbox" id="tour-show-again" style="cursor:pointer;flex-shrink:0;">
        Show this tour again next time I visit
    </label>
    <div class="tour-actions">
        <button class="tbtn tbtn-skip" id="tour-skip" onclick="tourSkip()">Skip — remind me next time</button>
        <div style="display:flex;gap:0.4rem">
            <button class="tbtn tbtn-ghost" id="tour-back" onclick="tourBack()">← Back</button>
            <button class="tbtn tbtn-primary" id="tour-next" onclick="tourNext()">Next →</button>
        </div>
    </div>
</div>

<script>
(function() {
var STEPS = [
    {
        sel: null,
        title: "Welcome to your dashboard!",
        body:  "Your dashboard is all set up. Let me show you the key parts so you can hit the ground running.",
    },
    {
        sel: '.topbar-context',
        title: "Your active dashboard",
        body:  "Your active dashboard and starting address live here. Switch dashboards or addresses anytime — travel times and totals update automatically.",
    },
    {
        sel: '.topbar-nav a[href="planning_groups.php"]',
        title: "Manage your dashboards",
        body:  "Manage Dashboards is where you add friends as co-activators, set up additional dashboards, and manage your starting addresses. Everything's editable anytime.",
    },
    {
        sel: 'a[href="nominate.php"].btn',
        title: "Add your first summit",
        body:  "Click '+ Add Summits' and enter a SOTA reference code (like W7O/NC-001). SOTA Planner pulls the name, elevation, and coordinates automatically.",
    },
    {
        sel: '.toolbar',
        title: "Filter and time your trips",
        body:  "Filter by difficulty or research status. Adjust the Activation time field to instantly recalculate the total door-to-door estimate for every summit in your list.",
    },
    {
        sel: null,
        title: "You're all set!",
        body:  "Click any summit row to open its full detail page — interactive map, elevation chart, GPX upload, and planning tools. Manage your dashboard anytime from Manage Dashboards in the nav.",
        final: true,
    },
];

var step = 0;

function dots() {
    var el = document.getElementById('tdots');
    el.innerHTML = '';
    STEPS.forEach(function(_, i) {
        var d = document.createElement('div');
        d.className = 'tdot' + (i < step ? ' done' : i === step ? ' active' : '');
        el.appendChild(d);
    });
}

function spotlight(rect, pad) {
    var p = pad || 10;
    var hole = document.getElementById('tour-hole');
    var ring = document.getElementById('tour-ring');
    if (!rect) {
        ['x','y','width','height'].forEach(function(a,i) {
            hole.setAttribute(a, i < 2 ? '-9999' : '1');
            ring.setAttribute(a, i < 2 ? '-9999' : '1');
        });
        return;
    }
    hole.setAttribute('x',      rect.left - p);
    hole.setAttribute('y',      rect.top  - p);
    hole.setAttribute('width',  rect.width  + p * 2);
    hole.setAttribute('height', rect.height + p * 2);
    ring.setAttribute('x',      rect.left - p - 2);
    ring.setAttribute('y',      rect.top  - p - 2);
    ring.setAttribute('width',  rect.width  + p * 2 + 4);
    ring.setAttribute('height', rect.height + p * 2 + 4);
}

function placeCard(rect) {
    var card = document.getElementById('tour-card');
    var vw = window.innerWidth, vh = window.innerHeight;
    var cw = card.offsetWidth, ch = card.offsetHeight;
    var top, left;
    if (!rect) {
        top  = Math.max(16, (vh - ch) / 2);
        left = Math.max(16, (vw - cw) / 2);
    } else {
        var pad = 10, gap = 16;
        var mid = (rect.top + rect.bottom) / 2;
        if (mid < vh * 0.55) {
            top = rect.bottom + pad + gap;
            if (top + ch > vh - 16) top = vh - ch - 16;
        } else {
            top = rect.top - pad - ch - gap;
            if (top < 16) top = 16;
        }
        left = rect.left + rect.width / 2 - cw / 2;
        left = Math.max(16, Math.min(left, vw - cw - 16));
    }
    card.style.top  = top  + 'px';
    card.style.left = left + 'px';
}

function show(i) {
    var s = STEPS[i];
    document.getElementById('tour-title').textContent = s.title;
    document.getElementById('tour-body').textContent  = s.body;
    dots();

    var backBtn = document.getElementById('tour-back');
    var nextBtn = document.getElementById('tour-next');
    var skipBtn = document.getElementById('tour-skip');

    backBtn.style.display = (i === 0) ? 'none' : '';
    skipBtn.style.display = s.final  ? 'none' : '';
    nextBtn.textContent = s.final ? 'Start planning →' : (i === STEPS.length - 2 ? 'Finish →' : 'Next →');

    var showAgainWrap = document.getElementById('tour-show-again-wrap');
    showAgainWrap.style.display = s.final ? 'flex' : 'none';

    var card = document.getElementById('tour-card');
    card.classList.remove('visible');

    if (s.sel) {
        var el = document.querySelector(s.sel);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            setTimeout(function() {
                var rect = el.getBoundingClientRect();
                spotlight(rect);
                placeCard(rect);
                card.classList.add('visible');
            }, 280);
        } else {
            spotlight(null);
            placeCard(null);
            card.classList.add('visible');
        }
    } else {
        spotlight(null);
        placeCard(null);
        card.classList.add('visible');
    }
}

window.tourNext = function() {
    if (step < STEPS.length - 1) { step++; show(step); }
    else tourEnd();
};
window.tourBack = function() {
    if (step > 0) { step--; show(step); }
};
function hideTour() {
    document.getElementById('tour-svg').style.display  = 'none';
    document.getElementById('tour-card').style.display = 'none';
    var url = new URL(window.location);
    url.searchParams.delete('tour');
    history.replaceState({}, '', url.toString());
}
window.tourSkip = function() {
    localStorage.setItem('sota_tour_pending', '1');
    hideTour();
};
window.tourEnd = function() {
    var cb = document.getElementById('tour-show-again');
    if (cb && cb.checked) {
        localStorage.setItem('sota_tour_pending', '1');
    } else {
        localStorage.removeItem('sota_tour_pending');
    }
    hideTour();
};

window.addEventListener('resize', function() {
    var s = STEPS[step];
    if (s.sel) {
        var el = document.querySelector(s.sel);
        if (el) { var r = el.getBoundingClientRect(); spotlight(r); placeCard(r); }
    } else { spotlight(null); placeCard(null); }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape')      tourEnd();
    if (e.key === 'ArrowRight')  tourNext();
    if (e.key === 'ArrowLeft')   tourBack();
});

window.addEventListener('load', function() { show(0); });
})();
</script>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Dashboard map view ────────────────────────────────────────────────────
const DASH_SUMMITS = <?= $map_json ?>;

let dashMap = null;
let dashBaseLayers = null;
let activeDashBase = 'light';
try { activeDashBase = localStorage.getItem('sota_dash_base') || 'light'; } catch(e) {}

const SOTA_POINTS_COLORS = { 0: '#a0a0a0', 1: '#4d7a20', 2: '#6da536', 4: '#aea727', 6: '#efa818', 8: '#dc5d04', 10: '#c8101e' };
function pointsColor(points) {
    return SOTA_POINTS_COLORS[points] || '#a0a0a0';
}

function initDashMap() {
    const container = document.getElementById('dash-map');
    if (!container) return;

    dashMap = L.map('dash-map', { zoomControl: true });

    dashBaseLayers = {
        light: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19
        }),
        topo: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            attribution: '© <a href="https://opentopomap.org">OpenTopoMap</a>',
            maxZoom: 17
        }),
        satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '© Esri',
            maxZoom: 19
        })
    };
    dashBaseLayers[activeDashBase].addTo(dashMap);
    // "Map" reuses the OSM tiles above with a muted CSS filter (see #dash-map CSS)
    // instead of a separate tile CDN, so the filter only applies while "Map" is active.
    dashMap.getContainer().classList.toggle('sota-muted-tiles', activeDashBase === 'light');

    const bounds = [];

    DASH_SUMMITS.forEach(function(s) {
        // GPX polyline (green)
        if (s.path && s.path.length > 1) {
            L.polyline(s.path, {
                color: '#2d7a4f',
                weight: 3,
                opacity: 0.85,
                lineJoin: 'round',
                lineCap: 'round'
            }).addTo(dashMap);
            s.path.forEach(function(pt) { bounds.push(pt); });
        } else {
            bounds.push([s.lat, s.lng]);
        }

        // Badge label
        const label = s.label || '+ Research';
        const cls = s.label ? 'lmap-' + s.badge : 'lmap-warn';

        const icon = L.divIcon({
            className: '',
            html: '<div class="lmap-badge ' + cls + '">' + label + '</div>',
            iconSize: null,
            iconAnchor: [0, 0]
        });

        const marker = L.marker([s.lat, s.lng], {
            icon: icon,
            opacity: s.badge === 'gray' ? 0.45 : 1
        }).addTo(dashMap);

        const tipPoints = '<div class="dash-tip-points" style="background:' + pointsColor(s.points) + '">' + s.points + '</div>';

        const tipTime = s.label
            ? '<div class="dash-tip-time">' + s.label + ' total</div>'
            : '<div class="dash-tip-time" style="color:var(--ink-3)">Add research for time estimate</div>';

        const breakdownParts = [];
        breakdownParts.push('<span>Travel ' + (s.drive || '—') + '</span>');
        breakdownParts.push('<span>Hike ' + (s.hike || '—') + '</span>');
        const tipBreakdown = '<div class="dash-tip-breakdown">' + breakdownParts.join('<span class="dash-tip-dot">·</span>') + '</div>';

        marker.bindTooltip(
            tipPoints +
            '<div class="dash-tip-ref">' + s.ref + '</div>' +
            '<div class="dash-tip-name">' + s.name + '</div>' +
            tipTime +
            tipBreakdown,
            { direction: 'top', offset: [0, -6], className: 'dash-tip', sticky: false }
        );

        marker.on('click', function() {
            window.location = s.url;
        });
    });

    // Fit bounds
    if (bounds.length > 0) {
        dashMap.fitBounds(bounds, { padding: [32, 32] });
        if (bounds.length === 1) dashMap.setZoom(12);
    } else {
        dashMap.setView([45, -110], 5);
    }

    // "Find summits near here" control button
    const findControl = L.control({ position: 'bottomleft' });
    findControl.onAdd = function() {
        const btn = L.DomUtil.create('button', 'dash-find-btn');
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 13 13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><circle cx="5.5" cy="5.5" r="4"/><line x1="9" y1="9" x2="12" y2="12"/></svg> Find new summits near here';
        L.DomEvent.disableClickPropagation(btn);
        L.DomEvent.on(btn, 'click', function() {
            const c = dashMap.getCenter();
            window.location.href = 'nominate.php?tab=area&lat=' + c.lat.toFixed(5) + '&lng=' + c.lng.toFixed(5);
        });
        return btn;
    };
    findControl.addTo(dashMap);

    // Base map type toggle (Map / Topo / Satellite)
    const baseToggleControl = L.control({ position: 'topright' });
    baseToggleControl.onAdd = function() {
        const wrap = L.DomUtil.create('div', 'dash-base-toggle');
        [['light', 'Map'], ['topo', 'Topo'], ['satellite', 'Satellite']].forEach(function(pair) {
            const btn = L.DomUtil.create('button', 'dash-base-btn' + (pair[0] === activeDashBase ? ' active' : ''), wrap);
            btn.type = 'button';
            btn.textContent = pair[1];
            btn.dataset.base = pair[0];
            L.DomEvent.on(btn, 'click', function() { switchDashBase(pair[0]); });
        });
        L.DomEvent.disableClickPropagation(wrap);
        return wrap;
    };
    baseToggleControl.addTo(dashMap);

    // Hide loading overlay once tiles start appearing
    dashMap.once('load', removeDashLoading);
    setTimeout(removeDashLoading, 1800); // fallback
}

function switchDashBase(name) {
    if (!dashMap || !dashBaseLayers || name === activeDashBase) return;
    dashMap.removeLayer(dashBaseLayers[activeDashBase]);
    dashBaseLayers[name].addTo(dashMap);
    activeDashBase = name;
    dashMap.getContainer().classList.toggle('sota-muted-tiles', name === 'light');
    try { localStorage.setItem('sota_dash_base', name); } catch(e) {}
    document.querySelectorAll('.dash-base-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.base === name);
    });
}

function removeDashLoading() {
    const overlay = document.getElementById('map-loading-overlay');
    if (overlay) overlay.style.display = 'none';
}

function setDashView(view) {
    const listEl = document.getElementById('list-view');
    const mapEl  = document.getElementById('map-view');
    const btnL   = document.getElementById('btn-list-view');
    const btnM   = document.getElementById('btn-map-view');

    if (view === 'map') {
        listEl.style.display = 'none';
        mapEl.style.display  = 'block';
        btnL.classList.remove('active');
        btnM.classList.add('active');

        if (!dashMap) {
            initDashMap();
        } else {
            setTimeout(function() { dashMap.invalidateSize(); }, 50);
        }
        try { localStorage.setItem('sota_dash_view', 'map'); } catch(e) {}
    } else {
        mapEl.style.display  = 'none';
        listEl.style.display = 'block';
        btnL.classList.add('active');
        btnM.classList.remove('active');
        try { localStorage.setItem('sota_dash_view', 'list'); } catch(e) {}
    }
}

// Restore last-used view — but a brand-new, empty dashboard always opens to List;
// there's nothing useful to show on the map yet.
var DASH_HAS_SUMMITS = <?= (count($summits) > 0 || !$is_all) ? 'true' : 'false' ?>;
try {
    if (DASH_HAS_SUMMITS && localStorage.getItem('sota_dash_view') === 'map') {
        setDashView('map');
    }
} catch(e) {}
</script>
<script>
// Silently refresh SOTA activation cache for all summits on the dashboard.
// Fires after page paint, 3 at a time, so it never blocks the UI.
// The server caches each summit's SOTA API result for 24h (see fetchSotaActivations()
// in config.php), so hitting sota_refresh.php again is cheap — but index.php sends
// Cache-Control: no-store (PHP's session default), which blocks the browser's
// back/forward cache. That means every back-button return re-runs this whole
// script from scratch. Track which summit IDs we've already refreshed today in
// sessionStorage (per tab) and only fetch the ones not already covered, so a
// same-day revisit (back button, reload, re-opened tab) doesn't re-fire a
// request per summit just to hit the cache again — while a newly nominated
// summit added later the same day still gets checked.
(function() {
  const groupId = <?= (int)$current_group['id'] ?>;
  const allIds = Array.from(document.querySelectorAll('tr[data-summit-id]'))
                      .map(r => r.dataset.summitId);
  if (!allIds.length) return;

  const today = new Date().toISOString().slice(0, 10);
  const storageKey = 'sota_refresh_done_' + groupId;
  let alreadyDone = [];
  try {
    const stored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    if (stored && stored.date === today) alreadyDone = stored.ids || [];
  } catch (e) {}

  const doneSet = new Set(alreadyDone);
  const ids = allIds.filter(id => !doneSet.has(id));
  if (!ids.length) return;

  try {
    sessionStorage.setItem(storageKey, JSON.stringify({ date: today, ids: allIds }));
  } catch (e) {}

  let i = 0;
  function next() {
    if (i >= ids.length) return;
    const id = ids[i++];
    fetch('sota_refresh.php?summit_id=' + id + '&group_id=' + groupId)
      .then(r => r.json())
      .catch(() => {})
      .finally(() => setTimeout(next, 200));
  }
  // Start 3 concurrent workers after page is idle
  requestIdleCallback ? requestIdleCallback(() => { next(); next(); next(); })
                      : setTimeout(() => { next(); next(); next(); }, 1500);
})();
</script>

<script>
// Highlight newly-batch-nominated rows, then fade back to normal.
(function() {
  const params = new URLSearchParams(window.location.search);
  const raw = params.get('new_ids');
  if (!raw) return;
  const ids = new Set(raw.split(',').map(s => s.trim()).filter(Boolean));
  if (!ids.size) return;

  // Scroll to first matched row and apply highlight class
  let firstRow = null;
  ids.forEach(id => {
    const row = document.querySelector('tr[data-summit-id="' + id + '"]');
    if (!row) return;
    row.classList.add('row-new-highlight');
    if (!firstRow) firstRow = row;
  });
  if (firstRow) {
    // Small delay so the page has settled before scrolling
    setTimeout(() => firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' }), 200);
  }

  // Clean up URL so refreshing doesn't re-trigger the highlight
  const clean = new URL(window.location.href);
  clean.searchParams.delete('new_ids');
  window.history.replaceState({}, '', clean.toString());
})();
</script>

<?php if ($should_sync_activations): ?>
<script>
// Background sync: check the SOTA API for any "ready" summit this dashboard's
// team has actually already activated, and fade the row to activated in place.
(function() {
  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  fetch('sync_dashboard_activations.php', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
      (data.updated || []).forEach(u => {
        const row = document.querySelector('tr[data-summit-id="' + u.id + '"]');
        if (!row) return;

        row.style.transition = 'background-color 0.8s ease, opacity 0.8s ease';
        row.classList.remove('row-ready');
        row.classList.add('row-activated');

        const badgeCell = row.querySelector('.td-status');
        if (badgeCell) {
          badgeCell.innerHTML = '<span class="badge badge-activated">Activated ' + u.year + '</span>';
        }

        const lastActCell = row.querySelector('.td-last-activated');
        if (lastActCell) {
          lastActCell.innerHTML = '<div class="stat-val">' + escapeHtml(u.last_activated_display) + '</div>' +
            (u.activated_by ? '<div class="last-act-callsign">' + escapeHtml(u.activated_by) + '</div>' : '');
        }
      });
    })
    .catch(() => {}); // silent — this is a best-effort background refresh
})();
</script>
<?php endif; ?>
</body>
</html>
