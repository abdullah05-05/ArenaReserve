<?php
// favicon.php - Dynamic favicon handler to provide a stable URL for search engines and browsers

require_once __DIR__ . '/logo_helper.php';

$logo_path = get_custom_logo_url();
$ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));

// Determine dynamic content type based on extension
$mime_type = 'image/png';
if ($ext === 'svg') {
    $mime_type = 'image/svg+xml';
} elseif ($ext === 'jpg' || $ext === 'jpeg') {
    $mime_type = 'image/jpeg';
} elseif ($ext === 'webp') {
    $mime_type = 'image/webp';
}

// Set headers for caching and content type
header("Content-Type: $mime_type");
header("Cache-Control: public, max-age=3600"); // Cache for 1 hour to optimize performance

// Read and output the file
$full_path = __DIR__ . '/' . $logo_path;
if (file_exists($full_path)) {
    readfile($full_path);
} else {
    // Graceful fallback to default logo
    readfile(__DIR__ . '/assets/images/logo-default.svg');
}
exit;
?>
