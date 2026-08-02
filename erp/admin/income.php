<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$pdo = $GLOBALS['pdo'];

$error = '';
$success = '';

$pdo->exec("CREATE TABLE IF NOT EXISTS income_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS income_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    income_type ENUM('donation','grant','miscellaneous','other') NOT NULL DEFAULT 'other',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    receipt_no VARCHAR(100),
    donor_name VARCHAR(255),
    donor_contact VARCHAR(100),
    description TEXT,
    payment_mode VARCHAR(50),
    payment_date DATE,
    transaction_reference VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

try { $pdo->exec("ALTER TABLE income_categories ADD INDEX idx_name (name)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE income_records ADD INDEX idx_income_type (income_type)"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE income_records ADD INDEX idx_payment_date (payment_date)"); } catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (in_array($action, ['add', 'edit'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
        $incomeType = trim((string) ($_POST['income_type'] ?? 'other'));
        $amount = (float) ($_POST['amount'] ?? 0);
        $receiptNo = trim((string) ($_POST['receipt_no'] ?? ''));
        $donorName = trim((string) ($_POST['donor_name'] ?? ''));
        $donorContact = trim((string) ($_POST['donor_contact'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $paymentMode = trim((string) ($_POST['payment_mode'] ?? ''));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        $transactionRef = trim((string) ($_POST['transaction_reference'] ?? ''));

        if ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE income_records SET category_id=?, income_type=?, amount=?, receipt_no=?, donor_name=?, donor_contact=?, description=?, payment_mode=?, payment_date=?, transaction_reference=? WHERE id=?");
                $stmt->execute([$categoryId, $incomeType, $amount, $receiptNo ?: null, $donorName ?: null, $donorContact ?: null, $description ?: null, $paymentMode ?: null, $paymentDate ?: null, $transactionRef ?: null, $id]);
                $success = 'Income record updated successfully.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO income_records (category_id, income_type, amount, receipt_no, donor_name, donor_contact, description, payment_mode, payment_date, transaction_reference, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$categoryId, $incomeType, $amount, $receiptNo ?: null, $donorName ?: null, $donorContact ?: null, $description ?: null, $paymentMode ?: null, $paymentDate ?: null, $transactionRef ?: null, (int) ($user['id'] ?? 0)]);
                $success = 'Income record added successfully.';
            }
        }
    }

    if ($action === 'delete' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $pdo->prepare("DELETE FROM income_records WHERE id=?")->execute([$id]);
        $success = 'Income record deleted successfully.';
    }
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM income_records WHERE id=?");
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $error = 'Income record not found.';
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

$catFilter = (int) ($_GET['category'] ?? 0);
if ($catFilter > 0) {
    $where[] = 'r.category_id = :category';
    $params[':category'] = $catFilter;
}

$typeFilter = trim((string) ($_GET['income_type'] ?? ''));
$allowedTypes = ['donation', 'grant', 'miscellaneous', 'other'];
if ($typeFilter !== '' && in_array($typeFilter, $allowedTypes, true)) {
    $where[] = 'r.income_type = :income_type';
    $params[':income_type'] = $typeFilter;
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
if ($dateFrom !== '') {
    $where[] = 'r.payment_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}

$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($dateTo !== '') {
    $where[] = 'r.payment_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM income_records r $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));

$stmt = $pdo->prepare("SELECT r.*, ic.name AS category_name FROM income_records r LEFT JOIN income_categories ic ON ic.id = r.category_id $whereClause ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catStmt = $pdo->query("SELECT id, name FROM income_categories ORDER BY name");
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$showForm = isset($_GET['action']) && $_GET['action'] === 'add';
$formAction = $editRow ? 'edit' : ($showForm ? 'add' : '');
$formTitle = $editRow ? 'Edit Income' : ($showForm ? 'Add Income' : '');

$incomeTypeLabels = [
    'donation' => 'Donation',
    'grant' => 'Grant',
    'miscellaneous' => 'Miscellaneous',
    'other' => 'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Income – SIBA ERP</title>
    <link rel="stylesheet" href="../assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/erp-ui.css">
    <style>
        .tab-bar { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:2px solid #e5e7eb; }
        .tab-bar a { padding:.6rem 1.5rem; font-size:.9rem; font-weight:500; color:var(--text-light); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab-bar a.active { color:var(--primary-color); border-bottom-color:var(--primary-color); }
        .tab-bar a:hover { color:var(--primary-color); }
        .action-btns { display:flex; gap:.4rem; align-items:center; flex-wrap:wrap; }
        .filter-bar { display:flex; align-items:flex-end; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
        .filter-bar label { font-size:.78rem; margin-bottom:.2rem; }
        .filter-bar input, .filter-bar select { min-height:36px; padding:.4rem .6rem; border-radius:8px; }
        .page-links { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
        .page-links a, .page-links span { min-height:34px; padding:.38rem .65rem; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; text-decoration:none; font-size:.82rem; }
        .page-links a:hover { background:#f1f5f9; }
        .page-links .active { background:#64748b; border-color:#64748b; color:#fff; }
        .filter-group { display:flex; flex-direction:column; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $activePage = basename(__FILE__); include __DIR__ . '/_sidebar.php'; ?>

    <main class="admin-main stack" style="padding:1.5rem;">
        <section class="hero-banner" style="margin-bottom:1rem;">
            <div class="toolbar">
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Finance</span>
                    <h1>Income Records</h1>
                    <p>Manage donations, grants, and other income.</p>
                </div>
                <div class="toolbar-right">
                    <?php if (!$editRow && !$showForm): ?>
                        <a href="?action=add" class="btn btn-sm" style="background:#059669;color:#fff;border:none;padding:.5rem 1rem;font-size:.85rem;border-radius:10px;text-decoration:none;">+ Add Income</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($error): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:.75rem 1rem;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem 1rem;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($formAction === 'add' || $editRow): ?>
            <section class="panel" style="padding:1.25rem;margin-bottom:1.5rem;">
                <div class="section-title">
                    <div>
                        <h2><?= $formTitle ?></h2>
                        <p>Fill in the details below.</p>
                    </div>
                    <a href="income.php" class="btn btn-sm btn-outline" style="padding:.4rem .8rem;font-size:.8rem;border-radius:8px;text-decoration:none;">← Back</a>
                </div>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $formAction ?>">
                    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

                    <div class="field-grid">
                        <div>
                            <label for="category_id">Category</label>
                            <select name="category_id" id="category_id">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int) $cat['id'] ?>" <?= (isset($editRow['category_id']) && (int) $editRow['category_id'] === (int) $cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="income_type">Income Type *</label>
                            <select name="income_type" id="income_type" required>
                                <option value="">-- Select Type --</option>
                                <?php foreach ($incomeTypeLabels as $val => $label): ?>
                                    <option value="<?= e($val) ?>" <?= (($editRow['income_type'] ?? '') === $val) ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="amount">Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0.01" name="amount" id="amount" required value="<?= e((string) ($editRow['amount'] ?? '')) ?>">
                        </div>
                        <div>
                            <label for="receipt_no">Receipt No</label>
                            <input type="text" name="receipt_no" id="receipt_no" value="<?= e($editRow['receipt_no'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="donor_name">Donor Name</label>
                            <input type="text" name="donor_name" id="donor_name" value="<?= e($editRow['donor_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="donor_contact">Donor Contact</label>
                            <input type="text" name="donor_contact" id="donor_contact" value="<?= e($editRow['donor_contact'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="payment_mode">Payment Mode</label>
                            <select name="payment_mode" id="payment_mode">
                                <option value="">-- Select --</option>
                                <option value="Cash" <?= ($editRow['payment_mode'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="Cheque" <?= ($editRow['payment_mode'] ?? '') === 'Cheque' ? 'selected' : '' ?>>Cheque</option>
                                <option value="Bank Transfer" <?= ($editRow['payment_mode'] ?? '') === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="Online" <?= ($editRow['payment_mode'] ?? '') === 'Online' ? 'selected' : '' ?>>Online</option>
                                <option value="Card" <?= ($editRow['payment_mode'] ?? '') === 'Card' ? 'selected' : '' ?>>Card</option>
                            </select>
                        </div>
                        <div>
                            <label for="payment_date">Payment Date</label>
                            <input type="date" name="payment_date" id="payment_date" value="<?= e($editRow['payment_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="transaction_reference">Transaction Reference</label>
                            <input type="text" name="transaction_reference" id="transaction_reference" value="<?= e($editRow['transaction_reference'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="field-grid" style="margin-top:1rem;">
                        <div>
                            <label for="description">Description</label>
                            <textarea name="description" id="description" rows="3"><?= e($editRow['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div style="margin-top:1rem;display:flex;gap:.75rem;">
                        <button type="submit" class="btn" style="background:#2563eb;padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;"><?= $editRow ? 'Update Income' : 'Add Income' ?></button>
                        <a href="income.php" class="btn btn-outline" style="padding:.6rem 1.5rem;min-height:auto;font-size:.9rem;text-decoration:none;">Cancel</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$editRow): ?>
            <div class="filter-bar">
                <form method="get" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;width:100%;">
                    <div class="filter-group">
                        <label for="f_category">Category</label>
                        <select name="category" id="f_category" style="min-width:150px;">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>" <?= ($catFilter === (int) $cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="f_income_type">Income Type</label>
                        <select name="income_type" id="f_income_type" style="min-width:130px;">
                            <option value="">All Types</option>
                            <?php foreach ($incomeTypeLabels as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($typeFilter === $val) ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="f_date_from">From Date</label>
                        <input type="date" name="date_from" id="f_date_from" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="f_date_to">To Date</label>
                        <input type="date" name="date_to" id="f_date_to" value="<?= e($dateTo) ?>">
                    </div>
                    <div class="filter-group" style="align-self:flex-end;">
                        <button type="submit" class="btn" style="background:#64748b;padding:.45rem 1rem;min-height:36px;font-size:.85rem;border-radius:8px;">Filter</button>
                        <a href="income.php" style="font-size:.85rem;color:#64748b;margin-left:.5rem;">Clear</a>
                    </div>
                </form>
            </div>

            <section class="panel" style="padding:1.25rem;">
                <?php if (empty($rows)): ?>
                    <p style="text-align:center;padding:2rem;color:var(--text-light);">No income records found.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Receipt No</th>
                                    <th>Donor Name</th>
                                    <th>Mode</th>
                                    <th>Payment Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = $offset + 1; foreach ($rows as $r): ?>
                                    <tr>
                                        <td style="color:#94a3b8;"><?= $i++ ?></td>
                                        <td><?= e($r['category_name'] ?? '—') ?></td>
                                        <td><span style="background:#eef7f2;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;"><?= e($incomeTypeLabels[$r['income_type']] ?? $r['income_type']) ?></span></td>
                                        <td><strong>Rs. <?= number_format((float) $r['amount'], 2) ?></strong></td>
                                        <td><?= e($r['receipt_no'] ?? '—') ?></td>
                                        <td><?= e($r['donor_name'] ?? '—') ?></td>
                                        <td><?= e($r['payment_mode'] ?? '—') ?></td>
                                        <td style="white-space:nowrap;"><?= e($r['payment_date'] ?? '—') ?></td>
                                        <td>
                                            <div class="action-btns">
                                                <a href="?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline" style="padding:.25rem .6rem;font-size:.75rem;border-radius:6px;text-decoration:none;">Edit</a>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this income record permanently?')">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:.25rem .6rem;font-size:.75rem;border-radius:6px;cursor:pointer;">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination" style="margin-top:1rem;">
                            <div style="font-size:.85rem;color:#64748b;">Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?></div>
                            <div class="page-links">
                                <?php if ($page > 1): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Prev</a>
                                <?php endif; ?>
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($p = $startPage; $p <= $endPage; $p++):
                                ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<script src="../assets/erp.js?v=<?php echo filemtime(dirname(__DIR__) . '/assets/erp.js'); ?>"></script>
<?php include __DIR__ . '/_theme-js.php'; ?>
</body>
</html>
