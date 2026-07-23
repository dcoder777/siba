<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isSuperAdmin = ($user['role'] ?? '') === 'admin';
$explicitModules = fetch_user_module_access($pdo, (int) $user['id']);
$userRoles = fetch_user_roles($pdo, (int) $user['id'], (string) ($user['role'] ?? 'admin'));
$menus = menu_for_roles($userRoles, $explicitModules);
$entityMap = entity_config();
$error = '';
$success = '';

$classOptions = ['Nursery', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10'];
$statusOptions = ['Application started', 'Under review', 'Admitted', 'Rejected'];
$currentStatus = trim((string) ($_GET['status'] ?? ''));
$searchQ = trim((string) ($_GET['q'] ?? ''));

// ─── Update Status ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    $newStatus = trim((string) ($_POST['status'] ?? ''));
    if ($appId > 0 && in_array($newStatus, $statusOptions, true)) {
        try {
            $stmt = $pdo->prepare("UPDATE applications SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $appId]);

            if ($newStatus === 'Admitted') {
                $appStmt = $pdo->prepare("SELECT a.*, p.name AS parent_name, p.phone AS parent_phone FROM applications a JOIN parents p ON p.id = a.parent_id WHERE a.id = :id");
                $appStmt->execute(['id' => $appId]);
                $app = $appStmt->fetch(PDO::FETCH_ASSOC);
                if ($app && empty($app['student_id'])) {
                    $nameParts = explode(' ', trim($app['student_name']), 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? '';

                    $cntStmt = $pdo->query("SELECT COUNT(*) AS cnt FROM students");
                    $admissionNo = sprintf("ADM%04d", ((int) $cntStmt->fetch()['cnt']) + 1);

                    $addrParts = array_filter([$app['address_line1'], $app['address_line2'], $app['village_city'] ?? $app['district'], $app['state'], $app['pin']]);
                    $addr = implode(', ', $addrParts);

                    $insStmt = $pdo->prepare("INSERT INTO students (admission_no, first_name, last_name, gender, dob, blood_group, phone, email, address) VALUES (:admission_no, :first_name, :last_name, :gender, :dob, :blood_group, :phone, :email, :address)");
                    $insStmt->execute([
                        'admission_no' => $admissionNo,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'gender' => $app['gender'],
                        'dob' => $app['dob'],
                        'blood_group' => $app['blood_group'],
                        'phone' => $app['contact_no'],
                        'email' => $app['email'],
                        'address' => $addr,
                    ]);
                    $studentId = (int) $pdo->lastInsertId();

                    $sessionStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'academic_year' LIMIT 1");
                    $sessionStmt->execute();
                    $sessionLabel = $sessionStmt->fetchColumn() ?: date('Y') . '-' . (date('y') + 1);

                    $enrollStmt = $pdo->prepare("INSERT INTO student_enrollments (student_id, class_name, session_label, status, is_current) VALUES (:student_id, :class_name, :session_label, 'active', 1)");
                    $enrollStmt->execute([
                        'student_id' => $studentId,
                        'class_name' => $app['class_sought'],
                        'session_label' => $sessionLabel,
                    ]);

                    $updStmt = $pdo->prepare("UPDATE applications SET student_id = :student_id, admission_no = :admission_no WHERE id = :id");
                    $updStmt->execute(['student_id' => $studentId, 'admission_no' => $admissionNo, 'id' => $appId]);
                }
            }

            $success = 'Application status updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    }
}

// ─── Delete Application ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_app']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    if ($appId > 0) {
        try {
            $pdo->prepare("DELETE FROM fees WHERE application_id = :id")->execute(['id' => $appId]);
            $pdo->prepare("DELETE FROM applications WHERE id = :id")->execute(['id' => $appId]);
            $success = 'Application deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete application: ' . $e->getMessage();
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

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM applications a LEFT JOIN parents p ON p.id = a.parent_id" . $whereSql);
$countStmt->execute($params);
$totalApps = (int) $countStmt->fetch()['c'];

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
        .app-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .app-table th { text-align:left; padding:.65rem .5rem; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; }
        .app-table td { padding:.65rem .5rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
        .app-table tr:hover td { background:#f8fafc; }
        .pagination { display:flex; gap:.5rem; align-items:center; margin-top:1rem; }
        .pagination a, .pagination span { padding:.35rem .7rem; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; font-size:.85rem; color:#334155; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#1e293b; color:#fff; border-color:#1e293b; }
        .inline-status-form { display:flex; gap:.35rem; align-items:center; }
        .inline-status-form select { padding:.25rem .4rem; font-size:.8rem; border:1px solid #cbd5e1; border-radius:4px; }
        .inline-status-form .btn-sm { padding:.25rem .6rem; font-size:.8rem; }
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
            <div class="nav-title">Core</div>
            <a class="nav-link" href="index.php">
                <span class="sidebar-icon">◫</span><span>Main Dashboard</span><span class="nav-tag">Overview</span>
            </a>
            <?php if ($isSuperAdmin): ?>
                <a class="nav-link" href="index.php?view=user-access">
                    <span class="sidebar-icon">⚙</span><span>User Access</span><span class="nav-tag">Control</span>
                </a>
            <?php endif; ?>
        </div>

        <div class="nav-group">
            <div class="nav-title">Admissions</div>
            <a class="nav-link" href="application-intake.php">
                <span class="sidebar-icon">📋</span><span>Application Intake</span><span class="nav-tag">New</span>
            </a>
            <a class="nav-link active" href="applications-list.php">
                <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag"><?= $totalApps ?></span>
            </a>
        </div>

        <?php foreach ($menus as $menuKey => $menu): ?>
            <div class="nav-group">
                <div class="nav-title"><?= e((string) $menu['label']) ?></div>
                <a class="nav-link" href="index.php?view=module&amp;module=<?= e((string) $menuKey) ?>">
                    <span class="sidebar-icon">▣</span>
                    <span><?= e((string) $menu['label']) ?> Dashboard</span>
                    <span class="nav-tag"><?= count($menu['entities'] ?? []) ?> views</span>
                </a>
                <?php foreach (($menu['entities'] ?? []) as $menuEntity): ?>
                    <a class="nav-link" href="index.php?module=<?= e((string) $menuKey) ?>&amp;entity=<?= e((string) $menuEntity) ?>">
                        <span class="sidebar-icon">•</span>
                        <span><?= e((string) $entityMap[$menuEntity]['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

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

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:auto;">
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
                                <td><code style="font-size:.85rem;"><?= e($a['application_no'] ?? '—') ?></code></td>
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
                                    <form method="post" class="inline-status-form" onsubmit="return confirm('Update status & payment for <?= e($a['student_name']) ?>?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="app_id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="toggle_payment" value="1">
                                        <select name="status" title="Application status" style="padding:.25rem .4rem;font-size:.8rem;border:1px solid #cbd5e1;border-radius:4px;">
                                            <?php foreach ($statusOptions as $s): ?>
                                                <option value="<?= e($s) ?>" <?= $a['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="payment_status" title="Payment status" style="padding:.25rem .4rem;font-size:.8rem;border:1px solid #cbd5e1;border-radius:4px;">
                                            <option value="Pending" <?= $payStatus === 'Pending' ? 'selected' : '' ?>>💳 Pending</option>
                                            <option value="Paid" <?= $payStatus === 'Paid' ? 'selected' : '' ?>>✅ Paid</option>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm" style="padding:.25rem .6rem;font-size:.8rem;">Save</button>
                                    </form>
                                    <div style="margin-top:.3rem;display:flex;gap:.6rem;">
                                        <a href="application-view.php?app_id=<?= (int) $a['id'] ?>" style="color:#2563eb;font-size:.78rem;text-decoration:underline;">View</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete application <?= e($a['application_no'] ?? '#' . $a['id']) ?>? This cannot be undone.')">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="app_id" value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="delete_app" value="1">
                                            <button type="submit" class="btn btn-sm" style="background:none;border:none;color:#dc2626;font-size:.78rem;cursor:pointer;padding:0;text-decoration:underline;">Delete</button>
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
</body>
</html>
