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

    // Read Merchant Credentials from Azure Environment Variables or local config
    $merchantId = getenv('JAZZCASH_MERCHANT_ID')
        ?: getenv('JAZZCASH_MID')
        ?: ($local['merchant_id'] ?? 'MC990823');

    $password = getenv('JAZZCASH_PASSWORD')
        ?: getenv('JAZZCASH_PASS')
        ?: ($local['password'] ?? '');

    $integritySalt = getenv('JAZZCASH_INTEGRITY_SALT')
        ?: getenv('JAZZCASH_SALT')
        ?: ($local['integrity_salt'] ?? '');

    // Detect environment: Check explicit env var first
    $envVar = getenv('JAZZCASH_ENVIRONMENT');
    if (!empty($envVar)) {
        $environment = strtolower(trim($envVar));
    } elseif ($merchantId !== 'MC990823' && !empty($merchantId)) {
        // If live production Merchant ID is configured, automatically switch to production
        $environment = 'production';
    } else {
        $environment = strtolower($local['environment'] ?? 'sandbox');
    }

    // Official JazzCash Live Production & Go-Live Endpoints (2026 Standards)
    // Page Redirection (Hosted Checkout)
    $postUrl = getenv('JAZZCASH_POST_URL')
        ?: 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/CustomerPortal/transactionmanagement/merchantform';

    // Status Inquiry API (v1.0)
    $statusInquiryUrl = getenv('JAZZCASH_STATUS_INQUIRY_URL')
        ?: 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/rest/payments/status/inquiry';

    // MWallet API with CNIC (v2.0) - Standard & Recommended
    $mwalletUrl = getenv('JAZZCASH_MWALLET_URL')
        ?: 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v2/rest/payments/m-wallet';

    // MWallet API without CNIC (v1.1)
    $mwalletV1Url = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/rest/payments/m-wallet';

    // MWallet Linking Form Action URL (v4.0) & Token Payment URL
    $walletLinkingUrl = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/WalletLinkingPortal/wallet/LinkWallet';
    $walletPayTokenUrl = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v4/rest/payments/m-wallet';

    $appUrl = get_app_base_url();
    $returnUrl = getenv('JAZZCASH_RETURN_URL') ?: ($local['return_url'] ?? ($appUrl . '/jazzcash_return.php'));
    $ipnUrl = getenv('JAZZCASH_IPN_URL') ?: ($local['ipn_url'] ?? ($appUrl . '/jazzcash_ipn.php'));

    return [
        'merchant_id'          => trim($merchantId),
        'password'             => trim($password),
        'integrity_salt'       => trim($integritySalt),
        'environment'          => $environment,
        'post_url'             => $postUrl,
        'status_inquiry_url'   => $statusInquiryUrl,
        'mwallet_url'          => $mwalletUrl,
        'mwallet_v1_url'       => $mwalletV1Url,
        'wallet_linking_url'   => $walletLinkingUrl,
        'wallet_pay_token_url' => $walletPayTokenUrl,
        'return_url'           => $returnUrl,
        'ipn_url'              => $ipnUrl
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
