<?php
/**
 * assanpay_return.php
 * Landing page when user returns from AssanPay Hosted Checkout.
 * Displays real-time transaction status and seamless navigation.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logo_helper.php';
require_once __DIR__ . '/AssanPayService.php';

$orderId = trim($_GET['orderId'] ?? '');
$paramStatus = trim($_GET['status'] ?? '');

$transaction = null;
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
            // If still pending in DB, query AssanPay status inquiry API in real-time
            if ($transaction['status'] === 'pending') {
                $service = new AssanPayService();
                $inquiry = $service->checkPaymentStatus($orderId);
                
                if ($inquiry['success'] && isset($inquiry['data'])) {
                    $inqData = $inquiry['data'];
                    $inqStatus = strtoupper($inqData['status'] ?? '');
                    if ($inqStatus === 'SUCCESS' || ($inqData['statusCode'] ?? '') === '200') {
                        // Mark success
                        $pdo->prepare("
                            UPDATE payment_transactions 
                            SET status = 'success', reference = ? 
                            WHERE id = ?
                        ")->execute([$inqData['reference'] ?? $inqData['transactionId'] ?? null, $transaction['id']]);
                        
                        // If wallet topup, credit wallet if not already done
                        if ($transaction['purpose'] === 'wallet_topup') {
                            $wStmt = $pdo->prepare("SELECT id FROM wallets WHERE user_id = ?");
                            $wStmt->execute([$transaction['user_id']]);
                            $w = $wStmt->fetch();
                            if ($w) {
                                $pdo->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE id = ?")
                                    ->execute([$transaction['amount'], $w['id']]);
                                $pdo->prepare("
                                    INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id) 
                                    VALUES (?, ?, 'Deposit', ?)
                                ")->execute([$w['id'], $transaction['amount'], 'AP-' . $orderId]);
                            }
                        }
                        $transaction['status'] = 'success';
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
<body class="bg-slate-50 min-h-screen flex flex-col justify-between">
    <!-- Top Header -->
    <header class="bg-white border-b border-slate-200 shadow-sm py-4">
        <div class="max-w-4xl mx-auto px-4 flex items-center justify-between">
            <a href="wallet.php" class="text-emerald-600 text-xl font-bold flex items-center gap-2">
                <?php echo get_logo_markup('h-7 w-7 flex-shrink-0'); ?>
                <span>ArenaReserve</span>
            </a>
            <a href="wallet.php" class="text-xs font-semibold text-slate-600 hover:text-emerald-600 transition-colors">
                ← Return to Wallet
            </a>
        </div>
    </header>

    <!-- Main Content Box -->
    <main class="flex-1 flex items-center justify-center p-4 my-8">
        <div class="max-w-md w-full bg-white rounded-2xl border border-slate-200 shadow-xl overflow-hidden text-center p-6 sm:p-8">
            
            <?php if ($isSuccess): ?>
                <!-- Success State -->
                <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h1 class="text-2xl font-extrabold text-slate-900 mb-1">Payment Successful!</h1>
                <p class="text-xs text-slate-500 mb-6">Your transaction has been processed securely via AssanPay.</p>

                <!-- Transaction Details Card -->
                <div class="bg-slate-50 border border-slate-200/80 rounded-xl p-4 text-left text-xs space-y-2.5 mb-6">
                    <div class="flex justify-between">
                        <span class="text-slate-500">Order ID:</span>
                        <span class="font-mono font-semibold text-slate-800"><?php echo htmlspecialchars($orderId); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Amount Paid:</span>
                        <span class="font-bold text-emerald-600 text-sm"><?php echo number_format($transaction['amount'] ?? 0, 2); ?> PKR</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Payment Purpose:</span>
                        <span class="font-semibold text-slate-700 capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $transaction['purpose'] ?? '')); ?></span>
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
                    <?php if (($transaction['purpose'] ?? '') === 'wallet_topup'): ?>
                        <a href="wallet.php" class="w-full block py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                            View Updated Wallet Balance
                        </a>
                        <a href="book_slot.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                            Proceed to Book a Slot
                        </a>
                    <?php else: ?>
                        <a href="match_history.php" class="w-full block py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                            View in Match History
                        </a>
                        <a href="explore.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                            Explore More Grounds
                        </a>
                    <?php endif; ?>
                </div>

            <?php elseif ($isPending): ?>
                <!-- Pending State -->
                <div class="w-16 h-16 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-4 animate-pulse">
                    <svg class="w-9 h-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-slate-900 mb-1">Payment Verification Pending</h1>
                <p class="text-xs text-slate-500 mb-6">AssanPay is confirming the transaction. Your balance will be updated automatically as soon as confirmation arrives.</p>

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
                    <a href="wallet.php" class="w-full block py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg text-xs transition-colors">
                        Go to Wallet
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
                    <a href="wallet.php" class="w-full block py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg text-sm transition-colors shadow-sm">
                        Try Again on Wallet
                    </a>
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
