<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'hr');

// Ensure table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS staff_attendance (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        employee_id BIGINT NOT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('present', 'absent', 'half-day', 'on-leave') NOT NULL DEFAULT 'present',
        remark VARCHAR(255) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_staff_attendance (employee_id, attendance_date),
        CONSTRAINT fk_staff_attendance_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$error = '';
$success = '';
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf($_POST['_token'] ?? '');
    $action = $_POST['action'];

    if ($action === 'create_attendance' || $action === 'update_attendance') {
        $id = (int)($_POST['id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $attDate = $_POST['attendance_date'] ?? date('Y-m-d');
        $status = $_POST['status'] ?? 'present';
        $remark = trim((string)($_POST['remark'] ?? ''));

        if (!$employeeId) {
            $error = 'Employee is required.';
        } else {
            try {
                if ($action === 'create_attendance') {
                    $stmt = $pdo->prepare("INSERT INTO staff_attendance (employee_id, attendance_date, status, remark) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$employeeId, $attDate, $status, $remark]);
                    $success = 'Attendance logged successfully.';
                } else {
                    $stmt = $pdo->prepare("UPDATE staff_attendance SET employee_id=?, attendance_date=?, status=?, remark=? WHERE id=?");
                    $stmt->execute([$employeeId, $attDate, $status, $remark, $id]);
                    $success = 'Attendance updated successfully.';
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'An attendance record for this employee on this date already exists.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_attendance' && isset($_POST['id'])) {
        if (!$canDelete && !$isOwner) {
            $error = 'You do not have delete permission.';
        } else {
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM staff_attendance WHERE id=?")->execute([$id]);
            $success = 'Attendance record deleted.';
        }
    }

    $qs = [];
    if ($error !== '') $qs['error'] = $error;
    elseif ($success !== '') $qs['success'] = $success;
    
    // preserve filters
    if (!empty($_GET['date_from'])) $qs['date_from'] = $_GET['date_from'];
    if (!empty($_GET['date_to'])) $qs['date_to'] = $_GET['date_to'];
    if (!empty($_GET['department'])) $qs['department'] = $_GET['department'];
    if (!empty($_GET['page'])) $qs['page'] = $_GET['page'];

    header('Location: staff-attendance.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

// Filters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$departmentFilter = $_GET['department'] ?? 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($dateFrom !== '') {
    $where[] = "sa.attendance_date >= :date_from";
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "sa.attendance_date <= :date_to";
    $params[':date_to'] = $dateTo;
}

if ($departmentFilter !== 'all') {
    $where[] = "e.department = :dept";
    $params[':dept'] = $departmentFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM staff_attendance sa
    JOIN employees e ON sa.employee_id = e.id
    $whereClause
");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

// Fetch Records
$sql = "
    SELECT sa.id as att_id, sa.attendance_date, sa.status, sa.remark,
           e.id as emp_id, e.employee_code, e.name, e.department, e.designation
    FROM staff_attendance sa
    JOIN employees e ON sa.employee_id = e.id
    $whereClause
    ORDER BY sa.attendance_date DESC, e.name ASC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active employees for the modal dropdown
$allEmployees = $pdo->query("SELECT id, name, employee_code, department FROM employees WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

$editMode = false;
$editRow = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM staff_attendance WHERE id = ?");
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editRow) {
        $editMode = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Attendance – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
    <style>
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: flex-start; justify-content: center; padding-top: 3vh; overflow-y: auto; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #fff; border-radius: 12px; width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; padding: 1.5rem; margin-bottom: 3vh; }
        .modal-box .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; }
        .modal-box .modal-header h2 { margin: 0; font-size: 1.15rem; }
        .modal-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #64748b; padding: 0.25rem 0.5rem; border-radius: 6px; }
        .modal-close:hover { background: #f1f5f9; }
        .sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        .sortable:hover { color: #2563eb; }
        .sort-icon { font-size: 0.7rem; margin-left: 3px; opacity: 0.4; }
        .sortable.sort-asc .sort-icon, .sortable.sort-desc .sort-icon { opacity: 1; color: #2563eb; }
        .status-badge.status-present { background: #dcfce7; color: #166534; }
        .status-badge.status-absent { background: #fee2e2; color: #991b1b; }
        .status-badge.status-half-day { background: #fef3c7; color: #92400e; }
        .status-badge.status-on-leave { background: #ede9fe; color: #5b21b6; }
        .pagination { display: flex; gap: 0.25rem; align-items: center; }
        .pagination a { padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none; color: #475569; font-size: 0.85rem; }
        .pagination a.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .pagination a:hover:not(.active) { background: #f8fafc; }
        .custom-dropdown { position: relative; width: 100%; }
        .custom-dropdown-input { width: 100%; padding-right: 30px; cursor: text; }
        .custom-dropdown-list { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 6px 6px; max-height: 200px; overflow-y: auto; z-index: 1050; display: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .custom-dropdown-list.show { display: block; }
        .custom-dropdown-item { padding: 0.5rem 0.75rem; cursor: pointer; font-size: 0.9rem; border-bottom: 1px solid #f1f5f9; }
        .custom-dropdown-item:last-child { border-bottom: none; }
        .custom-dropdown-item:hover { background: #f8fafc; }
        .custom-dropdown-item.hidden { display: none; }
        .custom-dropdown-icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #64748b; font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = 'staff-attendance.php'; include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">HR & Staff Management</span>
                    <h1>Staff Attendance</h1>
                    <p>Manually log and view historical attendance records for staff members.</p>
                </div>
                <div class="actions">
                    <?php if (!$editMode): ?>
                        <button type="button" class="btn btn-primary" onclick="openFormModal()">+ Add Attendance</button>
                    <?php else: ?>
                        <a href="staff-attendance.php" class="btn btn-outline">Cancel Edit</a>
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

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Attendance Logs</h2>
                <form method="GET" action="staff-attendance.php" style="display:flex; gap:.5rem;">
                    <select name="department" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="all">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= e($d) ?>" <?= $departmentFilter === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>" onchange="this.form.submit()" title="From Date">
                    <span style="color:#64748b; margin-top: 0.5rem;">-</span>
                    <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>" onchange="this.form.submit()" title="To Date">
                    <?php if ($dateFrom !== '' || $dateTo !== '' || $departmentFilter !== 'all'): ?>
                        <a href="staff-attendance.php" class="btn btn-outline">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body" style="padding:0; overflow-x:auto;">
                <table class="app-table" id="attTable">
                    <thead>
                        <tr>
                            <th class="sortable" onclick="sortTable(0, 'num')">Date<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(1, 'str')">Code<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(2, 'str')">Name<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(3, 'str')">Department<span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortTable(4, 'str')">Status<span class="sort-icon">⇅</span></th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="attTableBody">
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:2rem;">No attendance records found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                        <tr>
                            <td data-sort="<?= strtotime($row['attendance_date']) ?>"><?= date('M d, Y', strtotime($row['attendance_date'])) ?></td>
                            <td style="font-weight:600;" data-sort="<?= e($row['employee_code']) ?>"><?= e($row['employee_code']) ?></td>
                            <td data-sort="<?= e($row['name']) ?>"><?= e($row['name']) ?></td>
                            <td data-sort="<?= e($row['department']) ?>"><?= e($row['department']) ?></td>
                            <td data-sort="<?= e($row['status']) ?>">
                                <span class="status-badge status-<?= $row['status'] ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td><?= e($row['remark']) ?: '-' ?></td>
                            <td>
                                <div style="display:flex;gap:.5rem;">
                                    <?php 
                                        $editQs = $_GET;
                                        $editQs['edit'] = $row['att_id'];
                                    ?>
                                    <a href="staff-attendance.php?<?= http_build_query($editQs) ?>" class="btn btn-soft btn-sm">Edit</a>
                                    <?php if ($canDelete || $isOwner): ?>
                                    <form method="POST" action="staff-attendance.php" onsubmit="return confirm('Delete this attendance record?');">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_attendance">
                                        <input type="hidden" name="id" value="<?= $row['att_id'] ?>">
                                        <?php if(isset($_GET['page'])) echo '<input type="hidden" name="page" value="'.e($_GET['page']).'">'; ?>
                                        <?php if(isset($_GET['date_from'])) echo '<input type="hidden" name="date_from" value="'.e($_GET['date_from']).'">'; ?>
                                        <?php if(isset($_GET['date_to'])) echo '<input type="hidden" name="date_to" value="'.e($_GET['date_to']).'">'; ?>
                                        <?php if(isset($_GET['department'])) echo '<input type="hidden" name="department" value="'.e($_GET['department']).'">'; ?>
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
            
            <?php if ($totalPages > 1): ?>
                <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding:1rem 1.5rem;">
                    <div style="font-size:0.85rem; color:#64748b;">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> entries
                    </div>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Prev</a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($p = $startPage; $p <= $endPage; $p++):
                        ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="modal-overlay <?= $editMode ? 'open' : '' ?>" id="modal-form">
            <div class="modal-box">
                <div class="modal-header">
                    <h2><?= $editMode ? 'Edit Attendance' : 'Add Attendance Record' ?></h2>
                    <button type="button" class="modal-close" onclick="closeFormModal()">&times;</button>
                </div>
                <form method="POST" action="staff-attendance.php" class="stack" style="gap:1rem;">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editMode ? 'update_attendance' : 'create_attendance' ?>">
                    <?php if ($editMode): ?>
                        <input type="hidden" name="id" value="<?= $editRow['id'] ?>">
                    <?php endif; ?>
                    
                    <?php if(isset($_GET['page'])) echo '<input type="hidden" name="page" value="'.e($_GET['page']).'">'; ?>
                    <?php if(isset($_GET['date_from'])) echo '<input type="hidden" name="date_from" value="'.e($_GET['date_from']).'">'; ?>
                    <?php if(isset($_GET['date_to'])) echo '<input type="hidden" name="date_to" value="'.e($_GET['date_to']).'">'; ?>
                    <?php if(isset($_GET['department'])) echo '<input type="hidden" name="department" value="'.e($_GET['department']).'">'; ?>

                    <div class="form-group">
                        <label>Employee <span style="color:red">*</span></label>
                        <?php
                            $editEmpName = '';
                            if ($editMode) {
                                foreach ($allEmployees as $e) {
                                    if ($e['id'] == $editRow['employee_id']) {
                                        $editEmpName = $e['name'] . ' (' . $e['employee_code'] . ')';
                                        break;
                                    }
                                }
                            }
                        ?>
                        <?php if ($editMode): ?>
                            <input type="hidden" name="employee_id" value="<?= $editRow['employee_id'] ?>">
                            <input type="text" class="form-control" value="<?= e($editEmpName) ?>" readonly style="background:#f8fafc;">
                        <?php else: ?>
                            <div class="custom-dropdown" id="employeeDropdownWrapper">
                                <input type="text" id="employeeSearch" class="form-control custom-dropdown-input" placeholder="Search by name or code..." autocomplete="off" required>
                                <span class="custom-dropdown-icon">▼</span>
                                <div class="custom-dropdown-list" id="employeeList">
                                    <?php foreach ($allEmployees as $emp): ?>
                                        <div class="custom-dropdown-item" data-id="<?= $emp['id'] ?>" data-search="<?= strtolower(e($emp['name']) . ' ' . e($emp['employee_code'])) ?>">
                                            <div style="font-weight: 600;"><?= e($emp['name']) ?></div>
                                            <div style="font-size: 0.75rem; color: #64748b;"><?= e($emp['employee_code']) ?> - <?= e($emp['department']) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="employee_id" id="employeeId" required>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>Date <span style="color:red">*</span></label>
                        <input type="date" name="attendance_date" class="form-control" required value="<?= $editMode ? e($editRow['attendance_date']) : date('Y-m-d') ?>">
                    </div>

                    <div class="form-group">
                        <label>Status <span style="color:red">*</span></label>
                        <select name="status" class="form-control" required>
                            <option value="present" <?= ($editMode && $editRow['status'] === 'present') ? 'selected' : '' ?>>Present</option>
                            <option value="absent" <?= ($editMode && $editRow['status'] === 'absent') ? 'selected' : '' ?>>Absent</option>
                            <option value="half-day" <?= ($editMode && $editRow['status'] === 'half-day') ? 'selected' : '' ?>>Half-Day</option>
                            <option value="on-leave" <?= ($editMode && $editRow['status'] === 'on-leave') ? 'selected' : '' ?>>On Leave</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea name="remark" class="form-control" rows="2" placeholder="Optional details..."><?= $editMode ? e((string)$editRow['remark']) : '' ?></textarea>
                    </div>

                    <div style="margin-top:1rem; display:flex; gap:1rem; align-items:center;">
                        <button type="submit" class="btn btn-primary"><?= $editMode ? 'Update Record' : 'Save Record' ?></button>
                        <?php if ($editMode): ?>
                            <a href="staff-attendance.php" class="btn btn-outline">Cancel</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline" onclick="closeFormModal()">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

    </main>
</div>
<script>
    function openFormModal() {
        document.getElementById('modal-form').classList.add('open');
    }
    function closeFormModal() {
        document.getElementById('modal-form').classList.remove('open');
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit')) {
            urlParams.delete('edit');
            window.location.search = urlParams.toString();
        }
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeFormModal();
        }
    });

    const searchInput = document.getElementById('employeeSearch');
    const idInput = document.getElementById('employeeId');
    const dropdownWrapper = document.getElementById('employeeDropdownWrapper');
    const dropdownList = document.getElementById('employeeList');

    if (searchInput && idInput && dropdownList) {
        searchInput.addEventListener('focus', function() {
            dropdownList.classList.add('show');
            filterDropdown();
        });

        searchInput.addEventListener('input', function() {
            dropdownList.classList.add('show');
            filterDropdown();
            idInput.value = '';
        });

        function filterDropdown() {
            const filter = searchInput.value.toLowerCase();
            const items = dropdownList.querySelectorAll('.custom-dropdown-item');
            items.forEach(function(item) {
                if (item.getAttribute('data-search').indexOf(filter) > -1) {
                    item.classList.remove('hidden');
                } else {
                    item.classList.add('hidden');
                }
            });
        }

        dropdownList.addEventListener('click', function(e) {
            const item = e.target.closest('.custom-dropdown-item');
            if (item) {
                idInput.value = item.getAttribute('data-id');
                searchInput.value = item.querySelector('div').textContent;
                dropdownList.classList.remove('show');
            }
        });

        document.addEventListener('click', function(e) {
            if (!dropdownWrapper.contains(e.target)) {
                dropdownList.classList.remove('show');
            }
        });
    }

    var sortCol = -1, sortAsc = true;
    function sortTable(col, type) {
        var table = document.getElementById('attTable');
        var tbody = document.getElementById('attTableBody');
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
