<?php
require_once 'db.php';

$sqls = [
    // Add ground_status column if not exists
    "ALTER TABLE `grounds` ADD COLUMN IF NOT EXISTS `ground_status` ENUM('Active','Suspended','Blocked') NOT NULL DEFAULT 'Active'",
    // Add block_reason column if not exists
    "ALTER TABLE `grounds` ADD COLUMN IF NOT EXISTS `block_reason` TEXT NULL",
    // Add description column if not exists
    "ALTER TABLE `grounds` ADD COLUMN IF NOT EXISTS `description` TEXT NULL",
    // Create ground_slots table
    "CREATE TABLE IF NOT EXISTS `ground_slots` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ground_id` INT NOT NULL,
        `hour` TINYINT NOT NULL COMMENT '0-23 representing hour of day',
        `is_available` TINYINT(1) NOT NULL DEFAULT 1,
        `slot_type` ENUM('Normal','Peak') NOT NULL DEFAULT 'Normal',
        `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        UNIQUE KEY `unique_ground_hour` (`ground_id`, `hour`),
        FOREIGN KEY (`ground_id`) REFERENCES `grounds` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$errors = [];
foreach ($sqls as $sql) {
    try {
        $pdo->exec($sql);
        echo "<p style='color:green'>OK: " . substr($sql, 0, 80) . "...</p>";
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        echo "<p style='color:red'>ERROR: " . $e->getMessage() . "</p>";
    }
}

if (empty($errors)) {
    echo "<p style='color:green;font-weight:bold'>Migration completed successfully!</p>";
} else {
    echo "<p style='color:red;font-weight:bold'>Migration completed with errors.</p>";
}
?>
