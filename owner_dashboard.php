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

        /* Modal Backdrop */
        .modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
            opacity: 0; pointer-events: none;
            transition: opacity 0.25s ease;
        }
        .modal-backdrop.open {
            opacity: 1; pointer-events: all;
        }
        .modal-box {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.25);
            width: 100%; max-width: 860px;
            max-height: 92vh;
            overflow-y: auto;
            transform: translateY(30px) scale(0.97);
            transition: transform 0.25s ease;
        }
        .modal-backdrop.open .modal-box {
            transform: translateY(0) scale(1);
        }

        /* Slot Table */
        .slot-table th {
            background: #f1f5f9;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
        }
        .slot-table td {
            font-size: 13px;
            vertical-align: middle;
        }
        .slot-table tr:hover td {
            background: #f8fafc;
        }
        .slot-row.available td { }
        .slot-row.unavailable td {
            opacity: 0.45;
        }

        /* Custom checkbox */
        .slot-check {
            width: 18px; height: 18px;
            accent-color: #10b981;
            cursor: pointer;
        }

        /* Slot type select */
        .slot-type-select {
            border: 1.5px solid #e2e8f0;
            border-radius: 7px;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            transition: border-color .15s;
        }
        .slot-type-select:focus { border-color: #10b981; }
        .slot-type-select.peak { background: #fef3c7; color: #92400e; }
        .slot-type-select.normal { background: #ecfdf5; color: #065f46; }

        /* Price input */
        .price-input {
            border: 1.5px solid #e2e8f0;
            border-radius: 7px;
            padding: 5px 8px;
            width: 110px;
            font-size: 13px;
            outline: none;
            transition: border-color .15s;
        }
        .price-input:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.12); }

        /* Save btn pulse */
        @keyframes pulse-green {
            0%,100% { box-shadow: 0 0 0 0 rgba(16,185,129,.5); }
            50% { box-shadow: 0 0 0 8px rgba(16,185,129,0); }
        }
        .btn-save { animation: pulse-green 2.5s infinite; }

        /* Toast */
        #toast {
            position: fixed; bottom: 28px; right: 28px;
            background: #1e293b; color: #fff;
            padding: 12px 20px; border-radius: 10px;
            font-size: 13px; font-weight: 500;
            z-index: 9999;
            transform: translateY(20px); opacity: 0;
            transition: all .3s ease;
            display: flex; align-items: center; gap: 8px;
        }
        #toast.show { transform: translateY(0); opacity: 1; }
        #toast.success { background: #064e3b; border-left: 4px solid #10b981; }
        #toast.error { background: #7f1d1d; border-left: 4px solid #ef4444; }

        /* Detail form inputs */
        .detail-input {
            border: 1.5px solid #e2e8f0; border-radius: 8px;
            padding: 9px 12px; width: 100%; font-size: 14px;
            outline: none; transition: border-color .15s, box-shadow .15s;
            font-family: 'Inter', sans-serif;
        }
        .detail-input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,.12);
        }
        .detail-label {
            font-size: 12px; font-weight: 600; color: #475569;
            margin-bottom: 4px; display: block;
        }

        /* Scrollbar */
        .modal-box::-webkit-scrollbar { width: 6px; }
        .modal-box::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
        .modal-box::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
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

                        <!-- Block Reason Banner -->
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
                                    <span class="text-slate-400 font-medium">Base / Peak:</span>
                                    <span class="font-bold text-slate-700 ml-1"><?php echo number_format($venue['base_price']); ?> / <?php echo number_format($venue['peak_price']); ?> PKR</span>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="mt-4 flex gap-2">
                                <button
                                    onclick="openManageModal(<?php echo $venue['id']; ?>, '<?php echo addslashes($venue['title']); ?>')"
                                    class="flex-1 py-1.5 border border-slate-200 text-slate-700 text-xs font-semibold rounded-lg hover:bg-slate-50 hover:border-slate-300 transition-all flex items-center justify-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    Manage
                                </button>
                                <?php if ($venue['is_verified'] == 2): ?>
                                    <a href="add_ground.php" class="flex-1 text-center py-1.5 border border-emerald-300 text-emerald-700 text-xs font-semibold rounded-lg hover:bg-emerald-50 transition-colors">Re-submit</a>
                                <?php else: ?>
                                    <button
                                        onclick="openDetailsModal(<?php echo $venue['id']; ?>, '<?php echo addslashes($venue['title']); ?>')"
                                        class="flex-1 py-1.5 border border-emerald-200 text-emerald-700 text-xs font-semibold rounded-lg hover:bg-emerald-50 hover:border-emerald-300 transition-all flex items-center justify-center gap-1.5">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        Details
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Add New Venue Card -->
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

    <!-- ============================================================ -->
    <!--  MANAGE MODAL (Time Slots)                                   -->
    <!-- ============================================================ -->
    <div id="manageModal" class="modal-backdrop" onclick="closeModal('manageModal', event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Manage Time Slots</h2>
                    <p id="manageModalSubtitle" class="text-xs text-slate-400 mt-0.5">Configure availability and pricing for each hour</p>
                </div>
                <button onclick="closeModal('manageModal')" class="text-slate-400 hover:text-slate-600 transition-colors p-1 rounded-lg hover:bg-slate-100">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Legend + Bulk Actions -->
            <div class="px-6 pt-4 pb-2 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                    <span class="text-xs text-slate-500 font-medium">Available (shown on site)</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-full bg-slate-300 inline-block"></span>
                    <span class="text-xs text-slate-500 font-medium">Unavailable (hidden)</span>
                </div>
                <div class="ml-auto flex gap-2">
                    <button onclick="selectAllSlots(true)" class="text-[11px] font-semibold px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-lg hover:bg-emerald-100 transition-colors border border-emerald-200">✓ Select All</button>
                    <button onclick="selectAllSlots(false)" class="text-[11px] font-semibold px-3 py-1.5 bg-slate-50 text-slate-600 rounded-lg hover:bg-slate-100 transition-colors border border-slate-200">✗ Deselect All</button>
                </div>
            </div>

            <!-- Slot Table -->
            <div class="px-6 pb-2 overflow-x-auto">
                <div id="manageModalLoader" class="text-center py-10 text-slate-400 text-sm">
                    <svg class="animate-spin w-7 h-7 mx-auto mb-3 text-emerald-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    Loading slot data...
                </div>
                <table id="slotTable" class="slot-table w-full border-collapse" style="display:none">
                    <thead>
                        <tr>
                            <th class="py-2 px-3 text-left rounded-tl-lg">Available</th>
                            <th class="py-2 px-3 text-left">Time Slot</th>
                            <th class="py-2 px-3 text-left">Period</th>
                            <th class="py-2 px-3 text-left">Slot Type</th>
                            <th class="py-2 px-3 text-left rounded-tr-lg">Price (PKR)</th>
                        </tr>
                    </thead>
                    <tbody id="slotTableBody"></tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between">
                <p class="text-xs text-slate-400">
                    <span id="availableCount" class="font-bold text-slate-700">0</span> slots available
                </p>
                <div class="flex gap-3">
                    <button onclick="closeModal('manageModal')" class="px-5 py-2 text-sm text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 font-medium transition-colors">Cancel</button>
                    <button id="saveManageBtn" onclick="saveSlots()" class="btn-save px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  DETAILS MODAL (Venue Edit)                                  -->
    <!-- ============================================================ -->
    <div id="detailsModal" class="modal-backdrop" onclick="closeModal('detailsModal', event)">
        <div class="modal-box max-w-lg" onclick="event.stopPropagation()">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Venue Details</h2>
                    <p id="detailsModalSubtitle" class="text-xs text-slate-400 mt-0.5">Edit and update your venue information</p>
                </div>
                <button onclick="closeModal('detailsModal')" class="text-slate-400 hover:text-slate-600 transition-colors p-1 rounded-lg hover:bg-slate-100">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Loader -->
            <div id="detailsModalLoader" class="text-center py-10 text-slate-400 text-sm">
                <svg class="animate-spin w-7 h-7 mx-auto mb-3 text-emerald-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                Loading venue details...
            </div>

            <!-- Detail Form -->
            <div id="detailsForm" class="px-6 py-5 space-y-4" style="display:none">
                <input type="hidden" id="detail_ground_id">

                <div class="grid grid-cols-1 gap-4">
                    <!-- Venue Name -->
                    <div>
                        <label class="detail-label">Venue Name <span class="text-red-400">*</span></label>
                        <input type="text" id="detail_title" class="detail-input" placeholder="e.g. Star Cricket Ground">
                    </div>

                    <!-- Sport Type -->
                    <div>
                        <label class="detail-label">Sport Type <span class="text-red-400">*</span></label>
                        <select id="detail_sport_type" class="detail-input" style="cursor:pointer">
                            <option value="Cricket">Cricket</option>
                            <option value="Football">Football</option>
                            <option value="Basketball">Basketball</option>
                            <option value="Tennis">Tennis</option>
                            <option value="Badminton">Badminton</option>
                            <option value="Hockey">Hockey</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <!-- Address -->
                    <div>
                        <label class="detail-label">Address <span class="text-red-400">*</span></label>
                        <textarea id="detail_address" class="detail-input" rows="2" placeholder="Full venue address"></textarea>
                    </div>

                    <!-- Prices Row -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="detail-label">
                                Base Price (PKR) <span class="text-red-400">*</span>
                                <span class="text-slate-400 font-normal ml-1">(Normal hours)</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-semibold">PKR</span>
                                <input type="number" id="detail_base_price" class="detail-input" style="padding-left:42px" placeholder="0" min="0">
                            </div>
                        </div>
                        <div>
                            <label class="detail-label">
                                Peak Price (PKR) <span class="text-red-400">*</span>
                                <span class="text-slate-400 font-normal ml-1">(Peak hours)</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-semibold">PKR</span>
                                <input type="number" id="detail_peak_price" class="detail-input" style="padding-left:42px" placeholder="0" min="0">
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="detail-label">Description <span class="text-slate-400 font-normal">(optional)</span></label>
                        <textarea id="detail_description" class="detail-input" rows="3" placeholder="Describe your venue — facilities, rules, special features..."></textarea>
                    </div>
                </div>

                <!-- Info Box -->
                <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 flex gap-3">
                    <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-xs text-amber-700">
                        <strong>Note:</strong> Changes to venue name and details are visible to players immediately. Price changes apply to new bookings only and are subject to admin review.
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div id="detailsFooter" class="px-6 py-4 border-t border-slate-100 flex items-center justify-end gap-3" style="display:none">
                <button onclick="closeModal('detailsModal')" class="px-5 py-2 text-sm text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 font-medium transition-colors">Cancel</button>
                <button id="saveDetailsBtn" onclick="saveDetails()" class="btn-save px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg transition-all flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast">
        <svg id="toastIcon" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <span id="toastMsg"></span>
    </div>

    <script>
    // ========================================================
    //  STATE
    // ========================================================
    let currentGroundId = null;
    let groundBasePrice = 0;
    let groundPeakPrice = 0;

    const PERIODS = ['Midnight','Night','Night','Night','Early Morning','Early Morning','Early Morning','Morning','Morning','Morning','Morning','Midday','Midday','Afternoon','Afternoon','Afternoon','Evening','Evening','Evening','Night','Night','Night','Late Night','Late Night'];

    // ========================================================
    //  TOAST
    // ========================================================
    function showToast(msg, type = 'success') {
        const toast = document.getElementById('toast');
        const icon = document.getElementById('toastIcon');
        const msgEl = document.getElementById('toastMsg');
        toast.className = `show ${type}`;
        msgEl.textContent = msg;
        if (type === 'success') {
            icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>';
        } else {
            icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>';
        }
        setTimeout(() => { toast.className = ''; }, 3800);
    }

    // ========================================================
    //  MODAL HELPERS
    // ========================================================
    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id, event) {
        if (event && event.target !== document.getElementById(id)) return;
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    // ========================================================
    //  FORMAT TIME
    // ========================================================
    function formatHour(h) {
        const suffix = h < 12 ? 'AM' : 'PM';
        const displayH = h === 0 ? 12 : h > 12 ? h - 12 : h;
        const nextH = h === 23 ? 12 : (h + 1 > 12 ? h + 1 - 12 : h + 1 === 0 ? 12 : h + 1);
        const nextSuffix = (h + 1) < 12 ? 'AM' : 'PM';
        return `${displayH}:00 ${suffix} – ${nextH}:00 ${nextSuffix === suffix ? '' : nextSuffix}`.replace('  ', ' ');
    }

    // ========================================================
    //  MANAGE MODAL
    // ========================================================
    function openManageModal(groundId, title) {
        currentGroundId = groundId;
        document.getElementById('manageModalSubtitle').textContent = title + ' — Configure 24-hour slot availability';
        document.getElementById('manageModalLoader').style.display = 'block';
        document.getElementById('slotTable').style.display = 'none';
        openModal('manageModal');

        fetch(`get_ground_data.php?ground_id=${groundId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { showToast('Failed to load data', 'error'); return; }
                groundBasePrice = parseFloat(data.ground.base_price) || 0;
                groundPeakPrice = parseFloat(data.ground.peak_price) || 0;

                // Build slot map from DB (hour => slot data)
                const slotMap = {};
                (data.slots || []).forEach(s => { slotMap[parseInt(s.hour)] = s; });

                renderSlotTable(slotMap);
                document.getElementById('manageModalLoader').style.display = 'none';
                document.getElementById('slotTable').style.display = 'table';
                updateAvailableCount();
            })
            .catch(() => showToast('Network error', 'error'));
    }

    function renderSlotTable(slotMap) {
        const tbody = document.getElementById('slotTableBody');
        tbody.innerHTML = '';
        for (let h = 0; h < 24; h++) {
            const saved = slotMap[h];
            const isAvail = saved ? parseInt(saved.is_available) : 1;
            const slotType = saved ? saved.slot_type : (isPeakHour(h) ? 'Peak' : 'Normal');
            const price = saved ? parseFloat(saved.price) : (slotType === 'Peak' ? groundPeakPrice : groundBasePrice);

            const tr = document.createElement('tr');
            tr.className = `slot-row border-b border-slate-50 ${isAvail ? 'available' : 'unavailable'}`;
            tr.dataset.hour = h;

            tr.innerHTML = `
                <td class="py-2.5 px-3">
                    <input type="checkbox" class="slot-check" ${isAvail ? 'checked' : ''}
                        onchange="toggleSlotRow(this, ${h})"
                        title="Enable/disable this time slot">
                </td>
                <td class="py-2.5 px-3 font-medium text-slate-700">${formatHour(h)}</td>
                <td class="py-2.5 px-3 text-slate-400 text-xs">${PERIODS[h]}</td>
                <td class="py-2.5 px-3">
                    <select class="slot-type-select ${slotType === 'Peak' ? 'peak' : 'normal'}"
                        onchange="onSlotTypeChange(this, ${h})">
                        <option value="Normal" ${slotType === 'Normal' ? 'selected' : ''}>🟢 Normal</option>
                        <option value="Peak" ${slotType === 'Peak' ? 'selected' : ''}>🔥 Peak</option>
                    </select>
                </td>
                <td class="py-2.5 px-3">
                    <input type="number" class="price-input" value="${price.toFixed(0)}" min="0" step="100"
                        id="price_${h}" placeholder="0">
                </td>
            `;
            tbody.appendChild(tr);
        }
    }

    function isPeakHour(h) {
        return (h >= 17 && h <= 21); // 5PM – 10PM considered peak by default
    }

    function toggleSlotRow(checkbox, hour) {
        const row = checkbox.closest('tr');
        if (checkbox.checked) {
            row.classList.remove('unavailable');
            row.classList.add('available');
        } else {
            row.classList.remove('available');
            row.classList.add('unavailable');
        }
        updateAvailableCount();
    }

    function onSlotTypeChange(select, hour) {
        const val = select.value;
        select.className = `slot-type-select ${val === 'Peak' ? 'peak' : 'normal'}`;
        // Auto-fill price based on type
        const priceInput = document.getElementById(`price_${hour}`);
        if (val === 'Peak' && parseFloat(priceInput.value) === groundBasePrice) {
            priceInput.value = groundPeakPrice.toFixed(0);
        } else if (val === 'Normal' && parseFloat(priceInput.value) === groundPeakPrice) {
            priceInput.value = groundBasePrice.toFixed(0);
        }
    }

    function selectAllSlots(checked) {
        document.querySelectorAll('.slot-check').forEach(cb => {
            cb.checked = checked;
            toggleSlotRow(cb, parseInt(cb.closest('tr').dataset.hour));
        });
    }

    function updateAvailableCount() {
        const count = document.querySelectorAll('.slot-check:checked').length;
        document.getElementById('availableCount').textContent = count;
    }

    function saveSlots() {
        const btn = document.getElementById('saveManageBtn');
        btn.disabled = true;
        btn.innerHTML = `<svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Saving...`;

        const slots = [];
        for (let h = 0; h < 24; h++) {
            const row = document.querySelector(`tr[data-hour="${h}"]`);
            if (!row) continue;
            const checkbox = row.querySelector('.slot-check');
            const selectEl = row.querySelector('.slot-type-select');
            const priceInput = document.getElementById(`price_${h}`);
            slots.push({
                hour: h,
                is_available: checkbox.checked ? 1 : 0,
                slot_type: selectEl.value,
                price: parseFloat(priceInput.value) || 0
            });
        }

        fetch('save_slots.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ground_id: currentGroundId, slots })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeModal('manageModal');
            } else {
                showToast(data.message || 'Save failed', 'error');
            }
        })
        .catch(() => showToast('Network error', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Save Changes`;
        });
    }

    // ========================================================
    //  DETAILS MODAL
    // ========================================================
    function openDetailsModal(groundId, title) {
        currentGroundId = groundId;
        document.getElementById('detailsModalSubtitle').textContent = title;
        document.getElementById('detailsModalLoader').style.display = 'block';
        document.getElementById('detailsForm').style.display = 'none';
        document.getElementById('detailsFooter').style.display = 'none';
        openModal('detailsModal');

        fetch(`get_ground_data.php?ground_id=${groundId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { showToast('Failed to load data', 'error'); return; }
                const g = data.ground;
                document.getElementById('detail_ground_id').value = g.id;
                document.getElementById('detail_title').value = g.title || '';
                document.getElementById('detail_address').value = g.address || '';
                document.getElementById('detail_base_price').value = g.base_price || '';
                document.getElementById('detail_peak_price').value = g.peak_price || '';
                document.getElementById('detail_description').value = g.description || '';

                // Set sport_type
                const sportSel = document.getElementById('detail_sport_type');
                const opt = [...sportSel.options].find(o => o.value.toLowerCase() === (g.sport_type || '').toLowerCase());
                if (opt) sportSel.value = opt.value;

                document.getElementById('detailsModalLoader').style.display = 'none';
                document.getElementById('detailsForm').style.display = 'block';
                document.getElementById('detailsFooter').style.display = 'flex';
            })
            .catch(() => showToast('Network error', 'error'));
    }

    function saveDetails() {
        const btn = document.getElementById('saveDetailsBtn');
        const title = document.getElementById('detail_title').value.trim();
        const address = document.getElementById('detail_address').value.trim();
        const sport_type = document.getElementById('detail_sport_type').value;
        const base_price = parseFloat(document.getElementById('detail_base_price').value);
        const peak_price = parseFloat(document.getElementById('detail_peak_price').value);
        const description = document.getElementById('detail_description').value.trim();

        if (!title || !address || !sport_type || !base_price || !peak_price) {
            showToast('Please fill in all required fields.', 'error');
            return;
        }
        if (peak_price < base_price) {
            showToast('Peak price should be ≥ base price.', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = `<svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Saving...`;

        fetch('save_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ground_id: currentGroundId, title, address, sport_type, base_price, peak_price, description })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeModal('detailsModal');
                // Reload page to reflect updated info
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(data.message || 'Save failed', 'error');
            }
        })
        .catch(() => showToast('Network error', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Save Changes`;
        });
    }

    // Close modal on Escape
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeModal('manageModal');
            closeModal('detailsModal');
        }
    });
    </script>
</body>
</html>
