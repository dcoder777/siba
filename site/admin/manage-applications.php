<?php
$pageTitle = "Manage Applications";
require_once('../includes/db_connect.php');
include('../includes/admin_header.php');
include('../includes/admin_sidebar.php');

// ─── Update Status ───
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $id     = (int)$_POST['app_id'];
    $status = $conn->real_escape_string($_POST['status']);
    $conn->query("UPDATE applications SET status='$status' WHERE id='$id'");

    // Auto-create ERP student record when admitted
    if ($status === 'Admitted') {
        $app = $conn->query("SELECT * FROM applications WHERE id = $id")->fetch_assoc();
        if ($app && empty($app['student_id'])) {
            $name_parts = explode(' ', trim($app['student_name']), 2);
            $first_name = $conn->real_escape_string($name_parts[0]);
            $last_name  = $conn->real_escape_string($name_parts[1] ?? '');

            $cnt_q = $conn->query("SELECT COUNT(*) AS cnt FROM students");
            $admission_no = sprintf("ADM%04d", ($cnt_q ? (int)$cnt_q->fetch_assoc()['cnt'] : 0) + 1);

            $addr = $conn->real_escape_string(implode(', ', array_filter([
                $app['address_line1'], $app['address_line2'],
                $app['village_city'] ?? $app['district'],
                $app['state'], $app['pin']
            ])));

            $gender  = $app['gender'] ? "'" . $conn->real_escape_string($app['gender']) . "'" : 'NULL';
            $dob     = $app['dob'] ? "'" . $conn->real_escape_string($app['dob']) . "'" : 'NULL';
            $bg      = $app['blood_group'] ? "'" . $conn->real_escape_string($app['blood_group']) . "'" : 'NULL';
            $phone   = $app['contact_no'] ? "'" . $conn->real_escape_string($app['contact_no']) . "'" : 'NULL';
            $email   = $app['email'] ? "'" . $conn->real_escape_string($app['email']) . "'" : 'NULL';

            $conn->query("INSERT INTO students (admission_no, first_name, last_name, gender, dob, blood_group, phone, email, address)
                VALUES ('$admission_no', '$first_name', '$last_name', $gender, $dob, $bg, $phone, $email, '$addr')");
            $student_id = $conn->insert_id;

            $ss = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'academic_year' LIMIT 1");
            $session = $ss ? $ss->fetch_assoc()['setting_value'] : (date('Y') . '-' . (date('Y') + 1));
            $class = $conn->real_escape_string($app['class_sought']);

            $conn->query("INSERT INTO student_enrollments (student_id, class_name, session_label, status, is_current)
                VALUES ($student_id, '$class', '$session', 'active', 1)");
            $conn->query("UPDATE applications SET student_id = $student_id, admission_no = '$admission_no' WHERE id = $id");
        }
    }

    header("Location: manage-applications.php?updated=1"); exit();
}

// ─── Filters ───
$filterStatus = $_GET['status'] ?? '';
$search       = $conn->real_escape_string($_GET['search'] ?? '');
$where        = [];
if ($filterStatus) $where[] = "a.status = '$filterStatus'";
if ($search)       $where[] = "(a.student_name LIKE '%$search%' OR p.phone LIKE '%$search%' OR a.father_name LIKE '%$search%')";
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$apps = $conn->query("SELECT a.*, p.phone FROM applications a JOIN parents p ON a.parent_id=p.id $whereSQL ORDER BY a.applied_at DESC");

// ─── Single Application Detail ───
$viewApp = null;
if (isset($_GET['id'])) {
    $vid    = (int)$_GET['id'];
    $result = $conn->query("SELECT a.*, p.phone FROM applications a JOIN parents p ON a.parent_id=p.id WHERE a.id='$vid'");
    if ($result->num_rows > 0) $viewApp = $result->fetch_assoc();
}
?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem;"><i class="fas fa-check-circle"></i> Application status updated successfully.</div>
<?php endif; ?>

<?php if ($viewApp): ?>
<!-- ═══ DETAIL VIEW ═══ -->
<div class="admin-panel" style="margin-bottom:1.5rem;">
    <div class="admin-panel-header">
        <h3><i class="fas fa-user-circle" style="color:var(--secondary-color)"></i> &nbsp;Application Detail — SIBA-2026-<?php echo str_pad($viewApp['id'],4,'0',STR_PAD_LEFT); ?></h3>
        <a href="manage-applications.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>
    <div class="admin-panel-body">
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
            <div>
                <div class="form-section-title">Student Info</div>
                <div class="info-row"><span class="key">Name</span><span class="val"><?php echo htmlspecialchars($viewApp['student_name']); ?></span></div>
                <div class="info-row"><span class="key">DOB</span><span class="val"><?php echo date('d M Y', strtotime($viewApp['dob'])); ?></span></div>
                <div class="info-row"><span class="key">Class</span><span class="val"><?php echo htmlspecialchars($viewApp['class_sought']); ?></span></div>
                <div class="info-row"><span class="key">Applied On</span><span class="val"><?php echo date('d M Y', strtotime($viewApp['applied_at'])); ?></span></div>
            </div>
            <div>
                <div class="form-section-title">Parent Info</div>
                <div class="info-row"><span class="key">Father</span><span class="val"><?php echo htmlspecialchars($viewApp['father_name']); ?></span></div>
                <div class="info-row"><span class="key">Mother</span><span class="val"><?php echo htmlspecialchars($viewApp['mother_name']); ?></span></div>
                <div class="info-row"><span class="key">Guardian</span><span class="val"><?php echo htmlspecialchars($viewApp['guardian_name'] ?: '—'); ?></span></div>
                <div class="info-row"><span class="key">Phone</span><span class="val"><?php echo htmlspecialchars($viewApp['phone']); ?></span></div>
                <div class="info-row"><span class="key">Email</span><span class="val"><?php echo htmlspecialchars($viewApp['email']); ?></span></div>
            </div>
            <div>
                <div class="form-section-title">Address</div>
                <div class="info-row"><span class="key">Address</span><span class="val"><?php echo htmlspecialchars($viewApp['address']); ?></span></div>
                <div class="info-row"><span class="key">District</span><span class="val"><?php echo htmlspecialchars($viewApp['district']); ?></span></div>
                <div class="info-row"><span class="key">State</span><span class="val"><?php echo htmlspecialchars($viewApp['state']); ?></span></div>
                <div class="info-row"><span class="key">PIN</span><span class="val"><?php echo htmlspecialchars($viewApp['pin']); ?></span></div>
            </div>
        </div>

        <!-- Documents -->
        <div class="form-section-title">Documents</div>
        <div style="display:flex; gap:1.5rem; flex-wrap:wrap; margin-bottom:2rem; align-items:flex-start;">
            <?php if ($viewApp['photo']): ?>
            <div style="text-align:center;">
                <img src="<?php echo SITE_URL; ?>/uploads/photos/<?php echo htmlspecialchars($viewApp['photo']); ?>"
                     alt="Photo" style="width:100px; height:120px; object-fit:cover; border-radius:10px; border:2px solid #e5e7eb; display:block;">
                <small style="color:var(--text-light);">Child Photo</small>
            </div>
            <?php endif; ?>
            <?php if ($viewApp['birth_cert']): ?>
            <div>
                <a href="<?php echo SITE_URL; ?>/uploads/docs/<?php echo htmlspecialchars($viewApp['birth_cert']); ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-file-pdf"></i> View Birth Certificate
                </a>
            </div>
            <?php endif; ?>
            <?php if ($viewApp['aadhaar']): ?>
            <div>
                <a href="<?php echo SITE_URL; ?>/uploads/docs/<?php echo htmlspecialchars($viewApp['aadhaar']); ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-id-card"></i> View Aadhaar Card
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Update Status -->
        <div class="form-section-title">Update Application Status</div>
        <form method="POST" style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="app_id" value="<?php echo $viewApp['id']; ?>">
            <div class="form-group" style="margin:0; min-width:220px;">
                <label style="margin-bottom:0.4rem;">Current Status:
                    <strong style="color:var(--primary-color);"><?php echo $viewApp['status']; ?></strong>
                </label>
                <select name="status" required>
                    <?php
                    $statuses = ['Application started','Under review','Admitted','Rejected'];
                    foreach ($statuses as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $viewApp['status']==$s?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="update_status" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Status
            </button>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- ═══ APPLICATION LIST ═══ -->
<div class="admin-panel">
    <div class="admin-panel-header">
        <h3><i class="fas fa-list" style="color:var(--secondary-color)"></i> &nbsp;All Applications
            <span class="badge badge-review" style="margin-left:0.5rem;"><?php echo $apps->num_rows; ?></span>
        </h3>
        <form method="GET" style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                   placeholder="Search name / phone..." style="padding:0.5rem 0.75rem; border:1.5px solid #d1d5db; border-radius:8px; font-size:0.85rem; width:180px;">
            <select name="status" style="padding:0.5rem 0.75rem; border:1.5px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                <option value="">All Statuses</option>
                <option value="Application started" <?php echo ($filterStatus=='Application started')?'selected':''; ?>>Application Started</option>
                <option value="Under review" <?php echo ($filterStatus=='Under review')?'selected':''; ?>>Under Review</option>
                <option value="Admitted"     <?php echo ($filterStatus=='Admitted')?'selected':''; ?>>Admitted</option>
                <option value="Rejected"     <?php echo ($filterStatus=='Rejected')?'selected':''; ?>>Rejected</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
            <a href="manage-applications.php" class="btn btn-sm" style="background:#f3f4f6;">Clear</a>
        </form>
    </div>
    <div class="admin-panel-table">
        <table class="admin-table">
            <thead>
                <tr><th>#</th><th>Student Name</th><th>Class</th><th>Father</th><th>Phone</th><th>Applied</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if ($apps->num_rows == 0): ?>
                <tr><td colspan="8" style="text-align:center; color:var(--text-light); padding:2rem;">No applications found.</td></tr>
            <?php else: while ($row = $apps->fetch_assoc()):
                $bc = ['Application started'=>'badge-pending','Under review'=>'badge-review','Admitted'=>'badge-admitted','Rejected'=>'badge-rejected'];
                $cls = $bc[$row['status']] ?? 'badge-pending';
            ?>
                <tr>
                    <td><strong style="color:var(--primary-color);">S-<?php echo str_pad($row['id'],3,'0',STR_PAD_LEFT); ?></strong></td>
                    <td><strong><?php echo htmlspecialchars($row['student_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['class_sought']); ?></td>
                    <td><?php echo htmlspecialchars($row['father_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                    <td style="color:var(--text-light);"><?php echo date('d M Y', strtotime($row['applied_at'])); ?></td>
                    <td><span class="badge <?php echo $cls; ?>"><?php echo $row['status']; ?></span></td>
                    <td>
                        <a href="manage-applications.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> Review</a>
                    </td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div></div>
<?php include('../includes/admin_footer.php'); ?>
