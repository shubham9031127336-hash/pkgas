<?php
$error_code = http_response_code() ?: 500;
$error_title = $error_code === 404 ? 'Page Not Found' : 'Something Went Wrong';
$error_message = $error_code === 404
    ? 'The page you are looking for does not exist or has been moved.'
    : 'We encountered an unexpected error. Our team has been notified.';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Error — Prem Gas Solution</title>
<link rel="icon" type="image/png" href="../Images/favicon.png">
<link rel="stylesheet" href="error-page.css">
</head>
<body>
  <div class="error-card">
    <div class="error-code"><?php echo $error_code; ?></div>
    <h1><?php echo $error_title; ?></h1>
    <p><?php echo $error_message; ?></p>
    <a href="dashboard.php" class="btn-home">Back to Dashboard</a>
  </div>
</body>
</html>
