<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
$active_policy = 'contact';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact & Support — ArenaReserve</title>
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
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #e2e8f0;
            border-radius: 1.5rem;
        }
        .btn-glow {
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.45);
            transition: all 0.3s ease;
        }
        .btn-glow:hover {
            box-shadow: 0 15px 35px -5px rgba(16, 185, 129, 0.65);
            transform: translateY(-2px);
        }
        html {
            scroll-behavior: smooth;
        }
    </style>
    <?php
    $page_description = 'Contact ArenaReserve Support — Reach our operations team for ground reservations, match challenges, refund queries, or venue listings.';
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
                    <a href="contact.php" class="text-emerald-600 font-bold transition-colors py-1">Support</a>
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
                    <span>📞 24/7 Support Network</span>
                </div>
                <h1 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight text-white">
                    Contact & Support Hub
                </h1>
                <p class="mt-4 text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
                    Have questions about booking slots, match challenges, or listing your sports facility? Our dedicated operations desk is available 7 days a week.
                </p>
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
                <a href="cancellation-policy.php" class="px-4 py-2 rounded-xl text-slate-600 hover:text-emerald-600 hover:bg-slate-50 transition-colors flex-shrink-0 flex items-center gap-2">
                    <span>🔄 Cancellation Policy</span>
                </a>
                <a href="contact.php" class="px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 font-bold flex-shrink-0 flex items-center gap-2">
                    <span>📞 Support & Contact</span>
                </a>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MAIN CONTACT & INQUIRY SECTION
    ============================================================ -->
    <main class="flex-grow py-12 sm:py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-10 lg:gap-12 items-start">
                
                <!-- Left: Contact Details Cards -->
                <div class="lg:col-span-5 space-y-6">
                    <!-- Card 1: Phone / WhatsApp -->
                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0 text-xl font-bold">
                            📞
                        </div>
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">Phone & WhatsApp Support</div>
                            <a href="tel:03137970801" class="text-lg font-black text-slate-900 hover:text-emerald-600 transition-colors block mt-1">
                                03137970801
                            </a>
                            <div class="text-xs text-slate-500 mt-1 font-medium">Mon – Sun: 9:00 AM – 11:00 PM PKT</div>
                        </div>
                    </div>

                    <!-- Card 2: Email -->
                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-teal-100 text-teal-700 flex items-center justify-center flex-shrink-0 text-xl font-bold">
                            ✉️
                        </div>
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">Direct Support Email</div>
                            <a href="mailto:abdullahtariq0505@gmail.com" class="text-base font-black text-slate-900 hover:text-emerald-600 transition-colors block mt-1 break-all">
                                abdullahtariq0505@gmail.com
                            </a>
                            <div class="text-xs text-slate-500 mt-1 font-medium">Average Response: Under 2 Hours</div>
                        </div>
                    </div>

                    <!-- Card 3: Location -->
                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-violet-100 text-violet-700 flex items-center justify-center flex-shrink-0 text-xl font-bold">
                            📍
                        </div>
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">Head Office</div>
                            <div class="text-sm font-bold text-slate-900 mt-1 leading-snug">
                                ArenaReserve Sports Tech HQ
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5 leading-relaxed">
                                Phase 5 DHA & Gulberg III, Lahore, Pakistan
                            </div>
                        </div>
                    </div>

                    <!-- Card 4: Venue Owner Hotline -->
                    <div class="rounded-3xl bg-gradient-to-br from-emerald-600 to-teal-700 p-6 text-white shadow-lg">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">⚡</span>
                            <div>
                                <h4 class="text-base font-black">Ground Owner Onboarding Hotline</h4>
                                <p class="text-xs text-emerald-100 mt-1 leading-relaxed">
                                    Want to list your sports ground, cricket turf, or futsal arena? Reach out via WhatsApp at <strong class="text-white">03137970801</strong> for rapid onboarding.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Contact Message Form -->
                <div class="lg:col-span-7 glass-card p-8 sm:p-10 shadow-sm border border-slate-200">
                    <h3 class="text-2xl font-black text-slate-900 tracking-tight mb-2">Send Direct Inquiry</h3>
                    <p class="text-xs sm:text-sm text-slate-500 mb-8 leading-relaxed">
                        Fill out the form below and our operations desk will reach out shortly.
                    </p>

                    <form onsubmit="handleContactSubmit(event)" class="space-y-5">
                        <div id="contactFeedback" class="hidden rounded-xl p-4 text-xs font-bold"></div>

                        <div class="grid sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Your Full Name</label>
                                <input type="text" required placeholder="Ali Ahmed" class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Email Address</label>
                                <input type="email" required placeholder="ali@example.com" class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                            </div>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Phone Number</label>
                                <input type="tel" placeholder="0313 1234567" class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Subject / Category</label>
                                <select class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                                    <option value="booking">Slot Booking Assistance</option>
                                    <option value="refund">Refund / Cancellation Query</option>
                                    <option value="challenge">50/50 Match Challenge Dispute</option>
                                    <option value="owner">Venue Listing & Partnership</option>
                                    <option value="other">General Inquiry</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Your Message</label>
                            <textarea rows="4" required placeholder="Please describe your question or booking reference..." class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white"></textarea>
                        </div>

                        <button type="submit" class="btn-glow w-full py-4 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold rounded-2xl flex items-center justify-center gap-2">
                            <span>Send Message</span>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </button>
                    </form>
                </div>

            </div>

            <!-- FAQ Section -->
            <div class="mt-16 pt-12 border-t border-slate-200">
                <h3 class="text-2xl font-black text-slate-900 text-center mb-8">Frequently Asked Support Questions</h3>
                <div class="grid md:grid-cols-2 gap-6">
                    <div class="bg-white p-6 rounded-2xl border border-slate-200">
                        <h4 class="font-bold text-slate-900 text-base mb-2">How fast are wallet refunds processed?</h4>
                        <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                            Cancellations initiated through the Match History dashboard credit eligible refund amounts to your ArenaReserve wallet immediately in real-time.
                        </p>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-slate-200">
                        <h4 class="font-bold text-slate-900 text-base mb-2">How does a 50/50 challenge work?</h4>
                        <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                            You pay 50% upfront. An opposing team accepts and funds the other 50%. Both squads meet at the arena and battle for leaderboard glory!
                        </p>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-slate-200">
                        <h4 class="font-bold text-slate-900 text-base mb-2">What if weather prevents the match from being played?</h4>
                        <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                            In severe rain or unplayable ground conditions, report the issue to support via <a href="mailto:abdullahtariq0505@gmail.com" class="text-emerald-600 font-bold hover:underline">abdullahtariq0505@gmail.com</a> for an immediate 100% wallet reimbursement.
                        </p>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-slate-200">
                        <h4 class="font-bold text-slate-900 text-base mb-2">How do ground owners withdraw their earnings?</h4>
                        <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                            Owners can submit a withdrawal request from the Owner Dashboard to their Pakistani bank account, Easypaisa, or JazzCash wallet.
                        </p>
                    </div>
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
                        <li><a href="cancellation-policy.php" class="hover:text-emerald-400 transition-colors">Cancellation Policy</a></li>
                        <li><a href="contact.php" class="text-emerald-400 font-semibold hover:underline">Contact & Support</a></li>
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

    <script>
        function handleContactSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const feedback = document.getElementById('contactFeedback');
            
            feedback.className = 'rounded-xl p-4 text-xs font-bold bg-emerald-100 border border-emerald-300 text-emerald-800 flex items-center gap-2';
            feedback.innerHTML = '<span class="text-base">✓</span> Thank you! Your inquiry has been sent to our support desk (abdullahtariq0505@gmail.com). We will reach out promptly!';
            feedback.classList.remove('hidden');

            form.reset();
        }
    </script>
</body>
</html>
