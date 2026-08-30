<?php
/**
 * hold_slot.php — AJAX endpoint to place, refresh, or release a 10-minute hold on one or multiple slots.
 * Uses MySQL NOW() exclusively to avoid PHP ↔ MySQL timezone mismatches.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = intval($_SESSION['user_id']);
$ground_id = intval($_POST['ground_id'] ?? 0);
$slot_date = trim($_POST['slot_date'] ?? '');
$action    = trim($_POST['action'] ?? 'hold'); // 'hold', 'release', 'toggle'

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

if (!$ground_id || !$slot_date || empty($slot_hours)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

// Check action = 'release'
if ($action === 'release') {
    try {
        $inClause = implode(',', array_fill(0, count($slot_hours), '?'));
        $stmt = $pdo->prepare("DELETE FROM slot_holds WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause) AND held_by = ?");
        $params = array_merge([$ground_id, $slot_date], $slot_hours, [$user_id]);
        $stmt->execute($params);

        echo json_encode([
            'success'      => true,
            'action'       => 'released',
            'slot_hours'   => $slot_hours,
            'message'      => 'Hold released.'
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error releasing hold: ' . $e->getMessage()]);
        exit;
    }
}

// Validate that slots are not in the past
$currentTime = time();
foreach ($slot_hours as $h) {
    $slot_start_ts = strtotime($slot_date . ' ' . sprintf('%02d:00:00', $h));
    if ($slot_start_ts <= $currentTime) {
        $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
        $suf = $h < 12 ? 'AM' : 'PM';
        echo json_encode([
            'success' => false,
            'message' => "The {$displayH}:00 {$suf} time slot has already passed or started."
        ]);
        exit;
    }
}

try {
    // 1. Clean all expired holds
    $pdo->prepare("DELETE FROM slot_holds WHERE expires_at < NOW()")->execute();

    // 2. Check if any requested slot is already booked
    $inClause = implode(',', array_fill(0, count($slot_hours), '?'));
    $stmt = $pdo->prepare("
        SELECT slot_hour FROM bookings
        WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
        AND status NOT IN ('cancelled')
    ");
    $stmt->execute(array_merge([$ground_id, $slot_date], $slot_hours));
    $bookedRows = $stmt->fetchAll();
    if (!empty($bookedRows)) {
        $bookedHours = array_column($bookedRows, 'slot_hour');
        $h = $bookedHours[0];
        $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
        $suf = $h < 12 ? 'AM' : 'PM';
        echo json_encode([
            'success' => false,
            'message' => "The {$displayH}:00 {$suf} slot is already booked."
        ]);
        exit;
    }

    // 3. Check for active holds by OTHER users
    $stmt = $pdo->prepare("
        SELECT slot_hour, held_by,
               TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS remaining_sec
        FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
        AND expires_at >= NOW() AND held_by != ?
    ");
    $stmt->execute(array_merge([$ground_id, $slot_date], $slot_hours, [$user_id]));
    $otherHolds = $stmt->fetchAll();
    if (!empty($otherHolds)) {
        $conflict = $otherHolds[0];
        $h = intval($conflict['slot_hour']);
        $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
        $suf = $h < 12 ? 'AM' : 'PM';
        $rem = max(0, intval($conflict['remaining_sec']));
        echo json_encode([
            'success'   => false,
            'message'   => "The {$displayH}:00 {$suf} slot is on hold by another user. Try again in " . ceil($rem / 60) . ' min.',
            'remaining' => $rem
        ]);
        exit;
    }

    // 4. Insert or refresh holds for all requested slot hours
    $holdStmt = $pdo->prepare("
        INSERT INTO slot_holds (ground_id, slot_date, slot_hour, held_by, expires_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
        ON DUPLICATE KEY UPDATE
            held_by    = VALUES(held_by),
            expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
    ");
    foreach ($slot_hours as $h) {
        $holdStmt->execute([$ground_id, $slot_date, $h, $user_id]);
    }

    // 5. Read back minimum remaining seconds from MySQL
    $stmt = $pdo->prepare("
        SELECT MIN(TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining_sec,
               MAX(expires_at) AS expires_at
        FROM slot_holds
        WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause) AND held_by = ?
    ");
    $stmt->execute(array_merge([$ground_id, $slot_date], $slot_hours, [$user_id]));
    $row = $stmt->fetch();
    $remaining = max(0, intval($row['remaining_sec'] ?? 600));

    echo json_encode([
        'success'    => true,
        'slot_hours' => $slot_hours,
        'expires_at' => $row['expires_at'] ?? '',
        'remaining'  => $remaining
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
