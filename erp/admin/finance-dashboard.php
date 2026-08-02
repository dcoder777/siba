<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$pageTitle = 'Finance Dashboard';

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

// Today's Collection
$todayCollection = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM fee_collections WHERE payment_date = CURDATE() AND status = 'Active'");

// Monthly Collection
$monthlyCollection = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM fee_collections WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) AND status = 'Active'");

// Outstanding Fees
$outstandingFees = finance_scalar($pdo, "SELECT COALESCE(SUM(balance), 0) FROM student_fee_accounts WHERE status = 'active'");

// Total Expenses (monthly)
$monthlyExpenses = finance_scalar($pdo, "SELECT COALESCE(SUM(net_amount), 0) FROM expenses WHERE status = 'Approved' AND MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");

// Total Income (monthly, non-fee)
$monthlyIncome = finance_scalar($pdo, "SELECT COALESCE(SUM(amount), 0) FROM income_records WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())");

// Recent Transactions
$recentTransactions = finance_rows($pdo, "SELECT fc.*, fc.student_name AS student_display FROM fee_collections fc WHERE fc.status = 'Active' ORDER BY fc.created_at DESC LIMIT 10");

// Pending Dues (top 10)
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
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:1.25rem; margin-bottom:2rem; }
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
            </div>
        </section>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card success">
                <div class="kpi-label">Today's Collection</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($todayCollection, 2) ?></div>
                <div class="kpi-sub">Fee collected today</div>
            </div>
            <div class="kpi-card highlight">
                <div class="kpi-label">Monthly Collection</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($monthlyCollection, 2) ?></div>
                <div class="kpi-sub">This month's total fee collection</div>
            </div>
            <div class="kpi-card danger">
                <div class="kpi-label">Outstanding Fees</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($outstandingFees, 2) ?></div>
                <div class="kpi-sub">Total balance from active accounts</div>
            </div>
            <div class="kpi-card warning">
                <div class="kpi-label">Monthly Expenses</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($monthlyExpenses, 2) ?></div>
                <div class="kpi-sub">Approved expenses this month</div>
            </div>
            <div class="kpi-card highlight">
                <div class="kpi-label">Monthly Income (Non-Fee)</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($monthlyIncome, 2) ?></div>
                <div class="kpi-sub">Donations, grants & other income</div>
            </div>
            <div class="kpi-card <?= ($monthlyCollection + $monthlyIncome - $monthlyExpenses) >= 0 ? 'success' : 'danger' ?>">
                <div class="kpi-label">Net Surplus / Deficit</div>
                <div class="kpi-value kpi-value-currency">Rs. <?= number_format($monthlyCollection + $monthlyIncome - $monthlyExpenses, 2) ?></div>
                <div class="kpi-sub">Income minus expenses this month</div>
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
                        <div>Income: Rs. <?= number_format($monthlyCollection + $monthlyIncome, 2) ?></div>
                        <div>Expenses: Rs. <?= number_format($monthlyExpenses, 2) ?></div>
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
                <a href="fee-collection.php">
                    💳 New Fee Collection
                    <small>Record a new fee payment</small>
                </a>
                <a href="fee-structures.php">
                    💰 New Fee Structure
                    <small>Create a fee structure</small>
                </a>
                <a href="expenses.php">
                    📤 Record Expense
                    <small>Log a new expense</small>
                </a>
                <a href="income.php">
                    📥 New Income
                    <small>Record non-fee income</small>
                </a>
            </div>
        </section>
    </main>
</div>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
