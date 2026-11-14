<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$pdo = $GLOBALS['pdo'];

$itemId = (int) ($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    die('Invalid payslip item ID.');
}

$stmt = $pdo->prepare("SELECT pi.*, e.name AS employee_name, e.employee_code, e.department, e.payout_account, e.basic_salary, e.hra, e.transport_allowance, e.special_allowance, e.pf_deduction, e.pt_deduction, pr.payroll_month, pr.generated_at FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id JOIN payroll_runs pr ON pr.id = pi.payroll_run_id WHERE pi.id = :id");
$stmt->execute(['id' => $itemId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    die('Payslip not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip - <?= e($item['employee_name']) ?> - <?= e($item['payroll_month']) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; color: #333; margin: 0; padding: 20px; }
        .payslip-container { max-width: 800px; margin: 0 auto; border: 1px solid #ddd; padding: 30px; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 24px; text-transform: uppercase; letter-spacing: 2px; }
        .header p { margin: 5px 0 0; font-size: 14px; color: #666; }
        .row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .col { width: 48%; }
        .label { font-weight: bold; color: #555; display: inline-block; width: 120px; }
        .value { color: #000; }
        .section-title { font-size: 16px; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin: 30px 0 15px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f9f9f9; font-weight: bold; }
        .text-right { text-align: right; }
        .total-row { font-weight: bold; background: #f1f5f9; }
        .footer { margin-top: 50px; display: flex; justify-content: space-between; align-items: flex-end; }
        .signature { text-align: center; width: 200px; border-top: 1px solid #333; padding-top: 10px; }
        @media print {
            body { padding: 0; }
            .payslip-container { border: none; padding: 0; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="payslip-container">
        <div class="header">
            <h1>SIBA PUBLIC SCHOOL</h1>
            <p>Payslip for the month of <strong><?= e($item['payroll_month']) ?></strong></p>
        </div>

        <div class="row">
            <div class="col">
                <div><span class="label">Employee Name:</span> <span class="value"><?= e($item['employee_name']) ?></span></div>
                <div><span class="label">Employee ID:</span> <span class="value"><?= e($item['employee_code']) ?></span></div>
                <div><span class="label">Department:</span> <span class="value"><?= e($item['department']) ?></span></div>
            </div>
            <div class="col">
                <div><span class="label">Payslip Date:</span> <span class="value"><?= date('d M Y', strtotime($item['generated_at'])) ?></span></div>
                <div><span class="label">Bank A/C:</span> <span class="value"><?= e((string)$item['payout_account']) ?: 'N/A' ?></span></div>
            </div>
        </div>

        <div class="section-title">Earnings & Deductions</div>
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">Earnings (?)</th>
                    <th class="text-right">Deductions (?)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Basic Salary</td>
                    <td class="text-right"><?= number_format((float)$item['basic_salary'], 2) ?></td>
                    <td class="text-right"></td>
                </tr>
                <tr>
                    <td>HRA (House Rent Allowance)</td>
                    <td class="text-right"><?= number_format((float)$item['hra'], 2) ?></td>
                    <td class="text-right"></td>
                </tr>
                <tr>
                    <td>Transport Allowance</td>
                    <td class="text-right"><?= number_format((float)$item['transport_allowance'], 2) ?></td>
                    <td class="text-right"></td>
                </tr>
                <tr>
                    <td>Special Allowance</td>
                    <td class="text-right"><?= number_format((float)$item['special_allowance'], 2) ?></td>
                    <td class="text-right"></td>
                </tr>
                <tr>
                    <td>PF Deduction</td>
                    <td class="text-right"></td>
                    <td class="text-right"><?= number_format((float)$item['pf_deduction'], 2) ?></td>
                </tr>
                <tr>
                    <td>PT Deduction</td>
                    <td class="text-right"></td>
                    <td class="text-right"><?= number_format((float)$item['pt_deduction'], 2) ?></td>
                </tr>
                <tr class="total-row">
                    <td>Total</td>
                    <td class="text-right"><?= number_format((float)$item['gross_amount'], 2) ?></td>
                    <td class="text-right"><?= number_format((float)$item['deductions_amount'], 2) ?></td>
                </tr>
                <tr class="total-row" style="font-size: 16px;">
                    <td colspan="2" class="text-right">NET PAYOUT:</td>
                    <td class="text-right" style="color: #16a34a;">? <?= number_format((float)$item['net_payout'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <div>
                <p><strong>Note:</strong> This is a computer-generated payslip and does not require a signature.</p>
            </div>
            <div class="signature">
                Authorized Signatory
            </div>
        </div>
    </div>
</body>
</html>
