<?php
/**
 * jazzcash_ipn.php
 * REST-based Instant Payment Notification (IPN) listener for JazzCash.
 * Conforms strictly to official JazzCash 2026 IPN Implementation Guide.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/JazzCashService.php';
require_once __DIR__ . '/payment_fulfill_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Log incoming IPN calls for audit
function log_jazzcash_ipn(string $msg, array $context = []): void {
    $logDir = __DIR__ . '/logs';
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $line = date('Y-m-d H:i:s') . ' | ' . $msg . ' | ' . json_encode($context) . "\n";
    @file_put_contents($logDir . '/jazzcash_ipn.log', $line, FILE_APPEND);
}

// Receive raw body (JSON) or form-data
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true) ?: $_POST;

log_jazzcash_ipn('Incoming JazzCash IPN', ['body' => $rawBody, 'post' => $_POST]);

$jc = new JazzCashService();

// Verify Secure Hash
if (empty($payload['pp_SecureHash']) || !$jc->verifySecureHash($payload)) {
    log_jazzcash_ipn('Invalid or Missing Secure Hash in IPN', ['payload' => $payload]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or Missing Secure Hash']);
    exit;
}

$txnRefNo     = trim($payload['pp_TxnRefNo'] ?? '');
$responseCode = trim($payload['pp_ResponseCode'] ?? '');
$retrievalRef = trim($payload['pp_RetreivalReferenceNo'] ?? ($payload['pp_RetrievalReferenceNo'] ?? ''));
$authCode     = trim($payload['pp_AuthCode'] ?? '');
$billRef      = trim($payload['pp_BillReference'] ?? '');

$orderId = $billRef ?: $txnRefNo;

if (empty($orderId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing transaction reference']);
    exit;
}

try {
    // 121 or 000 indicates successful completion
    if ($responseCode === '121' || $responseCode === '000') {
        $reference = $retrievalRef ?: $txnRefNo;
        $res = fulfillPaymentTransaction($pdo, $orderId, $reference, $rawBody ?: json_encode($_POST), $authCode);
        log_jazzcash_ipn('IPN Fulfilled', ['orderId' => $orderId, 'result' => $res]);
    } elseif (in_array($responseCode, ['124', '157'])) {
        // Pending state: do not mark failed, keep pending for subsequent callback or inquiry
        log_jazzcash_ipn('IPN Pending', ['orderId' => $orderId, 'code' => $responseCode]);
    } else {
        // Mark failed
        $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'failed', raw_callback = ? 
            WHERE (order_id = ? OR session_id = ?) AND status = 'pending'
        ")->execute([$rawBody ?: json_encode($_POST), $orderId, $txnRefNo]);
        log_jazzcash_ipn('IPN Marked Failed', ['orderId' => $orderId, 'code' => $responseCode]);
    }

    // Return official expected response format per JazzCash 2026 IPN Guide:
    $ipnResponse = $jc->generateIpnResponse('IPN received successfully');
    http_response_code(200);
    echo json_encode($ipnResponse);
    exit;

} catch (Exception $e) {
    log_jazzcash_ipn('IPN Processing Exception', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
