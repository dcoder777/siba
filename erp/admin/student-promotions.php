<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pageTitle = 'Student Session Promotions & Academic Rollover';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_promotions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id BIGINT NOT NULL,
        from_academic_year VARCHAR(30) NOT NULL,
        from_class VARCHAR(50) NOT NULL,
        to_academic_year VARCHAR(30) NOT NULL,
        to_class VARCHAR(50) NOT NULL,
        to_section VARCHAR(20) DEFAULT NULL,
        final_result VARCHAR(50) DEFAULT 'Pass',
        attendance_pct DECIMAL(5,2) DEFAULT NULL,
        promotion_status ENUM('Promoted','Conditional Promotion','Detained','Graduated') NOT NULL DEFAULT 'Promoted',
        remarks VARCHAR(255) DEFAULT NULL,
        promoted_by VARCHAR(100) DEFAULT 'Admin',
        promoted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sp_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Distinct classes and sessions
$classList = $pdo->query("SELECT DISTINCT class_name FROM student_enrollments WHERE class_name IS NOT NULL AND class_name <> '' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Standard and database classes for promote-to dropdown
$standardClasses = [
    'Pre Nursery', 'Nursery',
    'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5',
    'Class 6', 'Class 7', 'Class 8',
    'Graduated / Alumni'
];
$toClassList = array_values(array_unique(array_merge($classList, $standardClasses)));

$currentSession = '2025-2026';
$nextSession = '2026-2027';

// ── Process Bulk Promotion POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'execute_promotion') {
            $fromYear = trim((string) ($_POST['from_year'] ?? ''));
            $fromClass = trim((string) ($_POST['from_class'] ?? ''));
            $toYear = trim((string) ($_POST['to_year'] ?? ''));
            $toClass = trim((string) ($_POST['to_class'] ?? ''));
            $toSection = trim((string) ($_POST['to_section'] ?? 'A'));
            $selectedStudents = $_POST['students'] ?? [];
            $statuses = $_POST['promo_status'] ?? [];
            $remarksArr = $_POST['promo_remarks'] ?? [];

            if ($fromClass === '' || $toClass === '' || $toYear === '') {
                throw new \RuntimeException('From Class, To Class, and Target Academic Year are mandatory.');
            }
            if (empty($selectedStudents)) {
                throw new \RuntimeException('Please select at least one student to promote or roll over.');
            }

            $pdo->beginTransaction();
            $promotedCount = 0;

            $insPromo = $pdo->prepare("INSERT INTO student_promotions 
                (student_id, from_academic_year, from_class, to_academic_year, to_class, to_section, final_result, promotion_status, remarks, promoted_by) 
                VALUES (?, ?, ?, ?, ?, ?, 'Pass', ?, ?, ?)");

            $closeOldEnroll = $pdo->prepare("UPDATE student_enrollments SET is_current = 0, status = ? WHERE student_id = ? AND is_current = 1");

            $openNewEnroll = $pdo->prepare("INSERT INTO student_enrollments 
                (student_id, class_name, section_name, session_label, status, is_current, created_at) 
                VALUES (?, ?, ?, ?, 'active', 1, NOW())");

            foreach ($selectedStudents as $studentIdStr) {
                $studentId = (int) $studentIdStr;
                if ($studentId <= 0) continue;

                $promoStatus = trim((string) ($statuses[$studentId] ?? 'Promoted'));
                $rem = trim((string) ($remarksArr[$studentId] ?? ''));

                // 1. Record promotion log
                $insPromo->execute([
                    $studentId, $fromYear, $fromClass, $toYear, $toClass, $toSection,
                    $promoStatus, $rem, $user['name'] ?? 'Admin'
                ]);

                // 2. Mark old enrollment
                $enrollCloseStatus = ($promoStatus === 'Graduated') ? 'alumni' : ($promoStatus === 'Detained' ? 'active' : 'promoted');
                $closeOldEnroll->execute([$enrollCloseStatus, $studentId]);

                // 3. If promoted or retained, create new active enrollment in destination class
                if ($promoStatus !== 'Graduated') {
                    $destClass = ($promoStatus === 'Detained') ? $fromClass : $toClass;
                    $openNewEnroll->execute([$studentId, $destClass, $toSection, $toYear]);
                }

                $promotedCount++;
            }

            $pdo->commit();
            $success = "Successfully processed session rollover for {$promotedCount} student(s) from {$fromClass} to {$toClass} ({$toYear}).";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: student-promotions.php?success=' . urlencode($success));
        exit;
    }
}

// ── Fetch students in selected source class ──
$selectedFromClass = trim((string) ($_GET['from_class'] ?? ($classList[0] ?? 'Class 1')));
$selectedFromYear = trim((string) ($_GET['from_year'] ?? $currentSession));

$studentsStmt = $pdo->prepare("
    SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender, e.class_name, e.section_name, e.session_label,
           (SELECT COUNT(*) FROM attendance_records WHERE student_id = s.id AND status = 'present') as presents,
           (SELECT COUNT(*) FROM attendance_records WHERE student_id = s.id) as total_attendance
    FROM students s
    JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
    WHERE e.class_name = ?
    ORDER BY s.admission_no ASC
");
$studentsStmt->execute([$selectedFromClass]);
$eligibleStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Promotions History
$recentPromotions = $pdo->query("
    SELECT p.*, s.admission_no, s.first_name, s.last_name
    FROM student_promotions p
    JOIN students s ON s.id = p.student_id
    ORDER BY p.id DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Compute suggested next class based on selectedFromClass
$suggestedNextClass = '';
if (is_numeric($selectedFromClass)) {
    $nextNum = ((int) $selectedFromClass) + 1;
    if (in_array((string) $nextNum, $toClassList, true)) {
        $suggestedNextClass = (string) $nextNum;
    } elseif (in_array('Class ' . $nextNum, $toClassList, true)) {
        $suggestedNextClass = 'Class ' . $nextNum;
    }
} elseif (preg_match('/class\s*(\d+)/i', $selectedFromClass, $m)) {
    $nextNum = ((int) $m[1]) + 1;
    if (in_array('Class ' . $nextNum, $toClassList, true)) {
        $suggestedNextClass = 'Class ' . $nextNum;
    } elseif (in_array((string) $nextNum, $toClassList, true)) {
        $suggestedNextClass = (string) $nextNum;
    }
} elseif ($selectedFromClass === 'Pre Nursery' || $selectedFromClass === 'Playgroup' || $selectedFromClass === 'Play School') {
    $suggestedNextClass = 'Nursery';
} elseif ($selectedFromClass === 'Nursery') {
    $suggestedNextClass = in_array('Class 1', $toClassList, true) ? 'Class 1' : (in_array('1', $toClassList, true) ? '1' : 'Class 1');
} elseif ($selectedFromClass === 'Class 8' || $selectedFromClass === '8') {
    $suggestedNextClass = 'Graduated / Alumni';
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
                    <span class="eyebrow">Academic Lifecycle</span>
                    <h1>Student Session Promotions & Rollover</h1>
                    <p>Promote entire classes to the next academic year or handle retentions, section transfers, and graduation.</p>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Source Class Selection Bar -->
        <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <form method="get" action="student-promotions.php" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                <div style="font-weight:700;color:#0f172a;">Select Current Class to Promote:</div>
                <div>
                    <select name="from_class" onchange="this.form.submit()" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-weight:600;">
                        <?php foreach ($classList as $c): ?>
                            <option value="<?= e($c) ?>" <?= $selectedFromClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <input type="text" name="from_year" value="<?= e($selectedFromYear) ?>" placeholder="e.g. 2025-2026" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;width:130px;">
                </div>
                <button type="submit" class="btn btn-sm">Load Class Roster</button>
            </form>
        </div>

        <!-- Promotion Form -->
        <form method="post" action="student-promotions.php">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="execute_promotion">
            <input type="hidden" name="from_class" value="<?= e($selectedFromClass) ?>">
            <input type="hidden" name="from_year" value="<?= e($selectedFromYear) ?>">

            <!-- Destination Configuration Card -->
            <div class="panel" style="padding:1.25rem;margin-bottom:1.25rem;background:#f0f9ff;border:1px solid #bae6fd;">
                <h3 style="margin:0 0 .75rem;font-size:1rem;font-weight:700;color:#0369a1;">Target Destination Details</h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:1rem;">
                    <div>
                        <label style="display:block;font-size:.82rem;font-weight:600;color:#0369a1;margin-bottom:.3rem;">Target Academic Year *</label>
                        <input type="text" name="to_year" value="<?= e($nextSession) ?>" required style="width:100%;padding:.45rem .75rem;border:1px solid #93c5fd;border-radius:6px;background:#fff;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.82rem;font-weight:600;color:#0369a1;margin-bottom:.3rem;">Promote To Class *</label>
                        <select name="to_class" required style="width:100%;padding:.45rem .75rem;border:1px solid #93c5fd;border-radius:6px;background:#fff;font-weight:600;color:#0f172a;">
                            <option value="">-- Select Target Class --</option>
                            <?php foreach ($toClassList as $cls): ?>
                                <option value="<?= e($cls) ?>" <?= ($suggestedNextClass === $cls) ? 'selected' : '' ?>>
                                    <?= e($cls) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:.82rem;font-weight:600;color:#0369a1;margin-bottom:.3rem;">Section *</label>
                        <input type="text" name="to_section" value="A" required style="width:100%;padding:.45rem .75rem;border:1px solid #93c5fd;border-radius:6px;background:#fff;">
                    </div>
                </div>
            </div>

            <!-- Student Roster Table -->
            <div class="panel" style="overflow:auto;">
                <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 style="margin:0;font-size:1rem;font-weight:700;">Students in <?= e($selectedFromClass) ?> (<?= count($eligibleStudents) ?>)</h3>
                        <span style="font-size:.8rem;color:#64748b;">Select students to execute promotion or status change</span>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Execute promotion for selected students?')">
                            ⚡ Execute Promotion
                        </button>
                    </div>
                </div>

                <?php if (empty($eligibleStudents)): ?>
                    <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                        No active students found in <?= e($selectedFromClass) ?>.
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;text-align:center;">
                                        <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                                    </th>
                                    <th>Adm No</th>
                                    <th>Student Name</th>
                                    <th>Gender</th>
                                    <th>Attendance %</th>
                                    <th>Decision / Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eligibleStudents as $s): 
                                    $totAtt = (int) $s['total_attendance'];
                                    $pres = (int) $s['presents'];
                                    $attPct = $totAtt > 0 ? round(($pres / $totAtt) * 100, 1) : 100.0;
                                ?>
                                    <tr>
                                        <td style="text-align:center;">
                                            <input type="checkbox" name="students[]" value="<?= (int) $s['id'] ?>" class="student-check" checked>
                                        </td>
                                        <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($s['admission_no']) ?></td>
                                        <td><strong><?= e($s['first_name'] . ' ' . $s['last_name']) ?></strong></td>
                                        <td><?= e($s['gender'] ?: '—') ?></td>
                                        <td>
                                            <span style="font-weight:600;color:<?= $attPct >= 75 ? '#16a34a' : '#dc2626' ?>"><?= $attPct ?>%</span>
                                        </td>
                                        <td>
                                            <select name="promo_status[<?= (int)$s['id'] ?>]" style="padding:.3rem .5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">
                                                <option value="Promoted" selected>Promoted</option>
                                                <option value="Conditional Promotion">Conditional Promotion</option>
                                                <option value="Detained">Detained (Repeat Class)</option>
                                                <option value="Graduated">Graduated (Alumni)</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="promo_remarks[<?= (int)$s['id'] ?>]" placeholder="Optional remark..." style="padding:.3rem .5rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;width:100%;">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <!-- Promotion History Log -->
        <div class="panel" style="overflow:auto;margin-top:1.25rem;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Recent Promotion History Log</h3>
                <span style="font-size:.8rem;color:#64748b;">Last 20 operations</span>
            </div>
            <?php if (empty($recentPromotions)): ?>
                <div style="padding:2rem;text-align:center;color:#94a3b8;">No promotion records in history yet.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Adm No</th>
                                <th>Student Name</th>
                                <th>From Class</th>
                                <th>To Class</th>
                                <th>Status</th>
                                <th>Promoted By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPromotions as $p): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= e($p['promoted_at']) ?></td>
                                    <td style="font-family:monospace;"><?= e($p['admission_no']) ?></td>
                                    <td><strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong></td>
                                    <td><?= e($p['from_class']) ?> (<?= e($p['from_academic_year']) ?>)</td>
                                    <td><strong style="color:#2563eb;"><?= e($p['to_class']) ?></strong> (<?= e($p['to_academic_year']) ?>)</td>
                                    <td>
                                        <span class="badge" style="background:#d1fae5;color:#065f46;"><?= e($p['promotion_status']) ?></span>
                                    </td>
                                    <td><?= e($p['promoted_by'] ?: 'Admin') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<script>
function toggleAll(master) {
    document.querySelectorAll('.student-check').forEach(function(cb) {
        cb.checked = master.checked;
    });
}
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
