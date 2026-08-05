<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';


$error = '';
$success = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS budget_heads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS financial_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active','closed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    financial_year_id INT NOT NULL,
    department VARCHAR(150) NOT NULL,
    budget_head_id INT NOT NULL,
    budget_head_name VARCHAR(255) NOT NULL,
    annual_budget DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_used DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_committed DECIMAL(14,2) NOT NULL DEFAULT 0,
    available_budget DECIMAL(14,2) NOT NULL DEFAULT 0,
    alert_percentage INT NOT NULL DEFAULT 80,
    block_extra TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_fy (financial_year_id),
    KEY idx_department (department),
    KEY idx_head (budget_head_id)
)");

try { $pdo->exec("ALTER TABLE budget_heads ADD INDEX idx_name (name)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE budgets ADD UNIQUE KEY uniq_budget_entry (financial_year_id, department, budget_head_id)"); } catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_budget') {
            $fyId = (int) ($_POST['financial_year_id'] ?? 0);
            $department = trim((string) ($_POST['department'] ?? ''));
            $headId = (int) ($_POST['budget_head_id'] ?? 0);
            $annualBudget = (float) ($_POST['annual_budget'] ?? 0);
            $alertPct = (int) ($_POST['alert_percentage'] ?? 80);
            $blockExtra = isset($_POST['block_extra']) ? 1 : 0;

            if ($fyId <= 0) throw new \RuntimeException('Financial year is required.');
            if ($department === '') throw new \RuntimeException('Department is required.');
            if ($headId <= 0) throw new \RuntimeException('Budget head is required.');
            if ($annualBudget < 0) throw new \RuntimeException('Annual budget cannot be negative.');
            if ($alertPct < 1 || $alertPct > 100) $alertPct = 80;

            $headName = $pdo->prepare("SELECT name FROM budget_heads WHERE id = ?");
            $headName->execute([$headId]);
            $hn = $headName->fetchColumn();
            if (!$hn) throw new \RuntimeException('Budget head not found.');

            $available = $annualBudget;

            $stmt = $pdo->prepare("INSERT INTO budgets (financial_year_id, department, budget_head_id, budget_head_name, annual_budget, amount_used, amount_committed, available_budget, alert_percentage, block_extra, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$fyId, $department, $headId, $hn, $annualBudget, $available, $alertPct, $blockExtra]);
            $success = 'Budget created successfully.';
        }

        if ($action === 'update_budget') {
            $id = (int) ($_POST['id'] ?? 0);
            $annualBudget = (float) ($_POST['annual_budget'] ?? 0);
            $alertPct = (int) ($_POST['alert_percentage'] ?? 80);
            $blockExtra = isset($_POST['block_extra']) ? 1 : 0;

            if ($id <= 0) throw new \RuntimeException('Invalid budget entry.');
            if ($annualBudget < 0) throw new \RuntimeException('Annual budget cannot be negative.');
            if ($alertPct < 1 || $alertPct > 100) $alertPct = 80;

            $existing = $pdo->prepare("SELECT amount_used, amount_committed FROM budgets WHERE id = ?");
            $existing->execute([$id]);
            $ex = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$ex) throw new \RuntimeException('Budget entry not found.');

            $used = (float) $ex['amount_used'];
            $committed = (float) $ex['amount_committed'];
            $available = $annualBudget - $used - $committed;

            $pdo->prepare("UPDATE budgets SET annual_budget = ?, available_budget = ?, alert_percentage = ?, block_extra = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$annualBudget, $available, $alertPct, $blockExtra, $id]);
            $success = 'Budget updated successfully.';
        }

        if ($action === 'delete_budget') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new \RuntimeException('Invalid budget entry.');
            $pdo->prepare("DELETE FROM budgets WHERE id = ?")->execute([$id]);
            $success = 'Budget deleted successfully.';
        }

        if ($action === 'create_head') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            if ($name === '') throw new \RuntimeException('Head name is required.');
            $pdo->prepare("INSERT INTO budget_heads (name, description, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())")->execute([$name, $desc ?: null]);
            $success = 'Budget head "' . $name . '" created.';
        }

        if ($action === 'toggle_head') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 1);
            if ($id > 0) {
                $pdo->prepare("UPDATE budget_heads SET is_active = ?, updated_at = NOW() WHERE id = ?")->execute([$isActive ? 1 : 0, $id]);
                $success = $isActive ? 'Budget head activated.' : 'Budget head deactivated.';
            }
        }

        if ($action === 'delete_head') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM budget_heads WHERE id = ?")->execute([$id]);
                $success = 'Budget head deleted.';
            }
        }

    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        $fyParam = isset($_POST['financial_year_id']) ? '&fy=' . urlencode($_POST['financial_year_id']) : '';
        header('Location: budget.php?success=' . urlencode($success) . $fyParam);
        exit;
    }
}

if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}

$fyFilter = (int) ($_GET['fy'] ?? 0);
if ($fyFilter <= 0) {
    $activeFy = $pdo->query("SELECT id FROM financial_years WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $fyFilter = (int) ($activeFy ?? 0);
}

function fy_options(PDO $pdo): array
{
    return $pdo->query("SELECT id, label, start_date, end_date, status FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
}

function budget_head_options(PDO $pdo): array
{
    return $pdo->query("SELECT id, name FROM budget_heads WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$fyList = fy_options($pdo);
$headList = budget_head_options($pdo);

$where = [];
$params = [];
if ($fyFilter > 0) {
    $where[] = 'b.financial_year_id = :fy';
    $params[':fy'] = $fyFilter;
}
$departmentFilter = trim((string) ($_GET['dept'] ?? ''));
if ($departmentFilter !== '') {
    $where[] = 'b.department = :dept';
    $params[':dept'] = $departmentFilter;
}
$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT b.*, fy.label AS fy_label
    FROM budgets b
    LEFT JOIN financial_years fy ON fy.id = b.financial_year_id
    $whereSql
    ORDER BY b.department, b.budget_head_name");
$stmt->execute($params);
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = ['total' => 0, 'used' => 0, 'available' => 0, 'over_budget' => 0];
foreach ($budgets as $b) {
    $stats['total'] += (float) $b['annual_budget'];
    $stats['used'] += (float) $b['amount_used'];
    $stats['available'] += (float) $b['available_budget'];
    if ((float) $b['annual_budget'] > 0 && (float) $b['amount_used'] > (float) $b['annual_budget']) {
        $stats['over_budget']++;
    }
}

$departments = $pdo->query("SELECT DISTINCT department FROM budgets ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

$grouped = [];
foreach ($budgets as $b) {
    $grouped[$b['department']][] = $b;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Budget Management – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .summary-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
        .summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem 1.15rem;transition:box-shadow .15s ease,transform .15s ease}
        .summary-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
        .summary-card .sc-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
        .summary-card .sc-value{font-size:1.35rem;font-weight:700;margin-top:.2rem;line-height:1;font-variant-numeric:tabular-nums;white-space:nowrap}
        .summary-card.blue{border-left:4px solid #2563eb}.summary-card.blue .sc-value{color:#2563eb}
        .summary-card.red{border-left:4px solid #dc2626}.summary-card.red .sc-value{color:#dc2626}
        .summary-card.green{border-left:4px solid #10b981}.summary-card.green .sc-value{color:#10b981}
        .summary-card.orange{border-left:4px solid #f59e0b}.summary-card.orange .sc-value{color:#f59e0b}
        .dept-group{margin-bottom:1.25rem}
        .dept-header{display:flex;align-items:center;gap:.6rem;padding:.65rem 1rem;border-radius:10px 10px 0 0;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#f1f5f9;color:#334155}
        .dept-header .count{margin-left:auto;background:rgba(255,255,255,.6);padding:.15em .5em;border-radius:999px;font-size:.7rem}
        .dept-subtotal{padding:.6rem 1rem;background:#f8fafc;border-top:2px solid #e2e8f0;font-size:.82rem;font-weight:600;display:flex;gap:1.5rem;flex-wrap:wrap}
        .app-table{width:100%;border-collapse:collapse;font-size:.85rem}
        .app-table th{text-align:left;padding:.6rem;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;white-space:nowrap}
        .app-table td{padding:.55rem .6rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
        .app-table tbody tr:hover td{background:#f8fafc}
        .app-table tbody tr:last-child td{border-bottom:none}
        .field-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem}
        .modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:1.5rem;background:rgba(0,0,0,.5);backdrop-filter:blur(3px);z-index:1055}
        .modal-backdrop.show{display:flex}
        .modal-backdrop .modal{position:relative;display:block;top:auto;left:auto;width:min(680px,100%);height:auto;max-height:90vh;margin:0;overflow:auto;padding:1.25rem;border-radius:12px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)}
        .modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1rem}
        .icon-btn{border:1px solid #e2e8f0;background:#fff;color:#1e293b;border-radius:.375rem;min-height:36px;padding:.45rem .75rem;cursor:pointer;font-weight:600;font-size:.865rem}
        .icon-btn:hover{background:#f1f3f5}
        .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25em .6em;border-radius:999px;font-size:.75rem;font-weight:600}
        .badge-active{background:#d1fae5;color:#065f46}
        .badge-blocked{background:#fee2e2;color:#991b1b}
        .badge-over{background:#fee2e2;color:#991b1b}
        .badge-ok{background:#d1fae5;color:#065f46}
        .badge-warn{background:#fef3c7;color:#92400e}
        .badge-alert{background:#ffedd5;color:#9a3412}
        .util-bar{width:100%;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;position:relative}
        .util-bar .fill{height:100%;border-radius:4px;transition:width .3s}
        .util-bar .fill.green{background:#10b981}
        .util-bar .fill.yellow{background:#f59e0b}
        .util-bar .fill.red{background:#dc2626}
        .filter-bar{display:flex;align-items:flex-end;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem}
        .filter-bar label{font-size:.78rem;margin-bottom:.2rem}
        .filter-bar input,.filter-bar select{min-height:36px;padding:.4rem .6rem;border-radius:8px}
        .action-btns{display:flex;gap:.3rem;flex-wrap:nowrap}
        .text-right{text-align:right}
        .text-center{text-align:center}
        .btn-sm{min-height:36px;padding:.4rem .85rem;font-size:.82rem;border-radius:8px}
        @media(max-width:768px){.summary-cards{grid-template-columns:1fr 1fr}.field-grid{grid-template-columns:1fr}.filter-bar{flex-direction:column;align-items:stretch}}
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Budget Management</h1>
                    <p>Set and monitor departmental budgets per financial year.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button class="btn btn-sm" onclick="openModal('budgetModal')">+ Create Budget</button>
                    <button class="btn btn-sm btn-soft" onclick="openModal('headModal')">Manage Heads</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="filter-bar">
            <form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;width:100%;">
                <div style="display:flex;flex-direction:column;">
                    <label for="f_fy">Financial Year</label>
                    <select name="fy" id="f_fy" style="min-width:180px;">
                        <option value="">All Years</option>
                        <?php foreach ($fyList as $fy): ?>
                            <option value="<?= (int) $fy['id'] ?>" <?= ($fyFilter === (int) $fy['id']) ? 'selected' : '' ?>><?= e($fy['label']) ?> (<?= e($fy['status']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;flex-direction:column;">
                    <label for="f_dept">Department</label>
                    <select name="dept" id="f_dept" style="min-width:160px;">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= e($dept) ?>" <?= $departmentFilter === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="align-self:flex-end;">
                    <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:36px;font-size:.85rem;border-radius:8px;">Filter</button>
                    <?php if ($fyFilter > 0 || $departmentFilter !== ''): ?>
                        <a href="budget.php" style="font-size:.85rem;color:#64748b;margin-left:.5rem;">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="summary-cards">
            <div class="summary-card blue">
                <div class="sc-label">Total Budget</div>
                <div class="sc-value">₹ <?= number_format($stats['total'], 0) ?></div>
            </div>
            <div class="summary-card orange">
                <div class="sc-label">Total Used</div>
                <div class="sc-value">₹ <?= number_format($stats['used'], 0) ?></div>
            </div>
            <div class="summary-card green">
                <div class="sc-label">Total Available</div>
                <div class="sc-value">₹ <?= number_format($stats['available'], 0) ?></div>
            </div>
            <div class="summary-card red">
                <div class="sc-label">Over-Budget Heads</div>
                <div class="sc-value"><?= $stats['over_budget'] ?></div>
            </div>
        </div>

        <?php if (empty($grouped)): ?>
            <section class="panel" style="padding:2rem;">
                <p style="text-align:center;color:#94a3b8;">No budgets found. Click "+ Create Budget" to add one.</p>
            </section>
        <?php else: ?>
            <?php foreach ($grouped as $dept => $deptBudgets): ?>
                <?php
                $deptTotal = 0;
                $deptUsed = 0;
                $deptAvail = 0;
                foreach ($deptBudgets as $db) {
                    $deptTotal += (float) $db['annual_budget'];
                    $deptUsed += (float) $db['amount_used'];
                    $deptAvail += (float) $db['available_budget'];
                }
                ?>
                <div class="dept-group">
                    <div class="dept-header">
                        <span>📁 <?= e($dept) ?></span>
                        <span class="count"><?= count($deptBudgets) ?> head(s)</span>
                    </div>
                    <div class="panel" style="padding:0;overflow:auto;border-radius:0 0 10px 10px;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Budget Head</th>
                                    <th class="text-right">Annual Budget</th>
                                    <th class="text-right">Used</th>
                                    <th class="text-right">Committed</th>
                                    <th class="text-right">Available</th>
                                    <th style="min-width:140px;">Utilization</th>
                                    <th class="text-center">Alert %</th>
                                    <th class="text-center">Block Extra</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deptBudgets as $b): ?>
                                    <?php
                                    $annual = (float) $b['annual_budget'];
                                    $used = (float) $b['amount_used'];
                                    $committed = (float) $b['amount_committed'];
                                    $available = (float) $b['available_budget'];
                                    $pct = $annual > 0 ? round(($used / $annual) * 100, 1) : 0;
                                    $barColor = $pct < 60 ? 'green' : ($pct <= 80 ? 'yellow' : 'red');
                                    $isOver = $annual > 0 && $used > $annual;
                                    $isBlocked = (int) $b['block_extra'] === 1;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($b['budget_head_name']) ?></strong>
                                            <?php if ($isOver): ?>
                                                <span class="badge badge-over" style="margin-left:.4rem;">Over Budget</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">₹ <?= number_format($annual, 2) ?></td>
                                        <td class="text-right" style="<?= $isOver ? 'color:#dc2626;font-weight:700;' : '' ?>">₹ <?= number_format($used, 2) ?></td>
                                        <td class="text-right">₹ <?= number_format($committed, 2) ?></td>
                                        <td class="text-right" style="<?= $available < 0 ? 'color:#dc2626;font-weight:700;' : '' ?>">₹ <?= number_format($available, 2) ?></td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:.5rem;">
                                                <div class="util-bar" style="flex:1;">
                                                    <div class="fill <?= $barColor ?>" style="width:<?= min($pct, 100) ?>%"></div>
                                                </div>
                                                <span style="font-size:.78rem;font-weight:600;min-width:36px;text-align:right;"><?= $pct ?>%</span>
                                            </div>
                                            <?php if ($pct >= (int) $b['alert_percentage']): ?>
                                                <span class="badge badge-alert" style="margin-top:.25rem;">⚠ At/Over <?= e((string) $b['alert_percentage']) ?>% threshold</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= (int) $b['alert_percentage'] ?>%</td>
                                        <td class="text-center">
                                            <?php if ($isBlocked): ?>
                                                <span class="badge badge-blocked">Blocked</span>
                                            <?php else: ?>
                                                <span class="badge badge-active">Open</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <button class="btn btn-sm btn-soft" onclick="editBudget(<?= (int) $b['id'] ?>,<?= (int) $b['financial_year_id'] ?>,'<?= e($b['department']) ?>',<?= (int) $b['budget_head_id'] ?>,'<?= e($b['budget_head_name']) ?>',<?= $annual ?>,<?= (int) $b['alert_percentage'] ?>,<?= $isBlocked ? '1' : '0' ?>)">Edit</button>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this budget entry?')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete_budget">
                                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-soft" style="color:#dc2626;font-size:.75rem;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="dept-subtotal">
                            <span>Department Subtotal:</span>
                            <span>Budget: <strong>₹ <?= number_format($deptTotal, 2) ?></strong></span>
                            <span>Used: <strong>₹ <?= number_format($deptUsed, 2) ?></strong></span>
                            <span>Available: <strong>₹ <?= number_format($deptAvail, 2) ?></strong></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>

<!-- Create / Edit Budget Modal -->
<div id="budgetModal" class="modal-backdrop">
    <div class="modal" style="max-width:640px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;" id="budgetModalTitle">Create Budget</h2>
            <button class="icon-btn" onclick="closeModal('budgetModal')">✕</button>
        </div>
        <form method="post" id="budgetForm">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="budgetFormAction" value="create_budget">
            <input type="hidden" name="id" id="budgetFormId" value="0">
            <div class="field-grid">
                <div>
                    <label>Financial Year *</label>
                    <select name="financial_year_id" id="bf_fy" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($fyList as $fy): ?>
                            <option value="<?= (int) $fy['id'] ?>"><?= e($fy['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Department *</label>
                    <input type="text" name="department" id="bf_dept" list="dept-list" required placeholder="e.g. Academics, Admin, Sports">
                    <datalist id="dept-list">
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= e($dept) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label>Budget Head *</label>
                    <select name="budget_head_id" id="bf_head" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($headList as $h): ?>
                            <option value="<?= (int) $h['id'] ?>"><?= e($h['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Annual Budget (₹) *</label>
                    <input type="number" step="0.01" min="0" name="annual_budget" id="bf_budget" required value="0">
                </div>
                <div>
                    <label>Alert Threshold (%)</label>
                    <input type="number" min="1" max="100" name="alert_percentage" id="bf_alert" value="80">
                </div>
                <div style="display:flex;align-items:flex-end;padding-bottom:.35rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin:0;">
                        <input type="checkbox" name="block_extra" id="bf_block" value="1" style="width:18px;height:18px;">
                        <span>Block Extra Expenses</span>
                    </label>
                </div>
            </div>
            <div style="margin-top:1.25rem;padding:.65rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:.8rem;color:#9a3412;">
                <strong>Note:</strong> Available budget is auto-calculated as Annual Budget − Used − Committed.
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm" id="budgetSubmitBtn">Create Budget</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('budgetModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Manage Budget Heads Modal -->
<div id="headModal" class="modal-backdrop">
    <div class="modal" style="max-width:560px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Manage Budget Heads</h2>
            <button class="icon-btn" onclick="closeModal('headModal')">✕</button>
        </div>
        <div style="margin-bottom:1rem;">
            <form method="post" id="headForm" style="display:flex;gap:.5rem;align-items:flex-end;">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_head">
                <div style="flex:1;">
                    <label style="font-size:.8rem;margin-bottom:.2rem;display:block;">Head Name *</label>
                    <input type="text" name="name" id="head_name" required placeholder="e.g. Salaries, Infrastructure" style="width:100%;">
                </div>
                <div style="flex:1;">
                    <label style="font-size:.8rem;margin-bottom:.2rem;display:block;">Description</label>
                    <input type="text" name="description" id="head_desc" placeholder="Optional description" style="width:100%;">
                </div>
                <button type="submit" class="btn btn-sm" style="white-space:nowrap;">Add Head</button>
            </form>
        </div>
        <div style="max-height:300px;overflow-y:auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th class="text-center">Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $heads = $pdo->query("SELECT * FROM budget_heads ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($heads)):
                    ?>
                        <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:1rem;">No budget heads yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($heads as $h): ?>
                            <tr>
                                <td><strong><?= e($h['name']) ?></strong></td>
                                <td style="color:#64748b;font-size:.82rem;"><?= e($h['description'] ?? '—') ?></td>
                                <td class="text-center">
                                    <?php if ((int) $h['is_active']): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-blocked">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <?php if ((int) $h['is_active']): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="toggle_head">
                                                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                                                <input type="hidden" name="is_active" value="0">
                                                <button type="submit" class="btn btn-sm btn-soft" style="font-size:.73rem;">Deactivate</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="toggle_head">
                                                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                                                <input type="hidden" name="is_active" value="1">
                                                <button type="submit" class="btn btn-sm btn-soft" style="font-size:.73rem;">Activate</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this budget head?')">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_head">
                                            <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-soft" style="color:#dc2626;font-size:.73rem;">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
function editBudget(id, fyId, dept, headId, headName, annual, alertPct, blocked) {
    document.getElementById('budgetModalTitle').textContent = 'Edit Budget';
    document.getElementById('budgetFormAction').value = 'update_budget';
    document.getElementById('budgetFormId').value = id;
    document.getElementById('bf_fy').value = fyId;
    document.getElementById('bf_dept').value = dept;
    document.getElementById('bf_head').value = headId;
    document.getElementById('bf_budget').value = annual;
    document.getElementById('bf_alert').value = alertPct;
    document.getElementById('bf_block').checked = blocked === 1;
    document.getElementById('budgetSubmitBtn').textContent = 'Update Budget';
    openModal('budgetModal');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
});
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
