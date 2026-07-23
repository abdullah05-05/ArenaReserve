<?php
/**
 * accept_challenge.php — AJAX endpoint to accept an open challenge
 * POST params: booking_id
 * Returns JSON
 */
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id    = intval($_SESSION['user_id']);
$booking_id = intval($_POST['booking_id'] ?? 0);

if (!$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Lock and fetch the challenge booking
    $stmt = $pdo->prepare("
        SELECT b.*, g.title AS ground_title
        FROM bookings b
        JOIN grounds g ON g.id = b.ground_id
        WHERE b.id = ? AND b.status = 'challenge_open'
        FOR UPDATE
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Challenge not found or already accepted.']);
        exit;
    }

    if ($booking['booked_by'] == $user_id) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'You cannot accept your own challenge.']);
        exit;
    }

    // 2. Calculate opponent's 50% payment
    $amount_to_charge = round(floatval($booking['price']) * 0.5, 2);

    // 3. Check opponent wallet
    $stmt = $pdo->prepare("SELECT id, available_balance FROM wallets WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();

    if (!$wallet || floatval($wallet['available_balance']) < $amount_to_charge) {
        $pdo->rollBack();
        echo json_encode([
            'success'  => false,
            'message'  => 'Insufficient wallet balance to accept this challenge.',
            'required' => $amount_to_charge,
            'balance'  => floatval($wallet['available_balance'] ?? 0)
        ]);
        exit;
    }

    // 4. Deduct opponent wallet
    $stmt = $pdo->prepare("UPDATE wallets SET available_balance = available_balance - ? WHERE user_id = ?");
    $stmt->execute([$amount_to_charge, $user_id]);

    // 5. Record transaction
    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id)
        VALUES (?, ?, 'Booking_Payment', ?)
    ");
    $ref = 'ACCEPT-BK-' . $booking_id;
    $stmt->execute([$wallet['id'], -$amount_to_charge, $ref]);

    // 6. Update booking status to accepted
    $stmt = $pdo->prepare("
        UPDATE bookings SET status = 'challenge_accepted', opponent_id = ?, amount_paid = amount_paid + ?
        WHERE id = ?
    ");
    $stmt->execute([$user_id, $amount_to_charge, $booking_id]);

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'message'     => '🎉 Challenge accepted! The slot is now confirmed for both teams.',
        'amount_paid' => $amount_to_charge,
        'booking_id'  => $booking_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
