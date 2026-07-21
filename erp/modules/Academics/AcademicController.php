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
}
