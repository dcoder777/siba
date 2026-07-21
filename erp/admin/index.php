<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isSuperAdmin = ($user['role'] ?? '') === 'admin';
$explicitModules = fetch_user_module_access($pdo, (int) $user['id']);
$userRoles = fetch_user_roles($pdo, (int) $user['id'], (string) ($user['role'] ?? 'admin'));
$menus = menu_for_roles($userRoles, $explicitModules);
$entityMap = entity_config();

$firstModule = array_key_first($menus) ?: 'students';
$module = (string) ($_GET['module'] ?? $firstModule);
if (!isset($menus[$module])) {
    $module = $firstModule;
}

$view = (string) ($_GET['view'] ?? 'dashboard');
$entity = (string) ($_GET['entity'] ?? '');
if ($entity !== '') {
    $view = 'entity';
}
if ($view === 'user-access' && !$isSuperAdmin) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? array_values($row)[0] ?? 0 : 0;
}

function field_options(PDO $pdo, string $field): array
{
    return match ($field) {
        'student_id' => $pdo->query('SELECT id, CONCAT(admission_no, " - ", first_name, " ", last_name) AS label FROM students ORDER BY id DESC LIMIT 500')->fetchAll(),
        'subject_id' => $pdo->query('SELECT id, CONCAT(subject_code, " - ", subject_name) AS label FROM subjects ORDER BY id DESC LIMIT 500')->fetchAll(),
        'assignment_id' => $pdo->query('SELECT id, title AS label FROM assignments ORDER BY id DESC LIMIT 500')->fetchAll(),
        'fee_structure_id' => $pdo->query('SELECT id, CONCAT(class_name, " - ", fee_head) AS label FROM fee_structures ORDER BY id DESC LIMIT 500')->fetchAll(),
        'fee_due_id' => $pdo->query('SELECT id, CONCAT("Due#", id, " - ", amount) AS label FROM student_fee_dues ORDER BY id DESC LIMIT 500')->fetchAll(),
        'route_id' => $pdo->query('SELECT id, CONCAT(route_name, " - ", vehicle_no) AS label FROM transport_routes ORDER BY id DESC LIMIT 500')->fetchAll(),
        'hostel_id' => $pdo->query('SELECT id, name AS label FROM hostels ORDER BY id DESC LIMIT 200')->fetchAll(),
        'room_id' => $pdo->query('SELECT id, room_no AS label FROM hostel_rooms ORDER BY id DESC LIMIT 500')->fetchAll(),
        'employee_id' => $pdo->query('SELECT id, CONCAT(employee_code, " - ", name) AS label FROM employees ORDER BY id DESC LIMIT 500')->fetchAll(),
        'payroll_run_id' => $pdo->query('SELECT id, CONCAT("Run#", id, " - ", month_label) AS label FROM payroll_runs ORDER BY id DESC LIMIT 200')->fetchAll(),
        'sender_user_id', 'receiver_user_id', 'generated_by', 'requested_by', 'approved_by' => $pdo->query('SELECT id, CONCAT(name, " <", email, ">") AS label FROM users ORDER BY id DESC LIMIT 500')->fetchAll(),
        default => [],
    };
}

function format_metric(mixed $value, bool $currency = false): string
{
    $number = (float) $value;
    if ($currency) {
        return 'Rs. ' . number_format($number, 2);
    }
    if (floor($number) === $number) {
        return number_format($number, 0);
    }
    return number_format($number, 2);
}

function format_cell_value(string $column, mixed $value): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }

    foreach (['amount', 'ctc', 'gross', 'deductions', 'payout', 'paid_amount', 'net_payout'] as $hint) {
        if (str_contains($column, $hint)) {
            return 'Rs. ' . number_format((float) $value, 2);
        }
    }

    return (string) $value;
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("DESCRIBE {$table}");
    return array_map(static fn(array $row): string => (string) $row['Field'], $stmt->fetchAll());
}

function resolve_period_range(string $period, string $customFrom, string $customTo): array
{
    $today = new DateTimeImmutable('today');

    return match ($period) {
        'all_time' => ['', ''],
        'weekly' => [
            $today->modify('monday this week')->format('Y-m-d'),
            $today->modify('sunday this week')->format('Y-m-d'),
        ],
        'monthly' => [
            $today->modify('first day of this month')->format('Y-m-d'),
            $today->modify('last day of this month')->format('Y-m-d'),
        ],
        'quarterly' => (function () use ($today): array {
            $month = (int) $today->format('n');
            $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3) + 1;
            $start = new DateTimeImmutable($today->format('Y') . '-' . str_pad((string) $quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $end = $start->modify('+2 months')->modify('last day of this month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        })(),
        'yearly' => [
            $today->modify('first day of january ' . $today->format('Y'))->format('Y-m-d'),
            $today->modify('last day of december ' . $today->format('Y'))->format('Y-m-d'),
        ],
        'custom' => [$customFrom, $customTo],
        default => [
            $today->modify('first day of this month')->format('Y-m-d'),
            $today->modify('last day of this month')->format('Y-m-d'),
        ],
    };
}

function pretty_field_label(string $field): string
{
    $overrides = [
        'dob' => 'Date of Birth',
        'ctc' => 'CTC',
        'id' => 'ID',
    ];
    if (isset($overrides[$field])) {
        return $overrides[$field];
    }

    $parts = explode('_', $field);
    $mapped = array_map(static function (string $part): string {
        $lower = strtolower($part);
        return match ($lower) {
            'id' => 'ID',
            'ctc' => 'CTC',
            'dob' => 'DOB',
            'no' => 'No',
            default => ucfirst($lower),
        };
    }, $parts);

    return implode(' ', $mapped);
}

function module_dashboard_payload(PDO $pdo, string $module): array
{
    return match ($module) {
        'students' => [
            'summary' => [
                ['label' => 'Students', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM students')],
                ['label' => 'Current Enrollments', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM student_enrollments WHERE is_current = 1')],
                ['label' => 'Present Today', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM attendance_records WHERE attendance_date = CURDATE() AND status = "present"')],
                ['label' => 'Pending Fees', 'value' => (int) scalar($pdo, 'SELECT COUNT(DISTINCT student_id) FROM student_fee_dues WHERE status <> "paid"')],
            ],
            'chart' => [
                'labels' => ['Students', 'Enrollments', 'Present Today', 'Pending Fees'],
                'values' => [
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM students'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM student_enrollments WHERE is_current = 1'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM attendance_records WHERE attendance_date = CURDATE() AND status = "present"'),
                    (float) scalar($pdo, 'SELECT COUNT(DISTINCT student_id) FROM student_fee_dues WHERE status <> "paid"'),
                ],
            ],
            'insights' => [
                ['title' => 'Attendance and fee records stay connected', 'text' => 'Student operations now surface finance impact immediately through linked pending fee counts.'],
                ['title' => 'Lifecycle visibility', 'text' => 'Enrollments, attendance, and student profiles are part of the same operational timeline.'],
            ],
        ],
        'academics' => [
            'summary' => [
                ['label' => 'Subjects', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM subjects')],
                ['label' => 'Assignments', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM assignments')],
                ['label' => 'Submissions', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM assignment_submissions')],
                ['label' => 'Exam Results', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM exam_results')],
            ],
            'chart' => [
                'labels' => ['Subjects', 'Assignments', 'Submissions', 'Exam Results'],
                'values' => [
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM subjects'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM assignments'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM assignment_submissions'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM exam_results'),
                ],
            ],
            'insights' => [
                ['title' => 'Homework to assessment flow', 'text' => 'Assignments, submissions, and exam outcomes now sit in one academic reporting layer.'],
                ['title' => 'Family communication included', 'text' => 'Parent-teacher messaging lives beside academic progress for easier follow-up.'],
            ],
        ],
        'finance' => [
            'summary' => [
                ['label' => 'Fee Structures', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM fee_structures')],
                ['label' => 'Open Dues', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM student_fee_dues WHERE status <> "paid"')],
                ['label' => 'Collections', 'value' => format_metric(scalar($pdo, 'SELECT COALESCE(SUM(amount), 0) FROM payments'), true)],
                ['label' => 'Reconciliations', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM payment_reconciliations')],
            ],
            'chart' => [
                'labels' => ['Fee Heads', 'Open Dues', 'Payments', 'Reconciliations'],
                'values' => [
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM fee_structures'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM student_fee_dues WHERE status <> "paid"'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM payments'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM payment_reconciliations'),
                ],
            ],
            'insights' => [
                ['title' => 'Offline and web flows reconcile centrally', 'text' => 'Manual entries and website orders now belong to one finance record stream.'],
                ['title' => 'Student dues stay visible', 'text' => 'Finance dashboards keep operational awareness on which student accounts still need attention.'],
            ],
        ],
        'operations' => [
            'summary' => [
                ['label' => 'Timetable Rows', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM timetables')],
                ['label' => 'Transport Routes', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM transport_routes')],
                ['label' => 'Hostel Rooms', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM hostel_rooms')],
                ['label' => 'Active Boarders', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM hostel_allocations WHERE status = "active"')],
            ],
            'chart' => [
                'labels' => ['Timetables', 'Routes', 'Rooms', 'Active Boarders'],
                'values' => [
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM timetables'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM transport_routes'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM hostel_rooms'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM hostel_allocations WHERE status = "active"'),
                ],
            ],
            'insights' => [
                ['title' => 'Transport and hostel data are linked to students', 'text' => 'Operational allocations are easier to manage because the student record is the shared reference point.'],
                ['title' => 'Capacity is visible', 'text' => 'Room occupancy and route allocation metrics make planning feel far more intentional.'],
            ],
        ],
        'hr' => [
            'summary' => [
                ['label' => 'Employees', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM employees')],
                ['label' => 'Pending Leaves', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM leave_requests WHERE status = "pending"')],
                ['label' => 'Payroll Runs', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM payroll_runs')],
                ['label' => 'Net Payout', 'value' => format_metric(scalar($pdo, 'SELECT COALESCE(SUM(net_payout), 0) FROM payroll_items'), true)],
            ],
            'chart' => [
                'labels' => ['Employees', 'Pending Leaves', 'Payroll Runs', 'Payroll Items'],
                'values' => [
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM employees'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM leave_requests WHERE status = "pending"'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM payroll_runs'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM payroll_items'),
                ],
            ],
            'insights' => [
                ['title' => 'Leave and payroll are in one workspace', 'text' => 'Approvals, employee records, and payout visibility are all handled from the same module.'],
                ['title' => 'Finance awareness remains built in', 'text' => 'Payroll totals can be compared quickly against wider finance activity as needed.'],
            ],
        ],
        default => [
            'summary' => [
                ['label' => 'Students', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM students')],
                ['label' => 'Payments', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM payments')],
                ['label' => 'Employees', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM employees')],
                ['label' => 'Assignments', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM assignments')],
            ],
            'chart' => [
                'labels' => ['Students', 'Payments', 'Employees', 'Assignments'],
                'values' => [
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM students'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM payments'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM employees'),
                    (float) scalar($pdo, 'SELECT COUNT(*) FROM assignments'),
                ],
            ],
            'insights' => [
                ['title' => 'Cross-module reporting', 'text' => 'This area is ready to become a richer analytics layer as reporting grows.'],
            ],
        ],
    };
}

$flash = '';
$cfg = null;
$pk = '';
$fields = [];
$searchable = [];
$rows = [];
$total = 0;
$totalPages = 1;
$page = 1;
$limit = 20;
$q = '';
$fieldOptionMap = [];
$fieldTypes = [];

$allModules = available_modules();
$roleList = [];
$accessUsers = [];
$moduleFilterFields = [];
$selectedFilters = [];
$filterOptionMap = [];
$dateFrom = '';
$dateTo = '';
$activeDateField = '';

$moduleFilterTemplates = [
    'students' => ['class_name', 'section_name', 'status', 'gender'],
    'academics' => ['class_name', 'section_name', 'status', 'exam_name', 'grade'],
    'finance' => ['status', 'payment_mode', 'source', 'frequency', 'fee_head'],
    'operations' => ['status', 'day_name', 'route_name', 'type'],
    'hr' => ['status', 'department', 'leave_type', 'designation', 'month_label'],
];

$reportsState = [
    'available_modules' => [],
    'module' => '',
    'entity' => '',
    'entities' => [],
    'period' => 'all_time',
    'custom_from' => '',
    'custom_to' => '',
    'date_from' => '',
    'date_to' => '',
    'date_field' => '',
    'status' => '',
    'class_name' => '',
    'section_name' => '',
    'payment_mode' => '',
    'status_options' => [],
    'class_options' => [],
    'section_options' => [],
    'payment_mode_options' => [],
    'row_count' => 0,
];

if ($view === 'user-access') {
    $roleList = $pdo->query('SELECT id, name FROM roles ORDER BY id ASC')->fetchAll();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create-user') {
            $stmt = $pdo->prepare('INSERT INTO users (role_id, name, email, password_hash, is_active, created_at, updated_at) VALUES (:role_id,:name,:email,:password_hash,1,NOW(),NOW())');
            $primaryRoleId = (int) ($_POST['role_id'] ?? 0);
            $stmt->execute([
                'role_id' => $primaryRoleId,
                'name' => trim((string) ($_POST['name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'password_hash' => password_hash((string) ($_POST['password'] ?? 'password'), PASSWORD_DEFAULT),
            ]);
            $newUserId = (int) $pdo->lastInsertId();
            set_user_module_access($pdo, $newUserId, array_map('strval', $_POST['modules'] ?? []));
            $assignedRoleIds = array_map('intval', $_POST['role_ids'] ?? []);
            if (!in_array($primaryRoleId, $assignedRoleIds, true)) {
                $assignedRoleIds[] = $primaryRoleId;
            }
            set_user_role_assignments($pdo, $newUserId, $assignedRoleIds);
            $flash = 'User created with module access.';
        }
        if ($action === 'update-access') {
            set_user_module_access($pdo, (int) ($_POST['user_id'] ?? 0), array_map('strval', $_POST['modules'] ?? []));
            $flash = 'User module access updated.';
        }
        if ($action === 'toggle-active') {
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            $targetIsActive = (int) ($_POST['is_active'] ?? 0);
            if ($targetUserId === (int) $user['id']) {
                $flash = 'You cannot deactivate your own account while signed in.';
            } else {
                $pdo->prepare('UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id')
                    ->execute([
                        'is_active' => $targetIsActive,
                        'id' => $targetUserId,
                    ]);
                $flash = $targetIsActive === 1 ? 'User activated successfully.' : 'User deactivated successfully.';
            }
        }
        if ($action === 'reset-password') {
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            $newPassword = trim((string) ($_POST['new_password'] ?? ''));
            if ($newPassword === '') {
                $flash = 'Please enter a new password.';
            } else {
                $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id')
                    ->execute([
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'id' => $targetUserId,
                    ]);
                $flash = 'Password reset successfully.';
            }
        }
        if ($action === 'update-roles') {
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            set_user_role_assignments($pdo, $targetUserId, array_map('intval', $_POST['role_ids'] ?? []));
            $flash = 'User role assignments updated.';
        }
    }
    $accessUsers = $pdo->query('SELECT u.id, u.name, u.email, u.is_active, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.id ASC')->fetchAll();
}

if ($view === 'entity') {
    $availableEntities = $menus[$module]['entities'] ?? [];
    if ($entity === '' || !isset($entityMap[$entity]) || !in_array($entity, $availableEntities, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $cfg = $entityMap[$entity];
    $pk = $cfg['pk'];
    $fields = $cfg['fields'];
    $searchable = $cfg['search'];
    $entityColumns = array_merge([$pk], $fields);
    $moduleFilterFields = array_values(array_intersect($moduleFilterTemplates[$module] ?? [], $entityColumns));

    $dateFieldCandidates = ['attendance_date', 'result_date', 'due_date', 'payment_date', 'from_date', 'to_date', 'joining_date', 'generated_at', 'dob'];
    foreach ($dateFieldCandidates as $candidate) {
        if (in_array($candidate, $entityColumns, true)) {
            $activeDateField = $candidate;
            break;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create' || $action === 'update') {
            $data = [];
            foreach ($fields as $field) {
                $value = $_POST[$field] ?? null;
                $data[$field] = ($value === '') ? null : $value;
            }
            if ($action === 'create') {
                $columns = implode(', ', array_keys($data));
                $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, array_keys($data)));
                $pdo->prepare("INSERT INTO {$entity} ({$columns}) VALUES ({$placeholders})")->execute($data);
                $flash = 'Record created successfully.';
            } else {
                $id = (int) ($_POST[$pk] ?? 0);
                $set = implode(', ', array_map(static fn(string $field): string => "{$field} = :{$field}", array_keys($data)));
                $data[$pk] = $id;
                $pdo->prepare("UPDATE {$entity} SET {$set} WHERE {$pk} = :{$pk}")->execute($data);
                $flash = 'Record updated successfully.';
            }
        }
        if ($action === 'delete') {
            $pdo->prepare("DELETE FROM {$entity} WHERE {$pk} = :id")->execute(['id' => (int) ($_POST[$pk] ?? 0)]);
            $flash = 'Record deleted successfully.';
        }
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(5, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereSql = '';
    $params = [];
    $whereParts = [];
    if ($q !== '' && !empty($searchable)) {
        $parts = [];
        foreach ($searchable as $index => $column) {
            $key = 'q' . $index;
            $parts[] = "{$column} LIKE :{$key}";
            $params[$key] = '%' . $q . '%';
        }
        $whereParts[] = '(' . implode(' OR ', $parts) . ')';
    }

    foreach ($moduleFilterFields as $field) {
        $key = 'filter_' . $field;
        $value = trim((string) ($_GET[$key] ?? ''));
        $selectedFilters[$field] = $value;
        if ($value !== '') {
            $paramKey = 'mf_' . $field;
            $whereParts[] = "{$field} = :{$paramKey}";
            $params[$paramKey] = $value;
        }
    }

    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    if ($activeDateField !== '') {
        if ($dateFrom !== '') {
            $whereParts[] = "DATE({$activeDateField}) >= :date_from";
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $whereParts[] = "DATE({$activeDateField}) <= :date_to";
            $params['date_to'] = $dateTo;
        }
    }

    if (!empty($whereParts)) {
        $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$entity}{$whereSql}");
    $stmt->execute($params);
    $total = (int) ($stmt->fetch()['c'] ?? 0);
    $totalPages = (int) max(1, ceil(max($total, 1) / $limit));

    $stmt = $pdo->prepare("SELECT * FROM {$entity}{$whereSql} ORDER BY {$pk} DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($fields as $field) {
        $options = field_options($pdo, $field);
        if (!empty($options)) {
            $fieldOptionMap[$field] = $options;
            $fieldTypes[$field] = 'select';
        } elseif (str_contains($field, 'description') || str_contains($field, 'note') || str_contains($field, 'message') || str_contains($field, 'reason') || $field === 'address') {
            $fieldTypes[$field] = 'textarea';
        } elseif (str_contains($field, 'date')) {
            $fieldTypes[$field] = 'date';
        } else {
            $fieldTypes[$field] = 'text';
        }
    }

    foreach ($moduleFilterFields as $field) {
        $filterOptionMap[$field] = [];
        $stmt = $pdo->query("SELECT DISTINCT {$field} AS value FROM {$entity} WHERE {$field} IS NOT NULL AND {$field} <> '' ORDER BY {$field} ASC LIMIT 100");
        foreach ($stmt->fetchAll() as $optionRow) {
            $value = (string) ($optionRow['value'] ?? '');
            if ($value !== '') {
                $filterOptionMap[$field][] = $value;
            }
        }
    }
}

if ($view === 'module' && $module === 'reports') {
    foreach ($menus as $menuKey => $menuData) {
        if ($menuKey !== 'reports' && !empty($menuData['entities'] ?? [])) {
            $reportsState['available_modules'][$menuKey] = (string) $menuData['label'];
        }
    }

    $reportsState['module'] = (string) ($_GET['report_module'] ?? array_key_first($reportsState['available_modules']) ?? 'students');
    if (!isset($reportsState['available_modules'][$reportsState['module']])) {
        $reportsState['module'] = (string) (array_key_first($reportsState['available_modules']) ?? 'students');
    }

    $reportsState['entities'] = $menus[$reportsState['module']]['entities'] ?? [];
    $reportsState['entity'] = (string) ($_GET['report_entity'] ?? ($reportsState['entities'][0] ?? ''));
    if (!in_array($reportsState['entity'], $reportsState['entities'], true)) {
        $reportsState['entity'] = (string) ($reportsState['entities'][0] ?? '');
    }

    $reportsState['period'] = (string) ($_GET['period'] ?? 'all_time');
    $reportsState['custom_from'] = trim((string) ($_GET['custom_from'] ?? ''));
    $reportsState['custom_to'] = trim((string) ($_GET['custom_to'] ?? ''));

    [$reportsState['date_from'], $reportsState['date_to']] = resolve_period_range(
        $reportsState['period'],
        $reportsState['custom_from'],
        $reportsState['custom_to']
    );

    if ($reportsState['entity'] !== '') {
        $columns = table_columns($pdo, $reportsState['entity']);
        foreach (['payment_date', 'attendance_date', 'result_date', 'due_date', 'from_date', 'to_date', 'joining_date', 'generated_at', 'dob', 'created_at'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $reportsState['date_field'] = $candidate;
                break;
            }
        }

        $reportsState['status'] = trim((string) ($_GET['status'] ?? ''));
        $reportsState['class_name'] = trim((string) ($_GET['class_name'] ?? ''));
        $reportsState['section_name'] = trim((string) ($_GET['section_name'] ?? ''));
        $reportsState['payment_mode'] = trim((string) ($_GET['payment_mode'] ?? ''));

        $whereParts = [];
        $params = [];
        if ($reportsState['date_field'] !== '' && $reportsState['date_from'] !== '' && $reportsState['date_to'] !== '') {
            $whereParts[] = "DATE({$reportsState['date_field']}) BETWEEN :date_from AND :date_to";
            $params['date_from'] = $reportsState['date_from'];
            $params['date_to'] = $reportsState['date_to'];
        }
        if (in_array('status', $columns, true) && $reportsState['status'] !== '') {
            $whereParts[] = "status = :status";
            $params['status'] = $reportsState['status'];
        }
        if (in_array('class_name', $columns, true) && $reportsState['class_name'] !== '') {
            $whereParts[] = "class_name = :class_name";
            $params['class_name'] = $reportsState['class_name'];
        }
        if (in_array('section_name', $columns, true) && $reportsState['section_name'] !== '') {
            $whereParts[] = "section_name = :section_name";
            $params['section_name'] = $reportsState['section_name'];
        }
        if (in_array('payment_mode', $columns, true) && $reportsState['payment_mode'] !== '') {
            $whereParts[] = "payment_mode = :payment_mode";
            $params['payment_mode'] = $reportsState['payment_mode'];
        }

        $whereSql = !empty($whereParts) ? (' WHERE ' . implode(' AND ', $whereParts)) : '';
        $reportsState['row_count'] = (int) scalar($pdo, "SELECT COUNT(*) FROM {$reportsState['entity']}{$whereSql}", $params);

        if (in_array('status', $columns, true)) {
            $reportsState['status_options'] = array_map(static fn(array $row): string => (string) $row['v'], $pdo->query("SELECT DISTINCT status AS v FROM {$reportsState['entity']} WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll());
        }
        if (in_array('class_name', $columns, true)) {
            $reportsState['class_options'] = array_map(static fn(array $row): string => (string) $row['v'], $pdo->query("SELECT DISTINCT class_name AS v FROM {$reportsState['entity']} WHERE class_name IS NOT NULL AND class_name <> '' ORDER BY class_name")->fetchAll());
        }
        if (in_array('section_name', $columns, true)) {
            $reportsState['section_options'] = array_map(static fn(array $row): string => (string) $row['v'], $pdo->query("SELECT DISTINCT section_name AS v FROM {$reportsState['entity']} WHERE section_name IS NOT NULL AND section_name <> '' ORDER BY section_name")->fetchAll());
        }
        if (in_array('payment_mode', $columns, true)) {
            $reportsState['payment_mode_options'] = array_map(static fn(array $row): string => (string) $row['v'], $pdo->query("SELECT DISTINCT payment_mode AS v FROM {$reportsState['entity']} WHERE payment_mode IS NOT NULL AND payment_mode <> '' ORDER BY payment_mode")->fetchAll());
        }

        if ((string) ($_GET['export'] ?? '') === 'csv') {
            $sortColumn = in_array('id', $columns, true) ? 'id' : (string) $columns[0];
            $stmt = $pdo->prepare("SELECT * FROM {$reportsState['entity']}{$whereSql} ORDER BY {$sortColumn} DESC");
            $stmt->execute($params);
            $reportRows = $stmt->fetchAll();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="report_' . $reportsState['entity'] . '_' . date('Ymd_His') . '.csv"');

            $out = fopen('php://output', 'wb');
            fputcsv($out, $columns);
            foreach ($reportRows as $reportRow) {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = $reportRow[$column] ?? '';
                }
                fputcsv($out, $line);
            }
            fclose($out);
            exit;
        }
    }
}

$overallSummary = [
    ['label' => 'Students', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM students')],
    ['label' => 'Employees', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM employees')],
    ['label' => 'Collections', 'value' => format_metric(scalar($pdo, 'SELECT COALESCE(SUM(amount), 0) FROM payments'), true)],
    ['label' => 'Pending Leaves', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM leave_requests WHERE status = "pending"')],
];

$overallChart = [
    'labels' => ['Students', 'Employees', 'Assignments', 'Payments', 'Hostel Boarders'],
    'values' => [
        (float) scalar($pdo, 'SELECT COUNT(*) FROM students'),
        (float) scalar($pdo, 'SELECT COUNT(*) FROM employees'),
        (float) scalar($pdo, 'SELECT COUNT(*) FROM assignments'),
        (float) scalar($pdo, 'SELECT COUNT(*) FROM payments'),
        (float) scalar($pdo, 'SELECT COUNT(*) FROM hostel_allocations WHERE status = "active"'),
    ],
];

$crossInsights = [
    ['label' => 'Students with pending fees', 'value' => (int) scalar($pdo, 'SELECT COUNT(DISTINCT student_id) FROM student_fee_dues WHERE status <> "paid"'), 'url' => '?module=finance&entity=student_fee_dues'],
    ['label' => 'Hostel students on transport', 'value' => (int) scalar($pdo, 'SELECT COUNT(DISTINCT ha.student_id) FROM hostel_allocations ha JOIN transport_allocations ta ON ta.student_id = ha.student_id WHERE ha.status = "active" AND ta.status = "active"'), 'url' => '?module=operations&entity=hostel_allocations'],
    ['label' => 'Assignment submissions received', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM assignment_submissions'), 'url' => '?module=academics&entity=assignment_submissions'],
    ['label' => 'Payroll items generated', 'value' => (int) scalar($pdo, 'SELECT COUNT(*) FROM payroll_items'), 'url' => '?module=hr&entity=payroll_items'],
];

$dashboardModuleCharts = [];
foreach (array_keys($menus) as $menuKey) {
    $dashboardModuleCharts[$menuKey] = module_dashboard_payload($pdo, $menuKey);
}

$modulePayload = module_dashboard_payload($pdo, $module);
$currentLabel = $menus[$module]['label'] ?? ucfirst($module);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIBA ERP Admin</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
</head>
<body>
<div class="admin-layout">
    <aside class="sidebar">
        <div class="brand-block stack" style="gap:.6rem">
            <span class="eyebrow" style="background:rgba(255,255,255,.1);color:#effff5">SIBA ERP</span>
            <div class="brand-copy">
                <h2 style="font-size:1.7rem;color:#fff">Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
            <div class="sidebar-controls">
                <button type="button" class="btn btn-soft sidebar-toggle" id="sidebarToggle" title="Collapse menu">
                    <span class="sidebar-icon">≡</span>
                </button>
                <button type="button" class="btn btn-soft sidebar-toggle theme-toggle" id="themeToggle" title="Toggle theme">
                    <span class="sidebar-icon" id="themeToggleIcon">D</span>
                </button>
            </div>
        </div>

        <div class="nav-group">
            <div class="nav-title">Core</div>
            <a class="nav-link <?= $view === 'dashboard' ? 'active' : '' ?>" href="?view=dashboard">
                <span class="sidebar-icon">◫</span>
                <span>Main Dashboard</span>
                <span class="nav-tag">Overview</span>
            </a>
            <?php if ($isSuperAdmin): ?>
                <a class="nav-link <?= $view === 'user-access' ? 'active' : '' ?>" href="?view=user-access">
                    <span class="sidebar-icon">⚙</span>
                    <span>User Access</span>
                    <span class="nav-tag">Control</span>
                </a>
            <?php endif; ?>
        </div>

        <div class="nav-group">
            <div class="nav-title">Admissions</div>
            <a class="nav-link" href="application-intake.php">
                <span class="sidebar-icon">📋</span>
                <span>Application Intake</span>
                <span class="nav-tag">New</span>
            </a>
            <a class="nav-link" href="applications-list.php">
                <span class="sidebar-icon">📂</span>
                <span>Applications</span>
                <span class="nav-tag">List</span>
            </a>
        </div>

        <?php foreach ($menus as $menuKey => $menu): ?>
            <div class="nav-group">
                <div class="nav-title"><?= e((string) $menu['label']) ?></div>
                <a class="nav-link <?= $view === 'module' && $module === $menuKey ? 'active' : '' ?>" href="?view=module&amp;module=<?= e((string) $menuKey) ?>">
                    <span class="sidebar-icon">▣</span>
                    <span><?= e((string) $menu['label']) ?> Dashboard</span>
                    <span class="nav-tag"><?= count($menu['entities'] ?? []) ?> views</span>
                </a>
                <?php foreach (($menu['entities'] ?? []) as $menuEntity): ?>
                    <a class="nav-link <?= $view === 'entity' && $entity === $menuEntity ? 'active' : '' ?>" href="?module=<?= e((string) $menuKey) ?>&amp;entity=<?= e((string) $menuEntity) ?>">
                        <span class="sidebar-icon">•</span>
                        <span><?= e((string) $entityMap[$menuEntity]['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="nav-group">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack">
        <section class="hero-banner">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">
                        <?php if ($view === 'dashboard'): ?>Executive Dashboard<?php elseif ($view === 'module'): ?><?= e((string) $currentLabel) ?> Module<?php elseif ($view === 'entity' && $cfg): ?><?= e((string) $cfg['label']) ?> Records<?php else: ?>Access Management<?php endif; ?>
                    </span>
                    <div>
                        <h1>
                            <?php if ($view === 'dashboard'): ?>Modern school operations, all in one place.
                            <?php elseif ($view === 'module'): ?><?= e((string) $currentLabel) ?> dashboard with connected operational insight.
                            <?php elseif ($view === 'entity' && $cfg): ?><?= e((string) $cfg['label']) ?> management workspace.
                            <?php else: ?>Create users and assign module access precisely.<?php endif; ?>
                        </h1>
                        <p>
                            <?php if ($view === 'dashboard'): ?>A cleaner control center for academic, financial, operational, and people data.
                            <?php elseif ($view === 'module'): ?>Track the most relevant metrics for this module while keeping the rest of the ERP connected.
                            <?php elseif ($view === 'entity' && $cfg): ?>Search, edit, create, and manage records from a focused interface without leaving the module context.
                            <?php else: ?>Super Admin can create users and control exactly which modules each user is allowed to access.<?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="toolbar-right">
                    <a class="btn btn-outline" href="../system-health.php" target="_blank" rel="noopener">System Health</a>
                </div>
            </div>
        </section>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?= e($flash) ?></div>
        <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Executive Snapshot</h2>
                        <p>Key numbers across the full ERP with a cleaner visual hierarchy.</p>
                    </div>
                </div>
                <div class="kpi-grid">
                    <?php foreach ($overallSummary as $item): ?>
                        <?php $isCurrency = str_starts_with((string) $item['value'], 'Rs.'); ?>
                        <div class="kpi-card">
                            <div class="kpi-label"><?= e((string) $item['label']) ?></div>
                            <div class="kpi-value<?= $isCurrency ? ' kpi-value-currency' : '' ?>"><?= e((string) $item['value']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="chart-shell">
                <div class="panel" style="padding:1.25rem">
                    <div class="section-title">
                        <div>
                            <h2>Interconnected Insights</h2>
                            <p>Cross-module indicators that show how the system ties together operationally.</p>
                        </div>
                    </div>
                    <div class="quick-link-grid">
                        <?php foreach ($crossInsights as $insight): ?>
                            <a class="quick-link" href="<?= e((string) $insight['url']) ?>">
                                <strong><?= e((string) $insight['label']) ?></strong>
                                <span class="kpi-value" style="font-size:1.55rem"><?= e((string) $insight['value']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Module Performance</h2>
                        <p>Each module now gets its own chart directly on the main dashboard.</p>
                    </div>
                </div>
                <div class="module-chip-grid">
                    <?php foreach ($menus as $menuKey => $menu): ?>
                        <a class="module-chip" href="?view=module&amp;module=<?= e((string) $menuKey) ?>" style="text-decoration:none">
                            <strong><?= e((string) $menu['label']) ?></strong>
                            <span><?= count($menu['entities'] ?? []) ?> entity views available</span>
                            <div id="dashboardChart_<?= e((string) $menuKey) ?>" class="mini-chart"></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($view === 'module' && $module === 'reports'): ?>
            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Reports Export Center</h2>
                        <p>Generate module-wise CSV reports with period and context filters, without leaving the ERP design flow.</p>
                    </div>
                    <span class="badge"><?= number_format((int) $reportsState['row_count']) ?> rows match current filters</span>
                </div>
                <form method="get" class="field-grid">
                    <input type="hidden" name="view" value="module">
                    <input type="hidden" name="module" value="reports">
                    <div>
                        <label for="report_module">Module</label>
                        <select id="report_module" name="report_module" onchange="this.form.submit()">
                            <?php foreach ($reportsState['available_modules'] as $reportModuleKey => $reportModuleLabel): ?>
                                <option value="<?= e((string) $reportModuleKey) ?>" <?= $reportsState['module'] === $reportModuleKey ? 'selected' : '' ?>><?= e((string) $reportModuleLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="report_entity">Dataset</label>
                        <select id="report_entity" name="report_entity">
                            <?php foreach ($reportsState['entities'] as $reportEntity): ?>
                                <option value="<?= e((string) $reportEntity) ?>" <?= $reportsState['entity'] === $reportEntity ? 'selected' : '' ?>><?= e((string) ($entityMap[$reportEntity]['label'] ?? $reportEntity)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="period">Period</label>
                        <select id="period" name="period">
                            <option value="all_time" <?= $reportsState['period'] === 'all_time' ? 'selected' : '' ?>>All Time</option>
                            <option value="weekly" <?= $reportsState['period'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                            <option value="monthly" <?= $reportsState['period'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                            <option value="quarterly" <?= $reportsState['period'] === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                            <option value="yearly" <?= $reportsState['period'] === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                            <option value="custom" <?= $reportsState['period'] === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div>
                        <label for="custom_from">Custom From</label>
                        <input type="date" id="custom_from" name="custom_from" value="<?= e((string) $reportsState['custom_from']) ?>">
                    </div>
                    <div>
                        <label for="custom_to">Custom To</label>
                        <input type="date" id="custom_to" name="custom_to" value="<?= e((string) $reportsState['custom_to']) ?>">
                    </div>
                    <?php if (!empty($reportsState['status_options'])): ?>
                        <div>
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="">All</option>
                                <?php foreach ($reportsState['status_options'] as $option): ?>
                                    <option value="<?= e((string) $option) ?>" <?= $reportsState['status'] === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($reportsState['class_options'])): ?>
                        <div>
                            <label for="class_name">Class</label>
                            <select id="class_name" name="class_name">
                                <option value="">All</option>
                                <?php foreach ($reportsState['class_options'] as $option): ?>
                                    <option value="<?= e((string) $option) ?>" <?= $reportsState['class_name'] === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($reportsState['section_options'])): ?>
                        <div>
                            <label for="section_name">Section</label>
                            <select id="section_name" name="section_name">
                                <option value="">All</option>
                                <?php foreach ($reportsState['section_options'] as $option): ?>
                                    <option value="<?= e((string) $option) ?>" <?= $reportsState['section_name'] === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($reportsState['payment_mode_options'])): ?>
                        <div>
                            <label for="payment_mode">Payment Mode</label>
                            <select id="payment_mode" name="payment_mode">
                                <option value="">All</option>
                                <?php foreach ($reportsState['payment_mode_options'] as $option): ?>
                                    <option value="<?= e((string) $option) ?>" <?= $reportsState['payment_mode'] === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label>Date Window</label>
                        <input type="text" value="<?= e((string) $reportsState['date_from']) ?> to <?= e((string) $reportsState['date_to']) ?>" readonly>
                    </div>
                    <div class="action-row" style="align-self:end">
                        <button class="btn btn-soft" type="submit">Apply Report Filters</button>
                        <button class="btn" type="submit" name="export" value="csv">Export CSV</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($view === 'module' && $module !== 'reports'): ?>
            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2><?= e((string) $currentLabel) ?> Summary</h2>
                        <p>Module-specific KPIs built around the records and flows inside this area.</p>
                    </div>
                </div>
                <div class="kpi-grid">
                    <?php foreach ($modulePayload['summary'] as $item): ?>
                        <?php $isCurrency = str_starts_with((string) $item['value'], 'Rs.'); ?>
                        <div class="kpi-card">
                            <div class="kpi-label"><?= e((string) $item['label']) ?></div>
                            <div class="kpi-value<?= $isCurrency ? ' kpi-value-currency' : '' ?>"><?= e((string) $item['value']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="split-grid">
                <div class="panel" style="padding:1.25rem">
                    <div class="section-title">
                        <div>
                            <h2><?= e((string) $currentLabel) ?> Graph</h2>
                            <p>Visual comparison of the main records in this module.</p>
                        </div>
                    </div>
                    <div id="moduleChart" class="bar-chart"></div>
                </div>

                <div class="panel" style="padding:1.25rem">
                    <div class="section-title">
                        <div>
                            <h2>Why This Module Matters</h2>
                            <p>Design-wise and workflow-wise, this module now feels connected to the rest of the ERP.</p>
                        </div>
                    </div>
                    <div class="feature-list">
                        <?php foreach ($modulePayload['insights'] as $insight): ?>
                            <div class="feature-item">
                                <strong><?= e((string) $insight['title']) ?></strong>
                                <p><?= e((string) $insight['text']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2><?= e((string) $currentLabel) ?> Records</h2>
                        <p>Open entity workspaces directly from the module dashboard.</p>
                    </div>
                </div>
                <div class="module-chip-grid">
                    <?php foreach (($menus[$module]['entities'] ?? []) as $menuEntity): ?>
                        <a class="module-chip" href="?module=<?= e((string) $module) ?>&amp;entity=<?= e((string) $menuEntity) ?>" style="text-decoration:none">
                            <strong><?= e((string) $entityMap[$menuEntity]['label']) ?></strong>
                            <span>Manage, search, edit, and create records.</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($view === 'user-access'): ?>
            <section class="panel" style="padding:1.25rem">
                <div class="toolbar">
                    <div>
                        <h2>User Access Management</h2>
                        <p>Create users via popup and manage module access from user cards below.</p>
                    </div>
                    <button type="button" class="btn" id="openUserCreateModal">Create User</button>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Existing Users</h2>
                        <p>Adjust module access without leaving the dashboard.</p>
                    </div>
                </div>
                <div class="user-cards-grid">
                    <?php foreach ($accessUsers as $accessUser): ?>
                        <?php $allowed = fetch_user_module_access($pdo, (int) $accessUser['id']); ?>
                        <?php $assignedRoles = fetch_user_role_assignments($pdo, (int) $accessUser['id']); ?>
                        <article class="user-card">
                            <div class="user-card-head">
                                <div class="user-meta">
                                    <div class="user-name"><?= e((string) $accessUser['name']) ?></div>
                                    <div class="user-sub"><?= e((string) $accessUser['email']) ?></div>
                                    <div class="user-sub">User ID: <?= (int) $accessUser['id'] ?></div>
                                </div>
                                <div class="stack" style="gap:.4rem;justify-items:end">
                                    <span class="user-role-badge"><?= e((string) $accessUser['role_name']) ?></span>
                                    <span class="status-pill"><?= ((int) $accessUser['is_active'] === 1) ? 'Active' : 'Inactive' ?></span>
                                </div>
                            </div>

                            <div class="user-card-section">
                                <h4>Role Assignments</h4>
                                <form method="post" class="stack" style="gap:.7rem">
                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update-roles">
                                    <input type="hidden" name="user_id" value="<?= (int) $accessUser['id'] ?>">
                                    <div class="access-grid">
                                        <?php foreach ($roleList as $role): ?>
                                            <?php $roleName = (string) $role['name']; ?>
                                            <label class="access-chip">
                                                <input type="checkbox" name="role_ids[]" value="<?= (int) $role['id'] ?>" <?= in_array($roleName, $assignedRoles, true) || $roleName === (string) $accessUser['role_name'] ? 'checked' : '' ?>>
                                                <strong><?= e($roleName) ?></strong>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div>
                                        <button class="btn btn-soft" type="submit">Save Roles</button>
                                    </div>
                                </form>
                            </div>

                            <div class="user-card-section">
                                <h4>Module Access</h4>
                                <form method="post" class="stack" style="gap:.7rem">
                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update-access">
                                    <input type="hidden" name="user_id" value="<?= (int) $accessUser['id'] ?>">
                                    <div class="access-grid">
                                        <?php foreach ($allModules as $key => $moduleConfig): ?>
                                            <label class="access-chip">
                                                <input type="checkbox" name="modules[]" value="<?= e((string) $key) ?>" <?= in_array($key, $allowed, true) ? 'checked' : '' ?>>
                                                <strong><?= e((string) $moduleConfig['label']) ?></strong>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div>
                                        <button class="btn btn-soft" type="submit">Save Access</button>
                                    </div>
                                </form>
                            </div>

                            <div class="user-card-section">
                                <h4>Security Actions</h4>
                                <div class="user-actions">
                                    <form method="post" class="stack" style="gap:.5rem">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle-active">
                                        <input type="hidden" name="user_id" value="<?= (int) $accessUser['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= ((int) $accessUser['is_active'] === 1) ? '0' : '1' ?>">
                                        <button class="btn btn-outline" type="submit" <?= ((int) $accessUser['id'] === (int) $user['id']) ? 'disabled' : '' ?>>
                                            <?= ((int) $accessUser['is_active'] === 1) ? 'Deactivate User' : 'Activate User' ?>
                                        </button>
                                    </form>
                                    <form method="post" class="stack" style="gap:.5rem">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reset-password">
                                        <input type="hidden" name="user_id" value="<?= (int) $accessUser['id'] ?>">
                                        <input type="text" name="new_password" placeholder="Set new password" required>
                                        <button class="btn" type="submit">Reset Password</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <div id="userCreateModalBackdrop" class="modal-backdrop">
                <div class="modal">
                    <div class="modal-head">
                        <div>
                            <span class="eyebrow">User Onboarding</span>
                            <h2 style="margin-top:.45rem">Create User</h2>
                        </div>
                        <button type="button" class="icon-btn" id="closeUserCreateModal">Close</button>
                    </div>
                    <form method="post" class="stack">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create-user">
                        <div class="field-grid">
                            <div>
                                <label for="user_name_modal">Name</label>
                                <input id="user_name_modal" name="name" required>
                            </div>
                            <div>
                                <label for="user_email_modal">Email</label>
                                <input id="user_email_modal" type="email" name="email" required>
                            </div>
                            <div>
                                <label for="user_password_modal">Password</label>
                                <input id="user_password_modal" name="password" value="password" required>
                            </div>
                            <div>
                                <label for="role_id_modal">Role</label>
                                <select id="role_id_modal" name="role_id" required>
                                    <option value="">Select role</option>
                                    <?php foreach ($roleList as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>"><?= e((string) $role['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label>Role Assignments (Multi-role)</label>
                            <div class="access-grid">
                                <?php foreach ($roleList as $role): ?>
                                    <label class="access-chip">
                                        <input type="checkbox" name="role_ids[]" value="<?= (int) $role['id'] ?>">
                                        <strong><?= e((string) $role['name']) ?></strong>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="module-chip-grid">
                            <?php foreach ($allModules as $key => $moduleConfig): ?>
                                <label class="module-chip">
                                    <input type="checkbox" name="modules[]" value="<?= e((string) $key) ?>" checked style="width:auto;min-height:auto;margin-right:.55rem">
                                    <strong><?= e((string) $moduleConfig['label']) ?></strong>
                                    <span>Enable access to this module.</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="action-row">
                            <button class="btn" type="submit">Create User</button>
                            <button class="btn btn-outline" type="button" id="cancelUserCreateModal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'entity' && $cfg): ?>
            <section class="panel" style="padding:1.25rem">
                <div class="toolbar">
                    <div>
                        <h2><?= e((string) $cfg['label']) ?></h2>
                        <p>Filter records, manage entries, and keep work inside the current module.</p>
                    </div>
                    <button type="button" class="btn" id="openCreateBtn">Create Record</button>
                </div>

                <form class="search-row" method="get">
                    <input type="hidden" name="module" value="<?= e($module) ?>">
                    <input type="hidden" name="entity" value="<?= e($entity) ?>">
                    <div style="flex:1 1 280px;max-width:360px">
                        <label for="q">Search</label>
                        <input id="q" name="q" placeholder="Search records..." value="<?= e($q) ?>">
                    </div>
                    <div style="width:130px">
                        <label for="limit">Rows</label>
                        <input id="limit" type="number" name="limit" value="<?= $limit ?>" min="5" max="100">
                    </div>
                    <?php foreach ($moduleFilterFields as $filterField): ?>
                        <div style="width:180px">
                            <label for="filter_<?= e($filterField) ?>"><?= e(ucwords(str_replace('_', ' ', $filterField))) ?></label>
                            <select id="filter_<?= e($filterField) ?>" name="filter_<?= e($filterField) ?>">
                                <option value="">All</option>
                                <?php foreach (($filterOptionMap[$filterField] ?? []) as $optionValue): ?>
                                    <option value="<?= e((string) $optionValue) ?>" <?= (($selectedFilters[$filterField] ?? '') === (string) $optionValue) ? 'selected' : '' ?>><?= e((string) $optionValue) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($activeDateField !== ''): ?>
                        <div style="width:170px">
                            <label for="date_from"><?= e(ucwords(str_replace('_', ' ', $activeDateField))) ?> From</label>
                            <input id="date_from" type="date" name="date_from" value="<?= e($dateFrom) ?>">
                        </div>
                        <div style="width:170px">
                            <label for="date_to"><?= e(ucwords(str_replace('_', ' ', $activeDateField))) ?> To</label>
                            <input id="date_to" type="date" name="date_to" value="<?= e($dateTo) ?>">
                        </div>
                    <?php endif; ?>
                    <div style="align-self:end">
                        <button class="btn btn-soft" type="submit">Apply Filters</button>
                    </div>
                </form>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Records</h2>
                        <p><?= number_format($total) ?> total rows available in this entity.</p>
                    </div>
                    <span class="badge">Page <?= $page ?> of <?= $totalPages ?></span>
                </div>

                <div class="data-table-wrap">
                    <div class="datatable-toolbar">
                        <div class="inline-note">DataTable enabled for this module with sorting and paging controls.</div>
                        <div class="datatable-controls">
                            <select class="datatable-size" data-table-target="entityTable">
                                <option value="10" selected>10 rows</option>
                                <option value="20">20 rows</option>
                                <option value="50">50 rows</option>
                                <option value="100">100 rows</option>
                            </select>
                        </div>
                    </div>
                    <table id="entityTable" class="js-datatable">
                        <thead>
                        <tr>
                            <?php foreach (array_merge([$pk], $fields) as $column): ?>
                                <th><?= e((string) $column) ?></th>
                            <?php endforeach; ?>
                            <th>Options</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach (array_merge([$pk], $fields) as $column): ?>
                                        <td><?= e(format_cell_value((string) $column, $row[$column] ?? null)) ?></td>
                                    <?php endforeach; ?>
                                    <td>
                                        <select class="action-select compact-select" data-id="<?= (int) $row[$pk] ?>" data-record="<?= htmlspecialchars((string) (json_encode($row, JSON_UNESCAPED_UNICODE) ?: '{}'), ENT_QUOTES, 'UTF-8') ?>">
                                            <option value="">Options</option>
                                            <option value="edit">Edit</option>
                                            <option value="delete">Delete</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?= count($fields) + 2 ?>">
                                    <div class="empty-state">No records matched the current filter.</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="datatable-pager" data-table-target="entityTable"></div>
                </div>

                <div class="pagination">
                    <div class="inline-note">Showing a clean paginated view so larger tables remain usable.</div>
                    <div class="page-links">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-outline" href="?module=<?= e($module) ?>&amp;entity=<?= e($entity) ?>&amp;q=<?= urlencode($q) ?>&amp;limit=<?= $limit ?>&amp;page=<?= $page - 1 ?><?php foreach ($moduleFilterFields as $filterField): ?>&amp;filter_<?= e($filterField) ?>=<?= urlencode((string) ($selectedFilters[$filterField] ?? '')) ?><?php endforeach; ?><?php if ($activeDateField !== ''): ?>&amp;date_from=<?= urlencode($dateFrom) ?>&amp;date_to=<?= urlencode($dateTo) ?><?php endif; ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a class="btn btn-outline" href="?module=<?= e($module) ?>&amp;entity=<?= e($entity) ?>&amp;q=<?= urlencode($q) ?>&amp;limit=<?= $limit ?>&amp;page=<?= $page + 1 ?><?php foreach ($moduleFilterFields as $filterField): ?>&amp;filter_<?= e($filterField) ?>=<?= urlencode((string) ($selectedFilters[$filterField] ?? '')) ?><?php endforeach; ?><?php if ($activeDateField !== ''): ?>&amp;date_from=<?= urlencode($dateFrom) ?>&amp;date_to=<?= urlencode($dateTo) ?><?php endif; ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div id="recordModalBackdrop" class="modal-backdrop">
                <div class="modal">
                    <div class="modal-head">
                        <div>
                            <span class="eyebrow">Record Editor</span>
                            <h2 id="recordModalTitle" style="margin-top:.45rem">Record</h2>
                        </div>
                        <button type="button" class="icon-btn" id="closeRecordModal">Close</button>
                    </div>

                    <form method="post" id="recordForm" class="stack">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" id="recordAction" value="create">
                        <input type="hidden" name="<?= e($pk) ?>" id="recordPk" value="">
                        <div class="field-grid">
                            <?php foreach ($fields as $field): ?>
                                <div>
                                    <label for="field_<?= e((string) $field) ?>"><?= e(pretty_field_label((string) $field)) ?></label>
                                    <?php if (($fieldTypes[$field] ?? 'text') === 'select'): ?>
                                        <select name="<?= e((string) $field) ?>" id="field_<?= e((string) $field) ?>">
                                            <option value="">Select</option>
                                            <?php foreach ($fieldOptionMap[$field] as $option): ?>
                                                <option value="<?= e((string) $option['id']) ?>"><?= e((string) $option['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif (($fieldTypes[$field] ?? 'text') === 'textarea'): ?>
                                        <textarea name="<?= e((string) $field) ?>" id="field_<?= e((string) $field) ?>"></textarea>
                                    <?php else: ?>
                                        <input type="<?= e((string) ($fieldTypes[$field] ?? 'text')) ?>" name="<?= e((string) $field) ?>" id="field_<?= e((string) $field) ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="action-row">
                            <button class="btn" id="recordSubmitBtn" type="submit">Save</button>
                            <button type="button" class="btn btn-outline" id="cancelRecordModal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <form method="post" id="inlineDeleteForm" style="display:none">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="<?= e($pk) ?>" id="inlineDeleteId" value="">
            </form>
        <?php endif; ?>
    </main>
</div>

<script>
(function(){
    const layout = document.querySelector('.admin-layout');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');
    const collapseKey = 'siba_erp_sidebar_collapsed';
    const themeKey = 'siba_erp_theme';
    const setTheme = (theme) => {
        const isDark = theme === 'dark';
        document.body.classList.toggle('theme-dark', isDark);
        if (themeToggleIcon) {
            themeToggleIcon.textContent = isDark ? 'L' : 'D';
        }
    };

    setTheme(localStorage.getItem(themeKey) === 'dark' ? 'dark' : 'light');
    if (layout && localStorage.getItem(collapseKey) === '1') {
        layout.classList.add('sidebar-collapsed');
    }
    sidebarToggle?.addEventListener('click', () => {
        layout?.classList.toggle('sidebar-collapsed');
        if (layout?.classList.contains('sidebar-collapsed')) {
            localStorage.setItem(collapseKey, '1');
        } else {
            localStorage.removeItem(collapseKey);
        }
    });
    themeToggle?.addEventListener('click', () => {
        const nextTheme = document.body.classList.contains('theme-dark') ? 'light' : 'dark';
        localStorage.setItem(themeKey, nextTheme);
        setTheme(nextTheme);
    });

    const drawChart = (id, data) => {
        const el = document.getElementById(id);
        if (!el) return;
        const labels = data.labels || [];
        const values = data.values || [];
        const max = Math.max(...values, 1);
        el.innerHTML = '';
        labels.forEach((label, index) => {
            const value = Number(values[index] || 0);
            const percent = Math.max(4, (value / max) * 100);
            const bar = document.createElement('div');
            bar.className = 'bar';
            bar.innerHTML = `<div class="bar-value">${value}</div><div class="bar-fill" style="height:${percent}%"></div><div class="bar-label">${label}</div>`;
            el.appendChild(bar);
        });
    };

    drawChart('moduleChart', <?= json_encode($modulePayload['chart'], JSON_UNESCAPED_UNICODE) ?>);
    const dashboardCharts = <?= json_encode($dashboardModuleCharts, JSON_UNESCAPED_UNICODE) ?>;
    Object.keys(dashboardCharts).forEach((key) => {
        drawChart('dashboardChart_' + key, dashboardCharts[key].chart || {labels: [], values: []});
    });

    const userCreateBackdrop = document.getElementById('userCreateModalBackdrop');
    const openUserCreateBtn = document.getElementById('openUserCreateModal');
    const closeUserCreateBtn = document.getElementById('closeUserCreateModal');
    const cancelUserCreateBtn = document.getElementById('cancelUserCreateModal');
    const closeUserCreateModal = () => userCreateBackdrop?.classList.remove('show');
    openUserCreateBtn?.addEventListener('click', () => userCreateBackdrop?.classList.add('show'));
    closeUserCreateBtn?.addEventListener('click', closeUserCreateModal);
    cancelUserCreateBtn?.addEventListener('click', closeUserCreateModal);
    userCreateBackdrop?.addEventListener('click', (event) => {
        if (event.target === userCreateBackdrop) {
            closeUserCreateModal();
        }
    });

    const initDataTable = (table) => {
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const headerCells = Array.from(table.querySelectorAll('thead th'));
        const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => !row.querySelector('.empty-state'));
        if (rows.length === 0) return;

        const targetId = table.id;
        const searchInput = document.querySelector(`.datatable-search[data-table-target="${targetId}"]`);
        const sizeSelect = document.querySelector(`.datatable-size[data-table-target="${targetId}"]`);
        const pager = document.querySelector(`.datatable-pager[data-table-target="${targetId}"]`);

        let page = 1;
        let sortIndex = -1;
        let sortDir = 1;
        let searchValue = '';
        let pageSize = Number(sizeSelect?.value || 10);

        const getText = (row, index) => {
            const cell = row.children[index];
            return (cell ? cell.textContent : '').trim().toLowerCase();
        };

        const filteredRows = () => {
            let list = rows.slice();
            if (searchValue !== '') {
                list = list.filter((row) => row.textContent.toLowerCase().includes(searchValue));
            }
            if (sortIndex >= 0) {
                list.sort((a, b) => {
                    const av = getText(a, sortIndex);
                    const bv = getText(b, sortIndex);
                    const an = Number(av.replace(/[^0-9.-]/g, ''));
                    const bn = Number(bv.replace(/[^0-9.-]/g, ''));
                    const bothNumeric = !Number.isNaN(an) && !Number.isNaN(bn) && av !== '' && bv !== '';
                    let compare = 0;
                    if (bothNumeric) {
                        compare = an - bn;
                    } else {
                        compare = av.localeCompare(bv);
                    }
                    return compare * sortDir;
                });
            }
            return list;
        };

        const renderPager = (totalPages) => {
            if (!pager) return;
            pager.innerHTML = '';
            if (totalPages <= 1) return;
            const addBtn = (label, targetPage, active = false) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = label;
                if (active) btn.classList.add('active');
                btn.addEventListener('click', () => {
                    page = targetPage;
                    render();
                });
                pager.appendChild(btn);
            };
            addBtn('Prev', Math.max(1, page - 1));
            for (let i = 1; i <= totalPages; i++) {
                addBtn(String(i), i, i === page);
            }
            addBtn('Next', Math.min(totalPages, page + 1));
        };

        const render = () => {
            const list = filteredRows();
            const totalPages = Math.max(1, Math.ceil(list.length / pageSize));
            page = Math.min(page, totalPages);
            const start = (page - 1) * pageSize;
            const end = start + pageSize;

            // Reorder rows in DOM so sorting remains correct even after page-size changes.
            list.forEach((row, idx) => {
                row.style.display = (idx >= start && idx < end) ? '' : 'none';
                tbody.appendChild(row);
            });
            renderPager(totalPages);
        };

        headerCells.forEach((th, index) => {
            const label = (th.textContent || '').trim().toLowerCase();
            if (label === 'options' || label === 'module access') {
                return;
            }
            th.classList.add('sortable');
            th.addEventListener('click', () => {
                if (sortIndex === index) {
                    sortDir *= -1;
                } else {
                    sortIndex = index;
                    sortDir = 1;
                }
                render();
            });
        });

        searchInput?.addEventListener('input', () => {
            searchValue = searchInput.value.trim().toLowerCase();
            page = 1;
            render();
        });

        sizeSelect?.addEventListener('change', () => {
            pageSize = Math.max(1, Number(sizeSelect.value || 10));
            page = 1;
            render();
        });

        render();
    };

    document.querySelectorAll('.js-datatable').forEach((table) => initDataTable(table));

    if (<?= json_encode($view, JSON_UNESCAPED_UNICODE) ?> !== 'entity') {
        return;
    }

    const fields = <?= json_encode($fields, JSON_UNESCAPED_UNICODE) ?>;
    const pk = <?= json_encode($pk, JSON_UNESCAPED_UNICODE) ?>;
    const backdrop = document.getElementById('recordModalBackdrop');
    const actionField = document.getElementById('recordAction');
    const pkField = document.getElementById('recordPk');
    const title = document.getElementById('recordModalTitle');
    const submitBtn = document.getElementById('recordSubmitBtn');
    const deleteId = document.getElementById('inlineDeleteId');
    const deleteForm = document.getElementById('inlineDeleteForm');

    const openRecordModal = (mode, record) => {
        const current = record || {};
        actionField.value = mode;
        pkField.value = mode === 'update' ? (current[pk] ?? '') : '';
        title.textContent = mode === 'update' ? 'Edit Record' : 'Create Record';
        submitBtn.textContent = mode === 'update' ? 'Update Record' : 'Create Record';
        fields.forEach((field) => {
            const element = document.getElementById('field_' + field);
            if (element) {
                element.value = mode === 'update' ? (current[field] ?? '') : '';
            }
        });
        backdrop.classList.add('show');
    };

    document.getElementById('openCreateBtn')?.addEventListener('click', () => openRecordModal('create', {}));
    document.getElementById('closeRecordModal')?.addEventListener('click', () => backdrop.classList.remove('show'));
    document.getElementById('cancelRecordModal')?.addEventListener('click', () => backdrop.classList.remove('show'));
    backdrop?.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            backdrop.classList.remove('show');
        }
    });

    document.querySelectorAll('.action-select').forEach((select) => {
        select.addEventListener('change', () => {
            let record = {};
            try {
                record = JSON.parse(select.dataset.record || '{}');
            } catch (error) {
                record = {};
            }
            if (select.value === 'edit') {
                openRecordModal('update', record);
            }
            if (select.value === 'delete') {
                if (confirm('Delete this record?')) {
                    deleteId.value = select.dataset.id || '';
                    deleteForm.submit();
                }
            }
            select.value = '';
        });
    });
})();
</script>
</body>
</html>
