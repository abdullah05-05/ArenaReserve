<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'logo_helper.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Fetch wallet balance
try {
    $stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $available_balance = floatval($wallet['available_balance'] ?? 0);
} catch (Exception $e) { $available_balance = 0.00; }

// Fetch verified active grounds
try {
    $stmt = $pdo->prepare("SELECT * FROM grounds WHERE is_verified = 1 AND COALESCE(ground_status, 'Active') = 'Active' ORDER BY title ASC");
    $stmt->execute();
    $grounds = $stmt->fetchAll();
} catch (Exception $e) { $grounds = []; }

$selected_ground_id = intval($_GET['ground'] ?? ($grounds[0]['id'] ?? 0));
$selected_ground    = null;
foreach ($grounds as $g) { if ($g['id'] == $selected_ground_id) { $selected_ground = $g; break; } }
if (!$selected_ground && !empty($grounds)) { $selected_ground = $grounds[0]; $selected_ground_id = $selected_ground['id']; }

$selected_date = $_GET['date'] ?? date('Y-m-d');
if ($selected_date < date('Y-m-d')) $selected_date = date('Y-m-d');

// Build slots list with real statuses
$slots = [];
if ($selected_ground) {
    // Clean expired holds first
    try { $pdo->exec("DELETE FROM slot_holds WHERE expires_at < NOW()"); } catch(Exception $e) {}

    // Fetch configured slots
    try {
        $stmt = $pdo->prepare("SELECT hour, slot_type, price FROM ground_slots WHERE ground_id = ? AND is_available = 1 ORDER BY hour ASC");
        $stmt->execute([$selected_ground_id]);
        $db_slots = $stmt->fetchAll();
    } catch (Exception $e) { $db_slots = []; }

    if (!empty($db_slots)) {
        // Fetch bookings for this ground/date
        try {
            $stmt = $pdo->prepare("SELECT slot_hour, status, booked_by FROM bookings WHERE ground_id = ? AND slot_date = ? AND status NOT IN ('cancelled')");
            $stmt->execute([$selected_ground_id, $selected_date]);
            $bookings_map = [];
            foreach ($stmt->fetchAll() as $b) {
                $bookings_map[$b['slot_hour']] = $b;
            }
        } catch (Exception $e) { $bookings_map = []; }

        // Fetch active holds with MySQL-native remaining seconds (avoids PHP ↔ MySQL timezone mismatch)
        try {
            $stmt = $pdo->prepare("
                SELECT slot_hour, held_by, expires_at,
                       GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining_sec
                FROM slot_holds
                WHERE ground_id = ? AND slot_date = ? AND expires_at >= NOW()
            ");
            $stmt->execute([$selected_ground_id, $selected_date]);
            $holds_map = [];
            foreach ($stmt->fetchAll() as $h) {
                $holds_map[$h['slot_hour']] = $h;
            }
        } catch (Exception $e) { $holds_map = []; }

        foreach ($db_slots as $s) {
            $h = intval($s['hour']);
            $suffix    = $h < 12 ? 'AM' : 'PM';
            $displayH  = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
            $nextH     = $h + 1;
            $nextDisp  = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
            $nextSuffix = $nextH < 12 ? 'AM' : 'PM';
            $time_label = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuffix);
            $type           = 'available';
            $label          = '';
            $hold_remaining = 0;

            $slot_start_time = strtotime($selected_date . ' ' . sprintf('%02d:00:00', $h));
            $is_passed = ($slot_start_time <= time());

            if (isset($bookings_map[$h])) {
                $bk = $bookings_map[$h];
                if ($bk['booked_by'] == $user_id) {
                    $type  = 'my_booking';
                    $label = match($bk['status']) {
                        'confirmed'          => 'My Booking',
                        'challenge_open'     => 'My Challenge',
                        'challenge_pending'  => 'Pending',
                        'challenge_accepted' => 'Match Set',
                        default              => 'My Booking'
                    };
                } else {
                    $type  = match($bk['status']) {
                        'challenge_open'    => 'challenge',
                        'challenge_pending' => 'challenge',
                        default            => 'booked'
                    };
                    $label = match($bk['status']) {
                        'challenge_open'    => 'Open Challenge',
                        'challenge_pending' => 'Challenge',
                        default            => 'Booked'
                    };
                }
            } elseif ($is_passed) {
                $type  = 'passed';
                $label = 'Passed';
            } elseif (isset($holds_map[$h])) {
                $hold = $holds_map[$h];
                if ($hold['held_by'] == $user_id) {
                    $type  = 'held';
                    $hold_remaining = intval($hold['remaining_sec']);
                } else {
                    $type  = 'on_hold';
                    $hold_remaining = intval($hold['remaining_sec']);
                }
            }

            $slots[] = [
                'hour'           => $h,
                'time'           => $time_label,
                'type'           => $type,
                'slot_type'      => $s['slot_type'],
                'price'          => floatval($s['price']),
                'label'          => $label,
                'hold_remaining' => $hold_remaining,
            ];
        }
    } else {
        $no_slots_configured = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book a Slot – ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { font-family: 'Inter', sans-serif; }
body { background: #f5f6fa; }

/* Slot states */
.slot-available  { background: #e6f9f1; border: 1.5px solid #a7f3d0; cursor: pointer; }
.slot-available:hover { background: #d1fae5; border-color: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,0.15); }
.slot-booked     { background: #fee2e2; border: 1.5px solid #fca5a5; cursor: not-allowed; opacity: 0.85; }
.slot-my_booking { background: #fef3c7; border: 1.5px solid #fcd34d; cursor: default; }
.slot-challenge  { background: #ede9fe; border: 1.5px solid #c4b5fd; cursor: default; }
.slot-held       { background: #dbeafe; border: 2px solid #3b82f6; cursor: pointer; }
.slot-on_hold    { background: #f1f5f9; border: 1.5px solid #cbd5e1; cursor: not-allowed; opacity: 0.7; }
.slot-passed     { background: #f1f5f9; border: 1.5px solid #e2e8f0; cursor: not-allowed; opacity: 0.6; }
.slot-selected   { background: #d1fae5; border: 2.5px solid #059669; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(5,150,105,0.2); }

.slot-card { border-radius: 10px; padding: 12px; transition: all 0.18s ease; }

/* Modal */
#booking-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
    z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 16px;
    opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
}
#booking-modal-overlay.open { opacity: 1; pointer-events: all; }
#booking-modal {
    background: white; border-radius: 20px; box-shadow: 0 25px 80px rgba(0,0,0,0.3);
    max-width: 500px; width: 100%; transform: scale(0.92) translateY(20px);
    transition: transform 0.25s cubic-bezier(.34,1.56,.64,1), opacity 0.2s ease;
    opacity: 0; overflow: hidden;
}
#booking-modal-overlay.open #booking-modal { transform: scale(1) translateY(0); opacity: 1; }

/* Hold timer */
.hold-timer-bar {
    height: 4px; background: #dbeafe; border-radius: 2px; overflow: hidden; margin-top: 8px;
}
.hold-timer-fill {
    height: 100%; background: linear-gradient(90deg, #3b82f6, #6366f1);
    transition: width 1s linear; border-radius: 2px;
}

/* Choice cards */
.choice-card {
    border: 2px solid #e2e8f0; border-radius: 14px; padding: 16px; cursor: pointer;
    transition: all 0.18s ease; position: relative; overflow: hidden;
}
.choice-card:hover { border-color: #059669; background: #f0fdf4; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(5,150,105,0.12); }
.choice-card.selected { border-color: #059669; background: #f0fdf4; box-shadow: 0 0 0 3px rgba(5,150,105,0.15); }

/* Step indicator */
.step-dot { width: 8px; height: 8px; border-radius: 50%; background: #e2e8f0; transition: background 0.2s; }
.step-dot.active { background: #059669; }

/* Toast */
#toast {
    position: fixed; top: 20px; right: 20px; z-index: 9999;
    background: #1e293b; color: white; padding: 12px 20px; border-radius: 12px;
    font-size: 14px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,0.3);
    transform: translateX(120%); transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
    display: flex; align-items: center; gap: 8px; max-width: 360px;
}
#toast.show { transform: translateX(0); }
#toast.success { background: linear-gradient(135deg, #059669, #047857); }
#toast.error   { background: linear-gradient(135deg, #dc2626, #b91c1c); }
#toast.info    { background: linear-gradient(135deg, #3b82f6, #2563eb); }
</style>
    <?php
    $page_description = 'Book a sports ground slot on ArenaReserve. Choose your date, time, and venue – confirmed in seconds.';
    include 'logo_head.php';
    ?>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">

<!-- Toast notification -->
<div id="toast"></div>

<!-- Header -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between h-16 items-center">
    <div class="flex items-center gap-2">
      <button type="button" onclick="toggleMobileMenu()" class="lg:hidden text-slate-500 hover:text-slate-700 focus:outline-none p-1 rounded-md" title="Toggle Navigation">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
      </button>
      <a href="explore.php" class="flex items-center gap-1 sm:gap-2 text-emerald-600 text-[12px] sm:text-xl font-bold flex-shrink-0">
        <?php echo get_logo_markup('h-[18px] w-[18px] sm:h-7 sm:w-7 flex-shrink-0'); ?>
        <span class="hidden min-[360px]:inline">ArenaReserve</span>
      </a>
    </div>
    <div class="flex-shrink-0 flex items-center gap-1 sm:gap-3">
      <a href="wallet.php" id="navbar-wallet-badge" class="hidden sm:flex items-center bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full text-xs font-semibold border border-emerald-200">
        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span><span id="navbar-wallet-amount"><?php echo number_format($available_balance, 0); ?></span> PKR
      </a>
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
          </a>
      </div>
      <div class="flex items-center gap-2">
        <!-- Notification Bell -->
        <?php include __DIR__ . '/assets/notification_bell.php'; ?>
        <!-- Profile Dropdown -->
        <div class="relative">
          <button id="profileDropdownBtn" onclick="toggleProfileDropdown()" class="flex items-center gap-2 hover:opacity-90 focus:outline-none transition-opacity" title="User Menu">
            <div class="w-8 h-8 rounded-full overflow-hidden bg-emerald-600 text-white flex items-center justify-center font-bold text-sm flex-shrink-0 shadow-sm border border-emerald-500">
              <?php if (!empty($_SESSION['profile_picture']) && file_exists(__DIR__ . '/' . $_SESSION['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="w-full h-full object-cover">
              <?php else: ?>
                <?php $uName = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Player'; echo strtoupper(substr($uName, 0, 1)); ?>
              <?php endif; ?>
            </div>
            <div class="hidden md:block text-left">
              <div class="text-xs font-semibold text-slate-800 flex items-center gap-1">
                <?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Player'); ?>
                <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </div>
              <div class="text-[10px] text-slate-400">Player</div>
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
  <!-- Sidebar -->
  <aside class="hidden lg:block w-64 flex-shrink-0">
    <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
      <a href="explore.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>Explore Grounds</a>
      <a href="book_slot.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg"><svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Book Slot</a>
      <a href="match_history.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Match History</a>
      <a href="challenge_team.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Challenge Team</a>
      <a href="leaderboard.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Leaderboard</a>
      <div class="border-t border-slate-100 mt-1 pt-1">
        <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>My Wallet</a>
      </div>
    </nav>

    <!-- Wallet info box -->
    <div class="mt-4 bg-gradient-to-br from-emerald-600 to-teal-700 rounded-xl p-4 text-white shadow-lg">
      <div class="text-xs font-semibold opacity-80 mb-1">Wallet Balance</div>
      <div class="text-2xl font-extrabold"><?php echo number_format($available_balance, 0); ?> <span class="text-sm font-medium opacity-80">PKR</span></div>
      <a href="wallet.php" class="inline-flex items-center gap-1 mt-3 text-xs bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-full font-semibold transition-colors">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Top Up
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="flex-1 min-w-0">
    <h1 class="text-xl font-bold text-gray-800">Book a Slot</h1>
    <p class="text-sm text-gray-400 mt-0.5 mb-5">Select your preferred venue, date and time</p>

    <!-- Legend -->
    <div class="flex flex-wrap items-center gap-3 mb-4 text-[11px] font-semibold text-gray-500">
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-200 border border-emerald-400 inline-block"></span>Available</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-red-200 border border-red-400 inline-block"></span>Booked</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-amber-200 border border-amber-400 inline-block"></span>My Booking</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-violet-200 border border-violet-400 inline-block"></span>Challenge</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-blue-200 border border-blue-400 inline-block"></span>On Hold (5 min)</span>
    </div>

    <!-- Select Ground -->
    <?php if (!empty($grounds)): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 max-w-2xl shadow-sm">
      <div class="flex items-center gap-2 mb-3">
        <div class="w-7 h-7 bg-emerald-100 rounded-lg flex items-center justify-center">
          <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-gray-800">Select Venue</div>
          <div class="text-xs text-gray-400">Choose your ground</div>
        </div>
      </div>
      <select id="ground-select" data-ground-name="<?php echo htmlspecialchars($selected_ground['title'] ?? ''); ?>"
              onchange="onGroundChanged(this.value)"
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-400">
        <?php foreach ($grounds as $g): ?>
          <option value="<?php echo $g['id']; ?>" data-sport="<?php echo htmlspecialchars($g['sport_type']); ?>" data-title="<?php echo htmlspecialchars($g['title']); ?>" <?php echo ($g['id']==$selected_ground_id)?'selected':''; ?>>
            <?php echo htmlspecialchars($g['title']); ?> — <?php echo $g['sport_type']; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <!-- Select Date -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 max-w-2xl shadow-sm">
      <div class="flex items-center gap-2 mb-3">
        <div class="w-7 h-7 bg-emerald-100 rounded-lg flex items-center justify-center">
          <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-gray-800">Select Date</div>
          <div class="text-xs text-gray-400">Choose your booking date</div>
        </div>
      </div>
      <input id="booking-date" type="date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo $selected_date; ?>"
             onchange="onDateChanged(this.value)"
             class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-400">
    </div>

    <!-- Time Slots Grid -->
    <div class="bg-white rounded-xl border border-gray-200 p-5 max-w-2xl shadow-sm">
      <div class="flex items-center justify-between mb-4">
        <div>
          <div class="text-sm font-bold text-gray-800">Available Time Slots</div>
          <div class="text-xs text-gray-400 mt-0.5" id="slots-date-label"><?php echo date('l, F j, Y', strtotime($selected_date)); ?></div>
        </div>
        <div class="flex items-center gap-2">
          <span id="live-indicator" class="flex items-center gap-1 text-[11px] font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>Live
          </span>
          <?php if ($selected_ground): ?>
          <div id="ground-sport-badge" class="text-xs text-slate-500 font-medium bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200">
            <?php echo htmlspecialchars($selected_ground['sport_type']); ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div id="slots-container">
        <?php if (empty($slots)): ?>
          <div class="text-center py-12" id="slots-empty-state">
            <div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
              <svg class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <?php if (!empty($no_slots_configured)): ?>
              <p class="text-sm font-semibold text-slate-500">No slots configured yet</p>
              <p class="text-xs text-slate-400 mt-1">The owner hasn't set up time slots for this venue yet.</p>
            <?php else: ?>
              <p class="text-sm font-semibold text-slate-500">No available slots</p>
              <p class="text-xs text-slate-400 mt-1">There are no open slots for this venue on the selected date.</p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-2 gap-3" id="slots-grid">
            <?php foreach ($slots as $slot):
              $isPeak = ($slot['slot_type'] ?? 'Normal') === 'Peak';
              // 'available' and 'held' (own hold) are both clickable
              $isClickable = in_array($slot['type'], ['available', 'held']);
              $colorClasses = [
                'available'  => ['text' => 'text-emerald-800', 'price' => 'text-emerald-700'],
                'booked'     => ['text' => 'text-red-700',     'price' => 'text-red-600'],
                'my_booking' => ['text' => 'text-amber-700',   'price' => 'text-amber-600'],
                'challenge'  => ['text' => 'text-violet-700',  'price' => 'text-violet-600'],
                'held'       => ['text' => 'text-blue-700',    'price' => 'text-blue-600'],
                'on_hold'    => ['text' => 'text-slate-600',   'price' => 'text-slate-500'],
                'passed'     => ['text' => 'text-slate-400',   'price' => 'text-slate-400'],
              ];
              $tc = $colorClasses[$slot['type']] ?? $colorClasses['available'];
            ?>
            <div class="slot-card slot-<?php echo $slot['type']; ?>"
                 id="slot-card-<?php echo $slot['hour']; ?>"
                 data-hour="<?php echo $slot['hour']; ?>"
                 data-time="<?php echo htmlspecialchars($slot['time']); ?>"
                 data-price="<?php echo $slot['price']; ?>"
                 data-ground="<?php echo $selected_ground_id; ?>"
                 data-date="<?php echo $selected_date; ?>"
                 <?php if (in_array($slot['type'], ['held','on_hold'])): ?>data-hold-remaining="<?php echo $slot['hold_remaining']; ?>"<?php endif; ?>
                 <?php if ($isClickable): ?>onclick="clickSlot(this)"<?php endif; ?>>

              <div class="flex items-center justify-between mb-1.5">
                <div class="flex items-center gap-1.5 <?php echo $tc['text']; ?>">
                  <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/></svg>
                  <span class="text-xs font-semibold"><?php echo $slot['time']; ?></span>
                </div>
                <span class="text-xs font-bold <?php echo $tc['price']; ?>"><?php echo number_format($slot['price']); ?> PKR</span>
              </div>

              <div class="flex items-center justify-between">
                <?php if ($isPeak): ?>
                  <span class="text-[10px] font-bold bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded">🔥 Peak</span>
                <?php else: ?>
                  <span class="text-[10px] font-bold bg-emerald-50 text-emerald-600 px-1.5 py-0.5 rounded">🟢 Normal</span>
                <?php endif; ?>
                <?php if ($slot['label']): ?>
                  <span class="text-[10px] font-semibold <?php echo $tc['text']; ?>"><?php echo htmlspecialchars($slot['label']); ?></span>
                <?php endif; ?>
              </div>

              <?php if (in_array($slot['type'], ['held', 'on_hold'])): ?>
              <div class="hold-timer-bar mt-2">
                <div class="hold-timer-fill" id="fill-<?php echo $slot['hour']; ?>" style="width:<?php echo min(100, round($slot['hold_remaining'] / 3)); ?>%"></div>
              </div>
              <div class="text-[10px] text-blue-600 font-semibold mt-1" id="hold-text-<?php echo $slot['hour']; ?>">
                <?php echo $slot['type'] === 'held' ? '🔵 Your hold – ' : '⏳ On hold – '; ?><?php echo ceil($slot['hold_remaining'] / 60); ?>m remaining
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<!-- ============================================================
     BOOKING MODAL
============================================================ -->
<div id="booking-modal-overlay">
  <div id="booking-modal">

    <!-- Modal Header -->
    <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 pt-6 pb-5 text-white">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-xs font-semibold opacity-80 mb-0.5" id="modal-ground-name">Ground Name</div>
          <h2 class="text-xl font-extrabold" id="modal-slot-time">10:00 AM – 11:00 AM</h2>
        </div>
        <button onclick="closeModal()" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-colors">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="flex items-center gap-4 mt-3 text-xs opacity-90">
        <span>📅 <span id="modal-date">--</span></span>
        <span>💰 <span id="modal-price" class="font-bold">--</span> PKR full price</span>
      </div>

      <!-- Hold countdown bar -->
      <div class="mt-3">
        <div class="flex items-center justify-between text-xs mb-1">
          <span class="opacity-80">Slot hold expires in</span>
          <span class="font-bold" id="modal-countdown">5:00</span>
        </div>
        <div class="h-1.5 bg-white/30 rounded-full overflow-hidden">
          <div class="h-full bg-white rounded-full transition-all duration-1000" id="modal-progress" style="width:100%"></div>
        </div>
      </div>

      <!-- Step dots -->
      <div class="flex items-center gap-2 mt-3">
        <div class="step-dot active" id="dot-1"></div>
        <div class="step-dot" id="dot-2"></div>
      </div>
    </div>

    <!-- Step 1: Choose booking type -->
    <div id="step-1" class="p-6">
      <p class="text-sm font-semibold text-slate-700 mb-4">How would you like to book this slot?</p>
      <div class="space-y-3">

        <!-- Direct Booking -->
        <div class="choice-card" onclick="selectBookingType('direct', this)">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center flex-shrink-0">
              <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="flex-1">
              <div class="font-bold text-slate-800 text-sm">Direct Booking</div>
              <div class="text-xs text-slate-500 mt-0.5">Reserve exclusively. Pay 50% advance now, remaining 50% at the venue.</div>
              <div class="mt-1.5 text-xs font-bold text-emerald-600" id="direct-price-label">Advance: -- PKR (50%)</div>
            </div>
          </div>
        </div>

        <!-- Open Challenge -->
        <div class="choice-card" onclick="selectBookingType('open_challenge', this)">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-violet-100 flex items-center justify-center flex-shrink-0">
              <svg class="h-5 w-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div class="flex-1">
              <div class="font-bold text-slate-800 text-sm">Open Challenge</div>
              <div class="text-xs text-slate-500 mt-0.5">Post an open match. Pay 25% now, opponent pays 25%, remaining 50% at venue.</div>
              <div class="mt-1.5 text-xs font-bold text-violet-600" id="open-price-label">Pay now: -- PKR (25%)</div>
            </div>
          </div>
        </div>

        <!-- Challenge a Specific Team -->
        <div class="choice-card" onclick="selectBookingType('team_challenge', this)">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-orange-100 flex items-center justify-center flex-shrink-0">
              <svg class="h-5 w-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div class="flex-1">
              <div class="font-bold text-slate-800 text-sm">Challenge a Specific Team</div>
              <div class="text-xs text-slate-500 mt-0.5">Search and invite a team. You each pay 25% advance now, remaining 50% at venue.</div>
              <div class="mt-1.5 text-xs font-bold text-orange-600" id="team-price-label">Your share: -- PKR (25%)</div>
            </div>
          </div>
        </div>
      </div>

      <button onclick="proceedToStep2()"
              class="mt-5 w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md hover:shadow-lg disabled:opacity-40 disabled:cursor-not-allowed"
              id="step1-next-btn" disabled>
        Continue →
      </button>
    </div>

    <!-- Step 2a: Direct Booking Confirm -->
    <div id="step-2-direct" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-4 font-medium">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back
      </button>
      <h3 class="text-base font-bold text-slate-800 mb-4">Confirm Direct Booking (50% Advance)</h3>
      <div class="bg-slate-50 rounded-xl p-4 mb-4 space-y-3 text-sm border border-slate-200">
        <div class="flex justify-between"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800" id="d-venue">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="d-date">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold text-slate-800" id="d-time">--</span></div>
        <div class="flex justify-between text-xs text-slate-500"><span>Slot Full Price</span><span id="d-full-price">-- PKR</span></div>
        <div class="border-t border-slate-200 pt-3 flex justify-between">
          <span class="font-bold text-slate-700">Advance Payment (50%)</span>
          <span class="font-extrabold text-emerald-600 text-base" id="d-price">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs font-semibold text-amber-700 bg-amber-50 rounded-lg p-2">
          <span>Pay at Venue (50% remaining):</span>
          <span id="d-venue-due">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400">Wallet Balance</span>
          <span class="font-semibold text-slate-600" id="d-balance">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400">Balance After Advance</span>
          <span class="font-semibold" id="d-after">-- PKR</span>
        </div>
      </div>
      <button onclick="submitBooking('direct')"
              class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md" id="direct-pay-btn">
        ✅ Pay 50% Advance from Wallet
      </button>
    </div>

    <!-- Step 2b: Open Challenge Confirm -->
    <div id="step-2-open" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-4 font-medium">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back
      </button>
      <h3 class="text-base font-bold text-slate-800 mb-1">Post Open Challenge (25% Advance)</h3>
      <p class="text-xs text-slate-500 mb-4">Your challenge will be visible to all players. When someone accepts with their 25% share, the match is confirmed.</p>
      <div class="bg-violet-50 rounded-xl p-4 mb-4 space-y-3 text-sm border border-violet-200">
        <div class="flex justify-between"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800" id="oc-venue">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="oc-date">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold text-slate-800" id="oc-time">--</span></div>
        <div class="flex justify-between text-xs text-slate-500"><span>Slot Full Price</span><span id="oc-full-price">-- PKR</span></div>
        <div class="border-t border-violet-200 pt-3 flex justify-between">
          <span class="font-bold text-slate-700">You Pay Now (25% Advance)</span>
          <span class="font-extrabold text-violet-700 text-base" id="oc-price">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-500">Opponent Pays (25%):</span>
          <span class="font-semibold text-violet-700" id="oc-opp-price">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs font-semibold text-amber-700 bg-amber-50 rounded-lg p-2">
          <span>Remaining 50% paid at venue:</span>
          <span id="oc-venue-due">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400">Wallet Balance</span>
          <span class="font-semibold text-slate-600" id="oc-balance">-- PKR</span>
        </div>
      </div>
      <button id="oc-pay-btn" onclick="submitBooking('open_challenge')"
              class="w-full bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md">
        ⚡ Pay 25% & Post Challenge
      </button>
    </div>

    <!-- Step 2c: Challenge Team – redirect info -->
    <div id="step-2-team" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-4 font-medium">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back
      </button>
      <h3 class="text-base font-bold text-slate-800 mb-1">Challenge a Specific Team (25% Advance)</h3>
      <p class="text-xs text-slate-500 mb-4">You'll be taken to the teams page with your slot pre-filled. Search a team, pay your 25% share, and the invite will be sent.</p>
      <div class="bg-orange-50 rounded-xl p-4 mb-4 space-y-3 text-sm border border-orange-200">
        <div class="flex justify-between"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800" id="tc-venue">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="tc-date">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold text-slate-800" id="tc-time">--</span></div>
        <div class="flex justify-between text-xs text-slate-500"><span>Slot Full Price</span><span id="tc-full-price">-- PKR</span></div>
        <div class="border-t border-orange-200 pt-3 flex justify-between">
          <span class="font-bold text-slate-700">Your Share (25% Advance)</span>
          <span class="font-extrabold text-orange-600 text-base" id="tc-price">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs font-semibold text-amber-700 bg-amber-50 rounded-lg p-2">
          <span>Remaining 50% paid at venue:</span>
          <span id="tc-venue-due">-- PKR</span>
        </div>
      </div>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-700 mb-4">
        ⚠️ Your 5-min slot hold will be released when you navigate away. The slot reservation will be locked once you pay your 25% advance on the next page.
      </div>
      <button onclick="goToChallengeTeam()"
              class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md">
        🏆 Select Team & Pay 25% →
      </button>
    </div>

  </div>
</div>

<script>
// ---- State ----
let currentGroundId     = <?php echo $selected_ground_id; ?>;
let currentDate         = '<?php echo $selected_date; ?>';
let currentWalletBalance = <?php echo $available_balance; ?>;
let modalData           = {};
let countdownInterval   = null;
let cardTickInterval    = null;
let livePollInterval    = null;
let holdSeconds         = 300;
let selectedType        = null;
let isModalOpen         = false;

// ---- Slot Color Theme Mapping ----
function getSlotColorClasses(type) {
  const map = {
    available:  { text: 'text-emerald-800', price: 'text-emerald-700' },
    booked:     { text: 'text-red-700',     price: 'text-red-600' },
    my_booking: { text: 'text-amber-700',   price: 'text-amber-600' },
    challenge:  { text: 'text-violet-700',  price: 'text-violet-600' },
    held:       { text: 'text-blue-700',    price: 'text-blue-600' },
    on_hold:    { text: 'text-slate-600',   price: 'text-slate-500' },
    passed:     { text: 'text-slate-400',   price: 'text-slate-400' },
  };
  return map[type] || map.available;
}

// ---- Render Single Slot Card HTML ----
function renderSlotCardHtml(slot, groundId, date) {
  const isPeak = (slot.slot_type || 'Normal') === 'Peak';
  const isClickable = (slot.type === 'available' || slot.type === 'held');
  const tc = getSlotColorClasses(slot.type);
  const isHeldOrOnHold = (slot.type === 'held' || slot.type === 'on_hold');

  let holdHtml = '';
  if (isHeldOrOnHold) {
    const isOwn  = (slot.type === 'held');
    const prefix = isOwn ? '🔵 Your hold – ' : '⏳ On hold – ';
    const rem    = Math.max(0, parseInt(slot.hold_remaining || 0));
    const m      = Math.floor(rem / 60);
    const s      = String(rem % 60).padStart(2, '0');
    const widthPct = Math.min(100, Math.max(0, Math.round((rem / 300) * 100)));
    holdHtml = `
      <div class="hold-timer-bar mt-2">
        <div class="hold-timer-fill" id="fill-${slot.hour}" style="width:${widthPct}%"></div>
      </div>
      <div class="text-[10px] text-blue-600 font-semibold mt-1" id="hold-text-${slot.hour}">
        ${prefix}${m}:${s} remaining
      </div>
    `;
  }

  return `
    <div class="slot-card slot-${slot.type}"
         id="slot-card-${slot.hour}"
         data-hour="${slot.hour}"
         data-time="${escHtml(slot.time)}"
         data-price="${slot.price}"
         data-ground="${groundId}"
         data-date="${date}"
         ${isHeldOrOnHold ? `data-hold-remaining="${slot.hold_remaining}"` : ''}
         ${isClickable ? 'onclick="clickSlot(this)"' : ''}>

      <div class="flex items-center justify-between mb-1.5">
        <div class="flex items-center gap-1.5 ${tc.text}">
          <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/></svg>
          <span class="text-xs font-semibold">${escHtml(slot.time)}</span>
        </div>
        <span class="text-xs font-bold ${tc.price}">${formatNum(slot.price)} PKR</span>
      </div>

      <div class="flex items-center justify-between">
        ${isPeak ? '<span class="text-[10px] font-bold bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded">🔥 Peak</span>' : '<span class="text-[10px] font-bold bg-emerald-50 text-emerald-600 px-1.5 py-0.5 rounded">🟢 Normal</span>'}
        ${slot.label ? `<span class="text-[10px] font-semibold ${tc.text}">${escHtml(slot.label)}</span>` : ''}
      </div>

      ${holdHtml}
    </div>
  `;
}

// ---- Render Entire Slots Grid in Real Time ----
function renderSlotsGrid(slots, groundId, date) {
  const container = document.getElementById('slots-container');
  if (!container) return;

  if (!slots || slots.length === 0) {
    container.innerHTML = `
      <div class="text-center py-12" id="slots-empty-state">
        <div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
          <svg class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-sm font-semibold text-slate-500">No open slots</p>
        <p class="text-xs text-slate-400 mt-1">There are no slots available for this date.</p>
      </div>`;
    return;
  }

  // Build grid HTML
  const gridHtml = '<div class="grid grid-cols-2 gap-3" id="slots-grid">' +
    slots.map(s => renderSlotCardHtml(s, groundId, date)).join('') +
    '</div>';

  container.innerHTML = gridHtml;

  // Restore selected state if modal is open
  if (isModalOpen && modalData && modalData.hour !== undefined) {
    const card = document.getElementById('slot-card-' + modalData.hour);
    if (card) card.classList.add('slot-selected');
  }
}

// ---- Real-time Fetch Slots (Background & Triggered) ----
function fetchSlotsLive(groundId, date, showLoading = false) {
  groundId = groundId || currentGroundId;
  date     = date || currentDate;

  const container = document.getElementById('slots-container');
  if (showLoading && container) {
    container.style.opacity = '0.5';
  }

  fetch(`get_slots.php?ground_id=${groundId}&slot_date=${date}`)
    .then(r => r.json())
    .then(res => {
      if (showLoading && container) container.style.opacity = '1';

      if (res.success) {
        if (res.available_balance !== undefined) {
          currentWalletBalance = parseFloat(res.available_balance);
          updateWalletNavbar(currentWalletBalance);
        }
        renderSlotsGrid(res.slots, groundId, date);
      }
    })
    .catch(() => {
      if (showLoading && container) container.style.opacity = '1';
    });
}

// ---- Update Wallet Balance in Navbar & Modal Real-Time ----
function updateWalletNavbar(bal) {
  const el = document.getElementById('navbar-wallet-amount');
  if (el) el.textContent = formatNum(bal);
}

// ---- Slot Click: Optimistic Local State & AJAX Hold ----
function clickSlot(el) {
  document.querySelectorAll('.slot-card').forEach(s => { if (s !== el) s.classList.remove('slot-selected'); });
  el.classList.add('slot-selected');

  const hour   = parseInt(el.dataset.hour);
  const time   = el.dataset.time;
  const price  = parseFloat(el.dataset.price);
  const ground = parseInt(el.dataset.ground);
  const date   = el.dataset.date;
  modalData    = { hour, time, price, ground, date };

  // Place / refresh hold via AJAX
  fetch('hold_slot.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `ground_id=${ground}&slot_date=${date}&slot_hour=${hour}`
  })
  .then(r => r.json())
  .then(res => {
    if (!res.success) {
      showToast('❌ ' + (res.message || 'Slot is currently on hold.'), 'error');
      el.classList.remove('slot-selected');
      // Instantly sync grid in real time without refreshing!
      fetchSlotsLive(ground, date, false);
      return;
    }

    holdSeconds = res.remaining || 300;

    // Instantly update card DOM to held state
    updateSlotCardHoldState(hour, holdSeconds, true);

    openModal();
  })
  .catch(() => {
    showToast('❌ Network error. Please try again.', 'error');
    el.classList.remove('slot-selected');
  });
}

// ---- Update Single Slot Card Hold Visuals Directly ----
function updateSlotCardHoldState(hour, seconds, isOwn) {
  const card = document.getElementById('slot-card-' + hour);
  if (!card) return;

  card.className = isOwn ? 'slot-card slot-held slot-selected' : 'slot-card slot-on_hold';
  card.dataset.holdRemaining = seconds;

  let timerBar = card.querySelector('.hold-timer-bar');
  let holdText = document.getElementById('hold-text-' + hour);

  const prefix = isOwn ? '🔵 Your hold – ' : '⏳ On hold – ';
  const m = Math.floor(seconds / 60);
  const s = String(seconds % 60).padStart(2, '0');
  const widthPct = Math.min(100, Math.max(0, Math.round((seconds / 300) * 100)));

  if (!timerBar) {
    const barWrap = document.createElement('div');
    barWrap.className = 'hold-timer-bar mt-2';
    barWrap.innerHTML = `<div class="hold-timer-fill" id="fill-${hour}" style="width:${widthPct}%"></div>`;
    card.appendChild(barWrap);
  } else {
    const fill = document.getElementById('fill-' + hour);
    if (fill) fill.style.width = widthPct + '%';
  }

  if (!holdText) {
    holdText = document.createElement('div');
    holdText.id = 'hold-text-' + hour;
    holdText.className = 'text-[10px] text-blue-600 font-semibold mt-1';
    card.appendChild(holdText);
  }
  holdText.textContent = `${prefix}${m}:${s} remaining`;
}

// ---- Modal Open / Close ----
function openModal() {
  isModalOpen = true;
  selectedType = null;
  document.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('step1-next-btn').disabled = true;

  const groundEl   = document.getElementById('ground-select');
  const selectedOpt = groundEl ? groundEl.options[groundEl.selectedIndex] : null;
  const groundName = selectedOpt ? (selectedOpt.dataset.title || selectedOpt.text.split('—')[0].trim()) : 'Venue';

  document.getElementById('modal-ground-name').textContent = groundName;
  document.getElementById('modal-slot-time').textContent   = modalData.time;
  document.getElementById('modal-date').textContent        = modalData.date;
  document.getElementById('modal-price').textContent       = formatNum(modalData.price);

  const directHalf = Math.round(modalData.price * 0.5);
  const quarter    = Math.round(modalData.price * 0.25);
  document.getElementById('direct-price-label').textContent = 'Advance: ' + formatNum(directHalf) + ' PKR (50%)';
  document.getElementById('open-price-label').textContent   = 'Pay now: ' + formatNum(quarter) + ' PKR (25%)';
  document.getElementById('team-price-label').textContent   = 'Your share: ' + formatNum(quarter) + ' PKR (25%)';

  showStep(1);
  document.getElementById('booking-modal-overlay').classList.add('open');
  startCountdown(holdSeconds);
}

function closeModal() {
  isModalOpen = false;
  document.getElementById('booking-modal-overlay').classList.remove('open');
  if (countdownInterval) clearInterval(countdownInterval);
  document.querySelectorAll('.slot-card.slot-selected').forEach(s => s.classList.remove('slot-selected'));

  // Sync slots without page reload
  fetchSlotsLive(currentGroundId, currentDate, false);
}

// ---- Modal Countdown ----
function startCountdown(seconds) {
  if (countdownInterval) clearInterval(countdownInterval);
  let remaining = seconds;
  const total   = seconds;
  updateCountdownUI(remaining, total);

  countdownInterval = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
      clearInterval(countdownInterval);
      showToast('⏰ Hold expired. Slot released.', 'info');
      closeModal();
      return;
    }
    updateCountdownUI(remaining, total);
  }, 1000);
}

function updateCountdownUI(remaining, total) {
  const m   = Math.floor(remaining / 60);
  const s   = String(remaining % 60).padStart(2, '0');
  const el  = document.getElementById('modal-countdown');
  if (el) el.textContent = m + ':' + s;
  const pct = Math.max(0, Math.min(100, (remaining / total) * 100));
  const bar = document.getElementById('modal-progress');
  if (!bar) return;
  bar.style.width      = pct + '%';
  bar.style.background = pct < 30 ? '#fca5a5' : (pct < 60 ? '#fde68a' : 'white');
}

// ---- Live Card Countdown Timer (Ticks Every Second) ----
function initCardCountdowns() {
  if (cardTickInterval) clearInterval(cardTickInterval);
  cardTickInterval = setInterval(() => {
    const heldCards = document.querySelectorAll('.slot-card[data-hold-remaining]');
    let hasExpired = false;

    heldCards.forEach(card => {
      let rem = parseInt(card.dataset.holdRemaining || '0');
      if (rem <= 0) {
        hasExpired = true;
        return;
      }
      rem--;
      card.dataset.holdRemaining = rem;
      const hour = card.dataset.hour;
      const fill = document.getElementById('fill-' + hour);
      const text = document.getElementById('hold-text-' + hour);
      if (fill) fill.style.width = Math.max(0, Math.min(100, Math.round((rem / 300) * 100))) + '%';
      if (text) {
        if (rem <= 0) {
          text.textContent = 'Hold expired';
          hasExpired = true;
        } else {
          const isOwn  = card.classList.contains('slot-held');
          const prefix = isOwn ? '🔵 Your hold – ' : '⏳ On hold – ';
          const m      = Math.floor(rem / 60);
          const s      = String(rem % 60).padStart(2, '0');
          text.textContent = prefix + m + ':' + s + ' remaining';
        }
      }
    });

    if (hasExpired && !isModalOpen) {
      // Revert card state in real time via live fetch — no full reload!
      fetchSlotsLive(currentGroundId, currentDate, false);
    }
  }, 1000);
}

// ---- Smooth Venue Change without Page Reload ----
function onGroundChanged(newGroundId) {
  currentGroundId = parseInt(newGroundId);
  const groundEl   = document.getElementById('ground-select');
  const opt        = groundEl ? groundEl.options[groundEl.selectedIndex] : null;

  if (opt) {
    const sportBadge = document.getElementById('ground-sport-badge');
    if (sportBadge && opt.dataset.sport) sportBadge.textContent = opt.dataset.sport;
  }

  // Update URL seamlessly
  history.pushState(null, '', `book_slot.php?ground=${currentGroundId}&date=${currentDate}`);

  // Fetch slots live
  fetchSlotsLive(currentGroundId, currentDate, true);
}

// ---- Smooth Date Change without Page Reload ----
function onDateChanged(newDate) {
  currentDate = newDate;

  const dateLabel = document.getElementById('slots-date-label');
  if (dateLabel) {
    const d = new Date(newDate + 'T00:00:00');
    dateLabel.textContent = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  }

  // Update URL seamlessly
  history.pushState(null, '', `book_slot.php?ground=${currentGroundId}&date=${currentDate}`);

  // Fetch slots live
  fetchSlotsLive(currentGroundId, currentDate, true);
}

// ---- Step Navigation in Booking Modal ----
function showStep(n) {
  ['step-1','step-2-direct','step-2-open','step-2-team'].forEach(id => document.getElementById(id).classList.add('hidden'));
  ['dot-1','dot-2'].forEach(id => document.getElementById(id).classList.remove('active'));

  if (n === 1) {
    document.getElementById('step-1').classList.remove('hidden');
    document.getElementById('dot-1').classList.add('active');
    return;
  }

  document.getElementById('dot-1').classList.add('active');
  document.getElementById('dot-2').classList.add('active');

  const balance    = currentWalletBalance;
  const directHalf = Math.round(modalData.price * 0.5);
  const quarter    = Math.round(modalData.price * 0.25);
  const groundEl   = document.getElementById('ground-select');
  const opt        = groundEl ? groundEl.options[groundEl.selectedIndex] : null;
  const groundName = opt ? (opt.dataset.title || opt.text.split('—')[0].trim()) : 'Venue';

  if (selectedType === 'direct') {
    document.getElementById('step-2-direct').classList.remove('hidden');
    document.getElementById('d-venue').textContent      = groundName;
    document.getElementById('d-date').textContent       = modalData.date;
    document.getElementById('d-time').textContent       = modalData.time;
    document.getElementById('d-full-price').textContent = formatNum(modalData.price) + ' PKR';
    document.getElementById('d-price').textContent      = formatNum(directHalf) + ' PKR';
    document.getElementById('d-venue-due').textContent  = formatNum(modalData.price - directHalf) + ' PKR';
    document.getElementById('d-balance').textContent    = formatNum(balance) + ' PKR';
    const after   = balance - directHalf;
    const afterEl = document.getElementById('d-after');
    afterEl.textContent = formatNum(after) + ' PKR';
    afterEl.className   = 'font-semibold ' + (after >= 0 ? 'text-emerald-600' : 'text-red-600');
    const payBtn = document.getElementById('direct-pay-btn');
    if (balance < directHalf) {
      payBtn.disabled    = true;
      payBtn.textContent = '❌ Insufficient Balance – Top Up Wallet';
      payBtn.className  += ' opacity-50 cursor-not-allowed';
    } else {
      payBtn.disabled    = false;
      payBtn.textContent = '✅ Pay 50% Advance from Wallet';
      payBtn.className   = payBtn.className.replace('opacity-50 cursor-not-allowed','');
    }
  } else if (selectedType === 'open_challenge') {
    document.getElementById('step-2-open').classList.remove('hidden');
    document.getElementById('oc-venue').textContent      = groundName;
    document.getElementById('oc-date').textContent       = modalData.date;
    document.getElementById('oc-time').textContent       = modalData.time;
    document.getElementById('oc-full-price').textContent = formatNum(modalData.price) + ' PKR';
    document.getElementById('oc-price').textContent      = formatNum(quarter) + ' PKR';
    document.getElementById('oc-opp-price').textContent  = formatNum(quarter) + ' PKR';
    document.getElementById('oc-venue-due').textContent   = formatNum(modalData.price - (quarter * 2)) + ' PKR';
    document.getElementById('oc-balance').textContent    = formatNum(balance) + ' PKR';
    const ocBtn = document.getElementById('oc-pay-btn');
    if (balance < quarter) {
      ocBtn.disabled    = true;
      ocBtn.textContent = '❌ Insufficient Balance – Top Up Wallet';
      ocBtn.className  += ' opacity-50 cursor-not-allowed';
    } else {
      ocBtn.disabled    = false;
      ocBtn.textContent = '⚡ Pay 25% & Post Challenge';
      ocBtn.className   = ocBtn.className.replace('opacity-50 cursor-not-allowed','');
    }
  } else {
    document.getElementById('step-2-team').classList.remove('hidden');
    document.getElementById('tc-venue').textContent      = groundName;
    document.getElementById('tc-date').textContent       = modalData.date;
    document.getElementById('tc-time').textContent       = modalData.time;
    document.getElementById('tc-full-price').textContent = formatNum(modalData.price) + ' PKR';
    document.getElementById('tc-price').textContent      = formatNum(quarter) + ' PKR';
    document.getElementById('tc-venue-due').textContent  = formatNum(modalData.price - (quarter * 2)) + ' PKR';
  }
}

function selectBookingType(type, el) {
  selectedType = type;
  document.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('step1-next-btn').disabled = false;
}
function proceedToStep2() { if (!selectedType) return; showStep(2); }
function backToStep1()    { showStep(1); }

// ---- Submit Booking: Real-Time Instant State Update without Page Reload ----
function submitBooking(type) {
  const btnId = type === 'direct' ? 'direct-pay-btn' : 'oc-pay-btn';
  const btn   = document.getElementById(btnId);
  if (btn) { btn.disabled = true; btn.textContent = 'Processing payment…'; }

  fetch('process_booking.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({
      ground_id:    modalData.ground,
      slot_date:    modalData.date,
      slot_hour:    modalData.hour,
      booking_type: type
    })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      closeModal();
      showToast(res.message, 'success');

      // Deduct wallet balance locally in real time
      if (res.amount_paid !== undefined) {
        currentWalletBalance = Math.max(0, currentWalletBalance - parseFloat(res.amount_paid));
        updateWalletNavbar(currentWalletBalance);
      }

      // Fetch fresh slots live to immediately show confirmed / challenge state
      fetchSlotsLive(modalData.ground, modalData.date, false);
    } else {
      showToast('❌ ' + res.message, 'error');
      if (btn) {
        btn.disabled    = false;
        btn.textContent = type === 'direct' ? '✅ Pay 50% Advance from Wallet' : '⚡ Pay 25% & Post Challenge';
      }
    }
  })
  .catch(() => {
    showToast('❌ Network error.', 'error');
    if (btn) btn.disabled = false;
  });
}

// ---- Go to Challenge Team Page ----
function goToChallengeTeam() {
  const quarter = Math.round(modalData.price * 0.25);
  window.location.href = 'challenge_team.php?ground_id=' + modalData.ground + '&date=' + modalData.date + '&hour=' + modalData.hour + '&price=' + modalData.price + '&quarter=' + quarter + '&half=' + quarter;
}

// ---- Toast Notification ----
function showToast(message, type) {
  type = type || 'info';
  const toast     = document.getElementById('toast');
  toast.textContent = message;
  toast.className   = 'show ' + type;
  setTimeout(() => { toast.className = toast.className.replace('show', '').trim(); }, 4500);
}

function formatNum(n) { return Math.round(n).toLocaleString('en-PK'); }
function escHtml(str) {
  const div = document.createElement('div');
  div.textContent = str || '';
  return div.innerHTML;
}

// ---- Event Listeners ----
document.getElementById('booking-modal-overlay').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

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

// ---- Background Polling (Live Sync Every 4s) ----
(function startRealTimeSync() {
  initCardCountdowns();
  if (livePollInterval) clearInterval(livePollInterval);
  livePollInterval = setInterval(() => {
    // Only poll when user is viewing the page and not typing
    if (!document.hidden && !isModalOpen) {
      fetchSlotsLive(currentGroundId, currentDate, false);
    }
  }, 4000);
})();
</script>
</body>
</html>
