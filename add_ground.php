<?php
session_start();
require_once 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Step 1 Details
    $title = trim($_POST['title'] ?? '');
    $sport_type = trim($_POST['sport_type'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $latitude = floatval($_POST['latitude'] ?? 24.8607);
    $longitude = floatval($_POST['longitude'] ?? 67.0011);
    
    // Step 2 Details
    $base_price = floatval($_POST['base_price'] ?? 0);
    $peak_price = floatval($_POST['peak_price'] ?? 0);
    
    // Step 3 Details
    $verification_method = $_POST['verification_method'] ?? 'StampPaper';

    // Validations
    if (empty($title) || empty($sport_type) || empty($address) || $base_price <= 0 || $peak_price <= 0) {
        $error = 'Please complete all required fields across all steps.';
    } else {
        // Handle files upload
        $image_path = '';
        $legal_docs_path = '';
        $security_fee_receipt = '';
        
        $upload_dir_grounds = 'uploads/grounds/';
        $upload_dir_docs = 'uploads/stamp_papers/';
        $upload_dir_receipts = 'uploads/receipts/';
        
        // Ensure directories exist
        if (!file_exists($upload_dir_grounds)) mkdir($upload_dir_grounds, 0777, true);
        if (!file_exists($upload_dir_docs)) mkdir($upload_dir_docs, 0777, true);
        if (!file_exists($upload_dir_receipts)) mkdir($upload_dir_receipts, 0777, true);

        // Upload Venue Photo
        if (isset($_FILES['venue_photo']) && $_FILES['venue_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['venue_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $venue_photo_name = uniqid('ground_', true) . '.' . $ext;
                if (move_uploaded_file($_FILES['venue_photo']['tmp_name'], $upload_dir_grounds . $venue_photo_name)) {
                    $image_path = $upload_dir_grounds . $venue_photo_name;
                }
            }
        }

        // Upload Verification Files
        if ($verification_method === 'StampPaper') {
            if (isset($_FILES['stamp_paper']) && $_FILES['stamp_paper']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['stamp_paper']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                    $doc_name = uniqid('stamp_', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['stamp_paper']['tmp_name'], $upload_dir_docs . $doc_name)) {
                        $legal_docs_path = $upload_dir_docs . $doc_name;
                    }
                }
            }
        } else {
            if (isset($_FILES['security_receipt']) && $_FILES['security_receipt']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['security_receipt']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                    $receipt_name = uniqid('sec_rec_', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['security_receipt']['tmp_name'], $upload_dir_receipts . $receipt_name)) {
                        $security_fee_receipt = $upload_dir_receipts . $receipt_name;
                    }
                }
            }
        }

        // Final check on required files
        if (empty($image_path)) {
            $error = 'Venue photo is required.';
        } else if ($verification_method === 'StampPaper' && empty($legal_docs_path)) {
            $error = 'Please upload legal stamp paper documents.';
        } else if ($verification_method === 'SecurityDeposit' && empty($security_fee_receipt)) {
            $error = 'Please upload your security fee deposit receipt.';
        } else {
            // Save to DB
            try {
                $pdo->beginTransaction();

                // Insert into grounds (is_verified = 1: Verified)
                $stmt = $pdo->prepare("INSERT INTO grounds (owner_id, title, address, latitude, longitude, sport_type, base_price, peak_price, image_path, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$user_id, $title, $address, $latitude, $longitude, $sport_type, $base_price, $peak_price, $image_path]);
                $ground_id = $pdo->lastInsertId();

                // Insert default slots (hours 0..23)
                $slot_stmt = $pdo->prepare("INSERT INTO ground_slots (ground_id, hour, is_available, slot_type, price) VALUES (?, ?, ?, ?, ?)");
                for ($h = 0; $h < 24; $h++) {
                    $is_peak = ($h >= 17 && $h <= 20);
                    $slot_type = $is_peak ? 'Peak' : 'Normal';
                    $slot_price = $is_peak ? $peak_price : $base_price;
                    // Make common slots available by default
                    $avail = in_array($h, [3, 5, 10, 18, 19, 20]) ? 1 : 0;
                    $slot_stmt->execute([$ground_id, $h, $avail, $slot_type, $slot_price]);
                }

                // Insert into onboarding_packages (Approved)
                $stmt = $pdo->prepare("INSERT INTO onboarding_packages (owner_id, ground_id, verification_method, legal_docs_path, security_fee_receipt, approval_status) VALUES (?, ?, ?, ?, ?, 'Approved')");
                $stmt->execute([$user_id, $ground_id, $verification_method, $legal_docs_path, $security_fee_receipt]);

                // Update current user role & active mode to Owner
                $stmt = $pdo->prepare("UPDATE users SET `current_role` = 'Owner', `current_active_mode` = 'Owner' WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['current_role'] = 'Owner';
                $_SESSION['current_active_mode'] = 'Owner';

                $pdo->commit();
                header("Location: owner_dashboard.php?registered=1");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onboard Sports Venue - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Leaflet CSS for Map Picker -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
    <!-- Leaflet JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .step-content { display: none; }
        .step-content.active { display: block; }
        /* Map Modal */
        #mapModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        #mapModal.open { display: flex; }
        #mapContainer {
            width: 100%;
            height: 420px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            position: relative;
            z-index: 1;
            background: #e2e8f0;
        }
        /* Wrapper clips the rounded corners without clipping tiles */
        #mapContainerWrapper {
            border-radius: 12px;
            overflow: hidden;
        }
        /* Leaflet popup custom */
        .leaflet-popup-content { font-size: 12px; font-family: 'Inter', sans-serif; }
        /* GPS spinner */
        @keyframes spin { to { transform: rotate(360deg); } }
        .spin { animation: spin 0.8s linear infinite; display: inline-block; }
        /* Location badge */
        #locationBadge { display: none; }
        #locationBadge.show { display: flex; }
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
        <aside class="hidden md:block w-64 flex-shrink-0">
            <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
                <a href="owner_dashboard.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    My Venues
                </a>
                <a href="add_ground.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg">
                    <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
            <div class="bg-white p-8 rounded-2xl border border-slate-200 shadow-md max-w-2xl mx-auto">
            <!-- Title -->
            <div class="text-center mb-8">
                <h1 class="text-2xl font-extrabold text-slate-900">List New Venue</h1>
                <p class="text-sm text-slate-500 mt-1">Complete the onboarding process to list your sports facility</p>
            </div>

            <!-- Steps Indicator -->
            <div class="flex items-center justify-between mb-8 relative">
                <!-- Progress Line -->
                <div class="absolute left-0 right-0 top-1/2 -translate-y-1/2 h-0.5 bg-slate-200 -z-10"></div>
                <div id="progress-line-fill" class="absolute left-0 top-1/2 -translate-y-1/2 h-0.5 bg-emerald-500 -z-10 transition-all duration-300" style="width: 0%;"></div>

                <!-- Step 1 Indicator -->
                <div class="step-indicator flex flex-col items-center">
                    <span id="ind-1" class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs bg-emerald-600 text-white border-2 border-emerald-600">1</span>
                    <span class="text-[10px] text-slate-500 font-semibold mt-1">Venue Details</span>
                </div>
                <!-- Step 2 Indicator -->
                <div class="step-indicator flex flex-col items-center">
                    <span id="ind-2" class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs bg-white text-slate-400 border-2 border-slate-200">2</span>
                    <span class="text-[10px] text-slate-500 font-semibold mt-1">Pricing Setup</span>
                </div>
                <!-- Step 3 Indicator -->
                <div class="step-indicator flex flex-col items-center">
                    <span id="ind-3" class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs bg-white text-slate-400 border-2 border-slate-200">3</span>
                    <span class="text-[10px] text-slate-500 font-semibold mt-1">Verification</span>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-3 text-xs text-red-700">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form id="onboardForm" action="add_ground.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <!-- STEP 1: Venue Details -->
                <div id="step-1" class="step-content active">
                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4">Venue Basic Details</h3>
                    <div class="space-y-4">
                        <div>
                            <label for="title" class="block text-xs font-semibold text-slate-700">Venue Name</label>
                            <input id="title" name="title" type="text" placeholder="e.g. Champions Stadium A"
                                   class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
                        </div>

                        <div>
                            <label for="sport_type" class="block text-xs font-semibold text-slate-700">Sport Type</label>
                            <select id="sport_type" name="sport_type"
                                    class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="Football">Football</option>
                                <option value="Cricket">Cricket</option>
                                <option value="Basketball">Basketball</option>
                                <option value="Badminton">Badminton</option>
                                <option value="Futsal">Futsal</option>
                            </select>
                        </div>

                        <div>
                            <label for="address" class="block text-xs font-semibold text-slate-700">Full Address</label>
                            <textarea id="address" name="address" rows="3" placeholder="Enter complete address with area and city"
                                      class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500"></textarea>
                        </div>

                        <!-- Location Section -->
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-semibold text-slate-700">📍 Venue Location (Coordinates)</label>
                                <!-- Detected badge -->
                                <span id="locationBadge" class="items-center gap-1.5 text-[10px] font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">
                                    <svg class="h-3 w-3 inline-block" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    Location Set
                                </span>
                            </div>

                            <!-- Coordinate display fields -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="latitude" class="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Latitude</label>
                                    <input id="latitude" name="latitude" type="text" value="24.8607" readonly
                                           class="mt-1 block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white text-slate-700 font-mono focus:outline-none focus:ring-1 focus:ring-emerald-400">
                                </div>
                                <div>
                                    <label for="longitude" class="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Longitude</label>
                                    <input id="longitude" name="longitude" type="text" value="67.0011" readonly
                                           class="mt-1 block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white text-slate-700 font-mono focus:outline-none focus:ring-1 focus:ring-emerald-400">
                                </div>
                            </div>

                            <!-- GPS Error message -->
                            <div id="gpsError" class="hidden text-[11px] text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>

                            <!-- Location Action Buttons -->
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" id="autoDetectBtn" onclick="autoDetectGPS()"
                                        class="flex items-center justify-center gap-2 py-2 border border-emerald-500 text-emerald-600 text-xs font-semibold rounded-lg hover:bg-emerald-50 focus:outline-none transition-colors">
                                    <svg id="gpsIcon" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <span id="gpsLabel">Auto-Detect GPS</span>
                                </button>
                                <button type="button" onclick="openMapPicker()"
                                        class="flex items-center justify-center gap-2 py-2 border border-slate-400 text-slate-600 text-xs font-semibold rounded-lg hover:bg-slate-100 focus:outline-none transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                                    Choose on Map
                                </button>
                            </div>
                            <p class="text-[10px] text-slate-400">Use GPS to detect your device location, or manually pin the venue on the interactive map.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Venue Photo</label>
                            <div class="mt-1 flex items-center gap-4">
                                <input type="file" id="venue_photo" name="venue_photo" accept="image/*" class="text-xs text-slate-500 file:mr-4 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Pricing Setup -->
                <div id="step-2" class="step-content">
                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4">Pricing Setup</h3>
                    <div class="space-y-4">
                        <div>
                            <label for="base_price" class="block text-xs font-semibold text-slate-700">Standard Hourly Rate (PKR)</label>
                            <input id="base_price" name="base_price" type="number" placeholder="2500"
                                   class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
                        </div>

                        <div>
                            <label for="peak_price" class="block text-xs font-semibold text-slate-700">Peak Hour Rate (PKR)</label>
                            <input id="peak_price" name="peak_price" type="number" placeholder="3500"
                                   class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-lg text-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-[10px] text-slate-400 mt-1">This rate applies to evening and weekend slots (typically 4 PM - 10 PM)</p>
                        </div>

                        <div class="bg-emerald-50 border border-emerald-100 rounded-lg p-4 text-xs text-emerald-800">
                            <h4 class="font-bold mb-1">Platform Commission Info</h4>
                            ArenaReserve charges a 5% platform service fee on all bookings. This fee is automatically deducted from your payouts.
                            <div class="mt-2 grid grid-cols-2 gap-4 font-semibold text-emerald-950">
                                <div>Your Standard Rate: <span id="lbl-std">0 PKR</span></div>
                                <div>After Commission: <span id="lbl-comm">0 PKR</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: Verification docs -->
                <div id="step-3" class="step-content">
                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4">Legal Verification</h3>
                    <div class="space-y-4">
                        <p class="text-xs text-slate-500">Choose your preferred verification method to complete the listing process:</p>
                        
                        <div class="space-y-3">
                            <!-- Option 1: Stamp Paper -->
                            <label class="block border border-slate-200 rounded-lg p-4 hover:border-emerald-500 transition-colors cursor-pointer">
                                <div class="flex items-center">
                                    <input type="radio" name="verification_method" value="StampPaper" checked
                                           class="h-4 w-4 text-emerald-600 border-slate-300 focus:ring-emerald-500"
                                           onchange="toggleVerificationForms()">
                                    <div class="ml-3">
                                        <span class="block text-xs font-bold text-slate-800">Option 1: Legal Stamp Papers</span>
                                        <span class="block text-[10px] text-slate-500 mt-0.5">Submit scanned copies of ownership papers, lease agreements, or legal stamp papers proving ownership or authorized operation rights.</span>
                                    </div>
                                </div>
                            </label>

                            <!-- Option 2: Security Deposit -->
                            <label class="block border border-slate-200 rounded-lg p-4 hover:border-emerald-500 transition-colors cursor-pointer">
                                <div class="flex items-center">
                                    <input type="radio" name="verification_method" value="SecurityDeposit"
                                           class="h-4 w-4 text-emerald-600 border-slate-300 focus:ring-emerald-500"
                                           onchange="toggleVerificationForms()">
                                    <div class="ml-3">
                                        <span class="block text-xs font-bold text-slate-800">Option 2: Security Fee (10,000 PKR)</span>
                                        <span class="block text-[10px] text-slate-500 mt-0.5">Pay a refundable security deposit via bank transfer. Upload the deposit slip as proof. The deposit will be returned if you delist your venue.</span>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <!-- Form field for Option 1: Stamp Paper -->
                        <div id="section-stamp" class="border-t border-slate-100 pt-4">
                            <label class="block text-xs font-semibold text-slate-700">Upload Legal Stamp Documents (PDF, JPG, PNG)</label>
                            <input type="file" name="stamp_paper" accept="image/*,application/pdf" class="mt-2 text-xs text-slate-500 file:mr-4 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                        </div>

                        <!-- Form field for Option 2: Security Fee -->
                        <div id="section-security" class="hidden border-t border-slate-100 pt-4">
                            <div class="mb-3 bg-slate-50 border border-slate-200 rounded-lg p-3 text-[10px] text-slate-600">
                                <span class="font-bold text-slate-800">Security Fee Payment Details:</span><br>
                                Allied Bank (ABL) - 001004958273012 (Title: ArenaReserve LLC)<br>
                                Amount: 10,000 PKR
                            </div>
                            <label class="block text-xs font-semibold text-slate-700">Upload Security Deposit Bank Receipt (JPG, PNG, PDF)</label>
                            <input type="file" name="security_receipt" accept="image/*,application/pdf" class="mt-2 text-xs text-slate-500 file:mr-4 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                        </div>
                    </div>
                </div>

                <!-- Footer Navigation Buttons -->
                <div class="flex justify-between border-t border-slate-100 pt-6">
                    <button type="button" id="prevBtn" onclick="navigateStep(-1)"
                            class="px-4 py-2 border border-slate-300 rounded-lg text-slate-700 text-xs font-semibold hover:bg-slate-50 transition-colors hidden">
                        Previous
                    </button>
                    <!-- Spacer if prev is hidden -->
                    <div id="prevSpacer" class="flex-1"></div>
                    
                    <button type="button" id="nextBtn" onclick="navigateStep(1)"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm hover:shadow transition-all">
                        Next
                    </button>

                    <button type="submit" id="submitBtn"
                            class="px-6 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm hover:shadow transition-all hidden">
                        Submit for Review
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Wizard Javascript Logic -->
    <script>
        let currentStep = 1;
        const totalSteps = 3;
        
        function updateWizardUI() {
            // Toggle Content
            document.querySelectorAll('.step-content').forEach((el, idx) => {
                if (idx + 1 === currentStep) {
                    el.classList.add('active');
                } else {
                    el.classList.remove('active');
                }
            });

            // Toggle Indicators
            for(let i = 1; i <= totalSteps; i++) {
                const ind = document.getElementById('ind-' + i);
                if (i < currentStep) {
                    ind.className = "w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs bg-emerald-600 text-white border-2 border-emerald-600";
                    ind.textContent = "✓";
                } else if (i === currentStep) {
                    ind.className = "w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs bg-emerald-600 text-white border-2 border-emerald-600";
                    ind.textContent = i;
                } else {
                    ind.className = "w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs bg-white text-slate-400 border-2 border-slate-200";
                    ind.textContent = i;
                }
            }

            // Fill Line
            const percent = ((currentStep - 1) / (totalSteps - 1)) * 100;
            document.getElementById('progress-line-fill').style.width = percent + '%';

            // Navigation buttons toggling
            const prevBtn = document.getElementById('prevBtn');
            const prevSpacer = document.getElementById('prevSpacer');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');

            if (currentStep === 1) {
                prevBtn.classList.add('hidden');
                prevSpacer.classList.remove('hidden');
            } else {
                prevBtn.classList.remove('hidden');
                prevSpacer.classList.add('hidden');
            }

            if (currentStep === totalSteps) {
                nextBtn.classList.add('hidden');
                submitBtn.classList.remove('hidden');
            } else {
                nextBtn.classList.remove('hidden');
                submitBtn.classList.add('hidden');
            }
        }

        function navigateStep(direction) {
            // Validation before going next
            if (direction === 1) {
                if (currentStep === 1) {
                    const title = document.getElementById('title').value;
                    const address = document.getElementById('address').value;
                    const photo = document.getElementById('venue_photo').files.length;
                    if (!title.trim() || !address.trim() || photo === 0) {
                        alert("Please fill in the venue name, address, and upload a venue photo.");
                        return;
                    }
                } else if (currentStep === 2) {
                    const base_price = parseFloat(document.getElementById('base_price').value);
                    const peak_price = parseFloat(document.getElementById('peak_price').value);
                    if (isNaN(base_price) || base_price <= 0 || isNaN(peak_price) || peak_price <= 0) {
                        alert("Please enter a valid hourly rate and peak rate.");
                        return;
                    }
                }
            }

            currentStep += direction;
            if (currentStep < 1) currentStep = 1;
            if (currentStep > totalSteps) currentStep = totalSteps;
            updateWizardUI();
        }

        function toggleVerificationForms() {
            const method = document.querySelector('input[name="verification_method"]:checked').value;
            const stampSec = document.getElementById('section-stamp');
            const securitySec = document.getElementById('section-security');
            
            if (method === 'StampPaper') {
                stampSec.classList.remove('hidden');
                securitySec.classList.add('hidden');
            } else {
                stampSec.classList.add('hidden');
                securitySec.classList.remove('hidden');
            }
        }

        // =============================================
        // REAL GPS AUTO-DETECTION
        // =============================================
        function autoDetectGPS() {
            if (!navigator.geolocation) {
                showGpsError('Geolocation is not supported by your browser. Please use the map picker instead.');
                return;
            }

            // Show spinner
            const btn = document.getElementById('autoDetectBtn');
            const icon = document.getElementById('gpsIcon');
            const label = document.getElementById('gpsLabel');
            btn.disabled = true;
            icon.outerHTML = '<svg id="gpsIcon" class="h-4 w-4 spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>';
            label.textContent = 'Detecting…';
            hideGpsError();

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude.toFixed(6);
                    const lng = position.coords.longitude.toFixed(6);
                    setCoordinates(lat, lng);

                    // Reset button
                    document.getElementById('gpsIcon').outerHTML = '<svg id="gpsIcon" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
                    document.getElementById('gpsLabel').textContent = '✓ GPS Detected';
                    document.getElementById('autoDetectBtn').disabled = false;
                },
                function(error) {
                    // Reset button
                    document.getElementById('gpsIcon').outerHTML = '<svg id="gpsIcon" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
                    document.getElementById('gpsLabel').textContent = 'Auto-Detect GPS';
                    document.getElementById('autoDetectBtn').disabled = false;

                    let msg = 'Could not detect location. ';
                    if (error.code === error.PERMISSION_DENIED) {
                        msg += 'Location access was denied. Please allow location in browser settings or use the map picker.';
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        msg += 'Location information is unavailable. Try the map picker instead.';
                    } else if (error.code === error.TIMEOUT) {
                        msg += 'Location request timed out. Please try again.';
                    }
                    showGpsError(msg);
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }

        function setCoordinates(lat, lng) {
            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;
            document.getElementById('locationBadge').classList.add('show');
            hideGpsError();
        }

        function showGpsError(msg) {
            const el = document.getElementById('gpsError');
            el.textContent = '⚠ ' + msg;
            el.classList.remove('hidden');
        }
        function hideGpsError() {
            document.getElementById('gpsError').classList.add('hidden');
        }

        // =============================================
        // LEAFLET MAP PICKER WITH FALLBACK LOADER
        // =============================================
        let mapInstance = null;
        let mapMarker = null;
        let pendingLat = null;
        let pendingLng = null;

        function openMapPicker() {
            const modal = document.getElementById('mapModal');
            modal.classList.add('open');

            ensureLeafletLoaded(function() {
                setTimeout(initOrRefreshMap, 50);
                setTimeout(initOrRefreshMap, 250);
                setTimeout(initOrRefreshMap, 600);
            });
        }

        function ensureLeafletLoaded(callback) {
            if (typeof L !== 'undefined') {
                callback();
                return;
            }
            // Dynamic script fallback if primary CDN failed or was delayed
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(css);

            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = callback;
            script.onerror = function() {
                alert('Map library could not be loaded. Please check your internet connection.');
            };
            document.head.appendChild(script);
        }

        function initOrRefreshMap() {
            if (typeof L === 'undefined') return;

            const startLat = parseFloat(document.getElementById('latitude').value) || 24.8607;
            const startLng = parseFloat(document.getElementById('longitude').value) || 67.0011;

            if (!mapInstance) {
                const container = document.getElementById('mapContainer');
                if (!container) return;

                mapInstance = L.map('mapContainer', {
                    zoomControl: true,
                    scrollWheelZoom: true
                }).setView([startLat, startLng], 13);

                // Define Google Maps tile layers (100% Google Maps visual styling & data)
                const googleStreets = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
                    maxZoom: 20,
                    subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                    attribution: '&copy; Google Maps'
                });

                const googleHybrid = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
                    maxZoom: 20,
                    subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                    attribution: '&copy; Google Maps'
                });

                const googleSat = L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
                    maxZoom: 20,
                    subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                    attribution: '&copy; Google Maps'
                });

                const googleTerrain = L.tileLayer('https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}', {
                    maxZoom: 20,
                    subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                    attribution: '&copy; Google Maps'
                });

                // Add default Google Maps Roadmap layer to map
                googleStreets.addTo(mapInstance);

                // Add built-in Leaflet Layer Control (top-right switcher for Google Maps styles)
                const baseLayers = {
                    "🗺️ Google Maps (Default)": googleStreets,
                    "🛰️ Google Satellite + Roads": googleHybrid,
                    "📷 Google Satellite Only": googleSat,
                    "⛰️ Google Terrain": googleTerrain
                };
                L.control.layers(baseLayers, null, { position: 'topright' }).addTo(mapInstance);

                // Custom marker icon with standard fallback
                let pinIcon;
                try {
                    pinIcon = L.divIcon({
                        html: `<div style="width:28px;height:28px;background:#059669;border:3px solid white;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 3px 12px rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;"><div style="width:8px;height:8px;background:white;border-radius:50%;transform:rotate(45deg);"></div></div>`,
                        iconSize: [28, 28],
                        iconAnchor: [14, 28],
                        className: ''
                    });
                } catch(e) {}

                const markerOptions = { draggable: true };
                if (pinIcon) markerOptions.icon = pinIcon;

                // Place initial marker
                mapMarker = L.marker([startLat, startLng], markerOptions).addTo(mapInstance);
                pendingLat = startLat;
                pendingLng = startLng;

                // Click to move marker
                mapInstance.on('click', function(e) {
                    mapMarker.setLatLng(e.latlng);
                    pendingLat = e.latlng.lat.toFixed(6);
                    pendingLng = e.latlng.lng.toFixed(6);
                    updateMapInfo(pendingLat, pendingLng);
                    reverseGeocode(pendingLat, pendingLng);
                });

                // Drag marker
                mapMarker.on('dragend', function(e) {
                    const pos = mapMarker.getLatLng();
                    pendingLat = pos.lat.toFixed(6);
                    pendingLng = pos.lng.toFixed(6);
                    updateMapInfo(pendingLat, pendingLng);
                    reverseGeocode(pendingLat, pendingLng);
                });

                updateMapInfo(startLat, startLng);
            }

            if (mapInstance) {
                mapInstance.invalidateSize();
                mapInstance.setView([pendingLat || startLat, pendingLng || startLng], 13);
            }
        }

        function closeMapPicker() {
            document.getElementById('mapModal').classList.remove('open');
        }

        function confirmMapSelection() {
            if (pendingLat && pendingLng) {
                setCoordinates(pendingLat, pendingLng);
            }
            closeMapPicker();
        }

        function updateMapInfo(lat, lng) {
            document.getElementById('mapCoordDisplay').textContent = `Lat: ${parseFloat(lat).toFixed(5)}, Lng: ${parseFloat(lng).toFixed(5)}`;
        }

        // Nominatim Reverse Geocoding (Free — OpenStreetMap)
        function reverseGeocode(lat, lng) {
            const statusEl = document.getElementById('mapAddressPreview');
            statusEl.textContent = 'Fetching address…';

            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&accept-language=en`, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.display_name) {
                    statusEl.textContent = data.display_name;
                    // Offer to auto-fill address field
                    document.getElementById('mapFillAddressBtn').style.display = 'inline-flex';
                    document.getElementById('mapFillAddressBtn').onclick = function() {
                        document.getElementById('address').value = data.display_name;
                    };
                } else {
                    statusEl.textContent = 'Address not found for this location.';
                }
            })
            .catch(() => { statusEl.textContent = 'Could not fetch address (check internet).'; });
        }

        // Search location by name/city
        function searchMapLocation() {
            const query = document.getElementById('mapSearchInput').value.trim();
            if (!query) return;
            const statusEl = document.getElementById('mapAddressPreview');
            statusEl.textContent = 'Searching location…';

            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`)
            .then(r => r.json())
            .then(data => {
                if (data && data.length > 0) {
                    const lat = parseFloat(data[0].lat);
                    const lng = parseFloat(data[0].lon);
                    pendingLat = lat.toFixed(6);
                    pendingLng = lng.toFixed(6);
                    if (mapInstance) {
                        mapInstance.setView([lat, lng], 15);
                        if (mapMarker) mapMarker.setLatLng([lat, lng]);
                    }
                    updateMapInfo(pendingLat, pendingLng);
                    reverseGeocode(pendingLat, pendingLng);
                } else {
                    statusEl.textContent = 'Location not found. Try typing a city, area, or landmark name.';
                }
            })
            .catch(() => { statusEl.textContent = 'Error searching location.'; });
        }

        // Live Commission calculator
        const basePriceInput = document.getElementById('base_price');
        basePriceInput.addEventListener('input', function() {
            const val = parseFloat(this.value) || 0;
            document.getElementById('lbl-std').textContent = val + " PKR";
            document.getElementById('lbl-comm').textContent = (val * 0.95) + " PKR";
        });

        // Initialize UI
        updateWizardUI();

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

    <!-- =========================================
         LEAFLET MAP PICKER MODAL
    ========================================== -->
    <div id="mapModal" role="dialog" aria-modal="true" aria-label="Choose location on map">
        <div style="background:white;border-radius:20px;width:100%;max-width:760px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.4);display:flex;flex-direction:column;">
            <!-- Modal Header -->
            <div style="background:linear-gradient(135deg,#059669,#047857);padding:16px 20px;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="color:rgba(255,255,255,0.75);font-size:11px;font-family:Inter,sans-serif;">Interactive Map</div>
                    <div style="color:white;font-size:16px;font-weight:700;font-family:Inter,sans-serif;">📍 Pin Your Venue Location</div>
                </div>
                <button onclick="closeMapPicker()" style="background:rgba(255,255,255,0.2);border:none;border-radius:50%;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:white;font-size:18px;" aria-label="Close map">&times;</button>
            </div>

            <!-- Instruction bar -->
            <div style="background:#f0fdf4;border-bottom:1px solid #d1fae5;padding:10px 20px;font-size:11px;color:#065f46;font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">
                <div>🖱 <strong>Click anywhere</strong> on the map or <strong>drag the pin</strong> to set location.</div>
                <div style="color:#047857;font-weight:600;">✨ Switch map layers (Street / Satellite / Clean) in the top-right corner</div>
            </div>

            <!-- Search location bar -->
            <div style="padding:12px 16px 0;display:flex;gap:8px;">
                <input id="mapSearchInput" type="text" placeholder="🔍 Search city, area, or landmark (e.g. DHA Karachi, Gulberg Lahore)..."
                       onkeydown="if(event.key==='Enter'){event.preventDefault();searchMapLocation();}"
                       style="flex:1;padding:8px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:12px;outline:none;font-family:Inter,sans-serif;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <button type="button" onclick="searchMapLocation()"
                        style="padding:8px 18px;background:#059669;color:white;border:none;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;font-family:Inter,sans-serif;box-shadow:0 2px 6px rgba(5,150,105,0.25);">
                    Search
                </button>
            </div>

            <!-- Map Container -->
            <div style="padding:12px 16px 0;">
                <div id="mapContainer"></div>
            </div>

            <!-- Coordinates & Address Preview -->
            <div style="padding:12px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div>
                        <div style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;font-family:Inter,sans-serif;">Selected Coordinates</div>
                        <div id="mapCoordDisplay" style="font-size:13px;font-weight:700;color:#0f172a;font-family:monospace;">—</div>
                    </div>
                    <button id="mapFillAddressBtn" onclick="" style="display:none;align-items:center;gap:6px;font-size:11px;font-weight:600;color:#059669;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:6px 12px;cursor:pointer;font-family:Inter,sans-serif;">
                        ✎ Auto-fill address field
                    </button>
                </div>
                <div id="mapAddressPreview" style="margin-top:6px;font-size:11px;color:#64748b;font-family:Inter,sans-serif;min-height:18px;">Click the map to see address preview</div>
            </div>

            <!-- Modal Footer Buttons -->
            <div style="padding:14px 20px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;">
                <button onclick="closeMapPicker()" style="padding:8px 20px;border:1px solid #cbd5e1;border-radius:10px;font-size:12px;font-weight:600;color:#475569;background:white;cursor:pointer;font-family:Inter,sans-serif;">Cancel</button>
                <button onclick="confirmMapSelection()" style="padding:8px 24px;background:#059669;border:none;border-radius:10px;font-size:12px;font-weight:700;color:white;cursor:pointer;box-shadow:0 2px 8px rgba(5,150,105,0.3);font-family:Inter,sans-serif;">✓ Confirm Location</button>
            </div>
        </div>
    </div>
</body>
</html>
