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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = trim($_POST['role'] ?? 'Player');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Simple validations
    if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else if ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
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
            $error = 'Something went wrong. Please try again. ' . $e->getMessage();
        }
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

            <form class="space-y-6" action="signup.php" method="POST">
                <!-- Full Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">Full Name</label>
                    <div class="mt-1">
                        <input id="name" name="name" type="text" required placeholder="John Doe"
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                </div>

                <!-- Email Address -->
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email Address</label>
                    <div class="mt-1">
                        <input id="email" name="email" type="email" required placeholder="your@email.com"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                </div>

                <!-- Phone Number -->
                <div>
                    <label for="phone" class="block text-sm font-medium text-slate-700">Phone Number</label>
                    <div class="mt-1">
                        <input id="phone" name="phone" type="text" required placeholder="+92 300 1234567"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                </div>

                <!-- Role Selection -->
                <div>
                    <label for="role" class="block text-sm font-medium text-slate-700">I want to</label>
                    <div class="mt-1">
                        <select id="role" name="role"
                                class="block w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm bg-white">
                            <option value="Player" <?php echo (($_POST['role'] ?? '') === 'Player') ? 'selected' : ''; ?>>Book grounds as a Player</option>
                            <option value="Owner" <?php echo (($_POST['role'] ?? '') === 'Owner') ? 'selected' : ''; ?>>List grounds as an Owner</option>
                        </select>
                    </div>
                </div>

                <!-- Password -->
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <div class="mt-1">
                        <input id="password" name="password" type="password" required placeholder="••••••••"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
                </div>

                <!-- Confirm Password -->
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-slate-700">Confirm Password</label>
                    <div class="mt-1">
                        <input id="confirm_password" name="confirm_password" type="password" required placeholder="••••••••"
                               class="appearance-none block w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm">
                    </div>
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
</body>
</html>
