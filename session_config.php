<?php
/**
 * session_config.php — Secure session configuration
 * Include this file BEFORE session_start() in every PHP file that uses sessions.
 *
 * Usage: require_once 'session_config.php'; session_start();
 */

// Detect HTTPS
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

// Session cookie parameters
$cookieParams = [
    'lifetime' => 0,           // Session cookie (expires on browser close)
    'path' => '/',             // Available on all paths
    'domain' => '',            // Current domain only
    'secure' => $isHttps,      // Only transmit over HTTPS in production
    'httponly' => true,        // Prevent JavaScript access (XSS protection)
    'samesite' => 'Lax',       // CSRF protection (Lax allows normal navigation)
];

session_set_cookie_params($cookieParams);

// Security settings
ini_set('session.use_strict_mode', '1');      // Reject uninitialized session IDs
ini_set('session.use_only_cookies', '1');     // Prevent session ID in URL
ini_set('session.cookie_httponly', '1');      // Redundant but explicit
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

// Optional: Regenerate session ID periodically for additional security
// ini_set('session.gc_maxlifetime', 1440);    // 24 minutes default
// ini_set('session.gc_probability', 1);
// ini_set('session.gc_divisor', 100);