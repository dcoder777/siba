<?php
$pageTitle = "Settings";
require_once('../includes/db_connect.php');
include('../includes/admin_header.php');
include('../includes/admin_sidebar.php');

$success = '';
$error   = '';

// Ensure settings table
$conn->query("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Defaults
$defaults = [
    'site_name'       => 'SIBA Public School',
    'school_email'    => 'info@sibaschool.com',
    'school_phone'    => '+91 12345 67890',
    'school_address'  => '123 Education Lane, City, State - 700001',
    'admission_open'  => '1',
    'fee_amount'      => '5000',
    'academic_year'   => '2026-27',
];
foreach ($defaults as $k => $v) {
    $conn->query("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('$k','$v')");
}

// Save settings
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    foreach ($_POST as $key => $val) {
        if ($key === 'save_settings') continue;
        $k = $conn->real_escape_string($key);
        $v = $conn->real_escape_string($val);
        $conn->query("UPDATE settings SET setting_value='$v' WHERE setting_key='$k'");
    }
    $success = "Settings saved successfully.";
}

// Change admin password
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $oldPass  = $_POST['old_password'];
    $newPass  = $_POST['new_password'];
    $newPass2 = $_POST['new_password2'];
    $admin    = $conn->query("SELECT * FROM admins WHERE id='{$_SESSION['admin_id']}'")->fetch_assoc();
    if (!password_verify($oldPass, $admin['password'])) {
        $error = "Current password is incorrect.";
    } elseif (strlen($newPass) < 6) {
        $error = "New password must be at least 6 characters.";
    } elseif ($newPass !== $newPass2) {
        $error = "New passwords do not match.";
    } else {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $conn->query("UPDATE admins SET password='$hash' WHERE id='{$_SESSION['admin_id']}'");
        $success = "Password changed successfully.";
    }
}

// Load settings
$rawSettings = $conn->query("SELECT * FROM settings");
$settings = [];
while ($r = $rawSettings->fetch_assoc()) $settings[$r['setting_key']] = $r['setting_value'];
?>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:1.25rem;"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem;"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:1.5fr 1fr; gap:1.5rem;">

    <!-- General Settings -->
    <div class="admin-panel">
        <div class="admin-panel-header"><h3><i class="fas fa-cog" style="color:var(--secondary-color)"></i> &nbsp;School Settings</h3></div>
        <div class="admin-panel-body">
            <form method="POST">
                <div class="form-group"><label>School Name</label>
                    <input type="text" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>"></div>
                <div class="form-group"><label>School Email</label>
                    <input type="email" name="school_email" value="<?php echo htmlspecialchars($settings['school_email']); ?>"></div>
                <div class="form-group"><label>School Phone</label>
                    <input type="text" name="school_phone" value="<?php echo htmlspecialchars($settings['school_phone']); ?>"></div>
                <div class="form-group"><label>School Address</label>
                    <textarea name="school_address" rows="2"><?php echo htmlspecialchars($settings['school_address']); ?></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label>Academic Year</label>
                        <input type="text" name="academic_year" value="<?php echo htmlspecialchars($settings['academic_year']); ?>"></div>
                    <div class="form-group"><label>Monthly Fee (₹)</label>
                        <input type="number" name="fee_amount" value="<?php echo htmlspecialchars($settings['fee_amount']); ?>"></div>
                </div>
                <div class="form-group">
                    <label>Admissions Status</label>
                    <select name="admission_open">
                        <option value="1" <?php echo $settings['admission_open']=='1'?'selected':''; ?>>Open – Accepting Applications</option>
                        <option value="0" <?php echo $settings['admission_open']=='0'?'selected':''; ?>>Closed – Not Accepting</option>
                    </select>
                </div>
                <button type="submit" name="save_settings" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div style="display:flex; flex-direction:column; gap:1.5rem;">
        <div class="admin-panel">
            <div class="admin-panel-header"><h3><i class="fas fa-key" style="color:var(--secondary-color)"></i> &nbsp;Change Admin Password</h3></div>
            <div class="admin-panel-body">
                <form method="POST">
                    <div class="form-group"><label>Current Password</label>
                        <input type="password" name="old_password" required></div>
                    <div class="form-group"><label>New Password</label>
                        <input type="password" name="new_password" required placeholder="Min. 6 characters"></div>
                    <div class="form-group"><label>Confirm New Password</label>
                        <input type="password" name="new_password2" required></div>
                    <button type="submit" name="change_password" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-lock"></i> Change Password
                    </button>
                </form>
            </div>
        </div>

        <!-- System Info -->
        <div class="admin-panel">
            <div class="admin-panel-header"><h3><i class="fas fa-info-circle" style="color:var(--secondary-color)"></i> &nbsp;System Info</h3></div>
            <div class="admin-panel-body">
                <div class="info-row"><span class="key">PHP Version</span><span class="val"><?php echo PHP_VERSION; ?></span></div>
                <div class="info-row"><span class="key">Server</span><span class="val"><?php echo $_SERVER['SERVER_SOFTWARE']; ?></span></div>
                <div class="info-row"><span class="key">Database</span><span class="val">MySQL / MariaDB</span></div>
                <div class="info-row"><span class="key">Admin User</span><span class="val"><?php echo htmlspecialchars($_SESSION['admin_user']); ?></span></div>
                <div class="info-row"><span class="key">Last Login</span><span class="val"><?php echo date('d M Y, h:i A'); ?></span></div>
            </div>
        </div>
    </div>
</div>

</div></div></div>
<?php include('../includes/admin_footer.php'); ?>
