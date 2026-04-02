<?php
/**
 * Plugin Name: SOTA Magic
 * Description: Block-based SOTA data uploader with GPX maps and contact tables
 * Version: 0.607 Beta
 * Author: Created by KI6CR
 */

if (!defined('ABSPATH')) exit;

// Add Settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    array_unshift($links, '<a href="options-general.php?page=sota-magic-settings">Settings</a>');
    return $links;
});

// Allow GPX uploads
add_filter('upload_mimes', function($mimes) {
    $mimes['gpx'] = 'application/gpx+xml';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function($data, $file, $filename) {
    if (pathinfo($filename, PATHINFO_EXTENSION) === 'gpx') {
        $data['ext'] = 'gpx';
        $data['type'] = 'application/gpx+xml';
    }
    return $data;
}, 10, 3);

// SETTINGS
add_action('admin_menu', function() {
    add_options_page('SOTA Magic Settings', 'SOTA Magic', 'manage_options', 'sota-magic-settings', 'sota_magic_settings_page');
});

add_action('admin_init', function() {
    $options = [
        'sota_headline_gpx'   => 'Activation GPS Track',
        'sota_headline_csv'   => 'Activation Contacts',
        'sota_headline_map'   => 'Contact Map',
        'sota_bg_color'       => '#ffffff',
        'sota_text_color'     => '#333333',
        'sota_is_transparent' => 0,
        'sota_use_theme_font' => 0,
        'sota_s2s_highlight'  => '#ffebee',
        'sota_s2s_text_color' => '#d32f2f',
        'sota_show_contact_map' => 1,
        'sota_qrz_username'   => '',
        'sota_qrz_password'   => '',
        'sota_show_gpx_stats' => 1,
        'sota_stationary_threshold' => '0.3',
        'sota_unit_system'    => 'metric',
        'sota_activation_zone_radius' => '50',
        'sota_rest_threshold_minutes' => '3',
        'sota_use_azapi' => 1,
        'sota_debug_mode' => 0
    ];
    foreach ($options as $key => $default) {
        register_setting('sota_magic_group', $key);
        if (get_option($key) === false) update_option($key, $default);
    }
});

function sota_magic_settings_page() {
    if (!current_user_can('manage_options')) return;
    
    if (isset($_POST['sota_magic_save'])) {
        check_admin_referer('sota_magic_settings');
        update_option('sota_headline_gpx', sanitize_text_field($_POST['sota_headline_gpx']));
        update_option('sota_headline_csv', sanitize_text_field($_POST['sota_headline_csv']));
        update_option('sota_headline_map', sanitize_text_field($_POST['sota_headline_map']));
        update_option('sota_bg_color', sanitize_hex_color($_POST['sota_bg_color']));
        update_option('sota_text_color', sanitize_hex_color($_POST['sota_text_color']));
        update_option('sota_is_transparent', isset($_POST['sota_is_transparent']) ? 1 : 0);
        update_option('sota_use_theme_font', isset($_POST['sota_use_theme_font']) ? 1 : 0);
        update_option('sota_s2s_highlight', sanitize_hex_color($_POST['sota_s2s_highlight']));
        update_option('sota_s2s_text_color', sanitize_hex_color($_POST['sota_s2s_text_color']));
        update_option('sota_show_contact_map', isset($_POST['sota_show_contact_map']) ? 1 : 0);
        update_option('sota_qrz_username', sanitize_text_field($_POST['sota_qrz_username']));
        update_option('sota_qrz_password', sanitize_text_field($_POST['sota_qrz_password']));
        update_option('sota_show_gpx_stats', isset($_POST['sota_show_gpx_stats']) ? 1 : 0);
        update_option('sota_stationary_threshold', sanitize_text_field($_POST['sota_stationary_threshold']));
        update_option('sota_unit_system', sanitize_text_field($_POST['sota_unit_system']));
        update_option('sota_activation_zone_radius', sanitize_text_field($_POST['sota_activation_zone_radius']));
        update_option('sota_rest_threshold_minutes', sanitize_text_field($_POST['sota_rest_threshold_minutes']));
        update_option('sota_use_azapi', isset($_POST['sota_use_azapi']) ? 1 : 0);
        update_option('sota_debug_mode', isset($_POST['sota_debug_mode']) ? 1 : 0);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>🏔️ SOTA Magic Settings</h1>
        <p style="font-size:12px;color:#666;"><em>Created by KI6CR - Version 0.607 Beta</em></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('sota_magic_settings'); ?>
            <table class="form-table">
                <tr><th colspan="2"><h2>Headlines</h2></th></tr>
                <tr><th>GPX Headline</th><td><input type="text" name="sota_headline_gpx" value="<?php echo esc_attr(get_option('sota_headline_gpx')); ?>" class="regular-text" /></td></tr>
                <tr><th>CSV Headline</th><td><input type="text" name="sota_headline_csv" value="<?php echo esc_attr(get_option('sota_headline_csv')); ?>" class="regular-text" /></td></tr>
                <tr><th>Map Headline</th><td><input type="text" name="sota_headline_map" value="<?php echo esc_attr(get_option('sota_headline_map')); ?>" class="regular-text" /></td></tr>
                
                <tr><th colspan="2"><h2>Colors</h2></th></tr>
                <tr><th>Background</th><td><input type="color" name="sota_bg_color" value="<?php echo esc_attr(get_option('sota_bg_color')); ?>" /></td></tr>
                <tr><th>Text</th><td><input type="color" name="sota_text_color" value="<?php echo esc_attr(get_option('sota_text_color')); ?>" /></td></tr>
                <tr><th>Transparent BG</th><td><input type="checkbox" name="sota_is_transparent" value="1" <?php checked(1, get_option('sota_is_transparent')); ?> /></td></tr>
                <tr><th>Use Theme Font</th><td><input type="checkbox" name="sota_use_theme_font" value="1" <?php checked(1, get_option('sota_use_theme_font')); ?> /></td></tr>
                
                <tr><th colspan="2"><h2>S2S Highlighting</h2></th></tr>
                <tr><th>S2S Background</th><td><input type="color" name="sota_s2s_highlight" value="<?php echo esc_attr(get_option('sota_s2s_highlight')); ?>" /></td></tr>
                <tr><th>S2S Text</th><td><input type="color" name="sota_s2s_text_color" value="<?php echo esc_attr(get_option('sota_s2s_text_color')); ?>" /></td></tr>
                
                <tr><th colspan="2"><h2>GPX Track Analysis</h2></th></tr>
                <tr><th colspan="2"><p style="background:#f0f0f0;padding:10px;border-left:4px solid #0073aa;margin:10px 0;">
                    <strong>How Hiking vs. Activation Time is Calculated:</strong><br>
                    The plugin can use two methods to determine the activation zone:<br>
                    <strong>1. Activation.Zone API (Recommended):</strong> Queries api.activation.zone for the precise activation zone based on terrain elevation data (25m vertical drop per SOTA rules). Most accurate!<br>
                    <strong>2. Fallback Method:</strong> Uses a simple radius around the highest GPS point. Used automatically if the API is unavailable or disabled.<br><br>
                    <strong>All time spent within the activation zone counts as activation time</strong> (regardless of movement - you're often adjusting antenna, repositioning, taking photos, etc.). All other time (including rest breaks during the hike) counts as hiking time, with rest breaks shown as a sub-note for reference.
                </p></th></tr>
                <tr><th>Use Activation.Zone API</th><td>
                    <input type="checkbox" name="sota_use_azapi" value="1" <?php checked(1, get_option('sota_use_azapi')); ?> />
                    <br><small>Query <a href="https://activation.zone" target="_blank">activation.zone</a> (by N6ARA) for precise activation zone geometry based on terrain data. If disabled or API fails, falls back to radius method.</small>
                </td></tr>
                <tr><th>Show Debug Info</th><td>
                    <input type="checkbox" name="sota_debug_mode" value="1" <?php checked(1, get_option('sota_debug_mode')); ?> />
                    <br><small>Show technical debugging information below GPX stats (only visible to admins). Useful for troubleshooting API issues.</small>
                </td></tr>
                <tr><th>Show GPX Statistics</th><td><input type="checkbox" name="sota_show_gpx_stats" value="1" <?php checked(1, get_option('sota_show_gpx_stats')); ?> /><br><small>Display hiking time, activation time, and other statistics</small></td></tr>
                <tr><th>Unit System</th><td>
                    <select name="sota_unit_system">
                        <option value="metric" <?php selected('metric', get_option('sota_unit_system')); ?>>Metric (km, m, km/h)</option>
                        <option value="imperial" <?php selected('imperial', get_option('sota_unit_system')); ?>>Imperial (mi, ft, mph)</option>
                    </select>
                    <br><small>Choose how distances and speeds are displayed</small>
                </td></tr>
                <tr><th>Activation Zone Radius</th><td><input type="number" name="sota_activation_zone_radius" value="<?php echo esc_attr(get_option('sota_activation_zone_radius')); ?>" step="10" min="20" max="200" style="width:80px;" /> meters<br><small>Used as fallback if Activation.Zone API is disabled or unavailable (default: 50m)</small></td></tr>
                <tr><th>Rest Break Threshold</th><td><input type="number" name="sota_rest_threshold_minutes" value="<?php echo esc_attr(get_option('sota_rest_threshold_minutes')); ?>" step="1" min="1" max="30" style="width:80px;" /> minutes<br><small>Minimum duration to count as a rest break. Short stops (photos, water) won't count as breaks. (default: 3 min)</small></td></tr>
                <tr><th>Stationary Speed Threshold</th><td><input type="number" name="sota_stationary_threshold" value="<?php echo esc_attr(get_option('sota_stationary_threshold')); ?>" step="0.1" min="0.1" max="2.0" style="width:80px;" /> km/h<br><small>Speed below this is considered stationary (default: 0.3 km/h)</small></td></tr>
                
                <tr><th colspan="2"><h2>Contact Map</h2></th></tr>
                <tr><th colspan="2"><p style="background:#f0f0f0;padding:10px;border-left:4px solid #0073aa;margin:10px 0;">
                    <strong>How Contact Locations are Determined:</strong><br>
                    • <strong>Summit-to-Summit (S2S) contacts:</strong> Exact summit coordinates from SOTA API<br>
                    • <strong>Regular contacts:</strong> Station location from QRZ.com database (requires QRZ credentials below)<br>
                    • Locations are looked up once when the map loads and cached for performance
                </p></th></tr>
                <tr><th>Show Contact Map</th><td><input type="checkbox" name="sota_show_contact_map" value="1" <?php checked(1, get_option('sota_show_contact_map')); ?> /></td></tr>
                <tr><th>QRZ Username</th><td><input type="text" name="sota_qrz_username" value="<?php echo esc_attr(get_option('sota_qrz_username')); ?>" class="regular-text" /><br><small>Your QRZ.com callsign</small></td></tr>
                <tr><th>QRZ Password</th><td><input type="password" name="sota_qrz_password" value="<?php echo esc_attr(get_option('sota_qrz_password')); ?>" class="regular-text" /><br><small>Your QRZ.com password</small></td></tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'sota_magic_save'); ?>
        </form>
    </div>
    <?php
}

/**
 * Query activation.zone API for summit activation zone polygon
 * Returns array of lat/lon coordinates defining the polygon, or null on failure
 */
function get_activation_zone_from_api($summit_ref, $summit_lat, $summit_lon, $summit_alt) {
    // Format summit reference - try keeping slashes, only remove dash
    $summit_ref_clean = str_replace('-', '', $summit_ref);
    
    $debug = "Original: $summit_ref | Cleaned: $summit_ref_clean | ";
    
    // Prepare API request
    $api_url = 'https://api.activation.zone';
    
    // API requires specific fields
    $data_array = array(
        'summit_ref' => $summit_ref_clean,
        'summit_lat' => floatval($summit_lat),
        'summit_long' => floatval($summit_lon),
        'summit_alt' => intval(round($summit_alt)),  // Must be integer!
        'deg_delta' => 0.001,  // Search within ~110m of summit (changed from 0.01)
        'sota_summit_alt_thres' => 25  // SOTA rule: 25m vertical drop
    );
    
    $json_data = json_encode($data_array);
    $debug .= "JSON: " . $json_data . " | Calling API | ";
    
    $options = array(
        'http' => array(
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json' . "\r\n" .
                         'Accept: application/json' . "\r\n",
            'content' => $json_data,
            'timeout' => 30,
            'user_agent' => 'SOTA-Magic-Plugin/0.517',
            'ignore_errors' => true
        )
    );
    
    $context = stream_context_create($options);
    $result = @file_get_contents($api_url, false, $context);
    
    if ($result === false) {
        $error = error_get_last();
        $debug .= "API call failed: " . ($error ? $error['message'] : 'Unknown error');
        return ['polygon' => null, 'debug' => $debug];
    }
    
    $debug .= "Response received (" . strlen($result) . " bytes) | ";
    
    // Check HTTP response code
    if (isset($http_response_header)) {
        $debug .= "Status: " . $http_response_header[0] . " | ";
    }
    
    // Parse response
    $response = json_decode($result, true);
    if (!$response) {
        $debug .= "JSON decode failed | Raw response: " . substr($result, 0, 200);
        return ['polygon' => null, 'debug' => $debug];
    }
    
    $debug .= "Keys: " . implode(', ', array_keys($response)) . " | ";
    
    // Check for error/detail in response
    if (isset($response['error'])) {
        $debug .= "API Error: " . $response['error'];
        return ['polygon' => null, 'debug' => $debug];
    }
    
    if (isset($response['detail'])) {
        $debug .= "API Detail: " . (is_array($response['detail']) ? json_encode($response['detail']) : $response['detail']) . " | ";
    }
    
    // Extract polygon coordinates from response
    // Try different possible response formats
    if (isset($response['az'])) {
        $debug .= "Found 'az' key | Type: " . gettype($response['az']) . " | ";
        
        if (is_array($response['az'])) {
            $debug .= "AZ is array | ";
            
            // Show what keys/structure az has
            if (count($response['az']) > 0) {
                $first_item = reset($response['az']);
                if (is_array($first_item)) {
                    $debug .= "First item is array with keys: " . implode(',', array_keys($first_item)) . " | ";
                } else {
                    $debug .= "First item type: " . gettype($first_item) . " | ";
                }
            }
            
            $debug .= "Count: " . count($response['az']) . " | ";
            
            if (isset($response['az']['coordinates'])) {
                $debug .= "Found az.coordinates";
                return ['polygon' => $response['az']['coordinates'], 'debug' => $debug];
            } elseif (isset($response['az']['geometry'])) {
                $debug .= "Found az.geometry";
                if (isset($response['az']['geometry']['coordinates'])) {
                    return ['polygon' => $response['az']['geometry']['coordinates'], 'debug' => $debug];
                }
            } elseif (isset($response['az'][0]) && is_array($response['az'][0])) {
                // Check if it's a GeoJSON feature collection
                if (isset($response['az'][0]['geometry'])) {
                    $debug .= "Found az[0].geometry";
                    return ['polygon' => $response['az'][0]['geometry']['coordinates'], 'debug' => $debug];
                }
                // Or just direct coordinate array
                $debug .= "AZ appears to be direct coordinate array";
                return ['polygon' => $response['az'], 'debug' => $debug];
            }
        } elseif (is_string($response['az'])) {
            $debug .= "AZ is string (length " . strlen($response['az']) . ") | ";
            
            // Check if it's WKT format (starts with POLYGON)
            if (strpos($response['az'], 'POLYGON') === 0) {
                $debug .= "Detected WKT POLYGON format | ";
                
                // Parse WKT: POLYGON ((-117.899 34.346, -117.900 34.346, ...))
                // Extract the coordinates from between the parentheses
                if (preg_match('/POLYGON\s*\(\(([^)]+)\)\)/', $response['az'], $matches)) {
                    $coords_string = $matches[1];
                    $debug .= "Extracted coords string | ";
                    
                    // Split by comma to get individual points
                    $points = explode(',', $coords_string);
                    $coordinates = array();
                    
                    foreach ($points as $point) {
                        $point = trim($point);
                        // Split by space to get lon, lat
                        $parts = preg_split('/\s+/', $point);
                        if (count($parts) >= 2) {
                            // WKT is [lon, lat], which is what we want for GeoJSON
                            $coordinates[] = array(floatval($parts[0]), floatval($parts[1]));
                        }
                    }
                    
                    if (count($coordinates) > 0) {
                        $debug .= "Parsed " . count($coordinates) . " coordinate pairs from WKT";
                        return ['polygon' => array($coordinates), 'debug' => $debug];  // Wrap in array for polygon format
                    }
                }
                $debug .= "Failed to parse WKT | ";
            } else {
                // Try JSON decode
                $debug .= "Not WKT, trying JSON decode | ";
                $az_decoded = json_decode($response['az'], true);
                if ($az_decoded === null) {
                    $debug .= "JSON decode failed (error: " . json_last_error_msg() . ") | ";
                } else {
                    $debug .= "Decoded successfully, keys: " . implode(',', array_keys($az_decoded)) . " | ";
                    if (isset($az_decoded['coordinates'])) {
                        return ['polygon' => $az_decoded['coordinates'], 'debug' => $debug];
                    } elseif (isset($az_decoded['geometry']['coordinates'])) {
                        return ['polygon' => $az_decoded['geometry']['coordinates'], 'debug' => $debug];
                    }
                }
            }
        }
    } elseif (isset($response['coordinates']) && is_array($response['coordinates'])) {
        $debug .= "Found coordinates";
        return ['polygon' => $response['coordinates'], 'debug' => $debug];
    } elseif (isset($response['geometry']['coordinates']) && is_array($response['geometry']['coordinates'])) {
        $debug .= "Found geometry.coordinates";
        return ['polygon' => $response['geometry']['coordinates'], 'debug' => $debug];
    }
    
    $debug .= "No valid coordinates found in known keys";
    return ['polygon' => null, 'debug' => $debug];
}

/**
 * Check if a point is inside a polygon using ray casting algorithm
 * @param float $lat Point latitude
 * @param float $lon Point longitude
 * @param array $polygon Array of [lat, lon] pairs defining polygon vertices
 * @return bool True if point is inside polygon
 */
function point_in_polygon($lat, $lon, $polygon) {
    if (!is_array($polygon) || count($polygon) < 3) {
        return false;
    }
    
    $inside = false;
    $count = count($polygon);
    
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $vertex_i = $polygon[$i];
        $vertex_j = $polygon[$j];
        
        // Handle different coordinate formats [lon, lat] or [lat, lon]
        // GeoJSON typically uses [lon, lat] but we'll check both
        if (is_array($vertex_i) && count($vertex_i) >= 2) {
            // Assume [lon, lat] format (GeoJSON standard)
            $lat_i = $vertex_i[1];
            $lon_i = $vertex_i[0];
            $lat_j = $vertex_j[1];
            $lon_j = $vertex_j[0];
        } else {
            continue;
        }
        
        // Ray casting algorithm
        if ((($lon_i > $lon) != ($lon_j > $lon)) &&
            ($lat < ($lat_j - $lat_i) * ($lon - $lon_i) / ($lon_j - $lon_i) + $lat_i)) {
            $inside = !$inside;
        }
    }
    
    return $inside;
}

/**
 * Analyze GPX track to determine hiking vs stationary time
 * Uses hybrid approach: activation zone around summit peak + time threshold for rest breaks
 */
/**
 * Analyze GPX track to determine hiking vs stationary time
 * Uses hybrid approach: activation.zone API (if enabled) or radius fallback
 */
function analyze_gpx_track($gpx_url, $csv_url = null) {
    $stationary_threshold = floatval(get_option('sota_stationary_threshold', 0.3)); // km/h
    $activation_zone_radius = floatval(get_option('sota_activation_zone_radius', 50)); // meters
    $rest_threshold_minutes = floatval(get_option('sota_rest_threshold_minutes', 10)); // minutes
    $rest_threshold_seconds = $rest_threshold_minutes * 60;
    $use_azapi = get_option('sota_use_azapi', 1);
    
    // Download and parse GPX
    $gpx_content = @file_get_contents($gpx_url);
    if (!$gpx_content) {
        return null;
    }
    
    $xml = @simplexml_load_string($gpx_content);
    if (!$xml) {
        return null;
    }
    
    // Register namespaces
    $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
    
    // Get all trackpoints
    $trackpoints = $xml->xpath('//gpx:trkpt');
    if (!$trackpoints || count($trackpoints) < 2) {
        return null;
    }
    
    // First pass: collect all points and find summit (highest elevation)
    $points = [];
    $max_elevation = -999999;
    $min_elevation = 999999;
    $summit_lat = null;
    $summit_lon = null;
    
    foreach ($trackpoints as $point) {
        $lat = floatval($point['lat']);
        $lon = floatval($point['lon']);
        $ele = floatval($point->ele);
        $time = strtotime((string)$point->time);
        
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
    
    // Try to get activation zone from API if enabled and CSV is available
    $activation_zone_polygon = null;
    $using_api = false;
    $summit_ref = null; // Declare here so it's available for return
    $api_debug_message = 'Not attempted';
    
    error_log('SOTA Magic: use_azapi setting = ' . $use_azapi);
    error_log('SOTA Magic: csv_url = ' . ($csv_url ? 'present' : 'null'));
    
    if ($use_azapi && $csv_url) {
        // Try to get summit reference from CSV
        if (($handle = @fopen($csv_url, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($data[0] == 'V2' && !empty($data[2])) {
                    $summit_ref = $data[2]; // MySummit column
                    error_log('SOTA Magic: Found summit reference: ' . $summit_ref);
                    break;
                }
            }
            fclose($handle);
        }
        
        error_log('SOTA Magic: Summit ref = ' . ($summit_ref ? $summit_ref : 'not found'));
        
        // Query SOTA API to get the actual summit coordinates
        if ($summit_ref) {
            error_log('SOTA Magic: Fetching official summit coordinates from SOTA API...');
            $sota_api_url = 'https://api2.sota.org.uk/api/summits/' . urlencode($summit_ref);
            $sota_response = @file_get_contents($sota_api_url);
            
            if ($sota_response) {
                $sota_data = json_decode($sota_response, true);
                if ($sota_data && isset($sota_data['latitude']) && isset($sota_data['longitude']) && isset($sota_data['altM'])) {
                    // Use official SOTA coordinates instead of GPX highest point
                    $summit_lat = floatval($sota_data['latitude']);
                    $summit_lon = floatval($sota_data['longitude']);
                    $max_elevation = floatval($sota_data['altM']);
                    error_log("SOTA Magic: Using official coordinates: {$summit_lat}, {$summit_lon}, {$max_elevation}m");
                }
            }
        }
        
        // Query activation.zone API if we have summit reference
        if ($summit_ref) {
            error_log('SOTA Magic: Calling activation.zone API...');
            $api_result = get_activation_zone_from_api($summit_ref, $summit_lat, $summit_lon, $max_elevation);
            if (is_array($api_result)) {
                $activation_zone_polygon = $api_result['polygon'];
                $api_debug_message = $api_result['debug'];
                if ($activation_zone_polygon) {
                    $using_api = true;
                    error_log('SOTA Magic: API returned polygon with ' . count($activation_zone_polygon) . ' points');
                } else {
                    error_log('SOTA Magic: API call failed or returned null');
                }
            }
        }
    } else {
        if (!$use_azapi) error_log('SOTA Magic: API disabled in settings');
        if (!$csv_url) error_log('SOTA Magic: No CSV URL provided');
    }
    
    // Second pass: analyze segments and classify time
    $total_time = 0;
    $hiking_time = 0;
    $activation_time = 0;
    $rest_break_time = 0;
    $total_distance = 0;
    $hiking_distance = 0;
    $elevation_gain = 0;
    $elevation_loss = 0;
    $prev_elevation = null;
    
    // Track stationary periods outside activation zone
    $current_rest_start = null;
    $current_rest_duration = 0;
    
    // Debug: count points in zone
    $points_in_zone = 0;
    $stationary_in_zone = 0;
    $max_speed_in_zone = 0;
    $min_speed_in_zone = 999;
    
    for ($i = 1; $i < count($points); $i++) {
        $prev_point = $points[$i - 1];
        $curr_point = $points[$i];
        
        // Calculate distance and time
        $distance = haversine_distance($prev_point['lat'], $prev_point['lon'], $curr_point['lat'], $curr_point['lon']);
        $time_diff = $curr_point['time'] - $prev_point['time'];
        
        if ($time_diff <= 0) continue;
        
        // Calculate speed in km/h
        $speed = ($distance / 1000) / ($time_diff / 3600);
        
        // Calculate elevation change
        if ($prev_elevation !== null) {
            $ele_diff = $curr_point['ele'] - $prev_elevation;
            if ($ele_diff > 0) {
                $elevation_gain += $ele_diff;
            } else {
                $elevation_loss += abs($ele_diff);
            }
        }
        $prev_elevation = $curr_point['ele'];
        
        // Check if current point is in activation zone
        $in_activation_zone = false;
        
        if ($using_api && $activation_zone_polygon) {
            // Use API polygon - unwrap the outer array if needed
            $polygon_coords = is_array($activation_zone_polygon[0]) && isset($activation_zone_polygon[0][0]) && is_array($activation_zone_polygon[0][0]) 
                ? $activation_zone_polygon[0]  // Unwrap if double-nested
                : $activation_zone_polygon;     // Use as-is if single level
            
            // Debug: Log first check on first iteration
            if ($i == 1) {
                error_log("SOTA Magic: First polygon check - Point: {$curr_point['lat']}, {$curr_point['lon']}");
                error_log("SOTA Magic: Polygon has " . count($polygon_coords) . " vertices");
                error_log("SOTA Magic: First vertex: " . json_encode($polygon_coords[0]));
                error_log("SOTA Magic: Polygon structure: " . (is_array($polygon_coords[0][0]) ? 'double-nested' : 'single-level'));
            }
            
            $in_activation_zone = point_in_polygon($curr_point['lat'], $curr_point['lon'], $polygon_coords);
            
            // Debug: count
            if ($in_activation_zone) {
                $points_in_zone++;
                if ($is_stationary) $stationary_in_zone++;
                if ($speed > $max_speed_in_zone) $max_speed_in_zone = $speed;
                if ($speed < $min_speed_in_zone) $min_speed_in_zone = $speed;
            }
            
            // Debug: Log first few results
            if ($i <= 5) {
                error_log("SOTA Magic: Point $i - Speed: " . round($speed, 2) . " km/h, In zone: " . ($in_activation_zone ? 'YES' : 'NO') . ", Stationary: " . ($is_stationary ? 'YES' : 'NO'));
            }
        } else {
            // Use fallback radius method
            $dist_from_summit = haversine_distance($summit_lat, $summit_lon, $curr_point['lat'], $curr_point['lon']);
            $in_activation_zone = ($dist_from_summit <= $activation_zone_radius);
        }
        
        $total_distance += $distance;
        $total_time += $time_diff;
        
        // Classify the segment
        $is_stationary = ($speed <= $stationary_threshold);
        
        if ($in_activation_zone) {
            // In activation zone = activation time (regardless of speed)
            // You're activating whether sitting still or moving around setting up
            $activation_time += $time_diff;
            
            // Reset rest tracking
            $current_rest_start = null;
            $current_rest_duration = 0;
            
        } else if ($is_stationary) {
            // Stationary outside activation zone = potential rest break
            if ($current_rest_start === null) {
                // Start of a stationary period
                $current_rest_start = $prev_point['time'];
                $current_rest_duration = $time_diff;
            } else {
                // Continuing stationary period
                $current_rest_duration += $time_diff;
            }
            
            // Always add to hiking time (all stationary time counts as hiking)
            $hiking_time += $time_diff;
            
            // Check if this stationary period qualifies as a rest break
            $was_below_threshold = ($current_rest_duration - $time_diff) < $rest_threshold_seconds;
            $is_now_above_threshold = $current_rest_duration >= $rest_threshold_seconds;
            
            if ($was_below_threshold && $is_now_above_threshold) {
                // Just crossed threshold - add entire accumulated duration to rest_break_time
                $rest_break_time += $current_rest_duration;
            } else if ($is_now_above_threshold) {
                // Already above threshold - add this segment to rest_break_time
                $rest_break_time += $time_diff;
            }
            // If below threshold, we add to hiking_time but not rest_break_time (short stop)
            
        } else {
            // Moving = hiking time
            $hiking_time += $time_diff;
            $hiking_distance += $distance;
            
            // Reset rest tracking when we start moving again
            $current_rest_start = null;
            $current_rest_duration = 0;
        }
    }
    
    // Calculate average speeds
    $avg_speed = $total_time > 0 ? ($total_distance / 1000) / ($total_time / 3600) : 0;
    $hiking_speed = $hiking_time > 0 ? ($hiking_distance / 1000) / ($hiking_time / 3600) : 0;
    
    return [
        'total_time' => $total_time,
        'hiking_time' => $hiking_time,
        'stationary_time' => $activation_time,
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
        'activation_zone_polygon' => $activation_zone_polygon,
        'activation_zone_radius' => $activation_zone_radius,
        'summit_ref' => $summit_ref,
        'api_debug' => $api_debug_message,
        'polygon_check_debug' => isset($polygon_coords) ? 'Vertices: ' . count($polygon_coords) . ', First: ' . json_encode($polygon_coords[0]) : 'No polygon',
        'points_in_zone' => isset($points_in_zone) ? $points_in_zone : 0,
        'stationary_in_zone' => isset($stationary_in_zone) ? $stationary_in_zone : 0,
        'speed_range_in_zone' => isset($min_speed_in_zone) && $min_speed_in_zone < 999 ? round($min_speed_in_zone, 2) . '-' . round($max_speed_in_zone, 2) . ' km/h' : 'N/A'
    ];
}

/**
 * Calculate distance between two lat/lon points using Haversine formula
 * Returns distance in meters
 */
function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meters
    
    $lat1_rad = deg2rad($lat1);
    $lat2_rad = deg2rad($lat2);
    $delta_lat = deg2rad($lat2 - $lat1);
    $delta_lon = deg2rad($lon2 - $lon1);
    
    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
         cos($lat1_rad) * cos($lat2_rad) *
         sin($delta_lon / 2) * sin($delta_lon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earth_radius * $c;
}

/**
 * Format seconds into human-readable time
 */
function format_time_duration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $minutes);
    } else {
        return sprintf('%dm', $minutes);
    }
}

/**
 * Convert kilometers to miles
 */
function km_to_miles($km) {
    return $km * 0.621371;
}

/**
 * Convert meters to feet
 */
function meters_to_feet($meters) {
    return $meters * 3.28084;
}

/**
 * Format distance based on unit preference
 */
function format_distance($km, $unit_system = 'metric') {
    if ($unit_system === 'imperial') {
        $miles = km_to_miles($km);
        return number_format($miles, 2) . ' mi';
    }
    return number_format($km, 2) . ' km';
}

/**
 * Format elevation based on unit preference
 */
function format_elevation($meters, $unit_system = 'metric') {
    if ($unit_system === 'imperial') {
        $feet = meters_to_feet($meters);
        return number_format($feet, 0) . ' ft';
    }
    return number_format($meters, 0) . ' m';
}

/**
 * Format speed based on unit preference
 */
function format_speed($kmh, $unit_system = 'metric') {
    if ($unit_system === 'imperial') {
        $mph = km_to_miles($kmh);
        return number_format($mph, 1) . ' mph';
    }
    return number_format($kmh, 1) . ' km/h';
}

/**
 * Get distance unit label
 */
function get_distance_unit($unit_system = 'metric') {
    return $unit_system === 'imperial' ? 'mi' : 'km';
}

/**
 * Get elevation unit label
 */
function get_elevation_unit($unit_system = 'metric') {
    return $unit_system === 'imperial' ? 'ft' : 'm';
}

/**
 * Get speed unit label
 */
function get_speed_unit($unit_system = 'metric') {
    return $unit_system === 'imperial' ? 'mph' : 'km/h';
}

// BLOCK REGISTRATION
add_action('init', function() {
    register_block_type('ki6cr/sota-data', [
        'editor_script' => 'sota-editor-js',
        'render_callback' => 'ki6cr_render_sota_data'
    ]);
});

add_action('enqueue_block_editor_assets', function() {
    wp_register_script('sota-editor-js', '', ['wp-blocks','wp-element','wp-editor','wp-components']);
    wp_add_inline_script('sota-editor-js', "
        wp.blocks.registerBlockType('ki6cr/sota-data', {
            title: 'SOTA DATA',
            icon: 'location-alt',
            category: 'common',
            attributes: {
                gpxUrl: {type:'string'},
                csvUrl: {type:'string'}
            },
            edit: function(props) {
                return wp.element.createElement('div', {
                    style:{padding:'25px', background:'\\x23f5f5f5', border:'2px dashed \\x230073aa', borderRadius:'8px', textAlign:'center'}
                },
                    wp.element.createElement('h3', {style:{margin:'0 0 10px 0', color:'\\x230073aa'}}, '🏔️ SOTA DATA UPLOADER'),
                    wp.element.createElement('p', {style:{color:'\\x23d32f2f', fontWeight:'bold', margin:'0 0 10px 0'}}, '⚠️ Map and table visible in Preview only'),
                    wp.element.createElement('p', {style:{color:'\\x23666', fontSize:'13px', marginBottom:'20px'}}, 'Settings → SOTA Magic to customize'),
                    wp.element.createElement('div', {style:{display:'flex', gap:'10px', justifyContent:'center', marginBottom:'15px'}},
                        wp.element.createElement(wp.editor.MediaUpload, {
                            onSelect: function(media) { props.setAttributes({gpxUrl: media.url}); },
                            allowedTypes: ['application/gpx+xml', 'text/xml'],
                            render: function(obj) {
                                return wp.element.createElement(wp.components.Button, {
                                    isPrimary: true,
                                    onClick: obj.open
                                }, props.attributes.gpxUrl ? '✓ GPX Selected' : 'Upload GPX');
                            }
                        }),
                        wp.element.createElement(wp.editor.MediaUpload, {
                            onSelect: function(media) { props.setAttributes({csvUrl: media.url}); },
                            allowedTypes: ['text/csv'],
                            render: function(obj) {
                                return wp.element.createElement(wp.components.Button, {
                                    isPrimary: true,
                                    onClick: obj.open
                                }, props.attributes.csvUrl ? '✓ CSV Selected' : 'Upload CSV');
                            }
                        })
                    ),
                    wp.element.createElement('div', {style:{fontSize:'14px', color:'\\x23666'}},
                        wp.element.createElement('div', null, 'GPX: ' + (props.attributes.gpxUrl ? '✅' : '❌')),
                        wp.element.createElement('div', null, 'CSV: ' + (props.attributes.csvUrl ? '✅' : '❌'))
                    )
                );
            },
            save: function() { return null; }
        });
    ");
});

// RENDER
function ki6cr_render_sota_data($atts) {
    $gpx_url = $atts['gpxUrl'] ?? '';
    $csv_url = $atts['csvUrl'] ?? '';
    if (!$gpx_url && !$csv_url) return '';

    $bg = get_option('sota_is_transparent') ? 'transparent' : get_option('sota_bg_color');
    $text = get_option('sota_text_color');
    $font = get_option('sota_use_theme_font') ? 'inherit' : 'sans-serif';
    $s2s_bg = get_option('sota_s2s_highlight');
    $s2s_text = get_option('sota_s2s_text_color');
    $show_map = get_option('sota_show_contact_map');
    $show_gpx_stats = get_option('sota_show_gpx_stats');
    $unit_system = get_option('sota_unit_system', 'metric');
    
    // Analyze GPX if available and stats are enabled
    $gpx_stats = null;
    if ($gpx_url && $show_gpx_stats) {
        $gpx_stats = analyze_gpx_track($gpx_url, $csv_url);
    }
    
    // Build map iframe URL if needed
    $map_iframe_url = '';
    if ($show_map && $csv_url) {
        $map_iframe_url = plugins_url('contact-map.php', __FILE__) . '?csv=' . urlencode($csv_url) . '&v=1.3';
    }

    ob_start();
    ?>
    <style>
        .sota-main-container {
            background: <?php echo $bg; ?>;
            color: <?php echo $text; ?>;
            font-family: <?php echo $font; ?>;
            padding: 30px;
            border-radius: 12px;
            margin: 40px 0;
            <?php if (!get_option('sota_is_transparent')): ?>
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            <?php endif; ?>
        }
        .sota-main-container h3 {
            color: <?php echo $text; ?>;
            border-bottom: 1px solid <?php echo $text; ?>44;
            padding-bottom: 10px;
        }
        
        /* GPX Statistics Grid */
        .sota-gpx-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
            padding: 20px;
            background: <?php echo $bg === 'transparent' ? 'rgba(255,255,255,0.05)' : ($bg . '22'); ?>;
            border-radius: 8px;
            border: 1px solid <?php echo $text; ?>22;
        }
        
        .sota-stat-box {
            text-align: center;
            padding: 15px;
            background: <?php echo $bg === 'transparent' ? 'rgba(255,255,255,0.1)' : '#fff'; ?>;
            border-radius: 6px;
            <?php if ($bg !== 'transparent'): ?>
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            <?php endif; ?>
        }
        
        .sota-stat-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .sota-stat-value {
            font-size: 24px;
            font-weight: bold;
            color: <?php echo $text; ?>;
            margin-bottom: 5px;
        }
        
        .sota-stat-label {
            font-size: 13px;
            color: <?php echo $text; ?>99;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .sota-stat-secondary {
            font-size: 12px;
            color: <?php echo $text; ?>77;
            margin-top: 5px;
        }
        
        /* Responsive table wrapper */
        .sota-table-wrapper {
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            margin: 20px 0;
        }
        .sota-table {
            width: 100%;
            border-collapse: collapse;
            color: <?php echo $text; ?>;
            min-width: 800px; /* Ensures table doesn't get too cramped */
        }
        .sota-table th {
            border-bottom: 2px solid <?php echo $text; ?>66;
            padding: 12px;
            text-align: left;
            white-space: nowrap;
        }
        .sota-table td {
            padding: 10px;
            border-bottom: 1px solid <?php echo $text; ?>22;
            vertical-align: top;
        }
        /* Allow comments column to wrap text */
        .sota-table td:last-child {
            white-space: normal;
            word-wrap: break-word;
            max-width: 300px;
        }
        .s2s-row td {
            background: <?php echo $s2s_bg; ?> !important;
            color: <?php echo $s2s_text; ?> !important;
            font-weight: bold;
        }
        .s2s-badge {
            background: <?php echo $s2s_text; ?>;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.75em;
            margin-left: 5px;
        }
        .wpgpxmaps {
            background: #fff !important;
            padding: 10px;
            border-radius: 8px;
        }
        .wpgpxmaps * {
            color: #000 !important;
        }
        .wpgpxmaps a {
            color: #0073aa !important;
            text-decoration: underline !important;
        }
        /* Mobile responsiveness */
        @media screen and (max-width: 768px) {
            .sota-main-container {
                padding: 15px;
            }
            .sota-gpx-stats {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
                padding: 15px;
            }
            .sota-stat-icon {
                font-size: 24px;
            }
            .sota-stat-value {
                font-size: 20px;
            }
            .sota-table {
                font-size: 14px;
            }
            .sota-table th,
            .sota-table td {
                padding: 8px;
            }
        }
    </style>

    <div class="sota-main-container">
        <?php if ($gpx_url): ?>
            <h3>🏔️ <?php echo esc_html(get_option('sota_headline_gpx')); ?></h3>
            <?php echo do_shortcode('[sgpx gpx="'.esc_url($gpx_url).'"]'); ?>
            
            <?php if ($gpx_stats && ($gpx_stats['using_api'] || $gpx_stats['activation_zone_radius'])): ?>
            <?php
            // Enqueue the overlay JavaScript file
            wp_enqueue_script(
                'sota-magic-overlay',
                plugins_url('sota-magic-overlay.js', __FILE__),
                array('jquery', 'leaflet'),
                '1.0.0',
                true
            );
            
            // Prepare data for JavaScript
            if ($gpx_stats['using_api'] && $gpx_stats['activation_zone_polygon']) {
                // Convert polygon coordinates from [lon, lat] to [lat, lon] for Leaflet
                $leaflet_coords = array();
                foreach ($gpx_stats['activation_zone_polygon'][0] as $coord) {
                    $leaflet_coords[] = array($coord[1], $coord[0]); // Swap lon,lat to lat,lon
                }
                
                $script_data = array(
                    'mode' => 'polygon',
                    'coordinates' => $leaflet_coords,
                    'summit_lat' => $gpx_stats['summit_lat'],
                    'summit_lon' => $gpx_stats['summit_lon'],
                    'popup_text' => 'Summit / Activation Zone'
                );
            } else {
                $script_data = array(
                    'mode' => 'circle',
                    'summit_lat' => $gpx_stats['summit_lat'],
                    'summit_lon' => $gpx_stats['summit_lon'],
                    'radius' => $gpx_stats['activation_zone_radius'],
                    'popup_text' => 'Summit (radius approximation)'
                );
            }
            
            // Pass data to JavaScript
            wp_localize_script('sota-magic-overlay', 'sotaMagicData', $script_data);
            ?>
            <?php endif; ?>
            
            <script>
                (function() {
                    var fix = function() {
                        document.querySelectorAll('.wpgpxmaps td, .wpgpxmaps span').forEach(function(el) {
                            if (el.innerText.match(/\d+\.\d{3,}/)) {
                                var num = parseFloat(el.innerText);
                                if (!isNaN(num)) {
                                    var unit = el.innerText.replace(/[0-9.]/g, '').trim();
                                    el.innerText = num.toFixed(2) + ' ' + unit;
                                }
                            }
                        });
                    };
                    setInterval(fix, 1000);
                })();
            </script>
            
            <?php if ($gpx_stats): ?>
                <div class="sota-gpx-stats">
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">🥾</div>
                        <div class="sota-stat-value"><?php echo format_time_duration($gpx_stats['hiking_time']); ?></div>
                        <div class="sota-stat-label">Hiking Time</div>
                        <div class="sota-stat-secondary">
                            <?php echo format_distance($gpx_stats['hiking_distance'], $unit_system); ?>
                            <?php if ($gpx_stats['rest_break_time'] > 0): ?>
                                <br><em style="font-size:11px;opacity:0.8;">(<?php echo format_time_duration($gpx_stats['rest_break_time']); ?> breaks)</em>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">📻</div>
                        <div class="sota-stat-value"><?php echo format_time_duration($gpx_stats['stationary_time']); ?></div>
                        <div class="sota-stat-label">Activation Time</div>
                        <div class="sota-stat-secondary">
                            <?php if ($gpx_stats['using_api']): ?>
                                <span title="Using precise activation zone from activation.zone API">✓ API-based zone</span>
                            <?php else: ?>
                                <span title="Using radius approximation method">Within <?php echo $gpx_stats['activation_zone_radius']; ?>m of summit</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">⏱️</div>
                        <div class="sota-stat-value"><?php echo format_time_duration($gpx_stats['total_time']); ?></div>
                        <div class="sota-stat-label">Total Time</div>
                        <div class="sota-stat-secondary"><?php echo format_distance($gpx_stats['total_distance'], $unit_system); ?></div>
                    </div>
                    
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">📈</div>
                        <div class="sota-stat-value"><?php echo format_elevation($gpx_stats['elevation_gain'], $unit_system); ?></div>
                        <div class="sota-stat-label">Elevation Gain</div>
                        <div class="sota-stat-secondary">↓ <?php echo format_elevation($gpx_stats['elevation_loss'], $unit_system); ?> loss</div>
                    </div>
                    
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">🚶</div>
                        <div class="sota-stat-value"><?php echo format_speed($gpx_stats['hiking_speed'], $unit_system); ?></div>
                        <div class="sota-stat-label">Hiking Speed</div>
                        <div class="sota-stat-secondary">average</div>
                    </div>
                    
                    <div class="sota-stat-box">
                        <div class="sota-stat-icon">⛰️</div>
                        <div class="sota-stat-value"><?php echo format_elevation($gpx_stats['max_elevation'], $unit_system); ?></div>
                        <div class="sota-stat-label">Peak Elevation</div>
                        <div class="sota-stat-secondary"><?php echo format_elevation($gpx_stats['min_elevation'], $unit_system); ?> base</div>
                    </div>
                </div>
                
                <!-- Methodology explanation -->
                <div style="margin-top:15px; padding:12px; background:rgba(0,0,0,0.03); border-radius:8px; font-size:13px; color:#666;">
                    <strong>Activation Zone Method:</strong>
                    <?php if ($gpx_stats['using_api']): ?>
                        Using precise terrain-based activation zone from <a href="https://activation.zone" target="_blank" style="color:#0073aa;">activation.zone</a> (by N6ARA). 
                        This uses Digital Elevation Model (DEM) data and the official SOTA 25m vertical drop rule for maximum accuracy.
                    <?php else: ?>
                        Using radius approximation method (<?php echo $gpx_stats['activation_zone_radius']; ?>m from highest GPS point). 
                        For more accuracy, enable the activation.zone API in Settings → SOTA Magic, or ensure your CSV file includes the summit reference.
                    <?php endif; ?>
                </div>
                
                <!-- Debug info -->
                <?php if (current_user_can('manage_options') && get_option('sota_debug_mode')): ?>
                <div style="margin-top:10px; padding:10px; background:#fff3cd; border:1px solid #ffc107; border-radius:5px; font-size:12px; font-family:monospace;">
                    <strong>🔍 Debug Info (only visible to admins):</strong><br>
                    API Enabled: <?php echo get_option('sota_use_azapi') ? 'YES' : 'NO'; ?><br>
                    CSV URL: <?php echo $csv_url ? 'Present' : 'Missing'; ?><br>
                    Summit Reference: <?php echo isset($gpx_stats['summit_ref']) ? $gpx_stats['summit_ref'] : 'Not extracted'; ?><br>
                    Using API: <?php echo $gpx_stats['using_api'] ? 'YES' : 'NO'; ?><br>
                    Summit Lat/Lon: <?php echo $gpx_stats['summit_lat'] . ', ' . $gpx_stats['summit_lon']; ?><br>
                    Max Elevation: <?php echo $gpx_stats['max_elevation']; ?> m<br>
                    Polygon Data: <?php 
                        if ($gpx_stats['activation_zone_polygon']) {
                            $point_count = is_array($gpx_stats['activation_zone_polygon'][0]) ? count($gpx_stats['activation_zone_polygon'][0]) : count($gpx_stats['activation_zone_polygon']);
                            echo 'Present (' . $point_count . ' points)';
                        } else {
                            echo 'NULL';
                        }
                    ?><br>
                    <strong>API Debug:</strong> <?php echo isset($gpx_stats['api_debug']) ? $gpx_stats['api_debug'] : 'N/A'; ?><br>
                    <strong>Polygon Check:</strong> <?php echo isset($gpx_stats['polygon_check_debug']) ? $gpx_stats['polygon_check_debug'] : 'N/A'; ?><br>
                    <strong>Points in Zone:</strong> <?php echo isset($gpx_stats['points_in_zone']) ? $gpx_stats['points_in_zone'] : 0; ?> total, <?php echo isset($gpx_stats['stationary_in_zone']) ? $gpx_stats['stationary_in_zone'] : 0; ?> stationary<br>
                    <strong>Speed Range in Zone:</strong> <?php echo isset($gpx_stats['speed_range_in_zone']) ? $gpx_stats['speed_range_in_zone'] : 'N/A'; ?><br>
                    <strong>Stationary Threshold:</strong> <?php echo get_option('sota_stationary_threshold'); ?> km/h<br>
                    <strong>Activation Time:</strong> <?php echo format_time_duration($gpx_stats['stationary_time']); ?> (<?php echo $gpx_stats['stationary_time']; ?> seconds)
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($map_iframe_url): ?>
            <h3 style="margin-top:40px;">🗺️ <?php echo esc_html(get_option('sota_headline_map')); ?></h3>
            <iframe src="<?php echo esc_url($map_iframe_url); ?>" 
                    style="width:100%; height:500px; border:none; border-radius:8px; background:#f5f5f5;" 
                    title="Contact Map">
            </iframe>
        <?php endif; ?>

        <?php if ($csv_url): ?>
            <h3 style="margin-top:40px;">📡 <?php echo esc_html(get_option('sota_headline_csv')); ?></h3>
            <div class="sota-table-wrapper">
                <table class="sota-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Callsign</th>
                            <th>Frequency</th>
                            <th>Mode</th>
                            <th>My Summit</th>
                            <th>Their Summit</th>
                            <th>Comments</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    if (($handle = @fopen($csv_url, "r")) !== FALSE) {
                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            if ($data[0] == 'V2') {
                                $s2s = !empty(trim($data[8] ?? ''));
                                
                                // Format date according to WordPress settings
                                $csv_date = $data[3]; // Format: DD/MM/YY (e.g., 16/01/26)
                                $parts = explode('/', $csv_date);
                                if (count($parts) == 3) {
                                    $day = (int)$parts[0];
                                    $month = (int)$parts[1];
                                    $year = (int)$parts[2];
                                    // Handle 2-digit year (26 = 2026, not 1926)
                                    $year = $year < 50 ? 2000 + $year : 1900 + $year;
                                    $formatted_date = date_i18n(get_option('date_format'), strtotime("$year-$month-$day"));
                                } else {
                                    $formatted_date = $csv_date; // Fallback to original
                                }
                                
                                echo '<tr class="'.($s2s ? 's2s-row' : '').'">';
                                echo '<td>'.$formatted_date.'</td>';
                                echo '<td>'.$data[4].'</td>';
                                echo '<td><strong>'.$data[7].'</strong>'.($s2s ? '<span class="s2s-badge">S2S</span>' : '').'</td>';
                                echo '<td>'.$data[5].'</td>';
                                echo '<td>'.$data[6].'</td>';
                                echo '<td>'.$data[2].'</td>';
                                echo '<td>'.$data[8].'</td>';
                                echo '<td>'.($data[9] ?? '').'</td>';
                                echo '</tr>';
                            }
                        }
                        fclose($handle);
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
