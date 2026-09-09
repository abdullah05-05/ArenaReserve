<?php
/**
 * process_booking.php — AJAX endpoint to process a booking payment for one or multiple slots.
 * POST params: ground_id, slot_date, slot_hours (or slot_hour), booking_type, challenger_team_name (optional), ch_message (optional)
 * Returns JSON
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'notifications.php';
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id              = intval($_SESSION['user_id']);
$ground_id            = intval($_POST['ground_id'] ?? 0);
$slot_date            = trim($_POST['slot_date'] ?? '');
$booking_type         = trim($_POST['booking_type'] ?? '');
$challenger_team_name = trim($_POST['challenger_team_name'] ?? '');
$challenged_user_id   = intval($_POST['challenged_user_id'] ?? 0);
$ch_message           = trim($_POST['ch_message'] ?? ($_POST['challenge_message'] ?? ''));

// Parse slot hours (can be array e.g. [14, 15] or comma-separated "14,15" or single "slot_hour")
$slot_hours = [];
if (isset($_POST['slot_hours'])) {
    if (is_array($_POST['slot_hours'])) {
        $slot_hours = array_map('intval', $_POST['slot_hours']);
    } else {
        $raw = trim($_POST['slot_hours']);
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $slot_hours = array_map('intval', $decoded);
            }
        } else {
            $slot_hours = array_map('intval', explode(',', $raw));
        }
    }
} elseif (isset($_POST['slot_hour']) && intval($_POST['slot_hour']) >= 0) {
    $slot_hours = [intval($_POST['slot_hour'])];
}

$slot_hours = array_values(array_unique(array_filter($slot_hours, fn($h) => $h >= 0 && $h <= 23)));
sort($slot_hours);

$valid_types = ['direct', 'open_challenge', 'team_challenge'];
if (!$ground_id || !$slot_date || empty($slot_hours) || !in_array($booking_type, $valid_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

try {
    // 0. Prevent booking past slots
    $currentTime = time();
    foreach ($slot_hours as $h) {
        $slot_start_ts = strtotime($slot_date . ' ' . sprintf('%02d:00:00', $h));
        if ($slot_start_ts <= $currentTime) {
            $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
            $suf = $h < 12 ? 'AM' : 'PM';
            echo json_encode(['success' => false, 'message' => "The {$displayH}:00 {$suf} slot has already passed or started."]);
            exit;
        }
    }

    $pdo->beginTransaction();

    $inClause = implode(',', array_fill(0, count($slot_hours), '?'));

    // 1. Verify active holds for all slots belong to this user
    $stmt = $pdo->prepare("
        SELECT slot_hour FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
        AND held_by = ? AND expires_at >= NOW()
    ");
    $stmt->execute(array_merge([$ground_id, $slot_date], $slot_hours, [$user_id]));
    $heldHours = array_map('intval', array_column($stmt->fetchAll(), 'slot_hour'));
    
    // Check if any slot is missing hold
    $missingHolds = array_diff($slot_hours, $heldHours);
    if (!empty($missingHolds)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'One or more of your slot holds have expired. Please re-select the slots.']);
        exit;
    }

    // 2. Double-check none are already booked
    $stmt = $pdo->prepare("
        SELECT slot_hour FROM bookings
        WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
        AND status NOT IN ('cancelled')
    ");
    $stmt->execute(array_merge([$ground_id, $slot_date], $slot_hours));
    $alreadyBooked = $stmt->fetchAll();
    if (!empty($alreadyBooked)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'One or more slots were just booked by someone else.']);
        exit;
    }

    // 3. Get slot prices from ground_slots
    $stmt = $pdo->prepare("
        SELECT hour, price FROM ground_slots
        WHERE ground_id = ? AND hour IN ($inClause) AND is_available = 1
    ");
    $stmt->execute(array_merge([$ground_id], $slot_hours));
    $slotPriceRows = $stmt->fetchAll();
    
    $slotPrices = [];
    foreach ($slotPriceRows as $sp) {
        $slotPrices[intval($sp['hour'])] = floatval($sp['price']);
    }

    $total_full_price = 0.0;
    $total_amount_to_charge = 0.0;
    $items = [];

    foreach ($slot_hours as $h) {
        if (!isset($slotPrices[$h])) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => "Slot configuration for hour {$h}:00 not found."]);
            exit;
        }
        $full_price = $slotPrices[$h];
        $advance = ($booking_type === 'direct') ? round($full_price * 0.50, 2) : round($full_price * 0.25, 2);
        
        $total_full_price += $full_price;
        $total_amount_to_charge += $advance;

        $items[$h] = [
            'full_price' => $full_price,
            'advance'    => $advance
        ];
    }

    // 4. Check wallet balance
    $stmt = $pdo->prepare("SELECT id, available_balance FROM wallets WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    if (!$wallet || floatval($wallet['available_balance']) < $total_amount_to_charge) {
        $pdo->rollBack();
        echo json_encode([
            'success'  => false,
            'message'  => 'Insufficient wallet balance. Please top up your wallet or pay online via JazzCash.',
            'required' => $total_amount_to_charge,
            'balance'  => floatval($wallet['available_balance'] ?? 0)
        ]);
        exit;
    }

    // 5. Determine booking status
    $status_map = [
        'direct'          => 'confirmed',
        'open_challenge'  => 'challenge_open',
        'team_challenge'  => 'challenge_pending',
    ];
    $booking_status = $status_map[$booking_type];

    // 6. Deduct wallet balance
    $stmt = $pdo->prepare("UPDATE wallets SET available_balance = available_balance - ? WHERE user_id = ?");
    $stmt->execute([$total_amount_to_charge, $user_id]);

    // 7. Record wallet transaction
    $ref = 'BK-' . strtoupper($booking_type) . '-' . $ground_id . '-' . $slot_date . '-' . implode('_', $slot_hours);
    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id)
        VALUES (?, ?, 'Booking_Payment', ?)
    ");
    $stmt->execute([$wallet['id'], -$total_amount_to_charge, substr($ref, 0, 100)]);

    // Ensure challenge_message column exists
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'challenge_message'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN challenge_message TEXT DEFAULT NULL");
        }
    } catch (Exception $e) {}

    // 8. Insert individual booking records for each slot hour
    $insertBookingStmt = $pdo->prepare("
        INSERT INTO bookings 
        (ground_id, booked_by, slot_date, slot_hour, price, amount_paid, booking_type, status, challenger_team_name, challenged_user_id, challenge_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $createdBookingIds = [];
    foreach ($slot_hours as $h) {
        $full_p = $items[$h]['full_price'];
        $adv_p  = $items[$h]['advance'];

        $insertBookingStmt->execute([
            $ground_id, $user_id, $slot_date, $h,
            $full_p, $adv_p,
            $booking_type, $booking_status,
            $challenger_team_name ?: null,
            ($booking_type === 'team_challenge' && $challenged_user_id > 0) ? $challenged_user_id : null,
            ($booking_type === 'team_challenge' && !empty($ch_message)) ? $ch_message : null
        ]);
        $createdBookingIds[] = $pdo->lastInsertId();
    }

    // 9. Remove holds for all slot hours
    $delHoldStmt = $pdo->prepare("DELETE FROM slot_holds WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)");
    $delHoldStmt->execute(array_merge([$ground_id, $slot_date], $slot_hours));

    // 10. Fetch ground + owner + player details for notifications
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

    // Format human-readable time strings
    $timeLabels = [];
    foreach ($slot_hours as $h) {
        $suffix     = $h < 12 ? 'AM' : 'PM';
        $displayH   = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
        $nextH      = $h + 1;
        $nextDisp   = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
        $nextSuffix = $nextH < 12 ? 'AM' : 'PM';
        $timeLabels[] = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuffix);
    }
    $timeString = implode(', ', $timeLabels);
    $slotCount = count($slot_hours);

    // ── In-app notification for player ──
    $playerNotifTitles = [
        'direct'          => $slotCount > 1 ? "{$slotCount} Bookings Confirmed!" : 'Booking Confirmed!',
        'open_challenge'  => $slotCount > 1 ? "{$slotCount} Open Challenges Posted!" : 'Open Challenge Posted!',
        'team_challenge'  => $slotCount > 1 ? "{$slotCount} Team Challenges Sent!" : 'Team Challenge Sent!',
    ];
    $playerNotifMsgs = [
        'direct'          => "Your slots ({$timeString}) at {$info['ground_title']} on {$slot_date} are confirmed (50% advance paid, remaining 50% at venue).",
        'open_challenge'  => "Your open challenges ({$timeString}) at {$info['ground_title']} on {$slot_date} are live (25% advance held). Waiting for opponents!",
        'team_challenge'  => "Your team challenges ({$timeString}) at {$info['ground_title']} on {$slot_date} are sent (25% advance held). Awaiting acceptance.",
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
            "{$info['player_name']} booked {$slotCount} slot(s) ({$timeString}) on {$slot_date}.",
            'owner_dashboard.php'
        );
    }

    // ── In-app + Email: Notify challenged user (team_challenge) ──
    if ($booking_type === 'team_challenge' && $challenged_user_id > 0 && $info) {
        $chalStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $chalStmt->execute([$challenged_user_id]);
        $chalUser = $chalStmt->fetch();
        if ($chalUser) {
            $chalNotifMsg = "{$info['player_name']} challenged your team for {$slotCount} slot(s) ({$timeString}) at {$info['ground_title']} on {$slot_date}. Pay 25% to accept in Match History!";
            if (!empty($ch_message)) {
                $chalNotifMsg .= " Message: \"{$ch_message}\"";
            }
            createNotification($pdo, $challenged_user_id, 'challenge_received',
                '⚡ Team Challenge Received!',
                $chalNotifMsg,
                'match_history.php'
            );
            foreach ($slot_hours as $idx => $h) {
                sendTeamChallengeSentEmail($chalUser['email'], $chalUser['name'], [
                    'ground_title'    => $info['ground_title'],
                    'slot_date'       => $slot_date,
                    'slot_hour'       => $h,
                    'booking_id'      => $createdBookingIds[$idx] ?? 0,
                    'challenger_name' => $info['player_name'],
                    'message'         => $ch_message,
                ]);
            }
        }
    }

    // ── Email notification for player + owner ──
    if ($info) {
        foreach ($slot_hours as $idx => $h) {
            sendBookingConfirmedEmail($info['player_email'], $info['player_name'], [
                'ground_title'  => $info['ground_title'],
                'slot_date'     => $slot_date,
                'slot_hour'     => $h,
                'booking_type'  => $booking_type,
                'amount_paid'   => $items[$h]['advance'],
                'booking_id'    => $createdBookingIds[$idx] ?? 0,
            ]);

            if ($info['owner_id'] != $user_id) {
                sendNewBookingOwnerEmail($info['owner_email'], $info['owner_name'], [
                    'ground_title'  => $info['ground_title'],
                    'slot_date'     => $slot_date,
                    'slot_hour'     => $h,
                    'booking_type'  => $booking_type,
                    'player_name'   => $info['player_name'],
                ]);
            }
        }
    }

    $messages = [
        'direct'         => $slotCount > 1 ? "✅ {$slotCount} Slots confirmed! 50% advance deducted from wallet. Please pay remaining 50% at the venue." : '✅ Booking confirmed! 50% advance deducted from wallet. Please pay remaining 50% at the venue.',
        'open_challenge' => $slotCount > 1 ? "⚡ {$slotCount} Open challenges posted! 25% payment held. Opponents pay 25% to confirm." : '⚡ Open challenge posted! 25% payment held. Opponent pays 25% to confirm. Remaining 50% paid at venue.',
        'team_challenge' => $slotCount > 1 ? "🤝 {$slotCount} Challenges sent! 25% payment held. Opponent pays 25% to accept." : '🤝 Challenge sent! 25% payment held. Opponent pays 25% to accept. Remaining 50% paid at venue.',
    ];

    echo json_encode([
        'success'      => true,
        'booking_id'   => $createdBookingIds[0] ?? 0,
        'booking_ids'  => $createdBookingIds,
        'slot_hours'   => $slot_hours,
        'message'      => $messages[$booking_type],
        'amount_paid'  => $total_amount_to_charge,
        'status'       => $booking_status
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
