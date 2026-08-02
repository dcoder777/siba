<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (admin_user()) {
    header('Location: index.php');
    exit;
}

// Auto-migrate table
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(15),
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    owner_note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '') {
        $error = 'Name is required.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid email is required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = $pdo->prepare("SELECT id FROM admin_registrations WHERE email = :email AND status = 'pending' LIMIT 1");
        $check->execute(['email' => $email]);
        if ($check->fetch()) {
            $error = 'You already have a pending registration request. Please wait for approval.';
        } else {
            $check2 = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $check2->execute(['email' => $email]);
            if ($check2->fetch()) {
                $error = 'This email is already registered. Please login.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO admin_registrations (name, email, phone, password_hash, status) VALUES (:name, :email, :phone, :password_hash, 'pending')");
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone ?: null,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $success = 'Registration request submitted! You will be able to login once the owner approves your account.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Registration — SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
</head>
<body class="page-shell">
<div class="content-wrap auth-shell">
    <section class="surface-card hero-card">
        <div class="hero-grid">
            <aside class="hero-panel stack" style="justify-content:center;grid-column:1/-1;max-width:440px;margin:0 auto;">
                <div class="stack" style="gap:.45rem">
                    <h2 style="font-size:1.6rem;text-align:center;">SIBA PUBLIC SCHOOL<br>ADMIN REGISTRATION</h2>
                    <p style="text-align:center;color:var(--text-light);font-size:.88rem;">Request access to the admin portal. Your account will be reviewed by the owner.</p>
                </div>

                <?php if ($success !== ''): ?>
                    <div class="flash" style="background:#e8f5e9;border-color:#a5d6a7;color:#2e7d32"><?= e($success) ?></div>
                    <a href="login.php" class="btn" style="width:100%;text-align:center;">Back to Login</a>
                <?php else: ?>
                    <form method="post" class="stack" style="margin-top:.8rem">
                        <div>
                            <label for="name">Full Name *</label>
                            <input id="name" type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="email">Email Address *</label>
                            <input id="email" type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="phone">Phone Number</label>
                            <input id="phone" type="tel" name="phone" maxlength="10" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="10-digit mobile number">
                        </div>
                        <div>
                            <label for="password">Password * <span style="font-weight:400;color:var(--text-light)">(min 6 chars)</span></label>
                            <input id="password" type="password" name="password" required>
                        </div>
                        <button class="btn" type="submit" style="width:100%">Submit Registration Request</button>
                        <?php if ($error !== ''): ?>
                            <div class="flash" style="background:#fdecea;border-color:#f3c8c5;color:#8f1c13"><?= e($error) ?></div>
                        <?php endif; ?>
                    </form>
                    <p style="text-align:center;margin-top:1rem;"><a href="login.php" style="color:var(--primary-color);font-weight:600;font-size:.88rem;">&larr; Back to Login</a></p>
                <?php endif; ?>
            </aside>
        </div>
    </section>
</div>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
</body>
</html>
