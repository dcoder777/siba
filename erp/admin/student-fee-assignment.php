<?php
require __DIR__ . '/bootstrap.php';
require_admin_login();

ensure_columns($pdo, 'student_fee_assignments', [
    'section_name' => "VARCHAR(50) DEFAULT NULL AFTER class_name",
    'transport_required' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER fee_structure_id",
    'hostel_required' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER transport_required",
    'discount_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER hostel_required",
    'emi_plan' => "VARCHAR(50) DEFAULT NULL AFTER discount_amount",
    'effective_date' => "DATE DEFAULT NULL AFTER emi_plan",
    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER academic_session",
    'updated_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
]);

/* ── helper functions ────────────────────────────────────────────── */
function student_options(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT id, student_name, admission_no, class_sought
        FROM applications
        WHERE status = 'Admitted'
        ORDER BY student_name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fee_structure_options(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT id, name, class_name, academic_session, total_amount
        FROM fee_structures
        WHERE is_active = 1
        ORDER BY class_name ASC, name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ── POST handling (before any HTML) ────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    /* Create */
    if ($action === 'create_assignment') {
        $student_id           = (int)($_POST['student_id'] ?? 0);
        $student_name         = trim($_POST['student_name'] ?? '');
        $admission_no         = trim($_POST['admission_no'] ?? '');
        $class_name           = trim($_POST['class_name'] ?? '');
        $section_name         = trim($_POST['section_name'] ?? '');
        $fee_structure_id     = (int)($_POST['fee_structure_id'] ?? 0);
        $transport_required   = $_POST['transport_required'] ?? 'No';
        $hostel_required      = $_POST['hostel_required'] ?? 'No';
        $discount_amount      = (float)($_POST['discount_amount'] ?? 0);
        $emi_plan             = trim($_POST['emi_plan'] ?? '');
        $effective_date       = $_POST['effective_date'] ?? date('Y-m-d');
        $academic_session     = trim($_POST['academic_session'] ?? '');
        $section_name         = trim($_POST['section_name'] ?? '');

        if ($student_id && $fee_structure_id && $academic_session) {
            $stmt = $pdo->prepare("
                INSERT INTO student_fee_assignments
                (student_id, student_name, admission_no, class_name, section_name,
                 fee_structure_id, transport_required, hostel_required,
                 discount_amount, emi_plan, effective_date, academic_session,
                 is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                $student_id, $student_name, $admission_no, $class_name,
                $section_name, $fee_structure_id, $transport_required,
                $hostel_required, $discount_amount, $emi_plan,
                $effective_date, $academic_session
            ]);
            $GLOBALS['flash'] = ['type' => 'success', 'msg' => 'Fee structure assigned successfully.'];
        } else {
            $GLOBALS['flash'] = ['type' => 'error', 'msg' => 'Please fill all required fields.'];
        }
        redirect('student-fee-assignment.php');
    }

    /* Update */
    if ($action === 'update_assignment') {
        $id                   = (int)($_POST['id'] ?? 0);
        $student_id           = (int)($_POST['student_id'] ?? 0);
        $student_name         = trim($_POST['student_name'] ?? '');
        $admission_no         = trim($_POST['admission_no'] ?? '');
        $class_name           = trim($_POST['class_name'] ?? '');
        $section_name         = trim($_POST['section_name'] ?? '');
        $fee_structure_id     = (int)($_POST['fee_structure_id'] ?? 0);
        $transport_required   = $_POST['transport_required'] ?? 'No';
        $hostel_required      = $_POST['hostel_required'] ?? 'No';
        $discount_amount      = (float)($_POST['discount_amount'] ?? 0);
        $emi_plan             = trim($_POST['emi_plan'] ?? '');
        $effective_date       = $_POST['effective_date'] ?? '';
        $academic_session     = trim($_POST['academic_session'] ?? '');

        if ($id && $student_id && $fee_structure_id && $academic_session) {
            $stmt = $pdo->prepare("
                UPDATE student_fee_assignments SET
                    student_id = ?, student_name = ?, admission_no = ?,
                    class_name = ?, section_name = ?, fee_structure_id = ?,
                    transport_required = ?, hostel_required = ?,
                    discount_amount = ?, emi_plan = ?, effective_date = ?,
                    academic_session = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $student_id, $student_name, $admission_no, $class_name,
                $section_name, $fee_structure_id, $transport_required,
                $hostel_required, $discount_amount, $emi_plan,
                $effective_date, $academic_session, $id
            ]);
            $GLOBALS['flash'] = ['type' => 'success', 'msg' => 'Assignment updated successfully.'];
        } else {
            $GLOBALS['flash'] = ['type' => 'error', 'msg' => 'Invalid data.'];
        }
        redirect('student-fee-assignment.php');
    }

    /* Delete */
    if ($action === 'delete_assignment') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM student_fee_assignments WHERE id = ?");
            $stmt->execute([$id]);
            $GLOBALS['flash'] = ['type' => 'success', 'msg' => 'Assignment deleted.'];
        }
        redirect('student-fee-assignment.php');
    }
}

/* ── filter parameters ──────────────────────────────────────────── */
$filter_session = trim($_GET['session'] ?? '');
$filter_class   = trim($_GET['class'] ?? '');
$filter_search  = trim($_GET['search'] ?? '');

/* ── fetch data ─────────────────────────────────────────────────── */
$students          = student_options($pdo);
$fee_structures    = fee_structure_options($pdo);

// build distinct class list for filters
$class_rows = $pdo->query("SELECT DISTINCT class_name FROM student_fee_assignments ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$session_rows = $pdo->query("SELECT DISTINCT academic_session FROM student_fee_assignments ORDER BY academic_session DESC")->fetchAll(PDO::FETCH_COLUMN);

// query assignments
$where  = [];
$params = [];
if ($filter_session !== '') {
    $where[]    = "sfa.academic_session = ?";
    $params[]   = $filter_session;
}
if ($filter_class !== '') {
    $where[]    = "sfa.class_name = ?";
    $params[]   = $filter_class;
}
if ($filter_search !== '') {
    $where[]    = "(sfa.student_name LIKE ? OR sfa.admission_no LIKE ?)";
    $params[]   = "%{$filter_search}%";
    $params[]   = "%{$filter_search}%";
}

$sql = "
    SELECT sfa.*, fs.name AS fee_structure_name, fs.total_amount
    FROM student_fee_assignments sfa
    LEFT JOIN fee_structures fs ON fs.id = sfa.fee_structure_id
";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY sfa.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $GLOBALS['flash'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Fee Assignment — ERP Admin</title>
    <link rel="stylesheet" href="/erp/assets/css/admin.css">
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: flex-start;
            padding-top: 60px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: #fff;
            border-radius: 8px;
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
        }
        .modal-header h3 { margin: 0; font-size: 18px; }
        .modal-close {
            background: none; border: none; font-size: 24px;
            cursor: pointer; color: #64748b; line-height: 1;
        }
        .modal-close:hover { color: #1e293b; }
        .modal-body { padding: 24px; }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 14px;
            color: #374151;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.2);
        }
        .radio-group {
            display: flex;
            gap: 20px;
            padding-top: 4px;
        }
        .radio-group label {
            font-weight: 400;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .badge-active   { background: #dcfce7; color: #166534; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-inactive { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .action-btns { display: flex; gap: 6px; }
        .action-btns .btn { padding: 4px 10px; font-size: 13px; }
    </style>
</head>
<body>
<div class="admin-wrapper">

    <!-- Hero Banner -->
    <div class="hero-banner">
        <div class="hero-content">
            <h1>Student Fee Assignment</h1>
            <p>Assign fee structures to admitted students, manage transport &amp; hostel, discounts and EMI plans.</p>
        </div>
        <button class="btn btn-primary" onclick="openCreateModal()">+ Assign Fee Structure</button>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" style="margin: 16px 24px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="toolbar" style="margin: 16px 24px;">
        <form method="get" action="" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap; width:100%;">
            <div class="form-group" style="margin:0; flex:1; min-width:160px;">
                <label for="session">Academic Session</label>
                <select name="session" id="session">
                    <option value="">All Sessions</option>
                    <?php foreach ($session_rows as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filter_session === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0; flex:1; min-width:160px;">
                <label for="class">Class</label>
                <select name="class" id="class">
                    <option value="">All Classes</option>
                    <?php foreach ($class_rows as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filter_class === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0; flex:2; min-width:200px;">
                <label for="search">Search</label>
                <input type="text" name="search" id="search" placeholder="Student name or admission no..." value="<?= e($filter_search) ?>">
            </div>
            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="student-fee-assignment.php" class="btn btn-secondary">Clear</a>
            </div>
        </form>
    </div>

    <!-- Main Table -->
    <div class="panel" style="margin: 0 24px 24px;">
        <div class="panel-header">
            <h2 class="section-title">Fee Assignments</h2>
            <span style="color:#64748b; font-size:14px;"><?= count($assignments) ?> record(s)</span>
        </div>
        <div style="overflow-x:auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Admission No</th>
                        <th>Class</th>
                        <th>Fee Structure</th>
                        <th>Transport</th>
                        <th>Hostel</th>
                        <th>Discount (₹)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr><td colspan="10" style="text-align:center; padding:32px; color:#94a3b8;">No assignments found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $i => $a): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= e($a['student_name']) ?></strong></td>
                                <td><?= e($a['admission_no']) ?></td>
                                <td><?= e($a['class_name']) ?></td>
                                <td><?= e($a['fee_structure_name'] ?? '—') ?></td>
                                <td><?= e($a['transport_required']) ?></td>
                                <td><?= e($a['hostel_required']) ?></td>
                                <td>₹<?= number_format((float)$a['discount_amount'], 2) ?></td>
                                <td>
                                    <?php if ($a['is_active']): ?>
                                        <span class="badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn btn-sm btn-outline"
                                                onclick='openEditModal(<?= json_encode($a) ?>)'>Edit</button>
                                        <form method="post" action="" style="display:inline;"
                                              onsubmit="return confirm('Delete this assignment?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_assignment">
                                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /admin-wrapper -->

<!-- ═══════════════════════════════════════════════════════════════════
     CREATE / EDIT MODAL
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="assignmentModal">
    <div class="modal">
        <form method="post" action="" id="assignmentForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="create_assignment">
            <input type="hidden" name="id" id="formId" value="">

            <div class="modal-header">
                <h3 id="modalTitle">Assign Fee Structure</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>

            <div class="modal-body">
                <!-- Student Search -->
                <div class="form-group">
                    <label for="student_search">Student <span style="color:red">*</span></label>
                    <input type="text" id="student_search" list="student_list"
                           placeholder="Start typing student name or admission no..."
                           autocomplete="off" required>
                    <datalist id="student_list">
                        <?php foreach ($students as $s): ?>
                            <option value="<?= e($s['student_name']) ?>"
                                    data-id="<?= (int)$s['id'] ?>"
                                    data-admission="<?= e($s['admission_no']) ?>"
                                    data-class="<?= e($s['class_sought']) ?>">
                                <?= e($s['student_name']) ?> (<?= e($s['admission_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="student_id" id="formStudentId">
                    <input type="hidden" name="student_name" id="formStudentName">
                    <input type="hidden" name="admission_no" id="formAdmissionNo">
                    <input type="hidden" name="class_name" id="formClassName">
                </div>

                <div class="field-grid">
                    <!-- Fee Structure -->
                    <div class="form-group">
                        <label for="formFeeStructure">Fee Structure <span style="color:red">*</span></label>
                        <select name="fee_structure_id" id="formFeeStructure" required>
                            <option value="">— Select —</option>
                            <?php foreach ($fee_structures as $fs): ?>
                                <option value="<?= (int)$fs['id'] ?>"
                                        data-amount="<?= (float)$fs['total_amount'] ?>">
                                    <?= e($fs['name']) ?> (<?= e($fs['class_name']) ?>) — ₹<?= number_format((float)$fs['total_amount'], 0) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Effective Date -->
                    <div class="form-group">
                        <label for="formEffectiveDate">Effective Date <span style="color:red">*</span></label>
                        <input type="date" name="effective_date" id="formEffectiveDate" required>
                    </div>
                </div>

                <div class="field-grid">
                    <!-- Transport Required -->
                    <div class="form-group">
                        <label>Transport Required</label>
                        <div class="radio-group">
                            <label><input type="radio" name="transport_required" value="Yes" id="formTransportYes"> Yes</label>
                            <label><input type="radio" name="transport_required" value="No" id="formTransportNo" checked> No</label>
                        </div>
                    </div>

                    <!-- Hostel Required -->
                    <div class="form-group">
                        <label>Hostel Required</label>
                        <div class="radio-group">
                            <label><input type="radio" name="hostel_required" value="Yes" id="formHostelYes"> Yes</label>
                            <label><input type="radio" name="hostel_required" value="No" id="formHostelNo" checked> No</label>
                        </div>
                    </div>
                </div>

                <div class="field-grid">
                    <!-- Discount -->
                    <div class="form-group">
                        <label for="formDiscount">Discount Amount (₹)</label>
                        <input type="number" name="discount_amount" id="formDiscount" min="0" step="0.01" value="0">
                    </div>

                    <!-- EMI Plan -->
                    <div class="form-group">
                        <label for="formEmiPlan">EMI Plan</label>
                        <input type="text" name="emi_plan" id="formEmiPlan" placeholder="e.g. Monthly, Quarterly">
                    </div>
                </div>

                <div class="field-grid">
                    <!-- Academic Session -->
                    <div class="form-group">
                        <label for="formAcademicSession">Academic Session <span style="color:red">*</span></label>
                        <input type="text" name="academic_session" id="formAcademicSession"
                               placeholder="e.g. 2026-27" required>
                    </div>

                    <!-- Section (hidden — kept for data) -->
                    <div class="form-group">
                        <label for="formSectionName">Section</label>
                        <input type="text" name="section_name" id="formSectionName" placeholder="Optional">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="formSubmitBtn">Assign Fee Structure</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── modal open / close ─────────────────────────────────────────── */
function openModal() {
    document.getElementById('assignmentModal').classList.add('active');
}
function closeModal() {
    document.getElementById('assignmentModal').classList.remove('active');
    document.getElementById('assignmentForm').reset();
    document.getElementById('formAction').value = 'create_assignment';
    document.getElementById('formId').value = '';
    document.getElementById('modalTitle').textContent = 'Assign Fee Structure';
    document.getElementById('formSubmitBtn').textContent = 'Assign Fee Structure';
    document.getElementById('formStudentId').value = '';
    document.getElementById('formStudentName').value = '';
    document.getElementById('formAdmissionNo').value = '';
    document.getElementById('formClassName').value = '';
}

function openCreateModal() {
    closeModal();
    document.getElementById('formEffectiveDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('formAcademicSession').value = '<?= e(date('Y') . '-' . substr(date('Y')+1, -2)) ?>';
    openModal();
}

function openEditModal(row) {
    closeModal();
    document.getElementById('modalTitle').textContent = 'Edit Fee Assignment';
    document.getElementById('formSubmitBtn').textContent = 'Update Assignment';
    document.getElementById('formAction').value = 'update_assignment';
    document.getElementById('formId').value = row.id;

    document.getElementById('student_search').value = row.student_name + ' (' + row.admission_no + ')';
    document.getElementById('formStudentId').value = row.student_id;
    document.getElementById('formStudentName').value = row.student_name;
    document.getElementById('formAdmissionNo').value = row.admission_no;
    document.getElementById('formClassName').value = row.class_name;

    document.getElementById('formFeeStructure').value = row.fee_structure_id;
    document.getElementById('formEffectiveDate').value = row.effective_date || '';
    document.getElementById('formDiscount').value = row.discount_amount || 0;
    document.getElementById('formEmiPlan').value = row.emi_plan || '';
    document.getElementById('formAcademicSession').value = row.academic_session || '';
    document.getElementById('formSectionName').value = row.section_name || '';

    document.getElementById('formTransportYes').checked = row.transport_required === 'Yes';
    document.getElementById('formTransportNo').checked  = row.transport_required !== 'Yes';
    document.getElementById('formHostelYes').checked    = row.hostel_required === 'Yes';
    document.getElementById('formHostelNo').checked     = row.hostel_required !== 'Yes';

    openModal();
}

/* ── auto-fill student fields from datalist ────────────────────── */
document.getElementById('student_search').addEventListener('input', function () {
    var val   = this.value;
    var items = document.querySelectorAll('#student_list option');
    items.forEach(function (opt) {
        var text = opt.textContent.trim();
        if (text === val || opt.value === val) {
            document.getElementById('formStudentId').value    = opt.getAttribute('data-id') || '';
            document.getElementById('formStudentName').value  = opt.value;
            document.getElementById('formAdmissionNo').value  = opt.getAttribute('data-admission') || '';
            document.getElementById('formClassName').value    = opt.getAttribute('data-class') || '';
        }
    });
});

/* ── close modal on overlay click ──────────────────────────────── */
document.getElementById('assignmentModal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

/* ── close modal on Escape ─────────────────────────────────────── */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
