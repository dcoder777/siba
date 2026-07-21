<?php

declare(strict_types=1);

namespace modules\Finance;

use core\Controller;
use core\Request;

class FinanceController extends Controller
{
    public function listFeeStructures(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $session = trim((string) Request::query('academic_session', ''));
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(class_name LIKE :q OR fee_head LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($session !== '') {
            $where[] = 'academic_session = :academic_session';
            $params['academic_session'] = $session;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM fee_structures' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM fee_structures' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'fee_structures');
    }

    public function createFeeStructure(): void
    {
        $payload = Request::json();
        if (empty($payload['class_name']) || empty($payload['academic_session']) || !isset($payload['amount'])) {
            $this->fail('class_name, academic_session, amount are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO fee_structures (
                class_name, academic_session, fee_head, amount, frequency, created_at
             ) VALUES (
                :class_name, :academic_session, :fee_head, :amount, :frequency, NOW()
             )'
        );
        $stmt->execute([
            'class_name' => $payload['class_name'],
            'academic_session' => $payload['academic_session'],
            'fee_head' => $payload['fee_head'] ?? 'Tuition Fee',
            'amount' => $payload['amount'],
            'frequency' => $payload['frequency'] ?? 'monthly',
        ]);

        $this->ok(['fee_structure_id' => (int) $this->pdo->lastInsertId()], 'Fee structure created');
    }

    public function updateFeeStructure(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE fee_structures
             SET class_name = :class_name, academic_session = :academic_session, fee_head = :fee_head, amount = :amount, frequency = :frequency
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'class_name' => $payload['class_name'] ?? '',
            'academic_session' => $payload['academic_session'] ?? '',
            'fee_head' => $payload['fee_head'] ?? '',
            'amount' => $payload['amount'] ?? 0,
            'frequency' => $payload['frequency'] ?? 'monthly',
        ]);
        $this->ok([], 'Fee structure updated');
    }

    public function deleteFeeStructure(array $params): void
    {
        $this->pdo->prepare('DELETE FROM fee_structures WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Fee structure deleted');
    }

    public function listFeeDues(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $status = trim((string) Request::query('status', ''));
        $studentId = Request::query('student_id');
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }
        if ($studentId !== null && $studentId !== '') {
            $where[] = 'd.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM student_fee_dues d' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT d.*, s.admission_no, s.first_name, s.last_name, fs.fee_head, fs.class_name
             FROM student_fee_dues d
             JOIN students s ON s.id = d.student_id
             JOIN fee_structures fs ON fs.id = d.fee_structure_id' . $whereSql . '
             ORDER BY d.due_date ASC, d.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'fee_dues');
    }

    public function createFeeDue(): void
    {
        $payload = Request::json();
        if (empty($payload['student_id']) || empty($payload['fee_structure_id']) || empty($payload['due_date']) || !isset($payload['amount'])) {
            $this->fail('student_id, fee_structure_id, due_date, amount are required', 422);
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO student_fee_dues (student_id, fee_structure_id, due_date, amount, paid_amount, status, created_at)
             VALUES (:student_id, :fee_structure_id, :due_date, :amount, :paid_amount, :status, NOW())'
        )->execute([
            'student_id' => $payload['student_id'],
            'fee_structure_id' => $payload['fee_structure_id'],
            'due_date' => $payload['due_date'],
            'amount' => $payload['amount'],
            'paid_amount' => $payload['paid_amount'] ?? 0,
            'status' => $payload['status'] ?? 'pending',
        ]);
        $this->ok(['fee_due_id' => (int) $this->pdo->lastInsertId()], 'Fee due created');
    }

    public function updateFeeDue(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE student_fee_dues
             SET student_id = :student_id, fee_structure_id = :fee_structure_id, due_date = :due_date,
                 amount = :amount, paid_amount = :paid_amount, status = :status
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'student_id' => $payload['student_id'] ?? null,
            'fee_structure_id' => $payload['fee_structure_id'] ?? null,
            'due_date' => $payload['due_date'] ?? date('Y-m-d'),
            'amount' => $payload['amount'] ?? 0,
            'paid_amount' => $payload['paid_amount'] ?? 0,
            'status' => $payload['status'] ?? 'pending',
        ]);
        $this->ok([], 'Fee due updated');
    }

    public function deleteFeeDue(array $params): void
    {
        $this->pdo->prepare('DELETE FROM student_fee_dues WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Fee due deleted');
    }

    public function listPayments(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $source = trim((string) Request::query('source', ''));
        $studentId = Request::query('student_id');
        $where = [];
        $params = [];
        if ($source !== '') {
            $where[] = 'p.source = :source';
            $params['source'] = $source;
        }
        if ($studentId !== null && $studentId !== '') {
            $where[] = 'p.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM payments p' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT p.*, s.admission_no, s.first_name, s.last_name, r.receipt_no
             FROM payments p
             JOIN students s ON s.id = p.student_id
             LEFT JOIN receipts r ON r.payment_id = p.id' . $whereSql . '
             ORDER BY p.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'payments');
    }

    public function offlinePaymentEntry(): void
    {
        $payload = Request::json();
        $required = ['student_id', 'amount', 'payment_date', 'payment_mode'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === '' || $payload[$field] === null) {
                $this->fail("{$field} is required", 422);
                return;
            }
        }

        $receiptNo = 'RCP-' . date('Ymd') . '-' . random_int(1000, 9999);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO payments (
                    student_id, fee_due_id, amount, payment_date, payment_mode, source, reference_no, created_at
                 ) VALUES (
                    :student_id, :fee_due_id, :amount, :payment_date, :payment_mode, "offline", :reference_no, NOW()
                 )'
            )->execute([
                'student_id' => $payload['student_id'],
                'fee_due_id' => $payload['fee_due_id'] ?? null,
                'amount' => $payload['amount'],
                'payment_date' => $payload['payment_date'],
                'payment_mode' => $payload['payment_mode'],
                'reference_no' => $payload['reference_no'] ?? null,
            ]);

            $paymentId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO receipts (payment_id, receipt_no, generated_at) VALUES (:payment_id, :receipt_no, NOW())'
            )->execute([
                'payment_id' => $paymentId,
                'receipt_no' => $receiptNo,
            ]);

            if (!empty($payload['fee_due_id'])) {
                $this->pdo->prepare(
                    'UPDATE student_fee_dues
                     SET paid_amount = paid_amount + :amount,
                         status = CASE WHEN paid_amount + :amount >= amount THEN "paid" ELSE "pending" END
                     WHERE id = :id'
                )->execute([
                    'amount' => $payload['amount'],
                    'id' => $payload['fee_due_id'],
                ]);
            }

            $this->pdo->commit();
            $this->ok(['payment_id' => $paymentId, 'receipt_no' => $receiptNo], 'Offline payment recorded');
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->fail('Payment entry failed: ' . $e->getMessage(), 500);
        }
    }

    public function updatePayment(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE payments
             SET student_id = :student_id, fee_due_id = :fee_due_id, amount = :amount, payment_date = :payment_date,
                 payment_mode = :payment_mode, source = :source, reference_no = :reference_no
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'student_id' => $payload['student_id'] ?? null,
            'fee_due_id' => $payload['fee_due_id'] ?? null,
            'amount' => $payload['amount'] ?? 0,
            'payment_date' => $payload['payment_date'] ?? date('Y-m-d'),
            'payment_mode' => $payload['payment_mode'] ?? 'cash',
            'source' => $payload['source'] ?? 'offline',
            'reference_no' => $payload['reference_no'] ?? null,
        ]);
        $this->ok([], 'Payment updated');
    }

    public function deletePayment(array $params): void
    {
        $this->pdo->prepare('DELETE FROM payments WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Payment deleted');
    }

    public function feeSummary(): void
    {
        $stmt = $this->pdo->query(
            'SELECT
                SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) AS paid,
                SUM(CASE WHEN status = "pending" THEN amount - paid_amount ELSE 0 END) AS pending,
                SUM(CASE WHEN due_date < CURDATE() AND status <> "paid" THEN amount - paid_amount ELSE 0 END) AS arrears
             FROM student_fee_dues'
        );
        $this->ok(['summary' => $stmt->fetch()], 'Fee summary');
    }

    public function listReconciliations(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $status = trim((string) Request::query('status', ''));
        $q = trim((string) Request::query('q', ''));
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(gateway_reference LIKE :q OR website_order_id LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM payment_reconciliations' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT * FROM payment_reconciliations' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'reconciliations');
    }

    public function reconcileWebsitePayment(): void
    {
        $payload = Request::json();
        if (empty($payload['gateway_reference']) || empty($payload['status'])) {
            $this->fail('gateway_reference and status are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO payment_reconciliations (
                gateway_reference, website_order_id, amount, status, reconciled_at, notes
             ) VALUES (
                :gateway_reference, :website_order_id, :amount, :status, NOW(), :notes
             )'
        );
        $stmt->execute([
            'gateway_reference' => $payload['gateway_reference'],
            'website_order_id' => $payload['website_order_id'] ?? null,
            'amount' => $payload['amount'] ?? 0,
            'status' => $payload['status'],
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->ok([], 'Reconciliation entry saved');
    }

    public function updateReconciliation(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE payment_reconciliations
             SET gateway_reference = :gateway_reference, website_order_id = :website_order_id,
                 amount = :amount, status = :status, notes = :notes
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'gateway_reference' => $payload['gateway_reference'] ?? '',
            'website_order_id' => $payload['website_order_id'] ?? null,
            'amount' => $payload['amount'] ?? 0,
            'status' => $payload['status'] ?? 'pending',
            'notes' => $payload['notes'] ?? null,
        ]);
        $this->ok([], 'Reconciliation updated');
    }

    public function deleteReconciliation(array $params): void
    {
        $this->pdo->prepare('DELETE FROM payment_reconciliations WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Reconciliation deleted');
    }
}
