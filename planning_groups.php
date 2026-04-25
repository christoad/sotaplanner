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

                $message = "Planning group created! Now add a starting location for your group below.";
                $scroll_to_addresses = true;
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
        $message = "✓ Current address updated!";
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
        $stmt = $db->prepare("SELECT owner_callsign FROM planning_groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $grp = $stmt->fetch();
        if ($grp && $grp['owner_callsign'] === $current_callsign && $raw !== '') {
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
            $error = "Only the group owner can add members.";
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

// Get selected group
$managing_group_id = $_SESSION['manage_group_id'] ?? null;
$managing_group = null;
$addresses = [];

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
    <title>Planning Groups & Addresses - SOTA Planner</title>
    <link href="https://fonts.googleapis.com/css2?family=Overpass:wght@300;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #1E3A5F;
            --teal: #4A90A4;
            --gold: #E6B84A;
            --snow: #F5F5F0;
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
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .logo {
            height: 80px;
            margin-bottom: 1.5rem;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 0.5rem;
        }

        .subtitle {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .blue-box {
            background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%);
            padding: 2.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .message.success {
            background: #E8F5E9;
            color: #2E7D32;
        }

        .message.error {
            background: #FFEBEE;
            color: #C62828;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: white;
            font-size: 1rem;
        }

        .card label {
            color: var(--navy);
        }

        select, input[type="text"] {
            width: 100%;
            padding: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Overpass', sans-serif;
        }

        .big-select {
            font-size: 1.5rem;
            padding: 1.5rem;
            font-weight: 700;
            border: 3px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.95);
            color: var(--navy);
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Overpass', sans-serif;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #E6B84A 0%, #D4A574 100%);
            color: white;
            font-size: 1.3rem;
            padding: 1.25rem 3rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        }

        .btn-secondary {
            background: var(--teal);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--navy);
        }

        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .address-list {
            list-style: none;
            padding: 0;
        }

        .address-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }

        .address-info strong {
            color: var(--navy);
            display: block;
            margin-bottom: 0.25rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 2.5rem;
            border-radius: 12px;
            max-width: 700px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #666;
            line-height: 1;
        }

        .modal-close:hover {
            color: #000;
        }

        .help-text {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 1rem; margin-bottom: 1rem; font-size: 0.85rem; color: #888;">
            <span>Signed in as <strong><?= htmlspecialchars($current_callsign) ?></strong></span>
            <a href="index.php" style="color: var(--teal); text-decoration: none; font-weight: 600;">← Dashboard</a>
            <a href="logout.php" style="color: #aaa; text-decoration: none;">Sign Out</a>
        </div>

        <div style="text-align: center; margin-bottom: 2rem;">
            <img src="logo.png" alt="SOTA Planner" class="logo">
            <h1>Planning Groups</h1>
            <p class="subtitle">A <strong>planning group</strong> can be just you, or your whole activator crew. Either way it holds a shared summit wishlist, starting addresses, and drive-time estimates. Select a group below or create a new one.</p>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Step 1: Two-path layout -->
        <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 0; align-items: stretch; margin-bottom: 2rem;">

            <!-- Option A: Select existing -->
            <div class="blue-box" style="margin-bottom: 0; border-radius: 12px 0 0 12px;">
                <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255,255,255,0.6); margin-bottom: 0.5rem;">
                    <?= count($all_groups) > 0 ? 'Returning?' : 'Have a group?' ?>
                </div>
                <h2 style="color: white; font-size: 1.4rem; margin-bottom: 0.5rem;">Join an Existing Group</h2>
                <p style="color: rgba(255,255,255,0.65); font-size: 0.82rem; margin-bottom: 1.1rem;">Select a group you belong to, then go to the dashboard to plan activations.</p>
                <?php if (count($all_groups) > 0): ?>
                    <form method="POST" id="groupForm">
                        <select name="group_id" class="big-select" style="font-size: 1.1rem; padding: 1rem;" onchange="this.form.submit()">
                            <option value="">Choose a group...</option>
                            <?php foreach ($all_groups as $group): ?>
                                <option value="<?= $group['id'] ?>" <?= ($managing_group && $managing_group['id'] == $group['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($group['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="select_group" value="1">
                    </form>
                    <?php if ($managing_group): ?>
                        <div style="margin-top: 1.25rem;">
                            <form method="POST">
                                <input type="hidden" name="group_id" value="<?= $managing_group['id'] ?>">
                                <button type="submit" name="activate_group" class="btn btn-primary" style="width: 100%; text-align: center;">
                                    Go to Dashboard — <?= htmlspecialchars($managing_group['name']) ?> →
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: rgba(255,255,255,0.7); font-size: 0.95rem;">No groups yet — create one to get started.</p>
                <?php endif; ?>
            </div>

            <!-- OR divider -->
            <div style="display: flex; align-items: center; justify-content: center; background: #c8c4ba; padding: 0 1.25rem; min-width: 70px;">
                <span style="font-weight: 800; font-size: 1.1rem; color: #5a5650; letter-spacing: 0.12em;">OR</span>
            </div>

            <!-- Option B: Create new -->
            <div class="card" style="margin-bottom: 0; border-radius: 0 12px 12px 0; border-left: 3px solid #e0ddd5;">
                <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #aaa; margin-bottom: 0.5rem;">New here?</div>
                <h2 style="color: var(--navy); font-size: 1.4rem; margin-bottom: 0.5rem;">Create a New Group</h2>
                <p style="color: #888; font-size: 0.82rem; margin-bottom: 1.1rem;">Give your group a name, then add your crew's callsigns so they can access it too.</p>
                <form method="POST">
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label style="color: var(--navy);">Group Name</label>
                        <input type="text" name="group_name" placeholder="e.g., KI6CR and Friends, Weekend Warriors" required>
                        <p class="help-text">Your callsign, a group nickname, whatever works</p>
                    </div>
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label style="color: var(--navy);">Preferred Units</label>
                        <select name="units">
                            <option value="imperial">Imperial (miles, feet)</option>
                            <option value="metric">Metric (km, meters)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label style="color: var(--navy);">Your Crew's Callsigns <span style="font-weight:400; color:#999;">(optional)</span></label>
                        <input type="text" name="member_callsigns" placeholder="e.g. K3MGM, N6ARA, W6CMY, WZ1EEE">
                        <p class="help-text">Comma-separated. Each callsign will see this group when they log in.</p>
                    </div>
                    <button type="submit" name="create_group" class="btn btn-secondary" style="width: 100%;">
                        ➕ Create Group
                    </button>
                </form>
            </div>
        </div>

        <!-- Manage Addresses (only show if group selected) -->
        <?php if ($managing_group): ?>
            <div class="card" id="addresses">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h2 style="color: var(--navy); margin: 0;">
                        Addresses for "<?= htmlspecialchars($managing_group['name']) ?>"
                    </h2>
                    <button onclick="document.getElementById('addressModal').style.display='flex'" class="btn btn-secondary">
                        ➕ Add Address
                    </button>
                </div>

                <?php if (count($addresses) > 0): ?>
                    <ul class="address-list">
                        <?php foreach ($addresses as $addr): ?>
                            <li class="address-item">
                                <div class="address-info">
                                    <?php if ($addr['label']): ?>
                                        <strong><?= htmlspecialchars($addr['label']) ?></strong>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($addr['address']) ?></span>
                                    <?php if (isset($selected_address_id) && $selected_address_id == $addr['id']): ?>
                                        <span style="display: inline-block; margin-left: 0.5rem; padding: 0.25rem 0.75rem; background: var(--trail-green); color: white; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">
                                            ✓ Current
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    <?php if (!isset($selected_address_id) || $selected_address_id != $addr['id']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                                            <button type="submit" name="set_current_address" class="btn btn-secondary" style="background: var(--teal); color: white;">
                                                📍 Set as Current
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                                        <button type="submit" name="delete_address" class="btn btn-danger" onclick="return confirm('Delete this address?')">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div style="text-align: center; padding: 2rem; background: #f0f7f4; border-radius: 8px; border: 2px dashed #4A7C59;">
                        <div style="font-size: 2rem; margin-bottom: 0.75rem;">📍</div>
                        <p style="font-weight: 700; color: #2C4A3E; margin-bottom: 0.5rem;">Add your first starting location</p>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.25rem;">This can be a home address, a park-and-ride, or any place your group typically drives from. Drive times to summits will be calculated from this location.</p>
                        <button onclick="document.getElementById('addressModal').style.display='flex'" class="btn btn-secondary">
                            ➕ Add a Location
                        </button>
                    </div>
                <?php endif; ?>

                <p class="help-text" style="margin-top: 1.5rem;">
                    💡 Add your home, work, or any starting location. For privacy, you can use nearby cross streets instead of your exact address. Drive times to summits will be calculated from the selected address.
                </p>
            </div>

            <!-- Group Members Card -->
            <div class="card" style="margin-top: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
                    <h2 style="color: var(--navy); margin: 0;">Group Members</h2>
                    <?php if ($is_group_owner): ?>
                        <button onclick="document.getElementById('memberModal').style.display='flex'" class="btn btn-secondary" style="padding: 0.5rem 1.1rem; font-size: 0.88rem;">
                            ➕ Add Member
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (count($group_members) > 0): ?>
                    <ul class="address-list">
                        <?php foreach ($group_members as $mem): ?>
                            <li class="address-item">
                                <div class="address-info">
                                    <strong><?= htmlspecialchars($mem['callsign']) ?></strong>
                                    <span style="font-size: 0.82rem; color: #888;">
                                        <?= $mem['role'] === 'owner' ? 'Owner' : 'Member' ?>
                                        <?php if ($mem['invited_by']): ?>
                                            &nbsp;·&nbsp; invited by <?= htmlspecialchars($mem['invited_by']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($is_group_owner && $mem['role'] !== 'owner'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="remove_callsign" value="<?= htmlspecialchars($mem['callsign']) ?>">
                                        <button type="submit" name="remove_member" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;" onclick="return confirm('Remove <?= htmlspecialchars($mem['callsign']) ?> from the group?')">
                                            Remove
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="color: #888; font-size: 0.9rem;">No members yet. Add callsigns to share this group.</p>
                <?php endif; ?>

                <p class="help-text" style="margin-top: 1rem;">
                    Members can see this group's summits and addresses when they log in with their callsign.
                </p>
            </div>
        <?php endif; ?>

        <!-- Add Member Modal -->
        <div id="memberModal" class="modal">
            <div class="modal-content" style="max-width: 440px;">
                <button class="modal-close" onclick="document.getElementById('memberModal').style.display='none'">×</button>
                <h2 style="color: var(--navy); margin-bottom: 1.25rem;">Add Group Member</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>Callsign(s)</label>
                        <input type="text" name="new_member_callsign" placeholder="e.g. K3MGM, N6ARA, W6CMY, WZ1EEE" autocapitalize="characters" required>
                        <p class="help-text">Separate multiple callsigns with commas. Each person will see this group when they log in.</p>
                    </div>
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" name="add_member" class="btn btn-secondary">Add Member</button>
                        <button type="button" onclick="document.getElementById('memberModal').style.display='none'" class="btn" style="background: #ddd; color: #666;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Address Modal -->
        <div id="addressModal" class="modal">
            <div class="modal-content">
                <button class="modal-close" onclick="document.getElementById('addressModal').style.display='none'">×</button>
                <h2 style="color: var(--navy); margin-bottom: 1.5rem;">Add Address</h2>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Label (optional):</label>
                        <input type="text" name="label" placeholder="e.g., Home, Work, Cabin">
                        <p class="help-text">Give this address a friendly name</p>
                    </div>

                    <div class="form-group">
                        <label>Address:</label>
                        <input type="text" name="address" placeholder="123 Main St, City, CA 12345" required>
                        <p class="help-text">Enter a city, cross streets, or full address that Google Maps can find</p>
                    </div>

                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" name="add_address" class="btn btn-secondary">
                            ➕ Add Address
                        </button>
                        <button type="button" onclick="document.getElementById('addressModal').style.display='none'" class="btn" style="background: #ddd; color: #666;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php if (!empty($scroll_to_addresses) && $managing_group): ?>
<script>
    window.addEventListener('load', function() {
        var el = document.getElementById('addresses');
        if (el) el.scrollIntoView({behavior: 'smooth', block: 'start'});
    });
</script>
<?php endif; ?>
<footer style="text-align:center; padding:2rem 1rem 1.5rem; color:#aaa; font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:#aaa; text-decoration:none;">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:#aaa; text-decoration:none;">sotaplanner.com</a>
</footer>
</body>
</html>
