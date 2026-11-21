<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$studentId = (int) ($_GET['student_id'] ?? 0);
$examTitle = trim((string) ($_GET['exam_title'] ?? 'Half Yearly'));

if ($studentId <= 0) {
    die('Invalid student ID.');
}

// Fetch student & enrollment details
$stStmt = $pdo->prepare("
    SELECT s.*, e.class_name, e.section_name, e.session_label,
           g.name as guardian_name, g.relation_type as guardian_relation,
           (SELECT COUNT(*) FROM attendance_records WHERE student_id = s.id AND status = 'present') as presents,
           (SELECT COUNT(*) FROM attendance_records WHERE student_id = s.id) as total_attendance
    FROM students s
    LEFT JOIN student_enrollments e ON e.student_id = s.id AND e.is_current = 1
    LEFT JOIN guardians g ON g.student_id = s.id
    WHERE s.id = ?
");
$stStmt->execute([$studentId]);
$student = $stStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Student record not found.');
}

// Fetch marks for this student and exam
$marksStmt = $pdo->prepare("
    SELECT er.*, sb.subject_code, sb.subject_name
    FROM exam_results er
    JOIN subjects sb ON sb.id = er.subject_id
    WHERE er.student_id = ? AND er.exam_name = ?
    ORDER BY sb.id ASC
");
$marksStmt->execute([$studentId, $examTitle]);
$results = $marksStmt->fetchAll(PDO::FETCH_ASSOC);

// If no marks found for this specific exam, fallback to any available marks for preview
if (empty($results)) {
    $marksStmt = $pdo->prepare("
        SELECT er.*, sb.subject_code, sb.subject_name
        FROM exam_results er
        JOIN subjects sb ON sb.id = er.subject_id
        WHERE er.student_id = ?
        ORDER BY sb.id ASC
        LIMIT 6
    ");
    $marksStmt->execute([$studentId]);
    $results = $marksStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$grandMax = 0.0;
$grandObtained = 0.0;
$isAllPass = true;

foreach ($results as $r) {
    $maxM = (float) $r['max_marks'];
    $obtM = (float) $r['obtained_marks'];
    $grandMax += $maxM;
    $grandObtained += $obtM;
    if ($obtM < ($maxM * 0.33)) {
        $isAllPass = false;
    }
}

$percentage = ($grandMax > 0) ? round(($grandObtained / $grandMax) * 100, 2) : 0.0;
$finalStatus = ($isAllPass && $percentage >= 33.0) ? 'PASS' : 'NEEDS IMPROVEMENT';

$totalAtt = (int) $student['total_attendance'];
$presents = (int) $student['presents'];
$attPct = ($totalAtt > 0) ? round(($presents / $totalAtt) * 100, 1) : 100.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($student['admission_no']) ?> – Marksheet (<?= e($examTitle) ?>)</title>
    <style>
        * { box-sizing: border-box; }
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
        body {
            font-family: 'Times New Roman', serif;
            background: #f1f5f9;
            margin: 0;
            padding: 2rem;
            color: #1e293b;
        }
        .report-card {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            padding: 2.5rem 3rem;
            border: 8px double #1e3a8a;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            position: relative;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        .school-logo {
            height: 70px;
            width: auto;
            margin-bottom: .4rem;
        }
        .school-name {
            font-size: 1.9rem;
            font-weight: 800;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 .2rem;
        }
        .sub-header {
            font-size: .95rem;
            color: #475569;
            margin-bottom: .2rem;
        }
        .report-title-box {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .report-title {
            display: inline-block;
            font-size: 1.25rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: .35rem 1.75rem;
            background: #eff6ff;
            color: #1e3a8a;
            border: 1px solid #93c5fd;
            border-radius: 4px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .5rem 1.5rem;
            font-size: .95rem;
            margin-bottom: 1.5rem;
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .info-row { display: flex; justify-content: space-between; }
        .info-label { color: #64748b; font-weight: 600; }
        .info-value { font-weight: 700; color: #0f172a; }
        table.marks-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .95rem;
            margin-bottom: 1.5rem;
        }
        table.marks-table th, table.marks-table td {
            border: 1px solid #cbd5e1;
            padding: .6rem .8rem;
        }
        table.marks-table th {
            background: #f1f5f9;
            color: #1e3a8a;
            font-weight: 700;
            text-align: left;
        }
        .total-row {
            background: #f8fafc;
            font-weight: 800;
        }
        .result-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 1rem 1.25rem;
            margin-bottom: 2.5rem;
            border-radius: 6px;
        }
        .result-badge {
            font-size: 1.2rem;
            font-weight: 800;
            padding: .3rem 1.25rem;
            border-radius: 4px;
        }
        .badge-pass { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .badge-fail { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .sig-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            text-align: center;
            margin-top: 3.5rem;
        }
        .sig-line {
            border-top: 1px solid #94a3b8;
            padding-top: .5rem;
            font-weight: 700;
            font-size: .9rem;
            color: #334155;
            margin: 0 1rem;
        }
        .seal-circle {
            width: 80px;
            height: 80px;
            border: 2px dashed #94a3b8;
            border-radius: 50%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem;
            color: #94a3b8;
            text-transform: uppercase;
        }
        .toolbar-print {
            max-width: 820px;
            margin: 0 auto 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-print {
            background: #1e3a8a;
            color: #fff;
            padding: .6rem 1.5rem;
            font-size: .95rem;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        @media print {
            html, body { background: #fff !important; padding: 0 !important; margin: 0 !important; overflow: hidden !important; }
            .toolbar-print { display: none !important; }
            .report-card {
                box-shadow: none !important;
                border-width: 5px !important;
                padding: 12mm 15mm 10mm 15mm !important;
                margin: 0 auto !important;
                width: 100% !important;
                max-width: 100% !important;
                height: 265mm !important;
                max-height: 265mm !important;
                box-sizing: border-box !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
                break-inside: avoid !important;
                overflow: hidden !important;
            }
        }
    </style>
</head>
<body>

<div class="toolbar-print">
    <a href="exam-marks.php" style="background:#fff;padding:.6rem 1rem;border:1px solid #cbd5e1;border-radius:6px;text-decoration:none;color:#475569;font-weight:600;">← Back to Marks Sheet</a>
    <button onclick="window.print()" class="btn-print">🖨 Print Marksheet</button>
</div>

<div class="report-card">
    <div class="header">
        <img src="../../assets/images/logo.jpg" alt="SIBA Public School Logo" class="school-logo" onerror="this.src='https://sibapublicschool.com/assets/images/logo.jpg'">
        <div class="school-name">SIBA Public School</div>
        <div class="sub-header">Nurturing Young Minds for a Brighter Future</div>
        <div style="font-size:.85rem;color:#64748b;">WBBSE Affiliated &bull; Chapra, Nadia, West Bengal</div>
    </div>

    <div class="report-title-box">
        <div class="report-title">Progress Report & Marksheet (<?= e($examTitle) ?>)</div>
    </div>

    <div class="info-grid">
        <div class="info-row">
            <span class="info-label">Student Name:</span>
            <span class="info-value"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Admission No:</span>
            <span class="info-value" style="font-family:monospace;"><?= e($student['admission_no']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Class & Section:</span>
            <span class="info-value"><?= e($student['class_name'] ?: 'Class 1') ?> <?= $student['section_name'] ? ('- ' . e($student['section_name'])) : '' ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Academic Session:</span>
            <span class="info-value"><?= e($student['session_label'] ?: '2025-2026') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Parent / Guardian:</span>
            <span class="info-value"><?= e($student['guardian_name'] ?: 'Guardian') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Attendance Record:</span>
            <span class="info-value"><?= $attPct ?>% (<?= $presents ?> / <?= $totalAtt ?> days)</span>
        </div>
    </div>

    <!-- Marks Table -->
    <table class="marks-table">
        <thead>
            <tr>
                <th>Subject Code</th>
                <th>Subject Name</th>
                <th style="text-align:right;">Max Marks</th>
                <th style="text-align:right;">Pass Marks</th>
                <th style="text-align:right;">Marks Obtained</th>
                <th style="text-align:center;">Result</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($results)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:2rem;color:#94a3b8;">
                        No examination marks recorded for this student in <?= e($examTitle) ?>.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($results as $res): 
                    $max = (float) $res['max_marks'];
                    $obt = (float) $res['obtained_marks'];
                    $pass = round($max * 0.33, 1);
                    $subPassed = ($obt >= $pass);
                ?>
                    <tr>
                        <td style="font-family:monospace;"><?= e($res['subject_code']) ?></td>
                        <td><strong><?= e($res['subject_name']) ?></strong></td>
                        <td style="text-align:right;"><?= number_format($max, 1) ?></td>
                        <td style="text-align:right;color:#64748b;"><?= number_format($pass, 1) ?></td>
                        <td style="text-align:right;font-weight:700;font-size:1.05rem;color:<?= $subPassed ? '#0f172a' : '#dc2626' ?>;">
                            <?= number_format($obt, 1) ?>
                        </td>
                        <td style="text-align:center;">
                            <span style="font-weight:700;color:<?= $subPassed ? '#16a34a' : '#dc2626' ?>;">
                                <?= $subPassed ? 'PASS' : 'FAIL' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="2">GRAND TOTAL</td>
                <td style="text-align:right;"><?= number_format($grandMax, 1) ?></td>
                <td style="text-align:right;">—</td>
                <td style="text-align:right;font-size:1.15rem;color:#1e3a8a;"><?= number_format($grandObtained, 1) ?></td>
                <td style="text-align:center;color:#1e3a8a;"><?= $percentage ?>%</td>
            </tr>
        </tfoot>
    </table>

    <!-- Result Summary Box -->
    <div class="result-box">
        <div>
            <div style="font-size:.85rem;color:#64748b;font-weight:600;">Overall Percentage</div>
            <div style="font-size:1.5rem;font-weight:800;color:#1e3a8a;"><?= $percentage ?>%</div>
        </div>
        <div>
            <div style="font-size:.85rem;color:#64748b;font-weight:600;margin-bottom:.2rem;">Final Evaluation Status</div>
            <div class="result-badge <?= $finalStatus === 'PASS' ? 'badge-pass' : 'badge-fail' ?>">
                <?= $finalStatus ?>
            </div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="sig-grid">
        <div class="sig-line">
            Class Teacher Signature
        </div>
        <div style="display:flex;align-items:center;justify-content:center;">
            <div class="seal-circle">
                School Seal
            </div>
        </div>
        <div class="sig-line">
            Principal Signature
        </div>
    </div>
</div>

</body>
</html>

