<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pageTitle = 'Student House Allocations';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure table exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_house_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id BIGINT NOT NULL,
        house_id INT UNSIGNED NOT NULL,
        academic_year VARCHAR(30) NOT NULL,
        role ENUM('Member','Captain','Vice-Captain','Prefect') NOT NULL DEFAULT 'Member',
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sha_student (student_id),
        KEY idx_sha_house (house_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$houses = $pdo->query("SELECT id, house_code, house_name, color_code FROM houses WHERE status = 'Active' ORDER BY house_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'save_allocation') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $houseId = (int) ($_POST['house_id'] ?? 0);
            $role = trim((string) ($_POST['role'] ?? 'Member'));
            $year = trim((string) ($_POST['academic_year'] ?? '2025-2026'));

            if ($studentId <= 0 || $houseId <= 0) {
                throw new \RuntimeException('Student and House selection are required.');
            }

            // Deactivate existing active allocation for this student
            $pdo->prepare("UPDATE student_house_allocations SET status = 'Inactive' WHERE student_id = ?")->execute([$studentId]);

            // Insert new allocation
            $pdo->prepare("INSERT INTO student_house_allocations (student_id, house_id, academic_year, role, status) VALUES (?, ?, ?, ?, 'Active')")
                ->execute([$studentId, $houseId, $year, $role]);

            $success = 'House allocation updated successfully.';
        }

        if ($action === 'auto_balance') {
            // Find students who have no active house allocation
            $unallocated = $pdo->query("
                SELECT s.id, s.admission_no, s.first_name, s.last_name
                FROM students s
                WHERE s.id NOT IN (SELECT student_id FROM student_house_allocations WHERE status = 'Active')
                ORDER BY s.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($unallocated)) {
                $success = 'All students are already allocated to houses. No action required.';
            } else {
                $houseIds = array_column($houses, 'id');
                if (empty($houseIds)) {
                    throw new \RuntimeException('No active houses available to allocate students.');
                }

                $pdo->beginTransaction();
                $allocCount = 0;
                $numHouses = count($houseIds);
                $stmt = $pdo->prepare("INSERT INTO student_house_allocations (student_id, house_id, academic_year, role, status) VALUES (?, ?, '2025-2026', 'Member', 'Active')");

                foreach ($unallocated as $idx => $st) {
                    $assignedHouseId = $houseIds[$idx % $numHouses];
                    $stmt->execute([(int)$st['id'], $assignedHouseId]);
                    $allocCount++;
                }

                $pdo->commit();
                $success = "Successfully auto-balanced and allocated {$allocCount} previously unassigned student(s) evenly across all active houses.";
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: house-allocations.php?success=' . urlencode($success));
        exit;
    }
}

// ── Search & Filter State ──
$search = trim((string) ($_GET['q'] ?? ''));
$filterHouse = trim((string) ($_GET['house_id'] ?? ''));
$filterRole = trim((string) ($_GET['role'] ?? ''));

$whereParts = ["1=1"];
$params = [];

if ($search !== '') {
    $whereParts[] = "(s.admission_no LIKE :q OR s.first_name LIKE :q OR s.last_name LIKE :q)";
    $params['q'] = '%' . $search . '%';
}
if ($filterHouse !== '') {
    $whereParts[] = "sha.house_id = :hid";
    $params['hid'] = (int) $filterHouse;
}
if ($filterRole !== '') {
    $whereParts[] = "sha.role = :rl";
    $params['rl'] = $filterRole;
}

$whereStr = implode(' AND ', $whereParts);

$allocations = $pdo->prepare("
    SELECT sha.*, s.admission_no, s.first_name, s.last_name, s.gender,
           h.house_name, h.color_code, h.house_code,
           e.class_name, e.section_name
    FROM student_house_allocations sha
    JOIN students s ON s.id = sha.student_id
    JOIN houses h ON h.id = sha.house_id
    LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
    WHERE sha.status = 'Active' AND {$whereStr}
    ORDER BY h.house_name ASC, s.admission_no ASC
    LIMIT 150
");
$allocations->execute($params);
$allocList = $allocations->fetchAll(PDO::FETCH_ASSOC);

// Count of unallocated students
$unallocatedCount = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE id NOT IN (SELECT student_id FROM student_house_allocations WHERE status = 'Active')")->fetchColumn();

// Students for manual assignment modal
$allStudents = $pdo->query("SELECT s.id, s.admission_no, s.first_name, s.last_name, e.class_name FROM students s LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1 ORDER BY s.admission_no ASC")->fetchAll(PDO::FETCH_ASSOC);
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
                    <span class="eyebrow">House Management</span>
                    <h1>Student House Allocations</h1>
                    <p>Assign students to houses, appoint House Captains and Prefects, and use automatic balancing for new students.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <form method="post" style="display:inline;" onsubmit="return confirm('Auto-balance and allocate all unassigned students evenly across active houses?')">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="auto_balance">
                        <button type="submit" class="btn btn-sm btn-outline">
                            ⚖ Auto-Balance <?= $unallocatedCount > 0 ? "({$unallocatedCount} Unassigned)" : '' ?>
                        </button>
                    </form>
                    <a href="house-management.php" class="btn btn-sm btn-soft">← Back to Leaderboard</a>
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
            <form method="get" action="house-allocations.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:200px;">
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by student name or admission number..." style="width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                </div>
                <div>
                    <select name="house_id" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Houses</option>
                        <?php foreach ($houses as $h): ?>
                            <option value="<?= (int) $h['id'] ?>" <?= (string)$filterHouse === (string)$h['id'] ? 'selected' : '' ?>><?= e($h['house_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select name="role" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Roles</option>
                        <option value="Captain" <?= $filterRole === 'Captain' ? 'selected' : '' ?>>Captain</option>
                        <option value="Vice-Captain" <?= $filterRole === 'Vice-Captain' ? 'selected' : '' ?>>Vice-Captain</option>
                        <option value="Prefect" <?= $filterRole === 'Prefect' ? 'selected' : '' ?>>Prefect</option>
                        <option value="Member" <?= $filterRole === 'Member' ? 'selected' : '' ?>>Member</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <?php if ($search !== '' || $filterHouse !== '' || $filterRole !== ''): ?>
                    <a href="house-allocations.php" class="btn btn-sm btn-soft">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Allocation Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Active House Allocations (<?= count($allocList) ?>)</h3>
                <span style="font-size:.8rem;color:#64748b;">Assigned to houses</span>
            </div>

            <?php if (empty($allocList)): ?>
                <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    No house allocations found. Click "<strong>⚖ Auto-Balance</strong>" to allocate students automatically.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Adm No</th>
                                <th>Student Name</th>
                                <th>Class & Sec</th>
                                <th>House Assigned</th>
                                <th>House Role</th>
                                <th>Academic Session</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allocList as $a): ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($a['admission_no']) ?></td>
                                    <td><strong><?= e($a['first_name'] . ' ' . $a['last_name']) ?></strong></td>
                                    <td><?= e($a['class_name'] ?: '—') ?> <?= $a['section_name'] ? ('(' . e($a['section_name']) . ')') : '' ?></td>
                                    <td>
                                        <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:700;background:<?= e($a['color_code']) ?>20;color:<?= e($a['color_code']) ?>;">
                                            <span style="width:8px;height:8px;border-radius:50%;background:<?= e($a['color_code']) ?>;"></span>
                                            <?= e($a['house_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($a['role'] === 'Captain'): ?>
                                            <span class="badge" style="background:#fef08a;color:#854d0e;font-weight:800;">⭐ Captain</span>
                                        <?php elseif ($a['role'] === 'Vice-Captain'): ?>
                                            <span class="badge" style="background:#e0e7ff;color:#3730a3;font-weight:700;">Vice-Captain</span>
                                        <?php elseif ($a['role'] === 'Prefect'): ?>
                                            <span class="badge" style="background:#d1fae5;color:#065f46;font-weight:700;">Prefect</span>
                                        <?php else: ?>
                                            <span style="color:#64748b;font-size:.85rem;">Member</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($a['academic_year']) ?></td>
                                    <td style="text-align:center;">
                                        <button type="button" class="btn btn-sm btn-outline" onclick='editAlloc(<?= json_encode([
                                            'student_id' => $a['student_id'],
                                            'house_id' => $a['house_id'],
                                            'role' => $a['role'],
                                            'academic_year' => $a['academic_year'],
                                            'student_name' => $a['first_name'] . ' ' . $a['last_name']
                                        ]) ?>)'>
                                            Change House
                                        </button>
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

<!-- ═══════════════════ ASSIGN / CHANGE HOUSE MODAL ═══════════════════ -->
<div id="allocModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:540px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="allocModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Assign Student to House</h2>
            <button type="button" class="icon-btn" onclick="closeModal('allocModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_allocation">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Select Student *</label>
                <select name="student_id" id="allocStudentSelect" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                    <option value="">-- Choose Student --</option>
                    <?php foreach ($allStudents as $st): ?>
                        <option value="<?= (int) $st['id'] ?>">
                            <?= e($st['admission_no']) ?> – <?= e($st['first_name'] . ' ' . $st['last_name']) ?> (<?= e($st['class_name'] ?: 'Enrolled') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Select House *</label>
                <select name="house_id" id="allocHouseSelect" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                    <option value="">-- Choose House --</option>
                    <?php foreach ($houses as $h): ?>
                        <option value="<?= (int) $h['id'] ?>"><?= e($h['house_name']) ?> (<?= e($h['house_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">House Role</label>
                    <select name="role" id="allocRoleSelect" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                        <option value="Member">Member</option>
                        <option value="Captain">Captain</option>
                        <option value="Vice-Captain">Vice-Captain</option>
                        <option value="Prefect">Prefect</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Academic Session</label>
                    <input type="text" name="academic_year" id="allocYear" value="2025-2026" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('allocModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Save Allocation</button>
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
function editAlloc(data) {
    document.getElementById('allocStudentSelect').value = data.student_id;
    document.getElementById('allocHouseSelect').value = data.house_id;
    document.getElementById('allocRoleSelect').value = data.role;
    document.getElementById('allocYear').value = data.academic_year;
    document.getElementById('allocModalTitle').textContent = 'Reassign House for ' + data.student_name;
    openModal('allocModal');
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
