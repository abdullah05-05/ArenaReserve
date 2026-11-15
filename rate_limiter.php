<?php
/**
 * rate_limiter.php — Simple file-based rate limiter
 * Usage: require_once 'rate_limiter.php'; rate_limit('login', 5, 60); // 5 req/min
 */

function rate_limit(string $action, int $maxRequests, int $windowSeconds): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = $action . ':' . $ip;

    $storageDir = __DIR__ . '/logs/ratelimit';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0755, true);
    }

    $file = $storageDir . '/' . md5($key) . '.json';
    $now = time();

    // Load existing data
    $data = ['requests' => []];
    if (file_exists($file)) {
        $json = @file_get_contents($file);
        if ($json) {
            $data = json_decode($json, true) ?: ['requests' => []];
        }
    }

    // Filter requests within window
    $data['requests'] = array_filter($data['requests'], fn($ts) => $now - $ts < $windowSeconds);

    // Check limit
    if (count($data['requests']) >= $maxRequests) {
        http_response_code(429);
        header('Content-Type: application/json');
        $retryAfter = $windowSeconds - ($now - min($data['requests']));
        header("Retry-After: $retryAfter");
        echo json_encode([
            'success' => false,
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter
        ]);
        exit;
    }

    // Record this request
    $data['requests'][] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

// Convenience wrappers for common endpoints
function rate_limit_login(): void {
    rate_limit('login', 5, 60); // 5 attempts per minute per IP
}

function rate_limit_forgot_password(): void {
    rate_limit('forgot_password', 3, 3600); // 3 attempts per hour per IP
}

function rate_limit_ipn(): void {
    rate_limit('ipn', 100, 60); // 100 IPN calls per minute (high for legitimate traffic)
}

function rate_limit_api(): void {
    rate_limit('api', 60, 60); // 60 API calls per minute per IP
}