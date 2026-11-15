<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$activePage = 'transport-routes.php';

// Self-healing table check
$pdo->exec("
    CREATE TABLE IF NOT EXISTS transport_routes (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        route_name VARCHAR(100) NOT NULL,
        vehicle_id INT NULL,
        vehicle_no VARCHAR(30) NULL,
        driver_name VARCHAR(120) NULL,
        attendant_name VARCHAR(120) NULL,
        start_location VARCHAR(150) NULL,
        end_location VARCHAR(150) NULL,
        monthly_fare DECIMAL(10,2) DEFAULT 0.00,
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

        if ($action === 'add_route') {
            $rName = trim((string) ($_POST['route_name'] ?? ''));
            $vId = (int) ($_POST['vehicle_id'] ?? 0);
            $startLoc = trim((string) ($_POST['start_location'] ?? ''));
            $endLoc = trim((string) ($_POST['end_location'] ?? ''));
            $fare = (float) ($_POST['monthly_fare'] ?? 0.0);
            $attendant = trim((string) ($_POST['attendant_name'] ?? ''));

            if ($rName === '') {
                $err = 'Route name is required.';
            } else {
                // Fetch vehicle info
                $vNo = '';
                $driver = '';
                if ($vId > 0) {
                    $vRow = $pdo->query("SELECT vehicle_no, driver_name FROM transport_vehicles WHERE id = $vId")->fetch(PDO::FETCH_ASSOC);
                    if ($vRow) {
                        $vNo = $vRow['vehicle_no'];
                        $driver = $vRow['driver_name'];
                    }
                }
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO transport_routes (route_name, vehicle_id, vehicle_no, driver_name, attendant_name, start_location, end_location, monthly_fare)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$rName, $vId ?: null, $vNo, $driver, $attendant, $startLoc, $endLoc, $fare]);
                    $msg = "Route '{$rName}' created successfully.";
                } catch (\PDOException $e) {
                    $err = 'Error creating route: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'edit_route') {
            $rId = (int) ($_POST['route_id'] ?? 0);
            $rName = trim((string) ($_POST['route_name'] ?? ''));
            $vId = (int) ($_POST['vehicle_id'] ?? 0);
            $startLoc = trim((string) ($_POST['start_location'] ?? ''));
            $endLoc = trim((string) ($_POST['end_location'] ?? ''));
            $fare = (float) ($_POST['monthly_fare'] ?? 0.0);
            $attendant = trim((string) ($_POST['attendant_name'] ?? ''));

            if ($rId <= 0 || $rName === '') {
                $err = 'Invalid route parameters.';
            } else {
                $vNo = '';
                $driver = '';
                if ($vId > 0) {
                    $vRow = $pdo->query("SELECT vehicle_no, driver_name FROM transport_vehicles WHERE id = $vId")->fetch(PDO::FETCH_ASSOC);
                    if ($vRow) {
                        $vNo = $vRow['vehicle_no'];
                        $driver = $vRow['driver_name'];
                    }
                }
                try {
                    $stmt = $pdo->prepare("
                        UPDATE transport_routes 
                        SET route_name = ?, vehicle_id = ?, vehicle_no = ?, driver_name = ?, attendant_name = ?, start_location = ?, end_location = ?, monthly_fare = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$rName, $vId ?: null, $vNo, $driver, $attendant, $startLoc, $endLoc, $fare, $rId]);
                    $msg = "Route '{$rName}' updated successfully.";
                } catch (\PDOException $e) {
                    $err = 'Error updating route: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_route') {
            $rId = (int) ($_POST['route_id'] ?? 0);
            if ($rId > 0) {
                $allocCount = (int) $pdo->query("SELECT COUNT(*) FROM transport_allocations WHERE route_id = $rId AND status = 'active'")->fetchColumn();
                if ($allocCount > 0) {
                    $err = "Cannot delete route: {$allocCount} student(s) currently allocated to this route. Reassign or vacate them first.";
                } else {
                    $pdo->prepare("DELETE FROM transport_routes WHERE id = ?")->execute([$rId]);
                    $msg = 'Route deleted successfully.';
                }
            }
        }
    }
}

// Fetch all routes with vehicle details & passenger count
$routes = $pdo->query("
    SELECT r.*,
           v.capacity,
           v.status as vehicle_status,
           (SELECT COUNT(*) FROM transport_allocations WHERE route_id = r.id AND status = 'active') as active_passengers
    FROM transport_routes r
    LEFT JOIN transport_vehicles v ON v.id = r.vehicle_id
    ORDER BY r.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Vehicles for dropdown
$allVehicles = $pdo->query("SELECT id, vehicle_no, model, capacity, driver_name, status FROM transport_vehicles ORDER BY vehicle_no ASC")->fetchAll(PDO::FETCH_ASSOC);

// Total active passengers across routes
$totalPassengers = (int) $pdo->query("SELECT COUNT(*) FROM transport_allocations WHERE status = 'active'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Transport Routes | Operations & Fleet</title>
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
                    <strong>Transport Routes</strong>
                </nav>
                <h1 class="page-title">Bus Routes & Schedules</h1>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddRoute">+ Create New Route</button>
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

            <!-- Metrics -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #3b82f6 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Active Routes</span>
                        <div class="fs-3 fw-bold text-dark"><?= count($routes) ?></div>
                        <span class="small text-muted">Covering city & suburbs</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #10b981 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Enrolled Students</span>
                        <div class="fs-3 fw-bold text-success"><?= $totalPassengers ?></div>
                        <span class="small text-muted">Daily commuters</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #6366f1 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Assigned Vehicles</span>
                        <div class="fs-3 fw-bold text-primary">
                            <?= count(array_filter($routes, fn($r) => !empty($r['vehicle_no']))) ?>
                        </div>
                        <span class="small text-muted">Dedicated school buses</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #f59e0b !important;">
                        <span class="text-muted small text-uppercase fw-bold">Student Allocations</span>
                        <div class="fs-3 fw-bold text-dark">
                            <a href="transport-allocations.php" class="btn btn-sm btn-outline-warning text-dark fw-bold mt-1">Manage Seats →</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Routes Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">Configured Transport Routes</h5>
                    <a href="transport-allocations.php" class="btn btn-sm btn-outline-primary">View Student Allocations</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Route Name & Trajectory</th>
                                <th>Assigned Vehicle</th>
                                <th>Driver & Attendant</th>
                                <th>Monthly Fare</th>
                                <th>Occupancy</th>
                                <th style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($routes)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No transport routes configured.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($routes as $r): 
                                    $cap = (int) ($r['capacity'] ?? 30);
                                    $pCount = (int) $r['active_passengers'];
                                    $pct = $cap > 0 ? min(100, round(($pCount / $cap) * 100)) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold fs-6 text-dark"><?= e($r['route_name']) ?></div>
                                            <div class="small text-muted">
                                                🚩 <?= e($r['start_location'] ?: 'Campus') ?> ➔ 🏁 <?= e($r['end_location'] ?: 'Destination') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($r['vehicle_no']): ?>
                                                <span class="fw-bold font-monospace text-primary"><?= e($r['vehicle_no']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= e($r['driver_name'] ?: 'No Driver') ?></div>
                                            <?php if ($r['attendant_name']): ?>
                                                <small class="text-muted">Attendant: <?= e($r['attendant_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-success">₹<?= number_format((float) $r['monthly_fare'], 2) ?></div>
                                            <small class="text-muted">/ month</small>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="fw-bold"><?= $pCount ?> / <?= $cap ?></span>
                                                <div class="progress flex-grow-1" style="height: 6px; width: 70px;">
                                                    <div class="progress-bar <?= $pct > 90 ? 'bg-danger' : ($pct > 70 ? 'bg-warning' : 'bg-success') ?>"
                                                         style="width: <?= $pct ?>%"></div>
                                                </div>
                                            </div>
                                            <a href="transport-allocations.php?route_id=<?= (int) $r['id'] ?>" class="small text-decoration-none">
                                                View Students (<?= $pCount ?>)
                                            </a>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditRoute<?= (int) $r['id'] ?>">
                                                    Edit
                                                </button>
                                                <button type="button" class="btn btn-outline-danger"
                                                        data-bs-toggle="modal" data-bs-target="#modalDeleteRoute<?= (int) $r['id'] ?>">
                                                    ✕
                                                </button>
                                            </div>

                                            <!-- Modal Edit -->
                                            <div class="modal fade" id="modalEditRoute<?= (int) $r['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="post">
                                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                            <input type="hidden" name="action" value="edit_route">
                                                            <input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Route: <?= e($r['route_name']) ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Route Name *</label>
                                                                    <input type="text" name="route_name" class="form-control" required value="<?= e($r['route_name']) ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Assign Vehicle</label>
                                                                    <select name="vehicle_id" class="form-select">
                                                                        <option value="">-- No Vehicle Assigned --</option>
                                                                        <?php foreach ($allVehicles as $veh): ?>
                                                                            <option value="<?= (int) $veh['id'] ?>" <?= ((int) $r['vehicle_id'] === (int) $veh['id']) ? 'selected' : '' ?>>
                                                                                <?= e($veh['vehicle_no']) ?> (<?= e($veh['model']) ?> - <?= (int) $veh['capacity'] ?> Seats - Driver: <?= e($veh['driver_name']) ?>)
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="row g-2 mb-3">
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold">Start Location</label>
                                                                        <input type="text" name="start_location" class="form-control" value="<?= e($r['start_location'] ?? '') ?>">
                                                                    </div>
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold">End Location</label>
                                                                        <input type="text" name="end_location" class="form-control" value="<?= e($r['end_location'] ?? '') ?>">
                                                                    </div>
                                                                </div>
                                                                <div class="row g-2 mb-3">
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold">Monthly Fare (₹)</label>
                                                                        <input type="number" step="0.01" name="monthly_fare" class="form-control" value="<?= (float) $r['monthly_fare'] ?>">
                                                                    </div>
                                                                    <div class="col-6">
                                                                        <label class="form-label fw-bold">Attendant / Conductor</label>
                                                                        <input type="text" name="attendant_name" class="form-control" value="<?= e($r['attendant_name'] ?? '') ?>">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Modal Delete -->
                                            <div class="modal fade" id="modalDeleteRoute<?= (int) $r['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="post">
                                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                            <input type="hidden" name="action" value="delete_route">
                                                            <input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title text-danger">Delete Transport Route</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                Are you sure you want to delete route <strong><?= e($r['route_name']) ?></strong>?
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-danger">Confirm Delete</button>
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

<!-- Modal Add Route -->
<div class="modal fade" id="modalAddRoute" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_route">
                <div class="modal-header">
                    <h5 class="modal-title">Create Transport Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Route Name *</label>
                        <input type="text" name="route_name" class="form-control" required placeholder="e.g. Route 9 - Airport Road to SIBA">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Assign Fleet Vehicle</label>
                        <select name="vehicle_id" class="form-select">
                            <option value="">-- Choose Fleet Vehicle --</option>
                            <?php foreach ($allVehicles as $veh): ?>
                                <option value="<?= (int) $veh['id'] ?>">
                                    <?= e($veh['vehicle_no']) ?> (<?= e($veh['model']) ?> - <?= (int) $veh['capacity'] ?> Seats)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Start Location</label>
                            <input type="text" name="start_location" class="form-control" placeholder="e.g. Master Canteen">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">End Location</label>
                            <input type="text" name="end_location" class="form-control" placeholder="e.g. SIBA Campus Gate">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Monthly Fare (₹)</label>
                            <input type="number" step="0.01" name="monthly_fare" class="form-control" value="1200.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Attendant Name</label>
                            <input type="text" name="attendant_name" class="form-control" placeholder="e.g. Anita Mohanty">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Route</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
