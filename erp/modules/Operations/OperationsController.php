<?php

declare(strict_types=1);

namespace modules\Operations;

use core\Controller;
use core\Request;

class OperationsController extends Controller
{
    public function listTimetables(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $className = trim((string) Request::query('class_name', ''));
        $dayName = trim((string) Request::query('day_name', ''));
        $where = [];
        $params = [];
        if ($className !== '') {
            $where[] = 'class_name = :class_name';
            $params['class_name'] = $className;
        }
        if ($dayName !== '') {
            $where[] = 'day_name = :day_name';
            $params['day_name'] = $dayName;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM timetables' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM timetables' . $whereSql . ' ORDER BY class_name, day_name, period_no LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'timetables');
    }

    public function saveTimetable(): void
    {
        $payload = Request::json();
        if (empty($payload['class_name']) || empty($payload['day_name']) || empty($payload['periods']) || !is_array($payload['periods'])) {
            $this->fail('class_name, day_name and periods[] are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO timetables (class_name, section_name, day_name, period_no, subject_name, teacher_name, start_time, end_time, created_at)
             VALUES (:class_name, :section_name, :day_name, :period_no, :subject_name, :teacher_name, :start_time, :end_time, NOW())'
        );

        foreach ($payload['periods'] as $period) {
            $stmt->execute([
                'class_name' => $payload['class_name'],
                'section_name' => $payload['section_name'] ?? null,
                'day_name' => $payload['day_name'],
                'period_no' => $period['period_no'] ?? null,
                'subject_name' => $period['subject_name'] ?? null,
                'teacher_name' => $period['teacher_name'] ?? null,
                'start_time' => $period['start_time'] ?? null,
                'end_time' => $period['end_time'] ?? null,
            ]);
        }

        $this->ok([], 'Timetable uploaded');
    }

    public function updateTimetable(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE timetables
             SET class_name = :class_name, section_name = :section_name, day_name = :day_name, period_no = :period_no,
                 subject_name = :subject_name, teacher_name = :teacher_name, start_time = :start_time, end_time = :end_time
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'class_name' => $payload['class_name'] ?? '',
            'section_name' => $payload['section_name'] ?? null,
            'day_name' => $payload['day_name'] ?? '',
            'period_no' => $payload['period_no'] ?? 1,
            'subject_name' => $payload['subject_name'] ?? null,
            'teacher_name' => $payload['teacher_name'] ?? null,
            'start_time' => $payload['start_time'] ?? null,
            'end_time' => $payload['end_time'] ?? null,
        ]);
        $this->ok([], 'Timetable updated');
    }

    public function deleteTimetable(array $params): void
    {
        $this->pdo->prepare('DELETE FROM timetables WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Timetable deleted');
    }

    public function listTransportRoutes(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $whereSql = '';
        $params = [];
        if ($q !== '') {
            $whereSql = ' WHERE (route_name LIKE :q OR vehicle_no LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM transport_routes' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM transport_routes' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'routes');
    }

    public function createTransportRoute(): void
    {
        $payload = Request::json();
        if (empty($payload['route_name']) || empty($payload['vehicle_no'])) {
            $this->fail('route_name and vehicle_no are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO transport_routes (route_name, vehicle_no, driver_name, attendant_name, created_at)
             VALUES (:route_name, :vehicle_no, :driver_name, :attendant_name, NOW())'
        );
        $stmt->execute([
            'route_name' => $payload['route_name'],
            'vehicle_no' => $payload['vehicle_no'],
            'driver_name' => $payload['driver_name'] ?? null,
            'attendant_name' => $payload['attendant_name'] ?? null,
        ]);

        $this->ok(['route_id' => (int) $this->pdo->lastInsertId()], 'Transport route created');
    }

    public function updateTransportRoute(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE transport_routes
             SET route_name = :route_name, vehicle_no = :vehicle_no, driver_name = :driver_name, attendant_name = :attendant_name
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'route_name' => $payload['route_name'] ?? '',
            'vehicle_no' => $payload['vehicle_no'] ?? '',
            'driver_name' => $payload['driver_name'] ?? null,
            'attendant_name' => $payload['attendant_name'] ?? null,
        ]);
        $this->ok([], 'Transport route updated');
    }

    public function deleteTransportRoute(array $params): void
    {
        $this->pdo->prepare('DELETE FROM transport_routes WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Transport route deleted');
    }

    public function listTransportAllocations(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $routeId = Request::query('route_id');
        $studentId = Request::query('student_id');
        $status = Request::query('status');
        $where = [];
        $params = [];
        if ($routeId !== null && $routeId !== '') {
            $where[] = 'ta.route_id = :route_id';
            $params['route_id'] = $routeId;
        }
        if ($studentId !== null && $studentId !== '') {
            $where[] = 'ta.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'ta.status = :status';
            $params['status'] = $status;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM transport_allocations ta' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT ta.*, tr.route_name, tr.vehicle_no, s.admission_no, s.first_name, s.last_name
             FROM transport_allocations ta
             JOIN transport_routes tr ON tr.id = ta.route_id
             JOIN students s ON s.id = ta.student_id' . $whereSql . '
             ORDER BY ta.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'transport_allocations');
    }

    public function allocateTransportStudent(): void
    {
        $payload = Request::json();
        if (empty($payload['student_id']) || empty($payload['route_id']) || empty($payload['pickup_point'])) {
            $this->fail('student_id, route_id and pickup_point are required', 422);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO transport_allocations (student_id, route_id, pickup_point, drop_point, status, created_at)
             VALUES (:student_id, :route_id, :pickup_point, :drop_point, :status, NOW())'
        );
        $stmt->execute([
            'student_id' => $payload['student_id'],
            'route_id' => $payload['route_id'],
            'pickup_point' => $payload['pickup_point'],
            'drop_point' => $payload['drop_point'] ?? null,
            'status' => $payload['status'] ?? 'active',
        ]);

        $this->ok([], 'Transport allocated');
    }

    public function updateTransportAllocation(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE transport_allocations
             SET student_id = :student_id, route_id = :route_id, pickup_point = :pickup_point,
                 drop_point = :drop_point, status = :status
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'student_id' => $payload['student_id'] ?? null,
            'route_id' => $payload['route_id'] ?? null,
            'pickup_point' => $payload['pickup_point'] ?? '',
            'drop_point' => $payload['drop_point'] ?? null,
            'status' => $payload['status'] ?? 'active',
        ]);
        $this->ok([], 'Transport allocation updated');
    }

    public function deleteTransportAllocation(array $params): void
    {
        $this->pdo->prepare('DELETE FROM transport_allocations WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Transport allocation deleted');
    }

    public function listHostels(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $type = trim((string) Request::query('type', ''));
        $whereSql = '';
        $params = [];
        if ($type !== '') {
            $whereSql = ' WHERE type = :type';
            $params['type'] = $type;
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM hostels' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare('SELECT * FROM hostels' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'hostels');
    }

    public function createHostel(): void
    {
        $payload = Request::json();
        if (empty($payload['name']) || empty($payload['type'])) {
            $this->fail('name and type are required', 422);
            return;
        }
        $this->pdo->prepare('INSERT INTO hostels (name, type, created_at) VALUES (:name, :type, NOW())')
            ->execute(['name' => $payload['name'], 'type' => $payload['type']]);
        $this->ok(['hostel_id' => (int) $this->pdo->lastInsertId()], 'Hostel created');
    }

    public function updateHostel(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare('UPDATE hostels SET name = :name, type = :type WHERE id = :id')
            ->execute([
                'id' => $params['id'],
                'name' => $payload['name'] ?? '',
                'type' => $payload['type'] ?? 'boys',
            ]);
        $this->ok([], 'Hostel updated');
    }

    public function deleteHostel(array $params): void
    {
        $this->pdo->prepare('DELETE FROM hostels WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Hostel deleted');
    }

    public function listHostelRooms(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $hostelId = Request::query('hostel_id');
        $status = Request::query('status');
        $where = [];
        $params = [];
        if ($hostelId !== null && $hostelId !== '') {
            $where[] = 'r.hostel_id = :hostel_id';
            $params['hostel_id'] = $hostelId;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM hostel_rooms r' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT r.*, h.name AS hostel_name
             FROM hostel_rooms r
             JOIN hostels h ON h.id = r.hostel_id' . $whereSql . '
             ORDER BY r.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'hostel_rooms');
    }

    public function createHostelRoom(): void
    {
        $payload = Request::json();
        if (empty($payload['hostel_id']) || empty($payload['room_no']) || empty($payload['total_beds'])) {
            $this->fail('hostel_id, room_no and total_beds are required', 422);
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO hostel_rooms (hostel_id, room_no, total_beds, occupied_beds, status, created_at)
             VALUES (:hostel_id, :room_no, :total_beds, :occupied_beds, :status, NOW())'
        )->execute([
            'hostel_id' => $payload['hostel_id'],
            'room_no' => $payload['room_no'],
            'total_beds' => $payload['total_beds'],
            'occupied_beds' => $payload['occupied_beds'] ?? 0,
            'status' => $payload['status'] ?? 'available',
        ]);
        $this->ok(['room_id' => (int) $this->pdo->lastInsertId()], 'Hostel room created');
    }

    public function updateHostelRoom(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE hostel_rooms
             SET hostel_id = :hostel_id, room_no = :room_no, total_beds = :total_beds,
                 occupied_beds = :occupied_beds, status = :status
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'hostel_id' => $payload['hostel_id'] ?? null,
            'room_no' => $payload['room_no'] ?? '',
            'total_beds' => $payload['total_beds'] ?? 1,
            'occupied_beds' => $payload['occupied_beds'] ?? 0,
            'status' => $payload['status'] ?? 'available',
        ]);
        $this->ok([], 'Hostel room updated');
    }

    public function deleteHostelRoom(array $params): void
    {
        $this->pdo->prepare('DELETE FROM hostel_rooms WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Hostel room deleted');
    }

    public function listHostelAllocations(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $studentId = Request::query('student_id');
        $status = Request::query('status');
        $where = [];
        $params = [];
        if ($studentId !== null && $studentId !== '') {
            $where[] = 'ha.student_id = :student_id';
            $params['student_id'] = $studentId;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'ha.status = :status';
            $params['status'] = $status;
        }
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM hostel_allocations ha' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT ha.*, r.room_no, h.name AS hostel_name, s.admission_no, s.first_name, s.last_name
             FROM hostel_allocations ha
             JOIN hostel_rooms r ON r.id = ha.room_id
             JOIN hostels h ON h.id = r.hostel_id
             JOIN students s ON s.id = ha.student_id' . $whereSql . '
             ORDER BY ha.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'hostel_allocations');
    }

    public function allocateHostel(): void
    {
        $payload = Request::json();
        if (empty($payload['student_id']) || empty($payload['room_id']) || empty($payload['from_date'])) {
            $this->fail('student_id, room_id and from_date are required', 422);
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO hostel_allocations (student_id, room_id, from_date, status, created_at)
                 VALUES (:student_id, :room_id, :from_date, :status, NOW())'
            )->execute([
                'student_id' => $payload['student_id'],
                'room_id' => $payload['room_id'],
                'from_date' => $payload['from_date'],
                'status' => $payload['status'] ?? 'active',
            ]);

            $this->pdo->prepare(
                'UPDATE hostel_rooms SET occupied_beds = occupied_beds + 1, status = CASE
                    WHEN occupied_beds + 1 >= total_beds THEN "full"
                    ELSE "available"
                 END WHERE id = :room_id'
            )->execute(['room_id' => $payload['room_id']]);

            $this->pdo->commit();
            $this->ok([], 'Hostel allocated');
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->fail('Hostel allocation failed: ' . $e->getMessage(), 500);
        }
    }

    public function updateHostelAllocation(array $params): void
    {
        $payload = Request::json();
        $this->pdo->prepare(
            'UPDATE hostel_allocations
             SET student_id = :student_id, room_id = :room_id, from_date = :from_date, to_date = :to_date, status = :status
             WHERE id = :id'
        )->execute([
            'id' => $params['id'],
            'student_id' => $payload['student_id'] ?? null,
            'room_id' => $payload['room_id'] ?? null,
            'from_date' => $payload['from_date'] ?? date('Y-m-d'),
            'to_date' => $payload['to_date'] ?? null,
            'status' => $payload['status'] ?? 'active',
        ]);
        $this->ok([], 'Hostel allocation updated');
    }

    public function deleteHostelAllocation(array $params): void
    {
        $this->pdo->prepare('DELETE FROM hostel_allocations WHERE id = :id')->execute(['id' => $params['id']]);
        $this->ok([], 'Hostel allocation deleted');
    }
}
