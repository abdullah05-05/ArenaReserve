<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$target_mode = ($_SESSION['current_active_mode'] === 'Player') ? 'Owner' : 'Player';

if ($target_mode === 'Owner') {
    // Check if the owner has any grounds registered (even if pending verification)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM grounds WHERE owner_id = ?");
        $stmt->execute([$user_id]);
        $has_grounds = $stmt->fetchColumn() > 0;

        if (!$has_grounds) {
            // No grounds onboarded yet, redirect to the onboarding wizard
            header("Location: add_ground.php");
            exit;
        }
    } catch (Exception $e) {
        // Fallback to onboarding if query fails
        header("Location: add_ground.php");
        exit;
    }
}

// Update database active mode
try {
    // Also upgrade current_role to Owner if it was Player and they are switching to Owner
    $new_role = $_SESSION['current_role'];
    if ($target_mode === 'Owner' && $_SESSION['current_role'] === 'Player') {
        $new_role = 'Owner';
    }

    $stmt = $pdo->prepare("UPDATE users SET `current_active_mode` = ?, `current_role` = ? WHERE id = ?");
    $stmt->execute([$target_mode, $new_role, $user_id]);

    $_SESSION['current_active_mode'] = $target_mode;
    $_SESSION['current_role'] = $new_role;

} catch (Exception $e) {
    // If update fails, still toggle in session
    $_SESSION['current_active_mode'] = $target_mode;
}

if ($target_mode === 'Owner') {
    header("Location: owner_dashboard.php");
} else {
    header("Location: explore.php");
}
exit;
?>
