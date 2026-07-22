<?php
$pageTitle = "Application Summary";
require_once('../includes/db_connect.php');
include('../includes/portal_header.php');
include('../includes/portal_sidebar.php');

$app_id = (int)($_GET['app_id'] ?? 0);
if (!$app_id) {
    header("Location: dashboard.php"); exit();
}

$fullApp = $conn->query("SELECT * FROM applications WHERE id='$app_id' AND parent_id='$parent_id'")->fetch_assoc();
if (!$fullApp) {
    header("Location: dashboard.php"); exit();
}

$paidCheck = $conn->query("SELECT * FROM fees WHERE application_id='$app_id' AND fee_type='application' AND status='Paid' LIMIT 1");
$feePaid = $paidCheck->fetch_assoc();
$appNo = $fullApp['application_no'] ?? 'SBA-' . date('Y') . '-' . str_pad($app_id, 4, '0', STR_PAD_LEFT);
$fullName = trim(($fullApp['first_name'] ?? '') . ' ' . ($fullApp['middle_name'] ?? '') . ' ' . ($fullApp['last_name'] ?? ''));
if (!$fullName) $fullName = $fullApp['student_name'];
?>

<style>
.summary-wrap { max-width: 820px; margin: 0 auto; }
.summary-card { background: #fff; border-radius: var(--border-radius); box-shadow: var(--shadow); margin-bottom: 1.5rem; overflow: hidden; }
.summary-card .head { background: var(--primary-color); color: #fff; padding: 0.85rem 1.5rem; font-weight: 700; font-size: 1.05rem; }
.summary-card .head i { margin-right: 0.5rem; }
.summary-card .body { padding: 1.5rem; }
.summary-row { display: flex; border-bottom: 1px solid #f0f0f0; padding: 0.65rem 0; font-size: 0.93rem; }
.summary-row:last-child { border-bottom: none; }
.summary-row .lbl { width: 35%; color: var(--text-light); flex-shrink: 0; }
.summary-row .val { width: 65%; font-weight: 500; color: var(--text-color); }
.doc-link { display: inline-block; padding: 0.25rem 0.75rem; background: #eaf4fb; color: var(--secondary-color); border-radius: 6px; font-size: 0.85rem; text-decoration: none; font-weight: 600; }
.doc-link:hover { background: var(--secondary-color); color: #fff; }
.status-badge { display: inline-block; padding: 0.3rem 1rem; border-radius: 20px; font-weight: 700; font-size: 0.85rem; }
.status-Application_started { background: #fef3cd; color: #856404; }
.status-Under_review { background: #cce5ff; color: #004085; }
.status-Admitted { background: #d4edda; color: #155724; }
.status-Rejected { background: #f8d7da; color: #721c24; }
.summary-contact { background: linear-gradient(135deg, #4b5563, #6b7280); color: #fff; padding: 1.5rem 2rem; border-radius: var(--border-radius); text-align: center; margin-top: 1.5rem; }
.summary-contact h3 { margin-bottom: 0.75rem; font-size: 1.2rem; }
.summary-contact p { opacity: 0.9; font-size: 0.93rem; margin-bottom: 0.3rem; }
.summary-contact i { margin-right: 0.4rem; }
.photo-thumb { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; }
@media print { .no-print { display:none; } body { background:#fff; } .summary-wrap { max-width:100%; } }
</style>

<div class="portal-header">
    <div class="portal-header-title">
        <h2><i class="fas fa-file-alt" style="color:var(--secondary-color);"></i> &nbsp;Application Summary</h2>
        <p>Application No: <strong><?= htmlspecialchars($appNo) ?></strong></p>
    </div>
    <div class="no-print" style="display:flex;gap:.75rem;">
        <a href="receipt.php?app_id=<?= $app_id ?>" class="btn btn-outline-primary" target="_blank"><i class="fas fa-receipt"></i> Print Receipt</a>
        <a href="dashboard.php" class="btn btn-outline-primary"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
</div>

<?php if ($feePaid): ?>
<div class="alert alert-success" style="margin-bottom:1.5rem;">
    <i class="fas fa-check-circle"></i>
    <strong>Payment Successful!</strong> Application fee of ₹<?php echo number_format(APPLICATION_FEE); ?> has been paid.
    (Payment ID: <?php echo htmlspecialchars($feePaid['razorpay_payment_id'] ?? 'N/A'); ?>)
</div>
<?php endif; ?>

<div class="summary-wrap">

    <!-- Application & Status -->
    <div class="summary-card">
        <div class="head"><i class="fas fa-id-card"></i> Application Status</div>
        <div class="body">
            <div class="summary-row">
                <span class="lbl">Application No</span>
                <span class="val"><strong><?= htmlspecialchars($appNo) ?></strong></span>
            </div>
            <div class="summary-row">
                <span class="lbl">Admission No</span>
                <span class="val"><?= htmlspecialchars($fullApp['admission_no'] ?? '—') ?></span>
            </div>
            <div class="summary-row">
                <span class="lbl">Status</span>
                <span class="val"><span class="status-badge status-<?php echo str_replace(' ', '_', $fullApp['status']); ?>"><?php echo htmlspecialchars($fullApp['status']); ?></span></span>
            </div>
            <div class="summary-row">
                <span class="lbl">Submitted On</span>
                <span class="val"><?php echo date('d M Y, h:i A', strtotime($fullApp['applied_at'])); ?></span>
            </div>
            <div class="summary-row">
                <span class="lbl">Fee Payment</span>
                <span class="val">
                    <?php if ($feePaid): ?>
                        <span style="color:#155724;font-weight:600;"><i class="fas fa-check-circle"></i> Paid</span>
                    <?php elseif (($fullApp['payment_status'] ?? 'Pending') === 'Paid'): ?>
                        <span style="color:#155724;font-weight:600;"><i class="fas fa-check-circle"></i> Paid</span>
                    <?php else: ?>
                        <span style="color:#856404;font-weight:600;"><i class="fas fa-clock"></i> Pending</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Student Information -->
    <div class="summary-card">
        <div class="head"><i class="fas fa-child"></i> Student Information</div>
        <div class="body">
            <?php if ($fullApp['photo']): ?>
                <div style="text-align:center;margin-bottom:1rem;">
                    <img src="<?= SITE_URL ?>/uploads/docs/<?= rawurlencode($fullApp['photo']) ?>" alt="Student Photo" class="photo-thumb">
                </div>
            <?php endif; ?>
            <div class="summary-row"><span class="lbl">Full Name</span><span class="val"><?php echo htmlspecialchars($fullName); ?></span></div>
            <div class="summary-row"><span class="lbl">Date of Birth</span><span class="val"><?php echo htmlspecialchars($fullApp['dob']); ?></span></div>
            <div class="summary-row"><span class="lbl">Gender</span><span class="val"><?php echo htmlspecialchars($fullApp['gender'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Religion</span><span class="val"><?php echo htmlspecialchars($fullApp['religion'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Blood Group</span><span class="val"><?php echo htmlspecialchars($fullApp['blood_group'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Aadhaar No.</span><span class="val"><?php echo htmlspecialchars($fullApp['aadhaar_no'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Previous School</span><span class="val"><?php echo htmlspecialchars($fullApp['previous_school'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Previous Class</span><span class="val"><?php echo htmlspecialchars($fullApp['previous_class'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Admission Class</span><span class="val"><?php echo htmlspecialchars($fullApp['class_sought']); ?></span></div>
        </div>
    </div>

    <!-- Address Details -->
    <div class="summary-card">
        <div class="head"><i class="fas fa-map-marker-alt"></i> Address Details</div>
        <div class="body">
            <div class="summary-row"><span class="lbl">Address Line 1</span><span class="val"><?php echo htmlspecialchars($fullApp['address_line1'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Address Line 2</span><span class="val"><?php echo htmlspecialchars($fullApp['address_line2'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Post Office</span><span class="val"><?php echo htmlspecialchars($fullApp['post_office'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Police Station</span><span class="val"><?php echo htmlspecialchars($fullApp['police_station'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">District</span><span class="val"><?php echo htmlspecialchars($fullApp['district'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Village / City</span><span class="val"><?php echo htmlspecialchars($fullApp['village_city'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">PIN Code</span><span class="val"><?php echo htmlspecialchars($fullApp['pin'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">State</span><span class="val"><?php echo htmlspecialchars($fullApp['state'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Country</span><span class="val"><?php echo htmlspecialchars($fullApp['country'] ?? 'India'); ?></span></div>
        </div>
    </div>

    <!-- Parent / Guardian Details -->
    <div class="summary-card">
        <div class="head"><i class="fas fa-users"></i> Parent / Guardian Details</div>
        <div class="body">
            <div class="summary-row"><span class="lbl">Father's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['father_name']); ?></span></div>
            <div class="summary-row"><span class="lbl">Father's Occupation</span><span class="val"><?php echo htmlspecialchars($fullApp['father_occupation'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Mother's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['mother_name']); ?></span></div>
            <div class="summary-row"><span class="lbl">Mother's Occupation</span><span class="val"><?php echo htmlspecialchars($fullApp['mother_occupation'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Guardian's Name</span><span class="val"><?php echo htmlspecialchars($fullApp['guardian_name'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Guardian's Occupation</span><span class="val"><?php echo htmlspecialchars($fullApp['guardian_occupation'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Family Annual Income</span><span class="val">₹<?php echo htmlspecialchars($fullApp['family_annual_income'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Contact No</span><span class="val"><?php echo htmlspecialchars($fullApp['contact_no'] ?? ''); ?></span></div>
            <div class="summary-row"><span class="lbl">Email</span><span class="val"><?php echo htmlspecialchars($fullApp['email'] ?? ''); ?></span></div>
        </div>
    </div>

    <!-- Uploaded Documents -->
    <div class="summary-card">
        <div class="head"><i class="fas fa-paperclip"></i> Uploaded Documents</div>
        <div class="body">
            <?php
            $docs = [
                'Aadhaar Card'            => $fullApp['aadhaar'] ?? $fullApp['aadhaar_file'] ?? '',
                'Birth Certificate'       => $fullApp['birth_cert'] ?? '',
                'Leaving Certificate'     => $fullApp['leaving_cert'] ?? '',
                'Previous Marksheet'      => $fullApp['prev_marksheet'] ?? '',
            ];
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <?php foreach ($docs as $label => $file): ?>
                    <div style="background:#f9f9f9;border-radius:8px;padding:0.75rem 1rem;text-align:center;">
                        <div style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.3rem;"><?php echo $label; ?></div>
                        <?php if ($file): ?>
                            <a href="<?php echo SITE_URL; ?>/uploads/docs/<?php echo rawurlencode($file); ?>" target="_blank" class="doc-link"><i class="fas fa-eye"></i> View</a>
                        <?php else: ?>
                            <span style="color:#999;font-size:0.85rem;">Not uploaded</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Contact -->
    <div class="summary-contact">
        <h3><i class="fas fa-phone-alt"></i> Need Help?</h3>
        <p><i class="fas fa-phone"></i> +91 12345 67890</p>
        <p><i class="fas fa-envelope"></i> info@sibaschool.com</p>
        <p style="margin-top:0.5rem;font-size:0.85rem;opacity:0.75;">Mon–Fri: 8:00 AM – 3:00 PM</p>
    </div>

</div>

</div></div>
<?php include('../includes/portal_footer.php'); ?>
