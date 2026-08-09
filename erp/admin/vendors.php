<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$error = '';
$success = '';

// ─── Auto-create vendors table if missing ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_code VARCHAR(50),
        name VARCHAR(255) NOT NULL,
        mobile VARCHAR(50),
        email VARCHAR(255),
        gst_number VARCHAR(50),
        pan VARCHAR(50),
        address TEXT,
        bank_name VARCHAR(255),
        account_number VARCHAR(100),
        ifsc_code VARCHAR(50),
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}

ensure_columns($pdo, 'vendors', [
    'vendor_code' => "VARCHAR(50)",
    'mobile' => "VARCHAR(50)",
    'email' => "VARCHAR(255)",
    'gst_number' => "VARCHAR(50)",
    'pan' => "VARCHAR(50)",
    'address' => "TEXT",
    'bank_name' => "VARCHAR(255)",
    'account_number' => "VARCHAR(100)",
    'ifsc_code' => "VARCHAR(50)",
    'is_active' => "TINYINT(1) DEFAULT 1",
    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
]);

// ─── Helpers ───
function generate_vendor_code(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendors WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $next = (int) $stmt->fetchColumn() + 1;
    return 'VND-' . $year . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

// ─── POST handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    // Create vendor
    if ($action === 'create_vendor') {
        $vendorCode = trim((string) ($_POST['vendor_code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $gstNumber = trim((string) ($_POST['gst_number'] ?? ''));
        $pan = trim((string) ($_POST['pan'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
        $ifscCode = trim((string) ($_POST['ifsc_code'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $error = 'Vendor name is required.';
        } else {
            if (!$vendorCode) {
                $vendorCode = generate_vendor_code($pdo);
            }
            $stmt = $pdo->prepare("INSERT INTO vendors (vendor_code, name, mobile, email, gst_number, pan, address, bank_name, account_number, ifsc_code, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$vendorCode, $name, $mobile, $email, $gstNumber, $pan, $address, $bankName, $accountNumber, $ifscCode, $isActive]);
            $success = 'Vendor added successfully.';
        }
    }

    // Update vendor
    if ($action === 'update_vendor' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $vendorCode = trim((string) ($_POST['vendor_code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $gstNumber = trim((string) ($_POST['gst_number'] ?? ''));
        $pan = trim((string) ($_POST['pan'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $bankName = trim((string) ($_POST['bank_name'] ?? ''));
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
        $ifscCode = trim((string) ($_POST['ifsc_code'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $error = 'Vendor name is required.';
        } else {
            $stmt = $pdo->prepare("UPDATE vendors SET vendor_code=?, name=?, mobile=?, email=?, gst_number=?, pan=?, address=?, bank_name=?, account_number=?, ifsc_code=?, is_active=? WHERE id=?");
            $stmt->execute([$vendorCode, $name, $mobile, $email, $gstNumber, $pan, $address, $bankName, $accountNumber, $ifscCode, $isActive, $id]);
            $success = 'Vendor updated successfully.';
        }
    }

    // Delete vendor
    if ($action === 'delete_vendor' && isset($_POST['id']) && $isOwner) {
        $id = (int) $_POST['id'];
        $pdo->prepare("DELETE FROM vendors WHERE id=?")->execute([$id]);
        $success = 'Vendor deleted.';
    }

    header('Location: vendors.php' . ($error !== '' ? '?error=1' : ''));
    exit;
}

// ─── Filters ───
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(v.vendor_code LIKE :s1 OR v.name LIKE :s2 OR v.mobile LIKE :s3 OR v.email LIKE :s4 OR v.gst_number LIKE :s5 OR v.pan LIKE :s6)';
    $like = '%' . $search . '%';
    $params[':s1'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
    $params[':s5'] = $like;
    $params[':s6'] = $like;
}

if ($statusFilter === 'active') {
    $where[] = 'v.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'v.is_active = 0';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vendors v $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT v.* FROM vendors v $whereClause ORDER BY v.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Vendor expense totals
$vendorExpenses = $pdo->query("SELECT vendor_id, COALESCE(SUM(net_amount),0) AS total_expense, COUNT(*) AS expense_count FROM expenses WHERE vendor_id IS NOT NULL AND status != 'Cancelled' GROUP BY vendor_id")->fetchAll(PDO::FETCH_ASSOC);
$vendorExpenseMap = [];
foreach ($vendorExpenses as $ve) { $vendorExpenseMap[(int) $ve['vendor_id']] = $ve; }

// ─── Stats ───
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'total_spend' => 0.0];
try {
    $stats['total'] = (int) $pdo->query("SELECT COUNT(*) FROM vendors")->fetchColumn();
    $stats['active'] = (int) $pdo->query("SELECT COUNT(*) FROM vendors WHERE is_active = 1")->fetchColumn();
    $stats['inactive'] = (int) $pdo->query("SELECT COUNT(*) FROM vendors WHERE is_active = 0")->fetchColumn();
    $stats['total_spend'] = (float) $pdo->query("SELECT COALESCE(SUM(net_amount),0) FROM expenses WHERE status != 'Cancelled'")->fetchColumn();
} catch (\Throwable $e) {}

// Edit record
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $error = 'Vendor not found.'; }
}

$editMode = $editRow !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Vendors – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .badge-active{background:#d1fae5;color:#065f46;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;}
        .badge-inactive{background:#fee2e2;color:#991b1b;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;}
        .filter-bar{display:flex;align-items:flex-end;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;}
        .filter-group{display:flex;flex-direction:column;}
        .filter-group label{font-size:.78rem;margin-bottom:.2rem;color:#64748b;}
        .filter-group input,.filter-group select{min-height:36px;padding:.4rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;}
        .stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;}
        .stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.25rem;}
        .stat-card .stat-label{font-size:.78rem;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.25rem;}
        .stat-card .stat-value{font-size:1.35rem;font-weight:700;color:#1e293b;}
        .stat-card .stat-value.green{color:#059669;}
        .stat-card .stat-value.amber{color:#d97706;}
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
        .page-links{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;}
        .page-links a,.page-links span{min-height:34px;padding:.38rem .65rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;text-decoration:none;font-size:.82rem;}
        .page-links a:hover{background:#f1f5f9;}
        .page-links .active{background:#64748b;border-color:#64748b;color:#fff;}
        .action-btns{display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;}
        .field-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
        .field-grid .full-col{grid-column:1/-1;}
        .field-grid label{display:block;font-size:.82rem;font-weight:600;color:#475569;margin-bottom:.3rem;}
        .field-grid input,.field-grid textarea,.field-grid select{width:100%;padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.875rem;box-sizing:border-box;}
        .field-grid textarea{resize:vertical;}
        .sortable{cursor:pointer;user-select:none;white-space:nowrap;}
        .sortable:hover{color:#2563eb;}
        .sort-icon{font-size:.7rem;margin-left:3px;opacity:.4;}
        .sortable.sort-asc .sort-icon,.sortable.sort-desc .sort-icon{opacity:1;color:#2563eb;}
        .expenses-mini{margin-top:2rem;}
        .expenses-mini table{width:100%;font-size:.82rem;border-collapse:collapse;}
        .expenses-mini th{text-align:left;padding:.5rem;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;font-size:.75rem;text-transform:uppercase;}
        .expenses-mini td{padding:.5rem;border-bottom:1px solid #f1f5f9;}
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
                    <h1>Vendors</h1>
                    <p>Manage vendor profiles including contact, GST, PAN, and bank details.</p>
                </div>
                <div class="toolbar-right">
                    <button type="button" class="btn" onclick="openAddModal()">+ Add Vendor</button>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-label">Total Vendors</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active</div>
                <div class="stat-value green"><?= $stats['active'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inactive</div>
                <div class="stat-value amber"><?= $stats['inactive'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Spend (All Time)</div>
                <div class="stat-value">Rs. <?= number_format($stats['total_spend'], 2) ?></div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;width:100%;">
                <div class="filter-group" style="flex:1;min-width:200px;max-width:400px;">
                    <label for="f_search">Search</label>
                    <input type="text" name="search" id="f_search" placeholder="Code, Name, Mobile, Email, GST, PAN..." value="<?= e($search) ?>">
                </div>
                <div class="filter-group">
                    <label for="f_status">Status</label>
                    <select name="status" id="f_status">
                        <option value="all">All</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="filter-group" style="align-self:flex-end;">
                    <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:36px;font-size:.85rem;border-radius:8px;">Filter</button>
                    <a href="vendors.php" style="font-size:.85rem;color:#64748b;margin-left:.5rem;text-decoration:none;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Main table -->
        <section class="panel" style="padding:1.25rem;">
            <?php if (empty($rows)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No vendors found.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table" id="vendorTable">
                        <thead>
                            <tr>
                                <th class="sortable" onclick="sortTable(0,'num')"># <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(1,'str')">Code <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(2,'str')">Name <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(3,'str')">Mobile <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(4,'str')">Email <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(5,'str')">GST <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(6,'str')">PAN <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(7,'num')" style="text-align:right;">Total Expense <span class="sort-icon">⇅</span></th>
                                <th class="sortable" onclick="sortTable(8,'str')">Status <span class="sort-icon">⇅</span></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="vendorTableBody">
                            <?php $i = $offset + 1; foreach ($rows as $row): ?>
                            <tr>
                                <td style="color:#94a3b8;"><?= $i++ ?></td>
                                <td style="font-family:monospace;font-size:.82rem;"><?= e($row['vendor_code'] ?? '') ?></td>
                                <td>
                                    <strong style="cursor:pointer;color:#2563eb;text-decoration:underline;" onclick="viewVendor(<?= (int) $row['id'] ?>)"><?= e($row['name']) ?></strong>
                                </td>
                                <td><?= e($row['mobile'] ?? '') ?></td>
                                <td><?= e($row['email'] ?? '') ?></td>
                                <td><?= e($row['gst_number'] ?? '') ?></td>
                                <td><?= e($row['pan'] ?? '') ?></td>
                                <td style="text-align:right;" data-sort="<?= (float) ($vendorExpenseMap[(int) $row['id']]['total_expense'] ?? 0) ?>"><?= isset($vendorExpenseMap[(int) $row['id']]) ? 'Rs. ' . number_format((float) $vendorExpenseMap[(int) $row['id']]['total_expense'], 2) : '—' ?></td>
                                <td data-sort="<?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?>"><span class="<?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                                <td>
                                    <div class="action-btns">
                                        <button type="button" class="btn btn-sm btn-outline" style="padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick="openEditModal(<?= (int) $row['id'] ?>)">Edit</button>
                                        <?php if ($isOwner): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this vendor? This cannot be undone.')">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_vendor">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" style="background:#ef4444;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
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

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- VIEW MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-view">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Vendor Details</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <div id="view-content">
            <p style="text-align:center;color:#94a3b8;">Loading...</p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- ADD/EDIT MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-form">
    <div class="modal-box" style="max-width:720px;">
        <div class="modal-header">
            <h2 id="form-modal-title">Add Vendor</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <form method="post" id="vendor-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="form-action" value="create_vendor">
            <input type="hidden" name="id" id="form-id" value="">
            <div class="field-grid">
                <div>
                    <label>Vendor Code</label>
                    <input name="vendor_code" id="f-vendor_code" type="text" placeholder="Auto-generated if empty">
                </div>
                <div>
                    <label>Name *</label>
                    <input name="name" id="f-name" type="text" required>
                </div>
                <div>
                    <label>Mobile</label>
                    <input name="mobile" id="f-mobile" type="text">
                </div>
                <div>
                    <label>Email</label>
                    <input name="email" id="f-email" type="email">
                </div>
                <div>
                    <label>GST Number</label>
                    <input name="gst_number" id="f-gst_number" type="text">
                </div>
                <div>
                    <label>PAN</label>
                    <input name="pan" id="f-pan" type="text">
                </div>
                <div class="full-col">
                    <label>Address</label>
                    <textarea name="address" id="f-address" rows="2"></textarea>
                </div>
                <div>
                    <label>Bank Name</label>
                    <input name="bank_name" id="f-bank_name" type="text">
                </div>
                <div>
                    <label>Account Number</label>
                    <input name="account_number" id="f-account_number" type="text">
                </div>
                <div>
                    <label>IFSC Code</label>
                    <input name="ifsc_code" id="f-ifsc_code" type="text">
                </div>
                <div class="full-col">
                    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                        <input type="checkbox" name="is_active" id="f-is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" checked>
                        Active
                    </label>
                </div>
            </div>
            <div style="display:flex;gap:.75rem;margin-top:1.5rem;">
                <button type="submit" class="btn" id="form-submit-btn">Add Vendor</button>
                <button type="button" class="btn btn-outline" style="padding:.6rem 1.5rem;border-radius:8px;font-size:.9rem;cursor:pointer;" onclick="closeModals()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<script>
var allVendors = <?= json_encode(array_map(fn(array $r) => [
    'id' => (int) $r['id'],
    'vendor_code' => $r['vendor_code'] ?? '',
    'name' => $r['name'] ?? '',
    'mobile' => $r['mobile'] ?? '',
    'email' => $r['email'] ?? '',
    'gst_number' => $r['gst_number'] ?? '',
    'pan' => $r['pan'] ?? '',
    'address' => $r['address'] ?? '',
    'bank_name' => $r['bank_name'] ?? '',
    'account_number' => $r['account_number'] ?? '',
    'ifsc_code' => $r['ifsc_code'] ?? '',
    'is_active' => (int) ($r['is_active'] ?? 0),
    'total_expense' => (float) ($vendorExpenseMap[(int) $r['id']]['total_expense'] ?? 0),
    'expense_count' => (int) ($vendorExpenseMap[(int) $r['id']]['expense_count'] ?? 0),
], $rows)) ?>;

function closeModals() {
    document.getElementById('modal-view').classList.remove('open');
    document.getElementById('modal-form').classList.remove('open');
}

function viewVendor(id) {
    var v = allVendors.find(function(x) { return x.id === id; });
    if (!v) return;
    var html = '';
    var fields = [
        ['Vendor Code', v.vendor_code || '—'],
        ['Name', v.name],
        ['Mobile', v.mobile || '—'],
        ['Email', v.email || '—'],
        ['GST Number', v.gst_number || '—'],
        ['PAN', v.pan || '—'],
        ['Address', v.address || '—'],
        ['Bank Name', v.bank_name || '—'],
        ['Account Number', v.account_number || '—'],
        ['IFSC Code', v.ifsc_code || '—'],
        ['Status', v.is_active ? '<span class="badge-active">Active</span>' : '<span class="badge-inactive">Inactive</span>'],
        ['Total Expenses', v.expense_count + ' expense(s) worth Rs. ' + v.total_expense.toLocaleString('en-IN', {minimumFractionDigits:2})]
    ];
    fields.forEach(function(f) {
        html += '<div class="view-detail"><div class="vd-label">' + f[0] + '</div><div class="vd-value">' + f[1] + '</div></div>';
    });
    document.getElementById('view-content').innerHTML = html;
    document.getElementById('modal-view').classList.add('open');
}

function openAddModal() {
    document.getElementById('form-modal-title').textContent = 'Add Vendor';
    document.getElementById('form-action').value = 'create_vendor';
    document.getElementById('form-id').value = '';
    document.getElementById('form-submit-btn').textContent = 'Add Vendor';
    ['vendor_code','name','mobile','email','gst_number','pan','address','bank_name','account_number','ifsc_code'].forEach(function(f) {
        document.getElementById('f-' + f).value = '';
    });
    document.getElementById('f-is_active').checked = true;
    document.getElementById('modal-form').classList.add('open');
}

function openEditModal(id) {
    var v = allVendors.find(function(x) { return x.id === id; });
    if (!v) return;
    document.getElementById('form-modal-title').textContent = 'Edit Vendor';
    document.getElementById('form-action').value = 'update_vendor';
    document.getElementById('form-id').value = v.id;
    document.getElementById('form-submit-btn').textContent = 'Update Vendor';
    document.getElementById('f-vendor_code').value = v.vendor_code;
    document.getElementById('f-name').value = v.name;
    document.getElementById('f-mobile').value = v.mobile;
    document.getElementById('f-email').value = v.email;
    document.getElementById('f-gst_number').value = v.gst_number;
    document.getElementById('f-pan').value = v.pan;
    document.getElementById('f-address').value = v.address;
    document.getElementById('f-bank_name').value = v.bank_name;
    document.getElementById('f-account_number').value = v.account_number;
    document.getElementById('f-ifsc_code').value = v.ifsc_code;
    document.getElementById('f-is_active').checked = v.is_active === 1;
    document.getElementById('modal-form').classList.add('open');
}

document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === this) closeModals(); });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModals(); });

var sortCol = -1, sortAsc = true;
function sortTable(col, type) {
    var table = document.getElementById('vendorTable');
    var tbody = document.getElementById('vendorTableBody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    if (sortCol === col) { sortAsc = !sortAsc; } else { sortCol = col; sortAsc = true; }
    table.querySelectorAll('th.sortable').forEach(function(th) { th.classList.remove('sort-asc','sort-desc'); th.querySelector('.sort-icon').textContent = '⇅'; });
    var th = table.querySelectorAll('th.sortable')[col];
    if (th) { th.classList.add(sortAsc ? 'sort-asc' : 'sort-desc'); th.querySelector('.sort-icon').textContent = sortAsc ? '↑' : '↓'; }
    rows.sort(function(a, b) {
        var ac = a.cells[col], bc = b.cells[col];
        var av = ac.getAttribute('data-sort') !== null ? ac.getAttribute('data-sort') : ac.textContent.trim();
        var bv = bc.getAttribute('data-sort') !== null ? bc.getAttribute('data-sort') : bc.textContent.trim();
        if (type === 'num') { av = parseFloat(av.replace(/[^\d.\-]/g,'')) || 0; bv = parseFloat(bv.replace(/[^\d.\-]/g,'')) || 0; return sortAsc ? av - bv : bv - av; }
        return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
}
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
