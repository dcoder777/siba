<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();

$pageTitle = 'Hostel Accounts';
$error = '';
$success = '';

// ── Ensure table exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hostel_fee_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        student_name VARCHAR(255) NOT NULL,
        hostel_name VARCHAR(255) NOT NULL,
        room_number VARCHAR(50) DEFAULT '',
        bed_number VARCHAR(50) DEFAULT '',
        joining_date DATE,
        monthly_room_fee DECIMAL(12,2) DEFAULT 0,
        meal_fee DECIMAL(12,2) DEFAULT 0,
        security_deposit DECIMAL(12,2) DEFAULT 0,
        emi_allowed TINYINT(1) DEFAULT 0,
        status ENUM('Active','Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_assignment') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $studentName = trim((string) ($_POST['student_name'] ?? ''));
            $hostelName = trim((string) ($_POST['hostel_name'] ?? ''));
            $roomNumber = trim((string) ($_POST['room_number'] ?? ''));
            $bedNumber = trim((string) ($_POST['bed_number'] ?? ''));
            $joiningDate = trim((string) ($_POST['joining_date'] ?? ''));
            $monthlyRoomFee = (float) ($_POST['monthly_room_fee'] ?? 0);
            $mealFee = (float) ($_POST['meal_fee'] ?? 0);
            $securityDeposit = (float) ($_POST['security_deposit'] ?? 0);
            $emiAllowed = isset($_POST['emi_allowed']) ? 1 : 0;

            if ($studentId <= 0 || $studentName === '' || $hostelName === '') {
                throw new \RuntimeException('Student, hostel name are required.');
            }

            $pdo->prepare("INSERT INTO hostel_fee_assignments (student_id, student_name, hostel_name, room_number, bed_number, joining_date, monthly_room_fee, meal_fee, security_deposit, emi_allowed) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$studentId, $studentName, $hostelName, $roomNumber, $bedNumber, $joiningDate ?: null, $monthlyRoomFee, $mealFee, $securityDeposit, $emiAllowed]);
            $success = 'Hostel fee assignment created successfully.';
        }

        if ($action === 'update_assignment') {
            $id = (int) ($_POST['id'] ?? 0);
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $studentName = trim((string) ($_POST['student_name'] ?? ''));
            $hostelName = trim((string) ($_POST['hostel_name'] ?? ''));
            $roomNumber = trim((string) ($_POST['room_number'] ?? ''));
            $bedNumber = trim((string) ($_POST['bed_number'] ?? ''));
            $joiningDate = trim((string) ($_POST['joining_date'] ?? ''));
            $monthlyRoomFee = (float) ($_POST['monthly_room_fee'] ?? 0);
            $mealFee = (float) ($_POST['meal_fee'] ?? 0);
            $securityDeposit = (float) ($_POST['security_deposit'] ?? 0);
            $emiAllowed = isset($_POST['emi_allowed']) ? 1 : 0;

            if ($id <= 0 || $studentId <= 0 || $studentName === '' || $hostelName === '') {
                throw new \RuntimeException('Student, hostel name are required.');
            }

            $pdo->prepare("UPDATE hostel_fee_assignments SET student_id=?, student_name=?, hostel_name=?, room_number=?, bed_number=?, joining_date=?, monthly_room_fee=?, meal_fee=?, security_deposit=?, emi_allowed=?, updated_at=NOW() WHERE id=?")
                ->execute([$studentId, $studentName, $hostelName, $roomNumber, $bedNumber, $joiningDate ?: null, $monthlyRoomFee, $mealFee, $securityDeposit, $emiAllowed, $id]);
            $success = 'Assignment updated successfully.';
        }

        if ($action === 'delete_assignment') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE hostel_fee_assignments SET status='Inactive', updated_at=NOW() WHERE id=?")->execute([$id]);
                $success = 'Assignment deactivated.';
            }
        }

    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: hostel-accounts.php?success=' . urlencode($success));
        exit;
    }
}

if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}

// ── Fetch data ──
$assignments = $pdo->query("SELECT * FROM hostel_fee_assignments ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$activeResidents = 0;
$totalMonthlyRevenue = 0.0;
$totalSecurityDeposits = 0.0;
foreach ($assignments as $a) {
    if ($a['status'] === 'Active') {
        $activeResidents++;
        $totalMonthlyRevenue += (float) $a['monthly_room_fee'] + (float) $a['meal_fee'];
    }
    $totalSecurityDeposits += (float) $a['security_deposit'];
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
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Module 15</span>
                    <h1>Hostel Accounts</h1>
                    <p>Manage hostel fee assignments for students — room fee, meal fee, security deposits, and EMI plans.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button class="btn btn-sm" onclick="openCreateAssignment()">+ Assign Hostel Fee</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="kpi-grid" style="margin-bottom:1.25rem;">
            <div class="kpi-card">
                <div class="kpi-label">Active Residents</div>
                <div class="kpi-value"><?= $activeResidents ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Total Monthly Revenue</div>
                <div class="kpi-value kpi-value-currency">₹ <?= number_format($totalMonthlyRevenue, 2) ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Security Deposits</div>
                <div class="kpi-value kpi-value-currency">₹ <?= number_format($totalSecurityDeposits, 2) ?></div>
            </div>
        </div>

        <!-- Assignments Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                <div class="toolbar">
                    <h3 style="margin:0;font-size:1rem;">Hostel Fee Assignments</h3>
                    <span style="color:#64748b;font-size:.85rem;"><?= count($assignments) ?> record(s)</span>
                </div>
            </div>

            <?php if (empty($assignments)): ?>
                <div style="padding:2rem;text-align:center;color:#94a3b8;">No hostel fee assignments found. Add one to get started.</div>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Hostel</th>
                                <th>Room</th>
                                <th>Bed</th>
                                <th style="text-align:right">Monthly Fee</th>
                                <th style="text-align:right">Meal Fee</th>
                                <th style="text-align:right">Security</th>
                                <th style="text-align:center">EMI</th>
                                <th style="text-align:center">Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td><strong><?= e($a['student_name']) ?></strong></td>
                                    <td><?= e($a['hostel_name']) ?></td>
                                    <td><?= e($a['room_number'] ?: '—') ?></td>
                                    <td><?= e($a['bed_number'] ?: '—') ?></td>
                                    <td style="text-align:right;">₹ <?= number_format((float) $a['monthly_room_fee'], 2) ?></td>
                                    <td style="text-align:right;">₹ <?= number_format((float) $a['meal_fee'], 2) ?></td>
                                    <td style="text-align:right;">₹ <?= number_format((float) $a['security_deposit'], 2) ?></td>
                                    <td style="text-align:center;">
                                        <?php if ($a['emi_allowed']): ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#d1fae5;color:#065f46;font-size:.75rem;font-weight:600;">Yes</span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#f1f5f9;color:#475569;font-size:.75rem;font-weight:600;">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($a['status'] === 'Active'): ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#d1fae5;color:#065f46;font-size:.75rem;font-weight:600;">Active</span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#f8d7da;color:#842029;font-size:.75rem;font-weight:600;">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:.3rem;flex-wrap:nowrap;">
                                            <button class="btn btn-sm btn-outline" onclick='editAssignment(<?= json_encode([
                                                'id' => $a['id'],
                                                'student_id' => $a['student_id'],
                                                'student_name' => $a['student_name'],
                                                'hostel_name' => $a['hostel_name'],
                                                'room_number' => $a['room_number'],
                                                'bed_number' => $a['bed_number'],
                                                'joining_date' => $a['joining_date'],
                                                'monthly_room_fee' => $a['monthly_room_fee'],
                                                'meal_fee' => $a['meal_fee'],
                                                'security_deposit' => $a['security_deposit'],
                                                'emi_allowed' => $a['emi_allowed']
                                            ]) ?>)'>Edit</button>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Deactivate this assignment?');">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_assignment">
                                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
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

<!-- ═══════════════════ ADD/EDIT ASSIGNMENT MODAL ═══════════════════ -->
<div id="assignmentModal" class="modal-backdrop">
    <div class="modal" style="max-width:680px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;" id="assignModalTitle">Assign Hostel Fee</h2>
            <button class="icon-btn" onclick="closeModal('assignmentModal')">✕</button>
        </div>
        <form method="post" id="assignmentForm">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="assignFormAction" value="create_assignment">
            <input type="hidden" name="id" id="assignFormId" value="">
            <input type="hidden" name="student_id" id="assignStudentId" value="">
            <div class="field-grid">
                <div style="grid-column:1/-1;">
                    <label>Student *</label>
                    <input type="text" id="student_search" list="student_list" placeholder="Start typing student name..." autocomplete="off" required>
                    <datalist id="student_list"></datalist>
                </div>
                <div><label>Hostel Name *</label><input type="text" name="hostel_name" id="assignHostelName" placeholder="e.g. Boys Hostel A" required></div>
                <div><label>Room Number</label><input type="text" name="room_number" id="assignRoomNumber" placeholder="e.g. 101"></div>
                <div><label>Bed Number</label><input type="text" name="bed_number" id="assignBedNumber" placeholder="e.g. B2"></div>
                <div><label>Joining Date</label><input type="date" name="joining_date" id="assignJoiningDate"></div>
                <div><label>Monthly Room Fee (₹)</label><input type="number" step="0.01" min="0" name="monthly_room_fee" id="assignRoomFee" value="0"></div>
                <div><label>Meal Fee (₹)</label><input type="number" step="0.01" min="0" name="meal_fee" id="assignMealFee" value="0"></div>
                <div><label>Security Deposit (₹)</label><input type="number" step="0.01" min="0" name="security_deposit" id="assignSecurityDeposit" value="0"></div>
                <div style="display:flex;align-items:end;padding-bottom:.4rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;margin:0;font-weight:400;">
                        <input type="checkbox" name="emi_allowed" id="assignEmiAllowed" value="1" style="width:auto;min-height:auto;">
                        EMI Allowed
                    </label>
                </div>
            </div>
            <!-- Hidden field for student_name -->
            <input type="hidden" name="student_name" id="assignStudentName" value="">
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm" id="assignFormBtn">Create Assignment</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('assignmentModal')">Cancel</button>
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

// Load student list via AJAX
function loadStudents() {
    var dl = document.getElementById('student_list');
    if (dl.options.length > 0) return;
    fetch('application-intake.php?action=student_list', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (Array.isArray(data)) {
                data.forEach(function(s) {
                    var opt = document.createElement('option');
                    opt.value = s.name;
                    opt.setAttribute('data-id', s.id);
                    dl.appendChild(opt);
                });
            }
        })
        .catch(function() {});
}

function openCreateAssignment() {
    document.getElementById('assignmentForm').reset();
    document.getElementById('assignFormAction').value = 'create_assignment';
    document.getElementById('assignFormId').value = '';
    document.getElementById('assignStudentId').value = '';
    document.getElementById('assignStudentName').value = '';
    document.getElementById('assignFormBtn').textContent = 'Create Assignment';
    document.getElementById('assignModalTitle').textContent = 'Assign Hostel Fee';
    document.getElementById('assignJoiningDate').value = '<?= date('Y-m-d') ?>';
    loadStudents();
    openModal('assignmentModal');
}

function editAssignment(row) {
    document.getElementById('assignFormAction').value = 'update_assignment';
    document.getElementById('assignFormId').value = row.id;
    document.getElementById('assignStudentId').value = row.student_id;
    document.getElementById('assignStudentName').value = row.student_name;
    document.getElementById('student_search').value = row.student_name;
    document.getElementById('assignHostelName').value = row.hostel_name;
    document.getElementById('assignRoomNumber').value = row.room_number;
    document.getElementById('assignBedNumber').value = row.bed_number;
    document.getElementById('assignJoiningDate').value = row.joining_date || '';
    document.getElementById('assignRoomFee').value = row.monthly_room_fee || 0;
    document.getElementById('assignMealFee').value = row.meal_fee || 0;
    document.getElementById('assignSecurityDeposit').value = row.security_deposit || 0;
    document.getElementById('assignEmiAllowed').checked = row.emi_allowed == 1;
    document.getElementById('assignFormBtn').textContent = 'Update Assignment';
    document.getElementById('assignModalTitle').textContent = 'Edit Hostel Fee Assignment';
    loadStudents();
    openModal('assignmentModal');
}

// Auto-fill student_id from datalist
document.getElementById('student_search').addEventListener('input', function() {
    var val = this.value;
    var opts = document.getElementById('student_list').options;
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) {
            document.getElementById('assignStudentId').value = opts[i].getAttribute('data-id') || '';
            document.getElementById('assignStudentName').value = val;
            break;
        }
    }
});

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
