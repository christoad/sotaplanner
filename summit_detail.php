<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($summit['name']) ?> - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
            --light-blue: #5BA4B8;
            --gold: #E6B84A;
            --tan: #D4A574;
            --snow: #F5F5F0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, var(--snow) 0%, #E8E4D8 100%);
            color: var(--navy);
            padding: 1.5rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .logo {
            height: 50px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: white;
            background: var(--teal);
            border: 2px solid var(--teal);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.55rem 1.2rem;
            border-radius: 8px;
            transition: opacity 0.2s;
        }
        .back-link:hover { opacity: 0.85; }

        .summit-header {
            background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        .summit-header h1 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .summit-ref {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .quick-link {
            display: inline-block;
            padding: 0.6rem 1.1rem;
            background: rgba(255, 255, 255, 0.25);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            white-space: nowrap;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .quick-link:hover {
            background: rgba(255, 255, 255, 0.35);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .message.success {
            background: #E6F4EA;
            color: #1E7E34;
        }

        .message.error {
            background: #FDECEA;
            color: #C62828;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            background: white;
            padding: 0.55rem 0.8rem;
            border-radius: 8px;
            border-left: 3px solid var(--teal);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #888;
            margin-bottom: 0.15rem;
            font-weight: 600;
            line-height: 1.3;
        }

        .stat-value {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--navy);
            line-height: 1.2;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .card h2 {
            color: var(--navy);
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem 1.5rem;
        }

        .form-grid .full-width {
            grid-column: 1 / -1;
        }

        .form-row {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.compact select {
            width: auto;
        }

        .form-group.compact input[type="number"] {
            width: 100px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.4rem;
            color: var(--navy);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid var(--tan);
            border-radius: 6px;
            font-family: 'Overpass', sans-serif;
            font-size: 0.95rem;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--teal);
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            padding: 0.7rem 1.5rem;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
            transition: all 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .trail-search-btn {
            display: inline-block;
            padding: 0.25rem 0.65rem;
            background: #f0f0f0;
            color: #333;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.15s;
        }
        .trail-search-btn:hover {
            background: var(--teal);
            color: white;
            border-color: var(--teal);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--gold) 0%, var(--tan) 100%);
            color: var(--navy);
        }

        .btn-danger {
            background: linear-gradient(135deg, #C62828 0%, #8E0000 100%);
        }

        .geocode-box {
            background: #E8F4F8;
            padding: 1.25rem;
            border-radius: 8px;
            border: 2px solid var(--teal);
        }

        .note-item {
            background: var(--snow);
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border-left: 3px solid var(--gold);
        }

        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .note-meta {
            font-size: 0.8rem;
            color: #666;
            font-weight: 600;
        }

        .links-list {
            list-style: none;
        }

        .links-list li {
            margin-bottom: 0.75rem;
        }

        .links-list a {
            color: var(--teal);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* ===========================
           MOBILE RESPONSIVE STYLES
           =========================== */
        
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .container {
                padding: 0;
            }

            header {
                flex-direction: column;
                padding: 1rem;
                gap: 1rem;
            }

            header img {
                height: 60px;
            }

            h1 {
                font-size: 1.75rem;
            }

            h2 {
                font-size: 1.3rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.compact input[type="number"] {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .stat-label {
                font-size: 0.7rem;
            }

            .card {
                padding: 1.5rem;
                margin-bottom: 1rem;
            }

            .form-group {
                margin-bottom: 1.25rem;
            }

            input[type="text"],
            input[type="number"],
            input[type="date"],
            select,
            textarea {
                width: 100%;
                font-size: 1rem;
            }

            .btn {
                width: 100%;
                padding: 0.9rem 1rem;
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }

            /* Trailhead coordinates grid */
            .form-group.full-width > div {
                grid-template-columns: 1fr !important;
                gap: 0.75rem !important;
            }

            /* Activation history table */
            table {
                font-size: 0.85rem;
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            th, td {
                padding: 0.6rem 0.4rem;
                white-space: nowrap;
            }

            /* Links grid */
            .links-grid {
                grid-template-columns: 1fr;
            }

            /* Map buttons */
            .map-buttons {
                flex-direction: column;
            }

            .map-buttons .btn {
                width: 100%;
            }

            .back-link {
                font-size: 0.9rem;
                margin-bottom: 1rem;
            }
        }

        /* Extra small screens */
        @media (max-width: 375px) {
            body {
                padding: 0.5rem;
            }

            header {
                padding: 0.75rem;
            }

            h1 {
                font-size: 1.5rem;
            }

            h2 {
                font-size: 1.15rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 1rem;
            }

            .btn {
                padding: 0.8rem;
                font-size: 0.85rem;
            }

            .stat-value {
                font-size: 1.25rem;
            }
        }
    </style>
    <!-- Leaflet.js for GPS maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <img src="logo.png" alt="SOTA Planner" class="logo">
            <a href="index.php" class="back-link">← Back to Dashboard</a>
        </div>
        
        <div class="summit-header">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1><?= htmlspecialchars($summit['name']) ?></h1>
                    <p class="summit-ref"><?= htmlspecialchars($summit['sota_ref']) ?> • <?= htmlspecialchars($summit['region']) ?></p>
                    <?php if ($selected_address): ?>
                        <p style="font-size: 0.9rem; opacity: 0.85; margin-top: 0.5rem;">
                            📍 Calculating from: <strong><?= htmlspecialchars($selected_address['label'] ?: $selected_address['address']) ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <a href="https://www.google.com/maps/search/?api=1&query=<?= $summit['latitude'] ?>,<?= $summit['longitude'] ?>" target="_blank" class="quick-link" title="View Summit on Google Maps">🗺️ Maps</a>
                    <?php if ($selected_address && $directions_lat && $directions_lng): ?>
                        <a href="https://www.google.com/maps/dir/?api=1&origin=<?= urlencode($selected_address['address']) ?>&destination=<?= $directions_lat ?>,<?= $directions_lng ?>&travelmode=driving" target="_blank" class="quick-link" title="Driving Directions to Trailhead">🚗 Directions</a>
                    <?php endif; ?>
                    <?php if ($summit['sotlas_link']): ?>
                        <a href="<?= htmlspecialchars($summit['sotlas_link']) ?>" target="_blank" class="quick-link" title="View on SOTLas">📡 SOTLAS</a>
                    <?php endif; ?>
                    <?php if ($summit['trail_link']): ?>
                        <a href="<?= htmlspecialchars($summit['trail_link']) ?>" target="_blank" class="quick-link" title="Trail Information">🥾 Trail Info</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['shared_data'])): ?>
            <div class="message success">
                ✓ This summit was researched by another group. Their trail data has been copied as a starting point. You can edit and customize it for your group below.
            </div>
        <?php endif; ?>

        <?php if ($summit['uses_shared_data'] && $summit['source_group_id']): ?>
            <?php
                // Get source group name
                $stmt = $db->prepare("SELECT name FROM planning_groups WHERE id = ?");
                $stmt->execute([$summit['source_group_id']]);
                $source_group = $stmt->fetch();
            ?>
            <div style="background: #E8F4F8; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid var(--teal);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong>ℹ️ Using shared research</strong><br>
                        <span style="color: #666;">Trail data from: <?= htmlspecialchars($source_group['name'] ?? 'Another group') ?></span>
                    </div>
                    <form method="POST" style="margin: 0;">
                        <button type="submit" name="use_custom_data" class="btn btn-secondary btn-small">
                            Use Custom Data
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats Grid - Full Width -->
        <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.4rem;">
            <span style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#aaa; font-weight:600;">Planning Summary</span>
            <button onclick="document.getElementById('statsModal').style.display='flex'" style="background:none;border:1px solid #b0c4d0;color:#4A90A4;border-radius:50%;width:18px;height:18px;font-size:0.65rem;cursor:pointer;font-weight:700;padding:0;line-height:1;" title="About these tiles">ℹ</button>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">SOTA Points</div>
                <div class="stat-value"><?= $summit['points'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Elevation</div>
                <div class="stat-value">
                    <?php
                    $elevation = convertElevation($summit['elevation_ft'], $current_group['units']);
                    $unit = getElevationUnit($current_group['units']);
                    echo number_format($elevation) . ' ' . $unit;
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Distance
                    <?php if ($distance_source === 'GPS'): ?>
                        <span style="color: var(--trail-green); font-size: 0.7rem;">(GPS recorded)</span>
                    <?php elseif ($distance_source === 'Route'): ?>
                        <span style="color: #E6A020; font-size: 0.7rem;">(route file)</span>
                    <?php endif; ?>
                </div>
                <div class="stat-value">
                    <?php
                    if ($distance_display_mi) {
                        $distance = convertDistance($distance_display_mi, $current_group['units']);
                        $unit = getDistanceUnit($current_group['units']);
                        echo $distance . ' ' . $unit;
                    } else {
                        echo '—';
                    }
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Gain
                    <?php if ($elevation_source === 'GPS'): ?>
                        <span style="color: var(--trail-green); font-size: 0.7rem;">(GPS)</span>
                    <?php endif; ?>
                </div>
                <div class="stat-value">
                    <?php
                    if ($elevation_gain_display) {
                        $gain = convertElevation($elevation_gain_display, $current_group['units']);
                        $unit = getElevationUnit($current_group['units']);
                        echo number_format($gain) . ' ' . $unit;
                    } else {
                        echo '—';
                    }
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Hike Time (RT)
                    <?php if ($hike_time_source === 'GPS'): ?>
                        <span style="color: var(--trail-green); font-size: 0.7rem;">(GPS recorded)</span>
                    <?php elseif ($hike_time_source === 'Estimated'): ?>
                        <span style="color: #E6A020; font-size: 0.7rem;">*estimated</span>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $hike_time_total ? formatTime($hike_time_total) : '—' ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Drive Time (RT)</div>
                <div class="stat-value"><?= $summit['drive_time_min'] ? formatTime($summit['drive_time_min']) : '—' ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Last Activated</div>
                <div class="stat-value" style="font-size: 1.1rem;">
                    <?= $summit['last_activated_date'] ? date('M j, Y', strtotime($summit['last_activated_date'])) : '—' ?>
                </div>
            </div>
        </div>

        <!-- Edit Form - Compact Grid -->
        <div class="card">
            <h2>Edit Summit Details <button onclick="document.getElementById('editModal').style.display='flex'" style="background:none;border:1px solid #b0c4d0;color:#4A90A4;border-radius:50%;width:22px;height:22px;font-size:0.75rem;cursor:pointer;font-weight:700;padding:0;line-height:1;vertical-align:middle;margin-left:0.4rem;" title="About these fields">ℹ</button></h2>
            <form method="POST">
                <div class="form-grid">

                    <!-- Left column: compact fields -->
                    <div class="form-row">
                        <div class="form-group compact">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="nominated" <?= $summit['status'] === 'nominated' ? 'selected' : '' ?>>Nominated</option>
                                <option value="researched" <?= $summit['status'] === 'researched' ? 'selected' : '' ?>>Researched</option>
                                <option value="ready" <?= $summit['status'] === 'ready' ? 'selected' : '' ?>>Ready</option>
                                <option value="activated" <?= $summit['status'] === 'activated' ? 'selected' : '' ?>>Activated</option>
                            </select>
                        </div>

                        <div class="form-group compact">
                            <label for="difficulty">Difficulty</label>
                            <select id="difficulty" name="difficulty">
                                <option value="">Not Set</option>
                                <option value="drive-up" <?= $summit['difficulty'] === 'drive-up' ? 'selected' : '' ?>>Drive-Up</option>
                                <option value="easy" <?= $summit['difficulty'] === 'easy' ? 'selected' : '' ?>>Easy</option>
                                <option value="moderate" <?= $summit['difficulty'] === 'moderate' ? 'selected' : '' ?>>Moderate</option>
                                <option value="hard" <?= $summit['difficulty'] === 'hard' ? 'selected' : '' ?>>Hard</option>
                            </select>
                        </div>

                        <div class="form-group compact">
                            <label for="cell_service">Cell Service</label>
                            <select id="cell_service" name="cell_service">
                                <option value="">Unknown</option>
                                <option value="full" <?= ($summit['cell_service'] ?? '') === 'full' ? 'selected' : '' ?>>Full Coverage</option>
                                <option value="intermittent" <?= ($summit['cell_service'] ?? '') === 'intermittent' ? 'selected' : '' ?>>Intermittent</option>
                                <option value="summit_only" <?= ($summit['cell_service'] ?? '') === 'summit_only' ? 'selected' : '' ?>>Summit Only</option>
                                <option value="none" <?= ($summit['cell_service'] ?? '') === 'none' ? 'selected' : '' ?>>No Service</option>
                            </select>
                        </div>

                        <div style="display: flex; gap: 0.75rem; align-items: flex-end;">
                            <div class="form-group compact">
                                <label for="hike_distance_mi">Distance (<?= getDistanceUnit($current_group['units']) ?>)</label>
                                <input type="number" id="hike_distance_mi" name="hike_distance_mi" step="0.01" value="<?= $summit['hike_distance_mi'] ?>">
                            </div>
                            <div class="form-group compact">
                                <label for="hike_elevation_gain_ft">Gain (<?= getElevationUnit($current_group['units']) ?>)</label>
                                <input type="number" id="hike_elevation_gain_ft" name="hike_elevation_gain_ft" value="<?= $summit['hike_elevation_gain_ft'] ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Right column: trailhead geocoder + lat/lng -->
                    <div class="form-row">
                        <div style="background: #E8F4F8; padding: 0.75rem; border-radius: 6px; border: 2px solid var(--teal);">
                            <div style="font-size: 0.85rem; margin-bottom: 0.5rem; font-weight: 600;">📍 Set Trailhead</div>
                            <div style="display: flex; gap: 0.5rem;">
                                <input type="text" id="geocode_address"
                                       placeholder="Paste lat,lng or enter an address"
                                       style="flex: 1; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 0.85rem;">
                                <button type="button" onclick="geocodeAddress()" class="btn btn-small" style="white-space: nowrap;">Find</button>
                            </div>
                            <small style="color: #666; font-size: 0.75rem; display: block; margin-top: 0.4rem;">
                                💡 e.g. 34.168, -118.236 or a street address
                            </small>
                        </div>

                        <div style="display: flex; gap: 0.75rem;">
                            <div class="form-group" style="flex: 1;">
                                <label for="trailhead_lat">Trailhead Lat</label>
                                <input type="number" id="trailhead_lat" name="trailhead_lat" step="0.000001"
                                       value="<?= $summit['trailhead_lat'] ?>" placeholder="34.168300">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label for="trailhead_lng">Trailhead Lng</label>
                                <input type="number" id="trailhead_lng" name="trailhead_lng" step="0.000001"
                                       value="<?= $summit['trailhead_lng'] ?>" placeholder="-118.236200">
                            </div>
                        </div>
                    </div>

                    <script>
                    function geocodeAddress() {
                        const address = document.getElementById('geocode_address').value;
                        if (!address) {
                            alert('Please enter an address');
                            return;
                        }
                        
                        // Get the main form (the parent form element)
                        const mainForm = document.querySelector('form[method="POST"]');
                        
                        // Create hidden input for geocode flag
                        const geocodeInput = document.createElement('input');
                        geocodeInput.type = 'hidden';
                        geocodeInput.name = 'geocode_address';
                        geocodeInput.value = '1';
                        mainForm.appendChild(geocodeInput);
                        
                        // Create hidden input for trailhead address
                        const addressInput = document.createElement('input');
                        addressInput.type = 'hidden';
                        addressInput.name = 'trailhead_address';
                        addressInput.value = address;
                        mainForm.appendChild(addressInput);
                        
                        // Submit the main form (includes all other fields!)
                        mainForm.submit();
                    }
                    </script>

                    <div class="form-group full-width">
                        <label for="trail_link">Trail Link</label>
                        <input type="text" id="trail_link" name="trail_link" value="<?= htmlspecialchars($summit['trail_link'] ?? '') ?>">
                        <?php
                            $search_name = urlencode($summit['name'] ?? '');
                            $search_lat  = $summit['latitude'] ?? '';
                            $search_lng  = $summit['longitude'] ?? '';
                        ?>
                        <div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.5rem;">
                            <span style="font-size:0.8rem;color:#888;align-self:center;">Search:</span>
                            <a href="https://www.alltrails.com/explore?q=<?= $search_name ?>" target="_blank" class="trail-search-btn">AllTrails</a>
                            <a href="https://www.gaiagps.com/map/?search=<?= $search_name ?>" target="_blank" class="trail-search-btn">Gaia GPS</a>
                            <a href="https://caltopo.com/map.html#ll=<?= $search_lat ?>,<?= $search_lng ?>&z=14&b=t" target="_blank" class="trail-search-btn">CalTopo</a>
                            <a href="https://www.hikingproject.com/directory/search?type=trail&q=<?= $search_name ?>" target="_blank" class="trail-search-btn">Hiking Project</a>
                            <?php if (!empty($summit['sota_ref'])): ?>
                            <a href="https://sotl.as/summits/<?= urlencode($summit['sota_ref']) ?>" target="_blank" class="trail-search-btn">SOTLAS</a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                    <button type="submit" name="update_summit" class="btn">💾 Save Changes</button>
                    <?php if ($selected_address): ?>
                        <button type="submit" name="calculate_drive_time" class="btn btn-secondary">🚗 Calculate Drive Time</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>


        <!-- GPS Track Analysis -->
        <div class="card">
            <h2>📊 GPS Track Analysis <button onclick="document.getElementById('gpxInfoModal').style.display='flex'" style="background:none;border:1px solid #b0c4d0;color:#4A90A4;border-radius:50%;width:22px;height:22px;font-size:0.75rem;cursor:pointer;font-weight:700;padding:0;line-height:1;vertical-align:middle;margin-left:0.4rem;" title="About GPS data &amp; planning">ℹ</button></h2>
            
            <?php if ($gpx_data): ?>
                <!-- Existing GPX -->
                <div style="background: #E8F5E9; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border-left: 4px solid var(--trail-green);">
                    <strong>✓ GPS Track Loaded:</strong> <?= htmlspecialchars($gpx_data['filename']) ?>
                    <small style="display: block; color: #666; margin-top: 0.5rem;">
                        Uploaded: <?= date('M j, Y g:i A', strtotime($gpx_data['uploaded_date'])) ?>
                    </small>
                </div>

                <!-- Stats Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: #f9f9f9; padding: 1rem; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                        <div style="font-size: 1.5rem;">🚶</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--teal); margin: 0.5rem 0;">
                            <?= format_time_duration($gpx_data['hiking_time']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #666; font-weight: 600;">Hiking Time</div>
                        <small style="color: #999; font-size: 0.7rem;">Excludes activation zone</small>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 1rem; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                        <div style="font-size: 1.5rem;">📻</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--navy); margin: 0.5rem 0;">
                            <?= format_time_duration($gpx_data['activation_time']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #666; font-weight: 600;">Activation Time</div>
                        <small style="color: #999; font-size: 0.7rem;">Time in zone</small>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 1rem; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                        <div style="font-size: 1.5rem;">📏</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--forest-dark); margin: 0.5rem 0;">
                            <?php
                            $dist = convertDistance($gpx_data['total_distance'] * 0.621371, $current_group['units']);
                            echo number_format($dist, 2) . ' ' . getDistanceUnit($current_group['units']);
                            ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #666; font-weight: 600;">Total Distance</div>
                        <small style="color: #999; font-size: 0.7rem;">Round trip</small>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 1rem; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                        <div style="font-size: 1.5rem;">⛰️</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--gold); margin: 0.5rem 0;">
                            <?php
                            $gain = convertElevation($gpx_data['elevation_gain'] * 3.28084, $current_group['units']);
                            echo number_format($gain) . ' ' . getElevationUnit($current_group['units']);
                            ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #666; font-weight: 600;">Elevation Gain</div>
                        <small style="color: #999; font-size: 0.7rem;">From GPS</small>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 1rem; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                        <div style="font-size: 1.5rem;">⚡</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--teal); margin: 0.5rem 0;">
                            <?php
                            $spd = $current_group['units'] === 'metric' ? $gpx_data['hiking_speed'] : $gpx_data['hiking_speed'] * 0.621371;
                            echo number_format($spd, 1) . ' ' . ($current_group['units'] === 'metric' ? 'km/h' : 'mph');
                            ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #666; font-weight: 600;">Hiking Speed</div>
                        <small style="color: #999; font-size: 0.7rem;">Average</small>
                    </div>
                    
                    <div style="background: #f9f9f9; padding: 1rem; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0;">
                        <div style="font-size: 1.5rem;">☕</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--peak-brown); margin: 0.5rem 0;">
                            <?= format_time_duration($gpx_data['rest_break_time']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #666; font-weight: 600;">Rest Breaks</div>
                        <small style="color: #999; font-size: 0.7rem;">Stops >3 min</small>
                    </div>
                </div>


                <!-- Elevation Profile Chart -->
                <div style="background: #f8f9fa; border-radius: 8px; padding: 0.6rem 0.75rem 0.4rem; margin-bottom: 1rem; border: 1px solid #e8e8e8;">
                    <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: #aaa; font-weight: 600; margin-bottom: 0.3rem;">Elevation Profile</div>
                    <canvas id="elev-canvas" style="width: 100%; height: 120px; display: block;"></canvas>
                    <div id="elev-note" style="font-size: 0.72rem; color: #bbb; margin-top: 0.25rem; text-align: center;">Loading profile…</div>
                </div>

                <!-- Use for Planning -->
                <form method="POST" style="background: #E8F4F8; padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid var(--teal);">
                    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin: 0;">
                            <input type="checkbox" name="use_gps_data" value="1"
                                   <?= $gpx_data['use_for_hike_time'] ? 'checked' : '' ?>
                                   style="width: 18px; height: 18px; cursor: pointer;">
                            <span style="font-weight: 600; font-size: 0.95rem;">Use GPS data for planning</span>
                            <button onclick="document.getElementById('gpxInfoModal').style.display='flex'" type="button" style="background:none;border:1px solid #6aabbc;color:#4A90A4;border-radius:50%;width:18px;height:18px;font-size:0.65rem;cursor:pointer;font-weight:700;padding:0;line-height:1;flex-shrink:0;" title="How GPS planning works">ℹ</button>
                        </label>

                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                            <span style="font-size: 0.82rem; font-weight: 600; color: #2c5f7a;">Track type:</span>
                            <select name="track_type" style="width: auto; padding: 0.3rem 0.5rem; font-size: 0.85rem; border-radius: 4px; border: 1px solid #b2dcc0;">
                                <option value="round-trip" <?= ($track_type ?? 'round-trip') === 'round-trip' ? 'selected' : '' ?>>Round-Trip</option>
                                <option value="ascent"     <?= ($track_type ?? '') === 'ascent'     ? 'selected' : '' ?>>Ascent Only</option>
                                <option value="descent"    <?= ($track_type ?? '') === 'descent'    ? 'selected' : '' ?>>Descent Only</option>
                            </select>
                        </div>

                        <button type="submit" name="update_gpx_preferences" class="btn btn-small" style="margin: 0; padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                            Save
                        </button>
                    </div>
                    <div style="margin-top: 0.5rem; font-size: 0.75rem; color: #5a8a9f;">
                        <?php if ($track_type === 'ascent'): ?>
                            ↑ Ascent-only track — distance and time doubled for round-trip planning
                        <?php elseif ($track_type === 'descent'): ?>
                            ↓ Descent-only track — direction reversed; elevation gain derived from track's descent
                        <?php else: ?>
                            Round-trip track — GPS values used as-is
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Map -->
                <!-- Map layer toggles -->
                <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.6rem; align-items:center;">

                    <!-- Base map selector -->
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

                    <span style="color:#ddd; font-size:0.75rem;">|</span>
                    <span style="font-size:0.8rem; font-weight:700; color:#555; margin-right:0.1rem;">Overlays:</span>

                    <!-- Activation Zone zoom button -->
                    <button onclick="zoomToActivationZone()" id="btn-actzone" disabled
                            style="padding:0.3rem 0.8rem; border-radius:20px; border:2px solid #CC2200;
                                   background:#CC2200; color:white; font-weight:700; font-size:0.78rem;
                                   cursor:pointer; transition:all 0.2s; opacity:0.5;">🏔 Zoom to Activation Zone</button>

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
                </div>
                <div id="gpx-map" style="height: 450px; border-radius: 8px; border: 2px solid #ddd; margin-bottom: 0.5rem;"></div>
                <p style="font-size:0.72rem; color:#aaa; margin-bottom:1rem;">Coverage data: FCC Form 477 filings (2021), via ArcGIS public tile service. Carrier-reported estimates — actual signal in mountainous terrain may differ.</p>

                <!-- Methodology — updated by JS once activation zone result is known -->
                <div id="az-methodology" style="padding: 1rem; background: #f9f9f9; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1rem;">
                    <strong>Activation Zone:</strong>
                    <?php if ($gpx_data['using_api'] && $gpx_data['activation_zone_polygon']): ?>
                        <span style="color: var(--trail-green);">✓ Precise terrain-based boundary</span> from
                        <a href="https://activation.zone" target="_blank" style="color: var(--teal);">Activation.Zone</a>
                        by <strong>N6ARA</strong>.
                    <?php else: ?>
                        <span style="color: #aaa;">⏳ Fetching boundary from Activation.Zone…</span>
                    <?php endif; ?>
                </div>

                <!-- Replace GPX -->
                <details style="margin-top: 1rem;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--teal); padding: 0.5rem;">
                        Replace GPS Track
                    </summary>
                    <div style="margin-top: 1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php if (!empty($summit['sota_ref'])): ?>
                        <div style="padding: 0.75rem 1rem; background: #EEF6F0; border: 1px solid #B2DCC0; border-radius: 6px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                                <span style="font-size: 0.9rem; color: #1a5c2e; font-weight: 600;">Import from SOTA Maps</span>
                                <button class="btn btn-small" onclick="fetchSotaMaps(this)">🗺 Check for Tracks</button>
                            </div>
                            <div id="sotamaps-result" style="margin-top: 0.75rem; display: none;"></div>
                        </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.4rem;">Upload your own GPX file:</div>
                            <input type="file" name="gpx_file" accept=".gpx" required style="margin-bottom: 0.5rem; display: block;">
                            <button type="submit" class="btn btn-small">Upload & Analyze</button>
                        </form>
                    </div>
                </details>

                <script>
                const map = L.map('gpx-map').setView([<?= $gpx_data['summit_lat'] ?>, <?= $gpx_data['summit_lon'] ?>], 14);

                // Base layers
                const baseLayers = {
                    street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
                        maxZoom: 19
                    }),
                    topo: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
                        attribution: '© <a href="https://opentopomap.org">OpenTopoMap</a> | © <a href="https://openstreetmap.org">OpenStreetMap</a>',
                        maxZoom: 17
                    }),
                    satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                        attribution: '© Esri / USGS / USDA',
                        maxZoom: 19
                    })
                };

                let activeBase = 'street';
                let gpxPolyline = null;
                baseLayers.street.addTo(map);

                function switchBase(name) {
                    if (name === activeBase) return;
                    map.removeLayer(baseLayers[activeBase]);
                    baseLayers[name].addTo(map);
                    // Bring vector overlays to front after new tile layer loads
                    if (gpxPolyline) gpxPolyline.bringToFront();
                    if (activationZoneLayer) activationZoneLayer.bringToFront();
                    activeBase = name;
                    ['street','topo','satellite'].forEach(n => {
                        const b = document.getElementById('btn-base-' + n);
                        b.style.background = n === name ? '#1E3A5F' : 'white';
                        b.style.color      = n === name ? 'white'   : '#1E3A5F';
                    });
                }

                // FCC Form 477 carrier LTE coverage tiles (LOD 3-13, stretched via maxNativeZoom)
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

                // Activation Zone — always-visible red polygon
                let activationZoneLayer = null;

                function initActivationZone(poly) {
                    let coords;
                    if (Array.isArray(poly[0]) && Array.isArray(poly[0][0]) && Array.isArray(poly[0][0][0])) {
                        coords = poly[0][0].map(c => [c[1], c[0]]);
                    } else if (Array.isArray(poly[0]) && Array.isArray(poly[0][0])) {
                        coords = poly[0].map(c => [c[1], c[0]]);
                    } else {
                        coords = poly.map(c => [c[1], c[0]]);
                    }
                    activationZoneLayer = L.polygon(coords, {
                        color: '#CC2200',
                        fillColor: '#CC2200',
                        fillOpacity: 0.18,
                        weight: 2,
                        dashArray: '5, 4'
                    }).bindPopup('<strong>SOTA Activation Zone</strong><br>Must operate within this boundary.<br><small>Source: Activation.Zone by N6ARA</small>');
                    activationZoneLayer.addTo(map);
                    // Enable the zoom button
                    const zBtn = document.getElementById('btn-actzone');
                    if (zBtn) { zBtn.disabled = false; zBtn.style.opacity = '1'; }
                    // Update methodology note
                    const az = document.getElementById('az-methodology');
                    if (az) az.innerHTML = '<strong>Activation Zone:</strong> <span style="color:#4A7C59;">✓ Precise terrain-based boundary</span> from <a href="https://activation.zone" target="_blank" style="color:#4A90A4;">Activation.Zone</a> by <strong>N6ARA</strong>.';
                }

                function setActivationZoneFallback() {
                    const az = document.getElementById('az-methodology');
                    if (az) az.innerHTML = '<strong>Activation Zone:</strong> <span style="color:#E6A020;">⚠ Activation.Zone API unavailable</span> — showing estimated 50 m radius from highest GPS point instead.';
                }

                function zoomToActivationZone() {
                    if (!activationZoneLayer) return;
                    map.fitBounds(activationZoneLayer.getBounds(), {padding: [40, 40]});
                }
                
                fetch('load_gpx.php?id=<?= $gpx_data['id'] ?>')
                    .then(r => r.text())
                    .then(gpxText => {
                        const parser = new DOMParser();
                        const gpx = parser.parseFromString(gpxText, 'text/xml');
                        // Support both track points (trkpt) and route points (rtept)
                        const pts = gpx.querySelectorAll('trkpt, rtept');
                        const coords = [];
                        const elevPts = []; // [lat, lon, ele_m]

                        pts.forEach(pt => {
                            const lat = parseFloat(pt.getAttribute('lat'));
                            const lon = parseFloat(pt.getAttribute('lon'));
                            coords.push([lat, lon]);
                            const ele = pt.querySelector('ele');
                            if (ele) elevPts.push([lat, lon, parseFloat(ele.textContent)]);
                        });

                        gpxPolyline = L.polyline(coords, {color: '#4A90A4', weight: 3, opacity: 0.8}).addTo(map);
                        const elevState = drawElevationProfile(elevPts);
                        if (elevState) setupElevMapHover(elevState, map);
                        map.fitBounds(L.polyline(coords).getBounds(), {padding: [50, 50]});

                        // Try to load activation zone from cached DB data, else fetch from API
                        <?php if ($gpx_data['using_api'] && $gpx_data['activation_zone_polygon']): ?>
                        initActivationZone(<?= $gpx_data['activation_zone_polygon'] ?>);
                        <?php elseif (!empty($summit['sota_ref'])): ?>
                        fetch('activation_zone.php?sota_ref=<?= urlencode($summit['sota_ref']) ?>')
                            .then(r => r.json())
                            .then(data => {
                                if (data.polygon) initActivationZone(data.polygon);
                                else setActivationZoneFallback();
                            })
                            .catch(() => setActivationZoneFallback());
                        <?php endif; ?>
                    });

                function drawElevationProfile(elevPts) {
                    const canvas = document.getElementById('elev-canvas');
                    const note = document.getElementById('elev-note');
                    if (!canvas) return null;
                    if (elevPts.length < 2) {
                        if (note) note.textContent = 'No elevation data in this track.';
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

                    // Downsample for performance
                    let pts = elevPts;
                    if (pts.length > 500) {
                        const step = Math.ceil(pts.length / 500);
                        pts = pts.filter((_, i) => i % step === 0 || i === pts.length - 1);
                    }

                    // Cumulative distance
                    function hDist(lat1, lon1, lat2, lon2) {
                        const R = 6371, r = Math.PI / 180;
                        const dLat = (lat2 - lat1) * r, dLon = (lon2 - lon1) * r;
                        const a = Math.sin(dLat/2)**2 + Math.cos(lat1*r)*Math.cos(lat2*r)*Math.sin(dLon/2)**2;
                        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                    }

                    const useMetric = <?= $current_group['units'] === 'metric' ? 'true' : 'false' ?>;
                    const eleConv = useMetric ? 1 : 3.28084;
                    const distConv = useMetric ? 1 : 0.621371;
                    const eleUnit = useMetric ? 'm' : 'ft';
                    const distUnit = useMetric ? 'km' : 'mi';

                    const data = [];
                    let cumD = 0;
                    for (let i = 0; i < pts.length; i++) {
                        if (i > 0) cumD += hDist(pts[i-1][0], pts[i-1][1], pts[i][0], pts[i][1]);
                        // Store lat/lon alongside elevation+distance for crosshair sync
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

                    // Gradient fill
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

                    // Profile line
                    ctx.beginPath();
                    ctx.moveTo(xS(data[0].d), yS(data[0].e));
                    for (let i = 1; i < data.length; i++) ctx.lineTo(xS(data[i].d), yS(data[i].e));
                    ctx.strokeStyle = '#4A90A4';
                    ctx.lineWidth = 1.5;
                    ctx.stroke();

                    // Gridlines + Y labels
                    ctx.font = '10px sans-serif';
                    ctx.textAlign = 'right';
                    for (let i = 0; i <= 3; i++) {
                        const e = minE + (maxE - minE) * i / 3;
                        const y = yS(e);
                        ctx.strokeStyle = '#efefef';
                        ctx.lineWidth = 1;
                        ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + plotW, y); ctx.stroke();
                        ctx.fillStyle = '#aaa';
                        ctx.fillText(Math.round(e), pad.left - 3, y + 3);
                    }

                    // X axis labels
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#aaa';
                    const nX = Math.min(5, Math.floor(maxD) || 1);
                    for (let i = 0; i <= nX; i++) {
                        const d = maxD * i / nX;
                        ctx.fillText(d.toFixed(1), xS(d), H - 5);
                    }

                    // Axis unit labels
                    ctx.fillStyle = '#ccc';
                    ctx.textAlign = 'left';
                    ctx.fillText(eleUnit, 2, pad.top + 8);
                    ctx.textAlign = 'right';
                    ctx.fillText(distUnit, W - 2, H - 5);

                    if (note) note.style.display = 'none';

                    // Snapshot the finished render so hover can restore it without a full redraw
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

                        // Dashed vertical line
                        ctx.save();
                        ctx.setLineDash([3, 3]);
                        ctx.strokeStyle = 'rgba(230,160,32,0.9)';
                        ctx.lineWidth = 1.5;
                        ctx.beginPath();
                        ctx.moveTo(cx, pad.top);
                        ctx.lineTo(cx, pad.top + plotH);
                        ctx.stroke();
                        ctx.restore();

                        // Dot on the profile line
                        ctx.beginPath();
                        ctx.arc(cx, cy, 4, 0, Math.PI * 2);
                        ctx.fillStyle = '#E6A020';
                        ctx.fill();
                        ctx.strokeStyle = 'white';
                        ctx.lineWidth = 1.5;
                        ctx.stroke();

                        // Readout in the note div below the chart
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

                    // ── Canvas hover → crosshair + map marker ───────────────
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
                                radius: 6, color: '#E6A020', fillColor: '#E6A020',
                                fillOpacity: 0.9, weight: 2
                            }).addTo(gpxMap);
                        } else {
                            hoverMarker.setLatLng([p.lat, p.lon]);
                        }
                    });
                    canvas.addEventListener('mouseleave', () => {
                        clearCrosshair();
                        if (hoverMarker) { gpxMap.removeLayer(hoverMarker); hoverMarker = null; }
                    });

                    // ── Map hover → crosshair on elevation chart ─────────────
                    gpxMap.on('mousemove', e => {
                        const idx = nearestByLatLon(e.latlng.lat, e.latlng.lng);
                        // Convert nearest track point to screen pixels to check proximity
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

            <?php else: ?>
                <!-- No GPX - Import or Upload -->
                <?php if (!empty($summit['sota_ref'])): ?>
                <div style="margin-bottom: 1.5rem; padding: 1rem 1.25rem; background: #EEF6F0; border: 1px solid #B2DCC0; border-radius: 8px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                        <div>
                            <strong style="color: #1a5c2e;">Import from SOTA Maps</strong>
                            <div style="font-size: 0.85rem; color: #555; margin-top: 0.2rem;">Community-submitted route tracks from sotamaps.org</div>
                        </div>
                        <button class="btn" onclick="fetchSotaMaps(this)" id="sotamaps-fetch-btn">
                            🗺 Check for Tracks
                        </button>
                    </div>
                    <div id="sotamaps-result" style="margin-top: 1rem; display: none;"></div>
                </div>
                <?php endif; ?>

                <p style="margin-bottom: 1rem; color: #666;">
                    Or upload your own GPS track for precise time calculations and activation zone detection.
                </p>

                <form method="POST" enctype="multipart/form-data">
                    <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem;">
                        <input type="file" name="gpx_file" accept=".gpx" required
                               style="flex: 1; padding: 0.5rem; border: 2px solid #ddd; border-radius: 4px;">
                        <button type="submit" class="btn">Upload & Analyze</button>
                    </div>
                    <small style="color: #666;">Export from: Garmin, Gaia GPS, CalTopo, AllTrails</small>
                </form>

                <div style="margin-top: 1.5rem; padding: 1rem; background: #E8F4F8; border-radius: 6px;">
                    <strong>What We'll Calculate:</strong>
                    <ul style="margin: 0.5rem 0 0 1.5rem; line-height: 1.8;">
                        <li>Actual hiking time (excluding activation zone)</li>
                        <li>Time spent in activation zone</li>
                        <li>True distance and elevation gain</li>
                        <li>Hiking speed and rest breaks</li>
                        <li>Precise activation zone boundaries</li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <!-- Planned Activations -->
        <div class="card">
            <h2>📅 Planned Activations <button onclick="document.getElementById('plannedModal').style.display='flex'" style="background:none;border:1px solid #b0c4d0;color:#4A90A4;border-radius:50%;width:22px;height:22px;font-size:0.75rem;cursor:pointer;font-weight:700;padding:0;line-height:1;vertical-align:middle;margin-left:0.4rem;" title="About planned activations">ℹ</button></h2>

            <?php if (!empty($planned_activations_list)): ?>
                <?php foreach ($planned_activations_list as $pa): ?>
                    <?php
                        $peak_slug = preg_replace('/[^a-zA-Z0-9]+/', '-', trim(($summit['sota_ref'] ?? '') . '-' . ($summit['name'] ?? '')));
                        $peak_slug = strtolower(trim($peak_slug, '-'));
                        $invite_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
                                      '://' . $_SERVER['HTTP_HOST'] .
                                      rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/activation_invite.php?id=' . $pa['id'] .
                                      '&peak=' . rawurlencode($peak_slug);
                    ?>
                    <div style="background: var(--snow); border-left: 4px solid var(--gold); border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                            <div>
                                <div style="font-size: 1.15rem; font-weight: 700; color: var(--navy);">
                                    <?= date('l, F j, Y', strtotime($pa['planned_date'])) ?>
                                </div>
                                <div style="color: #555; margin-top: 0.4rem; font-size: 0.95rem;">
                                    🥾 Hike start: <strong><?= date('g:i A', strtotime($pa['hike_start_time'])) ?></strong>
                                    &nbsp;•&nbsp; 📻 <strong><?= $pa['activation_duration_min'] ?> min</strong> radio time
                                    &nbsp;•&nbsp; 👥 <strong><?= htmlspecialchars($pa['callsigns']) ?></strong>
                                </div>
                                <?php if ($pa['invitation_message']): ?>
                                    <div style="color: #666; font-size: 0.85rem; margin-top: 0.5rem; font-style: italic;">
                                        "<?= htmlspecialchars(substr($pa['invitation_message'], 0, 120)) ?><?= strlen($pa['invitation_message']) > 120 ? '…' : '' ?>"
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                                <a href="<?= htmlspecialchars($invite_url) ?>" target="_blank" class="btn btn-secondary btn-small">🔗 View Invite</a>
                                <button onclick="copyToClipboard('<?= htmlspecialchars($invite_url, ENT_QUOTES) ?>', this)" class="btn btn-small">📋 Copy Link</button>
                                <button type="button" onclick="toggleEdit(<?= $pa['id'] ?>)" class="btn btn-secondary btn-small">✏️ Edit</button>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Remove this planned activation?');">
                                    <input type="hidden" name="planned_activation_id" value="<?= $pa['id'] ?>">
                                    <button type="submit" name="delete_planned_activation" class="btn btn-danger btn-small">×</button>
                                </form>
                            </div>
                        </div>

                        <!-- Inline edit form (hidden by default) -->
                        <div id="edit-form-<?= $pa['id'] ?>" style="display:none; margin-top:1rem; padding-top:1rem; border-top:2px solid #ddd;">
                            <form method="POST">
                                <input type="hidden" name="planned_activation_id" value="<?= $pa['id'] ?>">
                                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem;">
                                    <div>
                                        <label style="font-size:0.8rem;">Date</label>
                                        <input type="date" name="planned_date" required style="width:100%;"
                                               value="<?= htmlspecialchars($pa['planned_date']) ?>">
                                    </div>
                                    <div>
                                        <label style="font-size:0.8rem;">Hike Start Time <span style="font-weight:400;color:#888;">(local time)</span></label>
                                        <input type="time" name="hike_start_time" required style="width:100%;"
                                               value="<?= htmlspecialchars($pa['hike_start_time']) ?>">
                                    </div>
                                    <div>
                                        <label style="font-size:0.8rem;">Radio Time (minutes)</label>
                                        <input type="number" name="activation_duration_min" required style="width:100%;"
                                               value="<?= (int)$pa['activation_duration_min'] ?>" min="15" max="480">
                                    </div>
                                </div>
                                <div style="margin-bottom:1rem;">
                                    <label style="font-size:0.8rem;">Callsigns</label>
                                    <input type="text" name="planned_callsigns" required style="width:100%;"
                                           value="<?= htmlspecialchars($pa['callsigns']) ?>">
                                </div>
                                <div style="margin-bottom:1rem;">
                                    <label style="font-size:0.8rem;">Message for Guests</label>
                                    <textarea name="invitation_message" style="width:100%;"><?= htmlspecialchars($pa['invitation_message'] ?? '') ?></textarea>
                                </div>
                                <div style="margin-bottom:1rem;">
                                    <label style="font-size:0.8rem;">Parking & Travel Notes</label>
                                    <textarea name="travel_notes" style="width:100%;"><?= htmlspecialchars($pa['travel_notes'] ?? '') ?></textarea>
                                </div>
                                <div style="margin-bottom:1rem;">
                                    <label style="font-size:0.8rem;">Real-Time Location Sharing Link (optional)</label>
                                    <input type="url" name="location_link" style="width:100%;"
                                           value="<?= htmlspecialchars($pa['location_link'] ?? '') ?>"
                                           placeholder="e.g. https://share.garmin.com/… or any live tracking URL">
                                </div>
                                <div style="display:flex; gap:0.5rem;">
                                    <button type="submit" name="edit_planned_activation" class="btn btn-small">💾 Save Changes</button>
                                    <button type="button" onclick="toggleEdit(<?= $pa['id'] ?>)" class="btn btn-secondary btn-small">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #666; font-style: italic; margin-bottom: 1.5rem;">No upcoming activations planned yet.</p>
            <?php endif; ?>

            <!-- Add Planned Activation Form -->
            <form method="POST" style="background: var(--snow); padding: 1.5rem; border-radius: 8px; margin-top: 0.5rem;">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;">Plan an Upcoming Activation</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="font-size: 0.85rem;">Date</label>
                        <input type="date" name="planned_date" required style="width: 100%;"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                    <div>
                        <label style="font-size: 0.85rem;">Hike Start Time (Local time, at trailhead)</label>
                        <input type="time" name="hike_start_time" required style="width: 100%;" value="08:00">
                    </div>
                    <div>
                        <label style="font-size: 0.85rem;">Planned Radio Time (minutes)</label>
                        <input type="number" name="activation_duration_min" required style="width: 100%;"
                               value="90" min="15" max="480">
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem;">Activating Callsigns</label>
                    <input type="text" name="planned_callsigns" required placeholder="KI6CR, W6ABC" style="width: 100%;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem;">Message for Guests (optional)</label>
                    <textarea name="invitation_message" style="width: 100%;"
                              placeholder="Add any details guests should know — meeting spot, gear suggestions, etc."></textarea>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem;">Parking & Travel Notes (optional)</label>
                    <textarea name="travel_notes" style="width: 100%;"
                              placeholder="Where to park, trailhead directions, carpooling info, etc."></textarea>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-size: 0.85rem;">Real-Time Location Sharing Link (optional)</label>
                    <input type="url" name="location_link" style="width: 100%;"
                           placeholder="e.g. https://share.garmin.com/… or any live tracking URL">
                    <div style="font-size: 0.75rem; color: #888; margin-top: 0.25rem;">If provided, guests on the invitation page will see a link to follow the group's live location.</div>
                </div>
                <button type="submit" name="add_planned_activation" class="btn btn-small">📅 Schedule Activation</button>
            </form>

            <script>
            function copyToClipboard(text, btn) {
                navigator.clipboard.writeText(text).then(() => {
                    const orig = btn.textContent;
                    btn.textContent = '✓ Copied!';
                    setTimeout(() => btn.textContent = orig, 2000);
                });
            }
            function toggleEdit(id) {
                const el = document.getElementById('edit-form-' + id);
                el.style.display = el.style.display === 'none' ? 'block' : 'none';
            }
            </script>
        </div>

        <!-- Activation History -->
        <div class="card">
            <h2>Activation History <button onclick="document.getElementById('historyModal').style.display='flex'" style="background:none;border:1px solid #b0c4d0;color:#4A90A4;border-radius:50%;width:22px;height:22px;font-size:0.75rem;cursor:pointer;font-weight:700;padding:0;line-height:1;vertical-align:middle;margin-left:0.4rem;" title="About activation history">ℹ</button></h2>
            
            <?php
            // Get activations for this summit and planning group
            $current_group = getCurrentPlanningGroup($db);
            $stmt = $db->prepare("
                SELECT * FROM activations 
                WHERE summit_id = ? AND planning_group_id = ?
                ORDER BY activation_date DESC
            ");
            $stmt->execute([$summit_id, $current_group['id']]);
            $activations = $stmt->fetchAll();
            ?>
            
            <?php if (!empty($activations)): ?>
                <table style="width: 100%; margin-bottom: 1.5rem;">
                    <thead style="background: var(--snow);">
                        <tr>
                            <th style="padding: 0.75rem; text-align: left;">Date</th>
                            <th style="padding: 0.75rem; text-align: left;">Callsigns</th>
                            <th style="padding: 0.75rem; text-align: left;">Notes</th>
                            <th style="padding: 0.75rem; width: 80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activations as $activation): ?>
                            <tr>
                                <td style="padding: 0.75rem;"><?= date('M j, Y', strtotime($activation['activation_date'])) ?></td>
                                <td style="padding: 0.75rem; font-family: 'Courier New', monospace;"><?= htmlspecialchars($activation['callsigns']) ?></td>
                                <td style="padding: 0.75rem;"><?= htmlspecialchars($activation['notes']) ?></td>
                                <td style="padding: 0.75rem;">
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="activation_id" value="<?= $activation['id'] ?>">
                                        <button type="submit" name="delete_activation" class="btn btn-danger btn-small">×</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #666; font-style: italic; margin-bottom: 1.5rem;">No activations recorded yet.</p>
            <?php endif; ?>
            
            <!-- Add Activation Form -->
            <form method="POST" style="background: var(--snow); padding: 1.5rem; border-radius: 8px;">
                <h3 style="margin-bottom: 1rem; font-size: 1.1rem;">Add Activation</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 2fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label for="activation_date" style="font-size: 0.85rem;">Date</label>
                        <input type="date" id="activation_date" name="activation_date" required 
                               value="<?= date('Y-m-d') ?>" style="width: 100%;">
                    </div>
                    <div>
                        <label for="activation_callsigns" style="font-size: 0.85rem;">Callsigns</label>
                        <input type="text" id="activation_callsigns" name="activation_callsigns" 
                               placeholder="KI6CR, W6ABC" required style="width: 100%;">
                    </div>
                    <div>
                        <label for="activation_notes" style="font-size: 0.85rem;">Notes (optional)</label>
                        <input type="text" id="activation_notes" name="activation_notes" 
                               placeholder="Weather, conditions, etc." style="width: 100%;">
                    </div>
                </div>
                <button type="submit" name="add_activation" class="btn btn-small">Add Activation</button>
            </form>
        </div>

                <!-- Notes -->
        <div class="card">
            <h2>Notes & Comments <button onclick="document.getElementById('notesModal').style.display='flex'" style="background:none;border:1px solid #b0c4d0;color:#4A90A4;border-radius:50%;width:22px;height:22px;font-size:0.75rem;cursor:pointer;font-weight:700;padding:0;line-height:1;vertical-align:middle;margin-left:0.4rem;" title="About notes">ℹ</button></h2>
            <form method="POST" style="margin-bottom: 1.5rem;">
                <div class="form-group">
                    <textarea name="note" placeholder="Add notes about access, parking, trail conditions..." required></textarea>
                </div>
                <button type="submit" name="add_note" class="btn btn-small">Add Note</button>
            </form>

            <?php if (!empty($notes)): ?>
                <?php foreach ($notes as $note): ?>
                    <div class="note-item">
                        <div class="note-header">
                            <span class="note-meta"><?= date('M j, Y g:i A', strtotime($note['created_at'])) ?></span>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                <button type="submit" name="delete_note" class="btn btn-small btn-danger">Delete</button>
                            </form>
                        </div>
                        <div><?= nl2br(htmlspecialchars($note['note'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Remove Nomination -->
        <div class="card">
            <form method="POST" onsubmit="return confirm('Remove this summit nomination from your planning group?');">
                <button type="submit" name="delete_summit" class="btn btn-danger" style="width: 100%;">Remove Nomination</button>
            </form>
        </div>
    </div>

    <!-- GPS Info Modal -->
    <!-- ── Stats Modal ─────────────────────────────────────────────────────── -->
    <div id="statsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:white; border-radius:12px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; color:#1E3A5F; margin:0;">📋 Planning Summary — How Tiles Are Calculated</h2>
                <button onclick="document.getElementById('statsModal').style.display='none'"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1;">×</button>
            </div>
            <div style="padding:1.25rem 1.5rem 1.5rem; font-size:0.9rem; line-height:1.65; color:#333;">

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Individual Tiles</h3>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.35rem;"><strong>SOTA Points</strong> — fixed value from the SOTA database for this summit's association and region.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Elevation</strong> — summit altitude from the SOTA database, shown in your group's preferred units.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Distance</strong> — round-trip hiking distance. Comes from a GPS track or route file if one is loaded and "Use GPS data for planning" is checked; otherwise from the value entered in Edit Summit Details.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Gain</strong> — total elevation gained on the approach. Same source priority as Distance. For descent-only GPS tracks, the gain is derived from the track's descent (since you'd be going the other way).</li>
                    <li style="margin-bottom:0.35rem;"><strong>Hike Time (RT)</strong> — round-trip hiking time. If a GPS track with real timestamps is loaded and enabled for planning, this comes from actual recorded movement time (excluding time in the activation zone). Otherwise it is estimated using Naismith's rule: roughly 1 hour per 5 km plus 1 hour per 600 m of gain. Shown in green if from GPS, amber if estimated.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Drive Time (RT)</strong> — round-trip drive time from your group's selected home base to the trailhead, calculated via Google Maps. Use the Recalculate Drive Times button on the dashboard to refresh it.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Last Activated</strong> — the most recent activation date recorded in the Activation History section below.</li>
                </ul>

            </div>
        </div>
    </div>

    <!-- ── Edit Summit Details Modal ────────────────────────────────────────── -->
    <div id="editModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:white; border-radius:12px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; color:#1E3A5F; margin:0;">✏️ Edit Summit Details — Field Guide</h2>
                <button onclick="document.getElementById('editModal').style.display='none'"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1;">×</button>
            </div>
            <div style="padding:1.25rem 1.5rem 1.5rem; font-size:0.9rem; line-height:1.65; color:#333;">

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Status &amp; Difficulty</h3>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.35rem;"><strong>Status</strong> — tracks where this summit is in your planning workflow: Nominated → Researched → Ready → Activated. Controls visibility and sorting on the dashboard.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Difficulty</strong> — your group's subjective rating (Drive-Up, Easy, Moderate, Hard). Doesn't affect any calculations.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Cell Service</strong> — what to expect at the summit for spotting via phone. Options: Unknown, Full Coverage, Intermittent, Summit Only, No Service.</li>
                </ul>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Hike Distance &amp; Gain</h3>
                <p style="margin:0 0 0.75rem;">These are the fallback values used for planning when no GPS track is loaded (or when "Use GPS data for planning" is unchecked). Hike time is always calculated from these using Naismith's rule — there is no manual time entry. Set the values here and the formula does the rest.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Set Trailhead</h3>
                <p style="margin:0 0 0.75rem;">The trailhead location determines where drive time is measured to. Paste a latitude/longitude pair (e.g. <em>34.168, -118.236</em>) or type a street address into the box and click Find — the coordinates will be looked up via Google and saved automatically. You can also type the lat/lng directly into the Trailhead Lat and Lng fields and save.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Trail Link</h3>
                <p style="margin:0 0 0.5rem;">Paste a URL to any trail description — AllTrails, Gaia GPS, CalTopo, Hiking Project, or SOTLAS. Quick-search buttons below the field will open each site pre-populated with the summit name or coordinates so you can find the right page and copy the link back.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Calculate Drive Time</h3>
                <p style="margin:0;">After setting the trailhead, use the Calculate Drive Time button (below the Save button) to query Google Maps and store the round-trip drive time. This only appears when a home base address is selected on the dashboard.</p>

            </div>
        </div>
    </div>

    <!-- ── Planned Activations Modal ────────────────────────────────────────── -->
    <div id="plannedModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:white; border-radius:12px; max-width:560px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; color:#1E3A5F; margin:0;">📅 Planned Activations — How This Works</h2>
                <button onclick="document.getElementById('plannedModal').style.display='none'"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1;">×</button>
            </div>
            <div style="padding:1.25rem 1.5rem 1.5rem; font-size:0.9rem; line-height:1.65; color:#333;">

                <p style="margin:0 0 0.75rem;">Use this section to schedule an upcoming activation and share the details with other operators. Each plan generates a shareable invite link.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Fields</h3>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.35rem;"><strong>Date</strong> — the day of the activation.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Hike Start Time</strong> — when you plan to leave the trailhead (local time). Helps chasers know when you might be on the air.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Planned Radio Time</strong> — how long you expect to operate from the summit, in minutes.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Activating Callsigns</strong> — who's going. Comma-separate multiple operators.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Message for Guests</strong> — optional text included in the invite, visible to anyone you share the link with.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Parking &amp; Travel Notes</strong> — directions, parking tips, carpooling info — also visible on the invite page.</li>
                </ul>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Invite Link</h3>
                <p style="margin:0 0 0.75rem;">After saving, use the View Invite or Copy Link buttons to share the activation details. The invite page shows the summit, timing, and your notes — no login required for guests.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">After the Activation</h3>
                <p style="margin:0;">Once done, add a record in the Activation History section below. Don't forget to also submit your log at <strong>sotadata.org.uk</strong> for official SOTA credit.</p>

            </div>
        </div>
    </div>

    <!-- ── Activation History Modal ─────────────────────────────────────────── -->
    <div id="historyModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:white; border-radius:12px; max-width:560px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; color:#1E3A5F; margin:0;">🏔 Activation History — How This Works</h2>
                <button onclick="document.getElementById('historyModal').style.display='none'"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1;">×</button>
            </div>
            <div style="padding:1.25rem 1.5rem 1.5rem; font-size:0.9rem; line-height:1.65; color:#333;">

                <p style="margin:0 0 0.75rem;">This is your group's local log of completed activations — separate from the official SOTA database. The most recent date recorded here also appears in the <strong>Last Activated</strong> tile in the Planning Summary above.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Logging an Activation</h3>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.35rem;"><strong>Date</strong> — the date the summit was activated.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Callsigns</strong> — the activating operator(s). Comma-separate multiple callsigns.</li>
                    <li style="margin-bottom:0.35rem;"><strong>Notes</strong> — optional. Conditions, gear notes, anything worth remembering.</li>
                </ul>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Official SOTA Records</h3>
                <p style="margin:0;">This log is for your group's reference only. For official SOTA credit, submit your activation log at <strong>sotadata.org.uk</strong>.</p>

            </div>
        </div>
    </div>

    <!-- ── Notes & Comments Modal ───────────────────────────────────────────── -->
    <div id="notesModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:white; border-radius:12px; max-width:560px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; color:#1E3A5F; margin:0;">💬 Notes &amp; Comments — How This Works</h2>
                <button onclick="document.getElementById('notesModal').style.display='none'"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1;">×</button>
            </div>
            <div style="padding:1.25rem 1.5rem 1.5rem; font-size:0.9rem; line-height:1.65; color:#333;">

                <p style="margin:0 0 0.75rem;">Free-form notes visible to everyone in your planning group. Use them to capture anything useful that isn't covered by the structured fields above.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">What to Note</h3>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.35rem;">Trailhead details — parking, permit requirements, gate hours, road conditions</li>
                    <li style="margin-bottom:0.35rem;">Trail conditions — seasonal closures, brush, scrambling, water crossings</li>
                    <li style="margin-bottom:0.35rem;">Operating tips — best antenna spots, spotting cell coverage, summit layout</li>
                    <li style="margin-bottom:0.35rem;">Anything from a past activation that would be useful next time</li>
                </ul>

                <p style="margin:0; font-size:0.85rem; color:#666;">Notes can be deleted with the Delete button but not edited — if something needs correcting, delete it and add a new one.</p>

            </div>
        </div>
    </div>

    <div id="gpxInfoModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:white; border-radius:12px; max-width:640px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:1.5rem 1.5rem 0; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; color:#1E3A5F; margin:0;">📊 GPS Track & Planning — How It Works</h2>
                <button onclick="document.getElementById('gpxInfoModal').style.display='none'"
                        style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#999;line-height:1;">×</button>
            </div>
            <div style="padding:1.25rem 1.5rem 1.5rem; font-size:0.9rem; line-height:1.65; color:#333;">

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Track Types: Route vs. Recorded</h3>
                <p style="margin:0 0 0.75rem;">Not all GPX files are the same. There are two fundamentally different kinds:</p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1rem;">
                    <div style="background:#EEF6F0; border-radius:8px; padding:0.85rem; border-left:3px solid #4A7C59;">
                        <div style="font-weight:700; color:#2a5c38; margin-bottom:0.3rem;">✅ Recorded Track (with timestamps)</div>
                        <div style="font-size:0.82rem; color:#444;">Captured live during an actual hike. Contains timestamps on every point, giving us real hiking speed, rest breaks, and time spent in the activation zone. This is the most accurate data.</div>
                        <div style="font-size:0.78rem; margin-top:0.5rem; color:#2a5c38; font-weight:600;">Labels shown: <span style="color:var(--trail-green);">(GPS recorded)</span></div>
                    </div>
                    <div style="background:#FFF8EE; border-radius:8px; padding:0.85rem; border-left:3px solid #E6A020;">
                        <div style="font-weight:700; color:#8a5c00; margin-bottom:0.3rem;">🗺 Route File (no timestamps)</div>
                        <div style="font-size:0.82rem; color:#444;">A planned route drawn on a map, or a community-submitted track (like from SOTA Maps). Has accurate distance and elevation — but no time data. Hike time is estimated from distance &amp; elevation using Naismith's rule.</div>
                        <div style="font-size:0.78rem; margin-top:0.5rem; color:#8a5c00; font-weight:600;">Labels shown: <span style="color:#E6A020;">(route file)</span> · <span style="color:#E6A020;">*estimated</span></div>
                    </div>
                </div>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">Track Direction</h3>
                <p style="margin:0 0 0.5rem;">Some tracks only cover part of the hike. Set the direction so round-trip totals are calculated correctly:</p>
                <ul style="margin:0 0 1rem 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.3rem;"><strong>Round-Trip</strong> — track covers both up and down (most common for recorded hikes). Values used as-is.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Ascent Only</strong> — track is one-way going up. Distance and time are doubled for planning. Elevation gain is unchanged.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Descent Only</strong> — track is one-way going down. Distance and time are doubled. Elevation <em>gain</em> for planning is derived from the track's descent (you'll be going the other direction).</li>
                </ul>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">The "Use GPS Data for Planning" Checkbox</h3>
                <p style="margin:0 0 0.75rem;">This controls whether GPS values override your manually-entered data on the planning tiles. When unchecked, all planning uses whatever is in the Edit Summit Details form.</p>
                <p style="margin:0 0 1rem;">When checked, GPS provides: distance (always), elevation gain (always), and hike time <em>only if the track has timestamps</em>. A route-only file will supply distance and elevation but let the formula estimate the time.</p>

                <h3 style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.06em; color:#4A90A4; margin:0 0 0.5rem;">What the GPX Analysis Tiles Show</h3>
                <ul style="margin:0 0 0 1.25rem; font-size:0.85rem;">
                    <li style="margin-bottom:0.3rem;"><strong>Hiking Time</strong> — time moving on trail, excluding activation zone time and rest stops &gt;3 min. Only available with timestamps.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Activation Time</strong> — time spent within the summit's activation zone boundary (sourced from activation.zone when available, otherwise 50m radius).</li>
                    <li style="margin-bottom:0.3rem;"><strong>Rest Breaks</strong> — stationary time outside the activation zone for more than 3 minutes.</li>
                    <li style="margin-bottom:0.3rem;"><strong>Hiking Speed</strong> — average moving speed, useful for estimating future hikes on similar terrain.</li>
                </ul>

            </div>
        </div>
    </div>

<script>
// ── SOTA Maps GPX Import ──────────────────────────────────────────────────────
const SOTAMAPS_SOTA_REF  = <?= json_encode($summit['sota_ref'] ?? '') ?>;
const SOTAMAPS_SUMMIT_ID = <?= intval($summit_id) ?>;
const SOTAMAPS_GROUP_ID  = <?= intval($current_group['id']) ?>;

function fetchSotaMaps(fetchBtn) {
    // Find the sibling result div within the same container
    const resultEl = fetchBtn.closest('div').parentElement.querySelector('#sotamaps-result')
                     || document.getElementById('sotamaps-result');

    if (!SOTAMAPS_SOTA_REF) {
        resultEl.style.display = 'block';
        resultEl.innerHTML = '<em>No SOTA reference set for this summit.</em>';
        return;
    }

    fetchBtn.disabled = true;
    fetchBtn.textContent = '⏳ Checking…';
    resultEl.style.display = 'none';

    const url = 'import_sotamaps_gpx.php?action=list'
              + '&sota_ref=' + encodeURIComponent(SOTAMAPS_SOTA_REF)
              + '&summit_id=' + SOTAMAPS_SUMMIT_ID;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            fetchBtn.disabled = false;
            fetchBtn.textContent = '🗺 Check for Tracks';
            resultEl.style.display = 'block';
            resultEl.innerHTML = renderTrackList(data);
        })
        .catch(() => {
            fetchBtn.disabled = false;
            fetchBtn.textContent = '🗺 Check for Tracks';
            resultEl.style.display = 'block';
            resultEl.innerHTML = '<span style="color:#c0392b;">Network error — could not reach SOTA Maps API.</span>';
        });
}

function renderTrackList(data) {
    if (data.error) {
        return '<span style="color:#c0392b;">Error: ' + escHtml(data.error) + '</span>';
    }
    if (data.message) {
        return '<em style="color:#666;">' + escHtml(data.message) + '</em>';
    }
    if (!data.tracks || data.tracks.length === 0) {
        return '<em style="color:#666;">No tracks found for this summit on SOTA Maps.</em>';
    }

    let html = '<div style="display:flex;flex-direction:column;gap:0.6rem;">';
    for (const t of data.tracks) {
        const date = t.posted_date ? t.posted_date.slice(0, 10) : '';
        const notes = t.notes ? '<div style="font-size:0.8rem;color:#555;margin-top:0.2rem;">' + escHtml(t.notes) + '</div>' : '';
        html += `
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem;
                    padding:0.6rem 0.75rem;background:white;border:1px solid #ddd;border-radius:6px;">
            <div>
                <strong style="font-size:0.9rem;">${escHtml(t.title)}</strong>
                <div style="font-size:0.8rem;color:#666;">${escHtml(t.callsign)} &nbsp;·&nbsp; ${escHtml(date)} &nbsp;·&nbsp; ${t.point_count} pts</div>
                ${notes}
            </div>
            <button class="btn btn-small" style="white-space:nowrap;flex-shrink:0;"
                    onclick="importSotaMapsTrack(${t.hdr_id}, this)">
                Import
            </button>
        </div>`;
    }
    html += '</div>';
    return html;
}

function importSotaMapsTrack(hdrId, btn) {
    if (!confirm('Import this track? It will replace any existing GPX for this summit.')) return;

    btn.disabled = true;
    btn.textContent = '⏳ Importing…';

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
                btn.textContent = '✓ Imported!';
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
</script>
<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:#aaa; font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:#aaa; text-decoration:none;">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:#aaa; text-decoration:none;">sotaplanner.com</a>
</footer>
</body>
</html>
