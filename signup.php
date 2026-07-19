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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $fieldErrors = [];

    if ($name === '') {
        $fieldErrors['name'] = 'Full Name is required.';
    } elseif (strlen($name) < 2) {
        $fieldErrors['name'] = 'Full Name must be at least 2 characters long.';
    } elseif (preg_match('/\d/', $name)) {
        $fieldErrors['name'] = 'Full Name cannot contain numbers.';
    }

    if ($email === '') {
        $fieldErrors['email'] = 'Email Address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Please enter a valid email address.';
    }

    if ($phone === '') {
        $fieldErrors['phone'] = 'Phone Number is required.';
    } else {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^[0-9]{11}$/', $cleanPhone)) {
            $fieldErrors['phone'] = 'Phone Number must contain exactly 11 digits.';
        }
    }

    if ($role === '') {
        $fieldErrors['role'] = 'Please select a role.';
    } elseif (!in_array($role, ['Player', 'Owner'], true)) {
        $fieldErrors['role'] = 'Invalid role selection.';
    }

    if ($password === '') {
        $fieldErrors['password'] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $fieldErrors['password'] = 'Password must be at least 6 characters long.';
    }

    if ($confirm_password === '') {
        $fieldErrors['confirm_password'] = 'Confirm Password is required.';
    } elseif ($password !== $confirm_password) {
        $fieldErrors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($fieldErrors)) {
        try {
            // Check if email already registered
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email already registered.';
            } else {
                // Insert User
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO users (`name`, `email`, `phone`, `password`, `current_role`, `current_active_mode`) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $phone, $hashed_password, $role, $role]);
                $user_id = $pdo->lastInsertId();

                // Initialize Wallet
                $stmt = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, frozen_escrow_balance) VALUES (?, 0.00, 0.00)");
                $stmt->execute([$user_id]);

                $pdo->commit();
                $success = 'Account created successfully! You can now log in.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Something went wrong. Please try again.';
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
    <title>Sign Up - ArenaReserve</title>
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
        <h2 class="mt-6 text-center text-3xl font-extrabold text-slate-900">Create your account to get started</h2>
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
                    <div class="mt-2">
                        <a href="login.php" class="font-medium underline text-green-800 hover:text-green-900">Proceed to login &rarr;</a>
                    </div>
                </div>
            <?php endif; ?>

            <form class="space-y-6" action="signup.php" method="POST" novalidate>
                <div id="live-validation-summary" class="hidden mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700"></div>
                <!-- Full Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">Full Name</label>
                    <div class="mt-1">
                        <input id="name" name="name" type="text" required placeholder="John Doe"
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['name']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                    <p id="name-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['name'] ?? ''); ?></p>
                </div>

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

                <!-- Phone Number -->
                <div>
                    <label for="phone" class="block text-sm font-medium text-slate-700">Phone Number</label>
                    <div class="mt-1">
                        <input id="phone" name="phone" type="text" required placeholder="+92 300 1234567"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['phone']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                    <p id="phone-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['phone'] ?? ''); ?></p>
                </div>

                <!-- Role Selection -->
                <div>
                    <label for="role" class="block text-sm font-medium text-slate-700">I want to</label>
                    <div class="mt-1">
                        <select id="role" name="role"
                                class="block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['role']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm bg-white">
                            <option value="Player" <?php echo (($_POST['role'] ?? '') === 'Player') ? 'selected' : ''; ?>>Book grounds as a Player</option>
                            <option value="Owner" <?php echo (($_POST['role'] ?? '') === 'Owner') ? 'selected' : ''; ?>>List grounds as an Owner</option>
                        </select>
                    </div>
                    <p id="role-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['role'] ?? ''); ?></p>
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

                <!-- Confirm Password -->
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-slate-700">Confirm Password</label>
                    <div class="mt-1">
                        <input id="confirm_password" name="confirm_password" type="password" required placeholder="••••••••"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300<?php echo isset($fieldErrors['confirm_password']) ? ' border-red-500 focus:border-red-500 focus:ring-red-500' : ''; ?> rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                    <p id="confirm_password-error" class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($fieldErrors['confirm_password'] ?? ''); ?></p>
                </div>

                <!-- Submit Button -->
                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-colors">
                        Create Account
                    </button>
                </div>
            </form>

            <div class="mt-6 text-center">
                <span class="text-sm text-slate-600">Already have an account?</span>
                <a href="login.php" class="font-medium text-emerald-600 hover:text-emerald-500 text-sm ml-1">Sign in</a>
            </div>
        </div>
    </div>

    <script>
        const signupForm = document.querySelector('form');
        const inputs = {
            name: document.getElementById('name'),
            email: document.getElementById('email'),
            phone: document.getElementById('phone'),
            role: document.getElementById('role'),
            password: document.getElementById('password'),
            confirm_password: document.getElementById('confirm_password')
        };

        const errors = {
            name: document.getElementById('name-error'),
            email: document.getElementById('email-error'),
            phone: document.getElementById('phone-error'),
            role: document.getElementById('role-error'),
            password: document.getElementById('password-error'),
            confirm_password: document.getElementById('confirm_password-error')
        };

        const liveSummary = document.getElementById('live-validation-summary');

        const validators = {
            name(value) {
                if (!value.trim()) return 'Full Name is required.';
                if (value.trim().length < 2) return 'Full Name must be at least 2 characters long.';
                if (/\d/.test(value)) return 'Full Name cannot contain numbers.';
                return '';
            },
            email(value) {
                if (!value.trim()) return 'Email Address is required.';
                const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!pattern.test(value.trim())) return 'Please enter a valid email address.';
                return '';
            },
            phone(value) {
                if (!value.trim()) return 'Phone Number is required.';
                const cleaned = value.replace(/\D/g, '');
                if (!/^[0-9]{11}$/.test(cleaned)) return 'Phone Number must contain exactly 11 digits.';
                return '';
            },
            role(value) {
                if (!value) return 'Please select a role.';
                if (!['Player', 'Owner'].includes(value)) return 'Invalid role selection.';
                return '';
            },
            password(value) {
                if (!value) return 'Password is required.';
                if (value.length < 6) return 'Password must be at least 6 characters long.';
                return '';
            },
            confirm_password(value) {
                const password = inputs.password.value;
                if (!value) return 'Confirm Password is required.';
                if (value !== password) return 'Passwords do not match.';
                return '';
            }
        };

        function setFieldState(field, message) {
            const input = inputs[field];
            const error = errors[field];
            if (message) {
                error.textContent = message;
                input.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            } else {
                error.textContent = '';
                input.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-500');
            }
        }

        function updateLiveSummary() {
            const invalidMessages = Object.keys(inputs)
                .map((field) => validators[field](inputs[field].value))
                .filter(Boolean);

            if (invalidMessages.length > 0) {
                liveSummary.textContent = 'Please fix the highlighted fields.';
                liveSummary.classList.remove('hidden');
            } else {
                liveSummary.textContent = '';
                liveSummary.classList.add('hidden');
            }
        }

        function validateField(field) {
            const message = validators[field](inputs[field].value);
            setFieldState(field, message);
            updateLiveSummary();
            return !message;
        }

        Object.keys(inputs).forEach((field) => {
            const inputField = inputs[field];
            const runValidation = () => {
                validateField(field);

                if (field === 'password' && inputs.confirm_password.value.trim() !== '') {
                    validateField('confirm_password');
                }
            };

            inputField.addEventListener('input', runValidation);
            inputField.addEventListener('blur', runValidation);
            inputField.addEventListener('change', runValidation);
        });

        signupForm.addEventListener('submit', function (event) {
            let valid = true;
            Object.keys(inputs).forEach((field) => {
                if (!validateField(field)) {
                    valid = false;
                }
            });

            if (!valid) {
                event.preventDefault();
            }
        });
    </script>
</body>
</html>
