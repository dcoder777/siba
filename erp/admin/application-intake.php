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

$classOptions = ['Nursery', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10'];
$genderOptions = ['Male', 'Female', 'Other'];
$bloodGroupOptions = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$incomeOptions = ['Below 1 Lakh', '1 - 2.5 Lakhs', '2.5 - 5 Lakhs', '5 - 10 Lakhs', 'Above 10 Lakhs'];

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
    $previousSchool = trim((string) ($_POST['previous_school'] ?? ''));
    $previousClass = trim((string) ($_POST['previous_class'] ?? ''));
    $fatherName = trim((string) ($_POST['father_name'] ?? ''));
    $fatherOccupation = trim((string) ($_POST['father_occupation'] ?? ''));
    $motherName = trim((string) ($_POST['mother_name'] ?? ''));
    $motherOccupation = trim((string) ($_POST['mother_occupation'] ?? ''));
    $guardianName = trim((string) ($_POST['guardian_name'] ?? ''));
    $guardianOccupation = trim((string) ($_POST['guardian_occupation'] ?? ''));
    $familyIncome = trim((string) ($_POST['family_annual_income'] ?? ''));
    $contactNo = trim((string) ($_POST['contact_no'] ?? ''));
    $studentEmail = trim((string) ($_POST['student_email'] ?? ''));
    $addressLine1 = trim((string) ($_POST['address_line1'] ?? ''));
    $addressLine2 = trim((string) ($_POST['address_line2'] ?? ''));
    $postOffice = trim((string) ($_POST['post_office'] ?? ''));
    $policeStation = trim((string) ($_POST['police_station'] ?? ''));
    $district = trim((string) ($_POST['district'] ?? ''));
    $villageCity = trim((string) ($_POST['village_city'] ?? ''));
    $pin = trim((string) ($_POST['pin'] ?? ''));
    $state = trim((string) ($_POST['state'] ?? ''));
    $country = trim((string) ($_POST['country'] ?? 'India'));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Online'));

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
            $uploadDir = __DIR__ . '/../../site/uploads/docs/';
            $birthCert = '';
            $aadhaarFile = '';
            $leavingCert = '';
            $prevMarksheet = '';
            $photo = '';
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

            // Generate application number
            $year = date('Y');
            $prefix = "SBA-{$year}-";
            $countStmt = $pdo->query("SELECT COUNT(*) AS c FROM applications WHERE application_no LIKE '{$prefix}%'");
            $appCount = (int) $countStmt->fetch()['c'];
            $appNo = $prefix . str_pad((string) ($appCount + 1), 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO applications (parent_id, application_no, student_name, first_name, middle_name, last_name, dob, gender, religion, blood_group, aadhaar_no, previous_school, previous_class, class_sought, address_line1, address_line2, post_office, police_station, district, village_city, pin, state, country, father_name, father_occupation, mother_name, mother_occupation, guardian_name, guardian_occupation, family_annual_income, contact_no, email, address, birth_cert, aadhaar, leaving_cert, prev_marksheet, payment_method, payment_status, status, applied_at) VALUES (:parent_id, :application_no, :student_name, :first_name, :middle_name, :last_name, :dob, :gender, :religion, :blood_group, :aadhaar_no, :previous_school, :previous_class, :class_sought, :address_line1, :address_line2, :post_office, :police_station, :district, :village_city, :pin, :state, :country, :father_name, :father_occupation, :mother_name, :mother_occupation, :guardian_name, :guardian_occupation, :family_annual_income, :contact_no, :email, :address, :birth_cert, :aadhaar, :leaving_cert, :prev_marksheet, :payment_method, :payment_status, 'Application started', NOW())");
            $stmt->execute([
                'parent_id' => $parentId, 'application_no' => $appNo, 'student_name' => $studentName, 'first_name' => $firstName,
                'middle_name' => $middleName ?: null, 'last_name' => $lastName ?: null, 'dob' => $dob,
                'gender' => $gender ?: null, 'religion' => $religion ?: null, 'blood_group' => $bloodGroup ?: null,
                'aadhaar_no' => $aadhaarNo ?: null, 'previous_school' => $previousSchool ?: null,
                'previous_class' => $previousClass ?: null, 'class_sought' => $classSought,
                'address_line1' => $addressLine1 ?: null, 'address_line2' => $addressLine2 ?: null,
                'post_office' => $postOffice ?: null, 'police_station' => $policeStation ?: null,
                'district' => $district ?: null, 'village_city' => $villageCity ?: null,
                'pin' => $pin ?: null, 'state' => $state ?: null, 'country' => $country ?: null,
                'father_name' => $fatherName, 'father_occupation' => $fatherOccupation ?: null,
                'mother_name' => $motherName, 'mother_occupation' => $motherOccupation ?: null,
                'guardian_name' => $guardianName ?: null, 'guardian_occupation' => $guardianOccupation ?: null,
                'family_annual_income' => $familyIncome ?: null, 'contact_no' => $contactNo ?: null,
                'email' => $studentEmail ?: null, 'address' => $combinedAddress ?: null,
                'birth_cert' => $birthCert ?: null, 'aadhaar' => $aadhaarFile ?: null,
                'leaving_cert' => $leavingCert ?: null, 'prev_marksheet' => $prevMarksheet ?: null,
                'payment_method' => $paymentMethod, 'payment_status' => $paymentMethod === 'Offline' ? 'Paid' : 'Pending',
            ]);

            $pdo->commit();

            $generatedPhone = $parentPhone;
            $generatedPassword = $parentPassword;

            $emailSent = false;
            $subject = 'Welcome to SIBA Public School – Your Parent Portal Credentials';
            $loginUrl = 'http://localhost/siba/site/parent/login.php';
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
    <title>Application Intake — SIBA ERP Admin</title>
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
            <div class="nav-title">Core</div>
            <a class="nav-link" href="index.php">
                <span class="sidebar-icon">◫</span><span>Main Dashboard</span><span class="nav-tag">Overview</span>
            </a>
            <?php if ($isSuperAdmin): ?>
                <a class="nav-link" href="index.php?view=user-access">
                    <span class="sidebar-icon">⚙</span><span>User Access</span><span class="nav-tag">Control</span>
                </a>
            <?php endif; ?>
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
        </div>
        <?php foreach ($menus as $menuKey => $menu): ?>
            <div class="nav-group">
                <div class="nav-title"><?= e((string) $menu['label']) ?></div>
                <a class="nav-link" href="index.php?view=module&amp;module=<?= e((string) $menuKey) ?>">
                    <span class="sidebar-icon">▣</span>
                    <span><?= e((string) $menu['label']) ?> Dashboard</span>
                    <span class="nav-tag"><?= count($menu['entities'] ?? []) ?> views</span>
                </a>
                <?php foreach (($menu['entities'] ?? []) as $menuEntity): ?>
                    <a class="nav-link" href="index.php?module=<?= e((string) $menuKey) ?>&amp;entity=<?= e((string) $menuEntity) ?>">
                        <span class="sidebar-icon">•</span>
                        <span><?= e((string) $entityMap[$menuEntity]['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="nav-group" style="margin-top:auto;">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Admissions</span>
                    <h1>Register a parent and submit an admission application on their behalf.</h1>
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
                    Portal: <a href="http://localhost/siba/site/parent/login.php">http://localhost/siba/site/parent/login.php</a><br>
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
                        <label for="parent_password">Password <span style="font-weight:400;color:var(--text-light)">(leave empty to auto-generate)</span></label>
                        <input id="parent_password" name="parent_password" type="text" value="<?= e($_POST['parent_password'] ?? '') ?>" placeholder="Auto-generated if left blank">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Student Admission Application</h2>
                    <p>Enter the student's details for the admission application.</p>
                </div>
                <div class="field-grid">
                    <div>
                        <label for="student_name">Student Full Name *</label>
                        <input id="student_name" name="student_name" type="text" required value="<?= e($_POST['student_name'] ?? '') ?>">
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
                    <div>
                        <label for="aadhaar_no">Aadhaar Number</label>
                        <input id="aadhaar_no" name="aadhaar_no" type="text" value="<?= e($_POST['aadhaar_no'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="previous_school">Previous School</label>
                        <input id="previous_school" name="previous_school" type="text" value="<?= e($_POST['previous_school'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="previous_class">Previous Class</label>
                        <input id="previous_class" name="previous_class" type="text" value="<?= e($_POST['previous_class'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Parent / Guardian Details</h2>
                </div>
                <div class="field-grid">
                    <div>
                        <label for="father_name">Father's Name *</label>
                        <input id="father_name" name="father_name" type="text" required value="<?= e($_POST['father_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="father_occupation">Father's Occupation</label>
                        <input id="father_occupation" name="father_occupation" type="text" value="<?= e($_POST['father_occupation'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="mother_name">Mother's Name *</label>
                        <input id="mother_name" name="mother_name" type="text" required value="<?= e($_POST['mother_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="mother_occupation">Mother's Occupation</label>
                        <input id="mother_occupation" name="mother_occupation" type="text" value="<?= e($_POST['mother_occupation'] ?? '') ?>">
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
                        <label for="contact_no">Contact Number</label>
                        <input id="contact_no" name="contact_no" type="tel" value="<?= e($_POST['contact_no'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="student_email">Student Email</label>
                        <input id="student_email" name="student_email" type="email" value="<?= e($_POST['student_email'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Address Details</h2>
                </div>
                <div class="field-grid">
                    <div class="full-col">
                        <label for="address_line1">Address Line 1</label>
                        <input id="address_line1" name="address_line1" type="text" value="<?= e($_POST['address_line1'] ?? '') ?>">
                    </div>
                    <div class="full-col">
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
                        <input id="pin" name="pin" type="text" maxlength="10" value="<?= e($_POST['pin'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="state">State</label>
                        <input id="state" name="state" type="text" value="<?= e($_POST['state'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="country">Country</label>
                        <input id="country" name="country" type="text" value="<?= e($_POST['country'] ?? 'India') ?>">
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.25rem">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>Document Uploads</h2>
                    <p>Accepted formats: JPG, PNG, PDF</p>
                </div>
                <div class="field-grid">
                    <div>
                        <label for="aadhaar_file">Aadhaar Card</label>
                        <input id="aadhaar_file" name="aadhaar_file" type="file" accept="image/*,application/pdf">
                    </div>
                    <div>
                        <label for="birth_cert">Birth Certificate</label>
                        <input id="birth_cert" name="birth_cert" type="file" accept="image/*,application/pdf">
                    </div>
                    <div>
                        <label for="leaving_cert">Previous School Leaving Certificate</label>
                        <input id="leaving_cert" name="leaving_cert" type="file" accept="image/*,application/pdf">
                    </div>
                    <div>
                        <label for="prev_marksheet">Previous Marksheet</label>
                        <input id="prev_marksheet" name="prev_marksheet" type="file" accept="image/*,application/pdf">
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
                            <option value="Online" <?= ($_POST['payment_method'] ?? 'Online') === 'Online' ? 'selected' : '' ?>>Online (Pending)</option>
                            <option value="Offline" <?= ($_POST['payment_method'] ?? '') === 'Offline' ? 'selected' : '' ?>>Offline (Paid – cash/cheque/dd)</option>
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
</body>
</html>
