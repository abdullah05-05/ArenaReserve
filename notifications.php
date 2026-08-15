<?php
/**
 * notifications.php
 * Central helper: in-app notification creation + all email notification functions.
 * Include this file wherever you need to fire a notification.
 *
 * Requires: $pdo (PDO connection), PHPMailer via vendor/autoload.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
if (!function_exists('getBaseUrl')) {
    require_once __DIR__ . '/mail_config.php';
}

/* ═══════════════════════════════════════════════════════════════════
   IN-APP NOTIFICATION
═══════════════════════════════════════════════════════════════════ */

/**
 * createNotification()
 * Inserts a row into the notifications table for one user.
 *
 * @param PDO    $pdo
 * @param int    $user_id  Recipient user ID
 * @param string $type     Machine-readable type slug (e.g. 'booking_confirmed')
 * @param string $title    Short heading shown in bell dropdown
 * @param string $message  Body text
 * @param string $link     Optional URL the user is taken to when clicking
 */
function createNotification(PDO $pdo, int $user_id, string $type, string $title, string $message, string $link = ''): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $type, $title, $message, $link ?: null]);
    } catch (Throwable $e) {
        // Silently fail — notifications must never break the main flow
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: TEAM CHALLENGE SENT (notify the challenged user)
═══════════════════════════════════════════════════════════════════ */

/**
 * @param array $details Keys: ground_title, slot_date, slot_hour, booking_id, challenger_name, message
 */
function sendTeamChallengeSentEmail(string $toEmail, string $toName, array $details): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '⚡ You\'ve Been Challenged! — ArenaReserve';

        $slotDate   = date('l, d M Y', strtotime($details['slot_date']));
        $slotTime   = sprintf('%02d:00 – %02d:00', $details['slot_hour'], $details['slot_hour'] + 1);
        $acceptLink = getBaseUrl() . '/match_history.php';
        $msgText    = !empty($details['message']) ? htmlspecialchars($details['message']) : 'Game on! See you on the field! 🏆';

        $mail->Body = _emailHeader('Team Challenge Received') . "
            <h2 style='color:#ea580c;font-size:22px;margin:0 0 8px;'>You've Been Challenged! ⚡</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ",
                <strong>" . htmlspecialchars($details['challenger_name']) . "</strong>
                has challenged your team to a match on ArenaReserve!
            </p>
            <div style='background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:14px 18px;margin:0 0 20px;'>
                <p style='color:#9a3412;font-size:14px;font-style:italic;margin:0;'>\"" . $msgText . "\"</p>
            </div>
            " . _detailTable([
                '🏟️ Venue'        => htmlspecialchars($details['ground_title']),
                '📅 Date'         => $slotDate,
                '🕐 Time'         => $slotTime,
                '⚡ Challenger'   => htmlspecialchars($details['challenger_name']),
            ]) . "
            <p style='color:#475569;font-size:14px;'>Accept the challenge from your Match History page. You'll need to pay 50% of the slot price to confirm.</p>
            " . _btn('View & Accept Challenge', $acceptLink, '#ea580c') . "
        " . _emailFooter();

        $mail->AltBody = "You've Been Challenged!\nHi {$toName},\n{$details['challenger_name']} challenged you!\nVenue: {$details['ground_title']}\nDate: {$slotDate}\nAccept: {$acceptLink}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}


/* ═══════════════════════════════════════════════════════════════════
   SHARED MAILER SETUP
═══════════════════════════════════════════════════════════════════ */

function _buildMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = getenv('BREVO_SMTP_HOST');
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('BREVO_SMTP_USERNAME');
    $mail->Password   = getenv('BREVO_SMTP_PASSWORD');
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int) getenv('BREVO_SMTP_PORT');
    $mail->setFrom(getenv('BREVO_FROM_EMAIL'), getenv('BREVO_FROM_NAME'));
    $mail->isHTML(true);
    return $mail;
}

function _emailHeader(string $title): string
{
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><title>{$title}</title></head>
    <body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;'>
    <div style='max-width:620px;margin:40px auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);'>
        <div style='background:linear-gradient(135deg,#064e3b 0%,#10b981 100%);padding:28px 32px;'>
            <div style='display:flex;align-items:center;gap:10px;'>
                <span style='font-size:26px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;'>ArenaReserve</span>
            </div>
        </div>
        <div style='padding:32px;'>
    ";
}

function _emailFooter(): string
{
    return "
        </div>
        <div style='background:#f8fafc;padding:20px 32px;border-top:1px solid #e2e8f0;text-align:center;'>
            <p style='color:#94a3b8;font-size:12px;margin:0;'>© " . date('Y') . " ArenaReserve. All rights reserved.</p>
            <p style='color:#cbd5e1;font-size:11px;margin:6px 0 0;'>You're receiving this because you have an account on ArenaReserve.</p>
        </div>
    </div>
    </body></html>
    ";
}

function _btn(string $text, string $url, string $color = '#10b981'): string
{
    return "<div style='text-align:center;margin:28px 0;'>
        <a href='" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "'
           style='display:inline-block;padding:14px 32px;background:{$color};color:#ffffff;text-decoration:none;
                  border-radius:8px;font-size:15px;font-weight:700;letter-spacing:0.2px;'>
            {$text}
        </a>
    </div>";
}

function _detailRow(string $label, string $value): string
{
    return "<tr>
        <td style='padding:10px 14px;font-size:13px;color:#64748b;font-weight:600;width:130px;'>{$label}</td>
        <td style='padding:10px 14px;font-size:13px;color:#1e293b;font-weight:500;'>{$value}</td>
    </tr>";
}

function _detailTable(array $rows): string
{
    $html = "<table style='width:100%;border-collapse:collapse;background:#f8fafc;border-radius:10px;overflow:hidden;margin:20px 0;border:1px solid #e2e8f0;'>";
    foreach ($rows as $label => $value) {
        $html .= _detailRow($label, $value);
    }
    $html .= "</table>";
    return $html;
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: BOOKING CONFIRMED
═══════════════════════════════════════════════════════════════════ */

/**
 * @param array $details Keys: ground_title, slot_date, slot_hour, booking_type, amount_paid, booking_id
 */
function sendBookingConfirmedEmail(string $toEmail, string $toName, array $details): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '✅ Booking Confirmed — ArenaReserve #' . ($details['booking_id'] ?? '');

        $slotTime  = sprintf('%02d:00 – %02d:00', $details['slot_hour'], $details['slot_hour'] + 1);
        $slotDate  = date('l, d M Y', strtotime($details['slot_date']));
        $typeLabel = ucwords(str_replace('_', ' ', $details['booking_type']));
        $amount    = 'PKR ' . number_format($details['amount_paid'], 2);
        $profileLink = getBaseUrl() . '/match_history.php';

        $mail->Body = _emailHeader('Booking Confirmed') . "
            <h2 style='color:#064e3b;font-size:22px;margin:0 0 8px;'>Your slot is booked! 🎉</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ", your booking has been confirmed. See you on the field!
            </p>
            " . _detailTable([
                '📋 Booking ID'   => '#' . ($details['booking_id'] ?? '—'),
                '🏟️ Venue'        => htmlspecialchars($details['ground_title']),
                '📅 Date'         => $slotDate,
                '🕐 Time'         => $slotTime,
                '🎯 Type'         => $typeLabel,
                '💳 Amount Paid'  => $amount,
            ]) .
            _btn('View My Bookings', $profileLink) . "
            <p style='color:#94a3b8;font-size:12px;margin:16px 0 0;text-align:center;'>
                Need to cancel? You can do so from your Match History page up to 24 hours before the slot.
            </p>
        " . _emailFooter();

        $mail->AltBody = "Booking Confirmed!\nHi {$toName},\nVenue: {$details['ground_title']}\nDate: {$slotDate}\nTime: {$slotTime}\nAmount: {$amount}\nView: {$profileLink}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: BOOKING CANCELLED
═══════════════════════════════════════════════════════════════════ */

/**
 * @param array $details Keys: ground_title, slot_date, slot_hour, booking_id
 */
function sendBookingCancelledEmail(string $toEmail, string $toName, array $details, float $refundAmount): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '❌ Booking Cancelled — ArenaReserve #' . ($details['booking_id'] ?? '');

        $slotDate    = date('l, d M Y', strtotime($details['slot_date']));
        $slotTime    = sprintf('%02d:00 – %02d:00', $details['slot_hour'], $details['slot_hour'] + 1);
        $refundText  = $refundAmount > 0 ? 'PKR ' . number_format($refundAmount, 2) : 'No refund (cancellation policy)';
        $walletLink  = getBaseUrl() . '/wallet.php';

        $mail->Body = _emailHeader('Booking Cancelled') . "
            <h2 style='color:#dc2626;font-size:22px;margin:0 0 8px;'>Booking Cancelled</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ", your booking has been successfully cancelled.
            </p>
            " . _detailTable([
                '📋 Booking ID'  => '#' . ($details['booking_id'] ?? '—'),
                '🏟️ Venue'       => htmlspecialchars($details['ground_title']),
                '📅 Date'        => $slotDate,
                '🕐 Time'        => $slotTime,
                '💰 Refund'      => $refundText,
            ]) . "
            " . ($refundAmount > 0 ? "<div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;margin:16px 0;'>
                <p style='color:#166534;font-size:14px;font-weight:600;margin:0;'>
                    💚 Refund of {$refundText} has been added to your ArenaReserve wallet.
                </p>
            </div>" : '') . "
            " . _btn('Check My Wallet', $walletLink, '#475569') . "
        " . _emailFooter();

        $mail->AltBody = "Booking Cancelled!\nHi {$toName},\nVenue: {$details['ground_title']}\nDate: {$slotDate}\nRefund: {$refundText}\nWallet: {$walletLink}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: CHALLENGE ACCEPTED
═══════════════════════════════════════════════════════════════════ */

/**
 * Sends to BOTH challenger and opponent.
 * @param array $details Keys: ground_title, slot_date, slot_hour, booking_id, amount_paid
 */
function sendChallengeAcceptedEmail(
    string $challengerEmail, string $challengerName,
    string $opponentEmail,   string $opponentName,
    array  $details
): void {
    try {
        $slotDate  = date('l, d M Y', strtotime($details['slot_date']));
        $slotTime  = sprintf('%02d:00 – %02d:00', $details['slot_hour'], $details['slot_hour'] + 1);
        $histLink  = getBaseUrl() . '/match_history.php';

        $tableRows = [
            '📋 Booking ID'  => '#' . ($details['booking_id'] ?? '—'),
            '🏟️ Venue'       => htmlspecialchars($details['ground_title']),
            '📅 Date'        => $slotDate,
            '🕐 Time'        => $slotTime,
        ];

        foreach ([
            [$challengerEmail, $challengerName, "Your challenge was accepted by {$opponentName}!"],
            [$opponentEmail,   $opponentName,   "You accepted a challenge from {$challengerName}!"],
        ] as [$email, $name, $subMsg]) {
            $mail = _buildMailer();
            $mail->addAddress($email, $name);
            $mail->Subject = '🎉 Challenge Accepted — ArenaReserve #' . ($details['booking_id'] ?? '');
            $mail->Body = _emailHeader('Challenge Accepted') . "
                <h2 style='color:#064e3b;font-size:22px;margin:0 0 8px;'>Challenge Accepted! ⚡</h2>
                <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                    Hi " . htmlspecialchars($name) . ", {$subMsg} The slot is now confirmed for both teams. Game on!
                </p>
                " . _detailTable($tableRows) . "
                " . _btn('View Match Details', $histLink) . "
            " . _emailFooter();
            $mail->AltBody = "Challenge Accepted!\nHi {$name},\n{$subMsg}\nVenue: {$details['ground_title']}\nDate: {$slotDate}\nTime: {$slotTime}";
            $mail->send();
        }
    } catch (Throwable $e) {
        // Silently fail
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: OPEN CHALLENGE POSTED (notify owner)
═══════════════════════════════════════════════════════════════════ */

function sendNewBookingOwnerEmail(string $toEmail, string $toName, array $details): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '🏟️ New Booking on Your Venue — ' . ($details['ground_title'] ?? '');

        $slotDate = date('l, d M Y', strtotime($details['slot_date']));
        $slotTime = sprintf('%02d:00 – %02d:00', $details['slot_hour'], $details['slot_hour'] + 1);
        $dashLink = getBaseUrl() . '/owner_dashboard.php';

        $mail->Body = _emailHeader('New Booking') . "
            <h2 style='color:#064e3b;font-size:22px;margin:0 0 8px;'>New Booking Received! 🏟️</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ", a player has booked a slot at your venue.
            </p>
            " . _detailTable([
                '🏟️ Venue'      => htmlspecialchars($details['ground_title']),
                '📅 Date'       => $slotDate,
                '🕐 Time'       => $slotTime,
                '🎯 Type'       => ucwords(str_replace('_', ' ', $details['booking_type'])),
                '👤 Booked By'  => htmlspecialchars($details['player_name']),
            ]) .
            _btn('View Owner Dashboard', $dashLink) . "
        " . _emailFooter();

        $mail->AltBody = "New Booking!\nHi {$toName},\nVenue: {$details['ground_title']}\nDate: {$slotDate}\nTime: {$slotTime}\nBy: {$details['player_name']}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: VENUE APPROVED
═══════════════════════════════════════════════════════════════════ */

function sendVenueApprovedEmail(string $toEmail, string $toName, string $groundTitle): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '✅ Venue Approved — ' . $groundTitle;

        $dashLink = getBaseUrl() . '/owner_dashboard.php';
        $mail->Body = _emailHeader('Venue Approved') . "
            <h2 style='color:#064e3b;font-size:22px;margin:0 0 8px;'>Your Venue is Live! 🎉</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ", congratulations! Your venue
                <strong>" . htmlspecialchars($groundTitle) . "</strong> has been approved by our admin team
                and is now live on ArenaReserve. Players can start booking your slots!
            </p>
            " . _btn('Go to Dashboard', $dashLink) . "
        " . _emailFooter();

        $mail->AltBody = "Venue Approved!\nHi {$toName},\nYour venue '{$groundTitle}' is now live on ArenaReserve.\nDashboard: {$dashLink}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: VENUE REJECTED
═══════════════════════════════════════════════════════════════════ */

function sendVenueRejectedEmail(string $toEmail, string $toName, string $groundTitle, string $reason): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '❌ Venue Review Update — ' . $groundTitle;

        $dashLink = getBaseUrl() . '/owner_dashboard.php';
        $mail->Body = _emailHeader('Venue Review Update') . "
            <h2 style='color:#dc2626;font-size:22px;margin:0 0 8px;'>Venue Needs Revision</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ", unfortunately your venue
                <strong>" . htmlspecialchars($groundTitle) . "</strong> could not be approved at this time.
            </p>
            <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;margin:16px 0;'>
                <p style='color:#991b1b;font-size:14px;font-weight:600;margin:0 0 4px;'>Reason:</p>
                <p style='color:#7f1d1d;font-size:14px;margin:0;'>" . htmlspecialchars($reason) . "</p>
            </div>
            <p style='color:#475569;font-size:14px;'>Please address the issues and re-submit your venue for review.</p>
            " . _btn('View Dashboard', $dashLink, '#475569') . "
        " . _emailFooter();

        $mail->AltBody = "Venue Review Update\nHi {$toName},\nVenue '{$groundTitle}' was not approved.\nReason: {$reason}\nDashboard: {$dashLink}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: WALLET DEPOSIT APPROVED
═══════════════════════════════════════════════════════════════════ */

function sendWalletDepositApprovedEmail(string $toEmail, string $toName, float $amount): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '💰 Wallet Top-Up Approved — ArenaReserve';

        $walletLink = getBaseUrl() . '/wallet.php';
        $amountFmt  = 'PKR ' . number_format($amount, 2);

        $mail->Body = _emailHeader('Wallet Top-Up Approved') . "
            <h2 style='color:#064e3b;font-size:22px;margin:0 0 8px;'>Wallet Credited! 💚</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ", your wallet top-up request has been approved.
                <strong>{$amountFmt}</strong> has been added to your ArenaReserve wallet balance.
            </p>
            " . _btn('View Wallet', $walletLink) . "
        " . _emailFooter();

        $mail->AltBody = "Wallet Top-Up Approved!\nHi {$toName},\n{$amountFmt} credited to your wallet.\nWallet: {$walletLink}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   EMAIL: WALLET DEPOSIT REJECTED
═══════════════════════════════════════════════════════════════════ */

function sendWalletDepositRejectedEmail(string $toEmail, string $toName, float $amount, string $reason): bool
{
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = '❌ Wallet Top-Up Rejected — ArenaReserve';

        $walletLink = getBaseUrl() . '/wallet.php';
        $amountFmt  = 'PKR ' . number_format($amount, 2);

        $mail->Body = _emailHeader('Wallet Top-Up Rejected') . "
            <h2 style='color:#dc2626;font-size:22px;margin:0 0 8px;'>Top-Up Request Rejected</h2>
            <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 20px;'>
                Hi " . htmlspecialchars($toName) . ", unfortunately your wallet top-up request of
                <strong>{$amountFmt}</strong> could not be approved.
            </p>
            <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;margin:16px 0;'>
                <p style='color:#991b1b;font-size:14px;font-weight:600;margin:0 0 4px;'>Reason:</p>
                <p style='color:#7f1d1d;font-size:14px;margin:0;'>" . htmlspecialchars($reason) . "</p>
            </div>
            <p style='color:#475569;font-size:14px;'>Please submit a new top-up request with the correct details.</p>
            " . _btn('Go to Wallet', $walletLink, '#475569') . "
        " . _emailFooter();

        $mail->AltBody = "Wallet Top-Up Rejected\nHi {$toName},\nRequest for {$amountFmt} was rejected.\nReason: {$reason}\nWallet: {$walletLink}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
