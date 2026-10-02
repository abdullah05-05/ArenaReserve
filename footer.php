<?php
/**
 * footer.php — Universal shared footer for ArenaReserve
 * Included across all public, player, and owner pages.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/logo_helper.php';

$is_logged_in = isset($_SESSION['user_id']);
$user_role    = $_SESSION['current_active_mode'] ?? ($_SESSION['current_role'] ?? 'Player');
$user_name    = $_SESSION['name'] ?? '';
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!-- ============================================================
     ARENARESERVE UNIVERSAL FOOTER
============================================================ -->
<footer class="bg-slate-900 text-slate-400 py-14 border-t border-slate-800 mt-auto relative z-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-10">
            <!-- Col 1: Brand & Contact Info -->
            <div class="md:col-span-4">
                <a href="<?php echo $is_logged_in ? ($user_role === 'Owner' ? 'owner_dashboard.php' : 'explore.php') : 'landing.php'; ?>" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-xl bg-emerald-600 flex items-center justify-center text-white shadow-md shadow-emerald-600/30 group-hover:scale-105 transition-transform flex-shrink-0">
                        <?php echo get_logo_markup('h-5 w-5'); ?>
                    </div>
                    <span class="text-xl font-black text-white tracking-tight">Arena<span class="text-emerald-500">Reserve</span></span>
                </a>
                <p class="mt-3.5 text-xs text-slate-400 leading-relaxed max-w-sm">
                    Pakistan's premier sports ground booking network. Empowering players, squads, and venue operators with verified real-time reservations.
                </p>
                <div class="mt-4 text-xs text-slate-400 space-y-1.5 font-medium">
                    <div class="flex items-start gap-1.5">
                        <span class="text-emerald-400">📍</span>
                        <span>17-km Sheikhupura Road, Shah Zaman Park near Mughal Steel, Lahore</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-emerald-400">📞</span>
                        <a href="tel:03137970801" class="text-slate-300 hover:text-emerald-400 transition-colors font-bold">03137970801</a>
                        <span class="text-[10px] text-slate-500">(Mon–Sun 9 AM–11 PM)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-emerald-400">✉️</span>
                        <a href="mailto:abdullahtariq0505@gmail.com" class="text-slate-300 hover:text-emerald-400 transition-colors">abdullahtariq0505@gmail.com</a>
                    </div>
                </div>
            </div>

            <!-- Col 2: Navigation Links -->
            <div class="md:col-span-2">
                <h4 class="text-xs font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    Platform
                </h4>
                <ul class="space-y-2.5 text-xs">
                    <li><a href="explore.php" class="<?php echo $current_page==='explore.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Explore Arenas</a></li>
                    <li><a href="leaderboard.php" class="<?php echo $current_page==='leaderboard.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Community Leaderboard</a></li>
                    <li><a href="landing.php#featured-grounds" class="hover:text-emerald-400 text-slate-400 transition-colors">Featured Grounds</a></li>
                    <li><a href="landing.php#about" class="hover:text-emerald-400 text-slate-400 transition-colors">About Us</a></li>
                    <li><a href="landing.php#how-it-works" class="hover:text-emerald-400 text-slate-400 transition-colors">How It Works</a></li>
                    <li><a href="contact.php" class="<?php echo $current_page==='contact.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Contact Support</a></li>
                </ul>
            </div>

            <!-- Col 3: Compliance & Legal Policies -->
            <div class="md:col-span-3">
                <h4 class="text-xs font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    Policies
                </h4>
                <ul class="space-y-2.5 text-xs">
                    <li><a href="terms.php" class="<?php echo $current_page==='terms.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Terms &amp; Conditions</a></li>
                    <li><a href="privacy.php" class="<?php echo $current_page==='privacy.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Privacy Policy</a></li>
                    <li><a href="refund-policy.php" class="<?php echo $current_page==='refund-policy.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Refund Policy</a></li>
                    <li><a href="cancellation-policy.php" class="<?php echo $current_page==='cancellation-policy.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Cancellation Policy</a></li>
                    <li><a href="sitemap.php" class="<?php echo $current_page==='sitemap.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Platform Sitemap</a></li>
                </ul>
            </div>

            <!-- Col 4: Account & Portal Access -->
            <div class="md:col-span-3">
                <h4 class="text-xs font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <?php echo $is_logged_in ? 'Your Account' : 'Get Started'; ?>
                </h4>

                <?php if ($is_logged_in): ?>
                    <!-- Logged In User Snapshot -->
                    <div class="bg-slate-800/80 rounded-xl p-3.5 border border-slate-700/60 mb-3 text-xs">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="font-bold text-slate-200 truncate"><?php echo htmlspecialchars($user_name); ?></span>
                            <span class="text-[10px] bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 px-2 py-0.2 rounded font-extrabold uppercase">
                                <?php echo htmlspecialchars($user_role); ?>
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-400">
                            <?php if ($user_role === 'Owner'): ?>
                                <a href="owner_dashboard.php" class="text-emerald-400 hover:underline">Owner Dashboard</a> • 
                                <a href="owner_analytics.php" class="text-slate-300 hover:underline">Analytics</a>
                            <?php else: ?>
                                <a href="wallet.php" class="text-emerald-400 hover:underline">My Wallet</a> • 
                                <a href="match_history.php" class="text-slate-300 hover:underline">Matches</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="switch_role.php" class="flex-1 py-2 px-3 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold text-center transition-colors border border-slate-700">
                            Switch Role
                        </a>
                        <a href="logout.php" class="py-2 px-3 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-300 text-xs font-semibold text-center transition-colors border border-red-800/40">
                            Logout
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Logged Out Portal Links -->
                    <div class="flex flex-col gap-2.5">
                        <a href="login.php" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold text-center transition-colors border border-slate-700">
                            Player &amp; Owner Login
                        </a>
                        <a href="signup.php" class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold text-center transition-colors shadow-md shadow-emerald-900/40">
                            Create Free Account
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Security & Trust Badges -->
                <div class="mt-4 pt-3.5 border-t border-slate-800/80 flex items-center justify-between text-[11px] text-slate-500">
                    <span class="flex items-center gap-1">🔒 256-bit SSL</span>
                    <span class="flex items-center gap-1">⚡ Instant Confirmation</span>
                </div>
            </div>
        </div>

        <!-- ── Sub-Footer Bar ── -->
        <div class="mt-12 pt-6 border-t border-slate-800/80 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
            <div>
                &copy; <?php echo date('Y'); ?> <strong class="text-slate-400">ArenaReserve</strong>. All rights reserved. Built with passion for sports in Pakistan 🇵🇰
            </div>
            <div class="flex items-center gap-3 text-[11px]">
                <span class="text-slate-400 font-semibold">Payment Partners:</span>
                <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">JazzCash</span>
                <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">Swich Pay</span>
                <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">Visa / Mastercard</span>
            </div>
        </div>
    </div>
</footer>
