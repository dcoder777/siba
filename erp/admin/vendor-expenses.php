<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';

$vendorId = (int) ($_GET['id'] ?? 0);
if ($vendorId <= 0) {
    header('Location: vendors.php');
    exit;
}

// Fetch vendor
$stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->execute([$vendorId]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$vendor) {
    header('Location: vendors.php');
    exit;
}

// ─── Filters ───
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$allowedStatuses = ['all', 'Pending', 'Approved', 'Rejected', 'Cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) { $statusFilter = 'all'; }

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['e.vendor_id = :vid'];
$params = [':vid' => $vendorId];

if ($statusFilter !== 'all') {
    $where[] = 'e.status = :status';
    $params[':status'] = $statusFilter;
}
if ($dateFrom !== '') {
    $where[] = 'e.expense_date >= :df';
    $params[':df'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'e.expense_date <= :dt';
    $params[':dt'] = $dateTo;
}
if ($search !== '') {
    $where[] = '(e.expense_no LIKE :s1 OR e.category_name LIKE :s2 OR e.description LIKE :s3 OR e.bill_no LIKE :s4)';
    $like = '%' . $search . '%';
    $params[':s1'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT e.* FROM expenses e $whereClause ORDER BY e.expense_date DESC, e.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats for this vendor
$stats = ['total' => 0.0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'count' => 0];
try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(net_amount),0), COUNT(*) FROM expenses WHERE vendor_id = ? AND status != 'Cancelled'");
    $st->execute([$vendorId]);
    $r = $st->fetch(PDO::FETCH_NUM);
    $stats['total'] = (float) ($r[0] ?? 0);
    $stats['count'] = (int) ($r[1] ?? 0);
    $st2 = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE vendor_id = ? AND status = 'Pending'");
    $st2->execute([$vendorId]);
    $stats['pending'] = (int) $st2->fetchColumn();
    $st3 = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE vendor_id = ? AND status = 'Approved'");
    $st3->execute([$vendorId]);
    $stats['approved'] = (int) $st3->fetchColumn();
    $st4 = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE vendor_id = ? AND status = 'Rejected'");
    $st4->execute([$vendorId]);
    $stats['rejected'] = (int) $st4->fetchColumn();
} catch (\Throwable $e) {}

// Categories for filter
$categories = $pdo->query("SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($vendor['name']) ?> – Expenses – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .badge-pending{background:#fef3c7;color:#92400e;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;}
        .badge-approved{background:#d1fae5;color:#065f46;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;}
        .badge-rejected{background:#fee2e2;color:#991b1b;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;}
        .badge-cancelled{background:#f1f5f9;color:#64748b;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;}
        .filter-bar{display:flex;align-items:flex-end;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;}
        .filter-group{display:flex;flex-direction:column;}
        .filter-group label{font-size:.78rem;margin-bottom:.2rem;color:#64748b;}
        .filter-group input,.filter-group select{min-height:36px;padding:.4rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;}
        .stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;}
        .stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.25rem;}
        .stat-card .stat-label{font-size:.78rem;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.25rem;}
        .stat-card .stat-value{font-size:1.35rem;font-weight:700;color:#1e293b;}
        .stat-card .stat-value.pending{color:#d97706;}
        .stat-card .stat-value.approved{color:#059669;}
        .stat-card .stat-value.rejected{color:#dc2626;}
        .page-links{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;}
        .page-links a,.page-links span{min-height:34px;padding:.38rem .65rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;text-decoration:none;font-size:.82rem;}
        .page-links a:hover{background:#f1f5f9;}
        .page-links .active{background:#64748b;border-color:#64748b;color:#fff;}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto;}
        .modal-overlay.open{display:flex;}
        .modal-box{background:#fff;border-radius:12px;width:100%;max-width:720px;max-height:90vh;overflow-y:auto;padding:1.5rem;margin-bottom:3vh;}
        .modal-box .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;border-bottom:1px solid #e2e8f0;padding-bottom:.75rem;}
        .modal-box .modal-header h2{margin:0;font-size:1.15rem;}
        .modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;color:#64748b;padding:.25rem .5rem;border-radius:6px;}
        .modal-close:hover{background:#f1f5f9;}
        .view-detail{display:flex;gap:.5rem;padding:.4rem 0;border-bottom:1px solid #f1f5f9;}
        .view-detail .vd-label{min-width:150px;font-weight:600;color:#64748b;font-size:.85rem;}
        .view-detail .vd-value{font-size:.85rem;color:#1e293b;}
        .note-toggle{cursor:pointer;color:#2563eb;font-size:.9rem;}
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Expenses – <?= e($vendor['name']) ?></h1>
                    <p>
                        <?php if ($vendor['vendor_code']): ?><span style="color:#64748b;"><?= e($vendor['vendor_code']) ?></span> &nbsp;|&nbsp;<?php endif; ?>
                        <?php if ($vendor['mobile']): ?><span style="color:#64748b;"><?= e($vendor['mobile']) ?></span> &nbsp;|&nbsp;<?php endif; ?>
                        <?php if ($vendor['email']): ?><span style="color:#64748b;"><?= e($vendor['email']) ?></span><?php endif; ?>
                    </p>
                </div>
                <div class="toolbar-right">
                    <a href="vendors.php" style="text-decoration:none;padding:.45rem 1rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;color:#475569;background:#fff;">← Back to Vendors</a>
                </div>
            </div>
        </section>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-label">Total Expenses</div>
                <div class="stat-value">Rs. <?= number_format($stats['total'], 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Count</div>
                <div class="stat-value"><?= $stats['count'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending</div>
                <div class="stat-value pending"><?= $stats['pending'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Approved</div>
                <div class="stat-value approved"><?= $stats['approved'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Rejected</div>
                <div class="stat-value rejected"><?= $stats['rejected'] ?></div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;width:100%;">
                <input type="hidden" name="id" value="<?= $vendorId ?>">
                <div class="filter-group">
                    <label for="f_status">Status</label>
                    <select name="status" id="f_status">
                        <option value="all">All Statuses</option>
                        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="f_date_from">From</label>
                    <input type="date" name="date_from" id="f_date_from" value="<?= e($dateFrom) ?>">
                </div>
                <div class="filter-group">
                    <label for="f_date_to">To</label>
                    <input type="date" name="date_to" id="f_date_to" value="<?= e($dateTo) ?>">
                </div>
                <div class="filter-group">
                    <label for="f_search">Search</label>
                    <input type="text" name="search" id="f_search" placeholder="Exp No, Category, Bill No..." value="<?= e($search) ?>" style="min-width:200px;">
                </div>
                <div style="display:flex;align-items:flex-end;gap:.5rem;">
                    <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:36px;font-size:.85rem;border-radius:8px;">Filter</button>
                    <a href="?id=<?= $vendorId ?>" style="font-size:.85rem;color:#64748b;text-decoration:none;line-height:36px;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Main table -->
        <section class="panel" style="padding:1.25rem;">
            <?php if (empty($rows)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No expenses found for this vendor.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Expense No</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Bill No</th>
                                <th>Amount</th>
                                <th></th>
                                <th>GST</th>
                                <th>Net Amount</th>
                                <th>Payment Mode</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td style="font-family:monospace;font-size:.82rem;"><?= e($r['expense_no']) ?></td>
                                    <td style="white-space:nowrap;"><?= e($r['expense_date']) ?></td>
                                    <td><?= e($r['category_name'] ?? '—') ?></td>
                                    <td><?= e($r['bill_no'] ?? '—') ?></td>
                                    <td>Rs. <?= number_format((float) $r['amount'], 2) ?></td>
                                    <td><?php if (!empty($r['description'])): ?><span class="note-toggle" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='block'?'none':'block'" style="cursor:pointer;color:#2563eb;font-size:.9rem;" title="View note">📝</span><div style="display:none;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;margin-top:4px;font-size:.82rem;max-width:260px;position:relative;z-index:1;"><?= e($r['description']) ?></div><?php else: ?><span style="color:#94a3b8;">—</span><?php endif; ?></td>
                                    <td>Rs. <?= number_format((float) $r['gst_amount'], 2) ?></td>
                                    <td><strong>Rs. <?= number_format((float) $r['net_amount'], 2) ?></strong></td>
                                    <td><?= e($r['payment_mode'] ?? '—') ?></td>
                                    <td>
                                        <?php $statusClass = 'badge-' . strtolower($r['status']); ?>
                                        <span class="<?= $statusClass ?>"><?= e($r['status']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div style="margin-top:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
                        <div style="font-size:.85rem;color:#64748b;">Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?></div>
                        <div class="page-links">
                            <?php if ($page > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Prev</a>
                            <?php endif; ?>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($p = $startPage; $p <= $endPage; $p++):
                            ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</div>

<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
</body>
</html>
