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

$error = '';
$success = '';
$fieldErrors = [];

if (isset($_GET['registered'])) {
    $success = 'Account created successfully! Please log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '') {
        $fieldErrors['email'] = 'Email Address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $fieldErrors['password'] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $fieldErrors['password'] = 'Password must be at least 6 characters long.';
    }

    if (empty($fieldErrors)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'Suspended') {
                    $error = 'Your account has been suspended by an administrator.';
                } else {
                    // Regenerate session ID for security
                    session_regenerate_id();

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['current_role'] = $user['current_role'];
                    $_SESSION['current_active_mode'] = $user['current_active_mode'];
                    $_SESSION['city'] = $user['city'];

                    // Direct based on mode
                    if ($user['current_active_mode'] === 'Owner') {
                        header("Location: owner_dashboard.php");
                    } else if ($user['current_active_mode'] === 'Admin') {
                        header("Location: admin_dashboard.php");
                    } else {
                        header("Location: explore.php");
                    }
                    exit;
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
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
    <title>Sign In - ArenaReserve</title>
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
<body class="bg-slate-50 min-h-screen flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <!-- Logo -->
        <div class="flex justify-center items-center gap-2">
            <span class="text-emerald-600 text-3xl font-bold flex items-center">
                <!-- SVG Trophy Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mr-1 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-2.5V20l-4 2.5L8 20v-8.5l4 2.5z" />
                </svg>
                ArenaReserve
            </span>
        </div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-slate-900">Welcome back! Sign in to your account</h2>
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
                </div>
            <?php endif; ?>

            <form class="space-y-6" action="login.php" method="POST" novalidate>
                <div id="live-login-summary" class="hidden mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700"></div>
                <!-- Email Address -->
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email Address</label>
                    <div class="mt-1">
                        <input id="email" name="email" type="email" required placeholder="your@email.com"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['email']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                    <p id="email-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['email'] ?? ''); ?></p>
                </div>

                <!-- Password -->
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <div class="mt-1">
                        <input id="password" name="password" type="password" required placeholder="••••••••"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['password']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                    <p id="password-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['password'] ?? ''); ?></p>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember_me" name="remember_me" type="checkbox"
                               class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-300 rounded">
                        <label for="remember_me" class="ml-2 block text-sm text-slate-900">Remember me</label>
                    </div>

                    <div class="text-sm">
                        <a href="#" class="font-medium text-emerald-600 hover:text-emerald-500">Forgot your password?</a>
                    </div>
                </div>

                <!-- Submit Button -->
                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-colors">
                        Sign In
                    </button>
                </div>
            </form>

            <div class="mt-6 text-center">
                <span class="text-sm text-slate-600">Don't have an account?</span>
                <a href="signup.php" class="font-medium text-emerald-600 hover:text-emerald-500 text-sm ml-1">Sign up</a>
            </div>
        </div>
    </div>

    <!-- Footer Terms of Service and Privacy Policy -->
    <script>
        const loginForm = document.querySelector('form');
        const loginInputs = {
            email: document.getElementById('email'),
            password: document.getElementById('password')
        };

        const loginErrors = {
            email: document.getElementById('email-error'),
            password: document.getElementById('password-error')
        };

        const liveSummary = document.getElementById('live-login-summary');

        const loginValidators = {
            email(value) {
                if (!value.trim()) return 'Email Address is required.';
                const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!pattern.test(value.trim())) return 'Please enter a valid email address.';
                return '';
            },
            password(value) {
                if (!value) return 'Password is required.';
                if (value.length < 6) return 'Password must be at least 6 characters long.';
                return '';
            }
        };

        function setLoginFieldState(field, message) {
            const input = loginInputs[field];
            const error = loginErrors[field];
            error.textContent = message;
            if (message) {
                input.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            } else {
                input.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            }
        }

        function updateLoginSummary() {
            const messages = Object.keys(loginInputs)
                .map((field) => loginValidators[field](loginInputs[field].value))
                .filter(Boolean);

            if (messages.length > 0) {
                liveSummary.textContent = messages[0];
                liveSummary.classList.remove('hidden');
            } else {
                liveSummary.classList.add('hidden');
                liveSummary.textContent = '';
            }
        }

        function validateLoginField(field) {
            const message = loginValidators[field](loginInputs[field].value);
            setLoginFieldState(field, message);
            updateLoginSummary();
            return !message;
        }

        Object.keys(loginInputs).forEach((field) => {
            ['input', 'blur', 'change'].forEach((eventName) => {
                loginInputs[field].addEventListener(eventName, () => validateLoginField(field));
            });
        });

        loginForm.addEventListener('submit', function (event) {
            let valid = true;
            Object.keys(loginInputs).forEach((field) => {
                if (!validateLoginField(field)) {
                    valid = false;
                }
            });

            if (!valid) {
                event.preventDefault();
            }
        });
    </script>
    <p class="mt-8 text-center text-xs text-slate-500">
        By signing in, you agree to our <a href="#" class="underline hover:text-slate-600">Terms of Service</a> and <a href="#" class="underline hover:text-slate-600">Privacy Policy</a>
    </p>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('loginForm');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');

            const showError = (input, message) => {
                const errorEl = document.getElementById(input.id + '-error');
                errorEl.textContent = message;
                errorEl.classList.remove('hidden');
                input.classList.add('border-red-500', 'focus:ring-red-500', 'focus:border-red-500');
                input.classList.remove('border-slate-300', 'focus:ring-emerald-500', 'focus:border-emerald-500');
            };

            const clearError = (input) => {
                const errorEl = document.getElementById(input.id + '-error');
                errorEl.textContent = '';
                errorEl.classList.add('hidden');
                input.classList.remove('border-red-500', 'focus:ring-red-500', 'focus:border-red-500');
                input.classList.add('border-slate-300', 'focus:ring-emerald-500', 'focus:border-emerald-500');
            };

            const validateEmail = () => {
                const val = emailInput.value.trim();
                if (!val) {
                    showError(emailInput, 'Email is required');
                    return false;
                }
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(val)) {
                    showError(emailInput, 'Please enter a valid email address');
                    return false;
                }
                clearError(emailInput);
                return true;
            };

            const validatePassword = () => {
                const val = passwordInput.value;
                if (!val) {
                    showError(passwordInput, 'Password is required');
                    return false;
                }
                clearError(passwordInput);
                return true;
            };

            emailInput.addEventListener('input', validateEmail);
            passwordInput.addEventListener('input', validatePassword);

            form.addEventListener('submit', (e) => {
                const isEmailValid = validateEmail();
                const isPasswordValid = validatePassword();

                if (!isEmailValid || !isPasswordValid) {
                    e.preventDefault(); // Stop form submission
                }
            });
        });
    </script>
</body>
</html>
