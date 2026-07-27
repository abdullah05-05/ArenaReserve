<?php
/**
 * cancel_booking.php — AJAX endpoint to cancel a booking and issue a refund.
 *
 * Cancellation Policy:
 *  - 'confirmed' (direct):       Full refund if slot is > 24h away, else 50% refund.
 *  - 'challenge_open':           Full refund (no opponent has paid yet).
 *  - 'challenge_pending':        Full refund (opponent hasn't accepted yet).
 *  - 'challenge_accepted':       NOT cancellable via this endpoint (both parties paid).
 *  - Past dates / already cancelled: Blocked.
 *
 * POST params: booking_id (int)
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

    // 1. Fetch the booking with a row lock
    $stmt = $pdo->prepare("
        SELECT b.id, b.ground_id, b.booked_by, b.slot_date, b.slot_hour,
               b.price, b.amount_paid, b.booking_type, b.status,
               w.id AS wallet_id, w.available_balance
        FROM bookings b
        JOIN wallets w ON w.user_id = b.booked_by
        WHERE b.id = ? AND b.booked_by = ?
        FOR UPDATE
    ");
    $stmt->execute([$booking_id, $user_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Booking not found or not yours.']);
        exit;
    }

    // 2. Already cancelled?
    if ($booking['status'] === 'cancelled') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'This booking is already cancelled.']);
        exit;
    }

    // 3. Cannot cancel accepted challenges
    if ($booking['status'] === 'challenge_accepted') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Cannot cancel an accepted challenge. Please contact support.']);
        exit;
    }

    // 4. Cannot cancel past bookings
    $today = date('Y-m-d');
    if ($booking['slot_date'] < $today) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Cannot cancel a past booking.']);
        exit;
    }

    // 5. Calculate refund amount
    $amount_paid   = floatval($booking['amount_paid']);
    $refund_amount = 0.0;
    $refund_note   = '';

    if (in_array($booking['status'], ['challenge_open', 'challenge_pending'])) {
        // Full refund — no opponent committed any payment
        $refund_amount = $amount_paid;
        $refund_note   = 'Full refund (no opponent committed).';
    } elseif ($booking['status'] === 'confirmed') {
        // Check if slot is > 24 hours away using MySQL to avoid timezone issues
        $check = $pdo->prepare("
            SELECT TIMESTAMPDIFF(HOUR, NOW(), CONCAT(?, ' ', LPAD(?, 2, '0'), ':00:00')) AS hours_until
        ");
        $check->execute([$booking['slot_date'], $booking['slot_hour']]);
        $hours_until_slot = intval($check->fetch()['hours_until'] ?? 0);

        if ($hours_until_slot > 24) {
            $refund_amount = $amount_paid;
            $refund_note   = 'Full refund (cancelled more than 24 hours before slot).';
        } else {
            $refund_amount = round($amount_paid * 0.5, 2);
            $refund_note   = '50% refund (cancelled within 24 hours of slot).';
        }
    }

    // 6. Update booking status to 'cancelled'
    $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$booking_id]);

    // 7. Refund wallet if applicable
    if ($refund_amount > 0) {
        $stmt = $pdo->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE id = ?");
        $stmt->execute([$refund_amount, $booking['wallet_id']]);

        // 8. Record refund transaction
        $ref = 'CANCEL-BK-' . $booking_id;
        $stmt = $pdo->prepare("
            INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id)
            VALUES (?, ?, 'Refund', ?)
        ");
        $stmt->execute([$booking['wallet_id'], $refund_amount, $ref]);
    }

    $pdo->commit();

    $new_balance = round(floatval($booking['available_balance']) + $refund_amount, 2);

    echo json_encode([
        'success'       => true,
        'message'       => '✅ Booking cancelled. ' . $refund_note,
        'refund_amount' => $refund_amount,
        'new_balance'   => $new_balance,
        'booking_id'    => $booking_id,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
