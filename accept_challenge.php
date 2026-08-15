<?php
/**
 * accept_challenge.php — AJAX endpoint to accept an open challenge
 * POST params: booking_id
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

    // 1. Lock and fetch the challenge booking (open_challenge OR team_challenge targeted at this user)
    $stmt = $pdo->prepare("
        SELECT b.*, g.title AS ground_title
        FROM bookings b
        JOIN grounds g ON g.id = b.ground_id
        WHERE b.id = ?
        AND (
            (b.status = 'challenge_open')
            OR (b.status = 'challenge_pending' AND b.challenged_user_id = ?)
        )
        FOR UPDATE
    ");
    $stmt->execute([$booking_id, $user_id]);
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

    // ── Fetch details for notifications ──
    $infoStmt = $pdo->prepare("
        SELECT u_challenger.name  AS challenger_name,  u_challenger.email AS challenger_email,
               u_opponent.name    AS opponent_name,    u_opponent.email   AS opponent_email
        FROM users u_challenger
        JOIN users u_opponent ON u_opponent.id = ?
        WHERE u_challenger.id = ?
    ");
    $infoStmt->execute([$user_id, $booking['booked_by']]);
    $info = $infoStmt->fetch();

    if ($info) {
        // In-app: challenger
        createNotification($pdo, $booking['booked_by'], 'challenge_accepted',
            'Challenge Accepted! ⚡',
            "{$info['opponent_name']} accepted your challenge at {$booking['ground_title']} on {$booking['slot_date']}. Game on!",
            'match_history.php'
        );
        // In-app: opponent
        createNotification($pdo, $user_id, 'challenge_accepted',
            'Challenge Confirmed!',
            "You accepted a challenge at {$booking['ground_title']} on {$booking['slot_date']}. Good luck!",
            'match_history.php'
        );

        // Email both parties
        sendChallengeAcceptedEmail(
            $info['challenger_email'], $info['challenger_name'],
            $info['opponent_email'],   $info['opponent_name'],
            [
                'ground_title' => $booking['ground_title'],
                'slot_date'    => $booking['slot_date'],
                'slot_hour'    => $booking['slot_hour'],
                'booking_id'   => $booking_id,
                'amount_paid'  => $amount_to_charge,
            ]
        );
    }

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
