<?php
session_start();
require_once 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success_msg = '';

// Initialize session-based matches for scores demo if not set
if (!isset($_SESSION['owner_matches'])) {
    $_SESSION['owner_matches'] = [
        [
            'id' => 1,
            'venue' => 'Champions Stadium A',
            'date_time' => '2026-05-30 06:00 PM',
            'sport' => 'Football',
            'team_a' => 'Thunder Warriors',
            'team_b' => 'Phoenix Strikers',
            'score_a' => null,
            'score_b' => null,
            'status' => 'Pending'
        ],
        [
            'id' => 2,
            'venue' => 'Elite Cricket Ground',
            'date_time' => '2026-05-29 05:00 PM',
            'sport' => 'Cricket',
            'team_a' => 'Lightning Squad',
            'team_b' => 'Eagle Shooters',
            'score_a' => 185,
            'score_b' => 178,
            'status' => 'Finalized'
        ]
    ];
}

// Handle score entry post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_score') {
    $match_id = intval($_POST['match_id'] ?? 0);
    $score_a = trim($_POST['score_a'] ?? '');
    $score_b = trim($_POST['score_b'] ?? '');

    if ($score_a !== '' && $score_b !== '') {
        foreach ($_SESSION['owner_matches'] as &$match) {
            if ($match['id'] === $match_id) {
                $match['score_a'] = intval($score_a);
                $match['score_b'] = intval($score_b);
                $match['status'] = 'Finalized';
                break;
            }
        }
        $success_msg = 'Score entered and match finalized successfully!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authoritative Score Entry - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">
    <!-- Top Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo -->
                <div class="flex-shrink-0 flex items-center gap-2">
                    <span class="text-emerald-600 text-2xl font-bold flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z" />
                        </svg>
                        ArenaReserve
                    </span>
                </div>

                <!-- Right Side Actions -->
                <div class="flex items-center gap-4">
                    <!-- Mode Toggle -->
                    <div class="flex items-center gap-2 bg-slate-100 p-1 rounded-lg border border-slate-200">
                        <a href="switch_role.php" class="text-xs font-medium px-2 py-1 text-slate-500 hover:text-slate-800 transition-colors">Player</a>
                        <span class="text-xs font-medium px-2 py-1 text-slate-500 bg-white rounded shadow-sm text-emerald-600 font-bold">Owner</span>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm">
                            <?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="hidden md:block text-left">
                            <div class="text-xs font-semibold text-slate-800"><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></div>
                            <div class="text-[10px] text-slate-400 capitalize">Owner</div>
                        </div>
                        <a href="logout.php" class="text-xs text-red-500 hover:text-red-700 ml-2 font-medium">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="flex-1 flex max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">
        <!-- Sidebar Navigation -->
        <aside class="hidden lg:block w-64 flex-shrink-0">
            <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
                <a href="owner_dashboard.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    My Venues
                </a>
                <a href="add_ground.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    List New Venue
                </a>
                <a href="owner_analytics.php" class="text-slate-600 hover:bg-slate-50 hover:text-slate-900 flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors">
                    <svg class="mr-3 h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Analytics & Wallet
                </a>
                <a href="owner_scores.php" class="bg-emerald-50 text-emerald-700 flex items-center px-3 py-2.5 text-sm font-semibold rounded-lg">
                    <svg class="mr-3 h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Score Entry
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 min-w-0">
            <!-- Alert Banner -->
            <?php if (!empty($success_msg)): ?>
                <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 text-sm text-green-700 rounded-r-lg shadow-sm">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-slate-900">Authoritative Score Entry</h1>
                <p class="text-sm text-slate-500 mt-1">Enter final scores for matches at your venues</p>
            </div>

            <!-- Matches List -->
            <div class="space-y-6 max-w-4xl">
                <?php foreach ($_SESSION['owner_matches'] as $match): ?>
                    <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="space-y-3">
                            <!-- Venue Details -->
                            <div class="flex items-center gap-2 text-slate-800">
                                <span class="text-emerald-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                </span>
                                <h3 class="font-bold text-slate-800 text-lg leading-snug"><?php echo htmlspecialchars($match['venue']); ?></h3>
                            </div>

                            <!-- Date / Time / Sport Tag -->
                            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500 font-medium">
                                <div class="flex items-center gap-1">
                                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span><?php echo $match['date_time']; ?></span>
                                </div>
                                <span class="bg-slate-100 text-slate-700 px-2.5 py-1 rounded font-semibold text-[10px] uppercase">
                                    <?php echo htmlspecialchars($match['sport']); ?>
                                </span>
                            </div>

                            <!-- Teams / Matchup Display -->
                            <div class="flex items-center gap-2.5 text-slate-700 font-medium text-sm">
                                <?php if ($match['status'] === 'Finalized'): ?>
                                    <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($match['team_a']); ?></span>
                                    <span class="bg-slate-100 text-slate-800 font-bold px-2 py-0.5 rounded text-xs"><?php echo $match['score_a']; ?></span>
                                    <span class="text-slate-400 font-normal">vs</span>
                                    <span class="bg-slate-100 text-slate-800 font-bold px-2 py-0.5 rounded text-xs"><?php echo $match['score_b']; ?></span>
                                    <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($match['team_b']); ?></span>
                                <?php else: ?>
                                    <span class="font-semibold text-slate-850"><?php echo htmlspecialchars($match['team_a']); ?></span>
                                    <span class="text-slate-400 font-normal">vs</span>
                                    <span class="font-semibold text-slate-850"><?php echo htmlspecialchars($match['team_b']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Action side -->
                        <div class="flex-shrink-0">
                            <?php if ($match['status'] === 'Finalized'): ?>
                                <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 px-4 py-2 rounded-lg text-xs font-bold border border-emerald-100 shadow-sm">
                                    <!-- Trophy or check icon -->
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Finalized
                                </span>
                            <?php else: ?>
                                <button onclick="openScoreModal(<?php echo $match['id']; ?>, '<?php echo htmlspecialchars($match['team_a']); ?>', '<?php echo htmlspecialchars($match['team_b']); ?>')" 
                                        class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold px-5 py-2.5 rounded-lg shadow-sm transition-colors">
                                    Enter Scores
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <!-- Score Entry Modal -->
    <div id="scoreModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-xl max-w-md w-full p-6 mx-4">
            <h3 class="text-base font-bold text-slate-950 mb-4 border-b border-slate-100 pb-3">Enter Authoritative Scores</h3>
            <form action="owner_scores.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_score">
                <input type="hidden" id="modal_match_id" name="match_id" value="">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="score_a" id="label_team_a" class="text-xs font-semibold text-slate-500 block mb-1">Team A Score</label>
                        <input type="number" id="score_a" name="score_a" required min="0" placeholder="0"
                               class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-800 font-semibold">
                    </div>
                    <div>
                        <label for="score_b" id="label_team_b" class="text-xs font-semibold text-slate-500 block mb-1">Team B Score</label>
                        <input type="number" id="score_b" name="score_b" required min="0" placeholder="0"
                               class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-800 font-semibold">
                    </div>
                </div>

                <div class="flex gap-3 justify-end mt-6">
                    <button type="button" onclick="closeScoreModal()" class="px-4 py-2 border border-slate-200 text-slate-600 rounded-lg text-xs font-semibold hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-xs font-bold shadow-sm">
                        Submit Final Scores
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openScoreModal(matchId, teamA, teamB) {
            document.getElementById('modal_match_id').value = matchId;
            document.getElementById('label_team_a').textContent = teamA + ' Score';
            document.getElementById('label_team_b').textContent = teamB + ' Score';
            document.getElementById('scoreModal').classList.remove('hidden');
        }

        function closeScoreModal() {
            document.getElementById('scoreModal').classList.add('hidden');
        }
    </script>
</body>
</html>
