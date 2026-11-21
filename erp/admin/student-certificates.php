<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'student_lifecycle');

$pageTitle = 'Student Certificates & TC Generator';
$error = '';
$successMessage = '';
$printCertId = 0;

if (isset($_GET['success'])) {
    $sCode = (string) $_GET['success'];
    if ($sCode === 'issued') {
        $cNo = trim((string) ($_GET['cert_no'] ?? ''));
        $printCertId = (int) ($_GET['cert_id'] ?? 0);
        $successMessage = "Certificate <strong>" . e($cNo) . "</strong> generated successfully.";
    } elseif ($sCode === 'cancelled') {
        $successMessage = "Certificate cancelled successfully.";
    } elseif (str_contains($sCode, '<a href=') || str_contains($sCode, '&lt;a href=')) {
        if (preg_match('/id[=\']+(\d+)/', $sCode, $m)) {
            $printCertId = (int) $m[1];
        }
        $clean = html_entity_decode($sCode);
        $clean = strip_tags($clean);
        $clean = trim(str_ireplace('Click here to Print', '', $clean));
        $successMessage = e($clean);
    } else {
        $successMessage = e($sCode);
    }
}

// ── Ensure table exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_certificates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        certificate_no VARCHAR(50) NOT NULL UNIQUE,
        student_id BIGINT NOT NULL,
        certificate_type ENUM('Bonafide Certificate','Character Certificate','Transfer Certificate','Leaving Certificate','Attendance Certificate','Fee Certificate','No-Dues Certificate') NOT NULL,
        issue_date DATE NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        status ENUM('Draft','Approved','Issued','Cancelled') NOT NULL DEFAULT 'Issued',
        approved_by VARCHAR(100) DEFAULT 'Principal',
        content_json TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sc_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Helper function to generate Certificate No
function generate_certificate_no(PDO $pdo, string $type): string {
    $year = date('Y');
    $prefix = match($type) {
        'Transfer Certificate' => 'TC',
        'Bonafide Certificate' => 'BON',
        'Character Certificate' => 'CHAR',
        'Leaving Certificate' => 'LC',
        'No-Dues Certificate' => 'NODUE',
        default => 'CERT',
    };
    $count = (int) $pdo->query("SELECT COUNT(*) FROM student_certificates WHERE YEAR(issue_date) = {$year}")->fetchColumn() + 1;
    return 'SIBA/' . $year . '/' . $prefix . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

// Pre-selected student ID from query param
$preSelectedStudentId = (int) ($_GET['student_id'] ?? 0);

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'issue_certificate') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $certType = trim((string) ($_POST['certificate_type'] ?? ''));
            $issueDate = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $approvedBy = trim((string) ($_POST['approved_by'] ?? 'Principal'));

            if ($studentId <= 0 || $certType === '') {
                throw new \RuntimeException('Student and Certificate Type are required.');
            }

            // Fetch student & guardian details for JSON snapshot
            $stStmt = $pdo->prepare("
                SELECT s.*, e.class_name, e.section_name, e.session_label,
                       g.name as guardian_name, g.relation_type as guardian_relation
                FROM students s
                LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
                LEFT JOIN guardians g ON g.student_id = s.id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stStmt->execute([$studentId]);
            $st = $stStmt->fetch(PDO::FETCH_ASSOC);

            if (!$st) {
                throw new \RuntimeException('Student record not found.');
            }

            $certNo = generate_certificate_no($pdo, $certType);
            $payload = [
                'student_name' => $st['first_name'] . ' ' . $st['last_name'],
                'admission_no' => $st['admission_no'],
                'gender' => $st['gender'],
                'dob' => $st['dob'],
                'class_name' => $st['class_name'] ?? 'Class 1',
                'section_name' => $st['section_name'] ?? 'A',
                'session_label' => $st['session_label'] ?? '2025-2026',
                'guardian_name' => $st['guardian_name'] ?? '',
                'guardian_relation' => $st['guardian_relation'] ?? 'Guardian',
                'reason' => $reason,
                'conduct' => 'Good',
            ];

            $stmt = $pdo->prepare("INSERT INTO student_certificates 
                (certificate_no, student_id, certificate_type, issue_date, reason, status, approved_by, content_json) 
                VALUES (?, ?, ?, ?, ?, 'Issued', ?, ?)");
            $stmt->execute([$certNo, $studentId, $certType, $issueDate, $reason, $approvedBy, json_encode($payload)]);

            $newCertId = (int) $pdo->lastInsertId();
            header('Location: student-certificates.php?success=issued&cert_no=' . urlencode($certNo) . '&cert_id=' . $newCertId);
            exit;
        }

        if ($action === 'cancel_certificate') {
            $certId = (int) ($_POST['id'] ?? 0);
            if ($certId > 0) {
                $pdo->prepare("UPDATE student_certificates SET status = 'Cancelled' WHERE id = ?")->execute([$certId]);
                header('Location: student-certificates.php?success=cancelled');
                exit;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Search & Filter State ──
$searchQ = trim((string) ($_GET['q'] ?? ''));
$filterType = trim((string) ($_GET['type'] ?? ''));
$filterClass = trim((string) ($_GET['class'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterDate = trim((string) ($_GET['issue_date'] ?? ($_GET['date'] ?? '')));

// Available classes for dropdown filter
$classList = $pdo->query("SELECT DISTINCT class_name FROM student_enrollments WHERE class_name IS NOT NULL AND class_name <> '' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
if (empty($classList)) {
    $classList = ['Pre Nursery', 'Nursery', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8'];
}
usort($classList, 'strnatcasecmp');

$whereParts = ["1=1"];
$pParams = [];
if ($searchQ !== '') {
    $whereParts[] = "(sc.certificate_no LIKE :q OR s.admission_no LIKE :q OR s.first_name LIKE :q OR s.last_name LIKE :q)";
    $pParams['q'] = '%' . $searchQ . '%';
}
if ($filterType !== '') {
    $whereParts[] = "sc.certificate_type = :t";
    $pParams['t'] = $filterType;
}
if ($filterClass !== '') {
    $whereParts[] = "(e.class_name = :cls OR sc.content_json LIKE :cls_json)";
    $pParams['cls'] = $filterClass;
    $pParams['cls_json'] = '%"class_name":"' . $filterClass . '"%';
}
if ($filterStatus !== '') {
    $whereParts[] = "sc.status = :st";
    $pParams['st'] = $filterStatus;
}
if ($filterDate !== '') {
    $whereParts[] = "sc.issue_date = :dt";
    $pParams['dt'] = $filterDate;
}

$hasActiveFilter = ($searchQ !== '' || $filterType !== '' || $filterClass !== '' || $filterStatus !== '' || $filterDate !== '');

$whereStr = implode(' AND ', $whereParts);

$certificates = $pdo->prepare("
    SELECT sc.*, s.admission_no, s.first_name, s.last_name, e.class_name, e.section_name
    FROM student_certificates sc
    JOIN students s ON s.id = sc.student_id
    LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
    WHERE {$whereStr}
    ORDER BY sc.id DESC
    LIMIT 100
");
$certificates->execute($pParams);
$certList = $certificates->fetchAll(PDO::FETCH_ASSOC);

// Students for select dropdown
$allStudents = $pdo->query("
    SELECT s.id, s.admission_no, s.first_name, s.last_name, e.class_name 
    FROM students s 
    LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1 
    ORDER BY s.admission_no ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?php echo filemtime(__DIR__ . '/../assets/erp-ui.css'); ?>">
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.4rem">
                    <span class="eyebrow">Academic Lifecycle</span>
                    <h1>Student Certificates & TC Generator</h1>
                    <p>Issue and print official Transfer Certificates (TC), Bonafide, Character, and No-Dues Certificates with the official school logo.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="openModal('issueCertModal')">+ Issue New Certificate</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="flash" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;padding:.75rem 1.15rem;border-radius:8px;">
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;">
                    <span style="font-size:1.1rem;">✅</span>
                    <span><?= $successMessage ?></span>
                </div>
                <?php if ($printCertId > 0): ?>
                    <div>
                        <a href="certificate-print.php?id=<?= $printCertId ?>" target="_blank" class="btn btn-sm btn-primary" style="display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;">
                            🖨️ Print Certificate
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <form method="get" action="student-certificates.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:180px;">
                    <input type="text" name="q" value="<?= e($searchQ) ?>" placeholder="Search by Certificate No, Admission No, Student Name..." style="width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                </div>
                <div>
                    <select name="type" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Certificate Types</option>
                        <option value="Transfer Certificate" <?= $filterType === 'Transfer Certificate' ? 'selected' : '' ?>>Transfer Certificate (TC)</option>
                        <option value="Bonafide Certificate" <?= $filterType === 'Bonafide Certificate' ? 'selected' : '' ?>>Bonafide Certificate</option>
                        <option value="Character Certificate" <?= $filterType === 'Character Certificate' ? 'selected' : '' ?>>Character Certificate</option>
                        <option value="Leaving Certificate" <?= $filterType === 'Leaving Certificate' ? 'selected' : '' ?>>Leaving Certificate</option>
                        <option value="No-Dues Certificate" <?= $filterType === 'No-Dues Certificate' ? 'selected' : '' ?>>No-Dues Certificate</option>
                        <option value="Attendance Certificate" <?= $filterType === 'Attendance Certificate' ? 'selected' : '' ?>>Attendance Certificate</option>
                    </select>
                </div>
                <div>
                    <select name="class" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Classes</option>
                        <?php foreach ($classList as $c): ?>
                            <option value="<?= e($c) ?>" <?= $filterClass === (string)$c ? 'selected' : '' ?>>
                                <?= (is_numeric($c) || !str_starts_with(strtolower($c), 'class')) ? ('Class ' . e($c)) : e($c) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select name="status" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Statuses</option>
                        <option value="Issued" <?= $filterStatus === 'Issued' ? 'selected' : '' ?>>Issued</option>
                        <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Draft" <?= $filterStatus === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:.35rem;">
                    <label style="font-size:.8rem;color:#64748b;font-weight:600;white-space:nowrap;">Issue Date:</label>
                    <input type="date" name="issue_date" value="<?= e($filterDate) ?>" style="padding:.42rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                </div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <?php if ($hasActiveFilter): ?>
                    <a href="student-certificates.php" class="btn btn-sm btn-soft">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Certificates Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Issued Certificates Directory (<?= count($certList) ?>)</h3>
                <span style="font-size:.8rem;color:#64748b;">Official verifiable documents</span>
            </div>

            <?php if (empty($certList)): ?>
                <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    <div style="font-size:1.5rem;margin-bottom:.5rem;">📜</div>
                    No certificates issued yet. Click "<strong>+ Issue New Certificate</strong>" to create one.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Certificate No</th>
                                <th>Student Name</th>
                                <th>Adm No</th>
                                <th>Class</th>
                                <th>Certificate Type</th>
                                <th>Issue Date</th>
                                <th>Reason / Purpose</th>
                                <th>Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($certList as $c): ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($c['certificate_no']) ?></td>
                                    <td><strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?></strong></td>
                                    <td style="font-family:monospace;"><?= e($c['admission_no']) ?></td>
                                    <td><?= e($c['class_name'] ?: '—') ?></td>
                                    <td>
                                        <span class="badge" style="background:#e0e7ff;color:#3730a3;font-weight:600;"><?= e($c['certificate_type']) ?></span>
                                    </td>
                                    <td style="white-space:nowrap;"><?= e($c['issue_date']) ?></td>
                                    <td style="color:#64748b;font-size:.85rem;max-width:180px;"><?= e($c['reason'] ?: '—') ?></td>
                                    <td>
                                        <span class="badge" style="<?= $c['status'] === 'Issued' ? 'background:#d1fae5;color:#065f46;' : 'background:#fee2e2;color:#991b1b;' ?>">
                                            <?= e($c['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;gap:.35rem;justify-content:center;align-items:center;">
                                            <?php if ($c['status'] !== 'Cancelled'): ?>
                                                <a href="certificate-print.php?id=<?= (int)$c['id'] ?>" target="_blank" class="btn btn-sm btn-primary" title="Print Official Certificate">
                                                    🖨 Print
                                                </a>
                                                <?php if ($c['status'] === 'Issued' && ($canDelete || $isOwner)): ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Cancel certificate <?= e($c['certificate_no']) ?>?');">
                                                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="cancel_certificate">
                                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="Cancel">✕</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:.8rem;color:#94a3b8;font-weight:600;">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ ISSUE CERTIFICATE MODAL ═══════════════════ -->
<div id="issueCertModal" class="modal-backdrop <?= $preSelectedStudentId > 0 ? 'show' : '' ?>">
    <div class="modal-box" style="max-width:620px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;font-weight:700;">Issue Official Student Certificate</h2>
            <button type="button" class="icon-btn" onclick="closeModal('issueCertModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="issue_certificate">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Select Student *</label>
                <select name="student_id" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                    <option value="">-- Choose Student --</option>
                    <?php foreach ($allStudents as $st): ?>
                        <option value="<?= (int) $st['id'] ?>" <?= $preSelectedStudentId === (int)$st['id'] ? 'selected' : '' ?>>
                            <?= e($st['admission_no']) ?> – <?= e($st['first_name'] . ' ' . $st['last_name']) ?> (<?= e($st['class_name'] ?: 'Enrolled') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Certificate Type *</label>
                    <select name="certificate_type" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                        <option value="Transfer Certificate">Transfer Certificate (TC)</option>
                        <option value="Bonafide Certificate">Bonafide Certificate</option>
                        <option value="Character Certificate">Character Certificate</option>
                        <option value="Leaving Certificate">School Leaving Certificate</option>
                        <option value="No-Dues Certificate">No-Dues Clearance</option>
                        <option value="Attendance Certificate">Attendance Certificate</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Date of Issue *</label>
                    <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Purpose / Reason for Certificate</label>
                <input type="text" name="reason" placeholder="e.g. Higher Education admission, Father's job transfer, Passport application" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Issuing Authority</label>
                <input type="text" name="approved_by" value="Principal" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1.5rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('issueCertModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Generate Certificate</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal-backdrop').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });
});
</script>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
