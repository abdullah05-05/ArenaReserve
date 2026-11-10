<?php
/**
 * footer.php — Universal shared footer for ArenaReserve
 * Included across all public, player, and owner pages.
 */
require_once __DIR__ . '/logo_helper.php';

$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!-- ============================================================
     ARENARESERVE UNIVERSAL FOOTER
============================================================ -->
<footer class="bg-slate-900 text-slate-400 py-12 sm:py-14 border-t border-slate-800 mt-auto relative z-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-8 lg:gap-12">
            <!-- Col 1: Brand & Contact Info -->
            <div class="md:col-span-6 lg:col-span-5">
                <a href="explore.php" class="flex items-center gap-2.5 group">
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

                <!-- Trust Indicators -->
                <div class="mt-5 pt-4 border-t border-slate-800/80 flex items-center gap-4 text-[11px] text-slate-500">
                    <span class="flex items-center gap-1.5"><span class="text-emerald-400">🔒</span> 256-bit SSL Secure</span>
                    <span class="text-slate-700">•</span>
                    <span class="flex items-center gap-1.5"><span class="text-amber-400">⚡</span> Instant Confirmation</span>
                </div>
            </div>

            <!-- Col 2: Navigation Links -->
            <div class="md:col-span-3 lg:col-span-3 lg:col-start-7">
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
            <div class="md:col-span-3 lg:col-span-3">
                <h4 class="text-xs font-bold text-slate-200 uppercase tracking-wider mb-4 flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    Policies &amp; Legal
                </h4>
                <ul class="space-y-2.5 text-xs">
                    <li><a href="terms.php" class="<?php echo $current_page==='terms.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Terms &amp; Conditions</a></li>
                    <li><a href="privacy.php" class="<?php echo $current_page==='privacy.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Privacy Policy</a></li>
                    <li><a href="refund-policy.php" class="<?php echo $current_page==='refund-policy.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Refund Policy</a></li>
                    <li><a href="cancellation-policy.php" class="<?php echo $current_page==='cancellation-policy.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Cancellation Policy</a></li>
                    <li><a href="sitemap.php" class="<?php echo $current_page==='sitemap.php'?'text-emerald-400 font-bold':'hover:text-emerald-400 text-slate-400'; ?> transition-colors">Platform Sitemap</a></li>
                </ul>
            </div>
        </div>

        <!-- ── Sub-Footer Bar ── -->
        <div class="mt-12 pt-6 border-t border-slate-800/80 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
            <div>
                &copy; <?php echo date('Y'); ?> <strong class="text-slate-400">ArenaReserve</strong>. All rights reserved. Built with passion for sports in Pakistan 🇵🇰
            </div>
            <div class="flex items-center gap-2.5 text-[11px] flex-wrap">
                <span class="text-slate-400 font-semibold">Payment Partners:</span>
                <span class="px-2.5 py-1 rounded bg-slate-800 text-slate-300 border border-slate-700">JazzCash</span>
                <span class="px-2.5 py-1 rounded bg-slate-800 text-slate-300 border border-slate-700">Visa / Mastercard</span>
                <span class="px-2.5 py-1 rounded bg-slate-800 text-slate-300 border border-slate-700">Debit / Credit Cards</span>
            </div>
        </div>
    </div>
</footer>
