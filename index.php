<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();

$message = '';

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
    $message = "Planning group switched!";
}

// Handle set default group
if (isset($_POST['set_default_group'])) {
    $group_id = (int)$_POST['default_group_id'];
    setcookie('sota_default_group', $group_id, time() + 60 * 60 * 24 * 365, '/');
    $_COOKIE['sota_default_group'] = $group_id;
    setCurrentPlanningGroup($group_id);
    $message = "⭐ Default group saved — this group will load automatically next time.";
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
        $message = "Please select a planning group first";
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
                    $message = "Drive times updated for $updated summit(s) in {$current_group['name']}!";
                    if ($failed > 0) {
                        $message .= " ($failed failed - check API key)";
                    }
                } else {
                    $message = "Failed to calculate drive times. Check your Google Maps API key and quota.";
                }
            }
        }
    }
}

// Get selected address (will be null if no group selected)
$current_group = getCurrentPlanningGroup($db);
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

// Get activation time from request or use default
$activation_time = $_GET['activation_time'] ?? 60;

// Get sort parameters
$sort_by = $_GET['sort'] ?? 'nominated_date';
$sort_order = $_GET['order'] ?? 'DESC';

// Get filter parameter (comma-separated for multiple filters)
$filter_param = $_GET['filter'] ?? 'all';

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

$stmt = $db->prepare("
    SELECT s.*,
           g.hiking_time as gpx_hiking_time,
           g.elevation_gain as gpx_elevation_gain,
           g.elevation_loss as gpx_elevation_loss,
           g.total_distance as gpx_total_distance,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
            --light-blue: #5BA4B8;
            --gold: #E6B84A;
            --tan: #D4A574;
            --snow: #F5F5F0;
            /* Aliases for compatibility */
            --peak-brown: #1E3A5F;
            --trail-green: #4A90A4;
            --sky-blue: #5BA4B8;
            --earth-tan: #D4A574;
            --forest-dark: #1E3A5F;
            --summit-gold: #E6B84A;
            --rock-gray: #8A8D91;
            --snow-white: #F5F5F0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, #F5F5F0 0%, #E8E4D8 100%);
            color: var(--forest-dark);
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
        }

        header {
            background: linear-gradient(135deg, var(--peak-brown) 0%, var(--forest-dark) 100%);
            color: var(--snow-white);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 2rem -2rem;
            border-bottom: 4px solid var(--summit-gold);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.15rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            line-height: 1;
        }

        .subtitle {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 300;
        }

        .address-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .address-selector select {
            padding: 0.4rem 0.8rem;
            border: 2px solid var(--summit-gold);
            border-radius: 6px;
            background: white;
            color: var(--forest-dark);
            font-family: 'Overpass', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .address-selector button {
            padding: 0.4rem 0.8rem;
            background: var(--summit-gold);
            color: var(--forest-dark);
            border: none;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
        }

        .address-selector button:hover {
            background: white;
        }

        .address-selector a {
            color: var(--summit-gold);
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.3s;
        }

        .address-selector a:hover {
            opacity: 0.8;
        }

        .controls {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            background: white;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        }

        .controls-row {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .control-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        label {
            font-weight: 600;
            color: var(--forest-dark);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        select, input[type="number"] {
            padding: 0.6rem 1rem;
            border: 2px solid var(--earth-tan);
            border-radius: 6px;
            font-family: 'Overpass', sans-serif;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
        }

        select:focus, input[type="number"]:focus {
            outline: none;
            border-color: var(--trail-green);
            box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
        }

        .btn {
            padding: 0.7rem 1.5rem;
            background: linear-gradient(135deg, var(--trail-green) 0%, var(--forest-dark) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.9rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .message {
            padding: 1rem;
            background: #E6F4EA;
            color: #1E7E34;
            border-left: 4px solid #1E7E34;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, var(--peak-brown) 0%, var(--forest-dark) 100%);
            color: white;
        }

        th {
            padding: 0.7rem 0.5rem;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            cursor: pointer;
            user-select: none;
            transition: background 0.3s;
            white-space: nowrap;
        }

        th:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        th.sortable::after {
            content: ' ↕';
            opacity: 0.5;
        }

        th.sorted-asc::after {
            content: ' ↑';
            opacity: 1;
        }

        th.sorted-desc::after {
            content: ' ↓';
            opacity: 1;
        }

        tbody tr {
            border-bottom: 1px solid #e8e4d8;
            transition: background 0.3s;
        }

        tbody tr:hover {
            background: var(--snow-white);
        }

        tbody tr.status-ready {
            background: #E6F4EA;
        }

        td {
            padding: 0.6rem 0.5rem;
            font-size: 0.85rem;
        }

        .summit-name {
            font-weight: 700;
            color: var(--peak-brown);
            cursor: pointer;
            transition: color 0.3s;
        }

        .summit-name:hover {
            color: var(--trail-green);
        }

        .summit-ref {
            font-family: 'Courier Prime', monospace;
            color: var(--rock-gray);
            font-size: 0.85rem;
        }

        .difficulty-badge {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .difficulty-easy {
            background: #E6F4EA;
            color: #1E7E34;
        }

        .difficulty-moderate {
            background: #FFF4E6;
            color: #996A13;
        }

        .difficulty-hard {
            background: #FDECEA;
            color: #C62828;
        }

        .difficulty-drive-up {
            background: #E8F4F8;
            color: #2C5F7A;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ===========================
           MOBILE RESPONSIVE STYLES
           =========================== */

        @media (max-width: 768px) {
            body { padding: 0; }
            .container { padding: 0.75rem; }

            header {
                flex-direction: column;
                align-items: flex-start;
                /* match container padding so header bleeds edge-to-edge without clipping */
                padding: 1rem 0.75rem;
                margin: -0.75rem -0.75rem 1rem -0.75rem;
                gap: 0.6rem;
            }

            header img { height: 56px !important; }
            h1 { font-size: 1.5rem; }

            .subtitle {
                flex-direction: column;
                gap: 0.6rem;
                align-items: stretch;
                width: 100%;
            }

            .address-selector { width: 100%; }
            .address-selector select { width: 100%; font-size: 0.9rem; }

            /* Action buttons row: stack below filters, full width */
            .controls-row .action-btns {
                width: 100%;
                display: flex;
                gap: 0.5rem;
            }
            .controls-row .action-btns form,
            .controls-row .action-btns a { flex: 1; }
            .controls-row .action-btns .btn {
                width: 100%;
                text-align: center;
                padding: 0.55rem 0.5rem;
                font-size: 0.78rem;
            }

            /* ── Summit list: table → cards ── */
            .table-container {
                overflow-x: visible;
                background: transparent;
                box-shadow: none;
                border-radius: 0;
            }
            table { display: block; min-width: 0; width: 100%; }
            thead { display: none; }
            tbody { display: flex; flex-direction: column; gap: 0.55rem; }

            tbody tr {
                display: grid !important;
                grid-template-columns: 1fr auto;
                background: white;
                border-radius: 10px;
                padding: 0.75rem;
                border: 1px solid #e8e4d8 !important;
                border-bottom: 1px solid #e8e4d8 !important;
                box-shadow: 0 1px 4px rgba(0,0,0,0.07);
                column-gap: 0.5rem;
                row-gap: 0.15rem;
                cursor: pointer;
            }
            tbody tr:hover { background: white; }
            tbody tr.ready-to-activate {
                background: #E8F5E9 !important;
                border-color: #c8e6c9 !important;
            }
            tbody tr.activated-this-year {
                background: #f5f5f5 !important;
                border-color: #e0e0e0 !important;
                opacity: 0.65;
            }

            td.td-summit     { grid-column: 1; grid-row: 1; }
            td.td-total-time {
                grid-column: 2; grid-row: 1;
                text-align: right; align-self: center;
                font-size: 1.05rem; font-weight: 800;
                color: var(--navy); white-space: nowrap;
            }
            td.td-total-time strong { font-size: inherit; }
            td.td-difficulty { grid-column: 1; grid-row: 2; align-self: center; }
            td.td-status     { grid-column: 2; grid-row: 2; text-align: right; align-self: center; }

            /* Stats strip from data attribute */
            tbody tr:not([data-stats=""])::after {
                content: attr(data-stats);
                grid-column: 1 / -1;
                grid-row: 3;
                font-size: 0.72rem;
                color: #888;
                padding-top: 0.4rem;
                border-top: 1px solid rgba(0,0,0,0.07);
                margin-top: 0.1rem;
                line-height: 1.6;
            }

            /* Columns hidden in card view */
            td.td-elevation, td.td-points,
            td.td-hike-time, td.td-drive-time,
            td.td-distance,  td.td-gain,
            td.td-last-activated { display: none; }

            .summit-name { font-size: 0.95rem; }
            .summit-ref  { font-size: 0.72rem; }
            .difficulty-badge { font-size: 0.7rem; padding: 0.2rem 0.5rem; }
            .status-badge { font-size: 0.65rem; padding: 0.25rem 0.6rem; }
        }

        /* Extra small screens (iPhone SE, etc) */
        @media (max-width: 375px) {
            .container { padding: 0.5rem; }
            header {
                padding: 0.75rem 0.5rem;
                margin: -0.5rem -0.5rem 0.75rem -0.5rem;
            }
            h1 { font-size: 1.25rem; }
            header img { height: 48px !important; }
        }

        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-nominated {
            background: #E8F4F8;
            color: #2C5F7A;
        }

        .status-researched {
            background: #FFF4E6;
            color: #996A13;
        }

        .status-ready {
            background: #E6F4EA;
            color: #1E7E34;
        }

        .status-activated-this-year {
            background: #EEEEEE;
            color: #757575;
        }

        .view-link {
            color: var(--trail-green);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .view-link:hover {
            color: var(--forest-dark);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .empty-state h2 {
            font-size: 2rem;
            color: var(--rock-gray);
            margin-bottom: 1rem;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .header-link {
            display: inline-flex;
            align-items: center;
            padding: 0.6rem 1.1rem;
            background: rgba(255, 255, 255, 0.25);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            line-height: 1;
            transition: all 0.3s ease;
            white-space: nowrap;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            min-height: 40px;
        }
        
        .header-link:hover {
            background: rgba(255, 255, 255, 0.35);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .filter-btn {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background: #f8f8f8;
            color: #555;
            text-decoration: none;
            border-radius: 3px;
            font-weight: 400;
            font-size: 0.75rem;
            transition: all 0.15s;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            font-family: inherit;
            white-space: nowrap;
        }
        
        .filter-btn:hover {
            background: #efefef;
            border-color: #ccc;
        }
        
        .filter-btn.active {
            background: var(--teal);
            color: white;
            border-color: var(--teal);
            font-weight: 500;
        }
        
        .filter-btn-action {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background: var(--navy);
            color: white;
            text-decoration: none;
            border-radius: 3px;
            font-weight: 500;
            font-size: 0.75rem;
            transition: all 0.15s;
            border: 1px solid var(--navy);
            cursor: pointer;
            white-space: nowrap;
        }
        
        .filter-btn-action:hover {
            background: var(--teal);
            border-color: var(--teal);
        }

        
        @media (max-width: 1200px) {
            .filter-btn, .filter-btn-action {
                padding: 0.2rem 0.4rem;
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <img src="logo.png" alt="SOTA Planner" style="height: 80px; margin-right: 1rem;">
            <div style="flex: 1;">
                <h1>SOTA Planner</h1>
                <div style="font-size: 0.8rem; opacity: 0.72; margin-top: 0.25rem; margin-bottom: 0.5rem; letter-spacing: 0.03em; font-weight: 300;">Doorstep to doorstep planning tool for busy activators</div>
                <div class="subtitle">
                    <form method="POST" class="address-selector">
                        <span>👥 Group:</span>
                        <select name="planning_group_id" onchange="this.form.submit()">
                            <?php foreach ($all_groups as $group): ?>
                                <option value="<?= $group['id'] ?>" <?= ($current_group && $current_group['id'] == $group['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($group['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="select_planning_group" value="1">
                    </form>
                    <form method="POST">
                        <input type="hidden" name="set_default_group" value="1">
                        <input type="hidden" name="default_group_id" value="<?= $current_group['id'] ?>">
                        <button type="submit"
                                title="Always load this group on startup"
                                style="background:none; border:none; cursor:pointer; font-size:0.8rem; color:<?= (!empty($_COOKIE['sota_default_group']) && (int)$_COOKIE['sota_default_group'] === $current_group['id']) ? '#E6B84A' : 'rgba(255,255,255,0.45)' ?>; padding:0; line-height:1;">
                            ★
                        </button>
                    </form>

                    <?php if (count($all_addresses) > 0): ?>
                        <form method="POST" class="address-selector">
                            <span>📍 From:</span>
                            <select name="address_id" onchange="this.form.submit()">
                                <?php foreach ($all_addresses as $addr): ?>
                                    <option value="<?= $addr['id'] ?>" <?= ($selected_address && $selected_address['id'] == $addr['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($addr['label'] ?: $addr['address']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="select_address" value="1">
                        </form>
                        <?php if ($selected_address): ?>
                        <form method="POST">
                            <input type="hidden" name="set_default_address" value="1">
                            <input type="hidden" name="default_address_id" value="<?= $selected_address['id'] ?>">
                            <button type="submit"
                                    title="Always use this address on startup"
                                    style="background:none; border:none; cursor:pointer; font-size:0.8rem; color:<?= (!empty($_COOKIE[$cookie_addr_key]) && (int)$_COOKIE[$cookie_addr_key] === $selected_address['id']) ? '#E6B84A' : 'rgba(255,255,255,0.45)' ?>; padding:0; line-height:1;">
                                ★
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 1rem; background: rgba(255, 255, 255, 0.1); border-radius: 8px; border: 1px dashed rgba(255, 255, 255, 0.3);">
                            <span style="font-size: 0.9rem; opacity: 0.8;">📍 No addresses yet</span>
                            <span style="font-size: 0.85rem; opacity: 0.7;">→ Add one to calculate drive times</span>
                        </div>
                    <?php endif; ?>
                    <a href="planning_groups.php" class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; text-decoration: none;">✏️ Manage</a>
                </div>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: flex-start; flex-direction: column; text-align: right;">
                <div style="font-size: 0.78rem; color: rgba(255,255,255,0.55); font-weight: 600; letter-spacing: 0.04em;">
                    <?= htmlspecialchars(getCurrentCallsign()) ?>
                    <?php if (($_SESSION['sota_login_type'] ?? '') === 'dev'): ?>
                        <span style="background: #856404; color: #fff3cd; font-size: 0.65rem; padding: 0.05rem 0.35rem; border-radius: 3px; margin-left: 0.3rem; vertical-align: middle;">DEV</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <a href="about.php" class="header-link">About</a>
                    <a href="#" onclick="document.getElementById('howItWorksModal').style.display='flex'; return false;" class="header-link">How It Works</a>
                    <a href="logout.php" class="header-link" style="opacity: 0.65;">Sign Out</a>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
        <div style="background:white; border-left:4px solid #4A90A4; border-radius:0 6px 6px 0; padding:0.6rem 1rem; margin-bottom:0.75rem; font-size:0.88rem; color:#1E3A5F; box-shadow:0 1px 4px rgba(0,0,0,0.08); display:flex; align-items:center; justify-content:space-between; gap:1rem;">
            <span><?= htmlspecialchars($message) ?><?php if (str_contains($message, '⭐')): ?> <span style="color:#888; font-size:0.8rem;">(Saved in a browser cookie for 1 year.)</span><?php endif; ?></span>
            <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;color:#aaa;font-size:1.1rem;line-height:1;padding:0;">×</button>
        </div>
        <?php endif; ?>

        <div class="controls">
            <!-- Filter + action row -->
            <div class="controls-row">
                <span style="font-size: 0.78rem; color: #888; font-weight: 500; margin-right: 0.1rem;">Filter:</span>

                <button onclick="selectAll()" class="filter-btn-action">View All</button>

                <span style="color: #e0e0e0; font-size: 0.7rem; margin: 0 0.1rem;">|</span>

                <?php
                $filter_options = [
                    'drive-up' => 'Drive-Up',
                    'easy' => 'Easy',
                    'moderate' => 'Moderate',
                    'hard' => 'Hard',
                    'ready' => 'Ready',
                    'needs-research' => 'Research Needed',
                    'activated-this-year' => 'Activated This Year'
                ];

                foreach ($filter_options as $filter_key => $filter_label):
                    $is_active = in_array($filter_key, $active_filters);
                    $active_class = $is_active ? 'active' : '';
                ?>
                    <button onclick="toggleFilter('<?= $filter_key ?>')" class="filter-btn <?= $active_class ?>" id="filter-<?= $filter_key ?>">
                        <?= $filter_label ?>
                    </button>
                    <?php if ($filter_key === 'hard'): ?>
                        <span style="color: #e0e0e0; font-size: 0.7rem; margin: 0 0.1rem;">|</span>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Activation time — minimal inline -->
                <span style="color: #e0e0e0; font-size: 0.7rem; margin: 0 0.1rem;">|</span>
                <span style="font-size: 0.78rem; color: #888;">⏱ Planned Activation:</span>
                <input type="number" id="activation_time" value="<?= $activation_time ?>" min="15" max="300" step="15"
                       style="width: 52px; padding: 0.15rem 0.35rem; font-size: 0.78rem; border: 1px solid #ddd; border-radius: 3px; color: #555; text-align: center;">
                <span style="font-size: 0.78rem; color: #888;">min</span>

                <!-- Push right -->
                <div class="action-btns" style="margin-left: auto; display: flex; align-items: center; gap: 0.5rem;">
                    <?php if ($selected_address && GOOGLE_MAPS_API_KEY !== 'YOUR_API_KEY_HERE'): ?>
                        <form method="POST" style="margin: 0;">
                            <button type="submit" name="calculate_drive_times" class="btn" style="padding: 0.35rem 0.8rem; font-size: 0.78rem;">🚗 Recalculate Drive Times</button>
                        </form>
                    <?php endif; ?>
                    <a href="nominate.php" class="btn" style="padding: 0.35rem 0.9rem; font-size: 0.78rem;">+ Nominate Summit</a>
                </div>
            </div>

            <!-- Summit count -->
            <div style="font-size: 0.72rem; color: #bbb; line-height: 1;">
                <?= count($summits) ?> summit<?= count($summits) !== 1 ? 's' : '' ?>
                <?php if (count($active_filters) < 7): ?>
                    · <?= count($active_filters) ?> filter<?= count($active_filters) !== 1 ? 's' : '' ?> active
                <?php endif; ?>
            </div>
        </div>

        <script>
        function toggleFilter(filterKey) {
            const urlParams = new URLSearchParams(window.location.search);
            let currentFilter = urlParams.get('filter') || 'all';

            let filters;
            if (currentFilter === 'all') {
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
        </script>

        <?php if (empty($summits)): ?>
            <div class="empty-state">
                <h2>🏔️ No summits nominated yet</h2>
                <p>Start by nominating your first summit to plan an activation!</p>
                <a href="nominate.php" class="btn" style="margin-top: 1.5rem;">Nominate Your First Summit</a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th class="sortable <?= $sort_by === 'name' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('name')">Summit</th>
                            <th class="sortable <?= $sort_by === 'points' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('points')">Points</th>
                            <th class="sortable <?= $sort_by === 'difficulty' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('difficulty')">Difficulty</th>
                            <th class="sortable <?= $sort_by === 'elevation_ft' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('elevation_ft')">Elevation</th>
                            <th class="sortable <?= $sort_by === 'hike_distance_mi' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('hike_distance_mi')">Distance</th>
                            <th class="sortable <?= $sort_by === 'hike_elevation_gain_ft' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('hike_elevation_gain_ft')">Gain</th>
                            <th class="sortable text-right <?= $sort_by === 'hike_time' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('hike_time')">Hike Time (RT)</th>
                            <th class="sortable text-right <?= $sort_by === 'drive_time' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('drive_time')">Drive Time (RT)</th>
                            <th class="sortable text-right <?= $sort_by === 'total_time' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('total_time')">Total Time (RT)</th>
                            <th class="sortable <?= $sort_by === 'last_activated_date' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('last_activated_date')">Last Activated</th>
                            <th class="sortable <?= $sort_by === 'status' ? 'sorted-' . strtolower($sort_order) : '' ?>" 
                                onclick="sortTable('status')">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summits as $summit):
                            $track_type    = $summit['track_type'] ?? 'round-trip';
                            $one_way       = ($track_type === 'ascent' || $track_type === 'descent');
                            $has_timestamps = ($summit['gpx_hiking_time'] ?? 0) > 0;

                            // Elevation gain
                            if ($summit['use_for_elevation'] && $summit['gpx_elevation_gain']) {
                                $elevation_for_display = ($track_type === 'descent')
                                    ? ($summit['gpx_elevation_loss'] ?? 0) * 3.28084
                                    : $summit['gpx_elevation_gain'] * 3.28084;
                            } else {
                                $elevation_for_display = $summit['hike_elevation_gain_ft'];
                            }

                            // Distance
                            if ($summit['use_for_hike_time'] && ($summit['gpx_total_distance'] ?? 0) > 0) {
                                $dist_km = $summit['gpx_total_distance'];
                                if ($one_way) $dist_km *= 2;
                                $distance_display_mi = round($dist_km * 0.621371, 2);
                            } else {
                                $distance_display_mi = $summit['hike_distance_mi'];
                            }

                            // Hike time — GPS recorded if available, otherwise Naismith
                            if ($summit['use_for_hike_time'] && $has_timestamps) {
                                $secs = $summit['gpx_hiking_time'];
                                if ($one_way) $secs *= 2;
                                $hike_time_total = round($secs / 60);
                            } else {
                                $hike_time_total = ($distance_display_mi || $elevation_for_display)
                                    ? calculateHikeTime($distance_display_mi ?? 0, $elevation_for_display ?? 0)
                                    : 0;
                            }

                            $drive_time = $summit['drive_time_min'] ?? 0;
                            $total_time = $hike_time_total + $drive_time + $activation_time;

                            // Mobile card stats strip
                            $_ms = [];
                            if ($hike_time_total) $_ms[] = '🥾 ' . formatTime($hike_time_total);
                            if ($drive_time)      $_ms[] = '🚗 ' . formatTime($drive_time);
                            if ($distance_display_mi) {
                                $_ms[] = '📏 ' . convertDistance($distance_display_mi, $current_group['units']) . ' ' . getDistanceUnit($current_group['units']);
                            }
                            if ($elevation_for_display) {
                                $_ms[] = '↑ ' . number_format(convertElevation($elevation_for_display, $current_group['units'])) . ' ' . getElevationUnit($current_group['units']);
                            }
                            $mobile_stats = implode(' · ', $_ms);

                            // Check if activated this calendar year (UTC)
                            $activated_this_year = false;
                            if ($summit['last_activated_date']) {
                                $last_activated_year = date('Y', strtotime($summit['last_activated_date']));
                                $current_year = gmdate('Y'); // UTC year
                                $activated_this_year = ($last_activated_year == $current_year);
                            }
                            
                            // Row styling
                            $row_class = '';
                            $row_style = '';
                            if ($activated_this_year) {
                                // Activated this year - grey (not eligible)
                                $row_class = 'activated-this-year';
                                $row_style = 'background: #f5f5f5; opacity: 0.6;';
                            } elseif ($summit['status'] === 'ready' || ($summit['status'] === 'activated' && !$activated_this_year)) {
                                // Ready to activate OR activated in previous year - green!
                                $row_class = 'ready-to-activate';
                                $row_style = 'background: #E8F5E9;';
                            }
                        ?>
                        <tr class="status-<?= $summit['status'] ?> <?= $row_class ?>" style="cursor: pointer; <?= $row_style ?>" data-stats="<?= htmlspecialchars($mobile_stats) ?>" onclick="window.location='summit_detail.php?id=<?= $summit['id'] ?>&group=<?= $current_group['id'] ?>';">
                            <td class="td-summit" onclick="event.stopPropagation();">
                                <a href="summit_detail.php?id=<?= $summit['id'] ?>&group=<?= $current_group['id'] ?>" style="text-decoration: none; color: inherit;">
                                    <div class="summit-name"><?= htmlspecialchars($summit['name']) ?></div>
                                    <div class="summit-ref"><?= htmlspecialchars($summit['sota_ref']) ?></div>
                                </a>
                            </td>
                            <td class="td-points"><?= $summit['points'] ?></td>
                            <td class="td-difficulty">
                                <?php if ($summit['difficulty']): ?>
                                    <span class="difficulty-badge difficulty-<?= strtolower(str_replace('-', '-', $summit['difficulty'])) ?>">
                                        <?= ucfirst($summit['difficulty']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="td-elevation">
                                <?php
                                $elevation = convertElevation($summit['elevation_ft'], $current_group['units']);
                                $unit = getElevationUnit($current_group['units']);
                                echo number_format($elevation) . ' ' . $unit;
                                ?>
                            </td>
                            <td class="td-distance">
                                <?php
                                if ($distance_display_mi) {
                                    $distance = convertDistance($distance_display_mi, $current_group['units']);
                                    $unit = getDistanceUnit($current_group['units']);
                                    echo $distance . ' ' . $unit;
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td class="td-gain">
                                <?php
                                if ($elevation_for_display) {
                                    $gain = convertElevation($elevation_for_display, $current_group['units']);
                                    $unit = getElevationUnit($current_group['units']);
                                    echo number_format($gain) . ' ' . $unit;
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td class="text-right td-hike-time"><?= $hike_time_total ? formatTime($hike_time_total) : '—' ?></td>
                            <td class="text-right td-drive-time"><?= $drive_time ? formatTime($drive_time) : '—' ?></td>
                            <td class="text-right td-total-time"><strong><?= formatTime($total_time) ?></strong></td>
                            <td class="td-last-activated">
                                <?php if ($summit['last_activated_date']): ?>
                                    <div><?= date('M j, Y', strtotime($summit['last_activated_date'])) ?></div>
                                    <?php if (!empty($summit['this_year_callsigns'])): ?>
                                        <div style="font-size: 0.75rem; color: #666; font-family: 'Courier New', monospace;">
                                            <?= htmlspecialchars($summit['this_year_callsigns']) ?>
                                        </div>
                                    <?php elseif (!empty($summit['activated_by'])): ?>
                                        <div style="font-size: 0.75rem; color: #666; font-family: 'Courier New', monospace;">
                                            <?= htmlspecialchars($summit['activated_by']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="td-status">
                                <?php if ($summit['status'] === 'activated'): ?>
                                    <?php if ($activated_this_year): ?>
                                        <span class="status-badge status-activated-this-year">Done This Year</span>
                                    <?php else: ?>
                                        <span class="status-badge status-ready">Ready</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-badge status-<?= $summit['status'] ?>">
                                        <?= ucfirst($summit['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Update page when activation time changes
        document.getElementById('activation_time').addEventListener('change', function() {
            const url = new URL(window.location);
            url.searchParams.set('activation_time', this.value);
            window.location = url.toString();
        });

        // Sort table by column
        function sortTable(column) {
            const url = new URL(window.location);
            const currentSort = url.searchParams.get('sort');
            const currentOrder = url.searchParams.get('order') || 'DESC';
            
            // Toggle order if clicking same column
            let newOrder = 'DESC';
            if (currentSort === column && currentOrder === 'DESC') {
                newOrder = 'ASC';
            }
            
            url.searchParams.set('sort', column);
            url.searchParams.set('order', newOrder);
            window.location = url.toString();
        }
    </script>

    <footer style="text-align:center; padding: 2rem 1rem 1.5rem; color:#aaa; font-size:0.78rem;">
        SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:#aaa; text-decoration:none;">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:#aaa; text-decoration:none;">sotaplanner.com</a>
    </footer>

    <!-- How It Works Modal -->
    <div id="howItWorksModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:1rem;" onclick="if(event.target===this)this.style.display='none'">
        <div style="background:white; border-radius:12px; max-width:660px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; color:#1E3A5F; margin:0;">⛰️ How SOTA Planner Works</h2>
                <button onclick="document.getElementById('howItWorksModal').style.display='none'"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1;">×</button>
            </div>
            <div style="padding:1.25rem 1.5rem 1.5rem; font-size:0.9rem; line-height:1.7; color:#333;">

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">What This Tool Does</h3>
                <p style="margin:0 0 1rem;">SOTA Planner helps your group plan Summits on the Air activations from door to door — not just the hike. It combines drive time, hiking time, and radio time into a single total-day estimate so you can compare summits and pick the right one for your available time.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">The Summit List</h3>
                <p style="margin:0 0 0.5rem;">Each row shows a nominated summit with its key planning numbers. Columns:</p>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.3rem;"><strong>Pts</strong> — SOTA points awarded for activating this summit.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Distance</strong> — round-trip hiking distance. From GPS track if one is loaded and enabled, otherwise manually entered.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Gain</strong> — total elevation gained on the approach.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Hike Time</strong> — round-trip hiking time. From actual GPS timestamps if available; otherwise estimated using Naismith's rule (distance + elevation gain).</li>
                    <li style="margin-bottom:0.3rem;"><strong>Drive Time</strong> — round-trip drive from your selected home base to the trailhead, via Google Maps.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Total Time</strong> — hike + drive + your planned activation time. Your full door-to-door day estimate.</li>
                </ul>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Filters &amp; Settings</h3>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.3rem;"><strong>⏱ Planned Activation</strong> — how long you expect to operate from the summit. Adjusts Total Time for all summits at once.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Max Total Time</strong> — filter to only show summits that fit within a target day length.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Status filters</strong> — show/hide summits by workflow stage (Nominated, Researched, Ready, Activated).</li>
                    <li style="margin-bottom:0.3rem;"><strong>Home Base</strong> — select your starting address to calculate drive times. Set up addresses in Manage Addresses.</li>
                    <li style="margin-bottom:0.3rem;"><strong>🚗 Recalculate Drive Times</strong> — re-query Google Maps for all summits in your list.</li>
                </ul>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Planning Groups</h3>
                <p style="margin:0 0 1rem;">Each planning group has its own list of summits, addresses, and settings. Switch groups in the top bar. Groups let different clubs or operating styles maintain separate lists while sharing the same tool.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Adding Summits</h3>
                <p style="margin:0;">Click <strong>+ Nominate Summit</strong> to add a summit by SOTA reference. The tool pulls coordinates and points from the SOTA database automatically. From there, use the summit detail page to add trail info, upload a GPX track, set the trailhead location, and schedule activations.</p>

            </div>
        </div>
    </div>
</body>
</html>
