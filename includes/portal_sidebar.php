<?php
if (!isset($_SESSION['parent_id'])) {
    header("Location: " . SITE_URL . "/parent/login.php");
    exit();
}

// Fetch parent info
$parent_id = $_SESSION['parent_id'];
$phone = $_SESSION['phone'] ?? 'Unknown';

// Check if they have an application
$appResult = $conn->query("SELECT id, student_name, status FROM applications WHERE parent_id='$parent_id' LIMIT 1");
$hasApp = ($appResult->num_rows > 0);
$app = $hasApp ? $appResult->fetch_assoc() : null;
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<div class="portal-wrapper">
<aside class="portal-sidebar">
    <div class="portal-sidebar-header">
        <div class="portal-user-avatar"><?php echo strtoupper(substr($phone, -2)); ?></div>
        <h3>Parent Portal</h3>
        <p><?php echo htmlspecialchars($phone); ?></p>
    </div>
    <nav class="portal-nav">
        <ul>
            <li>
                <a href="<?php echo SITE_URL; ?>/parent/dashboard.php" class="<?php echo $currentPage=='dashboard.php'?'active':''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <?php if (!$hasApp): ?>
            <li>
                <a href="<?php echo SITE_URL; ?>/parent/apply.php" class="<?php echo $currentPage=='apply.php'?'active':''; ?>">
                    <i class="fas fa-file-alt"></i> Apply for Admission
                </a>
            </li>
            <?php else: ?>
            <li>
                <a href="<?php echo SITE_URL; ?>/parent/application-status.php" class="<?php echo $currentPage=='application-status.php'?'active':''; ?>">
                    <i class="fas fa-search"></i> Application Status
                </a>
            </li>
            <?php if ($app && $app['status'] == 'Admitted'): ?>
            <li>
                <a href="<?php echo SITE_URL; ?>/parent/pay-fees.php" class="<?php echo $currentPage=='pay-fees.php'?'active':''; ?>">
                    <i class="fas fa-credit-card"></i> Pay Fees
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>/parent/fee-history.php" class="<?php echo $currentPage=='fee-history.php'?'active':''; ?>">
                    <i class="fas fa-history"></i> Fee History
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
        </ul>
        <div class="portal-nav-separator">Help</div>
        <ul>
            <li>
                <a href="<?php echo SITE_URL; ?>/contact.php">
                    <i class="fas fa-headset"></i> Contact School
                </a>
            </li>
            <li>
                <a href="<?php echo SITE_URL; ?>/admissions.php#faq">
                    <i class="fas fa-question-circle"></i> FAQ
                </a>
            </li>
        </ul>
    </nav>
    <div class="portal-sidebar-footer">
        <a href="<?php echo SITE_URL; ?>/logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</aside>
<div class="portal-content">
