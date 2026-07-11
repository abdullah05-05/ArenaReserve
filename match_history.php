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

// Mock match history data (to be replaced by real bookings table in future phase)
$matches = [
    ['id'=>1,'venue'=>'Victory Basketball Court','sport'=>'Basketball','date'=>'2026-06-20','time'=>'18:00 - 19:00','team'=>'City Ballers','result'=>'Win','score'=>'28-22','status'=>'Completed','cost'=>1500],
    ['id'=>2,'venue'=>'Champions Stadium A','sport'=>'Football','date'=>'2026-06-15','time'=>'17:00 - 18:00','team'=>'Street Kings','result'=>'Draw','score'=>'2-2','status'=>'Completed','cost'=>2500],
    ['id'=>3,'venue'=>'Sunset Cricket Arena','sport'=>'Cricket','date'=>'2026-06-10','time'=>'07:00 - 09:00','team'=>'Thunder XI','result'=>'Loss','score'=>'147-162','status'=>'Completed','cost'=>6400],
    ['id'=>4,'venue'=>'Victory Basketball Court','sport'=>'Basketball','date'=>'2026-06-25','time'=>'19:00 - 20:00','team'=>'Solo Practice','result'=>'N/A','score'=>'--','status'=>'Upcoming','cost'=>1500],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Match History - ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>body{font-family:'Inter',sans-serif;background:#f8fafc;}</style>
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
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Match History</h1>
        <p class="text-sm text-slate-500 mt-1">Your booking and match records</p>
      </div>
      <a href="book_slot.php" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2 rounded-lg shadow transition-all">+ Book New Slot</a>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-slate-800">3</div><div class="text-xs text-slate-500 uppercase font-semibold mt-1">Total Matches</div>
      </div>
      <div class="bg-white border border-green-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-green-600">1</div><div class="text-xs text-slate-500 uppercase font-semibold mt-1">Wins</div>
      </div>
      <div class="bg-white border border-red-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-red-500">1</div><div class="text-xs text-slate-500 uppercase font-semibold mt-1">Losses</div>
      </div>
      <div class="bg-white border border-amber-200 rounded-xl p-4 shadow-sm text-center">
        <div class="text-2xl font-extrabold text-amber-500">1</div><div class="text-xs text-slate-500 uppercase font-semibold mt-1">Draws</div>
      </div>
    </div>

    <!-- Match History Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-800">All Matches</h2>
        <div class="flex gap-2">
          <select class="text-xs border border-slate-300 rounded-lg px-2 py-1 focus:outline-none">
            <option>All Sports</option><option>Football</option><option>Cricket</option><option>Basketball</option>
          </select>
          <select class="text-xs border border-slate-300 rounded-lg px-2 py-1 focus:outline-none">
            <option>All Results</option><option>Win</option><option>Loss</option><option>Draw</option>
          </select>
        </div>
      </div>
      <div class="divide-y divide-slate-100">
        <?php foreach ($matches as $m): ?>
        <div class="p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 hover:bg-slate-50 transition-colors">
          <div class="flex gap-4 items-center">
            <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm flex-shrink-0">
              <?php echo strtoupper(substr($m['sport'],0,2)); ?>
            </div>
            <div>
              <div class="font-semibold text-slate-800 text-sm"><?php echo htmlspecialchars($m['venue']); ?></div>
              <div class="text-xs text-slate-500"><?php echo $m['date']; ?> &bull; <?php echo $m['time']; ?></div>
              <div class="text-xs text-slate-500 mt-0.5">vs <span class="font-medium text-slate-700"><?php echo htmlspecialchars($m['team']); ?></span></div>
            </div>
          </div>
          <div class="flex items-center gap-4 sm:text-right">
            <div>
              <div class="text-xs text-slate-400">Score</div>
              <div class="font-bold text-slate-700 text-sm"><?php echo $m['score']; ?></div>
            </div>
            <div>
              <?php
                if ($m['status'] === 'Upcoming') $r = ['bg-blue-100 text-blue-700','Upcoming'];
                else if ($m['result']==='Win') $r = ['bg-green-100 text-green-700','Win'];
                else if ($m['result']==='Loss') $r = ['bg-red-100 text-red-700','Loss'];
                else $r = ['bg-amber-100 text-amber-700','Draw'];
              ?>
              <span class="text-xs font-bold px-2 py-1 rounded-full <?php echo $r[0]; ?>"><?php echo $r[1]; ?></span>
            </div>
            <div class="text-xs text-slate-500 font-medium"><?php echo number_format($m['cost']); ?> PKR</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
