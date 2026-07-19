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
$title = trim($input['title'] ?? '');
$address = trim($input['address'] ?? '');
$sport_type = trim($input['sport_type'] ?? '');
$base_price = floatval($input['base_price'] ?? 0);
$peak_price = floatval($input['peak_price'] ?? 0);
$description = trim($input['description'] ?? '');

if (!$ground_id || empty($title) || empty($address) || empty($sport_type) || $base_price <= 0 || $peak_price <= 0) {
    echo json_encode(['success' => false, 'message' => 'All fields are required and prices must be positive.']);
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

    // 1. Update the grounds table
    $stmt = $pdo->prepare("UPDATE grounds SET title = ?, address = ?, sport_type = ?, base_price = ?, peak_price = ?, description = ? WHERE id = ? AND owner_id = ?");
    $stmt->execute([$title, $address, $sport_type, $base_price, $peak_price, $description, $ground_id, $user_id]);

    // 2. Sync ground_slots prices so book_slot.php stays in sync with explore.php
    //    Normal slots → new base_price, Peak slots → new peak_price
    $stmt = $pdo->prepare("UPDATE ground_slots SET price = ? WHERE ground_id = ? AND slot_type = 'Normal'");
    $stmt->execute([$base_price, $ground_id]);

    $stmt = $pdo->prepare("UPDATE ground_slots SET price = ? WHERE ground_id = ? AND slot_type = 'Peak'");
    $stmt->execute([$peak_price, $ground_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Venue details updated successfully!']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
?>
