<?php
require_once 'config.php';
session_start();
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$db = getDbConnection();
$current_group = getCurrentPlanningGroup($db);

if (!$current_group) {
    echo json_encode(['success' => false, 'error' => 'No dashboard selected']);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No summits specified']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("DELETE FROM summits WHERE planning_group_id = ? AND id IN ($placeholders)");
$stmt->execute(array_merge([$current_group['id']], $ids));

echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
