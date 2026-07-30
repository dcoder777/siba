<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$pdo = $GLOBALS['pdo'];

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    die('Invalid receipt ID.');
}

$stmt = $pdo->prepare("SELECT * FROM fee_collections WHERE id = :id");
$stmt->execute(['id' => $id]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    die('Receipt not found.');
}

$stmt = $pdo->prepare(
    "SELECT fci.*, fh.name AS fee_head_name
     FROM fee_collection_items fci
     LEFT JOIN fee_heads fh ON fh.id = fci.fee_head_id
     WHERE fci.fee_collection_id = :id"
);
$stmt->execute(['id' => $id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

function amountInWords(float $amount): string {
    $words = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy',
        80 => 'Eighty', 90 => 'Ninety'
    ];
    if ($amount == 0) return 'Zero';
    $number = (int) $amount;
    $paise = round(($amount - $number) * 100);
    $result = '';
    if ($number >= 10000000) { $result .= $words[(int)($number/10000000)] . ' Crore '; $number %= 10000000; }
    if ($number >= 100000) { $result .= $words[(int)($number/100000)] . ' Lakh '; $number %= 100000; }
    if ($number >= 1000) { $result .= $words[(int)($number/1000)] . ' Thousand '; $number %= 1000; }
    if ($number >= 100) { $result .= $words[(int)($number/100)] . ' Hundred '; $number %= 100; }
    if ($number > 0) {
        if ($number < 20) $result .= $words[$number];
        else { $result .= $words[(int)($number/10)*10] . ' ' . ($number%10 > 0 ? $words[$number%10] : ''); }
    }
    if ($paise > 0) $result .= 'and ' . $paise . ' Paise';
    return trim($result) . ' Only';
}

$netAmount = (float) ($receipt['net_amount'] ?? 0);
$totalAmount = (float) ($receipt['total_amount'] ?? 0);
$discountAmount = (float) ($receipt['discount_amount'] ?? 0);
$lateFee = (float) ($receipt['late_fee'] ?? 0);
$isCancelled = ($receipt['status'] ?? 'Active') === 'Cancelled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt – <?= e($receipt['receipt_no'] ?? '') ?></title>
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Courier New',Courier,monospace; font-size:14px; line-height:1.6; color:#1e293b; background:#f1f5f9; padding:20px; }
.receipt-wrapper { max-width:800px; margin:0 auto; background:#fff; padding:40px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,.08); }
.school-header { text-align:center; margin-bottom:24px; }
.school-header h1 { font-size:24px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#0f172a; }
.school-header .subtitle { font-size:13px; color:#64748b; margin-top:4px; }
.school-header .contact { font-size:12px; color:#94a3b8; margin-top:2px; }
.receipt-title { text-align:center; margin-bottom:20px; }
.receipt-title h2 { font-size:18px; font-weight:700; letter-spacing:4px; text-transform:uppercase; border-top:2px solid #1e293b; border-bottom:2px solid #1e293b; display:inline-block; padding:6px 32px; }
.receipt-meta { display:flex; justify-content:space-between; margin-bottom:12px; font-size:13px; }
.receipt-meta div { flex:1; }
.receipt-meta .meta-right { text-align:right; }
.receipt-divider { border:none; border-top:1px dashed #94a3b8; margin:8px 0; }
.receipt-divider-thick { border:none; border-top:2px solid #1e293b; margin:8px 0; }
.info-line { display:flex; margin-bottom:4px; font-size:13px; }
.info-line .label { width:160px; font-weight:700; flex-shrink:0; }
.info-line .value { flex:1; }
.items-table { width:100%; border-collapse:collapse; margin:16px 0; font-size:13px; }
.items-table th { text-align:left; padding:8px 6px; border-bottom:2px solid #1e293b; font-weight:700; }
.items-table td { padding:6px; border-bottom:1px solid #e2e8f0; }
.items-table .col-sno { width:40px; text-align:center; }
.items-table .col-amount { text-align:right; width:140px; }
.summary { margin-top:16px; padding-top:8px; }
.summary-row { display:flex; justify-content:flex-end; margin-bottom:3px; font-size:13px; }
.summary-row .sum-label { width:200px; text-align:right; font-weight:600; padding-right:12px; }
.summary-row .sum-value { width:140px; text-align:right; font-weight:600; }
.summary-row.net { font-size:16px; font-weight:700; border-top:2px solid #1e293b; padding-top:6px; margin-top:6px; }
.in-words { margin-top:12px; font-size:13px; font-style:italic; color:#334155; }
.payment-detail { margin-top:12px; font-size:13px; }
.status-cancelled { display:inline-block; margin-top:12px; padding:4px 16px; border:2px solid #dc2626; color:#dc2626; font-weight:700; font-size:14px; letter-spacing:2px; }
.footer { text-align:right; margin-top:32px; padding-top:16px; border-top:1px solid #e2e8f0; }
.footer .signatory { margin-top:40px; font-weight:700; font-size:13px; }
.btn-print { display:block; width:200px; margin:20px auto; padding:10px 0; background:#1e293b; color:#fff; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; text-align:center; text-decoration:none; font-family:sans-serif; }
.btn-print:hover { background:#0f172a; }
@media print {
    body { background:#fff; padding:0; }
    .receipt-wrapper { box-shadow:none; border-radius:0; padding:20px 40px; }
    .btn-print { display:none !important; }
}
</style>
</head>
<body>
<a class="btn-print" href="javascript:window.print()">Print Receipt</a>
<div class="receipt-wrapper">
    <div class="school-header">
        <h1>SIBA Public School</h1>
        <div class="subtitle">Bangaljhi, Chapra, West Bengal 741123</div>
        <div class="contact">Phone: +91-7501011996 | Email: info@sibapublicschool.com</div>
    </div>
    <div class="receipt-title">
        <h2>Receipt</h2>
    </div>
    <div class="receipt-meta">
        <div>Receipt No: <strong><?= e($receipt['receipt_no'] ?? '') ?></strong></div>
        <div class="meta-right">Date: <strong><?= e($receipt['payment_date'] ?? '') ?></strong></div>
    </div>
    <hr class="receipt-divider-thick">
    <div class="info-line"><span class="label">Student Name:</span><span class="value"><?= e($receipt['student_name'] ?? '') ?></span></div>
    <div class="info-line"><span class="label">Class:</span><span class="value"><?= e($receipt['class_name'] ?? '') ?></span></div>
    <div class="info-line"><span class="label">Academic Session:</span><span class="value"><?= e($receipt['academic_session'] ?? '') ?></span></div>
    <hr class="receipt-divider">
    <?php if (!empty($items)): ?>
    <table class="items-table">
        <thead>
            <tr><th class="col-sno">S.No</th><th>Fee Head</th><th class="col-amount">Amount</th></tr>
        </thead>
        <tbody>
            <?php $sno = 0; foreach ($items as $item): $sno++; ?>
            <tr>
                <td class="col-sno"><?= $sno ?></td>
                <td><?= e($item['fee_head_name'] ?? '') ?></td>
                <td class="col-amount"><?= number_format((float) ($item['amount'] ?? 0), 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <hr class="receipt-divider">
    <div class="summary">
        <div class="summary-row"><span class="sum-label">Total Fee:</span><span class="sum-value"><?= number_format($totalAmount, 2) ?></span></div>
        <div class="summary-row"><span class="sum-label">Discount:</span><span class="sum-value"><?= number_format($discountAmount, 2) ?></span></div>
        <div class="summary-row"><span class="sum-label">Late Fee:</span><span class="sum-value"><?= number_format($lateFee, 2) ?></span></div>
        <div class="summary-row net"><span class="sum-label">Net Amount:</span><span class="sum-value"><?= number_format($netAmount, 2) ?></span></div>
    </div>
    <div class="in-words">Amount in Words: <?= e(amountInWords($netAmount)) ?></div>
    <div class="payment-detail">
        <strong>Payment Mode:</strong> <?= e($receipt['payment_mode'] ?? '') ?>
        <?php if (($receipt['payment_mode'] ?? '') === 'Cheque' && !empty($receipt['cheque_no'])): ?>
            <br>Cheque No: <?= e($receipt['cheque_no'] ?? '') ?>, Bank: <?= e($receipt['cheque_bank'] ?? '') ?>, Date: <?= e($receipt['cheque_date'] ?? '') ?>
        <?php endif; ?>
        <?php if (!empty($receipt['transaction_ref'])): ?>
            <br>Ref: <?= e($receipt['transaction_ref']) ?>
        <?php endif; ?>
    </div>
    <hr class="receipt-divider">
    <div class="info-line"><span class="label">Status:</span><span class="value"><?= e($receipt['status'] ?? 'Active') ?></span></div>
    <?php if ($isCancelled): ?>
        <div class="status-cancelled">CANCELLED</div>
        <div class="info-line" style="margin-top:8px;"><span class="label">Cancelled on:</span><span class="value"><?= e($receipt['cancelled_at'] ?? '') ?></span></div>
        <?php if (!empty($receipt['cancel_reason'])): ?>
        <div class="info-line"><span class="label">Reason:</span><span class="value"><?= e($receipt['cancel_reason']) ?></span></div>
        <?php endif; ?>
    <?php endif; ?>
    <hr class="receipt-divider">
    <div class="footer">
        <div class="signatory">Authorised Signatory</div>
    </div>
</div>
<a class="btn-print" href="javascript:window.print()">Print Receipt</a>
</body>
</html>
