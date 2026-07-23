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

$appId = (int) ($_GET['app_id'] ?? 0);
if (!$appId) {
    header("Location: applications-list.php");
    exit();
}

$stmt = $pdo->prepare("SELECT a.*, p.name AS parent_name, p.email AS parent_email, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.id = :id");
$stmt->execute(['id' => $appId]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    header("Location: applications-list.php");
    exit();
}

$statusBadge = match ($app['status']) {
    'Application started' => '<span class="badge" style="background:#e2e8f0;color:#475569">Application started</span>',
    'Under review' => '<span class="badge" style="background:#fef3c7;color:#92400e">Under review</span>',
    'Admitted' => '<span class="badge" style="background:#d1fae5;color:#065f46">Admitted</span>',
    'Rejected' => '<span class="badge" style="background:#fee2e2;color:#991b1b">Rejected</span>',
    default => '<span class="badge">' . e($app['status']) . '</span>',
};

$payStatusBadge = ($app['payment_status'] ?? 'Pending') === 'Paid'
    ? '<span class="badge" style="background:#d1fae5;color:#065f46">Paid</span>'
    : '<span class="badge" style="background:#fef3c7;color:#92400e">Pending</span>';

$docs = [
    'Aadhaar Card' => $app['aadhaar'] ?? '',
    'Birth Certificate' => $app['birth_cert'] ?? '',
    'Leaving Certificate' => $app['leaving_cert'] ?? '',
    'Previous Marksheet' => $app['prev_marksheet'] ?? '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application #<?= e($app['application_no'] ?? (string) $appId) ?> – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        .detail-grid .full-col { grid-column:1 / -1; }
        .detail-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; }
        .detail-card .head { background:#f8fafc; padding:.75rem 1.25rem; font-weight:700; font-size:.95rem; border-bottom:1px solid #e2e8f0; color:#1e293b; }
        .detail-card .head i { margin-right:.5rem; color:#64748b; }
        .detail-card .body { padding:1rem 1.25rem; }
        .detail-row { display:flex; padding:.5rem 0; border-bottom:1px solid #f1f5f9; font-size:.875rem; }
        .detail-row:last-child { border-bottom:none; }
        .detail-row .lbl { width:40%; color:#64748b; flex-shrink:0; }
        .detail-row .val { width:60%; font-weight:500; color:#1e293b; }
        .doc-link { display:inline-block; padding:.25rem .75rem; background:#eaf4fb; color:#2563eb; border-radius:6px; font-size:.85rem; text-decoration:none; font-weight:600; }
        .doc-link:hover { background:#2563eb; color:#fff; }
        .photo-thumb { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid #e2e8f0; }
        @media (max-width:768px) { .detail-grid { grid-template-columns:1fr; } }
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
            <a class="nav-link" href="applications-list.php">
                <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag">List</span>
            </a>
            <a class="nav-link" href="parents-list.php">
                <span class="sidebar-icon">👤</span><span>Parents</span>
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
                    <h1>Application <?= e($app['application_no'] ?? '#' . $appId) ?></h1>
                    <p>Submitted on <?= date('d M Y, h:i A', strtotime($app['applied_at'])) ?> &middot; <?= $statusBadge ?> &middot; Payment: <?= $payStatusBadge ?></p>
                </div>
                <div style="display:flex;gap:.75rem;">
                    <a href="applications-list.php" class="btn btn-soft"><i class="fas fa-arrow-left"></i> Back to List</a>
                </div>
            </div>
        </section>

        <div class="detail-grid">
            <!-- Student Info -->
            <div class="detail-card">
                <div class="head"><i class="fas fa-child"></i> Student Information</div>
                <div class="body">
                    <?php if ($app['photo']): ?>
                        <div style="text-align:center;margin-bottom:.75rem;">
                            <img src="../../site/uploads/docs/<?= rawurlencode($app['photo']) ?>" alt="Photo" class="photo-thumb">
                        </div>
                    <?php endif; ?>
                    <div class="detail-row"><span class="lbl">Full Name</span><span class="val"><?= e($app['student_name']) ?></span></div>
                    <div class="detail-row"><span class="lbl">Date of Birth</span><span class="val"><?= e($app['dob']) ?></span></div>
                    <div class="detail-row"><span class="lbl">Gender</span><span class="val"><?= e($app['gender'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Religion</span><span class="val"><?= e($app['religion'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Blood Group</span><span class="val"><?= e($app['blood_group'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Aadhaar No.</span><span class="val"><?= e($app['aadhaar_no'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Previous School</span><span class="val"><?= e($app['previous_school'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Previous Class</span><span class="val"><?= e($app['previous_class'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Admission Class</span><span class="val"><strong><?= e($app['class_sought']) ?></strong></span></div>
                </div>
            </div>

            <!-- Parent / Guardian -->
            <div class="detail-card">
                <div class="head"><i class="fas fa-users"></i> Parent / Guardian</div>
                <div class="body">
                    <div class="detail-row"><span class="lbl">Father's Name</span><span class="val"><?= e($app['father_name']) ?></span></div>
                    <div class="detail-row"><span class="lbl">Father's Occupation</span><span class="val"><?= e($app['father_occupation'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Mother's Name</span><span class="val"><?= e($app['mother_name']) ?></span></div>
                    <div class="detail-row"><span class="lbl">Mother's Occupation</span><span class="val"><?= e($app['mother_occupation'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Guardian Name</span><span class="val"><?= e($app['guardian_name'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Guardian Occupation</span><span class="val"><?= e($app['guardian_occupation'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Annual Income</span><span class="val"><?= e($app['family_annual_income'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Contact No</span><span class="val"><?= e($app['contact_no'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Email</span><span class="val"><?= e($app['email'] ?? '—') ?></span></div>
                </div>
            </div>

            <!-- Address -->
            <div class="detail-card">
                <div class="head"><i class="fas fa-map-marker-alt"></i> Address</div>
                <div class="body">
                    <div class="detail-row"><span class="lbl">Address Line 1</span><span class="val"><?= e($app['address_line1'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Address Line 2</span><span class="val"><?= e($app['address_line2'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Post Office</span><span class="val"><?= e($app['post_office'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Police Station</span><span class="val"><?= e($app['police_station'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">District</span><span class="val"><?= e($app['district'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Village / City</span><span class="val"><?= e($app['village_city'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">PIN Code</span><span class="val"><?= e($app['pin'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">State</span><span class="val"><?= e($app['state'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Country</span><span class="val"><?= e($app['country'] ?? 'India') ?></span></div>
                </div>
            </div>

            <!-- Parent Account -->
            <div class="detail-card">
                <div class="head"><i class="fas fa-user-circle"></i> Parent Account</div>
                <div class="body">
                    <div class="detail-row"><span class="lbl">Parent Name</span><span class="val"><?= e($app['parent_name'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Phone</span><span class="val"><?= e($app['parent_phone'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Email</span><span class="val"><?= e($app['parent_email'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Admission No</span><span class="val"><?= e($app['admission_no'] ?? '—') ?></span></div>
                    <div class="detail-row"><span class="lbl">Payment Method</span><span class="val"><?= e($app['payment_method'] ?? 'Online') ?></span></div>
                    <div class="detail-row"><span class="lbl">Applied On</span><span class="val"><?= date('d M Y, h:i A', strtotime($app['applied_at'])) ?></span></div>
                </div>
            </div>

            <!-- Documents -->
            <div class="detail-card full-col">
                <div class="head"><i class="fas fa-paperclip"></i> Uploaded Documents</div>
                <div class="body">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:.75rem;">
                        <?php foreach ($docs as $label => $file): ?>
                            <div style="background:#f9f9f9;border-radius:8px;padding:.75rem 1rem;text-align:center;">
                                <div style="font-size:.8rem;color:#64748b;margin-bottom:.3rem;"><?= $label ?></div>
                                <?php if ($file): ?>
                                    <a href="../../site/uploads/docs/<?= rawurlencode($file) ?>" target="_blank" class="doc-link"><i class="fas fa-eye"></i> View</a>
                                <?php else: ?>
                                    <span style="color:#999;font-size:.85rem;">Not uploaded</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
