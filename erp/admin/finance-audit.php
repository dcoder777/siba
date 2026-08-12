<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$pdo = $GLOBALS['pdo'];
$action = $_GET['action'] ?? '';
$confirm = $_GET['confirm'] ?? '';

// ─── ANALYSIS MODE ───
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Finance Data Audit</title>';
echo '<link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">';
echo '<style>body{padding:2rem;font-family:system-ui;}table{border-collapse:collapse;width:100%;margin:1rem 0;}th,td{padding:.5rem .75rem;border:1px solid #e2e8f0;text-align:left;}th{background:#f1f5f9;} .red{color:#ef4444;font-weight:700;} .green{color:#10b981;font-weight:700;} .card{background:#fff;border-radius:12px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);}</style></head><body>';
echo '<h1>Finance Data Audit</h1>';

function safeQuery(PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll(); } catch (Throwable) { return []; }
}
function safeScalar(PDO $pdo, string $sql): float {
    try { return (float)$pdo->query($sql)->fetchColumn(); } catch (Throwable) { return 0.0; }
}

// ─── EXPENSES ───
echo '<div class="card"><h2>Expenses</h2>';
$expTotal = safeScalar($pdo, "SELECT COALESCE(SUM(net_amount),0) FROM expenses");
$expCount = safeScalar($pdo, "SELECT COUNT(*) FROM expenses");
$expApproved = safeScalar($pdo, "SELECT COUNT(*) FROM expenses WHERE status='Approved'");
$expPending = safeScalar($pdo, "SELECT COUNT(*) FROM expenses WHERE status='Pending'");
echo "<p>Rows: <strong>{$expCount}</strong> | Approved: {$expApproved} | Pending: {$expPending}</p>";
echo '<p class="red">Total Expenses: Rs. ' . number_format($expTotal, 2) . '</p>';

$rows = safeQuery($pdo, "SELECT id, description, vendor_name, net_amount, expense_date, status, category_id FROM expenses ORDER BY id DESC LIMIT 20");
if ($rows) {
    echo '<table><tr><th>ID</th><th>Date</th><th>Vendor</th><th>Category</th><th>Amount</th><th>Status</th><th>Description</th></tr>';
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td>{$r['expense_date']}</td><td>" . ($r['vendor_name'] ?: '—') . "</td><td>" . ($r['category_id'] ?: '—') . "</td><td>Rs. " . number_format((float)$r['net_amount'], 2) . "</td><td>{$r['status']}</td><td>" . mb_substr($r['description'] ?: '', 0, 60) . "</td></tr>";
    }
    echo '</table>';
}
echo '</div>';

// ─── FEE COLLECTIONS ───
echo '<div class="card"><h2>Fee Collections</h2>';
$colTotal = safeScalar($pdo, "SELECT COALESCE(SUM(net_amount),0) FROM fee_collections WHERE status='Active'");
$colCount = safeScalar($pdo, "SELECT COUNT(*) FROM fee_collections WHERE status='Active'");
echo "<p>Rows: <strong>{$colCount}</strong> | Total: Rs. " . number_format($colTotal, 2) . '</p>';
if ($colCount == 0) echo '<p class="red">No fee collections recorded — this table is empty!</p>';
echo '</div>';

// ─── INCOME ───
echo '<div class="card"><h2>Income Categories</h2>';
$incTotal = safeScalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM income_categories");
$incCount = safeScalar($pdo, "SELECT COUNT(*) FROM income_categories");
echo "<p>Rows: <strong>{$incCount}</strong> | Total: Rs. " . number_format($incTotal, 2) . '</p>';
$rows = safeQuery($pdo, "SELECT id, name, amount, income_date, status FROM income_categories ORDER BY id DESC LIMIT 10");
if ($rows) {
    echo '<table><tr><th>ID</th><th>Date</th><th>Name</th><th>Amount</th><th>Status</th></tr>';
    foreach ($rows as $r) echo "<tr><td>{$r['id']}</td><td>{$r['income_date']}</td><td>{$r['name']}</td><td>Rs. " . number_format((float)$r['amount'], 2) . "</td><td>{$r['status']}</td></tr>";
    echo '</table>';
}
echo '</div>';

// ─── STUDENT FEE ACCOUNTS ───
echo '<div class="card"><h2>Student Fee Accounts</h2>';
$sfaTotal = safeScalar($pdo, "SELECT COALESCE(SUM(balance),0) FROM student_fee_accounts");
$sfaCount = safeScalar($pdo, "SELECT COUNT(*) FROM student_fee_accounts");
echo "<p>Rows: <strong>{$sfaCount}</strong> | Total balance: Rs. " . number_format($sfaTotal, 2) . '</p>';
if ($sfaCount == 0) echo '<p class="red">No student fee accounts — fee tracking is not set up!</p>';
echo '</div>';

// ─── APPLICATIONS ───
echo '<div class="card"><h2>Applications (paid)</h2>';
$appPaid = safeScalar($pdo, "SELECT COUNT(*) FROM applications WHERE payment_status='Paid' AND deleted_at IS NULL");
$appAmount = safeScalar($pdo, "SELECT COALESCE(SUM(CAST(payment_amount AS DECIMAL(12,2))),0) FROM applications WHERE payment_status='Paid' AND deleted_at IS NULL");
echo "<p>Paid apps: <strong>{$appPaid}</strong> | Total fee amount: Rs. " . number_format($appAmount, 2) . '</p>';
echo '</div>';

// ─── VENDORS ───
echo '<div class="card"><h2>Vendors</h2>';
$vCount = safeScalar($pdo, "SELECT COUNT(*) FROM vendors");
echo "<p>Rows: <strong>{$vCount}</strong></p>";
echo '</div>';

// ─── CLEANUP ACTIONS ───
echo '<div class="card"><h2>Cleanup Actions</h2>';
echo '<p>If the Rs. 46M expenses are test/demo data, you can truncate the expenses table:</p>';
if ($action === 'truncate_expenses' && $confirm === 'yes') {
    try {
        $pdo->exec("TRUNCATE TABLE expenses");
        echo '<p class="green">Expenses table truncated successfully!</p>';
    } catch (Throwable $e) {
        echo '<p class="red">Error: ' . $e->getMessage() . '</p>';
    }
} elseif ($action === 'truncate_expenses') {
    echo '<p><a href="?action=truncate_expenses&confirm=yes" style="background:#ef4444;color:#fff;padding:.5rem 1rem;border-radius:8px;text-decoration:none;font-weight:600;">⚠ Confirm: Delete ALL expenses</a></p>';
    echo '<p><a href="?action=truncate_income&confirm=yes" style="background:#ef4444;color:#fff;padding:.5rem 1rem;border-radius:8px;text-decoration:none;font-weight:600;">⚠ Confirm: Delete ALL income entries</a></p>';
} else {
    echo '<p><a href="?action=truncate_expenses">Delete test expenses</a></p>';
}
echo '<p><a href="finance-dashboard.php">← Back to Dashboard</a></p>';
echo '</div>';

echo '</body></html>';
