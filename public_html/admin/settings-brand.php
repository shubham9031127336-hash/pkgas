<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/lang_init.php';
require_login();
$page_title = __('settings_brand.title');
$active_menu = "settings_brand";

require_once __DIR__ . '/layout.php';
require_role('super_admin');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/business_helper.php';
runBusinessConfigMigration($pdo);
runMultiBrandMigration($pdo);

$message = '';
$error = '';

// Handle Brand Config save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_brand_config') {
    $business_key = trim($_POST['business_key'] ?? '');
    if (!$business_key) {
        $error = 'Business key is required.';
    } else {
        $brand_data = [
            'business_key' => $business_key,
            'label' => trim($_POST['brand_label'] ?? ''),
            'business_name' => trim($_POST['brand_name'] ?? ''),
            'tagline' => trim($_POST['brand_tagline'] ?? ''),
            'address' => trim($_POST['brand_address'] ?? ''),
            'gstin' => trim($_POST['brand_gstin'] ?? ''),
            'phone' => trim($_POST['brand_phone'] ?? ''),
            'email' => trim($_POST['brand_email'] ?? ''),
            'website' => trim($_POST['brand_website'] ?? ''),
            'bank_details' => trim($_POST['brand_bank_details'] ?? ''),
            'invoice_terms' => trim($_POST['brand_invoice_terms'] ?? ''),
            'smtp_host' => trim($_POST['brand_smtp_host'] ?? ''),
            'smtp_port' => intval($_POST['brand_smtp_port'] ?? 587),
            'smtp_username' => trim($_POST['brand_smtp_username'] ?? ''),
            'smtp_password' => trim($_POST['brand_smtp_password'] ?? ''),
            'smtp_encryption' => trim($_POST['brand_smtp_encryption'] ?? 'tls'),
            'email_from_name' => trim($_POST['brand_email_from_name'] ?? ''),
            'email_from_address' => trim($_POST['brand_email_from_address'] ?? ''),
            'logo_path' => '',
            'logo_white_path' => '',
        ];
        $logos_dir = __DIR__ . '/../Images/logos';
        if (!is_dir($logos_dir)) @mkdir($logos_dir, 0777, true);
        if (isset($_FILES['brand_logo']) && $_FILES['brand_logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['brand_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])) {
                $target = $logos_dir . '/' . $business_key . '.' . $ext;
                move_uploaded_file($_FILES['brand_logo']['tmp_name'], $target);
                $brand_data['logo_path'] = '../Images/logos/' . $business_key . '.' . $ext;
            }
        } else {
            $existing = getBrandConfig($business_key);
            $brand_data['logo_path'] = $existing['logo_path'] ?? '';
        }
        if (isset($_FILES['brand_logo_white']) && $_FILES['brand_logo_white']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['brand_logo_white']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])) {
                $target = $logos_dir . '/' . $business_key . '_white.' . $ext;
                move_uploaded_file($_FILES['brand_logo_white']['tmp_name'], $target);
                $brand_data['logo_white_path'] = '../Images/logos/' . $business_key . '_white.' . $ext;
            }
        } else {
            $existing = getBrandConfig($business_key);
            $brand_data['logo_white_path'] = $existing['logo_white_path'] ?? '';
        }
        saveBrandConfig($pdo, $brand_data);
        $message = 'Brand "' . htmlspecialchars($business_key) . '" saved successfully.';
    }
}

// Handle brand delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_brand') {
    $business_key = trim($_POST['business_key'] ?? '');
    if ($business_key) {
        $result = deleteBrand($pdo, $business_key);
        if ($result['success']) {
            $message = 'Brand "' . htmlspecialchars($business_key) . '" deleted.';
        } else {
            $error = $result['error'] ?? 'Could not delete brand.';
        }
    }
}

// Handle set default brand
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_default_brand') {
    $business_key = trim($_POST['business_key'] ?? '');
    if ($business_key) {
        $pdo->exec("UPDATE business_config SET is_default = 0");
        $stmt = $pdo->prepare("UPDATE business_config SET is_default = 1 WHERE business_key = ?");
        $stmt->execute([$business_key]);
        $message = 'Default brand set to "' . htmlspecialchars($business_key) . '".';
    }
}

$brand_rows = loadAllBusinessConfigs();
$editing_key = $_GET['edit_brand'] ?? '';
$editing_config = null;
if ($editing_key && $editing_key !== 'new') {
    if ($brand_rows) {
        foreach ($brand_rows as $r) {
            if ($r['business_key'] === $editing_key) {
                $editing_config = $r;
                break;
            }
        }
    }
}
?>
<div style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;"><?php echo __('settings_brand.heading'); ?></h2>
    <p style="color: var(--admin-muted); font-size: 0.9rem; margin-top: 0.25rem;"><?php echo __('settings_brand.subtitle'); ?></p>
</div>

<?php if ($message): ?>
    <div class="alert-banner" style="background: var(--success-soft); color: var(--success); border-color: #a7f3d0;">
        <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert-banner" style="background: var(--danger-soft); color: var(--danger); border-color: #fca5a5;">
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="admin-card">
    <h3 class="card-title" style="display: flex; align-items: center; justify-content: space-between;">
        <span>Brand Settings</span>
        <a href="?edit_brand=new" class="btn-secondary" style="font-size: 0.8rem; padding: 6px 14px; height: auto; text-decoration: none;">
            + Add Brand
        </a>
    </h3>
    <p style="color: var(--admin-muted); font-size: 0.85rem; margin-top: 0.25rem; margin-bottom: 1.5rem;">
        Manage multiple business brands. Each brand has its own logo, contact info, invoice settings, and SMTP configuration.
    </p>

    <?php if ($brand_rows): ?>
    <div style="overflow-x: auto; margin-bottom: 1.5rem;">
        <table class="admin-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--admin-border);">
                    <th style="padding: 10px 12px; text-align: left;">Logo</th>
                    <th style="padding: 10px 12px; text-align: left;">Key</th>
                    <th style="padding: 10px 12px; text-align: left;">Label</th>
                    <th style="padding: 10px 12px; text-align: left;">Legal Name</th>
                    <th style="padding: 10px 12px; text-align: left;">GSTIN</th>
                    <th style="padding: 10px 12px; text-align: center;">Default</th>
                    <th style="padding: 10px 12px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($brand_rows as $r): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 8px 12px;">
                        <?php if (!empty($r['logo_path'])): ?>
                            <img src="../<?php echo htmlspecialchars($r['logo_path']); ?>" style="max-height: 32px; max-width: 80px;">
                        <?php else: ?>
                            <span style="color: var(--admin-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 8px 12px; font-weight: 600;"><?php echo htmlspecialchars($r['business_key']); ?></td>
                    <td style="padding: 8px 12px;"><?php echo htmlspecialchars($r['label']); ?></td>
                    <td style="padding: 8px 12px;"><?php echo htmlspecialchars($r['business_name']); ?></td>
                    <td style="padding: 8px 12px; font-family: monospace;"><?php echo htmlspecialchars($r['gstin'] ?? '—'); ?></td>
                    <td style="padding: 8px 12px; text-align: center;">
                        <?php if (!empty($r['is_default'])): ?>
                            <span class="badge badge-filled" style="font-size: 0.7rem; background: #059669; color: #fff;">Default</span>
                        <?php else: ?>
                            <form method="POST" style="display:inline;"><?php csrfField(); ?>
                                <input type="hidden" name="action" value="set_default_brand">
                                <input type="hidden" name="business_key" value="<?php echo htmlspecialchars($r['business_key']); ?>">
                                <button type="submit" class="btn-text" style="font-size: 0.75rem; color: var(--admin-accent); cursor: pointer; background: none; border: none; padding: 2px 6px; text-decoration: underline;">Set Default</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 8px 12px; text-align: right; white-space: nowrap;">
                        <a href="?edit_brand=<?php echo urlencode($r['business_key']); ?>" class="btn-text" style="font-size: 0.8rem; margin-right: 8px;">Edit</a>
                        <?php if (empty($r['is_default'])): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete brand &quot;<?php echo htmlspecialchars($r['label']); ?>&quot;? This cannot be undone.')"><?php csrfField(); ?>
                            <input type="hidden" name="action" value="delete_brand">
                            <input type="hidden" name="business_key" value="<?php echo htmlspecialchars($r['business_key']); ?>">
                            <button type="submit" class="btn-text" style="font-size: 0.8rem; color: #dc2626; background: none; border: none; cursor: pointer; padding: 0;">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($editing_key): ?>
    <hr style="border: 0; border-top: 1px solid var(--admin-border); margin: 1.5rem 0;">
    <h4 style="font-weight: 700; font-size: 1rem; margin-bottom: 1rem;">
        <?php echo $editing_key === 'new' ? 'Add New Brand' : 'Edit Brand: ' . htmlspecialchars($editing_key); ?>
    </h4>

    <?php $form = $editing_config ?: []; ?>
    <form method="POST" enctype="multipart/form-data"><?php csrfField(); ?>
        <input type="hidden" name="action" value="save_brand_config">
        <?php if ($editing_key === 'new'): ?>
        <input type="hidden" name="business_key" id="new_business_key" value="">
        <?php else: ?>
        <input type="hidden" name="business_key" value="<?php echo htmlspecialchars($editing_key); ?>">
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label class="form-label">Business Key (unique identifier)</label>
                <?php if ($editing_key === 'new'): ?>
                <input type="text" id="new_business_key_input" class="form-control" placeholder="e.g. my_brand" required oninput="document.getElementById('new_business_key').value=this.value.replace(/[^a-z0-9_]/g,'')">
                <span style="font-size: 0.7rem; color: var(--admin-muted);">Lowercase letters, numbers, and underscores only. Cannot be changed later.</span>
                <?php else: ?>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($editing_key); ?>" disabled>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Business Label (display name)</label>
                <input type="text" name="brand_label" class="form-control" value="<?php echo htmlspecialchars($form['label'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Business Name (legal, on invoices)</label>
                <input type="text" name="brand_name" class="form-control" value="<?php echo htmlspecialchars($form['business_name'] ?? ''); ?>" required>
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
                <label class="form-label">Tagline</label>
                <input type="text" name="brand_tagline" class="form-control" value="<?php echo htmlspecialchars($form['tagline'] ?? ''); ?>">
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
                <label class="form-label">Address</label>
                <textarea name="brand_address" class="form-control" rows="3"><?php echo htmlspecialchars($form['address'] ?? ''); ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">GSTIN</label>
                <input type="text" name="brand_gstin" class="form-control" value="<?php echo htmlspecialchars($form['gstin'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="brand_phone" class="form-control" value="<?php echo htmlspecialchars($form['phone'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="brand_email" class="form-control" value="<?php echo htmlspecialchars($form['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Website</label>
                <input type="url" name="brand_website" class="form-control" value="<?php echo htmlspecialchars($form['website'] ?? ''); ?>">
            </div>

        </div>

        <hr style="border: 0; border-top: 1px solid var(--admin-border); margin: 1.5rem 0;">

        <h4 style="font-weight: 700; font-size: 0.9rem; margin-bottom: 0.75rem;">Logo</h4>
        <div style="display: flex; gap: 2rem; align-items: flex-start; margin-bottom: 1.5rem;">
            <div>
                <label class="form-label">Regular Logo (light background)</label>
                <?php if (!empty($form['logo_path'])): ?>
                    <div style="margin-bottom: 0.5rem;"><img src="../<?php echo htmlspecialchars($form['logo_path']); ?>" style="max-height: 50px; border: 1px solid var(--admin-border); border-radius: 8px; padding: 4px;"></div>
                <?php endif; ?>
                <input type="file" name="brand_logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                <span style="font-size: 0.7rem; color: var(--admin-muted); display: block; margin-top: 4px;">Leave empty to keep current.</span>
            </div>
            <div>
                <label class="form-label">White Logo (dark background)</label>
                <?php if (!empty($form['logo_white_path'])): ?>
                    <div style="margin-bottom: 0.5rem;"><img src="../<?php echo htmlspecialchars($form['logo_white_path']); ?>" style="max-height: 50px; background: #1e293b; border: 1px solid var(--admin-border); border-radius: 8px; padding: 4px;"></div>
                <?php endif; ?>
                <input type="file" name="brand_logo_white" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
                <span style="font-size: 0.7rem; color: var(--admin-muted); display: block; margin-top: 4px;">Leave empty to keep current.</span>
            </div>
        </div>

        <hr style="border: 0; border-top: 1px solid var(--admin-border); margin: 1.5rem 0;">

        <h4 style="font-weight: 700; font-size: 0.9rem; margin-bottom: 0.75rem;">Invoice Settings</h4>
        <div class="form-group">
            <label class="form-label">Bank Details (appears on invoices)</label>
            <textarea name="brand_bank_details" class="form-control" rows="3" placeholder="Bank Name, Account Number, IFSC Code, etc."><?php echo htmlspecialchars($form['bank_details'] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Invoice Terms & Conditions</label>
            <textarea name="brand_invoice_terms" class="form-control" rows="3" placeholder="Terms and conditions for invoices."><?php echo htmlspecialchars($form['invoice_terms'] ?? ''); ?></textarea>
        </div>

        <hr style="border: 0; border-top: 1px solid var(--admin-border); margin: 1.5rem 0;">

        <div class="nav-dropdown">
            <button type="button" class="dropdown-toggle" onclick="toggleNavDropdown(this)" style="outline: none; font-weight: 700; font-size: 0.9rem; background: none; border: none; cursor: pointer; padding: 0.5rem 0; display: flex; align-items: center; gap: 0.5rem; width: 100%; text-align: left;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Email / SMTP Configuration
                <svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px; margin-left: auto; transition: transform 0.2s;">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
            <div class="dropdown-menu" style="display: none; margin-top: 1rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 1rem 0;">
                    <div class="form-group">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" name="brand_smtp_host" class="form-control" value="<?php echo htmlspecialchars($form['smtp_host'] ?? ''); ?>" placeholder="smtp.hostinger.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SMTP Port</label>
                        <input type="number" name="brand_smtp_port" class="form-control" value="<?php echo intval($form['smtp_port'] ?? 587); ?>" placeholder="587">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SMTP Username</label>
                        <input type="text" name="brand_smtp_username" class="form-control" value="<?php echo htmlspecialchars($form['smtp_username'] ?? ''); ?>" placeholder="noreply@example.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">SMTP Password</label>
                        <div style="position: relative;">
                            <input type="password" name="brand_smtp_password" class="form-control" value="<?php echo htmlspecialchars($form['smtp_password'] ?? ''); ?>" placeholder="SMTP password" style="padding-right: 40px;">
                            <button type="button" class="toggle-pwd-btn" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px; color: #94a3b8;" title="Show password">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SMTP Encryption</label>
                        <select name="brand_smtp_encryption" class="form-control">
                            <option value="tls" <?php echo ($form['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="ssl" <?php echo ($form['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Send From Name</label>
                        <input type="text" name="brand_email_from_name" class="form-control" value="<?php echo htmlspecialchars($form['email_from_name'] ?? ''); ?>" placeholder="Prem Gas Solution">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Send From Address</label>
                        <input type="email" name="brand_email_from_address" class="form-control" value="<?php echo htmlspecialchars($form['email_from_address'] ?? ''); ?>" placeholder="noreply@example.com">
                    </div>
                </div>
                <p style="font-size: 0.75rem; color: var(--admin-muted); margin-top: 0.5rem;">
                    If left empty, the default SMTP configuration from <code>mail-config.php</code> will be used as fallback.
                </p>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
            <button type="submit" class="btn-primary" style="justify-content: center;">
                <?php echo $editing_key === 'new' ? 'Add Brand' : 'Save Brand'; ?>
            </button>
            <a href="settings-brand.php" class="btn-secondary" style="justify-content: center; text-decoration: none;">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('.toggle-pwd-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.parentElement.querySelector('input');
            var svg = this.querySelector('svg');
            if (input.type === 'password') {
                input.type = 'text';
                svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                input.type = 'password';
                svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        });
    });
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
