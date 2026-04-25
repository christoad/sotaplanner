<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();

// Load hashed admin password from file outside web root
// Hash file lives one directory above the web root to prevent direct access
$hash_file = __DIR__ . '/../admin_password.hash';

if (!file_exists($hash_file)) {
    die("Admin password not configured. Please contact the administrator.");
}

$admin_password_hash = trim(file_get_contents($hash_file));

// Check if logged in
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (password_verify($_POST['password'], $admin_password_hash)) {
            $_SESSION['admin_logged_in'] = true;
        } else {
            $error = "Incorrect password";
        }
    }
    
    // Show login form if not logged in
    if (!isset($_SESSION['admin_logged_in'])) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="robots" content="noindex, nofollow">
            <title>Admin Login</title>
            <style>
                body {
                    font-family: 'Overpass', sans-serif;
                    background: linear-gradient(135deg, #1E3A5F 0%, #4A90A4 100%);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                }
                .login-box {
                    background: white;
                    padding: 3rem;
                    border-radius: 12px;
                    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
                    max-width: 400px;
                    width: 100%;
                }
                h1 {
                    color: #1E3A5F;
                    margin-bottom: 2rem;
                    text-align: center;
                }
                input[type="password"] {
                    width: 100%;
                    padding: 1rem;
                    border: 2px solid #ccc;
                    border-radius: 6px;
                    margin-bottom: 1rem;
                    font-size: 1rem;
                }
                button {
                    width: 100%;
                    padding: 1rem;
                    background: linear-gradient(135deg, #4A90A4 0%, #1E3A5F 100%);
                    color: white;
                    border: none;
                    border-radius: 6px;
                    font-weight: 700;
                    font-size: 1rem;
                    cursor: pointer;
                }
                .error {
                    color: #C62828;
                    margin-bottom: 1rem;
                    font-weight: 600;
                }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h1>🔒 Admin Access</h1>
                <?php if (isset($error)): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="Enter password" required autofocus>
                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Handle logout
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Handle password change
if (isset($_POST['change_password'])) {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $admin_password_hash)) {
        $error = "Current password is incorrect.";
    } elseif (strlen($new) < 8) {
        $error = "New password must be at least 8 characters.";
    } elseif ($new !== $confirm) {
        $error = "New passwords do not match.";
    } else {
        file_put_contents($hash_file, password_hash($new, PASSWORD_BCRYPT));
        $admin_password_hash = trim(file_get_contents($hash_file));
        $message = "✓ Password changed successfully.";
    }
}

$message = $message ?? '';
$error   = $error ?? '';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create new planning group
    if (isset($_POST['create_group'])) {
        $name  = trim($_POST['new_group_name'] ?? '');
        $units = $_POST['new_group_units'] ?? 'imperial';
        if ($name) {
            try {
                $db->prepare("INSERT INTO planning_groups (name, units) VALUES (?, ?)")->execute([$name, $units]);
                $message = "Planning group '$name' created.";
            } catch (PDOException $e) {
                $error = "Error creating group: " . $e->getMessage();
            }
        } else {
            $error = "Group name cannot be empty.";
        }
    }

    // Rename / change units for a group
    if (isset($_POST['update_group'])) {
        $group_id = (int)$_POST['group_id'];
        $name     = trim($_POST['group_name'] ?? '');
        $units    = $_POST['group_units'] ?? 'imperial';
        if ($name) {
            try {
                $db->prepare("UPDATE planning_groups SET name = ?, units = ? WHERE id = ?")
                   ->execute([$name, $units, $group_id]);
                $message = "Group updated.";
            } catch (PDOException $e) {
                $error = "Error updating group: " . $e->getMessage();
            }
        } else {
            $error = "Group name cannot be empty.";
        }
    }

    // Delete orphaned GPX files
    if (isset($_POST['delete_orphaned_gpx'])) {
        $files = $_POST['orphaned_files'] ?? [];
        $deleted = 0;
        foreach ($files as $f) {
            $path = __DIR__ . '/gpx_files/' . basename($f);
            if (file_exists($path)) { unlink($path); $deleted++; }
        }
        $message = "Deleted $deleted orphaned GPX file(s).";
    }

    // Delete planning group
    if (isset($_POST['delete_group'])) {
        $group_id = (int)$_POST['group_id'];
        try {
            // Delete all associated data
            $db->prepare("DELETE FROM activations WHERE planning_group_id = ?")->execute([$group_id]);
            $db->prepare("DELETE FROM addresses WHERE planning_group_id = ?")->execute([$group_id]);
            $db->prepare("DELETE FROM summits WHERE planning_group_id = ?")->execute([$group_id]);
            $db->prepare("DELETE FROM planning_groups WHERE id = ?")->execute([$group_id]);
            $message = "Planning group and all associated data deleted successfully";
        } catch (PDOException $e) {
            $error = "Error deleting group: " . $e->getMessage();
        }
    }
    
    // Delete all nominations for a group
    if (isset($_POST['delete_group_summits'])) {
        $group_id = (int)$_POST['group_id'];
        try {
            $db->prepare("DELETE FROM activations WHERE planning_group_id = ?")->execute([$group_id]);
            $db->prepare("DELETE FROM summits WHERE planning_group_id = ?")->execute([$group_id]);
            $message = "All nominations deleted for selected group";
        } catch (PDOException $e) {
            $error = "Error deleting summits: " . $e->getMessage();
        }
    }
    
    // Delete all addresses for a group
    if (isset($_POST['delete_group_addresses'])) {
        $group_id = (int)$_POST['group_id'];
        try {
            $db->prepare("DELETE FROM addresses WHERE planning_group_id = ?")->execute([$group_id]);
            $message = "All addresses deleted for selected group";
        } catch (PDOException $e) {
            $error = "Error deleting addresses: " . $e->getMessage();
        }
    }
    
    // Delete ALL data (nuclear option)
    if (isset($_POST['delete_all_data']) && $_POST['confirm'] === 'DELETE EVERYTHING') {
        try {
            $db->exec("DELETE FROM activations");
            $db->exec("DELETE FROM summit_notes");
            $db->exec("DELETE FROM summits");
            $db->exec("DELETE FROM addresses");
            $db->exec("DELETE FROM planning_groups");
            $db->exec("DELETE FROM app_settings");
            $message = "ALL DATA HAS BEEN DELETED - Database reset to empty state";
        } catch (PDOException $e) {
            $error = "Error deleting all data: " . $e->getMessage();
        }
    }
}

// Get all planning groups with stats
$stmt = $db->query("
    SELECT 
        pg.*,
        COUNT(DISTINCT s.id) as summit_count,
        COUNT(DISTINCT a.id) as address_count,
        COUNT(DISTINCT act.id) as activation_count
    FROM planning_groups pg
    LEFT JOIN summits s ON s.planning_group_id = pg.id
    LEFT JOIN addresses a ON a.planning_group_id = pg.id
    LEFT JOIN activations act ON act.planning_group_id = pg.id
    GROUP BY pg.id
    ORDER BY pg.id
");
$groups = $stmt->fetchAll();

// Get database stats
$stats = [
    'total_groups'      => $db->query("SELECT COUNT(*) FROM planning_groups")->fetchColumn(),
    'total_summits'     => $db->query("SELECT COUNT(*) FROM summits")->fetchColumn(),
    'total_addresses'   => $db->query("SELECT COUNT(*) FROM addresses")->fetchColumn(),
    'total_activations' => $db->query("SELECT COUNT(*) FROM activations")->fetchColumn(),
];

// Recent activity — last 20 changes across all tables
$recent_activity = $db->query("
    SELECT * FROM (
        SELECT
            s.updated_at as event_time,
            CONVERT('Summit edited' USING utf8mb4) COLLATE utf8mb4_general_ci as event_type,
            CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_general_ci as subject,
            CONVERT(s.sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci as detail,
            CONVERT(COALESCE(s.nominated_by, '-') USING utf8mb4) COLLATE utf8mb4_general_ci as actor,
            CONVERT(pg.name USING utf8mb4) COLLATE utf8mb4_general_ci as group_name
        FROM summits s
        JOIN planning_groups pg ON s.planning_group_id = pg.id
        WHERE s.updated_at IS NOT NULL

        UNION ALL

        SELECT
            s.created_at,
            CONVERT('Summit nominated' USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(COALESCE(s.nominated_by, '-') USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(pg.name USING utf8mb4) COLLATE utf8mb4_general_ci
        FROM summits s
        JOIN planning_groups pg ON s.planning_group_id = pg.id

        UNION ALL

        SELECT
            a.created_at,
            CONVERT('Activation logged' USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(a.callsigns USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(pg.name USING utf8mb4) COLLATE utf8mb4_general_ci
        FROM activations a
        JOIN summits s ON a.summit_id = s.id
        JOIN planning_groups pg ON a.planning_group_id = pg.id

        UNION ALL

        SELECT
            g.uploaded_date,
            CONVERT('GPX uploaded' USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(COALESCE(g.uploaded_by, '-') USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(pg.name USING utf8mb4) COLLATE utf8mb4_general_ci
        FROM gpx_tracks g
        JOIN summits s ON g.summit_id = s.id
        JOIN planning_groups pg ON g.planning_group_id = pg.id

        UNION ALL

        SELECT
            n.created_at,
            CONVERT('Note added' USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(s.sota_ref USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(n.user_callsign USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(pg.name USING utf8mb4) COLLATE utf8mb4_general_ci
        FROM summit_notes n
        JOIN summits s ON n.summit_id = s.id
        JOIN planning_groups pg ON s.planning_group_id = pg.id

        UNION ALL

        SELECT
            addr.created_at,
            CONVERT('Address added' USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(COALESCE(addr.label, '') USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(addr.address USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT('-' USING utf8mb4) COLLATE utf8mb4_general_ci,
            CONVERT(pg.name USING utf8mb4) COLLATE utf8mb4_general_ci
        FROM addresses addr
        JOIN planning_groups pg ON addr.planning_group_id = pg.id
    ) activity
    ORDER BY event_time DESC
    LIMIT 20
")->fetchAll();

// Orphaned GPX files (on disk but no DB record)
$gpx_dir = __DIR__ . '/gpx_files';
$orphaned_gpx = [];
if (is_dir($gpx_dir)) {
    $db_files = $db->query("SELECT filename FROM gpx_tracks")->fetchAll(PDO::FETCH_COLUMN);
    $db_files = array_flip($db_files);
    foreach (glob($gpx_dir . '/*.gpx') as $path) {
        $fname = basename($path);
        if (!isset($db_files[$fname])) {
            $orphaned_gpx[] = ['filename' => $fname, 'size' => filesize($path), 'mtime' => filemtime($path)];
        }
    }
}

// Summit health report — per group, count summits in each tier
// Tier 4 (Activated): status=activated
// Tier 3 (Ready/Complete): status=ready OR has trail_link + trailhead + distance + elevation
// Tier 2 (Partial research): has at least one of trail_link, trailhead_lat, hike_distance_mi
// Tier 1 (Nominated only): none of the above
$health_raw = $db->query("
    SELECT
        pg.id as group_id,
        pg.name as group_name,
        s.id as summit_id,
        s.status,
        s.trail_link,
        s.trailhead_lat,
        s.hike_distance_mi,
        s.hike_elevation_gain_ft,
        (SELECT COUNT(*) FROM gpx_tracks g WHERE g.summit_id = s.id AND g.planning_group_id = s.planning_group_id) as has_gpx
    FROM summits s
    JOIN planning_groups pg ON s.planning_group_id = pg.id
    ORDER BY pg.id
")->fetchAll();

$health = []; // [group_id => [name, t1, t2, t3, t4, total]]
foreach ($health_raw as $r) {
    $gid = $r['group_id'];
    if (!isset($health[$gid])) {
        $health[$gid] = ['name' => $r['group_name'], 't1' => 0, 't2' => 0, 't3' => 0, 't4' => 0, 'total' => 0];
    }
    $health[$gid]['total']++;

    $has_trail    = !empty($r['trail_link']);
    $has_trailhd  = !empty($r['trailhead_lat']);
    $has_distance = !empty($r['hike_distance_mi']);
    $has_elevation= !empty($r['hike_elevation_gain_ft']);
    $has_gpx      = $r['has_gpx'] > 0;

    if ($r['status'] === 'activated') {
        $health[$gid]['t4']++;
    } elseif ($r['status'] === 'ready' || ($has_trail && $has_trailhd && $has_distance && $has_elevation)) {
        $health[$gid]['t3']++;
    } elseif ($has_trail || $has_trailhd || $has_distance || $has_gpx) {
        $health[$gid]['t2']++;
    } else {
        $health[$gid]['t1']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
            --gold: #E6B84A;
            --red: #C62828;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Overpass', sans-serif;
            background: linear-gradient(135deg, #F5F5F0 0%, #E8E4D8 100%);
            color: var(--navy);
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 {
            font-size: 2rem;
            font-weight: 800;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid var(--teal);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--navy);
        }

        .stat-label {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .card h2 {
            color: var(--navy);
            margin-bottom: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }

        th {
            background: var(--navy);
            color: white;
            font-weight: 700;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .btn-danger {
            background: var(--red);
            color: white;
        }

        .btn-warning {
            background: var(--gold);
            color: var(--navy);
        }

        .btn-secondary {
            background: #666;
            color: white;
        }

        .danger-zone {
            background: #FDECEA;
            border: 2px solid var(--red);
            padding: 2rem;
            border-radius: 12px;
        }

        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .message.success {
            background: #E6F4EA;
            color: #1E7E34;
        }

        .message.error {
            background: #FDECEA;
            color: var(--red);
        }

        input[type="text"], input[type="password"], select {
            padding: 0.6rem;
            border: 2px solid #ccc;
            border-radius: 6px;
            font-size: 0.95rem;
            font-family: 'Overpass', sans-serif;
        }

        .btn-primary {
            background: var(--teal);
            color: white;
        }

        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.78rem;
        }

        .inline-edit { display: none; }
        .inline-edit.open { display: table-row; background: #f8f8f8; }

        .health-bar-wrap {
            display: flex;
            height: 28px;
            border-radius: 6px;
            overflow: hidden;
            width: 100%;
            gap: 2px;
        }
        .health-bar-seg {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
            color: white;
            transition: flex 0.4s;
            white-space: nowrap;
            overflow: hidden;
        }
        .seg-t1 { background: #9E9E9E; }
        .seg-t2 { background: #FF9800; }
        .seg-t3 { background: #4A90A4; }
        .seg-t4 { background: #2E7D32; }

        .health-legend {
            display: flex; gap: 1rem; flex-wrap: wrap;
            font-size: 0.8rem; margin-bottom: 1rem;
        }
        .health-legend span {
            display: flex; align-items: center; gap: 0.3rem;
        }
        .legend-dot {
            width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0;
        }

        .activity-row td { font-size: 0.9rem; }
        .orphan-row td { font-size: 0.85rem; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>🔧 SOTA Planner Admin</h1>
                <p style="opacity: 0.9; margin-top: 0.5rem;">God Mode Access</p>
            </div>
            <form method="POST">
                <button type="submit" name="logout" class="btn btn-secondary">Logout</button>
            </form>
        </header>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Database Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_groups'] ?></div>
                <div class="stat-label">Planning Groups</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_summits'] ?></div>
                <div class="stat-label">Summits</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_addresses'] ?></div>
                <div class="stat-label">Addresses</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_activations'] ?></div>
                <div class="stat-label">Activations</div>
            </div>
        </div>

        <!-- Planning Groups Management -->
        <div class="card">
            <h2 style="margin-bottom:1.25rem;">Planning Groups</h2>

            <!-- Create new group -->
            <form method="POST" style="display:flex; gap:0.75rem; align-items:flex-end; margin-bottom:1.5rem; flex-wrap:wrap;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:700;text-transform:uppercase;margin-bottom:0.3rem;">New Group Name</label>
                    <input type="text" name="new_group_name" placeholder="e.g. W6LIX Club" style="width:220px;">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:700;text-transform:uppercase;margin-bottom:0.3rem;">Units</label>
                    <select name="new_group_units">
                        <option value="imperial">Imperial</option>
                        <option value="metric">Metric</option>
                    </select>
                </div>
                <button type="submit" name="create_group" class="btn btn-primary">+ Create Group</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Units</th>
                        <th>Summits</th>
                        <th>Addresses</th>
                        <th>Activations</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td><?= $group['id'] ?></td>
                            <td><strong><?= htmlspecialchars($group['name']) ?></strong></td>
                            <td><?= strtoupper($group['units']) ?></td>
                            <td><?= $group['summit_count'] ?></td>
                            <td><?= $group['address_count'] ?></td>
                            <td><?= $group['activation_count'] ?></td>
                            <td style="white-space:nowrap;">
                                <button class="btn btn-primary btn-sm" onclick="toggleEdit(<?= $group['id'] ?>)">Edit</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete all summits for <?= htmlspecialchars($group['name']) ?>?');">
                                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                    <button type="submit" name="delete_group_summits" class="btn btn-warning btn-sm">Del Summits</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete all addresses for <?= htmlspecialchars($group['name']) ?>?');">
                                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                    <button type="submit" name="delete_group_addresses" class="btn btn-warning btn-sm">Del Addresses</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('DELETE ENTIRE GROUP: <?= htmlspecialchars($group['name']) ?>? This cannot be undone!');">
                                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                    <button type="submit" name="delete_group" class="btn btn-danger btn-sm">Delete Group</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="inline-edit" id="edit-group-<?= $group['id'] ?>">
                            <td colspan="7">
                                <form method="POST" style="display:flex; gap:0.75rem; align-items:flex-end; padding:0.5rem 0; flex-wrap:wrap;">
                                    <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                    <div>
                                        <label style="display:block;font-size:0.78rem;font-weight:700;text-transform:uppercase;margin-bottom:0.3rem;">Name</label>
                                        <input type="text" name="group_name" value="<?= htmlspecialchars($group['name']) ?>" style="width:220px;">
                                    </div>
                                    <div>
                                        <label style="display:block;font-size:0.78rem;font-weight:700;text-transform:uppercase;margin-bottom:0.3rem;">Units</label>
                                        <select name="group_units">
                                            <option value="imperial" <?= $group['units']==='imperial'?'selected':'' ?>>Imperial</option>
                                            <option value="metric"   <?= $group['units']==='metric'  ?'selected':'' ?>>Metric</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="update_group" class="btn btn-primary btn-sm">Save</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEdit(<?= $group['id'] ?>)">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Activity + Orphaned GPX side by side -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-bottom:2rem; align-items:start;">

        <!-- Recent Activity -->
        <div class="card" style="margin-bottom:0;">
            <h2 style="margin-bottom:1rem; font-size:1.1rem;">🕐 Recent Activity</h2>
            <?php if ($recent_activity): ?>
            <div style="display:flex; flex-direction:column; gap:0.5rem;">
                <?php foreach ($recent_activity as $act): ?>
                <div style="display:flex; gap:0.6rem; padding:0.5rem 0; border-bottom:1px solid #f0f0f0; align-items:flex-start;">
                    <div style="flex-shrink:0; font-size:0.72rem; color:#888; width:52px; padding-top:2px;">
                        <?= date('M j', strtotime($act['event_time'])) ?>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.82rem;">
                            <span style="font-weight:700; color:var(--navy);"><?= htmlspecialchars($act['event_type']) ?></span>
                            —
                            <?= htmlspecialchars($act['subject']) ?>
                            <span style="font-family:monospace; font-size:0.75rem; color:#888;"><?= htmlspecialchars($act['detail']) ?></span>
                        </div>
                        <div style="font-size:0.75rem; color:#888; margin-top:0.15rem;">
                            <?= htmlspecialchars($act['actor']) ?>
                            · <em><?= htmlspecialchars($act['group_name']) ?></em>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <p style="color:#888; font-size:0.9rem;">No activity recorded yet.</p>
            <?php endif; ?>
        </div>

        <!-- Summit Health Report -->
        <div class="card">
            <h2 style="margin-bottom:1rem;">📊 Summit Health Report</h2>
            <div class="health-legend">
                <span><span class="legend-dot seg-t1"></span> Nominated only — no research</span>
                <span><span class="legend-dot seg-t2"></span> Partial research — some data added</span>
                <span><span class="legend-dot seg-t3"></span> Fully researched / Ready</span>
                <span><span class="legend-dot seg-t4"></span> Activated this year</span>
            </div>
            <?php if ($health): ?>
            <table style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th style="width:160px;">Group</th>
                        <th>Breakdown</th>
                        <th style="width:60px;text-align:center;">Total</th>
                        <th style="width:60px;text-align:center;" class="seg-t1" title="Nominated only">T1</th>
                        <th style="width:60px;text-align:center;" class="seg-t2" title="Partial">T2</th>
                        <th style="width:60px;text-align:center;" class="seg-t3" title="Full/Ready">T3</th>
                        <th style="width:60px;text-align:center;" class="seg-t4" title="Activated">T4</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($health as $gid => $h): ?>
                    <?php $total = max($h['total'], 1); ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($h['name']) ?></strong></td>
                        <td>
                            <div class="health-bar-wrap">
                                <?php if ($h['t1']): ?><div class="health-bar-seg seg-t1" style="flex:<?= $h['t1'] ?>;" title="Nominated only: <?= $h['t1'] ?>"><?= $h['t1'] > 1 ? $h['t1'] : '' ?></div><?php endif; ?>
                                <?php if ($h['t2']): ?><div class="health-bar-seg seg-t2" style="flex:<?= $h['t2'] ?>;" title="Partial: <?= $h['t2'] ?>"><?= $h['t2'] > 1 ? $h['t2'] : '' ?></div><?php endif; ?>
                                <?php if ($h['t3']): ?><div class="health-bar-seg seg-t3" style="flex:<?= $h['t3'] ?>;" title="Fully researched: <?= $h['t3'] ?>"><?= $h['t3'] > 1 ? $h['t3'] : '' ?></div><?php endif; ?>
                                <?php if ($h['t4']): ?><div class="health-bar-seg seg-t4" style="flex:<?= $h['t4'] ?>;" title="Activated: <?= $h['t4'] ?>"><?= $h['t4'] > 1 ? $h['t4'] : '' ?></div><?php endif; ?>
                            </div>
                        </td>
                        <td style="text-align:center;font-weight:700;"><?= $h['total'] ?></td>
                        <td style="text-align:center;color:#9E9E9E;font-weight:700;"><?= $h['t1'] ?: '—' ?></td>
                        <td style="text-align:center;color:#E65100;font-weight:700;"><?= $h['t2'] ?: '—' ?></td>
                        <td style="text-align:center;color:#1565C0;font-weight:700;"><?= $h['t3'] ?: '—' ?></td>
                        <td style="text-align:center;color:#2E7D32;font-weight:700;"><?= $h['t4'] ?: '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:0.78rem;color:#999;margin-top:1rem;">
                T2 = has trail link, trailhead, distance, or GPX. T3 = status Ready or all four fields present. T4 = activated this calendar year.
            </p>
            <?php else: ?>
                <p style="color:#888;">No summits yet.</p>
            <?php endif; ?>
        </div>

        <!-- Orphaned GPX Files -->
        <div class="card" style="margin-bottom:0;">
            <h2 style="margin-bottom:1rem; font-size:1.1rem;">🗂 Orphaned GPX Files</h2>
            <?php if ($orphaned_gpx): ?>
            <p style="color:#666; font-size:0.85rem; margin-bottom:0.75rem;">On disk but no DB record — safe to delete.</p>
            <form method="POST">
                <table style="margin-bottom:1rem; font-size:0.82rem;">
                    <thead>
                        <tr>
                            <th style="width:32px;"><input type="checkbox" onclick="toggleAll(this)"></th>
                            <th>Filename</th>
                            <th>Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphaned_gpx as $f): ?>
                        <tr>
                            <td><input type="checkbox" name="orphaned_files[]" value="<?= htmlspecialchars($f['filename']) ?>" checked></td>
                            <td style="font-family:monospace; word-break:break-all;"><?= htmlspecialchars($f['filename']) ?></td>
                            <td style="white-space:nowrap;"><?= round($f['size'] / 1024, 1) ?> KB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" name="delete_orphaned_gpx" class="btn btn-warning btn-sm"
                        onclick="return confirm('Delete selected orphaned files?');">
                    Delete Selected
                </button>
            </form>
            <?php else: ?>
                <p style="color:#2E7D32; font-weight:600; font-size:0.9rem;">✓ Disk is clean — no orphaned files.</p>
            <?php endif; ?>
        </div>

        </div><!-- end 2-col grid -->

        <!-- Change Password -->
        <div class="card" style="margin-bottom: 2rem;">
            <h2 style="margin-bottom: 1.25rem;">🔑 Change Admin Password</h2>
            <form method="POST" style="max-width: 360px; display: flex; flex-direction: column; gap: 0.85rem;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.3rem;">Current Password</label>
                    <input type="password" name="current_password" required style="width:100%; padding:0.55rem 0.75rem; border:2px solid #ddd; border-radius:6px; font-size:0.95rem;">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.3rem;">New Password <span style="font-weight:400; text-transform:none;">(min 8 chars)</span></label>
                    <input type="password" name="new_password" required minlength="8" style="width:100%; padding:0.55rem 0.75rem; border:2px solid #ddd; border-radius:6px; font-size:0.95rem;">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.3rem;">Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="8" style="width:100%; padding:0.55rem 0.75rem; border:2px solid #ddd; border-radius:6px; font-size:0.95rem;">
                </div>
                <div>
                    <button type="submit" name="change_password" class="btn" style="width:auto;">Update Password</button>
                </div>
            </form>
        </div>

        <!-- DANGER ZONE -->
        <div class="danger-zone">
            <h2 style="color: var(--red); margin-bottom: 1rem;">⚠️ DANGER ZONE</h2>
            <p style="margin-bottom: 1.5rem; font-weight: 600;">
                The action below will DELETE EVERYTHING from the database. This cannot be undone.
            </p>
            <form method="POST" onsubmit="return confirm('ARE YOU ABSOLUTELY SURE? This will delete ALL data from ALL groups!');">
                <div style="margin-bottom: 1rem;">
                    <label for="confirm">Type "DELETE EVERYTHING" to confirm:</label><br>
                    <input type="text" id="confirm" name="confirm" placeholder="DELETE EVERYTHING" style="margin-top: 0.5rem; width: 300px;" required>
                </div>
                <button type="submit" name="delete_all_data" class="btn btn-danger">🔥 DELETE ALL DATA</button>
            </form>
        </div>
    </div>
<script>
function toggleEdit(id) {
    const row = document.getElementById('edit-group-' + id);
    row.classList.toggle('open');
}
function toggleAll(cb) {
    document.querySelectorAll('input[name="orphaned_files[]"]').forEach(c => c.checked = cb.checked);
}
</script>
</body>
</html>
