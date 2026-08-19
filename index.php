<?php
session_start();
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['current_active_mode']) && $_SESSION['current_active_mode'] === 'Owner') {
        header("Location: owner_dashboard.php");
    } else if (isset($_SESSION['current_active_mode']) && $_SESSION['current_active_mode'] === 'Admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: explore.php");
    }
    exit;
}

header("Location: landing.php");
exit;