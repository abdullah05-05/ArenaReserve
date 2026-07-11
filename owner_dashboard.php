<?php
session_start();
require_once 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success_msg = '';

if (isset($_GET['registered'])) {
    $success_msg = 'Venue registration submitted successfully! Pending administrative review and verification.';
}

// Fetch owner venues with rejection reasons and status
try {
    $stmt = $pdo->prepare(
        "SELECT g.*, COALESCE(op.rejection_reason, '') as rejection_reason,
                COALESCE(g.ground_status, 'Active') as ground_status,
                COALESCE(g.block_reason, '') as block_reason
         FROM grounds g
         LEFT JOIN onboarding_packages op ON g.id = op.ground_id
         WHERE g.owner_id = ? ORDER BY g.created_at DESC"
    );
    $stmt->execute([$user_id]);
    $venues = $stmt->fetchAll();
    
    $total_venues = count($venues);
    
    // Stats
    $active_bookings = 14;
    $weekly_revenue = 46000;
} catch (Exception $e) {
    $venues = [];
    $total_venues = 0;
    $active_bookings = 0;
    $weekly_revenue = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
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
                    <!-- Mode Toggle -->
                    <div class="flex items-center gap-2 bg-slate-100 p-1 rounded-lg border border-slate-200">
                        <a href="switch_role.php" class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?> hover:text-slate-800 transition-colors">Player</a>
                        <span class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?>">Owner</span>
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
                <a href="owner_dashboard.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg">
                    <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                <a href="owner_scores.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Score Entry
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 min-w-0">
            <!-- Alert Banner -->
            <?php if (!empty($success_msg)): ?>
                <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 text-sm text-green-700 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <!-- Success Checkmark -->
                            <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="font-semibold"><?php echo htmlspecialchars($success_msg); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">My Venues</h1>
                    <p class="text-sm text-slate-500">Manage all your sports facilities</p>
                </div>
                <a href="add_ground.php" class="py-2 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all flex items-center">
                    + Add New Venue
                </a>
            </div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                <!-- Total Venues -->
                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-400 uppercase font-semibold">Total Venues</div>
                    <div class="text-2xl font-extrabold text-slate-800 mt-1"><?php echo $total_venues; ?></div>
                </div>

                <!-- Active Bookings -->
                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-400 uppercase font-semibold">Active Bookings</div>
                    <div class="text-2xl font-extrabold text-slate-800 mt-1"><?php echo $active_bookings; ?></div>
                </div>

                <!-- Weekly Revenue -->
                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                    <div class="text-xs text-slate-400 uppercase font-semibold">Weekly Revenue</div>
                    <div class="text-2xl font-extrabold text-slate-800 mt-1"><?php echo number_format($weekly_revenue); ?> PKR</div>
                </div>
            </div>

            <!-- Venues Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($venues as $venue): ?>
                    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col justify-between">
                        <!-- Image & Status Overlay -->
                        <div class="h-44 bg-slate-100 relative">
                            <img src="<?php echo htmlspecialchars($venue['image_path']); ?>" alt="<?php echo htmlspecialchars($venue['title']); ?>" class="w-full h-full object-cover">
                            <!-- Status Badges -->
                            <?php if ($venue['is_verified'] == 2): ?>
                                <span class="absolute top-3 right-3 bg-red-100 text-red-700 text-[10px] font-bold px-2 py-1 rounded shadow-sm">Rejected</span>
                            <?php elseif ($venue['is_verified'] == 1): ?>
                                <?php
                                    $gs = $venue['ground_status'] ?? 'Active';
                                    $gsBadge = ['Active' => 'bg-green-100 text-green-800', 'Suspended' => 'bg-amber-100 text-amber-800', 'Blocked' => 'bg-red-100 text-red-800'][$gs] ?? 'bg-green-100 text-green-800';
                                ?>
                                <span class="absolute top-3 right-3 <?php echo $gsBadge; ?> text-[10px] font-bold px-2 py-1 rounded shadow-sm"><?php echo $gs; ?></span>
                            <?php else: ?>
                                <span class="absolute top-3 right-3 bg-amber-100 text-amber-800 text-[10px] font-bold px-2 py-1 rounded shadow-sm">Pending Review</span>
                            <?php endif; ?>
                        </div>

                        <!-- Rejection Reason Banner -->
                        <?php if ($venue['is_verified'] == 2 && !empty($venue['rejection_reason'])): ?>
                            <div class="bg-red-50 border-l-4 border-red-400 px-4 py-2.5">
                                <p class="text-xs font-bold text-red-700 mb-0.5">❌ Rejected by Admin:</p>
                                <p class="text-xs text-red-600"><?php echo htmlspecialchars($venue['rejection_reason']); ?></p>
                            </div>
                        <?php endif; ?>

                        <!-- Block Reason Banner (shown when admin blocks venue) -->
                        <?php if ($venue['is_verified'] == 1 && ($venue['ground_status'] ?? '') === 'Blocked' && !empty($venue['block_reason'])): ?>
                            <div class="bg-amber-50 border-l-4 border-amber-400 px-4 py-2.5">
                                <p class="text-xs font-bold text-amber-700 mb-0.5">🚫 Venue Blocked by Admin:</p>
                                <p class="text-xs text-amber-700"><?php echo htmlspecialchars($venue['block_reason']); ?></p>
                                <p class="text-[10px] text-amber-600 mt-1">Your venue is currently hidden from the public. Contact support to resolve this.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Info -->
                        <div class="p-4 flex-1 flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-bold text-slate-800 text-sm truncate max-w-[180px]"><?php echo htmlspecialchars($venue['title']); ?></h3>
                                    <span class="text-[10px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded font-semibold uppercase"><?php echo htmlspecialchars($venue['sport_type']); ?></span>
                                </div>
                                <p class="text-xs text-slate-500 mb-3 truncate"><?php echo htmlspecialchars($venue['address']); ?></p>
                            </div>

                            <div class="border-t border-slate-100 pt-3 flex justify-between items-center text-xs">
                                <div>
                                    <span class="text-slate-400 font-medium">Hourly Rate:</span>
                                    <span class="font-bold text-slate-700 ml-1"><?php echo number_format($venue['base_price']); ?> PKR</span>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="mt-4 flex gap-2">
                                <button class="flex-1 py-1.5 border border-slate-200 text-slate-700 text-xs font-semibold rounded-lg hover:bg-slate-50 transition-colors">Manage</button>
                                <?php if ($venue['is_verified'] == 2): ?>
                                    <a href="add_ground.php" class="flex-1 text-center py-1.5 border border-emerald-300 text-emerald-700 text-xs font-semibold rounded-lg hover:bg-emerald-50 transition-colors">Re-submit</a>
                                <?php else: ?>
                                    <button class="flex-1 py-1.5 border border-red-200 text-red-600 text-xs font-semibold rounded-lg hover:bg-red-50 transition-colors">Details</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Add New Venue Card (Dashed Link Box) -->
                <a href="add_ground.php" class="border-2 border-dashed border-slate-300 rounded-xl hover:border-emerald-500 transition-colors duration-300 p-6 flex flex-col items-center justify-center text-slate-400 hover:text-emerald-600 h-64">
                    <svg class="h-10 w-10 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-sm font-semibold">Add New Venue</span>
                    <span class="text-[10px] text-slate-400 mt-1">List another facility</span>
                </a>
            </div>
        </main>
    </div>
</body>
</html>
