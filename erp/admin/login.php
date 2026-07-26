<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (admin_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.password_hash, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['admin_user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role_name'],
        ];
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid email or password';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIBA ERP Admin Login</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
</head>
<body class="page-shell">
<div class="content-wrap auth-shell">
    <section class="surface-card hero-card">
        <div class="hero-grid">
            <aside class="hero-panel stack" style="justify-content:center;grid-column:1/-1;max-width:440px;margin:0 auto;">
                <div class="stack" style="gap:.45rem">
                    <h2 style="font-size:1.6rem;text-align:center;">SIBA PUBLIC SCHOOL<br>MANAGEMENT SYSTEM</h2>
                </div>

                <form method="post" class="stack" style="margin-top:.8rem">
                    <div>
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" required>
                    </div>
                    <div>
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" required>
                    </div>
                    <button class="btn" type="submit" style="width:100%">Login to Dashboard</button>
                    <?php if ($error !== ''): ?>
                        <div class="flash" style="background:#fdecea;border-color:#f3c8c5;color:#8f1c13"><?= e($error) ?></div>
                    <?php endif; ?>
                </form>
            </aside>
        </div>
    </section>
</div>
</body>
</html>
