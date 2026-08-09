<?php
// logo_head.php - Shared HTML tags for website icon, favicon and social previews
// Include this inside <head> on every page. Pass $page_description to customize the Google snippet.

require_once __DIR__ . '/logo_helper.php';
$logo_abs_url = get_logo_absolute_url();

// Page-specific meta description (set $page_description before including this file, or use a default)
if (!isset($page_description)) {
    $page_description = 'ArenaReserve – Book sports grounds instantly. Find and reserve cricket, football, and multi-sport venues near you. Trusted by players and ground owners across Pakistan.';
}
?>
<!-- ─── Meta Description (Google Search 2-line snippet) ─── -->
<meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
<meta name="robots" content="index, follow">

<!-- ─── Favicon / Site Icon ─── -->
<!-- Physical SVG at root for Google's favicon crawler -->
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<!-- Fallback dynamic favicon for browsers -->
<link rel="alternate icon" href="favicon.php">
<link rel="shortcut icon" href="favicon.svg">
<link rel="apple-touch-icon" href="favicon.svg">

<!-- ─── Open Graph / Social Preview ─── -->
<meta property="og:site_name" content="ArenaReserve">
<meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
<meta property="og:image" content="<?php echo htmlspecialchars($logo_abs_url); ?>">
<meta property="og:image:secure_url" content="<?php echo htmlspecialchars($logo_abs_url); ?>">
<meta property="og:image:width" content="512">
<meta property="og:image:height" content="512">

<!-- ─── Twitter Card ─── -->
<meta name="twitter:card" content="summary">
<meta name="twitter:description" content="<?php echo htmlspecialchars($page_description); ?>">
<meta name="twitter:image" content="<?php echo htmlspecialchars($logo_abs_url); ?>">
