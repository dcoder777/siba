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

$searchQ = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($searchQ !== '') {
    $where[] = '(p.name LIKE :q1 OR p.email LIKE :q2 OR p.phone LIKE :q3)';
    $likeQ = '%' . $searchQ . '%';
    $params['q1'] = $likeQ;
    $params['q2'] = $likeQ;
    $params['q3'] = $likeQ;
}
$whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM parents p" . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetch()['c'];

$page = max(1, (int) ($_GET['p'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$sql = "SELECT p.*, (SELECT COUNT(*) FROM applications WHERE parent_id = p.id) AS app_count FROM parents p" . $whereSql . " ORDER BY p.created_at DESC LIMIT :lim OFFSET :off";
$listStmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$parents = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int) ceil($total / $limit));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parents – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .app-filters { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; }
        .app-filters input { padding:.45rem .7rem; border:1px solid #cbd5e1; border-radius:6px; font-size:.875rem; }
        .app-filters .btn { padding:.45rem 1rem; }
        .app-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .app-table th { text-align:left; padding:.65rem .5rem; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; }
        .app-table td { padding:.65rem .5rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
        .app-table tr:hover td { background:#f8fafc; }
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
            <a class="nav-link active" href="parents-list.php">
                <span class="sidebar-icon">👤</span><span>Parents</span><span class="nav-tag"><?= $total ?></span>
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
                    <h1>Registered Parents</h1>
                    <p>View all parents who have registered on the parent portal.</p>
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
            <input type="text" name="q" placeholder="Search name, email, phone..." value="<?= e($searchQ) ?>" style="min-width:220px;">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="parents-list.php" class="btn btn-soft">Clear</a>
            <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $total ?> parent<?= $total !== 1 ? 's' : '' ?></span>
        </form>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Applications</th>
                        <th>Registered On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($parents)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:2rem;color:#94a3b8;">No parents found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($parents as $i => $p): ?>
                            <tr>
                                <td style="color:#94a3b8;"><?= $offset + $i + 1 ?></td>
                                <td><strong><?= e($p['name'] ?? '—') ?></strong></td>
                                <td><?= e($p['email'] ?? '—') ?></td>
                                <td><?= e($p['phone']) ?></td>
                                <td><a href="applications-list.php?q=<?= e($p['phone']) ?>" style="color:#2563eb;"><?= (int) $p['app_count'] ?> application<?= (int) $p['app_count'] !== 1 ? 's' : '' ?></a></td>
                                <td style="white-space:nowrap;"><?= date('d-m-Y', strtotime($p['created_at'])) ?></td>
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
