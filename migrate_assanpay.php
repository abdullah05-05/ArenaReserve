<?php
/**
 * migrate_assanpay.php
 * Database migration to create payment_transactions table for AssanPay integration.
 */

require_once __DIR__ . '/db.php';

$errors = [];
$success = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `order_id` VARCHAR(20) NOT NULL UNIQUE,
            `session_id` VARCHAR(100) DEFAULT NULL,
            `payment_id` VARCHAR(100) DEFAULT NULL,
            `amount` DECIMAL(10, 2) NOT NULL,
            `purpose` ENUM('wallet_topup', 'slot_booking', 'accept_challenge') NOT NULL DEFAULT 'wallet_topup',
            `payment_method` VARCHAR(50) DEFAULT NULL,
            `meta_data` TEXT DEFAULT NULL,
            `status` ENUM('pending', 'success', 'failed', 'expired') NOT NULL DEFAULT 'pending',
            `reference` VARCHAR(100) DEFAULT NULL,
            `raw_callback` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            INDEX `idx_order_id` (`order_id`),
            INDEX `idx_session_id` (`session_id`),
            INDEX `idx_user_status` (`user_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $success[] = "payment_transactions table created or already exists.";
} catch (Exception $e) {
    $errors[] = "Error creating payment_transactions table: " . $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    echo "--- AssanPay Migration ---\n";
    foreach ($success as $msg) echo "[OK] " . $msg . "\n";
    foreach ($errors as $msg) echo "[ERROR] " . $msg . "\n";
    exit(empty($errors) ? 0 : 1);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>AssanPay Database Migration</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; }
        .box { max-width: 600px; margin: auto; background: #1e293b; padding: 2rem; border-radius: 12px; }
        .ok { color: #34d399; margin-bottom: 0.5rem; }
        .err { color: #f87171; margin-bottom: 0.5rem; }
        a { color: #38bdf8; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <h2>⚡ AssanPay Migration</h2>
        <?php foreach ($success as $s): ?>
            <p class="ok">✅ <?= htmlspecialchars($s) ?></p>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <p class="err">❌ <?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>
        <p style="margin-top: 1.5rem;"><a href="wallet.php">← Back to Wallet</a></p>
    </div>
</body>
</html>
