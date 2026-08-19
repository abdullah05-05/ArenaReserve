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
require_once 'notifications.php';
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
               b.price, b.amount_paid, b.booking_type, b.status, b.challenged_user_id,
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

    // 4. Cannot cancel past bookings (slot start time has already passed)
    $slot_start_time = strtotime($booking['slot_date'] . ' ' . sprintf('%02d:00:00', intval($booking['slot_hour'])));
    if ($slot_start_time <= time()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Cannot cancel a past or currently active booking.']);
        exit;
    }

    // Ensure cancellation_payout_owner column exists in bookings
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'cancellation_payout_owner'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN cancellation_payout_owner DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
    } catch (Exception $e) {}

    // 5. Calculate refund amount and owner compensation
    $amount_paid   = floatval($booking['amount_paid']);
    $refund_amount = 0.0;
    $owner_payout  = 0.0;
    $platform_fee  = 0.0;
    $refund_note   = '';

    if (in_array($booking['status'], ['challenge_open', 'challenge_pending'])) {
        // Full refund — no opponent committed any payment
        $refund_amount = $amount_paid;
        $refund_note   = 'Full refund (no opponent committed).';
    } elseif ($booking['status'] === 'confirmed') {
        // Check if slot is > 24 hours away using MySQL
        $check = $pdo->prepare("
            SELECT TIMESTAMPDIFF(HOUR, NOW(), CONCAT(?, ' ', LPAD(?, 2, '0'), ':00:00')) AS hours_until
        ");
        $check->execute([$booking['slot_date'], $booking['slot_hour']]);
        $hours_until_slot = intval($check->fetch()['hours_until'] ?? 0);

        if ($hours_until_slot > 24) {
            $refund_amount = $amount_paid;
            $refund_note   = 'Full refund (cancelled more than 24 hours before slot).';
        } else {
            // Late cancellation within 24 hours: 50% refunded to player, 50% retained
            $refund_amount = round($amount_paid * 0.5, 2);
            $retained_fee  = round($amount_paid - $refund_amount, 2);
            // 5% platform commission on retained fee
            $platform_fee  = round($retained_fee * 0.05, 2);
            // Remaining 95% sent to ground owner's wallet
            $owner_payout  = max(0, round($retained_fee - $platform_fee, 2));
            $refund_note   = '50% refund (cancelled within 24 hours of slot). PKR ' . number_format($owner_payout, 2) . ' credited to venue owner.';
        }
    }

    // 6. Update booking status to 'cancelled' and save owner payout
    $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_payout_owner = ? WHERE id = ?");
    $stmt->execute([$owner_payout, $booking_id]);

    // 7. Refund player's wallet if applicable
    if ($refund_amount > 0) {
        $stmt = $pdo->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE id = ?");
        $stmt->execute([$refund_amount, $booking['wallet_id']]);

        // 8. Record refund transaction for player
        $ref = 'CANCEL-BK-' . $booking_id;
        $stmt = $pdo->prepare("
            INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id)
            VALUES (?, ?, 'Refund', ?)
        ");
        $stmt->execute([$booking['wallet_id'], $refund_amount, $ref]);
    }

    // 9. If owner earned late cancellation compensation, credit ground owner's wallet
    if ($owner_payout > 0) {
        $gStmt = $pdo->prepare("SELECT owner_id, title FROM grounds WHERE id = ?");
        $gStmt->execute([$booking['ground_id']]);
        $groundRow = $gStmt->fetch();
        if ($groundRow && !empty($groundRow['owner_id'])) {
            $ground_owner_id = intval($groundRow['owner_id']);

            $owStmt = $pdo->prepare("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE");
            $owStmt->execute([$ground_owner_id]);
            $ownerWallet = $owStmt->fetch();

            if (!$ownerWallet) {
                $pdo->prepare("INSERT INTO wallets (user_id, available_balance) VALUES (?, ?)")
                    ->execute([$ground_owner_id, $owner_payout]);
                $ownerWalletId = $pdo->lastInsertId();
            } else {
                $ownerWalletId = $ownerWallet['id'];
                $pdo->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE id = ?")
                    ->execute([$owner_payout, $ownerWalletId]);
            }

            // Record transaction for owner
            $refOwner = 'CANCEL-FEE-BK-' . $booking_id;
            $pdo->prepare("
                INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id)
                VALUES (?, ?, 'Commission', ?)
            ")->execute([$ownerWalletId, $owner_payout, $refOwner]);

            // In-app notification to ground owner
            createNotification($pdo, $ground_owner_id, 'cancellation_fee_payout',
                'Cancellation Fee Earned 💰',
                "Booking #{$booking_id} at {$groundRow['title']} was cancelled within 24h. PKR " . number_format($owner_payout, 2) . " has been credited to your wallet (after 5% platform fee).",
                'owner_scores.php'
            );
        }
    }

    $pdo->commit();

    $new_balance = round(floatval($booking['available_balance']) + $refund_amount, 2);

    // ── Fetch ground info for notifications ──
    $infoStmt = $pdo->prepare("
        SELECT g.title AS ground_title, u.email, u.name
        FROM grounds g
        JOIN users u ON u.id = ?
        WHERE g.id = ?
    ");
    $infoStmt->execute([$user_id, $booking['ground_id']]);
    $info = $infoStmt->fetch();

    // ── In-app notification ──
    $refundMsg = $refund_amount > 0
        ? "PKR " . number_format($refund_amount, 2) . " has been refunded to your wallet."
        : "No refund was issued due to cancellation policy.";
    createNotification($pdo, $user_id, 'booking_cancelled',
        'Booking Cancelled',
        "Your booking at " . ($info['ground_title'] ?? 'your venue') . " on {$booking['slot_date']} has been cancelled. {$refundMsg}",
        'wallet.php'
    );

    // If a pending team challenge was withdrawn, notify the challenged user
    if ($booking['status'] === 'challenge_pending' && !empty($booking['challenged_user_id'])) {
        createNotification($pdo, intval($booking['challenged_user_id']), 'challenge_cancelled',
            'Challenge Withdrawn',
            "The team challenge at " . ($info['ground_title'] ?? 'the venue') . " on {$booking['slot_date']} was withdrawn by the challenger.",
            'match_history.php'
        );
    }

    // ── Email notification ──
    if ($info) {
        sendBookingCancelledEmail($info['email'], $info['name'], [
            'ground_title' => $info['ground_title'],
            'slot_date'    => $booking['slot_date'],
            'slot_hour'    => $booking['slot_hour'],
            'booking_id'   => $booking_id,
        ], $refund_amount);
    }

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
