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

// ─── Fetch roles for dropdown ───
$allRoles = [];
try {
    $allRoles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ─── Handle POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {

    // Update delete permissions
    if ($action === 'update_permissions') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $deleteModules = $_POST['can_delete'] ?? [];

        if ($targetUserId > 0) {
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

    // Edit user
    if ($action === 'edit_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($targetUserId <= 0) {
            $error = 'Invalid user.';
        } elseif ($name === '' || $email === '') {
            $error = 'Name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif ($roleId <= 0) {
            $error = 'Please select a valid role.';
        } else {
            // Prevent owner from changing their own role
            $targetRole = $pdo->prepare('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
            $targetRole->execute([$targetUserId]);
            $oldRole = $targetRole->fetchColumn() ?: '';
            $newRole = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
            $newRole->execute([$roleId]);
            $newRoleName = $newRole->fetchColumn() ?: '';

            if ($oldRole === 'owner' && $newRoleName !== 'owner') {
                $error = 'Cannot change the owner role.';
            } else {
                // Check email uniqueness
                $emailCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?');
                $emailCheck->execute([$email, $targetUserId]);
                if ((int) $emailCheck->fetchColumn() > 0) {
                    $error = 'Email address is already in use by another user.';
                } else {
                    $pdo->prepare('UPDATE users SET name=?, email=?, role_id=?, is_active=?, updated_at=NOW() WHERE id=?')
                        ->execute([$name, $email, $roleId, $isActive, $targetUserId]);
                    $success = 'User "' . e($name) . '" updated successfully.';
                }
            }
        }
    }

    // Delete user
    if ($action === 'delete_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $error = 'Invalid user.';
        } elseif ($targetUserId === (int) $user['id']) {
            $error = 'You cannot delete your own account.';
        } else {
            $targetRole = $pdo->prepare('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
            $targetRole->execute([$targetUserId]);
            $roleName = $targetRole->fetchColumn() ?: '';

            if ($roleName === 'owner') {
                $error = 'Cannot delete the owner account.';
            } else {
                // Get user name for flash
                $nameRow = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                $nameRow->execute([$targetUserId]);
                $deletedName = $nameRow->fetchColumn() ?: 'User';

                // Delete related records first
                $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$targetUserId]);
                $pdo->prepare('DELETE FROM user_role_assignments WHERE user_id = ?')->execute([$targetUserId]);
                $pdo->prepare('DELETE FROM user_module_access WHERE user_id = ?')->execute([$targetUserId]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetUserId]);

                $success = 'User "' . e($deletedName) . '" has been deleted.';
            }
        }
    }
}

// ─── Fetch all users ───
$allUsers = [];
try {
    $stmt = $pdo->query("SELECT u.id, u.name, u.email, u.is_active, u.role_id, r.name AS role_name
        FROM users u JOIN roles r ON r.id = u.role_id
        ORDER BY r.name, u.name");
    $allUsers = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'Could not load users: ' . $e->getMessage();
}

$modules = available_modules();

// ─── Edit mode ───
$editUser = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT u.id, u.name, u.email, u.is_active, u.role_id, r.name AS role_name
            FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
        $stmt->execute([$editId]);
        $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
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
        .perm-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
        .perm-card { background:#fff; border-radius:12px; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.08); border:1px solid #e2e8f0; position:relative; }
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
        .card-actions { position:absolute; top:1rem; right:1rem; display:flex; gap:.35rem; }
        .card-actions button, .card-actions a { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px solid #e2e8f0; background:#fff; border-radius:6px; cursor:pointer; font-size:.85rem; color:#64748b; text-decoration:none; transition:all .15s; }
        .card-actions button:hover, .card-actions a:hover { background:#f1f5f9; color:#2563eb; border-color:#2563eb; }
        .card-actions .btn-del:hover { background:#fee2e2; color:#ef4444; border-color:#ef4444; }
        .modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
        .modal-backdrop.show { display:flex; }
        .modal-box { background:#fff; border-radius:14px; width:100%; max-width:480px; box-shadow:0 20px 60px rgba(0,0,0,.2); }
        .modal-head { padding:1.25rem 1.5rem; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; }
        .modal-head h2 { font-size:1.1rem; margin:0; }
        .modal-body { padding:1.5rem; }
        .form-row { margin-bottom:1rem; }
        .form-row label { display:block; font-size:.82rem; font-weight:600; color:#475569; margin-bottom:.3rem; }
        .form-row input, .form-row select { width:100%; padding:.5rem .75rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.875rem; }
        .form-row input:focus, .form-row select:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
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
                    <p>Edit, delete users and control their delete permissions per module.</p>
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
            <strong>How it works:</strong> Use <em>Edit</em> to change a user's name, email, role or status. Use <em>Delete</em> to permanently remove a user. Toggle <em>Can Delete</em> for each module to control what records they can delete. Owner always has full access.
        </div>

        <?php if (empty($allUsers)): ?>
            <div style="text-align:center;padding:3rem;color:#94a3b8;">No users found.</div>
        <?php else: ?>
            <div class="perm-grid">
                <?php foreach ($allUsers as $u): ?>
                    <?php
                    $uid = (int) $u['id'];
                    $isTargetOwner = $u['role_name'] === 'owner';
                    $isSelf = $uid === (int) $user['id'];
                    $perms = $isTargetOwner ? [] : fetch_user_permissions($pdo, $uid);
                    ?>
                    <div class="perm-card <?= !$u['is_active'] ? 'inactive' : '' ?>">
                        <!-- Edit / Delete buttons -->
                        <?php if (!$isTargetOwner): ?>
                        <div class="card-actions">
                            <a href="?edit=<?= $uid ?>" title="Edit user">&#9998;</a>
                            <?php if (!$isSelf): ?>
                            <button type="button" class="btn-del" title="Delete user" onclick="if(confirm('Delete user <?= e(addslashes($u['name'])) ?>? This cannot be undone.'))document.getElementById('delForm_<?= $uid ?>').submit();">&#128465;</button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

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

                        <!-- Hidden delete form -->
                        <?php if (!$isTargetOwner && !$isSelf): ?>
                        <form id="delForm_<?= $uid ?>" method="post" style="display:none;">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                        </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Edit User Modal -->
<div id="editModal" class="modal-backdrop <?= $editUser ? 'show' : '' ?>">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Edit User</h2>
            <a href="user-management.php" style="font-size:1.3rem;color:#94a3b8;text-decoration:none;line-height:1;">&times;</a>
        </div>
        <?php if ($editUser): ?>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">

                <div class="form-row">
                    <label for="edit_name">Full Name</label>
                    <input type="text" id="edit_name" name="name" value="<?= e($editUser['name']) ?>" required>
                </div>
                <div class="form-row">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" value="<?= e($editUser['email']) ?>" required>
                </div>
                <div class="form-row">
                    <label for="edit_role">Role</label>
                    <select id="edit_role" name="role_id" required>
                        <?php foreach ($allRoles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>" <?= (int) $editUser['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= e(ucfirst($role['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row" style="display:flex;align-items:center;gap:.75rem;">
                    <label for="edit_active" style="margin:0;">Active</label>
                    <div class="toggle">
                        <input type="checkbox" id="edit_active" name="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </div>
                </div>

                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.5rem;">
                    <a href="user-management.php" style="padding:.5rem 1rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;color:#475569;text-decoration:none;">Cancel</a>
                    <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:.5rem 1.25rem;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Save Changes</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script src="../assets/erp.js?v=<?= filemtime(dirname(__DIR__) . '/assets/erp.js') ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
