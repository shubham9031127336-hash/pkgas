<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../admin/business_helper.php';
require_customer_login();

$portal_brand = getBrandConfig();
$portal_brand_name = htmlspecialchars($portal_brand['label']);

if (!isset($page_title)) {
    $page_title = "Dashboard";
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header_remove('X-Powered-By');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');
$active_page = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../Images/favicon.png">
    <title><?php echo htmlspecialchars($page_title); ?> - Customer Portal - <?php echo $portal_brand_name; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/portal.css">
</head>
<body>
    <nav class="portal-nav">
        <div class="nav-brand">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2c0 0-8 4.5-8 12a8 8 0 0 0 16 0c0-7.5-8-12-8-12z"/>
                <path d="M12 12c0 0-3 1.5-3 4a3 3 0 0 0 6 0c0-2.5-3-4-3-4z"/>
            </svg>
            <span class="brand-text"><?php echo $portal_brand_name; ?></span>
        </div>
        <div class="nav-links">
            <a href="dashboard.php" class="<?php echo $active_page === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="orders.php" class="<?php echo $active_page === 'orders' || $active_page === 'order-detail' ? 'active' : ''; ?>">Orders</a>
            <a href="cylinders.php" class="<?php echo $active_page === 'cylinders' || $active_page === 'cylinder-detail' ? 'active' : ''; ?>">Cylinders</a>
            <a href="refill-services.php" class="<?php echo $active_page === 'refill-services' || $active_page === 'refill-service-detail' ? 'active' : ''; ?>">Refill Services</a>
            <a href="payments.php" class="<?php echo $active_page === 'payments' || $active_page === 'make-payment' ? 'active' : ''; ?>">Payments</a>
            <a href="profile.php" class="<?php echo $active_page === 'profile' ? 'active' : ''; ?>">Profile</a>
        </div>
        <div class="nav-user">
            <span class="user-name"><?php echo htmlspecialchars(get_customer_name()); ?></span>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <main class="portal-main">
        <div class="container">
            <?php if (isset($_SESSION['success_flash'])): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success_flash']); unset($_SESSION['success_flash']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_flash'])): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error_flash']); unset($_SESSION['error_flash']); ?></div>
            <?php endif; ?>
