<?php
require_once 'config.php';
session_start();
requireLogin();

header('Content-Type: application/json');

$callsign      = $_SESSION['sota_callsign'] ?? '';
$real_callsign = $_SESSION['_god_mode_real_callsign'] ?? '';

if ($callsign !== 'KI6CR' && $real_callsign !== 'KI6CR') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$db     = getDbConnection();
$gid    = (int)($_POST['group_id'] ?? 0);
$new_cs = strtoupper(trim($_POST['new_callsign'] ?? ''));

if (!$gid || !$new_cs || !preg_match('/^[A-Z0-9]{3,10}$/', $new_cs)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid callsign or group.']);
    exit;
}

$stmt = $db->prepare("INSERT IGNORE INTO planning_group_members (planning_group_id, callsign, role, invited_by) VALUES (?, ?, 'member', 'KI6CR')");
$stmt->execute([$gid, $new_cs]);

$already_member = $stmt->rowCount() === 0;

$countStmt = $db->prepare("SELECT COUNT(*) FROM planning_group_members WHERE planning_group_id = ?");
$countStmt->execute([$gid]);
$member_count = (int)$countStmt->fetchColumn();

echo json_encode([
    'ok'             => true,
    'callsign'       => $new_cs,
    'already_member' => $already_member,
    'member_count'   => $member_count,
    'message'        => $already_member ? "$new_cs is already a member." : "Added $new_cs to group.",
]);
