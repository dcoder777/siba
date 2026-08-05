<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$error = '';
$success = '';

// ─── Auto-create tables if missing ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        group_name VARCHAR(100) DEFAULT '',
        description TEXT,
        approval_required TINYINT(1) DEFAULT 1,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_code VARCHAR(50),
        name VARCHAR(255) NOT NULL,
        mobile VARCHAR(50),
        email VARCHAR(255),
        gst_number VARCHAR(50),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_no VARCHAR(50) UNIQUE NOT NULL,
        expense_date DATE NOT NULL,
        category_id INT,
        category_name VARCHAR(255) DEFAULT '',
        vendor_id INT,
        vendor_name VARCHAR(255) DEFAULT '',
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
        transaction_id VARCHAR(255),
        payee_name VARCHAR(255),
        status ENUM('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending',
        approved_by INT,
        approved_at DATETIME,
        reject_reason TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_expense_date (expense_date),
        INDEX idx_category (category_id),
        INDEX idx_vendor (vendor_id)
    )");
} catch (\Throwable $e) {}

// ─── Helpers ───

function expense_categories_options(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, name, group_name FROM expense_categories WHERE is_active = 1 ORDER BY group_name, name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function vendor_options(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, vendor_code, name FROM vendors WHERE is_active = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generate_expense_no(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $next = (int) $stmt->fetchColumn() + 1;
    return 'EXP-' . $year . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    // Create
    if ($action === 'create_expense') {
        $expenseDate = trim((string) ($_POST['expense_date'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
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
        $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
        $payeeName = trim((string) ($_POST['payee_name'] ?? ''));

        if ($expenseDate === '' || $categoryId < 1 || $amount <= 0) {
            $error = 'Expense date, category, and amount are required.';
        } else {
            $catName = '';
            if ($categoryId > 0) {
                $cs = $pdo->prepare("SELECT name FROM expense_categories WHERE id = ?");
                $cs->execute([$categoryId]);
                $catName = (string) ($cs->fetchColumn() ?: '');
            }
            if ($vendorId < 1 && $vendorName !== '') {
                $vs = $pdo->prepare("INSERT INTO vendors (name, is_active) VALUES (?, 1)");
                $vs->execute([$vendorName]);
                $vendorId = (int) $pdo->lastInsertId();
            } elseif ($vendorId > 0 && $vendorName === '') {
                $vs = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                $vs->execute([$vendorId]);
                $vendorName = (string) ($vs->fetchColumn() ?: '');
            }

            $uploadDir = __DIR__ . '/../../uploads/expenses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
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
                $expenseNo = generate_expense_no($pdo);
                $stmt = $pdo->prepare("INSERT INTO expenses (expense_no, expense_date, category_id, category_name, vendor_id, vendor_name, bill_no, bill_date, amount, gst_amount, net_amount, description, bill_file, payment_mode, payment_date, cheque_no, transaction_id, payee_name, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
                $stmt->execute([
                    $expenseNo, $expenseDate, $categoryId, $catName, $vendorId ?: null, $vendorName,
                    $billNo ?: null, $billDate ?: null, $amount, $gstAmount, $netAmount,
                    $description ?: null, $billFile, $paymentMode ?: null, $paymentDate ?: null,
                    $chequeNo ?: null, $transactionId ?: null, $payeeName ?: null,
                    (int) ($user['id'] ?? 0),
                ]);
                $success = "Expense {$expenseNo} created successfully.";
            }
        }
    }

    // Update
    if ($action === 'update_expense') {
        $id = (int) ($_POST['id'] ?? 0);
        $expenseDate = trim((string) ($_POST['expense_date'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
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
        $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
        $payeeName = trim((string) ($_POST['payee_name'] ?? ''));

        if ($id < 1) {
            $error = 'Invalid expense ID.';
        } elseif ($expenseDate === '' || $categoryId < 1 || $amount <= 0) {
            $error = 'Expense date, category, and amount are required.';
        } else {
            $chk = $pdo->prepare("SELECT status, bill_file FROM expenses WHERE id = ?");
            $chk->execute([$id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$existing || $existing['status'] !== 'Pending') {
                $error = 'Only pending expenses can be edited.';
            } else {
                $catName = '';
                if ($categoryId > 0) {
                    $cs = $pdo->prepare("SELECT name FROM expense_categories WHERE id = ?");
                    $cs->execute([$categoryId]);
                    $catName = (string) ($cs->fetchColumn() ?: '');
                }
                if ($vendorId < 1 && $vendorName !== '') {
                    $vs = $pdo->prepare("INSERT INTO vendors (name, is_active) VALUES (?, 1)");
                    $vs->execute([$vendorName]);
                    $vendorId = (int) $pdo->lastInsertId();
                } elseif ($vendorId > 0 && $vendorName === '') {
                    $vs = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                    $vs->execute([$vendorId]);
                    $vendorName = (string) ($vs->fetchColumn() ?: '');
                }

                $uploadDir = __DIR__ . '/../../uploads/expenses/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $billFile = $existing['bill_file'];
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
                    $stmt = $pdo->prepare("UPDATE expenses SET expense_date=?, category_id=?, category_name=?, vendor_id=?, vendor_name=?, bill_no=?, bill_date=?, amount=?, gst_amount=?, net_amount=?, description=?, bill_file=?, payment_mode=?, payment_date=?, cheque_no=?, transaction_id=?, payee_name=? WHERE id=?");
                    $stmt->execute([
                        $expenseDate, $categoryId, $catName, $vendorId ?: null, $vendorName,
                        $billNo ?: null, $billDate ?: null, $amount, $gstAmount, $netAmount,
                        $description ?: null, $billFile, $paymentMode ?: null, $paymentDate ?: null,
                        $chequeNo ?: null, $transactionId ?: null, $payeeName ?: null, $id,
                    ]);
                    $success = 'Expense updated successfully.';
                }
            }
        }
    }

    // Delete (soft)
    if ($action === 'delete_expense' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $stmt = $pdo->prepare("UPDATE expenses SET status='Cancelled' WHERE id=? AND status IN ('Pending','Approved','Rejected')");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            $success = 'Expense cancelled.';
        } else {
            $error = 'Expense could not be cancelled.';
        }
    }

    // Approve
    if ($action === 'approve_expense' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$id]);
            $exp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp) {
                throw new \RuntimeException('Expense not found or already processed.');
            }

            $pdo->prepare("UPDATE expenses SET status='Approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([(int) ($user['id'] ?? 0), $id]);

            $payMode = strtolower((string) ($exp['payment_mode'] ?? ''));
            $desc = "Expense {$exp['expense_no']} - {$exp['category_name']} - {$exp['vendor_name']}";
            $payDate = $exp['payment_date'] ?: date('Y-m-d');
            $netAmt = (float) $exp['net_amount'];
            $uid = (int) ($user['id'] ?? 0);

            if ($payMode === 'cash') {
                $pdo->prepare("INSERT INTO cash_book (transaction_date, transaction_type, reference_type, reference_id, description, amount, direction, created_by) VALUES (?, 'payment', 'expense', ?, ?, ?, 'credit', ?)")
                    ->execute([$payDate, $id, $desc, $netAmt, $uid]);
            } else {
                $bankAccountId = 1;
                $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, description, amount, direction, created_by) VALUES (?, ?, 'payment', 'expense', ?, ?, ?, 'credit', ?)")
                    ->execute([$bankAccountId, $payDate, $id, $desc, $netAmt, $uid]);
            }

            $pdo->commit();
            $success = 'Expense approved and posted to books.';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Approval failed: ' . $e->getMessage();
        }
    }

    // Reject
    if ($action === 'reject_expense' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $reason = trim((string) ($_POST['reject_reason'] ?? ''));
        $stmt = $pdo->prepare("UPDATE expenses SET status='Rejected', reject_reason=?, approved_by=?, approved_at=NOW() WHERE id=? AND status='Pending'");
        $stmt->execute([$reason ?: null, (int) ($user['id'] ?? 0), $id]);
        if ($stmt->rowCount() > 0) {
            $success = 'Expense rejected.';
        } else {
            $error = 'Expense could not be rejected.';
        }
    }

    if ($action === 'add_category') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $groupName = trim((string) ($_POST['group_name'] ?? ''));
        if ($name === '') {
            $error = 'Category name is required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO expense_categories (name, group_name) VALUES (?, ?)");
            $stmt->execute([$name, $groupName]);
            $newId = (int) $pdo->lastInsertId();
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['id' => $newId, 'name' => $name, 'group_name' => $groupName]);
                exit;
            }
            header('Location: expense-entry.php');
            exit;
        }
    }

    header('Location: expense-entry.php' . ($error !== '' ? '?error=1' : ''));
    exit;
}

// ─── Filters ───
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$allowedStatuses = ['all', 'Pending', 'Approved', 'Rejected', 'Cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$catFilter = (int) ($_GET['category'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($statusFilter !== 'all') {
    $where[] = 'e.status = :status';
    $params[':status'] = $statusFilter;
}
if ($catFilter > 0) {
    $where[] = 'e.category_id = :cat';
    $params[':cat'] = $catFilter;
}
if ($dateFrom !== '') {
    $where[] = 'e.expense_date >= :df';
    $params[':df'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'e.expense_date <= :dt';
    $params[':dt'] = $dateTo;
}
if ($search !== '') {
    $where[] = '(e.expense_no LIKE :s1 OR e.vendor_name LIKE :s2 OR e.description LIKE :s3 OR e.bill_no LIKE :s4)';
    $like = '%' . $search . '%';
    $params[':s1'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT e.* FROM expenses e $whereClause ORDER BY e.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Stats ───
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$stats = [
    'month_total' => 0.0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];
try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(net_amount),0) FROM expenses WHERE expense_date >= :m1 AND expense_date <= :m2 AND status != 'Cancelled'");
    $st->execute(['m1' => $monthStart, 'm2' => $monthEnd]);
    $stats['month_total'] = (float) $st->fetchColumn();
    $stats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status='Pending'")->fetchColumn();
    $stats['approved'] = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status='Approved'")->fetchColumn();
    $stats['rejected'] = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status='Rejected'")->fetchColumn();
} catch (\Throwable $e) {}

$categories = expense_categories_options($pdo);
$catGroupMap = [];
foreach ($categories as $cat) {
    $catGroupMap[(int) $cat['id']] = $cat['group_name'] ?? '';
}
$vendors = vendor_options($pdo);

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $error = 'Expense not found.';
    }
}

$viewRow = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
    $stmt->execute([(int) $_GET['view']]);
    $viewRow = $stmt->fetch(PDO::FETCH_ASSOC);
}

$rejectId = (int) ($_GET['reject'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Expense Entry – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; flex-wrap:wrap; }
        .tab-bar a { padding:.6rem 1.25rem; font-size:.85rem; font-weight:500; color:var(--text-light); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; }
        .tab-bar a.active { color:var(--primary-color); border-bottom-color:var(--primary-color); }
        .tab-bar a:hover { color:var(--primary-color); }
        .badge-pending { background:#fef3c7; color:#92400e; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-approved { background:#d1fae5; color:#065f46; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-rejected { background:#fee2e2; color:#991b1b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-cancelled { background:#f1f5f9; color:#64748b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .action-btns { display:flex; gap:.35rem; align-items:center; flex-wrap:wrap; }
        .filter-bar { display:flex; align-items:flex-end; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
        .filter-group { display:flex; flex-direction:column; }
        .filter-group label { font-size:.78rem; margin-bottom:.2rem; color:#64748b; }
        .filter-group input, .filter-group select { min-height:36px; padding:.4rem .6rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.85rem; }
        .stats-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
        .stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:1rem 1.25rem; }
        .stat-card .stat-label { font-size:.78rem; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.25rem; }
        .stat-card .stat-value { font-size:1.35rem; font-weight:700; color:#1e293b; }
        .stat-card .stat-value.pending { color:#d97706; }
        .stat-card .stat-value.approved { color:#059669; }
        .stat-card .stat-value.rejected { color:#dc2626; }
        .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:1000; align-items:flex-start; justify-content:center; padding-top:3vh; overflow-y:auto; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:#fff; border-radius:12px; width:100%; max-width:720px; max-height:90vh; overflow-y:auto; padding:1.5rem; margin-bottom:3vh; }
        .modal-box .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:.75rem; }
        .modal-box .modal-header h2 { margin:0; font-size:1.15rem; }
        .modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b; padding:.25rem .5rem; border-radius:6px; }
        .modal-close:hover { background:#f1f5f9; }
        .view-detail { display:flex; gap:.5rem; padding:.4rem 0; border-bottom:1px solid #f1f5f9; }
        .view-detail .vd-label { min-width:150px; font-weight:600; color:#64748b; font-size:.85rem; }
        .view-detail .vd-value { font-size:.85rem; color:#1e293b; }
        .page-links { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
        .page-links a, .page-links span { min-height:34px; padding:.38rem .65rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; text-decoration:none; font-size:.82rem; }
        .page-links a:hover { background:#f1f5f9; }
        .page-links .active { background:#64748b; border-color:#64748b; color:#fff; }
        .txn-fields { display:none; }
        .txn-fields.show { display:block; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Expense Management</h1>
                    <p>Track, approve, and manage all school expenses.</p>
                </div>
                <div class="toolbar-right">
                    <button type="button" class="btn" style="background:#059669;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;cursor:pointer;" onclick="openModal('create')">+ Add Expense</button>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-label">Total Expenses (This Month)</div>
                <div class="stat-value">Rs. <?= number_format($stats['month_total'], 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Approval</div>
                <div class="stat-value pending"><?= $stats['pending'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Approved</div>
                <div class="stat-value approved"><?= $stats['approved'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Rejected</div>
                <div class="stat-value rejected"><?= $stats['rejected'] ?></div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;width:100%;">
                <div class="filter-group">
                    <label for="f_status">Status</label>
                    <select name="status" id="f_status">
                        <option value="all">All Statuses</option>
                        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="f_category">Category</label>
                    <select name="category" id="f_category">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= $catFilter === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?><?= $cat['group_name'] ? ' (' . e($cat['group_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="f_date_from">From</label>
                    <input type="date" name="date_from" id="f_date_from" value="<?= e($dateFrom) ?>">
                </div>
                <div class="filter-group">
                    <label for="f_date_to">To</label>
                    <input type="date" name="date_to" id="f_date_to" value="<?= e($dateTo) ?>">
                </div>
                <div class="filter-group">
                    <label for="f_search">Search</label>
                    <input type="text" name="search" id="f_search" placeholder="Exp No, Vendor, Bill No..." value="<?= e($search) ?>" style="min-width:200px;">
                </div>
                <div class="filter-group" style="align-self:flex-end;">
                    <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:36px;font-size:.85rem;border-radius:8px;">Filter</button>
                    <a href="expense-entry.php" style="font-size:.85rem;color:#64748b;margin-left:.5rem;text-decoration:none;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Main table -->
        <section class="panel" style="padding:1.25rem;">
            <?php if (empty($rows)): ?>
                <p style="text-align:center;padding:2rem;color:var(--text-light);">No expenses found.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Expense No</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Vendor</th>
                                <th>Bill No</th>
                                <th>Amount</th>
                                <th>GST</th>
                                <th>Net Amount</th>
                                <th>Payment Mode</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td style="font-family:monospace;font-size:.82rem;"><?= e($r['expense_no']) ?></td>
                                    <td style="white-space:nowrap;"><?= e($r['expense_date']) ?></td>
                                    <td>
                                        <?= e($r['category_name'] ?? '—') ?>
                                        <?php $grp = $catGroupMap[(int) $r['category_id']] ?? ''; ?>
                                        <?php if ($grp !== ''): ?>
                                            <span style="color:#94a3b8;font-size:.75rem;">(<?= e($grp) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= e($r['vendor_name'] ?: '—') ?></strong></td>
                                    <td><?= e($r['bill_no'] ?? '—') ?></td>
                                    <td>Rs. <?= number_format((float) $r['amount'], 2) ?></td>
                                    <td>Rs. <?= number_format((float) $r['gst_amount'], 2) ?></td>
                                    <td><strong>Rs. <?= number_format((float) $r['net_amount'], 2) ?></strong></td>
                                    <td><?= e($r['payment_mode'] ?? '—') ?></td>
                                    <td>
                                        <?php
                                        $statusClass = 'badge-' . strtolower($r['status']);
                                        ?>
                                        <span class="<?= $statusClass ?>"><?= e($r['status']) ?></span>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button type="button" class="btn btn-sm btn-outline" style="padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick="openModal('view', <?= (int) $r['id'] ?>)">View</button>
                                            <?php if ($r['status'] === 'Pending'): ?>
                                                <button type="button" class="btn btn-sm btn-outline" style="padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick="openModal('edit', <?= (int) $r['id'] ?>)">Edit</button>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Approve this expense?')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="approve_expense">
                                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" style="background:#059669;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Approve</button>
                                                </form>
                                                <button type="button" style="background:#dc2626;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick="openModal('reject', <?= (int) $r['id'] ?>)">Reject</button>
                                            <?php endif; ?>
                                            <?php if (in_array($r['status'], ['Pending', 'Approved', 'Rejected'], true)): ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this expense?')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete_expense">
                                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" style="background:#94a3b8;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Cancel</button>
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
                    <div style="margin-top:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
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
    </main>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- CREATE / EDIT MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-form">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="modal-form-title">Add Expense</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data" id="expense-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="form-action" value="create_expense">
            <input type="hidden" name="id" id="form-id" value="">

            <div class="field-grid">
                <div>
                    <label for="f_expense_date">Expense Date *</label>
                    <input type="date" name="expense_date" id="f_expense_date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label for="f_category_id">Category *</label>
                    <div style="display:flex;gap:.4rem;align-items:center;">
                        <select name="category_id" id="f_category_id" required style="flex:1;min-width:0;">
                            <option value="">-- Select --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?><?= $cat['group_name'] ? ' (' . e($cat['group_name']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-soft" onclick="addCategoryInline()" style="white-space:nowrap;font-size:.78rem;">+ New</button>
                    </div>
                </div>
                <div>
                    <label for="f_vendor_id">Vendor</label>
                    <div style="display:flex;gap:.4rem;align-items:center;">
                        <select name="vendor_id" id="f_vendor_id" style="flex:1;min-width:0;" onchange="document.getElementById('f_vendor_name').value = this.options[this.selectedIndex].text || '';">
                            <option value="0">-- Select Vendor --</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= (int) $v['id'] ?>"><?= e($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="masters.php" target="_blank" class="btn btn-sm btn-soft" style="white-space:nowrap;font-size:.78rem;text-decoration:none;">+ Add New</a>
                    </div>
                    <input type="hidden" name="vendor_name" id="f_vendor_name" value="">
                </div>
                <div>
                    <label for="f_bill_no">Bill Number</label>
                    <input type="text" name="bill_no" id="f_bill_no">
                </div>
                <div>
                    <label for="f_bill_date">Bill Date</label>
                    <input type="date" name="bill_date" id="f_bill_date">
                </div>
                <div>
                    <label for="f_amount">Amount (Rs.) *</label>
                    <input type="number" step="0.01" min="0" name="amount" id="f_amount" required oninput="calcNet()">
                </div>
                <div>
                    <label for="f_gst_amount">GST Amount (Rs.)</label>
                    <input type="number" step="0.01" min="0" name="gst_amount" id="f_gst_amount" value="0" oninput="calcNet()">
                </div>
                <div>
                    <label for="f_net_amount">Net Amount (Rs.)</label>
                    <input type="number" step="0.01" min="0" name="net_amount" id="f_net_amount" readonly style="background:#f1f5f9;">
                </div>
                <div>
                    <label for="f_payment_mode">Payment Mode</label>
                    <select name="payment_mode" id="f_payment_mode" onchange="toggleTxnFields()">
                        <option value="">-- Select --</option>
                        <option value="Cash">Cash</option>
                        <option value="Cheque">Cheque</option>
                        <option value="UPI">UPI</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Card">Card</option>
                        <option value="Online">Online</option>
                    </select>
                </div>
                <div>
                    <label for="f_payment_date">Payment Date</label>
                    <input type="date" name="payment_date" id="f_payment_date">
                </div>
                <div>
                    <label for="f_cheque_no">Cheque No</label>
                    <input type="text" name="cheque_no" id="f_cheque_no">
                </div>
                <div class="txn-fields" id="txn-fields-tid">
                    <label for="f_transaction_id">Transaction ID</label>
                    <input type="text" name="transaction_id" id="f_transaction_id">
                </div>
                <div>
                    <label for="f_payee_name">Payee Name</label>
                    <input type="text" name="payee_name" id="f_payee_name">
                </div>
                <div>
                    <label for="f_bill_file">Bill Upload</label>
                    <input type="file" name="bill_file" id="f_bill_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                </div>
            </div>
            <div class="field-grid" style="margin-top:1rem;">
                <div>
                    <label for="f_description">Description</label>
                    <textarea name="description" id="f_description" rows="3"></textarea>
                </div>
            </div>
            <div style="margin-top:1.25rem;display:flex;gap:.75rem;">
                <button type="submit" class="btn" style="background:#2563eb;padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;color:#fff;border:none;border-radius:8px;cursor:pointer;" id="form-submit-btn">Create Expense</button>
                <button type="button" class="btn btn-outline" style="padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;border-radius:8px;cursor:pointer;" onclick="closeModals()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- VIEW MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-view">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Expense Details</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <div id="view-content">
            <p style="text-align:center;color:#94a3b8;">Loading...</p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- REJECT MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-reject">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h2 style="color:#dc2626;">Reject Expense</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="reject_expense">
            <input type="hidden" name="id" id="reject-id" value="">
            <div style="margin-bottom:1rem;">
                <label for="reject_reason" style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.85rem;">Rejection Reason</label>
                <textarea name="reject_reason" id="reject_reason" rows="3" placeholder="Provide a reason for rejection..." style="width:100%;padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:.75rem;">
                <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:.6rem 1.5rem;border-radius:8px;font-weight:600;cursor:pointer;font-size:.9rem;">Confirm Reject</button>
                <button type="button" class="btn btn-outline" style="padding:.6rem 1.5rem;border-radius:8px;font-size:.9rem;cursor:pointer;" onclick="closeModals()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<script>
var allExpenses = <?= json_encode(array_map(fn(array $r) => [
    'id' => (int) $r['id'],
    'expense_no' => $r['expense_no'],
    'expense_date' => $r['expense_date'],
    'category_id' => (int) $r['category_id'],
    'category_name' => $r['category_name'] ?? '',
    'vendor_id' => (int) ($r['vendor_id'] ?? 0),
    'vendor_name' => $r['vendor_name'] ?? '',
    'bill_no' => $r['bill_no'] ?? '',
    'bill_date' => $r['bill_date'] ?? '',
    'amount' => (float) $r['amount'],
    'gst_amount' => (float) $r['gst_amount'],
    'net_amount' => (float) $r['net_amount'],
    'description' => $r['description'] ?? '',
    'bill_file' => $r['bill_file'] ?? '',
    'payment_mode' => $r['payment_mode'] ?? '',
    'payment_date' => $r['payment_date'] ?? '',
    'cheque_no' => $r['cheque_no'] ?? '',
    'transaction_id' => $r['transaction_id'] ?? '',
    'payee_name' => $r['payee_name'] ?? '',
    'status' => $r['status'],
], $rows)) ?>;

function calcNet() {
    var amt = parseFloat(document.getElementById('f_amount').value) || 0;
    var gst = parseFloat(document.getElementById('f_gst_amount').value) || 0;
    document.getElementById('f_net_amount').value = (amt + gst).toFixed(2);
}

function toggleTxnFields() {
    var mode = document.getElementById('f_payment_mode').value;
    var el = document.getElementById('txn-fields-tid');
    if (mode === 'UPI' || mode === 'Bank Transfer' || mode === 'Online') {
        el.classList.add('show');
    } else {
        el.classList.remove('show');
    }
}

function closeModals() {
    document.getElementById('modal-form').classList.remove('open');
    document.getElementById('modal-view').classList.remove('open');
    document.getElementById('modal-reject').classList.remove('open');
}

function openModal(type, id) {
    closeModals();
    if (type === 'create') {
        document.getElementById('modal-form-title').textContent = 'Add Expense';
        document.getElementById('form-action').value = 'create_expense';
        document.getElementById('form-id').value = '';
        document.getElementById('form-submit-btn').textContent = 'Create Expense';
        document.getElementById('expense-form').reset();
        document.getElementById('f_expense_date').value = <?= json_encode(date('Y-m-d')) ?>;
        document.getElementById('f_gst_amount').value = '0';
        document.getElementById('f_net_amount').value = '0';
        toggleTxnFields();
        document.getElementById('modal-form').classList.add('open');
    } else if (type === 'edit') {
        var exp = allExpenses.find(function(e) { return e.id === id; });
        if (!exp) return;
        document.getElementById('modal-form-title').textContent = 'Edit Expense';
        document.getElementById('form-action').value = 'update_expense';
        document.getElementById('form-id').value = exp.id;
        document.getElementById('form-submit-btn').textContent = 'Update Expense';
        document.getElementById('f_expense_date').value = exp.expense_date;
        document.getElementById('f_category_id').value = exp.category_id;
        document.getElementById('f_vendor_id').value = exp.vendor_id;
        document.getElementById('f_vendor_name').value = exp.vendor_name;
        document.getElementById('f_bill_no').value = exp.bill_no;
        document.getElementById('f_bill_date').value = exp.bill_date;
        document.getElementById('f_amount').value = exp.amount;
        document.getElementById('f_gst_amount').value = exp.gst_amount;
        document.getElementById('f_net_amount').value = exp.net_amount;
        document.getElementById('f_payment_mode').value = exp.payment_mode;
        document.getElementById('f_payment_date').value = exp.payment_date;
        document.getElementById('f_cheque_no').value = exp.cheque_no;
        document.getElementById('f_transaction_id').value = exp.transaction_id;
        document.getElementById('f_payee_name').value = exp.payee_name;
        document.getElementById('f_description').value = exp.description;
        toggleTxnFields();
        document.getElementById('modal-form').classList.add('open');
    } else if (type === 'view') {
        var exp = allExpenses.find(function(e) { return e.id === id; });
        if (!exp) return;
        var html = '';
        var fields = [
            ['Expense No', exp.expense_no],
            ['Date', exp.expense_date],
            ['Category', exp.category_name || '—'],
            ['Vendor', exp.vendor_name || '—'],
            ['Bill No', exp.bill_no || '—'],
            ['Bill Date', exp.bill_date || '—'],
            ['Amount', 'Rs. ' + parseFloat(exp.amount).toFixed(2)],
            ['GST Amount', 'Rs. ' + parseFloat(exp.gst_amount).toFixed(2)],
            ['Net Amount', '<strong>Rs. ' + parseFloat(exp.net_amount).toFixed(2) + '</strong>'],
            ['Payment Mode', exp.payment_mode || '—'],
            ['Payment Date', exp.payment_date || '—'],
            ['Cheque No', exp.cheque_no || '—'],
            ['Transaction ID', exp.transaction_id || '—'],
            ['Payee Name', exp.payee_name || '—'],
            ['Description', exp.description || '—'],
            ['Status', exp.status]
        ];
        fields.forEach(function(f) {
            html += '<div class="view-detail"><div class="vd-label">' + f[0] + '</div><div class="vd-value">' + f[1] + '</div></div>';
        });
        if (exp.bill_file) {
            html += '<div style="margin-top:1rem;"><a href="../../uploads/expenses/' + exp.bill_file + '" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none;padding:.4rem .8rem;border-radius:6px;font-size:.82rem;">View Uploaded Bill</a></div>';
        }
        document.getElementById('view-content').innerHTML = html;
        document.getElementById('modal-view').classList.add('open');
    } else if (type === 'reject') {
        document.getElementById('reject-id').value = id;
        document.getElementById('reject_reason').value = '';
        document.getElementById('modal-reject').classList.add('open');
    }
}

function addCategoryInline() {
    var name = prompt('Enter new expense category name:');
    if (!name || name.trim() === '') return;
    var groupName = prompt('Group name (optional, e.g. Operations, Admin):') || '';
    var fd = new FormData();
    fd.append('_token', <?= json_encode(csrf_token()) ?>);
    fd.append('action', 'add_category');
    fd.append('name', name.trim());
    fd.append('group_name', groupName.trim());
    fetch('expense-entry.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.id) {
                var sel = document.getElementById('f_category_id');
                var opt = document.createElement('option');
                opt.value = data.id;
                opt.text = data.name + (data.group_name ? ' (' + data.group_name + ')' : '');
                sel.add(opt);
                sel.value = String(data.id);
            } else {
                alert(data && data.error ? data.error : 'Could not add category.');
            }
        })
        .catch(function() { alert('Could not add category.'); });
}

document.addEventListener('DOMContentLoaded', function() {
    toggleTxnFields();
});
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
