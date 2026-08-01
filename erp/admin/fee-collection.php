<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();
$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];
$error = '';
$success = '';

// ─── Auto-migrate finance tables if missing ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(50) UNIQUE NOT NULL,
        student_id INT,
        student_name VARCHAR(200),
        class_name VARCHAR(50),
        academic_session VARCHAR(20),
        total_amount DECIMAL(12,2) NOT NULL,
        discount_amount DECIMAL(12,2) DEFAULT 0,
        late_fee DECIMAL(12,2) DEFAULT 0,
        net_amount DECIMAL(12,2) NOT NULL,
        payment_mode ENUM('Cash','Cheque','UPI','Card','Bank Transfer') NOT NULL,
        payment_date DATE NOT NULL,
        cheque_no VARCHAR(50),
        cheque_date DATE,
        cheque_bank VARCHAR(100),
        cheque_clearance ENUM('Pending','Cleared','Bounced') DEFAULT 'Pending',
        transaction_ref VARCHAR(100),
        collector_id INT,
        collector_name VARCHAR(100),
        notes TEXT,
        status ENUM('Active','Cancelled','Void') DEFAULT 'Active',
        cancelled_at TIMESTAMP NULL,
        cancelled_by INT,
        cancel_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_collection_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_collection_id INT NOT NULL,
        fee_head_id INT NOT NULL,
        fee_head_name VARCHAR(100),
        amount DECIMAL(10,2) NOT NULL,
        is_advance TINYINT(1) DEFAULT 0,
        FOREIGN KEY (fee_collection_id) REFERENCES fee_collections(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_collection_installments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_collection_id INT NOT NULL,
        installment_id INT,
        installment_no INT,
        amount DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (fee_collection_id) REFERENCES fee_collections(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_fee_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        student_name VARCHAR(200) DEFAULT '',
        class_name VARCHAR(50) DEFAULT '',
        academic_session VARCHAR(20) NOT NULL,
        fee_structure_id INT NOT NULL,
        total_fee DECIMAL(12,2) DEFAULT 0,
        total_paid DECIMAL(12,2) DEFAULT 0,
        total_discount DECIMAL(12,2) DEFAULT 0,
        total_late_fee DECIMAL(12,2) DEFAULT 0,
        balance DECIMAL(12,2) DEFAULT 0,
        status ENUM('active','closed','transferred') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ledger_account_id INT NOT NULL,
        entry_date DATE NOT NULL,
        entry_type ENUM('journal','receipt','payment','expense','income','contra') NOT NULL,
        reference_type VARCHAR(50),
        reference_id INT,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL,
        direction ENUM('debit','credit') NOT NULL,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_book (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_date DATE NOT NULL,
        transaction_type ENUM('opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out') NOT NULL,
        reference_type VARCHAR(50),
        reference_id INT,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL,
        direction ENUM('debit','credit') NOT NULL,
        balance DECIMAL(12,2) DEFAULT 0,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bank_book (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank_account_id INT NOT NULL,
        transaction_date DATE NOT NULL,
        transaction_type ENUM('opening','receipt','payment','deposit','withdrawal','transfer_in','transfer_out','reconciliation') NOT NULL,
        reference_type VARCHAR(50),
        reference_id INT,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL,
        direction ENUM('debit','credit') NOT NULL,
        balance DECIMAL(12,2) DEFAULT 0,
        reconciled TINYINT(1) DEFAULT 0,
        reconciliation_date DATE,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    // ignore migration errors
}

// ─── Cancel Receipt ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_receipt']) && verify_csrf()) {
    $receiptId = (int) ($_POST['receipt_id'] ?? 0);
    $cancelReason = trim((string) ($_POST['cancel_reason'] ?? ''));
    if ($receiptId > 0 && $cancelReason !== '') {
        try {
            $stmt = $pdo->prepare("UPDATE fee_collections SET status='Cancelled', cancelled_at=NOW(), cancelled_by=:uid, cancel_reason=:reason WHERE id=:id AND status='Active'");
            $stmt->execute(['uid' => (int) $user['id'], 'reason' => $cancelReason, 'id' => $receiptId]);
            if ($stmt->rowCount() > 0) {
                $success = 'Receipt cancelled successfully.';
            } else {
                $error = 'Receipt not found or already cancelled.';
            }
        } catch (Exception $e) {
            $error = 'Failed to cancel receipt: ' . $e->getMessage();
        }
    } else {
        $error = 'Cancellation reason is required.';
    }
}

// ─── Collect Fee ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['collect_fee']) && verify_csrf()) {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $academicSession = trim((string) ($_POST['academic_session'] ?? ''));
    $paymentMode = trim((string) ($_POST['payment_mode'] ?? ''));
    $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
    $discountAmount = (float) ($_POST['discount_amount'] ?? 0);
    $lateFee = (float) ($_POST['late_fee'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $selectedItems = isset($_POST['fee_items']) ? (array) $_POST['fee_items'] : [];
    $installmentId = (int) ($_POST['installment_id'] ?? 0);
    $chequeNo = trim((string) ($_POST['cheque_no'] ?? ''));
    $chequeDate = trim((string) ($_POST['cheque_date'] ?? ''));
    $chequeBank = trim((string) ($_POST['cheque_bank'] ?? ''));

    if (!$studentId || !$academicSession || !$paymentMode || !$paymentDate || empty($selectedItems)) {
        $error = 'Please fill all required fields and select at least one fee item.';
    } elseif ($paymentMode === 'Cheque' && (!$chequeNo || !$chequeDate || !$chequeBank)) {
        $error = 'Cheque number, date, and bank are required for Cheque payment mode.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, student_name, class_sought, admission_no FROM applications WHERE id = :id AND status = 'Admitted'");
            $stmt->execute(['id' => $studentId]);
            $student = $stmt->fetch();
            if (!$student) {
                throw new \RuntimeException('Selected student not found or not admitted.');
            }

            $itemsTotal = 0.0;
            $validItems = [];
            foreach ($selectedItems as $itemId => $amount) {
                $itemId = (int) $itemId;
                $amount = (float) $amount;
                if ($itemId > 0 && $amount >= 0) {
                    $itemsTotal += $amount;
                    $validItems[$itemId] = $amount;
                }
            }

            $netAmount = $itemsTotal + $lateFee - $discountAmount;
            if ($netAmount < 0) {
                $netAmount = 0;
            }

            $stmt = $pdo->query("SELECT COUNT(*)+1 FROM fee_collections WHERE YEAR(created_at)=YEAR(CURDATE())");
            $nextNum = (int) $stmt->fetchColumn();
            $receiptNo = 'RCP-' . date('Y') . '-' . str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);

            $ins = $pdo->prepare("INSERT INTO fee_collections (receipt_no, student_id, student_name, class_name, academic_session, total_amount, discount_amount, late_fee, net_amount, payment_mode, payment_date, cheque_no, cheque_date, cheque_bank, collector_id, collector_name, notes, status, created_at) VALUES (:receipt_no, :student_id, :student_name, :class_name, :academic_session, :total_amount, :discount_amount, :late_fee, :net_amount, :payment_mode, :payment_date, :cheque_no, :cheque_date, :cheque_bank, :collector_id, :collector_name, :notes, 'Active', NOW())");
            $ins->execute([
                'receipt_no' => $receiptNo,
                'student_id' => $student['id'],
                'student_name' => $student['student_name'],
                'class_name' => $student['class_sought'],
                'academic_session' => $academicSession,
                'total_amount' => $itemsTotal,
                'discount_amount' => $discountAmount,
                'late_fee' => $lateFee,
                'net_amount' => $netAmount,
                'payment_mode' => $paymentMode,
                'payment_date' => $paymentDate,
                'cheque_no' => $paymentMode === 'Cheque' ? $chequeNo : null,
                'cheque_date' => $paymentMode === 'Cheque' ? $chequeDate : null,
                'cheque_bank' => $paymentMode === 'Cheque' ? $chequeBank : null,
                'collector_id' => (int) $user['id'],
                'collector_name' => $user['name'],
                'notes' => $notes,
            ]);
            $collectionId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("SELECT fsi.id, fsi.fee_head_id, fh.name AS fee_head_name, fsi.amount FROM fee_structure_items fsi JOIN fee_heads fh ON fh.id = fsi.fee_head_id WHERE fsi.id = :item_id");
            $insItem = $pdo->prepare("INSERT INTO fee_collection_items (fee_collection_id, fee_head_id, fee_head_name, amount) VALUES (:fc_id, :fh_id, :fh_name, :amount)");
            foreach ($validItems as $itemId => $amount) {
                $itemStmt->execute(['item_id' => $itemId]);
                $item = $itemStmt->fetch();
                if ($item) {
                    $insItem->execute([
                        'fc_id' => $collectionId,
                        'fh_id' => $item['fee_head_id'],
                        'fh_name' => $item['fee_head_name'],
                        'amount' => $amount,
                    ]);
                }
            }

            if ($installmentId > 0) {
                $stmt = $pdo->prepare("SELECT installment_no, amount FROM fee_installments WHERE id = :id");
                $stmt->execute(['id' => $installmentId]);
                $inst = $stmt->fetch();
                if ($inst) {
                    $pdo->prepare("INSERT INTO fee_collection_installments (fee_collection_id, installment_id, installment_no, amount) VALUES (:fc_id, :inst_id, :inst_no, :amount)")
                        ->execute(['fc_id' => $collectionId, 'inst_id' => $installmentId, 'inst_no' => $inst['installment_no'], 'amount' => $netAmount]);
                }
            }

            $stmt = $pdo->prepare("SELECT id, total_fee, total_paid, total_discount, total_late_fee, balance FROM student_fee_accounts WHERE student_id = :sid AND academic_session = :sess LIMIT 1");
            $stmt->execute(['sid' => $student['id'], 'sess' => $academicSession]);
            $feeAccount = $stmt->fetch();

            if ($feeAccount) {
                $newPaid = (float) $feeAccount['total_paid'] + $netAmount;
                $newDiscount = (float) $feeAccount['total_discount'] + $discountAmount;
                $newLateFee = (float) $feeAccount['total_late_fee'] + $lateFee;
                $newBalance = (float) $feeAccount['total_fee'] - $newPaid;
                if ($newBalance < 0) {
                    $newBalance = 0;
                }
                $pdo->prepare("UPDATE student_fee_accounts SET total_paid = :paid, total_discount = :disc, total_late_fee = :lf, balance = :bal, student_name = :sname, class_name = :cls WHERE id = :id")
                    ->execute(['paid' => $newPaid, 'disc' => $newDiscount, 'lf' => $newLateFee, 'bal' => $newBalance, 'sname' => $student['student_name'], 'cls' => $student['class_sought'], 'id' => $feeAccount['id']]);
            } else {
                $fsaStmt = $pdo->prepare("SELECT fsa.fee_structure_id FROM fee_structure_assignments fsa WHERE fsa.assign_type = 'class' AND fsa.assign_value = :class AND fsa.is_active = 1 LIMIT 1");
                $fsaStmt->execute(['class' => $student['class_sought']]);
                $fsaRow = $fsaStmt->fetch();
                $fsId = $fsaRow ? (int) $fsaRow['fee_structure_id'] : 0;
                $balanceAmt = $netAmount > $itemsTotal ? 0 : $itemsTotal - $netAmount;
                $pdo->prepare("INSERT INTO student_fee_accounts (student_id, student_name, class_name, academic_session, fee_structure_id, total_fee, total_paid, total_discount, total_late_fee, balance, status) VALUES (:sid, :sname, :cls, :sess, :fsid, :tot, :paid, :disc, :lf, :bal, 'active')")
                    ->execute(['sid' => $student['id'], 'sname' => $student['student_name'], 'cls' => $student['class_sought'], 'sess' => $academicSession, 'fsid' => $fsId, 'tot' => $itemsTotal, 'paid' => $netAmount, 'disc' => $discountAmount, 'lf' => $lateFee, 'bal' => $itemsTotal - $netAmount]);
            }

            if ($paymentMode === 'Cash') {
                $pdo->prepare("INSERT INTO cash_book (transaction_date, transaction_type, reference_type, reference_id, description, amount, direction, created_by) VALUES (:dt, 'receipt', 'fee_collection', :rid, :desc, :amt, 'debit', :uid)")
                    ->execute(['dt' => $paymentDate, 'rid' => $collectionId, 'desc' => "Fee collection - Receipt {$receiptNo} - {$student['student_name']}", 'amt' => $netAmount, 'uid' => (int) $user['id']]);
            } else {
                $pdo->prepare("INSERT INTO bank_book (bank_account_id, transaction_date, transaction_type, reference_type, reference_id, description, amount, direction, created_by) VALUES ('1', :dt, 'receipt', 'fee_collection', :rid, :desc, :amt, 'debit', :uid)")
                    ->execute(['dt' => $paymentDate, 'rid' => $collectionId, 'desc' => "Fee collection - Receipt {$receiptNo} - {$student['student_name']}", 'amt' => $netAmount, 'uid' => (int) $user['id']]);
            }

            $ledgerAssetId = $paymentMode === 'Cash' ? 2 : 3;
            $pdo->prepare("INSERT INTO ledger_entries (ledger_account_id, entry_date, entry_type, reference_type, reference_id, description, amount, direction, created_by) VALUES (:acct_id, :dt, 'receipt', 'fee_collection', :rid, :desc, :amt, 'debit', :uid)")
                ->execute(['acct_id' => $ledgerAssetId, 'dt' => $paymentDate, 'rid' => $collectionId, 'desc' => "Fee collection - Receipt {$receiptNo} - {$student['student_name']}", 'amt' => $netAmount, 'uid' => (int) $user['id']]);

            $incomeAccountId = null;
            if (!empty($validItems) && count($validItems) === 1) {
                reset($validItems);
                $singleItemId = (int) key($validItems);
                $itemStmt->execute(['item_id' => $singleItemId]);
                $singleItem = $itemStmt->fetch();
                if ($singleItem) {
                    $feeHeadName = $singleItem['fee_head_name'] ?? '';
                    $incomeAccountId = match (true) {
                        stripos($feeHeadName, 'tuition') !== false => 7,
                        stripos($feeHeadName, 'transport') !== false => 8,
                        stripos($feeHeadName, 'hostel') !== false => 9,
                        stripos($feeHeadName, 'admission') !== false => 10,
                        default => 11,
                    };
                }
            }
            if (!$incomeAccountId) {
                $incomeAccountId = 11;
            }
            $pdo->prepare("INSERT INTO ledger_entries (ledger_account_id, entry_date, entry_type, reference_type, reference_id, description, amount, direction, created_by) VALUES (:acct_id, :dt, 'receipt', 'fee_collection', :rid, :desc, :amt, 'credit', :uid)")
                ->execute(['acct_id' => $incomeAccountId, 'dt' => $paymentDate, 'rid' => $collectionId, 'desc' => "Fee collection - Receipt {$receiptNo} - {$student['student_name']}", 'amt' => $netAmount, 'uid' => (int) $user['id']]);

            $pdo->commit();
            $success = "Fee collected successfully. Receipt No: {$receiptNo}";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to process fee collection: ' . $e->getMessage();
        }
    }
}

// ─── Load Student / Fee Structure for Form (GET) ───
$selectedStudent = null;
$feeStructure = null;
$feeStructureItems = [];
$installments = [];
$selectedStudentId = (int) ($_GET['student_id'] ?? 0);

if ($selectedStudentId > 0) {
    $stmt = $pdo->prepare("SELECT id, student_name, class_sought, admission_no FROM applications WHERE id = :id AND status = 'Admitted'");
    $stmt->execute(['id' => $selectedStudentId]);
    $selectedStudent = $stmt->fetch();

    if ($selectedStudent) {
        $stmt = $pdo->prepare("SELECT fsa.fee_structure_id, fs.id, fs.name, fs.total_amount, fs.installment_enabled, fs.num_installments FROM fee_structure_assignments fsa JOIN fee_structures fs ON fs.id = fsa.fee_structure_id WHERE fsa.assign_type = 'class' AND fsa.assign_value = :class AND fsa.is_active = 1 AND fs.is_active = 1 LIMIT 1");
        $stmt->execute(['class' => $selectedStudent['class_sought']]);
        $feeStructure = $stmt->fetch();

        if ($feeStructure) {
            $stmt = $pdo->prepare("SELECT fsi.id, fsi.fee_head_id, fsi.amount, fsi.is_optional, fh.name AS fee_head_name, fh.sort_order FROM fee_structure_items fsi JOIN fee_heads fh ON fh.id = fsi.fee_head_id WHERE fsi.fee_structure_id = :fsid ORDER BY fh.sort_order ASC");
            $stmt->execute(['fsid' => $feeStructure['id']]);
            $feeStructureItems = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT * FROM fee_installments WHERE fee_structure_id = :fsid ORDER BY installment_no ASC");
            $stmt->execute(['fsid' => $feeStructure['id']]);
            $installments = $stmt->fetchAll();
        }
    }
}

// ─── Recent Collections (paginated) ───
$searchQ = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$whereClause = '';
$params = [];
if ($searchQ !== '') {
    $whereClause = ' WHERE (fc.receipt_no LIKE :q1 OR fc.student_name LIKE :q2)';
    $likeQ = '%' . $searchQ . '%';
    $params['q1'] = $likeQ;
    $params['q2'] = $likeQ;
}

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM fee_collections fc" . $whereClause);
    $countStmt->execute($params);
    $totalCollections = (int) $countStmt->fetchColumn();
} catch (\Throwable $e) {
    $totalCollections = 0;
}

$totalPages = max(1, (int) ceil($totalCollections / $limit));

$sql = "SELECT fc.* FROM fee_collections fc" . $whereClause . " ORDER BY fc.created_at DESC LIMIT :lim OFFSET :off";
$listStmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$listStmt->execute();
$collections = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Cancellation Form ───
$cancelReceiptId = (int) ($_GET['cancel'] ?? 0);
$cancelReceipt = null;
if ($cancelReceiptId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM fee_collections WHERE id = :id AND status = 'Active'");
    $stmt->execute(['id' => $cancelReceiptId]);
    $cancelReceipt = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fee Collection – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .app-filters { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; }
        .app-filters input, .app-filters select { padding:.45rem .7rem; border:1px solid #cbd5e1; border-radius:6px; font-size:.875rem; }
        .app-filters .btn { padding:.45rem 1rem; }
        .app-table { width:100%; table-layout:auto; border-collapse:collapse; font-size:.85rem; }
        .app-table thead th { text-align:left; padding:.55rem .65rem; background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600; white-space:nowrap; position:sticky; top:0; }
        .app-table tbody td { padding:.6rem .65rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .app-table tbody tr:nth-child(even) td { background:#fafbfc; }
        .app-table tbody tr:hover td { background:#eff6ff; }
        .pagination { display:flex; gap:.5rem; align-items:center; margin-top:1rem; }
        .pagination a, .pagination span { padding:.35rem .7rem; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; font-size:.85rem; color:#334155; }
        .pagination a:hover { background:#f1f5f9; }
        .pagination .current { background:#1e293b; color:#fff; border-color:#1e293b; }
        .fee-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
        .fee-grid .full-width { grid-column:1/-1; }
        .form-row { display:flex; gap:1rem; align-items:center; flex-wrap:wrap; margin-bottom:.85rem; }
        .form-row label { min-width:140px; font-weight:600; font-size:.85rem; color:#334155; }
        .form-row input, .form-row select, .form-row textarea { flex:1; min-width:180px; padding:.5rem .7rem; border:1px solid #cbd5e1; border-radius:6px; font-size:.875rem; }
        .form-row textarea { min-height:70px; resize:vertical; }
        .fee-item { display:flex; align-items:center; gap:.75rem; padding:.5rem .75rem; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:.4rem; background:#fafbfc; }
        .fee-item:hover { background:#f1f5f9; }
        .fee-item label { flex:1; cursor:pointer; font-weight:500; }
        .fee-item input[type="number"] { width:120px; padding:.35rem .5rem; border:1px solid #cbd5e1; border-radius:6px; text-align:right; }
        .status-badge { display:inline-block; padding:.2rem .6rem; border-radius:999px; font-size:.75rem; font-weight:600; }
        .status-Active { background:#d1fae5; color:#065f46; }
        .status-Cancelled { background:#fee2e2; color:#991b1b; }
        .net-amount-display { font-size:1.5rem; font-weight:700; color:#1e293b; text-align:right; padding:.5rem 0; }
        .cheque-fields { display:none; }
        @media (max-width:768px) { .fee-grid { grid-template-columns:1fr; } .form-row { flex-direction:column; align-items:stretch; } .form-row label { min-width:auto; } }
    </style>
</head>
<body style="min-height:100vh;">
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
            <div class="nav-title">Finance</div>
            <a class="nav-link" href="finance-dashboard.php"><span class="sidebar-icon">📊</span><span>Finance Dashboard</span></a>
            <a class="nav-link" href="fee-structures.php"><span class="sidebar-icon">🏗</span><span>Fee Structures</span></a>
            <a class="nav-link active" href="fee-collection.php"><span class="sidebar-icon">💰</span><span>Fee Collection</span></a>
            <a class="nav-link" href="receipts-list.php"><span class="sidebar-icon">🧾</span><span>Receipts</span></a>
            <a class="nav-link" href="expenses.php"><span class="sidebar-icon">📤</span><span>Expenses</span></a>
            <a class="nav-link" href="income.php"><span class="sidebar-icon">📥</span><span>Income</span></a>
            <a class="nav-link" href="accounts.php"><span class="sidebar-icon">🏦</span><span>Accounts</span></a>
            <a class="nav-link" href="reports.php"><span class="sidebar-icon">📈</span><span>Reports</span></a>
        </div>
        <?php if ($isOwner): ?>
        <div class="nav-group">
            <div class="nav-title">Administration</div>
            <?php $pendingAdminCount = 0; try { $pendingAdminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_registrations WHERE status = 'pending'")->fetchColumn(); } catch (\Throwable $e) {} ?>
            <a class="nav-link" href="admin-requests.php">
                <span class="sidebar-icon">🔑</span>
                <span>Admin Requests</span>
                <?php if ($pendingAdminCount > 0): ?>
                    <span class="nav-tag" style="background:#f59e0b;color:#fff;"><?= $pendingAdminCount ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>
        <div class="nav-group" style="margin-top:auto;">
            <a class="btn btn-soft" style="width:100%" href="logout.php">Logout</a>
        </div>
    </aside>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Fee Collection</h1>
                    <p>Collect fees from students and generate receipts.</p>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="alert alert-error" style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($cancelReceipt): ?>
            <!-- ─── Cancellation Form ─── -->
            <section class="panel" style="padding:1.5rem;margin-bottom:2rem;">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2 style="color:#dc2626;">Cancel Receipt</h2>
                    <p>Receipt No: <strong><?= e($cancelReceipt['receipt_no']) ?></strong> – <?= e($cancelReceipt['student_name']) ?> (<?= e($cancelReceipt['class_name']) ?>) – Rs. <?= number_format((float) $cancelReceipt['net_amount'], 2) ?></p>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="receipt_id" value="<?= (int) $cancelReceipt['id'] ?>">
                    <div class="form-row">
                        <label for="cancel_reason">Cancellation Reason</label>
                        <textarea id="cancel_reason" name="cancel_reason" required placeholder="Provide a reason for cancellation..."></textarea>
                    </div>
                    <div style="display:flex;gap:.75rem;margin-top:1rem;">
                        <button type="submit" name="cancel_receipt" value="1" class="btn btn-danger" style="background:#dc2626;color:#fff;border:none;padding:.5rem 1.5rem;border-radius:6px;font-weight:600;cursor:pointer;">Confirm Cancellation</button>
                        <a href="fee-collection.php" class="btn btn-soft" style="padding:.5rem 1.5rem;border-radius:6px;text-decoration:none;">Back</a>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <!-- ─── Fee Collection Form ─── -->
            <section class="panel" style="padding:1.5rem;margin-bottom:2rem;">
                <div class="section-title" style="margin-bottom:1rem;">
                    <h2>New Fee Collection</h2>
                    <p>Select a student and fee items to collect payment.</p>
                </div>
                <form method="get" id="student-select-form" style="margin-bottom:1.25rem;">
                    <div class="form-row">
                        <label for="student_id">Select Student</label>
                        <select name="student_id" id="student_id" onchange="this.form.submit()" style="flex:1;min-width:220px;padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                            <option value="">— Choose a student —</option>
                            <?php
                            $stmt = $pdo->prepare("SELECT id, student_name, class_sought, admission_no FROM applications WHERE status = 'Admitted' ORDER BY student_name ASC");
                            $stmt->execute();
                            $students = $stmt->fetchAll();
                            foreach ($students as $s):
                                $label = $s['student_name'];
                                if (!empty($s['admission_no'])) {
                                    $label .= ' [' . $s['admission_no'] . ']';
                                }
                                $label .= ' (' . $s['class_sought'] . ')';
                            ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $selectedStudent && (int) $s['id'] === (int) $selectedStudent['id'] ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selectedStudent): ?>
                            <a href="fee-collection.php" class="btn btn-soft" style="padding:.45rem 1rem;border-radius:6px;text-decoration:none;font-size:.85rem;">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($selectedStudent): ?>
                    <form method="post" id="fee-collection-form">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="student_id" value="<?= (int) $selectedStudent['id'] ?>">
                        <input type="hidden" name="collect_fee" value="1">

                        <div class="fee-grid">
                            <div class="stack" style="gap:.5rem;">
                                <div class="form-row">
                                    <label>Student</label>
                                    <span style="flex:1;font-weight:600;"><?= e($selectedStudent['student_name']) ?> (<?= e($selectedStudent['class_sought']) ?>)</span>
                                </div>
                                <div class="form-row">
                                    <label for="academic_session">Academic Session</label>
                                    <input type="text" id="academic_session" name="academic_session" value="2025-26" required>
                                </div>
                                <div class="form-row">
                                    <label for="payment_mode">Payment Mode</label>
                                    <select id="payment_mode" name="payment_mode" required onchange="toggleChequeFields(this.value)">
                                        <option value="Cash">Cash</option>
                                        <option value="Cheque">Cheque</option>
                                        <option value="UPI">UPI</option>
                                        <option value="Card">Card</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <label for="payment_date">Payment Date</label>
                                    <input type="date" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="cheque-fields" id="cheque-fields">
                                    <div class="form-row">
                                        <label for="cheque_no">Cheque No.</label>
                                        <input type="text" id="cheque_no" name="cheque_no" placeholder="Cheque number">
                                    </div>
                                    <div class="form-row">
                                        <label for="cheque_date">Cheque Date</label>
                                        <input type="date" id="cheque_date" name="cheque_date">
                                    </div>
                                    <div class="form-row">
                                        <label for="cheque_bank">Bank</label>
                                        <input type="text" id="cheque_bank" name="cheque_bank" placeholder="Bank name">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <label for="notes">Notes</label>
                                    <textarea id="notes" name="notes" placeholder="Optional notes..."></textarea>
                                </div>
                            </div>

                            <div class="stack" style="gap:.5rem;">
                                <?php if (!empty($installments)): ?>
                                    <div class="form-row">
                                        <label for="installment_id">Installment</label>
                                        <select id="installment_id" name="installment_id">
                                            <option value="0">— None (full payment) —</option>
                                            <?php foreach ($installments as $inst): ?>
                                                <option value="<?= (int) $inst['id'] ?>"><?= e($inst['title'] ?: 'Installment ' . $inst['installment_no']) ?> – Rs. <?= number_format((float) $inst['amount'], 2) ?> (Due: <?= e($inst['due_date']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($feeStructureItems)): ?>
                                    <div style="font-weight:600;font-size:.9rem;margin-bottom:.5rem;color:#334155;">Fee Items</div>
                                    <div id="fee-items-container">
                                        <?php foreach ($feeStructureItems as $item): ?>
                                            <div class="fee-item">
                                                <input type="checkbox" class="fee-item-checkbox" data-id="<?= (int) $item['id'] ?>" data-amount="<?= (float) $item['amount'] ?>" checked onchange="updateNetAmount()">
                                                <label onclick="this.previousElementSibling.click();updateNetAmount()"><?= e($item['fee_head_name']) ?></label>
                                                <input type="number" class="fee-item-amount" name="fee_items[<?= (int) $item['id'] ?>]" value="<?= (float) $item['amount'] ?>" step="0.01" min="0" onchange="updateNetAmount()" onkeyup="updateNetAmount()">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="padding:2rem;text-align:center;color:#94a3b8;border:1px dashed #e2e8f0;border-radius:8px;">
                                        No fee structure assigned for this class.
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($feeStructureItems)): ?>
                                    <div style="margin-top:1rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
                                        <div class="form-row">
                                            <label for="late_fee">Late Fee</label>
                                            <input type="number" id="late_fee" name="late_fee" value="0" step="0.01" min="0" onchange="updateNetAmount()" onkeyup="updateNetAmount()">
                                        </div>
                                        <div class="form-row">
                                            <label for="discount_amount">Discount</label>
                                            <input type="number" id="discount_amount" name="discount_amount" value="0" step="0.01" min="0" onchange="updateNetAmount()" onkeyup="updateNetAmount()">
                                        </div>
                                        <div class="net-amount-display">
                                            Net Amount: Rs. <span id="net-amount">0.00</span>
                                        </div>
                                        <button type="submit" class="btn btn-primary" style="background:#1e293b;color:#fff;border:none;padding:.65rem 2rem;border-radius:6px;font-weight:600;font-size:1rem;cursor:pointer;width:100%;">Collect Fee & Generate Receipt</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- ─── Recent Collections ─── -->
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title" style="margin-bottom:1rem;">
                <div>
                    <h2>Recent Collections</h2>
                    <p>View and manage fee collections.</p>
                </div>
            </div>

            <form method="get" class="app-filters">
                <input type="text" name="q" placeholder="Search receipt no or student name..." value="<?= e($searchQ) ?>" style="min-width:220px;">
                <button type="submit" class="btn btn-primary" style="padding:.45rem 1rem;">Search</button>
                <a href="fee-collection.php" class="btn btn-soft" style="padding:.45rem 1rem;text-decoration:none;">Clear</a>
                <span style="margin-left:auto;color:#64748b;font-size:.85rem;"><?= $totalCollections ?> collection<?= $totalCollections !== 1 ? 's' : '' ?></span>
            </form>

            <div style="overflow-x:auto;">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>Receipt No</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Amount</th>
                            <th>Payment Mode</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($collections)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8;">No collections found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($collections as $c): ?>
                                <tr>
                                    <td style="font-family:monospace;font-size:.8rem;"><?= e($c['receipt_no'] ?? '—') ?></td>
                                    <td><strong><?= e($c['student_name'] ?? '—') ?></strong></td>
                                    <td><?= e($c['class_name'] ?? '—') ?></td>
                                    <td>Rs. <?= number_format((float) ($c['net_amount'] ?? 0), 2) ?></td>
                                    <td><?= e($c['payment_mode'] ?? '—') ?></td>
                                    <td style="white-space:nowrap;"><?= e($c['payment_date'] ?? '—') ?></td>
                                    <td><span class="status-badge status-<?= e($c['status'] ?? 'Active') ?>"><?= e($c['status'] ?? 'Active') ?></span></td>
                                    <td>
                                        <?php if (($c['status'] ?? '') === 'Active'): ?>
                                            <a href="?cancel=<?= (int) $c['id'] ?>" class="btn btn-soft" style="font-size:.75rem;padding:.25rem .6rem;border-radius:6px;text-decoration:none;color:#dc2626;border:1px solid #fecaca;">Cancel</a>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:.8rem;">—</span>
                                        <?php endif; ?>
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
        </section>
    </main>
</div>
<script src="../assets/erp.js"></script>
<script>
function toggleChequeFields(mode) {
    var el = document.getElementById('cheque-fields');
    if (el) {
        el.style.display = (mode === 'Cheque') ? 'block' : 'none';
    }
}

function updateNetAmount() {
    var items = document.querySelectorAll('.fee-item-amount');
    var total = 0;
    items.forEach(function(inp) {
        var cb = inp.closest('.fee-item').querySelector('.fee-item-checkbox');
        if (cb && cb.checked) {
            total += parseFloat(inp.value) || 0;
        }
    });
    var lateFee = parseFloat(document.getElementById('late_fee').value) || 0;
    var discount = parseFloat(document.getElementById('discount_amount').value) || 0;
    var net = total + lateFee - discount;
    if (net < 0) net = 0;
    document.getElementById('net-amount').textContent = net.toFixed(2);
}

document.addEventListener('DOMContentLoaded', function() {
    toggleChequeFields(document.getElementById('payment_mode').value);
    updateNetAmount();
});
</script>
</body>
</html>
