<?php

require_once 'db.php';

if (!isset($_GET['token'])) {
    die("Invalid verification link.");
}

$token = $_GET['token'];

$stmt = $pdo->prepare("
SELECT id
FROM users
WHERE verification_token = ?
AND verification_token_expires > NOW()
");

$stmt->execute([$token]);

$user = $stmt->fetch();

if ($user) {

    $stmt = $pdo->prepare("
    UPDATE users
    SET
        email_verified = 1,
        verification_token = NULL,
        verification_token_expires = NULL
    WHERE id = ?
    ");

    $stmt->execute([$user['id']]);

    echo "<h2>Email Verified Successfully!</h2>";
    echo "<a href='login.php'>Click here to Login</a>";

} else {

    echo "<h2>Verification link is invalid or expired.</h2>";

}