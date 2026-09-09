<?php
/**
 * initiate_checkout.php
 * Endpoint to initiate a JazzCash Payment Gateway Hosted Checkout session (v1.1).
 * Supports AJAX JSON requests and standard form submissions.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/JazzCashService.php';

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

$purpose       = trim($_POST['purpose'] ?? 'wallet_topup');
$paymentMethod = trim($_POST['payment_method'] ?? '');
$customerPhone = trim($_POST['phone'] ?? ($user['phone'] ?? ''));
$customerEmail = trim($_POST['email'] ?? ($user['email'] ?? ''));
$customerName  = trim($_POST['name'] ?? ($user['name'] ?? ''));

// Clean customer phone (ensure e.g. 03001234567 format)
$customerPhone = preg_replace('/[^0-9]/', '', $customerPhone);
if (strpos($customerPhone, '92') === 0 && strlen($customerPhone) === 12) {
    $customerPhone = '0' . substr($customerPhone, 2);
}

$amount = 0.00;
$metaData = [];
$description = 'ArenaReserve Payment';

try {
    if ($purpose === 'wallet_topup') {
        $amount = floatval($_POST['amount'] ?? 0);
        if ($amount < 1.00) {
            throw new Exception('Minimum deposit amount is 1 PKR.');
        }
        $description = 'ArenaReserve Wallet Top-up';
        $metaData = [
            'type'    => 'wallet_topup',
            'user_id' => $user_id,
            'amount'  => $amount
        ];
    } elseif ($purpose === 'slot_booking') {
        $ground_id            = intval($_POST['ground_id'] ?? 0);
        $slot_date            = trim($_POST['slot_date'] ?? '');
        $booking_type         = trim($_POST['booking_type'] ?? 'direct');
        $challenger_team_name = trim($_POST['challenger_team_name'] ?? '');
        $challenged_user_id   = intval($_POST['challenged_user_id'] ?? 0);
        $ch_message           = trim($_POST['ch_message'] ?? ($_POST['challenge_message'] ?? ''));

        // Parse slot hours (array or comma-separated string)
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
        $description = 'Slot Booking (' . count($slot_hours) . ' slots)';

        $metaData = [
            'type'                 => 'slot_booking',
            'ground_id'            => $ground_id,
            'slot_date'            => $slot_date,
            'slot_hour'            => $slot_hours[0],
            'slot_hours'           => $slot_hours,
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
        $description = 'Challenge Share Payment';
        $metaData = [
            'type'       => 'accept_challenge',
            'booking_id' => $booking_id,
            'amount'     => $amount
        ];
    } else {
        throw new Exception('Unsupported checkout purpose.');
    }

    // Generate unique compliant Order ID and TxnRefNo
    $orderId = 'AR' . date('ymd') . strtoupper(substr(uniqid(), -6));
    $txnRefNo = JazzCashService::generateTxnRefNo('TRN');

    $jc = new JazzCashService();

    // Check credentials configured
    if (empty($jc->getMerchantId())) {
        throw new Exception('JazzCash Merchant ID is not configured.');
    }

    // Insert pending payment record
    $insStmt = $pdo->prepare("
        INSERT INTO payment_transactions 
        (user_id, order_id, session_id, amount, purpose, payment_method, meta_data, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $insStmt->execute([
        $user_id,
        $orderId,
        $txnRefNo,
        $amount,
        $purpose,
        $paymentMethod ?: 'JazzCash',
        json_encode($metaData)
    ]);
    $transactionId = $pdo->lastInsertId();

    // Detect if initiating from local development environment
    $isLocal = false;
    if (isset($_SERVER['HTTP_HOST'])) {
        $hostOnly = strtolower(explode(':', $_SERVER['HTTP_HOST'])[0]);
        $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1', '0.0.0.0']) || str_ends_with($hostOnly, '.test') || str_ends_with($hostOnly, '.local');
    }

    $extra = [];
    if ($isLocal) {
        // Pass local return URL in ppmpf_3 so live return URL can bridge back to localhost
        $extra['ppmpf_3'] = get_app_base_url() . '/jazzcash_return.php';
    }

    // Determine JazzCash transaction type (empty string for hosted multi-option checkout, or specific like MPAY / MWALLET)
    $txnType = '';
    if (in_array(strtoupper($paymentMethod), ['MPAY', 'MWALLET', 'OTC'])) {
        $txnType = strtoupper($paymentMethod);
    }

    // Build Hosted Checkout payload
    $checkoutData = $jc->buildCheckoutPayload($amount, $txnRefNo, $orderId, $description, $txnType, $extra);

    // Handle AJAX JSON request
    $isJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
              || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_POST['format']) && $_POST['format'] === 'json');

    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'   => true,
            'orderId'   => $orderId,
            'txnRefNo'  => $txnRefNo,
            'post_url'  => $checkoutData['post_url'],
            'params'    => $checkoutData['params'],
            'amount'    => $amount,
            'message'   => 'Redirecting to JazzCash Payment Gateway...'
        ]);
        exit;
    }

    // Direct Browser HTML auto-submit redirection
    $postUrl = htmlspecialchars($checkoutData['post_url'], ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Connecting to JazzCash...</title>
        <style>
            body { font-family: system-ui, sans-serif; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .box { text-align: center; background: #1e293b; padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.4); max-width: 420px; }
            .spinner { width: 44px; height: 44px; border: 4px solid #334155; border-top-color: #10b981; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 1.5rem; }
            @keyframes spin { to { transform: rotate(360deg); } }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="spinner"></div>
            <h2 style="margin: 0 0 0.5rem; font-size: 1.25rem;">Connecting to JazzCash</h2>
            <p style="margin: 0; color: #94a3b8; font-size: 0.875rem;">Please wait while we transfer you to the secure payment portal...</p>
            <form id="jc_form" method="POST" action="<?php echo $postUrl; ?>">
                <?php foreach ($checkoutData['params'] as $k => $v): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>">
                <?php endforeach; ?>
                <noscript><button type="submit" style="margin-top: 1rem; padding: 0.5rem 1rem;">Click here if not redirected</button></noscript>
            </form>
            <script>document.getElementById('jc_form').submit();</script>
        </div>
    </body>
    </html>
    <?php
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
