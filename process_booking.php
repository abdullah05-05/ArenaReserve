<?php
/**
 * process_booking.php — AJAX endpoint to process a booking payment
 * POST params: ground_id, slot_date, slot_hour, booking_type, challenger_team_name (optional)
 * Returns JSON
 */
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id              = intval($_SESSION['user_id']);
$ground_id            = intval($_POST['ground_id'] ?? 0);
$slot_date            = trim($_POST['slot_date'] ?? '');
$slot_hour            = intval($_POST['slot_hour'] ?? -1);
$booking_type         = trim($_POST['booking_type'] ?? '');
$challenger_team_name = trim($_POST['challenger_team_name'] ?? '');

$valid_types = ['direct', 'open_challenge', 'team_challenge'];
if (!$ground_id || !$slot_date || $slot_hour < 0 || !in_array($booking_type, $valid_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Verify active hold belongs to this user
    $stmt = $pdo->prepare("
        SELECT id FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND slot_hour = ?
        AND held_by = ? AND expires_at >= NOW()
    ");
    $stmt->execute([$ground_id, $slot_date, $slot_hour, $user_id]);
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Your hold has expired. Please click the slot again.']);
        exit;
    }

    // 2. Double-check not already booked
    $stmt = $pdo->prepare("
        SELECT id FROM bookings
        WHERE ground_id = ? AND slot_date = ? AND slot_hour = ?
        AND status NOT IN ('cancelled')
    ");
    $stmt->execute([$ground_id, $slot_date, $slot_hour]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Slot was just booked by someone else.']);
        exit;
    }

    // 3. Get slot price from ground_slots
    $stmt = $pdo->prepare("SELECT price FROM ground_slots WHERE ground_id = ? AND hour = ? AND is_available = 1");
    $stmt->execute([$ground_id, $slot_hour]);
    $slot_row = $stmt->fetch();
    if (!$slot_row) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Slot configuration not found.']);
        exit;
    }
    $full_price = floatval($slot_row['price']);

    // 4. Calculate amount to charge
    $amount_to_charge = ($booking_type === 'direct') ? $full_price : round($full_price * 0.5, 2);

    // 5. Check wallet balance
    $stmt = $pdo->prepare("SELECT id, available_balance FROM wallets WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    if (!$wallet || floatval($wallet['available_balance']) < $amount_to_charge) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient wallet balance. Please top up your wallet.',
            'required' => $amount_to_charge,
            'balance'  => floatval($wallet['available_balance'] ?? 0)
        ]);
        exit;
    }

    // 6. Determine booking status
    $status_map = [
        'direct'          => 'confirmed',
        'open_challenge'  => 'challenge_open',
        'team_challenge'  => 'challenge_pending',
    ];
    $booking_status = $status_map[$booking_type];

    // 7. Deduct wallet
    $stmt = $pdo->prepare("UPDATE wallets SET available_balance = available_balance - ? WHERE user_id = ?");
    $stmt->execute([$amount_to_charge, $user_id]);

    // 8. Record wallet transaction
    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id)
        VALUES (?, ?, 'Booking_Payment', ?)
    ");
    $ref = 'BK-' . strtoupper($booking_type) . '-' . $ground_id . '-' . $slot_date . '-' . $slot_hour;
    $stmt->execute([$wallet['id'], -$amount_to_charge, $ref]);

    // 9. Insert booking
    $stmt = $pdo->prepare("
        INSERT INTO bookings (ground_id, booked_by, slot_date, slot_hour, price, amount_paid, booking_type, status, challenger_team_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $ground_id, $user_id, $slot_date, $slot_hour,
        $full_price, $amount_to_charge,
        $booking_type, $booking_status,
        $challenger_team_name ?: null
    ]);
    $booking_id = $pdo->lastInsertId();

    // 10. Remove the hold
    $stmt = $pdo->prepare("DELETE FROM slot_holds WHERE ground_id = ? AND slot_date = ? AND slot_hour = ?");
    $stmt->execute([$ground_id, $slot_date, $slot_hour]);

    $pdo->commit();

    $messages = [
        'direct'         => '✅ Booking confirmed! Full payment deducted from wallet.',
        'open_challenge' => '⚡ Open challenge posted! 50% payment held. Others can now accept.',
        'team_challenge' => '🤝 Challenge sent! 50% payment held pending opponent acceptance.',
    ];

    echo json_encode([
        'success'    => true,
        'booking_id' => $booking_id,
        'message'    => $messages[$booking_type],
        'amount_paid'=> $amount_to_charge,
        'status'     => $booking_status
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
