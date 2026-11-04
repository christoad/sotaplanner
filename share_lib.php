<?php
// Public summit pages (summit_share.php, reached as /s/<designator>-<name>).
//
// Every SOTA summit has one; nobody has to "share" it first. The page is built from public
// SOTA summit data plus the same community research nominate.php already copies between
// dashboards (trail link, hike stats, difficulty, starting point, cell service, GPX route),
// and never shows anything about the dashboard or person that research came from.
//
// Only the designator matters when resolving a link: /s/w6-ct-012 and
// /s/w6-ct-012-waterman-mtn are the same page. The name part is cosmetic.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sota_cache_helper.php';
require_once __DIR__ . '/multi_import_lib.php';
require_once __DIR__ . '/nominate_lib.php';

function shareMakeSlug(string $ref, string $name): string {
    // Common words are abbreviated to keep links short and readable
    static $abbr = [
        'mountain' => 'mtn', 'mountains' => 'mtns', 'mount' => 'mt', 'peak' => 'pk', 'point' => 'pt',
        'ridge' => 'rdg', 'canyon' => 'cyn', 'junction' => 'jct', 'saint' => 'st', 'fort' => 'ft',
        'benchmark' => 'bm', 'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w',
    ];
    $text = $name;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($t !== false && $t !== '') $text = $t;
    }
    $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_map(fn($w) => $abbr[$w] ?? $w, $words);
    $ref_part = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ref)), '-');
    $slug = trim($ref_part . '-' . implode('-', $words), '-');
    $slug = substr($slug, 0, 100);
    return trim($slug, '-') ?: 'summit';
}

/** "w6-ct-12-waterman-mtn" or "W6/CT-012" -> "W6/CT-012", or null if it does not start with a designator. */
function shareParseRef(string $slug): ?string {
    $slug = strtolower(trim($slug));
    if (preg_match('~^([a-z0-9]{1,6})[-/]([a-z0-9]{1,6})-(\d{1,4})(?:-|$)~', $slug, $m)) {
        return strtoupper($m[1]) . '/' . strtoupper($m[2]) . '-' . str_pad($m[3], 3, '0', STR_PAD_LEFT);
    }
    return null;
}

/** The public page's data for a link, or null if the summit does not exist. */
function sharePublicSummit(PDO $db, string $slug): ?array {
    $ref = shareParseRef($slug);
    return $ref ? sharePublicSummitByRef($db, $ref) : null;
}

/**
 * Public summit data for a SOTA reference: SOTA's summit list for the basics, plus the best
 * available community research (chosen the same way nominate.php picks a source summit).
 * 'id' is the dashboard summit row that research came from (used only to find its GPX track),
 * never shown. Returns null if SOTA has no such summit and nobody has it.
 */
function sharePublicSummitByRef(PDO $db, string $ref): ?array {
    $ref = strtoupper(trim($ref));
    $cache = get_sota_cache_summit($ref);

    $stmt = $db->prepare("SELECT * FROM summits WHERE sota_ref = ? ORDER BY id LIMIT 1");
    $stmt->execute([$ref]);
    $any = $stmt->fetch() ?: null;
    if (!$cache && !$any) return null;

    if ($cache) {
        $alt_ft = (int)$cache['altFt'];
        $s = [
            'name' => $cache['name'], 'points' => (int)$cache['points'], 'bonus_points' => (int)$cache['bonus'],
            'elevation_ft' => $alt_ft, 'elevation_m' => (int)round($alt_ft * 0.3048),
            'latitude' => $cache['lat'], 'longitude' => $cache['lon'],
            'region' => $any['region'] ?? '', 'activation_count' => $any['activation_count'] ?? null,
        ];
    } else {
        $s = [
            'name' => $any['name'], 'points' => (int)$any['points'], 'bonus_points' => (int)($any['bonus_points'] ?? 0),
            'elevation_ft' => $any['elevation_ft'], 'elevation_m' => $any['elevation_m'],
            'latitude' => $any['latitude'], 'longitude' => $any['longitude'],
            'region' => $any['region'] ?? '', 'activation_count' => $any['activation_count'] ?? null,
        ];
    }
    $s += [
        'sota_ref' => $ref, 'id' => null, 'difficulty' => null, 'trail_link' => null, 'cell_service' => null,
        'hike_distance_mi' => null, 'hike_elevation_gain_ft' => null,
        'trailhead_lat' => null, 'trailhead_lng' => null, 'gpx_opted_out' => 0,
    ];

    $stmt = $db->prepare("
        SELECT * FROM summits
        WHERE sota_ref = ? AND ((trail_link IS NOT NULL AND trail_link <> '') OR hike_distance_mi IS NOT NULL)
        ORDER BY (trail_link IS NOT NULL AND trail_link <> '') DESC, id LIMIT 1
    ");
    $stmt->execute([$ref]);
    if ($r = $stmt->fetch()) {
        foreach (['difficulty', 'trail_link', 'cell_service', 'hike_distance_mi', 'hike_elevation_gain_ft', 'trailhead_lat', 'trailhead_lng', 'gpx_opted_out'] as $k) {
            $s[$k] = $r[$k] ?? $s[$k];
        }
        $s['id'] = (int)$r['id'];
    }

    // Starting point from the community GPX library when no dashboard has set one
    if (empty($s['trailhead_lat'])) {
        $g = $db->prepare("SELECT trailhead_lat, trailhead_lon FROM global_gpx_tracks WHERE sota_ref = ?");
        $g->execute([$ref]);
        if (($gt = $g->fetch()) && !empty($gt['trailhead_lat'])) {
            $s['trailhead_lat'] = $gt['trailhead_lat'];
            $s['trailhead_lng'] = $gt['trailhead_lon'];
        }
    }

    $s['slug'] = shareMakeSlug($ref, $s['name']);
    return $s;
}

function shareUrl(string $slug): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'sotaplanner.com') . $dir . '/s/' . $slug;
}

/** A dashboard of this callsign that already contains the SOTA ref: [id, planning_group_id] or null. */
function shareFindExistingForUser(PDO $db, string $callsign, string $sota_ref): ?array {
    $stmt = $db->prepare("
        SELECT s.id, s.planning_group_id FROM summits s
        WHERE s.sota_ref = ? AND s.planning_group_id IN (
            SELECT pg.id FROM planning_groups pg
            LEFT JOIN planning_group_members pgm ON pgm.planning_group_id = pg.id
            WHERE pg.owner_callsign = ? OR pgm.callsign = ?
        ) LIMIT 1
    ");
    $stmt->execute([$sota_ref, $callsign, $callsign]);
    return $stmt->fetch() ?: null;
}

/**
 * Adds a summit to $target_group_id from its public page, the way nominate.php would:
 * community research and the library GPX route come along, status and history do not.
 * Travel time is calculated by summit_detail.php on first open.
 * Returns the summit id in the target dashboard, or null.
 */
function importSharedSummitIntoGroup(PDO $db, string $ref, int $target_group_id): ?int {
    $pub = sharePublicSummitByRef($db, $ref);
    if (!$pub) return null;

    $chk = $db->prepare("SELECT id FROM summits WHERE sota_ref = ? AND planning_group_id = ?");
    $chk->execute([$pub['sota_ref'], $target_group_id]);
    if ($existing = $chk->fetchColumn()) return (int)$existing;

    $source_group = null;
    if ($pub['id']) {
        $g = $db->prepare("SELECT planning_group_id FROM summits WHERE id = ?");
        $g->execute([$pub['id']]);
        $source_group = (int)$g->fetchColumn() ?: null;
    }

    $db->prepare("
        INSERT INTO summits
        (planning_group_id, source_group_id, uses_shared_data, sota_ref, name, region, points, bonus_points,
         elevation_m, elevation_ft, latitude, longitude, nominated_date, sotlas_link, status,
         trail_link, hike_distance_mi, hike_elevation_gain_ft, difficulty,
         trailhead_lat, trailhead_lng, cell_service)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'nominated', ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $target_group_id, $source_group, $source_group ? 1 : 0,
        $pub['sota_ref'], $pub['name'], $pub['region'], $pub['points'], $pub['bonus_points'],
        $pub['elevation_m'], $pub['elevation_ft'], $pub['latitude'], $pub['longitude'],
        'https://sotl.as/summits/' . $pub['sota_ref'],
        $pub['trail_link'], $pub['hike_distance_mi'], $pub['hike_elevation_gain_ft'], $pub['difficulty'],
        $pub['trailhead_lat'], $pub['trailhead_lng'], $pub['cell_service'],
    ]);
    $new_id = (int)$db->lastInsertId();

    if ($pub['activation_count'] !== null) {
        $db->prepare("UPDATE summits SET activation_count = ? WHERE id = ?")->execute([(int)$pub['activation_count'], $new_id]);
    }
    if ($pub['id'] && $source_group) {
        _import_copy_gpx($db, (int)$pub['id'], $source_group, $new_id, $target_group_id);
    }
    _link_global_gpx($db, $new_id, $target_group_id, $pub['sota_ref']);
    _maybe_set_researched($db, $new_id);

    return $new_id;
}
