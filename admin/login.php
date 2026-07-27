<?php
$pageTitle = "Admin Login";
require_once('../includes/db_connect.php');

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php"); exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $password = $_POST['password'];
    $result   = $conn->query("SELECT * FROM admins WHERE username = '$username'");
    if ($result->num_rows > 0) {
        $admin = $result->fetch_assoc();
        if (password_verify($password, $admin['password'])) {
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            $_SESSION['admin_role'] = $admin['role'];
            header("Location: dashboard.php"); exit();
        }
    }
    $error = "Invalid username or password.";
}
include('../includes/admin_header.php');
?>

<div class="admin-login-page">
    <div class="admin-login-card">
        <div style="text-align:center; margin-bottom:2rem;">
            <img src="<?php echo SITE_LOGO_URL; ?>" alt="SIBA Public School Logo" class="brand-logo brand-logo-auth" style="margin:0 auto 1rem;">
            <h2 style="font-size:1.5rem; font-weight:800; color:var(--primary-color);">Admin Login</h2>
            <p style="color:var(--text-light); font-size:0.88rem;">SIBA Public School – Super Admin Panel</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-user"></i> &nbsp;Username</label>
                <input type="text" name="username" placeholder="Enter admin username" autofocus required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> &nbsp;Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%; margin-top:0.5rem;">
                <i class="fas fa-shield-alt"></i> Sign in to Admin Panel
            </button>
        </form>

        <p style="text-align:center; margin-top:1.5rem; font-size:0.8rem; color:var(--text-light);">
            <strong>Default:</strong> admin / password &nbsp;|&nbsp;
            <a href="<?php echo SITE_URL; ?>" style="color:var(--secondary-color);">← Back to Website</a>
        </p>
    </div>
</div>

<?php include('../includes/admin_footer.php'); ?>
