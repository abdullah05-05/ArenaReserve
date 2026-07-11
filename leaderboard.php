<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $available_balance = $wallet['available_balance'] ?? 0.00;
} catch (Exception $e) { $available_balance = 0.00; }

$my_name = htmlspecialchars($_SESSION['name'] ?? 'You');
// Mock leaderboard data per sport
$leaderboards = [
    'overall' => [
        ['rank'=>1,'name'=>'Ahmed Khan','sport'=>'Football','wins'=>24,'rating'=>4.97,'team'=>'Street Kings','badge'=>'🥇','city'=>'Lahore'],
        ['rank'=>2,'name'=>'Ali Raza','sport'=>'Cricket','wins'=>21,'rating'=>4.91,'team'=>'Thunder XI','badge'=>'🥈','city'=>'Karachi'],
        ['rank'=>3,'name'=>'Sara Baig','sport'=>'Badminton','wins'=>19,'rating'=>4.87,'team'=>'Smash Bros','badge'=>'🥉','city'=>'Islamabad'],
        ['rank'=>4,'name'=>'Usman Tariq','sport'=>'Basketball','wins'=>17,'rating'=>4.79,'team'=>'City Ballers','badge'=>'','city'=>'Karachi'],
        ['rank'=>5,'name'=>'Fatima Malik','sport'=>'Football','wins'=>15,'rating'=>4.72,'team'=>'Kick Force','badge'=>'','city'=>'Karachi'],
        ['rank'=>6,'name'=>'Zaid Ahmed','sport'=>'Cricket','wins'=>14,'rating'=>4.65,'team'=>'Thunder XI','badge'=>'','city'=>'Karachi'],
        ['rank'=>7,'name'=>'Hamza Shah','sport'=>'Basketball','wins'=>12,'rating'=>4.58,'team'=>'Hoop Dreams','badge'=>'','city'=>'Lahore'],
        ['rank'=>8,'name'=>'Nadia Iqbal','sport'=>'Badminton','wins'=>11,'rating'=>4.50,'team'=>'Smash Bros','badge'=>'','city'=>'Islamabad'],
        ['rank'=>9,'name'=>$my_name,'sport'=>'Football','wins'=>8,'rating'=>4.35,'team'=>'My Team','badge'=>'⭐','city'=>'Karachi'],
        ['rank'=>10,'name'=>'Omar Qayyum','sport'=>'Cricket','wins'=>7,'rating'=>4.20,'team'=>'Spin Kings','badge'=>'','city'=>'Faisalabad'],
    ]
];
$sport_icons = ['Football'=>'⚽','Cricket'=>'🏏','Basketball'=>'🏀','Badminton'=>'🏸','Futsal'=>'⚽'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leaderboard - ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body{font-family:'Inter',sans-serif;background:#f8fafc;}
.podium-1{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#fff;}
.podium-2{background:linear-gradient(135deg,#94a3b8,#64748b);color:#fff;}
.podium-3{background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;}
</style>
</head>
<body>
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between h-16 items-center">
    <a href="explore.php" class="flex items-center gap-2 text-emerald-600 text-xl font-bold">
      <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0112 20.055a11.952 11.952 0 01-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
      ArenaReserve
    </a>
    <div class="flex items-center gap-3">
      <a href="wallet.php" class="flex items-center bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full text-xs font-semibold border border-emerald-200">
        <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span><?php echo number_format($available_balance,0); ?> PKR
      </a>
      <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg border border-slate-200 text-xs font-semibold">
        <span class="px-2 py-1 bg-white rounded shadow-sm text-emerald-600">Player</span>
        <a href="switch_role.php" class="px-2 py-1 text-slate-500 hover:text-slate-700">Owner</a>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm"><?php echo strtoupper(substr($_SESSION['name'],0,1)); ?></div>
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
      <a href="challenge_team.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Challenge Team</a>
      <a href="leaderboard.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg"><svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Leaderboard</a>
      <div class="border-t border-slate-100 mt-2 pt-2">
        <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>My Wallet</a>
      </div>
    </nav>
  </aside>

  <!-- Main Content -->
  <main class="flex-1 min-w-0">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-slate-900">Leaderboard</h1>
      <p class="text-sm text-slate-500 mt-1">Top players across all sports in ArenaReserve</p>
    </div>

    <!-- Top 3 Podium -->
    <div class="grid grid-cols-3 gap-4 mb-8">
      <!-- 2nd Place -->
      <div class="podium-2 rounded-2xl p-5 text-center shadow-md mt-6">
        <div class="text-3xl mb-2">🥈</div>
        <div class="w-12 h-12 rounded-full bg-white/20 mx-auto flex items-center justify-center font-extrabold text-lg">
          <?php echo strtoupper(substr($leaderboards['overall'][1]['name'],0,1)); ?>
        </div>
        <div class="mt-2 font-bold text-sm truncate"><?php echo $leaderboards['overall'][1]['name']; ?></div>
        <div class="text-xs opacity-80"><?php echo $leaderboards['overall'][1]['wins']; ?> wins</div>
      </div>
      <!-- 1st Place -->
      <div class="podium-1 rounded-2xl p-5 text-center shadow-xl">
        <div class="text-4xl mb-2">🥇</div>
        <div class="w-14 h-14 rounded-full bg-white/20 mx-auto flex items-center justify-center font-extrabold text-xl">
          <?php echo strtoupper(substr($leaderboards['overall'][0]['name'],0,1)); ?>
        </div>
        <div class="mt-2 font-extrabold truncate"><?php echo $leaderboards['overall'][0]['name']; ?></div>
        <div class="text-xs opacity-80"><?php echo $leaderboards['overall'][0]['wins']; ?> wins</div>
        <div class="text-xs opacity-80 font-semibold">★ <?php echo $leaderboards['overall'][0]['rating']; ?></div>
      </div>
      <!-- 3rd Place -->
      <div class="podium-3 rounded-2xl p-5 text-center shadow-md mt-8">
        <div class="text-3xl mb-2">🥉</div>
        <div class="w-12 h-12 rounded-full bg-white/20 mx-auto flex items-center justify-center font-extrabold text-lg">
          <?php echo strtoupper(substr($leaderboards['overall'][2]['name'],0,1)); ?>
        </div>
        <div class="mt-2 font-bold text-sm truncate"><?php echo $leaderboards['overall'][2]['name']; ?></div>
        <div class="text-xs opacity-80"><?php echo $leaderboards['overall'][2]['wins']; ?> wins</div>
      </div>
    </div>

    <!-- Full Leaderboard Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-800">All Rankings</h2>
        <div class="flex gap-2">
          <select class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 focus:outline-none bg-white">
            <option>All Sports</option><option>Football</option><option>Cricket</option><option>Basketball</option><option>Badminton</option>
          </select>
          <select class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 focus:outline-none bg-white">
            <option>This Month</option><option>This Week</option><option>All Time</option>
          </select>
        </div>
      </div>
      <div class="divide-y divide-slate-100">
        <?php foreach ($leaderboards['overall'] as $p): ?>
        <div class="px-5 py-4 flex items-center gap-4 hover:bg-slate-50 transition-colors <?php echo ($p['name'] === htmlspecialchars($_SESSION['name'])) ? 'bg-emerald-50/60 border-l-4 border-emerald-500' : ''; ?>">
          <!-- Rank -->
          <div class="w-8 text-center font-extrabold <?php echo $p['rank']<=3?'text-amber-500':'text-slate-400'; ?> text-sm flex-shrink-0">
            <?php echo $p['badge'] ?: '#'.$p['rank']; ?>
          </div>
          <!-- Avatar -->
          <div class="w-9 h-9 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center font-bold text-sm flex-shrink-0">
            <?php echo strtoupper(substr($p['name'],0,1)); ?>
          </div>
          <!-- Info -->
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-slate-800 text-sm truncate"><?php echo htmlspecialchars($p['name']); ?><?php if($p['badge']==='⭐') echo ' <span class="text-xs text-emerald-600 font-bold">(You)</span>'; ?></div>
            <div class="text-xs text-slate-500"><?php echo $p['team']; ?> &bull; <?php echo $p['city']; ?></div>
          </div>
          <!-- Sport -->
          <div class="hidden sm:block text-xs font-semibold text-slate-600">
            <?php echo ($sport_icons[$p['sport']] ?? '') . ' ' . $p['sport']; ?>
          </div>
          <!-- Wins -->
          <div class="text-sm font-bold text-slate-700 flex-shrink-0"><?php echo $p['wins']; ?> <span class="text-xs font-normal text-slate-400">wins</span></div>
          <!-- Rating -->
          <div class="text-xs font-bold text-amber-500 flex-shrink-0">★ <?php echo $p['rating']; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
