<?php
// logo_head.php - Shared HTML tags for website icon, favicon and social previews

require_once __DIR__ . '/logo_helper.php';
$logo_url = get_custom_logo_url();
$logo_abs_url = get_logo_absolute_url();

// Determine stable relative URL for the favicon router
$favicon_url = 'favicon.php';
?>
<!-- Favicon / Site Icon (Stable URL for search engine crawlers and browser bookmarks) -->
<link rel="icon" href="<?php echo htmlspecialchars($favicon_url); ?>" sizes="any">
<link rel="shortcut icon" href="<?php echo htmlspecialchars($favicon_url); ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($favicon_url); ?>">

<!-- Search Engine / Browser Preview Metadata -->
<meta name="image" content="<?php echo htmlspecialchars($logo_abs_url); ?>">

<!-- Open Graph / Facebook Metadata -->
<meta property="og:image" content="<?php echo htmlspecialchars($logo_abs_url); ?>">
<meta property="og:image:secure_url" content="<?php echo htmlspecialchars($logo_abs_url); ?>">

<!-- Twitter Metadata -->
<meta name="twitter:image" content="<?php echo htmlspecialchars($logo_abs_url); ?>">
