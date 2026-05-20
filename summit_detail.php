<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();

// Migrate: add track_type column if missing
try {
    $db->exec("ALTER TABLE gpx_tracks ADD COLUMN track_type VARCHAR(20) NOT NULL DEFAULT 'round-trip'");
} catch (PDOException $e) { /* already exists */ }

// Handle group parameter from URL (for shareable links)
if (isset($_GET['group'])) {
    $group_id = (int)$_GET['group'];
    // Validate group exists
    $stmt = $db->prepare("SELECT id FROM planning_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    if ($stmt->fetch()) {
        setCurrentPlanningGroup($group_id);
    }
}

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$summit_id = (int)$_GET['id'];
$message = '';
$error = '';

// Get current planning group (needed for all handlers)
$current_group = getCurrentPlanningGroup($db);

// Check for success messages
if (isset($_GET['nominated'])) {
    $message = "✓ Summit nominated! Use the tools below to add trail info and import a GPS track.";
}
if (isset($_GET['saved'])) {
    $message = "Summit updated successfully!";
}
if (isset($_GET['geocoded'])) {
    $message = $_GET['geocoded'];
}


// Quick summit/group name lookup used for activity logging on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_FILES['gpx_file'])) {
    $_ls = $db->prepare("SELECT s.name, s.sota_ref, pg.name AS grp FROM summits s LEFT JOIN planning_groups pg ON s.planning_group_id = pg.id WHERE s.id = ?");
    $_ls->execute([$summit_id]);
    $_li = $_ls->fetch() ?: [];
    $log_summit_name = $_li['name']     ?? "Summit #$summit_id";
    $log_sota_ref    = $_li['sota_ref'] ?? '';
    $log_group_name  = $_li['grp']      ?? ($current_group['name'] ?? '');
    unset($_ls, $_li);
}

// ========== GPX UPLOAD HANDLER ==========
if (isset($_FILES['gpx_file']) && $_FILES['gpx_file']['error'] === UPLOAD_ERR_OK) {
    $error_msg = '';
    
    // Current group already loaded at top
    if ($current_group) {
        $allowed_ext = ['gpx'];
        $file_ext = strtolower(pathinfo($_FILES['gpx_file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, $allowed_ext)) {
            $error = "Only .gpx files allowed";
        } else {
            $upload_dir = __DIR__ . '/gpx_files';
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    $error = "Could not create upload directory";
                }
            }
            
            if (!$error) {
                $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $summit_id) . '_grp' . $current_group['id'] . '_' . time() . '.gpx';
                $filepath = $upload_dir . '/' . $filename;
                
                if (move_uploaded_file($_FILES['gpx_file']['tmp_name'], $filepath)) {
                    // File uploaded, now analyze it
                    $gpx_stats = analyze_gpx_track($filepath, null); // We'll get SOTA ref later
                    
                    if ($gpx_stats) {
                        try {
                            // Delete old GPX for this summit/group
                            $stmt = $db->prepare("SELECT file_path FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
                            $stmt->execute([$summit_id, $current_group['id']]);
                            $old = $stmt->fetch();
                            if ($old && file_exists($old['file_path'])) {
                                unlink($old['file_path']);
                            }
                            
                            $db->prepare("DELETE FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?")
                               ->execute([$summit_id, $current_group['id']]);
                            
                            // Auto-enable hike time only when the GPX has real timestamp data
                            $use_for_hike = $gpx_stats['has_timestamps'] ? 1 : 0;

                            // Insert new GPX record
                            $stmt = $db->prepare("
                                INSERT INTO gpx_tracks (
                                    summit_id, planning_group_id, filename, file_path,
                                    total_time, hiking_time, activation_time, rest_break_time,
                                    total_distance, hiking_distance, max_elevation, min_elevation,
                                    elevation_gain, elevation_loss, avg_speed, hiking_speed,
                                    num_points, summit_lat, summit_lon, using_api,
                                    activation_zone_polygon, activation_zone_method,
                                    use_for_hike_time, use_for_elevation
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $summit_id, $current_group['id'], $filename, $filepath,
                                $gpx_stats['total_time'], $gpx_stats['hiking_time'],
                                $gpx_stats['activation_time'], $gpx_stats['rest_break_time'],
                                $gpx_stats['total_distance'], $gpx_stats['hiking_distance'],
                                $gpx_stats['max_elevation'], $gpx_stats['min_elevation'],
                                $gpx_stats['elevation_gain'], $gpx_stats['elevation_loss'],
                                $gpx_stats['avg_speed'], $gpx_stats['hiking_speed'],
                                $gpx_stats['num_points'], $gpx_stats['summit_lat'],
                                $gpx_stats['summit_lon'], $gpx_stats['using_api'] ? 1 : 0,
                                $gpx_stats['activation_zone_polygon'], $gpx_stats['activation_zone_method'],
                                $use_for_hike, $use_for_hike
                            ]);

                            // Only overwrite distance/elevation from GPX when it has timestamps
                            // (real recorded hike). Route-only GPX files leave manual data intact.
                            if ($use_for_hike) {
                                $db->prepare("
                                    UPDATE summits SET
                                        hike_distance_mi = ?,
                                        hike_elevation_gain_ft = ?,
                                        gpx_source = 'user_upload',
                                        data_source = 'gpx'
                                    WHERE id = ?
                                ")->execute([
                                    round($gpx_stats['total_distance'] * 0.621371, 2),
                                    round($gpx_stats['elevation_gain'] * 3.28084),
                                    $summit_id
                                ]);
                            }

                            if ($use_for_hike) {
                                $message = "✓ GPX uploaded with timestamps — hike time enabled. Hiking: " . format_time_duration($gpx_stats['hiking_time']) .
                                           ", Activation: " . format_time_duration($gpx_stats['activation_time']);
                            } else {
                                $message = "✓ GPX uploaded (route/track only — no timestamps). Map and elevation data saved; hike time not enabled.";
                            }
                            
                            logActivity($db, 'GPX uploaded', $log_summit_name, $log_sota_ref, $log_group_name);
                            header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&gpx=1");
                            exit;
                        } catch (Exception $e) {
                            $error = "Database error: " . $e->getMessage();
                        }
                    } else {
                        $error = "Could not analyze GPX file. Ensure it contains valid trackpoints with time and elevation data.";
                    }
                } else {
                    $error = "Failed to upload file. Check directory permissions.";
                }
            }
        }
    }
}

// Handle GPS preferences update (single checkbox controls all GPS data)
if (isset($_POST['update_gpx_preferences'])) {
    if ($current_group) {
        $use_gps = isset($_POST['use_gps_data']) ? 1 : 0;
        $track_type = in_array($_POST['track_type'] ?? '', ['round-trip', 'ascent', 'descent'])
            ? $_POST['track_type']
            : 'round-trip';

        $stmt = $db->prepare("
            UPDATE gpx_tracks
            SET use_for_hike_time = ?, use_for_elevation = ?, track_type = ?
            WHERE summit_id = ? AND planning_group_id = ?
        ");
        $stmt->execute([$use_gps, $use_gps, $track_type, $summit_id, $current_group['id']]);

        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
        exit;
    }
}


// ========== END GPX UPLOAD HANDLER ==========

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add activation
    if (isset($_POST['add_activation'])) {
        $current_group = getCurrentPlanningGroup($db);
        $stmt = $db->prepare("
            INSERT INTO activations (summit_id, planning_group_id, activation_date, callsigns, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $summit_id,
            $current_group['id'],
            $_POST['activation_date'],
            $_POST['activation_callsigns'],
            $_POST['activation_notes'] ?? null
        ]);
        
        // Update summit's last_activated_date if this is most recent
        $stmt = $db->prepare("
            UPDATE summits 
            SET last_activated_date = ?,
                activated_by = ?,
                status = 'activated'
            WHERE id = ? AND (last_activated_date IS NULL OR last_activated_date < ?)
        ");
        $stmt->execute([
            $_POST['activation_date'],
            $_POST['activation_callsigns'],
            $summit_id,
            $_POST['activation_date']
        ]);

        logActivity($db, 'Activation logged', $log_summit_name, $log_sota_ref, $log_group_name);
        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
        exit;
    }
    
    // Delete activation
    if (isset($_POST['delete_activation'])) {
        $current_group = getCurrentPlanningGroup($db);
        $activation_id = (int)$_POST['activation_id'];
        
        // Delete the activation (only if it belongs to this group)
        $stmt = $db->prepare("DELETE FROM activations WHERE id = ? AND planning_group_id = ?");
        $stmt->execute([$activation_id, $current_group['id']]);
        
        // Update summit's last_activated_date to most recent remaining activation
        $stmt = $db->prepare("
            SELECT activation_date, callsigns 
            FROM activations 
            WHERE summit_id = ? AND planning_group_id = ?
            ORDER BY activation_date DESC 
            LIMIT 1
        ");
        $stmt->execute([$summit_id, $current_group['id']]);
        $latest = $stmt->fetch();
        
        if ($latest) {
            $stmt = $db->prepare("
                UPDATE summits 
                SET last_activated_date = ?, activated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$latest['activation_date'], $latest['callsigns'], $summit_id]);
        } else {
            // No activations left - clear the fields
            $stmt = $db->prepare("
                UPDATE summits 
                SET last_activated_date = NULL, activated_by = NULL
                WHERE id = ?
            ");
            $stmt->execute([$summit_id]);
        }
        
        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
        exit;
    }
    
    // Disconnect from shared data
    if (isset($_POST['use_custom_data'])) {
        $stmt = $db->prepare("UPDATE summits SET uses_shared_data = FALSE WHERE id = ?");
        $stmt->execute([$summit_id]);
        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
        exit;
    }
    
    // Delete summit
    if (isset($_POST['delete_summit'])) {
        $stmt = $db->prepare("DELETE FROM summits WHERE id = ?");
        $stmt->execute([$summit_id]);
        header('Location: index.php');
        exit;
    }
    
    // Geocode address OR use direct lat/long
    if (isset($_POST['geocode_address'])) {
        // FIRST: Save all other form fields if they were provided
        if (isset($_POST['status']) || isset($_POST['difficulty']) || isset($_POST['hike_distance_mi'])) {
            $stmt = $db->prepare("
                UPDATE summits SET
                    status = ?,
                    difficulty = ?,
                    hike_distance_mi = ?,
                    hike_elevation_gain_ft = ?,
                    hike_time_up_min = ?,
                    hike_time_down_min = ?,
                    trail_link = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['status'] ?? $summit['status'],
                $_POST['difficulty'] ?? $summit['difficulty'],
                $_POST['hike_distance_mi'] ?: null,
                $_POST['hike_elevation_gain_ft'] ?: null,
                $_POST['hike_time_up_min'] ?: null,
                $_POST['hike_time_down_min'] ?: null,
                $_POST['trail_link'] ?? '',
                $summit_id
            ]);
        }
        
        // THEN: Process geocoding
        $input = trim($_POST['trailhead_address']);
        
        if (!empty($input)) {
            // Check if input is lat/long format (e.g., "34.23938, -118.09335")
            if (preg_match('/^(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)$/', $input, $matches)) {
                // Direct lat/long input - use EXACT values
                $lat = floatval($matches[1]);
                $lng = floatval($matches[2]);
                
                $stmt = $db->prepare("UPDATE summits SET trailhead_lat = ?, trailhead_lng = ?, trailhead_manual = TRUE WHERE id = ?");
                $stmt->execute([$lat, $lng, $summit_id]);
                
                $msg = urlencode("Coordinates set to: $lat, $lng (exact) + other fields saved");
                header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&geocoded=" . $msg);
                exit;
            } else {
                // Address string - geocode it
                $geocode_url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . 
                               urlencode($input) . "&key=" . GOOGLE_MAPS_API_KEY;
                
                $ch = curl_init($geocode_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                curl_close($ch);
                
                if ($response) {
                    $geocode = json_decode($response, true);
                    if ($geocode['status'] === 'OK' && isset($geocode['results'][0]['geometry']['location'])) {
                        $lat = $geocode['results'][0]['geometry']['location']['lat'];
                        $lng = $geocode['results'][0]['geometry']['location']['lng'];
                        $formatted_address = $geocode['results'][0]['formatted_address'];
                        
                        $stmt = $db->prepare("UPDATE summits SET trailhead_lat = ?, trailhead_lng = ?, trailhead_manual = FALSE WHERE id = ?");
                        $stmt->execute([$lat, $lng, $summit_id]);
                        
                        $msg = urlencode("Trailhead set to: " . $formatted_address . " + other fields saved");
                        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&geocoded=" . $msg);
                        exit;
                    }
                }
            }
        }
        $error = "Could not geocode address or parse coordinates.";
    }
    
    // Calculate drive time
    if (isset($_POST['calculate_drive_time'])) {
        $selected_address = getSelectedAddress($db);
        if ($selected_address) {
            $stmt = $db->prepare("SELECT trailhead_lat, trailhead_lng, latitude, longitude FROM summits WHERE id = ?");
            $stmt->execute([$summit_id]);
            $summit_data = $stmt->fetch();
            
            $dest_lat = $summit_data['trailhead_lat'] ?? $summit_data['latitude'];
            $dest_lng = $summit_data['trailhead_lng'] ?? $summit_data['longitude'];
            
            $drive_time = calculateDriveTime($selected_address['address'], $dest_lat, $dest_lng);
            
            if ($drive_time !== null) {
                // Multiply by 2 for round trip
                $drive_time_rt = $drive_time * 2;
                $stmt = $db->prepare("UPDATE summits SET drive_time_min = ? WHERE id = ?");
                $stmt->execute([$drive_time_rt, $summit_id]);
                
                $msg = urlencode("Drive time (RT): " . formatTime($drive_time_rt));
                header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&geocoded=" . $msg);
                exit;
            } else {
                $error = "Could not calculate drive time.";
            }
        } else {
            $error = "Please select an address first.";
        }
    }
    
    // Update summit
    if (isset($_POST['update_summit'])) {
        try {
            $trailhead_lat = !empty($_POST['trailhead_lat']) ? (float)$_POST['trailhead_lat'] : null;
            $trailhead_lng = !empty($_POST['trailhead_lng']) ? (float)$_POST['trailhead_lng'] : null;
            $trailhead_manual = ($trailhead_lat !== null && $trailhead_lng !== null);

            // Note: last_activated_date / activated_by are managed exclusively by the
            // Add Activation / Delete Activation handlers — never overwritten here.
            $stmt = $db->prepare("
                UPDATE summits SET
                    status = ?,
                    difficulty = ?,
                    cell_service = ?,
                    trail_link = ?,
                    hike_distance_mi = ?,
                    hike_elevation_gain_ft = ?,
                    trailhead_lat = ?,
                    trailhead_lng = ?,
                    trailhead_manual = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $_POST['status'],
                $_POST['difficulty'] ?? null,
                $_POST['cell_service'] ?: null,
                $_POST['trail_link'],
                $_POST['hike_distance_mi'] ?: null,
                $_POST['hike_elevation_gain_ft'] ?: null,
                $trailhead_lat,
                $trailhead_lng,
                $trailhead_manual,
                $summit_id
            ]);
            
            // Calculate hike times
            if (!empty($_POST['hike_distance_mi']) && !empty($_POST['hike_elevation_gain_ft'])) {
                $distance = (float)$_POST['hike_distance_mi'];
                $elevation = (int)$_POST['hike_elevation_gain_ft'];
                
                $time_up = calculateHikeTime($distance / 2, $elevation);
                $time_down = calculateHikeTime($distance / 2, 0);
                
                $stmt = $db->prepare("UPDATE summits SET hike_time_up_min = ?, hike_time_down_min = ? WHERE id = ?");
                $stmt->execute([$time_up, $time_down, $summit_id]);
            }

            logActivity($db, 'Summit edited', $log_summit_name, $log_sota_ref, $log_group_name);
            header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
            exit;
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // Add note
    if (isset($_POST['add_note'])) {
        $stmt = $db->prepare("INSERT INTO summit_notes (summit_id, note) VALUES (?, ?)");
        $stmt->execute([$summit_id, $_POST['note']]);
        logActivity($db, 'Note added', $log_summit_name, $log_sota_ref, $log_group_name);
        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
        exit;
    }
    
    // Delete note
    if (isset($_POST['delete_note'])) {
        $stmt = $db->prepare("DELETE FROM summit_notes WHERE id = ?");
        $stmt->execute([$_POST['note_id']]);
        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
        exit;
    }

    // Add planned activation
    if (isset($_POST['add_planned_activation'])) {
        if ($current_group) {
            $stmt = $db->prepare("
                INSERT INTO planned_activations
                    (summit_id, planning_group_id, planned_date, hike_start_time, callsigns, activation_duration_min, invitation_message, travel_notes, location_link)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $summit_id,
                $current_group['id'],
                $_POST['planned_date'],
                $_POST['hike_start_time'],
                $_POST['planned_callsigns'],
                (int)$_POST['activation_duration_min'],
                $_POST['invitation_message'] ?: null,
                $_POST['travel_notes'] ?: null,
                $_POST['location_link'] ?: null
            ]);
            logActivity($db, 'Activation scheduled', $log_summit_name, $log_sota_ref, $log_group_name);
            header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
            exit;
        }
    }

    // Delete planned activation
    if (isset($_POST['delete_planned_activation'])) {
        if ($current_group) {
            $pa_id = (int)$_POST['planned_activation_id'];
            $stmt = $db->prepare("DELETE FROM planned_activations WHERE id = ? AND planning_group_id = ?");
            $stmt->execute([$pa_id, $current_group['id']]);
            header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
            exit;
        }
    }

    // Reset trailhead
    if (isset($_POST['reset_trailhead'])) {
        $stmt = $db->prepare("UPDATE summits SET trailhead_lat = NULL, trailhead_lng = NULL, trailhead_manual = FALSE WHERE id = ?");
        $stmt->execute([$summit_id]);
        logActivity($db, 'Trailhead reset', $log_summit_name, $log_sota_ref, $log_group_name);
        header("Location: summit_detail.php?id=" . $summit_id . "&group=" . ($current_group['id'] ?? '') . "&saved=1");
        exit;
    }

    // Edit planned activation
    if (isset($_POST['edit_planned_activation'])) {
        if ($current_group) {
            $pa_id = (int)$_POST['planned_activation_id'];
            $stmt = $db->prepare("
                UPDATE planned_activations
                SET planned_date = ?, hike_start_time = ?, callsigns = ?,
                    activation_duration_min = ?, invitation_message = ?, travel_notes = ?, location_link = ?
                WHERE id = ? AND planning_group_id = ?
            ");
            $stmt->execute([
                $_POST['planned_date'],
                $_POST['hike_start_time'],
                $_POST['planned_callsigns'],
                (int)$_POST['activation_duration_min'],
                $_POST['invitation_message'] ?: null,
                $_POST['travel_notes'] ?: null,
                $_POST['location_link'] ?: null,
                $pa_id,
                $current_group['id']
            ]);
            header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&saved=1");
            exit;
        }
    }
}

// Fetch summit data
$stmt = $db->prepare("SELECT * FROM summits WHERE id = ?");
$stmt->execute([$summit_id]);
$summit = $stmt->fetch();

// Get GPX data for this summit/group
$gpx_data = null;
if ($current_group) {
    $stmt = $db->prepare("SELECT * FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
    $stmt->execute([$summit_id, $current_group['id']]);
    $gpx_data = $stmt->fetch();
}


if (!$summit) {
    header('Location: index.php');
    exit;
}

// Auto-archive past planned activations for this summit, then fetch current ones
$planned_activations_list = [];
if ($current_group) {
    $stmt = $db->prepare("SELECT * FROM planned_activations WHERE summit_id = ? AND planning_group_id = ? AND planned_date < CURDATE()");
    $stmt->execute([$summit_id, $current_group['id']]);
    $past_planned = $stmt->fetchAll();

    foreach ($past_planned as $pp) {
        $db->prepare("INSERT INTO activations (summit_id, planning_group_id, activation_date, callsigns, notes) VALUES (?, ?, ?, ?, ?)")
           ->execute([$pp['summit_id'], $pp['planning_group_id'], $pp['planned_date'], $pp['callsigns'],
                      $pp['invitation_message'] ? substr($pp['invitation_message'], 0, 200) : null]);
        $db->prepare("UPDATE summits SET last_activated_date = ?, activated_by = ?, status = 'activated' WHERE id = ? AND (last_activated_date IS NULL OR last_activated_date < ?)")
           ->execute([$pp['planned_date'], $pp['callsigns'], $summit_id, $pp['planned_date']]);
        $db->prepare("DELETE FROM planned_activations WHERE id = ?")->execute([$pp['id']]);
    }

    if (!empty($past_planned)) {
        $stmt = $db->prepare("SELECT * FROM summits WHERE id = ?");
        $stmt->execute([$summit_id]);
        $summit = $stmt->fetch();
    }

    $stmt = $db->prepare("SELECT * FROM planned_activations WHERE summit_id = ? AND planning_group_id = ? ORDER BY planned_date ASC");
    $stmt->execute([$summit_id, $current_group['id']]);
    $planned_activations_list = $stmt->fetchAll();
}

// Fetch notes
$stmt = $db->prepare("SELECT * FROM summit_notes WHERE summit_id = ? ORDER BY created_at DESC");
$stmt->execute([$summit_id]);
$notes = $stmt->fetchAll();

$selected_address = getSelectedAddress($db);
// Track type determines how one-way GPX tracks are used for round-trip planning
$track_type = $gpx_data['track_type'] ?? 'round-trip';
$gpx_download_name = $gpx_data ? preg_replace('/[^a-zA-Z0-9]+/', '-', $summit['name'] ?? 'summit')
    . ($summit['sota_ref'] ? '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', $summit['sota_ref']) : '')
    . '.gpx' : '';
$one_way = ($track_type === 'ascent' || $track_type === 'descent');
// has_timestamps is not stored in the DB — derive it from hiking_time:
// if the track had no timestamps, hiking_time stays 0 since speed can't be computed.
$has_timestamps = $gpx_data && $gpx_data['hiking_time'] > 0;

// Elevation gain — resolve first so hike time can use it
if ($gpx_data && $gpx_data['use_for_elevation']) {
    if ($track_type === 'descent') {
        $elevation_gain_display = $gpx_data['elevation_loss'] * 3.28084;
    } else {
        $elevation_gain_display = $gpx_data['elevation_gain'] * 3.28084;
    }
    $elevation_source = 'GPS';
} else {
    $elevation_gain_display = $summit['hike_elevation_gain_ft'];
    $elevation_source = 'Manual';
}

// Distance — resolve second so hike time can use it
if ($gpx_data && $gpx_data['use_for_hike_time'] && $gpx_data['total_distance'] > 0) {
    $dist_km = $gpx_data['total_distance'];
    if ($one_way) $dist_km *= 2;
    $distance_display_mi = round($dist_km * 0.621371, 2);
    $distance_source = $has_timestamps ? 'GPS' : 'Route';
} else {
    $distance_display_mi = $summit['hike_distance_mi'];
    $distance_source = 'Manual';
}

// Hike time — uses whichever distance/elevation source is now active
if ($gpx_data && $gpx_data['use_for_hike_time'] && $has_timestamps) {
    // Real GPS recorded time
    $gpx_hiking_secs = $gpx_data['hiking_time'];
    if ($one_way) $gpx_hiking_secs *= 2;
    $hike_time_total = round($gpx_hiking_secs / 60);
    $hike_time_source = 'GPS';
} else {
    // Naismith's formula from whichever distance/elevation is active (GPS route or manual)
    $hike_time_total = ($distance_display_mi || $elevation_gain_display)
        ? calculateHikeTime($distance_display_mi ?? 0, $elevation_gain_display ?? 0)
        : 0;
    $hike_time_source = ($gpx_data && $gpx_data['use_for_hike_time'] && !$has_timestamps)
        ? 'Estimated' : 'Calculated';
}
$directions_lat = $summit['trailhead_lat'] ?? $summit['latitude'];
$directions_lng = $summit['trailhead_lng'] ?? $summit['longitude'];

// --- Timeline Gantt data ---
$tl_drive_one = $summit['drive_time_min'] ? intval(round($summit['drive_time_min'] / 2)) : 0;
if ($hike_time_total) {
    // Always use the live $hike_time_total (GPS or Naismith) so the Gantt
    // matches the stats shown above it and is never stale.
    $tl_hike_up   = intval(round($hike_time_total * 0.6));
    $tl_hike_down = $hike_time_total - $tl_hike_up;
} elseif ($summit['hike_time_up_min'] && $summit['hike_time_down_min']) {
    // Fallback: pre-stored leg times (only reached if no distance/elevation data at all)
    $tl_hike_up   = $summit['hike_time_up_min'];
    $tl_hike_down = $summit['hike_time_down_min'];
} else {
    $tl_hike_up = $tl_hike_down = 0;
}
$tl_activation = ($gpx_data && $gpx_data['activation_time'] > 0)
    ? intval(round($gpx_data['activation_time'] / 60))
    : 60;
$tl_total = $tl_drive_one * 2 + $tl_hike_up + $tl_activation + $tl_hike_down;
$tl_show = $tl_total > 0;
if ($tl_show) {
    $tl_drive_pct      = $tl_drive_one  > 0 ? round($tl_drive_one  / $tl_total * 100, 1) : 0;
    $tl_hike_up_pct    = $tl_hike_up    > 0 ? round($tl_hike_up    / $tl_total * 100, 1) : 0;
    $tl_activation_pct =                       round($tl_activation / $tl_total * 100, 1);
    $tl_hike_down_pct  = $tl_hike_down  > 0 ? round($tl_hike_down  / $tl_total * 100, 1) : 0;
    // Milestones: [pct_position, label, elapsed_minutes]
    $tl_m = [];
    $elapsed = 0;
    if ($tl_drive_one > 0) { $tl_m[] = [0, 'Depart', 0]; }
    $elapsed += $tl_drive_one;
    $tl_m[] = [round($elapsed / $tl_total * 100, 1), 'Trailhead', $elapsed];
    $elapsed += $tl_hike_up;
    if ($tl_hike_up > 0) $tl_m[] = [round($elapsed / $tl_total * 100, 1), 'Summit', $elapsed];
    $elapsed += $tl_activation;
    $tl_m[] = [round($elapsed / $tl_total * 100, 1), 'Radio Done', $elapsed];
    $elapsed += $tl_hike_down;
    if ($tl_hike_down > 0) $tl_m[] = [round($elapsed / $tl_total * 100, 1), 'Trailhead', $elapsed];
    $elapsed += $tl_drive_one;
    if ($tl_drive_one > 0) $tl_m[] = [round($elapsed / $tl_total * 100, 1), 'Home', $elapsed];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($summit['name']) ?> — SOTAplanner</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    :root {
      --bg:         #F7F6F3;
      --bg-2:       #EFEDE8;
      --bg-3:       #E5E2DA;
      --ink:        #1C1B19;
      --ink-2:      #4A4844;
      --ink-3:      #8C8A86;
      --ink-4:      #B8B5B0;
      --accent:        oklch(52% 0.13 50);
      --accent-2:      oklch(44% 0.13 50);
      --accent-bg:     oklch(96% 0.04 65);
      --accent-border: oklch(84% 0.08 65);
      --green:      #2D8653;
      --green-bg:   #EBF5EF;
      --orange:     #C07020;
      --orange-bg:  #FDF3E7;
      --red:        #C03030;
      --red-bg:     #FBE9E9;
      --blue:       #2B5CA0;
      --blue-bg:    #E8EFF9;
      --gray-badge: #6B7280;
      --gray-bg:    #F3F4F6;
      --surface:    #FFFFFF;
      --border:     #E5E2DA;
      --border-2:   #D4D0C8;
      --font-sans:  'DM Sans', system-ui, sans-serif;
      --font-mono:  'DM Mono', 'Courier New', monospace;
      --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
      --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
      --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
      --shadow-lg: 0 8px 24px rgba(28,27,25,0.10), 0 4px 8px rgba(28,27,25,0.06);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 16px; -webkit-font-smoothing: antialiased; }
    body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }
    h1,h2,h3,h4,h5 { font-family: var(--font-sans); font-weight: 600; line-height: 1.2; }
    p { line-height: 1.65; color: var(--ink-2); }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* Topbar */
    .topbar { background: var(--surface); border-bottom: 1px solid var(--border); height: 56px; display: flex; align-items: center; padding: 0 2rem; gap: 1.5rem; position: sticky; top: 0; z-index: 100; }
    .topbar-logo { display: flex; align-items: center; gap: 0.75rem; text-decoration: none; color: var(--ink); font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0; }
    .topbar-logo:hover { text-decoration: none; color: var(--ink); }
    .logo-mark { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
    .topbar-nav { display: flex; align-items: center; gap: 0.25rem; flex: 1; }
    .topbar-nav a { color: var(--ink-3); font-size: 0.875rem; font-weight: 500; padding: 0.5rem 0.75rem; border-radius: var(--r-sm); transition: color 0.15s, background 0.15s; text-decoration: none; white-space: nowrap; }
    .topbar-nav a:hover { color: var(--ink); background: var(--bg-2); text-decoration: none; }
    .topbar-nav a.active { color: var(--ink); background: var(--bg-2); }
    .topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; }

    /* Page */
    .page { padding: 2rem; max-width: 1400px; margin: 0 auto; }

    /* Buttons */
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0 1rem; height: 36px; border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; transition: background 0.15s, box-shadow 0.15s, transform 0.1s; text-decoration: none; white-space: nowrap; line-height: 1; }
    .btn:hover { text-decoration: none; }
    .btn:active { transform: scale(0.98); }
    .btn-primary { background: var(--ink); color: #fff; }
    .btn-primary:hover { background: var(--ink-2); color: #fff; }
    .btn-accent { background: var(--accent); color: #fff; }
    .btn-accent:hover { background: var(--accent-2); color: #fff; }
    .btn-secondary { background: var(--bg-2); color: var(--ink); border: 1px solid var(--border); }
    .btn-secondary:hover { background: var(--bg-3); color: var(--ink); }
    .btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--border); }
    .btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
    .btn-danger { background: var(--red-bg); color: var(--red); border: 1px solid #e8baba; }
    .btn-danger:hover { background: #f5d5d5; }
    .btn-sm { height: 30px; padding: 0 0.75rem; font-size: 0.8rem; }
    .btn-map-active { background: #6B6865; color: #fff; }
    .btn-map-active:hover { background: #5C5956; color: #fff; }
    .btn-lg { height: 44px; padding: 0 1.5rem; font-size: 1rem; }
    .user-chip {
        position: relative; display: flex; align-items: center; gap: 0.35rem;
        cursor: pointer; padding: 0.25rem 0.6rem;
        border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: var(--ink-2);
        border: 1px solid var(--border); background: var(--bg); user-select: none; white-space: nowrap;
    }
    .user-chip:hover { background: var(--bg-2); }
    .user-chip-chevron { transition: transform 0.15s; flex-shrink: 0; }
    .user-chip.open .user-chip-chevron { transform: rotate(180deg); }
    .user-dropdown {
        display: none; position: absolute; top: calc(100% + 6px); right: 0;
        background: #fff; border: 1px solid var(--border);
        border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        min-width: 130px; overflow: hidden; z-index: 200;
    }
    .user-chip.open .user-dropdown { display: block; }
    .user-dropdown a {
        display: block; padding: 0.6rem 1rem;
        font-size: 0.82rem; font-weight: 500; color: var(--ink-2); text-decoration: none;
    }
    .user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }
    .btn-full { width: 100%; }

    /* Forms */
    .form-group { margin-bottom: 1.25rem; }
    .form-label { display: block; font-size: 0.8rem; font-weight: 500; color: var(--ink-2); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.06em; }
    .form-input, .form-select, .form-textarea { display: block; width: 100%; padding: 0.625rem 0.875rem; background: var(--surface); border: 1px solid var(--border-2); border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.9rem; color: var(--ink); transition: border-color 0.15s, box-shadow 0.15s; outline: none; -webkit-appearance: none; }
    .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(43,142,142,0.15); }
    .form-input::placeholder { color: var(--ink-4); }
    .form-hint { font-size: 0.78rem; color: var(--ink-3); margin-top: 0.4rem; line-height: 1.5; }
    .form-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238C8A86' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 2rem; cursor: pointer; }

    /* Cards */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); }

    /* Badges */
    .badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 100px; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap; }
    .badge-easy     { background: var(--green-bg); color: var(--green); }
    .badge-moderate { background: var(--orange-bg); color: var(--orange); }
    .badge-hard     { background: var(--red-bg); color: var(--red); }
    .badge-drive-up { background: var(--blue-bg); color: var(--blue); }
    .badge-nominated  { background: var(--blue-bg); color: var(--blue); }
    .badge-researched { background: var(--orange-bg); color: var(--orange); }
    .badge-ready      { background: var(--green-bg); color: var(--green); }
    .badge-activated  { background: var(--gray-bg); color: var(--gray-badge); }

    /* Messages */
    .msg { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1rem; border-radius: var(--r-md); font-size: 0.875rem; font-weight: 500; margin-bottom: 1rem; }
    .msg-success { background: var(--green-bg); color: var(--green); border: 1px solid #b8d9c9; }
    .msg-error   { background: var(--red-bg);   color: var(--red);   border: 1px solid #e8baba; }
    .msg-info    { background: var(--accent-bg); color: var(--accent); border: 1px solid var(--accent-border); }
    .msg-dismiss { background: none; border: none; cursor: pointer; color: inherit; opacity: 0.5; font-size: 1.1rem; padding: 0; line-height: 1; flex-shrink: 0; }
    .msg-dismiss:hover { opacity: 1; }

    /* Footer */
    .footer { text-align: center; padding: 2rem 1rem 1.5rem; color: var(--ink-4); font-size: 0.78rem; border-top: 1px solid var(--border); margin-top: 3rem; }
    .footer a { color: var(--ink-3); }
    .footer a:hover { color: var(--ink); }

    /* Modal */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(28,27,25,0.5); z-index: 200; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
    .modal-overlay.open { display: flex; }
    .modal-box { background: var(--surface); border-radius: var(--r-xl); padding: 2rem; max-width: 520px; width: 92%; position: relative; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto; }
    .modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--ink-3); line-height: 1; padding: 0.25rem; border-radius: var(--r-sm); transition: background 0.1s, color 0.1s; }
    .modal-close:hover { background: var(--bg-2); color: var(--ink); }

    /* Page header */
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; }
    .page-title { font-size: 1.4rem; font-weight: 600; letter-spacing: -0.02em; color: var(--ink); }
    .page-subtitle { font-size: 0.875rem; color: var(--ink-3); margin-top: 0.25rem; }

    /* Status pipeline */
    .status-pipeline { display: flex; margin-bottom: 1.25rem; }
    .pipeline-step { flex: 1; text-align: center; padding: 0.75rem 0.5rem; background: var(--surface); border: 1px solid var(--border); border-right: none; cursor: pointer; transition: background 0.1s; font: inherit; }
    .pipeline-step:first-child { border-radius: var(--r-md) 0 0 var(--r-md); }
    .pipeline-step:last-child  { border-radius: 0 var(--r-md) var(--r-md) 0; border-right: 1px solid var(--border); }
    .pipeline-step.done   { background: var(--green-bg); border-color: #b8d9c9; }
    .pipeline-step.active { background: var(--accent-bg); border-color: var(--accent-border); }
    .pipeline-step:hover:not(.active):not(.done) { background: var(--bg-2); }
    .pipeline-icon  { font-size: 0.8rem; margin-bottom: 2px; color: var(--ink-3); }
    .pipeline-label { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-3); }
    .pipeline-step.done   .pipeline-icon,  .pipeline-step.done   .pipeline-label  { color: var(--green); }
    .pipeline-step.active .pipeline-icon,  .pipeline-step.active .pipeline-label  { color: var(--accent); }

    /* Detail layout */
    .detail-layout { display: grid; grid-template-columns: 1fr 300px; gap: 1.5rem; align-items: start; }

    /* 4-stat grid */
    .stat-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--r-lg); overflow: hidden; margin-bottom: 1.25rem; }
    .stat-cell { background: var(--surface); padding: 1rem 1.25rem; }
    .stat-cell-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-3); margin-bottom: 5px; }
    .stat-cell-val   { font-size: 1.1rem; font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; line-height: 1.2; }
    .stat-cell-sub   { font-size: 0.72rem; color: var(--ink-3); margin-top: 3px; }

    /* Time bar */
    .time-bar { height: 8px; border-radius: 100px; overflow: hidden; display: flex; gap: 2px; margin-bottom: 6px; }
    .time-legend { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
    .time-legend-item { display: flex; align-items: center; gap: 5px; font-size: 0.72rem; color: var(--ink-3); }
    .time-legend-dot  { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }

    /* Map */
    #summit-map { height: 280px; border-radius: var(--r-lg); border: 1px solid var(--border); overflow: hidden; margin-bottom: 0.75rem; }
    .map-buttons { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; align-items: center; }
    .carrier-btn { height: 28px; padding: 0 0.625rem; font-size: 0.75rem; font-weight: 600; border-radius: var(--r-sm); border: 1.5px solid; cursor: pointer; transition: all 0.12s; font-family: var(--font-sans); }
    .map-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }

    /* Elevation profile */
    .elev-wrap { background: var(--bg-2); border: 1px solid var(--border); border-radius: var(--r-md); overflow: hidden; margin-bottom: 0.75rem; }
    #elev-canvas { display: block; width: 100%; height: 120px; cursor: crosshair; }
    .elev-note { font-size: 0.75rem; color: var(--ink-3); padding: 0.35rem 0.75rem; }

    /* Section divider / head */
    .section-divider { border: none; border-top: 1px solid var(--border); margin: 1.25rem 0; }
    .section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
    .section-head-title { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-3); }

    /* Field grid */
    .field-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

    /* Difficulty selector */
    .diff-btns { display: flex; gap: 0.5rem; }
    .diff-btn { flex: 1; padding: 0.5rem 0.25rem; border-radius: var(--r-md); border: 1px solid var(--border); background: var(--surface); color: var(--ink-3); font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.1s; text-transform: capitalize; font-family: var(--font-sans); }
    .diff-btn:hover { border-color: var(--border-2); color: var(--ink); }
    .diff-btn.active-easy     { border-color: var(--green); background: var(--green-bg); color: var(--green); }
    .diff-btn.active-moderate { border-color: var(--orange); background: var(--orange-bg); color: var(--orange); }
    .diff-btn.active-hard     { border-color: var(--red); background: var(--red-bg); color: var(--red); }
    .diff-btn.active-drive-up { border-color: var(--blue); background: var(--blue-bg); color: var(--blue); }

    /* GPX drop */
    .gpx-drop { border: 2px dashed var(--border-2); border-radius: var(--r-lg); padding: 1.5rem; text-align: center; background: var(--bg-2); cursor: pointer; transition: border-color 0.15s, background 0.15s; display: block; }
    .gpx-drop:hover { border-color: var(--accent); background: var(--accent-bg); }

    /* Info rows */
    .info-row { display: flex; justify-content: space-between; align-items: baseline; padding: 0.5rem 0; border-bottom: 1px solid var(--border); }
    .info-row:last-child { border-bottom: none; padding-bottom: 0; }
    .info-label { font-size: 0.8rem; color: var(--ink-3); flex-shrink: 0; }
    .info-val   { font-size: 0.8rem; font-weight: 500; color: var(--ink); text-align: right; }

    /* Trail search links */
    .trail-search-btn { display: inline-flex; align-items: center; height: 26px; padding: 0 0.625rem; font-size: 0.75rem; font-weight: 500; border-radius: var(--r-sm); background: var(--bg-2); border: 1px solid var(--border-2); color: var(--ink-2); text-decoration: none; transition: all 0.12s; white-space: nowrap; }
    .trail-search-btn:hover { border-color: var(--accent-border); color: var(--ink); background: var(--accent-bg); text-decoration: none; }

    /* Mini table */
    .mini-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    .mini-table th { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-3); padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--border); background: var(--bg-2); }
    .mini-table td { padding: 0.625rem 0.75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .mini-table tr:last-child td { border-bottom: none; }

    /* Section card */
    .section-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); margin-bottom: 1rem; }
    .section-title { font-size: 1rem; font-weight: 600; color: var(--ink); margin-bottom: 1rem; }

    /* Notes */
    .note-item { padding: 0.625rem 0; border-bottom: 1px solid var(--border); }
    .note-item:last-child { border-bottom: none; }
    .note-text { font-size: 0.875rem; color: var(--ink); line-height: 1.5; }
    .note-meta { font-size: 0.72rem; color: var(--ink-3); margin-top: 3px; }

    /* Geocoder box */
    .geocoder-box { background: var(--accent-bg); border: 1px solid var(--accent-border); border-radius: var(--r-md); padding: 1rem; margin-bottom: 1rem; }

    /* Shared notice */
    .shared-notice { background: var(--accent-bg); border: 1px solid var(--accent-border); border-radius: var(--r-md); padding: 1rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; }

    /* GPS prefs block */
    .gps-prefs { background: var(--bg-2); border: 1px solid var(--border); border-radius: var(--r-md); padding: 0.875rem 1rem; margin-top: 0.75rem; }

    /* Summary expand */
    details summary { list-style: none; }
    details summary::-webkit-details-marker { display: none; }

    /* Mobile */
    @media (max-width: 768px) {
      .page { padding: 1rem; }
      .topbar { padding: 0 1rem; }
      .topbar-nav { display: none; }
      .detail-layout { grid-template-columns: 1fr; }
      .stat-grid-4 { grid-template-columns: 1fr 1fr; }
      .field-row-2 { grid-template-columns: 1fr; }
      .page-header { flex-direction: column; gap: 0.75rem; }
      .diff-btns { flex-wrap: wrap; }
    }
  </style>
</head>
<body>

<nav class="topbar">
  <a href="index.php" class="topbar-logo">
    <div class="logo-mark">
      <img src="sota-planner-logo.svg" width="32" height="32" alt="">
    </div>
    <span>SOTAplanner</span>
  </a>
  <div class="topbar-divider"></div>
  <div class="topbar-nav">
    <a href="index.php">Dashboard</a>
    <a href="manage_addresses.php">Groups &amp; Addresses</a>
    <a href="about.php">About</a>
  </div>
  <div class="topbar-right">
    <a href="index.php" class="btn btn-ghost btn-sm">← Dashboard</a>
    <div class="user-chip" id="userChip">
        <?= htmlspecialchars(getCurrentCallsign()) ?>
        <svg class="user-chip-chevron" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="2,3.5 5,6.5 8,3.5"/></svg>
        <div class="user-dropdown">
            <?php if (getCurrentCallsign() === 'KI6CR' || !empty($_SESSION['_god_mode_real_callsign'])): ?>
                <a href="god_mode.php">God Mode</a>
            <?php endif; ?>
            <a href="logout.php">Sign Out</a>
        </div>
    </div>
  </div>
</nav>

<div class="page">

  <!-- PAGE HEADER -->
  <div class="page-header">
    <div>
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
        <?php if ($summit['difficulty']): ?>
          <span class="badge badge-<?= htmlspecialchars($summit['difficulty']) ?>"><?= ucfirst($summit['difficulty']) ?></span>
        <?php endif; ?>
        <span class="badge badge-<?= htmlspecialchars($summit['status']) ?>"><?= ucfirst($summit['status']) ?></span>
        <?php if ($summit['sota_ref']): ?>
          <span style="font-family:var(--font-mono); font-size:0.78rem; color:var(--ink-3);"><?= htmlspecialchars($summit['sota_ref']) ?></span>
        <?php endif; ?>
      </div>
      <div class="page-title"><?= htmlspecialchars($summit['name']) ?></div>
      <div class="page-subtitle">
        <?= htmlspecialchars($summit['region']) ?>
        <?php if ($summit['points']): ?> &middot; <?= $summit['points'] ?> pts<?php endif; ?>
        <?php if ($selected_address): ?> &middot; From: <?= htmlspecialchars($selected_address['label'] ?: $selected_address['address']) ?><?php endif; ?>
      </div>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-start;">
      <?php if ($summit['sota_ref']): ?>
        <a href="https://sotl.as/summits/<?= str_replace('%2F', '/', urlencode($summit['sota_ref'])) ?>" target="_blank" class="btn btn-ghost btn-sm">SOTLAS ↗</a>
      <?php endif; ?>
      <?php if (!empty($summit['trail_link'])): ?>
        <a href="<?= htmlspecialchars($summit['trail_link']) ?>" target="_blank" class="btn btn-ghost btn-sm">Trail ↗</a>
      <?php endif; ?>
      <?php if ($selected_address && $directions_lat && $directions_lng): ?>
        <a href="https://www.google.com/maps/dir/?api=1&origin=<?= urlencode($selected_address['address']) ?>&destination=<?= $directions_lat ?>,<?= $directions_lng ?>&travelmode=driving" target="_blank" class="btn btn-ghost btn-sm">Directions ↗</a>
      <?php endif; ?>
      <?php if (!empty($planned_activations_list)): ?>
        <?php
          $pa0 = $planned_activations_list[0];
          $peak_slug0 = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', ($summit['sota_ref'] ?? '') . '-' . ($summit['name'] ?? '')), '-'));
          $invite_url0 = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/activation_invite.php?id=' . $pa0['id'] . '&peak=' . rawurlencode($peak_slug0);
        ?>
        <a href="<?= htmlspecialchars($invite_url0) ?>" target="_blank" class="btn btn-secondary btn-sm">Activation Invite</a>
      <?php else: ?>
        <a href="#planned-activations" class="btn btn-secondary btn-sm">Schedule Activation</a>
      <?php endif; ?>
      <button class="btn btn-primary btn-sm" type="submit" form="main-edit-form" name="update_summit">Save Changes</button>
    </div>
  </div>

  <!-- MESSAGES -->
  <?php if ($message): ?>
    <div class="msg msg-success" id="flash-msg">
      <span><?= htmlspecialchars($message) ?></span>
      <button class="msg-dismiss" onclick="this.parentElement.style.display='none'" type="button">×</button>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="msg msg-error">
      <span><?= htmlspecialchars($error) ?></span>
      <button class="msg-dismiss" onclick="this.parentElement.style.display='none'" type="button">×</button>
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['shared_data'])): ?>
    <div class="msg msg-info">
      <span>Summit data copied from another group — customize it below for your group.</span>
      <button class="msg-dismiss" onclick="this.parentElement.style.display='none'" type="button">×</button>
    </div>
  <?php endif; ?>

  <?php if ($summit['uses_shared_data'] && $summit['source_group_id']): ?>
    <?php
      $stmt = $db->prepare("SELECT name FROM planning_groups WHERE id = ?");
      $stmt->execute([$summit['source_group_id']]);
      $source_group = $stmt->fetch();
    ?>
    <div class="shared-notice">
      <div>
        <div style="font-size:0.875rem; font-weight:600; color:var(--ink);">Using shared research</div>
        <div style="font-size:0.8rem; color:var(--ink-3); margin-top:2px;">Trail data from: <?= htmlspecialchars($source_group['name'] ?? 'Another group') ?></div>
      </div>
      <form method="POST" style="margin:0;">
        <button type="submit" name="use_custom_data" class="btn btn-ghost btn-sm">Clear Imported Data</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- STATUS PIPELINE -->
  <?php
    $pipe_steps = ['nominated','researched','ready','activated'];
    $pipe_idx   = array_search($summit['status'], $pipe_steps);
    if ($pipe_idx === false) $pipe_idx = 0;
  ?>
  <div class="status-pipeline">
    <?php foreach ($pipe_steps as $i => $step):
      $cls = $i < $pipe_idx ? 'done' : ($i === $pipe_idx ? 'active' : '');
      $icon = $i < $pipe_idx ? '✓' : ($i === $pipe_idx ? '●' : '○');
    ?>
      <form method="POST" style="flex:1; display:flex; margin:0;">
        <input type="hidden" name="update_summit" value="1">
        <input type="hidden" name="status" value="<?= $step ?>">
        <input type="hidden" name="difficulty" value="<?= htmlspecialchars($summit['difficulty'] ?? '') ?>">
        <input type="hidden" name="hike_distance_mi" value="<?= htmlspecialchars($summit['hike_distance_mi'] ?? '') ?>">
        <input type="hidden" name="hike_elevation_gain_ft" value="<?= htmlspecialchars($summit['hike_elevation_gain_ft'] ?? '') ?>">
        <input type="hidden" name="trail_link" value="<?= htmlspecialchars($summit['trail_link'] ?? '') ?>">
        <input type="hidden" name="trailhead_lat" value="<?= htmlspecialchars($summit['trailhead_lat'] ?? '') ?>">
        <input type="hidden" name="trailhead_lng" value="<?= htmlspecialchars($summit['trailhead_lng'] ?? '') ?>">
        <input type="hidden" name="cell_service" value="<?= htmlspecialchars($summit['cell_service'] ?? '') ?>">
        <button type="submit" class="pipeline-step <?= $cls ?>" style="flex:1;" title="Set status to <?= ucfirst($step) ?>">
          <div class="pipeline-icon"><?= $icon ?></div>
          <div class="pipeline-label"><?= ucfirst($step) ?></div>
        </button>
      </form>
    <?php endforeach; ?>
  </div>

  <!-- MAIN EDIT FORM -->
  <form method="POST" id="main-edit-form">
  <input type="hidden" name="update_summit" value="1">
  <input type="hidden" name="status" value="<?= htmlspecialchars($summit['status'] ?? '') ?>">

  <div class="detail-layout">
    <!-- ═══ LEFT COLUMN ═══ -->
    <div>

      <!-- 4-stat grid -->
      <?php
        $total_min = ($summit['drive_time_min'] ?? 0) + ($hike_time_total ?? 0) + $tl_activation;
        $drive_rt  = (int)($summit['drive_time_min'] ?? 0);
        $hike_rt   = (int)($hike_time_total ?? 0);
        $act_time  = $tl_activation;
      ?>
      <div class="stat-grid-4">
        <div class="stat-cell">
          <div class="stat-cell-label">Drive (RT)</div>
          <?php if ($drive_rt): ?>
            <div class="stat-cell-val"><?= floor($drive_rt/60) ?>h <?= $drive_rt%60 ?>m</div>
            <div class="stat-cell-sub"><?= $selected_address ? htmlspecialchars($selected_address['label'] ?: 'from base') : 'round-trip' ?></div>
          <?php else: ?>
            <div class="stat-cell-val" style="color:var(--ink-3); font-size:0.875rem;">—</div>
            <div class="stat-cell-sub">Not set</div>
          <?php endif; ?>
        </div>
        <div class="stat-cell">
          <div class="stat-cell-label">Hike (RT)</div>
          <?php if ($hike_rt): ?>
            <div class="stat-cell-val"><?= floor($hike_rt/60) ?>h <?= $hike_rt%60 ?>m</div>
            <div class="stat-cell-sub"><?= $hike_time_source ?></div>
          <?php else: ?>
            <div class="stat-cell-val" style="color:var(--ink-3); font-size:0.875rem;">—</div>
            <div class="stat-cell-sub">No data yet</div>
          <?php endif; ?>
        </div>
        <div class="stat-cell">
          <div class="stat-cell-label">Summit Time</div>
          <div class="stat-cell-val"><?= $act_time ?>m</div>
          <div class="stat-cell-sub">planned</div>
        </div>
        <div class="stat-cell" style="background:#6B6865;">
          <div class="stat-cell-label" style="color:rgba(255,255,255,0.45);">Total (RT)</div>
          <?php if ($total_min): ?>
            <div class="stat-cell-val" style="color:#fff;"><?= floor($total_min/60) ?>h <?= $total_min%60 ?>m</div>
            <div class="stat-cell-sub" style="color:rgba(255,255,255,0.4);">doorstep to doorstep</div>
          <?php else: ?>
            <div class="stat-cell-val" style="color:rgba(255,255,255,0.35); font-size:0.875rem;">—</div>
            <div class="stat-cell-sub" style="color:rgba(255,255,255,0.3);">add distances first</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Time breakdown bar -->
      <?php if ($tl_show): ?>
      <div style="margin-bottom:1.25rem;">
        <div class="time-bar">
          <?php if ($tl_drive_pct > 0): ?>
            <div style="flex:<?= $tl_drive_one ?>; background:#8B73A8; border-radius:100px 0 0 100px;"></div>
          <?php endif; ?>
          <?php if ($tl_hike_up_pct > 0): ?>
            <div style="flex:<?= $tl_hike_up ?>; background:var(--accent); <?= $tl_drive_pct == 0 ? 'border-radius:100px 0 0 100px;' : '' ?>"></div>
          <?php endif; ?>
          <div style="flex:<?= $tl_activation ?>; background:var(--green);"></div>
          <?php if ($tl_hike_down_pct > 0): ?>
            <div style="flex:<?= $tl_hike_down ?>; background:#5BA4B8;"></div>
          <?php endif; ?>
          <?php if ($tl_drive_pct > 0): ?>
            <div style="flex:<?= $tl_drive_one ?>; background:#8B73A8; border-radius:0 100px 100px 0;"></div>
          <?php endif; ?>
        </div>
        <div class="time-legend">
          <?php if ($tl_drive_pct > 0): ?>
            <div class="time-legend-item"><span class="time-legend-dot" style="background:#8B73A8;"></span>Drive (<?= $tl_drive_one ?>m each way)</div>
          <?php endif; ?>
          <?php if ($tl_hike_up_pct > 0): ?>
            <div class="time-legend-item"><span class="time-legend-dot" style="background:var(--accent);"></span>Hike Up (<?= formatTime($tl_hike_up) ?>)</div>
          <?php endif; ?>
          <div class="time-legend-item"><span class="time-legend-dot" style="background:var(--green);"></span>Radio (<?= formatTime($tl_activation) ?>)</div>
          <?php if ($tl_hike_down_pct > 0): ?>
            <div class="time-legend-item"><span class="time-legend-dot" style="background:#5BA4B8;"></span>Hike Down (<?= formatTime($tl_hike_down) ?>)</div>
          <?php endif; ?>
          <div class="time-legend-item" style="font-weight:600; color:var(--ink);">Total: <?= formatTime($tl_total) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Map -->
      <div id="summit-map"></div>
      <div class="map-buttons">
        <button type="button" id="btn-base-street"    class="btn btn-sm btn-map-active"  onclick="switchBase('street')">Street</button>
        <button type="button" id="btn-base-topo"      class="btn btn-sm btn-secondary"  onclick="switchBase('topo')">Topo</button>
        <button type="button" id="btn-base-satellite" class="btn btn-sm btn-secondary"  onclick="switchBase('satellite')">Satellite</button>
        <span class="map-divider"></span>
        <button type="button" id="btn-tmobile" class="carrier-btn" style="border-color:#E91E8C; color:#E91E8C; background:#fff;" onclick="toggleCarrier('tmobile')">T-Mo</button>
        <button type="button" id="btn-verizon" class="carrier-btn" style="border-color:#CD040B; color:#CD040B; background:#fff;" onclick="toggleCarrier('verizon')">VZW</button>
        <button type="button" id="btn-att"     class="carrier-btn" style="border-color:#00A8E0; color:#00A8E0; background:#fff;" onclick="toggleCarrier('att')">AT&amp;T</button>
        <?php if (!empty($summit['sota_ref'])): ?>
          <span class="map-divider"></span>
          <button type="button" id="btn-actzone" class="btn btn-sm btn-secondary" onclick="zoomToActivationZone()" disabled style="opacity:0.4;">Activation Zone</button>
        <?php endif; ?>
        <span class="map-divider"></span>
        <a href="https://www.google.com/maps/search/?api=1&query=<?= $summit['latitude'] ?>,<?= $summit['longitude'] ?>" target="_blank" class="btn btn-sm btn-ghost">Maps ↗</a>
        <?php if ($selected_address && $directions_lat && $directions_lng): ?>
          <a href="https://www.google.com/maps/dir/?api=1&origin=<?= urlencode($selected_address['address']) ?>&destination=<?= $directions_lat ?>,<?= $directions_lng ?>&travelmode=driving" target="_blank" class="btn btn-sm btn-ghost">Directions ↗</a>
        <?php endif; ?>
      </div>

      <!-- Elevation profile (only if GPX) -->
      <?php if ($gpx_data): ?>
      <div class="elev-wrap">
        <canvas id="elev-canvas"></canvas>
        <div class="elev-note" id="elev-note">Hover for elevation details</div>
      </div>
      <?php endif; ?>
      <?php if (!empty($summit['sota_ref'])): ?>
      <div id="az-methodology" style="font-size:0.75rem; color:var(--ink-3); margin-bottom:1rem; line-height:1.5;"></div>
      <?php endif; ?>

      <!-- Trail Research -->
      <hr class="section-divider">
      <div class="section-head">
        <span class="section-head-title">Trail Research</span>
      </div>

      <div class="field-row-2" style="margin-bottom:1rem;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Distance (<?= getDistanceUnit($current_group['units']) ?>, RT)</label>
          <input type="number" class="form-input" name="hike_distance_mi" step="0.01" min="0"
                 value="<?= htmlspecialchars($summit['hike_distance_mi'] ?? '') ?>" placeholder="0.0">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Elevation Gain (<?= getElevationUnit($current_group['units']) ?>)</label>
          <input type="number" class="form-input" name="hike_elevation_gain_ft" min="0"
                 value="<?= htmlspecialchars($summit['hike_elevation_gain_ft'] ?? '') ?>" placeholder="0">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label">Difficulty</label>
        <div class="diff-btns">
          <?php foreach (['drive-up','easy','moderate','hard'] as $d): ?>
            <button type="button" class="diff-btn<?= ($summit['difficulty'] === $d) ? ' active-'.$d : '' ?>"
                    onclick="setDifficulty('<?= $d ?>')" data-diff="<?= $d ?>"><?= ucfirst($d) ?></button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="difficulty" id="difficulty-input" value="<?= htmlspecialchars($summit['difficulty'] ?? '') ?>">
      </div>

      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label">Cell Service</label>
        <select class="form-select" name="cell_service">
          <option value="">Unknown</option>
          <option value="full"         <?= ($summit['cell_service'] ?? '') === 'full'         ? 'selected' : '' ?>>Full Coverage</option>
          <option value="intermittent" <?= ($summit['cell_service'] ?? '') === 'intermittent' ? 'selected' : '' ?>>Intermittent</option>
          <option value="summit_only"  <?= ($summit['cell_service'] ?? '') === 'summit_only'  ? 'selected' : '' ?>>Summit Only</option>
          <option value="none"         <?= ($summit['cell_service'] ?? '') === 'none'         ? 'selected' : '' ?>>No Service</option>
        </select>
      </div>

      <!-- Trailhead location -->
      <?php if (!empty($summit['trailhead_lat']) && !empty($summit['trailhead_lng'])): ?>
        <div style="background:var(--green-bg); border:1px solid #b8d9c9; border-radius:var(--r-md); padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem;">
          <div>
            <div style="font-size:0.8rem; font-weight:600; color:var(--green);">Trailhead Set</div>
            <div style="font-size:0.72rem; color:var(--ink-2); font-family:var(--font-mono); margin-top:2px;"><?= htmlspecialchars($summit['trailhead_lat']) ?>, <?= htmlspecialchars($summit['trailhead_lng']) ?></div>
          </div>
          <form method="POST" style="margin:0;">
            <button type="submit" name="reset_trailhead" class="btn btn-danger btn-sm" onclick="return confirm('Clear the saved trailhead coordinates?')">Reset</button>
          </form>
        </div>
        <input type="hidden" name="trailhead_lat" value="<?= htmlspecialchars($summit['trailhead_lat']) ?>">
        <input type="hidden" name="trailhead_lng" value="<?= htmlspecialchars($summit['trailhead_lng']) ?>">
      <?php else: ?>
        <div class="geocoder-box">
          <div style="font-size:0.8rem; font-weight:600; color:var(--ink); margin-bottom:0.5rem;">Set Trailhead Location</div>
          <div style="display:flex; gap:0.5rem; margin-bottom:0.4rem;">
            <input type="text" class="form-input" id="geocode_address" placeholder="Paste lat,lng or an address" style="flex:1; height:36px; padding:0.5rem 0.75rem;">
            <button type="button" class="btn btn-accent btn-sm" onclick="geocodeAddress()">Find</button>
          </div>
          <div class="form-hint" style="margin:0;">e.g. 34.168, -118.236 or a street address</div>
        </div>
        <div class="field-row-2" style="margin-bottom:1rem;">
          <div class="form-group" style="margin:0;">
            <label class="form-label">Trailhead Lat</label>
            <input type="number" class="form-input" id="trailhead_lat" name="trailhead_lat" step="0.000001"
                   value="" placeholder="34.168300">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Trailhead Lng</label>
            <input type="number" class="form-input" id="trailhead_lng" name="trailhead_lng" step="0.000001"
                   value="" placeholder="-118.236200">
          </div>
        </div>
      <?php endif; ?>

      <div class="form-group" style="margin-bottom:1rem;">
        <label class="form-label">Trail Reference Link</label>
        <input type="url" class="form-input" name="trail_link"
               value="<?= htmlspecialchars($summit['trail_link'] ?? '') ?>" placeholder="https://alltrails.com/...">
        <?php
          $search_name = urlencode($summit['name'] ?? '');
          $search_lat  = $summit['latitude'] ?? '';
          $search_lng  = $summit['longitude'] ?? '';
        ?>
        <div style="display:flex; flex-wrap:wrap; gap:0.4rem; margin-top:0.5rem;">
          <span style="font-size:0.75rem; color:var(--ink-3); align-self:center;">Search:</span>
          <a href="https://www.alltrails.com/explore?q=<?= $search_name ?>" target="_blank" class="trail-search-btn">AllTrails</a>
          <a href="https://www.gaiagps.com/map/?search=<?= $search_name ?>" target="_blank" class="trail-search-btn">Gaia GPS</a>
          <a href="https://caltopo.com/map.html#ll=<?= $search_lat ?>,<?= $search_lng ?>&z=14&b=t" target="_blank" class="trail-search-btn">CalTopo</a>
          <a href="https://www.hikingproject.com/directory/search?type=trail&q=<?= $search_name ?>" target="_blank" class="trail-search-btn">Hiking Project</a>
          <?php if (!empty($summit['sota_ref'])): ?>
            <a href="https://sotl.as/summits/<?= str_replace('%2F', '/', urlencode($summit['sota_ref'])) ?>" target="_blank" class="trail-search-btn">SOTLAS</a>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:flex; gap:0.75rem; padding-top:1rem; border-top:1px solid var(--border);">
        <button type="submit" name="update_summit" class="btn btn-primary">Save Changes</button>
        <?php if ($selected_address): ?>
          <button type="submit" name="calculate_drive_time" class="btn btn-secondary">Calculate Drive Time</button>
        <?php endif; ?>
      </div>
    </div>
  </form><!-- end main-edit-form — must close before right sidebar to avoid nested forms -->

    <!-- ═══ RIGHT SIDEBAR ═══ -->
    <div>
      <!-- Summit info -->
      <div class="card" style="margin-bottom:1rem;">
        <div style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:var(--ink-3); margin-bottom:0.875rem;">Summit Info</div>
        <div class="info-row">
          <span class="info-label">Elevation</span>
          <span class="info-val">
            <?php
              $elev_disp = convertElevation($summit['elevation_ft'], $current_group['units']);
              echo number_format($elev_disp) . ' ' . getElevationUnit($current_group['units']);
            ?>
          </span>
        </div>
        <div class="info-row">
          <span class="info-label">SOTA Points</span>
          <span class="info-val"><?= $summit['points'] ?> pts</span>
        </div>
        <div class="info-row">
          <span class="info-label">Coordinates</span>
          <span class="info-val" style="font-family:var(--font-mono); font-size:0.72rem;"><?= number_format((float)$summit['latitude'], 4) ?>°N, <?= number_format(abs((float)$summit['longitude']), 4) ?>°W</span>
        </div>
        <?php if ($distance_display_mi): ?>
        <div class="info-row">
          <span class="info-label">Distance (RT)</span>
          <span class="info-val"><?= number_format($distance_display_mi, 1) ?> <?= getDistanceUnit($current_group['units']) ?> <span style="color:var(--ink-3); font-size:0.72rem;"><?= $distance_source ?></span></span>
        </div>
        <?php endif; ?>
        <?php if ($elevation_gain_display): ?>
        <div class="info-row">
          <span class="info-label">Elevation Gain</span>
          <span class="info-val"><?= number_format(convertElevation($elevation_gain_display, $current_group['units'])) ?> <?= getElevationUnit($current_group['units']) ?> <span style="color:var(--ink-3); font-size:0.72rem;"><?= $elevation_source ?></span></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
          <span class="info-label">Nominated</span>
          <span class="info-val"><?= $summit['nominated_date'] ? date('M j, Y', strtotime($summit['nominated_date'])) : '—' ?></span>
        </div>
        <div class="info-row" style="border-bottom:none; padding-bottom:0;">
          <span class="info-label">Last Activated</span>
          <span class="info-val"><?= $summit['last_activated_date'] ? date('M j, Y', strtotime($summit['last_activated_date'])) : 'Never' ?></span>
        </div>
      </div>

      <!-- GPX Track -->
      <div class="card" style="margin-bottom:1rem;">
        <div style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:var(--ink-3); margin-bottom:0.875rem;">GPX Track</div>
        <?php if ($gpx_data): ?>
          <div style="font-size:0.78rem; color:var(--green); font-weight:500; margin-bottom:0.75rem;">
            Track loaded: <?= htmlspecialchars($gpx_data['filename']) ?>
          </div>
          <?php if ($has_timestamps): ?>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:0.75rem;">
            <div style="background:var(--bg-2); border:1px solid var(--border); border-radius:var(--r-md); padding:0.625rem; text-align:center;">
              <div style="font-size:0.65rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:var(--ink-3); margin-bottom:3px;">Hiking</div>
              <div style="font-size:0.95rem; font-weight:600; color:var(--ink);"><?= format_time_duration($gpx_data['hiking_time']) ?></div>
            </div>
            <div style="background:var(--bg-2); border:1px solid var(--border); border-radius:var(--r-md); padding:0.625rem; text-align:center;">
              <div style="font-size:0.65rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:var(--ink-3); margin-bottom:3px;">Summit</div>
              <div style="font-size:0.95rem; font-weight:600; color:var(--ink);"><?= format_time_duration($gpx_data['activation_time']) ?></div>
            </div>
          </div>
          <?php else: ?>
            <div style="font-size:0.78rem; color:var(--ink-3); margin-bottom:0.75rem;">Route-only GPX (no timestamps) — map &amp; elevation data loaded.</div>
          <?php endif; ?>
          <form method="POST" class="gps-prefs">
            <input type="hidden" name="update_gpx_preferences" value="1">
            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.82rem; font-weight:500; color:var(--ink); margin-bottom:0.625rem;">
              <input type="checkbox" name="use_gps_data" value="1" <?= ($gpx_data['use_for_hike_time']) ? 'checked' : '' ?> style="width:14px; height:14px;">
              Use GPS data for planning
            </label>
            <label class="form-label" style="margin-bottom:0.3rem; font-size:0.72rem;">Track type</label>
            <select name="track_type" class="form-select" style="height:32px; padding:0.25rem 0.625rem; font-size:0.8rem; margin-bottom:0.625rem;">
              <option value="round-trip" <?= $track_type === 'round-trip' ? 'selected' : '' ?>>Round-trip</option>
              <option value="ascent"     <?= $track_type === 'ascent'     ? 'selected' : '' ?>>Ascent only</option>
              <option value="descent"    <?= $track_type === 'descent'    ? 'selected' : '' ?>>Descent only</option>
            </select>
            <div style="display:flex; gap:0.5rem;">
              <button type="submit" class="btn btn-secondary btn-sm">Save Prefs</button>
              <a href="load_gpx.php?id=<?= $gpx_data['id'] ?>" download="<?= htmlspecialchars($gpx_download_name) ?>" class="btn btn-ghost btn-sm">Download GPX</a>
            </div>
          </form>
          <div style="margin-top:0.875rem; padding-top:0.875rem; border-top:1px solid var(--border);">
            <div style="font-size:0.75rem; color:var(--ink-3); margin-bottom:0.5rem;">Replace track:</div>
            <?php if (!empty($summit['sota_ref'])): ?>
            <div style="margin-bottom:0.625rem;">
              <button type="button" class="btn btn-secondary btn-sm btn-full" onclick="fetchSotaMaps(this)" id="sotamaps-fetch-btn">Import from SOTA Maps</button>
              <div id="sotamaps-result" style="margin-top:0.5rem; display:none;"></div>
            </div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
              <input type="file" name="gpx_file" accept=".gpx" style="font-size:0.8rem; color:var(--ink-2); width:100%; margin-bottom:0.5rem;">
              <button type="submit" class="btn btn-secondary btn-sm btn-full">Upload New GPX</button>
            </form>
          </div>
        <?php else: ?>
          <?php if (!empty($summit['sota_ref'])): ?>
          <div style="background:var(--green-bg); border:1px solid #b8d9c9; border-radius:var(--r-md); padding:0.75rem 1rem; margin-bottom:0.875rem;">
            <div style="font-size:0.82rem; font-weight:600; color:var(--green); margin-bottom:4px;">Import from SOTA Maps</div>
            <div style="font-size:0.78rem; color:var(--ink-2); margin-bottom:0.625rem;">Community route tracks from sotamaps.org</div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="fetchSotaMaps(this)" id="sotamaps-fetch-btn">Check for Tracks</button>
            <div id="sotamaps-result" style="margin-top:0.625rem; display:none;"></div>
          </div>
          <?php endif; ?>
          <form method="POST" enctype="multipart/form-data">
            <label class="gpx-drop" for="gpx-file-input">
              <div style="font-size:1.5rem; opacity:0.3; margin-bottom:0.5rem;">&#128196;</div>
              <div style="font-size:0.875rem; font-weight:500; color:var(--ink-2);">Drop a GPX file here</div>
              <div style="font-size:0.78rem; color:var(--ink-3); margin-top:3px;">or click to browse</div>
              <input type="file" name="gpx_file" id="gpx-file-input" accept=".gpx" style="display:none;" onchange="this.form.submit()">
            </label>
            <p class="form-hint" style="margin-top:0.625rem;">Upload a recorded hike or route. Recorded tracks (with timestamps) give precise timing; route-only files use Naismith's rule.</p>
          </form>
        <?php endif; ?>
      </div>

      <!-- Notes -->
      <div class="card">
        <div style="font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:var(--ink-3); margin-bottom:0.875rem;">Notes</div>
        <?php if (!empty($notes)): ?>
          <div style="margin-bottom:0.875rem;">
            <?php foreach ($notes as $note): ?>
              <div class="note-item">
                <div class="note-text"><?= htmlspecialchars($note['note']) ?></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px;">
                  <span class="note-meta"><?= date('M j, Y', strtotime($note['created_at'])) ?></span>
                  <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this note?');">
                    <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                    <button type="submit" name="delete_note" class="btn btn-danger btn-sm" style="height:24px; padding:0 8px; font-size:0.72rem;">Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <form method="POST">
          <textarea class="form-textarea" name="note" rows="3" placeholder="Add notes about access, parking, trail conditions..." style="font-size:0.875rem; resize:vertical;"></textarea>
          <button type="submit" name="add_note" class="btn btn-secondary btn-sm btn-full" style="margin-top:0.5rem;">Add Note</button>
        </form>
      </div>
    </div>
  </div>

  <!-- PLANNED ACTIVATIONS -->
  <div id="planned-activations" style="margin-top:1.5rem;">
    <div class="section-card">
      <div class="section-title">Planned Activations</div>

      <?php if (!empty($planned_activations_list)): ?>
        <?php foreach ($planned_activations_list as $pa):
          $peak_slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', ($summit['sota_ref'] ?? '') . '-' . ($summit['name'] ?? '')), '-'));
          $invite_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/activation_invite.php?id=' . $pa['id'] . '&peak=' . rawurlencode($peak_slug);
        ?>
          <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:0.875rem; background:var(--bg-2); border:1px solid var(--border); border-radius:var(--r-md); margin-bottom:0.625rem; gap:1rem; flex-wrap:wrap;">
            <div>
              <div style="font-size:0.9rem; font-weight:600; color:var(--ink);"><?= date('D, M j, Y', strtotime($pa['planned_date'])) ?></div>
              <div style="font-size:0.8rem; color:var(--ink-3); margin-top:3px;">
                Hike: <?= date('g:i A', strtotime($pa['hike_start_time'])) ?>
                &middot; <?= $pa['activation_duration_min'] ?>m radio
                &middot; <span style="font-family:var(--font-mono);"><?= htmlspecialchars($pa['callsigns']) ?></span>
              </div>
              <?php if ($pa['invitation_message']): ?>
                <div style="font-size:0.78rem; color:var(--ink-2); margin-top:4px; font-style:italic;">"<?= htmlspecialchars(substr($pa['invitation_message'], 0, 100)) ?><?= strlen($pa['invitation_message']) > 100 ? '…' : '' ?>"</div>
              <?php endif; ?>
              <div style="display:flex; gap:0.5rem; margin-top:0.625rem; flex-wrap:wrap;">
                <a href="<?= htmlspecialchars($invite_url) ?>" target="_blank" class="btn btn-accent btn-sm">View Invite</a>
                <button type="button" onclick="copyInviteLink('<?= htmlspecialchars($invite_url, ENT_QUOTES) ?>')" class="btn btn-ghost btn-sm">Copy Link</button>
              </div>
            </div>
            <form method="POST" style="margin:0; flex-shrink:0;" onsubmit="return confirm('Remove this planned activation?');">
              <input type="hidden" name="planned_activation_id" value="<?= $pa['id'] ?>">
              <button type="submit" name="delete_planned_activation" class="btn btn-danger btn-sm">×</button>
            </form>
          </div>
        <?php endforeach; ?>
        <div style="height:0.75rem;"></div>
      <?php endif; ?>

      <details>
        <summary style="cursor:pointer; font-size:0.875rem; font-weight:600; color:var(--accent); margin-bottom:0.875rem; display:inline-flex; align-items:center; gap:0.4rem;">
          + Schedule a New Activation
        </summary>
        <div style="background:var(--accent-bg); border:1px solid var(--accent-border); border-radius:var(--r-md); padding:0.7rem 0.875rem; margin-bottom:1rem; font-size:0.8rem; color:var(--accent-2); line-height:1.5;">
          <strong>Note:</strong> Scheduling an activation here is for your own planning only. SOTAWatch alert posting is coming soon — we'll add that once the SOTA API integration is complete.
        </div>
        <form method="POST" style="margin-top:0;">
          <div class="field-row-2" style="margin-bottom:1rem;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Date</label>
              <input type="date" name="planned_date" class="form-input" required value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Hike Start Time</label>
              <input type="time" name="hike_start_time" class="form-input" value="07:00">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Callsigns</label>
            <input type="text" name="planned_callsigns" class="form-input" placeholder="KI6CR/P, W6XX" required>
          </div>
          <div class="field-row-2" style="margin-bottom:1rem;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Activation Duration (min)</label>
              <input type="number" name="activation_duration_min" class="form-input" value="60" min="15" max="480" required>
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Location Link (optional)</label>
              <input type="url" name="location_link" class="form-input" placeholder="https://...">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Message for Guests (optional)</label>
            <textarea name="invitation_message" class="form-textarea" rows="3" placeholder="Join us for a SOTA activation!"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Parking &amp; Travel Notes (optional)</label>
            <textarea name="travel_notes" class="form-textarea" rows="2" placeholder="Parking details, carpooling info..."></textarea>
          </div>
          <button type="submit" name="add_planned_activation" class="btn btn-primary">Schedule Activation</button>
        </form>
      </details>
    </div>
  </div>

  <!-- ACTIVATION HISTORY -->
  <div style="margin-top:1rem;">
    <div class="section-card">
      <div class="section-title">Activation History</div>
      <?php
        $stmt = $db->prepare("SELECT * FROM activations WHERE summit_id = ? AND planning_group_id = ? ORDER BY activation_date DESC");
        $stmt->execute([$summit_id, $current_group['id']]);
        $activations = $stmt->fetchAll();
      ?>
      <?php if (!empty($activations)): ?>
        <div style="overflow-x:auto; margin-bottom:1.25rem;">
          <table class="mini-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Callsigns</th>
                <th>Notes</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($activations as $activation): ?>
                <tr>
                  <td style="white-space:nowrap; font-weight:500; color:var(--ink);"><?= date('M j, Y', strtotime($activation['activation_date'])) ?></td>
                  <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--ink);"><?= htmlspecialchars($activation['callsigns']) ?></td>
                  <td style="color:var(--ink-2);"><?= htmlspecialchars($activation['notes'] ?? '') ?></td>
                  <td style="text-align:right; white-space:nowrap;">
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this activation?');">
                      <input type="hidden" name="activation_id" value="<?= $activation['id'] ?>">
                      <button type="submit" name="delete_activation" class="btn btn-danger btn-sm" style="height:24px; padding:0 8px; font-size:0.72rem;">×</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p style="color:var(--ink-3); font-size:0.875rem; margin-bottom:1.25rem;">No activations recorded yet.</p>
      <?php endif; ?>

      <details>
        <summary style="cursor:pointer; font-size:0.875rem; font-weight:600; color:var(--accent); display:inline-flex; align-items:center; gap:0.4rem;">
          + Record an Activation
        </summary>
        <form method="POST" style="margin-top:0.875rem;">
          <div class="field-row-2" style="margin-bottom:1rem;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Date</label>
              <input type="date" name="activation_date" class="form-input" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Callsigns</label>
              <input type="text" name="activation_callsigns" class="form-input" placeholder="KI6CR/P" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Notes (optional)</label>
            <input type="text" name="activation_notes" class="form-input" placeholder="Conditions, gear notes...">
          </div>
          <button type="submit" name="add_activation" class="btn btn-primary">Record Activation</button>
        </form>
      </details>
    </div>
  </div>

  <!-- DANGER ZONE -->
  <div style="margin-top:1rem; margin-bottom:1rem;">
    <details>
      <summary style="cursor:pointer; font-size:0.78rem; color:var(--ink-4); display:inline-flex; align-items:center; gap:0.35rem;">
        Danger zone
      </summary>
      <div style="margin-top:0.75rem; padding:1rem; background:var(--red-bg); border:1px solid #e8baba; border-radius:var(--r-md);">
        <div style="font-size:0.875rem; color:var(--red); font-weight:500; margin-bottom:0.75rem;">This will permanently remove this summit from your planning list.</div>
        <form method="POST" onsubmit="return confirm('Remove this summit? This cannot be undone.');">
          <button type="submit" name="delete_summit" class="btn btn-danger">Remove Summit</button>
        </form>
      </div>
    </details>
  </div>

</div><!-- .page -->

<footer class="footer">
  SOTAplanner &middot; <a href="about.php">About</a> &middot; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

<script>
// ── Difficulty selector ─────────────────────────────────────────────────────
function setDifficulty(val) {
  document.getElementById('difficulty-input').value = val;
  document.querySelectorAll('.diff-btn').forEach(btn => {
    const d = btn.getAttribute('data-diff');
    btn.className = 'diff-btn' + (d === val ? ' active-' + d : '');
  });
}

// ── Geocoder ────────────────────────────────────────────────────────────────
function geocodeAddress() {
  const address = document.getElementById('geocode_address').value.trim();
  if (!address) { alert('Please enter an address or coordinates'); return; }
  const form = document.getElementById('main-edit-form');
  const g = document.createElement('input'); g.type = 'hidden'; g.name = 'geocode_address'; g.value = '1'; form.appendChild(g);
  const a = document.createElement('input'); a.type = 'hidden'; a.name = 'trailhead_address'; a.value = address; form.appendChild(a);
  form.submit();
}

// ── Copy invite link ────────────────────────────────────────────────────────
function copyInviteLink(url) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => { alert('Link copied to clipboard!'); });
  } else {
    prompt('Copy this link:', url);
  }
}

// ── Leaflet map ─────────────────────────────────────────────────────────────
const sumLat  = <?= (float)$summit['latitude'] ?>;
const sumLng  = <?= (float)$summit['longitude'] ?>;
const trailLat = <?= $summit['trailhead_lat'] ? (float)$summit['trailhead_lat'] : 'null' ?>;
const trailLng = <?= $summit['trailhead_lng'] ? (float)$summit['trailhead_lng'] : 'null' ?>;

const map = L.map('summit-map').setView([sumLat, sumLng], 13);

const baseLayers = {
  street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>', maxZoom: 19 }),
  topo:   L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',   { attribution: '© <a href="https://opentopomap.org">OpenTopoMap</a>',      maxZoom: 17 }),
  satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '© Esri', maxZoom: 19 })
};
let activeBase = 'street';
baseLayers.street.addTo(map);
let gpxPolyline = null, activationZoneLayer = null;

function switchBase(name) {
  if (name === activeBase) return;
  map.removeLayer(baseLayers[activeBase]);
  baseLayers[name].addTo(map);
  if (gpxPolyline) gpxPolyline.bringToFront();
  if (activationZoneLayer) activationZoneLayer.bringToFront();
  activeBase = name;
  ['street','topo','satellite'].forEach(n => {
    const b = document.getElementById('btn-base-' + n);
    if (!b) return;
    b.className = 'btn btn-sm ' + (n === name ? 'btn-map-active' : 'btn-secondary');
  });
}

// Summit marker
L.circleMarker([sumLat, sumLng], { radius: 7, color: '#C03030', fillColor: '#C03030', fillOpacity: 0.9, weight: 2 })
  .bindPopup('<strong><?= htmlspecialchars(addslashes($summit['name'])) ?></strong><br><?= htmlspecialchars(addslashes($summit['sota_ref'] ?? '')) ?>')
  .addTo(map);

// Trailhead marker
if (trailLat !== null && trailLng !== null) {
  L.circleMarker([trailLat, trailLng], { radius: 6, color: '#2D8653', fillColor: '#2D8653', fillOpacity: 0.9, weight: 2 })
    .bindPopup('Trailhead').addTo(map);
}

// Cell coverage layers
const tileOpts = { opacity: 0.5, maxNativeZoom: 13, maxZoom: 20, crossOrigin: true, attribution: 'FCC Form 477' };
const carrierLayers = {
  tmobile: L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/TMobile_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts),
  verizon: L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/Verizon_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts),
  att:     L.tileLayer('https://tiles.arcgis.com/tiles/YnOQrIGdN9JGtBh4/arcgis/rest/services/ATT_Mobility_LTE_Data/MapServer/tile/{z}/{y}/{x}', tileOpts)
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

function initActivationZone(poly) {
  let coords;
  if (Array.isArray(poly[0]) && Array.isArray(poly[0][0]) && Array.isArray(poly[0][0][0])) coords = poly[0][0].map(c => [c[1], c[0]]);
  else if (Array.isArray(poly[0]) && Array.isArray(poly[0][0])) coords = poly[0].map(c => [c[1], c[0]]);
  else coords = poly.map(c => [c[1], c[0]]);
  activationZoneLayer = L.polygon(coords, { color: '#CC2200', fillColor: '#CC2200', fillOpacity: 0.18, weight: 2, dashArray: '5,4' })
    .bindPopup('<strong>SOTA Activation Zone</strong>').addTo(map);
  const zBtn = document.getElementById('btn-actzone');
  if (zBtn) { zBtn.disabled = false; zBtn.style.opacity = '1'; }
  const az = document.getElementById('az-methodology');
  if (az) az.innerHTML = '<strong>Activation Zone:</strong> <span style="color:var(--green);">Precise terrain-based boundary</span> from <a href="https://activation.zone" target="_blank">Activation.Zone</a> by N6ARA.';
}
function setActivationZoneFallback() {
  const az = document.getElementById('az-methodology');
  if (az) az.textContent = 'Activation Zone: API unavailable — showing estimated 50m radius.';
}
function zoomToActivationZone() {
  if (!activationZoneLayer) return;
  map.fitBounds(activationZoneLayer.getBounds(), { padding: [40, 40] });
}

<?php if ($gpx_data): ?>
fetch('load_gpx.php?id=<?= $gpx_data['id'] ?>')
  .then(r => r.text())
  .then(gpxText => {
    const parser = new DOMParser();
    const gpx = parser.parseFromString(gpxText, 'text/xml');
    const pts = gpx.querySelectorAll('trkpt, rtept');
    const coords = [], elevPts = [];
    pts.forEach(pt => {
      const lat = parseFloat(pt.getAttribute('lat')), lon = parseFloat(pt.getAttribute('lon'));
      coords.push([lat, lon]);
      const ele = pt.querySelector('ele');
      if (ele) elevPts.push([lat, lon, parseFloat(ele.textContent)]);
    });
    gpxPolyline = L.polyline(coords, { color: '#9B6328', weight: 3, opacity: 0.85 }).addTo(map);
    const elevState = drawElevationProfile(elevPts);
    if (elevState) setupElevMapHover(elevState, map);
    map.fitBounds(L.polyline(coords).getBounds(), { padding: [50, 50] });
    <?php if ($gpx_data['using_api'] && $gpx_data['activation_zone_polygon']): ?>
    initActivationZone(<?= $gpx_data['activation_zone_polygon'] ?>);
    <?php elseif (!empty($summit['sota_ref'])): ?>
    fetch('activation_zone.php?sota_ref=<?= urlencode($summit['sota_ref']) ?>')
      .then(r => r.json())
      .then(data => { if (data.polygon) initActivationZone(data.polygon); else setActivationZoneFallback(); })
      .catch(() => setActivationZoneFallback());
    <?php endif; ?>
  });

function drawElevationProfile(elevPts) {
  const canvas = document.getElementById('elev-canvas');
  const note   = document.getElementById('elev-note');
  if (!canvas) return null;
  if (elevPts.length < 2) { if (note) note.textContent = 'No elevation data.'; return null; }
  const W = Math.floor(canvas.getBoundingClientRect().width) || 600, H = 120;
  const dpr = window.devicePixelRatio || 1;
  canvas.width = W * dpr; canvas.height = H * dpr;
  const ctx = canvas.getContext('2d'); ctx.scale(dpr, dpr);
  let pts = elevPts;
  if (pts.length > 500) { const step = Math.ceil(pts.length / 500); pts = pts.filter((_, i) => i % step === 0 || i === pts.length - 1); }
  function hDist(lat1, lon1, lat2, lon2) {
    const R = 6371, r = Math.PI/180, dLat = (lat2-lat1)*r, dLon = (lon2-lon1)*r;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*r)*Math.cos(lat2*r)*Math.sin(dLon/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  }
  const useMetric = <?= $current_group['units'] === 'metric' ? 'true' : 'false' ?>;
  const eleConv = useMetric ? 1 : 3.28084, distConv = useMetric ? 1 : 0.621371;
  const eleUnit = useMetric ? 'm' : 'ft', distUnit = useMetric ? 'km' : 'mi';
  const data = []; let cumD = 0;
  for (let i = 0; i < pts.length; i++) {
    if (i > 0) cumD += hDist(pts[i-1][0], pts[i-1][1], pts[i][0], pts[i][1]);
    data.push({ d: cumD * distConv, e: pts[i][2] * eleConv, lat: pts[i][0], lon: pts[i][1] });
  }
  const eles = data.map(p => p.e), minE = Math.min(...eles), maxE = Math.max(...eles), maxD = data[data.length-1].d;
  const pad = {top:8,right:12,bottom:24,left:46}, plotW = W-pad.left-pad.right, plotH = H-pad.top-pad.bottom;
  const xS = d => pad.left + (d/maxD)*plotW;
  const yS = e => pad.top + (1-(e-minE)/((maxE-minE)||1))*plotH;
  const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top+plotH);
  grad.addColorStop(0, 'rgba(43,142,142,0.32)'); grad.addColorStop(1, 'rgba(43,142,142,0.03)');
  ctx.beginPath(); ctx.moveTo(xS(data[0].d), pad.top+plotH);
  for (const p of data) ctx.lineTo(xS(p.d), yS(p.e));
  ctx.lineTo(xS(maxD), pad.top+plotH); ctx.closePath(); ctx.fillStyle = grad; ctx.fill();
  ctx.beginPath(); ctx.moveTo(xS(data[0].d), yS(data[0].e));
  for (const p of data) ctx.lineTo(xS(p.d), yS(p.e));
  ctx.strokeStyle = 'rgba(43,142,142,0.85)'; ctx.lineWidth = 1.5; ctx.stroke();
  ctx.fillStyle = '#8C8A86'; ctx.font = '10px DM Mono,monospace'; ctx.textAlign = 'right';
  [minE, (minE+maxE)/2, maxE].forEach(e => ctx.fillText(Math.round(e)+' '+eleUnit, pad.left-4, yS(e)+3));
  ctx.textAlign = 'center';
  [0, maxD/2, maxD].forEach(d => ctx.fillText(d.toFixed(1)+' '+distUnit, xS(d), H-4));
  return { data, xS, yS, W, H, pad, plotW, plotH, minE, maxE, maxD, eleUnit, distUnit };
}

function setupElevMapHover(s, mapRef) {
  const canvas = document.getElementById('elev-canvas');
  const note = document.getElementById('elev-note');
  let hoverMarker = null;
  canvas.addEventListener('mousemove', e => {
    const rect = canvas.getBoundingClientRect();
    const x = (e.clientX - rect.left) * (canvas.width / rect.width / (window.devicePixelRatio || 1));
    const d = ((x - s.pad.left) / s.plotW) * s.maxD;
    if (d < 0 || d > s.maxD) { if (note) note.textContent = 'Hover for elevation details'; return; }
    let closest = s.data[0], minDist = Infinity;
    for (const p of s.data) { const dd = Math.abs(p.d - d); if (dd < minDist) { minDist = dd; closest = p; } }
    if (note) note.textContent = Math.round(closest.e) + ' ' + s.eleUnit + ' @ ' + closest.d.toFixed(2) + ' ' + s.distUnit;
    if (!hoverMarker) hoverMarker = L.circleMarker([closest.lat, closest.lon], { radius: 5, color: '#9B6328', fillColor: '#9B6328', fillOpacity: 0.85, weight: 2 }).addTo(mapRef);
    else hoverMarker.setLatLng([closest.lat, closest.lon]);
  });
  canvas.addEventListener('mouseleave', () => {
    if (note) note.textContent = 'Hover for elevation details';
    if (hoverMarker) { mapRef.removeLayer(hoverMarker); hoverMarker = null; }
  });
}
<?php endif; ?>

<?php if (!$gpx_data && !empty($summit['sota_ref'])): ?>
// No GPX — fetch activation zone directly
fetch('activation_zone.php?sota_ref=<?= urlencode($summit['sota_ref']) ?>')
  .then(r => r.json())
  .then(data => { if (data.polygon) initActivationZone(data.polygon); else setActivationZoneFallback(); })
  .catch(() => setActivationZoneFallback());
<?php endif; ?>

// ── SOTA Maps GPX Import ────────────────────────────────────────────────────
const SOTAMAPS_SOTA_REF  = <?= json_encode($summit['sota_ref'] ?? '') ?>;
const SOTAMAPS_SUMMIT_ID = <?= intval($summit_id) ?>;
const SOTAMAPS_GROUP_ID  = <?= intval($current_group['id']) ?>;

function fetchSotaMaps(fetchBtn) {
  const resultEl = fetchBtn.closest('div').querySelector('#sotamaps-result')
                   || document.getElementById('sotamaps-result');

  if (!SOTAMAPS_SOTA_REF) {
    resultEl.style.display = 'block';
    resultEl.innerHTML = '<em style="font-size:0.8rem; color:var(--ink-3);">No SOTA reference set for this summit.</em>';
    return;
  }

  fetchBtn.disabled = true;
  fetchBtn.textContent = 'Checking…';
  resultEl.style.display = 'none';

  const url = 'import_sotamaps_gpx.php?action=list'
            + '&sota_ref=' + encodeURIComponent(SOTAMAPS_SOTA_REF)
            + '&summit_id=' + SOTAMAPS_SUMMIT_ID;

  fetch(url)
    .then(r => r.json())
    .then(data => {
      fetchBtn.disabled = false;
      fetchBtn.textContent = 'Import from SOTA Maps';
      resultEl.style.display = 'block';
      resultEl.innerHTML = renderTrackList(data);
    })
    .catch(() => {
      fetchBtn.disabled = false;
      fetchBtn.textContent = 'Import from SOTA Maps';
      resultEl.style.display = 'block';
      resultEl.innerHTML = '<span style="font-size:0.8rem; color:var(--red);">Network error — could not reach SOTA Maps API.</span>';
    });
}

function renderTrackList(data) {
  if (data.error)   return '<span style="font-size:0.8rem; color:var(--red);">Error: ' + escHtml(data.error) + '</span>';
  if (data.message) return '<em style="font-size:0.8rem; color:var(--ink-3);">' + escHtml(data.message) + '</em>';
  if (!data.tracks || data.tracks.length === 0)
    return '<em style="font-size:0.8rem; color:var(--ink-3);">No tracks found for this summit on SOTA Maps.</em>';

  let html = '<div style="display:flex;flex-direction:column;gap:0.4rem;">';
  for (const t of data.tracks) {
    const date  = t.posted_date ? t.posted_date.slice(0, 10) : '';
    const notes = t.notes ? '<div style="font-size:0.75rem;color:var(--ink-3);margin-top:2px;">' + escHtml(t.notes) + '</div>' : '';
    html += `<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;
                  padding:0.5rem 0.75rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);">
      <div>
        <div style="font-size:0.82rem;font-weight:500;color:var(--ink);">${escHtml(t.title)}</div>
        <div style="font-size:0.75rem;color:var(--ink-3);">${escHtml(t.callsign)} &middot; ${escHtml(date)} &middot; ${t.point_count} pts</div>
        ${notes}
      </div>
      <button class="btn btn-accent btn-sm" style="flex-shrink:0;white-space:nowrap;"
              onclick="importSotaMapsTrack(${t.hdr_id}, this)">Import</button>
    </div>`;
  }
  html += '</div>';
  return html;
}

function importSotaMapsTrack(hdrId, btn) {
  if (!confirm('Import this track? It will replace any existing GPX for this summit.')) return;
  btn.disabled = true;
  btn.textContent = 'Importing…';

  const body = new URLSearchParams({
    action:    'import',
    sota_ref:  SOTAMAPS_SOTA_REF,
    summit_id: SOTAMAPS_SUMMIT_ID,
    hdr_id:    hdrId
  });

  fetch('import_sotamaps_gpx.php', { method: 'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        btn.textContent = 'Imported!';
        setTimeout(() => {
          window.location.href = 'summit_detail.php?id=' + SOTAMAPS_SUMMIT_ID
            + '&group=' + SOTAMAPS_GROUP_ID + '&gpx=1';
        }, 800);
      } else {
        btn.disabled = false;
        btn.textContent = 'Import';
        alert('Import failed: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = 'Import';
      alert('Network error during import.');
    });
}

function escHtml(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Floating save / top widget ──────────────────────────────────────────────
// DOMContentLoaded ensures the widget div (placed after this script block) is in the DOM.
document.addEventListener('DOMContentLoaded', function() {
  const widget = document.getElementById('float-widget');
  if (!widget) return;
  function checkScroll() {
    const show = window.scrollY > 280;
    widget.style.opacity = show ? '1' : '0';
    widget.style.pointerEvents = show ? 'auto' : 'none';
  }
  window.addEventListener('scroll', checkScroll, { passive: true });
  checkScroll();
});

// Auto-dismiss flash
const flash = document.getElementById('flash-msg');
if (flash) setTimeout(() => { flash.style.transition = 'opacity 0.5s'; flash.style.opacity = '0'; setTimeout(() => flash.remove(), 500); }, 4000);

// User chip dropdown
(function() {
    var chip = document.getElementById('userChip');
    if (!chip) return;
    chip.addEventListener('click', function(e) { e.stopPropagation(); this.classList.toggle('open'); });
    document.addEventListener('click', function() { chip.classList.remove('open'); });
})();
</script>

<!-- Floating save / top widget -->
<div id="float-widget" style="
  position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 150;
  display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;
  opacity: 0; pointer-events: none;
  transition: opacity 0.2s ease;
">
  <button type="submit" form="main-edit-form" name="update_summit" style="
    background: #6B6865; color: #fff; border: none;
    border-radius: var(--r-md); height: 40px; padding: 0 1.1rem;
    font-family: var(--font-sans); font-size: 0.875rem; font-weight: 600;
    cursor: pointer; white-space: nowrap;
    box-shadow: 0 4px 14px rgba(0,0,0,0.22);
    display: flex; align-items: center; gap: 0.4rem;
    transition: background 0.15s;
  " onmouseover="this.style.background='#5C5956'" onmouseout="this.style.background='#6B6865'">
    <svg width="13" height="13" viewBox="0 0 13 13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 8.5v1.5a1 1 0 01-1 1H3a1 1 0 01-1-1V8.5"/><polyline points="9,4.5 6.5,2 4,4.5"/><line x1="6.5" y1="2" x2="6.5" y2="8.5"/></svg>
    Save Changes
  </button>
  <button type="button" onclick="window.scrollTo({top:0,behavior:'smooth'})" style="
    background: var(--surface); color: var(--ink-2);
    border: 1px solid var(--border); border-radius: var(--r-md);
    height: 34px; padding: 0 0.875rem;
    font-family: var(--font-sans); font-size: 0.8rem; font-weight: 500;
    cursor: pointer; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex; align-items: center; gap: 0.35rem;
    transition: background 0.15s;
  " onmouseover="this.style.background='var(--bg-2)'" onmouseout="this.style.background='var(--surface)'">
    <svg width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2,7 5.5,3.5 9,7"/></svg>
    Back to Top
  </button>
</div>
</body>
</html>
