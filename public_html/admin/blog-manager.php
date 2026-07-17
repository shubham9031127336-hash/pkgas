<?php
require_once __DIR__ . '/lang_init.php';
$page_title = __('blog.title');
$active_menu = "blog";
require_once __DIR__ . '/layout.php';
require_role(['super_admin', 'billing_clerk']);
require_once __DIR__ . '/db.php';

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$id]);
        echo "<script>window.location.href='blog-manager.php';</script>";
        exit();
    } catch (PDOException $e) {
        $error = __('blog.delete_failed') . $e->getMessage();
    }
}

// Fetch all posts
$stmt = $pdo->query("SELECT * FROM posts ORDER BY created_at DESC");
$posts = $stmt->fetchAll();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('blog.heading'); ?></h2>
        <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('blog.subtitle'); ?></p>
    </div>
    <a href="add-post.php" class="btn-primary" style="border-radius: 12px; padding: 0.75rem 1.5rem;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        <?php echo __('blog.new'); ?>
    </a>
</div>

<?php if (isset($error)): ?>
    <div class="alert-banner alert-warning" style="background: #fef2f2; color: #b91c1c; border-color: #fca5a5;">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="admin-card" style="padding: 0;">
    <div class="table-wrapper" style="border: none;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><?php echo __('blog.name'); ?></th>
                    <th><?php echo __('blog.category'); ?></th>
                    <th><?php echo __('blog.published_date'); ?></th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $post): ?>
                <tr>
                    <td style="display: flex; align-items: center; gap: 1rem;" data-label="<?php echo __('blog.name'); ?>">
                        <img src="../uploads/blog/<?php echo $post['image'] ?: 'default.jpg'; ?>" 
                             style="width: 48px; height: 48px; border-radius: 12px; object-fit: cover; background: #f1f5f9;"
                             onerror="this.src='https://ui-avatars.com/api/?name=Gas&background=eff6ff&color=3b82f6'">
                        <div>
                            <div style="font-weight: 700; color: var(--admin-fg);"><?php echo htmlspecialchars($post['title']); ?></div>
                            <div style="font-size: 0.8rem; color: var(--admin-muted); margin-top: 0.25rem;">/<?php echo htmlspecialchars($post['slug']); ?></div>
                        </div>
                    </td>
                    <td data-label="<?php echo __('blog.category'); ?>">
                        <span style="background: #f1f5f9; color: #475569; padding: 4px 12px; border-radius: 99px; font-size: 0.8rem; font-weight: 600;">
                            <?php echo htmlspecialchars($post['category']); ?>
                        </span>
                    </td>
                    <td style="color: var(--admin-muted); font-weight: 500;" data-label="<?php echo __('blog.published_date'); ?>"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></td>
                    <td style="text-align: right;" data-label="Actions">
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                            <a href="add-post.php?id=<?php echo $post['id']; ?>" class="btn-secondary" style="padding: 0.5rem 1rem; border-radius: 8px;"><?php echo __('blog.edit'); ?></a>
                            <a href="blog-manager.php?delete=<?php echo $post['id']; ?>" class="btn-danger" onclick="return confirm('<?php echo __('blog.delete_confirm'); ?>')" style="padding: 0.5rem 1rem; border-radius: 8px;"><?php echo __('blog.delete'); ?></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (empty($posts)): ?>
        <div style="text-align: center; padding: 4rem 0;">
            <div style="width: 64px; height: 64px; background: #f8fafc; border-radius: 99px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: #cbd5e1;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
            </div>
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--admin-fg);"><?php echo __('blog.no_data'); ?></h3>
            <p style="color: var(--admin-muted); margin-top: 0.5rem; font-size: 0.9rem;"><?php echo __('blog.no_data_desc'); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once 'layout_footer.php';
?>
