<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$pdo = $GLOBALS['pdo'];
$pageTitle = 'Cash & Bank Management';
$error = '';
$success = '';

$activeTab = max(1, min(4, (int) ($_GET['tab'] ?? 1)));

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank_name VARCHAR(100) NOT NULL,
        account_name VARCHAR(100) NOT NULL,
        account_number VARCHAR(50) NOT NULL,
        ifsc_code VARCHAR(20),
        branch VARCHAR(100),
        account_type VARCHAR(50) DEFAULT 'Savings',
        opening_balance DECIMAL(12,2) DEFAULT 0,
        current_balance DECIMAL(12,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_book (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        transaction_type ENUM('Receipt','Payment','Opening','Transfer-In','Transfer-Out') NOT NULL,
        reference_type VARCHAR(50),
        reference_id INT,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL,
        direction ENUM('Dr','Cr') NOT NULL,
        balance DECIMAL(12,2) DEFAULT 0,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_book (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank_account_id INT NOT NULL,
        transaction_date DATE NOT NULL,
        transaction_type ENUM('Deposit','Withdrawal','Transfer-In','Transfer-Out','Opening','Expense','Salary') NOT NULL,
        reference_type VARCHAR(50),
        reference_id INT,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL,
        direction ENUM('Dr','Cr') NOT NULL,
        balance DECIMAL(12,2) DEFAULT 0,
        reconciled TINYINT(1) DEFAULT 0,
        reconciliation_date DATE,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

// ── Helper functions ──
function cash_balance(PDO $pdo): float
{
    $row = $pdo->query("SELECT balance FROM cash_book ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (float) $row['balance'];
    }
    return (float) $pdo->query("SELECT COALESCE(SUM(CASE WHEN direction='Cr' THEN amount ELSE -amount END), 0) FROM cash_book")->fetchColumn();
}

function bank_balance(PDO $pdo, int $accountId): float
{
    $row = $pdo->prepare("SELECT balance FROM bank_book WHERE bank_account_id = ? ORDER BY id DESC LIMIT 1");
    $row->execute([$accountId]);
    $last = $row->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        return (float) $last['balance'];
    }
    $op = $pdo->prepare("SELECT COALESCE(opening_balance, 0) FROM bank_accounts WHERE id = ?");
    $op->execute([$accountId]);
    return (float) $op->fetchColumn();
}

function recalculate_cash_balances(PDO $pdo): void
{
    $entries = $pdo->query("SELECT id, amount, direction FROM cash_book ORDER BY transaction_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $bal = 0.0;
    $stmt = $pdo->prepare("UPDATE cash_book SET balance = ? WHERE id = ?");
    foreach ($entries as $e) {
        $bal = $e['direction'] === 'Cr' ? $bal + (float) $e['amount'] : $bal - (float) $e['amount'];
        $stmt->execute([$bal, (int) $e['id']]);
    }
}

function recalculate_bank_balances(PDO $pdo, int $accountId): void
{
    $op = $pdo->prepare("SELECT COALESCE(opening_balance, 0) FROM bank_accounts WHERE id = ?");
    $op->execute([$accountId]);
    $opening = (float) $op->fetchColumn();
    $entries = $pdo->prepare("SELECT id, amount, direction FROM bank_book WHERE bank_account_id = ? ORDER BY transaction_date ASC, id ASC");
    $entries->execute([$accountId]);
    $rows = $entries->fetchAll(PDO::FETCH_ASSOC);
    $bal = $opening;
    $stmt = $pdo->prepare("UPDATE bank_book SET balance = ? WHERE id = ?");
    foreach ($rows as $e) {
        $bal = $e['direction'] === 'Cr' ? $bal + (float) $e['amount'] : $bal - (float) $e['amount'];
        $stmt->execute([$bal, (int) $e['id']]);
    }
    $upd = $pdo->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
    $upd->execute([$bal, $accountId]);
}

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'add_cash_entry') {
            $txnDate = trim((string) ($_POST['transaction_date'] ?? date('Y-m-d')));
            $txnType = trim((string) ($_POST['transaction_type'] ?? 'Receipt'));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $direction = trim((string) ($_POST['direction'] ?? 'Cr'));
            $allowedTypes = ['Receipt', 'Payment', 'Opening', 'Transfer-In', 'Transfer-Out'];
            if (!in_array($txnType, $allowedTypes, true)) throw new \RuntimeException('Invalid transaction type.');
            if ($direction !== 'Dr' && $direction !== 'Cr') throw new \RuntimeException('Direction must be Dr or Cr.');
            if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero.');
            $lastBal = (float) $pdo->query("SELECT COALESCE(balance, 0) FROM cash_book ORDER BY id DESC LIMIT 1")->fetchColumn();
            $newBal = $direction === 'Cr' ? $lastBal + $amount : $lastBal - $amount;
            $pdo->prepare("INSERT INTO cash_book (transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$txnDate, $txnType, $desc, $amount, $direction, $newBal, (int) $user['id']]);
            $success = 'Cash entry added successfully.';
        }

        if ($action === 'add_bank_entry') {
            $baId = (int) ($_POST['bank_account_id'] ?? 0);
            $txnDate = trim((string) ($_POST['transaction_date'] ?? date('Y-m-d')));
            $txnType = trim((string) ($_POST['transaction_type'] ?? 'Deposit'));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $direction = trim((string) ($_POST['direction'] ?? 'Cr'));
            $allowedTypes = ['Deposit', 'Withdrawal', 'Transfer-In', 'Transfer-Out', 'Opening', 'Expense', 'Salary'];
            if (!in_array($txnType, $allowedTypes, true)) throw new \RuntimeException('Invalid transaction type.');
            if ($baId <= 0) throw new \RuntimeException('Select a bank account.');
            if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero.');
            if ($direction !== 'Dr' && $direction !== 'Cr') throw new \RuntimeException('Direction must be Dr or Cr.');
            $lastBal = bank_balance($pdo, $baId);
            $newBal = $direction === 'Cr' ? $lastBal + $amount : $lastBal - $amount;
            $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$baId, $txnDate, $txnType, $desc, $amount, $direction, $newBal, (int) $user['id']]);
            recalculate_bank_balances($pdo, $baId);
            $success = 'Bank entry added successfully.';
        }

        if ($action === 'create_bank_account') {
            $bankName = trim((string) ($_POST['bank_name'] ?? ''));
            $accName = trim((string) ($_POST['account_name'] ?? ''));
            $accNo = trim((string) ($_POST['account_number'] ?? ''));
            $ifsc = trim((string) ($_POST['ifsc_code'] ?? ''));
            $branch = trim((string) ($_POST['branch'] ?? ''));
            $accType = trim((string) ($_POST['account_type'] ?? 'Savings'));
            $opening = (float) ($_POST['opening_balance'] ?? 0);
            if ($bankName === '' || $accName === '' || $accNo === '') throw new \RuntimeException('Bank name, account name, and account number are required.');
            $pdo->prepare("INSERT INTO bank_accounts (bank_name, account_name, account_number, ifsc_code, branch, account_type, opening_balance, current_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$bankName, $accName, $accNo, $ifsc, $branch, $accType, $opening, $opening]);
            if ($opening > 0) {
                $baId = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, CURDATE(), 'Opening', 'Opening balance', ?, 'Cr', ?, ?)")
                    ->execute([$baId, $opening, $opening, (int) $user['id']]);
            }
            $success = 'Bank account created successfully.';
        }

        if ($action === 'update_bank_account') {
            $baId = (int) ($_POST['id'] ?? 0);
            $bankName = trim((string) ($_POST['bank_name'] ?? ''));
            $accName = trim((string) ($_POST['account_name'] ?? ''));
            $accNo = trim((string) ($_POST['account_number'] ?? ''));
            $ifsc = trim((string) ($_POST['ifsc_code'] ?? ''));
            $branch = trim((string) ($_POST['branch'] ?? ''));
            $accType = trim((string) ($_POST['account_type'] ?? 'Savings'));
            if ($bankName === '' || $accName === '' || $accNo === '') throw new \RuntimeException('Bank name, account name, and account number are required.');
            $pdo->prepare("UPDATE bank_accounts SET bank_name=?, account_name=?, account_number=?, ifsc_code=?, branch=?, account_type=?, updated_at=NOW() WHERE id=?")
                ->execute([$bankName, $accName, $accNo, $ifsc, $branch, $accType, $baId]);
            $success = 'Bank account updated successfully.';
        }

        if ($action === 'delete_bank_account') {
            $baId = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE bank_accounts SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$baId]);
            $success = 'Bank account deactivated.';
        }

    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: cash-bank.php?tab=' . $activeTab . '&success=' . urlencode($success));
        exit;
    }
}

// ── Handle success flash ──
if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}

// ── Fetch data ──

// Bank accounts
$bankAccounts = $pdo->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name")->fetchAll(PDO::FETCH_ASSOC);

// Cash balance
$cashBal = cash_balance($pdo);

// Total bank balance
$totalBankBal = 0.0;
foreach ($bankAccounts as $ba) {
    $totalBankBal += (float) $ba['current_balance'];
}

// Today's collections
$todayCollections = 0.0;
try {
    $todayCollections = (float) $pdo->query("SELECT COALESCE(SUM(net_amount), 0) FROM fee_collections WHERE payment_date = CURDATE() AND status = 'Active'")->fetchColumn();
} catch (Throwable) {}

// Today's expenses
$todayExpenses = 0.0;
try {
    $todayExpenses = (float) $pdo->query("SELECT COALESCE(SUM(net_amount), 0) FROM expenses WHERE payment_date = CURDATE() AND status IN ('Approved', 'Pending')")->fetchColumn();
} catch (Throwable) {}

// Pending bank entries
$pendingBankEntries = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bank_book WHERE reconciled = 0 AND bank_account_id IN (SELECT id FROM bank_accounts WHERE is_active = 1)");
    $stmt->execute();
    $pendingBankEntries = (int) $stmt->fetchColumn();
} catch (Throwable) {}

// Recent transactions (combined cash + bank, last 20)
$recentTransactions = [];
try {
    $cashRows = $pdo->query("SELECT id, transaction_date, transaction_type, description, amount, direction, balance, 'cash' AS source FROM cash_book ORDER BY transaction_date DESC, id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $bankRows = $pdo->query("SELECT bb.id, bb.transaction_date, bb.transaction_type, bb.description, bb.amount, bb.direction, bb.balance, ba.account_name, 'bank' AS source FROM bank_book bb JOIN bank_accounts ba ON ba.id = bb.bank_account_id ORDER BY bb.transaction_date DESC, bb.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $all = array_merge($cashRows, $bankRows);
    usort($all, function ($a, $b) {
        $dateCmp = strcmp($b['transaction_date'], $a['transaction_date']);
        if ($dateCmp !== 0) return $dateCmp;
        return (int) ($b['id'] ?? 0) - (int) ($a['id'] ?? 0);
    });
    $recentTransactions = array_slice($all, 0, 20);
} catch (Throwable) {}

// Cash Book pagination
$cbPage = max(1, (int) ($_GET['cb_p'] ?? 1));
$cbLimit = 25;
$cbOffset = ($cbPage - 1) * $cbLimit;
$cbWhere = [];
$cbParams = [];
$cbFrom = trim((string) ($_GET['cb_from'] ?? ''));
$cbTo = trim((string) ($_GET['cb_to'] ?? ''));
if ($cbFrom !== '') { $cbWhere[] = 'transaction_date >= :cb_from'; $cbParams['cb_from'] = $cbFrom; }
if ($cbTo !== '') { $cbWhere[] = 'transaction_date <= :cb_to'; $cbParams['cb_to'] = $cbTo; }
$cbWhereSql = count($cbWhere) > 0 ? ' WHERE ' . implode(' AND ', $cbWhere) : '';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM cash_book" . $cbWhereSql);
$stmt->execute($cbParams);
$cbCount = (int) $stmt->fetchColumn();
$cbStmt = $pdo->prepare("SELECT * FROM cash_book" . $cbWhereSql . " ORDER BY transaction_date DESC, id DESC LIMIT :cblim OFFSET :cboff");
foreach ($cbParams as $k => $v) $cbStmt->bindValue(':' . $k, $v);
$cbStmt->bindValue(':cblim', $cbLimit, PDO::PARAM_INT);
$cbStmt->bindValue(':cboff', $cbOffset, PDO::PARAM_INT);
$cbStmt->execute();
$cashBookEntries = $cbStmt->fetchAll(PDO::FETCH_ASSOC);
$cbTotalPages = max(1, (int) ceil($cbCount / $cbLimit));

// Bank Book pagination
$bbPage = max(1, (int) ($_GET['bb_p'] ?? 1));
$bbLimit = 25;
$bbOffset = ($bbPage - 1) * $bbLimit;
$bbWhere = [];
$bbParams = [];
$bbFilterAcc = (int) ($_GET['bb_account'] ?? 0);
$bbFrom = trim((string) ($_GET['bb_from'] ?? ''));
$bbTo = trim((string) ($_GET['bb_to'] ?? ''));
$bbSearch = trim((string) ($_GET['bb_q'] ?? ''));
if ($bbFilterAcc > 0) { $bbWhere[] = 'bb.bank_account_id = :bb_acc'; $bbParams['bb_acc'] = $bbFilterAcc; }
if ($bbFrom !== '') { $bbWhere[] = 'bb.transaction_date >= :bb_from'; $bbParams['bb_from'] = $bbFrom; }
if ($bbTo !== '') { $bbWhere[] = 'bb.transaction_date <= :bb_to'; $bbParams['bb_to'] = $bbTo; }
if ($bbSearch !== '') { $bbWhere[] = 'bb.description LIKE :bb_q'; $bbParams['bb_q'] = '%' . $bbSearch . '%'; }
$bbWhereSql = count($bbWhere) > 0 ? ' WHERE ' . implode(' AND ', $bbWhere) : '';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bank_book bb" . $bbWhereSql);
$stmt->execute($bbParams);
$bbCount = (int) $stmt->fetchColumn();
$bbStmt = $pdo->prepare("SELECT bb.*, ba.account_name, ba.bank_name FROM bank_book bb JOIN bank_accounts ba ON ba.id = bb.bank_account_id" . $bbWhereSql . " ORDER BY bb.transaction_date DESC, bb.id DESC LIMIT :bblim OFFSET :bboff");
foreach ($bbParams as $k => $v) $bbStmt->bindValue(':' . $k, $v);
$bbStmt->bindValue(':bblim', $bbLimit, PDO::PARAM_INT);
$bbStmt->bindValue(':bboff', $bbOffset, PDO::PARAM_INT);
$bbStmt->execute();
$bankBookEntries = $bbStmt->fetchAll(PDO::FETCH_ASSOC);
$bbTotalPages = max(1, (int) ceil($bbCount / $bbLimit));

// Selected bank account info for tab 4
$selectedBankAccount = null;
if ($bbFilterAcc > 0) {
    foreach ($bankAccounts as $ba) {
        if ((int) $ba['id'] === $bbFilterAcc) {
            $selectedBankAccount = $ba;
            break;
        }
    }
}
$bbOpeningBal = 0.0;
$bbCurrentBal = 0.0;
$bbReconciledBal = 0.0;
if ($selectedBankAccount) {
    $bbOpeningBal = (float) $selectedBankAccount['opening_balance'];
    $bbCurrentBal = (float) $selectedBankAccount['current_balance'];
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='Cr' THEN amount ELSE -amount END), 0) FROM bank_book WHERE bank_account_id = ? AND reconciled = 1");
        $stmt->execute([$bbFilterAcc]);
        $bbReconciledBal = $bbOpeningBal + (float) $stmt->fetchColumn();
    } catch (Throwable) {
        $bbReconciledBal = $bbCurrentBal;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Cash &amp; Bank – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .summary-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
        .summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.1rem 1.25rem;transition:box-shadow .15s ease,transform .15s ease}
        .summary-card:hover{box-shadow:0 4px 6px -1px rgba(16,24,40,.1),0 2px 4px -1px rgba(16,24,40,.05);transform:translateY(-1px)}
        .summary-card .sc-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
        .summary-card .sc-value{font-size:1.45rem;font-weight:700;margin-top:.3rem;line-height:1;font-variant-numeric:tabular-nums;white-space:nowrap}
        .summary-card .sc-sub{font-size:.78rem;color:#94a3b8;margin-top:.2rem}
        .summary-card.blue{border-left:4px solid #2563eb}
        .summary-card.blue .sc-value{color:#2563eb}
        .summary-card.green{border-left:4px solid #10b981}
        .summary-card.green .sc-value{color:#10b981}
        .summary-card.purple{border-left:4px solid #8b5cf6}
        .summary-card.purple .sc-value{color:#8b5cf6}
        .summary-card.amber{border-left:4px solid #f59e0b}
        .summary-card.amber .sc-value{color:#f59e0b}
        .summary-card.rose{border-left:4px solid #f43f5e}
        .summary-card.rose .sc-value{color:#f43f5e}
        .balance-bar{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem}
        .balance-chip{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.75rem 1rem;flex:1;min-width:160px}
        .balance-chip .bl-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
        .balance-chip .bl-value{font-size:1.15rem;font-weight:700;margin-top:.15rem;color:#0f172a}
        .app-table{width:100%;border-collapse:collapse;font-size:.875rem}
        .app-table th{text-align:left;padding:.65rem .6rem;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;white-space:nowrap}
        .app-table td{padding:.65rem .6rem;border-bottom:1px solid #e2e8f0;vertical-align:middle}
        .app-table tbody tr:hover td{background:#f8fafc}
        .app-table tbody tr:last-child td{border-bottom:none}
        .field-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem}
        .modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:1.5rem;background:rgba(0,0,0,.5);backdrop-filter:blur(3px);z-index:1055}
        .modal-backdrop.show{display:flex}
        .modal-backdrop .modal{position:relative;display:block;top:auto;left:auto;width:min(680px,100%);height:auto;max-height:90vh;margin:0;overflow:auto;padding:1.25rem;border-radius:12px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)}
        .modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1rem}
        .icon-btn{border:1px solid #e2e8f0;background:#fff;color:#1e293b;border-radius:.375rem;min-height:36px;padding:.45rem .75rem;cursor:pointer;font-weight:600;font-size:.865rem}
        .icon-btn:hover{background:#f1f3f5}
        .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.3em .6em;border-radius:999px;font-size:.75rem;font-weight:600}
        .badge-receipt{background:#d1fae5;color:#065f46}
        .badge-payment{background:#fee2e2;color:#991b1b}
        .badge-deposit{background:#dbeafe;color:#1e40af}
        .badge-withdrawal{background:#fef3c7;color:#92400e}
        .badge-transfer-in{background:#e0e7ff;color:#3730a3}
        .badge-transfer-out{background:#fce7f3;color:#9d174d}
        .badge-opening{background:#f1f5f9;color:#475569}
        .badge-expense{background:#ffedd5;color:#9a3412}
        .badge-salary{background:#ede9fe;color:#5b21b6}
        .badge-reconciled{background:#d1fae5;color:#065f46}
        .badge-pending{background:#fef3c7;color:#92400e}
        .badge-active{background:#d1fae5;color:#065f46}
        .badge-inactive{background:#f8d7da;color:#842029}
        .amount-dr{color:#dc2626;font-weight:600}
        .amount-cr{color:#059669;font-weight:600}
        .text-right{text-align:right}
        .text-center{text-align:center}
        .btn-sm{min-height:36px;padding:.4rem .85rem;font-size:.82rem;border-radius:8px}
        .filter-row{display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem}
        .filter-row label{font-size:.8rem;margin-bottom:.2rem}
        .filter-row input,.filter-row select{min-height:38px;padding:.45rem .7rem;border-radius:8px;font-size:.85rem;width:auto}
        .pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1rem}
        .page-links{display:flex;gap:.4rem;flex-wrap:wrap}
        .page-links a,.page-links span{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 .5rem;border:1px solid #e2e8f0;border-radius:.375rem;font-size:.8rem;font-weight:500;color:#212529}
        .page-links a:hover{background:#e7f1ff;border-color:#0d6efd;color:#0d6efd}
        .page-links .current{background:#0d6efd;border-color:#0d6efd;color:#fff}
        @media(max-width:768px){.summary-cards{grid-template-columns:1fr 1fr}.field-grid{grid-template-columns:1fr}}
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
                    <h1>Cash &amp; Bank Management</h1>
                    <p>Manage cash book, bank accounts, bank book, and fund transfers.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button class="btn btn-sm" onclick="openModal('transferModal')">Transfer Cash to Bank</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="tab-bar">
            <a href="?tab=1" class="<?= $activeTab === 1 ? 'active' : '' ?>">Dashboard</a>
            <a href="?tab=2" class="<?= $activeTab === 2 ? 'active' : '' ?>">Cash Book</a>
            <a href="?tab=3" class="<?= $activeTab === 3 ? 'active' : '' ?>">Bank Accounts</a>
            <a href="?tab=4" class="<?= $activeTab === 4 ? 'active' : '' ?>">Bank Book</a>
        </div>

        <!-- ═══════════════════ TAB 1: DASHBOARD ═══════════════════ -->
        <div style="display:<?= $activeTab === 1 ? 'block' : 'none' ?>">

            <div class="summary-cards">
                <div class="summary-card blue">
                    <div class="sc-label">Cash Balance</div>
                    <div class="sc-value">₹ <?= number_format($cashBal, 2) ?></div>
                    <div class="sc-sub">Cash in Hand</div>
                </div>
                <div class="summary-card green">
                    <div class="sc-label">Total Bank Balance</div>
                    <div class="sc-value">₹ <?= number_format($totalBankBal, 2) ?></div>
                    <div class="sc-sub">Across <?= count($bankAccounts) ?> account(s)</div>
                </div>
                <div class="summary-card purple">
                    <div class="sc-label">Today's Collections</div>
                    <div class="sc-value">₹ <?= number_format($todayCollections, 2) ?></div>
                    <div class="sc-sub"><?= date('d M Y') ?></div>
                </div>
                <div class="summary-card amber">
                    <div class="sc-label">Today's Expenses</div>
                    <div class="sc-value">₹ <?= number_format($todayExpenses, 2) ?></div>
                    <div class="sc-sub"><?= date('d M Y') ?></div>
                </div>
                <div class="summary-card rose">
                    <div class="sc-label">Pending Reconciliation</div>
                    <div class="sc-value"><?= $pendingBankEntries ?></div>
                    <div class="sc-sub">Bank entries pending</div>
                </div>
            </div>

            <div class="panel" style="overflow:auto;">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                    <h3 style="margin:0;font-size:1rem;">Recent Transactions</h3>
                </div>
                <?php if (empty($recentTransactions)): ?>
                    <div style="padding:2rem;text-align:center;color:#94a3b8;">No recent transactions.</div>
                <?php else: ?>
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Source</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th class="text-right">Amount</th>
                                <th class="text-center">Dr/Cr</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $txn): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= e($txn['transaction_date']) ?></td>
                                    <td>
                                        <?php if (($txn['source'] ?? '') === 'cash'): ?>
                                            <span class="badge badge-deposit">Cash</span>
                                        <?php else: ?>
                                            <span class="badge badge-transfer-in"><?= e($txn['account_name'] ?? 'Bank') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-<?= strtolower(str_replace('-', '', $txn['transaction_type'])) ?>"><?= e($txn['transaction_type']) ?></span></td>
                                    <td style="max-width:220px;white-space:normal;"><?= e($txn['description'] ?? '—') ?></td>
                                    <td class="text-right">₹ <?= number_format((float) $txn['amount'], 2) ?></td>
                                    <td class="text-center"><span class="<?= $txn['direction'] === 'Dr' ? 'amount-dr' : 'amount-cr' ?>"><?= e($txn['direction']) ?></span></td>
                                    <td class="text-right" style="font-weight:600;">₹ <?= number_format((float) $txn['balance'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════ TAB 2: CASH BOOK ═══════════════════ -->
        <div style="display:<?= $activeTab === 2 ? 'block' : 'none' ?>">

            <div class="balance-bar">
                <div class="balance-chip">
                    <div class="bl-label">Current Cash Balance</div>
                    <div class="bl-value">₹ <?= number_format($cashBal, 2) ?></div>
                </div>
                <div class="balance-chip">
                    <div class="bl-label">Total Receipts</div>
                    <div class="bl-value" style="color:#059669;">₹ <?= number_format((float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE direction = 'Cr'")->fetchColumn(), 2) ?></div>
                </div>
                <div class="balance-chip">
                    <div class="bl-label">Total Payments</div>
                    <div class="bl-value" style="color:#dc2626;">₹ <?= number_format((float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cash_book WHERE direction = 'Dr'")->fetchColumn(), 2) ?></div>
                </div>
            </div>

            <div class="panel">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                    <div class="toolbar">
                        <h3 style="margin:0;font-size:1rem;">Cash Book</h3>
                        <button class="btn btn-sm" onclick="openModal('cashEntryModal')">+ Add Cash Entry</button>
                    </div>
                </div>

                <div style="padding:.75rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                    <form method="get" class="filter-row" style="margin-bottom:0;">
                        <input type="hidden" name="tab" value="2">
                        <div><label>From</label><input type="date" name="cb_from" value="<?= e($cbFrom) ?>"></div>
                        <div><label>To</label><input type="date" name="cb_to" value="<?= e($cbTo) ?>"></div>
                        <button type="submit" class="btn btn-sm">Filter</button>
                        <a href="?tab=2" class="btn btn-sm btn-soft">Clear</a>
                        <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $cbCount ?> entries</span>
                    </form>
                </div>

                <?php if (empty($cashBookEntries)): ?>
                    <div style="padding:2rem;text-align:center;color:#94a3b8;">No cash book entries found.</div>
                <?php else: ?>
                    <div style="overflow:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th class="text-right">Amount</th>
                                    <th class="text-center">Dr/Cr</th>
                                    <th class="text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cashBookEntries as $entry): ?>
                                    <tr>
                                        <td style="white-space:nowrap;"><?= e($entry['transaction_date']) ?></td>
                                        <td><span class="badge badge-<?= strtolower(str_replace('-', '', $entry['transaction_type'])) ?>"><?= e($entry['transaction_type']) ?></span></td>
                                        <td style="max-width:280px;white-space:normal;"><?= e($entry['description'] ?? '—') ?></td>
                                        <td class="text-right">₹ <?= number_format((float) $entry['amount'], 2) ?></td>
                                        <td class="text-center"><span class="<?= $entry['direction'] === 'Dr' ? 'amount-dr' : 'amount-cr' ?>"><?= e($entry['direction']) ?></span></td>
                                        <td class="text-right" style="font-weight:600;">₹ <?= number_format((float) $entry['balance'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($cbTotalPages > 1): ?>
                        <div class="pagination" style="padding:.75rem 1.25rem;">
                            <span style="color:#64748b;font-size:.85rem;">Page <?= $cbPage ?> of <?= $cbTotalPages ?></span>
                            <div class="page-links">
                                <?php if ($cbPage > 1): ?>
                                    <a href="?tab=2&cb_p=<?= $cbPage - 1 ?>&cb_from=<?= urlencode($cbFrom) ?>&cb_to=<?= urlencode($cbTo) ?>">‹ Prev</a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $cbPage - 2); $i <= min($cbTotalPages, $cbPage + 2); $i++): ?>
                                    <a href="?tab=2&cb_p=<?= $i ?>&cb_from=<?= urlencode($cbFrom) ?>&cb_to=<?= urlencode($cbTo) ?>" class="<?= $i === $cbPage ? 'current' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($cbPage < $cbTotalPages): ?>
                                    <a href="?tab=2&cb_p=<?= $cbPage + 1 ?>&cb_from=<?= urlencode($cbFrom) ?>&cb_to=<?= urlencode($cbTo) ?>">Next ›</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════ TAB 3: BANK ACCOUNTS ═══════════════════ -->
        <div style="display:<?= $activeTab === 3 ? 'block' : 'none' ?>">

            <div class="panel" style="overflow:auto;">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                    <div class="toolbar">
                        <h3 style="margin:0;font-size:1rem;">Bank Accounts</h3>
                        <button class="btn btn-sm" onclick="openModal('bankAccountModal')">+ Add Bank Account</button>
                    </div>
                </div>

                <?php if (empty($bankAccounts)): ?>
                    <div style="padding:2rem;text-align:center;color:#94a3b8;">No bank accounts found. Add one above.</div>
                <?php else: ?>
                    <div style="overflow:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Bank Name</th>
                                    <th>Account Name</th>
                                    <th>Account No</th>
                                    <th>IFSC</th>
                                    <th>Branch</th>
                                    <th>Type</th>
                                    <th class="text-right">Opening Bal.</th>
                                    <th class="text-right">Current Bal.</th>
                                    <th class="text-center">Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <tr>
                                        <td><strong><?= e($ba['bank_name']) ?></strong></td>
                                        <td><?= e($ba['account_name']) ?></td>
                                        <td style="font-family:monospace;"><?= e($ba['account_number']) ?></td>
                                        <td style="font-family:monospace;"><?= e($ba['ifsc_code'] ?? '—') ?></td>
                                        <td><?= e($ba['branch'] ?? '—') ?></td>
                                        <td><span class="badge badge-deposit"><?= e($ba['account_type'] ?? 'Savings') ?></span></td>
                                        <td class="text-right">₹ <?= number_format((float) $ba['opening_balance'], 2) ?></td>
                                        <td class="text-right" style="font-weight:700;">₹ <?= number_format((float) $ba['current_balance'], 2) ?></td>
                                        <td class="text-center"><span class="badge badge-active">Active</span></td>
                                        <td>
                                            <div style="display:flex;gap:.3rem;flex-wrap:nowrap;">
                                                <button class="btn btn-sm btn-soft" onclick="editBankAccount(<?= (int) $ba['id'] ?>, '<?= e($ba['bank_name']) ?>', '<?= e($ba['account_name']) ?>', '<?= e($ba['account_number']) ?>', '<?= e($ba['ifsc_code'] ?? '') ?>', '<?= e($ba['branch'] ?? '') ?>', '<?= e($ba['account_type'] ?? 'Savings') ?>')">Edit</button>
                                                <a href="?tab=4&bb_account=<?= (int) $ba['id'] ?>" class="btn btn-sm btn-soft">Book</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════ TAB 4: BANK BOOK ═══════════════════ -->
        <div style="display:<?= $activeTab === 4 ? 'block' : 'none' ?>">

            <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1rem;">
                <div class="toolbar">
                    <div>
                        <label style="font-size:.8rem;font-weight:600;">Select Bank Account</label>
                        <form method="get" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-top:.3rem;">
                            <input type="hidden" name="tab" value="4">
                            <select name="bb_account" onchange="this.form.submit()" style="min-height:38px;padding:.45rem .7rem;border-radius:8px;font-size:.85rem;">
                                <option value="">— Select Account —</option>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="<?= (int) $ba['id'] ?>" <?= $bbFilterAcc === (int) $ba['id'] ? 'selected' : '' ?>><?= e($ba['bank_name']) ?> – <?= e($ba['account_name']) ?> (<?= e($ba['account_number']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <button class="btn btn-sm" onclick="openModal('bankEntryModal')">+ Add Bank Entry</button>
                </div>
            </div>

            <?php if ($bbFilterAcc > 0 && $selectedBankAccount): ?>
                <div class="balance-bar">
                    <div class="balance-chip">
                        <div class="bl-label">Opening Balance</div>
                        <div class="bl-value">₹ <?= number_format($bbOpeningBal, 2) ?></div>
                    </div>
                    <div class="balance-chip">
                        <div class="bl-label">Current Balance</div>
                        <div class="bl-value" style="color:#059669;">₹ <?= number_format($bbCurrentBal, 2) ?></div>
                    </div>
                    <div class="balance-chip">
                        <div class="bl-label">Reconciled Balance</div>
                        <div class="bl-value" style="color:#2563eb;">₹ <?= number_format($bbReconciledBal, 2) ?></div>
                    </div>
                </div>

                <div class="panel" style="overflow:auto;">
                    <div style="padding:.75rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                        <form method="get" class="filter-row" style="margin-bottom:0;">
                            <input type="hidden" name="tab" value="4">
                            <input type="hidden" name="bb_account" value="<?= $bbFilterAcc ?>">
                            <div><label>From</label><input type="date" name="bb_from" value="<?= e($bbFrom) ?>"></div>
                            <div><label>To</label><input type="date" name="bb_to" value="<?= e($bbTo) ?>"></div>
                            <div><label>Search</label><input type="text" name="bb_q" placeholder="Description..." value="<?= e($bbSearch) ?>"></div>
                            <button type="submit" class="btn btn-sm">Filter</button>
                            <a href="?tab=4&bb_account=<?= $bbFilterAcc ?>" class="btn btn-sm btn-soft">Clear</a>
                            <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $bbCount ?> entries</span>
                        </form>
                    </div>

                    <?php if (empty($bankBookEntries)): ?>
                        <div style="padding:2rem;text-align:center;color:#94a3b8;">No bank book entries found.</div>
                    <?php else: ?>
                        <div style="overflow:auto;">
                            <table class="app-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-center">Dr/Cr</th>
                                        <th class="text-right">Balance</th>
                                        <th class="text-center">Reconciled</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bankBookEntries as $entry): ?>
                                        <tr>
                                            <td style="white-space:nowrap;"><?= e($entry['transaction_date']) ?></td>
                                            <td><span class="badge badge-<?= strtolower(str_replace('-', '', $entry['transaction_type'])) ?>"><?= e($entry['transaction_type']) ?></span></td>
                                            <td style="max-width:250px;white-space:normal;"><?= e($entry['description'] ?? '—') ?></td>
                                            <td class="text-right">₹ <?= number_format((float) $entry['amount'], 2) ?></td>
                                            <td class="text-center"><span class="<?= $entry['direction'] === 'Dr' ? 'amount-dr' : 'amount-cr' ?>"><?= e($entry['direction']) ?></span></td>
                                            <td class="text-right" style="font-weight:600;">₹ <?= number_format((float) $entry['balance'], 2) ?></td>
                                            <td class="text-center">
                                                <?php if ((int) $entry['reconciled']): ?>
                                                    <span class="badge badge-reconciled">Yes</span>
                                                <?php else: ?>
                                                    <span class="badge badge-pending">No</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($bbTotalPages > 1): ?>
                            <div class="pagination" style="padding:.75rem 1.25rem;">
                                <span style="color:#64748b;font-size:.85rem;">Page <?= $bbPage ?> of <?= $bbTotalPages ?></span>
                                <div class="page-links">
                                    <?php if ($bbPage > 1): ?>
                                        <a href="?tab=4&bb_account=<?= $bbFilterAcc ?>&bb_p=<?= $bbPage - 1 ?>&bb_from=<?= urlencode($bbFrom) ?>&bb_to=<?= urlencode($bbTo) ?>&bb_q=<?= urlencode($bbSearch) ?>">‹ Prev</a>
                                    <?php endif; ?>
                                    <?php for ($i = max(1, $bbPage - 2); $i <= min($bbTotalPages, $bbPage + 2); $i++): ?>
                                        <a href="?tab=4&bb_account=<?= $bbFilterAcc ?>&bb_p=<?= $i ?>&bb_from=<?= urlencode($bbFrom) ?>&bb_to=<?= urlencode($bbTo) ?>&bb_q=<?= urlencode($bbSearch) ?>" class="<?= $i === $bbPage ? 'current' : '' ?>"><?= $i ?></a>
                                    <?php endfor; ?>
                                    <?php if ($bbPage < $bbTotalPages): ?>
                                        <a href="?tab=4&bb_account=<?= $bbFilterAcc ?>&bb_p=<?= $bbPage + 1 ?>&bb_from=<?= urlencode($bbFrom) ?>&bb_to=<?= urlencode($bbTo) ?>&bb_q=<?= urlencode($bbSearch) ?>">Next ›</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="panel" style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    <p style="font-size:1rem;margin-bottom:.5rem;">Select a bank account above to view its bank book.</p>
                    <p style="font-size:.85rem;">Or <a href="?tab=3" style="color:#0d6efd;">add a bank account</a> first.</p>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ MODALS ═══════════════════ -->

<!-- Cash Entry Modal -->
<div id="cashEntryModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Add Cash Entry</h2>
            <button class="icon-btn" onclick="closeModal('cashEntryModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_cash_entry">
            <div class="field-grid">
                <div><label>Date *</label><input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></div>
                <div><label>Type *</label>
                    <select name="transaction_type" required>
                        <option value="Receipt">Receipt</option>
                        <option value="Payment">Payment</option>
                    </select>
                </div>
                <div><label>Amount *</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
                <div><label>Direction *</label>
                    <select name="direction" required>
                        <option value="Cr">Credit (In)</option>
                        <option value="Dr">Debit (Out)</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <label>Description</label>
                <input type="text" name="description" placeholder="e.g. Fee collection from John">
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Save Entry</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('cashEntryModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Bank Entry Modal -->
<div id="bankEntryModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Add Bank Entry</h2>
            <button class="icon-btn" onclick="closeModal('bankEntryModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_bank_entry">
            <div class="field-grid">
                <div><label>Bank Account *</label>
                    <select name="bank_account_id" required>
                        <option value="">Select Account</option>
                        <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?= (int) $ba['id'] ?>" <?= $bbFilterAcc === (int) $ba['id'] ? 'selected' : '' ?>><?= e($ba['bank_name']) ?> – <?= e($ba['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Date *</label><input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></div>
                <div><label>Type *</label>
                    <select name="transaction_type" required>
                        <option value="Deposit">Deposit</option>
                        <option value="Withdrawal">Withdrawal</option>
                        <option value="Transfer-In">Transfer In</option>
                        <option value="Transfer-Out">Transfer Out</option>
                        <option value="Expense">Expense</option>
                        <option value="Salary">Salary</option>
                    </select>
                </div>
                <div><label>Amount *</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
                <div><label>Direction *</label>
                    <select name="direction" required>
                        <option value="Cr">Credit (In)</option>
                        <option value="Dr">Debit (Out)</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <label>Description</label>
                <input type="text" name="description" placeholder="e.g. Vendor payment via NEFT">
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Save Entry</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('bankEntryModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Bank Account Modal -->
<div id="bankAccountModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Add Bank Account</h2>
            <button class="icon-btn" onclick="closeModal('bankAccountModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_bank_account">
            <div class="field-grid">
                <div><label>Bank Name *</label><input type="text" name="bank_name" required></div>
                <div><label>Account Name *</label><input type="text" name="account_name" required></div>
                <div><label>Account Number *</label><input type="text" name="account_number" required></div>
                <div><label>IFSC Code</label><input type="text" name="ifsc_code"></div>
                <div><label>Branch</label><input type="text" name="branch"></div>
                <div><label>Account Type</label>
                    <select name="account_type">
                        <option value="Savings">Savings</option>
                        <option value="Current">Current</option>
                        <option value="Fixed Deposit">Fixed Deposit</option>
                        <option value="Recurring Deposit">Recurring Deposit</option>
                    </select>
                </div>
                <div><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0"></div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Create Account</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('bankAccountModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Bank Account Modal -->
<div id="editBankModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Edit Bank Account</h2>
            <button class="icon-btn" onclick="closeModal('editBankModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_bank_account">
            <input type="hidden" name="id" id="edit_id">
            <div class="field-grid">
                <div><label>Bank Name *</label><input type="text" name="bank_name" id="edit_bank_name" required></div>
                <div><label>Account Name *</label><input type="text" name="account_name" id="edit_account_name" required></div>
                <div><label>Account Number *</label><input type="text" name="account_number" id="edit_account_number" required></div>
                <div><label>IFSC Code</label><input type="text" name="ifsc_code" id="edit_ifsc_code"></div>
                <div><label>Branch</label><input type="text" name="branch" id="edit_branch"></div>
                <div><label>Account Type</label>
                    <select name="account_type" id="edit_account_type">
                        <option value="Savings">Savings</option>
                        <option value="Current">Current</option>
                        <option value="Fixed Deposit">Fixed Deposit</option>
                        <option value="Recurring Deposit">Recurring Deposit</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Update Account</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('editBankModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Cash to Bank Modal -->
<div id="transferModal" class="modal-backdrop">
    <div class="modal" style="max-width:520px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Transfer Cash to Bank</h2>
            <button class="icon-btn" onclick="closeModal('transferModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_bank_entry">
            <div class="field-grid">
                <div><label>Bank Account *</label>
                    <select name="bank_account_id" required>
                        <option value="">Select Account</option>
                        <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?= (int) $ba['id'] ?>"><?= e($ba['bank_name']) ?> – <?= e($ba['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Amount *</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
            </div>
            <div style="margin-top:1rem;">
                <label>Date *</label><input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div style="margin-top:1rem;">
                <label>Description</label>
                <input type="text" name="description" value="Cash deposited to bank" placeholder="Description">
            </div>
            <input type="hidden" name="transaction_type" value="Deposit">
            <input type="hidden" name="direction" value="Cr">
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Transfer</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('transferModal')">Cancel</button>
            </div>
        </form>
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
function editBankAccount(id, bankName, accountName, accountNumber, ifsc, branch, accountType) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_bank_name').value = bankName;
    document.getElementById('edit_account_name').value = accountName;
    document.getElementById('edit_account_number').value = accountNumber;
    document.getElementById('edit_ifsc_code').value = ifsc;
    document.getElementById('edit_branch').value = branch;
    document.getElementById('edit_account_type').value = accountType;
    openModal('editBankModal');
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
