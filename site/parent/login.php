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
<div class="form-container" style="width: 100%; max-width: 440px; margin: 0;">

    <div style="text-align: center; margin-bottom: 2rem;">
        <img src="<?php echo SITE_LOGO_URL; ?>" alt="SIBA Public School Logo" class="brand-logo brand-logo-auth" style="margin: 0 auto 1rem;">
        <h2 style="font-size: 1.6rem; font-weight: 800; color: var(--primary-color);">Parent Portal Login</h2>
        <p style="color: var(--text-light); font-size: 0.9rem;">Access your child's admission status and fees</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label><i class="fas fa-mobile-alt"></i> &nbsp;Registered Phone Number</label>
            <input type="tel" name="phone" placeholder="Enter 10-digit phone number" maxlength="10"
                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required autofocus>
        </div>
        <div class="form-group">
            <label><i class="fas fa-lock"></i> &nbsp;Password</label>
            <div class="pw-wrap" style="position:relative;">
                <input type="password" name="password" id="pw" placeholder="Enter your password" required style="padding-right:2.5rem;">
                <i class="fas fa-eye pw-toggle" data-target="pw" style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--text-light);"></i>
            </div>
        </div>
        <script>
        document.querySelectorAll('.pw-toggle').forEach(function(el) {
            el.addEventListener('click', function() {
                var inp = document.getElementById(this.dataset.target);
                if (inp.type === 'password') {
                    inp.type = 'text';
                    this.classList.remove('fa-eye');
                    this.classList.add('fa-eye-slash');
                } else {
                    inp.type = 'password';
                    this.classList.remove('fa-eye-slash');
                    this.classList.add('fa-eye');
                }
            });
        });
        </script>
        <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 0.5rem;">
            <i class="fas fa-sign-in-alt"></i> Login to Portal
        </button>
    </form>

    <div style="text-align: center; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #eee;">
        <p style="font-size: 0.88rem; color: var(--text-light);">Don't have an account?</p>
        <a href="register.php" class="btn btn-accent" style="margin-top: 0.5rem; width: 100%;">
            <i class="fas fa-user-plus"></i> Register & Apply for Admission
        </a>
    </div>

    <p style="text-align: center; margin-top: 1rem; font-size: 0.8rem; color: var(--text-light);">
        <strong>Demo credentials:</strong> Phone: 1234567890 | Password: password
    </p>
</div>
</div>

<?php include('../includes/portal_footer.php'); ?>
