<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Fetch wallet balance
try {
    $stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $available_balance = floatval($wallet['available_balance'] ?? 0);
} catch (Exception $e) { $available_balance = 0.00; }

// Fetch verified active grounds
try {
    $stmt = $pdo->prepare("SELECT * FROM grounds WHERE is_verified = 1 AND COALESCE(ground_status, 'Active') = 'Active' ORDER BY title ASC");
    $stmt->execute();
    $grounds = $stmt->fetchAll();
} catch (Exception $e) { $grounds = []; }

$selected_ground_id = intval($_GET['ground'] ?? ($grounds[0]['id'] ?? 0));
$selected_ground    = null;
foreach ($grounds as $g) { if ($g['id'] == $selected_ground_id) { $selected_ground = $g; break; } }
if (!$selected_ground && !empty($grounds)) { $selected_ground = $grounds[0]; $selected_ground_id = $selected_ground['id']; }

$selected_date = $_GET['date'] ?? date('Y-m-d');
if ($selected_date < date('Y-m-d')) $selected_date = date('Y-m-d');

// Build slots list with real statuses
$slots = [];
if ($selected_ground) {
    // Clean expired holds first
    try { $pdo->exec("DELETE FROM slot_holds WHERE expires_at < NOW()"); } catch(Exception $e) {}

    // Fetch configured slots
    try {
        $stmt = $pdo->prepare("SELECT hour, slot_type, price FROM ground_slots WHERE ground_id = ? AND is_available = 1 ORDER BY hour ASC");
        $stmt->execute([$selected_ground_id]);
        $db_slots = $stmt->fetchAll();
    } catch (Exception $e) { $db_slots = []; }

    if (!empty($db_slots)) {
        // Fetch bookings for this ground/date
        try {
            $stmt = $pdo->prepare("SELECT slot_hour, status, booked_by FROM bookings WHERE ground_id = ? AND slot_date = ? AND status NOT IN ('cancelled')");
            $stmt->execute([$selected_ground_id, $selected_date]);
            $bookings_map = [];
            foreach ($stmt->fetchAll() as $b) {
                $bookings_map[$b['slot_hour']] = $b;
            }
        } catch (Exception $e) { $bookings_map = []; }

        // Fetch active holds
        try {
            $stmt = $pdo->prepare("SELECT slot_hour, held_by, expires_at FROM slot_holds WHERE ground_id = ? AND slot_date = ? AND expires_at >= NOW()");
            $stmt->execute([$selected_ground_id, $selected_date]);
            $holds_map = [];
            foreach ($stmt->fetchAll() as $h) {
                $holds_map[$h['slot_hour']] = $h;
            }
        } catch (Exception $e) { $holds_map = []; }

        foreach ($db_slots as $s) {
            $h = intval($s['hour']);
            $suffix    = $h < 12 ? 'AM' : 'PM';
            $displayH  = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
            $nextH     = $h + 1;
            $nextDisp  = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
            $nextSuffix = $nextH < 12 ? 'AM' : 'PM';
            $time_label = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuffix);

            $type  = 'available';
            $label = '';
            $hold_remaining = 0;

            if (isset($bookings_map[$h])) {
                $bk = $bookings_map[$h];
                if ($bk['booked_by'] == $user_id) {
                    $type  = 'my_booking';
                    $label = match($bk['status']) {
                        'confirmed'          => 'My Booking',
                        'challenge_open'     => 'My Challenge',
                        'challenge_pending'  => 'Pending',
                        'challenge_accepted' => 'Match Set',
                        default              => 'My Booking'
                    };
                } else {
                    $type  = match($bk['status']) {
                        'challenge_open'    => 'challenge',
                        'challenge_pending' => 'challenge',
                        default            => 'booked'
                    };
                    $label = match($bk['status']) {
                        'challenge_open'    => 'Open Challenge',
                        'challenge_pending' => 'Challenge',
                        default            => 'Booked'
                    };
                }
            } elseif (isset($holds_map[$h])) {
                $hold = $holds_map[$h];
                if ($hold['held_by'] == $user_id) {
                    $type  = 'held';
                    $hold_remaining = max(0, strtotime($hold['expires_at']) - time());
                } else {
                    $type  = 'on_hold';
                    $hold_remaining = max(0, strtotime($hold['expires_at']) - time());
                }
            }

            $slots[] = [
                'hour'           => $h,
                'time'           => $time_label,
                'type'           => $type,
                'slot_type'      => $s['slot_type'],
                'price'          => floatval($s['price']),
                'label'          => $label,
                'hold_remaining' => $hold_remaining,
            ];
        }
    } else {
        $no_slots_configured = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book a Slot – ArenaReserve</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { font-family: 'Inter', sans-serif; }
body { background: #f5f6fa; }

/* Slot states */
.slot-available  { background: #e6f9f1; border: 1.5px solid #a7f3d0; cursor: pointer; }
.slot-available:hover { background: #d1fae5; border-color: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,0.15); }
.slot-booked     { background: #fee2e2; border: 1.5px solid #fca5a5; cursor: not-allowed; opacity: 0.85; }
.slot-my_booking { background: #fef3c7; border: 1.5px solid #fcd34d; cursor: default; }
.slot-challenge  { background: #ede9fe; border: 1.5px solid #c4b5fd; cursor: default; }
.slot-held       { background: #dbeafe; border: 2px solid #3b82f6; cursor: pointer; }
.slot-on_hold    { background: #f1f5f9; border: 1.5px solid #cbd5e1; cursor: not-allowed; opacity: 0.7; }
.slot-selected   { background: #d1fae5; border: 2.5px solid #059669; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(5,150,105,0.2); }

.slot-card { border-radius: 10px; padding: 12px; transition: all 0.18s ease; }

/* Modal */
#booking-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
    z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 16px;
    opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
}
#booking-modal-overlay.open { opacity: 1; pointer-events: all; }
#booking-modal {
    background: white; border-radius: 20px; box-shadow: 0 25px 80px rgba(0,0,0,0.3);
    max-width: 500px; width: 100%; transform: scale(0.92) translateY(20px);
    transition: transform 0.25s cubic-bezier(.34,1.56,.64,1), opacity 0.2s ease;
    opacity: 0; overflow: hidden;
}
#booking-modal-overlay.open #booking-modal { transform: scale(1) translateY(0); opacity: 1; }

/* Hold timer */
.hold-timer-bar {
    height: 4px; background: #dbeafe; border-radius: 2px; overflow: hidden; margin-top: 8px;
}
.hold-timer-fill {
    height: 100%; background: linear-gradient(90deg, #3b82f6, #6366f1);
    transition: width 1s linear; border-radius: 2px;
}

/* Choice cards */
.choice-card {
    border: 2px solid #e2e8f0; border-radius: 14px; padding: 16px; cursor: pointer;
    transition: all 0.18s ease; position: relative; overflow: hidden;
}
.choice-card:hover { border-color: #059669; background: #f0fdf4; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(5,150,105,0.12); }
.choice-card.selected { border-color: #059669; background: #f0fdf4; box-shadow: 0 0 0 3px rgba(5,150,105,0.15); }

/* Step indicator */
.step-dot { width: 8px; height: 8px; border-radius: 50%; background: #e2e8f0; transition: background 0.2s; }
.step-dot.active { background: #059669; }

/* Toast */
#toast {
    position: fixed; top: 20px; right: 20px; z-index: 9999;
    background: #1e293b; color: white; padding: 12px 20px; border-radius: 12px;
    font-size: 14px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,0.3);
    transform: translateX(120%); transition: transform 0.3s cubic-bezier(.34,1.56,.64,1);
    display: flex; align-items: center; gap: 8px; max-width: 360px;
}
#toast.show { transform: translateX(0); }
#toast.success { background: linear-gradient(135deg, #059669, #047857); }
#toast.error   { background: linear-gradient(135deg, #dc2626, #b91c1c); }
#toast.info    { background: linear-gradient(135deg, #3b82f6, #2563eb); }
</style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">

<!-- Toast notification -->
<div id="toast"></div>

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
        <div class="hidden md:block text-left">
          <div class="text-xs font-semibold text-slate-800"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
          <div class="text-[10px] text-slate-400">Player</div>
        </div>
        <a href="logout.php" class="text-xs text-red-500 font-medium">Logout</a>
      </div>
    </div>
  </div>
</header>

<div class="flex-1 flex max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">
  <!-- Sidebar -->
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

    <!-- Wallet info box -->
    <div class="mt-4 bg-gradient-to-br from-emerald-600 to-teal-700 rounded-xl p-4 text-white shadow-lg">
      <div class="text-xs font-semibold opacity-80 mb-1">Wallet Balance</div>
      <div class="text-2xl font-extrabold"><?php echo number_format($available_balance, 0); ?> <span class="text-sm font-medium opacity-80">PKR</span></div>
      <a href="wallet.php" class="inline-flex items-center gap-1 mt-3 text-xs bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-full font-semibold transition-colors">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Top Up
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="flex-1 min-w-0">
    <h1 class="text-xl font-bold text-gray-800">Book a Slot</h1>
    <p class="text-sm text-gray-400 mt-0.5 mb-5">Select your preferred venue, date and time</p>

    <!-- Legend -->
    <div class="flex flex-wrap items-center gap-3 mb-4 text-[11px] font-semibold text-gray-500">
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-200 border border-emerald-400 inline-block"></span>Available</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-red-200 border border-red-400 inline-block"></span>Booked</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-amber-200 border border-amber-400 inline-block"></span>My Booking</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-violet-200 border border-violet-400 inline-block"></span>Challenge</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-blue-200 border border-blue-400 inline-block"></span>On Hold (5 min)</span>
    </div>

    <!-- Select Ground -->
    <?php if (!empty($grounds)): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 max-w-2xl shadow-sm">
      <div class="flex items-center gap-2 mb-3">
        <div class="w-7 h-7 bg-emerald-100 rounded-lg flex items-center justify-center">
          <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-gray-800">Select Venue</div>
          <div class="text-xs text-gray-400">Choose your ground</div>
        </div>
      </div>
      <select id="ground-select" data-ground-name="<?php echo htmlspecialchars($selected_ground['title'] ?? ''); ?>"
              onchange="location.href='book_slot.php?ground='+this.value+'&date='+document.getElementById('booking-date').value"
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-400">
        <?php foreach ($grounds as $g): ?>
          <option value="<?php echo $g['id']; ?>" <?php echo ($g['id']==$selected_ground_id)?'selected':''; ?>>
            <?php echo htmlspecialchars($g['title']); ?> — <?php echo $g['sport_type']; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <!-- Select Date -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 max-w-2xl shadow-sm">
      <div class="flex items-center gap-2 mb-3">
        <div class="w-7 h-7 bg-emerald-100 rounded-lg flex items-center justify-center">
          <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-gray-800">Select Date</div>
          <div class="text-xs text-gray-400">Choose your booking date</div>
        </div>
      </div>
      <input id="booking-date" type="date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo $selected_date; ?>"
             onchange="location.href='book_slot.php?ground=<?php echo $selected_ground_id; ?>&date='+this.value"
             class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-400">
    </div>

    <!-- Time Slots Grid -->
    <div class="bg-white rounded-xl border border-gray-200 p-5 max-w-2xl shadow-sm">
      <div class="flex items-center justify-between mb-4">
        <div>
          <div class="text-sm font-bold text-gray-800">Available Time Slots</div>
          <div class="text-xs text-gray-400 mt-0.5"><?php echo date('l, F j, Y', strtotime($selected_date)); ?></div>
        </div>
        <?php if ($selected_ground): ?>
        <div class="text-xs text-slate-500 font-medium bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200">
          <?php echo htmlspecialchars($selected_ground['sport_type']); ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if (empty($slots)): ?>
        <div class="text-center py-12">
          <div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
            <svg class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <?php if (!empty($no_slots_configured)): ?>
            <p class="text-sm font-semibold text-slate-500">No slots configured yet</p>
            <p class="text-xs text-slate-400 mt-1">The owner hasn't set up time slots for this venue yet.</p>
          <?php else: ?>
            <p class="text-sm font-semibold text-slate-500">No available slots</p>
            <p class="text-xs text-slate-400 mt-1">There are no open slots for this venue on the selected date.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="grid grid-cols-2 gap-3" id="slots-grid">
          <?php foreach ($slots as $slot):
            $isPeak = ($slot['slot_type'] ?? 'Normal') === 'Peak';
            // 'available' and 'held' (own hold) are both clickable
            $isClickable = in_array($slot['type'], ['available', 'held']);
            $colorClasses = [
              'available'  => ['text' => 'text-emerald-800', 'price' => 'text-emerald-700'],
              'booked'     => ['text' => 'text-red-700',     'price' => 'text-red-600'],
              'my_booking' => ['text' => 'text-amber-700',   'price' => 'text-amber-600'],
              'challenge'  => ['text' => 'text-violet-700',  'price' => 'text-violet-600'],
              'held'       => ['text' => 'text-blue-700',    'price' => 'text-blue-600'],
              'on_hold'    => ['text' => 'text-slate-600',   'price' => 'text-slate-500'],
            ];
            $tc = $colorClasses[$slot['type']] ?? $colorClasses['available'];
          ?>
          <div class="slot-card slot-<?php echo $slot['type']; ?>"
               data-hour="<?php echo $slot['hour']; ?>"
               data-time="<?php echo htmlspecialchars($slot['time']); ?>"
               data-price="<?php echo $slot['price']; ?>"
               data-ground="<?php echo $selected_ground_id; ?>"
               data-date="<?php echo $selected_date; ?>"
               <?php if (in_array($slot['type'], ['held','on_hold'])): ?>data-hold-remaining="<?php echo $slot['hold_remaining']; ?>"<?php endif; ?>
               <?php if ($isClickable): ?>onclick="clickSlot(this)"<?php endif; ?>>

            <div class="flex items-center justify-between mb-1.5">
              <div class="flex items-center gap-1.5 <?php echo $tc['text']; ?>">
                <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/></svg>
                <span class="text-xs font-semibold"><?php echo $slot['time']; ?></span>
              </div>
              <span class="text-xs font-bold <?php echo $tc['price']; ?>"><?php echo number_format($slot['price']); ?> PKR</span>
            </div>

            <div class="flex items-center justify-between">
              <?php if ($isPeak): ?>
                <span class="text-[10px] font-bold bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded">🔥 Peak</span>
              <?php else: ?>
                <span class="text-[10px] font-bold bg-emerald-50 text-emerald-600 px-1.5 py-0.5 rounded">🟢 Normal</span>
              <?php endif; ?>
              <?php if ($slot['label']): ?>
                <span class="text-[10px] font-semibold <?php echo $tc['text']; ?>"><?php echo htmlspecialchars($slot['label']); ?></span>
              <?php endif; ?>
            </div>

            <?php if (in_array($slot['type'], ['held', 'on_hold'])): ?>
            <div class="hold-timer-bar mt-2">
              <div class="hold-timer-fill" id="fill-<?php echo $slot['hour']; ?>" style="width:<?php echo min(100, round($slot['hold_remaining'] / 3)); ?>%"></div>
            </div>
            <div class="text-[10px] text-blue-600 font-semibold mt-1" id="hold-text-<?php echo $slot['hour']; ?>">
              <?php echo $slot['type'] === 'held' ? '🔵 Your hold – ' : '⏳ On hold – '; ?><?php echo ceil($slot['hold_remaining'] / 60); ?>m remaining
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- ============================================================
     BOOKING MODAL
============================================================ -->
<div id="booking-modal-overlay">
  <div id="booking-modal">

    <!-- Modal Header -->
    <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 pt-6 pb-5 text-white">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-xs font-semibold opacity-80 mb-0.5" id="modal-ground-name">Ground Name</div>
          <h2 class="text-xl font-extrabold" id="modal-slot-time">10:00 AM – 11:00 AM</h2>
        </div>
        <button onclick="closeModal()" class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-colors">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="flex items-center gap-4 mt-3 text-xs opacity-90">
        <span>📅 <span id="modal-date">--</span></span>
        <span>💰 <span id="modal-price" class="font-bold">--</span> PKR full price</span>
      </div>

      <!-- Hold countdown bar -->
      <div class="mt-3">
        <div class="flex items-center justify-between text-xs mb-1">
          <span class="opacity-80">Slot hold expires in</span>
          <span class="font-bold" id="modal-countdown">5:00</span>
        </div>
        <div class="h-1.5 bg-white/30 rounded-full overflow-hidden">
          <div class="h-full bg-white rounded-full transition-all duration-1000" id="modal-progress" style="width:100%"></div>
        </div>
      </div>

      <!-- Step dots -->
      <div class="flex items-center gap-2 mt-3">
        <div class="step-dot active" id="dot-1"></div>
        <div class="step-dot" id="dot-2"></div>
      </div>
    </div>

    <!-- Step 1: Choose booking type -->
    <div id="step-1" class="p-6">
      <p class="text-sm font-semibold text-slate-700 mb-4">How would you like to book this slot?</p>
      <div class="space-y-3">

        <!-- Direct Booking -->
        <div class="choice-card" onclick="selectBookingType('direct', this)">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center flex-shrink-0">
              <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="flex-1">
              <div class="font-bold text-slate-800 text-sm">Direct Booking</div>
              <div class="text-xs text-slate-500 mt-0.5">Reserve the slot exclusively for yourself. Full payment required.</div>
              <div class="mt-1.5 text-xs font-bold text-emerald-600" id="direct-price-label">Full price: -- PKR</div>
            </div>
          </div>
        </div>

        <!-- Open Challenge -->
        <div class="choice-card" onclick="selectBookingType('open_challenge', this)">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-violet-100 flex items-center justify-center flex-shrink-0">
              <svg class="h-5 w-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div class="flex-1">
              <div class="font-bold text-slate-800 text-sm">Open Challenge</div>
              <div class="text-xs text-slate-500 mt-0.5">Post an open match. Pay 50% now, opponent pays the other 50%.</div>
              <div class="mt-1.5 text-xs font-bold text-violet-600" id="open-price-label">Pay now: -- PKR (50%)</div>
            </div>
          </div>
        </div>

        <!-- Challenge a Specific Team -->
        <div class="choice-card" onclick="selectBookingType('team_challenge', this)">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-orange-100 flex items-center justify-center flex-shrink-0">
              <svg class="h-5 w-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div class="flex-1">
              <div class="font-bold text-slate-800 text-sm">Challenge a Specific Team</div>
              <div class="text-xs text-slate-500 mt-0.5">Search and invite a specific team. You each pay 50% to confirm.</div>
              <div class="mt-1.5 text-xs font-bold text-orange-600" id="team-price-label">Your share: -- PKR (50%)</div>
            </div>
          </div>
        </div>
      </div>

      <button onclick="proceedToStep2()"
              class="mt-5 w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md hover:shadow-lg disabled:opacity-40 disabled:cursor-not-allowed"
              id="step1-next-btn" disabled>
        Continue →
      </button>
    </div>

    <!-- Step 2a: Direct Booking Confirm -->
    <div id="step-2-direct" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-4 font-medium">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back
      </button>
      <h3 class="text-base font-bold text-slate-800 mb-4">Confirm Direct Booking</h3>
      <div class="bg-slate-50 rounded-xl p-4 mb-4 space-y-3 text-sm border border-slate-200">
        <div class="flex justify-between"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800" id="d-venue">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="d-date">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold text-slate-800" id="d-time">--</span></div>
        <div class="border-t border-slate-200 pt-3 flex justify-between">
          <span class="font-bold text-slate-700">Total Payment</span>
          <span class="font-extrabold text-emerald-600 text-base" id="d-price">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400">Wallet Balance</span>
          <span class="font-semibold text-slate-600" id="d-balance">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400">After Payment</span>
          <span class="font-semibold" id="d-after">-- PKR</span>
        </div>
      </div>
      <button onclick="submitBooking('direct')"
              class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md" id="direct-pay-btn">
        ✅ Confirm & Pay from Wallet
      </button>
    </div>

    <!-- Step 2b: Open Challenge Confirm -->
    <div id="step-2-open" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-4 font-medium">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back
      </button>
      <h3 class="text-base font-bold text-slate-800 mb-1">Post Open Challenge</h3>
      <p class="text-xs text-slate-500 mb-4">Your challenge will be visible to all players on the Explore page. When someone accepts, their 50% confirms the booking.</p>
      <div class="bg-violet-50 rounded-xl p-4 mb-4 space-y-3 text-sm border border-violet-200">
        <div class="flex justify-between"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800" id="oc-venue">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="oc-date">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold text-slate-800" id="oc-time">--</span></div>
        <div class="border-t border-violet-200 pt-3 flex justify-between">
          <span class="font-bold text-slate-700">You Pay Now (50%)</span>
          <span class="font-extrabold text-violet-700 text-base" id="oc-price">-- PKR</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400">Wallet Balance</span>
          <span class="font-semibold text-slate-600" id="oc-balance">-- PKR</span>
        </div>
        <div class="text-xs text-violet-600 bg-violet-100 rounded-lg p-2 mt-1">⚡ Opponent pays the remaining 50% to confirm the match.</div>
      </div>
      <button id="oc-pay-btn" onclick="submitBooking('open_challenge')"
              class="w-full bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md">
        ⚡ Pay 50% & Post Challenge
      </button>
    </div>

    <!-- Step 2c: Challenge Team – redirect info -->
    <div id="step-2-team" class="p-6 hidden">
      <button onclick="backToStep1()" class="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 mb-4 font-medium">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Back
      </button>
      <h3 class="text-base font-bold text-slate-800 mb-1">Challenge a Specific Team</h3>
      <p class="text-xs text-slate-500 mb-4">You'll be taken to the teams page with your slot pre-filled. Search a team, pay your 50%, and the invite will be sent.</p>
      <div class="bg-orange-50 rounded-xl p-4 mb-4 space-y-3 text-sm border border-orange-200">
        <div class="flex justify-between"><span class="text-slate-500">Venue</span><span class="font-semibold text-slate-800" id="tc-venue">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold text-slate-800" id="tc-date">--</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold text-slate-800" id="tc-time">--</span></div>
        <div class="border-t border-orange-200 pt-3 flex justify-between">
          <span class="font-bold text-slate-700">Your Share (50%)</span>
          <span class="font-extrabold text-orange-600 text-base" id="tc-price">-- PKR</span>
        </div>
      </div>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-700 mb-4">
        ⚠️ Your 5-min slot hold will be released when you navigate away. The slot reservation will be locked once you pay your 50% on the next page.
      </div>
      <button onclick="goToChallengeTeam()"
              class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold py-3 rounded-xl text-sm transition-all shadow-md">
        🏆 Select Team & Pay 50% →
      </button>
    </div>

  </div>
</div>

<script>
// ---- State ----
let modalData = {};
let countdownInterval = null;
let holdSeconds = 300;
let selectedType = null;

// ---- Slot Click (available + held by current user) ----
function clickSlot(el) {
  document.querySelectorAll('.slot-card').forEach(s => { if (s !== el) s.classList.remove('slot-selected'); });
  el.classList.add('slot-selected');

  const hour   = el.dataset.hour;
  const time   = el.dataset.time;
  const price  = parseFloat(el.dataset.price);
  const ground = el.dataset.ground;
  const date   = el.dataset.date;
  modalData = { hour, time, price, ground, date };

  // Place / refresh hold via AJAX
  fetch('hold_slot.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `ground_id=${ground}&slot_date=${date}&slot_hour=${hour}`
  })
  .then(r => r.json())
  .then(res => {
    if (!res.success) {
      showToast('\u274c ' + res.message, 'error');
      el.classList.remove('slot-selected');
      setTimeout(() => location.reload(), 1600);
      return;
    }
    holdSeconds = res.remaining || 300;
    openModal();
  })
  .catch(() => { showToast('\u274c Network error. Please try again.', 'error'); el.classList.remove('slot-selected'); });
}

// ---- Modal open / close ----
function openModal() {
  selectedType = null;
  document.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('step1-next-btn').disabled = true;

  // Ground name via data attribute (no em-dash splitting issues)
  const groundEl   = document.getElementById('ground-select');
  const groundName = groundEl ? groundEl.dataset.groundName : 'Venue';

  document.getElementById('modal-ground-name').textContent = groundName;
  document.getElementById('modal-slot-time').textContent   = modalData.time;
  document.getElementById('modal-date').textContent        = modalData.date;
  document.getElementById('modal-price').textContent       = formatNum(modalData.price);

  const half = Math.round(modalData.price * 0.5);
  document.getElementById('direct-price-label').textContent = 'Full price: ' + formatNum(modalData.price) + ' PKR';
  document.getElementById('open-price-label').textContent   = 'Pay now: ' + formatNum(half) + ' PKR (50%)';
  document.getElementById('team-price-label').textContent   = 'Your share: ' + formatNum(half) + ' PKR (50%)';

  showStep(1);
  document.getElementById('booking-modal-overlay').classList.add('open');
  startCountdown(holdSeconds);
}

function closeModal() {
  document.getElementById('booking-modal-overlay').classList.remove('open');
  if (countdownInterval) clearInterval(countdownInterval);
  document.querySelectorAll('.slot-card.slot-selected').forEach(s => s.classList.remove('slot-selected'));
}

// ---- Modal countdown ----
function startCountdown(seconds) {
  if (countdownInterval) clearInterval(countdownInterval);
  let remaining = seconds;
  const total   = seconds;
  updateCountdownUI(remaining, total);
  countdownInterval = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
      clearInterval(countdownInterval);
      showToast('\u23f0 Hold expired. Slot released.', 'info');
      closeModal();
      setTimeout(() => location.reload(), 1000);
      return;
    }
    updateCountdownUI(remaining, total);
  }, 1000);
}

function updateCountdownUI(remaining, total) {
  const m   = Math.floor(remaining / 60);
  const s   = String(remaining % 60).padStart(2, '0');
  const el  = document.getElementById('modal-countdown');
  if (el) el.textContent = m + ':' + s;
  const pct = (remaining / total) * 100;
  const bar = document.getElementById('modal-progress');
  if (!bar) return;
  bar.style.width      = pct + '%';
  bar.style.background = pct < 30 ? '#fca5a5' : (pct < 60 ? '#fde68a' : 'white');
}

// ---- Live slot-card hold countdowns (ticks every second) ----
(function initCardCountdowns() {
  const heldCards = document.querySelectorAll('.slot-card[data-hold-remaining]');
  if (!heldCards.length) return;
  setInterval(() => {
    heldCards.forEach(card => {
      let rem = parseInt(card.dataset.holdRemaining || '0');
      if (rem <= 0) return;
      rem--;
      card.dataset.holdRemaining = rem;
      const hour = card.dataset.hour;
      const fill = document.getElementById('fill-' + hour);
      const text = document.getElementById('hold-text-' + hour);
      if (fill) fill.style.width = Math.max(0, Math.round((rem / 300) * 100)) + '%';
      if (text) {
        if (rem <= 0) {
          text.textContent = 'Hold expired';
          setTimeout(() => location.reload(), 900);
        } else {
          const isOwn  = card.classList.contains('slot-held');
          const prefix = isOwn ? '\ud83d\udd35 Your hold \u2013 ' : '\u23f3 On hold \u2013 ';
          const m      = Math.floor(rem / 60);
          const s      = String(rem % 60).padStart(2, '0');
          text.textContent = prefix + m + ':' + s;
        }
      }
    });
  }, 1000);
})();

// ---- Step navigation ----
function showStep(n) {
  ['step-1','step-2-direct','step-2-open','step-2-team'].forEach(id => document.getElementById(id).classList.add('hidden'));
  ['dot-1','dot-2'].forEach(id => document.getElementById(id).classList.remove('active'));

  if (n === 1) {
    document.getElementById('step-1').classList.remove('hidden');
    document.getElementById('dot-1').classList.add('active');
    return;
  }

  document.getElementById('dot-1').classList.add('active');
  document.getElementById('dot-2').classList.add('active');

  const balance    = <?php echo $available_balance; ?>;
  const half       = Math.round(modalData.price * 0.5);
  const groundEl   = document.getElementById('ground-select');
  const groundName = groundEl ? groundEl.dataset.groundName : 'Venue';

  if (selectedType === 'direct') {
    document.getElementById('step-2-direct').classList.remove('hidden');
    document.getElementById('d-venue').textContent   = groundName;
    document.getElementById('d-date').textContent    = modalData.date;
    document.getElementById('d-time').textContent    = modalData.time;
    document.getElementById('d-price').textContent   = formatNum(modalData.price) + ' PKR';
    document.getElementById('d-balance').textContent = formatNum(balance) + ' PKR';
    const after   = balance - modalData.price;
    const afterEl = document.getElementById('d-after');
    afterEl.textContent = formatNum(after) + ' PKR';
    afterEl.className   = 'font-semibold ' + (after >= 0 ? 'text-emerald-600' : 'text-red-600');
    const payBtn = document.getElementById('direct-pay-btn');
    if (balance < modalData.price) {
      payBtn.disabled    = true;
      payBtn.textContent = '\u274c Insufficient Balance \u2013 Top Up Wallet';
      payBtn.className  += ' opacity-50 cursor-not-allowed';
    } else {
      payBtn.disabled    = false;
      payBtn.textContent = '\u2705 Confirm & Pay from Wallet';
    }
  } else if (selectedType === 'open_challenge') {
    document.getElementById('step-2-open').classList.remove('hidden');
    document.getElementById('oc-venue').textContent   = groundName;
    document.getElementById('oc-date').textContent    = modalData.date;
    document.getElementById('oc-time').textContent    = modalData.time;
    document.getElementById('oc-price').textContent   = formatNum(half) + ' PKR';
    document.getElementById('oc-balance').textContent = formatNum(balance) + ' PKR';
    const ocBtn = document.getElementById('oc-pay-btn');
    if (balance < half) {
      ocBtn.disabled    = true;
      ocBtn.textContent = '\u274c Insufficient Balance \u2013 Top Up Wallet';
      ocBtn.className  += ' opacity-50 cursor-not-allowed';
    } else {
      ocBtn.disabled    = false;
      ocBtn.textContent = '\u26a1 Pay 50% & Post Challenge';
    }
  } else {
    document.getElementById('step-2-team').classList.remove('hidden');
    document.getElementById('tc-venue').textContent = groundName;
    document.getElementById('tc-date').textContent  = modalData.date;
    document.getElementById('tc-time').textContent  = modalData.time;
    document.getElementById('tc-price').textContent = formatNum(half) + ' PKR';
  }
}

function selectBookingType(type, el) {
  selectedType = type;
  document.querySelectorAll('.choice-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('step1-next-btn').disabled = false;
}
function proceedToStep2() { if (!selectedType) return; showStep(2); }
function backToStep1()    { showStep(1); }

// ---- Submit booking ----
function submitBooking(type) {
  const btnId = type === 'direct' ? 'direct-pay-btn' : 'oc-pay-btn';
  const btn   = document.getElementById(btnId);
  if (btn) { btn.disabled = true; btn.textContent = 'Processing\u2026'; }

  fetch('process_booking.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ ground_id: modalData.ground, slot_date: modalData.date, slot_hour: modalData.hour, booking_type: type })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      closeModal();
      showToast(res.message, 'success');
      setTimeout(() => location.reload(), 2200);
    } else {
      showToast('\u274c ' + res.message, 'error');
      if (btn) {
        btn.disabled    = false;
        btn.textContent = type === 'direct' ? '\u2705 Confirm & Pay from Wallet' : '\u26a1 Pay 50% & Post Challenge';
      }
    }
  })
  .catch(() => { showToast('\u274c Network error.', 'error'); if (btn) btn.disabled = false; });
}

// ---- Go to challenge team page ----
function goToChallengeTeam() {
  const half = Math.round(modalData.price * 0.5);
  window.location.href = 'challenge_team.php?ground_id=' + modalData.ground + '&date=' + modalData.date + '&hour=' + modalData.hour + '&price=' + modalData.price + '&half=' + half;
}

// ---- Toast ----
function showToast(message, type) {
  type = type || 'info';
  const toast     = document.getElementById('toast');
  toast.textContent = message;
  toast.className   = 'show ' + type;
  setTimeout(() => { toast.className = toast.className.replace('show', '').trim(); }, 4500);
}

function formatNum(n) { return Math.round(n).toLocaleString('en-PK'); }

document.getElementById('booking-modal-overlay').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
