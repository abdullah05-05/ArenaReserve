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

// Fetch user profile for online checkout
try {
    $uStmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $uStmt->execute([$user_id]);
    $currentUser = $uStmt->fetch() ?: ['name' => $_SESSION['name'] ?? '', 'email' => '', 'phone' => ''];
} catch (Exception $e) {
    $currentUser = ['name' => $_SESSION['name'] ?? '', 'email' => '', 'phone' => ''];
}

// Fetch verified active grounds with ratings
try {
    $stmt = $pdo->prepare("
        SELECT g.*, 
               COALESCE(gr.avg_rating, 0.0) AS avg_rating,
               COALESCE(gr.total_reviews, 0) AS total_reviews
        FROM grounds g 
        LEFT JOIN (
            SELECT ground_id, 
                   ROUND(AVG(rating), 1) AS avg_rating, 
                   COUNT(*) AS total_reviews 
            FROM ground_ratings 
            GROUP BY ground_id
        ) gr ON gr.ground_id = g.id
        WHERE g.is_verified = 1 AND COALESCE(g.ground_status, 'Active') = 'Active' 
        ORDER BY g.title ASC
    ");
    $stmt->execute();
    $grounds = $stmt->fetchAll();
} catch (Exception $e) { $grounds = []; }

$selected_ground_id = intval($_GET['ground'] ?? ($grounds[0]['id'] ?? 0));
$selected_ground    = null;
foreach ($grounds as $g) { if ($g['id'] == $selected_ground_id) { $selected_ground = $g; break; } }
if (!$selected_ground && !empty($grounds)) { $selected_ground = $grounds[0]; $selected_ground_id = $selected_ground['id']; }

// Helper to resolve ground image
if (!function_exists('getGroundCardImage')) {
    function getGroundCardImage(?array $g): string {
        if (!$g) return 'assets/images/football.png';
        if (!empty($g['image_path']) && file_exists(__DIR__ . '/' . $g['image_path'])) {
            return htmlspecialchars($g['image_path']);
        }
        $st = strtolower($g['sport_type'] ?? '');
        if (strpos($st, 'basketball') !== false) {
            return 'assets/images/basketball.png';
        } else if (strpos($st, 'cricket') !== false) {
            return 'assets/images/cricket.png';
        } else {
            return 'assets/images/football.png';
        }
    }
}

// Fetch verified reviews for all grounds
$reviews_by_ground = [];
try {
    $rStmt = $pdo->query("
        SELECT r.id, r.ground_id, r.rating, r.review, r.created_at,
               u.name AS user_name, u.city AS user_city
        FROM ground_ratings r
        JOIN users u ON u.id = r.user_id
        ORDER BY r.created_at DESC
    ");
    while ($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
        $reviews_by_ground[intval($r['ground_id'])][] = $r;
    }
} catch (Exception $e) { $reviews_by_ground = []; }

// Prepare grounds data map for JavaScript
$grounds_js_map = [];
foreach ($grounds as $g) {
    $gid = intval($g['id']);
    $desc = trim($g['description'] ?? '');
    if (empty($desc)) {
        $desc = "Standard " . ($g['sport_type'] ?? 'sports') . " venue located at " . ($g['address'] ?? 'the city center') . " equipped with high-grade playable turf, floodlighting, boundary nets, and player pavilion.";
    }
    $grounds_js_map[$gid] = [
        'id'            => $gid,
        'title'         => $g['title'],
        'sport_type'    => $g['sport_type'],
        'address'       => $g['address'],
        'description'   => $desc,
        'image_url'     => getGroundCardImage($g),
        'avg_rating'    => floatval($g['avg_rating'] ?? 0),
        'total_reviews' => intval($g['total_reviews'] ?? 0),
        'base_price'    => floatval($g['base_price'] ?? 0),
        'peak_price'    => floatval($g['peak_price'] ?? 0),
    ];
}

$current_ground_reviews = $reviews_by_ground[$selected_ground_id] ?? [];
$first_review = $current_ground_reviews[0] ?? null;
$remaining_reviews = array_slice($current_ground_reviews, 1);

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
.slot-selected   { background: #ecfdf5 !important; border: 2.5px solid #059669 !important; transform: translateY(-2px); box-shadow: 0 0 0 3px rgba(5,150,105,0.25), 0 8px 18px rgba(5,150,105,0.18) !important; position: relative; }
.slot-selected::after {
    content: '✓';
    position: absolute;
    top: -8px;
    right: -8px;
    width: 20px;
    height: 20px;
    background: #059669;
    color: white;
    font-size: 11px;
    font-weight: 800;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
}

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
    max-width: 520px; width: 100%; max-height: 90vh; display: flex; flex-direction: column;
    transform: scale(0.92) translateY(20px);
    transition: transform 0.25s cubic-bezier(.34,1.56,.64,1), opacity 0.2s ease;
    opacity: 0; overflow: hidden;
}
#booking-modal > div:not(:first-child) {
    overflow-y: auto;
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

/* Payment method selector cards */
.pay-method-card {
    border: 2px solid #e2e8f0; border-radius: 14px; padding: 12px 14px; cursor: pointer;
    transition: all 0.18s ease;
}
.pay-method-card:hover { border-color: #059669; background: #f0fdf4; }
.pay-method-card.selected { border-color: #059669; background: #ecfdf5; box-shadow: 0 0 0 2px rgba(5,150,105,0.2); }

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
          <a href="<?php echo (($_SESSION['current_active_mode'] ?? '') === 'Owner') ? 'switch_role.php' : '#'; ?>" 
             class="text-[11px] sm:text-xs font-semibold px-2 py-1 sm:px-2.5 sm:py-1.5 rounded-full transition-all duration-300 flex items-center gap-1 <?php echo (($_SESSION['current_active_mode'] ?? '') === 'Player') ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'; ?>" title="Switch to Player Mode">
             <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
             <span class="hidden sm:inline">Player</span>
          </a>
          <a href="<?php echo (($_SESSION['current_active_mode'] ?? '') === 'Player') ? 'switch_role.php' : '#'; ?>" 
             class="text-[11px] sm:text-xs font-semibold px-2 py-1 sm:px-2.5 sm:py-1.5 rounded-full transition-all duration-300 flex items-center gap-1 <?php echo (($_SESSION['current_active_mode'] ?? '') === 'Owner') ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'; ?>" title="Switch to Owner Mode">
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
      <?php if (($_SESSION['current_active_mode'] ?? '') === 'Owner'): ?>
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
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-blue-200 border border-blue-400 inline-block"></span>On Hold (10 min)</span>
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
          <option value="<?php echo $g['id']; ?>" data-sport="<?php echo htmlspecialchars($g['sport_type']); ?>" data-title="<?php echo htmlspecialchars($g['title']); ?>" data-rating="<?php echo floatval($g['avg_rating'] ?? 0); ?>" data-reviews="<?php echo intval($g['total_reviews'] ?? 0); ?>" <?php echo ($g['id']==$selected_ground_id)?'selected':''; ?>>
            <?php echo htmlspecialchars($g['title']); ?> — <?php echo $g['sport_type']; ?>
          </option>
        <?php endforeach; ?>
      </select>

      <!-- Venue Showcase: Photo, Description & Verified Reviews -->
      <div id="venue-showcase-card" class="mt-4 pt-4 border-t border-slate-100">
        <!-- Photo with overlay badges -->
        <div class="relative rounded-xl overflow-hidden mb-3.5 bg-slate-900 aspect-[16/9] sm:h-52 w-full border border-slate-100 shadow-xs group">
          <img id="venue-photo" 
               src="<?php echo getGroundCardImage($selected_ground); ?>" 
               alt="<?php echo htmlspecialchars($selected_ground['title'] ?? 'Venue'); ?>" 
               class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
               onerror="this.onerror=null; this.src='assets/images/football.png';">
          <div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/25 to-transparent"></div>
          
          <!-- Sport Badge top-right -->
          <div class="absolute top-3 right-3 flex items-center gap-1.5">
            <span id="venue-sport-tag" class="px-2.5 py-1 bg-white/95 backdrop-blur-xs text-slate-800 text-[10px] font-bold uppercase rounded-md shadow-sm">
              <?php echo htmlspecialchars($selected_ground['sport_type'] ?? ''); ?>
            </span>
          </div>

          <!-- Bottom details overlay -->
          <div class="absolute bottom-3 left-3 right-3 flex justify-between items-end text-white">
            <div class="min-w-0 pr-2">
              <h2 id="venue-title-display" class="font-extrabold text-base sm:text-lg leading-tight drop-shadow-sm truncate">
                <?php echo htmlspecialchars($selected_ground['title'] ?? ''); ?>
              </h2>
              <p id="venue-address-display" class="text-xs text-slate-200 flex items-center gap-1 mt-0.5 drop-shadow-xs truncate">
                <svg class="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                <span class="truncate"><?php echo htmlspecialchars($selected_ground['address'] ?? ''); ?></span>
              </p>
            </div>
            <div class="text-right flex-shrink-0">
              <div id="venue-rating-display" class="inline-flex items-center gap-1 text-xs font-bold bg-black/60 backdrop-blur-md px-2.5 py-1 rounded-lg border border-white/20">
                <span class="text-amber-400">★</span>
                <span id="venue-rating-val"><?php echo (!empty($selected_ground['total_reviews']) && $selected_ground['total_reviews'] > 0) ? number_format(floatval($selected_ground['avg_rating']), 1) : 'New'; ?></span>
                <?php if (!empty($selected_ground['total_reviews']) && $selected_ground['total_reviews'] > 0): ?>
                <span id="venue-rating-count" class="text-[10px] text-slate-300 font-normal">(<?php echo intval($selected_ground['total_reviews']); ?>)</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Description -->
        <div class="mb-4 bg-slate-50 border border-slate-200/80 rounded-xl p-3">
          <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1 flex items-center gap-1">
            <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            About This Ground
          </div>
          <p id="venue-description" class="text-xs sm:text-sm text-slate-700 leading-relaxed">
            <?php 
              $desc = trim($selected_ground['description'] ?? '');
              if (empty($desc)) {
                  $desc = "Standard " . ($selected_ground['sport_type'] ?? 'sports') . " ground located in " . ($selected_ground['address'] ?? 'the city') . " equipped with high-quality playable surface, floodlighting, boundary nets, and player pavilion.";
              }
              echo htmlspecialchars($desc);
            ?>
          </p>
        </div>

        <!-- Verified Player Reviews Container -->
        <div class="bg-slate-50 border border-slate-200/80 rounded-xl p-3.5">
          <div class="flex items-center justify-between mb-2.5">
            <div class="flex items-center gap-1.5 text-xs font-bold text-slate-800">
              <span class="text-amber-500">⭐</span>
              <span>Player Reviews</span>
              <span id="venue-reviews-count-badge" class="text-[10px] font-semibold text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded-full">
                <?php echo count($current_ground_reviews); ?>
              </span>
            </div>
            <div class="text-[11px] font-semibold text-slate-500" id="venue-score-summary">
              <?php if (!empty($selected_ground['total_reviews']) && $selected_ground['total_reviews'] > 0): ?>
                ★ <?php echo number_format(floatval($selected_ground['avg_rating']), 1); ?>/5 Average
              <?php else: ?>
                No reviews yet
              <?php endif; ?>
            </div>
          </div>

          <!-- Featured 1 Review (or empty state) -->
          <div id="venue-featured-review-box">
            <?php if ($first_review): ?>
            <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs">
              <div class="flex items-center justify-between mb-1.5">
                <div class="flex items-center gap-2">
                  <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-bold flex items-center justify-center">
                    <?php echo strtoupper(substr($first_review['user_name'] ?? 'P', 0, 1)); ?>
                  </div>
                  <div>
                    <span class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($first_review['user_name'] ?? 'Player'); ?></span>
                    <span class="text-[10px] text-emerald-600 font-semibold ml-1">✓ Verified Match</span>
                  </div>
                </div>
                <div class="text-amber-500 text-xs font-semibold tracking-tighter">
                  <?php echo str_repeat('★', intval($first_review['rating'])) . str_repeat('☆', 5 - intval($first_review['rating'])); ?>
                </div>
              </div>
              <?php if (!empty($first_review['review'])): ?>
              <p class="text-xs text-slate-600 italic mt-1 leading-relaxed">
                "<?php echo htmlspecialchars($first_review['review']); ?>"
              </p>
              <?php else: ?>
              <p class="text-xs text-slate-400 italic mt-0.5">Rated <?php echo intval($first_review['rating']); ?>/5 stars without written comments.</p>
              <?php endif; ?>
              <div class="text-[10px] text-slate-400 mt-1.5">
                <?php echo date('M j, Y', strtotime($first_review['created_at'])); ?>
              </div>
            </div>
            <?php else: ?>
            <div class="text-center py-4 bg-white border border-dashed border-slate-200 rounded-lg">
              <div class="text-amber-400 text-base mb-1">⭐</div>
              <p class="text-xs font-semibold text-slate-700">No player reviews yet for this venue</p>
              <p class="text-[11px] text-slate-400 mt-0.5">Book a slot and be the first verified player to rate this ground!</p>
            </div>
            <?php endif; ?>
          </div>

          <!-- Hidden Extra Reviews (toggled by Show More) -->
          <div id="venue-extra-reviews" class="hidden mt-2.5 pt-2.5 border-t border-slate-200 space-y-2.5">
            <?php foreach ($remaining_reviews as $r): ?>
            <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs">
              <div class="flex items-center justify-between mb-1.5">
                <div class="flex items-center gap-2">
                  <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-bold flex items-center justify-center">
                    <?php echo strtoupper(substr($r['user_name'] ?? 'P', 0, 1)); ?>
                  </div>
                  <div>
                    <span class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($r['user_name'] ?? 'Player'); ?></span>
                    <span class="text-[10px] text-emerald-600 font-semibold ml-1">✓ Verified Match</span>
                  </div>
                </div>
                <div class="text-amber-500 text-xs font-semibold tracking-tighter">
                  <?php echo str_repeat('★', intval($r['rating'])) . str_repeat('☆', 5 - intval($r['rating'])); ?>
                </div>
              </div>
              <?php if (!empty($r['review'])): ?>
              <p class="text-xs text-slate-600 italic mt-1 leading-relaxed">
                "<?php echo htmlspecialchars($r['review']); ?>"
              </p>
              <?php else: ?>
              <p class="text-xs text-slate-400 italic mt-0.5">Rated <?php echo intval($r['rating']); ?>/5 stars without written comments.</p>
              <?php endif; ?>
              <div class="text-[10px] text-slate-400 mt-1.5">
                <?php echo date('M j, Y', strtotime($r['created_at'])); ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Show More Button -->
          <div id="venue-show-more-wrap" class="mt-2.5 pt-2 border-t border-slate-200/60 text-center <?php echo (count($current_ground_reviews) <= 1) ? 'hidden' : ''; ?>">
            <button type="button" id="venue-show-more-btn" onclick="toggleMoreReviews()" 
                    class="text-xs font-bold text-emerald-700 hover:text-emerald-800 flex items-center justify-center gap-1 mx-auto py-1 px-3 rounded-lg hover:bg-emerald-50 transition-colors">
              <span id="venue-show-more-text">Show More Reviews (<?php echo max(0, count($current_ground_reviews) - 1); ?> more)</span>
              <svg id="venue-show-more-icon" class="w-3.5 h-3.5 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
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
          <?php if ($selected_ground): 
            $selAvg = floatval($selected_ground['avg_rating'] ?? 0);
            $selRev = intval($selected_ground['total_reviews'] ?? 0);
          ?>
          <div id="ground-rating-badge" class="flex items-center gap-1 text-xs font-semibold <?php echo ($selRev > 0) ? 'text-amber-800 bg-amber-50 border border-amber-200' : 'text-slate-500 bg-slate-50 border border-slate-200'; ?> px-2.5 py-1.5 rounded-lg shadow-2xs">
            <span class="text-amber-500">★</span>
            <span><?php echo ($selRev > 0) ? number_format($selAvg, 1) : 'New'; ?></span>
            <?php if ($selRev > 0): ?>
            <span class="text-[10px] text-slate-400 font-normal">(<?php echo $selRev; ?> reviews)</span>
            <?php endif; ?>
          </div>
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
     FLOATING MULTI-SLOT BOOKING BAR
============================================================ -->
<div id="multi-slot-bar" class="hidden fixed bottom-5 left-1/2 -translate-x-1/2 w-[94%] max-w-2xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white rounded-2xl shadow-2xl p-4 flex items-center justify-between z-40 border border-emerald-500/40 backdrop-blur-md transition-all duration-300">
  <div class="flex items-center gap-3 min-w-0">
    <div class="w-10 h-10 rounded-xl bg-white text-emerald-700 font-extrabold flex items-center justify-center text-sm shadow-sm flex-shrink-0" id="ms-bar-count">
      1
    </div>
    <div class="min-w-0">
      <div class="text-xs font-bold text-white flex items-center gap-2">
        <span id="ms-bar-title">1 Slot Selected</span>
        <span class="text-[11px] text-white font-mono font-bold bg-white/20 px-2.5 py-0.5 rounded-full border border-white/30 backdrop-blur-xs" id="ms-bar-price">2,000 PKR</span>
      </div>
      <div class="text-[11px] text-emerald-100 truncate max-w-[200px] sm:max-w-md mt-0.5" id="ms-bar-times">10:00 AM – 11:00 AM</div>
    </div>
  </div>
  <div class="flex items-center gap-2 flex-shrink-0">
    <button onclick="clearAllSelections()" class="text-xs text-white/80 hover:text-white px-3 py-2 rounded-xl transition-colors font-medium bg-white/10 hover:bg-white/20 cursor-pointer">
      Clear
    </button>
    <button onclick="goToCheckoutPage()" class="bg-white hover:bg-slate-50 text-emerald-700 text-xs font-extrabold px-4 py-2.5 rounded-xl shadow-md transition-all flex items-center gap-1.5 cursor-pointer">
      <span>Proceed to Checkout</span>
      <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
    </button>
  </div>
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

      <!-- Selected slot tags list -->
      <div id="modal-slots-pills" class="flex flex-wrap gap-1.5 mt-2.5"></div>

      <div class="flex items-center gap-4 mt-3 text-xs opacity-90">
        <span>📅 <span id="modal-date">--</span></span>
        <span>💰 <span id="modal-price" class="font-bold">--</span> PKR full price</span>
      </div>

      <!-- Hold countdown bar -->
      <div class="mt-3">
        <div class="flex items-center justify-between text-xs mb-1">
          <span class="opacity-80">Slot hold expires in</span>
          <span class="font-bold" id="modal-countdown">10:00</span>
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
      <p class="text-sm font-semibold text-slate-700 mb-3">How would you like to book the selected slot(s)?</p>
      
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
              class="mt-5 w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md hover:shadow-lg disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
              id="step1-next-btn" disabled>
        Continue →
      </button>
    </div>

    <!-- Step 2: Confirmation & Payment Selection -->
    <div id="step-2-checkout" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-3 font-medium cursor-pointer">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to options
      </button>

      <h3 class="text-base font-bold text-slate-800" id="s2-title">Confirm Booking</h3>
      <p class="text-xs text-slate-500 mb-3" id="s2-subtitle">Review booking summary and choose your payment method.</p>

      <!-- Slot Details Summary -->
      <div class="bg-slate-50 rounded-xl p-4 mb-4 space-y-2 text-xs border border-slate-200">
        <div class="flex justify-between"><span class="text-slate-500">Venue:</span><span class="font-semibold text-slate-800" id="s2-venue">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date:</span><span class="font-semibold text-slate-800" id="s2-date">--</span></div>
        
        <!-- Itemized slots list container -->
        <div class="border-t border-b border-slate-200/80 py-2 my-1 space-y-1" id="s2-slots-list">
          <!-- Filled dynamically by showStep() -->
        </div>

        <div class="flex justify-between text-slate-500"><span>Full Total Price:</span><span id="s2-full-price" class="font-medium text-slate-700">-- PKR</span></div>
        <div class="border-t border-slate-200 pt-2 flex justify-between">
          <span class="font-bold text-slate-700" id="s2-advance-label">Advance Payment (50%):</span>
          <span class="font-extrabold text-emerald-600 text-sm" id="s2-advance-price">-- PKR</span>
        </div>
        <div id="s2-opp-row" class="hidden flex justify-between text-slate-600">
          <span>Opponent Share (25%):</span>
          <span class="font-semibold text-violet-700" id="s2-opp-price">-- PKR</span>
        </div>
        <div class="flex justify-between font-semibold text-amber-700 bg-amber-50 rounded-lg p-2 text-[11px]">
          <span>Pay at Venue (Remaining 50%):</span>
          <span id="s2-venue-due">-- PKR</span>
        </div>
      </div>

      <!-- Payment Method Switcher -->
      <div class="mb-4">
        <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wider mb-2">Choose How to Pay Advance</label>
        <div class="grid grid-cols-2 gap-2.5">
          <!-- Wallet Card Option -->
          <div id="payopt-wallet" onclick="selectPaymentMethod('wallet')" class="pay-method-card selected">
            <div class="flex items-center gap-1.5 mb-1">
              <span class="text-base">💳</span>
              <span class="font-bold text-xs text-slate-800">Pay with Wallet</span>
            </div>
            <div class="text-[10px] text-slate-500">Balance: <span class="font-bold text-slate-700" id="s2-wallet-bal">-- PKR</span></div>
          </div>

          <!-- JazzCash Card Option -->
          <div id="payopt-jazzcash" onclick="selectPaymentMethod('jazzcash')" class="pay-method-card">
            <div class="flex items-center gap-1.5 mb-1">
              <span class="text-base">⚡</span>
              <span class="font-bold text-xs text-slate-800">JazzCash Online</span>
            </div>
            <div class="text-[10px] text-red-600 font-semibold truncate">Mobile · Cards · Voucher</div>
          </div>
        </div>
      </div>

      <!-- Panel 1: Wallet Payment -->
      <div id="panel-wallet" class="space-y-3">
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs space-y-1.5">
          <div class="flex justify-between text-slate-500">
            <span>Available Balance:</span>
            <span class="font-semibold text-slate-700" id="pw-balance">-- PKR</span>
          </div>
          <div class="flex justify-between text-slate-500">
            <span>Balance After Advance:</span>
            <span class="font-bold" id="pw-after">-- PKR</span>
          </div>
        </div>

        <div id="pw-insufficient-alert" class="hidden bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-800">
          <div class="font-bold mb-1 flex items-center gap-1">
            <span>⚠️ Insufficient Wallet Balance</span>
          </div>
          <p class="text-[11px]">Your wallet balance is less than the advance fee. You can pay instantly online via JazzCash!</p>
          <button type="button" onclick="selectPaymentMethod('jazzcash')" class="mt-2 text-xs font-bold text-red-700 bg-red-100 hover:bg-red-200 px-3 py-1.5 rounded-lg transition-colors inline-flex items-center gap-1 cursor-pointer">
            ⚡ Switch to JazzCash Online Checkout →
          </button>
        </div>

        <button type="button" id="wallet-pay-btn" onclick="submitWalletBooking()"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md cursor-pointer">
          ✅ Pay Advance from Wallet
        </button>
      </div>

      <!-- Panel 2: JazzCash Online Checkout -->
      <div id="panel-jazzcash" class="hidden space-y-3">
        <div class="bg-gradient-to-r from-red-50 to-amber-50 border border-red-200 rounded-xl p-3 text-xs space-y-2">
          <div class="flex items-center justify-between">
            <span class="font-bold text-slate-800">⚡ JazzCash Online Checkout</span>
            <div class="flex items-center gap-1">
              <span class="px-1.5 py-0.5 bg-white border border-red-200 text-[10px] font-bold text-red-700 rounded shadow-2xs">JazzCash</span>
              <span class="px-1.5 py-0.5 bg-white border border-red-200 text-[10px] font-bold text-red-700 rounded shadow-2xs">Cards</span>
            </div>
          </div>
          <p class="text-[11px] text-slate-600">Pay your advance fee securely via JazzCash. You will be redirected to the secure payment page.</p>
        </div>

        <div class="grid grid-cols-2 gap-2 text-xs">
          <div>
            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Mobile Number</label>
            <input type="text" id="jc-booking-phone" value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>" placeholder="03001234567"
                   class="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-800 focus:ring-1 focus:ring-emerald-400 focus:outline-none">
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Email Address</label>
            <input type="email" id="jc-booking-email" value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" placeholder="player@example.com"
                   class="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-800 focus:ring-1 focus:ring-emerald-400 focus:outline-none">
          </div>
        </div>

        <button type="button" id="jazzcash-pay-btn" onclick="submitJazzCashBooking()"
                class="w-full bg-gradient-to-r from-red-600 to-amber-600 hover:from-red-700 hover:to-amber-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          <span id="jc-booking-btn-text">⚡ Proceed to JazzCash Checkout</span>
        </button>
      </div>
    </div>

    <!-- Step 2c: Challenge Team – redirect info -->
    <div id="step-2-team" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-4 font-medium cursor-pointer">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back
      </button>
      <h3 class="text-base font-bold text-slate-800 mb-1">Challenge a Specific Team (25% Advance)</h3>
      <p class="text-xs text-slate-500 mb-4">You'll be taken to the teams page with your selected slot(s) pre-filled. Search a team, pay your 25% share, and the invite will be sent.</p>
      <div class="bg-orange-50 rounded-xl p-4 mb-4 space-y-2 text-sm border border-orange-200">
        <div class="flex justify-between text-xs"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800" id="tc-venue">--</span></div>
        <div class="flex justify-between text-xs"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="tc-date">--</span></div>
        
        <div class="border-t border-b border-orange-200/70 py-2 my-1 space-y-1" id="tc-slots-list"></div>

        <div class="flex justify-between text-xs text-slate-500"><span>Combined Full Price</span><span id="tc-full-price">-- PKR</span></div>
        <div class="border-t border-orange-200 pt-2 flex justify-between">
          <span class="font-bold text-slate-700">Your Share (25% Advance)</span>
          <span class="font-extrabold text-orange-600 text-base" id="tc-price">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs font-semibold text-amber-700 bg-amber-50 rounded-lg p-2">
          <span>Remaining 50% paid at venue:</span>
          <span id="tc-venue-due">-- PKR</span>
        </div>
      </div>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-700 mb-4">
        ⚠️ Your 10-min slot hold will be released when you navigate away. The slot reservation will be locked once you pay your 25% advance on the next page.
      </div>
      <button onclick="goToChallengeTeam()"
              class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md cursor-pointer">
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
let selectedSlots       = new Map(); // hour (int) -> { hour, time, price, ground, date }
let countdownInterval   = null;
let cardTickInterval    = null;
let livePollInterval    = null;
let holdSeconds         = 600;
let selectedType        = null;
let isModalOpen         = false;
let currentPaymentMethod = 'wallet';

// Grounds Data & Reviews Maps
const groundsDataMap   = <?php echo json_encode($grounds_js_map); ?>;
const groundReviewsMap = <?php echo json_encode($reviews_by_ground); ?>;

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function renderReviewItemHtml(r) {
  const initial = (r.user_name || 'P').charAt(0).toUpperCase();
  const stars   = '★'.repeat(parseInt(r.rating || 5)) + '☆'.repeat(Math.max(0, 5 - parseInt(r.rating || 5)));
  const text    = r.review ? `<p class="text-xs text-slate-600 italic mt-1 leading-relaxed">"${escapeHtml(r.review)}"</p>` : `<p class="text-xs text-slate-400 italic mt-0.5">Rated ${r.rating}/5 stars without written comments.</p>`;
  const dateStr = r.created_at ? new Date(r.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';

  return `
    <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs">
      <div class="flex items-center justify-between mb-1.5">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-bold flex items-center justify-center">
            ${initial}
          </div>
          <div>
            <span class="text-xs font-bold text-slate-800">${escapeHtml(r.user_name || 'Player')}</span>
            <span class="text-[10px] text-emerald-600 font-semibold ml-1">✓ Verified Match</span>
          </div>
        </div>
        <div class="text-amber-500 text-xs font-semibold tracking-tighter">
          ${stars}
        </div>
      </div>
      ${text}
      <div class="text-[10px] text-slate-400 mt-1.5">
        ${dateStr}
      </div>
    </div>
  `;
}

function updateVenueShowcase(groundId) {
  const g = groundsDataMap[groundId];
  if (!g) return;

  const photo = document.getElementById('venue-photo');
  if (photo) {
    photo.src = g.image_url;
    photo.alt = g.title;
  }

  const sportTag = document.getElementById('venue-sport-tag');
  if (sportTag) sportTag.textContent = g.sport_type;

  const titleEl = document.getElementById('venue-title-display');
  if (titleEl) titleEl.textContent = g.title;

  const addrEl = document.getElementById('venue-address-display');
  if (addrEl) {
    addrEl.innerHTML = `<svg class="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg><span class="truncate">${escapeHtml(g.address)}</span>`;
  }

  const ratingVal = document.getElementById('venue-rating-val');
  if (ratingVal) {
    ratingVal.textContent = g.total_reviews > 0 ? g.avg_rating.toFixed(1) : 'New';
  }

  const ratingCount = document.getElementById('venue-rating-count');
  if (ratingCount) {
    if (g.total_reviews > 0) {
      ratingCount.textContent = `(${g.total_reviews})`;
      ratingCount.style.display = 'inline';
    } else {
      ratingCount.style.display = 'none';
    }
  }

  const descEl = document.getElementById('venue-description');
  if (descEl) descEl.textContent = g.description;

  const reviews = groundReviewsMap[groundId] || [];
  const countBadge = document.getElementById('venue-reviews-count-badge');
  if (countBadge) countBadge.textContent = reviews.length;

  const scoreSummary = document.getElementById('venue-score-summary');
  if (scoreSummary) {
    scoreSummary.textContent = reviews.length > 0 ? `★ ${g.avg_rating.toFixed(1)}/5 Average` : 'No reviews yet';
  }

  const featuredBox = document.getElementById('venue-featured-review-box');
  const extraBox    = document.getElementById('venue-extra-reviews');
  const showMoreWrap = document.getElementById('venue-show-more-wrap');
  const showMoreText = document.getElementById('venue-show-more-text');
  const showMoreIcon = document.getElementById('venue-show-more-icon');

  if (featuredBox) {
    if (reviews.length > 0) {
      featuredBox.innerHTML = renderReviewItemHtml(reviews[0]);
      if (reviews.length > 1) {
        if (extraBox) {
          extraBox.innerHTML = reviews.slice(1).map(renderReviewItemHtml).join('');
          extraBox.classList.add('hidden');
        }
        if (showMoreWrap) showMoreWrap.classList.remove('hidden');
        if (showMoreText) showMoreText.textContent = `Show More Reviews (${reviews.length - 1} more)`;
        if (showMoreIcon) showMoreIcon.classList.remove('rotate-180');
      } else {
        if (extraBox) {
          extraBox.innerHTML = '';
          extraBox.classList.add('hidden');
        }
        if (showMoreWrap) showMoreWrap.classList.add('hidden');
      }
    } else {
      featuredBox.innerHTML = `
        <div class="text-center py-4 bg-white border border-dashed border-slate-200 rounded-lg">
          <div class="text-amber-400 text-base mb-1">⭐</div>
          <p class="text-xs font-semibold text-slate-700">No player reviews yet for this venue</p>
          <p class="text-[11px] text-slate-400 mt-0.5">Book a slot and be the first verified player to rate this ground!</p>
        </div>
      `;
      if (extraBox) {
        extraBox.innerHTML = '';
        extraBox.classList.add('hidden');
      }
      if (showMoreWrap) showMoreWrap.classList.add('hidden');
    }
  }
}

function toggleMoreReviews() {
  const extraBox = document.getElementById('venue-extra-reviews');
  const showMoreText = document.getElementById('venue-show-more-text');
  const showMoreIcon = document.getElementById('venue-show-more-icon');
  if (!extraBox) return;

  const isHidden = extraBox.classList.contains('hidden');
  if (isHidden) {
    extraBox.classList.remove('hidden');
    if (showMoreText) showMoreText.textContent = 'Show Fewer Reviews';
    if (showMoreIcon) showMoreIcon.classList.add('rotate-180');
  } else {
    extraBox.classList.add('hidden');
    const reviews = groundReviewsMap[currentGroundId] || [];
    if (showMoreText) showMoreText.textContent = `Show More Reviews (${Math.max(0, reviews.length - 1)} more)`;
    if (showMoreIcon) showMoreIcon.classList.remove('rotate-180');
  }
}

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
  const isSelected = selectedSlots.has(parseInt(slot.hour));

  let holdHtml = '';
  if (isHeldOrOnHold) {
    const isOwn  = (slot.type === 'held');
    const prefix = isOwn ? '🔵 Your hold – ' : '⏳ On hold – ';
    const rem    = Math.max(0, parseInt(slot.hold_remaining || 0));
    const m      = Math.floor(rem / 60);
    const s      = String(rem % 60).padStart(2, '0');
    const widthPct = Math.min(100, Math.max(0, Math.round((rem / 600) * 100)));
    holdHtml = `
      <div class="hold-timer-bar mt-2">
        <div class="hold-timer-fill" id="fill-${slot.hour}" style="width:${widthPct}%"></div>
      </div>
      <div class="text-[10px] text-blue-600 font-semibold mt-1" id="hold-text-${slot.hour}">
        ${prefix}${m}:${s} remaining
      </div>
    `;
  }

  let extraClasses = '';
  if (isSelected) {
    extraClasses += ' slot-selected';
  }

  return `
    <div class="slot-card slot-${slot.type}${extraClasses}"
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

  // Pre-seed any slots held by current user into selectedSlots if empty
  if (selectedSlots.size === 0) {
    slots.forEach(s => {
      if (s.type === 'held') {
        selectedSlots.set(parseInt(s.hour), {
          hour: parseInt(s.hour),
          time: s.time,
          price: parseFloat(s.price),
          ground: groundId,
          date: date
        });
      }
    });
    updateMultiSlotBar();
  }

  // Build grid HTML
  const gridHtml = '<div class="grid grid-cols-2 gap-3" id="slots-grid">' +
    slots.map(s => renderSlotCardHtml(s, groundId, date)).join('') +
    '</div>';

  container.innerHTML = gridHtml;
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

// ---- Update Wallet Balance in Navbar Real-Time ----
function updateWalletNavbar(bal) {
  const el = document.getElementById('navbar-wallet-amount');
  if (el) el.textContent = formatNum(bal);
}

// ---- Multi-Slot Click: Toggle Selection & Place/Release Hold ----
function clickSlot(el) {
  const hour   = parseInt(el.dataset.hour);
  const time   = el.dataset.time;
  const price  = parseFloat(el.dataset.price);
  const ground = parseInt(el.dataset.ground);
  const date   = el.dataset.date;

  if (selectedSlots.has(hour)) {
    // Deselect
    selectedSlots.delete(hour);
    el.classList.remove('slot-selected');

    // Call release hold via AJAX
    fetch('hold_slot.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=release&ground_id=${ground}&slot_date=${date}&slot_hours=${JSON.stringify([hour])}`
    }).catch(() => {});

    updateMultiSlotBar();
  } else {
    // Select & Place Hold
    fetch('hold_slot.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=hold&ground_id=${ground}&slot_date=${date}&slot_hours=${JSON.stringify([hour])}`
    })
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showToast('❌ ' + (res.message || 'Slot is currently on hold.'), 'error');
        fetchSlotsLive(ground, date, false);
        return;
      }

      selectedSlots.set(hour, { hour, time, price, ground, date });
      el.classList.add('slot-selected');
      holdSeconds = res.remaining || 600;
      updateSlotCardHoldState(hour, holdSeconds, true);
      updateMultiSlotBar();
    })
    .catch(() => {
      showToast('❌ Network error. Please try again.', 'error');
    });
  }
}

// ---- Update Floating Multi-Slot Action Bar ----
function updateMultiSlotBar() {
  const bar = document.getElementById('multi-slot-bar');
  if (!bar) return;

  const count = selectedSlots.size;
  if (count === 0) {
    bar.classList.add('hidden');
    return;
  }

  bar.classList.remove('hidden');

  let totalPrice = 0;
  const times = [];
  // Sort by hour
  const sorted = Array.from(selectedSlots.values()).sort((a,b) => a.hour - b.hour);
  sorted.forEach(s => {
    totalPrice += s.price;
    times.push(s.time);
  });

  const countEl = document.getElementById('ms-bar-count');
  const titleEl = document.getElementById('ms-bar-title');
  const priceEl = document.getElementById('ms-bar-price');
  const timesEl = document.getElementById('ms-bar-times');

  if (countEl) countEl.textContent = count;
  if (titleEl) titleEl.textContent = count === 1 ? '1 Slot Selected' : `${count} Slots Selected`;
  if (priceEl) priceEl.textContent = `${formatNum(totalPrice)} PKR`;
  if (timesEl) timesEl.textContent = times.join(', ');
}

// ---- Clear All Selected Slots ----
function clearAllSelections() {
  if (selectedSlots.size === 0) return;

  const hours = Array.from(selectedSlots.keys());
  fetch('hold_slot.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=release&ground_id=${currentGroundId}&slot_date=${currentDate}&slot_hours=${JSON.stringify(hours)}`
  }).catch(() => {});

  selectedSlots.clear();
  document.querySelectorAll('.slot-card.slot-selected').forEach(c => c.classList.remove('slot-selected'));
  updateMultiSlotBar();
  fetchSlotsLive(currentGroundId, currentDate, false);
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
  const widthPct = Math.min(100, Math.max(0, Math.round((seconds / 600) * 100)));

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
  if (selectedSlots.size === 0) {
    showToast('Please select at least one time slot to book.', 'info');
    return;
  }

  isModalOpen = true;
  selectedType = null;
  document.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('step1-next-btn').disabled = true;

  const groundEl   = document.getElementById('ground-select');
  const selectedOpt = groundEl ? groundEl.options[groundEl.selectedIndex] : null;
  const groundName = selectedOpt ? (selectedOpt.dataset.title || selectedOpt.text.split('—')[0].trim()) : 'Venue';

  const count = selectedSlots.size;
  const sorted = Array.from(selectedSlots.values()).sort((a,b) => a.hour - b.hour);
  let totalPrice = 0;
  sorted.forEach(s => totalPrice += s.price);

  document.getElementById('modal-ground-name').textContent = groundName;
  document.getElementById('modal-slot-time').textContent   = count === 1 ? sorted[0].time : `${count} Slots Selected`;
  document.getElementById('modal-date').textContent        = currentDate;
  document.getElementById('modal-price').textContent       = formatNum(totalPrice);

  // Render pills in header
  const pillsWrap = document.getElementById('modal-slots-pills');
  if (pillsWrap) {
    pillsWrap.innerHTML = sorted.map(s => `
      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/20 text-white backdrop-blur-xs border border-white/30">
        🕒 ${escHtml(s.time)} (${formatNum(s.price)} PKR)
      </span>
    `).join('');
  }

  const directHalf = Math.round(totalPrice * 0.5);
  const quarter    = Math.round(totalPrice * 0.25);
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

  // Sync slots without full reload
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
      showToast('⏰ Hold expired. Slots released.', 'info');
      clearAllSelections();
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
      if (fill) fill.style.width = Math.max(0, Math.min(100, Math.round((rem / 600) * 100))) + '%';
      if (text) {
        if (rem <= 0) {
          text.textContent = 'Hold expired';
          hasExpired = true;
        } else {
          const isOwn  = card.classList.contains('slot-held') || card.classList.contains('slot-selected');
          const prefix = isOwn ? '🔵 Your hold – ' : '⏳ On hold – ';
          const m      = Math.floor(rem / 60);
          const s      = String(rem % 60).padStart(2, '0');
          text.textContent = prefix + m + ':' + s + ' remaining';
        }
      }
    });

    if (hasExpired && !isModalOpen) {
      fetchSlotsLive(currentGroundId, currentDate, false);
    }
  }, 1000);
}

// ---- Smooth Venue Change without Page Reload ----
function onGroundChanged(newGroundId) {
  clearAllSelections();
  currentGroundId = parseInt(newGroundId);
  const groundEl   = document.getElementById('ground-select');
  const opt        = groundEl ? groundEl.options[groundEl.selectedIndex] : null;

  if (opt) {
    const sportBadge = document.getElementById('ground-sport-badge');
    if (sportBadge && opt.dataset.sport) sportBadge.textContent = opt.dataset.sport;

    const ratingBadge = document.getElementById('ground-rating-badge');
    if (ratingBadge) {
      const avg = parseFloat(opt.dataset.rating) || 0;
      const revs = parseInt(opt.dataset.reviews) || 0;
      if (revs > 0) {
        ratingBadge.className = 'flex items-center gap-1 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-2.5 py-1.5 rounded-lg shadow-2xs';
        ratingBadge.innerHTML = `<span class="text-amber-500">★</span> <span>${avg.toFixed(1)}</span> <span class="text-[10px] text-slate-400 font-normal">(${revs} reviews)</span>`;
      } else {
        ratingBadge.className = 'flex items-center gap-1 text-xs font-semibold text-slate-500 bg-slate-50 border border-slate-200 px-2.5 py-1.5 rounded-lg shadow-2xs';
        ratingBadge.innerHTML = `<span class="text-amber-500">★</span> <span>New</span>`;
      }
    }
  }

  // Update venue photo, description and reviews showcase
  updateVenueShowcase(currentGroundId);

  // Update URL seamlessly
  history.pushState(null, '', `book_slot.php?ground=${currentGroundId}&date=${currentDate}`);
  fetchSlotsLive(currentGroundId, currentDate, true);
}

// ---- Smooth Date Change without Page Reload ----
function onDateChanged(newDate) {
  clearAllSelections();
  currentDate = newDate;

  const dateLabel = document.getElementById('slots-date-label');
  if (dateLabel) {
    const d = new Date(newDate + 'T00:00:00');
    dateLabel.textContent = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  }

  // Update URL seamlessly
  history.pushState(null, '', `book_slot.php?ground=${currentGroundId}&date=${currentDate}`);
  fetchSlotsLive(currentGroundId, currentDate, true);
}

// ---- Payment Method State in Modal ----
function selectPaymentMethod(method) {
  currentPaymentMethod = method;
  const wCard = document.getElementById('payopt-wallet');
  const jcCard = document.getElementById('payopt-jazzcash');
  const wPanel = document.getElementById('panel-wallet');
  const jcPanel = document.getElementById('panel-jazzcash');

  if (method === 'wallet') {
    if (wCard) { wCard.className = 'pay-method-card selected'; }
    if (jcCard) { jcCard.className = 'pay-method-card'; }
    if (wPanel) { wPanel.classList.remove('hidden'); }
    if (jcPanel) { jcPanel.classList.add('hidden'); }
  } else {
    if (wCard) { wCard.className = 'pay-method-card'; }
    if (jcCard) { jcCard.className = 'pay-method-card selected'; }
    if (wPanel) { wPanel.classList.add('hidden'); }
    if (jcPanel) { jcPanel.classList.remove('hidden'); }
  }
}

// ---- Step Navigation in Booking Modal ----
function showStep(n) {
  ['step-1','step-2-checkout','step-2-team'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
  });
  ['dot-1','dot-2'].forEach(id => document.getElementById(id).classList.remove('active'));

  if (n === 1) {
    document.getElementById('step-1').classList.remove('hidden');
    document.getElementById('dot-1').classList.add('active');
    return;
  }

  document.getElementById('dot-1').classList.add('active');
  document.getElementById('dot-2').classList.add('active');

  const count = selectedSlots.size;
  const sorted = Array.from(selectedSlots.values()).sort((a,b) => a.hour - b.hour);
  let totalPrice = 0;
  sorted.forEach(s => totalPrice += s.price);

  const balance    = currentWalletBalance;
  const directHalf = Math.round(totalPrice * 0.5);
  const quarter    = Math.round(totalPrice * 0.25);
  const groundEl   = document.getElementById('ground-select');
  const opt        = groundEl ? groundEl.options[groundEl.selectedIndex] : null;
  const groundName = opt ? (opt.dataset.title || opt.text.split('—')[0].trim()) : 'Venue';

  if (selectedType === 'team_challenge') {
    document.getElementById('step-2-team').classList.remove('hidden');
    document.getElementById('tc-venue').textContent      = groundName;
    document.getElementById('tc-date').textContent       = currentDate;
    
    const tcSlotsList = document.getElementById('tc-slots-list');
    if (tcSlotsList) {
      tcSlotsList.innerHTML = sorted.map(s => `
        <div class="flex justify-between items-center text-[11px] text-slate-700 bg-white/70 px-2 py-1 rounded border border-orange-200/60">
          <span class="font-semibold">${escHtml(s.time)}</span>
          <span class="font-bold text-slate-800">${formatNum(s.price)} PKR</span>
        </div>
      `).join('');
    }

    document.getElementById('tc-full-price').textContent = formatNum(totalPrice) + ' PKR';
    document.getElementById('tc-price').textContent      = formatNum(quarter) + ' PKR';
    document.getElementById('tc-venue-due').textContent  = formatNum(totalPrice - (quarter * 2)) + ' PKR';
    return;
  }

  // Direct Booking or Open Challenge
  document.getElementById('step-2-checkout').classList.remove('hidden');
  document.getElementById('s2-venue').textContent      = groundName;
  document.getElementById('s2-date').textContent       = currentDate;

  const s2SlotsList = document.getElementById('s2-slots-list');
  if (s2SlotsList) {
    s2SlotsList.innerHTML = sorted.map(s => `
      <div class="flex justify-between items-center text-[11px] text-slate-700 bg-white px-2.5 py-1.5 rounded-lg border border-slate-200 shadow-2xs">
        <div class="flex items-center gap-1.5">
          <span class="text-emerald-600 font-bold">●</span>
          <span class="font-semibold">${escHtml(s.time)}</span>
        </div>
        <span class="font-bold text-slate-800">${formatNum(s.price)} PKR</span>
      </div>
    `).join('');
  }

  document.getElementById('s2-full-price').textContent = formatNum(totalPrice) + ' PKR';
  document.getElementById('s2-wallet-bal').textContent  = formatNum(balance) + ' PKR';
  document.getElementById('pw-balance').textContent     = formatNum(balance) + ' PKR';

  const isDirect = (selectedType === 'direct');
  const advanceAmount = isDirect ? directHalf : quarter;

  document.getElementById('s2-title').textContent = isDirect 
    ? (count > 1 ? `Confirm Direct Booking (${count} Slots)` : 'Confirm Direct Booking')
    : (count > 1 ? `Post Open Challenge (${count} Slots)` : 'Post Open Challenge');

  document.getElementById('s2-subtitle').textContent = isDirect 
    ? `Pay 50% advance now (${formatNum(advanceAmount)} PKR), remaining 50% at venue.`
    : `Pay 25% share now (${formatNum(advanceAmount)} PKR), opponent pays 25%, remaining 50% at venue.`;

  document.getElementById('s2-advance-label').textContent = isDirect ? 'Advance Payment (50%):' : 'You Pay Now (25% Advance):';
  document.getElementById('s2-advance-price').textContent = formatNum(advanceAmount) + ' PKR';

  const oppRow = document.getElementById('s2-opp-row');
  if (oppRow) {
    if (!isDirect) {
      oppRow.classList.remove('hidden');
      document.getElementById('s2-opp-price').textContent = formatNum(quarter) + ' PKR';
    } else {
      oppRow.classList.add('hidden');
    }
  }

  const venueDue = isDirect ? (totalPrice - directHalf) : (totalPrice - (quarter * 2));
  document.getElementById('s2-venue-due').textContent = formatNum(venueDue) + ' PKR';

  // Balance calculation
  const after = balance - advanceAmount;
  const afterEl = document.getElementById('pw-after');
  if (afterEl) {
    afterEl.textContent = formatNum(after) + ' PKR';
    afterEl.className = 'font-bold ' + (after >= 0 ? 'text-emerald-600' : 'text-red-600');
  }

  const walletBtn = document.getElementById('wallet-pay-btn');
  const alertBox  = document.getElementById('pw-insufficient-alert');
  const jcBtnText = document.getElementById('jc-booking-btn-text');

  if (jcBtnText) {
    jcBtnText.textContent = `⚡ Pay ${formatNum(advanceAmount)} PKR via JazzCash`;
  }

  if (balance < advanceAmount) {
    if (walletBtn) {
      walletBtn.disabled = true;
      walletBtn.textContent = '❌ Insufficient Wallet Balance';
      walletBtn.className = 'w-full bg-slate-300 text-slate-500 font-bold py-3 rounded-xl text-sm cursor-not-allowed';
    }
    if (alertBox) alertBox.classList.remove('hidden');
    selectPaymentMethod('jazzcash');
  } else {
    if (walletBtn) {
      walletBtn.disabled = false;
      walletBtn.textContent = `✅ Pay ${formatNum(advanceAmount)} PKR from Wallet`;
      walletBtn.className = 'w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md cursor-pointer';
    }
    if (alertBox) alertBox.classList.add('hidden');
    selectPaymentMethod('wallet');
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

// ---- Submit Wallet Booking ----
function submitWalletBooking() {
  submitBooking(selectedType);
}

// ---- Submit JazzCash Hosted Checkout Booking ----
function submitJazzCashBooking() {
  if (selectedSlots.size === 0) return;

  const btn = document.getElementById('jazzcash-pay-btn');
  const btnText = document.getElementById('jc-booking-btn-text');
  if (btn) btn.disabled = true;
  if (btnText) btnText.textContent = 'Initiating Checkout…';

  const phone = document.getElementById('jc-booking-phone')?.value || '';
  const email = document.getElementById('jc-booking-email')?.value || '';
  const hours = Array.from(selectedSlots.keys());

  fetch('initiate_checkout.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Accept': 'application/json'
    },
    body: new URLSearchParams({
      purpose:        'slot_booking',
      format:         'json',
      ground_id:      currentGroundId,
      slot_date:      currentDate,
      slot_hours:     JSON.stringify(hours),
      booking_type:   selectedType,
      payment_method: 'JazzCash',
      phone:          phone,
      email:          email
    })
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
      let totalP = 0;
      selectedSlots.forEach(s => totalP += s.price);
      const advAmt = (selectedType === 'direct') ? Math.round(totalP * 0.5) : Math.round(totalP * 0.25);
      if (btnText) btnText.textContent = `⚡ Pay ${formatNum(advAmt)} PKR via JazzCash`;
    }
  })
  .catch(() => {
    showToast('❌ Network error while initiating checkout.', 'error');
    if (btn) btn.disabled = false;
    if (btnText) btnText.textContent = '⚡ Proceed to JazzCash Checkout';
  });
}

// ---- Submit Booking via Wallet ----
function submitBooking(type) {
  if (selectedSlots.size === 0) return;

  const btn = document.getElementById('wallet-pay-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Processing payment…'; }

  const hours = Array.from(selectedSlots.keys());

  fetch('process_booking.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({
      ground_id:    currentGroundId,
      slot_date:    currentDate,
      slot_hours:   JSON.stringify(hours),
      booking_type: type
    })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      selectedSlots.clear();
      updateMultiSlotBar();
      closeModal();
      showToast(res.message, 'success');

      // Deduct wallet balance locally in real time
      if (res.amount_paid !== undefined) {
        currentWalletBalance = Math.max(0, currentWalletBalance - parseFloat(res.amount_paid));
        updateWalletNavbar(currentWalletBalance);
      }

      // Fetch fresh slots live
      fetchSlotsLive(currentGroundId, currentDate, false);
    } else {
      showToast('❌ ' + res.message, 'error');
      if (btn) {
        btn.disabled    = false;
        let totalP = 0;
        selectedSlots.forEach(s => totalP += s.price);
        const advAmt = (type === 'direct') ? Math.round(totalP * 0.5) : Math.round(totalP * 0.25);
        btn.textContent = `✅ Pay ${formatNum(advAmt)} PKR from Wallet`;
      }
    }
  })
  .catch(() => {
    showToast('❌ Network error.', 'error');
    if (btn) btn.disabled = false;
  });
}

// ---- Go to Dedicated Checkout Page ----
function goToCheckoutPage() {
  if (selectedSlots.size === 0) {
    showToast('Please select at least one time slot to book.', 'info');
    return;
  }
  const hours = Array.from(selectedSlots.keys());
  window.location.href = `checkout.php?ground_id=${currentGroundId}&date=${currentDate}&hours=${hours.join(',')}`;
}

// ---- Go to Challenge Team Page ----
function goToChallengeTeam() {
  const sorted = Array.from(selectedSlots.values()).sort((a,b) => a.hour - b.hour);
  const primary = sorted[0];
  let totalPrice = 0;
  sorted.forEach(s => totalPrice += s.price);
  const quarter = Math.round(totalPrice * 0.25);

  window.location.href = 'challenge_team.php?ground_id=' + currentGroundId + '&date=' + currentDate + '&hour=' + primary.hour + '&price=' + totalPrice + '&quarter=' + quarter + '&half=' + quarter;
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
    // Only poll when user is viewing the page, not inside modal, and not actively holding custom selection
    if (!document.hidden && !isModalOpen && selectedSlots.size === 0) {
      fetchSlotsLive(currentGroundId, currentDate, false);
    }
  }, 4000);
})();
</script>
</body>
</html>
