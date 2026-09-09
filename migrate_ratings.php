<?php
/**
 * migrate_ratings.php
 * Creates the ground_ratings table if it does not already exist.
 */

require_once __DIR__ . '/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS ground_ratings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ground_id INT NOT NULL,
        booking_id INT NOT NULL,
        user_id INT NOT NULL,
        rating TINYINT NOT NULL,
        review TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_booking_user (booking_id, user_id),
        KEY idx_ground (ground_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "SUCCESS: ground_ratings table is ready.\n";

    // Show columns
    $cols = $pdo->query("SHOW COLUMNS FROM ground_ratings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo " - " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
