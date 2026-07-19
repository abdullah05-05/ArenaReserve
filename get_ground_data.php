<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$user_id = $_SESSION['user_id'];
$ground_id = intval($_GET['ground_id'] ?? 0);

if (!$ground_id) {
    echo json_encode(['success' => false]);
    exit;
}

// Verify ownership
$stmt = $pdo->prepare("SELECT * FROM grounds WHERE id = ? AND owner_id = ?");
$stmt->execute([$ground_id, $user_id]);
$ground = $stmt->fetch();

if (!$ground) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Fetch slots
$stmt = $pdo->prepare("SELECT hour, is_available, slot_type, price FROM ground_slots WHERE ground_id = ? ORDER BY hour");
$stmt->execute([$ground_id]);
$slots = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'ground' => $ground,
    'slots' => $slots
]);
?>
