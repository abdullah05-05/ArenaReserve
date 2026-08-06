<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch fresh user data from database every time
try {
    $stmt = $pdo->prepare("SELECT id, name, email, phone, city, current_role, current_active_mode, profile_picture FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    $user = null;
}

if (!$user) {
    header("Location: logout.php");
    exit;
}

$name_val      = trim($user['name'] ?? '');

$avatar_url    = (!empty($user['profile_picture'])) ? htmlspecialchars($user['profile_picture']) : null;
$name_initials = strtoupper(substr($user['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - ArenaReserve</title>
    <meta name="description" content="Manage your ArenaReserve ground owner profile — update your name, email, city, phone and profile picture.">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }

        /* ── Avatar ring ─────────────────────────── */
        .avatar-ring {
            background: linear-gradient(135deg, #10b981, #059669, #047857);
            padding: 3px;
            border-radius: 50%;
        }
        .avatar-inner {
            border-radius: 50%;
            overflow: hidden;
            background: #e2e8f0;
        }

        /* ── Light card ───────────────────────────── */
        .profile-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
        }

        /* ── Inputs ──────────────────────────────── */
        .profile-input {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 11px 14px;
            width: 100%;
            color: #1e293b;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .profile-input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12);
            background: #ffffff;
        }
        .profile-input::placeholder { color: #94a3b8; }

        /* ── Labels ──────────────────────────────── */
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #64748b;
            margin-bottom: 6px;
        }

        /* ── Save button ─────────────────────────── */
        .btn-save {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            font-weight: 700;
            font-size: 15px;
            padding: 13px;
            border-radius: 12px;
            width: 100%;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            letter-spacing: 0.02em;
        }
        .btn-save:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }
        .btn-save:active:not(:disabled) { transform: translateY(0); }
        .btn-save:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── Edit avatar overlay ─────────────────── */
        .avatar-edit-btn {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 50%;
            border: 2.5px solid #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        }
        .avatar-edit-btn:hover {
            transform: scale(1.12);
            box-shadow: 0 5px 14px rgba(16, 185, 129, 0.35);
        }

        /* ── Toast ───────────────────────────────── */
        #profile-toast {
            position: fixed;
            bottom: 28px;
            right: 28px;
            min-width: 280px;
            max-width: 380px;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            z-index: 9999;
            transform: translateX(130%);
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        #profile-toast.show { transform: translateX(0); }
        #profile-toast.success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-left: 4px solid #10b981;
        }
        #profile-toast.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-left: 4px solid #ef4444;
        }

        @keyframes avatar-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.35); }
            50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
        }
        .avatar-ring.new-upload { animation: avatar-pulse 1.5s ease 2; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">

    <!-- ══ Top Header ══════════════════════════════════════════════════════ -->
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
                    <a href="owner_dashboard.php" class="text-emerald-600 text-2xl font-bold flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z"/>
                        </svg>
                        ArenaReserve
                    </a>
                </div>

                <!-- Right side -->
                <div class="flex items-center gap-4">
                    <!-- Mode Toggle -->
                    <div class="flex items-center gap-2 bg-slate-100 p-1 rounded-lg border border-slate-200">
                        <a href="switch_role.php" class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Player') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?> hover:text-slate-800 transition-colors">Player</a>
                        <span class="text-xs font-medium px-2 py-1 text-slate-500 <?php echo ($_SESSION['current_active_mode'] === 'Owner') ? 'bg-white rounded shadow-sm text-emerald-600 font-bold' : ''; ?>">Owner</span>
                    </div>

                    <!-- Profile Dropdown (Only Profile Settings & Logout) -->
                    <div class="relative">
                        <button id="profileDropdownBtn" onclick="toggleProfileDropdown()" class="flex items-center gap-2 hover:opacity-90 focus:outline-none transition-opacity" title="User Menu">
                            <div id="header-avatar" class="w-8 h-8 rounded-full overflow-hidden bg-emerald-600 text-white flex items-center justify-center font-bold text-sm flex-shrink-0 shadow-sm border border-emerald-500">
                                <?php if ($avatar_url): ?>
                                    <img src="<?= $avatar_url ?>" alt="Avatar" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span id="headerAvatarInitials"><?php echo $name_initials; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="hidden md:block text-left">
                                <div class="text-xs font-semibold text-slate-800 flex items-center gap-1">
                                    <span id="headerName"><?php echo htmlspecialchars($user['name']); ?></span>
                                    <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                                <div class="text-[10px] text-slate-400 capitalize"><?php echo htmlspecialchars($_SESSION['current_active_mode']); ?></div>
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

        <!-- ══ Sidebar (Owner) ═══════════════════════════════════════════ -->
        <aside class="hidden lg:block w-64 flex-shrink-0">
            <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
                <a href="owner_dashboard.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    My Venues
                </a>
                <a href="add_ground.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    List New Venue
                </a>
                <a href="owner_analytics.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Analytics &amp; Wallet
                </a>
                <a href="owner_scores.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Score Entry
                </a>
            </nav>
        </aside>

        <!-- ══ Main Content ═══════════════════════════════════════════════ -->
        <main class="flex-1 min-w-0">
            <!-- Page heading -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-900">Profile Settings</h1>
                <p class="text-sm text-slate-500 mt-0.5">Manage your personal information and profile picture</p>
            </div>

            <!-- Profile Card -->
            <div class="max-w-xl">
                <div class="profile-card rounded-2xl p-8 shadow-sm">

                    <!-- ── Avatar Section ─────────────────────────────────── -->
                    <div class="flex flex-col items-center mb-8">
                        <div class="relative">
                            <div class="avatar-ring" id="avatarRing">
                                <div class="avatar-inner w-28 h-28 flex items-center justify-center">
                                    <?php if ($avatar_url): ?>
                                        <img id="avatarImg" src="<?= $avatar_url ?>" alt="Profile Picture" class="w-full h-full object-cover">
                                        <span id="avatarInitials" class="hidden text-4xl font-extrabold text-emerald-600"><?= $name_initials ?></span>
                                    <?php else: ?>
                                        <img id="avatarImg" src="" alt="Profile Picture" class="w-full h-full object-cover hidden">
                                        <span id="avatarInitials" class="text-4xl font-extrabold text-emerald-600"><?= $name_initials ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Edit button -->
                            <button type="button" onclick="document.getElementById('avatarInput').click()" class="avatar-edit-btn" title="Change profile picture">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                            </button>
                            <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
                        </div>
                        <p class="text-slate-500 text-xs mt-3">Click the pencil icon to change your photo</p>
                        <p class="text-slate-500 text-xs mt-0.5">Supports: JPG, PNG, WebP, GIF · Max 5 MB</p>
                    </div>

                    <!-- ── Form ───────────────────────────────────────────── -->
                    <form id="profileForm" novalidate>

                        <!-- Full Name -->
                        <div class="mb-5">
                            <label for="name" class="field-label">Full Name</label>
                            <input type="text" id="name" name="name"
                                   value="<?php echo htmlspecialchars($name_val); ?>"
                                   placeholder="Shahzaib Khan"
                                   class="profile-input" autocomplete="name" required>
                        </div>

                        <!-- Email -->
                        <div class="mb-5">
                            <label for="email" class="field-label">Email Address</label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($user['email']); ?>"
                                   placeholder="Enter your email"
                                   class="profile-input" autocomplete="email" required>
                        </div>

                        <!-- City -->
                        <div class="mb-5">
                            <label for="city" class="field-label">City</label>
                            <input type="text" id="city" name="city"
                                   value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>"
                                   placeholder="Enter your city"
                                   class="profile-input" autocomplete="address-level2" required>
                        </div>

                        <!-- Phone -->
                        <div class="mb-7">
                            <label for="phone" class="field-label">Phone Number</label>
                            <input type="tel" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                   placeholder="03XXXXXXXXX (11 digits)"
                                   class="profile-input" autocomplete="tel" required>
                            <?php if (empty($user['phone'])): ?>
                                <p class="text-amber-600 text-xs mt-1.5 flex items-center gap-1">
                                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    Phone number not set — please add one
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Save Button -->
                        <button type="submit" class="btn-save" id="saveBtn">
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- ══ Toast Notification ═════════════════════════════════════════════ -->
    <div id="profile-toast" role="alert" aria-live="polite">
        <span id="toast-icon">✓</span>
        <span id="toast-msg"></span>
    </div>

    <script>
        // ── Dropdown Toggle ───────────────────────────────────────────────────
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
        document.addEventListener('click', function(e) {
            const btn = document.getElementById('profileDropdownBtn');
            const menu = document.getElementById('profileDropdownMenu');
            if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('opacity-100', 'scale-100');
                menu.classList.add('opacity-0', 'scale-95');
                setTimeout(() => menu.classList.add('hidden'), 150);
            }
        });

        // ── Avatar file input → live preview ─────────────────────────────────
        const avatarInput = document.getElementById('avatarInput');
        const avatarImg   = document.getElementById('avatarImg');
        const avatarInit  = document.getElementById('avatarInitials');
        const avatarRing  = document.getElementById('avatarRing');

        avatarInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                showToast('Image must be under 5 MB.', false);
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (ev) {
                avatarImg.src = ev.target.result;
                avatarImg.classList.remove('hidden');
                avatarInit.classList.add('hidden');
                avatarRing.classList.add('new-upload');
                setTimeout(() => avatarRing.classList.remove('new-upload'), 3500);
            };
            reader.readAsDataURL(file);
        });

        // ── Form submission via Fetch ─────────────────────────────────────────
        document.getElementById('profileForm').addEventListener('submit', async function (e) {
            e.preventDefault();

            const nameVal  = document.getElementById('name').value.trim();
            const emailVal = document.getElementById('email').value.trim();
            const cityVal  = document.getElementById('city').value.trim();
            const phoneVal = document.getElementById('phone').value.trim();

            const nameRegex = /^[a-zA-Z0-9_\s]+$/;

            if (!nameVal) { showToast('Full Name is required.', false); return; }
            if (nameVal.length < 2) { showToast('Full Name must be at least 2 characters.', false); return; }
            if (!nameRegex.test(nameVal)) { showToast('Full Name must contain only letters, numbers, underscores, and spaces.', false); return; }
            if (!emailVal) { showToast('Email Address is required.', false); return; }
            if (!cityVal) { showToast('City is required.', false); return; }
            if (!phoneVal) { showToast('Phone Number is required.', false); return; }

            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled    = true;
            saveBtn.textContent = 'Saving…';

            const formData = new FormData();
            formData.append('name',  nameVal);
            formData.append('email', emailVal);
            formData.append('city',  cityVal);
            formData.append('phone', phoneVal);

            const avatarFile = avatarInput.files[0];
            if (avatarFile) formData.append('profile_picture', avatarFile);

            try {
                const res    = await fetch('update_profile.php', { method: 'POST', body: formData });
                const result = await res.json();

                showToast(result.message, result.success);

                if (result.success) {
                    const headerName = document.getElementById('headerName');
                    if (headerName) headerName.textContent = result.name || nameVal;

                    if (!avatarFile) {
                        const initSpan = document.getElementById('headerAvatarInitials');
                        if (initSpan) initSpan.textContent = nameVal.charAt(0).toUpperCase();
                    }

                    if (result.profile_picture) {
                        const headerAvatar = document.getElementById('header-avatar');
                        if (headerAvatar) {
                            headerAvatar.innerHTML = `<img src="${result.profile_picture}?t=${Date.now()}" alt="Avatar" class="w-full h-full object-cover rounded-full">`;
                        }
                    }
                }
            } catch (err) {
                showToast('Network error. Please check your connection and try again.', false);
            } finally {
                saveBtn.disabled    = false;
                saveBtn.textContent = 'Save Changes';
            }
        });

        // ── Toast helper ──────────────────────────────────────────────────────
        let toastTimer = null;
        function showToast(message, success) {
            const toast = document.getElementById('profile-toast');
            const msg   = document.getElementById('toast-msg');
            const icon  = document.getElementById('toast-icon');

            toast.classList.remove('show', 'success', 'error');
            void toast.offsetWidth;

            msg.textContent  = message;
            icon.textContent = success ? '✓' : '✗';
            toast.classList.add('show', success ? 'success' : 'error');

            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 5000);
        }

        function toggleMobileMenu() {
            const menu = document.getElementById('mobileNavigationMenu');
            if (menu) menu.classList.toggle('hidden');
        }
    </script>
</body>
</html>
