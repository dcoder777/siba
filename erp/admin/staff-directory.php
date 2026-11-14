<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'hr'); 
$error = '';
$success = '';
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ─── Helpers ───
function generate_employee_code(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $next = (int) $stmt->fetchColumn() + 1;
    return 'EMP-' . $year . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function handle_cv_upload(): ?string {
    if (isset($_FILES['cv']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['cv']['tmp_name'];
        $name = basename($_FILES['cv']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx'];
        if (in_array($ext, $allowed)) {
            $newName = uniqid('cv_') . '.' . $ext;
            $dest = __DIR__ . '/../uploads/cvs/' . $newName;
            if (move_uploaded_file($tmpName, $dest)) {
                return 'uploads/cvs/' . $newName;
            }
        }
    }
    return null;
}

// ─── POST handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    // Create employee
    if ($action === 'create_employee') {
        $employeeCode = trim((string) ($_POST['employee_code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
        $altContactNumber = trim((string) ($_POST['alt_contact_number'] ?? ''));
        $aadharNumber = trim((string) ($_POST['aadhar_number'] ?? ''));
        $panNumber = trim((string) ($_POST['pan_number'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $designation = trim((string) ($_POST['designation'] ?? ''));
        $joiningDate = trim((string) ($_POST['joining_date'] ?? ''));
        $ctc = (float) ($_POST['ctc'] ?? 0);
        $payoutAccount = trim((string) ($_POST['payout_account'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $cvPath = handle_cv_upload();

        if ($name === '' || $department === '' || $designation === '' || $contactNumber === '') {
            $error = 'Name, Contact Number, Department, and Designation are required.';
        } else {
            if (!$employeeCode) {
                $employeeCode = generate_employee_code($pdo);
            }
            if ($joiningDate === '') $joiningDate = null;
            
            $stmt = $pdo->prepare("INSERT INTO employees (employee_code, name, email, contact_number, alt_contact_number, aadhar_number, pan_number, cv_path, department, designation, joining_date, ctc, payout_account, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$employeeCode, $name, $email, $contactNumber, $altContactNumber, $aadharNumber, $panNumber, $cvPath, $department, $designation, $joiningDate, $ctc, $payoutAccount, $status]);
            $success = 'Employee added successfully.';
        }
    }

    // Update employee
    if ($action === 'update_employee' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $employeeCode = trim((string) ($_POST['employee_code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
        $altContactNumber = trim((string) ($_POST['alt_contact_number'] ?? ''));
        $aadharNumber = trim((string) ($_POST['aadhar_number'] ?? ''));
        $panNumber = trim((string) ($_POST['pan_number'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $designation = trim((string) ($_POST['designation'] ?? ''));
        $joiningDate = trim((string) ($_POST['joining_date'] ?? ''));
        $ctc = (float) ($_POST['ctc'] ?? 0);
        $payoutAccount = trim((string) ($_POST['payout_account'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $newCv = handle_cv_upload();

        if ($name === '' || $department === '' || $designation === '' || $contactNumber === '') {
            $error = 'Name, Contact Number, Department, and Designation are required.';
        } else {
            if ($joiningDate === '') $joiningDate = null;
            
            if ($newCv !== null) {
                $stmt = $pdo->prepare("UPDATE employees SET employee_code=?, name=?, email=?, contact_number=?, alt_contact_number=?, aadhar_number=?, pan_number=?, cv_path=?, department=?, designation=?, joining_date=?, ctc=?, payout_account=?, status=? WHERE id=?");
                $stmt->execute([$employeeCode, $name, $email, $contactNumber, $altContactNumber, $aadharNumber, $panNumber, $newCv, $department, $designation, $joiningDate, $ctc, $payoutAccount, $status, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE employees SET employee_code=?, name=?, email=?, contact_number=?, alt_contact_number=?, aadhar_number=?, pan_number=?, department=?, designation=?, joining_date=?, ctc=?, payout_account=?, status=? WHERE id=?");
                $stmt->execute([$employeeCode, $name, $email, $contactNumber, $altContactNumber, $aadharNumber, $panNumber, $department, $designation, $joiningDate, $ctc, $payoutAccount, $status, $id]);
            }
            $success = 'Employee updated successfully.';
        }
    }

    // Delete employee
    if ($action === 'delete_employee' && isset($_POST['id'])) {
        if (!$canDelete && !$isOwner) {
            $error = 'You do not have delete permission.';
        } else {
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            $success = 'Employee deleted.';
        }
    }

    $qs = [];
    if ($error !== '') $qs['error'] = $error;
    elseif ($success !== '') $qs['success'] = $success;
    $qStr = $qs ? '?' . http_build_query($qs) : '';
    header('Location: staff-directory.php' . $qStr);
    exit;
}

// ─── Filters ───
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$departmentFilter = trim((string) ($_GET['department'] ?? 'all'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(e.employee_code LIKE :s1 OR e.name LIKE :s2 OR e.designation LIKE :s3)';
    $like = '%' . $search . '%';
    $params[':s1'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
}

if ($statusFilter !== 'all') {
    $where[] = 'e.status = :st';
    $params[':st'] = $statusFilter;
}

if ($departmentFilter !== 'all') {
    $where[] = 'e.department = :dept';
    $params[':dept'] = $departmentFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT e.* FROM employees e $whereClause ORDER BY e.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// ─── Stats ───
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0];
try {
    $stats['total'] = (int) $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    $stats['active'] = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
    $stats['inactive'] = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'inactive'")->fetchColumn();
} catch (\Throwable $e) {}

// Edit record
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $error = 'Employee not found.'; }
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

$editMode = $editRow !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Master Directory – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
    <style>
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .stats-grid { display: grid; gap: 1.5rem; }
        .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-card .stat-label { font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; }
        .stat-card .stat-value { font-size: 2rem; font-weight: 700; color: #1e293b; line-height: 1; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: flex-start; justify-content: center; padding-top: 3vh; overflow-y: auto; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #fff; border-radius: 12px; width: 100%; max-width: 720px; max-height: 90vh; overflow-y: auto; padding: 1.5rem; margin-bottom: 3vh; }
        .modal-box .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; }
        .modal-box .modal-header h2 { margin: 0; font-size: 1.15rem; }
        .modal-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #64748b; padding: 0.25rem 0.5rem; border-radius: 6px; }
        .modal-close:hover { background: #f1f5f9; }
        .sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        .sortable:hover { color: #2563eb; }
        .sort-icon { font-size: 0.7rem; margin-left: 3px; opacity: 0.4; }
        .sortable.sort-asc .sort-icon, .sortable.sort-desc .sort-icon { opacity: 1; color: #2563eb; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = 'staff-directory.php'; include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">HR & Staff Management</span>
                    <h1>Staff Master Directory</h1>
                    <p>Manage all school employees, their departments, designations, and core details.</p>
                </div>
                <div class="actions">
                    <?php if (!$editMode): ?>
                        <button type="button" class="btn btn-primary" onclick="openFormModal()">+ Add Employee</button>
                    <?php else: ?>
                        <a href="staff-directory.php" class="btn btn-outline">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success" style="margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-label">Total Employees</div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Staff</div>
                <div class="stat-value" style="color:#16a34a;"><?= number_format($stats['active']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inactive Staff</div>
                <div class="stat-value" style="color:#ef4444;"><?= number_format($stats['inactive']) ?></div>
            </div>
        </div>

        <div class="modal-overlay <?= $editMode ? 'open' : '' ?>" id="modal-form">
            <div class="modal-box">
                <div class="modal-header">
                    <h2><?= $editMode ? 'Edit Employee: ' . e($editRow['name']) : 'Register New Employee' ?></h2>
                    <button type="button" class="modal-close" onclick="closeFormModal()">&times;</button>
                </div>
                <form method="POST" action="staff-directory.php" enctype="multipart/form-data" class="stack" style="gap:1rem;">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editMode ? 'update_employee' : 'create_employee' ?>">
                    <?php if ($editMode): ?>
                        <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
                    <?php endif; ?>

                    <div class="grid-form" style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                        <div class="form-group">
                            <label>Employee Code (Auto-generated if empty)</label>
                            <input type="text" name="employee_code" class="form-control" value="<?= $editMode ? e($editRow['employee_code']) : '' ?>" <?= $editMode ? 'readonly style="background:#f8fafc;"' : '' ?>>
                        </div>
                        <div class="form-group">
                            <label>Full Name <span style="color:red">*</span></label>
                            <input type="text" name="name" class="form-control" required value="<?= $editMode ? e($editRow['name']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Email ID</label>
                            <input type="email" name="email" class="form-control" value="<?= $editMode ? e((string)($editRow['email'] ?? '')) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Contact Number <span style="color:red">*</span></label>
                            <input type="text" name="contact_number" class="form-control" required value="<?= $editMode ? e((string)($editRow['contact_number'] ?? '')) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Alternative Contact</label>
                            <input type="text" name="alt_contact_number" class="form-control" value="<?= $editMode ? e((string)($editRow['alt_contact_number'] ?? '')) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Aadhar Number</label>
                            <input type="text" name="aadhar_number" class="form-control" value="<?= $editMode ? e((string)($editRow['aadhar_number'] ?? '')) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>PAN Number</label>
                            <input type="text" name="pan_number" class="form-control" value="<?= $editMode ? e((string)($editRow['pan_number'] ?? '')) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Department <span style="color:red">*</span></label>
                            <select name="department" class="form-control" required>
                                <option value="">Select Department...</option>
                                <?php
                                $predefinedDepts = ['Teaching', 'Administration', 'Management', 'Support Staff', 'IT', 'HR', 'Finance'];
                                $allDepts = array_unique(array_merge($predefinedDepts, $departments));
                                sort($allDepts);
                                foreach ($allDepts as $d):
                                ?>
                                    <option value="<?= e($d) ?>" <?= ($editMode && $editRow['department'] === $d) ? 'selected' : '' ?>><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Designation <span style="color:red">*</span></label>
                            <select name="designation" class="form-control" required>
                                <option value="">Select Designation...</option>
                                <?php
                                $designations = ['Principal', 'Vice Principal', 'Teacher', 'Admin Officer', 'Accountant', 'HR Manager', 'IT Support', 'Clerk', 'Peon', 'Other'];
                                foreach ($designations as $dsg):
                                ?>
                                    <option value="<?= e($dsg) ?>" <?= ($editMode && $editRow['designation'] === $dsg) ? 'selected' : '' ?>><?= e($dsg) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Joining Date</label>
                            <input type="date" name="joining_date" class="form-control" value="<?= $editMode ? e($editRow['joining_date'] ?? '') : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="active" <?= ($editMode && $editRow['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($editMode && $editRow['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:1rem; border-top:1px solid #e2e8f0; padding-top:1rem;">
                        <h3 style="font-size:1rem; margin-bottom:1rem;">Additional Details (Optional)</h3>
                        <div class="grid-form" style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                            <div class="form-group">
                                <label>Annual CTC (₹)</label>
                                <input type="number" step="0.01" name="ctc" class="form-control" value="<?= $editMode ? e((string)$editRow['ctc']) : '0' ?>">
                            </div>
                            <div class="form-group">
                                <label>Payout Account Details</label>
                                <textarea name="payout_account" class="form-control" rows="2" placeholder="Bank A/C or UPI"><?= $editMode ? e((string)$editRow['payout_account']) : '' ?></textarea>
                            </div>
                        </div>
                        <div class="grid-form" style="display:grid; grid-template-columns:1fr; gap:1.5rem; margin-top: 1.5rem;">
                            <div class="form-group">
                                <label>Upload CV (PDF, DOC, DOCX)</label>
                                <input type="file" name="cv" class="form-control" accept=".pdf,.doc,.docx">
                                <?php if ($editMode && !empty($editRow['cv_path'])): ?>
                                    <div style="margin-top: 0.5rem; font-size: 0.85rem;">
                                        Current CV: <a href="/<?= e($editRow['cv_path']) ?>" target="_blank" style="color: #2563eb; text-decoration: underline;">View File</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:1rem; display:flex; gap:1rem; align-items:center;">
                        <button type="submit" class="btn btn-primary"><?= $editMode ? 'Update Employee' : 'Save Employee' ?></button>
                        <?php if ($editMode): ?>
                            <a href="staff-directory.php" class="btn btn-outline">Cancel</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline" onclick="closeFormModal()">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Employee Directory</h2>
                <form method="GET" action="staff-directory.php" style="display:flex; gap:.5rem;">
                    <select name="department" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="all">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= e($d) ?>" <?= $departmentFilter === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="all">All Statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <input type="text" name="search" class="form-control" placeholder="Search name/code..." value="<?= e($search) ?>" style="width:200px;">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search !== '' || $statusFilter !== 'all' || $departmentFilter !== 'all'): ?>
                        <a href="staff-directory.php" class="btn btn-outline">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body">
                <div style="overflow-x:auto;">
                    <table class="app-table" id="empTable">
                    <thead>
                        <tr>
                            <th class="sortable" onclick="sortTable(0, 'str')">Code<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(1, 'str')">Name<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(2, 'str')">Department<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(3, 'str')">Designation<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(4, 'num')">Joining Date<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(5, 'str')">Status<span class="sort-icon">⇅</span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="empTableBody">
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:2rem;">No employees found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                        <tr>
                            <td style="font-weight:600;" data-sort="<?= e($row['employee_code']) ?>"><?= e($row['employee_code']) ?></td>
                            <td data-sort="<?= e($row['name']) ?>"><?= e($row['name']) ?></td>
                            <td data-sort="<?= e($row['department']) ?>"><?= e($row['department']) ?></td>
                            <td data-sort="<?= e($row['designation']) ?>"><?= e($row['designation']) ?></td>
                            <td data-sort="<?= $row['joining_date'] ? strtotime($row['joining_date']) : 0 ?>"><?= $row['joining_date'] ? date('M d, Y', strtotime($row['joining_date'])) : '-' ?></td>
                            <td data-sort="<?= e($row['status']) ?>">
                                <span class="status-badge status-<?= $row['status'] ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:.5rem;">
                                    <a href="staff-directory.php?edit=<?= $row['id'] ?>#createForm" class="btn btn-soft btn-sm">Edit</a>
                                    <?php if ($canDelete || $isOwner): ?>
                                    <form method="POST" action="staff-directory.php" onsubmit="return confirm('Delete this employee? This action cannot be undone.');">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_employee">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn btn-soft btn-sm" style="color:#ef4444;">Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.875rem; color:#64748b;">Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalRecords ?> total records)</span>
                <div style="display:flex; gap:0.5rem;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&department=<?= urlencode($departmentFilter) ?>" class="btn btn-outline btn-sm">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&department=<?= urlencode($departmentFilter) ?>" class="btn btn-outline btn-sm">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
    function openFormModal() {
        document.getElementById('modal-form').classList.add('open');
    }
    function closeFormModal() {
        document.getElementById('modal-form').classList.remove('open');
        // If we are in edit mode, canceling should take us back to list view
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit')) {
            window.location.href = 'staff-directory.php';
        }
    }

    var sortCol = -1, sortAsc = true;
    function sortTable(col, type) {
        var table = document.getElementById('empTable');
        var tbody = document.getElementById('empTableBody');
        var rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length === 1 && rows[0].cells.length === 1) return;

        if (sortCol === col) { sortAsc = !sortAsc; } else { sortCol = col; sortAsc = true; }
        table.querySelectorAll('th.sortable').forEach(function(th) { 
            th.classList.remove('sort-asc','sort-desc'); 
            th.querySelector('.sort-icon').textContent = '⇅'; 
        });
        var th = table.querySelectorAll('th.sortable')[col];
        if (th) { 
            th.classList.add(sortAsc ? 'sort-asc' : 'sort-desc'); 
            th.querySelector('.sort-icon').textContent = sortAsc ? '↑' : '↓'; 
        }
        rows.sort(function(a, b) {
            var ac = a.cells[col], bc = b.cells[col];
            var av = ac.getAttribute('data-sort') !== null ? ac.getAttribute('data-sort') : ac.textContent.trim();
            var bv = bc.getAttribute('data-sort') !== null ? bc.getAttribute('data-sort') : bc.textContent.trim();
            if (type === 'num') { 
                av = parseFloat(av) || 0; bv = parseFloat(bv) || 0; 
                return sortAsc ? av - bv : bv - av; 
            }
            return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
    }
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
