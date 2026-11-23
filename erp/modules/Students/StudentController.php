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

        if ($status === 'deleted') {
            $where[] = 's.deleted_at IS NOT NULL';
        } else {
            $where[] = 's.deleted_at IS NULL';
            if ($status !== '') {
                $where[] = 'se.status = :status';
                $params['status'] = $status;
            }
        }

        if ($q !== '') {
            $where[] = '(s.first_name LIKE :q OR s.last_name LIKE :q OR s.admission_no LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($class !== '') {
            $where[] = 'se.class_name = :class_name';
            $params['class_name'] = $class;
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
        $this->pdo->prepare('UPDATE students SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Student deleted');
    }

    public function restore(array $params): void
    {
        $this->pdo->prepare('UPDATE students SET deleted_at = NULL WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Student restored');
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

    // ═══════════════════ STUDENT 360° DOSSIER ═══════════════════

    public function dossier(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $sStmt = $this->pdo->prepare(
            'SELECT s.*, se.class_name, se.section_name, se.status AS enrollment_status,
                    h.house_name, h.color_code AS house_color
             FROM students s
             LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1
             LEFT JOIN student_house_allocations sha ON sha.student_id = s.id AND sha.status = "Active"
             LEFT JOIN houses h ON h.id = sha.house_id
             WHERE s.id = :id LIMIT 1'
        );
        $sStmt->execute(['id' => $id]);
        $student = $sStmt->fetch();
        if (!$student) {
            $this->fail('Student not found', 404);
            return;
        }

        // Guardians
        $gStmt = $this->pdo->prepare('SELECT * FROM guardians WHERE student_id = :id');
        $gStmt->execute(['id' => $id]);
        $student['guardians'] = $gStmt->fetchAll();

        // Remarks
        $rStmt = $this->pdo->prepare('SELECT * FROM student_remarks_discipline WHERE student_id = :id ORDER BY id DESC');
        $rStmt->execute(['id' => $id]);
        $student['remarks'] = $rStmt->fetchAll();

        // Certificates
        $cStmt = $this->pdo->prepare('SELECT * FROM student_certificates WHERE student_id = :id ORDER BY id DESC');
        $cStmt->execute(['id' => $id]);
        $student['certificates'] = $cStmt->fetchAll();

        // Recent Exam Results
        $mStmt = $this->pdo->prepare(
            'SELECT em.*, e.title AS exam_title, sub.subject_name
             FROM exam_marks em
             JOIN exams e ON e.id = em.exam_id
             JOIN subjects sub ON sub.id = em.subject_id
             WHERE em.student_id = :id ORDER BY em.id DESC LIMIT 20'
        );
        $mStmt->execute(['id' => $id]);
        $student['exam_results'] = $mStmt->fetchAll();

        $this->ok(['dossier' => $student]);
    }

    // ═══════════════════ REMARKS & DISCIPLINE ═══════════════════

    public function listRemarks(array $params): void
    {
        $studentId = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM student_remarks_discipline WHERE student_id = :sid ORDER BY id DESC'
        );
        $stmt->execute(['sid' => $studentId]);
        $this->ok(['remarks' => $stmt->fetchAll()]);
    }

    public function createRemark(array $params): void
    {
        $studentId = (int) ($params['id'] ?? 0);
        $data = Request::json();

        $remarks = trim((string) ($data['remarks'] ?? ''));
        if ($remarks === '') {
            $this->fail('remarks text is required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO student_remarks_discipline (
                student_id, remark_date, remark_type, subject, remarks, parent_notification_required, entered_by, created_at
            ) VALUES (
                :sid, :rdate, :rtype, :subj, :rem, :pnotif, :by, NOW()
            )'
        );
        $stmt->execute([
            'sid' => $studentId,
            'rdate' => $data['remark_date'] ?? date('Y-m-d'),
            'rtype' => $data['remark_type'] ?? 'General',
            'subj' => $data['subject'] ?? 'Discipline & Conduct',
            'rem' => $remarks,
            'pnotif' => !empty($data['parent_notification_required']) ? 1 : 0,
            'by' => $data['entered_by'] ?? 'Teacher',
        ]);

        $this->ok(['id' => (int) $this->pdo->lastInsertId(), 'student_id' => $studentId], 'Remark recorded successfully');
    }

    public function deleteRemark(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM student_remarks_discipline WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Remark deleted successfully');
    }

    // ═══════════════════ PROMOTIONS ═══════════════════

    public function listPromotions(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $countStmt = $this->pdo->query('SELECT COUNT(*) AS c FROM student_promotions');
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT p.*, s.first_name, s.last_name, s.admission_no
                FROM student_promotions p
                JOIN students s ON s.id = p.student_id
                ORDER BY p.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'promotions');
    }

    public function batchPromote(): void
    {
        $data = Request::json();
        $studentIds = $data['student_ids'] ?? [];
        $fromClass = trim((string) ($data['from_class'] ?? ''));
        $toClass = trim((string) ($data['to_class'] ?? ''));
        $fromYear = trim((string) ($data['from_academic_year'] ?? '2025-2026'));
        $toYear = trim((string) ($data['to_academic_year'] ?? '2026-2027'));

        if (empty($studentIds) || !is_array($studentIds) || $toClass === '') {
            $this->fail('student_ids (array) and to_class are required', 422);
            return;
        }

        $insStmt = $this->pdo->prepare(
            'INSERT INTO student_promotions (
                student_id, from_academic_year, from_class, to_academic_year, to_class,
                to_section, final_result, promotion_status, promoted_by, promoted_at
            ) VALUES (
                :sid, :fy, :fc, :ty, :tc, :tsec, "Pass", "Promoted", "Admin", NOW()
            )'
        );

        $upEnr = $this->pdo->prepare(
            'UPDATE student_enrollments SET is_current = 0 WHERE student_id = :sid'
        );

        $newEnr = $this->pdo->prepare(
            'INSERT INTO student_enrollments (student_id, class_name, section_name, session_label, status, is_current, created_at)
             VALUES (:sid, :class_name, :section_name, :session_label, "promoted", 1, NOW())'
        );

        $promotedCount = 0;
        foreach ($studentIds as $sid) {
            $sid = (int) $sid;
            if ($sid <= 0) continue;

            $insStmt->execute([
                'sid' => $sid,
                'fy' => $fromYear,
                'fc' => $fromClass,
                'ty' => $toYear,
                'tc' => $toClass,
                'tsec' => $data['to_section'] ?? 'A',
            ]);

            $upEnr->execute(['sid' => $sid]);
            $newEnr->execute([
                'sid' => $sid,
                'class_name' => $toClass,
                'section_name' => $data['to_section'] ?? 'A',
                'session_label' => $toYear,
            ]);
            $promotedCount++;
        }

        $this->ok(['promoted_count' => $promotedCount, 'to_class' => $toClass], 'Students promoted successfully');
    }

    // ═══════════════════ CERTIFICATES & TC GENERATOR ═══════════════════

    public function listCertificates(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $studentId = Request::intQuery('student_id', 0);
        $type = trim((string) Request::query('type', ''));

        $where = [];
        $params = [];
        if ($studentId > 0) {
            $where[] = 'c.student_id = :sid';
            $params['sid'] = $studentId;
        }
        if ($type !== '') {
            $where[] = 'c.certificate_type = :type';
            $params['type'] = $type;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM student_certificates c' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT c.*, s.first_name, s.last_name, s.admission_no
                FROM student_certificates c
                JOIN students s ON s.id = c.student_id' . $whereSql . '
                ORDER BY c.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'certificates');
    }

    public function issueCertificate(): void
    {
        $data = Request::json();
        $studentId = (int) ($data['student_id'] ?? 0);
        $certType = trim((string) ($data['certificate_type'] ?? 'Transfer Certificate'));

        if ($studentId <= 0) {
            $this->fail('student_id is required', 422);
            return;
        }

        $certNo = 'SIBA/' . date('Y') . '/' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $certType), 0, 2)) . '-' . rand(1000, 9999);

        $stmt = $this->pdo->prepare(
            'INSERT INTO student_certificates (
                certificate_no, student_id, certificate_type, issue_date, reason, status, approved_by, content_json, created_at
            ) VALUES (
                :cno, :sid, :ctype, :idate, :reason, "Issued", :by, :json, NOW()
            )'
        );
        $stmt->execute([
            'cno' => $certNo,
            'sid' => $studentId,
            'ctype' => $certType,
            'idate' => $data['issue_date'] ?? date('Y-m-d'),
            'reason' => $data['reason'] ?? 'Application by parents',
            'by' => $data['approved_by'] ?? 'Principal',
            'json' => json_encode($data['custom_fields'] ?? []),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->ok(['id' => $id, 'certificate_no' => $certNo, 'certificate_type' => $certType], 'Certificate issued successfully');
    }

    public function getCertificate(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare(
            'SELECT c.*, s.first_name, s.last_name, s.admission_no, s.dob, s.gender,
                    se.class_name, se.section_name
             FROM student_certificates c
             JOIN students s ON s.id = c.student_id
             LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1
             WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $cert = $stmt->fetch();
        if (!$cert) {
            $this->fail('Certificate not found', 404);
            return;
        }

        $this->ok(['certificate' => $cert]);
    }

    public function classes(): void
    {
        $session = trim((string) Request::query('session_label', ''));
        $status = trim((string) Request::query('status', ''));

        $where = [
            'se.is_current = 1',
            'se.class_name IS NOT NULL',
            "TRIM(se.class_name) != ''",
        ];
        $params = [];

        // Defensively check if deleted_at exists on students table
        $hasDeletedAt = false;
        try {
            $hasDeletedAt = (bool) $this->pdo->query("SHOW COLUMNS FROM students LIKE 'deleted_at'")->fetchColumn();
        } catch (\Throwable) {}

        if ($hasDeletedAt) {
            $where[] = 's.deleted_at IS NULL';
        }

        if ($session !== '') {
            $where[] = 'se.session_label = :session_label';
            $params['session_label'] = $session;
        }

        if ($status !== '') {
            $where[] = 'se.status = :status';
            $params['status'] = $status;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT 
                TRIM(se.class_name) AS class_name,
                COALESCE(NULLIF(TRIM(se.section_name), ''), 'A') AS section_name,
                COUNT(s.id) AS student_count
            FROM student_enrollments se
            JOIN students s ON s.id = se.student_id
            {$whereSql}
            GROUP BY TRIM(se.class_name), COALESCE(NULLIF(TRIM(se.section_name), ''), 'A')
            ORDER BY class_name ASC, section_name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $cName = (string) $row['class_name'];
            $sName = (string) $row['section_name'];
            $count = (int) ($row['student_count'] ?? 0);

            if (!isset($grouped[$cName])) {
                $grouped[$cName] = [
                    'class_name' => $cName,
                    'sections' => [],
                    'total_students' => 0,
                ];
            }
            if (!in_array($sName, $grouped[$cName]['sections'], true)) {
                $grouped[$cName]['sections'][] = $sName;
            }
            $grouped[$cName]['total_students'] += $count;
        }

        $classes = array_values($grouped);

        $this->ok([
            'classes' => $classes,
            'items' => $rows,
            'flat' => $rows,
            'total_classes' => count($classes),
        ], 'Classes and sections retrieved successfully');
    }
}
