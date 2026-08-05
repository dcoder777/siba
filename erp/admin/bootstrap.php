<?php

declare(strict_types=1);

use core\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = Database::connection($config['db']);

function admin_user(): ?array
{
    return $_SESSION['admin_user'] ?? null;
}

function require_admin_login(): void
{
    if (!admin_user()) {
        header('Location: login.php');
        exit;
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool
{
    $token = $_POST['_token'] ?? '';
    $saved = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && is_string($saved) && hash_equals($saved, $token);
}

function available_modules(): array
{
    return [
        'students' => ['label' => 'Students', 'entities' => ['students', 'student_enrollments', 'attendance_records']],
        'academics' => ['label' => 'Academics', 'entities' => ['subjects', 'assignments', 'exam_results', 'assignment_submissions', 'parent_teacher_messages']],
        'finance' => ['label' => 'Finance', 'entities' => ['fee_heads', 'fee_structures', 'fee_collections', 'expenses', 'income_categories', 'vendors', 'bank_accounts', 'ledger_accounts']],
        'operations' => ['label' => 'Operations', 'entities' => ['timetables', 'transport_routes', 'transport_allocations', 'hostels', 'hostel_rooms', 'hostel_allocations']],
        'hr' => ['label' => 'HR', 'entities' => ['employees', 'leave_requests', 'payroll_runs', 'payroll_items']],
        'reports' => ['label' => 'Reports', 'entities' => []],
    ];
}

function default_modules_for_role(string $role): array
{
    $menu = available_modules();

    if ($role === 'owner' || $role === 'admin') {
        return array_keys($menu);
    }
    if ($role === 'teacher') {
        return ['students', 'academics', 'reports'];
    }
    if ($role === 'finance') {
        return ['finance', 'students', 'hr'];
    }
    if ($role === 'parent') {
        return ['students', 'academics', 'finance', 'reports'];
    }
    if ($role === 'driver') {
        return ['operations', 'reports'];
    }
    if ($role === 'hr') {
        return ['hr'];
    }
    return array_keys($menu);
}

function fetch_user_role_assignments(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT r.name
             FROM user_role_assignments ura
             JOIN roles r ON r.id = ura.role_id
             WHERE ura.user_id = :user_id AND ura.is_active = 1'
        );
        $stmt->execute(['user_id' => $userId]);
        return array_values(array_unique(array_map(static fn(array $r): string => (string) $r['name'], $stmt->fetchAll())));
    } catch (Throwable) {
        return [];
    }
}

function set_user_role_assignments(PDO $pdo, int $userId, array $roleIds): void
{
    $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE user_role_assignments SET is_active = 0, updated_at = NOW() WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);

        $stmt = $pdo->prepare(
            'INSERT INTO user_role_assignments (user_id, role_id, is_active, created_at, updated_at)
             VALUES (:user_id, :role_id, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = NOW()'
        );
        foreach ($roleIds as $roleId) {
            $stmt->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fetch_user_roles(PDO $pdo, int $userId, string $primaryRole): array
{
    $roles = [$primaryRole];
    $assigned = fetch_user_role_assignments($pdo, $userId);
    foreach ($assigned as $role) {
        $roles[] = $role;
    }
    return array_values(array_unique(array_filter($roles)));
}

function default_modules_for_roles(array $roles): array
{
    $modules = [];
    foreach ($roles as $role) {
        $modules = array_merge($modules, default_modules_for_role((string) $role));
    }
    return array_values(array_unique($modules));
}

function fetch_user_module_access(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT module_key FROM user_module_access WHERE user_id = :user_id AND can_access = 1');
        $stmt->execute(['user_id' => $userId]);
        return array_map(static fn(array $r): string => (string) $r['module_key'], $stmt->fetchAll());
    } catch (Throwable) {
        return [];
    }
}

function set_user_module_access(PDO $pdo, int $userId, array $modules): void
{
    $allowed = array_keys(available_modules());
    $modules = array_values(array_intersect($modules, $allowed));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_module_access WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $ins = $pdo->prepare(
            'INSERT INTO user_module_access (user_id, module_key, can_access, created_at, updated_at)
             VALUES (:user_id, :module_key, 1, NOW(), NOW())'
        );
        foreach ($modules as $module) {
            $ins->execute([
                'user_id' => $userId,
                'module_key' => $module,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function menu_for_role(string $role, array $allowedModules = []): array
{
    return menu_for_roles([$role], $allowedModules);
}

function menu_for_roles(array $roles, array $allowedModules = []): array
{
    $menu = available_modules();
    $defaultKeys = default_modules_for_roles($roles);
    $primaryRole = (string) ($roles[0] ?? '');

    if (!in_array('admin', $roles, true) && !in_array('owner', $roles, true)) {
        $defaultKeys = array_values(array_intersect($defaultKeys, array_keys($menu)));
        if (!empty($allowedModules)) {
            $defaultKeys = array_values(array_intersect($defaultKeys, $allowedModules));
        }
    } elseif (!empty($allowedModules)) {
        $defaultKeys = array_values(array_intersect(array_keys($menu), $allowedModules));
    }

    $filtered = [];
    foreach ($defaultKeys as $key) {
        if (!isset($menu[$key])) {
            continue;
        }
        $filtered[$key] = $menu[$key];
    }

    if ($primaryRole === 'teacher' && isset($filtered['hr'])) {
        $filtered['hr']['entities'] = ['leave_requests'];
    }
    if ($primaryRole === 'finance') {
        if (isset($filtered['students'])) {
            $filtered['students']['entities'] = ['students'];
        }
        if (isset($filtered['hr'])) {
            $filtered['hr']['entities'] = ['payroll_runs', 'payroll_items'];
        }
    }

    return $filtered;
}

function entity_config(): array
{
    return [
        'students' => [
            'pk' => 'id',
            'label' => 'Students',
            'fields' => ['admission_no', 'first_name', 'last_name', 'gender', 'dob', 'phone', 'email'],
            'search' => ['admission_no', 'first_name', 'last_name', 'email'],
        ],
        'student_enrollments' => [
            'pk' => 'id',
            'label' => 'Student Lifecycle',
            'fields' => ['student_id', 'class_name', 'section_name', 'session_label', 'status', 'is_current'],
            'search' => ['class_name', 'session_label', 'status'],
        ],
        'attendance_records' => [
            'pk' => 'id',
            'label' => 'Attendance',
            'fields' => ['student_id', 'attendance_date', 'status', 'remark'],
            'search' => ['status', 'attendance_date'],
        ],
        'subjects' => [
            'pk' => 'id',
            'label' => 'Subjects',
            'fields' => ['subject_code', 'subject_name', 'class_name'],
            'search' => ['subject_code', 'subject_name', 'class_name'],
        ],
        'assignments' => [
            'pk' => 'id',
            'label' => 'Assignments',
            'fields' => ['title', 'description', 'class_name', 'section_name', 'due_date'],
            'search' => ['title', 'class_name', 'section_name'],
        ],
        'exam_results' => [
            'pk' => 'id',
            'label' => 'Exam Results',
            'fields' => ['student_id', 'subject_id', 'exam_name', 'max_marks', 'obtained_marks', 'grade', 'result_date'],
            'search' => ['exam_name', 'grade'],
        ],
        'assignment_submissions' => [
            'pk' => 'id',
            'label' => 'Assignment Submissions',
            'fields' => ['assignment_id', 'student_id', 'status', 'marks_awarded', 'submission_note'],
            'search' => ['status'],
        ],
        'parent_teacher_messages' => [
            'pk' => 'id',
            'label' => 'Parent-Teacher Messages',
            'fields' => ['student_id', 'sender_user_id', 'receiver_user_id', 'message'],
            'search' => ['message'],
        ],
        'fee_heads' => [
            'pk' => 'id',
            'label' => 'Fee Heads',
            'fields' => ['name', 'category', 'default_amount', 'frequency', 'is_refundable', 'late_fee_applicable', 'is_active'],
            'search' => ['name', 'category'],
        ],
        'fee_structures' => [
            'pk' => 'id',
            'label' => 'Fee Structures',
            'fields' => ['name', 'academic_session', 'class_name', 'student_category', 'total_amount', 'emi_allowed', 'num_installments', 'is_active'],
            'search' => ['name', 'class_name', 'academic_session'],
        ],
        'fee_collections' => [
            'pk' => 'id',
            'label' => 'Fee Collections',
            'fields' => ['receipt_no', 'student_name', 'class_name', 'net_amount', 'payment_mode', 'payment_date', 'status'],
            'search' => ['receipt_no', 'student_name', 'admission_no'],
        ],
        'expenses' => [
            'pk' => 'id',
            'label' => 'Expenses',
            'fields' => ['expense_no', 'expense_date', 'category_name', 'vendor_name', 'net_amount', 'status'],
            'search' => ['expense_no', 'vendor_name', 'category_name'],
        ],
        'income_categories' => [
            'pk' => 'id',
            'label' => 'Income Categories',
            'fields' => ['name', 'description', 'is_active'],
            'search' => ['name'],
        ],
        'vendors' => [
            'pk' => 'id',
            'label' => 'Vendors',
            'fields' => ['vendor_code', 'name', 'mobile', 'email', 'gst_number', 'is_active'],
            'search' => ['vendor_code', 'name', 'mobile'],
        ],
        'bank_accounts' => [
            'pk' => 'id',
            'label' => 'Bank Accounts',
            'fields' => ['bank_name', 'account_name', 'account_number', 'ifsc_code', 'current_balance', 'is_active'],
            'search' => ['bank_name', 'account_name', 'account_number'],
        ],
        'ledger_accounts' => [
            'pk' => 'id',
            'label' => 'Ledger Accounts',
            'fields' => ['account_code', 'account_name', 'account_type', 'current_balance'],
            'search' => ['account_code', 'account_name'],
        ],
        'timetables' => [
            'pk' => 'id',
            'label' => 'Timetables',
            'fields' => ['class_name', 'section_name', 'day_name', 'period_no', 'subject_name', 'teacher_name', 'start_time', 'end_time'],
            'search' => ['class_name', 'day_name', 'subject_name'],
        ],
        'transport_routes' => [
            'pk' => 'id',
            'label' => 'Transport Routes',
            'fields' => ['route_name', 'vehicle_no', 'driver_name', 'attendant_name'],
            'search' => ['route_name', 'vehicle_no'],
        ],
        'transport_allocations' => [
            'pk' => 'id',
            'label' => 'Transport Allocations',
            'fields' => ['student_id', 'route_id', 'pickup_point', 'drop_point', 'status'],
            'search' => ['pickup_point', 'drop_point', 'status'],
        ],
        'hostels' => [
            'pk' => 'id',
            'label' => 'Hostels',
            'fields' => ['name', 'type'],
            'search' => ['name', 'type'],
        ],
        'hostel_rooms' => [
            'pk' => 'id',
            'label' => 'Hostel Rooms',
            'fields' => ['hostel_id', 'room_no', 'total_beds', 'occupied_beds', 'status'],
            'search' => ['room_no', 'status'],
        ],
        'hostel_allocations' => [
            'pk' => 'id',
            'label' => 'Hostel Allocations',
            'fields' => ['student_id', 'room_id', 'from_date', 'to_date', 'status'],
            'search' => ['status'],
        ],
        'employees' => [
            'pk' => 'id',
            'label' => 'Employees',
            'fields' => ['employee_code', 'name', 'department', 'designation', 'joining_date', 'ctc', 'payout_account', 'status'],
            'search' => ['employee_code', 'name', 'department', 'designation', 'status'],
        ],
        'leave_requests' => [
            'pk' => 'id',
            'label' => 'Leave Requests',
            'fields' => ['employee_id', 'leave_type', 'from_date', 'to_date', 'reason', 'status'],
            'search' => ['leave_type', 'status'],
        ],
        'payroll_runs' => [
            'pk' => 'id',
            'label' => 'Payroll Runs',
            'fields' => ['month_label', 'generated_at', 'generated_by'],
            'search' => ['month_label'],
        ],
        'payroll_items' => [
            'pk' => 'id',
            'label' => 'Payroll Items',
            'fields' => ['payroll_run_id', 'employee_id', 'ctc_amount', 'gross_amount', 'deductions_amount', 'net_payout'],
            'search' => [],
        ],
    ];
}
