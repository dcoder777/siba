<?php

declare(strict_types=1);

namespace modules\Houses;

use core\Controller;
use core\Request;
use PDO;

class HouseController extends Controller
{
    public function listHouses(): void
    {
        $stmt = $this->pdo->query(
            'SELECT h.*, 
                    (SELECT COUNT(*) FROM student_house_allocations sha WHERE sha.house_id = h.id AND sha.status = "Active") AS active_students
             FROM houses h
             ORDER BY h.points DESC, h.house_name ASC'
        );
        $houses = $stmt->fetchAll();

        $this->ok(['houses' => $houses]);
    }

    public function createHouse(): void
    {
        $data = Request::json();
        $name = trim((string) ($data['house_name'] ?? ''));
        if ($name === '') {
            $this->fail('house_name is required', 422);
            return;
        }

        $code = trim((string) ($data['house_code'] ?? ''));
        if ($code === '') {
            $code = strtoupper(substr($name, 0, 3));
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO houses (
                house_code, house_name, color_code, motto, description, points, status, created_at, updated_at
            ) VALUES (
                :code, :name, :color, :motto, :desc, :points, "Active", NOW(), NOW()
            )'
        );
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'color' => $data['color_code'] ?? '#0d6efd',
            'motto' => $data['motto'] ?? '',
            'desc' => $data['description'] ?? '',
            'points' => (int) ($data['points'] ?? 0),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->ok(['id' => $id, 'house_name' => $name], 'House created successfully');
    }

    public function getHouse(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM houses WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $house = $stmt->fetch();
        if (!$house) {
            $this->fail('House not found', 404);
            return;
        }

        // Recent activity
        $actStmt = $this->pdo->prepare(
            'SELECT * FROM house_activity_logs WHERE house_id = :id ORDER BY id DESC LIMIT 15'
        );
        $actStmt->execute(['id' => $id]);
        $house['recent_activities'] = $actStmt->fetchAll();

        // Allocated students
        $stStmt = $this->pdo->prepare(
            'SELECT sha.*, s.first_name, s.last_name, s.admission_no
             FROM student_house_allocations sha
             JOIN students s ON s.id = sha.student_id
             WHERE sha.house_id = :id AND sha.status = "Active"
             ORDER BY s.admission_no ASC LIMIT 50'
        );
        $stStmt->execute(['id' => $id]);
        $house['students'] = $stStmt->fetchAll();

        $this->ok(['house' => $house]);
    }

    public function updateHouse(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Request::json();

        $stmt = $this->pdo->prepare(
            'UPDATE houses SET
                house_name = COALESCE(:name, house_name),
                house_code = COALESCE(:code, house_code),
                color_code = COALESCE(:color, color_code),
                motto = COALESCE(:motto, motto),
                description = COALESCE(:desc, description),
                points = COALESCE(:points, points),
                status = COALESCE(:status, status),
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => isset($data['house_name']) ? trim((string) $data['house_name']) : null,
            'code' => isset($data['house_code']) ? trim((string) $data['house_code']) : null,
            'color' => isset($data['color_code']) ? trim((string) $data['color_code']) : null,
            'motto' => isset($data['motto']) ? trim((string) $data['motto']) : null,
            'desc' => isset($data['description']) ? trim((string) $data['description']) : null,
            'points' => isset($data['points']) ? (int) $data['points'] : null,
            'status' => isset($data['status']) ? trim((string) $data['status']) : null,
        ]);

        $this->ok(['id' => $id], 'House updated successfully');
    }

    public function deleteHouse(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('DELETE FROM houses WHERE id = :id')->execute(['id' => $id]);
        $this->ok(['id' => $id], 'House deleted successfully');
    }

    public function listAllocations(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $houseId = Request::intQuery('house_id', 0);
        $studentId = Request::intQuery('student_id', 0);

        $where = [];
        $params = [];
        if ($houseId > 0) {
            $where[] = 'sha.house_id = :hid';
            $params['hid'] = $houseId;
        }
        if ($studentId > 0) {
            $where[] = 'sha.student_id = :sid';
            $params['sid'] = $studentId;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM student_house_allocations sha' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT sha.*, h.house_name, h.color_code, s.first_name, s.last_name, s.admission_no
                FROM student_house_allocations sha
                JOIN houses h ON h.id = sha.house_id
                JOIN students s ON s.id = sha.student_id' . $whereSql . '
                ORDER BY sha.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'allocations');
    }

    public function allocateStudent(): void
    {
        $data = Request::json();
        $studentId = (int) ($data['student_id'] ?? 0);
        $houseId = (int) ($data['house_id'] ?? 0);

        if ($studentId <= 0 || $houseId <= 0) {
            $this->fail('student_id and house_id are required', 422);
            return;
        }

        // Deactivate previous active allocation
        $this->pdo->prepare(
            'UPDATE student_house_allocations SET status = "Inactive" WHERE student_id = :sid'
        )->execute(['sid' => $studentId]);

        // Insert new allocation
        $stmt = $this->pdo->prepare(
            'INSERT INTO student_house_allocations (
                student_id, house_id, academic_year, role, status, created_at
            ) VALUES (
                :sid, :hid, :ay, :role, "Active", NOW()
            )'
        );
        $stmt->execute([
            'sid' => $studentId,
            'hid' => $houseId,
            'ay' => $data['academic_year'] ?? '2026-2027',
            'role' => $data['role'] ?? 'Member',
        ]);

        $this->ok(['student_id' => $studentId, 'house_id' => $houseId], 'Student assigned to house successfully');
    }

    public function listPointsLog(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $houseId = Request::intQuery('house_id', 0);

        $where = [];
        $params = [];
        if ($houseId > 0) {
            $where[] = 'l.house_id = :hid';
            $params['hid'] = $houseId;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM house_activity_logs l' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT l.*, h.house_name, h.color_code
                FROM house_activity_logs l
                JOIN houses h ON h.id = l.house_id' . $whereSql . '
                ORDER BY l.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'points_log');
    }

    public function awardPoints(): void
    {
        $data = Request::json();
        $houseId = (int) ($data['house_id'] ?? 0);
        $pts = (int) ($data['points_awarded'] ?? $data['points'] ?? 0);
        $event = trim((string) ($data['event_name'] ?? 'House Activity'));

        if ($houseId <= 0 || $pts === 0) {
            $this->fail('house_id and non-zero points are required', 422);
            return;
        }

        // Insert log
        $stmt = $this->pdo->prepare(
            'INSERT INTO house_activity_logs (
                house_id, event_name, event_date, points_awarded, reason, awarded_by, created_at
            ) VALUES (
                :hid, :event, :date, :pts, :reason, :by, NOW()
            )'
        );
        $stmt->execute([
            'hid' => $houseId,
            'event' => $event,
            'date' => $data['event_date'] ?? date('Y-m-d'),
            'pts' => $pts,
            'reason' => $data['reason'] ?? '',
            'by' => $data['awarded_by'] ?? 'Admin',
        ]);

        // Update house points total
        $this->pdo->prepare(
            'UPDATE houses SET points = points + :pts, updated_at = NOW() WHERE id = :hid'
        )->execute(['pts' => $pts, 'hid' => $houseId]);

        $this->ok(['house_id' => $houseId, 'points_awarded' => $pts], 'House points recorded successfully');
    }
}
