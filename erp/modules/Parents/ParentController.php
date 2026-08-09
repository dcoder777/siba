<?php

declare(strict_types=1);

namespace modules\Parents;

use core\Controller;
use core\Request;

class ParentController extends Controller
{
    public function list(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(p.name LIKE :q OR p.email LIKE :q2 OR p.phone LIKE :q3)';
            $like = '%' . $q . '%';
            $params[':q'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM parents p $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT p.id, p.name, p.email, p.phone, p.user_id, p.created_at,
                       (SELECT COUNT(*) FROM applications WHERE parent_id = p.id) AS application_count
                FROM parents p $whereSql
                ORDER BY p.id DESC
                LIMIT $limit OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $parents = $stmt->fetchAll();

        $this->ok([
            'parents' => $parents,
            'pagination' => $this->paginationMeta($page, $limit, $total),
        ]);
    }

    public function get(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('Invalid parent ID', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.name, p.email, p.phone, p.user_id, p.created_at,
                    (SELECT COUNT(*) FROM applications WHERE parent_id = p.id) AS application_count
             FROM parents p WHERE p.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $parent = $stmt->fetch();

        if (!$parent) {
            $this->fail('Parent not found', 404);
            return;
        }

        $this->ok(['parent' => $parent]);
    }

    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('Invalid parent ID', 422);
            return;
        }

        $payload = Request::json();
        if (empty($payload)) {
            $this->fail('Request body is required', 422);
            return;
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($payload['phone'] ?? ''));

        if ($name === '') {
            $this->fail('Parent name is required', 422);
            return;
        }

        $this->pdo->prepare(
            'UPDATE parents SET name = :name, email = :email, phone = :phone WHERE id = :id'
        )->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'id' => $id]);

        // Also update linked user if exists
        $parent = $this->pdo->prepare('SELECT user_id FROM parents WHERE id = ?');
        $parent->execute([$id]);
        $row = $parent->fetch();
        if ($row && $row['user_id']) {
            $this->pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id')
                ->execute(['name' => $name, 'email' => $email, 'id' => $row['user_id']]);
        }

        $this->ok([], 'Parent updated successfully');
    }

    public function applications(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            $this->fail('Invalid parent ID', 422);
            return;
        }

        [$page, $limit, $offset] = $this->pagination();

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM applications WHERE parent_id = ?');
        $countStmt->execute([$id]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, application_no, student_name, class_sought, status, payment_status, applied_at
             FROM applications WHERE parent_id = ?
             ORDER BY applied_at DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute([$id]);
        $applications = $stmt->fetchAll();

        $this->ok([
            'applications' => $applications,
            'pagination' => $this->paginationMeta($page, $limit, $total),
        ]);
    }
}
