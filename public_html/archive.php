<?php
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/business_helper.php';
$brand_cfg = getBrandConfig();
$brand_name = htmlspecialchars($brand_cfg['label']);

$category = $_GET['category'] ?? '';
$month = $_GET['month'] ?? '';
$year = $_GET['year'] ?? '';

$query = "SELECT * FROM posts WHERE 1=1";
$params = [];

if ($category) {
    $query .= " AND category = ?";
    $params[] = $category;
}

if ($month && $year) {
    $query .= " AND MONTH(created_at) = ? AND YEAR(created_at) = ?";
    $params[] = $month;
    $params[] = $year;
}

$query .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Get unique categories for the sidebar
$cat_stmt = $pdo->query("SELECT DISTINCT category FROM posts");
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive - <?php echo $brand_name; ?> Blog</title>
    <meta name="description" content="Browse all articles, categories, and archives from the <?php echo $brand_name; ?> blog. Insights on industrial and medical gas solutions in Bihar.">
    <link rel="canonical" href="<?php echo getSiteUrl('archive.php'); ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Blog Archive - <?php echo $brand_name; ?>">
    <meta property="og:description" content="Browse all articles from the <?php echo $brand_name; ?> blog.">
    <meta property="og:url" content="<?php echo getSiteUrl('archive.php'); ?>">
    <meta property="og:image" content="<?php echo getSiteUrl('Images/feature.png'); ?>">
    <meta property="og:locale" content="en_IN">
    <meta property="og:site_name" content="<?php echo $brand_name; ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php include __DIR__ . '/header-meta.php'; ?>
    <style>
        .archive-container {
            max-width: var(--max-width);
            margin: 4rem auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 4rem;
        }
        .archive-sidebar {
            background: white;
            padding: 2rem;
            border-radius: 24px;
            border: 1px solid var(--border);
            height: fit-content;
        }
        .sidebar-title { font-weight: 800; margin-bottom: 1.5rem; font-size: 1.25rem; }
        .cat-list { list-style: none; }
        .cat-list li { margin-bottom: 0.75rem; }
        .cat-list a { text-decoration: none; color: var(--muted); transition: color 0.2s; }
        .cat-list a:hover { color: var(--accent); }
        
        @media (max-width: 900px) {
            .archive-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="mobile-overlay" id="mobileOverlay"></div>
    <div class="mobile-menu" id="mobileMenu">
        <button class="mobile-menu-close" onclick="toggleMenu()" aria-label="Close Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        <a href="index.php" class="mobile-link"><?php echo __p('nav.home'); ?></a>
        <a href="blog.php" class="mobile-link"><?php echo __p('nav.blog'); ?></a>
        <a href="archive.php" class="mobile-link">Archive</a>
        <a href="index.php#products" class="mobile-link"><?php echo __p('nav.products'); ?></a>
        <a href="index.php#contact" class="mobile-link"><?php echo __p('nav.contact'); ?></a>
    </div>
    <script src="assets/js/main.js"></script>

    <header>
        <a href="index.php"><img src="Images/logo.png" alt="<?php echo $brand_name; ?>" class="logo" loading="lazy"></a>

        <a href="tel:+919954440122" class="mobile-contact-btn">
            <svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
            +91 99544 40122
        </a>

        <div class="hamburger" id="hamburger" onclick="toggleMenu()">
            <span></span>
            <span></span>
            <span></span>
        </div>

        <nav class="nav-pill">
            <a href="index.php" class="nav-link"><?php echo __p('nav.home'); ?></a>
            <a href="blog.php" class="nav-link"><?php echo __p('nav.blog'); ?></a>
            <a href="tel:+919954440122" class="contact-chip">+91- 9954440122</a>
        </nav>
    </header>

    <div class="blog-header">
        <h1><?php echo __p('archive.heading'); ?></h1>
        <p><?php echo $category ? "Showing articles in '$category'" : "Browsing all insights"; ?></p>
    </div>

    <div class="archive-container">
        <div class="archive-posts">
            <div class="blog-grid" style="grid-template-columns: 1fr; margin: 0; padding: 0;">
                <?php foreach ($posts as $post): ?>
                <article class="blog-card" style="flex-direction: row; align-items: center; gap: 2rem; padding: 1.5rem;">
                    <?php if ($post['image']): ?>
                        <div class="blog-img" style="width: 200px; height: 150px; flex-shrink: 0; border-radius: 20px; background-image: url('uploads/blog/<?php echo $post['image']; ?>')"></div>
                    <?php endif; ?>
                    <div>
                        <div class="blog-meta">
                            <span><?php echo htmlspecialchars($post['category']); ?></span>
                            <span>&bull;</span>
                            <span><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                        </div>
                        <h2 class="blog-title" style="font-size: 1.25rem;"><a href="post.php?slug=<?php echo $post['slug']; ?>" style="text-decoration: none; color: inherit;"><?php echo htmlspecialchars($post['title']); ?></a></h2>
                    </div>
                </article>
                <?php endforeach; ?>
                
                <?php if (empty($posts)): ?>
                    <p style="text-align: center; padding: 4rem; color: var(--muted);">No articles found in this archive.</p>
                <?php endif; ?>
            </div>
        </div>

        <aside class="archive-sidebar">
            <div class="sidebar-title">Categories</div>
            <ul class="cat-list">
                <li><a href="archive.php">All Categories</a></li>
                <?php foreach ($categories as $cat): ?>
                    <li><a href="archive.php?category=<?php echo urlencode($cat); ?>"><?php echo htmlspecialchars($cat); ?></a></li>
                <?php endforeach; ?>
            </ul>

            <div class="sidebar-title" style="margin-top: 3rem;">Recent Posts</div>
            <ul class="cat-list">
                <?php
                $recent_stmt = $pdo->query("SELECT title, slug FROM posts ORDER BY created_at DESC LIMIT 5");
                while ($recent = $recent_stmt->fetch()): ?>
                    <li><a href="post.php?slug=<?php echo $recent['slug']; ?>"><?php echo htmlspecialchars($recent['title']); ?></a></li>
                <?php endwhile; ?>
            </ul>

            <div class="sidebar-title" style="margin-top: 3rem;">Newsletter</div>
            <form class="newsletter-form-inline" onsubmit="event.preventDefault();subscribeNewsletter(this);" style="display:flex;flex-direction:column;gap:0.5rem;">
                <input type="email" name="email" placeholder="Your email address" required style="padding:0.6rem 0.8rem;border:1px solid var(--border);border-radius:8px;font-size:0.85rem;">
                <button type="submit" style="padding:0.6rem 1.2rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-weight:700;font-size:0.85rem;cursor:pointer;">Subscribe</button>
                <p class="newsletter-msg" style="font-size:0.8rem;color:var(--muted);margin-top:0.25rem;display:none;"></p>
            </form>
        </aside>
    </div>

    <footer>
        <div class="footer-bottom">
            <p><?php echo __p('footer.copyright'); ?></p>
        </div>
    </footer>
</body>
</html>
