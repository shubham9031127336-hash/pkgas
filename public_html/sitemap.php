<?php
header("Content-type: text/xml");
header("Cache-Control: public, max-age=3600");
header("Expires: " . gmdate('D, d M Y H:i:s', time() + 3600) . " GMT");
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/business_helper.php';

$base_url = getSiteUrl('/');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

// Home
echo '<url>';
echo '<loc>' . $base_url . 'index.php</loc>';
echo '<lastmod>' . date('Y-m-d') . '</lastmod>';
echo '<priority>1.0</priority>';
echo '</url>';

// Blog Page
echo '<url>';
echo '<loc>' . $base_url . 'blog.php</loc>';
echo '<lastmod>' . date('Y-m-d') . '</lastmod>';
echo '<priority>0.8</priority>';
echo '</url>';

// Product Pages (SEO: Added lastmod)
$product_pages = [
    'hydrogen-gas-distributor-bihar.php',
    'argon-gas-cylinder-bihar.php',
    'medical-oxygen-cylinder-khagaria.php',
    'acetylene-gas-supplier-khagaria.php',
    'nitrous-oxide-cylinder-bihar.php',
    'co2-gas-supplier-khagaria.php',
    'refrigerant-gas-supplier-bihar.php',
    'cylinder-hardware-khagaria.php',
    'oxygen-gas-supplier-khagaria.php'
];

foreach ($product_pages as $page) {
    echo '<url>';
    echo '<loc>' . $base_url . $page . '</loc>';
    echo '<lastmod>' . date('Y-m-d') . '</lastmod>';
    echo '<priority>0.9</priority>';
    echo '</url>';
}

// Fetch all posts (SEO: Already has lastmod from created_at)
$stmt = $pdo->query("SELECT slug, created_at FROM posts ORDER BY created_at DESC");
while ($row = $stmt->fetch()) {
    echo '<url>';
    echo '<loc>' . $base_url . 'post.php?slug=' . $row['slug'] . '</loc>';
    echo '<lastmod>' . date('Y-m-d', strtotime($row['created_at'])) . '</lastmod>';
    echo '<priority>0.7</priority>';
    echo '</url>';
}

echo '</urlset>';
?>
