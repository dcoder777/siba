<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$error = '';
$success = '';

$classOptions = ['Play School', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8'];
$frequencyOptions = ['Monthly', 'Quarterly', 'Half-Yearly', 'Yearly', 'One-Time'];

// ─── Ensure tables exist ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category VARCHAR(100),
        default_amount DECIMAL(10,2) DEFAULT 0,
        frequency VARCHAR(30) DEFAULT 'One-Time',
        is_refundable TINYINT(1) DEFAULT 0,
        late_fee_applicable TINYINT(1) DEFAULT 0,
        is_mandatory TINYINT(1) DEFAULT 1,
        is_active TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_structures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        academic_year_id INT DEFAULT NULL,
        academic_session VARCHAR(20) NOT NULL,
        class_name VARCHAR(50),
        student_category VARCHAR(50) DEFAULT 'General',
        total_amount DECIMAL(12,2) DEFAULT 0,
        emi_allowed TINYINT(1) DEFAULT 0,
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
        fee_head_name VARCHAR(100) DEFAULT '',
        amount DECIMAL(10,2) NOT NULL,
        frequency VARCHAR(30) DEFAULT 'One-Time',
        is_optional TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_structure_installments (
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
        assign_type ENUM('Class','Section','Individual') NOT NULL DEFAULT 'Class',
        assign_value VARCHAR(200) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

// ─── Add new columns to legacy tables if missing ───
try {
    $cols = array_map(static fn(array $r): string => (string) $r['Field'], $pdo->query("DESCRIBE fee_structures")->fetchAll());
    $addCols = [
        'academic_year_id' => "INT DEFAULT NULL",
        'student_category' => "VARCHAR(50) DEFAULT 'General'",
        'emi_allowed' => "TINYINT(1) DEFAULT 0",
        'is_active' => "TINYINT(1) DEFAULT 1",
    ];
    foreach ($addCols as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE fee_structures ADD COLUMN {$col} {$def}");
        }
    }
} catch (Throwable $e) {}

try {
    $cols = array_map(static fn(array $r): string => (string) $r['Field'], $pdo->query("DESCRIBE fee_structure_items")->fetchAll());
    if (!in_array('fee_head_name', $cols, true)) {
        $pdo->exec("ALTER TABLE fee_structure_items ADD COLUMN fee_head_name VARCHAR(100) DEFAULT ''");
    }
    if (!in_array('frequency', $cols, true)) {
        $pdo->exec("ALTER TABLE fee_structure_items ADD COLUMN frequency VARCHAR(30) DEFAULT 'One-Time'");
    }
} catch (Throwable $e) {}

// ─── Helper: Recalculate total_amount from items ───
function recalculate_total(PDO $pdo, int $structureId): void
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM fee_structure_items WHERE fee_structure_id=?");
    $stmt->execute([$structureId]);
    $total = (float) $stmt->fetchColumn();
    $upd = $pdo->prepare("UPDATE fee_structures SET total_amount=? WHERE id=?");
    $upd->execute([$total, $structureId]);
}

// ─── Helper: Fee heads dropdown options ───
function fee_heads_options(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, name FROM fee_heads WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}

// ─── POST HANDLING ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_structure') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $academicSession = trim((string) ($_POST['academic_session'] ?? date('Y') . '-' . substr((string)((int) date('Y') + 1), 2)));
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $studentCategory = trim((string) ($_POST['student_category'] ?? 'General'));
        $emiAllowed = isset($_POST['emi_allowed']) ? 1 : 0;
        $numInstallments = max(1, (int) ($_POST['num_installments'] ?? 1));
        $isActive = isset($_POST['is_active']) ? 1 : 1;

        if ($name === '') {
            $error = 'Fee structure name is required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO fee_structures (name, academic_session, class_name, student_category, emi_allowed, num_installments, is_active) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$name, $academicSession, $className ?: null, $studentCategory, $emiAllowed, $numInstallments, $isActive]);
            $newId = (int) $pdo->lastInsertId();
            $success = 'Fee structure created.';
            header('Location: fee-structure-new.php?structure_id=' . $newId);
            exit;
        }
    }

    if ($action === 'update_structure') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $academicSession = trim((string) ($_POST['academic_session'] ?? ''));
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $studentCategory = trim((string) ($_POST['student_category'] ?? 'General'));
        $emiAllowed = isset($_POST['emi_allowed']) ? 1 : 0;
        $numInstallments = max(1, (int) ($_POST['num_installments'] ?? 1));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare("UPDATE fee_structures SET name=?, academic_session=?, class_name=?, student_category=?, emi_allowed=?, num_installments=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $academicSession, $className ?: null, $studentCategory, $emiAllowed, $numInstallments, $isActive, $id]);
            $success = 'Fee structure updated.';
        } else {
            $error = 'Invalid data.';
        }
        header('Location: fee-structure-new.php?structure_id=' . $id);
        exit;
    }

    if ($action === 'delete_structure') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM fee_structure_assignments WHERE fee_structure_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM fee_structure_installments WHERE fee_structure_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM fee_structure_items WHERE fee_structure_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM fee_structures WHERE id=?")->execute([$id]);
            $success = 'Fee structure deleted.';
        }
        header('Location: fee-structure-new.php');
        exit;
    }

    if ($action === 'add_item') {
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        $feeHeadId = (int) ($_POST['fee_head_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $frequency = trim((string) ($_POST['frequency'] ?? 'One-Time'));
        $isOptional = isset($_POST['is_optional']) ? 1 : 0;

        if ($feeStructureId > 0 && $feeHeadId > 0 && $amount > 0) {
            $headName = '';
            $hstmt = $pdo->prepare("SELECT name FROM fee_heads WHERE id=?");
            $hstmt->execute([$feeHeadId]);
            $headName = (string) $hstmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO fee_structure_items (fee_structure_id, fee_head_id, fee_head_name, amount, frequency, is_optional) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$feeStructureId, $feeHeadId, $headName, $amount, $frequency, $isOptional]);
            recalculate_total($pdo, $feeStructureId);
            $success = 'Item added.';
        } else {
            $error = 'Select a fee head and enter a valid amount.';
        }
        header('Location: fee-structure-new.php?structure_id=' . $feeStructureId . '&tab=items');
        exit;
    }

    if ($action === 'delete_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        if ($itemId > 0 && $feeStructureId > 0) {
            $pdo->prepare("DELETE FROM fee_structure_items WHERE id=?")->execute([$itemId]);
            recalculate_total($pdo, $feeStructureId);
            $success = 'Item removed.';
        }
        header('Location: fee-structure-new.php?structure_id=' . $feeStructureId . '&tab=items');
        exit;
    }

    if ($action === 'add_installment') {
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        $installmentNo = (int) ($_POST['installment_no'] ?? 1);
        $title = trim((string) ($_POST['title'] ?? ''));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $lateFeeType = in_array(trim((string) ($_POST['late_fee_type'] ?? '')), ['fixed', 'percentage'], true) ? trim((string) $_POST['late_fee_type']) : 'fixed';
        $lateFeeValue = (float) ($_POST['late_fee_value'] ?? 0);
        $lateFeeGraceDays = (int) ($_POST['late_fee_grace_days'] ?? 0);

        if ($feeStructureId > 0 && $dueDate !== '' && $amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO fee_structure_installments (fee_structure_id, installment_no, title, due_date, amount, late_fee_type, late_fee_value, late_fee_grace_days) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$feeStructureId, $installmentNo, $title, $dueDate, $amount, $lateFeeType, $lateFeeValue, $lateFeeGraceDays]);
            $success = 'Installment added.';
        } else {
            $error = 'Due date and amount are required.';
        }
        header('Location: fee-structure-new.php?structure_id=' . $feeStructureId . '&tab=installments');
        exit;
    }

    if ($action === 'delete_installment') {
        $id = (int) ($_POST['id'] ?? 0);
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM fee_structure_installments WHERE id=?")->execute([$id]);
            $success = 'Installment deleted.';
        }
        header('Location: fee-structure-new.php?structure_id=' . $feeStructureId . '&tab=installments');
        exit;
    }

    if ($action === 'add_assignment') {
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        $assignType = in_array(trim((string) ($_POST['assign_type'] ?? '')), ['Class', 'Section', 'Individual'], true) ? trim((string) $_POST['assign_type']) : 'Class';
        $assignValue = trim((string) ($_POST['assign_value'] ?? ''));

        if ($feeStructureId > 0 && $assignValue !== '') {
            $stmt = $pdo->prepare("INSERT INTO fee_structure_assignments (fee_structure_id, assign_type, assign_value) VALUES (?,?,?)");
            $stmt->execute([$feeStructureId, $assignType, $assignValue]);
            $success = 'Assignment added.';
        } else {
            $error = 'Enter a value for the assignment.';
        }
        header('Location: fee-structure-new.php?structure_id=' . $feeStructureId . '&tab=assignments');
        exit;
    }

    if ($action === 'delete_assignment') {
        $id = (int) ($_POST['id'] ?? 0);
        $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM fee_structure_assignments WHERE id=?")->execute([$id]);
            $success = 'Assignment deleted.';
        }
        header('Location: fee-structure-new.php?structure_id=' . $feeStructureId . '&tab=assignments');
        exit;
    }
}

// ─── GET DATA ───
$feeHeads = $pdo->query("SELECT * FROM fee_heads WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
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

    $stmt = $pdo->prepare("SELECT * FROM fee_structure_items WHERE fee_structure_id=? ORDER BY id ASC");
    $stmt->execute([$selStructureId]);
    $selItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM fee_structure_installments WHERE fee_structure_id=? ORDER BY installment_no ASC");
    $stmt->execute([$selStructureId]);
    $selInstallments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM fee_structure_assignments WHERE fee_structure_id=? ORDER BY id ASC");
    $stmt->execute([$selStructureId]);
    $selAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$tab = in_array(trim((string) ($_GET['tab'] ?? '')), ['items', 'installments', 'assignments'], true)
    ? trim((string) $_GET['tab'])
    : 'items';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Fee Structure Management – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .layout-split{display:grid;grid-template-columns:1fr 1.4fr;gap:1.25rem;align-items:start}
        .list-panel{position:sticky;top:1.5rem;max-height:calc(100vh - 3rem);overflow-y:auto}
        .detail-panel{min-height:400px}
        .structure-card{padding:.85rem 1rem;border-radius:var(--radius-md);border:1px solid var(--line);background:#fff;cursor:pointer;transition:border-color .12s ease,box-shadow .12s ease;margin-bottom:.6rem}
        .structure-card:hover{border-color:var(--brand);box-shadow:var(--shadow-md)}
        .structure-card.selected{border-color:var(--brand);background:var(--brand-soft);box-shadow:var(--shadow-md)}
        .structure-card .card-title{font-weight:700;font-size:.9rem;margin-bottom:.25rem}
        .structure-card .card-meta{font-size:.78rem;color:var(--muted);display:flex;gap:.75rem;flex-wrap:wrap}
        .structure-card .card-amount{font-weight:700;font-size:.95rem;color:var(--ink)}
        .detail-tabs{display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:1rem}
        .detail-tabs a{padding:.5rem 1.2rem;font-size:.85rem;font-weight:600;color:var(--muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px}
        .detail-tabs a.active{color:var(--brand);border-bottom-color:var(--brand)}
        .detail-tabs a:hover{color:var(--brand)}
        .summary-row{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
        .summary-chip{padding:.5rem .85rem;border-radius:var(--radius-sm);background:var(--surface-alt);border:1px solid var(--line);font-size:.82rem}
        .summary-chip strong{display:block;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.15rem}
        .empty-detail{padding:3rem 1rem;text-align:center;color:var(--muted)}
        .empty-detail h3{margin-bottom:.5rem}
        .add-form{display:flex;gap:.75rem;align-items:end;flex-wrap:wrap;padding:1rem;background:var(--surface-alt);border-radius:var(--radius-md);border:1px solid var(--line);margin-bottom:1rem}
        .add-form > div{display:flex;flex-direction:column;gap:.25rem}
        .add-form label{font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
        .inline-form{display:inline}
        .total-highlight{font-size:1.15rem;font-weight:700;color:var(--ink)}
        .inst-total-warn{background:#fef3c7;border:1px solid #fde68a;border-radius:var(--radius-sm);padding:.5rem .85rem;color:#92400e;font-size:.82rem;margin-top:.5rem}
        .inst-total-ok{background:#d1fae5;border:1px solid #a7f3d0;border-radius:var(--radius-sm);padding:.5rem .85rem;color:#065f46;font-size:.82rem;margin-top:.5rem}
        .badge-class{background:#e0e7ff;color:#3730a3}
        .badge-section{background:#dbeafe;color:#1e40af}
        .badge-individual{background:#fce7f3;color:#9d174d}
        .badge-active{background:#d1fae5;color:#065f46}
        .badge-inactive{background:#fee2e2;color:#991b1b}
        @media(max-width:960px){.layout-split{grid-template-columns:1fr}.list-panel{position:static;max-height:none}}
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
                    <h1>Fee Structure Management</h1>
                    <p>Create and manage fee structures with line items, installment schedules, and class/section assignments.</p>
                </div>
                <div class="toolbar-right">
                    <button type="button" class="btn" onclick="document.getElementById('createModal').classList.add('show')">+ New Fee Structure</button>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="layout-split">
            <!-- LEFT: Fee Structures List -->
            <div class="list-panel">
                <div class="panel" style="padding:1rem;">
                    <div class="section-title" style="margin-bottom:.75rem;">
                        <div>
                            <h2 style="font-size:1rem;">All Fee Structures</h2>
                            <p style="font-size:.78rem;"><?= count($feeStructures) ?> structure(s)</p>
                        </div>
                    </div>
                    <?php if (empty($feeStructures)): ?>
                        <p style="text-align:center;padding:1.5rem;color:var(--muted);font-size:.85rem;">No fee structures yet.</p>
                    <?php else: ?>
                        <?php foreach ($feeStructures as $fs): ?>
                            <a href="?structure_id=<?= (int) $fs['id'] ?>&tab=<?= e($tab) ?>" class="structure-card <?= $selStructureId === (int) $fs['id'] ? 'selected' : '' ?>" style="text-decoration:none;color:inherit;display:block;">
                                <div class="card-title"><?= e($fs['name']) ?></div>
                                <div class="card-meta">
                                    <span><?= e($fs['academic_session']) ?></span>
                                    <span><?= e((string) ($fs['class_name'] ?: 'All Classes')) ?></span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.4rem;">
                                    <span class="card-amount">Rs. <?= number_format((float) ($fs['total_amount'] ?? 0), 2) ?></span>
                                    <span class="badge <?= ($fs['is_active'] ?? 1) ? 'badge-active' : 'badge-inactive' ?>"><?= ($fs['is_active'] ?? 1) ? 'Active' : 'Inactive' ?></span>
                                </div>
                                <div class="card-meta" style="margin-top:.25rem;">
                                    <span><?= ($fs['emi_allowed'] ?? 0) ? ((int) $fs['num_installments'] . ' installments') : 'Full Payment' ?></span>
                                    <span><?= e((string) ($fs['student_category'] ?: 'General')) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Detail Panel -->
            <div class="detail-panel">
                <?php if ($selStructure): ?>
                    <div class="panel" style="padding:1.25rem;">
                        <div class="section-title">
                            <div>
                                <h2><?= e($selStructure['name']) ?></h2>
                                <p>Session: <?= e($selStructure['academic_session']) ?> | Class: <?= e((string) ($selStructure['class_name'] ?: 'All')) ?> | Category: <?= e((string) ($selStructure['student_category'] ?: 'General')) ?></p>
                            </div>
                            <div style="display:flex;gap:.4rem;align-items:center;">
                                <button type="button" class="btn btn-sm btn-soft" onclick="document.getElementById('editModal').classList.add('show')">Edit</button>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete fee structure &quot;<?= e($selStructure['name']) ?>&quot;? All items, installments and assignments will be removed.')">
                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_structure">
                                    <input type="hidden" name="id" value="<?= (int) $selStructure['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                </form>
                            </div>
                        </div>

                        <div class="summary-row">
                            <div class="summary-chip">
                                <strong>Total Amount</strong>
                                <span class="total-highlight">Rs. <?= number_format((float) ($selStructure['total_amount'] ?? 0), 2) ?></span>
                            </div>
                            <div class="summary-chip">
                                <strong>Items</strong>
                                <?= count($selItems) ?>
                            </div>
                            <div class="summary-chip">
                                <strong>Installments</strong>
                                <?= ($selStructure['emi_allowed'] ?? 0) ? ((int) $selStructure['num_installments']) . ' allowed' : 'No EMI' ?>
                            </div>
                            <div class="summary-chip">
                                <strong>Assigned To</strong>
                                <?= count($selAssignments) ?> group(s)
                            </div>
                        </div>

                        <!-- Detail Tabs -->
                        <div class="detail-tabs">
                            <a href="?structure_id=<?= $selStructureId ?>&tab=items" class="<?= $tab === 'items' ? 'active' : '' ?>">Items (<?= count($selItems) ?>)</a>
                            <a href="?structure_id=<?= $selStructureId ?>&tab=installments" class="<?= $tab === 'installments' ? 'active' : '' ?>">Installments (<?= count($selInstallments) ?>)</a>
                            <a href="?structure_id=<?= $selStructureId ?>&tab=assignments" class="<?= $tab === 'assignments' ? 'active' : '' ?>">Assignments (<?= count($selAssignments) ?>)</a>
                        </div>

                        <!-- TAB: ITEMS -->
                        <?php if ($tab === 'items'): ?>
                            <button type="button" class="btn btn-sm" style="margin-bottom:1rem;" onclick="document.getElementById('addItemModal').classList.add('show')">+ Add Item</button>

                            <?php if (empty($selItems)): ?>
                                <div class="empty-detail"><h3>No Items</h3><p>Add fee heads as line items to this structure.</p></div>
                            <?php else: ?>
                                <div style="overflow-x:auto;">
                                    <table class="app-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Fee Head</th>
                                                <th>Amount</th>
                                                <th>Frequency</th>
                                                <th>Optional</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $j = 1; foreach ($selItems as $item): ?>
                                            <tr>
                                                <td style="color:#94a3b8;"><?= $j++ ?></td>
                                                <td><strong><?= e((string) ($item['fee_head_name'] ?: '—')) ?></strong></td>
                                                <td>Rs. <?= number_format((float) $item['amount'], 2) ?></td>
                                                <td><?= e((string) ($item['frequency'] ?: 'One-Time')) ?></td>
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
                                            <tr style="font-weight:700;background:#f8fafc;">
                                                <td colspan="2" style="text-align:right;">Total:</td>
                                                <td>Rs. <?= number_format((float) ($selStructure['total_amount'] ?? 0), 2) ?></td>
                                                <td colspan="3"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- TAB: INSTALLMENTS -->
                        <?php if ($tab === 'installments'): ?>
                            <?php if (!($selStructure['emi_allowed'] ?? 0)): ?>
                                <div class="inst-total-warn">EMI is not enabled for this structure. Edit the structure to enable installments.</div>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm" style="margin-bottom:1rem;" onclick="document.getElementById('addInstallmentModal').classList.add('show')">+ Add Installment</button>

                                <?php if (empty($selInstallments)): ?>
                                    <div class="empty-detail"><h3>No Installments</h3><p>Define installment milestones for this fee structure.</p></div>
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
                                                    <th>Late Fee</th>
                                                    <th>Grace Days</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($selInstallments as $inst): $instTotal += (float) $inst['amount']; ?>
                                                <tr>
                                                    <td style="color:#94a3b8;"><?= (int) $inst['installment_no'] ?></td>
                                                    <td><strong><?= e((string) ($inst['title'] ?: 'Installment ' . $inst['installment_no'])) ?></strong></td>
                                                    <td><?= e($inst['due_date']) ?></td>
                                                    <td>Rs. <?= number_format((float) $inst['amount'], 2) ?></td>
                                                    <td>
                                                        <?php if ((float) ($inst['late_fee_value'] ?? 0) > 0): ?>
                                                            <?= e($inst['late_fee_type'] === 'percentage' ? $inst['late_fee_value'] . '%' : 'Rs. ' . $inst['late_fee_value']) ?>
                                                        <?php else: ?>
                                                            <span style="color:#94a3b8;">None</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= (int) ($inst['late_fee_grace_days'] ?? 0) ?></td>
                                                    <td>
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this installment?')">
                                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="delete_installment">
                                                            <input type="hidden" name="id" value="<?= (int) $inst['id'] ?>">
                                                            <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
                                                            <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr style="font-weight:700;background:#f8fafc;">
                                                    <td colspan="3" style="text-align:right;">Installment Total:</td>
                                                    <td>Rs. <?= number_format($instTotal, 2) ?></td>
                                                    <td colspan="3"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    <?php if (abs($instTotal - (float) ($selStructure['total_amount'] ?? 0)) > 0.01): ?>
                                        <div class="inst-total-warn">Installment total (Rs. <?= number_format($instTotal, 2) ?>) does not match structure total (Rs. <?= number_format((float) ($selStructure['total_amount'] ?? 0), 2) ?>).</div>
                                    <?php else: ?>
                                        <div class="inst-total-ok">Installment total matches structure total.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- TAB: ASSIGNMENTS -->
                        <?php if ($tab === 'assignments'): ?>
                            <button type="button" class="btn btn-sm" style="margin-bottom:1rem;" onclick="document.getElementById('addAssignmentModal').classList.add('show')">+ Add Assignment</button>

                            <?php if (empty($selAssignments)): ?>
                                <div class="empty-detail"><h3>No Assignments</h3><p>Assign this fee structure to classes, sections, or individual students.</p></div>
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
                                                <td>
                                                    <?php if ($asgn['assign_type'] === 'Class'): ?>
                                                        <span class="badge badge-class"><?= e($asgn['assign_type']) ?></span>
                                                    <?php elseif ($asgn['assign_type'] === 'Section'): ?>
                                                        <span class="badge badge-section"><?= e($asgn['assign_type']) ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-individual"><?= e($asgn['assign_type']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong><?= e($asgn['assign_value']) ?></strong></td>
                                                <td><span class="badge <?= ($asgn['is_active'] ?? 1) ? 'badge-active' : 'badge-inactive' ?>"><?= ($asgn['is_active'] ?? 1) ? 'Active' : 'Inactive' ?></span></td>
                                                <td>
                                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this assignment?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="delete_assignment">
                                                        <input type="hidden" name="id" value="<?= (int) $asgn['id'] ?>">
                                                        <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
                                                        <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="panel" style="padding:3rem;">
                        <div class="empty-detail">
                            <h3>Select a Fee Structure</h3>
                            <p>Choose a fee structure from the left panel to view and manage its details.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- ═══════════════ MODAL: Create Fee Structure ═══════════════ -->
<div id="createModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Create Fee Structure</h2>
            <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_structure">
            <div class="field-grid">
                <div>
                    <label for="cs_name">Name *</label>
                    <input id="cs_name" name="name" type="text" required placeholder="e.g. Annual Fee 2025-26">
                </div>
                <div>
                    <label for="cs_session">Academic Session</label>
                    <input id="cs_session" name="academic_session" type="text" value="<?= e(date('Y') . '-' . substr((string)((int) date('Y') + 1), 2)) ?>">
                </div>
                <div>
                    <label for="cs_class">Class</label>
                    <select id="cs_class" name="class_name">
                        <option value="">All Classes</option>
                        <?php foreach ($classOptions as $co): ?>
                            <option value="<?= e($co) ?>"><?= e($co) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="cs_category">Student Category</label>
                    <input id="cs_category" name="student_category" type="text" value="General" placeholder="e.g. General, SC/ST, Staff Ward">
                </div>
                <div>
                    <label for="cs_installments">Number of Installments</label>
                    <input id="cs_installments" name="num_installments" type="number" min="1" value="1">
                </div>
                <div class="full-col" style="display:flex;gap:1.5rem;margin-top:.5rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                        <input type="checkbox" name="emi_allowed" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;">
                        Enable EMI / Installments
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                        <input type="checkbox" name="is_active" value="1" checked style="width:auto;min-height:auto;accent-color:#2563eb;">
                        Active
                    </label>
                </div>
            </div>
            <div class="action-row" style="margin-top:1.5rem;">
                <button type="submit" class="btn">Create Structure</button>
                <button type="button" class="btn btn-soft" onclick="this.closest('.modal-backdrop').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════ MODAL: Edit Fee Structure ═══════════════ -->
<?php if ($selStructure): ?>
<div id="editModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Edit Fee Structure</h2>
            <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_structure">
            <input type="hidden" name="id" value="<?= (int) $selStructure['id'] ?>">
            <div class="field-grid">
                <div>
                    <label for="es_name">Name *</label>
                    <input id="es_name" name="name" type="text" required value="<?= e($selStructure['name']) ?>">
                </div>
                <div>
                    <label for="es_session">Academic Session</label>
                    <input id="es_session" name="academic_session" type="text" value="<?= e($selStructure['academic_session']) ?>">
                </div>
                <div>
                    <label for="es_class">Class</label>
                    <select id="es_class" name="class_name">
                        <option value="">All Classes</option>
                        <?php foreach ($classOptions as $co): ?>
                            <option value="<?= e($co) ?>" <?= ($selStructure['class_name'] ?? '') === $co ? 'selected' : '' ?>><?= e($co) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="es_category">Student Category</label>
                    <input id="es_category" name="student_category" type="text" value="<?= e((string) ($selStructure['student_category'] ?? 'General')) ?>">
                </div>
                <div>
                    <label for="es_installments">Number of Installments</label>
                    <input id="es_installments" name="num_installments" type="number" min="1" value="<?= (int) ($selStructure['num_installments'] ?? 1) ?>">
                </div>
                <div class="full-col" style="display:flex;gap:1.5rem;margin-top:.5rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                        <input type="checkbox" name="emi_allowed" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($selStructure['emi_allowed'] ?? 0) ? 'checked' : '' ?>>
                        Enable EMI / Installments
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                        <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($selStructure['is_active'] ?? 1) ? 'checked' : '' ?>>
                        Active
                    </label>
                </div>
            </div>
            <div class="action-row" style="margin-top:1.5rem;">
                <button type="submit" class="btn">Update Structure</button>
                <button type="button" class="btn btn-soft" onclick="this.closest('.modal-backdrop').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════ MODAL: Add Item ═══════════════ -->
<div id="addItemModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Add Fee Item</h2>
            <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_item">
            <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
            <div class="field-grid">
                <div>
                    <label for="ai_head">Fee Head *</label>
                    <select id="ai_head" name="fee_head_id" required>
                        <option value="">— Select Fee Head —</option>
                        <?php foreach ($feeHeads as $fh): ?>
                            <option value="<?= (int) $fh['id'] ?>"><?= e($fh['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="ai_amount">Amount (Rs.) *</label>
                    <input id="ai_amount" name="amount" type="number" step="0.01" min="0" required>
                </div>
                <div>
                    <label for="ai_frequency">Frequency</label>
                    <select id="ai_frequency" name="frequency">
                        <?php foreach ($frequencyOptions as $fo): ?>
                            <option value="<?= e($fo) ?>"><?= e($fo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;align-items:end;padding-bottom:.1rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                        <input type="checkbox" name="is_optional" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;">
                        Optional Fee
                    </label>
                </div>
            </div>
            <div class="action-row" style="margin-top:1.5rem;">
                <button type="submit" class="btn">Add Item</button>
                <button type="button" class="btn btn-soft" onclick="this.closest('.modal-backdrop').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════ MODAL: Add Installment ═══════════════ -->
<div id="addInstallmentModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Add Installment</h2>
            <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_installment">
            <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
            <div class="field-grid">
                <div>
                    <label for="ai_no">Installment No *</label>
                    <input id="ai_no" name="installment_no" type="number" min="1" required value="<?= count($selInstallments) + 1 ?>">
                </div>
                <div>
                    <label for="ai_title">Title</label>
                    <input id="ai_title" name="title" type="text" placeholder="e.g. 1st Installment">
                </div>
                <div>
                    <label for="ai_due">Due Date *</label>
                    <input id="ai_due" name="due_date" type="date" required>
                </div>
                <div>
                    <label for="ai_inst_amt">Amount (Rs.) *</label>
                    <input id="ai_inst_amt" name="amount" type="number" step="0.01" min="0" required>
                </div>
                <div>
                    <label for="ai_late_type">Late Fee Type</label>
                    <select id="ai_late_type" name="late_fee_type">
                        <option value="fixed">Fixed</option>
                        <option value="percentage">Percentage</option>
                    </select>
                </div>
                <div>
                    <label for="ai_late_val">Late Fee Value</label>
                    <input id="ai_late_val" name="late_fee_value" type="number" step="0.01" min="0" value="0">
                </div>
                <div>
                    <label for="ai_grace">Grace Days</label>
                    <input id="ai_grace" name="late_fee_grace_days" type="number" min="0" value="0">
                </div>
            </div>
            <div class="action-row" style="margin-top:1.5rem;">
                <button type="submit" class="btn">Add Installment</button>
                <button type="button" class="btn btn-soft" onclick="this.closest('.modal-backdrop').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════ MODAL: Add Assignment ═══════════════ -->
<div id="addAssignmentModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Add Assignment</h2>
            <button type="button" class="icon-btn" onclick="this.closest('.modal-backdrop').classList.remove('show')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_assignment">
            <input type="hidden" name="fee_structure_id" value="<?= $selStructureId ?>">
            <div class="field-grid">
                <div>
                    <label for="aa_type">Assign Type *</label>
                    <select id="aa_type" name="assign_type" required>
                        <option value="Class">Class</option>
                        <option value="Section">Section</option>
                        <option value="Individual">Individual</option>
                    </select>
                </div>
                <div>
                    <label for="aa_value">Value *</label>
                    <input id="aa_value" name="assign_value" type="text" required placeholder="e.g. Class 5, Section A, or Student ID">
                </div>
            </div>
            <div class="action-row" style="margin-top:1.5rem;">
                <button type="submit" class="btn">Add Assignment</button>
                <button type="button" class="btn btn-soft" onclick="this.closest('.modal-backdrop').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
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
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
