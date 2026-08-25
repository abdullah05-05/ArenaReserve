<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
$active_policy = 'cancellation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellation Policy — ArenaReserve</title>
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
    $page_description = 'ArenaReserve Cancellation Policy — Comprehensive cancellation rules, time windows, and venue owner protection guarantees.';
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
                    <span>🔄 Reservation Management</span>
                </div>
                <h1 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight text-white">
                    Cancellation Policy
                </h1>
                <p class="mt-4 text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
                    Understand how slot cancellations work, required advance notice windows, and venue owner protection guarantees.
                </p>
                <div class="mt-6 flex flex-wrap items-center gap-6 text-xs text-slate-400 font-medium">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        <span>Free Cancellation Window: > 24 Hours Prior</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                        <span>Late Cancellation Fee: 50% (Within 24 Hours)</span>
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
                <a href="privacy.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
                    <span>🔒 Privacy Policy</span>
                </a>
                <a href="refund-policy.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
                    <span>💳 Refund Policy</span>
                </a>
                <a href="cancellation-policy.php" class="px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 font-bold flex-shrink-0 flex items-center gap-2">
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
                        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Quick Navigation</h3>
                        <nav class="space-y-2 text-sm text-slate-600 font-medium">
                            <a href="#rules-summary" class="block hover:text-emerald-600 transition-colors py-1">1. Cancellation Tiers</a>
                            <a href="#how-to-cancel" class="block hover:text-emerald-600 transition-colors py-1">2. How to Cancel a Slot</a>
                            <a href="#owner-protection" class="block hover:text-emerald-600 transition-colors py-1">3. Venue Owner Protection</a>
                            <a href="#challenge-rules" class="block hover:text-emerald-600 transition-colors py-1">4. Match Challenges</a>
                            <a href="#owner-cancellations" class="block hover:text-emerald-600 transition-colors py-1">5. Owner Cancellations</a>
                            <a href="#support-help" class="block hover:text-emerald-600 transition-colors py-1">6. Support Assistance</a>
                        </nav>

                        <div class="mt-6 pt-6 border-t border-slate-100">
                            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Need Urgent Cancellation?</div>
                            <a href="tel:03137970801" class="text-sm font-bold text-emerald-600 hover:underline block">📞 03137970801</a>
                            <a href="mailto:abdullahtariq0505@gmail.com" class="text-xs text-slate-500 hover:text-emerald-600 break-all block mt-1">✉️ abdullahtariq0505@gmail.com</a>
                        </div>
                    </div>
                </aside>

                <!-- Right Content -->
                <div class="lg:col-span-9 space-y-8">

                    <!-- Section 1: Cancellation Tiers -->
                    <section id="rules-summary" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">01</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Player Direct Cancellation Tiers</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed mb-6">
                            When players reserve ground slots, our automated cancellation engine computes exact hourly notice based on the ground's local match time:
                        </p>

                        <div class="space-y-4">
                            <!-- Tier 1 -->
                            <div class="p-5 rounded-2xl bg-emerald-50/70 border border-emerald-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white font-black text-xs">TIER 1</span>
                                        <h4 class="font-extrabold text-emerald-900 text-base">Standard Cancellation (> 24 Hours Notice)</h4>
                                    </div>
                                    <span class="text-emerald-700 font-bold text-sm">100% Wallet Refund</span>
                                </div>
                                <p class="text-xs sm:text-sm text-slate-600 mt-2 leading-relaxed">
                                    If you cancel more than 24 hours prior to the scheduled slot start time, you receive a full 100% refund immediately credited to your ArenaReserve wallet. The slot is reopened on the public calendar.
                                </p>
                            </div>

                            <!-- Tier 2 -->
                            <div class="p-5 rounded-2xl bg-amber-50/70 border border-amber-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2.5 py-1 rounded-lg bg-amber-600 text-white font-black text-xs">TIER 2</span>
                                        <h4 class="font-extrabold text-amber-900 text-base">Late Cancellation (≤ 24 Hours Notice)</h4>
                                    </div>
                                    <span class="text-amber-700 font-bold text-sm">50% Wallet Refund</span>
                                </div>
                                <p class="text-xs sm:text-sm text-slate-600 mt-2 leading-relaxed">
                                    Because grounds turn away walk-in players to hold booked slots, cancellations within 24 hours incur a 50% late cancellation fee. 50% is refunded to your wallet, and 95% of the retained fee is directly credited to the venue owner.
                                </p>
                            </div>

                            <!-- Tier 3 -->
                            <div class="p-5 rounded-2xl bg-rose-50/70 border border-rose-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2.5 py-1 rounded-lg bg-rose-600 text-white font-black text-xs">TIER 3</span>
                                        <h4 class="font-extrabold text-rose-900 text-base">Active or Past Slot Time</h4>
                                    </div>
                                    <span class="text-rose-700 font-bold text-sm">Non-Cancellable</span>
                                </div>
                                <p class="text-xs sm:text-sm text-slate-600 mt-2 leading-relaxed">
                                    Once the scheduled slot time has started or passed, the reservation is permanently finalized and cannot be cancelled or refunded.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Section 2: How to Cancel -->
                    <section id="how-to-cancel" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">02</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">How to Cancel Your Booking in 3 Steps</h2>
                        </div>
                        <div class="grid sm:grid-cols-3 gap-4">
                            <div class="p-4 bg-slate-50 rounded-xl border border-slate-100">
                                <div class="text-emerald-600 font-black text-lg mb-1">Step 1</div>
                                <h4 class="font-bold text-slate-800 text-sm">Go to Match History</h4>
                                <p class="text-xs text-slate-500 mt-1">Navigate to your Match History tab from the top navigation bar.</p>
                            </div>
                            <div class="p-4 bg-slate-50 rounded-xl border border-slate-100">
                                <div class="text-emerald-600 font-black text-lg mb-1">Step 2</div>
                                <h4 class="font-bold text-slate-800 text-sm">Select Active Booking</h4>
                                <p class="text-xs text-slate-500 mt-1">Locate the upcoming match slot you want to release and click 'Cancel Booking'.</p>
                            </div>
                            <div class="p-4 bg-slate-50 rounded-xl border border-slate-100">
                                <div class="text-emerald-600 font-black text-lg mb-1">Step 3</div>
                                <h4 class="font-bold text-slate-800 text-sm">Instant Wallet Credit</h4>
                                <p class="text-xs text-slate-500 mt-1">Review the cancellation preview and confirm. Funds are instantly returned to your wallet.</p>
                            </div>
                        </div>
                    </section>

                    <!-- Section 3: Owner Protection -->
                    <section id="owner-protection" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">03</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Venue Owner Compensation Guarantee</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                            ArenaReserve guarantees sports venue partners protection against revenue losses caused by last-minute dropouts. When a player initiates a cancellation within 24 hours:
                        </p>
                        <ul class="list-disc pl-5 space-y-2 text-slate-600 text-sm sm:text-base leading-relaxed mt-3">
                            <li><strong>95% of the retained late fee</strong> is automatically transferred directly into the venue owner's wallet balance.</li>
                            <li><strong>5% platform fee</strong> covers operational handling and payment infrastructure costs.</li>
                            <li>The canceled slot is instantly marked available on the public calendar, allowing another team to rebook the ground.</li>
                        </ul>
                    </section>

                    <!-- Section 4: Match Challenges -->
                    <section id="challenge-rules" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">04</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">50/50 Match Challenge Cancellations</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>
                                <strong>Unaccepted Challenges:</strong> If no team has accepted your open challenge, you may cancel at any moment prior to the slot with a 100% immediate refund.
                            </p>
                            <p>
                                <strong>Accepted Challenges:</strong> Both squads have locked their 50% split. To protect scheduled rivalries and preserve competitive fairness, accepted challenges cannot be cancelled from the match history table. If both squads mutually agree to reschedule or call off a fixture, contact ArenaReserve support.
                            </p>
                        </div>
                    </section>

                    <!-- Section 5: Owner Cancellations -->
                    <section id="owner-cancellations" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">05</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Owner-Initiated Cancellations & Penalties</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                            Arena owners must maintain accurate slot schedules. In the rare event that a ground owner is forced to cancel due to unforeseen pitch maintenance or facility breakdown:
                        </p>
                        <ul class="list-disc pl-5 space-y-2 text-slate-600 text-sm sm:text-base leading-relaxed mt-3">
                            <li>Affected players receive an immediate <strong>100% full refund</strong> plus priority rebooking support.</li>
                            <li>Unwarranted repeated cancellations by venue owners may result in temporary delisting or lower algorithmic discovery ranking.</li>
                        </ul>
                    </section>

                    <!-- Section 6: Support -->
                    <section id="support-help" class="rounded-2xl bg-gradient-to-r from-emerald-800 to-teal-900 p-6 sm:p-8 text-white shadow-lg">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/10 flex items-center justify-center text-2xl flex-shrink-0">
                                📞
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-white">Need Help With a Cancellation?</h3>
                                <p class="text-emerald-100 text-sm mt-1 leading-relaxed">
                                    Our live operations desk is available 7 days a week from 9:00 AM to 11:00 PM PKT for emergency match cancellations or challenge reconciliations.
                                </p>
                                <div class="mt-4 flex flex-wrap items-center gap-4 text-sm font-semibold">
                                    <a href="tel:03137970801" class="px-4 py-2 rounded-xl bg-white text-emerald-900 hover:bg-emerald-50 transition-colors flex items-center gap-2">
                                        <span>📞 03137970801</span>
                                    </a>
                                    <a href="mailto:abdullahtariq0505@gmail.com" class="px-4 py-2 rounded-xl bg-emerald-700/80 hover:bg-emerald-700 text-white border border-emerald-500/50 transition-colors flex items-center gap-2">
                                        <span>✉️ abdullahtariq0505@gmail.com</span>
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
                        <li><a href="privacy.php" class="hover:text-emerald-400 transition-colors">Privacy Policy</a></li>
                        <li><a href="refund-policy.php" class="hover:text-emerald-400 transition-colors">Refund Policy</a></li>
                        <li><a href="cancellation-policy.php" class="text-emerald-400 font-semibold hover:underline">Cancellation Policy</a></li>
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
