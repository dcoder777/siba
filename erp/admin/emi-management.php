<?php
require __DIR__ . '/bootstrap.php';
require_admin_login();

$pdo = $pdo ?? null;

// Flash messages
if (session_status() === PHP_SESSION_NONE) session_start();
function set_flash(string $type, string $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function get_flash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// Helper: get admitted students for dropdown
function student_search_options(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, student_name, admission_no, class_sought FROM applications WHERE status = 'Admitted' ORDER BY student_name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Helper: generate EMI schedule + installment rows
function generate_emi_schedule(PDO $pdo, array $data): int {
    $remaining = $data['total_fee'] - $data['down_payment'];
    $installment_amount = round($remaining / $data['num_installments'], 2);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO emi_schedules (student_id, student_name, fee_structure_id, total_fee, down_payment, remaining_amount, installment_type, num_installments, installment_amount, first_emi_date, processing_charge, late_fee_type, late_fee_value, late_fee_grace_days, status, academic_session, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW(), NOW())");
        $stmt->execute([
            $data['student_id'],
            $data['student_name'],
            $data['fee_structure_id'] ?? null,
            $data['total_fee'],
            $data['down_payment'],
            $remaining,
            $data['installment_type'],
            $data['num_installments'],
            $installment_amount,
            $data['first_emi_date'],
            $data['processing_charge'] ?? 0,
            $data['late_fee_type'] ?? 'None',
            $data['late_fee_value'] ?? 0,
            $data['late_fee_grace_days'] ?? 0,
            $data['academic_session'] ?? date('Y')
        ]);
        $schedule_id = (int)$pdo->lastInsertId();

        $date = new DateTime($data['first_emi_date']);
        $interval_map = ['Monthly' => 'P1M', 'Quarterly' => 'P3M'];
        $interval_str = $interval_map[$data['installment_type']] ?? 'P1M';

        $ins = $pdo->prepare("INSERT INTO emi_payments (emi_schedule_id, installment_no, due_date, amount, late_fee, paid_amount, paid_date, payment_mode, transaction_id, status, created_at) VALUES (?, ?, ?, ?, 0, 0, NULL, NULL, NULL, 'Pending', NOW())");

        for ($i = 0; $i < $data['num_installments']; $i++) {
            $due = clone $date;
            if ($i > 0) {
                $due->modify('+' . $interval_str);
            }
            $ins->execute([
                $schedule_id,
                $i + 1,
                $due->format('Y-m-d'),
                $installment_amount
            ]);
        }

        $pdo->commit();
        return $schedule_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid CSRF token.');
        header('Location: emi-management.php');
        exit;
    }

    if ($action === 'create_emi') {
        try {
            $student_id = (int)$_POST['student_id'];
            $student_name = trim($_POST['student_name'] ?? '');
            $total_fee = (float)$_POST['total_fee'];
            $down_payment = (float)$_POST['down_payment'];
            $installment_type = $_POST['installment_type'];
            $num_installments = (int)$_POST['num_installments'];
            $first_emi_date = $_POST['first_emi_date'];
            $processing_charge = (float)($_POST['processing_charge'] ?? 0);
            $late_fee_type = $_POST['late_fee_type'] ?? 'None';
            $late_fee_value = (float)($_POST['late_fee_value'] ?? 0);
            $late_fee_grace_days = (int)($_POST['late_fee_grace_days'] ?? 0);
            $academic_session = $_POST['academic_session'] ?? date('Y');
            $fee_structure_id = $_POST['fee_structure_id'] ?? null;

            if ($total_fee <= 0) throw new Exception('Total fee must be greater than zero.');
            if ($down_payment < 0) throw new Exception('Down payment cannot be negative.');
            if ($num_installments <= 0) throw new Exception('Number of installments must be at least 1.');
            if (empty($student_name)) throw new Exception('Student name is required.');

            $sid = generate_emi_schedule($pdo, [
                'student_id' => $student_id,
                'student_name' => $student_name,
                'fee_structure_id' => $fee_structure_id,
                'total_fee' => $total_fee,
                'down_payment' => $down_payment,
                'installment_type' => $installment_type,
                'num_installments' => $num_installments,
                'first_emi_date' => $first_emi_date,
                'processing_charge' => $processing_charge,
                'late_fee_type' => $late_fee_type,
                'late_fee_value' => $late_fee_value,
                'late_fee_grace_days' => $late_fee_grace_days,
                'academic_session' => $academic_session,
            ]);

            set_flash('success', "EMI schedule #$sid created successfully with $num_installments installments.");
        } catch (Exception $e) {
            set_flash('error', 'Error creating EMI schedule: ' . $e->getMessage());
        }
        header('Location: emi-management.php');
        exit;
    }

    if ($action === 'pay_installment') {
        try {
            $payment_id = (int)$_POST['payment_id'];
            $paid_amount = (float)$_POST['paid_amount'];
            $payment_mode = trim($_POST['payment_mode']);
            $transaction_id = trim($_POST['transaction_id'] ?? '');
            $paid_date = $_POST['paid_date'];

            if ($paid_amount <= 0) throw new Exception('Paid amount must be greater than zero.');
            if (empty($payment_mode)) throw new Exception('Payment mode is required.');

            $stmt = $pdo->prepare("UPDATE emi_payments SET paid_amount = ?, paid_date = ?, payment_mode = ?, transaction_id = ?, status = 'Paid' WHERE id = ?");
            $stmt->execute([$paid_amount, $paid_date, $payment_mode, $transaction_id, $payment_id]);

            // Check if all installments for this schedule are paid
            $row = $pdo->query("SELECT emi_schedule_id FROM emi_payments WHERE id = $payment_id")->fetch();
            if ($row) {
                $pending = $pdo->prepare("SELECT COUNT(*) FROM emi_payments WHERE emi_schedule_id = ? AND status != 'Paid'");
                $pending->execute([$row['emi_schedule_id']]);
                if ((int)$pending->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE emi_schedules SET status = 'Completed', updated_at = NOW() WHERE id = ?")->execute([$row['emi_schedule_id']]);
                }
            }

            set_flash('success', 'Installment payment recorded successfully.');
        } catch (Exception $e) {
            set_flash('error', 'Error recording payment: ' . $e->getMessage());
        }
        header('Location: emi-management.php' . (isset($_GET['view']) ? '?view=' . (int)$_GET['view'] : ''));
        exit;
    }

    if ($action === 'cancel_emi') {
        try {
            $schedule_id = (int)$_POST['schedule_id'];
            $pdo->prepare("UPDATE emi_schedules SET status = 'Cancelled', updated_at = NOW() WHERE id = ?")->execute([$schedule_id]);
            $pdo->prepare("UPDATE emi_payments SET status = 'Cancelled' WHERE emi_schedule_id = ? AND status = 'Pending'")->execute([$schedule_id]);
            set_flash('success', "EMI schedule #$schedule_id has been cancelled.");
        } catch (Exception $e) {
            set_flash('error', 'Error cancelling EMI schedule: ' . $e->getMessage());
        }
        header('Location: emi-management.php');
        exit;
    }

    if ($action === 'collect_all_pending') {
        try {
            $schedule_id = (int)$_POST['schedule_id'];
            $payment_mode = trim($_POST['payment_mode']);
            $transaction_id = trim($_POST['transaction_id'] ?? '');
            $paid_date = $_POST['paid_date'];

            if (empty($payment_mode)) throw new Exception('Payment mode is required.');

            $stmt = $pdo->prepare("SELECT id, amount FROM emi_payments WHERE emi_schedule_id = ? AND status = 'Pending' ORDER BY installment_no ASC");
            $stmt->execute([$schedule_id]);
            $pendings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pendings)) throw new Exception('No pending installments found.');

            $total = 0;
            $ins = $pdo->prepare("UPDATE emi_payments SET paid_amount = ?, paid_date = ?, payment_mode = ?, transaction_id = ?, status = 'Paid' WHERE id = ?");
            foreach ($pendings as $p) {
                $ins->execute([$p['amount'], $paid_date, $payment_mode, $transaction_id, $p['id']]);
                $total += $p['amount'];
            }

            $pdo->prepare("UPDATE emi_schedules SET status = 'Completed', updated_at = NOW() WHERE id = ?")->execute([$schedule_id]);

            set_flash('success', "All pending installments collected. Total: ₹" . number_format($total, 2));
        } catch (Exception $e) {
            set_flash('error', 'Error collecting payments: ' . $e->getMessage());
        }
        header('Location: emi-management.php?view=' . (int)$_POST['schedule_id']);
        exit;
    }
}

// Fetch data for display
$students = student_search_options($pdo);
$flash = get_flash();
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$view_schedule = null;
$view_payments = [];

if ($view_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM emi_schedules WHERE id = ?");
    $stmt->execute([$view_id]);
    $view_schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($view_schedule) {
        $stmt2 = $pdo->prepare("SELECT * FROM emi_payments WHERE emi_schedule_id = ? ORDER BY installment_no ASC");
        $stmt2->execute([$view_id]);
        $view_payments = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Active schedules
$active_schedules = $pdo->query("SELECT es.*, a.admission_no FROM emi_schedules es LEFT JOIN applications a ON es.student_id = a.id WHERE es.status = 'Active' ORDER BY es.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$completed_schedules = $pdo->query("SELECT es.*, a.admission_no FROM emi_schedules es LEFT JOIN applications a ON es.student_id = a.id WHERE es.status = 'Completed' ORDER BY es.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$cancelled_schedules = $pdo->query("SELECT es.*, a.admission_no FROM emi_schedules es LEFT JOIN applications a ON es.student_id = a.id WHERE es.status = 'Cancelled' ORDER BY es.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_active = count($active_schedules);
$total_completed = count($completed_schedules);
$total_pending_amount = 0;
foreach ($active_schedules as $s) {
    $total_pending_amount += (float)$s['remaining_amount'];
}

// Fee structures for reference
$fee_structures = $pdo->query("SELECT id, name, class_name, total_amount FROM fee_structures ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMI & Installment Management — ERP Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; color: #1a1a2e; line-height: 1.6; }
        .hero { background: linear-gradient(135deg, #0f3460 0%, #16213e 100%); color: #fff; padding: 2.5rem 2rem; text-align: center; }
        .hero h1 { font-size: 1.8rem; font-weight: 700; letter-spacing: 0.5px; }
        .hero p { margin-top: 0.4rem; opacity: 0.85; font-size: 0.95rem; }
        .container { max-width: 1200px; margin: 1.5rem auto; padding: 0 1.5rem; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
        .toolbar h2 { font-size: 1.15rem; color: #16213e; }
        .btn { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.55rem 1.1rem; border: none; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #0f3460; color: #fff; }
        .btn-primary:hover { background: #1a4a8a; }
        .btn-success { background: #27ae60; color: #fff; }
        .btn-success:hover { background: #219a52; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-danger:hover { background: #c0392b; }
        .btn-sm { padding: 0.35rem 0.75rem; font-size: 0.78rem; }
        .btn-outline { background: transparent; border: 1.5px solid #0f3460; color: #0f3460; }
        .btn-outline:hover { background: #0f3460; color: #fff; }
        .btn-warning { background: #f39c12; color: #fff; }
        .btn-warning:hover { background: #e67e22; }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: #fff; border-radius: 10px; padding: 1.2rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-align: center; }
        .stat-card .stat-value { font-size: 1.6rem; font-weight: 700; color: #0f3460; }
        .stat-card .stat-label { font-size: 0.8rem; color: #666; margin-top: 0.3rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card.active .stat-value { color: #27ae60; }
        .stat-card.pending .stat-value { color: #e67e22; }

        .panel { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 1.5rem; overflow: hidden; }
        .panel-header { padding: 1rem 1.5rem; border-bottom: 1px solid #eef; display: flex; justify-content: space-between; align-items: center; }
        .panel-header h3 { font-size: 1rem; color: #16213e; }
        .panel-body { padding: 1.2rem 1.5rem; }

        .section-title { font-size: 1rem; font-weight: 600; color: #16213e; margin-bottom: 0.8rem; padding-bottom: 0.5rem; border-bottom: 2px solid #eef; }

        .app-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .app-table th { background: #f7f9fc; padding: 0.7rem 1rem; text-align: left; font-weight: 600; color: #444; border-bottom: 2px solid #eef; white-space: nowrap; }
        .app-table td { padding: 0.65rem 1rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        .app-table tr:hover td { background: #f8f9fb; }
        .app-table tr.overdue td { background: #fff5f5; }
        .app-table .text-right { text-align: right; }
        .app-table .text-center { text-align: center; }
        .app-table .mono { font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.82rem; }

        .badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 20px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-completed { background: #d1ecf1; color: #0c5460; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-paid { background: #d4edda; color: #155724; }
        .badge-overdue { background: #f8d7da; color: #721c24; }
        .badge-partial { background: #ffeaa7; color: #856404; }

        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .field-grid.single { grid-template-columns: 1fr; }
        .field-group { display: flex; flex-direction: column; }
        .field-group label { font-size: 0.8rem; font-weight: 600; color: #444; margin-bottom: 0.3rem; }
        .field-group input, .field-group select { padding: 0.55rem 0.8rem; border: 1.5px solid #ddd; border-radius: 6px; font-size: 0.85rem; transition: border 0.2s; }
        .field-group input:focus, .field-group select:focus { outline: none; border-color: #0f3460; box-shadow: 0 0 0 3px rgba(15,52,96,0.1); }
        .field-group .hint { font-size: 0.72rem; color: #888; margin-top: 0.2rem; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal { background: #fff; border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        .modal-header { padding: 1.2rem 1.5rem; border-bottom: 1px solid #eef; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 1.05rem; color: #16213e; }
        .modal-close { background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #888; padding: 0.2rem; }
        .modal-close:hover { color: #333; }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #eef; display: flex; justify-content: flex-end; gap: 0.8rem; }

        .flash { padding: 0.8rem 1.2rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 500; }
        .flash-success { background: #d4edda; color: #155724; border-left: 4px solid #27ae60; }
        .flash-error { background: #f8d7da; color: #721c24; border-left: 4px solid #e74c3c; }

        .progress-bar-wrap { background: #e9ecef; border-radius: 20px; height: 22px; overflow: hidden; margin: 0.8rem 0; }
        .progress-bar { height: 100%; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 700; color: #fff; transition: width 0.5s ease; }
        .progress-bar.green { background: linear-gradient(90deg, #27ae60, #2ecc71); }
        .progress-bar.blue { background: linear-gradient(90deg, #0f3460, #3498db); }

        .detail-header { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; }
        .detail-item { text-align: center; }
        .detail-item .val { font-size: 1.2rem; font-weight: 700; color: #0f3460; }
        .detail-item .lbl { font-size: 0.75rem; color: #888; text-transform: uppercase; margin-top: 0.2rem; }

        .actions-cell { display: flex; gap: 0.4rem; flex-wrap: nowrap; }

        .empty-state { text-align: center; padding: 3rem 1rem; color: #999; }
        .empty-state .icon { font-size: 2.5rem; margin-bottom: 0.5rem; }
        .empty-state p { font-size: 0.9rem; }

        @media (max-width: 768px) {
            .field-grid { grid-template-columns: 1fr; }
            .detail-header { grid-template-columns: 1fr 1fr; }
            .app-table { font-size: 0.78rem; }
            .app-table th, .app-table td { padding: 0.5rem 0.6rem; }
        }
    </style>
</head>
<body>

<div class="hero">
    <h1>EMI & Installment Management</h1>
    <p>Manage student fee installment schedules, payments, and tracking</p>
</div>

<div class="container">
    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if (!$view_schedule): ?>
    <!-- DASHBOARD VIEW -->
    <div class="stats-row">
        <div class="stat-card active">
            <div class="stat-value"><?= $total_active ?></div>
            <div class="stat-label">Active Schedules</div>
        </div>
        <div class="stat-card pending">
            <div class="stat-value">₹<?= number_format($total_pending_amount, 2) ?></div>
            <div class="stat-label">Pending Collection</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $total_completed ?></div>
            <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count($cancelled_schedules) ?></div>
            <div class="stat-label">Cancelled</div>
        </div>
    </div>

    <!-- SECTION 1: Active EMI Schedules -->
    <div class="panel">
        <div class="panel-header">
            <h3>Active EMI Schedules</h3>
            <button class="btn btn-primary" onclick="openModal('createModal')">
                <span>+</span> New EMI Schedule
            </button>
        </div>
        <?php if (empty($active_schedules)): ?>
            <div class="empty-state">
                <div class="icon">📋</div>
                <p>No active EMI schedules found. Click "New EMI Schedule" to create one.</p>
            </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Admission No</th>
                        <th class="text-right">Total Fee</th>
                        <th class="text-right">Down Payment</th>
                        <th class="text-right">Remaining</th>
                        <th>Type</th>
                        <th class="text-center">Installments</th>
                        <th class="text-right">Monthly Amount</th>
                        <th class="text-center">Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_schedules as $s): ?>
                    <tr>
                        <td><strong><?= e($s['student_name']) ?></strong></td>
                        <td class="mono"><?= e($s['admission_no'] ?? '—') ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$s['total_fee'], 2) ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$s['down_payment'], 2) ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$s['remaining_amount'], 2) ?></td>
                        <td><?= e($s['installment_type']) ?></td>
                        <td class="text-center"><?= $s['num_installments'] ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$s['installment_amount'], 2) ?></td>
                        <td class="text-center"><span class="badge badge-active">Active</span></td>
                        <td>
                            <div class="actions-cell">
                                <a href="?view=<?= $s['id'] ?>" class="btn btn-sm btn-outline" title="View Details">View</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel this EMI schedule?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="cancel_emi">
                                    <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Cancel">Cancel</button>
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

    <?php if (!empty($completed_schedules)): ?>
    <!-- SECTION: Completed Schedules -->
    <div class="panel">
        <div class="panel-header">
            <h3>Recently Completed</h3>
        </div>
        <div style="overflow-x: auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Admission No</th>
                        <th class="text-right">Total Fee</th>
                        <th class="text-center">Installments</th>
                        <th class="text-center">Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed_schedules as $s): ?>
                    <tr>
                        <td><strong><?= e($s['student_name']) ?></strong></td>
                        <td class="mono"><?= e($s['admission_no'] ?? '—') ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$s['total_fee'], 2) ?></td>
                        <td class="text-center"><?= $s['num_installments'] ?></td>
                        <td class="text-center"><span class="badge badge-completed">Completed</span></td>
                        <td><a href="?view=<?= $s['id'] ?>" class="btn btn-sm btn-outline">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- DETAIL VIEW -->
    <?php if (!$view_schedule): ?>
        <div class="empty-state">
            <div class="icon">❌</div>
            <p>EMI schedule not found.</p>
            <a href="emi-management.php" class="btn btn-primary" style="margin-top:1rem;">Back to List</a>
        </div>
    <?php else: ?>
    <div style="margin-bottom:1rem;">
        <a href="emi-management.php" class="btn btn-outline">← Back to List</a>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h3>EMI Schedule #<?= $view_schedule['id'] ?> — <?= e($view_schedule['student_name']) ?></h3>
            <div>
                <span class="badge badge-<?= strtolower($view_schedule['status']) ?>"><?= e($view_schedule['status']) ?></span>
            </div>
        </div>
        <div class="panel-body">
            <div class="detail-header">
                <div class="detail-item">
                    <div class="val">₹<?= number_format((float)$view_schedule['total_fee'], 2) ?></div>
                    <div class="lbl">Total Fee</div>
                </div>
                <div class="detail-item">
                    <div class="val">₹<?= number_format((float)$view_schedule['down_payment'], 2) ?></div>
                    <div class="lbl">Down Payment</div>
                </div>
                <div class="detail-item">
                    <div class="val">₹<?= number_format((float)$view_schedule['remaining_amount'], 2) ?></div>
                    <div class="lbl">Remaining</div>
                </div>
                <div class="detail-item">
                    <div class="val">₹<?= number_format((float)$view_schedule['installment_amount'], 2) ?></div>
                    <div class="lbl">Installment Amount</div>
                </div>
            </div>

            <?php
            $paid_count = 0;
            foreach ($view_payments as $p) {
                if ($p['status'] === 'Paid') $paid_count++;
            }
            $total_count = count($view_payments);
            $pct = $total_count > 0 ? round(($paid_count / $total_count) * 100) : 0;
            ?>
            <div class="progress-bar-wrap">
                <div class="progress-bar <?= $pct == 100 ? 'blue' : 'green' ?>" style="width: <?= $pct ?>%"><?= $paid_count ?> of <?= $total_count ?> paid (<?= $pct ?>%)</div>
            </div>

            <div style="margin-top:1rem; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:0.8rem; font-size:0.82rem; color:#555;">
                <div><strong>Type:</strong> <?= e($view_schedule['installment_type']) ?></div>
                <div><strong>Installments:</strong> <?= $view_schedule['num_installments'] ?></div>
                <div><strong>First EMI:</strong> <?= e($view_schedule['first_emi_date']) ?></div>
                <div><strong>Processing Charge:</strong> ₹<?= number_format((float)$view_schedule['processing_charge'], 2) ?></div>
                <div><strong>Late Fee:</strong> <?= $view_schedule['late_fee_type'] === 'None' ? 'None' : e($view_schedule['late_fee_type']) . ' — ₹' . number_format((float)$view_schedule['late_fee_value'], 2) ?></div>
                <div><strong>Grace Days:</strong> <?= $view_schedule['late_fee_grace_days'] ?></div>
            </div>
        </div>
    </div>

    <!-- Installment Schedule Table -->
    <div class="panel">
        <div class="panel-header">
            <h3>Installment Schedule</h3>
            <?php
            $has_pending = false;
            foreach ($view_payments as $p) {
                if (in_array($p['status'], ['Pending', 'Overdue'])) {
                    $has_pending = true;
                    break;
                }
            }
            if ($has_pending && $view_schedule['status'] === 'Active'):
            ?>
            <button class="btn btn-success btn-sm" onclick="openModal('collectAllModal')">
                Collect All Pending
            </button>
            <?php endif; ?>
        </div>
        <?php if (empty($view_payments)): ?>
            <div class="empty-state">
                <p>No installments generated yet.</p>
            </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Due Date</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Late Fee</th>
                        <th class="text-right">Paid Amount</th>
                        <th>Paid Date</th>
                        <th>Payment Mode</th>
                        <th class="text-center">Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $today = date('Y-m-d');
                    foreach ($view_payments as $p):
                        $is_overdue = ($p['status'] === 'Pending' && $p['due_date'] < $today);
                        $row_class = ($p['status'] === 'Overdue' || $is_overdue) ? 'overdue' : '';
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td class="text-center mono"><?= $p['installment_no'] ?></td>
                        <td class="mono"><?= e($p['due_date']) ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$p['amount'], 2) ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$p['late_fee'], 2) ?></td>
                        <td class="text-right mono">₹<?= number_format((float)$p['paid_amount'], 2) ?></td>
                        <td class="mono"><?= $p['paid_date'] ? e($p['paid_date']) : '—' ?></td>
                        <td><?= $p['payment_mode'] ? e($p['payment_mode']) : '—' ?></td>
                        <td class="text-center">
                            <span class="badge badge-<?= strtolower($p['status']) ?>"><?= e($p['status']) ?></span>
                        </td>
                        <td>
                            <?php if (in_array($p['status'], ['Pending', 'Overdue', 'Partial']) && $view_schedule['status'] === 'Active'): ?>
                            <button class="btn btn-sm btn-success" onclick="openPayModal(<?= $p['id'] ?>, <?= $p['amount'] ?>, '<?= e($p['due_date']) ?>')">Pay Now</button>
                            <?php else: ?>
                            <span style="color:#aaa; font-size:0.75rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($view_schedule['status'] === 'Active'): ?>
    <div style="margin-top:0.5rem; margin-bottom:1.5rem;">
        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel this EMI schedule? All pending installments will be marked as Cancelled.')">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="cancel_emi">
            <input type="hidden" name="schedule_id" value="<?= $view_schedule['id'] ?>">
            <button type="submit" class="btn btn-danger">Cancel This EMI Schedule</button>
        </form>
    </div>
    <?php endif; ?>

    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Create EMI Schedule Modal -->
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Create New EMI Schedule</h3>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" id="createEmiForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create_emi">
            <div class="modal-body">
                <div class="field-grid single" style="margin-bottom:1rem;">
                    <div class="field-group">
                        <label for="student_search">Search Student *</label>
                        <input type="text" id="student_search" list="studentList" placeholder="Type student name..." required oninput="selectStudent(this)">
                        <input type="hidden" name="student_id" id="student_id">
                        <input type="hidden" name="student_name" id="student_name_field">
                        <datalist id="studentList">
                            <?php foreach ($students as $st): ?>
                            <option value="<?= e($st['student_name']) ?>" data-id="<?= $st['id'] ?>" data-admission="<?= e($st['admission_no']) ?>" data-class="<?= e($st['class_sought']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <span class="hint" id="studentInfo"></span>
                    </div>
                </div>

                <div class="field-grid">
                    <div class="field-group">
                        <label for="fee_structure_id">Fee Structure (Optional)</label>
                        <select name="fee_structure_id" id="fee_structure_id" onchange="autoFillFee(this)">
                            <option value="">— Manual Entry —</option>
                            <?php foreach ($fee_structures as $fs): ?>
                            <option value="<?= $fs['id'] ?>" data-amount="<?= $fs['total_amount'] ?>"><?= e($fs['name']) ?> (<?= e($fs['class_name']) ?>) — ₹<?= number_format((float)$fs['total_amount'], 2) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="total_fee">Total Fee Amount *</label>
                        <input type="number" name="total_fee" id="total_fee" step="0.01" min="0" required>
                    </div>
                </div>

                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group">
                        <label for="down_payment">Down Payment</label>
                        <input type="number" name="down_payment" id="down_payment" step="0.01" min="0" value="0" onchange="calcInstallment()">
                    </div>
                    <div class="field-group">
                        <label for="installment_type">Installment Type *</label>
                        <select name="installment_type" id="installment_type" required onchange="calcInstallment()">
                            <option value="Monthly">Monthly</option>
                            <option value="Quarterly">Quarterly</option>
                            <option value="Custom">Custom</option>
                        </select>
                    </div>
                </div>

                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group">
                        <label for="num_installments">Number of Installments *</label>
                        <input type="number" name="num_installments" id="num_installments" min="1" value="6" required onchange="calcInstallment()" oninput="calcInstallment()">
                    </div>
                    <div class="field-group">
                        <label>Installment Amount (Auto)</label>
                        <input type="text" id="installment_preview" readonly style="background:#f0f2f5; font-weight:700; color:#0f3460;">
                        <span class="hint">Remaining ÷ Number of Installments</span>
                    </div>
                </div>

                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group">
                        <label for="first_emi_date">First EMI Date *</label>
                        <input type="date" name="first_emi_date" id="first_emi_date" required>
                    </div>
                    <div class="field-group">
                        <label for="processing_charge">Processing Charge</label>
                        <input type="number" name="processing_charge" id="processing_charge" step="0.01" min="0" value="0">
                    </div>
                </div>

                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group">
                        <label for="late_fee_type">Late Fee Rule</label>
                        <select name="late_fee_type" id="late_fee_type">
                            <option value="None">None</option>
                            <option value="Fixed">Fixed (₹)</option>
                            <option value="Percentage">Percentage (%)</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="late_fee_value">Late Fee Value</label>
                        <input type="number" name="late_fee_value" id="late_fee_value" step="0.01" min="0" value="0">
                    </div>
                </div>

                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group">
                        <label for="late_fee_grace_days">Grace Days</label>
                        <input type="number" name="late_fee_grace_days" id="late_fee_grace_days" min="0" value="0">
                    </div>
                    <div class="field-group">
                        <label for="academic_session">Academic Session</label>
                        <input type="text" name="academic_session" id="academic_session" value="<?= date('Y') ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create EMI Schedule</button>
            </div>
        </form>
    </div>
</div>

<!-- Pay Installment Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>Pay Installment</h3>
            <button class="modal-close" onclick="closeModal('payModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="pay_installment">
            <input type="hidden" name="payment_id" id="pay_payment_id">
            <div class="modal-body">
                <div class="field-grid single">
                    <div class="field-group">
                        <label>Due Amount</label>
                        <input type="text" id="pay_due_amount" readonly style="background:#f0f2f5; font-weight:700;">
                    </div>
                </div>
                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group">
                        <label for="pay_amount">Paid Amount *</label>
                        <input type="number" name="paid_amount" id="pay_amount" step="0.01" min="0" required>
                    </div>
                    <div class="field-group">
                        <label for="pay_date">Payment Date *</label>
                        <input type="date" name="paid_date" id="pay_date" required>
                    </div>
                </div>
                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group">
                        <label for="pay_mode">Payment Mode *</label>
                        <select name="payment_mode" id="pay_mode" required>
                            <option value="">Select...</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="UPI">UPI</option>
                            <option value="Card">Card</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Online">Online</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="pay_txn">Transaction ID</label>
                        <input type="text" name="transaction_id" id="pay_txn" placeholder="Optional">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('payModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Collect All Pending Modal -->
<?php if ($view_schedule && $view_schedule['status'] === 'Active'): ?>
<div class="modal-overlay" id="collectAllModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>Collect All Pending Installments</h3>
            <button class="modal-close" onclick="closeModal('collectAllModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="collect_all_pending">
            <input type="hidden" name="schedule_id" value="<?= $view_schedule['id'] ?>">
            <div class="modal-body">
                <?php
                $total_pending_collect = 0;
                foreach ($view_payments as $p) {
                    if (in_array($p['status'], ['Pending', 'Overdue'])) {
                        $total_pending_collect += (float)$p['amount'];
                    }
                }
                ?>
                <div class="field-grid single" style="margin-bottom:1rem;">
                    <div class="field-group">
                        <label>Total Pending Amount</label>
                        <input type="text" value="₹<?= number_format($total_pending_collect, 2) ?>" readonly style="background:#f0f2f5; font-weight:700; color:#e67e22;">
                    </div>
                </div>
                <div class="field-grid">
                    <div class="field-group">
                        <label for="collect_mode">Payment Mode *</label>
                        <select name="payment_mode" id="collect_mode" required>
                            <option value="">Select...</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="UPI">UPI</option>
                            <option value="Card">Card</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Online">Online</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="collect_date">Payment Date *</label>
                        <input type="date" name="paid_date" id="collect_date" required>
                    </div>
                </div>
                <div class="field-grid" style="margin-top:1rem;">
                    <div class="field-group single">
                        <label for="collect_txn">Transaction ID</label>
                        <input type="text" name="transaction_id" id="collect_txn" placeholder="Optional">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('collectAllModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Collect All</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.getElementById(id).style.display = 'none';
}

function selectStudent(el) {
    var options = document.querySelectorAll('#studentList option');
    var val = el.value;
    var found = false;
    options.forEach(function(opt) {
        if (opt.value === val) {
            document.getElementById('student_id').value = opt.dataset.id;
            document.getElementById('student_name_field').value = val;
            document.getElementById('studentInfo').textContent = 'Admission: ' + (opt.dataset.admission || '—') + ' | Class: ' + (opt.dataset.class || '—');
            found = true;
        }
    });
    if (!found) {
        document.getElementById('student_id').value = '';
        document.getElementById('studentInfo').textContent = '';
    }
}

function autoFillFee(sel) {
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.amount) {
        document.getElementById('total_fee').value = parseFloat(opt.dataset.amount).toFixed(2);
        calcInstallment();
    }
}

function calcInstallment() {
    var total = parseFloat(document.getElementById('total_fee').value) || 0;
    var down = parseFloat(document.getElementById('down_payment').value) || 0;
    var num = parseInt(document.getElementById('num_installments').value) || 1;
    var remaining = total - down;
    if (remaining < 0) remaining = 0;
    var amt = remaining / num;
    document.getElementById('installment_preview').value = '₹' + amt.toFixed(2);
}

function openPayModal(id, amount, dueDate) {
    document.getElementById('pay_payment_id').value = id;
    document.getElementById('pay_due_amount').value = '₹' + parseFloat(amount).toFixed(2);
    document.getElementById('pay_amount').value = parseFloat(amount).toFixed(2);
    document.getElementById('pay_amount').max = parseFloat(amount).toFixed(2);
    var today = new Date().toISOString().split('T')[0];
    document.getElementById('pay_date').value = today;
    openModal('payModal');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(ov) {
    ov.addEventListener('click', function(e) {
        if (e.target === ov) {
            ov.classList.remove('active');
            ov.style.display = 'none';
        }
    });
});

// Initial calc
calcInstallment();

// Set default date
var today = new Date().toISOString().split('T')[0];
var firstDate = document.getElementById('first_emi_date');
if (firstDate && !firstDate.value) firstDate.value = today;
</script>

</body>
</html>