<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'academics');

$pageTitle = 'Academics & Subject Master';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS subjects (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        subject_code VARCHAR(20) NOT NULL UNIQUE,
        subject_name VARCHAR(120) NOT NULL,
        class_name VARCHAR(50) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subject_teacher_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        subject_id BIGINT NOT NULL,
        teacher_id BIGINT UNSIGNED NOT NULL,
        academic_year VARCHAR(30) NOT NULL DEFAULT '2025-2026',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sta_sub (subject_id),
        KEY idx_sta_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_subject') {
            $code = strtoupper(trim((string) ($_POST['subject_code'] ?? '')));
            $name = trim((string) ($_POST['subject_name'] ?? ''));
            $class = trim((string) ($_POST['class_name'] ?? 'Class 1'));

            if ($code === '' || $name === '') {
                throw new \RuntimeException('Subject code and Subject name are required.');
            }

            // Check duplicate
            $dup = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = ?");
            $dup->execute([$code]);
            if ($dup->fetch()) {
                throw new \RuntimeException("Subject code '{$code}' already exists.");
            }

            $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, class_name) VALUES (?, ?, ?)")
                ->execute([$code, $name, $class]);

            $success = "Subject '{$name}' ({$code}) added for {$class}.";
        }

        if ($action === 'update_subject') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = strtoupper(trim((string) ($_POST['subject_code'] ?? '')));
            $name = trim((string) ($_POST['subject_name'] ?? ''));
            $class = trim((string) ($_POST['class_name'] ?? 'Class 1'));

            if ($id <= 0 || $code === '' || $name === '') {
                throw new \RuntimeException('Valid Subject details are required.');
            }

            $dup = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = ? AND id != ?");
            $dup->execute([$code, $id]);
            if ($dup->fetch()) {
                throw new \RuntimeException("Subject code '{$code}' is already used by another subject.");
            }

            $pdo->prepare("UPDATE subjects SET subject_code = ?, subject_name = ?, class_name = ? WHERE id = ?")
                ->execute([$code, $name, $class, $id]);

            $success = "Subject '{$name}' updated successfully.";
        }

        if ($action === 'delete_subject') {
            if (!$canDelete && !$isOwner) {
                throw new \RuntimeException('You do not have permission to delete subjects.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM subjects WHERE id = ?")->execute([$id]);
                $success = 'Subject deleted successfully.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: academic-subjects.php?success=' . urlencode($success));
        exit;
    }
}

// ── Search & Filter State ──
$search = trim((string) ($_GET['q'] ?? ''));
$filterClass = trim((string) ($_GET['class'] ?? ''));

$classList = $pdo->query("SELECT DISTINCT class_name FROM subjects ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN);

$whereParts = ["1=1"];
$params = [];

if ($search !== '') {
    $whereParts[] = "(subject_code LIKE :q OR subject_name LIKE :q)";
    $params['q'] = '%' . $search . '%';
}
if ($filterClass !== '') {
    $whereParts[] = "class_name = :cls";
    $params['cls'] = $filterClass;
}

$whereStr = implode(' AND ', $whereParts);

$subjectsStmt = $pdo->prepare("SELECT * FROM subjects WHERE {$whereStr} ORDER BY class_name ASC, subject_name ASC LIMIT 150");
$subjectsStmt->execute($params);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalSubjects = (int) $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
$totalExams = (int) $pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn();
$totalResults = (int) $pdo->query("SELECT COUNT(*) FROM exam_results")->fetchColumn();
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
                    <span class="eyebrow">Academic Curriculum</span>
                    <h1>Academics & Subject Master</h1>
                    <p>Configure classes, curriculum subjects, syllabus courses, and academic structures.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <a href="exam-schedules.php" class="btn btn-sm btn-outline">📅 Exam Datesheets</a>
                    <a href="exam-marks.php" class="btn btn-sm btn-outline">📝 Marks Entry</a>
                    <button type="button" class="btn btn-sm btn-primary" onclick="openAddSubjectModal()">+ Add New Subject</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="kpi-grid" style="margin-bottom:1.25rem;">
            <div class="kpi-card">
                <div class="kpi-label">Active Subjects</div>
                <div class="kpi-value"><?= $totalSubjects ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Across all classes</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Scheduled Exam Terms</div>
                <div class="kpi-value" style="color:#0284c7;"><?= $totalExams ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Unit tests & Term exams</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Recorded Marks Entries</div>
                <div class="kpi-value" style="color:#16a34a;"><?= $totalResults ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Individual subject scores</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Evaluation Standard</div>
                <div class="kpi-value" style="font-size:1.3rem;">Marks Only</div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Official scoring system</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <form method="get" action="academic-subjects.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:200px;">
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by subject code or subject name..." style="width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                </div>
                <div>
                    <select name="class" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Classes</option>
                        <?php foreach ($classList as $c): ?>
                            <option value="<?= e($c) ?>" <?= $filterClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <?php if ($search !== '' || $filterClass !== ''): ?>
                    <a href="academic-subjects.php" class="btn btn-sm btn-soft">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Subjects Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Subject Directory (<?= count($subjects) ?>)</h3>
                <span style="font-size:.8rem;color:#64748b;">Class curriculum mapping</span>
            </div>

            <?php if (empty($subjects)): ?>
                <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    No subjects found. Click "<strong>+ Add New Subject</strong>" to register curriculum subjects.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Class</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $s): ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($s['subject_code']) ?></td>
                                    <td><strong><?= e($s['subject_name']) ?></strong></td>
                                    <td><span class="badge" style="background:#e0e7ff;color:#3730a3;font-weight:600;"><?= e($s['class_name']) ?></span></td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;gap:.35rem;justify-content:center;">
                                            <button type="button" class="btn btn-sm btn-outline" onclick='editSubject(<?= json_encode($s) ?>)'>
                                                Edit
                                            </button>
                                            <?php if ($canDelete || $isOwner): ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete subject <?= e(addslashes($s['subject_name'])) ?>?');">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete_subject">
                                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete">✕</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ ADD / EDIT SUBJECT MODAL ═══════════════════ -->
<div id="subjectModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:480px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="subjectModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Add New Subject</h2>
            <button type="button" class="icon-btn" onclick="closeModal('subjectModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="subjectAction" value="create_subject">
            <input type="hidden" name="id" id="subjectId" value="">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Subject Code *</label>
                <input type="text" name="subject_code" id="subjectCode" required placeholder="e.g. C1S1 or ENG-101" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;font-family:monospace;text-transform:uppercase;">
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Subject Name *</label>
                <input type="text" name="subject_name" id="subjectName" required placeholder="e.g. English Literature" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Class *</label>
                <input type="text" name="class_name" id="subjectClass" required placeholder="e.g. Class 1 or 1" value="Class 1" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('subjectModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary" id="subjectBtn">Save Subject</button>
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
function openAddSubjectModal() {
    document.getElementById('subjectAction').value = 'create_subject';
    document.getElementById('subjectId').value = '';
    document.getElementById('subjectCode').value = '';
    document.getElementById('subjectName').value = '';
    document.getElementById('subjectClass').value = 'Class 1';
    document.getElementById('subjectModalTitle').textContent = 'Add New Subject';
    document.getElementById('subjectBtn').textContent = 'Create Subject';
    openModal('subjectModal');
}
function editSubject(s) {
    document.getElementById('subjectAction').value = 'update_subject';
    document.getElementById('subjectId').value = s.id;
    document.getElementById('subjectCode').value = s.subject_code;
    document.getElementById('subjectName').value = s.subject_name;
    document.getElementById('subjectClass').value = s.class_name;
    document.getElementById('subjectModalTitle').textContent = 'Edit Subject: ' + s.subject_name;
    document.getElementById('subjectBtn').textContent = 'Update Subject';
    openModal('subjectModal');
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

