<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ArenaReserve — Play Sports Anytime, Anywhere</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        /* ── Scroll-triggered animations ── */
        .reveal-on-scroll {
            opacity: 0;
            transform: translateY(32px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }
        .reveal-on-scroll.is-revealed {
            opacity: 1;
            transform: translateY(0);
        }

        .delay-100 { transition-delay: 100ms; }
        .delay-200 { transition-delay: 200ms; }
        .delay-300 { transition-delay: 300ms; }
        .delay-400 { transition-delay: 400ms; }

        /* ── Floating & Glowing Keyframes ── */
        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-14px) rotate(2deg); }
        }
        @keyframes floatReverse {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(12px) rotate(-2deg); }
        }
        @keyframes subtlePulse {
            0%, 100% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.06); opacity: 1; }
        }
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .animate-float-slow {
            animation: floatSlow 6s ease-in-out infinite;
        }
        .animate-float-rev {
            animation: floatReverse 7s ease-in-out infinite;
        }
        .animate-pulse-subtle {
            animation: subtlePulse 3s ease-in-out infinite;
        }

        /* ── Modern Glassmorphism ── */
        .glass-header {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            background: rgba(255, 255, 255, 0.82);
        }
        .glass-card {
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .glass-dark-card {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* ── Gradient text & CTA glows ── */
        .text-gradient-emerald {
            background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .btn-glow {
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.45);
            transition: all 0.3s ease;
        }
        .btn-glow:hover {
            box-shadow: 0 15px 35px -5px rgba(16, 185, 129, 0.65);
            transform: translateY(-2px);
        }

        /* ── Card interactive hover ── */
        .interactive-card {
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.35s ease, border-color 0.35s ease;
        }
        .interactive-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 40px -15px rgba(0, 0, 0, 0.08);
            border-color: rgba(16, 185, 129, 0.35);
        }

        /* ── Smooth Scrolling ── */
        html {
            scroll-behavior: smooth;
        }

        /* ── Mobile menu drawer ── */
        #mobileMenu {
            transition: max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
        }
        #mobileMenu.closed {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            pointer-events: none;
        }
        #mobileMenu.open {
            max-height: 480px;
            opacity: 1;
            overflow: visible;
            pointer-events: auto;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased overflow-x-hidden selection:bg-emerald-500 selection:text-white">

    <!-- ============================================================
         NAVBAR
    ============================================================ -->
    <header class="glass-header fixed top-0 left-0 right-0 z-50 border-b border-slate-200/70 transition-all duration-300">
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

                <!-- Desktop Navigation Links -->
                <nav class="hidden md:flex items-center gap-7 text-sm font-semibold text-slate-600">
                    <a href="#about" class="hover:text-emerald-600 transition-colors py-1">About Us</a>
                    <a href="#mission" class="hover:text-emerald-600 transition-colors py-1">Our Mission</a>
                    <a href="#features" class="hover:text-emerald-600 transition-colors py-1">Features</a>
                    <a href="#how-it-works" class="hover:text-emerald-600 transition-colors py-1">How It Works</a>
                    <a href="#venues" class="hover:text-emerald-600 transition-colors py-1">Sports</a>
                    <a href="#contact" class="hover:text-emerald-600 transition-colors py-1">Contact Us</a>
                </nav>

                <!-- Auth Buttons -->
                <div class="hidden md:flex items-center gap-3.5">
                    <a href="login.php" class="px-5 py-2.5 text-sm font-bold text-slate-700 hover:text-emerald-600 rounded-xl hover:bg-slate-100/80 transition-all">
                        Log In
                    </a>
                    <a href="signup.php" class="btn-glow px-5 py-2.5 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl flex items-center gap-2">
                        <span>Get Started</span>
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    </a>
                </div>

                <!-- Mobile Menu Button -->
                <button type="button" onclick="toggleMobileMenu()" class="md:hidden p-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 focus:outline-none" aria-label="Toggle navigation">
                    <svg id="hamburgerIcon" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Mobile Drawer -->
        <div id="mobileMenu" class="closed md:hidden bg-white/95 backdrop-blur-lg border-b border-slate-200 px-4 pt-2 pb-6 space-y-3 shadow-xl">
            <a href="#about" onclick="closeMobileMenu()" class="block px-3 py-2.5 rounded-lg text-base font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600">About Us</a>
            <a href="#mission" onclick="closeMobileMenu()" class="block px-3 py-2.5 rounded-lg text-base font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600">Our Mission</a>
            <a href="#features" onclick="closeMobileMenu()" class="block px-3 py-2.5 rounded-lg text-base font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600">Features</a>
            <a href="#how-it-works" onclick="closeMobileMenu()" class="block px-3 py-2.5 rounded-lg text-base font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600">How It Works</a>
            <a href="#venues" onclick="closeMobileMenu()" class="block px-3 py-2.5 rounded-lg text-base font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600">Sports</a>
            <a href="#contact" onclick="closeMobileMenu()" class="block px-3 py-2.5 rounded-lg text-base font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-600">Contact Us</a>
            
            <div class="pt-4 border-t border-slate-100 flex flex-col gap-2.5">
                <a href="login.php" class="w-full text-center py-3 text-sm font-bold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl">
                    Log In
                </a>
                <a href="signup.php" class="w-full text-center py-3 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl shadow-md">
                    Sign Up Free
                </a>
            </div>
        </div>
    </header>

    <!-- ============================================================
         HERO SECTION
    ============================================================ -->
    <section class="relative pt-32 pb-20 md:pt-40 md:pb-32 overflow-hidden bg-gradient-to-b from-emerald-50/50 via-slate-50 to-slate-50">
        <!-- Background decorative ambient circles -->
        <div class="absolute top-20 left-1/2 -translate-x-1/2 w-[700px] h-[500px] bg-emerald-300/20 rounded-full blur-3xl pointer-events-none -z-10 animate-pulse-subtle"></div>
        <div class="absolute -top-10 -right-20 w-96 h-96 bg-teal-200/25 rounded-full blur-3xl pointer-events-none -z-10"></div>
        <div class="absolute top-1/2 -left-20 w-80 h-80 bg-emerald-200/20 rounded-full blur-2xl pointer-events-none -z-10"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-8 items-center">
                <!-- Left Hero Text -->
                <div class="lg:col-span-7 text-center lg:text-left">
                    <div class="reveal-on-scroll inline-flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-100/80 border border-emerald-200 text-emerald-800 text-xs font-bold uppercase tracking-wider mb-6 shadow-sm">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                        The Ultimate Sports Ground Network
                    </div>

                    <h1 class="reveal-on-scroll delay-100 text-4xl sm:text-5xl lg:text-6xl font-black text-slate-900 tracking-tight leading-[1.12]">
                        Find, Book & Play at Premier <span class="text-gradient-emerald">Sports Arenas</span>
                    </h1>

                    <p class="reveal-on-scroll delay-200 mt-6 text-lg sm:text-xl text-slate-600 max-w-2xl mx-auto lg:mx-0 leading-relaxed font-normal">
                        Say goodbye to endless phone calls and double bookings. Discover top cricket stadiums, football pitches, futsal arenas, and basketball courts with instant live confirmation.
                    </p>

                    <!-- CTA Action Row -->
                    <div class="reveal-on-scroll delay-300 mt-8 sm:mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4">
                        <a href="signup.php" class="btn-glow w-full sm:w-auto px-8 py-4 bg-emerald-600 hover:bg-emerald-500 text-white text-base font-bold rounded-2xl flex items-center justify-center gap-3">
                            <span>Join ArenaReserve</span>
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </a>
                        <a href="login.php" class="w-full sm:w-auto px-8 py-4 bg-white hover:bg-slate-50 text-slate-700 text-base font-bold rounded-2xl border border-slate-200 shadow-sm flex items-center justify-center gap-2 transition-all hover:border-slate-300">
                            <span>Explore Grounds</span>
                            <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </a>
                    </div>

                    <!-- Highlight Badges -->
                    <div class="reveal-on-scroll delay-400 mt-12 pt-8 border-t border-slate-200/80 flex flex-wrap items-center justify-center lg:justify-start gap-6 sm:gap-10 text-slate-500 text-sm font-medium">
                        <div class="flex items-center gap-2.5">
                            <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs font-bold">✓</div>
                            <span>Instant Slot Locking</span>
                        </div>
                        <div class="flex items-center gap-2.5">
                            <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs font-bold">✓</div>
                            <span>Split Match Challenges</span>
                        </div>
                        <div class="flex items-center gap-2.5">
                            <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xs font-bold">✓</div>
                            <span>Verified Venues</span>
                        </div>
                    </div>
                </div>

                <!-- Right Visual: Mock Interactive Dashboard & Arena Cards -->
                <div class="lg:col-span-5 relative">
                    <!-- Floating Badge Top-Left -->
                    <div class="hidden sm:flex animate-float-slow absolute -top-8 -left-8 z-20 glass-card p-4 rounded-2xl shadow-xl flex items-center gap-3.5 border border-emerald-100">
                        <div class="w-11 h-11 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-xl shadow-md">
                            ⚡
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-900">Live Challenge Accepted!</div>
                            <div class="text-[11px] text-slate-500 font-medium">50% cost split confirmed</div>
                        </div>
                    </div>

                    <!-- Main Showcase Card -->
                    <div class="reveal-on-scroll glass-card rounded-3xl p-6 sm:p-7 shadow-2xl border border-white/60 relative z-10">
                        <div class="relative h-52 sm:h-60 rounded-2xl overflow-hidden mb-5 bg-slate-900 group">
                            <!-- Background preview gradient / visual -->
                            <div class="absolute inset-0 bg-gradient-to-tr from-slate-950 via-slate-900 to-emerald-950 opacity-90"></div>
                            
                            <!-- Sports Field Graphic Mockup -->
                            <div class="absolute inset-0 flex items-center justify-center opacity-25">
                                <svg class="w-3/4 h-3/4 text-emerald-400" viewBox="0 0 100 60" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="5" y="5" width="90" height="50" rx="3"/>
                                    <line x1="50" y1="5" x2="50" y2="55"/>
                                    <circle cx="50" cy="30" r="10"/>
                                    <rect x="5" y="18" width="15" height="24"/>
                                    <rect x="80" y="18" width="15" height="24"/>
                                </svg>
                            </div>

                            <div class="absolute top-3.5 left-3.5 flex items-center gap-2">
                                <span class="bg-emerald-500 text-white text-[11px] font-extrabold uppercase px-3 py-1 rounded-full tracking-wider shadow">
                                    Featured Arena
                                </span>
                                <span class="bg-slate-900/80 text-amber-400 text-xs font-bold px-2.5 py-1 rounded-full backdrop-blur-md">
                                    ★ 4.9 (128 reviews)
                                </span>
                            </div>

                            <div class="absolute bottom-4 left-4 right-4 text-white">
                                <h3 class="text-xl font-bold tracking-tight">National Sports Complex</h3>
                                <p class="text-xs text-slate-300 flex items-center gap-1 mt-0.5">
                                    <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    Gulberg III, Main Boulevard
                                </p>
                            </div>
                        </div>

                        <!-- Real-time interactive slot selector simulation -->
                        <div class="space-y-4">
                            <div class="flex items-center justify-between text-xs font-semibold text-slate-600">
                                <span>Select Game Hour:</span>
                                <span class="text-emerald-600 font-bold">Today, Available</span>
                            </div>
                            
                            <div class="grid grid-cols-4 gap-2">
                                <div class="py-2 text-center rounded-xl bg-slate-100 text-slate-400 text-xs font-medium line-through">06:00 PM</div>
                                <div class="py-2 text-center rounded-xl bg-emerald-600 text-white text-xs font-bold shadow-md shadow-emerald-500/20">07:00 PM ✓</div>
                                <div class="py-2 text-center rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold">08:00 PM</div>
                                <div class="py-2 text-center rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold">09:00 PM</div>
                            </div>

                            <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                                <div>
                                    <div class="text-[10px] uppercase font-bold text-slate-400">Total Slot Price</div>
                                    <div class="text-xl font-black text-slate-900">3,500 <span class="text-xs font-medium text-slate-500">PKR</span></div>
                                </div>
                                <a href="signup.php" class="px-5 py-2.5 bg-slate-900 hover:bg-emerald-600 text-white text-xs font-bold rounded-xl transition-colors shadow">
                                    Instant Book
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Badge Bottom-Right -->
                    <div class="hidden sm:flex animate-float-rev absolute -bottom-6 -right-6 z-20 glass-card p-4 rounded-2xl shadow-xl flex items-center gap-3.5 border border-emerald-100">
                        <div class="w-11 h-11 rounded-xl bg-violet-600 text-white flex items-center justify-center text-lg font-bold shadow-md">
                            🏆
                        </div>
                        <div>
                            <div class="text-xs font-bold text-slate-900">Live Team Matchmaking</div>
                            <div class="text-[11px] text-slate-500 font-medium">Rankings updated post match</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         ABOUT SECTION
    ============================================================ -->
    <section id="about" class="py-24 bg-white relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-16 items-center">
                <!-- Left Info Illustration / Story -->
                <div class="lg:col-span-5 reveal-on-scroll">
                    <div class="relative">
                        <div class="rounded-3xl bg-gradient-to-tr from-emerald-600 to-teal-500 p-8 sm:p-10 text-white shadow-2xl relative z-10 overflow-hidden">
                            <div class="w-16 h-16 rounded-2xl bg-white/15 backdrop-blur-md flex items-center justify-center text-3xl mb-6">
                                🏟️
                            </div>
                            <h3 class="text-2xl sm:text-3xl font-black tracking-tight mb-4">Born from a passion for true sportsmanship</h3>
                            <p class="text-emerald-50 text-sm sm:text-base leading-relaxed opacity-95">
                                We experienced the real frustration of booking turf grounds — unreturned messages, double bookings, lack of pricing transparency, and empty slots. ArenaReserve was built to digitize sports venues from the ground up.
                            </p>

                            <div class="mt-8 pt-6 border-t border-white/20 grid grid-cols-2 gap-4">
                                <div>
                                    <div class="text-2xl font-black">100%</div>
                                    <div class="text-xs text-emerald-100">Digital Automated Slots</div>
                                </div>
                                <div>
                                    <div class="text-2xl font-black">Zero</div>
                                    <div class="text-xs text-emerald-100">Double-Booking Risk</div>
                                </div>
                            </div>
                        </div>
                        <!-- Background Glow Accent -->
                        <div class="absolute -bottom-6 -right-6 w-full h-full bg-emerald-100 rounded-3xl -z-0"></div>
                    </div>
                </div>

                <!-- Right About Copy -->
                <div class="lg:col-span-7">
                    <div class="reveal-on-scroll">
                        <span class="text-xs font-extrabold uppercase tracking-widest text-emerald-600 bg-emerald-50 px-3.5 py-1.5 rounded-lg">About ArenaReserve</span>
                        <h2 class="text-3xl sm:text-4xl font-black text-slate-900 mt-4 tracking-tight leading-tight">
                            Pakistan's Dedicated Arena Booking & Competitive Match Network
                        </h2>
                    </div>

                    <p class="reveal-on-scroll delay-100 mt-6 text-slate-600 text-base leading-relaxed">
                        ArenaReserve bridges the gap between athletic venue operators and passionate sports enthusiasts. Whether you are organizing a weekend cricket tournament, scheduling regular weekly football sessions, or challenging rival teams to a 5-a-side match, ArenaReserve gives you full control.
                    </p>

                    <div class="reveal-on-scroll delay-200 mt-8 grid sm:grid-cols-2 gap-6">
                        <div class="flex items-start gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0 font-bold">
                                👥
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">For Players & Squads</h4>
                                <p class="text-xs text-slate-500 mt-1 leading-relaxed">Locate verified grounds near your radius, split fees with challenger teams, and lock your slot instantly.</p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100">
                            <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center flex-shrink-0 font-bold">
                                📈
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-900">For Ground Owners</h4>
                                <p class="text-xs text-slate-500 mt-1 leading-relaxed">Boost venue occupancy rates, manage calendar schedules, and withdraw revenue directly through automated payouts.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         OUR MISSION SECTION
    ============================================================ -->
    <section id="mission" class="py-24 bg-slate-900 text-white relative overflow-hidden">
        <!-- Ambient lighting -->
        <div class="absolute top-0 right-1/4 w-96 h-96 bg-emerald-600/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-0 left-1/4 w-96 h-96 bg-teal-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="text-center max-w-3xl mx-auto">
                <span class="reveal-on-scroll text-xs font-extrabold uppercase tracking-widest text-emerald-400 bg-emerald-950/80 border border-emerald-800/80 px-3.5 py-1.5 rounded-lg">Our Mission</span>
                <h2 class="reveal-on-scroll delay-100 text-3xl sm:text-5xl font-black mt-4 tracking-tight leading-tight">
                    Making Active Sports Accessible, Seamless & Competitive for Everyone
                </h2>
                <p class="reveal-on-scroll delay-200 mt-6 text-slate-400 text-base sm:text-lg leading-relaxed">
                    Our goal is to build the digital infrastructure that fuels grassroots sports culture — getting people away from screens and onto the pitch with zero friction.
                </p>
            </div>

            <!-- Mission Pillars -->
            <div class="mt-16 grid md:grid-cols-3 gap-8">
                <!-- Pillar 1 -->
                <div class="reveal-on-scroll delay-100 glass-dark-card rounded-3xl p-8 hover:border-emerald-500/50 transition-all duration-300 group">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        ⚡
                    </div>
                    <h3 class="text-xl font-bold text-white mb-3">Unrivaled Convenience</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Transform the complex process of venue discovery, price comparison, slot booking, and payment into a 30-second mobile experience.
                    </p>
                </div>

                <!-- Pillar 2 -->
                <div class="reveal-on-scroll delay-200 glass-dark-card rounded-3xl p-8 hover:border-emerald-500/50 transition-all duration-300 group">
                    <div class="w-14 h-14 rounded-2xl bg-teal-500/20 text-teal-400 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        🤝
                    </div>
                    <h3 class="text-xl font-bold text-white mb-3">Thriving Community</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Connect local teams with fair match opportunities, split billing, verified leaderboards, and competitive match tracking.
                    </p>
                </div>

                <!-- Pillar 3 -->
                <div class="reveal-on-scroll delay-300 glass-dark-card rounded-3xl p-8 hover:border-emerald-500/50 transition-all duration-300 group">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        🏆
                    </div>
                    <h3 class="text-xl font-bold text-white mb-3">Empowering Operators</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Equip arena owners with real-time financial tracking, slot management, automated notifications, and maximum court occupancy.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         FEATURES SECTION
    ============================================================ -->
    <section id="features" class="py-24 bg-white relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto">
                <span class="reveal-on-scroll text-xs font-extrabold uppercase tracking-widest text-emerald-600 bg-emerald-100/80 px-3.5 py-1.5 rounded-lg">Platform Features</span>
                <h2 class="reveal-on-scroll delay-100 text-3xl sm:text-4xl lg:text-5xl font-black text-slate-900 mt-4 tracking-tight">
                    Built for Players, Engineered for Venues
                </h2>
                <p class="reveal-on-scroll delay-200 mt-4 text-slate-600 text-sm sm:text-base leading-relaxed">
                    Everything you need to discover arenas, manage match bookings, challenge rivals, and grow sports facilities in one streamlined platform.
                </p>
            </div>

            <!-- 6 Grid Feature Cards -->
            <div class="mt-16 grid sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="reveal-on-scroll delay-100 interactive-card bg-slate-50 rounded-3xl p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        ⚡
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Live Slot Locking</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Never worry about duplicate bookings again. Time slots are locked in real-time with countdown holds while you confirm and checkout.
                    </p>
                </div>

                <!-- Feature 2 -->
                <div class="reveal-on-scroll delay-200 interactive-card bg-slate-50 rounded-3xl p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="w-14 h-14 rounded-2xl bg-violet-100 text-violet-700 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        ⚔️
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">50/50 Match Challenges</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Post an open challenge or challenge a specific team. Pay only 50% of the arena cost upfront — the challenger pays their half to confirm.
                    </p>
                </div>

                <!-- Feature 3 -->
                <div class="reveal-on-scroll delay-300 interactive-card bg-slate-50 rounded-3xl p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="w-14 h-14 rounded-2xl bg-teal-100 text-teal-700 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        💳
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">In-App Digital Wallet</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Quick, secure balance management with automated escrow holds. Instant refunds for cancelled matches without manual paperwork.
                    </p>
                </div>

                <!-- Feature 4 -->
                <div class="reveal-on-scroll delay-100 interactive-card bg-slate-50 rounded-3xl p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        📍
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">GPS Proximity Discovery</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Find top-rated sports venues near your exact coordinates. Filter by distance radius (5km, 10km, 25km), hourly price, and sport type.
                    </p>
                </div>

                <!-- Feature 5 -->
                <div class="reveal-on-scroll delay-200 interactive-card bg-slate-50 rounded-3xl p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="w-14 h-14 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        🏆
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Match History & Rankings</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Keep track of past games, recorded scores, match stats, and win ratios. Rise through the community leaderboard ranks.
                    </p>
                </div>

                <!-- Feature 6 -->
                <div class="reveal-on-scroll delay-300 interactive-card bg-slate-50 rounded-3xl p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                    <div class="w-14 h-14 rounded-2xl bg-blue-100 text-blue-700 flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform duration-300">
                        📊
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Owner Business Suite</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Complete venue dashboard for operators with customizable pricing, peak hour overrides, revenue analytics, and score submission.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         SUPPORTED SPORTS SECTION
    ============================================================ -->
    <section id="venues" class="py-24 bg-slate-50 relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto">
                <span class="reveal-on-scroll text-xs font-extrabold uppercase tracking-widest text-emerald-600 bg-emerald-100/80 px-3.5 py-1.5 rounded-lg">Available Categories</span>
                <h2 class="reveal-on-scroll delay-100 text-3xl sm:text-4xl font-black text-slate-900 mt-4 tracking-tight">
                    Every Sport, Every Format
                </h2>
                <p class="reveal-on-scroll delay-200 mt-4 text-slate-600 text-sm sm:text-base">
                    Find high-quality indoor and floodlit outdoor facilities optimized for your game.
                </p>
            </div>

            <div class="mt-14 grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Sport Card 1 -->
                <div class="reveal-on-scroll delay-100 interactive-card bg-white rounded-3xl p-6 border border-slate-200 shadow-sm text-center">
                    <div class="w-16 h-16 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-3xl mx-auto mb-5">
                        🏏
                    </div>
                    <h3 class="text-lg font-bold text-slate-900">Tape Ball & Hardball Cricket</h3>
                    <p class="text-xs text-slate-500 mt-2 leading-relaxed">Floodlit natural turf and cemented strip grounds equipped with safety nets and pavilion seating.</p>
                </div>

                <!-- Sport Card 2 -->
                <div class="reveal-on-scroll delay-200 interactive-card bg-white rounded-3xl p-6 border border-slate-200 shadow-sm text-center">
                    <div class="w-16 h-16 rounded-2xl bg-teal-100 text-teal-700 flex items-center justify-center text-3xl mx-auto mb-5">
                        ⚽
                    </div>
                    <h3 class="text-lg font-bold text-slate-900">5-a-side & 7-a-side Football</h3>
                    <p class="text-xs text-slate-500 mt-2 leading-relaxed">High-grade artificial AstroTurf pitches with rebound fencing and professional goal standards.</p>
                </div>

                <!-- Sport Card 3 -->
                <div class="reveal-on-scroll delay-300 interactive-card bg-white rounded-3xl p-6 border border-slate-200 shadow-sm text-center">
                    <div class="w-16 h-16 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center text-3xl mx-auto mb-5">
                        🏀
                    </div>
                    <h3 class="text-lg font-bold text-slate-900">Basketball Courts</h3>
                    <p class="text-xs text-slate-500 mt-2 leading-relaxed">Standard acrylic coated outdoor & indoor hardwood courts with spring hoops and lighting.</p>
                </div>

                <!-- Sport Card 4 -->
                <div class="reveal-on-scroll delay-400 interactive-card bg-white rounded-3xl p-6 border border-slate-200 shadow-sm text-center">
                    <div class="w-16 h-16 rounded-2xl bg-violet-100 text-violet-700 flex items-center justify-center text-3xl mx-auto mb-5">
                        🏸
                    </div>
                    <h3 class="text-lg font-bold text-slate-900">Futsal & Badminton</h3>
                    <p class="text-xs text-slate-500 mt-2 leading-relaxed">Air-cushioned synthetic mat courts for fast-paced badminton rallies and indoor futsal tournaments.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         HOW IT WORKS SECTION
    ============================================================ -->
    <section id="how-it-works" class="py-24 bg-white relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto">
                <span class="reveal-on-scroll text-xs font-extrabold uppercase tracking-widest text-emerald-600 bg-emerald-100/80 px-3.5 py-1.5 rounded-lg">Simple Workflow</span>
                <h2 class="reveal-on-scroll delay-100 text-3xl sm:text-4xl font-black text-slate-900 mt-4 tracking-tight">
                    How ArenaReserve Works in 3 Steps
                </h2>
            </div>

            <div class="mt-16 grid md:grid-cols-3 gap-8 relative">
                <!-- Step 1 -->
                <div class="reveal-on-scroll delay-100 relative p-8 rounded-3xl bg-slate-50 border border-slate-100 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-black mx-auto mb-6 shadow-lg shadow-emerald-600/30">
                        1
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Explore Nearby Arenas</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Filter by your preferred sport type, city, distance radius, and budget to view available grounds.
                    </p>
                </div>

                <!-- Step 2 -->
                <div class="reveal-on-scroll delay-200 relative p-8 rounded-3xl bg-slate-50 border border-slate-100 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-black mx-auto mb-6 shadow-lg shadow-emerald-600/30">
                        2
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Pick Slot or Open Challenge</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Select an open time slot for a private game, or create an open challenge and pay just 50% upfront.
                    </p>
                </div>

                <!-- Step 3 -->
                <div class="reveal-on-scroll delay-300 relative p-8 rounded-3xl bg-slate-50 border border-slate-100 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-black mx-auto mb-6 shadow-lg shadow-emerald-600/30">
                        3
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2">Show Up & Compete</h3>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        Arrive with your squad, play on pristine turf, record scores, and climb the platform leaderboards!
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         CONTACT DETAILS & INQUIRY SECTION
    ============================================================ -->
    <section id="contact" class="py-24 bg-white relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <span class="reveal-on-scroll text-xs font-extrabold uppercase tracking-widest text-emerald-600 bg-emerald-100/80 px-3.5 py-1.5 rounded-lg">Get in Touch</span>
                <h2 class="reveal-on-scroll delay-100 text-3xl sm:text-4xl lg:text-5xl font-black text-slate-900 mt-4 tracking-tight">
                    We're Here to Help You Play
                </h2>
                <p class="reveal-on-scroll delay-200 mt-4 text-slate-600 text-sm sm:text-base leading-relaxed">
                    Have questions about booking slots, match challenges, or listing your sports facility? Our dedicated team is available 7 days a week.
                </p>
            </div>

            <div class="grid lg:grid-cols-12 gap-10 lg:gap-12 items-start">
                <!-- Left: Contact Information Cards -->
                <div class="lg:col-span-5 space-y-6">
                    <!-- Card 1: Phone / WhatsApp -->
                    <div class="reveal-on-scroll delay-100 interactive-card bg-slate-50 rounded-3xl p-6 border border-slate-100 flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0 text-xl font-bold">
                            📞
                        </div>
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">Phone & WhatsApp Support</div>
                            <a href="tel:+923001234567" class="text-base font-extrabold text-slate-900 hover:text-emerald-600 transition-colors block mt-1">
                                +92 (300) 123-4567
                            </a>
                            <div class="text-xs text-slate-500 mt-0.5 font-medium">Mon – Sun: 9:00 AM – 11:00 PM PKT</div>
                        </div>
                    </div>

                    <!-- Card 2: Email -->
                    <div class="reveal-on-scroll delay-200 interactive-card bg-slate-50 rounded-3xl p-6 border border-slate-100 flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-teal-100 text-teal-600 flex items-center justify-center flex-shrink-0 text-xl font-bold">
                            ✉️
                        </div>
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">Email Inquiries</div>
                            <a href="mailto:support@arenareserve.pk" class="text-base font-extrabold text-slate-900 hover:text-emerald-600 transition-colors block mt-1">
                                support@arenareserve.pk
                            </a>
                            <div class="text-xs text-slate-500 mt-0.5 font-medium">For partner onboarding: <a href="mailto:partners@arenareserve.pk" class="text-emerald-600 hover:underline">partners@arenareserve.pk</a></div>
                        </div>
                    </div>

                    <!-- Card 3: Location -->
                    <div class="reveal-on-scroll delay-300 interactive-card bg-slate-50 rounded-3xl p-6 border border-slate-100 flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-violet-100 text-violet-600 flex items-center justify-center flex-shrink-0 text-xl font-bold">
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

                    <!-- Card 4: Quick Help Note -->
                    <div class="reveal-on-scroll delay-400 rounded-3xl bg-gradient-to-br from-emerald-600 to-teal-700 p-6 text-white shadow-lg">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">⚡</span>
                            <div>
                                <h4 class="text-sm font-extrabold">Instant Ground Onboarding</h4>
                                <p class="text-xs text-emerald-100 mt-0.5 leading-relaxed">
                                    Are you a ground owner? Register your venue in under 5 minutes to start receiving live bookings today.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Contact Message Form -->
                <div class="lg:col-span-7 reveal-on-scroll delay-200 glass-card rounded-3xl p-8 sm:p-10 shadow-xl border border-slate-200">
                    <h3 class="text-2xl font-black text-slate-900 tracking-tight mb-2">Send Us a Direct Message</h3>
                    <p class="text-xs sm:text-sm text-slate-500 mb-8 leading-relaxed">
                        Fill out the form below and our operations desk will reach out within 2 business hours.
                    </p>

                    <form onsubmit="handleContactSubmit(event)" class="space-y-5">
                        <div id="contactFeedback" class="hidden rounded-xl p-4 text-xs font-bold"></div>

                        <div class="grid sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Your Name</label>
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
                                <input type="tel" placeholder="0300 1234567" class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">I am interested in</label>
                                <select class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                                    <option value="player">Player / Team Booking Support</option>
                                    <option value="owner">Listing My Ground / Venue Owner</option>
                                    <option value="challenge">50/50 Match Challenge Inquiry</option>
                                    <option value="feedback">General Inquiries & Feedback</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Your Message</label>
                            <textarea rows="4" required placeholder="Let us know how we can assist you..." class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white"></textarea>
                        </div>

                        <button type="submit" class="btn-glow w-full py-4 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold rounded-2xl flex items-center justify-center gap-2">
                            <span>Send Message</span>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         CTA BANNER
    ============================================================ -->
    <section class="py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="reveal-on-scroll rounded-3xl bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-600 p-10 sm:p-16 text-center text-white relative overflow-hidden shadow-2xl">
                <!-- Background decorative elements -->
                <div class="absolute -top-16 -right-16 w-64 h-64 bg-white/10 rounded-full blur-2xl"></div>
                <div class="absolute -bottom-16 -left-16 w-64 h-64 bg-white/10 rounded-full blur-2xl"></div>

                <div class="relative z-10 max-w-3xl mx-auto">
                    <h2 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight">
                        Ready to Lock Your Next Match Slot?
                    </h2>
                    <p class="mt-5 text-emerald-100 text-base sm:text-lg leading-relaxed">
                        Join hundreds of sports clubs and thousands of players on ArenaReserve today. It only takes a minute to get set up.
                    </p>
                    <div class="mt-8 sm:mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                        <a href="signup.php" class="w-full sm:w-auto px-8 py-4 bg-white hover:bg-slate-100 text-emerald-800 text-base font-bold rounded-2xl shadow-lg transition-all hover:scale-105">
                            Create Free Account
                        </a>
                        <a href="login.php" class="w-full sm:w-auto px-8 py-4 bg-emerald-800/60 hover:bg-emerald-800 text-white text-base font-bold rounded-2xl border border-white/20 transition-all">
                            Sign In to Arena
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         FOOTER
    ============================================================ -->
    <footer class="bg-slate-900 text-slate-400 py-16 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-10">
                <!-- Col 1: Brand & Bio -->
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
                        Pakistan's modern arena management & ground reservation network. Empowering athletes and arena owners through instant digital bookings.
                    </p>
                    <div class="mt-6 text-xs text-slate-500">
                        &copy; 2026 ArenaReserve. All rights reserved.
                    </div>
                </div>

                <!-- Col 2: Navigation Links -->
                <div class="md:col-span-3">
                    <h4 class="text-xs font-bold text-slate-200 uppercase tracking-wider mb-4">Quick Links</h4>
                    <ul class="space-y-2.5 text-sm">
                        <li><a href="#about" class="hover:text-emerald-400 transition-colors">About Us</a></li>
                        <li><a href="#mission" class="hover:text-emerald-400 transition-colors">Our Mission</a></li>
                        <li><a href="#features" class="hover:text-emerald-400 transition-colors">Features</a></li>
                        <li><a href="#how-it-works" class="hover:text-emerald-400 transition-colors">How It Works</a></li>
                        <li><a href="#contact" class="hover:text-emerald-400 transition-colors">Contact Details</a></li>
                    </ul>
                </div>

                <!-- Col 3: Authentication -->
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

    <!-- ============================================================
         INTERACTIVE SCRIPTS & SCROLL REVEAL
    ============================================================ -->
    <script>
        // ── Scroll-triggered animations using IntersectionObserver ──
        const observerOptions = {
            threshold: 0.15,
            rootMargin: '0px 0px -40px 0px'
        };

        const scrollObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.reveal-on-scroll').forEach((el) => {
            scrollObserver.observe(el);
        });

        // ── Mobile Menu Toggle ──
        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            menu.classList.toggle('open');
            menu.classList.toggle('closed');
        }

        function closeMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            menu.classList.remove('open');
            menu.classList.add('closed');
        }

        // ── Contact Form Submission Feedback ──
        function handleContactSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const feedback = document.getElementById('contactFeedback');
            
            feedback.className = 'rounded-xl p-4 text-xs font-bold bg-emerald-100 border border-emerald-300 text-emerald-800 flex items-center gap-2';
            feedback.innerHTML = '<span class="text-base">✓</span> Thank you! Your message has been received. Our team will get back to you shortly.';
            feedback.classList.remove('hidden');

            form.reset();
        }
    </script>
</body>
</html>
