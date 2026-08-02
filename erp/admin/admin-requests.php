<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';

if (!$isOwner) {
    http_response_code(403);
    echo 'Forbidden — only the Owner can manage admin requests.';
    exit;
}

$error = '';
$success = '';

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

// Handle Approve / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $reqAction = trim((string) ($_POST['req_action'] ?? ''));
    $reqId = (int) ($_POST['req_id'] ?? 0);
    $note = trim((string) ($_POST['owner_note'] ?? ''));

    if ($reqId > 0 && $reqAction === 'approve') {
        $row = $pdo->prepare("SELECT * FROM admin_registrations WHERE id = :id AND status = 'pending' LIMIT 1");
        $row->execute(['id' => $reqId]);
        $reg = $row->fetch();
        if ($reg) {
            try {
                $pdo->beginTransaction();

                $adminRole = $pdo->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetch();
                if (!$adminRole) {
                    throw new \RuntimeException('Admin role not found.');
                }
                $roleId = (int) $adminRole['id'];

                $stmt = $pdo->prepare("INSERT INTO users (role_id, name, email, password_hash, is_active, created_at, updated_at) VALUES (:role_id, :name, :email, :password_hash, 1, NOW(), NOW())");
                $stmt->execute([
                    'role_id' => $roleId,
                    'name' => $reg['name'],
                    'email' => $reg['email'],
                    'password_hash' => $reg['password_hash'],
                ]);
                $userId = (int) $pdo->lastInsertId();

                $pdo->prepare("INSERT IGNORE INTO user_role_assignments (user_id, role_id, is_active, created_at, updated_at) VALUES (:user_id, :role_id, 1, NOW(), NOW())")
                    ->execute(['user_id' => $userId, 'role_id' => $roleId]);

                $pdo->prepare("UPDATE admin_registrations SET status = 'approved', owner_note = :note, updated_at = NOW() WHERE id = :id")
                    ->execute(['note' => $note ?: null, 'id' => $reqId]);

                $pdo->commit();
                $success = "Approved: {$reg['name']} ({$reg['email']}). They can now login.";
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to approve: ' . $e->getMessage();
            }
        } else {
            $error = 'Registration request not found or already processed.';
        }
    }

    if ($reqId > 0 && $reqAction === 'reject') {
        $stmt = $pdo->prepare("UPDATE admin_registrations SET status = 'rejected', owner_note = :note, updated_at = NOW() WHERE id = :id AND status = 'pending'");
        $stmt->execute(['note' => $note ?: null, 'id' => $reqId]);
        if ($stmt->rowCount() > 0) {
            $success = 'Registration request rejected.';
        } else {
            $error = 'Request not found or already processed.';
        }
    }
}

$filter = trim((string) ($_GET['filter'] ?? 'pending'));
$allowedFilters = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'pending';

if ($filter === 'all') {
    $rows = $pdo->query("SELECT * FROM admin_registrations ORDER BY FIELD(status, 'pending', 'approved', 'rejected'), created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM admin_registrations WHERE status = :status ORDER BY created_at DESC");
    $stmt->execute(['status' => $filter]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$counts = [
    'pending'   => (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'pending'")->fetchColumn(),
    'approved'  => (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'approved'")->fetchColumn(),
    'rejected'  => (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'rejected'")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Requests — SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .req-table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .req-table th { text-align:left; padding:.6rem .75rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        .req-table td { padding:.65rem .75rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .req-table tr:hover td { background:#f8fafc; }
        .badge-pending { background:#fef3c7; color:#92400e; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-approved { background:#d1fae5; color:#065f46; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-rejected { background:#fee2e2; color:#991b1b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .tab-bar a { padding:.6rem 1.5rem; font-size:.9rem; font-weight:500; color:var(--text-light); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab-bar a.active { color:var(--primary-color); border-bottom-color:var(--primary-color); }
        .tab-bar a:hover { color:var(--primary-color); }
        .action-btns { display:flex; gap:.4rem; align-items:center; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Administration</span>
                    <h1>Admin Registration Requests</h1>
                    <p>Review and approve or reject admin registration requests.</p>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="tab-bar">
            <a href="?filter=pending" class="<?= $filter === 'pending' ? 'active' : '' ?>">Pending (<?= $counts['pending'] ?>)</a>
            <a href="?filter=approved" class="<?= $filter === 'approved' ? 'active' : '' ?>">Approved (<?= $counts['approved'] ?>)</a>
            <a href="?filter=rejected" class="<?= $filter === 'rejected' ? 'active' : '' ?>">Rejected (<?= $counts['rejected'] ?>)</a>
            <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All</a>
        </div>

        <section class="panel" style="padding:1.25rem;">
            <?php if (empty($rows)): ?>
                <p style="text-align:center;padding:2rem;color:var(--text-light);">No <?= $filter === 'all' ? '' : $filter ?> registration requests.</p>
            <?php else: ?>
                <table class="req-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th>Note</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td style="color:#94a3b8;"><?= $i++ ?></td>
                                <td><strong><?= e($r['name']) ?></strong></td>
                                <td><?= e($r['email']) ?></td>
                                <td><?= e($r['phone'] ?? '—') ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <span class="badge-pending">Pending</span>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <span class="badge-approved">Approved</span>
                                    <?php else: ?>
                                        <span class="badge-rejected">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;font-size:.83rem;"><?= date('d-M-Y H:i', strtotime($r['created_at'])) ?></td>
                                <td style="max-width:180px;font-size:.83rem;color:#64748b;"><?= e($r['owner_note'] ?? '—') ?></td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <div class="action-btns">
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Approve <?= e($r['name']) ?>? They will be able to login as Admin.')">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="req_action" value="approve">
                                                <input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" class="btn btn-sm" style="background:#059669;color:#fff;border:none;padding:.3rem .7rem;font-size:.78rem;">Approve</button>
                                            </form>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Reject <?= e($r['name']) ?>?')">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="req_action" value="reject">
                                                <input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>">
                                                <input type="text" name="owner_note" placeholder="Reason (optional)" style="width:120px;padding:.25rem .4rem;font-size:.75rem;border:1px solid #cbd5e1;border-radius:4px;">
                                                <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;padding:.3rem .7rem;font-size:.78rem;">Reject</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-size:.83rem;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
