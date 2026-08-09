<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/application_fee.php';
require_admin_login();

function generate_expense_no(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $next = (int) $stmt->fetchColumn() + 1;
    return 'EXP-' . $year . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

function generate_income_no(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM income_categories WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $next = (int) $stmt->fetchColumn() + 1;
    return 'INC-' . $year . '-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
}

function render_pagination(int $currentPage, int $totalPages, int $totalRecords, int $perPage, int $offset, string $paramKey): string
{
    if ($totalPages <= 1) return '';
    $html = '<div style="margin-top:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">';
    $html .= '<div style="font-size:.85rem;color:#64748b;">Showing ' . ($offset + 1) . '–' . min($offset + $perPage, $totalRecords) . ' of ' . $totalRecords . '</div>';
    $html .= '<div class="page-links">';
    $params = $_GET;
    if ($currentPage > 1) {
        $params[$paramKey] = $currentPage - 1;
        $html .= '<a href="?' . http_build_query($params) . '">← Prev</a>';
    }
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    for ($p = $start; $p <= $end; $p++) {
        $params[$paramKey] = $p;
        $cls = $p === $currentPage ? ' class="active"' : '';
        $html .= '<a href="?' . http_build_query($params) . '"' . $cls . '>' . $p . '</a>';
    }
    if ($currentPage < $totalPages) {
        $params[$paramKey] = $currentPage + 1;
        $html .= '<a href="?' . http_build_query($params) . '">Next →</a>';
    }
    $html .= '</div></div>';
    return $html;
}

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
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
        if ($action === 'create_expense' || $action === 'update_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            $expenseDate = trim((string) ($_POST['expense_date'] ?? date('Y-m-d')));
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $vendorId = (int) ($_POST['vendor_id'] ?? 0);
            $vendorName = trim((string) ($_POST['vendor_name'] ?? ''));
            $billNo = trim((string) ($_POST['bill_no'] ?? ''));
            $billDate = trim((string) ($_POST['bill_date'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $gstAmount = (float) ($_POST['gst_amount'] ?? 0);
            $netAmount = (float) ($_POST['net_amount'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));
            $paymentMode = trim((string) ($_POST['payment_mode'] ?? ''));
            $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
            $paymentId = trim((string) ($_POST['payment_id'] ?? ''));
            $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
            $payeeName = trim((string) ($_POST['payee_name'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? 'Pending'));
            $validStatuses = ['Pending', 'Approved', 'Rejected', 'Cancelled'];
            $status = in_array($status, $validStatuses, true) ? $status : 'Pending';

            if ($expenseDate === '') {
                $error = 'Expense date is required.';
            } else {
                $catName = '';
                if ($categoryId > 0) {
                    $catRow = $pdo->prepare("SELECT name FROM expense_categories WHERE id=?");
                    $catRow->execute([$categoryId]);
                    $cat = $catRow->fetch();
                    if ($cat) { $catName = (string) $cat['name']; }
                }

                if (!$vendorName && $vendorId > 0) {
                    $vRow = $pdo->prepare("SELECT name FROM vendors WHERE id=?");
                    $vRow->execute([$vendorId]);
                    $v = $vRow->fetch();
                    if ($v) { $vendorName = (string) $v['name']; }
                }

                if ($action === 'update_expense' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE expenses SET expense_date=?, category_id=?, category_name=?, vendor_id=?, vendor_name=?, bill_no=?, bill_date=?, amount=?, gst_amount=?, net_amount=?, description=?, payment_mode=?, payment_date=?, payment_id=?, transaction_id=?, payee_name=?, status=? WHERE id=?");
                    $stmt->execute([$expenseDate, $categoryId > 0 ? $categoryId : null, $catName ?: null, $vendorId > 0 ? $vendorId : null, $vendorName ?: null, $billNo ?: null, $billDate ?: null, $amount, $gstAmount, $netAmount, $description ?: null, $paymentMode ?: null, $paymentDate ?: null, $paymentId ?: null, $transactionId ?: null, $payeeName ?: null, $status, $id]);
                    $success = 'Expense updated.';
                } else {
                    $expenseNo = generate_expense_no($pdo);
                    $createdBy = (int) ($user['id'] ?? 0);
                    $stmt = $pdo->prepare("INSERT INTO expenses (expense_no, expense_date, category_id, category_name, vendor_id, vendor_name, bill_no, bill_date, amount, gst_amount, net_amount, description, payment_mode, payment_date, payment_id, transaction_id, payee_name, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$expenseNo, $expenseDate, $categoryId > 0 ? $categoryId : null, $catName ?: null, $vendorId > 0 ? $vendorId : null, $vendorName ?: null, $billNo ?: null, $billDate ?: null, $amount, $gstAmount, $netAmount, $description ?: null, $paymentMode ?: null, $paymentDate ?: null, $paymentId ?: null, $transactionId ?: null, $payeeName ?: null, $status, $createdBy]);
                    $success = 'Expense created.';
                }
                header("Location: masters.php?tab=expense-categories");
                exit;
            }
        }
        if ($action === 'delete_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
                $success = 'Expense deleted.';
                header("Location: masters.php?tab=expense-categories");
                exit;
            }
        }

        // ─── Income ───
        if ($action === 'create_income_category' || $action === 'update_income_category') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $incomeDate = trim((string) ($_POST['income_date'] ?? ''));
            $paymentMode = trim((string) ($_POST['payment_mode'] ?? ''));
            $paymentId = trim((string) ($_POST['payment_id'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $incStatus = trim((string) ($_POST['status'] ?? 'Pending'));
            if ($name === '') {
                $error = 'Income name is required.';
            } elseif ($amount <= 0) {
                $error = 'Amount must be greater than zero.';
            } else {
                if ($action === 'update_income_category' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE income_categories SET name=?, amount=?, income_date=?, payment_mode=?, payment_id=?, description=?, status=?, is_active=1 WHERE id=?");
                    $stmt->execute([$name, $amount, $incomeDate ?: null, $paymentMode ?: null, $paymentId ?: null, $description ?: null, $incStatus, $id]);
                    $success = 'Income updated.';
                } else {
                    $incomeNo = generate_income_no($pdo);
                    $uid = (int) ($user['id'] ?? 0);
                    $stmt = $pdo->prepare("INSERT INTO income_categories (income_no, income_date, name, description, amount, payment_mode, payment_id, status, is_active, created_by) VALUES (?,?,?,?,?,?,?,'Pending',1,?)");
                    $stmt->execute([$incomeNo, $incomeDate ?: null, $name, $description ?: null, $amount, $paymentMode ?: null, $paymentId ?: null, $uid]);
                    $success = 'Income created.';
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
            if ($category === 'Application Fee') {
                $frequency = 'One-Time';
                $className = '';
            }
            if ($name === '') {
                $error = 'Fee name is required.';
            } elseif ($category === 'Application Fee') {
                $chk = $pdo->prepare("SELECT id FROM fee_heads WHERE category = 'Application Fee' AND id != ? LIMIT 1");
                $chk->execute([$id > 0 ? $id : 0]);
                if ($chk->fetch()) {
                    $error = 'Only one Application Fee entry is allowed. Edit the existing one instead.';
                }
            }
            if ($error === '') {
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
ensure_columns($pdo, 'expenses', [
    'payment_id' => "VARCHAR(150) DEFAULT NULL",
]);
try { $pdo->exec("ALTER TABLE expenses MODIFY category_id INT UNSIGNED NULL"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE expenses MODIFY category_name VARCHAR(100) NULL"); } catch (Throwable) {}
ensure_columns($pdo, 'income_categories', [
    'description' => "TEXT NULL",
    'amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
    'income_no' => "VARCHAR(50) NULL",
    'income_date' => "DATE NULL",
    'payment_mode' => "VARCHAR(50) NULL",
    'payment_id' => "VARCHAR(150) NULL",
    'status' => "VARCHAR(30) DEFAULT 'Pending'",
    'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
    'created_by' => "INT NULL",
    'approved_by' => "INT NULL",
    'approved_at' => "DATETIME NULL",
    'reject_reason' => "TEXT NULL",
]);
ensure_columns($pdo, 'applications', [
    'payment_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 200.00",
]);
$expenseCategories = $pdo->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ─── Pagination helper ───
$mPerPage = 20;
$mTab = $tab;

// Expenses pagination
$mExpPage = max(1, (int) ($_GET['ep'] ?? 1));
$mExpOffset = ($mExpPage - 1) * $mPerPage;
$mExpTotal = (int) $pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
$mExpTotalPages = max(1, (int) ceil($mExpTotal / $mPerPage));
$expenses = $pdo->query("SELECT e.*, ec.name AS category_label, v.name AS vendor_label FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id LEFT JOIN vendors v ON v.id = e.vendor_id ORDER BY e.id DESC LIMIT $mPerPage OFFSET $mExpOffset")->fetchAll(PDO::FETCH_ASSOC);

// Income pagination
$mIncPage = max(1, (int) ($_GET['ip'] ?? 1));
$mIncOffset = ($mIncPage - 1) * $mPerPage;
$mIncTotal = (int) $pdo->query("SELECT COUNT(*) FROM income_categories")->fetchColumn();
$mIncTotalPages = max(1, (int) ceil($mIncTotal / $mPerPage));
$incomeCategories = $pdo->query("SELECT * FROM income_categories ORDER BY COALESCE(income_date, created_at) DESC LIMIT $mPerPage OFFSET $mIncOffset")->fetchAll(PDO::FETCH_ASSOC);
$paidApplications = $pdo->query("SELECT a.id, a.application_no, a.student_name, a.class_sought, a.payment_amount, a.payment_method, a.payment_status, a.applied_at, p.name AS parent_name, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.payment_status = 'Paid' AND a.deleted_at IS NULL ORDER BY a.applied_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Vendors pagination
$mVndPage = max(1, (int) ($_GET['vp'] ?? 1));
$mVndOffset = ($mVndPage - 1) * $mPerPage;
$mVndTotal = (int) $pdo->query("SELECT COUNT(*) FROM vendors")->fetchColumn();
$mVndTotalPages = max(1, (int) ceil($mVndTotal / $mPerPage));
$vendors = $pdo->query("SELECT * FROM vendors ORDER BY id DESC LIMIT $mPerPage OFFSET $mVndOffset")->fetchAll(PDO::FETCH_ASSOC);

// Vendor expenses summary
$vendorExpenses = $pdo->query("SELECT vendor_id, vendor_name, COALESCE(SUM(net_amount),0) AS total_expense, COUNT(*) AS expense_count FROM expenses WHERE vendor_id IS NOT NULL AND status != 'Cancelled' GROUP BY vendor_id, vendor_name ORDER BY total_expense DESC")->fetchAll(PDO::FETCH_ASSOC);
$vendorExpenseMap = [];
foreach ($vendorExpenses as $ve) { $vendorExpenseMap[(int) $ve['vendor_id']] = $ve; }
// Bank accounts pagination
$mBaPage = max(1, (int) ($_GET['bp'] ?? 1));
$mBaOffset = ($mBaPage - 1) * $mPerPage;
$mBaTotal = (int) $pdo->query("SELECT COUNT(*) FROM bank_accounts")->fetchColumn();
$mBaTotalPages = max(1, (int) ceil($mBaTotal / $mPerPage));
$bankAccounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id DESC LIMIT $mPerPage OFFSET $mBaOffset")->fetchAll(PDO::FETCH_ASSOC);
ensure_columns($pdo, 'fee_heads', [
    'class_name' => "VARCHAR(100) DEFAULT NULL",
]);
$feeHeads = $pdo->query("SELECT * FROM fee_heads ORDER BY category, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$hasApplicationFee = false;
foreach ($feeHeads as $fh) {
    if (($fh['category'] ?? '') === 'Application Fee') {
        $hasApplicationFee = true;
        break;
    }
}

// ─── Edit records ───
$editRecord = null;
$editType = '';
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $editType = $tab;
    switch ($tab) {
        case 'expense-categories':
            $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
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
        .app-table{width:100%;border-collapse:collapse;font-size:.8rem;}
        .app-table th{text-align:left;padding:.5rem .4rem;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;white-space:nowrap;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;}
        .app-table td{padding:.45rem .4rem;border-bottom:1px solid #e2e8f0;vertical-align:middle;}
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
        .sortable{cursor:pointer;user-select:none;white-space:nowrap;}
        .sortable:hover{color:#2563eb;}
        .sort-icon{font-size:.7rem;margin-left:3px;opacity:.4;}
        .sortable.sort-asc .sort-icon,.sortable.sort-desc .sort-icon{opacity:1;color:#2563eb;}
        .page-links{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center;}
        .page-links a,.page-links span{min-height:34px;padding:.38rem .65rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;text-decoration:none;font-size:.82rem;}
        .page-links a:hover{background:#f1f5f9;}
        .page-links .active{background:#64748b;border-color:#64748b;color:#fff;}
        .vendor-table-wrap{overflow-x:auto;max-width:100%;}
        .vendor-table-wrap .app-table{min-width:900px;}
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


        <!-- ======================== EXPENSE ======================== -->
        <?php if ($tab === 'expense-categories'): ?>
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title">
                <div>
                    <h2>Expense</h2>
                    <p>Record and manage all expenses with vendor, GST, and payment details.</p>
                </div>
                <button type="button" class="btn btn-sm" onclick="document.getElementById('ecModal').classList.add('show'); document.getElementById('expense-form').reset(); document.getElementById('expense-form').action=''; document.getElementById('ee-net-amount').value='0'; document.getElementById('ecModalTitle').textContent='Add Expense'; document.getElementById('ee-form-action').value='create_expense'; document.getElementById('ee-form-id').value='';">+ Add Expense</button>
            </div>

            <?php if (empty($expenses)): ?>
                <p style="text-align:center;padding:2rem;color:#94a3b8;">No expenses recorded yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="app-table" style="font-size:.8rem;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Exp No</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Vendor</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Note</th>
                            <th>Mode</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($expenses as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td style="font-family:monospace;font-size:.78rem;white-space:nowrap;"><?= e($row['expense_no']) ?></td>
                            <td style="white-space:nowrap;font-size:.8rem;"><?= date('d-m-Y', strtotime($row['expense_date'])) ?></td>
                            <td><span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:.72rem;padding:.1rem .4rem;"><?= e($row['category_label'] ?? $row['category_name'] ?? '—') ?></span></td>
                            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($row['vendor_label'] ?? $row['vendor_name'] ?? '') ?>"><?= e($row['vendor_label'] ?? $row['vendor_name'] ?? '') ?: '—' ?></td>
                            <td style="text-align:right;white-space:nowrap;">&#8377; <?= number_format((float) $row['net_amount'], 2) ?></td>
                            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($row['description'] ?? '') ?>"><?= e($row['description'] ?? '—') ?></td>
                            <td style="white-space:nowrap;"><?= e($row['payment_mode'] ?? '') ?: '—' ?></td>
                            <td><?php
                                $statusColors = ['Pending' => '#fef3c7,#92400e', 'Approved' => '#d1fae5,#065f46', 'Rejected' => '#fee2e2,#991b1b', 'Cancelled' => '#e2e8f0,#475569'];
                                $sc = $statusColors[$row['status']] ?? '#fef3c7,#92400e';
                                [$stBg, $stClr] = explode(',', $sc);
                            ?><span class="badge" style="background:<?= $stBg ?>;color:<?= $stClr ?>;"><?= e($row['status']) ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=expense-categories&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete expense <?= e($row['expense_no']) ?>?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_expense">
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
            <?= render_pagination($mExpPage, $mExpTotalPages, $mExpTotal, $mPerPage, $mExpOffset, 'ep') ?>
            <?php endif; ?>
        </section>

        <div id="ecModal" class="modal-backdrop <?= ($editRecord && $editType === 'expense-categories') ? 'show' : '' ?>">
            <div class="modal">
                <div class="modal-head">
                    <h2 id="ecModalTitle"><?= ($editRecord && $editType === 'expense-categories') ? 'Edit Expense' : 'Add Expense' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))">&times;</button>
                </div>
                <form method="post" id="expense-form" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" id="ee-form-action" value="<?= ($editRecord && $editType === 'expense-categories') ? 'update_expense' : 'create_expense' ?>">
                    <input type="hidden" name="id" id="ee-form-id" value="<?= $editRecord['id'] ?? '' ?>">
                    <div class="field-grid">
                        <div>
                            <label>Expense Date *</label>
                            <input type="date" name="expense_date" required value="<?= e($editRecord['expense_date'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div>
                            <label>Vendor</label>
                            <select name="vendor_id" id="ee-vendor-id" onchange="document.getElementById('ee-vendor-name').value = this.options[this.selectedIndex].text;">
                                <option value="0">-- Select Vendor --</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= (int) $v['id'] ?>" <?= (isset($editRecord['vendor_id']) && (int) $editRecord['vendor_id'] === (int) $v['id']) ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="vendor_name" id="ee-vendor-name" value="<?= e($editRecord['vendor_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Bill Number</label>
                            <input type="text" name="bill_no" value="<?= e($editRecord['bill_no'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Bill Date</label>
                            <input type="date" name="bill_date" value="<?= e($editRecord['bill_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0" name="amount" required value="<?= e((string) ($editRecord['amount'] ?? '0')) ?>" oninput="eeCalcNet()">
                        </div>
                        <div>
                            <label>GST Amount (Rs.)</label>
                            <input type="number" step="0.01" min="0" name="gst_amount" value="<?= e((string) ($editRecord['gst_amount'] ?? '0')) ?>" oninput="eeCalcNet()">
                        </div>
                        <div>
                            <label>Net Amount (Rs.)</label>
                            <input type="number" step="0.01" min="0" name="net_amount" id="ee-net-amount" readonly style="background:#f1f5f9;" value="<?= e((string) ($editRecord['net_amount'] ?? '0')) ?>">
                        </div>
                        <div>
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="ee-payment-mode" onchange="eeToggleTxn()">
                                <option value="">-- Select --</option>
                                <?php foreach (['Cash', 'Cheque', 'UPI', 'Bank Transfer', 'Card', 'Online'] as $pm): ?>
                                    <option value="<?= $pm ?>" <?= (isset($editRecord['payment_mode']) && $editRecord['payment_mode'] === $pm) ? 'selected' : '' ?>><?= $pm ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Payment ID</label>
                            <input type="text" name="payment_id" value="<?= e($editRecord['payment_id'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Payment Date</label>
                            <input type="date" name="payment_date" value="<?= e($editRecord['payment_date'] ?? '') ?>">
                        </div>
                        <div class="ee-txn-field">
                            <label>Transaction ID</label>
                            <input type="text" name="transaction_id" value="<?= e($editRecord['transaction_id'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Payee Name</label>
                            <input type="text" name="payee_name" value="<?= e($editRecord['payee_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Bill Upload</label>
                            <input type="file" name="bill_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                            <?php if (!empty($editRecord['bill_file'])): ?>
                                <small style="color:#64748b;">Current: <?= e($editRecord['bill_file']) ?></small>
                            <?php endif; ?>
                        </div>
                        <?php if ($editRecord && $editType === 'expense-categories'): ?>
                        <div>
                            <label>Status</label>
                            <select name="status">
                                <?php foreach (['Pending', 'Approved', 'Rejected', 'Cancelled'] as $st): ?>
                                    <option value="<?= $st ?>" <?= (isset($editRecord['status']) && $editRecord['status'] === $st) ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="full-col">
                            <label>Description / Note</label>
                            <textarea name="description" rows="2"><?= e($editRecord['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="action-row" style="margin-top:1.5rem;">
                        <button type="submit" class="btn"><?= ($editRecord && $editType === 'expense-categories') ? 'Update' : 'Create Expense' ?></button>
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
                            <th>Income No</th>
                            <th>Date</th>
                            <th>Name</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Mode</th>
                            <th>Note</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($incomeCategories as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td style="font-family:monospace;font-size:.82rem;"><?= e($row['income_no'] ?? '') ?: '—' ?></td>
                            <td style="white-space:nowrap;"><?= $row['income_date'] ? date('d-m-Y', strtotime($row['income_date'])) : '—' ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td style="text-align:right;">&#8377; <?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                            <td><?= e($row['payment_mode'] ?? '') ?: '—' ?></td>
                            <td style="max-width:150px;color:#64748b;"><?= e((string) ($row['description'] ?? '')) ?: '—' ?></td>
                            <td><?php
                                $incStatus = $row['status'] ?? 'Pending';
                                $incStatusColors = ['Pending' => '#fef3c7,#92400e', 'Approved' => '#d1fae5,#065f46', 'Rejected' => '#fee2e2,#991b1b', 'Received' => '#d1fae5,#065f46'];
                                $isc = $incStatusColors[$incStatus] ?? '#fef3c7,#92400e';
                                [$iscBg, $iscClr] = explode(',', $isc);
                            ?><span class="badge" style="background:<?= $iscBg ?>;color:<?= $iscClr ?>;"><?= e($incStatus) ?></span></td>
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
            <?= render_pagination($mIncPage, $mIncTotalPages, $mIncTotal, $mPerPage, $mIncOffset, 'ip') ?>
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
                            <td>&#8377; <?= number_format((float) ($app['payment_amount'] ?? 0) ?: get_application_fee_amount($pdo), 2) ?></td>
                            <td style="white-space:nowrap;"><?= date('d-m-Y', strtotime($app['applied_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <div id="icModal" class="modal-backdrop <?= ($editRecord && $editType === 'income-categories') ? 'show' : '' ?>">
            <div class="modal" style="max-width:640px;border-radius:14px;padding:0;overflow:auto;">
                <div class="modal-head" style="padding:1.1rem 1.4rem;border-bottom:1px solid #e2e8f0;">
                    <h2 style="font-size:1.15rem;font-weight:700;margin:0;"><?= ($editRecord && $editType === 'income-categories') ? 'Edit Income' : 'Add Income' ?></h2>
                    <button type="button" class="icon-btn" onclick="closeModal(this.closest('.modal-backdrop'))" style="font-size:1.3rem;color:#94a3b8;">&times;</button>
                </div>
                <form method="post" style="padding:1.25rem 1.4rem 1.4rem;">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="master_action" value="<?= ($editRecord && $editType === 'income-categories') ? 'update_income_category' : 'create_income_category' ?>">
                    <?php if ($editRecord && $editType === 'income-categories'): ?>
                        <input type="hidden" name="id" value="<?= (int) $editRecord['id'] ?>">
                    <?php endif; ?>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem 1.25rem;">
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.875rem;color:#334155;">Income Date *</label>
                            <input type="date" name="income_date" required value="<?= e($editRecord['income_date'] ?? '') ?>" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.875rem;color:#334155;">Name *</label>
                            <input name="name" type="text" required value="<?= e($editRecord['name'] ?? '') ?>" placeholder="SOURCE OF INCOME" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.875rem;color:#334155;">Amount (Rs.) *</label>
                            <input name="amount" type="number" step="0.01" min="0.01" required value="<?= e((string) ($editRecord['amount'] ?? '')) ?>" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.875rem;color:#334155;">Payment Mode</label>
                            <select name="payment_mode" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;background:#fff;">
                                <option value="">-- Select --</option>
                                <?php foreach (['Cash', 'Cheque', 'UPI', 'Bank Transfer', 'Card', 'Online'] as $pm): ?>
                                    <option value="<?= $pm ?>" <?= (isset($editRecord['payment_mode']) && $editRecord['payment_mode'] === $pm) ? 'selected' : '' ?>><?= $pm ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.875rem;color:#334155;">Payment ID</label>
                            <input type="text" name="payment_id" value="<?= e($editRecord['payment_id'] ?? '') ?>" placeholder="TRANSACTION / CHEQUE / REF NO" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;">
                        </div>
                        <div style="grid-column:1 / -1;">
                            <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.875rem;color:#334155;">Note</label>
                            <textarea name="description" rows="3" placeholder="Additional description..." style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;resize:vertical;"><?= e($editRecord['description'] ?? '') ?></textarea>
                        </div>
                        <?php if ($editRecord && $editType === 'income-categories'): ?>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.875rem;color:#334155;">Status</label>
                            <select name="status" style="width:100%;padding:.55rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem;background:#fff;">
                                <?php foreach (['Pending', 'Approved', 'Rejected', 'Received'] as $st): ?>
                                    <option value="<?= $st ?>" <?= (isset($editRecord['status']) && $editRecord['status'] === $st) ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top:1.5rem;display:flex;gap:.75rem;align-items:center;">
                        <button type="submit" style="background:#059669;color:#fff;border:none;padding:.65rem 1.4rem;border-radius:999px;font-weight:600;font-size:.9rem;cursor:pointer;"><?= ($editRecord && $editType === 'income-categories') ? 'Update Income' : 'Save Income' ?></button>
                        <a href="?tab=income-categories" style="background:#fff;color:#334155;border:1px solid #cbd5e1;padding:.65rem 1.4rem;border-radius:999px;font-weight:600;font-size:.9rem;text-decoration:none;">Cancel</a>
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
            <div style="margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;">
                <input type="text" id="vendorSearchInput" placeholder="Search vendors..." oninput="filterVendorTable()" style="flex:1;max-width:360px;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.88rem;outline:none;">
                <span id="vendorCount" style="font-size:.82rem;color:#64748b;"><?= count($vendors) ?> vendors</span>
            </div>
            <div class="vendor-table-wrap">
                <table class="app-table" id="vendorTable">
                    <thead>
                        <tr>
                            <th class="sortable" onclick="sortVendorTable(0,'num')"># <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(1,'str')">Code <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(2,'str')">Name <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(3,'str')">Mobile <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(4,'str')">Email <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(5,'str')">GST <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(6,'str')">PAN <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(7,'num')" style="text-align:right;">Total Expense <span class="sort-icon">⇅</span></th>
                            <th class="sortable" onclick="sortVendorTable(8,'str')">Status <span class="sort-icon">⇅</span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="vendorTableBody">
                        <?php $i = 1; foreach ($vendors as $row): ?>
                        <tr>
                            <td style="color:#94a3b8;"><?= $i++ ?></td>
                            <td><?= e($row['vendor_code'] ?? '') ?></td>
                            <td><strong style="cursor:pointer;color:#2563eb;text-decoration:underline;" onclick="showVendorExpenses(<?= (int) $row['id'] ?>, '<?= e($row['name']) ?>')"><?= e($row['name']) ?></strong></td>
                            <td><?= e($row['mobile'] ?? '') ?></td>
                            <td><?= e($row['email'] ?? '') ?></td>
                            <td><?= e($row['gst_number'] ?? '') ?></td>
                            <td><?= e($row['pan'] ?? '') ?></td>
                            <td style="text-align:right;" data-sort="<?= isset($vendorExpenseMap[(int) $row['id']]) ? (float) $vendorExpenseMap[(int) $row['id']]['total_expense'] : 0 ?>"><?= isset($vendorExpenseMap[(int) $row['id']]) ? 'Rs. ' . number_format((float) $vendorExpenseMap[(int) $row['id']]['total_expense'], 2) : '—' ?></td>
                            <td data-sort="<?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?>"><span class="badge <?= ($row['is_active'] ?? 0) ? 'badge-active' : 'badge-inactive' ?>"><?= ($row['is_active'] ?? 0) ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a class="btn-icon" href="?tab=vendors&edit=<?= (int) $row['id'] ?>" title="Edit">&#9998;</a>
                                    <?php if ($isOwner): ?>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this vendor?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="master_action" value="delete_vendor">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn-icon btn-del" title="Delete">&#128465;</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= render_pagination($mVndPage, $mVndTotalPages, $mVndTotal, $mPerPage, $mVndOffset, 'vp') ?>
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
            <?= render_pagination($mBaPage, $mBaTotalPages, $mBaTotal, $mPerPage, $mBaOffset, 'bp') ?>
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
                    <p>Define fee heads for classes, applications, donations, and other charges. Application Fee allows only 1 entry and is used site-wide.</p>
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
                                $editingAppFee = ($editRecord && ($editRecord['category'] ?? '') === 'Application Fee');
                                foreach ($categories as $cat):
                                    if ($cat === 'Application Fee' && $hasApplicationFee && !$editingAppFee) {
                                        continue;
                                    }
                                ?>
                                    <option value="<?= $cat ?>" <?= (isset($editRecord['category']) && $editRecord['category'] === $cat) ? 'selected' : '' ?>><?= $cat ?><?= $cat === 'Application Fee' ? ' (only 1 allowed)' : '' ?></option>
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

function eeCalcNet() {
    var amt = parseFloat(document.querySelector('[name="amount"]')?.value) || 0;
    var gst = parseFloat(document.querySelector('[name="gst_amount"]')?.value) || 0;
    var net = document.getElementById('ee-net-amount');
    if (net) net.value = (amt + gst).toFixed(2);
}

function eeToggleTxn() {
    var mode = document.getElementById('ee-payment-mode');
    var txnFields = document.querySelectorAll('.ee-txn-field');
    var show = mode && (mode.value === 'UPI' || mode.value === 'Bank Transfer' || mode.value === 'Online');
    txnFields.forEach(function(el) { el.style.display = show ? '' : 'none'; });
}

document.addEventListener('DOMContentLoaded', function() { eeToggleTxn(); eeCalcNet(); });

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

var vendorExpenses = <?= json_encode(array_map(fn(array $e) => [
    'vendor_id' => (int) $e['vendor_id'],
    'expense_no' => $e['expense_no'] ?? '',
    'expense_date' => $e['expense_date'] ?? '',
    'category_name' => $e['category_name'] ?? '',
    'description' => $e['description'] ?? '',
    'net_amount' => (float) $e['net_amount'],
    'status' => $e['status'] ?? '',
], $expenses)) ?>;

function showVendorExpenses(vendorId, vendorName) {
    var filtered = vendorExpenses.filter(function(e) { return e.vendor_id === vendorId; });
    var total = filtered.reduce(function(sum, e) { return sum + e.net_amount; }, 0);
    var html = '<table class="app-table" style="width:100%;font-size:.85rem;">' +
        '<thead><tr><th>Date</th><th>Expense No</th><th>Category</th><th>Description</th><th style="text-align:right;">Amount</th><th>Status</th></tr></thead><tbody>';
    if (filtered.length === 0) {
        html += '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#94a3b8;">No expenses found for this vendor.</td></tr>';
    } else {
        filtered.forEach(function(e) {
            html += '<tr><td>' + e.expense_date + '</td><td><code>' + e.expense_no + '</code></td><td>' + (e.category_name || '—') + '</td><td>' + (e.description || '—') + '</td><td style="text-align:right;font-weight:600;">Rs. ' + e.net_amount.toLocaleString('en-IN', {minimumFractionDigits:2}) + '</td><td>' + e.status + '</td></tr>';
        });
    }
    html += '</tbody></table>';
    html += '<div style="margin-top:1rem;text-align:right;font-size:.95rem;font-weight:700;color:#1e293b;">Total: Rs. ' + total.toLocaleString('en-IN', {minimumFractionDigits:2}) + ' (' + filtered.length + ' expenses)</div>';
    document.getElementById('vendorExpName').textContent = vendorName;
    document.getElementById('vendorExpBody').innerHTML = html;
    document.getElementById('vendorExpModal').classList.add('show');
}

var vendorSortCol = -1, vendorSortAsc = true;
function sortVendorTable(col, type) {
    var table = document.getElementById('vendorTable');
    var tbody = document.getElementById('vendorTableBody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    if (vendorSortCol === col) { vendorSortAsc = !vendorSortAsc; } else { vendorSortCol = col; vendorSortAsc = true; }
    table.querySelectorAll('th.sortable').forEach(function(th) { th.classList.remove('sort-asc','sort-desc'); th.querySelector('.sort-icon').textContent = '⇅'; });
    var th = table.querySelectorAll('th.sortable')[col];
    if (th) { th.classList.add(vendorSortAsc ? 'sort-asc' : 'sort-desc'); th.querySelector('.sort-icon').textContent = vendorSortAsc ? '↑' : '↓'; }
    rows.sort(function(a, b) {
        var ac = a.cells[col], bc = b.cells[col];
        var av = ac.getAttribute('data-sort') !== null ? ac.getAttribute('data-sort') : ac.textContent.trim();
        var bv = bc.getAttribute('data-sort') !== null ? bc.getAttribute('data-sort') : bc.textContent.trim();
        if (type === 'num') { av = parseFloat(av.replace(/[^\d.\-]/g,'')) || 0; bv = parseFloat(bv.replace(/[^\d.\-]/g,'')) || 0; return vendorSortAsc ? av - bv : bv - av; }
        return vendorSortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
}

function filterVendorTable() {
    var q = document.getElementById('vendorSearchInput').value.toLowerCase();
    var rows = document.querySelectorAll('#vendorTableBody tr'), shown = 0;
    rows.forEach(function(r) {
        var text = r.textContent.toLowerCase();
        var match = text.indexOf(q) !== -1;
        r.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    document.getElementById('vendorCount').textContent = shown + ' vendor' + (shown !== 1 ? 's' : '');
}
</script>

<div id="vendorExpModal" class="modal-backdrop">
    <div class="modal" style="max-width:800px;border-radius:14px;padding:0;overflow:auto;">
        <div class="modal-head" style="padding:1.1rem 1.4rem;border-bottom:1px solid #e2e8f0;">
            <h2 style="font-size:1.15rem;font-weight:700;margin:0;">Expenses – <span id="vendorExpName"></span></h2>
            <button type="button" class="icon-btn" onclick="closeModal(document.getElementById('vendorExpModal'))" style="font-size:1.3rem;color:#94a3b8;">&times;</button>
        </div>
        <div id="vendorExpBody" style="padding:1.25rem 1.4rem;max-height:60vh;overflow-y:auto;"></div>
    </div>
</div>

</body>
</html>
