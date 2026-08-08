<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

function getBaseUrl()
{
    $host = $_SERVER['HTTP_HOST'] ?? '';

    if (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false
    ) {
        return 'http://localhost/GHR/a1';
    }

    return 'https://arenareserve.app';
}

function sendVerificationEmail($toEmail, $toName, $token)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'abdullahtariq0505@gmail.com';
        $mail->Password = 'fricnoxitxaktvjz';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('abdullahtariq0505@gmail.com', 'ArenaReserve');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Verify your ArenaReserve account';

        $verificationLink = getBaseUrl() . '/verify-email.php?token=' . urlencode($token);

        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Verify your ArenaReserve account</title>
        </head>
        <body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;'>
            <div style='max-width:600px;margin:40px auto;background:#ffffff;padding:30px;border-radius:10px;'>
                <h2 style='color:#111827;'>
                    Welcome to ArenaReserve!
                </h2>

                <p style='color:#374151;font-size:16px;line-height:1.6;'>
                    Hello " . htmlspecialchars($toName) . ",
                </p>

                <p style='color:#374151;font-size:16px;line-height:1.6;'>
                    Thank you for creating an ArenaReserve account.
                    Please verify your email address by clicking the button below.
                </p>

                <div style='text-align:center;margin:30px 0;'>
                    <a href='" . htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8') . "'
                       style='display:inline-block;padding:12px 25px;background:#10b981;color:#ffffff;text-decoration:none;border-radius:5px;font-size:16px;font-weight:bold;'>
                        Verify Email
                    </a>
                </div>

                <p style='color:#6b7280;font-size:14px;'>
                    This verification link will expire in 24 hours.
                </p>

                <p style='color:#6b7280;font-size:14px;'>
                    If you did not create an ArenaReserve account, you can safely ignore this email.
                </p>

                <hr style='border:none;border-top:1px solid #e5e7eb;margin:30px 0;'>

                <p style='color:#9ca3af;font-size:12px;text-align:center;'>
                    © ArenaReserve. All rights reserved.
                </p>
            </div>
        </body>
        </html>
        ";

        $mail->AltBody =
            "Welcome to ArenaReserve!\n\n" .
            "Hello " . $toName . ",\n\n" .
            "Please verify your email address using this link:\n\n" .
            $verificationLink . "\n\n" .
            "This verification link will expire in 24 hours.";

        $mail->send();

        return true;

    } catch (Exception $e) {
        return false;
    }
}

function sendPasswordResetEmail($toEmail, $toName, $token)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'abdullahtariq0505@gmail.com';
        $mail->Password = 'fricnoxitxaktvjz';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('abdullahtariq0505@gmail.com', 'ArenaReserve');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Reset your ArenaReserve password';

        $resetLink = getBaseUrl() . '/reset-password.php?token=' . urlencode($token);

        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Reset your ArenaReserve password</title>
        </head>
        <body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;'>
            <div style='max-width:600px;margin:40px auto;background:#ffffff;padding:30px;border-radius:10px;'>
                <h2 style='color:#111827;'>
                    Reset your ArenaReserve password
                </h2>

                <p style='color:#374151;font-size:16px;line-height:1.6;'>
                    Hello " . htmlspecialchars($toName) . ",
                </p>

                <p style='color:#374151;font-size:16px;line-height:1.6;'>
                    We received a request to reset your ArenaReserve account password.
                    Click the button below to create a new password.
                </p>

                <div style='text-align:center;margin:30px 0;'>
                    <a href='" . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . "'
                       style='display:inline-block;padding:12px 25px;background:#10b981;color:#ffffff;text-decoration:none;border-radius:5px;font-size:16px;font-weight:bold;'>
                        Reset Password
                    </a>
                </div>

                <p style='color:#6b7280;font-size:14px;'>
                    This password reset link will expire in 1 hour.
                </p>

                <p style='color:#6b7280;font-size:14px;'>
                    If you did not request a password reset, you can safely ignore this email.
                </p>

                <hr style='border:none;border-top:1px solid #e5e7eb;margin:30px 0;'>

                <p style='color:#9ca3af;font-size:12px;text-align:center;'>
                    © ArenaReserve. All rights reserved.
                </p>
            </div>
        </body>
        </html>
        ";

        $mail->AltBody =
            "Reset your ArenaReserve password\n\n" .
            "Hello " . $toName . ",\n\n" .
            "Click the link below to reset your password:\n\n" .
            $resetLink . "\n\n" .
            "This password reset link will expire in 1 hour.";

        $mail->send();

        return true;

    } catch (Exception $e) {
        return false;
    }
}