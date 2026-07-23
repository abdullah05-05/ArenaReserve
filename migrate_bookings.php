<?php
require_once 'db.php';

$errors = [];
$success = [];

// 1. slot_holds table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `slot_holds` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ground_id` INT NOT NULL,
            `slot_date` DATE NOT NULL,
            `slot_hour` INT NOT NULL,
            `held_by` INT NOT NULL,
            `expires_at` DATETIME NOT NULL,
            UNIQUE KEY `unique_hold` (`ground_id`, `slot_date`, `slot_hour`),
            FOREIGN KEY (`held_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`ground_id`) REFERENCES `grounds`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $success[] = "✅ slot_holds table created (or already exists).";
} catch (Exception $e) {
    $errors[] = "❌ slot_holds: " . $e->getMessage();
}

// 2. bookings table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bookings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ground_id` INT NOT NULL,
            `booked_by` INT NOT NULL,
            `slot_date` DATE NOT NULL,
            `slot_hour` INT NOT NULL,
            `price` DECIMAL(10,2) NOT NULL,
            `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `booking_type` ENUM('direct','open_challenge','team_challenge') NOT NULL,
            `status` ENUM('confirmed','challenge_open','challenge_pending','challenge_accepted','cancelled') NOT NULL DEFAULT 'confirmed',
            `challenger_team_name` VARCHAR(255) DEFAULT NULL,
            `opponent_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`ground_id`) REFERENCES `grounds`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`booked_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $success[] = "✅ bookings table created (or already exists).";
} catch (Exception $e) {
    $errors[] = "❌ bookings: " . $e->getMessage();
}

// 3. Add wallet_transactions types if needed (alter enum)
try {
    $pdo->exec("
        ALTER TABLE `wallet_transactions`
        MODIFY COLUMN `transaction_type`
        ENUM('Deposit','Booking_Payment','Refund','Payout','Challenge_Hold') NOT NULL;
    ");
    $success[] = "✅ wallet_transactions enum updated.";
} catch (Exception $e) {
    // Non-fatal if already updated
    $success[] = "ℹ️ wallet_transactions enum: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html><head><title>Migration</title>
<style>body{font-family:monospace;padding:2rem;background:#0f172a;color:#e2e8f0;}
h1{color:#34d399;} .ok{color:#86efac;} .err{color:#f87171;}</style>
</head><body>
<h1>🗃️ ArenaReserve Migration: Booking System</h1>
<?php foreach ($success as $s): ?>
  <p class="ok"><?= htmlspecialchars($s) ?></p>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
  <p class="err"><?= htmlspecialchars($e) ?></p>
<?php endforeach; ?>
<p style="margin-top:2rem;color:#94a3b8;">Migration complete. <a href="book_slot.php" style="color:#34d399;">→ Go to Book Slot</a></p>
</body></html>
