<?php
/**
 * footer.php — Universal shared footer for ArenaReserve
 * Included across all public, player, and owner pages.
 */
require_once __DIR__ . '/logo_helper.php';
$config = require __DIR__ . '/config_constants.php';
$support = $config['support'];
$platform = $config['platform'];

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
                    <span class="text-xl font-black text-white tracking-tight"><?php echo $platform['name']; ?></span>
                </a>
                <p class="mt-3.5 text-xs text-slate-400 leading-relaxed max-w-sm">
                    Pakistan's premier sports ground booking network. Empowering players, squads, and venue operators with verified real-time reservations.
                </p>
                <div class="mt-4 text-xs text-slate-400 space-y-1.5 font-medium">
                    <div class="flex items-start gap-2">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span><?php echo htmlspecialchars($support['address']); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <a href="tel:<?php echo htmlspecialchars($support['phone']); ?>" class="text-slate-300 hover:text-emerald-400 transition-colors font-bold"><?php echo htmlspecialchars($support['phone']); ?></a>
                        <span class="text-[10px] text-slate-500">(<?php echo htmlspecialchars($support['hours']); ?>)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <a href="mailto:<?php echo htmlspecialchars($support['email']); ?>" class="text-slate-300 hover:text-emerald-400 transition-colors"><?php echo htmlspecialchars($support['email']); ?></a>
                    </div>
                </div>

                <!-- Trust Indicators -->
                <div class="mt-5 pt-4 border-t border-slate-800/80 flex items-center gap-4 text-[11px] text-slate-500">
                    <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> 256-bit SSL Secure</span>
                    <span class="text-slate-700">•</span>
                    <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Instant Confirmation</span>
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
                &copy; <?php echo date('Y'); ?> <strong class="text-slate-400">ArenaReserve</strong>. All rights reserved. Built with passion for sports in Pakistan.
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
