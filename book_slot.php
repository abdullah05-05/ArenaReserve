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

try {
    $stmt = $pdo->prepare("SELECT * FROM grounds WHERE is_verified = 1 ORDER BY title ASC");
    $stmt->execute();
    $grounds = $stmt->fetchAll();
} catch (Exception $e) { $grounds = []; }

$selected_ground_id = intval($_GET['ground'] ?? ($grounds[0]['id'] ?? 0));
$selected_ground = null;
foreach ($grounds as $g) { if ($g['id'] == $selected_ground_id) { $selected_ground = $g; break; } }
if (!$selected_ground && !empty($grounds)) $selected_ground = $grounds[0];

// Slot schedule: time => [type: available|booked|my_booking|challenge, price]
$slots = [
    ['time'=>'06:00 AM - 07:00 AM','type'=>'available','price'=>2000],
    ['time'=>'07:00 AM - 08:00 AM','type'=>'booked','price'=>2000],
    ['time'=>'08:00 AM - 09:00 AM','type'=>'my_booking','price'=>2500],
    ['time'=>'09:00 AM - 10:00 AM','type'=>'available','price'=>2500],
    ['time'=>'10:00 AM - 11:00 AM','type'=>'available','price'=>2500],
    ['time'=>'11:00 AM - 12:00 PM','type'=>'available','price'=>2500],
    ['time'=>'12:00 PM - 01:00 PM','type'=>'available','price'=>2500],
    ['time'=>'01:00 PM - 02:00 PM','type'=>'booked','price'=>2500],
    ['time'=>'02:00 PM - 03:00 PM','type'=>'available','price'=>3000],
    ['time'=>'03:00 PM - 04:00 PM','type'=>'available','price'=>3000],
    ['time'=>'04:00 PM - 05:00 PM','type'=>'challenge','price'=>3000],
    ['time'=>'05:00 PM - 06:00 PM','type'=>'available','price'=>3000],
    ['time'=>'06:00 PM - 07:00 PM','type'=>'available','price'=>3500],
    ['time'=>'07:00 PM - 08:00 PM','type'=>'booked','price'=>3500],
    ['time'=>'08:00 PM - 09:00 PM','type'=>'available','price'=>3500],
    ['time'=>'09:00 PM - 10:00 PM','type'=>'available','price'=>3500],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book a Slot - ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { font-family: 'Inter', sans-serif; }
body { background: #f5f6fa; }
.slot-available { background: #e6f9f1; border: 1px solid #a7f3d0; }
.slot-booked    { background: #fee2e2; border: 1px solid #fca5a5; }
.slot-my_booking{ background: #fef3c7; border: 1px solid #fcd34d; }
.slot-challenge { background: #ede9fe; border: 1px solid #c4b5fd; }
.slot-selected  { background: #d1fae5; border: 2px solid #059669; }
.slot-available:hover { border-color: #059669; cursor: pointer; }
</style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">
<!-- Top Header -->
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
        <div class="hidden md:block text-left">
          <div class="text-xs font-semibold text-slate-800"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
          <div class="text-[10px] text-slate-400 capitalize">Player</div>
        </div>
        <a href="logout.php" class="text-xs text-red-500 font-medium">Logout</a>
      </div>
    </div>
  </div>
</header>

<div class="flex-1 flex max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">
  <!-- Sidebar Navigation -->
  <aside class="hidden lg:block w-64 flex-shrink-0">
    <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
      <a href="explore.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>Explore Grounds</a>
      <a href="book_slot.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg"><svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Book Slot</a>
      <a href="match_history.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Match History</a>
      <a href="challenge_team.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Challenge Team</a>
      <a href="leaderboard.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>Leaderboard</a>
      <div class="border-t border-slate-100 mt-1 pt-1">
        <a href="wallet.php" class="text-slate-600 hover:bg-slate-50 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"><svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>My Wallet</a>
      </div>
    </nav>
  </aside>

  <!-- Main Content -->
  <main class="flex-1 min-w-0">
    <h1 class="text-xl font-bold text-gray-800">Book a Slot</h1>
    <p class="text-sm text-gray-400 mt-0.5 mb-5">Select your preferred date and time</p>

    <!-- Select Ground -->
    <?php if (!empty($grounds)): ?>
    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-5 max-w-2xl">
      <div class="flex items-center gap-2 mb-3">
        <div class="w-7 h-7 bg-emerald-100 rounded flex items-center justify-center">
          <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-gray-800">Select Venue</div>
          <div class="text-xs text-gray-400">Choose your ground</div>
        </div>
      </div>
      <select onchange="location.href='book_slot.php?ground='+this.value+'&date='+document.getElementById('booking-date').value"
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-1 focus:ring-emerald-400">
        <?php foreach ($grounds as $g): ?>
          <option value="<?php echo $g['id']; ?>" <?php echo ($g['id']==$selected_ground_id)?'selected':''; ?>>
            <?php echo htmlspecialchars($g['title']); ?> — <?php echo $g['sport_type']; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <!-- Select Date -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-5 max-w-2xl">
      <div class="flex items-center gap-2 mb-3">
        <div class="w-7 h-7 bg-emerald-100 rounded flex items-center justify-center">
          <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-gray-800">Select Date</div>
          <div class="text-xs text-gray-400">Choose your booking date</div>
        </div>
      </div>
      <input id="booking-date" type="date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo $_GET['date'] ?? date('Y-m-d'); ?>"
             class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-1 focus:ring-emerald-400">
    </div>

    <!-- Available Time Slots -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 max-w-2xl">
      <div class="flex items-center justify-between mb-4">
        <div class="text-sm font-semibold text-gray-800">Available Time Slots</div>
        <div class="flex items-center gap-3 text-[11px] text-gray-500 font-medium">
          <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-emerald-300 inline-block"></span>Available</span>
          <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-red-300 inline-block"></span>Booked</span>
          <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-amber-300 inline-block"></span>My Booking</span>
          <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-violet-300 inline-block"></span>Challenge</span>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <?php foreach ($slots as $slot):
          $label = ['booked'=>'Booked','my_booking'=>'My Booking','challenge'=>'Challenge'][$slot['type']] ?? '';
          $textColor = ['available'=>'text-emerald-800','booked'=>'text-red-700','my_booking'=>'text-amber-700','challenge'=>'text-violet-700'][$slot['type']];
          $priceColor = ['available'=>'text-emerald-700','booked'=>'text-red-600','my_booking'=>'text-amber-600','challenge'=>'text-violet-600'][$slot['type']];
        ?>
        <div class="slot-<?php echo $slot['type']; ?> rounded-lg p-3 <?php echo $slot['type']==='available'?'cursor-pointer':''; ?>"
             <?php if($slot['type']==='available'): ?>onclick="selectSlot(this,'<?php echo $slot['time']; ?>',<?php echo $slot['price']; ?>)"<?php endif; ?>>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5 <?php echo $textColor; ?>">
              <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/></svg>
              <span class="text-xs font-semibold"><?php echo $slot['time']; ?></span>
            </div>
            <span class="text-xs font-bold <?php echo $priceColor; ?>"><?php echo number_format($slot['price']); ?> PKR</span>
          </div>
          <?php if ($label): ?>
          <div class="text-center text-[10px] font-semibold <?php echo $textColor; ?> mt-1"><?php echo $label; ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Booking Confirm Panel -->
    <div id="booking-panel" class="hidden mt-5 max-w-2xl bg-white rounded-lg border border-emerald-200 p-5">
      <h3 class="text-sm font-bold text-gray-800 mb-3">Confirm Booking</h3>
      <div class="grid grid-cols-2 gap-3 text-sm mb-4">
        <div><span class="text-gray-400 text-xs block">Venue</span><span class="font-semibold" id="bs-venue"><?php echo htmlspecialchars($selected_ground['title'] ?? ''); ?></span></div>
        <div><span class="text-gray-400 text-xs block">Time Slot</span><span class="font-semibold" id="bs-slot">--</span></div>
        <div><span class="text-gray-400 text-xs block">Date</span><span class="font-semibold" id="bs-date"><?php echo date('Y-m-d'); ?></span></div>
        <div><span class="text-gray-400 text-xs block">Cost</span><span class="font-bold text-emerald-600 text-base" id="bs-cost">-- PKR</span></div>
      </div>
      <button onclick="confirmBooking()" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-bold py-2.5 rounded-lg transition-colors">
        Confirm & Pay from Wallet
      </button>
    </div>
  </main>
</div>

<script>
function selectSlot(el, time, price) {
  document.querySelectorAll('.slot-selected').forEach(s => {
    s.classList.remove('slot-selected');
    s.classList.add('slot-available');
  });
  el.classList.remove('slot-available'); el.classList.add('slot-selected');
  document.getElementById('bs-slot').textContent = time;
  document.getElementById('bs-cost').textContent = price.toLocaleString() + ' PKR';
  document.getElementById('bs-date').textContent = document.getElementById('booking-date').value;
  document.getElementById('booking-panel').classList.remove('hidden');
  document.getElementById('booking-panel').scrollIntoView({behavior:'smooth',block:'nearest'});
}
function confirmBooking() {
  const slot = document.getElementById('bs-slot').textContent;
  const cost = document.getElementById('bs-cost').textContent;
  if (slot === '--') { alert('Please select a time slot first.'); return; }
  alert('✅ Booking submitted!\nSlot: ' + slot + '\nCost: ' + cost + '\n\nPayment integration coming in next phase.');
}
</script>
</body>
</html>
