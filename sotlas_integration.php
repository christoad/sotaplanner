<?php
// Add this to nominate.php after the form submission handling

/**
 * Fetch data from SOTLAS API when user enters SOTA reference
 * This pre-fills trail data from community contributions
 */
function fetch_and_import_sotlas_data($db, $summit_id, $sota_ref, $planning_group_id) {
    // Query SOTLAS API
    $api_url = "https://api.sotl.as/summits/" . urlencode($sota_ref);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => 'User-Agent: SOTA-Planner/1.0'
        ]
    ]);
    
    $response = @file_get_contents($api_url, false, $context);
    
    if (!$response) {
        return [
            'success' => false,
            'message' => 'Could not reach SOTLAS API'
        ];
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['code'])) {
        return [
            'success' => false,
            'message' => 'No data found for this summit'
        ];
    }
    
    $imported = [];
    $updates = [];
    
    // Basic summit info
    if (isset($data['name'])) {
        $updates['name'] = $data['name'];
        $imported[] = 'name';
    }
    
    if (isset($data['points'])) {
        $updates['points'] = intval($data['points']);
        $imported[] = 'points';
    }
    
    if (isset($data['altitude'])) {
        $updates['elevation_ft'] = round($data['altitude'] * 3.28084); // Convert m to ft
        $imported[] = 'elevation';
    }
    
    if (isset($data['latitude']) && isset($data['longitude'])) {
        $updates['latitude'] = floatval($data['latitude']);
        $updates['longitude'] = floatval($data['longitude']);
        $imported[] = 'coordinates';
    }
    
    // Trail data from routes
    if (isset($data['routes']) && is_array($data['routes']) && !empty($data['routes'])) {
        // Use the first route as default
        $route = $data['routes'][0];
        
        if (isset($route['distance'])) {
            // SOTLAS gives one-way distance in km, we need round-trip in miles
            $distance_km = floatval($route['distance']);
            $updates['hike_distance_mi'] = round($distance_km * 2 * 0.621371, 2);
            $imported[] = 'distance';
        }
        
        if (isset($route['ascent'])) {
            // Convert m to ft
            $gain_m = floatval($route['ascent']);
            $updates['hike_elevation_gain_ft'] = round($gain_m * 3.28084);
            $imported[] = 'elevation_gain';
        }
        
        if (isset($route['difficulty'])) {
            // Map SOTLAS difficulty to our scale
            $difficulty_map = [
                'easy' => 'easy',
                'moderate' => 'moderate', 
                'hard' => 'hard',
                'very_hard' => 'very hard'
            ];
            $sotlas_difficulty = strtolower($route['difficulty']);
            if (isset($difficulty_map[$sotlas_difficulty])) {
                $updates['difficulty'] = $difficulty_map[$sotlas_difficulty];
                $imported[] = 'difficulty';
            }
        }
        
        // Trailhead coordinates
        if (isset($route['start_point'])) {
            if (isset($route['start_point']['latitude']) && isset($route['start_point']['longitude'])) {
                $updates['trailhead_lat'] = floatval($route['start_point']['latitude']);
                $updates['trailhead_lng'] = floatval($route['start_point']['longitude']);
                $imported[] = 'trailhead';
            }
        }
        
        // GPX link
        if (isset($route['gpx']) && !empty($route['gpx'])) {
            $gpx_url = $route['gpx'];
            
            // Download and save GPX file
            $gpx_content = @file_get_contents($gpx_url);
            if ($gpx_content) {
                $gpx_dir = '/home/claude/gpx_files';
                if (!is_dir($gpx_dir)) {
                    mkdir($gpx_dir, 0755, true);
                }
                
                $filename = 'sotlas_' . $sota_ref . '_' . time() . '.gpx';
                $filepath = $gpx_dir . '/' . $filename;
                
                if (file_put_contents($filepath, $gpx_content)) {
                    // Analyze the GPX
                    $gpx_stats = analyze_gpx_track($filepath, $sota_ref);
                    
                    if ($gpx_stats) {
                        // Store GPX record
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
                            $summit_id, $planning_group_id, $filename, $filepath,
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
                        
                        $updates['gpx_source'] = 'sotlas';
                        $imported[] = 'gpx_track';
                        
                        // Use GPX-derived data if more accurate
                        if ($gpx_stats['total_distance'] > 0) {
                            $updates['hike_distance_mi'] = round($gpx_stats['total_distance'] * 0.621371, 2);
                        }
                        if ($gpx_stats['elevation_gain'] > 0) {
                            $updates['hike_elevation_gain_ft'] = round($gpx_stats['elevation_gain'] * 3.28084);
                        }
                    }
                }
            }
        }
    }
    
    // Build update query
    if (!empty($updates)) {
        $updates['data_source'] = 'sotlas';
        $updates['sotlas_data_fetched'] = 1;
        $updates['sotlas_last_fetch'] = date('Y-m-d H:i:s');
        
        $set_clauses = [];
        $params = [];
        
        foreach ($updates as $field => $value) {
            $set_clauses[] = "$field = ?";
            $params[] = $value;
        }
        
        $params[] = $summit_id;
        
        $sql = "UPDATE summits SET " . implode(', ', $set_clauses) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return [
            'success' => true,
            'imported' => $imported,
            'message' => 'Imported from SOTLAS: ' . implode(', ', $imported)
        ];
    }
    
    return [
        'success' => false,
        'message' => 'No usable data found on SOTLAS'
    ];
}

// Usage in nomination flow:
// After inserting summit, call:
// $sotlas_result = fetch_and_import_sotlas_data($db, $summit_id, $sota_ref, $planning_group_id);
// Display message to user about what was imported
?>
