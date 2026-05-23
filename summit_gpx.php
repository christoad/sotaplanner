<?php
/**
 * summit_gpx.php
 * Standalone GPS Track Analysis Page
 * Link this from summit_detail.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'field_help.php';
session_start();
requireLogin();

$db = getDbConnection();
$summit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get current planning group
$current_group = getCurrentPlanningGroup($db);

if (!$current_group) {
    header("Location: index.php");
    exit;
}

// Get summit
$stmt = $db->prepare("SELECT * FROM summits WHERE id = ? AND planning_group_id = ?");
$stmt->execute([$summit_id, $current_group['id']]);
$summit = $stmt->fetch();

if (!$summit) {
    die("Summit not found");
}

$message = '';
$error = '';

// Handle GPX upload
if (isset($_FILES['gpx_file']) && $_FILES['gpx_file']['error'] === UPLOAD_ERR_OK) {
    $allowed_ext = ['gpx'];
    $file_ext = strtolower(pathinfo($_FILES['gpx_file']['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, $allowed_ext)) {
        $error = "Only .gpx files are allowed";
    } else {
        $upload_dir = __DIR__ . '/gpx_files';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $summit['sota_ref']) . '_' . $current_group['id'] . '_' . time() . '.gpx';
        $filepath = $upload_dir . '/' . $filename;
        
        if (move_uploaded_file($_FILES['gpx_file']['tmp_name'], $filepath)) {
            $gpx_stats = analyze_gpx_track($filepath, $summit['sota_ref']);
            
            if ($gpx_stats) {
                // Delete old GPX
                $stmt = $db->prepare("SELECT file_path FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
                $stmt->execute([$summit_id, $current_group['id']]);
                $old_gpx = $stmt->fetch();
                if ($old_gpx && file_exists($old_gpx['file_path'])) {
                    unlink($old_gpx['file_path']);
                }
                $stmt = $db->prepare("DELETE FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
                $stmt->execute([$summit_id, $current_group['id']]);
                
                // Insert new GPX
                $stmt = $db->prepare("
                    INSERT INTO gpx_tracks (
                        summit_id, planning_group_id, filename, file_path,
                        total_time, hiking_time, activation_time, rest_break_time,
                        total_distance, hiking_distance, max_elevation, min_elevation,
                        elevation_gain, elevation_loss, avg_speed, hiking_speed,
                        num_points, summit_lat, summit_lon, using_api,
                        activation_zone_polygon, activation_zone_method
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                    $gpx_stats['activation_zone_polygon'],
                    $gpx_stats['activation_zone_method']
                ]);
                
                // Update summit with GPX data
                $stmt = $db->prepare("
                    UPDATE summits SET 
                        hike_distance_mi = ?,
                        hike_elevation_gain_ft = ?,
                        gpx_source = 'user_upload',
                        data_source = 'gpx'
                    WHERE id = ?
                ");
                $stmt->execute([
                    round($gpx_stats['total_distance'] * 0.621371, 2),
                    round($gpx_stats['elevation_gain'] * 3.28084),
                    $summit_id
                ]);
                
                $message = "GPX uploaded! Hiking: " . format_time_duration($gpx_stats['hiking_time']) . 
                          ", Activation: " . format_time_duration($gpx_stats['activation_time']);
                
                // Refresh to show new data
                header("Location: summit_gpx.php?id=" . $summit_id . "&uploaded=1");
                exit;
            } else {
                $error = "Could not analyze GPX file. Ensure it contains valid trackpoints.";
            }
        } else {
            $error = "Failed to upload file";
        }
    }
}

// Get existing GPX
$stmt = $db->prepare("SELECT * FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ?");
$stmt->execute([$summit_id, $current_group['id']]);
$gpx_data = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Track - <?= htmlspecialchars($summit['name']) ?> - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #9B6328;
            --gold: #E6B84A;
            --snow: #F5F5F0;
            --peak-brown: #5D4E37;
            --trail-green: #4A7C59;
            --forest-dark: #2F4538;
            --earth-tan: #D4A574;
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
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .back-link {
            color: var(--trail-green);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--peak-brown);
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: #666;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .message {
            padding: 1rem;
            background: #E6F4EA;
            color: #1E7E34;
            border-left: 4px solid #1E7E34;
            border-radius: 6px;
            margin-bottom: 1rem;
        }

        .error {
            background: #FFEBEE;
            color: #C62828;
            border-left-color: #C62828;
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
            transition: all 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: #f9f9f9;
            padding: 1.25rem;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--teal);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .stat-secondary {
            font-size: 0.75rem;
            color: #999;
        }

        #gpx-map {
            height: 500px;
            border-radius: 8px;
            border: 2px solid #ddd;
            margin-bottom: 1.5rem;
        }

        .methodology {
            padding: 1rem;
            background: #f9f9f9;
            border-radius: 6px;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .help-link {
            display: inline-block;
            width: 20px;
            height: 20px;
            line-height: 20px;
            text-align: center;
            background: var(--teal);
            color: white;
            border-radius: 50%;
            font-size: 0.75rem;
            text-decoration: none;
            margin-left: 0.5rem;
            transition: all 0.3s;
        }

        .help-link:hover {
            background: var(--navy);
            transform: scale(1.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="summit_detail.php?id=<?= $summit_id ?>" class="back-link">← Back to Summit Details</a>
        
        <h1><?= htmlspecialchars($summit['name']) ?></h1>
        <div class="subtitle"><?= htmlspecialchars($summit['sota_ref']) ?></div>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($gpx_data): ?>
            <!-- GPX Data Display -->
            <div class="card">
                <h2>
                    📊 GPS Track Analysis 
                    <a href="#" onclick="showHelp('gpx_track'); return false;" class="help-link">ℹ️</a>
                </h2>
                
                <div style="background: #E8F5E9; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border-left: 4px solid var(--trail-green);">
                    <strong>✓ GPS Track Loaded:</strong> <?= htmlspecialchars($gpx_data['filename']) ?>
                    <small style="display: block; color: #666; margin-top: 0.5rem;">
                        Uploaded: <?= date('M j, Y g:i A', strtotime($gpx_data['uploaded_date'])) ?>
                    </small>
                </div>

                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-icon">🚶</div>
                        <div class="stat-value"><?= format_time_duration($gpx_data['hiking_time']) ?></div>
                        <div class="stat-label">Hiking Time</div>
                        <div class="stat-secondary">Excludes activation zone</div>
                    </div>
                    
                    <div class="stat-box">
                        <div class="stat-icon">📻</div>
                        <div class="stat-value"><?= format_time_duration($gpx_data['activation_time']) ?></div>
                        <div class="stat-label">Activation Time</div>
                        <div class="stat-secondary">Time in activation zone</div>
                    </div>
                    
                    <div class="stat-box">
                        <div class="stat-icon">📏</div>
                        <div class="stat-value">
                            <?php
                            $distance = convertDistance($gpx_data['total_distance'] * 0.621371, $current_group['units']);
                            echo $distance . ' ' . getDistanceUnit($current_group['units']);
                            ?>
                        </div>
                        <div class="stat-label">Total Distance</div>
                        <div class="stat-secondary">Round trip</div>
                    </div>
                    
                    <div class="stat-box">
                        <div class="stat-icon">⛰️</div>
                        <div class="stat-value">
                            <?php
                            $gain = convertElevation($gpx_data['elevation_gain'] * 3.28084, $current_group['units']);
                            echo number_format($gain) . ' ' . getElevationUnit($current_group['units']);
                            ?>
                        </div>
                        <div class="stat-label">Elevation Gain</div>
                        <div class="stat-secondary">From GPS track</div>
                    </div>
                    
                    <div class="stat-box">
                        <div class="stat-icon">⚡</div>
                        <div class="stat-value">
                            <?php
                            $speed = $current_group['units'] === 'metric' ? $gpx_data['hiking_speed'] : $gpx_data['hiking_speed'] * 0.621371;
                            echo number_format($speed, 1);
                            ?> <?= $current_group['units'] === 'metric' ? 'km/h' : 'mph' ?>
                        </div>
                        <div class="stat-label">Hiking Speed</div>
                        <div class="stat-secondary">Average while moving</div>
                    </div>
                    
                    <div class="stat-box">
                        <div class="stat-icon">☕</div>
                        <div class="stat-value"><?= format_time_duration($gpx_data['rest_break_time']) ?></div>
                        <div class="stat-label">Rest Breaks</div>
                        <div class="stat-secondary">Stops >3 minutes</div>
                    </div>
                </div>

                <!-- Map -->
                <div id="gpx-map"></div>

                <!-- Methodology -->
                <div class="methodology">
                    <strong>Activation Zone Detection:</strong>
                    <?php if ($gpx_data['using_api']): ?>
                        <span style="color: var(--trail-green);">✓ Using precise terrain-based boundary</span> from 
                        <a href="https://activation.zone" target="_blank" style="color: var(--teal);">activation.zone</a> (by N6ARA).
                        Based on Digital Elevation Model data and SOTA's 25m vertical drop rule.
                    <?php else: ?>
                        Using 50m radius approximation from highest GPS point.
                        <span style="color: #666;">(Activation.zone API unavailable or summit not in database)</span>
                    <?php endif; ?>
                </div>

                <!-- Upload New -->
                <details style="margin-top: 1.5rem;">
                    <summary style="cursor: pointer; font-weight: 600; color: var(--teal); padding: 0.5rem;">
                        Upload New GPS Track (replaces current)
                    </summary>
                    <form method="POST" enctype="multipart/form-data" style="margin-top: 1rem;">
                        <input type="file" name="gpx_file" accept=".gpx" required style="margin-bottom: 0.5rem;">
                        <button type="submit" class="btn">Upload & Analyze</button>
                    </form>
                </details>
            </div>

            <script>
            // Initialize map
            const map = L.map('gpx-map').setView([<?= $gpx_data['summit_lat'] ?>, <?= $gpx_data['summit_lon'] ?>], 14);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap',
                maxZoom: 18
            }).addTo(map);

            // Load GPX track
            fetch('load_gpx.php?id=<?= $gpx_data['id'] ?>')
                .then(response => response.text())
                .then(gpxText => {
                    const parser = new DOMParser();
                    const gpx = parser.parseFromString(gpxText, 'text/xml');
                    const points = gpx.querySelectorAll('trkpt');
                    const coords = [];
                    
                    points.forEach(pt => {
                        coords.push([
                            parseFloat(pt.getAttribute('lat')),
                            parseFloat(pt.getAttribute('lon'))
                        ]);
                    });
                    
                    // Draw track
                    L.polyline(coords, {
                        color: '#9B6328',
                        weight: 3,
                        opacity: 0.8
                    }).addTo(map);
                    
                    // Summit marker
                    const summitIcon = L.divIcon({
                        html: '<div style="background: #E6B84A; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">⛰️</div>',
                        iconSize: [30, 30],
                        className: ''
                    });
                    
                    L.marker([<?= $gpx_data['summit_lat'] ?>, <?= $gpx_data['summit_lon'] ?>], {
                        icon: summitIcon
                    }).bindPopup('<strong><?= htmlspecialchars($summit['name']) ?></strong><br>Activation Zone').addTo(map);
                    
                    <?php if ($gpx_data['using_api'] && $gpx_data['activation_zone_polygon']): ?>
                    // Draw activation zone
                    const polygonData = <?= $gpx_data['activation_zone_polygon'] ?>;
                    let polygonCoords;
                    
                    if (Array.isArray(polygonData[0]) && Array.isArray(polygonData[0][0])) {
                        polygonCoords = polygonData[0][0].map(coord => [coord[1], coord[0]]);
                    } else if (Array.isArray(polygonData[0])) {
                        polygonCoords = polygonData[0].map(coord => [coord[1], coord[0]]);
                    } else {
                        polygonCoords = polygonData.map(coord => [coord[1], coord[0]]);
                    }
                    
                    L.polygon(polygonCoords, {
                        color: '#E6B84A',
                        fillColor: '#E6B84A',
                        fillOpacity: 0.2,
                        weight: 2
                    }).bindPopup('Activation Zone<br>(25m drop boundary)').addTo(map);
                    <?php endif; ?>
                    
                    map.fitBounds(L.polyline(coords).getBounds(), { padding: [50, 50] });
                })
                .catch(err => {
                    console.error('Error loading GPX:', err);
                    document.getElementById('gpx-map').innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #999;">Unable to load GPS track</div>';
                });
            </script>

        <?php else: ?>
            <!-- No GPX - Upload Form -->
            <div class="card">
                <h2>
                    📊 GPS Track Analysis 
                    <a href="#" onclick="showHelp('gpx_track'); return false;" class="help-link">ℹ️</a>
                </h2>
                
                <p style="margin-bottom: 1.5rem; color: #666;">
                    Upload your GPS track for precise time calculations and activation zone detection.
                </p>
                
                <form method="POST" enctype="multipart/form-data">
                    <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem;">
                        <input type="file" name="gpx_file" accept=".gpx" required
                               style="flex: 1; padding: 0.5rem; border: 2px solid #ddd; border-radius: 4px;">
                        <button type="submit" class="btn">Upload & Analyze</button>
                    </div>
                    <small style="color: #666;">
                        Export from: Garmin, Gaia GPS, CalTopo, AllTrails, etc.
                    </small>
                </form>
                
                <div style="margin-top: 1.5rem; padding: 1rem; background: #E8F4F8; border-radius: 6px;">
                    <strong>What We'll Calculate:</strong>
                    <ul style="margin: 0.5rem 0 0 1.5rem; line-height: 1.8;">
                        <li>Actual hiking time (excluding activation zone)</li>
                        <li>Time spent in activation zone (operating)</li>
                        <li>True distance and elevation gain</li>
                        <li>Your hiking speed and rest breaks</li>
                        <li>Precise activation zone boundaries</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Help Modal -->
    <div id="helpModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <div style="padding: 1.5rem; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center;">
                <h2 id="helpModalTitle" style="margin: 0; color: var(--peak-brown); font-size: 1.5rem;"></h2>
                <button onclick="closeHelp()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #999;">×</button>
            </div>
            <div id="helpModalContent" style="padding: 1.5rem; line-height: 1.6;"></div>
        </div>
    </div>

    <script>
    const helpContent = <?= json_encode($field_help) ?>;

    function showHelp(fieldName) {
        if (!helpContent[fieldName]) return;
        
        const help = helpContent[fieldName];
        document.getElementById('helpModalTitle').textContent = help.title;
        
        let html = '<div style="font-size: 0.95rem;">' + help.content + '</div>';
        
        if (help.example) {
            html += '<div style="margin-top: 1.5rem; padding: 1rem; background: #f9f9f9; border-left: 4px solid var(--teal); border-radius: 4px;">';
            html += '<strong style="color: var(--teal);">Example:</strong><br><div style="margin-top: 0.5rem; color: #666;">' + help.example + '</div></div>';
        }
        
        if (help.link) {
            html += '<div style="margin-top: 1.5rem;"><a href="' + help.link + '" target="_blank" style="color: var(--trail-green); font-weight: 600;">→ ' + help.link_text + '</a></div>';
        }
        
        document.getElementById('helpModalContent').innerHTML = html;
        document.getElementById('helpModal').style.display = 'flex';
    }

    function closeHelp() {
        document.getElementById('helpModal').style.display = 'none';
    }

    document.getElementById('helpModal').addEventListener('click', function(e) {
        if (e.target === this) closeHelp();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeHelp();
    });
    </script>
</body>
</html>
