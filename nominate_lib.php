<?php
// Helpers shared by nominate.php and share_lib.php (saving a shared summit page into a dashboard).

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
