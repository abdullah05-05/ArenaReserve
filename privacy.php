<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
$active_policy = 'privacy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        .glass-header {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            background: rgba(255, 255, 255, 0.85);
        }
        .legal-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1.25rem;
            transition: all 0.2s ease;
        }
        .legal-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.04);
        }
        html {
            scroll-behavior: smooth;
        }
    </style>
    <?php
    $page_description = 'ArenaReserve Privacy Policy — Details on how we collect, safeguard, and process your sports booking data and personal information.';
    include 'logo_head.php';
    ?>
</head>
<body class="bg-slate-50 text-slate-800 antialiased selection:bg-emerald-500 selection:text-white flex flex-col min-h-screen">

    <!-- ============================================================
         NAVBAR
    ============================================================ -->
    <header class="glass-header sticky top-0 left-0 right-0 z-50 border-b border-slate-200/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Brand Logo -->
                <a href="landing.php" class="flex items-center gap-2.5 group">
                    <div class="w-10 h-10 rounded-xl bg-emerald-600 flex items-center justify-center text-white shadow-md shadow-emerald-500/25 group-hover:scale-105 transition-transform duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z" />
                        </svg>
                    </div>
                    <span class="text-2xl font-black tracking-tight text-slate-900 group-hover:text-emerald-600 transition-colors">
                        Arena<span class="text-emerald-600">Reserve</span>
                    </span>
                </a>

                <!-- Desktop Nav -->
                <nav class="hidden md:flex items-center gap-7 text-sm font-semibold text-slate-600">
                    <a href="landing.php" class="hover:text-emerald-600 transition-colors py-1">Home</a>
                    <a href="landing.php#about" class="hover:text-emerald-600 transition-colors py-1">About</a>
                    <a href="landing.php#features" class="hover:text-emerald-600 transition-colors py-1">Features</a>
                    <a href="contact.php" class="hover:text-emerald-600 transition-colors py-1">Support</a>
                </nav>

                <!-- Auth Buttons -->
                <div class="flex items-center gap-3">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="explore.php" class="px-5 py-2.5 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow-md transition-all">
                            Dashboard
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="hidden sm:inline-block px-4 py-2.5 text-sm font-bold text-slate-700 hover:text-emerald-600 rounded-xl hover:bg-slate-100/80 transition-all">
                            Log In
                        </a>
                        <a href="signup.php" class="px-5 py-2.5 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow-md transition-all">
                            Sign Up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- ============================================================
         POLICY HERO HEADER
    ============================================================ -->
    <section class="bg-gradient-to-b from-emerald-900 via-slate-900 to-slate-900 text-white py-16 sm:py-20 relative overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(#10b981_1px,transparent_1px)] [background-size:24px_24px] opacity-10"></div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="max-w-3xl">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-bold uppercase tracking-wider mb-4 border border-emerald-500/30">
                    <span>🔒 Privacy & Data Protection</span>
                </div>
                <h1 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight text-white">
                    Privacy Policy
                </h1>
                <p class="mt-4 text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
                    Learn how ArenaReserve collects, utilizes, safeguards, and respects your personal and sports booking information.
                </p>
                <div class="mt-6 flex flex-wrap items-center gap-6 text-xs text-slate-400 font-medium">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        <span>Last Revised: January 1, 2026</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-teal-400"></span>
                        <span>GDPR & PECA Compliant Principles</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         POLICY SUB-NAVIGATION TABS
    ============================================================ -->
    <div class="bg-white border-b border-slate-200 sticky top-20 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center space-x-1 sm:space-x-4 overflow-x-auto py-3 no-scrollbar text-sm font-semibold">
                <a href="terms.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
                    <span>📜 Terms & Conditions</span>
                </a>
                <a href="privacy.php" class="px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 font-bold flex-shrink-0 flex items-center gap-2">
                    <span>🔒 Privacy Policy</span>
                </a>
                <a href="refund-policy.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
                    <span>💳 Refund Policy</span>
                </a>
                <a href="cancellation-policy.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
                    <span>🔄 Cancellation Policy</span>
                </a>
                <a href="contact.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
                    <span>📞 Support & Contact</span>
                </a>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MAIN CONTENT AREA
    ============================================================ -->
    <main class="flex-grow py-12 sm:py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">

                <!-- Left Sticky Table of Contents -->
                <aside class="hidden lg:block lg:col-span-3">
                    <div class="sticky top-40 bg-white p-6 rounded-2xl border border-slate-200 shadow-xs">
                        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Table of Contents</h3>
                        <nav class="space-y-2 text-sm text-slate-600 font-medium">
                            <a href="#info-collected" class="block hover:text-emerald-600 transition-colors py-1">1. Information We Collect</a>
                            <a href="#how-we-use" class="block hover:text-emerald-600 transition-colors py-1">2. How We Use Your Data</a>
                            <a href="#data-sharing" class="block hover:text-emerald-600 transition-colors py-1">3. Data Sharing & Third Parties</a>
                            <a href="#location-data" class="block hover:text-emerald-600 transition-colors py-1">4. Proximity & Location Data</a>
                            <a href="#wallet-security" class="block hover:text-emerald-600 transition-colors py-1">5. Financial & Wallet Security</a>
                            <a href="#cookies" class="block hover:text-emerald-600 transition-colors py-1">6. Cookies & Session Storage</a>
                            <a href="#user-rights" class="block hover:text-emerald-600 transition-colors py-1">7. Your Data Rights</a>
                            <a href="#retention" class="block hover:text-emerald-600 transition-colors py-1">8. Data Retention & Deletion</a>
                            <a href="#privacy-contact" class="block hover:text-emerald-600 transition-colors py-1">9. Privacy Contact Office</a>
                        </nav>

                        <div class="mt-6 pt-6 border-t border-slate-100">
                            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Privacy Questions?</div>
                            <a href="mailto:abdullahtariq0505@gmail.com" class="text-xs font-bold text-emerald-600 break-all block hover:underline">✉️ abdullahtariq0505@gmail.com</a>
                            <a href="tel:03137970801" class="text-xs text-slate-500 hover:text-emerald-600 block mt-1">📞 03137970801</a>
                        </div>
                    </div>
                </aside>

                <!-- Right Content -->
                <div class="lg:col-span-9 space-y-8">

                    <!-- Section 1 -->
                    <section id="info-collected" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">01</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Information We Collect</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed mb-4">
                            ArenaReserve collects information necessary to deliver seamless ground reservation, challenge matchmaking, and wallet management services.
                        </p>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                                <h4 class="font-bold text-slate-900 text-sm mb-1">👤 Profile & Account Data</h4>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Full Name, Email Address, 11-digit Phone Number, City of residence, Profile Avatar, and User Role (Player / Venue Owner).
                                </p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                                <h4 class="font-bold text-slate-900 text-sm mb-1">💳 Transaction & Wallet Data</h4>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Slot booking records, match challenge commitments, escrow balances, and uploaded bank transfer deposit slips.
                                </p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                                <h4 class="font-bold text-slate-900 text-sm mb-1">📍 Geolocation & Device Data</h4>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Player GPS latitude/longitude (when granted) to calculate proximity distance (in km) to nearby sports venues.
                                </p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                                <h4 class="font-bold text-slate-900 text-sm mb-1">🏟️ Venue Owner Listing Data</h4>
                                <p class="text-xs text-slate-600 leading-relaxed">
                                    Sports ground title, physical address, sport types, pitch dimensions, pricing schedules, and ground photos.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Section 2 -->
                    <section id="how-we-use" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">02</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">How We Use Your Data</h2>
                        </div>
                        <ul class="list-disc pl-5 space-y-2 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <li>To process, confirm, and verify slot reservations between players and sports arenas.</li>
                            <li>To calculate geographic proximity and display the nearest sports grounds within chosen radii (5km, 10km, 25km, 50km).</li>
                            <li>To facilitate 50/50 split challenge invitations and notify opponents of upcoming matches.</li>
                            <li>To deliver critical transactional emails (email verification, booking receipts, password reset links).</li>
                            <li>To prevent fraudulent chargebacks, double-booking abuses, or malicious activities.</li>
                        </ul>
                    </section>

                    <!-- Section 3 -->
                    <section id="data-sharing" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">03</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Data Sharing & Third Parties</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>We <strong>do not sell, rent, or trade</strong> your personal identifying data to marketing brokers or third-party advertisers. Data is strictly shared with:</p>
                            <ul class="list-disc pl-5 space-y-2">
                                <li><strong>Ground Venue Owners:</strong> When you book a slot, the venue owner receives your player name and booking reference to admit you into the venue.</li>
                                <li><strong>Authentication & Email Providers:</strong> Secure services (such as Google OAuth for one-click login, PHPMailer / transactional SMTP for email deliveries).</li>
                                <li><strong>Law Enforcement & Regulators:</strong> When legally mandated under the Prevention of Electronic Crimes Act (PECA) or valid Pakistani court order.</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Section 4 -->
                    <section id="location-data" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">04</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Proximity & Location Services</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                            When browsing grounds on our Explore page, our platform requests temporary browser location access to sort grounds by geographic distance using the Haversine formula. Your real-time location is never permanently logged or broadcasted to other players. If location permissions are denied, the system defaults to city-level center coordinates.
                        </p>
                    </section>

                    <!-- Section 5 -->
                    <section id="wallet-security" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">05</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Financial & Wallet Data Security</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>
                                All password hashes utilize cryptographically secure <code>BCRYPT</code> algorithms. Wallet balances and escrow holds are protected through database-level row locking (<code>FOR UPDATE</code> transactions) to ensure balance integrity and prevent race conditions.
                            </p>
                        </div>
                    </section>

                    <!-- Section 6 -->
                    <section id="cookies" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">06</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Cookies & Session Management</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                            We use secure PHP session cookies to maintain your authenticated state across platform dashboards. These cookies do not track you across third-party websites and expire upon browser closure or account sign out.
                        </p>
                    </section>

                    <!-- Section 7 -->
                    <section id="user-rights" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">07</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Your Data Rights</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>As a registered ArenaReserve user, you have the right to:</p>
                            <ul class="list-disc pl-5 space-y-2">
                                <li><strong>Access & Update:</strong> Modify your profile name, city, phone number, and avatar directly via the Profile settings.</li>
                                <li><strong>Data Portability:</strong> Request an export of your match booking history and wallet transaction ledger.</li>
                                <li><strong>Account Deletion:</strong> Request permanent removal of your account and personal identifiers by contacting our data protection desk.</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Section 8 -->
                    <section id="retention" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">08</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Data Retention</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                            We retain booking records, receipt logs, and match statistics for as long as your account remains active or as required to comply with financial auditing, accounting standards, and tax obligations under Pakistani law.
                        </p>
                    </section>

                    <!-- Section 9: Contact -->
                    <section id="privacy-contact" class="rounded-2xl bg-gradient-to-r from-emerald-800 to-teal-900 p-6 sm:p-8 text-white shadow-lg">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/10 flex items-center justify-center text-2xl flex-shrink-0">
                                🔒
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-white">Data Protection & Privacy Desk</h3>
                                <p class="text-emerald-100 text-sm mt-1 leading-relaxed">
                                    For data access requests, privacy concerns, or account removal inquiries, reach out directly to our designated data officer:
                                </p>
                                <div class="mt-4 flex flex-wrap items-center gap-4 text-sm font-semibold">
                                    <a href="mailto:abdullahtariq0505@gmail.com" class="px-4 py-2 rounded-xl bg-white text-emerald-900 hover:bg-emerald-50 transition-colors flex items-center gap-2">
                                        <span>✉️ abdullahtariq0505@gmail.com</span>
                                    </a>
                                    <a href="tel:03137970801" class="px-4 py-2 rounded-xl bg-emerald-700/80 hover:bg-emerald-700 text-white border border-emerald-500/50 transition-colors flex items-center gap-2">
                                        <span>📞 03137970801</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </section>

                </div>
            </div>
        </div>
    </main>

    <!-- ============================================================
         FOOTER
    ============================================================ -->
    <footer class="bg-slate-900 text-slate-400 py-16 border-t border-slate-800 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-10">
                <!-- Col 1: Brand & Contact -->
                <div class="md:col-span-5">
                    <a href="landing.php" class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-emerald-600 flex items-center justify-center text-white">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z" />
                            </svg>
                        </div>
                        <span class="text-xl font-black text-white tracking-tight">Arena<span class="text-emerald-500">Reserve</span></span>
                    </a>
                    <p class="mt-4 text-sm text-slate-400 leading-relaxed max-w-sm">
                        Pakistan's premier sports ground booking network. Empowering players, teams, and venue owners with instantaneous digital reservations.
                    </p>
                    <div class="mt-4 text-xs text-slate-400 space-y-1">
                        <div>📞 Phone / WhatsApp: <a href="tel:03137970801" class="text-emerald-400 font-bold hover:underline">03137970801</a></div>
                        <div>✉️ Support Email: <a href="mailto:abdullahtariq0505@gmail.com" class="text-emerald-400 font-bold hover:underline">abdullahtariq0505@gmail.com</a></div>
                    </div>
                    <div class="mt-6 text-xs text-slate-500">
                        &copy; 2026 ArenaReserve. All rights reserved.
                    </div>
                </div>

                <!-- Col 2: Legal Policies -->
                <div class="md:col-span-3">
                    <h4 class="text-xs font-bold text-slate-200 uppercase tracking-wider mb-4">Compliance Policies</h4>
                    <ul class="space-y-2.5 text-sm">
                        <li><a href="terms.php" class="hover:text-emerald-400 transition-colors">Terms & Conditions</a></li>
                        <li><a href="privacy.php" class="text-emerald-400 font-semibold hover:underline">Privacy Policy</a></li>
                        <li><a href="refund-policy.php" class="hover:text-emerald-400 transition-colors">Refund Policy</a></li>
                        <li><a href="cancellation-policy.php" class="hover:text-emerald-400 transition-colors">Cancellation Policy</a></li>
                        <li><a href="contact.php" class="hover:text-emerald-400 transition-colors">Contact & Support</a></li>
                    </ul>
                </div>

                <!-- Col 3: Navigation & Auth -->
                <div class="md:col-span-4">
                    <h4 class="text-xs font-bold text-slate-200 uppercase tracking-wider mb-4">Account & Access</h4>
                    <div class="flex flex-col gap-2.5">
                        <a href="login.php" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-semibold text-center transition-colors">
                            Player & Owner Login
                        </a>
                        <a href="signup.php" class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold text-center transition-colors">
                            Create New Account
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>
