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

$multi_id = (int)($_POST['multi_id'] ?? 0);
$expanded = !empty($_POST['expanded']) ? 1 : 0;

if (!$multi_id) {
    echo json_encode(['success' => false, 'error' => 'Missing multi_id']);
    exit;
}

$stmt = $db->prepare("UPDATE multi_activations SET is_expanded = ? WHERE id = ? AND planning_group_id = ?");
$stmt->execute([$expanded, $multi_id, $current_group['id']]);

echo json_encode(['success' => true]);
