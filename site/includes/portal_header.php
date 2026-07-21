<?php
require_once(__DIR__ . '/config.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . " | Parent Portal – " . SITE_NAME : "Parent Portal – " . SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/styles.css?v=<?php echo time(); ?>">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>

<header>
    <div class="logo">
        <a href="<?php echo SITE_URL; ?>" class="brand-logo-link" aria-label="SIBA Public School Home">
            <img src="<?php echo SITE_LOGO_URL; ?>" alt="SIBA Public School Logo" class="brand-logo brand-logo-header">
        </a>
    </div>
    <div class="cta-btns">
        <a href="<?php echo SITE_URL; ?>" class="btn btn-outline-primary btn"><i class="fas fa-home"></i> Main Site</a>
        <?php if (isset($_SESSION['parent_id'])): ?>
            <a href="<?php echo SITE_URL; ?>/logout.php" class="btn btn-primary"><i class="fas fa-sign-out-alt"></i> Logout</a>
        <?php endif; ?>
    </div>
</header>
<main>
