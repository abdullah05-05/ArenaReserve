<?php
session_start();
require_once 'db.php';

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
                    <!-- Wallet Display -->
                    <div class="flex items-center bg-slate-100 text-slate-800 px-3 py-1.5 rounded-full text-xs font-semibold border border-slate-200">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span>
                        Wallet: <?php echo number_format($available_balance, 2); ?> PKR
                    </div>

                    <!-- Mode Toggle -->
                    <div class="flex items-center gap-2 bg-slate-100 p-1 rounded-lg border border-slate-200">
                        <span class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?>">Player</span>
                        <a href="switch_role.php" class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?> hover:text-slate-800 transition-colors">Owner</a>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm">
                            <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                        </div>
                        <div class="hidden md:block text-left">
                            <div class="text-xs font-semibold text-slate-800"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
                            <div class="text-[10px] text-slate-400 capitalize"><?php echo htmlspecialchars($_SESSION['current_active_mode']); ?></div>
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
    </script>
</body>
</html>
