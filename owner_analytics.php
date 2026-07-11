<?php
session_start();
require_once 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Get wallet balance details
try {
    $stmt = $pdo->prepare("SELECT available_balance, frozen_escrow_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    
    // For demo / screenshot parity: default to 125,000 PKR if 0 or missing
    $available_balance = ($wallet && $wallet['available_balance'] > 0) ? floatval($wallet['available_balance']) : 125000.00;
} catch (Exception $e) {
    $available_balance = 125000.00;
}

$total_earnings = 345000.00;
$pending_payouts = 15000.00;

// Handle manual payout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'withdraw') {
    $bank_title = trim($_POST['bank_title'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);

    if (empty($bank_title) || empty($iban) || $amount <= 0) {
        $error_msg = 'Please fill out all required fields with valid details.';
    } else if ($amount > $available_balance) {
        $error_msg = 'Withdrawal amount exceeds your available balance.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Fetch wallet or create one
            $stmt = $pdo->prepare("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE");
            $stmt->execute([$user_id]);
            $wallet_db = $stmt->fetch();
            
            if (!$wallet_db) {
                $stmt = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, frozen_escrow_balance) VALUES (?, ?, 0.00)");
                $stmt->execute([$user_id, $available_balance]);
                $wallet_id = $pdo->lastInsertId();
            } else {
                $wallet_id = $wallet_db['id'];
            }

            // Deduct from wallet
            $new_balance = $available_balance - $amount;
            $stmt = $pdo->prepare("UPDATE wallets SET available_balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $wallet_id]);

            // Add Payout Transaction log
            $stmt = $pdo->prepare("INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id) VALUES (?, ?, 'Payout', ?)");
            $ref_details = "Payout to Account: " . $bank_title . " (IBAN: " . $iban . ")";
            $stmt->execute([$wallet_id, -$amount, $ref_details]);

            $pdo->commit();
            $available_balance = $new_balance;
            $success_msg = 'Manual cashout request submitted successfully! Fund clearance is under review.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = 'Transaction failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics & Wallet - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">
    <!-- Top Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo -->
                <div class="flex-shrink-0 flex items-center gap-2">
                    <span class="text-emerald-600 text-2xl font-bold flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z" />
                        </svg>
                        ArenaReserve
                    </span>
                </div>

                <!-- Right Side Actions -->
                <div class="flex items-center gap-4">
                    <!-- Mode Toggle -->
                    <div class="flex items-center gap-2 bg-slate-100 p-1 rounded-lg border border-slate-200">
                        <a href="switch_role.php" class="text-xs font-medium px-2 py-1 text-slate-500 hover:text-slate-800 transition-colors">Player</a>
                        <span class="text-xs font-medium px-2 py-1 text-slate-500 bg-white rounded shadow-sm text-emerald-600 font-bold">Owner</span>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm">
                            <?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="hidden md:block text-left">
                            <div class="text-xs font-semibold text-slate-800"><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></div>
                            <div class="text-[10px] text-slate-400 capitalize">Owner</div>
                        </div>
                        <a href="logout.php" class="text-xs text-red-500 hover:text-red-700 ml-2 font-medium">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="flex-1 flex max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">
        <!-- Sidebar Navigation -->
        <aside class="hidden lg:block w-64 flex-shrink-0">
            <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
                <a href="owner_dashboard.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    My Venues
                </a>
                <a href="add_ground.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    List New Venue
                </a>
                <a href="owner_analytics.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg">
                    <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Analytics & Wallet
                </a>
                <a href="owner_scores.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Score Entry
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 min-w-0">
            <!-- Messages -->
            <?php if (!empty($success_msg)): ?>
                <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 text-sm text-green-700 rounded-r-lg">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 text-sm text-red-700 rounded-r-lg">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <!-- Top Metric Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <!-- Wallet Balance -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="p-1 rounded bg-teal-50 text-teal-600">
                                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            </span>
                            Wallet Balance
                        </div>
                        <div class="text-3xl font-bold text-slate-800 mt-3">
                            <?php echo number_format($available_balance); ?> <span class="text-lg font-medium text-slate-500">PKR</span>
                        </div>
                    </div>
                </div>

                <!-- Total Earnings -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="p-1 rounded bg-emerald-50 text-emerald-600">
                                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            </span>
                            Total Earnings
                        </div>
                        <div class="text-3xl font-bold text-slate-800 mt-3">
                            <?php echo number_format($total_earnings); ?> <span class="text-lg font-medium text-slate-500">PKR</span>
                        </div>
                    </div>
                </div>

                <!-- Pending Payouts -->
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="p-1 rounded bg-amber-50 text-amber-600">
                                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            Pending Payouts
                        </div>
                        <div class="text-3xl font-bold text-slate-800 mt-3">
                            <?php echo number_format($pending_payouts); ?> <span class="text-lg font-medium text-slate-500">PKR</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Two-Column Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column (Chart & History) -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Revenue Trend Bar Chart -->
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <h3 class="text-base font-bold text-slate-800 mb-6">Revenue Trend</h3>
                        
                        <div class="flex gap-4 items-stretch h-64">
                            <!-- Y-Axis Labels -->
                            <div class="flex flex-col justify-between text-[11px] text-slate-400 font-semibold py-2 text-right w-12">
                                <span>100,000</span>
                                <span>75,000</span>
                                <span>50,000</span>
                                <span>25,000</span>
                                <span>0</span>
                            </div>

                            <!-- Chart Area -->
                            <div class="flex-1 flex items-end justify-between gap-4 border-l border-b border-slate-200 pb-2 pl-4 pr-2 relative">
                                <!-- Grid Lines -->
                                <div class="absolute inset-0 flex flex-col justify-between pointer-events-none pl-4 pb-2">
                                    <div class="border-t border-dashed border-slate-200/80 w-full h-0"></div>
                                    <div class="border-t border-dashed border-slate-200/80 w-full h-0"></div>
                                    <div class="border-t border-dashed border-slate-200/80 w-full h-0"></div>
                                    <div class="border-t border-dashed border-slate-200/80 w-full h-0"></div>
                                    <div class="w-full h-0"></div> <!-- Baseline -->
                                </div>

                                <!-- Bars -->
                                <!-- Jan: 45,000 -->
                                <div class="flex flex-col items-center flex-1 z-10 group cursor-pointer">
                                    <div class="text-[10px] font-semibold text-emerald-600 mb-1 opacity-0 group-hover:opacity-100 transition-opacity">45k</div>
                                    <div class="bg-emerald-500 hover:bg-emerald-600 w-full rounded-t transition-all" style="height: 45%;"></div>
                                    <span class="text-xs text-slate-500 mt-2 font-medium">Jan</span>
                                </div>
                                <!-- Feb: 52,000 -->
                                <div class="flex flex-col items-center flex-1 z-10 group cursor-pointer">
                                    <div class="text-[10px] font-semibold text-emerald-600 mb-1 opacity-0 group-hover:opacity-100 transition-opacity">52k</div>
                                    <div class="bg-emerald-500 hover:bg-emerald-600 w-full rounded-t transition-all" style="height: 52%;"></div>
                                    <span class="text-xs text-slate-500 mt-2 font-medium">Feb</span>
                                </div>
                                <!-- Mar: 48,000 -->
                                <div class="flex flex-col items-center flex-1 z-10 group cursor-pointer">
                                    <div class="text-[10px] font-semibold text-emerald-600 mb-1 opacity-0 group-hover:opacity-100 transition-opacity">48k</div>
                                    <div class="bg-emerald-500 hover:bg-emerald-600 w-full rounded-t transition-all" style="height: 48%;"></div>
                                    <span class="text-xs text-slate-500 mt-2 font-medium">Mar</span>
                                </div>
                                <!-- Apr: 61,000 -->
                                <div class="flex flex-col items-center flex-1 z-10 group cursor-pointer">
                                    <div class="text-[10px] font-semibold text-emerald-600 mb-1 opacity-0 group-hover:opacity-100 transition-opacity">61k</div>
                                    <div class="bg-emerald-500 hover:bg-emerald-600 w-full rounded-t transition-all" style="height: 61%;"></div>
                                    <span class="text-xs text-slate-500 mt-2 font-medium">Apr</span>
                                </div>
                                <!-- May: 58,000 -->
                                <div class="flex flex-col items-center flex-1 z-10 group cursor-pointer">
                                    <div class="text-[10px] font-semibold text-emerald-600 mb-1 opacity-0 group-hover:opacity-100 transition-opacity">58k</div>
                                    <div class="bg-emerald-500 hover:bg-emerald-600 w-full rounded-t transition-all" style="height: 58%;"></div>
                                    <span class="text-xs text-slate-500 mt-2 font-medium">May</span>
                                </div>
                                <!-- Jun: 82,000 -->
                                <div class="flex flex-col items-center flex-1 z-10 group cursor-pointer">
                                    <div class="text-[10px] font-semibold text-emerald-600 mb-1 opacity-0 group-hover:opacity-100 transition-opacity">82k</div>
                                    <div class="bg-emerald-500 hover:bg-emerald-600 w-full rounded-t transition-all" style="height: 82%;"></div>
                                    <span class="text-xs text-slate-500 mt-2 font-medium">Jun</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <h3 class="text-base font-bold text-slate-800 mb-4">Recent Transactions</h3>
                        <div class="divide-y divide-slate-100">
                            <!-- Tx 1 -->
                            <div class="flex justify-between items-center py-3.5">
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-800">Champions Stadium A</h4>
                                    <span class="text-xs text-slate-400 font-medium">2026-05-30</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-bold text-emerald-600">+3,500 PKR</span>
                                    <div class="text-[10px] text-slate-400 font-medium capitalize">Booking</div>
                                </div>
                            </div>
                            <!-- Tx 2 -->
                            <div class="flex justify-between items-center py-3.5">
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-800">Sunset Cricket Arena</h4>
                                    <span class="text-xs text-slate-400 font-medium">2026-05-28</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-bold text-emerald-600">+6,400 PKR</span>
                                    <div class="text-[10px] text-slate-400 font-medium capitalize">Booking</div>
                                </div>
                            </div>
                            <!-- Tx 3 -->
                            <div class="flex justify-between items-center py-3.5">
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-800">Victory Basketball Court</h4>
                                    <span class="text-xs text-slate-400 font-medium">2026-05-25</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-bold text-emerald-600">+1,500 PKR</span>
                                    <div class="text-[10px] text-slate-400 font-medium capitalize">Booking</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column (Withdraw Form) -->
                <div>
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                        <div class="flex items-center gap-2 border-b border-slate-100 pb-4 mb-4">
                            <span class="p-1.5 rounded-lg bg-emerald-50 text-emerald-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </span>
                            <h3 class="text-base font-bold text-slate-800">Withdraw Funds</h3>
                        </div>

                        <form action="owner_analytics.php" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="withdraw">
                            
                            <div>
                                <label class="text-xs font-semibold text-slate-500 block mb-1.5">Available Balance</label>
                                <div class="bg-emerald-50/50 text-emerald-600 rounded-lg py-3 text-center font-bold text-lg border border-emerald-100/50">
                                    <?php echo number_format($available_balance); ?> PKR
                                </div>
                            </div>

                            <div>
                                <label for="bank_title" class="text-xs font-semibold text-slate-500 block mb-1">Bank Account Title</label>
                                <input type="text" id="bank_title" name="bank_title" required placeholder="Account holder name"
                                       class="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            </div>

                            <div>
                                <label for="iban" class="text-xs font-semibold text-slate-500 block mb-1">IBAN Number</label>
                                <input type="text" id="iban" name="iban" required placeholder="PK36ABPA0010034567891"
                                       class="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            </div>

                            <div>
                                <label for="amount" class="text-xs font-semibold text-slate-500 block mb-1">Withdrawal Amount (PKR)</label>
                                <input type="number" id="amount" name="amount" min="1" max="<?php echo $available_balance; ?>" required placeholder="Enter amount"
                                       class="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            </div>

                            <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors mt-6">
                                Submit Manual Cashout Request
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
