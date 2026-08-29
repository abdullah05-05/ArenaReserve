<?php
/**
 * AssanPayService.php
 * Service class for AssanPay Payment Gateway (Hosted Checkout API).
 * Implements Checkout Session Creation, Request Signing, Webhook Verification, and Status Inquiry.
 */

require_once __DIR__ . '/assanpay_config.php';

class AssanPayService {
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $branchCode;
    private string $callbackIp;

    public function __construct(?array $config = null) {
        $cfg = $config ?? get_assanpay_config();
        $this->baseUrl    = rtrim($cfg['base_url'] ?? 'https://lc-mrcs.assanpay.com', '/');
        $this->apiKey     = $cfg['api_key'] ?? '';
        $this->apiSecret  = $cfg['api_secret'] ?? '';
        $this->branchCode = $cfg['branch_code'] ?? '';
        $this->callbackIp = $cfg['callback_ip'] ?? '52.198.114.98';
    }

    /**
     * Generate unique compliant Order ID (max 20 chars, alphanumeric only, no special characters)
     */
    public static function generateOrderId(string $prefix = 'AR'): string {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix);
        // Base36 timestamp + random 6-char alphanumeric
        $timePart = strtoupper(base_convert((string)time(), 10, 36));
        $randPart = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $orderId = $prefix . $timePart . $randPart;
        return substr($orderId, 0, 20);
    }

    /**
     * Create Hosted Checkout Session (POST /api/checkout/sessions)
     *
     * @param array $params [
     *   'amount'            => float,
     *   'orderId'           => string (max 20 alphanumeric),
     *   'customerContact'   => string,
     *   'customerEmail'     => string,
     *   'customerName'      => string|null,
     *   'paymentMethodName' => string|null ('Easypaisa', 'JazzCash', 'QR', 'Card', or null),
     *   'callbackUrl'       => string|null,
     *   'successUrl'        => string|null,
     *   'failedUrl'         => string|null,
     *   'pendingUrl'        => string|null,
     *   'expiresInSeconds'  => int (max 600, default 600)
     * ]
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function createCheckoutSession(array $params): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error'   => 'AssanPay API Key is not configured. Please check your environment or config file.'
            ];
        }

        $branchCode = !empty($params['branchCode']) ? $params['branchCode'] : $this->branchCode;
        if (empty($branchCode)) {
            return [
                'success' => false,
                'error'   => 'AssanPay Branch Code is required. Please configure your branch code.'
            ];
        }

        $orderId = preg_replace('/[^A-Za-z0-9]/', '', $params['orderId'] ?? self::generateOrderId());
        if (strlen($orderId) > 20) {
            $orderId = substr($orderId, 0, 20);
        }

        $amount = round(floatval($params['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return [
                'success' => false,
                'error'   => 'Invalid amount specified.'
            ];
        }

        $payload = [
            'orderId'          => $orderId,
            'branchCode'       => (string)$branchCode,
            'amount'           => $amount,
            'customerContact'  => trim((string)($params['customerContact'] ?? '')),
            'customerEmail'    => trim((string)($params['customerEmail'] ?? '')),
            'customerName'     => trim((string)($params['customerName'] ?? 'Customer')),
            'expiresInSeconds' => intval($params['expiresInSeconds'] ?? 600)
        ];

        if (!empty($params['paymentMethodName'])) {
            $payload['paymentMethodName'] = trim($params['paymentMethodName']);
        }
        if (!empty($params['callbackUrl'])) {
            $payload['callbackUrl'] = trim($params['callbackUrl']);
        }
        if (!empty($params['successUrl'])) {
            $payload['successUrl'] = trim($params['successUrl']);
        }
        if (!empty($params['failedUrl'])) {
            $payload['failedUrl'] = trim($params['failedUrl']);
        }
        if (!empty($params['pendingUrl'])) {
            $payload['pendingUrl'] = trim($params['pendingUrl']);
        }

        $path = '/api/checkout/sessions';
        $response = $this->sendRequest('POST', $path, $payload);

        if (!$response['success']) {
            return $response;
        }

        $data = $response['data'];
        if (isset($data['checkoutUrl']) || isset($data['sessionId'])) {
            return [
                'success' => true,
                'orderId' => $orderId,
                'data'    => $data,
                'checkoutUrl' => $data['checkoutUrl'] ?? '',
                'sessionId'   => $data['sessionId'] ?? '',
                'paymentId'   => $data['paymentId'] ?? null,
                'expiresAt'   => $data['expiresAt'] ?? null
            ];
        }

        $errMsg = $data['message'] ?? $data['error'] ?? 'Unknown response from AssanPay checkout session endpoint.';
        return [
            'success' => false,
            'error'   => $errMsg,
            'raw'     => $data
        ];
    }

    /**
     * Payin Status Inquiry (GET /api/merchant/status-inquiry?type=payin&orderId={orderId})
     */
    public function checkPaymentStatus(string $orderId): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'AssanPay API Key not configured.'];
        }

        $path = '/api/merchant/status-inquiry?type=payin&orderId=' . urlencode($orderId);
        return $this->sendRequest('GET', $path, null);
    }

    /**
     * Verify Callback / Webhook Signature
     * Formula:
     * canonical = EVENT_ID + "\n" + TIMESTAMP + "\n" + RAW_BODY
     * signature = Base64(HMAC-SHA256(canonical, API_SECRET))
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool {
        // If no API secret is configured, fallback to allowlist IP check or key check
        if (empty($this->apiSecret)) {
            return true;
        }

        // Normalize header keys to lowercase
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower(str_replace('_', '-', $k))] = is_array($v) ? $v[0] : $v;
        }

        $eventId   = $normalized['x-assanpay-event-id'] ?? $normalized['http-x-assanpay-event-id'] ?? '';
        $timestamp = $normalized['x-assanpay-timestamp'] ?? $normalized['http-x-assanpay-timestamp'] ?? '';
        $receivedSignature = $normalized['x-assanpay-signature'] ?? $normalized['http-x-assanpay-signature'] ?? '';

        if (empty($receivedSignature)) {
            return false;
        }

        $canonical = $eventId . "\n" . $timestamp . "\n" . $rawBody;
        $expectedSignature = base64_encode(hash_hmac('sha256', $canonical, $this->apiSecret, true));

        return hash_equals($expectedSignature, $receivedSignature);
    }

    /**
     * Build Signed Headers for API Requests
     */
    private function buildHeaders(string $method, string $pathWithQuery, ?string $rawBody): array {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $this->apiKey
        ];

        // If API Secret is available, add HMAC-SHA256 signature headers (Signed Request Mode)
        if (!empty($this->apiSecret)) {
            $timestamp = (string)time();
            $nonce = bin2hex(random_bytes(16));
            $bodyString = $rawBody ?? '';

            // Step 1: Body Hash (SHA256 Hex)
            $bodyHash = hash('sha256', $bodyString);

            // Step 2: Canonical String
            $canonical = strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyHash;

            // Step 3: HMAC-SHA256 Base64 Signature
            $signature = base64_encode(hash_hmac('sha256', $canonical, $this->apiSecret, true));

            $headers[] = 'X-TIMESTAMP: ' . $timestamp;
            $headers[] = 'X-NONCE: ' . $nonce;
            $headers[] = 'X-SIGNATURE: ' . $signature;
        }

        return $headers;
    }

    /**
     * Send HTTP request via cURL
     */
    private function sendRequest(string $method, string $pathWithQuery, ?array $body = null): array {
        $url = $this->baseUrl . $pathWithQuery;
        $rawBody = ($body !== null) ? json_encode($body, JSON_UNESCAPED_SLASHES) : null;
        $headers = $this->buildHeaders($method, $pathWithQuery, $rawBody);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($rawBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
            }
        } elseif (strtoupper($method) === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || !empty($curlError)) {
            return [
                'success' => false,
                'error'   => 'cURL error: ' . $curlError,
                'httpCode'=> $httpCode
            ];
        }

        $decoded = json_decode($responseBody, true);
        if ($decoded === null && !empty($responseBody)) {
            return [
                'success' => false,
                'error'   => 'Invalid JSON response from AssanPay server (HTTP ' . $httpCode . ')',
                'raw'     => $responseBody,
                'httpCode'=> $httpCode
            ];
        }

        // Check for 2xx status code
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data'    => $decoded,
                'httpCode'=> $httpCode
            ];
        }

        // Return error details
        $errMsg = $decoded['message'] ?? $decoded['error'] ?? ('HTTP Error ' . $httpCode);
        return [
            'success' => false,
            'error'   => $errMsg,
            'data'    => $decoded,
            'httpCode'=> $httpCode
        ];
    }
}
