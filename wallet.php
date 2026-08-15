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

} catch (Exception $e) {
    $available_balance = 0.00;
    $frozen_balance = 0.00;
    $requests = [];
    $transactions = [];
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

                <!-- Top up Form -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                    <h3 class="text-base font-bold text-slate-900 mb-4">Manual Wallet Top-up</h3>
                    
                    <div class="mb-5 bg-slate-50 border border-slate-200 rounded-lg p-4 text-xs text-slate-600">
                        <h4 class="font-bold text-slate-800 mb-2 uppercase tracking-wide">Deposit Payment Details</h4>
                        <p class="mb-1"><span class="font-semibold text-slate-700">Bank Account:</span> Allied Bank (ABL) - 001004958273012</p>
                        <p class="mb-1"><span class="font-semibold text-slate-700">EasyPaisa/JazzCash:</span> 0300-1234567</p>
                        <p class="mt-2 text-slate-500">Transfer funds to one of these accounts, take a receipt snapshot, fill in the form, and submit.</p>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-3 text-xs text-red-700">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="mb-4 bg-green-50 border-l-4 border-green-500 p-3 text-xs text-green-700">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <form class="space-y-4" action="wallet.php" method="POST" enctype="multipart/form-data">
                        <!-- Deposit Amount -->
                        <div>
                            <label for="amount" class="block text-xs font-semibold text-slate-700">Amount (PKR)</label>
                            <div class="mt-1 relative rounded-md shadow-sm">
                                <input id="amount" name="amount" type="number" step="0.01" required placeholder="5000"
                                       value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
                                       class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-lg placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                            </div>
                        </div>

                        <!-- Reference Details -->
                        <div>
                            <label for="reference_details" class="block text-xs font-semibold text-slate-700">Transaction ID / Reference Details</label>
                            <div class="mt-1">
                                <input id="reference_details" name="reference_details" type="text" required placeholder="TID-98274192"
                                       value="<?php echo htmlspecialchars($_POST['reference_details'] ?? ''); ?>"
                                       class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-lg placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                            </div>
                        </div>

                        <!-- Payment Receipt File -->
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

                        <!-- Submit Button -->
                        <div>
                            <button type="submit"
                                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-colors">
                                Submit Deposit Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right 1 Col: Recent Audit / Topup status logs -->
            <div class="space-y-6">
                <!-- Audit Requests Log -->
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                    <h3 class="text-sm font-bold text-slate-800 mb-3 border-b border-slate-100 pb-2">Recent Deposits Status</h3>
                    <?php if (empty($requests)): ?>
                        <p class="text-xs text-slate-500 py-4 text-center">No deposit logs found.</p>
                    <?php else: ?>
                        <div class="space-y-3">
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
                        <p class="text-xs text-slate-500 py-4 text-center">No transactions recorded.</p>
                    <?php else: ?>
                        <div class="space-y-3">
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
        const fileInput = document.getElementById('receipt');
        const fileDisplay = document.getElementById('file-name-display');

        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                fileDisplay.textContent = 'Selected: ' + e.target.files[0].name;
                fileDisplay.classList.add('text-emerald-600', 'font-semibold');
            }
        });

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
