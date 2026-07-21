<?php

declare(strict_types=1);

namespace modules\Admissions;

use core\Controller;
use core\Request;

class AdmissionController extends Controller
{
    private function createParentUser(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($name === '') {
            $this->fail('Parent name is required', 422);
            return [];
        }
        if (strlen($phone) < 10) {
            $this->fail('Valid 10-digit phone number is required', 422);
            return [];
        }
        if ($email === '') {
            $this->fail('Email is required', 422);
            return [];
        }
        if ($password === '') {
            $this->fail('Password is required', 422);
            return [];
        }

        $roleStmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = 'parent' LIMIT 1");
        $roleStmt->execute();
        $role = $roleStmt->fetch();
        if (!$role) {
            $this->fail('Parent role not found', 500);
            return [];
        }

        $erpEmail = $email ?: 'parent_' . $phone . '@siba.local';
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $userStmt = $this->pdo->prepare(
            'INSERT INTO users (role_id, name, email, password_hash, is_active) VALUES (:role_id, :name, :email, :password_hash, 1)'
        );
        $userStmt->execute([
            'role_id' => $role['id'],
            'name' => $name,
            'email' => $erpEmail,
            'password_hash' => $hashed,
        ]);
        $userId = (int) $this->pdo->lastInsertId();

        $parentStmt = $this->pdo->prepare(
            'INSERT INTO parents (name, email, phone, password, user_id) VALUES (:name, :email, :phone, :password, :user_id)'
        );
        $parentStmt->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $hashed,
            'user_id' => $userId,
        ]);
        $parentId = (int) $this->pdo->lastInsertId();

        return [
            'parent_id' => $parentId,
            'user_id' => $userId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    public function registerParent(): void
    {
        $payload = Request::json();
        if (empty($payload)) {
            $this->fail('Request body is required', 422);
            return;
        }

        try {
            $this->pdo->beginTransaction();
            $result = $this->createParentUser($payload);
            if (empty($result)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return;
            }
            $this->pdo->commit();

            $this->ok([
                'parent_id' => $result['parent_id'],
                'user_id' => $result['user_id'],
            ], 'Parent registered successfully');
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail('Failed to register parent: ' . $e->getMessage(), 500);
        }
    }

    public function apply(): void
    {
        $payload = Request::json();
        if (empty($payload)) {
            $this->fail('Request body is required', 422);
            return;
        }

        $studentName = trim((string) ($payload['student_name'] ?? ''));
        $classSought = trim((string) ($payload['class_sought'] ?? ''));
        $dob = trim((string) ($payload['dob'] ?? ''));
        $fatherName = trim((string) ($payload['father_name'] ?? ''));
        $motherName = trim((string) ($payload['mother_name'] ?? ''));

        if ($studentName === '' || $classSought === '' || $dob === '' || $fatherName === '' || $motherName === '') {
            $this->fail('student_name, class_sought, dob, father_name, and mother_name are required', 422);
            return;
        }

        try {
            $this->pdo->beginTransaction();

            $parentResult = $this->createParentUser($payload);
            if (empty($parentResult)) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return;
            }

            $fields = [
                'parent_id', 'student_name', 'first_name', 'middle_name', 'last_name',
                'dob', 'gender', 'religion', 'blood_group', 'aadhaar_no',
                'previous_school', 'previous_class', 'class_sought',
                'address_line1', 'address_line2', 'post_office', 'police_station',
                'district', 'village_city', 'pin', 'state', 'country',
                'father_name', 'father_occupation', 'mother_name', 'mother_occupation',
                'guardian_name', 'guardian_occupation', 'family_annual_income',
                'contact_no', 'email', 'address',
            ];

            $colNames = [];
            $placeholders = [];
            $params = [];
            foreach ($fields as $f) {
                $colNames[] = $f;
                $placeholders[] = ':' . $f;
                $params[$f] = $payload[$f] ?? null;
            }
            $params['parent_id'] = $parentResult['parent_id'];
            $params['class_sought'] = $classSought;
            $params['dob'] = $dob;
            $params['father_name'] = $fatherName;
            $params['mother_name'] = $motherName;
            $params['student_name'] = $studentName;

            $appStmt = $this->pdo->prepare(
                'INSERT INTO applications (' . implode(', ', $colNames) . ", status, applied_at) VALUES (" . implode(', ', $placeholders) . ", 'Application started', NOW())"
            );
            $appStmt->execute($params);
            $applicationId = (int) $this->pdo->lastInsertId();

            $this->pdo->commit();

            $this->ok([
                'parent_id' => $parentResult['parent_id'],
                'application_id' => $applicationId,
                'user_id' => $parentResult['user_id'],
            ], 'Application submitted successfully');

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail('Failed to submit application: ' . $e->getMessage(), 500);
        }
    }

    public function list(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $status = trim((string) Request::query('status', ''));

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(a.student_name LIKE :q1 OR a.father_name LIKE :q2 OR a.mother_name LIKE :q3 OR p.name LIKE :q4 OR p.phone LIKE :q5)';
            $likeQ = '%' . $q . '%';
            $params['q1'] = $likeQ;
            $params['q2'] = $likeQ;
            $params['q3'] = $likeQ;
            $params['q4'] = $likeQ;
            $params['q5'] = $likeQ;
        }
        if ($status !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM applications a LEFT JOIN parents p ON p.id = a.parent_id' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT a.id, a.student_name, a.first_name, a.middle_name, a.last_name, a.dob, a.gender,
                       a.class_sought, a.status, a.admission_no, a.student_id, a.applied_at,
                       p.name AS parent_name, p.phone AS parent_phone
                FROM applications a
                LEFT JOIN parents p ON p.id = a.parent_id' . $whereSql . '
                ORDER BY a.applied_at DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'applications');
    }
}
