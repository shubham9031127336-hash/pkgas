<?php
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/business_helper.php';
$brand_cfg = getBrandConfig();
$brand_name = htmlspecialchars($brand_cfg['label']);

$slug = $_GET['slug'] ?? '';

if (!$slug) {
    header("Location: blog.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = ?");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    $pageTitle = "Post Not Found - " . $brand_name;
    $pageDesc = "The requested blog post could not be found.";
    include __DIR__ . '/header.php'; ?>
    <section style="padding: 8rem 2rem; text-align: center; min-height: 60vh; display: flex; flex-direction: column; align-items: center; justify-content: center;">
        <h1 style="font-size: 3rem; font-weight: 800; color: #0f172a; margin-bottom: 1rem;">Post Not Found</h1>
        <p style="color: #64748b; font-size: 1.2rem; max-width: 500px; margin-bottom: 2rem;">The blog post you're looking for doesn't exist or has been removed.</p>
        <a href="blog.php" style="background: #1e40ff; color: white; padding: 1rem 2rem; border-radius: 99px; text-decoration: none; font-weight: 700;">← Back to Blog</a>
    </section>
    <?php include 'footer.php';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($post['meta_title'] ?: $post['title']); ?> | <?php echo $brand_name; ?> Blog</title>
    <meta name="description" content="<?php echo htmlspecialchars($post['meta_description'] ?: $post['excerpt']); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($post['meta_keywords']); ?>">
    
    <!-- Open Graph / SEO -->
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?php echo htmlspecialchars($post['title']); ?>">
    <meta property="og:site_name" content="<?php echo $brand_name; ?>">
    <meta property="og:url" content="<?php echo getSiteUrl('post.php?slug=' . $post['slug']); ?>">
    <?php if ($post['image']): ?>
        <meta property="og:image" content="<?php echo getSiteUrl('uploads/blog/' . $post['image']); ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?php echo getSiteUrl('post.php?slug=' . $post['slug']); ?>">

    <!-- Article Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BlogPosting",
      "headline": "<?php echo htmlspecialchars($post['title']); ?>",
      "description": "<?php echo htmlspecialchars($post['meta_description'] ?: $post['excerpt']); ?>",
      "image": {
        "@type": "ImageObject",
        "url": "<?php echo getSiteUrl('uploads/blog/' . $post['image']); ?>",
        "width": 1200,
        "height": 675
      },
      "author": {
        "@type": "Organization",
        "name": "<?php echo $brand_name; ?>"
      },
      "publisher": {
        "@type": "Organization",
        "name": "<?php echo $brand_name; ?>",
        "logo": {
          "@type": "ImageObject",
          "url": "<?php echo getSiteUrl('Images/logo.png'); ?>"
        }
      },
      "datePublished": "<?php echo date('c', strtotime($post['created_at'])); ?>",
      "dateModified": "<?php echo date('c', strtotime($post['created_at'])); ?>",
      "keywords": "<?php echo htmlspecialchars($post['meta_keywords']); ?>",
      "articleSection": "<?php echo htmlspecialchars($post['category']); ?>"
    }
    </script>

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
      },{
        "@type": "ListItem",
        "position": 3,
        "name": "<?php echo htmlspecialchars($post['title']); ?>",
        "item": "<?php echo getSiteUrl('post.php?slug=' . $post['slug']); ?>"
      }]
    }
    </script>
    
    <?php include __DIR__ . '/header-meta.php'; ?>
    <style>
        .post-header-wrapper {
            background: #0f172a;
            color: white;
            padding: 8rem 2rem 10rem;
            text-align: center;
            border-radius: 0 0 60px 60px;
            margin-bottom: -6rem;
            position: relative;
        }
        @media (max-width: 768px) {
            .post-header-wrapper { padding: 6rem 1.5rem 8rem; border-radius: 0 0 40px 40px; }
        }
        .post-header-wrapper h1 { 
            font-size: clamp(2rem, 5vw, 3.5rem);
            font-weight: 800;
            max-width: 900px;
            margin: 1.5rem auto;
            line-height: 1.2;
            color: #f8fafc;
        }
        .post-header-wrapper .blog-meta {
            justify-content: center;
            color: #94a3b8;
        }
        .post-header-wrapper .category-tag {
            background: rgba(96, 165, 250, 0.2);
            color: #60a5fa;
            border-color: rgba(96, 165, 250, 0.3);
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 2rem;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 99px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .back-btn:hover {
            background: white;
            color: #0f172a;
            transform: translateY(-2px);
        }
        .back-btn svg { width: 18px; height: 18px; }
        .blog-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            max-width: var(--max-width);
            margin: 3rem auto;
            padding: 0 1.5rem;
        }

        @media (min-width: 768px) {
            .blog-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 2rem;
                padding: 0 2rem;
            }
        }

        @media (min-width: 1200px) {
            .blog-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        .post-featured-img-wrapper {
            max-width: var(--max-width);
            margin: 0 auto 4rem;
            padding: 0 2rem;
            position: relative;
            z-index: 10;
        }

        .post-featured-img {
            width: 100%;
            height: 60vh;
            min-height: 400px;
            max-height: 600px;
            object-fit: cover;
            border-radius: 40px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.15);
            display: block;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .post-featured-img-wrapper {
                padding: 0 1.5rem;
                margin: 0 auto 2rem;
            }
            .post-featured-img {
                height: auto;
                min-height: 0;
                max-height: none;
                border-radius: 20px;
            }
        }

        .blog-post-content img {
            cursor: default !important;
        }
    </style>
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

    <article>
        <div class="post-header-wrapper">
            <a href="blog.php" class="back-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <?php echo __p('post.back'); ?>
            </a>
            <div class="blog-meta">
                <span class="category-tag"><?php echo htmlspecialchars($post['category']); ?></span>
                <span class="category-separator">&bull;</span>
                <span><?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
            </div>
            <h1><?php echo htmlspecialchars($post['title']); ?></h1>
        </div>

        <?php if ($post['image']): ?>
            <div class="post-featured-img-wrapper">
                <img src="uploads/blog/<?php echo $post['image']; ?>" class="post-featured-img" alt="<?php echo htmlspecialchars($post['category']); ?> - <?php echo htmlspecialchars($post['title']); ?> | <?php echo $brand_name; ?>" loading="lazy">
            </div>
        <?php endif; ?>

        <div class="blog-post-content">
            <?php echo $post['content']; ?>
        </div>
        <script>
            document.querySelectorAll('.blog-post-content img').forEach(img => {
                img.removeAttribute('title');
            });
        </script>

        <!-- SEO: Keyword Tags Section (Only show tags with search results) -->
        <?php if (!empty($post['meta_keywords'])):
            $keywords = array_map('trim', explode(',', $post['meta_keywords']));
            $valid_keywords = [];

            // Only include keywords that have matching posts
            foreach ($keywords as $keyword) {
                if (empty($keyword)) continue;
                $check_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM posts WHERE (title LIKE ? OR content LIKE ? OR category = ?)");
                $check_stmt->execute(["%$keyword%", "%$keyword%", $keyword]);
                $result = $check_stmt->fetch();

                if ($result['count'] > 0) {
                    $valid_keywords[] = $keyword;
                }
            }

            // Only show section if there are valid keywords with results
            if (!empty($valid_keywords)):
        ?>
        <section style="padding: 3rem 2rem; background: #f8fafc; border-top: 1px solid #e2e8f0;">
            <div style="max-width: var(--max-width); margin: 0 auto;">
                <h3 style="font-size: 1.2rem; margin-bottom: 1.5rem; color: #475569; font-weight: 700;">Related Topics</h3>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <?php foreach ($valid_keywords as $keyword): ?>
                    <a href="blog.php?s=<?php echo urlencode($keyword); ?>"
                       style="background: var(--accent); color: white; padding: 0.5rem 1.25rem;
                              border-radius: 99px; text-decoration: none; font-weight: 600;
                              font-size: 0.9rem; transition: all 0.2s ease; display: inline-block;"
                       onmouseover="this.style.opacity='0.85';"
                       onmouseout="this.style.opacity='1';">
                        #<?php echo htmlspecialchars($keyword); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; endif; ?>
    </article>

    <section style="padding: 4rem 2rem; background: #f8fafc;">
        <div style="max-width: var(--max-width); margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem;">
                <div>
                    <h2 style="font-size: 2.5rem; font-weight: 800;"><?php echo __p('post.recent'); ?></h2>
                    <p style="color: var(--muted); margin-top: 0.5rem;">More insights from <?php echo $brand_name; ?>.</p>
                </div>
                <a href="blog.php" style="color: var(--accent); font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 0.5rem;">
                    View All
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
            </div>
            
            <div class="blog-grid" style="padding: 0; margin: 0;">
                <?php
                // SEO: Try to get related posts by category first
                $related_stmt = $pdo->prepare("SELECT * FROM posts WHERE id != ? AND category = ? ORDER BY created_at DESC LIMIT 3");
                $related_stmt->execute([$post['id'], $post['category']]);
                $recent_posts = $related_stmt->fetchAll();

                // Fallback: If not enough, get recent posts from any category
                if (count($recent_posts) < 3) {
                    $fallback_stmt = $pdo->prepare("SELECT * FROM posts WHERE id != ? ORDER BY created_at DESC LIMIT ?");
                    $fallback_stmt->execute([$post['id'], (3 - count($recent_posts))]);
                    $fallback_posts = $fallback_stmt->fetchAll();
                    $recent_posts = array_merge($recent_posts, $fallback_posts);
                }

                foreach ($recent_posts as $rp):
                    $rp_img = $rp['image'] ? "uploads/blog/" . $rp['image'] : "Images/feature.png";
                ?>
                <article class="blog-card">
                    <div class="blog-img" style="background-image: url('<?php echo $rp_img; ?>')"></div>
                    <div class="blog-body" style="padding: 2rem;">
                        <div class="blog-meta">
                            <span class="category-tag"><?php echo htmlspecialchars($rp['category']); ?></span>
                            <span class="category-separator">&bull;</span>
                            <span><?php echo date('M d, Y', strtotime($rp['created_at'])); ?></span>
                        </div>
                        <h3 class="blog-title" style="font-size: 1.25rem;"><?php echo htmlspecialchars($rp['title']); ?></h3>
                        <a href="post.php?slug=<?php echo $rp['slug']; ?>" class="read-more">Read More</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Contact Us CTA Card (NEW - Responsive & Beautiful) -->
    <section style="padding: 4rem 2rem; background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%);">
        <div style="max-width: 1000px; margin: 0 auto; background: white; border-radius: 40px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.08); display: grid; grid-template-columns: 1fr 1fr; gap: 0;">

            <!-- Left: Image -->
            <div style="background: linear-gradient(135deg, var(--accent) 0%, #1e40ff 100%); display: flex; align-items: center; justify-content: center; min-height: 350px; position: relative; overflow: hidden;">
                <div style="position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                <div style="position: absolute; bottom: -30%; left: -5%; width: 250px; height: 250px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>

                <div style="text-align: center; position: relative; z-index: 2; color: white; padding: 2rem;">
                    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.5" style="margin: 0 auto 1.5rem;">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                    <h3 style="font-size: 1.5rem; margin-bottom: 0.5rem;">Need Help?</h3>
                    <p style="opacity: 0.9; font-size: 0.95rem;">Get in touch with our experts</p>
                </div>
            </div>

            <!-- Right: Contact Form -->
            <div style="padding: 3rem 2.5rem; display: flex; flex-direction: column; justify-content: center;">
                <h2 style="font-size: 2rem; margin-bottom: 0.5rem; color: #1e293b; font-weight: 800;">Ready to Order?</h2>
                <p style="color: #64748b; margin-bottom: 2rem; font-size: 1rem; line-height: 1.6;">
                    Contact us today for reliable industrial and medical gas solutions. Our team is ready to help!
                </p>

                <!-- Contact Options -->
                <div style="display: grid; gap: 1rem; margin-bottom: 2rem;">
                    <!-- WhatsApp -->
                    <a href="https://wa.me/919954440122" style="display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; background: #f0f7ff; border: 2px solid #e0eeff; border-radius: 16px; text-decoration: none; color: #1e293b; transition: all 0.3s; font-weight: 600;"
                       onmouseover="this.style.background='#e0eeff'; this.style.borderColor='var(--accent)';"
                       onmouseout="this.style.background='#f0f7ff'; this.style.borderColor='#e0eeff';">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.411-2.296-1.414-.848-.755-1.423-1.685-1.591-1.982-.168-.297-.028-.458.126-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.67-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421-7.403h-.004a9.87 9.87 0 00-4.783 1.14L.855 2.1.714 6.465c-1.119 5.228 1.412 10.289 5.124 13.567.06.055.119.108.179.159h.003a10.02 10.02 0 005.316 1.54 10.018 10.018 0 009.857-8.203l.196-.974-.992-.144c-.289-.042-.576-.086-.86-.135l-.748 3.71a8.019 8.019 0 01-7.853 6.659c-3.254 0-6.215-1.58-8.038-4.189l-2.4 1.454c1.86 3.065 5.169 5.097 8.967 5.097.704 0 1.397-.055 2.077-.165l.796-3.957-.183.914a7.981 7.981 0 01-2.773 4.038z"/>
                        </svg>
                        <div>
                            <div style="font-size: 0.85rem; opacity: 0.7;">WhatsApp</div>
                            <div>+91 9954440122</div>
                        </div>
                    </a>

                    <!-- Phone -->
                    <a href="tel:+919954440122" style="display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; background: #f0f7ff; border: 2px solid #e0eeff; border-radius: 16px; text-decoration: none; color: #1e293b; transition: all 0.3s; font-weight: 600;"
                       onmouseover="this.style.background='#e0eeff'; this.style.borderColor='var(--accent)';"
                       onmouseout="this.style.background='#f0f7ff'; this.style.borderColor='#e0eeff';">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                        </svg>
                        <div>
                            <div style="font-size: 0.85rem; opacity: 0.7;">Call Us</div>
                            <div>+91 9954440122</div>
                        </div>
                    </a>

                    <!-- Email -->
                    <a href="mailto:nandkishoremahato16@gmail.com" style="display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; background: #f0f7ff; border: 2px solid #e0eeff; border-radius: 16px; text-decoration: none; color: #1e293b; transition: all 0.3s; font-weight: 600;"
                       onmouseover="this.style.background='#e0eeff'; this.style.borderColor='var(--accent)';"
                       onmouseout="this.style.background='#f0f7ff'; this.style.borderColor='#e0eeff';">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
                        <div>
                            <div style="font-size: 0.85rem; opacity: 0.7;">Email</div>
                            <div>nandkishoremahato16@gmail.com</div>
                        </div>
                    </a>
                </div>

                <!-- Quick Action Button -->
                <a href="https://wa.me/919954440122" style="display: inline-block; background: var(--accent); color: white; padding: 1rem 2rem; border-radius: 16px; text-decoration: none; font-weight: 700; text-align: center; transition: all 0.3s; width: 100%;"
                   onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 25px rgba(30, 64, 255, 0.3)';"
                   onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                    Get in Touch Now
                </a>
            </div>
        </div>

        <!-- Mobile Responsive Styles -->
        <style>
            @media (max-width: 768px) {
                div[style*="grid-template-columns: 1fr 1fr"] {
                    grid-template-columns: 1fr !important;
                    gap: 0 !important;
                }
                div[style*="grid-template-columns: 1fr 1fr"] > div:first-child {
                    min-height: 250px !important;
                }
                div[style*="grid-template-columns: 1fr 1fr"] > div:last-child {
                    padding: 2rem 1.5rem !important;
                }
                div[style*="grid-template-columns: 1fr 1fr"] h2 {
                    font-size: 1.5rem !important;
                }
            }
        </style>
    </section>

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
