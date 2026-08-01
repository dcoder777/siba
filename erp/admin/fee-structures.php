<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$error = '';
$success = '';

$classOptions = ['Play School', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8'];

// Auto-create tables (FK constraints omitted to avoid type mismatch with legacy tables)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        is_mandatory TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_structures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        academic_session VARCHAR(20) NOT NULL,
        class_name VARCHAR(50),
        total_amount DECIMAL(12,2) DEFAULT 0,
        installment_enabled TINYINT(1) DEFAULT 0,
        num_installments INT DEFAULT 1,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_structure_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_structure_id BIGINT NOT NULL,
        fee_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        is_optional TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_installments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_structure_id BIGINT NOT NULL,
        installment_no INT NOT NULL,
        title VARCHAR(100),
        due_date DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        late_fee_type ENUM('fixed','percentage') DEFAULT 'fixed',
        late_fee_value DECIMAL(10,2) DEFAULT 0,
        late_fee_grace_days INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_structure_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_structure_id BIGINT NOT NULL,
        assign_type ENUM('class','section') NOT NULL,
        assign_value VARCHAR(100) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

// ─── Auto-migrate legacy fee_structures table if it has the old schema ───
try {
    $existingCols = array_map(static fn(array $r): string => (string) $r['Field'], $pdo->query("DESCRIBE fee_structures")->fetchAll());
    $addCols = [
        'name'              => "VARCHAR(200) NOT NULL DEFAULT 'Fee Structure'",
        'total_amount'      => "DECIMAL(12,2) DEFAULT 0",
        'installment_enabled' => "TINYINT(1) DEFAULT 0",
        'num_installments'  => "INT DEFAULT 1",
        'is_active'         => "TINYINT(1) DEFAULT 1",
        'updated_at'        => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($addCols as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE fee_structures ADD COLUMN {$col} {$def}");
        }
    }
    // If legacy 'fee_head' exists but 'name' was just added, seed name from class_name + fee_head
    if (in_array('fee_head', $existingCols, true) && !in_array('name', $existingCols, true)) {
        $pdo->exec("UPDATE fee_structures SET name = CONCAT(class_name, ' - ', fee_head) WHERE name = 'Fee Structure'");
    }
    // fee_structures.id is BIGINT in legacy; keep as-is (INT vs BIGINT both fine for FK refs)
} catch (Throwable $e) {
    // ignore migration errors
}

$tab = in_array(trim((string) ($_GET['tab'] ?? '')), ['fee-heads', 'fee-structures', 'installments', 'assignments'], true)
    ? trim((string) $_GET['tab'])
    : 'fee-heads';

// --- HANDLE POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    // --- Fee Heads ---
    if ($action === 'add_fee_head' || $action === 'edit_fee_head') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $isMandatory = isset($_POST['is_mandatory']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        if ($name === '') {
            $error = 'Fee head name is required.';
        } else {
            if ($action === 'edit_fee_head' && $id > 0) {
                $stmt = $pdo->prepare("UPDATE fee_heads SET name=?, description=?, is_mandatory=?, sort_order=? WHERE id=?");
                $stmt->execute([$name, $description, $isMandatory, $sortOrder, $id]);
                $success = 'Fee head updated.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO fee_heads (name, description, is_mandatory, sort_order) VALUES (?,?,?,?)");
                $stmt->execute([$name, $description, $isMandatory, $sortOrder]);
                $success = 'Fee head added.';
            }
        }
    }

    if ($action === 'delete_fee_head') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM fee_heads WHERE id=?")->execute([$id]);
            $success = 'Fee head deleted.';
        }
    }

    // --- Fee Structures ---
    if ($action === 'add_fee_structure' || $action === 'edit_fee_structure') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $curYear = (int) date('Y');
        $academicSession = trim((string) ($_POST['academic_session'] ?? $curYear . '-' . substr((string)($curYear + 1), 2)));
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $installmentEnabled = isset($_POST['installment_enabled']) ? 1 : 0;
        $numInstallments = max(1, (int) ($_POST['num_installments'] ?? 1));
        if ($name === '') {
            $error = 'Fee structure name is required.';
        } else {
            if ($action === 'edit_fee_structure' && $id > 0) {
                $stmt = $pdo->prepare("UPDATE fee_structures SET name=?, academic_session=?, class_name=?, installment_enabled=?, num_installments=? WHERE id=?");
                $stmt->execute([$name, $academicSession, $className, $installmentEnabled, $numInstallments, $id]);
                $success = 'Fee structure updated.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO fee_structures (name, academic_session, class_name, installment_enabled, num_installments) VALUES (?,?,?,?,?)");
                $stmt->execute([$name, $academicSession, $className, $installmentEnabled, $numInstallments]);
                $id = (int) $pdo->lastInsertId();
                $success = 'Fee structure created.';
            }
        }
    }

    if ($action === 'delete_fee_structure') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM fee_structures WHERE id=?")->execute([$id]);
            $success = 'Fee structure deleted.';
        }
    }

    // --- Structure Items ---
    if ($action === 'add_item') {
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        $feeHeadId = (int) ($_POST['fee_head_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $isOptional = isset($_POST['is_optional']) ? 1 : 0;
        if ($feeStructureId > 0 && $feeHeadId > 0 && $amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO fee_structure_items (fee_structure_id, fee_head_id, amount, is_optional) VALUES (?,?,?,?)");
            $stmt->execute([$feeStructureId, $feeHeadId, $amount, $isOptional]);
            $itemSum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fee_structure_items WHERE fee_structure_id=?");
            $itemSum->execute([$feeStructureId]);
            $total = (float) $itemSum->fetchColumn();
            $pdo->prepare("UPDATE fee_structures SET total_amount=? WHERE id=?")->execute([$total, $feeStructureId]);
            $success = 'Item added.';
        } else {
            $error = 'Please select a fee head and enter a valid amount.';
        }
    }

    if ($action === 'delete_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        if ($itemId > 0 && $feeStructureId > 0) {
            $pdo->prepare("DELETE FROM fee_structure_items WHERE id=?")->execute([$itemId]);
            $itemSum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fee_structure_items WHERE fee_structure_id=?");
            $itemSum->execute([$feeStructureId]);
            $total = (float) $itemSum->fetchColumn();
            $pdo->prepare("UPDATE fee_structures SET total_amount=? WHERE id=?")->execute([$total, $feeStructureId]);
            $success = 'Item removed.';
        }
    }

    // --- Installments ---
    if ($action === 'add_installment' || $action === 'edit_installment') {
        $id = (int) ($_POST['id'] ?? 0);
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        $installmentNo = (int) ($_POST['installment_no'] ?? 1);
        $title = trim((string) ($_POST['title'] ?? ''));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $lateFeeType = in_array(trim((string) ($_POST['late_fee_type'] ?? '')), ['fixed', 'percentage'], true) ? trim((string) $_POST['late_fee_type']) : 'fixed';
        $lateFeeValue = (float) ($_POST['late_fee_value'] ?? 0);
        $lateFeeGraceDays = (int) ($_POST['late_fee_grace_days'] ?? 0);
        if ($feeStructureId < 1 || $dueDate === '' || $amount <= 0) {
            $error = 'Due date and amount are required.';
        } else {
            if ($action === 'edit_installment' && $id > 0) {
                $stmt = $pdo->prepare("UPDATE fee_installments SET installment_no=?, title=?, due_date=?, amount=?, late_fee_type=?, late_fee_value=?, late_fee_grace_days=? WHERE id=?");
                $stmt->execute([$installmentNo, $title, $dueDate, $amount, $lateFeeType, $lateFeeValue, $lateFeeGraceDays, $id]);
                $success = 'Installment updated.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO fee_installments (fee_structure_id, installment_no, title, due_date, amount, late_fee_type, late_fee_value, late_fee_grace_days) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$feeStructureId, $installmentNo, $title, $dueDate, $amount, $lateFeeType, $lateFeeValue, $lateFeeGraceDays]);
                $success = 'Installment added.';
            }
        }
    }

    if ($action === 'delete_installment') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM fee_installments WHERE id=?")->execute([$id]);
            $success = 'Installment deleted.';
        }
    }

    // --- Assignments ---
    if ($action === 'add_assignment') {
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        $assignType = in_array(trim((string) ($_POST['assign_type'] ?? '')), ['class', 'section'], true) ? trim((string) $_POST['assign_type']) : 'class';
        $assignValue = trim((string) ($_POST['assign_value'] ?? ''));
        if ($feeStructureId < 1 || $assignValue === '') {
            $error = 'Please select a structure and enter a value.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO fee_structure_assignments (fee_structure_id, assign_type, assign_value) VALUES (?,?,?)");
            $stmt->execute([$feeStructureId, $assignType, $assignValue]);
            $success = 'Assignment added.';
        }
    }

    if ($action === 'delete_assignment') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM fee_structure_assignments WHERE id=?")->execute([$id]);
            $success = 'Assignment deleted.';
        }
    }
}

// --- Fetch data ---
$feeHeads = $pdo->query("SELECT * FROM fee_heads ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$feeStructures = $pdo->query("SELECT * FROM fee_structures ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$feeStructuresById = [];
foreach ($feeStructures as $fs) {
    $feeStructuresById[(int) $fs['id']] = $fs;
}

$selStructureId = (int) ($_GET['structure_id'] ?? 0);
$selStructure = null;
$selItems = [];
$selInstallments = [];
$selAssignments = [];
if ($selStructureId > 0 && isset($feeStructuresById[$selStructureId])) {
    $selStructure = $feeStructuresById[$selStructureId];
    $stmt = $pdo->prepare("SELECT fsi.*, fh.name AS head_name FROM fee_structure_items fsi LEFT JOIN fee_heads fh ON fh.id = fsi.fee_head_id WHERE fsi.fee_structure_id=? ORDER BY fsi.id ASC");
    $stmt->execute([$selStructureId]);
    $selItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT * FROM fee_installments WHERE fee_structure_id=? ORDER BY installment_no ASC");
    $stmt->execute([$selStructureId]);
    $selInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT * FROM fee_structure_assignments WHERE fee_structure_id=? ORDER BY id ASC");
    $stmt->execute([$selStructureId]);
    $selAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$editHead = null;
if (isset($_GET['edit_head'])) {
    $stmt = $pdo->prepare("SELECT * FROM fee_heads WHERE id=?");
    $stmt->execute([(int) $_GET['edit_head']]);
    $editHead = $stmt->fetch(PDO::FETCH_ASSOC);
}

$editStructure = null;
if (isset($_GET['edit_structure'])) {
    $stmt = $pdo->prepare("SELECT * FROM fee_structures WHERE id=?");
    $stmt->execute([(int) $_GET['edit_structure']]);
    $editStructure = $stmt->fetch(PDO::FETCH_ASSOC);
}

$editInstallment = null;
if (isset($_GET['edit_installment'])) {
    $stmt = $pdo->prepare("SELECT * FROM fee_installments WHERE id=?");
    $stmt->execute([(int) $_GET['edit_installment']]);
    $editInstallment = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Fee Structures – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .tab-bar a { padding:.6rem 1.5rem; font-size:.9rem; font-weight:500; color:#64748b; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab-bar a.active { color:#2563eb; border-bottom-color:#2563eb; }
        .tab-bar a:hover { color:#2563eb; }
        .app-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .app-table th { text-align:left; padding:.65rem .5rem; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        .app-table td { padding:.65rem .5rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
        .app-table tr:hover td { background:#f8fafc; }
        .badge { display:inline-block; padding:.15rem .5rem; border-radius:4px; font-size:.75rem; font-weight:600; }
        .badge-yes { background:#d1fae5; color:#065f46; }
        .badge-no { background:#fee2e2; color:#991b1b; }
        .badge-optional { background:#fef3c7; color:#92400e; }
        .sub-table { margin:1rem 0 0 1.5rem; }
        .sub-table table { font-size:.82rem; }
        .sub-table table th { font-size:.72rem; }
        .total-row { font-weight:700; background:#f8fafc; }
        .inline-form { display:inline; }
        .section-divider { margin:1.5rem 0 .75rem; border:0; border-top:1px solid #e2e8f0; }
    </style>
</head>
<body>
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
            <div class="nav-title">Finance</div>
            <a class="nav-link" href="finance-dashboard.php"><span class="sidebar-icon">📊</span><span>Finance Dashboard</span></a>
            <a class="nav-link active" href="fee-structures.php"><span class="sidebar-icon">🏗</span><span>Fee Structures</span></a>
            <a class="nav-link" href="fee-collection.php"><span class="sidebar-icon">💰</span><span>Fee Collection</span></a>
            <a class="nav-link" href="receipts-list.php"><span class="sidebar-icon">🧾</span><span>Receipts</span></a>
            <a class="nav-link" href="expenses.php"><span class="sidebar-icon">📤</span><span>Expenses</span></a>
            <a class="nav-link" href="income.php"><span class="sidebar-icon">📥</span><span>Income</span></a>
            <a class="nav-link" href="accounts.php"><span class="sidebar-icon">🏦</span><span>Accounts</span></a>
            <a class="nav-link" href="reports.php"><span class="sidebar-icon">📈</span><span>Reports</span></a>
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
                    <span class="eyebrow">Finance</span>
                    <h1>Fee Structures</h1>
                    <p>Manage fee heads, structures, installments, and assignments.</p>
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
            <a href="?tab=fee-heads" class="<?= $tab === 'fee-heads' ? 'active' : '' ?>">Fee Heads</a>
            <a href="?tab=fee-structures" class="<?= $tab === 'fee-structures' ? 'active' : '' ?>">Fee Structures</a>
            <a href="?tab=installments" class="<?= $tab === 'installments' ? 'active' : '' ?>">Installments</a>
            <a href="?tab=assignments" class="<?= $tab === 'assignments' ? 'active' : '' ?>">Assignments</a>
        </div>

        <!-- ===================== TAB 1: FEE HEADS ===================== -->
        <?php if ($tab === 'fee-heads'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Fee Heads</h2>
                    <p>Define fee heads (components) used across fee structures.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('addHeadModal').classList.add('show')">+ Add Fee Head</button>
            </div>

            <?php if (empty($feeHeads)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No fee heads defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Mandatory</th>
                            <th>Sort Order</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($feeHeads as $fh): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($fh['name']) ?></strong></td>
                            <td style="max-width:200px;color:#64748b;"><?= e((string) ($fh['description'] ?? '')) ?: '—' ?></td>
                            <td><span class="badge <?= ($fh['is_mandatory'] ?? 1) ? 'badge-yes' : 'badge-no' ?>"><?= ($fh['is_mandatory'] ?? 1) ? 'Yes' : 'No' ?></span></td>
                            <td><?= (int) ($fh['sort_order'] ?? 0) ?></td>
                            <td>
                                <div style="display:flex;gap:.4rem;">
                                    <a class="btn btn-sm btn-soft" href="?tab=fee-heads&edit_head=<?= (int) $fh['id'] ?>">Edit</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete fee head &quot;<?= e($fh['name']) ?>&quot;? This may affect structures using it.')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_fee_head">
                                        <input type="hidden" name="id" value="<?= (int) $fh['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <!-- Add/Edit Fee Head Modal -->
        <div id="addHeadModal" class="modal-backdrop <?= $editHead ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= $editHead ? 'Edit Fee Head' : 'Add Fee Head' ?></h2>
                    <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editHead ? 'edit_fee_head' : 'add_fee_head' ?>">
                    <?php if ($editHead): ?>
                        <input type="hidden" name="id" value="<?= (int) $editHead['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label for="fh_name">Name *</label>
                            <input id="fh_name" name="name" type="text" required value="<?= e($editHead['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="fh_sort">Sort Order</label>
                            <input id="fh_sort" name="sort_order" type="number" value="<?= (int) ($editHead['sort_order'] ?? 0) ?>">
                        </div>
                        <div class="full-col">
                            <label for="fh_desc">Description</label>
                            <textarea id="fh_desc" name="description" rows="2"><?= e($editHead['description'] ?? '') ?></textarea>
                        </div>
                        <div class="full-col">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_mandatory" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editHead['is_mandatory'] ?? 1) ? 'checked' : '' ?>>
                                Mandatory fee head
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= $editHead ? 'Update' : 'Add' ?></button>
                        <a href="?tab=fee-heads" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===================== TAB 2: FEE STRUCTURES ===================== -->
        <?php if ($tab === 'fee-structures'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Fee Structures</h2>
                    <p>Create and manage fee structures with individual fee items.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('addStructModal').classList.add('show')">+ Add Structure</button>
            </div>

            <?php if (empty($feeStructures)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No fee structures yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Session</th>
                            <th>Class</th>
                            <th>Total Amount</th>
                            <th>Installments</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($feeStructures as $fs): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($fs['name']) ?></strong></td>
                            <td><?= e($fs['academic_session']) ?></td>
                            <td><?= e((string) ($fs['class_name'] ?? '—')) ?></td>
                            <td>Rs. <?= number_format((float) ($fs['total_amount'] ?? 0), 2) ?></td>
                            <td><?= ($fs['installment_enabled'] ?? 0) ? 'Yes (' . ((int) $fs['num_installments']) . ')' : 'No' ?></td>
                            <td>
                                <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                    <a class="btn btn-sm btn-soft" href="?tab=fee-structures&structure_id=<?= (int) $fs['id'] ?>">Manage Items</a>
                                    <a class="btn btn-sm btn-soft" href="?tab=fee-structures&edit_structure=<?= (int) $fs['id'] ?>">Edit</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete fee structure &quot;<?= e($fs['name']) ?>&quot;? All items, installments and assignments will be removed.')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_fee_structure">
                                        <input type="hidden" name="id" value="<?= (int) $fs['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <!-- Add/Edit Structure Modal -->
        <div id="addStructModal" class="modal-backdrop <?= $editStructure ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= $editStructure ? 'Edit Fee Structure' : 'Add Fee Structure' ?></h2>
                    <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editStructure ? 'edit_fee_structure' : 'add_fee_structure' ?>">
                    <?php if ($editStructure): ?>
                        <input type="hidden" name="id" value="<?= (int) $editStructure['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label for="fs_name">Name *</label>
                            <input id="fs_name" name="name" type="text" required value="<?= e($editStructure['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="fs_session">Academic Session</label>
                            <input id="fs_session" name="academic_session" type="text" value="<?= e($editStructure['academic_session'] ?? (date('Y') . '-' . substr((string)((int)date('Y') + 1), 2))) ?>">
                        </div>
                        <div>
                            <label for="fs_class">Class</label>
                            <select id="fs_class" name="class_name">
                                <option value="">All Classes</option>
                                <?php foreach ($classOptions as $co): ?>
                                    <option value="<?= e($co) ?>" <?= ($editStructure['class_name'] ?? '') === $co ? 'selected' : '' ?>><?= e($co) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="fs_installments">Number of Installments</label>
                            <input id="fs_installments" name="num_installments" type="number" min="1" value="<?= (int) ($editStructure['num_installments'] ?? 1) ?>">
                        </div>
                        <div class="full-col">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="installment_enabled" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editStructure['installment_enabled'] ?? 0) ? 'checked' : '' ?>>
                                Enable installments
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= $editStructure ? 'Update' : 'Add' ?></button>
                        <a href="?tab=fee-structures" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Items management for selected structure -->
        <?php if ($selStructure): ?>
        <hr class="section-divider">
        <section class="panel" style="padding:1.25rem;margin-top:1rem;">
            <div class="section-title">
                <div>
                    <h2>Items: <?= e($selStructure['name']) ?></h2>
                    <p>Academic Session: <?= e($selStructure['academic_session']) ?> | Class: <?= e((string) ($selStructure['class_name'] ?? 'All')) ?></p>
                </div>
                <div style="font-size:1.1rem;font-weight:700;">Total: Rs. <?= number_format((float) ($selStructure['total_amount'] ?? 0), 2) ?></div>
            </div>

            <form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;padding:1rem;background:#f8fafc;border-radius:12px;margin-bottom:1rem;">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
                <div>
                    <label for="item_head" style="font-size:.78rem;">Fee Head</label>
                    <select id="item_head" name="fee_head_id" required style="min-width:180px;">
                        <option value="">— Select —</option>
                        <?php foreach ($feeHeads as $fh): ?>
                            <option value="<?= (int) $fh['id'] ?>"><?= e($fh['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="item_amount" style="font-size:.78rem;">Amount (Rs.)</label>
                    <input id="item_amount" name="amount" type="number" step="0.01" min="0" required style="width:140px;">
                </div>
                <div>
                    <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.82rem;margin-bottom:0;">
                        <input type="checkbox" name="is_optional" value="1" style="width:auto;min-height:auto;">
                        Optional
                    </label>
                </div>
                <button type="submit" class="btn" style="min-height:42px;">Add Item</button>
            </form>

            <?php if (empty($selItems)): ?>
                <p style="text-align:center;padding:1rem;color:#94a3b8;">No items added yet.</p>
            <?php else: ?>
            <div class="sub-table">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Fee Head</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $j = 1; $sum = 0; foreach ($selItems as $item): $sum += (float) $item['amount']; ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $j++ ?></td>
                            <td><?= e($item['head_name'] ?? '—') ?></td>
                            <td>Rs. <?= number_format((float) $item['amount'], 2) ?></td>
                            <td><span class="badge <?= ($item['is_optional'] ?? 0) ? 'badge-optional' : 'badge-yes' ?>"><?= ($item['is_optional'] ?? 0) ? 'Optional' : 'Mandatory' ?></span></td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Remove this item?')">
                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
                                    <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="2" style="text-align:right;">Total:</td>
                            <td>Rs. <?= number_format($sum, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ===================== TAB 3: INSTALLMENTS ===================== -->
        <?php if ($tab === 'installments'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Installments</h2>
                    <p>Manage payment installments for fee structures.</p>
                </div>
            </div>

            <form method="get" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap;padding:1rem 0;">
                <input type="hidden" name="tab" value="installments">
                <div>
                    <label for="inst_struct">Select Fee Structure</label>
                    <select id="inst_struct" name="structure_id" onchange="this.form.submit()" style="min-width:280px;">
                        <option value="">— Select —</option>
                        <?php foreach ($feeStructures as $fs): ?>
                            <option value="<?= (int) $fs['id'] ?>" <?= $selStructureId === (int) $fs['id'] ? 'selected' : '' ?>>
                                <?= e($fs['name']) ?> (<?= e($fs['academic_session']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($selStructure): ?>
                <?php if (!($selStructure['installment_enabled'] ?? 0)): ?>
                    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:.75rem 1rem;color:#92400e;">
                        Installments are not enabled for this fee structure. Edit the structure to enable installments.
                    </div>
                <?php else: ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <p><strong>Structure:</strong> <?= e($selStructure['name']) ?> | <strong>Total:</strong> Rs. <?= number_format((float) ($selStructure['total_amount'] ?? 0), 2) ?></p>
                    <button type="button" class="btn btn-sm" onclick="document.getElementById('addInstModal').classList.add('show')">+ Add Installment</button>
                </div>

                <?php if (empty($selInstallments)): ?>
                    <p style="text-align:center;padding:2rem;color:#94a3b8;">No installments defined yet.</p>
                <?php else: ?>
                <?php $instTotal = 0; ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Title</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Late Fee Type</th>
                                <th>Late Fee Value</th>
                                <th>Grace Days</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selInstallments as $inst): $instTotal += (float) $inst['amount']; ?>
                            <tr>
                                <td style="color:#94a3b8;"><?= (int) $inst['installment_no'] ?></td>
                                <td><?= e((string) ($inst['title'] ?? '')) ?: '—' ?></td>
                                <td><?= e($inst['due_date']) ?></td>
                                <td>Rs. <?= number_format((float) $inst['amount'], 2) ?></td>
                                <td><?= e($inst['late_fee_type'] ?? 'fixed') ?></td>
                                <td><?= e((string) ($inst['late_fee_value'] ?? '0')) ?></td>
                                <td><?= (int) ($inst['late_fee_grace_days'] ?? 0) ?></td>
                                <td>
                                    <div style="display:flex;gap:.4rem;">
                                        <a class="btn btn-sm btn-soft" href="?tab=installments&structure_id=<?= $selStructureId ?>&edit_installment=<?= (int) $inst['id'] ?>">Edit</a>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this installment?')">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_installment">
                                            <input type="hidden" name="id" value="<?= (int) $inst['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="3" style="text-align:right;">Total Installments:</td>
                                <td>Rs. <?= number_format($instTotal, 2) ?></td>
                                <td colspan="4"></td>
                            </tr>
                            <tr>
                                <td colspan="3" style="text-align:right;">Structure Total:</td>
                                <td>Rs. <?= number_format((float) ($selStructure['total_amount'] ?? 0), 2) ?></td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if (abs($instTotal - (float) ($selStructure['total_amount'] ?? 0)) > 0.01): ?>
                    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:.5rem 1rem;color:#92400e;margin-top:.5rem;font-size:.85rem;">
                        Total of installments (Rs. <?= number_format($instTotal, 2) ?>) does not match structure total (Rs. <?= number_format((float) ($selStructure['total_amount'] ?? 0), 2) ?>).
                    </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">Select a fee structure with installments enabled to manage installments.</p>
            <?php endif; ?>
        </section>

        <!-- Add/Edit Installment Modal -->
        <div id="addInstModal" class="modal-backdrop <?= $editInstallment ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= $editInstallment ? 'Edit Installment' : 'Add Installment' ?></h2>
                    <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editInstallment ? 'edit_installment' : 'add_installment' ?>">
                    <?php if ($editInstallment): ?>
                        <input type="hidden" name="id" value="<?= (int) $editInstallment['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
                    <div class="field-grid">
                        <div>
                            <label for="inst_no">Installment No *</label>
                            <input id="inst_no" name="installment_no" type="number" min="1" required value="<?= (int) ($editInstallment['installment_no'] ?? (count($selInstallments) + 1)) ?>">
                        </div>
                        <div>
                            <label for="inst_title">Title</label>
                            <input id="inst_title" name="title" type="text" value="<?= e($editInstallment['title'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="inst_due">Due Date *</label>
                            <input id="inst_due" name="due_date" type="date" required value="<?= e($editInstallment['due_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="inst_amt">Amount (Rs.) *</label>
                            <input id="inst_amt" name="amount" type="number" step="0.01" min="0" required value="<?= e((string) ($editInstallment['amount'] ?? '0')) ?>">
                        </div>
                        <div>
                            <label for="inst_late_type">Late Fee Type</label>
                            <select id="inst_late_type" name="late_fee_type">
                                <option value="fixed" <?= ($editInstallment['late_fee_type'] ?? 'fixed') === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                                <option value="percentage" <?= ($editInstallment['late_fee_type'] ?? '') === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                            </select>
                        </div>
                        <div>
                            <label for="inst_late_val">Late Fee Value</label>
                            <input id="inst_late_val" name="late_fee_value" type="number" step="0.01" min="0" value="<?= e((string) ($editInstallment['late_fee_value'] ?? '0')) ?>">
                        </div>
                        <div>
                            <label for="inst_grace">Grace Days</label>
                            <input id="inst_grace" name="late_fee_grace_days" type="number" min="0" value="<?= (int) ($editInstallment['late_fee_grace_days'] ?? 0) ?>">
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= $editInstallment ? 'Update' : 'Add' ?></button>
                        <a href="?tab=installments&structure_id=<?= $selStructureId ?>" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===================== TAB 4: ASSIGNMENTS ===================== -->
        <?php if ($tab === 'assignments'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Assignments</h2>
                    <p>Assign fee structures to classes or sections.</p>
                </div>
            </div>

            <form method="get" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap;padding:1rem 0;">
                <input type="hidden" name="tab" value="assignments">
                <div>
                    <label for="asgn_struct">Select Fee Structure</label>
                    <select id="asgn_struct" name="structure_id" onchange="this.form.submit()" style="min-width:280px;">
                        <option value="">— Select —</option>
                        <?php foreach ($feeStructures as $fs): ?>
                            <option value="<?= (int) $fs['id'] ?>" <?= $selStructureId === (int) $fs['id'] ? 'selected' : '' ?>>
                                <?= e($fs['name']) ?> (<?= e($fs['academic_session']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($selStructure): ?>
            <form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;padding:1rem;background:#f8fafc;border-radius:12px;margin-bottom:1rem;">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_assignment">
                <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
                <div>
                    <label for="asgn_type" style="font-size:.78rem;">Assign Type</label>
                    <select id="asgn_type" name="assign_type" style="min-width:140px;">
                        <option value="class">Class</option>
                        <option value="section">Section</option>
                    </select>
                </div>
                <div>
                    <label for="asgn_val" style="font-size:.78rem;">Value</label>
                    <input id="asgn_val" name="assign_value" type="text" required placeholder="e.g. Class 1 or A" style="min-width:180px;">
                </div>
                <button type="submit" class="btn" style="min-height:42px;">Add Assignment</button>
            </form>

            <?php if (empty($selAssignments)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No assignments yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $k = 1; foreach ($selAssignments as $asgn): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $k++ ?></td>
                            <td><span class="badge" style="background:#e0e7ff;color:#3730a3;"><?= e($asgn['assign_type']) ?></span></td>
                            <td><strong><?= e($asgn['assign_value']) ?></strong></td>
                            <td><span class="badge <?= ($asgn['is_active'] ?? 1) ? 'badge-yes' : 'badge-no' ?>"><?= ($asgn['is_active'] ?? 1) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this assignment?')">
                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_assignment">
                                    <input type="hidden" name="id" value="<?= (int) $asgn['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php else: ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">Select a fee structure to manage its assignments.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    </main>
</div>
<script>
document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
    backdrop.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
        }
    });
});
</script>
<script src="../assets/erp.js"></script>
</body>
</html>