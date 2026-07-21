<?php
$pageTitle = "Staff Management";
require_once('../includes/db_connect.php');
include('../includes/admin_header.php');
include('../includes/admin_sidebar.php');

$success = '';
$error   = '';

// Ensure staff table exists (matches unified schema in siba_erp)
$conn->query("CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100),
    department VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    status ENUM('Active','Inactive') DEFAULT 'Active',
    employee_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Add staff
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_staff'])) {
    $name        = $conn->real_escape_string(trim($_POST['name']));
    $designation = $conn->real_escape_string(trim($_POST['designation']));
    $department  = $conn->real_escape_string(trim($_POST['department']));
    $phone       = $conn->real_escape_string(trim($_POST['phone']));
    $email       = $conn->real_escape_string(trim($_POST['email']));
    if ($name) {
        $conn->query("INSERT INTO staff (name, designation, department, phone, email) VALUES ('$name','$designation','$department','$phone','$email')");
        $staff_id = $conn->insert_id;

        // Also create ERP employee record
        $dept  = $department ?: 'General';
        $desig = $designation ?: 'Staff';
        $emp_code = 'EMP-' . str_pad($staff_id, 4, '0', STR_PAD_LEFT);
        $conn->query("INSERT INTO employees (employee_code, name, department, designation, status)
            VALUES ('$emp_code', '$name', '$dept', '$desig', 'active')");
        $emp_id = $conn->insert_id;
        $conn->query("UPDATE staff SET employee_id = $emp_id WHERE id = $staff_id");

        $success = "Staff member added successfully.";
    } else {
        $error = "Name is required.";
    }
}

// Toggle status
if (isset($_GET['toggle'])) {
    $sid = (int)$_GET['toggle'];
    $conn->query("UPDATE staff SET status = IF(status='Active','Inactive','Active') WHERE id=$sid");
    header("Location: manage-staff.php"); exit();
}

// Delete
if (isset($_GET['delete'])) {
    $sid = (int)$_GET['delete'];
    $conn->query("DELETE FROM staff WHERE id=$sid");
    header("Location: manage-staff.php"); exit();
}

$staffList = $conn->query("SELECT * FROM staff ORDER BY name");
$total = $conn->query("SELECT COUNT(*) FROM staff")->fetch_row()[0];
$active = $conn->query("SELECT COUNT(*) FROM staff WHERE status='Active'")->fetch_row()[0];
?>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:1.25rem;"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem;"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom:1.5rem;">
    <div class="admin-stat-card blue">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div><div class="stat-num"><?php echo $total; ?></div><div class="stat-lbl">Total Staff</div></div>
    </div>
    <div class="admin-stat-card green">
        <div class="stat-icon"><i class="fas fa-user-check"></i></div>
        <div><div class="stat-num"><?php echo $active; ?></div><div class="stat-lbl">Active</div></div>
    </div>
    <div class="admin-stat-card red">
        <div class="stat-icon"><i class="fas fa-user-times"></i></div>
        <div><div class="stat-num"><?php echo $total - $active; ?></div><div class="stat-lbl">Inactive</div></div>
    </div>
</div>

<div style="display:grid; grid-template-columns:1.8fr 1fr; gap:1.5rem;">

    <!-- Staff List -->
    <div class="admin-panel">
        <div class="admin-panel-header">
            <h3><i class="fas fa-users-cog" style="color:var(--secondary-color)"></i> &nbsp;Staff Members</h3>
        </div>
        <div class="admin-panel-table">
            <table class="admin-table">
                <thead>
                    <tr><th>Name</th><th>Designation</th><th>Department</th><th>Phone</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if ($staffList->num_rows == 0): ?>
                    <tr><td colspan="6" style="text-align:center; color:var(--text-light); padding:2rem;">No staff added yet. Add your first staff member.</td></tr>
                <?php else: while ($s = $staffList->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['name']); ?></strong>
                        <?php if ($s['email']): ?><br><small style="color:var(--text-light);"><?php echo htmlspecialchars($s['email']); ?></small><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($s['designation']); ?></td>
                        <td><?php echo htmlspecialchars($s['department']); ?></td>
                        <td><?php echo htmlspecialchars($s['phone']); ?></td>
                        <td>
                            <span class="badge <?php echo $s['status']=='Active'?'badge-admitted':'badge-rejected'; ?>">
                                <?php echo $s['status']; ?>
                            </span>
                        </td>
                        <td style="display:flex; gap:0.5rem;">
                            <a href="?toggle=<?php echo $s['id']; ?>" class="btn btn-sm btn-primary"><?php echo $s['status']=='Active'?'Deactivate':'Activate'; ?></a>
                            <a href="?delete=<?php echo $s['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this staff member?')">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Staff Form -->
    <div class="admin-panel">
        <div class="admin-panel-header"><h3><i class="fas fa-user-plus" style="color:var(--secondary-color)"></i> &nbsp;Add Staff Member</h3></div>
        <div class="admin-panel-body">
            <form method="POST">
                <div class="form-group"><label>Full Name *</label><input type="text" name="name" required placeholder="Staff full name"></div>
                <div class="form-group"><label>Designation</label><input type="text" name="designation" placeholder="e.g. Senior Teacher"></div>
                <div class="form-group"><label>Department</label>
                    <select name="department">
                        <option value="">Select Department</option>
                        <option>Science</option><option>Mathematics</option><option>Commerce</option>
                        <option>Humanities</option><option>Languages</option><option>Physical Education</option>
                        <option>Arts</option><option>Administration</option><option>Support Staff</option>
                    </select>
                </div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" placeholder="Contact number"></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="Email address"></div>
                <button type="submit" name="add_staff" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-plus"></i> Add Staff Member
                </button>
            </form>
        </div>
    </div>
</div>

</div></div></div>
<?php include('../includes/admin_footer.php'); ?>
