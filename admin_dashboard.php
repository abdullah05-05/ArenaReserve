<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';

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
try { $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('Active','Blocked','Suspended') NOT NULL DEFAULT 'Active'"); } catch(Exception $e) {}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

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
            $ns = in_array($_POST['new_status'] ?? '', ['Active','Suspended','Blocked']) ? $_POST['new_status'] : 'Active';
            $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$ns, $target_id]);
            $success_msg = "User status updated to $ns.";
        }

        /* ── Update platform fee ── */
        elseif ($action === 'update_fee') {
            $_SESSION['platform_fee'] = floatval($_POST['fee'] ?? 5);
            $success_msg = "Platform fee updated to {$_SESSION['platform_fee']}%.";
        }

        /* ── Upload website logo ── */
        elseif ($action === 'upload_logo') {
            if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                $upload_err_code = $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE;
                if ($upload_err_code === UPLOAD_ERR_INI_SIZE || $upload_err_code === UPLOAD_ERR_FORM_SIZE) {
                    throw new Exception('The uploaded picture exceeds the 2MB server limit.');
                }
                throw new Exception('No file uploaded or upload error occurred.');
            }
            
            $file = $_FILES['logo'];
            $filename = $file['name'];
            $tmp_name = $file['tmp_name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            $allowed_exts = ['svg', 'png', 'jpg', 'jpeg', 'webp'];
            if (!in_array($ext, $allowed_exts)) {
                throw new Exception('Invalid file extension. Only SVG, PNG, JPG, JPEG, and WEBP files are allowed.');
            }
            
            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);
            
            $allowed_mimes = [
                'image/svg+xml',
                'image/svg',
                'image/png',
                'image/jpeg',
                'image/pjpeg',
                'image/webp'
            ];
            if (!in_array($mime, $allowed_mimes)) {
                throw new Exception('Invalid file type. Only SVG, PNG, JPG, JPEG, and WEBP images are allowed.');
            }
            
            // Check file size (max 2MB)
            if ($file['size'] > 2 * 1024 * 1024) {
                throw new Exception('File is too large. Maximum allowed size is 2MB.');
            }
            
            // Ensure uploads/logo directory exists
            $upload_dir = __DIR__ . '/uploads/logo';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Delete old custom logo file if it exists
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'custom_logo'");
            $stmt->execute();
            $old_row = $stmt->fetch();
            if ($old_row && !empty($old_row['setting_value'])) {
                $old_file = __DIR__ . '/' . $old_row['setting_value'];
                if (file_exists($old_file)) {
                    @unlink($old_file);
                }
            }
            
            // Save new logo with a unique name to prevent cache issues
            $new_filename = 'logo_' . time() . '.' . $ext;
            $new_path = 'uploads/logo/' . $new_filename;
            
            if (!move_uploaded_file($tmp_name, __DIR__ . '/' . $new_path)) {
                throw new Exception('Failed to save uploaded file.');
            }
            
            // Save or update in database
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('custom_logo', ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$new_path, $new_path]);
            
            $success_msg = 'Logo updated successfully!';
        }
        
        /* ── Delete website logo ── */
        elseif ($action === 'delete_logo') {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'custom_logo'");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row && !empty($row['setting_value'])) {
                $file_path = __DIR__ . '/' . $row['setting_value'];
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
            
            $pdo->prepare("DELETE FROM settings WHERE setting_key = 'custom_logo'")->execute();
            $success_msg = 'Custom logo removed. Default ArenaReserve logo is now active.';
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

$platform_fee  = $_SESSION['platform_fee'] ?? 5;
$active_page   = $_GET['page'] ?? 'compliance';

// Real-time Dashboard Statistics
try {
    $stat_total_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(current_role, current_active_mode, 'Player') != 'Admin'")->fetchColumn();
    $stat_total_players = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(current_role, current_active_mode, 'Player') = 'Player'")->fetchColumn();
    $stat_total_owners = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(current_role, current_active_mode, 'Player') = 'Owner'")->fetchColumn();
    $stat_active_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active' AND COALESCE(current_role, current_active_mode, 'Player') != 'Admin'")->fetchColumn();
    $stat_blocked_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE (status = 'Blocked' OR status = 'Suspended') AND COALESCE(current_role, current_active_mode, 'Player') != 'Admin'")->fetchColumn();
} catch (Exception $e) {
    $stat_total_users = $stat_total_players = $stat_total_owners = $stat_active_users = $stat_blocked_users = 0;
}

// User Management filters and pagination logic
$search = trim($_GET['search'] ?? '');
$filter_role = trim($_GET['account_type'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$page = max(1, intval($_GET['p'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$params = [];
$where_clauses = ["COALESCE(current_role, current_active_mode, 'Player') != 'Admin'"];

if ($search !== '') {
    $where_clauses[] = "(name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter_role !== '') {
    $where_clauses[] = "COALESCE(current_role, current_active_mode, 'Player') = ?";
    $params[] = $filter_role;
}

if ($filter_status !== '') {
    $where_clauses[] = "status = ?";
    $params[] = $filter_status;
}

$where_sql = implode(" AND ", $where_clauses);

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where_sql");
    $count_stmt->execute($params);
    $total_matching_users = (int)$count_stmt->fetchColumn();
    $total_pages = ceil($total_matching_users / $limit);

    $users_stmt = $pdo->prepare("SELECT id, name, email, phone, city, current_role, current_active_mode, status, created_at, profile_picture FROM users WHERE $where_sql ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $users_stmt->execute($params);
    $users_list = $users_stmt->fetchAll();
} catch (Exception $e) {
    $total_matching_users = 0;
    $total_pages = 0;
    $users_list = [];
}
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
    <?php
    $page_description = 'ArenaReserve Admin Panel – Manage users, ground owners, bookings, wallet audits, and platform settings from the admin dashboard.';
    include 'logo_head.php';
    ?>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col">

<!-- ══════════ HEADER ══════════ -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between h-16 items-center">
        <div class="flex items-center gap-2">
            <!-- Hamburger button for Admin Mobile Menu -->
            <button type="button" onclick="toggleAdminSidebar()" class="lg:hidden text-slate-500 hover:text-slate-700 focus:outline-none p-1 rounded-md" title="Toggle Navigation">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <?php echo get_logo_markup('h-7 w-7 flex-shrink-0'); ?>
            <span class="text-emerald-600 text-[12px] sm:text-lg md:text-xl font-bold flex-shrink-0">ArenaReserve</span>
            <span class="ml-2 text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-full font-bold uppercase">Admin</span>
        </div>
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-sm">A</div>
            <span class="text-xs font-semibold text-slate-700 hidden md:block">Super Admin</span>
            <a href="logout.php" class="text-xs text-red-500 hover:text-red-700 font-medium">Logout</a>
        </div>
    </div>
</header>

<div class="flex-1 flex max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 gap-6 relative">

    <!-- Sidebar Backdrop Overlay -->
    <div id="adminSidebarBackdrop" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 transition-opacity duration-300 opacity-0 lg:hidden" onclick="toggleAdminSidebar()"></div>

    <!-- ══════════ SIDEBAR ══════════ -->
    <aside id="adminSidebar" class="fixed inset-y-0 left-0 w-64 bg-white z-50 shadow-2xl transform -translate-x-full transition-transform duration-300 ease-in-out flex flex-col lg:static lg:translate-x-0 lg:w-52 lg:z-0 lg:shadow-none lg:bg-transparent lg:flex-shrink-0">
        <!-- Close button on Mobile -->
        <div class="flex items-center justify-between p-4 border-b border-slate-100 lg:hidden bg-white flex-shrink-0">
            <div class="flex items-center gap-2">
                <?php echo get_logo_markup('h-6 w-6'); ?>
                <span class="text-emerald-600 text-lg font-bold">ArenaReserve</span>
            </div>
            <button onclick="toggleAdminSidebar()" class="text-slate-500 hover:text-slate-700 focus:outline-none">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <nav class="space-y-1 bg-white rounded-xl border border-slate-200 p-3 shadow-sm lg:block flex-1 overflow-y-auto m-0 lg:m-0">
            <a href="admin_dashboard.php?page=compliance"
               onclick="if(window.innerWidth < 1024) toggleAdminSidebar()"
               class="<?php echo $active_page==='compliance'?'bg-emerald-50 text-emerald-700 font-semibold':'text-slate-600 hover:bg-slate-50'; ?> flex items-center px-3 py-2.5 text-sm rounded-lg transition-colors">
                <svg class="mr-3 h-5 w-5 <?php echo $active_page==='compliance'?'text-emerald-600':'text-slate-400'; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Compliance Panel
            </a>
            <a href="admin_dashboard.php?page=users"
               onclick="if(window.innerWidth < 1024) toggleAdminSidebar()"
               class="<?php echo $active_page==='users'?'bg-emerald-50 text-emerald-700 font-semibold':'text-slate-600 hover:bg-slate-50'; ?> flex items-center px-3 py-2.5 text-sm rounded-lg transition-colors">
                <svg class="mr-3 h-5 w-5 <?php echo $active_page==='users'?'text-emerald-600':'text-slate-400'; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                User Management
            </a>
            <a href="admin_dashboard.php?page=system"
               onclick="if(window.innerWidth < 1024) toggleAdminSidebar()"
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

        <!-- Platform Config -->
        <div class="max-w-3xl">
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

            <!-- Logo Management Card -->
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 mt-6">
                <h2 class="text-base font-bold text-slate-900 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Logo Management
                </h2>
                
                <!-- Logo Status & Preview -->
                <div class="mb-5 flex flex-col sm:flex-row items-start sm:items-center gap-4 p-4 bg-slate-50 border border-slate-100 rounded-lg">
                    <div class="flex-shrink-0">
                        <div class="text-xs font-semibold text-slate-400 mb-2">Active Logo Preview</div>
                        <!-- Grid checkerboard pattern background for transparent logos -->
                        <div class="w-24 h-24 rounded-lg border border-slate-200 bg-white flex items-center justify-center p-2" 
                             style="background-image: radial-gradient(#e2e8f0 20%, transparent 20%), radial-gradient(#e2e8f0 20%, transparent 20%); background-size: 8px 8px; background-position: 0 0, 4px 4px;">
                            <?php echo get_logo_markup('h-full w-full object-contain'); ?>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-slate-400">Current Logo Status</div>
                        <div class="mt-1 flex items-center gap-2">
                            <?php if (has_custom_logo()): ?>
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">Custom Logo Active</span>
                            <?php else: ?>
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Default Logo Active</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">
                            The logo is displayed on the navbar, login/signup flows, and as the browser favicon. 
                            Supported formats: SVG, PNG, JPG, JPEG, WEBP. Max size: 2MB.
                        </p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-4 items-end">
                    <!-- Upload Form -->
                    <form method="POST" action="admin_dashboard.php?page=system" enctype="multipart/form-data" class="flex-1 w-full">
                        <input type="hidden" name="action" value="upload_logo">
                        <label class="text-xs font-semibold text-slate-500 block mb-1">Upload New Logo</label>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="file" name="logo" accept=".svg,.png,.jpg,.jpeg,.webp" required onchange="validateLogoSize(this)"
                                   class="flex-1 text-xs file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-[10px] file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 border border-slate-200 rounded-lg p-1 focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs px-4 py-2 rounded-lg transition-colors whitespace-nowrap">Upload</button>
                        </div>
                        <p id="logo-size-error" class="text-xs font-semibold text-red-500 mt-1.5 hidden"></p>
                    </form>

                    <!-- Revert Button -->
                    <?php if (has_custom_logo()): ?>
                        <form method="POST" action="admin_dashboard.php?page=system" onsubmit="return confirm('Are you sure you want to delete the custom logo and revert to the default ArenaReserve logo?');" class="w-full sm:w-auto">
                            <input type="hidden" name="action" value="delete_logo">
                            <button type="submit" class="w-full sm:w-auto bg-slate-100 hover:bg-red-50 hover:text-red-600 hover:border-red-200 text-slate-600 font-semibold text-xs px-4 py-2 rounded-lg border border-slate-200 transition-all">Revert to Default</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php elseif ($active_page === 'users'): ?>
        <!-- ══════════════════════════════
             USER MANAGEMENT PANEL
        ══════════════════════════════ -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900">User Management</h1>
            <p class="text-sm text-slate-500 mt-1">Search, filter, and manage platform user accounts</p>
        </div>

        <!-- Real-time Dashboard Statistics -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 font-semibold uppercase font-medium">Total Users</div>
                    <div class="text-xl font-bold text-slate-900"><?php echo $stat_total_users; ?></div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 font-semibold uppercase font-medium">Players</div>
                    <div class="text-xl font-bold text-slate-900"><?php echo $stat_total_players; ?></div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 font-semibold uppercase font-medium">Owners</div>
                    <div class="text-xl font-bold text-slate-900"><?php echo $stat_total_owners; ?></div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 font-semibold uppercase font-medium">Active</div>
                    <div class="text-xl font-bold text-slate-900"><?php echo $stat_active_users; ?></div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636" />
                    </svg>
                </div>
                <div>
                    <div class="text-[10px] text-slate-400 font-semibold uppercase font-medium">Blocked</div>
                    <div class="text-xl font-bold text-slate-900"><?php echo $stat_blocked_users; ?></div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Controls -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
            <form method="GET" action="admin_dashboard.php" class="flex flex-col md:flex-row gap-3">
                <input type="hidden" name="page" value="users">

                <!-- Search -->
                <div class="flex-1 relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </span>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or email..."
                           class="w-full pl-9 pr-3 py-2 text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>

                <!-- Filters -->
                <div class="flex flex-wrap gap-2">
                    <select name="account_type" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400">
                        <option value="">All Account Types</option>
                        <option value="Player" <?php echo $filter_role === 'Player' ? 'selected' : ''; ?>>Player</option>
                        <option value="Owner" <?php echo $filter_role === 'Owner' ? 'selected' : ''; ?>>Ground Owner</option>
                    </select>

                    <select name="status" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400">
                        <option value="">All Statuses</option>
                        <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Blocked" <?php echo $filter_status === 'Blocked' ? 'selected' : ''; ?>>Blocked</option>
                        <option value="Suspended" <?php echo $filter_status === 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>

                    <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-sm px-4 py-2 rounded-lg transition-colors">
                        Apply Filters
                    </button>
                    <?php if ($search !== '' || $filter_role !== '' || $filter_status !== ''): ?>
                        <a href="admin_dashboard.php?page=users" class="border border-slate-200 text-slate-500 hover:bg-slate-50 font-semibold text-sm px-4 py-2 rounded-lg transition-colors flex items-center justify-center">
                            Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-[11px] text-slate-500 uppercase font-semibold tracking-wide">
                            <th class="px-5 py-3 text-left">User</th>
                            <th class="px-4 py-3 text-left">Contact Info</th>
                            <th class="px-4 py-3 text-left">Location</th>
                            <th class="px-4 py-3 text-left">Account Type</th>
                            <th class="px-4 py-3 text-left">Reg Date</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($users_list as $u): ?>
                            <?php
                                $status = $u['status'];
                                $badge_class = 'bg-emerald-50 text-emerald-700 border-emerald-100';
                                if ($status === 'Blocked' || $status === 'Suspended') {
                                    $badge_class = 'bg-red-50 text-red-700 border-red-100';
                                }
                            ?>
                            <tr class="hover:bg-slate-50 transition-colors align-middle">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php if (!empty($u['profile_picture']) && file_exists(__DIR__ . '/' . $u['profile_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($u['profile_picture']); ?>" class="w-10 h-10 rounded-full object-cover border border-slate-200">
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 text-slate-500 flex items-center justify-center font-bold text-sm">
                                                <?php echo strtoupper(substr($u['name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-bold text-slate-800"><?php echo htmlspecialchars($u['name']); ?></div>
                                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($u['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-slate-700 text-xs font-medium">
                                    <?php echo htmlspecialchars($u['phone']); ?>
                                </td>
                                <td class="px-4 py-4 text-slate-600 text-xs">
                                    <?php echo htmlspecialchars($u['city']); ?>
                                </td>
                                <td class="px-4 py-4">
                                    <?php $display_role = $u['current_role'] ?? $u['current_active_mode'] ?? 'Player'; ?>
                                    <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded border <?php echo $display_role === 'Owner' ? 'bg-purple-50 text-purple-700 border-purple-100' : 'bg-blue-50 text-blue-700 border-blue-100'; ?>">
                                        <?php echo $display_role === 'Owner' ? 'Ground Owner' : 'Player'; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-slate-500 text-xs">
                                    <?php echo date('Y-m-d', strtotime($u['created_at'])); ?>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="text-[10px] border px-2.5 py-1 rounded-full font-bold <?php echo $badge_class; ?>">
                                        <?php echo $status; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <form method="POST" action="admin_dashboard.php?page=users" class="inline toggle-block-form">
                                        <input type="hidden" name="action" value="set_user_status">
                                        <input type="hidden" name="target_id" value="<?php echo $u['id']; ?>">
                                        <?php if ($status === 'Active'): ?>
                                            <input type="hidden" name="new_status" value="Blocked">
                                            <button type="button" onclick="confirmAction(event, '<?php echo htmlspecialchars(addslashes($u['name'])); ?>', 'Block')" class="text-xs bg-red-500 hover:bg-red-600 text-white font-bold px-3 py-1.5 rounded-lg shadow-sm transition-colors">
                                                Block
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="Active">
                                            <button type="button" onclick="confirmAction(event, '<?php echo htmlspecialchars(addslashes($u['name'])); ?>', 'Unblock')" class="text-xs bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-3 py-1.5 rounded-lg shadow-sm transition-colors">
                                                Unblock
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users_list)): ?>
                            <tr>
                                <td colspan="7" class="text-sm text-slate-400 text-center py-8">No users found matching your search.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between">
                    <div class="text-xs text-slate-500">
                        Showing page <span class="font-semibold text-slate-700"><?php echo $page; ?></span> of <span class="font-semibold text-slate-700"><?php echo $total_pages; ?></span> (<?php echo $total_matching_users; ?> total matching users)
                    </div>
                    <div class="flex gap-1.5">
                        <?php if ($page > 1): ?>
                            <a href="admin_dashboard.php?page=users&p=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&account_type=<?php echo urlencode($filter_role); ?>&status=<?php echo urlencode($filter_status); ?>"
                               class="px-3 py-1.5 border border-slate-200 rounded-lg text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50 transition-colors">&larr; Previous</a>
                        <?php endif; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="admin_dashboard.php?page=users&p=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&account_type=<?php echo urlencode($filter_role); ?>&status=<?php echo urlencode($filter_status); ?>"
                               class="px-3 py-1.5 border border-slate-200 rounded-lg text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50 transition-colors">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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
                                <?php if (!empty($v['image_path']) && file_exists(__DIR__ . '/' . $v['image_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($v['image_path']); ?>" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='assets/images/football.png';">
                                <?php else: ?>
                                    <img src="assets/images/football.png" class="w-full h-full object-cover opacity-80">
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

<!-- Premium Confirmation Modal -->
<div id="confirm-modal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-sm w-full shadow-2xl border border-slate-100 p-6 transform scale-95 opacity-0 transition-all duration-200 ease-out" id="confirm-modal-box">
        <div class="flex items-center gap-3.5 mb-4">
            <div id="confirm-modal-icon-bg" class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0">
                <svg id="confirm-modal-icon" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"></svg>
            </div>
            <div>
                <h3 id="confirm-modal-title" class="font-bold text-slate-900 text-lg">Confirm Action</h3>
                <p id="confirm-modal-body" class="text-xs text-slate-500 mt-0.5">Are you sure you want to proceed?</p>
            </div>
        </div>
        <div class="flex gap-3 justify-end mt-6">
            <button type="button" onclick="closeConfirmModal()" class="flex-1 py-2.5 border border-slate-200 text-slate-600 text-xs font-semibold rounded-xl hover:bg-slate-50 transition-colors">
                Cancel
            </button>
            <button type="button" id="confirm-modal-btn" class="flex-1 py-2.5 text-white text-xs font-bold rounded-xl shadow-sm transition-colors">
                Confirm
            </button>
        </div>
    </div>
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

/* ── Custom Confirmation Modal Logic ── */
let pendingFormToSubmit = null;

function confirmAction(event, name, actionType) {
    event.preventDefault();
    pendingFormToSubmit = event.target.closest('form');
    
    const modal = document.getElementById('confirm-modal');
    const modalBox = document.getElementById('confirm-modal-box');
    const title = document.getElementById('confirm-modal-title');
    const body = document.getElementById('confirm-modal-body');
    const btn = document.getElementById('confirm-modal-btn');
    const iconBg = document.getElementById('confirm-modal-icon-bg');
    const icon = document.getElementById('confirm-modal-icon');
    
    title.textContent = actionType + ' User';
    body.innerHTML = `Are you sure you want to <strong>${actionType.toLowerCase()}</strong> the user <strong>${name}</strong>?`;
    
    if (actionType === 'Block') {
        btn.textContent = 'Yes, Block User';
        btn.className = 'flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white text-xs font-bold rounded-xl shadow-sm transition-colors';
        iconBg.className = 'w-12 h-12 rounded-full bg-red-50 flex items-center justify-center flex-shrink-0';
        icon.className = 'w-6 h-6 text-red-600';
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />';
    } else {
        btn.textContent = 'Yes, Unblock User';
        btn.className = 'flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-sm transition-colors';
        iconBg.className = 'w-12 h-12 rounded-full bg-emerald-50 flex items-center justify-center flex-shrink-0';
        icon.className = 'w-6 h-6 text-emerald-600';
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />';
    }
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modalBox.classList.remove('scale-95', 'opacity-0');
        modalBox.classList.add('scale-100', 'opacity-100');
    }, 10);
    
    btn.onclick = function() {
        if (pendingFormToSubmit) {
            pendingFormToSubmit.submit();
        }
    };
}

function closeConfirmModal() {
    const modal = document.getElementById('confirm-modal');
    const modalBox = document.getElementById('confirm-modal-box');
    
    modalBox.classList.remove('scale-100', 'opacity-100');
    modalBox.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        pendingFormToSubmit = null;
    }, 200);
}

function toggleAdminSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const backdrop = document.getElementById('adminSidebarBackdrop');
    if (!sidebar || !backdrop) return;
    
    if (sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        sidebar.classList.add('translate-x-0');
        backdrop.classList.remove('hidden');
        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            backdrop.classList.add('opacity-100');
        }, 10);
    } else {
        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.remove('opacity-100');
        backdrop.classList.add('opacity-0');
        setTimeout(() => backdrop.classList.add('hidden'), 300);
    }
}

/* ── Validate logo file size (max 2MB) before upload ── */
function validateLogoSize(input) {
    const file = input.files[0];
    const errorDiv = document.getElementById('logo-size-error');
    if (file) {
        // Max size: 2MB (2,097,152 bytes)
        const maxSize = 2 * 1024 * 1024;
        if (file.size > maxSize) {
            errorDiv.textContent = '❌ Warning: The selected picture is ' + (file.size / (1024 * 1024)).toFixed(2) + 'MB, which is greater than the 2MB limit. Please select a smaller file.';
            errorDiv.classList.remove('hidden');
            input.value = ''; // Reset file input
        } else {
            errorDiv.classList.add('hidden');
        }
    } else {
        errorDiv.classList.add('hidden');
    }
}
</script>
</body>
</html>
