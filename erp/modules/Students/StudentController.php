<?php

declare(strict_types=1);

namespace modules\Students;

use core\Controller;
use core\Request;

class StudentController extends Controller
{
    public function list(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $class = trim((string) Request::query('class_name', ''));
        $status = trim((string) Request::query('status', ''));

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(s.first_name LIKE :q OR s.last_name LIKE :q OR s.admission_no LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($class !== '') {
            $where[] = 'se.class_name = :class_name';
            $params['class_name'] = $class;
        }
        if ($status !== '') {
            $where[] = 'se.status = :status';
            $params['status'] = $status;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c
             FROM students s
             LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.dob, s.phone, s.email,
                       se.class_name, se.section_name, se.status
                FROM students s
                LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1' . $whereSql . '
                ORDER BY s.id DESC
                LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'students');
    }

    public function show(array $params): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $params['id']]);
        $student = $stmt->fetch();
        if (!$student) {
            $this->fail('Student not found', 404);
            return;
        }
        $this->ok(['student' => $student]);
    }

    public function create(): void
    {
        $payload = Request::json();
        $required = ['admission_no', 'first_name', 'last_name'];
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                $this->fail("{$field} is required", 422);
                return;
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO students (
                admission_no, first_name, last_name, gender, dob, blood_group, phone, email, address, created_at
             ) VALUES (
                :admission_no, :first_name, :last_name, :gender, :dob, :blood_group, :phone, :email, :address, NOW()
             )'
        );
        $stmt->execute([
            'admission_no' => $payload['admission_no'],
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'gender' => $payload['gender'] ?? null,
            'dob' => $payload['dob'] ?? null,
            'blood_group' => $payload['blood_group'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'address' => $payload['address'] ?? null,
        ]);

        $this->ok(['student_id' => (int) $this->pdo->lastInsertId()], 'Student created');
    }

    public function update(array $params): void
    {
        $payload = Request::json();
        $stmt = $this->pdo->prepare(
            'UPDATE students SET
                first_name = :first_name, last_name = :last_name, gender = :gender, dob = :dob,
                blood_group = :blood_group, phone = :phone, email = :email, address = :address, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $params['id'],
            'first_name' => $payload['first_name'] ?? '',
            'last_name' => $payload['last_name'] ?? '',
            'gender' => $payload['gender'] ?? null,
            'dob' => $payload['dob'] ?? null,
            'blood_group' => $payload['blood_group'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'address' => $payload['address'] ?? null,
        ]);
        $this->ok([], 'Student updated');
    }

    public function delete(array $params): void
    {
        $this->pdo->prepare('DELETE FROM students WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Student deleted');
    }

    public function listAttendance(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $studentId = Request::query('student_id');
        $date = Request::query('date');
        $where = [];
        $params = [];
        if ($studentId !== null && $studentId !== '') {
            $where[] = 'a.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        if ($date !== null && $date !== '') {
            $where[] = 'a.attendance_date = :attendance_date';
            $params['attendance_date'] = $date;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM attendance_records a' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT a.*, s.first_name, s.last_name, s.admission_no
             FROM attendance_records a
             JOIN students s ON s.id = a.student_id' . $whereSql . '
             ORDER BY a.attendance_date DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'attendance');
    }

    public function createAttendance(array $context): void
    {
        $payload = Request::json();
        if (empty($payload['student_id']) || empty($payload['date']) || empty($payload['status'])) {
            $this->fail('student_id, date, status are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_records (student_id, attendance_date, status, remark, marked_by, created_at)
             VALUES (:student_id, :attendance_date, :status, :remark, :marked_by, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), remark = VALUES(remark), marked_by = VALUES(marked_by)'
        );
        $stmt->execute([
            'student_id' => $payload['student_id'],
            'attendance_date' => $payload['date'],
            'status' => $payload['status'],
            'remark' => $payload['remark'] ?? null,
            'marked_by' => $context['user']['id'] ?? null,
        ]);

        $this->ok([], 'Attendance saved');
    }

    public function updateAttendance(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE attendance_records
             SET status = :status, remark = :remark
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'status' => $payload['status'] ?? 'present',
            'remark' => $payload['remark'] ?? null,
        ]);
        $this->ok([], 'Attendance updated');
    }

    public function deleteAttendance(array $params): void
    {
        $this->pdo->prepare('DELETE FROM attendance_records WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Attendance deleted');
    }

    public function listLifecycle(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $studentId = Request::query('student_id');
        $whereSql = '';
        $params = [];
        if ($studentId !== null && $studentId !== '') {
            $whereSql = ' WHERE e.student_id = :student_id';
            $params['student_id'] = $studentId;
        }

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM student_enrollments e' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT e.*, s.admission_no, s.first_name, s.last_name
             FROM student_enrollments e
             JOIN students s ON s.id = e.student_id' . $whereSql . '
             ORDER BY e.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'lifecycle');
    }

    public function createLifecycle(): void
    {
        $payload = Request::json();
        if (empty($payload['student_id']) || empty($payload['class_name']) || empty($payload['session_label']) || empty($payload['status'])) {
            $this->fail('student_id, class_name, session_label, status are required', 422);
            return;
        }

        $this->pdo->prepare('UPDATE student_enrollments SET is_current = 0 WHERE student_id = :student_id')
            ->execute(['student_id' => $payload['student_id']]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO student_enrollments (student_id, class_name, section_name, session_label, status, is_current, created_at)
             VALUES (:student_id, :class_name, :section_name, :session_label, :status, 1, NOW())'
        );
        $stmt->execute([
            'student_id' => $payload['student_id'],
            'class_name' => $payload['class_name'],
            'section_name' => $payload['section_name'] ?? null,
            'session_label' => $payload['session_label'],
            'status' => $payload['status'],
        ]);

        $this->ok([], 'Student lifecycle updated');
    }

    public function updateLifecycle(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE student_enrollments
             SET class_name = :class_name, section_name = :section_name, session_label = :session_label, status = :status, is_current = :is_current
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'class_name' => $payload['class_name'] ?? '',
            'section_name' => $payload['section_name'] ?? null,
            'session_label' => $payload['session_label'] ?? '',
            'status' => $payload['status'] ?? 'active',
            'is_current' => $payload['is_current'] ?? 1,
        ]);
        $this->ok([], 'Lifecycle record updated');
    }

    public function deleteLifecycle(array $params): void
    {
        $this->pdo->prepare('DELETE FROM student_enrollments WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Lifecycle record deleted');
    }
}
