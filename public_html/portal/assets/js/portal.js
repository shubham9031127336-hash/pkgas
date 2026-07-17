(function() {
    'use strict';

    function init() {
        // Flash message auto-dismiss
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.3s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 300);
            }, 5000);
        });

        // Confirm dangerous actions
        document.querySelectorAll('[data-confirm]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                if (!confirm(el.getAttribute('data-confirm'))) {
                    e.preventDefault();
                }
            });
        });

        // Active nav highlight for sub-pages
        var navLinks = document.querySelectorAll('.nav-links a');
        var currentPath = window.location.pathname;

        navLinks.forEach(function(link) {
            var href = link.getAttribute('href');
            if (currentPath.indexOf(href) !== -1 && href !== 'dashboard.php') {
                navLinks.forEach(function(l) { l.classList.remove('active'); });
                link.classList.add('active');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
