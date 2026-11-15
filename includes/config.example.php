<?php
// Global Configuration — copy to config.php and fill in live credentials

// Read DB config from the shared .env (used by both site and ERP)
$envPath = __DIR__ . '/../../erp/.env';
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'siba_erp';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
    $dbHost = $env['DB_HOST'] ?? $dbHost;
    $dbUser = $env['DB_USER'] ?? $dbUser;
    $dbPass = $env['DB_PASS'] ?? $dbPass;
    $dbName = $env['DB_NAME'] ?? $dbName;
}

define('DB_HOST', $dbHost);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_NAME', $dbName);

define('SITE_NAME', 'SIBA Public School');

// Auto-detect site URL (handles root domain as well as subdirectories like /siba/)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$subDir = '';
$scriptFilename = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME']) ?: $_SERVER['SCRIPT_FILENAME']) : '';
$projRoot = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';

if ($scriptFilename && $scriptName && stripos($scriptFilename, $projRoot) === 0) {
    $rel = substr($scriptFilename, strlen($projRoot));
    if ($rel !== '' && substr($scriptName, -strlen($rel)) === $rel) {
        $subDir = rtrim(substr($scriptName, 0, -strlen($rel)), '/');
    }
} elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']);
    if (stripos($projRoot, $docRoot) === 0) {
        $subDir = rtrim(substr($projRoot, strlen($docRoot)), '/');
    }
}

define('SITE_URL', $protocol . '://' . $host . $subDir);

define('SITE_LOGO_JPG', 'assets/images/logo.jpg');
define('SITE_LOGO_PDF', 'SIBA LOGO PRIMARY 01-1.pdf');

$logoJpgPath = __DIR__ . '/../' . SITE_LOGO_JPG;
if (file_exists($logoJpgPath)) {
    define('SITE_LOGO_URL', SITE_URL . '/' . SITE_LOGO_JPG);
} else {
    define('SITE_LOGO_URL', SITE_URL . '/' . rawurlencode(SITE_LOGO_PDF));
}

// Razorpay API Keys (update with live keys)
define('RAZORPAY_KEY_ID', 'rzp_test_XXXXXXXXXXXXXXXX');
define('RAZORPAY_KEY_SECRET', 'YOUR_SECRET_HERE');
define('APPLICATION_FEE', 200);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
