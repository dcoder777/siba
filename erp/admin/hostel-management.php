<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$activePage = 'hostel-management.php';

// Self-healing table checks
$pdo->exec("
    CREATE TABLE IF NOT EXISTS hostels (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        type ENUM('boys', 'girls', 'staff') NOT NULL DEFAULT 'boys',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS hostel_rooms (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        hostel_id BIGINT NOT NULL,
        room_no VARCHAR(30) NOT NULL,
        total_beds INT NOT NULL DEFAULT 4,
        occupied_beds INT NOT NULL DEFAULT 0,
        status ENUM('available', 'full', 'maintenance') NOT NULL DEFAULT 'available',
        block_id INT NULL,
        monthly_fee DECIMAL(10,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS hostel_allocations (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        student_id BIGINT NOT NULL,
        room_id BIGINT NOT NULL,
        from_date DATE NOT NULL,
        to_date DATE NULL,
        status ENUM('active', 'vacated') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$msg = '';
$err = '';
$activeTab = trim((string) ($_GET['tab'] ?? 'residents'));

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $err = 'Security validation failed (CSRF).';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'add_hostel') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $type = in_array($_POST['type'] ?? '', ['boys', 'girls', 'staff'], true) ? $_POST['type'] : 'boys';
            if ($name === '') {
                $err = 'Hostel name is required.';
            } else {
                $pdo->prepare("INSERT INTO hostels (name, type) VALUES (?, ?)")->execute([$name, $type]);
                $msg = "Hostel block '{$name}' created.";
                $activeTab = 'rooms';
            }
        } elseif ($action === 'add_room') {
            $hostelId = (int) ($_POST['hostel_id'] ?? 0);
            $roomNo = trim((string) ($_POST['room_no'] ?? ''));
            $totalBeds = (int) ($_POST['total_beds'] ?? 4);
            $fee = (float) ($_POST['monthly_fee'] ?? 0.0);

            if ($hostelId <= 0 || $roomNo === '') {
                $err = 'Hostel and Room Number are required.';
            } else {
                try {
                    $pdo->prepare("INSERT INTO hostel_rooms (hostel_id, room_no, total_beds, occupied_beds, status, monthly_fee) VALUES (?, ?, ?, 0, 'available', ?)")
                        ->execute([$hostelId, $roomNo, $totalBeds, $fee]);
                    $msg = "Room {$roomNo} registered successfully.";
                    $activeTab = 'rooms';
                } catch (\PDOException $e) {
                    $err = 'Error adding room: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'allocate_bed') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $roomId = (int) ($_POST['room_id'] ?? 0);
            $fromDate = trim((string) ($_POST['from_date'] ?? date('Y-m-d')));

            if ($studentId <= 0 || $roomId <= 0) {
                $err = 'Please select a valid student and hostel room.';
            } else {
                // Check if student already has active bed
                $hasActive = (int) $pdo->query("SELECT COUNT(*) FROM hostel_allocations WHERE student_id = $studentId AND status = 'active'")->fetchColumn();
                if ($hasActive > 0) {
                    $err = 'Student already has an active hostel bed assigned. Vacate that record first.';
                } else {
                    // Check room capacity
                    $rm = $pdo->query("SELECT total_beds, occupied_beds FROM hostel_rooms WHERE id = $roomId")->fetch(PDO::FETCH_ASSOC);
                    if ($rm && $rm['occupied_beds'] >= $rm['total_beds']) {
                        $err = 'Selected room is already fully occupied.';
                    } else {
                        // Allocate
                        $pdo->prepare("INSERT INTO hostel_allocations (student_id, room_id, from_date, status) VALUES (?, ?, ?, 'active')")
                            ->execute([$studentId, $roomId, $fromDate]);
                        // Increment occupied beds
                        $pdo->exec("UPDATE hostel_rooms SET occupied_beds = occupied_beds + 1 WHERE id = $roomId");
                        // Update status to full if max reached
                        $pdo->exec("UPDATE hostel_rooms SET status = 'full' WHERE id = $roomId AND occupied_beds >= total_beds");
                        $msg = 'Student successfully assigned to hostel room.';
                        $activeTab = 'residents';
                    }
                }
            }
        } elseif ($action === 'vacate_bed') {
            $allocId = (int) ($_POST['alloc_id'] ?? 0);
            if ($allocId > 0) {
                $al = $pdo->query("SELECT room_id, status FROM hostel_allocations WHERE id = $allocId")->fetch(PDO::FETCH_ASSOC);
                if ($al && $al['status'] === 'active') {
                    $rId = (int) $al['room_id'];
                    $pdo->prepare("UPDATE hostel_allocations SET status = 'vacated', to_date = CURDATE() WHERE id = ?")->execute([$allocId]);
                    // Decrement occupied beds
                    $pdo->exec("UPDATE hostel_rooms SET occupied_beds = GREATEST(0, occupied_beds - 1) WHERE id = $rId");
                    $pdo->exec("UPDATE hostel_rooms SET status = 'available' WHERE id = $rId AND occupied_beds < total_beds AND status != 'maintenance'");
                    $msg = 'Bed marked as vacated.';
                    $activeTab = 'residents';
                }
            }
        }
    }
}

// Hostels
$hostels = $pdo->query("SELECT * FROM hostels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Rooms
$rooms = $pdo->query("
    SELECT hr.*, h.name as hostel_name, h.type as hostel_type
    FROM hostel_rooms hr
    JOIN hostels h ON h.id = hr.hostel_id
    ORDER BY h.name ASC, hr.room_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Allocations
$residents = $pdo->query("
    SELECT ha.*,
           s.admission_no, s.first_name, s.last_name, s.gender,
           se.class_name, se.section_name,
           hr.room_no, hr.monthly_fee,
           h.name as hostel_name, h.type as hostel_type
    FROM hostel_allocations ha
    JOIN students s ON s.id = ha.student_id
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1
    JOIN hostel_rooms hr ON hr.id = ha.room_id
    JOIN hostels h ON h.id = hr.hostel_id
    ORDER BY ha.status ASC, ha.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Available students for allocation
$availableStudents = $pdo->query("
    SELECT s.id, s.admission_no, s.first_name, s.last_name, se.class_name, se.section_name
    FROM students s
    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.is_current = 1
    WHERE s.status = 'active'
    ORDER BY s.first_name ASC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

// Available rooms for allocation
$availableRooms = $pdo->query("
    SELECT hr.id, hr.room_no, hr.total_beds, hr.occupied_beds, hr.monthly_fee, h.name as hostel_name
    FROM hostel_rooms hr
    JOIN hostels h ON h.id = hr.hostel_id
    WHERE hr.status = 'available' AND hr.occupied_beds < hr.total_beds
    ORDER BY h.name ASC, hr.room_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$totalHostels = count($hostels);
$totalRooms = count($rooms);
$totalBeds = (int) array_sum(array_column($rooms, 'total_beds'));
$occupiedBeds = (int) array_sum(array_column($rooms, 'occupied_beds'));
$vacantBeds = max(0, $totalBeds - $occupiedBeds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Hostel & Bed Management | Operations & Fleet</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <header class="erp-header">
            <div>
                <nav class="breadcrumb-nav">
                    <span>Operations & Fleet</span> &gt;
                    <strong>Hostel & Bed Management</strong>
                </nav>
                <h1 class="page-title">Hostel Blocks, Rooms &amp; Bed Allocations</h1>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalAddRoom">+ Add Room</button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAllocateBed">+ Assign Student to Bed</button>
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
                        <span class="text-muted small text-uppercase fw-bold">Hostel Blocks</span>
                        <div class="fs-3 fw-bold text-dark"><?= $totalHostels ?> Blocks</div>
                        <span class="small text-muted"><?= $totalRooms ?> Total Rooms</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #6366f1 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Total Capacity</span>
                        <div class="fs-3 fw-bold text-primary"><?= $totalBeds ?> Beds</div>
                        <span class="small text-muted">Across all dormitory blocks</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #10b981 !important;">
                        <span class="text-muted small text-uppercase fw-bold">Occupied Beds</span>
                        <div class="fs-3 fw-bold text-success"><?= $occupiedBeds ?> Beds</div>
                        <span class="small text-muted">Active student boarders</span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card p-3 border-0 shadow-sm" style="border-left: 4px solid #f59e0b !important;">
                        <span class="text-muted small text-uppercase fw-bold">Vacant Beds</span>
                        <div class="fs-3 fw-bold text-warning"><?= $vacantBeds ?> Beds Available</div>
                        <span class="small text-muted">Ready for new allocations</span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'residents' ? 'active fw-bold' : '' ?>" href="?tab=residents">
                        🛌 Student Residents (<?= count(array_filter($residents, fn($r) => $r['status'] === 'active')) ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'rooms' ? 'active fw-bold' : '' ?>" href="?tab=rooms">
                        🏢 Hostel Rooms &amp; Bed Capacity (<?= count($rooms) ?>)
                    </a>
                </li>
            </ul>

            <?php if ($activeTab === 'residents'): ?>
                <!-- Residents Tab -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Boarding Residents Roster</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalAllocateBed">+ Assign New Bed</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Resident Student</th>
                                    <th>Class & Section</th>
                                    <th>Hostel Block</th>
                                    <th>Room No</th>
                                    <th>Check-in Date</th>
                                    <th>Monthly Fee</th>
                                    <th>Status</th>
                                    <th style="width: 120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($residents)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No student residents found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($residents as $res): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?= e($res['first_name'] . ' ' . $res['last_name']) ?></div>
                                                <span class="badge bg-light text-dark border font-monospace"><?= e($res['admission_no']) ?></span>
                                            </td>
                                            <td><?= e($res['class_name'] ?: 'Class 1') ?> <?= $res['section_name'] ? ('- ' . e($res['section_name'])) : '' ?></td>
                                            <td>
                                                <span class="badge <?= $res['hostel_type'] === 'boys' ? 'bg-primary' : ($res['hostel_type'] === 'girls' ? 'bg-danger' : 'bg-secondary') ?>">
                                                    <?= e($res['hostel_name']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="fw-bold text-dark font-monospace">Room <?= e($res['room_no']) ?></span>
                                            </td>
                                            <td><?= e($res['from_date']) ?></td>
                                            <td>
                                                <div class="fw-bold text-success">₹<?= number_format((float) $res['monthly_fee'], 2) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($res['status'] === 'active'): ?>
                                                    <span class="badge bg-success">Active Resident</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Vacated (<?= e($res['to_date'] ?? '') ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($res['status'] === 'active'): ?>
                                                    <form method="post" onsubmit="return confirm('Vacate bed for <?= e($res['first_name']) ?>? Room occupancy will be released.');">
                                                        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="action" value="vacate_bed">
                                                        <input type="hidden" name="alloc_id" value="<?= (int) $res['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Vacate Bed</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="small text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <!-- Rooms Tab -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">Hostel Room Roster</h5>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddRoom">+ Add Room</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Hostel Block</th>
                                    <th>Room Number</th>
                                    <th>Bed Capacity</th>
                                    <th>Occupancy Status</th>
                                    <th>Vacant Beds</th>
                                    <th>Monthly Fee</th>
                                    <th>Room Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rooms)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No hostel rooms registered yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rooms as $rm): 
                                        $tBeds = (int) $rm['total_beds'];
                                        $oBeds = (int) $rm['occupied_beds'];
                                        $vBeds = max(0, $tBeds - $oBeds);
                                        $rmPct = $tBeds > 0 ? min(100, round(($oBeds / $tBeds) * 100)) : 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($rm['hostel_name']) ?></strong>
                                                <small class="text-muted d-block text-capitalize"><?= e($rm['hostel_type']) ?> Dormitory</small>
                                            </td>
                                            <td>
                                                <div class="fw-bold font-monospace fs-6 text-primary"><?= e($rm['room_no']) ?></div>
                                            </td>
                                            <td><?= $tBeds ?> Beds</td>
                                            <td style="width: 200px;">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="fw-bold"><?= $oBeds ?> / <?= $tBeds ?></span>
                                                    <div class="progress flex-grow-1" style="height: 6px;">
                                                        <div class="progress-bar <?= $rmPct >= 100 ? 'bg-danger' : ($rmPct > 50 ? 'bg-warning' : 'bg-success') ?>"
                                                             style="width: <?= $rmPct ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($vBeds > 0): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                        <?= $vBeds ?> Available
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Full</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-success">₹<?= number_format((float) $rm['monthly_fee'], 2) ?></div>
                                                <small class="text-muted">/ month</small>
                                            </td>
                                            <td>
                                                <?php if ($rm['status'] === 'available'): ?>
                                                    <span class="badge bg-success">Available</span>
                                                <?php elseif ($rm['status'] === 'full'): ?>
                                                    <span class="badge bg-warning text-dark">Full</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Maintenance</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal Add Room -->
<div class="modal fade" id="modalAddRoom" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_room">
                <div class="modal-header">
                    <h5 class="modal-title">Register Hostel Room</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Hostel Block *</label>
                        <select name="hostel_id" class="form-select" required>
                            <?php foreach ($hostels as $h): ?>
                                <option value="<?= (int) $h['id'] ?>">
                                    <?= e($h['name']) ?> (<?= ucfirst(e($h['type'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Room Number *</label>
                        <input type="text" name="room_no" class="form-control" required placeholder="e.g. 101, A-12">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Total Bed Capacity</label>
                        <input type="number" name="total_beds" class="form-control" min="1" max="20" value="4">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Monthly Fee (₹)</label>
                        <input type="number" step="0.01" name="monthly_fee" class="form-control" value="2500.00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Room</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Allocate Bed -->
<div class="modal fade" id="modalAllocateBed" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="allocate_bed">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Student to Hostel Bed</h5>
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
                        <label class="form-label fw-bold">Select Available Room & Bed *</label>
                        <select name="room_id" class="form-select" required>
                            <option value="">-- Choose Room with Vacant Beds --</option>
                            <?php foreach ($availableRooms as $avr): 
                                $free = (int) $avr['total_beds'] - (int) $avr['occupied_beds'];
                            ?>
                                <option value="<?= (int) $avr['id'] ?>">
                                    <?= e($avr['hostel_name']) ?> – Room <?= e($avr['room_no']) ?> (<?= $free ?> beds available) – ₹<?= number_format((float) $avr['monthly_fee'], 2) ?>/mo
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Check-in Date *</label>
                        <input type="date" name="from_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Allocate Bed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
