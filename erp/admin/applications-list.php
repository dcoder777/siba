<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isSuperAdmin = ($user['role'] ?? '') === 'admin';
$isOwner = ($user['role'] ?? '') === 'owner';
$explicitModules = fetch_user_module_access($pdo, (int) $user['id']);
$userRoles = fetch_user_roles($pdo, (int) $user['id'], (string) ($user['role'] ?? 'admin'));
$menus = menu_for_roles($userRoles, $explicitModules);
$entityMap = entity_config();
$error = '';
$success = '';

$statusOptions = ['Application started', 'Under review', 'Admitted', 'Rejected'];
$currentStatus = trim((string) ($_GET['status'] ?? ''));
$searchQ = trim((string) ($_GET['q'] ?? ''));

// One-time: restore any soft-deleted applications
try { $pdo->exec("UPDATE applications SET deleted_at = NULL WHERE deleted_at IS NOT NULL"); } catch (\Throwable $e) {}

// ─── Delete Application (Permanent) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_app']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    if ($appId > 0) {
        try {
            $pdo->prepare("DELETE FROM applications WHERE id = :id")->execute(['id' => $appId]);
            $success = 'Application deleted permanently.';
        } catch (Exception $e) {
            $error = 'Failed to delete application: ' . $e->getMessage();
        }
    }
}

// ─── Restore Application ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_app']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    if ($appId > 0) {
        try {
            $pdo->prepare("UPDATE applications SET deleted_at = NULL WHERE id = :id")->execute(['id' => $appId]);
            $success = 'Application restored.';
        } catch (Exception $e) {
            $error = 'Failed to restore: ' . $e->getMessage();
        }
    }
}

// ─── Toggle Payment Status ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_payment']) && verify_csrf()) {
    $appId = (int) ($_POST['app_id'] ?? 0);
    $newStatus = trim((string) ($_POST['payment_status'] ?? ''));
    if ($appId > 0 && in_array($newStatus, ['Pending', 'Paid'], true)) {
        try {
            $appStmt = $pdo->prepare("SELECT a.*, p.name AS parent_name, p.email AS parent_email FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.id = :id");
            $appStmt->execute(['id' => $appId]);
            $appData = $appStmt->fetch(PDO::FETCH_ASSOC);
            $oldPaymentStatus = (string) ($appData['payment_status'] ?? '');

            require_once __DIR__ . '/../../includes/application_fee.php';
            ensure_application_payment_amount_column($pdo);
            $payAmt = (float) ($appData['payment_amount'] ?? 0);
            if ($payAmt <= 0) {
                $payAmt = get_application_fee_amount($pdo);
            }
            $pdo->prepare("UPDATE applications SET payment_status = :status, payment_amount = :amt WHERE id = :id")->execute(['status' => $newStatus, 'amt' => $payAmt, 'id' => $appId]);
            $success = 'Payment status updated to ' . $newStatus . '.';

            if ($oldPaymentStatus !== $newStatus && !empty($appData['parent_email'])) {
                $parentEmail = $appData['parent_email'];
                $parentName = $appData['parent_name'] ?? '';
                $studentName = $appData['student_name'] ?? '';
                $appNo = $appData['application_no'] ?? ('#' . $appId);
                $receiptUrl = 'https://sibapublicschool.com/parent/receipt.php?app_id=' . $appId . '&download=1';
                $loginUrl = 'https://sibapublicschool.com/parent/login.php';
                $subject = 'SIBA Public School – Payment Status Update (' . $appNo . ')';
                $body = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;padding:20px;color:#333;">
    <h2>Payment Status Update – SIBA Public School</h2>
    <p>Dear {$parentName},</p>
    <p>The payment status for the admission application of <strong>{$studentName}</strong> has been updated.</p>
    <table style="background:#f5f5f5;padding:15px;border-radius:8px;margin:15px 0;">
        <tr><td><strong>Application No:</strong></td><td>{$appNo}</td></tr>
        <tr><td><strong>Previous Payment Status:</strong></td><td>{$oldPaymentStatus}</td></tr>
        <tr><td><strong>New Payment Status:</strong></td><td><strong>{$newStatus}</strong></td></tr>
    </table>
    <p>You can view the full application details in your parent portal.</p>
    <p><a href="{$receiptUrl}" style="background:#1e293b;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;">Download Application Receipt</a></p>
    <p><a href="{$loginUrl}">Log in to the Parent Portal</a></p>
    <p>Best regards,<br>SIBA Public School Administration</p>
</body>
</html>
HTML;
                $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: noreply@sibapublicschool.com\r\n";
                $emailSent = false;
                try {
                    $emailSent = @mail($parentEmail, $subject, $body, $headers);
                } catch (\Throwable) {}
                if ($emailSent) {
                    $success .= ' An email notification has been sent to ' . htmlspecialchars($parentEmail) . '.';
                }
            }
        } catch (Exception $e) {
            $error = 'Failed to update payment status: ' . $e->getMessage();
        }
    }
}

// ─── Batch Multi-Select Status Update (Admit & Move to Student Directory or Update Status) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_status_update']) && verify_csrf()) {
    $selectedIds = array_filter(array_map('intval', (array) ($_POST['selected_app_ids'] ?? [])));
    $targetStatus = trim((string) ($_POST['target_status'] ?? ''));

    if (empty($selectedIds)) {
        $error = 'Please select at least one application from the list.';
    } elseif (!in_array($targetStatus, $statusOptions, true)) {
        $error = 'Invalid status selected.';
    } else {
        try {
            $updatedCount = 0;
            $admittedCount = 0;

            // Session label for new enrollments
            $sessionStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'academic_year' LIMIT 1");
            $sessionStmt->execute();
            $sessionLabel = $sessionStmt->fetchColumn() ?: date('Y') . '-' . (date('y') + 1);

            // Fetch selected applications
            $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $fetchStmt = $pdo->prepare("
                SELECT a.*, p.name AS parent_name, p.email AS parent_email, p.phone AS parent_phone 
                FROM applications a 
                LEFT JOIN parents p ON p.id = a.parent_id 
                WHERE a.id IN ($inPlaceholders) AND a.deleted_at IS NULL
            ");
            $fetchStmt->execute($selectedIds);
            $appsToUpdate = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

            // Determine starting admission number sequence
            $maxAdm = (int) $pdo->query("SELECT MAX(CAST(SUBSTRING(admission_no, 4) AS UNSIGNED)) FROM students WHERE admission_no LIKE 'ADM%'")->fetchColumn();
            if ($maxAdm <= 0) {
                $maxAdm = (int) $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
            }
            $nextAdmNum = $maxAdm + 1;

            $insStudentStmt = $pdo->prepare("
                INSERT INTO students (admission_no, first_name, last_name, gender, dob, blood_group, phone, email, address, created_at) 
                VALUES (:admission_no, :first_name, :last_name, :gender, :dob, :blood_group, :phone, :email, :address, NOW())
            ");

            $enrollStmt = $pdo->prepare("
                INSERT INTO student_enrollments (student_id, class_name, session_label, status, is_current) 
                VALUES (:student_id, :class_name, :session_label, 'active', 1)
            ");

            $updateAppStmt = $pdo->prepare("
                UPDATE applications 
                SET status = :status, student_id = COALESCE(:student_id, student_id), admission_no = COALESCE(:admission_no, admission_no) 
                WHERE id = :id
            ");

            foreach ($appsToUpdate as $appData) {
                $appId = (int) $appData['id'];
                $oldStatus = (string) ($appData['status'] ?? '');
                $existingStudentId = !empty($appData['student_id']) ? (int) $appData['student_id'] : null;
                $existingAdmNo = !empty($appData['admission_no']) ? (string) $appData['admission_no'] : null;

                $newStudentId = $existingStudentId;
                $newAdmNo = $existingAdmNo;

                if ($targetStatus === 'Admitted' && empty($existingStudentId)) {
                    // Create student in directory
                    $nameParts = explode(' ', trim((string)$appData['student_name']), 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? '';

                    $assignedAdmNo = sprintf("ADM%04d", $nextAdmNum++);
                    $addrParts = array_filter([
                        $appData['address_line1'],
                        $appData['address_line2'],
                        $appData['village_city'] ?? $appData['district'],
                        $appData['state'],
                        $appData['pin']
                    ]);
                    $addr = implode(', ', $addrParts) ?: ($appData['address'] ?? '');

                    $insStudentStmt->execute([
                        'admission_no' => $assignedAdmNo,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'gender' => $appData['gender'] ?? null,
                        'dob' => $appData['dob'] ?? null,
                        'blood_group' => $appData['blood_group'] ?? null,
                        'phone' => $appData['contact_no'] ?: ($appData['parent_phone'] ?? null),
                        'email' => $appData['email'] ?: ($appData['parent_email'] ?? null),
                        'address' => $addr,
                    ]);
                    $newStudentId = (int) $pdo->lastInsertId();
                    $newAdmNo = $assignedAdmNo;

                    // Create enrollment
                    $enrollStmt->execute([
                        'student_id' => $newStudentId,
                        'class_name' => $appData['class_sought'],
                        'session_label' => $sessionLabel,
                    ]);

                    $admittedCount++;
                }

                $updateAppStmt->execute([
                    'status' => $targetStatus,
                    'student_id' => $newStudentId,
                    'admission_no' => $newAdmNo,
                    'id' => $appId
                ]);

                // Email notification if status changed to Admitted and parent email exists
                if ($oldStatus !== $targetStatus && $targetStatus === 'Admitted' && !empty($appData['parent_email'])) {
                    $parentEmail = $appData['parent_email'];
                    $parentName = $appData['parent_name'] ?? 'Parent';
                    $studentName = $appData['student_name'] ?? 'Applicant';
                    $appNo = $appData['application_no'] ?? ('#' . $appId);
                    $subject = "Admission Approved – SIBA Public School ({$appNo})";
                    $body = "Dear {$parentName},\r\n\r\nCongratulations! The admission application for {$studentName} has been approved and admitted.\r\nAdmission Number: {$newAdmNo}\r\nClass: {$appData['class_sought']}\r\n\r\nBest regards,\r\nSIBA Public School Administration";
                    $headers = "From: noreply@sibapublicschool.com\r\n";
                    try { @mail($parentEmail, $subject, $body, $headers); } catch (\Throwable) {}
                }

                $updatedCount++;
            }

            if ($targetStatus === 'Admitted') {
                $success = "Successfully updated {$updatedCount} application(s) to 'Admitted'. {$admittedCount} new student profile(s) moved to the Student Directory!";
            } else {
                $success = "Successfully updated {$updatedCount} application(s) to '{$targetStatus}'.";
            }
        } catch (Exception $e) {
            $error = 'Failed to update application statuses: ' . $e->getMessage();
        }
    }
}

// ─── Fetch Applications ───
$where = [];
$params = [];
$idFilter = trim((string) ($_GET['id'] ?? ''));
if ($idFilter !== '' && is_numeric($idFilter)) {
    $where[] = 'a.id = :id_filter';
    $params['id_filter'] = (int) $idFilter;
}
if ($currentStatus !== '') {
    $where[] = 'a.status = :status';
    $params['status'] = $currentStatus;
}
if ($searchQ !== '') {
    $likeQ = '%' . $searchQ . '%';
    if (ctype_digit($searchQ) || is_numeric($searchQ)) {
        $where[] = '(a.id = :qid_exact OR a.application_no LIKE :q0 OR a.application_number LIKE :q0 OR a.student_name LIKE :q1 OR a.father_name LIKE :q2 OR a.mother_name LIKE :q3 OR p.name LIKE :q4 OR p.phone LIKE :q5 OR a.contact_no LIKE :q6)';
        $params['qid_exact'] = (int) $searchQ;
    } else {
        $where[] = '(a.application_no LIKE :q0 OR a.application_number LIKE :q0 OR a.student_name LIKE :q1 OR a.father_name LIKE :q2 OR a.mother_name LIKE :q3 OR p.name LIKE :q4 OR p.phone LIKE :q5 OR a.contact_no LIKE :q6)';
    }
    $params['q0'] = $likeQ;
    $params['q1'] = $likeQ;
    $params['q2'] = $likeQ;
    $params['q3'] = $likeQ;
    $params['q4'] = $likeQ;
    $params['q5'] = $likeQ;
    $params['q6'] = $likeQ;
}
$whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

$debugInfo = '';
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM applications a LEFT JOIN parents p ON p.id = a.parent_id" . $whereSql);
    $countStmt->execute($params);
    $totalApps = (int) $countStmt->fetch()['c'];
} catch (\Throwable $e) {
    $totalApps = 0;
}

$page = max(1, (int) ($_GET['p'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$sql = "SELECT a.*, p.name AS parent_name, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id" . $whereSql . " ORDER BY a.applied_at DESC LIMIT :lim OFFSET :off";
$listStmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$applications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int) ceil($totalApps / $limit));

// ─── Status badge helper ───
function statusBadge(string $s): string {
    return match ($s) {
        'Application started' => '<span class="badge" style="background:#e2e8f0;color:#475569">Application started</span>',
        'Under review' => '<span class="badge" style="background:#fef3c7;color:#92400e">Under review</span>',
        'Admitted' => '<span class="badge" style="background:#d1fae5;color:#065f46">Admitted</span>',
        'Rejected' => '<span class="badge" style="background:#fee2e2;color:#991b1b">Rejected</span>',
        default => '<span class="badge">' . e($s) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Applications – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
    <style>
        .app-filters { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; }
        .app-filters input, .app-filters select { padding:.45rem .7rem; border:1px solid #cbd5e1; border-radius:6px; font-size:.875rem; }
        .app-filters .btn { padding:.45rem 1rem; }
        .app-table { width:100%; table-layout:auto; border-collapse:collapse; font-size:.85rem; }
        .app-table thead th { text-align:left; padding:.55rem .65rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; position:sticky; top:0; }
        .app-table tbody td { padding:.6rem .65rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .app-table tbody tr:nth-child(even) td { background:#fafbfc; }
        .app-table tbody tr:hover td { background:#eff6ff; }
        .row-actions { display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; }
        .row-actions select { padding:.3rem .4rem; font-size:.78rem; border:1px solid #cbd5e1; border-radius:6px; background:#fff; min-height:auto; }
        .row-actions .btn-xs { padding:.3rem .65rem; font-size:.75rem; border-radius:6px; border:1px solid transparent; cursor:pointer; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; }
        .row-actions .btn-xs-save { background:#1e293b; color:#fff; }
        .row-actions .btn-xs-save:hover { background:#334155; }
        .row-links { display:flex; gap:.35rem; margin-top:.35rem; flex-wrap:wrap; }
        .row-links a, .row-links button { font-size:.75rem; padding:.25rem .55rem; border-radius:6px; text-decoration:none; border:1px solid transparent; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; }
        .row-links .link-view { background:#eff6ff; color:#2563eb; border-color:#bfdbfe; }
        .row-links .link-view:hover { background:#dbeafe; }
        .row-links .link-delete { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
        .row-links .link-delete:hover { background:#fee2e2; }
        .pagination { display:flex; gap:.5rem; align-items:center; margin-top:1rem; }
        .pagination a, .pagination span { padding:.35rem .7rem; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; font-size:.85rem; color:#334155; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#1e293b; color:#fff; border-color:#1e293b; }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Admissions</span>
                    <h1>Manage Applications</h1>
                    <p>View, search, and update admission application statuses.</p>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="alert alert-error" style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="get" class="app-filters">
            <input type="text" name="q" placeholder="Search by ID, App No, name, phone..." value="<?= e($searchQ) ?>" style="min-width:240px;">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= e($s) ?>" <?= $currentStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="applications-list.php" class="btn btn-soft">Clear</a>
            <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $totalApps ?> application<?= $totalApps !== 1 ? 's' : '' ?></span>
        </form>

        <!-- Batch Multi-Select Status Update Toolbar -->
        <form method="post" id="batchActionForm" action="applications-list.php<?= !empty($_SERVER['QUERY_STRING']) ? ('?' . e($_SERVER['QUERY_STRING'])) : '' ?>" style="margin-bottom:1rem;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="batch_status_update" value="1">
            <div id="batchActionBar" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:.85rem 1.15rem;transition:all .2s ease;">
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:.45rem;font-weight:700;color:#0f172a;font-size:.9rem;">
                        <span style="font-size:1.15rem;">☑️</span>
                        <span id="selectedCountText">0 applications selected</span>
                    </div>
                    <div style="display:flex;gap:.35rem;">
                        <button type="button" class="btn btn-sm btn-soft" onclick="toggleSelectAll(true)" style="padding:.25rem .6rem;font-size:.78rem;">Select Page (<?= count($applications) ?>)</button>
                        <button type="button" class="btn btn-sm btn-soft" onclick="toggleSelectAll(false)" style="padding:.25rem .6rem;font-size:.78rem;">Clear</button>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
                    <label for="target_status" style="font-size:.82rem;font-weight:600;color:#334155;margin:0;">Change Status To:</label>
                    <select name="target_status" id="target_status" style="padding:.38rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;background:#fff;font-weight:600;">
                        <option value="">— Choose Status —</option>
                        <option value="Admitted" style="color:#065f46;font-weight:700;">🎓 Admitted (Move to Student Directory)</option>
                        <option value="Under review">⏳ Under review</option>
                        <option value="Application started">📝 Application started</option>
                        <option value="Rejected" style="color:#991b1b;">❌ Rejected</option>
                    </select>
                    <button type="submit" id="btnApplyBatch" class="btn btn-sm btn-primary" style="padding:.4rem 1rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;" disabled onclick="return confirmBatchAction();">
                        <span>Apply Status</span>
                    </button>
                </div>
            </div>
        </form>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow-x:auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center;">
                            <input type="checkbox" id="masterCheckbox" title="Select all applications on this page" style="cursor:pointer;width:16px;height:16px;">
                        </th>
                        <th>#</th>
                        <th>App No</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Parent</th>
                        <th>Phone</th>
                        <th>Applied</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr><td colspan="10" style="text-align:center;padding:2rem;color:#94a3b8;">No applications found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($applications as $i => $a): ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input type="checkbox" class="app-checkbox" value="<?= (int) $a['id'] ?>" style="cursor:pointer;width:16px;height:16px;" onchange="updateSelectedCount();">
                                </td>
                                <td style="color:#94a3b8;"><?= $offset + $i + 1 ?></td>
                                <td><code style="font-size:.8rem;"><?= e($a['application_no'] ?? '—') ?></code></td>
                                <td>
                                    <strong><?= e($a['student_name']) ?></strong>
                                    <?php if ($a['admission_no']): ?>
                                        <br><small style="color:#64748b;"><?= e($a['admission_no']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($a['class_sought']) ?></td>
                                <td><?= e($a['parent_name'] ?? '—') ?></td>
                                <td><?= e($a['parent_phone'] ?? $a['contact_no'] ?? '—') ?></td>
                                <td style="white-space:nowrap;"><?= date('d-m-Y', strtotime($a['applied_at'])) ?></td>
                                <td>
                                    <?= statusBadge($a['status']) ?>
                                    <?php if (!empty($a['student_id'])): ?>
                                        <div style="font-size:.72rem;color:#059669;font-weight:600;margin-top:3px;display:flex;align-items:center;gap:3px;">
                                            <span>✓</span> In Directory
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $payStatus = $a['payment_status'] ?? 'Pending'; ?>
                                    <form method="post" onsubmit="return confirm('Update payment for <?= e($a['student_name']) ?>?')">
                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="app_id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="toggle_payment" value="1">
                                        <div class="row-actions">
                                            <select name="payment_status" title="Payment">
                                                <option value="Pending" <?= $payStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="Paid" <?= $payStatus === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                            </select>
                                            <button type="submit" class="btn-xs btn-xs-save">Save</button>
                                        </div>
                                    </form>
                                    <div class="row-links">
                                        <a href="application-view.php?app_id=<?= (int) $a['id'] ?>" class="link-view">View</a>
                                        <a href="application-receipt.php?app_id=<?= (int) $a['id'] ?>&download=1" class="link-view" target="_blank" title="Download Receipt PDF">Receipt</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete <?= e($a['application_no'] ?? '#' . $a['id']) ?>? This cannot be undone.')">
                                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="app_id" value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="delete_app" value="1">
                                            <button type="submit" class="link-delete">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page - 1])) ?>">‹ Prev</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['p' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['p' => $page + 1])) ?>">Next ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script>
function updateSelectedCount() {
    var checkedBoxes = document.querySelectorAll('.app-checkbox:checked');
    var count = checkedBoxes.length;
    var countText = document.getElementById('selectedCountText');
    var applyBtn = document.getElementById('btnApplyBatch');
    var bar = document.getElementById('batchActionBar');
    var master = document.getElementById('masterCheckbox');
    var allBoxes = document.querySelectorAll('.app-checkbox');

    if (countText) {
        countText.textContent = count + ' application' + (count === 1 ? '' : 's') + ' selected';
    }
    if (applyBtn) {
        applyBtn.disabled = (count === 0);
    }
    if (master && allBoxes.length > 0) {
        master.checked = (count === allBoxes.length);
        master.indeterminate = (count > 0 && count < allBoxes.length);
    }

    if (bar) {
        if (count > 0) {
            bar.style.borderColor = '#3b82f6';
            bar.style.background = '#eff6ff';
            bar.style.boxShadow = '0 2px 8px rgba(59,130,246,0.12)';
        } else {
            bar.style.borderColor = '#cbd5e1';
            bar.style.background = '#f8fafc';
            bar.style.boxShadow = 'none';
        }
    }
}

function toggleSelectAll(checked) {
    document.querySelectorAll('.app-checkbox').forEach(function(cb) {
        cb.checked = checked;
    });
    updateSelectedCount();
}

document.getElementById('masterCheckbox')?.addEventListener('change', function() {
    toggleSelectAll(this.checked);
});

function confirmBatchAction() {
    var checked = document.querySelectorAll('.app-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one application.');
        return false;
    }
    var targetStatus = document.getElementById('target_status').value;
    if (!targetStatus) {
        alert('Please choose a status from the dropdown to apply.');
        document.getElementById('target_status').focus();
        return false;
    }

    // Populate hidden inputs in batchActionForm for all checked checkboxes
    var form = document.getElementById('batchActionForm');
    form.querySelectorAll('input[name="selected_app_ids[]"]').forEach(function(el) { el.remove(); });
    checked.forEach(function(cb) {
        var hiddenInp = document.createElement('input');
        hiddenInp.type = 'hidden';
        hiddenInp.name = 'selected_app_ids[]';
        hiddenInp.value = cb.value;
        form.appendChild(hiddenInp);
    });

    if (targetStatus === 'Admitted') {
        return confirm('Are you sure you want to ADMIT ' + checked.length + ' application(s)?\n\nAll selected applicants will be assigned Admission Numbers and moved directly into the Student Directory.');
    }
    return confirm('Update status of ' + checked.length + ' application(s) to "' + targetStatus + '"?');
}
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
