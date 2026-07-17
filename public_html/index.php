<?php
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/business_helper.php';
$brand_cfg = getBrandConfig();
$brand_name = htmlspecialchars($brand_cfg['label']);
$brand_legal = htmlspecialchars($brand_cfg['business_name']);

// Fetch latest 3 posts for the homepage
$stmt = $pdo->query("SELECT * FROM posts ORDER BY created_at DESC LIMIT 3");
$latest_posts = $stmt->fetchAll();
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo $brand_name; ?>: Top supplier of industrial and medical gas cylinders in Khagaria, Bihar. Reliable Oxygen, Nitrogen, Argon, and CO2 refill services for hospitals and industries.">
    <meta name="keywords" content="<?php echo $brand_name; ?>, Oxygen Cylinder Bihar, Medical Gas Supplier Khagaria, Industrial Gas Refill, Argon Gas Bihar, CO2 Cylinder Khagaria">
    <meta name="author" content="<?php echo $brand_name; ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo getSiteUrl('/'); ?>">
    <!-- Open Graph / Facebook / WhatsApp -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $brand_name; ?> - Industrial & Medical Gas Supplier">
    <meta property="og:description" content="Trusted supplier of Industrial & Medical Gas Cylinders in Bihar and Arunachal Pradesh.">
    <meta property="og:url" content="<?php echo getSiteUrl('/'); ?>">
    <meta property="og:image" content="<?php echo getSiteUrl('Images/feature.png'); ?>">
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $brand_name; ?> - Industrial & Medical Gas Supplier">
    <meta name="twitter:description" content="Reliable Industrial & Medical Gas Supply Partner in Bihar.">
    <meta name="twitter:image" content="<?php echo getSiteUrl('Images/feature.png'); ?>">
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
      "url": "<?php echo getSiteUrl('/'); ?>",
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
        "Khagaria",
        "Dibrugarh",
        "Duliajan",
        "Digboi",
        "Naharkatia",
        "Doomdooma",
        "Chabua",
        "Upper Bihar",
        "Arunachal Pradesh"
      ],
      "description": "Trusted supplier of industrial and medical gas cylinders in Bihar and Arunachal Pradesh.",
      "sameAs": [
        "<?php echo getSiteUrl('/'); ?>"
      ]
    }
    </script>

    <title><?php echo $brand_name; ?> - Industrial & Medical Gas Supplier in Bihar</title>
    
    <!-- WebPage Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "<?php echo $brand_name; ?> - Industrial & Medical Gas Supplier in Bihar",
      "description": "<?php echo $brand_name; ?>: Top supplier of industrial and medical gas cylinders in Khagaria, Bihar. Reliable Oxygen, Nitrogen, Argon, and CO2 refill services for hospitals and industries.",
      "url": "<?php echo getSiteUrl('/'); ?>",
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
    <?php include 'header-meta.php'; ?>
    
    <style>
        .home-blog-section { padding: 4rem 2rem 8rem; background: white; }
        .blog-card-home { background: #f8fafc; border-radius: 32px; overflow: hidden; border: 1px solid var(--border); transition: all 0.3s ease; text-decoration: none; color: inherit; display: flex; flex-direction: column; }
        .blog-card-home:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.05); border-color: var(--accent); }
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

    <a href="tel:+919954440122" class="floating-call-btn" id="floatingCallBtn">
    <svg viewBox="0 0 24 24" fill="currentColor">
        <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
    </svg>
    </a>
    <script src="assets/js/main.js"></script>
    <script>
    const floatingBtn = document.getElementById('floatingCallBtn');

    window.addEventListener('scroll', () => {
        if (window.scrollY > 120) {
            floatingBtn.classList.add('show');
        } else {
            floatingBtn.classList.remove('show');
        }
    });
    </script>

    <div class="gradient-bg" id="hero">
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
                <a href="blog.php" class="nav-link"><?php echo __p('nav.blog'); ?></a>
                <a href="tel:+919954440122" class="contact-chip">
                    <svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
                    +91- 9954440122
                </a>
            </nav>
        </header>

        <main>
            <div class="hero-content">
                <h1><?php echo __p('home.hero_title'); ?></h1>
                <p class="subhead"><?php echo __p('home.hero_desc'); ?></p>
                <a href="https://wa.me/919954440122" class="btn-primary">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.335-1.662c1.72.94 3.659 1.437 5.634 1.437h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                    <?php echo __p('home.hero_cta'); ?>
                </a>
            </div>
            <div class="hero-image-container">
                <img src="Images/image 1.png" alt="Gas Cylinders" class="hero-image">
            </div>
        </main>

        <div class="section-divider" id="products">
            <h2><?php echo __p('home.products_heading'); ?></h2>
            <p>We supply high-quality industrial, medical, and commercial gas cylinders for various industries, hospitals, workshops, laboratories, and businesses across Bihar and Arunachal Pradesh.</p>
        </div>

        <div class="product-grid">
            <!-- 1. Hydrogen -->
            <a href="hydrogen-gas-distributor-bihar.php" class="product-card">
                <div class="card-img" style="background-image: url('Images/hydraogen.jpg')"></div>
                <div class="card-content">
                    <div class="card-title">Hydrogen Cylinders</div>
                    <div class="card-desc">Safe and dependable hydrogen gas supply for industrial and specialized commercial use.</div>
                </div>
            </a>

            <!-- 2. Argon -->
            <a href="argon-gas-cylinder-bihar.php" class="product-card">
                <div class="card-img" style="background-image: url('Images/argon.jpg')"></div>
                <div class="card-content">
                    <div class="card-title">Argon Cylinders</div>
                    <div class="card-desc">High-purity argon gas cylinders suitable for welding, metal fabrication, and industrial processes.</div>
                </div>
            </a>

            <!-- 3. Medical -->
            <a href="medical-oxygen-cylinder-khagaria.php" class="product-card">
                <div class="card-img" style="background-image: url('Images/medical.jpg')"></div>
                <div class="card-content">
                    <div class="card-title">Medical Cylinders</div>
                    <div class="card-desc">Trusted medical gas cylinders supplied for hospitals, healthcare facilities, and emergency medical requirements.</div>
                </div>
            </a>

            <!-- 4. Acetylene -->
            <a href="acetylene-gas-supplier-khagaria.php" class="product-card">
                <div class="card-img" style="background-image: url('Images/actyline.jpg')"></div>
                <div class="card-content">
                    <div class="card-title">Acetylene Cylinders (DA Gas)</div>
                    <div class="card-desc">Efficient acetylene gas supply for cutting, welding, and industrial applications.</div>
                </div>
            </a>

            <!-- 5. Nitrous -->
            <a href="nitrous-oxide-cylinder-bihar.php" class="product-card">
                <div class="card-img" style="background-image: url('Images/nitrious.jpg')"></div>
                <div class="card-content">
                    <div class="card-title">Nitrous Cylinders</div>
                    <div class="card-desc">Quality nitrous gas cylinders for industrial and medical applications.</div>
                </div>
            </a>

            <!-- 6. CO2 -->
            <a href="co2-gas-supplier-khagaria.php" class="product-card">
                <div class="card-img" style="background-image: url('Images/carbon.jpg')"></div>
                <div class="card-content">
                    <div class="card-title">CO2 Cylinders</div>
                    <div class="card-desc">Reliable carbon dioxide gas cylinders for industrial, commercial, and refrigeration needs.</div>
                </div>
            </a>

            <!-- 7. Refrigerant -->
            <a href="refrigerant-gas-supplier-bihar.php" class="product-card">
                <div class="card-img" style="background-image: url('Images/refri.webp')"></div>
                <div class="card-content">
                    <div class="card-title">Refrigerant Gas</div>
                    <div class="card-desc">Supply of refrigerant gases for cooling systems, refrigeration units, and industrial equipment.</div>
                </div>
            </a>

            <!-- 8. And Beyond -->
            <a href="cylinder-hardware-khagaria.php" class="product-card">
                <div class="card-blue-box">
                    <div class="card-title" style="font-size: 1.5rem; text-align: center;">And Beyond</div>
                    <div class="card-desc" style="text-align: center;">We also provide additional industrial gas solutions based on customer and business requirements.</div>
                </div>
            </a>
        </div>

    <!-- Product Comparison Table -->
    <div class="section-divider">
        <h2><?php echo __p('home.compare_heading'); ?></h2>
        <p><?php echo __p('home.compare_desc'); ?></p>
    </div>
    <div class="compare-table-wrapper">
        <table class="compare-table">
            <thead>
                <tr>
                    <th><?php echo __p('home.compare_col_gas'); ?></th>
                    <th><?php echo __p('home.compare_col_uses'); ?></th>
                    <th><?php echo __p('home.compare_col_availability'); ?></th>
                    <th><?php echo __p('home.compare_col_inquiry'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td>Oxygen</td><td>Medical, Welding, Water Treatment</td><td>In Stock</td><td><a href="oxygen-gas-supplier-khagaria.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>Hydrogen</td><td>Industrial, Chemical Processing</td><td>In Stock</td><td><a href="hydrogen-gas-distributor-bihar.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>Argon</td><td>Welding, Metal Fabrication</td><td>In Stock</td><td><a href="argon-gas-cylinder-bihar.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>Acetylene</td><td>Cutting, Welding</td><td>In Stock</td><td><a href="acetylene-gas-supplier-khagaria.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>CO₂</td><td>Industrial, Refrigeration</td><td>In Stock</td><td><a href="co2-gas-supplier-khagaria.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>Nitrous Oxide</td><td>Medical, Industrial</td><td>In Stock</td><td><a href="nitrous-oxide-cylinder-bihar.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>Refrigerant Gas</td><td>Cooling Systems, Refrigeration</td><td>In Stock</td><td><a href="refrigerant-gas-supplier-bihar.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>Medical Cylinders</td><td>Healthcare, Emergency</td><td>In Stock</td><td><a href="medical-oxygen-cylinder-khagaria.php#enquiry" class="btn-primary" style="padding:0.4rem 1rem;font-size:0.8rem;border-radius:8px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
            </tbody>
        </table>
    </div>

    <section class="service-section" id="network">
            <div class="service-container">
                <h2><?php echo __p('home.service_heading'); ?></h2>
                <p class="service-desc">Based in Khagaria, Bihar, we proudly supply industrial and medical gas cylinders across Upper Bihar with reliable delivery and customer support. Our service network covers major industrial, commercial, and nearby regional areas to ensure timely and uninterrupted gas supply solutions.</p>
                
                <div class="tag-container">
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Khagaria
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Duliajan
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Digboi
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Naharkatia
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Kakopathar
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Sadiya
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Namrup
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Chabua
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Doomdooma
                    </div>
                    <div class="tag-pill">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Dibrugarh
                    </div>
                    <div class="tag-pill highlight">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Nearby Industrial & Commercial Regions
                    </div>
                </div>
            </div>
        </section>

        <section class="about-section" id="about">
            <div class="about-content">
                <h2><?php echo __p('home.about_heading'); ?></h2>
                <h3>Reliable Industrial & Medical Gas Supply Partner in Bihar</h3>
                <p class="about-text"><?php echo $brand_name; ?> is a trusted supplier and distributor of industrial and medical gas cylinders based in Khagaria, Bihar. We specialize in supplying high-quality gases including Oxygen, Hydrogen, Argon, CO₂, Acetylene, Refrigerant Gas, and Medical Cylinders for industries, hospitals, and workshops.</p>
                <p class="about-text">With a strong distribution network across Upper Bihar and nearby Arunachal Pradesh, we are committed to providing reliable supply, timely delivery, and customer-focused service.</p>
            </div>
            <div class="about-features">
                <div class="feature-box">
                    <h4><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Quality Assurance</h4>
                    <p>Certified gases meeting the highest industrial and medical safety standards.</p>
                </div>
                <div class="feature-box">
                    <h4><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Timely Delivery</h4>
                    <p>Reliable logistics and distribution ensuring uninterrupted gas supply solutions.</p>
                </div>
            </div>
        </section>

        <!-- Dynamic Blog Section -->
        <section class="home-blog-section" style="background: #fdfdfd; padding: 4rem 2rem;">
            <div class="section-divider">
                <h2>Latest from Our <span>Insights</span></h2>
                <p>Stay updated with the latest in industrial gas technology and safety standards.</p>
            </div>
            
            <style>
            .blog-grid {
                display: grid;
                grid-template-columns: 1fr;
                gap: 1.5rem;
                max-width: var(--max-width);
                margin: 2rem auto;
            }

            @media (min-width: 768px) {
                .blog-grid {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 2rem;
                }
            }

            @media (min-width: 1200px) {
                .blog-grid {
                    grid-template-columns: repeat(3, 1fr);
                }
            }
            </style>

            <div class="blog-grid" style="padding-top: 0; margin-top: 0;">
                <?php foreach ($latest_posts as $post): ?>
                <a href="post.php?slug=<?php echo $post['slug']; ?>" class="blog-card-home">
                    <div class="blog-img" style="background-image: url('uploads/blog/<?php echo $post['image']; ?>'); aspect-ratio: 16/9; height: auto;"></div>
                    <div style="padding: 2rem; flex-grow: 1; display: flex; flex-direction: column;">
                        <span class="category-tag" style="color: var(--accent); font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo htmlspecialchars($post['category']); ?></span>
                        <h3 style="font-size: 1.25rem; font-weight: 800; margin: 0.75rem 0; line-height: 1.3; color: #1e293b;"><?php echo htmlspecialchars($post['title']); ?></h3>
                        <p style="color: #64748b; line-height: 1.5; font-size: 0.95rem; margin-bottom: 1.5rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?php echo htmlspecialchars($post['excerpt']); ?>
                        </p>
                        <div style="margin-top: auto; color: var(--accent); font-weight: 700; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
                            Read More
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align: center; margin-top: 2rem;">
                <a href="blog.php" class="btn-primary" style="background: transparent; color: var(--fg); border: 2px solid var(--border); padding: 12px 32px; font-size: 0.95rem;">View All Insights</a>
            </div>
        </section>

        <section class="contact-section" id="contact">
            <div class="contact-header">
                <h2>Get In Touch</h2>
                <p>Need Industrial or Medical Gas Supply? Contact Us Today.</p>
                <p style="margin-top: 1rem; font-size: 1rem; color: var(--muted);">Our team is ready to assist you with gas supply inquiries, refill services, and bulk orders across Bihar.</p>
            </div>

            <div class="contact-grid">
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </div>
                    <h3>+91  9954440122</h3>
                    <p>Call us for orders & refills</p>
                    <span class="card-label">Phone Support</span>
                </div>

                <div class="contact-card">
                    <div class="contact-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                     <p><?php echo $brand_name; ?>,Namghar Road,opposite Manav Kalyan Puja Bhawan</p>
                    <h3>Khagaria, Bihar</h3>
                    <p>Operational Hub & Headquarters</p>
                    <span class="card-label">Our Location</span>
                </div>

                <div class="contact-card">
                    <div class="contact-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3>nandkishoremahato16@gmail.com</h3>
                    <p>Official Email Inquiries</p>
                    <span class="card-label">Email Us</span>
                </div>
            </div>

            <div class="cta-group">
                <a href="https://wa.me/919954440122" class="btn-whatsapp">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.335-1.662c1.72.94 3.659 1.437 5.634 1.437h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                    WhatsApp Us
                </a>
                <a href="mailto:nandkishoremahato16@gmail.com" class="btn-email-cta">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Email Us
                </a>
            </div>

            <div class="map-placeholder">
                <iframe 
                    src="https://www.google.com/maps?q=Manav+Kalyan+Namghar+Road+Khagaria&output=embed"
                    width="100%" 
                    height="100%" 
                    style="border:0; border-radius:48px;"
                    allowfullscreen=""
                    loading="lazy">
                </iframe>
            </div>
        </section>


        <section class="testimonial-section">
            <div class="section-divider">
                <h2>What Our Clients Say</h2>
                <p>Trusted by industries, workshops, and healthcare facilities across Bihar.</p>
            </div>

            <div class="testimonial-slider">
                <div class="testimonial-track">
                    <div class="testimonial-card">
                        <p class="testimonial-text">
                            "Reliable oxygen cylinder supply and excellent delivery support. Highly recommended for industrial requirements."
                        </p>
                        <div class="testimonial-user">
                            <h4>Rajesh Sharma</h4>
                            <span>Workshop Owner, Khagaria</span>
                        </div>
                    </div>

                    <div class="testimonial-card">
                        <p class="testimonial-text">
                            "Professional service and timely refill support. <?php echo $brand_name; ?> has been very dependable for our business."
                        </p>
                        <div class="testimonial-user">
                            <h4>Amit Agarwal</h4>
                            <span>Industrial Client, Dibrugarh</span>
                        </div>
                    </div>

                    <div class="testimonial-card">
                        <p class="testimonial-text">
                            "Very smooth experience ordering medical gas cylinders. Quick response and reliable delivery."
                        </p>
                        <div class="testimonial-user">
                            <h4>Priyanshu Das</h4>
                            <span>Healthcare Support, Digboi</span>
                        </div>
                    </div>

                    <div class="testimonial-card">
                        <p class="testimonial-text">
                            "Quality cylinders and excellent customer support. Their supply network is very strong across Upper Bihar."
                        </p>
                        <div class="testimonial-user">
                            <h4>Vikash Gupta</h4>
                            <span>Business Owner, Doomdooma</span>
                        </div>
                    </div>

                    <!-- duplicate cards for seamless loop -->
                    <div class="testimonial-card">
                        <p class="testimonial-text">
                            "Reliable oxygen cylinder supply and excellent delivery support. Highly recommended for industrial requirements."
                        </p>
                        <div class="testimonial-user">
                            <h4>Rajesh Sharma</h4>
                            <span>Workshop Owner, Khagaria</span>
                        </div>
                    </div>

                    <div class="testimonial-card">
                        <p class="testimonial-text">
                            "Professional service and timely refill support. <?php echo $brand_name; ?> has been very dependable for our business."
                        </p>
                        <div class="testimonial-user">
                            <h4>Amit Agarwal</h4>
                            <span>Industrial Client, Dibrugarh</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="testimonial-controls">
                <button class="testimonial-btn" id="testimonialToggle" aria-label="Pause testimonial slideshow" onclick="toggleTestimonial()">
                    <svg viewBox="0 0 24 24" id="testimonialIcon"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                </button>
            </div>
            <script>
            var testimonialTrack = document.querySelector('.testimonial-track');
            var testimonialIcon = document.getElementById('testimonialIcon');
            var testimonialToggle = document.getElementById('testimonialToggle');
            var testimonialRunning = true;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                testimonialRunning = false;
                if (testimonialIcon) { testimonialIcon.innerHTML = '<polygon points="5 3 19 12 5 21 5 3"/>'; }
                if (testimonialToggle) { testimonialToggle.setAttribute('aria-label', 'Play testimonial slideshow'); }
            }
            function toggleTestimonial() {
                if (!testimonialTrack) return;
                testimonialRunning = !testimonialRunning;
                testimonialTrack.style.animationPlayState = testimonialRunning ? 'running' : 'paused';
                if (testimonialIcon) {
                    testimonialIcon.innerHTML = testimonialRunning
                        ? '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>'
                        : '<polygon points="5 3 19 12 5 21 5 3"/>';
                }
                if (testimonialToggle) {
                    testimonialToggle.setAttribute('aria-label', testimonialRunning ? 'Pause testimonial slideshow' : 'Play testimonial slideshow');
                }
            }
            </script>
        </section>

    <!-- Trust Badges -->
    <section class="trust-section">
        <div class="trust-badges">
            <div class="trust-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span>ISI Certified Cylinders</span>
            </div>
            <div class="trust-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32"><circle cx="12" cy="12" r="10"/><path d="M8 12l2 2 4-4"/></svg>
                <span>ISO 9001:2015 Compliant</span>
            </div>
            <div class="trust-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                <span>PESO Approved</span>
            </div>
            <div class="trust-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                <span>24/7 Emergency Support</span>
            </div>
        </div>
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
                            <li><a href="#about"><?php echo __p('footer.about'); ?></a></li>
                            <li><a href="#network"><?php echo __p('footer.service_network'); ?></a></li>
                        </ul>
                    </div>
                    <div class="footer-nav-col">
                        <h5><?php echo __p('footer.products'); ?></h5>
                        <ul>
                            <li><a href="#products"><?php echo __p('footer.gas_cylinders'); ?></a></li>
                            <li><a href="#products"><?php echo __p('footer.refill_services'); ?></a></li>
                            <li><a href="#products"><?php echo __p('footer.medical_gases'); ?></a></li>
                        </ul>
                    </div>
                    <div class="footer-nav-col">
                        <h5><?php echo __p('footer.contact'); ?></h5>
                        <ul>
                            <li><a href="#contact"><?php echo __p('footer.get_in_touch'); ?></a></li>
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
    </div>
</body>
</html>
