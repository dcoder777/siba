<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();


$error = '';
$success = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    bill_no VARCHAR(100) NOT NULL,
    bill_date DATE NOT NULL,
    bill_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    description TEXT,
    expense_id INT DEFAULT NULL,
    status ENUM('Unpaid','Partial','Paid','Cancelled') NOT NULL DEFAULT 'Unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_no VARCHAR(50) NOT NULL,
    vendor_id INT NOT NULL,
    vendor_bill_id INT DEFAULT NULL,
    bill_no VARCHAR(100) DEFAULT NULL,
    bill_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tds_deducted DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_mode VARCHAR(50),
    payment_date DATE NOT NULL,
    transaction_id VARCHAR(150) DEFAULT NULL,
    cheque_no VARCHAR(50) DEFAULT NULL,
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_payment_no (payment_no)
)");

try { $pdo->exec("ALTER TABLE vendor_bills ADD INDEX idx_vb_vendor (vendor_id)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE vendor_bills ADD INDEX idx_vb_status (status)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE vendor_bills ADD INDEX idx_vb_bill_date (bill_date)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE vendor_payments ADD INDEX idx_vp_vendor (vendor_id)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE vendor_payments ADD INDEX idx_vp_bill (vendor_bill_id)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE vendor_payments ADD INDEX idx_vp_date (payment_date)"); } catch (\Throwable $e) {}

function vendor_options(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, vendor_code, name FROM vendors WHERE is_active = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generate_payment_no(PDO $pdo): string
{
    $year = date('Y');
    $prefix = 'VPAY-' . $year . '-';
    $stmt = $pdo->prepare("SELECT payment_no FROM vendor_payments WHERE payment_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    if ($last) {
        $seq = (int) substr($last, -4) + 1;
    } else {
        $seq = 1;
    }
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function vendor_bills_for_payment(PDO $pdo, int $vendorId): array
{
    $stmt = $pdo->prepare("SELECT id, bill_no, bill_amount, paid_amount, balance FROM vendor_bills WHERE vendor_id = ? AND status IN ('Unpaid','Partial') ORDER BY bill_date ASC");
    $stmt->execute([$vendorId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_bill') {
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $billNo = trim((string) ($_POST['bill_no'] ?? ''));
        $billDate = trim((string) ($_POST['bill_date'] ?? ''));
        $billAmount = (float) ($_POST['bill_amount'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($vendorId < 1) {
            $error = 'Please select a vendor.';
        } elseif ($billNo === '') {
            $error = 'Bill number is required.';
        } elseif ($billDate === '') {
            $error = 'Bill date is required.';
        } elseif ($billAmount <= 0) {
            $error = 'Bill amount must be greater than zero.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO vendor_bills (vendor_id, bill_no, bill_date, bill_amount, paid_amount, balance, description, status) VALUES (?,?,?,?,'0',?,?,?)");
            $stmt->execute([$vendorId, $billNo, $billDate, $billAmount, $billAmount, $description ?: null, 'Unpaid']);
            header('Location: vendor-bills.php?tab=1&msg=bill_created');
            exit;
        }
    }

    if ($action === 'record_payment') {
        $vendorId = (int) ($_POST['vendor_id'] ?? 0);
        $vendorBillId = (int) ($_POST['vendor_bill_id'] ?? 0);
        $paymentMode = trim((string) ($_POST['payment_mode'] ?? ''));
        $paidAmount = (float) ($_POST['paid_amount'] ?? 0);
        $tdsDeducted = (float) ($_POST['tds_deducted'] ?? 0);
        $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
        $chequeNo = trim((string) ($_POST['cheque_no'] ?? ''));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($vendorId < 1) {
            $error = 'Please select a vendor.';
        } elseif ($vendorBillId < 1) {
            $error = 'Please select a bill.';
        } elseif ($paymentDate === '') {
            $error = 'Payment date is required.';
        } elseif ($paidAmount <= 0) {
            $error = 'Paid amount must be greater than zero.';
        } else {
            $billStmt = $pdo->prepare("SELECT id, bill_no, bill_amount, paid_amount, balance FROM vendor_bills WHERE id = ? AND vendor_id = ?");
            $billStmt->execute([$vendorBillId, $vendorId]);
            $bill = $billStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bill) {
                $error = 'Bill not found.';
            } elseif ((float) $bill['balance'] <= 0) {
                $error = 'This bill is already fully paid.';
            } elseif ($paidAmount > (float) $bill['balance']) {
                $error = 'Paid amount cannot exceed the remaining balance of Rs. ' . number_format((float) $bill['balance'], 2) . '.';
            } elseif ($paymentMode === '') {
                $error = 'Payment mode is required.';
            } else {
                $netPaid = $paidAmount - $tdsDeducted;
                if ($netPaid < 0) {
                    $error = 'TDS cannot exceed the paid amount.';
                } else {
                    $paymentNo = generate_payment_no($pdo);
                    $newPaid = (float) $bill['paid_amount'] + $paidAmount;
                    $newBalance = (float) $bill['bill_amount'] - $newPaid;
                    if ($newBalance <= 0) {
                        $newStatus = 'Paid';
                        $newBalance = 0;
                    } else {
                        $newStatus = 'Partial';
                    }

                    $pdo->beginTransaction();
                    try {
                        $ins = $pdo->prepare("INSERT INTO vendor_payments (payment_no, vendor_id, vendor_bill_id, bill_no, bill_amount, paid_amount, tds_deducted, net_paid, payment_mode, payment_date, transaction_id, cheque_no, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $ins->execute([$paymentNo, $vendorId, $vendorBillId, $bill['bill_no'], $bill['bill_amount'], $paidAmount, $tdsDeducted, $netPaid, $paymentMode, $paymentDate, $transactionId ?: null, $chequeNo ?: null, $notes ?: null, (int) ($user['id'] ?? 0)]);

                        $upd = $pdo->prepare("UPDATE vendor_bills SET paid_amount = ?, balance = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        $upd->execute([$newPaid, $newBalance, $newStatus, $vendorBillId]);

                        $pdo->commit();
                        header('Location: vendor-bills.php?tab=2&msg=payment_recorded');
                        exit;
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'Failed to record payment: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    if ($action === 'cancel_bill' && isset($_POST['id'])) {
        $billId = (int) $_POST['id'];
        $stmt = $pdo->prepare("UPDATE vendor_bills SET status = 'Cancelled', updated_at = NOW() WHERE id = ? AND status IN ('Unpaid','Partial')");
        $stmt->execute([$billId]);
        if ($stmt->rowCount() > 0) {
            header('Location: vendor-bills.php?tab=1&msg=bill_cancelled');
            exit;
        } else {
            $error = 'Bill could not be cancelled (already paid or not found).';
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'api_bills' && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $apiVendorId = (int) ($_GET['vendor_id'] ?? 0);
    header('Content-Type: application/json');
    if ($apiVendorId > 0) {
        $bills = vendor_bills_for_payment($pdo, $apiVendorId);
        echo json_encode($bills);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['msg'])) {
    $msg = (string) $_GET['msg'];
    $msgMap = [
        'bill_created' => 'Vendor bill added successfully.',
        'payment_recorded' => 'Payment recorded successfully.',
        'bill_cancelled' => 'Vendor bill cancelled.',
    ];
    $success = $msgMap[$msg] ?? '';
}

$activeTab = max(1, min(2, (int) ($_GET['tab'] ?? 1)));
$allVendors = vendor_options($pdo);

$bVendorFilter = (int) ($_GET['b_vendor'] ?? 0);
$bStatusFilter = trim((string) ($_GET['b_status'] ?? 'all'));
$bSearch = trim((string) ($_GET['b_search'] ?? ''));
$bPage = max(1, (int) ($_GET['b_page'] ?? 1));
$bPerPage = 25;
$bOffset = ($bPage - 1) * $bPerPage;

$bWhere = [];
$bParams = [];

if ($bVendorFilter > 0) {
    $bWhere[] = 'vb.vendor_id = :b_vendor';
    $bParams[':b_vendor'] = $bVendorFilter;
}

$allowedBillStatuses = ['all', 'Unpaid', 'Partial', 'Paid', 'Cancelled'];
if (!in_array($bStatusFilter, $allowedBillStatuses, true)) $bStatusFilter = 'all';
if ($bStatusFilter !== 'all') {
    $bWhere[] = 'vb.status = :b_status';
    $bParams[':b_status'] = $bStatusFilter;
}

if ($bSearch !== '') {
    $bWhere[] = '(vb.bill_no LIKE :b_search OR v.name LIKE :b_search2 OR vb.description LIKE :b_search3)';
    $bParams[':b_search'] = "%$bSearch%";
    $bParams[':b_search2'] = "%$bSearch%";
    $bParams[':b_search3'] = "%$bSearch%";
}

$bWhereSql = count($bWhere) > 0 ? 'WHERE ' . implode(' AND ', $bWhere) : '';

$bCountStmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_bills vb LEFT JOIN vendors v ON v.id = vb.vendor_id $bWhereSql");
$bCountStmt->execute($bParams);
$bTotalRecords = (int) $bCountStmt->fetchColumn();
$bTotalPages = max(1, (int) ceil($bTotalRecords / $bPerPage));

$bStmt = $pdo->prepare("SELECT vb.*, v.name AS vendor_name, v.vendor_code FROM vendor_bills vb LEFT JOIN vendors v ON v.id = vb.vendor_id $bWhereSql ORDER BY vb.bill_date DESC, vb.id DESC LIMIT $bPerPage OFFSET $bOffset");
$bStmt->execute($bParams);
$bRows = $bStmt->fetchAll(PDO::FETCH_ASSOC);

$bCounts = [
    'all' => (int) $pdo->query("SELECT COUNT(*) FROM vendor_bills")->fetchColumn(),
    'Unpaid' => (int) $pdo->query("SELECT COUNT(*) FROM vendor_bills WHERE status='Unpaid'")->fetchColumn(),
    'Partial' => (int) $pdo->query("SELECT COUNT(*) FROM vendor_bills WHERE status='Partial'")->fetchColumn(),
    'Paid' => (int) $pdo->query("SELECT COUNT(*) FROM vendor_bills WHERE status='Paid'")->fetchColumn(),
    'Cancelled' => (int) $pdo->query("SELECT COUNT(*) FROM vendor_bills WHERE status='Cancelled'")->fetchColumn(),
];

$pVendorFilter = (int) ($_GET['p_vendor'] ?? 0);
$pDateFrom = trim((string) ($_GET['p_date_from'] ?? ''));
$pDateTo = trim((string) ($_GET['p_date_to'] ?? ''));
$pSearch = trim((string) ($_GET['p_search'] ?? ''));
$pPage = max(1, (int) ($_GET['p_page'] ?? 1));
$pPerPage = 25;
$pOffset = ($pPage - 1) * $pPerPage;

$pWhere = [];
$pParams = [];

if ($pVendorFilter > 0) {
    $pWhere[] = 'vp.vendor_id = :p_vendor';
    $pParams[':p_vendor'] = $pVendorFilter;
}

if ($pDateFrom !== '') {
    $pWhere[] = 'vp.payment_date >= :p_date_from';
    $pParams[':p_date_from'] = $pDateFrom;
}

if ($pDateTo !== '') {
    $pWhere[] = 'vp.payment_date <= :p_date_to';
    $pParams[':p_date_to'] = $pDateTo;
}

if ($pSearch !== '') {
    $pWhere[] = '(vp.payment_no LIKE :p_search OR v.name LIKE :p_search2 OR vp.bill_no LIKE :p_search3 OR vp.transaction_id LIKE :p_search4)';
    $pParams[':p_search'] = "%$pSearch%";
    $pParams[':p_search2'] = "%$pSearch%";
    $pParams[':p_search3'] = "%$pSearch%";
    $pParams[':p_search4'] = "%$pSearch%";
}

$pWhereSql = count($pWhere) > 0 ? 'WHERE ' . implode(' AND ', $pWhere) : '';

$pCountStmt = $pdo->prepare("SELECT COUNT(*) FROM vendor_payments vp LEFT JOIN vendors v ON v.id = vp.vendor_id $pWhereSql");
$pCountStmt->execute($pParams);
$pTotalRecords = (int) $pCountStmt->fetchColumn();
$pTotalPages = max(1, (int) ceil($pTotalRecords / $pPerPage));

$pStmt = $pdo->prepare("SELECT vp.*, v.name AS vendor_name, v.vendor_code FROM vendor_payments vp LEFT JOIN vendors v ON v.id = vp.vendor_id $pWhereSql ORDER BY vp.payment_date DESC, vp.id DESC LIMIT $pPerPage OFFSET $pOffset");
$pStmt->execute($pParams);
$pRows = $pStmt->fetchAll(PDO::FETCH_ASSOC);

$totalBilled = (float) $pdo->query("SELECT COALESCE(SUM(bill_amount),0) FROM vendor_bills WHERE status != 'Cancelled'")->fetchColumn();
$totalPaid = (float) $pdo->query("SELECT COALESCE(SUM(bill_amount - balance),0) FROM vendor_bills WHERE status != 'Cancelled'")->fetchColumn();
$totalBalance = (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM vendor_bills WHERE status IN ('Unpaid','Partial')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Vendor Bills &amp; Payments – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .tab-bar a { padding:.6rem 1.5rem; font-size:.9rem; font-weight:500; color:#64748b; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s, border-color .15s; }
        .tab-bar a.active { color:#1e293b; border-bottom-color:#1e293b; font-weight:700; }
        .tab-bar a:hover { color:#1e293b; }
        .stat-bar { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
        .stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.8rem 1.2rem; flex:1; min-width:160px; }
        .stat-card .stat-label { font-size:.75rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
        .stat-card .stat-value { font-size:1.3rem; font-weight:700; color:#0f172a; margin-top:.2rem; }
        .filter-row { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
        .filter-row label { font-size:.8rem; margin-bottom:.2rem; }
        .filter-row input, .filter-row select { min-height:38px; padding:.45rem .7rem; border-radius:8px; font-size:.85rem; width:auto; }
        .filter-row .btn { min-height:38px; padding:.45rem 1rem; font-size:.85rem; }
        .filter-group { display:flex; flex-direction:column; }
        .badge-unpaid { background:#fee2e2; color:#991b1b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-partial { background:#fef3c7; color:#92400e; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-paid { background:#d1fae5; color:#065f46; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-cancelled { background:#f1f5f9; color:#64748b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .action-btns { display:flex; gap:.4rem; align-items:center; flex-wrap:wrap; }
        .page-links { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
        .page-links a, .page-links span { min-height:34px; padding:.38rem .65rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; text-decoration:none; font-size:.82rem; }
        .page-links a:hover { background:#f1f5f9; }
        .page-links .active { background:#64748b; border-color:#64748b; color:#fff; }
        .amount-balance { color:#dc2626; font-weight:700; }
        .amount-zero { color:#059669; font-weight:700; }
        .mini-form { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; }
        .mini-form .field-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:1rem; }
        .mini-form label { font-size:.8rem; }
        .mini-form input, .mini-form select { min-height:40px; padding:.5rem .7rem; font-size:.85rem; }
        .btn-sm { min-height:36px; padding:.4rem .85rem; font-size:.82rem; border-radius:8px; }
        .modal-backdrop { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
        .modal-backdrop.show { display:flex; }
        .modal { background:#fff; border-radius:16px; width:90%; max-width:700px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 60px rgba(0,0,0,.2); }
        .modal-head { display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem; border-bottom:1px solid #e2e8f0; }
        .modal-head h2 { margin:0; font-size:1.1rem; }
        .modal-body { padding:1.5rem; }
        .icon-btn { background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b; padding:.25rem; }
        .icon-btn:hover { color:#1e293b; }
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
                    <h1>Vendor Bills &amp; Payments</h1>
                    <p>Manage vendor bills and track payments.</p>
                </div>
                <div class="toolbar-right">
                    <?php if ($activeTab === 1): ?>
                        <button class="btn btn-sm" onclick="openModal('addBillModal')" style="background:#059669;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;">+ Add Bill</button>
                    <?php else: ?>
                        <button class="btn btn-sm" onclick="openModal('recordPaymentModal')" style="background:#059669;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;">+ Record Payment</button>
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

        <div class="stat-bar">
            <div class="stat-card">
                <div class="stat-label">Total Billed</div>
                <div class="stat-value">Rs. <?= number_format($totalBilled, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Paid</div>
                <div class="stat-value" style="color:#059669;">Rs. <?= number_format($totalPaid, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-value" style="color:#dc2626;">Rs. <?= number_format($totalBalance, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Bills</div>
                <div class="stat-value"><?= $bCounts['all'] ?></div>
            </div>
        </div>

        <div class="tab-bar">
            <a href="?tab=1" class="<?= $activeTab === 1 ? 'active' : '' ?>">Vendor Bills</a>
            <a href="?tab=2" class="<?= $activeTab === 2 ? 'active' : '' ?>">Vendor Payments</a>
        </div>

        <!-- ═══════════ TAB 1: VENDOR BILLS ═══════════ -->
        <?php if ($activeTab === 1): ?>

            <div class="filter-row">
                <form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;width:100%;">
                    <input type="hidden" name="tab" value="1">
                    <div class="filter-group">
                        <label for="b_vendor">Vendor</label>
                        <select name="b_vendor" id="b_vendor" style="min-width:160px;">
                            <option value="">All Vendors</option>
                            <?php foreach ($allVendors as $v): ?>
                                <option value="<?= (int) $v['id'] ?>" <?= $bVendorFilter === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="b_status">Status</label>
                        <select name="b_status" id="b_status" style="min-width:120px;">
                            <option value="all" <?= $bStatusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <?php foreach (['Unpaid','Partial','Paid','Cancelled'] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $bStatusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="b_search">Search</label>
                        <input type="text" name="b_search" id="b_search" placeholder="Bill no, vendor..." value="<?= e($bSearch) ?>" style="min-width:180px;">
                    </div>
                    <div class="filter-group" style="align-self:flex-end;">
                        <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:38px;font-size:.85rem;border-radius:8px;">Filter</button>
                        <a href="?tab=1" style="font-size:.85rem;color:#64748b;margin-left:.5rem;">Clear</a>
                    </div>
                </form>
            </div>

            <div class="tab-bar" style="margin-top:-.5rem;margin-bottom:1rem;border-bottom-color:#e5e7eb;">
                <a href="?tab=1&b_status=all" class="<?= $bStatusFilter === 'all' ? 'active' : '' ?>" style="font-size:.82rem;padding:.45rem 1rem;">All (<?= $bCounts['all'] ?>)</a>
                <a href="?tab=1&b_status=Unpaid" class="<?= $bStatusFilter === 'Unpaid' ? 'active' : '' ?>" style="font-size:.82rem;padding:.45rem 1rem;">Unpaid (<?= $bCounts['Unpaid'] ?>)</a>
                <a href="?tab=1&b_status=Partial" class="<?= $bStatusFilter === 'Partial' ? 'active' : '' ?>" style="font-size:.82rem;padding:.45rem 1rem;">Partial (<?= $bCounts['Partial'] ?>)</a>
                <a href="?tab=1&b_status=Paid" class="<?= $bStatusFilter === 'Paid' ? 'active' : '' ?>" style="font-size:.82rem;padding:.45rem 1rem;">Paid (<?= $bCounts['Paid'] ?>)</a>
                <a href="?tab=1&b_status=Cancelled" class="<?= $bStatusFilter === 'Cancelled' ? 'active' : '' ?>" style="font-size:.82rem;padding:.45rem 1rem;">Cancelled (<?= $bCounts['Cancelled'] ?>)</a>
            </div>

            <section class="panel" style="padding:1.25rem;">
                <?php if (empty($bRows)): ?>
                    <p style="text-align:center;padding:2rem;color:#94a3b8;">No vendor bills found.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Vendor Name</th>
                                    <th>Bill No</th>
                                    <th>Bill Date</th>
                                    <th>Bill Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = $bOffset + 1; foreach ($bRows as $r): ?>
                                    <tr>
                                        <td style="color:#94a3b8;"><?= $i++ ?></td>
                                        <td><strong><?= e($r['vendor_name'] ?? '—') ?></strong></td>
                                        <td style="font-family:monospace;"><?= e($r['bill_no']) ?></td>
                                        <td style="white-space:nowrap;"><?= e($r['bill_date']) ?></td>
                                        <td>Rs. <?= number_format((float) $r['bill_amount'], 2) ?></td>
                                        <td>Rs. <?= number_format((float) $r['paid_amount'], 2) ?></td>
                                        <td>
                                            <?php if ((float) $r['balance'] > 0): ?>
                                                <span class="amount-balance">Rs. <?= number_format((float) $r['balance'], 2) ?></span>
                                            <?php else: ?>
                                                <span class="amount-zero">Rs. <?= number_format((float) $r['balance'], 2) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($r['status'] === 'Unpaid'): ?>
                                                <span class="badge-unpaid">Unpaid</span>
                                            <?php elseif ($r['status'] === 'Partial'): ?>
                                                <span class="badge-partial">Partial</span>
                                            <?php elseif ($r['status'] === 'Paid'): ?>
                                                <span class="badge-paid">Paid</span>
                                            <?php else: ?>
                                                <span class="badge-cancelled">Cancelled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <?php if (in_array($r['status'], ['Unpaid','Partial'], true) && (float) $r['balance'] > 0): ?>
                                                    <button class="btn btn-sm" onclick="openPaymentForBill(<?= (int) $r['vendor_id'] ?>, <?= (int) $r['id'] ?>, '<?= e($r['bill_no']) ?>', <?= (float) $r['bill_amount'] ?>, <?= (float) $r['balance'] ?>)" style="background:#2563eb;color:#fff;padding:.25rem .6rem;font-size:.75rem;border-radius:6px;">Add Payment</button>
                                                <?php endif; ?>
                                                <?php if (in_array($r['status'], ['Unpaid','Partial'], true)): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this bill?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="cancel_bill">
                                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:.25rem .6rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Cancel</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($bTotalPages > 1): ?>
                        <div class="pagination" style="margin-top:1rem;">
                            <div style="font-size:.85rem;color:#64748b;">Showing <?= $bOffset + 1 ?>–<?= min($bOffset + $bPerPage, $bTotalRecords) ?> of <?= $bTotalRecords ?></div>
                            <div class="page-links">
                                <?php if ($bPage > 1): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 1, 'b_page' => $bPage - 1])) ?>">← Prev</a>
                                <?php endif; ?>
                                <?php
                                $bStartPage = max(1, $bPage - 2);
                                $bEndPage = min($bTotalPages, $bPage + 2);
                                for ($p = $bStartPage; $p <= $bEndPage; $p++):
                                ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 1, 'b_page' => $p])) ?>" class="<?= $p === $bPage ? 'active' : '' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <?php if ($bPage < $bTotalPages): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 1, 'b_page' => $bPage + 1])) ?>">Next →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- ═══════════ TAB 2: VENDOR PAYMENTS ═══════════ -->
        <?php if ($activeTab === 2): ?>

            <div class="filter-row">
                <form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;width:100%;">
                    <input type="hidden" name="tab" value="2">
                    <div class="filter-group">
                        <label for="p_vendor">Vendor</label>
                        <select name="p_vendor" id="p_vendor" style="min-width:160px;">
                            <option value="">All Vendors</option>
                            <?php foreach ($allVendors as $v): ?>
                                <option value="<?= (int) $v['id'] ?>" <?= $pVendorFilter === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="p_date_from">From Date</label>
                        <input type="date" name="p_date_from" id="p_date_from" value="<?= e($pDateFrom) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="p_date_to">To Date</label>
                        <input type="date" name="p_date_to" id="p_date_to" value="<?= e($pDateTo) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="p_search">Search</label>
                        <input type="text" name="p_search" id="p_search" placeholder="Payment no, vendor..." value="<?= e($pSearch) ?>" style="min-width:180px;">
                    </div>
                    <div class="filter-group" style="align-self:flex-end;">
                        <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:38px;font-size:.85rem;border-radius:8px;">Filter</button>
                        <a href="?tab=2" style="font-size:.85rem;color:#64748b;margin-left:.5rem;">Clear</a>
                    </div>
                </form>
            </div>

            <section class="panel" style="padding:1.25rem;">
                <?php if (empty($pRows)): ?>
                    <p style="text-align:center;padding:2rem;color:#94a3b8;">No vendor payments found.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Payment No</th>
                                    <th>Vendor</th>
                                    <th>Bill No</th>
                                    <th>Bill Amount</th>
                                    <th>Paid</th>
                                    <th>TDS</th>
                                    <th>Net Paid</th>
                                    <th>Mode</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = $pOffset + 1; foreach ($pRows as $r): ?>
                                    <tr>
                                        <td style="color:#94a3b8;"><?= $i++ ?></td>
                                        <td style="font-family:monospace;"><?= e($r['payment_no']) ?></td>
                                        <td><strong><?= e($r['vendor_name'] ?? '—') ?></strong></td>
                                        <td style="font-family:monospace;"><?= e($r['bill_no'] ?? '—') ?></td>
                                        <td>Rs. <?= number_format((float) $r['bill_amount'], 2) ?></td>
                                        <td>Rs. <?= number_format((float) $r['paid_amount'], 2) ?></td>
                                        <td>
                                            <?php if ((float) $r['tds_deducted'] > 0): ?>
                                                <span style="color:#92400e;">Rs. <?= number_format((float) $r['tds_deducted'], 2) ?></span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><strong>Rs. <?= number_format((float) $r['net_paid'], 2) ?></strong></td>
                                        <td><?= e($r['payment_mode'] ?? '—') ?></td>
                                        <td style="white-space:nowrap;"><?= e($r['payment_date']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($pTotalPages > 1): ?>
                        <div class="pagination" style="margin-top:1rem;">
                            <div style="font-size:.85rem;color:#64748b;">Showing <?= $pOffset + 1 ?>–<?= min($pOffset + $pPerPage, $pTotalRecords) ?> of <?= $pTotalRecords ?></div>
                            <div class="page-links">
                                <?php if ($pPage > 1): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 2, 'p_page' => $pPage - 1])) ?>">← Prev</a>
                                <?php endif; ?>
                                <?php
                                $pStartPage = max(1, $pPage - 2);
                                $pEndPage = min($pTotalPages, $pPage + 2);
                                for ($p = $pStartPage; $p <= $pEndPage; $p++):
                                ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 2, 'p_page' => $p])) ?>" class="<?= $p === $pPage ? 'active' : '' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <?php if ($pPage < $pTotalPages): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 2, 'p_page' => $pPage + 1])) ?>">Next →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>

<!-- ═══════════ MODAL: ADD BILL ═══════════ -->
<div id="addBillModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Add Vendor Bill</h2>
            <button class="icon-btn" onclick="closeModal('addBillModal')">✕</button>
        </div>
        <div class="modal-body">
            <form method="post">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_bill">
                <div class="mini-form" style="border:none;padding:0;background:none;">
                    <div class="field-row">
                        <div>
                            <label>Vendor *</label>
                            <select name="vendor_id" required>
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($allVendors as $v): ?>
                                    <option value="<?= (int) $v['id'] ?>"><?= e($v['vendor_code'] ? $v['vendor_code'] . ' – ' : '') ?><?= e($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Bill No *</label>
                            <input type="text" name="bill_no" required placeholder="e.g. BILL-001">
                        </div>
                        <div>
                            <label>Bill Date *</label>
                            <input type="date" name="bill_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Bill Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0.01" name="bill_amount" required>
                        </div>
                    </div>
                    <div class="field-row" style="margin-top:1rem;">
                        <div>
                            <label>Description</label>
                            <textarea name="description" rows="3" placeholder="Optional notes about this bill..."></textarea>
                        </div>
                    </div>
                </div>
                <div style="margin-top:1rem;display:flex;gap:.75rem;">
                    <button type="submit" class="btn btn-sm" style="background:#2563eb;">Save Bill</button>
                    <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('addBillModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════ MODAL: RECORD PAYMENT ═══════════ -->
<div id="recordPaymentModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Record Vendor Payment</h2>
            <button class="icon-btn" onclick="closeModal('recordPaymentModal')">✕</button>
        </div>
        <div class="modal-body">
            <form method="post">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="record_payment">
                <div class="mini-form" style="border:none;padding:0;background:none;">
                    <div class="field-row">
                        <div>
                            <label>Vendor *</label>
                            <select name="vendor_id" id="rp_vendor_id" required onchange="loadVendorBills()">
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($allVendors as $v): ?>
                                    <option value="<?= (int) $v['id'] ?>"><?= e($v['vendor_code'] ? $v['vendor_code'] . ' – ' : '') ?><?= e($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Bill *</label>
                            <select name="vendor_bill_id" id="rp_vendor_bill_id" required onchange="fillBillDetails()">
                                <option value="">-- Select Bill --</option>
                            </select>
                        </div>
                        <div>
                            <label>Bill No</label>
                            <input type="text" id="rp_bill_no" readonly style="background:#f1f5f9;">
                        </div>
                        <div>
                            <label>Bill Amount (Rs.)</label>
                            <input type="text" id="rp_bill_amount" readonly style="background:#f1f5f9;">
                        </div>
                        <div>
                            <label>Balance (Rs.)</label>
                            <input type="text" id="rp_bill_balance" readonly style="background:#f1f5f9;color:#dc2626;font-weight:700;">
                        </div>
                    </div>
                    <div class="field-row" style="margin-top:1rem;">
                        <div>
                            <label>Payment Mode *</label>
                            <select name="payment_mode" id="rp_payment_mode" required onchange="toggleTdsFields()">
                                <option value="">-- Select --</option>
                                <option value="Cash">Cash</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="NEFT/RTGS">NEFT/RTGS</option>
                                <option value="UPI">UPI</option>
                                <option value="Online">Online</option>
                            </select>
                        </div>
                        <div>
                            <label>Paid Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0.01" name="paid_amount" id="rp_paid_amount" required oninput="calcNetPaid()">
                        </div>
                        <div>
                            <label>TDS Deducted (Rs.)</label>
                            <input type="number" step="0.01" min="0" name="tds_deducted" id="rp_tds_deducted" value="0" oninput="calcNetPaid()">
                        </div>
                        <div>
                            <label>Net Paid (Rs.)</label>
                            <input type="text" id="rp_net_paid" readonly style="background:#f1f5f9;font-weight:700;">
                        </div>
                        <div>
                            <label>Payment Date *</label>
                            <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="field-row" style="margin-top:1rem;">
                        <div>
                            <label>Transaction ID</label>
                            <input type="text" name="transaction_id" id="rp_transaction_id" placeholder="NEFT/UTR/Ref No">
                        </div>
                        <div>
                            <label>Cheque No</label>
                            <input type="text" name="cheque_no" id="rp_cheque_no">
                        </div>
                    </div>
                    <div class="field-row" style="margin-top:1rem;">
                        <div>
                            <label>Notes</label>
                            <textarea name="notes" rows="2" placeholder="Optional payment notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div style="margin-top:1rem;display:flex;gap:.75rem;">
                    <button type="submit" class="btn btn-sm" style="background:#2563eb;">Save Payment</button>
                    <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('recordPaymentModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var vendorBillsData = {};

function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function openPaymentForBill(vendorId, billId, billNo, billAmount, balance) {
    openModal('recordPaymentModal');
    var vendorSel = document.getElementById('rp_vendor_id');
    vendorSel.value = String(vendorId);
    loadVendorBills(function() {
        document.getElementById('rp_vendor_bill_id').value = String(billId);
        fillBillDetails();
    });
}

function loadVendorBills(callback) {
    var vendorId = document.getElementById('rp_vendor_id').value;
    var billSel = document.getElementById('rp_vendor_bill_id');
    billSel.innerHTML = '<option value="">-- Select Bill --</option>';
    document.getElementById('rp_bill_no').value = '';
    document.getElementById('rp_bill_amount').value = '';
    document.getElementById('rp_bill_balance').value = '';
    document.getElementById('rp_paid_amount').value = '';
    document.getElementById('rp_tds_deducted').value = '0';
    document.getElementById('rp_net_paid').value = '';

    if (!vendorId || vendorId === '0') return;

    fetch('vendor-bills.php?action=api_bills&vendor_id=' + vendorId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(bills) {
        vendorBillsData = {};
        bills.forEach(function(b) {
            vendorBillsData[b.id] = b;
            var opt = document.createElement('option');
            opt.value = b.id;
            opt.text = b.bill_no + ' (Bal: Rs. ' + parseFloat(b.balance).toFixed(2) + ')';
            billSel.add(opt);
        });
        if (typeof callback === 'function') callback();
    })
    .catch(function() {});
}

function fillBillDetails() {
    var billId = document.getElementById('rp_vendor_bill_id').value;
    if (billId && vendorBillsData[billId]) {
        var b = vendorBillsData[billId];
        document.getElementById('rp_bill_no').value = b.bill_no;
        document.getElementById('rp_bill_amount').value = 'Rs. ' + parseFloat(b.bill_amount).toFixed(2);
        document.getElementById('rp_bill_balance').value = 'Rs. ' + parseFloat(b.balance).toFixed(2);
        document.getElementById('rp_paid_amount').max = b.balance;
        document.getElementById('rp_paid_amount').value = '';
        document.getElementById('rp_net_paid').value = '';
    } else {
        document.getElementById('rp_bill_no').value = '';
        document.getElementById('rp_bill_amount').value = '';
        document.getElementById('rp_bill_balance').value = '';
        document.getElementById('rp_paid_amount').value = '';
        document.getElementById('rp_net_paid').value = '';
    }
}

function calcNetPaid() {
    var paid = parseFloat(document.getElementById('rp_paid_amount').value) || 0;
    var tds = parseFloat(document.getElementById('rp_tds_deducted').value) || 0;
    var net = paid - tds;
    document.getElementById('rp_net_paid').value = net >= 0 ? 'Rs. ' + net.toFixed(2) : 'Rs. 0.00';
}

function toggleTdsFields() {
    var mode = document.getElementById('rp_payment_mode').value;
    var tdsInput = document.getElementById('rp_tds_deducted');
    var transIdInput = document.getElementById('rp_transaction_id');
    var chequeInput = document.getElementById('rp_cheque_no');

    if (mode === 'Cheque') {
        chequeInput.parentElement.style.display = 'block';
    } else {
        chequeInput.parentElement.style.display = 'none';
        chequeInput.value = '';
    }

    if (['Bank Transfer', 'NEFT/RTGS', 'Online', 'UPI'].indexOf(mode) !== -1) {
        transIdInput.parentElement.style.display = 'block';
        transIdInput.required = true;
    } else {
        transIdInput.parentElement.style.display = 'none';
        transIdInput.required = false;
        transIdInput.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
    toggleTdsFields();
});
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
