<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pageTitle = 'User Management';

if (!$isOwner) {
    http_response_code(403);
    echo 'Only the owner can access User Management.';
    exit;
}

ensure_user_permissions_table($pdo);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$success = '';
$error = '';

// ─── Handle actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if ($action === 'update_permissions') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $deleteModules = $_POST['can_delete'] ?? [];

        if ($targetUserId > 0) {
            // Owner always has full permissions — skip
            $targetRole = $pdo->prepare('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
            $targetRole->execute([$targetUserId]);
            $roleName = $targetRole->fetchColumn() ?: '';

            if ($roleName === 'owner') {
                $error = 'Cannot modify permissions for the owner.';
            } else {
                set_user_permissions($pdo, $targetUserId, $deleteModules);
                $success = 'Delete permissions updated for user #' . $targetUserId . '.';
            }
        } else {
            $error = 'Invalid user.';
        }
    }
}

// ─── Fetch all users ───
$allUsers = [];
try {
    $stmt = $pdo->query("SELECT u.id, u.name, u.email, u.is_active, r.name AS role_name
        FROM users u JOIN roles r ON r.id = u.role_id
        ORDER BY r.name, u.name");
    $allUsers = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'Could not load users: ' . $e->getMessage();
}

$modules = available_modules();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
    <style>
        .perm-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
        .perm-card { background:#fff; border-radius:12px; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.08); border:1px solid #e2e8f0; }
        .perm-card h3 { font-size:1rem; margin:0 0 .15rem; color:#0f172a; }
        .perm-card .email { font-size:.8rem; color:#64748b; margin-bottom:.75rem; }
        .perm-card .role-badge { display:inline-block; background:#e0e7ff; color:#3730a3; font-size:.7rem; font-weight:600; padding:.15rem .5rem; border-radius:20px; text-transform:uppercase; margin-bottom:.75rem; }
        .perm-card .role-badge.owner { background:#fef3c7; color:#92400e; }
        .perm-card .inactive { opacity:.5; }
        .perm-row { display:flex; align-items:center; gap:.5rem; padding:.35rem 0; border-bottom:1px solid #f1f5f9; font-size:.85rem; }
        .perm-row:last-child { border-bottom:none; }
        .perm-row label { flex:1; cursor:pointer; }
        .toggle { position:relative; width:36px; height:20px; }
        .toggle input { opacity:0; width:0; height:0; }
        .toggle .slider { position:absolute; inset:0; background:#cbd5e1; border-radius:20px; cursor:pointer; transition:.2s; }
        .toggle .slider:before { content:''; position:absolute; width:16px; height:16px; left:2px; bottom:2px; background:#fff; border-radius:50%; transition:.2s; }
        .toggle input:checked + .slider { background:#2563eb; }
        .toggle input:checked + .slider:before { transform:translateX(16px); }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Administration</span>
                    <h1>User Management</h1>
                    <p>Control delete permissions for each user across all modules.</p>
                </div>
            </div>
        </section>

        <?php if ($success): ?>
            <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.9rem;"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.9rem;"><?= e($error) ?></div>
        <?php endif; ?>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;margin-bottom:1.5rem;font-size:.85rem;color:#475569;">
            <strong>How it works:</strong> Toggle <em>Can Delete</em> for each module per user. When disabled, that user cannot delete records from that module — the delete button will be hidden or blocked. Owner always has full access. Users not listed here have <strong>no delete permission</strong> by default.
        </div>

        <?php if (empty($allUsers)): ?>
            <div style="text-align:center;padding:3rem;color:#94a3b8;">No users found.</div>
        <?php else: ?>
            <div class="perm-grid">
                <?php foreach ($allUsers as $u): ?>
                    <?php
                    $uid = (int) $u['id'];
                    $isTargetOwner = $u['role_name'] === 'owner';
                    $perms = $isTargetOwner ? [] : fetch_user_permissions($pdo, $uid);
                    $activeModules = $isOwner ? array_keys($modules) : array_keys($modules);
                    ?>
                    <div class="perm-card <?= !$u['is_active'] ? 'inactive' : '' ?>">
                        <h3><?= e($u['name']) ?></h3>
                        <div class="email"><?= e($u['email']) ?></div>
                        <span class="role-badge <?= $isTargetOwner ? 'owner' : '' ?>"><?= e($u['role_name']) ?></span>
                        <?php if (!$u['is_active']): ?>
                            <span class="role-badge" style="background:#fee2e2;color:#991b1b;">Inactive</span>
                        <?php endif; ?>

                        <?php if ($isTargetOwner): ?>
                            <div style="font-size:.82rem;color:#94a3b8;padding:.5rem 0;">Owner has full access to all modules.</div>
                        <?php else: ?>
                            <form method="post" style="margin-top:.5rem;">
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_permissions">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">

                                <?php foreach ($modules as $key => $mod): ?>
                                    <?php $hasPerm = $perms[$key]['can_delete'] ?? false; ?>
                                    <div class="perm-row">
                                        <label for="del_<?= $uid ?>_<?= $key ?>"><?= e($mod['label']) ?></label>
                                        <div class="toggle">
                                            <input type="checkbox" id="del_<?= $uid ?>_<?= $key ?>" name="can_delete[]" value="<?= $key ?>" <?= $hasPerm ? 'checked' : '' ?> onchange="this.form.submit()">
                                            <span class="slider"></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/erp.js?v=<?= filemtime(dirname(__DIR__) . '/assets/erp.js') ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
