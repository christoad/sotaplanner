<?php
require_once 'config.php';
session_start();
requireLogin();

$callsign      = $_SESSION['sota_callsign'] ?? '';
$real_callsign = $_SESSION['_god_mode_real_callsign'] ?? '';

// Only KI6CR may access (even while impersonating)
if ($callsign !== 'KI6CR' && $real_callsign !== 'KI6CR') {
    header('Location: index.php');
    exit;
}

$db      = getDbConnection();
$message = '';
$error   = '';

// ── POST HANDLERS ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Exit impersonation
    if (isset($_POST['exit_impersonate'])) {
        $_SESSION['sota_callsign'] = 'KI6CR';
        unset($_SESSION['_god_mode_real_callsign']);
        header('Location: god_mode.php');
        exit;
    }

    // Impersonate a callsign
    if (isset($_POST['impersonate'])) {
        $target = strtoupper(trim($_POST['target_callsign'] ?? ''));
        if ($target && $target !== 'KI6CR' && preg_match('/^[A-Z0-9]{3,10}$/', $target)) {
            $_SESSION['_god_mode_real_callsign'] = 'KI6CR';
            $_SESSION['sota_callsign']           = $target;
            $stmt = $db->prepare("
                SELECT pg.id FROM planning_groups pg
                LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
                WHERE pg.owner_callsign = ? OR pgm.callsign = ?
                ORDER BY pg.id ASC LIMIT 1
            ");
            $stmt->execute([$target, $target]);
            $gid = $stmt->fetchColumn();
            if ($gid) $_SESSION['current_planning_group_id'] = $gid;
            else       unset($_SESSION['current_planning_group_id']);
            header('Location: index.php');
            exit;
        } else {
            $error = "Invalid callsign.";
        }
    }

    // Post sitewide banner
    if (isset($_POST['post_banner'])) {
        $text = trim($_POST['banner_text'] ?? '');
        $type = in_array($_POST['banner_type'] ?? '', ['info', 'success', 'error']) ? $_POST['banner_type'] : 'info';
        if ($text) {
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('sitewide_banner', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
               ->execute([json_encode(['text' => $text, 'type' => $type])]);
            $message = "Banner posted.";
        } else {
            $error = "Banner text cannot be empty.";
        }
    }

    // Clear banner
    if (isset($_POST['clear_banner'])) {
        $db->prepare("DELETE FROM app_settings WHERE setting_key = 'sitewide_banner'")->execute();
        $message = "Banner cleared.";
    }

    // Delete planning group (cascade)
    if (isset($_POST['delete_group'])) {
        $gid = (int)$_POST['group_id'];
        if ($gid && $_POST['confirm_delete'] === 'yes') {
            $stmt = $db->prepare("SELECT id FROM summits WHERE planning_group_id = ?");
            $stmt->execute([$gid]);
            $sids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($sids)) {
                $ph = implode(',', array_fill(0, count($sids), '?'));
                $db->prepare("DELETE FROM summit_notes WHERE summit_id IN ($ph)")->execute($sids);
                $db->prepare("DELETE FROM gpx_tracks WHERE summit_id IN ($ph)")->execute($sids);
            }
            $db->prepare("DELETE FROM activations WHERE planning_group_id = ?")->execute([$gid]);
            $db->prepare("DELETE FROM addresses WHERE planning_group_id = ?")->execute([$gid]);
            $db->prepare("DELETE FROM app_settings WHERE setting_key = ?")->execute(["selected_address_group_$gid"]);
            $db->prepare("DELETE FROM summits WHERE planning_group_id = ?")->execute([$gid]);
            $db->prepare("DELETE FROM planning_group_members WHERE planning_group_id = ?")->execute([$gid]);
            $db->prepare("DELETE FROM planning_groups WHERE id = ?")->execute([$gid]);
            $message = "Group deleted.";
        }
    }

    // Remove user from all non-owned groups
    if (isset($_POST['remove_user'])) {
        $target_cs = strtoupper(trim($_POST['target_callsign'] ?? ''));
        if ($target_cs && $target_cs !== 'KI6CR') {
            $db->prepare("DELETE FROM planning_group_members WHERE callsign = ? AND role != 'owner'")->execute([$target_cs]);
            $message = "Removed $target_cs from all member groups.";
        } else {
            $error = "Cannot remove KI6CR.";
        }
    }

    // Add member to a group
    if (isset($_POST['add_member_to_group'])) {
        $gid    = (int)$_POST['group_id'];
        $new_cs = strtoupper(trim($_POST['new_callsign'] ?? ''));
        if ($gid && $new_cs && preg_match('/^[A-Z0-9]{3,10}$/', $new_cs)) {
            $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role, invited_by) VALUES (?, ?, 'member', 'KI6CR')")
               ->execute([$gid, $new_cs]);
            $message = "Added $new_cs to group.";
        } else {
            $error = "Invalid callsign or group.";
        }
    }

    // Transfer ownership
    if (isset($_POST['transfer_ownership'])) {
        $gid       = (int)$_POST['group_id'];
        $new_owner = strtoupper(trim($_POST['new_owner'] ?? ''));
        if ($gid && $new_owner && preg_match('/^[A-Z0-9]{3,10}$/', $new_owner)) {
            $stmt = $db->prepare("SELECT owner_callsign FROM planning_groups WHERE id = ?");
            $stmt->execute([$gid]);
            $old_owner = $stmt->fetchColumn();
            $db->prepare("UPDATE planning_groups SET owner_callsign = ? WHERE id = ?")->execute([$new_owner, $gid]);
            if ($old_owner) $db->prepare("UPDATE planning_group_members SET role = 'member' WHERE planning_group_id = ? AND callsign = ?")->execute([$gid, $old_owner]);
            $db->prepare("INSERT INTO planning_group_members (planning_group_id, callsign, role) VALUES (?, ?, 'owner') ON DUPLICATE KEY UPDATE role = 'owner'")->execute([$gid, $new_owner]);
            $message = "Ownership transferred to $new_owner.";
        } else {
            $error = "Invalid callsign or group.";
        }
    }

    // Clear drive times for a group
    if (isset($_POST['clear_drive_times'])) {
        $gid = (int)$_POST['group_id'];
        if ($gid) {
            $db->prepare("UPDATE summits SET drive_time_min = NULL WHERE planning_group_id = ?")->execute([$gid]);
            $message = "Drive times cleared — they'll recalculate on next visit.";
        }
    }

    // Delete orphaned GPX files
    if (isset($_POST['delete_orphaned_gpx'])) {
        $files   = $_POST['orphaned_files'] ?? [];
        $deleted = 0;
        foreach ($files as $f) {
            $path = __DIR__ . '/gpx_files/' . basename($f);
            if (file_exists($path)) { unlink($path); $deleted++; }
        }
        $message = "Deleted $deleted orphaned GPX file(s).";
    }
}

// ── DATA ─────────────────────────────────────────────────────────────────────

$stats = [
    'users'           => $db->query("SELECT COUNT(DISTINCT callsign) FROM (SELECT callsign FROM planning_group_members UNION SELECT owner_callsign FROM planning_groups) u")->fetchColumn(),
    'groups'          => $db->query("SELECT COUNT(*) FROM planning_groups")->fetchColumn(),
    'summits'         => $db->query("SELECT COUNT(*) FROM summits")->fetchColumn(),
    'activations'     => $db->query("SELECT COUNT(*) FROM activations")->fetchColumn(),
    'gpx_tracks'      => $db->query("SELECT COUNT(*) FROM gpx_tracks")->fetchColumn(),
    'global_gpx'      => $db->query("SELECT COUNT(*) FROM global_gpx_tracks")->fetchColumn(),
];

$banner_raw     = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sitewide_banner'")->fetchColumn();
$current_banner = $banner_raw ? json_decode($banner_raw, true) : null;

$groups = $db->query("
    SELECT pg.*,
           COUNT(DISTINCT pgm.callsign) as member_count,
           COUNT(DISTINCT s.id)         as summit_count,
           COUNT(DISTINCT addr.id)      as address_count,
           SUM(CASE WHEN s.drive_time_min IS NULL AND s.trailhead_lat IS NOT NULL THEN 1 ELSE 0 END) as missing_drive
    FROM planning_groups pg
    LEFT JOIN planning_group_members pgm ON pgm.planning_group_id = pg.id
    LEFT JOIN summits s    ON s.planning_group_id    = pg.id
    LEFT JOIN addresses addr ON addr.planning_group_id = pg.id
    GROUP BY pg.id
    ORDER BY pg.created_at DESC
")->fetchAll();

$gm_map = [];
foreach ($db->query("SELECT planning_group_id, callsign, role FROM planning_group_members ORDER BY role DESC, callsign")->fetchAll() as $r) {
    $gm_map[$r['planning_group_id']][] = $r;
}

$users_raw = $db->query("
    SELECT cs.callsign,
           GROUP_CONCAT(DISTINCT pg.name ORDER BY pg.name SEPARATOR ', ') as group_names,
           COUNT(DISTINCT pgm2.planning_group_id) as group_count
    FROM (
        SELECT DISTINCT callsign FROM planning_group_members
        UNION
        SELECT DISTINCT owner_callsign FROM planning_groups
    ) cs
    LEFT JOIN planning_group_members pgm2 ON pgm2.callsign = cs.callsign
    LEFT JOIN planning_groups pg ON pgm2.planning_group_id = pg.id
    GROUP BY cs.callsign
    ORDER BY cs.callsign
")->fetchAll();

$la_rows = $db->query("
    SELECT actor, MAX(event_time) as last_activity FROM (
        SELECT CONVERT(nominated_by USING utf8mb4) COLLATE utf8mb4_general_ci AS actor, MAX(created_at) AS event_time FROM summits WHERE nominated_by IS NOT NULL GROUP BY nominated_by
        UNION ALL
        SELECT CONVERT(user_callsign USING utf8mb4) COLLATE utf8mb4_general_ci, MAX(created_at) FROM summit_notes GROUP BY user_callsign
        UNION ALL
        SELECT CONVERT(uploaded_by USING utf8mb4) COLLATE utf8mb4_general_ci, MAX(uploaded_date) FROM gpx_tracks WHERE uploaded_by IS NOT NULL GROUP BY uploaded_by
    ) a GROUP BY actor
")->fetchAll();
$last_activity = [];
foreach ($la_rows as $r) $last_activity[$r['actor']] = $r['last_activity'];

// Try the structured activity_log table first; fall back to derived query if table doesn't exist yet
try {
    $activity_feed = $db->query("
        SELECT event_time, callsign AS actor, login_type, event_type, subject, detail, group_name
        FROM activity_log
        ORDER BY event_time DESC
        LIMIT 200
    ")->fetchAll();
    $activity_feed_source = 'log';
} catch (PDOException $e) {
    // Table not yet created — run db_migrate.php
    $activity_feed = [];
    $activity_feed_source = 'none';
}

$gpx_dir      = __DIR__ . '/gpx_files';
$orphaned_gpx = [];
if (is_dir($gpx_dir)) {
    $db_files = array_flip($db->query("SELECT filename FROM gpx_tracks")->fetchAll(PDO::FETCH_COLUMN));
    foreach (glob($gpx_dir . '/*.gpx') as $path) {
        $fname = basename($path);
        if (!isset($db_files[$fname]))
            $orphaned_gpx[] = ['filename' => $fname, 'size' => filesize($path), 'mtime' => filemtime($path)];
    }
}

$active_tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview' => 'Overview', 'users' => 'Users', 'groups' => 'Groups', 'activity' => 'Activity', 'data' => 'Data Tools', 'cleanup' => 'Cleanup', 'api' => 'API Status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>God Mode — SOTA Planner</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:            #F7F6F3;
  --bg-2:          #EFEDE8;
  --bg-3:          #E5E2DA;
  --ink:           #1C1B19;
  --ink-2:         #4A4844;
  --ink-3:         #8C8A86;
  --ink-4:         #B8B5B0;
  --accent:        oklch(52% 0.13 50);
  --accent-2:      oklch(44% 0.13 50);
  --accent-bg:     oklch(96% 0.04 65);
  --accent-border: oklch(84% 0.08 65);
  --green:         oklch(52% 0.13 155);
  --green-bg:      oklch(95% 0.04 155);
  --orange:        oklch(62% 0.14 58);
  --orange-bg:     oklch(96% 0.05 58);
  --red:           oklch(52% 0.16 22);
  --red-bg:        oklch(96% 0.04 22);
  --blue:          oklch(52% 0.12 240);
  --blue-bg:       oklch(95% 0.04 240);
  --surface:       #FFFFFF;
  --border:        #E5E2DA;
  --border-2:      #D4D0C8;
  --font-sans:     'DM Sans', system-ui, sans-serif;
  --font-mono:     'DM Mono', 'Courier New', monospace;
  --r-sm: 4px; --r-md: 8px; --r-lg: 12px; --r-xl: 16px;
  --sp-1: 0.25rem; --sp-2: 0.5rem; --sp-3: 0.75rem; --sp-4: 1rem;
  --sp-5: 1.25rem; --sp-6: 1.5rem; --sp-8: 2rem; --sp-10: 2.5rem;
  --sp-12: 3rem;
  --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
  --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-font-smoothing: antialiased; }
body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

/* Topbar */
.topbar { background: var(--surface); border-bottom: 1px solid var(--border); height: 56px; display: flex; align-items: center; padding: 0 var(--sp-8); gap: var(--sp-4); position: sticky; top: 0; z-index: 100; }
.topbar-logo { display: flex; align-items: center; gap: var(--sp-3); text-decoration: none; color: var(--ink); font-weight: 600; font-size: 0.95rem; letter-spacing: -0.01em; flex-shrink: 0; }
.topbar-logo:hover { text-decoration: none; color: var(--ink); }
.topbar-logo .logo-mark { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; }
.topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
.god-badge { background: var(--ink); color: #fff; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; padding: 0.2rem 0.55rem; border-radius: var(--r-sm); }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: var(--sp-3); }
.btn-sm-ghost { display: inline-flex; align-items: center; gap: 0.35rem; height: 30px; padding: 0 var(--sp-3); border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.8rem; font-weight: 500; cursor: pointer; border: 1px solid var(--border); background: transparent; color: var(--ink-2); transition: background 0.15s; text-decoration: none; }
.btn-sm-ghost:hover { background: var(--bg-2); color: var(--ink); }

/* Impersonation bar */
.impersonate-bar { background: oklch(52% 0.16 22); color: #fff; text-align: center; padding: 0.5rem 1rem; font-size: 0.82rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 1rem; }
.impersonate-bar button { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: #fff; padding: 0.2rem 0.75rem; border-radius: var(--r-sm); font-size: 0.78rem; font-weight: 600; cursor: pointer; }
.impersonate-bar button:hover { background: rgba(255,255,255,0.3); }

/* Page */
.page { padding: var(--sp-8); max-width: 1100px; margin: 0 auto; }

/* Tabs */
.tab-bar { display: flex; gap: 2px; border-bottom: 1px solid var(--border); margin-bottom: var(--sp-8); }
.tab-btn { display: inline-flex; align-items: center; padding: 0.6rem 1rem; font-size: 0.875rem; font-weight: 500; color: var(--ink-3); border: none; background: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: var(--font-sans); text-decoration: none; transition: color 0.12s, border-color 0.12s; }
.tab-btn:hover { color: var(--ink); }
.tab-btn.active { color: var(--ink); border-bottom-color: var(--ink); }

/* Stats */
.stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: var(--sp-4); margin-bottom: var(--sp-8); }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: var(--sp-5); box-shadow: var(--shadow-sm); }
.stat-num { font-size: 2rem; font-weight: 700; color: var(--ink); line-height: 1; }
.stat-label { font-size: 0.75rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 0.25rem; }

/* Section headings */
.section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--sp-4); }
.section-head h2 { font-size: 1.05rem; font-weight: 600; }
.section-head p  { font-size: 0.8rem; color: var(--ink-3); margin-top: 2px; }

/* Cards */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: var(--sp-6); box-shadow: var(--shadow-sm); margin-bottom: var(--sp-5); }
.card-accent { background: var(--accent-bg); border: 1px solid var(--accent-border); border-radius: var(--r-lg); padding: var(--sp-5); margin-bottom: var(--sp-5); }

/* Table */
.data-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.data-table th { text-align: left; font-size: 0.72rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.06em; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--border); white-space: nowrap; }
.data-table td { padding: 0.65rem 0.75rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: var(--bg); }
.mono { font-family: var(--font-mono); font-size: 0.82rem; }
.muted { color: var(--ink-3); }
.text-right { text-align: right; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: var(--sp-2); padding: 0 var(--sp-4); height: 36px; border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; transition: background 0.15s; text-decoration: none; white-space: nowrap; }
.btn:active { transform: scale(0.98); }
.btn-primary { background: var(--ink); color: #fff; }
.btn-primary:hover { background: var(--ink-2); color: #fff; }
.btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
.btn-danger { background: var(--red-bg); color: var(--red); border: 1px solid oklch(85% 0.08 22); }
.btn-danger:hover { background: oklch(93% 0.05 22); }
.btn-sm { height: 28px; padding: 0 var(--sp-3); font-size: 0.78rem; }

/* Form */
.form-row { display: flex; gap: var(--sp-3); align-items: flex-end; flex-wrap: wrap; }
.form-group { display: flex; flex-direction: column; gap: 0.3rem; }
.form-label { font-size: 0.72rem; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; }
.form-input { padding: 0.5rem 0.65rem; border: 1px solid var(--border); border-radius: var(--r-md); font-family: var(--font-sans); font-size: 0.875rem; color: var(--ink); background: var(--surface); outline: none; min-width: 180px; }
.form-input:focus { border-color: var(--accent); }
select.form-input { cursor: pointer; }

/* Messages */
.msg { display: flex; align-items: center; gap: var(--sp-4); padding: var(--sp-3) var(--sp-4); border-radius: var(--r-md); font-size: 0.875rem; font-weight: 500; margin-bottom: var(--sp-5); }
.msg-success { background: var(--green-bg); color: var(--green); border: 1px solid oklch(85% 0.07 155); }
.msg-error   { background: var(--red-bg);   color: var(--red);   border: 1px solid oklch(85% 0.08 22); }

/* Badge */
.badge { display: inline-block; font-size: 0.68rem; font-weight: 600; border-radius: 20px; padding: 0.12rem 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
.badge-owner  { background: var(--accent-bg); color: var(--accent-2); }
.badge-member { background: var(--bg-3); color: var(--ink-3); }

/* Inline action form */
.inline-form { display: inline; }

/* Activity feed */
.feed-row { display: flex; gap: var(--sp-3); padding: 0.6rem 0; border-bottom: 1px solid var(--border); align-items: baseline; font-size: 0.85rem; }
.feed-row:last-child { border-bottom: none; }
.feed-type { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-4); min-width: 90px; }
.feed-subject { color: var(--ink); flex: 1; }
.feed-actor { font-family: var(--font-mono); font-size: 0.78rem; color: var(--ink-3); }
.feed-group { font-size: 0.78rem; color: var(--ink-4); }
.feed-time { font-size: 0.75rem; color: var(--ink-4); white-space: nowrap; min-width: 100px; text-align: right; }

/* Banner preview */
.banner-preview { border: 1px dashed var(--border-2); border-radius: var(--r-md); padding: var(--sp-3) var(--sp-4); margin-top: var(--sp-3); font-size: 0.875rem; }

/* Empty state */
.empty { text-align: center; padding: var(--sp-8); color: var(--ink-3); font-size: 0.875rem; }
</style>
</head>
<body>

<!-- Topbar -->
<nav class="topbar">
    <a href="index.php" class="topbar-logo">
        <span class="logo-mark"><img src="sota-planner-logo.svg" width="32" height="32" alt=""></span>
        <span>SOTAplanner</span>
    </a>
    <div class="topbar-divider"></div>
    <span class="god-badge">God Mode</span>
    <a href="index.php" class="btn-sm-ghost" style="margin-left:.5rem;">← Dashboard</a>
    <div class="topbar-right">
        <?php if ($real_callsign === 'KI6CR'): ?>
            <form method="POST" class="inline-form">
                <button type="submit" name="exit_impersonate" class="btn btn-sm btn-danger">Exit impersonation</button>
            </form>
        <?php endif; ?>
    </div>
</nav>

<?php if ($real_callsign === 'KI6CR'): ?>
<div class="impersonate-bar">
    ⚠️ You are currently impersonating <strong><?= htmlspecialchars($callsign) ?></strong>.
    <form method="POST" class="inline-form">
        <button type="submit" name="exit_impersonate">Exit &amp; return to God Mode</button>
    </form>
</div>
<?php endif; ?>

<div class="page">

    <?php if ($message): ?>
        <div class="msg msg-success">✓ <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="msg msg-error">✕ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Tab bar -->
    <div class="tab-bar">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="?tab=<?= $key ?>" class="tab-btn <?= $active_tab === $key ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>

    <!-- ── OVERVIEW ── -->
    <?php if ($active_tab === 'overview'): ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-num"><?= $stats['users'] ?></div>
                <div class="stat-label">Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $stats['groups'] ?></div>
                <div class="stat-label">Groups</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $stats['summits'] ?></div>
                <div class="stat-label">Summits</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $stats['gpx_tracks'] ?></div>
                <div class="stat-label">GPX Tracks</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $stats['global_gpx'] ?></div>
                <div class="stat-label">Community Routes</div>
            </div>
        </div>

        <!-- Site Notice -->
        <div class="section-head">
            <div>
                <h2>Sitewide Notice</h2>
                <p>Posts a banner on the dashboard and planning groups page for all logged-in users.</p>
            </div>
        </div>
        <div class="card">
            <?php if ($current_banner && !empty($current_banner['text'])): ?>
                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:var(--sp-4); padding:var(--sp-3) var(--sp-4); background:var(--<?= htmlspecialchars($current_banner['type'] === 'success' ? 'green' : ($current_banner['type'] === 'error' ? 'red' : 'accent')) ?>-bg); border:1px solid var(--border); border-radius:var(--r-md);">
                    <span style="font-size:0.875rem; font-weight:500;"><?= htmlspecialchars($current_banner['text']) ?></span>
                    <form method="POST" class="inline-form">
                        <button type="submit" name="clear_banner" class="btn btn-sm btn-danger">Clear Banner</button>
                    </form>
                </div>
            <?php else: ?>
                <p style="font-size:0.85rem; color:var(--ink-3); margin-bottom:var(--sp-4);">No active banner.</p>
            <?php endif; ?>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group" style="flex:1; min-width:260px;">
                        <label class="form-label">Banner Text</label>
                        <input type="text" name="banner_text" class="form-input" placeholder="e.g. Maintenance tonight at 10 PM Pacific" style="width:100%;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="banner_type" class="form-input">
                            <option value="info">Info</option>
                            <option value="success">Success</option>
                            <option value="error">Alert</option>
                        </select>
                    </div>
                    <button type="submit" name="post_banner" class="btn btn-primary">Post Banner</button>
                </div>
            </form>
        </div>

        <!-- Impersonate -->
        <div class="section-head" style="margin-top:var(--sp-6);">
            <div>
                <h2>Impersonate User</h2>
                <p>Log in as any callsign to see their exact view. You'll be redirected to their dashboard.</p>
            </div>
        </div>
        <div class="card">
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Callsign</label>
                        <input type="text" name="target_callsign" class="form-input" placeholder="e.g. W7XYZ" autocapitalize="characters" style="width:160px;">
                    </div>
                    <button type="submit" name="impersonate" class="btn btn-ghost" onclick="return confirm('Impersonate this user? You\'ll see the site as them.')">Impersonate →</button>
                </div>
            </form>
        </div>

    <!-- ── USERS ── -->
    <?php elseif ($active_tab === 'users'): ?>

        <div class="section-head">
            <div>
                <h2>All Users</h2>
                <p><?= count($users_raw) ?> known callsigns</p>
            </div>
        </div>
        <div class="card" style="padding:0; overflow:hidden;">
            <?php if (empty($users_raw)): ?>
                <div class="empty">No users yet.</div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Callsign</th>
                        <th>Groups</th>
                        <th>Last Activity</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users_raw as $u): ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars($u['callsign']) ?></td>
                        <td style="font-size:0.8rem; color:var(--ink-2); max-width:300px;"><?= htmlspecialchars($u['group_names'] ?? '—') ?></td>
                        <td class="muted" style="font-size:0.8rem;">
                            <?= isset($last_activity[$u['callsign']]) ? date('M j, Y', strtotime($last_activity[$u['callsign']])) : '—' ?>
                        </td>
                        <td class="text-right" style="white-space:nowrap;">
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="target_callsign" value="<?= htmlspecialchars($u['callsign']) ?>">
                                <button type="submit" name="impersonate" class="btn btn-sm btn-ghost">View as</button>
                            </form>
                            &nbsp;
                            <?php if ($u['callsign'] !== 'KI6CR'): ?>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="target_callsign" value="<?= htmlspecialchars($u['callsign']) ?>">
                                <button type="submit" name="remove_user" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Remove <?= htmlspecialchars($u['callsign']) ?> from all non-owned groups?')">Remove</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    <!-- ── GROUPS ── -->
    <?php elseif ($active_tab === 'groups'): ?>

        <div class="section-head">
            <div>
                <h2>All Planning Groups</h2>
                <p><?= count($groups) ?> groups</p>
            </div>
        </div>

        <?php foreach ($groups as $g): ?>
        <div class="card" style="margin-bottom:var(--sp-4);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:var(--sp-4); margin-bottom:var(--sp-4);">
                <div>
                    <div style="font-weight:600; font-size:1rem;"><?= htmlspecialchars($g['name']) ?></div>
                    <div style="font-size:0.8rem; color:var(--ink-3); margin-top:2px;">
                        Owner: <span class="mono" style="color:var(--ink-2);"><?= htmlspecialchars($g['owner_callsign'] ?? '—') ?></span>
                        &nbsp;·&nbsp; <?= $g['member_count'] ?> member<?= $g['member_count'] != 1 ? 's' : '' ?>
                        &nbsp;·&nbsp; <?= $g['summit_count'] ?> summit<?= $g['summit_count'] != 1 ? 's' : '' ?>
                        &nbsp;·&nbsp; <?= $g['address_count'] ?> address<?= $g['address_count'] != 1 ? 'es' : '' ?>
                        &nbsp;·&nbsp; <?= $g['units'] ?>
                        <?php if ($g['created_at']): ?>
                            &nbsp;·&nbsp; Created <?= date('M j, Y', strtotime($g['created_at'])) ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($gm_map[$g['id']])): ?>
                    <div style="margin-top:var(--sp-2); display:flex; gap:var(--sp-2); flex-wrap:wrap;">
                        <?php foreach ($gm_map[$g['id']] as $m): ?>
                            <span class="badge <?= $m['role'] === 'owner' ? 'badge-owner' : 'badge-member' ?>">
                                <?= htmlspecialchars($m['callsign']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="display:flex; gap:var(--sp-2); flex-shrink:0; flex-wrap:wrap; justify-content:flex-end;">
                    <!-- Clear drive times -->
                    <?php if ($g['missing_drive'] > 0): ?>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                        <button type="submit" name="clear_drive_times" class="btn btn-sm btn-ghost" onclick="return confirm('Clear all drive times for this group?')">
                            Clear drive times (<?= (int)$g['missing_drive'] ?> missing)
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                        <button type="submit" name="clear_drive_times" class="btn btn-sm btn-ghost" onclick="return confirm('Clear all drive times for this group so they recalculate?')">
                            Reset drive times
                        </button>
                    </form>
                    <?php endif; ?>
                    <!-- Delete -->
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                        <input type="hidden" name="confirm_delete" value="yes">
                        <button type="submit" name="delete_group" class="btn btn-sm btn-danger"
                            onclick="return confirm('DELETE group \"<?= htmlspecialchars(addslashes($g['name'])) ?>\" and ALL its data? This cannot be undone.')">
                            Delete group
                        </button>
                    </form>
                </div>
            </div>

            <!-- Add member & transfer ownership -->
            <div style="display:flex; gap:var(--sp-6); flex-wrap:wrap; padding-top:var(--sp-4); border-top:1px solid var(--border);">
                <form method="POST" style="display:flex; gap:var(--sp-2); align-items:flex-end;">
                    <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                    <div class="form-group">
                        <label class="form-label">Add Member</label>
                        <input type="text" name="new_callsign" class="form-input" placeholder="Callsign" autocapitalize="characters" style="width:130px;">
                    </div>
                    <button type="submit" name="add_member_to_group" class="btn btn-sm btn-ghost">Add</button>
                </form>
                <form method="POST" style="display:flex; gap:var(--sp-2); align-items:flex-end;">
                    <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                    <div class="form-group">
                        <label class="form-label">Transfer Ownership To</label>
                        <input type="text" name="new_owner" class="form-input" placeholder="Callsign" autocapitalize="characters" style="width:130px;">
                    </div>
                    <button type="submit" name="transfer_ownership" class="btn btn-sm btn-ghost"
                        onclick="return confirm('Transfer ownership of this group?')">Transfer</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($groups)): ?>
            <div class="empty">No planning groups yet.</div>
        <?php endif; ?>

    <!-- ── ACTIVITY ── -->
    <?php elseif ($active_tab === 'activity'): ?>

        <div class="section-head">
            <div>
                <h2>Activity Log</h2>
                <p>Last 200 logged events — includes callsign and login method. Persistent log file also at <code>~/logs/sotaplanner_activity.log</code>.</p>
            </div>
        </div>
        <?php if ($activity_feed_source === 'none'): ?>
        <div class="card" style="background:var(--orange-bg); border-color:var(--orange);">
            <strong style="color:var(--orange);">activity_log table not yet created.</strong>
            Run <a href="db_migrate.php" style="color:var(--orange);">db_migrate.php</a> to create it, then events will be tracked here.
        </div>
        <?php else: ?>
        <div class="card">
            <?php if (empty($activity_feed)): ?>
                <div class="empty">No events logged yet. Activity will appear here as users make changes.</div>
            <?php else: ?>
                <?php foreach ($activity_feed as $ev): ?>
                <div class="feed-row">
                    <span class="feed-type"><?= htmlspecialchars($ev['event_type']) ?></span>
                    <span class="feed-subject">
                        <?= htmlspecialchars($ev['subject']) ?>
                        <?php if (!empty($ev['detail']) && $ev['detail'] !== $ev['subject']): ?>
                            <span class="muted" style="font-size:0.78rem;"> — <?= htmlspecialchars($ev['detail']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="feed-actor" title="<?= htmlspecialchars($ev['login_type'] ?? '') ?>">
                        <?= htmlspecialchars($ev['actor']) ?>
                        <?php if (!empty($ev['login_type']) && $ev['login_type'] === 'sota_oauth'): ?>
                            <span style="font-size:0.65rem; color:var(--green); font-weight:600; margin-left:3px;">SSO</span>
                        <?php endif; ?>
                    </span>
                    <span class="feed-group"><?= htmlspecialchars($ev['group_name']) ?></span>
                    <span class="feed-time"><?= $ev['event_time'] ? date('M j, g:ia', strtotime($ev['event_time'])) : '—' ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <!-- ── DATA TOOLS ── -->
    <?php elseif ($active_tab === 'data'): ?>

        <div class="section-head">
            <div>
                <h2>Data Pre-population Tools</h2>
                <p>Import community data from external sources into the global library. Run on production only.</p>
            </div>
        </div>

        <div class="card" style="margin-bottom:var(--sp-4);">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:var(--sp-6);">
                <div>
                    <div style="font-weight:600; font-size:1rem; margin-bottom:0.3rem;">Batch GPX Import</div>
                    <div style="font-size:0.85rem; color:var(--ink-2); line-height:1.55;">
                        Imports community-submitted trail routes from the SOTA Mapping Project into the global GPX library.
                        Pick any SOTA association (W6, W7O, G, VK, etc.), load the queue, and run.
                        Routes appear automatically when users nominate matching summits.
                    </div>
                    <div style="font-size:0.78rem; color:var(--ink-3); margin-top:0.5rem;"><?= $stats['global_gpx'] ?> community routes in library so far</div>
                </div>
                <a href="admin_batch_gpx.php" class="btn btn-primary" style="flex-shrink:0;">Open →</a>
            </div>
        </div>

        <div class="card" style="margin-bottom:var(--sp-4);">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:var(--sp-6);">
                <div>
                    <div style="font-weight:600; font-size:1rem; margin-bottom:0.3rem;">GPX Library Browser</div>
                    <div style="font-size:0.85rem; color:var(--ink-2); line-height:1.55;">
                        Browse all community routes in the global library. Filter by association, search by summit reference,
                        and remove individual entries to re-queue them for re-import.
                    </div>
                </div>
                <a href="admin_gpx_library.php" class="btn btn-primary" style="flex-shrink:0;">Open →</a>
            </div>
        </div>

        <div class="card" style="margin-bottom:var(--sp-4);">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:var(--sp-6);">
                <div>
                    <div style="font-weight:600; font-size:1rem; margin-bottom:0.3rem;">Trailhead Lookup (OpenStreetMap)</div>
                    <div style="font-size:0.85rem; color:var(--ink-2); line-height:1.55;">
                        For summits that are missing trailhead coordinates, queries OpenStreetMap for nearby trailheads and
                        parking areas. Once a trailhead is found, drive time calculations unlock automatically for that summit.
                    </div>
                </div>
                <a href="admin_trailhead_osm.php" class="btn btn-primary" style="flex-shrink:0;">Open →</a>
            </div>
        </div>

    <!-- ── CLEANUP ── -->
    <?php elseif ($active_tab === 'cleanup'): ?>

        <!-- Orphaned GPX -->
        <div class="section-head">
            <div>
                <h2>Orphaned GPX Files</h2>
                <p>Files on disk with no matching database record.</p>
            </div>
        </div>
        <div class="card" style="padding:0; overflow:hidden; margin-bottom:var(--sp-8);">
            <?php if (empty($orphaned_gpx)): ?>
                <div class="empty">No orphaned files found.</div>
            <?php else: ?>
            <form method="POST">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:32px;"><input type="checkbox" id="gpx-all" onchange="document.querySelectorAll('.gpx-cb').forEach(c=>c.checked=this.checked)"></th>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Modified</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphaned_gpx as $f): ?>
                        <tr>
                            <td><input type="checkbox" class="gpx-cb" name="orphaned_files[]" value="<?= htmlspecialchars($f['filename']) ?>"></td>
                            <td class="mono"><?= htmlspecialchars($f['filename']) ?></td>
                            <td class="muted"><?= round($f['size'] / 1024) ?> KB</td>
                            <td class="muted"><?= date('M j, Y', $f['mtime']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="padding:var(--sp-4); border-top:1px solid var(--border);">
                    <button type="submit" name="delete_orphaned_gpx" class="btn btn-danger"
                        onclick="return confirm('Delete selected GPX files? This cannot be undone.')">Delete Selected</button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- Missing drive times -->
        <div class="section-head">
            <div>
                <h2>Drive Time Coverage</h2>
                <p>Summits with trailhead coordinates but no drive time calculated yet.</p>
            </div>
        </div>
        <div class="card" style="padding:0; overflow:hidden;">
            <?php
            $drive_gaps = $db->query("
                SELECT pg.id, pg.name as group_name, COUNT(*) as count
                FROM summits s
                JOIN planning_groups pg ON s.planning_group_id = pg.id
                WHERE s.drive_time_min IS NULL AND s.trailhead_lat IS NOT NULL
                GROUP BY pg.id, pg.name
            ")->fetchAll();
            ?>
            <?php if (empty($drive_gaps)): ?>
                <div class="empty">All trailheads have drive times calculated.</div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Group</th>
                        <th>Summits missing drive time</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($drive_gaps as $g): ?>
                    <tr>
                        <td><?= htmlspecialchars($g['group_name']) ?></td>
                        <td><?= $g['count'] ?></td>
                        <td class="text-right">
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                                <button type="submit" name="clear_drive_times" class="btn btn-sm btn-ghost"
                                    onclick="return confirm('Reset all drive times for this group?')">Reset all drive times</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    <!-- ── API STATUS ── -->
    <?php elseif ($active_tab === 'api'): ?>

        <?php
        // ── Server-side API tests ────────────────────────────────────────────
        $key = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';

        function gm_curl_get($url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'SOTAPlanner/1.0',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return ['body' => $body, 'code' => $code, 'err' => $err];
        }

        // Test 1: Geocoding API
        $geo = null; $geo_ok = false; $geo_msg = '';
        if ($key && $key !== 'YOUR_API_KEY_HERE') {
            $r = gm_curl_get("https://maps.googleapis.com/maps/api/geocode/json?" . http_build_query([
                'address' => 'Portland, Oregon, USA',
                'key'     => $key,
            ]));
            if ($r['err']) {
                $geo_msg = "cURL error: " . $r['err'];
            } else {
                $geo = json_decode($r['body'], true);
                if ($geo && $geo['status'] === 'OK') {
                    $geo_ok  = true;
                    $loc     = $geo['results'][0]['geometry']['location'];
                    $geo_msg = "OK — Portland, OR resolved to {$loc['lat']}, {$loc['lng']}";
                } else {
                    $geo_msg = "Status: " . ($geo['status'] ?? 'unknown');
                    if (!empty($geo['error_message'])) $geo_msg .= " — " . $geo['error_message'];
                }
            }
        } else {
            $geo_msg = "API key not configured";
        }

        // Test 2: Distance Matrix API
        $dm = null; $dm_ok = false; $dm_msg = '';
        if ($key && $key !== 'YOUR_API_KEY_HERE') {
            $r = gm_curl_get("https://maps.googleapis.com/maps/api/distancematrix/json?" . http_build_query([
                'origins'      => 'Portland, Oregon, USA',
                'destinations' => '45.3732,-121.6959',   // Mt Hood summit
                'mode'         => 'driving',
                'key'          => $key,
            ]));
            if ($r['err']) {
                $dm_msg = "cURL error: " . $r['err'];
            } else {
                $dm = json_decode($r['body'], true);
                if ($dm && $dm['status'] === 'OK') {
                    $elem = $dm['rows'][0]['elements'][0] ?? [];
                    if (($elem['status'] ?? '') === 'OK') {
                        $dm_ok  = true;
                        $dur    = $elem['duration']['text'] ?? '?';
                        $dist   = $elem['distance']['text'] ?? '?';
                        $dm_msg = "OK — Portland to Mt Hood: $dist, $dur";
                    } else {
                        $dm_msg = "Row status: " . ($elem['status'] ?? 'unknown');
                    }
                } else {
                    $dm_msg = "Status: " . ($dm['status'] ?? 'unknown');
                    if (!empty($dm['error_message'])) $dm_msg .= " — " . $dm['error_message'];
                }
            }
        } else {
            $dm_msg = "API key not configured";
        }
        ?>

        <div class="section-head">
            <div>
                <h2>Google Maps API Diagnostics</h2>
                <p>Live checks for every Maps API used by SOTA Planner. Server-side tests run on page load; browser test runs in your browser tab.</p>
            </div>
            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="btn btn-ghost btn-sm">Google Cloud Console →</a>
        </div>

        <!-- API Key cards -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:var(--sp-4); margin-bottom:var(--sp-4);">
            <div class="card">
                <div style="font-size:0.75rem; font-weight:600; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:0.4rem;">Server Key (Geocoding + Distance Matrix)</div>
                <?php if ($key && $key !== 'YOUR_API_KEY_HERE'): ?>
                    <div class="mono" style="font-size:0.9rem; color:var(--ink-2);"><?= htmlspecialchars(substr($key, 0, 8)) ?>...<?= htmlspecialchars(substr($key, -6)) ?></div>
                    <div style="font-size:0.78rem; color:var(--ink-3); margin-top:0.4rem;">Application restrictions: None &nbsp;·&nbsp; API restrictions: Geocoding + Distance Matrix</div>
                <?php else: ?>
                    <div style="color:var(--red);">Not configured</div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div style="font-size:0.75rem; font-weight:600; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:0.4rem;">Browser Key (Maps JavaScript API)</div>
                <?php $bkey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : ''; ?>
                <?php if ($bkey): ?>
                    <div class="mono" style="font-size:0.9rem; color:var(--ink-2);"><?= htmlspecialchars(substr($bkey, 0, 8)) ?>...<?= htmlspecialchars(substr($bkey, -6)) ?></div>
                    <div style="font-size:0.78rem; color:var(--ink-3); margin-top:0.4rem;">Application restrictions: HTTP referrers (3 domains) &nbsp;·&nbsp; API restrictions: Maps JS only</div>
                <?php else: ?>
                    <div style="color:var(--red);">Not configured — add GOOGLE_MAPS_BROWSER_KEY to sotaplanner_secrets.php</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Server-side test results -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:var(--sp-4); margin-bottom:var(--sp-4);">

            <div class="card">
                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                    <span style="font-size:1.25rem;"><?= $geo_ok ? '✅' : '❌' ?></span>
                    <div style="font-weight:600;">Geocoding API</div>
                    <span style="margin-left:auto; font-size:0.72rem; font-weight:600; color:<?= $geo_ok ? 'var(--green)' : 'var(--red)' ?>; text-transform:uppercase; letter-spacing:0.05em;"><?= $geo_ok ? 'PASS' : 'FAIL' ?></span>
                </div>
                <div style="font-size:0.82rem; color:var(--ink-2); line-height:1.5;"><?= htmlspecialchars($geo_msg) ?></div>
                <div style="font-size:0.75rem; color:var(--ink-3); margin-top:0.5rem;">Used for: converting starting addresses to lat/lng</div>
            </div>

            <div class="card">
                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem;">
                    <span style="font-size:1.25rem;"><?= $dm_ok ? '✅' : '❌' ?></span>
                    <div style="font-weight:600;">Distance Matrix API</div>
                    <span style="margin-left:auto; font-size:0.72rem; font-weight:600; color:<?= $dm_ok ? 'var(--green)' : 'var(--red)' ?>; text-transform:uppercase; letter-spacing:0.05em;"><?= $dm_ok ? 'PASS' : 'FAIL' ?></span>
                </div>
                <div style="font-size:0.82rem; color:var(--ink-2); line-height:1.5;"><?= htmlspecialchars($dm_msg) ?></div>
                <div style="font-size:0.75rem; color:var(--ink-3); margin-top:0.5rem;">Used for: drive time from home to trailhead</div>
            </div>

        </div>

        <!-- Browser / JS API test -->
        <div class="card" style="margin-bottom:var(--sp-4);">
            <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.75rem;">
                <div style="font-weight:600;">Maps JavaScript API</div>
                <span id="js-status-badge" style="margin-left:auto; font-size:0.72rem; font-weight:600; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">LOADING…</span>
            </div>
            <div id="js-status-msg" style="font-size:0.82rem; color:var(--ink-2); margin-bottom:0.75rem;">Initialising map — watch for auth errors below.</div>
            <div id="map-test-container" style="width:100%; height:220px; border-radius:var(--r-md); overflow:hidden; background:var(--bg-2); border:1px solid var(--border);">
                <div id="map-test" style="width:100%; height:100%;"></div>
            </div>
            <div style="font-size:0.75rem; color:var(--ink-3); margin-top:0.5rem;">
                Used for: interactive maps on summit detail and activation invite pages &nbsp;·&nbsp;
                Referrer restrictions must allow <code>*.sotaplanner.com/*</code> and <code>*.ki6cr.com/*</code>
            </div>
        </div>

        <!-- Troubleshooting checklist -->
        <div class="card">
            <div style="font-weight:600; margin-bottom:0.75rem;">Common failure causes &amp; fixes</div>
            <table style="width:100%; font-size:0.83rem; border-collapse:collapse;">
                <tbody>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:0.55rem 0.5rem 0.55rem 0; width:50%; font-weight:500;">Geocoding or Distance Matrix fails with <code>REQUEST_DENIED</code></td>
                        <td style="padding:0.55rem 0; color:var(--ink-2);">Billing not enabled, or those APIs not activated in Google Cloud. <a href="https://console.cloud.google.com/apis/library" target="_blank" style="color:var(--accent);">Enable APIs →</a></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:0.55rem 0.5rem 0.55rem 0; font-weight:500;">JS map shows "This page can't load Google Maps correctly"</td>
                        <td style="padding:0.55rem 0; color:var(--ink-2);">The key's HTTP referrer allowlist is missing the site domains. Add <code>*.sotaplanner.com/*</code> and <code>*.ki6cr.com/*</code> in the key's "Application restrictions." <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--accent);">Edit key →</a></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:0.55rem 0.5rem 0.55rem 0; font-weight:500;">Maps JS API works here (god_mode) but not on other pages</td>
                        <td style="padding:0.55rem 0; color:var(--ink-2);">This admin page loads the map from the server domain. Other pages may be blocked if the referrer allowlist doesn't include all domains.</td>
                    </tr>
                    <tr>
                        <td style="padding:0.55rem 0.5rem 0.55rem 0; font-weight:500;">Everything was working, then suddenly stopped</td>
                        <td style="padding:0.55rem 0; color:var(--ink-2);">Most common cause: billing lapsed, quota exceeded, or Google auto-rotated / revoked the key. Check <a href="https://console.cloud.google.com/billing" target="_blank" style="color:var(--accent);">Billing →</a> and <a href="https://console.cloud.google.com/apis/dashboard" target="_blank" style="color:var(--accent);">API dashboard →</a> for errors.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        var mapPassed = false;

        function setJsFail(msg) {
            if (mapPassed) return;
            document.getElementById('js-status-badge').textContent = 'FAIL';
            document.getElementById('js-status-badge').style.color = 'var(--red)';
            document.getElementById('js-status-msg').textContent = '❌ ' + msg;
            document.getElementById('js-status-msg').style.color = 'var(--red)';
            document.getElementById('map-test-container').style.background = 'var(--red-bg)';
        }

        window.gm_authfailure = function() {
            setJsFail('Auth failure — the Maps JavaScript API rejected the key. ' +
                'Likely a referrer restriction blocking this domain, billing disabled, ' +
                'or the Maps JS API is not enabled for this key.');
        };

        function initMap() {
            try {
                var map = new google.maps.Map(document.getElementById('map-test'), {
                    center: { lat: 45.37, lng: -121.70 },
                    zoom: 9,
                    mapTypeId: 'terrain',
                    disableDefaultUI: true,
                    gestureHandling: 'none',
                });
                // Only mark PASS when tiles actually load — not just when the Map object is created
                google.maps.event.addListenerOnce(map, 'tilesloaded', function() {
                    mapPassed = true;
                    document.getElementById('js-status-badge').textContent = 'PASS';
                    document.getElementById('js-status-badge').style.color = 'var(--green)';
                    document.getElementById('js-status-msg').textContent =
                        '✅ Maps JavaScript API loaded successfully — tiles rendered.';
                    document.getElementById('js-status-msg').style.color = 'var(--green)';
                });
                // Timeout fallback — if tiles never load, something failed silently
                setTimeout(function() {
                    if (!mapPassed) {
                        setJsFail('Map tiles never loaded (timed out after 8 s). ' +
                            'This usually means a referrer restriction or billing issue.');
                    }
                }, 8000);
            } catch(e) {
                setJsFail('JS error: ' + e.message);
            }
        }
        </script>
        <script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars(defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : GOOGLE_MAPS_API_KEY) ?>&callback=initMap&loading=async"
                async defer
                onerror="document.getElementById('js-status-badge').textContent='ERROR';document.getElementById('js-status-msg').textContent='❌ Script failed to load — check network or API key.';">
        </script>

    <?php endif; ?>

</div><!-- /page -->

<footer style="text-align:center; padding:1.5rem 1rem; color:var(--ink-4); font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:var(--ink-4); text-decoration:none;">v<?= APP_VERSION ?></a>
    &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:var(--ink-4); text-decoration:none;">sotaplanner.com</a>
</footer>
</body>
</html>
