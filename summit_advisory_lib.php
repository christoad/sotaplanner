<?php
// Advisory Notices: community warnings and tips pinned to a summit (keyed by SOTA reference, so every
// dashboard sees them). Any signed-in user can post one; only the poster or God Mode can remove it.
// Removal is a soft delete (removed_at / removed_by) so a bad removal can be undone in the database.
// Table: summit_advisories (created by db_migrate.php).

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
