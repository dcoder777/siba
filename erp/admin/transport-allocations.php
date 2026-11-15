<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$activePage = 'transport-allocations.php';

// Self-healing table check
$pdo->exec("
    CREATE TABLE IF NOT EXISTS transport_allocations (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        student_id BIGINT NOT NULL,
        route_id BIGINT NOT NULL,
        pickup_point VARCHAR(120) NULL,
        drop_point VARCHAR(120) NULL,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$msg = '';
$err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $err = 'Security validation failed (CSRF).';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'allocate_student') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $routeId = (int) ($_POST['route_id'] ?? 0);
            $pickup = trim((string) ($_POST['pickup_point'] ?? ''));
            $drop = trim((string) ($_POST['drop_point'] ?? ''));

            if ($studentId <= 0 || $routeId <= 0) {
                $err = 'Please select both a valid student and a transport route.';
            } else {
                // Check if student already has active transport
                $existing = $pdo->prepare("SELECT id FROM transport_allocations WHERE student_id = ? AND status = 'active'");
                $existing->execute([$studentId]);
                $exId = $existing->fetchColumn();

                if ($exId) {
                    $err = 'This student already has an active bus route allocated. Vacate or edit that record first.';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO transport_allocations (student_id, route_id, pickup_point, drop_point, status) VALUES (?, ?, ?, ?, 'active')");
                        $stmt->execute([$studentId, $routeId, $pickup, $drop]);
                        $msg = 'Student transport seat allocated successfully.';
                    } catch (\PDOException $e) {
                        $err = 'Error allocating seat: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'update_allocation') {
            $allocId = (int) ($_POST['alloc_id'] ?? 0);
            $routeId = (int) ($_POST['route_id'] ?? 0);
            $pickup = trim((string) ($_POST['pickup_point'] ?? ''));
            $drop = trim((string) ($_POST['drop_point'] ?? ''));
            $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

            if ($allocId <= 0 || $routeId <= 0) {
                $err = 'Invalid allocation parameters.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE transport_allocations SET route_id = ?, pickup_point = ?, drop_point = ?, status = ? WHERE id = ?");
                    $stmt->execute([$routeId, $pickup, $drop, $status, $allocId]);
                    $msg = 'Student transport allocation updated.';
                } catch (\PDOException $e) {
                    $err = 'Error updating allocation: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'toggle_status') {
            $allocId = (int) ($_POST['alloc_id'] ?? 0);
            $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';
            if ($allocId > 0) {
                $pdo->prepare("UPDATE transport_allocations SET status = ? WHERE id = ?")->execute([$newStatus, $allocId]);
                $msg = "Seat allocation marked as {$newStatus}.";
            }
        } elseif ($action === 'delete_allocation') {
            $allocId = (int) ($_POST['alloc_id'] ?? 0);
            if ($allocId > 0) {
                $pdo->prepare("DELETE FROM transport_allocations WHERE id = ?")->execute([$allocId]);
                $msg = 'Student transport allocation deleted.';
            }
        }
    }
}

// Filters
$selectedRouteId = (int) ($_GET['route_id'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? 'active'));
$q = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT ta.*,
           s.admission_no, s.first_name, s.last_name, s.gender,
           se.class_name, se.section_name,
           r.route_name, r.vehicle_no, r.monthly_fare, r.driver_name
    FROM transport_allocations ta
    JOIN students s ON s.id = ta.student_id
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1
    JOIN transport_routes r ON r.id = ta.route_id
    WHERE 1=1
";
$params = [];

if ($selectedRouteId > 0) {
    $sql .= " AND ta.route_id = ?";
    $params[] = $selectedRouteId;
}
if ($filterStatus !== '') {
    $sql .= " AND ta.status = ?";
    $params[] = $filterStatus;
}
if ($q !== '') {
    $sql .= " AND (s.admission_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR ta.pickup_point LIKE ? OR ta.drop_point LIKE ?)";
    $like = "%{$q}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
$sql .= " ORDER BY ta.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Route list for filter & modal
$routes = $pdo->query("SELECT id, route_name, vehicle_no, monthly_fare FROM transport_routes ORDER BY route_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Students for allocation dropdown
$availableStudents = $pdo->query("
    SELECT s.id, s.admission_no, s.first_name, s.last_name, se.class_name, se.section_name
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1
    WHERE s.status = 'active'
    ORDER BY s.first_name ASC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$totalAllocated = (int) $pdo->query("SELECT COUNT(*) FROM transport_allocations WHERE status = 'active'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Transport Allocations | Operations & Fleet</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
</head>
<body class="erp-body">
<div class="erp-layout">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="erp-main">
        <header class="erp-header">
            <div>
                <nav class="breadcrumb-nav">
                    <span>Operations & Fleet</span> &gt;
                    <strong>Student Transport</strong>
                </nav>
                <h1 class="page-title">Transport Seat Allocation</h1>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAllocateStudent">+ Assign Student to Route</button>
            </div>
        </header>

        <div class="erp-content">
            <?php if ($msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($err): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= e($err) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Metrics bar -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #10b981 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Active Commuters</span>
                        <div class="fs-3 fw-bold text-success"><?= $totalAllocated ?></div>
                        <span class="small text-muted">Students riding buses daily</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #3b82f6 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Routes In Operation</span>
                        <div class="fs-3 fw-bold text-dark"><?= count($routes) ?></div>
                        <span class="small text-muted">Daily pickup/drop routes</span>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-center">
                        <div class="col-md-4">
                            <input type="text" name="q" class="form-control" placeholder="Search student name, admission no, stop..." value="<?= e($q) ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="route_id" class="form-select">
                                <option value="0">All Transport Routes</option>
                                <?php foreach ($routes as $rt): ?>
                                    <option value="<?= (int) $rt['id'] ?>" <?= $selectedRouteId === (int) $rt['id'] ? 'selected' : '' ?>>
                                        <?= e($rt['route_name']) ?> (<?= e($rt['vehicle_no']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-select">
                                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active Riders</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Vacated / Inactive</option>
                                <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>All Statuses</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-dark w-100">Filter</button>
                        </div>
                        <div class="col-md-1">
                            <a href="transport-allocations.php" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Allocations Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">Assigned Student Commuters (<?= count($allocations) ?>)</h5>
                    <div class="small text-muted">Operations &amp; Fleet</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Class & Section</th>
                                <th>Assigned Bus & Route</th>
                                <th>Pickup Stop</th>
                                <th>Drop Stop</th>
                                <th>Monthly Fare</th>
                                <th>Status</th>
                                <th style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allocations)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No student allocations found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allocations as $al): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= e($al['first_name'] . ' ' . $al['last_name']) ?></div>
                                            <span class="badge bg-light text-dark border font-monospace"><?= e($al['admission_no']) ?></span>
                                        </td>
                                        <td><?= e($al['class_name'] ?: 'Class 1') ?> <?= $al['section_name'] ? ('- ' . e($al['section_name'])) : '' ?></td>
                                        <td>
                                            <div class="fw-semibold text-primary"><?= e($al['route_name']) ?></div>
                                            <small class="text-muted font-monospace"><?= e($al['vehicle_no']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                📍 <?= e($al['pickup_point'] ?: 'Designated Stop') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                🏁 <?= e($al['drop_point'] ?: 'Campus Gate') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success">₹<?= number_format((float) $al['monthly_fare'], 2) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($al['status'] === 'active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditAlloc<?= (int) $al['id'] ?>">
                                                    Edit
                                                </button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Change status of this student transport seat?');">
                                                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="alloc_id" value="<?= (int) $al['id'] ?>">
                                                    <input type="hidden" name="new_status" value="<?= $al['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                    <button type="submit" class="btn btn-outline-secondary" title="<?= $al['status'] === 'active' ? 'Vacate Seat' : 'Reactivate' ?>">
                                                        <?= $al['status'] === 'active' ? 'Vacate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                            </div>

                                            <!-- Modal Edit Allocation -->
                                            <div class="modal fade" id="modalEditAlloc<?= (int) $al['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="post">
                                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                            <input type="hidden" name="action" value="update_allocation">
                                                            <input type="hidden" name="alloc_id" value="<?= (int) $al['id'] ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Route: <?= e($al['first_name'] . ' ' . $al['last_name']) ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Select Transport Route *</label>
                                                                    <select name="route_id" class="form-select" required>
                                                                        <?php foreach ($routes as $rt): ?>
                                                                            <option value="<?= (int) $rt['id'] ?>" <?= ((int) $al['route_id'] === (int) $rt['id']) ? 'selected' : '' ?>>
                                                                                <?= e($rt['route_name']) ?> (<?= e($rt['vehicle_no']) ?>) – ₹<?= number_format((float) $rt['monthly_fare'], 2) ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Pickup Stop</label>
                                                                    <input type="text" name="pickup_point" class="form-control" value="<?= e($al['pickup_point'] ?? '') ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Drop Stop</label>
                                                                    <input type="text" name="drop_point" class="form-control" value="<?= e($al['drop_point'] ?? '') ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Allocation Status</label>
                                                                    <select name="status" class="form-select">
                                                                        <option value="active" <?= $al['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                                        <option value="inactive" <?= $al['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (Vacated)</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-primary">Save Allocation</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modal Allocate Student -->
<div class="modal fade" id="modalAllocateStudent" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="allocate_student">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Student to Transport</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Student *</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">-- Choose Student --</option>
                            <?php foreach ($availableStudents as $ast): ?>
                                <option value="<?= (int) $ast['id'] ?>">
                                    <?= e($ast['first_name'] . ' ' . $ast['last_name']) ?> (<?= e($ast['admission_no']) ?> - <?= e($ast['class_name'] ?? 'Class') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Transport Route *</label>
                        <select name="route_id" class="form-select" required>
                            <option value="">-- Choose Bus Route --</option>
                            <?php foreach ($routes as $rt): ?>
                                <option value="<?= (int) $rt['id'] ?>">
                                    <?= e($rt['route_name']) ?> (<?= e($rt['vehicle_no']) ?>) – ₹<?= number_format((float) $rt['monthly_fare'], 2) ?>/mo
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pickup Stop / Location</label>
                        <input type="text" name="pickup_point" class="form-control" placeholder="e.g. Master Canteen Square">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Drop Stop / Location</label>
                        <input type="text" name="drop_point" class="form-control" placeholder="e.g. SIBA Campus Gate">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Allocate Seat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
