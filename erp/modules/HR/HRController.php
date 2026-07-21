<?php

declare(strict_types=1);

namespace modules\HR;

use core\Controller;
use core\Request;

class HRController extends Controller
{
    public function listEmployees(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $department = trim((string) Request::query('department', ''));
        $status = trim((string) Request::query('status', ''));
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(employee_code LIKE :q OR name LIKE :q OR designation LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($department !== '') {
            $where[] = 'department = :department';
            $params['department'] = $department;
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM employees' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM employees' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'employees');
    }

    public function createEmployee(): void
    {
        $payload = Request::json();
        if (empty($payload['employee_code']) || empty($payload['name']) || empty($payload['department']) || empty($payload['designation'])) {
            $this->fail('employee_code, name, department and designation are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO employees (
                employee_code, name, department, designation, joining_date, ctc, payout_account, created_at
             ) VALUES (
                :employee_code, :name, :department, :designation, :joining_date, :ctc, :payout_account, NOW()
             )'
        );
        $stmt->execute([
            'employee_code' => $payload['employee_code'],
            'name' => $payload['name'],
            'department' => $payload['department'],
            'designation' => $payload['designation'],
            'joining_date' => $payload['joining_date'] ?? null,
            'ctc' => $payload['ctc'] ?? 0,
            'payout_account' => $payload['payout_account'] ?? null,
        ]);

        $this->ok(['employee_id' => (int) $this->pdo->lastInsertId()], 'Employee created');
    }

    public function updateEmployee(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE employees
             SET employee_code = :employee_code, name = :name, department = :department, designation = :designation,
                 joining_date = :joining_date, ctc = :ctc, payout_account = :payout_account, status = :status
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'employee_code' => $payload['employee_code'] ?? '',
            'name' => $payload['name'] ?? '',
            'department' => $payload['department'] ?? '',
            'designation' => $payload['designation'] ?? '',
            'joining_date' => $payload['joining_date'] ?? null,
            'ctc' => $payload['ctc'] ?? 0,
            'payout_account' => $payload['payout_account'] ?? null,
            'status' => $payload['status'] ?? 'active',
        ]);
        $this->ok([], 'Employee updated');
    }

    public function deleteEmployee(array $params): void
    {
        $this->pdo->prepare('DELETE FROM employees WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Employee deleted');
    }

    public function listLeaveRequests(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $employeeId = Request::query('employee_id');
        $status = trim((string) Request::query('status', ''));
        $where = [];
        $params = [];
        if ($employeeId !== null && $employeeId !== '') {
            $where[] = 'lr.employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
        }
        if ($status !== '') {
            $where[] = 'lr.status = :status';
            $params['status'] = $status;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM leave_requests lr' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT lr.*, e.employee_code, e.name
             FROM leave_requests lr
             JOIN employees e ON e.id = lr.employee_id' . $whereSql . '
             ORDER BY lr.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'leave_requests');
    }

    public function leaveRequest(array $context): void
    {
        $payload = Request::json();
        if (empty($payload['employee_id']) || empty($payload['from_date']) || empty($payload['to_date']) || empty($payload['leave_type'])) {
            $this->fail('employee_id, from_date, to_date and leave_type are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO leave_requests (
                employee_id, leave_type, from_date, to_date, reason, status, requested_by, created_at
             ) VALUES (
                :employee_id, :leave_type, :from_date, :to_date, :reason, "pending", :requested_by, NOW()
             )'
        );
        $stmt->execute([
            'employee_id' => $payload['employee_id'],
            'leave_type' => $payload['leave_type'],
            'from_date' => $payload['from_date'],
            'to_date' => $payload['to_date'],
            'reason' => $payload['reason'] ?? null,
            'requested_by' => $context['user']['id'] ?? null,
        ]);

        $this->ok([], 'Leave request submitted');
    }

    public function updateLeaveRequest(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE leave_requests
             SET employee_id = :employee_id, leave_type = :leave_type, from_date = :from_date,
                 to_date = :to_date, reason = :reason, status = :status
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'employee_id' => $payload['employee_id'] ?? null,
            'leave_type' => $payload['leave_type'] ?? '',
            'from_date' => $payload['from_date'] ?? date('Y-m-d'),
            'to_date' => $payload['to_date'] ?? date('Y-m-d'),
            'reason' => $payload['reason'] ?? null,
            'status' => $payload['status'] ?? 'pending',
        ]);
        $this->ok([], 'Leave request updated');
    }

    public function deleteLeaveRequest(array $params): void
    {
        $this->pdo->prepare('DELETE FROM leave_requests WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Leave request deleted');
    }

    public function approveLeave(array $context): void
    {
        $payload = Request::json();
        if (empty($payload['leave_request_id']) || empty($payload['status'])) {
            $this->fail('leave_request_id and status are required', 422);
            return;
        }

        $this->pdo->prepare(
            'UPDATE leave_requests
             SET status = :status, approved_by = :approved_by, approved_at = NOW()
             WHERE id = :id'
        )->execute([
            'status' => $payload['status'],
            'approved_by' => $context['user']['id'] ?? null,
            'id' => $payload['leave_request_id'],
        ]);

        $this->ok([], 'Leave request updated');
    }

    public function listPayrollRuns(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $month = trim((string) Request::query('month_label', ''));
        $whereSql = '';
        $params = [];
        if ($month !== '') {
            $whereSql = ' WHERE month_label = :month_label';
            $params['month_label'] = $month;
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM payroll_runs' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM payroll_runs' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'payroll_runs');
    }

    public function listPayrollItems(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $runId = Request::query('payroll_run_id');
        $whereSql = '';
        $params = [];
        if ($runId !== null && $runId !== '') {
            $whereSql = ' WHERE pi.payroll_run_id = :payroll_run_id';
            $params['payroll_run_id'] = $runId;
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM payroll_items pi' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT pi.*, e.employee_code, e.name
             FROM payroll_items pi
             JOIN employees e ON e.id = pi.employee_id' . $whereSql . '
             ORDER BY pi.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'payroll_items');
    }

    public function generatePayroll(): void
    {
        $payload = Request::json();
        if (empty($payload['month_label'])) {
            $this->fail('month_label is required (example: 2026-04)', 422);
            return;
        }

        $employees = $this->pdo->query('SELECT id, ctc FROM employees WHERE status = "active"')->fetchAll();
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                'INSERT INTO payroll_runs (month_label, generated_at, generated_by) VALUES (:month_label, NOW(), :generated_by)'
            )->execute([
                'month_label' => $payload['month_label'],
                'generated_by' => $payload['generated_by'] ?? null,
            ]);

            $runId = (int) $this->pdo->lastInsertId();
            $itemStmt = $this->pdo->prepare(
                'INSERT INTO payroll_items (
                    payroll_run_id, employee_id, ctc_amount, gross_amount, deductions_amount, net_payout
                 ) VALUES (
                    :payroll_run_id, :employee_id, :ctc_amount, :gross_amount, :deductions_amount, :net_payout
                 )'
            );

            foreach ($employees as $employee) {
                $monthlyGross = ((float) $employee['ctc']) / 12;
                $deductions = $monthlyGross * 0.12;
                $net = $monthlyGross - $deductions;

                $itemStmt->execute([
                    'payroll_run_id' => $runId,
                    'employee_id' => $employee['id'],
                    'ctc_amount' => $employee['ctc'],
                    'gross_amount' => $monthlyGross,
                    'deductions_amount' => $deductions,
                    'net_payout' => $net,
                ]);
            }

            $this->pdo->commit();
            $this->ok(['payroll_run_id' => $runId, 'count' => count($employees)], 'Payroll generated');
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->fail('Payroll generation failed: ' . $e->getMessage(), 500);
        }
    }

    public function deletePayrollRun(array $params): void
    {
        $this->pdo->prepare('DELETE FROM payroll_runs WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Payroll run deleted');
    }
}
