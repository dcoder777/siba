<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$pageTitle = 'Masters';
$error = '';
$success = '';

$validTabs = [
    'financial-years', 'academic-years', 'fee-heads',
    'expense-categories', 'income-categories', 'vendors', 'bank-accounts',
    'asset-categories', 'inventory-items', 'transport-fee'
];

$tab = trim((string) ($_GET['tab'] ?? 'schools'));
if (!in_array($tab, $validTabs, true)) {
    $tab = 'financial-years';
}

// ─── Handle POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['master_action'] ?? ''));

    try {
        // ─── Financial Years ───
        if ($action === 'create_financial_year' || $action === 'update_financial_year') {
            $id = (int) ($_POST['id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $status = in_array(trim((string) ($_POST['status'] ?? '')), ['Open', 'Closed'], true) ? trim((string) $_POST['status']) : 'Open';
            if ($label === '' || $startDate === '' || $endDate === '') {
                $error = 'Label, start date and end date are required.';
            } else {
                if ($action === 'update_financial_year' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE financial_years SET label=?, start_date=?, end_date=?, status=? WHERE id=?");
                    $stmt->execute([$label, $startDate, $endDate, $status, $id]);
                    $success = 'Financial year updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO financial_years (label, start_date, end_date, status) VALUES (?,?,?,?)");
                    $stmt->execute([$label, $startDate, $endDate, $status]);
                    $success = 'Financial year created.';
                }
                header("Location: masters.php?tab=financial-years");
                exit;
            }
        }
        if ($action === 'delete_financial_year') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM financial_years WHERE id=?")->execute([$id]);
                $success = 'Financial year deleted.';
                header("Location: masters.php?tab=financial-years");
                exit;
            }
        }

        // ─── Academic Years ───
        if ($action === 'create_academic_year' || $action === 'update_academic_year') {
            $id = (int) ($_POST['id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $status = in_array(trim((string) ($_POST['status'] ?? '')), ['Active', 'Closed'], true) ? trim((string) $_POST['status']) : 'Active';
            if ($label === '' || $startDate === '' || $endDate === '') {
                $error = 'Label, start date and end date are required.';
            } else {
                if ($action === 'update_academic_year' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE academic_years SET label=?, start_date=?, end_date=?, status=? WHERE id=?");
                    $stmt->execute([$label, $startDate, $endDate, $status, $id]);
                    $success = 'Academic year updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO academic_years (label, start_date, end_date, status) VALUES (?,?,?,?)");
                    $stmt->execute([$label, $startDate, $endDate, $status]);
                    $success = 'Academic year created.';
                }
                header("Location: masters.php?tab=academic-years");
                exit;
            }
        }
        if ($action === 'delete_academic_year') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM academic_years WHERE id=?")->execute([$id]);
                $success = 'Academic year deleted.';
                header("Location: masters.php?tab=academic-years");
                exit;
            }
        }

        // ─── Fee Heads ───
        if ($action === 'create_fee_head' || $action === 'update_fee_head') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $defaultAmount = (float) ($_POST['default_amount'] ?? 0);
            $frequency = in_array(trim((string) ($_POST['frequency'] ?? '')), ['Monthly', 'Annual', 'One-Time', 'Quarterly'], true) ? trim((string) $_POST['frequency']) : 'Monthly';
            $isRefundable = isset($_POST['is_refundable']) ? 1 : 0;
            $lateFeeApplicable = isset($_POST['late_fee_applicable']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            if ($name === '') {
                $error = 'Fee head name is required.';
            } else {
                if ($action === 'update_fee_head' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE fee_heads SET name=?, category=?, default_amount=?, frequency=?, is_refundable=?, late_fee_applicable=?, is_active=?, sort_order=? WHERE id=?");
                    $stmt->execute([$name, $category, $defaultAmount, $frequency, $isRefundable, $lateFeeApplicable, $isActive, $sortOrder, $id]);
                    $success = 'Fee head updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO fee_heads (name, category, default_amount, frequency, is_refundable, late_fee_applicable, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([$name, $category, $defaultAmount, $frequency, $isRefundable, $lateFeeApplicable, $isActive, $sortOrder]);
                    $success = 'Fee head created.';
                }
                header("Location: masters.php?tab=fee-heads");
                exit;
            }
        }
        if ($action === 'delete_fee_head') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM fee_heads WHERE id=?")->execute([$id]);
                $success = 'Fee head deleted.';
                header("Location: masters.php?tab=fee-heads");
                exit;
            }
        }

        // ─── Expense Categories ───
        if ($action === 'create_expense_category' || $action === 'update_expense_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $groupName = trim((string) ($_POST['group_name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $approvalRequired = isset($_POST['approval_required']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                $error = 'Category name is required.';
            } else {
                if ($action === 'update_expense_category' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE expense_categories SET name=?, group_name=?, description=?, approval_required=?, is_active=? WHERE id=?");
                    $stmt->execute([$name, $groupName, $description, $approvalRequired, $isActive, $id]);
                    $success = 'Expense category updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO expense_categories (name, group_name, description, approval_required, is_active) VALUES (?,?,?,?,?)");
                    $stmt->execute([$name, $groupName, $description, $approvalRequired, $isActive]);
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

        // ─── Income Categories ───
        if ($action === 'create_income_category' || $action === 'update_income_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                $error = 'Category name is required.';
            } else {
                if ($action === 'update_income_category' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE income_categories SET name=?, description=?, is_active=? WHERE id=?");
                    $stmt->execute([$name, $description, $isActive, $id]);
                    $success = 'Income category updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO income_categories (name, description, is_active) VALUES (?,?,?)");
                    $stmt->execute([$name, $description, $isActive]);
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

        // ─── Asset Categories ───
        if ($action === 'create_asset_category' || $action === 'update_asset_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $defaultDepreciationRate = (float) ($_POST['default_depreciation_rate'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                $error = 'Category name is required.';
            } else {
                if ($action === 'update_asset_category' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE asset_categories SET name=?, description=?, default_depreciation_rate=?, is_active=? WHERE id=?");
                    $stmt->execute([$name, $description, $defaultDepreciationRate, $isActive, $id]);
                    $success = 'Asset category updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO asset_categories (name, description, default_depreciation_rate, is_active) VALUES (?,?,?,?)");
                    $stmt->execute([$name, $description, $defaultDepreciationRate, $isActive]);
                    $success = 'Asset category created.';
                }
                header("Location: masters.php?tab=asset-categories");
                exit;
            }
        }
        if ($action === 'delete_asset_category') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM asset_categories WHERE id=?")->execute([$id]);
                $success = 'Asset category deleted.';
                header("Location: masters.php?tab=asset-categories");
                exit;
            }
        }

    } catch (Throwable $e) {
        $error = 'Operation failed: ' . $e->getMessage();
    }
}

// ─── Fetch data for each tab ───
$schools = [];
$financialYears = $pdo->query("SELECT * FROM financial_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$academicYears = $pdo->query("SELECT * FROM academic_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$feeHeads = $pdo->query("SELECT * FROM fee_heads ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$expenseCategories = $pdo->query("SELECT * FROM expense_categories ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$incomeCategories = $pdo->query("SELECT * FROM income_categories ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$vendors = $pdo->query("SELECT * FROM vendors ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$bankAccounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$assetCategories = $pdo->query("SELECT * FROM asset_categories ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// ─── Edit records ───
$editRecord = null;
$editType = '';
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $editType = $tab;
    switch ($tab) {
        case 'financial-years':
            $stmt = $pdo->prepare("SELECT * FROM financial_years WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'academic-years':
            $stmt = $pdo->prepare("SELECT * FROM academic_years WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
        case 'fee-heads':
            $stmt = $pdo->prepare("SELECT * FROM fee_heads WHERE id=?");
            $stmt->execute([$editId]);
            $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
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
        case 'asset-categories':
            $stmt = $pdo->prepare("SELECT * FROM asset_categories WHERE id=?");
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
                    <p>Manage all foundational master data for the ERP system — schools, financial years, fee heads, vendors, and more.</p>
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
                            'financial-years' => ['icon' => '📅', 'label' => 'Financial Years'],
                            'academic-years' => ['icon' => '🎓', 'label' => 'Academic Years'],
                            'fee-heads' => ['icon' => '💰', 'label' => 'Fee Heads'],
                            'expense-categories' => ['icon' => '📤', 'label' => 'Expense Categories'],
                            'income-categories' => ['icon' => '📥', 'label' => 'Income Categories'],
                            'vendors' => ['icon' => '🤝', 'label' => 'Vendors'],
                            'bank-accounts' => ['icon' => '🏦', 'label' => 'Bank Accounts'],
                            'asset-categories' => ['icon' => '📦', 'label' => 'Asset Categories'],
                            'inventory-items' => ['icon' => '📦', 'label' => 'Inventory'],
                            'transport-fee' => ['icon' => '🚌', 'label' => 'Transport Fee'],
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

        <!-- ======================== FINANCIAL YEARS ======================== -->
        <?php if ($tab === 'financial-years'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Financial Years</h2>
                    <p>Define financial year periods for accounting and reporting.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('fyModal').classList.add('show')">+ Add Financial Year</button>
            </div>

            <?php if (empty($financialYears)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No financial years defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Label</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($financialYears as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['label']) ?></strong></td>
                            <td><?= e($row['start_date']) ?></td>
                            <td><?= e($row['end_date']) ?></td>
                            <td><span class="badge <?= ($row['status'] ?? '') === 'Open' ? 'badge-open' : 'badge-closed' ?>"><?= e($row['status'] ?? '') ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=financial-years&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this financial year?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_financial_year">
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

        <div id="fyModal" class="modal-backdrop <?= ($editRecord && $editType === 'financial-years') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'financial-years') ? 'Edit Financial Year' : 'Add Financial Year' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'financial-years') ? 'update_financial_year' : 'create_financial_year' ?>">
                    <?php if ($editRecord && $editType === 'financial-years'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Label *</label>
                            <input name="label" type="text" required value="<?= e($editRecord['label'] ?? '') ?>" placeholder="e.g. FY 2025-26">
                        </div>
                        <div>
                            <label>Start Date *</label>
                            <input name="start_date" type="date" required value="<?= e($editRecord['start_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label>End Date *</label>
                            <input name="end_date" type="date" required value="<?= e($editRecord['end_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Status</label>
                            <select name="status">
                                <option value="Open" <?= ($editRecord['status'] ?? '') === 'Open' ? 'selected' : '' ?>>Open</option>
                                <option value="Closed" <?= ($editRecord['status'] ?? '') === 'Closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'financial-years') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=financial-years" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== ACADEMIC YEARS ======================== -->
        <?php if ($tab === 'academic-years'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Academic Years</h2>
                    <p>Define academic sessions for student enrollments and fee structures.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('ayModal').classList.add('show')">+ Add Academic Year</button>
            </div>

            <?php if (empty($academicYears)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No academic years defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Label</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($academicYears as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['label']) ?></strong></td>
                            <td><?= e($row['start_date']) ?></td>
                            <td><?= e($row['end_date']) ?></td>
                            <td><span class="badge <?= ($row['status'] ?? '') === 'Active' ? 'badge-active' : 'badge-closed' ?>"><?= e($row['status'] ?? '') ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=academic-years&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this academic year?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_academic_year">
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

        <div id="ayModal" class="modal-backdrop <?= ($editRecord && $editType === 'academic-years') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'academic-years') ? 'Edit Academic Year' : 'Add Academic Year' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'academic-years') ? 'update_academic_year' : 'create_academic_year' ?>">
                    <?php if ($editRecord && $editType === 'academic-years'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Label *</label>
                            <input name="label" type="text" required value="<?= e($editRecord['label'] ?? '') ?>" placeholder="e.g. 2025-26">
                        </div>
                        <div>
                            <label>Start Date *</label>
                            <input name="start_date" type="date" required value="<?= e($editRecord['start_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label>End Date *</label>
                            <input name="end_date" type="date" required value="<?= e($editRecord['end_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Status</label>
                            <select name="status">
                                <option value="Active" <?= ($editRecord['status'] ?? '') === 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Closed" <?= ($editRecord['status'] ?? '') === 'Closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'academic-years') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=academic-years" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== FEE HEADS ======================== -->
        <?php if ($tab === 'fee-heads'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Fee Heads</h2>
                    <p>Define fee components used across fee structures — tuition, transport, hostel, etc.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('fhModal').classList.add('show')">+ Add Fee Head</button>
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
                            <th>Default Amount</th>
                            <th>Frequency</th>
                            <th>Refundable</th>
                            <th>Late Fee</th>
                            <th>Status</th>
                            <th>Sort</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($feeHeads as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td><?= e($row['category'] ?? '—') ?></td>
                            <td>Rs. <?= number_format((float) ($row['default_amount'] ?? 0), 2) ?></td>
                            <td><span class="badge" style="background:#e0e7ff;color:#3730a3;"><?= e($row['frequency'] ?? 'Monthly') ?></span></td>
                            <td><span class="badge <?= ($row['is_refundable'] ?? 0) ? 'badge-yes' : 'badge-no' ?>"><?= ($row['is_refundable'] ?? 0) ? 'Yes' : 'No' ?></span></td>
                            <td><span class="badge <?= ($row['late_fee_applicable'] ?? 0) ? 'badge-yes' : 'badge-no' ?>"><?= ($row['late_fee_applicable'] ?? 0) ? 'Yes' : 'No' ?></span></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td><?= (int) ($row['sort_order'] ?? 0) ?></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=fee-heads&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
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

        <div id="fhModal" class="modal-backdrop <?= ($editRecord && $editType === 'fee-heads') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'fee-heads') ? 'Edit Fee Head' : 'Add Fee Head' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'fee-heads') ? 'update_fee_head' : 'create_fee_head' ?>">
                    <?php if ($editRecord && $editType === 'fee-heads'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Name *</label>
                            <input name="name" type="text" required value="<?= e($editRecord['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Category</label>
                            <input name="category" type="text" value="<?= e($editRecord['category'] ?? '') ?>" placeholder="e.g. Tuition, Transport">
                        </div>
                        <div>
                            <label>Default Amount (Rs.)</label>
                            <input name="default_amount" type="number" step="0.01" min="0" value="<?= e((string) ($editRecord['default_amount'] ?? '0')) ?>">
                        </div>
                        <div>
                            <label>Frequency</label>
                            <select name="frequency">
                                <option value="Monthly" <?= ($editRecord['frequency'] ?? '') === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="Annual" <?= ($editRecord['frequency'] ?? '') === 'Annual' ? 'selected' : '' ?>>Annual</option>
                                <option value="One-Time" <?= ($editRecord['frequency'] ?? '') === 'One-Time' ? 'selected' : '' ?>>One-Time</option>
                                <option value="Quarterly" <?= ($editRecord['frequency'] ?? '') === 'Quarterly' ? 'selected' : '' ?>>Quarterly</option>
                            </select>
                        </div>
                        <div>
                            <label>Sort Order</label>
                            <input name="sort_order" type="number" value="<?= (int) ($editRecord['sort_order'] ?? 0) ?>">
                        </div>
                        <div class="full-col" style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_refundable" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['is_refundable'] ?? 0) ? 'checked' : '' ?>>
                                Refundable
                            </label>
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="late_fee_applicable" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['late_fee_applicable'] ?? 0) ? 'checked' : '' ?>>
                                Late Fee Applicable
                            </label>
                            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:400;margin-bottom:0;">
                                <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;accent-color:#2563eb;" <?= ($editRecord['is_active'] ?? 1) ? 'checked' : '' ?>>
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'fee-heads') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=fee-heads" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== EXPENSE CATEGORIES ======================== -->
        <?php if ($tab === 'expense-categories'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Expense Categories</h2>
                    <p>Classify expenses by category and group for budgeting and reporting.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('ecModal').classList.add('show')">+ Add Category</button>
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
                            <th>Group</th>
                            <th>Description</th>
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
                            <td><?= e($row['group_name'] ?? '—') ?></td>
                            <td style="max-width:200px;color:#64748b;"><?= e((string) ($row['description'] ?? '')) ?: '—' ?></td>
                            <td><span class="badge <?= ($row['approval_required'] ?? 0) ? 'badge-yes' : 'badge-no' ?>"><?= ($row['approval_required'] ?? 0) ? 'Yes' : 'No' ?></span></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=expense-categories&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this expense category?')">
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
                    <h2><?= ($editRecord && $editType === 'expense-categories') ? 'Edit Expense Category' : 'Add Expense Category' ?></h2>
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
                            <label>Group Name</label>
                            <input name="group_name" type="text" value="<?= e($editRecord['group_name'] ?? '') ?>" placeholder="e.g. Administrative, Academic">
                        </div>
                        <div class="full-col">
                            <label>Description</label>
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
                    <h2>Income Categories</h2>
                    <p>Classify income sources for tracking and financial reporting.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('icModal').classList.add('show')">+ Add Category</button>
            </div>

            <?php if (empty($incomeCategories)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No income categories defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($incomeCategories as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td style="max-width:300px;color:#64748b;"><?= e((string) ($row['description'] ?? '')) ?: '—' ?></td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=income-categories&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this income category?')">
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
        </section>

        <div id="icModal" class="modal-backdrop <?= ($editRecord && $editType === 'income-categories') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'income-categories') ? 'Edit Income Category' : 'Add Income Category' ?></h2>
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
                        <div class="full-col">
                            <label>Description</label>
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

        <!-- ======================== ASSET CATEGORIES ======================== -->
        <?php if ($tab === 'asset-categories'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Asset Categories</h2>
                    <p>Classify fixed assets and set default depreciation rates.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('acModal').classList.add('show')">+ Add Category</button>
            </div>

            <?php if (empty($assetCategories)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No asset categories defined yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Depreciation Rate (%)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($assetCategories as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td style="max-width:300px;color:#64748b;"><?= e((string) ($row['description'] ?? '')) ?: '—' ?></td>
                            <td><?= number_format((float) ($row['default_depreciation_rate'] ?? 0), 2) ?>%</td>
                            <td><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=asset-categories&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this asset category?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_asset_category">
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

        <div id="acModal" class="modal-backdrop <?= ($editRecord && $editType === 'asset-categories') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2><?= ($editRecord && $editType === 'asset-categories') ? 'Edit Asset Category' : 'Add Asset Category' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'asset-categories') ? 'update_asset_category' : 'create_asset_category' ?>">
                    <?php if ($editRecord && $editType === 'asset-categories'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="field-grid">
                        <div>
                            <label>Name *</label>
                            <input name="name" type="text" required value="<?= e($editRecord['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Default Depreciation Rate (%)</label>
                            <input name="default_depreciation_rate" type="number" step="0.01" min="0" max="100" value="<?= e((string) ($editRecord['default_depreciation_rate'] ?? '0')) ?>">
                        </div>
                        <div class="full-col">
                            <label>Description</label>
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
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'asset-categories') ? 'Update' : 'Add' ?></button>
                        <a href="?tab=asset-categories" class="btn btn-soft">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ======================== INVENTORY ITEMS (Coming Soon) ======================== -->
        <?php if ($tab === 'inventory-items'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Inventory Items</h2>
                    <p>Manage stock items, categories, and inventory tracking.</p>
                </div>
            </div>
            <div class="coming-soon">
                <h3>Inventory Management</h3>
                <p>Complete inventory management with stock tracking, item categories, and procurement workflows will be available from the Inventory page.</p>
                <a href="inventory.php" class="btn" style="margin-top:1rem;">Go to Inventory</a>
            </div>
        </section>
        <?php endif; ?>

        <!-- ======================== TRANSPORT FEE (Coming Soon) ======================== -->
        <?php if ($tab === 'transport-fee'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Transport Fee Masters</h2>
                    <p>Manage transport fee structures, route-wise charges, and pickup/drop point pricing.</p>
                </div>
            </div>
            <div class="coming-soon">
                <h3>Transport Accounts</h3>
                <p>Transport fee management with route-wise billing, distance-based pricing, and transport-specific fee allocation will be available from the Transport Accounts page.</p>
                <a href="transport-accounts.php" class="btn" style="margin-top:1rem;">Go to Transport Accounts</a>
            </div>
        </section>
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
