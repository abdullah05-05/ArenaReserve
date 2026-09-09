<?php
/**
 * JazzCashService.php
 * Comprehensive client implementation for JazzCash Payment Gateway.
 * Fully conforms to official JazzCash 2026 specifications:
 * - Page Redirection / Hosted Checkout (v1.1)
 * - HMAC-SHA256 Signature Generation & Verification
 * - REST Status Inquiry API (v1.1)
 * - REST IPN Response Generation
 */

require_once __DIR__ . '/jazzcash_config.php';

class JazzCashService {
    private string $merchantId;
    private string $password;
    private string $integritySalt;
    private string $environment;
    private string $postUrl;
    private string $statusInquiryUrl;
    private string $mwalletUrl;
    private string $returnUrl;
    private string $ipnUrl;
    public function __construct(?array $config = null) {
        $defaults = get_jazzcash_config();
        $cfg = array_merge($defaults, $config ?? []);
        $this->merchantId       = $cfg['merchant_id'] ?? '';
        $this->password         = $cfg['password'] ?? '';
        $this->integritySalt    = $cfg['integrity_salt'] ?? '';
        $this->environment      = $cfg['environment'] ?? 'sandbox';
        $this->postUrl          = $cfg['post_url'] ?? '';
        $this->statusInquiryUrl = $cfg['status_inquiry_url'] ?? '';
        $this->mwalletUrl       = $cfg['mwallet_url'] ?? 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v2/rest/payments/m-wallet';
        $this->returnUrl        = $cfg['return_url'] ?? '';
        $this->ipnUrl           = $cfg['ipn_url'] ?? '';

        // Ensure Pakistan Standard Time (PKT)
        date_default_timezone_set('Asia/Karachi');
    }

    /**
     * Generates a unique transaction reference number (e.g. TRN20260909060012345)
     */
    public static function generateTxnRefNo(string $prefix = 'TRN'): string {
        $milliTime = sprintf("%03d", (int)((microtime(true) * 1000) % 1000));
        return $prefix . date('YmdHis') . $milliTime;
    }

    /**
     * Calculates official HMAC-SHA256 Secure Hash as specified in JazzCash 2026 Guide.
     *
     * Rules:
     * 1. Sort based on parameter keys ascending (ASCII / ksort).
     * 2. Exclude empty or null values.
     * 3. Concatenate non-empty values separated by '&'.
     * 4. Prepend Integrity Salt.
     * 5. HMAC-SHA256 with Integrity Salt as secret key.
     */
    public function calculateSecureHash(array $params): string {
        // Exclude secure hash field if already present
        unset($params['pp_SecureHash'], $params['SecureHash']);

        ksort($params);

        $sortedString = $this->integritySalt;
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $sortedString .= '&' . $value;
            }
        }

        return hash_hmac('sha256', $sortedString, $this->integritySalt);
    }

    /**
     * Verifies an incoming response or IPN callback hash.
     */
    public function verifySecureHash(array $response): bool {
        $receivedHash = $response['pp_SecureHash'] ?? ($response['SecureHash'] ?? '');
        if (empty($receivedHash)) {
            return false;
        }

        $calculatedHash = $this->calculateSecureHash($response);
        return (strcasecmp($calculatedHash, $receivedHash) === 0);
    }

    /**
     * Builds the complete parameter array for Page Redirection / Hosted Checkout (v1.1).
     *
     * @param float $amount Amount in PKR (will be converted to Paisas by multiplying by 100)
     * @param string $txnRefNo Unique transaction reference
     * @param string $billReference Alphanumeric reference (e.g. order ID)
     * @param string $description Payment description
     * @param string $txnType Payment type ("MPAY" for Card, or empty string for all methods)
     * @param array $extra Optional custom meta or ppmpf fields
     */
    public function buildCheckoutPayload(
        float $amount,
        string $txnRefNo,
        string $billReference,
        string $description = 'ArenaReserve Payment',
        string $txnType = '',
        array $extra = []
    ): array {
        // Format amount as integer in paisas (100 PKR = 10000)
        $amountInPaisas = (int)round($amount * 100);

        // Alphanumeric bill reference only
        $cleanBillRef = preg_replace('/[^A-Za-z0-9]/', '', $billReference);
        if (empty($cleanBillRef)) {
            $cleanBillRef = 'BILL' . date('YmdHis');
        }

        $cleanDesc = trim(preg_replace('/[^A-Za-z0-9 ]/', '', $description));
        if (empty($cleanDesc)) {
            $cleanDesc = 'ArenaReserve Payment';
        }

        $params = [
            'pp_Version'           => '1.1',
            'pp_TxnType'           => $txnType,
            'pp_Language'          => 'EN',
            'pp_MerchantID'        => $this->merchantId,
            'pp_Password'          => $this->password,
            'pp_TxnRefNo'          => $txnRefNo,
            'pp_Amount'            => (string)$amountInPaisas,
            'pp_TxnCurrency'       => 'PKR',
            'pp_TxnDateTime'       => date('YmdHis'),
            'pp_BillReference'     => $cleanBillRef,
            'pp_Description'       => substr($cleanDesc, 0, 60),
            'pp_TxnExpiryDateTime' => date('YmdHis', strtotime('+1 Days')),
            'pp_ReturnURL'         => $extra['return_url'] ?? $this->returnUrl,
            'pp_SubMerchantID'     => '',
            'pp_BankID'            => '',
            'pp_ProductID'         => '',
            'ppmpf_1'              => '',
            'ppmpf_2'              => '',
            'ppmpf_3'              => (string)($extra['ppmpf_3'] ?? ($extra['ppmpf_1'] ?? '')),
            'ppmpf_4'              => (string)($extra['ppmpf_4'] ?? ''),
            'ppmpf_5'              => (string)($extra['ppmpf_5'] ?? ''),
        ];

        // Generate and attach Secure Hash
        $params['pp_SecureHash'] = $this->calculateSecureHash($params);

        return [
            'post_url' => $this->postUrl,
            'params'   => $params
        ];
    }

    /**
     * Executes official JazzCash MWallet REST API v2.0 (With CNIC).
     *
     * Rules per Official 2026 Guide:
     * - All values passed as strings enclosed in double quotes.
     * - Empty parameters must remain as empty strings "".
     * - pp_Amount multiplied by 100 (in Paisas).
     * - pp_CNIC requires last 6 digits.
     * - pp_TxnExpiryDateTime set to +1 day.
     * - pp_SecureHash calculated using HMAC-SHA256 with Integrity Salt.
     *
     * @param float  $amount Amount in PKR
     * @param string $txnRefNo Unique transaction reference
     * @param string $billReference Order / Bill reference
     * @param string $mobileNumber Customer JazzCash mobile number (e.g. 03001234567)
     * @param string $cnic6 Customer CNIC last 6 digits
     * @param string $description Payment description
     * @return array
     */
    public function processMWalletPayment(
        float $amount,
        string $txnRefNo,
        string $billReference,
        string $mobileNumber,
        string $cnic6,
        string $description = 'ArenaReserve Payment'
    ): array {
        $amountInPaisas = (int)round($amount * 100);

        // Sanitize bill reference: alphanumeric only
        $cleanBillRef = preg_replace('/[^A-Za-z0-9]/', '', $billReference);
        if (empty($cleanBillRef)) {
            $cleanBillRef = 'BILL' . date('YmdHis');
        }

        // Sanitize description: alphanumeric and space only, max 60 chars
        $cleanDesc = trim(preg_replace('/[^A-Za-z0-9 ]/', '', $description));
        if (empty($cleanDesc)) {
            $cleanDesc = 'ArenaReserve Payment';
        }

        // Clean mobile number (e.g. 03001234567)
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobileNumber);
        // Clean CNIC last 6 digits
        $cleanCnic6 = preg_replace('/[^0-9]/', '', $cnic6);

        $params = [
            'pp_Amount'            => (string)$amountInPaisas,
            'pp_BankID'            => '',
            'pp_BillReference'     => $cleanBillRef,
            'pp_CNIC'              => (string)$cleanCnic6,
            'pp_Description'       => substr($cleanDesc, 0, 60),
            'pp_Language'          => 'EN',
            'pp_MerchantID'        => $this->merchantId,
            'pp_MobileNumber'      => (string)$cleanMobile,
            'pp_Password'          => $this->password,
            'pp_ProductID'         => '',
            'pp_SubMerchantID'     => '',
            'pp_TxnCurrency'       => 'PKR',
            'pp_TxnDateTime'       => date('YmdHis'),
            'pp_TxnExpiryDateTime' => date('YmdHis', strtotime('+1 day')),
            'pp_TxnRefNo'          => $txnRefNo,
            'ppmpf_1'              => '',
            'ppmpf_2'              => '',
            'ppmpf_3'              => '',
            'ppmpf_4'              => '',
            'ppmpf_5'              => '',
        ];

        // Generate and attach Secure Hash per official 2026 guidelines
        $params['pp_SecureHash'] = $this->calculateSecureHash($params);

        $ch = curl_init($this->mwalletUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        // 75 seconds timeout for USSD MPIN entry on customer phone
        curl_setopt($ch, CURLOPT_TIMEOUT, 75);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return [
                'success'       => false,
                'response_code' => 'CURL_ERR',
                'message'       => 'Connection timed out or failed: ' . $err,
                'http_code'     => $httpCode,
                'raw'           => null
            ];
        }

        $data = json_decode($response, true);
        if (!$data || !is_array($data)) {
            return [
                'success'       => false,
                'response_code' => 'INVALID_RESP',
                'message'       => 'Unexpected response from JazzCash server.',
                'http_code'     => $httpCode,
                'raw'           => $response
            ];
        }

        $respCode     = trim($data['pp_ResponseCode'] ?? '');
        $respMsg      = trim($data['pp_ResponseMessage'] ?? '');
        $retrievalRef = trim($data['pp_RetreivalReferenceNo'] ?? ($data['pp_RetrievalReferenceNo'] ?? ''));
        $authCode     = trim($data['pp_AuthCode'] ?? '');

        // Verify hash if present in response
        $hashValid = true;
        if (!empty($data['pp_SecureHash'])) {
            $hashValid = $this->verifySecureHash($data);
        }

        // '000' is official JazzCash success code for MWallet REST v2.0
        $isSuccess = ($respCode === '000') && $hashValid;

        return [
            'success'       => $isSuccess,
            'response_code' => $respCode,
            'message'       => $respMsg,
            'retrieval_ref' => $retrievalRef,
            'auth_code'     => $authCode,
            'data'          => $data,
            'raw'           => $data,
            'http_code'     => $httpCode,
            'hash_valid'    => $hashValid
        ];
    }

    /**
     * Executes Status Inquiry API (v1.1) to check transaction status on JazzCash servers.
     */
    public function queryStatus(string $txnRefNo): array {
        $params = [
            'pp_TxnRefNo'   => $txnRefNo,
            'pp_MerchantID' => $this->merchantId,
            'pp_Password'   => $this->password,
        ];
        $params['pp_SecureHash'] = $this->calculateSecureHash($params);

        $ch = curl_init($this->statusInquiryUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => $err];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return ['success' => false, 'raw' => $response, 'http_code' => $httpCode];
        }

        // '000' is API success, '121' indicates payment was completed and amount debited
        $isSuccess = ($data['pp_ResponseCode'] ?? '') === '000' && ($data['pp_PaymentResponseCode'] ?? '') === '121';

        return [
            'success'     => $isSuccess,
            'http_code'   => $httpCode,
            'data'        => $data,
            'is_paid'     => $isSuccess,
            'status'      => $data['pp_Status'] ?? 'Unknown',
            'message'     => $data['pp_PaymentResponseMessage'] ?? ($data['pp_ResponseMessage'] ?? '')
        ];
    }

    /**
     * Generates standard acknowledgment JSON for JazzCash REST IPN notifications.
     */
    public function generateIpnResponse(string $message = 'IPN received successfully'): array {
        $resp = [
            'pp_ResponseCode'    => '000',
            'pp_ResponseMessage' => $message,
        ];
        $resp['pp_SecureHash'] = $this->calculateSecureHash($resp);
        return $resp;
    }

    public function getMerchantId(): string {
        return $this->merchantId;
    }

    public function getPostUrl(): string {
        return $this->postUrl;
    }
}
