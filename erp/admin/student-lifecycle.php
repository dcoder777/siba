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

if ($search !== '') {
    $whereSql .= " AND (s.admission_no LIKE :q OR s.first_name LIKE :q OR s.last_name LIKE :q OR s.phone LIKE :q)";
    $params['q'] = '%' . $search . '%';
}
if ($filterClass !== '') {
    $whereSql .= " AND e.class_name = :class";
    $params['class'] = $filterClass;
}
if ($filterStatus !== '') {
    $whereSql .= " AND e.status = :st";
    $params['st'] = $filterStatus;
}
if ($filterHouse !== '') {
    $whereSql .= " AND sha.house_id = :house";
    $params['house'] = (int) $filterHouse;
}

$query = "
    SELECT 
        s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.dob, s.phone, s.email, s.blood_group,
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

// Overall Lifecycle KPIs
$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$activeEnrolled = (int) $pdo->query("SELECT COUNT(*) FROM student_enrollments WHERE is_current = 1 AND status = 'active'")->fetchColumn();
$promotedCount = (int) $pdo->query("SELECT COUNT(DISTINCT student_id) FROM student_promotions")->fetchColumn();
$issuedCertsCount = (int) $pdo->query("SELECT COUNT(*) FROM student_certificates WHERE status = 'Issued'")->fetchColumn();
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
                    <p>Track student profiles from admission to alumni, academic promotions, disciplinary logs, and certificate issuance.</p>
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
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">All historical & active admissions</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Currently Enrolled</div>
                <div class="kpi-value" style="color:#0284c7;"><?= $activeEnrolled ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Active students in current session</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Session Promotions</div>
                <div class="kpi-value"><?= $promotedCount ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Students promoted across sessions</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Certificates Issued</div>
                <div class="kpi-value"><?= $issuedCertsCount ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">TC, Bonafide & Character Certs</div>
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
                        <option value="">All Statuses</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="promoted" <?= $filterStatus === 'promoted' ? 'selected' : '' ?>>Promoted</option>
                        <option value="transferred" <?= $filterStatus === 'transferred' ? 'selected' : '' ?>>Transferred</option>
                        <option value="withdrawn" <?= $filterStatus === 'withdrawn' ? 'selected' : '' ?>>Withdrawn</option>
                        <option value="alumni" <?= $filterStatus === 'alumni' ? 'selected' : '' ?>>Alumni</option>
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
                    <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:#0f172a;">Student Profiles (<?= count($students) ?>)</h3>
                    <span style="font-size:.78rem; color:#475569; background:#e2e8f0; padding:2px 8px; border-radius:12px; font-weight:600;">Active &amp; Historical</span>
                </div>
                <div style="display:flex; align-items:center; gap:.5rem;">
                    <span style="font-size:.78rem; color:#64748b; font-weight:600;">⇄ Horizontal Scroller:</span>
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
                                            <span class="badge" style="background:#fef3c7;color:#92400e;font-size:.75rem;font-weight:600;"><?= $s['remarks_count'] ?> remark(s)</span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:.8rem;">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
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
                                    </td>
                                    <td style="text-align:center; position:sticky; right:0; background:#fff; box-shadow:-2px 0 4px rgba(0,0,0,0.03);">
                                        <div style="display:flex;gap:.35rem;justify-content:center;">
                                            <button type="button" class="btn btn-sm btn-soft" onclick='openStudent360(<?= json_encode($s) ?>)'>
                                                🔍 360° View
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline" onclick="openRemarkModal(<?= (int)$s['id'] ?>, '<?= e(addslashes($s['first_name'] . ' ' . $s['last_name'])) ?>')">
                                                + Remark
                                            </button>
                                            <a href="student-certificates.php?student_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline" title="Issue Certificate">
                                                📜
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="padding:0.6rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; font-size:.78rem; color:#64748b;">
                    <span>⇄ <strong>Horizontal Scroller:</strong> Scroll across or use ◀ Left / Right ▶ buttons to view all columns.</span>
                    <span>Showing <?= count($students) ?> students</span>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ STUDENT 360° DOSSIER MODAL ═══════════════════ -->
<div id="student360Modal" class="modal-backdrop">
    <div class="modal-box" style="max-width:720px;max-height:90vh;overflow-y:auto;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="dossierTitle" style="margin:0;font-size:1.15rem;font-weight:700;">Student 360° Dossier</h2>
            <button type="button" class="icon-btn" onclick="closeModal('student360Modal')">✕</button>
        </div>
        <div style="padding:1.5rem;background:#fff;" id="dossierBody">
            <!-- Populated via JS -->
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:.5rem;background:#f8fafc;">
            <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('student360Modal')">Close</button>
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

<script>
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
function openStudent360(s) {
    document.getElementById('dossierTitle').textContent = 'Student 360° Dossier: ' + s.first_name + ' ' + s.last_name + ' (' + s.admission_no + ')';
    var body = document.getElementById('dossierBody');
    body.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;background:#f8fafc;padding:1rem;border-radius:8px;border:1px solid #e2e8f0;">
            <div>
                <div style="font-size:.78rem;color:#64748b;">Full Name</div>
                <div style="font-size:1rem;font-weight:700;color:#0f172a;">\${s.first_name} \${s.last_name}</div>
            </div>
            <div>
                <div style="font-size:.78rem;color:#64748b;">Admission No</div>
                <div style="font-size:1rem;font-weight:700;font-family:monospace;color:#1e3a8a;">\${s.admission_no}</div>
            </div>
            <div>
                <div style="font-size:.78rem;color:#64748b;">Gender / Date of Birth</div>
                <div style="font-weight:600;">\${s.gender || '—'} | \${s.dob || '—'}</div>
            </div>
            <div>
                <div style="font-size:.78rem;color:#64748b;">Blood Group</div>
                <div style="font-weight:600;">\${s.blood_group || 'Not recorded'}</div>
            </div>
            <div>
                <div style="font-size:.78rem;color:#64748b;">Contact Phone</div>
                <div style="font-weight:600;">\${s.phone || '—'}</div>
            </div>
            <div>
                <div style="font-size:.78rem;color:#64748b;">Contact Email</div>
                <div style="font-weight:600;">\${s.email || '—'}</div>
            </div>
        </div>

        <h4 style="font-size:.95rem;font-weight:700;margin-bottom:.5rem;">Academic & Campus Life Allocation</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
            <div style="border:1px solid #e2e8f0;padding:.85rem;border-radius:8px;">
                <div style="font-size:.78rem;color:#64748b;">Enrolled Class</div>
                <div style="font-size:1.05rem;font-weight:700;color:#2563eb;">\${s.class_name || '—'} \${s.section_name ? ('(' + s.section_name + ')') : ''}</div>
                <div style="font-size:.75rem;color:#64748b;">Session: \${s.session_label || 'Current'}</div>
            </div>
            <div style="border:1px solid #e2e8f0;padding:.85rem;border-radius:8px;">
                <div style="font-size:.78rem;color:#64748b;">House Allocation</div>
                <div style="font-size:1.05rem;font-weight:700;color:\${s.house_color || '#334155'};">\${s.house_name || 'Unallocated'}</div>
                <div style="font-size:.75rem;color:#64748b;">Role: \${s.house_role || 'Member'}</div>
            </div>
            <div style="border:1px solid #e2e8f0;padding:.85rem;border-radius:8px;">
                <div style="font-size:.78rem;color:#64748b;">Transport Fleet</div>
                <div style="font-size:1.05rem;font-weight:700;color:#0f172a;">\${s.route_name || 'Self Travel'}</div>
                <div style="font-size:.75rem;color:#64748b;">Stop: \${s.pickup_stop || '—'}</div>
            </div>
        </div>

        <h4 style="font-size:.95rem;font-weight:700;margin-bottom:.5rem;">Financial & Certificate Standing</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
            <div style="border:1px solid #e2e8f0;padding:.85rem;border-radius:8px;">
                <div style="font-size:.78rem;color:#64748b;">Outstanding Fee Balance</div>
                <div style="font-size:1.15rem;font-weight:700;color:\${parseFloat(s.fee_due) > 0 ? '#dc2626' : '#16a34a'};">
                    \${parseFloat(s.fee_due) > 0 ? ('₹ ' + parseFloat(s.fee_due).toFixed(2)) : '✓ No Dues Pending'}
                </div>
            </div>
            <div style="border:1px solid #e2e8f0;padding:.85rem;border-radius:8px;">
                <div style="font-size:.78rem;color:#64748b;">Certificates Issued</div>
                <div style="font-size:1.15rem;font-weight:700;color:#0f172a;">\${s.certs_count} Certificate(s)</div>
            </div>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;">
            <a href="student-certificates.php?student_id=\${s.id}" class="btn btn-sm btn-outline">Generate Certificate</a>
            <a href="student-promotions.php" class="btn btn-sm btn-soft">Promote Student</a>
        </div>
    `;
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
