<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$pdo = $GLOBALS['pdo'];
$error = '';
$success = '';

// ─── Ensure tables exist ───
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        receipt_no VARCHAR(50) UNIQUE NOT NULL,
        student_id INT,
        student_name VARCHAR(200),
        admission_no VARCHAR(50),
        class_name VARCHAR(50),
        academic_session VARCHAR(20),
        fee_period VARCHAR(50),
        total_outstanding DECIMAL(12,2) DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL,
        discount_amount DECIMAL(12,2) DEFAULT 0,
        late_fee DECIMAL(12,2) DEFAULT 0,
        net_amount DECIMAL(12,2) NOT NULL,
        payment_mode VARCHAR(50) NOT NULL,
        transaction_id VARCHAR(100),
        payment_date DATE NOT NULL,
        cheque_no VARCHAR(50),
        cheque_date DATE,
        cheque_bank VARCHAR(100),
        payee_name VARCHAR(200),
        collector_name VARCHAR(100),
        notes TEXT,
        status ENUM('Active','Cancelled','Void') DEFAULT 'Active',
        cancel_reason TEXT,
        cancelled_by INT,
        cancelled_at TIMESTAMP NULL,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_collection_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fee_collection_id INT NOT NULL,
        fee_head_id INT NOT NULL,
        fee_head_name VARCHAR(100),
        amount DECIMAL(10,2) NOT NULL,
        is_advance TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

// ─── Helper: Search admitted students ───
function student_search(PDO $pdo, string $query): array
{
    $like = '%' . $query . '%';
    $stmt = $pdo->prepare(
        "SELECT id, student_name, admission_no, class_sought, first_name, last_name
         FROM applications
         WHERE status = 'Admitted'
           AND (student_name LIKE :q OR admission_no LIKE :q2 OR class_sought LIKE :q3 OR id = :q4)
         ORDER BY student_name ASC
         LIMIT 20"
    );
    $stmt->execute(['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => (int) $query]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── Helper: Get student fee detail ───
function student_fee_detail(PDO $pdo, int $studentId, string $session): array
{
    $result = [
        'student' => null,
        'fee_account' => null,
        'fee_structure' => null,
        'fee_items' => [],
        'paid_items' => [],
    ];

    $stmt = $pdo->prepare(
        "SELECT id, student_name, admission_no, class_sought
         FROM applications WHERE id = :id AND status = 'Admitted'"
    );
    $stmt->execute(['id' => $studentId]);
    $result['student'] = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$result['student']) {
        return $result;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM student_fee_accounts
         WHERE student_id = :sid AND academic_session = :sess LIMIT 1"
    );
    $stmt->execute(['sid' => $studentId, 'sess' => $session]);
    $result['fee_account'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($result['fee_account']) {
        $fsId = (int) $result['fee_account']['fee_structure_id'];
    } else {
        $stmt = $pdo->prepare(
            "SELECT fsa.fee_structure_id
             FROM fee_structure_assignments fsa
             JOIN fee_structures fs ON fs.id = fsa.fee_structure_id
             WHERE fsa.assign_type = 'Class'
               AND fsa.assign_value = :cls
               AND fsa.is_active = 1
               AND fs.is_active = 1
             LIMIT 1"
        );
        $stmt->execute(['cls' => $result['student']['class_sought']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $fsId = $row ? (int) $row['fee_structure_id'] : 0;
    }

    if ($fsId > 0) {
        $stmt = $pdo->prepare(
            "SELECT * FROM fee_structures WHERE id = :id AND is_active = 1"
        );
        $stmt->execute(['id' => $fsId]);
        $result['fee_structure'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare(
            "SELECT fsi.id, fsi.fee_head_id, fsi.amount AS structure_amount,
                    fsi.frequency, fsi.is_optional,
                    fh.name AS fee_head_name
             FROM fee_structure_items fsi
             JOIN fee_heads fh ON fh.id = fsi.fee_head_id
             WHERE fsi.fee_structure_id = :fsid
             ORDER BY fh.sort_order ASC, fh.name ASC"
        );
        $stmt->execute(['fsid' => $fsId]);
        $result['fee_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get already paid items
    if ($result['fee_account']) {
        $stmt = $pdo->prepare(
            "SELECT fci.fee_head_id, fci.fee_head_name, SUM(fci.amount) AS total_paid
             FROM fee_collection_items fci
             JOIN fee_collections fc ON fc.id = fci.fee_collection_id
             WHERE fc.student_id = :sid
               AND fc.academic_session = :sess
               AND fc.status = 'Active'
             GROUP BY fci.fee_head_id, fci.fee_head_name"
        );
        $stmt->execute(['sid' => $studentId, 'sess' => $session]);
        $result['paid_items'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return $result;
}

// ─── Helper: Generate atomic receipt number ───
function generate_receipt_no(PDO $pdo): string
{
    $month = date('Ym');
    $prefix = 'RCPT-' . $month . '-';
    $stmt = $pdo->prepare(
        "SELECT receipt_no FROM fee_collections
         WHERE receipt_no LIKE :prefix
         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->execute(['prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = (int) substr($last, -4) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
}

// ─── Helper: Payment mode options ───
function payment_mode_options(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT id, name, is_online, cheque_details_required
             FROM payment_modes WHERE status = 'Active' ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            return $rows;
        }
    } catch (Throwable $e) {
        // table might not exist
    }
    return [
        ['id' => 0, 'name' => 'Cash', 'is_online' => 0, 'cheque_details_required' => 0],
        ['id' => 0, 'name' => 'Cheque', 'is_online' => 0, 'cheque_details_required' => 1],
        ['id' => 0, 'name' => 'UPI', 'is_online' => 1, 'cheque_details_required' => 0],
        ['id' => 0, 'name' => 'Card', 'is_online' => 1, 'cheque_details_required' => 0],
        ['id' => 0, 'name' => 'Bank Transfer', 'is_online' => 1, 'cheque_details_required' => 0],
    ];
}

// ─── Helper: Bank account options ───
function bank_account_options(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, bank_name, account_name, current_balance
         FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════
// POST HANDLING (before any HTML)
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {

    // ─── COLLECT FEE ───
    if (isset($_POST['collect_fee'])) {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $academicSession = trim((string) ($_POST['academic_session'] ?? ''));
        $feePeriod = trim((string) ($_POST['fee_period'] ?? ''));
        $paymentMode = trim((string) ($_POST['payment_mode'] ?? ''));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
        $discountAmount = (float) ($_POST['discount_amount'] ?? 0);
        $lateFee = (float) ($_POST['late_fee'] ?? 0);
        $payeeName = trim((string) ($_POST['payee_name'] ?? ''));
        $collectorName = trim((string) ($_POST['collector_name'] ?? $user['name'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $chequeNo = trim((string) ($_POST['cheque_no'] ?? ''));
        $chequeDate = trim((string) ($_POST['cheque_date'] ?? ''));
        $chequeBank = trim((string) ($_POST['cheque_bank'] ?? ''));

        $feeItems = isset($_POST['fee_items']) ? $_POST['fee_items'] : [];
        $feeAmounts = isset($_POST['fee_amounts']) ? $_POST['fee_amounts'] : [];

        if (!$studentId || !$academicSession || !$paymentMode || !$paymentDate) {
            $error = 'Please fill all required fields.';
        } elseif (empty($feeItems)) {
            $error = 'Please select at least one fee item to collect.';
        } elseif ($paymentMode === 'Cheque' && (!$chequeNo || !$chequeDate || !$chequeBank)) {
            $error = 'Cheque number, date, and bank name are required for cheque payments.';
        } else {
            try {
                $pdo->beginTransaction();

                // Fetch student
                $stmt = $pdo->prepare(
                    "SELECT id, student_name, admission_no, class_sought
                     FROM applications WHERE id = :id AND status = 'Admitted'"
                );
                $stmt->execute(['id' => $studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    throw new \RuntimeException('Student not found or not admitted.');
                }

                // Calculate totals
                $totalAmount = 0.0;
                $validItems = [];
                foreach ($feeItems as $feeHeadId => $flag) {
                    if ((string) $flag !== '1') {
                        continue;
                    }
                    $feeHeadId = (int) $feeHeadId;
                    $amount = (float) ($feeAmounts[$feeHeadId] ?? 0);
                    if ($feeHeadId > 0 && $amount > 0) {
                        $totalAmount += $amount;
                        $validItems[$feeHeadId] = $amount;
                    }
                }

                if (empty($validItems)) {
                    throw new \RuntimeException('No valid fee items selected.');
                }

                // Fetch fee head names
                $headNames = [];
                $placeholders = implode(',', array_fill(0, count($validItems), '?'));
                $stmt = $pdo->prepare("SELECT id, name FROM fee_heads WHERE id IN ($placeholders)");
                $stmt->execute(array_keys($validItems));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $headNames[(int) $row['id']] = $row['name'];
                }

                $netAmount = $totalAmount + $lateFee - $discountAmount;
                if ($netAmount < 0) {
                    $netAmount = 0;
                }

                // Get outstanding from student_fee_accounts
                $stmt = $pdo->prepare(
                    "SELECT id, total_fee, total_paid, balance
                     FROM student_fee_accounts
                     WHERE student_id = :sid AND academic_session = :sess LIMIT 1"
                );
                $stmt->execute(['sid' => $studentId, 'sess' => $academicSession]);
                $feeAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                $totalOutstanding = $feeAccount ? (float) $feeAccount['balance'] : $totalAmount;

                $receiptNo = generate_receipt_no($pdo);

                // Insert fee_collections
                $ins = $pdo->prepare(
                    "INSERT INTO fee_collections
                     (receipt_no, student_id, student_name, admission_no, class_name,
                      academic_session, fee_period, total_outstanding, total_amount,
                      discount_amount, late_fee, net_amount, payment_mode, transaction_id,
                      payment_date, cheque_no, cheque_date, cheque_bank, payee_name,
                      collector_name, notes, status, created_by, created_at)
                     VALUES
                     (:receipt_no, :student_id, :student_name, :admission_no, :class_name,
                      :academic_session, :fee_period, :total_outstanding, :total_amount,
                      :discount_amount, :late_fee, :net_amount, :payment_mode, :transaction_id,
                      :payment_date, :cheque_no, :cheque_date, :cheque_bank, :payee_name,
                      :collector_name, :notes, 'Active', :created_by, NOW())"
                );
                $ins->execute([
                    'receipt_no' => $receiptNo,
                    'student_id' => $student['id'],
                    'student_name' => $student['student_name'],
                    'admission_no' => $student['admission_no'],
                    'class_name' => $student['class_sought'],
                    'academic_session' => $academicSession,
                    'fee_period' => $feePeriod,
                    'total_outstanding' => $totalOutstanding,
                    'total_amount' => $totalAmount,
                    'discount_amount' => $discountAmount,
                    'late_fee' => $lateFee,
                    'net_amount' => $netAmount,
                    'payment_mode' => $paymentMode,
                    'transaction_id' => $transactionId ?: null,
                    'payment_date' => $paymentDate,
                    'cheque_no' => $paymentMode === 'Cheque' ? $chequeNo : null,
                    'cheque_date' => $paymentMode === 'Cheque' && $chequeDate ? $chequeDate : null,
                    'cheque_bank' => $paymentMode === 'Cheque' ? $chequeBank : null,
                    'payee_name' => $payeeName ?: null,
                    'collector_name' => $collectorName,
                    'notes' => $notes ?: null,
                    'created_by' => (int) $user['id'],
                ]);
                $collectionId = (int) $pdo->lastInsertId();

                // Insert fee_collection_items
                $insItem = $pdo->prepare(
                    "INSERT INTO fee_collection_items
                     (fee_collection_id, fee_head_id, fee_head_name, amount, created_at)
                     VALUES (:fc_id, :fh_id, :fh_name, :amount, NOW())"
                );
                foreach ($validItems as $fhId => $amt) {
                    $insItem->execute([
                        'fc_id' => $collectionId,
                        'fh_id' => $fhId,
                        'fh_name' => $headNames[$fhId] ?? '',
                        'amount' => $amt,
                    ]);
                }

                // Update student_fee_accounts
                if ($feeAccount) {
                    $newPaid = (float) $feeAccount['total_paid'] + $netAmount;
                    $newBalance = (float) $feeAccount['total_fee'] - $newPaid;
                    if ($newBalance < 0) {
                        $newBalance = 0;
                    }
                    $newStatus = $newBalance <= 0.001 ? 'closed' : 'active';
                    $pdo->prepare(
                        "UPDATE student_fee_accounts
                         SET total_paid = :paid, balance = :bal, status = :st,
                             student_name = :sname, class_name = :cls
                         WHERE id = :id"
                    )->execute([
                        'paid' => $newPaid,
                        'bal' => $newBalance,
                        'st' => $newStatus,
                        'sname' => $student['student_name'],
                        'cls' => $student['class_sought'],
                        'id' => $feeAccount['id'],
                    ]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO student_fee_accounts
                         (student_id, student_name, class_name, academic_session,
                          fee_structure_id, total_fee, total_paid, balance, status, created_at, updated_at)
                         VALUES (:sid, :sname, :cls, :sess, 0, :tot, :paid, :bal, 'active', NOW(), NOW())"
                    )->execute([
                        'sid' => $student['id'],
                        'sname' => $student['student_name'],
                        'cls' => $student['class_sought'],
                        'sess' => $academicSession,
                        'tot' => $totalAmount,
                        'paid' => $netAmount,
                        'bal' => max(0, $totalAmount - $netAmount),
                    ]);
                }

                // Cash book entry
                if ($paymentMode === 'Cash') {
                    $cashDesc = "Fee receipt {$receiptNo} - {$student['student_name']}";
                    // Get current cash balance
                    $cashBal = (float) $pdo->query("SELECT COALESCE(balance,0) FROM cash_book ORDER BY id DESC LIMIT 1")->fetchColumn();
                    $newCashBal = $cashBal + $netAmount;
                    $pdo->prepare(
                        "INSERT INTO cash_book
                         (transaction_date, transaction_type, reference_type, reference_id,
                          description, amount, direction, balance, created_by, created_at)
                         VALUES (:dt, 'receipt', 'fee_collection', :rid, :desc, :amt, 'debit', :bal, :uid, NOW())"
                    )->execute([
                        'dt' => $paymentDate,
                        'rid' => $collectionId,
                        'desc' => $cashDesc,
                        'amt' => $netAmount,
                        'bal' => $newCashBal,
                        'uid' => (int) $user['id'],
                    ]);
                } else {
                    // Bank book entry
                    if ($bankAccountId <= 0) {
                        $bankAccountId = (int) $pdo->query("SELECT id FROM bank_accounts WHERE is_active = 1 LIMIT 1")->fetchColumn();
                    }
                    if ($bankAccountId > 0) {
                        $bankDesc = "Fee receipt {$receiptNo} - {$student['student_name']}";
                        $bankStmt = $pdo->prepare("SELECT COALESCE(current_balance,0) FROM bank_accounts WHERE id = :id");
                        $bankStmt->execute(['id' => $bankAccountId]);
                        $bankBal = (float) $bankStmt->fetchColumn();
                        $newBankBal = $bankBal + $netAmount;
                        $pdo->prepare(
                            "INSERT INTO bank_book
                             (bank_account_id, transaction_date, transaction_type, reference_type,
                              reference_id, description, amount, direction, balance, created_by, created_at)
                             VALUES (:bid, :dt, 'receipt', 'fee_collection', :rid, :desc, :amt, 'debit', :bal, :uid, NOW())"
                        )->execute([
                            'bid' => $bankAccountId,
                            'dt' => $paymentDate,
                            'rid' => $collectionId,
                            'desc' => $bankDesc,
                            'amt' => $netAmount,
                            'bal' => $newBankBal,
                            'uid' => (int) $user['id'],
                        ]);
                        $pdo->prepare("UPDATE bank_accounts SET current_balance = :bal WHERE id = :id")
                            ->execute(['bal' => $newBankBal, 'id' => $bankAccountId]);
                    }
                }

                $pdo->commit();
                $success = "Fee collected successfully. Receipt No: {$receiptNo}";
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to process fee collection: ' . $e->getMessage();
            }
        }

        header('Location: fee-collection-new.php');
        exit;
    }

    // ─── CANCEL COLLECTION ───
    if (isset($_POST['cancel_collection'])) {
        $collectionId = (int) ($_POST['collection_id'] ?? 0);
        $cancelReason = trim((string) ($_POST['cancel_reason'] ?? ''));

        if ($collectionId <= 0) {
            $error = 'Invalid collection.';
        } elseif ($cancelReason === '') {
            $error = 'Cancellation reason is required.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT id, student_id, academic_session, net_amount, status
                     FROM fee_collections WHERE id = :id"
                );
                $stmt->execute(['id' => $collectionId]);
                $collection = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$collection) {
                    throw new \RuntimeException('Collection not found.');
                }
                if ($collection['status'] !== 'Active') {
                    throw new \RuntimeException('Only active collections can be cancelled.');
                }

                // Update fee_collections
                $pdo->prepare(
                    "UPDATE fee_collections
                     SET status = 'Cancelled', cancel_reason = :reason,
                         cancelled_by = :uid, cancelled_at = NOW()
                     WHERE id = :id"
                )->execute([
                    'reason' => $cancelReason,
                    'uid' => (int) $user['id'],
                    'id' => $collectionId,
                ]);

                // Reverse student_fee_accounts
                $netAmount = (float) $collection['net_amount'];
                $stmt = $pdo->prepare(
                    "SELECT id, total_paid, balance
                     FROM student_fee_accounts
                     WHERE student_id = :sid AND academic_session = :sess LIMIT 1"
                );
                $stmt->execute(['sid' => $collection['student_id'], 'sess' => $collection['academic_session']]);
                $feeAccount = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($feeAccount) {
                    $newPaid = max(0, (float) $feeAccount['total_paid'] - $netAmount);
                    // Recalculate balance properly
                    $stmt2 = $pdo->prepare("SELECT total_fee FROM student_fee_accounts WHERE id = :id");
                    $stmt2->execute(['id' => $feeAccount['id']]);
                    $totalFee = (float) $stmt2->fetchColumn();
                    $newBalance = max(0, $totalFee - $newPaid);
                    $pdo->prepare(
                        "UPDATE student_fee_accounts
                         SET total_paid = :paid, balance = :bal, status = 'active'
                         WHERE id = :id"
                    )->execute([
                        'paid' => $newPaid,
                        'bal' => $newBalance,
                        'id' => $feeAccount['id'],
                    ]);
                }

                $pdo->commit();
                $success = 'Receipt cancelled successfully. Cash/bank book entries retained for audit trail.';
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to cancel receipt: ' . $e->getMessage();
            }
        }

        header('Location: fee-collection-new.php');
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════
// GET DATA
// ═══════════════════════════════════════════════════════════════════

// Student search
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$studentResults = [];
if ($searchQuery !== '') {
    $studentResults = student_search($pdo, $searchQuery);
}

// Selected student fee detail
$selectedStudentId = (int) ($_GET['student_id'] ?? 0);
$academicSession = trim((string) ($_GET['session'] ?? date('Y') . '-' . substr((string) ((int) date('Y') + 1), 2)));
$studentDetail = null;
if ($selectedStudentId > 0) {
    $studentDetail = student_fee_detail($pdo, $selectedStudentId, $academicSession);
}

// Payment modes & bank accounts
$paymentModes = payment_mode_options($pdo);
$bankAccounts = bank_account_options($pdo);

// Recent collections
$recentPage = max(1, (int) ($_GET['rp'] ?? 1));
$recentLimit = 20;
$recentOffset = ($recentPage - 1) * $recentLimit;

$recentCount = (int) $pdo->query("SELECT COUNT(*) FROM fee_collections")->fetchColumn();
$recentTotalPages = max(1, (int) ceil($recentCount / $recentLimit));

$recentStmt = $pdo->prepare(
    "SELECT fc.*
     FROM fee_collections fc
     ORDER BY fc.created_at DESC
     LIMIT :lim OFFSET :off"
);
$recentStmt->bindValue(':lim', $recentLimit, PDO::PARAM_INT);
$recentStmt->bindValue(':off', $recentOffset, PDO::PARAM_INT);
$recentStmt->execute();
$recentCollections = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Cancel modal data
$cancelId = (int) ($_GET['cancel'] ?? 0);
$cancelCollection = null;
if ($cancelId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM fee_collections WHERE id = :id AND status = 'Active'");
    $stmt->execute(['id' => $cancelId]);
    $cancelCollection = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fee Collection – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css?v=<?= filemtime(__DIR__ . '/../assets/erp-ui.css') ?>">
    <style>
        .search-box {
            display: flex; gap: .75rem; align-items: stretch;
        }
        .search-box input {
            flex: 1; padding: .65rem .85rem; border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: .95rem; min-width: 250px;
        }
        .search-box input:focus { outline: none; border-color: var(--brand, #2563eb); box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
        .student-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .75rem; margin-top: 1rem; }
        .student-card {
            padding: 1rem; border: 1px solid #e2e8f0; border-radius: 8px;
            background: #fff; cursor: pointer; transition: border-color .12s, box-shadow .12s;
            text-decoration: none; color: inherit; display: block;
        }
        .student-card:hover { border-color: var(--brand, #2563eb); box-shadow: 0 2px 8px rgba(37,99,235,.1); }
        .student-card .sc-name { font-weight: 700; font-size: .95rem; margin-bottom: .25rem; }
        .student-card .sc-meta { font-size: .8rem; color: #64748b; display: flex; gap: .5rem; }

        .student-info-bar {
            display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: center;
            padding: .85rem 1rem; background: #f0f4ff; border: 1px solid #c7d2fe;
            border-radius: 8px; margin-bottom: 1rem;
        }
        .student-info-bar .sib-item { font-size: .85rem; }
        .student-info-bar .sib-item strong { color: #1e293b; }

        .fee-detail-grid {
            display: grid; grid-template-columns: 1.4fr 1fr; gap: 1.25rem; align-items: start;
        }
        @media (max-width: 900px) { .fee-detail-grid { grid-template-columns: 1fr; } }

        .outstanding-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .outstanding-table thead th {
            text-align: left; padding: .55rem .65rem; background: #f8fafc;
            border-bottom: 2px solid #e2e8f0; color: #64748b; font-weight: 600; white-space: nowrap;
        }
        .outstanding-table tbody td {
            padding: .5rem .65rem; border-bottom: 1px solid #f1f5f9;
        }
        .outstanding-table tbody tr:hover td { background: #eff6ff; }
        .outstanding-table tfoot td {
            padding: .65rem .65rem; border-top: 2px solid #e2e8f0; font-weight: 700; background: #f8fafc;
        }

        .form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: .85rem;
        }
        .form-grid .full-col { grid-column: 1 / -1; }
        .form-grid label {
            display: block; font-size: .78rem; font-weight: 600; color: #64748b;
            text-transform: uppercase; letter-spacing: .03em; margin-bottom: .25rem;
        }
        .form-grid input, .form-grid select, .form-grid textarea {
            width: 100%; padding: .5rem .65rem; border: 1px solid #cbd5e1; border-radius: 6px;
            font-size: .875rem; box-sizing: border-box;
        }
        .form-grid input:focus, .form-grid select:focus, .form-grid textarea:focus {
            outline: none; border-color: var(--brand, #2563eb); box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }
        .form-grid textarea { min-height: 60px; resize: vertical; }

        .net-display {
            font-size: 1.4rem; font-weight: 700; color: #1e293b;
            text-align: right; padding: .75rem 0; border-top: 1px solid #e2e8f0; margin-top: .75rem;
        }

        .app-table { width: 100%; table-layout: auto; border-collapse: collapse; font-size: .82rem; }
        .app-table thead th {
            text-align: left; padding: .55rem .65rem; background: #f8fafc;
            border-bottom: 2px solid #e2e8f0; color: #64748b; font-weight: 600; white-space: nowrap;
        }
        .app-table tbody td { padding: .55rem .65rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .app-table tbody tr:nth-child(even) td { background: #fafbfc; }
        .app-table tbody tr:hover td { background: #eff6ff; }

        .status-badge {
            display: inline-block; padding: .2rem .55rem; border-radius: 999px;
            font-size: .72rem; font-weight: 600;
        }
        .status-Active { background: #d1fae5; color: #065f46; }
        .status-Cancelled { background: #fee2e2; color: #991b1b; }
        .status-Void { background: #f3f4f6; color: #6b7280; }

        .cheque-fields { display: none; }

        .pagination { display: flex; gap: .4rem; align-items: center; margin-top: 1rem; }
        .pagination a, .pagination span {
            padding: .3rem .65rem; border: 1px solid #e2e8f0; border-radius: 6px;
            text-decoration: none; font-size: .82rem; color: #334155;
        }
        .pagination a:hover { background: #f1f5f9; }
        .pagination .current { background: #1e293b; color: #fff; border-color: #1e293b; }

        .empty-state {
            padding: 2.5rem; text-align: center; color: #94a3b8;
            border: 1px dashed #e2e8f0; border-radius: 8px;
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .student-cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body style="min-height:100vh;">
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">

        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Fee Collection</h1>
                    <p>Search for a student, review outstanding fees, and collect payment.</p>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- ═══════════════ SECTION 1: Student Search ═══════════════ -->
        <section class="panel" style="padding:1.25rem;margin-bottom:1.25rem;">
            <div class="section-title" style="margin-bottom:.85rem;">
                <div>
                    <h2 style="font-size:1.05rem;">Find Student</h2>
                    <p style="font-size:.82rem;">Search by Student ID, Admission No, Name, or Class</p>
                </div>
            </div>
            <form method="get" class="search-box">
                <input type="text" name="q" placeholder="e.g. 101, ADM-2025-001, Rahul, Class 5..."
                       value="<?= e($searchQuery) ?>" autofocus>
                <button type="submit" class="btn" style="padding:.6rem 1.5rem;">Search</button>
                <?php if ($selectedStudentId > 0): ?>
                    <a href="fee-collection-new.php" class="btn btn-soft" style="padding:.6rem 1rem;text-decoration:none;">Clear Selection</a>
                <?php endif; ?>
            </form>

            <?php if ($searchQuery !== '' && empty($studentResults)): ?>
                <div class="empty-state" style="margin-top:1rem;">No students found for "<strong><?= e($searchQuery) ?></strong>".</div>
            <?php elseif (!empty($studentResults)): ?>
                <div class="student-cards">
                    <?php foreach ($studentResults as $s): ?>
                        <a href="?q=<?= urlencode($searchQuery) ?>&student_id=<?= (int) $s['id'] ?>&session=<?= urlencode($academicSession) ?>" class="student-card">
                            <div class="sc-name"><?= e($s['student_name']) ?></div>
                            <div class="sc-meta">
                                <span><?= e($s['admission_no'] ?: '—') ?></span>
                                <span><?= e($s['class_sought']) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ═══════════════ SECTION 2: Fee Collection Form ═══════════════ -->
        <?php if ($studentDetail && $studentDetail['student']): ?>
        <?php $stud = $studentDetail['student']; ?>
        <?php $feeAcc = $studentDetail['fee_account']; ?>
        <?php $feeItems = $studentDetail['fee_items']; ?>
        <?php $paidItems = $studentDetail['paid_items']; ?>

        <section class="panel" style="padding:1.25rem;margin-bottom:1.25rem;">
            <div class="section-title" style="margin-bottom:.85rem;">
                <div>
                    <h2 style="font-size:1.05rem;">Collect Fee</h2>
                </div>
            </div>

            <div class="student-info-bar">
                <div class="sib-item"><strong><?= e($stud['student_name']) ?></strong></div>
                <div class="sib-item">Admission: <strong><?= e($stud['admission_no'] ?: '—') ?></strong></div>
                <div class="sib-item">Class: <strong><?= e($stud['class_sought']) ?></strong></div>
                <div class="sib-item">Session: <strong><?= e($academicSession) ?></strong></div>
                <?php if ($feeAcc): ?>
                    <div class="sib-item">Outstanding: <strong style="color:#dc2626;">Rs. <?= number_format((float) $feeAcc['balance'], 2) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (empty($feeItems)): ?>
                <div class="empty-state">No fee structure items found for this class. Please assign a fee structure first.</div>
            <?php else: ?>
            <form method="post" id="fee-collection-form">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="collect_fee" value="1">
                <input type="hidden" name="student_id" value="<?= (int) $stud['id'] ?>">

                <div class="fee-detail-grid">
                    <!-- Left: Outstanding Table -->
                    <div>
                        <table class="outstanding-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;">
                                        <input type="checkbox" id="select-all-items" checked onchange="toggleAllItems(this)" title="Select/Deselect All">
                                    </th>
                                    <th>Fee Head</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th style="text-align:right;">Paid</th>
                                    <th style="text-align:right;">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $grandTotal = 0; $grandPaid = 0; ?>
                                <?php foreach ($feeItems as $fi):
                                    $headId = (int) $fi['fee_head_id'];
                                    $amt = (float) $fi['structure_amount'];
                                    $paid = (float) ($paidItems[$fi['fee_head_name']] ?? 0);
                                    $bal = max(0, $amt - $paid);
                                    $grandTotal += $amt;
                                    $grandPaid += $paid;
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="fee_items[<?= $headId ?>]" value="1"
                                               class="fee-item-cb" data-head-id="<?= $headId ?>"
                                               <?= $bal > 0 ? 'checked' : '' ?>
                                               onchange="recalcNet()">
                                    </td>
                                    <td><strong><?= e($fi['fee_head_name']) ?></strong></td>
                                    <td style="text-align:right;">Rs. <?= number_format($amt, 2) ?></td>
                                    <td style="text-align:right;color:#64748b;">Rs. <?= number_format($paid, 2) ?></td>
                                    <td style="text-align:right;font-weight:600;<?= $bal > 0 ? 'color:#dc2626;' : 'color:#16a34a;' ?>">
                                        Rs. <?= number_format($bal, 2) ?>
                                    </td>
                                </tr>
                                <input type="hidden" name="fee_amounts[<?= $headId ?>]" value="<?= number_format($bal, 2, '.', '') ?>"
                                       class="fee-amount-input" data-head-id="<?= $headId ?>" data-balance="<?= $bal ?>">
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" style="text-align:right;">Total:</td>
                                    <td style="text-align:right;">Rs. <?= number_format($grandTotal, 2) ?></td>
                                    <td style="text-align:right;color:#64748b;">Rs. <?= number_format($grandPaid, 2) ?></td>
                                    <td style="text-align:right;color:#dc2626;">Rs. <?= number_format(max(0, $grandTotal - $grandPaid), 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Right: Payment Form -->
                    <div>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;">
                            <div style="font-weight:700;font-size:.9rem;color:#334155;margin-bottom:.85rem;">Payment Details</div>

                            <div class="form-grid">
                                <div>
                                    <label for="academic_session">Academic Session</label>
                                    <input type="text" id="academic_session" name="academic_session"
                                           value="<?= e($academicSession) ?>" required>
                                </div>
                                <div>
                                    <label for="fee_period">Fee Period</label>
                                    <input type="text" id="fee_period" name="fee_period"
                                           placeholder="e.g. Apr-Jun 2025">
                                </div>
                                <div>
                                    <label for="payment_mode">Payment Mode *</label>
                                    <select id="payment_mode" name="payment_mode" required onchange="handlePaymentMode(this.value)">
                                        <option value="">— Select —</option>
                                        <?php foreach ($paymentModes as $pm): ?>
                                            <option value="<?= e($pm['name']) ?>"
                                                    data-cheque="<?= $pm['cheque_details_required'] ? '1' : '0' ?>">
                                                <?= e($pm['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="payment_date">Payment Date *</label>
                                    <input type="date" id="payment_date" name="payment_date"
                                           value="<?= e(date('Y-m-d')) ?>" required>
                                </div>
                                <div>
                                    <label for="transaction_id">Transaction ID / UTR</label>
                                    <input type="text" id="transaction_id" name="transaction_id"
                                           placeholder="Optional">
                                </div>
                                <div>
                                    <label for="bank_account_id">Bank Account</label>
                                    <select id="bank_account_id" name="bank_account_id">
                                        <option value="0">— Auto-detect —</option>
                                        <?php foreach ($bankAccounts as $ba): ?>
                                            <option value="<?= (int) $ba['id'] ?>">
                                                <?= e($ba['bank_name'] . ' - ' . $ba['account_name']) ?>
                                                (Rs. <?= number_format((float) $ba['current_balance'], 2) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Cheque fields -->
                            <div class="cheque-fields" id="cheque-fields" style="margin-top:.85rem;">
                                <div style="font-weight:600;font-size:.82rem;color:#64748b;margin-bottom:.5rem;">Cheque Details</div>
                                <div class="form-grid">
                                    <div>
                                        <label for="cheque_no">Cheque No *</label>
                                        <input type="text" id="cheque_no" name="cheque_no" placeholder="Cheque number">
                                    </div>
                                    <div>
                                        <label for="cheque_date">Cheque Date *</label>
                                        <input type="date" id="cheque_date" name="cheque_date">
                                    </div>
                                    <div>
                                        <label for="cheque_bank">Bank Name *</label>
                                        <input type="text" id="cheque_bank" name="cheque_bank" placeholder="e.g. SBI, HDFC">
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid" style="margin-top:.85rem;">
                                <div>
                                    <label for="discount_amount">Discount (Rs.)</label>
                                    <input type="number" id="discount_amount" name="discount_amount"
                                           value="0" step="0.01" min="0" onchange="recalcNet()" onkeyup="recalcNet()">
                                </div>
                                <div>
                                    <label for="late_fee">Late Fee (Rs.)</label>
                                    <input type="number" id="late_fee" name="late_fee"
                                           value="0" step="0.01" min="0" onchange="recalcNet()" onkeyup="recalcNet()">
                                </div>
                                <div>
                                    <label for="payee_name">Payee Name</label>
                                    <input type="text" id="payee_name" name="payee_name"
                                           placeholder="Name of person paying">
                                </div>
                                <div>
                                    <label for="collector_name">Collector Name</label>
                                    <input type="text" id="collector_name" name="collector_name"
                                           value="<?= e($user['name'] ?? '') ?>">
                                </div>
                                <div class="full-col">
                                    <label for="notes">Remarks</label>
                                    <textarea id="notes" name="notes" placeholder="Optional notes..."></textarea>
                                </div>
                            </div>

                            <div class="net-display">
                                Net Amount: Rs. <span id="net-amount">0.00</span>
                            </div>

                            <button type="submit" class="btn" id="collect-btn"
                                    style="width:100%;padding:.7rem;font-size:1rem;font-weight:600;margin-top:.5rem;">
                                Collect Fee &amp; Generate Receipt
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ═══════════════ SECTION 3: Recent Collections ═══════════════ -->
        <section class="panel" style="padding:1.25rem;">
            <div class="section-title" style="margin-bottom:1rem;">
                <div>
                    <h2 style="font-size:1.05rem;">Recent Collections</h2>
                    <p style="font-size:.82rem;"><?= $recentCount ?> collection<?= $recentCount !== 1 ? 's' : '' ?> total</p>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="app-table" id="recent-table">
                    <thead>
                        <tr>
                            <th style="cursor:pointer;" onclick="sortTable(0)">Receipt No ↕</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th style="cursor:pointer;" onclick="sortTable(3)">Amount ↕</th>
                            <th>Mode</th>
                            <th style="cursor:pointer;" onclick="sortTable(5)">Date ↕</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentCollections)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8;">No collections yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentCollections as $c): ?>
                                <tr>
                                    <td style="font-family:monospace;font-size:.78rem;"><?= e($c['receipt_no']) ?></td>
                                    <td><strong><?= e($c['student_name']) ?></strong></td>
                                    <td><?= e($c['class_name']) ?></td>
                                    <td>Rs. <?= number_format((float) $c['net_amount'], 2) ?></td>
                                    <td><span class="status-badge" style="background:#e0e7ff;color:#3730a3;"><?= e($c['payment_mode']) ?></span></td>
                                    <td style="white-space:nowrap;"><?= e($c['payment_date']) ?></td>
                                    <td><span class="status-badge status-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
                                    <td>
                                        <?php if ($c['status'] === 'Active'): ?>
                                            <button type="button" class="btn btn-soft" style="font-size:.72rem;padding:.2rem .5rem;border-radius:6px;"
                                                    onclick="openCancelModal(<?= (int) $c['id'] ?>, '<?= e($c['receipt_no']) ?>', '<?= e($c['student_name']) ?>', <?= (float) $c['net_amount'] ?>)">
                                                Cancel
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:.78rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($recentTotalPages > 1): ?>
                <div class="pagination">
                    <?php if ($recentPage > 1): ?>
                        <a href="?rp=<?= $recentPage - 1 ?>">‹ Prev</a>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $recentPage - 3);
                    $end = min($recentTotalPages, $recentPage + 3);
                    if ($start > 1): ?>
                        <a href="?rp=1">1</a>
                        <?php if ($start > 2): ?><span>…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i === $recentPage): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?rp=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($end < $recentTotalPages): ?>
                        <?php if ($end < $recentTotalPages - 1): ?><span>…</span><?php endif; ?>
                        <a href="?rp=<?= $recentTotalPages ?>"><?= $recentTotalPages ?></a>
                    <?php endif; ?>
                    <?php if ($recentPage < $recentTotalPages): ?>
                        <a href="?rp=<?= $recentPage + 1 ?>">Next ›</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

    </main>
</div>

<!-- ═══════════════ MODAL: Cancel Collection ═══════════════ -->
<div id="cancelModal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <h2>Cancel Receipt</h2>
            <button type="button" class="icon-btn" onclick="closeCancelModal()">&times;</button>
        </div>
        <div style="padding:1rem;">
            <p style="margin-bottom:1rem;color:#64748b;font-size:.9rem;">
                Receipt: <strong id="cancel-receipt-no"></strong> —
                Student: <strong id="cancel-student-name"></strong> —
                Amount: Rs. <strong id="cancel-amount"></strong>
            </p>
            <form method="post" id="cancel-form">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="cancel_collection" value="1">
                <input type="hidden" name="collection_id" id="cancel-collection-id" value="0">
                <div style="margin-bottom:1rem;">
                    <label for="cancel_reason" style="display:block;font-size:.82rem;font-weight:600;color:#64748b;margin-bottom:.35rem;">Cancellation Reason *</label>
                    <textarea id="cancel_reason" name="cancel_reason" required
                              placeholder="Provide a reason for cancellation..."
                              style="width:100%;padding:.55rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;min-height:80px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
                <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                    <button type="button" class="btn btn-soft" onclick="closeCancelModal()">Close</button>
                    <button type="submit" class="btn" style="background:#dc2626;color:#fff;border:none;"
                            onclick="return confirm('Are you sure you want to cancel this receipt?')">
                        Confirm Cancellation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/erp.js?v=<?= filemtime(dirname(__DIR__) . '/assets/erp.js') ?>"></script>
<script>
function handlePaymentMode(mode) {
    var el = document.getElementById('cheque-fields');
    var opt = document.querySelector('#payment_mode option[value="' + mode + '"]');
    if (el && opt) {
        el.style.display = opt.getAttribute('data-cheque') === '1' ? 'block' : 'none';
    }
}

function toggleAllItems(cb) {
    document.querySelectorAll('.fee-item-cb').forEach(function(c) {
        c.checked = cb.checked;
    });
    recalcNet();
}

function recalcNet() {
    var total = 0;
    document.querySelectorAll('.fee-item-cb').forEach(function(cb) {
        if (cb.checked) {
            var headId = cb.getAttribute('data-head-id');
            var inp = document.querySelector('.fee-amount-input[data-head-id="' + headId + '"]');
            if (inp) {
                total += parseFloat(inp.value) || 0;
            }
        }
    });
    var lateFee = parseFloat(document.getElementById('late_fee').value) || 0;
    var discount = parseFloat(document.getElementById('discount_amount').value) || 0;
    var net = total + lateFee - discount;
    if (net < 0) net = 0;
    document.getElementById('net-amount').textContent = net.toFixed(2);
}

function openCancelModal(id, receiptNo, studentName, amount) {
    document.getElementById('cancel-collection-id').value = id;
    document.getElementById('cancel-receipt-no').textContent = receiptNo;
    document.getElementById('cancel-student-name').textContent = studentName;
    document.getElementById('cancel-amount').textContent = amount.toFixed(2);
    document.getElementById('cancelModal').classList.add('show');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('show');
    document.getElementById('cancel_reason').value = '';
}

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(function(bd) {
    bd.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('show');
    });
});

// Sort recent collections table
function sortTable(colIdx) {
    var table = document.getElementById('recent-table');
    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var asc = table.getAttribute('data-sort-dir') !== 'asc';
    table.setAttribute('data-sort-dir', asc ? 'asc' : 'desc');

    rows.sort(function(a, b) {
        var aVal = (a.cells[colIdx].textContent || '').trim();
        var bVal = (b.cells[colIdx].textContent || '').trim();
        // Try numeric sort
        var aNum = parseFloat(aVal.replace(/[^\d.\-]/g, ''));
        var bNum = parseFloat(bVal.replace(/[^\d.\-]/g, ''));
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return asc ? aNum - bNum : bNum - aNum;
        }
        return asc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
    });

    rows.forEach(function(row) { tbody.appendChild(row); });
}

// Init
document.addEventListener('DOMContentLoaded', function() {
    var pm = document.getElementById('payment_mode');
    if (pm) handlePaymentMode(pm.value);
    recalcNet();
});
</script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
