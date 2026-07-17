<?php
$pageTitle = "Medical Oxygen Gas Supplier in Khagaria | Prem Gas Solution Bihar";
$pageDesc = "Reliable oxygen gas supplier in Khagaria, Bihar. We provide certified medical and industrial oxygen cylinders with 24/7 emergency refill support for hospitals and industries.";
$pageKeys = "Oxygen Gas Supplier Khagaria, Medical Oxygen Bihar, Industrial Oxygen Khagaria, Prem Gas Solution Oxygen";
$canonical = "https://pkgas.com/oxygen-gas-supplier-khagaria.php";
include 'header.php';
?>

<section class="product-hero">
    <div class="container">
        <h1>Medical <span>Oxygen Gas Supplier</span> in Khagaria</h1>
        <p>Reliable distribution of certified medical and industrial oxygen gas cylinders across Khagaria, Upper Bihar, and Arunachal Pradesh. 24/7 support for life-saving and industrial needs.</p>
    </div>
</section>

<section class="product-content">
    <div class="product-grid-detail">
        <div class="product-image-container">
            <img src="Images/medical.jpg" alt="Oxygen Gas Cylinders Khagaria" class="product-image" loading="lazy">
        </div>
        <div class="product-text">
            <h2>Trusted Oxygen Supply Partner</h2>
            <p>Prem Gas Solution is a primary supplier of oxygen gas in the Khagaria region. We prioritize purity and availability, ensuring that our medical and industrial partners never face a shortage of this vital gas.</p>
            
            <ul class="feature-list">
                <li><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Certified High-Purity Oxygen Gas</li>
                <li><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> 24/7 Emergency Refill Services</li>
                <li><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Safe & Tested Cylinder Inventory</li>
                <li><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Dedicated Logistics for Timely Delivery</li>
            </ul>

            <h3>Sectors We Support</h3>
            <p>Our oxygen gas supply is critical for several local industries:</p>
            <div class="applications-grid">
                <div class="app-card">
                    <h4>Healthcare</h4>
                    <p>Vital oxygen supply for patient care, emergency rooms, and surgical centers.</p>
                </div>
                <div class="app-card">
                    <h4>Industrial Welding</h4>
                    <p>High-quality industrial oxygen for metal cutting, brazing, and fabrication.</p>
                </div>
                <div class="app-card">
                    <h4>Water Treatment</h4>
                    <p>Used in specialized water purification and environmental processes.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pricing Table -->
    <div class="pricing-section">
        <h2><?php echo __p('product.pricing_heading'); ?></h2>
        <p style="color:var(--muted);margin-bottom:2rem;"><?php echo __p('product.pricing_note'); ?></p>
        <table class="pricing-table">
            <thead>
                <tr>
                    <th><?php echo __p('product.size'); ?></th>
                    <th><?php echo __p('product.price_range'); ?></th>
                    <th><?php echo __p('product.enquire'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td>10L Cylinder</td><td>₹800 – ₹1,200</td><td><a href="#enquiry" class="btn-primary" style="padding:0.3rem 0.8rem;font-size:0.8rem;border-radius:6px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>40L Cylinder</td><td>₹1,500 – ₹2,500</td><td><a href="#enquiry" class="btn-primary" style="padding:0.3rem 0.8rem;font-size:0.8rem;border-radius:6px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
                <tr><td>47L Cylinder</td><td>₹2,000 – ₹3,500</td><td><a href="#enquiry" class="btn-primary" style="padding:0.3rem 0.8rem;font-size:0.8rem;border-radius:6px;text-decoration:none;display:inline-block;"><?php echo __p('product.enquire'); ?></a></td></tr>
            </tbody>
        </table>
    </div>

    <div class="cta-box">
        <h2>Need Oxygen Urgently?</h2>
        <p>Contact Prem Gas Solution for immediate oxygen cylinder refills or new supplies in Khagaria. We are here to support you 24/7.</p>
        <div style="display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap;">
            <a href="tel:+919954440122" class="btn-primary" style="padding: 1.25rem 3rem;">Call +91 9954440122</a>
            <a href="https://wa.me/919954440122" class="btn-primary" style="background: #25D366; border-color: #25D366; padding: 1.25rem 3rem;">WhatsApp for Oxygen</a>
        </div>
    </div>

    <!-- Enquiry Form -->
    <div class="enquiry-section" id="enquiry">
        <h2><?php echo __p('form.enquiry_title'); ?></h2>
        <form class="enquiry-form" onsubmit="return submitEnquiry(this, event);">
            <input type="hidden" name="product" value="Oxygen">
            <input type="hidden" name="redirect_whatsapp" value="https://wa.me/919954440122?text=Hi%20Prem%20Gas%20Solution%2C%20I%20am%20interested%20in%20Oxygen%20cylinders.">
            <div class="enquiry-row">
                <input type="text" name="name" placeholder="<?php echo __p('form.name'); ?>" required>
                <input type="email" name="email" placeholder="<?php echo __p('form.email'); ?>">
            </div>
            <div class="enquiry-row">
                <input type="tel" name="phone" placeholder="<?php echo __p('form.phone'); ?>" required>
            </div>
            <textarea name="message" placeholder="<?php echo __p('form.message'); ?>" rows="4"></textarea>
            <button type="submit" class="btn-primary" style="justify-content:center;width:100%;"><?php echo __p('form.submit'); ?></button>
            <p class="enquiry-msg" style="display:none;margin-top:1rem;font-weight:700;"></p>
        </form>
    </div>
</section>

<script>
function submitEnquiry(form, e) {
    e.preventDefault();
    var btn = form.querySelector('button[type="submit"]');
    var msg = form.querySelector('.enquiry-msg');
    var data = new FormData(form);
    btn.disabled = true;
    btn.textContent = '<?php echo __p('form.submitting'); ?>';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'lead-capture.php', true);
    xhr.onload = function () {
        btn.disabled = false;
        btn.textContent = '<?php echo __p('form.submit'); ?>';
        try {
            var res = JSON.parse(xhr.responseText);
            msg.style.display = 'block';
            msg.style.color = res.success ? 'var(--success, #10b981)' : 'var(--danger, #ef4444)';
            msg.textContent = res.message;
            if (res.success) {
                form.reset();
                if (res.redirect) {
                    setTimeout(function () { window.location.href = res.redirect; }, 1500);
                }
            }
        } catch(e) {
            msg.style.display = 'block';
            msg.style.color = 'var(--danger, #ef4444)';
            msg.textContent = '<?php echo __p('form.error'); ?>';
        }
    };
    xhr.onerror = function () {
        btn.disabled = false;
        btn.textContent = '<?php echo __p('form.submit'); ?>';
        msg.style.display = 'block';
        msg.style.color = 'var(--danger, #ef4444)';
        msg.textContent = '<?php echo __p('form.error'); ?>';
    };
    xhr.send(data);
}
</script>

<?php include 'footer.php'; ?>
