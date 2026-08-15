<?php
/**
 * get_notifications.php
 * AJAX endpoint — returns current user's recent notifications + unread count.
 * GET params: (none required)
 * Returns JSON: { unread_count, notifications: [...] }
 */
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id = intval($_SESSION['user_id']);

try {
    // Auto-create table if missing (idempotent)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `type` VARCHAR(60) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT NOT NULL,
        `link` VARCHAR(512) DEFAULT NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user_unread` (`user_id`, `is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Unread count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = (int) $stmt->fetchColumn();

    // Last 30 notifications
    $stmt = $pdo->prepare("
        SELECT id, type, title, message, link, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();

    // Format timestamps for display
    foreach ($notifications as &$n) {
        $n['is_read']      = (bool) $n['is_read'];
        $n['time_ago']     = _timeAgo($n['created_at']);
        $n['created_at']   = $n['created_at'];
    }
    unset($n);

    echo json_encode([
        'success'       => true,
        'unread_count'  => $unread_count,
        'notifications' => $notifications,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}

function _timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)         return 'just now';
    if ($diff < 3600)       return floor($diff / 60) . 'm ago';
    if ($diff < 86400)      return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)     return floor($diff / 86400) . 'd ago';
    return date('d M', strtotime($datetime));
}
