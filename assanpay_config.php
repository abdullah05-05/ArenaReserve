<?php
/**
 * assanpay_config.php
 * Secure configuration loader for AssanPay Payment Gateway.
 * Credentials are read dynamically from environment variables or config.local.php.
 * NO SECRETS OR KEYS ARE HARDCODED HERE.
 */

function get_assanpay_config(): array {
    // 1. Check if config.local.php exists and load local settings if present
    $local_config = [];
    $configFile = __DIR__ . '/config.local.php';
    if (file_exists($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded) && isset($loaded['assanpay']) && is_array($loaded['assanpay'])) {
            $local_config = $loaded['assanpay'];
        }
    }

    // 2. Read from environment variables with fallback to config.local.php
    $baseUrl = getenv('ASSANPAY_BASE_URL') ?: ($local_config['base_url'] ?? 'https://lc-mrcs.assanpay.com');
    // Normalize base URL (trim trailing slash)
    $baseUrl = rtrim($baseUrl, '/');

    $apiKey = getenv('ASSANPAY_API_KEY') ?: ($local_config['api_key'] ?? '');
    $apiSecret = getenv('ASSANPAY_API_SECRET') ?: ($local_config['api_secret'] ?? '');
    $branchCode = getenv('ASSANPAY_BRANCH_CODE') ?: ($local_config['branch_code'] ?? '');
    $callbackIp = getenv('ASSANPAY_CALLBACK_IP') ?: ($local_config['callback_ip'] ?? '52.198.114.98');

    return [
        'base_url'    => $baseUrl,
        'api_key'     => trim($apiKey),
        'api_secret'  => trim($apiSecret),
        'branch_code' => trim($branchCode),
        'callback_ip' => trim($callbackIp),
    ];
}

/**
 * Returns the current application base URL (e.g. http://localhost/GHR/a1 or https://arenareserve.com)
 */
function get_app_base_url(): string {
    $envAppUrl = getenv('APP_URL');
    if (!empty($envAppUrl)) {
        return rtrim($envAppUrl, '/');
    }

    // Detect dynamically from current request if running in web context
    if (isset($_SERVER['HTTP_HOST'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $scriptDir = rtrim($scriptDir, '/');
        return $protocol . $host . $scriptDir;
    }

    return 'http://localhost/GHR/a1';
}
