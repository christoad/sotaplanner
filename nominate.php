<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();

$db = getDbConnection();

$message = '';
$error = '';

// Get current planning group
$current_group = getCurrentPlanningGroup($db);

// Handle direct nomination
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nominate'])) {
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
                    // Already nominated by this group - redirect to it
                    header("Location: summit_detail.php?id=" . $existing['id'] . "&group=" . $current_group['id']);
                    exit;
                }
                
                // Check if ANY other group has researched this summit (has trail data)
                $stmt = $db->prepare("
                    SELECT * FROM summits 
                    WHERE sota_ref = ? 
                    AND planning_group_id != ? 
                    AND (trail_link IS NOT NULL OR hike_distance_mi IS NOT NULL)
                    LIMIT 1
                ");
                $stmt->execute([$sota_ref, $current_group['id']]);
                $source_summit = $stmt->fetch();
                
                // Create new nomination for THIS group
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
                    // Use existing research as starting point
                    $stmt->execute([
                        $current_group['id'],
                        $source_summit['planning_group_id'], // Track source
                        true, // Using shared data
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

                    // Copy GPX track from source group if one exists
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

                    // Show message about using shared data
                    header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&shared_data=1");
                    exit;
                } else {
                    // No existing research - start fresh
                    $stmt->execute([
                        $current_group['id'],
                        null, // No source
                        false, // Original research
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
                            
                            // Import trail data
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
                    
                    header("Location: summit_detail.php?id=" . $summit_id . "&group=" . $current_group['id'] . "&nominated=1");
                    exit;
                }
            } catch (PDOException $e) {
                $error = "Error saving summit: " . $e->getMessage();
            }
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
    <title>Nominate Summit - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
            --light-blue: #5BA4B8;
            --gold: #E6B84A;
            --tan: #D4A574;
            --snow: #F5F5F0;
            /* Aliases */
            --peak-brown: #1E3A5F;
            --trail-green: #4A90A4;
            --forest-dark: #1E3A5F;
            --summit-gold: #E6B84A;
            --earth-tan: #D4A574;
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
            padding: 2rem;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--peak-brown);
            margin-bottom: 0.5rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: white;
            background: var(--trail-green);
            border: 2px solid var(--trail-green);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.55rem 1.2rem;
            border-radius: 8px;
            transition: opacity 0.2s;
            margin-bottom: 1.25rem;
        }
        .back-link:hover {
            opacity: 0.85;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .message {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .message.success {
            background: #E6F4EA;
            color: #1E7E34;
            border-left: 4px solid #1E7E34;
        }

        .message.error {
            background: #FDECEA;
            color: #C62828;
            border-left: 4px solid #C62828;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--forest-dark);
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }

        input[type="text"] {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--earth-tan);
            border-radius: 6px;
            font-family: 'Overpass', sans-serif;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: var(--trail-green);
            box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
        }

        .btn {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--trail-green) 0%, var(--forest-dark) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 1rem;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .helper-text {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
            line-height: 1.6;
        }

        .info-box {
            background: #E8F4F8;
            border-left: 4px solid var(--trail-green);
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 2rem;
        }

        .info-box h3 {
            margin-bottom: 1rem;
            color: var(--forest-dark);
        }

        .info-box ul {
            margin-left: 1.5rem;
            line-height: 1.8;
        }

        .current-group {
            background: #FFF4E6;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <header style="margin-bottom: 2rem;">
            <a href="index.php" class="back-link">← Back to Dashboard</a>
            <h1>⛰️ Nominate a Summit</h1>
        </header>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="current-group">
            👥 Nominating for: <strong><?= htmlspecialchars($current_group['name']) ?></strong>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 1.5rem; color: var(--peak-brown);">Enter SOTA Reference</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="sota_ref">SOTA Summit Reference</label>
                    <input 
                        type="text" 
                        id="sota_ref" 
                        name="sota_ref" 
                        placeholder="e.g., W6/CT-225" 
                        required
                        autofocus
                    >
                    <p class="helper-text">
                        Enter the summit's SOTA reference code. You can find this on 
                        <a href="https://www.sotamaps.org" target="_blank" style="color: var(--trail-green);">SOTAmaps.org</a> or 
                        <a href="https://sotl.as" target="_blank" style="color: var(--trail-green);">SOTLas</a>.
                    </p>
                </div>
                <button type="submit" name="nominate" class="btn">Nominate Summit</button>
            </form>
        </div>

        <div class="info-box">
            <h3>ℹ️ How It Works</h3>
            <ul>
                <li>Summits are shared in the <strong>Community</strong> by default</li>
                <li>If someone already researched a summit, you'll see their research</li>
                <li>You can create group-specific versions on the summit detail page</li>
            </ul>
        </div>
    </div>
</body>
</html>
