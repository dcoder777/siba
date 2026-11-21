<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'student_lifecycle');

$pageTitle = 'Student Lifecycle & 360° Directory';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Handle Post Actions (Disciplinary Remark, Status Update) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'add_remark') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $remarkType = trim((string) ($_POST['remark_type'] ?? 'General'));
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $remarks = trim((string) ($_POST['remarks'] ?? ''));
            $parentNotify = isset($_POST['parent_notification_required']) ? 1 : 0;

            if ($studentId <= 0 || $remarks === '') {
                throw new \RuntimeException('Student and remark text are required.');
            }

            $stmt = $pdo->prepare("INSERT INTO student_remarks_discipline 
                (student_id, remark_date, remark_type, subject, remarks, parent_notification_required, entered_by) 
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?)");
            $stmt->execute([$studentId, $remarkType, $subject, $remarks, $parentNotify, $user['name'] ?? 'Admin']);

            $success = 'Remark / disciplinary entry recorded successfully.';
        }

        if ($action === 'update_status') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $newStatus = trim((string) ($_POST['status'] ?? 'active'));
            $allowed = ['active', 'promoted', 'transferred', 'withdrawn', 'alumni'];
            if ($studentId > 0 && in_array($newStatus, $allowed, true)) {
                $pdo->prepare("UPDATE student_enrollments SET status = ?, updated_at = NOW() WHERE student_id = ? AND is_current = 1")
                    ->execute([$newStatus, $studentId]);
                $success = "Student enrollment status updated to '{$newStatus}'.";
            }
        }

        if ($action === 'soft_delete_student' && ($canDelete || $isOwner)) {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if ($studentId > 0) {
                $pdo->prepare("UPDATE students SET deleted_at = NOW() WHERE id = ?")->execute([$studentId]);
                $success = 'Student record has been moved to soft-deleted archive. Filter by "Soft-Deleted" to review or restore.';
            }
        }

        if ($action === 'restore_student' && ($canDelete || $isOwner)) {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if ($studentId > 0) {
                $pdo->prepare("UPDATE students SET deleted_at = NULL WHERE id = ?")->execute([$studentId]);
                $success = 'Student record has been successfully restored.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: student-lifecycle.php?success=' . urlencode($success));
        exit;
    }
}

// ── Search & Filter State ──
$search = trim((string) ($_GET['q'] ?? ''));
$filterClass = trim((string) ($_GET['class'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? 'active'));
$filterHouse = trim((string) ($_GET['house'] ?? ''));

// Classes list for dropdown
$classList = $pdo->query("SELECT DISTINCT class_name FROM student_enrollments WHERE class_name IS NOT NULL AND class_name <> '' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$houseList = $pdo->query("SELECT id, house_name, color_code FROM houses WHERE status = 'Active' ORDER BY house_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Base Query with 360-degree joins
$whereSql = " WHERE 1=1";
$params = [];

if ($filterStatus === 'deleted') {
    $whereSql .= " AND s.deleted_at IS NOT NULL";
} else {
    $whereSql .= " AND s.deleted_at IS NULL";
    if ($filterStatus !== '') {
        $whereSql .= " AND e.status = :st";
        $params['st'] = $filterStatus;
    }
}

if ($search !== '') {
    $whereSql .= " AND (s.admission_no LIKE :q OR s.first_name LIKE :q OR s.last_name LIKE :q OR s.phone LIKE :q)";
    $params['q'] = '%' . $search . '%';
}
if ($filterClass !== '') {
    $whereSql .= " AND e.class_name = :class";
    $params['class'] = $filterClass;
}
if ($filterHouse !== '') {
    $whereSql .= " AND sha.house_id = :house";
    $params['house'] = (int) $filterHouse;
}

$query = "
    SELECT 
        s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.dob, s.phone, s.email, s.blood_group, s.deleted_at,
        e.class_name, e.section_name, e.session_label, e.status as enrollment_status,
        h.house_name, h.color_code as house_color, sha.role as house_role,
        tr.route_name, ta.pickup_stop,
        (SELECT COUNT(*) FROM student_remarks_discipline WHERE student_id = s.id) as remarks_count,
        (SELECT COUNT(*) FROM student_certificates WHERE student_id = s.id) as certs_count,
        (SELECT COALESCE(SUM(amount), 0) FROM student_fee_dues WHERE student_id = s.id AND status <> 'paid') as fee_due
    FROM students s
    LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
    LEFT JOIN student_house_allocations sha ON sha.student_id = s.id AND sha.status = 'Active'
    LEFT JOIN houses h ON h.id = sha.house_id
    LEFT JOIN transport_assignments ta ON ta.student_id = s.id AND ta.status = 'Active'
    LEFT JOIN transport_routes tr ON tr.id = ta.route_id
    {$whereSql}
    ORDER BY s.id DESC
    LIMIT 100
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load recent remarks for loaded students
$studentIds = array_filter(array_column($students, 'id'));
$remarksByStudent = [];
if (!empty($studentIds)) {
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $rStmt = $pdo->prepare("
        SELECT id, student_id, remark_date, remark_type, subject, remarks, parent_notification_required, entered_by, created_at
        FROM student_remarks_discipline
        WHERE student_id IN ($placeholders)
        ORDER BY id DESC
    ");
    $rStmt->execute(array_values($studentIds));
    while ($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
        $remarksByStudent[$r['student_id']][] = $r;
    }
}
foreach ($students as &$st) {
    $st['remarks_list'] = $remarksByStudent[$st['id']] ?? [];
}
unset($st);

// Overall Lifecycle KPIs
$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL")->fetchColumn();
$activeEnrolled = (int) $pdo->query("SELECT COUNT(*) FROM student_enrollments se JOIN students s ON s.id = se.student_id WHERE se.is_current = 1 AND se.status = 'active' AND s.deleted_at IS NULL")->fetchColumn();
$housesAssignedCount = (int) $pdo->query("SELECT COUNT(*) FROM student_house_allocations sha JOIN students s ON s.id = sha.student_id WHERE sha.status = 'Active' AND s.deleted_at IS NULL")->fetchColumn();
$remarksLoggedCount = (int) $pdo->query("SELECT COUNT(*) FROM student_remarks_discipline srd JOIN students s ON s.id = srd.student_id WHERE s.deleted_at IS NULL")->fetchColumn();
$deletedStudentsCount = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NOT NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        /* Prominent Horizontal & Vertical Scroller for Student Profiles */
        .student-table-scroll-wrap, #topScrollContainer {
            scrollbar-width: thin;
            scrollbar-color: #2563eb #e2e8f0;
        }
        .student-table-scroll-wrap::-webkit-scrollbar, #topScrollContainer::-webkit-scrollbar {
            height: 12px;
            width: 8px;
        }
        .student-table-scroll-wrap::-webkit-scrollbar-track, #topScrollContainer::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 6px;
        }
        .student-table-scroll-wrap::-webkit-scrollbar-thumb, #topScrollContainer::-webkit-scrollbar-thumb {
            background: #2563eb;
            border-radius: 6px;
            border: 2px solid #f1f5f9;
        }
        .student-table-scroll-wrap::-webkit-scrollbar-thumb:hover, #topScrollContainer::-webkit-scrollbar-thumb:hover {
            background: #1d4ed8;
        }
        #studentProfilesTable {
            min-width: 1250px;
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }
        #studentProfilesTable th, #studentProfilesTable td {
            white-space: nowrap;
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }
        #studentProfilesTable thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8fafc;
            border-bottom: 2px solid #cbd5e1;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
        }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem; min-width:0; max-width:100%; overflow-x:hidden;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.4rem">
                    <span class="eyebrow">Academic Administration</span>
                    <h1>Student Lifecycle & 360° Dossier</h1>
                    <p>Track student profiles from admission to alumni, academic allocations, and disciplinary logs.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <a href="student-promotions.php" class="btn btn-sm btn-outline">🚀 Session Promotions</a>
                    <a href="student-certificates.php" class="btn btn-sm btn-outline">📜 Certificate Generator</a>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- KPI Summary Cards -->
        <div class="kpi-grid" style="margin-bottom:1.25rem;">
            <div class="kpi-card">
                <div class="kpi-label">Total Student Records</div>
                <div class="kpi-value"><?= $totalStudents ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Active student directory</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Currently Enrolled</div>
                <div class="kpi-value" style="color:#0284c7;"><?= $activeEnrolled ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Active students in current session</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">House Allocations</div>
                <div class="kpi-value"><?= $housesAssignedCount ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Active house assignments</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Disciplinary Logs</div>
                <div class="kpi-value"><?= $remarksLoggedCount ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Behavioral & staff records</div>
            </div>
            <div class="kpi-card" style="<?= $deletedStudentsCount > 0 ? 'border:1px solid #fca5a5;background:#fff5f5;' : '' ?>">
                <div class="kpi-label" style="<?= $deletedStudentsCount > 0 ? 'color:#b91c1c;' : '' ?>">Soft-Deleted Records</div>
                <div class="kpi-value" style="color:<?= $deletedStudentsCount > 0 ? '#dc2626' : '#64748b' ?>;"><?= $deletedStudentsCount ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">
                    <?php if ($deletedStudentsCount > 0): ?>
                        <a href="student-lifecycle.php?status=deleted" style="color:#dc2626;font-weight:600;text-decoration:underline;">View soft-deleted</a>
                    <?php else: ?>
                        No archived records
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <form method="get" action="student-lifecycle.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:200px;">
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by Admission No, Name, or Phone..." style="width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                </div>
                <div>
                    <select name="class" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Classes</option>
                        <?php foreach ($classList as $c): ?>
                            <option value="<?= e($c) ?>" <?= $filterClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select name="house" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Houses</option>
                        <?php foreach ($houseList as $h): ?>
                            <option value="<?= (int)$h['id'] ?>" <?= (string)$filterHouse === (string)$h['id'] ? 'selected' : '' ?>><?= e($h['house_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select name="status" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Statuses (Active)</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="promoted" <?= $filterStatus === 'promoted' ? 'selected' : '' ?>>Promoted</option>
                        <option value="transferred" <?= $filterStatus === 'transferred' ? 'selected' : '' ?>>Transferred</option>
                        <option value="withdrawn" <?= $filterStatus === 'withdrawn' ? 'selected' : '' ?>>Withdrawn</option>
                        <option value="alumni" <?= $filterStatus === 'alumni' ? 'selected' : '' ?>>Alumni</option>
                        <option value="deleted" <?= $filterStatus === 'deleted' ? 'selected' : '' ?>>🗑 Soft-Deleted / Archived (<?= $deletedStudentsCount ?>)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <?php if ($search !== '' || $filterClass !== '' || $filterStatus !== '' || $filterHouse !== ''): ?>
                    <a href="student-lifecycle.php" class="btn btn-sm btn-soft">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Student Table -->
        <div class="panel" style="min-width:0; max-width:100%; width:100%; overflow:hidden; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,0.06); border:1px solid #e2e8f0;">
            <div style="padding:0.85rem 1.25rem; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; background:#f8fafc;">
                <div style="display:flex; align-items:center; gap:0.6rem;">
                    <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:#0f172a;">
                        <?= $filterStatus === 'deleted' ? 'Soft-Deleted Students' : 'Student Profiles' ?> (<?= count($students) ?>)
                    </h3>
                    <span style="font-size:.78rem; color:<?= $filterStatus === 'deleted' ? '#991b1b' : '#475569' ?>; background:<?= $filterStatus === 'deleted' ? '#fee2e2' : '#e2e8f0' ?>; padding:2px 8px; border-radius:12px; font-weight:600;">
                        <?= $filterStatus === 'deleted' ? '🗑 Soft-Deleted / Archived' : 'Active &amp; Historical' ?>
                    </span>
                </div>
                <div style="display:flex; align-items:center; gap:.5rem;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="scrollStudentTable(-280)" title="Scroll Left" style="padding:3px 10px; font-weight:700; font-size:.8rem;">
                        ◀ Left
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="scrollStudentTable(280)" title="Scroll Right" style="padding:3px 10px; font-weight:700; font-size:.8rem;">
                        Right ▶
                    </button>
                </div>
            </div>

            <?php if (empty($students)): ?>
                <div style="padding:2.5rem; text-align:center; color:#94a3b8;">
                    No student records match your query.
                </div>
            <?php else: ?>
                <!-- Top Horizontal Scroller Bar (Synchronized) -->
                <div id="topScrollContainer" style="overflow-x:auto; overflow-y:hidden; width:100%; height:14px; background:#f1f5f9; border-bottom:1px solid #e2e8f0;">
                    <div id="topScrollDummy" style="height:14px;"></div>
                </div>

                <!-- Main Table Scroll Container -->
                <div id="studentTableContainer" class="student-table-scroll-wrap" style="overflow-x:auto; overflow-y:auto; max-height:72vh; width:100%; -webkit-overflow-scrolling:touch;">
                    <table class="app-table" id="studentProfilesTable">
                        <thead>
                            <tr>
                                <th>Adm No</th>
                                <th>Student Name</th>
                                <th>Class & Sec</th>
                                <th>House</th>
                                <th>Transport</th>
                                <th>Fee Due</th>
                                <th>Remarks</th>
                                <th>Status</th>
                                <th style="text-align:center; position:sticky; right:0; background:#f8fafc; box-shadow:-2px 0 4px rgba(0,0,0,0.03);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $s): ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($s['admission_no']) ?></td>
                                    <td>
                                        <div style="font-weight:700;color:#0f172a;"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
                                        <div style="font-size:.78rem;color:#64748b;"><?= e($s['gender'] ?: '—') ?> | DOB: <?= e($s['dob'] ?: '—') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= e($s['class_name'] ?: 'Not Enrolled') ?></strong>
                                        <?= $s['section_name'] ? ('- ' . e($s['section_name'])) : '' ?>
                                        <div style="font-size:.75rem;color:#64748b;"><?= e($s['session_label'] ?: 'Current') ?></div>
                                    </td>
                                    <td>
                                        <?php if ($s['house_name']): ?>
                                            <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .5rem;border-radius:12px;font-size:.75rem;font-weight:600;background:<?= e($s['house_color'] ?? '#e2e8f0') ?>20;color:<?= e($s['house_color'] ?? '#334155') ?>;border:1px solid <?= e($s['house_color'] ?? '#cbd5e1') ?>;">
                                                <span style="width:8px;height:8px;border-radius:50%;background:<?= e($s['house_color'] ?? '#3b82f6') ?>;"></span>
                                                <?= e($s['house_name']) ?>
                                            </span>
                                            <?php if ($s['house_role'] && $s['house_role'] !== 'Member'): ?>
                                                <div style="font-size:.7rem;color:#4338ca;font-weight:600;"><?= e($s['house_role']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:.8rem;">Unallocated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s['route_name']): ?>
                                            <div style="font-size:.82rem;font-weight:600;"><?= e($s['route_name']) ?></div>
                                            <div style="font-size:.75rem;color:#64748b;"><?= e($s['pickup_stop'] ?: 'Stop') ?></div>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:.8rem;">Self / No Transport</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $due = (float) $s['fee_due']; ?>
                                        <?php if ($due > 0): ?>
                                            <span style="color:#dc2626;font-weight:700;">₹ <?= number_format($due, 2) ?></span>
                                        <?php else: ?>
                                            <span style="color:#16a34a;font-weight:600;">✓ Cleared</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s['remarks_count'] > 0): ?>
                                            <button type="button" class="btn btn-sm" onclick='viewStudentRemarks(<?= json_encode($s) ?>)' style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:.78rem;font-weight:700;padding:.2rem .55rem;border-radius:12px;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem;" title="Click to view full remarks">
                                                👁️ <?= $s['remarks_count'] ?> remark(s)
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm" onclick="openRemarkModal(<?= (int)$s['id'] ?>, '<?= e(addslashes($s['first_name'] . ' ' . $s['last_name'])) ?>')" style="color:#94a3b8;font-size:.8rem;padding:2px 8px;background:transparent;border:1px dashed #cbd5e1;border-radius:10px;" title="Click to add a remark">
                                                0 <span style="font-size:.75rem;color:#64748b;">+</span>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['deleted_at'])): ?>
                                            <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;font-weight:700;">
                                                🗑 Soft-Deleted
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="
                                                <?php
                                                 echo match($s['enrollment_status']) {
                                                     'active' => 'background:#d1fae5;color:#065f46;',
                                                     'promoted' => 'background:#dbeafe;color:#1e40af;',
                                                     'transferred' => 'background:#fef3c7;color:#92400e;',
                                                     'withdrawn' => 'background:#fee2e2;color:#991b1b;',
                                                     'alumni' => 'background:#f1f5f9;color:#475569;',
                                                     default => 'background:#f1f5f9;color:#475569;',
                                                 };
                                                ?>
                                            ">
                                                <?= ucfirst(e($s['enrollment_status'] ?: 'Active')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center; position:sticky; right:0; background:#fff; box-shadow:-2px 0 4px rgba(0,0,0,0.03);">
                                        <div style="display:flex;gap:.35rem;justify-content:center;align-items:center;">
                                            <button type="button" class="btn btn-sm btn-soft" onclick='openStudent360(<?= json_encode($s) ?>)'>
                                                🔍 360° View
                                            </button>
                                            <?php if (empty($s['deleted_at'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline" onclick="openRemarkModal(<?= (int)$s['id'] ?>, '<?= e(addslashes($s['first_name'] . ' ' . $s['last_name'])) ?>')">
                                                    + Remark
                                                </button>
                                                <a href="student-certificates.php?student_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline" title="Issue Certificate">
                                                    📜
                                                </a>
                                                <?php if ($canDelete || $isOwner): ?>
                                                    <form method="post" action="student-lifecycle.php" style="display:inline;margin:0;" onsubmit="return confirm('Soft-delete student <?= e(addslashes($s['first_name'] . ' ' . $s['last_name'])) ?> (<?= e($s['admission_no']) ?>)? You can restore them anytime.');">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="soft_delete_student">
                                                        <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn btn-sm" style="padding:.25rem .5rem;background:#ef4444;color:#fff;border:none;border-radius:4px;" title="Soft Delete Student">🗑</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($canDelete || $isOwner): ?>
                                                    <form method="post" action="student-lifecycle.php" style="display:inline;margin:0;" onsubmit="return confirm('Restore student <?= e(addslashes($s['first_name'] . ' ' . $s['last_name'])) ?> (<?= e($s['admission_no']) ?>)?');">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="restore_student">
                                                        <input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn btn-sm" style="padding:.25rem .6rem;background:#059669;color:#fff;border:none;border-radius:4px;font-weight:600;" title="Restore Student">↺ Restore</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="padding:0.6rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; align-items:center; font-size:.78rem; color:#64748b;">
                    <span>Showing <?= count($students) ?> students</span>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ STUDENT 360° DOSSIER MODAL ═══════════════════ -->
<div id="student360Modal" class="modal-backdrop">
    <div class="modal-box" style="max-width:780px;width:94%;max-height:90vh;display:flex;flex-direction:column;background:#fff;border-radius:16px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head" style="padding:1.15rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#ffffff;display:flex;justify-content:space-between;align-items:center;">
            <div id="dossierTitle" style="margin:0;">
                <h2 style="margin:0;font-size:1.15rem;font-weight:700;">Student 360° Dossier</h2>
            </div>
            <button type="button" class="icon-btn" onclick="closeModal('student360Modal')">✕</button>
        </div>
        <div style="padding:1.5rem;background:#fff;flex:1;overflow-y:auto;" id="dossierBody">
            <!-- Populated via JS -->
        </div>
        <div id="dossierFooter" style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;background:#f8fafc;">
            <div style="display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('student360Modal')">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ ADD REMARK MODAL ═══════════════════ -->
<div id="remarkModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:540px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="remarkModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Record Disciplinary / Behavioral Remark</h2>
            <button type="button" class="icon-btn" onclick="closeModal('remarkModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_remark">
            <input type="hidden" name="student_id" id="remarkStudentId" value="">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Remark Type *</label>
                <select name="remark_type" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                    <option value="General">General Remark</option>
                    <option value="Appreciation">⭐ Appreciation / Award</option>
                    <option value="Discipline">⚠️ Disciplinary Warning</option>
                    <option value="Behaviour">Behavioural Observation</option>
                    <option value="Academic Remark">Academic Progress Remark</option>
                    <option value="Counselling">Counselling Record</option>
                </select>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Subject / Incident Title</label>
                <input type="text" name="subject" placeholder="e.g. Uniform violation / Outstanding debate speech" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Details / Observations *</label>
                <textarea name="remarks" rows="3" required placeholder="Enter detailed note or context..." style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer;">
                    <input type="checkbox" name="parent_notification_required" value="1">
                    <span>Send notification / alert to Parent</span>
                </label>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('remarkModal')">Cancel</button>
                <button type="submit" class="btn btn-sm">Save Remark</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ VIEW REMARKS MODAL ═══════════════════ -->
<div id="remarksHistoryModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:680px;width:94%;max-height:85vh;display:flex;flex-direction:column;background:#fff;border-radius:16px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head" style="padding:1.15rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#ffffff;display:flex;justify-content:space-between;align-items:center;">
            <div id="remarksHistoryTitle" style="margin:0;">
                <h2 style="margin:0;font-size:1.15rem;font-weight:700;">Student Remarks History</h2>
            </div>
            <button type="button" class="icon-btn" onclick="closeModal('remarksHistoryModal')">✕</button>
        </div>
        <div style="padding:1.5rem;background:#fff;flex:1;overflow-y:auto;" id="remarksHistoryBody">
            <!-- Populated via JS -->
        </div>
        <div id="remarksHistoryFooter" style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;">
            <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('remarksHistoryModal')">Close</button>
        </div>
    </div>
</div>

<script>
var csrfToken = '<?= e(csrf_token()) ?>';
var canManageDelete = <?= ($canDelete || $isOwner) ? 'true' : 'false' ?>;

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeJs(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
function openRemarkModal(studentId, studentName) {
    document.getElementById('remarkStudentId').value = studentId;
    document.getElementById('remarkModalTitle').textContent = 'Remark for ' + studentName;
    openModal('remarkModal');
}
function viewStudentRemarks(s) {
    var fullName = ((s.first_name || '') + ' ' + (s.last_name || '')).trim() || 'Student';
    var admNo = s.admission_no || '—';

    document.getElementById('remarksHistoryTitle').innerHTML = `
        <div style="display:flex;align-items:center;gap:.6rem;">
            <span style="font-size:1.3rem;">📝</span>
            <div>
                <h2 style="margin:0;font-size:1.1rem;font-weight:700;color:#0f172a;">
                    Remarks: ${escapeHtml(fullName)}
                </h2>
                <div style="font-size:.78rem;color:#64748b;margin-top:.15rem;">
                    Admission No: <strong style="font-family:monospace;color:#1e40af;">${escapeHtml(admNo)}</strong>
                    <span style="margin:0 .35rem;">•</span>
                    <span>${(s.remarks_list || []).length} recorded remark(s)</span>
                </div>
            </div>
        </div>
    `;

    var list = s.remarks_list || [];
    var body = document.getElementById('remarksHistoryBody');

    if (list.length === 0) {
        body.innerHTML = `
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:2rem;text-align:center;color:#64748b;">
                <div style="font-size:1.6rem;margin-bottom:.5rem;">📋</div>
                <div style="font-weight:600;color:#334155;margin-bottom:.25rem;">No remarks recorded</div>
                <div style="font-size:.82rem;">No disciplinary or behavioral notes have been recorded for this student yet.</div>
            </div>
        `;
    } else {
        body.innerHTML = `
            <div style="display:flex;flex-direction:column;gap:.75rem;">
                ${list.map(function(r) {
                    var badgeColor = 'background:#f1f5f9;color:#475569;';
                    if (r.remark_type === 'Appreciation') badgeColor = 'background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;';
                    else if (r.remark_type === 'Discipline' || r.remark_type === 'Warning') badgeColor = 'background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;';
                    else if (r.remark_type === 'Behaviour') badgeColor = 'background:#fef3c7;color:#b45309;border:1px solid #fde68a;';
                    else if (r.remark_type === 'Academic Remark' || r.remark_type === 'Counselling') badgeColor = 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;';

                    return `
                        <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:1rem 1.15rem;box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.5rem;flex-wrap:wrap;gap:.4rem;">
                                <div style="display:flex;align-items:center;gap:.5rem;">
                                    <span class="badge" style="${badgeColor}font-size:.75rem;font-weight:700;padding:.25rem .55rem;border-radius:6px;">
                                        ${escapeHtml(r.remark_type)}
                                    </span>
                                    <strong style="font-size:.92rem;color:#0f172a;">${escapeHtml(r.subject || 'Remark Entry')}</strong>
                                </div>
                                <span style="font-size:.75rem;color:#64748b;font-weight:500;">
                                    📅 ${escapeHtml(r.remark_date || '')}
                                </span>
                            </div>
                            <div style="font-size:.875rem;color:#334155;line-height:1.5;margin-bottom:.65rem;background:#f8fafc;padding:.75rem;border-radius:6px;border-left:3px solid #cbd5e1;white-space:pre-wrap;">${escapeHtml(r.remarks)}</div>
                            <div style="font-size:.72rem;color:#64748b;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
                                <span>👤 Recorded by: <strong>${escapeHtml(r.entered_by || 'Staff')}</strong></span>
                                ${parseInt(r.parent_notification_required) === 1 ? '<span style="color:#2563eb;font-weight:600;">✉️ Parent notification requested</span>' : '<span style="color:#94a3b8;">No parent notification</span>'}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    var footer = document.getElementById('remarksHistoryFooter');
    if (footer) {
        footer.innerHTML = `
            <button type="button" class="btn btn-sm btn-primary" onclick="closeModal('remarksHistoryModal'); openRemarkModal(${s.id}, '${escapeJs(fullName)}');">
                + Add Another Remark
            </button>
            <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('remarksHistoryModal')">Close</button>
        `;
    }

    openModal('remarksHistoryModal');
}
function openStudent360(s) {
    var fullName = ((s.first_name || '') + ' ' + (s.last_name || '')).trim() || 'Student';
    var admNo = s.admission_no || '—';
    var initials = ((s.first_name ? s.first_name[0] : '') + (s.last_name ? s.last_name[0] : '')).toUpperCase() || 'ST';
    var isDeleted = Boolean(s.deleted_at);
    var status = isDeleted ? 'Soft-Deleted' : (s.enrollment_status || 'active').toLowerCase();

    var statusBadgeStyle = isDeleted ? 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;' : 'background:#d1fae5;color:#065f46;';
    if (!isDeleted) {
        if (status === 'promoted') statusBadgeStyle = 'background:#dbeafe;color:#1e40af;';
        else if (status === 'transferred') statusBadgeStyle = 'background:#fef3c7;color:#92400e;';
        else if (status === 'withdrawn') statusBadgeStyle = 'background:#fee2e2;color:#991b1b;';
        else if (status === 'alumni') statusBadgeStyle = 'background:#f1f5f9;color:#475569;';
    }

    document.getElementById('dossierTitle').innerHTML = `
        <div style="display:flex;align-items:center;gap:.85rem;">
            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.15rem;box-shadow:0 3px 8px rgba(37,99,235,.25);flex-shrink:0;">
                ${escapeHtml(initials)}
            </div>
            <div>
                <div style="font-size:1.15rem;font-weight:700;color:#0f172a;line-height:1.2;">
                    ${escapeHtml(fullName)}
                </div>
                <div style="font-size:.8rem;color:#64748b;margin-top:.2rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                    <span>Admission No: <strong style="font-family:monospace;color:#1e40af;">${escapeHtml(admNo)}</strong></span>
                    <span>•</span>
                    <span class="badge" style="${statusBadgeStyle}font-size:.72rem;padding:.2rem .55rem;border-radius:20px;text-transform:capitalize;font-weight:700;">
                        ${isDeleted ? '🗑 Soft-Deleted' : escapeHtml(status)}
                    </span>
                </div>
            </div>
        </div>
    `;

    var feeDue = parseFloat(s.fee_due || 0);
    var feeBadge = feeDue > 0
        ? `<div style="font-size:1.15rem;font-weight:700;color:#dc2626;">₹ ${feeDue.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div><div style="font-size:.72rem;color:#dc2626;">Payment pending</div>`
        : `<div style="font-size:1.05rem;font-weight:700;color:#16a34a;">✓ No Dues Pending</div><div style="font-size:.72rem;color:#16a34a;">Fee account in good standing</div>`;

    var houseName = s.house_name || 'Unallocated';
    var houseColor = s.house_color || '#64748b';
    var houseRole = s.house_role || 'Member';

    var routeName = s.route_name || 'Self Travel';
    var pickupStop = s.pickup_stop ? ('Stop: ' + s.pickup_stop) : (s.route_name ? 'Standard Route Stop' : 'No school bus allocated');

    var deletedBanner = isDeleted ? `
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:.85rem 1.15rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.3rem;">🗑</span>
                <div>
                    <strong style="color:#991b1b;font-size:.9rem;">Soft-Deleted / Archived Student Record</strong>
                    <div style="font-size:.78rem;color:#7f1d1d;">Archived on ${escapeHtml(s.deleted_at)}. Excluded from active student rosters and KPIs. All historical academic & financial data is retained.</div>
                </div>
            </div>
            ${canManageDelete ? `
                <form method="post" action="student-lifecycle.php" style="margin:0;" onsubmit="return confirm('Restore this student back to the active directory?');">
                    <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                    <input type="hidden" name="action" value="restore_student">
                    <input type="hidden" name="student_id" value="${s.id}">
                    <button type="submit" class="btn btn-sm" style="background:#059669;color:#fff;border:none;font-weight:600;padding:.3rem .75rem;">↺ Restore Record</button>
                </form>
            ` : ''}
        </div>
    ` : '';

    var body = document.getElementById('dossierBody');
    body.innerHTML = `
        ${deletedBanner}
        <!-- Section 1: Personal & Contact Information -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1.15rem;margin-bottom:1.25rem;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem;">
                <span>👤</span> Personal & Contact Profile
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:1rem;">
                <div>
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Full Name</div>
                    <div style="font-weight:700;color:#0f172a;font-size:.95rem;">${escapeHtml(fullName)}</div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Admission Number</div>
                    <div style="font-weight:700;font-family:monospace;color:#1e40af;font-size:.95rem;">${escapeHtml(admNo)}</div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Gender / Date of Birth</div>
                    <div style="font-weight:600;color:#334155;font-size:.9rem;">
                        ${escapeHtml(s.gender || '—')} &nbsp;|&nbsp; ${escapeHtml(s.dob || '—')}
                    </div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Blood Group</div>
                    <div>
                        <span class="badge" style="background:#fee2e2;color:#991b1b;font-weight:700;font-size:.78rem;padding:.25rem .5rem;border-radius:6px;">
                            ${escapeHtml(s.blood_group || 'Not recorded')}
                        </span>
                    </div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Contact Phone</div>
                    <div style="font-weight:600;color:#334155;font-size:.9rem;">
                        ${s.phone ? ('📞 ' + escapeHtml(s.phone)) : '<span style="color:#94a3b8;">Not provided</span>'}
                    </div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.15rem;">Parent / Contact Email</div>
                    <div style="font-weight:600;color:#334155;font-size:.9rem;">
                        ${s.email ? ('✉️ ' + escapeHtml(s.email)) : '<span style="color:#94a3b8;">Not provided</span>'}
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Academic & Campus Allocations -->
        <div style="margin-bottom:1.25rem;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem;">
                <span>🏫</span> Academic & Campus Allocations
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:1rem;">
                <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:1rem;border-left:4px solid #3b82f6;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Enrolled Class & Section</div>
                    <div style="font-size:1.1rem;font-weight:700;color:#1e3a8a;">
                        ${escapeHtml(s.class_name || '—')} ${s.section_name ? ('(' + escapeHtml(s.section_name) + ')') : ''}
                    </div>
                    <div style="font-size:.75rem;color:#64748b;margin-top:.35rem;">
                        Session: <strong>${escapeHtml(s.session_label || 'Current')}</strong>
                    </div>
                </div>
                <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:1rem;border-left:4px solid ${escapeHtml(houseColor)};box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.25rem;">House Management</div>
                    <div style="font-size:1.1rem;font-weight:700;color:${escapeHtml(houseColor)};">
                        ${escapeHtml(houseName)}
                    </div>
                    <div style="font-size:.75rem;color:#64748b;margin-top:.35rem;">
                        Role: <strong>${escapeHtml(houseRole)}</strong>
                    </div>
                </div>
                <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:1rem;border-left:4px solid #f59e0b;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Operations & Fleet</div>
                    <div style="font-size:1.05rem;font-weight:700;color:#0f172a;">
                        ${escapeHtml(routeName)}
                    </div>
                    <div style="font-size:.75rem;color:#64748b;margin-top:.35rem;">
                        ${escapeHtml(pickupStop)}
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Standing, Dues & Records -->
        <div style="margin-bottom:.5rem;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem;">
                <span>📊</span> Financial & Administrative Records
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));gap:1rem;">
                <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.35rem;">Fee Dues Status</div>
                    ${feeBadge}
                </div>
                <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.35rem;">Official Certificates</div>
                    <div style="font-size:1.15rem;font-weight:700;color:#0f172a;">
                        ${parseInt(s.certs_count || 0)} Issued
                    </div>
                    <div style="font-size:.72rem;color:#64748b;margin-top:.2rem;">TC, Bonafide, Character</div>
                </div>
                <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.35rem;">Discipline & Progress</div>
                    <div style="font-size:1.15rem;font-weight:700;color:${(parseInt(s.remarks_count || 0) > 0) ? '#d97706' : '#0f172a'};">
                        ${parseInt(s.remarks_count || 0)} Logged
                    </div>
                    <div style="font-size:.72rem;color:#64748b;margin-top:.2rem;">Staff remarks on profile</div>
                </div>
            </div>
        </div>

        <!-- Section 4: Behavioral & Disciplinary Remarks History -->
        <div style="margin-top:1.25rem;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin-bottom:.75rem;display:flex;align-items:center;justify-content:space-between;">
                <span style="display:flex;align-items:center;gap:.4rem;">
                    <span>📝</span> Logged Remarks & Observations (${(s.remarks_list || []).length})
                </span>
                <button type="button" class="btn btn-sm btn-outline" style="font-size:.75rem;padding:.2rem .5rem;" onclick="closeModal('student360Modal'); openRemarkModal(${s.id}, '${escapeJs(fullName)}');">
                    + Add New Remark
                </button>
            </div>
            ${(s.remarks_list && s.remarks_list.length > 0) ? `
                <div style="display:flex;flex-direction:column;gap:.6rem;max-height:220px;overflow-y:auto;padding-right:.25rem;">
                    ${s.remarks_list.map(function(r) {
                        var badgeColor = 'background:#f1f5f9;color:#475569;';
                        if (r.remark_type === 'Appreciation') badgeColor = 'background:#dcfce7;color:#15803d;';
                        else if (r.remark_type === 'Discipline' || r.remark_type === 'Warning') badgeColor = 'background:#fee2e2;color:#b91c1c;';
                        else if (r.remark_type === 'Behaviour') badgeColor = 'background:#fef3c7;color:#b45309;';
                        else if (r.remark_type === 'Academic Remark' || r.remark_type === 'Counselling') badgeColor = 'background:#e0f2fe;color:#0369a1;';

                        return `
                            <div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:8px;padding:.8rem .95rem;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem;flex-wrap:wrap;gap:.35rem;">
                                    <div style="display:flex;align-items:center;gap:.5rem;">
                                        <span class="badge" style="${badgeColor}font-size:.72rem;font-weight:700;padding:.2rem .5rem;border-radius:4px;">
                                            ${escapeHtml(r.remark_type)}
                                        </span>
                                        <strong style="font-size:.85rem;color:#0f172a;">${escapeHtml(r.subject || 'Remark Entry')}</strong>
                                    </div>
                                    <span style="font-size:.72rem;color:#64748b;">${escapeHtml(r.remark_date || '')}</span>
                                </div>
                                <div style="font-size:.82rem;color:#334155;line-height:1.4;margin-bottom:.35rem;white-space:pre-wrap;">${escapeHtml(r.remarks)}</div>
                                <div style="font-size:.7rem;color:#94a3b8;display:flex;gap:.75rem;align-items:center;">
                                    <span>👤 By: <strong>${escapeHtml(r.entered_by || 'Staff')}</strong></span>
                                    ${parseInt(r.parent_notification_required) === 1 ? '<span style="color:#2563eb;">✉️ Parent notification requested</span>' : ''}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            ` : `
                <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:1.1rem;text-align:center;color:#64748b;font-size:.82rem;">
                    No remarks recorded yet for this student. Use the "<strong>+ Remark</strong>" button to log disciplinary or behavioral notes.
                </div>
            `}
        </div>
    `;

    var footer = document.getElementById('dossierFooter');
    if (footer) {
        var actionButtons = '';
        if (isDeleted) {
            actionButtons = canManageDelete ? `
                <form method="post" action="student-lifecycle.php" style="margin:0;display:inline;" onsubmit="return confirm('Restore this student back to the active directory?');">
                    <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                    <input type="hidden" name="action" value="restore_student">
                    <input type="hidden" name="student_id" value="${s.id}">
                    <button type="submit" class="btn btn-sm" style="background:#059669;color:#fff;border:none;font-weight:600;padding:.35rem .75rem;">↺ Restore Student</button>
                </form>
            ` : '';
        } else {
            actionButtons = `
                <a href="student-certificates.php?student_id=${s.id}" class="btn btn-sm btn-outline" style="display:inline-flex;align-items:center;gap:.35rem;">
                    📜 Generate Certificate
                </a>
                <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('student360Modal'); openRemarkModal(${s.id}, '${escapeJs(fullName)}');">
                    ⚠️ Add Remark
                </button>
                <a href="student-promotions.php" class="btn btn-sm btn-soft">
                    🚀 Promote Student
                </a>
                ${canManageDelete ? `
                    <form method="post" action="student-lifecycle.php" style="margin:0;display:inline;" onsubmit="return confirm('Soft-delete student ${escapeJs(fullName)} (${escapeJs(admNo)})? You can restore them anytime.');">
                        <input type="hidden" name="_token" value="${escapeHtml(csrfToken)}">
                        <input type="hidden" name="action" value="soft_delete_student">
                        <input type="hidden" name="student_id" value="${s.id}">
                        <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff;border:none;font-weight:600;padding:.35rem .65rem;" title="Soft Delete Student">🗑 Soft Delete</button>
                    </form>
                ` : ''}
            `;
        }

        footer.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;width:100%;gap:.75rem;flex-wrap:wrap;">
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    ${actionButtons}
                </div>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('student360Modal')">Close</button>
            </div>
        `;
    }

    openModal('student360Modal');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });

    // Synchronize Top Scroller with Main Table Scroller
    var topScroll = document.getElementById('topScrollContainer');
    var topDummy = document.getElementById('topScrollDummy');
    var tableScroll = document.getElementById('studentTableContainer');
    var table = document.getElementById('studentProfilesTable');

    if (topScroll && topDummy && tableScroll && table) {
        function syncWidth() {
            topDummy.style.width = table.scrollWidth + 'px';
        }
        syncWidth();
        window.addEventListener('resize', syncWidth);

        var isSyncingTop = false;
        var isSyncingBottom = false;

        topScroll.addEventListener('scroll', function() {
            if (!isSyncingTop) {
                isSyncingBottom = true;
                tableScroll.scrollLeft = topScroll.scrollLeft;
            }
            isSyncingTop = false;
        });

        tableScroll.addEventListener('scroll', function() {
            if (!isSyncingBottom) {
                isSyncingTop = true;
                topScroll.scrollLeft = tableScroll.scrollLeft;
            }
            isSyncingBottom = false;
        });
    }
});

function scrollStudentTable(delta) {
    var el = document.getElementById('studentTableContainer');
    if (el) {
        el.scrollBy({ left: delta, behavior: 'smooth' });
    }
}
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
