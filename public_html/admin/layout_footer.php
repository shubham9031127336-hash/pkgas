        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="flatpickr-init.min.js?v=<?php echo filemtime(__DIR__.'/flatpickr-init.min.js'); ?>"></script>

    <!-- Universal Layout Scripts -->
    <script src="admin.min.js?v=<?php echo filemtime(__DIR__.'/admin.min.js'); ?>"></script>

    <!-- Modal body scroll lock -->
    <script>
    (function() {
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (m.type === 'attributes' && m.attributeName === 'class') {
                    var modal = m.target;
                    if (modal.classList.contains('active')) {
                        document.body.classList.add('modal-open');
                    } else {
                        var anyActive = document.querySelector('.modal.active');
                        if (!anyActive) document.body.classList.remove('modal-open');
                    }
                }
            });
        });
        document.querySelectorAll('.modal').forEach(function(el) {
            observer.observe(el, { attributes: true, attributeFilter: ['class'] });
        });
        // Watch for dynamically added modals
        var bodyObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 && node.classList && node.classList.contains('modal')) {
                        observer.observe(node, { attributes: true, attributeFilter: ['class'] });
                    }
                });
            });
        });
        bodyObserver.observe(document.body, { childList: true, subtree: true });
    })();
    </script>

    <!-- Toast container (populated by JS) -->

<?php if (ob_get_level()) { ob_end_flush(); } ?>
</body>
</html>
