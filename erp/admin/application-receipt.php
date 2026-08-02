<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
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

/* ─────────────────────────────────────────────────────────────
   Pure-PHP PDF generator (no external library required)
   ───────────────────────────────────────────────────────────── */
if ($download) {
    $esc = static fn(string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $lines = [];
    $lines[] = 'BT';
    $lines[] = '/F1 16 Tf';
    $lines[] = '0.29 0.33 0.39 rg';
    $lines[] = '72 772 Td';
    $lines[] = '(' . $esc('SIBA PUBLIC SCHOOL') . ') Tj';
    $lines[] = 'ET';

    $lines[] = 'BT';
    $lines[] = '/F2 9 Tf';
    $lines[] = '0.40 0.45 0.48 rg';
    $lines[] = '72 758 Td';
    $lines[] = '(' . $esc('WBBSE Affiliated | Chapra, West Bengal') . ') Tj';
    $lines[] = 'ET';

    $lines[] = 'BT';
    $lines[] = '/F1 14 Tf';
    $lines[] = '0.29 0.33 0.39 rg';
    $lines[] = '72 716 Td';
    $lines[] = '(' . $esc('APPLICATION RECEIPT') . ') Tj';
    $lines[] = 'ET';

    // Thin rule
    $lines[] = '0.85 0.85 0.85 RG';
    $lines[] = '2 w';
    $lines[] = '72 702 m 528 702 l S';

    $rows = [
        ['Application No', $app['application_no'] ?? ''],
        ['Student Name', $app['student_name'] ?? ''],
        ['Date of Birth', $app['dob'] ?? ''],
        ['Class Applied', $app['class_sought'] ?? ''],
        ["Father's Name", $app['father_name'] ?? ''],
        ["Mother's Name", $app['mother_name'] ?? ''],
        ['Parent Name', $app['parent_name'] ?? ''],
        ['Parent Phone', $app['parent_phone'] ?? ''],
        ['Parent Email', $app['parent_email'] ?? ''],
        ['Status', $app['status'] ?? ''],
        ['Payment Status', $app['payment_status'] ?? 'Pending'],
        ['Application Fee', 'Rs. 150'],
        ['Applied On', $app['applied_at'] ?? ''],
    ];

    $y = 688;
    foreach ($rows as [$k, $v]) {
        $lines[] = 'BT';
        $lines[] = '/F2 9 Tf';
        $lines[] = '0.40 0.45 0.48 rg';
        $lines[] = '72 ' . $y . ' Td';
        $lines[] = '(' . $esc($k) . ') Tj';
        $lines[] = 'ET';
        $lines[] = 'BT';
        $lines[] = '/F2 9 Tf';
        $lines[] = '0 0 0 rg';
        $lines[] = '210 ' . $y . ' Td';
        $lines[] = '(' . $esc((string) $v) . ') Tj';
        $lines[] = 'ET';
        $lines[] = '0.90 0.90 0.90 RG';
        $lines[] = '1 w';
        $lines[] = '72 ' . ($y - 5) . ' m 528 ' . ($y - 5) . ' l S';
        $y -= 17;
    }

    $lines[] = 'BT';
    $lines[] = '/F2 8 Tf';
    $lines[] = '0.58 0.62 0.65 rg';
    $lines[] = '72 120 Td';
    $lines[] = '(' . $esc('This is a computer-generated receipt. No signature required.') . ') Tj';
    $lines[] = 'ET';
    $lines[] = 'BT';
    $lines[] = '/F2 8 Tf';
    $lines[] = '0.58 0.62 0.65 rg';
    $lines[] = '72 108 Td';
    $lines[] = '(' . $esc('SIBA Public School | All Rights Reserved') . ') Tj';
    $lines[] = 'ET';

    $content = implode("\n", $lines) . "\n";

    $objects = [];
    $objectCount = 1;
    $offsets = [];

    $pdf = "%PDF-1.4\n";

    $addObject = function (string $body) use (&$pdf, &$offsets, &$objectCount): int {
        $offsets[] = strlen($pdf);
        $pdf .= $objectCount . " 0 obj\n" . $body . "\nendobj\n";
        $num = $objectCount;
        $objectCount++;
        return $num;
    };

    // Object 1: catalog
    $catalogId = $addObject("<< /Type /Catalog /Pages 2 0 R >>");
    // Object 2: pages
    $pagesId = $addObject("<< /Type /Pages /Kids [3 0 R] /Count 1 >>");
    // Object 3: page
    $pageId = $addObject("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>");
    // Object 4: font F1 (Helvetica-Bold)
    $f1Id = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>");
    // Object 5: font F2 (Helvetica)
    $f2Id = $addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

    // Object 6: content stream (compressed)
    $compressed = gzcompress($content, 9);
    $offsets[] = strlen($pdf);
    $pdf .= "6 0 obj\n<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream\nendobj\n";

    $totalObjects = $objectCount + 1; // +1 for the manually-added content object

    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 " . $totalObjects . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $totalObjects; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i - 1]);
    }
    $pdf .= "trailer\n<< /Size " . $totalObjects . " /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF\n";

    $appNoClean = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($app['application_no'] ?? ('SBA_' . $appId)));
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $appNoClean . '_receipt.pdf"');
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
            <tr><td>Application Fee</td><td><strong>₹150</strong></td></tr>
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
