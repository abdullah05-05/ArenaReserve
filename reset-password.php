<?php
session_start();
require_once 'db.php';

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

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = '';
$fieldErrors = [];
$user = null;

if (empty($token)) {
    $error = 'Invalid or missing password recovery token.';
} else {
    try {
        // Find user by token and verify it hasn't expired
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'The password recovery token is invalid or has expired. Please request a new link.';
        }
    } catch (Exception $e) {
        $error = 'An error occurred. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($password === '') {
        $fieldErrors['password'] = 'New password is required.';
    } elseif (strlen($password) < 6) {
        $fieldErrors['password'] = 'Password must be at least 6 characters.';
    }

    if ($confirm_password === '') {
        $fieldErrors['confirm_password'] = 'Confirm password is required.';
    } elseif ($password !== $confirm_password) {
        $fieldErrors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($fieldErrors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            
            // Update password and clear reset token columns
            $updateStmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
            $updateStmt->execute([$hashed_password, $user['id']]);
            
            $success = 'Your password has been successfully reset! You can now sign in with your new password.';
            $user = null; // Hide form on success
        } catch (Exception $e) {
            $error = 'Failed to update password. Please try again.';
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
    <title>Reset Password - ArenaReserve</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col justify-start md:justify-center py-6 md:py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <!-- Logo -->
        <div class="flex justify-center items-center gap-2">
            <a href="login.php" class="text-emerald-600 text-3xl font-bold flex items-center">
                <!-- SVG Trophy Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z" />
                </svg>
                ArenaReserve
            </a>
        </div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-slate-900">Reset your password</h2>
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
                        <a href="login.php" class="font-medium underline text-green-800 hover:text-green-900">Proceed to login &rarr;</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($user): ?>
                <form id="resetForm" class="space-y-6" action="reset-password.php" method="POST" novalidate>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <!-- New Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700">New Password</label>
                        <div class="mt-1">
                            <input id="password" name="password" type="password" required placeholder="••••••••"
                                   class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['password']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                        </div>
                        <p id="password-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['password'] ?? ''); ?></p>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-slate-700">Confirm New Password</label>
                        <div class="mt-1">
                            <input id="confirm_password" name="confirm_password" type="password" required placeholder="••••••••"
                                   class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['confirm_password']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                        </div>
                        <p id="confirm-password-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['confirm_password'] ?? ''); ?></p>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <button type="submit"
                                class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-colors">
                            Update Password
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
        const resetForm = document.getElementById('resetForm');
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');
        
        const passwordError = document.getElementById('password-error');
        const confirmError = document.getElementById('confirm-password-error');

        const validators = {
            password(value) {
                if (!value) return 'New password is required.';
                if (value.length < 6) return 'Password must be at least 6 characters.';
                return '';
            },
            confirm(value) {
                if (!value) return 'Confirm password is required.';
                if (value !== passwordInput.value) return 'Passwords do not match.';
                return '';
            }
        };

        function setFieldState(input, errorEl, message) {
            errorEl.textContent = message;
            if (message) {
                input.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            } else {
                input.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            }
        }

        if (passwordInput && confirmInput) {
            ['input', 'blur', 'change'].forEach((eventName) => {
                passwordInput.addEventListener(eventName, () => {
                    const msg = validators.password(passwordInput.value);
                    setFieldState(passwordInput, passwordError, msg);
                    
                    if (confirmInput.value) {
                        const confirmMsg = validators.confirm(confirmInput.value);
                        setFieldState(confirmInput, confirmError, confirmMsg);
                    }
                });
                
                confirmInput.addEventListener(eventName, () => {
                    const msg = validators.confirm(confirmInput.value);
                    setFieldState(confirmInput, confirmError, msg);
                });
            });
        }

        if (resetForm) {
            resetForm.addEventListener('submit', function (event) {
                const passMsg = validators.password(passwordInput.value);
                const confMsg = validators.confirm(confirmInput.value);
                
                setFieldState(passwordInput, passwordError, passMsg);
                setFieldState(confirmInput, confirmError, confMsg);
                
                if (passMsg || confMsg) {
                    event.preventDefault();
                }
            });
        }
    </script>
</body>
</html>
