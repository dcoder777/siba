<?php
require __DIR__ . '/bootstrap.php';
require_admin_login();

if (!$pdo) {
    die('Database connection not available');
}

ensure_columns($pdo, 'discounts', [
    'student_id' => "INT UNSIGNED NOT NULL DEFAULT 0",
    'admission_no' => "VARCHAR(50) DEFAULT '' AFTER student_name",
]);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_discount') {
        $student_name = trim($_POST['student_name'] ?? '');
        $admission_no = trim($_POST['admission_no'] ?? '');
        $discount_type = $_POST['discount_type'] ?? '';
        $discount_method = $_POST['discount_method'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $fee_head_id = $_POST['fee_head_id'] !== '' ? intval($_POST['fee_head_id']) : null;
        $fee_head_name = '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $approved_by = trim($_POST['approved_by'] ?? '');

        if ($fee_head_id) {
            $stmt = $pdo->prepare("SELECT name FROM fee_heads WHERE id = ?");
            $stmt->execute([$fee_head_id]);
            $fee_head_name = $stmt->fetchColumn() ?? '';
        }

        if ($student_name && $admission_no && $discount_type && $discount_method && $amount > 0 && $start_date) {
            $stmt = $pdo->prepare("INSERT INTO discounts (student_name, admission_no, discount_type, discount_method, amount, applicable_fee_head_id, applicable_fee_head_name, start_date, end_date, reason, approved_by, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())");
            $stmt->execute([$student_name, $admission_no, $discount_type, $discount_method, $amount, $fee_head_id, $fee_head_name, $start_date, $end_date ?: null, $reason, $approved_by]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Discount created successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please fill all required fields.'];
        }

        header('Location: discounts.php');
        exit;
    }

    if ($action === 'update_discount') {
        $id = intval($_POST['id'] ?? 0);
        $student_name = trim($_POST['student_name'] ?? '');
        $admission_no = trim($_POST['admission_no'] ?? '');
        $discount_type = $_POST['discount_type'] ?? '';
        $discount_method = $_POST['discount_method'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $fee_head_id = $_POST['fee_head_id'] !== '' ? intval($_POST['fee_head_id']) : null;
        $fee_head_name = '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $approved_by = trim($_POST['approved_by'] ?? '');

        if ($fee_head_id) {
            $stmt = $pdo->prepare("SELECT name FROM fee_heads WHERE id = ?");
            $stmt->execute([$fee_head_id]);
            $fee_head_name = $stmt->fetchColumn() ?? '';
        }

        if ($id && $student_name && $admission_no && $discount_type && $discount_method && $amount > 0 && $start_date) {
            $stmt = $pdo->prepare("UPDATE discounts SET student_name = ?, admission_no = ?, discount_type = ?, discount_method = ?, amount = ?, applicable_fee_head_id = ?, applicable_fee_head_name = ?, start_date = ?, end_date = ?, reason = ?, approved_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$student_name, $admission_no, $discount_type, $discount_method, $amount, $fee_head_id, $fee_head_name, $start_date, $end_date ?: null, $reason, $approved_by, $id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Discount updated successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please fill all required fields.'];
        }

        header('Location: discounts.php');
        exit;
    }

    if ($action === 'delete_discount') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE discounts SET status = 'Cancelled', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Discount cancelled.'];
        }
        header('Location: discounts.php');
        exit;
    }
}

// Fetch fee heads
$fee_heads_stmt = $pdo->query("SELECT id, name FROM fee_heads ORDER BY name");
$fee_heads = $fee_heads_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch admitted students for datalist
$students_stmt = $pdo->query("SELECT admission_no, CONCAT(COALESCE(first_name,''), ' ', COALESCE(middle_name,''), ' ', COALESCE(last_name,'')) AS full_name, class_sought FROM applications WHERE status = 'Admitted' ORDER BY full_name");
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

$query = "SELECT * FROM discounts WHERE 1=1";
$params = [];

if ($status_filter) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}
if ($type_filter) {
    $query .= " AND discount_type = ?";
    $params[] = $type_filter;
}
if ($search) {
    $query .= " AND (student_name LIKE ? OR admission_no LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

function format_date_range($start_date, $end_date) {
    $start = date('d-M-Y', strtotime($start_date));
    if ($end_date) {
        $end = date('d-M-Y', strtotime($end_date));
        return "$start to $end";
    }
    return $start;
}

function get_type_badge_class($type) {
    $map = [
        'Sibling' => 'badge-blue',
        'Staff' => 'badge-green',
        'Scholarship' => 'badge-purple',
        'Sports' => 'badge-orange',
        'Other' => 'badge-gray',
    ];
    return $map[$type] ?? 'badge-gray';
}

function get_status_badge_class($status) {
    $map = [
        'Active' => 'badge-green',
        'Expired' => 'badge-gray',
        'Cancelled' => 'badge-red',
    ];
    return $map[$status] ?? 'badge-gray';
}

function format_amount($discount_method, $amount) {
    if ($discount_method === 'Percentage') {
        return $amount . '%';
    }
    return number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discounts & Concessions - ERP Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .badge { padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; display: inline-block; }
        .badge-blue { background: #e3f2fd; color: #1565c0; }
        .badge-green { background: #e8f5e9; color: #2e7d32; }
        .badge-purple { background: #f3e5f5; color: #7b1fa2; }
        .badge-orange { background: #fff3e0; color: #e65100; }
        .badge-gray { background: #f5f5f5; color: #616161; }
        .badge-red { background: #ffebee; color: #c62828; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: #fff; border-radius: 8px; width: 90%; max-width: 650px; max-height: 90vh; overflow-y: auto; padding: 24px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { margin: 0; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; padding: 4px 8px; }
        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .field-grid .full-width { grid-column: 1 / -1; }
        .field-grid label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px; color: #333; }
        .field-grid input, .field-grid select, .field-grid textarea { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .field-grid textarea { resize: vertical; min-height: 60px; }
        .radio-group { display: flex; gap: 16px; padding-top: 8px; }
        .radio-group label { font-weight: normal; display: flex; align-items: center; gap: 6px; }
        .filter-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-bar select, .filter-bar input { padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .filter-bar .btn { margin-left: auto; }
        .app-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .app-table th, .app-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
        .app-table th { background: #f5f5f5; font-weight: 600; font-size: 13px; color: #555; }
        .app-table tr:hover { background: #fafafa; }
        .app-table .actions { white-space: nowrap; }
        .app-table .actions button, .app-table .actions a { margin-right: 6px; }
        .btn-edit { background: #1976d2; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .btn-edit:hover { background: #1565c0; }
        .btn-delete { background: #d32f2f; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .btn-delete:hover { background: #b71c1c; }
    </style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main-content">
    <div class="hero-banner">
        <h1>Discounts & Concessions</h1>
        <p>Manage student fee discounts and concessions</p>
    </div>

    <?php if (!empty($_SESSION['flash'])): ?>
        <div class="flash flash-<?= e($_SESSION['flash']['type']) ?>">
            <?= e($_SESSION['flash']['message']) ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="panel">
        <div class="filter-bar">
            <select id="filterStatus" onchange="applyFilters()">
                <option value="">All Status</option>
                <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                <option value="Expired" <?= $status_filter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                <option value="Cancelled" <?= $status_filter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
            <select id="filterType" onchange="applyFilters()">
                <option value="">All Types</option>
                <option value="Sibling" <?= $type_filter === 'Sibling' ? 'selected' : '' ?>>Sibling</option>
                <option value="Staff" <?= $type_filter === 'Staff' ? 'selected' : '' ?>>Staff</option>
                <option value="Scholarship" <?= $type_filter === 'Scholarship' ? 'selected' : '' ?>>Scholarship</option>
                <option value="Sports" <?= $type_filter === 'Sports' ? 'selected' : '' ?>>Sports</option>
                <option value="Other" <?= $type_filter === 'Other' ? 'selected' : '' ?>>Other</option>
            </select>
            <input type="text" id="searchInput" placeholder="Search by student name or admission no..." value="<?= e($search) ?>">
            <button class="btn" onclick="applyFilters()">Search</button>
            <button class="btn btn-primary" onclick="openModal()">Add Discount</button>
        </div>

        <table class="app-table">
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Admission No</th>
                    <th>Discount Type</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Applicable Fee</th>
                    <th>Date Range</th>
                    <th>Status</th>
                    <th>Approved By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($discounts)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center; padding:30px; color:#888;">No discounts found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($discounts as $d): ?>
                        <tr>
                            <td><?= e($d['student_name']) ?></td>
                            <td><?= e($d['admission_no']) ?></td>
                            <td><span class="badge <?= get_type_badge_class($d['discount_type']) ?>"><?= e($d['discount_type']) ?></span></td>
                            <td><?= e($d['discount_method']) ?></td>
                            <td>
                                <?php if ($d['discount_method'] === 'Percentage'): ?>
                                    <?= e($d['amount']) ?>%
                                <?php else: ?>
                                    <?= number_format($d['amount'], 2) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($d['applicable_fee_head_name'] ?: 'All Fees') ?></td>
                            <td><?= format_date_range($d['start_date'], $d['end_date']) ?></td>
                            <td><span class="badge <?= get_status_badge_class($d['status']) ?>"><?= e($d['status']) ?></span></td>
                            <td><?= e($d['approved_by']) ?></td>
                            <td class="actions">
                                <?php if ($d['status'] === 'Active'): ?>
                                    <button class="btn-edit" onclick='editDiscount(<?= json_encode($d) ?>)'>Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this discount?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="delete_discount">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-delete">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#999; font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="discountModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add Discount</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" id="discountForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" id="formAction" value="create_discount">
            <input type="hidden" name="id" id="formId" value="">
            <div class="field-grid">
                <div>
                    <label for="student_search">Student Name *</label>
                    <input type="text" id="student_search" list="studentList" required autocomplete="off">
                    <datalist id="studentList">
                        <?php foreach ($students as $s): ?>
                            <option value="<?= e($s['full_name']) ?>" data-admission="<?= e($s['admission_no']) ?>" data-class="<?= e($s['class_sought']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="student_name" id="student_name">
                </div>
                <div>
                    <label for="admission_no">Admission No *</label>
                    <input type="text" name="admission_no" id="admission_no" required readonly>
                </div>
                <div>
                    <label for="discount_type">Discount Type *</label>
                    <select name="discount_type" id="discount_type" required>
                        <option value="">Select Type</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Staff">Staff</option>
                        <option value="Scholarship">Scholarship</option>
                        <option value="Sports">Sports</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label>Discount Method *</label>
                    <div class="radio-group">
                        <label><input type="radio" name="discount_method" value="Fixed" required checked> Fixed</label>
                        <label><input type="radio" name="discount_method" value="Percentage"> Percentage</label>
                    </div>
                </div>
                <div>
                    <label for="amount">Amount *</label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0.01" required>
                </div>
                <div>
                    <label for="fee_head_id">Applicable Fee Head</label>
                    <select name="fee_head_id" id="fee_head_id">
                        <option value="">All Fees</option>
                        <?php foreach ($fee_heads as $fh): ?>
                            <option value="<?= $fh['id'] ?>"><?= e($fh['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="start_date">Start Date *</label>
                    <input type="date" name="start_date" id="start_date" required>
                </div>
                <div>
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date">
                </div>
                <div>
                    <label for="approved_by">Approved By</label>
                    <input type="text" name="approved_by" id="approved_by" placeholder="e.g. Principal">
                </div>
                <div class="full-width">
                    <label for="reason">Reason</label>
                    <textarea name="reason" id="reason" rows="3"></textarea>
                </div>
            </div>
            <div style="margin-top: 20px; text-align: right;">
                <button type="button" class="btn" onclick="closeModal()" style="margin-right: 8px;">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Discount</button>
            </div>
        </form>
    </div>
</div>

<script>
function applyFilters() {
    const status = document.getElementById('filterStatus').value;
    const type = document.getElementById('filterType').value;
    const search = document.getElementById('searchInput').value;
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    if (type) params.set('type', type);
    if (search) params.set('search', search);
    window.location.href = 'discounts.php?' + params.toString();
}

function openModal() {
    document.getElementById('modalTitle').textContent = 'Add Discount';
    document.getElementById('formAction').value = 'create_discount';
    document.getElementById('formId').value = '';
    document.getElementById('discountForm').reset();
    document.getElementById('student_search').value = '';
    document.getElementById('admission_no').value = '';
    document.getElementById('student_name').value = '';
    document.getElementById('discountModal').classList.add('active');
}

function closeModal() {
    document.getElementById('discountModal').classList.remove('active');
}

function editDiscount(discount) {
    document.getElementById('modalTitle').textContent = 'Edit Discount';
    document.getElementById('formAction').value = 'update_discount';
    document.getElementById('formId').value = discount.id;
    document.getElementById('student_search').value = discount.student_name;
    document.getElementById('student_name').value = discount.student_name;
    document.getElementById('admission_no').value = discount.admission_no;
    document.getElementById('discount_type').value = discount.discount_type;
    document.querySelector('input[name="discount_method"][value="' + discount.discount_method + '"]').checked = true;
    document.getElementById('amount').value = discount.amount;
    document.getElementById('fee_head_id').value = discount.applicable_fee_head_id || '';
    document.getElementById('start_date').value = discount.start_date;
    document.getElementById('end_date').value = discount.end_date || '';
    document.getElementById('reason').value = discount.reason || '';
    document.getElementById('approved_by').value = discount.appified_by || '';
    document.getElementById('discountModal').classList.add('active');
}

document.getElementById('student_search').addEventListener('input', function() {
    const val = this.value;
    const options = document.querySelectorAll('#studentList option');
    let matched = false;
    options.forEach(function(opt) {
        if (opt.value === val) {
            document.getElementById('student_name').value = opt.value;
            document.getElementById('admission_no').value = opt.getAttribute('data-admission');
            matched = true;
        }
    });
    if (!matched) {
        document.getElementById('student_name').value = val;
        document.getElementById('admission_no').value = '';
    }
});

document.getElementById('discountModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>
