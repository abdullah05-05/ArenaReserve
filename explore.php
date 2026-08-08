<?php
session_start();
require_once 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user profile details & wallet balance
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT available_balance, frozen_escrow_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    
    $available_balance = $wallet['available_balance'] ?? 0.00;
} catch (Exception $e) {
    $available_balance = 0.00;
}

// --- Player location: read from GET (set by client-side JS) or fall back to Karachi default ---
$default_lat = 24.8607;
$default_lng = 67.0011;
$using_default_location = true;

$player_lat = $default_lat;
$player_lng = $default_lng;

if (isset($_GET['player_lat']) && isset($_GET['player_lng'])) {
    $raw_lat = floatval($_GET['player_lat']);
    $raw_lng = floatval($_GET['player_lng']);
    // Validate coordinate ranges
    if ($raw_lat >= -90 && $raw_lat <= 90 && $raw_lng >= -180 && $raw_lng <= 180) {
        $player_lat = $raw_lat;
        $player_lng = $raw_lng;
        $using_default_location = false;
    }
}

// Get filters
$sport_filter = $_GET['sport_type'] ?? 'All';
$sort_by = $_GET['sort_by'] ?? 'proximity';
$radius_km = $_GET['radius_km'] ?? 'any'; // 'any', '5', '10', '25', '50'

// Build SQL query for verified grounds
$sql = "SELECT g.*, 
        (6371 * acos(cos(radians(:player_lat1)) * cos(radians(g.latitude)) * cos(radians(g.longitude) - radians(:player_lng)) + sin(radians(:player_lat2)) * sin(radians(g.latitude)))) AS distance 
        FROM grounds g 
        WHERE g.is_verified = 1
        AND (g.ground_status IS NULL OR g.ground_status = 'Active')";

$params = [
    'player_lat1' => $player_lat,
    'player_lat2' => $player_lat,
    'player_lng' => $player_lng
];

if ($sport_filter !== 'All') {
    $sql .= " AND g.sport_type = :sport_type";
    $params['sport_type'] = $sport_filter;
}

// Sorting logic
if ($sort_by === 'price_asc') {
    $sql .= " ORDER BY g.base_price ASC";
} else if ($sort_by === 'price_desc') {
    $sql .= " ORDER BY g.base_price DESC";
} else {
    // Default proximity sort
    $sql .= " ORDER BY distance ASC";
}

// Radius filter — wrap as subquery to filter on the computed 'distance'
if ($radius_km !== 'any' && is_numeric($radius_km) && intval($radius_km) > 0) {
    $sql = "SELECT * FROM ({$sql}) AS subq WHERE subq.distance <= :radius_km";
    $params['radius_km'] = intval($radius_km);
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $grounds = $stmt->fetchAll();
} catch (Exception $e) {
    $grounds = [];
}

// Fetch open challenges
$open_challenges = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.slot_date, b.slot_hour, b.price, b.amount_paid,
               g.title AS ground_title, g.sport_type, g.address,
               u.name AS challenger_name
        FROM bookings b
        JOIN grounds g ON g.id = b.ground_id
        JOIN users u ON u.id = b.booked_by
        WHERE b.status = 'challenge_open'
        AND b.slot_date >= CURDATE()
        AND b.booked_by != ?
        ORDER BY b.slot_date ASC, b.slot_hour ASC
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $open_challenges = $stmt->fetchAll();
} catch (Exception $e) {
    $open_challenges = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explore Sports Grounds - ArenaReserve</title>
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
                <!-- Logo & Mobile Toggle -->
                <div class="flex-shrink-0 flex items-center gap-2">
                    <button type="button" onclick="toggleMobileMenu()" class="lg:hidden text-slate-500 hover:text-slate-700 focus:outline-none p-1 rounded-md" title="Toggle Navigation">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <span class="text-emerald-600 text-[12px] sm:text-xl md:text-2xl font-bold flex items-center flex-shrink-0 gap-1 sm:gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px] sm:h-7 sm:w-7 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z" />
                        </svg>
                        <span>ArenaReserve</span>
                    </span>
                </div>

                <!-- Right Side Actions -->
                <div class="flex-shrink-0 flex items-center gap-1 sm:gap-3">
                    <!-- Wallet Display -->
                    <a href="wallet.php" class="hidden sm:flex items-center bg-slate-100 hover:bg-slate-200 text-slate-800 px-3 py-1.5 rounded-full text-xs font-semibold border border-slate-200 transition-colors">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span>
                        Wallet: <?php echo number_format($available_balance, 2); ?> PKR
                    </a>

                    <!-- Mode Toggle -->
                    <div class="flex-shrink-0 flex items-center gap-1 bg-slate-100 p-1 rounded-full border border-slate-200/80 shadow-inner">
                        <a href="<?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'switch_role.php' : '#'; ?>" 
                           class="text-[11px] sm:text-xs font-semibold px-2.5 py-1.5 rounded-full transition-all duration-300 flex items-center gap-1 <?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'; ?>" title="Switch to Player Mode">
                           <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                           <span class="hidden sm:inline">Player</span>
                        </a>
                        <a href="<?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'switch_role.php' : '#'; ?>" 
                           class="text-[11px] sm:text-xs font-semibold px-2.5 py-1.5 rounded-full transition-all duration-300 flex items-center gap-1 <?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'; ?>" title="Switch to Owner Mode">
                           <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                           <span class="hidden sm:inline">Owner</span>
                        </a>
                    </div>

                    <!-- Notification -->
                    <button class="p-1 rounded-full text-slate-400 hover:text-slate-600 focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                    </button>

                    <!-- Profile Dropdown (Only Profile Settings & Logout) -->
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
                            <a href="<?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'owner_profile.php' : 'player_profile.php'; ?>" class="flex items-center px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-emerald-600 transition-colors">
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
                <a href="explore.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg">
                    <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                    My Wallet
                </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 min-w-0">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <div>
                <h1 class="text-2xl font-bold text-slate-900">Explore Sports Grounds</h1>
                <p class="text-sm text-slate-500">
                    Find and book nearby sports facilities
                    <?php if (!$using_default_location): ?>
                        &nbsp;·&nbsp;<span class="text-emerald-600 font-semibold">📍 Location Active</span>
                    <?php endif; ?>
                </p>
            </div>
                <!-- Wallet Display for Mobile -->
                <a href="wallet.php" class="sm:hidden flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-800 px-3 py-2 rounded-lg text-xs font-semibold border border-slate-200 transition-colors">
                    Wallet: <?php echo number_format($available_balance, 2); ?> PKR
                </a>
            </div>

            <!-- Location Permission Banner (shown when using default/fallback coordinates) -->
            <?php if ($using_default_location): ?>
            <div id="locationBanner" class="mb-4 flex items-center gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm">
                <svg class="h-5 w-5 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="text-amber-800 flex-1">📍 Enable your location for accurate proximity sorting &amp; distances.</span>
                <button onclick="requestPlayerLocation()" class="ml-auto flex-shrink-0 text-xs font-bold text-white bg-amber-500 hover:bg-amber-600 px-3 py-1.5 rounded-lg transition-colors">Enable Now</button>
                <button onclick="document.getElementById('locationBanner').remove()" class="text-amber-400 hover:text-amber-600 flex-shrink-0" aria-label="Dismiss">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <?php endif; ?>

            <!-- Search, Filter & Sort Form -->
            <form id="filterForm" action="explore.php" method="GET" class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6 space-y-4">
                <!-- Pass through player coordinates if we have real ones -->
                <?php if (!$using_default_location): ?>
                <input type="hidden" name="player_lat" value="<?php echo htmlspecialchars($player_lat); ?>">
                <input type="hidden" name="player_lng" value="<?php echo htmlspecialchars($player_lng); ?>">
                <?php endif; ?>

                <div class="flex flex-col md:flex-row gap-4">
                    <!-- Search Input -->
                    <div class="flex-1 relative">
                        <input id="searchInput" type="text" placeholder="Search grounds by name..."
                               class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg shadow-sm focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                        <div class="absolute left-3 top-2.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Filters and Sort -->
                    <div class="flex flex-wrap lg:flex-nowrap gap-3 items-center w-full lg:w-auto">
                        <!-- Near Me Button -->
                        <button type="button" id="nearMeBtn" onclick="requestPlayerLocation()"
                                class="flex items-center justify-center gap-1.5 px-3 py-2 w-full lg:w-auto <?php echo !$using_default_location ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-300'; ?> border text-xs font-semibold rounded-lg hover:bg-emerald-600 hover:text-white hover:border-emerald-600 transition-colors whitespace-nowrap">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <?php echo $using_default_location ? '📍 Near Me' : '✓ Near Me'; ?>
                        </button>

                        <!-- Radius Filter (only shown when location is active) -->
                        <?php if (!$using_default_location): ?>
                        <div class="flex items-center gap-2 w-full lg:w-auto">
                            <label for="radius_km" class="text-xs font-medium text-slate-500 whitespace-nowrap min-w-[70px] lg:min-w-0">Radius</label>
                            <select id="radius_km" name="radius_km" onchange="this.form.submit()"
                                    class="text-xs border border-slate-300 rounded-lg py-2 px-3 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 bg-white flex-1 lg:flex-initial w-full lg:w-auto">
                                <option value="any" <?php echo ($radius_km === 'any') ? 'selected' : ''; ?>>Any Distance</option>
                                <option value="5" <?php echo ($radius_km === '5') ? 'selected' : ''; ?>>Within 5 km</option>
                                <option value="10" <?php echo ($radius_km === '10') ? 'selected' : ''; ?>>Within 10 km</option>
                                <option value="25" <?php echo ($radius_km === '25') ? 'selected' : ''; ?>>Within 25 km</option>
                                <option value="50" <?php echo ($radius_km === '50') ? 'selected' : ''; ?>>Within 50 km</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Sport Type Filter -->
                        <div class="flex items-center gap-2 w-full lg:w-auto">
                            <label for="sport_type" class="text-xs font-medium text-slate-500 whitespace-nowrap min-w-[70px] lg:min-w-0">Sport Type</label>
                            <select id="sport_type" name="sport_type" onchange="this.form.submit()"
                                    class="text-xs border border-slate-300 rounded-lg py-2 px-3 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 bg-white flex-1 lg:flex-initial w-full lg:w-auto">
                                <option value="All" <?php echo ($sport_filter === 'All') ? 'selected' : ''; ?>>All Sports</option>
                                <option value="Football" <?php echo ($sport_filter === 'Football') ? 'selected' : ''; ?>>Football</option>
                                <option value="Cricket" <?php echo ($sport_filter === 'Cricket') ? 'selected' : ''; ?>>Cricket</option>
                                <option value="Basketball" <?php echo ($sport_filter === 'Basketball') ? 'selected' : ''; ?>>Basketball</option>
                                <option value="Badminton" <?php echo ($sport_filter === 'Badminton') ? 'selected' : ''; ?>>Badminton</option>
                                <option value="Futsal" <?php echo ($sport_filter === 'Futsal') ? 'selected' : ''; ?>>Futsal</option>
                            </select>
                        </div>

                        <!-- Sort By -->
                        <div class="flex items-center gap-2 w-full lg:w-auto">
                            <label for="sort_by" class="text-xs font-medium text-slate-500 whitespace-nowrap min-w-[70px] lg:min-w-0">Sort By</label>
                            <select id="sort_by" name="sort_by" onchange="this.form.submit()"
                                    class="text-xs border border-slate-300 rounded-lg py-2 px-3 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 bg-white flex-1 lg:flex-initial w-full lg:w-auto">
                                <option value="proximity" <?php echo ($sort_by === 'proximity') ? 'selected' : ''; ?>>Nearby Proximity</option>
                                <option value="price_asc" <?php echo ($sort_by === 'price_asc') ? 'selected' : ''; ?>>Price: Low to High</option>
                                <option value="price_desc" <?php echo ($sort_by === 'price_desc') ? 'selected' : ''; ?>>Price: High to Low</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Grounds Cards Grid -->
            <?php if (empty($grounds)): ?>
                <div class="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-500">
                    <svg class="mx-auto h-12 w-12 text-slate-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h3 class="text-lg font-medium text-slate-800">No grounds found</h3>
                    <p class="text-sm mt-1">Try adjusting your filters or search keywords.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="groundsGrid">
                    <?php foreach ($grounds as $ground): ?>
                        <div class="ground-card bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm hover:shadow-md transition-shadow duration-300"
                             data-title="<?php echo htmlspecialchars(strtolower($ground['title'])); ?>">
                            <!-- Image -->
                            <div class="h-48 bg-slate-100 relative">
                                <?php 
                                    $img_src = '';
                                    if (!empty($ground['image_path']) && file_exists(__DIR__ . '/' . $ground['image_path'])) {
                                        $img_src = htmlspecialchars($ground['image_path']);
                                    } else {
                                        if (stripos($ground['title'], 'basketball') !== false) {
                                            $img_src = 'assets/images/basketball.png';
                                        } else if (stripos($ground['title'], 'football') !== false || stripos($ground['title'], 'stadium a') !== false) {
                                            $img_src = 'assets/images/football.png';
                                        } else if (stripos($ground['title'], 'cricket') !== false) {
                                            $img_src = 'assets/images/cricket.png';
                                        } else {
                                            $st = strtolower($ground['sport_type']);
                                            if (strpos($st, 'basketball') !== false) {
                                                $img_src = 'assets/images/basketball.png';
                                            } else if (strpos($st, 'cricket') !== false) {
                                                $img_src = 'assets/images/cricket.png';
                                            } else {
                                                $img_src = 'assets/images/football.png';
                                            }
                                        }
                                    }
                                ?>
                                <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($ground['title']); ?>" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='assets/images/football.png';">
                                <span class="absolute top-3 right-3 bg-white/95 text-slate-800 font-semibold text-[10px] uppercase px-2 py-1 rounded shadow-sm">
                                    <?php echo htmlspecialchars($ground['sport_type']); ?>
                                </span>
                            </div>

                            <!-- Card Details -->
                            <div class="p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-bold text-slate-900 text-sm hover:text-emerald-600 transition-colors">
                                        <?php echo htmlspecialchars($ground['title']); ?>
                                    </h3>
                                    <!-- Star rating -->
                                    <div class="flex items-center text-xs font-semibold text-amber-500 bg-amber-50 px-1.5 py-0.5 rounded">
                                        ★ 4.8
                                    </div>
                                </div>

                                <p class="text-xs text-slate-500 mb-4 flex items-center">
                                    <svg class="h-4 w-4 text-slate-400 mr-1 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    </svg>
                                    <span class="truncate"><?php echo htmlspecialchars($ground['address']); ?></span>
                                </p>

                                <div class="flex justify-between items-center border-t border-slate-100 pt-3">
                                    <div>
                                        <div class="text-slate-400 text-[10px] uppercase font-semibold">Hourly Rate</div>
                                        <div class="font-bold text-slate-800 text-sm">
                                            <?php echo number_format($ground['base_price']); ?> PKR<span class="text-slate-400 font-normal text-xs">/hr</span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] uppercase font-semibold
                                            <?php
                                                $d = floatval($ground['distance']);
                                                if ($d < 3) echo 'text-emerald-600';
                                                elseif ($d < 10) echo 'text-amber-500';
                                                else echo 'text-slate-400';
                                            ?>
                                        ">Distance</div>
                                        <?php
                                            $d = floatval($ground['distance']);
                                            if ($d < 3) {
                                                $badge_class = 'bg-emerald-100 text-emerald-700';
                                                $label = '🟢';
                                            } elseif ($d < 10) {
                                                $badge_class = 'bg-amber-100 text-amber-700';
                                                $label = '🟡';
                                            } else {
                                                $badge_class = 'bg-slate-100 text-slate-600';
                                                $label = '⚪';
                                            }
                                        ?>
                                        <div class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full <?php echo $badge_class; ?>">
                                            <?php echo $label; ?> <?php echo number_format($ground['distance'], 1); ?> km away
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="mt-4 flex gap-2">
                                    <a href="book_slot.php?ground=<?php echo $ground['id']; ?>"
                                       class="flex-1 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm hover:shadow transition-all text-center">
                                        Book Now
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- ============================================================
         OPEN CHALLENGES SECTION
    ============================================================ -->
    <?php if (!empty($open_challenges)): ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
        <!-- Header -->
        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-violet-600 flex items-center justify-center flex-shrink-0">
                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-slate-900">⚡ Open Challenges</h2>
                <p class="text-sm text-slate-500">Accept a challenge — pay your 50% to confirm the match!</p>
            </div>
            <span class="ml-auto bg-violet-100 text-violet-700 text-xs font-bold px-3 py-1.5 rounded-full"><?php echo count($open_challenges); ?> open</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($open_challenges as $ch):
            $h = intval($ch['slot_hour']);
            $suffix   = $h < 12 ? 'AM' : 'PM';
            $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
            $nextH    = $h + 1;
            $nextDisp = $nextH > 12 ? $nextH - 12 : ($nextH === 0 ? 12 : $nextH);
            $nextSuf  = $nextH < 12 ? 'AM' : 'PM';
            $time_lbl = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuf);
            $opponent_cost = round(floatval($ch['price']) * 0.5, 0);
            $sport_badge_colors = [
                'Football'  =>'bg-green-100 text-green-700',
                'Cricket'   =>'bg-sky-100 text-sky-700',
                'Basketball'=>'bg-orange-100 text-orange-700',
                'Badminton' =>'bg-violet-100 text-violet-700',
                'Futsal'    =>'bg-rose-100 text-rose-700',
            ];
            $badge = $sport_badge_colors[$ch['sport_type']] ?? 'bg-slate-100 text-slate-700';
        ?>
        <div class="bg-white border border-violet-200 rounded-xl shadow-sm hover:shadow-md hover:border-violet-400 transition-all duration-200 overflow-hidden">
            <!-- Top accent -->
            <div class="h-1.5 bg-gradient-to-r from-violet-500 to-purple-600"></div>
            <div class="p-5">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($ch['ground_title']); ?></h3>
                        <p class="text-xs text-slate-500 mt-0.5 flex items-center gap-1">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                            <?php echo htmlspecialchars($ch['address']); ?>
                        </p>
                    </div>
                    <span class="text-[10px] font-bold px-2 py-1 rounded-full <?php echo $badge; ?>"><?php echo $ch['sport_type']; ?></span>
                </div>

                <div class="flex items-center gap-3 text-xs text-slate-600 mb-3 bg-slate-50 rounded-lg p-3">
                    <div class="flex items-center gap-1">
                        <svg class="h-4 w-4 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span class="font-semibold"><?php echo date('D, d M', strtotime($ch['slot_date'])); ?></span>
                    </div>
                    <div class="flex items-center gap-1">
                        <svg class="h-4 w-4 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2"/></svg>
                        <span class="font-semibold"><?php echo $time_lbl; ?></span>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">Challenger</div>
                        <div class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($ch['challenger_name']); ?></div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] text-slate-400 uppercase font-semibold">You Pay</div>
                        <div class="text-base font-extrabold text-violet-700"><?php echo number_format($opponent_cost); ?> PKR</div>
                        <div class="text-[10px] text-slate-400">50% of <?php echo number_format($ch['price']); ?></div>
                    </div>
                </div>

                <button onclick="openAcceptModal(<?php echo $ch['id']; ?>, '<?php echo addslashes($ch['ground_title']); ?>', '<?php echo $time_lbl; ?>', <?php echo $opponent_cost; ?>, '<?php echo date('D d M', strtotime($ch['slot_date'])); ?>')"
                        class="mt-4 w-full bg-violet-600 hover:bg-violet-700 text-white text-sm font-bold py-2.5 rounded-xl transition-all shadow-sm hover:shadow-md">
                    ⚡ Accept Challenge
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Accept Challenge Modal -->
    <div id="accept-overlay" style="display:none" class="fixed inset-0 bg-black/55 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">
        <div class="bg-gradient-to-r from-violet-600 to-purple-700 px-6 py-5 text-white">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs opacity-80 mb-0.5">Accepting Challenge</div>
              <h3 class="text-xl font-extrabold" id="acc-ground">Ground</h3>
            </div>
            <button onclick="closeAcceptModal()" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>
        <div class="p-6">
          <div class="bg-violet-50 rounded-xl p-4 mb-4 space-y-2 text-sm border border-violet-200">
            <div class="flex justify-between"><span class="text-slate-500">Time Slot</span><span class="font-semibold text-slate-800" id="acc-time">--</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="acc-date">--</span></div>
            <div class="border-t border-violet-200 pt-2 flex justify-between">
              <span class="font-bold text-slate-700">Your Payment (50%)</span>
              <span class="font-extrabold text-violet-700 text-base" id="acc-cost">-- PKR</span>
            </div>
            <div class="flex justify-between text-xs">
              <span class="text-slate-400">Your Wallet</span>
              <span class="font-semibold text-slate-600"><?php echo number_format($available_balance, 0); ?> PKR</span>
            </div>
          </div>
          <div class="bg-violet-100 rounded-xl p-3 text-xs text-violet-700 mb-5">
            By accepting, you confirm your <?php echo '50%'; ?> share. The full match slot will be locked for both teams.
          </div>
          <div class="flex gap-3">
            <button onclick="closeAcceptModal()" class="flex-1 py-2.5 border border-slate-300 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50">Cancel</button>
            <button onclick="confirmAccept()" id="acc-btn" class="flex-1 py-2.5 bg-violet-600 hover:bg-violet-700 text-white text-sm font-bold rounded-xl shadow transition-all">⚡ Pay & Accept</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast -->
    <div id="exp-toast" style="position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:14px;font-weight:500;box-shadow:0 8px 30px rgba(0,0,0,.3);transform:translateX(120%);transition:transform .3s cubic-bezier(.34,1.56,.64,1);display:flex;align-items:center;gap:8px;max-width:360px;color:white;"></div>

    <!-- Client-side real-time filter script -->
    <script>
        const searchInput = document.getElementById('searchInput');
        const cards = document.querySelectorAll('.ground-card');

        searchInput.addEventListener('keyup', function(e) {
            const query = e.target.value.toLowerCase().trim();
            let visibleCount = 0;

            cards.forEach(card => {
                const title = card.getAttribute('data-title');
                if (title.includes(query)) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // =============================================
        // PLAYER LOCATION DETECTION
        // =============================================
        function requestPlayerLocation() {
            if (!navigator.geolocation) {
                showExpToast('❌ Geolocation is not supported by your browser.', '#dc2626');
                return;
            }

            const btn = document.getElementById('nearMeBtn');
            if (btn) { btn.textContent = '⏳ Detecting...'; btn.disabled = true; }

            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    const lat = pos.coords.latitude.toFixed(6);
                    const lng = pos.coords.longitude.toFixed(6);

                    // Cache in sessionStorage so we don't re-prompt on every filter change
                    sessionStorage.setItem('player_lat', lat);
                    sessionStorage.setItem('player_lng', lng);

                    // Build URL preserving existing filters
                    const url = new URL(window.location.href);
                    url.searchParams.set('player_lat', lat);
                    url.searchParams.set('player_lng', lng);
                    url.searchParams.set('sort_by', 'proximity');
                    window.location.href = url.toString();
                },
                function(err) {
                    if (btn) { btn.textContent = '📍 Near Me'; btn.disabled = false; }
                    let msg = 'Could not get your location.';
                    if (err.code === err.PERMISSION_DENIED) {
                        msg = '❌ Location permission denied. Please allow location access in your browser settings.';
                    } else if (err.code === err.POSITION_UNAVAILABLE) {
                        msg = '❌ Location unavailable. Check your device GPS/network.';
                    } else if (err.code === err.TIMEOUT) {
                        msg = '❌ Location request timed out. Please try again.';
                    }
                    showExpToast(msg, '#dc2626');
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
            );
        }

        // On page load: if no real location in URL but we have it in sessionStorage, auto-inject it
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.has('player_lat')) {
                const cachedLat = sessionStorage.getItem('player_lat');
                const cachedLng = sessionStorage.getItem('player_lng');
                if (cachedLat && cachedLng) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('player_lat', cachedLat);
                    url.searchParams.set('player_lng', cachedLng);
                    // Redirect to inject cached coordinates
                    window.location.replace(url.toString());
                }
            }
        })();

        // ----- Accept Challenge -----
        let acceptBookingId = null;

        function openAcceptModal(id, ground, time, cost, date) {
            acceptBookingId = id;
            document.getElementById('acc-ground').textContent = ground;
            document.getElementById('acc-time').textContent   = time;
            document.getElementById('acc-date').textContent   = date;
            document.getElementById('acc-cost').textContent   = parseInt(cost).toLocaleString() + ' PKR';
            document.getElementById('accept-overlay').style.display = 'flex';
        }
        function closeAcceptModal() {
            document.getElementById('accept-overlay').style.display = 'none';
        }
        function confirmAccept() {
            if (!acceptBookingId) return;
            const btn = document.getElementById('acc-btn');
            btn.disabled = true;
            btn.textContent = 'Processing…';

            fetch('accept_challenge.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'booking_id=' + acceptBookingId
            })
            .then(r => r.json())
            .then(res => {
                closeAcceptModal();
                if (res.success) {
                    showExpToast(res.message, '#059669');
                    setTimeout(() => location.reload(), 2500);
                } else {
                    showExpToast('❌ ' + res.message, '#dc2626');
                    btn.disabled = false;
                    btn.textContent = '⚡ Pay & Accept';
                }
            })
            .catch(() => { showExpToast('❌ Network error.', '#dc2626'); btn.disabled=false; btn.textContent='⚡ Pay & Accept'; });
        }

        function toggleProfileDropdown() {
            const menu = document.getElementById('profileDropdownMenu');
            if (!menu) return;
            if (menu.classList.contains('hidden')) {
                menu.classList.remove('hidden');
                setTimeout(() => {
                    menu.classList.remove('opacity-0', 'scale-95');
                    menu.classList.add('opacity-100', 'scale-100');
                }, 10);
            } else {
                menu.classList.remove('opacity-100', 'scale-100');
                menu.classList.add('opacity-0', 'scale-95');
                setTimeout(() => menu.classList.add('hidden'), 150);
            }
        }

        function toggleMobileMenu() {
            const menu = document.getElementById('mobileNavigationMenu');
            if (menu) menu.classList.toggle('hidden');
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
    </script>
</body>
</html>
