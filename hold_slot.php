<?php
/**
 * hold_slot.php — AJAX endpoint to place a 5-minute hold on a slot.
 * Uses MySQL NOW() exclusively to avoid PHP ↔ MySQL timezone mismatches.
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

// Basic date sanity check (MySQL date comparison)
try {
    $check = $pdo->query("SELECT CURDATE() AS today")->fetch();
    if ($slot_date < $check['today']) {
        echo json_encode(['success' => false, 'message' => 'Cannot book a past date.']);
        exit;
    }
} catch (Exception $e) {}

try {
    // 1. Remove all expired holds (using MySQL NOW())
    $pdo->prepare("DELETE FROM slot_holds WHERE expires_at < NOW()")->execute();

    // 2. Check if slot is already confirmed/booked
    $stmt = $pdo->prepare("
        SELECT id FROM bookings
        WHERE ground_id = ? AND slot_date = ? AND slot_hour = ?
        AND status NOT IN ('cancelled')
    ");
    $stmt->execute([$ground_id, $slot_date, $slot_hour]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This slot is already booked.']);
        exit;
    }

    // 3. Check for an active hold by ANOTHER user
    $stmt = $pdo->prepare("
        SELECT held_by,
               TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS remaining_sec
        FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND slot_hour = ?
        AND expires_at >= NOW()
    ");
    $stmt->execute([$ground_id, $slot_date, $slot_hour]);
    $existing = $stmt->fetch();

    if ($existing && intval($existing['held_by']) !== $user_id) {
        $remaining = max(0, intval($existing['remaining_sec']));
        echo json_encode([
            'success'   => false,
            'message'   => 'This slot is on hold by another user. Try again in ' . ceil($remaining / 60) . ' min.',
            'remaining' => $remaining
        ]);
        exit;
    }

    // 4. Insert or refresh hold — expires_at set via MySQL NOW() + INTERVAL 5 MINUTE
    $stmt = $pdo->prepare("
        INSERT INTO slot_holds (ground_id, slot_date, slot_hour, held_by, expires_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))
        ON DUPLICATE KEY UPDATE
            held_by    = VALUES(held_by),
            expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$ground_id, $slot_date, $slot_hour, $user_id]);

    // 5. Read back the exact remaining seconds from MySQL
    $stmt = $pdo->prepare("
        SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS remaining_sec,
               expires_at
        FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND slot_hour = ? AND held_by = ?
    ");
    $stmt->execute([$ground_id, $slot_date, $slot_hour, $user_id]);
    $row = $stmt->fetch();
    $remaining = max(0, intval($row['remaining_sec'] ?? 300));

    echo json_encode([
        'success'    => true,
        'expires_at' => $row['expires_at'] ?? '',
        'remaining'  => $remaining
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
