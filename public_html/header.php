<?php
if (!function_exists('__p')) {
    require_once __DIR__ . '/translations.php';
}
require_once __DIR__ . '/admin/business_helper.php';
$brand_cfg = getBrandConfig();
$brand_name = htmlspecialchars($brand_cfg['label']);
$brand_legal = htmlspecialchars($brand_cfg['business_name']);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header_remove('X-Powered-By');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$pageTitle = $pageTitle ?? ($brand_name . " - Industrial & Medical Gas Supplier in Bihar");
$pageDesc = $pageDesc ?? "Trusted supplier of industrial and medical gas cylinders in Bihar and Arunachal Pradesh.";
$pageKeys = $pageKeys ?? $brand_name . ", Oxygen Cylinder Bihar, Medical Gas Supplier Khagaria, Industrial Gas Refill";
$canonical = $canonical ?? getSiteUrl('/');
$ogTitle = $ogTitle ?? $pageTitle;
$ogDesc = $ogDesc ?? $pageDesc;
$ogUrl = $ogUrl ?? $canonical;
$ogImage = $ogImage ?? getSiteUrl('Images/feature.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo $pageDesc; ?>">
    <meta name="keywords" content="<?php echo $pageKeys; ?>">
    <meta name="author" content="<?php echo $brand_name; ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo $canonical; ?>">
    <link rel="alternate" hreflang="en" href="<?php echo $canonical; ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo $canonical; ?>">
    
    <!-- Open Graph / Facebook / WhatsApp -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $ogTitle; ?>">
    <meta property="og:description" content="<?php echo $ogDesc; ?>">
    <meta property="og:url" content="<?php echo $ogUrl; ?>">
    <meta property="og:image" content="<?php echo $ogImage; ?>">
    <meta property="og:locale" content="en_IN">
    <meta property="og:site_name" content="<?php echo $brand_name; ?>">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $ogTitle; ?>">
    <meta name="twitter:description" content="<?php echo $ogDesc; ?>">
    <meta name="twitter:image" content="<?php echo $ogImage; ?>">

    <!-- Geo Tags -->
    <meta name="geo.region" content="IN-AS">
    <meta name="geo.placename" content="Khagaria, Bihar">
    <meta name="geo.position" content="27.4924;95.3554">
    <meta name="ICBM" content="27.4924, 95.3554">

    <!-- Business Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "LocalBusiness",
      "name": "<?php echo $brand_legal; ?>",
      "image": "<?php echo getSiteUrl('Images/feature.png'); ?>",
      "url": "<?php echo $canonical; ?>",
      "telephone": "+919954440122",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Namghar Road, Opposite Manav Kalyan Puja Bhawan",
        "addressLocality": "Khagaria",
        "addressRegion": "Bihar",
        "postalCode": "786125",
        "addressCountry": "IN"
      },
      "areaServed": [
        "Khagaria", "Dibrugarh", "Duliajan", "Digboi", "Naharkatia", "Doomdooma", "Chabua", "Upper Bihar", "Arunachal Pradesh"
      ],
      "description": "Trusted supplier of industrial and medical gas cylinders in Bihar and Arunachal Pradesh.",
      "sameAs": ["<?php echo getSiteUrl('/'); ?>"]
    }
    </script>
    
    <!-- WebPage Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "<?php echo htmlspecialchars($pageTitle); ?>",
      "description": "<?php echo htmlspecialchars($pageDesc); ?>",
      "url": "<?php echo $canonical; ?>",
      "inLanguage": "en",
      "publisher": {
        "@type": "Organization",
        "name": "<?php echo $brand_legal; ?>",
        "logo": {
          "@type": "ImageObject",
          "url": "<?php echo getSiteUrl('Images/logo.png'); ?>"
        }
      }
    }
    </script>

    <title><?php echo $pageTitle; ?></title>
    <?php include __DIR__ . '/header-meta.php'; ?>

    <style>
        .product-hero { padding: 10rem 2rem 6rem; background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%); text-align: center; position: relative; overflow: hidden; }
        .product-hero::before { content: ''; position: absolute; top: 0; right: 0; width: 40%; height: 100%; background: radial-gradient(circle at 70% 30%, rgba(30, 64, 255, 0.05) 0%, transparent 70%); z-index: 0; }
        .product-hero h1 { font-size: clamp(2.5rem, 6vw, 4.5rem); margin-bottom: 1.5rem; position: relative; z-index: 1; }
        .product-hero h1 span { color: var(--accent); }
        .product-hero p { font-size: 1.25rem; opacity: 0.9; max-width: 800px; margin: 0 auto; line-height: 1.6; position: relative; z-index: 1; }
        
        .product-content { padding: 6rem 2rem; max-width: var(--max-width); margin: 0 auto; }
        .product-grid-detail { display: grid; grid-template-columns: 1fr 1fr; gap: 5rem; align-items: start; }
        @media (max-width: 992px) { .product-grid-detail { grid-template-columns: 1fr; gap: 3rem; } }
        
        .product-image-container { position: sticky; top: 120px; }
        .product-image { border-radius: 40px; width: 100%; box-shadow: 0 40px 80px rgba(0,0,0,0.08); border: 1px solid var(--border); }
        
        .product-text h2 { font-size: 2.5rem; margin-bottom: 1.5rem; color: #1e293b; }
        .product-text p { font-size: 1.1rem; line-height: 1.8; color: #475569; margin-bottom: 2rem; }
        
        .feature-list { list-style: none; padding: 0; margin-bottom: 3rem; }
        .feature-list li { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem; font-weight: 600; color: #1e293b; }
        .feature-list li svg { color: var(--accent); flex-shrink: 0; }
        
        .applications-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-top: 3rem; }
        .app-card { background: #f8fafc; padding: 2rem; border-radius: 24px; border: 1px solid var(--border); transition: all 0.3s ease; }
        .app-card:hover { border-color: var(--accent); transform: translateY(-5px); }
        .app-card h4 { margin-bottom: 0.5rem; color: #1e293b; }
        .app-card p { font-size: 0.95rem; margin-bottom: 0; }
        
        .cta-box { background: #050a10; color: white; padding: 5rem 2rem; border-radius: 48px; margin-top: 6rem; text-align: center; position: relative; overflow: hidden; }
        .cta-box h2 { font-size: 3rem; margin-bottom: 1.5rem; }
        .cta-box p { font-size: 1.25rem; opacity: 0.8; margin-bottom: 3rem; max-width: 600px; margin-left: auto; margin-right: auto; }

        /* Mobile Optimization */
        @media (max-width: 768px) {
            .product-hero { padding: 6rem 1.25rem 3.5rem; }
            .product-hero h1 { font-size: 2.25rem; line-height: 1.2; }
            .product-hero p { font-size: 1.1rem; }
            
            .product-content { padding: 3.5rem 1.25rem; }
            .product-grid-detail { gap: 2.5rem; }
            
            .product-image-container { position: static; margin-bottom: 1rem; }
            .product-image { border-radius: 24px; }
            
            .product-text h2 { font-size: 1.85rem; margin-bottom: 1rem; }
            .product-text p { font-size: 1rem; line-height: 1.7; }
            
            .feature-list { margin-bottom: 2rem; }
            .feature-list li { font-size: 0.95rem; margin-bottom: 1rem; }
            
            .applications-grid { grid-template-columns: 1fr; gap: 1rem; margin-top: 2rem; }
            .app-card { padding: 1.5rem; border-radius: 20px; }
            
            .cta-box { padding: 3.5rem 1.25rem; border-radius: 32px; margin-top: 4rem; }
            .cta-box h2 { font-size: 2.1rem; }
            .cta-box p { font-size: 1.05rem; margin-bottom: 2rem; }
        }
    </style>
</head>
<body>
    <div class="mobile-overlay" id="mobileOverlay"></div>
    <div class="mobile-menu" id="mobileMenu">
        <button class="mobile-menu-close" onclick="toggleMenu()" aria-label="<?php echo __p('nav.close_menu'); ?>">
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
        <a href="tracker.php" class="mobile-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            <?php echo __p('nav.tracker'); ?>
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

    <a href="tel:+919954440122" class="floating-call-btn" id="floatingCallBtn">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
    </a>
    <script src="assets/js/main.js" defer></script>
    <script>
    const floatingBtn = document.getElementById('floatingCallBtn');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 120) floatingBtn.classList.add('show');
        else floatingBtn.classList.remove('show');
    });
    </script>

    <header>
        <a href="index.php"><img src="Images/logo.png" alt="<?php echo __p('nav.home'); ?> - <?php echo $brand_name; ?>" class="logo"></a>
        
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
            <a href="tracker.php" class="nav-link"><?php echo __p('nav.tracker'); ?></a>
            <a href="tel:+919954440122" class="contact-chip">
                <svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
                +91- 9954440122
            </a>
        </nav>
    </header>
