<?php
// One-off migration: store coordinates on starting addresses so drive times
// use a fixed point instead of re-looking up the address text every time
// (Google's lookup of business names can stop working from day to day).
// Adds addresses.lat / addresses.lng, then backfills existing rows.
// Safe to run twice. Delete this file after running.
require_once 'config.php';

if (($_GET['password'] ?? '') !== 'sota') {
    http_response_code(403);
    die('Forbidden');
}

$db = getDbConnection();

header('Content-Type: text/plain');

function step($label, $fn) {
    try {
        $result = $fn();
        echo "[OK] $label" . ($result ? ": $result" : '') . "\n";
    } catch (Exception $e) {
        echo "[ERROR] $label: " . $e->getMessage() . "\n";
    }
}

step('Check addresses.lat column', function () use ($db) {
    $cols = $db->query("SHOW COLUMNS FROM addresses LIKE 'lat'")->fetchAll();
    if ($cols) return 'already exists, skipped';
    $db->exec("ALTER TABLE addresses ADD COLUMN lat DECIMAL(10,7) NULL AFTER address");
    return 'column added';
});

step('Check addresses.lng column', function () use ($db) {
    $cols = $db->query("SHOW COLUMNS FROM addresses LIKE 'lng'")->fetchAll();
    if ($cols) return 'already exists, skipped';
    $db->exec("ALTER TABLE addresses ADD COLUMN lng DECIMAL(10,7) NULL AFTER lat");
    return 'column added';
});

step('Backfill coordinates for existing addresses', function () use ($db) {
    $rows = $db->query("SELECT id, planning_group_id, address FROM addresses WHERE lat IS NULL OR lng IS NULL")->fetchAll();
    if (!$rows) return 'all addresses already have coordinates, skipped';

    $upd = $db->prepare("UPDATE addresses SET lat = ?, lng = ? WHERE id = ?");
    $found = 0;
    $missing = [];
    foreach ($rows as $r) {
        $status = null;
        $geo = geocodeAddress($r['address'], $status);
        if ($geo) {
            $upd->execute([$geo['lat'], $geo['lng'], $r['id']]);
            $found++;
        } else {
            $missing[] = "    address #{$r['id']} (dashboard #{$r['planning_group_id']}): \"{$r['address']}\" [$status]";
        }
        usleep(100000);
    }
    $out = "$found of " . count($rows) . " filled in";
    if ($missing) {
        $out .= "\n  Not found by Google (these will keep using the address text until edited):\n" . implode("\n", $missing);
    }
    return $out;
});

echo "\nDone. Delete this file from the server now.\n";
