<?php
// Global Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'siba_erp');

define('SITE_NAME', 'SIBA Public School');
define('SITE_URL', 'http://localhost/siba/site');
define('SITE_LOGO_JPG', 'assets/images/logo.jpg');
define('SITE_LOGO_PDF', 'SIBA LOGO PRIMARY 01-1.pdf');

$logoJpgPath = __DIR__ . '/../' . SITE_LOGO_JPG;
if (file_exists($logoJpgPath)) {
    define('SITE_LOGO_URL', SITE_URL . '/' . SITE_LOGO_JPG);
} else {
    define('SITE_LOGO_URL', SITE_URL . '/' . rawurlencode(SITE_LOGO_PDF));
}

// Razorpay API Keys (Test Mode)
define('RAZORPAY_KEY_ID', 'rzp_test_XXXXXXXXXXXXXXXX');
define('RAZORPAY_KEY_SECRET', 'YOUR_SECRET_HERE');
define('APPLICATION_FEE', 500); // Application fee in INR

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
