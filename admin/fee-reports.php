<?php
$pageTitle = "Fee Reports";
require_once('../includes/db_connect.php');
include('../includes/admin_header.php');
include('../includes/admin_sidebar.php');

$total_fees      = (float)($conn->query("SELECT COALESCE(SUM(amount),0) FROM fees WHERE status='Paid'")->fetch_row()[0] ?? 0);
$total_txns      = (int)$conn->query("SELECT COUNT(*) FROM fees WHERE status='Paid'")->fetch_row()[0];
$unique_payers   = (int)$conn->query("SELECT COUNT(DISTINCT application_id) FROM fees WHERE status='Paid'")->fetch_row()[0];

$filter_month = $conn->real_escape_string($_GET['month'] ?? '');
$filter_year  = (int)($_GET['year'] ?? 0);
$where = ["f.status='Paid'"];
if ($filter_month) $where[] = "f.month='$filter_month'";
if ($filter_year)  $where[] = "f.year='$filter_year'";
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$fees = $conn->query("SELECT f.*, a.student_name, a.class_sought, p.phone
    FROM fees f
    JOIN applications a ON f.application_id = a.id
    JOIN parents p ON a.parent_id = p.id
    $whereSQL ORDER BY f.paid_at DESC");

$months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
$years  = [2025, 2026, 2027];
?>

<!-- Stats -->
<div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom:1.5rem;">
    <div class="admin-stat-card green">
        <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
        <div><div class="stat-num">₹<?php echo number_format($total_fees); ?></div><div class="stat-lbl">Total Collected</div></div>
    </div>
    <div class="admin-stat-card blue">
        <div class="stat-icon"><i class="fas fa-receipt"></i></div>
        <div><div class="stat-num"><?php echo $total_txns; ?></div><div class="stat-lbl">Transactions</div></div>
    </div>
    <div class="admin-stat-card purple">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div><div class="stat-num"><?php echo $unique_payers; ?></div><div class="stat-lbl">Students Paid</div></div>
    </div>
</div>

<div class="admin-panel">
    <div class="admin-panel-header">
        <h3><i class="fas fa-file-invoice-dollar" style="color:var(--secondary-color)"></i> &nbsp;Payment Records</h3>
        <form method="GET" style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <select name="month" style="padding:0.5rem 0.75rem; border:1.5px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                <option value="">All Months</option>
                <?php foreach ($months as $m): ?>
                    <option value="<?php echo $m; ?>" <?php echo $filter_month==$m?'selected':''; ?>><?php echo $m; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" style="padding:0.5rem 0.75rem; border:1.5px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                <option value="">All Years</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo $filter_year==$y?'selected':''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <a href="fee-reports.php" class="btn btn-sm" style="background:#f3f4f6;">Clear</a>
        </form>
    </div>
    <div class="admin-panel-table">
        <table class="admin-table">
            <thead>
                <tr><th>Txn ID</th><th>Student</th><th>Class</th><th>Phone</th><th>Month</th><th>Year</th><th>Amount</th><th>Paid On</th></tr>
            </thead>
            <tbody>
            <?php if ($fees->num_rows == 0): ?>
                <tr><td colspan="8" style="text-align:center; color:var(--text-light); padding:2rem;">No payment records found.</td></tr>
            <?php else: while ($row = $fees->fetch_assoc()): ?>
                <tr>
                    <td><strong style="color:var(--primary-color);">SIBA<?php echo str_pad($row['id'],5,'0',STR_PAD_LEFT); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['class_sought']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone']); ?></td>
                    <td><?php echo $row['month']; ?></td>
                    <td><?php echo $row['year']; ?></td>
                    <td><strong style="color:#4b5563;">₹<?php echo number_format($row['amount'],2); ?></strong></td>
                    <td style="color:var(--text-light);"><?php echo date('d M Y, h:i A', strtotime($row['paid_at'])); ?></td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div></div></div>
<?php include('../includes/admin_footer.php'); ?>
