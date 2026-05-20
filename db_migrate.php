<?php
/**
 * DB Migration — activity_log table
 * Creates the persistent activity log table used by God Mode.
 * Safe to run multiple times (idempotent). Delete after running.
 */

require_once 'config.php';
session_start();

$authorized = false;
$results    = [];
$done       = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['pass'] ?? '') === 'sota') {
    $authorized = true;
    $db = getDbConnection();

    // Create activity_log table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_log (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                event_time  DATETIME DEFAULT CURRENT_TIMESTAMP,
                callsign    VARCHAR(20),
                login_type  VARCHAR(20),
                event_type  VARCHAR(50),
                subject     VARCHAR(255),
                detail      VARCHAR(255),
                group_name  VARCHAR(255),
                ip_address  VARCHAR(45),
                INDEX idx_time     (event_time),
                INDEX idx_callsign (callsign)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results[] = ['ok', 'activity_log table created (or already existed)'];
    } catch (PDOException $e) {
        $results[] = ['err', 'Failed to create activity_log: ' . $e->getMessage()];
    }

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

<h1>SOTA Planner — DB Migration: activity_log</h1>

<?php if (!$authorized): ?>
    <p>Enter the dev password to run the migration:</p>
    <form method="POST">
        <input type="password" name="pass" placeholder="password" autofocus>
        <button type="submit">Run Migration</button>
    </form>
<?php else: ?>
    <?php foreach ($results as [$status, $msg]): ?>
        <div class="row <?= $status ?>">
            <?= $status === 'ok' ? '✓' : ($status === 'err' ? '✗' : '–') ?> <?= htmlspecialchars($msg) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($done): ?>
        <div class="done">
            Migration complete. Delete <code>db_migrate.php</code> from the server when done.
            <br><br>
            <a href="god_mode.php?tab=activity" style="color:#E6B84A;">Go to Activity Log →</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
