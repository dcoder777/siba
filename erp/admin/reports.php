<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$pageTitle = 'Reports';

$reportTypes = [
    1 => 'Daily Collection Report',
    2 => 'Monthly Collection Report',
    3 => 'Annual Collection Report',
    4 => 'Outstanding Fee Report',
    5 => 'Defaulter Report',
    6 => 'Expense Report',
    7 => 'Income Report',
    8 => 'Cash Book Report',
    9 => 'Bank Book Report',
];

$reportId = (int) ($_GET['report'] ?? 1);
if (!isset($reportTypes[$reportId])) $reportId = 1;
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$export = trim((string) ($_GET['export'] ?? ''));

// ── Helper: emit CSV ──
function csvEscape(string $s): string
{
    return '"' . str_replace('"', '""', $s) . '"';
}

// ── Fetch report data ──
$reportTitle = $reportTypes[$reportId];
$headers = [];
$rows = [];
$totals = [];
$reportError = '';

try {
switch ($reportId) {
    // ─── 1. Daily Collection ───
    case 1:
        $reportTitle = 'Daily Collection Report';
        $headers = ['Date', 'Receipts Count', 'Total Amount', 'Discount', 'Late Fee', 'Net Amount', 'Cash', 'Cheque', 'UPI', 'Card', 'Bank Transfer'];
        $dateWhere = $fromDate ? "AND payment_date >= :frm" : "AND payment_date = CURDATE()";
        $dateParams = $fromDate ? ['frm' => $fromDate] : [];
        if ($toDate && $fromDate) $dateWhere .= " AND payment_date <= :to";
        if ($toDate && $fromDate) $dateParams['to'] = $toDate;
        $sql = "SELECT payment_date,
                       COUNT(*) AS cnt,
                       COALESCE(SUM(total_amount),0) AS total,
                       COALESCE(SUM(discount_amount),0) AS discount,
                       COALESCE(SUM(late_fee),0) AS late_fee,
                       COALESCE(SUM(net_amount),0) AS net,
                       COALESCE(SUM(CASE WHEN payment_mode='Cash' THEN net_amount ELSE 0 END),0) AS cash_amt,
                       COALESCE(SUM(CASE WHEN payment_mode='Cheque' THEN net_amount ELSE 0 END),0) AS cheque_amt,
                       COALESCE(SUM(CASE WHEN payment_mode='UPI' THEN net_amount ELSE 0 END),0) AS upi_amt,
                       COALESCE(SUM(CASE WHEN payment_mode='Card' THEN net_amount ELSE 0 END),0) AS card_amt,
                       COALESCE(SUM(CASE WHEN payment_mode='Bank Transfer' THEN net_amount ELSE 0 END),0) AS bank_amt
                FROM fee_collections
                WHERE status = 'Active' $dateWhere
                GROUP BY payment_date
                ORDER BY payment_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dateParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'cnt' => array_sum(array_column($rows, 'cnt')),
            'total' => array_sum(array_column($rows, 'total')),
            'discount' => array_sum(array_column($rows, 'discount')),
            'late_fee' => array_sum(array_column($rows, 'late_fee')),
            'net' => array_sum(array_column($rows, 'net')),
            'cash_amt' => array_sum(array_column($rows, 'cash_amt')),
            'cheque_amt' => array_sum(array_column($rows, 'cheque_amt')),
            'upi_amt' => array_sum(array_column($rows, 'upi_amt')),
            'card_amt' => array_sum(array_column($rows, 'card_amt')),
            'bank_amt' => array_sum(array_column($rows, 'bank_amt')),
        ];
        break;

    // ─── 2. Monthly Collection ───
    case 2:
        $reportTitle = 'Monthly Collection Report';
        $headers = ['Date', 'Receipts', 'Total Amount', 'Discount', 'Late Fee', 'Net Amount'];
        $dateWhere = $fromDate ? "AND payment_date >= :frm" : "AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())";
        $dateParams = $fromDate ? ['frm' => $fromDate] : [];
        if ($toDate && $fromDate) $dateWhere .= " AND payment_date <= :to";
        if ($toDate && $fromDate) $dateParams['to'] = $toDate;
        $stmt = $pdo->prepare("SELECT payment_date, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(discount_amount),0) AS discount, COALESCE(SUM(late_fee),0) AS late_fee, COALESCE(SUM(net_amount),0) AS net FROM fee_collections WHERE status = 'Active' $dateWhere GROUP BY payment_date ORDER BY payment_date DESC");
        $stmt->execute($dateParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'cnt' => array_sum(array_column($rows, 'cnt')),
            'total' => array_sum(array_column($rows, 'total')),
            'discount' => array_sum(array_column($rows, 'discount')),
            'late_fee' => array_sum(array_column($rows, 'late_fee')),
            'net' => array_sum(array_column($rows, 'net')),
        ];
        break;

    // ─── 3. Annual Collection ───
    case 3:
        $reportTitle = 'Annual Collection Report';
        $headers = ['Month', 'Receipts', 'Total Amount', 'Discount', 'Late Fee', 'Net Amount'];
        $year = $fromDate ? (int) date('Y', strtotime($fromDate)) : (int) date('Y');
        $dateWhere = "AND YEAR(payment_date) = :yr";
        $dateParams = ['yr' => $year];
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month_label, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(discount_amount),0) AS discount, COALESCE(SUM(late_fee),0) AS late_fee, COALESCE(SUM(net_amount),0) AS net FROM fee_collections WHERE status = 'Active' $dateWhere GROUP BY month_label ORDER BY month_label DESC");
        $stmt->execute($dateParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'cnt' => array_sum(array_column($rows, 'cnt')),
            'total' => array_sum(array_column($rows, 'total')),
            'discount' => array_sum(array_column($rows, 'discount')),
            'late_fee' => array_sum(array_column($rows, 'late_fee')),
            'net' => array_sum(array_column($rows, 'net')),
        ];
        break;

    // ─── 4. Outstanding Fee ───
    case 4:
        $reportTitle = 'Outstanding Fee Report';
        $headers = ['Admission No', 'Student Name', 'Session', 'Total Fee', 'Total Paid', 'Discount', 'Late Fee', 'Balance'];
        $where = "WHERE sfa.status = 'active' AND sfa.balance > 0";
        $params = [];
        if ($fromDate) { $where .= " AND sfa.updated_at >= :frm"; $params['frm'] = $fromDate; }
        if ($toDate) { $where .= " AND sfa.updated_at <= :to"; $params['to'] = $toDate; }
        $stmt = $pdo->prepare("SELECT sfa.*, sfa.student_name AS student_display, sfa.class_name FROM student_fee_accounts sfa $where ORDER BY sfa.balance DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'total_fee' => array_sum(array_column($rows, 'total_fee')),
            'total_paid' => array_sum(array_column($rows, 'total_paid')),
            'total_discount' => array_sum(array_column($rows, 'total_discount')),
            'total_late_fee' => array_sum(array_column($rows, 'total_late_fee')),
            'balance' => array_sum(array_column($rows, 'balance')),
        ];
        break;

    // ─── 5. Defaulter Report ───
    case 5:
        $reportTitle = 'Defaulter Report';
        $headers = ['Admission No', 'Student Name', 'Class', 'Session', 'Total Fee', 'Paid', 'Balance', 'Last Payment Date'];
        $where = "WHERE sfa.status = 'active' AND sfa.balance > 0";
        $params = [];
        if ($fromDate) { $where .= " AND sfa.updated_at >= :frm"; $params['frm'] = $fromDate; }
        if ($toDate) { $where .= " AND sfa.updated_at <= :to"; $params['to'] = $toDate; }
        $stmt = $pdo->prepare("SELECT sfa.*, sfa.student_name AS student_display, sfa.class_name, (SELECT MAX(fc.payment_date) FROM fee_collections fc WHERE fc.student_id = sfa.student_id AND fc.status = 'Active') AS last_payment_date FROM student_fee_accounts sfa $where ORDER BY sfa.balance DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'total_fee' => array_sum(array_column($rows, 'total_fee')),
            'total_paid' => array_sum(array_column($rows, 'total_paid')),
            'balance' => array_sum(array_column($rows, 'balance')),
        ];
        break;

    // ─── 6. Expense Report ───
    case 6:
        $reportTitle = 'Expense Report';
        $headers = ['Category', 'Count', 'Total Amount', 'Net Amount', 'Approved', 'Pending'];
        $where = "WHERE 1=1";
        $params = [];
        if ($fromDate) { $where .= " AND e.payment_date >= :frm"; $params['frm'] = $fromDate; }
        if ($toDate) { $where .= " AND e.payment_date <= :to"; $params['to'] = $toDate; }
        $stmt = $pdo->prepare("SELECT ec.name AS category, COUNT(*) AS cnt, COALESCE(SUM(e.amount),0) AS total_amt, COALESCE(SUM(e.net_amount),0) AS net_amt, COALESCE(SUM(CASE WHEN e.status='Approved' THEN e.net_amount ELSE 0 END),0) AS approved, COALESCE(SUM(CASE WHEN e.status='Pending' THEN e.net_amount ELSE 0 END),0) AS pending FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id $where GROUP BY ec.name ORDER BY net_amt DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'cnt' => array_sum(array_column($rows, 'cnt')),
            'total_amt' => array_sum(array_column($rows, 'total_amt')),
            'net_amt' => array_sum(array_column($rows, 'net_amt')),
            'approved' => array_sum(array_column($rows, 'approved')),
            'pending' => array_sum(array_column($rows, 'pending')),
        ];
        break;

    // ─── 7. Income Report ───
    case 7:
        $reportTitle = 'Income Report';
        $headers = ['Category', 'Count', 'Total Amount', 'Income Type'];
        $where = "WHERE 1=1";
        $params = [];
        if ($fromDate) { $where .= " AND ir.payment_date >= :frm"; $params['frm'] = $fromDate; }
        if ($toDate) { $where .= " AND ir.payment_date <= :to"; $params['to'] = $toDate; }
        $stmt = $pdo->prepare("SELECT ic.name AS category, COUNT(*) AS cnt, COALESCE(SUM(ir.amount),0) AS total_amt, ir.income_type FROM income_records ir LEFT JOIN income_categories ic ON ic.id = ir.category_id $where GROUP BY ic.name, ir.income_type ORDER BY total_amt DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'cnt' => array_sum(array_column($rows, 'cnt')),
            'total_amt' => array_sum(array_column($rows, 'total_amt')),
        ];
        break;

    // ─── 8. Cash Book Report ───
    case 8:
        $reportTitle = 'Cash Book Report';
        $headers = ['Date', 'Type', 'Description', 'Amount', 'Direction', 'Balance'];
        $where = "WHERE 1=1";
        $params = [];
        if ($fromDate) { $where .= " AND transaction_date >= :frm"; $params['frm'] = $fromDate; }
        if ($toDate) { $where .= " AND transaction_date <= :to"; $params['to'] = $toDate; }
        $stmt = $pdo->prepare("SELECT * FROM cash_book $where ORDER BY transaction_date DESC, id DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'credit' => array_sum(array_map(fn($r) => $r['direction'] === 'credit' ? (float) $r['amount'] : 0, $rows)),
            'debit' => array_sum(array_map(fn($r) => $r['direction'] === 'debit' ? (float) $r['amount'] : 0, $rows)),
        ];
        break;

    // ─── 9. Bank Book Report ───
    case 9:
        $reportTitle = 'Bank Book Report';
        $headers = ['Date', 'Account', 'Type', 'Description', 'Amount', 'Direction', 'Balance', 'Reconciled'];
        $where = "WHERE 1=1";
        $params = [];
        if ($fromDate) { $where .= " AND bb.transaction_date >= :frm"; $params['frm'] = $fromDate; }
        if ($toDate) { $where .= " AND bb.transaction_date <= :to"; $params['to'] = $toDate; }
        $stmt = $pdo->prepare("SELECT bb.*, ba.account_name, ba.bank_name FROM bank_book bb JOIN bank_accounts ba ON ba.id = bb.bank_account_id $where ORDER BY bb.transaction_date DESC, bb.id DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = [
            'credit' => array_sum(array_map(fn($r) => $r['direction'] === 'credit' ? (float) $r['amount'] : 0, $rows)),
            'debit' => array_sum(array_map(fn($r) => $r['direction'] === 'debit' ? (float) $r['amount'] : 0, $rows)),
        ];
        break;
}
} catch (Throwable $e) {
    $reportError = 'Finance tables may not be set up yet. Run sql/finance_schema.sql to create them.';
    $rows = [];
    $totals = [];
}

// ── Handle CSV Export ──
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]/', '_', $reportTitle) . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $key = strtolower(str_replace([' ', '.', '/', '-'], '_', $h));
            // Map header to row key
            $val = $row[$key] ?? $row[str_replace(' ', '_', strtolower($h))] ?? $row[strtolower($h)] ?? '';
            if (is_numeric($val)) {
                $line[] = $val;
            } else {
                $line[] = html_entity_decode(strip_tags((string) $val));
            }
        }
        fputcsv($out, $line);
    }
    if (!empty($totals)) {
        $line = [];
        foreach ($headers as $h) {
            $key = strtolower(str_replace([' ', '.', '/', '-'], '_', $h));
            $found = null;
            foreach ($totals as $tk => $tv) {
                if (str_contains($key, strtolower($tk)) || str_contains(strtolower($tk), $key)) {
                    $found = $tv;
                }
            }
            $line[] = $found ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
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
    <title>Reports – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .app-filters { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
        .app-filters label { font-size:.8rem; margin-bottom:.2rem; }
        .app-filters input, .app-filters select { min-height:38px; padding:.45rem .7rem; border-radius:8px; width:auto; font-size:.85rem; }
        .app-filters .btn { min-height:38px; padding:.45rem 1rem; font-size:.85rem; }
        .report-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .report-table th { text-align:left; padding:.6rem .75rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
        .report-table td { padding:.55rem .75rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .report-table tr:hover td { background:#f8fafc; }
        .report-table .total-row td { font-weight:700; background:#f1f5f9; border-top:2px solid #cbd5e1; }
        .report-selector { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1.5rem; }
        .report-selector select { min-width:280px; }
        .export-bar { display:flex; gap:.5rem; margin-top:1rem; flex-wrap:wrap; }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff; }
            .admin-layout { display:block; }
            .sidebar { display:none !important; }
            .admin-main { padding:0 !important; }
            .hero-banner { display:none !important; }
            .app-filters, .report-selector, .export-bar { display:none !important; }
            .report-table th { background:#e2e8f0 !important; }
            .panel { border:none !important; box-shadow:none !important; padding:0 !important; }
            @page { margin:1.5cm; }
        }
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
            <a class="nav-link" href="receipts-list.php"><span class="sidebar-icon">🧾</span><span>Receipts</span></a>
            <a class="nav-link" href="expenses.php"><span class="sidebar-icon">📤</span><span>Expenses</span></a>
            <a class="nav-link" href="income.php"><span class="sidebar-icon">📥</span><span>Income</span></a>
            <a class="nav-link" href="accounts.php"><span class="sidebar-icon">🏦</span><span>Accounts</span></a>
            <a class="nav-link active" href="reports.php"><span class="sidebar-icon">📈</span><span>Reports</span></a>
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
        <section class="hero-banner" style="margin-bottom:1rem;" class="no-print">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Reports</h1>
                    <p>Generate financial reports with date filtering and export.</p>
                </div>
            </div>
        </section>

        <div class="no-print">
            <form method="get" class="report-selector">
                <div>
                    <label>Report Type</label>
                    <select name="report" onchange="this.form.submit()">
                        <?php foreach ($reportTypes as $id => $label): ?>
                            <option value="<?= $id ?>" <?= $reportId === $id ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (in_array($reportId, [1, 2, 6, 7, 8, 9], true)): ?>
                    <div><label>From</label><input type="date" name="from" value="<?= e($fromDate) ?>"></div>
                    <div><label>To</label><input type="date" name="to" value="<?= e($toDate) ?>"></div>
                <?php elseif ($reportId === 3): ?>
                    <div><label>Year</label>
                        <select name="from">
                            <?php for ($y = (int) date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?= $y ?>" <?= ($fromDate ? (int) $fromDate : (int) date('Y')) === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm">Generate</button>
            </form>
        </div>

        <section class="panel" style="padding:1.25rem;">
            <div class="toolbar" style="margin-bottom:1rem;">
                <h2><?= e($reportTitle) ?></h2>
                <div class="export-bar no-print">
                    <a href="?report=<?= $reportId ?>&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>&export=csv" class="btn btn-sm btn-soft">Export CSV</a>
                    <button class="btn btn-sm btn-soft" onclick="window.print()">🖨 Print / PDF</button>
                </div>
            </div>

            <?php if ($reportError !== ''): ?>
                <div style="padding:1rem;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;margin-bottom:1rem;">
                    ⚠️ <?= e($reportError) ?>
                </div>
            <?php elseif (empty($rows)): ?>
                <div style="text-align:center;padding:2rem;color:#94a3b8;">No data found for the selected criteria.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <?php foreach ($headers as $h): ?>
                                    <th><?= e($h) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($headers as $h): ?>
                                        <?php
                                        $key = strtolower(str_replace([' ', '.', '/', '-'], '_', $h));
                                        $val = $row[$key] ?? $row[str_replace(' ', '_', strtolower($h))] ?? $row[strtolower($h)] ?? '—';
                                        $class = '';
                                        if (is_numeric($val) && in_array($h, ['Total Amount', 'Net Amount', 'Total Fee', 'Total Paid', 'Balance', 'Amount', 'Discount', 'Late Fee', 'Total', 'Approved', 'Pending', 'Debit', 'Credit'])) {
                                            $class = ' style="text-align:right;font-weight:500;"';
                                            $val = 'Rs. ' . number_format((float) $val, 2);
                                        } elseif (is_numeric($val) && in_array($h, ['Count', 'Receipts Count', 'Receipts'])) {
                                            $class = ' style="text-align:right;"';
                                        } elseif ($h === 'Direction') {
                                            $class = ' style="font-weight:600;' . ($val === 'credit' ? 'color:#059669;' : ($val === 'debit' ? 'color:#dc2626;' : '')) . '"';
                                            $val = ucfirst((string) $val);
                                        } elseif ($h === 'Reconciled') {
                                            $val = (int) $val ? 'Yes' : 'No';
                                        } elseif ($h === 'Status') {
                                            $class = ' style="text-align:center;"';
                                        }
                                        ?>
                                        <td<?= $class ?>><?= e((string) $val) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($totals)): ?>
                        <tfoot>
                            <tr class="total-row">
                                <?php foreach ($headers as $h): ?>
                                    <?php
                                    $key = strtolower(str_replace([' ', '.', '/', '-'], '_', $h));
                                    $val = '';
                                    $class = ' style="font-weight:700;';
                                    if (isset($totals['cnt']) && ($key === 'count' || $key === 'receipts' || $key === 'receipts_count')) {
                                        $val = $totals['cnt'];
                                        $class .= 'text-align:right;';
                                    } elseif (isset($totals[$key])) {
                                        $v = $totals[$key];
                                        if (is_numeric($v)) {
                                            $val = 'Rs. ' . number_format((float) $v, 2);
                                            $class .= 'text-align:right;';
                                        } else {
                                            $val = (string) $v;
                                        }
                                    } elseif ($h === 'Receipts' && isset($totals['cnt'])) {
                                        $val = $totals['cnt'];
                                    } elseif (($key === 'balance' || $key === 'net_amount' || $key === 'total_amount' || str_contains($key, 'amt') || str_contains($key, 'amount')) && ($totals['net'] ?? $totals['total_amt'] ?? $totals['net_amt'] ?? null) !== null) {
                                        // Try matching
                                        foreach (['net','total','total_amt','net_amt','balance','total_fee','total_paid','total_discount','total_late_fee','credit','debit','approved','pending','cash_amt','cheque_amt','upi_amt','card_amt','bank_amt'] as $tk) {
                                            if (isset($totals[$tk]) && (str_contains($key, $tk) || str_contains($tk, str_replace('_amount', '', $key)))) {
                                                $val = 'Rs. ' . number_format((float) $totals[$tk], 2);
                                                break;
                                            }
                                        }
                                        $class .= $val ? '' : 'text-align:right;';
                                    }
                                    $class .= '"';
                                    ?>
                                    <td<?= $class ?>><?= $val !== '' ? $val : '' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="../assets/erp.js"></script>
</body>
</html>
