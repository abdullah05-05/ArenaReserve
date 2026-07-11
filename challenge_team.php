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

// Mock teams data
$mock_teams = [
    ['id'=>1,'name'=>'City Ballers','sport'=>'Basketball','players'=>5,'wins'=>8,'losses'=>3,'rating'=>4.6,'city'=>'Karachi','avatar'=>'CB'],
    ['id'=>2,'name'=>'Street Kings','sport'=>'Football','players'=>11,'wins'=>12,'losses'=>4,'rating'=>4.8,'city'=>'Lahore','avatar'=>'SK'],
    ['id'=>3,'name'=>'Thunder XI','sport'=>'Cricket','players'=>11,'wins'=>6,'losses'=>7,'rating'=>4.2,'city'=>'Karachi','avatar'=>'TX'],
    ['id'=>4,'name'=>'Smash Bros','sport'=>'Badminton','players'=>4,'wins'=>15,'losses'=>2,'rating'=>4.9,'city'=>'Islamabad','avatar'=>'SB'],
    ['id'=>5,'name'=>'Kick Force','sport'=>'Football','players'=>11,'wins'=>9,'losses'=>5,'rating'=>4.4,'city'=>'Karachi','avatar'=>'KF'],
    ['id'=>6,'name'=>'Hoop Dreams','sport'=>'Basketball','players'=>5,'wins'=>4,'losses'=>9,'rating'=>3.8,'city'=>'Lahore','avatar'=>'HD'],
];
$sport_colors = ['Basketball'=>'bg-orange-100 text-orange-700','Football'=>'bg-green-100 text-green-700','Cricket'=>'bg-sky-100 text-sky-700','Badminton'=>'bg-violet-100 text-violet-700','Futsal'=>'bg-rose-100 text-rose-700'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Challenge Team - ArenaReserve</title>
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
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Challenge a Team</h1>
        <p class="text-sm text-slate-500 mt-1">Find and send match challenges to rival teams</p>
      </div>
    </div>

    <!-- Search & Filter -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6 flex flex-col sm:flex-row gap-3">
      <input type="text" id="teamSearch" placeholder="Search teams by name or city..."
             class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-emerald-500 focus:border-emerald-500"
             onkeyup="filterTeams(this.value)">
      <select onchange="filterBySport(this.value)" class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 bg-white">
        <option value="">All Sports</option>
        <option>Basketball</option><option>Football</option><option>Cricket</option><option>Badminton</option>
      </select>
    </div>

    <!-- Teams Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5" id="teams-grid">
      <?php foreach ($mock_teams as $team): ?>
      <div class="team-card bg-white border border-slate-200 rounded-xl shadow-sm p-5 hover:shadow-md transition-shadow duration-200"
           data-name="<?php echo strtolower($team['name']); ?>" data-city="<?php echo strtolower($team['city']); ?>" data-sport="<?php echo $team['sport']; ?>">
        <div class="flex items-center gap-4 mb-4">
          <div class="w-12 h-12 rounded-xl <?php echo $sport_colors[$team['sport']] ?? 'bg-slate-100 text-slate-600'; ?> flex items-center justify-center font-extrabold text-sm flex-shrink-0">
            <?php echo $team['avatar']; ?>
          </div>
          <div class="flex-1 min-w-0">
            <h3 class="font-bold text-slate-900 truncate"><?php echo htmlspecialchars($team['name']); ?></h3>
            <p class="text-xs text-slate-500"><?php echo $team['city']; ?> &bull; <?php echo $team['players']; ?> players</p>
          </div>
          <div class="text-xs bg-amber-50 text-amber-600 font-bold px-2 py-1 rounded-full flex-shrink-0">★ <?php echo $team['rating']; ?></div>
        </div>
        <div class="grid grid-cols-3 gap-2 text-center mb-4 bg-slate-50 rounded-lg p-3">
          <div><div class="text-lg font-extrabold text-slate-800"><?php echo $team['wins']+$team['losses']; ?></div><div class="text-[10px] text-slate-400 uppercase font-semibold">Played</div></div>
          <div><div class="text-lg font-extrabold text-green-600"><?php echo $team['wins']; ?></div><div class="text-[10px] text-slate-400 uppercase font-semibold">Won</div></div>
          <div><div class="text-lg font-extrabold text-red-500"><?php echo $team['losses']; ?></div><div class="text-[10px] text-slate-400 uppercase font-semibold">Lost</div></div>
        </div>
        <div class="flex justify-between items-center">
          <span class="text-xs font-semibold px-2 py-1 rounded-full <?php echo $sport_colors[$team['sport']] ?? 'bg-slate-100 text-slate-600'; ?>">
            <?php echo $team['sport']; ?>
          </span>
          <button onclick="sendChallenge('<?php echo htmlspecialchars($team['name']); ?>')"
                  class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-1.5 rounded-lg transition-colors shadow-sm">
            ⚡ Challenge
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>

<!-- Challenge Modal -->
<div id="challenge-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" style="display:none!important">
  <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
    <h3 class="text-lg font-bold text-slate-900 mb-1">Send Challenge ⚡</h3>
    <p class="text-sm text-slate-500 mb-5">You are challenging: <span id="modal-team-name" class="font-bold text-emerald-700"></span></p>
    <div class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-slate-700 mb-1">Proposed Match Date</label>
        <input type="date" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-700 mb-1">Preferred Venue</label>
        <select class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-emerald-500 focus:border-emerald-500">
          <option value="">Select a venue...</option>
          <option>Victory Basketball Court</option><option>Champions Stadium A</option><option>Sunset Cricket Arena</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-700 mb-1">Message (Optional)</label>
        <textarea class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-emerald-500 focus:border-emerald-500" rows="2" placeholder="Good luck, see you on the field!"></textarea>
      </div>
    </div>
    <div class="flex gap-3 mt-5">
      <button onclick="closeModal()" class="flex-1 py-2 border border-slate-300 text-slate-700 text-sm font-semibold rounded-xl hover:bg-slate-50">Cancel</button>
      <button onclick="confirmChallenge()" class="flex-1 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-xl shadow transition-all">Send Challenge ⚡</button>
    </div>
  </div>
</div>

<script>
function filterTeams(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.team-card').forEach(c => {
    const n = c.dataset.name + ' ' + c.dataset.city;
    c.style.display = n.includes(q) ? '' : 'none';
  });
}
function filterBySport(s) {
  document.querySelectorAll('.team-card').forEach(c => {
    c.style.display = (!s || c.dataset.sport === s) ? '' : 'none';
  });
}
function sendChallenge(name) {
  document.getElementById('modal-team-name').textContent = name;
  document.getElementById('challenge-modal').style.display = 'flex';
}
function closeModal() { document.getElementById('challenge-modal').style.display = 'none'; }
function confirmChallenge() {
  closeModal();
  const banner = document.createElement('div');
  banner.className = 'fixed top-4 right-4 bg-emerald-600 text-white text-sm font-semibold px-5 py-3 rounded-xl shadow-lg z-50';
  banner.textContent = '✅ Challenge sent successfully!';
  document.body.appendChild(banner);
  setTimeout(() => banner.remove(), 3000);
}
document.getElementById('challenge-modal').addEventListener('click', function(e){if(e.target===this)closeModal();});
</script>
</body>
</html>
