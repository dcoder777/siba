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

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'configure_salary') {
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $basicSalary = (float) ($_POST['basic_salary'] ?? 0);
        $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
        $hra = (float) ($_POST['hra'] ?? 0);
        $transportAllowance = (float) ($_POST['transport_allowance'] ?? 0);
        $specialAllowance = (float) ($_POST['special_allowance'] ?? 0);
        $pfDeduction = (float) ($_POST['pf_deduction'] ?? 0);
        $ptDeduction = (float) ($_POST['pt_deduction'] ?? 0);
        
        $ctc = ($basicSalary + $hra + $transportAllowance + $specialAllowance) * 12;

        if ($staffId > 0) {
            $stmt = $pdo->prepare("UPDATE employees SET ctc=?, basic_salary=?, salary_effective_date=?, hra=?, transport_allowance=?, special_allowance=?, pf_deduction=?, pt_deduction=? WHERE id=?");
            $stmt->execute([$ctc, $basicSalary, $effectiveDate ?: null, $hra, $transportAllowance, $specialAllowance, $pfDeduction, $ptDeduction, $staffId]);
            $success = 'Salary structure configured successfully.';
        } else {
            $error = 'Please select a staff member.';
        }
    }

    header('Location: salary-setup.php' . ($error !== '' ? '?error=' . urlencode($error) : ''));
    exit;
}

// Fetch Active Employees
$stmt = $pdo->query("SELECT * FROM employees WHERE status = 'active' ORDER BY name");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

$totalCTC = 0;
foreach ($rows as $r) {
    $totalCTC += (float) $r['ctc'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Salary Structure - SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
    <style>
        .stats-grid { display: grid; gap: 1.5rem; }
        .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-card .stat-label { font-size: 0.85rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.5rem; }
        .stat-card .stat-value { font-size: 2rem; font-weight: 700; color: #1e293b; line-height: 1; }
        .grid-form-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-top: 1rem; }
        .grid-form-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1.5rem; }
        
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff; border-radius: 8px; padding: 20px 24px;
            width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto;
            position: relative;
        }
        .modal-close {
            position: absolute; top: 20px; right: 24px; font-size: 24px;
            color: #64748b; cursor: pointer; text-decoration: none;
        }
        .modal-header { display: flex; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem; margin-bottom: 1rem; }
        .modal-header h2 { margin: 0; display: flex; align-items: center; gap: 8px; font-size: 1.25rem; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = 'salary-setup.php'; include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar" style="display:flex; justify-content:space-between; align-items:center;">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">HR & Staff Management</span>
                    <h1>Salary Structure</h1>
                    <p>Manage Annual Cost to Company (CTC) and payout account details for active staff.</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" onclick="openConfigureModal()">Configure Salary Structure</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success" style="margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-label">Total Active Staff</div>
                <div class="stat-value"><?= count($rows) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Annual CTC</div>
                <div class="stat-value" style="color:#2563eb;">?<?= number_format($totalCTC, 2) ?></div>
                <div style="font-size:0.75rem; color:#64748b; margin-top:4px;">Approx. Monthly: ?<?= number_format($totalCTC / 12, 2) ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Active Staff Salary Structures</h2>
            </div>
            <div class="card-body" style="padding:0; overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Annual CTC</th>
                            <th>Basic Salary</th>
                            <th>Net Monthly (Approx)</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:2rem;">No active employees found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): 
                            $gross = (float)$row['basic_salary'] + (float)$row['hra'] + (float)$row['transport_allowance'] + (float)$row['special_allowance'];
                            $ded = (float)$row['pf_deduction'] + (float)$row['pt_deduction'];
                            $net = $gross - $ded;
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($row['employee_code']) ?></td>
                            <td><?= e($row['name']) ?></td>
                            <td><?= e($row['department']) ?></td>
                            <td style="font-family:monospace;">?<?= number_format((float)$row['ctc'], 2) ?></td>
                            <td style="font-family:monospace;">?<?= number_format((float)$row['basic_salary'], 2) ?></td>
                            <td style="font-family:monospace; color:#16a34a; font-weight:bold;">?<?= number_format($net, 2) ?></td>
                            <td>
                                <button type="button" class="btn btn-soft btn-sm" onclick='editSalary(<?= json_encode($row) ?>)'>Edit Structure</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- Modal -->
<div class="modal-overlay" id="salaryModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="modal_title"><span style="color:#2563eb;">⚙</span> Configure Salary Structure</h2>
            <a href="javascript:void(0)" class="modal-close" onclick="closeConfigureModal()">&times;</a>
        </div>
        <form method="POST" action="salary-setup.php" id="salaryForm">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="configure_salary">
            
            <div class="grid-form-3">
                <div class="form-group" id="staff_select_group">
                    <label id="staff_label">Select Staff Member <span style="color:red">*</span></label>
                    <select name="staff_id" id="modal_staff_id" class="form-control" required>
                        <option value="">-- Choose Staff --</option>
                        <?php foreach ($rows as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= e($r['name']) ?> (<?= e($r['employee_code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="modal_staff_display" class="form-control" readonly style="background-color: #f8fafc; color: #475569; display:none;">
                </div>
                <div class="form-group">
                    <label>Basic Salary (₹) <span style="color:red">*</span></label>
                    <input type="number" step="0.01" name="basic_salary" id="modal_basic" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Effective Date</label>
                    <input type="date" name="effective_date" id="modal_effective" class="form-control">
                </div>
            </div>

            <div class="grid-form-4">
                <div class="form-group">
                    <label>HRA (House Rent ₹)</label>
                    <input type="number" step="0.01" name="hra" id="modal_hra" class="form-control">
                </div>
                <div class="form-group">
                    <label>Transport Allowance (₹)</label>
                    <input type="number" step="0.01" name="transport_allowance" id="modal_transport" class="form-control">
                </div>
                <div class="form-group">
                    <label>Special Allowance (₹)</label>
                    <input type="number" step="0.01" name="special_allowance" id="modal_special" class="form-control">
                </div>
                <div class="form-group"></div>
            </div>

            <div class="grid-form-4" style="margin-top:0;">
                <div class="form-group">
                    <label>PF Deduction (₹)</label>
                    <input type="number" step="0.01" name="pf_deduction" id="modal_pf" class="form-control">
                </div>
                <div class="form-group">
                    <label>PT Deduction (₹)</label>
                    <input type="number" step="0.01" name="pt_deduction" id="modal_pt" class="form-control">
                </div>
            </div>

            <div style="margin-top:2rem; display:flex; gap:1rem; align-items:center; justify-content:flex-end; border-top:1px solid #e2e8f0; padding-top:1rem;">
                <button type="button" class="btn btn-outline" onclick="closeConfigureModal()">Cancel</button>
                <button type="submit" id="modal_submit_btn" class="btn btn-primary" style="background:#0f172a; color:#fff; padding:0.5rem 1rem;">Save Salary Structure</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openConfigureModal() {
        document.getElementById('salaryForm').reset();
        document.getElementById('modal_title').innerHTML = '<span style="color:#2563eb;">⚙</span> Configure Salary Structure';
        document.getElementById('staff_label').innerHTML = 'Select Staff Member <span style="color:red">*</span>';
        document.getElementById('modal_staff_id').style.display = 'block';
        document.getElementById('modal_staff_id').required = true;
        document.getElementById('modal_staff_display').style.display = 'none';
        document.getElementById('modal_submit_btn').innerHTML = '<span style="margin-right:6px">💾</span> Save Salary Structure';
        document.getElementById('modal_submit_btn').style.background = '#0f172a';
        document.getElementById('salaryModal').classList.add('open');
    }
    function closeConfigureModal() {
        document.getElementById('salaryModal').classList.remove('open');
    }
    function editSalary(data) {
        document.getElementById('salaryForm').reset();
        document.getElementById('modal_title').innerHTML = '<span style="color:#eab308;">📝</span> Edit Staff Salary Structure';
        document.getElementById('staff_label').innerHTML = 'Staff Member';
        
        let staffSelect = document.getElementById('modal_staff_id');
        staffSelect.style.display = 'none';
        staffSelect.required = false;
        staffSelect.value = data.id; // Still needs to be submitted
        
        let staffDisplay = document.getElementById('modal_staff_display');
        staffDisplay.style.display = 'block';
        staffDisplay.value = data.name; // User screenshot only shows name
        
        document.getElementById('modal_basic').value = data.basic_salary > 0 ? parseFloat(data.basic_salary) : '';
        document.getElementById('modal_effective').value = data.salary_effective_date || '';
        document.getElementById('modal_hra').value = data.hra > 0 ? parseFloat(data.hra) : '';
        document.getElementById('modal_transport').value = data.transport_allowance > 0 ? parseFloat(data.transport_allowance) : '';
        document.getElementById('modal_special').value = data.special_allowance > 0 ? parseFloat(data.special_allowance) : '';
        document.getElementById('modal_pf').value = data.pf_deduction > 0 ? parseFloat(data.pf_deduction) : '';
        document.getElementById('modal_pt').value = data.pt_deduction > 0 ? parseFloat(data.pt_deduction) : '';
        
        let submitBtn = document.getElementById('modal_submit_btn');
        submitBtn.innerHTML = '<span style="margin-right:6px">💾</span> Update Salary Structure';
        submitBtn.style.background = '#334155';
        
        document.getElementById('salaryModal').classList.add('open');
    }
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>