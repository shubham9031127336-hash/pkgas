<?php
$page_title = "My Profile";
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/csrf.php';
require_once __DIR__ . '/../admin/gst/json/schema.php';

$customer_id = get_customer_id();
$success = '';
$error = '';

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    if (isset($_POST['update_profile'])) {
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $gst_number = trim($_POST['gst_number'] ?? '');
        $state_code = intval($_POST['state_code'] ?? 0);
        $city = trim($_POST['city'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');
        $states = gstnStateCodes();
        $state_name = $state_code ? ($states[$state_code] ?? null) : null;

        if (empty($name) || empty($mobile)) {
            $error = 'Name and mobile are required.';
        } else {
            try {
                $upd = $pdo->prepare("UPDATE customers SET name = ?, mobile = ?, address = ?, gst_number = ?, state_code = ?, state_name = ?, city = ?, pincode = ? WHERE id = ?");
                $upd->execute([$name, $mobile, $address, $gst_number, $state_code ?: null, $state_name, $city ?: null, $pincode ?: null, $customer_id]);

                $_SESSION['customer_name'] = $name;
                $customer['name'] = $name;
                $customer['mobile'] = $mobile;
                $customer['address'] = $address;
                $customer['gst_number'] = $gst_number;
                $customer['state_code'] = $state_code;
                $customer['state_name'] = $state_name;
                $customer['city'] = $city;
                $customer['pincode'] = $pincode;

                $success = 'Profile updated successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to update profile.';
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            $error = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif (empty($customer['password_hash']) || !password_verify($current, $customer['password_hash'])) {
            $error = 'Current password is incorrect.';
        } else {
            try {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE customers SET password_hash = ? WHERE id = ?");
                $upd->execute([$hash, $customer_id]);
                $success = 'Password changed successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to change password.';
            }
        }
    }
}
?>
<div class="page-header">
    <h1>My Profile</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card-grid">
    <div class="card">
        <div class="card-header"><h2>Account Information</h2></div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="update_profile" value="1">

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Email</label>
                    <input type="email" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" disabled style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;background:var(--bg);color:var(--muted);">
                    <small style="color:var(--muted);font-size:0.75rem;">Email cannot be changed. Contact support.</small>
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($customer['name']); ?>" required style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Mobile</label>
                    <input type="text" name="mobile" value="<?php echo htmlspecialchars($customer['mobile']); ?>" required style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Address</label>
                    <textarea name="address" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;resize:vertical;" rows="3"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">State</label>
                    <select name="state_code" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;background:var(--bg);">
                        <option value="">-- Select State --</option>
                        <?php foreach (gstnStateCodes() as $code => $name): ?>
                        <option value="<?= $code ?>" <?= ($customer['state_code'] ?? 0) == $code ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(strtolower($name))) ?> (<?= $code ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
                    <div>
                        <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">City</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($customer['city'] ?? ''); ?>" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Pincode</label>
                        <input type="text" name="pincode" value="<?php echo htmlspecialchars($customer['pincode'] ?? ''); ?>" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                    </div>
                </div>

                <div style="margin-bottom:1.5rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">GST Number</label>
                    <input type="text" name="gst_number" value="<?php echo htmlspecialchars($customer['gst_number'] ?? ''); ?>" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                </div>

                <button type="submit" class="btn-filter" style="width:100%;padding:0.85rem;font-size:1rem;">Save Changes</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Change Password</h2></div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="change_password" value="1">

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Current Password</label>
                    <input type="password" name="current_password" required style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">New Password</label>
                    <input type="password" name="new_password" required minlength="6" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                </div>

                <div style="margin-bottom:1.5rem;">
                    <label style="display:block;font-size:0.8rem;font-weight:700;color:var(--muted);margin-bottom:0.4rem;">Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="6" style="width:100%;padding:0.75rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;">
                </div>

                <button type="submit" class="btn-filter" style="width:100%;padding:0.85rem;font-size:1rem;">Change Password</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
