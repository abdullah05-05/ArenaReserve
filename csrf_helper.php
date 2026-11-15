<?php
/**
 * csrf_helper.php — CSRF protection for ArenaReserve
 * Include this file in any page that renders a form or handles POST requests.
 */

if (!function_exists('csrf_token')) {
    /**
     * Get or generate CSRF token for current session
     */
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Output hidden CSRF input field
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Verify CSRF token from POST data
     * Returns true if valid, false otherwise
     */
    function csrf_verify(?string $token = null): bool
    {
        $submitted = $token ?? ($_POST['csrf_token'] ?? '');
        $expected  = $_SESSION['csrf_token'] ?? '';

        if (empty($submitted) || empty($expected)) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}

if (!function_exists('csrf_require')) {
    /**
     * Require valid CSRF token - exit with 403 if invalid
     * Use at top of POST handlers
     */
    function csrf_require(): void
    {
        if (!csrf_verify()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Invalid CSRF token. Please refresh the page and try again.'
            ]);
            exit;
        }
    }
}

if (!function_exists('csrf_meta')) {
    /**
     * Output meta tag for AJAX requests to read
     */
    function csrf_meta(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}