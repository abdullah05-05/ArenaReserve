<?php
// logo_helper.php - Helper file for dynamic logo fetching and styling

if (!isset($pdo)) {
    require_once __DIR__ . '/db.php';
}

/**
 * Returns the currently active logo relative URL.
 * Falls back to default logo if custom is not defined or not present on disk.
 */
function get_custom_logo_url() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'custom_logo'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['setting_value'])) {
            $logo_path = $row['setting_value'];
            // Check if file exists on disk
            if (file_exists(__DIR__ . '/' . $logo_path)) {
                return $logo_path;
            }
        }
    } catch (Exception $e) {
        // Safe fallback
    }
    return 'assets/images/logo-default.svg';
}

/**
 * Checks if a custom logo is active.
 */
function has_custom_logo() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'custom_logo'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['setting_value'])) {
            $logo_path = $row['setting_value'];
            if (file_exists(__DIR__ . '/' . $logo_path)) {
                return true;
            }
        }
    } catch (Exception $e) {}
    return false;
}

/**
 * Generates an image tag markup for the logo.
 * Sets w-auto and object-contain to preserve aspect ratio.
 * Automatically adapts and scales heights to make the logo look prominent on all devices.
 */
function get_logo_markup($class_attr = '') {
    $url = get_custom_logo_url();
    
    // Default optimized classes for standard headers/navbars:
    // Mobile height: h-8 (32px), Tablet/Desktop height: sm:h-10 (40px)
    $final_classes = 'h-8 sm:h-10 flex-shrink-0 object-contain w-auto max-w-[140px] sm:max-w-[200px]';
    
    if (!empty($class_attr)) {
        if (strpos($class_attr, 'h-8') !== false && strpos($class_attr, 'mr-1') !== false) {
            // Auth pages (login, signup, forgot password, reset password)
            // Mobile: h-10 (40px), Desktop: sm:h-12 (48px)
            $final_classes = 'h-10 sm:h-12 mr-2 inline-block object-contain w-auto max-w-[160px] sm:max-w-[220px] align-middle';
        } elseif (strpos($class_attr, 'h-6') !== false && strpos($class_attr, 'w-6') !== false) {
            // Admin mobile sidebar close area
            $final_classes = 'h-7 sm:h-8 object-contain w-auto max-w-[120px]';
        } elseif (strpos($class_attr, 'h-7') !== false && strpos($class_attr, 'w-7') !== false) {
            // Admin dashboard header top navbar
            $final_classes = 'h-8 sm:h-10 object-contain w-auto max-w-[140px] sm:max-w-[200px]';
        } else {
            // Standard headers/navbars (originally h-[18px] sm:h-7)
            $final_classes = 'h-8 sm:h-10 flex-shrink-0 object-contain w-auto max-w-[140px] sm:max-w-[200px]';
        }
    }
    
    return '<img src="' . htmlspecialchars($url) . '" class="' . $final_classes . ' flex-shrink-0" alt="ArenaReserve Logo">';
}

/**
 * Returns fully qualified absolute URL of the active logo.
 * Useful for Open Graph and search crawler metadata.
 */
function get_logo_absolute_url() {
    $logo_path = get_custom_logo_url();
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Determine subdirectory if any (e.g. /GHR/a1/)
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $dir = preg_replace('/[^\/]+\.php($|\?.*)/', '', $request_uri);
    if (substr($dir, -1) !== '/') {
        $dir .= '/';
    }
    
    return $protocol . '://' . $host . $dir . $logo_path;
}
?>
