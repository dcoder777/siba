<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isSuperAdmin = ($user['role'] ?? '') === 'admin';
$explicitModules = fetch_user_module_access($pdo, (int) $user['id']);
$userRoles = fetch_user_roles($pdo, (int) $user['id'], (string) ($user['role'] ?? 'admin'));
$menus = menu_for_roles($userRoles, $explicitModules);
$entityMap = entity_config();
$error = '';
$success = '';
$generatedPhone = '';
$generatedPassword = '';
$generatedAppNo = '';

$classOptions = ['Nursery', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8'];
$genderOptions = ['Male', 'Female', 'Other'];
$bloodGroupOptions = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$incomeOptions = ['Below 1 Lakh', '1 - 2.5 Lakhs', '2.5 - 5 Lakhs', '5 - 10 Lakhs', 'Above 10 Lakhs'];
$casteOptions = ['General', 'OBC', 'SC', 'ST', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $parentName = trim((string) ($_POST['parent_name'] ?? ''));
    $parentEmail = trim((string) ($_POST['parent_email'] ?? ''));
    $parentPhone = preg_replace('/\D/', '', $_POST['parent_phone'] ?? '');
    $parentPassword = trim((string) ($_POST['parent_password'] ?? ''));

    $studentName = trim((string) ($_POST['student_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $classSought = trim((string) ($_POST['class_sought'] ?? ''));
    $dob = trim((string) ($_POST['dob'] ?? ''));
    $gender = trim((string) ($_POST['gender'] ?? ''));
    $bloodGroup = trim((string) ($_POST['blood_group'] ?? ''));
    $religion = trim((string) ($_POST['religion'] ?? ''));
    $aadhaarNo = trim((string) ($_POST['aadhaar_no'] ?? ''));
    $caste = trim((string) ($_POST['caste'] ?? ''));
    $disability = trim((string) ($_POST['disability'] ?? ''));
    $disabilityDetails = trim((string) ($_POST['disability_details'] ?? ''));
    $previousSchool = trim((string) ($_POST['previous_school'] ?? ''));
    $previousClass = trim((string) ($_POST['previous_class'] ?? ''));
    $fatherName = trim((string) ($_POST['father_name'] ?? ''));
    $fatherOccupation = trim((string) ($_POST['father_occupation'] ?? ''));
    $motherName = trim((string) ($_POST['mother_name'] ?? ''));
    $motherOccupation = trim((string) ($_POST['mother_occupation'] ?? ''));
    $guardianName = trim((string) ($_POST['guardian_name'] ?? ''));
    $guardianOccupation = trim((string) ($_POST['guardian_occupation'] ?? ''));
    $familyIncome = trim((string) ($_POST['family_annual_income'] ?? ''));
    $fatherAadhaarNo = trim((string) ($_POST['father_aadhaar_no'] ?? ''));
    $motherAadhaarNo = trim((string) ($_POST['mother_aadhaar_no'] ?? ''));
    $fatherVoterNo = trim((string) ($_POST['father_voter_no'] ?? ''));
    $motherVoterNo = trim((string) ($_POST['mother_voter_no'] ?? ''));
    $addressLine1 = trim((string) ($_POST['address_line1'] ?? ''));
    $addressLine2 = trim((string) ($_POST['address_line2'] ?? ''));
    $postOffice = trim((string) ($_POST['post_office'] ?? ''));
    $policeStation = trim((string) ($_POST['police_station'] ?? ''));
    $district = trim((string) ($_POST['district'] ?? ''));
    $villageCity = trim((string) ($_POST['village_city'] ?? ''));
    $pin = trim((string) ($_POST['pin'] ?? ''));
    $state = trim((string) ($_POST['state'] ?? ''));
    $country = trim((string) ($_POST['country'] ?? 'India'));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Offline'));

    $errors = [];
    if ($parentName === '') $errors[] = 'Parent name is required.';
    if ($parentEmail === '' || !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid parent email is required.';
    if (strlen($parentPhone) !== 10) $errors[] = 'Parent phone must be exactly 10 digits.';
    if ($studentName === '') $errors[] = 'Student name is required.';
    if ($classSought === '' || !in_array($classSought, $classOptions, true)) $errors[] = 'A valid class must be selected.';
    if ($dob === '') $errors[] = 'Date of birth is required.';
    if ($fatherName === '') $errors[] = 'Father name is required.';
    if ($motherName === '') $errors[] = 'Mother name is required.';

    if (empty($errors)) {
        if ($parentPassword === '') {
            $parentPassword = substr(bin2hex(random_bytes(4)), 0, 8);
        }
        $passwordHash = password_hash($parentPassword, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction();

            // Auto-migrate new columns
            $migrations = [
                "ALTER TABLE applications ADD COLUMN father_aadhaar_no VARCHAR(20) AFTER father_name",
                "ALTER TABLE applications ADD COLUMN mother_aadhaar_no VARCHAR(20) AFTER mother_name",
                "ALTER TABLE applications ADD COLUMN father_voter_no VARCHAR(30) AFTER father_occupation",
                "ALTER TABLE applications ADD COLUMN mother_voter_no VARCHAR(30) AFTER mother_occupation",
            ];
            foreach ($migrations as $mig) {
                try { $pdo->exec($mig); } catch (\Throwable $e) {}
            }

            $check = $pdo->prepare("SELECT id FROM parents WHERE phone = :phone LIMIT 1");
            $check->execute(['phone' => $parentPhone]);
            if ($check->fetch()) {
                throw new \RuntimeException('A parent with this phone number already exists.');
            }

            $check = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $check->execute(['email' => $parentEmail]);
            if ($check->fetch()) {
                throw new \RuntimeException('A user with this email already exists.');
            }

            $stmt = $pdo->prepare("INSERT INTO parents (name, email, phone, password, created_at) VALUES (:name, :email, :phone, :password, NOW())");
            $stmt->execute(['name' => $parentName, 'email' => $parentEmail, 'phone' => $parentPhone, 'password' => $passwordHash]);
            $parentId = (int) $pdo->lastInsertId();

            $role = $pdo->query("SELECT id FROM roles WHERE name = 'parent' LIMIT 1")->fetch();
            if (!$role) throw new \RuntimeException('Parent role not found in the system.');
            $roleId = (int) $role['id'];

            $stmt = $pdo->prepare("INSERT INTO users (role_id, name, email, password_hash, is_active, created_at, updated_at) VALUES (:role_id, :name, :email, :password_hash, 1, NOW(), NOW())");
            $stmt->execute(['role_id' => $roleId, 'name' => $parentName, 'email' => $parentEmail, 'password_hash' => $passwordHash]);
            $userId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT IGNORE INTO user_role_assignments (user_id, role_id, is_active, created_at, updated_at) VALUES (:user_id, :role_id, 1, NOW(), NOW())");
            $stmt->execute(['user_id' => $userId, 'role_id' => $roleId]);

            $pdo->prepare("UPDATE parents SET user_id = :user_id WHERE id = :id")
                ->execute(['user_id' => $userId, 'id' => $parentId]);

            $nameParts = explode(' ', trim($studentName), 2);
            $firstName = $nameParts[0] ?: $studentName;

            $addrParts = array_filter([$addressLine1, $addressLine2, $villageCity ?: $district, $state, $pin]);
            $combinedAddress = implode(', ', $addrParts);

            // File uploads
            $uploadDir = __DIR__ . '/../../uploads/docs/';
            $birthCert = '';
            $aadhaarFile = '';
            $leavingCert = '';
            $prevMarksheet = '';
            $photo = '';
            $casteCert = '';
            $fatherPhoto = '';
            $motherPhoto = '';
            $fatherAadhaar = '';
            $motherAadhaar = '';
            $fatherVoter = '';
            $motherVoter = '';
            $disabilityCert = '';
            $guardianSig = '';
            $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];

            if (isset($_FILES['birth_cert']) && $_FILES['birth_cert']['error'] === UPLOAD_ERR_OK && in_array($_FILES['birth_cert']['type'], $allowed)) {
                $birthCert = time() . '_bc_' . basename($_FILES['birth_cert']['name']);
                move_uploaded_file($_FILES['birth_cert']['tmp_name'], $uploadDir . $birthCert);
            }
            if (isset($_FILES['aadhaar_file']) && $_FILES['aadhaar_file']['error'] === UPLOAD_ERR_OK && in_array($_FILES['aadhaar_file']['type'], $allowed)) {
                $aadhaarFile = time() . '_aa_' . basename($_FILES['aadhaar_file']['name']);
                move_uploaded_file($_FILES['aadhaar_file']['tmp_name'], $uploadDir . $aadhaarFile);
            }
            if (isset($_FILES['leaving_cert']) && $_FILES['leaving_cert']['error'] === UPLOAD_ERR_OK && in_array($_FILES['leaving_cert']['type'], $allowed)) {
                $leavingCert = time() . '_lc_' . basename($_FILES['leaving_cert']['name']);
                move_uploaded_file($_FILES['leaving_cert']['tmp_name'], $uploadDir . $leavingCert);
            }
            if (isset($_FILES['prev_marksheet']) && $_FILES['prev_marksheet']['error'] === UPLOAD_ERR_OK && in_array($_FILES['prev_marksheet']['type'], $allowed)) {
                $prevMarksheet = time() . '_pm_' . basename($_FILES['prev_marksheet']['name']);
                move_uploaded_file($_FILES['prev_marksheet']['tmp_name'], $uploadDir . $prevMarksheet);
            }
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK && in_array($_FILES['photo']['type'], $allowed)) {
                $photo = time() . '_ph_' . basename($_FILES['photo']['name']);
                move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photo);
            }
            if (isset($_FILES['caste_cert']) && $_FILES['caste_cert']['error'] === UPLOAD_ERR_OK && in_array($_FILES['caste_cert']['type'], $allowed)) {
                $casteCert = time() . '_cc_' . basename($_FILES['caste_cert']['name']);
                move_uploaded_file($_FILES['caste_cert']['tmp_name'], $uploadDir . $casteCert);
            }
            if (isset($_FILES['father_photo']) && $_FILES['father_photo']['error'] === UPLOAD_ERR_OK && in_array($_FILES['father_photo']['type'], $allowed)) {
                $fatherPhoto = time() . '_fp_' . basename($_FILES['father_photo']['name']);
                move_uploaded_file($_FILES['father_photo']['tmp_name'], $uploadDir . $fatherPhoto);
            }
            if (isset($_FILES['mother_photo']) && $_FILES['mother_photo']['error'] === UPLOAD_ERR_OK && in_array($_FILES['mother_photo']['type'], $allowed)) {
                $motherPhoto = time() . '_mp_' . basename($_FILES['mother_photo']['name']);
                move_uploaded_file($_FILES['mother_photo']['tmp_name'], $uploadDir . $motherPhoto);
            }
            if (isset($_FILES['father_aadhaar']) && $_FILES['father_aadhaar']['error'] === UPLOAD_ERR_OK && in_array($_FILES['father_aadhaar']['type'], $allowed)) {
                $fatherAadhaar = time() . '_fa_' . basename($_FILES['father_aadhaar']['name']);
                move_uploaded_file($_FILES['father_aadhaar']['tmp_name'], $uploadDir . $fatherAadhaar);
            }
            if (isset($_FILES['mother_aadhaar']) && $_FILES['mother_aadhaar']['error'] === UPLOAD_ERR_OK && in_array($_FILES['mother_aadhaar']['type'], $allowed)) {
                $motherAadhaar = time() . '_ma_' . basename($_FILES['mother_aadhaar']['name']);
                move_uploaded_file($_FILES['mother_aadhaar']['tmp_name'], $uploadDir . $motherAadhaar);
            }
            if (isset($_FILES['father_voter']) && $_FILES['father_voter']['error'] === UPLOAD_ERR_OK && in_array($_FILES['father_voter']['type'], $allowed)) {
                $fatherVoter = time() . '_fv_' . basename($_FILES['father_voter']['name']);
                move_uploaded_file($_FILES['father_voter']['tmp_name'], $uploadDir . $fatherVoter);
            }
            if (isset($_FILES['mother_voter']) && $_FILES['mother_voter']['error'] === UPLOAD_ERR_OK && in_array($_FILES['mother_voter']['type'], $allowed)) {
                $motherVoter = time() . '_mv_' . basename($_FILES['mother_voter']['name']);
                move_uploaded_file($_FILES['mother_voter']['tmp_name'], $uploadDir . $motherVoter);
            }
            if (isset($_FILES['disability_cert']) && $_FILES['disability_cert']['error'] === UPLOAD_ERR_OK && in_array($_FILES['disability_cert']['type'], $allowed)) {
                $disabilityCert = time() . '_dc_' . basename($_FILES['disability_cert']['name']);
                move_uploaded_file($_FILES['disability_cert']['tmp_name'], $uploadDir . $disabilityCert);
            }
            if (isset($_FILES['guardian_signature']) && $_FILES['guardian_signature']['error'] === UPLOAD_ERR_OK && in_array($_FILES['guardian_signature']['type'], $allowed)) {
                $guardianSig = time() . '_gs_' . basename($_FILES['guardian_signature']['name']);
                move_uploaded_file($_FILES['guardian_signature']['tmp_name'], $uploadDir . $guardianSig);
            }

            // Generate application number
            $year = date('Y');
            $prefix = "SBA-{$year}-";
            $countStmt = $pdo->query("SELECT COUNT(*) AS c FROM applications WHERE application_no LIKE '{$prefix}%'");
            $appCount = (int) $countStmt->fetch()['c'];
            $appNo = $prefix . str_pad((string) ($appCount + 1), 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO applications (parent_id, application_no, student_name, first_name, middle_name, last_name, dob, gender, religion, blood_group, aadhaar_no, caste, disability, disability_details, previous_school, previous_class, class_sought, address_line1, address_line2, post_office, police_station, district, village_city, pin, state, country, father_name, father_occupation, father_aadhaar_no, mother_name, mother_occupation, mother_aadhaar_no, guardian_name, guardian_occupation, family_annual_income, father_voter_no, mother_voter_no, address, birth_cert, aadhaar, leaving_cert, prev_marksheet, photo, caste_cert, father_photo, mother_photo, father_aadhaar, mother_aadhaar, father_voter, mother_voter, disability_cert, guardian_signature, payment_method, payment_status, status, applied_at) VALUES (:parent_id, :application_no, :student_name, :first_name, :middle_name, :last_name, :dob, :gender, :religion, :blood_group, :aadhaar_no, :caste, :disability, :disability_details, :previous_school, :previous_class, :class_sought, :address_line1, :address_line2, :post_office, :police_station, :district, :village_city, :pin, :state, :country, :father_name, :father_occupation, :father_aadhaar_no, :mother_name, :mother_occupation, :mother_aadhaar_no, :guardian_name, :guardian_occupation, :family_annual_income, :father_voter_no, :mother_voter_no, :address, :birth_cert, :aadhaar, :leaving_cert, :prev_marksheet, :photo, :caste_cert, :father_photo, :mother_photo, :father_aadhaar, :mother_aadhaar, :father_voter, :mother_voter, :disability_cert, :guardian_signature, :payment_method, :payment_status, 'Application started', NOW())");
            $stmt->execute([
                'parent_id' => $parentId, 'application_no' => $appNo, 'student_name' => $studentName, 'first_name' => $firstName,
                'middle_name' => $middleName ?: null, 'last_name' => $lastName ?: null, 'dob' => $dob,
                'gender' => $gender ?: null, 'religion' => $religion ?: null, 'blood_group' => $bloodGroup ?: null,
                'aadhaar_no' => $aadhaarNo ?: null, 'caste' => $caste ?: null, 'disability' => $disability ?: null,
                'disability_details' => $disabilityDetails ?: null,
                'previous_school' => $previousSchool ?: null,
                'previous_class' => $previousClass ?: null, 'class_sought' => $classSought,
                'address_line1' => $addressLine1 ?: null, 'address_line2' => $addressLine2 ?: null,
                'post_office' => $postOffice ?: null, 'police_station' => $policeStation ?: null,
                'district' => $district ?: null, 'village_city' => $villageCity ?: null,
                'pin' => $pin ?: null, 'state' => $state ?: null, 'country' => $country ?: null,
                'father_name' => $fatherName, 'father_occupation' => $fatherOccupation ?: null,
                'father_aadhaar_no' => $fatherAadhaarNo ?: null,
                'mother_name' => $motherName, 'mother_occupation' => $motherOccupation ?: null,
                'mother_aadhaar_no' => $motherAadhaarNo ?: null,
                'guardian_name' => $guardianName ?: null, 'guardian_occupation' => $guardianOccupation ?: null,
                'family_annual_income' => $familyIncome ?: null,
                'father_voter_no' => $fatherVoterNo ?: null, 'mother_voter_no' => $motherVoterNo ?: null,
                'address' => $combinedAddress ?: null,
                'birth_cert' => $birthCert ?: null, 'aadhaar' => $aadhaarFile ?: null,
                'leaving_cert' => $leavingCert ?: null, 'prev_marksheet' => $prevMarksheet ?: null,
                'photo' => $photo ?: null, 'caste_cert' => $casteCert ?: null,
                'father_photo' => $fatherPhoto ?: null, 'mother_photo' => $motherPhoto ?: null,
                'father_aadhaar' => $fatherAadhaar ?: null, 'mother_aadhaar' => $motherAadhaar ?: null,
                'father_voter' => $fatherVoter ?: null, 'mother_voter' => $motherVoter ?: null,
                'disability_cert' => $disabilityCert ?: null, 'guardian_signature' => $guardianSig ?: null,
                'payment_method' => $paymentMethod, 'payment_status' => 'Pending',
            ]);

            $pdo->commit();

            $generatedPhone = $parentPhone;
            $generatedPassword = $parentPassword;

            $emailSent = false;
            $subject = 'Welcome to SIBA Public School – Your Parent Portal Credentials';
            $loginUrl = 'http://localhost/siba/parent/login.php';
            $emailBody = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;padding:20px;color:#333;">
    <h2>Welcome to SIBA Public School</h2>
    <p>Dear {$parentName},</p>
    <p>A parent portal account has been created for you at <strong>SIBA Public School</strong>.</p>
    <p>You can use the following credentials to log in and track your child's admission application status:</p>
    <table style="background:#f5f5f5;padding:15px;border-radius:8px;margin:15px 0;">
        <tr><td><strong>Portal URL:</strong></td><td><a href="{$loginUrl}">{$loginUrl}</a></td></tr>
        <tr><td><strong>Phone:</strong></td><td>{$parentPhone}</td></tr>
        <tr><td><strong>Password:</strong></td><td>{$parentPassword}</td></tr>
    </table>
    <p>Please keep this information safe. You can change your password after logging in.</p>
    <p>Best regards,<br>SIBA Public School Administration</p>
</body>
</html>
HTML;
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: noreply@sibaschool.com\r\n";
            try {
                $emailSent = mail($parentEmail, $subject, $emailBody, $headers);
            } catch (\Throwable) {
                $emailSent = false;
            }

            $success = 'Application submitted successfully! Parent account and student application have been created.';
            if (!$emailSent) {
                $success .= ' Email notification could not be sent; please share the credentials manually (shown below).';
            } else {
                $success .= ' An email with login credentials has been sent to the parent.';
            }
            $generatedAppNo = $appNo;
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'An unexpected error occurred: ' . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admission Application — SIBA ERP Admin</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .intake-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        .intake-grid .full-col { grid-column:1 / -1; }
        @media (max-width:860px) { .intake-grid { grid-template-columns:1fr; } }
        .cred-box { background:#e8f5e9; border:1px solid #a5d6a7; border-radius:8px; padding:1rem 1.25rem; margin-bottom:1rem; }
        .cred-box strong { color:#2e7d32; }
        .cred-box code { background:#fff; padding:2px 8px; border-radius:4px; font-size:1rem; }
    </style>
</head>
<body>
<div class="admin-layout">
    <aside class="sidebar" style="display:flex;flex-direction:column;">
        <div class="brand-block stack" style="gap:.6rem;padding:1.2rem 1rem;">
            <span class="eyebrow" style="background:rgba(255,255,255,.1);color:#effff5">SIBA ERP</span>
            <div class="brand-copy">
                <h2 style="font-size:1.7rem;color:#fff">Administration</h2>
                <p><?= e((string) $user['name']) ?> signed in as <?= e((string) $user['role']) ?>.</p>
            </div>
        </div>
        <div class="nav-group">
            <div class="nav-title">Admissions</div>
            <a class="nav-link active" href="application-intake.php">
                <span class="sidebar-icon">📋</span><span>Application Intake</span><span class="nav-tag">New</span>
            </a>
            <a class="nav-link" href="applications-list.php">
                <span class="sidebar-icon">📂</span><span>Applications</span><span class="nav-tag">List</span>
            </a>
            <a class="nav-link" href="parents-list.php">
                <span class="sidebar-icon">👤</span><span>Parents</span>
            </a>
            <a class="nav-link" href="events-manager.php">
                <span class="sidebar-icon">📅</span><span>Events & News</span>
            </a>
            <a class="nav-link" href="gallery-manager.php">
                <span class="sidebar-icon">🖼</span><span>Gallery</span>
            </a>
        </div>
        <div class="nav-group" style="margin-top:auto;">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Admissions</span>
                    <h1>Admission Application</h1>
                    <p>This form creates a parent portal account and an admission application in one step. The parent will receive their login credentials via email.</p>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fdecea;border-color:#f3c8c5;color:#8f1c13"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#e8f5e9;border-color:#a5d6a7;color:#2e7d32"><?= $success ?></div>
            <?php if ($generatedPhone !== ''): ?>
                <div class="cred-box">
                    <strong>Application Created</strong><br>
                    Application No: <code><?= e($generatedAppNo) ?></code>
                </div>
                <div class="cred-box">
                    <strong>Parent Login Credentials</strong><br>
                    Portal: <a href="http://localhost/siba/parent/login.php">http://localhost/siba/parent/login.php</a><br>
                    Phone: <code><?= e($generatedPhone) ?></code><br>
                    Password: <code><?= e($generatedPassword) ?></code>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="stack" style="gap:1.5rem;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Parent Account Details</h2>
                    <p>These credentials will be used by the parent to log into the parent portal.</p>
                </div>
                <div class="field-grid">
                    <div>
                        <label for="parent_name">Full Name *</label>
                        <input id="parent_name" name="parent_name" type="text" required value="<?= e($_POST['parent_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="parent_email">Email Address *</label>
                        <input id="parent_email" name="parent_email" type="email" required value="<?= e($_POST['parent_email'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="parent_phone">Phone Number *</label>
                        <input id="parent_phone" name="parent_phone" type="tel" maxlength="10" required value="<?= e($_POST['parent_phone'] ?? '') ?>" placeholder="10-digit mobile number">
                    </div>
                    <div>
                        <label for="parent_password" style="white-space:nowrap;">Password <span style="font-weight:400;color:var(--text-light)">(leave empty to auto-generate)</span></label>
                        <input id="parent_password" name="parent_password" type="text" value="<?= e($_POST['parent_password'] ?? '') ?>" placeholder="Auto-generate if empty">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Student Details</h2>
                    <p>Enter the student's details for the admission application.</p>
                </div>
                <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                    <div style="flex:1;min-width:200px;">
                        <label for="student_name">First name *</label>
                        <input id="student_name" name="student_name" type="text" required value="<?= e($_POST['student_name'] ?? '') ?>">
                    </div>
                    <div style="flex:0 0 auto;">
                        <label for="photo">Passport Size Photo</label>
                        <div style="display:flex;gap:.8rem;align-items:flex-end;">
                            <div id="photoPreview" style="width:100px;height:120px;border:2px solid #cbd5e1;border-radius:4px;display:flex;align-items:center;justify-content:center;background:#f8fafc;overflow:hidden;">
                                <span style="font-size:.7rem;color:#94a3b8;text-align:center;">No Photo</span>
                            </div>
                            <input id="photo" name="photo" type="file" accept="image/*" onchange="previewPassport(this,'photoPreview')">
                        </div>
                    </div>
                </div>
                    <div>
                        <label for="middle_name">Middle Name</label>
                        <input id="middle_name" name="middle_name" type="text" value="<?= e($_POST['middle_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="last_name">Last Name</label>
                        <input id="last_name" name="last_name" type="text" value="<?= e($_POST['last_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="class_sought">Class Applying For *</label>
                        <select id="class_sought" name="class_sought" required>
                            <option value="">— Select Class —</option>
                            <?php foreach ($classOptions as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= ($_POST['class_sought'] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="dob">Date of Birth *</label>
                        <input id="dob" name="dob" type="date" required value="<?= e($_POST['dob'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">— Select —</option>
                            <?php foreach ($genderOptions as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= ($_POST['gender'] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="blood_group">Blood Group</label>
                        <select id="blood_group" name="blood_group">
                            <option value="">— Select —</option>
                            <?php foreach ($bloodGroupOptions as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= ($_POST['blood_group'] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="religion">Religion</label>
                        <input id="religion" name="religion" type="text" value="<?= e($_POST['religion'] ?? '') ?>">
                    </div>
                    <div style="display:flex;gap:1rem;">
                        <div style="flex:1;">
                            <label for="aadhaar_no">Aadhaar Number</label>
                            <input id="aadhaar_no" name="aadhaar_no" type="text" value="<?= e($_POST['aadhaar_no'] ?? '') ?>">
                        </div>
                        <div style="flex:1;">
                            <label for="aadhaar_file">Aadhaar Card Copy</label>
                            <input id="aadhaar_file" name="aadhaar_file" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="caste">Caste</label>
                            <select id="caste" name="caste">
                                <option value="">— Select —</option>
                                <?php foreach ($casteOptions as $opt): ?>
                                    <option value="<?= e($opt) ?>" <?= ($_POST['caste'] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label for="caste_cert">Caste Certificate</label>
                            <input id="caste_cert" name="caste_cert" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="disability">Disability</label>
                            <select id="disability" name="disability" onchange="document.getElementById('disabilityDetailsRow').style.display=this.value==='Yes'?'block':'none'">
                                <option value="">— Select —</option>
                                <option value="No" <?= ($_POST['disability'] ?? '') === 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($_POST['disability'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                            </select>
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label for="disability_cert">Disability Certificate</label>
                            <input id="disability_cert" name="disability_cert" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div id="disabilityDetailsRow" style="display:<?= (($_POST['disability'] ?? '') === 'Yes') ? 'block' : 'none' ?>">
                        <label for="disability_details">Disability Details</label>
                        <textarea id="disability_details" name="disability_details" rows="2" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;"><?= e($_POST['disability_details'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:1rem;">
                        <div style="flex:1;">
                            <label for="previous_school">Previous School</label>
                            <input id="previous_school" name="previous_school" type="text" value="<?= e($_POST['previous_school'] ?? '') ?>">
                        </div>
                        <div style="flex:1;">
                            <label for="leaving_cert">School Leaving Certificate / TC Copy</label>
                            <input id="leaving_cert" name="leaving_cert" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="previous_class">Previous Class</label>
                            <input id="previous_class" name="previous_class" type="text" value="<?= e($_POST['previous_class'] ?? '') ?>">
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label for="prev_marksheet">Previous Class Marksheet Copy</label>
                            <input id="prev_marksheet" name="prev_marksheet" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div>
                        <label for="birth_cert">Student Birth Certificate Copy</label>
                        <input id="birth_cert" name="birth_cert" type="file" accept="image/*,application/pdf">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Parent / Guardian Details</h2>
                </div>
                <div class="field-grid" style="grid-template-columns:1fr;">
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                        <div style="flex:1;min-width:200px;">
                            <label for="father_name">Father's Name *</label>
                            <input id="father_name" name="father_name" type="text" required value="<?= e($_POST['father_name'] ?? '') ?>">
                        </div>
                        <div style="flex:0 0 auto;">
                            <label for="father_photo">Father's Photo</label>
                            <div style="display:flex;gap:.8rem;align-items:flex-end;">
                                <div id="fatherPhotoPreview" style="width:80px;height:100px;border:2px solid #cbd5e1;border-radius:4px;display:flex;align-items:center;justify-content:center;background:#f8fafc;overflow:hidden;">
                                    <span style="font-size:.65rem;color:#94a3b8;text-align:center;">No Photo</span>
                                </div>
                                <input id="father_photo" name="father_photo" type="file" accept="image/*" onchange="previewPassport(this,'fatherPhotoPreview')">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="father_occupation">Father's Occupation</label>
                        <input id="father_occupation" name="father_occupation" type="text" value="<?= e($_POST['father_occupation'] ?? '') ?>">
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="father_aadhaar_no">Father's Aadhaar Number</label>
                            <input id="father_aadhaar_no" name="father_aadhaar_no" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="12" value="<?= e($_POST['father_aadhaar_no'] ?? '') ?>">
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label for="father_aadhaar">Father Aadhaar Copy</label>
                            <input id="father_aadhaar" name="father_aadhaar" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="father_voter_no">Father's Voter ID Number</label>
                            <input id="father_voter_no" name="father_voter_no" type="text" value="<?= e($_POST['father_voter_no'] ?? '') ?>">
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label for="father_voter">Father Voter Card Copy</label>
                            <input id="father_voter" name="father_voter" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                        <div style="flex:1;min-width:200px;">
                            <label for="mother_name">Mother's Name *</label>
                            <input id="mother_name" name="mother_name" type="text" required value="<?= e($_POST['mother_name'] ?? '') ?>">
                        </div>
                        <div style="flex:0 0 auto;">
                            <label for="mother_photo">Mother's Photo</label>
                            <div style="display:flex;gap:.8rem;align-items:flex-end;">
                                <div id="motherPhotoPreview" style="width:80px;height:100px;border:2px solid #cbd5e1;border-radius:4px;display:flex;align-items:center;justify-content:center;background:#f8fafc;overflow:hidden;">
                                    <span style="font-size:.65rem;color:#94a3b8;text-align:center;">No Photo</span>
                                </div>
                                <input id="mother_photo" name="mother_photo" type="file" accept="image/*" onchange="previewPassport(this,'motherPhotoPreview')">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="mother_occupation">Mother's Occupation</label>
                        <input id="mother_occupation" name="mother_occupation" type="text" value="<?= e($_POST['mother_occupation'] ?? '') ?>">
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="mother_aadhaar_no">Mother's Aadhaar Number</label>
                            <input id="mother_aadhaar_no" name="mother_aadhaar_no" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="12" value="<?= e($_POST['mother_aadhaar_no'] ?? '') ?>">
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label for="mother_aadhaar">Mother Aadhaar Copy</label>
                            <input id="mother_aadhaar" name="mother_aadhaar" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label for="mother_voter_no">Mother's Voter ID Number</label>
                            <input id="mother_voter_no" name="mother_voter_no" type="text" value="<?= e($_POST['mother_voter_no'] ?? '') ?>">
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label for="mother_voter">Mother Voter Card Copy</label>
                            <input id="mother_voter" name="mother_voter" type="file" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div>
                        <label for="guardian_name">Guardian Name <span style="font-weight:400;color:var(--text-light)">(if different)</span></label>
                        <input id="guardian_name" name="guardian_name" type="text" value="<?= e($_POST['guardian_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="guardian_occupation">Guardian Occupation</label>
                        <input id="guardian_occupation" name="guardian_occupation" type="text" value="<?= e($_POST['guardian_occupation'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="family_annual_income">Family Annual Income</label>
                        <select id="family_annual_income" name="family_annual_income">
                            <option value="">— Select —</option>
                            <?php foreach ($incomeOptions as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= ($_POST['family_annual_income'] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="guardian_signature">Guardian Signature</label>
                        <input id="guardian_signature" name="guardian_signature" type="file" accept="image/*">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Address Details</h2>
                </div>
                <div class="field-grid" style="grid-template-columns:1fr;">
                    <div>
                        <label for="address_line1">Address Line 1</label>
                        <input id="address_line1" name="address_line1" type="text" value="<?= e($_POST['address_line1'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="address_line2">Address Line 2</label>
                        <input id="address_line2" name="address_line2" type="text" value="<?= e($_POST['address_line2'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="post_office">Post Office</label>
                        <input id="post_office" name="post_office" type="text" value="<?= e($_POST['post_office'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="police_station">Police Station</label>
                        <input id="police_station" name="police_station" type="text" value="<?= e($_POST['police_station'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="district">District</label>
                        <input id="district" name="district" type="text" value="<?= e($_POST['district'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="village_city">Village / City</label>
                        <input id="village_city" name="village_city" type="text" value="<?= e($_POST['village_city'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="pin">PIN Code</label>
                        <input id="pin" name="pin" type="text" maxlength="10" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\D/g,'')" value="<?= e($_POST['pin'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="state">State</label>
                        <select id="state" name="state">
                            <option value="">Select State</option>
                            <?php $states = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Andaman & Nicobar','Chandigarh','Dadra & Nagar Haveli','Daman & Diu','Delhi','Jammu & Kashmir','Ladakh','Lakshadweep','Puducherry']; ?>
                            <?php foreach ($states as $st): ?>
                                <option value="<?= e($st) ?>" <?= ($_POST['state'] ?? '') === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="country">Country</label>
                        <input id="country" name="country" type="text" value="<?= e($_POST['country'] ?? 'India') ?>">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Payment</h2>
                    <p>Select the payment method for this application.</p>
                </div>
                <div class="field-grid">
                    <div>
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method">
                            <option value="Offline" selected>Offline (Cash/Cheque)</option>
                        </select>
                    </div>
                </div>
            </section>

            <div class="action-row">
                <button type="submit" class="btn" style="padding:0.75rem 2.5rem;font-size:1rem;">
                    <span class="sidebar-icon">📋</span> Submit Application
                </button>
                <a href="application-intake.php" class="btn btn-soft" style="padding:0.75rem 1.5rem;">Reset Form</a>
            </div>
        </form>
    </main>
</div>
<script>
function previewPassport(input, previewId) {
    var box = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            box.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
</body>
</html>
