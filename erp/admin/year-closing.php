<?php
require __DIR__ . '/bootstrap.php';
require_admin_login();

$pdo = $pdo ?? null;

function checklist_items(PDO $pdo, int $fyId): array
{
    $fy = $pdo->prepare("SELECT start_date, end_date FROM financial_years WHERE id = ?");
    $fy->execute([$fyId]);
    $row = $fy->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];

    $start = $row['start_date'];
    $end = $row['end_date'];

    $items = [];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_collections WHERE payment_date BETWEEN ? AND ? AND status = 'Active'");
    $stmt->execute([$start, $end]);
    $items['verified_collections'] = ['label' => 'All fee collections verified', 'count' => (int)$stmt->fetchColumn(), 'pass' => true];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE expense_date BETWEEN ? AND ? AND status = 'Pending'");
    $stmt->execute([$start, $end]);
    $pendingExpenses = (int)$stmt->fetchColumn();
    $items['approved_expenses'] = ['label' => 'All expenses approved', 'count' => $pendingExpenses, 'pass' => $pendingExpenses === 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE expense_date BETWEEN ? AND ? AND status = 'Unpaid'");
    $stmt->execute([$start, $end]);
    $unpaidBills = (int)$stmt->fetchColumn();
    $items['vendor_payments'] = ['label' => 'All vendor payments recorded', 'count' => $unpaidBills, 'pass' => $unpaidBills === 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ledger_entries WHERE entry_date BETWEEN ? AND ? AND reconciliation_status != 'Reconciled'");
    $stmt->execute([$start, $end]);
    $unreconciled = (int)$stmt->fetchColumn();
    $items['bank_reconciliation'] = ['label' => 'Bank reconciliation completed', 'count' => $unreconciled, 'pass' => $unreconciled === 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ledger_entries WHERE entry_date BETWEEN ? AND ? AND cheque_status = 'Uncleared'");
    $stmt->execute([$start, $end]);
    $unclearedCheques = (int)$stmt->fetchColumn();
    $items['cheque_audit'] = ['label' => 'Cheque audit completed', 'count' => $unclearedCheques, 'pass' => $unclearedCheques === 0];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll_items WHERE pay_date BETWEEN ? AND ? AND status = 'Pending'");
    $stmt->execute([$start, $end]);
    $pendingPayroll = (int)$stmt->fetchColumn();
    $items['payroll_processed'] = ['label' => 'Payroll processed', 'count' => $pendingPayroll, 'pass' => $pendingPayroll === 0];

    return $items;
}

function year_summary(PDO $pdo, int $fyId): array
{
    $fy = $pdo->prepare("SELECT start_date, end_date FROM financial_years WHERE id = ?");
    $fy->execute([$fyId]);
    $row = $fy->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [];

    $start = $row['start_date'];
    $end = $row['end_date'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_amount), 0) FROM fee_collections WHERE payment_date BETWEEN ? AND ? AND status = 'Active'");
    $stmt->execute([$start, $end]);
    $feeIncome = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM income_records WHERE income_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $otherIncome = (float)$stmt->fetchColumn();

    $totalIncome = $feeIncome + $otherIncome;

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(net_amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ? AND status IN ('Approved','Paid')");
    $stmt->execute([$start, $end]);
    $totalExpenses = (float)$stmt->fetchColumn();

    $netSurplus = $totalIncome - $totalExpenses;

    $stmt = $pdo->prepare("SELECT account_type, SUM(current_balance) as balance FROM ledger_accounts GROUP BY account_type ORDER BY account_type");
    $stmt->execute([]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total_income' => $totalIncome,
        'total_expenses' => $totalExpenses,
        'net_surplus' => $netSurplus,
        'balances' => $balances,
    ];
}

function open_financial_years(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, label, start_date, end_date FROM financial_years WHERE status = 'Open' ORDER BY start_date DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'close_year') {
        verify_csrf();
        $fyId = (int)($_POST['financial_year_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($fyId <= 0) {
            header('Location: year-closing.php?error=invalid_year');
            exit;
        }

        $fy = $pdo->prepare("SELECT id, label, start_date, end_date, status FROM financial_years WHERE id = ?");
        $fy->execute([$fyId]);
        $fyRow = $fy->fetch(PDO::FETCH_ASSOC);

        if (!$fyRow || $fyRow['status'] !== 'Open') {
            header('Location: year-closing.php?error=year_not_open');
            exit;
        }

        $items = checklist_items($pdo, $fyId);
        foreach ($items as $item) {
            if (!$item['pass']) {
                header('Location: year-closing.php?error=checklist_failed&id=' . $fyId);
                exit;
            }
        }

        $summary = year_summary($pdo, $fyId);

        try {
            $pdo->beginTransaction();

            $closingNote = $notes ?: "Year {$fyRow['label']} closed via system";
            $stmt = $pdo->prepare("INSERT INTO year_closings (financial_year_id, closed_by, closed_at, total_income, total_expenses, net_surplus, notes, status, created_at) VALUES (?, ?, NOW(), ?, ?, ?, ?, 'Completed', NOW())");
            $stmt->execute([
                $fyId,
                $_SESSION['admin_id'] ?? 1,
                $summary['total_income'],
                $summary['total_expenses'],
                $summary['net_surplus'],
                $closingNote,
            ]);

            $stmt = $pdo->prepare("UPDATE financial_years SET status = 'Closed' WHERE id = ?");
            $stmt->execute([$fyId]);

            $startParts = explode('-', $fyRow['start_date']);
            $endParts = explode('-', $fyRow['end_date']);
            $nextStartYear = (int)$endParts[0] + 1;
            $nextStartDate = ($nextStartYear) . '-04-01';
            $nextEndDate = ($nextStartYear + 1) . '-03-31';
            $nextLabel = ($nextStartYear) . '-' . ($nextStartYear + 1);

            $stmt = $pdo->prepare("INSERT INTO financial_years (label, start_date, end_date, status, created_at) VALUES (?, ?, ?, 'Open', NOW())");
            $stmt->execute([$nextLabel, $nextStartDate, $nextEndDate]);
            $newFyId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT id, current_balance FROM ledger_accounts WHERE current_balance != 0");
            $stmt->execute([]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($accounts as $acc) {
                $stmt = $pdo->prepare("UPDATE ledger_accounts SET current_balance = ? WHERE id = ?");
                $stmt->execute([$acc['current_balance'], $acc['id']]);
            }

            $pdo->commit();

            header('Location: year-closing.php?success=closed&id=' . $newFyId);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: year-closing.php?error=transaction_failed');
            exit;
        }
    }

    header('Location: year-closing.php?error=unknown_action');
    exit;
}

$successMsg = $_GET['success'] ?? '';
$errorMsg = $_GET['error'] ?? '';
$selectedFyId = (int)($_GET['id'] ?? 0);
$openYears = open_financial_years($pdo);

$activeFyId = $selectedFyId;
if (!$activeFyId && !empty($openYears)) {
    $activeFyId = (int)$openYears[0]['id'];
}

$items = $activeFyId ? checklist_items($pdo, $activeFyId) : [];
$summary = $activeFyId ? year_summary($pdo, $activeFyId) : [];
$allPass = !empty($items) && array_reduce($items, fn($carry, $item) => $carry && $item['pass'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Year Closing - ERP Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .fy-hero {
            background: linear-gradient(135deg, #1a237e, #283593);
            color: #fff;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .fy-hero h1 { margin: 0 0 0.25rem; font-size: 1.5rem; }
        .fy-hero p { margin: 0; opacity: 0.85; }
        .checklist-grid {
            display: grid;
            gap: 0.75rem;
        }
        .checklist-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
        }
        .checklist-icon {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            color: #fff;
            font-weight: 700;
        }
        .checklist-icon.pass { background: #2e7d32; }
        .checklist-icon.fail { background: #c62828; }
        .checklist-label { flex: 1; font-weight: 500; }
        .checklist-count {
            font-size: 0.8rem;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-weight: 600;
        }
        .checklist-count.pass { background: #e8f5e9; color: #2e7d32; }
        .checklist-count.fail { background: #ffebee; color: #c62828; }
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .kpi-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1.25rem;
            text-align: center;
        }
        .kpi-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .kpi-card .label {
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kpi-card.income .value { color: #2e7d32; }
        .kpi-card.expense .value { color: #c62828; }
        .kpi-card.surplus .value { color: #1565c0; }
        .kpi-card.deficit .value { color: #e65100; }
        .balance-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .balance-table th, .balance-table td {
            padding: 0.6rem 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .balance-table th { font-weight: 600; background: #fafafa; }
        .close-form-card {
            background: #fff;
            border: 2px solid #c62828;
            border-radius: 8px;
            padding: 1.5rem;
        }
        .close-form-card h3 { margin-top: 0; color: #c62828; }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 8px;
            padding: 2rem;
            max-width: 460px;
            width: 90%;
            text-align: center;
        }
        .modal-box h3 { margin-top: 0; }
        .modal-box .modal-actions { margin-top: 1.5rem; display: flex; gap: 0.75rem; justify-content: center; }
        .btn-confirm-close {
            background: #c62828;
            color: #fff;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-confirm-close:hover { background: #b71c1c; }
        .btn-cancel {
            background: #e0e0e0;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-close-year {
            background: #c62828;
            color: #fff;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
        }
        .btn-close-year:hover { background: #b71c1c; }
        .btn-close-year:disabled { background: #ccc; cursor: not-allowed; }
    </style>
</head>
<body>
<div class="container" style="max-width: 1100px; margin: 2rem auto; padding: 0 1.5rem;">

    <?php if ($successMsg === 'closed'): ?>
        <div class="flash flash-success">
            Financial year closed successfully.
            <?php if ($selectedFyId): ?>
                <a href="year-closing.php?id=<?= e((string)$selectedFyId) ?>" style="margin-left: 0.5rem; font-weight:600;">View new financial year</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="flash flash-error">
            <?php
            $messages = [
                'invalid_year' => 'Invalid financial year selected.',
                'year_not_open' => 'Selected financial year is not open.',
                'checklist_failed' => 'Pre-closing checklist items must pass before closing.',
                'transaction_failed' => 'An error occurred. Please try again.',
            ];
            echo $messages[$errorMsg] ?? 'An error occurred.';
            ?>
        </div>
    <?php endif; ?>

    <div class="fy-hero">
        <h1>Financial Year Closing</h1>
        <p>Close the current financial year and open the next. All transactions for the closed year will be locked.</p>
    </div>

    <div class="panel">
        <div class="toolbar">
            <span class="section-title">Pre-Closing Checklist</span>
        </div>
        <?php if (!$activeFyId): ?>
            <p style="padding: 1rem;">No open financial year found.</p>
        <?php else: ?>
            <div class="checklist-grid" style="padding: 1rem;">
                <?php
                $icons = [
                    'verified_collections' => '&#10003;',
                    'approved_expenses' => '&#10003;',
                    'vendor_payments' => '&#10003;',
                    'bank_reconciliation' => '&#10003;',
                    'cheque_audit' => '&#10003;',
                    'payroll_processed' => '&#10003;',
                ];
                foreach ($items as $key => $item):
                ?>
                    <div class="checklist-item">
                        <div class="checklist-icon <?= $item['pass'] ? 'pass' : 'fail' ?>">
                            <?= $item['pass'] ? '&#10003;' : '&#10007;' ?>
                        </div>
                        <div class="checklist-label"><?= e($item['label']) ?></div>
                        <span class="checklist-count <?= $item['pass'] ? 'pass' : 'fail' ?>">
                            <?= $item['count'] ?> item<?= $item['count'] !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($activeFyId && !empty($summary)): ?>
    <div class="panel" style="margin-top: 1.5rem;">
        <div class="toolbar">
            <span class="section-title">Year Summary</span>
            <?php
            $activeLabel = '';
            foreach ($openYears as $oy) {
                if ((int)$oy['id'] === $activeFyId) {
                    $activeLabel = $oy['label'];
                    break;
                }
            }
            ?>
            <?php if ($activeLabel): ?>
                <span class="badge badge-info"><?= e($activeLabel) ?></span>
            <?php endif; ?>
        </div>
        <div style="padding: 1rem;">
            <div class="kpi-row">
                <div class="kpi-card income">
                    <div class="value"><?= e(number_format($summary['total_income'], 2)) ?></div>
                    <div class="label">Total Income</div>
                </div>
                <div class="kpi-card expense">
                    <div class="value"><?= e(number_format($summary['total_expenses'], 2)) ?></div>
                    <div class="label">Total Expenses</div>
                </div>
                <div class="kpi-card <?= $summary['net_surplus'] >= 0 ? 'surplus' : 'deficit' ?>">
                    <div class="value"><?= $summary['net_surplus'] >= 0 ? '+' : '' ?><?= e(number_format($summary['net_surplus'], 2)) ?></div>
                    <div class="label">Net <?= $summary['net_surplus'] >= 0 ? 'Surplus' : 'Deficit' ?></div>
                </div>
            </div>

            <?php if (!empty($summary['balances'])): ?>
            <table class="balance-table">
                <thead>
                    <tr>
                        <th>Account Type</th>
                        <th style="text-align:right;">Current Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['balances'] as $bal): ?>
                        <tr>
                            <td><?= e($bal['account_type']) ?></td>
                            <td style="text-align:right; font-weight:600;"><?= e(number_format((float)$bal['balance'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($activeFyId): ?>
    <div class="panel" style="margin-top: 1.5rem;">
        <div class="toolbar">
            <span class="section-title">Close Year</span>
        </div>
        <div style="padding: 1rem;">
            <div class="close-form-card">
                <h3>Close Financial Year</h3>
                <form method="POST" id="closeYearForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="close_year">

                    <div class="field-grid" style="margin-bottom: 1rem;">
                        <div>
                            <label for="financial_year_id" style="font-weight:600; display:block; margin-bottom:0.3rem;">Financial Year</label>
                            <select name="financial_year_id" id="financial_year_id" style="width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px;" required>
                                <?php foreach ($openYears as $oy): ?>
                                    <option value="<?= e((string)$oy['id']) ?>" <?= (int)$oy['id'] === $activeFyId ? 'selected' : '' ?>>
                                        <?= e($oy['label']) ?> (<?= e($oy['start_date']) ?> to <?= e($oy['end_date']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label for="notes" style="font-weight:600; display:block; margin-bottom:0.3rem;">Closing Notes (optional)</label>
                        <textarea name="notes" id="notes" rows="3" style="width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px; resize:vertical;" placeholder="Any notes about this year's closing..."></textarea>
                    </div>

                    <button type="button" class="btn-close-year" id="closeYearBtn" <?= !$allPass ? 'disabled title="Complete all checklist items first"' : '' ?>>
                        Close Financial Year
                    </button>
                    <?php if (!$allPass): ?>
                        <p style="color:#c62828; font-size:0.85rem; margin-top:0.5rem; text-align:center;">
                            All checklist items must pass before closing.
                        </p>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <h3>Confirm Year Closing</h3>
        <p id="confirmText">This will close the financial year and create the next year. All transactions for the closed year will be locked.</p>
        <div class="modal-actions">
            <button class="btn-cancel" id="cancelClose">Cancel</button>
            <button class="btn-confirm-close" id="confirmClose">Yes, Close Year</button>
        </div>
    </div>
</div>

<script>
(function() {
    const btn = document.getElementById('closeYearBtn');
    const modal = document.getElementById('confirmModal');
    const cancelBtn = document.getElementById('cancelClose');
    const confirmBtn = document.getElementById('confirmClose');
    const form = document.getElementById('closeYearForm');
    const select = document.getElementById('financial_year_id');
    const confirmText = document.getElementById('confirmText');

    if (btn) {
        btn.addEventListener('click', function() {
            const opt = select.options[select.selectedIndex];
            const label = opt ? opt.text : 'this year';
            confirmText.textContent = 'This will close ' + label + ' and create the next financial year (April-March). All transactions will be locked.';
            modal.classList.add('active');
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            modal.classList.remove('active');
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            modal.classList.remove('active');
            form.submit();
        });
    }

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) modal.classList.remove('active');
        });
    }
})();
</script>
</body>
</html>
