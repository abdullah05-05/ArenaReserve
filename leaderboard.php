<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];

// ── Ensure match_scores table exists ──────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `match_scores` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `booking_id`       INT NOT NULL UNIQUE,
            `ground_id`        INT NOT NULL,
            `owner_id`         INT NOT NULL,
            `team_a_user`      INT NOT NULL,
            `team_b_user`      INT DEFAULT NULL,
            `score_a`          TINYINT NOT NULL COMMENT '1=win 0=loss',
            `score_b`          TINYINT NOT NULL COMMENT '1=win 0=loss',
            `commission_paid`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `scored_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`owner_id`)   REFERENCES `users`(`id`)    ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

// ── Wallet balance ────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $available_balance = $wallet['available_balance'] ?? 0.00;
} catch (Exception $e) { $available_balance = 0.00; }

// ── Logged-in user's city (default filter) ────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT city, name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $me = $stmt->fetch();
    $my_city = $me['city'] ?? 'Lahore';
    $my_name = $me['name'] ?? '';
} catch (Exception $e) {
    $my_city = 'Lahore';
    $my_name = $_SESSION['name'] ?? '';
}

// ── City filter (from GET or user's own city) ─────────────────────────────────
$selected_city = trim($_GET['city'] ?? $my_city);

// ── All distinct cities (for dropdown) ───────────────────────────────────────
$all_cities = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT city FROM users WHERE city != '' ORDER BY city ASC");
    $all_cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $all_cities = [$my_city];
}

// ── Leaderboard query ─────────────────────────────────────────────────────────
// Count wins per player (team_a or team_b) from match_scores, filtered by city
$leaderboard = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.city,
            COUNT(
                CASE
                    WHEN ms.team_a_user = u.id AND ms.score_a = 1 THEN 1
                    WHEN ms.team_b_user = u.id AND ms.score_b = 1 THEN 1
                END
            ) AS wins,
            COUNT(
                CASE
                    WHEN ms.team_a_user = u.id OR ms.team_b_user = u.id THEN 1
                END
            ) AS total_matches
        FROM users u
        LEFT JOIN match_scores ms ON (ms.team_a_user = u.id OR ms.team_b_user = u.id)
        WHERE u.city = :city
          AND u.current_role IN ('Player', 'Owner')
          AND u.status = 'Active'
        GROUP BY u.id, u.name, u.city
        ORDER BY wins DESC, total_matches DESC, u.name ASC
        LIMIT 50
    ");
    $stmt->execute([':city' => $selected_city]);
    $leaderboard = $stmt->fetchAll();
} catch (Exception $e) {
    $leaderboard = [];
}

// Assign ranks
foreach ($leaderboard as $i => &$row) {
    $row['rank'] = $i + 1;
}
unset($row);

$sport_icons = ['Football'=>'⚽','Cricket'=>'🏏','Basketball'=>'🏀','Badminton'=>'🏸','Futsal'=>'⚽','Tennis'=>'🎾'];
$podium = array_slice($leaderboard, 0, 3);
// Pad podium to 3 entries if fewer
while (count($podium) < 3) {
    $podium[] = ['name'=>'—','wins'=>0,'rank'=> count($podium)+1,'city'=>$selected_city, 'id'=>null];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leaderboard – ArenaReserve</title>
<meta name="description" content="See the top players in your city on the ArenaReserve leaderboard, ranked by match wins.">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body { font-family:'Inter',sans-serif; background:#f8fafc; }
.podium-1 { background: linear-gradient(135deg,#fbbf24,#f59e0b); color:#fff; }
.podium-2 { background: linear-gradient(135deg,#94a3b8,#64748b); color:#fff; }
.podium-3 { background: linear-gradient(135deg,#f97316,#ea580c); color:#fff; }
.my-row   { background: linear-gradient(90deg,rgba(16,185,129,.06),transparent); border-left: 3px solid #10b981; }
.city-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 1.2em; padding-right: 2rem; appearance: none; -webkit-appearance: none; }
.rank-badge { width:2rem;height:2rem;display:flex;align-items:center;justify-content:center;border-radius:50%;font-weight:800;font-size:.75rem; }
</style>
    <?php
    $page_description = 'Check the ArenaReserve leaderboard – See top players, team rankings, and match results across all sports venues.';
    include 'logo_head.php';
    ?>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
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
      <!-- Wallet (hidden on mobile) -->
      <a href="wallet.php" class="hidden sm:flex items-center bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full text-xs font-semibold border border-emerald-200">
        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span><?php echo number_format($available_balance,0); ?> PKR
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
                <?php echo strtoupper(substr($_SESSION['name'],0,1)); ?>
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
      <a href="explore.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg">
        <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
        Explore Grounds
      </a>
      <a href="book_slot.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg">
        <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        Book Slot
      </a>
      <a href="match_history.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg">
        <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Match History
      </a>
      <a href="challenge_team.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg">
        <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Challenge Team
      </a>
      <a href="leaderboard.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg">
        <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        Leaderboard
      </a>
      <div class="border-t border-slate-100 mt-2 pt-2">
        <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg">
          <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
          My Wallet
        </a>
      </div>
    </nav>
  </aside>

  <!-- Main -->
  <main class="flex-1 min-w-0">

    <!-- Page Header + City Filter -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Leaderboard</h1>
        <p class="text-sm text-slate-500 mt-1">Top players by wins in <span class="font-semibold text-emerald-600"><?php echo htmlspecialchars($selected_city); ?></span></p>
      </div>

      <!-- City Filter Form -->
      <form method="GET" action="leaderboard.php" class="flex items-center gap-2">
        <div class="relative">
          <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <select id="cityFilter" name="city"
                  onchange="this.form.submit()"
                  class="city-select pl-9 pr-8 py-2 text-sm font-semibold border border-slate-200 rounded-xl bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-400 shadow-sm">
            <?php foreach ($all_cities as $city): ?>
              <option value="<?php echo htmlspecialchars($city); ?>" <?php echo ($city === $selected_city) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($city); ?>
              </option>
            <?php endforeach; ?>
            <?php if (!in_array($selected_city, $all_cities) && $selected_city): ?>
              <option value="<?php echo htmlspecialchars($selected_city); ?>" selected><?php echo htmlspecialchars($selected_city); ?></option>
            <?php endif; ?>
          </select>
        </div>
      </form>
    </div>

    <?php if (empty($leaderboard)): ?>
      <!-- Empty state -->
      <div class="bg-white border border-slate-200 rounded-2xl p-14 shadow-sm text-center">
        <div class="text-5xl mb-4">🏆</div>
        <h3 class="text-base font-bold text-slate-700 mb-2">No rankings yet for <?php echo htmlspecialchars($selected_city); ?></h3>
        <p class="text-sm text-slate-400 max-w-xs mx-auto">Rankings appear here after matches are played and scores are entered by ground owners. Be the first to play!</p>
        <a href="book_slot.php" class="mt-5 inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-5 py-2.5 rounded-xl shadow-sm transition-colors">
          Book a Match Slot
        </a>
      </div>

    <?php else: ?>

      <!-- ── Podium (top 3) ──────────────────────────────────────────────── -->
      <?php if (count($leaderboard) >= 1): ?>
      <div class="grid grid-cols-3 gap-3 mb-8">

        <!-- 2nd place (left) -->
        <div class="podium-2 rounded-2xl p-5 text-center shadow-md mt-6 flex flex-col items-center">
          <div class="text-3xl mb-2">🥈</div>
          <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center font-extrabold text-lg mb-1">
            <?php echo $podium[1]['id'] ? strtoupper(substr($podium[1]['name'],0,1)) : '—'; ?>
          </div>
          <div class="font-bold text-sm truncate w-full text-center"><?php echo htmlspecialchars($podium[1]['name']); ?></div>
          <div class="text-xs opacity-80 mt-0.5"><?php echo $podium[1]['wins']; ?> win<?php echo $podium[1]['wins'] != 1 ? 's' : ''; ?></div>
        </div>

        <!-- 1st place (center, tallest) -->
        <div class="podium-1 rounded-2xl p-5 text-center shadow-xl flex flex-col items-center">
          <div class="text-4xl mb-2">🥇</div>
          <div class="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center font-extrabold text-xl mb-1">
            <?php echo $podium[0]['id'] ? strtoupper(substr($podium[0]['name'],0,1)) : '—'; ?>
          </div>
          <div class="font-extrabold truncate w-full text-center"><?php echo htmlspecialchars($podium[0]['name']); ?></div>
          <div class="text-xs opacity-80 mt-0.5"><?php echo $podium[0]['wins']; ?> win<?php echo $podium[0]['wins'] != 1 ? 's' : ''; ?></div>
          <?php if ($podium[0]['id']): ?>
            <div class="text-[10px] opacity-70 mt-0.5 font-semibold"><?php echo $podium[0]['total_matches'] ?? 0; ?> matches</div>
          <?php endif; ?>
        </div>

        <!-- 3rd place (right) -->
        <div class="podium-3 rounded-2xl p-5 text-center shadow-md mt-8 flex flex-col items-center">
          <div class="text-3xl mb-2">🥉</div>
          <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center font-extrabold text-lg mb-1">
            <?php echo $podium[2]['id'] ? strtoupper(substr($podium[2]['name'],0,1)) : '—'; ?>
          </div>
          <div class="font-bold text-sm truncate w-full text-center"><?php echo htmlspecialchars($podium[2]['name']); ?></div>
          <div class="text-xs opacity-80 mt-0.5"><?php echo $podium[2]['wins']; ?> win<?php echo $podium[2]['wins'] != 1 ? 's' : ''; ?></div>
        </div>

      </div>
      <?php endif; ?>

      <!-- ── Full Rankings Table ─────────────────────────────────────────── -->
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
          <h2 class="text-sm font-bold text-slate-800">All Rankings · <?php echo htmlspecialchars($selected_city); ?></h2>
          <span class="text-xs text-slate-400 font-medium"><?php echo count($leaderboard); ?> player<?php echo count($leaderboard) != 1 ? 's' : ''; ?></span>
        </div>
        <div class="divide-y divide-slate-100">
          <?php foreach ($leaderboard as $p):
            $is_me = ($p['id'] == $user_id);
            $rank  = $p['rank'];
          ?>
          <div class="px-5 py-4 flex items-center gap-4 hover:bg-slate-50 transition-colors <?php echo $is_me ? 'my-row' : ''; ?>">
            <!-- Rank badge -->
            <div class="flex-shrink-0">
              <?php if ($rank === 1): ?>
                <div class="rank-badge bg-amber-100 text-amber-600">🥇</div>
              <?php elseif ($rank === 2): ?>
                <div class="rank-badge bg-slate-100 text-slate-500">🥈</div>
              <?php elseif ($rank === 3): ?>
                <div class="rank-badge bg-orange-100 text-orange-600">🥉</div>
              <?php else: ?>
                <div class="rank-badge bg-slate-50 text-slate-400 border border-slate-200 text-xs font-bold">#<?php echo $rank; ?></div>
              <?php endif; ?>
            </div>

            <!-- Avatar -->
            <div class="w-9 h-9 rounded-full <?php echo $is_me ? 'bg-emerald-500 text-white ring-2 ring-emerald-300' : 'bg-slate-200 text-slate-600'; ?> flex items-center justify-center font-bold text-sm flex-shrink-0">
              <?php echo strtoupper(substr($p['name'], 0, 1)); ?>
            </div>

            <!-- Name & city -->
            <div class="flex-1 min-w-0">
              <div class="font-semibold text-slate-800 text-sm truncate flex items-center gap-1.5">
                <?php echo htmlspecialchars($p['name']); ?>
                <?php if ($is_me): ?><span class="text-[10px] bg-emerald-100 text-emerald-700 font-bold px-1.5 py-0.5 rounded-full">You</span><?php endif; ?>
              </div>
              <div class="text-xs text-slate-400 flex items-center gap-1 mt-0.5">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <?php echo htmlspecialchars($p['city']); ?>
                <span class="text-slate-300">·</span>
                <?php echo $p['total_matches'] ?? 0; ?> match<?php echo ($p['total_matches'] ?? 0) != 1 ? 'es' : ''; ?>
              </div>
            </div>

            <!-- Wins -->
            <div class="flex-shrink-0 text-right">
              <div class="text-sm font-bold text-slate-800">
                <?php echo $p['wins']; ?>
                <span class="text-xs font-normal text-slate-400">win<?php echo $p['wins'] != 1 ? 's' : ''; ?></span>
              </div>
              <?php if ($p['total_matches'] > 0): ?>
              <div class="text-[10px] text-slate-400 mt-0.5">
                <?php echo round(($p['wins'] / $p['total_matches']) * 100); ?>% win rate
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>
  </main>
</div>
</body>
<script>
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
</html>
