<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['current_role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

$success_msg = '';
$error_msg   = '';

/* ─── Auto-add new columns if missing ─── */
try { $pdo->exec("ALTER TABLE onboarding_packages ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE grounds ADD COLUMN IF NOT EXISTS ground_status ENUM('Active','Suspended','Blocked') NOT NULL DEFAULT 'Active'"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE grounds ADD COLUMN IF NOT EXISTS block_reason TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE wallet_deposit_requests ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL"); } catch(Exception $e) {}

/* ═══════════════════════════════
   POST HANDLERS
═══════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action    = $_POST['action'];
    $target_id = intval($_POST['target_id'] ?? 0);

    try {
        /* ── Approve venue ── */
        if ($action === 'approve_ground') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE grounds SET is_verified=1, ground_status='Active', block_reason=NULL WHERE id=?")->execute([$target_id]);
            $pdo->prepare("UPDATE onboarding_packages SET approval_status='Approved', rejection_reason=NULL WHERE ground_id=?")->execute([$target_id]);
            $pdo->commit();
            $success_msg = 'Venue approved and published live!';
        }

        /* ── Reject venue with reason ── */
        elseif ($action === 'reject_ground') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') { $error_msg = 'Please provide a rejection reason.'; }
            else {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE grounds SET is_verified=2 WHERE id=?")->execute([$target_id]);
                $pdo->prepare("UPDATE onboarding_packages SET approval_status='Rejected', rejection_reason=? WHERE ground_id=?")->execute([$reason, $target_id]);
                $pdo->commit();
                $success_msg = 'Venue rejected. Reason saved and visible to the owner.';
            }
        }

        /* ── Block venue with reason (removes from site) ── */
        elseif ($action === 'block_ground') {
            $reason = trim($_POST['block_reason'] ?? '');
            if ($reason === '') { $error_msg = 'Please provide a block reason.'; }
            else {
                $pdo->prepare("UPDATE grounds SET ground_status='Blocked', block_reason=? WHERE id=?")->execute([$reason, $target_id]);
                $success_msg = 'Venue blocked and removed from public site. Owner has been notified.';
            }
        }

        /* ── Suspend venue ── */
        elseif ($action === 'suspend_ground') {
            $pdo->prepare("UPDATE grounds SET ground_status='Suspended', block_reason=NULL WHERE id=?")->execute([$target_id]);
            $success_msg = 'Venue suspended.';
        }

        /* ── Activate venue ── */
        elseif ($action === 'activate_ground') {
            $pdo->prepare("UPDATE grounds SET ground_status='Active', block_reason=NULL WHERE id=?")->execute([$target_id]);
            $success_msg = 'Venue re-activated.';
        }

        /* ── Approve wallet deposit ── */
        elseif ($action === 'approve_deposit') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM wallet_deposit_requests WHERE id=? FOR UPDATE");
            $stmt->execute([$target_id]);
            $req = $stmt->fetch();
            if ($req && $req['status'] === 'Pending') {
                $pdo->prepare("UPDATE wallet_deposit_requests SET status='Approved' WHERE id=?")->execute([$target_id]);
                $stmt = $pdo->prepare("SELECT id, available_balance FROM wallets WHERE user_id=? FOR UPDATE");
                $stmt->execute([$req['player_id']]);
                $wallet = $stmt->fetch();
                if ($wallet) {
                    $pdo->prepare("UPDATE wallets SET available_balance=? WHERE id=?")->execute([$wallet['available_balance'] + $req['amount'], $wallet['id']]);
                    $wid = $wallet['id'];
                } else {
                    $pdo->prepare("INSERT INTO wallets (user_id, available_balance) VALUES (?,?)")->execute([$req['player_id'], $req['amount']]);
                    $wid = $pdo->lastInsertId();
                }
                $pdo->prepare("INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id) VALUES (?,?,'Deposit',?)")
                    ->execute([$wid, $req['amount'], $req['reference_details']]);
                $pdo->commit();
                $success_msg = 'Deposit approved and wallet credited!';
            } else {
                $pdo->rollBack();
                $error_msg = 'Already processed or not found.';
            }
        }

        /* ── Reject wallet deposit with reason ── */
        elseif ($action === 'reject_deposit') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($reason === '') { $error_msg = 'Please provide a rejection reason for the wallet request.'; }
            else {
                $pdo->prepare("UPDATE wallet_deposit_requests SET status='Rejected', rejection_reason=? WHERE id=?")->execute([$reason, $target_id]);
                $success_msg = 'Wallet request rejected. Reason saved and visible to player.';
            }
        }

        /* ── Suspend / Activate user ── */
        elseif ($action === 'set_user_status') {
            $ns = in_array($_POST['new_status'] ?? '', ['Active','Suspended']) ? $_POST['new_status'] : 'Active';
            $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$ns, $target_id]);
            $success_msg = "User status updated to $ns.";
        }

        /* ── Update platform fee ── */
        elseif ($action === 'update_fee') {
            $_SESSION['platform_fee'] = floatval($_POST['fee'] ?? 5);
            $success_msg = "Platform fee updated to {$_SESSION['platform_fee']}%.";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = 'Error: ' . $e->getMessage();
    }
}

/* ═══════════════════════════════
   FETCH DATA
═══════════════════════════════ */
// Pending venues (full details)
try {
    $pending_grounds = $pdo->query(
        "SELECT g.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
                op.verification_method, op.legal_docs_path, op.security_fee_receipt, op.rejection_reason
         FROM grounds g
         JOIN onboarding_packages op ON g.id = op.ground_id
         JOIN users u ON g.owner_id = u.id
         WHERE g.is_verified = 0 ORDER BY g.created_at ASC"
    )->fetchAll();
} catch (Exception $e) { $pending_grounds = []; }

// Approved venues
try {
    $approved_grounds = $pdo->query(
        "SELECT g.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
                COALESCE(g.ground_status,'Active') AS ground_status,
                COALESCE(g.block_reason,'') AS block_reason
         FROM grounds g
         JOIN users u ON g.owner_id = u.id
         WHERE g.is_verified = 1 ORDER BY g.created_at DESC"
    )->fetchAll();
} catch (Exception $e) { $approved_grounds = []; }

// Pending deposits
try {
    $pending_deposits = $pdo->query(
        "SELECT wdr.*, u.name AS player_name, u.email AS player_email
         FROM wallet_deposit_requests wdr
         JOIN users u ON wdr.player_id = u.id
         WHERE wdr.status = 'Pending' ORDER BY wdr.created_at ASC"
    )->fetchAll();
} catch (Exception $e) { $pending_deposits = []; }

// All users
try {
    $all_users = $pdo->query(
        "SELECT id,name,email,current_role,status,city,created_at FROM users WHERE current_role != 'Admin' ORDER BY created_at DESC"
    )->fetchAll();
} catch (Exception $e) { $all_users = []; }

$total_users   = count($all_users);
$suspended_cnt = count(array_filter($all_users, fn($u) => $u['status'] === 'Suspended'));
$platform_fee  = $_SESSION['platform_fee'] ?? 5;
$active_page   = $_GET['page'] ?? 'compliance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ArenaReserve</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .tab-content  { display: none; }
        .tab-content.active { display: block; }
        .inline-form  { display: none; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">

<!-- ══════════ HEADER ══════════ -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between h-16 items-center">
        <div class="flex items-center gap-2">
            <svg class="h-7 w-7 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0112 20.055a11.952 11.952 0 01-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/>
            </svg>
            <span class="text-emerald-600 text-xl font-bold">ArenaReserve</span>
            <span class="ml-2 text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-full font-bold uppercase">Admin</span>
        </div>
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm">A</div>
            <span class="text-xs font-semibold text-slate-700 hidden md:block">Super Admin</span>
            <a href="logout.php" class="text-xs text-red-500 hover:text-red-700 font-medium">Logout</a>
        </div>
    </div>
</header>

<div class="flex-1 flex max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6">

    <!-- ══════════ SIDEBAR ══════════ -->
    <aside class="hidden lg:block w-52 flex-shrink-0">
        <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm">
            <a href="admin_dashboard.php?page=compliance"
               class="<?php echo $active_page==='compliance'?'bg-emerald-50 text-emerald-700 font-semibold':'text-slate-600 hover:bg-slate-50'; ?> flex items-center px-3 py-2.5 text-sm rounded-lg transition-colors">
                <svg class="mr-3 h-5 w-5 <?php echo $active_page==='compliance'?'text-emerald-600':'text-slate-400'; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Compliance Panel
            </a>
            <a href="admin_dashboard.php?page=system"
               class="<?php echo $active_page==='system'?'bg-emerald-50 text-emerald-700 font-semibold':'text-slate-600 hover:bg-slate-50'; ?> flex items-center px-3 py-2.5 text-sm rounded-lg transition-colors">
                <svg class="mr-3 h-5 w-5 <?php echo $active_page==='system'?'text-emerald-600':'text-slate-400'; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                </svg>
                System Config
            </a>
        </nav>
    </aside>

    <!-- ══════════ MAIN ══════════ -->
    <main class="flex-1 min-w-0">

        <?php if (!empty($success_msg)): ?>
            <div class="mb-5 bg-green-50 border-l-4 border-green-500 p-4 text-sm text-green-700 rounded-r-lg flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="mb-5 bg-red-50 border-l-4 border-red-500 p-4 text-sm text-red-700 rounded-r-lg flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($active_page === 'system'): ?>
        <!-- ══════════════════════════════
             SYSTEM CONFIGURATION
        ══════════════════════════════ -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900">System Configuration</h1>
            <p class="text-sm text-slate-500 mt-1">Manage platform settings and user accounts</p>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <div><div class="text-xs text-slate-500 font-medium">Total Users</div><div class="text-3xl font-bold text-slate-900"><?php echo $total_users; ?></div></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-amber-50 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                </div>
                <div><div class="text-xs text-slate-500 font-medium">Suspended</div><div class="text-3xl font-bold text-slate-900"><?php echo $suspended_cnt; ?></div></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div><div class="text-xs text-slate-500 font-medium">Platform Fee</div><div class="text-3xl font-bold text-slate-900"><?php echo $platform_fee; ?>%</div></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Platform Config -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h2 class="text-base font-bold text-slate-900 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Platform Configuration
                </h2>
                <form method="POST" action="admin_dashboard.php?page=system">
                    <input type="hidden" name="action" value="update_fee">
                    <label class="text-xs font-semibold text-slate-500 block mb-1">Service Commission Fee (%)</label>
                    <div class="flex gap-3 mb-3">
                        <input type="number" name="fee" value="<?php echo $platform_fee; ?>" min="0" max="100" step="0.1"
                               class="flex-1 border border-slate-200 rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-400">
                        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm px-5 py-2.5 rounded-lg transition-colors">Update</button>
                    </div>
                    <p class="text-xs text-slate-400">Deducted from all bookings and earnings on the platform.</p>
                </form>
                <div class="mt-5 bg-slate-50 rounded-lg p-4 border border-slate-100">
                    <div class="text-xs font-bold text-slate-700 mb-3">Example Calculation</div>
                    <div class="space-y-1.5 text-xs text-slate-600">
                        <div class="flex justify-between"><span>Booking Amount:</span><span class="font-semibold text-slate-800">3,000 PKR</span></div>
                        <div class="flex justify-between"><span>Platform Fee (<?php echo $platform_fee; ?>%):</span><span class="font-semibold text-slate-800"><?php echo number_format(3000*$platform_fee/100,0); ?> PKR</span></div>
                        <div class="flex justify-between border-t border-slate-200 pt-1.5 mt-1.5"><span class="font-bold text-slate-700">Venue Owner Receives:</span><span class="font-bold text-emerald-600"><?php echo number_format(3000-(3000*$platform_fee/100),0); ?> PKR</span></div>
                    </div>
                </div>
            </div>

            <!-- User Management -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
                <h2 class="text-base font-bold text-slate-900 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    User Management
                </h2>
                <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                    <?php foreach ($all_users as $user): ?>
                        <div class="flex items-center justify-between py-2.5 border-b border-slate-100 last:border-0">
                            <div>
                                <div class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($user['name']); ?></div>
                                <div class="text-xs text-slate-400"><?php echo htmlspecialchars($user['email']); ?></div>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-100 px-2 py-0.5 rounded font-bold"><?php echo $user['current_role']; ?></span>
                                    <span class="text-[10px] text-slate-400">Joined <?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span>
                                </div>
                            </div>
                            <form method="POST" action="admin_dashboard.php?page=system">
                                <input type="hidden" name="action" value="set_user_status">
                                <input type="hidden" name="target_id" value="<?php echo $user['id']; ?>">
                                <?php if ($user['status'] === 'Suspended'): ?>
                                    <input type="hidden" name="new_status" value="Active">
                                    <button type="submit" class="text-xs bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-3 py-1.5 rounded-lg">Activate</button>
                                <?php else: ?>
                                    <input type="hidden" name="new_status" value="Suspended">
                                    <button type="submit" class="text-xs bg-red-500 hover:bg-red-600 text-white font-bold px-3 py-1.5 rounded-lg flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636"/></svg>
                                        Suspend
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($all_users)): ?><p class="text-sm text-slate-400 text-center py-6">No users yet.</p><?php endif; ?>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ══════════════════════════════
             COMPLIANCE PANEL
        ══════════════════════════════ -->
        <div class="mb-0 pb-0">
            <h1 class="text-2xl font-bold text-slate-900">Compliance Panel</h1>
            <p class="text-sm text-slate-500 mt-1 mb-4">Review venue applications, manage approved venues &amp; wallet audits</p>

            <div class="flex gap-1 border-b border-slate-200 mb-6">
                <button onclick="switchTab('pending-tab')" id="btn-pending-tab"
                        class="tab-btn border-b-2 border-emerald-500 text-emerald-600 px-4 py-2.5 text-sm font-semibold -mb-px">
                    Pending Applications
                    <?php if (count($pending_grounds)): ?><span class="ml-1 bg-amber-100 text-amber-700 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo count($pending_grounds); ?></span><?php endif; ?>
                </button>
                <button onclick="switchTab('approved-tab')" id="btn-approved-tab"
                        class="tab-btn border-b-2 border-transparent text-slate-500 hover:text-slate-700 px-4 py-2.5 text-sm font-semibold -mb-px">
                    Approved Venues
                    <?php if (count($approved_grounds)): ?><span class="ml-1 bg-emerald-100 text-emerald-700 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo count($approved_grounds); ?></span><?php endif; ?>
                </button>
                <button onclick="switchTab('deposits-tab')" id="btn-deposits-tab"
                        class="tab-btn border-b-2 border-transparent text-slate-500 hover:text-slate-700 px-4 py-2.5 text-sm font-semibold -mb-px">
                    Wallet Audits
                    <?php if (count($pending_deposits)): ?><span class="ml-1 bg-amber-100 text-amber-700 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo count($pending_deposits); ?></span><?php endif; ?>
                </button>
            </div>
        </div>

        <!-- ── TAB 1: PENDING ── -->
        <div id="pending-tab" class="tab-content active space-y-5">
            <?php if (empty($pending_grounds)): ?>
                <div class="bg-white border border-slate-200 rounded-xl p-14 text-center">
                    <svg class="w-12 h-12 text-slate-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <h3 class="font-semibold text-slate-700">No pending applications</h3>
                    <p class="text-sm text-slate-400 mt-1">All venue applications have been reviewed.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending_grounds as $v): ?>
                    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
                        <div class="flex flex-col md:flex-row">
                            <!-- Photo -->
                            <div class="w-full md:w-60 h-48 md:h-auto bg-slate-100 flex-shrink-0 relative">
                                <?php if (!empty($v['image_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($v['image_path']); ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center text-slate-300">
                                        <svg class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                <?php endif; ?>
                                <span class="absolute top-2 left-2 text-[10px] bg-amber-100 text-amber-700 border border-amber-200 px-2 py-0.5 rounded font-bold uppercase">Pending</span>
                            </div>

                            <!-- Full Details -->
                            <div class="p-5 flex-1 flex flex-col justify-between">
                                <div>
                                    <!-- Header row -->
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <h3 class="font-bold text-slate-900 text-base"><?php echo htmlspecialchars($v['title']); ?></h3>
                                            <p class="text-xs text-slate-400 mt-0.5"><?php echo htmlspecialchars($v['address']); ?></p>
                                        </div>
                                        <span class="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-100 px-2.5 py-1 rounded font-bold uppercase"><?php echo htmlspecialchars($v['sport_type']); ?></span>
                                    </div>

                                    <!-- All details grid -->
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2 mb-3">
                                        <div>
                                            <span class="text-[10px] text-slate-400 font-semibold uppercase">Owner</span>
                                            <div class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($v['owner_name']); ?></div>
                                            <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($v['owner_email']); ?></div>
                                            <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($v['owner_phone']); ?></div>
                                        </div>
                                        <div>
                                            <span class="text-[10px] text-slate-400 font-semibold uppercase">Pricing</span>
                                            <div class="text-xs font-bold text-slate-800">Base: <?php echo number_format($v['base_price']); ?> PKR/hr</div>
                                            <div class="text-[10px] text-slate-400">Peak: <?php echo number_format($v['peak_price']); ?> PKR/hr</div>
                                        </div>
                                        <div>
                                            <span class="text-[10px] text-slate-400 font-semibold uppercase">Verification</span>
                                            <div class="text-xs font-bold text-slate-800"><?php echo $v['verification_method']==='StampPaper'?'Legal Stamp Paper':'Security Deposit'; ?></div>
                                            <div class="text-[10px] text-slate-400">Submitted <?php echo date('M d, Y', strtotime($v['created_at'])); ?></div>
                                        </div>
                                        <?php if (!empty($v['latitude'])): ?>
                                        <div class="col-span-2 md:col-span-3">
                                            <span class="text-[10px] text-slate-400 font-semibold uppercase">Coordinates</span>
                                            <div class="text-xs text-slate-700 font-mono"><?php echo $v['latitude']; ?>, <?php echo $v['longitude']; ?>
                                                <a href="https://www.google.com/maps?q=<?php echo $v['latitude'].','.$v['longitude']; ?>" target="_blank" class="text-emerald-600 ml-2 underline">View Map ↗</a>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Document link -->
                                    <?php $doc = $v['verification_method']==='StampPaper' ? $v['legal_docs_path'] : $v['security_fee_receipt']; ?>
                                    <?php if (!empty($doc)): ?>
                                    <a href="<?php echo htmlspecialchars($doc); ?>" target="_blank"
                                       class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 hover:text-emerald-800 bg-emerald-50 border border-emerald-100 px-3 py-1.5 rounded-lg">
                                        📄 View Verification Document →
                                    </a>
                                    <?php endif; ?>
                                </div>

                                <!-- Action Buttons -->
                                <div class="mt-4 pt-4 border-t border-slate-100 space-y-3">
                                    <div class="flex justify-end gap-3">
                                        <button type="button" onclick="showInlineForm('reject-form-<?php echo $v['id']; ?>')"
                                                class="px-4 py-2 border border-red-200 text-red-600 text-xs font-bold rounded-lg hover:bg-red-50 transition-colors">
                                            ✕ Reject
                                        </button>
                                        <form method="POST" action="admin_dashboard.php?page=compliance" class="inline">
                                            <input type="hidden" name="action" value="approve_ground">
                                            <input type="hidden" name="target_id" value="<?php echo $v['id']; ?>">
                                            <button type="submit" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold rounded-lg shadow-sm transition-colors">
                                                ✓ Approve &amp; Publish
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Reject Form (hidden) -->
                                    <div id="reject-form-<?php echo $v['id']; ?>" class="inline-form bg-red-50 border border-red-200 rounded-xl p-4">
                                        <form method="POST" action="admin_dashboard.php?page=compliance">
                                            <input type="hidden" name="action" value="reject_ground">
                                            <input type="hidden" name="target_id" value="<?php echo $v['id']; ?>">
                                            <label class="text-xs font-bold text-red-700 block mb-2">
                                                Rejection Reason
                                                <span class="text-slate-400 font-normal ml-1">(shown to the venue owner)</span>
                                            </label>
                                            <textarea name="rejection_reason" rows="3" placeholder="e.g. Documents are incomplete or address doesn't match records..."
                                                      class="w-full text-xs border border-red-200 bg-white rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-400 text-slate-700 resize-none"></textarea>
                                            <div class="flex justify-end gap-2 mt-3">
                                                <button type="button" onclick="hideInlineForm('reject-form-<?php echo $v['id']; ?>')"
                                                        class="text-xs px-3 py-1.5 border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50">Cancel</button>
                                                <button type="submit" class="text-xs px-4 py-1.5 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg">
                                                    Submit Rejection
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ── TAB 2: APPROVED VENUES TABLE ── -->
        <div id="approved-tab" class="tab-content">
            <?php if (empty($approved_grounds)): ?>
                <div class="bg-white border border-slate-200 rounded-xl p-14 text-center">
                    <h3 class="font-semibold text-slate-700">No approved venues yet</h3>
                    <p class="text-sm text-slate-400 mt-1">Approve pending applications to see them here.</p>
                </div>
            <?php else: ?>
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-sm font-bold text-slate-800">Approved Venues — Status Management</h2>
                        <span class="text-xs text-slate-400"><?php echo count($approved_grounds); ?> venue(s)</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100 text-[11px] text-slate-500 uppercase font-semibold tracking-wide">
                                    <th class="px-5 py-3 text-left">Venue &amp; Details</th>
                                    <th class="px-4 py-3 text-left">Owner</th>
                                    <th class="px-4 py-3 text-left">Sport / Rate</th>
                                    <th class="px-4 py-3 text-center">Status</th>
                                    <th class="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($approved_grounds as $v): ?>
                                    <?php
                                        $gs = $v['ground_status'] ?? 'Active';
                                        $sc = ['Active'=>'bg-emerald-50 text-emerald-700 border-emerald-100','Suspended'=>'bg-amber-50 text-amber-700 border-amber-100','Blocked'=>'bg-red-50 text-red-700 border-red-100'][$gs] ?? 'bg-emerald-50 text-emerald-700 border-emerald-100';
                                    ?>
                                    <tr class="hover:bg-slate-50 transition-colors align-top" id="row-<?php echo $v['id']; ?>">
                                        <td class="px-5 py-4">
                                            <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($v['title']); ?></div>
                                            <div class="text-xs text-slate-400 mt-0.5 max-w-xs"><?php echo htmlspecialchars($v['address']); ?></div>
                                            <?php if (!empty($v['latitude'])): ?>
                                                <a href="https://www.google.com/maps?q=<?php echo $v['latitude'].','.$v['longitude']; ?>" target="_blank" class="text-[10px] text-emerald-600 underline">View on map ↗</a>
                                            <?php endif; ?>
                                            <?php if ($gs === 'Blocked' && !empty($v['block_reason'])): ?>
                                                <div class="mt-1 text-[10px] text-red-600 font-semibold bg-red-50 border border-red-100 rounded px-2 py-1">Block reason: <?php echo htmlspecialchars($v['block_reason']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="text-xs font-semibold text-slate-700"><?php echo htmlspecialchars($v['owner_name']); ?></div>
                                            <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($v['owner_email']); ?></div>
                                            <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($v['owner_phone']); ?></div>
                                        </td>
                                        <td class="px-4 py-4">
                                            <span class="text-[10px] bg-slate-100 text-slate-700 px-2 py-0.5 rounded font-bold uppercase"><?php echo htmlspecialchars($v['sport_type']); ?></span>
                                            <div class="text-xs text-slate-600 mt-1"><?php echo number_format($v['base_price']); ?> PKR/hr</div>
                                            <div class="text-[10px] text-slate-400">Peak: <?php echo number_format($v['peak_price']); ?></div>
                                        </td>
                                        <td class="px-4 py-4 text-center">
                                            <span class="text-[10px] border px-2.5 py-1 rounded-full font-bold <?php echo $sc; ?>"><?php echo $gs; ?></span>
                                        </td>
                                        <td class="px-4 py-4 text-right">
                                            <div class="flex flex-col items-end gap-1.5">
                                                <div class="flex gap-1.5">
                                                    <?php if ($gs !== 'Suspended'): ?>
                                                        <form method="POST" action="admin_dashboard.php?page=compliance">
                                                            <input type="hidden" name="action" value="suspend_ground">
                                                            <input type="hidden" name="target_id" value="<?php echo $v['id']; ?>">
                                                            <button type="submit" class="text-[11px] bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-700 font-bold px-3 py-1.5 rounded-lg transition-colors">Suspend</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($gs !== 'Blocked'): ?>
                                                        <button type="button" onclick="showInlineForm('block-form-<?php echo $v['id']; ?>')"
                                                                class="text-[11px] bg-red-50 hover:bg-red-100 border border-red-200 text-red-700 font-bold px-3 py-1.5 rounded-lg transition-colors">Block</button>
                                                    <?php endif; ?>
                                                    <?php if ($gs !== 'Active'): ?>
                                                        <form method="POST" action="admin_dashboard.php?page=compliance">
                                                            <input type="hidden" name="action" value="activate_ground">
                                                            <input type="hidden" name="target_id" value="<?php echo $v['id']; ?>">
                                                            <button type="submit" class="text-[11px] bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 font-bold px-3 py-1.5 rounded-lg transition-colors">Activate</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Block Reason Form (hidden) -->
                                                <div id="block-form-<?php echo $v['id']; ?>" class="inline-form w-64 text-left">
                                                    <div class="bg-red-50 border border-red-200 rounded-xl p-3 mt-1">
                                                        <form method="POST" action="admin_dashboard.php?page=compliance">
                                                            <input type="hidden" name="action" value="block_ground">
                                                            <input type="hidden" name="target_id" value="<?php echo $v['id']; ?>">
                                                            <label class="text-[10px] font-bold text-red-700 block mb-1">
                                                                Block Reason <span class="text-slate-400 font-normal">(shown to owner)</span>
                                                            </label>
                                                            <textarea name="block_reason" rows="2" required placeholder="Reason for blocking this venue..."
                                                                      class="w-full text-xs border border-red-200 bg-white rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-red-400 resize-none text-slate-700"></textarea>
                                                            <div class="flex gap-1.5 mt-2 justify-end">
                                                                <button type="button" onclick="hideInlineForm('block-form-<?php echo $v['id']; ?>')"
                                                                        class="text-[10px] px-2.5 py-1.5 border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50">Cancel</button>
                                                                <button type="submit" class="text-[10px] px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg">Block Venue</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── TAB 3: WALLET AUDITS ── -->
        <div id="deposits-tab" class="tab-content space-y-5">
            <?php if (empty($pending_deposits)): ?>
                <div class="bg-white border border-slate-200 rounded-xl p-14 text-center">
                    <h3 class="font-semibold text-slate-700">No pending wallet audits</h3>
                    <p class="text-sm text-slate-400 mt-1">All top-up requests are up to date.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending_deposits as $dep): ?>
                    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col md:flex-row">
                        <!-- Receipt Preview -->
                        <div class="w-full md:w-52 h-40 md:h-auto bg-slate-100 flex-shrink-0 flex items-center justify-center p-4">
                            <?php $ext = strtolower(pathinfo($dep['receipt_path'], PATHINFO_EXTENSION)); ?>
                            <?php if (in_array($ext, ['jpg','jpeg','png'])): ?>
                                <img src="<?php echo htmlspecialchars($dep['receipt_path']); ?>" class="w-full h-full object-contain">
                            <?php else: ?>
                                <div class="text-center text-slate-400">
                                    <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <span class="text-xs font-semibold mt-1 block">PDF</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Details & Actions -->
                        <div class="p-5 flex-1 flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <h3 class="font-bold text-slate-900 text-lg"><?php echo number_format($dep['amount'],0); ?> PKR</h3>
                                        <p class="text-xs text-slate-500">By: <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($dep['player_name']); ?></span> &mdash; <?php echo htmlspecialchars($dep['player_email']); ?></p>
                                    </div>
                                    <span class="text-[10px] bg-amber-50 text-amber-600 border border-amber-100 px-2.5 py-1 rounded font-bold uppercase">Pending Audit</span>
                                </div>
                                <div class="flex flex-wrap gap-6 text-xs mt-3">
                                    <span class="text-slate-400">Ref: <strong class="text-slate-700"><?php echo htmlspecialchars($dep['reference_details']); ?></strong></span>
                                    <span class="text-slate-400">Submitted: <strong class="text-slate-700"><?php echo date('M d, Y H:i', strtotime($dep['created_at'])); ?></strong></span>
                                </div>
                                <a href="<?php echo htmlspecialchars($dep['receipt_path']); ?>" target="_blank" class="inline-block mt-2 text-xs font-semibold text-emerald-600 hover:underline">📄 Open Full Receipt →</a>
                            </div>

                            <!-- Action Buttons -->
                            <div class="mt-4 pt-4 border-t border-slate-100 space-y-3">
                                <div class="flex gap-3 justify-end">
                                    <button type="button" onclick="showInlineForm('dep-reject-<?php echo $dep['id']; ?>')"
                                            class="px-4 py-2 border border-red-200 text-red-600 text-xs font-bold rounded-lg hover:bg-red-50 transition-colors">
                                        ✕ Reject
                                    </button>
                                    <form method="POST" action="admin_dashboard.php?page=compliance" class="inline">
                                        <input type="hidden" name="action" value="approve_deposit">
                                        <input type="hidden" name="target_id" value="<?php echo $dep['id']; ?>">
                                        <button type="submit" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-bold rounded-lg shadow-sm transition-colors">
                                            ✓ Confirm &amp; Credit Wallet
                                        </button>
                                    </form>
                                </div>

                                <!-- Reject Reason Form (hidden) -->
                                <div id="dep-reject-<?php echo $dep['id']; ?>" class="inline-form bg-red-50 border border-red-200 rounded-xl p-4">
                                    <form method="POST" action="admin_dashboard.php?page=compliance">
                                        <input type="hidden" name="action" value="reject_deposit">
                                        <input type="hidden" name="target_id" value="<?php echo $dep['id']; ?>">
                                        <label class="text-xs font-bold text-red-700 block mb-2">
                                            Rejection Reason
                                            <span class="text-slate-400 font-normal ml-1">(shown to the player in their wallet)</span>
                                        </label>
                                        <textarea name="rejection_reason" rows="2" placeholder="e.g. Reference number not found in bank records, receipt appears altered..."
                                                  class="w-full text-xs border border-red-200 bg-white rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-400 text-slate-700 resize-none"></textarea>
                                        <div class="flex justify-end gap-2 mt-3">
                                            <button type="button" onclick="hideInlineForm('dep-reject-<?php echo $dep['id']; ?>')"
                                                    class="text-xs px-3 py-1.5 border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50">Cancel</button>
                                            <button type="submit" class="text-xs px-4 py-1.5 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg">Submit Rejection</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </main>
</div>

<script>
/* ── Tab switching ── */
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('border-emerald-500', 'text-emerald-600');
        btn.classList.add('border-transparent', 'text-slate-500');
    });
    const btn = document.getElementById('btn-' + tabId);
    if (btn) { btn.classList.add('border-emerald-500','text-emerald-600'); btn.classList.remove('border-transparent','text-slate-500'); }
}

/* ── Show / hide inline reason forms ── */
function showInlineForm(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'block';
}
function hideInlineForm(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>
</body>
</html>
