<?php

declare(strict_types=1);

namespace modules\Academics;

use core\Controller;
use core\Request;

class AcademicController extends Controller
{
    public function listSubjects(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $className = trim((string) Request::query('class_name', ''));
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(subject_code LIKE :q OR subject_name LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($className !== '') {
            $where[] = 'class_name = :class_name';
            $params['class_name'] = $className;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM subjects' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM subjects' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'subjects');
    }

    public function createSubject(): void
    {
        $payload = Request::json();
        if (empty($payload['subject_code']) || empty($payload['subject_name']) || empty($payload['class_name'])) {
            $this->fail('subject_code, subject_name and class_name are required', 422);
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO subjects (subject_code, subject_name, class_name, created_at)
             VALUES (:subject_code, :subject_name, :class_name, NOW())'
        )->execute([
            'subject_code' => $payload['subject_code'],
            'subject_name' => $payload['subject_name'],
            'class_name' => $payload['class_name'],
        ]);
        $this->ok(['subject_id' => (int) $this->pdo->lastInsertId()], 'Subject created');
    }

    public function updateSubject(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE subjects SET subject_code = :subject_code, subject_name = :subject_name, class_name = :class_name WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'subject_code' => $payload['subject_code'] ?? '',
            'subject_name' => $payload['subject_name'] ?? '',
            'class_name' => $payload['class_name'] ?? '',
        ]);
        $this->ok([], 'Subject updated');
    }

    public function deleteSubject(array $params): void
    {
        $this->pdo->prepare('DELETE FROM subjects WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Subject deleted');
    }

    public function listExamResults(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $studentId = Request::query('student_id');
        $examName = Request::query('exam_name');
        $where = [];
        $params = [];
        if ($studentId !== null && $studentId !== '') {
            $where[] = 'er.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        if ($examName !== null && $examName !== '') {
            $where[] = 'er.exam_name = :exam_name';
            $params['exam_name'] = $examName;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM exam_results er' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT er.*, s.admission_no, s.first_name, s.last_name, sub.subject_name
             FROM exam_results er
             JOIN students s ON s.id = er.student_id
             JOIN subjects sub ON sub.id = er.subject_id' . $whereSql . '
             ORDER BY er.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'exam_results');
    }

    public function addExamResult(): void
    {
        $payload = Request::json();
        $required = ['student_id', 'subject_id', 'exam_name', 'max_marks', 'obtained_marks'];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                $this->fail("{$field} is required", 422);
                return;
            }
        }

        $grade = $payload['grade'] ?? $this->grade((float) $payload['obtained_marks'], (float) $payload['max_marks']);

        $stmt = $this->pdo->prepare(
            'INSERT INTO exam_results (
                student_id, subject_id, exam_name, max_marks, obtained_marks, grade, result_date, created_at
             ) VALUES (
                :student_id, :subject_id, :exam_name, :max_marks, :obtained_marks, :grade, :result_date, NOW()
             )'
        );
        $stmt->execute([
            'student_id' => $payload['student_id'],
            'subject_id' => $payload['subject_id'],
            'exam_name' => $payload['exam_name'],
            'max_marks' => $payload['max_marks'],
            'obtained_marks' => $payload['obtained_marks'],
            'grade' => $grade,
            'result_date' => $payload['result_date'] ?? date('Y-m-d'),
        ]);

        $this->ok([], 'Exam result saved');
    }

    public function updateExamResult(array $params): void
    {
        $payload = Request::json();
        $max = (float) ($payload['max_marks'] ?? 0);
        $obt = (float) ($payload['obtained_marks'] ?? 0);
        $grade = $payload['grade'] ?? $this->grade($obt, $max);
        $this->pdo->prepare(
            'UPDATE exam_results SET
                student_id = :student_id, subject_id = :subject_id, exam_name = :exam_name,
                max_marks = :max_marks, obtained_marks = :obtained_marks, grade = :grade, result_date = :result_date
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'student_id' => $payload['student_id'] ?? null,
            'subject_id' => $payload['subject_id'] ?? null,
            'exam_name' => $payload['exam_name'] ?? '',
            'max_marks' => $max,
            'obtained_marks' => $obt,
            'grade' => $grade,
            'result_date' => $payload['result_date'] ?? date('Y-m-d'),
        ]);
        $this->ok([], 'Exam result updated');
    }

    public function deleteExamResult(array $params): void
    {
        $this->pdo->prepare('DELETE FROM exam_results WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Exam result deleted');
    }

    public function listAssignments(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $className = trim((string) Request::query('class_name', ''));
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(title LIKE :q OR description LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($className !== '') {
            $where[] = 'class_name = :class_name';
            $params['class_name'] = $className;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM assignments' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM assignments' . $whereSql . ' ORDER BY due_date DESC, id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'assignments');
    }

    public function addAssignment(): void
    {
        $payload = Request::json();
        $required = ['title', 'class_name', 'due_date'];
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                $this->fail("{$field} is required", 422);
                return;
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO assignments (
                title, description, class_name, section_name, due_date, assigned_by, created_at
             ) VALUES (
                :title, :description, :class_name, :section_name, :due_date, :assigned_by, NOW()
             )'
        );
        $stmt->execute([
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'class_name' => $payload['class_name'],
            'section_name' => $payload['section_name'] ?? null,
            'due_date' => $payload['due_date'],
            'assigned_by' => $payload['assigned_by'] ?? null,
        ]);

        $this->ok(['assignment_id' => (int) $this->pdo->lastInsertId()], 'Assignment created');
    }

    public function updateAssignment(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE assignments SET
                title = :title, description = :description, class_name = :class_name, section_name = :section_name,
                due_date = :due_date, assigned_by = :assigned_by
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'title' => $payload['title'] ?? '',
            'description' => $payload['description'] ?? null,
            'class_name' => $payload['class_name'] ?? '',
            'section_name' => $payload['section_name'] ?? null,
            'due_date' => $payload['due_date'] ?? date('Y-m-d'),
            'assigned_by' => $payload['assigned_by'] ?? null,
        ]);
        $this->ok([], 'Assignment updated');
    }

    public function deleteAssignment(array $params): void
    {
        $this->pdo->prepare('DELETE FROM assignments WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Assignment deleted');
    }

    public function listSubmissions(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $assignmentId = Request::query('assignment_id');
        $studentId = Request::query('student_id');
        $where = [];
        $params = [];
        if ($assignmentId !== null && $assignmentId !== '') {
            $where[] = 'sub.assignment_id = :assignment_id';
            $params['assignment_id'] = $assignmentId;
        }
        if ($studentId !== null && $studentId !== '') {
            $where[] = 'sub.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM assignment_submissions sub' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT sub.*, a.title AS assignment_title, s.admission_no, s.first_name, s.last_name
             FROM assignment_submissions sub
             JOIN assignments a ON a.id = sub.assignment_id
             JOIN students s ON s.id = sub.student_id' . $whereSql . '
             ORDER BY sub.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'submissions');
    }

    public function submitAssignment(): void
    {
        $payload = Request::json();
        if (empty($payload['assignment_id']) || empty($payload['student_id'])) {
            $this->fail('assignment_id and student_id are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO assignment_submissions (
                assignment_id, student_id, submitted_at, submission_note, status
             ) VALUES (
                :assignment_id, :student_id, NOW(), :submission_note, :status
             )'
        );
        $stmt->execute([
            'assignment_id' => $payload['assignment_id'],
            'student_id' => $payload['student_id'],
            'submission_note' => $payload['submission_note'] ?? null,
            'status' => $payload['status'] ?? 'submitted',
        ]);

        $this->ok([], 'Assignment submission recorded');
    }

    public function updateSubmission(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE assignment_submissions
             SET status = :status, submission_note = :submission_note, marks_awarded = :marks_awarded
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'status' => $payload['status'] ?? 'submitted',
            'submission_note' => $payload['submission_note'] ?? null,
            'marks_awarded' => $payload['marks_awarded'] ?? null,
        ]);
        $this->ok([], 'Submission updated');
    }

    public function deleteSubmission(array $params): void
    {
        $this->pdo->prepare('DELETE FROM assignment_submissions WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Submission deleted');
    }

    public function listMessages(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $studentId = Request::query('student_id');
        $whereSql = '';
        $params = [];
        if ($studentId !== null && $studentId !== '') {
            $whereSql = ' WHERE m.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM parent_teacher_messages m' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT m.*, s.admission_no, s.first_name, s.last_name
             FROM parent_teacher_messages m
             JOIN students s ON s.id = m.student_id' . $whereSql . '
             ORDER BY m.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'messages');
    }

    public function parentTeacherMessage(array $context): void
    {
        $payload = Request::json();
        if (empty($payload['student_id']) || empty($payload['receiver_user_id']) || empty($payload['message'])) {
            $this->fail('student_id, receiver_user_id, message are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO parent_teacher_messages (
                student_id, sender_user_id, receiver_user_id, message, created_at
             ) VALUES (
                :student_id, :sender_user_id, :receiver_user_id, :message, NOW()
             )'
        );
        $stmt->execute([
            'student_id' => $payload['student_id'],
            'sender_user_id' => $context['user']['id'] ?? null,
            'receiver_user_id' => $payload['receiver_user_id'],
            'message' => $payload['message'],
        ]);

        $this->ok([], 'Message sent');
    }

    public function updateMessage(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare('UPDATE parent_teacher_messages SET message = :message WHERE id = :id')
            ->execute([
                'id' => $params['id'],
                'message' => $payload['message'] ?? '',
            ]);
        $this->ok([], 'Message updated');
    }

    public function deleteMessage(array $params): void
    {
        $this->pdo->prepare('DELETE FROM parent_teacher_messages WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Message deleted');
    }

    private function grade(float $obtained, float $max): string
    {
        if ($max <= 0) {
            return 'F';
        }
        $percent = ($obtained / $max) * 100;
        return match (true) {
            $percent >= 90 => 'A+',
            $percent >= 80 => 'A',
            $percent >= 70 => 'B',
            $percent >= 60 => 'C',
            $percent >= 50 => 'D',
            default => 'F',
        };
    }

    // ═══════════════════ EXAM SCHEDULES & TERMS ═══════════════════

    public function listExams(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $countStmt = $this->pdo->query('SELECT COUNT(*) AS c FROM exams');
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM exams ORDER BY start_date DESC, id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'exams');
    }

    public function createExam(): void
    {
        $data = Request::json();
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $this->fail('title is required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO exams (title, academic_year, start_date, end_date, status, created_at)
             VALUES (:title, :ay, :sdate, :edate, :status, NOW())'
        );
        $stmt->execute([
            'title' => $title,
            'ay' => $data['academic_year'] ?? '2026-2027',
            'sdate' => $data['start_date'] ?? date('Y-m-d'),
            'edate' => $data['end_date'] ?? date('Y-m-d', strtotime('+7 days')),
            'status' => $data['status'] ?? 'Upcoming',
        ]);

        $this->ok(['id' => (int) $this->pdo->lastInsertId(), 'title' => $title], 'Exam term created successfully');
    }

    public function getExam(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM exams WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $exam = $stmt->fetch();
        if (!$exam) {
            $this->fail('Exam not found', 404);
            return;
        }

        $this->ok(['exam' => $exam]);
    }

    public function updateExam(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Request::json();

        $stmt = $this->pdo->prepare(
            'UPDATE exams SET
                title = COALESCE(:title, title),
                academic_year = COALESCE(:ay, academic_year),
                start_date = COALESCE(:sdate, start_date),
                end_date = COALESCE(:edate, end_date),
                status = COALESCE(:status, status)
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'title' => isset($data['title']) ? trim((string) $data['title']) : null,
            'ay' => isset($data['academic_year']) ? trim((string) $data['academic_year']) : null,
            'sdate' => $data['start_date'] ?? null,
            'edate' => $data['end_date'] ?? null,
            'status' => isset($data['status']) ? trim((string) $data['status']) : null,
        ]);

        $this->ok(['id' => $id], 'Exam updated successfully');
    }

    public function deleteExam(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM exams WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Exam deleted successfully');
    }

    // ═══════════════════ EXAM MARKS (MARKS ONLY) ═══════════════════

    public function listMarks(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $examId = Request::intQuery('exam_id', 0);
        $studentId = Request::intQuery('student_id', 0);
        $subjectId = Request::intQuery('subject_id', 0);

        $where = [];
        $params = [];
        if ($examId > 0) {
            $where[] = 'em.exam_id = :eid';
            $params['eid'] = $examId;
        }
        if ($studentId > 0) {
            $where[] = 'em.student_id = :sid';
            $params['sid'] = $studentId;
        }
        if ($subjectId > 0) {
            $where[] = 'em.subject_id = :subid';
            $params['subid'] = $subjectId;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM exam_marks em' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT em.*, e.title AS exam_title, s.admission_no, s.first_name, s.last_name, sub.subject_name, sub.subject_code
                FROM exam_marks em
                JOIN exams e ON e.id = em.exam_id
                JOIN students s ON s.id = em.student_id
                JOIN subjects sub ON sub.id = em.subject_id' . $whereSql . '
                ORDER BY em.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'marks');
    }

    public function saveMarks(): void
    {
        $data = Request::json();
        $examId = (int) ($data['exam_id'] ?? 0);
        $studentId = (int) ($data['student_id'] ?? 0);
        $subjectId = (int) ($data['subject_id'] ?? 0);
        $marks = (float) ($data['marks_obtained'] ?? 0);

        if ($examId <= 0 || $studentId <= 0 || $subjectId <= 0) {
            $this->fail('exam_id, student_id, and subject_id are required', 422);
            return;
        }

        $chk = $this->pdo->prepare(
            'SELECT id FROM exam_marks WHERE exam_id = :eid AND student_id = :sid AND subject_id = :subid LIMIT 1'
        );
        $chk->execute(['eid' => $examId, 'sid' => $studentId, 'subid' => $subjectId]);
        $existing = $chk->fetch();

        if ($existing) {
            $up = $this->pdo->prepare(
                'UPDATE exam_marks SET marks_obtained = :m, remarks = :rem WHERE id = :id'
            );
            $up->execute(['m' => $marks, 'rem' => $data['remarks'] ?? '', 'id' => $existing['id']]);
            $this->ok(['id' => (int) $existing['id'], 'marks_obtained' => $marks], 'Marks updated successfully');
        } else {
            $ins = $this->pdo->prepare(
                'INSERT INTO exam_marks (exam_id, student_id, subject_id, marks_obtained, remarks, created_at)
                 VALUES (:eid, :sid, :subid, :m, :rem, NOW())'
            );
            $ins->execute([
                'eid' => $examId,
                'sid' => $studentId,
                'subid' => $subjectId,
                'm' => $marks,
                'rem' => $data['remarks'] ?? '',
            ]);
            $this->ok(['id' => (int) $this->pdo->lastInsertId(), 'marks_obtained' => $marks], 'Marks recorded successfully');
        }
    }

    public function batchSaveMarks(): void
    {
        $data = Request::json();
        $examId = (int) ($data['exam_id'] ?? 0);
        $subjectId = (int) ($data['subject_id'] ?? 0);
        $entries = $data['marks'] ?? [];

        if ($examId <= 0 || $subjectId <= 0 || empty($entries) || !is_array($entries)) {
            $this->fail('exam_id, subject_id, and marks array are required', 422);
            return;
        }

        $saved = 0;
        foreach ($entries as $e) {
            $studentId = (int) ($e['student_id'] ?? 0);
            $marks = (float) ($e['marks_obtained'] ?? 0);
            if ($studentId <= 0) continue;

            $chk = $this->pdo->prepare('SELECT id FROM exam_marks WHERE exam_id = :eid AND student_id = :sid AND subject_id = :subid LIMIT 1');
            $chk->execute(['eid' => $examId, 'sid' => $studentId, 'subid' => $subjectId]);
            $row = $chk->fetch();

            if ($row) {
                $this->pdo->prepare('UPDATE exam_marks SET marks_obtained = :m WHERE id = :id')
                    ->execute(['m' => $marks, 'id' => $row['id']]);
            } else {
                $this->pdo->prepare('INSERT INTO exam_marks (exam_id, student_id, subject_id, marks_obtained, created_at) VALUES (:eid, :sid, :subid, :m, NOW())')
                    ->execute(['eid' => $examId, 'sid' => $studentId, 'subid' => $subjectId, 'm' => $marks]);
            }
            $saved++;
        }

        $this->ok(['exam_id' => $examId, 'subject_id' => $subjectId, 'saved_count' => $saved], 'Batch marks recorded successfully');
    }

    public function reportCard(array $params): void
    {
        $studentId = (int) ($params['student_id'] ?? 0);
        $examId = (int) ($params['exam_id'] ?? 0);

        // Student details
        $sStmt = $this->pdo->prepare(
            'SELECT s.*, se.class_name, se.section_name
             FROM students s
             LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1
             WHERE s.id = :id LIMIT 1'
        );
        $sStmt->execute(['id' => $studentId]);
        $student = $sStmt->fetch();
        if (!$student) {
            $this->fail('Student not found', 404);
            return;
        }

        // Exam details
        $eStmt = $this->pdo->prepare('SELECT * FROM exams WHERE id = :id LIMIT 1');
        $eStmt->execute(['id' => $examId]);
        $exam = $eStmt->fetch();
        if (!$exam) {
            $this->fail('Exam not found', 404);
            return;
        }

        // Marks (Marks-only evaluation system as requested)
        $mStmt = $this->pdo->prepare(
            'SELECT em.marks_obtained, em.remarks, sub.subject_name, sub.subject_code, 100 AS max_marks
             FROM exam_marks em
             JOIN subjects sub ON sub.id = em.subject_id
             WHERE em.student_id = :sid AND em.exam_id = :eid
             ORDER BY sub.subject_name ASC'
        );
        $mStmt->execute(['sid' => $studentId, 'eid' => $examId]);
        $subjectsMarks = $mStmt->fetchAll();

        $totalObtained = 0.0;
        $totalMax = 0.0;
        foreach ($subjectsMarks as $row) {
            $totalObtained += (float) $row['marks_obtained'];
            $totalMax += (float) $row['max_marks'];
        }

        $percentage = $totalMax > 0 ? round(($totalObtained / $totalMax) * 100, 2) : 0;

        $this->ok([
            'student' => $student,
            'exam' => $exam,
            'subjects' => $subjectsMarks,
            'summary' => [
                'total_obtained' => $totalObtained,
                'total_max' => $totalMax,
                'percentage' => $percentage,
                'result' => $percentage >= 33.0 ? 'PASSED' : 'NEEDS IMPROVEMENT',
                'evaluation_scheme' => 'Marks Only',
            ]
        ]);
    }
}
