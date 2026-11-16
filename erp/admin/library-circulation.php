<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pageTitle = 'Library Circulation Counter (Issue & Return)';
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS library_issues (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        issue_no VARCHAR(50) NOT NULL UNIQUE,
        member_type ENUM('Student','Staff') NOT NULL DEFAULT 'Student',
        student_id BIGINT DEFAULT NULL,
        staff_id BIGINT UNSIGNED DEFAULT NULL,
        member_name VARCHAR(150) NOT NULL,
        book_id BIGINT UNSIGNED NOT NULL,
        copy_id BIGINT UNSIGNED NOT NULL,
        accession_no VARCHAR(60) NOT NULL,
        issue_date DATE NOT NULL,
        due_date DATE NOT NULL,
        return_date DATE DEFAULT NULL,
        renewal_count INT NOT NULL DEFAULT 0,
        issue_status ENUM('Issued','Returned','Overdue','Lost') NOT NULL DEFAULT 'Issued',
        issued_by VARCHAR(100) DEFAULT 'Librarian',
        returned_by VARCHAR(100) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_li_student (student_id),
        KEY idx_li_copy (copy_id),
        KEY idx_li_status (issue_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_returns_fines (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        issue_id BIGINT UNSIGNED NOT NULL,
        return_date DATE NOT NULL,
        days_overdue INT NOT NULL DEFAULT 0,
        fine_rate DECIMAL(8,2) NOT NULL DEFAULT 5.00,
        calculated_fine DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        damage_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        waived_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        final_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        waiver_reason VARCHAR(255) DEFAULT NULL,
        payment_status ENUM('Unpaid','Paid','Waived') NOT NULL DEFAULT 'Unpaid',
        collected_by VARCHAR(100) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lrf_issue (issue_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Pre-selected book ID from query
$preSelectedBookId = (int) ($_GET['book_id'] ?? 0);

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'issue_book') {
            $memberType = trim((string) ($_POST['member_type'] ?? 'Student'));
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $staffId = (int) ($_POST['staff_id'] ?? 0);
            $copyId = (int) ($_POST['copy_id'] ?? 0);
            $issueDate = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));
            $dueDate = trim((string) ($_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'))));

            if ($copyId <= 0) {
                throw new \RuntimeException('Please select a valid book copy.');
            }

            // Verify member
            $memberName = '';
            if ($memberType === 'Student') {
                if ($studentId <= 0) throw new \RuntimeException('Please select a student.');
                $st = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name, ' (', admission_no, ')') FROM students WHERE id = ?");
                $st->execute([$studentId]);
                $memberName = (string) $st->fetchColumn();
                $staffId = null;
            } else {
                if ($staffId <= 0) throw new \RuntimeException('Please select a staff member.');
                $st = $pdo->prepare("SELECT CONCAT(name, ' (', employee_code, ')') FROM employees WHERE id = ?");
                $st->execute([$staffId]);
                $memberName = (string) $st->fetchColumn();
                $studentId = null;
            }

            // Verify copy availability
            $cp = $pdo->prepare("SELECT c.*, b.title, b.id as master_book_id FROM library_book_copies c JOIN library_books b ON b.id = c.book_id WHERE c.id = ?");
            $cp->execute([$copyId]);
            $copyData = $cp->fetch(PDO::FETCH_ASSOC);
            if (!$copyData || $copyData['status'] !== 'Available') {
                throw new \RuntimeException('The selected book copy is not available for issue.');
            }

            $pdo->beginTransaction();

            $nextCount = (int) $pdo->query("SELECT COUNT(*) FROM library_issues")->fetchColumn() + 1;
            $issueNo = 'ISS-' . date('Y') . '-' . str_pad((string)$nextCount, 5, '0', STR_PAD_LEFT);

            // 1. Insert issue record
            $pdo->prepare("INSERT INTO library_issues 
                (issue_no, member_type, student_id, staff_id, member_name, book_id, copy_id, accession_no, issue_date, due_date, issue_status, issued_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Issued', ?)")
                ->execute([
                    $issueNo, $memberType, $studentId, $staffId, $memberName,
                    $copyData['master_book_id'], $copyId, $copyData['accession_no'],
                    $issueDate, $dueDate, $user['name'] ?? 'Librarian'
                ]);

            // 2. Mark copy as Issued
            $pdo->prepare("UPDATE library_book_copies SET status = 'Issued' WHERE id = ?")->execute([$copyId]);

            // 3. Decrement available copies in master
            $pdo->prepare("UPDATE library_books SET available_copies = GREATEST(available_copies - 1, 0), updated_at = NOW() WHERE id = ?")->execute([$copyData['master_book_id']]);

            $pdo->commit();
            $success = "Book '{$copyData['title']}' ({$copyData['accession_no']}) issued to {$memberName}. Due date: {$dueDate}.";
        }

        if ($action === 'return_book') {
            $issueId = (int) ($_POST['issue_id'] ?? 0);
            $damageCharge = max(0, (float) ($_POST['damage_charge'] ?? 0));
            $waiveAmount = max(0, (float) ($_POST['waived_amount'] ?? 0));
            $waiverReason = trim((string) ($_POST['waiver_reason'] ?? ''));

            if ($issueId <= 0) throw new \RuntimeException('Invalid issue ID.');

            $stmt = $pdo->prepare("SELECT * FROM library_issues WHERE id = ? AND issue_status = 'Issued'");
            $stmt->execute([$issueId]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$issue) throw new \RuntimeException('Active issue record not found.');

            $pdo->beginTransaction();

            $retDate = date('Y-m-d');
            $dueDate = $issue['due_date'];
            $daysOverdue = max(0, (int) ((strtotime($retDate) - strtotime($dueDate)) / 86400));
            $fineRate = 5.00;
            $overdueFine = $daysOverdue * $fineRate;
            $finalFine = max(0, ($overdueFine + $damageCharge) - $waiveAmount);

            // 1. Close issue
            $pdo->prepare("UPDATE library_issues SET issue_status = 'Returned', return_date = ?, returned_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$retDate, $user['name'] ?? 'Librarian', $issueId]);

            // 2. Mark copy Available
            $pdo->prepare("UPDATE library_book_copies SET status = 'Available' WHERE id = ?")->execute([$issue['copy_id']]);

            // 3. Increment master available_copies
            $pdo->prepare("UPDATE library_books SET available_copies = LEAST(available_copies + 1, total_copies), updated_at = NOW() WHERE id = ?")->execute([$issue['book_id']]);

            // 4. Log fine if applicable
            if ($daysOverdue > 0 || $damageCharge > 0) {
                $payStatus = ($finalFine == 0) ? 'Waived' : 'Unpaid';
                $pdo->prepare("INSERT INTO library_returns_fines 
                    (issue_id, return_date, days_overdue, fine_rate, calculated_fine, damage_charge, waived_amount, final_amount, waiver_reason, payment_status, collected_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $issueId, $retDate, $daysOverdue, $fineRate, $overdueFine,
                        $damageCharge, $waiveAmount, $finalFine, $waiverReason,
                        $payStatus, $user['name'] ?? 'Librarian'
                    ]);
            }

            $pdo->commit();
            $success = "Book returned successfully. " . ($daysOverdue > 0 ? "Days Overdue: {$daysOverdue} (Fine: ₹{$finalFine})." : "No overdue fine.");
        }

        if ($action === 'renew_book') {
            $issueId = (int) ($_POST['issue_id'] ?? 0);
            if ($issueId <= 0) throw new \RuntimeException('Invalid issue ID.');

            $stmt = $pdo->prepare("SELECT * FROM library_issues WHERE id = ? AND issue_status = 'Issued'");
            $stmt->execute([$issueId]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$issue) throw new \RuntimeException('Active issue record not found.');

            $newDueDate = date('Y-m-d', strtotime($issue['due_date'] . ' +14 days'));
            $pdo->prepare("UPDATE library_issues SET due_date = ?, renewal_count = renewal_count + 1, updated_at = NOW() WHERE id = ?")
                ->execute([$newDueDate, $issueId]);

            $success = "Loan renewed! New due date is {$newDueDate}.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: library-circulation.php?success=' . urlencode($success));
        exit;
    }
}

// ── Fetch Active Loans ──
$activeLoans = $pdo->query("
    SELECT li.*, b.title as book_title, b.author, b.rack_location,
           DATEDIFF(CURDATE(), li.due_date) as days_past_due
    FROM library_issues li
    JOIN library_books b ON b.id = li.book_id
    WHERE li.issue_status = 'Issued'
    ORDER BY li.due_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Available copies for dropdown
$availableCopies = $pdo->query("
    SELECT c.id, c.accession_no, c.book_id, b.title, b.author, c.rack_location
    FROM library_book_copies c
    JOIN library_books b ON b.id = c.book_id
    WHERE c.status = 'Available'
    ORDER BY b.title ASC, c.accession_no ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Students and Staff lists
$studentsList = $pdo->query("SELECT id, admission_no, first_name, last_name, (SELECT class_name FROM student_enrollments WHERE student_id = s.id AND is_current = 1 LIMIT 1) as class_name FROM students s ORDER BY admission_no ASC")->fetchAll(PDO::FETCH_ASSOC);
$staffList = $pdo->query("SELECT id, employee_code, name, department FROM employees WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Recent Completed Returns & Fines
$recentReturns = $pdo->query("
    SELECT li.*, b.title as book_title,
           f.days_overdue, f.final_amount, f.payment_status as fine_status
    FROM library_issues li
    JOIN library_books b ON b.id = li.book_id
    LEFT JOIN library_returns_fines f ON f.issue_id = li.id
    WHERE li.issue_status = 'Returned'
    ORDER BY li.return_date DESC, li.id DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Overdue count
$overdueCount = 0;
foreach ($activeLoans as $al) {
    if ((int)$al['days_past_due'] > 0) $overdueCount++;
}
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
                    <span class="eyebrow">Library Desk</span>
                    <h1>Circulation Counter (Issue & Return)</h1>
                    <p>Issue books to students or staff, manage active loans, renew borrowings, and process returns with auto-fines.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="openModal('issueBookModal')">📖 Issue Book to Member</button>
                    <a href="library-books.php" class="btn btn-sm btn-outline">📚 Book Catalog</a>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="kpi-grid" style="margin-bottom:1.25rem;">
            <div class="kpi-card">
                <div class="kpi-label">Active Book Loans</div>
                <div class="kpi-value" style="color:#2563eb;"><?= count($activeLoans) ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Currently checked out</div>
            </div>
            <div class="kpi-card" style="<?= $overdueCount > 0 ? 'border-left:4px solid #dc2626;' : '' ?>">
                <div class="kpi-label">Overdue Returns</div>
                <div class="kpi-value" style="<?= $overdueCount > 0 ? 'color:#dc2626;' : '' ?>"><?= $overdueCount ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Past grace due date</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Available Copies in Store</div>
                <div class="kpi-value" style="color:#16a34a;"><?= count($availableCopies) ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Ready for immediate checkout</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Fine Rate Policy</div>
                <div class="kpi-value">₹ 5.00</div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Per day for overdue books</div>
            </div>
        </div>

        <!-- Active Loans Table (Returns Desk) -->
        <div class="panel" style="overflow:auto;margin-bottom:1.5rem;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Active Borrowings / Return Desk (<?= count($activeLoans) ?>)</h3>
                <span style="font-size:.8rem;color:#64748b;">Sorted by due date</span>
            </div>

            <?php if (empty($activeLoans)): ?>
                <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    No books currently issued. All library copies are safely on the shelves!
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Issue No</th>
                                <th>Borrower (Member)</th>
                                <th>Book Title</th>
                                <th>Accession No</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th style="text-align:center;">Loan Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeLoans as $al): 
                                $daysOver = (int) $al['days_past_due'];
                                $isOverdue = ($daysOver > 0);
                            ?>
                                <tr style="<?= $isOverdue ? 'background:#fef2f2;' : '' ?>">
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($al['issue_no']) ?></td>
                                    <td>
                                        <strong><?= e($al['member_name']) ?></strong>
                                        <div style="font-size:.75rem;color:#64748b;"><?= e($al['member_type']) ?></div>
                                    </td>
                                    <td>
                                        <strong><?= e($al['book_title']) ?></strong>
                                        <div style="font-size:.75rem;color:#64748b;">By <?= e($al['author']) ?></div>
                                    </td>
                                    <td><span style="font-family:monospace;font-weight:600;"><?= e($al['accession_no']) ?></span></td>
                                    <td><?= e($al['issue_date']) ?></td>
                                    <td style="font-weight:700;color:<?= $isOverdue ? '#dc2626' : '#0f172a' ?>;">
                                        <?= e($al['due_date']) ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($isOverdue): ?>
                                            <span class="badge" style="background:#fee2e2;color:#991b1b;font-weight:800;">
                                                ⚠️ Overdue by <?= $daysOver ?> day(s)<br>(Fine: ₹<?= number_format($daysOver * 5, 2) ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#d1fae5;color:#065f46;">
                                                Active (<?= abs($daysOver) ?> days left)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;gap:.35rem;justify-content:center;">
                                            <button type="button" class="btn btn-sm btn-primary" onclick="openReturnModal(<?= (int)$al['id'] ?>, '<?= e(addslashes($al['book_title'])) ?>', '<?= e(addslashes($al['member_name'])) ?>', <?= $daysOver ?>)">
                                                📥 Return
                                            </button>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Renew loan for 14 more days?')">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="renew_book">
                                                <input type="hidden" name="issue_id" value="<?= (int) $al['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline" title="Renew Loan (+14 Days)">
                                                    🔄 (<?= $al['renewal_count'] ?>)
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Returns History Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Recent Completed Returns & Fines</h3>
                <span style="font-size:.8rem;color:#64748b;">Circulation audit</span>
            </div>
            <?php if (empty($recentReturns)): ?>
                <div style="padding:2rem;text-align:center;color:#94a3b8;">No returns recorded recently.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Return Date</th>
                                <th>Issue No</th>
                                <th>Member Name</th>
                                <th>Book Title</th>
                                <th>Accession No</th>
                                <th>Overdue Days</th>
                                <th>Fine Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentReturns as $rr): ?>
                                <tr>
                                    <td><?= e($rr['return_date'] ?: '—') ?></td>
                                    <td style="font-family:monospace;"><?= e($rr['issue_no']) ?></td>
                                    <td><strong><?= e($rr['member_name']) ?></strong></td>
                                    <td><?= e($rr['book_title']) ?></td>
                                    <td style="font-family:monospace;"><?= e($rr['accession_no']) ?></td>
                                    <td><?= (int)($rr['days_overdue'] ?? 0) ?></td>
                                    <td style="font-weight:700;">
                                        <?php if ((float)($rr['final_amount'] ?? 0) > 0): ?>
                                            ₹ <?= number_format((float)$rr['final_amount'], 2) ?>
                                        <?php else: ?>
                                            <span style="color:#16a34a;">₹ 0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:#d1fae5;color:#065f46;">Returned</span>
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

<!-- ═══════════════════ ISSUE BOOK MODAL ═══════════════════ -->
<div id="issueBookModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:620px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;font-weight:700;">Issue Library Book</h2>
            <button type="button" class="icon-btn" onclick="closeModal('issueBookModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="issue_book">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Member Type *</label>
                <div style="display:flex;gap:1.5rem;align-items:center;">
                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                        <input type="radio" name="member_type" value="Student" checked onchange="toggleMemberType('Student')">
                        <span>Student</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                        <input type="radio" name="member_type" value="Staff" onchange="toggleMemberType('Staff')">
                        <span>Staff Member / Teacher</span>
                    </label>
                </div>
            </div>

            <!-- Student Picker -->
            <div style="margin-bottom:1rem;" id="studentSelectRow">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Select Student *</label>
                <select name="student_id" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                    <option value="">-- Choose Student --</option>
                    <?php foreach ($studentsList as $st): ?>
                        <option value="<?= (int) $st['id'] ?>">
                            <?= e($st['admission_no']) ?> – <?= e($st['first_name'] . ' ' . $st['last_name']) ?> (<?= e($st['class_name'] ?: 'Enrolled') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Staff Picker -->
            <div style="margin-bottom:1rem;display:none;" id="staffSelectRow">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Select Staff Member *</label>
                <select name="staff_id" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                    <option value="">-- Choose Staff Member --</option>
                    <?php foreach ($staffList as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>">
                            <?= e($emp['employee_code']) ?> – <?= e($emp['name']) ?> (<?= e($emp['department']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Copy Picker -->
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Select Book Copy (Available Only) *</label>
                <select name="copy_id" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                    <option value="">-- Choose Available Copy --</option>
                    <?php foreach ($availableCopies as $ac): ?>
                        <option value="<?= (int) $ac['id'] ?>" <?= $preSelectedBookId === (int)$ac['book_id'] ? 'selected' : '' ?>>
                            <?= e($ac['accession_no']) ?>: <?= e($ac['title']) ?> (<?= e($ac['rack_location']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Date of Issue *</label>
                    <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Return Due Date *</label>
                    <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('issueBookModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Check Out Book</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ RETURN BOOK MODAL ═══════════════════ -->
<div id="returnBookModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:540px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="returnModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Process Book Return</h2>
            <button type="button" class="icon-btn" onclick="closeModal('returnBookModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="return_book">
            <input type="hidden" name="issue_id" id="returnIssueId" value="">

            <div id="returnSummaryBox" style="background:#f8fafc;padding:1rem;border-radius:8px;margin-bottom:1rem;font-size:.9rem;line-height:1.5;">
                <!-- Populated via JS -->
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Damage / Penalty (₹)</label>
                    <input type="number" step="0.01" name="damage_charge" value="0.00" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Waived Fine (₹)</label>
                    <input type="number" step="0.01" name="waived_amount" value="0.00" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Waiver Reason (if any)</label>
                <input type="text" name="waiver_reason" placeholder="e.g. Authorized by Principal / Medical leave" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('returnBookModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary">Confirm Book Return</button>
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
function toggleMemberType(type) {
    document.getElementById('studentSelectRow').style.display = (type === 'Student') ? 'block' : 'none';
    document.getElementById('staffSelectRow').style.display = (type === 'Staff') ? 'block' : 'none';
}
function openReturnModal(issueId, bookTitle, memberName, daysOverdue) {
    document.getElementById('returnIssueId').value = issueId;
    document.getElementById('returnModalTitle').textContent = 'Return Book: ' + bookTitle;
    var fine = Math.max(0, daysOverdue * 5.00);
    var box = document.getElementById('returnSummaryBox');
    box.innerHTML = `
        <div><strong>Borrower:</strong> ${memberName}</div>
        <div><strong>Book Title:</strong> ${bookTitle}</div>
        <div style="margin-top:.5rem;color:${daysOverdue > 0 ? '#dc2626' : '#16a34a'};font-weight:700;">
            ${daysOverdue > 0 ? ('⚠️ Overdue by ' + daysOverdue + ' day(s). Calculated Fine: ₹ ' + fine.toFixed(2)) : '✓ Returned on time! No overdue fine.'}
        </div>
    `;
    openModal('returnBookModal');
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
