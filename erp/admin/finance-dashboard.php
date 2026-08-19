<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
require_once __DIR__ . '/../../includes/application_fee.php';
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pageTitle = 'Finance Dashboard';

// Date filter — applies ONLY to the "Filtered Period" section, defaults to current month
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo = trim((string) ($_GET['to'] ?? ''));
$filterApplied = ($filterFrom !== '' || $filterTo !== '');
if ($filterFrom === '' && $filterTo === '') {
    $filterFrom = date('Y-m-01');
    $filterTo = date('Y-m-t');
}

// Fixed period: Jan 2026 to today (for top overview — never changes)
$fixedFrom = '2026-01-01';
$fixedTo = date('Y-m-d');

// Guard: if finance tables are missing, show empty dashboard instead of 500
function finance_scalar(PDO $pdo, string $sql, array $params = []): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

function finance_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

// ─── Top Section: Jan 2026 to Today (always fixed) ───
$topExpenses = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM expenses WHERE status IN ('Approved','Pending') AND expense_date >= ? AND expense_date <= ?", [$fixedFrom, $fixedTo]);
$topIncomeApproved = finance_scalar($pdo, "SELECT COALESCE(SUM(amount), 0) FROM income_categories WHERE status = 'Approved' AND income_date >= ? AND income_date <= ?", [$fixedFrom, $fixedTo]);
$topAppFeeIncome = finance_scalar($pdo, "SELECT COALESCE(SUM(CAST(payment_amount AS DECIMAL(12,2))), 0) FROM applications WHERE payment_status = 'Paid' AND deleted_at IS NULL AND DATE(applied_at) >= ? AND DATE(applied_at) <= ?", [$fixedFrom, $fixedTo]);
$topTotalIncome = $topIncomeApproved + $topAppFeeIncome;
$topCollection = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM fee_collections WHERE payment_date >= ? AND payment_date <= ? AND status = 'Active'", [$fixedFrom, $fixedTo]);
$todayCollection = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM fee_collections WHERE payment_date = CURDATE() AND status = 'Active'");
$outstandingFees = finance_scalar($pdo, "SELECT COALESCE(SUM(balance), 0) FROM student_fee_accounts WHERE status = 'active'");

// ─── Filtered Period Section (defaults to current month, changes with filter) ───
$filteredExpenses = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM expenses WHERE status IN ('Approved','Pending') AND expense_date >= ? AND expense_date <= ?", [$filterFrom, $filterTo]);
$filteredIncomeApproved = finance_scalar($pdo, "SELECT COALESCE(SUM(amount), 0) FROM income_categories WHERE status = 'Approved' AND income_date >= ? AND income_date <= ?", [$filterFrom, $filterTo]);
$filteredAppFeeIncome = finance_scalar($pdo, "SELECT COALESCE(SUM(CAST(payment_amount AS DECIMAL(12,2))), 0) FROM applications WHERE payment_status = 'Paid' AND deleted_at IS NULL AND DATE(applied_at) >= ? AND DATE(applied_at) <= ?", [$filterFrom, $filterTo]);
$filteredTotalIncome = $filteredIncomeApproved + $filteredAppFeeIncome;
$filteredCollection = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM fee_collections WHERE payment_date >= ? AND payment_date <= ? AND status = 'Active'", [$filterFrom, $filterTo]);
$filteredPendingExpenses = (int) finance_scalar($pdo, "SELECT COUNT(*) FROM expenses WHERE status = 'Pending'");
$filteredPendingDues = finance_scalar($pdo, "SELECT COALESCE(SUM(balance), 0) FROM student_fee_accounts WHERE status = 'active' AND balance > 0");

// Recent Transactions (filtered)
$recentTransactions = finance_rows($pdo, "SELECT fc.*, fc.student_name AS student_display FROM fee_collections fc WHERE fc.status = 'Active' AND fc.payment_date >= ? AND fc.payment_date <= ? ORDER BY fc.created_at DESC LIMIT 10", [$filterFrom, $filterTo]);

// Pending Dues (not date-filtered — always current)
$pendingDues = finance_rows($pdo, "SELECT sfa.*, sfa.student_name AS student_display FROM student_fee_accounts sfa WHERE sfa.status = 'active' AND sfa.balance > 0 ORDER BY sfa.balance DESC LIMIT 10");

// Fee collection count for sidebar badge
$todayCount = (int) finance_scalar($pdo, "SELECT COUNT(*) FROM fee_collections WHERE payment_date = CURDATE() AND status = 'Active'");

// Expenses count for sidebar badge
$pendingExpenseCount = (int) finance_scalar($pdo, "SELECT COUNT(*) FROM expenses WHERE status = 'Pending'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Finance Dashboard – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .kpi-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:1.25rem; margin-bottom:2rem; }
        .kpi-card { background:#fff; border-radius:14px; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04); display:flex; flex-direction:column; gap:.35rem; }
        .kpi-card .kpi-label { font-size:.8rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
        .kpi-card .kpi-value { font-size:1.75rem; font-weight:700; color:#0f172a; }
        .kpi-card .kpi-value-currency { font-size:1.4rem; }
        .kpi-card .kpi-sub { font-size:.8rem; color:#94a3b8; }
        .kpi-card.highlight { border-left:4px solid #2563eb; }
        .kpi-card.warning { border-left:4px solid #f59e0b; }
        .kpi-card.danger { border-left:4px solid #ef4444; }
        .kpi-card.success { border-left:4px solid #10b981; }
        .split-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:2rem; }
        .app-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .app-table th { text-align:left; padding:.65rem .5rem; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; }
        .app-table td { padding:.65rem .5rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
        .app-table tr:hover td { background:#f8fafc; }
        .action-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px,1fr)); gap:.85rem; }
        .action-grid a { display:flex; flex-direction:column; padding:1rem; background:#fff; border-radius:10px; border:1px solid #e2e8f0; text-decoration:none; color:#334155; font-weight:600; font-size:.9rem; transition:box-shadow .15s; }
        .action-grid a:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); border-color:#cbd5e1; }
        .action-grid a small { font-weight:400; color:#94a3b8; font-size:.8rem; margin-top:.2rem; }
        @media (max-width:768px) { .split-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Finance Dashboard</h1>
                    <p>Real-time overview of collections, dues, expenses, and income.</p>
                </div>
                <form method="get" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:.2rem;">From</label>
                        <input type="date" name="from" value="<?= e($filterFrom) ?>" style="padding:.45rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:.2rem;">To</label>
                        <input type="date" name="to" value="<?= e($filterTo) ?>" style="padding:.45rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
                    </div>
                    <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:.5rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Filter</button>
                    <?php if ($filterApplied): ?>
                        <a href="finance-dashboard.php" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;padding:.5rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none;">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <!-- ═══ TOP SECTION: Jan 2026 – Today (always fixed) ═══ -->
        <div style="margin-bottom:.75rem;">
            <h2 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">📊 Overview — Jan 2026 to <?= e(date('d M Y')) ?></h2>
            <p style="font-size:.82rem;color:#64748b;margin:.2rem 0 0;">All-time totals since system start. These values never change.</p>
        </div>
        <?php $topLabel = 'Jan 2026 – ' . e(date('d M Y')); ?>
        <div class="kpi-grid">
            <div class="kpi-card warning">
                <div class="kpi-label">Total Expenses</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($topExpenses, 2) ?></div>
                <div class="kpi-sub"><?= $topLabel ?></div>
            </div>
            <div class="kpi-card highlight">
                <div class="kpi-label">Total Income</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($topTotalIncome, 2) ?></div>
                <div class="kpi-sub">Approved income + application fees</div>
            </div>
            <div class="kpi-card <?= ($topTotalIncome - $topExpenses) >= 0 ? 'success' : 'danger' ?>">
                <div class="kpi-label">Net Surplus / Deficit</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($topTotalIncome - $topExpenses, 2) ?></div>
                <div class="kpi-sub">Income minus expenses</div>
            </div>
            <div class="kpi-card success">
                <div class="kpi-label">Today's Collection</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($todayCollection, 2) ?></div>
                <div class="kpi-sub">Fee collected today</div>
            </div>
            <div class="kpi-card highlight">
                <div class="kpi-label">Total Collection</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($topCollection, 2) ?></div>
                <div class="kpi-sub"><?= $topLabel ?></div>
            </div>
            <div class="kpi-card danger">
                <div class="kpi-label">Outstanding Fees</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($outstandingFees, 2) ?></div>
                <div class="kpi-sub">Total balance from active accounts</div>
            </div>
        </div>

        <!-- ═══ FILTERED PERIOD SECTION ═══ -->
        <?php $periodLabel = e(date('d M', strtotime($filterFrom))) . ' – ' . e(date('d M Y', strtotime($filterTo))); ?>
        <div style="margin-bottom:.75rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <div>
                <h2 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">🔍 Filtered Period — <?= $periodLabel ?></h2>
                <p style="font-size:.82rem;color:#64748b;margin:.2rem 0 0;">Use the date filter above to change this period. Defaults to current month.</p>
            </div>
            <?php if ($filterApplied): ?>
                <a href="finance-dashboard.php" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:.4rem .9rem;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;white-space:nowrap;">✕ Clear Filter</a>
            <?php endif; ?>
        </div>
        <div class="kpi-grid">
            <div class="kpi-card success">
                <div class="kpi-label">Fee Collection</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($filteredCollection, 2) ?></div>
                <div class="kpi-sub"><?= $periodLabel ?></div>
            </div>
            <div class="kpi-card highlight">
                <div class="kpi-label">Total Income</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($filteredTotalIncome, 2) ?></div>
                <div class="kpi-sub">Approved income + application fees</div>
            </div>
            <div class="kpi-card warning">
                <div class="kpi-label">Total Expenses</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($filteredExpenses, 2) ?></div>
                <div class="kpi-sub">Approved + pending expenses</div>
            </div>
            <div class="kpi-card <?= ($filteredTotalIncome - $filteredExpenses) >= 0 ? 'success' : 'danger' ?>">
                <div class="kpi-label">Net Surplus / Deficit</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($filteredTotalIncome - $filteredExpenses, 2) ?></div>
                <div class="kpi-sub">Income minus expenses</div>
            </div>
            <div class="kpi-card danger">
                <div class="kpi-label">Outstanding Dues</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($filteredPendingDues, 2) ?></div>
                <div class="kpi-sub">Total balance from active accounts</div>
            </div>
            <div class="kpi-card warning">
                <div class="kpi-label">Pending Expenses</div>
                <div class="kpi-value"><?= $filteredPendingExpenses ?></div>
                <div class="kpi-sub">Expenses awaiting approval</div>
            </div>
        </div>

        <!-- Chart Placeholder & Recent Transactions -->
        <div class="split-grid">
            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Income vs Expenses</h2>
                        <p>Monthly comparison chart placeholder</p>
                    </div>
                </div>
                <div style="height:240px;display:flex;align-items:center;justify-content:center;background:#f8fafc;border-radius:8px;color:#94a3b8;font-size:.9rem;border:1px dashed #e2e8f0;">
                    <div style="text-align:center;">
                        <div style="font-size:2rem;margin-bottom:.5rem;">📊</div>
                        <div>Income: Rs. <?= number_format($filteredCollection + $filteredTotalIncome, 2) ?></div>
                        <div>Expenses: Rs. <?= number_format($filteredExpenses, 2) ?></div>
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Recent Transactions</h2>
                        <p>Last 10 fee collections</p>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Student</th>
                                <th>Amount</th>
                                <th>Mode</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTransactions)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:2rem;color:#94a3b8;">No transactions yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentTransactions as $txn): ?>
                                    <tr>
                                        <td style="font-family:monospace;"><?= e((string) ($txn['receipt_no'] ?? '—')) ?></td>
                                        <td><?= e((string) ($txn['student_display'] ?? '—')) ?></td>
                                        <td>Rs. <?= number_format((float) ($txn['net_amount'] ?? 0), 2) ?></td>
                                        <td><?= e((string) ($txn['payment_mode'] ?? '—')) ?></td>
                                        <td style="white-space:nowrap;"><?= e((string) ($txn['payment_date'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Pending Dues -->
        <section class="panel" style="padding:1.25rem;margin-bottom:2rem;">
            <div class="section-title">
                <div>
                    <h2>Pending Dues</h2>
                    <p>Top outstanding student fee accounts</p>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Student</th>
                            <th>Total Fee</th>
                            <th>Total Paid</th>
                            <th>Balance</th>
                            <th>Session</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingDues)): ?>
                            <tr><td colspan="6" style="text-align:center;padding:2rem;color:#94a3b8;">No pending dues.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pendingDues as $due): ?>
                                <tr>
                                    <td><?= e((string) ($due['student_name'] ?? '—')) ?></td>
                                    <td><strong><?= e((string) ($due['student_display'] ?? '—')) ?></strong></td>
                                    <td>Rs. <?= number_format((float) ($due['total_fee'] ?? 0), 2) ?></td>
                                    <td>Rs. <?= number_format((float) ($due['total_paid'] ?? 0), 2) ?></td>
                                    <td style="color:#ef4444;font-weight:600;">Rs. <?= number_format((float) ($due['balance'] ?? 0), 2) ?></td>
                                    <td><?= e((string) ($due['academic_session'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Quick Actions -->
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Quick Actions</h2>
                    <p>Common finance operations</p>
                </div>
            </div>
            <div class="action-grid">
                <a href="fee-collection-new.php">
                    💳 New Fee Collection
                    <small>Record a new fee payment</small>
                </a>
                <a href="fee-structure-new.php">
                    💰 New Fee Structure
                    <small>Create a fee structure</small>
                </a>
                <a href="expense-entry.php">
                    📤 View Expenses
                    <small>Track all expense records</small>
                </a>
                <a href="income-entry.php">
                    📥 View Income
                    <small>Track all income records</small>
                </a>
            </div>
        </section>
    </main>
</div>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
