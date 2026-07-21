<?php
// Admin auth guard – include after db_connect
if (!isset($_SESSION['admin_id'])) {
    header("Location: " . SITE_URL . "/admin/login.php");
    exit();
}
$adminUser = $_SESSION['admin_user'] ?? 'Admin';
$currentAdminPage = basename($_SERVER['PHP_SELF']);

function adminNavLink($file, $label, $icon, $current) {
    $active = ($current == $file) ? 'active' : '';
    echo '<li><a href="' . SITE_URL . '/admin/' . $file . '" class="' . $active . '"><i class="fas fa-' . $icon . '"></i><span>' . $label . '</span></a></li>';
}
?>

<div class="admin-layout">

<!-- ===== SIDEBAR ===== -->
<aside class="admin-sidebar">
    <div class="admin-sidebar-logo">
        <a href="<?php echo SITE_URL; ?>" class="brand-logo-link" aria-label="SIBA Public School Home">
            <img src="<?php echo SITE_LOGO_URL; ?>" alt="SIBA Public School Logo" class="brand-logo brand-logo-admin">
        </a>
        <div>
            <div style="font-weight:800;font-size:0.95rem;color:white;">SIBA School</div>
            <div style="font-size:0.68rem;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.08em;">Super Admin Panel</div>
        </div>
    </div>

    <nav class="admin-nav">
        <div class="admin-nav-group-label">Main</div>
        <ul>
            <?php adminNavLink('dashboard.php',           'Dashboard',          'tachometer-alt',       $currentAdminPage); ?>
            <?php adminNavLink('manage-applications.php', 'Applications',       'file-alt',             $currentAdminPage); ?>
            <?php adminNavLink('fee-reports.php',         'Fee Reports',        'file-invoice-dollar',  $currentAdminPage); ?>
        </ul>

        <div class="admin-nav-group-label">Management</div>
        <ul>
            <?php adminNavLink('manage-staff.php',        'Staff Management',   'users-cog',            $currentAdminPage); ?>
            <?php adminNavLink('notifications.php',       'Notifications',      'bullhorn',             $currentAdminPage); ?>
        </ul>

        <div class="admin-nav-group-label">System</div>
        <ul>
            <?php adminNavLink('manage-content.php',      'Website CMS',        'pen-ruler',            $currentAdminPage); ?>
            <?php adminNavLink('settings.php',            'Settings',           'cog',                  $currentAdminPage); ?>
            <li>
                <a href="<?php echo SITE_URL; ?>" target="_blank">
                    <i class="fas fa-external-link-alt"></i><span>View Website</span>
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>/logout.php" style="color:#fca5a5;">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="admin-sidebar-footer">
        <div class="admin-user-badge">
            <div class="admin-avatar"><?php echo strtoupper(substr($adminUser, 0, 2)); ?></div>
            <div>
                <div style="font-size:0.85rem;font-weight:600;color:white;"><?php echo htmlspecialchars($adminUser); ?></div>
                <div style="font-size:0.7rem;color:rgba(255,255,255,0.5);">Super Administrator</div>
            </div>
        </div>
    </div>
</aside>

<!-- ===== TOP BAR ===== -->
<div class="admin-content-wrap">
<div class="admin-topbar">
    <div class="admin-topbar-title">
        <h2><?php echo $pageTitle ?? 'Dashboard'; ?></h2>
        <span><?php echo date('l, d F Y'); ?></span>
    </div>
    <div class="admin-topbar-actions">
        <a href="<?php echo SITE_URL; ?>/parent/register.php" class="btn btn-accent btn-sm" target="_blank">
            <i class="fas fa-user-plus"></i> New Application
        </a>
    </div>
</div>
<div class="admin-content">
