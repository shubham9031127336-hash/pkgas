        </div>
    </main>

    <?php if (!isset($portal_brand_name)) { require_once __DIR__ . '/../admin/business_helper.php'; $portal_brand_name = htmlspecialchars(getBrandConfig()['label']); } ?>
    <footer class="portal-footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo $portal_brand_name; ?>. All rights reserved.</p>
    </footer>

    <script src="assets/js/portal.js"></script>
</body>
</html>
