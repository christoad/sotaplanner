<?php
/**
 * SSO Login DB Migration — run once, then delete or restrict access.
 *
 * Changes:
 *  1. Adds owner_callsign column to planning_groups
 *  2. Creates planning_group_members table
 *  3. Sets KI6CR as owner of all existing groups
 *  4. Inserts KI6CR as owner member for all existing groups
 *
 * Access: requires the dev password to prevent accidental re-runs.
 */

require_once 'config.php';
session_start();

$authorized = false;
$results    = [];
$done       = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['pass'] ?? '') === 'sota') {
    $authorized = true;

    $db = getDbConnection();

    // 1. Add owner_callsign to planning_groups (if not present)
    $col = $db->query("SHOW COLUMNS FROM planning_groups LIKE 'owner_callsign'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE planning_groups ADD COLUMN owner_callsign VARCHAR(20) DEFAULT NULL AFTER units");
        $results[] = ['ok', 'Added owner_callsign column to planning_groups'];
    } else {
        $results[] = ['skip', 'owner_callsign column already exists — skipped'];
    }

    // 2. Create planning_group_members table
    $db->exec("
        CREATE TABLE IF NOT EXISTS planning_group_members (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            planning_group_id INT NOT NULL,
            callsign          VARCHAR(20) NOT NULL,
            role              ENUM('owner','member') NOT NULL DEFAULT 'member',
            invited_by        VARCHAR(20) DEFAULT NULL,
            joined_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_membership (planning_group_id, callsign),
            FOREIGN KEY (planning_group_id) REFERENCES planning_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results[] = ['ok', 'planning_group_members table created (or already existed)'];

    // 3. Set KI6CR as owner of all groups that have no owner yet
    $updated = $db->exec("UPDATE planning_groups SET owner_callsign = 'KI6CR' WHERE owner_callsign IS NULL OR owner_callsign = ''");
    $results[] = ['ok', "Set owner_callsign = 'KI6CR' on $updated group(s)"];

    // 4. Insert KI6CR as owner member for every group (IGNORE skips duplicates)
    $stmt = $db->query("SELECT id FROM planning_groups");
    $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $ins = $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role) VALUES (?, 'KI6CR', 'owner')");
    $inserted = 0;
    foreach ($groups as $gid) {
        $ins->execute([$gid]);
        $inserted += $ins->rowCount();
    }
    $results[] = ['ok', "Added KI6CR as owner member to $inserted group(s)"];

    $done = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DB Migration — SOTA Planner</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #eee; padding: 2rem; }
        h1 { color: #E6B84A; margin-bottom: 1.5rem; }
        .row { padding: 0.4rem 0; }
        .ok   { color: #4caf50; }
        .skip { color: #aaa; }
        .err  { color: #f44336; }
        form  { margin-top: 1.5rem; }
        input[type=password] { padding: 0.5rem; font-size: 1rem; border-radius: 4px; border: none; }
        button { margin-left: 0.5rem; padding: 0.5rem 1.5rem; background: #E6B84A; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .done { margin-top: 1.5rem; padding: 1rem; background: #1e3a1e; border: 1px solid #4caf50; border-radius: 6px; }
    </style>
</head>
<body>

<h1>SOTA Planner — SSO DB Migration</h1>

<?php if (!$authorized): ?>
    <p>Enter the dev password to run the migration:</p>
    <form method="POST">
        <input type="password" name="pass" placeholder="password" autofocus>
        <button type="submit">Run Migration</button>
    </form>
<?php else: ?>
    <?php foreach ($results as [$status, $msg]): ?>
        <div class="row <?= $status ?>">
            <?= $status === 'ok' ? '✓' : '–' ?> <?= htmlspecialchars($msg) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($done): ?>
        <div class="done">
            Migration complete. You can now delete <code>db_migrate.php</code> from the server.
            <br><br>
            <a href="index.php" style="color:#E6B84A;">Go to SOTA Planner →</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
