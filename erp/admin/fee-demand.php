<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';

$error = '';
$success = '';

// ─── Auto-create tables ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_demands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        demand_no VARCHAR(50) UNIQUE NOT NULL,
        academic_session VARCHAR(20) NOT NULL,
        demand_month VARCHAR(50) NOT NULL,
        class_name VARCHAR(100) NOT NULL,
        demand_date DATE NOT NULL,
        due_date DATE NOT NULL,
        total_students INT DEFAULT 0,
        total_amount DECIMAL(12,2) DEFAULT 0,
        include_late_fee TINYINT(1) DEFAULT 0,
        include_old_dues TINYINT(1) DEFAULT 0,
        status ENUM('Draft','Generated','Cancelled') DEFAULT 'Draft',
        generated_by INT,
        generated_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_demand_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        demand_id INT NOT NULL,
        student_id INT NOT NULL,
        student_name VARCHAR(200) NOT NULL,
        admission_no VARCHAR(50) DEFAULT '',
        class_name VARCHAR(100) NOT NULL,
        fee_head_id INT NOT NULL,
        fee_head_name VARCHAR(100) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        late_fee DECIMAL(10,2) DEFAULT 0,
        old_dues DECIMAL(10,2) DEFAULT 0,
        total_demand DECIMAL(10,2) NOT NULL,
        status ENUM('Pending','Paid','Partial','Cancelled') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_fee_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        student_name VARCHAR(200) NOT NULL,
        admission_no VARCHAR(50) DEFAULT '',
        class_name VARCHAR(100) NOT NULL,
        fee_structure_id INT NOT NULL,
        academic_session VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_structure_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_structure_id BIGINT NOT NULL,
        fee_head_id INT NOT NULL,
        fee_head_name VARCHAR(100) DEFAULT '',
        amount DECIMAL(10,2) NOT NULL,
        frequency VARCHAR(50) DEFAULT 'monthly',
        is_optional TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_fee_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        student_name VARCHAR(200) DEFAULT '',
        class_name VARCHAR(50) DEFAULT '',
        academic_session VARCHAR(20) NOT NULL,
        fee_structure_id INT NOT NULL,
        total_fee DECIMAL(12,2) DEFAULT 0,
        total_paid DECIMAL(12,2) DEFAULT 0,
        total_discount DECIMAL(12,2) DEFAULT 0,
        total_late_fee DECIMAL(12,2) DEFAULT 0,
        balance DECIMAL(12,2) DEFAULT 0,
        status ENUM('active','closed','transferred') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

// ─── Ensure fee_demand_items has fee_head_name column ───
try {
    $cols = array_map(static fn(array $r): string => (string) $r['Field'], $pdo->query("DESCRIBE fee_demand_items")->fetchAll());
    if (!in_array('fee_head_name', $cols, true)) {
        $pdo->exec("ALTER TABLE fee_demand_items ADD COLUMN fee_head_name VARCHAR(100) DEFAULT '' AFTER fee_head_id");
    }
} catch (Throwable $e) {}

// ─── Ensure fee_structure_items has fee_head_name and frequency columns ───
try {
    $cols = array_map(static fn(array $r): string => (string) $r['Field'], $pdo->query("DESCRIBE fee_structure_items")->fetchAll());
    if (!in_array('fee_head_name', $cols, true)) {
        $pdo->exec("ALTER TABLE fee_structure_items ADD COLUMN fee_head_name VARCHAR(100) DEFAULT '' AFTER fee_head_id");
    }
    if (!in_array('frequency', $cols, true)) {
        $pdo->exec("ALTER TABLE fee_structure_items ADD COLUMN frequency VARCHAR(50) DEFAULT 'monthly' AFTER amount");
    }
} catch (Throwable $e) {}

// ─── Ensure student_fee_accounts has student_name and class_name columns ───
try {
    $cols = array_map(static fn(array $r): string => (string) $r['Field'], $pdo->query("DESCRIBE student_fee_accounts")->fetchAll());
    if (!in_array('student_name', $cols, true)) {
        $pdo->exec("ALTER TABLE student_fee_accounts ADD COLUMN student_name VARCHAR(200) DEFAULT '' AFTER student_id");
    }
    if (!in_array('class_name', $cols, true)) {
        $pdo->exec("ALTER TABLE student_fee_accounts ADD COLUMN class_name VARCHAR(50) DEFAULT '' AFTER student_name");
    }
} catch (Throwable $e) {}

// ─── POST HANDLING ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    // ─── Generate Demand ───
    if ($action === 'generate_demand') {
        $academicSession = trim((string) ($_POST['academic_session'] ?? ''));
        $demandMonth = trim((string) ($_POST['demand_month'] ?? ''));
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $demandDate = trim((string) ($_POST['demand_date'] ?? date('Y-m-d')));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $includeLateFee = isset($_POST['include_late_fee']) ? 1 : 0;
        $includeOldDues = isset($_POST['include_old_dues']) ? 1 : 0;

        if ($academicSession === '' || $demandMonth === '' || $className === '' || $dueDate === '') {
            $error = 'Please fill all required fields.';
        } else {
            try {
                $pdo->beginTransaction();

                // Generate demand_no
                $seqPrefix = date('Ym');
                $seqStmt = $pdo->prepare("SELECT COUNT(*) + 1 FROM fee_demands WHERE demand_no LIKE :prefix");
                $seqStmt->execute(['prefix' => "DEMAND-{$seqPrefix}-%"]);
                $seqNum = (int) $seqStmt->fetchColumn();
                $demandNo = "DEMAND-{$seqPrefix}-" . str_pad((string) $seqNum, 4, '0', STR_PAD_LEFT);

                // Find all students in the selected class with active fee assignments
                $stuStmt = $pdo->prepare("SELECT sfa.* FROM student_fee_assignments sfa WHERE sfa.class_name = :class AND sfa.academic_session = :session");
                $stuStmt->execute(['class' => $className, 'session' => $academicSession]);
                $students = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($students)) {
                    $pdo->rollBack();
                    $error = 'No students found in class "' . $className . '" for the selected academic session.';
                } else {
                    $totalStudents = count($students);
                    $totalAmount = 0.0;
                    $demandItems = [];

                    foreach ($students as $student) {
                        // Get fee structure items for this student's fee structure
                        $fsiStmt = $pdo->prepare("SELECT fsi.*, COALESCE(fh.name, fsi.fee_head_name, 'Fee') AS head_name FROM fee_structure_items fsi LEFT JOIN fee_heads fh ON fh.id = fsi.fee_head_id WHERE fsi.fee_structure_id = :fsid");
                        $fsiStmt->execute(['fsid' => (int) $student['fee_structure_id']]);
                        $structureItems = $fsiStmt->fetchAll(PDO::FETCH_ASSOC);

                        // Calculate old dues
                        $oldDues = 0.0;
                        if ($includeOldDues) {
                            $dueStmt = $pdo->prepare("SELECT COALESCE(balance, 0) FROM student_fee_accounts WHERE student_id = :sid AND academic_session = :session AND balance > 0");
                            $dueStmt->execute(['sid' => (int) $student['student_id'], 'session' => $academicSession]);
                            $oldDues = (float) $dueStmt->fetchColumn();
                        }

                        foreach ($structureItems as $item) {
                            $amount = (float) $item['amount'];
                            $lateFee = 0.0;

                            if ($includeLateFee) {
                                // Apply a default 5% late fee if the fee head allows it
                                $lfStmt = $pdo->prepare("SELECT late_fee_applicable FROM fee_heads WHERE id = :fhid");
                                $lfStmt->execute(['fhid' => (int) $item['fee_head_id']]);
                                $lfRow = $lfStmt->fetch(PDO::FETCH_ASSOC);
                                if ($lfRow && ($lfRow['late_fee_applicable'] ?? 0)) {
                                    $lateFee = round($amount * 0.05, 2);
                                }
                            }

                            $totalDemand = $amount + $lateFee;
                            $totalAmount += $totalDemand;

                            $demandItems[] = [
                                'student_id' => (int) $student['student_id'],
                                'student_name' => $student['student_name'],
                                'admission_no' => $student['admission_no'] ?? '',
                                'class_name' => $className,
                                'fee_head_id' => (int) $item['fee_head_id'],
                                'fee_head_name' => $item['head_name'],
                                'amount' => $amount,
                                'late_fee' => $lateFee,
                                'old_dues' => $oldDues,
                                'total_demand' => $totalDemand,
                            ];
                        }
                    }

                    // Insert demand header
                    $insDemand = $pdo->prepare("INSERT INTO fee_demands (demand_no, academic_session, demand_month, class_name, demand_date, due_date, total_students, total_amount, include_late_fee, include_old_dues, status, generated_by, generated_at, created_at) VALUES (:demand_no, :academic_session, :demand_month, :class_name, :demand_date, :due_date, :total_students, :total_amount, :include_late_fee, :include_old_dues, 'Generated', :generated_by, NOW(), NOW())");
                    $insDemand->execute([
                        'demand_no' => $demandNo,
                        'academic_session' => $academicSession,
                        'demand_month' => $demandMonth,
                        'class_name' => $className,
                        'demand_date' => $demandDate,
                        'due_date' => $dueDate,
                        'total_students' => $totalStudents,
                        'total_amount' => $totalAmount,
                        'include_late_fee' => $includeLateFee,
                        'include_old_dues' => $includeOldDues,
                        'generated_by' => (int) $user['id'],
                    ]);
                    $demandId = (int) $pdo->lastInsertId();

                    // Insert demand items
                    $insItem = $pdo->prepare("INSERT INTO fee_demand_items (demand_id, student_id, student_name, admission_no, class_name, fee_head_id, fee_head_name, amount, late_fee, old_dues, total_demand, status, created_at) VALUES (:demand_id, :student_id, :student_name, :admission_no, :class_name, :fee_head_id, :fee_head_name, :amount, :late_fee, :old_dues, :total_demand, 'Pending', NOW())");
                    foreach ($demandItems as $di) {
                        $insItem->execute([
                            'demand_id' => $demandId,
                            'student_id' => $di['student_id'],
                            'student_name' => $di['student_name'],
                            'admission_no' => $di['admission_no'],
                            'class_name' => $di['class_name'],
                            'fee_head_id' => $di['fee_head_id'],
                            'fee_head_name' => $di['fee_head_name'],
                            'amount' => $di['amount'],
                            'late_fee' => $di['late_fee'],
                            'old_dues' => $di['old_dues'],
                            'total_demand' => $di['total_demand'],
                        ]);
                    }

                    $pdo->commit();
                    $success = "Demand {$demandNo} generated successfully for {$totalStudents} students. Total: Rs. " . number_format($totalAmount, 2);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to generate demand: ' . $e->getMessage();
            }
        }

        header('Location: fee-demand.php');
        exit;
    }

    // ─── Cancel Demand ───
    if ($action === 'cancel_demand') {
        $demandId = (int) ($_POST['demand_id'] ?? 0);
        if ($demandId > 0) {
            try {
                $pdo->beginTransaction();

                $updStmt = $pdo->prepare("UPDATE fee_demands SET status = 'Cancelled' WHERE id = :id AND status != 'Cancelled'");
                $updStmt->execute(['id' => $demandId]);

                if ($updStmt->rowCount() > 0) {
                    $pdo->prepare("UPDATE fee_demand_items SET status = 'Cancelled' WHERE demand_id = :did AND status != 'Cancelled'")->execute(['did' => $demandId]);
                    $success = 'Demand cancelled successfully.';
                } else {
                    $error = 'Demand not found or already cancelled.';
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to cancel demand: ' . $e->getMessage();
            }
        }

        header('Location: fee-demand.php');
        exit;
    }
}

// ─── FETCH DATA ───

// View demand detail
$viewDemandId = (int) ($_GET['view'] ?? 0);
$viewDemand = null;
$viewItems = [];
if ($viewDemandId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM fee_demands WHERE id = :id");
    $stmt->execute(['id' => $viewDemandId]);
    $viewDemand = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($viewDemand) {
        $itemStmt = $pdo->prepare("SELECT * FROM fee_demand_items WHERE demand_id = :did ORDER BY student_name ASC, fee_head_name ASC");
        $itemStmt->execute(['did' => $viewDemandId]);
        $viewItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Distinct class names for dropdown
$classOptions = [];
try {
    $classStmt = $pdo->query("SELECT DISTINCT class_name FROM student_fee_assignments WHERE class_name IS NOT NULL AND class_name != '' ORDER BY class_name ASC");
    $classOptions = array_map(static fn(array $r): string => (string) $r['class_name'], $classStmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {}

// All demands list
$demands = [];
try {
    $demands = $pdo->query("SELECT * FROM fee_demands ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fee Demand Generation – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .app-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .app-table th { text-align:left; padding:.6rem .5rem; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        .app-table td { padding:.6rem .5rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
        .app-table tr:hover td { background:#f8fafc; }
        .badge { display:inline-block; padding:.18rem .55rem; border-radius:4px; font-size:.75rem; font-weight:600; }
        .badge-draft { background:#e2e8f0; color:#475569; }
        .badge-generated { background:#d1fae5; color:#065f46; }
        .badge-cancelled { background:#fee2e2; color:#991b1b; }
        .badge-pending { background:#fef3c7; color:#92400e; }
        .badge-paid { background:#d1fae5; color:#065f46; }
        .badge-partial { background:#dbeafe; color:#1e40af; }
        .field-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .field-grid .full-col { grid-column:1/-1; }
        @media (max-width:768px) { .field-grid { grid-template-columns:1fr; } }
        .inline-form { display:inline; }
        .detail-group { margin-bottom:1.5rem; }
        .detail-group h3 { font-size:1rem; color:#334155; margin-bottom:.75rem; padding-bottom:.5rem; border-bottom:1px solid #e2e8f0; }
        .summary-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
        .summary-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; text-align:center; }
        .summary-card .label { font-size:.75rem; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.25rem; }
        .summary-card .value { font-size:1.25rem; font-weight:700; color:#1e293b; }
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
                    <h1>Fee Demand Generation</h1>
                    <p>Generate monthly fee demands (bills) for students by class and session.</p>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($viewDemand): ?>
            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- SECTION 3: DEMAND DETAIL                               -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <div style="margin-bottom:1rem;">
                <a href="fee-demand.php" class="btn btn-soft" style="text-decoration:none;font-size:.85rem;">&larr; Back to All Demands</a>
            </div>

            <section class="panel" style="padding:1.5rem;">
                <div class="section-title" style="margin-bottom:1.25rem;">
                    <div>
                        <h2>Demand: <?= e($viewDemand['demand_no']) ?></h2>
                        <p>Generated on <?= e($viewDemand['generated_at'] ?? $viewDemand['created_at']) ?></p>
                    </div>
                    <span class="badge badge-<?= strtolower(e($viewDemand['status'])) ?>"><?= e($viewDemand['status']) ?></span>
                </div>

                <div class="summary-cards">
                    <div class="summary-card">
                        <div class="label">Students</div>
                        <div class="value"><?= (int) $viewDemand['total_students'] ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="label">Total Amount</div>
                        <div class="value">Rs. <?= number_format((float) $viewDemand['total_amount'], 2) ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="label">Due Date</div>
                        <div class="value" style="font-size:1rem;"><?= e($viewDemand['due_date']) ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="label">Late Fee</div>
                        <div class="value" style="font-size:1rem;"><?= ($viewDemand['include_late_fee'] ?? 0) ? 'Yes' : 'No' ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="label">Old Dues</div>
                        <div class="value" style="font-size:1rem;"><?= ($viewDemand['include_old_dues'] ?? 0) ? 'Yes' : 'No' ?></div>
                    </div>
                </div>

                <div class="detail-group">
                    <h3>Student-wise Breakdown</h3>
                    <?php if (empty($viewItems)): ?>
                        <p style="text-align:center;padding:2rem;color:#94a3b8;">No demand items found.</p>
                    <?php else: ?>
                        <?php
                        // Group by student
                        $grouped = [];
                        foreach ($viewItems as $item) {
                            $sid = (int) $item['student_id'];
                            $grouped[$sid]['student_name'] = $item['student_name'];
                            $grouped[$sid]['admission_no'] = $item['admission_no'];
                            $grouped[$sid]['class_name'] = $item['class_name'];
                            $grouped[$sid]['items'][] = $item;
                            $grouped[$sid]['total'] = ($grouped[$sid]['total'] ?? 0) + (float) $item['total_demand'];
                        }
                        ?>
                        <div style="overflow-x:auto;">
                            <table class="app-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student Name</th>
                                        <th>Admission No</th>
                                        <th>Class</th>
                                        <th>Fee Head</th>
                                        <th style="text-align:right;">Amount</th>
                                        <th style="text-align:right;">Late Fee</th>
                                        <th style="text-align:right;">Old Dues</th>
                                        <th style="text-align:right;">Total</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; foreach ($viewItems as $item): ?>
                                    <tr>
                                        <td style="color:#94a3b8;"><?= $i++ ?></td>
                                        <td><strong><?= e($item['student_name']) ?></strong></td>
                                        <td style="font-family:monospace;font-size:.8rem;"><?= e($item['admission_no']) ?></td>
                                        <td><?= e($item['class_name']) ?></td>
                                        <td><?= e($item['fee_head_name']) ?></td>
                                        <td style="text-align:right;">Rs. <?= number_format((float) $item['amount'], 2) ?></td>
                                        <td style="text-align:right;"><?= (float) $item['late_fee'] > 0 ? 'Rs. ' . number_format((float) $item['late_fee'], 2) : '—' ?></td>
                                        <td style="text-align:right;"><?= (float) $item['old_dues'] > 0 ? 'Rs. ' . number_format((float) $item['old_dues'], 2) : '—' ?></td>
                                        <td style="text-align:right;font-weight:600;">Rs. <?= number_format((float) $item['total_demand'], 2) ?></td>
                                        <td><span class="badge badge-<?= strtolower(e($item['status'])) ?>"><?= e($item['status']) ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="font-weight:700;background:#f8fafc;">
                                        <td colspan="5" style="text-align:right;">Grand Total:</td>
                                        <td style="text-align:right;">Rs. <?= number_format(array_reduce($viewItems, static fn($carry, $item) => $carry + (float) $item['amount'], 0), 2) ?></td>
                                        <td style="text-align:right;">Rs. <?= number_format(array_reduce($viewItems, static fn($carry, $item) => $carry + (float) $item['late_fee'], 0), 2) ?></td>
                                        <td style="text-align:right;">Rs. <?= number_format(array_reduce($viewItems, static fn($carry, $item) => $carry + (float) $item['old_dues'], 0), 2) ?></td>
                                        <td style="text-align:right;">Rs. <?= number_format(array_reduce($viewItems, static fn($carry, $item) => $carry + (float) $item['total_demand'], 0), 2) ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        <?php else: ?>
            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- SECTION 1: GENERATE NEW DEMAND                        -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <section class="panel" style="padding:1.5rem;margin-bottom:2rem;">
                <div class="section-title" style="margin-bottom:1.25rem;">
                    <div>
                        <h2>Generate New Demand</h2>
                        <p>Create a fee demand for students in a specific class and month.</p>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="generate_demand">

                    <div class="field-grid">
                        <div>
                            <label for="academic_session" style="font-weight:600;font-size:.85rem;margin-bottom:.3rem;display:block;">Academic Session *</label>
                            <input id="academic_session" name="academic_session" type="text" required placeholder="e.g. 2025-26" value="<?= e(date('Y') . '-' . substr((string) ((int) date('Y') + 1), 2)) ?>" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        </div>
                        <div>
                            <label for="demand_month" style="font-weight:600;font-size:.85rem;margin-bottom:.3rem;display:block;">Demand Month *</label>
                            <input id="demand_month" name="demand_month" type="text" required placeholder="e.g. August 2026" value="<?= e(date('F Y')) ?>" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        </div>
                        <div>
                            <label for="class_name" style="font-weight:600;font-size:.85rem;margin-bottom:.3rem;display:block;">Class *</label>
                            <select id="class_name" name="class_name" required style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                                <option value="">— Select Class —</option>
                                <?php foreach ($classOptions as $co): ?>
                                    <option value="<?= e($co) ?>"><?= e($co) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="demand_date" style="font-weight:600;font-size:.85rem;margin-bottom:.3rem;display:block;">Demand Date *</label>
                            <input id="demand_date" name="demand_date" type="date" required value="<?= e(date('Y-m-d')) ?>" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        </div>
                        <div>
                            <label for="due_date" style="font-weight:600;font-size:.85rem;margin-bottom:.3rem;display:block;">Due Date *</label>
                            <input id="due_date" name="due_date" type="date" required value="<?= e(date('Y-m-d', strtotime('+15 days'))) ?>" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        </div>
                        <div class="full-col" style="display:flex;gap:2rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;">
                            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;font-size:.875rem;">
                                <input type="checkbox" name="include_late_fee" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;">
                                Include Late Fee
                            </label>
                            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:400;font-size:.875rem;">
                                <input type="checkbox" name="include_old_dues" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;">
                                Include Old Dues
                            </label>
                        </div>
                    </div>

                    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid #e2e8f0;display:flex;gap:.75rem;align-items:center;">
                        <button type="submit" class="btn" style="padding:.6rem 2rem;font-size:.95rem;font-weight:600;">Generate Demand</button>
                        <span style="color:#64748b;font-size:.82rem;">This will create demands for all students in the selected class.</span>
                    </div>
                </form>
            </section>

            <!-- ═══════════════════════════════════════════════════════ -->
            <!-- SECTION 2: EXISTING DEMANDS                           -->
            <!-- ═══════════════════════════════════════════════════════ -->
            <section class="panel" style="padding:1.25rem;">
                <div class="section-title" style="margin-bottom:1rem;">
                    <div>
                        <h2>Existing Demands</h2>
                        <p>View and manage previously generated fee demands.</p>
                    </div>
                    <span style="color:#64748b;font-size:.85rem;"><?= count($demands) ?> demand<?= count($demands) !== 1 ? 's' : '' ?></span>
                </div>

                <?php if (empty($demands)): ?>
                    <p style="text-align:center;padding:2.5rem;color:#94a3b8;">No demands generated yet. Use the form above to create your first demand.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Demand No</th>
                                    <th>Month</th>
                                    <th>Class</th>
                                    <th>Session</th>
                                    <th style="text-align:right;">Students</th>
                                    <th style="text-align:right;">Total Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($demands as $d): ?>
                                <tr>
                                    <td style="font-family:monospace;font-size:.8rem;"><?= e($d['demand_no']) ?></td>
                                    <td><strong><?= e($d['demand_month']) ?></strong></td>
                                    <td><?= e($d['class_name']) ?></td>
                                    <td style="font-size:.82rem;"><?= e($d['academic_session']) ?></td>
                                    <td style="text-align:right;"><?= (int) $d['total_students'] ?></td>
                                    <td style="text-align:right;font-weight:600;">Rs. <?= number_format((float) $d['total_amount'], 2) ?></td>
                                    <td><span class="badge badge-<?= strtolower(e($d['status'])) ?>"><?= e($d['status']) ?></span></td>
                                    <td style="white-space:nowrap;font-size:.82rem;"><?= e($d['demand_date']) ?></td>
                                    <td>
                                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                            <a class="btn btn-sm btn-soft" href="?view=<?= (int) $d['id'] ?>">View</a>
                                            <?php if ($d['status'] !== 'Cancelled'): ?>
                                                <form method="post" class="inline-form" onsubmit="return confirm('Cancel demand &quot;<?= e($d['demand_no']) ?>&quot;? All items will be marked as Cancelled.')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="cancel_demand">
                                                    <input type="hidden" name="demand_id" value="<?= (int) $d['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-soft" style="color:#ef4444;">Cancel</button>
                                                </form>
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
        <?php endif; ?>

    </main>
</div>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
