<?php
$pageTitle = "Application Receipt";
require_once('../includes/db_connect.php');

$appId = (int) ($_GET['app_id'] ?? 0);
$parentId = (int) ($_SESSION['parent_id'] ?? 0);

if (!$appId || !$parentId) {
    header("Location: dashboard.php");
    exit();
}

$app = $conn->query("SELECT a.*, p.name AS parent_name, p.phone AS parent_phone, p.email AS parent_email FROM applications a JOIN parents p ON p.id = a.parent_id WHERE a.id = $appId AND a.parent_id = $parentId")->fetch_assoc();
if (!$app) {
    header("Location: dashboard.php");
    exit();
}
?><html>
<head>
    <meta charset="utf-8">
    <title>Application Receipt – SIBA Public School</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',Arial,sans-serif; background:#f1f5f9; padding:2rem; }
        .receipt { max-width:700px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
        .receipt-header { background:#1e293b; color:#fff; padding:1.5rem 2rem; display:flex; justify-content:space-between; align-items:center; }
        .receipt-header h1 { font-size:1.3rem; }
        .receipt-body { padding:2rem; }
        .receipt-title { text-align:center; margin-bottom:2rem; }
        .receipt-title h2 { color:#1e293b; font-size:1.5rem; }
        .receipt-title .app-no { font-size:2rem; font-weight:800; color:#1e293b; letter-spacing:1px; margin-top:.25rem; }
        .receipt-table { width:100%; border-collapse:collapse; margin:1.5rem 0; }
        .receipt-table td { padding:.6rem .75rem; border-bottom:1px solid #e2e8f0; font-size:.9rem; }
        .receipt-table td:first-child { font-weight:600; color:#64748b; width:40%; }
        .receipt-table td:last-child { color:#1e293b; }
        .receipt-footer { text-align:center; padding:1.5rem 2rem; border-top:2px dashed #e2e8f0; color:#94a3b8; font-size:.8rem; }
        .print-btn { display:inline-block; margin-top:1rem; padding:.6rem 1.5rem; background:#1e293b; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:.9rem; }
        .print-btn:hover { background:#0f172a; }
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
        <h1><i class="fas fa-school"></i> SIBA Public School</h1>
        <div style="text-align:right;font-size:.85rem;">Receipt<br><?= date('d-m-Y') ?></div>
    </div>
    <div class="receipt-body">
        <div class="receipt-title">
            <h2>Application Acknowledgement</h2>
            <div class="app-no"><?= htmlspecialchars($app['application_no']) ?></div>
        </div>
        <table class="receipt-table">
            <tr><td>Application No</td><td><strong><?= htmlspecialchars($app['application_no']) ?></strong></td></tr>
            <tr><td>Student Name</td><td><?= htmlspecialchars($app['student_name']) ?></td></tr>
            <tr><td>Date of Birth</td><td><?= htmlspecialchars($app['dob']) ?></td></tr>
            <tr><td>Class Applied</td><td><?= htmlspecialchars($app['class_sought']) ?></td></tr>
            <tr><td>Father's Name</td><td><?= htmlspecialchars($app['father_name']) ?></td></tr>
            <tr><td>Mother's Name</td><td><?= htmlspecialchars($app['mother_name']) ?></td></tr>
            <tr><td>Parent Name</td><td><?= htmlspecialchars($app['parent_name']) ?></td></tr>
            <tr><td>Parent Phone</td><td><?= htmlspecialchars($app['parent_phone']) ?></td></tr>
            <tr><td>Parent Email</td><td><?= htmlspecialchars($app['parent_email']) ?></td></tr>
            <tr><td>Status</td><td><span class="status-badge started"><?= htmlspecialchars($app['status']) ?></span></td></tr>
            <tr><td>Payment Status</td><td><span class="status-badge <?= ($app['payment_status'] ?? 'Pending') === 'Paid' ? 'paid' : 'pending' ?>"><?= htmlspecialchars($app['payment_status'] ?? 'Pending') ?></span></td></tr>
            <tr><td>Applied On</td><td><?= date('d-m-Y h:i A', strtotime($app['applied_at'])) ?></td></tr>
        </table>
        <div style="text-align:center;" class="no-print">
            <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print / Download PDF</button>
            <br><br>
            <a href="dashboard.php" style="color:#64748b;font-size:.85rem;">&larr; Back to Dashboard</a>
        </div>
    </div>
    <div class="receipt-footer">
        This is a computer-generated receipt. No signature required.<br>
        SIBA Public School &bull; All Rights Reserved
    </div>
</div>
</body>
</html>
