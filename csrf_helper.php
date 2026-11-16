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
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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
     * Verify CSRF token from POST data, HTTP headers, or JSON body
     * Returns true if valid, false otherwise
     */
    function csrf_verify(?string $token = null): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 1. Explicit token argument
        $submitted = $token;

        // 2. Standard POST parameter
        if (empty($submitted) && isset($_POST['csrf_token'])) {
            $submitted = $_POST['csrf_token'];
        }

        // 3. HTTP Headers (X-CSRF-Token / X-XSRF-Token)
        if (empty($submitted)) {
            $submitted = $_SERVER['HTTP_X_CSRF_TOKEN']
                      ?? ($_SERVER['HTTP_X_XSRF_TOKEN']
                      ?? ($_SERVER['HTTP_CSRF_TOKEN']
                      ?? ''));
        }

        // 4. Raw JSON input body
        if (empty($submitted)) {
            $raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded['csrf_token'])) {
                    $submitted = $decoded['csrf_token'];
                }
            }
        }

        $expected = $_SESSION['csrf_token'] ?? '';

        if (empty($submitted) || empty($expected)) {
            return false;
        }

        return hash_equals($expected, (string)$submitted);
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