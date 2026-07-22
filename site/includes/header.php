<?php
require_once(__DIR__ . '/config.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . " | " . SITE_NAME : SITE_NAME; ?></title>
    <meta name="description" content="SIBA Public School - Nurturing young minds for a brighter future. Excellence in education, character, and holistic development.">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/styles.css?v=<?php echo time(); ?>">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>

<div class="announcement-bar">
    <span class="label">📢 NOTICE</span>
    <span class="ticker">Admissions Open for Academic Year 2026–27 &nbsp;|&nbsp; Apply before 30th June 2026 &nbsp;|&nbsp; Results of Annual Sports Day announced on the Notice Board</span>
</div>

<header>
    <div class="logo">
        <a href="<?php echo SITE_URL; ?>" class="brand-logo-link" aria-label="SIBA Public School Home">
            <img src="<?php echo SITE_LOGO_URL; ?>" alt="SIBA Public School Logo" class="brand-logo brand-logo-header">
        </a>
    </div>
    <nav>
        <button class="mobile-menu-toggle" aria-label="Toggle mobile menu">
            <i class="fas fa-bars"></i>
        </button>
        <ul class="nav-links">
            <li><a href="<?php echo SITE_URL; ?>/index.php" <?php echo (basename($_SERVER['PHP_SELF'])=='index.php')?'class="active"':''; ?>>Home</a></li>
            <li><a href="<?php echo SITE_URL; ?>/about.php" <?php echo (basename($_SERVER['PHP_SELF'])=='about.php')?'class="active"':''; ?>>About Us</a></li>
            <li><a href="<?php echo SITE_URL; ?>/academics.php" <?php echo (basename($_SERVER['PHP_SELF'])=='academics.php')?'class="active"':''; ?>>Academics</a></li>
            <li><a href="<?php echo SITE_URL; ?>/admissions.php" <?php echo (basename($_SERVER['PHP_SELF'])=='admissions.php')?'class="active"':''; ?>>Admissions</a></li>
            <li><a href="<?php echo SITE_URL; ?>/events.php" <?php echo (basename($_SERVER['PHP_SELF'])=='events.php')?'class="active"':''; ?>>Events &amp; News</a></li>
            <li><a href="<?php echo SITE_URL; ?>/contact.php" <?php echo (basename($_SERVER['PHP_SELF'])=='contact.php')?'class="active"':''; ?>>Contact</a></li>
        </ul>
    </nav>
    <div class="cta-btns">
        <?php if (isset($_SESSION['parent_id'])): ?>
            <a href="<?php echo SITE_URL; ?>/parent/dashboard.php" class="btn btn-primary"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <?php elseif (isset($_SESSION['admin_id'])): ?>
            <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="btn btn-primary"><i class="fas fa-shield-alt"></i> Admin</a>
        <?php else: ?>
            <a href="<?php echo SITE_URL; ?>/parent/login.php" class="btn btn-outline-primary btn" title="My Account"><i class="fas fa-user-circle" style="font-size:1.3rem;"></i></a>
            <a href="<?php echo SITE_URL; ?>/parent/register.php" class="btn btn-accent"><i class="fas fa-user-plus"></i> Admission</a>
        <?php endif; ?>
    </div>
</header>
<main>
