<?php
// Advisory Notices: community warnings and tips pinned to a summit (keyed by SOTA reference, so every
// dashboard sees them). Any signed-in user can post one; only the poster or God Mode can remove it.
// Removal is a soft delete (removed_at / removed_by) so a bad removal can be undone in the database.
// Table: summit_advisories (created by db_migrate.php).

require_once __DIR__ . '/share_lib.php'; // shareMakeSlug(), get_sota_cache_summit()

const ADVISORY_MAX_LEN = 400;

/** Levels in display order (most serious first). Keys are what the database stores. */
function advisory_levels() {
    return [
        'danger'  => ['label' => 'Do not attempt', 'hint' => 'No access, closed, private property, unsafe'],
        'caution' => ['label' => 'Caution',        'hint' => 'Hazards to prepare for'],
        'tip'     => ['label' => 'Tip',            'hint' => 'Advice that makes the hike go better'],
    ];
}

/** Active notices for a summit, most serious first, newest first within a level. Never throws. */
function advisory_list($db, $sota_ref) {
    if ($sota_ref === null || $sota_ref === '') return [];
    try {
        $st = $db->prepare("SELECT id, level, message, created_by, created_at FROM summit_advisories
                            WHERE sota_ref = ? AND removed_at IS NULL
                            ORDER BY FIELD(level, 'danger', 'caution', 'tip'), created_at DESC");
        $st->execute([$sota_ref]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return []; // table not created yet: show nothing rather than break the page
    }
}

/** Save a new notice. Returns the new id, or an error string. */
function advisory_add($db, $sota_ref, $level, $message, $callsign) {
    $message = trim(preg_replace('/\r\n?/', "\n", (string)$message));
    if ($sota_ref === null || $sota_ref === '') return 'This summit has no SOTA reference.';
    if (!isset(advisory_levels()[$level]))       return 'Pick a level for the notice.';
    if ($message === '')                          return 'Type a message for the notice.';
    if (mb_strlen($message) > ADVISORY_MAX_LEN)   return 'Keep the notice under ' . ADVISORY_MAX_LEN . ' characters.';
    $st = $db->prepare("INSERT INTO summit_advisories (sota_ref, level, message, created_by, created_at)
                        VALUES (?, ?, ?, ?, NOW())");
    $st->execute([$sota_ref, $level, $message, $callsign]);
    return (int)$db->lastInsertId();
}

/** Inline SVG icon per level (no emoji, Alpine Precision line style). */
function advisory_icon($level) {
    $icons = [
        'danger'  => '<polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="7" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'caution' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'tip'     => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
         . ($icons[$level] ?? $icons['tip']) . '</svg>';
}

/**
 * Where a summit designator in a notice should go for this viewer: their own summit detail page
 * when the summit is on one of their dashboards (the current one first), otherwise the public
 * /s/ page, the same page a shared summit link shows. Null when SOTA has no such summit.
 */
function advisory_summit_href($db, $ref, $callsign) {
    static $memo = [];
    $key = $ref . '|' . $callsign;
    if (array_key_exists($key, $memo)) return $memo[$key];
    $href = null;
    try {
        if ($callsign) {
            $gid = (int)($_SESSION['current_planning_group_id'] ?? 0);
            $st = $db->prepare("SELECT s.id, s.planning_group_id FROM summits s
                                WHERE s.sota_ref = ? AND s.planning_group_id IN (
                                    SELECT pg.id FROM planning_groups pg
                                    LEFT JOIN planning_group_members pgm ON pgm.planning_group_id = pg.id
                                    WHERE pg.owner_callsign = ? OR pgm.callsign = ?)
                                ORDER BY (s.planning_group_id = ?) DESC, s.id LIMIT 1");
            $st->execute([$ref, $callsign, $callsign, $gid]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $href = 'summit_detail.php?id=' . (int)$row['id'];
                if ((int)$row['planning_group_id'] !== $gid) $href .= '&group=' . (int)$row['planning_group_id'];
            }
        }
        if ($href === null) {
            $name = null;
            if ($c = get_sota_cache_summit($ref)) {
                $name = $c['name'];
            } else {
                $st = $db->prepare("SELECT name FROM summits WHERE sota_ref = ? LIMIT 1");
                $st->execute([$ref]);
                $name = $st->fetchColumn() ?: null;
            }
            if ($name !== null) $href = 's/' . shareMakeSlug($ref, $name);
        }
    } catch (Throwable $e) {
        $href = null; // a lookup problem just leaves the designator as plain text
    }
    return $memo[$key] = $href;
}

/** Degrees, minutes, seconds and a hemisphere letter to signed decimal degrees. */
function advisory_dms($deg, $min, $sec, $hemi) {
    $v = (float)$deg + (float)$min / 60 + (float)$sec / 3600;
    return in_array(strtoupper($hemi), ['S', 'W'], true) ? -$v : $v;
}

/**
 * A notice's text as safe HTML: summit designators (W6/CT-012) link to the summit, and GPS
 * coordinates link to Google Maps. Coordinates are recognised as decimal pairs with at least
 * three decimals (34.2245, -117.1234), decimal with letters (N34.2245 W117.1234 or 34.2245N
 * 117.1234W) and degrees/minutes/seconds (34°13'28"N 117°7'24"W). Web addresses (https://...
 * or www....) open in a new tab. Everything else is escaped.
 */
function advisory_message_html($db, $message, $callsign = null) {
    $message = (string)$message;
    $deg = '\\s?[°º]?\\s?';
    $dmsPart = '(\\d{1,3})\\s?[°º]\\s?(\\d{1,2}(?:\\.\\d+)?)\\s?[\'′’]\\s?(?:(\\d{1,2}(?:\\.\\d+)?)\\s?(?:["″”]|\'\'|′′)\\s?)?';
    $re = '~'
        . '(?<![\\w/])(?<ref>[a-z0-9]{1,4}/[a-z]{2}-\\d{1,4})(?![\\w-])'
        . '|(?<dms>' . $dmsPart . '([NS])[,;\\s]+' . $dmsPart . '([EW]))(?![a-z])'
        . '|(?<![\\w.])(?<hpre>([NS])\\s?(\\d{1,2}\\.\\d+)' . $deg . '[,;\\s]+([EW])\\s?(\\d{1,3}\\.\\d+)°?)(?!\\w|\\.\\d)'
        . '|(?<![\\w.])(?<hpost>(\\d{1,2}\\.\\d+)' . $deg . '([NS])[,;\\s]+(\\d{1,3}\\.\\d+)' . $deg . '([EW]))(?![a-z])'
        . '|(?<![\\w.\\-])(?<dec>(-?\\d{1,2}\\.\\d{3,})(?:\\s*,\\s*|\\s+)(-?\\d{1,3}\\.\\d{3,}))(?!\\w|\\.\\d)'
        . '|(?<![\\w@/])(?<url>(?:https?://|www\\.)[^\\s<>"]+)'
        . '~iu';
    if (!preg_match_all($re, $message, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
        return nl2br(htmlspecialchars($message));
    }
    $out = '';
    $pos = 0;
    foreach ($all as $m) {
        [$text, $at] = $m[0];
        $link = null;
        if (isset($m['url'][0])) {
            // Leave sentence punctuation and an unmatched closing bracket outside the link
            while ($text !== '' && (strpbrk(substr($text, -1), '.,;:!?\'') !== false
                   || (substr($text, -1) === ')' && substr_count($text, '(') < substr_count($text, ')')))) {
                $text = substr($text, 0, -1);
            }
            $href = preg_match('~^https?://~i', $text) ? $text : 'https://' . $text;
            if (strlen($text) > 4) $link = '<a class="adv-link" href="' . htmlspecialchars($href) . '" target="_blank" rel="noopener noreferrer">';
        } elseif (isset($m['ref'][0])) {
            $ref = shareParseRef($m['ref'][0]); // normalises W6/CT-12 to W6/CT-012
            $href = $ref ? advisory_summit_href($db, $ref, $callsign) : null;
            if ($href) $link = '<a class="adv-link" href="' . htmlspecialchars($href) . '" title="Open ' . htmlspecialchars($ref) . '">';
        } else {
            $lat = $lng = null;
            if (isset($m['dms'][0])) {
                $lat = advisory_dms($m[3][0], $m[4][0], $m[5][0] ?? 0, $m[6][0]);
                $lng = advisory_dms($m[7][0], $m[8][0], $m[9][0] ?? 0, $m[10][0]);
            } elseif (isset($m['hpre'][0])) {
                $lat = (strtoupper($m[12][0]) === 'S' ? -1 : 1) * (float)$m[13][0];
                $lng = (strtoupper($m[14][0]) === 'W' ? -1 : 1) * (float)$m[15][0];
            } elseif (isset($m['hpost'][0])) {
                $lat = (strtoupper($m[18][0]) === 'S' ? -1 : 1) * (float)$m[17][0];
                $lng = (strtoupper($m[20][0]) === 'W' ? -1 : 1) * (float)$m[19][0];
            } elseif (isset($m['dec'][0])) {
                $lat = (float)$m[22][0];
                $lng = (float)$m[23][0];
            }
            if ($lat !== null && abs($lat) <= 90 && abs($lng) <= 180) {
                $q = round($lat, 6) . ',' . round($lng, 6);
                $link = '<a class="adv-link" href="https://www.google.com/maps/search/?api=1&amp;query=' . $q
                      . '" target="_blank" rel="noopener" title="Open in Google Maps">';
            }
        }
        $out .= nl2br(htmlspecialchars(substr($message, $pos, $at - $pos)));
        $out .= $link ? $link . htmlspecialchars($text) . '</a>' : nl2br(htmlspecialchars($text));
        $pos = $at + strlen($text);
    }
    return $out . nl2br(htmlspecialchars(substr($message, $pos)));
}

/** CSS for the top-of-page banners. Needs the page's --red, --blue, --ink, --r-lg and --shadow-md variables. */
function advisory_banner_css() {
    return <<<CSS
    .adv-stack { display:flex; flex-direction:column; gap:0.75rem; margin-bottom:1.5rem; }
    .adv { display:flex; gap:1rem; align-items:flex-start; padding:1.15rem 1.35rem; border-radius:var(--r-lg);
           border:2px solid transparent; box-shadow:var(--shadow-md); animation:advIn 0.35s ease-out both; }
    .adv-icon { flex-shrink:0; width:40px; height:40px; display:flex; align-items:center; justify-content:center; }
    .adv-icon svg { width:36px; height:36px; }
    .adv-body { flex:1; min-width:0; }
    .adv-kicker { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.09em; opacity:0.9; margin-bottom:0.2rem; }
    .adv-msg { font-size:1.2rem; font-weight:700; line-height:1.35; overflow-wrap:anywhere; }
    .adv-msg a.adv-link { color:inherit; text-decoration:underline; text-decoration-thickness:2px; text-underline-offset:3px; }
    .adv-msg a.adv-link:hover { text-decoration-thickness:3px; opacity:0.9; }
    .adv-meta { margin-top:0.55rem; font-size:0.78rem; opacity:0.85; }
    .adv-meta .cs { font-family:var(--font-mono); font-weight:600; }
    .adv-danger  { background:var(--red);    border-color:oklch(40% 0.15 22);  color:#fff; }
    .adv-caution { background:oklch(80% 0.15 75); border-color:oklch(62% 0.14 58); color:var(--ink); }
    .adv-tip     { background:var(--blue);   border-color:oklch(42% 0.12 240); color:#fff; }
    @keyframes advIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
    @media (prefers-reduced-motion: reduce) { .adv { animation:none; } }
    @media (max-width:600px) { .adv { padding:1rem; gap:0.75rem; } .adv-icon, .adv-icon svg { width:28px; height:28px; } .adv-msg { font-size:1.05rem; } }
CSS;
}

/** Banner stack for the top of a page (read only, removal lives with the entry form). '' when there are none. */
function advisory_render_banners($advisories, $db = null, $callsign = null) {
    if (empty($advisories)) return '';
    $levels = advisory_levels();
    $out = '<div class="adv-stack" id="advisory-notices">';
    foreach ($advisories as $a) {
        $lvl = isset($levels[$a['level']]) ? $a['level'] : 'tip';
        $out .= '<div class="adv adv-' . $lvl . '" role="' . ($lvl === 'tip' ? 'note' : 'alert') . '">'
              . '<div class="adv-icon" aria-hidden="true">' . advisory_icon($lvl) . '</div>'
              . '<div class="adv-body">'
              . '<div class="adv-kicker">Advisory Notice &middot; ' . htmlspecialchars($levels[$lvl]['label']) . '</div>'
              . '<div class="adv-msg">' . ($db ? advisory_message_html($db, $a['message'], $callsign) : nl2br(htmlspecialchars($a['message']))) . '</div>'
              . '<div class="adv-meta">Posted by <span class="cs">' . htmlspecialchars($a['created_by']) . '</span> on '
              . date('M j, Y', strtotime($a['created_at'])) . '</div>'
              . '</div></div>';
    }
    return $out . '</div>';
}

/**
 * Change a notice's level and message (poster or God Mode only). Returns the row as it was before
 * the edit, or an error string.
 */
function advisory_update($db, $id, $sota_ref, $level, $message, $callsign, $is_god) {
    $message = trim(preg_replace('/\r\n?/', "\n", (string)$message));
    if (!isset(advisory_levels()[$level]))     return 'Pick a level for the notice.';
    if ($message === '')                        return 'Type a message for the notice.';
    if (mb_strlen($message) > ADVISORY_MAX_LEN) return 'Keep the notice under ' . ADVISORY_MAX_LEN . ' characters.';
    $st = $db->prepare("SELECT * FROM summit_advisories WHERE id = ? AND sota_ref = ? AND removed_at IS NULL");
    $st->execute([(int)$id, $sota_ref]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'That notice was not found. It may have been removed.';
    if (!$is_god && strcasecmp($row['created_by'], (string)$callsign) !== 0) return 'Only the person who posted this notice can edit it.';
    $db->prepare("UPDATE summit_advisories SET level = ?, message = ? WHERE id = ?")->execute([$level, $message, $row['id']]);
    return $row;
}

/** Remove a notice (poster or God Mode only). Returns the removed row, or null if not allowed / not found. */
function advisory_remove($db, $id, $sota_ref, $callsign, $is_god) {
    $st = $db->prepare("SELECT * FROM summit_advisories WHERE id = ? AND sota_ref = ? AND removed_at IS NULL");
    $st->execute([(int)$id, $sota_ref]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (!$is_god && strcasecmp($row['created_by'], (string)$callsign) !== 0) return null;
    $db->prepare("UPDATE summit_advisories SET removed_at = NOW(), removed_by = ? WHERE id = ?")
       ->execute([$callsign, $row['id']]);
    return $row;
}
