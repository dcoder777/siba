<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$error = '';
$success = '';

$exportType = trim((string) ($_POST['export_type'] ?? $_GET['export_type'] ?? 'chart_of_accounts'));
$fromDate = trim((string) ($_POST['from_date'] ?? $_GET['from_date'] ?? ''));
$toDate = trim((string) ($_POST['to_date'] ?? $_GET['to_date'] ?? ''));
$allowedTypes = ['chart_of_accounts', 'ledgers', 'receipts', 'payments', 'journals', 'all'];
if (!in_array($exportType, $allowedTypes, true)) $exportType = 'chart_of_accounts';

$xmlOutput = '';
$download = trim((string) ($_GET['download'] ?? ''));

function tallyGroup(string $accountType): string
{
    return match ($accountType) {
        'asset' => 'Current Assets',
        'liability' => 'Current Liabilities',
        'income' => 'Direct Incomes',
        'expense' => 'Direct Expenses',
        'equity' => 'Reserves & Surplus',
        default => 'Primary',
    };
}

function formatTallyDate(string $date): string
{
    return str_replace('-', '', $date);
}

function buildEnvelope(string $inner): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<ENVELOPE>' . "\n"
        . '    <HEADER>' . "\n"
        . '        <TALLYREQUEST>Import Data</TALLYREQUEST>' . "\n"
        . '        <TYPE>Data</TYPE>' . "\n"
        . '    </HEADER>' . "\n"
        . '    <BODY>' . "\n"
        . '        <DESC>' . "\n"
        . '            <STATICVARIABLES>' . "\n"
        . '                <SVCURRENTCOMPANY>SIBA Public School</SVCURRENTCOMPANY>' . "\n"
        . '            </STATICVARIABLES>' . "\n"
        . '        </DESC>' . "\n"
        . '        <DATA>' . "\n"
        . '            <TALLYMESSAGE xmlns:UDF="TallyUDF">' . "\n"
        . $inner
        . '            </TALLYMESSAGE>' . "\n"
        . '        </DATA>' . "\n"
        . '    </BODY>' . "\n"
        . '</ENVELOPE>' . "\n";
}

function ledgerXml(string $name, string $group): string
{
    return '                <LEDGER NAME="' . e($name) . '">' . "\n"
        . '                    <PARENT>' . e($group) . '</PARENT>' . "\n"
        . '                    <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>' . "\n"
        . '                </LEDGER>' . "\n";
}

function voucherXml(string $type, string $date, string $number, string $partyLedger, string $partyName, array $entries): string
{
    $xml = '                <VOUCHER VCHTYPE="' . e($type) . '" ACTION="Create">' . "\n"
        . '                    <DATE>' . formatTallyDate($date) . '</DATE>' . "\n"
        . '                    <VOUCHERNUMBER>' . e($number) . '</VOUCHERNUMBER>' . "\n"
        . '                    <PARTYLEDGERNAME>' . e($partyLedger) . '</PARTYLEDGERNAME>' . "\n"
        . '                    <PARTYNAME>' . e($partyName) . '</PARTYNAME>' . "\n"
        . '                    <VOUCHERTYPENAME>' . e($type) . '</VOUCHERTYPENAME>' . "\n";
    foreach ($entries as $entry) {
        $xml .= '                    <ALLLEDGERENTRIES.LIST>' . "\n"
            . '                        <LEDGERNAME>' . e((string) ($entry['ledger'] ?? '')) . '</LEDGERNAME>' . "\n"
            . '                        <ISDEEMEDPOSITIVE>' . e((string) ($entry['is_deemed_positive'] ?? 'Yes')) . '</ISDEEMEDPOSITIVE>' . "\n"
            . '                        <AMOUNT>' . e((string) ($entry['amount'] ?? '0.00')) . '</AMOUNT>' . "\n"
            . '                    </ALLLEDGERENTRIES.LIST>' . "\n";
    }
    $xml .= '                </VOUCHER>' . "\n";
    return $xml;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    try {
        $xmlPieces = [];

        // ── Chart of Accounts ──
        if (in_array($exportType, ['chart_of_accounts', 'all'], true)) {
            $stmt = $pdo->query("SELECT account_name, account_type FROM ledger_accounts WHERE is_active = 1 ORDER BY account_code");
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($accounts as $acc) {
                $xmlPieces[] = ledgerXml($acc['account_name'], tallyGroup($acc['account_type']));
            }
        }

        // ── Ledgers (with opening balances) ──
        if (in_array($exportType, ['ledgers', 'all'], true)) {
            $stmt = $pdo->query("SELECT account_name, account_type, opening_balance FROM ledger_accounts WHERE is_active = 1 ORDER BY account_code");
            $ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ledgers as $led) {
                $bal = (float) $led['opening_balance'];
                $xmlPieces[] = '                <LEDGER NAME="' . e($led['account_name']) . '">' . "\n"
                    . '                    <PARENT>' . e(tallyGroup($led['account_type'])) . '</PARENT>' . "\n"
                    . '                    <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>' . "\n"
                    . '                    <OPENINGBALANCE>' . number_format($bal, 2, '.', '') . '</OPENINGBALANCE>' . "\n"
                    . '                </LEDGER>' . "\n";
            }
        }

        // ── Receipt Vouchers ──
        if (in_array($exportType, ['receipts', 'all'], true)) {
            $sql = "SELECT * FROM fee_collections WHERE status = 'Active'";
            $params = [];
            if ($fromDate !== '') { $sql .= " AND payment_date >= :rfrm"; $params['rfrm'] = $fromDate; }
            if ($toDate !== '') { $sql .= " AND payment_date <= :rto"; $params['rto'] = $toDate; }
            $sql .= " ORDER BY payment_date, id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $rcpSeq = 1;
            $rcpYear = date('Y');
            foreach ($receipts as $rcp) {
                $studentName = $rcp['student_name'] ?? 'Student';
                $netAmount = (float) $rcp['net_amount'];
                $paymentMode = $rcp['payment_mode'] ?? 'Cash';
                $receiptNo = $rcp['receipt_no'] ?? ('RCP-' . $rcpYear . '-' . str_pad((string) $rcpSeq, 6, '0', STR_PAD_LEFT));
                $rcpSeq++;

                $cashLedger = 'Cash in Hand';
                if ($paymentMode === 'Cheque') $cashLedger = 'Bank Accounts';
                elseif ($paymentMode === 'UPI' || $paymentMode === 'Card' || $paymentMode === 'Bank Transfer') $cashLedger = 'Bank Accounts';

                $entries = [
                    ['ledger' => $cashLedger, 'is_deemed_positive' => 'Yes', 'amount' => number_format($netAmount, 2, '.', '')],
                    ['ledger' => 'Tuition Fee Income', 'is_deemed_positive' => 'No', 'amount' => number_format(-$netAmount, 2, '.', '')],
                ];

                $xmlPieces[] = voucherXml('Receipt', $rcp['payment_date'], $receiptNo, $studentName, $studentName, $entries);
            }
        }

        // ── Payment Vouchers ──
        if (in_array($exportType, ['payments', 'all'], true)) {
            $sql = "SELECT e.*, ec.name AS category_name FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id WHERE e.status = 'Approved'";
            $params = [];
            if ($fromDate !== '') { $sql .= " AND e.payment_date >= :pfrm"; $params['pfrm'] = $fromDate; }
            if ($toDate !== '') { $sql .= " AND e.payment_date <= :pto"; $params['pto'] = $toDate; }
            $sql .= " ORDER BY e.payment_date, e.id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paySeq = 1;
            $payYear = date('Y');
            foreach ($expenses as $exp) {
                $vendorName = $exp['vendor_name'] ?? $exp['payee_name'] ?? 'Vendor';
                $payAmount = (float) $exp['net_amount'];
                $paymentMode = $exp['payment_mode'] ?? 'Cash';
                $payNo = 'PAY-' . $payYear . '-' . str_pad((string) $paySeq, 6, '0', STR_PAD_LEFT);
                $paySeq++;

                $cashLedger = 'Cash in Hand';
                if ($paymentMode === 'Cheque' || $paymentMode === 'UPI' || $paymentMode === 'Card' || $paymentMode === 'Bank Transfer') $cashLedger = 'Bank Accounts';

                $expenseLedger = $exp['category_name'] ?? 'Miscellaneous Expenses';
                $expenseLedgerMap = [
                    'Salary' => 'Salary Expenses',
                    'Electricity' => 'Utility Expenses',
                    'Maintenance' => 'Maintenance Expenses',
                    'Stationery' => 'Stationery Expenses',
                    'Transport' => 'Transport Expenses',
                    'Events' => 'Event Expenses',
                ];
                $expenseLedger = $expenseLedgerMap[$expenseLedger] ?? 'Miscellaneous Expenses';

                $entries = [
                    ['ledger' => $expenseLedger, 'is_deemed_positive' => 'No', 'amount' => number_format(-$payAmount, 2, '.', '')],
                    ['ledger' => $cashLedger, 'is_deemed_positive' => 'Yes', 'amount' => number_format($payAmount, 2, '.', '')],
                ];

                $xmlPieces[] = voucherXml('Payment', $exp['payment_date'] ?? date('Y-m-d'), $payNo, $vendorName, $vendorName, $entries);
            }
        }

        // ── Journal Vouchers ──
        if (in_array($exportType, ['journals', 'all'], true)) {
            $sql = "SELECT le.*, la.account_name FROM ledger_entries le JOIN ledger_accounts la ON la.id = le.ledger_account_id WHERE le.entry_type = 'journal'";
            $params = [];
            if ($fromDate !== '') { $sql .= " AND le.entry_date >= :jfrm"; $params['jfrm'] = $fromDate; }
            if ($toDate !== '') { $sql .= " AND le.entry_date <= :jto"; $params['jto'] = $toDate; }
            $sql .= " ORDER BY le.entry_date, le.id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group entries by reference_id and entry_date for journal vouchers
            $groups = [];
            foreach ($entries as $entry) {
                $key = $entry['entry_date'] . '-' . ($entry['reference_id'] ?? $entry['id']);
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'date' => $entry['entry_date'],
                        'description' => $entry['description'] ?? 'Journal Entry',
                        'id' => $entry['reference_id'] ?? $entry['id'],
                        'ledger_entries' => [],
                    ];
                }
                $groups[$key]['ledger_entries'][] = $entry;
            }

            $jrnlSeq = 1;
            $jrnlYear = date('Y');
            foreach ($groups as $group) {
                $jrnlNo = 'JRNL-' . $jrnlYear . '-' . str_pad((string) $jrnlSeq, 6, '0', STR_PAD_LEFT);
                $jrnlSeq++;

                $voucherEntries = [];
                foreach ($group['ledger_entries'] as $ge) {
                    $amt = (float) $ge['amount'];
                    $isDeemedPositive = $ge['direction'] === 'credit' ? 'No' : 'Yes';
                    $displayAmt = $ge['direction'] === 'credit' ? number_format(-$amt, 2, '.', '') : number_format($amt, 2, '.', '');
                    $voucherEntries[] = [
                        'ledger' => $ge['account_name'],
                        'is_deemed_positive' => $isDeemedPositive,
                        'amount' => $displayAmt,
                    ];
                }

                $xmlPieces[] = voucherXml('Journal', $group['date'], $jrnlNo, $group['description'], $group['description'], $voucherEntries);
            }
        }

        if (empty($xmlPieces)) {
            $error = 'No data found for the selected export criteria.';
        } else {
            $xmlOutput = buildEnvelope(implode('', $xmlPieces));
            $success = 'XML generated successfully.';
        }
    } catch (\Throwable $e) {
        $error = 'Export error: ' . $e->getMessage();
    }
}

// ── Handle download ──
if ($download === 'xml' && $xmlOutput !== '') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="tally_export_' . date('Ymd_His') . '.xml"');
    echo $xmlOutput;
    exit;
}

$todayCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_collections WHERE payment_date = CURDATE() AND status = 'Active'");
    $stmt->execute();
    $todayCount = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {}

$pendingExpenseCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'Pending'");
    $stmt->execute();
    $pendingExpenseCount = (int) $stmt->fetchColumn();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tally Export – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .export-options { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.25rem; }
        .export-options label { display:flex; align-items:center; gap:.4rem; padding:.5rem 1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; font-size:.85rem; font-weight:500; transition:background .15s,border-color .15s; }
        .export-options label:hover { background:#f1f5f9; }
        .export-options input[type="radio"]:checked + span { color:#0f172a; font-weight:700; }
        .export-options input[type="radio"]:checked ~ * { color:#0f172a; }
        .export-options label:has(input:checked) { background:#e2e8f0; border-color:#94a3b8; }
        .filter-row { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1.25rem; }
        .filter-row label { font-size:.8rem; margin-bottom:.2rem; }
        .filter-row input { min-height:38px; padding:.45rem .7rem; border-radius:8px; font-size:.85rem; width:auto; }
        .filter-row .btn { min-height:38px; padding:.45rem 1rem; font-size:.85rem; }
        .xml-output { width:100%; min-height:400px; font-family:'Consolas','Courier New',monospace; font-size:.78rem; padding:.75rem; border:1px solid #e2e8f0; border-radius:8px; background:#1e293b; color:#e2e8f0; resize:vertical; white-space:pre; tab-size:4; }
        .action-bar { display:flex; gap:.5rem; margin-top:1rem; flex-wrap:wrap; }
        .copy-toast { position:fixed; bottom:2rem; right:2rem; background:#1e293b; color:#fff; padding:.75rem 1.25rem; border-radius:8px; font-size:.85rem; opacity:0; transition:opacity .3s; pointer-events:none; z-index:999; }
        .copy-toast.show { opacity:1; }
        .stat-summary { display:flex; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
        .stat-chip { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.5rem 1rem; font-size:.82rem; color:#64748b; }
        .stat-chip strong { color:#0f172a; }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <aside class="sidebar" style="display:flex;flex-direction:column;">
        <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
            <span class="eyebrow">SIBA ERP</span>
            <div class="brand-copy">
                <h2>Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
        </div>
        <div class="nav-group">
            <div class="nav-title">Admissions</div>
            <a class="nav-link" href="application-intake.php">
                <span class="sidebar-icon">📋</span><span>Application Intake</span><span class="nav-tag">New</span>
            </a>
            <a class="nav-link" href="applications-list.php">
                <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag">List</span>
            </a>
            <a class="nav-link" href="parents-list.php">
                <span class="sidebar-icon">👤</span><span>Parents</span>
            </a>
            <a class="nav-link" href="events-manager.php">
                <span class="sidebar-icon">📅</span><span>Events & News</span>
            </a>
            <a class="nav-link" href="gallery-manager.php">
                <span class="sidebar-icon">🖼</span><span>Gallery</span>
            </a>
            <a class="nav-link" href="enquiries.php">
                <span class="sidebar-icon">📩</span><span>Enquiries</span>
            </a>
        </div>

        <div class="nav-group">
            <div class="nav-title">Finance</div>
            <a class="nav-link" href="finance-dashboard.php"><span class="sidebar-icon">📊</span><span>Finance Dashboard</span></a>
            <a class="nav-link" href="fee-structures.php"><span class="sidebar-icon">🏗</span><span>Fee Structures</span></a>
            <a class="nav-link" href="fee-collection.php"><span class="sidebar-icon">💰</span><span>Fee Collection</span></a>
            <a class="nav-link" href="receipts-list.php"><span class="sidebar-icon">🧾</span><span>Receipts</span></a>
            <a class="nav-link" href="expenses.php"><span class="sidebar-icon">📤</span><span>Expenses</span></a>
            <a class="nav-link" href="income.php"><span class="sidebar-icon">📥</span><span>Income</span></a>
            <a class="nav-link" href="accounts.php"><span class="sidebar-icon">🏦</span><span>Accounts</span></a>
            <a class="nav-link" href="reports.php"><span class="sidebar-icon">📈</span><span>Reports</span></a>
            <a class="nav-link active" href="tally-export.php"><span class="sidebar-icon">📤</span><span>Tally Export</span></a>
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
                    <h1>Tally Export</h1>
                    <p>Export financial data in Tally XML format for import into Tally accounting software.</p>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post" class="panel" style="padding:1.25rem;">
            <h2 style="margin-bottom:.75rem;">Export Options</h2>

            <div class="export-options">
                <label><input type="radio" name="export_type" value="chart_of_accounts" <?= $exportType === 'chart_of_accounts' ? 'checked' : '' ?>><span>Chart of Accounts</span></label>
                <label><input type="radio" name="export_type" value="ledgers" <?= $exportType === 'ledgers' ? 'checked' : '' ?>><span>Ledgers</span></label>
                <label><input type="radio" name="export_type" value="receipts" <?= $exportType === 'receipts' ? 'checked' : '' ?>><span>Receipt Vouchers</span></label>
                <label><input type="radio" name="export_type" value="payments" <?= $exportType === 'payments' ? 'checked' : '' ?>><span>Payment Vouchers</span></label>
                <label><input type="radio" name="export_type" value="journals" <?= $exportType === 'journals' ? 'checked' : '' ?>><span>Journal Vouchers</span></label>
                <label><input type="radio" name="export_type" value="all" <?= $exportType === 'all' ? 'checked' : '' ?>><span>All Combined</span></label>
            </div>

            <div class="filter-row">
                <div><label>From Date</label><input type="date" name="from_date" value="<?= e($fromDate) ?>"></div>
                <div><label>To Date</label><input type="date" name="to_date" value="<?= e($toDate) ?>"></div>
                <button type="submit" name="generate" class="btn" style="min-height:38px;padding:.45rem 1.5rem;font-size:.85rem;">Generate XML</button>
            </div>
        </form>

        <?php if ($xmlOutput !== ''): ?>
        <section class="panel" style="padding:1.25rem;margin-top:1rem;">
            <div class="toolbar" style="margin-bottom:.75rem;">
                <h2>Generated XML</h2>
                <span style="color:#64748b;font-size:.82rem;"><?= number_format(strlen($xmlOutput)) ?> bytes</span>
            </div>
            <textarea class="xml-output" id="xmlOutput" readonly><?= e($xmlOutput) ?></textarea>
            <div class="action-bar">
                <button class="btn btn-sm" onclick="copyXml()">Copy to Clipboard</button>
                <a href="?download=xml&export_type=<?= e($exportType) ?>&from_date=<?= e($fromDate) ?>&to_date=<?= e($toDate) ?>" class="btn btn-sm btn-soft">Download XML</a>
            </div>
        </section>
        <?php endif; ?>
    </main>
</div>

<div class="copy-toast" id="copyToast">Copied to clipboard!</div>

<script>
function copyXml() {
    var ta = document.getElementById('xmlOutput');
    if (!ta) return;
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    try {
        document.execCommand('copy');
        var toast = document.getElementById('copyToast');
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 2000);
    } catch (e) {}
}
</script>
<script src="../assets/erp.js"></script>
</body>
</html>
