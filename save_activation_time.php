<?php
require_once 'config.php';
session_start();
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]);
    exit;
}

$minutes = max(15, min(300, (int)($_POST['minutes'] ?? 60)));
$db = getDbConnection();
$db->prepare("
    INSERT INTO user_settings (user_callsign, default_activation_time_min)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE default_activation_time_min = VALUES(default_activation_time_min)
")->execute([getCurrentCallsign(), $minutes]);

echo json_encode(['ok' => true, 'minutes' => $minutes]);
