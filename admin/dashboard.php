<?php
$pageTitle = "Dashboard";
require_once('../includes/db_connect.php');
include('../includes/admin_header.php');
include('../includes/admin_sidebar.php');

// ─── Stats ───
$total_apps    = (int)$conn->query("SELECT COUNT(*) FROM applications")->fetch_row()[0];
$total_parents = (int)$conn->query("SELECT COUNT(*) FROM parents")->fetch_row()[0];
$total_admitted= (int)$conn->query("SELECT COUNT(*) FROM applications WHERE status='Admitted'")->fetch_row()[0];
$total_review  = (int)$conn->query("SELECT COUNT(*) FROM applications WHERE status='Under review'")->fetch_row()[0];
$total_rejected= (int)$conn->query("SELECT COUNT(*) FROM applications WHERE status='Rejected'")->fetch_row()[0];
$total_fees    = (float)($conn->query("SELECT COALESCE(SUM(amount),0) FROM fees WHERE status='Paid'")->fetch_row()[0] ?? 0);
$recent_apps   = $conn->query("SELECT a.*, p.phone FROM applications a JOIN parents p ON a.parent_id=p.id ORDER BY a.applied_at DESC LIMIT 8");
?>

<!-- ── Stat Cards ── -->
<div class="admin-stats-grid">
    <div class="admin-stat-card blue">
        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
        <div><div class="stat-num"><?php echo $total_apps; ?></div><div class="stat-lbl">Total Applications</div></div>
    </div>
    <div class="admin-stat-card green">
        <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
        <div><div class="stat-num"><?php echo $total_admitted; ?></div><div class="stat-lbl">Admitted</div></div>
    </div>
    <div class="admin-stat-card amber">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div><div class="stat-num"><?php echo $total_review; ?></div><div class="stat-lbl">Under Review</div></div>
    </div>
    <div class="admin-stat-card red">
        <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
        <div><div class="stat-num"><?php echo $total_rejected; ?></div><div class="stat-lbl">Rejected</div></div>
    </div>
    <div class="admin-stat-card purple">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div><div class="stat-num"><?php echo $total_parents; ?></div><div class="stat-lbl">Registered Parents</div></div>
    </div>
    <div class="admin-stat-card green">
        <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
        <div><div class="stat-num">₹<?php echo number_format($total_fees/1000, 1); ?>K</div><div class="stat-lbl">Fees Collected</div></div>
    </div>
</div>

<div style="display:grid; grid-template-columns:1.6fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">

    <!-- Recent Applications Table -->
    <div class="admin-panel">
        <div class="admin-panel-header">
            <h3><i class="fas fa-file-alt" style="color:var(--secondary-color);"></i> &nbsp;Recent Applications</h3>
            <a href="<?php echo SITE_URL; ?>/admin/manage-applications.php" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="admin-panel-table">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th><th>Student</th><th>Class</th><th>Phone</th><th>Status</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $recent_apps->fetch_assoc()):
                    $bc = ['Application started'=>'badge-pending','Under review'=>'badge-review','Admitted'=>'badge-admitted','Rejected'=>'badge-rejected'];
                    $cls = $bc[$row['status']] ?? 'badge-pending';
                ?>
                    <tr>
                        <td><strong style="color:var(--primary-color);">S-<?php echo str_pad($row['id'],3,'0',STR_PAD_LEFT); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['class_sought']); ?></td>
                        <td><?php echo htmlspecialchars($row['phone']); ?></td>
                        <td><span class="badge <?php echo $cls; ?>"><?php echo $row['status']; ?></span></td>
                        <td><a href="manage-applications.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-primary">Review</a></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column -->
    <div style="display:flex; flex-direction:column; gap:1.5rem;">

        <!-- Application Status Breakdown -->
        <div class="admin-panel">
            <div class="admin-panel-header"><h3><i class="fas fa-chart-pie" style="color:var(--secondary-color);"></i> &nbsp;Status Breakdown</h3></div>
            <div class="admin-panel-body">
                <?php
                $statuses = [
                    ['Application started', $total_apps - $total_review - $total_admitted - $total_rejected, '#feb630'],
                    ['Under Review',        $total_review,   '#5eabe3'],
                    ['Admitted',            $total_admitted, '#4b5563'],
                    ['Rejected',            $total_rejected, '#ef4444'],
                ];
                foreach ($statuses as $s):
                    $pct = $total_apps > 0 ? round(($s[1] / $total_apps) * 100) : 0;
                ?>
                <div style="margin-bottom:1rem;">
                    <div style="display:flex; justify-content:space-between; font-size:0.82rem; margin-bottom:0.3rem;">
                        <span style="font-weight:600;"><?php echo $s[0]; ?></span>
                        <span style="color:var(--text-light);"><?php echo $s[1]; ?> (<?php echo $pct; ?>%)</span>
                    </div>
                    <div style="height:8px; background:#f3f4f6; border-radius:50px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $pct; ?>%; background:<?php echo $s[2]; ?>; border-radius:50px; transition:width 1s ease;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="admin-panel">
            <div class="admin-panel-header"><h3><i class="fas fa-bolt" style="color:var(--secondary-color);"></i> &nbsp;Quick Actions</h3></div>
            <div class="admin-panel-body">
                <div class="quick-actions" style="grid-template-columns:1fr 1fr;">
                    <a href="manage-applications.php" class="action-tile"><i class="fas fa-file-alt"></i>Applications</a>
                    <a href="fee-reports.php" class="action-tile"><i class="fas fa-file-invoice-dollar"></i>Fee Reports</a>
                    <a href="manage-staff.php" class="action-tile"><i class="fas fa-users-cog"></i>Staff</a>
                    <a href="notifications.php" class="action-tile"><i class="fas fa-bullhorn"></i>Notifications</a>
                </div>
            </div>
        </div>
    </div>
</div>

</div></div></div>
<?php include('../includes/admin_footer.php'); ?>
