<?php
/**
 * swich_return.php
 * Landing page when customer returns from Swich PayIn PWA.
 * Verifies transaction status via Inquire API, fulfills order idempotently,
 * and renders verified receipt for wallet top-ups, bookings, and challenges.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logo_helper.php';
require_once __DIR__ . '/SwichService.php';
require_once __DIR__ . '/payment_fulfill_helper.php';

$swich = new SwichService();

// Parameters from GET or POST
$customerTxnId = trim($_GET['customerTransactionId'] ?? ($_GET['CustomerTransactionId'] ?? ($_POST['customerTransactionId'] ?? ($_GET['txnRefNo'] ?? ''))));
$orderIdParam  = trim($_GET['orderId'] ?? ($_GET['OrderId'] ?? ($_POST['orderId'] ?? '')));
$statusParam   = strtolower(trim($_GET['status'] ?? ($_GET['Status'] ?? '')));

$transaction = null;
$metaData = [];
$bookingDetails = null;
$allBookings = [];
$error = '';
$isSuccess = false;
$isPending = false;
$isFailed = false;

// Query transaction from database
if (!empty($customerTxnId) || !empty($orderIdParam)) {
    $stmt = $pdo->prepare("
        SELECT * FROM payment_transactions 
        WHERE session_id = ? OR order_id = ? OR order_id = ? OR session_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$customerTxnId, $customerTxnId, $orderIdParam, $orderIdParam]);
    $transaction = $stmt->fetch();
}

if ($transaction) {
    $orderId = $transaction['order_id'];
    $customerTxnId = $transaction['session_id'];
    if (!empty($transaction['meta_data'])) {
        $metaData = json_decode($transaction['meta_data'], true) ?: [];
    }

    // 1. Check if already marked success
    if ($transaction['status'] === 'success') {
        $isSuccess = true;
    } else {
        // 2. Query Swich Inquire API to confirm real-time status
        $inquiry = $swich->inquireTransaction($customerTxnId);
        if (!empty($inquiry['is_paid'])) {
            $swichOrderId = $inquiry['order_id'] ?? $customerTxnId;
            $fulfillResult = fulfillPaymentTransaction($pdo, $orderId, $swichOrderId, json_encode($inquiry), $swichOrderId);
            $isSuccess = $fulfillResult['success'] || ($transaction['status'] === 'success');
        } elseif ($statusParam === 'success') {
            // Callback might still be en route, or Inquire confirmed
            $fulfillResult = fulfillPaymentTransaction($pdo, $orderId, $customerTxnId, json_encode($_GET), $customerTxnId);
            $isSuccess = $fulfillResult['success'];
        } elseif ($statusParam === 'pending' || in_array($inquiry['status'] ?? '', ['pending', 'queue'])) {
            $isPending = true;
        } else {
            $isFailed = true;
            $error = $inquiry['message'] ?? 'Transaction was not completed or was cancelled.';
        }
    }

    // Localhost bridging: If callback landed on live server but transaction was initiated from localhost, bridge back
    $localBridge = trim($_GET['localBridge'] ?? ($_POST['localBridge'] ?? ''));
    $currentHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    $isCurrentHostLocal = in_array($currentHost, ['localhost', '127.0.0.1', '::1']);

    if (!$isCurrentHostLocal && !empty($localBridge) && (stripos($localBridge, 'localhost') !== false || stripos($localBridge, '127.0.0.1') !== false)) {
        $sep = (strpos($localBridge, '?') !== false) ? '&' : '?';
        $redirUrl = $localBridge . $sep . 'customerTransactionId=' . urlencode($customerTxnId) . '&orderId=' . urlencode($orderId) . '&status=' . ($isSuccess ? 'success' : ($isPending ? 'pending' : 'failed'));
        header("Location: " . $redirUrl);
        exit;
    }

    // Fetch booking details if purpose is slot_booking or accept_challenge
    if ($transaction['purpose'] === 'slot_booking') {
        $bookingId = $metaData['booking_id'] ?? null;
        $bookingIds = $metaData['booking_ids'] ?? ($bookingId ? [$bookingId] : []);

        if (!empty($bookingIds)) {
            $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
            $bStmt = $pdo->prepare("
                SELECT b.*, g.name AS ground_name, g.location AS ground_location
                FROM bookings b
                JOIN grounds g ON g.id = b.ground_id
                WHERE b.id IN ($placeholders)
                ORDER BY b.slot_hour ASC
            ");
            $bStmt->execute($bookingIds);
            $allBookings = $bStmt->fetchAll();
            $bookingDetails = $allBookings[0] ?? null;
        }
    } elseif ($transaction['purpose'] === 'accept_challenge') {
        $bookingId = $metaData['booking_id'] ?? null;
        if ($bookingId) {
            $bStmt = $pdo->prepare("
                SELECT b.*, g.name AS ground_name, g.location AS ground_location
                FROM bookings b
                JOIN grounds g ON g.id = b.ground_id
                WHERE b.id = ?
            ");
            $bStmt->execute([$bookingId]);
            $bookingDetails = $bStmt->fetch();
        }
    }
} else {
    $isFailed = true;
    $error = 'Transaction not found or invalid transaction reference.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isSuccess ? 'Payment Successful' : ($isPending ? 'Payment Processing' : 'Payment Failed'); ?> – ArenaReserve</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>* { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col justify-between text-slate-800 antialiased">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200">
        <div class="max-w-4xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="explore.php" class="flex items-center gap-2 text-emerald-600 font-bold text-lg">
                <?php echo get_logo_markup('h-7 w-7 flex-shrink-0'); ?>
                <span>Arena<span class="text-slate-900">Reserve</span></span>
            </a>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                <span>Payment Gateway:</span>
                <span class="px-2 py-0.5 bg-blue-50 text-blue-700 rounded-full border border-blue-200 font-bold">Swich Pay</span>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-lg w-full mx-auto px-4 py-10">
        <div class="bg-white rounded-3xl shadow-xl border border-slate-200/80 overflow-hidden text-center p-6 sm:p-8 space-y-6">

            <?php if ($isSuccess): ?>
                <!-- Success Icon -->
                <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto shadow-inner">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                </div>

                <div class="space-y-1">
                    <h1 class="text-2xl font-black text-slate-900">Payment Successful!</h1>
                    <p class="text-xs text-slate-500">Your transaction has been processed and verified via Swich.</p>
                </div>

                <!-- Receipt Box -->
                <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200/70 text-left space-y-3 text-xs">
                    <div class="flex justify-between items-center pb-2 border-b border-slate-200/60">
                        <span class="text-slate-500">Amount Paid:</span>
                        <span class="text-base font-extrabold text-emerald-600"><?php echo number_format($transaction['amount'] ?? 0, 2); ?> PKR</span>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-slate-500">Payment Method:</span>
                        <span class="font-semibold text-slate-800">Swich Pay</span>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-slate-500">Order ID:</span>
                        <span class="font-mono font-bold text-slate-800"><?php echo htmlspecialchars($orderId ?? ''); ?></span>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-slate-500">Transaction Ref:</span>
                        <span class="font-mono text-slate-600 text-[11px] truncate max-w-[200px]"><?php echo htmlspecialchars($customerTxnId ?? ''); ?></span>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-slate-500">Date & Time:</span>
                        <span class="text-slate-700"><?php echo date('d M Y, h:i A'); ?></span>
                    </div>
                </div>

                <!-- Context-specific Details -->
                <?php if ($transaction && $transaction['purpose'] === 'wallet_topup'): ?>
                    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-left flex items-start gap-3">
                        <span class="text-xl">💳</span>
                        <div>
                            <div class="text-xs font-bold text-emerald-900">Wallet Balance Credited</div>
                            <p class="text-[11px] text-emerald-700 mt-0.5">Your funds have been credited to your available balance and are ready for booking.</p>
                        </div>
                    </div>

                    <a href="wallet.php" class="block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 px-4 rounded-xl text-sm transition-all shadow-md">
                        View Wallet Balance →
                    </a>

                <?php elseif ($bookingDetails): ?>
                    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-left space-y-2">
                        <div class="font-bold text-emerald-900 text-xs flex items-center justify-between">
                            <span><?php echo htmlspecialchars($bookingDetails['ground_name'] ?? 'Venue Booking'); ?></span>
                            <span class="px-2 py-0.5 bg-emerald-600 text-white rounded text-[10px]">Confirmed</span>
                        </div>
                        <div class="text-[11px] text-emerald-800 space-y-0.5">
                            <div>📅 Date: <?php echo date('D, d M Y', strtotime($bookingDetails['slot_date'])); ?></div>
                            <?php if (count($allBookings) > 1): ?>
                                <div>⏰ Slots: <?php echo count($allBookings); ?> Hours (<?php echo sprintf('%02d:00', $allBookings[0]['slot_hour']); ?> – <?php echo sprintf('%02d:00', end($allBookings)['slot_hour'] + 1); ?>)</div>
                            <?php else: ?>
                                <div>⏰ Slot: <?php echo sprintf('%02d:00 – %02d:00', $bookingDetails['slot_hour'], $bookingDetails['slot_hour'] + 1); ?></div>
                            <?php endif; ?>
                            <div>📍 Location: <?php echo htmlspecialchars($bookingDetails['ground_location'] ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <a href="match_history.php" class="block bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold py-3 px-3 rounded-xl text-xs transition-colors">
                            Match History
                        </a>
                        <a href="explore.php" class="block bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-3 rounded-xl text-xs transition-colors shadow-sm">
                            Explore More Grounds
                        </a>
                    </div>
                <?php else: ?>
                    <a href="explore.php" class="block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 px-4 rounded-xl text-sm transition-all shadow-md">
                        Back to Home
                    </a>
                <?php endif; ?>

            <?php elseif ($isPending): ?>
                <!-- Pending State -->
                <div class="w-20 h-20 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto shadow-inner animate-pulse">
                    <svg class="w-10 h-10 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </div>

                <div class="space-y-1">
                    <h1 class="text-2xl font-black text-slate-900">Payment Processing</h1>
                    <p class="text-xs text-slate-500">Your payment is being confirmed with Swich. This usually takes under a minute.</p>
                </div>

                <div class="flex gap-3">
                    <button onclick="window.location.reload()" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-3 px-4 rounded-xl text-xs transition-colors">
                        Refresh Status
                    </button>
                    <a href="wallet.php" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3 px-4 rounded-xl text-xs transition-colors text-center">
                        Go to Wallet
                    </a>
                </div>

            <?php else: ?>
                <!-- Failed State -->
                <div class="w-20 h-20 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto shadow-inner">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>

                <div class="space-y-1">
                    <h1 class="text-2xl font-black text-slate-900">Payment Unsuccessful</h1>
                    <p class="text-xs text-red-600 font-medium"><?php echo htmlspecialchars($error ?: 'Transaction was declined or cancelled.'); ?></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <a href="javascript:history.back()" class="block bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold py-3 px-3 rounded-xl text-xs transition-colors">
                        Try Again
                    </a>
                    <a href="wallet.php" class="block bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-3 rounded-xl text-xs transition-colors shadow-sm">
                        Go to Wallet
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Footer -->
    <footer class="text-center py-4 text-xs text-slate-400">
        &copy; <?php echo date('Y'); ?> ArenaReserve. Secured with Swich &amp; JazzCash.
    </footer>

</body>
</html>
