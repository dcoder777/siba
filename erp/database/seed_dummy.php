<?php

declare(strict_types=1);

use core\Database;

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = Database::connection($config['db']);

function pick(array $items): mixed
{
    return $items[array_rand($items)];
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }
    return array_values($row)[0] ?? 0;
}

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = [
        'approval_workflows',
        'payroll_items',
        'payroll_runs',
        'leave_requests',
        'employees',
        'hostel_allocations',
        'hostel_rooms',
        'hostels',
        'transport_allocations',
        'transport_routes',
        'timetables',
        'payment_reconciliations',
        'receipts',
        'payments',
        'student_fee_dues',
        'fee_structures',
        'parent_teacher_messages',
        'assignment_submissions',
        'assignments',
        'exam_results',
        'subjects',
        'attendance_records',
        'student_enrollments',
        'guardians',
        'students',
        'api_tokens',
        'user_role_assignments',
        'user_module_access',
        'users',
        'roles',
    ];
    foreach ($tables as $table) {
        $pdo->exec("TRUNCATE TABLE {$table}");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->beginTransaction();

    $roles = ['owner', 'admin', 'parent', 'teacher', 'driver', 'finance', 'hr'];
    $roleStmt = $pdo->prepare('INSERT INTO roles (name) VALUES (:name)');
    foreach ($roles as $r) {
        $roleStmt->execute(['name' => $r]);
    }
    $roleMap = [];
    foreach ($pdo->query('SELECT id, name FROM roles')->fetchAll() as $r) {
        $roleMap[$r['name']] = (int) $r['id'];
    }

    $userStmt = $pdo->prepare(
        'INSERT INTO users (role_id, name, email, password_hash, is_active, created_at, updated_at)
         VALUES (:role_id, :name, :email, :password_hash, 1, NOW(), NOW())'
    );
    $pwd = password_hash('password', PASSWORD_DEFAULT);
    $users = [
        ['role' => 'admin', 'name' => 'System Admin', 'email' => 'admin@siba.local'],
        ['role' => 'teacher', 'name' => 'Aditi Sharma', 'email' => 'teacher1@siba.local'],
        ['role' => 'teacher', 'name' => 'Rahul Das', 'email' => 'teacher2@siba.local'],
        ['role' => 'finance', 'name' => 'Finance Officer', 'email' => 'finance@siba.local'],
        ['role' => 'hr', 'name' => 'HR Manager', 'email' => 'hr@siba.local'],
        ['role' => 'parent', 'name' => 'Parent User', 'email' => 'parent@siba.local'],
        ['role' => 'student', 'name' => 'Student User', 'email' => 'student@siba.local'],
    ];
    foreach ($users as $u) {
        $userStmt->execute([
            'role_id' => $roleMap[$u['role']],
            'name' => $u['name'],
            'email' => $u['email'],
            'password_hash' => $pwd,
        ]);
    }

    $defaultModules = [
        'admin' => ['students', 'academics', 'finance', 'operations', 'hr', 'reports'],
        'teacher' => ['students', 'academics', 'hr'],
        'finance' => ['students', 'finance', 'hr', 'reports'],
        'hr' => ['hr', 'reports'],
        'parent' => ['academics'],
        'student' => ['academics'],
    ];
    $umaStmt = $pdo->prepare(
        'INSERT INTO user_module_access (user_id, module_key, can_access, created_at, updated_at)
         VALUES (:user_id, :module_key, 1, NOW(), NOW())'
    );
    $allUsers = $pdo->query(
        'SELECT u.id, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id'
    )->fetchAll();
    foreach ($allUsers as $u) {
        $modules = $defaultModules[$u['role_name']] ?? [];
        foreach ($modules as $module) {
            $umaStmt->execute([
                'user_id' => $u['id'],
                'module_key' => $module,
            ]);
        }
    }

    $studentIds = [];
    $firstNames = ['Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Anaya', 'Diya', 'Ishita', 'Riya', 'Kavya', 'Myra'];
    $lastNames = ['Sharma', 'Verma', 'Patel', 'Das', 'Singh', 'Nair', 'Reddy', 'Ghosh', 'Gupta', 'Kapoor'];
    $classes = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
    $sections = ['A', 'B', 'C'];

    $studentStmt = $pdo->prepare(
        'INSERT INTO students (admission_no, first_name, last_name, gender, dob, blood_group, phone, email, address, created_at, updated_at)
         VALUES (:admission_no, :first_name, :last_name, :gender, :dob, :blood_group, :phone, :email, :address, NOW(), NOW())'
    );
    for ($i = 1; $i <= 80; $i++) {
        $first = pick($firstNames);
        $last = pick($lastNames);
        $gender = ($i % 2 === 0) ? 'female' : 'male';
        $dobYear = 2010 - (int) floor(($i % 10) / 2);
        $studentStmt->execute([
            'admission_no' => 'ADM' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'dob' => "{$dobYear}-" . str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) random_int(1, 28), 2, '0', STR_PAD_LEFT),
            'blood_group' => pick(['A+', 'A-', 'B+', 'O+', 'AB+']),
            'phone' => '98' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => strtolower($first . $i . '@mail.local'),
            'address' => 'Locality ' . random_int(1, 20) . ', City',
        ]);
        $studentIds[] = (int) $pdo->lastInsertId();
    }

    $guardianStmt = $pdo->prepare(
        'INSERT INTO guardians (student_id, name, relation_type, phone, email, created_at)
         VALUES (:student_id, :name, :relation_type, :phone, :email, NOW())'
    );
    foreach ($studentIds as $sid) {
        $guardianStmt->execute([
            'student_id' => $sid,
            'name' => pick(['Rajesh', 'Suresh', 'Pooja', 'Meena', 'Sunita']) . ' ' . pick($lastNames),
            'relation_type' => pick(['Father', 'Mother', 'Guardian']),
            'phone' => '97' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'guardian' . $sid . '@mail.local',
        ]);
    }

    $enrollmentStmt = $pdo->prepare(
        'INSERT INTO student_enrollments (student_id, class_name, section_name, session_label, status, is_current, created_at)
         VALUES (:student_id, :class_name, :section_name, :session_label, :status, :is_current, NOW())'
    );
    $studentClass = [];
    foreach ($studentIds as $sid) {
        $class = pick($classes);
        $studentClass[$sid] = $class;
        $enrollmentStmt->execute([
            'student_id' => $sid,
            'class_name' => $class,
            'section_name' => pick($sections),
            'session_label' => '2025-26',
            'status' => pick(['active', 'active', 'active', 'promoted']),
            'is_current' => 1,
        ]);
    }

    $subjectStmt = $pdo->prepare(
        'INSERT INTO subjects (subject_code, subject_name, class_name, created_at)
         VALUES (:subject_code, :subject_name, :class_name, NOW())'
    );
    $subjectIdsByClass = [];
    $subjectNames = ['English', 'Mathematics', 'Science', 'Social Studies', 'Computer'];
    foreach ($classes as $cls) {
        foreach ($subjectNames as $idx => $sname) {
            $code = 'C' . $cls . 'S' . ($idx + 1);
            $subjectStmt->execute([
                'subject_code' => $code,
                'subject_name' => $sname,
                'class_name' => $cls,
            ]);
            $subjectIdsByClass[$cls][] = (int) $pdo->lastInsertId();
        }
    }

    $attendanceStmt = $pdo->prepare(
        'INSERT INTO attendance_records (student_id, attendance_date, status, remark, marked_by, created_at)
         VALUES (:student_id, :attendance_date, :status, :remark, :marked_by, NOW())'
    );
    for ($d = 0; $d < 30; $d++) {
        $date = date('Y-m-d', strtotime("-{$d} days"));
        foreach ($studentIds as $sid) {
            $status = pick(['present', 'present', 'present', 'late', 'absent']);
            $attendanceStmt->execute([
                'student_id' => $sid,
                'attendance_date' => $date,
                'status' => $status,
                'remark' => $status === 'absent' ? 'Sick leave' : null,
                'marked_by' => 1,
            ]);
        }
    }

    $assignmentStmt = $pdo->prepare(
        'INSERT INTO assignments (title, description, class_name, section_name, due_date, assigned_by, created_at)
         VALUES (:title, :description, :class_name, :section_name, :due_date, :assigned_by, NOW())'
    );
    $assignmentIds = [];
    for ($i = 1; $i <= 30; $i++) {
        $class = pick($classes);
        $assignmentStmt->execute([
            'title' => "Homework {$i}",
            'description' => "Practice worksheet {$i} for class {$class}",
            'class_name' => $class,
            'section_name' => pick($sections),
            'due_date' => date('Y-m-d', strtotime('+' . random_int(1, 15) . ' days')),
            'assigned_by' => 2,
        ]);
        $assignmentIds[] = (int) $pdo->lastInsertId();
    }

    $examStmt = $pdo->prepare(
        'INSERT INTO exam_results (student_id, subject_id, exam_name, max_marks, obtained_marks, grade, result_date, created_at)
         VALUES (:student_id, :subject_id, :exam_name, 100, :obtained_marks, :grade, :result_date, NOW())'
    );
    foreach ($studentIds as $sid) {
        $class = $studentClass[$sid];
        $subjects = $subjectIdsByClass[$class];
        shuffle($subjects);
        $subjects = array_slice($subjects, 0, 3);
        foreach ($subjects as $subId) {
            $marks = random_int(35, 98);
            $grade = $marks >= 90 ? 'A+' : ($marks >= 80 ? 'A' : ($marks >= 70 ? 'B' : ($marks >= 60 ? 'C' : ($marks >= 50 ? 'D' : 'F'))));
            $examStmt->execute([
                'student_id' => $sid,
                'subject_id' => $subId,
                'exam_name' => pick(['Unit Test 1', 'Half Yearly', 'Final Term']),
                'obtained_marks' => $marks,
                'grade' => $grade,
                'result_date' => date('Y-m-d', strtotime('-' . random_int(1, 90) . ' days')),
            ]);
        }
    }

    $submissionStmt = $pdo->prepare(
        'INSERT INTO assignment_submissions (assignment_id, student_id, submitted_at, submission_note, status, marks_awarded, created_at)
         VALUES (:assignment_id, :student_id, :submitted_at, :submission_note, :status, :marks_awarded, NOW())'
    );
    foreach ($assignmentIds as $aid) {
        $sampleStudents = $studentIds;
        shuffle($sampleStudents);
        $sampleStudents = array_slice($sampleStudents, 0, 25);
        foreach ($sampleStudents as $sid) {
            $submissionStmt->execute([
                'assignment_id' => $aid,
                'student_id' => $sid,
                'submitted_at' => date('Y-m-d H:i:s', strtotime('-' . random_int(0, 10) . ' days')),
                'submission_note' => 'Submitted by student',
                'status' => pick(['submitted', 'submitted', 'reviewed', 'pending']),
                'marks_awarded' => random_int(5, 10),
            ]);
        }
    }

    $messageStmt = $pdo->prepare(
        'INSERT INTO parent_teacher_messages (student_id, sender_user_id, receiver_user_id, message, created_at)
         VALUES (:student_id, :sender_user_id, :receiver_user_id, :message, NOW())'
    );
    for ($i = 0; $i < 120; $i++) {
        $messageStmt->execute([
            'student_id' => pick($studentIds),
            'sender_user_id' => pick([2, 3, 6]),
            'receiver_user_id' => pick([2, 3, 6]),
            'message' => pick([
                'Student progressing well in class.',
                'Please review homework completion.',
                'Parent-teacher meeting scheduled this week.',
                'Attendance needs improvement.',
            ]),
        ]);
    }

    $feeStructureStmt = $pdo->prepare(
        'INSERT INTO fee_structures (class_name, academic_session, fee_head, amount, frequency, created_at)
         VALUES (:class_name, :academic_session, :fee_head, :amount, :frequency, NOW())'
    );
    $feeStructureByClass = [];
    foreach ($classes as $class) {
        foreach ([['Tuition Fee', 2500], ['Transport Fee', 800]] as $head) {
            $feeStructureStmt->execute([
                'class_name' => $class,
                'academic_session' => '2025-26',
                'fee_head' => $head[0],
                'amount' => $head[1] + ((int) $class * 20),
                'frequency' => 'monthly',
            ]);
            $feeStructureByClass[$class][] = (int) $pdo->lastInsertId();
        }
    }

    $dueStmt = $pdo->prepare(
        'INSERT INTO student_fee_dues (student_id, fee_structure_id, due_date, amount, paid_amount, status, created_at)
         VALUES (:student_id, :fee_structure_id, :due_date, :amount, :paid_amount, :status, NOW())'
    );
    $dueIds = [];
    foreach ($studentIds as $sid) {
        $class = $studentClass[$sid];
        $structures = $feeStructureByClass[$class];
        for ($m = 0; $m < 4; $m++) {
            foreach ($structures as $fsid) {
                $amount = (float) scalar($pdo, 'SELECT amount FROM fee_structures WHERE id = :id', ['id' => $fsid]);
                $paid = pick([0, $amount, $amount * 0.5, $amount]);
                $status = $paid >= $amount ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
                $dueDate = date('Y-m-10', strtotime("-{$m} months"));
                $dueStmt->execute([
                    'student_id' => $sid,
                    'fee_structure_id' => $fsid,
                    'due_date' => $dueDate,
                    'amount' => $amount,
                    'paid_amount' => $paid,
                    'status' => $status,
                ]);
                $dueIds[] = (int) $pdo->lastInsertId();
            }
        }
    }

    $paymentStmt = $pdo->prepare(
        'INSERT INTO payments (student_id, fee_due_id, amount, payment_date, payment_mode, source, reference_no, created_at)
         VALUES (:student_id, :fee_due_id, :amount, :payment_date, :payment_mode, :source, :reference_no, NOW())'
    );
    $receiptStmt = $pdo->prepare(
        'INSERT INTO receipts (payment_id, receipt_no, generated_at) VALUES (:payment_id, :receipt_no, NOW())'
    );
    foreach (array_slice($dueIds, 0, 350) as $dueId) {
        $due = $pdo->prepare('SELECT student_id, paid_amount FROM student_fee_dues WHERE id = :id');
        $due->execute(['id' => $dueId]);
        $d = $due->fetch();
        if (!$d || (float) $d['paid_amount'] <= 0) {
            continue;
        }
        $paymentStmt->execute([
            'student_id' => $d['student_id'],
            'fee_due_id' => $dueId,
            'amount' => $d['paid_amount'],
            'payment_date' => date('Y-m-d', strtotime('-' . random_int(1, 100) . ' days')),
            'payment_mode' => pick(['cash', 'upi', 'card', 'bank_transfer']),
            'source' => pick(['offline', 'website']),
            'reference_no' => 'REF' . random_int(100000, 999999),
        ]);
        $pid = (int) $pdo->lastInsertId();
        $receiptStmt->execute([
            'payment_id' => $pid,
            'receipt_no' => 'RCP-' . date('Ymd') . '-' . $pid,
        ]);
    }

    $reconStmt = $pdo->prepare(
        'INSERT INTO payment_reconciliations (gateway_reference, website_order_id, amount, status, reconciled_at, notes)
         VALUES (:gateway_reference, :website_order_id, :amount, :status, NOW(), :notes)'
    );
    for ($i = 1; $i <= 80; $i++) {
        $reconStmt->execute([
            'gateway_reference' => 'GW' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'website_order_id' => 'ORD' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'amount' => random_int(800, 5000),
            'status' => pick(['matched', 'matched', 'pending', 'mismatch']),
            'notes' => pick(['Auto matched', 'Manual review required', 'Pending callback']),
        ]);
    }

    $routeStmt = $pdo->prepare(
        'INSERT INTO transport_routes (route_name, vehicle_no, driver_name, attendant_name, created_at)
         VALUES (:route_name, :vehicle_no, :driver_name, :attendant_name, NOW())'
    );
    $routeIds = [];
    for ($i = 1; $i <= 8; $i++) {
        $routeStmt->execute([
            'route_name' => "Route {$i}",
            'vehicle_no' => 'WB-20-' . random_int(1000, 9999),
            'driver_name' => pick(['Ramesh', 'Sanjay', 'Kamal', 'Akhil']),
            'attendant_name' => pick(['Mohan', 'Ritu', 'Nisha', 'Pankaj']),
        ]);
        $routeIds[] = (int) $pdo->lastInsertId();
    }

    $transportAllocStmt = $pdo->prepare(
        'INSERT INTO transport_allocations (student_id, route_id, pickup_point, drop_point, status, created_at)
         VALUES (:student_id, :route_id, :pickup_point, :drop_point, :status, NOW())'
    );
    foreach (array_slice($studentIds, 0, 45) as $sid) {
        $transportAllocStmt->execute([
            'student_id' => $sid,
            'route_id' => pick($routeIds),
            'pickup_point' => pick(['Sector 1', 'Sector 2', 'Market Road', 'Main Gate']),
            'drop_point' => pick(['School Gate', 'Hostel Gate']),
            'status' => 'active',
        ]);
    }

    $hostelStmt = $pdo->prepare('INSERT INTO hostels (name, type, created_at) VALUES (:name, :type, NOW())');
    $hostelStmt->execute(['name' => 'Boys Hostel', 'type' => 'boys']);
    $boysHostelId = (int) $pdo->lastInsertId();
    $hostelStmt->execute(['name' => 'Girls Hostel', 'type' => 'girls']);
    $girlsHostelId = (int) $pdo->lastInsertId();

    $roomStmt = $pdo->prepare(
        'INSERT INTO hostel_rooms (hostel_id, room_no, total_beds, occupied_beds, status, created_at)
         VALUES (:hostel_id, :room_no, :total_beds, 0, "available", NOW())'
    );
    $roomIds = [];
    for ($i = 1; $i <= 12; $i++) {
        $roomStmt->execute([
            'hostel_id' => $i <= 6 ? $boysHostelId : $girlsHostelId,
            'room_no' => 'R' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'total_beds' => 4,
        ]);
        $roomIds[] = (int) $pdo->lastInsertId();
    }

    $hostelAllocStmt = $pdo->prepare(
        'INSERT INTO hostel_allocations (student_id, room_id, from_date, to_date, status, created_at)
         VALUES (:student_id, :room_id, :from_date, NULL, :status, NOW())'
    );
    foreach (array_slice($studentIds, 0, 30) as $sid) {
        $roomId = pick($roomIds);
        $hostelAllocStmt->execute([
            'student_id' => $sid,
            'room_id' => $roomId,
            'from_date' => date('Y-m-d', strtotime('-' . random_int(1, 150) . ' days')),
            'status' => 'active',
        ]);
        $pdo->prepare(
            'UPDATE hostel_rooms
             SET occupied_beds = occupied_beds + 1,
                 status = CASE WHEN occupied_beds + 1 >= total_beds THEN "full" ELSE "available" END
             WHERE id = :id'
        )->execute(['id' => $roomId]);
    }

    $timetableStmt = $pdo->prepare(
        'INSERT INTO timetables (class_name, section_name, day_name, period_no, subject_name, teacher_name, start_time, end_time, created_at)
         VALUES (:class_name, :section_name, :day_name, :period_no, :subject_name, :teacher_name, :start_time, :end_time, NOW())'
    );
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    foreach ($classes as $class) {
        foreach ($sections as $section) {
            foreach ($days as $day) {
                for ($p = 1; $p <= 6; $p++) {
                    $start = date('H:i:s', strtotime('08:00:00 +' . (($p - 1) * 50) . ' minutes'));
                    $end = date('H:i:s', strtotime($start . ' +45 minutes'));
                    $timetableStmt->execute([
                        'class_name' => $class,
                        'section_name' => $section,
                        'day_name' => $day,
                        'period_no' => $p,
                        'subject_name' => pick($subjectNames),
                        'teacher_name' => pick(['Aditi Sharma', 'Rahul Das', 'Neha Roy', 'Sanjana Pal']),
                        'start_time' => $start,
                        'end_time' => $end,
                    ]);
                }
            }
        }
    }

    $employeeStmt = $pdo->prepare(
        'INSERT INTO employees (employee_code, name, department, designation, joining_date, ctc, payout_account, status, created_at, updated_at)
         VALUES (:employee_code, :name, :department, :designation, :joining_date, :ctc, :payout_account, :status, NOW(), NOW())'
    );
    $departments = ['Teaching', 'Finance', 'HR', 'Admin', 'Transport', 'Hostel'];
    $designations = ['Teacher', 'Senior Teacher', 'Officer', 'Manager', 'Coordinator', 'Assistant'];
    $employeeIds = [];
    for ($i = 1; $i <= 40; $i++) {
        $employeeStmt->execute([
            'employee_code' => 'EMP' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'name' => pick(['Rohit', 'Pallavi', 'Nitin', 'Ankita', 'Megha', 'Vikas']) . ' ' . pick($lastNames),
            'department' => pick($departments),
            'designation' => pick($designations),
            'joining_date' => date('Y-m-d', strtotime('-' . random_int(50, 1200) . ' days')),
            'ctc' => random_int(260000, 920000),
            'payout_account' => 'AC' . random_int(10000000, 99999999),
            'status' => pick(['active', 'active', 'active', 'inactive']),
        ]);
        $employeeIds[] = (int) $pdo->lastInsertId();
    }

    $leaveStmt = $pdo->prepare(
        'INSERT INTO leave_requests (employee_id, leave_type, from_date, to_date, reason, status, requested_by, approved_by, approved_at, created_at)
         VALUES (:employee_id, :leave_type, :from_date, :to_date, :reason, :status, :requested_by, :approved_by, :approved_at, NOW())'
    );
    for ($i = 0; $i < 90; $i++) {
        $status = pick(['pending', 'approved', 'rejected', 'approved']);
        $leaveStmt->execute([
            'employee_id' => pick($employeeIds),
            'leave_type' => pick(['Sick', 'Casual', 'Earned']),
            'from_date' => date('Y-m-d', strtotime('-' . random_int(1, 90) . ' days')),
            'to_date' => date('Y-m-d', strtotime('+' . random_int(1, 5) . ' days')),
            'reason' => pick(['Medical', 'Family function', 'Personal work']),
            'status' => $status,
            'requested_by' => 1,
            'approved_by' => $status === 'pending' ? null : 1,
            'approved_at' => $status === 'pending' ? null : date('Y-m-d H:i:s'),
        ]);
    }

    $payrollRunStmt = $pdo->prepare('INSERT INTO payroll_runs (month_label, generated_at, generated_by) VALUES (:month_label, NOW(), :generated_by)');
    $payrollItemStmt = $pdo->prepare(
        'INSERT INTO payroll_items (payroll_run_id, employee_id, ctc_amount, gross_amount, deductions_amount, net_payout)
         VALUES (:payroll_run_id, :employee_id, :ctc_amount, :gross_amount, :deductions_amount, :net_payout)'
    );
    foreach (['2026-01', '2026-02', '2026-03'] as $monthLabel) {
        $payrollRunStmt->execute(['month_label' => $monthLabel, 'generated_by' => 1]);
        $runId = (int) $pdo->lastInsertId();
        foreach ($employeeIds as $eid) {
            $ctc = (float) scalar($pdo, 'SELECT ctc FROM employees WHERE id = :id', ['id' => $eid]);
            $gross = $ctc / 12;
            $ded = $gross * 0.12;
            $net = $gross - $ded;
            $payrollItemStmt->execute([
                'payroll_run_id' => $runId,
                'employee_id' => $eid,
                'ctc_amount' => $ctc,
                'gross_amount' => $gross,
                'deductions_amount' => $ded,
                'net_payout' => $net,
            ]);
        }
    }

    $approvalStmt = $pdo->prepare(
        'INSERT INTO approval_workflows (module_name, record_id, submitted_by, approved_by, status, remarks, created_at, updated_at)
         VALUES (:module_name, :record_id, :submitted_by, :approved_by, :status, :remarks, NOW(), NOW())'
    );
    for ($i = 1; $i <= 70; $i++) {
        $status = pick(['pending', 'approved', 'rejected']);
        $approvalStmt->execute([
            'module_name' => pick(['leave', 'payroll', 'finance', 'hostel']),
            'record_id' => random_int(1, 100),
            'submitted_by' => 1,
            'approved_by' => $status === 'pending' ? null : 1,
            'status' => $status,
            'remarks' => pick(['Auto seeded', 'Pending verification', 'Reviewed by admin']),
        ]);
    }

    $pdo->commit();
    echo "Dummy data seeded successfully.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'Seeding failed: ' . $e->getMessage() . "\n";
    exit(1);
}
