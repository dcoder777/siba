<?php

declare(strict_types=1);

namespace modules\Reports;

use core\Controller;

class ReportController extends Controller
{
    public function dashboard(): void
    {
        $students = (int) $this->pdo->query('SELECT COUNT(*) AS c FROM students')->fetch()['c'];
        $employees = (int) $this->pdo->query('SELECT COUNT(*) AS c FROM employees')->fetch()['c'];

        $fees = $this->pdo->query(
            'SELECT
                SUM(amount) AS total_due,
                SUM(paid_amount) AS total_paid,
                SUM(amount - paid_amount) AS total_pending
             FROM student_fee_dues'
        )->fetch();

        $this->ok([
            'students' => $students,
            'employees' => $employees,
            'finance' => $fees,
        ], 'Dashboard metrics');
    }

    public function payrollExport(): void
    {
        $month = $_GET['month'] ?? date('Y-m');
        $stmt = $this->pdo->prepare(
            'SELECT
                e.employee_code, e.name, pi.ctc_amount, pi.gross_amount, pi.deductions_amount, pi.net_payout
             FROM payroll_items pi
             JOIN payroll_runs pr ON pr.id = pi.payroll_run_id
             JOIN employees e ON e.id = pi.employee_id
             WHERE pr.month_label = :month
             ORDER BY e.name ASC'
        );
        $stmt->execute(['month' => $month]);
        $rows = $stmt->fetchAll();

        $filename = "payroll_{$month}.csv";
        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename={$filename}");

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Employee Code', 'Name', 'CTC', 'Gross', 'Deductions', 'Net Payout']);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }
}
