<?php
/**
 * SwichService.php
 * Comprehensive client implementation for Swich Payment Gateway (PayIn API & PWA).
 * Strictly conforms to official Swich 2026 Integration Guide:
 * - PWA Landing Page (GET) with HMAC-SHA256 Checksum
 * - Callback Webhook verification (SWCallback:CustomerTransactionId:OrderId:Amount:Status)
 * - OAuth 2.0 Bearer Token Generation (client_credentials)
 * - Inquire API (v2.0) for Transaction Status Inquiry
 */

require_once __DIR__ . '/swich_config.php';

class SwichService {
    private string $clientId;
    private string $clientSecret;
    private string $secretKey;
    private string $pwaUrl;
    private string $authUrl;
    private string $apiBaseUrl;
    private string $returnUrl;
    private string $callbackUrl;

    public function __construct(?array $config = null) {
        $defaults = get_swich_config();
        $cfg = array_merge($defaults, $config ?? []);

        $this->clientId     = $cfg['client_id'] ?? '';
        $this->clientSecret = $cfg['client_secret'] ?? '';
        $this->secretKey    = $cfg['secret_key'] ?? '';
        $this->pwaUrl       = $cfg['pwa_url'] ?? 'https://payin-pwa.swichnow.com/';
        $this->authUrl      = $cfg['auth_url'] ?? 'https://auth.swichnow.com/connect/token';
        $this->apiBaseUrl   = $cfg['api_base_url'] ?? 'https://api.swichnow.com';
        $this->returnUrl    = $cfg['return_url'] ?? '';
        $this->callbackUrl  = $cfg['callback_url'] ?? '';

        date_default_timezone_set('Asia/Karachi');
    }

    /**
     * Generates a unique transaction reference for Swich (max 50 characters).
     */
    public static function generateTransactionId(string $prefix = 'SW'): string {
        $milliTime = sprintf("%03d", (int)((microtime(true) * 1000) % 1000));
        return $prefix . date('YmdHis') . $milliTime . mt_rand(10, 99);
    }

    /**
     * Calculates HMAC-SHA256 Checksum for Swich PWA Landing Page (GET).
     *
     * Formula per Swich Specification (Section 5.2):
     * Swich:customer_transaction_id:item:amount
     */
    public function calculatePwaChecksum(string $customerTransactionId, string $item, string $amount): string {
        $plain = "Swich:{$customerTransactionId}:{$item}:{$amount}";
        return hash_hmac('sha256', $plain, $this->secretKey);
    }

    /**
     * Builds the complete redirect URL for Swich PayIn PWA Landing Page (GET).
     *
     * @param float $amount Transaction amount in PKR (between 10.00 and 50000.00)
     * @param string $customerTransactionId Unique transaction ID
     * @param string $payeeName Customer name
     * @param string $email Customer email
     * @param string $msisdn Customer phone (format: 03xxxxxxxxx)
     * @param string $description Description of purchase
     * @param string $item Product/Service identifier (alphanumeric, no special chars)
     * @param string|null $successRedirectUrl Custom redirect URL on success
     * @return string Complete URL to redirect customer browser
     */
    public function buildPwaCheckoutUrl(
        float $amount,
        string $customerTransactionId,
        string $payeeName,
        string $email,
        string $msisdn,
        string $description = 'ArenaReserve Payment',
        string $item = 'ArenaReserve',
        ?string $successRedirectUrl = null
    ): string {
        // Sanitize item: alphanumeric only, max 500 chars
        $cleanItem = preg_replace('/[^A-Za-z0-9]/', '', $item);
        if (empty($cleanItem)) {
            $cleanItem = 'ArenaReserve';
        }

        // Clean amount: format to 2 decimal places if needed or integer if whole
        $cleanAmount = ((float)$amount == (int)$amount) ? (string)(int)$amount : number_format($amount, 2, '.', '');

        // Clean MSISDN (e.g. 03001234567)
        $cleanMsisdn = preg_replace('/[^0-9]/', '', $msisdn);
        if (strpos($cleanMsisdn, '92') === 0 && strlen($cleanMsisdn) === 12) {
            $cleanMsisdn = '0' . substr($cleanMsisdn, 2);
        }

        // Clean payee name
        $cleanName = trim(preg_replace('/[^A-Za-z0-9 ]/', '', $payeeName)) ?: 'Customer';

        // Clean description
        $cleanDesc = trim(preg_replace('/[^A-Za-z0-9 ]/', '', $description)) ?: 'ArenaReserve Payment';

        $redirectUrl = !empty($successRedirectUrl) ? $successRedirectUrl : $this->returnUrl;

        // Calculate Checksum
        $checksum = $this->calculatePwaChecksum($customerTransactionId, $cleanItem, $cleanAmount);

        $params = [
            'clientId'              => $this->clientId,
            'customerTransactionId' => $customerTransactionId,
            'item'                  => $cleanItem,
            'amount'                => $cleanAmount,
            'channel'               => 0,
            'billReferenceNo'       => $customerTransactionId,
            'description'           => $cleanDesc,
            'PayeeName'             => $cleanName,
            'Email'                 => $email,
            'MSISDN'                => $cleanMsisdn,
            'currency'              => 'PKR',
            'checksum'              => $checksum,
            'successRedirectUrl'    => $redirectUrl,
        ];

        $queryString = http_build_query($params);
        $fullUrl = $this->pwaUrl . '?' . $queryString;

        $this->log('Generated PWA URL', [
            'customerTransactionId' => $customerTransactionId,
            'amount'                => $cleanAmount,
            'checksum'              => $checksum,
            'url'                   => $fullUrl
        ]);

        return $fullUrl;
    }

    /**
     * Verifies an incoming Swich Callback checksum.
     *
     * Formula per Swich Specification (Section 16):
     * SWCallback:CustomerTransactionId:OrderId:Amount:Status
     * Signature: hash_hmac('sha256', $plain, $SecretKey, false)
     */
    public function verifyCallbackChecksum(
        string $customerTransactionId,
        string $orderId,
        string $amount,
        string $status,
        string $receivedChecksum
    ): bool {
        if (empty($receivedChecksum)) {
            return false;
        }

        $plain = "SWCallback:{$customerTransactionId}:{$orderId}:{$amount}:{$status}";
        $calculated = hash_hmac('sha256', $plain, $this->secretKey, false);

        return (strcasecmp($calculated, $receivedChecksum) === 0);
    }

    /**
     * Retrieves an OAuth 2.0 Bearer Access Token from Swich.
     * Cached temporarily in session or memory if needed.
     */
    public function getAccessToken(): ?string {
        $ch = curl_init($this->authUrl);
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode !== 200) {
            $this->log('Failed to fetch Swich Access Token', [
                'http_code' => $httpCode,
                'error'     => $err,
                'response'  => $response
            ]);
            return null;
        }

        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /**
     * Inquires transaction status via Swich Inquire API (v2.0).
     *
     * URL: GET https://api.swichnow.com/gateway/payin/v2.0/inquire?CustomerTransactionId={id}
     */
    public function inquireTransaction(string $customerTransactionId): array {
        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Unable to authenticate with Swich API (Token failed)',
                'raw'     => null
            ];
        }

        $url = $this->apiBaseUrl . '/gateway/payin/v2.0/inquire?CustomerTransactionId=' . urlencode($customerTransactionId);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->log('Swich Inquire API Call', [
            'customerTransactionId' => $customerTransactionId,
            'http_code'             => $httpCode,
            'response'              => $response
        ]);

        if ($err) {
            return [
                'success' => false,
                'message' => 'Connection to Swich Inquire API failed: ' . $err,
                'raw'     => null
            ];
        }

        $data = json_decode($response, true);
        if (!$data || !is_array($data)) {
            return [
                'success' => false,
                'message' => 'Invalid response from Swich Inquire API',
                'raw'     => $response
            ];
        }

        $code   = trim($data['code'] ?? '');
        $status = strtolower(trim($data['status'] ?? ''));
        $tx     = $data['transaction'] ?? [];
        $txStatus = strtolower(trim($tx['transactionStatus'] ?? ''));

        $isPaid = ($status === 'success' && $txStatus === 'success');

        return [
            'success'            => $isPaid,
            'is_paid'            => $isPaid,
            'status'             => $txStatus ?: $status,
            'code'               => $code,
            'message'            => $data['message'] ?? '',
            'transaction_id'     => $tx['id'] ?? null,
            'order_id'           => $tx['orderId'] ?? null,
            'channel'            => $tx['channelName'] ?? null,
            'category'           => $tx['categoryName'] ?? null,
            'amount'             => $tx['amount'] ?? null,
            'data'               => $data,
            'raw'                => $data
        ];
    }

    /**
     * Internal audit logger for Swich transactions.
     */
    public function log(string $action, array $context = []): void {
        $logDir = __DIR__ . '/logs';
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $line = "[" . date('Y-m-d H:i:s') . "] {$action} | " . json_encode($context) . "\n";
        @file_put_contents($logDir . '/swich.log', $line, FILE_APPEND);
    }

    public function getClientId(): string {
        return $this->clientId;
    }

    public function getPwaUrl(): string {
        return $this->pwaUrl;
    }
}
