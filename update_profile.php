<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

// Must be authenticated
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Collect inputs (supports single name field OR first_name + last_name)
$name = trim($_POST['name'] ?? '');
if ($name === '' && isset($_POST['first_name'])) {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name'] ?? '');
    $name = trim($first_name . ' ' . $last_name);
}

$email = trim($_POST['email'] ?? '');
$city  = trim($_POST['city']  ?? '');
$phone = trim($_POST['phone'] ?? '');

$errors = [];

// ── Name validation ──────────────────────────────────────────────────────────
if ($name === '') {
    $errors[] = 'Name is required.';
} elseif (strlen($name) < 2) {
    $errors[] = 'Name must be at least 2 characters.';
} elseif (!preg_match('/^[a-zA-Z0-9_\s]+$/', $name)) {
    $errors[] = 'Name must contain only letters, numbers, underscores, and spaces.';
}

// ── Email validation ─────────────────────────────────────────────────────────
if ($email === '') {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
} else {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetch()) {
        $errors[] = 'This email is already in use by another account.';
    }
}

// ── City validation ──────────────────────────────────────────────────────────
if ($city === '') {
    $errors[] = 'City is required.';
}

// ── Phone validation ─────────────────────────────────────────────────────────
if ($phone === '') {
    $errors[] = 'Phone number is required.';
} else {
    $cleanPhone = preg_replace('/\D/', '', $phone);
    if (!preg_match('/^[0-9]{11}$/', $cleanPhone)) {
        $errors[] = 'Phone number must contain exactly 11 digits (e.g. 03001234567).';
    } else {
        $phone = $cleanPhone;
    }
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Profile picture upload ───────────────────────────────────────────────────
$profile_picture_path = null;

if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['profile_picture'];

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    if (!array_key_exists($mime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image format. Allowed: JPG, PNG, WebP, GIF.']);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Profile picture must be under 5 MB.']);
        exit;
    }

    $upload_dir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Delete old avatar if exists
    try {
        $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $old_pic = $stmt->fetchColumn();
        if ($old_pic && file_exists(__DIR__ . '/' . $old_pic)) {
            @unlink(__DIR__ . '/' . $old_pic);
        }
    } catch (Exception $e) { /* ignore */ }

    $ext      = $allowed[$mime];
    $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
    $dest     = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save profile picture. Please try again.']);
        exit;
    }

    $profile_picture_path = 'uploads/avatars/' . $filename;
}

// ── Update database ──────────────────────────────────────────────────────────
try {
    if ($profile_picture_path !== null) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET name = ?, email = ?, city = ?, phone = ?, profile_picture = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $city, $phone, $profile_picture_path, $user_id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET name = ?, email = ?, city = ?, phone = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $city, $phone, $user_id]);
    }

    // ── Refresh session immediately ──────────────────────────────────────────
    $_SESSION['name']  = $name;
    $_SESSION['email'] = $email;
    $_SESSION['city']  = $city;
    if ($profile_picture_path !== null) {
        $_SESSION['profile_picture'] = $profile_picture_path;
    }

    $response = [
        'success'    => true,
        'message'    => 'Profile updated successfully.',
        'name'       => $name,
    ];
    if ($profile_picture_path !== null) {
        $response['profile_picture'] = $profile_picture_path;
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
