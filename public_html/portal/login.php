<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/csrf.php';

if (is_customer_logged_in()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

// Rate limiting: 5 attempts per 60 seconds
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_limit_key = 'login_attempts_' . $client_ip;
$rate_limit_window = 60;
$max_attempts = 5;
if (isset($_SESSION[$rate_limit_key])) {
    $attempts = $_SESSION[$rate_limit_key];
    if ($attempts['count'] >= $max_attempts && time() - $attempts['time'] < $rate_limit_window) {
        $error = "Too many login attempts. Please try again later.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    validateCsrfToken();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ?");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer) {
                if ($customer['status'] === 'inactive') {
                    $error = 'Your account has been deactivated. Contact support.';
                } elseif (!$customer['login_enabled']) {
                    $error = 'Portal access is not enabled for your account. Contact support.';
                } elseif (!empty($customer['password_hash']) && password_verify($password, $customer['password_hash'])) {
                    unset($_SESSION[$rate_limit_key]);
                    session_regenerate_id(true);

                    $_SESSION['customer_id'] = $customer['id'];
                    $_SESSION['customer_name'] = $customer['name'];
                    $_SESSION['customer_email'] = $customer['email'];
                    $_SESSION['login_time'] = time();

                    // Remember me (30 days)
                    if (!empty($_POST['remember'])) {
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                        $pdo->prepare("UPDATE customers SET remember_token = ?, remember_expires = ? WHERE id = ?")->execute([password_hash($token, PASSWORD_BCRYPT), $expires, $customer['id']]);
                        setcookie('customer_remember', $customer['id'] . ':' . $token, time() + 86400 * 30, '/', '', isset($_SERVER['HTTPS']), true);
                    }

                    $upd = $pdo->prepare("UPDATE customers SET last_login = NOW() WHERE id = ?");
                    $upd->execute([$customer['id']]);

                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = 'Invalid email or password.';
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'System error. Please try again later.';
        }
    }

    // Record failed attempt
    if (!isset($_SESSION[$rate_limit_key])) {
        $_SESSION[$rate_limit_key] = ['count' => 1, 'time' => time()];
    } else {
        $_SESSION[$rate_limit_key]['count']++;
        $_SESSION[$rate_limit_key]['time'] = time();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    require_once __DIR__ . '/../admin/business_helper.php';
    $brand_cfg = getBrandConfig();
    ?>
    <link rel="icon" type="image/png" href="../Images/favicon.png">
    <title>Customer Portal - <?php echo htmlspecialchars($brand_cfg['label']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../admin/login.css">
</head>
<body>
    <div class="login-card">
        <div class="logo-header">
            <?php $plogo = !empty($brand_cfg['logo_white_path']) ? '../' . $brand_cfg['logo_white_path'] : '../Images/logo_white.png'; ?>
            <img src="<?php echo $plogo; ?>" alt="<?php echo htmlspecialchars($brand_cfg['label']); ?>" class="login-logo">
            <h2>Customer Portal</h2>
            <p><?php echo htmlspecialchars($brand_cfg['label']); ?> - Khagaria</p>
        </div>

        <?php if ($error): ?>
            <div class="error-banner">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php csrfField(); ?>
            <div class="form-group">
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" class="form-control" placeholder="your@email.com" required autofocus autocomplete="email">
            </div>

            <div class="form-group" style="margin-bottom: 0.75rem;">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
            </div>

            <div class="form-group" style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" name="remember" id="remember" value="1" style="accent-color: var(--accent); width: 16px; height: 16px;">
                <label for="remember" style="font-size: 0.85rem; color: var(--fg-muted); cursor: pointer;">Remember me for 30 days</label>
            </div>

            <button type="submit" class="btn-submit">
                Sign In
            </button>
        </form>

        <div class="footer-note">
            <a href="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/'); ?>/" style="color: var(--accent); text-decoration: none; font-weight: 600;">&larr; Back to Website</a>
        </div>
    </div>
</body>
</html>
