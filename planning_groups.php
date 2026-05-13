<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
session_start();
requireLogin();

$db = getDbConnection();
$current_callsign = getCurrentCallsign();

$message = '';
$error = '';

// Sitewide banner
$_banner_raw      = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'sitewide_banner'")->fetchColumn();
$_sitewide_banner = $_banner_raw ? json_decode($_banner_raw, true) : null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create new planning group
    if (isset($_POST['create_group'])) {
        $group_name = trim($_POST['group_name']);
        $units = $_POST['units'] ?? 'imperial';
        if (!empty($group_name)) {
            try {
                $stmt = $db->prepare("INSERT INTO planning_groups (name, units, owner_callsign) VALUES (?, ?, ?)");
                $stmt->execute([$group_name, $units, $current_callsign]);
                $new_group_id = $db->lastInsertId();

                // Add creator as owner in members table
                $stmt = $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role) VALUES (?, ?, 'owner')");
                $stmt->execute([$new_group_id, $current_callsign]);

                // Add invited member callsigns
                $members_raw = trim($_POST['member_callsigns'] ?? '');
                if (!empty($members_raw)) {
                    $ins = $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role, invited_by) VALUES (?, ?, 'member', ?)");
                    foreach (explode(',', $members_raw) as $cs) {
                        $cs = strtoupper(trim($cs));
                        if ($cs !== '' && $cs !== $current_callsign) {
                            $ins->execute([$new_group_id, $cs, $current_callsign]);
                        }
                    }
                }


                // Set as current group
                setCurrentPlanningGroup($new_group_id);
                $_SESSION['manage_group_id'] = $new_group_id;

                $message = "Planning group created! Add a starting location so we can calculate drive times.";
                $open_address_modal = true;
            } catch (PDOException $e) {
                $error = "Error creating group: " . $e->getMessage();
            }
        } else {
            $error = "Group name is required.";
        }
    }

    // Select a planning group

// Handle set current address
if (isset($_POST['set_current_address'])) {
    $address_id = (int)$_POST['address_id'];
    $current_group = getCurrentPlanningGroup($db);

    if ($current_group) {
        $setting_key = 'selected_address_group_' . $current_group['id'];
        $stmt = $db->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$setting_key, $address_id]);
        $message = "Current address updated.";
    }
}

if (isset($_POST['select_group'])) {
        $group_id = (int)$_POST['group_id'];
        if ($group_id > 0) {
            $_SESSION['manage_group_id'] = $group_id;
            setCurrentPlanningGroup($group_id);
        }
    }

    // Activate group (go to dashboard)
    if (isset($_POST['activate_group'])) {
        $group_id = (int)$_POST['group_id'];

        if ($group_id > 0) {
            $stmt = $db->prepare("SELECT id FROM planning_groups WHERE id = ?");
            $stmt->execute([$group_id]);
            if ($stmt->fetch()) {
                setCurrentPlanningGroup($group_id);
                $_SESSION['manage_group_id'] = $group_id;
                header("Location: index.php");
                exit;
            } else {
                $error = "Invalid planning group selected.";
            }
        } else {
            $error = "Please select a planning group from the dropdown above.";
        }
    }

    // Add address
    if (isset($_POST['add_address']) && isset($_SESSION['manage_group_id'])) {
        $label = trim($_POST['label']);
        $address = trim($_POST['address']);
        $group_id = $_SESSION['manage_group_id'];

        if (!empty($address)) {
            try {
                $stmt = $db->prepare("INSERT INTO addresses (planning_group_id, label, address) VALUES (?, ?, ?)");
                $stmt->execute([$group_id, $label, $address]);
                $new_address_id = $db->lastInsertId();

                // If this is the only address for the group, auto-select it
                $count_stmt = $db->prepare("SELECT COUNT(*) FROM addresses WHERE planning_group_id = ?");
                $count_stmt->execute([$group_id]);
                if ((int)$count_stmt->fetchColumn() === 1) {
                    $setting_key = 'selected_address_group_' . $group_id;
                    $sel_stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $sel_stmt->execute([$setting_key, $new_address_id]);
                }

                $message = "Address added successfully!";
            } catch (PDOException $e) {
                $error = "Error adding address: " . $e->getMessage();
            }
        } else {
            $error = "Address is required.";
        }
    }

    // Delete address
    if (isset($_POST['delete_address']) && isset($_SESSION['manage_group_id'])) {
        $address_id = (int)$_POST['address_id'];
        $group_id = $_SESSION['manage_group_id'];

        try {
            $stmt = $db->prepare("DELETE FROM addresses WHERE id = ? AND planning_group_id = ?");
            $stmt->execute([$address_id, $group_id]);
            $message = "Address deleted.";
        } catch (PDOException $e) {
            $error = "Error deleting address: " . $e->getMessage();
        }
    }

    // Add member callsign to group
    if (isset($_POST['add_member']) && isset($_SESSION['manage_group_id'])) {
        $group_id  = (int)$_SESSION['manage_group_id'];
        $raw       = trim($_POST['new_member_callsign'] ?? '');
        // Verify current user is a member of the group
        $stmt = $db->prepare("SELECT id FROM planning_group_members WHERE planning_group_id = ? AND callsign = ?");
        $stmt->execute([$group_id, $current_callsign]);
        $isMember = $stmt->fetch();
        if ($isMember && $raw !== '') {
            try {
                $ins = $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role, invited_by) VALUES (?, ?, 'member', ?)");
                $added = [];
                foreach (explode(',', $raw) as $cs) {
                    $cs = strtoupper(trim($cs));
                    if ($cs !== '' && $cs !== $current_callsign) {
                        $ins->execute([$group_id, $cs, $current_callsign]);
                        $added[] = $cs;
                    }
                }
                $message = count($added) ? 'Added: ' . implode(', ', $added) . '.' : 'No new callsigns to add.';
            } catch (PDOException $e) {
                $error = "Error adding member: " . $e->getMessage();
            }
        } else {
            $error = "You must be a member of this group to add members.";
        }
    }

    // Remove member from group
    if (isset($_POST['remove_member']) && isset($_SESSION['manage_group_id'])) {
        $group_id   = (int)$_SESSION['manage_group_id'];
        $remove_cs  = strtoupper(trim($_POST['remove_callsign'] ?? ''));
        $stmt = $db->prepare("SELECT owner_callsign FROM planning_groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $grp = $stmt->fetch();
        if ($grp && $grp['owner_callsign'] === $current_callsign && $remove_cs !== $current_callsign) {
            $stmt = $db->prepare("DELETE FROM planning_group_members WHERE planning_group_id = ? AND callsign = ? AND role != 'owner'");
            $stmt->execute([$group_id, $remove_cs]);
            $message = "Removed $remove_cs from the group.";
        } else {
            $error = "Cannot remove yourself (owner) or you don't have permission.";
        }
    }
}

// Get all planning groups
$all_groups = getAllPlanningGroups($db);

// Get summit counts per group
$group_counts = [];
try {
    $stmt = $db->query("SELECT planning_group_id, COUNT(*) as total, SUM(CASE WHEN status='activated' THEN 1 ELSE 0 END) as activated_count FROM summits GROUP BY planning_group_id");
    foreach ($stmt->fetchAll() as $row) {
        $group_counts[$row['planning_group_id']] = $row;
    }
} catch (PDOException $e) {}

// Get selected group
$managing_group_id = $_SESSION['manage_group_id'] ?? null;
$managing_group = null;
$addresses = [];
$selected_address_id = null;

$group_members = [];
$is_group_owner = false;

if ($managing_group_id) {
    $stmt = $db->prepare("SELECT * FROM planning_groups WHERE id = ?");
    $stmt->execute([$managing_group_id]);
    $managing_group = $stmt->fetch();

    if ($managing_group) {
        // Verify current user has access to this group
        $stmt = $db->prepare("
            SELECT pg.id FROM planning_groups pg
            LEFT JOIN planning_group_members pgm ON pg.id = pgm.planning_group_id
            WHERE pg.id = ? AND (pg.owner_callsign = ? OR pgm.callsign = ?)
            LIMIT 1
        ");
        $stmt->execute([$managing_group_id, $current_callsign, $current_callsign]);
        if (!$stmt->fetch()) {
            $managing_group = null; // No access — treat as unselected
            $_SESSION['manage_group_id'] = null;
        }
    }

    if ($managing_group) {
        $stmt = $db->prepare("SELECT * FROM addresses WHERE planning_group_id = ? ORDER BY label, address");
        $stmt->execute([$managing_group_id]);
        $addresses = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM planning_group_members WHERE planning_group_id = ? ORDER BY role DESC, callsign ASC");
        $stmt->execute([$managing_group_id]);
        $group_members = $stmt->fetchAll();

        $is_group_owner = ($managing_group['owner_callsign'] === $current_callsign);

        // Get selected address for this group
        $setting_key = 'selected_address_group_' . $managing_group_id;
        $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $stmt->execute([$setting_key]);
        $row = $stmt->fetch();
        $selected_address_id = $row ? (int)$row['setting_value'] : null;
    }
}

// Check if this is first visit (no group selected)
$is_first_visit = !$managing_group_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Groups & Addresses — SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
:root {
    --bg:           #F7F6F3;
    --bg-2:         #EFEDE8;
    --bg-3:         #E5E2DA;
    --ink:          #1C1B19;
    --ink-2:        #4A4844;
    --ink-3:        #8C8A86;
    --ink-4:        #B8B5B0;
    --accent:       oklch(52% 0.13 50);
    --accent-2:     oklch(44% 0.13 50);
    --accent-bg:    oklch(96% 0.04 65);
    --accent-border:oklch(84% 0.08 65);
    --green:        oklch(52% 0.13 155);
    --green-bg:     oklch(95% 0.04 155);
    --red:          oklch(52% 0.16 22);
    --red-bg:       oklch(96% 0.04 22);
    --surface:      #FFFFFF;
    --border:       #E5E2DA;
    --border-2:     #D4D0C8;
    --font-sans:    'DM Sans', system-ui, sans-serif;
    --font-mono:    'DM Mono', 'Courier New', monospace;
    --r-sm: 4px;
    --r-md: 8px;
    --r-lg: 12px;
    --r-xl: 16px;
    --shadow-sm: 0 1px 3px rgba(28,27,25,0.07), 0 1px 2px rgba(28,27,25,0.05);
    --shadow-md: 0 4px 12px rgba(28,27,25,0.08), 0 2px 4px rgba(28,27,25,0.05);
    --shadow-lg: 0 8px 24px rgba(28,27,25,0.10), 0 4px 8px rgba(28,27,25,0.06);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; -webkit-font-smoothing: antialiased; }
body { font-family: var(--font-sans); background: var(--bg); color: var(--ink); line-height: 1.5; min-height: 100vh; }

h1, h2, h3 { font-weight: 600; line-height: 1.2; }
p { line-height: 1.65; color: var(--ink-2); }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* Topbar */
.topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    height: 56px;
    display: flex;
    align-items: center;
    padding: 0 2rem;
    gap: 1.5rem;
    position: sticky;
    top: 0;
    z-index: 100;
}
.topbar-logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
    color: var(--ink);
    font-weight: 600;
    font-size: 0.95rem;
    letter-spacing: -0.01em;
    flex-shrink: 0;
}
.topbar-logo:hover { text-decoration: none; color: var(--ink); }
.topbar-divider { width: 1px; height: 20px; background: var(--border); flex-shrink: 0; }
.topbar-nav { display: flex; align-items: center; gap: 0.25rem; flex: 1; }
.topbar-nav a {
    color: var(--ink-3);
    font-size: 0.875rem;
    font-weight: 500;
    padding: 0.375rem 0.75rem;
    border-radius: var(--r-sm);
    transition: color 0.15s, background 0.15s;
    text-decoration: none;
    white-space: nowrap;
}
.topbar-nav a:hover { color: var(--ink); background: var(--bg-2); text-decoration: none; }
.topbar-nav a.active { color: var(--ink); background: var(--bg-2); }
.topbar-right { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; }

/* Page */
.page { padding: 2rem; max-width: 960px; margin: 0 auto; }
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.page-title { font-size: 1.4rem; font-weight: 600; letter-spacing: -0.02em; color: var(--ink); }
.page-subtitle { font-size: 0.875rem; color: var(--ink-3); margin-top: 0.25rem; }

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0 1rem;
    height: 36px;
    border-radius: var(--r-md);
    font-family: var(--font-sans);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: background 0.15s, box-shadow 0.15s, transform 0.1s;
    text-decoration: none;
    white-space: nowrap;
    line-height: 1;
}
.btn:hover { text-decoration: none; }
.btn:active { transform: scale(0.98); }
.btn-primary { background: var(--ink); color: #fff; }
.btn-primary:hover { background: var(--ink-2); color: #fff; }
.btn-accent { background: var(--accent); color: #fff; }
.btn-accent:hover { background: var(--accent-2); color: #fff; }
.btn-secondary { background: var(--bg-2); color: var(--ink); border: 1px solid var(--border); }
.btn-secondary:hover { background: var(--bg-3); color: var(--ink); }
.btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg-2); color: var(--ink); }
.btn-sm { height: 30px; padding: 0 0.75rem; font-size: 0.8rem; }
.user-chip {
    position: relative; display: flex; align-items: center; gap: 0.35rem;
    cursor: pointer; padding: 0.25rem 0.6rem;
    border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: var(--ink-2);
    border: 1px solid var(--border); background: var(--bg); user-select: none; white-space: nowrap;
}
.user-chip:hover { background: var(--bg-2); }
.user-chip-chevron { transition: transform 0.15s; flex-shrink: 0; }
.user-chip.open .user-chip-chevron { transform: rotate(180deg); }
.user-dropdown {
    display: none; position: absolute; top: calc(100% + 6px); right: 0;
    background: #fff; border: 1px solid var(--border);
    border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    min-width: 130px; overflow: hidden; z-index: 200;
}
.user-chip.open .user-dropdown { display: block; }
.user-dropdown a {
    display: block; padding: 0.6rem 1rem;
    font-size: 0.82rem; font-weight: 500; color: var(--ink-2); text-decoration: none;
}
.user-dropdown a:hover { background: var(--bg-2); color: var(--ink); }

/* Messages */
.msg {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1rem;
    border-radius: var(--r-md);
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 1rem;
}
.msg-success { background: var(--green-bg); color: var(--green); border: 1px solid oklch(82% 0.08 155); }
.msg-error   { background: var(--red-bg);   color: var(--red);   border: 1px solid oklch(82% 0.08 22); }
.msg-dismiss { background: none; border: none; cursor: pointer; color: inherit; opacity: 0.5; font-size: 1.1rem; padding: 0; line-height: 1; flex-shrink: 0; }
.msg-dismiss:hover { opacity: 1; }

/* Card */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
}

/* Section head */
.section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.section-head h2 { font-size: 1rem; font-weight: 600; }

/* Form */
.form-group { margin-bottom: 1.25rem; }
.form-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 500;
    color: var(--ink-2);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.form-input, .form-select {
    display: block;
    width: 100%;
    padding: 0.625rem 1rem;
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: var(--r-md);
    font-family: var(--font-sans);
    font-size: 0.9375rem;
    color: var(--ink);
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    -webkit-appearance: none;
}
.form-input:focus, .form-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px oklch(85% 0.07 55 / 0.3);
}
.form-input::placeholder { color: var(--ink-4); }
.form-hint { font-size: 0.8rem; color: var(--ink-3); margin-top: 0.5rem; line-height: 1.5; }
.form-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238C8A86' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 2rem;
    cursor: pointer;
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(28,27,25,0.5);
    z-index: 200;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--surface);
    border-radius: var(--r-xl);
    padding: 2rem;
    max-width: 520px;
    width: 92%;
    position: relative;
    box-shadow: var(--shadow-lg);
    max-height: 90vh;
    overflow-y: auto;
}
.modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    color: var(--ink-3);
    line-height: 1;
    padding: 0.25rem;
    border-radius: var(--r-sm);
    transition: background 0.1s, color 0.1s;
}
.modal-close:hover { background: var(--bg-2); color: var(--ink); }
.modal-title { font-size: 1.15rem; font-weight: 600; margin-bottom: 1.5rem; }
.modal-subtitle { font-size: 0.875rem; color: var(--ink-3); margin-top: 0.25rem; margin-bottom: 1.5rem; }

/* Groups grid */
.groups-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.5rem;
    align-items: start;
}

/* Group list */
.group-list-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-radius: var(--r-md);
    cursor: pointer;
    transition: background 0.1s;
    gap: 0.5rem;
    width: 100%;
    border: none;
    background: none;
    font-family: var(--font-sans);
    text-align: left;
}
.group-list-item:hover { background: var(--bg-2); }
.group-list-item.active { background: var(--accent-bg); }
.group-list-item.active .gli-name { color: var(--accent); font-weight: 600; }
.gli-name { font-size: 0.9rem; font-weight: 500; color: var(--ink); }
.gli-meta { font-size: 0.72rem; color: var(--ink-3); margin-top: 2px; }
.gli-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--accent); flex-shrink: 0;
}

/* Address rows */
.address-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    background: var(--surface);
    transition: border-color 0.15s;
}
.address-row + .address-row { margin-top: 0.5rem; }
.address-row:hover { border-color: var(--border-2); }
.address-row.is-current { border-color: var(--accent); background: var(--accent-bg); }
.addr-icon {
    width: 32px; height: 32px; border-radius: 50%;
    background: var(--bg-2); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; color: var(--ink-3); flex-shrink: 0;
}
.addr-icon.current { background: var(--ink); color: #fff; border-color: var(--ink); }
.addr-label { font-size: 0.875rem; font-weight: 500; color: var(--ink); }
.addr-text { font-size: 0.78rem; color: var(--ink-3); margin-top: 1px; }
.addr-actions { margin-left: auto; display: flex; gap: 0.5rem; align-items: center; }

/* Footer */
.footer {
    text-align: center;
    padding: 2rem 1rem 1.5rem;
    color: var(--ink-4);
    font-size: 0.78rem;
    border-top: 1px solid var(--border);
    margin-top: 3rem;
}
.footer a { color: var(--ink-3); }
.footer a:hover { color: var(--ink); }

@media (max-width: 640px) {
    .topbar { padding: 0 1rem; }
    .topbar-nav { display: none; }
    .page { padding: 1rem; }
    .groups-grid { grid-template-columns: 1fr; }
    .addr-actions { flex-direction: column; align-items: flex-end; gap: 0.25rem; }
}
    </style>
</head>
<body>

<nav class="topbar">
    <a href="index.php" class="topbar-logo">
        <img src="sota-planner-logo.svg" width="32" height="32" alt="">
        <span>SOTAplanner</span>
    </a>
    <div class="topbar-divider"></div>
    <div class="topbar-nav">
        <a href="index.php">Dashboard</a>
        <a href="planning_groups.php" class="active">Groups &amp; Addresses</a>
    </div>
    <div class="topbar-right">
        <div class="user-chip" id="userChip">
            <?= htmlspecialchars($current_callsign) ?>
            <svg class="user-chip-chevron" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polyline points="2,3.5 5,6.5 8,3.5"/></svg>
            <div class="user-dropdown">
                <?php if (($current_callsign ?? '') === 'KI6CR' || !empty($_SESSION['_god_mode_real_callsign'])): ?>
                    <a href="god_mode.php">God Mode</a>
                <?php endif; ?>
                <a href="logout.php">Sign Out</a>
            </div>
        </div>
    </div>
</nav>

<?php if (!empty($_SESSION['_god_mode_real_callsign'])): ?>
<div style="background:oklch(52% 0.16 22); color:#fff; text-align:center; padding:0.5rem 1rem; font-size:0.82rem; font-weight:600; display:flex; align-items:center; justify-content:center; gap:1rem;">
    ⚠️ Impersonating <strong><?= htmlspecialchars($_SESSION['sota_callsign'] ?? '') ?></strong>
    <a href="god_mode.php" style="color:#fff; background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.4); padding:0.2rem 0.75rem; border-radius:4px; font-size:0.78rem; text-decoration:none;">Return to God Mode</a>
</div>
<?php endif; ?>
<?php if (!empty($_sitewide_banner['text'])): ?>
<div style="background:var(--<?= $_sitewide_banner['type'] === 'success' ? 'green' : ($_sitewide_banner['type'] === 'error' ? 'red' : 'accent') ?>-bg); border-bottom:1px solid var(--border); padding:0.6rem var(--sp-8); font-size:0.85rem; font-weight:500; color:var(--ink-2); text-align:center;">
    <?= htmlspecialchars($_sitewide_banner['text']) ?>
</div>
<?php endif; ?>

<div class="page">
    <div class="page-header">
        <div>
            <div class="page-title">Groups &amp; Addresses</div>
            <div class="page-subtitle">Manage planning groups and their starting addresses for drive-time calculations.</div>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('createGroupModal').classList.add('open')">+ New Group</button>
    </div>

    <?php if ($message): ?>
        <div class="msg msg-success">
            <span><?= htmlspecialchars($message) ?></span>
            <button class="msg-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="msg msg-error">
            <span><?= htmlspecialchars($error) ?></span>
            <button class="msg-dismiss" onclick="this.parentElement.remove()">×</button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['welcome']) && count($all_groups) === 0): ?>
    <div class="msg" style="background: linear-gradient(135deg, #f0f7ff 0%, #fdf6ec 100%); border: 1.5px solid #c8dff5; border-radius: 12px; padding: 1.5rem 1.75rem; margin-bottom: 1.5rem; display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap;">
        <div style="font-size: 2rem; line-height: 1;">⛰️</div>
        <div style="flex: 1; min-width: 200px;">
            <div style="font-size: 1rem; font-weight: 800; color: #1E3A5F; margin-bottom: 0.35rem;">Welcome to SOTA Planner, <?= htmlspecialchars($current_callsign) ?>!</div>
            <div style="font-size: 0.875rem; color: #444; line-height: 1.55; margin-bottom: 1rem;">
                You don't have any planning groups yet. Planning groups are how you organize your summit wishlist — each group can have its own members, addresses, and summits.
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                <button class="btn btn-primary" onclick="document.getElementById('createGroupModal').classList.add('open')" style="font-size: 0.875rem;">
                    + Create my first group
                </button>
                <div style="font-size: 0.82rem; color: #666; line-height: 1.4;">
                    Or, ask a group owner to add your callsign (<strong><?= htmlspecialchars($current_callsign) ?></strong>) to their group — it'll appear here automatically next time you sign in.
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="groups-grid">

        <!-- Sidebar: Group list -->
        <div class="card" style="padding: 0.5rem;">
            <div style="padding: 0.5rem 0.75rem 0.375rem; margin-bottom: 0.25rem;">
                <div style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-3);">Planning Groups</div>
            </div>

            <?php if (count($all_groups) > 0): ?>
                <?php foreach ($all_groups as $group): ?>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                        <input type="hidden" name="select_group" value="1">
                        <button type="submit" class="group-list-item<?= ($managing_group_id == $group['id']) ? ' active' : '' ?>">
                            <div style="min-width: 0;">
                                <div class="gli-name"><?= htmlspecialchars($group['name']) ?></div>
                                <?php
                                    $counts = $group_counts[$group['id']] ?? null;
                                    $total = $counts ? (int)$counts['total'] : 0;
                                    $activated = $counts ? (int)$counts['activated_count'] : 0;
                                ?>
                                <div class="gli-meta"><?= $total ?> summit<?= $total !== 1 ? 's' : '' ?> · <?= $activated ?> activated</div>
                            </div>
                            <?php if ($managing_group_id == $group['id']): ?>
                                <div class="gli-dot"></div>
                            <?php endif; ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 1.25rem 1rem; text-align: center; color: var(--ink-3); font-size: 0.875rem;">
                    No groups yet
                </div>
            <?php endif; ?>

            <div style="border-top: 1px solid var(--border); margin: 0.5rem 0 0;">
                <button
                    class="group-list-item"
                    style="color: var(--accent); font-size: 0.85rem; font-weight: 500;"
                    onclick="document.getElementById('createGroupModal').classList.add('open')"
                >
                    <span>+ New group</span>
                </button>
            </div>
        </div>

        <!-- Detail panel -->
        <?php if ($managing_group): ?>
        <div>
            <!-- Group info card -->
            <div class="card" style="margin-bottom: 1.25rem;">
                <div class="section-head">
                    <h2><?= htmlspecialchars($managing_group['name']) ?></h2>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="group_id" value="<?= $managing_group['id'] ?>">
                        <button type="submit" name="activate_group" class="btn btn-accent btn-sm">Open Dashboard →</button>
                    </form>
                </div>
                <div style="display: flex; gap: 2rem; flex-wrap: wrap; padding-top: 0.75rem; border-top: 1px solid var(--border);">
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: var(--ink-3); margin-bottom: 3px;">Units</div>
                        <div style="font-size: 0.875rem; color: var(--ink); text-transform: capitalize;"><?= htmlspecialchars($managing_group['units']) ?></div>
                    </div>
                    <?php $counts = $group_counts[$managing_group['id']] ?? null; ?>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: var(--ink-3); margin-bottom: 3px;">Summits</div>
                        <div style="font-size: 0.875rem; color: var(--ink);"><?= $counts ? (int)$counts['total'] : 0 ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.07em; color: var(--ink-3); margin-bottom: 3px;">Activated</div>
                        <div style="font-size: 0.875rem; color: var(--ink);"><?= $counts ? (int)$counts['activated_count'] : 0 ?></div>
                    </div>
                </div>
            </div>

            <!-- Addresses card -->
            <div class="card" id="addresses">
                <div class="section-head">
                    <div>
                        <h2>Starting Addresses</h2>
                        <p style="font-size: 0.8rem; color: var(--ink-3); margin-top: 3px;">Drive times are calculated from the current address.</p>
                    </div>
                    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('addressModal').classList.add('open')">+ Add Address</button>
                </div>

                <?php if (count($addresses) === 0): ?>
                    <div style="text-align: center; padding: 2rem 1rem; background: var(--bg-2); border-radius: var(--r-md); border: 1px dashed var(--border-2);">
                        <div style="font-size: 1.5rem; margin-bottom: 0.75rem; opacity: 0.3;">📍</div>
                        <div style="font-weight: 500; font-size: 0.9rem; margin-bottom: 0.4rem; color: var(--ink);">No addresses yet</div>
                        <div style="font-size: 0.8rem; color: var(--ink-3); margin-bottom: 1rem; max-width: 34ch; margin-left: auto; margin-right: auto;">Add a home address, cross street, or any starting point to calculate drive times to summits.</div>
                        <button class="btn btn-secondary btn-sm" onclick="document.getElementById('addressModal').classList.add('open')">Add a Location</button>
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach ($addresses as $addr): ?>
                            <div class="address-row<?= ($selected_address_id && $selected_address_id == $addr['id']) ? ' is-current' : '' ?>">
                                <div class="addr-icon<?= ($selected_address_id && $selected_address_id == $addr['id']) ? ' current' : '' ?>">
                                    <?= ($selected_address_id && $selected_address_id == $addr['id']) ? '★' : '○' ?>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <?php if ($addr['label']): ?>
                                        <div class="addr-label"><?= htmlspecialchars($addr['label']) ?></div>
                                        <div class="addr-text"><?= htmlspecialchars($addr['address']) ?></div>
                                    <?php else: ?>
                                        <div class="addr-label"><?= htmlspecialchars($addr['address']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="addr-actions">
                                    <?php if (!$selected_address_id || $selected_address_id != $addr['id']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                                            <button type="submit" name="set_current_address" class="btn btn-secondary btn-sm">Set Current</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 0.72rem; color: var(--accent); font-weight: 600;">Current</span>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                                        <button type="submit" name="delete_address" class="btn btn-ghost btn-sm"
                                                style="color: var(--red); border-color: oklch(85% 0.06 22);"
                                                onclick="return confirm('Delete this address?')">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <p class="form-hint" style="margin-top: 0.75rem;">
                            Tip: use nearby cross streets instead of your exact address for privacy.
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Group Members Card -->
            <div class="card" style="margin-top: 1.25rem;">
                <div class="section-head">
                    <div>
                        <h2>Group Members</h2>
                        <p style="font-size: 0.8rem; color: var(--ink-3); margin-top: 3px;">Members can see this group's summits and addresses when they log in.</p>
                    </div>
                    <button onclick="document.getElementById('memberModal').classList.add('open')" class="btn btn-secondary btn-sm">+ Add Member</button>
                </div>

                <?php if (count($group_members) > 0): ?>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <?php foreach ($group_members as $mem): ?>
                            <div class="address-row">
                                <div class="addr-icon"><?= strtoupper(substr($mem['callsign'], 0, 2)) ?></div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="addr-label"><?= htmlspecialchars($mem['callsign']) ?></div>
                                    <div class="addr-text">
                                        <?= $mem['role'] === 'owner' ? 'Owner' : 'Member' ?>
                                        <?php if ($mem['invited_by']): ?> · invited by <?= htmlspecialchars($mem['invited_by']) ?><?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($is_group_owner && $mem['role'] !== 'owner'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="remove_callsign" value="<?= htmlspecialchars($mem['callsign']) ?>">
                                        <button type="submit" name="remove_member" class="btn btn-ghost btn-sm"
                                                style="color: var(--red); border-color: oklch(85% 0.06 22);"
                                                onclick="return confirm('Remove <?= htmlspecialchars($mem['callsign']) ?> from the group?')">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="form-hint">No members yet. Add callsigns to share this group.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <!-- No group selected -->
        <div class="card" style="text-align: center; padding: 3rem 2rem; color: var(--ink-3);">
            <div style="font-size: 1.5rem; margin-bottom: 0.75rem; opacity: 0.25;">⛰</div>
            <div style="font-weight: 500; font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--ink);">
                <?= count($all_groups) > 0 ? 'Select a group from the list' : 'Create your first group to get started' ?>
            </div>
            <p style="font-size: 0.85rem; max-width: 36ch; margin: 0 auto 1.25rem;">
                A planning group holds your summit wishlist, addresses, and drive-time calculations.
            </p>
            <?php if (count($all_groups) === 0): ?>
                <button class="btn btn-primary" onclick="document.getElementById('createGroupModal').classList.add('open')">Create a Group</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- .groups-grid -->
</div><!-- .page -->

<footer class="footer">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com">sotaplanner.com</a>
</footer>

<!-- Create Group Modal -->
<div id="createGroupModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('createGroupModal').classList.remove('open')">×</button>
        <div class="modal-title">Create Planning Group</div>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Group Name</label>
                <input type="text" name="group_name" class="form-input" placeholder="e.g., KI6CR & Friends, Weekend Warriors" required autofocus>
                <div class="form-hint">Name it after your crew or callsign</div>
            </div>
            <div class="form-group">
                <label class="form-label">Preferred Units</label>
                <select name="units" class="form-select">
                    <option value="imperial">Imperial (miles, feet)</option>
                    <option value="metric">Metric (km, meters)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Co-activators <span style="font-weight:400; color:var(--ink-4);">(optional)</span></label>
                <input type="text" name="member_callsigns" class="form-input" placeholder="e.g. K3MGM, N6ARA, W6CMY">
                <div class="form-hint">Comma-separated. Each callsign will see this group when they log in.</div>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button type="submit" name="create_group" class="btn btn-primary" style="flex: 1;">Create Group</button>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('createGroupModal').classList.remove('open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Member Modal -->
<div id="memberModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('memberModal').classList.remove('open')">×</button>
        <div class="modal-title">Add Group Member</div>
        <?php if ($managing_group): ?>
            <p class="modal-subtitle">For <strong><?= htmlspecialchars($managing_group['name']) ?></strong></p>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Callsign(s)</label>
                <input type="text" name="new_member_callsign" class="form-input" placeholder="e.g. K3MGM, N6ARA, W6CMY" autocapitalize="characters" required>
                <div class="form-hint">Separate multiple callsigns with commas. Each person will see this group when they log in.</div>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button type="submit" name="add_member" class="btn btn-primary" style="flex: 1;">Add Member</button>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('memberModal').classList.remove('open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Address Modal -->
<div id="addressModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('addressModal').classList.remove('open')">×</button>
        <div class="modal-title">Add Starting Location</div>
        <?php if ($managing_group): ?>
            <p class="modal-subtitle">For <strong><?= htmlspecialchars($managing_group['name']) ?></strong></p>
        <?php endif; ?>
        <p style="font-size: 0.85rem; color: var(--ink-2); margin-bottom: 1rem; line-height: 1.5;">SOTA Planner uses this to calculate accurate drive time estimates from your starting point to each summit's trailhead — so you can see the full door-to-door time for an activation.</p>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Label (optional)</label>
                <input type="text" name="label" class="form-input" placeholder="e.g., Home, Work, Cabin">
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-input" placeholder="e.g., 97201, Oak & Main Portland, Starbucks Bend OR" required>
                <div class="form-hint">Anything Google Maps can find — zip code, cross streets, a business name, or a full address. No need to use your home address.</div>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button type="submit" name="add_address" class="btn btn-primary" style="flex: 1;">Add Address</button>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addressModal').classList.remove('open')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($open_address_modal) && $managing_group): ?>
<script>
    window.addEventListener('load', function() {
        document.getElementById('addressModal').classList.add('open');
    });
</script>
<?php endif; ?>

<?php if (false && !empty($scroll_to_addresses) && $managing_group): ?>
<script>
    window.addEventListener('load', function() {
        var el = document.getElementById('addresses');
        if (el) el.scrollIntoView({behavior: 'smooth', block: 'start'});
    });
</script>
<?php endif; ?>

<?php if ($is_first_visit && count($all_groups) === 0): ?>
<script>
    window.addEventListener('load', function() {
        document.getElementById('createGroupModal').classList.add('open');
    });
</script>
<?php endif; ?>

<script>
(function() {
    var chip = document.getElementById('userChip');
    if (!chip) return;
    chip.addEventListener('click', function(e) { e.stopPropagation(); this.classList.toggle('open'); });
    document.addEventListener('click', function() { chip.classList.remove('open'); });
})();
</script>
</body>
</html>
