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

$appId = (int) ($_GET['app_id'] ?? 0);
if (!$appId) {
    header("Location: applications-list.php");
    exit();
}

try { $pdo->exec("ALTER TABLE applications ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL"); } catch (\Throwable $e) {}

$stmt = $pdo->prepare("SELECT a.*, p.name AS parent_name, p.email AS parent_email, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.id = :id AND a.deleted_at IS NULL AND a.deleted_at IS NULL");
$stmt->execute(['id' => $appId]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    header("Location: applications-list.php");
    exit();
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_application'])) {
    $uploadDir = __DIR__ . '/../../uploads/docs/';
    $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];

    $oldStatus = (string) ($app['status'] ?? '');

    $fields = [
        'student_name', 'dob', 'gender', 'religion', 'blood_group', 'aadhaar_no',
        'caste', 'disability', 'disability_details', 'previous_school', 'previous_class', 'class_sought',
        'father_name', 'father_occupation', 'father_aadhaar_no', 'mother_name', 'mother_occupation',
        'mother_aadhaar_no', 'guardian_name', 'guardian_occupation', 'family_annual_income',
        'father_voter_no', 'mother_voter_no',
        'address_line1', 'address_line2', 'post_office', 'police_station', 'district',
        'village_city', 'pin', 'state', 'country',
        'contact_no', 'email',
        'status', 'payment_method', 'payment_status', 'admission_no',
    ];

    $updates = [];
    $params = [];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $updates[] = "$f = :$f";
            $params[":$f"] = trim((string) $_POST[$f]);
        }
    }

    $fileFields = [
        'aadhaar', 'birth_cert', 'leaving_cert', 'prev_marksheet', 'photo', 'caste_cert',
        'father_photo', 'mother_photo', 'father_aadhaar', 'mother_aadhaar',
        'father_voter', 'mother_voter', 'disability_cert', 'guardian_signature',
    ];
    foreach ($fileFields as $ff) {
        if (isset($_FILES[$ff]) && $_FILES[$ff]['error'] === UPLOAD_ERR_OK && in_array($_FILES[$ff]['type'], $allowed)) {
            $newFile = time() . '_' . substr($ff, 0, 3) . '_' . basename($_FILES[$ff]['name']);
            move_uploaded_file($_FILES[$ff]['tmp_name'], $uploadDir . $newFile);
            $updates[] = "$ff = :$ff";
            $params[":$ff"] = $newFile;
        }
    }

    if (!empty($updates)) {
        $params[':id'] = $appId;
        $sql = "UPDATE applications SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt = $pdo->prepare("SELECT a.*, p.name AS parent_name, p.email AS parent_email, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.id = :id AND a.deleted_at IS NULL");
        $stmt->execute(['id' => $appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (($params[':status'] ?? '') === 'Admitted' && empty($app['student_id'])) {
            $nameParts = explode(' ', trim($app['student_name']), 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            $cntStmt = $pdo->query("SELECT COUNT(*) AS cnt FROM students");
            $admissionNo = sprintf("ADM%04d", ((int) $cntStmt->fetch()['cnt']) + 1);

            $addrParts = array_filter([$app['address_line1'], $app['address_line2'], $app['village_city'] ?? $app['district'], $app['state'], $app['pin']]);
            $addr = implode(', ', $addrParts);

            $insStmt = $pdo->prepare("INSERT INTO students (admission_no, first_name, last_name, gender, dob, blood_group, phone, email, address) VALUES (:admission_no, :first_name, :last_name, :gender, :dob, :blood_group, :phone, :email, :address)");
            $insStmt->execute([
                'admission_no' => $admissionNo,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'gender' => $app['gender'],
                'dob' => $app['dob'],
                'blood_group' => $app['blood_group'],
                'phone' => $app['contact_no'],
                'email' => $app['email'],
                'address' => $addr,
            ]);
            $studentId = (int) $pdo->lastInsertId();

            $sessionStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'academic_year' LIMIT 1");
            $sessionStmt->execute();
            $sessionLabel = $sessionStmt->fetchColumn() ?: date('Y') . '-' . (date('y') + 1);

            $enrollStmt = $pdo->prepare("INSERT INTO student_enrollments (student_id, class_name, session_label, status, is_current) VALUES (:student_id, :class_name, :session_label, 'active', 1)");
            $enrollStmt->execute([
                'student_id' => $studentId,
                'class_name' => $app['class_sought'],
                'session_label' => $sessionLabel,
            ]);

            $pdo->prepare("UPDATE applications SET student_id = :sid, admission_no = :ano WHERE id = :id")
                ->execute(['sid' => $studentId, 'ano' => $admissionNo, 'id' => $appId]);

            $stmt = $pdo->prepare("SELECT a.*, p.name AS parent_name, p.email AS parent_email, p.phone AS parent_phone FROM applications a LEFT JOIN parents p ON p.id = a.parent_id WHERE a.id = :id AND a.deleted_at IS NULL");
            $stmt->execute(['id' => $appId]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $success = 'Application updated successfully.';

        $newStatus = (string) ($_POST['status'] ?? $oldStatus);
        if ($newStatus !== $oldStatus && !empty($app['parent_email'])) {
            $parentEmail = $app['parent_email'];
            $studentName = $app['student_name'] ?? '';
            $appNo = $app['application_no'] ?? ('#' . $appId);
            $receiptUrl = 'https://sibapublicschool.com/parent/receipt.php?app_id=' . $appId . '&download=1';
            $loginUrl = 'https://sibapublicschool.com/parent/login.php';
            $subject = 'SIBA Public School – Application Status Update (' . $appNo . ')';
            $body = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;padding:20px;color:#333;">
    <h2>Application Status Update – SIBA Public School</h2>
    <p>Dear {$app['parent_name']},</p>
    <p>The status of the admission application for <strong>{$studentName}</strong> has been updated.</p>
    <table style="background:#f5f5f5;padding:15px;border-radius:8px;margin:15px 0;">
        <tr><td><strong>Application No:</strong></td><td>{$appNo}</td></tr>
        <tr><td><strong>Previous Status:</strong></td><td>{$oldStatus}</td></tr>
        <tr><td><strong>New Status:</strong></td><td><strong>{$newStatus}</strong></td></tr>
    </table>
    <p>You can view the full application details in your parent portal.</p>
    <p><a href="{$receiptUrl}" style="background:#1e293b;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;">Download Application Receipt</a></p>
    <p><a href="{$loginUrl}">Log in to the Parent Portal</a></p>
    <p>Best regards,<br>SIBA Public School Administration</p>
</body>
</html>
HTML;
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: noreply@sibapublicschool.com\r\n";
            try {
                @mail($parentEmail, $subject, $body, $headers);
            } catch (\Throwable) {}
        }
    }
}

$statusBadge = match ($app['status']) {
    'Application started' => '<span class="badge" style="background:#e2e8f0;color:#475569">Application started</span>',
    'Under review' => '<span class="badge" style="background:#fef3c7;color:#92400e">Under review</span>',
    'Admitted' => '<span class="badge" style="background:#d1fae5;color:#065f46">Admitted</span>',
    'Rejected' => '<span class="badge" style="background:#fee2e2;color:#991b1b">Rejected</span>',
    default => '<span class="badge">' . e($app['status']) . '</span>',
};

$payStatusBadge = ($app['payment_status'] ?? 'Pending') === 'Paid'
    ? '<span class="badge" style="background:#d1fae5;color:#065f46">Paid</span>'
    : '<span class="badge" style="background:#fef3c7;color:#92400e">Pending</span>';

$docs = [
    'aadhaar' => ['label' => 'Student Aadhaar Card', 'file' => $app['aadhaar'] ?? ''],
    'birth_cert' => ['label' => 'Student Birth Certificate', 'file' => $app['birth_cert'] ?? ''],
    'leaving_cert' => ['label' => 'Previous School TC/LC', 'file' => $app['leaving_cert'] ?? ''],
    'prev_marksheet' => ['label' => 'Previous Marksheet', 'file' => $app['prev_marksheet'] ?? ''],
    'photo' => ['label' => 'Student Photo', 'file' => $app['photo'] ?? ''],
    'caste_cert' => ['label' => 'Caste Certificate', 'file' => $app['caste_cert'] ?? ''],
    'father_photo' => ['label' => 'Father Photo', 'file' => $app['father_photo'] ?? ''],
    'mother_photo' => ['label' => 'Mother Photo', 'file' => $app['mother_photo'] ?? ''],
    'father_aadhaar' => ['label' => 'Father Aadhaar', 'file' => $app['father_aadhaar'] ?? ''],
    'mother_aadhaar' => ['label' => 'Mother Aadhaar', 'file' => $app['mother_aadhaar'] ?? ''],
    'father_voter' => ['label' => 'Father Voter Card', 'file' => $app['father_voter'] ?? ''],
    'mother_voter' => ['label' => 'Mother Voter Card', 'file' => $app['mother_voter'] ?? ''],
    'disability_cert' => ['label' => 'Disability Certificate', 'file' => $app['disability_cert'] ?? ''],
    'guardian_signature' => ['label' => 'Guardian Signature', 'file' => $app['guardian_signature'] ?? ''],
];

$stateList = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Andaman & Nicobar','Chandigarh','Dadra & Nagar Haveli','Daman & Diu','Delhi','Jammu & Kashmir','Ladakh','Lakshadweep','Puducherry'];
$classOptions = ['Nursery', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8'];
$casteOptions = ['General', 'OBC', 'SC', 'ST', 'Other'];
$incomeOptions = ['Below 1 Lakh', '1-2 Lakhs', '2-5 Lakhs', '5-8 Lakhs', 'Above 8 Lakhs'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Application <?= e($app['application_no'] ?? '#' . $appId) ?> – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        .detail-grid .full-col { grid-column:1 / -1; }
        .detail-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; }
        .detail-card .head { background:#f8fafc; padding:.75rem 1.25rem; font-weight:700; font-size:.95rem; border-bottom:1px solid #e2e8f0; color:#1e293b; }
        .detail-card .head i { margin-right:.5rem; color:#64748b; }
        .detail-card .body { padding:1rem 1.25rem; }
        .field-row { display:flex; align-items:center; padding:.45rem 0; border-bottom:1px solid #f1f5f9; font-size:.875rem; gap:.75rem; }
        .field-row:last-child { border-bottom:none; }
        .field-row .lbl { width:35%; color:#64748b; flex-shrink:0; }
        .field-row .inp { width:65%; }
        .field-row input[type="text"], .field-row input[type="date"], .field-row input[type="tel"],
        .field-row input[type="email"], .field-row input[type="number"], .field-row select,
        .field-row textarea {
            width:100%; padding:.4rem .6rem; border:1px solid #cbd5e1; border-radius:6px; font-size:.85rem; box-sizing:border-box;
        }
        .field-row textarea { resize:vertical; }
        .doc-link { display:inline-block; padding:.2rem .6rem; background:#eaf4fb; color:#2563eb; border-radius:6px; font-size:.8rem; text-decoration:none; font-weight:600; }
        .doc-link:hover { background:#2563eb; color:#fff; }
        .photo-thumb { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid #e2e8f0; }
        .doc-card { background:#f9f9f9; border-radius:8px; padding:.75rem 1rem; text-align:center; }
        .doc-card .doc-label { font-size:.78rem; color:#64748b; margin-bottom:.3rem; font-weight:600; }
        .doc-card .doc-actions { display:flex; flex-direction:column; gap:.3rem; align-items:center; }
        @media (max-width:768px) { .detail-grid { grid-template-columns:1fr; } .field-row { flex-direction:column; align-items:stretch; } .field-row .lbl { width:100%; } .field-row .inp { width:100%; } }
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
                    <h1>Application <?= e($app['application_no'] ?? '#' . $appId) ?></h1>
                    <p>Submitted on <?= date('d M Y, h:i A', strtotime($app['applied_at'])) ?> &middot; <?= $statusBadge ?> &middot; Payment: <?= $payStatusBadge ?></p>
                </div>
                <div style="display:flex;gap:.75rem;">
                    <a href="applications-list.php" class="btn btn-soft">Back to List</a>
                </div>
            </div>
        </section>

        <?php if ($success): ?>
            <div class="flash" style="background:#e8f5e9;border-color:#a5d6a7;color:#2e7d32;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="flash" style="background:#fdecea;border-color:#f3c8c5;color:#8f1c13;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="save_application" value="1">

            <div class="detail-grid">
                <!-- Student Info -->
                <div class="detail-card">
                    <div class="head">Student Information</div>
                    <div class="body">
                        <?php if ($app['photo']): ?>
                            <div style="text-align:center;margin-bottom:.75rem;">
                                <img src="../../uploads/docs/<?= rawurlencode($app['photo']) ?>" alt="Photo" class="photo-thumb">
                            </div>
                        <?php endif; ?>
                        <div class="field-row"><span class="lbl">Full Name *</span><span class="inp"><input type="text" name="student_name" required value="<?= e($app['student_name']) ?>"></span></div>
                        <div class="field-row"><span class="lbl">Date of Birth *</span><span class="inp"><input type="date" name="dob" required value="<?= e($app['dob']) ?>"></span></div>
                        <div class="field-row"><span class="lbl">Gender</span><span class="inp">
                            <select name="gender"><option value="">— Select —</option>
                                <?php foreach (['Male','Female','Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($app['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span></div>
                        <div class="field-row"><span class="lbl">Religion</span><span class="inp"><input type="text" name="religion" value="<?= e($app['religion'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Blood Group</span><span class="inp">
                            <select name="blood_group"><option value="">— Select —</option>
                                <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                                    <option value="<?= $bg ?>" <?= ($app['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span></div>
                        <div class="field-row"><span class="lbl">Aadhaar No.</span><span class="inp"><input type="text" name="aadhaar_no" maxlength="12" inputmode="numeric" pattern="[0-9]*" value="<?= e($app['aadhaar_no'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Caste</span><span class="inp">
                            <select name="caste"><option value="">— Select —</option>
                                <?php foreach ($casteOptions as $c): ?>
                                    <option value="<?= $c ?>" <?= ($app['caste'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span></div>
                        <div class="field-row"><span class="lbl">Disability</span><span class="inp">
                            <select name="disability" onchange="document.getElementById('disDetails').style.display=this.value==='Yes'?'block':'none'">
                                <option value="">— Select —</option>
                                <option value="No" <?= ($app['disability'] ?? '') === 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($app['disability'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                            </select>
                        </span></div>
                        <div id="disDetails" style="display:<?= ($app['disability'] ?? '') === 'Yes' ? 'block' : 'none' ?>">
                            <div class="field-row"><span class="lbl">Disability Details</span><span class="inp"><textarea name="disability_details" rows="2"><?= e($app['disability_details'] ?? '') ?></textarea></span></div>
                        </div>
                        <div class="field-row"><span class="lbl">Previous School</span><span class="inp"><input type="text" name="previous_school" value="<?= e($app['previous_school'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Previous Class</span><span class="inp"><input type="text" name="previous_class" value="<?= e($app['previous_class'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Admission Class *</span><span class="inp">
                            <select name="class_sought" required>
                                <option value="">— Select —</option>
                                <?php foreach ($classOptions as $cl): ?>
                                    <option value="<?= $cl ?>" <?= ($app['class_sought'] ?? '') === $cl ? 'selected' : '' ?>><?= $cl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span></div>
                    </div>
                </div>

                <!-- Parent / Guardian -->
                <div class="detail-card">
                    <div class="head">Parent / Guardian</div>
                    <div class="body">
                        <div class="field-row"><span class="lbl">Father's Name *</span><span class="inp"><input type="text" name="father_name" required value="<?= e($app['father_name']) ?>"></span></div>
                        <div class="field-row"><span class="lbl">Father's Occupation</span><span class="inp"><input type="text" name="father_occupation" value="<?= e($app['father_occupation'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Father Aadhaar No</span><span class="inp"><input type="text" name="father_aadhaar_no" maxlength="12" inputmode="numeric" pattern="[0-9]*" value="<?= e($app['father_aadhaar_no'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Father Voter No</span><span class="inp"><input type="text" name="father_voter_no" value="<?= e($app['father_voter_no'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Mother's Name *</span><span class="inp"><input type="text" name="mother_name" required value="<?= e($app['mother_name']) ?>"></span></div>
                        <div class="field-row"><span class="lbl">Mother's Occupation</span><span class="inp"><input type="text" name="mother_occupation" value="<?= e($app['mother_occupation'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Mother Aadhaar No</span><span class="inp"><input type="text" name="mother_aadhaar_no" maxlength="12" inputmode="numeric" pattern="[0-9]*" value="<?= e($app['mother_aadhaar_no'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Mother Voter No</span><span class="inp"><input type="text" name="mother_voter_no" value="<?= e($app['mother_voter_no'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Guardian Name</span><span class="inp"><input type="text" name="guardian_name" value="<?= e($app['guardian_name'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Guardian Occupation</span><span class="inp"><input type="text" name="guardian_occupation" value="<?= e($app['guardian_occupation'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Annual Income</span><span class="inp">
                            <select name="family_annual_income"><option value="">— Select —</option>
                                <?php foreach ($incomeOptions as $inc): ?>
                                    <option value="<?= $inc ?>" <?= ($app['family_annual_income'] ?? '') === $inc ? 'selected' : '' ?>><?= $inc ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span></div>
                    </div>
                </div>

                <!-- Address -->
                <div class="detail-card">
                    <div class="head">Address</div>
                    <div class="body">
                        <div class="field-row"><span class="lbl">Address Line 1</span><span class="inp"><input type="text" name="address_line1" value="<?= e($app['address_line1'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Address Line 2</span><span class="inp"><input type="text" name="address_line2" value="<?= e($app['address_line2'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Post Office</span><span class="inp"><input type="text" name="post_office" value="<?= e($app['post_office'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Police Station</span><span class="inp"><input type="text" name="police_station" value="<?= e($app['police_station'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">District</span><span class="inp"><input type="text" name="district" value="<?= e($app['district'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Village / City</span><span class="inp"><input type="text" name="village_city" value="<?= e($app['village_city'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">PIN Code</span><span class="inp"><input type="text" name="pin" maxlength="10" inputmode="numeric" pattern="[0-9]*" value="<?= e($app['pin'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">State</span><span class="inp">
                            <select name="state"><option value="">Select State</option>
                                <?php foreach ($stateList as $st): ?>
                                    <option value="<?= $st ?>" <?= ($app['state'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span></div>
                        <div class="field-row"><span class="lbl">Country</span><span class="inp"><input type="text" name="country" value="<?= e($app['country'] ?? 'India') ?>"></span></div>
                    </div>
                </div>

                <!-- Parent Account & Status -->
                <div class="detail-card">
                    <div class="head">Account & Status</div>
                    <div class="body">
                        <div class="field-row"><span class="lbl">Parent Name</span><span class="inp"><input type="text" value="<?= e($app['parent_name'] ?? '—') ?>" disabled style="background:#f1f5f9;"></span></div>
                        <div class="field-row"><span class="lbl">Phone</span><span class="inp"><input type="text" value="<?= e($app['parent_phone'] ?? '—') ?>" disabled style="background:#f1f5f9;"></span></div>
                        <div class="field-row"><span class="lbl">Email</span><span class="inp"><input type="text" value="<?= e($app['parent_email'] ?? '—') ?>" disabled style="background:#f1f5f9;"></span></div>
                        <div class="field-row"><span class="lbl">Admission No</span><span class="inp"><input type="text" name="admission_no" value="<?= e($app['admission_no'] ?? '') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Status</span><span class="inp">
                            <select name="status">
                                <?php foreach (['Application started','Under review','Admitted','Rejected'] as $s): ?>
                                    <option value="<?= $s ?>" <?= ($app['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span></div>
                        <div class="field-row"><span class="lbl">Payment Method</span><span class="inp"><input type="text" name="payment_method" value="<?= e($app['payment_method'] ?? 'Offline') ?>"></span></div>
                        <div class="field-row"><span class="lbl">Payment Status</span><span class="inp">
                            <select name="payment_status">
                                <option value="Pending" <?= ($app['payment_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Paid" <?= ($app['payment_status'] ?? '') === 'Paid' ? 'selected' : '' ?>>Paid</option>
                            </select>
                        </span></div>
                        <div class="field-row"><span class="lbl">Applied On</span><span class="inp"><input type="text" value="<?= date('d M Y, h:i A', strtotime($app['applied_at'])) ?>" disabled style="background:#f1f5f9;"></span></div>
                    </div>
                </div>

                <!-- Documents -->
                <div class="detail-card full-col">
                    <div class="head">Uploaded Documents</div>
                    <div class="body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;">
                            <?php foreach ($docs as $col => $doc): ?>
                                <div class="doc-card">
                                    <div class="doc-label"><?= $doc['label'] ?></div>
                                    <div class="doc-actions">
                                        <?php if ($doc['file']): ?>
                                            <a href="../../uploads/docs/<?= rawurlencode($doc['file']) ?>" target="_blank" class="doc-link">View</a>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:.8rem;">Not uploaded</span>
                                        <?php endif; ?>
                                        <input type="file" name="<?= $col ?>" accept="image/*,application/pdf" >
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="font-size:.78rem;color:#94a3b8;margin-top:.5rem;">Upload a new file to replace the existing document. Leave blank to keep current.</p>
                    </div>
                </div>
            </div>

            <div style="margin-top:1.5rem;display:flex;gap:.75rem;justify-content:flex-end;">
                <a href="applications-list.php" class="btn btn-soft" style="padding:.75rem 1.5rem;">Cancel</a>
                <button type="submit" class="btn" style="padding:.75rem 2rem;">Save Changes</button>
            </div>
        </form>
    </main>
</div>
<script src="../assets/erp.js"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
