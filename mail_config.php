<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

function sendVerificationEmail($toEmail, $toName, $token)
{
    $mail = new PHPMailer(true);

    try {
        
        $mail->isSMTP();

        $mail->Host = 'smtp.gmail.com';

        $mail->SMTPAuth = true;

        $mail->Username = 'abdullahtariq0505@gmail.com';

        $mail->Password = 'ntqxcrseolbfngkv';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port = 587;

        $mail->setFrom('abdullahtariq0505@gmail.com', 'ArenaReserve');

        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);

        $mail->Subject = 'Verify your ArenaReserve account';

        $verificationLink =
            "http://localhost/GHR/a1/verify-email.php?token=" . $token;

        $mail->Body = "
        <h2>Welcome to ArenaReserve!</h2>

        <p>Click the button below to verify your email.</p>

        <a href='$verificationLink'
        style='padding:12px 25px;
        background:#10b981;
        color:white;
        text-decoration:none;
        border-radius:5px;'>
        Verify Email
        </a>

        <p>This link expires in 24 hours.</p>
        ";

        $mail->send();

        return true;

    } catch (Exception $e) {

    die("Mailer Error: " . $mail->ErrorInfo);

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
        $mail->Password = 'ntqxcrseolbfngkv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('abdullahtariq0505@gmail.com', 'ArenaReserve');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Reset your ArenaReserve password';

        $resetLink = "http://localhost/GHR/a1/reset-password.php?token=" . $token;

        $mail->Body = "
        <h2>Reset Your Password</h2>
        <p>You requested to reset your password. Click the button below to choose a new password.</p>
        <a href='$resetLink'
        style='padding:12px 25px;
        background:#10b981;
        color:white;
        text-decoration:none;
        border-radius:5px;'>
        Reset Password
        </a>
        <p>This link expires in 1 hour.</p>
        <p>If you did not request this, you can ignore this email.</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}