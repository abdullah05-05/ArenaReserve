<?php
require_once 'session_config.php';

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

    $success = true;
    $message = "Email Verified Successfully!";
    $sub_message = "Your email address has been verified. You can now log in to your ArenaReserve account.";

} else {

    $success = false;
    $message = "Verification link is invalid or expired.";
    $sub_message = "The verification link you clicked is either invalid or has expired. Please request a new verification email from the sign-in page.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
    <?php
    $page_description = 'Verify your email address to activate your ArenaReserve account and start booking sports grounds.';
    include 'logo_head.php';
    ?>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col justify-start md:justify-center py-6 md:py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <!-- Logo -->
        <div class="flex justify-center items-center gap-2">
            <a href="login.php" class="text-emerald-600 text-3xl font-bold flex items-center">
                <!-- SVG Trophy Icon -->
                <?php echo get_logo_markup('h-8 w-8 mr-1 inline-block'); ?>
                ArenaReserve
            </a>
        </div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-slate-900">
            <?php echo $success ? 'Email Verified!' : 'Verification Failed'; ?>
        </h2>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="bg-white py-8 px-4 shadow-md sm:rounded-lg sm:px-10 border border-slate-100">

            <div class="mb-4 border-l-4 p-4 text-sm <?php echo $success ? 'bg-green-50 border-green-500 text-green-700' : 'bg-red-50 border-red-500 text-red-700'; ?>">
                <p class="font-semibold text-lg"><?php echo htmlspecialchars($message); ?></p>
                <p class="mt-1"><?php echo htmlspecialchars($sub_message); ?></p>
            </div>

            <div class="mt-6 text-center">
                <a href="login.php" class="font-medium text-emerald-600 hover:text-emerald-500 text-sm">
                    <?php echo $success ? 'Proceed to login &rarr;' : 'Back to login'; ?>
                </a>
            </div>

            <div class="mt-6 pt-4 border-t border-slate-100 flex flex-wrap items-center justify-center gap-3 text-xs text-slate-400">
                <a href="terms.php" class="hover:text-emerald-600 transition-colors">Terms</a>
                <span>•</span>
                <a href="privacy.php" class="hover:text-emerald-600 transition-colors">Privacy</a>
                <span>•</span>
                <a href="refund-policy.php" class="hover:text-emerald-600 transition-colors">Refunds</a>
                <span>•</span>
                <a href="cancellation-policy.php" class="hover:text-emerald-600 transition-colors">Cancellation</a>
                <span>•</span>
                <a href="contact.php" class="hover:text-emerald-600 transition-colors">Support (03137970801)</a>
            </div>
        </div>
    </div>

    <p class="mt-8 text-center text-xs text-slate-500">
        By using ArenaReserve, you agree to our <a href="terms.php" class="underline hover:text-slate-600">Terms of Service</a> and <a href="privacy.php" class="underline hover:text-slate-600">Privacy Policy</a>
    </p>
</body>
</html>