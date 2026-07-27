<?php
$pageTitle = "Application Status";
require_once('../includes/db_connect.php');
include('../includes/portal_header.php');
include('../includes/portal_sidebar.php');

if (!$hasApp) {
    header("Location: apply.php"); exit();
}

$fullApp = $conn->query("SELECT * FROM applications WHERE id='{$app['id']}'")->fetch_assoc();
?>

<div class="portal-header">
    <div class="portal-header-title">
        <h2><i class="fas fa-search"></i> &nbsp;Application Status</h2>
        <p>Full details of your submitted admission application.</p>
    </div>
</div>

<!-- Status Banner -->
<?php
$badgeClass = [
    'Application started' => 'badge-pending',
    'Under review'        => 'badge-review',
    'Admitted'            => 'badge-admitted',
    'Rejected'            => 'badge-rejected',
];
$bc = $badgeClass[$fullApp['status']] ?? 'badge-pending';
?>
<div class="info-card" style="margin-bottom: 1.5rem; border-left: 4px solid var(--secondary-color);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <p style="font-size: 0.8rem; color: var(--text-light); margin-bottom: 0.3rem;">Application ID</p>
            <h3 style="color: var(--primary-color);">SIBA-2026-<?php echo str_pad($fullApp['id'], 4, '0', STR_PAD_LEFT); ?></h3>
        </div>
        <div>
            <p style="font-size: 0.8rem; color: var(--text-light); margin-bottom: 0.3rem;">Current Status</p>
            <span class="badge <?php echo $bc; ?>" style="font-size: 0.9rem; padding: 0.4rem 1rem;">
                <?php echo htmlspecialchars($fullApp['status']); ?>
            </span>
        </div>
        <div>
            <p style="font-size: 0.8rem; color: var(--text-light); margin-bottom: 0.3rem;">Applied On</p>
            <strong><?php echo date('d M Y, h:i A', strtotime($fullApp['applied_at'])); ?></strong>
        </div>
    </div>
</div>

<div class="grid-2col" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- Student Info -->
    <div class="info-card">
        <div class="info-card-header"><h3><i class="fas fa-child"></i> &nbsp;Student Details</h3></div>
        <div class="info-row"><span class="key">Full Name</span><span class="val"><?php echo htmlspecialchars($fullApp['student_name']); ?></span></div>
        <div class="info-row"><span class="key">Date of Birth</span><span class="val"><?php echo date('d M Y', strtotime($fullApp['dob'])); ?></span></div>
        <div class="info-row"><span class="key">Class Applied</span><span class="val"><?php echo htmlspecialchars($fullApp['class_sought']); ?></span></div>
    </div>

    <!-- Parent Info -->
    <div class="info-card">
        <div class="info-card-header"><h3><i class="fas fa-users"></i> &nbsp;Parent Details</h3></div>
        <div class="info-row"><span class="key">Father's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['father_name']); ?></span></div>
        <div class="info-row"><span class="key">Mother's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['mother_name']); ?></span></div>
        <div class="info-row"><span class="key">Contact</span><span class="val"><?php echo htmlspecialchars($fullApp['contact_no']); ?></span></div>
        <div class="info-row"><span class="key">Email</span><span class="val"><?php echo htmlspecialchars($fullApp['email']); ?></span></div>
    </div>

    <!-- Address -->
    <div class="info-card">
        <div class="info-card-header"><h3><i class="fas fa-map-marker-alt"></i> &nbsp;Address</h3></div>
        <div class="info-row"><span class="key">Address</span><span class="val"><?php echo htmlspecialchars($fullApp['address']); ?></span></div>
        <div class="info-row"><span class="key">District / PIN</span><span class="val"><?php echo htmlspecialchars($fullApp['district']); ?> – <?php echo htmlspecialchars($fullApp['pin']); ?></span></div>
        <div class="info-row"><span class="key">State</span><span class="val"><?php echo htmlspecialchars($fullApp['state']); ?></span></div>
        <div class="info-row"><span class="key">Country</span><span class="val"><?php echo htmlspecialchars($fullApp['country']); ?></span></div>
    </div>

    <!-- Documents -->
    <div class="info-card">
        <div class="info-card-header"><h3><i class="fas fa-paperclip"></i> &nbsp;Uploaded Documents</h3></div>
        <div class="info-row">
            <span class="key">Child Photo</span>
            <span class="val">
                <?php if ($fullApp['photo']): ?>
                    <img src="<?php echo SITE_URL; ?>/uploads/photos/<?php echo htmlspecialchars($fullApp['photo']); ?>"
                         alt="Photo" style="width: 48px; height: 55px; object-fit: cover; border-radius: 6px; border: 1px solid #e5e7eb;">
                <?php else: echo '—'; endif; ?>
            </span>
        </div>
        <div class="info-row">
            <span class="key">Birth Certificate</span>
            <span class="val">
                <?php if ($fullApp['birth_cert']): ?>
                    <a href="<?php echo SITE_URL; ?>/uploads/docs/<?php echo htmlspecialchars($fullApp['birth_cert']); ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> View</a>
                <?php else: echo '—'; endif; ?>
            </span>
        </div>
        <div class="info-row">
            <span class="key">Aadhaar Card</span>
            <span class="val">
                <?php if ($fullApp['aadhaar']): ?>
                    <a href="<?php echo SITE_URL; ?>/uploads/docs/<?php echo htmlspecialchars($fullApp['aadhaar']); ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> View</a>
                <?php else: echo '<span style="color:var(--text-light)">Not uploaded</span>'; endif; ?>
            </span>
        </div>
    </div>
</div>

<?php if ($fullApp['status'] == 'Admitted'): ?>
<div class="alert alert-success">
    <i class="fas fa-graduation-cap" style="font-size: 1.3rem;"></i>
    <div>
        <strong>Admission Confirmed!</strong> Please proceed to the fee payment section to complete the enrolment.
        <br><a href="pay-fees.php?app_id=<?php echo $fullApp['id']; ?>" class="btn btn-success btn-sm" style="margin-top: 0.5rem;"><i class="fas fa-credit-card"></i> Pay Fees</a>
    </div>
</div>
<?php endif; ?>

</div></div>
<?php include('../includes/portal_footer.php'); ?>
