<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();

$pageTitle = 'Chart of Accounts & Journal';
$error = '';
$success = '';

$activeTab = max(1, min(2, (int) ($_GET['tab'] ?? 1)));

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_code VARCHAR(30) NOT NULL,
        account_name VARCHAR(150) NOT NULL,
        account_type ENUM('Asset','Liability','Income','Expense','Equity') NOT NULL DEFAULT 'Asset',
        parent_id INT DEFAULT NULL,
        opening_balance DECIMAL(12,2) DEFAULT 0,
        current_balance DECIMAL(12,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_account_code (account_code)
    )");
} catch (Throwable $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ledger_account_id INT NOT NULL,
        entry_date DATE NOT NULL,
        entry_type VARCHAR(50) DEFAULT NULL,
        reference_type VARCHAR(50) DEFAULT NULL,
        reference_id INT DEFAULT NULL,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL,
        direction ENUM('Dr','Cr') NOT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ledger_account (ledger_account_id),
        KEY idx_entry_date (entry_date)
    )");
} catch (Throwable $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS journal_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        journal_no VARCHAR(50) NOT NULL,
        entry_date DATE NOT NULL,
        description TEXT,
        debit_account_id INT NOT NULL,
        credit_account_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status ENUM('Draft','Posted','Cancelled') DEFAULT 'Draft',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_journal_no (journal_no),
        KEY idx_entry_date (entry_date),
        KEY idx_status (status)
    )");
} catch (Throwable $e) {}

// ── Helper: account options for dropdowns ──
function account_options(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, account_code, account_name, account_type FROM ledger_accounts WHERE is_active = 1 ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'label' => $r['account_code'] . ' — ' . $r['account_name'],
            'code' => $r['account_code'],
            'name' => $r['account_name'],
            'type' => $r['account_type'],
        ];
    }
    return $out;
}

// ── Helper: generate journal_no atomically ──
function generate_journal_no(PDO $pdo): string
{
    $year = date('Y');
    $prefix = 'JV-' . $year . '-';
    try {
        $stmt = $pdo->prepare("SELECT journal_no FROM journal_entries WHERE journal_no LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        if ($last) {
            $num = (int) substr($last, strlen($prefix));
            return $prefix . str_pad((string) ($num + 1), 4, '0', STR_PAD_LEFT);
        }
    } catch (Throwable) {}
    return $prefix . '0001';
}

// ── Helper: account type badge ──
function account_type_badge(string $type): string
{
    $colors = [
        'Asset' => ['#dbeafe', '#1e40af'],
        'Liability' => ['#fee2e2', '#991b1b'],
        'Income' => ['#d1fae5', '#065f46'],
        'Expense' => ['#ffedd5', '#9a3412'],
        'Equity' => ['#ede9fe', '#5b21b6'],
    ];
    $c = $colors[$type] ?? ['#f1f5f9', '#475569'];
    return '<span style="display:inline-flex;align-items:center;gap:.3rem;padding:.25em .6em;border-radius:999px;font-size:.75rem;font-weight:600;background:' . $c[0] . ';color:' . $c[1] . ';">' . htmlspecialchars($type) . '</span>';
}

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        // ─── Create Account ───
        if ($action === 'create_account') {
            $code = trim((string) ($_POST['account_code'] ?? ''));
            $name = trim((string) ($_POST['account_name'] ?? ''));
            $type = trim((string) ($_POST['account_type'] ?? 'Asset'));
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            $openingBalance = (float) ($_POST['opening_balance'] ?? 0);
            $allowedTypes = ['Asset', 'Liability', 'Income', 'Expense', 'Equity'];
            if ($code === '' || $name === '') throw new \RuntimeException('Account code and name are required.');
            if (!in_array($type, $allowedTypes, true)) throw new \RuntimeException('Invalid account type.');

            $stmt = $pdo->prepare("INSERT INTO ledger_accounts (account_code, account_name, account_type, parent_id, opening_balance, current_balance, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([
                $code,
                $name,
                $type,
                $parentId > 0 ? $parentId : null,
                $openingBalance,
                $openingBalance,
            ]);
            $success = 'Account "' . $code . '" created successfully.';
        }

        // ─── Update Account ───
        if ($action === 'update_account') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = trim((string) ($_POST['account_code'] ?? ''));
            $name = trim((string) ($_POST['account_name'] ?? ''));
            $type = trim((string) ($_POST['account_type'] ?? 'Asset'));
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            $allowedTypes = ['Asset', 'Liability', 'Income', 'Expense', 'Equity'];
            if ($id <= 0) throw new \RuntimeException('Invalid account.');
            if ($code === '' || $name === '') throw new \RuntimeException('Account code and name are required.');
            if (!in_array($type, $allowedTypes, true)) throw new \RuntimeException('Invalid account type.');
            if ($parentId === $id) throw new \RuntimeException('Parent account cannot be the same as the account itself.');

            $pdo->prepare("UPDATE ledger_accounts SET account_code = ?, account_name = ?, account_type = ?, parent_id = ? WHERE id = ?")
                ->execute([$code, $name, $type, $parentId > 0 ? $parentId : null, $id]);
            $success = 'Account updated successfully.';
        }

        // ─── Delete (soft-deactivate) Account ───
        if ($action === 'delete_account') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new \RuntimeException('Invalid account.');

            $hasEntries = $pdo->prepare("SELECT COUNT(*) FROM ledger_entries WHERE ledger_account_id = ?");
            $hasEntries->execute([$id]);
            if ((int) $hasEntries->fetchColumn() > 0) {
                throw new \RuntimeException('Cannot deactivate an account that has ledger entries. Clear its balance first.');
            }
            $hasJournals = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE (debit_account_id = ? OR credit_account_id = ?) AND status != 'Cancelled'");
            $hasJournals->execute([$id, $id]);
            if ((int) $hasJournals->fetchColumn() > 0) {
                throw new \RuntimeException('Cannot deactivate an account that is referenced in active journal entries.');
            }

            $pdo->prepare("UPDATE ledger_accounts SET is_active = 0 WHERE id = ?")->execute([$id]);
            $success = 'Account deactivated.';
        }

        // ─── Create Journal Entry ───
        if ($action === 'create_journal') {
            $entryDate = trim((string) ($_POST['entry_date'] ?? date('Y-m-d')));
            $description = trim((string) ($_POST['description'] ?? ''));
            $debitAccountId = (int) ($_POST['debit_account_id'] ?? 0);
            $creditAccountId = (int) ($_POST['credit_account_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);

            if ($debitAccountId <= 0 || $creditAccountId <= 0) throw new \RuntimeException('Both debit and credit accounts are required.');
            if ($debitAccountId === $creditAccountId) throw new \RuntimeException('Debit and credit accounts must be different.');
            if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero.');

            $journalNo = generate_journal_no($pdo);

            $stmt = $pdo->prepare("INSERT INTO journal_entries (journal_no, entry_date, description, debit_account_id, credit_account_id, amount, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Draft', ?, NOW())");
            $stmt->execute([$journalNo, $entryDate, $description, $debitAccountId, $creditAccountId, $amount, (int) $user['id']]);
            $success = 'Journal entry ' . $journalNo . ' created as Draft.';
        }

        // ─── Post Journal Entry ───
        if ($action === 'post_journal') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new \RuntimeException('Invalid journal entry.');

            $journal = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ? AND status = 'Draft'");
            $journal->execute([$id]);
            $j = $journal->fetch(PDO::FETCH_ASSOC);
            if (!$j) throw new \RuntimeException('Journal entry not found or not in Draft status.');

            $debitAccountId = (int) $j['debit_account_id'];
            $creditAccountId = (int) $j['credit_account_id'];
            $amount = (float) $j['amount'];
            $entryDate = $j['entry_date'];
            $description = $j['description'] ?? $j['journal_no'];

            $pdo->beginTransaction();
            try {
                // Insert two ledger entries: one Dr, one Cr
                $ins = $pdo->prepare("INSERT INTO ledger_entries (ledger_account_id, entry_date, entry_type, reference_type, reference_id, description, amount, direction, created_by, created_at) VALUES (?, ?, 'Journal', 'journal', ?, ?, ?, ?, ?, NOW())");
                $ins->execute([$debitAccountId, $entryDate, $id, $description, $amount, 'Dr', (int) $user['id']]);
                $ins->execute([$creditAccountId, $entryDate, $id, $description, $amount, 'Cr', (int) $user['id']]);

                // Update current_balance: Dr increases assets/expenses, Cr increases liabilities/income/equity
                $updDebit = $pdo->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $updCredit = $pdo->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $updDebit->execute([$amount, $debitAccountId]);
                $updCredit->execute([$amount, $creditAccountId]);

                // Update journal status
                $updJournal = $pdo->prepare("UPDATE journal_entries SET status = 'Posted' WHERE id = ?");
                $updJournal->execute([$id]);

                $pdo->commit();
                $success = 'Journal entry ' . $j['journal_no'] . ' posted successfully. Ledger balances updated.';
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

        // ─── Cancel Journal Entry ───
        if ($action === 'cancel_journal') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new \RuntimeException('Invalid journal entry.');

            $journal = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ? AND status != 'Cancelled'");
            $journal->execute([$id]);
            $j = $journal->fetch(PDO::FETCH_ASSOC);
            if (!$j) throw new \RuntimeException('Journal entry not found or already cancelled.');

            $pdo->beginTransaction();
            try {
                // If the entry was posted, reverse the ledger entries
                if ($j['status'] === 'Posted') {
                    $debitAccountId = (int) $j['debit_account_id'];
                    $creditAccountId = (int) $j['credit_account_id'];
                    $amount = (float) $j['amount'];
                    $entryDate = $j['entry_date'];
                    $description = 'Reversal of ' . $j['journal_no'];

                    $ins = $pdo->prepare("INSERT INTO ledger_entries (ledger_account_id, entry_date, entry_type, reference_type, reference_id, description, amount, direction, created_by, created_at) VALUES (?, ?, 'Reversal', 'journal', ?, ?, ?, ?, ?, NOW())");
                    $ins->execute([$debitAccountId, $entryDate, $id, $description, $amount, 'Cr', (int) $user['id']]);
                    $ins->execute([$creditAccountId, $entryDate, $id, $description, $amount, 'Dr', (int) $user['id']]);

                    // Reverse balance updates
                    $updDebit = $pdo->prepare("UPDATE ledger_accounts SET current_balance = current_balance - ? WHERE id = ?");
                    $updCredit = $pdo->prepare("UPDATE ledger_accounts SET current_balance = current_balance + ? WHERE id = ?");
                    $updDebit->execute([$amount, $debitAccountId]);
                    $updCredit->execute([$amount, $creditAccountId]);
                }

                $pdo->prepare("UPDATE journal_entries SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
                $pdo->commit();
                $success = 'Journal entry ' . $j['journal_no'] . ' cancelled.';
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: ledger-accounts.php?tab=' . $activeTab . '&success=' . urlencode($success));
        exit;
    }
}

// ── Handle success flash ──
if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}

// ── Fetch data ──
$allAccounts = account_options($pdo);

// Group accounts by type for chart of accounts
$accountsByType = ['Asset' => [], 'Liability' => [], 'Income' => [], 'Expense' => [], 'Equity' => []];
$stmtAll = $pdo->query("SELECT la.*, la.parent_id AS pid FROM ledger_accounts la ORDER BY la.account_type, la.account_code");
$flatAccounts = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
foreach ($flatAccounts as $a) {
    $accountsByType[$a['account_type']][] = $a;
}

// Journal entries with filters
$jPage = max(1, (int) ($_GET['j_p'] ?? 1));
$jLimit = 20;
$jOffset = ($jPage - 1) * $jLimit;
$jWhere = [];
$jParams = [];
$jFrom = trim((string) ($_GET['j_from'] ?? ''));
$jTo = trim((string) ($_GET['j_to'] ?? ''));
$jStatus = trim((string) ($_GET['j_status'] ?? ''));
$jAccount = (int) ($_GET['j_account'] ?? 0);

if ($jFrom !== '') { $jWhere[] = 'je.entry_date >= :j_from'; $jParams['j_from'] = $jFrom; }
if ($jTo !== '') { $jWhere[] = 'je.entry_date <= :j_to'; $jParams['j_to'] = $jTo; }
if ($jStatus !== '' && in_array($jStatus, ['Draft', 'Posted', 'Cancelled'], true)) {
    $jWhere[] = 'je.status = :j_status'; $jParams['j_status'] = $jStatus;
}
if ($jAccount > 0) {
    $jWhere[] = '(je.debit_account_id = :j_acc OR je.credit_account_id = :j_acc)';
    $jParams['j_acc'] = $jAccount;
}
$jWhereSql = $jWhere ? ' WHERE ' . implode(' AND ', $jWhere) : '';

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM journal_entries je" . $jWhereSql);
$stmtCount->execute($jParams);
$jCount = (int) $stmtCount->fetchColumn();

$stmtJe = $pdo->prepare("SELECT je.*, da.account_name AS debit_name, da.account_code AS debit_code, ca.account_name AS credit_name, ca.account_code AS credit_code, u.name AS creator_name
    FROM journal_entries je
    LEFT JOIN ledger_accounts da ON da.id = je.debit_account_id
    LEFT JOIN ledger_accounts ca ON ca.id = je.credit_account_id
    LEFT JOIN users u ON u.id = je.created_by" . $jWhereSql . " ORDER BY je.entry_date DESC, je.id DESC LIMIT :jlim OFFSET :joff");
foreach ($jParams as $k => $v) $stmtJe->bindValue(':' . $k, $v);
$stmtJe->bindValue(':jlim', $jLimit, PDO::PARAM_INT);
$stmtJe->bindValue(':joff', $jOffset, PDO::PARAM_INT);
$stmtJe->execute();
$journalEntries = $stmtJe->fetchAll(PDO::FETCH_ASSOC);
$jTotalPages = max(1, (int) ceil($jCount / $jLimit));

// Totals by type
$typeTotals = [];
foreach ($accountsByType as $type => $accounts) {
    $total = 0.0;
    foreach ($accounts as $a) {
        $total += (float) $a['current_balance'];
    }
    $typeTotals[$type] = $total;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Chart of Accounts – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .tab-bar{display:flex;gap:0;margin-bottom:1.5rem;border-bottom:2px solid #e5e7eb}
        .tab-bar a{padding:.6rem 1.5rem;font-size:.9rem;font-weight:500;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s}
        .tab-bar a.active{color:#1e293b;border-bottom-color:#1e293b;font-weight:700}
        .tab-bar a:hover{color:#1e293b}
        .summary-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
        .summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem 1.15rem;transition:box-shadow .15s ease,transform .15s ease}
        .summary-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
        .summary-card .sc-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
        .summary-card .sc-value{font-size:1.35rem;font-weight:700;margin-top:.2rem;line-height:1;font-variant-numeric:tabular-nums;white-space:nowrap}
        .summary-card.blue{border-left:4px solid #2563eb}.summary-card.blue .sc-value{color:#2563eb}
        .summary-card.red{border-left:4px solid #dc2626}.summary-card.red .sc-value{color:#dc2626}
        .summary-card.green{border-left:4px solid #10b981}.summary-card.green .sc-value{color:#10b981}
        .summary-card.orange{border-left:4px solid #f59e0b}.summary-card.orange .sc-value{color:#f59e0b}
        .summary-card.purple{border-left:4px solid #8b5cf6}.summary-card.purple .sc-value{color:#8b5cf6}
        .account-group{margin-bottom:1.25rem}
        .account-group-header{display:flex;align-items:center;gap:.6rem;padding:.65rem 1rem;border-radius:10px 10px 0 0;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
        .account-group-header.asset{background:#dbeafe;color:#1e40af}
        .account-group-header.liability{background:#fee2e2;color:#991b1b}
        .account-group-header.income{background:#d1fae5;color:#065f46}
        .account-group-header.expense{background:#ffedd5;color:#9a3412}
        .account-group-header.equity{background:#ede9fe;color:#5b21b6}
        .account-group-header .count{margin-left:auto;background:rgba(255,255,255,.6);padding:.15em .5em;border-radius:999px;font-size:.7rem}
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
        .badge-draft{background:#fef3c7;color:#92400e}
        .badge-posted{background:#d1fae5;color:#065f46}
        .badge-cancelled{background:#fee2e2;color:#991b1b}
        .amount-dr{color:#dc2626;font-weight:600}
        .amount-cr{color:#059669;font-weight:600}
        .text-right{text-align:right}
        .text-center{text-align:center}
        .btn-sm{min-height:36px;padding:.4rem .85rem;font-size:.82rem;border-radius:8px}
        .filter-row{display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem}
        .filter-row label{font-size:.8rem;margin-bottom:.2rem}
        .filter-row input,.filter-row select{min-height:38px;padding:.45rem .7rem;border-radius:8px;font-size:.85rem;width:auto}
        .pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1rem;padding:.75rem 1.25rem}
        .page-links{display:flex;gap:.4rem;flex-wrap:wrap}
        .page-links a,.page-links span{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 .5rem;border:1px solid #e2e8f0;border-radius:.375rem;font-size:.8rem;font-weight:500;color:#212529;text-decoration:none}
        .page-links a:hover{background:#e7f1ff;border-color:#0d6efd;color:#0d6efd}
        .page-links .current{background:#0d6efd;border-color:#0d6efd;color:#fff}
        .account-tree-indent{padding-left:1.5rem}
        .no-accounts-msg{padding:2rem;text-align:center;color:#94a3b8}
        @media(max-width:768px){.summary-cards{grid-template-columns:1fr 1fr}.field-grid{grid-template-columns:1fr}.filter-row{flex-direction:column;align-items:stretch}}
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
                    <h1>Chart of Accounts & Journal</h1>
                    <p>Manage your ledger accounts and manual journal entries.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <?php if ($activeTab === 1): ?>
                        <button class="btn btn-sm" onclick="openModal('accountModal')">+ Add Account</button>
                    <?php else: ?>
                        <button class="btn btn-sm" onclick="openModal('journalModal')">+ New Journal Entry</button>
                    <?php endif; ?>
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
            <a href="?tab=1" class="<?= $activeTab === 1 ? 'active' : '' ?>">Chart of Accounts</a>
            <a href="?tab=2" class="<?= $activeTab === 2 ? 'active' : '' ?>">Journal Entries</a>
        </div>

        <!-- ═══════════════════ TAB 1: CHART OF ACCOUNTS ═══════════════════ -->
        <div style="display:<?= $activeTab === 1 ? 'block' : 'none' ?>">

            <div class="summary-cards">
                <?php foreach ($accountsByType as $type => $accts): ?>
                    <?php
                    $cls = strtolower($type);
                    $cardClass = match($type) { 'Asset' => 'blue', 'Liability' => 'red', 'Income' => 'green', 'Expense' => 'orange', 'Equity' => 'purple', default => 'blue' };
                    ?>
                    <div class="summary-card <?= $cardClass ?>">
                        <div class="sc-label"><?= e($type) ?> Accounts</div>
                        <div class="sc-value">₹ <?= number_format($typeTotals[$type], 2) ?></div>
                        <div style="font-size:.78rem;color:#94a3b8;margin-top:.15rem;"><?= count($accts) ?> account(s)</div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php foreach ($accountsByType as $type => $accts): ?>
                <?php if (empty($accts)) continue; ?>
                <?php $cls = strtolower($type); ?>
                <div class="account-group">
                    <div class="account-group-header <?= $cls ?>">
                        <span><?= e($type) ?>s</span>
                        <span class="count"><?= count($accts) ?></span>
                    </div>
                    <div class="panel" style="padding:0;overflow:auto;border-radius:0 0 10px 10px;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th class="text-right">Opening Bal.</th>
                                    <th class="text-right">Current Bal.</th>
                                    <th class="text-center">Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Build parent→children map for tree display
                                $children = [];
                                foreach ($accts as $a) {
                                    $pid = (int) ($a['pid'] ?? 0);
                                    $children[$pid][] = $a;
                                }
                                // Recursive renderer
                                function render_account_rows(array $children, int $parentId, int $depth): void
                                {
                                    if (!isset($children[$parentId])) return;
                                    foreach ($children[$parentId] as $a) {
                                        $indent = str_repeat('— ', $depth);
                                        $isInactive = (int) $a['is_active'] === 0;
                                        $bal = (float) $a['current_balance'];
                                        $balClass = $bal >= 0 ? 'amount-dr' : 'amount-cr';
                                        $statusBadge = $isInactive ? '<span class="badge badge-cancelled">Inactive</span>' : '<span class="badge badge-posted">Active</span>';
                                        ?>
                                        <tr>
                                            <td style="font-family:monospace;white-space:nowrap;"><?= $indent ?><?= e($a['account_code']) ?></td>
                                            <td><strong><?= e($a['account_name']) ?></strong></td>
                                            <td class="text-right">₹ <?= number_format((float) $a['opening_balance'], 2) ?></td>
                                            <td class="text-right <?= $balClass ?>" style="white-space:nowrap;"><?= $bal >= 0 ? 'Dr' : 'Cr' ?> ₹ <?= number_format(abs($bal), 2) ?></td>
                                            <td class="text-center"><?= $statusBadge ?></td>
                                            <td>
                                                <div style="display:flex;gap:.3rem;flex-wrap:nowrap;">
                                                    <?php if (!$isInactive): ?>
                                                        <button class="btn btn-sm btn-soft" onclick="editAccount(<?= (int) $a['id'] ?>,'<?= e($a['account_code']) ?>','<?= e($a['account_name']) ?>','<?= e($a['account_type']) ?>',<?= (int) ($a['pid'] ?? 0) ?>)">Edit</button>
                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Deactivate this account?')">
                                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="delete_account">
                                                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-soft" style="color:#dc2626;font-size:.75rem;">Deactivate</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                        // Render children of this account
                                        render_account_rows($children, (int) $a['id'], $depth + 1);
                                    }
                                }
                                render_account_rows($children, 0, 0);
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php
            // Check if there are any inactive accounts to show separately
            $inactiveAccounts = $pdo->query("SELECT * FROM ledger_accounts WHERE is_active = 0 ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($inactiveAccounts)):
            ?>
                <div class="account-group">
                    <div class="account-group-header" style="background:#f1f5f9;color:#64748b;">
                        <span>Deactivated Accounts</span>
                        <span class="count"><?= count($inactiveAccounts) ?></span>
                    </div>
                    <div class="panel" style="padding:0;overflow:auto;border-radius:0 0 10px 10px;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th class="text-right">Current Bal.</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inactiveAccounts as $a): ?>
                                    <tr style="opacity:.6;">
                                        <td style="font-family:monospace;"><?= e($a['account_code']) ?></td>
                                        <td><?= e($a['account_name']) ?></td>
                                        <td><?= account_type_badge($a['account_type']) ?></td>
                                        <td class="text-right">₹ <?= number_format((float) $a['current_balance'], 2) ?></td>
                                        <td class="text-center"><span class="badge badge-cancelled">Inactive</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════ TAB 2: JOURNAL ENTRIES ═══════════════════ -->
        <div style="display:<?= $activeTab === 2 ? 'block' : 'none' ?>">

            <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1rem;">
                <div class="toolbar">
                    <h3 style="margin:0;font-size:1rem;">Journal Entries</h3>
                    <span style="color:#64748b;font-size:.85rem;"><?= $jCount ?> entries</span>
                </div>
            </div>

            <form method="get" class="filter-row">
                <input type="hidden" name="tab" value="2">
                <div><label>From</label><input type="date" name="j_from" value="<?= e($jFrom) ?>"></div>
                <div><label>To</label><input type="date" name="j_to" value="<?= e($jTo) ?>"></div>
                <div><label>Status</label>
                    <select name="j_status">
                        <option value="">All</option>
                        <option value="Draft" <?= $jStatus === 'Draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="Posted" <?= $jStatus === 'Posted' ? 'selected' : '' ?>>Posted</option>
                        <option value="Cancelled" <?= $jStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div><label>Account</label>
                    <select name="j_account">
                        <option value="">All Accounts</option>
                        <?php foreach ($allAccounts as $opt): ?>
                            <option value="<?= $opt['id'] ?>" <?= $jAccount === $opt['id'] ? 'selected' : '' ?>><?= e($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <a href="?tab=2" class="btn btn-sm btn-soft">Clear</a>
            </form>

            <div class="panel" style="padding:0;overflow:auto;">
                <?php if (empty($journalEntries)): ?>
                    <div class="no-accounts-msg">No journal entries found. Create one to get started.</div>
                <?php else: ?>
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Journal No</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Debit Account</th>
                                <th>Credit Account</th>
                                <th class="text-right">Amount</th>
                                <th class="text-center">Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($journalEntries as $je): ?>
                                <?php
                                $isDraft = $je['status'] === 'Draft';
                                $isPosted = $je['status'] === 'Posted';
                                $isCancelled = $je['status'] === 'Cancelled';
                                $statusCls = strtolower($je['status']);
                                ?>
                                <tr>
                                    <td style="font-family:monospace;white-space:nowrap;"><?= e($je['journal_no']) ?></td>
                                    <td style="white-space:nowrap;"><?= e($je['entry_date']) ?></td>
                                    <td style="max-width:200px;white-space:normal;"><?= e($je['description'] ?? '—') ?></td>
                                    <td>
                                        <span style="font-family:monospace;font-size:.8rem;"><?= e($je['debit_code'] ?? '—') ?></span>
                                        <span style="font-size:.8rem;color:#64748b;display:block;"><?= e($je['debit_name'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <span style="font-family:monospace;font-size:.8rem;"><?= e($je['credit_code'] ?? '—') ?></span>
                                        <span style="font-size:.8rem;color:#64748b;display:block;"><?= e($je['credit_name'] ?? '') ?></span>
                                    </td>
                                    <td class="text-right" style="font-weight:700;white-space:nowrap;">₹ <?= number_format((float) $je['amount'], 2) ?></td>
                                    <td class="text-center"><span class="badge badge-<?= $statusCls ?>"><?= e($je['status']) ?></span></td>
                                    <td>
                                        <?php if ($isDraft): ?>
                                            <div style="display:flex;gap:.3rem;flex-wrap:nowrap;">
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Post this journal entry? This will update ledger balances.')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="post_journal">
                                                    <input type="hidden" name="id" value="<?= (int) $je['id'] ?>">
                                                    <button type="submit" class="btn btn-sm" style="background:#059669;border-color:#059669;font-size:.75rem;">Post</button>
                                                </form>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this journal entry?')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="cancel_journal">
                                                    <input type="hidden" name="id" value="<?= (int) $je['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-soft" style="color:#dc2626;font-size:.75rem;">Cancel</button>
                                                </form>
                                            </div>
                                        <?php elseif ($isPosted): ?>
                                            <span style="font-size:.78rem;color:#94a3b8;">Read-only</span>
                                        <?php else: ?>
                                            <span style="font-size:.78rem;color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($jTotalPages > 1): ?>
                        <div class="pagination">
                            <span style="color:#64748b;font-size:.85rem;">Page <?= $jPage ?> of <?= $jTotalPages ?></span>
                            <div class="page-links">
                                <?php if ($jPage > 1): ?>
                                    <a href="?tab=2&j_p=<?= $jPage - 1 ?>&j_from=<?= urlencode($jFrom) ?>&j_to=<?= urlencode($jTo) ?>&j_status=<?= urlencode($jStatus) ?>&j_account=<?= $jAccount ?>">‹ Prev</a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $jPage - 2); $i <= min($jTotalPages, $jPage + 2); $i++): ?>
                                    <a href="?tab=2&j_p=<?= $i ?>&j_from=<?= urlencode($jFrom) ?>&j_to=<?= urlencode($jTo) ?>&j_status=<?= urlencode($jStatus) ?>&j_account=<?= $jAccount ?>" class="<?= $i === $jPage ? 'current' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($jPage < $jTotalPages): ?>
                                    <a href="?tab=2&j_p=<?= $jPage + 1 ?>&j_from=<?= urlencode($jFrom) ?>&j_to=<?= urlencode($jTo) ?>&j_status=<?= urlencode($jStatus) ?>&j_account=<?= $jAccount ?>">Next ›</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- ═══════════════════ MODALS ═══════════════════ -->

<!-- Create Account Modal -->
<div id="accountModal" class="modal-backdrop">
    <div class="modal" style="max-width:600px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Add Ledger Account</h2>
            <button class="icon-btn" onclick="closeModal('accountModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_account">
            <div class="field-grid">
                <div><label>Account Code *</label><input type="text" name="account_code" placeholder="e.g. 1001" required></div>
                <div><label>Account Name *</label><input type="text" name="account_name" placeholder="e.g. Cash in Hand" required></div>
                <div><label>Account Type *</label>
                    <select name="account_type" required>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Income">Income</option>
                        <option value="Expense">Expense</option>
                        <option value="Equity">Equity</option>
                    </select>
                </div>
                <div><label>Parent Account</label>
                    <select name="parent_id">
                        <option value="0">— None (Top Level) —</option>
                        <?php foreach ($allAccounts as $opt): ?>
                            <option value="<?= $opt['id'] ?>"><?= e($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0"></div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Create Account</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('accountModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Account Modal -->
<div id="editAccountModal" class="modal-backdrop">
    <div class="modal" style="max-width:600px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Edit Ledger Account</h2>
            <button class="icon-btn" onclick="closeModal('editAccountModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_account">
            <input type="hidden" name="id" id="edit_acc_id">
            <div class="field-grid">
                <div><label>Account Code *</label><input type="text" name="account_code" id="edit_acc_code" required></div>
                <div><label>Account Name *</label><input type="text" name="account_name" id="edit_acc_name" required></div>
                <div><label>Account Type *</label>
                    <select name="account_type" id="edit_acc_type" required>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Income">Income</option>
                        <option value="Expense">Expense</option>
                        <option value="Equity">Equity</option>
                    </select>
                </div>
                <div><label>Parent Account</label>
                    <select name="parent_id" id="edit_acc_parent">
                        <option value="0">— None (Top Level) —</option>
                        <?php foreach ($allAccounts as $opt): ?>
                            <option value="<?= $opt['id'] ?>"><?= e($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Update Account</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('editAccountModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Journal Entry Modal -->
<div id="journalModal" class="modal-backdrop">
    <div class="modal" style="max-width:650px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">New Journal Entry</h2>
            <button class="icon-btn" onclick="closeModal('journalModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_journal">
            <div class="field-grid">
                <div><label>Date *</label><input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required></div>
                <div><label>Amount *</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
            </div>
            <div style="margin-top:1rem;">
                <label>Description</label>
                <input type="text" name="description" placeholder="e.g. Adjustment entry for prepaid insurance">
            </div>
            <div style="margin-top:1rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;margin-bottom:.3rem;font-weight:600;color:#dc2626;">Debit Account *</label>
                    <select name="debit_account_id" required style="width:100%;min-height:40px;padding:.5rem .7rem;border-radius:8px;font-size:.85rem;border:1px solid #e2e8f0;">
                        <option value="">Select Account</option>
                        <?php foreach ($allAccounts as $opt): ?>
                            <option value="<?= $opt['id'] ?>"><?= e($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.3rem;font-weight:600;color:#059669;">Credit Account *</label>
                    <select name="credit_account_id" required style="width:100%;min-height:40px;padding:.5rem .7rem;border-radius:8px;font-size:.85rem;border:1px solid #e2e8f0;">
                        <option value="">Select Account</option>
                        <?php foreach ($allAccounts as $opt): ?>
                            <option value="<?= $opt['id'] ?>"><?= e($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:1.25rem;padding:.75rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:.8rem;color:#9a3412;">
                <strong>Note:</strong> Posting will create two ledger entries (one Debit, one Credit) and update the current balance of both accounts. This action cannot be undone without posting a reversal journal entry.
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Create Journal Entry</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('journalModal')">Cancel</button>
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
function editAccount(id, code, name, type, parentId) {
    document.getElementById('edit_acc_id').value = id;
    document.getElementById('edit_acc_code').value = code;
    document.getElementById('edit_acc_name').value = name;
    document.getElementById('edit_acc_type').value = type;
    document.getElementById('edit_acc_parent').value = parentId || 0;
    openModal('editAccountModal');
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
