<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$error = '';
$success = '';

// ─── Flash messages ───
$flash = get_flash();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

// ─── Auto-create tables if missing ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS income_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        income_no VARCHAR(50),
        income_date DATE,
        payment_mode VARCHAR(50),
        payment_id VARCHAR(150),
        status ENUM('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending',
        approved_by INT,
        approved_at DATETIME,
        reject_reason TEXT,
        created_by INT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_income_date (income_date)
    )");
} catch (\Throwable $e) {}

// ─── Add missing columns to existing tables ───
ensure_columns($pdo, 'income_categories', [
    'income_no' => "VARCHAR(50)",
    'income_date' => "DATE",
    'payment_mode' => "VARCHAR(50)",
    'payment_id' => "VARCHAR(150)",
    'status' => "ENUM('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending'",
    'approved_by' => "INT",
    'approved_at' => "DATETIME",
    'reject_reason' => "TEXT",
    'created_by' => "INT",
]);

// ─── Helpers ───

function generate_income_no(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM income_categories WHERE income_no IS NOT NULL AND income_no != '' AND YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $next = (int) $stmt->fetchColumn() + 1;
    return 'INC-' . $year . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        // Delete (soft)
        if ($action === 'delete_income' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $stmt = $pdo->prepare("UPDATE income_categories SET status='Cancelled' WHERE id=? AND status IN ('Pending','Approved','Rejected')");
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                set_flash('success', 'Income cancelled.');
            } else {
                $error = 'Income could not be cancelled.';
            }
            header('Location: income-entry.php' . ($error !== '' ? '?error=1' : ''));
            exit;
        }

        // Approve
        if ($action === 'approve_income' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM income_categories WHERE id = ? AND status = 'Pending'");
                $stmt->execute([$id]);
                $inc = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inc) {
                    throw new \RuntimeException('Income not found or already processed.');
                }

                $pdo->prepare("UPDATE income_categories SET status='Approved', approved_by=?, approved_at=NOW() WHERE id=?")
                    ->execute([(int) ($user['id'] ?? 0), $id]);

                $payMode = strtolower((string) ($inc['payment_mode'] ?? ''));
                $desc = "Income {$inc['income_no']} - {$inc['name']}";
                $netAmt = (float) $inc['amount'];
                $payDate = $inc['income_date'] ?: date('Y-m-d');
                $uid = (int) ($user['id'] ?? 0);

                if ($payMode === 'cash') {
                    $pdo->prepare("INSERT INTO cash_book (transaction_date, transaction_type, reference_type, reference_id, description, amount, direction, created_by) VALUES (?, 'receipt', 'income', ?, ?, ?, 'debit', ?)")
                        ->execute([$payDate, $id, $desc, $netAmt, $uid]);
                } else {
                    $bankAccountId = 1;
                    $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, description, amount, direction, created_by) VALUES (?, ?, 'receipt', 'income', ?, ?, ?, 'debit', ?)")
                        ->execute([$bankAccountId, $payDate, $id, $desc, $netAmt, $uid]);
                }

                $pdo->commit();
                set_flash('success', 'Income approved and posted to books.');
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Approval failed: ' . $e->getMessage();
            }
            header('Location: income-entry.php' . ($error !== '' ? '?error=1' : ''));
            exit;
        }

        // Reject
        if ($action === 'reject_income' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $reason = trim((string) ($_POST['reject_reason'] ?? ''));
            $stmt = $pdo->prepare("UPDATE income_categories SET status='Rejected', reject_reason=? WHERE id=? AND status='Pending'");
            $stmt->execute([$reason ?: null, $id]);
            if ($stmt->rowCount() > 0) {
                set_flash('success', 'Income rejected.');
            } else {
                $error = 'Income could not be rejected.';
            }
            header('Location: income-entry.php' . ($error !== '') ? '?error=1' : '');
            exit;
        }
    } catch (\Throwable $e) {
        $error = 'Operation failed: ' . $e->getMessage();
    }

    header('Location: income-entry.php' . ($error !== '' ? '?error=1' : ''));
    exit;
}

// ─── Fetch data ───

// Manual income entries
$manualIncome = $pdo->query("SELECT *, 'manual' AS source_type FROM income_categories WHERE COALESCE(status,'') NOT IN ('Cancelled') AND income_no IS NOT NULL AND income_no != '' ORDER BY COALESCE(income_date, created_at) DESC")->fetchAll(PDO::FETCH_ASSOC);

// Automatic entries from paid applications
$autoIncome = [];
try {
    $stmt = $pdo->query("SELECT a.id, a.application_no, a.student_name AS name, a.class_sought, a.payment_amount AS amount, a.payment_status AS status, a.applied_at AS income_date, p.name AS parent_name, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.payment_status = 'Paid' AND a.deleted_at IS NULL ORDER BY a.applied_at DESC");
    $autoIncome = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Combine and sort
$allRows = [];
$counter = 0;

// Add manual entries
foreach ($manualIncome as $r) {
    $counter++;
    $allRows[] = [
        'seq' => $counter,
        'source' => 'manual',
        'id' => (int) $r['id'],
        'income_no' => $r['income_no'] ?? '',
        'income_date' => $r['income_date'] ?? '',
        'name' => $r['name'] ?? '',
        'amount' => (float) $r['amount'],
        'payment_mode' => $r['payment_mode'] ?? '',
        'payment_id' => $r['payment_id'] ?? '',
        'status' => $r['status'] ?? 'Pending',
        'description' => $r['description'] ?? '',
        'created_by' => (int) ($r['created_by'] ?? 0),
        'approved_by' => (int) ($r['approved_by'] ?? 0),
        'approved_at' => $r['approved_at'] ?? '',
        'reject_reason' => $r['reject_reason'] ?? '',
        'class_sought' => '',
        'parent_name' => '',
        'parent_phone' => '',
        'sort_date' => $r['income_date'] ?? $r['created_at'] ?? '',
    ];
}

// Add automatic entries
foreach ($autoIncome as $r) {
    $counter++;
    $allRows[] = [
        'seq' => $counter,
        'source' => 'auto',
        'id' => (int) $r['id'],
        'income_no' => $r['application_no'] ?? 'APP-' . $r['id'],
        'income_date' => $r['income_date'] ?? '',
        'name' => $r['name'] ?? '',
        'amount' => (float) ($r['amount'] ?? 0),
        'payment_mode' => 'Online',
        'payment_id' => '',
        'status' => 'Paid',
        'description' => 'Application fee for ' . ($r['class_sought'] ?? 'N/A'),
        'created_by' => 0,
        'approved_by' => 0,
        'approved_at' => '',
        'reject_reason' => '',
        'class_sought' => $r['class_sought'] ?? '',
        'parent_name' => $r['parent_name'] ?? '',
        'parent_phone' => $r['parent_phone'] ?? '',
        'sort_date' => $r['income_date'] ?? '',
    ];
}

// Sort combined array by date descending
usort($allRows, function (array $a, array $b): int {
    return strcmp($b['sort_date'], $a['sort_date']);
});

// Re-number after sort
$i = 0;
foreach ($allRows as &$row) {
    $i++;
    $row['seq'] = $i;
}
unset($row);

// ─── Stats ───
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$stats = [
    'month_total' => 0.0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total_manual' => 0,
];
try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM income_categories WHERE income_date >= :m1 AND income_date <= :m2 AND income_no IS NOT NULL AND income_no != '' AND status != 'Cancelled'");
    $st->execute(['m1' => $monthStart, 'm2' => $monthEnd]);
    $stats['month_total'] = (float) $st->fetchColumn();
    $stats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM income_categories WHERE status='Pending' AND income_no IS NOT NULL AND income_no != ''")->fetchColumn();
    $stats['approved'] = (int) $pdo->query("SELECT COUNT(*) FROM income_categories WHERE status='Approved'")->fetchColumn();
    $stats['rejected'] = (int) $pdo->query("SELECT COUNT(*) FROM income_categories WHERE status='Rejected'")->fetchColumn();
    $stats['total_manual'] = (int) $pdo->query("SELECT COUNT(*) FROM income_categories WHERE income_no IS NOT NULL AND income_no != ''")->fetchColumn();
} catch (\Throwable $e) {}

$autoCount = count($autoIncome);
$totalAutoAmount = array_sum(array_column($autoIncome, 'amount'));

// ─── Edit / View ───
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM income_categories WHERE id = ? AND income_no IS NOT NULL AND income_no != ''");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $error = 'Income not found.';
    }
}

$viewRow = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM income_categories WHERE id = ?");
    $stmt->execute([(int) $_GET['view']]);
    $viewRow = $stmt->fetch(PDO::FETCH_ASSOC);
}

$rejectId = (int) ($_GET['reject'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Income Entry – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; flex-wrap:wrap; }
        .tab-bar a { padding:.6rem 1.25rem; font-size:.85rem; font-weight:500; color:var(--text-light); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; }
        .tab-bar a.active { color:var(--primary-color); border-bottom-color:var(--primary-color); }
        .tab-bar a:hover { color:var(--primary-color); }
        .badge-pending { background:#fef3c7; color:#92400e; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-approved { background:#d1fae5; color:#065f46; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-rejected { background:#fee2e2; color:#991b1b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-cancelled { background:#f1f5f9; color:#64748b; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-paid { background:#dbeafe; color:#1e40af; padding:.2rem .6rem; border-radius:4px; font-size:.78rem; font-weight:600; }
        .badge-auto { background:#f0fdf4; color:#166534; padding:.1rem .4rem; border-radius:4px; font-size:.7rem; font-weight:500; }
        .action-btns { display:flex; gap:.35rem; align-items:center; flex-wrap:wrap; }
        .stats-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
        .stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:1rem 1.25rem; }
        .stat-card .stat-label { font-size:.78rem; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.25rem; }
        .stat-card .stat-value { font-size:1.35rem; font-weight:700; color:#1e293b; }
        .stat-card .stat-value.pending { color:#d97706; }
        .stat-card .stat-value.approved { color:#059669; }
        .stat-card .stat-value.rejected { color:#dc2626; }
        .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:1000; align-items:flex-start; justify-content:center; padding-top:3vh; overflow-y:auto; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:#fff; border-radius:12px; width:100%; max-width:720px; max-height:90vh; overflow-y:auto; padding:1.5rem; margin-bottom:3vh; }
        .modal-box .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; border-bottom:1px solid #e2e8f0; padding-bottom:.75rem; }
        .modal-box .modal-header h2 { margin:0; font-size:1.15rem; }
        .modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b; padding:.25rem .5rem; border-radius:6px; }
        .modal-close:hover { background:#f1f5f9; }
        .view-detail { display:flex; gap:.5rem; padding:.4rem 0; border-bottom:1px solid #f1f5f9; }
        .view-detail .vd-label { min-width:150px; font-weight:600; color:#64748b; font-size:.85rem; }
        .view-detail .vd-value { font-size:.85rem; color:#1e293b; }
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
                    <h1>Income</h1>
                    <p>Track all income entries including manual entries and application payments.</p>
                </div>
                <div class="toolbar-right">
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
                <div class="stat-label">Manual Income (This Month)</div>
                <div class="stat-value">Rs. <?= number_format($stats['month_total'], 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Approval</div>
                <div class="stat-value pending"><?= $stats['pending'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Approved</div>
                <div class="stat-value approved"><?= $stats['approved'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Application Payments</div>
                <div class="stat-value" style="color:#2563eb;"><?= $autoCount ?></div>
            </div>
        </div>

        <!-- Main table -->
        <section class="panel" style="padding:1.25rem;">
            <?php if (empty($allRows)): ?>
                <p style="text-align:center;padding:2rem;color:var(--text-light);">No income entries found.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Name</th>
                                <th>Amount</th>
                                <th>Mode</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allRows as $r): ?>
                                <tr>
                                    <td style="color:#94a3b8;font-size:.82rem;"><?= $r['seq'] ?></td>
                                    <td style="white-space:nowrap;"><?= e($r['income_date']) ?></td>
                                    <td>
                                        <?= e($r['name']) ?>
                                        <?php if ($r['source'] === 'auto'): ?>
                                            <span class="badge-auto">Auto</span>
                                        <?php endif; ?>
                                        <?php if ($r['class_sought']): ?>
                                            <div style="font-size:.75rem;color:#94a3b8;"><?= e($r['class_sought']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($r['parent_name']): ?>
                                            <div style="font-size:.75rem;color:#94a3b8;">Parent: <?= e($r['parent_name']) ?><?= $r['parent_phone'] ? ' (' . e($r['parent_phone']) . ')' : '' ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>Rs. <?= number_format($r['amount'], 2) ?></strong></td>
                                    <td><?= e($r['payment_mode'] ?: '—') ?></td>
                                    <td>
                                        <?php
                                        $rawStatus = strtolower($r['status']);
                                        $statusClass = ($rawStatus === 'paid') ? 'badge-paid' : ('badge-' . strtolower($r['status']));
                                        ?>
                                        <span class="<?= $statusClass ?>"><?= e($r['status']) ?></span>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <?php if ($r['source'] === 'manual'): ?>
                                                <button type="button" class="btn btn-sm btn-outline" style="padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick="openViewModal(<?= (int) $r['id'] ?>)">View</button>
                                                <?php if ($r['status'] === 'Pending'): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Approve this income entry?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="approve_income">
                                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" style="background:#059669;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Approve</button>
                                                    </form>
                                                    <button type="button" style="background:#dc2626;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick="openRejectModal(<?= (int) $r['id'] ?>)">Reject</button>
                                                <?php endif; ?>
                                                <?php if ($r['status'] !== 'Cancelled'): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this income entry?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="delete_income">
                                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                        <button type="submit" style="background:#94a3b8;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Cancel</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline" style="padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick="openAutoViewModal('<?= e($r['income_no']) ?>', '<?= e($r['income_date']) ?>', '<?= e(addslashes($r['name'])) ?>', '<?= e($r['class_sought']) ?>', <?= $r['amount'] ?>, '<?= e($r['parent_name']) ?>', '<?= e($r['parent_phone']) ?>', '<?= e($r['description']) ?>')">View</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
            <h2>Income Details</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <div id="view-content">
            <p style="text-align:center;color:#94a3b8;">Loading...</p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- AUTO VIEW MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-auto-view">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Application Payment Details</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <div id="auto-view-content"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- REJECT MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-reject">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h2 style="color:#dc2626;">Reject Income</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="reject_income">
            <input type="hidden" name="id" id="reject-id" value="">
            <div style="margin-bottom:1rem;">
                <label for="reject_reason" style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.85rem;">Rejection Reason</label>
                <textarea name="reject_reason" id="reject_reason" rows="3" placeholder="Provide a reason for rejection..." style="width:100%;padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:.75rem;">
                <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:.6rem 1.5rem;border-radius:8px;font-weight:600;cursor:pointer;font-size:.9rem;">Confirm Reject</button>
                <button type="button" class="btn btn-outline" style="padding:.6rem 1.5rem;border-radius:8px;font-size:.9rem;cursor:pointer;" onclick="closeModals()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<script>
var allIncomeData = <?= json_encode($allRows) ?>;

function closeModals() {
    ['modal-view', 'modal-reject', 'modal-auto-view'].forEach(function(id) {
        document.getElementById(id).classList.remove('open');
    });
}

function openViewModal(id) {
    closeModals();
    var row = allIncomeData.find(function(r) { return r.id === id && r.source === 'manual'; });
    if (!row) return;

    var fields = [
        ['Income No', row.income_no],
        ['Date', row.income_date],
        ['Name', row.name],
        ['Description', row.description || '—'],
        ['Amount', '<strong>Rs. ' + parseFloat(row.amount).toFixed(2) + '</strong>'],
        ['Payment Mode', row.payment_mode || '—'],
        ['Payment ID', row.payment_id || '—'],
        ['Status', row.status]
    ];
    var html = '';
    fields.forEach(function(f) {
        html += '<div class="view-detail"><div class="vd-label">' + f[0] + '</div><div class="vd-value">' + f[1] + '</div></div>';
    });
    document.getElementById('view-content').innerHTML = html;
    document.getElementById('modal-view').classList.add('open');
}

function openAutoViewModal(appNo, date, name, classSought, amount, parentName, parentPhone, desc) {
    closeModals();
    var fields = [
        ['Application No', appNo],
        ['Date', date],
        ['Student Name', name],
        ['Class', classSought],
        ['Amount', '<strong>Rs. ' + parseFloat(amount).toFixed(2) + '</strong>'],
        ['Parent Name', parentName || '—'],
        ['Parent Phone', parentPhone || '—'],
        ['Description', desc],
        ['Status', 'Paid (Auto)']
    ];
    var html = '';
    fields.forEach(function(f) {
        html += '<div class="view-detail"><div class="vd-label">' + f[0] + '</div><div class="vd-value">' + f[1] + '</div></div>';
    });
    document.getElementById('auto-view-content').innerHTML = html;
    document.getElementById('modal-auto-view').classList.add('open');
}

function openRejectModal(id) {
    closeModals();
    document.getElementById('reject-id').value = id;
    document.getElementById('reject_reason').value = '';
    document.getElementById('modal-reject').classList.add('open');
}

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModals();
});

// Close modals when clicking outside
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModals();
    });
});
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
