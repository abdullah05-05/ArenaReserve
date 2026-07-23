<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $available_balance = floatval($wallet['available_balance'] ?? 0);
} catch (Exception $e) { $available_balance = 0.00; }

// Slot context passed from book_slot.php
$ctx_ground_id = intval($_GET['ground_id'] ?? 0);
$ctx_date      = trim($_GET['date'] ?? '');
$ctx_hour      = intval($_GET['hour']  ?? -1);
$ctx_price     = floatval($_GET['price'] ?? 0);
$ctx_half      = floatval($_GET['half']  ?? round($ctx_price * 0.5, 2));
$has_context   = ($ctx_ground_id > 0 && $ctx_date !== '' && $ctx_hour >= 0 && $ctx_price > 0);

// Fetch ground info if context provided
$ctx_ground = null;
if ($has_context) {
    try {
        $stmt = $pdo->prepare("SELECT title, sport_type FROM grounds WHERE id = ?");
        $stmt->execute([$ctx_ground_id]);
        $ctx_ground = $stmt->fetch();
    } catch (Exception $e) {}

    // Format time label
    $h = $ctx_hour;
    $suffix   = $h < 12 ? 'AM' : 'PM';
    $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
    $nextH    = $h + 1;
    $nextDisp = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
    $nextSuf  = $nextH < 12 ? 'AM' : 'PM';
    $ctx_time_label = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuf);
}

// Fetch real teams from users (players who have teams)
// Fallback to mock if table doesn't exist yet
$teams = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, city FROM users
        WHERE current_role IN ('Player','Owner') AND status = 'Active' AND id != ?
        ORDER BY name ASC LIMIT 50
    ");
    $stmt->execute([$user_id]);
    $raw_users = $stmt->fetchAll();
    foreach ($raw_users as $u) {
        $teams[] = [
            'id'     => $u['id'],
            'name'   => $u['name'],
            'city'   => $u['city'],
            'sport'  => 'Football',   // default
            'avatar' => strtoupper(substr($u['name'], 0, 2)),
            'wins'   => rand(2,15),
            'losses' => rand(1,8),
            'rating' => number_format(3.5 + (rand(0,15)/10), 1),
        ];
    }
} catch (Exception $e) { $teams = []; }

// If no real users, use mock teams
if (empty($teams)) {
    $teams = [
        ['id'=>101,'name'=>'City Ballers','sport'=>'Basketball','wins'=>8,'losses'=>3,'rating'=>'4.6','city'=>'Karachi','avatar'=>'CB'],
        ['id'=>102,'name'=>'Street Kings','sport'=>'Football','wins'=>12,'losses'=>4,'rating'=>'4.8','city'=>'Lahore','avatar'=>'SK'],
        ['id'=>103,'name'=>'Thunder XI','sport'=>'Cricket','wins'=>6,'losses'=>7,'rating'=>'4.2','city'=>'Karachi','avatar'=>'TX'],
        ['id'=>104,'name'=>'Smash Bros','sport'=>'Badminton','wins'=>15,'losses'=>2,'rating'=>'4.9','city'=>'Islamabad','avatar'=>'SB'],
        ['id'=>105,'name'=>'Kick Force','sport'=>'Football','wins'=>9,'losses'=>5,'rating'=>'4.4','city'=>'Karachi','avatar'=>'KF'],
        ['id'=>106,'name'=>'Hoop Dreams','sport'=>'Basketball','wins'=>4,'losses'=>9,'rating'=>'3.8','city'=>'Lahore','avatar'=>'HD'],
        ['id'=>107,'name'=>'Falcon FC','sport'=>'Football','wins'=>11,'losses'=>3,'rating'=>'4.7','city'=>'Karachi','avatar'=>'FF'],
        ['id'=>108,'name'=>'Net Ninjas','sport'=>'Badminton','wins'=>7,'losses'=>6,'rating'=>'4.1','city'=>'Islamabad','avatar'=>'NN'],
    ];
}

$sport_colors = [
    'Basketball'=>'bg-orange-100 text-orange-700',
    'Football'  =>'bg-green-100 text-green-700',
    'Cricket'   =>'bg-sky-100 text-sky-700',
    'Badminton' =>'bg-violet-100 text-violet-700',
    'Futsal'    =>'bg-rose-100 text-rose-700',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Challenge a Team – ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body { font-family:'Inter',sans-serif; background:#f8fafc; }

.team-card { border:1.5px solid #e2e8f0; border-radius:14px; background:white; padding:20px; transition:all 0.18s ease; cursor:pointer; }
.team-card:hover { border-color:#059669; box-shadow:0 6px 24px rgba(5,150,105,0.12); transform:translateY(-2px); }

/* Modal */
#challenge-overlay {
    position:fixed;inset:0;background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);
    z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;
    opacity:0;pointer-events:none;transition:opacity 0.2s;
}
#challenge-overlay.open { opacity:1;pointer-events:all; }
#challenge-modal {
    background:white;border-radius:20px;box-shadow:0 25px 80px rgba(0,0,0,0.25);
    max-width:480px;width:100%;transform:scale(0.92) translateY(20px);
    transition:transform 0.25s cubic-bezier(.34,1.56,.64,1),opacity 0.2s;opacity:0;overflow:hidden;
}
#challenge-overlay.open #challenge-modal { transform:scale(1) translateY(0);opacity:1; }

/* Toast */
#ct-toast {
    position:fixed;top:20px;right:20px;z-index:9999;
    padding:12px 20px;border-radius:12px;font-size:14px;font-weight:500;
    box-shadow:0 8px 30px rgba(0,0,0,0.3);transform:translateX(120%);
    transition:transform 0.3s cubic-bezier(.34,1.56,.64,1);
    display:flex;align-items:center;gap:8px;max-width:360px;color:white;
}
#ct-toast.show { transform:translateX(0); }
#ct-toast.success { background:linear-gradient(135deg,#059669,#047857); }
#ct-toast.error   { background:linear-gradient(135deg,#dc2626,#b91c1c); }

.context-banner {
    background:linear-gradient(135deg,#f97316,#ea580c);
    border-radius:14px;padding:16px;color:white;margin-bottom:20px;
}

.search-highlight { background:#fef08a; border-radius:3px; }
</style>
</head>
<body>
<!-- Toast -->
<div id="ct-toast"></div>

<!-- Header -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between h-16 items-center">
    <a href="explore.php" class="flex items-center gap-2 text-emerald-600 text-xl font-bold">
      <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0112 20.055a11.952 11.952 0 01-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
      ArenaReserve
    </a>
    <div class="flex items-center gap-3">
      <a href="wallet.php" class="flex items-center bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full text-xs font-semibold border border-emerald-200">
        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span><?php echo number_format($available_balance, 0); ?> PKR
      </a>
      <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg border border-slate-200 text-xs font-semibold">
        <span class="px-2 py-1 bg-white rounded shadow-sm text-emerald-600">Player</span>
        <a href="switch_role.php" class="px-2 py-1 text-slate-500 hover:text-slate-700">Owner</a>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
        <a href="logout.php" class="text-xs text-red-500 font-medium">Logout</a>
      </div>
    </div>
  </div>
</header>

<div class="flex max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">
  <!-- Sidebar -->
  <aside class="hidden lg:block w-64 flex-shrink-0">
    <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
      <a href="explore.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>Explore Grounds</a>
      <a href="book_slot.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Book Slot</a>
      <a href="match_history.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Match History</a>
      <a href="challenge_team.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg"><svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Challenge Team</a>
      <a href="leaderboard.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Leaderboard</a>
      <div class="border-t border-slate-100 mt-2 pt-2">
        <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>My Wallet</a>
      </div>
    </nav>
  </aside>

  <!-- Main -->
  <main class="flex-1 min-w-0">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Challenge a Team</h1>
        <p class="text-sm text-slate-500 mt-1">Find and invite a specific team to your slot</p>
      </div>
      <?php if ($has_context): ?>
      <a href="book_slot.php?ground=<?php echo $ctx_ground_id; ?>&date=<?php echo $ctx_date; ?>" class="text-xs text-slate-500 hover:text-slate-700 flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back to slots
      </a>
      <?php endif; ?>
    </div>

    <!-- Slot Context Banner (shown when coming from book_slot) -->
    <?php if ($has_context && $ctx_ground): ?>
    <div class="context-banner mb-6">
      <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
          <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <div class="flex-1">
          <div class="text-xs font-semibold opacity-80 mb-0.5">Booking for this slot</div>
          <div class="font-extrabold text-lg"><?php echo htmlspecialchars($ctx_ground['title']); ?></div>
          <div class="flex flex-wrap gap-3 mt-1 text-sm opacity-90">
            <span>📅 <?php echo date('D, d M Y', strtotime($ctx_date)); ?></span>
            <span>⏰ <?php echo $ctx_time_label; ?></span>
            <span>💰 Your share: <strong><?php echo number_format($ctx_half, 0); ?> PKR</strong> (50%)</span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
      <div class="relative">
        <input type="text" id="teamSearch" placeholder="Search teams by name or city…"
               class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
               oninput="filterTeams(this.value)">
        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
      </div>
    </div>

    <!-- Teams Grid -->
    <?php if (empty($teams)): ?>
    <div class="text-center py-16 text-slate-400">
      <svg class="h-12 w-12 mx-auto mb-3 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <p class="font-semibold">No teams found</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" id="teams-grid">
      <?php foreach ($teams as $team):
        $sc = $sport_colors[$team['sport']] ?? 'bg-slate-100 text-slate-600';
      ?>
      <div class="team-card"
           data-name="<?php echo strtolower($team['name']); ?>"
           data-city="<?php echo strtolower($team['city']); ?>"
           data-sport="<?php echo $team['sport']; ?>"
           onclick="openChallengeModal(<?php echo $team['id']; ?>, '<?php echo addslashes($team['name']); ?>', '<?php echo addslashes($team['city']); ?>')">

        <div class="flex items-center gap-3 mb-4">
          <div class="w-12 h-12 rounded-xl <?php echo $sc; ?> flex items-center justify-center font-extrabold text-sm flex-shrink-0">
            <?php echo $team['avatar']; ?>
          </div>
          <div class="flex-1 min-w-0">
            <h3 class="font-bold text-slate-900 truncate team-name"><?php echo htmlspecialchars($team['name']); ?></h3>
            <p class="text-xs text-slate-500"><?php echo $team['city']; ?></p>
          </div>
          <div class="text-xs bg-amber-50 text-amber-600 font-bold px-2 py-1 rounded-full flex-shrink-0">★ <?php echo $team['rating']; ?></div>
        </div>

        <div class="grid grid-cols-3 gap-2 text-center bg-slate-50 rounded-xl p-3 mb-4">
          <div><div class="text-lg font-extrabold text-slate-800"><?php echo $team['wins'] + $team['losses']; ?></div><div class="text-[10px] text-slate-400 uppercase font-semibold">Played</div></div>
          <div><div class="text-lg font-extrabold text-green-600"><?php echo $team['wins']; ?></div><div class="text-[10px] text-slate-400 uppercase font-semibold">Won</div></div>
          <div><div class="text-lg font-extrabold text-red-500"><?php echo $team['losses']; ?></div><div class="text-[10px] text-slate-400 uppercase font-semibold">Lost</div></div>
        </div>

        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full <?php echo $sc; ?>"><?php echo $team['sport']; ?></span>
          <button class="bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold px-4 py-2 rounded-lg transition-colors shadow-sm"
                  onclick="event.stopPropagation();openChallengeModal(<?php echo $team['id']; ?>,'<?php echo addslashes($team['name']); ?>','<?php echo addslashes($team['city']); ?>')">
            ⚡ Challenge
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </main>
</div>

<!-- ============================================================
     CHALLENGE PAYMENT MODAL
============================================================ -->
<div id="challenge-overlay">
  <div id="challenge-modal">
    <!-- Header -->
    <div class="bg-gradient-to-r from-orange-500 to-red-500 px-6 pt-6 pb-5 text-white">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-xs font-semibold opacity-80 mb-0.5">Challenging</div>
          <h2 class="text-xl font-extrabold" id="m-team-name">Team Name</h2>
          <p class="text-xs opacity-80 mt-0.5" id="m-team-city">City</p>
        </div>
        <button onclick="closeChModal()" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-colors">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>

    <div class="p-6">
      <!-- No slot context: show slot picker -->
      <?php if (!$has_context): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-4 text-xs text-amber-700">
        ⚠️ No slot selected. Please <a href="book_slot.php" class="font-bold underline">go to Book Slot</a> first and choose "Challenge a Team" from there to pre-fill slot details.
      </div>
      <?php endif; ?>

      <?php if ($has_context && $ctx_ground): ?>
      <!-- Slot Summary -->
      <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 mb-4 space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800"><?php echo htmlspecialchars($ctx_ground['title']); ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800"><?php echo date('D, d M Y', strtotime($ctx_date)); ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold text-slate-800"><?php echo $ctx_time_label; ?></span></div>
        <div class="border-t border-orange-200 pt-2 flex justify-between">
          <span class="font-bold text-slate-700">Your 50% Share</span>
          <span class="font-extrabold text-orange-600 text-base"><?php echo number_format($ctx_half, 0); ?> PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400">Wallet Balance</span>
          <span class="font-semibold text-slate-600"><?php echo number_format($available_balance, 0); ?> PKR</span>
        </div>
      </div>
      <?php else: ?>
      <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-4 text-sm">
        <div class="text-slate-500 mb-2 text-xs font-semibold uppercase">Enter Slot Details</div>
        <input type="date" id="ch-date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-2 focus:outline-none focus:ring-2 focus:ring-orange-400">
        <input type="number" id="ch-price" placeholder="Slot price (PKR)" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
      </div>
      <?php endif; ?>

      <!-- Optional message -->
      <div class="mb-4">
        <label class="block text-xs font-semibold text-slate-700 mb-1">Challenge Message (optional)</label>
        <textarea id="ch-message" rows="2" placeholder="Game on! See you on the field 🏆" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 resize-none"></textarea>
      </div>

      <div class="flex gap-3">
        <button onclick="closeChModal()" class="flex-1 py-2.5 border border-slate-300 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50 transition-colors">Cancel</button>
        <button onclick="submitChallenge()" id="ch-submit-btn"
                class="flex-1 py-2.5 bg-orange-500 hover:bg-orange-600 text-white text-sm font-bold rounded-xl shadow transition-all <?php echo (!$has_context) ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                <?php echo (!$has_context) ? 'disabled' : ''; ?>>
          ⚡ Pay & Send Challenge
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const HAS_CONTEXT = <?php echo $has_context ? 'true' : 'false'; ?>;
const CTX = {
  ground_id: <?php echo $ctx_ground_id; ?>,
  date:      '<?php echo $ctx_date; ?>',
  hour:      <?php echo $ctx_hour >= 0 ? $ctx_hour : 0; ?>,
  price:     <?php echo $ctx_price; ?>,
  half:      <?php echo $ctx_half; ?>
};

let selectedTeamId   = null;
let selectedTeamName = '';

function openChallengeModal(id, name, city) {
  selectedTeamId   = id;
  selectedTeamName = name;
  document.getElementById('m-team-name').textContent = name;
  document.getElementById('m-team-city').textContent  = city;
  document.getElementById('challenge-overlay').classList.add('open');
}
function closeChModal() {
  document.getElementById('challenge-overlay').classList.remove('open');
}

function submitChallenge() {
  if (!selectedTeamId || !HAS_CONTEXT) return;

  const btn = document.getElementById('ch-submit-btn');
  btn.disabled    = true;
  btn.textContent = 'Processing…';

  const formData = new URLSearchParams({
    ground_id:            CTX.ground_id,
    slot_date:            CTX.date,
    slot_hour:            CTX.hour,
    booking_type:         'team_challenge',
    challenger_team_name: selectedTeamName
  });

  fetch('process_booking.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    closeChModal();
    if (res.success) {
      showCTToast('🎉 Challenge sent to ' + selectedTeamName + '! Payment held.', 'success');
      setTimeout(() => { window.location.href = 'match_history.php'; }, 2500);
    } else {
      showCTToast('❌ ' + res.message, 'error');
      btn.disabled    = false;
      btn.textContent = '⚡ Pay & Send Challenge';
    }
  })
  .catch(() => {
    showCTToast('❌ Network error.', 'error');
    btn.disabled    = false;
    btn.textContent = '⚡ Pay & Send Challenge';
  });
}

function filterTeams(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.team-card').forEach(c => {
    const combined = c.dataset.name + ' ' + c.dataset.city;
    c.style.display = combined.includes(q) ? '' : 'none';
  });
}

function showCTToast(msg, type) {
  const t = document.getElementById('ct-toast');
  t.textContent = msg;
  t.className   = 'show ' + type;
  setTimeout(() => { t.className = t.className.replace('show','').trim(); }, 4000);
}

// Close on overlay click / Escape
document.getElementById('challenge-overlay').addEventListener('click', function(e){ if(e.target===this) closeChModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape') closeChModal(); });
</script>
</body>
</html>
