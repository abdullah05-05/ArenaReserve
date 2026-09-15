<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle manual top-up request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount'] ?? 0);
    $reference_details = trim($_POST['reference_details'] ?? '');
    
    if ($amount <= 0) {
        $error = 'Value must be greater than 0.';
    } else if (empty($reference_details)) {
        $error = 'Reference details/Transaction ID is required.';
    } else if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please attach your transaction payment receipt to continue.';
    } else {
        // Handle file upload
        $file_name = $_FILES['receipt']['name'];
        $file_tmp = $_FILES['receipt']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($file_ext, $allowed_exts)) {
            $error = 'Only JPG, PNG, and PDF receipts are allowed.';
        } else {
            // Ensure uploads directory exists
            $upload_dir = 'uploads/receipts/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $unique_name = uniqid('receipt_', true) . '.' . $file_ext;
            $dest_path = $upload_dir . $unique_name;
            
            if (move_uploaded_file($file_tmp, $dest_path)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO wallet_deposit_requests (player_id, amount, reference_details, receipt_path, status) VALUES (?, ?, ?, ?, 'Pending')");
                    $stmt->execute([$user_id, $amount, $reference_details, $dest_path]);
                    $success = 'Deposit request submitted successfully! Pending Admin verification.';
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            } else {
                $error = 'Failed to save the uploaded file. Please try again.';
            }
        }
    }
}

// Fetch current wallet details
try {
    $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    
    if (!$wallet) {
        // Fallback: create if missing
        $stmt = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, frozen_escrow_balance) VALUES (?, 0.00, 0.00)");
        $stmt->execute([$user_id]);
        $available_balance = 0.00;
        $frozen_balance = 0.00;
    } else {
        $available_balance = $wallet['available_balance'];
        $frozen_balance = $wallet['frozen_escrow_balance'];
    }
    
    // Fetch pending and past deposit requests
    $stmt = $pdo->prepare("SELECT *, COALESCE(rejection_reason,'') AS rejection_reason FROM wallet_deposit_requests WHERE player_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll();

    // Fetch transactions
    $stmt = $pdo->prepare("SELECT wt.* FROM wallet_transactions wt JOIN wallets w ON wt.wallet_id = w.id WHERE w.user_id = ? ORDER BY wt.recorded_at DESC");
    $stmt->execute([$user_id]);
    $transactions = $stmt->fetchAll();

    // Fetch user details for instant checkout prefill
    $uStmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $uStmt->execute([$user_id]);
    $currentUser = $uStmt->fetch() ?: ['name' => $_SESSION['name'] ?? '', 'email' => '', 'phone' => ''];

    // Fetch online payments (JazzCash)
    $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
    $stmt->execute([$user_id]);
    $online_payments = $stmt->fetchAll();

} catch (Exception $e) {
    $available_balance = 0.00;
    $frozen_balance = 0.00;
    $requests = [];
    $transactions = [];
    $online_payments = [];
    $currentUser = ['name' => $_SESSION['name'] ?? '', 'email' => '', 'phone' => ''];
}

if (isset($_SESSION['payment_error'])) {
    $error = $_SESSION['payment_error'];
    unset($_SESSION['payment_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallet - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
    <?php
    $page_description = 'Manage your ArenaReserve wallet. Top up your balance, track transactions, and pay for ground bookings with ease.';
    include 'logo_head.php';
    ?>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">
    <!-- Top Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo & Mobile Toggle -->
                <div class="flex-shrink-0 flex items-center gap-2">
                    <button type="button" onclick="toggleMobileMenu()" class="lg:hidden text-slate-500 hover:text-slate-700 focus:outline-none p-1 rounded-md" title="Toggle Navigation">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <span class="text-emerald-600 text-[12px] sm:text-xl md:text-2xl font-bold flex items-center flex-shrink-0 gap-1 sm:gap-2">
                        <?php echo get_logo_markup('h-[18px] w-[18px] sm:h-7 sm:w-7 flex-shrink-0'); ?>
                        <span class="hidden min-[360px]:inline">ArenaReserve</span>
                    </span>
                </div>

                <!-- Right Side Actions -->
                <div class="flex-shrink-0 flex items-center gap-1 sm:gap-2">
                    <!-- Wallet Display -->
                    <div class="hidden sm:flex items-center bg-slate-100 text-slate-800 px-3 py-1.5 rounded-full text-xs font-semibold border border-slate-200">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span>
                        Wallet: <?php echo number_format($available_balance, 2); ?> PKR
                    </div>

                    <!-- Mode Toggle -->
                    <div class="flex-shrink-0 flex items-center gap-1 bg-slate-100 p-1 rounded-full border border-slate-200/80 shadow-inner">
                        <a href="<?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'switch_role.php' : '#'; ?>" 
                           class="text-[11px] sm:text-xs font-semibold px-2 py-1 sm:px-2.5 sm:py-1.5 rounded-full transition-all duration-300 flex items-center gap-1 <?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'; ?>" title="Switch to Player Mode">
                           <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                           <span class="hidden sm:inline">Player</span>
                        </a>
                        <a href="<?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'switch_role.php' : '#'; ?>" 
                           class="text-[11px] sm:text-xs font-semibold px-2 py-1 sm:px-2.5 sm:py-1.5 rounded-full transition-all duration-300 flex items-center gap-1 <?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'; ?>" title="Switch to Owner Mode">
                           <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                           <span class="hidden sm:inline">Owner</span>
                    </div>

                    <!-- Notification Bell -->
                    <?php include __DIR__ . '/assets/notification_bell.php'; ?>

                    <div class="relative">
                        <button id="profileDropdownBtn" onclick="toggleProfileDropdown()" class="flex items-center gap-2 hover:opacity-90 focus:outline-none transition-opacity" title="User Menu">
                            <div class="w-8 h-8 rounded-full overflow-hidden bg-emerald-600 text-white flex items-center justify-center font-bold text-sm flex-shrink-0 shadow-sm border border-emerald-500">
                                <?php if (!empty($_SESSION['profile_picture']) && file_exists(__DIR__ . '/' . $_SESSION['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="hidden md:block text-left">
                                <div class="text-xs font-semibold text-slate-800 flex items-center gap-1">
                                    <?php echo htmlspecialchars($_SESSION['name']); ?>
                                    <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                                <div class="text-[10px] text-slate-400 capitalize"><?php echo htmlspecialchars($_SESSION['current_active_mode']); ?></div>
                            </div>
                        </button>
                        <!-- Dropdown Menu -->
                        <div id="profileDropdownMenu" class="hidden absolute right-0 top-11 w-48 bg-white rounded-xl shadow-xl border border-slate-200 py-1.5 z-50 transform opacity-0 scale-95 transition-all duration-150">
                            <a href="player_profile.php" class="flex items-center px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-emerald-600 transition-colors">
                                <svg class="mr-2.5 h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                Profile Settings
                            </a>
                            <div class="border-t border-slate-100 my-1"></div>
                            <a href="logout.php" class="flex items-center px-4 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 transition-colors">
                                <svg class="mr-2.5 h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Mobile Navigation Menu -->
        <div id="mobileNavigationMenu" class="hidden lg:hidden border-t border-slate-100 bg-white py-3 px-4 shadow-inner space-y-1">
            <?php if ($_SESSION['current_active_mode'] === 'Owner'): ?>
                <a href="owner_dashboard.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">My Venues</a>
                <a href="add_ground.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">List New Venue</a>
                <a href="owner_analytics.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Analytics & Wallet</a>
                <a href="owner_scores.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Score Entry</a>
            <?php else: ?>
                <a href="explore.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Explore Grounds</a>
                <a href="book_slot.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Book Slot</a>
                <a href="match_history.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Match History</a>
                <a href="challenge_team.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Challenge Team</a>
                <a href="leaderboard.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Leaderboard</a>
                <a href="wallet.php" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">My Wallet</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="flex-1 flex max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">
        <!-- Sidebar Navigation -->
        <aside class="hidden lg:block w-64 flex-shrink-0">
            <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
                <a href="explore.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Explore Grounds
                </a>
                <a href="book_slot.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Book Slot
                </a>
                <a href="match_history.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Match History
                </a>
                <a href="challenge_team.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    Challenge Team
                </a>
                <a href="leaderboard.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Leaderboard
                </a>
                <div class="border-t border-slate-100 mt-1 pt-1">
                <a href="wallet.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                    My Wallet
                </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 min-w-0 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left 2 Cols: Wallet Overview and Top up form -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Balance Card -->
                <div class="bg-gradient-to-r from-emerald-600 to-teal-500 rounded-2xl shadow-md p-6 text-white">
                    <h2 class="text-sm font-medium opacity-80 uppercase tracking-wider">Available Balance</h2>
                    <div class="text-3xl sm:text-4xl font-extrabold mt-1">
                        <?php echo number_format($available_balance, 2); ?> <span class="text-lg font-semibold">PKR</span>
                    </div>

                    <div class="mt-6 flex gap-4 text-xs">
                        <div>
                            <div class="opacity-75 uppercase font-medium">Escrow Locked</div>
                            <div class="text-sm font-bold mt-0.5"><?php echo number_format($frozen_balance, 2); ?> PKR</div>
                        </div>
                        <div class="border-l border-white/20 pl-4">
                            <div class="opacity-75 uppercase font-medium">Currency Type</div>
                            <div class="text-sm font-bold mt-0.5">Pakistani Rupee (PKR)</div>
                        </div>
                    </div>
                </div>

                <!-- Tabs for Top-up Method -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="border-b border-slate-200 bg-slate-50/70 p-1 flex">
                        <button type="button" id="tabBtnInstant" onclick="switchTopupTab('instant')"
                                class="flex-1 py-2.5 px-4 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition-all bg-white text-emerald-600 shadow-sm">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            <span>Instant Online Top-up</span>
                            <span class="bg-red-100 text-red-800 text-[10px] font-extrabold px-2 py-0.5 rounded-full hidden sm:inline">JazzCash</span>
                        </button>
                        <button type="button" id="tabBtnManual" onclick="switchTopupTab('manual')"
                                class="flex-1 py-2.5 px-4 rounded-xl text-xs sm:text-sm font-semibold flex items-center justify-center gap-2 transition-all text-slate-500 hover:text-slate-700">
                            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span>Manual Bank Transfer</span>
                        </button>
                    </div>

                    <div class="p-6">
                        <?php if (!empty($error)): ?>
                            <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-3 text-xs text-red-700 rounded-r">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="mb-4 bg-green-50 border-l-4 border-green-500 p-3 text-xs text-green-700 rounded-r">
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>

                        <!-- 1. INSTANT TOP-UP TAB (JazzCash Online) -->
                        <div id="tabContentInstant" class="space-y-5">
                            <div class="bg-gradient-to-r from-red-50 to-amber-50 border border-red-200/80 rounded-xl p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                <div>
                                    <h4 class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                                        <span>⚡ Instant JazzCash Deposit</span>
                                    </h4>
                                    <p class="text-xs text-slate-600 mt-0.5">Pay via JazzCash Mobile Account or Debit/Credit Card. Your wallet balance updates instantly upon payment.</p>
                                </div>
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="px-2 py-1 bg-white border border-red-200 text-[10px] font-bold text-red-700 rounded-md shadow-2xs">JazzCash Account</span>
                                    <span class="px-2 py-1 bg-white border border-red-200 text-[10px] font-bold text-red-700 rounded-md shadow-2xs">Debit / Credit Card</span>
                                </div>
                            </div>

                            <!-- Sub-method selector -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mb-2">
                                <button type="button" id="jc_sub_mwallet_btn" onclick="setJazzCashSubMethod('mwallet')"
                                        class="py-2.5 px-3 rounded-xl border-2 border-red-500 bg-red-50 text-red-800 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer">
                                    <span>📱</span>
                                    <span>JazzCash Mobile</span>
                                </button>
                                <button type="button" id="jc_sub_card_btn" onclick="setJazzCashSubMethod('card')"
                                        class="py-2.5 px-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer">
                                    <span>💳</span>
                                    <span>Debit / Credit Card</span>
                                </button>
                                <button type="button" id="jc_sub_swich_btn" onclick="setJazzCashSubMethod('swich')"
                                        class="py-2.5 px-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer">
                                    <span>⚡</span>
                                    <span>Swich (EasyPaisa/Bank)</span>
                                </button>
                            </div>

                            <form id="jazzcashTopupForm" action="initiate_checkout.php" method="POST" class="space-y-4" onsubmit="handleJazzCashSubmit(event)">
                                <input type="hidden" name="purpose" value="wallet_topup">
                                <input type="hidden" name="format" value="json">
                                <input type="hidden" name="payment_method" id="jc_payment_method" value="mwallet">

                                <!-- Quick Amount Chips -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Select Quick Amount (PKR)</label>
                                    <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                                        <button type="button" onclick="setQuickAmount(500)" class="quick-chip py-2 px-3 border border-slate-200 hover:border-emerald-500 rounded-lg text-xs font-bold text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 transition-colors text-center">500 PKR</button>
                                        <button type="button" onclick="setQuickAmount(1000)" class="quick-chip py-2 px-3 border border-slate-200 hover:border-emerald-500 rounded-lg text-xs font-bold text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 transition-colors text-center">1,000 PKR</button>
                                        <button type="button" onclick="setQuickAmount(2000)" class="quick-chip py-2 px-3 border border-slate-200 hover:border-emerald-500 rounded-lg text-xs font-bold text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 transition-colors text-center">2,000 PKR</button>
                                        <button type="button" onclick="setQuickAmount(3000)" class="quick-chip py-2 px-3 border border-slate-200 hover:border-emerald-500 rounded-lg text-xs font-bold text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 transition-colors text-center">3,000 PKR</button>
                                        <button type="button" onclick="setQuickAmount(5000)" class="quick-chip py-2 px-3 border border-slate-200 hover:border-emerald-500 rounded-lg text-xs font-bold text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 transition-colors text-center">5,000 PKR</button>
                                        <button type="button" onclick="setQuickAmount(10000)" class="quick-chip py-2 px-3 border border-slate-200 hover:border-emerald-500 rounded-lg text-xs font-bold text-slate-700 hover:text-emerald-700 hover:bg-emerald-50 transition-colors text-center">10,000 PKR</button>
                                    </div>
                                </div>

                                <!-- Amount Input -->
                                <div>
                                    <label for="jc_amount" class="block text-xs font-semibold text-slate-700">Or Enter Custom Amount (PKR)</label>
                                    <div class="mt-1 relative rounded-md shadow-sm">
                                        <input id="jc_amount" name="amount" type="number" step="1" min="1" required placeholder="e.g. 1000"
                                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-lg placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-sm font-semibold text-slate-900">
                                    </div>
                                    <p class="text-[11px] text-slate-500 mt-1" id="jc_method_desc">Instant online deposit &bull; Authorize with your 4-digit JazzCash MPIN.</p>
                                </div>

                                <!-- Payer Details for M-Wallet -->
                                <div id="jc_mwallet_fields" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label for="jc_phone" class="block text-xs font-semibold text-slate-700">JazzCash Mobile Number</label>
                                        <input id="jc_phone" name="mwallet_mobile" type="tel" maxlength="11"
                                               value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>"
                                               placeholder="03001234567"
                                               class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-xs text-slate-800 font-semibold">
                                    </div>
                                    <div>
                                        <label for="jc_cnic" class="block text-xs font-semibold text-slate-700">CNIC (Last 6 Digits)</label>
                                        <input id="jc_cnic" name="mwallet_cnic" type="text" maxlength="6"
                                               placeholder="e.g. 123456"
                                               class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-xs text-slate-800 font-semibold">
                                    </div>
                                </div>

                                <!-- Payer Details for Card -->
                                <div id="jc_card_fields" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label for="jc_card_phone" class="block text-xs font-semibold text-slate-700">Contact Phone Number</label>
                                        <input id="jc_card_phone" name="phone" type="text"
                                               value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>"
                                               placeholder="03001234567"
                                               class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-xs text-slate-800">
                                    </div>
                                    <div>
                                        <label for="jc_email" class="block text-xs font-semibold text-slate-700">Email Address (for receipt)</label>
                                        <input id="jc_email" name="email" type="email"
                                               value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>"
                                               placeholder="player@example.com"
                                               class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-xs text-slate-800">
                                    </div>
                                </div>

                                <!-- Waiting alert for M-Wallet MPIN -->
                                <div id="jc_waiting_box" class="hidden p-3 bg-amber-50 border border-amber-300 rounded-xl text-xs text-amber-900 space-y-1">
                                    <div class="flex items-center gap-2 font-bold">
                                        <svg class="animate-spin h-4 w-4 text-amber-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        <span>Prompt Sent to Mobile!</span>
                                    </div>
                                    <p class="text-[11px] text-amber-800">Please unlock your phone now and enter your 4-digit JazzCash MPIN to approve this deposit.</p>
                                </div>

                                <div id="jc_error_box" class="hidden bg-red-50 border border-red-200 text-red-700 p-3 rounded-lg text-xs font-medium"></div>

                                <!-- Submit Button -->
                                <button type="submit" id="jc_submit_btn"
                                        class="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl shadow-md text-sm font-bold text-white bg-gradient-to-r from-red-600 to-amber-600 hover:from-red-700 hover:to-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-all cursor-pointer">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <span id="jc_btn_text">⚡ Deposit via JazzCash Mobile (MPIN)</span>
                                </button>
                            </form>
                        </div>

                        <!-- 2. MANUAL TOP-UP TAB -->
                        <div id="tabContentManual" class="hidden space-y-4">
                            <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 text-xs text-slate-600">
                                <h4 class="font-bold text-slate-800 mb-2 uppercase tracking-wide">Manual Deposit Bank Details</h4>
                                <p class="mb-1"><span class="font-semibold text-slate-700">Bank Account:</span> Allied Bank (ABL) - 001004958273012</p>
                                <p class="mb-1"><span class="font-semibold text-slate-700">EasyPaisa/JazzCash:</span> 0300-1234567</p>
                                <p class="mt-2 text-slate-500">Transfer funds manually, upload your receipt slip below, and an admin will verify it within 1-2 hours.</p>
                            </div>

                            <form class="space-y-4" action="wallet.php" method="POST" enctype="multipart/form-data">
                                <div>
                                    <label for="amount" class="block text-xs font-semibold text-slate-700">Amount (PKR)</label>
                                    <div class="mt-1 relative rounded-md shadow-sm">
                                        <input id="amount" name="amount" type="number" step="0.01" required placeholder="5000"
                                               value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
                                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-lg placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                                    </div>
                                </div>

                                <div>
                                    <label for="reference_details" class="block text-xs font-semibold text-slate-700">Transaction ID / Reference Details</label>
                                    <div class="mt-1">
                                        <input id="reference_details" name="reference_details" type="text" required placeholder="TID-98274192"
                                               value="<?php echo htmlspecialchars($_POST['reference_details'] ?? ''); ?>"
                                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-lg placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-700">Upload Receipt Slip (JPG, PNG, PDF)</label>
                                    <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-300 border-dashed rounded-lg">
                                        <div class="space-y-1 text-center">
                                            <svg class="mx-auto h-12 w-12 text-slate-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                                <path d="M28 8H12a4 4 0 00-4 4v20a4 4 0 004 4h20a4 4 0 004-4V20m-6-6V8m0 6h6m-6 0a6 6 0 01-6-6V8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            <div class="flex text-sm text-slate-600 justify-center">
                                                <label for="receipt" class="relative cursor-pointer bg-white rounded-md font-semibold text-emerald-600 hover:text-emerald-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-emerald-500">
                                                    <span>Upload a file</span>
                                                    <input id="receipt" name="receipt" type="file" required class="sr-only">
                                                </label>
                                            </div>
                                            <p class="text-xs text-slate-500" id="file-name-display">PNG, JPG, PDF up to 5MB</p>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <button type="submit"
                                            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-colors cursor-pointer">
                                        Submit Manual Deposit Request
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right 1 Col: Transaction Logs & Payment History -->
            <div class="space-y-6">
                <!-- Online Transactions (JazzCash) -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                    <h3 class="text-sm font-bold text-slate-800 mb-3 border-b border-slate-100 pb-2 flex items-center justify-between">
                        <span>Online Payments</span>
                        <span class="text-[10px] font-semibold text-red-600 bg-red-50 px-2 py-0.5 rounded-full">JazzCash</span>
                    </h3>
                    <?php if (empty($online_payments)): ?>
                        <p class="text-xs text-slate-500 py-3 text-center">No online transactions yet.</p>
                    <?php else: ?>
                        <div class="space-y-2.5 max-h-64 overflow-y-auto pr-1">
                            <?php foreach ($online_payments as $op): ?>
                                <div class="text-xs border-b border-slate-50 pb-2 last:border-0 last:pb-0">
                                    <div class="flex justify-between font-semibold text-slate-700">
                                        <span><?php echo number_format($op['amount'], 2); ?> PKR</span>
                                        <?php
                                            $st_class = 'text-amber-600 bg-amber-50';
                                            if ($op['status'] === 'success') $st_class = 'text-emerald-600 bg-emerald-50';
                                            if ($op['status'] === 'failed') $st_class = 'text-red-500 bg-red-50';
                                        ?>
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $st_class; ?>">
                                            <?php echo htmlspecialchars($op['status']); ?>
                                        </span>
                                    </div>
                                    <div class="text-slate-400 mt-1 flex justify-between text-[11px]">
                                        <span class="font-mono"><?php echo htmlspecialchars($op['order_id']); ?></span>
                                        <span><?php echo date('M d, H:i', strtotime($op['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Manual Deposit Requests Log -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                    <h3 class="text-sm font-bold text-slate-800 mb-3 border-b border-slate-100 pb-2">Manual Slips Status</h3>
                    <?php if (empty($requests)): ?>
                        <p class="text-xs text-slate-500 py-3 text-center">No manual deposit logs.</p>
                    <?php else: ?>
                        <div class="space-y-3 max-h-56 overflow-y-auto pr-1">
                            <?php foreach ($requests as $req): ?>
                                <div class="text-xs border-b border-slate-50 pb-2 last:border-0 last:pb-0">
                                    <div class="flex justify-between font-semibold text-slate-700">
                                        <span><?php echo number_format($req['amount'], 2); ?> PKR</span>
                                        <?php 
                                            $status_class = 'text-amber-500 bg-amber-50';
                                            if ($req['status'] === 'Approved') $status_class = 'text-green-600 bg-green-50';
                                            if ($req['status'] === 'Rejected') $status_class = 'text-red-500 bg-red-50';
                                        ?>
                                        <span class="px-1.5 py-0.5 rounded text-[10px] <?php echo $status_class; ?>"><?php echo htmlspecialchars($req['status']); ?></span>
                                    </div>
                                    <div class="text-slate-400 mt-1 flex justify-between">
                                        <span>Ref: <?php echo htmlspecialchars($req['reference_details']); ?></span>
                                        <span><?php echo date('M d, Y', strtotime($req['created_at'])); ?></span>
                                    </div>
                                    <?php if ($req['status'] === 'Rejected' && !empty($req['rejection_reason'])): ?>
                                        <div class="mt-1.5 bg-red-50 border border-red-100 rounded px-2 py-1.5">
                                            <span class="text-[10px] font-bold text-red-600">Reason: </span>
                                            <span class="text-[10px] text-red-600"><?php echo htmlspecialchars($req['rejection_reason']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Ledger Transactions -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                    <h3 class="text-sm font-bold text-slate-800 mb-3 border-b border-slate-100 pb-2">Wallet Transactions</h3>
                    <?php if (empty($transactions)): ?>
                        <p class="text-xs text-slate-500 py-3 text-center">No transactions recorded.</p>
                    <?php else: ?>
                        <div class="space-y-3 max-h-56 overflow-y-auto pr-1">
                            <?php foreach ($transactions as $tx): ?>
                                <div class="text-xs border-b border-slate-50 pb-2 last:border-0 last:pb-0">
                                    <div class="flex justify-between font-semibold text-slate-700">
                                        <span class="<?php echo ($tx['transaction_type'] === 'Deposit' || $tx['transaction_type'] === 'Refund') ? 'text-green-600' : 'text-red-600'; ?>">
                                            <?php echo ($tx['transaction_type'] === 'Deposit' || $tx['transaction_type'] === 'Refund') ? '+' : '-'; ?>
                                            <?php echo number_format($tx['amount'], 2); ?> PKR
                                        </span>
                                        <span class="font-normal text-slate-500"><?php echo htmlspecialchars($tx['transaction_type']); ?></span>
                                    </div>
                                    <div class="text-slate-400 mt-1 text-[10px] text-right">
                                        <?php echo date('M d, Y H:i', strtotime($tx['recorded_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // ---- Top-up Tabs ----
        function switchTopupTab(tab) {
            const instantTab = document.getElementById('tabContentInstant');
            const manualTab = document.getElementById('tabContentManual');
            const btnInstant = document.getElementById('tabBtnInstant');
            const btnManual = document.getElementById('tabBtnManual');

            if (tab === 'instant') {
                instantTab.classList.remove('hidden');
                manualTab.classList.add('hidden');
                btnInstant.className = 'flex-1 py-2.5 px-4 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition-all bg-white text-emerald-600 shadow-sm';
                btnManual.className = 'flex-1 py-2.5 px-4 rounded-xl text-xs sm:text-sm font-semibold flex items-center justify-center gap-2 transition-all text-slate-500 hover:text-slate-700';
            } else {
                instantTab.classList.add('hidden');
                manualTab.classList.remove('hidden');
                btnManual.className = 'flex-1 py-2.5 px-4 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition-all bg-white text-emerald-600 shadow-sm';
                btnInstant.className = 'flex-1 py-2.5 px-4 rounded-xl text-xs sm:text-sm font-semibold flex items-center justify-center gap-2 transition-all text-slate-500 hover:text-slate-700';
            }
        }

        // ---- Quick Amount Setter ----
        function setQuickAmount(val) {
            const input = document.getElementById('jc_amount');
            if (input) {
                input.value = val;
                input.focus();
            }
        }

        let jcCurrentSubMethod = 'mwallet';

        function setJazzCashSubMethod(method) {
            jcCurrentSubMethod = method;
            const mwalletBtn = document.getElementById('jc_sub_mwallet_btn');
            const cardBtn    = document.getElementById('jc_sub_card_btn');
            const swichBtn   = document.getElementById('jc_sub_swich_btn');
            const mwalletFields = document.getElementById('jc_mwallet_fields');
            const cardFields    = document.getElementById('jc_card_fields');
            const hiddenMethod  = document.getElementById('jc_payment_method');
            const btnText       = document.getElementById('jc_btn_text');
            const descEl        = document.getElementById('jc_method_desc');

            if (hiddenMethod) hiddenMethod.value = method;

            // Reset button styles
            mwalletBtn.className = 'py-2.5 px-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer';
            cardBtn.className    = 'py-2.5 px-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer';
            if (swichBtn) swichBtn.className = 'py-2.5 px-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer';

            if (method === 'mwallet') {
                mwalletBtn.className = 'py-2.5 px-3 rounded-xl border-2 border-red-500 bg-red-50 text-red-800 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer';
                mwalletFields.classList.remove('hidden');
                cardFields.classList.add('hidden');
                if (btnText) btnText.textContent = '⚡ Deposit via JazzCash Mobile (MPIN)';
                if (descEl) descEl.textContent = 'Instant online deposit • Authorize with your 4-digit JazzCash MPIN.';
            } else if (method === 'swich') {
                if (swichBtn) swichBtn.className = 'py-2.5 px-3 rounded-xl border-2 border-blue-600 bg-blue-50 text-blue-800 font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer';
                cardFields.classList.remove('hidden');
                mwalletFields.classList.add('hidden');
                if (btnText) btnText.textContent = '⚡ Proceed to Swich Pay (EasyPaisa / Bank / Card)';
                if (descEl) descEl.textContent = 'Deposit securely via EasyPaisa, All Pakistani Banks, Raast QR, or Cards on Swich portal.';
            } else {
                cardBtn.className = 'py-2.5 px-3 rounded-xl border-2 border-slate-800 bg-slate-900 text-white font-bold text-xs flex items-center justify-center gap-2 transition-all cursor-pointer';
                cardFields.classList.remove('hidden');
                mwalletFields.classList.add('hidden');
                if (btnText) btnText.textContent = '💳 Proceed to Card Payment';
                if (descEl) descEl.textContent = 'Redirects to JazzCash secure payment portal for Visa / MasterCard.';
            }
        }

        // ---- Handle JazzCash Checkout Submit ----
        function handleJazzCashSubmit(e) {
            e.preventDefault();
            const form = document.getElementById('jazzcashTopupForm');
            const btn = document.getElementById('jc_submit_btn');
            const btnText = document.getElementById('jc_btn_text');
            const errBox = document.getElementById('jc_error_box');
            const waitingBox = document.getElementById('jc_waiting_box');

            errBox.classList.add('hidden');
            errBox.textContent = '';
            if (waitingBox) waitingBox.classList.add('hidden');

            const amount = parseFloat(document.getElementById('jc_amount').value || 0);
            const minAmount = (jcCurrentSubMethod === 'swich') ? 10 : 1;
            if (amount < minAmount) {
                errBox.textContent = (jcCurrentSubMethod === 'swich') 
                    ? 'Please enter a valid amount (minimum 10 PKR for Swich Pay).'
                    : 'Please enter a valid amount (minimum 1 PKR).';
                errBox.classList.remove('hidden');
                return;
            }

            if (jcCurrentSubMethod === 'mwallet') {
                const phone = (document.getElementById('jc_phone')?.value || '').replace(/\D/g, '');
                const cnic  = (document.getElementById('jc_cnic')?.value || '').replace(/\D/g, '');

                if (!phone || phone.length !== 11 || !phone.startsWith('03')) {
                    errBox.textContent = 'Please enter a valid 11-digit JazzCash mobile number (e.g. 03001234567).';
                    errBox.classList.remove('hidden');
                    return;
                }
                if (!cnic || cnic.length !== 6) {
                    errBox.textContent = 'Please enter the last 6 digits of your CNIC linked to your JazzCash account.';
                    errBox.classList.remove('hidden');
                    return;
                }

                btn.disabled = true;
                btnText.textContent = '📲 Prompt sent! Enter MPIN on phone…';
                btn.classList.add('opacity-75', 'cursor-not-allowed');
                if (waitingBox) waitingBox.classList.remove('hidden');
            } else {
                btn.disabled = true;
                btnText.textContent = 'Generating Secure Checkout...';
                btn.classList.add('opacity-75', 'cursor-not-allowed');
            }

            const formData = new FormData(form);

            fetch('initiate_checkout.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.redirect_url) {
                    btnText.textContent = '✅ Approved! Updating balance…';
                    window.location.href = data.redirect_url;
                } else if (data.success && data.post_url && data.params) {
                    btnText.textContent = 'Redirecting to JazzCash...';
                    const postForm = document.createElement('form');
                    postForm.method = 'POST';
                    postForm.action = data.post_url;
                    for (const key in data.params) {
                        if (data.params.hasOwnProperty(key)) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = key;
                            input.value = data.params[key];
                            postForm.appendChild(input);
                        }
                    }
                    document.body.appendChild(postForm);
                    postForm.submit();
                } else {
                    btn.disabled = false;
                    btn.classList.remove('opacity-75', 'cursor-not-allowed');
                    if (waitingBox) waitingBox.classList.add('hidden');
                    btnText.textContent = (jcCurrentSubMethod === 'mwallet') ? '⚡ Deposit via JazzCash Mobile (MPIN)' : '💳 Proceed to Card Payment';
                    errBox.textContent = data.message || 'Unable to complete payment. Please check your details or try again.';
                    errBox.classList.remove('hidden');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
                if (waitingBox) waitingBox.classList.add('hidden');
                btnText.textContent = (jcCurrentSubMethod === 'mwallet') ? '⚡ Deposit via JazzCash Mobile (MPIN)' : '💳 Proceed to Card Payment';
                errBox.textContent = 'Network or server error. Please try again.';
                errBox.classList.remove('hidden');
            });
        }

        const fileInput = document.getElementById('receipt');
        const fileDisplay = document.getElementById('file-name-display');

        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    fileDisplay.textContent = 'Selected: ' + e.target.files[0].name;
                    fileDisplay.classList.add('text-emerald-600', 'font-semibold');
                }
            });
        }

        // ---- Profile Dropdown ----
        function toggleProfileDropdown() {
            const menu = document.getElementById('profileDropdownMenu');
            if (!menu) return;
            if (menu.classList.contains('hidden')) {
                menu.classList.remove('hidden');
                setTimeout(() => { menu.classList.remove('opacity-0', 'scale-95'); menu.classList.add('opacity-100', 'scale-100'); }, 10);
            } else {
                menu.classList.remove('opacity-100', 'scale-100');
                menu.classList.add('opacity-0', 'scale-95');
                setTimeout(() => menu.classList.add('hidden'), 150);
            }
        }
        document.addEventListener('click', function(e) {
            const btn = document.getElementById('profileDropdownBtn');
            const menu = document.getElementById('profileDropdownMenu');
            if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('opacity-100', 'scale-100');
                menu.classList.add('opacity-0', 'scale-95');
                setTimeout(() => menu.classList.add('hidden'), 150);
            }
        });

        function toggleMobileMenu() {
            const menu = document.getElementById('mobileNavigationMenu');
            if (menu) menu.classList.toggle('hidden');
        }
    </script>
</body>
</html>
