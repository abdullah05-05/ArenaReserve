<?php
/**
 * sitemap.php — Dynamic XML Sitemap for Google Search Console & Search Engine Crawlers.
 * Generates standards-compliant XML (sitemaps.org protocol 0.9).
 */

require_once __DIR__ . '/db.php';

// Set proper XML header
header('Content-Type: application/xml; charset=utf-8');

// Determine protocol (supporting Cloudflare / reverse proxy SSL)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

$protocol = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'arenareserve.app';

// Determine subdirectory (e.g. /GHR/a1/ on local, or / in production)
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = str_replace('\\', '/', $scriptDir);
if ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') {
    $scriptDir = '/';
} else {
    $scriptDir = '/' . trim($scriptDir, '/') . '/';
}

$baseUrl = $protocol . '://' . $host . $scriptDir;

// Array of static public pages
$staticPages = [
    [
        'loc'        => $baseUrl,
        'changefreq' => 'daily',
        'priority'   => '1.0',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'landing.php',
        'changefreq' => 'weekly',
        'priority'   => '0.9',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'explore.php',
        'changefreq' => 'daily',
        'priority'   => '0.9',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'leaderboard.php',
        'changefreq' => 'daily',
        'priority'   => '0.8',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'contact.php',
        'changefreq' => 'monthly',
        'priority'   => '0.7',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'login.php',
        'changefreq' => 'monthly',
        'priority'   => '0.6',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'signup.php',
        'changefreq' => 'monthly',
        'priority'   => '0.6',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'terms.php',
        'changefreq' => 'monthly',
        'priority'   => '0.5',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'privacy.php',
        'changefreq' => 'monthly',
        'priority'   => '0.5',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'cancellation-policy.php',
        'changefreq' => 'monthly',
        'priority'   => '0.5',
        'lastmod'    => date('Y-m-d')
    ],
    [
        'loc'        => $baseUrl . 'refund-policy.php',
        'changefreq' => 'monthly',
        'priority'   => '0.5',
        'lastmod'    => date('Y-m-d')
    ],
];

// Dynamic Grounds from database
$groundPages = [];
try {
    $stmt = $pdo->query("SELECT id, title, created_at FROM grounds ORDER BY id DESC");
    $grounds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($grounds as $g) {
        $lastmod = !empty($g['created_at']) ? date('Y-m-d', strtotime($g['created_at'])) : date('Y-m-d');
        $groundPages[] = [
            'loc'        => $baseUrl . 'book_slot.php?ground=' . intval($g['id']),
            'changefreq' => 'daily',
            'priority'   => '0.8',
            'lastmod'    => $lastmod
        ];
    }
} catch (Exception $e) {
    // Graceful fallback if database query fails
}

// Output XML
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">

    <!-- Core Public Pages -->
<?php foreach ($staticPages as $page): ?>
    <url>
        <loc><?php echo htmlspecialchars($page['loc'], ENT_XML1, 'UTF-8'); ?></loc>
        <lastmod><?php echo htmlspecialchars($page['lastmod'], ENT_XML1, 'UTF-8'); ?></lastmod>
        <changefreq><?php echo htmlspecialchars($page['changefreq'], ENT_XML1, 'UTF-8'); ?></changefreq>
        <priority><?php echo htmlspecialchars($page['priority'], ENT_XML1, 'UTF-8'); ?></priority>
    </url>
<?php endforeach; ?>

    <!-- Dynamic Ground Booking Pages -->
<?php foreach ($groundPages as $gPage): ?>
    <url>
        <loc><?php echo htmlspecialchars($gPage['loc'], ENT_XML1, 'UTF-8'); ?></loc>
        <lastmod><?php echo htmlspecialchars($gPage['lastmod'], ENT_XML1, 'UTF-8'); ?></lastmod>
        <changefreq><?php echo htmlspecialchars($gPage['changefreq'], ENT_XML1, 'UTF-8'); ?></changefreq>
        <priority><?php echo htmlspecialchars($gPage['priority'], ENT_XML1, 'UTF-8'); ?></priority>
    </url>
<?php endforeach; ?>

</urlset>
