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

                // Insert into grounds (is_verified = 0: Pending)
                $stmt = $pdo->prepare("INSERT INTO grounds (owner_id, title, address, latitude, longitude, sport_type, base_price, peak_price, image_path, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([$user_id, $title, $address, $latitude, $longitude, $sport_type, $base_price, $peak_price, $image_path]);
                $ground_id = $pdo->lastInsertId();

                // Insert into onboarding_packages
                $stmt = $pdo->prepare("INSERT INTO onboarding_packages (owner_id, ground_id, verification_method, legal_docs_path, security_fee_receipt, approval_status) VALUES (?, ?, ?, ?, ?, 'Pending')");
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
    <style>
        body { font-family: 'Inter', sans-serif; }
        .step-content { display: none; }
        .step-content.active { display: block; }
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
                        <a href="switch_role.php" class="text-xs font-medium px-2 py-1 text-slate-500 hover:text-slate-800 transition-colors">Player</a>
                        <span class="text-xs font-medium px-2 py-1 text-slate-500 bg-white rounded shadow-sm text-emerald-600 font-bold">Owner</span>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm">
                            <?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="hidden md:block text-left">
                            <div class="text-xs font-semibold text-slate-800"><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></div>
                            <div class="text-[10px] text-slate-400 capitalize">Owner</div>
                        </div>
                        <a href="logout.php" class="text-xs text-red-500 hover:text-red-700 ml-2 font-medium">Logout</a>
                    </div>
                </div>
            </div>
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

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="latitude" class="block text-xs font-semibold text-slate-700">Latitude</label>
                                <input id="latitude" name="latitude" type="text" value="24.8607" readonly
                                       class="mt-1 block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-slate-100 text-slate-600 focus:outline-none">
                            </div>
                            <div>
                                <label for="longitude" class="block text-xs font-semibold text-slate-700">Longitude</label>
                                <input id="longitude" name="longitude" type="text" value="67.0011" readonly
                                       class="mt-1 block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-slate-100 text-slate-600 focus:outline-none">
                            </div>
                        </div>

                        <div>
                            <button type="button" onclick="autoDetectGPS()"
                                    class="w-full py-1.5 border border-emerald-600 text-emerald-600 text-xs font-semibold rounded-lg hover:bg-emerald-50 focus:outline-none transition-colors">
                                ⌖ Auto-Detect Location
                            </button>
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

        function autoDetectGPS() {
            // Simulate geo-detection
            const defaultLats = [24.8152, 24.8252, 24.8352, 24.8452];
            const defaultLngs = [67.033, 67.043, 67.053, 67.063];
            const randIdx = Math.floor(Math.random() * defaultLats.length);
            
            document.getElementById('latitude').value = defaultLats[randIdx];
            document.getElementById('longitude').value = defaultLngs[randIdx];
            alert("Location auto-detected successfully: Lat " + defaultLats[randIdx] + ", Lng " + defaultLngs[randIdx]);
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
    </script>
</body>
</html>
