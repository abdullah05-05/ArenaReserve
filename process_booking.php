<?php
/**
 * process_booking.php — AJAX endpoint to process a booking payment
 * POST params: ground_id, slot_date, slot_hour, booking_type, challenger_team_name (optional)
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

$user_id              = intval($_SESSION['user_id']);
$ground_id            = intval($_POST['ground_id'] ?? 0);
$slot_date            = trim($_POST['slot_date'] ?? '');
$slot_hour            = intval($_POST['slot_hour'] ?? -1);
$booking_type         = trim($_POST['booking_type'] ?? '');
$challenger_team_name = trim($_POST['challenger_team_name'] ?? '');
$challenged_user_id   = intval($_POST['challenged_user_id'] ?? 0); // specific opponent for team_challenge

$valid_types = ['direct', 'open_challenge', 'team_challenge'];
if (!$ground_id || !$slot_date || $slot_hour < 0 || !in_array($booking_type, $valid_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

try {
    // 0. Prevent booking past slots
    $slot_start_ts = strtotime($slot_date . ' ' . sprintf('%02d:00:00', $slot_hour));
    if ($slot_start_ts <= time()) {
        echo json_encode(['success' => false, 'message' => 'Cannot book a time slot that has already passed or started.']);
        exit;
    }

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

    // 4. Calculate amount to charge (50% for direct booking advance, 25% each for challenges)
    if ($booking_type === 'direct') {
        $amount_to_charge = round($full_price * 0.50, 2);
    } else {
        // open_challenge and team_challenge: 25% from creator
        $amount_to_charge = round($full_price * 0.25, 2);
    }

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

    // 9. Insert booking (with challenged_user_id for team_challenge)
    $stmt = $pdo->prepare("
        INSERT INTO bookings (ground_id, booked_by, slot_date, slot_hour, price, amount_paid, booking_type, status, challenger_team_name, challenged_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $ground_id, $user_id, $slot_date, $slot_hour,
        $full_price, $amount_to_charge,
        $booking_type, $booking_status,
        $challenger_team_name ?: null,
        ($booking_type === 'team_challenge' && $challenged_user_id > 0) ? $challenged_user_id : null
    ]);
    $booking_id = $pdo->lastInsertId();

    // 10. Remove the hold
    $stmt = $pdo->prepare("DELETE FROM slot_holds WHERE ground_id = ? AND slot_date = ? AND slot_hour = ?");
    $stmt->execute([$ground_id, $slot_date, $slot_hour]);

    // 11. Fetch ground + owner + player details for notifications
    $infoStmt = $pdo->prepare("
        SELECT g.title AS ground_title, g.owner_id,
               u_player.name AS player_name, u_player.email AS player_email,
               u_owner.name AS owner_name, u_owner.email AS owner_email
        FROM grounds g
        JOIN users u_player ON u_player.id = ?
        JOIN users u_owner  ON u_owner.id  = g.owner_id
        WHERE g.id = ?
    ");
    $infoStmt->execute([$user_id, $ground_id]);
    $info = $infoStmt->fetch();

    $pdo->commit();

    // ── In-app notification for player ──
    $playerNotifTitles = [
        'direct'          => 'Booking Confirmed!',
        'open_challenge'  => 'Open Challenge Posted!',
        'team_challenge'  => 'Team Challenge Sent!',
    ];
    $playerNotifMsgs = [
        'direct'          => "Your slot at {$info['ground_title']} on {$slot_date} " . sprintf('%02d:00', $slot_hour) . " is confirmed (50% advance paid, remaining 50% at venue).",
        'open_challenge'  => "Your open challenge at {$info['ground_title']} on {$slot_date} is live (25% advance held). Waiting for opponent!",
        'team_challenge'  => "Your team challenge at {$info['ground_title']} on {$slot_date} is sent (25% advance held). Awaiting acceptance.",
    ];
    createNotification($pdo, $user_id, 'booking_confirmed',
        $playerNotifTitles[$booking_type],
        $playerNotifMsgs[$booking_type],
        'match_history.php'
    );

    // ── In-app notification for ground owner ──
    if ($info && $info['owner_id'] != $user_id) {
        createNotification($pdo, $info['owner_id'], 'new_booking_owner',
            'New Booking on ' . $info['ground_title'],
            "{$info['player_name']} booked a slot on {$slot_date} at " . sprintf('%02d:00', $slot_hour) . ".",
            'owner_dashboard.php'
        );
    }

    // ── In-app + Email: Notify the challenged user (team_challenge only) ──
    if ($booking_type === 'team_challenge' && $challenged_user_id > 0 && $info) {
        // Fetch challenged user's details
        $chalStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $chalStmt->execute([$challenged_user_id]);
        $chalUser = $chalStmt->fetch();
        if ($chalUser) {
            // In-app notification to the challenged user
            createNotification($pdo, $challenged_user_id, 'challenge_received',
                '⚡ Team Challenge Received!',
                "{$info['player_name']} has challenged your team at {$info['ground_title']} on {$slot_date}. Pay 25% to accept in Match History!",
                'match_history.php'
            );
            // Email the challenged user
            sendTeamChallengeSentEmail($chalUser['email'], $chalUser['name'], [
                'ground_title'    => $info['ground_title'],
                'slot_date'       => $slot_date,
                'slot_hour'       => $slot_hour,
                'booking_id'      => $booking_id,
                'challenger_name' => $info['player_name'],
                'message'         => $_POST['ch_message'] ?? '',
            ]);
        }
    }

    // ── Email notification for player + owner (async-safe: after commit) ──
    if ($info) {
        sendBookingConfirmedEmail($info['player_email'], $info['player_name'], [
            'ground_title'  => $info['ground_title'],
            'slot_date'     => $slot_date,
            'slot_hour'     => $slot_hour,
            'booking_type'  => $booking_type,
            'amount_paid'   => $amount_to_charge,
            'booking_id'    => $booking_id,
        ]);

        // Email owner too
        if ($info['owner_id'] != $user_id) {
            sendNewBookingOwnerEmail($info['owner_email'], $info['owner_name'], [
                'ground_title'  => $info['ground_title'],
                'slot_date'     => $slot_date,
                'slot_hour'     => $slot_hour,
                'booking_type'  => $booking_type,
                'player_name'   => $info['player_name'],
            ]);
        }
    }

    $messages = [
        'direct'         => '✅ Booking confirmed! 50% advance deducted from wallet. Please pay remaining 50% at the venue.',
        'open_challenge' => '⚡ Open challenge posted! 25% payment held. Opponent pays 25% to confirm. Remaining 50% paid at venue.',
        'team_challenge' => '🤝 Challenge sent! 25% payment held. Opponent pays 25% to accept. Remaining 50% paid at venue.',
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
