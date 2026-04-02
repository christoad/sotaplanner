<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configure session for Safari/iOS compatibility
// Must be called BEFORE session_start()
if (!session_id()) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    ini_set('session.cookie_lifetime', 86400); // 24 hours
}

// Load credentials from secrets file outside the web root.
// Works whether files live at domain root or in a subfolder.
$_secrets_path = file_exists(dirname(__DIR__) . '/sotaplanner_secrets.php')
    ? dirname(__DIR__) . '/sotaplanner_secrets.php'
    : dirname(dirname(__DIR__)) . '/sotaplanner_secrets.php';

if (!file_exists($_secrets_path)) {
    die("Configuration error: secrets file not found. Upload sotaplanner_secrets.php one level above the web root.");
}
require_once $_secrets_path;
unset($_secrets_path);

// Create database connection
function getDbConnection() {
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $conn;
    } catch(PDOException $e) {
        error_log("Connection failed: " . $e->getMessage());
        die("Database connection failed. Please check your configuration.");
    }
}

// Improved hike time calculation for SOTA activations with radio gear
function calculateHikeTime($distance_mi, $elevation_gain_ft) {
    $time_for_distance = ($distance_mi / 2.5) * 60;
    $time_for_elevation = ($elevation_gain_ft / 1500) * 60;
    $total_time = $time_for_distance + $time_for_elevation;
    $total_time *= 1.10;
    return round($total_time);
}

// Helper function to format time in hours and minutes
function formatTime($minutes) {
    if ($minutes < 60) {
        return $minutes . " min";
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . "h " . $mins . "m";
}

// Calculate drive time using Google Maps Distance Matrix API
function calculateDriveTime($origin_address, $dest_lat, $dest_lng) {
    if (GOOGLE_MAPS_API_KEY === 'YOUR_API_KEY_HERE') {
        return null;
    }
    
    $api_url = "https://maps.googleapis.com/maps/api/distancematrix/json";
    $params = [
        'origins' => $origin_address,
        'destinations' => $dest_lat . ',' . $dest_lng,
        'key' => GOOGLE_MAPS_API_KEY,
        'units' => 'imperial'
    ];
    
    $url = $api_url . '?' . http_build_query($params);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        
        if ($data['status'] === 'OK' && isset($data['rows'][0]['elements'][0]['duration'])) {
            $duration_seconds = $data['rows'][0]['elements'][0]['duration']['value'];
            return round($duration_seconds / 60);
        }
    }
    
    return null;
}

// Get currently selected address for current planning group
function getSelectedAddress($db) {
    $current_group = getCurrentPlanningGroup($db);
    if (!$current_group) {
        return null;
    }
    
    $setting_key = 'selected_address_group_' . $current_group['id'];
    
    $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $stmt->execute([$setting_key]);
    $result = $stmt->fetch();
    
    if ($result) {
        $address_id = $result['setting_value'];
        $stmt = $db->prepare("SELECT * FROM addresses WHERE id = ? AND planning_group_id = ?");
        $stmt->execute([$address_id, $current_group['id']]);
        return $stmt->fetch();
    }
    
    return null;
}

// Get current planning group from SESSION
function getCurrentPlanningGroup($db) {
    if (!isset($_SESSION['current_planning_group_id'])) {
        return null;
    }
    
    $group_id = $_SESSION['current_planning_group_id'];
    $stmt = $db->prepare("SELECT * FROM planning_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    return $stmt->fetch();
}

// Set current planning group in SESSION
function setCurrentPlanningGroup($group_id) {
    $_SESSION['current_planning_group_id'] = $group_id;
}

// Convert distance based on group's units preference
function convertDistance($miles, $units) {
    if ($units === 'metric') {
        return round($miles * 1.60934, 2);
    }
    return $miles;
}

// Convert elevation based on group's units preference
function convertElevation($feet, $units) {
    if ($units === 'metric') {
        return round($feet * 0.3048);
    }
    return $feet;
}

// Get unit labels
function getDistanceUnit($units) {
    return $units === 'metric' ? 'km' : 'mi';
}

function getElevationUnit($units) {
    return $units === 'metric' ? 'm' : 'ft';
}

// Get all planning groups
function getAllPlanningGroups($db) {
    $stmt = $db->query("SELECT * FROM planning_groups ORDER BY id ASC");
    return $stmt->fetchAll();
}

// GPX PROCESSING FUNCTIONS
function analyze_gpx_track($gpx_file_path, $summit_ref = null) {
    $stationary_threshold = 0.3;
    $activation_zone_radius = 50;
    $rest_threshold_seconds = 180;
    
    $gpx_content = @file_get_contents($gpx_file_path);
    if (!$gpx_content) return null;
    
    $xml = @simplexml_load_string($gpx_content);
    if (!$xml) return null;
    
    $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');

    // Support both track points (<trkpt>) and route points (<rtept>)
    $trackpoints = $xml->xpath('//gpx:trkpt');
    if (!$trackpoints || count($trackpoints) < 2) {
        $trackpoints = $xml->xpath('//gpx:rtept');
    }

    if (!$trackpoints || count($trackpoints) < 2) return null;

    $points = [];
    $max_elevation = -999999;
    $min_elevation = 999999;
    $summit_lat = null;
    $summit_lon = null;

    // Detect real timestamps: points must have parseable times that actually differ.
    // Some apps (e.g. Gaia GPS routes) stamp every point with the same time — treat as route-only.
    $has_timestamps = false;
    $sample = array_slice($trackpoints, 0, min(10, count($trackpoints)));
    $sampled_times = [];
    foreach ($sample as $sp) {
        $t = strtotime((string)$sp->time);
        if ($t !== false && !empty((string)$sp->time)) {
            $sampled_times[] = $t;
        }
    }
    if (count($sampled_times) >= 2 && count(array_unique($sampled_times)) > 1) {
        $has_timestamps = true;
    }

    foreach ($trackpoints as $point) {
        $lat = floatval($point['lat']);
        $lon = floatval($point['lon']);
        $ele = floatval($point->ele);
        $time = $has_timestamps ? strtotime((string)$point->time) : 0;

        $points[] = [
            'lat' => $lat,
            'lon' => $lon,
            'ele' => $ele,
            'time' => $time
        ];
        
        if ($ele > $max_elevation) {
            $max_elevation = $ele;
            $summit_lat = $lat;
            $summit_lon = $lon;
        }
        if ($ele < $min_elevation) {
            $min_elevation = $ele;
        }
    }
    
    $activation_zone_polygon = null;
    $using_api = false;
    $activation_zone_method = 'radius';
    
    if ($summit_ref) {
        $sota_api_url = 'https://api2.sota.org.uk/api/summits/' . urlencode($summit_ref);
        $sota_response = @file_get_contents($sota_api_url);
        
        if ($sota_response) {
            $sota_data = json_decode($sota_response, true);
            if ($sota_data && isset($sota_data['latitude'])) {
                $summit_lat = floatval($sota_data['latitude']);
                $summit_lon = floatval($sota_data['longitude']);
                $max_elevation = floatval($sota_data['altM']);
            }
        }
        
        $api_result = get_activation_zone_from_api($summit_ref, $summit_lat, $summit_lon, $max_elevation);
        if ($api_result && isset($api_result['polygon'])) {
            $activation_zone_polygon = $api_result['polygon'];
            $using_api = true;
            $activation_zone_method = 'api';
        }
    }
    
    $total_time = 0;
    $hiking_time = 0;
    $activation_time = 0;
    $rest_break_time = 0;
    $total_distance = 0;
    $hiking_distance = 0;
    $elevation_gain = 0;
    $elevation_loss = 0;
    $prev_elevation = null;
    
    $current_rest_start = null;
    $current_rest_duration = 0;
    
    for ($i = 1; $i < count($points); $i++) {
        $prev_point = $points[$i - 1];
        $curr_point = $points[$i];
        
        $distance = haversine_distance(
            $prev_point['lat'], $prev_point['lon'],
            $curr_point['lat'], $curr_point['lon']
        );
        
        $time_diff = $has_timestamps ? ($curr_point['time'] - $prev_point['time']) : 0;
        if ($has_timestamps && $time_diff <= 0) continue;
        
        $speed = ($has_timestamps && $time_diff > 0) ? ($distance / 1000) / ($time_diff / 3600) : 0;
        
        if ($prev_elevation !== null) {
            $ele_diff = $curr_point['ele'] - $prev_elevation;
            if ($ele_diff > 0) {
                $elevation_gain += $ele_diff;
            } else {
                $elevation_loss += abs($ele_diff);
            }
        }
        $prev_elevation = $curr_point['ele'];
        
        $in_activation_zone = false;
        
        if ($using_api && $activation_zone_polygon) {
            $polygon_coords = is_array($activation_zone_polygon[0][0]) 
                ? $activation_zone_polygon[0] 
                : $activation_zone_polygon;
            $in_activation_zone = point_in_polygon(
                $curr_point['lat'], $curr_point['lon'], $polygon_coords
            );
        } else {
            $dist_from_summit = haversine_distance(
                $summit_lat, $summit_lon,
                $curr_point['lat'], $curr_point['lon']
            );
            $in_activation_zone = ($dist_from_summit <= $activation_zone_radius);
        }
        
        $total_distance += $distance;
        $total_time += $time_diff;
        
        $is_stationary = ($speed <= $stationary_threshold);
        
        if ($in_activation_zone) {
            $activation_time += $time_diff;
            $current_rest_start = null;
            $current_rest_duration = 0;
        } else if ($is_stationary) {
            if ($current_rest_start === null) {
                $current_rest_start = $prev_point['time'];
                $current_rest_duration = $time_diff;
            } else {
                $current_rest_duration += $time_diff;
            }
            
            $hiking_time += $time_diff;
            
            $was_below = ($current_rest_duration - $time_diff) < $rest_threshold_seconds;
            $is_above = $current_rest_duration >= $rest_threshold_seconds;
            
            if ($was_below && $is_above) {
                $rest_break_time += $current_rest_duration;
            } else if ($is_above) {
                $rest_break_time += $time_diff;
            }
        } else {
            $hiking_time += $time_diff;
            $hiking_distance += $distance;
            $current_rest_start = null;
            $current_rest_duration = 0;
        }
    }
    
    $avg_speed = $total_time > 0 ? ($total_distance / 1000) / ($total_time / 3600) : 0;
    $hiking_speed = $hiking_time > 0 ? ($hiking_distance / 1000) / ($hiking_time / 3600) : 0;
    
    return [
        'total_time' => $total_time,
        'hiking_time' => $hiking_time,
        'activation_time' => $activation_time,
        'rest_break_time' => $rest_break_time,
        'total_distance' => $total_distance / 1000,
        'hiking_distance' => $hiking_distance / 1000,
        'max_elevation' => $max_elevation,
        'min_elevation' => $min_elevation,
        'elevation_gain' => $elevation_gain,
        'elevation_loss' => $elevation_loss,
        'avg_speed' => $avg_speed,
        'hiking_speed' => $hiking_speed,
        'num_points' => count($points),
        'summit_lat' => $summit_lat,
        'summit_lon' => $summit_lon,
        'using_api' => $using_api,
        'activation_zone_polygon' => $activation_zone_polygon ? json_encode($activation_zone_polygon) : null,
        'activation_zone_method' => $activation_zone_method,
        'has_timestamps' => $has_timestamps,
    ];
}

function get_activation_zone_from_api($summit_ref, $lat, $lon, $elevation) {
    // API requires the dash removed from the summit code (W6/CT-170 → W6/CT170)
    $ref_clean = str_replace('-', '', $summit_ref);

    $payload = json_encode([
        'summit_ref'            => $ref_clean,
        'summit_lat'            => floatval($lat),
        'summit_long'           => floatval($lon),
        'summit_alt'            => intval(round($elevation)), // must be integer meters
        'deg_delta'             => 0.001,   // search radius ~110m around summit
        'sota_summit_alt_thres' => 25,      // SOTA rule: 25m vertical drop defines zone
    ]);

    $context = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 15,
        'user_agent'    => 'SOTAPlanner/1.0',
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents('https://api.activation.zone', false, $context);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    if (!$data) return null;

    $polygon = _extract_az_polygon($data);
    return $polygon ? ['polygon' => $polygon] : null;
}

// Parse all known response shapes from api.activation.zone
function _extract_az_polygon($data) {
    // Top-level coordinates or geometry
    if (isset($data['coordinates'])) return $data['coordinates'];
    if (isset($data['geometry']['coordinates'])) return $data['geometry']['coordinates'];

    if (!isset($data['az'])) return null;
    $az = $data['az'];

    if (is_array($az)) {
        if (isset($az['coordinates']))            return $az['coordinates'];
        if (isset($az['geometry']['coordinates'])) return $az['geometry']['coordinates'];
        if (isset($az[0]['geometry']['coordinates'])) return $az[0]['geometry']['coordinates'];
        // Direct coordinate array [[lon,lat], ...]
        if (isset($az[0]) && is_array($az[0])) return $az;
    }

    if (is_string($az)) {
        // WKT: POLYGON ((lon lat, lon lat, ...))
        if (preg_match('/POLYGON\s*\(\(([^)]+)\)\)/i', $az, $m)) {
            $coords = [];
            foreach (explode(',', $m[1]) as $pair) {
                $parts = preg_split('/\s+/', trim($pair));
                if (count($parts) >= 2) {
                    $coords[] = [floatval($parts[0]), floatval($parts[1])]; // [lon, lat] GeoJSON order
                }
            }
            if ($coords) return [$coords]; // wrap to match GeoJSON Polygon coordinates format
        }
        // JSON-encoded string
        $decoded = json_decode($az, true);
        if ($decoded) {
            if (isset($decoded['coordinates']))            return $decoded['coordinates'];
            if (isset($decoded['geometry']['coordinates'])) return $decoded['geometry']['coordinates'];
        }
    }

    return null;
}

function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    
    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    
    $a = sin($dlat/2) * sin($dlat/2) + 
         cos($lat1) * cos($lat2) * 
         sin($dlon/2) * sin($dlon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earth_radius * $c;
}

function point_in_polygon($lat, $lon, $polygon) {
    $num_vertices = count($polygon);
    $inside = false;
    
    $p1lat = $polygon[0][1];
    $p1lon = $polygon[0][0];
    
    for ($i = 1; $i <= $num_vertices; $i++) {
        $p2lat = $polygon[$i % $num_vertices][1];
        $p2lon = $polygon[$i % $num_vertices][0];
        
        if ($lat > min($p1lat, $p2lat)) {
            if ($lat <= max($p1lat, $p2lat)) {
                if ($lon <= max($p1lon, $p2lon)) {
                    if ($p1lat != $p2lat) {
                        $xinters = ($lat - $p1lat) * ($p2lon - $p1lon) / ($p2lat - $p1lat) + $p1lon;
                    }
                    if ($p1lon == $p2lon || $lon <= $xinters) {
                        $inside = !$inside;
                    }
                }
            }
        }
        $p1lat = $p2lat;
        $p1lon = $p2lon;
    }
    
    return $inside;
}

function format_time_duration($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    
    return $minutes . 'm';
}
