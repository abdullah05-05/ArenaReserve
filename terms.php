<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
$active_policy = 'terms';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms & Conditions — ArenaReserve</title>
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
    $page_description = 'ArenaReserve Terms & Conditions — Legal terms governing ground bookings, match challenges, wallets, and user conduct.';
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
                    <span>📜 Legal Compliance</span>
                </div>
                <h1 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight text-white">
                    Terms & Conditions
                </h1>
                <p class="mt-4 text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
                    Please read these terms carefully before utilizing the ArenaReserve platform for booking sports facilities, hosting match challenges, or managing venues.
                </p>
                <div class="mt-6 flex flex-wrap items-center gap-6 text-xs text-slate-400 font-medium">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        <span>Effective Date: January 1, 2026</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-teal-400"></span>
                        <span>Governing Law: Islamic Republic of Pakistan</span>
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
                <a href="terms.php" class="px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 font-bold flex-shrink-0 flex items-center gap-2">
                    <span>📜 Terms & Conditions</span>
                </a>
                <a href="privacy.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
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
                            <a href="#acceptance" class="block hover:text-emerald-600 transition-colors py-1">1. Acceptance of Terms</a>
                            <a href="#eligibility" class="block hover:text-emerald-600 transition-colors py-1">2. User Accounts & Eligibility</a>
                            <a href="#booking-rules" class="block hover:text-emerald-600 transition-colors py-1">3. Ground Bookings & Payments</a>
                            <a href="#challenge-rules" class="block hover:text-emerald-600 transition-colors py-1">4. 50/50 Match Challenges</a>
                            <a href="#owner-obligations" class="block hover:text-emerald-600 transition-colors py-1">5. Ground Owner Obligations</a>
                            <a href="#wallet-escrow" class="block hover:text-emerald-600 transition-colors py-1">6. Digital Wallet & Escrow</a>
                            <a href="#conduct" class="block hover:text-emerald-600 transition-colors py-1">7. Code of Conduct & Safety</a>
                            <a href="#liability" class="block hover:text-emerald-600 transition-colors py-1">8. Limitation of Liability</a>
                            <a href="#jurisdiction" class="block hover:text-emerald-600 transition-colors py-1">9. Governing Law & Disputes</a>
                            <a href="#contact-clauses" class="block hover:text-emerald-600 transition-colors py-1">10. Contact & Inquiries</a>
                        </nav>

                        <div class="mt-6 pt-6 border-t border-slate-100">
                            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Need Immediate Help?</div>
                            <a href="tel:03137970801" class="text-sm font-bold text-emerald-600 hover:underline block">📞 03137970801</a>
                            <a href="mailto:abdullahtariq0505@gmail.com" class="text-xs text-slate-500 hover:text-emerald-600 break-all block mt-1">✉️ abdullahtariq0505@gmail.com</a>
                        </div>
                    </div>
                </aside>

                <!-- Right Legal Text Content -->
                <div class="lg:col-span-9 space-y-8">

                    <!-- Section 1 -->
                    <section id="acceptance" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">01</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Acceptance of Terms</h2>
                        </div>
                        <p class="text-slate-600 leading-relaxed text-sm sm:text-base">
                            Welcome to <strong>ArenaReserve</strong> ("Platform", "we", "our", or "us"). By accessing or using our website, digital platform, mobile services, or creating an account, you agree to be bound by these Terms & Conditions and all related compliance policies, including our <a href="privacy.php" class="text-emerald-600 font-semibold hover:underline">Privacy Policy</a>, <a href="refund-policy.php" class="text-emerald-600 font-semibold hover:underline">Refund Policy</a>, and <a href="cancellation-policy.php" class="text-emerald-600 font-semibold hover:underline">Cancellation Policy</a>.
                        </p>
                        <p class="mt-3 text-slate-600 leading-relaxed text-sm sm:text-base">
                            If you do not agree with any portion of these terms, you must immediately discontinue use of the Platform.
                        </p>
                    </section>

                    <!-- Section 2 -->
                    <section id="eligibility" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">02</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">User Accounts & Eligibility</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>To access booking and venue hosting services, users must register an account under one of the supported platform roles: <strong>Player</strong> or <strong>Owner</strong>.</p>
                            <ul class="list-disc pl-5 space-y-2 mt-2">
                                <li><strong>Accuracy of Information:</strong> You agree to provide accurate, complete, and updated registration details, including your full legal name, valid email address, Pakistani phone number, and city.</li>
                                <li><strong>Account Security:</strong> You are strictly responsible for maintaining the confidentiality of your password and login credentials. Any activity taking place under your account is deemed your legal responsibility.</li>
                                <li><strong>Age Requirement:</strong> Users must be at least 13 years of age. Users under 18 must have parental or legal guardian consent to participate in slot bookings and financial transactions.</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Section 3 -->
                    <section id="booking-rules" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">03</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Ground Bookings & Direct Slot Reservations</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>
                                When a Player books an available slot through ArenaReserve, the reservation is locked and confirmed instantly upon deduction of payment from the user's available wallet balance.
                            </p>
                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 my-3 text-sm text-slate-700">
                                <span class="font-bold text-emerald-700">📌 Time Slot Lock:</span> Once a slot is selected, a 10-minute temporary hold is enforced to prevent double-booking while the player completes payment.
                            </div>
                            <p>
                                Confirmed bookings guarantee exclusive access to the designated pitch, court, or turf for the booked 1-hour interval, subject to venue facility rules.
                            </p>
                        </div>
                    </section>

                    <!-- Section 4 -->
                    <section id="challenge-rules" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">04</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">50/50 Split Match Challenges</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>
                                ArenaReserve provides a dedicated matchmaking mechanism enabling teams and players to split venue reservation fees 50/50:
                            </p>
                            <ul class="list-disc pl-5 space-y-2">
                                <li><strong>Open Challenge:</strong> The initiating player pays 50% of the ground fee. The slot is held on the platform public board until an opposing team accepts and pays the remaining 50%.</li>
                                <li><strong>Private Team Challenge:</strong> An invitation is issued to a specific opposing squad. The opponent has until the designated match time or hold window to confirm and pay their 50% share.</li>
                                <li><strong>Accepted Status:</strong> Once both parties have funded their respective 50% shares, the booking becomes <span class="text-emerald-700 font-bold">challenge_accepted</span> and cannot be unilaterally cancelled by either player without administrative intervention.</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Section 5 -->
                    <section id="owner-obligations" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">05</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Ground Owner Rights & Obligations</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>Sports facility owners who list venues on ArenaReserve represent and warrant that:</p>
                            <ul class="list-disc pl-5 space-y-2">
                                <li>They hold all lawful rights, permits, and municipal licenses to operate the sports venue at the listed address.</li>
                                <li>The venue will be maintained in safe, playable, and sanitary conditions with operating floodlights, netting, and marked boundaries during booked slots.</li>
                                <li>Ground owners shall honor all confirmed digital bookings made through the platform and shall not double-book slots for off-platform walk-in clients.</li>
                                <li>Payouts for completed bookings are credited to the owner's digital wallet in accordance with agreed commission schedules.</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Section 6 -->
                    <section id="wallet-escrow" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">06</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Digital Wallet, Escrow & Payments</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>
                                All financial transactions on ArenaReserve are managed through individual user wallets:
                            </p>
                            <ul class="list-disc pl-5 space-y-2">
                                <li><strong>Available Balance:</strong> Funds available for immediate slot reservations and entry fees.</li>
                                <li><strong>Frozen Escrow Balance:</strong> Funds committed to pending challenges or disputed bookings, securely safeguarded until match resolution or cancellation.</li>
                                <li><strong>Top-ups & Proof of Transfer:</strong> Manual bank top-ups require verified transaction receipt uploads and approval by administrative moderators.</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Section 7 -->
                    <section id="conduct" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">07</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Code of Conduct & Venue Rules</h2>
                        </div>
                        <div class="space-y-3 text-slate-600 text-sm sm:text-base leading-relaxed">
                            <p>Players and venue guests must adhere to the highest standards of sportsmanship and respect:</p>
                            <ul class="list-disc pl-5 space-y-2">
                                <li>Zero tolerance for violence, physical altercation, verbal abuse, hate speech, or harassment.</li>
                                <li>Players must respect scheduled time slots and clear the playing pitch promptly at the end of their hour.</li>
                                <li>Intentional damage to turf, floodlights, goals, nets, or venue equipment will result in immediate permanent account suspension and financial liability for repair.</li>
                            </ul>
                        </div>
                    </section>

                    <!-- Section 8 -->
                    <section id="liability" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">08</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Limitation of Liability</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                            ArenaReserve functions as a digital booking and management technology intermediary connecting players and independent ground owners. ArenaReserve is not liable for personal injuries, physical harm, theft of personal belongings, or property loss sustained at third-party sports facilities. Players participate in athletic activities at their own risk.
                        </p>
                    </section>

                    <!-- Section 9 -->
                    <section id="jurisdiction" class="legal-card p-6 sm:p-8">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-black text-sm flex items-center justify-center">09</span>
                            <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Governing Law & Dispute Resolution</h2>
                        </div>
                        <p class="text-slate-600 text-sm sm:text-base leading-relaxed">
                            These Terms & Conditions are governed by and construed in accordance with the laws of the <strong>Islamic Republic of Pakistan</strong>. Any legal disputes arising out of the use of the platform shall be subject to the exclusive jurisdiction of the competent courts in Pakistan.
                        </p>
                    </section>

                    <!-- Section 10: Official Contact -->
                    <section id="contact-clauses" class="rounded-2xl bg-gradient-to-r from-emerald-800 to-teal-900 p-6 sm:p-8 text-white shadow-lg">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/10 flex items-center justify-center text-2xl flex-shrink-0">
                                📞
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-white">Questions About These Terms?</h3>
                                <p class="text-emerald-100 text-sm mt-1 leading-relaxed">
                                    Our compliance and operations support team is available 7 days a week to clarify any legal or booking questions.
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
                        <div>📍 Address: 17-km Sheikhupura Road, Shah Zaman Park near Mughal Steel, Lahore</div>
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
                        <li><a href="terms.php" class="text-emerald-400 font-semibold hover:underline">Terms & Conditions</a></li>
                        <li><a href="privacy.php" class="hover:text-emerald-400 transition-colors">Privacy Policy</a></li>
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
