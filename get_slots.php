<?php
/**
 * get_slots.php — AJAX endpoint to fetch real-time slots data for a ground and date
 * GET / POST params: ground_id (int), slot_date (string 'YYYY-MM-DD')
 * Returns JSON
 */
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = intval($_SESSION['user_id']);
$ground_id = intval($_REQUEST['ground_id'] ?? 0);
$slot_date = trim($_REQUEST['slot_date'] ?? date('Y-m-d'));

if ($slot_date < date('Y-m-d')) {
    $slot_date = date('Y-m-d');
}

if (!$ground_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ground ID.']);
    exit;
}

try {
    // 1. Fetch wallet balance
    $wStmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $wStmt->execute([$user_id]);
    $wallet = $wStmt->fetch();
    $available_balance = floatval($wallet['available_balance'] ?? 0);

    // 2. Clean expired holds first
    try { $pdo->exec("DELETE FROM slot_holds WHERE expires_at < NOW()"); } catch(Exception $e) {}

    // 3. Fetch configured slots for this ground
    $stmt = $pdo->prepare("
        SELECT hour, slot_type, price
        FROM ground_slots
        WHERE ground_id = ? AND is_available = 1
        ORDER BY hour ASC
    ");
    $stmt->execute([$ground_id]);
    $db_slots = $stmt->fetchAll();

    if (empty($db_slots)) {
        echo json_encode([
            'success'           => true,
            'ground_id'         => $ground_id,
            'slot_date'         => $slot_date,
            'available_balance' => $available_balance,
            'no_slots'          => true,
            'slots'             => []
        ]);
        exit;
    }

    // 4. Fetch bookings for this ground & date
    $bStmt = $pdo->prepare("
        SELECT slot_hour, status, booked_by, challenger_team_name, challenged_user_id
        FROM bookings
        WHERE ground_id = ? AND slot_date = ? AND status NOT IN ('cancelled')
    ");
    $bStmt->execute([$ground_id, $slot_date]);
    $bookings_map = [];
    foreach ($bStmt->fetchAll() as $b) {
        $bookings_map[intval($b['slot_hour'])] = $b;
    }

    // 5. Fetch active holds
    $hStmt = $pdo->prepare("
        SELECT slot_hour, held_by, expires_at,
               GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining_sec
        FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND expires_at >= NOW()
    ");
    $hStmt->execute([$ground_id, $slot_date]);
    $holds_map = [];
    foreach ($hStmt->fetchAll() as $h) {
        $holds_map[intval($h['slot_hour'])] = $h;
    }

    $slots = [];
    foreach ($db_slots as $s) {
        $h = intval($s['hour']);
        $suffix     = $h < 12 ? 'AM' : 'PM';
        $displayH   = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
        $nextH      = $h + 1;
        $nextDisp   = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
        $nextSuffix = $nextH < 12 ? 'AM' : 'PM';
        $time_label = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuffix);

        $type           = 'available';
        $label          = '';
        $hold_remaining = 0;

        if (isset($bookings_map[$h])) {
            $bk = $bookings_map[$h];
            if (intval($bk['booked_by']) === $user_id) {
                $type  = 'my_booking';
                $label = match($bk['status']) {
                    'confirmed'          => 'My Booking',
                    'challenge_open'     => 'My Challenge',
                    'challenge_pending'  => 'Pending',
                    'challenge_accepted' => 'Match Set',
                    default              => 'My Booking'
                };
            } else {
                $type  = match($bk['status']) {
                    'challenge_open'    => 'challenge',
                    'challenge_pending' => 'challenge',
                    default            => 'booked'
                };
                $label = match($bk['status']) {
                    'challenge_open'    => 'Open Challenge',
                    'challenge_pending' => 'Challenge',
                    default            => 'Booked'
                };
            }
        } elseif (isset($holds_map[$h])) {
            $hold = $holds_map[$h];
            if (intval($hold['held_by']) === $user_id) {
                $type           = 'held';
                $hold_remaining = intval($hold['remaining_sec']);
            } else {
                $type           = 'on_hold';
                $hold_remaining = intval($hold['remaining_sec']);
            }
        }

        $slots[] = [
            'hour'           => $h,
            'time'           => $time_label,
            'type'           => $type,
            'slot_type'      => $s['slot_type'] ?? 'Normal',
            'price'          => floatval($s['price']),
            'label'          => $label,
            'hold_remaining' => $hold_remaining,
        ];
    }

    echo json_encode([
        'success'           => true,
        'ground_id'         => $ground_id,
        'slot_date'         => $slot_date,
        'available_balance' => $available_balance,
        'no_slots'          => false,
        'slots'             => $slots
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
