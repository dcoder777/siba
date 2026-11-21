<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'academics');

$pageTitle = 'Exams & Assessment Schedules';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure table exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS exams (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        academic_year VARCHAR(30) NOT NULL DEFAULT '2025-2026',
        teacher_id INT NULL DEFAULT NULL,
        teacher_name VARCHAR(120) NULL DEFAULT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('Upcoming','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'Upcoming',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE exams ADD COLUMN teacher_id INT NULL DEFAULT NULL AFTER academic_year"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE exams ADD COLUMN teacher_name VARCHAR(120) NULL DEFAULT NULL AFTER teacher_id"); } catch (\Throwable $e) {}

// Pre-populate standard exams if empty
$examCount = (int) $pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn();
if ($examCount === 0) {
    $pdo->exec("INSERT INTO exams (title, academic_year, start_date, end_date, status) VALUES
        ('Unit Test 1', '2025-2026', '2025-07-15', '2025-07-22', 'Completed'),
        ('Half Yearly Examination', '2025-2026', '2025-10-10', '2025-10-25', 'Completed'),
        ('Unit Test 2', '2025-2026', '2026-01-12', '2026-01-19', 'Completed'),
        ('Annual Final Examination', '2025-2026', '2026-03-05', '2026-03-20', 'Upcoming')");
}

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_exam') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $year = trim((string) ($_POST['academic_year'] ?? '2025-2026'));
            $start = trim((string) ($_POST['start_date'] ?? date('Y-m-d')));
            $end = trim((string) ($_POST['end_date'] ?? date('Y-m-d', strtotime('+7 days'))));
            $status = trim((string) ($_POST['status'] ?? 'Upcoming'));
            $teacherIdRaw = trim((string) ($_POST['teacher_id'] ?? ''));
            $teacherName = trim((string) ($_POST['teacher_name'] ?? ''));

            if ($title === '') {
                throw new \RuntimeException('Exam title is required.');
            }

            $teacherId = null;
            if (is_numeric($teacherIdRaw) && (int)$teacherIdRaw > 0) {
                $teacherId = (int) $teacherIdRaw;
                if ($teacherName === '') {
                    $tStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $tStmt->execute([$teacherId]);
                    $teacherName = (string) $tStmt->fetchColumn();
                }
            }

            if ($teacherName === '') {
                throw new \RuntimeException('Please select or specify an assigned teacher for this examination term.');
            }

            $stmt = $pdo->prepare("INSERT INTO exams (title, academic_year, teacher_id, teacher_name, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $year, $teacherId, $teacherName, $start, $end, $status]);

            $success = "Exam '{$title}' assigned to {$teacherName} created successfully.";
        }

        if ($action === 'update_status') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'Upcoming'));
            if ($id > 0) {
                $pdo->prepare("UPDATE exams SET status = ? WHERE id = ?")->execute([$status, $id]);
                $success = 'Exam status updated.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: exam-schedules.php?success=' . urlencode($success));
        exit;
    }
}

$exams = $pdo->query("
    SELECT e.*,
           (SELECT COUNT(DISTINCT student_id) FROM exam_marks WHERE exam_id = e.id) as evaluated_students
    FROM exams e
    ORDER BY e.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch active teachers from users and staff who can log in and enter marks
$teachers = $pdo->query("
    SELECT u.id, u.name, u.email 
    FROM users u 
    JOIN roles r ON r.id = u.role_id 
    WHERE r.name = 'teacher' AND u.is_active = 1
    ORDER BY u.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

try {
    $staffTeachers = $pdo->query("
        SELECT id, name, email 
        FROM staff 
        WHERE (staff_type = 'Teaching' OR designation LIKE '%Teacher%') AND status = 'Active'
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $staffTeachers = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.4rem">
                    <span class="eyebrow">Academic Assessments</span>
                    <h1>Exams & Evaluation Schedules</h1>
                    <p>Create institutional examination terms, datesheets, and manage student evaluation cycles.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="openModal('examModal')">+ Create New Exam Term</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Exam Cards Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:1.25rem;margin-bottom:1.5rem;">
            <?php foreach ($exams as $ex): 
                $stClass = match($ex['status']) {
                    'Upcoming' => 'background:#e0e7ff;color:#3730a3;',
                    'Ongoing' => 'background:#fef08a;color:#854d0e;',
                    'Completed' => 'background:#d1fae5;color:#065f46;',
                    'Cancelled' => 'background:#fee2e2;color:#991b1b;',
                    default => 'background:#f1f5f9;color:#475569;',
                };
            ?>
                <div class="panel" style="padding:1.25rem;border-top:4px solid #2563eb;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                        <span style="font-size:.78rem;font-weight:700;color:#64748b;text-transform:uppercase;">
                            <?= e($ex['academic_year']) ?>
                        </span>
                        <span class="badge" style="<?= $stClass ?>font-weight:700;">
                            <?= e($ex['status']) ?>
                        </span>
                    </div>

                    <h2 style="margin:0 0 .5rem;font-size:1.15rem;font-weight:800;color:#0f172a;"><?= e($ex['title']) ?></h2>

                    <div style="background:#f1f5f9;border-left:3px solid #2563eb;padding:.5rem .75rem;border-radius:6px;margin-bottom:.75rem;font-size:.85rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
                        <div>
                            <span style="color:#64748b;font-size:.72rem;display:block;text-transform:uppercase;font-weight:700;">Assigned Evaluator</span>
                            <span style="font-weight:700;color:#0f172a;display:inline-flex;align-items:center;gap:.3rem;">
                                👨‍🏫 <?= !empty($ex['teacher_name']) ? e($ex['teacher_name']) : '<span style="color:#94a3b8;font-weight:normal;">Unassigned</span>' ?>
                            </span>
                        </div>
                        <?php if (!empty($ex['teacher_id']) && (int)($user['id'] ?? 0) === (int)$ex['teacher_id']): ?>
                            <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:700;font-size:.72rem;">Assigned to You</span>
                        <?php endif; ?>
                    </div>

                    <div style="font-size:.85rem;color:#475569;margin-bottom:1rem;">
                        📅 <strong>Period:</strong> <?= date('d M, Y', strtotime($ex['start_date'])) ?> – <?= date('d M, Y', strtotime($ex['end_date'])) ?>
                    </div>

                    <div style="background:#f8fafc;padding:.75rem;border-radius:6px;font-size:.82rem;margin-bottom:1rem;display:flex;justify-content:space-between;color:#334155;">
                        <span>Marks System: <strong>Marks Only</strong></span>
                        <span>Evaluated: <strong><?= (int)$ex['evaluated_students'] ?> students</strong></span>
                    </div>

                    <div style="display:flex;gap:.5rem;">
                        <a href="exam-marks.php?exam_title=<?= urlencode($ex['title']) ?>" class="btn btn-sm btn-primary" style="flex:1;">
                            📝 Enter Marks
                        </a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= (int) $ex['id'] ?>">
                            <select name="status" onchange="this.form.submit()" style="padding:.3rem .5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.8rem;">
                                <option value="Upcoming" <?= $ex['status'] === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                                <option value="Ongoing" <?= $ex['status'] === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                <option value="Completed" <?= $ex['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ CREATE EXAM MODAL ═══════════════════ -->
<div id="examModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:520px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;font-weight:700;">Create Examination Term</h2>
            <button type="button" class="icon-btn" onclick="closeModal('examModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_exam">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Exam Title *</label>
                <input type="text" name="title" required placeholder="e.g. Midterm Assessment / Final Exam" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Academic Session *</label>
                <input type="text" name="academic_year" value="2025-2026" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Assigned Teacher *</label>
                <select name="teacher_id" id="examTeacherSelect" required onchange="handleTeacherSelect(this)" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;margin-bottom:.4rem;">
                    <option value="">— Select Assigned Teacher —</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" data-name="<?= e($t['name']) ?>"><?= e($t['name']) ?> (<?= e($t['email']) ?>)</option>
                    <?php endforeach; ?>
                    <?php foreach ($staffTeachers as $st): ?>
                        <?php if (!in_array($st['name'], array_column($teachers, 'name'))): ?>
                            <option value="staff_<?= (int)$st['id'] ?>" data-name="<?= e($st['name']) ?>"><?= e($st['name']) ?> (Staff / <?= e($st['email'] ?: 'No email') ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <option value="custom">✏️ Enter Custom Teacher Name...</option>
                </select>
                <input type="text" name="teacher_name" id="examTeacherName" placeholder="Enter teacher name..." style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;display:none;">
                <span style="font-size:.74rem;color:#64748b;">The assigned teacher logs into the ERP/App with their credentials to record & submit marks.</span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Start Date *</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">End Date *</label>
                    <input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+10 days')) ?>" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Initial Status</label>
                <select name="status" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                    <option value="Upcoming">Upcoming</option>
                    <option value="Ongoing">Ongoing</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('examModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Create Exam</button>
            </div>
        </form>
    </div>
</div>

<script>
function handleTeacherSelect(sel) {
    var nameInput = document.getElementById('examTeacherName');
    if (!sel || !nameInput) return;
    var selectedOpt = sel.options[sel.selectedIndex];
    if (sel.value === 'custom') {
        nameInput.style.display = 'block';
        nameInput.value = '';
        nameInput.required = true;
        nameInput.focus();
    } else {
        nameInput.style.display = 'none';
        nameInput.required = false;
        nameInput.value = selectedOpt.getAttribute('data-name') || '';
    }
}
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
});
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>

