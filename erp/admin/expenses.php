<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];

$error = '';
$success = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    bill_no VARCHAR(100),
    bill_date DATE,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    description TEXT,
    bill_file VARCHAR(500),
    payment_mode VARCHAR(50),
    payment_date DATE,
    cheque_no VARCHAR(100),
    payee_name VARCHAR(255),
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    approved_by INT,
    approved_at DATETIME,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

try { $pdo->exec("ALTER TABLE expense_categories ADD INDEX idx_name (name)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE expenses ADD INDEX idx_status (status)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE expenses ADD INDEX idx_payment_date (payment_date)"); } catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (in_array($action, ['add', 'edit'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $vendorName = trim((string) ($_POST['vendor_name'] ?? ''));
        $billNo = trim((string) ($_POST['bill_no'] ?? ''));
        $billDate = trim((string) ($_POST['bill_date'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $gstAmount = (float) ($_POST['gst_amount'] ?? 0);
        $netAmount = (float) ($_POST['net_amount'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));
        $paymentMode = trim((string) ($_POST['payment_mode'] ?? ''));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        $chequeNo = trim((string) ($_POST['cheque_no'] ?? ''));
        $payeeName = trim((string) ($_POST['payee_name'] ?? ''));

        if ($categoryId < 1 || $vendorName === '') {
            $error = 'Category and Vendor Name are required.';
        } else {
            $uploadDir = __DIR__ . '/../../uploads/expenses/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $billFile = null;
            if (isset($_FILES['bill_file']) && $_FILES['bill_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['bill_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];
                if (in_array($ext, $allowed, true)) {
                    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['bill_file']['name']));
                    move_uploaded_file($_FILES['bill_file']['tmp_name'], $uploadDir . $filename);
                    $billFile = $filename;
                } else {
                    $error = 'File type not allowed. Allowed: PDF, JPG, PNG, GIF, DOC, DOCX.';
                }
            }

            if ($error === '') {
                if ($id > 0) {
                    $existing = $pdo->prepare("SELECT bill_file FROM expenses WHERE id=?");
                    $existing->execute([$id]);
                    $oldFile = $existing->fetchColumn();
                    if ($billFile === null) $billFile = $oldFile;
                    $stmt = $pdo->prepare("UPDATE expenses SET category_id=?, vendor_name=?, bill_no=?, bill_date=?, amount=?, gst_amount=?, net_amount=?, description=?, bill_file=?, payment_mode=?, payment_date=?, cheque_no=?, payee_name=? WHERE id=?");
                    $stmt->execute([$categoryId, $vendorName, $billNo ?: null, $billDate ?: null, $amount, $gstAmount, $netAmount, $description ?: null, $billFile, $paymentMode ?: null, $paymentDate ?: null, $chequeNo ?: null, $payeeName ?: null, $id]);
                    $success = 'Expense updated successfully.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO expenses (category_id, vendor_name, bill_no, bill_date, amount, gst_amount, net_amount, description, bill_file, payment_mode, payment_date, cheque_no, payee_name, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending',?)");
                    $stmt->execute([$categoryId, $vendorName, $billNo ?: null, $billDate ?: null, $amount, $gstAmount, $netAmount, $description ?: null, $billFile, $paymentMode ?: null, $paymentDate ?: null, $chequeNo ?: null, $payeeName ?: null, (int) ($user['id'] ?? 0)]);
                    $success = 'Expense added successfully.';
                }
            }
        }
    }

    if ($action === 'delete' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $delRow = $pdo->prepare("SELECT bill_file FROM expenses WHERE id=?");
        $delRow->execute([$id]);
        $delFile = $delRow->fetchColumn();
        $uploadDir = __DIR__ . '/../../uploads/expenses/';
        if ($delFile && file_exists($uploadDir . $delFile)) unlink($uploadDir . $delFile);
        $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
        $success = 'Expense deleted successfully.';
    }

    if ($action === 'approve' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $stmt = $pdo->prepare("UPDATE expenses SET status='Approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='Pending'");
        $stmt->execute([(int) ($user['id'] ?? 0), $id]);
        if ($stmt->rowCount() > 0) $success = 'Expense approved.';
        else $error = 'Expense could not be approved (already processed or not found).';
    }

    if ($action === 'reject' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $stmt = $pdo->prepare("UPDATE expenses SET status='Rejected', approved_by=?, approved_at=NOW() WHERE id=? AND status='Pending'");
        $stmt->execute([(int) ($user['id'] ?? 0), $id]);
        if ($stmt->rowCount() > 0) $success = 'Expense rejected.';
        else $error = 'Expense could not be rejected (already processed or not found).';
    }
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $error = 'Expense not found.';
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$allowedStatuses = ['all', 'Pending', 'Approved', 'Rejected'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';

$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = 'e.status = :status';
    $params[':status'] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(e.description LIKE :search OR e.vendor_name LIKE :search2 OR e.bill_no LIKE :search3)';
    $params[':search'] = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT e.*, ec.name AS category_name FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id $whereClause ORDER BY e.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catStmt = $pdo->query("SELECT id, name FROM expense_categories ORDER BY name");
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'all' => (int) $pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn(),
    'Pending' => (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status='Pending'")->fetchColumn(),
    'Approved' => (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status='Approved'")->fetchColumn(),
    'Rejected' => (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status='Rejected'")->fetchColumn(),
];

$showForm = isset($_GET['action']) && $_GET['action'] === 'add';
$formAction = $editRow ? 'edit' : ($showForm ? 'add' : '');
$formTitle = $editRow ? 'Edit Expense' : ($showForm ? 'Add Expense' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Expenses – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .tab-bar a { padding:.6rem 1.5rem; font-size:.9rem; font-weight:500; color:var(--text-light); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab-bar a.active { color:var(--primary-color); border-bottom-color:var(--primary-color); }
        .tab-bar a:hover { color:var(--primary-color); }
        .badge-pending { background:#fef3c7; color:#92400e; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-approved { background:#d1fae5; color:#065f46; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-rejected { background:#fee2e2; color:#991b1b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .action-btns { display:flex; gap:.4rem; align-items:center; flex-wrap:wrap; }
        .filter-bar { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
        .search-form { display:flex; gap:.5rem; align-items:center; }
        .search-form input { min-height:38px; padding:.45rem .7rem; border-radius:10px; width:200px; }
        .search-form button { min-height:38px; padding:.45rem 1rem; border-radius:10px; }
        .page-links { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
        .page-links a, .page-links span { min-height:34px; padding:.38rem .65rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; text-decoration:none; font-size:.82rem; }
        .page-links a:hover { background:#f1f5f9; }
        .page-links .active { background:#64748b; border-color:#64748b; color:#fff; }
    </style>
</head>
<body>
<div class="admin-layout">
    <aside class="sidebar" style="display:flex;flex-direction:column;">
        <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
            <span class="eyebrow" style="background:rgba(255,255,255,.1);color:#effff5">SIBA ERP</span>
            <div class="brand-copy">
                <h2 style="font-size:1.7rem;color:#fff">Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
        </div>
        <div class="nav-group">
            <div class="nav-title">Finance</div>
            <a class="nav-link" href="finance-dashboard.php"><span class="sidebar-icon">📊</span><span>Finance Dashboard</span></a>
            <a class="nav-link" href="fee-structures.php"><span class="sidebar-icon">🏗</span><span>Fee Structures</span></a>
            <a class="nav-link" href="fee-collection.php"><span class="sidebar-icon">💰</span><span>Fee Collection</span></a>
            <a class="nav-link" href="receipts-list.php"><span class="sidebar-icon">🧾</span><span>Receipts</span></a>
            <a class="nav-link active" href="expenses.php"><span class="sidebar-icon">📤</span><span>Expenses</span></a>
            <a class="nav-link" href="income.php"><span class="sidebar-icon">📥</span><span>Income</span></a>
            <a class="nav-link" href="accounts.php"><span class="sidebar-icon">🏦</span><span>Accounts</span></a>
            <a class="nav-link" href="reports.php"><span class="sidebar-icon">📈</span><span>Reports</span></a>
        </div>
        <?php if ($isOwner): ?>
        <div class="nav-group">
            <div class="nav-title">Administration</div>
            <?php $pendingAdminCount = 0; try { $pendingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'pending'")->fetchColumn(); } catch (\Throwable $e) {} ?>
            <a class="nav-link" href="admin-requests.php">
                <span class="sidebar-icon">🔑</span>
                <span>Admin Requests</span>
                <?php if ($pendingAdminCount > 0): ?>
                    <span class="nav-tag" style="background:#f59e0b;color:#fff;"><?= $pendingAdminCount ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>
        <div class="nav-group" style="margin-top:auto;">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Expenses</h1>
                    <p>Manage school expenses, bills, and payments.</p>
                </div>
                <div class="toolbar-right">
                    <?php if (!$editRow && !$showForm): ?>
                        <a href="?action=add" class="btn btn-sm" style="background:#059669;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;text-decoration:none;">+ Add Expense</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($formAction === 'add' || $editRow): ?>
            <section class="panel" style="padding:1.25rem;margin-bottom:1.5rem;">
                <div class="section-title">
                    <div>
                        <h2><?= $formTitle ?></h2>
                        <p>Fill in the details below.</p>
                    </div>
                    <a href="expenses.php" class="btn btn-sm btn-outline" style="padding:.4rem .8rem;font-size:.8rem;border-radius:8px;text-decoration:none;">← Back</a>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $formAction ?>">
                    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

                    <div class="field-grid">
                        <div>
                            <label for="category_id">Category *</label>
                            <select name="category_id" id="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int) $cat['id'] ?>" <?= ((int) ($editRow['category_id'] ?? 0) === (int) $cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="vendor_name">Vendor Name *</label>
                            <input type="text" name="vendor_name" id="vendor_name" required value="<?= e($editRow['vendor_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="bill_no">Bill No</label>
                            <input type="text" name="bill_no" id="bill_no" value="<?= e($editRow['bill_no'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="bill_date">Bill Date</label>
                            <input type="date" name="bill_date" id="bill_date" value="<?= e($editRow['bill_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="amount">Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0" name="amount" id="amount" required value="<?= e((string) ($editRow['amount'] ?? '0')) ?>" oninput="calcNet()">
                        </div>
                        <div>
                            <label for="gst_amount">GST Amount (Rs.)</label>
                            <input type="number" step="0.01" min="0" name="gst_amount" id="gst_amount" value="<?= e((string) ($editRow['gst_amount'] ?? '0')) ?>" oninput="calcNet()">
                        </div>
                        <div>
                            <label for="net_amount">Net Amount (Rs.)</label>
                            <input type="number" step="0.01" min="0" name="net_amount" id="net_amount" readonly value="<?= e((string) ($editRow['net_amount'] ?? '0')) ?>" style="background:#f1f5f9;">
                        </div>
                        <div>
                            <label for="payment_mode">Payment Mode</label>
                            <select name="payment_mode" id="payment_mode" onchange="toggleCheque()">
                                <option value="">-- Select --</option>
                                <option value="Cash" <?= ($editRow['payment_mode'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="Cheque" <?= ($editRow['payment_mode'] ?? '') === 'Cheque' ? 'selected' : '' ?>>Cheque</option>
                                <option value="Bank Transfer" <?= ($editRow['payment_mode'] ?? '') === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="Online" <?= ($editRow['payment_mode'] ?? '') === 'Online' ? 'selected' : '' ?>>Online</option>
                                <option value="Card" <?= ($editRow['payment_mode'] ?? '') === 'Card' ? 'selected' : '' ?>>Card</option>
                            </select>
                        </div>
                        <div>
                            <label for="cheque_no">Cheque No</label>
                            <input type="text" name="cheque_no" id="cheque_no" value="<?= e($editRow['cheque_no'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="payment_date">Payment Date</label>
                            <input type="date" name="payment_date" id="payment_date" value="<?= e($editRow['payment_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="payee_name">Payee Name</label>
                            <input type="text" name="payee_name" id="payee_name" value="<?= e($editRow['payee_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="bill_file">Bill File (PDF/Image)</label>
                            <input type="file" name="bill_file" id="bill_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                            <?php if (isset($editRow['bill_file']) && $editRow['bill_file']): ?>
                                <div style="margin-top:.35rem;font-size:.82rem;">
                                    Current: <a href="../../uploads/expenses/<?= e($editRow['bill_file']) ?>" target="_blank"><?= e($editRow['bill_file']) ?></a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="field-grid" style="margin-top:1rem;">
                        <div>
                            <label for="description">Description</label>
                            <textarea name="description" id="description" rows="3"><?= e($editRow['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div style="margin-top:1rem;display:flex;gap:.75rem;">
                        <button type="submit" class="btn" style="background:#2563eb;padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;"><?= $editRow ? 'Update Expense' : 'Add Expense' ?></button>
                        <a href="expenses.php" class="btn btn-outline" style="padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;text-decoration:none;">Cancel</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$editRow): ?>
            <div class="tab-bar">
                <a href="expenses.php?status=all<?= $search ? '&search=' . e($search) : '' ?>" class="<?= $statusFilter === 'all' ? 'active' : '' ?>">All (<?= $counts['all'] ?>)</a>
                <a href="expenses.php?status=Pending<?= $search ? '&search=' . e($search) : '' ?>" class="<?= $statusFilter === 'Pending' ? 'active' : '' ?>">Pending (<?= $counts['Pending'] ?>)</a>
                <a href="expenses.php?status=Approved<?= $search ? '&search=' . e($search) : '' ?>" class="<?= $statusFilter === 'Approved' ? 'active' : '' ?>">Approved (<?= $counts['Approved'] ?>)</a>
                <a href="expenses.php?status=Rejected<?= $search ? '&search=' . e($search) : '' ?>" class="<?= $statusFilter === 'Rejected' ? 'active' : '' ?>">Rejected (<?= $counts['Rejected'] ?>)</a>
            </div>

            <div class="filter-bar">
                <form method="get" class="search-form">
                    <?php if ($statusFilter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                    <?php endif; ?>
                    <input type="text" name="search" placeholder="Search description, vendor, bill no..." value="<?= e($search) ?>">
                    <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:38px;font-size:.85rem;border-radius:10px;">Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="expenses.php<?= $statusFilter !== 'all' ? '?status=' . e($statusFilter) : '' ?>" style="font-size:.85rem;color:#64748b;">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <section class="panel" style="padding:1.25rem;">
                <?php if (empty($rows)): ?>
                    <p style="text-align:center;padding:2rem;color:var(--text-light);">No expenses found.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Category</th>
                                    <th>Vendor</th>
                                    <th>Bill No</th>
                                    <th>Amount</th>
                                    <th>GST</th>
                                    <th>Net Amt</th>
                                    <th>Mode</th>
                                    <th>Payment Date</th>
                                    <th>Status</th>
                                    <th>Bill</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = $offset + 1; foreach ($rows as $r): ?>
                                    <tr>
                                        <td style="color:#94a3b8;"><?= $i++ ?></td>
                                        <td><?= e($r['category_name'] ?? '—') ?></td>
                                        <td><strong><?= e($r['vendor_name']) ?></strong></td>
                                        <td><?= e($r['bill_no'] ?? '—') ?></td>
                                        <td>Rs. <?= number_format((float) $r['amount'], 2) ?></td>
                                        <td>Rs. <?= number_format((float) $r['gst_amount'], 2) ?></td>
                                        <td><strong>Rs. <?= number_format((float) $r['net_amount'], 2) ?></strong></td>
                                        <td><?= e($r['payment_mode'] ?? '—') ?></td>
                                        <td style="white-space:nowrap;"><?= e($r['payment_date'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($r['status'] === 'Pending'): ?>
                                                <span class="badge-pending">Pending</span>
                                            <?php elseif ($r['status'] === 'Approved'): ?>
                                                <span class="badge-approved">Approved</span>
                                            <?php else: ?>
                                                <span class="badge-rejected">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($r['bill_file']): ?>
                                                <a href="../../uploads/expenses/<?= e($r['bill_file']) ?>" target="_blank" style="font-size:.82rem;">Download</a>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <a href="?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline" style="padding:.25rem .6rem;font-size:.75rem;border-radius:6px;text-decoration:none;">Edit</a>
                                                <?php if ($r['status'] === 'Pending'): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Approve this expense?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" style="background:#059669;color:#fff;border:none;padding:.25rem .6rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Approve</button>
                                                    </form>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Reject this expense?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:.25rem .6rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Reject</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this expense permanently?')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:.25rem .6rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination" style="margin-top:1rem;">
                            <div style="font-size:.85rem;color:#64748b;">Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?></div>
                            <div class="page-links">
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
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/erp.js"></script>
<script>
function calcNet() {
    var amt = parseFloat(document.getElementById('amount').value) || 0;
    var gst = parseFloat(document.getElementById('gst_amount').value) || 0;
    document.getElementById('net_amount').value = (amt + gst).toFixed(2);
}
function toggleCheque() {
    var mode = document.getElementById('payment_mode').value;
    var chequeField = document.getElementById('cheque_no');
    if (mode === 'Cheque') {
        chequeField.parentElement.style.display = 'block';
        chequeField.required = true;
    } else {
        chequeField.parentElement.style.display = 'none';
        chequeField.required = false;
    }
}
<?php if (!isset($editRow['payment_mode']) || ($editRow['payment_mode'] ?? '') !== 'Cheque'): ?>
document.addEventListener('DOMContentLoaded', function() { toggleCheque(); });
<?php endif; ?>
</script>
</body>
</html>
