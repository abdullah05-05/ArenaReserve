<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Wallet balance
try {
    $stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $available_balance = floatval($wallet['available_balance'] ?? 0);
} catch (Exception $e) { $available_balance = 0.00; }

// Fetch real bookings from DB
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.slot_date, b.slot_hour, b.price, b.amount_paid,
               b.booking_type, b.status, b.challenger_team_name, b.opponent_id,
               b.created_at,
               g.title AS ground_title, g.sport_type, g.address
        FROM bookings b
        JOIN grounds g ON g.id = b.ground_id
        WHERE b.booked_by = ?
        ORDER BY b.slot_date DESC, b.slot_hour DESC
    ");
    $stmt->execute([$user_id]);
    $my_bookings = $stmt->fetchAll();
} catch (Exception $e) { $my_bookings = []; }

// Fetch challenges where this user is the OPPONENT (accepted challenges)
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.slot_date, b.slot_hour, b.price, b.amount_paid,
               b.booking_type, b.status, b.challenger_team_name,
               b.created_at,
               g.title AS ground_title, g.sport_type, g.address,
               u.name AS challenger_name
        FROM bookings b
        JOIN grounds g ON g.id = b.ground_id
        JOIN users u ON u.id = b.booked_by
        WHERE b.opponent_id = ?
        ORDER BY b.slot_date DESC, b.slot_hour DESC
    ");
    $stmt->execute([$user_id]);
    $accepted_challenges = $stmt->fetchAll();
} catch (Exception $e) { $accepted_challenges = []; }

// Merge both lists for display
$all_bookings = [];
foreach ($my_bookings as $b) {
    $b['role'] = 'challenger';
    $all_bookings[] = $b;
}
foreach ($accepted_challenges as $b) {
    $b['role'] = 'opponent';
    $all_bookings[] = $b;
}

// Sort by date desc
usort($all_bookings, fn($a,$b) => strcmp($b['slot_date'].$b['slot_hour'], $a['slot_date'].$a['slot_hour']));

// Stats
$total      = count($all_bookings);
$upcoming   = count(array_filter($all_bookings, fn($b) => $b['slot_date'] >= date('Y-m-d') && in_array($b['status'], ['confirmed','challenge_open','challenge_pending','challenge_accepted'])));
$confirmed  = count(array_filter($all_bookings, fn($b) => $b['status'] === 'confirmed'));
$challenges = count(array_filter($all_bookings, fn($b) => in_array($b['status'], ['challenge_open','challenge_pending','challenge_accepted'])));
$total_spent = array_sum(array_column($all_bookings, 'amount_paid'));

// Format hour → time label
function formatHourLabel(int $h): string {
    $suffix   = $h < 12 ? 'AM' : 'PM';
    $displayH = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
    $nextH    = $h + 1;
    $nextDisp = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
    $nextSuf  = $nextH < 12 ? 'AM' : 'PM';
    return sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuf);
}

$statusConfig = [
    'confirmed'          => ['badge' => 'bg-emerald-100 text-emerald-700', 'label' => 'Confirmed',         'icon' => '✅'],
    'challenge_open'     => ['badge' => 'bg-violet-100 text-violet-700',   'label' => 'Open Challenge',    'icon' => '⚡'],
    'challenge_pending'  => ['badge' => 'bg-orange-100 text-orange-700',   'label' => 'Pending Accept',    'icon' => '🤝'],
    'challenge_accepted' => ['badge' => 'bg-blue-100 text-blue-700',       'label' => 'Match Set ✓',      'icon' => '🏆'],
    'cancelled'          => ['badge' => 'bg-red-100 text-red-700',         'label' => 'Cancelled',         'icon' => '❌'],
];

$typeConfig = [
    'direct'          => ['badge' => 'bg-slate-100 text-slate-600',   'label' => 'Direct'],
    'open_challenge'  => ['badge' => 'bg-violet-100 text-violet-600', 'label' => 'Open Challenge'],
    'team_challenge'  => ['badge' => 'bg-orange-100 text-orange-600', 'label' => 'Team Challenge'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Match History – ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body { font-family: 'Inter', sans-serif; background: #f8fafc; }
.booking-row { transition: background 0.15s; }
.booking-row:hover { background: #f8fafc; }

/* Upcoming pulse dot */
.pulse-dot { animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* Toast */
#mh-toast {
    position:fixed;top:20px;right:20px;z-index:9999;
    padding:12px 20px;border-radius:12px;font-size:14px;font-weight:500;
    box-shadow:0 8px 30px rgba(0,0,0,.3);transform:translateX(120%);
    transition:transform .3s cubic-bezier(.34,1.56,.64,1);
    max-width:360px;color:white;
}
#mh-toast.show { transform:translateX(0); }
</style>
</head>
<body>
<div id="mh-toast"></div>

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
        <div class="hidden md:block">
          <div class="text-xs font-semibold text-slate-800"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
          <div class="text-[10px] text-slate-400">Player</div>
        </div>
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
      <a href="match_history.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg"><svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Match History</a>
      <a href="challenge_team.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Challenge Team</a>
      <a href="leaderboard.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Leaderboard</a>
      <div class="border-t border-slate-100 mt-2 pt-2">
        <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>My Wallet</a>
      </div>
    </nav>
  </aside>

  <!-- Main -->
  <main class="flex-1 min-w-0">
    <!-- Header row -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Match History</h1>
        <p class="text-sm text-slate-500 mt-1">All your bookings, challenges and matches</p>
      </div>
      <a href="book_slot.php" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2 rounded-xl shadow transition-all">+ Book New Slot</a>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-slate-800"><?php echo $total; ?></div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">Total Bookings</div>
      </div>
      <div class="bg-white border border-blue-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-blue-600 flex items-center justify-center gap-1">
          <span class="w-2 h-2 rounded-full bg-blue-500 pulse-dot inline-block"></span><?php echo $upcoming; ?>
        </div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">Upcoming</div>
      </div>
      <div class="bg-white border border-violet-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-violet-600"><?php echo $challenges; ?></div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">Challenges</div>
      </div>
      <div class="bg-white border border-emerald-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-lg font-extrabold text-emerald-600"><?php echo number_format($total_spent, 0); ?></div>
        <div class="text-xs text-slate-500 uppercase font-semibold mt-1">PKR Spent</div>
      </div>
    </div>

    <!-- Bookings List -->
    <?php if (empty($all_bookings)): ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-16 text-center">
      <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      </div>
      <h3 class="text-lg font-semibold text-slate-700 mb-1">No bookings yet</h3>
      <p class="text-sm text-slate-400 mb-5">Head over to Book Slot to make your first reservation.</p>
      <a href="book_slot.php" class="inline-block bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-6 py-2.5 rounded-xl shadow transition-all">Book a Slot →</a>
    </div>
    <?php else: ?>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-800">All Bookings</h2>
        <div class="flex items-center gap-2 text-xs text-slate-400 font-medium">
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>Confirmed</span>
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-violet-400 inline-block"></span>Challenge</span>
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span>Pending</span>
        </div>
      </div>
      <div class="divide-y divide-slate-100">
        <?php foreach ($all_bookings as $bk):
          $sc = $statusConfig[$bk['status']] ?? ['badge'=>'bg-slate-100 text-slate-600','label'=>ucfirst($bk['status']),'icon'=>'📋'];
          $tc = $typeConfig[$bk['booking_type']] ?? ['badge'=>'bg-slate-100 text-slate-600','label'=>$bk['booking_type']];
          $timeLabel = formatHourLabel(intval($bk['slot_hour']));
          $isUpcoming = $bk['slot_date'] >= date('Y-m-d');
          $isPast     = !$isUpcoming;
          $sportIcon  = ['Football'=>'⚽','Cricket'=>'🏏','Basketball'=>'🏀','Badminton'=>'🏸','Futsal'=>'⚽'][$bk['sport_type']] ?? '🏟️';
        ?>
        <div class="booking-row p-5 flex flex-col sm:flex-row sm:items-center gap-4">
          <!-- Left: sport icon + info -->
          <div class="flex gap-4 items-start flex-1">
            <div class="w-11 h-11 rounded-xl bg-emerald-50 flex items-center justify-center text-xl flex-shrink-0 border border-emerald-100">
              <?php echo $sportIcon; ?>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars($bk['ground_title']); ?></span>
                <?php if ($isUpcoming): ?>
                  <span class="flex items-center gap-1 text-[10px] font-bold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 pulse-dot inline-block"></span>Upcoming
                  </span>
                <?php endif; ?>
              </div>
              <div class="text-xs text-slate-500 mt-0.5">
                📅 <?php echo date('D, d M Y', strtotime($bk['slot_date'])); ?>
                &nbsp;⏰ <?php echo $timeLabel; ?>
              </div>
              <?php if ($bk['role'] === 'opponent'): ?>
              <div class="text-xs text-blue-600 font-medium mt-0.5">
                🏆 Accepted challenge from <span class="font-bold"><?php echo htmlspecialchars($bk['challenger_name'] ?? 'Unknown'); ?></span>
              </div>
              <?php elseif ($bk['challenger_team_name']): ?>
              <div class="text-xs text-orange-600 font-medium mt-0.5">
                🤝 Challenged: <span class="font-bold"><?php echo htmlspecialchars($bk['challenger_team_name']); ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Right: badges + cost -->
          <div class="flex items-center gap-3 sm:flex-col sm:items-end sm:gap-2 flex-shrink-0">
            <div class="flex items-center gap-2">
              <span class="text-xs font-bold px-2 py-1 rounded-full <?php echo $sc['badge']; ?>">
                <?php echo $sc['icon']; ?> <?php echo $sc['label']; ?>
              </span>
              <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded <?php echo $tc['badge']; ?>">
                <?php echo $tc['label']; ?>
              </span>
            </div>
            <div class="text-right">
              <div class="text-xs font-extrabold text-slate-800"><?php echo number_format($bk['amount_paid'], 0); ?> PKR</div>
              <?php if ($bk['price'] != $bk['amount_paid']): ?>
              <div class="text-[10px] text-slate-400">of <?php echo number_format($bk['price'], 0); ?> total</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
