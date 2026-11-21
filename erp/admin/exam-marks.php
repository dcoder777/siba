<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pageTitle = 'Examination Marks Entry & Evaluation';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_results (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        student_id BIGINT NOT NULL,
        subject_id BIGINT NOT NULL,
        exam_name VARCHAR(120) NOT NULL,
        max_marks DECIMAL(8,2) NOT NULL DEFAULT 100.00,
        obtained_marks DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        grade VARCHAR(5) DEFAULT NULL,
        result_date DATE DEFAULT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_er_student (student_id),
        KEY idx_er_subject (subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Distinct exams & classes
$examList = $pdo->query("SELECT DISTINCT title FROM exams ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($examList)) {
    $examList = ['Unit Test 1', 'Half Yearly', 'Unit Test 2', 'Annual Final Examination'];
}

$classList = $pdo->query("SELECT DISTINCT class_name FROM subjects ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($classList)) {
    $classList = ['1', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5'];
}

$selectedExam = trim((string) ($_GET['exam_title'] ?? ($examList[0] ?? 'Unit Test 1')));
$selectedClass = trim((string) ($_GET['class_name'] ?? ($classList[0] ?? '1')));

$examTeacherStmt = $pdo->prepare("SELECT teacher_name, teacher_id FROM exams WHERE title = ? LIMIT 1");
$examTeacherStmt->execute([$selectedExam]);
$examTeacherRow = $examTeacherStmt->fetch(PDO::FETCH_ASSOC);
$assignedTeacherName = $examTeacherRow['teacher_name'] ?? '';
$assignedTeacherId = (int) ($examTeacherRow['teacher_id'] ?? 0);

// ── Handle Batch Marks Save POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'save_marks') {
            $examName = trim((string) ($_POST['exam_name'] ?? $selectedExam));
            $maxMarks = max(1.0, (float) ($_POST['max_marks'] ?? 100.0));
            $marksData = $_POST['marks'] ?? []; // student_id => [subject_id => score]

            if (empty($marksData)) {
                throw new \RuntimeException('No marks data submitted.');
            }

            $pdo->beginTransaction();

            $saveStmt = $pdo->prepare("
                INSERT INTO exam_results (student_id, subject_id, exam_name, max_marks, obtained_marks, result_date)
                VALUES (:student_id, :subject_id, :exam_name, :max_marks, :obtained_marks, CURDATE())
                ON DUPLICATE KEY UPDATE obtained_marks = VALUES(obtained_marks), max_marks = VALUES(max_marks), result_date = CURDATE()
            ");

            $savedCount = 0;
            foreach ($marksData as $studentIdStr => $subjects) {
                $studentId = (int) $studentIdStr;
                if ($studentId <= 0) continue;

                foreach ($subjects as $subjectIdStr => $scoreStr) {
                    $subjectId = (int) $subjectIdStr;
                    if ($subjectId <= 0 || $scoreStr === '') continue;

                    $score = min($maxMarks, max(0.0, (float) $scoreStr));

                    // Check if existing record
                    $exist = $pdo->prepare("SELECT id FROM exam_results WHERE student_id = ? AND subject_id = ? AND exam_name = ?");
                    $exist->execute([$studentId, $subjectId, $examName]);
                    $rowId = $exist->fetchColumn();

                    if ($rowId) {
                        $pdo->prepare("UPDATE exam_results SET obtained_marks = ?, max_marks = ?, result_date = CURDATE() WHERE id = ?")
                            ->execute([$score, $maxMarks, $rowId]);
                    } else {
                        $pdo->prepare("INSERT INTO exam_results (student_id, subject_id, exam_name, max_marks, obtained_marks, result_date) VALUES (?, ?, ?, ?, ?, CURDATE())")
                            ->execute([$studentId, $subjectId, $examName, $maxMarks, $score]);
                    }

                    $savedCount++;
                }
            }

            $pdo->commit();
            $success = "Successfully saved marks for {$savedCount} student-subject entries in '{$examName}'.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header("Location: exam-marks.php?exam_title=" . urlencode($selectedExam) . "&class_name=" . urlencode($selectedClass) . "&success=" . urlencode($success));
        exit;
    }
}

// ── Fetch subjects for this class ──
$subjectsStmt = $pdo->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE class_name = ? OR class_name = ? ORDER BY id ASC");
$subjectsStmt->execute([$selectedClass, str_replace('Class ', '', $selectedClass)]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

// If no subjects found, fallback to all subjects
if (empty($subjects)) {
    $subjects = $pdo->query("SELECT id, subject_code, subject_name FROM subjects ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Fetch students in this class ──
$studentsStmt = $pdo->prepare("
    SELECT s.id, s.admission_no, s.first_name, s.last_name, e.class_name, e.section_name
    FROM students s
    JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
    WHERE e.class_name = ? OR e.class_name = ?
    ORDER BY s.admission_no ASC
    LIMIT 50
");
$studentsStmt->execute([$selectedClass, 'Class ' . $selectedClass]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($students)) {
    // Fallback to first 25 students so table is never blank
    $students = $pdo->query("SELECT s.id, s.admission_no, s.first_name, s.last_name, e.class_name, e.section_name FROM students s LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1 ORDER BY s.admission_no ASC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Load existing marks for these students in this exam ──
$existingMarks = [];
if (!empty($students) && !empty($subjects)) {
    $stIds = array_column($students, 'id');
    $inClause = implode(',', $stIds);
    $marksStmt = $pdo->prepare("SELECT student_id, subject_id, obtained_marks, max_marks FROM exam_results WHERE student_id IN ($inClause) AND exam_name = ?");
    $marksStmt->execute([$selectedExam]);
    foreach ($marksStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $existingMarks[$m['student_id']][$m['subject_id']] = (float) $m['obtained_marks'];
    }
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
                    <span class="eyebrow">Academic Evaluation</span>
                    <h1>Examination Marks Entry Sheet</h1>
                    <p>Enter and review evaluation marks for students per exam term. Official scoring evaluates on <strong>Marks Only</strong>.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <a href="exam-schedules.php" class="btn btn-sm btn-outline">📅 Exam Datesheets</a>
                    <a href="academic-subjects.php" class="btn btn-sm btn-soft">📚 Subjects Master</a>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <form method="get" action="exam-marks.php" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center;">
                <div>
                    <label style="display:block;font-size:.78rem;font-weight:700;color:#64748b;margin-bottom:.2rem;">Exam Term</label>
                    <select name="exam_title" onchange="this.form.submit()" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-weight:700;color:#1e40af;">
                        <?php foreach ($examList as $ex): ?>
                            <option value="<?= e($ex) ?>" <?= $selectedExam === $ex ? 'selected' : '' ?>><?= e($ex) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.78rem;font-weight:700;color:#64748b;margin-bottom:.2rem;">Class</label>
                    <select name="class_name" onchange="this.form.submit()" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-weight:600;">
                        <?php foreach ($classList as $c): ?>
                            <option value="<?= e($c) ?>" <?= $selectedClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-top:auto;">
                    <button type="submit" class="btn btn-sm">Load Evaluation Grid</button>
                </div>
                <?php if ($assignedTeacherName): ?>
                    <div style="margin-left:auto;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.4rem .85rem;display:flex;align-items:center;gap:.5rem;font-size:.82rem;">
                        <span style="font-size:1.1rem;">👨‍🏫</span>
                        <div>
                            <span style="color:#64748b;font-size:.72rem;display:block;font-weight:600;">Assigned Evaluator</span>
                            <strong style="color:#1e40af;"><?= e($assignedTeacherName) ?></strong>
                            <?php if ($assignedTeacherId > 0 && (int)($user['id'] ?? 0) === $assignedTeacherId): ?>
                                <span class="badge" style="background:#dcfce7;color:#15803d;font-size:.68rem;margin-left:.25rem;">(You)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Marks Entry Spreadsheet Form -->
        <form method="post" action="exam-marks.php">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_marks">
            <input type="hidden" name="exam_name" value="<?= e($selectedExam) ?>">

            <div class="panel" style="overflow:auto;">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
                    <div>
                        <h3 style="margin:0;font-size:1.05rem;font-weight:700;">
                            <?= e($selectedExam) ?> – <?= e($selectedClass) ?> Evaluation Grid
                        </h3>
                        <span style="font-size:.8rem;color:#64748b;">
                            <?= count($students) ?> students × <?= count($subjects) ?> subjects | Max Marks: 
                            <input type="number" name="max_marks" value="100" style="width:65px;padding:.2rem .4rem;border:1px solid #cbd5e1;border-radius:4px;font-size:.8rem;">
                        </span>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-sm btn-primary">
                            💾 Save All Marks
                        </button>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Adm No</th>
                                <th>Student Name</th>
                                <?php foreach ($subjects as $sb): ?>
                                    <th style="text-align:center;min-width:110px;">
                                        <?= e($sb['subject_name']) ?>
                                        <div style="font-size:.7rem;font-weight:normal;color:#64748b;">(<?= e($sb['subject_code']) ?>)</div>
                                    </th>
                                <?php endforeach; ?>
                                <th style="text-align:center;">Total</th>
                                <th style="text-align:center;">Report Card</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $st): 
                                $rowTotal = 0;
                                $hasAnyScore = false;
                            ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($st['admission_no']) ?></td>
                                    <td>
                                        <strong><?= e($st['first_name'] . ' ' . $st['last_name']) ?></strong>
                                    </td>
                                    <?php foreach ($subjects as $sb): 
                                        $val = isset($existingMarks[$st['id']][$sb['id']]) ? $existingMarks[$st['id']][$sb['id']] : '';
                                        if ($val !== '') {
                                            $rowTotal += (float)$val;
                                            $hasAnyScore = true;
                                        }
                                    ?>
                                        <td style="text-align:center;">
                                            <input type="number" step="0.5" min="0" max="100" 
                                                   name="marks[<?= (int)$st['id'] ?>][<?= (int)$sb['id'] ?>]" 
                                                   value="<?= $val !== '' ? $val : '' ?>" 
                                                   placeholder="—"
                                                   style="width:80px;text-align:center;padding:.35rem;border:1px solid #cbd5e1;border-radius:6px;font-weight:700;color:#0f172a;">
                                        </td>
                                    <?php endforeach; ?>
                                    <td style="text-align:center;font-weight:800;color:#2563eb;">
                                        <?= $hasAnyScore ? number_format($rowTotal, 1) : '—' ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <a href="exam-report-card.php?student_id=<?= (int)$st['id'] ?>&exam_title=<?= urlencode($selectedExam) ?>" target="_blank" class="btn btn-sm btn-outline" title="Print Official Marksheet">
                                            🖨 Marksheet
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="padding:1rem 1.25rem;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;">
                    <button type="submit" class="btn btn-sm btn-primary">
                        💾 Save All Marks
                    </button>
                </div>
            </div>
        </form>

    </main>
</div>

<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>

