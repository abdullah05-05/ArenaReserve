<?php
/**
 * jazzcash_config.php
 * Configuration loader for JazzCash Payment Gateway.
 * Strictly implements official JazzCash 2026 standards.
 */

function get_jazzcash_config(): array {
    // 1. Load local config from config.local.php if available
    $local = [];
    $configFile = __DIR__ . '/config.local.php';
    if (file_exists($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded) && isset($loaded['jazzcash']) && is_array($loaded['jazzcash'])) {
            $local = $loaded['jazzcash'];
        }
    }

    $merchantId    = getenv('JAZZCASH_MERCHANT_ID') ?: ($local['merchant_id'] ?? 'MC990823');
    $password      = getenv('JAZZCASH_PASSWORD') ?: ($local['password'] ?? '');
    $integritySalt = getenv('JAZZCASH_INTEGRITY_SALT') ?: ($local['integrity_salt'] ?? '');
    $environment   = strtolower(getenv('JAZZCASH_ENVIRONMENT') ?: ($local['environment'] ?? 'sandbox'));

    // Official Endpoints (2026 Documentation)
    $postUrl = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/CustomerPortal/transactionmanagement/merchantform';
    $statusInquiryUrl = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/rest/payments/status/inquiry';
    $mwalletUrl = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v2/rest/payments/m-wallet';

    $appUrl = get_app_base_url();
    $returnUrl = getenv('JAZZCASH_RETURN_URL') ?: ($local['return_url'] ?? ($appUrl . '/jazzcash_return.php'));
    $ipnUrl = getenv('JAZZCASH_IPN_URL') ?: ($local['ipn_url'] ?? ($appUrl . '/jazzcash_ipn.php'));

    return [
        'merchant_id'        => trim($merchantId),
        'password'           => trim($password),
        'integrity_salt'     => trim($integritySalt),
        'environment'        => $environment,
        'post_url'           => $postUrl,
        'status_inquiry_url' => $statusInquiryUrl,
        'mwallet_url'        => $mwalletUrl,
        'return_url'         => $returnUrl,
        'ipn_url'            => $ipnUrl
    ];
}

/**
 * Dynamic resolution of the application base URL
 */
function get_app_base_url(): string {
    $envAppUrl = getenv('APP_URL');
    if (!empty($envAppUrl)) {
        return rtrim($envAppUrl, '/');
    }

    if (isset($_SERVER['HTTP_HOST'])) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

        $protocol = $isHttps ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $scriptDir = rtrim($scriptDir, '/');
        return $protocol . $host . $scriptDir;
    }

    return 'https://arenareserve.app';
}
