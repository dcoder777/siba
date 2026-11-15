<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$activePage = 'fleet-management.php';

// Self-healing table initialization
$pdo->exec("
    CREATE TABLE IF NOT EXISTS transport_vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_no VARCHAR(50) NOT NULL,
        model VARCHAR(100) NULL,
        capacity INT NOT NULL DEFAULT 30,
        driver_name VARCHAR(120) NULL,
        driver_phone VARCHAR(30) NULL,
        status ENUM('Active', 'Maintenance', 'Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$msg = '';
$err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $err = 'Security validation failed (CSRF). Please reload and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'add_vehicle') {
            $vNo = trim((string) ($_POST['vehicle_no'] ?? ''));
            $model = trim((string) ($_POST['model'] ?? ''));
            $capacity = (int) ($_POST['capacity'] ?? 30);
            $driverName = trim((string) ($_POST['driver_name'] ?? ''));
            $driverPhone = trim((string) ($_POST['driver_phone'] ?? ''));
            $status = in_array($_POST['status'] ?? '', ['Active', 'Maintenance', 'Inactive'], true) ? $_POST['status'] : 'Active';

            if ($vNo === '') {
                $err = 'Vehicle registration number is required.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO transport_vehicles (vehicle_no, model, capacity, driver_name, driver_phone, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$vNo, $model, $capacity, $driverName, $driverPhone, $status]);
                    $msg = "Vehicle {$vNo} added successfully to the fleet.";
                } catch (\PDOException $e) {
                    $err = 'Error adding vehicle: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'update_vehicle') {
            $vId = (int) ($_POST['vehicle_id'] ?? 0);
            $vNo = trim((string) ($_POST['vehicle_no'] ?? ''));
            $model = trim((string) ($_POST['model'] ?? ''));
            $capacity = (int) ($_POST['capacity'] ?? 30);
            $driverName = trim((string) ($_POST['driver_name'] ?? ''));
            $driverPhone = trim((string) ($_POST['driver_phone'] ?? ''));
            $status = in_array($_POST['status'] ?? '', ['Active', 'Maintenance', 'Inactive'], true) ? $_POST['status'] : 'Active';

            if ($vId <= 0 || $vNo === '') {
                $err = 'Invalid vehicle parameters.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE transport_vehicles SET vehicle_no = ?, model = ?, capacity = ?, driver_name = ?, driver_phone = ?, status = ? WHERE id = ?");
                    $stmt->execute([$vNo, $model, $capacity, $driverName, $driverPhone, $status, $vId]);
                    $msg = "Vehicle {$vNo} updated successfully.";
                } catch (\PDOException $e) {
                    $err = 'Error updating vehicle: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'toggle_status') {
            $vId = (int) ($_POST['vehicle_id'] ?? 0);
            $newStatus = in_array($_POST['new_status'] ?? '', ['Active', 'Maintenance', 'Inactive'], true) ? $_POST['new_status'] : 'Active';
            if ($vId > 0) {
                $stmt = $pdo->prepare("UPDATE transport_vehicles SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $vId]);
                $msg = 'Vehicle status updated.';
            }
        } elseif ($action === 'delete_vehicle') {
            $vId = (int) ($_POST['vehicle_id'] ?? 0);
            if ($vId > 0) {
                // Check if route references this vehicle
                $inUse = (int) $pdo->query("SELECT COUNT(*) FROM transport_routes WHERE vehicle_id = $vId OR vehicle_no IN (SELECT vehicle_no FROM transport_vehicles WHERE id = $vId)")->fetchColumn();
                if ($inUse > 0) {
                    $err = "Cannot delete vehicle: It is currently assigned to {$inUse} active transport route(s). Reassign routes first.";
                } else {
                    $pdo->prepare("DELETE FROM transport_vehicles WHERE id = ?")->execute([$vId]);
                    $msg = 'Vehicle removed from fleet.';
                }
            }
        }
    }
}

// Filters & Search
$q = trim((string) ($_GET['q'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

$sql = "
    SELECT v.*, 
           (SELECT COUNT(*) FROM transport_routes WHERE vehicle_id = v.id OR vehicle_no = v.vehicle_no) as assigned_routes,
           (SELECT route_name FROM transport_routes WHERE vehicle_id = v.id OR vehicle_no = v.vehicle_no LIMIT 1) as primary_route
    FROM transport_vehicles v
    WHERE 1=1
";
$params = [];

if ($q !== '') {
    $sql .= " AND (v.vehicle_no LIKE ? OR v.model LIKE ? OR v.driver_name LIKE ? OR v.driver_phone LIKE ?)";
    $like = "%{$q}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($filterStatus !== '') {
    $sql .= " AND v.status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY v.status ASC, v.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary metrics
$totalVehicles = (int) $pdo->query("SELECT COUNT(*) FROM transport_vehicles")->fetchColumn();
$activeVehicles = (int) $pdo->query("SELECT COUNT(*) FROM transport_vehicles WHERE status = 'Active'")->fetchColumn();
$maintVehicles = (int) $pdo->query("SELECT COUNT(*) FROM transport_vehicles WHERE status = 'Maintenance'")->fetchColumn();
$totalCapacity = (int) $pdo->query("SELECT SUM(capacity) FROM transport_vehicles WHERE status = 'Active'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Fleet & Vehicles Master | Operations & Fleet</title>
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
                    <strong>Fleet & Vehicles Master</strong>
                </nav>
                <h1 class="page-title">School Bus & Van Fleet</h1>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddVehicle">+ Add New Vehicle</button>
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

            <!-- Metrics row -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #3b82f6 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Total Fleet</span>
                        <div class="fs-3 fw-bold text-dark"><?= $totalVehicles ?></div>
                        <span class="small text-muted">Buses, Vans & Wings</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #10b981 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Active On Road</span>
                        <div class="fs-3 fw-bold text-success"><?= $activeVehicles ?></div>
                        <span class="small text-muted">Operating regular routes</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #f59e0b !important;">
                        <span class="text-muted small text-uppercase fw-bold">Under Maintenance</span>
                        <div class="fs-3 fw-bold text-warning"><?= $maintVehicles ?></div>
                        <span class="small text-muted">Servicing / Inspections</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #6366f1 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Total Seating</span>
                        <div class="fs-3 fw-bold text-primary"><?= number_format($totalCapacity) ?></div>
                        <span class="small text-muted">Active seat capacity</span>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-center">
                        <div class="col-md-5">
                            <input type="text" name="q" class="form-control" placeholder="Search vehicle reg no, model, driver..." value="<?= e($q) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-select">
                                <option value="">All Operational Statuses</option>
                                <option value="Active" <?= $filterStatus === 'Active' ? 'selected' : '' ?>>Active (On Road)</option>
                                <option value="Maintenance" <?= $filterStatus === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                <option value="Inactive" <?= $filterStatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-dark w-100">Filter</button>
                        </div>
                        <div class="col-md-2">
                            <a href="fleet-management.php" class="btn btn-outline-secondary w-100">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Fleet Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">Fleet Roster (<?= count($vehicles) ?>)</h5>
                    <div class="small text-muted">Operations &amp; Logistics</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vehicle Reg. No</th>
                                <th>Make / Model</th>
                                <th>Passenger Capacity</th>
                                <th>Designated Driver</th>
                                <th>Assigned Route</th>
                                <th>Status</th>
                                <th style="width: 140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($vehicles)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        No vehicles found matching query.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vehicles as $v): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold font-monospace text-primary fs-6"><?= e($v['vehicle_no']) ?></div>
                                            <small class="text-muted">ID: #<?= (int) $v['id'] ?></small>
                                        </td>
                                        <td><?= e($v['model'] ?: 'Standard Bus') ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1">
                                                <?= (int) $v['capacity'] ?> Seats
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= e($v['driver_name'] ?: 'Not Assigned') ?></div>
                                            <?php if ($v['driver_phone']): ?>
                                                <a href="tel:<?= e($v['driver_phone']) ?>" class="small text-decoration-none text-muted">
                                                    📞 <?= e($v['driver_phone']) ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($v['assigned_routes'] > 0): ?>
                                                <span class="badge bg-info text-dark">
                                                    <?= e($v['primary_route'] ?? 'Route Assigned') ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($v['status'] === 'Active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php elseif ($v['status'] === 'Maintenance'): ?>
                                                <span class="badge bg-warning text-dark">Maintenance</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditVehicle<?= (int) $v['id'] ?>">
                                                    Edit
                                                </button>
                                                <button type="button" class="btn btn-outline-danger"
                                                        data-bs-toggle="modal" data-bs-target="#modalDeleteVehicle<?= (int) $v['id'] ?>">
                                                    ✕
                                                </button>
                                            </div>

                                            <!-- Modal Edit -->
                                            <div class="modal fade" id="modalEditVehicle<?= (int) $v['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="post">
                                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                            <input type="hidden" name="action" value="update_vehicle">
                                                            <input type="hidden" name="vehicle_id" value="<?= (int) $v['id'] ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Vehicle: <?= e($v['vehicle_no']) ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Vehicle Registration Number *</label>
                                                                    <input type="text" name="vehicle_no" class="form-control" required value="<?= e($v['vehicle_no']) ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Vehicle Model / Type</label>
                                                                    <input type="text" name="model" class="form-control" value="<?= e($v['model'] ?? '') ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Seating Capacity</label>
                                                                    <input type="number" name="capacity" class="form-control" min="5" max="100" value="<?= (int) $v['capacity'] ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Driver Name</label>
                                                                    <input type="text" name="driver_name" class="form-control" value="<?= e($v['driver_name'] ?? '') ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Driver Contact Number</label>
                                                                    <input type="text" name="driver_phone" class="form-control" value="<?= e($v['driver_phone'] ?? '') ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Operational Status</label>
                                                                    <select name="status" class="form-select">
                                                                        <option value="Active" <?= $v['status'] === 'Active' ? 'selected' : '' ?>>Active (On-Road)</option>
                                                                        <option value="Maintenance" <?= $v['status'] === 'Maintenance' ? 'selected' : '' ?>>In Maintenance</option>
                                                                        <option value="Inactive" <?= $v['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                                                    </select>
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
                                            <div class="modal fade" id="modalDeleteVehicle<?= (int) $v['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="post">
                                                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                            <input type="hidden" name="action" value="delete_vehicle">
                                                            <input type="hidden" name="vehicle_id" value="<?= (int) $v['id'] ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title text-danger">Remove Vehicle</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                Are you sure you want to remove vehicle <strong><?= e($v['vehicle_no']) ?></strong> from the fleet?
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

<!-- Modal Add Vehicle -->
<div class="modal fade" id="modalAddVehicle" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_vehicle">
                <div class="modal-header">
                    <h5 class="modal-title">Register New Vehicle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Vehicle Registration Number *</label>
                        <input type="text" name="vehicle_no" class="form-control" required placeholder="e.g. OD-02-AK-4589">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Vehicle Model / Type</label>
                        <input type="text" name="model" class="form-control" placeholder="e.g. Tata Starbus 40-Seater">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Seating Capacity</label>
                        <input type="number" name="capacity" class="form-control" min="5" max="100" value="32">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Driver Name</label>
                        <input type="text" name="driver_name" class="form-control" placeholder="e.g. Ramesh Chandra Das">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Driver Phone</label>
                        <input type="text" name="driver_phone" class="form-control" placeholder="e.g. 9876543210">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Initial Status</label>
                        <select name="status" class="form-select">
                            <option value="Active">Active (On-Road)</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Vehicle to Fleet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
