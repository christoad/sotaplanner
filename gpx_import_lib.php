<?php
/**
 * gpx_import_lib.php — shared helpers for SOTAmaps GPX import.
 *
 * Requires config.php (for analyze_gpx_track, haversine_distance).
 * Included by both admin_batch_gpx.php and batch_gpx_cron.php.
 */

function _batch_track_distance(array $points): float {
    $total = 0.0;
    $prev  = null;
    foreach ($points as $pt) {
        if ($prev !== null) {
            $total += haversine_distance(
                floatval($prev['latitude']), floatval($prev['longitude']),
                floatval($pt['latitude']),  floatval($pt['longitude'])
            );
        }
        $prev = $pt;
    }
    return $total;
}

function _batch_build_gpx(array $points, string $title, string $callsign): string {
    $safe_title    = htmlspecialchars($title,    ENT_XML1);
    $safe_callsign = htmlspecialchars($callsign, ENT_XML1);
    $trkpts = '';
    foreach ($points as $pt) {
        $lat = floatval($pt['latitude']);
        $lon = floatval($pt['longitude']);
        $ele = floatval($pt['altitude']);
        if ($ele > 0 && $ele < 9) $ele *= 1000;
        $trkpts .= sprintf(
            "    <trkpt lat=\"%.7f\" lon=\"%.7f\"><ele>%.1f</ele></trkpt>\n",
            $lat, $lon, $ele
        );
    }
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="SOTAPlanner-BatchImport"
     xmlns="http://www.topografix.com/GPX/1/1">
  <metadata><name>{$safe_title}</name>
    <desc>Imported from SOTA Mapping Project. Submitted by {$safe_callsign}</desc>
  </metadata>
  <trk><name>{$safe_title}</name><trkseg>
{$trkpts}  </trkseg></trk>
</gpx>
XML;
}

/**
 * Fetch the best SOTAmaps track for $sota_ref, save to disk, insert into the
 * global library, backfill any existing summit rows without a track, and record
 * the check result in global_gpx_checked.
 *
 * Returns an array with at minimum a 'status' key:
 *   imported — track saved; also has title, callsign, track_count, points, dist_mi, gain_ft, backfilled
 *   skip     — already in global_gpx_tracks, nothing done
 *   none     — SOTAmaps has no tracks for this summit (recorded in global_gpx_checked)
 *   error    — something failed; also has 'msg'
 */
function import_sotamaps_track(PDO $db, string $sota_ref): array {
    $sota_ref = strtoupper(trim($sota_ref));

    // Already imported — nothing to do
    $chk = $db->prepare("SELECT id FROM global_gpx_tracks WHERE sota_ref = ?");
    $chk->execute([$sota_ref]);
    if ($chk->fetch()) {
        return ['status' => 'skip', 'msg' => 'Already in global library'];
    }

    // Fetch track list from SOTA Mapping Project API
    $parts   = explode('/', $sota_ref, 2);
    $api_url = 'https://api-db.sota.org.uk/smp/gpx/summit/'
             . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1] ?? '');

    $ctx = stream_context_create(['http' => [
        'timeout'       => 20,
        'ignore_errors' => true,
        'header'        => "User-Agent: SOTAPlanner-BatchImport/1.0\r\n",
    ]]);
    $raw = @file_get_contents($api_url, false, $ctx);

    if ($raw === false || $raw === '') {
        return ['status' => 'error', 'msg' => 'API unreachable'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || count($data) === 0) {
        _record_gpx_checked($db, $sota_ref, 0);
        return ['status' => 'none', 'msg' => 'No tracks on SOTAmaps'];
    }

    // Pick the shortest-distance track (best for planning; avoids long wandering routes)
    $best = null; $best_dist = PHP_FLOAT_MAX;
    foreach ($data as $t) {
        $pts = $t['points'] ?? [];
        if (count($pts) < 2) continue;
        $dist = _batch_track_distance($pts);
        if ($dist < $best_dist) { $best = $t; $best_dist = $dist; }
    }

    if (!$best) {
        _record_gpx_checked($db, $sota_ref, 0);
        return ['status' => 'error', 'msg' => 'No usable tracks found'];
    }

    $points = $best['points'] ?? [];
    if (count($points) < 2) {
        _record_gpx_checked($db, $sota_ref, 0);
        return ['status' => 'error', 'msg' => 'Best track has too few points (' . count($points) . ')'];
    }

    usort($points, fn($a, $b) => intval($a['pt_index']) - intval($b['pt_index']));

    $gpx_xml = _batch_build_gpx($points, $best['track_title'] ?? $sota_ref, $best['callsign'] ?? '');

    $upload_dir = __DIR__ . '/gpx_files/global';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $safe_ref = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sota_ref);
    $filename = $safe_ref . '_sotamaps_' . intval($best['hdr_id'] ?? time()) . '.gpx';
    $filepath = $upload_dir . '/' . $filename;

    if (file_put_contents($filepath, $gpx_xml) === false) {
        return ['status' => 'error', 'msg' => 'Could not write GPX file to disk'];
    }

    $gpx_stats = analyze_gpx_track($filepath, null);
    if (!$gpx_stats) {
        @unlink($filepath);
        return ['status' => 'error', 'msg' => 'GPX analysis failed'];
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO global_gpx_tracks (
                sota_ref, filename, file_path, source, source_callsign, source_track_title,
                total_distance, max_elevation, min_elevation, elevation_gain, elevation_loss,
                num_points, summit_lat, summit_lon, trailhead_lat, trailhead_lon, sotamaps_track_count
            ) VALUES (?, ?, ?, 'sotamaps', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sota_ref, $filename, $filepath,
            $best['callsign'] ?? '', $best['track_title'] ?? $sota_ref,
            $gpx_stats['total_distance'], $gpx_stats['max_elevation'], $gpx_stats['min_elevation'],
            $gpx_stats['elevation_gain'], $gpx_stats['elevation_loss'],
            $gpx_stats['num_points'], $gpx_stats['summit_lat'], $gpx_stats['summit_lon'],
            null, null, count($data),
        ]);

        // Retroactively backfill existing summit rows that have no GPX track
        $need = $db->prepare("
            SELECT s.id, s.planning_group_id
            FROM summits s
            WHERE s.sota_ref = ?
              AND NOT EXISTS (SELECT 1 FROM gpx_tracks g WHERE g.summit_id = s.id)
        ");
        $need->execute([$sota_ref]);
        $backfill = 0;
        $ins = $db->prepare("
            INSERT IGNORE INTO gpx_tracks (
                summit_id, planning_group_id, filename, file_path,
                total_time, hiking_time, activation_time, rest_break_time,
                total_distance, hiking_distance, max_elevation, min_elevation,
                elevation_gain, elevation_loss, avg_speed, hiking_speed,
                num_points, summit_lat, summit_lon, using_api,
                activation_zone_polygon, activation_zone_method,
                use_for_hike_time, use_for_elevation, from_global_library
            ) VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, NULL, 'none', 0, 1, 1)
        ");
        $upd = $db->prepare("
            UPDATE summits
            SET hike_distance_mi       = COALESCE(hike_distance_mi, ?),
                hike_elevation_gain_ft = COALESCE(hike_elevation_gain_ft, ?)
            WHERE id = ?
        ");
        $dist_mi = round($gpx_stats['total_distance'] * 2 * 0.621371, 2);
        $gain_ft = round($gpx_stats['elevation_gain'] * 3.28084);
        foreach ($need->fetchAll() as $s) {
            $ins->execute([
                $s['id'], $s['planning_group_id'], $filename, $filepath,
                $gpx_stats['total_distance'], $gpx_stats['total_distance'],
                $gpx_stats['max_elevation'], $gpx_stats['min_elevation'],
                $gpx_stats['elevation_gain'], $gpx_stats['elevation_loss'],
                $gpx_stats['num_points'], $gpx_stats['summit_lat'], $gpx_stats['summit_lon'],
            ]);
            $upd->execute([$dist_mi, $gain_ft, $s['id']]);
            $backfill++;
        }

        _record_gpx_checked($db, $sota_ref, count($data));

        return [
            'status'      => 'imported',
            'title'       => $best['track_title'] ?? $sota_ref,
            'callsign'    => $best['callsign'] ?? '',
            'track_count' => count($data),
            'points'      => count($points),
            'dist_mi'     => round($gpx_stats['total_distance'] * 0.621371, 2),
            'gain_ft'     => round($gpx_stats['elevation_gain'] * 3.28084),
            'backfilled'  => $backfill,
        ];

    } catch (Exception $e) {
        @unlink($filepath);
        return ['status' => 'error', 'msg' => 'DB error: ' . $e->getMessage()];
    }
}

function _record_gpx_checked(PDO $db, string $sota_ref, int $tracks_found): void {
    try {
        $db->prepare("
            INSERT INTO global_gpx_checked (sota_ref, last_checked, tracks_found)
            VALUES (?, NOW(), ?)
            ON DUPLICATE KEY UPDATE last_checked = NOW(), tracks_found = VALUES(tracks_found)
        ")->execute([$sota_ref, $tracks_found]);
    } catch (Exception $e) {
        // Table may not exist yet — migrate will create it
    }
}
