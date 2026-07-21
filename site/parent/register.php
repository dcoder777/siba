<?php
$pageTitle = "Register – Parent Portal";
require_once('../includes/db_connect.php');

if (isset($_SESSION['parent_id'])) {
    header("Location: dashboard.php"); exit();
}

// Ensure name & email columns exist (run once)
$cols = $conn->query("SHOW COLUMNS FROM parents LIKE 'name'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE parents ADD COLUMN name VARCHAR(100) AFTER id");
    $conn->query("ALTER TABLE parents ADD COLUMN email VARCHAR(100) AFTER name");
}

$error = '';
$success = '';

// Send OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_otp'])) {
    $phone = preg_replace('/\D/', '', $_POST['phone']);
    if (strlen($phone) !== 10) {
        $error = "Please enter a valid 10-digit phone number.";
    } else {
        $check = $conn->query("SELECT id FROM parents WHERE phone = '$phone'");
        if ($check->num_rows > 0) {
            $error = "This phone number is already registered. <a href='login.php'>Login here</a>.";
        } else {
            $_SESSION['reg_phone'] = $phone;
            $_SESSION['reg_otp']   = '123456';
            $_SESSION['reg_name']  = $_POST['name'] ?? '';
            $_SESSION['reg_email'] = $_POST['email'] ?? '';
            $success = "OTP sent to +91 $phone (Demo OTP: 123456)";
        }
    }
}

// Resend OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['resend_otp'])) {
    if (!empty($_SESSION['reg_phone'])) {
        $_SESSION['reg_otp'] = '123456';
        $success = "OTP resent to +91 {$_SESSION['reg_phone']} (Demo OTP: 123456)";
    }
}

// Create account
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_account'])) {
    $name       = trim($_POST['name']);
    $email      = trim($_POST['email']);
    $phone      = preg_replace('/\D/', '', $_POST['phone']);
    $otp        = trim($_POST['otp']);
    $password   = $_POST['password'];
    $password2  = $_POST['password2'];
    $accept     = isset($_POST['accept_terms']) ? 1 : 0;

    if (empty($name)) {
        $error = "Please enter your name.";
    } elseif (strlen($phone) !== 10) {
        $error = "Please enter a valid 10-digit phone number.";
    } elseif (empty($otp)) {
        $error = "Please enter the OTP.";
    } elseif ($otp !== ($_SESSION['reg_otp'] ?? '') && $otp !== '123456') {
        $error = "Invalid OTP. Please try again. (Hint: use 123456)";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $password2) {
        $error = "Passwords do not match.";
    } elseif (!$accept) {
        $error = "You must accept the Terms & Conditions.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt   = $conn->prepare("INSERT INTO parents (name, email, phone, password) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $email, $phone, $hashed);
        if ($stmt->execute()) {
            $parent_id = $conn->insert_id;

            // Create ERP user record for unified auth
            $role_q = $conn->query("SELECT id FROM roles WHERE name = 'parent' LIMIT 1");
            if ($role_q && $role = $role_q->fetch_assoc()) {
                $erp_email = $email ?: 'parent_' . $phone . '@siba.local';
                $conn->query("INSERT INTO users (role_id, name, email, password_hash, is_active)
                    VALUES ({$role['id']}, '" . $conn->real_escape_string($name) . "', '" . $conn->real_escape_string($erp_email) . "', '$hashed', 1)");
                $erp_user_id = $conn->insert_id;
                $conn->query("UPDATE parents SET user_id = $erp_user_id WHERE id = $parent_id");
            }

            $_SESSION['parent_id'] = $parent_id;
            $_SESSION['phone']     = $phone;
            $_SESSION['parent_name'] = $name;
            unset($_SESSION['reg_step'], $_SESSION['reg_otp'], $_SESSION['reg_phone'], $_SESSION['reg_name'], $_SESSION['reg_email']);
            header("Location: apply.php"); exit();
        } else {
            $error = "Could not create account. Please try again.";
        }
    }
}

include('../includes/portal_header.php');
?>

<style>
.reg-wrap {
    min-height: calc(100vh - 75px);
    background: var(--bg-color);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}
.reg-card {
    width: 100%;
    max-width: 480px;
    margin: 0;
}
.reg-card h2 {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--primary-color);
}
.reg-card .sub {
    color: var(--text-light);
    font-size: 0.9rem;
}
</style>

<div class="reg-wrap">
<div class="reg-card">

    <div style="text-align: center; margin-bottom: 2rem;">
        <img src="<?php echo SITE_LOGO_URL; ?>" alt="SIBA Public School Logo" class="brand-logo brand-logo-auth" style="margin: 0 auto 1rem;">
        <h2>Create Your Account</h2>
        <p class="sub">Register to apply for your child's admission</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label><i class="fas fa-user"></i> &nbsp;Name</label>
            <input type="text" name="name" placeholder="Your full name" required
                   value="<?php echo htmlspecialchars($_POST['name'] ?? $_SESSION['reg_name'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label><i class="fas fa-envelope"></i> &nbsp;Email <span style="color:var(--text-light);font-weight:400;">(Optional)</span></label>
            <input type="email" name="email" placeholder="your@email.com"
                   value="<?php echo htmlspecialchars($_POST['email'] ?? $_SESSION['reg_email'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label><i class="fas fa-mobile-alt"></i> &nbsp;Phone No</label>
            <div style="display:flex;gap:0.5rem;">
                <input type="tel" name="phone" placeholder="10-digit mobile number" maxlength="10" required
                       value="<?php echo htmlspecialchars($_POST['phone'] ?? $_SESSION['reg_phone'] ?? ''); ?>"
                       style="flex:1;">
                <button type="submit" name="send_otp" class="btn btn-primary" style="white-space:nowrap;padding:0.6rem 1rem;">
                    <i class="fas fa-paper-plane"></i> Send OTP
                </button>
            </div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-key"></i> &nbsp;OTP</label>
            <div style="display:flex;gap:0.5rem;">
                <input type="text" name="otp" placeholder="Enter 6-digit OTP" maxlength="6"
                       style="flex:1;letter-spacing:0.3em;text-align:center;">
                <button type="submit" name="resend_otp" class="btn btn-outline-primary" style="white-space:nowrap;padding:0.6rem 0.8rem;font-size:0.8rem;">
                    <i class="fas fa-redo"></i> Resend
                </button>
            </div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-lock"></i> &nbsp;Password</label>
            <div class="pw-wrap" style="position:relative;">
                <input type="password" name="password" id="pw1" placeholder="Minimum 6 characters" required style="padding-right:2.5rem;">
                <i class="fas fa-eye pw-toggle" data-target="pw1" style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--text-light);"></i>
            </div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-lock"></i> &nbsp;Retype Password</label>
            <div class="pw-wrap" style="position:relative;">
                <input type="password" name="password2" id="pw2" placeholder="Re-enter your password" required style="padding-right:2.5rem;">
                <i class="fas fa-eye pw-toggle" data-target="pw2" style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--text-light);"></i>
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

        <div class="form-group" style="margin-bottom:1.5rem;">
            <label class="checkbox-label" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:500;">
                <input type="checkbox" name="accept_terms" value="1" style="width:auto;height:auto;">
                I accept the <a href="<?php echo SITE_URL; ?>/privacy-policy.php" target="_blank" style="color:var(--secondary-color);font-weight:600;">Terms &amp; Conditions</a>
            </label>
        </div>

        <button type="submit" name="create_account" class="btn btn-primary btn-lg" style="width:100%;">
            <i class="fas fa-user-check"></i> Create Account
        </button>
    </form>

    <p style="text-align: center; margin-top: 1.5rem; font-size: 0.88rem; color: var(--text-light);">
        Already have an account? <a href="login.php" style="color: var(--secondary-color); font-weight: 600;">Login here</a>
    </p>
</div>
</div>

<?php include('../includes/portal_footer.php'); ?>
