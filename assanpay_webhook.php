<?php
/**
 * assanpay_webhook.php
 * Webhook endpoint to receive and process AssanPay payin callback notifications.
 * Strictly verifies signature, prevents replay attacks, and processes payments idempotently.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/AssanPayService.php';
require_once __DIR__ . '/payment_fulfill_helper.php';

// Log webhook calls for auditing
function log_webhook(string $message, array $context = []) {
    $logDir = __DIR__ . '/logs';
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $entry = date('Y-m-d H:i:s') . " | " . $message . " | " . json_encode($context) . "\n";
    @file_put_contents($logDir . '/assanpay_webhook.log', $entry, FILE_APPEND);
}

// Read raw body & headers
$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (empty($headers)) {
    // Fallback for environments where getallheaders() is unavailable
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$header] = $value;
        }
    }
}

log_webhook("Incoming Webhook", ['headers' => $headers, 'body' => $rawBody]);

$assanPay = new AssanPayService();

// Verify signature if secret is configured
if (!$assanPay->verifyWebhookSignature($rawBody, $headers)) {
    log_webhook("Signature verification failed", ['raw' => $rawBody]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!$payload || !isset($payload['orderId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload or missing orderId']);
    exit;
}

$orderId = trim($payload['orderId']);
$statusStr = strtoupper($payload['status'] ?? '');
$statusCode = (string)($payload['statusCode'] ?? '');
$isSuccess = ($statusStr === 'SUCCESS' || $statusCode === '200' || ($payload['success'] ?? false) === true);

try {
    if ($isSuccess) {
        $paymentId = $payload['paymentId'] ?? null;
        $reference = $payload['reference'] ?? $payload['transactionId'] ?? null;
        $fulfillResult = fulfillPaymentTransaction($pdo, $orderId, $reference, $rawBody, $paymentId);

        if (!$fulfillResult['success'] && ($fulfillResult['status'] ?? '') === 'not_found') {
            log_webhook("Order ID not found in database", ['orderId' => $orderId]);
            http_response_code(200);
            echo json_encode(['status' => 'order_not_found', 'orderId' => $orderId]);
            exit;
        }

        log_webhook("Webhook processed successfully", ['orderId' => $orderId, 'fulfill' => $fulfillResult]);

        http_response_code(200);
        echo json_encode([
            'status'  => 'success',
            'message' => 'Callback processed successfully',
            'orderId' => $orderId
        ]);
        exit;
    } else {
        // Mark transaction as FAILED
        $paymentId = $payload['paymentId'] ?? null;
        $reference = $payload['reference'] ?? $payload['transactionId'] ?? null;
        $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'failed', payment_id = COALESCE(?, payment_id), reference = COALESCE(?, reference), raw_callback = ? 
            WHERE order_id = ? AND status = 'pending'
        ")->execute([$paymentId, $reference, $rawBody, $orderId]);

        log_webhook("Payment failed/declined by gateway", ['orderId' => $orderId, 'status' => $statusStr]);

        http_response_code(200);
        echo json_encode([
            'status'  => 'failed',
            'message' => 'Payment failure acknowledged',
            'orderId' => $orderId
        ]);
        exit;
    }
} catch (Exception $e) {
    log_webhook("Webhook error exception", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
