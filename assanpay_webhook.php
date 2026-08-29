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
    $pdo->beginTransaction();

    // Lock transaction record
    $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $tx = $stmt->fetch();

    if (!$tx) {
        $pdo->rollBack();
        log_webhook("Order ID not found in database", ['orderId' => $orderId]);
        // Return 200 to acknowledge AssanPay so it doesn't indefinitely retry an orphaned order
        http_response_code(200);
        echo json_encode(['status' => 'order_not_found', 'orderId' => $orderId]);
        exit;
    }

    // Idempotency: If already marked success, do not re-process
    if ($tx['status'] === 'success') {
        $pdo->rollBack();
        http_response_code(200);
        echo json_encode(['status' => 'already_processed', 'orderId' => $orderId]);
        exit;
    }

    $paymentId = $payload['paymentId'] ?? $tx['payment_id'];
    $reference = $payload['reference'] ?? $payload['transactionId'] ?? null;
    $amount    = floatval($payload['amount'] ?? $tx['amount']);
    $metaData  = !empty($tx['meta_data']) ? json_decode($tx['meta_data'], true) : [];

    if ($isSuccess) {
        // 1. Mark transaction as SUCCESS
        $updTx = $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'success', payment_id = ?, reference = ?, raw_callback = ? 
            WHERE id = ?
        ");
        $updTx->execute([$paymentId, $reference, $rawBody, $tx['id']]);

        // 2. Handle specific business logic based on purpose
        $purpose = $tx['purpose'];
        $user_id = intval($tx['user_id']);

        if ($purpose === 'wallet_topup') {
            // Credit user wallet
            $wStmt = $pdo->prepare("SELECT id, available_balance FROM wallets WHERE user_id = ? FOR UPDATE");
            $wStmt->execute([$user_id]);
            $wallet = $wStmt->fetch();

            if (!$wallet) {
                $insW = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, frozen_escrow_balance) VALUES (?, ?, 0.00)");
                $insW->execute([$user_id, $amount]);
                $walletId = $pdo->lastInsertId();
            } else {
                $walletId = $wallet['id'];
                $pdo->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE id = ?")
                    ->execute([$amount, $walletId]);
            }

            // Log wallet transaction
            $pdo->prepare("
                INSERT INTO wallet_transactions (wallet_id, amount, transaction_type, reference_id) 
                VALUES (?, ?, 'Deposit', ?)
            ")->execute([$walletId, $amount, 'AP-' . $orderId]);

            // In-app notification
            createNotification($pdo, $user_id, 'wallet_topup',
                'Wallet Top-up Successful! 💳',
                "Your wallet has been credited with " . number_format($amount, 2) . " PKR via AssanPay.",
                'wallet.php'
            );

        } elseif ($purpose === 'slot_booking') {
            $ground_id            = intval($metaData['ground_id'] ?? 0);
            $slot_date            = trim($metaData['slot_date'] ?? '');
            $slot_hour            = intval($metaData['slot_hour'] ?? -1);
            $booking_type         = trim($metaData['booking_type'] ?? 'direct');
            $full_price           = floatval($metaData['full_price'] ?? $amount);
            $challenger_team_name = trim($metaData['challenger_team_name'] ?? '');
            $challenged_user_id   = intval($metaData['challenged_user_id'] ?? 0);
            $ch_message           = trim($metaData['challenge_message'] ?? '');

            $status_map = [
                'direct'         => 'confirmed',
                'open_challenge' => 'challenge_open',
                'team_challenge' => 'challenge_pending',
            ];
            $booking_status = $status_map[$booking_type] ?? 'confirmed';

            // Insert into bookings table
            $bStmt = $pdo->prepare("
                INSERT INTO bookings (ground_id, booked_by, slot_date, slot_hour, price, amount_paid, booking_type, status, challenger_team_name, challenged_user_id, challenge_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $bStmt->execute([
                $ground_id, $user_id, $slot_date, $slot_hour,
                $full_price, $amount,
                $booking_type, $booking_status,
                $challenger_team_name ?: null,
                ($booking_type === 'team_challenge' && $challenged_user_id > 0) ? $challenged_user_id : null,
                ($booking_type === 'team_challenge' && !empty($ch_message)) ? $ch_message : null
            ]);
            $booking_id = $pdo->lastInsertId();

            // Release slot hold
            $pdo->prepare("DELETE FROM slot_holds WHERE ground_id = ? AND slot_date = ? AND slot_hour = ?")
                ->execute([$ground_id, $slot_date, $slot_hour]);

            // Fetch ground + user details for notifications
            $infoStmt = $pdo->prepare("
                SELECT g.title AS ground_title, g.owner_id,
                       u_player.name AS player_name, u_player.email AS player_email,
                       u_owner.name AS owner_name, u_owner.email AS owner_email
                FROM grounds g
                JOIN users u_player ON u_player.id = ?
                JOIN users u_owner  ON u_owner.id  = g.owner_id
                WHERE g.id = ?
            ");
            $infoStmt->execute([$user_id, $ground_id]);
            $info = $infoStmt->fetch();

            if ($info) {
                // Notifications
                $playerNotifTitles = [
                    'direct'         => 'Booking Confirmed!',
                    'open_challenge' => 'Open Challenge Posted!',
                    'team_challenge' => 'Team Challenge Sent!',
                ];
                $playerNotifMsgs = [
                    'direct'         => "Your slot at {$info['ground_title']} on {$slot_date} " . sprintf('%02d:00', $slot_hour) . " is confirmed via AssanPay.",
                    'open_challenge' => "Your open challenge at {$info['ground_title']} on {$slot_date} is live via AssanPay.",
                    'team_challenge' => "Your team challenge at {$info['ground_title']} on {$slot_date} is sent via AssanPay.",
                ];
                createNotification($pdo, $user_id, 'booking_confirmed',
                    $playerNotifTitles[$booking_type] ?? 'Booking Confirmed',
                    $playerNotifMsgs[$booking_type] ?? 'Booking confirmed.',
                    'match_history.php'
                );

                if ($info['owner_id'] != $user_id) {
                    createNotification($pdo, $info['owner_id'], 'new_booking_owner',
                        'New Booking on ' . $info['ground_title'],
                        "{$info['player_name']} booked a slot on {$slot_date} at " . sprintf('%02d:00', $slot_hour) . " (Paid online via AssanPay).",
                        'owner_dashboard.php'
                    );
                }

                // Email notifications
                sendBookingConfirmedEmail($info['player_email'], $info['player_name'], [
                    'ground_title' => $info['ground_title'],
                    'slot_date'    => $slot_date,
                    'slot_hour'    => $slot_hour,
                    'booking_type' => $booking_type,
                    'amount_paid'  => $amount,
                    'booking_id'   => $booking_id,
                ]);
            }

        } elseif ($purpose === 'accept_challenge') {
            $booking_id = intval($metaData['booking_id'] ?? 0);
            if ($booking_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE bookings SET status = 'challenge_accepted', opponent_id = ?, amount_paid = amount_paid + ?
                    WHERE id = ?
                ");
                $stmt->execute([$user_id, $amount, $booking_id]);

                // In-app notifications
                createNotification($pdo, $user_id, 'challenge_accepted',
                    'Challenge Confirmed!',
                    "You paid your 25% share via AssanPay and accepted the challenge. Match confirmed!",
                    'match_history.php'
                );
            }
        }

    } else {
        // Mark transaction as FAILED
        $updTx = $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'failed', payment_id = ?, reference = ?, raw_callback = ? 
            WHERE id = ?
        ");
        $updTx->execute([$paymentId, $reference, $rawBody, $tx['id']]);

        log_webhook("Payment failed/declined by gateway", ['orderId' => $orderId, 'status' => $statusStr]);
    }

    $pdo->commit();

    // Respond with HTTP 200 OK to acknowledge AssanPay webhook
    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'Callback processed successfully',
        'orderId' => $orderId
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_webhook("Webhook error exception", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
