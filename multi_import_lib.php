<?php
// Copies a shared multi-activation route (and the summits in it) from another
// dashboard into $target_group_id. Used by the "Add this Multi-Summit to your own dashboard"
// flow on the public multi_activate.php view: the visitor finishes onboarding
// into a brand-new dashboard, then this runs before they land on it.
//
// Summit research (trail link, hike distance/elevation, trailhead, difficulty)
// and GPX tracks carry over the same way nominating an already-researched
// summit does in nominate.php. Status and activation history do not: each
// dashboard tracks its own. Drive times are recalculated from the target
// dashboard's selected starting address. Returns the new route's id, or null.

function importMultiActivationIntoGroup(PDO $db, int $source_multi_id, int $target_group_id, string $callsign): ?int {
    $stmt = $db->prepare("SELECT * FROM multi_activations WHERE id = ?");
    $stmt->execute([$source_multi_id]);
    $src = $stmt->fetch();
    if (!$src) return null;
    $source_group_id = (int)$src['planning_group_id'];

    $stmt = $db->prepare("SELECT summit_id FROM multi_activation_summits WHERE multi_activation_id = ? ORDER BY sort_order");
    $stmt->execute([$source_multi_id]);
    $source_order = array_map('intval', array_column($stmt->fetchAll(), 'summit_id'));
    if (count($source_order) < 2) return null;

    $unique_ids = array_values(array_unique($source_order));
    $ph = implode(',', array_fill(0, count($unique_ids), '?'));
    $stmt = $db->prepare("SELECT * FROM summits WHERE id IN ($ph) AND planning_group_id = ?");
    $stmt->execute(array_merge($unique_ids, [$source_group_id]));
    $source_summits = [];
    foreach ($stmt->fetchAll() as $r) $source_summits[(int)$r['id']] = $r;

    // Source summit id -> new summit row in the target dashboard
    $id_map = [];
    $new_rows = [];
    foreach ($unique_ids as $sid) {
        if (!isset($source_summits[$sid])) continue;
        $s = $source_summits[$sid];

        $chk = $db->prepare("SELECT * FROM summits WHERE sota_ref = ? AND planning_group_id = ?");
        $chk->execute([$s['sota_ref'], $target_group_id]);
        $existing = $chk->fetch();
        if ($existing) {
            $id_map[$sid] = (int)$existing['id'];
            $new_rows[$sid] = $existing;
            continue;
        }

        $db->prepare("
            INSERT INTO summits
            (planning_group_id, source_group_id, uses_shared_data, sota_ref, name, region, points, bonus_points,
             elevation_m, elevation_ft, latitude, longitude, nominated_date, sotlas_link, status,
             trail_link, hike_distance_mi, hike_elevation_gain_ft, difficulty,
             trailhead_lat, trailhead_lng)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'nominated', ?, ?, ?, ?, ?, ?)
        ")->execute([
            $target_group_id, $source_group_id,
            $s['sota_ref'], $s['name'], $s['region'], $s['points'], $s['bonus_points'] ?? 0,
            $s['elevation_m'], $s['elevation_ft'], $s['latitude'], $s['longitude'], $s['sotlas_link'],
            $s['trail_link'], $s['hike_distance_mi'], $s['hike_elevation_gain_ft'], $s['difficulty'],
            $s['trailhead_lat'], $s['trailhead_lng'],
        ]);
        $new_id = (int)$db->lastInsertId();
        $id_map[$sid] = $new_id;
        $new_rows[$sid] = $s;

        _import_copy_gpx($db, $sid, $source_group_id, $new_id, $target_group_id);

        // Same rule as nominate.php: a GPX track plus a trailhead means "researched"
        $db->prepare("
            UPDATE summits s
            JOIN gpx_tracks g ON g.summit_id = s.id
            SET s.status = 'researched'
            WHERE s.id = ? AND s.status = 'nominated'
              AND s.trailhead_lat IS NOT NULL AND s.trailhead_lat != 0
              AND s.trailhead_lng IS NOT NULL AND s.trailhead_lng != 0
        ")->execute([$new_id]);
    }

    // Keep the route's order, including repeat visits to the same summit
    $new_order = [];
    foreach ($source_order as $sid) {
        if (isset($id_map[$sid])) $new_order[] = $sid;
    }
    if (count($new_order) < 2) return null;

    // Drive legs from the target dashboard's selected starting address
    $origin = null;
    $stmt = $db->prepare("
        SELECT a.* FROM app_settings st
        JOIN addresses a ON a.id = st.setting_value AND a.planning_group_id = ?
        WHERE st.setting_key = ?
    ");
    $stmt->execute([$target_group_id, 'selected_address_group_' . $target_group_id]);
    $addr = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($addr) {
        $geo = addressGeo($db, $addr);
        if ($geo) $origin = $geo['lat'] . ',' . $geo['lng'];
    }

    $coord = function ($row) {
        $lat = $row['trailhead_lat'] ?? $row['latitude'];
        $lng = $row['trailhead_lng'] ?? $row['longitude'];
        return ($lat !== null && $lng !== null) ? $lat . ',' . $lng : null;
    };

    $leg_times = [];
    $total_drive = 0;
    $prev = $origin;
    foreach ($new_order as $i => $sid) {
        $to = $coord($new_rows[$sid]);
        $t = ($prev && $to) ? calculateDriveTimeBetween($prev, $to) : null;
        $leg_times[$i] = $t;
        $total_drive += (int)$t;
        $prev = $to;
    }
    if ($origin && $prev) $total_drive += (int)calculateDriveTimeBetween($prev, $origin);

    $activation_min = (int)$src['activation_time_min'];
    $hike_min = (int)$src['total_hike_min'];
    $total_time = $hike_min + $total_drive + $activation_min * count($new_order);

    $db->prepare("
        INSERT INTO multi_activations
            (planning_group_id, name, activation_time_min, created_by,
             total_points, total_hike_min, total_drive_min, total_time_min, total_dist_mi, total_elev_ft)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $target_group_id, $src['name'], $activation_min, $callsign,
        $src['total_points'], $hike_min, $total_drive, $total_time, $src['total_dist_mi'], $src['total_elev_ft'],
    ]);
    $new_multi_id = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO multi_activation_summits (multi_activation_id, summit_id, sort_order, leg_drive_time_min) VALUES (?, ?, ?, ?)");
    foreach ($new_order as $i => $sid) {
        $ins->execute([$new_multi_id, $id_map[$sid], $i, $leg_times[$i]]);
    }

    return $new_multi_id;
}

// Copy a summit's GPX track row to the new dashboard. Tracks from the global
// library all point at one shared file; anything else gets its own file copy,
// matching how nominate.php handles shared research.
function _import_copy_gpx(PDO $db, int $src_summit_id, int $src_group_id, int $new_summit_id, int $new_group_id): void {
    $stmt = $db->prepare("SELECT * FROM gpx_tracks WHERE summit_id = ? AND planning_group_id = ? ORDER BY use_for_hike_time DESC, id DESC LIMIT 1");
    $stmt->execute([$src_summit_id, $src_group_id]);
    $g = $stmt->fetch();
    if (!$g || empty($g['file_path']) || !file_exists($g['file_path'])) return;

    unset($g['id']);
    $g['summit_id'] = $new_summit_id;
    $g['planning_group_id'] = $new_group_id;

    if (empty($g['from_global_library'])) {
        $upload_dir = __DIR__ . '/gpx_files';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $new_filename = $new_summit_id . '_grp' . $new_group_id . '_' . time() . '.gpx';
        $new_filepath = $upload_dir . '/' . $new_filename;
        if (!copy($g['file_path'], $new_filepath)) return;
        $g['filename'] = $new_filename;
        $g['file_path'] = $new_filepath;
    }

    $cols = array_keys($g);
    $sql = "INSERT IGNORE INTO gpx_tracks (`" . implode('`, `', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $db->prepare($sql)->execute(array_values($g));
}
