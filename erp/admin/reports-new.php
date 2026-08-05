<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$pageTitle = 'Reports & Analytics';

$reportId = (int) ($_GET['report'] ?? 0);
$category = trim((string) ($_GET['cat'] ?? ''));
$export = trim((string) ($_GET['export'] ?? ''));

$filters = [
    'from'          => trim((string) ($_GET['from'] ?? '')),
    'to'            => trim((string) ($_GET['to'] ?? '')),
    'class'         => trim((string) ($_GET['class'] ?? '')),
    'student'       => trim((string) ($_GET['student'] ?? '')),
    'category'      => trim((string) ($_GET['category_filter'] ?? '')),
    'vendor'        => trim((string) ($_GET['vendor'] ?? '')),
    'account'       => trim((string) ($_GET['account'] ?? '')),
    'month'         => trim((string) ($_GET['month'] ?? '')),
    'year'          => trim((string) ($_GET['year'] ?? '')),
    'status'        => trim((string) ($_GET['status'] ?? '')),
    'department'    => trim((string) ($_GET['department'] ?? '')),
];

// ── All 30 reports definition ──
$categories = [
    'fee' => [
        'label' => 'Fee Reports',
        'icon'  => '💰',
        'reports' => [
            1  => ['name' => 'Daily Fee Collection',           'desc' => 'Collections for a specific day',          'icon' => '📅'],
            2  => ['name' => 'Student Fee Ledger',              'desc' => 'All payments and balances per student',   'icon' => '📒'],
            3  => ['name' => 'Outstanding Fees',                'desc' => 'Students with pending amounts',           'icon' => '⚠️'],
            4  => ['name' => 'EMI Pending Report',              'desc' => 'Pending EMI installments',                'icon' => '🔄'],
            5  => ['name' => 'Class-Wise Collection',           'desc' => 'Totals grouped by class',                 'icon' => '🏫'],
            6  => ['name' => 'Fee Head-Wise Collection',        'desc' => 'Totals grouped by fee head',              'icon' => '📋'],
            7  => ['name' => 'Discount Report',                 'desc' => 'All discounts with amounts',              'icon' => '🏷️'],
            8  => ['name' => 'Fee Structure Summary',           'desc' => 'Structures with assignment counts',       'icon' => '🏗️'],
        ],
    ],
    'expense' => [
        'label' => 'Expense Reports',
        'icon'  => '📤',
        'reports' => [
            9  => ['name' => 'Daily Expense Report',            'desc' => 'Expenses for a specific day',             'icon' => '📅'],
            10 => ['name' => 'Category-Wise Expense',           'desc' => 'Totals grouped by category',              'icon' => '📂'],
            11 => ['name' => 'Vendor-Wise Expense',             'desc' => 'Totals grouped by vendor',                'icon' => '🏢'],
            12 => ['name' => 'Unpaid Vendor Bills',             'desc' => 'All unpaid or partial bills',             'icon' => '📄'],
        ],
    ],
    'cashbank' => [
        'label' => 'Cash & Bank Reports',
        'icon'  => '🏦',
        'reports' => [
            13 => ['name' => 'Cash Book Report',                'desc' => 'All cash transactions in date range',     'icon' => '💵'],
            14 => ['name' => 'Bank Book Report',                'desc' => 'Bank transactions with account filter',   'icon' => '🏛️'],
            15 => ['name' => 'Cash Counter Report',             'desc' => 'Daily cash summary',                      'icon' => '🧮'],
            16 => ['name' => 'Bank Reconciliation Report',      'desc' => 'Reconciled vs unreconciled',              'icon' => '✅'],
            17 => ['name' => 'Cheque Register',                 'desc' => 'All issued cheques with status',          'icon' => '📝'],
        ],
    ],
    'payroll' => [
        'label' => 'Payroll Reports',
        'icon'  => '👥',
        'reports' => [
            18 => ['name' => 'Monthly Salary Register',         'desc' => 'Payroll run detail by month',             'icon' => '📊'],
            19 => ['name' => 'Employee Salary Ledger',          'desc' => 'Per-employee salary history',             'icon' => '📒'],
            20 => ['name' => 'Salary Payment Report',           'desc' => 'Paid vs pending salary payments',         'icon' => '💳'],
        ],
    ],
    'inventory' => [
        'label' => 'Inventory Reports',
        'icon'  => '📦',
        'reports' => [
            21 => ['name' => 'Current Stock',                   'desc' => 'All items with quantities',               'icon' => '📦'],
            22 => ['name' => 'Low Stock Alert',                 'desc' => 'Items below reorder level',               'icon' => '🔔'],
            23 => ['name' => 'Item Movement',                   'desc' => 'Transaction history for items',           'icon' => '🔄'],
        ],
    ],
    'asset' => [
        'label' => 'Asset Reports',
        'icon'  => '🏢',
        'reports' => [
            24 => ['name' => 'Fixed Asset Register',            'desc' => 'All assets with values',                  'icon' => '📋'],
            25 => ['name' => 'Depreciation Report',             'desc' => 'Depreciation by year',                    'icon' => '📉'],
        ],
    ],
    'management' => [
        'label' => 'Management Reports',
        'icon'  => '📈',
        'reports' => [
            26 => ['name' => 'Monthly Income & Expense',        'desc' => 'Summary by month',                        'icon' => '📊'],
            27 => ['name' => 'Budget vs Actual',                'desc' => 'Compare budget allocations with actuals',  'icon' => '🎯'],
            28 => ['name' => 'Cash & Bank Position',            'desc' => 'Current balances across all accounts',    'icon' => '🏦'],
            29 => ['name' => 'Department Expense',              'desc' => 'Expenses grouped by department',          'icon' => '🏛️'],
            30 => ['name' => 'Annual Financial Summary',        'desc' => 'Full year income/expenses/surplus',        'icon' => '📅'],
        ],
    ],
];

function get_all_reports(): array
{
    global $categories;
    $all = [];
    foreach ($categories as $cat => $c) {
        foreach ($c['reports'] as $rid => $r) {
            $all[$rid] = $r;
            $all[$rid]['category'] = $cat;
        }
    }
    return $all;
}

function find_report_category(int $reportId): string
{
    global $categories;
    foreach ($categories as $cat => $c) {
        if (isset($c['reports'][$reportId])) return $cat;
    }
    return 'fee';
}

function safe_query(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

function safe_scalar(PDO $pdo, string $sql, array $params = []): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

function get_class_options(PDO $pdo): array
{
    $rows = safe_query($pdo, "SELECT DISTINCT class_name FROM student_fee_accounts WHERE class_name IS NOT NULL ORDER BY class_name");
    return array_map(fn($r) => $r['class_name'], $rows);
}

function get_vendor_options(PDO $pdo): array
{
    return safe_query($pdo, "SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name");
}

function get_bank_account_options(PDO $pdo): array
{
    return safe_query($pdo, "SELECT id, CONCAT(bank_name, ' - ', account_name) AS label FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
}

function get_category_options(PDO $pdo): array
{
    return safe_query($pdo, "SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY name");
}

function get_payroll_month_options(PDO $pdo): array
{
    return safe_query($pdo, "SELECT DISTINCT month_label FROM payroll_runs ORDER BY month_label DESC");
}

function get_employee_options(PDO $pdo): array
{
    return safe_query($pdo, "SELECT DISTINCT employee_id, employee_name FROM payroll_items ORDER BY employee_name");
}

// ──────────────────────────────────────────────────────────────────
// REPORT DATA FUNCTIONS
// ──────────────────────────────────────────────────────────────────

function report_data(PDO $pdo, int $reportId, array $filters): array
{
    $result = ['headers' => [], 'rows' => [], 'title' => '', 'totals' => []];

    switch ($reportId) {

        // ─── 1. Daily Fee Collection ───
        case 1:
            $result['title'] = 'Daily Fee Collection';
            $result['headers'] = ['Date', 'Receipts', 'Total Amount', 'Discount', 'Late Fee', 'Net Amount', 'Cash', 'UPI', 'Cheque', 'Card', 'Bank Transfer'];
            $where = "WHERE status = 'Active'";
            $params = [];
            if ($filters['from']) {
                $where .= " AND payment_date = :frm";
                $params['frm'] = $filters['from'];
            } else {
                $where .= " AND payment_date = CURDATE()";
            }
            $sql = "SELECT payment_date,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(total_amount),0) AS total,
                    COALESCE(SUM(discount_amount),0) AS discount,
                    COALESCE(SUM(late_fee),0) AS late_fee,
                    COALESCE(SUM(net_amount),0) AS net,
                    COALESCE(SUM(CASE WHEN payment_mode='Cash' THEN net_amount ELSE 0 END),0) AS cash,
                    COALESCE(SUM(CASE WHEN payment_mode='UPI' THEN net_amount ELSE 0 END),0) AS upi,
                    COALESCE(SUM(CASE WHEN payment_mode='Cheque' THEN net_amount ELSE 0 END),0) AS cheque,
                    COALESCE(SUM(CASE WHEN payment_mode='Card' THEN net_amount ELSE 0 END),0) AS card,
                    COALESCE(SUM(CASE WHEN payment_mode='Bank Transfer' THEN net_amount ELSE 0 END),0) AS bank_transfer
                    FROM fee_collections $where GROUP BY payment_date ORDER BY payment_date DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'cnt' => array_sum(array_column($result['rows'], 'cnt')),
                'total' => array_sum(array_column($result['rows'], 'total')),
                'discount' => array_sum(array_column($result['rows'], 'discount')),
                'late_fee' => array_sum(array_column($result['rows'], 'late_fee')),
                'net' => array_sum(array_column($result['rows'], 'net')),
                'cash' => array_sum(array_column($result['rows'], 'cash')),
                'upi' => array_sum(array_column($result['rows'], 'upi')),
                'cheque' => array_sum(array_column($result['rows'], 'cheque')),
                'card' => array_sum(array_column($result['rows'], 'card')),
                'bank_transfer' => array_sum(array_column($result['rows'], 'bank_transfer')),
            ];
            break;

        // ─── 2. Student Fee Ledger ───
        case 2:
            $result['title'] = 'Student Fee Ledger';
            $result['headers'] = ['Receipt No', 'Student Name', 'Admission No', 'Class', 'Date', 'Total Amount', 'Discount', 'Late Fee', 'Net Amount', 'Payment Mode', 'Status'];
            $where = "WHERE status = 'Active'";
            $params = [];
            if ($filters['student']) {
                $where .= " AND (student_name LIKE :st OR admission_no LIKE :st2)";
                $params['st'] = '%' . $filters['student'] . '%';
                $params['st2'] = '%' . $filters['student'] . '%';
            }
            if ($filters['from']) { $where .= " AND payment_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND payment_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['class']) { $where .= " AND class_name = :cls"; $params['cls'] = $filters['class']; }
            $sql = "SELECT receipt_no, student_name, admission_no, class_name, payment_date,
                    total_amount, discount_amount, late_fee, net_amount, payment_mode, status
                    FROM fee_collections $where ORDER BY payment_date DESC, id DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'total_amount' => array_sum(array_column($result['rows'], 'total_amount')),
                'discount_amount' => array_sum(array_column($result['rows'], 'discount_amount')),
                'late_fee' => array_sum(array_column($result['rows'], 'late_fee')),
                'net_amount' => array_sum(array_column($result['rows'], 'net_amount')),
            ];
            break;

        // ─── 3. Outstanding Fees ───
        case 3:
            $result['title'] = 'Outstanding Fees';
            $result['headers'] = ['Student Name', 'Admission No', 'Class', 'Session', 'Total Fee', 'Paid', 'Discount', 'Late Fee', 'Balance'];
            $where = "WHERE balance > 0";
            $params = [];
            if ($filters['class']) { $where .= " AND class_name = :cls"; $params['cls'] = $filters['class']; }
            if ($filters['student']) {
                $where .= " AND (student_name LIKE :st OR admission_no LIKE :st2)";
                $params['st'] = '%' . $filters['student'] . '%';
                $params['st2'] = '%' . $filters['student'] . '%';
            }
            $sql = "SELECT student_name, admission_no, class_name, academic_session, total_fee, total_paid, total_discount, total_late_fee, balance
                    FROM student_fee_accounts $where ORDER BY balance DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'total_fee' => array_sum(array_column($result['rows'], 'total_fee')),
                'total_paid' => array_sum(array_column($result['rows'], 'total_paid')),
                'total_discount' => array_sum(array_column($result['rows'], 'total_discount')),
                'total_late_fee' => array_sum(array_column($result['rows'], 'total_late_fee')),
                'balance' => array_sum(array_column($result['rows'], 'balance')),
            ];
            break;

        // ─── 4. EMI Pending Report ───
        case 4:
            $result['title'] = 'EMI Pending Report';
            $result['headers'] = ['Student Name', 'Installment #', 'Due Date', 'Amount', 'Late Fee', 'Paid Amount', 'Status'];
            $where = "WHERE ep.status IN ('Pending','Overdue','Partial')";
            $params = [];
            if ($filters['student']) {
                $where .= " AND es.student_name LIKE :st";
                $params['st'] = '%' . $filters['student'] . '%';
            }
            if ($filters['from']) { $where .= " AND ep.due_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND ep.due_date <= :to"; $params['to'] = $filters['to']; }
            $sql = "SELECT es.student_name, ep.installment_no, ep.due_date, ep.amount, ep.late_fee, ep.paid_amount, ep.status
                    FROM emi_payments ep
                    JOIN emi_schedules es ON es.id = ep.emi_schedule_id
                    $where ORDER BY ep.due_date ASC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'amount' => array_sum(array_column($result['rows'], 'amount')),
                'late_fee' => array_sum(array_column($result['rows'], 'late_fee')),
                'paid_amount' => array_sum(array_column($result['rows'], 'paid_amount')),
            ];
            break;

        // ─── 5. Class-Wise Collection ───
        case 5:
            $result['title'] = 'Class-Wise Collection';
            $result['headers'] = ['Class', 'Students', 'Total Amount', 'Discount', 'Late Fee', 'Net Amount'];
            $where = "WHERE status = 'Active'";
            $params = [];
            if ($filters['from']) { $where .= " AND payment_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND payment_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['class']) { $where .= " AND class_name = :cls"; $params['cls'] = $filters['class']; }
            $sql = "SELECT class_name,
                    COUNT(DISTINCT student_id) AS students,
                    COALESCE(SUM(total_amount),0) AS total_amount,
                    COALESCE(SUM(discount_amount),0) AS discount,
                    COALESCE(SUM(late_fee),0) AS late_fee,
                    COALESCE(SUM(net_amount),0) AS net_amount
                    FROM fee_collections $where GROUP BY class_name ORDER BY class_name";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'students' => array_sum(array_column($result['rows'], 'students')),
                'total_amount' => array_sum(array_column($result['rows'], 'total_amount')),
                'discount' => array_sum(array_column($result['rows'], 'discount')),
                'late_fee' => array_sum(array_column($result['rows'], 'late_fee')),
                'net_amount' => array_sum(array_column($result['rows'], 'net_amount')),
            ];
            break;

        // ─── 6. Fee Head-Wise Collection ───
        case 6:
            $result['title'] = 'Fee Head-Wise Collection';
            $result['headers'] = ['Fee Head', 'Collections', 'Total Amount'];
            $sql = "SELECT fci.fee_head_name,
                    COUNT(DISTINCT fci.fee_collection_id) AS collections,
                    COALESCE(SUM(fci.amount),0) AS total_amount
                    FROM fee_collection_items fci
                    JOIN fee_collections fc ON fc.id = fci.fee_collection_id AND fc.status = 'Active'";
            $whereExtra = "";
            $params = [];
            if ($filters['from']) { $whereExtra .= " AND fc.payment_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $whereExtra .= " AND fc.payment_date <= :to"; $params['to'] = $filters['to']; }
            $sql .= " $whereExtra GROUP BY fci.fee_head_name ORDER BY total_amount DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'collections' => array_sum(array_column($result['rows'], 'collections')),
                'total_amount' => array_sum(array_column($result['rows'], 'total_amount')),
            ];
            break;

        // ─── 7. Discount Report ───
        case 7:
            $result['title'] = 'Discount Report';
            $result['headers'] = ['Student Name', 'Type', 'Method', 'Amount', 'Fee Head', 'Start Date', 'End Date', 'Status'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['student']) {
                $where .= " AND student_name LIKE :st";
                $params['st'] = '%' . $filters['student'] . '%';
            }
            if ($filters['status']) { $where .= " AND status = :sts"; $params['sts'] = $filters['status']; }
            $sql = "SELECT student_name, discount_type, discount_method, amount, applicable_fee_head_name, start_date, end_date, status
                    FROM discounts $where ORDER BY created_at DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'amount' => array_sum(array_column($result['rows'], 'amount')),
            ];
            break;

        // ─── 8. Fee Structure Summary ───
        case 8:
            $result['title'] = 'Fee Structure Summary';
            $result['headers'] = ['Structure Name', 'Session', 'Class', 'Category', 'Total Amount', 'EMI', 'Installments', 'Assignments', 'Status'];
            $sql = "SELECT fs.name, fs.academic_session, fs.class_name, fs.student_category, fs.total_amount,
                    fs.emi_allowed, fs.num_installments,
                    (SELECT COUNT(*) FROM fee_structure_assignments fsa WHERE fsa.fee_structure_id = fs.id AND fsa.is_active = 1) AS assignments,
                    CASE WHEN fs.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS status
                    FROM fee_structures fs ORDER BY fs.created_at DESC";
            $result['rows'] = safe_query($pdo, $sql);
            $result['totals'] = [
                'total_amount' => array_sum(array_column($result['rows'], 'total_amount')),
                'assignments' => array_sum(array_column($result['rows'], 'assignments')),
            ];
            break;

        // ─── 9. Daily Expense Report ───
        case 9:
            $result['title'] = 'Daily Expense Report';
            $result['headers'] = ['Expense No', 'Date', 'Category', 'Vendor', 'Amount', 'GST', 'Net Amount', 'Payment Mode', 'Status'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND e.expense_date = :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND e.expense_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['category']) { $where .= " AND e.category_id = :cat"; $params['cat'] = $filters['category']; }
            $sql = "SELECT e.expense_no, e.expense_date, e.category_name, e.vendor_name, e.amount, e.gst_amount, e.net_amount, e.payment_mode, e.status
                    FROM expenses e $where ORDER BY e.expense_date DESC, e.id DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'amount' => array_sum(array_column($result['rows'], 'amount')),
                'gst_amount' => array_sum(array_column($result['rows'], 'gst_amount')),
                'net_amount' => array_sum(array_column($result['rows'], 'net_amount')),
            ];
            break;

        // ─── 10. Category-Wise Expense ───
        case 10:
            $result['title'] = 'Category-Wise Expense';
            $result['headers'] = ['Category', 'Expenses', 'Total Amount', 'Net Amount', 'Approved', 'Pending'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND e.expense_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND e.expense_date <= :to"; $params['to'] = $filters['to']; }
            $sql = "SELECT ec.name AS category,
                    COUNT(*) AS expenses,
                    COALESCE(SUM(e.amount),0) AS total_amount,
                    COALESCE(SUM(e.net_amount),0) AS net_amount,
                    COALESCE(SUM(CASE WHEN e.status='Approved' THEN e.net_amount ELSE 0 END),0) AS approved,
                    COALESCE(SUM(CASE WHEN e.status='Pending' THEN e.net_amount ELSE 0 END),0) AS pending
                    FROM expenses e
                    LEFT JOIN expense_categories ec ON ec.id = e.category_id
                    $where GROUP BY ec.name ORDER BY net_amount DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'expenses' => array_sum(array_column($result['rows'], 'expenses')),
                'total_amount' => array_sum(array_column($result['rows'], 'total_amount')),
                'net_amount' => array_sum(array_column($result['rows'], 'net_amount')),
                'approved' => array_sum(array_column($result['rows'], 'approved')),
                'pending' => array_sum(array_column($result['rows'], 'pending')),
            ];
            break;

        // ─── 11. Vendor-Wise Expense ───
        case 11:
            $result['title'] = 'Vendor-Wise Expense';
            $result['headers'] = ['Vendor', 'Expenses', 'Total Amount', 'Net Amount', 'Approved', 'Pending'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND e.expense_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND e.expense_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['vendor']) { $where .= " AND e.vendor_id = :vnd"; $params['vnd'] = $filters['vendor']; }
            $sql = "SELECT COALESCE(e.vendor_name, 'Unknown') AS vendor,
                    COUNT(*) AS expenses,
                    COALESCE(SUM(e.amount),0) AS total_amount,
                    COALESCE(SUM(e.net_amount),0) AS net_amount,
                    COALESCE(SUM(CASE WHEN e.status='Approved' THEN e.net_amount ELSE 0 END),0) AS approved,
                    COALESCE(SUM(CASE WHEN e.status='Pending' THEN e.net_amount ELSE 0 END),0) AS pending
                    FROM expenses e $where GROUP BY e.vendor_name ORDER BY net_amount DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'expenses' => array_sum(array_column($result['rows'], 'expenses')),
                'total_amount' => array_sum(array_column($result['rows'], 'total_amount')),
                'net_amount' => array_sum(array_column($result['rows'], 'net_amount')),
                'approved' => array_sum(array_column($result['rows'], 'approved')),
                'pending' => array_sum(array_column($result['rows'], 'pending')),
            ];
            break;

        // ─── 12. Unpaid Vendor Bills ───
        case 12:
            $result['title'] = 'Unpaid Vendor Bills';
            $result['headers'] = ['Vendor', 'Bill No', 'Bill Date', 'Bill Amount', 'Paid', 'Balance', 'Status'];
            $where = "WHERE vb.status IN ('Unpaid','Partial')";
            $params = [];
            if ($filters['vendor']) { $where .= " AND vb.vendor_id = :vnd"; $params['vnd'] = $filters['vendor']; }
            $sql = "SELECT v.name AS vendor, vb.bill_no, vb.bill_date, vb.bill_amount, vb.paid_amount, vb.balance, vb.status
                    FROM vendor_bills vb
                    JOIN vendors v ON v.id = vb.vendor_id
                    $where ORDER BY vb.bill_date ASC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'bill_amount' => array_sum(array_column($result['rows'], 'bill_amount')),
                'paid_amount' => array_sum(array_column($result['rows'], 'paid_amount')),
                'balance' => array_sum(array_column($result['rows'], 'balance')),
            ];
            break;

        // ─── 13. Cash Book Report ───
        case 13:
            $result['title'] = 'Cash Book Report';
            $result['headers'] = ['Date', 'Type', 'Description', 'Amount', 'Direction', 'Balance'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND transaction_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND transaction_date <= :to"; $params['to'] = $filters['to']; }
            $sql = "SELECT transaction_date, transaction_type, description, amount, direction, balance
                    FROM cash_book $where ORDER BY transaction_date DESC, id DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'credit' => array_sum(array_map(fn($r) => $r['direction'] === 'Cr' ? (float) $r['amount'] : 0, $result['rows'])),
                'debit' => array_sum(array_map(fn($r) => $r['direction'] === 'Dr' ? (float) $r['amount'] : 0, $result['rows'])),
            ];
            break;

        // ─── 14. Bank Book Report ───
        case 14:
            $result['title'] = 'Bank Book Report';
            $result['headers'] = ['Date', 'Account', 'Type', 'Description', 'Amount', 'Direction', 'Balance', 'Reconciled'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND bb.transaction_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND bb.transaction_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['account']) { $where .= " AND bb.bank_account_id = :acc"; $params['acc'] = $filters['account']; }
            $sql = "SELECT bb.transaction_date, CONCAT(ba.bank_name, ' - ', ba.account_name) AS account,
                    bb.transaction_type, bb.description, bb.amount, bb.direction, bb.balance,
                    CASE WHEN bb.reconciled = 1 THEN 'Yes' ELSE 'No' END AS reconciled
                    FROM bank_book bb
                    JOIN bank_accounts ba ON ba.id = bb.bank_account_id
                    $where ORDER BY bb.transaction_date DESC, bb.id DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'credit' => array_sum(array_map(fn($r) => $r['direction'] === 'Cr' ? (float) $r['amount'] : 0, $result['rows'])),
                'debit' => array_sum(array_map(fn($r) => $r['direction'] === 'Dr' ? (float) $r['amount'] : 0, $result['rows'])),
            ];
            break;

        // ─── 15. Cash Counter Report ───
        case 15:
            $result['title'] = 'Cash Counter Report';
            $result['headers'] = ['Date', 'Receipts In', 'Payments Out', 'Net Cash Flow', 'Closing Balance'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND transaction_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND transaction_date <= :to"; $params['to'] = $filters['to']; }
            $sql = "SELECT transaction_date AS date,
                    COALESCE(SUM(CASE WHEN direction='Cr' THEN amount ELSE 0 END),0) AS receipts_in,
                    COALESCE(SUM(CASE WHEN direction='Dr' THEN amount ELSE 0 END),0) AS payments_out,
                    COALESCE(SUM(CASE WHEN direction='Cr' THEN amount ELSE -amount END),0) AS net_cash_flow,
                    MAX(balance) AS closing_balance
                    FROM cash_book $where GROUP BY transaction_date ORDER BY transaction_date DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'receipts_in' => array_sum(array_column($result['rows'], 'receipts_in')),
                'payments_out' => array_sum(array_column($result['rows'], 'payments_out')),
                'net_cash_flow' => array_sum(array_column($result['rows'], 'net_cash_flow')),
            ];
            break;

        // ─── 16. Bank Reconciliation Report ───
        case 16:
            $result['title'] = 'Bank Reconciliation Report';
            $result['headers'] = ['Account', 'Total Transactions', 'Reconciled', 'Unreconciled', 'Reconciled Amount', 'Unreconciled Amount'];
            $sql = "SELECT CONCAT(ba.bank_name, ' - ', ba.account_name) AS account,
                    COUNT(bb.id) AS total_transactions,
                    SUM(CASE WHEN bb.reconciled = 1 THEN 1 ELSE 0 END) AS reconciled,
                    SUM(CASE WHEN bb.reconciled = 0 THEN 1 ELSE 0 END) AS unreconciled,
                    COALESCE(SUM(CASE WHEN bb.reconciled = 1 THEN bb.amount ELSE 0 END),0) AS reconciled_amount,
                    COALESCE(SUM(CASE WHEN bb.reconciled = 0 THEN bb.amount ELSE 0 END),0) AS unreconciled_amount
                    FROM bank_book bb
                    JOIN bank_accounts ba ON ba.id = bb.bank_account_id";
            $where = " WHERE 1=1";
            $params = [];
            if ($filters['account']) { $where .= " AND bb.bank_account_id = :acc"; $params['acc'] = $filters['account']; }
            if ($filters['from']) { $where .= " AND bb.transaction_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND bb.transaction_date <= :to"; $params['to'] = $filters['to']; }
            $sql .= "$where GROUP BY ba.id, ba.bank_name, ba.account_name ORDER BY ba.bank_name";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'total_transactions' => array_sum(array_column($result['rows'], 'total_transactions')),
                'reconciled' => array_sum(array_column($result['rows'], 'reconciled')),
                'unreconciled' => array_sum(array_column($result['rows'], 'unreconciled')),
                'reconciled_amount' => array_sum(array_column($result['rows'], 'reconciled_amount')),
                'unreconciled_amount' => array_sum(array_column($result['rows'], 'unreconciled_amount')),
            ];
            break;

        // ─── 17. Cheque Register ───
        case 17:
            $result['title'] = 'Cheque Register';
            $result['headers'] = ['Cheque No', 'Bank Account', 'Date', 'Payee', 'Amount', 'Purpose', 'Status', 'Cleared Date'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND ci.cheque_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND ci.cheque_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['account']) { $where .= " AND ci.bank_account_id = :acc"; $params['acc'] = $filters['account']; }
            if ($filters['status']) { $where .= " AND ci.status = :sts"; $params['sts'] = $filters['status']; }
            $sql = "SELECT ci.cheque_number, CONCAT(ba.bank_name, ' - ', ba.account_name) AS bank_account,
                    ci.cheque_date, ci.payee_name, ci.amount, ci.purpose, ci.status, ci.cleared_date
                    FROM cheque_issues ci
                    JOIN bank_accounts ba ON ba.id = ci.bank_account_id
                    $where ORDER BY ci.cheque_date DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'amount' => array_sum(array_column($result['rows'], 'amount')),
            ];
            break;

        // ─── 18. Monthly Salary Register ───
        case 18:
            $result['title'] = 'Monthly Salary Register';
            $result['headers'] = ['Month', 'Employees', 'Gross', 'Deductions', 'Net Pay', 'Status', 'Generated'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['month']) { $where .= " AND pr.month_label = :mo"; $params['mo'] = $filters['month']; }
            $sql = "SELECT pr.month_label, pr.total_employees, pr.total_gross, pr.total_deductions, pr.total_net,
                    pr.status, pr.generated_at
                    FROM payroll_runs pr $where ORDER BY pr.generated_at DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'total_employees' => array_sum(array_column($result['rows'], 'total_employees')),
                'total_gross' => array_sum(array_column($result['rows'], 'total_gross')),
                'total_deductions' => array_sum(array_column($result['rows'], 'total_deductions')),
                'total_net' => array_sum(array_column($result['rows'], 'total_net')),
            ];
            break;

        // ─── 19. Employee Salary Ledger ───
        case 19:
            $result['title'] = 'Employee Salary Ledger';
            $result['headers'] = ['Month', 'Employee', 'Department', 'Gross', 'Deductions', 'Net Payout', 'Payment Status'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['student']) {
                $where .= " AND pi.employee_name LIKE :emp";
                $params['emp'] = '%' . $filters['student'] . '%';
            }
            if ($filters['month']) { $where .= " AND pr.month_label = :mo"; $params['mo'] = $filters['month']; }
            if ($filters['department']) { $where .= " AND pi.department = :dept"; $params['dept'] = $filters['department']; }
            $sql = "SELECT pr.month_label, pi.employee_name, pi.department, pi.gross_amount, pi.total_deductions, pi.net_payout, pi.payment_status
                    FROM payroll_items pi
                    JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
                    $where ORDER BY pr.month_label DESC, pi.employee_name ASC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'gross_amount' => array_sum(array_column($result['rows'], 'gross_amount')),
                'total_deductions' => array_sum(array_column($result['rows'], 'total_deductions')),
                'net_payout' => array_sum(array_column($result['rows'], 'net_payout')),
            ];
            break;

        // ─── 20. Salary Payment Report ───
        case 20:
            $result['title'] = 'Salary Payment Report';
            $result['headers'] = ['Month', 'Employees', 'Total Gross', 'Total Deductions', 'Paid', 'Pending', 'Paid Amount', 'Pending Amount'];
            $sql = "SELECT pr.month_label, pr.total_employees, pr.total_gross, pr.total_deductions,
                    SUM(CASE WHEN pi.payment_status='Paid' THEN 1 ELSE 0 END) AS paid_count,
                    SUM(CASE WHEN pi.payment_status='Pending' THEN 1 ELSE 0 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN pi.payment_status='Paid' THEN pi.net_payout ELSE 0 END),0) AS paid_amount,
                    COALESCE(SUM(CASE WHEN pi.payment_status='Pending' THEN pi.net_payout ELSE 0 END),0) AS pending_amount
                    FROM payroll_runs pr
                    LEFT JOIN payroll_items pi ON pi.payroll_run_id = pr.id";
            $where = " WHERE 1=1";
            $params = [];
            if ($filters['month']) { $where .= " AND pr.month_label = :mo"; $params['mo'] = $filters['month']; }
            $sql .= "$where GROUP BY pr.id, pr.month_label, pr.total_employees, pr.total_gross, pr.total_deductions ORDER BY pr.generated_at DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'total_employees' => array_sum(array_column($result['rows'], 'total_employees')),
                'total_gross' => array_sum(array_column($result['rows'], 'total_gross')),
                'total_deductions' => array_sum(array_column($result['rows'], 'total_deductions')),
                'paid_count' => array_sum(array_column($result['rows'], 'paid_count')),
                'pending_count' => array_sum(array_column($result['rows'], 'pending_count')),
                'paid_amount' => array_sum(array_column($result['rows'], 'paid_amount')),
                'pending_amount' => array_sum(array_column($result['rows'], 'pending_amount')),
            ];
            break;

        // ─── 21. Current Stock ───
        case 21:
            $result['title'] = 'Current Stock';
            $result['headers'] = ['Item Code', 'Item Name', 'Category', 'Unit', 'Opening Qty', 'Current Qty', 'Reorder Level', 'Purchase Rate', 'Value', 'Location'];
            $sql = "SELECT item_code, item_name, category, unit, opening_quantity, current_quantity, reorder_level, purchase_rate,
                    (current_quantity * purchase_rate) AS value, store_location
                    FROM inventory_items WHERE is_active = 1 ORDER BY item_name";
            $result['rows'] = safe_query($pdo, $sql);
            $result['totals'] = [
                'opening_quantity' => array_sum(array_column($result['rows'], 'opening_quantity')),
                'current_quantity' => array_sum(array_column($result['rows'], 'current_quantity')),
                'value' => array_sum(array_column($result['rows'], 'value')),
            ];
            break;

        // ─── 22. Low Stock Alert ───
        case 22:
            $result['title'] = 'Low Stock Alert';
            $result['headers'] = ['Item Code', 'Item Name', 'Category', 'Unit', 'Current Qty', 'Reorder Level', 'Deficit'];
            $sql = "SELECT item_code, item_name, category, unit, current_quantity, reorder_level,
                    (reorder_level - current_quantity) AS deficit
                    FROM inventory_items WHERE is_active = 1 AND current_quantity <= reorder_level AND reorder_level > 0
                    ORDER BY deficit DESC";
            $result['rows'] = safe_query($pdo, $sql);
            $result['totals'] = [
                'current_quantity' => array_sum(array_column($result['rows'], 'current_quantity')),
                'reorder_level' => array_sum(array_column($result['rows'], 'reorder_level')),
                'deficit' => array_sum(array_column($result['rows'], 'deficit')),
            ];
            break;

        // ─── 23. Item Movement ───
        case 23:
            $result['title'] = 'Item Movement';
            $result['headers'] = ['Date', 'Item', 'Type', 'Qty', 'Department', 'Issued To', 'Remarks'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND it.transaction_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND it.transaction_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['category']) { $where .= " AND ii.id = :item"; $params['item'] = $filters['category']; }
            $sql = "SELECT it.transaction_date, ii.item_name, it.transaction_type, it.quantity, it.department, it.issued_to, it.remarks
                    FROM inventory_transactions it
                    JOIN inventory_items ii ON ii.id = it.item_id
                    $where ORDER BY it.transaction_date DESC, it.id DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'quantity' => array_sum(array_column($result['rows'], 'quantity')),
            ];
            break;

        // ─── 24. Fixed Asset Register ───
        case 24:
            $result['title'] = 'Fixed Asset Register';
            $result['headers'] = ['Code', 'Name', 'Category', 'Purchase Date', 'Cost', 'Depreciation Rate', 'Accumulated Dep.', 'Current Value', 'Status'];
            $sql = "SELECT fa.asset_code, fa.asset_name, ac.name AS category, fa.purchase_date, fa.purchase_cost,
                    fa.depreciation_rate, fa.accumulated_depreciation, fa.current_value, fa.status
                    FROM fixed_assets fa
                    LEFT JOIN asset_categories ac ON ac.id = fa.category_id
                    ORDER BY fa.purchase_date DESC";
            $result['rows'] = safe_query($pdo, $sql);
            $result['totals'] = [
                'purchase_cost' => array_sum(array_column($result['rows'], 'purchase_cost')),
                'accumulated_depreciation' => array_sum(array_column($result['rows'], 'accumulated_depreciation')),
                'current_value' => array_sum(array_column($result['rows'], 'current_value')),
            ];
            break;

        // ─── 25. Depreciation Report ───
        case 25:
            $result['title'] = 'Depreciation Report';
            $result['headers'] = ['Asset', 'Category', 'Cost', 'FY Depreciation', 'Accumulated After', 'Book Value After'];
            $sql = "SELECT fa.asset_name, ac.name AS category, fa.purchase_cost,
                    ad.depreciation_amount, ad.accumulated_after, ad.book_value_after
                    FROM asset_depreciations ad
                    JOIN fixed_assets fa ON fa.id = ad.asset_id
                    LEFT JOIN asset_categories ac ON ac.id = fa.category_id";
            $where = " WHERE 1=1";
            $params = [];
            if ($filters['year']) { $where .= " AND ad.financial_year_id = :fy"; $params['fy'] = $filters['year']; }
            $sql .= "$where ORDER BY fa.asset_name, ad.calculated_at DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'purchase_cost' => array_sum(array_column($result['rows'], 'purchase_cost')),
                'depreciation_amount' => array_sum(array_column($result['rows'], 'depreciation_amount')),
                'accumulated_after' => array_sum(array_column($result['rows'], 'accumulated_after')),
                'book_value_after' => array_sum(array_column($result['rows'], 'book_value_after')),
            ];
            break;

        // ─── 26. Monthly Income & Expense ───
        case 26:
            $result['title'] = 'Monthly Income & Expense';
            $result['headers'] = ['Month', 'Fee Income', 'Other Income', 'Total Income', 'Total Expenses', 'Net Surplus/Deficit'];
            $year = $filters['year'] ?: date('Y');
            $params = ['yr' => $year];

            $feeIncome = safe_query($pdo,
                "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS m, COALESCE(SUM(net_amount),0) AS amt
                 FROM fee_collections WHERE status='Active' AND YEAR(payment_date)=:yr GROUP BY m", $params);

            $otherIncome = safe_query($pdo,
                "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS m, COALESCE(SUM(amount),0) AS amt
                 FROM income_records WHERE YEAR(payment_date)=:yr GROUP BY m", $params);

            $expenses = safe_query($pdo,
                "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS m, COALESCE(SUM(net_amount),0) AS amt
                 FROM expenses WHERE status='Approved' AND YEAR(expense_date)=:yr GROUP BY m", $params);

            $feeMap = [];
            foreach ($feeIncome as $r) $feeMap[$r['m']] = (float) $r['amt'];
            $incMap = [];
            foreach ($otherIncome as $r) $incMap[$r['m']] = (float) $r['amt'];
            $expMap = [];
            foreach ($expenses as $r) $expMap[$r['m']] = (float) $r['amt'];

            $allMonths = array_unique(array_merge(array_keys($feeMap), array_keys($incMap), array_keys($expMap)));
            sort($allMonths);

            $result['rows'] = [];
            foreach ($allMonths as $m) {
                $fi = $feeMap[$m] ?? 0;
                $oi = $incMap[$m] ?? 0;
                $ti = $fi + $oi;
                $te = $expMap[$m] ?? 0;
                $result['rows'][] = [
                    'month' => $m,
                    'fee_income' => $fi,
                    'other_income' => $oi,
                    'total_income' => $ti,
                    'total_expenses' => $te,
                    'net_surplus_deficit' => $ti - $te,
                ];
            }
            $result['totals'] = [
                'fee_income' => array_sum(array_column($result['rows'], 'fee_income')),
                'other_income' => array_sum(array_column($result['rows'], 'other_income')),
                'total_income' => array_sum(array_column($result['rows'], 'total_income')),
                'total_expenses' => array_sum(array_column($result['rows'], 'total_expenses')),
                'net_surplus_deficit' => array_sum(array_column($result['rows'], 'net_surplus_deficit')),
            ];
            break;

        // ─── 27. Budget vs Actual ───
        case 27:
            $result['title'] = 'Budget vs Actual';
            $result['headers'] = ['Department', 'Budget Head', 'Annual Budget', 'Amount Used', 'Committed', 'Available', 'Usage %', 'Status'];
            $sql = "SELECT b.department, b.budget_head_name, b.annual_budget, b.amount_used, b.amount_committed, b.available_budget,
                    CASE WHEN b.annual_budget > 0 THEN ROUND((b.amount_used / b.annual_budget) * 100, 1) ELSE 0 END AS usage_pct,
                    CASE
                        WHEN b.annual_budget > 0 AND (b.amount_used / b.annual_budget * 100) >= b.alert_percentage THEN 'Over Budget'
                        WHEN b.annual_budget > 0 AND (b.amount_used / b.annual_budget * 100) >= (b.alert_percentage - 10) THEN 'Warning'
                        ELSE 'On Track'
                    END AS status
                    FROM budgets b
                    WHERE b.annual_budget > 0
                    ORDER BY b.department, b.budget_head_name";
            $result['rows'] = safe_query($pdo, $sql);
            $result['totals'] = [
                'annual_budget' => array_sum(array_column($result['rows'], 'annual_budget')),
                'amount_used' => array_sum(array_column($result['rows'], 'amount_used')),
                'amount_committed' => array_sum(array_column($result['rows'], 'amount_committed')),
                'available_budget' => array_sum(array_column($result['rows'], 'available_budget')),
            ];
            break;

        // ─── 28. Cash & Bank Position ───
        case 28:
            $result['title'] = 'Cash & Bank Position';
            $result['headers'] = ['Account Type', 'Account Name', 'Bank', 'Current Balance', 'Status'];
            $result['rows'] = [];

            $cashBal = safe_scalar($pdo, "SELECT COALESCE(SUM(CASE WHEN direction='Cr' THEN amount ELSE -amount END), 0) FROM cash_book");
            $result['rows'][] = [
                'account_type' => 'Cash',
                'account_name' => 'Main Cash Account',
                'bank' => '-',
                'current_balance' => $cashBal,
                'status' => $cashBal >= 0 ? 'Healthy' : 'Deficit',
            ];

            $bankRows = safe_query($pdo, "SELECT account_name, bank_name, current_balance FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
            foreach ($bankRows as $br) {
                $result['rows'][] = [
                    'account_type' => 'Bank',
                    'account_name' => $br['account_name'],
                    'bank' => $br['bank_name'],
                    'current_balance' => (float) $br['current_balance'],
                    'status' => (float) $br['current_balance'] >= 0 ? 'Healthy' : 'Deficit',
                ];
            }
            $result['totals'] = [
                'current_balance' => array_sum(array_column($result['rows'], 'current_balance')),
            ];
            break;

        // ─── 29. Department Expense ───
        case 29:
            $result['title'] = 'Department Expense';
            $result['headers'] = ['Department / Category', 'Expenses', 'Net Amount', 'Approved', 'Pending'];
            $where = "WHERE 1=1";
            $params = [];
            if ($filters['from']) { $where .= " AND e.expense_date >= :frm"; $params['frm'] = $filters['from']; }
            if ($filters['to']) { $where .= " AND e.expense_date <= :to"; $params['to'] = $filters['to']; }
            if ($filters['department']) { $where .= " AND ec.group_name = :dept"; $params['dept'] = $filters['department']; }
            $sql = "SELECT COALESCE(ec.group_name, ec.name, 'Uncategorized') AS department,
                    COUNT(*) AS expenses,
                    COALESCE(SUM(e.net_amount),0) AS net_amount,
                    COALESCE(SUM(CASE WHEN e.status='Approved' THEN e.net_amount ELSE 0 END),0) AS approved,
                    COALESCE(SUM(CASE WHEN e.status='Pending' THEN e.net_amount ELSE 0 END),0) AS pending
                    FROM expenses e
                    LEFT JOIN expense_categories ec ON ec.id = e.category_id
                    $where GROUP BY ec.group_name, ec.name ORDER BY net_amount DESC";
            $result['rows'] = safe_query($pdo, $sql, $params);
            $result['totals'] = [
                'expenses' => array_sum(array_column($result['rows'], 'expenses')),
                'net_amount' => array_sum(array_column($result['rows'], 'net_amount')),
                'approved' => array_sum(array_column($result['rows'], 'approved')),
                'pending' => array_sum(array_column($result['rows'], 'pending')),
            ];
            break;

        // ─── 30. Annual Financial Summary ───
        case 30:
            $result['title'] = 'Annual Financial Summary';
            $result['headers'] = ['Metric', 'Amount'];
            $year = $filters['year'] ?: date('Y');
            $params = ['yr' => $year];

            $feeIncome = safe_scalar($pdo, "SELECT COALESCE(SUM(net_amount),0) FROM fee_collections WHERE status='Active' AND YEAR(payment_date)=:yr", $params);
            $otherIncome = safe_scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM income_records WHERE YEAR(payment_date)=:yr", $params);
            $totalExpenses = safe_scalar($pdo, "SELECT COALESCE(SUM(net_amount),0) FROM expenses WHERE status='Approved' AND YEAR(expense_date)=:yr", $params);
            $totalPaid = safe_scalar($pdo, "SELECT COALESCE(SUM(net_amount),0) FROM fee_collections WHERE status='Active' AND YEAR(payment_date)=:yr", $params);
            $totalOutstanding = safe_scalar($pdo, "SELECT COALESCE(SUM(balance),0) FROM student_fee_accounts WHERE balance > 0");
            $totalSalaryPaid = safe_scalar($pdo, "SELECT COALESCE(SUM(pi.net_payout),0) FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.payroll_run_id WHERE pi.payment_status='Paid' AND YEAR(pr.generated_at)=:yr", $params);
            $assetValue = safe_scalar($pdo, "SELECT COALESCE(SUM(current_value),0) FROM fixed_assets WHERE status='Active'");
            $cashBalance = safe_scalar($pdo, "SELECT COALESCE(SUM(CASE WHEN direction='Cr' THEN amount ELSE -amount END), 0) FROM cash_book");
            $bankBalance = safe_scalar($pdo, "SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts WHERE is_active=1");

            $result['rows'] = [
                ['metric' => 'Total Fee Income', 'amount' => $feeIncome],
                ['metric' => 'Other Income', 'amount' => $otherIncome],
                ['metric' => 'Total Income', 'amount' => $feeIncome + $otherIncome],
                ['metric' => 'Total Expenses (excl. Salary)', 'amount' => $totalExpenses],
                ['metric' => 'Total Salary Paid', 'amount' => $totalSalaryPaid],
                ['metric' => 'Total Expenditure', 'amount' => $totalExpenses + $totalSalaryPaid],
                ['metric' => 'Net Surplus / (Deficit)', 'amount' => ($feeIncome + $otherIncome) - ($totalExpenses + $totalSalaryPaid)],
                ['metric' => 'Outstanding Fee Dues', 'amount' => $totalOutstanding],
                ['metric' => 'Fixed Assets (Current Value)', 'amount' => $assetValue],
                ['metric' => 'Cash Balance', 'amount' => $cashBalance],
                ['metric' => 'Bank Balance', 'amount' => $bankBalance],
                ['metric' => 'Total Liquid Assets', 'amount' => $cashBalance + $bankBalance],
            ];
            $result['totals'] = [
                'amount' => array_sum(array_column($result['rows'], 'amount')),
            ];
            break;

        default:
            $result['title'] = 'Select a Report';
            $result['headers'] = [];
            $result['rows'] = [];
            $result['totals'] = [];
    }

    return $result;
}

// ── Report column definitions for display formatting ──
function report_columns(int $reportId): array
{
    $currencyCols = [
        'total_amount', 'discount', 'late_fee', 'net', 'cash', 'upi', 'cheque', 'card', 'bank_transfer',
        'total_fee', 'total_paid', 'total_discount', 'total_late_fee', 'balance', 'total',
        'amount', 'gst_amount', 'net_amount', 'total_gross', 'total_deductions', 'total_net',
        'gross_amount', 'net_payout', 'paid_amount', 'bill_amount', 'purchase_rate', 'value',
        'receipts_in', 'payments_out', 'net_cash_flow', 'closing_balance',
        'reconciled_amount', 'unreconciled_amount', 'fee_income', 'other_income',
        'total_income', 'total_expenses', 'net_surplus_deficit', 'annual_budget',
        'amount_used', 'amount_committed', 'available_budget', 'accumulated_depreciation',
        'current_value', 'depreciation_amount', 'accumulated_after', 'book_value_after',
        'opening_quantity', 'current_quantity', 'reorder_level', 'deficit',
    ];
    $intCols = ['cnt', 'students', 'collections', 'expenses', 'employees', 'total_transactions',
        'reconciled', 'unreconciled', 'paid_count', 'pending_count', 'quantity', 'assignments',
        'paid', 'pending'];
    return ['currency' => $currencyCols, 'integer' => $intCols];
}

function report_totals(array $data, array $columns): array
{
    return $data['totals'] ?? [];
}

function export_csv(array $data, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, $data['headers']);
    foreach ($data['rows'] as $row) {
        $line = [];
        foreach ($data['headers'] as $h) {
            $key = strtolower(str_replace([' ', '.', '/', '-'], '_', $h));
            $val = $row[$key] ?? $row[str_replace(' ', '_', strtolower($h))] ?? $row[strtolower($h)] ?? '';
            $line[] = is_numeric($val) ? $val : html_entity_decode(strip_tags((string) $val));
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

// ── Fetch data for selected report ──
$reportData = ['headers' => [], 'rows' => [], 'title' => '', 'totals' => []];
if ($reportId > 0) {
    $reportData = report_data($pdo, $reportId, $filters);
}

// ── Handle CSV export ──
if ($export === 'csv' && $reportId > 0 && !empty($reportData['rows'])) {
    export_csv($reportData, $reportData['title']);
}

$allReports = get_all_reports();
if ($reportId > 0 && !isset($allReports[$reportId])) {
    $reportId = 0;
}
if ($reportId > 0 && empty($category)) {
    $category = find_report_category($reportId);
}
$activeCat = $category ?: array_key_first($categories);

$classList = get_class_options($pdo);
$vendorList = get_vendor_options($pdo);
$bankAccountList = get_bank_account_options($pdo);
$categoryList = get_category_options($pdo);
$payrollMonths = get_payroll_month_options($pdo);
$employeeList = get_employee_options($pdo);

$formatCols = report_columns($reportId);

$currentUser = admin_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reports & Analytics – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
    <style>
        .report-hero { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; padding:1.5rem; background:linear-gradient(135deg,#1e293b 0%,#334155 100%); border-radius:16px; color:#f8fafc; margin-bottom:1.5rem; }
        .report-hero h1 { margin:0; font-size:1.5rem; font-weight:700; }
        .report-hero p { margin:0; opacity:.7; font-size:.9rem; }
        .report-hero .hero-icon { font-size:2.5rem; }

        .cat-tabs { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem; padding-bottom:.75rem; border-bottom:1px solid #e2e8f0; }
        .cat-tab { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem 1rem; border-radius:10px; border:1px solid #e2e8f0; background:#fff; color:#475569; font-weight:600; font-size:.85rem; cursor:pointer; text-decoration:none; transition:all .15s; }
        .cat-tab:hover { background:#f1f5f9; border-color:#cbd5e1; }
        .cat-tab.active { background:#1e293b; color:#f8fafc; border-color:#1e293b; }

        .report-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:1rem; margin-bottom:2rem; }
        .report-card { display:flex; flex-direction:column; padding:1.25rem; background:#fff; border-radius:12px; border:2px solid #f1f5f9; text-decoration:none; color:#334155; transition:all .2s; cursor:pointer; }
        .report-card:hover { border-color:#2563eb; box-shadow:0 4px 14px rgba(37,99,235,.12); transform:translateY(-2px); }
        .report-card.selected { border-color:#2563eb; background:#eff6ff; }
        .report-card .rc-icon { font-size:1.75rem; margin-bottom:.5rem; }
        .report-card .rc-name { font-weight:700; font-size:.95rem; margin-bottom:.25rem; }
        .report-card .rc-desc { font-size:.8rem; color:#64748b; line-height:1.4; }

        .filter-panel { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:1.25rem; margin-bottom:1.5rem; }
        .filter-panel h3 { margin:0 0 .75rem 0; font-size:1rem; font-weight:700; color:#1e293b; }
        .filter-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px,1fr)); gap:.75rem; align-items:end; }
        .filter-grid label { font-size:.78rem; font-weight:600; color:#64748b; display:block; margin-bottom:.2rem; }
        .filter-grid input, .filter-grid select { width:100%; min-height:36px; padding:.4rem .65rem; border:1px solid #e2e8f0; border-radius:8px; font-size:.85rem; }
        .filter-grid input:focus, .filter-grid select:focus { border-color:#2563eb; outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.1); }

        .result-panel { background:#fff; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; }
        .result-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; padding:1rem 1.25rem; border-bottom:1px solid #f1f5f9; }
        .result-header h2 { margin:0; font-size:1.1rem; font-weight:700; color:#1e293b; }
        .result-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
        .result-actions .btn { font-size:.8rem; padding:.4rem .8rem; }

        .report-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .report-table th { text-align:left; padding:.6rem .75rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.03em; white-space:nowrap; }
        .report-table td { padding:.5rem .75rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .report-table tr:hover td { background:#f8fafc; }
        .report-table .total-row td { font-weight:700; background:#f1f5f9; border-top:2px solid #cbd5e1; }

        .td-currency { text-align:right; font-weight:500; font-variant-numeric:tabular-nums; }
        .td-integer { text-align:right; }
        .td-status-yes { color:#059669; font-weight:600; }
        .td-status-no { color:#94a3b8; }
        .td-direction-dr { color:#dc2626; font-weight:600; }
        .td-direction-cr { color:#059669; font-weight:600; }
        .td-status-approved { color:#059669; font-weight:600; }
        .td-status-pending { color:#d97706; font-weight:600; }
        .td-status-paid { color:#059669; font-weight:600; }
        .td-status-over-budget { color:#dc2626; font-weight:600; }
        .td-status-warning { color:#d97706; font-weight:600; }
        .td-status-on-track { color:#059669; font-weight:600; }
        .td-status-healthy { color:#059669; }
        .td-status-deficit { color:#dc2626; }

        .empty-state { text-align:center; padding:3rem 1rem; color:#94a3b8; }
        .empty-state .empty-icon { font-size:3rem; margin-bottom:.75rem; }
        .empty-state h3 { font-size:1.1rem; color:#64748b; margin-bottom:.25rem; }

        .no-print { }
        @media print {
            .no-print { display:none !important; }
            body { background:#fff; }
            .admin-layout { display:block; }
            .sidebar { display:none !important; }
            .admin-main { padding:0 !important; }
            .report-hero { background:#1e293b !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .cat-tabs { display:none !important; }
            .report-grid { display:none !important; }
            .filter-panel { display:none !important; }
            .report-table th { background:#e2e8f0 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .result-panel { border:none !important; box-shadow:none !important; }
            @page { margin:1.5cm; }
        }
        @media (max-width:768px) {
            .report-grid { grid-template-columns:1fr; }
            .filter-grid { grid-template-columns:1fr; }
            .cat-tabs { overflow-x:auto; flex-wrap:nowrap; -webkit-overflow-scrolling:touch; }
        }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">

        <div class="report-hero no-print">
            <div>
                <h1>Reports & Analytics</h1>
                <p>Comprehensive financial and operational reports for your institution.</p>
            </div>
            <div class="hero-icon">📈</div>
        </div>

        <nav class="cat-tabs no-print">
            <?php foreach ($categories as $catKey => $cat): ?>
                <a class="cat-tab<?= $activeCat === $catKey ? ' active' : '' ?>"
                   href="?cat=<?= e($catKey) ?><?= $reportId > 0 && find_report_category($reportId) === $catKey ? '&report=' . $reportId : '' ?>">
                    <?= $cat['icon'] ?> <?= e($cat['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($reportId === 0): ?>

            <div class="report-grid no-print">
                <?php foreach ($categories[$activeCat]['reports'] as $rid => $rinfo): ?>
                    <a class="report-card" href="?cat=<?= e($activeCat) ?>&report=<?= $rid ?>">
                        <div class="rc-icon"><?= $rinfo['icon'] ?></div>
                        <div class="rc-name"><?= e($rinfo['name']) ?></div>
                        <div class="rc-desc"><?= e($rinfo['desc']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="result-panel">
                <div class="empty-state">
                    <div class="empty-icon">📊</div>
                    <h3>Select a Report</h3>
                    <p>Choose a report category above, then click on a report card to generate it.</p>
                    <p style="margin-top:1rem;font-size:.85rem;">
                        Total: <?= count(get_all_reports()) ?> reports across <?= count($categories) ?> categories
                    </p>
                </div>
            </div>

        <?php else: ?>

            <div class="filter-panel no-print">
                <h3>🔍 Filters — <?= e($reportData['title']) ?></h3>
                <form method="get" class="filter-grid">
                    <input type="hidden" name="cat" value="<?= e($activeCat) ?>">
                    <input type="hidden" name="report" value="<?= $reportId ?>">

                    <?php
                    $showFrom = in_array($reportId, [1,2,3,4,5,6,7,9,10,11,13,14,15,16,17,23,26,29,30], true);
                    $showTo = in_array($reportId, [2,3,4,5,6,7,9,10,11,13,14,15,16,17,23,26,29], true);
                    $showClass = in_array($reportId, [2,3,5], true);
                    $showStudent = in_array($reportId, [2,3,4,7], true);
                    $showCategoryFilter = in_array($reportId, [9,23], true);
                    $showVendor = in_array($reportId, [11,12], true);
                    $showAccount = in_array($reportId, [14,16,17], true);
                    $showMonth = in_array($reportId, [18,19,20], true);
                    $showYear = in_array($reportId, [26,27,30], true);
                    $showStatus = in_array($reportId, [7,17], true);
                    $showDepartment = in_array($reportId, [19,29], true);
                    ?>

                    <?php if ($showFrom): ?>
                        <div>
                            <label for="from"><?= in_array($reportId, [1,9], true) ? 'Date' : 'From Date' ?></label>
                            <input type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
                        </div>
                    <?php endif; ?>

                    <?php if ($showTo && $reportId !== 1 && $reportId !== 9): ?>
                        <div>
                            <label for="to">To Date</label>
                            <input type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
                        </div>
                    <?php endif; ?>

                    <?php if ($showYear): ?>
                        <div>
                            <label for="year">Year</label>
                            <select id="year" name="year">
                                <?php for ($y = (int) date('Y'); $y >= 2020; $y--): ?>
                                    <option value="<?= $y ?>" <?= ($filters['year'] ?: date('Y')) == $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($showMonth): ?>
                        <div>
                            <label for="month">Payroll Month</label>
                            <select id="month" name="month">
                                <option value="">All Months</option>
                                <?php foreach ($payrollMonths as $pm): ?>
                                    <option value="<?= e($pm['month_label']) ?>" <?= $filters['month'] === $pm['month_label'] ? 'selected' : '' ?>><?= e($pm['month_label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($showClass): ?>
                        <div>
                            <label for="class">Class</label>
                            <select id="class" name="class">
                                <option value="">All Classes</option>
                                <?php foreach ($classList as $cls): ?>
                                    <option value="<?= e($cls) ?>" <?= $filters['class'] === $cls ? 'selected' : '' ?>><?= e($cls) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($showStudent): ?>
                        <div>
                            <label for="student"><?= $reportId === 7 ? 'Student Name' : 'Student Name / Admission No' ?></label>
                            <input type="text" id="student" name="student" value="<?= e($filters['student']) ?>" placeholder="Search...">
                        </div>
                    <?php endif; ?>

                    <?php if ($showVendor): ?>
                        <div>
                            <label for="vendor">Vendor</label>
                            <select id="vendor" name="vendor">
                                <option value="">All Vendors</option>
                                <?php foreach ($vendorList as $v): ?>
                                    <option value="<?= $v['id'] ?>" <?= $filters['vendor'] == $v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($showAccount): ?>
                        <div>
                            <label for="account">Bank Account</label>
                            <select id="account" name="account">
                                <option value="">All Accounts</option>
                                <?php foreach ($bankAccountList as $ba): ?>
                                    <option value="<?= $ba['id'] ?>" <?= $filters['account'] == $ba['id'] ? 'selected' : '' ?>><?= e($ba['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($showCategoryFilter): ?>
                        <div>
                            <label for="category_filter"><?= $reportId === 23 ? 'Item' : 'Category' ?></label>
                            <select id="category_filter" name="category_filter">
                                <option value="">All</option>
                                <?php foreach ($categoryList as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $filters['category'] == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($showDepartment): ?>
                        <div>
                            <label for="department">Department</label>
                            <input type="text" id="department" name="department" value="<?= e($filters['department']) ?>" placeholder="e.g. Admin, Academics">
                        </div>
                    <?php endif; ?>

                    <?php if ($showStatus && $reportId === 7): ?>
                        <div>
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="">All</option>
                                <option value="Active" <?= $filters['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Expired" <?= $filters['status'] === 'Expired' ? 'selected' : '' ?>>Expired</option>
                                <option value="Cancelled" <?= $filters['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($showStatus && $reportId === 17): ?>
                        <div>
                            <label for="status">Cheque Status</label>
                            <select id="status" name="status">
                                <option value="">All</option>
                                <option value="Issued" <?= $filters['status'] === 'Issued' ? 'selected' : '' ?>>Issued</option>
                                <option value="Cleared" <?= $filters['status'] === 'Cleared' ? 'selected' : '' ?>>Cleared</option>
                                <option value="Bounced" <?= $filters['status'] === 'Bounced' ? 'selected' : '' ?>>Bounced</option>
                                <option value="Cancelled" <?= $filters['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                <option value="Pending" <?= $filters['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex;gap:.5rem;align-items:end;padding-top:1rem;">
                        <button type="submit" class="btn">Apply Filters</button>
                        <?php if (!empty(array_filter($filters))): ?>
                            <a href="?cat=<?= e($activeCat) ?>&report=<?= $reportId ?>" class="btn btn-soft">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="result-panel">
                <div class="result-header">
                    <h2><?= e($reportData['title']) ?></h2>
                    <div class="result-actions no-print">
                        <?php
                        $qs = http_build_query(array_merge(
                            ['cat' => $activeCat, 'report' => $reportId],
                            array_filter($filters)
                        ));
                        ?>
                        <a href="?<?= e($qs) ?>&export=csv" class="btn btn-soft">↓ CSV</a>
                        <button class="btn btn-soft" onclick="window.print()">🖨 Print</button>
                        <a href="?cat=<?= e($activeCat) ?>" class="btn btn-soft">← Back</a>
                    </div>
                </div>

                <?php if (empty($reportData['rows'])): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <h3>No Data Found</h3>
                        <p>No records match the selected filters. Try adjusting your criteria.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <?php foreach ($reportData['headers'] as $h): ?>
                                        <th><?= e($h) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['rows'] as $row): ?>
                                    <tr>
                                        <?php foreach ($reportData['headers'] as $h): ?>
                                            <?php
                                            $key = strtolower(str_replace([' ', '.', '/', '-'], '_', $h));
                                            $val = $row[$key] ?? $row[str_replace(' ', '_', strtolower($h))] ?? $row[strtolower($h)] ?? '—';
                                            $cellClass = '';
                                            $cellStyle = '';

                                            $isCurrency = in_array($key, $formatCols['currency'], true);
                                            $isInteger = in_array($key, $formatCols['integer'], true);

                                            if ($isCurrency && is_numeric($val)) {
                                                $cellClass = 'td-currency';
                                                $val = '₹ ' . number_format((float) $val, 2);
                                            } elseif ($isInteger && is_numeric($val)) {
                                                $cellClass = 'td-integer';
                                                $val = number_format((int) $val);
                                            } elseif ($key === 'direction') {
                                                $ucfirst = ucfirst((string) $val);
                                                $cellClass = $val === 'Dr' ? 'td-direction-dr' : ($val === 'Cr' ? 'td-direction-cr' : '');
                                                $val = $ucfirst;
                                            } elseif ($key === 'reconciled' || $key === 'emi_allowed') {
                                                $val = ((int) $val === 1) ? 'Yes' : 'No';
                                                $cellClass = (int) $val === 1 ? 'td-status-yes' : 'td-status-no';
                                            } elseif ($key === 'status') {
                                                $lower = strtolower((string) $val);
                                                if (in_array($lower, ['approved', 'active', 'paid', 'completed', 'healthy'], true)) {
                                                    $cellClass = 'td-status-' . $lower;
                                                } elseif (in_array($lower, ['pending', 'partial', 'overdue', 'warning'], true)) {
                                                    $cellClass = 'td-status-pending';
                                                } elseif (in_array($lower, ['rejected', 'cancelled', 'void', 'closed', 'inactive', 'deficit', 'bounced', 'disposed', 'expired'], true)) {
                                                    $cellClass = 'td-status-over-budget';
                                                } elseif ($lower === 'on track') {
                                                    $cellClass = 'td-status-on-track';
                                                } elseif ($lower === 'over budget') {
                                                    $cellClass = 'td-status-over-budget';
                                                } elseif ($lower === 'draft') {
                                                    $cellClass = 'td-status-pending';
                                                }
                                            } elseif ($key === 'usage_pct') {
                                                $pct = (float) $val;
                                                if ($pct >= 100) $cellClass = 'td-status-over-budget';
                                                elseif ($pct >= 80) $cellClass = 'td-status-pending';
                                                else $cellClass = 'td-status-on-track';
                                                $val = number_format($pct, 1) . '%';
                                            }
                                            ?>
                                            <td class="<?= $cellClass ?>" style="<?= $cellStyle ?>"><?= e((string) $val) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if (!empty($reportData['totals'])): ?>
                            <tfoot>
                                <tr class="total-row">
                                    <?php foreach ($reportData['headers'] as $idx => $h): ?>
                                        <?php
                                        $key = strtolower(str_replace([' ', '.', '/', '-'], '_', $h));
                                        $val = '';
                                        $cellClass = 'td-currency';

                                        $firstKey = $key;
                                        $allCurrencyKeys = $formatCols['currency'];
                                        $allIntKeys = $formatCols['integer'];

                                        $foundTotal = false;
                                        if (isset($reportData['totals'][$key])) {
                                            $val = $reportData['totals'][$key];
                                            $foundTotal = true;
                                        } else {
                                            foreach ($reportData['totals'] as $tk => $tv) {
                                                if (str_contains($key, $tk) || str_contains($tk, str_replace('_amount', '', $key))) {
                                                    $val = $tv;
                                                    $foundTotal = true;
                                                    break;
                                                }
                                            }
                                        }

                                        if (!$foundTotal && $idx === 0) {
                                            $val = 'TOTAL';
                                            $cellClass = '';
                                        }

                                        if ($foundTotal && is_numeric($val)) {
                                            if (in_array($key, $allIntKeys, true)) {
                                                $cellClass = 'td-integer';
                                                $val = number_format((int) $val);
                                            } else {
                                                $cellClass = 'td-currency';
                                                $val = '₹ ' . number_format((float) $val, 2);
                                            }
                                        }
                                        ?>
                                        <td class="<?= $cellClass ?>"><?= $val !== '' ? (is_string($val) ? e($val) : $val) : '' ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>

                    <div style="padding:.75rem 1.25rem;border-top:1px solid #f1f5f9;font-size:.8rem;color:#94a3b8;">
                        <?= count($reportData['rows']) ?> record<?= count($reportData['rows']) !== 1 ? 's' : '' ?>
                        generated at <?= date('d M Y, h:i A') ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </main>
</div>
<script src="../assets/erp.js?v=<?= filemtime(dirname(__DIR__) . '/assets/erp.js') ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
