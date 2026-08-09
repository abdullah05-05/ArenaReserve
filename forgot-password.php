<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
require_once 'mail_config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['current_active_mode'] === 'Owner') {
        header("Location: owner_dashboard.php");
    } else if ($_SESSION['current_active_mode'] === 'Admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: explore.php");
    }
    exit;
}

$error = '';
$success = '';
$emailError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $emailError = 'Email Address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $emailError = 'Please enter a valid email address.';
    }

    if (empty($emailError)) {
        try {
            // Find user in database
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate a secure random token
                $token = bin2hex(random_bytes(32));
                // Set expiry to 1 hour from now
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Save to database
                $updateStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
                $updateStmt->execute([$token, $expires, $user['id']]);

                // Send email
                sendPasswordResetEmail($email, $user['name'], $token);
            }

            // Always display a generic success message to prevent user enumeration attacks
            $success = 'If that email address is registered, a password recovery link has been sent to it. Please check your inbox (and spam folder) for instructions.';
        } catch (Exception $e) {
            $error = 'An error occurred while processing your request. Please try again later.';
        }
    } else {
        $error = 'Please fix the highlighted fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - ArenaReserve</title>
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
    $page_description = 'Reset your ArenaReserve password securely. Get back to booking your favourite sports grounds in minutes.';
    include_once 'logo_head.php';
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
        <h2 class="mt-6 text-center text-3xl font-extrabold text-slate-900">Recover your password</h2>
        <p class="mt-2 text-center text-sm text-slate-600">
            Enter your registered email address and we'll send you a recovery link.
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="bg-white py-8 px-4 shadow-md sm:rounded-lg sm:px-10 border border-slate-100">
            
            <?php if (!empty($error)): ?>
                <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 text-sm text-red-700">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="mb-4 bg-green-50 border-l-4 border-green-500 p-4 text-sm text-green-700">
                    <?php echo htmlspecialchars($success); ?>
                    <div class="mt-3">
                        <a href="login.php" class="font-medium underline text-green-800 hover:text-green-900">Return to Sign In &rarr;</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($success)): ?>
                <form id="forgotForm" class="space-y-6" action="forgot-password.php" method="POST" novalidate>
                    <!-- Email Address -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700">Email Address</label>
                        <div class="mt-1">
                            <input id="email" name="email" type="email" required placeholder="your@email.com"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo !empty($emailError) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                        </div>
                        <p id="email-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($emailError); ?></p>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <button type="submit"
                                class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-colors">
                            Send Reset Link
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="mt-6 text-center">
                <a href="login.php" class="font-medium text-emerald-600 hover:text-emerald-500 text-sm">
                    Back to login
                </a>
            </div>
        </div>
    </div>

    <script>
        const forgotForm = document.getElementById('forgotForm');
        const emailInput = document.getElementById('email');
        const emailError = document.getElementById('email-error');

        function validateEmail(value) {
            if (!value.trim()) return 'Email Address is required.';
            const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!pattern.test(value.trim())) return 'Please enter a valid email address.';
            return '';
        }

        if (emailInput) {
            ['input', 'blur', 'change'].forEach((eventName) => {
                emailInput.addEventListener(eventName, () => {
                    const message = validateEmail(emailInput.value);
                    emailError.textContent = message;
                    if (message) {
                        emailInput.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
                    } else {
                        emailInput.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
                    }
                });
            });
        }

        if (forgotForm) {
            forgotForm.addEventListener('submit', function (event) {
                const message = validateEmail(emailInput.value);
                if (message) {
                    event.preventDefault();
                    emailError.textContent = message;
                    emailInput.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
                }
            });
        }
    </script>
</body>
</html>
