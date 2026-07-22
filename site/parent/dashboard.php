<?php
$pageTitle = "Dashboard";
require_once('../includes/db_connect.php');
include('../includes/portal_header.php');
include('../includes/portal_sidebar.php');
?>

<!-- Portal Header -->
<div class="portal-header">
    <div class="portal-header-title">
        <h2>Welcome Back!</h2>
        <p>Here's an overview of your child's admission journey.</p>
    </div>
    <a href="<?php echo SITE_URL; ?>/logout.php" class="btn btn-outline-primary btn-sm">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

<?php if (!$hasApp): ?>
<!-- NO APPLICATION YET -->
<div class="info-card" style="text-align: center; padding: 3rem 2rem; background: #f4f7f5;">
    <div style="width: 80px; height: 80px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: white; font-size: 2rem;">
        <i class="fas fa-file-medical-alt"></i>
    </div>
    <h3 style="font-size: 1.4rem; color: var(--primary-color); margin-bottom: 0.75rem;">No Application Found</h3>
    <p style="color: var(--text-light); max-width: 400px; margin: 0 auto 1.5rem;">You haven't submitted an admission application yet. Start your child's journey today!</p>
    <a href="apply.php" class="btn btn-primary btn-lg"><i class="fas fa-file-alt"></i> Start Application Now</a>
</div>

<?php else: ?>
<!-- HAS APPLICATION -->

<!-- Status Tracker -->
<?php
$statusMap = [
    'Application started' => 0,
    'Under review'        => 1,
    'Admitted'            => 2,
    'Rejected'            => -1,
];
$currentStatus = $app['status'];
$statusIndex   = $statusMap[$currentStatus] ?? 0;
$steps         = ['Submitted', 'Under Review', 'Decision'];
?>

<div class="info-card" style="margin-bottom: 1.5rem;">
    <div class="info-card-header">
        <h3><i class="fas fa-route"></i> &nbsp;Application Progress</h3>
        <?php if ($currentStatus == 'Rejected'): ?>
            <span class="badge badge-rejected"><i class="fas fa-times-circle"></i> Rejected</span>
        <?php elseif ($currentStatus == 'Admitted'): ?>
            <span class="badge badge-admitted"><i class="fas fa-check-circle"></i> Admitted ✓</span>
        <?php elseif ($currentStatus == 'Under review'): ?>
            <span class="badge badge-review"><i class="fas fa-clock"></i> Under Review</span>
        <?php else: ?>
            <span class="badge badge-pending"><i class="fas fa-hourglass-start"></i> Application Started</span>
        <?php endif; ?>
    </div>

    <div class="status-tracker">
        <?php foreach ($steps as $i => $label): ?>
        <?php
        if ($currentStatus == 'Rejected' && $i == 2) {
            $cls = 'rejected';
        } elseif ($i < $statusIndex) {
            $cls = 'done';
        } elseif ($i == $statusIndex) {
            $cls = 'active';
        } else {
            $cls = '';
        }
        ?>
        <div class="status-step <?php echo $cls; ?>">
            <div class="status-icon">
                <?php if ($cls == 'done'): ?><i class="fas fa-check"></i>
                <?php elseif ($cls == 'rejected'): ?><i class="fas fa-times"></i>
                <?php else: ?><?php echo $i + 1; ?>
                <?php endif; ?>
            </div>
            <span class="status-label"><?php echo $label; ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($currentStatus == 'Admitted'): ?>
    <div class="alert alert-success">
        <i class="fas fa-graduation-cap"></i>
        <div><strong>Congratulations!</strong> <?php echo htmlspecialchars($app['student_name']); ?> has been admitted to SIBA Public School. Please proceed to pay the fees.</div>
    </div>
    <a href="pay-fees.php?app_id=<?php echo $app['id']; ?>" class="btn btn-success"><i class="fas fa-credit-card"></i> Pay Fees Now</a>
    <?php elseif ($currentStatus == 'Rejected'): ?>
    <div class="alert alert-error">
        <i class="fas fa-info-circle"></i>
        <div>We regret to inform you that this application was not successful. Please contact the school for more information.</div>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <div>Your application is being processed. You will be notified once the status changes.</div>
    </div>
    <?php endif; ?>
</div>

<!-- Application Details Grid -->
<div class="grid-2col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <div class="info-card">
        <div class="info-card-header"><h3><i class="fas fa-child"></i> &nbsp;Student Details</h3></div>
        <?php
        $fullApp = $conn->query("SELECT * FROM applications WHERE id='{$app['id']}'")->fetch_assoc();
        ?>
        <div class="info-row"><span class="key">Full Name</span><span class="val"><?php echo htmlspecialchars($fullApp['student_name']); ?></span></div>
        <div class="info-row"><span class="key">Application No</span><span class="val"><strong><?php echo htmlspecialchars($fullApp['application_no'] ?? '—'); ?></strong></span></div>
        <div class="info-row"><span class="key">Date of Birth</span><span class="val"><?php echo date('d M Y', strtotime($fullApp['dob'])); ?></span></div>
        <div class="info-row"><span class="key">Class Applied</span><span class="val"><?php echo htmlspecialchars($fullApp['class_sought']); ?></span></div>
        <div class="info-row"><span class="key">Applied On</span><span class="val"><?php echo date('d M Y', strtotime($fullApp['applied_at'])); ?></span></div>
    </div>
    <div class="info-card">
        <div class="info-card-header"><h3><i class="fas fa-user-friends"></i> &nbsp;Parent Details</h3></div>
        <div class="info-row"><span class="key">Father's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['father_name']); ?></span></div>
        <div class="info-row"><span class="key">Mother's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['mother_name']); ?></span></div>
        <div class="info-row"><span class="key">Contact</span><span class="val"><?php echo htmlspecialchars($fullApp['contact_no']); ?></span></div>
        <div class="info-row"><span class="key">Email</span><span class="val"><?php echo htmlspecialchars($fullApp['email']); ?></span></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="info-card">
    <div class="info-card-header"><h3><i class="fas fa-bolt"></i> &nbsp;Quick Actions</h3></div>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem;">
        <a href="application-summary.php?app_id=<?php echo $app['id']; ?>" class="btn btn-outline-primary"><i class="fas fa-file-alt"></i> View Summary</a>
        <a href="application-status.php" class="btn btn-outline-primary"><i class="fas fa-search"></i> Status</a>
        <?php if ($currentStatus == 'Admitted'): ?>
            <a href="pay-fees.php?app_id=<?php echo $app['id']; ?>" class="btn btn-success"><i class="fas fa-credit-card"></i> Pay Fees</a>
            <a href="fee-history.php" class="btn btn-primary"><i class="fas fa-history"></i> Fee History</a>
        <?php endif; ?>
        <a href="<?php echo SITE_URL; ?>/contact.php" class="btn btn-outline-primary"><i class="fas fa-headset"></i> Contact School</a>
    </div>
</div>
<?php endif; ?>

</div><!-- /.portal-content -->
</div><!-- /.portal-wrapper -->

<?php include('../includes/portal_footer.php'); ?>
