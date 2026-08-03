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
        body { font-family:'Inter',Arial,sans-serif; background:#f5f7f6; padding:2rem; }
        .receipt { max-width:700px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
        .receipt-header { background:#4b5563; color:#fff; padding:1.5rem 2rem; display:flex; align-items:center; gap:1rem; }
        .receipt-header .logo { height:48px; width:auto; }
        .receipt-header h1 { font-size:1.3rem; }
        .receipt-body { padding:2rem; }
        .receipt-title { text-align:center; margin-bottom:2rem; }
        .receipt-title h2 { color:#4b5563; font-size:1.5rem; }
        .receipt-title .app-no { font-size:2rem; font-weight:800; color:#4b5563; letter-spacing:1px; margin-top:.25rem; }
        .receipt-table { width:100%; border-collapse:collapse; margin:1.5rem 0; }
        .receipt-table td { padding:.6rem .75rem; border-bottom:1px solid #e2e8f0; font-size:.9rem; }
        .receipt-table td:first-child { font-weight:600; color:#64748b; width:40%; }
        .receipt-table td:last-child { color:#4b5563; }
        .receipt-footer { text-align:center; padding:1.5rem 2rem; border-top:2px dashed #e2e8f0; color:#94a3b8; font-size:.8rem; }
        .print-btn { display:inline-block; margin-top:1rem; padding:.6rem 1.5rem; background:#feb630; color:#1f2937; border:none; border-radius:6px; cursor:pointer; font-size:.9rem; font-weight:600; }
        .print-btn:hover { background:#e5a420; }
        .status-badge { display:inline-block; padding:.2rem .7rem; border-radius:999px; font-size:.8rem; font-weight:600; }
        .status-badge.started { background:#e2e8f0; color:#475569; }
        .status-badge.pending { background:#fef3c7; color:#92400e; }
        .status-badge.paid { background:#d1fae5; color:#065f46; }
        @media print { body { background:#fff; padding:0; } .no-print { display:none !important; } }
    </style>
</head>
<body>
<div class="receipt">
    <div class="receipt-header">
        <img src="https://sibapublicschool.com/assets/images/logo.jpg" alt="SIBA Public School" class="logo" onerror="this.style.display='none'">
        <div>
            <h1>SIBA Public School</h1>
            <p style="font-size:.8rem;opacity:.8;">WBBSE Affiliated &bull; Chapra, West Bengal</p>
        </div>
    </div>
    <div class="receipt-body">
        <div class="receipt-title">
            <h2>Application Acknowledgement</h2>
            <div class="app-no"><?= htmlspecialchars((string) ($app['application_no'] ?? '')) ?></div>
        </div>
        <table class="receipt-table">
            <tr><td>Application No</td><td><strong><?= htmlspecialchars((string) ($app['application_no'] ?? '')) ?></strong></td></tr>
            <tr><td>Student Name</td><td><?= htmlspecialchars((string) ($app['student_name'] ?? '')) ?></td></tr>
            <tr><td>Date of Birth</td><td><?= htmlspecialchars((string) ($app['dob'] ?? '')) ?></td></tr>
            <tr><td>Class Applied</td><td><?= htmlspecialchars((string) ($app['class_sought'] ?? '')) ?></td></tr>
            <tr><td>Father's Name</td><td><?= htmlspecialchars((string) ($app['father_name'] ?? '')) ?></td></tr>
            <tr><td>Mother's Name</td><td><?= htmlspecialchars((string) ($app['mother_name'] ?? '')) ?></td></tr>
            <tr><td>Parent Name</td><td><?= htmlspecialchars((string) ($app['parent_name'] ?? '')) ?></td></tr>
            <tr><td>Parent Phone</td><td><?= htmlspecialchars((string) ($app['parent_phone'] ?? '')) ?></td></tr>
            <tr><td>Parent Email</td><td><?= htmlspecialchars((string) ($app['parent_email'] ?? '')) ?></td></tr>
            <tr><td>Status</td><td><span class="status-badge started"><?= htmlspecialchars((string) ($app['status'] ?? '')) ?></span></td></tr>
            <tr><td>Payment Status</td><td><span class="status-badge <?= (($app['payment_status'] ?? 'Pending') === 'Paid') ? 'paid' : 'pending' ?>"><?= htmlspecialchars((string) ($app['payment_status'] ?? 'Pending')) ?></span></td></tr>
            <tr><td>Application Fee</td><td><strong>₹200</strong></td></tr>
            <tr><td>Applied On</td><td><?= htmlspecialchars((string) ($app['applied_at'] ?? '')) ?></td></tr>
        </table>
        <div style="text-align:center;" class="no-print">
            <a class="print-btn" href="?app_id=<?= (int) $appId ?>&download=1"><i class="fas fa-download"></i> Download PDF</a>
            <br><br>
            <a href="application-view.php?app_id=<?= (int) $appId ?>" style="color:#64748b;font-size:.85rem;">&larr; Back to Application</a>
        </div>
    </div>
    <div class="receipt-footer">
        This is a computer-generated receipt. No signature required.<br>
        SIBA Public School &bull; All Rights Reserved
    </div>
</div>
</body>
</html>
