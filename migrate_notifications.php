<?php
/**
 * migrate_notifications.php
 * Run once to create the notifications table.
 * Access via browser: http://localhost/GHR/a1/migrate_notifications.php
 */
session_start();
require_once 'db.php';

// Basic protection — only admin or local access
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
$isAdmin = isset($_SESSION['current_role']) && $_SESSION['current_role'] === 'Admin';
if (!$isLocal && !$isAdmin) {
    http_response_code(403);
    die('Access denied.');
}

$results = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT NOT NULL,
            `type`       VARCHAR(60) NOT NULL,
            `title`      VARCHAR(255) NOT NULL,
            `message`    TEXT NOT NULL,
            `link`       VARCHAR(512) DEFAULT NULL,
            `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            INDEX `idx_user_unread` (`user_id`, `is_read`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $results[] = '✅ notifications table created (or already existed).';
} catch (Exception $e) {
    $results[] = '❌ Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notification Migration - ArenaReserve</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 16px; padding: 40px; max-width: 520px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,.08); }
        h2 { color: #0f172a; margin: 0 0 20px; font-size: 22px; }
        .item { padding: 10px 14px; border-radius: 8px; margin-bottom: 8px; font-size: 14px; background: #f0fdf4; color: #166534; }
        .item.err { background: #fef2f2; color: #991b1b; }
        a { display: inline-block; margin-top: 20px; color: #10b981; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h2>🗄️ Notification Migration</h2>
        <?php foreach ($results as $r): ?>
            <div class="item <?= strpos($r, '❌') !== false ? 'err' : '' ?>"><?= htmlspecialchars($r) ?></div>
        <?php endforeach; ?>
        <a href="admin_dashboard.php">← Back to Admin Dashboard</a>
    </div>
</body>
</html>
