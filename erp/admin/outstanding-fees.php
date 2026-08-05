<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$pageTitle = 'Outstanding Fees';

$sessionFilter = trim((string) ($_GET['session'] ?? ''));
$classFilter   = trim((string) ($_GET['class'] ?? ''));
$searchName    = trim((string) ($_GET['q'] ?? ''));
$ledgerStudent = (int) ($_GET['ledger'] ?? 0);

// ── Helper: safe scalar query ──
function out_scalar(PDO $pdo, string $sql, array $params = []): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

// ── Build WHERE clause ──
$where = "WHERE sfa.status = 'active' AND sfa.balance > 0";
$params = [];

if ($sessionFilter !== '') {
    $where .= " AND sfa.academic_session = :session";
    $params['session'] = $sessionFilter;
}
if ($classFilter !== '') {
    $where .= " AND sfa.class_name = :class";
    $params['class'] = $classFilter;
}
if ($searchName !== '') {
    $where .= " AND sfa.student_name LIKE :q";
    $params['q'] = '%' . $searchName . '%';
}

// ── Distinct sessions & classes for filters ──
$sessionOptions = [];
try {
    $sessionOptions = array_map(
        static fn(array $r): string => (string) $r['academic_session'],
        $pdo->query("SELECT DISTINCT academic_session FROM student_fee_accounts WHERE academic_session IS NOT NULL AND academic_session != '' ORDER BY academic_session DESC")->fetchAll(PDO::FETCH_ASSOC)
    );
} catch (Throwable) {}

$classOptions = [];
try {
    $classOptions = array_map(
        static fn(array $r): string => (string) $r['class_name'],
        $pdo->query("SELECT DISTINCT class_name FROM student_fee_accounts WHERE class_name IS NOT NULL AND class_name != '' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC)
    );
} catch (Throwable) {}

// ── Stats ──
$totalOutstanding = out_scalar($pdo, "SELECT COALESCE(SUM(balance), 0) FROM student_fee_accounts sfa $where", $params);
$studentCount     = (int) out_scalar($pdo, "SELECT COUNT(*) FROM student_fee_accounts sfa $where", $params);
$avgDues          = $studentCount > 0 ? $totalOutstanding / $studentCount : 0.0;

// ── Fetch rows ──
$rows = [];
try {
    $sql = "SELECT sfa.id, sfa.student_id, sfa.student_name, sfa.class_name, sfa.academic_session,
                   sfa.total_fee, sfa.total_paid, sfa.total_discount, sfa.total_late_fee, sfa.balance, sfa.status,
                   COALESCE(sfa2.admission_no, '') AS admission_no
            FROM student_fee_accounts sfa
            LEFT JOIN student_fee_assignments sfa2
                   ON sfa2.student_id = sfa.student_id
                  AND sfa2.academic_session = sfa.academic_session
            $where
            ORDER BY sfa.balance DESC, sfa.student_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $rows = [];
}

// ── Ledger modal data ──
$ledgerAccount = null;
$ledgerCollections = [];
if ($ledgerStudent > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM student_fee_accounts WHERE id = :id AND balance > 0");
        $stmt->execute(['id' => $ledgerStudent]);
        $ledgerAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {}

    if ($ledgerAccount) {
        try {
            $cStmt = $pdo->prepare(
                "SELECT receipt_no, student_name, net_amount, discount_amount, late_fee, payment_mode, payment_date, created_at
                 FROM fee_collections
                 WHERE student_id = :sid AND status = 'Active'
                 ORDER BY payment_date DESC, created_at DESC"
            );
            $cStmt->execute(['sid' => (int) $ledgerAccount['student_id']]);
            $ledgerCollections = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Outstanding Fees – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:1.25rem; margin-bottom:1.75rem; }
        .kpi-card { background:#fff; border-radius:14px; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04); display:flex; flex-direction:column; gap:.35rem; }
        .kpi-card .kpi-label { font-size:.8rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
        .kpi-card .kpi-value { font-size:1.6rem; font-weight:700; color:#0f172a; }
        .kpi-card.danger { border-left:4px solid #ef4444; }
        .kpi-card.warning { border-left:4px solid #f59e0b; }
        .kpi-card.info { border-left:4px solid #2563eb; }
        .app-filters { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1.25rem; }
        .app-filters label { font-size:.8rem; margin-bottom:.2rem; display:block; }
        .app-filters input, .app-filters select { min-height:38px; padding:.45rem .7rem; border-radius:8px; font-size:.85rem; border:1px solid #cbd5e1; }
        .app-filters .btn { min-height:38px; padding:.45rem 1rem; font-size:.85rem; }
        .app-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .app-table th { text-align:left; padding:.65rem .5rem; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        .app-table td { padding:.65rem .5rem; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
        .app-table tr:hover td { background:#f8fafc; }
        .app-table tfoot td { font-weight:700; background:#f1f5f9; border-top:2px solid #cbd5e1; }
        .badge { display:inline-block; padding:.18rem .55rem; border-radius:4px; font-size:.75rem; font-weight:600; }
        .badge-active { background:#d1fae5; color:#065f46; }
        .badge-closed { background:#e2e8f0; color:#475569; }
        .badge-overdue { background:#fee2e2; color:#991b1b; }
        .badge-partial { background:#fef3c7; color:#92400e; }
        .balance-cell { color:#ef4444; font-weight:700; }
        .ledger-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1000; align-items:center; justify-content:center; }
        .ledger-overlay.open { display:flex; }
        .ledger-modal { background:#fff; border-radius:14px; width:min(92vw, 820px); max-height:88vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); }
        .ledger-header { display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem; border-bottom:1px solid #e2e8f0; }
        .ledger-body { padding:1.5rem; }
        .summary-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:1rem; margin-bottom:1.25rem; }
        .summary-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:.85rem; text-align:center; }
        .summary-card .label { font-size:.72rem; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.2rem; }
        .summary-card .value { font-size:1.15rem; font-weight:700; color:#1e293b; }
        @media (max-width:768px) { .kpi-grid { grid-template-columns:1fr; } .summary-cards { grid-template-columns:1fr 1fr; } }
        @media print { .no-print { display:none !important; } .ledger-overlay { display:none !important; } }
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
                    <h1>Outstanding Fees</h1>
                    <p>View all students with pending fee balances and payment history.</p>
                </div>
            </div>
        </section>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card danger">
                <div class="kpi-label">Total Outstanding</div>
                <div class="kpi-value">Rs. <?= number_format($totalOutstanding, 2) ?></div>
            </div>
            <div class="kpi-card warning">
                <div class="kpi-label">Students with Dues</div>
                <div class="kpi-value"><?= number_format($studentCount) ?></div>
            </div>
            <div class="kpi-card info">
                <div class="kpi-label">Average Dues</div>
                <div class="kpi-value">Rs. <?= number_format($avgDues, 2) ?></div>
            </div>
        </div>

        <!-- Filters -->
        <section class="panel no-print" style="padding:1.25rem;margin-bottom:1.25rem;">
            <form method="get" class="app-filters">
                <div>
                    <label for="session">Academic Session</label>
                    <select id="session" name="session">
                        <option value="">All Sessions</option>
                        <?php foreach ($sessionOptions as $s): ?>
                            <option value="<?= e($s) ?>" <?= $sessionFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="class">Class</label>
                    <select id="class" name="class">
                        <option value="">All Classes</option>
                        <?php foreach ($classOptions as $c): ?>
                            <option value="<?= e($c) ?>" <?= $classFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="q">Search by Name</label>
                    <input type="text" id="q" name="q" placeholder="Student name..." value="<?= e($searchName) ?>" style="min-width:220px;">
                </div>
                <div style="display:flex;gap:.5rem;">
                    <button type="submit" class="btn">Apply</button>
                    <a href="outstanding-fees.php" class="btn btn-soft" style="text-decoration:none;">Clear</a>
                </div>
            </form>
        </section>

        <!-- Table -->
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title" style="margin-bottom:1rem;">
                <div>
                    <h2>Students with Pending Dues</h2>
                    <p><?= number_format($studentCount) ?> student<?= $studentCount !== 1 ? 's' : '' ?> with outstanding balances</p>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div style="text-align:center;padding:3rem;color:#94a3b8;">
                    <div style="font-size:2rem;margin-bottom:.5rem;">No outstanding dues found.</div>
                    <p>All students are up to date with their fee payments.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Admission No</th>
                                <th>Class</th>
                                <th>Session</th>
                                <th style="text-align:right;">Total Fee</th>
                                <th style="text-align:right;">Paid</th>
                                <th style="text-align:right;">Balance</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($rows as $row): ?>
                            <tr>
                                <td style="color:#94a3b8;"><?= $i++ ?></td>
                                <td><strong><?= e($row['student_name']) ?></strong></td>
                                <td style="font-family:monospace;font-size:.82rem;"><?= e($row['admission_no']) ?></td>
                                <td><?= e($row['class_name']) ?></td>
                                <td style="font-size:.82rem;"><?= e($row['academic_session']) ?></td>
                                <td style="text-align:right;">Rs. <?= number_format((float) $row['total_fee'], 2) ?></td>
                                <td style="text-align:right;">Rs. <?= number_format((float) $row['total_paid'], 2) ?></td>
                                <td class="balance-cell" style="text-align:right;">Rs. <?= number_format((float) $row['balance'], 2) ?></td>
                                <td>
                                    <?php
                                    $statusLabel = 'Active';
                                    $badgeClass = 'badge-active';
                                    if ((float) $row['total_paid'] === 0) {
                                        $statusLabel = 'Unpaid';
                                        $badgeClass = 'badge-overdue';
                                    } elseif ((float) $row['total_paid'] > 0 && (float) $row['balance'] > 0) {
                                        $statusLabel = 'Partial';
                                        $badgeClass = 'badge-partial';
                                    }
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                                </td>
                                <td>
                                    <a href="?ledger=<?= (int) $row['id'] ?>&session=<?= urlencode($sessionFilter) ?>&class=<?= urlencode($classFilter) ?>&q=<?= urlencode($searchName) ?>"
                                       class="btn btn-sm btn-soft"
                                       style="text-decoration:none;white-space:nowrap;">View Ledger</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" style="text-align:right;">Grand Total</td>
                                <td style="text-align:right;">Rs. <?= number_format(array_sum(array_column($rows, 'total_fee')), 2) ?></td>
                                <td style="text-align:right;">Rs. <?= number_format(array_sum(array_column($rows, 'total_paid')), 2) ?></td>
                                <td class="balance-cell" style="text-align:right;">Rs. <?= number_format(array_sum(array_column($rows, 'balance')), 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- Ledger Modal -->
<?php if ($ledgerAccount): ?>
<div class="ledger-overlay open" id="ledgerOverlay" onclick="if(event.target===this)closeLedger()">
    <div class="ledger-modal">
        <div class="ledger-header">
            <div>
                <h2 style="font-size:1.15rem;margin:0;">Fee Ledger</h2>
                <p style="font-size:.82rem;color:#64748b;margin:.2rem 0 0;">
                    <?= e($ledgerAccount['student_name']) ?> &mdash; <?= e($ledgerAccount['class_name']) ?>
                    (<?= e($ledgerAccount['academic_session']) ?>)
                </p>
            </div>
            <a href="?session=<?= urlencode($sessionFilter) ?>&class=<?= urlencode($classFilter) ?>&q=<?= urlencode($searchName) ?>"
               class="btn btn-sm btn-soft" style="text-decoration:none;">&times; Close</a>
        </div>
        <div class="ledger-body">
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="label">Total Fee</div>
                    <div class="value">Rs. <?= number_format((float) $ledgerAccount['total_fee'], 2) ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Paid</div>
                    <div class="value" style="color:#059669;">Rs. <?= number_format((float) $ledgerAccount['total_paid'], 2) ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Discount</div>
                    <div class="value" style="font-size:.95rem;">Rs. <?= number_format((float) $ledgerAccount['total_discount'], 2) ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Late Fee</div>
                    <div class="value" style="font-size:.95rem;">Rs. <?= number_format((float) $ledgerAccount['total_late_fee'], 2) ?></div>
                </div>
                <div class="summary-card" style="border:1px solid #fecaca;background:#fef2f2;">
                    <div class="label" style="color:#991b1b;">Balance Due</div>
                    <div class="value" style="color:#ef4444;">Rs. <?= number_format((float) $ledgerAccount['balance'], 2) ?></div>
                </div>
            </div>

            <h3 style="font-size:.95rem;margin-bottom:.75rem;color:#334155;">Payment History</h3>
            <?php if (empty($ledgerCollections)): ?>
                <p style="text-align:center;padding:1.5rem;color:#94a3b8;">No payments recorded yet.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Discount</th>
                                <th>Late Fee</th>
                                <th>Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledgerCollections as $c): ?>
                            <tr>
                                <td style="font-family:monospace;font-size:.82rem;"><?= e($c['receipt_no']) ?></td>
                                <td style="white-space:nowrap;"><?= e($c['payment_date']) ?></td>
                                <td style="text-align:right;font-weight:600;">Rs. <?= number_format((float) $c['net_amount'], 2) ?></td>
                                <td style="text-align:right;"><?= (float) $c['discount_amount'] > 0 ? 'Rs. ' . number_format((float) $c['discount_amount'], 2) : '—' ?></td>
                                <td style="text-align:right;"><?= (float) $c['late_fee'] > 0 ? 'Rs. ' . number_format((float) $c['late_fee'], 2) : '—' ?></td>
                                <td><?= e($c['payment_mode']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function closeLedger() {
    var params = new URLSearchParams(window.location.search);
    params.delete('ledger');
    window.location.search = params.toString();
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLedger();
});
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
