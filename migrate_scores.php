<?php
require_once 'db.php';

$errors  = [];
$success = [];

// 1. Create match_scores table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `match_scores` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `booking_id`       INT NOT NULL UNIQUE,
            `ground_id`        INT NOT NULL,
            `owner_id`         INT NOT NULL,
            `team_a_user`      INT NOT NULL,
            `team_b_user`      INT DEFAULT NULL,
            `score_a`          TINYINT NOT NULL COMMENT '1=win 0=loss',
            `score_b`          TINYINT NOT NULL COMMENT '1=win 0=loss',
            `commission_paid`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `scored_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`owner_id`)   REFERENCES `users`(`id`)    ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $success[] = "✅ match_scores table created (or already exists).";
} catch (Exception $e) {
    $errors[] = "❌ match_scores: " . $e->getMessage();
}

// 2. Update wallet_transactions enum to include Commission
try {
    $pdo->exec("
        ALTER TABLE `wallet_transactions`
        MODIFY COLUMN `transaction_type`
        ENUM('Deposit','Booking_Payment','Refund','Payout','Challenge_Hold','Commission') NOT NULL;
    ");
    $success[] = "✅ wallet_transactions enum updated (Commission added).";
} catch (Exception $e) {
    $success[] = "ℹ️ wallet_transactions enum: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
<title>Migration – Score System</title>
<style>
  body { font-family: monospace; padding: 2rem; background: #0f172a; color: #e2e8f0; }
  h1   { color: #34d399; }
  .ok  { color: #86efac; }
  .err { color: #f87171; }
</style>
</head>
<body>
<h1>🗃️ ArenaReserve Migration: Score System</h1>
<?php foreach ($success as $s): ?>
  <p class="ok"><?= htmlspecialchars($s) ?></p>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
  <p class="err"><?= htmlspecialchars($e) ?></p>
<?php endforeach; ?>
<p style="margin-top:2rem;color:#94a3b8;">
  Migration complete.
  <a href="owner_scores.php" style="color:#34d399;">→ Go to Score Entry</a> |
  <a href="leaderboard.php"  style="color:#34d399;">→ Go to Leaderboard</a>
</p>
</body>
</html>
