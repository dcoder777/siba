<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isSuperAdmin = ($user['role'] ?? '') === 'admin';
$isOwner = ($user['role'] ?? '') === 'owner';
$explicitModules = fetch_user_module_access($pdo, (int) $user['id']);
$userRoles = fetch_user_roles($pdo, (int) $user['id'], (string) ($user['role'] ?? 'admin'));
$menus = menu_for_roles($userRoles, $explicitModules);
$entityMap = entity_config();
$error = '';
$success = '';

$statusOptions = ['Application started', 'Under review', 'Admitted', 'Rejected'];
$currentStatus = trim((string) ($_GET['status'] ?? ''));
$searchQ = trim((string) ($_GET['q'] ?? ''));

// One-time: restore any soft-deleted applications
try { $pdo->exec("UPDATE applications SET deleted_at = NULL WHERE deleted_at IS NOT NULL"); } catch (\Throwable $e) {}

// ─── Delete Application (Permanent) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_app']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    if ($appId > 0) {
        try {
            $pdo->prepare("DELETE FROM applications WHERE id = :id")->execute(['id' => $appId]);
            $success = 'Application deleted permanently.';
        } catch (Exception $e) {
            $error = 'Failed to delete application: ' . $e->getMessage();
        }
    }
}

// ─── Restore Application ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_app']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    if ($appId > 0) {
        try {
            $pdo->prepare("UPDATE applications SET deleted_at = NULL WHERE id = :id")->execute(['id' => $appId]);
            $success = 'Application restored.';
        } catch (Exception $e) {
            $error = 'Failed to restore: ' . $e->getMessage();
        }
    }
}

// ─── Toggle Payment Status ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_payment']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    $newStatus = trim((string) ($_POST['payment_status'] ?? ''));
    if ($appId > 0 && in_array($newStatus, ['Pending', 'Paid'], true)) {
        try {
            $pdo->prepare("UPDATE applications SET payment_status = :status WHERE id = :id")->execute(['status' => $newStatus, 'id' => $appId]);
            $success = 'Payment status updated to ' . $newStatus . '.';
        } catch (Exception $e) {
            $error = 'Failed to update payment status: ' . $e->getMessage();
        }
    }
}

// ─── Fetch Applications ───
$where = [];
$params = [];
if ($currentStatus !== '') {
    $where[] = 'a.status = :status';
    $params['status'] = $currentStatus;
}
if ($searchQ !== '') {
    $where[] = '(a.student_name LIKE :q1 OR a.father_name LIKE :q2 OR a.mother_name LIKE :q3 OR p.name LIKE :q4 OR p.phone LIKE :q5 OR a.contact_no LIKE :q6)';
    $likeQ = '%' . $searchQ . '%';
    $params['q1'] = $likeQ;
    $params['q2'] = $likeQ;
    $params['q3'] = $likeQ;
    $params['q4'] = $likeQ;
    $params['q5'] = $likeQ;
    $params['q6'] = $likeQ;
}
$whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

$debugInfo = '';
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM applications a LEFT JOIN parents p ON p.id = a.parent_id" . $whereSql);
    $countStmt->execute($params);
    $totalApps = (int) $countStmt->fetch()['c'];
} catch (\Throwable $e) {
    $totalApps = 0;
}

$page = max(1, (int) ($_GET['p'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$sql = "SELECT a.*, p.name AS parent_name, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id" . $whereSql . " ORDER BY a.applied_at DESC LIMIT :lim OFFSET :off";
$listStmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$applications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int) ceil($totalApps / $limit));

// ─── Status badge helper ───
function statusBadge(string $s): string {
    return match ($s) {
        'Application started' => '<span class="badge" style="background:#e2e8f0;color:#475569">Application started</span>',
        'Under review' => '<span class="badge" style="background:#fef3c7;color:#92400e">Under review</span>',
        'Admitted' => '<span class="badge" style="background:#d1fae5;color:#065f46">Admitted</span>',
        'Rejected' => '<span class="badge" style="background:#fee2e2;color:#991b1b">Rejected</span>',
        default => '<span class="badge">' . e($s) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Applications – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .app-filters { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; }
        .app-filters input, .app-filters select { padding:.45rem .7rem; border:1px solid #cbd5e1; border-radius:6px; font-size:.875rem; }
        .app-filters .btn { padding:.45rem 1rem; }
        .app-table { width:100%; table-layout:auto; border-collapse:collapse; font-size:.85rem; }
        .app-table thead th { text-align:left; padding:.55rem .65rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; position:sticky; top:0; }
        .app-table tbody td { padding:.6rem .65rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .app-table tbody tr:nth-child(even) td { background:#fafbfc; }
        .app-table tbody tr:hover td { background:#eff6ff; }
        .row-actions { display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; }
        .row-actions select { padding:.3rem .4rem; font-size:.78rem; border:1px solid #cbd5e1; border-radius:6px; background:#fff; min-height:auto; }
        .row-actions .btn-xs { padding:.3rem .65rem; font-size:.75rem; border-radius:6px; border:1px solid transparent; cursor:pointer; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; }
        .row-actions .btn-xs-save { background:#1e293b; color:#fff; }
        .row-actions .btn-xs-save:hover { background:#334155; }
        .row-links { display:flex; gap:.35rem; margin-top:.35rem; flex-wrap:wrap; }
        .row-links a, .row-links button { font-size:.75rem; padding:.25rem .55rem; border-radius:6px; text-decoration:none; border:1px solid transparent; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; }
        .row-links .link-view { background:#eff6ff; color:#2563eb; border-color:#bfdbfe; }
        .row-links .link-view:hover { background:#dbeafe; }
        .row-links .link-delete { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
        .row-links .link-delete:hover { background:#fee2e2; }
        .pagination { display:flex; gap:.5rem; align-items:center; margin-top:1rem; }
        .pagination a, .pagination span { padding:.35rem .7rem; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; font-size:.85rem; color:#334155; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#1e293b; color:#fff; border-color:#1e293b; }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <aside class="sidebar" style="display:flex;flex-direction:column;">
        <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
            <span class="eyebrow" style="background:rgba(255,255,255,.1);color:#effff5">SIBA ERP</span>
            <div class="brand-copy">
                <h2 style="font-size:1.7rem;color:#fff">Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
        </div>
        <div class="nav-group">
            <div class="nav-title">Admissions</div>
            <a class="nav-link" href="application-intake.php">
                <span class="sidebar-icon">📋</span><span>Application Intake</span><span class="nav-tag">New</span>
            </a>
            <a class="nav-link active" href="applications-list.php">
                <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag"><?= $totalApps ?></span>
            </a>
            <a class="nav-link" href="parents-list.php">
                <span class="sidebar-icon">👤</span><span>Parents</span>
            </a>
            <a class="nav-link" href="events-manager.php">
                <span class="sidebar-icon">📅</span><span>Events & News</span>
            </a>
            <a class="nav-link" href="gallery-manager.php">
                <span class="sidebar-icon">🖼</span><span>Gallery</span>
            </a>
            <a class="nav-link" href="enquiries.php">
                <span class="sidebar-icon">📩</span><span>Enquiries</span>
            </a>
        </div>

        <div class="nav-group">
            <div class="nav-title">Finance</div>
            <a class="nav-link" href="finance-dashboard.php">
                <span class="sidebar-icon">📊</span><span>Finance Dashboard</span>
            </a>
        </div>

        <?php if ($isOwner): ?>
        <div class="nav-group">
            <div class="nav-title">Administration</div>
            <?php $pendingAdminCount = 0; try { $pendingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'pending'")->fetchColumn(); } catch (\Throwable $e) {} ?>
            <a class="nav-link" href="admin-requests.php">
                <span class="sidebar-icon">🔑</span>
                <span>Admin Requests</span>
                <?php if ($pendingAdminCount > 0): ?>
                    <span class="nav-tag" style="background:#f59e0b;color:#fff;"><?= $pendingAdminCount ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <div class="nav-group" style="margin-top:auto;">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Admissions</span>
                    <h1>Manage Applications</h1>
                    <p>View, search, and update admission application statuses.</p>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="alert alert-error" style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="get" class="app-filters">
            <input type="text" name="q" placeholder="Search name, phone, parent..." value="<?= e($searchQ) ?>" style="min-width:220px;">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= e($s) ?>" <?= $currentStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="applications-list.php" class="btn btn-soft">Clear</a>
            <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $totalApps ?> application<?= $totalApps !== 1 ? 's' : '' ?></span>
        </form>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow-x:auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>App No</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Parent</th>
                        <th>Phone</th>
                        <th>Applied</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8;">No applications found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($applications as $i => $a): ?>
                                <tr>
                                <td style="color:#94a3b8;"><?= $offset + $i + 1 ?></td>
                                <td><code style="font-size:.8rem;"><?= e($a['application_no'] ?? '—') ?></code></td>
                                <td>
                                    <strong><?= e($a['student_name']) ?></strong>
                                    <?php if ($a['admission_no']): ?>
                                        <br><small style="color:#64748b;"><?= e($a['admission_no']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($a['class_sought']) ?></td>
                                <td><?= e($a['parent_name'] ?? '—') ?></td>
                                <td><?= e($a['parent_phone'] ?? $a['contact_no'] ?? '—') ?></td>
                                <td style="white-space:nowrap;"><?= date('d-m-Y', strtotime($a['applied_at'])) ?></td>
                                <td><?= statusBadge($a['status']) ?></td>
                                <td>
                                    <?php $payStatus = $a['payment_status'] ?? 'Pending'; ?>
                                    <form method="post" onsubmit="return confirm('Update payment for <?= e($a['student_name']) ?>?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="app_id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="toggle_payment" value="1">
                                        <div class="row-actions">
                                            <select name="payment_status" title="Payment">
                                                <option value="Pending" <?= $payStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="Paid" <?= $payStatus === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                            </select>
                                            <button type="submit" class="btn-xs btn-xs-save">Save</button>
                                        </div>
                                    </form>
                                    <div class="row-links">
                                        <a href="application-view.php?app_id=<?= (int) $a['id'] ?>" class="link-view">View</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete <?= e($a['application_no'] ?? '#' . $a['id']) ?>? This cannot be undone.')">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="app_id" value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="delete_app" value="1">
                                            <button type="submit" class="link-delete">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page - 1])) ?>">‹ Prev</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['p' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page + 1])) ?>">Next ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/erp.js"></script>
</body>
</html>
