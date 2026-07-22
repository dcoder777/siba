<?php
$pageTitle = "Parent Login";
require_once('../includes/db_connect.php');

if (isset($_SESSION['parent_id'])) {
    header("Location: dashboard.php"); exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone    = $conn->real_escape_string(preg_replace('/\D/', '', $_POST['phone']));
    $password = $_POST['password'];
    $result   = $conn->query("SELECT * FROM parents WHERE phone = '$phone'");
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['parent_id'] = $user['id'];
            $_SESSION['phone']     = $user['phone'];
            header("Location: dashboard.php"); exit();
        }
    }
    $error = "Invalid phone number or password.";
}

include('../includes/portal_header.php');
?>

<div style="min-height: calc(100vh - 75px); background: var(--primary-color); display: flex; align-items: center; justify-content: center; padding: 2rem;">
<div class="form-container" style="width: 100%; max-width: 400px; margin: 0;">

    <div style="text-align: center; margin-bottom: 2rem;">
        <div style="font-size: 3.5rem; color: var(--secondary-color); margin-bottom: 0.5rem;"><i class="fas fa-user-circle"></i></div>
        <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--primary-color);">My Account</h2>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label><i class="fas fa-mobile-alt"></i></label>
            <input type="tel" name="phone" placeholder="Phone number" maxlength="10"
                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required autofocus>
        </div>
        <div class="form-group">
            <label><i class="fas fa-lock"></i></label>
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
            <i class="fas fa-sign-in-alt"></i> Login
        </button>
    </form>

    <div style="text-align: center; margin-top: 1rem;">
        <a href="register.php" style="font-size: 0.85rem; color: var(--secondary-color);">Register</a>
    </div>
    <p style="text-align: center; margin-top: 1rem; font-size: 0.75rem; color: var(--text-light);">
        Demo: 1234567890 / password
    </p>
</div>
</div>

<?php include('../includes/portal_footer.php'); ?>
