<?php
/**
 * assanpay_return.php
 * Landing page when user returns from AssanPay Hosted Checkout.
 * Displays real-time transaction status and seamless navigation for wallet top-ups and slot bookings.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logo_helper.php';
require_once __DIR__ . '/AssanPayService.php';
require_once __DIR__ . '/payment_fulfill_helper.php';

$orderId = trim($_GET['orderId'] ?? '');
$paramStatus = trim($_GET['status'] ?? '');

$transaction = null;
$metaData = [];
$bookingDetails = null;
$error = '';
$isSuccess = false;
$isPending = false;
$isFailed = false;

if (!empty($orderId)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $transaction = $stmt->fetch();

        if ($transaction) {
            $metaData = !empty($transaction['meta_data']) ? json_decode($transaction['meta_data'], true) : [];

            // If still pending in DB, query AssanPay status inquiry API in real-time
            if ($transaction['status'] === 'pending') {
                $service = new AssanPayService();
                $inquiry = $service->checkPaymentStatus($orderId);
                
                if ($inquiry['success'] && isset($inquiry['data'])) {
                    $inqData = $inquiry['data'];
                    $inqStatus = strtoupper($inqData['status'] ?? '');
                    if ($inqStatus === 'SUCCESS' || ($inqData['statusCode'] ?? '') === '200') {
                        $ref = $inqData['reference'] ?? $inqData['transactionId'] ?? null;
                        $fulRes = fulfillPaymentTransaction($pdo, $orderId, $ref, json_encode($inqData));
                        
                        // Reload transaction
                        $stmt->execute([$orderId]);
                        $transaction = $stmt->fetch();
                        $metaData = !empty($transaction['meta_data']) ? json_decode($transaction['meta_data'], true) : [];
                    } elseif ($inqStatus === 'FAILED' || ($inqData['statusCode'] ?? '') === '400') {
                        $errorReason = $inqData['message'] ?? $inqData['reason'] ?? 'Transaction was cancelled or declined by PSP.';
                        $pdo->prepare("UPDATE payment_transactions SET status = 'failed', raw_callback = ? WHERE id = ?")
                            ->execute([json_encode($inqData), $transaction['id']]);
                        $transaction['status'] = 'failed';
                        $error = $errorReason;
                    }
                }
            }

            if ($transaction['status'] === 'success') {
                $isSuccess = true;

                // If slot booking, fetch detailed info for rich UI
                if (($transaction['purpose'] ?? '') === 'slot_booking') {
                    $metaData = json_decode($transaction['meta_data'] ?? '{}', true);
                    $gId   = intval($metaData['ground_id'] ?? 0);
                    $sDate = trim($metaData['slot_date'] ?? '');
                    $slotHours = !empty($metaData['slot_hours']) && is_array($metaData['slot_hours'])
                        ? array_map('intval', $metaData['slot_hours'])
                        : [intval($metaData['slot_hour'] ?? -1)];

                    if ($gId && $sDate && !empty($slotHours)) {
                        $inClause = implode(',', array_fill(0, count($slotHours), '?'));
                        $bStmt = $pdo->prepare("
                            SELECT b.*, g.title AS ground_title, g.sport_type 
                            FROM bookings b 
                            JOIN grounds g ON g.id = b.ground_id 
                            WHERE b.ground_id = ? AND b.slot_date = ? AND b.slot_hour IN ($inClause) AND b.booked_by = ?
                            ORDER BY b.slot_hour ASC
                        ");
                        $bStmt->execute(array_merge([$gId, $sDate], $slotHours, [$transaction['user_id']]));
                        $allBookings = $bStmt->fetchAll();
                        $bookingDetails = $allBookings[0] ?? null;
                    }
                }

            } elseif ($transaction['status'] === 'pending') {
                $isPending = true;
            } else {
                $isFailed = true;
                if (empty($error) && !empty($transaction['raw_callback'])) {
                    $cb = json_decode($transaction['raw_callback'], true);
                    $error = $cb['message'] ?? $cb['reason'] ?? $cb['error'] ?? '';
                }
            }
        } else {
            $error = 'Transaction record not found.';
            $isFailed = true;
        }
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
        $isFailed = true;
    }
} else {
    $error = 'No Order ID provided in return URL.';
    $isFailed = true;
}

// Format time label helper
function formatSlotHourDisplay(int $h): string {
    $suffix    = $h < 12 ? 'AM' : 'PM';
    $displayH  = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
    $nextH     = $h + 1;
    $nextDisp  = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
    $nextSuffix = $nextH < 12 ? 'AM' : 'PM';
    return sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuffix);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl overflow-hidden border border-slate-200/80 transition-all">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 text-white px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <?php echo get_logo_markup('h-7 w-auto'); ?>
                <span class="font-bold text-sm tracking-wide">Arena<span class="text-white">Reserve</span></span>
            </div>
            <span class="text-[10px] uppercase font-bold tracking-wider text-emerald-900 bg-white/90 px-2.5 py-0.5 rounded-full shadow-2xs">Payment Status</span>
        </div>

        <div class="p-6 text-center">
            
            <?php if ($isSuccess): ?>
                <!-- Success State -->
                <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>

                <?php if (($transaction['purpose'] ?? '') === 'slot_booking'): 
                    $bookingCount = !empty($allBookings) ? count($allBookings) : 1;
                ?>
                    <h1 class="text-2xl font-extrabold text-slate-900 mb-1">
                        <?php echo $bookingCount > 1 ? "{$bookingCount} Bookings Confirmed! ⚽" : "Booking Confirmed! ⚽"; ?>
                    </h1>
                    <p class="text-xs text-slate-500 mb-6">Your slot advance has been paid securely via AssanPay.</p>

                    <!-- Slot Booking Details Card -->
                    <div class="bg-emerald-50/60 border border-emerald-200 rounded-xl p-4 text-left text-xs space-y-2.5 mb-6">
                        <?php if ($bookingDetails): ?>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Venue:</span>
                            <span class="font-bold text-slate-900"><?php echo htmlspecialchars($bookingDetails['ground_title']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Sport:</span>
                            <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($bookingDetails['sport_type']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Date:</span>
                            <span class="font-semibold text-slate-800"><?php echo date('D, d M Y', strtotime($bookingDetails['slot_date'])); ?></span>
                        </div>
                        <?php if (!empty($allBookings) && count($allBookings) > 1): ?>
                        <div class="border-t border-emerald-200/80 pt-2">
                            <div class="text-slate-500 font-semibold mb-1">Booked Time Slots (<?php echo count($allBookings); ?>):</div>
                            <div class="space-y-1">
                                <?php foreach ($allBookings as $bk): ?>
                                <div class="flex justify-between font-mono text-[11px] text-slate-800 bg-white/70 px-2 py-1 rounded border border-emerald-200/50">
                                    <span><?php echo formatSlotHourDisplay(intval($bk['slot_hour'])); ?></span>
                                    <span class="font-bold text-emerald-700"><?php echo number_format($bk['amount_paid'], 0); ?> PKR (Adv)</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Time:</span>
                            <span class="font-semibold text-slate-800"><?php echo formatSlotHourDisplay(intval($bookingDetails['slot_hour'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php elseif (!empty($metaData['ground_id'])): ?>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Date:</span>
                            <span class="font-semibold text-slate-800"><?php echo htmlspecialchars($metaData['slot_date'] ?? ''); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="border-t border-emerald-200/80 pt-2 flex justify-between">
                            <span class="text-slate-600 font-semibold">Total Advance Paid:</span>
                            <span class="font-bold text-emerald-700 text-sm"><?php echo number_format($transaction['amount'] ?? 0, 2); ?> PKR</span>
                        </div>

                        <?php 
                        $totalFullPrice = floatval($metaData['full_price'] ?? 0);
                        $totalPaid = floatval($transaction['amount'] ?? 0);
                        $totalRemaining = max(0, $totalFullPrice - $totalPaid);
                        if ($totalRemaining > 0): 
                        ?>
                        <div class="flex justify-between text-amber-700 font-medium bg-amber-50 rounded p-1.5 text-[11px]">
                            <span>Remaining Due at Venue:</span>
                            <span class="font-bold"><?php echo number_format($totalRemaining, 0); ?> PKR</span>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between pt-1">
                            <span class="text-slate-400">Order ID:</span>
                            <span class="font-mono text-slate-600"><?php echo htmlspecialchars($orderId); ?></span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <a href="match_history.php" class="w-full block py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                            🏆 View in Match History
                        </a>
                        <a href="book_slot.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                            📅 Book Another Slot
                        </a>
                    </div>

                <?php elseif (($transaction['purpose'] ?? '') === 'accept_challenge'): ?>
                    <h1 class="text-2xl font-extrabold text-slate-900 mb-1">Challenge Accepted! ⚡</h1>
                    <p class="text-xs text-slate-500 mb-6">Your 25% advance share was paid securely via AssanPay. Match is confirmed!</p>

                    <div class="bg-violet-50 border border-violet-200 rounded-xl p-4 text-left text-xs space-y-2.5 mb-6">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Order ID:</span>
                            <span class="font-mono font-semibold text-slate-800"><?php echo htmlspecialchars($orderId); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Share Paid:</span>
                            <span class="font-bold text-violet-700 text-sm"><?php echo number_format($transaction['amount'] ?? 0, 2); ?> PKR (25%)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Status:</span>
                            <span class="font-semibold text-emerald-600">Match Set & Confirmed</span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <a href="match_history.php" class="w-full block py-2.5 px-4 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                            🏆 View in Match History
                        </a>
                        <a href="explore.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                            Explore Grounds
                        </a>
                    </div>

                <?php else: ?>
                    <h1 class="text-2xl font-extrabold text-slate-900 mb-1">Wallet Top-up Successful! 💳</h1>
                    <p class="text-xs text-slate-500 mb-6">Your wallet balance has been updated instantly via AssanPay.</p>

                    <!-- Wallet Topup Details Card -->
                    <div class="bg-slate-50 border border-slate-200/80 rounded-xl p-4 text-left text-xs space-y-2.5 mb-6">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Order ID:</span>
                            <span class="font-mono font-semibold text-slate-800"><?php echo htmlspecialchars($orderId); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Amount Credited:</span>
                            <span class="font-bold text-emerald-600 text-sm"><?php echo number_format($transaction['amount'] ?? 0, 2); ?> PKR</span>
                        </div>
                        <?php if (!empty($transaction['reference'])): ?>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Gateway Ref:</span>
                            <span class="font-mono text-slate-700"><?php echo htmlspecialchars($transaction['reference']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between">
                            <span class="text-slate-500">Date & Time:</span>
                            <span class="text-slate-700"><?php echo date('M d, Y h:i A'); ?></span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <a href="wallet.php" class="w-full block py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                            View Updated Wallet Balance
                        </a>
                        <a href="book_slot.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                            📅 Proceed to Book a Slot
                        </a>
                    </div>
                <?php endif; ?>

            <?php elseif ($isPending): ?>
                <!-- Pending State -->
                <div class="w-16 h-16 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-4 animate-pulse">
                    <svg class="w-9 h-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-slate-900 mb-1">Payment Verification Pending</h1>
                <p class="text-xs text-slate-500 mb-6">AssanPay is confirming the transaction. Your booking/wallet will be updated automatically as soon as confirmation arrives.</p>

                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-left text-xs space-y-2 mb-6">
                    <div class="flex justify-between">
                        <span class="text-amber-800">Order ID:</span>
                        <span class="font-mono font-semibold text-amber-900"><?php echo htmlspecialchars($orderId); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-amber-800">Amount:</span>
                        <span class="font-bold text-amber-900"><?php echo number_format($transaction['amount'] ?? 0, 2); ?> PKR</span>
                    </div>
                </div>

                <div class="space-y-2">
                    <a href="assanpay_return.php?orderId=<?php echo urlencode($orderId); ?>" class="w-full block py-2.5 px-4 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-lg text-sm transition-colors">
                        🔄 Refresh Status
                    </a>
                    <a href="book_slot.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                        Go to Book Slots
                    </a>
                </div>

            <?php else: ?>
                <!-- Failed / Cancelled State -->
                <div class="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-slate-900 mb-1">Payment Failed or Cancelled</h1>
                <p class="text-xs text-slate-500 mb-6"><?php echo !empty($error) ? htmlspecialchars($error) : 'The payment could not be completed. No funds were charged.'; ?></p>

                <?php if ($transaction): ?>
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-left text-xs space-y-2 mb-6">
                    <div class="flex justify-between">
                        <span class="text-red-800">Order ID:</span>
                        <span class="font-mono font-semibold text-red-900"><?php echo htmlspecialchars($orderId); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-red-800">Amount:</span>
                        <span class="font-bold text-red-900"><?php echo number_format($transaction['amount'] ?? 0, 2); ?> PKR</span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="space-y-2">
                    <?php if (($transaction['purpose'] ?? '') === 'slot_booking'): ?>
                        <a href="book_slot.php<?php echo !empty($metaData['ground_id']) ? '?ground=' . intval($metaData['ground_id']) . '&date=' . urlencode($metaData['slot_date'] ?? '') : ''; ?>" class="w-full block py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                            Try Again on Book Slot
                        </a>
                    <?php else: ?>
                        <a href="wallet.php" class="w-full block py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                            Try Again on Wallet
                        </a>
                    <?php endif; ?>
                    <a href="explore.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                        Back to Home
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Footer -->
    <footer class="text-center py-4 text-xs text-slate-400">
        &copy; <?php echo date('Y'); ?> ArenaReserve &bull; Secured with AssanPay
    </footer>
</body>
</html>
