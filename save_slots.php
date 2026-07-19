<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$ground_id = intval($input['ground_id'] ?? 0);
$slots = $input['slots'] ?? [];

if (!$ground_id || empty($slots)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

// Verify ownership
$stmt = $pdo->prepare("SELECT id FROM grounds WHERE id = ? AND owner_id = ?");
$stmt->execute([$ground_id, $user_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Delete existing slots for this ground
    $pdo->prepare("DELETE FROM ground_slots WHERE ground_id = ?")->execute([$ground_id]);

    // Insert new slots
    $stmt = $pdo->prepare("INSERT INTO ground_slots (ground_id, hour, is_available, slot_type, price) VALUES (?, ?, ?, ?, ?)");
    foreach ($slots as $slot) {
        $hour = intval($slot['hour']);
        $is_available = intval($slot['is_available'] ?? 0);
        $slot_type = in_array($slot['slot_type'], ['Normal', 'Peak']) ? $slot['slot_type'] : 'Normal';
        $price = floatval($slot['price'] ?? 0);
        $stmt->execute([$ground_id, $hour, $is_available, $slot_type, $price]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Slot schedule saved successfully!']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
?>
