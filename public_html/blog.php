<?php
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/business_helper.php';
$brand_cfg = getBrandConfig();
$brand_name = htmlspecialchars($brand_cfg['label']);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Handle Search & Category Filtering
$search = $_GET['s'] ?? '';
$query = "SELECT * FROM posts";
$params = [];

if ($search) {
    // Search in title, content, AND category
    $query .= " WHERE title LIKE ? OR content LIKE ? OR category = ?";
    $search_escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
    $params = ["%$search_escaped%", "%$search_escaped%", $search];
}

$query .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$posts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $search ? 'Search: ' . htmlspecialchars($search) . ' - Blog' : 'Blog'; ?> - <?php echo $brand_name; ?> | Industrial & Medical Gas Insights</title>
    <meta name="description" content="<?php echo $search ? 'Search results for &quot;' . htmlspecialchars($search) . '&quot; in ' . $brand_name . ' blog.' : 'Stay updated with the latest insights on industrial and medical gases, safety standards, and oxygen supply in Bihar. Expert articles by ' . $brand_name . '.'; ?>">
    <meta name="keywords" content="<?php
    if ($search) {
        echo htmlspecialchars($search) . ', ' . $brand_name . ', Oxygen Supply Bihar, Industrial Gas, Medical Gas';
    } else {
        // Get top keywords from recent posts
        $top_keywords = $pdo->query("SELECT meta_keywords FROM posts ORDER BY created_at DESC LIMIT 3");
        $keywords = [];
        while ($row = $top_keywords->fetch()) {
            if ($row['meta_keywords']) {
                $kw = array_map('trim', explode(',', $row['meta_keywords']));
                $keywords = array_merge($keywords, $kw);
            }
        }
        $keywords = array_slice(array_unique($keywords), 0, 5);
        echo $brand_name . ' Blog, ' . implode(', ', $keywords) . ', Oxygen Supply Bihar, Industrial Gas Safety, Medical Gas Insights Khagaria';
    }
    ?>">
    <link rel="canonical" href="<?php echo getSiteUrl('blog.php' . ($search ? '?s=' . urlencode($search) : '')); ?>">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $brand_name; ?> Blog | Industrial & Medical Gas Insights">
    <meta property="og:description" content="Reliable insights into gas supply solutions across Bihar.">
    <meta property="og:url" content="<?php echo getSiteUrl('blog.php'); ?>">
    <meta property="og:image" content="<?php echo getSiteUrl('Images/feature.png'); ?>">

    <!-- Breadcrumb Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      "itemListElement": [{
        "@type": "ListItem",
        "position": 1,
        "name": "Home",
        "item": "<?php echo getSiteUrl('index.php'); ?>"
      },{
        "@type": "ListItem",
        "position": 2,
        "name": "Blog",
        "item": "<?php echo getSiteUrl('blog.php'); ?>"
      }]
    }
    </script>

    <?php include __DIR__ . '/header-meta.php'; ?>
</head>
<body>
    <div class="mobile-overlay" id="mobileOverlay"></div>
    <div class="mobile-menu" id="mobileMenu">
        <button class="mobile-menu-close" onclick="toggleMenu()" aria-label="Close Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        <a href="index.php" class="mobile-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            <?php echo __p('nav.home'); ?>
        </a>
        <a href="blog.php" class="mobile-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"></path><path d="M18 14h-8"></path><path d="M15 18h-5"></path><path d="M10 6h8v4h-8z"></path></svg>
            <?php echo __p('nav.blog'); ?>
        </a>
        <a href="index.php#products" class="mobile-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
            <?php echo __p('nav.products'); ?>
        </a>
        <a href="index.php#contact" class="mobile-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <?php echo __p('nav.contact'); ?>
        </a>
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
            <a href="tel:+919954440122" class="contact-chip">
                <svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
                +91- 9954440122
            </a>
        </nav>
    </header>

    <section class="blog-header">
        <h1><?php echo __p('blog.page_title'); ?></h1>
        <p><?php echo __p('blog.page_desc'); ?></p>
        
        <form action="blog.php" method="GET" class="search-bar">
            <input type="text" name="s" class="search-input" placeholder="Search articles..." value="<?php echo htmlspecialchars($search); ?>">
        </form>
    </section>

    <?php if (!$search && !empty($posts)): 
        $featured = $posts[0];
        $posts = array_slice($posts, 1);
        $featured_img = $featured['image'] ? "uploads/blog/" . $featured['image'] : "Images/feature.png";
    ?>
    <section style="max-width: var(--max-width); margin: 0 auto 3rem; padding: 0 2rem;">
        <a href="post.php?slug=<?php echo $featured['slug']; ?>" style="text-decoration: none; color: inherit;">
            <div style="background: #0f172a; border-radius: 30px; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 40px rgba(0,0,0,0.1); transition: transform 0.4s ease, box-shadow 0.4s ease;" class="featured-card-new">
                <div class="featured-image" style="background: url('<?php echo $featured_img; ?>') center/cover;"></div>
                <div style="padding: 2.5rem; display: flex; flex-direction: column; justify-content: center;">
                    <span style="color: #60a5fa; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; font-size: 0.8rem; margin-bottom: 1rem; display: block;">Featured Story</span>
                    <h2 style="font-size: clamp(1.5rem, 2.5vw, 2rem); font-weight: 800; margin-bottom: 1rem; line-height: 1.2; color: #f8fafc;"><?php echo htmlspecialchars($featured['title']); ?></h2>
                    <p style="font-size: 1rem; color: #94a3b8; line-height: 1.6; margin-bottom: 1.5rem; max-width: 800px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;"><?php echo htmlspecialchars($featured['excerpt']); ?></p>
                    <div style="display: flex; align-items: center; gap: 0.5rem; color: #60a5fa; font-weight: 700; font-size: 1rem; margin-top: auto;">
                        Read Full Insight
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </div>
                </div>
            </div>
        </a>
    </section>
    <style>
        .featured-card-new { display: grid; grid-template-columns: 1fr 1fr; min-height: 280px; max-height: 320px; }
        .featured-card-new:hover { transform: translateY(-5px); box-shadow: 0 25px 50px rgba(0,0,0,0.2) !important; }
        .featured-image { width: 100%; height: 100%; }
        @media (max-width: 768px) {
            .featured-card-new { grid-template-columns: 1fr; max-height: none; }
            .featured-image { 
                aspect-ratio: auto; 
                min-height: 220px;
                background-size: contain !important;
                background-repeat: no-repeat !important;
                background-position: center !important;
            }
            .featured-card-new div:last-child { padding: 1.5rem !important; }
            .featured-card-new h2 { font-size: 1.5rem !important; }
        }
    </style>
    <?php endif; ?>

    <!-- Category Section -->
    <section style="max-width: var(--max-width); margin: 0 auto 2.5rem; padding: 0 2rem;">
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center;">
            <a href="blog.php" style="text-decoration: none; padding: 12px 24px; border-radius: 99px; background: <?php echo !$search ? 'var(--fg)' : 'white'; ?>; color: <?php echo !$search ? 'white' : 'var(--fg)'; ?>; font-weight: 700; border: 1px solid var(--border);">All Topics</a>
            <a href="blog.php?s=Industrial" style="text-decoration: none; padding: 12px 24px; border-radius: 99px; background: white; color: var(--fg); font-weight: 700; border: 1px solid var(--border);">Industrial Gas</a>
            <a href="blog.php?s=Medical" style="text-decoration: none; padding: 12px 24px; border-radius: 99px; background: white; color: var(--fg); font-weight: 700; border: 1px solid var(--border);">Medical Oxygen</a>
            <a href="blog.php?s=Safety" style="text-decoration: none; padding: 12px 24px; border-radius: 99px; background: white; color: var(--fg); font-weight: 700; border: 1px solid var(--border);">Safety Guides</a>
            <a href="blog.php?s=News" style="text-decoration: none; padding: 12px 24px; border-radius: 99px; background: white; color: var(--fg); font-weight: 700; border: 1px solid var(--border);">Company News</a>
        </div>
    </section>

    <div class="blog-grid" style="margin-top: 0;">
        <?php foreach ($posts as $post): 
            $post_img = $post['image'] ? "uploads/blog/" . $post['image'] : "Images/feature.png";
        ?>
        <article class="blog-card">
            <div class="blog-img" style="background-image: url('<?php echo $post_img; ?>')"></div>
            <div class="blog-body">
                <div class="blog-meta">
                    <span class="category-tag" style="color: var(--accent); font-weight: 700;"><?php echo htmlspecialchars($post['category']); ?></span>
                    <span class="category-separator">&bull;</span>
                    <span><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                </div>
                <h2 class="blog-title"><?php echo htmlspecialchars($post['title']); ?></h2>
                <p class="blog-excerpt"><?php echo htmlspecialchars($post['excerpt']); ?></p>
                <a href="post.php?slug=<?php echo $post['slug']; ?>" class="read-more">
                    Explore Article
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>

    <!-- Industry Solutions CTA Section -->
    <section style="background: #050a10; color: white; border-radius: 60px; margin: 4rem 2rem; padding: 5rem 2rem; text-align: center; position: relative; overflow: hidden;">
        <div style="position: absolute; top: 0; right: 0; width: 400px; height: 400px; background: var(--accent); filter: blur(150px); opacity: 0.15;"></div>
        <div style="position: relative; z-index: 2; max-width: 800px; margin: 0 auto;">
            <h2 style="font-size: 3rem; font-weight: 800; margin-bottom: 1.5rem;">Need a Reliable Gas Partner?</h2>
            <p style="font-size: 1.25rem; opacity: 0.8; margin-bottom: 3.5rem;">Whether it's for a high-tech manufacturing plant or a 500-bed hospital, <?php echo $brand_name; ?> has the inventory and expertise to keep you running.</p>
            <div style="display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap;">
                <a href="https://wa.me/919954440122" style="text-decoration: none; color: white; border: 2px solid rgba(255,255,255,0.2); padding: 1.25rem 3rem; border-radius: 16px; font-weight: 700; transition: all 0.3s;" onmouseover="this.style.borderColor='white'" onmouseout="this.style.borderColor='rgba(255,255,255,0.2)'">Talk to an Expert</a>
            </div>
        </div>
    </section>

        <?php if (empty($posts)): ?>
            <p style="grid-column: 1/-1; text-align: center; padding: 4rem; color: var(--muted);">No articles found matching your search.</p>
        <?php endif; ?>

    <footer>
        <div class="footer-top">
            <div class="footer-brand">
                <img src="Images/logo.png" alt="<?php echo $brand_name; ?>" class="logo" loading="lazy">
                <p><?php echo __p('footer.tagline'); ?></p>
            </div>
            <div class="footer-nav">
                <div class="footer-nav-col">
                    <h5><?php echo __p('footer.company'); ?></h5>
                    <ul>
                        <li><a href="index.php"><?php echo __p('footer.home'); ?></a></li>
                        <li><a href="index.php#about"><?php echo __p('footer.about'); ?></a></li>
                        <li><a href="blog.php"><?php echo __p('nav.blog'); ?></a></li>
                    </ul>
                </div>
                <div class="footer-nav-col">
                    <h5><?php echo __p('footer.products'); ?></h5>
                    <ul>
                        <li><a href="index.php#products"><?php echo __p('footer.gas_cylinders'); ?></a></li>
                        <li><a href="index.php#products"><?php echo __p('footer.refill_services'); ?></a></li>
                        <li><a href="index.php#products"><?php echo __p('footer.medical_gases'); ?></a></li>
                    </ul>
                </div>
                <div class="footer-nav-col">
                    <h5><?php echo __p('footer.contact'); ?></h5>
                    <ul>
                        <li><a href="index.php#contact"><?php echo __p('footer.get_in_touch'); ?></a></li>
                        <li><a href="tel:+91 9954440122"><?php echo __p('footer.call_us'); ?></a></li>
                        <li><a href="mailto:nandkishoremahato16@gmail.com"><?php echo __p('footer.email_support'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p><?php echo __p('footer.copyright'); ?></p>
            <p><?php echo __p('footer.credit'); ?></p>
        </div>
    </footer>
</body>
</html>
