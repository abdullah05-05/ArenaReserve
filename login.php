<?php
session_start();
require_once 'db.php';
require_once 'logo_helper.php';
$config = file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
$google_client_id = $config['google_client_id']
    ?? getenv('GOOGLE_CLIENT_ID')
    ?? 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com';
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
    // ── Google Sign-In verification ──────────────────────────────────────────
    if (isset($_POST['google_credential'])) {
        $id_token = $_POST['google_credential'];
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($id_token);
        
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5
            ]
        ]);
        
        $responseJson = @file_get_contents($url, false, $ctx);
        if ($responseJson) {
            $tokenInfo = json_decode($responseJson, true);
            $issuers = ['accounts.google.com', 'https://accounts.google.com'];
            
            if (isset($tokenInfo['email']) && in_array($tokenInfo['iss'] ?? '', $issuers)) {
                $email = $tokenInfo['email'];
                $name = $tokenInfo['name'] ?? 'Google User';
                $picture = $tokenInfo['picture'] ?? null;
                
                try {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();
                    
                    if ($user) {
                        if ($user['status'] === 'Blocked' || $user['status'] === 'Suspended') {
                            $error = 'Your account has been blocked by the administrator. Please contact support for assistance.';
                        } else {
                            session_regenerate_id();
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['name'] = $user['name'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['current_role'] = $user['current_role'] ?? $user['current_active_mode'] ?? 'Player';
                            $_SESSION['current_active_mode'] = $user['current_active_mode'] ?? 'Player';
                            $_SESSION['city'] = $user['city'];
                            $_SESSION['profile_picture'] = $user['profile_picture'] ?? $picture ?? null;
                            
                            if ($_SESSION['current_active_mode'] === 'Owner') {
                                header("Location: owner_dashboard.php");
                            } else if ($_SESSION['current_active_mode'] === 'Admin') {
                                header("Location: admin_dashboard.php");
                            } else {
                                header("Location: explore.php");
                            }
                            exit;
                        }
                    } else {
                        $role = 'Player';
                        $random_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                        
                        $pdo->beginTransaction();
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO users (
                                `name`, `email`, `phone`, `city`, `password`, `current_role`, `current_active_mode`, `email_verified`, `profile_picture`
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
                        ");
                        $stmt->execute([$name, $email, '', 'Lahore', $random_password, $role, $role, $picture]);
                        $user_id = $pdo->lastInsertId();
                        
                        $stmt = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, frozen_escrow_balance) VALUES (?, 0.00, 0.00)");
                        $stmt->execute([$user_id]);
                        
                        $pdo->commit();
                        
                        session_regenerate_id();
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['name'] = $name;
                        $_SESSION['email'] = $email;
                        $_SESSION['current_role'] = $role;
                        $_SESSION['current_active_mode'] = $role;
                        $_SESSION['city'] = 'Lahore';
                        $_SESSION['profile_picture'] = $picture;
                        
                        header("Location: explore.php");
                        exit;
                    }
                } catch (Exception $e) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Database error. Please try again.';
                }
            } else {
                $error = 'Invalid Google credential token.';
            }
        } else {
            $error = 'Failed to verify Google account credentials.';
        }
    }

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
                if ($user['email_verified'] == 0) {

                   $error = "Please verify your email before logging in.";
  
                   }
                else {
                if ($user['status'] === 'Blocked' || $user['status'] === 'Suspended') {
                    $error = 'Your account has been blocked by the administrator. Please contact support for assistance.';
                } else {
                    // Regenerate session ID for security
                    session_regenerate_id();

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['current_role'] = $user['current_role'] ?? $user['current_active_mode'] ?? 'Player';
                    $_SESSION['current_active_mode'] = $user['current_active_mode'] ?? 'Player';
                    $_SESSION['city'] = $user['city'];
                    $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;

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
    <!-- Google Sign-In SDK -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        .password-field-wrap {
            position: relative;
            display: block;
        }

        .password-field-wrap input {
            padding-right: 2.9rem;
        }

        .password-field-wrap button {
            position: absolute;
            top: 50%;
            right: 0.75rem;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            padding: 0;
            border: none;
            background: transparent;
            cursor: pointer;
            color: #64748b;
            z-index: 20;
            pointer-events: auto;
        }

        .password-field-wrap button:hover {
            color: #475569;
        }

        .password-field-wrap button:focus {
            outline: none;
        }

        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }

        input[type="password"]::-webkit-textfield-decoration-container {
            display: none;
        }
    </style>
    <?php include_once 'logo_head.php'; ?>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col justify-start md:justify-center py-6 md:py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <!-- Logo -->
        <div class="flex justify-center items-center gap-2">
            <span class="text-emerald-600 text-3xl font-bold flex items-center">
                <!-- SVG Trophy Icon -->
                <?php echo get_logo_markup('h-8 w-8 mr-1 inline-block'); ?>
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

            <form id="loginForm" class="space-y-6" action="login.php" method="POST" novalidate>
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
                    <div class="mt-1 password-field-wrap">
                        <input id="password" name="password" type="password" required placeholder="••••••••"
                               class="appearance-none block w-full px-3 py-2 pr-10 border border-slate-300<?php echo isset($fieldErrors['password']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                        <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false" class="flex items-center justify-center text-slate-400 hover:text-slate-600 focus:outline-none">
                            <svg class="show-password-icon h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg class="hide-password-icon hidden h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 012.223-3.99m2.16-1.85A9.956 9.956 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.99 9.99 0 01-4.043 5.179M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                            </svg>
                        </button>
                    </div>
                    <p id="password-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['password'] ?? ''); ?></p>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center">
                        <input id="remember_me" name="remember_me" type="checkbox"
                               class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-300 rounded">
                        <label for="remember_me" class="ml-2 block text-sm text-slate-900">Remember me</label>
                    </div>

                    <div class="text-sm">
                        <a href="forgot-password.php" class="font-medium text-emerald-600 hover:text-emerald-500">Forgot your password?</a>
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

            <!-- Google Sign-In Integration -->
            <div class="mt-6">
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-2 bg-white text-slate-500 font-medium">Or continue with</span>
                    </div>
                </div>

                <div class="mt-6 flex justify-center">
                    <div id="g_id_onload"
                         data-client_id="<?php echo htmlspecialchars($google_client_id); ?>"
                         data-context="signin"
                         data-ux_mode="popup"
                         data-callback="handleCredentialResponse"
                         data-auto_prompt="false">
                    </div>
                    <div class="g_id_signin"
                         data-type="standard"
                         data-shape="rectangular"
                         data-theme="outline"
                         data-text="continue_with"
                         data-size="large"
                         data-logo_alignment="left"
                         data-width="320">
                    </div>
                </div>
            </div>

            <!-- Hidden Google Form -->
            <form id="googleLoginForm" action="login.php" method="POST" class="hidden">
                <input type="hidden" id="googleCredential" name="google_credential" value="">
            </form>

            <div class="mt-6 text-center">
                <span class="text-sm text-slate-600">Don't have an account?</span>
                <a href="signup.php" class="font-medium text-emerald-600 hover:text-emerald-500 text-sm ml-1">Sign up</a>
            </div>
        </div>
    </div>

    <script>
        function handleCredentialResponse(response) {
            document.getElementById('googleCredential').value = response.credential;
            document.getElementById('googleLoginForm').submit();
        }

        const loginForm = document.getElementById('loginForm');
        const loginInputs = {
            email: document.getElementById('email'),
            password: document.getElementById('password')
        };

        const loginErrors = {
            email: document.getElementById('email-error'),
            password: document.getElementById('password-error')
        };

        const liveSummary = document.getElementById('live-login-summary');
        const togglePasswordButton = document.getElementById('toggle-password');
        const showPasswordIcon = togglePasswordButton?.querySelector('.show-password-icon');
        const hidePasswordIcon = togglePasswordButton?.querySelector('.hide-password-icon');

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

        if (togglePasswordButton && showPasswordIcon && hidePasswordIcon) {
            togglePasswordButton.addEventListener('click', () => {
                const isPasswordHidden = loginInputs.password.type === 'password';
                loginInputs.password.type = isPasswordHidden ? 'text' : 'password';
                togglePasswordButton.setAttribute('aria-label', isPasswordHidden ? 'Hide password' : 'Show password');
                togglePasswordButton.setAttribute('aria-pressed', String(isPasswordHidden));
                showPasswordIcon.classList.toggle('hidden', !isPasswordHidden);
                hidePasswordIcon.classList.toggle('hidden', isPasswordHidden);
            });
        }

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
</body>
</html>
