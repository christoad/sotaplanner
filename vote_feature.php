<?php
require_once 'config.php';
session_start();
requireLogin();
header('Content-Type: application/json');

$allowed = ['sotawatch_posting'];
$feature = $_POST['feature'] ?? '';
if (!in_array($feature, $allowed, true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$cs = getCurrentCallsign();
$db = getDbConnection();
try {
    if (($_POST['action'] ?? '') === 'unvote') {
        $db->prepare("DELETE FROM feature_votes WHERE feature_key = ? AND callsign = ?")->execute([$feature, $cs]);
    } else {
        $db->prepare("INSERT IGNORE INTO feature_votes (feature_key, callsign) VALUES (?, ?)")->execute([$feature, $cs]);
    }
    $st = $db->prepare("SELECT COUNT(*) FROM feature_votes WHERE feature_key = ?");
    $st->execute([$feature]);
    echo json_encode(['ok' => true, 'count' => (int)$st->fetchColumn()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
