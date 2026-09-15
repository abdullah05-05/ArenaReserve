<?php
/**
 * swich_callback.php
 * Server-to-server webhook listener for Swich Payment Gateway.
 * Strictly implements official Swich 2026 Callback Specification (Section 16).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/SwichService.php';
require_once __DIR__ . '/payment_fulfill_helper.php';

header('Content-Type: application/json; charset=utf-8');

$swich = new SwichService();

// Receive parameters from GET (Swich sends HTTP GET callback) or POST fallback
$payload = !empty($_GET) ? $_GET : $_POST;
$rawInput = file_get_contents('php://input');

$swich->log('Incoming Swich Callback', [
    'get'   => $_GET,
    'post'  => $_POST,
    'input' => $rawInput
]);

$paymentType           = trim($payload['PaymentType'] ?? ($payload['paymentType'] ?? ''));
$status                = strtolower(trim($payload['Status'] ?? ($payload['status'] ?? '')));
$swichOrderId          = trim($payload['OrderId'] ?? ($payload['orderId'] ?? ''));
$customerTransactionId = trim($payload['CustomerTransactionId'] ?? ($payload['customerTransactionId'] ?? ''));
$amount                = trim($payload['Amount'] ?? ($payload['amount'] ?? ''));
$checksum              = trim($payload['Checksum'] ?? ($payload['checksum'] ?? ''));

// Validate required fields
if (empty($customerTransactionId) || empty($checksum)) {
    $swich->log('Callback Rejected: Missing CustomerTransactionId or Checksum', ['payload' => $payload]);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required callback parameters']);
    exit;
}

// Verify Checksum
$isValidHash = $swich->verifyCallbackChecksum(
    $customerTransactionId,
    $swichOrderId,
    $amount,
    $status,
    $checksum
);

if (!$isValidHash) {
    // Some gateways send case-preserved status (e.g. "Success" vs "success")
    $origStatus = trim($payload['Status'] ?? ($payload['status'] ?? ''));
    $isValidHash = $swich->verifyCallbackChecksum(
        $customerTransactionId,
        $swichOrderId,
        $amount,
        $origStatus,
        $checksum
    );
}

if (!$isValidHash) {
    $swich->log('Callback Rejected: Invalid Checksum', [
        'customerTransactionId' => $customerTransactionId,
        'orderId'               => $swichOrderId,
        'amount'                => $amount,
        'status'                => $status,
        'checksum'              => $checksum
    ]);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid Checksum']);
    exit;
}

// Find transaction record in database
$stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE session_id = ? OR order_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$customerTransactionId, $customerTransactionId]);
$transaction = $stmt->fetch();

if (!$transaction) {
    $swich->log('Callback Warning: Transaction not found in DB', ['customerTransactionId' => $customerTransactionId]);
    // Respond HTTP 200 so Swich does not retry endlessly
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    exit;
}

$orderId = $transaction['order_id'];
$rawCallbackData = json_encode($payload);

try {
    if ($status === 'success') {
        $reference = !empty($swichOrderId) ? $swichOrderId : $customerTransactionId;
        $res = fulfillPaymentTransaction($pdo, $orderId, $reference, $rawCallbackData, $swichOrderId);
        $swich->log('Callback Fulfilled Payment', [
            'orderId' => $orderId,
            'result'  => $res
        ]);
    } elseif ($status === 'pending') {
        $swich->log('Callback Status Pending', ['orderId' => $orderId]);
    } else {
        // Failed / Expired / Terminated
        $pdo->prepare("UPDATE payment_transactions SET status = 'failed', raw_callback = ? WHERE id = ? AND status = 'pending'")
            ->execute([$rawCallbackData, $transaction['id']]);
        $swich->log('Callback Marked Failed', ['orderId' => $orderId, 'status' => $status]);
    }

    // Swich requires response: {"status": "success"} with 2xx HTTP code
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    $swich->log('Callback Exception', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
