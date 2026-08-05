<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();

$error = '';
$success = '';

// ─── Ensure tables exist ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_salary_structures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        employee_name VARCHAR(255) NOT NULL DEFAULT '',
        basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        hra DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
        pf_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
        esi_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
        professional_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        tds DECIMAL(12,2) NOT NULL DEFAULT 0,
        loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
        net_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        bank_account VARCHAR(255) DEFAULT '',
        payment_mode VARCHAR(50) DEFAULT 'Bank Transfer',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_id (employee_id)
    )");
} catch (\Throwable $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        month_label VARCHAR(50) NOT NULL,
        total_employees INT NOT NULL DEFAULT 0,
        total_gross DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_net DECIMAL(14,2) NOT NULL DEFAULT 0,
        status ENUM('Draft','Approved','Paid','Cancelled') DEFAULT 'Draft',
        approved_by INT,
        approved_at DATETIME,
        generated_by INT,
        generated_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payroll_run_id INT NOT NULL,
        employee_id INT NOT NULL,
        employee_name VARCHAR(255) NOT NULL DEFAULT '',
        department VARCHAR(100) DEFAULT '',
        basic DECIMAL(12,2) NOT NULL DEFAULT 0,
        hra DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
        gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        pf DECIMAL(12,2) NOT NULL DEFAULT 0,
        esi DECIMAL(12,2) NOT NULL DEFAULT 0,
        professional_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        tds DECIMAL(12,2) NOT NULL DEFAULT 0,
        loan DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
        net_payout DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_status ENUM('Pending','Paid') DEFAULT 'Pending',
        payment_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {}

// ─── Helper functions ───

function employee_options(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, employee_code, name, department, designation FROM employees WHERE status = 'Active' ORDER BY employee_code, name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function compute_net_salary(array $d): float
{
    $gross = (float) ($d['basic_salary'] ?? 0) + (float) ($d['hra'] ?? 0) + (float) ($d['other_allowance'] ?? 0);
    $deductions = (float) ($d['pf_deduction'] ?? 0) + (float) ($d['esi_deduction'] ?? 0)
        + (float) ($d['professional_tax'] ?? 0) + (float) ($d['tds'] ?? 0) + (float) ($d['loan_deduction'] ?? 0);
    return $gross - $deductions;
}

function generate_monthly_payroll(PDO $pdo, string $monthLabel, int $userId): array
{
    $empRows = $pdo->query(
        "SELECT ess.*, e.department
         FROM employee_salary_structures ess
         JOIN employees e ON e.id = ess.employee_id
         WHERE ess.is_active = 1 AND e.status = 'Active'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $totalGross = 0;
    $totalDeductions = 0;
    $totalNet = 0;

    $insItem = $pdo->prepare("INSERT INTO payroll_items (payroll_run_id, employee_id, employee_name, department, basic, hra, other_allowance, gross_amount, pf, esi, professional_tax, tds, loan, other_deductions, total_deductions, net_payout, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

    foreach ($empRows as $emp) {
        $gross = (float) $emp['basic_salary'] + (float) $emp['hra'] + (float) $emp['other_allowance'];
        $deductions = (float) $emp['pf_deduction'] + (float) $emp['esi_deduction']
            + (float) $emp['professional_tax'] + (float) $emp['tds'] + (float) $emp['loan_deduction'];
        $net = $gross - $deductions;

        $totalGross += $gross;
        $totalDeductions += $deductions;
        $totalNet += $net;

        $insItem->execute([
            0,
            (int) $emp['employee_id'],
            $emp['employee_name'],
            $emp['department'],
            $emp['basic_salary'],
            $emp['hra'],
            $emp['other_allowance'],
            $gross,
            $emp['pf_deduction'],
            $emp['esi_deduction'],
            $emp['professional_tax'],
            $emp['tds'],
            $emp['loan_deduction'],
            0,
            $deductions,
            $net,
        ]);
    }

    $insRun = $pdo->prepare("INSERT INTO payroll_runs (month_label, total_employees, total_gross, total_deductions, total_net, status, generated_by, generated_at) VALUES (?, ?, ?, ?, ?, 'Draft', ?, NOW())");
    $insRun->execute([
        $monthLabel,
        count($empRows),
        $totalGross,
        $totalDeductions,
        $totalNet,
        $userId,
    ]);
    $runId = (int) $pdo->lastInsertId();

    $pdo->prepare("UPDATE payroll_items SET payroll_run_id = ? WHERE payroll_run_id = 0 AND id > 0")->execute([$runId]);
    // Fix: re-update items with correct run id using a temp marker
    $pdo->prepare("UPDATE payroll_items SET payroll_run_id = ? WHERE payroll_run_id = ? AND employee_id IN (SELECT employee_id FROM payroll_items WHERE payroll_run_id = ?)") ->execute([$runId, 0, $runId]);

    return ['run_id' => $runId, 'count' => count($empRows)];
}

// ─── Active tab ───
$activeTab = max(1, min(3, (int) ($_GET['tab'] ?? 1)));
$viewRunId = (int) ($_GET['view_run'] ?? 0);

// ─── POST handlers ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'save_salary') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $employeeName = trim((string) ($_POST['employee_name'] ?? ''));
            $basicSalary = (float) ($_POST['basic_salary'] ?? 0);
            $hra = (float) ($_POST['hra'] ?? 0);
            $otherAllowance = (float) ($_POST['other_allowance'] ?? 0);
            $pf = (float) ($_POST['pf_deduction'] ?? 0);
            $esi = (float) ($_POST['esi_deduction'] ?? 0);
            $profTax = (float) ($_POST['professional_tax'] ?? 0);
            $tds = (float) ($_POST['tds'] ?? 0);
            $loan = (float) ($_POST['loan_deduction'] ?? 0);
            $bankAccount = trim((string) ($_POST['bank_account'] ?? ''));
            $paymentMode = trim((string) ($_POST['payment_mode'] ?? 'Bank Transfer'));
            $editId = (int) ($_POST['edit_id'] ?? 0);

            if ($employeeId < 1) {
                throw new \RuntimeException('Please select an employee.');
            }

            // Resolve employee name if not provided
            if ($employeeName === '') {
                $es = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
                $es->execute([$employeeId]);
                $employeeName = (string) ($es->fetchColumn() ?: '');
            }

            $data = [
                'basic_salary' => $basicSalary,
                'hra' => $hra,
                'other_allowance' => $otherAllowance,
                'pf_deduction' => $pf,
                'esi_deduction' => $esi,
                'professional_tax' => $profTax,
                'tds' => $tds,
                'loan_deduction' => $loan,
            ];
            $netSalary = compute_net_salary($data);

            if ($editId > 0) {
                $pdo->prepare("UPDATE employee_salary_structures SET employee_id=?, employee_name=?, basic_salary=?, hra=?, other_allowance=?, pf_deduction=?, esi_deduction=?, professional_tax=?, tds=?, loan_deduction=?, net_salary=?, bank_account=?, payment_mode=?, updated_at=NOW() WHERE id=?")
                    ->execute([$employeeId, $employeeName, $basicSalary, $hra, $otherAllowance, $pf, $esi, $profTax, $tds, $loan, $netSalary, $bankAccount, $paymentMode, $editId]);
                $success = 'Salary structure updated successfully.';
            } else {
                // Check if salary already exists for this employee
                $chk = $pdo->prepare("SELECT id FROM employee_salary_structures WHERE employee_id = ?");
                $chk->execute([$employeeId]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE employee_salary_structures SET employee_name=?, basic_salary=?, hra=?, other_allowance=?, pf_deduction=?, esi_deduction=?, professional_tax=?, tds=?, loan_deduction=?, net_salary=?, bank_account=?, payment_mode=?, updated_at=NOW() WHERE employee_id=?")
                        ->execute([$employeeName, $basicSalary, $hra, $otherAllowance, $pf, $esi, $profTax, $tds, $loan, $netSalary, $bankAccount, $paymentMode, $employeeId]);
                    $success = 'Salary structure updated (existing record replaced).';
                } else {
                    $pdo->prepare("INSERT INTO employee_salary_structures (employee_id, employee_name, basic_salary, hra, other_allowance, pf_deduction, esi_deduction, professional_tax, tds, loan_deduction, net_salary, bank_account, payment_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$employeeId, $employeeName, $basicSalary, $hra, $otherAllowance, $pf, $esi, $profTax, $tds, $loan, $netSalary, $bankAccount, $paymentMode]);
                    $success = 'Salary structure saved successfully.';
                }
            }
        }

        if ($action === 'generate_payroll') {
            $monthLabel = trim((string) ($_POST['month_label'] ?? ''));
            if ($monthLabel === '') {
                throw new \RuntimeException('Please select a month.');
            }

            // Check if payroll already exists for this month
            $chk = $pdo->prepare("SELECT id, status FROM payroll_runs WHERE month_label = ?");
            $chk->execute([$monthLabel]);
            if ($chk->fetch()) {
                throw new \RuntimeException('Payroll for ' . $monthLabel . ' has already been generated.');
            }

            $result = generate_monthly_payroll($pdo, $monthLabel, (int) ($user['id'] ?? 0));
            $success = "Payroll generated for {$monthLabel} with {$result['count']} employees. Total Net: ₹" . number_format((float) $pdo->prepare("SELECT total_net FROM payroll_runs WHERE id = ?")->execute([$result['run_id']]) ? $pdo->prepare("SELECT total_net FROM payroll_runs WHERE id = ?") : $pdo, 2);
            // Simplify success message
            $success = "Payroll generated for {$monthLabel} with {$result['count']} employees.";
        }

        if ($action === 'approve_payroll') {
            $runId = (int) ($_POST['run_id'] ?? 0);
            if ($runId < 1) throw new \RuntimeException('Invalid payroll run.');

            $chk = $pdo->prepare("SELECT status FROM payroll_runs WHERE id = ?");
            $chk->execute([$runId]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== 'Draft') {
                throw new \RuntimeException('Only Draft payrolls can be approved.');
            }
            $pdo->prepare("UPDATE payroll_runs SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([(int) ($user['id'] ?? 0), $runId]);
            $success = 'Payroll approved successfully.';
            $viewRunId = $runId;
        }

        if ($action === 'mark_paid') {
            $runId = (int) ($_POST['run_id'] ?? 0);
            if ($runId < 1) throw new \RuntimeException('Invalid payroll run.');

            $pdo->beginTransaction();
            try {
                $chk = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ?");
                $chk->execute([$runId]);
                $run = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$run || !in_array($run['status'], ['Approved'], true)) {
                    throw new \RuntimeException('Only Approved payrolls can be marked as paid.');
                }

                $pdo->prepare("UPDATE payroll_runs SET status = 'Paid' WHERE id = ?")->execute([$runId]);
                $pdo->prepare("UPDATE payroll_items SET payment_status = 'Paid', payment_date = CURDATE() WHERE payroll_run_id = ?")->execute([$runId]);

                // Post salary expense to bank_book
                $bankAccountId = 1;
                $desc = "Salary disbursement – {$run['month_label']} (" . $run['total_employees'] . " employees)";
                $amount = (float) $run['total_net'];
                $uid = (int) ($user['id'] ?? 0);

                $lastBal = 0.0;
                $lb = $pdo->prepare("SELECT balance FROM bank_book WHERE bank_account_id = ? ORDER BY id DESC LIMIT 1");
                $lb->execute([$bankAccountId]);
                $lbr = $lb->fetch(PDO::FETCH_ASSOC);
                if ($lbr) $lastBal = (float) $lbr['balance'];
                $newBal = $lastBal - $amount;

                $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, description, amount, direction, balance, created_by) VALUES (?, CURDATE(), 'Salary', 'payroll', ?, ?, ?, 'Dr', ?, ?)")
                    ->execute([$bankAccountId, $runId, $desc, $amount, $newBal, $uid]);

                $pdo->commit();
                $success = 'Payroll marked as paid and posted to bank book.';
                $viewRunId = $runId;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'cancel_payroll') {
            $runId = (int) ($_POST['run_id'] ?? 0);
            if ($runId < 1) throw new \RuntimeException('Invalid payroll run.');

            $chk = $pdo->prepare("SELECT status FROM payroll_runs WHERE id = ?");
            $chk->execute([$runId]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] === 'Paid') {
                throw new \RuntimeException('Paid payrolls cannot be cancelled.');
            }
            $pdo->prepare("UPDATE payroll_runs SET status = 'Cancelled' WHERE id = ?")->execute([$runId]);
            $success = 'Payroll cancelled.';
            $viewRunId = $runId;
        }

        if ($action === 'delete_salary' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $pdo->prepare("UPDATE employee_salary_structures SET is_active = 0 WHERE id = ?")->execute([$id]);
            $success = 'Salary structure deactivated.';
        }

    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    // Redirect after POST
    $redirect = 'salary-setup.php?tab=' . $activeTab;
    if ($viewRunId > 0) {
        $redirect .= '&view_run=' . $viewRunId;
    }
    if ($success !== '') {
        $redirect .= '&success=' . urlencode($success);
    } elseif ($error !== '') {
        $redirect .= '&error=' . urlencode($error);
    }
    header('Location: ' . $redirect);
    exit;
}

// ─── Handle flash messages from redirect ───
if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = (string) $_GET['error'];
}

// ─── Fetch data ───
$employees = employee_options($pdo);

// Salary structures
$salaryStructures = [];
try {
    $salaryStructures = $pdo->query(
        "SELECT ess.*, e.employee_code, e.department, e.designation
         FROM employee_salary_structures ess
         LEFT JOIN employees e ON e.id = ess.employee_id
         WHERE ess.is_active = 1
         ORDER BY e.employee_code, ess.employee_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Payroll runs
$payrollRuns = [];
try {
    $payrollRuns = $pdo->query("SELECT * FROM payroll_runs ORDER BY FIELD(status, 'Draft','Approved','Paid','Cancelled'), id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// View run detail
$viewRun = null;
$viewItems = [];
if ($viewRunId > 0) {
    $vs = $pdo->prepare("SELECT * FROM payroll_runs WHERE id = ?");
    $vs->execute([$viewRunId]);
    $viewRun = $vs->fetch(PDO::FETCH_ASSOC);
    if ($viewRun) {
        $vi = $pdo->prepare("SELECT * FROM payroll_items WHERE payroll_run_id = ? ORDER BY employee_name");
        $vi->execute([$viewRunId]);
        $viewItems = $vi->fetchAll(PDO::FETCH_ASSOC);
        $activeTab = 3;
    }
}

// Stats
$totalStructures = count($salaryStructures);
$activePayrolls = 0;
$pendingPayout = 0.0;
$paidPayout = 0.0;
foreach ($payrollRuns as $pr) {
    if ($pr['status'] === 'Draft' || $pr['status'] === 'Approved') {
        $activePayrolls++;
        if ($pr['status'] === 'Approved') $pendingPayout += (float) $pr['total_net'];
    }
    if ($pr['status'] === 'Paid') $paidPayout += (float) $pr['total_net'];
}

// Current and next month labels for dropdown
$now = new DateTime();
$currentMonth = $now->format('F Y');
$nextMonth = (clone $now)->modify('+1 month')->format('F Y');
$lastMonth = (clone $now)->modify('-1 month')->format('F Y');

// Check which months already have payroll
$existingMonths = [];
foreach ($payrollRuns as $pr) {
    $existingMonths[] = $pr['month_label'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Salary Setup & Payroll – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
    <style>
        .stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.25rem}
        .stat-card .stat-label{font-size:.78rem;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.25rem}
        .stat-card .stat-value{font-size:1.35rem;font-weight:700;color:#1e293b}
        .stat-card .stat-value.accent-blue{color:#2563eb}
        .stat-card .stat-value.accent-green{color:#059669}
        .stat-card .stat-value.accent-amber{color:#d97706}
        .badge-draft{background:#f1f5f9;color:#475569;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600}
        .badge-approved{background:#d1fae5;color:#065f46;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600}
        .badge-paid{background:#bbf7d0;color:#166534;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600}
        .badge-cancelled{background:#fee2e2;color:#991b1b;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600}
        .badge-pending{background:#fef3c7;color:#92400e;padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600}
        .badge-paid-sm{background:#bbf7d0;color:#166534;padding:.15rem .45rem;border-radius:4px;font-size:.72rem;font-weight:600}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto}
        .modal-overlay.open{display:flex}
        .modal-box{background:#fff;border-radius:12px;width:100%;max-width:720px;max-height:90vh;overflow-y:auto;padding:1.5rem;margin-bottom:3vh}
        .modal-box .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;border-bottom:1px solid #e2e8f0;padding-bottom:.75rem}
        .modal-box .modal-header h2{margin:0;font-size:1.15rem}
        .modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;color:#64748b;padding:.25rem .5rem;border-radius:6px}
        .modal-close:hover{background:#f1f5f9}
        .field-row{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem}
        .field-row>div{flex:1;min-width:180px}
        .field-row label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:.25rem}
        .field-row input,.field-row select,.field-row textarea{width:100%;padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.875rem}
        .field-row input:focus,.field-row select:focus,.field-row textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.15)}
        .payslip-wrap{max-width:900px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:2rem}
        .payslip-header{text-align:center;margin-bottom:1.5rem;border-bottom:2px solid #1e293b;padding-bottom:1rem}
        .payslip-header h2{font-size:1.3rem;margin:0}
        .payslip-header p{color:#64748b;font-size:.85rem;margin:.25rem 0 0}
        .payslip-meta{display:grid;grid-template-columns:1fr 1fr;gap:.5rem 2rem;margin-bottom:1.25rem;font-size:.85rem}
        .payslip-meta .pm-label{color:#64748b}
        .payslip-meta .pm-value{font-weight:600}
        .payslip-table{width:100%;border-collapse:collapse;font-size:.85rem}
        .payslip-table th,.payslip-table td{padding:.5rem .75rem;border:1px solid #e2e8f0;text-align:right}
        .payslip-table th{background:#f8fafc;color:#475569;text-align:left;font-weight:600}
        .payslip-table td:first-child{text-align:left}
        .payslip-table .row-total td{background:#f1f5f9;font-weight:700}
        @media print{.no-print{display:none!important}.payslip-wrap{border:none;box-shadow:none;padding:0}}
        .confirm-dialog{max-width:420px}
        .confirm-dialog p{margin:0 0 1rem;font-size:.9rem;line-height:1.5}
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Accounts</span>
                    <h1>Salary Setup & Payroll</h1>
                    <p>Manage employee salary structures and monthly payroll processing.</p>
                </div>
                <div class="toolbar-right">
                    <?php if ($activeTab === 1): ?>
                        <button type="button" class="btn" style="background:#059669;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;cursor:pointer;" onclick="openModal('salary')">+ Set Salary</button>
                    <?php endif; ?>
                    <?php if ($activeTab === 2): ?>
                        <button type="button" class="btn" style="background:#2563eb;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;cursor:pointer;" onclick="openModal('generate')">Generate Payroll</button>
                    <?php endif; ?>
                    <?php if ($activeTab === 3 && $viewRun): ?>
                        <button type="button" class="btn" style="background:#64748b;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;cursor:pointer;" onclick="window.print()">Print Payslip</button>
                        <a href="salary-setup.php?tab=2" class="btn" style="background:#e2e8f0;color:#334155;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;text-decoration:none;">← Back to Payroll</a>
                    <?php endif; ?>
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
                <div class="stat-label">Salary Structures</div>
                <div class="stat-value accent-blue"><?= $totalStructures ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Payrolls</div>
                <div class="stat-value accent-amber"><?= $activePayrolls ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Payout</div>
                <div class="stat-value accent-amber">₹<?= number_format($pendingPayout, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Paid</div>
                <div class="stat-value accent-green">₹<?= number_format($paidPayout, 2) ?></div>
            </div>
        </div>

        <!-- Tab Bar -->
        <div class="tab-bar">
            <a href="salary-setup.php?tab=1" class="<?= $activeTab === 1 ? 'active' : '' ?>">Salary Setup</a>
            <a href="salary-setup.php?tab=2" class="<?= $activeTab === 2 ? 'active' : '' ?>">Monthly Payroll</a>
            <?php if ($activeTab === 3 && $viewRun): ?>
                <a href="salary-setup.php?tab=3&view_run=<?= $viewRunId ?>" class="active">Payroll Detail – <?= e($viewRun['month_label']) ?></a>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════ -->
        <!-- TAB 1: Salary Setup -->
        <!-- ═══════════════════════════════════════════════ -->
        <?php if ($activeTab === 1): ?>
            <section class="panel" style="padding:1.25rem;">
                <?php if (empty($salaryStructures)): ?>
                    <p style="text-align:center;padding:2rem;color:#94a3b8;">No salary structures configured. Click "Set Salary" to add one.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Emp Code</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th style="text-align:right">Basic</th>
                                    <th style="text-align:right">HRA</th>
                                    <th style="text-align:right">Allowances</th>
                                    <th style="text-align:right">Deductions</th>
                                    <th style="text-align:right">Net Salary</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salaryStructures as $ss): ?>
                                    <?php
                                    $gross = (float) $ss['basic_salary'] + (float) $ss['hra'] + (float) $ss['other_allowance'];
                                    $deductions = (float) $ss['pf_deduction'] + (float) $ss['esi_deduction'] + (float) $ss['professional_tax'] + (float) $ss['tds'] + (float) $ss['loan_deduction'];
                                    ?>
                                    <tr>
                                        <td style="font-family:monospace;font-size:.82rem;"><?= e($ss['employee_code'] ?? '—') ?></td>
                                        <td><strong><?= e($ss['employee_name']) ?></strong></td>
                                        <td><?= e($ss['department'] ?? '—') ?></td>
                                        <td style="text-align:right">₹<?= number_format((float) $ss['basic_salary'], 2) ?></td>
                                        <td style="text-align:right">₹<?= number_format((float) $ss['hra'], 2) ?></td>
                                        <td style="text-align:right">₹<?= number_format((float) $ss['other_allowance'], 2) ?></td>
                                        <td style="text-align:right;color:#dc2626;">₹<?= number_format($deductions, 2) ?></td>
                                        <td style="text-align:right;"><strong style="color:#059669;">₹<?= number_format((float) $ss['net_salary'], 2) ?></strong></td>
                                        <td><span class="badge-<?= $ss['is_active'] ? 'approved' : 'cancelled' ?>"><?= $ss['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td>
                                            <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
                                                <button type="button" style="background:#2563eb;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;" onclick='editSalary(<?= json_encode($ss) ?>)'>Edit</button>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Deactivate this salary structure?')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete_salary">
                                                    <input type="hidden" name="id" value="<?= (int) $ss['id'] ?>">
                                                    <button type="submit" style="background:#94a3b8;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Remove</button>
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
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════ -->
        <!-- TAB 2: Monthly Payroll -->
        <!-- ═══════════════════════════════════════════════ -->
        <?php if ($activeTab === 2): ?>
            <section class="panel" style="padding:1.25rem;">
                <?php if (empty($payrollRuns)): ?>
                    <p style="text-align:center;padding:2rem;color:#94a3b8;">No payroll runs found. Click "Generate Payroll" to create one.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th style="text-align:right">Employees</th>
                                    <th style="text-align:right">Gross</th>
                                    <th style="text-align:right">Deductions</th>
                                    <th style="text-align:right">Net Pay</th>
                                    <th>Status</th>
                                    <th>Generated By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payrollRuns as $pr): ?>
                                    <tr>
                                        <td><strong><?= e($pr['month_label']) ?></strong></td>
                                        <td style="text-align:right"><?= (int) $pr['total_employees'] ?></td>
                                        <td style="text-align:right">₹<?= number_format((float) $pr['total_gross'], 2) ?></td>
                                        <td style="text-align:right;color:#dc2626;">₹<?= number_format((float) $pr['total_deductions'], 2) ?></td>
                                        <td style="text-align:right;"><strong style="color:#059669;">₹<?= number_format((float) $pr['total_net'], 2) ?></strong></td>
                                        <td>
                                            <?php
                                            $statusCls = strtolower($pr['status']);
                                            ?>
                                            <span class="badge-<?= $statusCls ?>"><?= e($pr['status']) ?></span>
                                        </td>
                                        <td style="font-size:.82rem;color:#64748b;"><?= e($pr['generated_at'] ?? '—') ?></td>
                                        <td>
                                            <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
                                                <a href="salary-setup.php?tab=3&view_run=<?= (int) $pr['id'] ?>" style="background:#2563eb;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;text-decoration:none;display:inline-block;">View</a>
                                                <?php if ($pr['status'] === 'Draft'): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Approve this payroll?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="approve_payroll">
                                                        <input type="hidden" name="run_id" value="<?= (int) $pr['id'] ?>">
                                                        <button type="submit" style="background:#059669;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Approve</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($pr['status'] === 'Approved'): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Mark this payroll as paid? This will post to the bank book.')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="mark_paid">
                                                        <input type="hidden" name="run_id" value="<?= (int) $pr['id'] ?>">
                                                        <button type="submit" style="background:#16a34a;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Mark Paid</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (in_array($pr['status'], ['Draft', 'Approved'], true)): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this payroll?')">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="cancel_payroll">
                                                        <input type="hidden" name="run_id" value="<?= (int) $pr['id'] ?>">
                                                        <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:.25rem .5rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Cancel</button>
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

        <!-- ═══════════════════════════════════════════════ -->
        <!-- TAB 3: Payroll Detail -->
        <!-- ═══════════════════════════════════════════════ -->
        <?php if ($activeTab === 3 && $viewRun): ?>
            <!-- Summary Cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Total Employees</div>
                    <div class="stat-value"><?= (int) $viewRun['total_employees'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Gross Amount</div>
                    <div class="stat-value">₹<?= number_format((float) $viewRun['total_gross'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Deductions</div>
                    <div class="stat-value" style="color:#dc2626;">₹<?= number_format((float) $viewRun['total_deductions'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Net Pay</div>
                    <div class="stat-value accent-green">₹<?= number_format((float) $viewRun['total_net'], 2) ?></div>
                </div>
            </div>

            <!-- Action bar -->
            <div style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <span style="font-size:.9rem;font-weight:600;"><?= e($viewRun['month_label']) ?></span>
                <span class="badge-<?= strtolower($viewRun['status']) ?>"><?= e($viewRun['status']) ?></span>
                <?php if ($viewRun['status'] === 'Draft'): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Approve this payroll?')">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="approve_payroll">
                        <input type="hidden" name="run_id" value="<?= $viewRunId ?>">
                        <button type="submit" class="btn" style="background:#059669;color:#fff;border:none;padding:.4rem 1rem;font-size:.82rem;border-radius:8px;cursor:pointer;">Approve Payroll</button>
                    </form>
                <?php endif; ?>
                <?php if ($viewRun['status'] === 'Approved'): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Mark as paid? This posts to bank book.')">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="run_id" value="<?= $viewRunId ?>">
                        <button type="submit" class="btn" style="background:#16a34a;color:#fff;border:none;padding:.4rem 1rem;font-size:.82rem;border-radius:8px;cursor:pointer;">Mark as Paid</button>
                    </form>
                <?php endif; ?>
                <?php if (in_array($viewRun['status'], ['Draft', 'Approved'], true)): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this payroll?')">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="cancel_payroll">
                        <input type="hidden" name="run_id" value="<?= $viewRunId ?>">
                        <button type="submit" class="btn" style="background:#dc2626;color:#fff;border:none;padding:.4rem 1rem;font-size:.82rem;border-radius:8px;cursor:pointer;">Cancel</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Detail Table -->
            <section class="panel" style="padding:1.25rem;">
                <?php if (empty($viewItems)): ?>
                    <p style="text-align:center;padding:2rem;color:#94a3b8;">No payroll items found.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th style="text-align:right">Basic</th>
                                    <th style="text-align:right">HRA</th>
                                    <th style="text-align:right">Allowances</th>
                                    <th style="text-align:right">Gross</th>
                                    <th style="text-align:right">PF</th>
                                    <th style="text-align:right">ESI</th>
                                    <th style="text-align:right">PT</th>
                                    <th style="text-align:right">TDS</th>
                                    <th style="text-align:right">Loan</th>
                                    <th style="text-align:right">Total Ded.</th>
                                    <th style="text-align:right">Net</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewItems as $item): ?>
                                    <tr>
                                        <td><strong><?= e($item['employee_name']) ?></strong></td>
                                        <td><?= e($item['department'] ?? '—') ?></td>
                                        <td style="text-align:right">₹<?= number_format((float) $item['basic'], 2) ?></td>
                                        <td style="text-align:right">₹<?= number_format((float) $item['hra'], 2) ?></td>
                                        <td style="text-align:right">₹<?= number_format((float) $item['other_allowance'], 2) ?></td>
                                        <td style="text-align:right"><strong>₹<?= number_format((float) $item['gross_amount'], 2) ?></strong></td>
                                        <td style="text-align:right;color:#dc2626;">₹<?= number_format((float) $item['pf'], 2) ?></td>
                                        <td style="text-align:right;color:#dc2626;">₹<?= number_format((float) $item['esi'], 2) ?></td>
                                        <td style="text-align:right;color:#dc2626;">₹<?= number_format((float) $item['professional_tax'], 2) ?></td>
                                        <td style="text-align:right;color:#dc2626;">₹<?= number_format((float) $item['tds'], 2) ?></td>
                                        <td style="text-align:right;color:#dc2626;">₹<?= number_format((float) $item['loan'], 2) ?></td>
                                        <td style="text-align:right;color:#dc2626;font-weight:600;">₹<?= number_format((float) $item['total_deductions'], 2) ?></td>
                                        <td style="text-align:right;"><strong style="color:#059669;">₹<?= number_format((float) $item['net_payout'], 2) ?></strong></td>
                                        <td>
                                            <?php if ($item['payment_status'] === 'Paid'): ?>
                                                <span class="badge-paid-sm">Paid</span>
                                            <?php else: ?>
                                                <span class="badge-pending">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals row -->
                    <div style="margin-top:1rem;padding:.75rem 1rem;background:#f8fafc;border-radius:8px;display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.85rem;">
                        <span><strong>Gross:</strong> ₹<?= number_format((float) $viewRun['total_gross'], 2) ?></span>
                        <span style="color:#dc2626;"><strong>Deductions:</strong> ₹<?= number_format((float) $viewRun['total_deductions'], 2) ?></span>
                        <span style="color:#059669;"><strong>Net Pay:</strong> ₹<?= number_format((float) $viewRun['total_net'], 2) ?></span>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Payslip Print View -->
            <div class="payslip-wrap no-print" style="margin-top:2rem;display:none;" id="payslip-section">
                <div class="payslip-header">
                    <h2>Salary Slip – <?= e($viewRun['month_label']) ?></h2>
                    <p>SIBA ERP – Employee Payroll Statement</p>
                </div>
                <div class="payslip-meta">
                    <div><span class="pm-label">Pay Period:</span> <span class="pm-value"><?= e($viewRun['month_label']) ?></span></div>
                    <div><span class="pm-label">Status:</span> <span class="pm-value"><?= e($viewRun['status']) ?></span></div>
                    <div><span class="pm-label">Total Employees:</span> <span class="pm-value"><?= (int) $viewRun['total_employees'] ?></span></div>
                    <div><span class="pm-label">Generated:</span> <span class="pm-value"><?= e($viewRun['generated_at'] ?? '—') ?></span></div>
                </div>
                <table class="payslip-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Basic</th>
                            <th>HRA</th>
                            <th>Allowances</th>
                            <th>Gross</th>
                            <th>Deductions</th>
                            <th>Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($viewItems as $item): ?>
                            <tr>
                                <td><?= e($item['employee_name']) ?></td>
                                <td>₹<?= number_format((float) $item['basic'], 2) ?></td>
                                <td>₹<?= number_format((float) $item['hra'], 2) ?></td>
                                <td>₹<?= number_format((float) $item['other_allowance'], 2) ?></td>
                                <td>₹<?= number_format((float) $item['gross_amount'], 2) ?></td>
                                <td>₹<?= number_format((float) $item['total_deductions'], 2) ?></td>
                                <td><strong>₹<?= number_format((float) $item['net_payout'], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="row-total">
                            <td><strong>TOTAL</strong></td>
                            <td>₹<?= number_format((float) array_sum(array_column($viewItems, 'basic')), 2) ?></td>
                            <td>₹<?= number_format((float) array_sum(array_column($viewItems, 'hra')), 2) ?></td>
                            <td>₹<?= number_format((float) array_sum(array_column($viewItems, 'other_allowance')), 2) ?></td>
                            <td>₹<?= number_format((float) $viewRun['total_gross'], 2) ?></td>
                            <td>₹<?= number_format((float) $viewRun['total_deductions'], 2) ?></td>
                            <td><strong>₹<?= number_format((float) $viewRun['total_net'], 2) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- SALARY STRUCTURE MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-salary">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="salary-modal-title">Set Employee Salary</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <form method="post" id="salary-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_salary">
            <input type="hidden" name="edit_id" id="salary-edit-id" value="">

            <div class="field-row">
                <div>
                    <label for="salary_employee_id">Employee *</label>
                    <select name="employee_id" id="salary_employee_id" required onchange="autoFillEmployeeName()">
                        <option value="">-- Select Employee --</option>
                        <?php
                        $empIdsWithSalary = array_column($salaryStructures, 'employee_id');
                        foreach ($employees as $emp):
                        ?>
                            <option value="<?= (int) $emp['id'] ?>" data-name="<?= e($emp['name']) ?>" data-dept="<?= e($emp['department'] ?? '') ?>" <?= in_array((int) $emp['id'], $empIdsWithSalary, true) ? '' : '' ?>><?= e($emp['employee_code'] ?? '') ?> – <?= e($emp['name']) ?> (<?= e($emp['department'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="salary_employee_name">Employee Name</label>
                    <input type="text" name="employee_name" id="salary_employee_name" readonly style="background:#f1f5f9;">
                </div>
            </div>

            <h3 style="font-size:.9rem;margin:1rem 0 .5rem;color:#2563eb;border-bottom:1px solid #e2e8f0;padding-bottom:.35rem;">Earnings</h3>
            <div class="field-row">
                <div>
                    <label for="salary_basic">Basic Salary *</label>
                    <input type="number" step="0.01" min="0" name="basic_salary" id="salary_basic" required value="0" oninput="calcSalaryNet()">
                </div>
                <div>
                    <label for="salary_hra">HRA</label>
                    <input type="number" step="0.01" min="0" name="hra" id="salary_hra" value="0" oninput="calcSalaryNet()">
                </div>
                <div>
                    <label for="salary_other">Other Allowance</label>
                    <input type="number" step="0.01" min="0" name="other_allowance" id="salary_other" value="0" oninput="calcSalaryNet()">
                </div>
            </div>

            <h3 style="font-size:.9rem;margin:1rem 0 .5rem;color:#dc2626;border-bottom:1px solid #e2e8f0;padding-bottom:.35rem;">Deductions</h3>
            <div class="field-row">
                <div>
                    <label for="salary_pf">PF Deduction</label>
                    <input type="number" step="0.01" min="0" name="pf_deduction" id="salary_pf" value="0" oninput="calcSalaryNet()">
                </div>
                <div>
                    <label for="salary_esi">ESI Deduction</label>
                    <input type="number" step="0.01" min="0" name="esi_deduction" id="salary_esi" value="0" oninput="calcSalaryNet()">
                </div>
                <div>
                    <label for="salary_pt">Professional Tax</label>
                    <input type="number" step="0.01" min="0" name="professional_tax" id="salary_pt" value="0" oninput="calcSalaryNet()">
                </div>
            </div>
            <div class="field-row">
                <div>
                    <label for="salary_tds">TDS</label>
                    <input type="number" step="0.01" min="0" name="tds" id="salary_tds" value="0" oninput="calcSalaryNet()">
                </div>
                <div>
                    <label for="salary_loan">Loan Deduction</label>
                    <input type="number" step="0.01" min="0" name="loan_deduction" id="salary_loan" value="0" oninput="calcSalaryNet()">
                </div>
                <div>
                    <label style="color:#059669;font-weight:700;">Net Salary</label>
                    <div id="salary-net-display" style="padding:.5rem .7rem;background:#f0fdf4;border:1px solid #a7f3d0;border-radius:8px;font-size:1.1rem;font-weight:700;color:#059669;">₹0.00</div>
                </div>
            </div>

            <h3 style="font-size:.9rem;margin:1rem 0 .5rem;color:#475569;border-bottom:1px solid #e2e8f0;padding-bottom:.35rem;">Payment Details</h3>
            <div class="field-row">
                <div>
                    <label for="salary_bank">Bank Account</label>
                    <input type="text" name="bank_account" id="salary_bank" placeholder="Account number or name">
                </div>
                <div>
                    <label for="salary_mode">Payment Mode</label>
                    <select name="payment_mode" id="salary_mode">
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:1.25rem;display:flex;gap:.75rem;">
                <button type="submit" class="btn" style="background:#059669;padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;color:#fff;border:none;border-radius:8px;cursor:pointer;" id="salary-submit-btn">Save Salary Structure</button>
                <button type="button" class="btn btn-outline" style="padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;border-radius:8px;cursor:pointer;" onclick="closeModals()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- GENERATE PAYROLL MODAL -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-generate">
    <div class="modal-box confirm-dialog" style="max-width:480px;">
        <div class="modal-header">
            <h2 style="color:#2563eb;">Generate Monthly Payroll</h2>
            <button type="button" class="modal-close" onclick="closeModals()">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate_payroll">
            <p>This will create payroll for <strong id="gen-month-display"></strong> with <strong><?= $totalStructures ?></strong> employees who have salary structures configured.</p>
            <div style="margin-bottom:1rem;">
                <label for="gen_month_label" style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.85rem;">Select Month *</label>
                <select name="month_label" id="gen_month_label" required style="width:100%;padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.875rem;" onchange="document.getElementById('gen-month-display').textContent = this.value;">
                    <option value="">-- Select Month --</option>
                    <?php foreach ([$lastMonth, $currentMonth, $nextMonth] as $ml): ?>
                        <?php if (!in_array($ml, $existingMonths, true)): ?>
                            <option value="<?= e($ml) ?>"><?= e($ml) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.6rem .8rem;font-size:.82rem;color:#92400e;margin-bottom:1rem;">
                ⚠ Only employees with active salary structures will be included. Each employee can only appear once per payroll run.
            </div>
            <div style="display:flex;gap:.75rem;">
                <button type="submit" class="btn" style="background:#2563eb;color:#fff;border:none;padding:.6rem 1.5rem;border-radius:8px;font-weight:600;cursor:pointer;font-size:.9rem;">Generate Payroll</button>
                <button type="button" class="btn btn-outline" style="padding:.6rem 1.5rem;border-radius:8px;font-size:.9rem;cursor:pointer;" onclick="closeModals()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/erp.js?v=<?= filemtime(dirname(__DIR__) . '/assets/erp.js') ?>"></script>
<script>
var salaryData = <?= json_encode(array_map(fn(array $s) => [
    'id' => (int) $s['id'],
    'employee_id' => (int) $s['employee_id'],
    'employee_name' => $s['employee_name'],
    'basic_salary' => (float) $s['basic_salary'],
    'hra' => (float) $s['hra'],
    'other_allowance' => (float) $s['other_allowance'],
    'pf_deduction' => (float) $s['pf_deduction'],
    'esi_deduction' => (float) $s['esi_deduction'],
    'professional_tax' => (float) $s['professional_tax'],
    'tds' => (float) $s['tds'],
    'loan_deduction' => (float) $s['loan_deduction'],
    'net_salary' => (float) $s['net_salary'],
    'bank_account' => $s['bank_account'] ?? '',
    'payment_mode' => $s['payment_mode'] ?? 'Bank Transfer',
], $salaryStructures)) ?>;

function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(function(m) { m.classList.remove('open'); });
}

function openModal(type) {
    closeModals();
    if (type === 'salary') {
        document.getElementById('salary-modal-title').textContent = 'Set Employee Salary';
        document.getElementById('salary-edit-id').value = '';
        document.getElementById('salary-submit-btn').textContent = 'Save Salary Structure';
        document.getElementById('salary-form').reset();
        document.getElementById('salary-net-display').textContent = '₹0.00';
        calcSalaryNet();
        document.getElementById('modal-salary').classList.add('open');
    } else if (type === 'generate') {
        document.getElementById('modal-generate').classList.add('open');
    }
}

function editSalary(data) {
    closeModals();
    document.getElementById('salary-modal-title').textContent = 'Edit Employee Salary';
    document.getElementById('salary-edit-id').value = data.id;
    document.getElementById('salary-submit-btn').textContent = 'Update Salary Structure';

    var empSel = document.getElementById('salary_employee_id');
    empSel.value = data.employee_id;
    document.getElementById('salary_employee_name').value = data.employee_name;
    document.getElementById('salary_basic').value = data.basic_salary;
    document.getElementById('salary_hra').value = data.hra;
    document.getElementById('salary_other').value = data.other_allowance;
    document.getElementById('salary_pf').value = data.pf_deduction;
    document.getElementById('salary_esi').value = data.esi_deduction;
    document.getElementById('salary_pt').value = data.professional_tax;
    document.getElementById('salary_tds').value = data.tds;
    document.getElementById('salary_loan').value = data.loan_deduction;
    document.getElementById('salary_bank').value = data.bank_account;
    document.getElementById('salary_mode').value = data.payment_mode;
    calcSalaryNet();
    document.getElementById('modal-salary').classList.add('open');
}

function autoFillEmployeeName() {
    var sel = document.getElementById('salary_employee_id');
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('salary_employee_name').value = opt.getAttribute('data-name') || '';
}

function calcSalaryNet() {
    var basic = parseFloat(document.getElementById('salary_basic').value) || 0;
    var hra = parseFloat(document.getElementById('salary_hra').value) || 0;
    var other = parseFloat(document.getElementById('salary_other').value) || 0;
    var pf = parseFloat(document.getElementById('salary_pf').value) || 0;
    var esi = parseFloat(document.getElementById('salary_esi').value) || 0;
    var pt = parseFloat(document.getElementById('salary_pt').value) || 0;
    var tds = parseFloat(document.getElementById('salary_tds').value) || 0;
    var loan = parseFloat(document.getElementById('salary_loan').value) || 0;
    var gross = basic + hra + other;
    var deductions = pf + esi + pt + tds + loan;
    var net = gross - deductions;
    document.getElementById('salary-net-display').textContent = '₹' + net.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
