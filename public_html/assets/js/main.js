function toggleMenu() {
    const hamburger = document.getElementById('hamburger');
    const mobileMenu = document.getElementById('mobileMenu');
    const mobileOverlay = document.getElementById('mobileOverlay');
    
    if (hamburger && mobileMenu && mobileOverlay) {
        hamburger.classList.toggle('active');
        mobileMenu.classList.toggle('active');
        mobileOverlay.classList.toggle('active');
        document.body.style.overflow = mobileMenu.classList.contains('active') ? 'hidden' : 'auto';
    }
}

// Close menu on link click
document.addEventListener('DOMContentLoaded', () => {
    const mobileLinks = document.querySelectorAll('.mobile-link');
    mobileLinks.forEach(link => {
        link.addEventListener('click', () => {
            const mobileMenu = document.getElementById('mobileMenu');
            if (mobileMenu && mobileMenu.classList.contains('active')) {
                toggleMenu();
            }
        });
    });

    const mobileOverlay = document.getElementById('mobileOverlay');
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', () => {
            toggleMenu();
    });
}

// CTA click tracking for conversion analytics
document.addEventListener('click', function(e) {
    var cta = e.target.closest('[data-track]');
    if (!cta) return;
    var action = cta.getAttribute('data-track');
    var label = cta.getAttribute('data-track-label') || '';
    if (typeof gtag === 'function') {
        gtag('event', 'click', { 'event_category': 'CTA', 'event_action': action, 'event_label': label });
    }
});

// Track WhatsApp, Phone, Email links automatically
document.addEventListener('click', function(e) {
    var link = e.target.closest('a[href^="https://wa.me"], a[href^="tel:"], a[href^="mailto:"]');
    if (!link) return;
    var href = link.getAttribute('href');
    var action = 'contact';
    if (href.indexOf('wa.me') > -1) action = 'whatsapp';
    else if (href.indexOf('tel:') === 0) action = 'phone';
    else if (href.indexOf('mailto:') === 0) action = 'email';
    var label = link.textContent.trim() || action;
    if (typeof gtag === 'function') {
        gtag('event', 'click', { 'event_category': 'Contact', 'event_action': action, 'event_label': label });
    }
});
});
