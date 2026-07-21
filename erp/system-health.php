<?php

declare(strict_types=1);

use core\Database;

$config = require __DIR__ . '/bootstrap.php';
$pdo = Database::connection($config['db']);

function countOf(PDO $pdo, string $table, string $where = ''): int
{
    $sql = "SELECT COUNT(*) AS c FROM {$table}" . ($where !== '' ? " WHERE {$where}" : '');
    $row = $pdo->query($sql)->fetch();
    return (int) ($row['c'] ?? 0);
}

$stats = [
    'Students' => countOf($pdo, 'students'),
    'Employees' => countOf($pdo, 'employees'),
    'Subjects' => countOf($pdo, 'subjects'),
    'Assignments' => countOf($pdo, 'assignments'),
    'Payments' => countOf($pdo, 'payments'),
    'Pending Leaves' => countOf($pdo, 'leave_requests', 'status = "pending"'),
];

$recentStudents = $pdo->query(
    'SELECT admission_no, first_name, last_name, created_at
     FROM students
     ORDER BY id DESC
     LIMIT 8'
)->fetchAll();

$recentPayments = $pdo->query(
    'SELECT p.amount, p.payment_date, p.payment_mode, p.source, s.admission_no
     FROM payments p
     JOIN students s ON s.id = p.student_id
     ORDER BY p.id DESC
     LIMIT 8'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SIBA ERP System Health</title>
    <link rel="stylesheet" href="./assets/erp-ui.css">
</head>
<body class="page-shell">
<div class="content-wrap" style="padding:1.5rem">
    <div style="max-width:1280px;margin:0 auto" class="stack">
        <section class="hero-banner">
            <div class="hero-banner-grid">
                <div class="hero-copy stack" style="gap:.8rem">
                    <span class="eyebrow">System Overview</span>
                    <h1>Operational health with live records at a glance.</h1>
                    <p>
                        This page gives administrators a quick read on platform readiness, core entity volume,
                        and the most recent activity flowing through the ERP.
                    </p>
                    <div class="hero-actions">
                        <a class="btn" href="./admin/login.php">Admin Login</a>
                        <a class="btn btn-outline" href="./admin/index.php">Admin Dashboard</a>
                    </div>
                </div>
                <div class="feature-list">
                    <div class="feature-item">
                        <span class="badge">Status</span>
                        <h3 style="margin-top:.5rem">Healthy</h3>
                        <p>Database-backed records are available and the ERP is ready for use.</p>
                    </div>
                    <div class="feature-item">
                        <span class="badge">Scope</span>
                        <h3 style="margin-top:.5rem">Students to Payroll</h3>
                        <p>Academic, financial, operational, and HR records are all represented here.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel" style="padding:1.25rem">
            <div class="section-title">
                <div>
                    <h2>Core Record Volume</h2>
                    <p>Quick totals to confirm your data is loaded and the system is active.</p>
                </div>
                <span class="status-pill">Healthy</span>
            </div>
            <div class="kpi-grid">
                <?php foreach ($stats as $label => $value): ?>
                    <div class="kpi-card">
                        <div class="kpi-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="kpi-value"><?= (int) $value ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="split-grid">
            <div class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Recent Students</h2>
                        <p>Newest student records entering the ERP.</p>
                    </div>
                </div>
                <div class="data-table-wrap">
                    <table>
                        <thead><tr><th>Admission No</th><th>Name</th><th>Created At</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentStudents as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $row['admission_no'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($row['first_name'] . ' ' . $row['last_name']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel" style="padding:1.25rem">
                <div class="section-title">
                    <div>
                        <h2>Recent Payments</h2>
                        <p>Latest financial activity captured in the system.</p>
                    </div>
                </div>
                <div class="data-table-wrap">
                    <table>
                        <thead><tr><th>Admission No</th><th>Amount</th><th>Date</th><th>Mode</th><th>Source</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentPayments as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $row['admission_no'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>&#8377;<?= number_format((float) $row['amount'], 2) ?></td>
                                <td><?= htmlspecialchars((string) $row['payment_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['payment_mode'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['source'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
</body>
</html>
