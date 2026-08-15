<?php
/**
 * mark_notifications_read.php
 * AJAX endpoint — marks notifications as read.
 * POST params: notification_id (int, optional — omit to mark ALL as read)
 * Returns JSON
 */
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id         = intval($_SESSION['user_id']);
$notification_id = intval($_POST['notification_id'] ?? 0);

try {
    if ($notification_id > 0) {
        // Mark single notification (must belong to this user)
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
    } else {
        // Mark ALL as read for this user
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
    }

    echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
