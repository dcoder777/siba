<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$error = '';
$success = '';

// ── Auto-migrate finance tables if missing ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(50) UNIQUE NOT NULL,
        student_id INT,
        student_name VARCHAR(200),
        class_name VARCHAR(50),
        academic_session VARCHAR(20),
        total_amount DECIMAL(12,2) NOT NULL,
        discount_amount DECIMAL(12,2) DEFAULT 0,
        late_fee DECIMAL(12,2) DEFAULT 0,
        net_amount DECIMAL(12,2) NOT NULL,
        payment_mode ENUM('Cash','Cheque','UPI','Card','Bank Transfer') NOT NULL,
        payment_date DATE NOT NULL,
        cheque_no VARCHAR(50),
        cheque_date DATE,
        cheque_bank VARCHAR(100),
        cheque_clearance ENUM('Pending','Cleared','Bounced') DEFAULT 'Pending',
        transaction_ref VARCHAR(100),
        collector_id INT,
        collector_name VARCHAR(100),
        notes TEXT,
        status ENUM('Active','Cancelled','Void') DEFAULT 'Active',
        cancelled_at TIMESTAMP NULL,
        cancelled_by INT,
        cancel_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_collection_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_collection_id INT NOT NULL,
        fee_head_id INT NOT NULL,
        fee_head_name VARCHAR(100),
        amount DECIMAL(10,2) NOT NULL,
        is_advance TINYINT(1) DEFAULT 0
    )");
} catch (Throwable $e) {
    // ignore migration errors
}

// ── Handle Cancel ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'cancel_receipt') {
        $receiptId = (int) ($_POST['receipt_id'] ?? 0);
        $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
        if ($receiptId <= 0) {
            $error = 'Invalid receipt.';
        } else {
            $stmt = $pdo->prepare("UPDATE fee_collections SET status = 'Cancelled', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND status = 'Active'");
            $stmt->execute([(int) $user['id'], $reason ?: null, $receiptId]);
            if ($stmt->rowCount() > 0) {
                $success = 'Receipt cancelled successfully.';
            } else {
                $error = 'Receipt not found or already cancelled.';
            }
        }
    }
}

if ($success !== '') {
    $params = ['success' => $success];
    if (isset($_GET['status']) && $_GET['status'] !== '') $params['status'] = (string) $_GET['status'];
    header('Location: receipts-list.php?' . http_build_query($params));
    exit;
}

if (isset($_GET['success'])) $success = (string) $_GET['success'];
if (isset($_GET['error'])) $error = (string) $_GET['error'];

// ── Filters ──
$filterStatus = trim((string) ($_GET['status'] ?? 'Active'));
$allowedStatuses = ['Active', 'Cancelled', 'Void', ''];
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = 'Active';

$searchQ = trim((string) ($_GET['q'] ?? ''));
$filterMode = trim((string) ($_GET['mode'] ?? ''));
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo = trim((string) ($_GET['to'] ?? ''));

$where = [];
$params = [];
if ($filterStatus !== '') {
    $where[] = 'fc.status = :status';
    $params['status'] = $filterStatus;
}
if ($searchQ !== '') {
    $where[] = '(fc.receipt_no LIKE :q1 OR fc.student_name LIKE :q2 OR fc.class_name LIKE :q3)';
    $likeQ = '%' . $searchQ . '%';
    $params['q1'] = $likeQ;
    $params['q2'] = $likeQ;
    $params['q3'] = $likeQ;
}
if ($filterMode !== '') {
    $where[] = 'fc.payment_mode = :mode';
    $params['mode'] = $filterMode;
}
if ($filterFrom !== '') {
    $where[] = 'fc.payment_date >= :frm';
    $params['frm'] = $filterFrom;
}
if ($filterTo !== '') {
    $where[] = 'fc.payment_date <= :to';
    $params['to'] = $filterTo;
}
$whereSql = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

// ── Pagination ──
$page = max(1, (int) ($_GET['p'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_collections fc" . $whereSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $limit));

$sql = "SELECT fc.*, fc.student_name AS student_display FROM fee_collections fc" . $whereSql . " ORDER BY fc.payment_date DESC, fc.id DESC LIMIT :lim OFFSET :off";
$listStmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $listStmt->bindValue(':' . $k, $v);
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$receipts = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// ── View Receipt Data ──
$viewReceipt = null;
$viewItems = [];
$viewStudent = null;
if (isset($_GET['view'])) {
    $vid = (int) $_GET['view'];
    $stmt = $pdo->prepare("SELECT fc.*, fc.student_name AS student_display FROM fee_collections fc WHERE fc.id = ?");
    $stmt->execute([$vid]);
    $viewReceipt = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($viewReceipt) {
        $stmt = $pdo->prepare("SELECT fci.*, fh.name AS fee_head_name FROM fee_collection_items fci LEFT JOIN fee_heads fh ON fh.id = fci.fee_head_id WHERE fci.fee_collection_id = ?");
        $stmt->execute([$vid]);
        $viewItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fee collection count for sidebar badge
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_collections WHERE payment_date = CURDATE() AND status = 'Active'");
    $stmt->execute();
    $todayCount = (int) $stmt->fetchColumn();
} catch (Throwable) {
    $todayCount = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'Pending'");
    $stmt->execute();
    $pendingExpenseCount = (int) $stmt->fetchColumn();
} catch (Throwable) {
    $pendingExpenseCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Receipts – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .app-filters { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
        .app-filters label { font-size:.8rem; margin-bottom:.2rem; }
        .app-filters input, .app-filters select { min-height:38px; padding:.45rem .7rem; border-radius:8px; width:auto; font-size:.85rem; }
        .app-filters .btn { min-height:38px; padding:.45rem 1rem; font-size:.85rem; }
        .app-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .app-table th { text-align:left; padding:.65rem .5rem; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; }
        .app-table td { padding:.65rem .5rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
        .app-table tr:hover td { background:#f8fafc; }
        .pagination { display:flex; gap:.5rem; align-items:center; margin-top:1rem; flex-wrap:wrap; }
        .pagination a, .pagination span { padding:.35rem .7rem; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; font-size:.85rem; color:#334155; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#1e293b; color:#fff; border-color:#1e293b; }
        .badge-active { background:#d1fae5; color:#065f46; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-cancelled { background:#fee2e2; color:#991b1b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-void { background:#f1f5f9; color:#64748b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .receipt-header { text-align:center; margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:2px solid #1e293b; }
        .receipt-header h2 { font-size:1.6rem; margin-bottom:.25rem; }
        .receipt-header p { font-size:.9rem; color:#64748b; }
        .receipt-details { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; margin-bottom:1rem; font-size:.9rem; }
        .receipt-details strong { font-weight:600; }
        .receipt-items { width:100%; border-collapse:collapse; margin-bottom:1rem; font-size:.875rem; }
        .receipt-items th { text-align:left; padding:.5rem .75rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; font-size:.78rem; font-weight:600; text-transform:uppercase; }
        .receipt-items td { padding:.5rem .75rem; border-bottom:1px solid #f1f5f9; }
        .receipt-summary { margin-top:1rem; padding-top:1rem; border-top:2px solid #e2e8f0; text-align:right; font-size:.9rem; }
        .receipt-summary div { margin-bottom:.3rem; }
        .receipt-summary .net { font-size:1.1rem; font-weight:700; color:#0f172a; }
        .cancelled-stamp { display:inline-block; background:#fee2e2; color:#991b1b; padding:.3rem 1rem; border-radius:6px; font-weight:700; font-size:.85rem; margin-top:.5rem; }
        .status-filter { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .status-filter a { padding:.55rem 1.25rem; font-size:.85rem; font-weight:500; color:#64748b; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .status-filter a.active { color:#1e293b; border-bottom-color:#1e293b; font-weight:700; }
        .action-btns { display:flex; gap:.4rem; align-items:center; flex-wrap:nowrap; }
        @media print {
            body * { visibility:hidden; }
            #receiptPrintArea, #receiptPrintArea * { visibility:visible; }
            #receiptPrintArea { position:absolute; left:0; top:0; width:100%; padding:2rem; }
            #receiptPrintArea .btn, #receiptPrintArea .icon-btn { display:none !important; }
            .no-print { display:none !important; }
        }
        .modal-wide { width:min(700px, 100%); }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <aside class="sidebar" style="display:flex;flex-direction:column;">
        <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
            <span class="eyebrow">SIBA ERP</span>
            <div class="brand-copy">
                <h2>Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
        </div>
        <div class="nav-group">
            <div class="nav-title">Admissions</div>
            <a class="nav-link" href="application-intake.php">
                <span class="sidebar-icon">📋</span><span>Application Intake</span><span class="nav-tag">New</span>
            </a>
            <a class="nav-link" href="applications-list.php">
                <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag">List</span>
            </a>
            <a class="nav-link" href="parents-list.php">
                <span class="sidebar-icon">👤</span><span>Parents</span>
            </a>
            <a class="nav-link" href="events-manager.php">
                <span class="sidebar-icon">📅</span><span>Events & News</span>
            </a>
            <a class="nav-link" href="gallery-manager.php">
                <span class="sidebar-icon">🖼</span><span>Gallery</span>
            </a>
            <a class="nav-link" href="enquiries.php">
                <span class="sidebar-icon">📩</span><span>Enquiries</span>
            </a>
        </div>

        <div class="nav-group">
            <div class="nav-title">Finance</div>
            <a class="nav-link" href="finance-dashboard.php"><span class="sidebar-icon">📊</span><span>Finance Dashboard</span></a>
            <a class="nav-link" href="fee-structures.php"><span class="sidebar-icon">🏗</span><span>Fee Structures</span></a>
            <a class="nav-link" href="fee-collection.php"><span class="sidebar-icon">💰</span><span>Fee Collection</span></a>
            <a class="nav-link active" href="receipts-list.php"><span class="sidebar-icon">🧾</span><span>Receipts</span></a>
            <a class="nav-link" href="expenses.php"><span class="sidebar-icon">📤</span><span>Expenses</span></a>
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
                    <h1>Receipts</h1>
                    <p>View, print, and manage fee collection receipts.</p>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="status-filter">
            <a href="?status=Active" class="<?= $filterStatus === 'Active' ? 'active' : '' ?>">Active</a>
            <a href="?status=Cancelled" class="<?= $filterStatus === 'Cancelled' ? 'active' : '' ?>">Cancelled</a>
            <a href="?status=Void" class="<?= $filterStatus === 'Void' ? 'active' : '' ?>">Void</a>
            <a href="?status=" class="<?= $filterStatus === '' ? 'active' : '' ?>">All</a>
        </div>

        <form method="get" class="app-filters">
            <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
            <div><label>Search</label><input type="text" name="q" placeholder="Receipt no, student..." value="<?= e($searchQ) ?>" style="min-width:180px;"></div>
            <div><label>Payment Mode</label>
                <select name="mode">
                    <option value="">All Modes</option>
                    <option value="Cash" <?= $filterMode === 'Cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="Cheque" <?= $filterMode === 'Cheque' ? 'selected' : '' ?>>Cheque</option>
                    <option value="UPI" <?= $filterMode === 'UPI' ? 'selected' : '' ?>>UPI</option>
                    <option value="Card" <?= $filterMode === 'Card' ? 'selected' : '' ?>>Card</option>
                    <option value="Bank Transfer" <?= $filterMode === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                </select>
            </div>
            <div><label>From</label><input type="date" name="from" value="<?= e($filterFrom) ?>"></div>
            <div><label>To</label><input type="date" name="to" value="<?= e($filterTo) ?>"></div>
            <button type="submit" class="btn btn-sm">Search</button>
            <a href="receipts-list.php<?= $filterStatus !== '' ? '?status=' . urlencode($filterStatus) : '' ?>" class="btn btn-sm btn-soft">Clear</a>
            <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $total ?> receipt<?= $total !== 1 ? 's' : '' ?></span>
        </form>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Receipt No</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Amount</th>
                        <th>Payment Mode</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($receipts)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8;">No receipts found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($receipts as $r): ?>
                            <tr>
                                <td style="font-family:monospace;font-weight:600;"><?= e($r['receipt_no'] ?? '—') ?></td>
                                <td><strong><?= e((string) ($r['student_display'] ?? '—')) ?></strong></td>
                                <td><?= e($r['class_name'] ?? '—') ?></td>
                                <td>Rs. <?= number_format((float) ($r['net_amount'] ?? 0), 2) ?></td>
                                <td><span class="badge"><?= e($r['payment_mode'] ?? '—') ?></span></td>
                                <td style="white-space:nowrap;"><?= e($r['payment_date'] ?? '—') ?></td>
                                <td>
                                    <?php if (($r['status'] ?? 'Active') === 'Active'): ?>
                                        <span class="badge-active">Active</span>
                                    <?php elseif (($r['status'] ?? '') === 'Cancelled'): ?>
                                        <span class="badge-cancelled">Cancelled</span>
                                    <?php else: ?>
                                        <span class="badge-void"><?= e($r['status'] ?? '') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <a href="?view=<?= (int) $r['id'] ?>&status=<?= urlencode($filterStatus) ?>" class="btn btn-sm btn-soft" style="font-size:.75rem;">View</a>
                                        <a href="receipt-print.php?id=<?= (int) $r['id'] ?>" target="_blank" class="btn btn-sm btn-soft" style="font-size:.75rem;">Print</a>
                                        <?php if (($r['status'] ?? 'Active') === 'Active'): ?>
                                            <button class="btn btn-sm btn-danger" style="font-size:.75rem;background:#dc2626;color:#fff;border:none;" onclick="openCancelModal(<?= (int) $r['id'] ?>, '<?= e($r['receipt_no'] ?? '') ?>')">Cancel</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page - 1])) ?>">‹ Prev</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['p' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page + 1])) ?>">Next ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- ── View Receipt Modal ── -->
<div id="viewReceiptModal" class="modal-backdrop <?= $viewReceipt ? 'show' : '' ?>">
    <div class="modal modal-wide">
        <div class="modal-head">
            <h2>Receipt</h2>
            <button class="icon-btn" onclick="closeModal('viewReceiptModal')">✕</button>
        </div>
        <?php if ($viewReceipt): ?>
        <div id="receiptPrintArea">
            <div class="receipt-header">
                <h2>SIBA Public School</h2>
                <p>Fee Payment Receipt</p>
            </div>

            <div class="receipt-details">
                <div><strong>Receipt No:</strong> <?= e($viewReceipt['receipt_no'] ?? '—') ?></div>
                <div><strong>Date:</strong> <?= e($viewReceipt['payment_date'] ?? '—') ?></div>
                <div><strong>Student:</strong> <?= e((string) ($viewReceipt['student_display'] ?? '—')) ?></div>
                <div><strong>Class:</strong> <?= e((string) ($viewReceipt['class_name'] ?? '—')) ?></div>
                <div><strong>Academic Session:</strong> <?= e((string) ($viewReceipt['academic_session'] ?? '—')) ?></div>
                <div><strong>Payment Mode:</strong> <?= e($viewReceipt['payment_mode'] ?? '—') ?></div>
            </div>

            <?php if (!empty($viewItems)): ?>
            <table class="receipt-items">
                <thead>
                    <tr>
                        <th style="width:60%;">Fee Head</th>
                        <th style="width:40%;text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewItems as $item): ?>
                        <tr>
                            <td><?= e($item['fee_head_name'] ?? $item['fee_head_name'] ?? 'Fee Item') ?></td>
                            <td style="text-align:right;">Rs. <?= number_format((float) $item['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div class="receipt-summary">
                <div>Total: <strong>Rs. <?= number_format((float) ($viewReceipt['total_amount'] ?? 0), 2) ?></strong></div>
                <?php if ((float) ($viewReceipt['discount_amount'] ?? 0) > 0): ?>
                    <div>Discount: <strong style="color:#059669;">- Rs. <?= number_format((float) $viewReceipt['discount_amount'], 2) ?></strong></div>
                <?php endif; ?>
                <?php if ((float) ($viewReceipt['late_fee'] ?? 0) > 0): ?>
                    <div>Late Fee: <strong style="color:#dc2626;">+ Rs. <?= number_format((float) $viewReceipt['late_fee'], 2) ?></strong></div>
                <?php endif; ?>
                <div class="net">Net Amount: Rs. <?= number_format((float) ($viewReceipt['net_amount'] ?? 0), 2) ?></div>
            </div>

            <?php if ($viewReceipt['payment_mode'] === 'Cheque' && !empty($viewReceipt['cheque_no'])): ?>
                <div style="margin-top:.75rem;font-size:.85rem;color:#64748b;">
                    <strong>Cheque Details:</strong> No: <?= e($viewReceipt['cheque_no'] ?? '') ?>,
                    Date: <?= e($viewReceipt['cheque_date'] ?? '') ?>,
                    Bank: <?= e($viewReceipt['cheque_bank'] ?? '') ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($viewReceipt['transaction_ref'])): ?>
                <div style="margin-top:.4rem;font-size:.85rem;color:#64748b;">
                    <strong>Transaction Ref:</strong> <?= e($viewReceipt['transaction_ref']) ?>
                </div>
            <?php endif; ?>

            <?php if (($viewReceipt['status'] ?? 'Active') === 'Cancelled'): ?>
                <div class="cancelled-stamp">CANCELLED</div>
                <?php if (!empty($viewReceipt['cancel_reason'])): ?>
                    <div style="margin-top:.5rem;font-size:.85rem;color:#991b1b;"><strong>Reason:</strong> <?= e($viewReceipt['cancel_reason']) ?></div>
                <?php endif; ?>
                <div style="font-size:.82rem;color:#64748b;margin-top:.3rem;">
                    Cancelled on: <?= $viewReceipt['cancelled_at'] ? date('d-M-Y H:i', strtotime($viewReceipt['cancelled_at'])) : '—' ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="toolbar" style="margin-top:1rem;border-top:1px solid #e2e8f0;padding-top:1rem;" class="no-print">
            <button class="btn btn-sm btn-soft" onclick="window.print()">🖨 Print</button>
            <button class="btn btn-sm btn-soft" onclick="closeModal('viewReceiptModal')">Close</button>
        </div>
        <?php else: ?>
            <p style="text-align:center;padding:2rem;color:#94a3b8;">Receipt not found.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ── Cancel Receipt Modal ── -->
<div id="cancelReceiptModal" class="modal-backdrop">
    <div class="modal" style="max-width:500px;">
        <div class="modal-head">
            <h2>Cancel Receipt</h2>
            <button class="icon-btn" onclick="closeModal('cancelReceiptModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel_receipt">
            <input type="hidden" name="receipt_id" id="cancel_receipt_id">
            <p style="margin-bottom:1rem;">Are you sure you want to cancel receipt <strong id="cancel_receipt_no"></strong>?</p>
            <div>
                <label for="cancel_reason">Reason (optional)</label>
                <textarea name="cancel_reason" id="cancel_reason" rows="3" placeholder="Reason for cancellation..."></textarea>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm btn-danger" style="background:#dc2626;color:#fff;border:none;">Cancel Receipt</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('cancelReceiptModal')">Close</button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
function openModal(id) {
    document.getElementById(id).classList.add('show');
}
function openCancelModal(id, receiptNo) {
    document.getElementById('cancel_receipt_id').value = id;
    document.getElementById('cancel_receipt_no').textContent = receiptNo;
    openModal('cancelReceiptModal');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
});
</script>
<script src="../assets/erp.js"></script>
</body>
</html>
