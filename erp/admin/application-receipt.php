<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/receipt_pdf.php';
require_admin_login();

$appId = (int) ($_GET['app_id'] ?? 0);
if (!$appId) {
    header("Location: applications-list.php");
    exit();
}

$stmt = $pdo->prepare("SELECT a.*, p.name AS parent_name, p.phone AS parent_phone, p.email AS parent_email FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.id = :id");
$stmt->execute(['id' => $appId]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    header("Location: applications-list.php");
    exit();
}

$studentFullName = trim(implode(' ', array_filter([
    $app['first_name'] ?? '',
    $app['middle_name'] ?? '',
    $app['last_name'] ?? '',
]))) ?: (string) ($app['student_name'] ?? '');

$appliedAt = (string) ($app['applied_at'] ?? '');
$appliedDate = $appliedAt !== '' ? date('d-m-Y h:i A', (int) strtotime($appliedAt)) : '';
$paymentStatus = (string) ($app['payment_status'] ?? 'Pending');
$isPaid = $paymentStatus === 'Paid';
require_once __DIR__ . '/../../includes/application_fee.php';
$appFeeAmount = (float) ($app['payment_amount'] ?? 0);
if ($appFeeAmount <= 0) {
    $appFeeAmount = get_application_fee_amount($pdo);
}

$download = (isset($_GET['download']) && $_GET['download'] === '1');

if ($download) {
    $pdf = siba_receipt_pdf($app);
    $filename = siba_receipt_filename($app, $appId);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    exit();
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Application Receipt – SIBA Public School</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter','Segoe UI',Arial,sans-serif; background:#eef2f6; padding:2rem 1rem; color:#1f2937; }
        .receipt { max-width:740px; margin:0 auto; background:#fff; border-radius:14px; box-shadow:0 10px 40px rgba(15,23,42,.12); overflow:hidden; border:1px solid #e2e8f0; }
        .receipt-accent { height:6px; background:linear-gradient(90deg,#feb630 0%,#f59e0b 100%); }
        .receipt-header { background:linear-gradient(135deg,#1e293b 0%,#334155 100%); color:#fff; padding:1.6rem 2rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; }
        .receipt-brand { display:flex; align-items:center; gap:1rem; }
        .receipt-brand .logo { height:56px; width:auto; border-radius:8px; background:#fff; padding:2px; }
        .receipt-brand h1 { font-size:1.25rem; letter-spacing:.01em; }
        .receipt-brand .tagline { font-size:.78rem; opacity:.75; margin-top:.15rem; }
        .receipt-receiptno { text-align:right; }
        .receipt-receiptno .label { font-size:.68rem; text-transform:uppercase; letter-spacing:.12em; opacity:.7; }
        .receipt-receiptno .value { font-size:1.15rem; font-weight:700; color:#feb630; margin-top:.15rem; }
        .receipt-body { padding:1.75rem 2rem 2rem; }
        .receipt-title { text-align:center; padding-bottom:1.25rem; border-bottom:2px solid #f1f5f9; margin-bottom:1.5rem; }
        .receipt-title .eyebrow { display:inline-block; font-size:.68rem; font-weight:700; letter-spacing:.18em; text-transform:uppercase; color:#f59e0b; margin-bottom:.35rem; }
        .receipt-title h2 { font-size:1.5rem; color:#0f172a; letter-spacing:-.01em; }
        .receipt-title p { font-size:.82rem; color:#64748b; margin-top:.3rem; }
        .receipt-meta { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
        .meta-chip { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:.55rem .9rem; flex:1; min-width:180px; }
        .meta-chip .k { font-size:.68rem; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; font-weight:600; }
        .meta-chip .v { font-size:.95rem; font-weight:700; color:#0f172a; margin-top:.15rem; }
        .section-block { margin-bottom:1.4rem; }
        .section-block h3 { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#f59e0b; margin-bottom:.75rem; padding-bottom:.4rem; border-bottom:1px solid #f1f5f9; }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:.55rem 1.5rem; }
        .info-item { display:flex; gap:.5rem; font-size:.88rem; padding:.3rem 0; border-bottom:1px dotted #e2e8f0; }
        .info-item .k { color:#64748b; font-weight:500; min-width:118px; }
        .info-item .v { color:#0f172a; font-weight:600; }
        .info-item.full { grid-column:1 / -1; }
        .payment-box { display:flex; align-items:center; justify-content:space-between; gap:1rem; background:linear-gradient(135deg,#f8fafc,#f1f5f9); border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.25rem; margin-top:.25rem; }
        .payment-box .fee-label { font-size:.78rem; color:#64748b; font-weight:600; }
        .payment-box .fee-amount { font-size:1.5rem; font-weight:800; color:#0f172a; }
        .payment-box .fee-amount small { font-size:.8rem; color:#94a3b8; font-weight:500; }
        .status-badge { display:inline-block; padding:.25rem .75rem; border-radius:999px; font-size:.75rem; font-weight:700; }
        .status-badge.paid { background:#d1fae5; color:#065f46; }
        .status-badge.pending { background:#fef3c7; color:#92400e; }
        .status-badge.started { background:#e2e8f0; color:#475569; }
        .pay-status { display:flex; align-items:center; gap:.6rem; }
        .pay-status .lbl { font-size:.78rem; color:#64748b; }
        .receipt-footer { background:#f8fafc; border-top:2px dashed #cbd5e1; padding:1.25rem 2rem; text-align:center; color:#64748b; font-size:.78rem; }
        .receipt-footer strong { color:#334155; }
        .receipt-footer .thanks { font-size:.9rem; color:#0f172a; font-weight:600; margin-bottom:.3rem; }
        .no-print { text-align:center; margin-top:1.5rem; }
        .btn { display:inline-flex; align-items:center; gap:.5rem; padding:.65rem 1.4rem; border-radius:8px; font-size:.88rem; font-weight:600; text-decoration:none; cursor:pointer; border:none; }
        .btn-download { background:#f59e0b; color:#1f2937; }
        .btn-download:hover { background:#d97706; }
        .btn-ghost { background:#fff; color:#475569; border:1px solid #cbd5e1; }
        .btn-ghost:hover { background:#f1f5f9; }
        @media (max-width:600px) { .info-grid { grid-template-columns:1fr; } .receipt-header { flex-direction:column; text-align:center; } .receipt-receiptno { text-align:center; } .receipt-brand { flex-direction:column; } }
        @media print { body { background:#fff; padding:0; } .receipt { box-shadow:none; border:none; } .no-print { display:none !important; } }
    </style>
</head>
<body>
<div class="receipt">
    <div class="receipt-accent"></div>
    <div class="receipt-header">
        <div class="receipt-brand">
            <img src="https://sibapublicschool.com/assets/images/logo.jpg" alt="SIBA Public School" class="logo" onerror="this.style.display='none'">
            <div>
                <h1>SIBA Public School</h1>
                <p class="tagline">WBBSE Affiliated &bull; Chapra, West Bengal</p>
            </div>
        </div>
        <div class="receipt-receiptno">
            <div class="label">Receipt No.</div>
            <div class="value"><?= htmlspecialchars((string) ($app['application_no'] ?? '')) ?></div>
        </div>
    </div>

    <div class="receipt-body">
        <div class="receipt-title">
            <span class="eyebrow">Admissions</span>
            <h2>Application Acknowledgement Receipt</h2>
            <p>Thank you for applying to SIBA Public School.</p>
        </div>

        <div class="receipt-meta">
            <div class="meta-chip">
                <div class="k">Application No</div>
                <div class="v"><?= htmlspecialchars((string) ($app['application_no'] ?? '—')) ?></div>
            </div>
            <div class="meta-chip">
                <div class="k">Date of Application</div>
                <div class="v"><?= htmlspecialchars($appliedDate ?: '—') ?></div>
            </div>
            <div class="meta-chip">
                <div class="k">Payment Status</div>
                <div class="v"><span class="status-badge <?= $isPaid ? 'paid' : 'pending' ?>"><?= htmlspecialchars($paymentStatus) ?></span></div>
            </div>
        </div>

        <div class="section-block">
            <h3>Student Details</h3>
            <div class="info-grid">
                <div class="info-item"><span class="k">Student Name</span><span class="v"><?= htmlspecialchars($studentFullName) ?></span></div>
                <div class="info-item"><span class="k">Date of Birth</span><span class="v"><?= htmlspecialchars((string) ($app['dob'] ?? '—')) ?></span></div>
                <div class="info-item"><span class="k">Class Applied</span><span class="v"><?= htmlspecialchars((string) ($app['class_sought'] ?? '—')) ?></span></div>
                <div class="info-item"><span class="k">Application Status</span><span class="v"><span class="status-badge started"><?= htmlspecialchars((string) ($app['status'] ?? '—')) ?></span></span></div>
            </div>
        </div>

        <div class="section-block">
            <h3>Family &amp; Guardian Details</h3>
            <div class="info-grid">
                <div class="info-item"><span class="k">Guardian Name</span><span class="v"><?= htmlspecialchars((string) ($app['parent_name'] ?? '—')) ?></span></div>
                <div class="info-item"><span class="k">Guardian Phone</span><span class="v"><?= htmlspecialchars((string) ($app['parent_phone'] ?? '—')) ?></span></div>
                <div class="info-item full"><span class="k">Guardian Email</span><span class="v"><?= htmlspecialchars((string) ($app['parent_email'] ?? '—')) ?></span></div>
                <div class="info-item"><span class="k">Father's Name</span><span class="v"><?= htmlspecialchars((string) ($app['father_name'] ?? '—')) ?></span></div>
                <div class="info-item"><span class="k">Mother's Name</span><span class="v"><?= htmlspecialchars((string) ($app['mother_name'] ?? '—')) ?></span></div>
            </div>
        </div>

        <div class="section-block">
            <h3>Payment Summary</h3>
            <div class="payment-box">
                <div>
                    <div class="fee-label">Application Fee</div>
                    <div class="fee-amount">₹<?= number_format($appFeeAmount, 2) ?> <small>INR</small></div>
                </div>
                <div class="pay-status">
                    <span class="lbl">Status</span>
                    <span class="status-badge <?= $isPaid ? 'paid' : 'pending' ?>"><?= htmlspecialchars($paymentStatus) ?></span>
                </div>
            </div>
        </div>

        <div class="no-print">
            <a class="btn btn-download" href="?app_id=<?= (int) $appId ?>&download=1"><i class="fas fa-download"></i> Download PDF</a>
            <button class="btn btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <div style="margin-top:1rem;">
                <a href="application-view.php?app_id=<?= (int) $appId ?>" style="color:#64748b;font-size:.85rem;">&larr; Back to Application</a>
            </div>
        </div>
    </div>

    <div class="receipt-footer">
        <div class="thanks">Thank you for choosing SIBA Public School.</div>
        This is a computer-generated receipt and does not require a signature.<br>
        For queries, contact the school office during working hours.<br>
        <strong>SIBA Public School</strong> &bull; Chapra, West Bengal &bull; All Rights Reserved
    </div>
</div>
</body>
</html>
