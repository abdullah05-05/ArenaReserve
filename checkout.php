<?php
/**
 * checkout.php — Dedicated, modern, responsive multi-slot booking checkout page.
 * Supports both Wallet and JazzCash Online checkout flows with live hold countdown.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'logo_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Parse parameters from GET or POST
$ground_id = intval($_GET['ground_id'] ?? ($_GET['ground'] ?? ($_POST['ground_id'] ?? 0)));
$slot_date = trim($_GET['date'] ?? ($_GET['slot_date'] ?? ($_POST['slot_date'] ?? '')));
$booking_type = trim($_GET['type'] ?? ($_GET['booking_type'] ?? ($_POST['booking_type'] ?? 'direct')));

// Parse slot hours (array or comma-separated string)
$raw_hours = $_GET['hours'] ?? ($_GET['slot_hours'] ?? ($_POST['slot_hours'] ?? ($_GET['hour'] ?? -1)));
$slot_hours = [];

if (is_array($raw_hours)) {
    $slot_hours = array_map('intval', $raw_hours);
} elseif (is_string($raw_hours) || is_numeric($raw_hours)) {
    $raw_str = trim((string)$raw_hours);
    if (str_starts_with($raw_str, '[') && str_ends_with($raw_str, ']')) {
        $decoded = json_decode($raw_str, true);
        if (is_array($decoded)) {
            $slot_hours = array_map('intval', $decoded);
        }
    } else {
        $slot_hours = array_map('intval', explode(',', $raw_str));
    }
}

$slot_hours = array_values(array_unique(array_filter($slot_hours, fn($h) => $h >= 0 && $h <= 23)));
sort($slot_hours);

// Redirect to booking page if params are missing
if (!$ground_id || empty($slot_date) || empty($slot_hours)) {
    header("Location: book_slot.php");
    exit;
}

// 1. Fetch ground details
$gStmt = $pdo->prepare("SELECT id, title, address, sport_type, owner_id FROM grounds WHERE id = ?");
$gStmt->execute([$ground_id]);
$ground = $gStmt->fetch();
if (!$ground) {
    header("Location: book_slot.php");
    exit;
}

// 2. Fetch user wallet balance & profile
try {
    $wStmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $wStmt->execute([$user_id]);
    $wallet = $wStmt->fetch();
    $available_balance = floatval($wallet['available_balance'] ?? 0);
} catch (Exception $e) {
    $available_balance = 0.00;
}

try {
    $uStmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $uStmt->execute([$user_id]);
    $currentUser = $uStmt->fetch() ?: ['name' => $_SESSION['name'] ?? 'Player', 'email' => '', 'phone' => ''];
} catch (Exception $e) {
    $currentUser = ['name' => $_SESSION['name'] ?? 'Player', 'email' => '', 'phone' => ''];
}

// Fetch active opponents/teams for Team Challenge
$opponents = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, city, current_role 
        FROM users 
        WHERE status = 'Active' AND id != ?
        ORDER BY name ASC 
        LIMIT 100
    ");
    $stmt->execute([$user_id]);
    $opponents = $stmt->fetchAll();
} catch (Exception $e) { $opponents = []; }

$preselected_opponent_id = intval($_GET['challenged_user_id'] ?? ($_GET['opponent_id'] ?? ($_GET['team_id'] ?? 0)));
$prefilled_team_name = trim($_GET['challenger_team_name'] ?? '');
if (empty($prefilled_team_name)) {
    $prefilled_team_name = ($currentUser['name'] ?? 'Player') . "'s Squad";
}

// 3. Ensure holds exist or place 10-minute hold for all requested slots
$inClause = implode(',', array_fill(0, count($slot_hours), '?'));

// Check if any slot is booked
$bChk = $pdo->prepare("
    SELECT slot_hour FROM bookings
    WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
    AND status NOT IN ('cancelled')
");
$bChk->execute(array_merge([$ground_id, $slot_date], $slot_hours));
$bookedList = $bChk->fetchAll();
if (!empty($bookedList)) {
    header("Location: book_slot.php?ground={$ground_id}&date={$slot_date}&error=" . urlencode("One or more selected slots are already booked."));
    exit;
}

// Check other users' active holds
$otherHoldChk = $pdo->prepare("
    SELECT slot_hour FROM slot_holds
    WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)
    AND held_by != ? AND expires_at >= NOW()
");
$otherHoldChk->execute(array_merge([$ground_id, $slot_date], $slot_hours, [$user_id]));
if ($otherHoldChk->fetch()) {
    header("Location: book_slot.php?ground={$ground_id}&date={$slot_date}&error=" . urlencode("One or more slots are currently on hold by another player."));
    exit;
}

// Refresh / place hold
$holdStmt = $pdo->prepare("
    INSERT INTO slot_holds (ground_id, slot_date, slot_hour, held_by, expires_at)
    VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
    ON DUPLICATE KEY UPDATE held_by = VALUES(held_by), expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
");
foreach ($slot_hours as $h) {
    $holdStmt->execute([$ground_id, $slot_date, $h, $user_id]);
}

// Fetch exact remaining seconds
$remStmt = $pdo->prepare("
    SELECT MIN(TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining_sec
    FROM slot_holds
    WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause) AND held_by = ?
");
$remStmt->execute(array_merge([$ground_id, $slot_date], $slot_hours, [$user_id]));
$remRow = $remStmt->fetch();
$hold_remaining_seconds = max(0, intval($remRow['remaining_sec'] ?? 600));

// 4. Fetch slot prices
$pStmt = $pdo->prepare("SELECT hour, price FROM ground_slots WHERE ground_id = ? AND hour IN ($inClause) AND is_available = 1");
$pStmt->execute(array_merge([$ground_id], $slot_hours));
$priceRows = $pStmt->fetchAll();
$slotPrices = [];
foreach ($priceRows as $pr) {
    $slotPrices[intval($pr['hour'])] = floatval($pr['price']);
}

$slots_data = [];
$total_full_price = 0.0;
foreach ($slot_hours as $h) {
    $price = $slotPrices[$h] ?? 2000.0;
    $suffix    = $h < 12 ? 'AM' : 'PM';
    $displayH  = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
    $nextH     = $h + 1;
    $nextDisp  = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
    $nextSuffix = $nextH < 12 ? 'AM' : 'PM';
    $time_label = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuffix);

    $slots_data[] = [
        'hour'       => $h,
        'time_label' => $time_label,
        'price'      => $price
    ];
    $total_full_price += $price;
}

$direct_advance_amount = round($total_full_price * 0.50, 2);
$challenge_advance_amount = round($total_full_price * 0.25, 2);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Checkout – ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        .choice-card {
            border: 2px solid #e2e8f0; border-radius: 14px; padding: 14px; cursor: pointer;
            transition: all 0.18s ease;
        }
        .choice-card:hover { border-color: #059669; background: #f0fdf4; }
        .choice-card.selected { border-color: #059669; background: #f0fdf4; box-shadow: 0 0 0 3px rgba(5,150,105,0.15); }

        .pay-method-card {
            border: 2px solid #e2e8f0; border-radius: 18px; cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative;
            background: #ffffff;
        }
        .pay-method-card:hover {
            border-color: #059669; background: #f8fafc; transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(5,150,105,0.12);
        }
        .pay-method-card.selected {
            border-color: #059669; background: #ecfdf5;
            box-shadow: 0 0 0 2px rgba(5,150,105,0.25), 0 6px 18px rgba(5,150,105,0.15);
        }
        .pay-method-card .selection-check {
            display: none;
        }
        .pay-method-card.selected .selection-check {
            display: flex;
        }

        #toast {
            position: fixed; top: 20px; right: 20px; z-index: 9999;
            background: #1e293b; color: white; padding: 12px 20px; border-radius: 12px;
            font-size: 14px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            transform: translateX(120%); transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
            display: flex; align-items: center; gap: 8px; max-width: 380px;
        }
        #toast.show { transform: translateX(0); }
        #toast.success { background: linear-gradient(135deg, #059669, #047857); }
        #toast.error   { background: linear-gradient(135deg, #dc2626, #b91c1c); }
        #toast.info    { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    </style>
</head>
<body class="bg-slate-100 min-h-screen flex flex-col text-slate-800 antialiased">

<!-- Toast Notification -->
<div id="toast"></div>

<!-- Top Navigation Header -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between h-16 items-center">
        <div class="flex items-center gap-3">
            <a href="book_slot.php?ground=<?php echo $ground_id; ?>&date=<?php echo $slot_date; ?>" class="text-slate-400 hover:text-slate-700 transition-colors p-1.5 rounded-lg hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <a href="explore.php" class="flex items-center gap-2 text-emerald-600 font-bold text-lg">
                <?php echo get_logo_markup('h-7 w-7 flex-shrink-0'); ?>
                <span>Arena<span class="text-slate-900">Reserve</span></span>
            </a>
        </div>
        <div class="flex items-center gap-3">
            <a href="wallet.php" class="hidden sm:flex items-center bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full text-xs font-semibold border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span>
                <span><?php echo number_format($available_balance, 0); ?> PKR</span>
            </a>
            <?php include __DIR__ . '/assets/notification_bell.php'; ?>
        </div>
    </div>
</header>

<!-- Guaranteed Hold Timer Banner -->
<div class="bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-600 text-white py-3 px-4 shadow-sm border-b border-emerald-700/30">
    <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="flex items-center gap-2">
            <span class="flex h-2.5 w-2.5 relative">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-white"></span>
            </span>
            <span class="font-medium text-emerald-50">Slots held exclusively for you. Complete booking before timer expires:</span>
        </div>
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-1.5 font-mono font-bold text-sm text-white bg-white/20 px-3 py-1 rounded-lg border border-white/30 backdrop-blur-xs shadow-2xs">
                <svg class="w-4 h-4 text-white animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span id="hold-countdown-text">10:00</span>
            </div>
        </div>
    </div>
    <div class="max-w-7xl mx-auto mt-2 h-1 bg-emerald-900/30 rounded-full overflow-hidden">
        <div id="hold-progress-bar" class="h-full bg-white transition-all duration-1000" style="width: 100%;"></div>
    </div>
</div>

<<!-- Main Checkout Body -->
<main class="max-w-lg sm:max-w-xl mx-auto px-4 py-8 sm:py-12 flex-1 w-full flex items-center justify-center">

    <div class="bg-white rounded-3xl p-6 sm:p-7 shadow-xl border border-slate-200/90 space-y-5 w-full">
        
        <h3 class="text-lg sm:text-xl font-extrabold text-slate-900 pb-3 border-b border-slate-100 flex items-center justify-between">
            <span>Payment Summary</span>
            <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                <?php echo count($slots_data); ?> Slot(s)
            </span>
        </h3>

        <!-- Pricing Breakdown -->
        <div class="space-y-2.5 text-xs">
            <div class="flex justify-between text-slate-500 font-medium">
                <span>Total Full Price:</span>
                <span class="font-bold text-slate-900" id="disp-full-price"><?php echo number_format($total_full_price, 0); ?> PKR</span>
            </div>

            <div class="border-t border-slate-100 pt-2 flex justify-between items-center">
                <div>
                    <span class="font-bold text-slate-900 text-sm block" id="disp-advance-label">Advance Due Now (50%):</span>
                    <span class="text-[11px] text-slate-400" id="disp-advance-desc">Secures your venue reservation</span>
                </div>
                <span class="font-extrabold text-emerald-600 text-xl" id="disp-advance-price">
                    <?php echo number_format($direct_advance_amount, 0); ?> PKR
                </span>
            </div>

            <div class="flex justify-between items-center text-amber-900 bg-amber-50/70 border border-amber-200/80 rounded-xl p-3 text-xs font-medium">
                <span>Pay at Venue (Remaining 50%):</span>
                <span class="font-bold text-amber-900" id="disp-venue-due"><?php echo number_format($total_full_price - $direct_advance_amount, 0); ?> PKR</span>
            </div>
        </div>

        <!-- Hidden fields for challenge / payer details so JS functions remain 100% functional -->
        <input type="hidden" id="challenger-team-name" value="<?php echo htmlspecialchars($prefilled_team_name); ?>">
        <input type="hidden" id="challenged-user-id" value="<?php echo htmlspecialchars($preselected_opponent_id); ?>">
        <input type="hidden" id="challenge-message" value="">

        <!-- Payment Method Switcher -->
        <div class="pt-2">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-3">SELECT PAYMENT METHOD</label>
            
            <div class="grid grid-cols-3 gap-2 sm:gap-3.5 mb-5 w-full">
                <!-- Wallet Option -->
                <div id="btn-method-wallet" onclick="selectPayMethod('wallet')" class="pay-method-card selected h-28 sm:h-36 p-2 sm:p-3 flex flex-col items-center justify-between text-center relative overflow-hidden select-none">
                    <div class="selection-check absolute top-1.5 right-1.5 sm:top-2 sm:right-2 w-4 h-4 rounded-full bg-emerald-600 text-white flex items-center justify-center text-[10px] font-bold shadow-2xs">✓</div>
                    
                    <!-- Wallet SVG Logo -->
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0 shadow-2xs mt-0.5">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4" />
                            <path d="M3 5v14a2 2 0 0 0 2 2h16v-5" />
                            <path d="M18 12a2 2 0 0 0 0 4h4v-4Z" />
                        </svg>
                    </div>

                    <div class="w-full">
                        <div class="font-extrabold text-xs sm:text-sm text-slate-900 leading-tight">Wallet</div>
                        <div class="text-[10px] sm:text-[11px] font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded-md border border-emerald-200 mt-1 truncate">
                            <?php echo number_format($available_balance, 0); ?> PKR
                        </div>
                    </div>
                </div>

                <!-- JazzCash Mobile Account -->
                <div id="btn-method-mwallet" onclick="selectPayMethod('mwallet')" class="pay-method-card h-28 sm:h-36 p-2 sm:p-3 flex flex-col items-center justify-between text-center relative overflow-hidden select-none">
                    <div class="selection-check absolute top-1.5 right-1.5 sm:top-2 sm:right-2 w-4 h-4 rounded-full bg-emerald-600 text-white flex items-center justify-center text-[10px] font-bold shadow-2xs">✓</div>

                    <!-- JazzCash Logo Badge -->
                    <div class="h-10 sm:h-12 w-full flex items-center justify-center flex-shrink-0 mt-0.5">
                        <div class="px-2.5 py-1.5 bg-[#ED1C24] rounded-xl flex items-center gap-1 shadow-2xs">
                            <div class="w-2.5 h-2.5 rounded-full bg-amber-300 flex-shrink-0"></div>
                            <span class="text-white font-black text-[11px] sm:text-xs tracking-tight">Jazz<span class="text-amber-300">Cash</span></span>
                        </div>
                    </div>

                    <div class="w-full">
                        <div class="font-extrabold text-xs sm:text-sm text-slate-900 leading-tight">JazzCash</div>
                        <div class="text-[10px] sm:text-[11px] font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded-md border border-red-200 mt-1 truncate">
                            Mobile PIN
                        </div>
                    </div>
                </div>

                <!-- Card Option -->
                <div id="btn-method-card" onclick="selectPayMethod('card')" class="pay-method-card h-28 sm:h-36 p-2 sm:p-3 flex flex-col items-center justify-between text-center relative overflow-hidden select-none">
                    <div class="selection-check absolute top-1.5 right-1.5 sm:top-2 sm:right-2 w-4 h-4 rounded-full bg-emerald-600 text-white flex items-center justify-center text-[10px] font-bold shadow-2xs">✓</div>

                    <!-- Visa & Mastercard Dual Logos -->
                    <div class="h-10 sm:h-12 w-full flex items-center justify-center gap-1.5 flex-shrink-0 mt-0.5">
                        <div class="px-2 py-1 bg-white border border-slate-200 rounded shadow-2xs flex items-center">
                            <span class="text-[#1A1F71] font-black italic text-[11px] tracking-tighter">VISA</span>
                        </div>
                        <div class="px-1.5 py-1 bg-white border border-slate-200 rounded shadow-2xs flex items-center">
                            <div class="flex items-center -space-x-1">
                                <div class="w-3.5 h-3.5 rounded-full bg-[#EB001B]"></div>
                                <div class="w-3.5 h-3.5 rounded-full bg-[#F79E1B] opacity-90"></div>
                            </div>
                        </div>
                    </div>

                    <div class="w-full">
                        <div class="font-extrabold text-xs sm:text-sm text-slate-900 leading-tight">Cards</div>
                        <div class="text-[10px] sm:text-[11px] font-bold text-slate-600 bg-slate-100 px-1.5 py-0.5 rounded-md border border-slate-200 mt-1 truncate">
                            Visa · Master
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel 1: Wallet -->
            <div id="checkout-panel-wallet" class="space-y-3">
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 text-xs space-y-2">
                    <div class="flex justify-between text-slate-600 font-medium">
                        <span>Wallet Balance:</span>
                        <span class="font-bold text-slate-900"><?php echo number_format($available_balance, 0); ?> PKR</span>
                    </div>
                    <div class="flex justify-between text-slate-600 font-medium">
                        <span>Balance After Payment:</span>
                        <span class="font-extrabold" id="disp-balance-after">-- PKR</span>
                    </div>
                </div>

                <div id="wallet-insufficient-msg" class="hidden bg-red-50 border border-red-200 rounded-2xl p-3 text-xs text-red-700 space-y-1.5">
                    <div class="font-bold">⚠️ Insufficient Wallet Balance</div>
                    <p class="text-[11px]">Your balance is lower than required. You can pay instantly using JazzCash Mobile or Card.</p>
                    <button type="button" onclick="selectPayMethod('mwallet')" class="text-xs font-bold text-red-700 underline cursor-pointer">
                        Switch to JazzCash Mobile PIN →
                    </button>
                </div>

                <button type="button" id="pay-wallet-submit-btn" onclick="executeWalletPayment()"
                        class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold py-3.5 px-4 rounded-xl text-base transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2 cursor-pointer">
                    <span id="pay-wallet-btn-text">✅ Pay <?php echo number_format($direct_advance_amount, 0); ?> PKR from Wallet</span>
                </button>
            </div>

            <!-- Panel 2: JazzCash Mobile Account (REST API v2.0) -->
            <div id="checkout-panel-mwallet" class="hidden space-y-3">
                <div class="bg-gradient-to-r from-red-50 to-amber-50 border border-red-200 rounded-2xl p-3 text-xs space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-slate-900 flex items-center gap-1.5">
                            <span>📱</span> JazzCash Mobile Account
                        </span>
                        <span class="px-2 py-0.5 bg-red-600 text-[10px] font-bold text-white rounded-full">Instant MPIN</span>
                    </div>
                    <p class="text-[11px] text-slate-600">Enter your mobile number and CNIC digits. You will receive an approval prompt on your phone.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">JazzCash Mobile #</label>
                        <input type="tel" id="mwallet-phone" maxlength="11"
                               value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>"
                               placeholder="03001234567"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-red-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">CNIC (Last 6 Digits)</label>
                        <input type="text" id="mwallet-cnic" maxlength="6"
                               placeholder="e.g. 123456"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-red-500 focus:outline-none">
                    </div>
                </div>

                <!-- Waiting status alert -->
                <div id="mwallet-waiting-alert" class="hidden p-3 bg-amber-50 border border-amber-300 rounded-xl text-xs text-amber-900 space-y-1">
                    <div class="flex items-center gap-2 font-bold">
                        <svg class="animate-spin h-4 w-4 text-amber-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <span>Approval Prompt Sent to Your Mobile!</span>
                    </div>
                    <p class="text-[11px] text-amber-800">Please unlock your phone now, check your screen, and enter your 4-digit JazzCash MPIN.</p>
                </div>

                <div id="mwallet-error-box" class="hidden p-3 bg-red-50 border border-red-200 rounded-xl text-xs text-red-700 font-medium"></div>

                <button type="button" id="pay-mwallet-submit-btn" onclick="executeMWalletPayment()"
                        class="w-full bg-gradient-to-r from-red-600 to-amber-600 hover:from-red-700 hover:to-amber-700 text-white font-extrabold py-3.5 px-4 rounded-xl text-sm transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span id="pay-mwallet-btn-text">📱 Pay <?php echo number_format($direct_advance_amount, 0); ?> PKR via JazzCash Mobile</span>
                </button>
            </div>

            <!-- Panel 3: Debit / Credit Card (Page Redirection) -->
            <div id="checkout-panel-card" class="hidden space-y-3">
                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-3 text-xs space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-slate-800 flex items-center gap-1.5">
                            <span>💳</span> Debit / Credit Card Checkout
                        </span>
                        <div class="flex items-center gap-1">
                            <span class="px-1.5 py-0.5 bg-white border border-slate-300 text-[9px] font-bold text-slate-700 rounded">Visa</span>
                            <span class="px-1.5 py-0.5 bg-white border border-slate-300 text-[9px] font-bold text-slate-700 rounded">MasterCard</span>
                            <span class="px-1.5 py-0.5 bg-white border border-slate-300 text-[9px] font-bold text-slate-700 rounded">PayPak</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-600">You will be transferred securely to JazzCash's official card payment portal.</p>
                </div>

                <button type="button" id="pay-card-submit-btn" onclick="executeCardPayment()"
                        class="w-full bg-slate-900 hover:bg-slate-800 text-white font-extrabold py-3.5 px-4 rounded-xl text-sm transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span id="pay-card-btn-text">💳 Proceed to Card Payment (<?php echo number_format($direct_advance_amount, 0); ?> PKR)</span>
                </button>
            </div>

        </div>

    </div>

</main>

<?php include __DIR__ . '/footer.php'; ?>

<script>
const groundId         = <?php echo $ground_id; ?>;
const slotDate         = '<?php echo $slot_date; ?>';
const slotHours        = <?php echo json_encode($slot_hours); ?>;
const totalFullPrice   = <?php echo $total_full_price; ?>;
const userBalance      = <?php echo $available_balance; ?>;
let selectedType       = '<?php echo $booking_type; ?>';
let selectedMethod     = 'wallet';
let holdSeconds        = <?php echo $hold_remaining_seconds; ?>;

// ---- Format helpers ----
function formatNum(n) { return Math.round(n).toLocaleString('en-PK'); }

// ---- Booking Type Switcher ----
function setBookingType(type, el) {
    selectedType = type;
    document.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
    if (el) el.classList.add('selected');
    recalculatePrices();
}

// ---- Payment Method Switcher ----
function selectPayMethod(method) {
    selectedMethod = method;
    const wCard   = document.getElementById('btn-method-wallet');
    const mwCard  = document.getElementById('btn-method-mwallet');
    const cCard   = document.getElementById('btn-method-card');

    const wPanel  = document.getElementById('checkout-panel-wallet');
    const mwPanel = document.getElementById('checkout-panel-mwallet');
    const cPanel  = document.getElementById('checkout-panel-card');

    if (wCard)  wCard.classList.toggle('selected', method === 'wallet');
    if (mwCard) mwCard.classList.toggle('selected', method === 'mwallet');
    if (cCard)  cCard.classList.toggle('selected', method === 'card');

    if (wPanel)  wPanel.classList.toggle('hidden', method !== 'wallet');
    if (mwPanel) mwPanel.classList.toggle('hidden', method !== 'mwallet');
    if (cPanel)  cPanel.classList.toggle('hidden', method !== 'card');
}

// ---- Recalculate Totals ----
function recalculatePrices() {
    const isDirect = (selectedType === 'direct');
    const advanceRate = isDirect ? 0.50 : 0.25;
    const advanceAmount = Math.round(totalFullPrice * advanceRate);
    const venueDue = isDirect ? (totalFullPrice - advanceAmount) : (totalFullPrice - (advanceAmount * 2));

    // Toggle Team Challenge Setup Card
    const tcCard = document.getElementById('team-challenge-card');
    if (tcCard) {
        if (selectedType === 'team_challenge') {
            tcCard.classList.remove('hidden');
        } else {
            tcCard.classList.add('hidden');
        }
    }

    const labelEl = document.getElementById('disp-advance-label');
    const descEl  = document.getElementById('disp-advance-desc');

    if (selectedType === 'direct') {
        if (labelEl) labelEl.textContent = 'Advance Due Now (50%):';
        if (descEl)  descEl.textContent  = 'Secures your venue reservation';
    } else if (selectedType === 'open_challenge') {
        if (labelEl) labelEl.textContent = 'Your Share Now (25% Advance):';
        if (descEl)  descEl.textContent  = 'Opponent will pay 25% share';
    } else if (selectedType === 'team_challenge') {
        if (labelEl) labelEl.textContent = 'Your Squad Share (25% Advance):';
        if (descEl)  descEl.textContent  = 'Challenged team will pay 25% upon accepting';
    }

    document.getElementById('disp-advance-price').textContent = formatNum(advanceAmount) + ' PKR';
    document.getElementById('disp-venue-due').textContent     = formatNum(venueDue) + ' PKR';

    const afterBal = userBalance - advanceAmount;
    const afterEl = document.getElementById('disp-balance-after');
    if (afterEl) {
        afterEl.textContent = formatNum(afterBal) + ' PKR';
        afterEl.className = 'font-bold ' + (afterBal >= 0 ? 'text-emerald-600' : 'text-red-600');
    }

    const walletBtn = document.getElementById('pay-wallet-submit-btn');
    const walletAlert = document.getElementById('wallet-insufficient-msg');
    const wBtnText = document.getElementById('pay-wallet-btn-text');
    const mwBtnText = document.getElementById('pay-mwallet-btn-text');
    const cBtnText  = document.getElementById('pay-card-btn-text');

    if (mwBtnText) {
        mwBtnText.textContent = `📱 Pay ${formatNum(advanceAmount)} PKR via JazzCash Mobile`;
    }
    if (cBtnText) {
        cBtnText.textContent = `💳 Proceed to Card Payment (${formatNum(advanceAmount)} PKR)`;
    }

    if (userBalance < advanceAmount) {
        if (walletBtn) {
            walletBtn.disabled = true;
            walletBtn.className = 'w-full bg-slate-300 text-slate-500 font-bold py-3.5 px-4 rounded-xl text-sm cursor-not-allowed';
        }
        if (wBtnText) wBtnText.textContent = '❌ Insufficient Wallet Balance';
        if (walletAlert) walletAlert.classList.remove('hidden');
        if (selectedMethod === 'wallet') {
            selectPayMethod('mwallet');
        }
    } else {
        if (walletBtn) {
            walletBtn.disabled = false;
            walletBtn.className = 'w-full bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold py-3.5 px-4 rounded-xl text-sm transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2 cursor-pointer';
        }
        if (wBtnText) wBtnText.textContent = `✅ Pay ${formatNum(advanceAmount)} PKR from Wallet`;
        if (walletAlert) walletAlert.classList.add('hidden');
    }
}

// ---- Hold Countdown Timer ----
let holdInterval = setInterval(() => {
    holdSeconds--;
    if (holdSeconds <= 0) {
        clearInterval(holdInterval);
        showToast('⏰ Your slot hold has expired.', 'error');
        setTimeout(() => {
            window.location.href = `book_slot.php?ground=${groundId}&date=${slotDate}&error=Hold+expired`;
        }, 1500);
        return;
    }
    const m = Math.floor(holdSeconds / 60);
    const s = String(holdSeconds % 60).padStart(2, '0');
    const textEl = document.getElementById('hold-countdown-text');
    if (textEl) textEl.textContent = `${m}:${s}`;
    
    const pct = Math.max(0, Math.min(100, (holdSeconds / 600) * 100));
    const bar = document.getElementById('hold-progress-bar');
    if (bar) {
        bar.style.width = pct + '%';
        bar.style.background = pct < 25 ? '#ef4444' : (pct < 50 ? '#f59e0b' : '#10b981');
    }
}, 1000);

// ---- Execute Wallet Payment ----
function executeWalletPayment() {
    const btn = document.getElementById('pay-wallet-submit-btn');
    const btnText = document.getElementById('pay-wallet-btn-text');

    const teamName         = (document.getElementById('challenger-team-name')?.value || '').trim();
    const challengedUserId = parseInt(document.getElementById('challenged-user-id')?.value || '0', 10);
    const challengeMsg     = (document.getElementById('challenge-message')?.value || '').trim();

    if (selectedType === 'team_challenge') {
        if (!teamName) {
            showToast('⚠️ Please enter your team name.', 'error');
            return;
        }
        if (!challengedUserId || challengedUserId <= 0) {
            showToast('⚠️ Please select an opponent squad to challenge.', 'error');
            return;
        }
    }

    if (btn) btn.disabled = true;
    if (btnText) btnText.textContent = 'Confirming booking…';

    const params = {
        ground_id:            groundId,
        slot_date:            slotDate,
        slot_hours:           JSON.stringify(slotHours),
        booking_type:         selectedType,
        challenger_team_name: teamName,
        challenged_user_id:   challengedUserId,
        ch_message:           challengeMsg
    };

    fetch('process_booking.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(params)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showToast('✅ ' + res.message, 'success');
            setTimeout(() => {
                window.location.href = 'match_history.php?booked=1';
            }, 1200);
        } else {
            showToast('❌ ' + (res.message || 'Booking failed.'), 'error');
            if (btn) btn.disabled = false;
            recalculatePrices();
        }
    })
    .catch(() => {
        showToast('❌ Network error. Please try again.', 'error');
        if (btn) btn.disabled = false;
        recalculatePrices();
    });
}

// ---- Execute JazzCash Mobile Account (REST API v2.0) ----
function executeMWalletPayment() {
    const btn = document.getElementById('pay-mwallet-submit-btn');
    const btnText = document.getElementById('pay-mwallet-btn-text');
    const phoneInput = document.getElementById('mwallet-phone');
    const cnicInput  = document.getElementById('mwallet-cnic');
    const waitingAlert = document.getElementById('mwallet-waiting-alert');
    const errorBox = document.getElementById('mwallet-error-box');

    if (errorBox) {
        errorBox.classList.add('hidden');
        errorBox.textContent = '';
    }

    const phone = (phoneInput?.value || '').replace(/\D/g, '');
    const cnic  = (cnicInput?.value || '').replace(/\D/g, '');

    if (!phone || phone.length !== 11 || !phone.startsWith('03')) {
        showToast('⚠️ Please enter a valid 11-digit JazzCash mobile number (03XXXXXXXXX).', 'error');
        if (phoneInput) phoneInput.focus();
        return;
    }

    if (!cnic || cnic.length !== 6) {
        showToast('⚠️ Please enter the last 6 digits of your CNIC.', 'error');
        if (cnicInput) cnicInput.focus();
        return;
    }

    const teamName         = (document.getElementById('challenger-team-name')?.value || '').trim();
    const challengedUserId = parseInt(document.getElementById('challenged-user-id')?.value || '0', 10);
    const challengeMsg     = (document.getElementById('challenge-message')?.value || '').trim();

    if (selectedType === 'team_challenge') {
        if (!teamName) {
            showToast('⚠️ Please enter your team name.', 'error');
            return;
        }
        if (!challengedUserId || challengedUserId <= 0) {
            showToast('⚠️ Please select an opponent squad to challenge.', 'error');
            return;
        }
    }

    if (btn) btn.disabled = true;
    if (btnText) btnText.textContent = '📲 Prompt sent! Enter MPIN on phone…';
    if (waitingAlert) waitingAlert.classList.remove('hidden');

    const params = {
        purpose:              'slot_booking',
        format:               'json',
        ground_id:            groundId,
        slot_date:            slotDate,
        slot_hours:           JSON.stringify(slotHours),
        booking_type:         selectedType,
        payment_method:       'mwallet',
        mwallet_mobile:       phone,
        mwallet_cnic:         cnic,
        challenger_team_name: teamName,
        challenged_user_id:   challengedUserId,
        challenge_message:    challengeMsg
    };

    fetch('initiate_checkout.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json'
        },
        body: new URLSearchParams(params)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success && res.redirect_url) {
            if (btnText) btnText.textContent = '✅ Payment Approved! Redirecting…';
            showToast('✅ Payment approved! Transferring to confirmation…', 'success');
            setTimeout(() => {
                window.location.href = res.redirect_url;
            }, 800);
        } else {
            if (waitingAlert) waitingAlert.classList.add('hidden');
            const msg = res.message || 'Payment was declined or timed out.';
            if (errorBox) {
                errorBox.textContent = '❌ ' + msg;
                errorBox.classList.remove('hidden');
            }
            showToast('❌ ' + msg, 'error');
            if (btn) btn.disabled = false;
            recalculatePrices();
        }
    })
    .catch(() => {
        if (waitingAlert) waitingAlert.classList.add('hidden');
        const errText = 'Network timeout while waiting for phone approval. If you entered your MPIN, please check your bookings.';
        if (errorBox) {
            errorBox.textContent = '⚠️ ' + errText;
            errorBox.classList.remove('hidden');
        }
        showToast('⚠️ ' + errText, 'error');
        if (btn) btn.disabled = false;
        recalculatePrices();
    });
}

// ---- Execute Debit / Credit Card Payment (Page Redirection) ----
function executeCardPayment() {
    const btn = document.getElementById('pay-card-submit-btn');
    const btnText = document.getElementById('pay-card-btn-text');

    const teamName         = (document.getElementById('challenger-team-name')?.value || '').trim();
    const challengedUserId = parseInt(document.getElementById('challenged-user-id')?.value || '0', 10);
    const challengeMsg     = (document.getElementById('challenge-message')?.value || '').trim();

    if (selectedType === 'team_challenge') {
        if (!teamName) {
            showToast('⚠️ Please enter your team name.', 'error');
            return;
        }
        if (!challengedUserId || challengedUserId <= 0) {
            showToast('⚠️ Please select an opponent squad to challenge.', 'error');
            return;
        }
    }

    if (btn) btn.disabled = true;
    if (btnText) btnText.textContent = 'Connecting to Secure Card Checkout…';

    const params = {
        purpose:              'slot_booking',
        format:               'json',
        ground_id:            groundId,
        slot_date:            slotDate,
        slot_hours:           JSON.stringify(slotHours),
        booking_type:         selectedType,
        payment_method:       'card',
        challenger_team_name: teamName,
        challenged_user_id:   challengedUserId,
        challenge_message:    challengeMsg
    };

    fetch('initiate_checkout.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json'
        },
        body: new URLSearchParams(params)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success && res.post_url && res.params) {
            if (btnText) btnText.textContent = 'Redirecting to JazzCash Card Portal…';
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = res.post_url;
            for (const key in res.params) {
                if (res.params.hasOwnProperty(key)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = res.params[key];
                    form.appendChild(input);
                }
            }
            document.body.appendChild(form);
            form.submit();
        } else {
            showToast('❌ ' + (res.message || 'Payment initiation failed.'), 'error');
            if (btn) btn.disabled = false;
            recalculatePrices();
        }
    })
    .catch(() => {
        showToast('❌ Network error while connecting to payment gateway.', 'error');
        if (btn) btn.disabled = false;
        recalculatePrices();
    });
}

// ---- Toast Notification ----
function showToast(msg, type = 'info') {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'show ' + type;
    setTimeout(() => { t.className = t.className.replace('show', '').trim(); }, 4000);
}

// Initialize prices on page load
recalculatePrices();
</script>
</body>
</html>
