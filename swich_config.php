<?php
/**
 * swich_config.php
 * Configuration loader for Swich Payment Gateway (PayIn API & PWA).
 * Strictly conforms to official Swich 2026 Integration Guide.
 */

function get_swich_config(): array {
    $local = [];
    $configFile = __DIR__ . '/config.local.php';
    if (file_exists($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded) && isset($loaded['swich']) && is_array($loaded['swich'])) {
            $local = $loaded['swich'];
        }
    }

    // Credentials provided for ArenaReserve - PayIN - Live
    $clientId     = getenv('SWICH_CLIENT_ID')     ?: ($local['client_id']     ?? '432fffd2ac934cd6838346e212c3350b');
    $clientSecret = getenv('SWICH_CLIENT_SECRET') ?: ($local['client_secret'] ?? 'f09438ea1ab74c34ae136e1e91742bcc5ce7dd5456f0417f8c2f0d2b58551831');
    $secretKey    = getenv('SWICH_SECRET_KEY')    ?: ($local['secret_key']    ?? 'F23FC25C30330116');

    // Official Production Endpoints
    $pwaUrl     = getenv('SWICH_PWA_URL')      ?: ($local['pwa_url']      ?? 'https://payin-pwa.swichnow.com/');
    $authUrl    = getenv('SWICH_AUTH_URL')     ?: ($local['auth_url']     ?? 'https://auth.swichnow.com/connect/token');
    $apiBaseUrl = getenv('SWICH_API_BASE_URL') ?: ($local['api_base_url'] ?? 'https://api.swichnow.com');

    $appUrl = get_swich_app_base_url();
    $returnUrl   = getenv('SWICH_RETURN_URL')   ?: ($local['return_url']   ?? ($appUrl . '/swich_return.php'));
    $callbackUrl = getenv('SWICH_CALLBACK_URL') ?: ($local['callback_url'] ?? ($appUrl . '/swich_ipn.php'));

    return [
        'client_id'     => trim($clientId),
        'client_secret' => trim($clientSecret),
        'secret_key'    => trim($secretKey),
        'pwa_url'       => rtrim(trim($pwaUrl), '/') . '/',
        'auth_url'      => trim($authUrl),
        'api_base_url'  => rtrim(trim($apiBaseUrl), '/'),
        'return_url'    => trim($returnUrl),
        'callback_url'  => trim($callbackUrl),
    ];
}

/**
 * Dynamic resolution of the application base URL
 */
function get_swich_app_base_url(): string {
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
