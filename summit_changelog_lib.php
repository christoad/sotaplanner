<?php
// Per-summit change log: who changed what on a summit page, and the old and new values.
// Table: summit_change_log (created by db_migrate_change_log.php). Every function is a quiet
// no-op if the table is missing, so a missing migration can never break the summit page.

// Summit columns that are recorded when they change, with the label shown to users.
// Computed values (hike times, travel time) are left out on purpose: they would only add noise.
function summit_log_fields() {
    return [
        'status'                 => 'Status',
        'difficulty'             => 'Difficulty',
        'cell_service'           => 'Cell service',
        'trail_link'             => 'Trail link',
        'hike_distance_mi'       => 'Hike distance (mi, round trip)',
        'hike_elevation_gain_ft' => 'Elevation gain (ft)',
        'last_activated_date'    => 'Last activated',
        'activated_by'           => 'Activated by',
    ];
}

function summit_log_norm($v) {
    if ($v === null || $v === '') return '';
    if (is_numeric($v)) return rtrim(rtrim(number_format((float)$v, 5, '.', ''), '0'), '.');
    return trim((string)$v);
}

function summit_log_trailhead_text($row) {
    $lat = $row['trailhead_lat'] ?? null; $lng = $row['trailhead_lng'] ?? null;
    if ($lat === null || $lng === null || $lat === '' || $lng === '' || (float)$lat == 0.0) return '';
    return number_format((float)$lat, 5, '.', '') . ', ' . number_format((float)$lng, 5, '.', '');
}

function summit_log_row($db, $summit_id) {
    $st = $db->prepare("SELECT * FROM summits WHERE id = ?");
    $st->execute([$summit_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Record one entry. Safe to call from anywhere.
function summit_log_event($db, $summit_id, $label, $old, $new, $callsign = null, $sota_ref = null, $group_id = null) {
    try {
        $callsign = $callsign ?? ($_SESSION['sota_callsign'] ?? '?');
        if ($sota_ref === null) {
            $s = $db->prepare("SELECT sota_ref, planning_group_id FROM summits WHERE id = ?");
            $s->execute([$summit_id]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $sota_ref = $r['sota_ref'] ?? '';
            $group_id = $group_id ?? ($r['planning_group_id'] ?? null);
        }
        $db->prepare("INSERT INTO summit_change_log (summit_id, sota_ref, planning_group_id, callsign, field_label, old_value, new_value)
                      VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$summit_id, $sota_ref, $group_id, substr((string)$callsign, 0, 20), substr($label, 0, 80),
                      $old === null ? null : mb_substr((string)$old, 0, 1000),
                      $new === null ? null : mb_substr((string)$new, 0, 1000)]);
    } catch (Throwable $e) { /* table not migrated yet */ }
}

// Call at the start of a POST request. When the request ends (including after any redirect and
// exit), the summit row is compared with how it looked at the start and every difference is logged.
function summit_log_watch($db, $summit_id) {
    try {
        $before = summit_log_row($db, $summit_id);
        if (!$before) return;
        $callsign = $_SESSION['sota_callsign'] ?? '?';
        register_shutdown_function(function () use ($db, $summit_id, $before, $callsign) {
            try {
                $after = summit_log_row($db, $summit_id);
                if (!$after) return; // summit was removed
                $ref = $after['sota_ref'] ?? '';
                $gid = $after['planning_group_id'] ?? null;
                foreach (summit_log_fields() as $col => $label) {
                    $o = summit_log_norm($before[$col] ?? null);
                    $n = summit_log_norm($after[$col] ?? null);
                    if ($o !== $n) summit_log_event($db, $summit_id, $label, $o, $n, $callsign, $ref, $gid);
                }
                $o = summit_log_trailhead_text($before);
                $n = summit_log_trailhead_text($after);
                if ($o !== $n) summit_log_event($db, $summit_id, 'Starting point', $o, $n, $callsign, $ref, $gid);
            } catch (Throwable $e) { /* never break the page */ }
        });
    } catch (Throwable $e) { /* table missing etc. */ }
}

function summit_log_fetch($db, $summit_id, $limit = 100) {
    try {
        $st = $db->prepare("SELECT * FROM summit_change_log WHERE summit_id = ? ORDER BY id DESC LIMIT " . (int)$limit);
        $st->execute([$summit_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
}
