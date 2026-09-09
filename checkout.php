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
            border: 2px solid #e2e8f0; border-radius: 14px; padding: 14px; cursor: pointer;
            transition: all 0.18s ease;
        }
        .pay-method-card:hover { border-color: #059669; background: #f0fdf4; }
        .pay-method-card.selected { border-color: #059669; background: #ecfdf5; box-shadow: 0 0 0 2px rgba(5,150,105,0.2); }

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

<!-- Main Checkout Body -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1">

    <div class="mb-6">
        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900">Secure Checkout</h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">Review your selected time slots and choose your preferred payment method.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

        <!-- ================= LEFT COLUMN: BOOKING DETAILS ================= -->
        <div class="lg:col-span-7 space-y-6">

            <!-- Venue & Date Card -->
            <div class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200/80">
                <div class="flex items-start justify-between gap-4 pb-4 border-b border-slate-100">
                    <div>
                        <div class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 mb-2">
                            ⚽ <?php echo htmlspecialchars($ground['sport_type']); ?>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-slate-900"><?php echo htmlspecialchars($ground['title']); ?></h2>
                        <p class="text-xs text-slate-500 mt-0.5 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span><?php echo htmlspecialchars($ground['address'] ?? ''); ?></span>
                        </p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block">Booking Date</span>
                        <span class="text-xs sm:text-sm font-bold text-slate-800"><?php echo date('D, d M Y', strtotime($slot_date)); ?></span>
                    </div>
                </div>

                <!-- Selected Time Slots (Itemized) -->
                <div class="pt-4">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Selected Slots (<?php echo count($slots_data); ?>)</span>
                        <a href="book_slot.php?ground=<?php echo $ground_id; ?>&date=<?php echo $slot_date; ?>" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 transition-colors">
                            + Modify Slots
                        </a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach ($slots_data as $slot): ?>
                        <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                <span class="font-semibold text-slate-800"><?php echo htmlspecialchars($slot['time_label']); ?></span>
                            </div>
                            <span class="font-bold text-slate-900"><?php echo number_format($slot['price'], 0); ?> PKR</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Booking Type Selector Card -->
            <div class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200/80">
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-600 mb-3">Select Booking Type</h3>
                <div class="space-y-3">
                    
                    <!-- Direct Booking -->
                    <div class="choice-card <?php echo $booking_type === 'direct' ? 'selected' : ''; ?>" onclick="setBookingType('direct', this)">
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0 font-bold">
                                ✓
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900 text-sm">Direct Booking</span>
                                    <span class="text-xs font-bold text-emerald-600">50% Advance</span>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5">Reserve exclusively for your squad. Pay 50% advance now, remaining 50% at venue.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Open Challenge -->
                    <div class="choice-card <?php echo $booking_type === 'open_challenge' ? 'selected' : ''; ?>" onclick="setBookingType('open_challenge', this)">
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center flex-shrink-0 font-bold">
                                ⚡
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900 text-sm">Open Challenge</span>
                                    <span class="text-xs font-bold text-violet-600">25% Advance</span>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5">Post an open match. You pay 25%, opponent pays 25%, remaining 50% at venue.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Team Challenge -->
                    <div class="choice-card <?php echo $booking_type === 'team_challenge' ? 'selected' : ''; ?>" onclick="setBookingType('team_challenge', this)">
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center flex-shrink-0 font-bold text-base">
                                ⚔️
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900 text-sm">Team Challenge</span>
                                    <span class="text-xs font-bold text-amber-600">25% Advance</span>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5">Challenge a specific squad or captain. You pay 25%, opponent pays 25%, remaining 50% at venue.</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Team Challenge Setup Card (Dynamic) -->
            <div id="team-challenge-card" class="<?php echo $booking_type === 'team_challenge' ? '' : 'hidden'; ?> bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-amber-200/90 space-y-4">
                <div class="flex items-center gap-2 pb-3 border-b border-amber-100">
                    <span class="text-lg">⚔️</span>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Team Challenge Setup</h3>
                        <p class="text-[11px] text-slate-500">Specify your squad and choose the opponent squad you want to challenge.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Your Team / Squad Name <span class="text-red-500">*</span></label>
                        <input type="text" id="challenger-team-name" value="<?php echo htmlspecialchars($prefilled_team_name); ?>" placeholder="e.g. Lahore Strikers"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2.5 text-xs text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none bg-white">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Select Opponent Captain / Team <span class="text-red-500">*</span></label>
                        <select id="challenged-user-id" class="w-full border border-slate-300 rounded-xl px-3 py-2.5 text-xs text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none bg-white">
                            <option value="">-- Choose Opponent Squad --</option>
                            <?php foreach ($opponents as $opp): ?>
                            <option value="<?php echo $opp['id']; ?>" <?php echo $opp['id'] == $preselected_opponent_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($opp['name'] . (!empty($opp['city']) ? ' (' . $opp['city'] . ')' : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 mb-1 text-xs">Challenge Message (Optional)</label>
                    <input type="text" id="challenge-message" placeholder="e.g. Ready for a competitive match? Winner takes all!"
                           class="w-full border border-slate-300 rounded-xl px-3 py-2.5 text-xs text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none bg-white">
                </div>
            </div>

            <!-- Contact Information Card -->
            <div class="bg-white rounded-2xl p-5 sm:p-6 shadow-sm border border-slate-200/80">
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-600 mb-3">Payer Details (For Instant Receipt)</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Mobile Number (JazzCash / EasyPaisa / SMS)</label>
                        <input type="text" id="payer-phone" value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>" placeholder="03001234567"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2.5 text-xs text-slate-800 focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Email Address (Payment Confirmation)</label>
                        <input type="email" id="payer-email" value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" placeholder="player@example.com"
                               class="w-full border border-slate-300 rounded-xl px-3 py-2.5 text-xs text-slate-800 focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    </div>
                </div>
            </div>

        </div>

        <!-- ================= RIGHT COLUMN: PAYMENT & SUMMARY (STICKY) ================= -->
        <div class="lg:col-span-5 space-y-6 lg:sticky lg:top-24">

            <div class="bg-white rounded-2xl p-6 shadow-lg border border-slate-200/90 space-y-5">
                
                <h3 class="text-base font-bold text-slate-900 pb-3 border-b border-slate-100 flex items-center justify-between">
                    <span>Payment Summary</span>
                    <span class="text-[11px] font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                        <?php echo count($slots_data); ?> Slot(s)
                    </span>
                </h3>

                <!-- Pricing Breakdown -->
                <div class="space-y-2.5 text-xs">
                    <div class="flex justify-between text-slate-500">
                        <span>Total Full Price:</span>
                        <span class="font-semibold text-slate-800" id="disp-full-price"><?php echo number_format($total_full_price, 0); ?> PKR</span>
                    </div>

                    <div class="border-t border-slate-100 pt-2 flex justify-between items-center">
                        <div>
                            <span class="font-bold text-slate-900 text-sm block" id="disp-advance-label">Advance Due Now (50%):</span>
                            <span class="text-[10px] text-slate-400" id="disp-advance-desc">Secures your venue reservation</span>
                        </div>
                        <span class="font-extrabold text-emerald-600 text-lg" id="disp-advance-price">
                            <?php echo number_format($direct_advance_amount, 0); ?> PKR
                        </span>
                    </div>

                    <div class="flex justify-between items-center text-amber-800 bg-amber-50 border border-amber-200/80 rounded-xl p-3 text-[11px] font-medium">
                        <span>Pay at Venue (Remaining 50%):</span>
                        <span class="font-bold" id="disp-venue-due"><?php echo number_format($total_full_price - $direct_advance_amount, 0); ?> PKR</span>
                    </div>
                </div>

                <!-- Payment Method Switcher -->
                <div class="pt-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2.5">Select Payment Method</label>
                    
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <!-- Wallet Option -->
                        <div id="btn-method-wallet" onclick="selectPayMethod('wallet')" class="pay-method-card selected">
                            <div class="flex items-center gap-1.5 mb-1">
                                <span class="text-base">💳</span>
                                <span class="font-bold text-xs text-slate-900">Wallet</span>
                            </div>
                            <div class="text-[10px] text-slate-500 truncate">
                                Bal: <span class="font-bold text-slate-700"><?php echo number_format($available_balance, 0); ?> PKR</span>
                            </div>
                        </div>

                        <!-- JazzCash Option -->
                        <div id="btn-method-jazzcash" onclick="selectPayMethod('jazzcash')" class="pay-method-card">
                            <div class="flex items-center gap-1.5 mb-1">
                                <span class="text-base">⚡</span>
                                <span class="font-bold text-xs text-slate-900">JazzCash</span>
                            </div>
                            <div class="text-[10px] text-red-600 font-semibold truncate">
                                Mobile · Cards · Voucher
                            </div>
                        </div>
                    </div>

                    <!-- Panel: Wallet -->
                    <div id="checkout-panel-wallet" class="space-y-3">
                        <div class="bg-slate-50 rounded-xl p-3 border border-slate-200 text-xs space-y-1.5">
                            <div class="flex justify-between text-slate-500">
                                <span>Wallet Balance:</span>
                                <span class="font-semibold text-slate-800"><?php echo number_format($available_balance, 0); ?> PKR</span>
                            </div>
                            <div class="flex justify-between text-slate-500">
                                <span>Balance After Payment:</span>
                                <span class="font-bold" id="disp-balance-after">-- PKR</span>
                            </div>
                        </div>

                        <div id="wallet-insufficient-msg" class="hidden bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-700 space-y-1.5">
                            <div class="font-bold">⚠️ Insufficient Wallet Balance</div>
                            <p class="text-[11px]">Your wallet balance is lower than the advance required. You can pay instantly using JazzCash.</p>
                            <button type="button" onclick="selectPayMethod('jazzcash')" class="text-xs font-bold text-red-700 underline cursor-pointer">
                                Switch to JazzCash Online Checkout →
                            </button>
                        </div>

                        <button type="button" id="pay-wallet-submit-btn" onclick="executeWalletPayment()"
                                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold py-3.5 px-4 rounded-xl text-sm transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2 cursor-pointer">
                            <span id="pay-wallet-btn-text">✅ Pay <?php echo number_format($direct_advance_amount, 0); ?> PKR from Wallet</span>
                        </button>
                    </div>

                    <!-- Panel: JazzCash Online -->
                    <div id="checkout-panel-jazzcash" class="hidden space-y-3">
                        <div class="bg-gradient-to-r from-red-50 to-amber-50 border border-red-200 rounded-xl p-3 text-xs space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-slate-800">⚡ JazzCash Online Checkout</span>
                                <div class="flex items-center gap-1">
                                    <span class="px-1.5 py-0.5 bg-white border border-red-200 text-[9px] font-bold text-red-700 rounded">JazzCash</span>
                                    <span class="px-1.5 py-0.5 bg-white border border-red-200 text-[9px] font-bold text-red-700 rounded">Debit/Credit Card</span>
                                </div>
                            </div>
                            <p class="text-[11px] text-slate-600">You will be transferred securely to JazzCash's official payment portal.</p>
                        </div>

                        <button type="button" id="pay-jazzcash-submit-btn" onclick="executeJazzCashPayment()"
                                class="w-full bg-gradient-to-r from-red-600 to-amber-600 hover:from-red-700 hover:to-amber-700 text-white font-extrabold py-3.5 px-4 rounded-xl text-sm transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            <span id="pay-jc-btn-text">⚡ Pay <?php echo number_format($direct_advance_amount, 0); ?> PKR via JazzCash</span>
                        </button>
                    </div>

                </div>

                <!-- Trust Badges -->
                <div class="pt-3 border-t border-slate-100 flex items-center justify-center gap-4 text-[10px] text-slate-400 font-medium">
                    <span class="flex items-center gap-1">🔒 256-bit SSL Secure</span>
                    <span>•</span>
                    <span class="flex items-center gap-1">⚡ Instant Hold Lock</span>
                    <span>•</span>
                    <span class="flex items-center gap-1">🛡️ Official Partner</span>
                </div>

            </div>

        </div>

    </div>

</main>

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
    const wCard  = document.getElementById('btn-method-wallet');
    const jcCard = document.getElementById('btn-method-jazzcash');
    const wPanel = document.getElementById('checkout-panel-wallet');
    const jcPanel= document.getElementById('checkout-panel-jazzcash');

    if (method === 'wallet') {
        if (wCard)  wCard.className  = 'pay-method-card selected';
        if (jcCard) jcCard.className = 'pay-method-card';
        if (wPanel) wPanel.classList.remove('hidden');
        if (jcPanel) jcPanel.classList.add('hidden');
    } else {
        if (wCard)  wCard.className  = 'pay-method-card';
        if (jcCard) jcCard.className = 'pay-method-card selected';
        if (wPanel) wPanel.classList.add('hidden');
        if (jcPanel) jcPanel.classList.remove('hidden');
    }
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
    const jcBtnText = document.getElementById('pay-jc-btn-text');

    if (jcBtnText) {
        jcBtnText.textContent = `⚡ Pay ${formatNum(advanceAmount)} PKR via JazzCash`;
    }

    if (userBalance < advanceAmount) {
        if (walletBtn) {
            walletBtn.disabled = true;
            walletBtn.className = 'w-full bg-slate-300 text-slate-500 font-bold py-3.5 px-4 rounded-xl text-sm cursor-not-allowed';
        }
        if (wBtnText) wBtnText.textContent = '❌ Insufficient Wallet Balance';
        if (walletAlert) walletAlert.classList.remove('hidden');
        selectPayMethod('jazzcash');
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

// ---- Execute JazzCash Payment ----
function executeJazzCashPayment() {
    const btn = document.getElementById('pay-jazzcash-submit-btn');
    const btnText = document.getElementById('pay-jc-btn-text');

    const phone            = document.getElementById('payer-phone')?.value || '';
    const email            = document.getElementById('payer-email')?.value || '';
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
    if (btnText) btnText.textContent = 'Initiating JazzCash Checkout…';

    const params = {
        purpose:              'slot_booking',
        format:               'json',
        ground_id:            groundId,
        slot_date:            slotDate,
        slot_hours:           JSON.stringify(slotHours),
        booking_type:         selectedType,
        payment_method:       'JazzCash',
        phone:                phone,
        email:                email,
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
            if (btnText) btnText.textContent = 'Redirecting to JazzCash…';
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
        showToast('❌ Network error while initiating checkout.', 'error');
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
