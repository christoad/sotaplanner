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
$multi_ids = array_filter(array_map('intval', explode(',', $_POST['multi_ids'] ?? '')));

if (empty($ids) && empty($multi_ids)) {
    echo json_encode(['success' => false, 'error' => 'Nothing specified']);
    exit;
}

$deleted = 0;
$deleted_multis = 0;

if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("DELETE FROM summits WHERE planning_group_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$current_group['id']], $ids));
    $deleted = $stmt->rowCount();
}

if (!empty($multi_ids)) {
    $placeholders = implode(',', array_fill(0, count($multi_ids), '?'));
    $stmt = $db->prepare("DELETE FROM multi_activations WHERE planning_group_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$current_group['id']], $multi_ids));
    $deleted_multis = $stmt->rowCount();
}

echo json_encode(['success' => true, 'deleted' => $deleted, 'deleted_multis' => $deleted_multis]);
