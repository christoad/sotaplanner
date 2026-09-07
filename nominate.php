<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'sota_cache_helper.php';
session_start();
requireLogin();

// Link a global GPX library track to a newly-created summit row.
// Creates a gpx_tracks row pointing at the shared file and fills in
// hike distance, elevation gain, and trailhead on the summit.
function _link_global_gpx(PDO $db, int $summit_id, int $group_id, string $sota_ref): void {
    $st = $db->prepare("SELECT * FROM global_gpx_tracks WHERE sota_ref = ?");
    $st->execute([$sota_ref]);
    $g = $st->fetch();
    if (!$g || !file_exists($g['file_path'])) return;

    // Don't add a second gpx_tracks row if one already exists,
    // but still update the trailhead on the summit if the global track has one
    $chk = $db->prepare("SELECT id FROM gpx_tracks WHERE summit_id = ? LIMIT 1");
    $chk->execute([$summit_id]);
    if ($chk->fetch()) {
        if (!empty($g['trailhead_lat']) && $g['trailhead_lat'] != 0) {
            $db->prepare("UPDATE summits SET trailhead_lat = COALESCE(NULLIF(trailhead_lat, 0), ?), trailhead_lng = COALESCE(NULLIF(trailhead_lng, 0), ?) WHERE id = ?")
               ->execute([$g['trailhead_lat'], $g['trailhead_lon'], $summit_id]);
        }
        return;
    }

    $db->prepare("
        INSERT IGNORE INTO gpx_tracks (
            summit_id, planning_group_id, filename, file_path,
            total_time, hiking_time, activation_time, rest_break_time,
            total_distance, hiking_distance, max_elevation, min_elevation,
            elevation_gain, elevation_loss, avg_speed, hiking_speed,
            num_points, summit_lat, summit_lon, using_api,
            activation_zone_polygon, activation_zone_method,
            use_for_hike_time, use_for_elevation, from_global_library
        ) VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, NULL, 'none', 1, 1, 1)
    ")->execute([
        $summit_id, $group_id, $g['filename'], $g['file_path'],
        $g['total_distance'], $g['total_distance'],
        $g['max_elevation'], $g['min_elevation'],
        $g['elevation_gain'], $g['elevation_loss'],
        $g['num_points'], $g['summit_lat'], $g['summit_lon'],
    ]);

    // Auto-fill summit fields only if not already set
    $updates = [];
    $params  = [];
    if ($g['elevation_gain'] && $g['elevation_gain'] > 0) {
        $updates[] = "hike_elevation_gain_ft = COALESCE(hike_elevation_gain_ft, ?)";
        $params[]  = round($g['elevation_gain'] * 3.28084);
    }
    if ($g['total_distance'] && $g['total_distance'] > 0) {
        $updates[] = "hike_distance_mi = COALESCE(hike_distance_mi, ?)";
        $params[]  = round($g['total_distance'] * 2 * 0.621371, 2);
    }
    if (!empty($g['trailhead_lat']) && $g['trailhead_lat'] != 0) {
        $updates[] = "trailhead_lat = COALESCE(NULLIF(trailhead_lat, 0), ?)";
        $params[]  = $g['trailhead_lat'];
        $updates[] = "trailhead_lng = COALESCE(NULLIF(trailhead_lng, 0), ?)";
        $params[]  = $g['trailhead_lon'];
    }
    if ($updates) {
        $params[] = $summit_id;
        $db->prepare("UPDATE summits SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
    }
}

// Set summit status to 'researched' if it has both a GPX track and a valid trailhead.
function _maybe_set_researched(PDO $db, int $summit_id): void {
    $chk = $db->prepare("
        SELECT s.id
        FROM summits s
        JOIN gpx_tracks g ON g.summit_id = s.id
        WHERE s.id = ?
          AND s.trailhead_lat IS NOT NULL AND s.trailhead_lat != 0
          AND s.trailhead_lng IS NOT NULL AND s.trailhead_lng != 0
          AND s.status = 'nominated'
        LIMIT 1
    ");
    $chk->execute([$summit_id]);
    if ($chk->fetch()) {
        $db->prepare("UPDATE summits SET status = 'researched' WHERE id = ?")->execute([$summit_id]);
    }
}

// ── AJAX search endpoint ─────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $hits = search_sota_cache($q);
    if (isset($hits['_no_cache'])) {
        echo json_encode(['error' => 'Summit search cache not built yet. Please contact the site admin.']);
    } else {
        echo json_encode($hits);
    }
    exit;
}

// ── AJAX radius search endpoint ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'radius_search') {
    header('Content-Type: application/json');
    $db            = getDbConnection();
    $current_group = getCurrentPlanningGroup($db);

    $location  = trim($_GET['location'] ?? '');
    $radius_mi = max(1, min(300, (float)($_GET['radius_mi'] ?? 25)));
    $min_pts   = max(1, min(10, (int)($_GET['min_pts'] ?? 1)));

    // Direct lat/lng from dashboard map (bypasses geocoding)
    $direct_lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $direct_lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

    if ($direct_lat !== null && $direct_lng !== null && $direct_lat != 0) {
        $clat  = $direct_lat;
        $clon  = $direct_lng;
        $label = round($direct_lat, 4) . ', ' . round($direct_lng, 4);
    } else {
        if (strlen($location) < 2) {
            echo json_encode(['error' => 'Enter a location to search.']); exit;
        }
        if (!defined('GOOGLE_MAPS_API_KEY')) {
            echo json_encode(['error' => 'Geocoding not configured.']); exit;
        }

        // Geocode the location text using the server-side Maps key
        $geo_url  = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($location) . '&key=' . GOOGLE_MAPS_API_KEY;
        $geo_resp = @file_get_contents($geo_url);
        if (!$geo_resp) { echo json_encode(['error' => 'Geocoding service unavailable.']); exit; }
        $geo = json_decode($geo_resp, true);
        if (!$geo || ($geo['status'] ?? '') !== 'OK' || empty($geo['results'])) {
            echo json_encode(['error' => 'Location not found. Try a more specific address, city, or zip code.']); exit;
        }

        $clat  = (float)$geo['results'][0]['geometry']['location']['lat'];
        $clon  = (float)$geo['results'][0]['geometry']['location']['lng'];
        $label = $geo['results'][0]['formatted_address'];
    }

    // Search cache for summits within radius
    $summits = search_sota_cache_by_radius($clat, $clon, $radius_mi, $min_pts);

    if (isset($summits['_no_cache']))   { echo json_encode(['error' => 'Summit cache not built. Contact admin.']); exit; }
    if (isset($summits['_no_latlon'])) { echo json_encode(['error' => 'Area search requires a cache rebuild — please contact the site admin.']); exit; }

    // Flag summits already in this group
    if ($summits && $current_group) {
        $refs = array_column($summits, 'ref');
        $pl   = implode(',', array_fill(0, count($refs), '?'));
        $st   = $db->prepare("SELECT sota_ref FROM summits WHERE sota_ref IN ($pl) AND planning_group_id = ?");
        $st->execute(array_merge($refs, [$current_group['id']]));
        $nominated = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'sota_ref'));
        foreach ($summits as &$s) $s['nominated'] = isset($nominated[$s['ref']]);
        unset($s);
    }

    echo json_encode([
        'lat'     => $clat,
        'lon'     => $clon,
        'label'   => $label,
        'radius'  => $radius_mi,
        'summits' => $summits,
    ]);
    exit;
}

// ── AJAX: nominate one summit + check activation history ─────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'nominate_one') {
    header('Content-Type: application/json');
    $db            = getDbConnection();
    $current_group = getCurrentPlanningGroup($db);
    if (!$current_group) { echo json_encode(['error' => 'No active group']); exit; }

    $sota_ref = strtoupper(trim($_GET['ref'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{1,6}\/[A-Z0-9]{1,6}-\d{3,}$/', $sota_ref)) {
        echo json_encode(['error' => 'Invalid reference format', 'ref' => $sota_ref]); exit;
    }

    $summit_id = null;
    $name      = $sota_ref;
    $skipped   = false;

    // Already in this group?
    $chk = $db->prepare("SELECT id, name FROM summits WHERE sota_ref = ? AND planning_group_id = ?");
    $chk->execute([$sota_ref, $current_group['id']]);
    $existing = $chk->fetch();

    if ($existing) {
        $summit_id = (int)$existing['id'];
        $name      = $existing['name'];
        $skipped   = true;
    } else {
        // Fetch basic summit data from SOTA API
        $ch = curl_init("https://api2.sota.org.uk/api/summits/" . $sota_ref);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER    => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT       => 10,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || !$response) {
            echo json_encode(['error' => 'Summit not found', 'ref' => $sota_ref]); exit;
        }
        $sd = json_decode($response, true);
        if (!$sd) { echo json_encode(['error' => 'Invalid API response', 'ref' => $sota_ref]); exit; }

        if (isset($sd['valid']) && $sd['valid'] === false) {
            echo json_encode(['error' => 'Summit is no longer valid for SOTA activations', 'ref' => $sota_ref]); exit;
        }

        $name         = $sd['name'] ?? $sd['summitName'] ?? 'Unknown';
        $region       = $sd['regionName'] ?? $sd['region'] ?? '';
        $points       = $sd['points'] ?? 1;
        $elevation_m  = $sd['altM'] ?? $sd['altitude'] ?? 0;
        $elevation_ft = $sd['altFt'] ?? round($elevation_m * 3.28084);
        $latitude     = $sd['latitude'] ?? $sd['lat'] ?? 0;
        $longitude    = $sd['longitude'] ?? $sd['lng'] ?? $sd['long'] ?? 0;
        $sotlas_link  = "https://sotl.as/summits/" . $sota_ref;

        // Inherit shared data from another group if available
        $chk2 = $db->prepare("SELECT * FROM summits WHERE sota_ref = ? AND planning_group_id != ? AND (trail_link IS NOT NULL OR hike_distance_mi IS NOT NULL) LIMIT 1");
        $chk2->execute([$sota_ref, $current_group['id']]);
        $source = $chk2->fetch();

        $ins = $db->prepare("
            INSERT INTO summits
            (planning_group_id, source_group_id, uses_shared_data, sota_ref, name, region, points,
             elevation_m, elevation_ft, latitude, longitude, nominated_date, sotlas_link, status,
             trail_link, hike_distance_mi, hike_elevation_gain_ft, difficulty, trailhead_lat, trailhead_lng)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'nominated', ?, ?, ?, ?, ?, ?)
        ");
        try {
            $ins->execute([
                $current_group['id'],
                $source ? $source['planning_group_id'] : null,
                $source ? true : false,
                $sota_ref, $name, $region, $points, $elevation_m, $elevation_ft, $latitude, $longitude, $sotlas_link,
                $source ? $source['trail_link'] : null,
                $source ? $source['hike_distance_mi'] : null,
                $source ? $source['hike_elevation_gain_ft'] : null,
                $source ? $source['difficulty'] : null,
                $source ? $source['trailhead_lat'] : null,
                $source ? $source['trailhead_lng'] : null,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'DB error', 'ref' => $sota_ref]); exit;
        }

        $summit_id = (int)$db->lastInsertId();
        _link_global_gpx($db, $summit_id, $current_group['id'], $sota_ref);
        _maybe_set_researched($db, $summit_id);
    }

    // ── Activation history check for group members ────────────────────────────
    $last_activated_date = null;
    $last_activated_by   = null;
    $activated_this_year = false;

    $group_callsigns = getGroupHomeCallsigns($db, $current_group['id']);

    $cache_key = 'sota_activations_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $sota_ref);
    $cstmt = $db->prepare("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = ?");
    $cstmt->execute([$cache_key]);
    $crow  = $cstmt->fetch();
    $all_acts = null;

    if ($crow && (time() - strtotime($crow['updated_at'])) < 86400) {
        $all_acts = json_decode($crow['setting_value'], true);
    } else {
        $ref_parts = explode('/', $sota_ref, 2);
        if (count($ref_parts) === 2) {
            $acts_url = 'https://api2.sota.org.uk/api/activations/' . urlencode($ref_parts[0]) . '/' . urlencode($ref_parts[1]);
            $ctx = stream_context_create(['http' => [
                'timeout'       => 6,
                'ignore_errors' => true,
                'header'        => "Accept: application/json\r\nUser-Agent: SOTAplanner/1.0\r\n",
            ]]);
            $raw = @file_get_contents($acts_url, false, $ctx);
            if ($raw !== false) {
                $fetched = json_decode($raw, true);
                if (is_array($fetched)) {
                    $all_acts = $fetched;
                    $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at)
                                  VALUES (?, ?, NOW())
                                  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()")
                       ->execute([$cache_key, json_encode($all_acts)]);
                }
            }
        }
    }

    if (is_array($all_acts)) {
        $member_acts = [];
        foreach ($all_acts as $act) {
            $cs2 = strtoupper(trim($act['ownCallsign'] ?? ''));
            if (sotaCallsignMatchesHome($cs2, $group_callsigns) !== null) {
                $member_acts[] = ['date' => $act['activationDate'] ?? '', 'callsign' => $cs2];
            }
        }
        usort($member_acts, fn($a, $b) => strcmp($b['date'], $a['date']));

        if (!empty($member_acts)) {
            $newest   = $member_acts[0];
            $new_date = date('Y-m-d', strtotime($newest['date']));
            $db->prepare("UPDATE summits SET last_activated_date = ?, activated_by = ?, status = 'activated'
                          WHERE id = ? AND (last_activated_date IS NULL OR last_activated_date < ?)")
               ->execute([$new_date, $newest['callsign'], $summit_id, $new_date]);
            $last_activated_date = $new_date;
            $last_activated_by   = $newest['callsign'];
            $activated_this_year = (substr($new_date, 0, 4) === gmdate('Y'));
        }
    }

    echo json_encode([
        'success'             => true,
        'skipped'             => $skipped,
        'ref'                 => $sota_ref,
        'id'                  => $summit_id,
        'name'                => $name,
        'last_activated_date' => $last_activated_date,
        'last_activated_by'   => $last_activated_by,
        'activated_this_year' => $activated_this_year,
    ]);
    exit;
}

// ── AJAX: calculate travel time for a batch of just-nominated summits ────────
// Only touches summits that already have a starting point (trailhead) set.
// Returns need_address=true if the dashboard has no address to calculate from.
if (isset($_GET['action']) && $_GET['action'] === 'bulk_drive_times') {
    header('Content-Type: application/json');
    $db            = getDbConnection();
    $current_group = getCurrentPlanningGroup($db);
    if (!$current_group) { echo json_encode(['error' => 'No active group']); exit; }

    $ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));
    if (empty($ids)) { echo json_encode(['updated' => 0, 'skipped' => 0, 'need_address' => false]); exit; }

    // Find (or auto-select, matching index.php's own fallback) an address to calculate from
    $selected_address = getSelectedAddress($db);
    if (!$selected_address) {
        $stmt = $db->prepare("SELECT * FROM addresses WHERE planning_group_id = ? ORDER BY label, address");
        $stmt->execute([$current_group['id']]);
        $all_addresses = $stmt->fetchAll();

        if (empty($all_addresses)) {
            echo json_encode(['updated' => 0, 'skipped' => 0, 'need_address' => true]); exit;
        }

        $selected_address = $all_addresses[0];
        $setting_key = 'selected_address_group_' . $current_group['id'];
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([$setting_key, $selected_address['id']]);
    }

    if (GOOGLE_MAPS_API_KEY === 'YOUR_API_KEY_HERE') {
        echo json_encode(['error' => 'Google Maps API key not configured', 'need_address' => false]); exit;
    }

    $pl   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, trailhead_lat, trailhead_lng FROM summits WHERE planning_group_id = ? AND id IN ($pl)");
    $stmt->execute(array_merge([$current_group['id']], $ids));
    $summits = $stmt->fetchAll();

    $updated = 0;
    $skipped = 0;
    foreach ($summits as $summit) {
        if (empty($summit['trailhead_lat']) || (float)$summit['trailhead_lat'] == 0
            || empty($summit['trailhead_lng']) || (float)$summit['trailhead_lng'] == 0) {
            $skipped++;
            continue;
        }

        $drive_time = calculateDriveTime($selected_address['address'], $summit['trailhead_lat'], $summit['trailhead_lng']);
        if ($drive_time !== null) {
            // Round trip, matching the manual "Calculate Drive Times" convention on index.php
            $db->prepare("UPDATE summits SET drive_time_min = ? WHERE id = ?")->execute([$drive_time * 2, $summit['id']]);
            $updated++;
        } else {
            $skipped++;
        }
    }

    echo json_encode(['updated' => $updated, 'skipped' => $skipped, 'need_address' => false]);
    exit;
}

// ── AJAX: add a starting point address to the current dashboard ──────────────
// Used when a bulk nomination finds no address to calculate travel time from.
if (isset($_GET['action']) && $_GET['action'] === 'add_starting_point') {
    header('Content-Type: application/json');
    $db            = getDbConnection();
    $current_group = getCurrentPlanningGroup($db);
    if (!$current_group) { echo json_encode(['error' => 'No active group']); exit; }

    $location = trim($_GET['location'] ?? '');
    if (strlen($location) < 2) { echo json_encode(['error' => 'Enter a starting point.']); exit; }
    if (!defined('GOOGLE_MAPS_API_KEY')) { echo json_encode(['error' => 'Geocoding not configured.']); exit; }

    // Validate the location is recognizable by Google Maps before saving it
    $geo_url  = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($location) . '&key=' . GOOGLE_MAPS_API_KEY;
    $geo_resp = @file_get_contents($geo_url);
    if (!$geo_resp) { echo json_encode(['error' => 'Geocoding service unavailable.']); exit; }
    $geo = json_decode($geo_resp, true);
    if (!$geo || ($geo['status'] ?? '') !== 'OK' || empty($geo['results'])) {
        echo json_encode(['error' => 'Location not found. Try a more specific address, city, or landmark.']); exit;
    }

    $stmt = $db->prepare("INSERT INTO addresses (planning_group_id, label, address) VALUES (?, ?, ?)");
    $stmt->execute([$current_group['id'], 'Starting Point', $location]);
    $new_address_id = (int)$db->lastInsertId();

    $setting_key = 'selected_address_group_' . $current_group['id'];
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
       ->execute([$setting_key, $new_address_id]);

    echo json_encode(['success' => true, 'address_id' => $new_address_id]);
    exit;
}

$db = getDbConnection();

$message = '';
$error = '';

// Get current planning group
$current_group = getCurrentPlanningGroup($db);

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nominate'])) {

    // ── Batch nomination ──────────────────────────────────────────────────────
    if (($_POST['form_mode'] ?? 'single') === 'bulk') {
        $raw_refs = $_POST['sota_refs'] ?? '';
        $refs = array_filter(array_unique(array_map('strtoupper', array_map('trim', explode(',', $raw_refs)))));

        $succeeded  = 0;
        $new_ids    = [];
        foreach ($refs as $sota_ref) {
            if (!preg_match('/^[A-Z0-9]{1,6}\/[A-Z0-9]{1,6}-\d{3,}$/', $sota_ref)) continue;

            $ch = curl_init("https://api2.sota.org.uk/api/summits/" . $sota_ref);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER    => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT       => 10,
            ]);
            $response  = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200 || !$response) continue;
            $summit_data = json_decode($response, true);
            if (!$summit_data) continue;

            // Skip inactive summits
            if (isset($summit_data['valid']) && $summit_data['valid'] === false) continue;
            $valid_to = $summit_data['validTo'] ?? null;
            if ($valid_to && strtotime($valid_to) < time()) continue;

            $name         = $summit_data['name'] ?? $summit_data['summitName'] ?? 'Unknown';
            $region       = $summit_data['regionName'] ?? $summit_data['region'] ?? '';
            $points       = $summit_data['points'] ?? 1;
            $elevation_m  = $summit_data['altM'] ?? $summit_data['altitude'] ?? 0;
            $elevation_ft = $summit_data['altFt'] ?? round($elevation_m * 3.28084);
            $latitude     = $summit_data['latitude'] ?? $summit_data['lat'] ?? 0;
            $longitude    = $summit_data['longitude'] ?? $summit_data['lng'] ?? $summit_data['long'] ?? 0;

            try {
                // Already nominated by this group — count as success, don't re-insert
                $chk = $db->prepare("SELECT id FROM summits WHERE sota_ref = ? AND planning_group_id = ?");
                $chk->execute([$sota_ref, $current_group['id']]);
                if ($chk->fetch()) { $succeeded++; continue; }

                $chk = $db->prepare("SELECT * FROM summits WHERE sota_ref = ? AND planning_group_id != ? AND (trail_link IS NOT NULL OR hike_distance_mi IS NOT NULL) LIMIT 1");
                $chk->execute([$sota_ref, $current_group['id']]);
                $source_summit = $chk->fetch();

                $sotlas_link = "https://sotl.as/summits/" . $sota_ref;

                $ins = $db->prepare("
                    INSERT INTO summits
                    (planning_group_id, source_group_id, uses_shared_data, sota_ref, name, region, points,
                     elevation_m, elevation_ft, latitude, longitude, nominated_date, sotlas_link, status,
                     trail_link, hike_distance_mi, hike_elevation_gain_ft, difficulty,
                     trailhead_lat, trailhead_lng)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'nominated', ?, ?, ?, ?, ?, ?)
                ");

                if ($source_summit) {
                    $ins->execute([
                        $current_group['id'], $source_summit['planning_group_id'], true,
                        $sota_ref, $name, $region, $points, $elevation_m, $elevation_ft, $latitude, $longitude, $sotlas_link,
                        $source_summit['trail_link'], $source_summit['hike_distance_mi'],
                        $source_summit['hike_elevation_gain_ft'], $source_summit['difficulty'],
                        $source_summit['trailhead_lat'], $source_summit['trailhead_lng'],
                    ]);
                } else {
                    $ins->execute([
                        $current_group['id'], null, false,
                        $sota_ref, $name, $region, $points, $elevation_m, $elevation_ft, $latitude, $longitude, $sotlas_link,
                        null, null, null, null, null, null
                    ]);
                }

                $summit_id = $db->lastInsertId();
                _link_global_gpx($db, $summit_id, $current_group['id'], $sota_ref);
                _maybe_set_researched($db, $summit_id);
                $new_ids[] = $summit_id;
                $succeeded++;
            } catch (PDOException $e) {
                // skip failed inserts
            }
        }

        $ids_param = $new_ids ? '&new_ids=' . implode(',', $new_ids) : '';
        header("Location: index.php?group=" . $current_group['id'] . "&bulk_nominated=" . $succeeded . $ids_param);
        exit;
    }

    // ── Single nomination ─────────────────────────────────────────────────────
    $sota_ref = strtoupper(trim($_POST['sota_ref']));

    $api_url = "https://api2.sota.org.uk/api/summits/" . $sota_ref;

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $summit_data = json_decode($response, true);

        if ($summit_data) {
            // Block inactive summits
            if (isset($summit_data['valid']) && $summit_data['valid'] === false) {
                $error = 'That summit is no longer valid for SOTA activations and cannot be added.';
            } elseif (!empty($summit_data['validTo']) && strtotime($summit_data['validTo']) < time()) {
                $error = 'That summit is no longer valid for SOTA activations and cannot be added.';
            } else {

            $name = $summit_data['name'] ?? $summit_data['summitName'] ?? 'Unknown';
            $region = $summit_data['regionName'] ?? $summit_data['region'] ?? '';
            $points = $summit_data['points'] ?? 1;
            $elevation_m = $summit_data['altM'] ?? $summit_data['altitude'] ?? 0;
            $elevation_ft = $summit_data['altFt'] ?? round($elevation_m * 3.28084);
            $latitude = $summit_data['latitude'] ?? $summit_data['lat'] ?? 0;
            $longitude = $summit_data['longitude'] ?? $summit_data['lng'] ?? $summit_data['long'] ?? 0;

            try {
                // Check if this group already nominated this summit
                $stmt = $db->prepare("SELECT id FROM summits WHERE sota_ref = ? AND planning_group_id = ?");
                $stmt->execute([$sota_ref, $current_group['id']]);
                $existing = $stmt->fetch();

                if ($existing) {
                    header("Location: summit_detail.php?id=" . $existing['id'] . "&group=" . $current_group['id']);
                    exit;
                }

                // Check if ANY other group has researched this summit
                $stmt = $db->prepare("
                    SELECT * FROM summits
                    WHERE sota_ref = ?
                    AND planning_group_id != ?
                    AND (trail_link IS NOT NULL OR hike_distance_mi IS NOT NULL)
                    LIMIT 1
                ");
                $stmt->execute([$sota_ref, $current_group['id']]);
                $source_summit = $stmt->fetch();

                $stmt = $db->prepare("
                    INSERT INTO summits
                    (planning_group_id, source_group_id, uses_shared_data, sota_ref, name, region, points,
                     elevation_m, elevation_ft, latitude, longitude, nominated_date, sotlas_link, status,
                     trail_link, hike_distance_mi, hike_elevation_gain_ft, difficulty,
                     trailhead_lat, trailhead_lng)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'nominated', ?, ?, ?, ?, ?, ?)
                ");

                $sotlas_link = "https://sotl.as/summits/" . $sota_ref;

                if ($source_summit) {
                    $stmt->execute([
                        $current_group['id'],
                        $source_summit['planning_group_id'],
                        true,
                        $sota_ref, $name, $region, $points, $elevation_m, $elevation_ft,
                        $latitude, $longitude, $sotlas_link,
                        $source_summit['trail_link'],
                        $source_summit['hike_distance_mi'],
                        $source_summit['hike_elevation_gain_ft'],
                        $source_summit['difficulty'],
                        $source_summit['trailhead_lat'],
                        $source_summit['trailhead_lng'],
                    ]);

                    $summit_id = $db->lastInsertId();

                    $stmt = $db->prepare("SELECT * FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
                    $stmt->execute([$source_summit['id'], $source_summit['planning_group_id']]);
                    $source_gpx = $stmt->fetch();

                    if ($source_gpx && file_exists($source_gpx['file_path'])) {
                        $upload_dir = __DIR__ . '/gpx_files';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $new_filename = $summit_id . '_grp' . $current_group['id'] . '_' . time() . '.gpx';
                        $new_filepath = $upload_dir . '/' . $new_filename;

                        if (copy($source_gpx['file_path'], $new_filepath)) {
                            $stmt = $db->prepare("
                                INSERT INTO gpx_tracks (
                                    summit_id, planning_group_id, filename, file_path,
                                    total_time, hiking_time, activation_time, rest_break_time,
                                    total_distance, hiking_distance, max_elevation, min_elevation,
                                    elevation_gain, elevation_loss, avg_speed, hiking_speed,
                                    num_points, summit_lat, summit_lon, using_api,
                                    activation_zone_polygon, activation_zone_method,
                                    use_for_hike_time, use_for_elevation, track_type
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $summit_id, $current_group['id'], $new_filename, $new_filepath,
                                $source_gpx['total_time'], $source_gpx['hiking_time'],
                                $source_gpx['activation_time'], $source_gpx['rest_break_time'],
                                $source_gpx['total_distance'], $source_gpx['hiking_distance'],
                                $source_gpx['max_elevation'], $source_gpx['min_elevation'],
                                $source_gpx['elevation_gain'], $source_gpx['elevation_loss'],
                                $source_gpx['avg_speed'], $source_gpx['hiking_speed'],
                                $source_gpx['num_points'], $source_gpx['summit_lat'], $source_gpx['summit_lon'],
                                $source_gpx['using_api'], $source_gpx['activation_zone_polygon'],
                                $source_gpx['activation_zone_method'],
                                $source_gpx['use_for_hike_time'], $source_gpx['use_for_elevation'],
                                $source_gpx['track_type'] ?? 'round-trip',
                            ]);
                        }
                    }

                    if (!($source_gpx && file_exists($source_gpx['file_path']))) {
                        _link_global_gpx($db, $summit_id, $current_group['id'], $sota_ref);
                    }

                    _maybe_set_researched($db, $summit_id);
                    header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&shared_data=1");
                    exit;
                } else {
                    $stmt->execute([
                        $current_group['id'],
                        null,
                        false,
                        $sota_ref, $name, $region, $points, $elevation_m, $elevation_ft,
                        $latitude, $longitude, $sotlas_link,
                        null, null, null, null, null, null
                    ]);

                    $summit_id = $db->lastInsertId();

                    // Try to import data from SOTLAS
                    $api_url_sotlas = "https://api.sotl.as/summits/" . urlencode($sota_ref);
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 10,
                            'ignore_errors' => true,
                            'header' => 'User-Agent: SOTA-Planner/1.0'
                        ]
                    ]);

                    $sotlas_response = @file_get_contents($api_url_sotlas, false, $context);
                    $sotlas_imported = [];

                    if ($sotlas_response) {
                        $sotlas_data = json_decode($sotlas_response, true);

                        if ($sotlas_data && isset($sotlas_data['routes']) && !empty($sotlas_data['routes'])) {
                            $route = $sotlas_data['routes'][0];

                            $updates = [];
                            if (isset($route['distance'])) {
                                $distance_km = floatval($route['distance']);
                                $updates[] = "hike_distance_mi = " . round($distance_km * 2 * 0.621371, 2);
                                $sotlas_imported[] = 'distance';
                            }
                            if (isset($route['ascent'])) {
                                $gain_m = floatval($route['ascent']);
                                $updates[] = "hike_elevation_gain_ft = " . round($gain_m * 3.28084);
                                $sotlas_imported[] = 'elevation_gain';
                            }
                            if (isset($route['difficulty'])) {
                                $diff_map = ['easy' => 'easy', 'moderate' => 'moderate', 'hard' => 'hard', 'very_hard' => 'very hard'];
                                $diff = strtolower($route['difficulty']);
                                if (isset($diff_map[$diff])) {
                                    $updates[] = "difficulty = '" . $diff_map[$diff] . "'";
                                    $sotlas_imported[] = 'difficulty';
                                }
                            }
                            if (isset($route['start_point']['latitude']) && isset($route['start_point']['longitude'])) {
                                $updates[] = "trailhead_lat = " . floatval($route['start_point']['latitude']);
                                $updates[] = "trailhead_lng = " . floatval($route['start_point']['longitude']);
                                $sotlas_imported[] = 'trailhead';
                            }

                            if (!empty($updates)) {
                                $updates[] = "data_source = 'sotlas'";
                                $updates[] = "sotlas_data_fetched = 1";
                                $sql = "UPDATE summits SET " . implode(', ', $updates) . " WHERE id = $summit_id";
                                $db->exec($sql);
                            }
                        }
                    }

                    _link_global_gpx($db, $summit_id, $current_group['id'], $sota_ref);
                    _maybe_set_researched($db, $summit_id);

                    $gpx_chk = $db->prepare("SELECT id FROM gpx_tracks WHERE summit_id = ? LIMIT 1");
                    $gpx_chk->execute([$summit_id]);
                    $has_gpx = (bool)$gpx_chk->fetchColumn();

                    logActivity($db, 'Summit nominated', $sota_ref, $summit_data['name'] ?? '', $current_group['name'] ?? '');
                    $qs = $has_gpx ? '&nominated=1' : '&nominated=1&fetch_gpx=1';
                    header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . $qs);
                    exit;
                }
            } catch (PDOException $e) {
                $error = "Error saving summit: " . $e->getMessage();
            }
            } // end: active summit block
        }
    } else {
        $error = "Summit '$sota_ref' not found. Check the reference format (e.g., W6/CT-225).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nominate Summit — SOTA Planner</title>
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
      --green-border:  oklch(85% 0.07 155);
      --red:           oklch(52% 0.16 22);
      --red-bg:        oklch(96% 0.04 22);
      --red-border:    oklch(85% 0.08 22);
      --surface:       #FFFFFF;
      --border:        #E5E2DA;
      --border-2:      #D4D0C8;
      --font-sans:     'DM Sans', system-ui, sans-serif;
      --font-mono:     'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
      --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }
    body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

    /* Topbar */
    .topbar {
      background: var(--surface); border-bottom: 1px solid var(--border);
      height: 56px; display: flex; align-items: center;
      padding: 0 2rem; gap: 1rem; position: sticky; top: 0; z-index: 100;
    }
    .topbar-logo {
      display: flex; align-items: center; gap: 0.75rem;
      text-decoration: none; color: var(--ink);
      font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0;
    }
    .topbar-logo:hover { color: var(--ink); text-decoration: none; }
    .topbar-logo .logo-mark { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
    .topbar-nav { display: flex; align-items: center; gap: 0.25rem; }
    .topbar-nav a {
      color: var(--ink-3); font-size: 0.875rem; font-weight: 500;
      padding: 0.5rem 0.75rem; border-radius: var(--r-sm);
      transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap;
    }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); }
    .topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; flex-shrink: 0; }
    .user-chip {
      position: relative; display: flex; align-items: center; gap: 0.35rem;
      cursor: pointer; padding: 0.25rem 0.6rem; border-radius: var(--r-sm);
      font-size: 0.8rem; font-weight: 600; color: var(--ink-2);
      border: 1px solid var(--border); background: var(--bg); user-select: none; white-space: nowrap;
    }
    .user-chip:hover { background: var(--bg-2); }
    .user-chip-chevron { transition: transform 0.15s; flex-shrink: 0; }
    .user-chip.open .user-chip-chevron { transform: rotate(180deg); }
    .user-dropdown {
      display: none; position: absolute; top: calc(100% + 6px); right: 0;
      background: #fff; border: 1px solid var(--border); border-radius: var(--r-sm);
      box-shadow: 0 4px 16px rgba(0,0,0,0.10); min-width: 130px; overflow: hidden; z-index: 200;
    }
    .user-chip.open .user-dropdown { display: block; }
    .user-dropdown a {
      display: block; padding: 0.6rem 1rem;
      font-size: 0.82rem; font-weight: 500; color: var(--ink-2); text-decoration: none;
    }
    .user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

    /* ── Hamburger menu (mobile nav — topbar-nav links are hidden below 640px) ── */
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

    /* Page layout */
    .page { padding: 2rem; max-width: 1100px; margin: 0 auto; }
    .nom-search-inner { max-width: 580px; margin: 0 auto; }

    /* Area tab two-column layout */
    #area_map {
      width: 100%; height: 240px;
      border-radius: var(--r-md); border: 1px solid var(--border);
      background: var(--bg-2); display: none;
    }
    .area-body { display: flex; flex-direction: column; }
    .area-map-col { order: 1; margin-bottom: 1rem; }
    .area-results-col { order: 2; }
    @media (min-width: 900px) {
      .area-body { flex-direction: row; gap: 1.25rem; align-items: flex-start; }
      .area-results-col { width: 380px; flex-shrink: 0; order: 1; }
      .area-map-col { flex: 1; min-width: 0; order: 2; margin-bottom: 0; position: sticky; top: 72px; }
      #area_map { height: 520px; }
    }

    /* Map-click "search here" popup */
    .area-click-popup { font-family: var(--font-sans); }
    .area-click-popup-btn {
      background: var(--ink); color: #fff; border: none; border-radius: var(--r-sm);
      padding: 0.4rem 0.75rem; font-size: 0.8rem; font-weight: 600; cursor: pointer;
      font-family: var(--font-sans); display: block;
    }
    .area-click-popup-btn:hover { background: var(--ink-2); }
    /* Google's InfoWindow chrome adds generous default padding; tighten it around our small button */
    .gm-style-iw.gm-style-iw-c { padding: 8px !important; }
    .gm-style-iw-d { overflow: hidden !important; }

    /* Green pulse highlight when a summit dot is clicked on the map */
    @keyframes areaRowPulse {
      0%, 100% { background: var(--green-bg); box-shadow: inset 0 0 0 2px var(--green); }
      50% { background: var(--surface); box-shadow: inset 0 0 0 2px var(--green); }
    }
    .area-row-pulse { animation: areaRowPulse 0.7s ease-in-out 2; }
    .area-row-selected { background: var(--green-bg) !important; }

    /* Card */
    .card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--r-lg); padding: 1.5rem; box-shadow: var(--shadow-sm);
      margin-bottom: 1.25rem;
    }

    /* Messages */
    .msg {
      display: flex; align-items: center; gap: 1rem;
      padding: 0.75rem 1rem; border-radius: var(--r-md);
      font-size: 0.875rem; font-weight: 500; margin-bottom: 1rem;
    }
    .msg-success { background: var(--green-bg); color: var(--green); border: 1px solid var(--green-border); }
    .msg-error   { background: var(--red-bg);   color: var(--red);   border: 1px solid var(--red-border); }

    /* Form */
    .form-label {
      display: block; font-size: 0.75rem; font-weight: 600; color: var(--ink-3);
      margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em;
    }
    .form-input {
      width: 100%; padding: 0.6rem 0.75rem;
      border: 1px solid var(--border); border-radius: var(--r-md);
      font-family: var(--font-sans); font-size: 0.9375rem; color: var(--ink);
      background: var(--surface); transition: border-color 0.15s; outline: none;
    }
    .form-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-bg); }
    .form-hint { font-size: 0.775rem; color: var(--ink-3); margin-top: 0.35rem; line-height: 1.4; min-height: 1.1rem; }

    /* Buttons */
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
      padding: 0 1rem; height: 36px; border-radius: var(--r-md);
      font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500;
      cursor: pointer; border: none; transition: background 0.15s, opacity 0.15s;
      text-decoration: none; white-space: nowrap; line-height: 1;
    }
    .btn:active { transform: scale(0.98); }
    .btn-primary { background: var(--ink); color: #fff; width: 100%; height: 40px; font-size: 0.9rem; }
    .btn-primary:hover:not(:disabled) { background: var(--ink-2); }
    .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }

    /* Group badge */
    .group-badge {
      display: inline-flex; align-items: center; gap: 0.4rem;
      background: var(--accent-bg); border: 1px solid var(--accent-border);
      border-radius: 100px; padding: 0.3rem 0.75rem;
      font-size: 0.8rem; font-weight: 600; color: var(--accent-2);
      margin-bottom: 1.25rem;
    }

    /* Search results (single mode) */
    .result-item {
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      padding: 0.7rem 0.875rem; border: 1px solid var(--border); border-radius: var(--r-md);
      background: var(--surface); cursor: pointer;
      transition: background 0.12s, border-color 0.12s; margin-bottom: 0.3rem;
    }
    .result-item:hover { background: var(--accent-bg); border-color: var(--accent-border); }
    .result-name { font-size: 0.875rem; font-weight: 600; color: var(--ink); }
    .result-ref  { font-family: var(--font-mono); font-size: 0.75rem; color: var(--ink-3); margin-top: 2px; }
    .result-meta { font-size: 0.775rem; color: var(--ink-3); text-align: right; white-space: nowrap; flex-shrink: 0; }
    .result-pts  { font-weight: 700; color: var(--accent); }
    .search-status { padding: 0.75rem 0; color: var(--ink-3); font-size: 0.85rem; }
    .search-count  { font-size: 0.75rem; color: var(--ink-4); margin-bottom: 0.5rem; }

    /* Selected summit (single mode) */
    .selected-box {
      display: none; background: var(--green-bg); border: 1px solid var(--green-border);
      border-radius: var(--r-md); padding: 0.875rem 1rem; margin-bottom: 1rem;
    }
    .selected-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--green); margin-bottom: 0.3rem; }
    .selected-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .selected-name { font-size: 0.9375rem; font-weight: 600; color: var(--ink); }
    .selected-ref  { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-3); margin-left: 0.5rem; }
    .clear-btn { background: none; border: none; color: var(--ink-4); cursor: pointer; font-size: 1rem; line-height: 1; padding: 0.2rem; border-radius: var(--r-sm); }
    .clear-btn:hover { color: var(--ink-2); background: var(--bg-2); }

    /* Batch results */
    .batch-summary {
      font-size: 0.8rem; font-weight: 600; color: var(--ink-3);
      margin-bottom: 0.6rem;
    }
    .batch-item {
      display: flex; align-items: center; gap: 0.75rem;
      padding: 0.6rem 0.875rem; border-radius: var(--r-md);
      border: 1px solid; margin-bottom: 0.35rem;
    }
    .batch-item-found    { background: var(--green-bg); border-color: var(--green-border); }
    .batch-item-notfound { background: var(--red-bg);   border-color: var(--red-border); }
    .batch-item-icon { flex-shrink: 0; }
    .batch-item-info { flex: 1; min-width: 0; }
    .batch-item-name { font-size: 0.875rem; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .batch-item-ref  { font-family: var(--font-mono); font-size: 0.75rem; color: var(--ink-3); }
    .batch-item-status { font-size: 0.775rem; color: var(--red); font-weight: 500; }
    .batch-item-pts  { font-size: 0.775rem; font-weight: 700; color: var(--green); white-space: nowrap; flex-shrink: 0; }

    /* Tabs */
    .nom-tabs { display: flex; background: var(--bg-2); border-bottom: 1px solid var(--border); }
    .nom-tab {
      flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.4rem;
      padding: 0.9rem 1rem; position: relative;
      font-size: 0.875rem; font-weight: 500; color: var(--ink-3);
      border: none; background: transparent; cursor: pointer;
      font-family: var(--font-sans); transition: background 0.15s, color 0.15s;
      white-space: nowrap;
    }
    .nom-tab + .nom-tab { border-left: 1px solid var(--border); }
    .nom-tab:hover:not(.active) { background: var(--bg-3); color: var(--ink-2); }
    .nom-tab.active {
      background: var(--surface); color: var(--ink); font-weight: 600;
    }
    .nom-tab.active::after {
      content: ''; position: absolute; bottom: 0; left: 0; right: 0;
      height: 2px; background: var(--accent);
    }
    .nom-panel { padding: 1.5rem; display: none; }
    .nom-panel.active { display: block; }
    .search-hint-strip {
      font-size: 0.775rem; color: var(--ink-3); margin-bottom: 1rem; line-height: 1.6;
    }
    .search-hint-strip code {
      font-family: var(--font-mono); font-size: 0.72rem; color: var(--accent-2);
      background: var(--accent-bg); border-radius: var(--r-sm);
      padding: 0.1rem 0.35rem; margin: 0 0.1rem;
    }

    @media (max-width: 640px) {
      .topbar { padding: 0 1rem; }
      .topbar-nav { display: none; }
      .hamburger-menu { display: block; }
      .page { padding: 1rem; }
    }

    /* Bulk nomination progress overlay */
    #nom-progress-overlay {
      position: fixed; inset: 0; z-index: 9000;
      background: rgba(20,19,18,0.55);
      display: flex; align-items: center; justify-content: center;
    }
    .nom-progress-card {
      background: #fff; border-radius: 20px;
      padding: 2.5rem 2.5rem 2rem;
      max-width: 480px; width: 92%;
      box-shadow: 0 16px 56px rgba(0,0,0,0.28);
      display: flex; flex-direction: column; align-items: center;
      gap: 1.25rem; text-align: center;
    }
    .nom-loading-svg { width: 100px; height: 100px; overflow: visible; }
    .nom-logo-path {
      stroke-dasharray: 116; stroke-dashoffset: 116;
      animation: nom-path-draw 3s ease-in-out infinite;
    }
    @keyframes nom-path-draw {
      0%   { stroke-dashoffset: 116; opacity: 0; }
      7%   { stroke-dashoffset: 116; opacity: 1; }
      62%  { stroke-dashoffset: 0;   opacity: 1; }
      80%  { stroke-dashoffset: 0;   opacity: 1; }
      94%  { stroke-dashoffset: 0;   opacity: 0; }
      100% { stroke-dashoffset: 116; opacity: 0; }
    }
    .nom-logo-dot {
      fill: var(--red);
      transform-box: fill-box; transform-origin: center;
      animation: nom-dot-pop 3s ease-in-out 1.2s infinite; opacity: 0;
    }
    @keyframes nom-dot-pop {
      0%   { transform: scale(0);   opacity: 0; }
      15%  { transform: scale(1.4); opacity: 1; }
      30%  { transform: scale(1);   opacity: 1; }
      72%  { transform: scale(1);   opacity: 1; }
      90%  { transform: scale(0.4); opacity: 0; }
      100% { transform: scale(0);   opacity: 0; }
    }
    .nom-logo-ring {
      transform-box: fill-box; transform-origin: center; opacity: 0;
    }
    .nom-logo-ring1 { animation: nom-ring-pulse 3s ease-out 1.2s infinite; }
    .nom-logo-ring2 { animation: nom-ring-pulse 3s ease-out 1.5s infinite; }
    @keyframes nom-ring-pulse {
      0%   { transform: scale(0.5); opacity: 0; }
      10%  { opacity: 0.5; }
      68%  { transform: scale(2.4); opacity: 0; }
      100% { transform: scale(2.4); opacity: 0; }
    }
    .nom-progress-bar-track {
      width: 100%; background: var(--bg-2);
      border-radius: 100px; height: 8px; overflow: hidden;
    }
    .nom-progress-bar-fill {
      height: 100%; background: var(--green);
      border-radius: 100px; width: 0%;
      transition: width 0.25s ease;
    }
    .nom-log-entry { padding: 0.25rem 0; border-bottom: 1px solid var(--border); font-size: 0.775rem; line-height: 1.4; }
    .nom-log-entry:last-child { border-bottom: none; }
    .nom-log-activated-year { color: var(--green); font-weight: 600; }
    .nom-log-activated      { color: var(--ink-2); }
    .nom-log-none           { color: var(--ink-3); }
    .nom-log-error          { color: var(--red); }
  </style>
</head>
<body>

<nav class="topbar">
  <a href="index.php" class="topbar-logo">
    <span class="logo-mark"><img src="sota-planner-logo.svg" width="32" height="32" alt=""></span>
    <span>SOTAplanner</span>
  </a>
  <div class="topbar-divider"></div>
  <div class="topbar-nav">
    <a href="index.php">Dashboard</a>
    <a href="planning_groups.php">Manage Dashboards</a>
  </div>
  <div class="topbar-right">
    <div class="hamburger-menu" id="hamburgerMenu">
      <button type="button" class="hamburger-btn" onclick="this.parentElement.classList.toggle('open')" aria-label="Menu">
        <svg width="17" height="17" viewBox="0 0 17 17" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="2" y1="4.5" x2="15" y2="4.5"/><line x1="2" y1="8.5" x2="15" y2="8.5"/><line x1="2" y1="12.5" x2="15" y2="12.5"/></svg>
      </button>
      <div class="hamburger-dropdown">
        <a href="index.php">Dashboard</a>
        <a href="planning_groups.php">Manage Dashboards</a>
      </div>
    </div>
    <div class="user-chip" onclick="this.classList.toggle('open')" id="userChip">
      <span><?= htmlspecialchars($_SESSION['sota_callsign'] ?? '') ?></span>
      <svg class="user-chip-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none">
        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <div class="user-dropdown">
        <?php if (getCurrentCallsign() === 'KI6CR' || !empty($_SESSION['_god_mode_real_callsign'])): ?>
          <a href="god_mode.php">God Mode</a>
        <?php endif; ?>
        <a href="user_settings.php">Settings</a>
        <a href="logout.php">Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page">

  <div style="margin-bottom: 1.5rem;">
    <h1 style="font-size: 1.375rem; font-weight: 600; letter-spacing: -0.02em; color: var(--ink); margin-bottom: 0.25rem;">Nominate a Summit</h1>
    <p style="font-size: 0.875rem; color: var(--ink-3);">Add one summit or a whole list to your dashboard.</p>
  </div>

  <?php if ($message): ?>
    <div class="msg msg-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="msg msg-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="group-badge">
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><circle cx="4" cy="3.5" r="1.5" stroke="currentColor" stroke-width="1.2"/><circle cx="8" cy="3.5" r="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1 10c0-1.657 1.343-3 3-3s3 1.343 3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><path d="M8 7c1.105 0 2 1.343 2 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
    Nominating for: <strong><?= htmlspecialchars($current_group['name']) ?></strong>
  </div>

  <!-- Tabbed nominate card -->
  <div class="card" style="padding:0; overflow:hidden;">

    <div class="nom-tabs">
      <button class="nom-tab active" id="tab-area" onclick="switchTab('area')">
        <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><circle cx="6.5" cy="5.5" r="3" stroke="currentColor" stroke-width="1.4"/><path d="M6.5 12C6.5 12 2 7.5 2 5.5a4.5 4.5 0 019 0C11 7.5 6.5 12 6.5 12z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
        Bulk Search by Area
      </button>
      <button class="nom-tab" id="tab-search" onclick="switchTab('search')">
        <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><circle cx="5.5" cy="5.5" r="4" stroke="currentColor" stroke-width="1.4"/><path d="M9 9l2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        Name / Reference
      </button>
    </div>

    <form method="POST" id="nominate-form">
      <input type="hidden" name="nominate"   value="1">
      <input type="hidden" id="sota_ref"     name="sota_ref"   value="">
      <input type="hidden" id="sota_refs"    name="sota_refs"  value="">
      <input type="hidden" id="form_mode"    name="form_mode"  value="single">

      <!-- ── Tab 1: Name / Reference / Multi ───────────────────────── -->
      <div class="nom-panel" id="panel-search">
        <div class="nom-search-inner">
          <div class="search-hint-strip">Search by name · paste a reference like <code>W7O/NC-001</code> · or paste multiple refs separated by commas</div>

          <div style="margin-bottom: 1rem;">
            <label class="form-label" for="summit_search">Summit Name or Reference</label>
            <input
              type="text"
              class="form-input"
              id="summit_search"
              placeholder="Mount Adams — or W7O/NC-001 — or W7O/NC-001, W7O/NC-002"
              autocomplete="off"
            >
            <div class="form-hint" id="search-hint">Type a name to search, or paste a SOTA reference. Separate multiple references with commas.</div>
          </div>

          <div id="search-results" style="display:none; margin-bottom:1rem;"></div>

          <div class="selected-box" id="selected-summit">
            <div class="selected-label">Selected Summit</div>
            <div class="selected-row">
              <div>
                <span class="selected-name" id="selected-name"></span>
                <span class="selected-ref"  id="selected-ref"></span>
              </div>
              <button type="button" class="clear-btn" onclick="clearSelection()">✕</button>
            </div>
          </div>

          <button type="submit" id="nominate-btn" class="btn btn-primary" disabled>Nominate Summit</button>
        </div>
      </div>

      <!-- ── Tab 2: Area Search ─────────────────────────────────────── -->
      <div class="nom-panel active" id="panel-area">

        <!-- Controls row — always above the two-column body -->
        <div style="display:flex; gap:0.75rem; margin-bottom:0.875rem; align-items:flex-end; flex-wrap:wrap;">
          <div style="flex:1; min-width:180px;">
            <label class="form-label" for="area_location">Location</label>
            <input type="text" class="form-input" id="area_location" placeholder="Bend, OR — or 97401 — or Crater Lake" autocomplete="off" autofocus>
          </div>
          <div>
            <label class="form-label">Radius</label>
            <div style="display:flex; align-items:center; gap:0.4rem;">
              <input type="number" class="form-input" id="area_radius" value="25" min="1" max="300" style="width:72px;">
              <span style="font-size:0.875rem; color:var(--ink-3); white-space:nowrap;"><?= ($current_group['units'] ?? 'imperial') === 'metric' ? 'km' : 'miles' ?></span>
            </div>
          </div>
          <div>
            <label class="form-label" for="area_min_pts">Min. pts</label>
            <select class="form-input" id="area_min_pts" style="width:auto;">
              <option value="1">Any</option>
              <option value="2">2+</option>
              <option value="4">4+</option>
              <option value="6">6+</option>
              <option value="8">8+</option>
              <option value="10">10</option>
            </select>
          </div>
          <div>
            <button type="button" class="btn btn-primary" id="area_search_btn" style="width:auto;" onclick="doAreaSearch()">Search</button>
          </div>
        </div>
        <div class="form-hint" style="margin-bottom:1rem;">Any location Google Maps recognizes — city, zip code, address, or landmark.</div>

        <!-- Two-column body: results list (left) + map (right) -->
        <div class="area-body">
          <div class="area-results-col">
            <div id="area_results">
              <div id="area_results_placeholder" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.75rem; padding:3rem 1.5rem; color:var(--ink-4); text-align:center; border:1px solid var(--border); border-radius:var(--r-md); background:var(--bg);">
                <img src="sota-planner-logo.svg" width="48" height="48" alt="" style="opacity:0.25;">
                <div style="font-size:0.9rem; font-weight:600; color:var(--ink-3);">Summit List</div>
                <div style="font-size:0.8rem; color:var(--ink-4);">Search a location to see summits in the area</div>
              </div>
            </div>
          </div>
          <div class="area-map-col">
            <div id="area_map"></div>
          </div>
        </div>

      </div>

    </form>
  </div>

</div>

<!-- Bulk nomination progress overlay (hidden until bulk submit) -->
<div id="nom-progress-overlay" style="display:none;">
  <div class="nom-progress-card">
    <svg class="nom-loading-svg" viewBox="0 0 110 110" xmlns="http://www.w3.org/2000/svg">
      <circle cx="55" cy="55" r="50" fill="none" stroke="#1c1b19" stroke-width="1.5" opacity="0.2"/>
      <path class="nom-logo-path" d="M26,79.5l17-30,7,8,12-20,22,42"
            fill="none" stroke="#1c1b19" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      <circle class="nom-logo-ring nom-logo-ring2" cx="62" cy="35.5" r="15" fill="none" stroke="var(--red)" stroke-width="0.8"/>
      <circle class="nom-logo-ring nom-logo-ring1" cx="62" cy="35.5" r="9"  fill="none" stroke="var(--red)" stroke-width="1.2"/>
      <circle class="nom-logo-dot" cx="62" cy="35.5" r="3.5"/>
    </svg>
    <div style="font-size:1.1rem; font-weight:600; color:var(--ink);">Adding summits to your group</div>
    <div id="nom-current" style="font-size:0.875rem; color:var(--ink-2); min-height:1.25em;">Preparing…</div>
    <div class="nom-progress-bar-track">
      <div class="nom-progress-bar-fill" id="nom-bar"></div>
    </div>
    <div id="nom-counter" style="font-size:0.8rem; font-weight:600; color:var(--ink-3);">Starting…</div>
    <div id="nom-log" style="width:100%; max-height:164px; overflow-y:auto; text-align:left; border:1px solid var(--border); border-radius:var(--r-md); padding:0.4rem 0.625rem; display:none;"></div>
  </div>
</div>

<!-- Starting point prompt (shown when a bulk nomination has no dashboard address to calculate travel time from) -->
<div id="sp-modal" style="display:none; position:fixed; inset:0; z-index:9100; background:rgba(20,19,18,0.55); align-items:center; justify-content:center;">
  <div class="nom-progress-card" style="align-items:stretch; text-align:left; gap:1rem;">
    <div style="text-align:center;">
      <div style="font-size:1.1rem; font-weight:600; color:var(--ink);">Add a travel starting point?</div>
      <div style="font-size:0.85rem; color:var(--ink-3); margin-top:0.35rem; line-height:1.4;">
        This dashboard doesn't have a starting point yet, so travel time couldn't be calculated for the summits you just added.
        It doesn't need to be a precise address — anything Google can search for works: a town, a cross-street intersection, a trailhead name, or a nearby landmark.
      </div>
    </div>
    <div>
      <label class="form-label" for="sp-input">Starting Point</label>
      <input type="text" id="sp-input" class="form-input" placeholder="e.g. Pasadena, CA — or Foothill &amp; Lake — or Descanso Gardens">
      <div id="sp-error" class="form-hint" style="display:none; color:var(--red);"></div>
    </div>
    <button type="button" id="sp-save-btn" class="btn btn-primary">Save &amp; Continue</button>
    <button type="button" id="sp-later-btn" style="background:none; border:none; color:var(--ink-3); font-size:0.825rem; font-family:var(--font-sans); cursor:pointer; padding:0.25rem;">I'll do this later</button>
  </div>
</div>

<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:var(--ink-4); font-size:0.78rem;">
  SOTA Planner &nbsp;·&nbsp;
  <a href="changelog.php" style="color:var(--ink-4); text-decoration:none;">v<?= APP_VERSION ?></a>
  &nbsp;·&nbsp;
  <a href="https://sotaplanner.com" style="color:var(--ink-4); text-decoration:none;">sotaplanner.com</a>
</footer>

<script>
document.addEventListener('click', function(e) {
  var chip = document.getElementById('userChip');
  if (chip && !chip.contains(e.target)) chip.classList.remove('open');
  var menu = document.getElementById('hamburgerMenu');
  if (menu && !menu.contains(e.target)) menu.classList.remove('open');
});

// ── Tabs ──────────────────────────────────────────────────────────────────────
let mapsApiLoaded = false;
let areaMap = null, areaCircle = null, areaMarkers = [], summitMarkerMap = {};
let areaClickInfoWindow = null, lastSelectedAreaRow = null;

function switchTab(tab) {
    document.querySelectorAll('.nom-tab').forEach(function(t) {
        t.classList.toggle('active', t.id === 'tab-' + tab);
    });
    document.querySelectorAll('.nom-panel').forEach(function(p) {
        p.classList.toggle('active', p.id === 'panel-' + tab);
    });
    if (tab === 'area' && !mapsApiLoaded) {
        mapsApiLoaded = true;
        const s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=<?= defined("GOOGLE_MAPS_BROWSER_KEY") ? GOOGLE_MAPS_BROWSER_KEY : "" ?>&callback=initAreaMap';
        s.async = true;
        document.head.appendChild(s);
    }
}

window.initAreaMap = function() {
    const preload = window._dashPreload;
    const mapDiv = document.getElementById('area_map');
    mapDiv.style.display = 'block';
    areaMap = new google.maps.Map(mapDiv, {
        center: preload ? { lat: preload.lat, lng: preload.lng } : { lat: 39.5, lng: -98.5 },
        zoom: preload ? 9 : 4,
        mapTypeId: 'terrain',
        disableDefaultUI: true,
        zoomControl: true,
        gestureHandling: 'cooperative',
    });
    setTimeout(function() { google.maps.event.trigger(areaMap, 'resize'); }, 50);

    // Clicking anywhere on the map (not on a summit marker) offers to re-center
    // the search there. Marker clicks are handled separately and don't bubble here.
    areaMap.addListener('click', function(e) {
        showAreaClickPopup(e.latLng);
    });

    if (preload) {
        setTimeout(function() { searchAtLatLng(preload.lat, preload.lng); }, 200);
    }
};

function showAreaClickPopup(latLng) {
    if (areaClickInfoWindow) areaClickInfoWindow.close();
    const lat = latLng.lat(), lng = latLng.lng();

    const div = document.createElement('div');
    div.className = 'area-click-popup';
    div.innerHTML = '<button type="button" class="area-click-popup-btn">Search Here</button>';
    div.querySelector('.area-click-popup-btn').addEventListener('click', function() {
        areaClickInfoWindow.close();
        searchAtLatLng(lat, lng);
    });

    areaClickInfoWindow = new google.maps.InfoWindow({
        position: { lat: lat, lng: lng },
        content: div,
    });
    areaClickInfoWindow.open(areaMap);
}

function searchAtLatLng(lat, lng) {
    // Show coordinates in location field and run the search directly against
    // the lat/lng endpoint, avoiding a redundant geocode round-trip.
    document.getElementById('area_location').value = lat.toFixed(4) + ', ' + lng.toFixed(4);
    const radiusInput = parseFloat(document.getElementById('area_radius').value) || 25;
    const minPts = parseInt(document.getElementById('area_min_pts').value) || 1;
    const radius_mi = useMetric ? radiusInput * 0.621371 : radiusInput;
    const resultsDiv = document.getElementById('area_results');
    const btn = document.getElementById('area_search_btn');
    resultsDiv.innerHTML = '<div class="search-status">Searching…</div>';
    btn.disabled = true;
    fetch('nominate.php?action=radius_search&lat=' + lat + '&lng=' + lng + '&radius_mi=' + radius_mi.toFixed(2) + '&min_pts=' + minPts)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.error) {
                resultsDiv.innerHTML = '<div class="msg msg-error">' + escHtml(data.error) + '</div>';
                return;
            }
            renderAreaResults(data);
        })
        .catch(function() {
            btn.disabled = false;
            resultsDiv.innerHTML = '<div class="msg msg-error">Search failed. Please try again.</div>';
        });
}

function updateAreaMap(data) {
    if (!areaMap) return;
    const mapDiv = document.getElementById('area_map');
    mapDiv.style.display = 'block';

    areaMarkers.forEach(function(m) { m.setMap(null); });
    areaMarkers = [];
    summitMarkerMap = {};
    if (areaCircle) areaCircle.setMap(null);

    const center = { lat: data.lat, lng: data.lon };

    areaCircle = new google.maps.Circle({
        map: areaMap,
        center: center,
        radius: data.radius * 1609.34,
        strokeColor: '#CC2222',
        strokeOpacity: 0.85,
        strokeWeight: 2,
        fillColor: '#CC2222',
        fillOpacity: 0.07,
    });

    areaMarkers.push(new google.maps.Marker({
        map: areaMap,
        position: center,
        title: data.label,
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 7,
            fillColor: '#1C1B19',
            fillOpacity: 1,
            strokeColor: '#fff',
            strokeWeight: 2,
        }
    }));

    (data.summits || []).forEach(function(s) {
        if (!s.lat || !s.lon) return;
        const marker = new google.maps.Marker({
            map: areaMap,
            position: { lat: s.lat, lng: s.lon },
            title: s.name + ' (' + s.ref + ', ' + s.points + 'pt)',
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 5,
                fillColor: s.nominated ? '#B8B5B0' : '#D4A574',
                fillOpacity: 0.9,
                strokeColor: '#fff',
                strokeWeight: 1.5,
            }
        });
        marker.addListener('click', function() { selectSummitFromMap(s.ref); });
        areaMarkers.push(marker);
        summitMarkerMap[s.ref] = { marker: marker, nominated: !!s.nominated };
    });

    // Defer resize+fitBounds so the browser reflows the container first,
    // otherwise the Maps canvas stays at its old pixel dimensions.
    setTimeout(function() {
        google.maps.event.trigger(areaMap, 'resize');
        areaMap.fitBounds(areaCircle.getBounds());
    }, 50);
}

function highlightSummitMarker(ref) {
    const entry = summitMarkerMap[ref];
    if (!entry) return;
    entry.marker.setIcon({
        path: google.maps.SymbolPath.CIRCLE,
        scale: 10,
        fillColor: '#E6B84A',
        fillOpacity: 1,
        strokeColor: '#fff',
        strokeWeight: 2.5,
    });
    entry.marker.setZIndex(999);
}

function unhighlightSummitMarker(ref) {
    const entry = summitMarkerMap[ref];
    if (!entry) return;
    entry.marker.setIcon({
        path: google.maps.SymbolPath.CIRCLE,
        scale: 5,
        fillColor: entry.nominated ? '#B8B5B0' : '#D4A574',
        fillOpacity: 0.9,
        strokeColor: '#fff',
        strokeWeight: 1.5,
    });
    entry.marker.setZIndex(null);
}

function selectSummitFromMap(ref) {
    const cb = document.querySelector('#area_list .area-chk[data-ref="' + CSS.escape(ref) + '"]');
    if (!cb) return;
    const row = cb.closest('label');
    if (!row) return;

    if (lastSelectedAreaRow && lastSelectedAreaRow !== row) {
        lastSelectedAreaRow.classList.remove('area-row-selected', 'area-row-pulse');
    }

    row.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Restart the pulse animation even if this row was already selected.
    row.classList.remove('area-row-pulse');
    void row.offsetWidth;
    row.classList.add('area-row-pulse', 'area-row-selected');
    lastSelectedAreaRow = row;
}

// ── Elements ─────────────────────────────────────────────────────────────────
const searchInput  = document.getElementById('summit_search');
const refInput     = document.getElementById('sota_ref');
const refsInput    = document.getElementById('sota_refs');
const formMode     = document.getElementById('form_mode');
const resultsBox   = document.getElementById('search-results');
const selectedBox  = document.getElementById('selected-summit');
const selectedName = document.getElementById('selected-name');
const selectedRef  = document.getElementById('selected-ref');
const nominateBtn  = document.getElementById('nominate-btn');
const searchHint   = document.getElementById('search-hint');

// Matches a complete SOTA reference like W6/CT-225 or W7O/NC-001
const refPattern = /^[A-Za-z0-9]{1,6}\/[A-Za-z0-9]{1,6}-\d{3,}$/;

let debounceTimer = null;
let currentMode   = 'single'; // 'single' | 'batch'

// ── Input handler ─────────────────────────────────────────────────────────────
searchInput.addEventListener('input', function() {
    const val = this.value;
    clearTimeout(debounceTimer);

    // Batch mode: input contains a comma
    if (val.includes(',')) {
        setSingleMode(false);
        const rawRefs = val.split(',').map(r => r.trim()).filter(r => r.length > 0);
        if (rawRefs.length === 0) { hideResults(); setNominateEnabled(false); return; }
        searchHint.textContent = 'Checking ' + rawRefs.length + ' reference' + (rawRefs.length !== 1 ? 's' : '') + '…';
        debounceTimer = setTimeout(() => doBatchLookup(rawRefs), 500);
        return;
    }

    // Single mode
    setSingleMode(true);

    if (!val.trim()) {
        hideResults();
        setNominateEnabled(false);
        searchHint.textContent = 'Type a summit name to search, or enter a SOTA reference directly (e.g., W6/CT-225).';
        return;
    }

    if (refPattern.test(val.trim())) {
        // Looks like a direct reference — select it immediately
        hideResults();
        const ref = val.trim().toUpperCase();
        refInput.value = ref;
        selectedName.textContent = ref;
        selectedRef.textContent  = '';
        selectedBox.style.display = 'block';
        setNominateEnabled(true);
        searchHint.textContent = 'Looking up summit name…';

        fetch('nominate.php?action=search&q=' + encodeURIComponent(ref))
            .then(r => r.json())
            .then(data => {
                if (Array.isArray(data) && data.length > 0) {
                    const match = data.find(s => s.ref.toUpperCase() === ref) || data[0];
                    if (match) {
                        selectedName.textContent = match.name;
                        selectedRef.textContent  = ref;
                    }
                }
                searchHint.textContent = 'Looks like a SOTA reference — ready to nominate.';
            })
            .catch(() => {
                searchHint.textContent = 'Looks like a SOTA reference — ready to nominate.';
            });
        return;
    }

    if (val.trim().length < 2) return;

    searchHint.textContent = 'Searching…';
    debounceTimer = setTimeout(() => doSearch(val.trim()), 380);
});

// ── Single mode search ────────────────────────────────────────────────────────
function doSearch(q) {
    resultsBox.style.display = 'block';
    resultsBox.innerHTML = '<div class="search-status">Searching summit cache…</div>';

    fetch('nominate.php?action=search&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                resultsBox.innerHTML = '<div class="search-status">' + data.error + '</div>';
                searchHint.textContent = '';
                return;
            }
            if (!Array.isArray(data) || data.length === 0) {
                resultsBox.innerHTML = '<div class="search-status">No summits found matching "' + escHtml(q) + '".</div>';
                searchHint.textContent = 'Try different spelling or enter a SOTA reference directly.';
                return;
            }
            renderResults(data, q);
        })
        .catch(() => {
            resultsBox.innerHTML = '<div class="search-status">Search failed. Try entering a SOTA reference directly.</div>';
        });
}

function renderResults(results, q) {
    const more = results.length === 20 ? ' (showing top 20)' : '';
    let html = '<div class="search-count">' + results.length + ' summit' + (results.length !== 1 ? 's' : '') + ' found' + more + '</div>';
    results.forEach(s => {
        const alt = s.altFt ? (s.altFt.toLocaleString() + ' ft') : '';
        html += `<div class="result-item" onclick="selectSummit('${escAttr(s.ref)}','${escAttr(s.name)}')">
            <div>
                <div class="result-name">${escHtml(s.name)}</div>
                <div class="result-ref">${escHtml(s.ref)}</div>
            </div>
            <div class="result-meta">
                <div class="result-pts">${s.points} pt${s.points !== 1 ? 's' : ''}</div>
                ${alt ? '<div>' + alt + '</div>' : ''}
            </div>
        </div>`;
    });
    resultsBox.innerHTML = html;
    searchHint.textContent = 'Click a summit to select it.';
}

function selectSummit(ref, name) {
    refInput.value = ref;
    selectedName.textContent = name;
    selectedRef.textContent  = ref !== name ? ref : '';
    selectedBox.style.display = 'block';
    hideResults();
    setNominateEnabled(true);
    searchHint.textContent = '';
}

function clearSelection() {
    refInput.value = '';
    selectedBox.style.display = 'none';
    setNominateEnabled(false);
    searchInput.value = '';
    searchInput.focus();
    searchHint.textContent = 'Type a summit name to search, or enter a SOTA reference directly (e.g., W6/CT-225).';
}

// ── Batch mode ────────────────────────────────────────────────────────────────
async function doBatchLookup(rawRefs) {
    resultsBox.style.display = 'block';
    resultsBox.innerHTML = '<div class="search-status">Checking ' + rawRefs.length + ' reference' + (rawRefs.length !== 1 ? 's' : '') + '…</div>';

    const lookups = rawRefs.map(async (rawRef) => {
        const ref = rawRef.toUpperCase();
        if (!refPattern.test(ref)) {
            return { ref, found: false, invalid: true };
        }
        try {
            const data = await fetch('nominate.php?action=search&q=' + encodeURIComponent(ref)).then(r => r.json());
            const match = Array.isArray(data) ? data.find(s => s.ref.toUpperCase() === ref) : null;
            return { ref, found: !!match, summit: match || null };
        } catch {
            return { ref, found: false, error: true };
        }
    });

    const results = await Promise.all(lookups);
    renderBatchResults(results);
}

function renderBatchResults(results) {
    const found    = results.filter(r => r.found);
    const notFound = results.filter(r => !r.found);

    let html = '<div class="batch-summary">';
    html += found.length + ' of ' + results.length + ' summit' + (results.length !== 1 ? 's' : '') + ' found';
    if (notFound.length > 0) html += ' &nbsp;·&nbsp; ' + notFound.length + ' not found';
    html += '</div>';

    results.forEach(r => {
        if (r.found) {
            const pts = r.summit.points;
            html += `<div class="batch-item batch-item-found">
                <div class="batch-item-icon">
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <circle cx="8" cy="8" r="7" fill="var(--green)" fill-opacity="0.15" stroke="var(--green)" stroke-width="1.2"/>
                    <path d="M5 8l2.2 2.2L11 5.5" stroke="var(--green)" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </div>
                <div class="batch-item-info">
                  <div class="batch-item-name">${escHtml(r.summit.name)}</div>
                  <div class="batch-item-ref">${escHtml(r.ref)}</div>
                </div>
                <div class="batch-item-pts">${pts} pt${pts !== 1 ? 's' : ''}</div>
            </div>`;
        } else {
            const label = r.invalid ? 'Invalid format' : 'Not found';
            html += `<div class="batch-item batch-item-notfound">
                <div class="batch-item-icon">
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <circle cx="8" cy="8" r="7" fill="var(--red)" fill-opacity="0.12" stroke="var(--red)" stroke-width="1.2"/>
                    <path d="M5.5 10.5l5-5M10.5 10.5l-5-5" stroke="var(--red)" stroke-width="1.4" stroke-linecap="round"/>
                  </svg>
                </div>
                <div class="batch-item-info">
                  <div class="batch-item-ref" style="color:var(--ink-2)">${escHtml(r.ref)}</div>
                  <div class="batch-item-status">${label}</div>
                </div>
            </div>`;
        }
    });

    resultsBox.innerHTML = html;
    resultsBox.style.display = 'block';

    // Wire up form for batch submit
    refsInput.value = found.map(r => r.ref).join(',');
    formMode.value  = 'bulk';

    if (found.length > 0) {
        setNominateEnabled(true);
        nominateBtn.textContent = found.length === 1
            ? 'Nominate 1 Summit'
            : 'Nominate ' + found.length + ' Summits';
        searchHint.textContent = '';
    } else {
        setNominateEnabled(false);
        nominateBtn.textContent = 'Nominate Summit';
        searchHint.textContent = 'None of the references were found. Check the format: ASSOC/CODE-NNN (e.g. W7O/NC-001).';
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setSingleMode(on) {
    if (on) {
        currentMode = 'single';
        formMode.value = 'single';
        nominateBtn.textContent = 'Nominate Summit';
        selectedBox.style.display = 'none';
    } else {
        currentMode = 'batch';
        selectedBox.style.display = 'none';
        refInput.value = '';
    }
}

function hideResults() { resultsBox.style.display = 'none'; resultsBox.innerHTML = ''; }
function setNominateEnabled(on) { nominateBtn.disabled = !on; nominateBtn.style.opacity = on ? '1' : '0.45'; }

function escHtml(s)  { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s)  { return String(s).replace(/'/g,"\\'").replace(/"/g,'&quot;'); }

// ── Form submit validation ────────────────────────────────────────────────────
document.getElementById('nominate-form').addEventListener('submit', function(e) {
    if (formMode.value === 'bulk') {
        e.preventDefault();
        const refs = refsInput.value.split(',').map(r => r.trim()).filter(r => r);
        if (refs.length === 0) { alert('No valid summits to nominate.'); return; }
        runBulkNominateFlow(refs);
    } else {
        if (!refInput.value.trim()) {
            e.preventDefault();
            alert('Please select a summit first.');
        }
    }
});

// ── Bulk AJAX nomination flow ─────────────────────────────────────────────────
async function runBulkNominateFlow(refs) {
    const overlay   = document.getElementById('nom-progress-overlay');
    const barEl     = document.getElementById('nom-bar');
    const counterEl = document.getElementById('nom-counter');
    const currentEl = document.getElementById('nom-current');
    const logEl     = document.getElementById('nom-log');
    const groupId   = <?= (int)($current_group['id'] ?? 0) ?>;
    const BATCH     = 5;

    overlay.style.display = 'flex';
    logEl.style.display = refs.length > 1 ? 'block' : 'none';

    let done = 0, newIds = [], activatedThisYear = 0;

    async function nominateOne(ref) {
        try {
            const resp = await fetch('nominate.php?action=nominate_one&ref=' + encodeURIComponent(ref));
            return await resp.json();
        } catch (_) {
            return { error: 'Network error', ref };
        }
    }

    function addLogEntry(ref, data) {
        const entry = document.createElement('div');
        entry.className = 'nom-log-entry';
        if (data && data.success) {
            if (data.id) newIds.push(data.id);
            if (data.activated_this_year) {
                activatedThisYear++;
                entry.className += ' nom-log-activated-year';
                entry.textContent = '★ ' + (data.name || ref) + ' — activated ' + data.last_activated_date + ' by ' + data.last_activated_by;
            } else if (data.last_activated_date) {
                entry.className += ' nom-log-activated';
                entry.textContent = '✓ ' + (data.name || ref) + ' — last activated ' + data.last_activated_date;
            } else {
                entry.className += ' nom-log-none';
                entry.textContent = '✓ ' + (data.name || ref);
            }
        } else {
            entry.className += ' nom-log-error';
            entry.textContent = '✗ ' + ref + (data && data.error ? ' — ' + data.error : '');
        }
        logEl.insertBefore(entry, logEl.firstChild);
    }

    for (let i = 0; i < refs.length; i += BATCH) {
        const batch = refs.slice(i, i + BATCH);
        const batchEnd = Math.min(i + BATCH, refs.length);
        currentEl.textContent = 'Adding ' + (i + 1) + '–' + batchEnd + ' of ' + refs.length + '…';

        const results = await Promise.all(batch.map(ref => nominateOne(ref)));

        results.forEach((data, j) => addLogEntry(batch[j], data));
        done += batch.length;
        barEl.style.width = Math.round((done / refs.length) * 100) + '%';
        counterEl.textContent = done + ' of ' + refs.length;
    }

    // ── Auto-calculate travel time for newly nominated summits with a starting point ──
    let driveMsg = '';
    if (newIds.length > 0) {
        currentEl.textContent = 'Checking travel times…';
        try {
            const dt = await (await fetch('nominate.php?action=bulk_drive_times&ids=' + newIds.join(','))).json();
            if (dt.need_address) {
                overlay.style.display = 'none';
                const saved = await promptForStartingPoint();
                overlay.style.display = 'flex';
                if (saved) {
                    currentEl.textContent = 'Calculating travel times…';
                    const dt2 = await (await fetch('nominate.php?action=bulk_drive_times&ids=' + newIds.join(','))).json();
                    if (dt2.updated > 0) driveMsg = ' • travel time added for ' + dt2.updated + ' summit' + (dt2.updated !== 1 ? 's' : '');
                }
            } else if (dt.updated > 0) {
                driveMsg = ' • travel time added for ' + dt.updated + ' summit' + (dt.updated !== 1 ? 's' : '');
            }
        } catch (_) { /* non-fatal — continue to dashboard either way */ }
    }

    let doneMsg = done + ' summit' + (done !== 1 ? 's' : '') + ' added';
    if (activatedThisYear > 0) doneMsg += ' • ' + activatedThisYear + ' activated by your group this year';
    doneMsg += driveMsg;
    currentEl.textContent = '✓ Done! Loading dashboard…';
    counterEl.textContent = doneMsg;
    barEl.style.width = '100%';

    const idsParam = newIds.length ? '&new_ids=' + newIds.join(',') : '';
    setTimeout(function() {
        window.location.href = 'index.php?group=' + groupId + '&bulk_nominated=' + done + idsParam;
    }, 1000);
}

// ── Starting point prompt (used by runBulkNominateFlow when no address exists yet) ──
function promptForStartingPoint() {
    return new Promise(function(resolve) {
        const modal    = document.getElementById('sp-modal');
        const input    = document.getElementById('sp-input');
        const errEl    = document.getElementById('sp-error');
        const saveBtn  = document.getElementById('sp-save-btn');
        const laterBtn = document.getElementById('sp-later-btn');

        input.value = '';
        errEl.style.display = 'none';
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save & Continue';
        modal.style.display = 'flex';
        input.focus();

        function cleanup() {
            modal.style.display = 'none';
            saveBtn.onclick = null;
            laterBtn.onclick = null;
            input.onkeydown = null;
        }

        async function doSave() {
            const loc = input.value.trim();
            if (!loc) {
                errEl.textContent = 'Please enter a starting point.';
                errEl.style.display = 'block';
                return;
            }
            saveBtn.disabled = true;
            saveBtn.textContent = 'Checking…';
            errEl.style.display = 'none';
            try {
                const data = await (await fetch('nominate.php?action=add_starting_point&location=' + encodeURIComponent(loc))).json();
                if (data.success) {
                    cleanup();
                    resolve(true);
                } else {
                    errEl.textContent = data.error || 'Could not add that starting point.';
                    errEl.style.display = 'block';
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save & Continue';
                }
            } catch (_) {
                errEl.textContent = 'Network error — please try again.';
                errEl.style.display = 'block';
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save & Continue';
            }
        }

        saveBtn.onclick = doSave;
        laterBtn.onclick = function() { cleanup(); resolve(false); };
        input.onkeydown = function(e) { if (e.key === 'Enter') doSave(); };
    });
}

// ── Area search ───────────────────────────────────────────────────────────────
const useMetric = <?= json_encode(($current_group['units'] ?? 'imperial') === 'metric') ?>;

// Area tab is the default. ?tab=search opens the Name/Reference tab instead;
// ?tab=area&lat=X&lng=Y (from a dashboard map link) preloads a location.
(function() {
    const params = new URLSearchParams(window.location.search);
    const preloadLat = parseFloat(params.get('lat'));
    const preloadLng = parseFloat(params.get('lng'));
    if (params.get('tab') === 'search') {
        switchTab('search');
    } else {
        switchTab('area');
        if (!isNaN(preloadLat) && !isNaN(preloadLng)) {
            // Store coords for initAreaMap to pick up after Maps API loads
            window._dashPreload = { lat: preloadLat, lng: preloadLng };
        }
    }
})();

document.getElementById('area_location').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); doAreaSearch(); }
});
document.getElementById('area_radius').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); doAreaSearch(); }
});

function doAreaSearch() {
    const location   = document.getElementById('area_location').value.trim();
    const radiusInput = parseFloat(document.getElementById('area_radius').value) || 25;
    const minPts      = parseInt(document.getElementById('area_min_pts').value) || 1;
    const radius_mi   = useMetric ? radiusInput * 0.621371 : radiusInput;

    if (!location) { document.getElementById('area_location').focus(); return; }

    const resultsDiv = document.getElementById('area_results');
    const btn        = document.getElementById('area_search_btn');
    resultsDiv.innerHTML = '<div class="search-status">Searching…</div>';
    btn.disabled = true;

    fetch('nominate.php?action=radius_search&location=' + encodeURIComponent(location) + '&radius_mi=' + radius_mi.toFixed(2) + '&min_pts=' + minPts)
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.error) {
                resultsDiv.innerHTML = '<div class="msg msg-error">' + escHtml(data.error) + '</div>';
                return;
            }
            renderAreaResults(data);
        })
        .catch(() => {
            btn.disabled = false;
            resultsDiv.innerHTML = '<div class="msg msg-error">Search failed. Please try again.</div>';
        });
}

function renderAreaResults(data) {
    const summits    = data.summits || [];
    const resultsDiv = document.getElementById('area_results');
    const unitsLabel = useMetric ? 'km' : 'mi';
    lastSelectedAreaRow = null;

    // Always update the map when we have a geocoded location
    updateAreaMap(data);

    if (summits.length === 0) {
        const r = useMetric ? (data.radius * 1.60934).toFixed(0) : data.radius.toFixed(0);
        resultsDiv.innerHTML = '<div class="search-status">No summits found within ' + r + ' ' + unitsLabel + ' of <strong>' + escHtml(data.label) + '</strong>. Try a larger radius or fewer point filters.</div>';
        return;
    }

    const newCount = summits.filter(s => !s.nominated).length;

    let html = '<div style="font-size:0.8rem; color:var(--ink-3); margin-bottom:0.6rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">';
    html += '<div><strong style="color:var(--ink);">' + summits.length + '</strong> summit' + (summits.length !== 1 ? 's' : '') + ' near <strong style="color:var(--ink);">' + escHtml(data.label) + '</strong>';
    if (newCount < summits.length) html += ' &nbsp;·&nbsp; <span style="color:var(--green);">' + newCount + ' new to your group</span>';
    html += '</div>';
    html += '<div style="display:flex; gap:0.75rem;">';
    html += '<button type="button" onclick="areaSelectAll(true)" style="background:none; border:none; font-size:0.75rem; color:var(--accent); cursor:pointer; font-family:var(--font-sans); padding:0;">Select all</button>';
    html += '<button type="button" onclick="areaSelectAll(false)" style="background:none; border:none; font-size:0.75rem; color:var(--ink-3); cursor:pointer; font-family:var(--font-sans); padding:0;">None</button>';
    html += '</div></div>';

    html += '<button type="button" class="btn btn-primary" id="area_nominate_btn" onclick="submitAreaSelection()" style="height:40px; width:100%; margin-bottom:0.75rem;" disabled>Nominate 0 Summits</button>';

    html += '<div id="area_list" style="max-height:400px; overflow-y:auto; border:1px solid var(--border); border-radius:var(--r-md); margin-bottom:0.875rem;">';

    summits.forEach(function(s) {
        const checked  = !s.nominated;
        const dist     = useMetric ? (s.dist_mi * 1.60934).toFixed(1) : s.dist_mi.toFixed(1);
        const alt      = s.altFt ? (useMetric ? Math.round(s.altFt * 0.3048).toLocaleString() + ' m' : s.altFt.toLocaleString() + ' ft') : '';
        const inGroup  = s.nominated ? ' <span style="font-size:0.65rem; background:var(--bg-2); border:1px solid var(--border-2); border-radius:3px; padding:1px 5px; color:var(--ink-3); font-weight:600; vertical-align:middle;">In group</span>' : '';

        html += '<label style="display:flex; align-items:center; gap:0.75rem; padding:0.55rem 0.875rem; cursor:' + (s.nominated ? 'default' : 'pointer') + '; border-bottom:1px solid var(--border); background:' + (s.nominated ? 'var(--bg)' : 'var(--surface)') + ';" onmouseenter="highlightSummitMarker(\'' + escAttr(s.ref) + '\')" onmouseleave="unhighlightSummitMarker(\'' + escAttr(s.ref) + '\')">';
        html += '<input type="checkbox" class="area-chk" data-ref="' + escAttr(s.ref) + '" ' + (checked ? 'checked' : '') + ' ' + (s.nominated ? 'disabled' : '') + ' onchange="updateAreaBtn()" style="width:15px; height:15px; flex-shrink:0; cursor:' + (s.nominated ? 'default' : 'pointer') + ';">';
        html += '<div style="flex:1; min-width:0;">';
        html += '<div style="font-size:0.8375rem; font-weight:600; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + escHtml(s.name) + inGroup + '</div>';
        html += '<div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--ink-3);">' + escHtml(s.ref) + '</div>';
        html += '</div>';
        html += '<div style="text-align:right; flex-shrink:0; font-size:0.775rem; line-height:1.4;">';
        html += '<div style="font-weight:700; color:var(--accent);">' + s.points + ' pt' + (s.points !== 1 ? 's' : '') + '</div>';
        html += '<div style="color:var(--ink-3);">' + dist + ' ' + unitsLabel + '</div>';
        if (alt) html += '<div style="color:var(--ink-4);">' + alt + '</div>';
        html += '</div>';
        html += '</label>';
    });

    html += '</div>';

    resultsDiv.innerHTML = html;
    updateAreaBtn();
}

function areaSelectAll(on) {
    document.querySelectorAll('.area-chk:not(:disabled)').forEach(function(cb) { cb.checked = on; });
    updateAreaBtn();
}

function updateAreaBtn() {
    const checked = Array.from(document.querySelectorAll('.area-chk:checked')).map(cb => cb.dataset.ref);
    const btn = document.getElementById('area_nominate_btn');
    if (!btn) return;
    btn.disabled = checked.length === 0;
    btn.textContent = checked.length === 0
        ? 'Nominate 0 Summits'
        : 'Nominate ' + checked.length + ' Summit' + (checked.length !== 1 ? 's' : '');
}

function submitAreaSelection() {
    const checked = Array.from(document.querySelectorAll('.area-chk:checked')).map(function(cb) { return cb.dataset.ref; });
    if (checked.length === 0) return;
    runBulkNominateFlow(checked);
}
</script>
</body>
</html>
