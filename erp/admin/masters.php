<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$pageTitle = 'Masters';
$error = '';
$success = '';

$validTabs = [
    'expense-categories', 'income-categories', 'vendors', 'bank-accounts',
    'fees-management'
];

$tab = trim((string) ($_GET['tab'] ?? 'schools'));
if (!in_array($tab, $validTabs, true)) {
    $tab = 'expense-categories';
}

// ─── Handle POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['master_action'] ?? ''));

    try {
        // ─── Expense ───
        if ($action === 'create_expense_category' || $action === 'update_expense_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $vendorId = (int) ($_POST['vendor_id'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $approvalRequired = isset($_POST['approval_required']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                $error = 'Category name is required.';
            } else {
                if ($action === 'update_expense_category' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE expense_categories SET name=?, vendor_id=?, description=?, amount=?, approval_required=?, is_active=? WHERE id=?");
                    $stmt->execute([$name, $vendorId > 0 ? $vendorId : null, $description, $amount, $approvalRequired, $isActive, $id]);
                    $success = 'Expense category updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO expense_categories (name, vendor_id, description, amount, approval_required, is_active) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$name, $vendorId > 0 ? $vendorId : null, $description, $amount, $approvalRequired, $isActive]);
                    $success = 'Expense category created.';
                }
                header("Location: masters.php?tab=expense-categories");
                exit;
            }
        }
        if ($action === 'delete_expense_category') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM expense_categories WHERE id=?")->execute([$id]);
                $success = 'Expense category deleted.';
                header("Location: masters.php?tab=expense-categories");
                exit;
            }
        }

        // ─── Income ───
        if ($action === 'create_income_category' || $action === 'update_income_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                $error = 'Category name is required.';
            } else {
                if ($action === 'update_income_category' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE income_categories SET name=?, description=?, amount=?, is_active=? WHERE id=?");
                    $stmt->execute([$name, $description, $amount, $isActive, $id]);
                    $success = 'Income category updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO income_categories (name, description, amount, is_active) VALUES (?,?,?,?)");
                    $stmt->execute([$name, $description, $amount, $isActive]);
                    $success = 'Income category created.';
                }
                header("Location: masters.php?tab=income-categories");
                exit;
            }
        }
        if ($action === 'delete_income_category') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM income_categories WHERE id=?")->execute([$id]);
                $success = 'Income category deleted.';
                header("Location: masters.php?tab=income-categories");
                exit;
            }
        }

        // ─── Vendors ───
        if ($action === 'create_vendor' || $action === 'update_vendor') {
            $id = (int) ($_POST['id'] ?? 0);
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
                if ($action === 'update_vendor' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE vendors SET vendor_code=?, name=?, mobile=?, email=?, gst_number=?, pan=?, address=?, bank_name=?, account_number=?, ifsc_code=?, is_active=? WHERE id=?");
                    $stmt->execute([$vendorCode, $name, $mobile, $email, $gstNumber, $pan, $address, $bankName, $accountNumber, $ifscCode, $isActive, $id]);
                    $success = 'Vendor updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO vendors (vendor_code, name, mobile, email, gst_number, pan, address, bank_name, account_number, ifsc_code, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$vendorCode, $name, $mobile, $email, $gstNumber, $pan, $address, $bankName, $accountNumber, $ifscCode, $isActive]);
                    $success = 'Vendor created.';
                }
                header("Location: masters.php?tab=vendors");
                exit;
            }
        }
        if ($action === 'delete_vendor') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM vendors WHERE id=?")->execute([$id]);
                $success = 'Vendor deleted.';
                header("Location: masters.php?tab=vendors");
                exit;
            }
        }

        // ─── Bank Accounts ───
        if ($action === 'create_bank_account' || $action === 'update_bank_account') {
            $id = (int) ($_POST['id'] ?? 0);
            $bankName = trim((string) ($_POST['bank_name'] ?? ''));
            $accountName = trim((string) ($_POST['account_name'] ?? ''));
            $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
            $ifscCode = trim((string) ($_POST['ifsc_code'] ?? ''));
            $branch = trim((string) ($_POST['branch'] ?? ''));
            $accountType = in_array(trim((string) ($_POST['account_type'] ?? '')), ['Savings', 'Current'], true) ? trim((string) $_POST['account_type']) : 'Savings';
            $openingBalance = (float) ($_POST['opening_balance'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($bankName === '' || $accountName === '' || $accountNumber === '') {
                $error = 'Bank name, account name and account number are required.';
            } else {
                if ($action === 'update_bank_account' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE bank_accounts SET bank_name=?, account_name=?, account_number=?, ifsc_code=?, branch=?, account_type=?, opening_balance=?, is_active=? WHERE id=?");
                    $stmt->execute([$bankName, $accountName, $accountNumber, $ifscCode, $branch, $accountType, $openingBalance, $isActive, $id]);
                    $success = 'Bank account updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO bank_accounts (bank_name, account_name, account_number, ifsc_code, branch, account_type, opening_balance, current_balance, is_active) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$bankName, $accountName, $accountNumber, $ifscCode, $branch, $accountType, $openingBalance, $openingBalance, $isActive]);
                    $success = 'Bank account created.';
                }
                header("Location: masters.php?tab=bank-accounts");
                exit;
            }
        }
        if ($action === 'delete_bank_account') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM bank_accounts WHERE id=?")->execute([$id]);
                $success = 'Bank account deleted.';
                header("Location: masters.php?tab=bank-accounts");
                exit;
            }
        }

        // ─── Fees Management ───
        if ($action === 'create_fee_head' || $action === 'update_fee_head') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $defaultAmount = (float) ($_POST['default_amount'] ?? 0);
            $frequency = trim((string) ($_POST['frequency'] ?? 'One-Time'));
            $validCategories = ['Class Fee', 'Application Fee', 'Admission Fee', 'Donation', 'Exam Fee', 'Transport Fee', 'Hostel Fee', 'Other'];
            $validFrequencies = ['One-Time', 'Monthly', 'Quarterly', 'Annual'];
            $category = in_array($category, $validCategories, true) ? $category : 'Other';
            $frequency = in_array($frequency, $validFrequencies, true) ? $frequency : 'One-Time';
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                $error = 'Fee name is required.';
            } else {
                if ($action === 'update_fee_head' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE fee_heads SET name=?, category=?, class_name=?, default_amount=?, frequency=?, is_active=? WHERE id=?");
                    $stmt->execute([$name, $category, $className ?: null, $defaultAmount, $frequency, $isActive, $id]);
                    $success = 'Fee head updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO fee_heads (name, category, class_name, default_amount, frequency, is_active) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$name, $category, $className ?: null, $defaultAmount, $frequency, $isActive]);
                    $success = 'Fee head created.';
                }
                header("Location: masters.php?tab=fees-management");
                exit;
            }
        }
        if ($action === 'delete_fee_head') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM fee_heads WHERE id=?")->execute([$id]);
                $success = 'Fee head deleted.';
                header("Location: masters.php?tab=fees-management");
                exit;
            }
        }

    } catch (Throwable $e) {
        $error = 'Operation failed: ' . $e->getMessage();
    }
}

// ─── Fetch data for each tab ───
ensure_columns($pdo, 'expense_categories', [
    'vendor_id' => "INT UNSIGNED DEFAULT NULL",
    'amount'    => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
]);
ensure_columns($pdo, 'income_categories', [
    'amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
]);
ensure_columns($pdo, 'applications', [
    'payment_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 200.00",
]);
$expenseCategories = $pdo->query("SELECT ec.*, v.name AS vendor_name FROM expense_categories ec LEFT JOIN vendors v ON v.id = ec.vendor_id ORDER BY ec.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$incomeCategories = $pdo->query("SELECT * FROM income_categories ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$paidApplications = $pdo->query("SELECT a.id, a.application_no, a.student_name, a.class_sought, a.payment_amount, a.payment_method, a.payment_status, a.applied_at, p.name AS parent_name, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.payment_status = 'Paid' AND a.deleted_at IS NULL ORDER BY a.applied_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$vendors = $pdo->query("SELECT * FROM vendors ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$bankAccounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
ensure_columns($pdo, 'fee_heads', [
    'class_name' => "VARCHAR(100) DEFAULT NULL",
]);
$feeHeads = $pdo->query("SELECT * FROM fee_heads ORDER BY category, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// ─── Edit records ───
$editRecord = null;
$editType = '';
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $editType = $tab;
    switch ($tab) {
        case 'expense-categories':
            $stmt = $pdo->prepare("SELECT * FROM expense_categories WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'income-categories':
            $stmt = $pdo->prepare("SELECT * FROM income_categories WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'vendors':
            $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'bank-accounts':
            $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'fees-management':
            $stmt = $pdo->prepare("SELECT * FROM fee_heads WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Masters – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .layout-split{display:grid;grid-template-columns:220px 1fr;gap:1.25rem;align-items:start}
        .list-panel{position:sticky;top:1.5rem;max-height:calc(100vh - 3rem);overflow-y:auto}
        .detail-panel{min-height:400px}
        .master-nav{display:flex;flex-direction:column;gap:2px}
        .master-nav a{display:flex;align-items:center;gap:.5rem;padding:.55rem .85rem;font-size:.85rem;font-weight:500;color:#64748b;text-decoration:none;border-radius:6px;transition:all .12s ease;border:1px solid transparent}
        .master-nav a:hover{background:#f1f5f9;color:#1e293b}
        .master-nav a.active{background:#eff6ff;color:#2563eb;border-color:#bfdbfe;font-weight:600}
        .master-nav a .nav-icon{width:18px;text-align:center;font-size:.9rem}
        .app-table{width:100%;border-collapse:collapse;font-size:.875rem;}
        .app-table th{text-align:left;padding:.65rem .5rem;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;white-space:nowrap;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;}
        .app-table td{padding:.65rem .5rem;border-bottom:1px solid #e2e8f0;vertical-align:middle;}
        .app-table tr:hover td{background:#f8fafc;}
        .badge{display:inline-block;padding:.15rem .5rem;border-radius:4px;font-size:.75rem;font-weight:600;}
        .badge-yes{background:#d1fae5;color:#065f46;}
        .badge-no{background:#fee2e2;color:#991b1b;}
        .badge-active{background:#d1fae5;color:#065f46;}
        .badge-inactive{background:#fee2e2;color:#991b1b;}
        .badge-open{background:#d1fae5;color:#065f46;}
        .badge-closed{background:#e2e8f0;color:#475569;}
        .inline-form{display:inline;}
        .action-btns{display:flex;gap:.35rem;}
        .action-btns .btn-icon{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid #e2e8f0;background:#fff;border-radius:6px;cursor:pointer;font-size:.85rem;color:#64748b;transition:all .15s;}
        .action-btns .btn-icon:hover{background:#f1f5f9;color:#2563eb;border-color:#2563eb;}
        .action-btns .btn-icon.btn-del:hover{background:#fee2e2;color:#ef4444;border-color:#ef4444;}
        .coming-soon{text-align:center;padding:3rem 1rem;color:#94a3b8;}
        .coming-soon h3{font-size:1.1rem;color:#475569;margin-bottom:.5rem;}
        .coming-soon p{font-size:.9rem;max-width:400px;margin:0 auto;}
        .flash{background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;}
        .flash-error{background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;}
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
                    <span class="eyebrow">Administration</span>
                    <h1>Masters</h1>
                    <p>Manage foundational master data for the ERP system.</p>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="flash"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="layout-split">
            <!-- LEFT: Master Categories Nav -->
            <div class="list-panel">
                <div class="panel" style="padding:1rem;">
                    <div class="section-title" style="margin-bottom:.75rem;">
                        <div>
                            <h2 style="font-size:1rem;">Master Data</h2>
                            <p style="font-size:.78rem;">Select a category</p>
                        </div>
                    </div>
                    <nav class="master-nav">
                        <?php
                        $masterTabs = [
                            'expense-categories' => ['icon' => '📤', 'label' => 'Expense'],
                            'income-categories' => ['icon' => '📥', 'label' => 'Income'],
                            'vendors' => ['icon' => '🤝', 'label' => 'Vendors Master'],
                            'bank-accounts' => ['icon' => '🏦', 'label' => 'Bank Accounts'],
                            'fees-management' => ['icon' => '💰', 'label' => 'Fees Management'],
                        ];
                        foreach ($masterTabs as $key => $m): ?>
                            <a href="?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>">
                                <span class="nav-icon"><?= $m['icon'] ?></span>
                                <?= $m['label'] ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>

            <!-- RIGHT: Tab Content -->
            <div class="detail-panel">


        <!-- ======================== EXPENSE CATEGORIES ======================== -->
        <?php if ($tab === 'expense-categories'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Expense</h2>
                    <p>Classify expenses by category and group for budgeting and reporting.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('ecModal').classList.add('show')">+ Add Expense</button>
            </div>

            <?php if (empty($expenseCategories)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No expense categories defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Vendor</th>
                            <th>Amount</th>
                            <th>Note</th>
                            <th>Approval Required</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($expenseCategories as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td><?= e($row['vendor_name'] ?? '') ?: '—' ?></td>
                            <td>&#8377; <?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                            <td style="max-width:200px;color:#64748b;"><?= e((string) ($row['description'] ?? '')) ?: '—' ?></td>
                            <td><span class="badge <?= ($row['approval_required'] ?? 0) ? 'badge-yes' : 'badge-no' ?>"><?= ($row['approval_required'] ?? 0) ? 'Yes' : 'No' ?></span></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=expense-categories&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this expense?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_expense_category">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn-icon btn-del" title="Delete">&#128465;</button>
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

        <div id="ecModal" class="modal-backdrop <?= ($editRecord && $editType === 'expense-categories') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'expense-categories') ? 'Edit Expense' : 'Add Expense' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'expense-categories') ? 'update_expense_category' : 'create_expense_category' ?>">
                    <?php if ($editRecord && $editType === 'expense-categories'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Name *</label>
                            <input name="name" type="text" required value="<?= e($editRecord['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Vendor</label>
                            <select name="vendor_id">
                                <option value="">— Select Vendor —</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= (int) $v['id'] ?>" <?= (isset($editRecord['vendor_id']) && (int) $editRecord['vendor_id'] === (int) $v['id']) ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Amount</label>
                            <input name="amount" type="number" step="0.01" min="0" value="<?= e((string) ($editRecord['amount'] ?? '0')) ?>">
                        </div>
                        <div class="full-col">
                            <label>Note</label>
                            <textarea name="description" rows="2"><?= e($editRecord['description'] ?? '') ?></textarea>
                        </div>
                        <div class="full-col" style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="approval_required" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['approval_required'] ?? 0) ? 'checked' : '' ?>>
                                Approval Required
                            </label>
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['is_active'] ?? 1) ? 'checked' : '' ?>>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'expense-categories') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=expense-categories" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== INCOME CATEGORIES ======================== -->
        <?php if ($tab === 'income-categories'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Income</h2>
                    <p>Manual income entries and automatic application fee income.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('icModal').classList.add('show')">+ Add Income</button>
            </div>

            <h3 style="font-size:1rem;color:#64748b;margin:1rem 0 .5rem;">Manual Income</h3>
            <?php if (empty($incomeCategories)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No manual income entries yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Amount</th>
                            <th>Note</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($incomeCategories as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td>&#8377; <?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                            <td style="max-width:200px;color:#64748b;"><?= e((string) ($row['description'] ?? '')) ?: '—' ?></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=income-categories&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this income?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_income_category">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn-icon btn-del" title="Delete">&#128465;</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <h3 style="font-size:1rem;color:#22c55e;margin:1.5rem 0 .5rem;">&#9679; Application Fee Income (Automatic)</h3>
            <?php if (empty($paidApplications)): ?>
                <p style="text-align:center;padding:1.5rem;color:#94a3b8;">No paid applications yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Application No</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Parent</th>
                            <th>Amount</th>
                            <th>Paid On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $j = 1; foreach ($paidApplications as $app): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $j++ ?></td>
                            <td><code style="font-size:.8rem;"><?= e($app['application_no'] ?? '—') ?></code></td>
                            <td><?= e($app['student_name']) ?></td>
                            <td><?= e($app['class_sought']) ?></td>
                            <td><?= e($app['parent_name'] ?? '—') ?><br><small style="color:#94a3b8;"><?= e($app['parent_phone'] ?? '') ?></small></td>
                            <td>&#8377; <?= number_format((float) ($app['payment_amount'] ?? 200), 2) ?></td>
                            <td style="white-space:nowrap;"><?= date('d-m-Y', strtotime($app['applied_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <div id="icModal" class="modal-backdrop <?= ($editRecord && $editType === 'income-categories') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'income-categories') ? 'Edit Income' : 'Add Income' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'income-categories') ? 'update_income_category' : 'create_income_category' ?>">
                    <?php if ($editRecord && $editType === 'income-categories'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Name *</label>
                            <input name="name" type="text" required value="<?= e($editRecord['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Amount</label>
                            <input name="amount" type="number" step="0.01" min="0" value="<?= e((string) ($editRecord['amount'] ?? '0')) ?>">
                        </div>
                        <div class="full-col">
                            <label>Note</label>
                            <textarea name="description" rows="2"><?= e($editRecord['description'] ?? '') ?></textarea>
                        </div>
                        <div class="full-col">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['is_active'] ?? 1) ? 'checked' : '' ?>>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'income-categories') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=income-categories" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== VENDORS ======================== -->
        <?php if ($tab === 'vendors'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Vendors</h2>
                    <p>Manage vendor profiles including contact, GST, PAN, and bank details.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('vendorModal').classList.add('show')">+ Add Vendor</button>
            </div>

            <?php if (empty($vendors)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No vendors defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th>Email</th>
                            <th>GST</th>
                            <th>PAN</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($vendors as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><?= e($row['vendor_code'] ?? '') ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td><?= e($row['mobile'] ?? '') ?></td>
                            <td><?= e($row['email'] ?? '') ?></td>
                            <td><?= e($row['gst_number'] ?? '') ?></td>
                            <td><?= e($row['pan'] ?? '') ?></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=vendors&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this vendor?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_vendor">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn-icon btn-del" title="Delete">&#128465;</button>
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

        <div id="vendorModal" class="modal-backdrop <?= ($editRecord && $editType === 'vendors') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'vendors') ? 'Edit Vendor' : 'Add Vendor' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'vendors') ? 'update_vendor' : 'create_vendor' ?>">
                    <?php if ($editRecord && $editType === 'vendors'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Vendor Code</label>
                            <input name="vendor_code" type="text" value="<?= e($editRecord['vendor_code'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Name *</label>
                            <input name="name" type="text" required value="<?= e($editRecord['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Mobile</label>
                            <input name="mobile" type="text" value="<?= e($editRecord['mobile'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Email</label>
                            <input name="email" type="email" value="<?= e($editRecord['email'] ?? '') ?>">
                        </div>
                        <div>
                            <label>GST Number</label>
                            <input name="gst_number" type="text" value="<?= e($editRecord['gst_number'] ?? '') ?>">
                        </div>
                        <div>
                            <label>PAN</label>
                            <input name="pan" type="text" value="<?= e($editRecord['pan'] ?? '') ?>">
                        </div>
                        <div class="full-col">
                            <label>Address</label>
                            <textarea name="address" rows="2"><?= e($editRecord['address'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label>Bank Name</label>
                            <input name="bank_name" type="text" value="<?= e($editRecord['bank_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Account Number</label>
                            <input name="account_number" type="text" value="<?= e($editRecord['account_number'] ?? '') ?>">
                        </div>
                        <div>
                            <label>IFSC Code</label>
                            <input name="ifsc_code" type="text" value="<?= e($editRecord['ifsc_code'] ?? '') ?>">
                        </div>
                        <div class="full-col">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['is_active'] ?? 1) ? 'checked' : '' ?>>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'vendors') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=vendors" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== BANK ACCOUNTS ======================== -->
        <?php if ($tab === 'bank-accounts'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Bank Accounts</h2>
                    <p>Manage school bank accounts with opening and current balances.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('baModal').classList.add('show')">+ Add Bank Account</button>
            </div>

            <?php if (empty($bankAccounts)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No bank accounts defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Bank Name</th>
                            <th>Account Name</th>
                            <th>Account Number</th>
                            <th>IFSC</th>
                            <th>Branch</th>
                            <th>Type</th>
                            <th>Opening Balance</th>
                            <th>Current Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($bankAccounts as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['bank_name']) ?></strong></td>
                            <td><?= e($row['account_name']) ?></td>
                            <td><?= e($row['account_number']) ?></td>
                            <td><?= e($row['ifsc_code'] ?? '') ?></td>
                            <td><?= e($row['branch'] ?? '') ?></td>
                            <td><span class="badge" style="background:#e0e7ff;color:#3730a3;"><?= e($row['account_type'] ?? 'Savings') ?></span></td>
                            <td>Rs. <?= number_format((float) ($row['opening_balance'] ?? 0), 2) ?></td>
                            <td>Rs. <?= number_format((float) ($row['current_balance'] ?? 0), 2) ?></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=bank-accounts&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this bank account?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_bank_account">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn-icon btn-del" title="Delete">&#128465;</button>
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

        <div id="baModal" class="modal-backdrop <?= ($editRecord && $editType === 'bank-accounts') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'bank-accounts') ? 'Edit Bank Account' : 'Add Bank Account' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'bank-accounts') ? 'update_bank_account' : 'create_bank_account' ?>">
                    <?php if ($editRecord && $editType === 'bank-accounts'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Bank Name *</label>
                            <input name="bank_name" type="text" required value="<?= e($editRecord['bank_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Account Name *</label>
                            <input name="account_name" type="text" required value="<?= e($editRecord['account_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Account Number *</label>
                            <input name="account_number" type="text" required value="<?= e($editRecord['account_number'] ?? '') ?>">
                        </div>
                        <div>
                            <label>IFSC Code</label>
                            <input name="ifsc_code" type="text" value="<?= e($editRecord['ifsc_code'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Branch</label>
                            <input name="branch" type="text" value="<?= e($editRecord['branch'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Account Type</label>
                            <select name="account_type">
                                <option value="Savings" <?= ($editRecord['account_type'] ?? '') === 'Savings' ? 'selected' : '' ?>>Savings</option>
                                <option value="Current" <?= ($editRecord['account_type'] ?? '') === 'Current' ? 'selected' : '' ?>>Current</option>
                            </select>
                        </div>
                        <div>
                            <label>Opening Balance (Rs.)</label>
                            <input name="opening_balance" type="number" step="0.01" min="0" value="<?= e((string) ($editRecord['opening_balance'] ?? '0')) ?>">
                        </div>
                        <div class="full-col">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['is_active'] ?? 1) ? 'checked' : '' ?>>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'bank-accounts') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=bank-accounts" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== FEES MANAGEMENT ======================== -->
        <?php if ($tab === 'fees-management'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Fees Management</h2>
                    <p>Define fee heads for classes, applications, donations, and other charges with tenure settings.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('fhModal').classList.add('show')">+ Add Fee</button>
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
                            <th>Category</th>
                            <th>Class</th>
                            <th>Amount</th>
                            <th>Tenure</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($feeHeads as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td><span class="badge" style="background:#e0f2fe;color:#0369a1;"><?= e($row['category'] ?: '—') ?></span></td>
                            <td><?= e($row['class_name'] ?? '') ?: '—' ?></td>
                            <td>&#8377; <?= number_format((float) ($row['default_amount'] ?? 0), 2) ?></td>
                            <td><span class="badge" style="background:#fef3c7;color:#92400e;"><?= e($row['frequency'] ?? 'One-Time') ?></span></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=fees-management&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this fee head?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_fee_head">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn-icon btn-del" title="Delete">&#128465;</button>
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

        <div id="fhModal" class="modal-backdrop <?= ($editRecord && $editType === 'fees-management') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'fees-management') ? 'Edit Fee' : 'Add Fee' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'fees-management') ? 'update_fee_head' : 'create_fee_head' ?>">
                    <?php if ($editRecord && $editType === 'fees-management'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Name *</label>
                            <input name="name" type="text" required value="<?= e($editRecord['name'] ?? '') ?>" placeholder="e.g. Tuition Fee, Admission Fee">
                        </div>
                        <div>
                            <label>Category</label>
                            <select name="category">
                                <?php
                                $categories = ['Class Fee', 'Application Fee', 'Admission Fee', 'Donation', 'Exam Fee', 'Transport Fee', 'Hostel Fee', 'Other'];
                                foreach ($categories as $cat): ?>
                                    <option value="<?= $cat ?>" <?= (isset($editRecord['category']) && $editRecord['category'] === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Class (if applicable)</label>
                            <select name="class_name">
                                <option value="">— All / Not class-specific —</option>
                                <?php
                                $classes = ['Nursery', 'LKG', 'UKG', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'];
                                foreach ($classes as $cls): ?>
                                    <option value="<?= $cls ?>" <?= (isset($editRecord['class_name']) && $editRecord['class_name'] === $cls) ? 'selected' : '' ?>><?= $cls ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Amount</label>
                            <input name="default_amount" type="number" step="0.01" min="0" value="<?= e((string) ($editRecord['default_amount'] ?? '0')) ?>">
                        </div>
                        <div>
                            <label>Tenure</label>
                            <select name="frequency">
                                <?php
                                $frequencies = ['One-Time' => 'One-Time', 'Monthly' => 'Monthly', 'Quarterly' => 'Quarterly', 'Annual' => 'Yearly'];
                                foreach ($frequencies as $freqValue => $freqLabel): ?>
                                    <option value="<?= $freqValue ?>" <?= (isset($editRecord['frequency']) && $editRecord['frequency'] === $freqValue) ? 'selected' : '' ?>><?= $freqLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="full-col">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['is_active'] ?? 1) ? 'checked' : '' ?>>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'fees-management') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=fees-management" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

            </div><!-- /.detail-panel -->
        </div><!-- /.layout-split -->

    </main>
</div>

<script>
function closeModal(backdrop) {
    if (backdrop) {
        backdrop.classList.remove('show');
    }
    var url = new URL(window.location.href);
    url.searchParams.delete('edit');
    history.replaceState(null, '', url.toString());
}

document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
    backdrop.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal(this);
        }
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.show').forEach(function(backdrop) {
            closeModal(backdrop);
        });
    }
});
</script>
</body>
</html>
