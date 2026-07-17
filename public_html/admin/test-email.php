<?php
$page_title = "Test Email";
$active_menu = "settings";
require_once __DIR__ . '/layout.php';
require_role(['super_admin']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail-config.php';
require_once __DIR__ . '/business_helper.php';

$result = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_email = trim($_POST['test_email'] ?? '');
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = intval($_POST['smtp_port'] ?? 465);
    $smtp_user = trim($_POST['smtp_user'] ?? '');
    $smtp_pass = $_POST['smtp_pass'] ?? '';
    $smtp_enc = $_POST['smtp_enc'] ?? 'ssl';
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? 'Prem Gas Solution');

    if (!$test_email) {
        $error = "Test email address is required.";
    } elseif (!$smtp_host || !$smtp_user || !$smtp_pass) {
        $error = "SMTP host, username, and password are required.";
    } else {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = ($smtp_enc === 'ssl') ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtp_port;
            $mail->setFrom($from_email ?: $smtp_user, $from_name);
            $mail->addReplyTo($from_email ?: $smtp_user, $from_name);
            $mail->addAddress($test_email);
            $mail->Subject = "SMTP Test from PKGAS";
            $mail->isHTML(true);
            $mail->Body = "<h2>SMTP Test</h2><p>This is a test email from <strong>" . htmlspecialchars($from_name) . "</strong>.</p><p>If you received this, SMTP is working correctly!</p><p>Sent at: " . date('d M Y, h:i A') . "</p>";
            $mail->send();
            $result = "SUCCESS: Email sent to <strong>" . htmlspecialchars($test_email) . "</strong>! Check inbox/spam.";
        } catch (Exception $e) {
            $error = "FAILED: " . $e->getMessage();
        } catch (\Exception $e) {
            $error = "FAILED: " . $e->getMessage();
        }
    }
}

$config = getBrandConfig();
?>
<div class="content-container">
    <h1>SMTP Test</h1>

    <?php if ($result): ?>
        <div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:6px;margin-bottom:16px;border:1px solid #c3e6cb;"><?= $result ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:6px;margin-bottom:16px;border:1px solid #f5c6cb;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;max-width:700px;">
        <p style="color:#666;margin-bottom:20px;">Current DB config will be pre-filled. Change any field to test different credentials.</p>
        <form method="post">
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Send test to (email):</label>
                <input type="email" name="test_email" required style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;" placeholder="your@email.com" value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>">
            </div>
            <hr style="margin:20px 0;border:none;border-top:1px solid #eee;">
            <h3 style="margin:0 0 14px 0;">SMTP Settings</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">SMTP Host:</label>
                    <input type="text" name="smtp_host" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;" value="<?= htmlspecialchars($_POST['smtp_host'] ?? $config['smtp_host'] ?? 'smtp.hostinger.com') ?>">
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Port:</label>
                    <input type="number" name="smtp_port" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;" value="<?= htmlspecialchars($_POST['smtp_port'] ?? $config['smtp_port'] ?? 465) ?>">
                </div>
                <div style="grid-column:span 2;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Encryption:</label>
                    <select name="smtp_enc" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;">
                        <option value="ssl" <?= ($_POST['smtp_enc'] ?? $config['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                        <option value="tls" <?= ($_POST['smtp_enc'] ?? $config['smtp_encryption'] ?? 'ssl') === 'tls' ? 'selected' : '' ?>>TLS/STARTTLS (port 587)</option>
                    </select>
                </div>
                <div style="grid-column:span 2;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Username:</label>
                    <input type="text" name="smtp_user" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;" value="<?= htmlspecialchars($_POST['smtp_user'] ?? $config['smtp_username'] ?? 'noreply@pkgas.com') ?>">
                </div>
                <div style="grid-column:span 2;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Password:</label>
                    <input type="password" name="smtp_pass" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;font-family:monospace;" value="<?= htmlspecialchars($_POST['smtp_pass'] ?? $config['smtp_password'] ?? '') ?>">
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">From Email:</label>
                    <input type="email" name="from_email" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;" value="<?= htmlspecialchars($_POST['from_email'] ?? $config['email_from_address'] ?? 'noreply@pkgas.com') ?>">
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">From Name:</label>
                    <input type="text" name="from_name" style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;" value="<?= htmlspecialchars($_POST['from_name'] ?? $config['email_from_name'] ?? 'Prem Gas Solution') ?>">
                </div>
            </div>
            <div style="margin-top:20px;">
                <button type="submit" style="background:#007bff;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:15px;cursor:pointer;font-weight:600;">Send Test Email</button>
            </div>
        </form>
    </div>

    <div style="margin-top:20px;background:#fff3cd;border:1px solid #ffeeba;border-radius:8px;padding:16px;color:#856404;">
        <strong>Note:</strong> The password field is pre-filled from the database. If the password is wrong, clear it and type the correct one.
        Check your Hostinger email panel to find the SMTP password for <code>noreply@pkgas.com</code>.
    </div>
</div>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
