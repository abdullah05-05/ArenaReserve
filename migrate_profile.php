<?php
/**
 * Migration: Add profile_picture column to users table
 * Run once: http://localhost/GHR/a1/migrate_profile.php
 */
session_start();
require_once 'db.php';

$messages = [];

try {
    // Add profile_picture column if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `profile_picture` VARCHAR(255) DEFAULT NULL AFTER `city`");
        $messages[] = ['type' => 'success', 'text' => 'Column `profile_picture` added to `users` table.'];
    } else {
        $messages[] = ['type' => 'info', 'text' => 'Column `profile_picture` already exists — skipped.'];
    }

    // Create avatars upload directory
    $avatarDir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($avatarDir)) {
        mkdir($avatarDir, 0755, true);
        $messages[] = ['type' => 'success', 'text' => 'Directory `uploads/avatars/` created.'];
    } else {
        $messages[] = ['type' => 'info', 'text' => 'Directory `uploads/avatars/` already exists — skipped.'];
    }

    $messages[] = ['type' => 'done', 'text' => 'Migration complete!'];
} catch (Exception $e) {
    $messages[] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile Migration - ArenaReserve</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center p-6">
    <div class="bg-slate-800 rounded-2xl p-8 max-w-lg w-full shadow-2xl border border-slate-700">
        <h1 class="text-2xl font-bold text-white mb-6 flex items-center gap-3">
            <span class="text-emerald-400">⚙</span> Profile Migration
        </h1>
        <?php foreach ($messages as $msg): ?>
            <?php
                $color = match($msg['type']) {
                    'success' => 'text-emerald-400 bg-emerald-900/30 border-emerald-700',
                    'error'   => 'text-red-400 bg-red-900/30 border-red-700',
                    'done'    => 'text-blue-400 bg-blue-900/30 border-blue-700',
                    default   => 'text-slate-300 bg-slate-700/50 border-slate-600',
                };
                $icon = match($msg['type']) {
                    'success' => '✓',
                    'error'   => '✗',
                    'done'    => '🎉',
                    default   => 'ℹ',
                };
            ?>
            <div class="<?= $color ?> border rounded-lg px-4 py-3 mb-3 text-sm font-medium">
                <?= $icon ?> <?= htmlspecialchars($msg['text']) ?>
            </div>
        <?php endforeach; ?>
        <div class="mt-6 flex gap-3">
            <a href="explore.php" class="flex-1 text-center bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors">
                Go to Player Dashboard
            </a>
            <a href="owner_dashboard.php" class="flex-1 text-center bg-slate-600 hover:bg-slate-500 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors">
                Go to Owner Dashboard
            </a>
        </div>
    </div>
</body>
</html>
