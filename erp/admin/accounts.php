<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$pageTitle = 'Accounts';
$error = '';
$success = '';

$activeTab = max(1, min(4, (int) ($_GET['tab'] ?? 1)));

// ── Ensure tables exist ──
$pdo->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_no VARCHAR(50) NOT NULL,
    branch VARCHAR(100),
    ifsc_code VARCHAR(20),
    opening_balance DECIMAL(12,2) DEFAULT 0,
    current_balance DECIMAL(12,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS cash_book (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out') NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    description TEXT,
    amount DECIMAL(12,2) NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    balance DECIMAL(12,2) DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS bank_book (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out','reconciliation') NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    description TEXT,
    amount DECIMAL(12,2) NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    balance DECIMAL(12,2) DEFAULT 0,
    reconciled TINYINT(1) DEFAULT 0,
    reconciliation_date DATE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE
)");

// ── Helper: recalculate cash book balances ──
function recalcCashBook(PDO $pdo): void
{
    $pdo->exec("SET @bal = 0");
    $pdo->exec("UPDATE cash_book SET balance = (@bal := CASE WHEN direction='credit' THEN @bal + amount ELSE @bal - amount END) ORDER BY transaction_date, id");
}

// ── Helper: recalculate bank book balances for an account ──
function recalcBankBook(PDO $pdo, int $bankAccountId): void
{
    $stmt = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM bank_accounts WHERE id = ?");
    $stmt->execute([$bankAccountId]);
    $opening = (float) $stmt->fetchColumn();
    $pdo->prepare("SET @bal = ?")->execute([$opening]);
    $pdo->prepare("UPDATE bank_book SET balance = (@bal := CASE WHEN direction='credit' THEN @bal + amount ELSE @bal - amount END) WHERE bank_account_id = ? ORDER BY transaction_date, id")->execute([$bankAccountId]);
    $pdo->prepare("UPDATE bank_accounts SET current_balance = (SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) FROM bank_book WHERE bank_account_id = ?) + ? WHERE id = ?")->execute([$bankAccountId, $opening, $bankAccountId]);
}

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        // ─── Tab 1: Add Bank Account ───
        if ($action === 'add_bank_account') {
            $accName = trim((string) ($_POST['account_name'] ?? ''));
            $bankName = trim((string) ($_POST['bank_name'] ?? ''));
            $accNo = trim((string) ($_POST['account_no'] ?? ''));
            $branch = trim((string) ($_POST['branch'] ?? ''));
            $ifsc = trim((string) ($_POST['ifsc_code'] ?? ''));
            $opening = (float) ($_POST['opening_balance'] ?? 0);
            if ($accName === '' || $bankName === '' || $accNo === '') throw new \RuntimeException('Account name, bank name, and account number are required.');
            $stmt = $pdo->prepare("INSERT INTO bank_accounts (account_name, bank_name, account_no, branch, ifsc_code, opening_balance, current_balance) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$accName, $bankName, $accNo, $branch, $ifsc, $opening, $opening]);
            if ($opening > 0) {
                $baId = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, CURDATE(), 'opening', 'Opening balance', ?, 'credit', ?, ?)")
                    ->execute([$baId, $opening, $opening, (int) $user['id']]);
            }
            $success = 'Bank account added successfully.';
        }

        // ─── Tab 1: Edit Bank Account ───
        if ($action === 'edit_bank_account') {
            $baId = (int) ($_POST['id'] ?? 0);
            $accName = trim((string) ($_POST['account_name'] ?? ''));
            $bankName = trim((string) ($_POST['bank_name'] ?? ''));
            $accNo = trim((string) ($_POST['account_no'] ?? ''));
            $branch = trim((string) ($_POST['branch'] ?? ''));
            $ifsc = trim((string) ($_POST['ifsc_code'] ?? ''));
            if ($accName === '' || $bankName === '' || $accNo === '') throw new \RuntimeException('Account name, bank name, and account number are required.');
            $pdo->prepare("UPDATE bank_accounts SET account_name=?, bank_name=?, account_no=?, branch=?, ifsc_code=? WHERE id=?")
                ->execute([$accName, $bankName, $accNo, $branch, $ifsc, $baId]);
            $success = 'Bank account updated successfully.';
        }

        // ─── Tab 1: Recalculate Balance ───
        if ($action === 'recalc_balance') {
            $baId = (int) ($_POST['id'] ?? 0);
            recalcBankBook($pdo, $baId);
            $success = 'Balance recalculated successfully.';
        }

        // ─── Tab 2: Add Cash Book Entry ───
        if ($action === 'add_cash_entry') {
            $txnDate = trim((string) ($_POST['transaction_date'] ?? date('Y-m-d')));
            $txnType = trim((string) ($_POST['transaction_type'] ?? 'receipt'));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $direction = trim((string) ($_POST['direction'] ?? 'credit'));
            $allowedTypes = ['opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out'];
            if (!in_array($txnType, $allowedTypes, true)) throw new \RuntimeException('Invalid transaction type.');
            if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero.');
            $lastBal = (float) $pdo->query("SELECT COALESCE(balance,0) FROM cash_book ORDER BY id DESC LIMIT 1")->fetchColumn();
            $newBal = $direction === 'credit' ? $lastBal + $amount : $lastBal - $amount;
            $pdo->prepare("INSERT INTO cash_book (transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$txnDate, $txnType, $desc, $amount, $direction, $newBal, (int) $user['id']]);
            $success = 'Cash book entry added successfully.';
        }

        // ─── Tab 3: Add Bank Book Entry ───
        if ($action === 'add_bank_entry') {
            $baId = (int) ($_POST['bank_account_id'] ?? 0);
            $txnDate = trim((string) ($_POST['transaction_date'] ?? date('Y-m-d')));
            $txnType = trim((string) ($_POST['transaction_type'] ?? 'receipt'));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);
            $direction = trim((string) ($_POST['direction'] ?? 'credit'));
            $allowedTypes = ['opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out','reconciliation'];
            if (!in_array($txnType, $allowedTypes, true)) throw new \RuntimeException('Invalid transaction type.');
            if ($baId <= 0) throw new \RuntimeException('Select a bank account.');
            if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero.');
            $stmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM bank_book WHERE bank_account_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$baId]);
            $lastBal = (float) $stmt->fetchColumn();
            if ($lastBal === 0.0) {
                $stmt2 = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM bank_accounts WHERE id = ?");
                $stmt2->execute([$baId]);
                $lastBal = (float) $stmt2->fetchColumn();
            }
            $newBal = $direction === 'credit' ? $lastBal + $amount : $lastBal - $amount;
            $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$baId, $txnDate, $txnType, $desc, $amount, $direction, $newBal, (int) $user['id']]);
            recalcBankBook($pdo, $baId);
            $success = 'Bank book entry added successfully.';
        }

        // ─── Tab 4: Transfer ───
        if ($action === 'do_transfer') {
            $fromType = trim((string) ($_POST['from_type'] ?? ''));
            $fromId = (int) ($_POST['from_id'] ?? 0);
            $toType = trim((string) ($_POST['to_type'] ?? ''));
            $toId = (int) ($_POST['to_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);
            $txnDate = trim((string) ($_POST['transaction_date'] ?? date('Y-m-d')));
            $desc = trim((string) ($_POST['description'] ?? ''));
            if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero.');
            if ($fromType === $toType && $fromId === $toId) throw new \RuntimeException('Source and destination cannot be the same.');
            if ($desc === '') $desc = 'Fund transfer';

            $pdo->beginTransaction();
            try {
                if ($fromType === 'cash' && $toType === 'bank') {
                    // Cash to Bank: debit cash, credit bank
                    $lastCash = (float) $pdo->query("SELECT COALESCE(balance,0) FROM cash_book ORDER BY id DESC LIMIT 1")->fetchColumn();
                    $pdo->prepare("INSERT INTO cash_book (transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, 'transfer_out', ?, ?, 'debit', ?, ?)")
                        ->execute([$txnDate, $desc . ' (Transfer to Bank)', $amount, $lastCash - $amount, (int) $user['id']]);
                    $stmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM bank_book WHERE bank_account_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$toId]);
                    $lastBank = (float) $stmt->fetchColumn();
                    if ($lastBank === 0.0) {
                        $s2 = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM bank_accounts WHERE id = ?");
                        $s2->execute([$toId]);
                        $lastBank = (float) $s2->fetchColumn();
                    }
                    $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, ?, 'transfer_in', ?, ?, 'credit', ?, ?)")
                        ->execute([$toId, $txnDate, $desc . ' (Transfer from Cash)', $amount, $lastBank + $amount, (int) $user['id']]);
                    recalcBankBook($pdo, $toId);
                } elseif ($fromType === 'bank' && $toType === 'cash') {
                    // Bank to Cash: debit bank, credit cash
                    $stmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM bank_book WHERE bank_account_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$fromId]);
                    $lastBank = (float) $stmt->fetchColumn();
                    if ($lastBank === 0.0) {
                        $s2 = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM bank_accounts WHERE id = ?");
                        $s2->execute([$fromId]);
                        $lastBank = (float) $s2->fetchColumn();
                    }
                    $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, ?, 'transfer_out', ?, ?, 'debit', ?, ?)")
                        ->execute([$fromId, $txnDate, $desc . ' (Transfer to Cash)', $amount, $lastBank - $amount, (int) $user['id']]);
                    recalcBankBook($pdo, $fromId);
                    $lastCash = (float) $pdo->query("SELECT COALESCE(balance,0) FROM cash_book ORDER BY id DESC LIMIT 1")->fetchColumn();
                    $pdo->prepare("INSERT INTO cash_book (transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, 'transfer_in', ?, ?, 'credit', ?, ?)")
                        ->execute([$txnDate, $desc . ' (Transfer from Bank)', $amount, $lastCash + $amount, (int) $user['id']]);
                } elseif ($fromType === 'bank' && $toType === 'bank') {
                    // Bank to Bank: debit from bank, credit to bank
                    $stmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM bank_book WHERE bank_account_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$fromId]);
                    $lastFrom = (float) $stmt->fetchColumn();
                    if ($lastFrom === 0.0) {
                        $s2 = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM bank_accounts WHERE id = ?");
                        $s2->execute([$fromId]);
                        $lastFrom = (float) $s2->fetchColumn();
                    }
                    $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, ?, 'transfer_out', ?, ?, 'debit', ?, ?)")
                        ->execute([$fromId, $txnDate, $desc . ' (Transfer to Bank)', $amount, $lastFrom - $amount, (int) $user['id']]);
                    recalcBankBook($pdo, $fromId);
                    $stmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM bank_book WHERE bank_account_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$toId]);
                    $lastTo = (float) $stmt->fetchColumn();
                    if ($lastTo === 0.0) {
                        $s2 = $pdo->prepare("SELECT COALESCE(opening_balance,0) FROM bank_accounts WHERE id = ?");
                        $s2->execute([$toId]);
                        $lastTo = (float) $s2->fetchColumn();
                    }
                    $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, description, amount, direction, balance, created_by) VALUES (?, ?, 'transfer_in', ?, ?, 'credit', ?, ?)")
                        ->execute([$toId, $txnDate, $desc . ' (Transfer from Bank)', $amount, $lastTo + $amount, (int) $user['id']]);
                    recalcBankBook($pdo, $toId);
                } else {
                    throw new \RuntimeException('Invalid transfer configuration.');
                }
                $pdo->commit();
                $success = 'Transfer completed successfully.';
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: accounts.php?tab=' . $activeTab . '&success=' . urlencode($success));
        exit;
    }
}

// ── Handle success flash ──
if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}

// ── Fetch data ──

// Bank Accounts
$bankAccounts = $pdo->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($bankAccounts as &$ba) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) FROM bank_book WHERE bank_account_id = ?");
    $stmt->execute([(int) $ba['id']]);
    $txnBal = (float) $stmt->fetchColumn();
    $ba['computed_balance'] = (float) $ba['opening_balance'] + $txnBal;
}
unset($ba);

// Cash Book
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

// Bank Book
$bbPage = max(1, (int) ($_GET['bb_p'] ?? 1));
$bbLimit = 25;
$bbOffset = ($bbPage - 1) * $bbLimit;
$bbWhere = [];
$bbParams = [];
$bbFilterAcc = (int) ($_GET['bb_account'] ?? 0);
$bbFrom = trim((string) ($_GET['bb_from'] ?? ''));
$bbTo = trim((string) ($_GET['bb_to'] ?? ''));
$bbSearch = trim((string) ($_GET['bb_q'] ?? ''));
if ($bbFilterAcc > 0) { $bbWhere[] = 'bank_account_id = :bb_acc'; $bbParams['bb_acc'] = $bbFilterAcc; }
if ($bbFrom !== '') { $bbWhere[] = 'transaction_date >= :bb_from'; $bbParams['bb_from'] = $bbFrom; }
if ($bbTo !== '') { $bbWhere[] = 'transaction_date <= :bb_to'; $bbParams['bb_to'] = $bbTo; }
if ($bbSearch !== '') { $bbWhere[] = 'description LIKE :bb_q'; $bbParams['bb_q'] = '%' . $bbSearch . '%'; }
$bbWhereSql = count($bbWhere) > 0 ? ' WHERE ' . implode(' AND ', $bbWhere) : '';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bank_book" . $bbWhereSql);
$stmt->execute($bbParams);
$bbCount = (int) $stmt->fetchColumn();
$bbStmt = $pdo->prepare("SELECT bb.*, ba.account_name, ba.bank_name FROM bank_book bb JOIN bank_accounts ba ON ba.id = bb.bank_account_id" . $bbWhereSql . " ORDER BY bb.transaction_date DESC, bb.id DESC LIMIT :bblim OFFSET :bboff");
foreach ($bbParams as $k => $v) $bbStmt->bindValue(':' . $k, $v);
$bbStmt->bindValue(':bblim', $bbLimit, PDO::PARAM_INT);
$bbStmt->bindValue(':bboff', $bbOffset, PDO::PARAM_INT);
$bbStmt->execute();
$bankBookEntries = $bbStmt->fetchAll(PDO::FETCH_ASSOC);
$bbTotalPages = max(1, (int) ceil($bbCount / $bbLimit));

// Cash total
$cashTotal = (float) $pdo->query("SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) FROM cash_book")->fetchColumn();

// Bank total
$bankTotal = (float) $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts WHERE is_active = 1")->fetchColumn();

// Fee collection count for sidebar badge
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_collections WHERE payment_date = CURDATE() AND status = 'Active'");
    $stmt->execute();
    $todayCount = (int) $stmt->fetchColumn();
} catch (Throwable) {
    $todayCount = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'Pending'");
    $stmt->execute();
    $pendingExpenseCount = (int) $stmt->fetchColumn();
} catch (Throwable) {
    $pendingExpenseCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Accounts – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .tab-bar a { padding:.6rem 1.5rem; font-size:.9rem; font-weight:500; color:#64748b; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s, border-color .15s; }
        .tab-bar a.active { color:#1e293b; border-bottom-color:#1e293b; font-weight:700; }
        .tab-bar a:hover { color:#1e293b; }
        .tab-content { display:none; }
        .tab-content.active { display:block; }
        .filter-row { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
        .filter-row label { font-size:.8rem; margin-bottom:.2rem; }
        .filter-row input, .filter-row select { min-height:38px; padding:.45rem .7rem; border-radius:8px; font-size:.85rem; width:auto; }
        .filter-row .btn { min-height:38px; padding:.45rem 1rem; font-size:.85rem; }
        .stat-bar { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
        .stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.8rem 1.2rem; flex:1; min-width:180px; }
        .stat-card .stat-label { font-size:.75rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
        .stat-card .stat-value { font-size:1.4rem; font-weight:700; color:#0f172a; margin-top:.2rem; }
        .mini-form { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; }
        .mini-form .field-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:1rem; }
        .mini-form label { font-size:.8rem; }
        .mini-form input, .mini-form select { min-height:40px; padding:.5rem .7rem; font-size:.85rem; }
        .transfer-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:1rem; align-items:end; }
        .transfer-arrow { font-size:2rem; padding-bottom:.5rem; color:#94a3b8; }
        @media (max-width:768px) { .transfer-grid { grid-template-columns:1fr; } .transfer-arrow { text-align:center; transform:rotate(90deg); } }
        .btn-sm { min-height:36px; padding:.4rem .85rem; font-size:.82rem; border-radius:8px; }
        .text-right { text-align:right; }
        .amount-credit { color:#059669; font-weight:600; }
        .amount-debit { color:#dc2626; font-weight:600; }
        .badge-reconciled { background:#d1fae5; color:#065f46; padding:.15rem .5rem; border-radius:4px; font-size:.75rem; font-weight:600; }
        .badge-pending { background:#fef3c7; color:#92400e; padding:.15rem .5rem; border-radius:4px; font-size:.75rem; font-weight:600; }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <aside class="sidebar" style="display:flex;flex-direction:column;">
        <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
            <span class="eyebrow" style="background:rgba(255,255,255,.1);color:#effff5">SIBA ERP</span>
            <div class="brand-copy">
                <h2 style="font-size:1.7rem;color:#fff">Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
        </div>
        <div class="nav-group">
            <div class="nav-title">Finance</div>
            <a class="nav-link" href="finance-dashboard.php"><span class="sidebar-icon">📊</span><span>Finance Dashboard</span></a>
            <a class="nav-link" href="fee-structures.php"><span class="sidebar-icon">🏗</span><span>Fee Structures</span></a>
            <a class="nav-link" href="fee-collection.php"><span class="sidebar-icon">💰</span><span>Fee Collection</span></a>
            <a class="nav-link" href="receipts-list.php"><span class="sidebar-icon">🧾</span><span>Receipts</span></a>
            <a class="nav-link" href="expenses.php"><span class="sidebar-icon">📤</span><span>Expenses</span></a>
            <a class="nav-link" href="income.php"><span class="sidebar-icon">📥</span><span>Income</span></a>
            <a class="nav-link active" href="accounts.php"><span class="sidebar-icon">🏦</span><span>Accounts</span></a>
            <a class="nav-link" href="reports.php"><span class="sidebar-icon">📈</span><span>Reports</span></a>
        </div>
        <?php if ($isOwner): ?>
        <div class="nav-group">
            <div class="nav-title">Administration</div>
            <?php $pendingAdminCount = 0; try { $pendingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'pending'")->fetchColumn(); } catch (\Throwable $e) {} ?>
            <a class="nav-link" href="admin-requests.php">
                <span class="sidebar-icon">🔑</span>
                <span>Admin Requests</span>
                <?php if ($pendingAdminCount > 0): ?>
                    <span class="nav-tag" style="background:#f59e0b;color:#fff;"><?= $pendingAdminCount ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>
        <div class="nav-group" style="margin-top:auto;">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Cash & Bank Management</h1>
                    <p>Manage bank accounts, cash book, bank book, and fund transfers.</p>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="stat-bar">
            <div class="stat-card">
                <div class="stat-label">Cash in Hand</div>
                <div class="stat-value">Rs. <?= number_format($cashTotal, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Bank Balance</div>
                <div class="stat-value">Rs. <?= number_format($bankTotal, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Funds</div>
                <div class="stat-value">Rs. <?= number_format($cashTotal + $bankTotal, 2) ?></div>
            </div>
        </div>

        <div class="tab-bar">
            <a href="?tab=1" class="<?= $activeTab === 1 ? 'active' : '' ?>">Bank Accounts</a>
            <a href="?tab=2" class="<?= $activeTab === 2 ? 'active' : '' ?>">Cash Book</a>
            <a href="?tab=3" class="<?= $activeTab === 3 ? 'active' : '' ?>">Bank Book</a>
            <a href="?tab=4" class="<?= $activeTab === 4 ? 'active' : '' ?>">Transfer</a>
        </div>

        <!-- ─────────────── TAB 1: Bank Accounts ─────────────── -->
        <div class="tab-content <?= $activeTab === 1 ? 'active' : '' ?>">
            <div class="toolbar" style="margin-bottom:1rem;">
                <h2>Bank Accounts</h2>
                <button class="btn btn-sm" onclick="toggleForm('bankAccountForm')">+ Add Account</button>
            </div>

            <div id="bankAccountForm" class="mini-form" style="display:none;">
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_bank_account">
                    <div class="field-row">
                        <div><label>Account Name *</label><input type="text" name="account_name" required></div>
                        <div><label>Bank Name *</label><input type="text" name="bank_name" required></div>
                        <div><label>Account No *</label><input type="text" name="account_no" required></div>
                        <div><label>Branch</label><input type="text" name="branch"></div>
                        <div><label>IFSC Code</label><input type="text" name="ifsc_code"></div>
                        <div><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0"></div>
                    </div>
                    <div style="margin-top:1rem;display:flex;gap:.5rem;">
                        <button type="submit" class="btn btn-sm">Save Account</button>
                        <button type="button" class="btn btn-sm btn-soft" onclick="toggleForm('bankAccountForm')">Cancel</button>
                    </div>
                </form>
            </div>

            <?php if (empty($bankAccounts)): ?>
                <div class="panel" style="padding:2rem;text-align:center;color:#94a3b8;">No bank accounts found. Add one above.</div>
            <?php else: ?>
                <div style="overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                        <thead>
                            <tr>
                                <th>Account Name</th>
                                <th>Bank</th>
                                <th>Account No</th>
                                <th>Branch</th>
                                <th>IFSC</th>
                                <th>Opening Bal.</th>
                                <th>Current Bal.</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bankAccounts as $ba): ?>
                                <tr>
                                    <td><strong><?= e($ba['account_name']) ?></strong></td>
                                    <td><?= e($ba['bank_name']) ?></td>
                                    <td style="font-family:monospace;"><?= e($ba['account_no']) ?></td>
                                    <td><?= e($ba['branch'] ?? '—') ?></td>
                                    <td style="font-family:monospace;"><?= e($ba['ifsc_code'] ?? '—') ?></td>
                                    <td class="text-right">Rs. <?= number_format((float) $ba['opening_balance'], 2) ?></td>
                                    <td class="text-right" style="font-weight:700;">Rs. <?= number_format((float) ($ba['computed_balance']), 2) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-soft" onclick="editBankAccount(<?= (int) $ba['id'] ?>, '<?= e($ba['account_name']) ?>', '<?= e($ba['bank_name']) ?>', '<?= e($ba['account_no']) ?>', '<?= e($ba['branch'] ?? '') ?>', '<?= e($ba['ifsc_code'] ?? '') ?>')">Edit</button>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="recalc_balance">
                                            <input type="hidden" name="id" value="<?= (int) $ba['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-soft" style="font-size:.75rem;">Recalc</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Edit Bank Account Modal -->
                <div id="editBankModal" class="modal-backdrop">
                    <div class="modal" style="max-width:600px;">
                        <div class="modal-head">
                            <h2>Edit Bank Account</h2>
                            <button class="icon-btn" onclick="closeModal('editBankModal')">✕</button>
                        </div>
                        <form method="post">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="edit_bank_account">
                            <input type="hidden" name="id" id="edit_id">
                            <div class="field-row">
                                <div><label>Account Name *</label><input type="text" name="account_name" id="edit_account_name" required></div>
                                <div><label>Bank Name *</label><input type="text" name="bank_name" id="edit_bank_name" required></div>
                                <div><label>Account No *</label><input type="text" name="account_no" id="edit_account_no" required></div>
                                <div><label>Branch</label><input type="text" name="branch" id="edit_branch"></div>
                                <div><label>IFSC Code</label><input type="text" name="ifsc_code" id="edit_ifsc"></div>
                            </div>
                            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                                <button type="submit" class="btn btn-sm">Update</button>
                                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('editBankModal')">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ─────────────── TAB 2: Cash Book ─────────────── -->
        <div class="tab-content <?= $activeTab === 2 ? 'active' : '' ?>">
            <div class="toolbar" style="margin-bottom:1rem;">
                <h2>Cash Book</h2>
                <button class="btn btn-sm" onclick="toggleForm('cashEntryForm')">+ Add Entry</button>
            </div>

            <div id="cashEntryForm" class="mini-form" style="display:none;">
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_cash_entry">
                    <div class="field-row">
                        <div><label>Date *</label><input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></div>
                        <div><label>Type *</label>
                            <select name="transaction_type" required>
                                <option value="receipt">Receipt</option>
                                <option value="payment">Payment</option>
                                <option value="deposit">Deposit</option>
                                <option value="withdrawal">Withdrawal</option>
                                <option value="transfer_in">Transfer In</option>
                                <option value="transfer_out">Transfer Out</option>
                            </select>
                        </div>
                        <div><label>Description</label><input type="text" name="description"></div>
                        <div><label>Amount *</label><input type="number" step="0.01" name="amount" required></div>
                        <div><label>Direction *</label>
                            <select name="direction" required>
                                <option value="credit">Credit (In)</option>
                                <option value="debit">Debit (Out)</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:1rem;display:flex;gap:.5rem;">
                        <button type="submit" class="btn btn-sm">Add Entry</button>
                        <button type="button" class="btn btn-sm btn-soft" onclick="toggleForm('cashEntryForm')">Cancel</button>
                    </div>
                </form>
            </div>

            <form method="get" class="filter-row">
                <input type="hidden" name="tab" value="2">
                <div><label>From</label><input type="date" name="cb_from" value="<?= e($cbFrom) ?>"></div>
                <div><label>To</label><input type="date" name="cb_to" value="<?= e($cbTo) ?>"></div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <a href="?tab=2" class="btn btn-sm btn-soft">Clear</a>
                <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $cbCount ?> entries</span>
            </form>

            <?php if (empty($cashBookEntries)): ?>
                <div class="panel" style="padding:2rem;text-align:center;color:#94a3b8;">No cash book entries found.</div>
            <?php else: ?>
                <div style="overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Direction</th>
                                <th>Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cashBookEntries as $entry): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= e($entry['transaction_date']) ?></td>
                                    <td><span class="badge"><?= e(ucfirst($entry['transaction_type'])) ?></span></td>
                                    <td style="max-width:250px;white-space:normal;"><?= e($entry['description'] ?? '—') ?></td>
                                    <td class="text-right">Rs. <?= number_format((float) $entry['amount'], 2) ?></td>
                                    <td><span class="<?= $entry['direction'] === 'credit' ? 'amount-credit' : 'amount-debit' ?>"><?= e(ucfirst($entry['direction'])) ?></span></td>
                                    <td class="text-right">Rs. <?= number_format((float) $entry['balance'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($cbTotalPages > 1): ?>
                    <div class="pagination">
                        <span style="color:#64748b;font-size:.85rem;">Page <?= $cbPage ?> of <?= $cbTotalPages ?></span>
                        <div class="page-links">
                            <?php if ($cbPage > 1): ?>
                                <a class="btn btn-sm btn-soft" href="?tab=2&cb_p=<?= $cbPage - 1 ?>&cb_from=<?= urlencode($cbFrom) ?>&cb_to=<?= urlencode($cbTo) ?>">‹ Prev</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $cbTotalPages; $i++): ?>
                                <a class="btn btn-sm <?= $i === $cbPage ? '' : 'btn-soft' ?>" style="<?= $i === $cbPage ? 'background:#1e293b;color:#fff;' : '' ?>" href="?tab=2&cb_p=<?= $i ?>&cb_from=<?= urlencode($cbFrom) ?>&cb_to=<?= urlencode($cbTo) ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($cbPage < $cbTotalPages): ?>
                                <a class="btn btn-sm btn-soft" href="?tab=2&cb_p=<?= $cbPage + 1 ?>&cb_from=<?= urlencode($cbFrom) ?>&cb_to=<?= urlencode($cbTo) ?>">Next ›</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- ─────────────── TAB 3: Bank Book ─────────────── -->
        <div class="tab-content <?= $activeTab === 3 ? 'active' : '' ?>">
            <div class="toolbar" style="margin-bottom:1rem;">
                <h2>Bank Book</h2>
                <button class="btn btn-sm" onclick="toggleForm('bankEntryForm')">+ Add Entry</button>
            </div>

            <div id="bankEntryForm" class="mini-form" style="display:none;">
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_bank_entry">
                    <div class="field-row">
                        <div><label>Bank Account *</label>
                            <select name="bank_account_id" required>
                                <option value="">Select Account</option>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="<?= (int) $ba['id'] ?>"><?= e($ba['account_name']) ?> – <?= e($ba['bank_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Date *</label><input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></div>
                        <div><label>Type *</label>
                            <select name="transaction_type" required>
                                <option value="receipt">Receipt</option>
                                <option value="payment">Payment</option>
                                <option value="deposit">Deposit</option>
                                <option value="withdrawal">Withdrawal</option>
                                <option value="transfer_in">Transfer In</option>
                                <option value="transfer_out">Transfer Out</option>
                                <option value="reconciliation">Reconciliation</option>
                            </select>
                        </div>
                        <div><label>Description</label><input type="text" name="description"></div>
                        <div><label>Amount *</label><input type="number" step="0.01" name="amount" required></div>
                        <div><label>Direction *</label>
                            <select name="direction" required>
                                <option value="credit">Credit (In)</option>
                                <option value="debit">Debit (Out)</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:1rem;display:flex;gap:.5rem;">
                        <button type="submit" class="btn btn-sm">Add Entry</button>
                        <button type="button" class="btn btn-sm btn-soft" onclick="toggleForm('bankEntryForm')">Cancel</button>
                    </div>
                </form>
            </div>

            <form method="get" class="filter-row">
                <input type="hidden" name="tab" value="3">
                <div><label>Bank Account</label>
                    <select name="bb_account">
                        <option value="">All Accounts</option>
                        <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?= (int) $ba['id'] ?>" <?= $bbFilterAcc === (int) $ba['id'] ? 'selected' : '' ?>><?= e($ba['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>From</label><input type="date" name="bb_from" value="<?= e($bbFrom) ?>"></div>
                <div><label>To</label><input type="date" name="bb_to" value="<?= e($bbTo) ?>"></div>
                <div><label>Search</label><input type="text" name="bb_q" placeholder="Description..." value="<?= e($bbSearch) ?>"></div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <a href="?tab=3" class="btn btn-sm btn-soft">Clear</a>
                <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $bbCount ?> entries</span>
            </form>

            <?php if (empty($bankBookEntries)): ?>
                <div class="panel" style="padding:2rem;text-align:center;color:#94a3b8;">No bank book entries found.</div>
            <?php else: ?>
                <div style="overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Direction</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bankBookEntries as $entry): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= e($entry['transaction_date']) ?></td>
                                    <td><span style="font-size:.82rem;"><?= e($entry['account_name'] ?? '—') ?></span></td>
                                    <td><span class="badge"><?= e(ucfirst($entry['transaction_type'])) ?></span></td>
                                    <td style="max-width:250px;white-space:normal;"><?= e($entry['description'] ?? '—') ?></td>
                                    <td class="text-right">Rs. <?= number_format((float) $entry['amount'], 2) ?></td>
                                    <td><span class="<?= $entry['direction'] === 'credit' ? 'amount-credit' : 'amount-debit' ?>"><?= e(ucfirst($entry['direction'])) ?></span></td>
                                    <td class="text-right">Rs. <?= number_format((float) $entry['balance'], 2) ?></td>
                                    <td><?= (int) $entry['reconciled'] ? '<span class="badge-reconciled">Reconciled</span>' : '<span class="badge-pending">Pending</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($bbTotalPages > 1): ?>
                    <div class="pagination">
                        <span style="color:#64748b;font-size:.85rem;">Page <?= $bbPage ?> of <?= $bbTotalPages ?></span>
                        <div class="page-links">
                            <?php if ($bbPage > 1): ?>
                                <a class="btn btn-sm btn-soft" href="?tab=3&bb_p=<?= $bbPage - 1 ?>&bb_account=<?= $bbFilterAcc ?>&bb_from=<?= urlencode($bbFrom) ?>&bb_to=<?= urlencode($bbTo) ?>&bb_q=<?= urlencode($bbSearch) ?>">‹ Prev</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $bbTotalPages; $i++): ?>
                                <a class="btn btn-sm <?= $i === $bbPage ? '' : 'btn-soft' ?>" style="<?= $i === $bbPage ? 'background:#1e293b;color:#fff;' : '' ?>" href="?tab=3&bb_p=<?= $i ?>&bb_account=<?= $bbFilterAcc ?>&bb_from=<?= urlencode($bbFrom) ?>&bb_to=<?= urlencode($bbTo) ?>&bb_q=<?= urlencode($bbSearch) ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($bbPage < $bbTotalPages): ?>
                                <a class="btn btn-sm btn-soft" href="?tab=3&bb_p=<?= $bbPage + 1 ?>&bb_account=<?= $bbFilterAcc ?>&bb_from=<?= urlencode($bbFrom) ?>&bb_to=<?= urlencode($bbTo) ?>&bb_q=<?= urlencode($bbSearch) ?>">Next ›</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- ─────────────── TAB 4: Transfer ─────────────── -->
        <div class="tab-content <?= $activeTab === 4 ? 'active' : '' ?>">
            <div class="toolbar" style="margin-bottom:1rem;">
                <h2>Fund Transfer</h2>
            </div>

            <div class="panel" style="padding:1.25rem;max-width:800px;">
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="do_transfer">

                    <div class="transfer-grid">
                        <div>
                            <label>From *</label>
                            <select name="from_type" id="fromType" onchange="updateTransferAccounts()" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Account</option>
                            </select>
                            <select name="from_id" id="fromId" style="margin-top:.5rem;">
                                <option value="0">Cash in Hand</option>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="<?= (int) $ba['id'] ?>"><?= e($ba['account_name']) ?> (Rs. <?= number_format((float) $ba['computed_balance'], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="transfer-arrow">→</div>
                        <div>
                            <label>To *</label>
                            <select name="to_type" id="toType" onchange="updateTransferAccounts()" required>
                                <option value="bank">Bank Account</option>
                                <option value="cash">Cash</option>
                            </select>
                            <select name="to_id" id="toId" style="margin-top:.5rem;">
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="<?= (int) $ba['id'] ?>"><?= e($ba['account_name']) ?> (Rs. <?= number_format((float) $ba['computed_balance'], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field-row" style="margin-top:1.5rem;">
                        <div><label>Amount *</label><input type="number" step="0.01" name="amount" required></div>
                        <div><label>Date *</label><input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></div>
                        <div><label>Description</label><input type="text" name="description" placeholder="Fund transfer"></div>
                    </div>

                    <div style="margin-top:1rem;">
                        <button type="submit" class="btn btn-sm">Execute Transfer</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<script>
function toggleForm(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
function openModal(id) {
    document.getElementById(id).classList.add('show');
}
function editBankAccount(id, name, bank, accNo, branch, ifsc) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_account_name').value = name;
    document.getElementById('edit_bank_name').value = bank;
    document.getElementById('edit_account_no').value = accNo;
    document.getElementById('edit_branch').value = branch;
    document.getElementById('edit_ifsc').value = ifsc;
    openModal('editBankModal');
}
function updateTransferAccounts() {
    var fromType = document.getElementById('fromType').value;
    var toType = document.getElementById('toType').value;
    var fromId = document.getElementById('fromId');
    var toId = document.getElementById('toId');
    var bankOpts = <?= json_encode(array_map(fn($ba) => ['id' => (int) $ba['id'], 'label' => $ba['account_name'] . ' (Rs. ' . number_format((float) $ba['computed_balance'], 2) . ')'], $bankAccounts)) ?>;

    var html = '';
    if (fromType === 'cash') {
        html += '<option value="0">Cash in Hand</option>';
    } else {
        bankOpts.forEach(function(b) { html += '<option value="' + b.id + '">' + b.label + '</option>'; });
    }
    fromId.innerHTML = html;

    html = '';
    if (toType === 'cash') {
        html += '<option value="0">Cash in Hand</option>';
    } else {
        bankOpts.forEach(function(b) { html += '<option value="' + b.id + '">' + b.label + '</option>'; });
    }
    toId.innerHTML = html;
}
document.addEventListener('DOMContentLoaded', function() {
    // Handle modal backdrop clicks to close
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
});
</script>
<script src="../assets/erp.js"></script>
</body>
</html>
