<?php
/**
 * release_hold.php — AJAX endpoint to release a hold on a slot placed by the current user
 * POST params: ground_id (int), slot_date (string), slot_hour (int)
 * Returns JSON
 */
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = intval($_SESSION['user_id']);
$ground_id = intval($_POST['ground_id'] ?? 0);
$slot_date = trim($_POST['slot_date'] ?? '');
$slot_hour = intval($_POST['slot_hour'] ?? -1);

if (!$ground_id || !$slot_date || $slot_hour < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        DELETE FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND slot_hour = ? AND held_by = ?
    ");
    $stmt->execute([$ground_id, $slot_date, $slot_hour, $user_id]);

    echo json_encode(['success' => true, 'message' => 'Hold released.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
