<?php
/**
 * initiate_checkout.php
 * Endpoint to initiate an AssanPay Hosted Checkout session.
 * Supports AJAX JSON requests and standard form submissions.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AssanPayService.php';

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please log in to proceed with payment.']);
    } else {
        header('Location: login.php');
    }
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Fetch user profile from DB
$userStmt = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE id = ?");
$userStmt->execute([$user_id]);
$user = $userStmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User profile not found.']);
    exit;
}

$purpose = trim($_POST['purpose'] ?? 'wallet_topup');
$paymentMethod = trim($_POST['payment_method'] ?? '');
$customerPhone = trim($_POST['phone'] ?? $user['phone']);
$customerEmail = trim($_POST['email'] ?? $user['email']);
$customerName  = trim($_POST['name'] ?? $user['name']);

// Clean customer phone (ensure e.g. 03001234567 format)
$customerPhone = preg_replace('/[^0-9]/', '', $customerPhone);
if (strpos($customerPhone, '92') === 0 && strlen($customerPhone) === 12) {
    $customerPhone = '0' . substr($customerPhone, 2);
}

$amount = 0.00;
$metaData = [];

try {
    if ($purpose === 'wallet_topup') {
        $amount = floatval($_POST['amount'] ?? 0);
        if ($amount < 1.00) {
            throw new Exception('Minimum deposit amount is 1 PKR.');
        }
        $metaData = [
            'type' => 'wallet_topup',
            'user_id' => $user_id,
            'amount' => $amount
        ];
    } elseif ($purpose === 'slot_booking') {
        $ground_id = intval($_POST['ground_id'] ?? 0);
        $slot_date = trim($_POST['slot_date'] ?? '');
        $booking_type = trim($_POST['booking_type'] ?? 'direct');
        $challenger_team_name = trim($_POST['challenger_team_name'] ?? '');
        $challenged_user_id = intval($_POST['challenged_user_id'] ?? 0);
        $ch_message = trim($_POST['ch_message'] ?? ($_POST['challenge_message'] ?? ''));

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

        if (!$ground_id || !$slot_date || empty($slot_hours)) {
            throw new Exception('Invalid booking parameters.');
        }

        // Prevent booking past slots
        $currentTime = time();
        foreach ($slot_hours as $h) {
            $slot_start_ts = strtotime($slot_date . ' ' . sprintf('%02d:00:00', $h));
            if ($slot_start_ts <= $currentTime) {
                $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
                $suf = $h < 12 ? 'AM' : 'PM';
                throw new Exception("The {$displayH}:00 {$suf} slot has already passed or started.");
            }
        }

        $inClause = implode(',', array_fill(0, count($slot_hours), '?'));

        // Check if any slot is already booked
        $stmt = $pdo->prepare("
            SELECT slot_hour FROM bookings
            WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
            AND status NOT IN ('cancelled')
        ");
        $stmt->execute(array_merge([$ground_id, $slot_date], $slot_hours));
        if ($stmt->fetch()) {
            throw new Exception('One or more selected slots were just booked by someone else.');
        }

        // Check if any slot is held by another user
        $stmt = $pdo->prepare("
            SELECT slot_hour FROM slot_holds
            WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
            AND held_by != ? AND expires_at >= NOW()
        ");
        $stmt->execute(array_merge([$ground_id, $slot_date], $slot_hours, [$user_id]));
        if ($stmt->fetch()) {
            throw new Exception('One or more selected slots are currently on hold by another player.');
        }

        // Place / extend hold to 10 minutes for all slot hours
        $holdStmt = $pdo->prepare("
            INSERT INTO slot_holds (ground_id, slot_date, slot_hour, held_by, expires_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
            ON DUPLICATE KEY UPDATE held_by = VALUES(held_by), expires_at = VALUES(expires_at)
        ");
        foreach ($slot_hours as $h) {
            $holdStmt->execute([$ground_id, $slot_date, $h, $user_id]);
        }

        // Get ground slot prices
        $stmt = $pdo->prepare("SELECT hour, price FROM ground_slots WHERE ground_id = ? AND hour IN ($inClause) AND is_available = 1");
        $stmt->execute(array_merge([$ground_id], $slot_hours));
        $slotRows = $stmt->fetchAll();
        $slotPrices = [];
        foreach ($slotRows as $sr) {
            $slotPrices[intval($sr['hour'])] = floatval($sr['price']);
        }

        $total_full_price = 0.0;
        $total_advance = 0.0;
        $items = [];

        foreach ($slot_hours as $h) {
            if (!isset($slotPrices[$h])) {
                throw new Exception("Slot configuration for hour {$h}:00 not found.");
            }
            $fp = $slotPrices[$h];
            $adv = ($booking_type === 'direct') ? round($fp * 0.50, 2) : round($fp * 0.25, 2);
            $total_full_price += $fp;
            $total_advance += $adv;
            $items[$h] = ['full_price' => $fp, 'advance' => $adv];
        }

        $amount = $total_advance;

        $metaData = [
            'type'                 => 'slot_booking',
            'ground_id'            => $ground_id,
            'slot_date'            => $slot_date,
            'slot_hour'            => $slot_hours[0], // primary / first slot hour
            'slot_hours'           => $slot_hours,    // complete array of selected hours
            'items'                => $items,
            'booking_type'         => $booking_type,
            'full_price'           => $total_full_price,
            'amount'               => $amount,
            'challenger_team_name' => $challenger_team_name,
            'challenged_user_id'   => $challenged_user_id,
            'challenge_message'    => $ch_message
        ];
    } elseif ($purpose === 'accept_challenge') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT price FROM bookings WHERE id = ? AND status IN ('challenge_open', 'challenge_pending')");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            throw new Exception('Challenge booking not found or already accepted.');
        }

        $amount = round(floatval($booking['price']) * 0.25, 2);
        $metaData = [
            'type'       => 'accept_challenge',
            'booking_id' => $booking_id,
            'amount'     => $amount
        ];
    } else {
        throw new Exception('Unsupported checkout purpose.');
    }

    // Generate unique compliant Order ID
    $orderId = AssanPayService::generateOrderId('AR');

    // Build absolute return URLs
    $appUrl = get_app_base_url();
    $successUrl  = $appUrl . '/assanpay_return.php?orderId=' . $orderId . '&status=success';
    $failedUrl   = $appUrl . '/assanpay_return.php?orderId=' . $orderId . '&status=failed';
    $pendingUrl  = $appUrl . '/assanpay_return.php?orderId=' . $orderId . '&status=pending';

    // AssanPay cloud servers reject 'localhost' / '127.0.0.1' in server-to-server callbackUrl.
    // Only send callbackUrl if it's on a public/routable host or explicitly configured.
    $callbackUrl = null;
    $host = parse_url($appUrl, PHP_URL_HOST);
    $isLocal = in_array(strtolower((string)$host), ['localhost', '127.0.0.1', '::1', '0.0.0.0', '']);

    $customWebhook = getenv('ASSANPAY_WEBHOOK_URL');
    if (!empty($customWebhook)) {
        $callbackUrl = $customWebhook;
    } elseif (!$isLocal) {
        $callbackUrl = $appUrl . '/assanpay_webhook.php';
    }

    // Insert pending payment record
    $insStmt = $pdo->prepare("
        INSERT INTO payment_transactions 
        (user_id, order_id, amount, purpose, payment_method, meta_data, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $insStmt->execute([
        $user_id,
        $orderId,
        $amount,
        $purpose,
        $paymentMethod ?: null,
        json_encode($metaData)
    ]);
    $transactionId = $pdo->lastInsertId();

    // Instantiate service and create Hosted Checkout session
    $assanPay = new AssanPayService();
    $sessionParams = [
        'amount'            => $amount,
        'orderId'           => $orderId,
        'customerContact'   => $customerPhone,
        'customerEmail'     => $customerEmail,
        'customerName'      => $customerName,
        'successUrl'        => $successUrl,
        'failedUrl'         => $failedUrl,
        'pendingUrl'        => $pendingUrl,
        'expiresInSeconds'  => 600
    ];

    if (!empty($callbackUrl)) {
        $sessionParams['callbackUrl'] = $callbackUrl;
    }

    if (!empty($paymentMethod)) {
        $sessionParams['paymentMethodName'] = $paymentMethod;
    }

    $sessionRes = $assanPay->createCheckoutSession($sessionParams);

    if (!$sessionRes['success']) {
        // Mark failed
        $pdo->prepare("UPDATE payment_transactions SET status = 'failed', raw_callback = ? WHERE id = ?")
            ->execute([json_encode($sessionRes), $transactionId]);

        $rawErr = $sessionRes['error'] ?? 'Failed to initiate AssanPay checkout session.';
        if (stripos($rawErr, 'limit exceeded') !== false || stripos($rawErr, 'PER_TRANSACTION_LIMIT_EXCEEDED') !== false) {
            $rawErr = 'AssanPay Gateway Limit: Your current AssanPay test/sandbox merchant account has a server-side cap of 100 PKR per transaction. To test with AssanPay, please use an amount ≤ 100 PKR (or use your live production credentials to remove this limit).';
        }

        throw new Exception($rawErr);
    }

    // Update with session details
    $pdo->prepare("
        UPDATE payment_transactions 
        SET session_id = ?, payment_id = ? 
        WHERE id = ?
    ")->execute([
        $sessionRes['sessionId'] ?? null,
        $sessionRes['paymentId'] ?? null,
        $transactionId
    ]);

    // Handle AJAX vs Redirect
    $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
              || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_POST['format']) && $_POST['format'] === 'json');

    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'     => true,
            'orderId'     => $orderId,
            'sessionId'   => $sessionRes['sessionId'] ?? '',
            'checkoutUrl' => $sessionRes['checkoutUrl'] ?? '',
            'amount'      => $amount,
            'message'     => 'Redirecting to AssanPay Hosted Checkout...'
        ]);
        exit;
    }

    // Direct Browser Redirection
    header('Location: ' . $sessionRes['checkoutUrl']);
    exit;

} catch (Exception $e) {
    $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
              || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_POST['format']) && $_POST['format'] === 'json');

    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    $_SESSION['payment_error'] = $e->getMessage();
    header('Location: wallet.php');
    exit;
}
