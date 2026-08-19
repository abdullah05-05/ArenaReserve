<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id     = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// ── Ensure match_scores table, cancellation_payout_owner column & enum exist ──
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
    $cols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'cancellation_payout_owner'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN cancellation_payout_owner DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }
} catch (Exception $e) {}

// ── Handle score submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_score') {
    $booking_id = intval($_POST['booking_id'] ?? 0);
    $winner_side = trim($_POST['winner_side'] ?? ''); // 'a' or 'b'

    if (!$booking_id || !in_array($winner_side, ['a', 'b'])) {
        $error_msg = 'Invalid submission. Please select a winner.';
    } else {
        try {
            $pdo->beginTransaction();

            // Fetch booking details for score entry (including slot total price and amount paid)
            $stmt = $pdo->prepare("
                SELECT b.id, b.ground_id, b.booked_by, b.opponent_id,
                       b.price, b.amount_paid, b.slot_date, b.slot_hour, b.booking_type,
                       b.status, b.challenger_team_name,
                       g.owner_id AS ground_owner_id,
                       booker.name AS team_a_name,
                       opp.name    AS team_b_name
                FROM bookings b
                JOIN grounds g ON g.id = b.ground_id
                LEFT JOIN users booker ON booker.id = b.booked_by
                LEFT JOIN users opp    ON opp.id    = b.opponent_id
                WHERE b.id = ?
                  AND b.status NOT IN ('cancelled')
            ");
            $stmt->execute([$booking_id]);
            $bk = $stmt->fetch();

            if (!$bk) {
                throw new Exception('Booking not found or already cancelled.');
            }

            $ground_owner_id = intval($bk['ground_owner_id']);

            // Verify slot has ended
            $slot_end_ts = strtotime($bk['slot_date'] . ' ' . sprintf('%02d:00:00', $bk['slot_hour'] + 1));
            if (time() < $slot_end_ts) {
                throw new Exception('The match slot has not ended yet.');
            }

            // Check not already scored
            $stmt2 = $pdo->prepare("SELECT id FROM match_scores WHERE booking_id = ?");
            $stmt2->execute([$booking_id]);
            if ($stmt2->fetch()) {
                throw new Exception('Score has already been entered for this booking.');
            }

            $score_a = ($winner_side === 'a') ? 1 : 0;
            $score_b = ($winner_side === 'b') ? 1 : 0;

            // 5% platform commission on total slot price deducted from online advance payment
            $slot_full_price = floatval($bk['price'] > 0 ? $bk['price'] : floatval($bk['amount_paid']) * 2);
            $online_paid     = floatval($bk['amount_paid']);
            $platform_fee    = round($slot_full_price * 0.05, 2);
            $owner_payout    = max(0, round($online_paid - $platform_fee, 2));

            // Insert into match_scores
            $stmt3 = $pdo->prepare("
                INSERT INTO match_scores
                    (booking_id, ground_id, owner_id, team_a_user, team_b_user, score_a, score_b, commission_paid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt3->execute([
                $booking_id,
                $bk['ground_id'],
                $ground_owner_id,
                $bk['booked_by'],
                $bk['opponent_id'],
                $score_a,
                $score_b,
                $owner_payout
            ]);

            // Credit payout to actual ground owner's wallet
            $wStmt = $pdo->prepare("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE");
            $wStmt->execute([$ground_owner_id]);
            $ownerWallet = $wStmt->fetch();

            if (!$ownerWallet) {
                $pdo->prepare("INSERT INTO wallets (user_id, available_balance) VALUES (?, ?)")
                    ->execute([$ground_owner_id, $owner_payout]);
                $ownerWalletId = $pdo->lastInsertId();
            } else {
                $ownerWalletId = $ownerWallet['id'];
                $pdo->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE id = ?")
                    ->execute([$owner_payout, $ownerWalletId]);
            }

            // Record transaction
            $pdo->prepare("
                INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id)
                VALUES (?, ?, 'Commission', ?)
            ")->execute([$ownerWalletId, $owner_payout, 'SCORE-BK-' . $booking_id]);

            $pdo->commit();
            $success_msg = '✅ Score recorded! PKR ' . number_format($owner_payout, 2) . ' credited to venue owner wallet (5% platform fee of PKR ' . number_format($platform_fee, 2) . ' deducted from online advance payment).';

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = '❌ ' . $e->getMessage();
        }
    }
}

// ── Fetch bookings for score entry ─────────────────────────────────────────────
$bookings = [];
try {
    // Check if user is Admin or owns specific grounds
    $is_admin = (($_SESSION['current_role'] ?? '') === 'Admin');

    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM grounds WHERE owner_id = ?");
    $stmt_check->execute([$user_id]);
    $my_grounds_count = $stmt_check->fetchColumn();

    if ($is_admin || $my_grounds_count == 0) {
        // Admin or owner without specific registered grounds: fetch bookings
        $stmt = $pdo->prepare("
            SELECT
                b.id            AS booking_id,
                b.slot_date,
                b.slot_hour,
                b.price,
                b.amount_paid,
                b.cancellation_payout_owner,
                b.booking_type,
                b.status        AS booking_status,
                b.challenger_team_name,
                g.id            AS ground_id,
                g.title         AS ground_title,
                g.sport_type,
                booker.name     AS team_a_name,
                opp.name        AS team_b_name,
                ms.id           AS score_id,
                ms.score_a,
                ms.score_b,
                ms.commission_paid,
                ms.scored_at
            FROM bookings b
            JOIN grounds g    ON g.id = b.ground_id
            LEFT JOIN users booker ON booker.id = b.booked_by
            LEFT JOIN users opp    ON opp.id    = b.opponent_id
            LEFT JOIN match_scores ms ON ms.booking_id = b.id
            WHERE (b.status != 'cancelled' OR b.cancellation_payout_owner > 0)
            ORDER BY b.slot_date DESC, b.slot_hour DESC
        ");
        $stmt->execute();
    } else {
        // Fetch bookings for grounds owned by this user
        $stmt = $pdo->prepare("
            SELECT
                b.id            AS booking_id,
                b.slot_date,
                b.slot_hour,
                b.price,
                b.amount_paid,
                b.cancellation_payout_owner,
                b.booking_type,
                b.status        AS booking_status,
                b.challenger_team_name,
                g.id            AS ground_id,
                g.title         AS ground_title,
                g.sport_type,
                booker.name     AS team_a_name,
                opp.name        AS team_b_name,
                ms.id           AS score_id,
                ms.score_a,
                ms.score_b,
                ms.commission_paid,
                ms.scored_at
            FROM bookings b
            JOIN grounds g    ON g.id  = b.ground_id  AND g.owner_id = :owner
            LEFT JOIN users booker ON booker.id = b.booked_by
            LEFT JOIN users opp    ON opp.id    = b.opponent_id
            LEFT JOIN match_scores ms ON ms.booking_id = b.id
            WHERE (b.status != 'cancelled' OR b.cancellation_payout_owner > 0)
            ORDER BY b.slot_date DESC, b.slot_hour DESC
        ");
        $stmt->execute([':owner' => $user_id]);
    }
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {
    $error_msg = 'Could not load bookings: ' . $e->getMessage();
}

// Current server time
$now_ts = time();

$sport_icons = [
    'Football'   => '⚽',
    'Cricket'    => '🏏',
    'Basketball' => '🏀',
    'Badminton'  => '🏸',
    'Futsal'     => '⚽',
    'Tennis'     => '🎾',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Score Entry – ArenaReserve</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .btn-disabled { opacity: 0.45; cursor: not-allowed; pointer-events: none; }
        .countdown-badge { animation: pulse 2s cubic-bezier(0.4,0,0.6,1) infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }
        .winner-btn { transition: all .2s; }
        .winner-btn.selected { box-shadow: 0 0 0 3px #10b981; transform: scale(1.02); }
        .slot-card { transition: box-shadow .2s; }
        .slot-card:hover { box-shadow: 0 4px 24px 0 rgba(16,185,129,.10); }
    </style>
    <?php
    $page_description = 'Track scores and match results for your ArenaReserve grounds. Monitor competitive activity and player engagement.';
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

                    <!-- Notification Bell -->
                    <?php include __DIR__ . '/assets/notification_bell.php'; ?>

                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <button id="profileDropdownBtn" onclick="toggleProfileDropdown()" class="flex items-center gap-2 hover:opacity-90 focus:outline-none transition-opacity" title="User Menu">
                            <div class="w-8 h-8 rounded-full overflow-hidden bg-emerald-600 text-white flex items-center justify-center font-bold text-sm flex-shrink-0 shadow-sm border border-emerald-500">
                                <?php if (!empty($_SESSION['profile_picture']) && file_exists(__DIR__ . '/' . $_SESSION['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="hidden md:block text-left">
                                <div class="text-xs font-semibold text-slate-800 flex items-center gap-1">
                                    <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?>
                                    <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                                <div class="text-[10px] text-slate-400 capitalize">Owner</div>
                            </div>
                        </button>
                        <!-- Dropdown Menu -->
                        <div id="profileDropdownMenu" class="hidden absolute right-0 top-11 w-48 bg-white rounded-xl shadow-xl border border-slate-200 py-1.5 z-50 transform opacity-0 scale-95 transition-all duration-150">
                            <a href="owner_profile.php" class="flex items-center px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-emerald-600 transition-colors">
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
                <a href="owner_analytics.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Analytics & Wallet
                </a>
                <a href="owner_scores.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg">
                    <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Score Entry
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 min-w-0">
            <!-- Alert Banner -->
            <?php if (!empty($success_msg)): ?>
                <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 text-sm text-green-700 rounded-r-lg shadow-sm">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-900">Authoritative Score Entry</h1>
                <p class="text-sm text-slate-500 mt-1">Enter final scores for matches at your venues</p>
            </div>

            <!-- Matches List -->
            <?php if (!empty($bookings)): ?>
            <div class="space-y-6 max-w-4xl">
                <?php foreach ($bookings as $bk): ?>
                    <?php
                        $slot_date = $bk['slot_date'] ?? date('Y-m-d');
                        $slot_hour = (int)($bk['slot_hour'] ?? 0);
                        $slot_end_ts = strtotime($slot_date . ' ' . sprintf('%02d:00:00', $slot_hour + 1));
                        $slot_over = ($now_ts >= $slot_end_ts);
                        $slot_remaining = max($slot_end_ts - $now_ts, 0);
                        $hrs = (int)floor($slot_remaining / 3600);
                        $mins = (int)floor(($slot_remaining % 3600) / 60);
                        $already_scored = !empty($bk['score_id']) || (!empty($bk['score_a']) || $bk['score_a'] === '0' || $bk['score_b'] !== null);
                        $is_cancelled   = ($bk['booking_status'] === 'cancelled');
                        $cancellation_earned = floatval($bk['cancellation_payout_owner'] ?? 0);
                        $team_a = trim((string)($bk['team_a_name'] ?? 'Team A')) ?: 'Team A';
                        $team_b = trim((string)($bk['team_b_name'] ?? 'Team B')) ?: 'Team B';
                        $slot_label = date('D, M j', strtotime($slot_date)) . ' · ' . sprintf('%02d:00', $slot_hour) . ':00';
                    ?>
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="space-y-3">
                            <!-- Venue Details -->
                            <div class="flex items-center gap-2 text-slate-800">
                                <span class="text-emerald-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                </span>
                                <h3 class="font-bold text-slate-800 text-lg leading-snug"><?php echo htmlspecialchars($bk['ground_title']); ?></h3>
                            </div>

                            <!-- Date / Time / Sport Tag -->
                            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500 font-medium">
                                <div class="flex items-center gap-1">
                                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span><?php echo date('D, M j, Y', strtotime($slot_date)); ?> · <?php echo sprintf('%02d:00', $slot_hour); ?>:00</span>
                                </div>
                                <span class="bg-slate-100 text-slate-700 px-2.5 py-1 rounded font-semibold text-[10px] uppercase">
                                    <?php echo htmlspecialchars($bk['sport_type']); ?>
                                </span>
                            </div>

                            <!-- Slot time -->
                            <div class="flex items-center gap-1.5 text-xs text-slate-500 font-medium">
                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <?php echo $slot_label; ?>
                                <?php if ($is_cancelled): ?>
                                    <span class="text-rose-600 font-semibold">· Cancelled booking</span>
                                <?php else: ?>
                                    <span class="text-emerald-600 font-semibold">· PKR <?php echo number_format($bk['amount_paid'], 0); ?> paid</span>
                                <?php endif; ?>
                            </div>

                            <!-- Teams -->
                            <div class="flex items-center gap-2 flex-wrap">
                                <?php if ($is_cancelled): ?>
                                    <span class="font-semibold text-slate-700 text-sm"><?php echo htmlspecialchars($team_a); ?></span>
                                    <?php if (!empty($bk['team_b_name'])): ?>
                                        <span class="text-slate-400 text-sm">vs</span>
                                        <span class="font-semibold text-slate-700 text-sm"><?php echo htmlspecialchars($team_b); ?></span>
                                    <?php endif; ?>
                                    <span class="text-xs text-rose-500 font-medium">(Booking Cancelled)</span>
                                <?php elseif ($already_scored): ?>
                                    <!-- Show final result -->
                                    <span class="font-semibold text-slate-900 text-sm <?php echo $bk['score_a'] == 1 ? 'text-emerald-700' : 'text-slate-500 line-through decoration-1'; ?>">
                                        <?php echo htmlspecialchars($team_a); ?>
                                    </span>
                                    <span class="bg-slate-100 text-slate-800 font-bold px-2.5 py-0.5 rounded text-xs"><?php echo $bk['score_a']; ?></span>
                                    <span class="text-slate-400 text-sm">vs</span>
                                    <span class="bg-slate-100 text-slate-800 font-bold px-2.5 py-0.5 rounded text-xs"><?php echo $bk['score_b']; ?></span>
                                    <span class="font-semibold text-slate-900 text-sm <?php echo $bk['score_b'] == 1 ? 'text-emerald-700' : 'text-slate-500 line-through decoration-1'; ?>">
                                        <?php echo htmlspecialchars($team_b); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="font-semibold text-slate-800 text-sm"><?php echo htmlspecialchars($team_a); ?></span>
                                    <span class="text-slate-400 text-sm">vs</span>
                                    <span class="font-semibold text-slate-800 text-sm"><?php echo htmlspecialchars($team_b); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right: action -->
                        <div class="flex-shrink-0 flex flex-col items-end gap-2">
                            <?php if ($is_cancelled): ?>
                                <!-- Cancelled state -->
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 bg-rose-50 text-rose-700 px-4 py-2 rounded-xl text-xs font-bold border border-rose-200 shadow-sm">
                                        ❌ Cancelled
                                    </span>
                                </div>
                                <?php if ($cancellation_earned > 0): ?>
                                    <span class="text-xs text-emerald-600 font-semibold">+PKR <?php echo number_format($cancellation_earned, 2); ?> earned (Cancellation fee)</span>
                                <?php else: ?>
                                    <span class="text-[10px] text-slate-400">Full refund issued to player</span>
                                <?php endif; ?>
                            <?php elseif ($already_scored): ?>
                                <!-- Finalized state -->
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 px-4 py-2 rounded-xl text-xs font-bold border border-emerald-200 shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Finalized
                                    </span>
                                </div>
                                <span class="text-xs text-emerald-600 font-semibold">+PKR <?php echo number_format($bk['commission_paid'], 0); ?> earned</span>
                            <?php elseif (!$slot_over): ?>
                                <!-- Match not yet ended -->
                                <button disabled class="btn-disabled inline-flex items-center gap-2 bg-slate-200 text-slate-500 text-xs font-bold px-5 py-2.5 rounded-xl border border-slate-300">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    Enter Score
                                </button>
                                <span class="countdown-badge text-[10px] text-amber-600 font-bold bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">
                                    ⏱ <?php
                                        if ($hrs > 0) echo "{$hrs}h {$mins}m remaining";
                                        else echo "{$mins}m remaining";
                                    ?>
                                </span>
                            <?php else: ?>
                                <!-- Slot ended – can score -->
                                <button onclick="openScoreModal(<?php echo $bk['booking_id']; ?>, '<?php echo htmlspecialchars(addslashes($team_a)); ?>', '<?php echo htmlspecialchars(addslashes($team_b)); ?>')"
                                        class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-xs font-bold px-5 py-2.5 rounded-xl shadow-sm transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Enter Score
                                </button>
                                <span class="text-[10px] text-slate-400 font-medium">Match has ended</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ── Score Modal ─────────────────────────────────────────────────────────── -->
<div id="scoreModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-sm w-full p-6 mx-4">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-base font-bold text-slate-900">Who won the match?</h3>
            <button onclick="closeScoreModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <p class="text-xs text-slate-500 mb-4">Select the winning team. The winner receives <span class="font-semibold text-slate-700">score 1</span>, the loser <span class="font-semibold text-slate-700">score 0</span>.</p>

        <form id="scoreForm" action="owner_scores.php" method="POST">
            <input type="hidden" name="action" value="save_score">
            <input type="hidden" id="modal_booking_id" name="booking_id" value="">
            <input type="hidden" id="modal_winner_side" name="winner_side" value="">

            <!-- Team buttons -->
            <div class="grid grid-cols-2 gap-3 mb-6">
                <button type="button" id="btnTeamA"
                        onclick="selectWinner('a')"
                        class="winner-btn flex flex-col items-center gap-2 border-2 border-slate-200 hover:border-emerald-400 bg-slate-50 hover:bg-emerald-50 rounded-xl px-4 py-5 transition-all">
                    <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-lg" id="avatarA">?</div>
                    <span class="text-xs font-bold text-slate-700 text-center leading-tight" id="labelTeamA">Team A</span>
                    <span class="text-[10px] text-emerald-600 font-semibold">🏆 Win (1)</span>
                </button>
                <button type="button" id="btnTeamB"
                        onclick="selectWinner('b')"
                        class="winner-btn flex flex-col items-center gap-2 border-2 border-slate-200 hover:border-emerald-400 bg-slate-50 hover:bg-emerald-50 rounded-xl px-4 py-5 transition-all">
                    <div class="w-10 h-10 rounded-full bg-violet-100 text-violet-700 flex items-center justify-center font-bold text-lg" id="avatarB">?</div>
                    <span class="text-xs font-bold text-slate-700 text-center leading-tight" id="labelTeamB">Team B</span>
                    <span class="text-[10px] text-emerald-600 font-semibold">🏆 Win (1)</span>
                </button>
            </div>

            <!-- Commission note -->
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2.5 text-xs text-emerald-800 font-medium mb-5 text-center leading-relaxed">
                💰 <strong>Payout Details:</strong> 5% platform commission of the slot price is deducted from the online advance payment, and the remaining online balance is credited directly to your wallet.
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeScoreModal()" class="flex-1 px-4 py-2.5 border border-slate-200 text-slate-600 rounded-xl text-xs font-semibold hover:bg-slate-50 transition-colors">
                    Cancel
                </button>
                <button type="submit" id="submitScoreBtn" disabled
                        class="flex-1 px-5 py-2.5 bg-emerald-500 hover:bg-emerald-600 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl text-xs font-bold shadow-sm transition-colors">
                    Submit Result
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal ────────────────────────────────────────────────────────────────────
function selectWinner(side) {
    document.getElementById('modal_winner_side').value = side;

    const btnA = document.getElementById('btnTeamA');
    const btnB = document.getElementById('btnTeamB');

    // Reset both buttons
    btnA.classList.remove('border-emerald-500', 'bg-emerald-50', 'ring-2', 'ring-emerald-300');
    btnA.classList.add('border-slate-200', 'bg-slate-50');
    btnB.classList.remove('border-emerald-500', 'bg-emerald-50', 'ring-2', 'ring-emerald-300');
    btnB.classList.add('border-slate-200', 'bg-slate-50');

    // Highlight selected button
    const selected = side === 'a' ? btnA : btnB;
    selected.classList.remove('border-slate-200', 'bg-slate-50');
    selected.classList.add('border-emerald-500', 'bg-emerald-50', 'ring-2', 'ring-emerald-300');

    // Enable submit
    document.getElementById('submitScoreBtn').disabled = false;
}
function openScoreModal(bookingId, teamA, teamB) {
    document.getElementById('modal_booking_id').value = bookingId;
    document.getElementById('modal_winner_side').value = '';
    document.getElementById('labelTeamA').textContent = teamA;
    document.getElementById('labelTeamB').textContent = teamB;
    document.getElementById('avatarA').textContent = teamA.charAt(0).toUpperCase();
    document.getElementById('avatarB').textContent = teamB.charAt(0).toUpperCase();
    // Reset selection
    document.getElementById('btnTeamA').classList.remove('selected','border-emerald-500','bg-emerald-50');
    document.getElementById('btnTeamB').classList.remove('selected','border-emerald-500','bg-emerald-50');
    document.getElementById('btnTeamA').classList.add('border-slate-200','bg-slate-50');
    document.getElementById('btnTeamB').classList.add('border-slate-200','bg-slate-50');
    document.getElementById('submitScoreBtn').disabled = true;
    document.getElementById('scoreModal').classList.remove('hidden');
}

        function closeScoreModal() {
            document.getElementById('scoreModal').classList.add('hidden');
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