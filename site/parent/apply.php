<?php
$pageTitle = "Admission Application";
require_once('../includes/db_connect.php');
include('../includes/portal_header.php');
include('../includes/portal_sidebar.php');

if ($hasApp) {
    header("Location: dashboard.php"); exit();
}

// Auto-migrate: add new columns to applications table (safe — skips if already exist)
$cols = $conn->query("SHOW COLUMNS FROM applications LIKE 'first_name'");
if ($cols->num_rows === 0) {
    $migrateCols = [
        "ADD COLUMN first_name VARCHAR(100) AFTER student_name",
        "ADD COLUMN middle_name VARCHAR(100) AFTER first_name",
        "ADD COLUMN last_name VARCHAR(100) AFTER middle_name",
        "ADD COLUMN gender VARCHAR(10) AFTER dob",
        "ADD COLUMN religion VARCHAR(50) AFTER gender",
        "ADD COLUMN blood_group VARCHAR(10) AFTER religion",
        "ADD COLUMN aadhaar_no VARCHAR(20) AFTER blood_group",
        "ADD COLUMN previous_school VARCHAR(200) AFTER aadhaar_no",
        "ADD COLUMN previous_class VARCHAR(20) AFTER previous_school",
        "ADD COLUMN address_line1 TEXT AFTER previous_class",
        "ADD COLUMN address_line2 TEXT AFTER address_line1",
        "ADD COLUMN post_office VARCHAR(100) AFTER address_line2",
        "ADD COLUMN police_station VARCHAR(100) AFTER post_office",
        "ADD COLUMN village_city VARCHAR(100) AFTER police_station",
        "ADD COLUMN father_occupation VARCHAR(100) AFTER father_name",
        "ADD COLUMN mother_occupation VARCHAR(100) AFTER mother_name",
        "ADD COLUMN guardian_occupation VARCHAR(100) AFTER guardian_name",
        "ADD COLUMN family_annual_income VARCHAR(50) AFTER guardian_occupation",
        "ADD COLUMN leaving_cert VARCHAR(255) AFTER aadhaar",
        "ADD COLUMN prev_marksheet VARCHAR(255) AFTER leaving_cert",
        "MODIFY COLUMN address TEXT NULL",
        "MODIFY COLUMN pin VARCHAR(10) NULL",
        "MODIFY COLUMN district VARCHAR(50) NULL",
        "MODIFY COLUMN state VARCHAR(50) NULL",
        "MODIFY COLUMN country VARCHAR(50) DEFAULT 'India'",
        "MODIFY COLUMN email VARCHAR(100) NULL",
        "MODIFY COLUMN contact_no VARCHAR(15) NULL",
        "MODIFY COLUMN photo VARCHAR(255) NULL",
        "MODIFY COLUMN birth_cert VARCHAR(255) NULL",
        "ADD COLUMN caste VARCHAR(20) AFTER photo",
        "ADD COLUMN disability VARCHAR(10) AFTER caste",
        "ADD COLUMN disability_details VARCHAR(255) AFTER disability",
        "ADD COLUMN caste_cert VARCHAR(255) AFTER disability_details",
        "ADD COLUMN father_photo VARCHAR(255) AFTER caste_cert",
        "ADD COLUMN mother_photo VARCHAR(255) AFTER father_photo",
        "ADD COLUMN father_aadhaar VARCHAR(255) AFTER mother_photo",
        "ADD COLUMN mother_aadhaar VARCHAR(255) AFTER father_aadhaar",
        "ADD COLUMN father_voter VARCHAR(255) AFTER mother_aadhaar",
        "ADD COLUMN mother_voter VARCHAR(255) AFTER father_voter",
        "ADD COLUMN disability_cert VARCHAR(255) AFTER mother_voter",
        "ADD COLUMN guardian_signature VARCHAR(255) AFTER disability_cert",
    ];
    foreach ($migrateCols as $stmt) {
        try {
            $conn->query("ALTER TABLE applications $stmt");
        } catch (\Throwable $e) {
            // skip if column already exists
        }
    }
}

// Ensure application_no and payment_status columns exist
$appNoCol = $conn->query("SHOW COLUMNS FROM applications LIKE 'application_no'");
if ($appNoCol->num_rows === 0) {
    $conn->query("ALTER TABLE applications ADD COLUMN application_no VARCHAR(30) NULL AFTER id");
}
$payStatusCol = $conn->query("SHOW COLUMNS FROM applications LIKE 'payment_status'");
if ($payStatusCol->num_rows === 0) {
    $conn->query("ALTER TABLE applications ADD COLUMN payment_status ENUM('Pending','Paid') DEFAULT 'Pending' AFTER application_no");
}

$error   = '';
$success = '';
$submittedApp = null;

// ---- Generate application number ----
function generateApplicationNo(mysqli $conn): string {
    $year = date('Y');
    $prefix = "SBA-{$year}-";
    $result = $conn->query("SELECT COUNT(*) AS c FROM applications WHERE application_no LIKE '{$prefix}%'");
    $count = $result ? (int) $result->fetch_assoc()['c'] : 0;
    return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
}

// ---- Handle form submission ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['submit_application']) || isset($_POST['submit_and_pay']))) {

    $first_name       = $conn->real_escape_string(trim($_POST['first_name']));
    $middle_name      = $conn->real_escape_string(trim($_POST['middle_name']));
    $last_name        = $conn->real_escape_string(trim($_POST['last_name']));
    $student_name     = trim("$first_name $middle_name $last_name");
    $dob              = $conn->real_escape_string($_POST['dob']);
    $gender           = $conn->real_escape_string($_POST['gender']);
    $religion         = $conn->real_escape_string(trim($_POST['religion']));
    $blood_group      = $conn->real_escape_string(trim($_POST['blood_group']));
    $aadhaar_no       = $conn->real_escape_string(trim($_POST['aadhaar_no']));
    $previous_school  = $conn->real_escape_string(trim($_POST['previous_school']));
    $previous_class   = $conn->real_escape_string(trim($_POST['previous_class']));
    $admission_class  = $conn->real_escape_string($_POST['admission_class']);
    $address_line1    = $conn->real_escape_string(trim($_POST['address_line1']));
    $address_line2    = $conn->real_escape_string(trim($_POST['address_line2']));
    $post_office      = $conn->real_escape_string(trim($_POST['post_office']));
    $police_station   = $conn->real_escape_string(trim($_POST['police_station']));
    $district         = $conn->real_escape_string(trim($_POST['district']));
    $village_city     = $conn->real_escape_string(trim($_POST['village_city']));
    $pin              = $conn->real_escape_string(trim($_POST['pin']));
    $state            = $conn->real_escape_string(trim($_POST['state']));
    $country          = $conn->real_escape_string(trim($_POST['country'] ?: 'India'));
    $father_name      = $conn->real_escape_string(trim($_POST['father_name']));
    $father_occup     = $conn->real_escape_string(trim($_POST['father_occupation']));
    $mother_name      = $conn->real_escape_string(trim($_POST['mother_name']));
    $mother_occup     = $conn->real_escape_string(trim($_POST['mother_occupation']));
    $guardian_name    = $conn->real_escape_string(trim($_POST['guardian_name']));
    $guardian_occup   = $conn->real_escape_string(trim($_POST['guardian_occupation']));
    $income           = $conn->real_escape_string(trim($_POST['family_annual_income']));
    $caste            = $conn->real_escape_string(trim($_POST['caste'] ?? ''));
    $disability       = $conn->real_escape_string(trim($_POST['disability'] ?? ''));
    $disability_det   = $conn->real_escape_string(trim($_POST['disability_details'] ?? ''));
    $terms            = isset($_POST['terms']) ? 1 : 0;

    if (empty($first_name)) {
        $error = "Please enter the child's first name.";
    } elseif (empty($dob)) {
        $error = "Please enter the date of birth.";
    } elseif (empty($gender)) {
        $error = "Please select the gender.";
    } elseif (empty($admission_class)) {
        $error = "Please select the class for admission.";
    } elseif (empty($father_name)) {
        $error = "Please enter the father's name.";
    } elseif (empty($mother_name)) {
        $error = "Please enter the mother's name.";
    } elseif (!$terms) {
        $error = "You must accept the Terms and Conditions to proceed.";
    } else {
        $upload_path = dirname(__DIR__) . '/uploads/docs/';
        $allowed     = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];

        $birth_cert   = '';
        $aadhaar_file = '';
        $leaving_cert = '';
        $prev_mark    = '';
        $photo_file   = '';
        $caste_cert   = '';
        $father_photo = '';
        $mother_photo = '';
        $father_aadhaar = '';
        $mother_aadhaar = '';
        $father_voter = '';
        $mother_voter = '';
        $disability_cert = '';
        $guardian_sig  = '';

        $fileUpload = function(string $field, string $prefix) use ($allowed, $upload_path, &$error): string {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] == 0) {
                if (in_array($_FILES[$field]['type'], $allowed)) {
                    $name = time() . '_' . $prefix . '_' . basename($_FILES[$field]['name']);
                    move_uploaded_file($_FILES[$field]['tmp_name'], $upload_path . $name);
                    return $name;
                } else {
                    $error = "$field must be JPG, PNG, or PDF.";
                }
            }
            return '';
        };

        // Birth certificate
        if (isset($_FILES['birth_cert']) && $_FILES['birth_cert']['error'] == 0) {
            if (in_array($_FILES['birth_cert']['type'], $allowed)) {
                $birth_cert = time() . '_bc_' . basename($_FILES['birth_cert']['name']);
                move_uploaded_file($_FILES['birth_cert']['tmp_name'], $upload_path . $birth_cert);
            } else {
                $error = "Birth certificate must be JPG, PNG, or PDF.";
            }
        }

        // Aadhaar card
        if (!$error && isset($_FILES['aadhaar_file']) && $_FILES['aadhaar_file']['error'] == 0) {
            if (in_array($_FILES['aadhaar_file']['type'], $allowed)) {
                $aadhaar_file = time() . '_aa_' . basename($_FILES['aadhaar_file']['name']);
                move_uploaded_file($_FILES['aadhaar_file']['tmp_name'], $upload_path . $aadhaar_file);
            } else {
                $error = "Aadhaar card must be JPG, PNG, or PDF.";
            }
        }

        // Leaving certificate
        if (!$error && isset($_FILES['leaving_cert']) && $_FILES['leaving_cert']['error'] == 0) {
            if (in_array($_FILES['leaving_cert']['type'], $allowed)) {
                $leaving_cert = time() . '_lc_' . basename($_FILES['leaving_cert']['name']);
                move_uploaded_file($_FILES['leaving_cert']['tmp_name'], $upload_path . $leaving_cert);
            } else {
                $error = "Leaving certificate must be JPG, PNG, or PDF.";
            }
        }

        // Previous marksheet
        if (!$error && isset($_FILES['prev_marksheet']) && $_FILES['prev_marksheet']['error'] == 0) {
            if (in_array($_FILES['prev_marksheet']['type'], $allowed)) {
                $prev_mark = time() . '_pm_' . basename($_FILES['prev_marksheet']['name']);
                move_uploaded_file($_FILES['prev_marksheet']['tmp_name'], $upload_path . $prev_mark);
            } else {
                $error = "Previous marksheet must be JPG, PNG, or PDF.";
            }
        }

        // Student photo
        if (!$error && isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            if (in_array($_FILES['photo']['type'], $allowed)) {
                $photo_file = time() . '_ph_' . basename($_FILES['photo']['name']);
                move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path . $photo_file);
            } else {
                $error = "Student photo must be JPG, PNG, or PDF.";
            }
        }

        // Caste certificate
        if (!$error && isset($_FILES['caste_cert']) && $_FILES['caste_cert']['error'] == 0) {
            if (in_array($_FILES['caste_cert']['type'], $allowed)) {
                $caste_cert = time() . '_cc_' . basename($_FILES['caste_cert']['name']);
                move_uploaded_file($_FILES['caste_cert']['tmp_name'], $upload_path . $caste_cert);
            } else {
                $error = "Caste certificate must be JPG, PNG, or PDF.";
            }
        }

        // Father photo
        if (!$error && isset($_FILES['father_photo']) && $_FILES['father_photo']['error'] == 0) {
            if (in_array($_FILES['father_photo']['type'], $allowed)) {
                $father_photo = time() . '_fp_' . basename($_FILES['father_photo']['name']);
                move_uploaded_file($_FILES['father_photo']['tmp_name'], $upload_path . $father_photo);
            } else {
                $error = "Father photo must be JPG, PNG, or PDF.";
            }
        }

        // Mother photo
        if (!$error && isset($_FILES['mother_photo']) && $_FILES['mother_photo']['error'] == 0) {
            if (in_array($_FILES['mother_photo']['type'], $allowed)) {
                $mother_photo = time() . '_mp_' . basename($_FILES['mother_photo']['name']);
                move_uploaded_file($_FILES['mother_photo']['tmp_name'], $upload_path . $mother_photo);
            } else {
                $error = "Mother photo must be JPG, PNG, or PDF.";
            }
        }

        // Father aadhaar
        if (!$error && isset($_FILES['father_aadhaar']) && $_FILES['father_aadhaar']['error'] == 0) {
            if (in_array($_FILES['father_aadhaar']['type'], $allowed)) {
                $father_aadhaar = time() . '_fa_' . basename($_FILES['father_aadhaar']['name']);
                move_uploaded_file($_FILES['father_aadhaar']['tmp_name'], $upload_path . $father_aadhaar);
            } else {
                $error = "Father Aadhaar must be JPG, PNG, or PDF.";
            }
        }

        // Mother aadhaar
        if (!$error && isset($_FILES['mother_aadhaar']) && $_FILES['mother_aadhaar']['error'] == 0) {
            if (in_array($_FILES['mother_aadhaar']['type'], $allowed)) {
                $mother_aadhaar = time() . '_ma_' . basename($_FILES['mother_aadhaar']['name']);
                move_uploaded_file($_FILES['mother_aadhaar']['tmp_name'], $upload_path . $mother_aadhaar);
            } else {
                $error = "Mother Aadhaar must be JPG, PNG, or PDF.";
            }
        }

        // Father voter card
        if (!$error && isset($_FILES['father_voter']) && $_FILES['father_voter']['error'] == 0) {
            if (in_array($_FILES['father_voter']['type'], $allowed)) {
                $father_voter = time() . '_fv_' . basename($_FILES['father_voter']['name']);
                move_uploaded_file($_FILES['father_voter']['tmp_name'], $upload_path . $father_voter);
            } else {
                $error = "Father voter card must be JPG, PNG, or PDF.";
            }
        }

        // Mother voter card
        if (!$error && isset($_FILES['mother_voter']) && $_FILES['mother_voter']['error'] == 0) {
            if (in_array($_FILES['mother_voter']['type'], $allowed)) {
                $mother_voter = time() . '_mv_' . basename($_FILES['mother_voter']['name']);
                move_uploaded_file($_FILES['mother_voter']['tmp_name'], $upload_path . $mother_voter);
            } else {
                $error = "Mother voter card must be JPG, PNG, or PDF.";
            }
        }

        // Disability certificate
        if (!$error && isset($_FILES['disability_cert']) && $_FILES['disability_cert']['error'] == 0) {
            if (in_array($_FILES['disability_cert']['type'], $allowed)) {
                $disability_cert = time() . '_dc_' . basename($_FILES['disability_cert']['name']);
                move_uploaded_file($_FILES['disability_cert']['tmp_name'], $upload_path . $disability_cert);
            } else {
                $error = "Disability certificate must be JPG, PNG, or PDF.";
            }
        }

        // Guardian signature
        if (!$error && isset($_FILES['guardian_signature']) && $_FILES['guardian_signature']['error'] == 0) {
            if (in_array($_FILES['guardian_signature']['type'], $allowed)) {
                $guardian_sig = time() . '_gs_' . basename($_FILES['guardian_signature']['name']);
                move_uploaded_file($_FILES['guardian_signature']['tmp_name'], $upload_path . $guardian_sig);
            } else {
                $error = "Guardian signature must be JPG, PNG, or PDF.";
            }
        }

        if (!$error) {
            $appNo = generateApplicationNo($conn);
            $payStatus = isset($_POST['submit_and_pay']) ? 'Pending' : 'Pending';

            $sql = "INSERT INTO applications SET
                parent_id = '$parent_id',
                application_no = '$appNo',
                payment_status = '$payStatus',
                first_name = '$first_name',
                middle_name = '$middle_name',
                last_name = '$last_name',
                student_name = '$student_name',
                dob = '$dob',
                gender = '$gender',
                religion = '$religion',
                blood_group = '$blood_group',
                aadhaar_no = '$aadhaar_no',
                previous_school = '$previous_school',
                previous_class = '$previous_class',
                class_sought = '$admission_class',
                address_line1 = '$address_line1',
                address_line2 = '$address_line2',
                post_office = '$post_office',
                police_station = '$police_station',
                district = '$district',
                village_city = '$village_city',
                pin = '$pin',
                state = '$state',
                country = '$country',
                father_name = '$father_name',
                father_occupation = '$father_occup',
                mother_name = '$mother_name',
                mother_occupation = '$mother_occup',
                guardian_name = '$guardian_name',
                guardian_occupation = '$guardian_occup',
                family_annual_income = '$income',
                caste = '$caste',
                disability = '$disability',
                disability_details = '$disability_det',
                birth_cert = '$birth_cert',
                aadhaar = '$aadhaar_file',
                leaving_cert = '$leaving_cert',
                prev_marksheet = '$prev_mark',
                photo = '$photo_file',
                caste_cert = '$caste_cert',
                father_photo = '$father_photo',
                mother_photo = '$mother_photo',
                father_aadhaar = '$father_aadhaar',
                mother_aadhaar = '$mother_aadhaar',
                father_voter = '$father_voter',
                mother_voter = '$mother_voter',
                disability_cert = '$disability_cert',
                guardian_signature = '$guardian_sig',
                status = 'Application started'";

            if ($conn->query($sql)) {
                $appId = $conn->insert_id;

                // Send email notification
                $parentData = $conn->query("SELECT name, email FROM parents WHERE id = $parent_id")->fetch_assoc();
                $parentEmail = $parentData ? $parentData['email'] : '';
                $parentName = $parentData ? $parentData['name'] : 'Parent';

                if ($parentEmail) {
                    $subject = "SIBA Public School – Application Submitted (#{$appNo})";
                    $loginUrl = SITE_URL . '/parent/login.php';
                    $receiptUrl = SITE_URL . "/parent/receipt.php?app_id={$appId}";
                    $message = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,sans-serif;padding:20px;color:#333;">
    <h2>Application Submitted Successfully</h2>
    <p>Dear {$parentName},</p>
    <p>Your admission application for <strong>{$student_name}</strong> has been received at SIBA Public School.</p>
    <table style="background:#f5f5f5;padding:15px;border-radius:8px;margin:15px 0;">
        <tr><td><strong>Application No:</strong></td><td>{$appNo}</td></tr>
        <tr><td><strong>Student Name:</strong></td><td>{$student_name}</td></tr>
        <tr><td><strong>Class Applied:</strong></td><td>{$admission_class}</td></tr>
        <tr><td><strong>Status:</strong></td><td>Application started</td></tr>
    </table>
    <p>Please keep your Application No. <strong>{$appNo}</strong> for future reference.</p>
    <p><a href="{$receiptUrl}" style="background:#1e293b;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;">Download Receipt</a></p>
    <p>To pay the application fee and complete the process, please visit your <a href="{$loginUrl}">Parent Portal Dashboard</a>.</p>
    <p>Best regards,<br>SIBA Public School Administration</p>
</body>
</html>
HTML;
                    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: noreply@sibapublicschool.com\r\n";
                    @mail($parentEmail, $subject, $message, $headers);
                }

                if (isset($_POST['submit_and_pay'])) {
                    header("Location: pay-fees.php?app_id=$appId");
                    exit();
                }

                $submittedApp = [
                    'id' => $appId,
                    'app_no' => $appNo,
                    'student_name' => $student_name,
                    'class' => $admission_class,
                ];
                $success = "Application submitted successfully! Your Application No. is <strong>{$appNo}</strong>.";
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}
?>

<style>
.section-card {
    background: #fff;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.section-card .section-head {
    background: var(--primary-color);
    color: #fff;
    padding: 0.85rem 1.5rem;
    font-weight: 700;
    font-size: 1.05rem;
}
.section-card .section-head i { margin-right: 0.5rem; }
.section-card .section-body { padding: 1.5rem; }
</style>

<div class="portal-header">
    <div class="portal-header-title">
        <h2><i class="fas fa-file-medical-alt"></i> &nbsp;Online Admission Form</h2>
        <p>Fill out all required details to apply for admission to SIBA Public School.</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

    <?php if ($submittedApp): ?>
        <div class="section-card" style="border:2px solid #22c55e;">
            <div class="section-head" style="background:#22c55e;"><i class="fas fa-check-circle"></i> Application Submitted Successfully</div>
            <div class="section-body" style="text-align:center;padding:2rem;">
                <div style="font-size:4rem;color:#22c55e;margin-bottom:1rem;"><i class="fas fa-check-circle"></i></div>
                <h3 style="margin-bottom:0.5rem;">Application No: <strong style="color:#1e293b;font-size:1.4rem;"><?= $submittedApp['app_no'] ?></strong></h3>
                <p style="color:#64748b;margin-bottom:0.25rem;">Student: <?= htmlspecialchars($submittedApp['student_name']) ?> | Class: <?= htmlspecialchars($submittedApp['class']) ?></p>
                <p style="color:#64748b;margin-bottom:1.5rem;">Please quote this Application No. in all correspondence with the school.</p>
                <p style="color:#dc2626;font-weight:600;margin-bottom:1.5rem;">Note: Please contact the school to complete the admission process and make the payment.</p>
                <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
                    <a href="receipt.php?app_id=<?= $submittedApp['id'] ?>" class="btn btn-primary" target="_blank"><i class="fas fa-download"></i> Download Receipt</a>
                    <a href="dashboard.php" class="btn btn-outline-primary"><i class="fas fa-tachometer-alt"></i> Go to Dashboard</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$submittedApp): ?>
    <form method="POST" enctype="multipart/form-data" id="applyForm">

    <!-- ===== SECTION 1: STUDENT DETAILS ===== -->
    <div class="section-card">
        <div class="section-head"><i class="fas fa-child"></i> Student Information</div>
        <div class="section-body">
            <div class="form-row">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Date of Birth *</label>
                    <input type="date" name="dob" required value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Gender *</label>
                    <select name="gender" required>
                        <option value="">Select Gender</option>
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                            <option value="<?php echo $g; ?>" <?php echo (($_POST['gender'] ?? '') == $g) ? 'selected' : ''; ?>><?php echo $g; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Religion</label>
                    <input type="text" name="religion" value="<?php echo htmlspecialchars($_POST['religion'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Blood Group</label>
                    <select name="blood_group">
                        <option value="">Select</option>
                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                            <option value="<?php echo $bg; ?>" <?php echo (($_POST['blood_group'] ?? '') == $bg) ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Aadhaar No.</label>
                    <input type="text" name="aadhaar_no" placeholder="12-digit Aadhaar" maxlength="12" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\D/g,'')" value="<?php echo htmlspecialchars($_POST['aadhaar_no'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Caste</label>
                    <select name="caste">
                        <option value="">Select Caste</option>
                        <?php foreach (['General','OBC','SC','ST','Other'] as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo (($_POST['caste'] ?? '') == $c) ? 'selected' : ''; ?>><?php echo $c; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Disability</label>
                    <select name="disability" id="disabilitySelect" onchange="document.getElementById('disabilityDetails').style.display=this.value==='Yes'?'block':'none'">
                        <option value="">Select</option>
                        <option value="No" <?php echo (($_POST['disability'] ?? '') == 'No') ? 'selected' : ''; ?>>No</option>
                        <option value="Yes" <?php echo (($_POST['disability'] ?? '') == 'Yes') ? 'selected' : ''; ?>>Yes</option>
                    </select>
                </div>
            </div>
            <div class="form-row" id="disabilityDetails" style="display:<?php echo (($_POST['disability'] ?? '') == 'Yes') ? 'block' : 'none'; ?>">
                <div class="form-group">
                    <label>Disability Details</label>
                    <textarea name="disability_details" rows="2" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;"><?php echo htmlspecialchars($_POST['disability_details'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Previous School</label>
                    <input type="text" name="previous_school" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\D/g,'')" value="<?php echo htmlspecialchars($_POST['previous_school'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Previous Class</label>
                    <input type="text" name="previous_class" placeholder="e.g. 5" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\D/g,'')" value="<?php echo htmlspecialchars($_POST['previous_class'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Admission Class *</label>
                    <select name="admission_class" required>
                        <option value="">Select Class</option>
                        <?php foreach (range(1, 8) as $cls): ?>
                            <option value="Class <?php echo $cls; ?>" <?php echo (($_POST['admission_class'] ?? '') == "Class $cls") ? 'selected' : ''; ?>>Class <?php echo $cls; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== SECTION 2: ADDRESS ===== -->
    <div class="section-card">
        <div class="section-head"><i class="fas fa-map-marker-alt"></i> Address Details</div>
        <div class="section-body">
            <div class="form-row">
                <div class="form-group">
                    <label>Address Line 1 *</label>
                    <input type="text" name="address_line1" placeholder="House/Flat No., Street" required value="<?php echo htmlspecialchars($_POST['address_line1'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Address Line 2</label>
                    <input type="text" name="address_line2" placeholder="Locality, Area" value="<?php echo htmlspecialchars($_POST['address_line2'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Post Office</label>
                    <input type="text" name="post_office" value="<?php echo htmlspecialchars($_POST['post_office'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Police Station</label>
                    <input type="text" name="police_station" value="<?php echo htmlspecialchars($_POST['police_station'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>District *</label>
                    <input type="text" name="district" required value="<?php echo htmlspecialchars($_POST['district'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Village / City *</label>
                    <input type="text" name="village_city" required value="<?php echo htmlspecialchars($_POST['village_city'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>PIN Code *</label>
                    <input type="text" name="pin" placeholder="6-digit PIN" maxlength="6" required inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/\D/g,'')" value="<?php echo htmlspecialchars($_POST['pin'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>State *</label>
                    <select name="state" required>
                        <option value="">Select State</option>
                        <?php
                        $states = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Andaman & Nicobar','Chandigarh','Dadra & Nagar Haveli','Daman & Diu','Delhi','Jammu & Kashmir','Ladakh','Lakshadweep','Puducherry'];
                        foreach ($states as $st): ?>
                            <option value="<?php echo $st; ?>" <?php echo (($_POST['state'] ?? '') === $st) ? 'selected' : ''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Country *</label>
                    <input type="text" name="country" value="<?php echo htmlspecialchars($_POST['country'] ?? 'India'); ?>" required>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== SECTION 3: PARENT / GUARDIAN ===== -->
    <div class="section-card">
        <div class="section-head"><i class="fas fa-users"></i> Parent / Guardian Details</div>
        <div class="section-body">
            <div class="form-row">
                <div class="form-group">
                    <label>Father's Name *</label>
                    <input type="text" name="father_name" required value="<?php echo htmlspecialchars($_POST['father_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Father's Occupation</label>
                    <input type="text" name="father_occupation" value="<?php echo htmlspecialchars($_POST['father_occupation'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Mother's Name *</label>
                    <input type="text" name="mother_name" required value="<?php echo htmlspecialchars($_POST['mother_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Mother's Occupation</label>
                    <input type="text" name="mother_occupation" value="<?php echo htmlspecialchars($_POST['mother_occupation'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Guardian's Name</label>
                    <input type="text" name="guardian_name" value="<?php echo htmlspecialchars($_POST['guardian_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Guardian's Occupation</label>
                    <input type="text" name="guardian_occupation" value="<?php echo htmlspecialchars($_POST['guardian_occupation'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Family Annual Income</label>
                <select name="family_annual_income">
                    <option value="">Select</option>
                    <?php
                    $incomes = [
                        'Below ₹1,00,000',
                        '₹1,00,001 – ₹2,50,000',
                        '₹2,50,001 – ₹5,00,000',
                        '₹5,00,001 – ₹10,00,000',
                        'Above ₹10,00,000',
                    ];
                    foreach ($incomes as $inc):
                    ?>
                        <option value="<?php echo $inc; ?>" <?php echo (($_POST['family_annual_income'] ?? '') == $inc) ? 'selected' : ''; ?>><?php echo $inc; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ===== SECTION 4: DOCUMENT UPLOADS ===== -->
    <div class="section-card">
        <div class="section-head"><i class="fas fa-paperclip"></i> Required Documents</div>
        <div class="section-body">
            <p style="margin-bottom:1rem;color:var(--text-light);font-size:0.9rem;">Accepted formats: JPG, PNG, PDF. Files marked * are recommended.</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Student Aadhaar Card Copy *</label>
                    <input type="file" name="aadhaar_file" accept="image/*,application/pdf">
                </div>
                <div class="form-group">
                    <label>Student Birth Certificate Copy *</label>
                    <input type="file" name="birth_cert" accept="image/*,application/pdf">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Previous School TC/LC Copy</label>
                    <input type="file" name="leaving_cert" accept="image/*,application/pdf">
                </div>
                <div class="form-group">
                    <label>Previous Class Marksheet Copy</label>
                    <input type="file" name="prev_marksheet" accept="image/*,application/pdf">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Passport Size Photo of Student *</label>
                    <input type="file" name="photo" accept="image/*,application/pdf">
                </div>
                <div class="form-group">
                    <label>Caste Certificate (if any)</label>
                    <input type="file" name="caste_cert" accept="image/*,application/pdf">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Passport Size Photo of Father *</label>
                    <input type="file" name="father_photo" accept="image/*,application/pdf">
                </div>
                <div class="form-group">
                    <label>Passport Size Photo of Mother *</label>
                    <input type="file" name="mother_photo" accept="image/*,application/pdf">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Father Aadhaar Copy *</label>
                    <input type="file" name="father_aadhaar" accept="image/*,application/pdf">
                </div>
                <div class="form-group">
                    <label>Mother Aadhaar Copy *</label>
                    <input type="file" name="mother_aadhaar" accept="image/*,application/pdf">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Father Voter Card Copy *</label>
                    <input type="file" name="father_voter" accept="image/*,application/pdf">
                </div>
                <div class="form-group">
                    <label>Mother Voter Card Copy *</label>
                    <input type="file" name="mother_voter" accept="image/*,application/pdf">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Disability Certificate (if any)</label>
                    <input type="file" name="disability_cert" accept="image/*,application/pdf">
                </div>
                <div class="form-group">
                    <label>Guardian Signature *</label>
                    <input type="file" name="guardian_signature" accept="image/*,application/pdf">
                </div>
            </div>
        </div>
    </div>

    <!-- ===== SECTION 5: TERMS ===== -->
    <div class="section-card">
        <div class="section-body" style="padding:1rem 1.5rem;">
            <div style="display: flex; align-items: flex-start; gap: 0.85rem;">
                <input type="checkbox" name="terms" id="termsCheck" style="width: auto; margin-top: 3px; width: 18px; height: 18px; cursor: pointer;">
                <label for="termsCheck" style="font-weight: 500; cursor: pointer;">
                    I have read and agree to the
                    <a href="#" id="showTermsBtn" style="color: var(--secondary-color); font-weight: 600;">Terms and Conditions</a>
                    of SIBA Public School.
                </label>
            </div>
        </div>
    </div>

    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <button type="submit" name="submit_application" class="btn btn-primary btn-lg">
            <i class="fas fa-paper-plane"></i> Submit Application
        </button>
        <p style="font-size: 0.82rem; color: var(--text-light);">Submit your application now.</p>
    </div>

</form>
<?php endif; ?>

</div><!-- /.portal-content -->
</div><!-- /.portal-wrapper -->

<!-- Terms Modal -->
<div class="modal-overlay" id="termsModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-file-contract"></i> &nbsp;Terms and Conditions</h3>
            <button class="modal-close" id="closeTerms"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <p>Please read these terms carefully before submitting your application to SIBA Public School.</p>
            <ol>
                <li><strong>Accuracy:</strong> All information provided in this application must be accurate and truthful. Any misrepresentation may lead to cancellation of admission.</li>
                <li><strong>Documents:</strong> Uploaded documents must be valid and clearly legible. SIBA reserves the right to verify originals.</li>
                <li><strong>Admission Process:</strong> Submission of this application does not guarantee admission. The school may conduct an entrance test or interview.</li>
                <li><strong>Fees:</strong> Fees paid are non-refundable once the academic session commences.</li>
                <li><strong>Communication:</strong> By registering, you consent to receiving SMS, WhatsApp, and email communications from SIBA Public School.</li>
                <li><strong>Privacy:</strong> Your personal data will be used solely for admission and school communication purposes and will not be shared with third parties.</li>
                <li><strong>Conduct:</strong> Students are expected to follow the school's code of conduct. Violation may result in disciplinary action.</li>
            </ol>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline-primary" id="declineTerms">Decline</button>
            <button class="btn btn-primary" id="acceptTerms"><i class="fas fa-check"></i> Accept & Continue</button>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#showTermsBtn').on('click', function(e){
        e.preventDefault();
        $('#termsModal').addClass('active');
    });
    $('#acceptTerms').on('click', function(){
        $('#termsCheck').prop('checked', true);
        $('#termsModal').removeClass('active');
    });
    $('#declineTerms, #closeTerms').on('click', function(){
        $('#termsCheck').prop('checked', false);
        $('#termsModal').removeClass('active');
    });
});
</script>

<?php include('../includes/portal_footer.php'); ?>
