<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/bootstrap.php';
require_admin_login();

$certId = (int) ($_GET['id'] ?? 0);
if ($certId <= 0) {
    die('Invalid certificate ID.');
}

$stmt = $pdo->prepare("
    SELECT sc.*, s.admission_no, s.first_name, s.last_name, s.dob, s.gender, s.blood_group,
           e.class_name, e.section_name, e.session_label,
           g.name as guardian_name, g.relation_type as guardian_relation, g.phone as guardian_phone,
           g.father_name, g.mother_name
    FROM student_certificates sc
    JOIN students s ON s.id = sc.student_id
    LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
    LEFT JOIN guardians g ON g.student_id = s.id
    WHERE sc.id = ?
");
$stmt->execute([$certId]);
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cert) {
    die('Certificate not found.');
}

if (($cert['status'] ?? '') === 'Cancelled') {
    die('This certificate has been cancelled and cannot be printed.');
}

$payload = json_decode($cert['content_json'] ?? '{}', true) ?: [];
$studentName = $payload['student_name'] ?? ($cert['first_name'] . ' ' . $cert['last_name']);
$admNo = $payload['admission_no'] ?? $cert['admission_no'];
$className = $payload['class_name'] ?? ($cert['class_name'] ?: 'Class 1');
$sectionName = $payload['section_name'] ?? ($cert['section_name'] ?: 'A');
$guardianName = $payload['guardian_name'] ?? ($cert['guardian_name'] ?: 'Guardian');
$guardianRelation = $payload['guardian_relation'] ?? ($cert['guardian_relation'] ?: 'Parent / Guardian');
$dob = $payload['dob'] ?? ($cert['dob'] ?: '—');
$issueDate = date('d F, Y', strtotime($cert['issue_date']));
$reason = $payload['reason'] ?? ($cert['reason'] ?: 'On request of parents');
$certType = $cert['certificate_type'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($cert['certificate_no']) ?> – <?= e($certType) ?></title>
    <style>
        * { box-sizing: border-box; }
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f1f5f9;
            margin: 0;
            padding: 1.5rem;
            color: #1e293b;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .cert-container {
            width: 100%;
            max-width: 780px;
            margin: 0 auto;
            background: #fff;
            padding: 2rem 2.5rem;
            border: 8px double #1e3a8a;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .cert-header {
            text-align: center;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 0.65rem;
            margin-bottom: 0.85rem;
        }
        .school-logo {
            height: 62px;
            width: auto;
            margin-bottom: 0.25rem;
        }
        .school-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: 0.8px;
            margin: 0 0 0.15rem;
            text-transform: uppercase;
        }
        .school-tagline {
            font-size: 0.9rem;
            font-style: italic;
            color: #475569;
            margin-bottom: 0.15rem;
        }
        .school-address {
            font-size: 0.78rem;
            color: #64748b;
        }
        .cert-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 0.85rem;
            color: #334155;
        }
        .cert-badge-box {
            text-align: center;
            margin-bottom: 1.15rem;
        }
        .cert-badge {
            display: inline-block;
            font-size: 1.2rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 0.35rem 1.75rem;
            border: 2px solid #1e3a8a;
            background: #eff6ff;
            color: #1e3a8a;
            border-radius: 4px;
        }
        .cert-body {
            font-size: 1.02rem;
            line-height: 1.8;
            text-align: justify;
            margin-bottom: 1rem;
            flex-grow: 1;
        }
        .cert-body p {
            margin: 0 0 0.75rem 0;
            text-indent: 1.5rem;
        }
        .cert-body .field {
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1px dotted #334155;
            padding: 0 0.25rem;
        }
        .cert-signatures {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: auto;
            padding-top: 0.5rem;
        }
        .sig-block {
            min-width: 200px;
            padding-top: 1.75rem;
            border-top: 1px solid #94a3b8;
            font-weight: 700;
            font-size: 0.85rem;
            color: #334155;
            text-align: center;
        }
        .no-print-toolbar {
            max-width: 780px;
            margin: 0 auto 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-print {
            background: #1e3a8a;
            color: #fff;
            padding: 0.55rem 1.4rem;
            font-size: 0.92rem;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-print:hover { background: #1e40af; }
        .btn-back {
            background: #fff;
            color: #475569;
            padding: 0.55rem 1.15rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
        }
        @media print {
            html, body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                width: 100% !important;
                height: 100% !important;
                overflow: hidden !important;
            }
            .no-print-toolbar { display: none !important; }
            .cert-container {
                box-shadow: none !important;
                border: 6px double #1e3a8a !important;
                padding: 12mm 15mm 10mm 15mm !important;
                margin: 0 auto !important;
                width: 100% !important;
                max-width: 100% !important;
                height: 265mm !important;
                max-height: 265mm !important;
                box-sizing: border-box !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
                page-break-before: avoid !important;
                break-inside: avoid !important;
                overflow: hidden !important;
            }
            .cert-header {
                padding-bottom: 3mm !important;
                margin-bottom: 4mm !important;
            }
            .school-logo {
                height: 52px !important;
                margin-bottom: 2px !important;
            }
            .school-title {
                font-size: 1.55rem !important;
                margin-bottom: 2px !important;
            }
            .school-tagline {
                font-size: 0.82rem !important;
                margin-bottom: 2px !important;
            }
            .school-address {
                font-size: 0.72rem !important;
            }
            .cert-meta {
                margin-bottom: 4mm !important;
                font-size: 0.82rem !important;
            }
            .cert-badge-box {
                margin-bottom: 5mm !important;
            }
            .cert-badge {
                font-size: 1.1rem !important;
                padding: 3px 18px !important;
            }
            .cert-body {
                font-size: 0.96rem !important;
                line-height: 1.7 !important;
                margin-bottom: 4mm !important;
            }
            .cert-body p {
                margin-bottom: 3mm !important;
            }
            .cert-signatures {
                padding-top: 2mm !important;
            }
            .sig-block {
                min-width: 180px !important;
                padding-top: 1.25rem !important;
                font-size: 0.82rem !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print-toolbar">
    <a href="student-certificates.php" class="btn-back">← Back to Certificates</a>
    <button onclick="window.print()" class="btn-print">🖨 Print Certificate (1 Page A4)</button>
</div>

<div class="cert-container">
    <div class="cert-header">
        <img src="../../assets/images/logo.jpg" alt="SIBA Public School" class="school-logo" onerror="this.src='https://sibapublicschool.com/assets/images/logo.jpg'">
        <h1 class="school-title">SIBA Public School</h1>
        <div class="school-tagline">Nurturing Young Minds for a Brighter Future</div>
        <div class="school-address">Email: info@sibapublicschool.com | Website: sibapublicschool.com</div>
    </div>

    <div class="cert-meta">
        <div>Certificate No: <span style="font-family:monospace;"><?= e($cert['certificate_no']) ?></span></div>
        <div>Date of Issue: <?= e($issueDate) ?></div>
    </div>

    <div class="cert-badge-box">
        <div class="cert-badge"><?= e($certType) ?></div>
    </div>

    <div class="cert-body">
        <?php if ($certType === 'Transfer Certificate'): ?>
            <p>
                This is to certify that <span class="field"><?= e($studentName) ?></span>, 
                bearing Admission No. <span class="field"><?= e($admNo) ?></span>, 
                son / daughter of <span class="field"><?= e($guardianName) ?></span> (<?= e($guardianRelation) ?>), 
                was a bonafide student of this school admitted into <span class="field"><?= e($className) ?> <?= e($sectionName) ?></span>.
            </p>
            <p>
                According to the school admission register, the student's recorded Date of Birth is <span class="field"><?= e($dob) ?></span>.
                The student has paid all school dues and cleared all library, laboratory, and sports accounts up to date.
            </p>
            <p>
                The reason for leaving the institution is <span class="field"><?= e($reason) ?></span>.
                During the period of study, the student's conduct and character have been found to be <span class="field">Good</span>.
                We wish the student every success in all future academic pursuits.
            </p>

        <?php elseif ($certType === 'Bonafide Certificate'): ?>
            <p>
                This is to certify that <span class="field"><?= e($studentName) ?></span>, 
                bearing Admission No. <span class="field"><?= e($admNo) ?></span>, 
                son / daughter of <span class="field"><?= e($guardianName) ?></span>, 
                is a regular and bonafide student of <span class="field"><?= e($className) ?> <?= e($sectionName) ?></span> 
                at SIBA Public School for the academic session <span class="field"><?= e($cert['session_label'] ?? '2025-2026') ?></span>.
            </p>
            <p>
                According to our official admission register, the date of birth of the student is <span class="field"><?= e($dob) ?></span>.
                This certificate is issued upon the request of the parent / guardian for the purpose of <span class="field"><?= e($reason) ?></span>.
            </p>

        <?php elseif ($certType === 'Character Certificate'): ?>
            <p>
                This is to certify that <span class="field"><?= e($studentName) ?></span>, 
                bearing Admission No. <span class="field"><?= e($admNo) ?></span>, 
                son / daughter of <span class="field"><?= e($guardianName) ?></span>, 
                has been a student of this school in <span class="field"><?= e($className) ?></span>.
            </p>
            <p>
                To the best of our knowledge and belief, the student possesses a sound moral character, has maintained exemplary conduct and discipline, and has actively participated in school curricular and co-curricular activities.
            </p>

        <?php else: ?>
            <p>
                This is to certify that <span class="field"><?= e($studentName) ?></span>, 
                bearing Admission No. <span class="field"><?= e($admNo) ?></span>, 
                studying in / studied in <span class="field"><?= e($className) ?></span>, 
                has fulfilled all institutional requirements.
            </p>
            <p>
                All school fee dues, library books, laboratory materials, sports items, and hostel accounts stand fully reconciled and settled with <span class="field">No Dues Outstanding</span>.
            </p>
        <?php endif; ?>
    </div>

    <div class="cert-signatures">
        <div class="sig-block">
            Class Teacher
        </div>
        <div class="sig-block">
            <?= e($cert['approved_by'] ?: 'Principal') ?><br>
            <span style="font-size:.78rem;font-weight:normal;color:#64748b;">SIBA Public School</span>
        </div>
    </div>
</div>

</body>
</html>
