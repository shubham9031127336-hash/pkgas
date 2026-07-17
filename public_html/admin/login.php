<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lang_init.php';
require_once __DIR__ . '/csrf.php';

$base_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', str_replace('\\', '/', __DIR__));

// Safe dynamic auto-migration: if users table is missing, bootstrap it immediately!
try {
    $pdo->query("SELECT 1 FROM users LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `username` VARCHAR(50) NOT NULL UNIQUE,
          `password_hash` VARCHAR(255) NOT NULL,
          `name` VARCHAR(100) NOT NULL,
          `role` ENUM('super_admin', 'billing_clerk', 'warehouse_supervisor') NOT NULL,
          `status` ENUM('active', 'inactive') DEFAULT 'active',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_logs` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `username` VARCHAR(100) NOT NULL,
          `action` VARCHAR(255) NOT NULL,
          `details` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        $seed_pass = getenv('ADMIN_SEED_PASSWORD') ?: 'admin123';
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, name, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['admin', password_hash($seed_pass, PASSWORD_BCRYPT), 'Super Admin', 'super_admin', 'active']);
        $stmt->execute(['clerk', password_hash($seed_pass, PASSWORD_BCRYPT), 'Billing Clerk', 'billing_clerk', 'active']);
        $stmt->execute(['warehouse', password_hash($seed_pass, PASSWORD_BCRYPT), 'Warehouse Supervisor', 'warehouse_supervisor', 'active']);
    } catch (PDOException $ex) {}
}

require_once __DIR__ . '/business_helper.php';
$brand_cfg = getBrandConfig();

if (is_logged_in()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

// Rate limiting: 5 attempts per 60 seconds
$rate_limit_key = 'login_attempts_' . md5($_SERVER['REMOTE_ADDR']);
$rate_limit_window = 60;
$max_attempts = 5;
if (isset($_SESSION[$rate_limit_key])) {
    $attempts = $_SESSION[$rate_limit_key];
    if ($attempts['count'] >= $max_attempts && time() - $attempts['time'] < $rate_limit_window) {
        $wait = $rate_limit_window - (time() - $attempts['time']);
        $error = sprintf(__('login.error_rate_limit'), $wait);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    validateCsrfToken();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = __('login.error_required');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user) {
                if ($user['status'] === 'inactive') {
                    $error = __('login.error_inactive_prefix');
                } elseif (password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['name'];
                    
                    $log = $pdo->prepare("INSERT INTO activity_logs (username, action, details) VALUES (?, 'Login', ?)");
                    $log->execute([$user['username'], "Logged in successfully with role: " . $user['role']]);
                    
                    unset($_SESSION[$rate_limit_key]);
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = __('login.error_invalid');
                }
            } else {
                $error = __('login.error_invalid');
            }
        } catch (PDOException $e) {
            $error = __('login.error_system');
        }
    }
    
    // Record failed attempt
    if (!empty($error)) {
        if (!isset($_SESSION[$rate_limit_key])) {
            $_SESSION[$rate_limit_key] = ['count' => 1, 'time' => time()];
        } else {
            $_SESSION[$rate_limit_key]['count']++;
            $_SESSION[$rate_limit_key]['time'] = time();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= $base_path ?>/../Images/favicon.png">
    <title><?php echo __('login.title'); ?> - <?php echo htmlspecialchars($brand_cfg['label']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_path ?>/login.css">
</head>
<body>
    <div class="login-card">
        <div class="logo-header">
            <?php $login_logo = !empty($brand_cfg['logo_white_path']) ? '../' . $brand_cfg['logo_white_path'] : ($base_path . '/../Images/logo_white.png'); ?>
            <img src="<?php echo $login_logo; ?>" alt="<?php echo htmlspecialchars($brand_cfg['label']); ?>" class="login-logo">
            <h2><?php echo __('login.portal_name'); ?></h2>
            <p><?php echo __('login.portal_subtitle'); ?></p>
        </div>
        
        <?php if ($error): ?>
            <div class="error-banner">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <?php csrfField(); ?>
            <div class="form-group">
                <label for="username" class="form-label"><?php echo __('login.username'); ?></label>
                <input type="text" name="username" id="username" class="form-control" placeholder="<?php echo __('login.username_placeholder'); ?>" required autofocus autocomplete="username">
            </div>
            
            <div class="form-group" style="margin-bottom: 0.75rem;">
                <label for="password" class="form-label"><?php echo __('login.password'); ?></label>
                <input type="password" name="password" id="password" class="form-control" placeholder="<?php echo __('login.password_placeholder'); ?>" required autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn-submit">
                <?php echo __('login.sign_in'); ?>
            </button>
        </form>
        
        <div class="footer-note">
            🛡️ <?php echo __('login.authorized_only'); ?><br>
            <?php echo __('login.activity_logged'); ?>
        </div>
    </div>
</body>
</html>
