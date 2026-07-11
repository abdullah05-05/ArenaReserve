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

// Default player coordinates (Karachi DHA/Clifton area center for realistic demo)
$player_lat = 24.8607;
$player_lng = 67.0011;

// Get filters
$sport_filter = $_GET['sport_type'] ?? 'All';
$sort_by = $_GET['sort_by'] ?? 'proximity';

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

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $grounds = $stmt->fetchAll();
} catch (Exception $e) {
    $grounds = [];
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
                    <a href="wallet.php" class="hidden sm:flex items-center bg-slate-100 hover:bg-slate-200 text-slate-800 px-3 py-1.5 rounded-full text-xs font-semibold border border-slate-200 transition-colors">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span>
                        Wallet: <?php echo number_format($available_balance, 2); ?> PKR
                    </a>

                    <!-- Mode Toggle -->
                    <div class="flex items-center gap-2 bg-slate-100 p-1 rounded-lg border border-slate-200">
                        <span class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?>">Player</span>
                        <a href="switch_role.php" class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?> hover:text-slate-800 transition-colors">Owner</a>
                    </div>

                    <!-- Notification -->
                    <button class="p-1 rounded-full text-slate-400 hover:text-slate-600 focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                    </button>

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
                    <p class="text-sm text-slate-500">Find and book nearby sports facilities</p>
                </div>
                <!-- Wallet Display for Mobile -->
                <a href="wallet.php" class="sm:hidden flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-800 px-3 py-2 rounded-lg text-xs font-semibold border border-slate-200 transition-colors">
                    Wallet: <?php echo number_format($available_balance, 2); ?> PKR
                </a>
            </div>

            <!-- Search, Filter & Sort Form -->
            <form id="filterForm" action="explore.php" method="GET" class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6 space-y-4">
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
                    <div class="flex flex-wrap md:flex-nowrap gap-3">
                        <!-- Sport Type Filter -->
                        <div class="flex items-center gap-2">
                            <label for="sport_type" class="text-xs font-medium text-slate-500 whitespace-nowrap">Sport Type</label>
                            <select id="sport_type" name="sport_type" onchange="this.form.submit()"
                                    class="text-xs border border-slate-300 rounded-lg py-2 px-3 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                                <option value="All" <?php echo ($sport_filter === 'All') ? 'selected' : ''; ?>>All Sports</option>
                                <option value="Football" <?php echo ($sport_filter === 'Football') ? 'selected' : ''; ?>>Football</option>
                                <option value="Cricket" <?php echo ($sport_filter === 'Cricket') ? 'selected' : ''; ?>>Cricket</option>
                                <option value="Basketball" <?php echo ($sport_filter === 'Basketball') ? 'selected' : ''; ?>>Basketball</option>
                                <option value="Badminton" <?php echo ($sport_filter === 'Badminton') ? 'selected' : ''; ?>>Badminton</option>
                                <option value="Futsal" <?php echo ($sport_filter === 'Futsal') ? 'selected' : ''; ?>>Futsal</option>
                            </select>
                        </div>

                        <!-- Sort By -->
                        <div class="flex items-center gap-2">
                            <label for="sort_by" class="text-xs font-medium text-slate-500 whitespace-nowrap">Sort By</label>
                            <select id="sort_by" name="sort_by" onchange="this.form.submit()"
                                    class="text-xs border border-slate-300 rounded-lg py-2 px-3 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 bg-white">
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
                                    $img_src = !empty($ground['image_path']) ? htmlspecialchars($ground['image_path']) : 'assets/images/basketball.png';
                                    // Map seeded names to local images if path is empty
                                    if (empty($ground['image_path'])) {
                                        if (stripos($ground['title'], 'basketball') !== false) {
                                            $img_src = 'assets/images/basketball.png';
                                        } else if (stripos($ground['title'], 'football') !== false || stripos($ground['title'], 'stadium a') !== false) {
                                            $img_src = 'assets/images/football.png';
                                        } else if (stripos($ground['title'], 'cricket') !== false) {
                                            $img_src = 'assets/images/cricket.png';
                                        }
                                    }
                                ?>
                                <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($ground['title']); ?>" class="w-full h-full object-cover">
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
                                        <div class="text-slate-400 text-[10px] uppercase font-semibold">Proximity</div>
                                        <div class="text-xs font-medium text-slate-600">
                                            <?php echo number_format($ground['distance'], 1); ?> km away
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="mt-4 flex gap-2">
                                    <button onclick="alert('Booking slot functionality is not yet active for booking. This represents the next phase of implementation.')"
                                            class="flex-1 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm hover:shadow transition-all text-center">
                                        Book Now
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

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
    </script>
</body>
</html>
