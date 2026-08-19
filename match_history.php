<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Wallet balance
try {
    $stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $available_balance = floatval($wallet['available_balance'] ?? 0);
} catch (Exception $e) { $available_balance = 0.00; }

// Fetch real bookings from DB
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.slot_date, b.slot_hour, b.price, b.amount_paid,
               b.booking_type, b.status, b.challenger_team_name, b.opponent_id,
               b.created_at,
               g.title AS ground_title, g.sport_type, g.address
        FROM bookings b
        JOIN grounds g ON g.id = b.ground_id
        WHERE b.booked_by = ?
        ORDER BY b.slot_date DESC, b.slot_hour DESC
    ");
    $stmt->execute([$user_id]);
    $my_bookings = $stmt->fetchAll();
} catch (Exception $e) { $my_bookings = []; }

// Fetch challenges where this user is the OPPONENT (accepted challenges OR incoming team challenges)
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.slot_date, b.slot_hour, b.price, b.amount_paid,
               b.booking_type, b.status, b.challenger_team_name, b.challenged_user_id,
               b.created_at,
               g.title AS ground_title, g.sport_type, g.address,
               u.name AS challenger_name
        FROM bookings b
        JOIN grounds g ON g.id = b.ground_id
        JOIN users u ON u.id = b.booked_by
        WHERE (
            b.opponent_id = ?
            OR (b.status = 'challenge_pending' AND b.challenged_user_id = ?)
        )
        ORDER BY b.slot_date DESC, b.slot_hour DESC
    ");
    $stmt->execute([$user_id, $user_id]);
    $accepted_challenges = $stmt->fetchAll();
} catch (Exception $e) { $accepted_challenges = []; }

// Merge both lists for display
$all_bookings = [];
foreach ($my_bookings as $b) {
    $b['role'] = 'challenger';
    $all_bookings[] = $b;
}
foreach ($accepted_challenges as $b) {
    $b['role'] = 'opponent';
    $all_bookings[] = $b;
}

// Sort by date desc
usort($all_bookings, fn($a,$b) => strcmp($b['slot_date'].$b['slot_hour'], $a['slot_date'].$a['slot_hour']));

// Stats
$total      = count($all_bookings);
$upcoming   = count(array_filter($all_bookings, function($b) {
    $st = strtotime($b['slot_date'] . ' ' . sprintf('%02d:00:00', intval($b['slot_hour'])));
    return $st > time() && in_array($b['status'], ['confirmed','challenge_open','challenge_pending','challenge_accepted']);
}));
$confirmed  = count(array_filter($all_bookings, fn($b) => $b['status'] === 'confirmed'));
$challenges = count(array_filter($all_bookings, fn($b) => in_array($b['status'], ['challenge_open','challenge_pending','challenge_accepted'])));
$total_spent = array_sum(array_column($all_bookings, 'amount_paid'));

// Format hour → time label
function formatHourLabel(int $h): string {
    $suffix   = $h < 12 ? 'AM' : 'PM';
    $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
    $nextH    = $h + 1;
    $nextDisp = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
    $nextSuf  = $nextH < 12 ? 'AM' : 'PM';
    return sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuf);
}

$statusConfig = [
    'confirmed'          => ['badge' => 'bg-emerald-100 text-emerald-700', 'label' => 'Confirmed',         'icon' => '✅'],
    'challenge_open'     => ['badge' => 'bg-violet-100 text-violet-700',   'label' => 'Open Challenge',    'icon' => '⚡'],
    'challenge_pending'  => ['badge' => 'bg-orange-100 text-orange-700',   'label' => 'Pending Accept',    'icon' => '🤝'],
    'challenge_accepted' => ['badge' => 'bg-blue-100 text-blue-700',       'label' => 'Match Set ✓',      'icon' => '🏆'],
    'cancelled'          => ['badge' => 'bg-red-100 text-red-700',         'label' => 'Cancelled',         'icon' => '❌'],
];

$typeConfig = [
    'direct'          => ['badge' => 'bg-slate-100 text-slate-600',   'label' => 'Direct'],
    'open_challenge'  => ['badge' => 'bg-violet-100 text-violet-600', 'label' => 'Open Challenge'],
    'team_challenge'  => ['badge' => 'bg-orange-100 text-orange-600', 'label' => 'Team Challenge'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Match History – ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body { font-family: 'Inter', sans-serif; background: #f8fafc; }
.booking-row { transition: background 0.15s; }
.booking-row:hover { background: #f8fafc; }

/* Upcoming pulse dot */
.pulse-dot { animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* Toast */
#mh-toast {
    position:fixed;top:20px;right:20px;z-index:9999;
    padding:12px 20px;border-radius:12px;font-size:14px;font-weight:500;
    box-shadow:0 8px 30px rgba(0,0,0,.3);transform:translateX(120%);
    transition:transform .3s cubic-bezier(.34,1.56,.64,1);
    max-width:360px;color:white;
}
#mh-toast.show { transform:translateX(0); }

/* Cancel modal */
#cancel-overlay {
    position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
    z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;
    opacity:0;pointer-events:none;transition:opacity .2s ease;
}
#cancel-overlay.open { opacity:1;pointer-events:all; }
#cancel-modal {
    background:white;border-radius:20px;box-shadow:0 25px 80px rgba(0,0,0,.3);
    max-width:440px;width:100%;transform:scale(0.92) translateY(20px);
    transition:transform .25s cubic-bezier(.34,1.56,.64,1),opacity .2s ease;
    opacity:0;overflow:hidden;
}
#cancel-overlay.open #cancel-modal { transform:scale(1) translateY(0);opacity:1; }
</style>
    <?php
    $page_description = 'View your complete match history on ArenaReserve. Track your wins, bookings, and ground performance over time.';
    include 'logo_head.php';
    ?>
</head>
<body>
<div id="mh-toast"></div>

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
    <div class="flex-shrink-0 flex items-center gap-1 sm:gap-2">
      <a href="wallet.php" class="hidden sm:flex items-center bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full text-xs font-semibold border border-emerald-200">
        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span><span class="wallet-balance-display"><?php echo number_format($available_balance, 0); ?> PKR</span>
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
                <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
              <?php endif; ?>
            </div>
            <div class="hidden md:block text-left">
              <div class="text-xs font-semibold text-slate-800 flex items-center gap-1">
                <?php echo htmlspecialchars($_SESSION['name']); ?>
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

<div class="flex max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">
  <!-- Sidebar -->
  <aside class="hidden lg:block w-64 flex-shrink-0">
    <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
      <a href="explore.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>Explore Grounds</a>
      <a href="book_slot.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Book Slot</a>
      <a href="match_history.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg"><svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Match History</a>
      <a href="challenge_team.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Challenge Team</a>
      <a href="leaderboard.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Leaderboard</a>
      <div class="border-t border-slate-100 mt-2 pt-2">
        <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>My Wallet</a>
      </div>
    </nav>
  </aside>

  <!-- Main -->
  <main class="flex-1 min-w-0">
    <!-- Header row -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Match History</h1>
        <p class="text-sm text-slate-500 mt-1">All your bookings, challenges and matches</p>
      </div>
      <a href="book_slot.php" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2 rounded-xl shadow transition-all">+ Book New Slot</a>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-slate-800"><?php echo $total; ?></div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">Total Bookings</div>
      </div>
      <div class="bg-white border border-blue-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-blue-600 flex items-center justify-center gap-1">
          <span class="w-2 h-2 rounded-full bg-blue-500 pulse-dot inline-block"></span><?php echo $upcoming; ?>
        </div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">Upcoming</div>
      </div>
      <div class="bg-white border border-violet-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-violet-600"><?php echo $challenges; ?></div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">Challenges</div>
      </div>
      <div class="bg-white border border-emerald-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-lg font-extrabold text-emerald-600"><?php echo number_format($total_spent, 0); ?></div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">PKR Spent</div>
      </div>
    </div>

    <!-- Bookings List -->
    <?php if (empty($all_bookings)): ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-16 text-center">
      <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      </div>
      <h3 class="text-lg font-semibold text-slate-700 mb-1">No bookings yet</h3>
      <p class="text-sm text-slate-400 mb-5">Head over to Book Slot to make your first reservation.</p>
      <a href="book_slot.php" class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5 rounded-xl shadow transition-all">Book a Slot →</a>
    </div>
    <?php else: ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-800">All Bookings</h2>
        <div class="flex items-center gap-2 text-xs text-slate-400 font-medium">
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>Confirmed</span>
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-violet-400 inline-block"></span>Challenge</span>
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span>Pending</span>
        </div>
      </div>
      <div class="divide-y divide-slate-100">
        <?php foreach ($all_bookings as $bk):
          $sc = $statusConfig[$bk['status']] ?? ['badge'=>'bg-slate-100 text-slate-600','label'=>ucfirst($bk['status']),'icon'=>'📋'];
          $tc = $typeConfig[$bk['booking_type']] ?? ['badge'=>'bg-slate-100 text-slate-600','label'=>$bk['booking_type']];
          $timeLabel = formatHourLabel(intval($bk['slot_hour']));
          $slot_start_time = strtotime($bk['slot_date'] . ' ' . sprintf('%02d:00:00', intval($bk['slot_hour'])));
          $isUpcoming = ($slot_start_time > time()) && ($bk['status'] !== 'cancelled');
          $isPast     = !$isUpcoming;
          $sportIcon  = ['Football'=>'⚽','Cricket'=>'🏏','Basketball'=>'🏀','Badminton'=>'🏸','Futsal'=>'⚽'][$bk['sport_type']] ?? '🏟️';
        ?>
        <div class="booking-row p-5 flex flex-col sm:flex-row sm:items-center gap-4" data-booking-id="<?php echo $bk['id']; ?>">
          <!-- Left: sport icon + info -->
          <div class="flex gap-4 items-start flex-1">
            <div class="w-11 h-11 rounded-xl bg-emerald-50 flex items-center justify-center text-xl flex-shrink-0 border border-emerald-100">
              <?php echo $sportIcon; ?>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars($bk['ground_title']); ?></span>
                <?php if ($isUpcoming): ?>
                  <span class="flex items-center gap-1 text-[10px] font-bold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 pulse-dot inline-block"></span>Upcoming
                  </span>
                <?php endif; ?>
              </div>
              <div class="text-xs text-slate-500 mt-0.5">
                📅 <?php echo date('D, d M Y', strtotime($bk['slot_date'])); ?>
                &nbsp;⏰ <?php echo $timeLabel; ?>
              </div>
              <?php if ($bk['role'] === 'opponent' && $bk['status'] === 'challenge_pending'): ?>
              <div class="text-xs text-orange-600 font-medium mt-0.5">
                ⚡ Team Challenge from <span class="font-bold"><?php echo htmlspecialchars($bk['challenger_name'] ?? 'Unknown'); ?></span> — awaiting your acceptance
              </div>
              <?php elseif ($bk['role'] === 'opponent'): ?>
              <div class="text-xs text-blue-600 font-medium mt-0.5">
                🏆 Accepted challenge from <span class="font-bold"><?php echo htmlspecialchars($bk['challenger_name'] ?? 'Unknown'); ?></span>
              </div>
              <?php elseif ($bk['challenger_team_name']): ?>
              <div class="text-xs text-orange-600 font-medium mt-0.5">
                🤝 Challenged: <span class="font-bold"><?php echo htmlspecialchars($bk['challenger_team_name']); ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Right: badges + cost + cancel -->
          <div class="flex items-center gap-3 sm:flex-col sm:items-end sm:gap-2 flex-shrink-0">
            <div class="flex items-center gap-2">
              <span class="status-badge text-xs font-bold px-2 py-1 rounded-full <?php echo $sc['badge']; ?>">
                <?php echo $sc['icon']; ?> <?php echo $sc['label']; ?>
              </span>
              <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded <?php echo $tc['badge']; ?>">
                <?php echo $tc['label']; ?>
              </span>
            </div>
            <div class="text-right">
              <div class="text-xs font-extrabold text-slate-800"><?php echo number_format($bk['amount_paid'], 0); ?> PKR</div>
              <?php if ($bk['price'] != $bk['amount_paid']): ?>
              <div class="text-[10px] text-slate-400">of <?php echo number_format($bk['price'], 0); ?> total</div>
              <?php endif; ?>
            </div>
            <?php
              // Show cancel button only for challenger's own future cancellable bookings
              $cancellable_statuses = ['confirmed', 'challenge_open', 'challenge_pending'];
              $can_cancel = ($bk['role'] === 'challenger')
                         && in_array(strtolower(trim($bk['status'])), $cancellable_statuses)
                         && $isUpcoming;
            ?>
            <?php if ($can_cancel): ?>
            <button
              onclick="openCancelModal(<?php echo $bk['id']; ?>, '<?php echo htmlspecialchars($bk['ground_title'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo date('D, d M Y', strtotime($bk['slot_date'])); ?>', '<?php echo htmlspecialchars(formatHourLabel(intval($bk['slot_hour'])), ENT_QUOTES, 'UTF-8'); ?>', <?php echo floatval($bk['amount_paid']); ?>, '<?php echo htmlspecialchars($bk['status'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $bk['slot_date']; ?>', <?php echo intval($bk['slot_hour']); ?>)"
              class="text-[11px] font-semibold text-red-600 border border-red-200 bg-red-50 hover:bg-red-100 px-2.5 py-1 rounded-lg transition-all"
              id="cancel-btn-<?php echo $bk['id']; ?>">
              ✕ Cancel
            </button>
            <?php endif; ?>
            <?php
              // Show Accept button for incoming team challenges
              $can_accept_team = ($bk['role'] === 'opponent')
                              && ($bk['status'] === 'challenge_pending')
                              && $isUpcoming;
            ?>
            <?php if ($can_accept_team): ?>
            <button
              onclick="acceptChallenge(<?php echo $bk['id']; ?>, this)"
              class="text-[11px] font-bold text-white bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 px-3 py-1.5 rounded-lg transition-all shadow-sm"
              id="accept-btn-<?php echo $bk['id']; ?>">
              ⚡ Accept Challenge
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </main>
</div>

<!-- ============================================================
     CANCELLATION CONFIRMATION MODAL
============================================================ -->
<div id="cancel-overlay">
  <div id="cancel-modal">
    <!-- Modal header -->
    <div class="bg-gradient-to-r from-red-500 to-rose-600 px-6 pt-6 pb-5 text-white">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-xs font-semibold opacity-80 mb-0.5">Cancel Booking</div>
          <h2 class="text-xl font-extrabold" id="cm-ground">Ground Name</h2>
        </div>
        <button onclick="closeCancelModal()" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-colors">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="flex items-center gap-4 mt-2 text-xs opacity-90">
        <span>📅 <span id="cm-date">--</span></span>
        <span>⏰ <span id="cm-time">--</span></span>
      </div>
    </div>

    <!-- Modal body -->
    <div class="p-6">
      <!-- Refund info box -->
      <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5">
        <div class="text-sm font-bold text-red-700 mb-2">💸 Refund Details</div>
        <div class="flex justify-between text-sm mb-1.5">
          <span class="text-slate-500">Amount Paid</span>
          <span class="font-bold text-slate-800" id="cm-paid">-- PKR</span>
        </div>
        <div class="flex justify-between text-sm mb-1">
          <span class="text-slate-500">Refund Amount</span>
          <span class="font-extrabold text-emerald-600" id="cm-refund">-- PKR</span>
        </div>
        <div class="text-[11px] text-slate-500 mt-2 border-t border-red-100 pt-2" id="cm-policy-note"></div>
      </div>

      <!-- Warning -->
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-700 mb-5" id="cm-warning">
        ⚠️ This action cannot be undone.
      </div>

      <!-- Buttons -->
      <div class="flex gap-3">
        <button onclick="closeCancelModal()" class="flex-1 border border-slate-200 text-slate-600 font-semibold py-3 rounded-xl text-sm hover:bg-slate-50 transition-all">
          Keep Booking
        </button>
        <button onclick="confirmCancel()" id="confirm-cancel-btn"
                class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md">
          Yes, Cancel
        </button>
      </div>
    </div>
  </div>
</div>

<script>
let cancelData = {};

function openCancelModal(bookingId, ground, date, time, amountPaid, status, slotDate, slotHour) {
  cancelData = { bookingId, amountPaid, status, slotDate, slotHour };

  document.getElementById('cm-ground').textContent = ground;
  document.getElementById('cm-date').textContent   = date;
  document.getElementById('cm-time').textContent   = time;
  document.getElementById('cm-paid').textContent   = formatNum(amountPaid) + ' PKR';

  // Calculate expected refund client-side for display
  let refund = 0;
  let policyNote = '';
  let warning = '⚠️ This action cannot be undone.';

  if (status === 'challenge_open' || status === 'challenge_pending') {
    refund = amountPaid;
    policyNote = '✅ Full refund — no opponent has committed yet.';
  } else if (status === 'confirmed') {
    // Approximate: compare slot datetime vs now
    const slotMs = new Date(slotDate + 'T' + String(slotHour).padStart(2,'0') + ':00:00').getTime();
    const nowMs  = Date.now();
    const hoursUntil = (slotMs - nowMs) / 3600000;
    if (hoursUntil > 24) {
      refund = amountPaid;
      policyNote = '✅ Full refund — more than 24 hours before slot.';
    } else {
      refund = Math.round(amountPaid * 0.5);
      policyNote = '⚠️ 50% refund only — cancelling within 24 hours of slot time.';
      warning = '⚠️ You will forfeit 50% of your payment as a late cancellation fee.';
    }
  }

  document.getElementById('cm-refund').textContent      = formatNum(refund) + ' PKR';
  document.getElementById('cm-policy-note').textContent  = policyNote;
  document.getElementById('cm-warning').textContent      = warning;

  const btn = document.getElementById('confirm-cancel-btn');
  btn.disabled = false;
  btn.textContent = 'Yes, Cancel';

  document.getElementById('cancel-overlay').classList.add('open');
}

function closeCancelModal() {
  document.getElementById('cancel-overlay').classList.remove('open');
}

// ---- Accept a specific team challenge ----
function acceptChallenge(bookingId, btn) {
  if (!confirm('Accept this challenge? 25% of the slot price will be deducted from your wallet as advance payment (50% remaining paid at venue).')) return;
  btn.disabled = true;
  btn.textContent = 'Processing…';

  fetch('accept_challenge.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'booking_id=' + bookingId
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      showToast('🎉 Challenge accepted! Match confirmed.', 'success');
      btn.outerHTML = '<span class="text-[11px] font-bold text-blue-600 bg-blue-50 border border-blue-200 px-2.5 py-1 rounded-lg">🏆 Match Set</span>';
      
      // Update status badge in the row
      const row = document.querySelector('[data-booking-id="' + bookingId + '"]');
      if (row) {
        const statusBadge = row.querySelector('.status-badge');
        if (statusBadge) {
          statusBadge.className = 'status-badge text-xs font-bold px-2 py-1 rounded-full bg-blue-100 text-blue-700';
          statusBadge.textContent = '🏆 Match Set ✓';
        }
      }

      // Update wallet balance in navbar
      if (res.amount_paid !== undefined) {
        document.querySelectorAll('.wallet-balance-display').forEach(el => {
          const current = parseFloat(el.textContent.replace(/[^0-9.]/g, '') || '0');
          const newBal = Math.max(0, current - parseFloat(res.amount_paid));
          el.textContent = formatNum(newBal) + ' PKR';
        });
      }
    } else {
      showToast('❌ ' + (res.message || 'Failed to accept challenge.'), 'error');
      btn.disabled = false;
      btn.textContent = '⚡ Accept Challenge';
    }
  })
  .catch(() => {
    showToast('❌ Network error. Please try again.', 'error');
    btn.disabled = false;
    btn.textContent = '⚡ Accept Challenge';
  });
}

function confirmCancel() {
  const btn = document.getElementById('confirm-cancel-btn');
  btn.disabled = true;
  btn.textContent = 'Cancelling…';

  fetch('cancel_booking.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'booking_id=' + cancelData.bookingId
  })
  .then(r => r.json())
  .then(res => {
    closeCancelModal();
    if (res.success) {
      showToast(res.message, 'success');
      // Update the cancel button to a cancelled badge
      const cancelBtn = document.getElementById('cancel-btn-' + cancelData.bookingId);
      if (cancelBtn) {
        cancelBtn.outerHTML = '<span class="text-[11px] font-semibold text-red-500 bg-red-50 border border-red-200 px-2.5 py-1 rounded-lg">❌ Cancelled</span>';
      }
      // Update the status badge in the row
      const row = document.querySelector('[data-booking-id="' + cancelData.bookingId + '"]');
      if (row) {
        const statusBadge = row.querySelector('.status-badge');
        if (statusBadge) {
          statusBadge.className = 'status-badge text-xs font-bold px-2 py-1 rounded-full bg-red-100 text-red-700';
          statusBadge.textContent = '❌ Cancelled';
        }
        // Remove Upcoming badge if present
        const upcomingBadge = row.querySelector('.pulse-dot')?.parentElement;
        if (upcomingBadge) upcomingBadge.remove();
      }
      // Update wallet balance display
      if (res.new_balance !== undefined) {
        const walletEls = document.querySelectorAll('.wallet-balance-display');
        walletEls.forEach(el => { el.textContent = formatNum(res.new_balance) + ' PKR'; });
      }
    } else {
      showToast('❌ ' + res.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Yes, Cancel';
    }
  })
  .catch(() => {
    showToast('❌ Network error. Please try again.', 'error');
    btn.disabled = false;
    btn.textContent = 'Yes, Cancel';
  });
}

function showToast(message, type) {
  const toast = document.getElementById('mh-toast');
  toast.textContent = message;
  toast.style.background = type === 'success'
    ? 'linear-gradient(135deg,#059669,#047857)'
    : (type === 'error' ? 'linear-gradient(135deg,#dc2626,#b91c1c)' : '#1e293b');
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 5000);
}

function formatNum(n) { return Math.round(n).toLocaleString('en-PK'); }

document.getElementById('cancel-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeCancelModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCancelModal(); });

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
