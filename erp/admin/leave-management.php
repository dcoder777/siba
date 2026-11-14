<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canManageHR = can_user_delete($pdo, (int)$user['id'], 'hr') || $isOwner;
$error = '';
$success = '';

// ─── POST handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    // Create leave request
    if ($action === 'create_leave') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $leaveType = trim((string) ($_POST['leave_type'] ?? ''));
        $fromDate = trim((string) ($_POST['from_date'] ?? ''));
        $toDate = trim((string) ($_POST['to_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($employeeId === 0 || $leaveType === '' || $fromDate === '' || $toDate === '') {
            $error = 'Employee, Leave Type, From Date, and To Date are required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, from_date, to_date, reason, status, requested_by) VALUES (?,?,?,?,?,'pending',?)");
            $stmt->execute([$employeeId, $leaveType, $fromDate, $toDate, $reason, $user['id']]);
            $success = 'Leave request submitted successfully.';
        }
    }

    // Approve / Reject Leave
    if ($action === 'update_status' && isset($_POST['id'])) {
        if (!$canManageHR) {
            $error = 'You do not have permission to approve/reject leaves.';
        } else {
            $id = (int) $_POST['id'];
            $status = trim((string) ($_POST['status'] ?? ''));
            if (in_array($status, ['approved', 'rejected'])) {
                $stmt = $pdo->prepare("UPDATE leave_requests SET status=?, approved_by=?, approved_at=NOW() WHERE id=?");
                $stmt->execute([$status, $user['id'], $id]);
                $success = 'Leave request ' . $status . '.';
            }
        }
    }

    // Delete leave request
    if ($action === 'delete_leave' && isset($_POST['id'])) {
        if (!$canManageHR) {
            $error = 'You do not have permission to delete leave requests.';
        } else {
            $id = (int) $_POST['id'];
            $pdo->prepare("DELETE FROM leave_requests WHERE id=?")->execute([$id]);
            $success = 'Leave request deleted.';
        }
    }

    header('Location: leave-management.php' . ($error !== '' ? '?error=' . urlencode($error) : ''));
    exit;
}

// ─── Filters ───
$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = 'lr.status = :st';
    $params[':st'] = $statusFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests lr $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT lr.*, e.name AS employee_name, e.employee_code, e.department, u.name AS approved_by_name 
                       FROM leave_requests lr 
                       JOIN employees e ON e.id = lr.employee_id 
                       LEFT JOIN users u ON u.id = lr.approved_by
                       $whereClause ORDER BY lr.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch employees for dropdown
$employees = $pdo->query("SELECT id, name, employee_code, department FROM employees WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Leave Management – SIBA ERP</title>
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
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending { background: #fef08a; color: #854d0e; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .pagination { display: flex; gap: 0.25rem; align-items: center; }
        .pagination a { padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none; color: #475569; font-size: 0.85rem; }
        .pagination a.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .pagination a:hover:not(.active) { background: #f8fafc; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = 'leave-management.php'; include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">HR & Staff Management</span>
                    <h1>Leave Management</h1>
                    <p>Track, approve, and reject staff leave requests.</p>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-primary" onclick="openFormModal()">+ New Leave Request</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success" style="margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="modal-overlay" id="modal-form">
            <div class="modal-box">
                <div class="modal-header">
                    <h2>Submit Leave Request</h2>
                    <button type="button" class="modal-close" onclick="closeFormModal()">&times;</button>
                </div>
                <form method="POST" action="leave-management.php" class="stack" style="gap:1rem;">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_leave">

                    <div class="grid-form" style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                        <div class="form-group">
                            <label>Employee <span style="color:red">*</span></label>
                            <select name="employee_id" class="form-control" required>
                                <option value="">Select Employee...</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Leave Type <span style="color:red">*</span></label>
                            <select name="leave_type" class="form-control" required>
                                <option value="">Select Type...</option>
                                <option value="Casual Leave (CL)">Casual Leave (CL)</option>
                                <option value="Sick Leave (SL)">Sick Leave (SL)</option>
                                <option value="Earned Leave (EL)">Earned Leave (EL)</option>
                                <option value="Maternity/Paternity Leave">Maternity/Paternity Leave</option>
                                <option value="Leave Without Pay (LWP)">Leave Without Pay (LWP)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>From Date <span style="color:red">*</span></label>
                            <input type="date" name="from_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>To Date <span style="color:red">*</span></label>
                            <input type="date" name="to_date" class="form-control" required>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Reason / Remarks</label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="Brief reason for the leave..."></textarea>
                        </div>
                    </div>

                    <div style="margin-top:1rem; display:flex; gap:1rem; align-items:center;">
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                        <button type="button" class="btn btn-outline" onclick="closeFormModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h2>Leave Requests</h2>
                <form method="GET" action="leave-management.php" style="display:flex; gap:.5rem;">
                    <select name="status" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Requests</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </form>
            </div>
            <div class="card-body" style="padding:0; overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Dates (From - To)</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Action / Approved By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:2rem;">No leave requests found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <strong><?= e($row['employee_name']) ?></strong><br>
                                <small style="color:#64748b;"><?= e($row['employee_code']) ?> &middot; <?= e($row['department']) ?></small>
                            </td>
                            <td><?= e($row['leave_type']) ?></td>
                            <td>
                                <?= date('d M Y', strtotime($row['from_date'])) ?> 
                                <?php if($row['from_date'] !== $row['to_date']): ?>
                                 <br><small style="color:#64748b;">to</small> <?= date('d M Y', strtotime($row['to_date'])) ?>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:200px; white-space:normal;"><?= e($row['reason']) ?></td>
                            <td>
                                <span class="status-badge status-<?= $row['status'] ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'pending' && $canManageHR): ?>
                                    <div style="display:flex;gap:.5rem;">
                                        <form method="POST" action="leave-management.php" onsubmit="return confirm('Approve this leave request?');">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="status" value="approved">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-soft btn-sm" style="color:#16a34a;border-color:#16a34a;">Approve</button>
                                        </form>
                                        <form method="POST" action="leave-management.php" onsubmit="return confirm('Reject this leave request?');">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="status" value="rejected">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-soft btn-sm" style="color:#ef4444;border-color:#ef4444;">Reject</button>
                                        </form>
                                    </div>
                                <?php elseif ($row['status'] !== 'pending'): ?>
                                    <small style="color:#64748b;">By <?= e($row['approved_by_name'] ?? 'Unknown') ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding:1rem 1.5rem;">
                    <div style="font-size:0.85rem; color:#64748b;">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> entries
                    </div>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo;</a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&raquo;</a>
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
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeFormModal();
        }
    });
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
