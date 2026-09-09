<?php
/**
 * payment_fulfill_helper.php
 * Centralized, thread-safe, idempotent fulfillment helper for JazzCash transactions.
 * Handles wallet_topup, slot_booking (single and multi-slot), and accept_challenge transactions.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mail_config.php';

/**
 * Fulfills a payment transaction idempotently based on orderId.
 *
 * @param PDO $pdo
 * @param string $orderId
 * @param string|null $reference
 * @param string|null $rawCallback
 * @param string|null $paymentId
 * @return array
 */
function fulfillPaymentTransaction(PDO $pdo, string $orderId, ?string $reference = null, ?string $rawCallback = null, ?string $paymentId = null): array
{
    $needsCommit = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $needsCommit = true;
    }

    try {
        // Lock and fetch the transaction record
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $tx = $stmt->fetch();

        if (!$tx) {
            if ($needsCommit) $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Transaction not found for Order ID: ' . $orderId,
                'status'  => 'not_found'
            ];
        }

        $metaData = !empty($tx['meta_data']) ? json_decode($tx['meta_data'], true) : [];
        $userId   = intval($tx['user_id']);
        $amount   = floatval($tx['amount']);
        $purpose  = $tx['purpose'];

        // If already marked success, fetch fulfilled details and return
        if ($tx['status'] === 'success') {
            if ($needsCommit) $pdo->commit();
            return [
                'success'     => true,
                'status'      => 'already_processed',
                'transaction' => $tx,
                'meta_data'   => $metaData,
                'purpose'     => $purpose,
                'amount'      => $amount,
            ];
        }

        // 1. Mark transaction as success
        $updStmt = $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'success',
                payment_id = COALESCE(?, payment_id),
                reference = COALESCE(?, reference),
                raw_callback = COALESCE(?, raw_callback)
            WHERE id = ?
        ");
        $updStmt->execute([
            $paymentId,
            $reference,
            $rawCallback,
            $tx['id']
        ]);

        $bookingId = null;
        $fulfilledDetails = [];

        // 2. Fulfill based on transaction purpose
        if ($purpose === 'wallet_topup') {
            // Credit wallet
            $wStmt = $pdo->prepare("SELECT id, available_balance FROM wallets WHERE user_id = ? FOR UPDATE");
            $wStmt->execute([$userId]);
            $wallet = $wStmt->fetch();

            if (!$wallet) {
                $insW = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, frozen_escrow_balance) VALUES (?, ?, 0.00)");
                $insW->execute([$userId, $amount]);
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
            ")->execute([$walletId, $amount, 'JC-' . $orderId]);

            // In-app notification
            createNotification($pdo, $userId, 'wallet_topup',
                'Wallet Top-up Successful! 💳',
                "Your wallet has been credited with " . number_format($amount, 2) . " PKR via JazzCash.",
                'wallet.php'
            );

            $fulfilledDetails = ['wallet_id' => $walletId, 'amount' => $amount];

        } elseif ($purpose === 'slot_booking') {
            $groundId           = intval($metaData['ground_id'] ?? 0);
            $slotDate           = trim($metaData['slot_date'] ?? '');
            $bookingType        = trim($metaData['booking_type'] ?? 'direct');
            $fullPrice          = floatval($metaData['full_price'] ?? $amount);
            $challengerTeamName = trim($metaData['challenger_team_name'] ?? '');
            $challengedUserId   = intval($metaData['challenged_user_id'] ?? 0);
            $chMessage          = trim($metaData['challenge_message'] ?? '');

            // Parse slot hours (array or single)
            $slotHours = [];
            if (!empty($metaData['slot_hours']) && is_array($metaData['slot_hours'])) {
                $slotHours = array_map('intval', $metaData['slot_hours']);
            } elseif (isset($metaData['slot_hour']) && intval($metaData['slot_hour']) >= 0) {
                $slotHours = [intval($metaData['slot_hour'])];
            }

            $slotHours = array_values(array_unique(array_filter($slotHours, fn($h) => $h >= 0 && $h <= 23)));
            sort($slotHours);
            $slotCount = count($slotHours);

            $statusMap = [
                'direct'         => 'confirmed',
                'open_challenge' => 'challenge_open',
                'team_challenge' => 'challenge_pending',
            ];
            $bookingStatus = $statusMap[$bookingType] ?? 'confirmed';

            // Ensure challenge_message column exists
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'challenge_message'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE bookings ADD COLUMN challenge_message TEXT DEFAULT NULL");
                }
            } catch (Exception $e) {}

            $items = $metaData['items'] ?? [];

            // Insert into bookings table for each slot hour
            $bStmt = $pdo->prepare("
                INSERT INTO bookings (
                    ground_id, booked_by, slot_date, slot_hour, price, amount_paid, 
                    booking_type, status, challenger_team_name, challenged_user_id, challenge_message
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $createdBookingIds = [];
            foreach ($slotHours as $h) {
                $itemFp  = floatval($items[$h]['full_price'] ?? ($fullPrice / max(1, $slotCount)));
                $itemAdv = floatval($items[$h]['advance'] ?? ($amount / max(1, $slotCount)));

                $bStmt->execute([
                    $groundId, $userId, $slotDate, $h,
                    $itemFp, $itemAdv,
                    $bookingType, $bookingStatus,
                    $challengerTeamName ?: null,
                    ($bookingType === 'team_challenge' && $challengedUserId > 0) ? $challengedUserId : null,
                    ($bookingType === 'team_challenge' && !empty($chMessage)) ? $chMessage : null
                ]);
                $createdBookingIds[] = $pdo->lastInsertId();
            }

            $bookingId = $createdBookingIds[0] ?? null;

            // Release slot holds
            if (!empty($slotHours)) {
                $inClause = implode(',', array_fill(0, count($slotHours), '?'));
                $pdo->prepare("DELETE FROM slot_holds WHERE ground_id = ? AND slot_date = ? AND slot_hour IN ($inClause)")
                    ->execute(array_merge([$groundId, $slotDate], $slotHours));
            }

            // Fetch ground + user details for notifications
            $infoStmt = $pdo->prepare("
                SELECT g.title AS ground_title, g.sport_type, g.owner_id,
                       u_player.name AS player_name, u_player.email AS player_email,
                       u_owner.name AS owner_name, u_owner.email AS owner_email
                FROM grounds g
                JOIN users u_player ON u_player.id = ?
                JOIN users u_owner  ON u_owner.id  = g.owner_id
                WHERE g.id = ?
            ");
            $infoStmt->execute([$userId, $groundId]);
            $info = $infoStmt->fetch();

            if ($info) {
                // Human-readable time strings
                $timeLabels = [];
                foreach ($slotHours as $h) {
                    $suffix     = $h < 12 ? 'AM' : 'PM';
                    $displayH   = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
                    $nextH      = $h + 1;
                    $nextDisp   = $nextH === 0 ? 12 : ($nextH > 12 ? $nextH - 12 : ($nextH === 12 ? 12 : $nextH));
                    $nextSuffix = $nextH < 12 ? 'AM' : 'PM';
                    $timeLabels[] = sprintf('%d:00 %s – %d:00 %s', $displayH, $suffix, $nextDisp, $nextSuffix);
                }
                $timeString = implode(', ', $timeLabels);

                $playerNotifTitles = [
                    'direct'         => $slotCount > 1 ? "{$slotCount} Bookings Confirmed!" : 'Booking Confirmed!',
                    'open_challenge' => $slotCount > 1 ? "{$slotCount} Open Challenges Posted!" : 'Open Challenge Posted!',
                    'team_challenge' => $slotCount > 1 ? "{$slotCount} Team Challenges Sent!" : 'Team Challenge Sent!',
                ];
                $playerNotifMsgs = [
                    'direct'         => "Your slots ({$timeString}) at {$info['ground_title']} on {$slotDate} are confirmed (Paid online via JazzCash).",
                    'open_challenge' => "Your open challenges ({$timeString}) at {$info['ground_title']} on {$slotDate} are live (Paid online via JazzCash).",
                    'team_challenge' => "Your team challenges ({$timeString}) at {$info['ground_title']} on {$slotDate} are sent (Paid online via JazzCash).",
                ];

                createNotification($pdo, $userId, 'booking_confirmed',
                    $playerNotifTitles[$bookingType] ?? 'Booking Confirmed',
                    $playerNotifMsgs[$bookingType] ?? 'Booking confirmed.',
                    'match_history.php'
                );

                if ($info['owner_id'] != $userId) {
                    createNotification($pdo, $info['owner_id'], 'new_booking_owner',
                        'New Booking on ' . $info['ground_title'],
                        "{$info['player_name']} booked {$slotCount} slot(s) ({$timeString}) on {$slotDate} (Paid online via JazzCash).",
                        'owner_dashboard.php'
                    );
                }

                // If team challenge, notify challenged user
                if ($bookingType === 'team_challenge' && $challengedUserId > 0) {
                    $chalStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
                    $chalStmt->execute([$challengedUserId]);
                    $chalUser = $chalStmt->fetch();
                    if ($chalUser) {
                        $chalNotifMsg = "{$info['player_name']} challenged your team for {$slotCount} slot(s) ({$timeString}) at {$info['ground_title']} on {$slotDate}. Pay 25% to accept in Match History!";
                        if (!empty($chMessage)) {
                            $chalNotifMsg .= " Message: \"{$chMessage}\"";
                        }
                        createNotification($pdo, $challengedUserId, 'challenge_received',
                            '⚡ Team Challenge Received!',
                            $chalNotifMsg,
                            'match_history.php'
                        );

                        foreach ($slotHours as $idx => $h) {
                            sendTeamChallengeSentEmail($chalUser['email'], $chalUser['name'], [
                                'ground_title'    => $info['ground_title'],
                                'slot_date'       => $slotDate,
                                'slot_hour'       => $h,
                                'booking_id'      => $createdBookingIds[$idx] ?? 0,
                                'challenger_name' => $info['player_name'],
                                'message'         => $chMessage,
                            ]);
                        }
                    }
                }

                // Email notifications
                foreach ($slotHours as $idx => $h) {
                    $itemAdv = floatval($items[$h]['advance'] ?? ($amount / max(1, $slotCount)));
                    sendBookingConfirmedEmail($info['player_email'], $info['player_name'], [
                        'ground_title' => $info['ground_title'],
                        'slot_date'    => $slotDate,
                        'slot_hour'    => $h,
                        'booking_type' => $bookingType,
                        'amount_paid'  => $itemAdv,
                        'booking_id'   => $createdBookingIds[$idx] ?? 0,
                    ]);

                    if ($info['owner_id'] != $userId) {
                        sendNewBookingOwnerEmail($info['owner_email'], $info['owner_name'], [
                            'ground_title' => $info['ground_title'],
                            'slot_date'    => $slotDate,
                            'slot_hour'    => $h,
                            'booking_type' => $bookingType,
                            'player_name'  => $info['player_name'],
                        ]);
                    }
                }

                $fulfilledDetails = [
                    'booking_id'   => $bookingId,
                    'booking_ids'  => $createdBookingIds,
                    'slot_hours'   => $slotHours,
                    'time_string'  => $timeString,
                    'time_labels'  => $timeLabels,
                    'ground_title' => $info['ground_title'],
                    'sport_type'   => $info['sport_type'],
                    'slot_date'    => $slotDate,
                    'slot_hour'    => $slotHours[0],
                    'booking_type' => $bookingType,
                    'amount'       => $amount,
                    'full_price'   => $fullPrice
                ];
            }

        } elseif ($purpose === 'accept_challenge') {
            $bookingId = intval($metaData['booking_id'] ?? 0);
            if ($bookingId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET status = 'challenge_accepted', opponent_id = ?, amount_paid = amount_paid + ?
                    WHERE id = ?
                ");
                $stmt->execute([$userId, $amount, $bookingId]);

                // Fetch details
                $infoStmt = $pdo->prepare("
                    SELECT b.*, g.title AS ground_title,
                           u_challenger.name AS challenger_name, u_challenger.email AS challenger_email,
                           u_opponent.name   AS opponent_name,   u_opponent.email   AS opponent_email
                    FROM bookings b
                    JOIN grounds g ON g.id = b.ground_id
                    JOIN users u_challenger ON u_challenger.id = b.booked_by
                    JOIN users u_opponent   ON u_opponent.id   = ?
                    WHERE b.id = ?
                ");
                $infoStmt->execute([$userId, $bookingId]);
                $info = $infoStmt->fetch();

                if ($info) {
                    createNotification($pdo, $info['booked_by'], 'challenge_accepted',
                        'Challenge Accepted! ⚡',
                        "{$info['opponent_name']} accepted your challenge at {$info['ground_title']} on {$info['slot_date']}. Match confirmed!",
                        'match_history.php'
                    );

                    createNotification($pdo, $userId, 'challenge_accepted',
                        'Challenge Confirmed!',
                        "You paid your 25% share via JazzCash and accepted the challenge at {$info['ground_title']}. Match confirmed!",
                        'match_history.php'
                    );

                    sendChallengeAcceptedEmail(
                        $info['challenger_email'], $info['challenger_name'],
                        $info['opponent_email'],   $info['opponent_name'],
                        [
                            'ground_title' => $info['ground_title'],
                            'slot_date'    => $info['slot_date'],
                            'slot_hour'    => $info['slot_hour'],
                            'booking_id'   => $bookingId,
                            'amount_paid'  => $amount,
                        ]
                    );

                    $fulfilledDetails = [
                        'booking_id'   => $bookingId,
                        'ground_title' => $info['ground_title'],
                        'slot_date'    => $info['slot_date'],
                        'slot_hour'    => $info['slot_hour'],
                        'amount'       => $amount
                    ];
                }
            }
        }

        if ($needsCommit) {
            $pdo->commit();
        }

        // Refresh transaction state
        $tx['status'] = 'success';
        if ($reference) $tx['reference'] = $reference;

        return [
            'success'     => true,
            'status'      => 'fulfilled',
            'transaction' => $tx,
            'meta_data'   => $metaData,
            'purpose'     => $purpose,
            'booking_id'  => $bookingId,
            'details'     => $fulfilledDetails
        ];

    } catch (Exception $e) {
        if ($needsCommit && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Fulfillment error: ' . $e->getMessage(),
            'status'  => 'error'
        ];
    }
}
