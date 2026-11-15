<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();
$isOwner = ($user['role'] ?? '') === 'owner';
$canDelete = can_user_delete($pdo, (int)$user['id'], 'inventory');

$pageTitle = 'Inventory Management (Stationery & Consumables)';
$error = '';
$success = '';

// ── Ensure tables and required columns exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Pre-populate standard categories if empty
    $catCount = (int) $pdo->query("SELECT COUNT(*) FROM inventory_categories")->fetchColumn();
    if ($catCount === 0) {
        $pdo->exec("INSERT INTO inventory_categories (category_name, description) VALUES
            ('Stationery', 'Pens, papers, notebooks, files, registers, whiteboard supplies'),
            ('Office Consumables', 'Printer cartridges, toners, staplers, envelopes, stamps'),
            ('Classroom Supplies', 'Chalks, dusters, charts, markers, art materials'),
            ('Cleaning & Hygiene', 'Sanitizers, soaps, disinfectants, tissues, dustbins'),
            ('First Aid & Medical', 'Bandages, antiseptics, cotton, basic medicines')");
    }
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL DEFAULT 1,
        item_code VARCHAR(50) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        category VARCHAR(100) DEFAULT 'Stationery',
        unit VARCHAR(50) DEFAULT 'Pcs',
        opening_quantity DECIMAL(12,2) DEFAULT 0,
        current_quantity DECIMAL(12,2) DEFAULT 0,
        quantity INT DEFAULT 0,
        reorder_level DECIMAL(12,2) DEFAULT 0,
        purchase_rate DECIMAL(12,2) DEFAULT 0,
        unit_price DECIMAL(10,2) DEFAULT 0,
        store_location VARCHAR(255) DEFAULT '',
        status VARCHAR(50) DEFAULT 'In Stock',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_item_code (item_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        transaction_type ENUM('Purchase','Issue','Return','Opening','Adjustment') NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        department VARCHAR(255) DEFAULT '',
        issued_to VARCHAR(255) DEFAULT '',
        transaction_date DATE NOT NULL,
        remarks TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_it_item (item_id),
        KEY idx_it_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Ensure columns on inventory_items
ensure_columns($pdo, 'inventory_items', [
    'category' => "VARCHAR(100) DEFAULT 'Stationery'",
    'unit' => "VARCHAR(50) DEFAULT 'Pcs'",
    'opening_quantity' => "DECIMAL(12,2) DEFAULT 0",
    'current_quantity' => "DECIMAL(12,2) DEFAULT 0",
    'quantity' => "INT DEFAULT 0",
    'reorder_level' => "DECIMAL(12,2) DEFAULT 0",
    'purchase_rate' => "DECIMAL(12,2) DEFAULT 0",
    'unit_price' => "DECIMAL(10,2) DEFAULT 0",
    'store_location' => "VARCHAR(255) DEFAULT ''",
    'status' => "VARCHAR(50) DEFAULT 'In Stock'",
    'is_active' => "TINYINT(1) DEFAULT 1",
]);

// ── Export CSV Handler ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportItems = $pdo->query("SELECT item_code, item_name, category, unit, current_quantity, reorder_level, purchase_rate, (current_quantity * purchase_rate) as total_val, store_location, status 
                                FROM inventory_items WHERE is_active = 1 ORDER BY item_code ASC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_stock_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Item Code', 'Item Name', 'Category', 'Unit', 'Current Quantity', 'Reorder Level', 'Rate (Rs)', 'Total Value (Rs)', 'Store Location', 'Stock Status']);
    foreach ($exportItems as $row) {
        fputcsv($out, [
            $row['item_code'],
            $row['item_name'],
            $row['category'],
            $row['unit'],
            $row['current_quantity'],
            $row['reorder_level'],
            $row['purchase_rate'],
            $row['total_val'],
            $row['store_location'],
            $row['status']
        ]);
    }
    fclose($out);
    exit;
}

// ── POST Handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_item') {
            $itemCode = strtoupper(trim((string) ($_POST['item_code'] ?? '')));
            $itemName = trim((string) ($_POST['item_name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'Stationery'));
            $unit = trim((string) ($_POST['unit'] ?? 'Pcs'));
            $openingQty = max(0, (float) ($_POST['opening_quantity'] ?? 0));
            $reorderLevel = max(0, (float) ($_POST['reorder_level'] ?? 0));
            $purchaseRate = max(0, (float) ($_POST['purchase_rate'] ?? 0));
            $storeLocation = trim((string) ($_POST['store_location'] ?? ''));

            if ($itemCode === '' || $itemName === '') {
                throw new \RuntimeException('Item code and Item name are required.');
            }

            // Check duplicate code
            $dup = $pdo->prepare("SELECT id FROM inventory_items WHERE item_code = ? AND is_active = 1");
            $dup->execute([$itemCode]);
            if ($dup->fetch()) {
                throw new \RuntimeException("Item code '{$itemCode}' already exists.");
            }

            // Resolve category_id
            $catStmt = $pdo->prepare("SELECT id FROM inventory_categories WHERE category_name = ? LIMIT 1");
            $catStmt->execute([$category]);
            $catId = (int) ($catStmt->fetchColumn() ?: 1);

            $status = ($openingQty <= $reorderLevel && $reorderLevel > 0) ? 'Low Stock' : ($openingQty > 0 ? 'In Stock' : 'Out of Stock');

            $stmt = $pdo->prepare("INSERT INTO inventory_items 
                (category_id, item_code, item_name, category, unit, opening_quantity, current_quantity, quantity, reorder_level, purchase_rate, unit_price, store_location, status, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([
                $catId, $itemCode, $itemName, $category, $unit,
                $openingQty, $openingQty, (int)$openingQty,
                $reorderLevel, $purchaseRate, $purchaseRate,
                $storeLocation, $status
            ]);

            $newItemId = (int) $pdo->lastInsertId();
            if ($openingQty > 0) {
                $pdo->prepare("INSERT INTO inventory_transactions (item_id, transaction_type, quantity, department, issued_to, transaction_date, remarks, created_by) VALUES (?, 'Opening', ?, 'Store', 'Initial Stock', CURDATE(), 'Opening stock entry', ?)")
                    ->execute([$newItemId, $openingQty, (int)($user['id'] ?? 0)]);
            }

            $success = "Item '{$itemName}' ({$itemCode}) added successfully.";
        }

        if ($action === 'update_item') {
            $id = (int) ($_POST['id'] ?? 0);
            $itemCode = strtoupper(trim((string) ($_POST['item_code'] ?? '')));
            $itemName = trim((string) ($_POST['item_name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'Stationery'));
            $unit = trim((string) ($_POST['unit'] ?? 'Pcs'));
            $reorderLevel = max(0, (float) ($_POST['reorder_level'] ?? 0));
            $purchaseRate = max(0, (float) ($_POST['purchase_rate'] ?? 0));
            $storeLocation = trim((string) ($_POST['store_location'] ?? ''));

            if ($id <= 0 || $itemCode === '' || $itemName === '') {
                throw new \RuntimeException('Valid Item code and name are required.');
            }

            // Check duplicate code for another active item
            $dup = $pdo->prepare("SELECT id FROM inventory_items WHERE item_code = ? AND id != ? AND is_active = 1");
            $dup->execute([$itemCode, $id]);
            if ($dup->fetch()) {
                throw new \RuntimeException("Item code '{$itemCode}' is already in use by another item.");
            }

            // Get current qty to re-evaluate status
            $cur = $pdo->prepare("SELECT current_quantity FROM inventory_items WHERE id = ?");
            $cur->execute([$id]);
            $curQty = (float) ($cur->fetchColumn() ?: 0);
            $status = ($curQty <= $reorderLevel && $reorderLevel > 0) ? 'Low Stock' : ($curQty > 0 ? 'In Stock' : 'Out of Stock');

            $stmt = $pdo->prepare("UPDATE inventory_items SET 
                item_code = ?, item_name = ?, category = ?, unit = ?, 
                reorder_level = ?, purchase_rate = ?, unit_price = ?, store_location = ?, status = ?, updated_at = NOW() 
                WHERE id = ?");
            $stmt->execute([
                $itemCode, $itemName, $category, $unit,
                $reorderLevel, $purchaseRate, $purchaseRate,
                $storeLocation, $status, $id
            ]);

            $success = "Item '{$itemName}' updated successfully.";
        }

        if ($action === 'delete_item') {
            if (!$canDelete && !$isOwner) {
                throw new \RuntimeException('You do not have permission to deactivate inventory items.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE inventory_items SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
                $success = 'Item deactivated successfully.';
            }
        }

        if ($action === 'record_transaction') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $txnType = trim((string) ($_POST['transaction_type'] ?? ''));
            $quantity = (float) ($_POST['quantity'] ?? 0);
            $department = trim((string) ($_POST['department'] ?? ''));
            $issuedTo = trim((string) ($_POST['issued_to'] ?? ''));
            $txnDate = trim((string) ($_POST['transaction_date'] ?? date('Y-m-d')));
            $remarks = trim((string) ($_POST['remarks'] ?? ''));

            $allowedTypes = ['Purchase', 'Issue', 'Return', 'Opening', 'Adjustment'];
            if ($itemId <= 0 || !in_array($txnType, $allowedTypes, true)) {
                throw new \RuntimeException('Select a valid item and transaction type.');
            }
            if ($quantity <= 0) {
                throw new \RuntimeException('Quantity must be greater than zero.');
            }

            // Fetch current stock
            $stmt = $pdo->prepare("SELECT current_quantity, reorder_level, item_name FROM inventory_items WHERE id = ? AND is_active = 1");
            $stmt->execute([$itemId]);
            $itemData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$itemData) {
                throw new \RuntimeException('Selected item not found or is inactive.');
            }

            $currentQty = (float) $itemData['current_quantity'];
            $reorderLevel = (float) $itemData['reorder_level'];

            if ($txnType === 'Issue' && $quantity > $currentQty) {
                throw new \RuntimeException("Insufficient stock! Available: {$currentQty}, requested to issue: {$quantity}.");
            }

            $delta = 0.0;
            if (in_array($txnType, ['Purchase', 'Return', 'Opening'], true)) {
                $delta = $quantity;
            } elseif (in_array($txnType, ['Issue', 'Adjustment'], true)) {
                $delta = -$quantity;
            }

            $newQty = max(0.0, $currentQty + $delta);
            $newStatus = ($newQty <= $reorderLevel && $reorderLevel > 0) ? 'Low Stock' : ($newQty > 0 ? 'In Stock' : 'Out of Stock');

            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO inventory_transactions 
                    (item_id, transaction_type, quantity, department, issued_to, transaction_date, remarks, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$itemId, $txnType, $quantity, $department, $issuedTo, $txnDate, $remarks ?: null, (int)($user['id'] ?? 0)]);

                $pdo->prepare("UPDATE inventory_items SET 
                    current_quantity = ?, quantity = ?, status = ?, updated_at = NOW() 
                    WHERE id = ?")
                    ->execute([$newQty, (int)$newQty, $newStatus, $itemId]);

                $pdo->commit();
                $success = "{$txnType} of {$quantity} units recorded for '{$itemData['item_name']}'. New stock: {$newQty}.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === '' && $success !== '') {
        header('Location: inventory.php?success=' . urlencode($success));
        exit;
    }
}

if (isset($_GET['success'])) {
    $success = (string) $_GET['success'];
}

// ── Search & Filter State ──
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$filterCategory = trim((string) ($_GET['cat'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

$categoriesList = $pdo->query("SELECT category_name FROM inventory_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Build query
$whereSql = " WHERE is_active = 1";
$params = [];

if ($searchQuery !== '') {
    $whereSql .= " AND (item_code LIKE :q OR item_name LIKE :q OR store_location LIKE :q)";
    $params['q'] = '%' . $searchQuery . '%';
}
if ($filterCategory !== '') {
    $whereSql .= " AND category = :cat";
    $params['cat'] = $filterCategory;
}
if ($filterStatus === 'low') {
    $whereSql .= " AND current_quantity <= reorder_level AND reorder_level > 0";
} elseif ($filterStatus === 'in') {
    $whereSql .= " AND current_quantity > reorder_level";
} elseif ($filterStatus === 'out') {
    $whereSql .= " AND current_quantity = 0";
}

$itemsStmt = $pdo->prepare("SELECT * FROM inventory_items {$whereSql} ORDER BY item_code ASC");
$itemsStmt->execute($params);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Overall KPIs
$totalItems = (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE is_active = 1")->fetchColumn();
$lowStockItems = (int) $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND current_quantity <= reorder_level AND reorder_level > 0")->fetchColumn();
$totalValue = (float) $pdo->query("SELECT COALESCE(SUM(current_quantity * purchase_rate), 0) FROM inventory_items WHERE is_active = 1")->fetchColumn();
$issuesThisMonth = (float) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory_transactions WHERE transaction_type = 'Issue' AND MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE())")->fetchColumn();

// Recent transactions
$recentTransactions = $pdo->query("
    SELECT t.*, i.item_code, i.item_name, i.unit
    FROM inventory_transactions t
    JOIN inventory_items i ON i.id = t.item_id
    ORDER BY t.id DESC
    LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC);

// Dropdown item list for modal
$allItems = $pdo->query("SELECT id, item_code, item_name, current_quantity, unit FROM inventory_items WHERE is_active = 1 ORDER BY item_name ASC")->fetchAll(PDO::FETCH_ASSOC);
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
                    <span class="eyebrow">Stationery & Consumables</span>
                    <h1>Inventory Management</h1>
                    <p>Track store consumables, register purchases/issues, monitor low-stock thresholds, and manage stationery inventory.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <a href="inventory.php?export=csv" class="btn btn-sm btn-outline">⬇ Export CSV</a>
                    <button type="button" class="btn btn-sm" onclick="openTransactionModal()">+ Record Issue / Stock In</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="openItemModal()">+ Add New Item</button>
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
                <div class="kpi-label">Active Items</div>
                <div class="kpi-value"><?= $totalItems ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Consumables & Stationery</div>
            </div>
            <div class="kpi-card" style="<?= $lowStockItems > 0 ? 'border-left:4px solid #dc2626;' : '' ?>">
                <div class="kpi-label">Low Stock Alerts</div>
                <div class="kpi-value" style="<?= $lowStockItems > 0 ? 'color:#dc2626;' : '' ?>"><?= $lowStockItems ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Items at or below reorder level</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Total Inventory Value</div>
                <div class="kpi-value kpi-value-currency">₹ <?= number_format($totalValue, 2) ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Stock on hand × purchase rate</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Issued This Month</div>
                <div class="kpi-value"><?= number_format($issuesThisMonth, 0) ?></div>
                <div style="font-size:.78rem;color:#64748b;margin-top:.2rem;">Units disbursed to staff/departments</div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="panel" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <form method="get" action="inventory.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:200px;">
                    <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search by item code, name, or location..." style="width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                </div>
                <div>
                    <select name="cat" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Categories</option>
                        <?php foreach ($categoriesList as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <select name="status" style="padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.875rem;">
                        <option value="">All Stock Levels</option>
                        <option value="low" <?= $filterStatus === 'low' ? 'selected' : '' ?>>⚠️ Low Stock Only</option>
                        <option value="in" <?= $filterStatus === 'in' ? 'selected' : '' ?>>In Stock</option>
                        <option value="out" <?= $filterStatus === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Filter</button>
                <?php if ($searchQuery !== '' || $filterCategory !== '' || $filterStatus !== ''): ?>
                    <a href="inventory.php" class="btn btn-sm btn-soft">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Items Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <div class="toolbar" style="margin:0;">
                    <h3 style="margin:0;font-size:1rem;font-weight:700;">Stock Master Directory</h3>
                    <span style="color:#64748b;font-size:.85rem;"><?= count($items) ?> matching item(s)</span>
                </div>
            </div>

            <?php if (empty($items)): ?>
                <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
                    <div style="font-size:1.5rem;margin-bottom:.5rem;">📦</div>
                    No inventory items found. Click "<strong>+ Add New Item</strong>" to register stock.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th style="text-align:right">Current Qty</th>
                                <th style="text-align:right">Reorder Lvl</th>
                                <th style="text-align:right">Rate (₹)</th>
                                <th style="text-align:right">Total Value (₹)</th>
                                <th>Location</th>
                                <th style="text-align:center">Stock Status</th>
                                <th style="text-align:center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item):
                                $qty = (float) $item['current_quantity'];
                                $reorder = (float) $item['reorder_level'];
                                $rate = (float) $item['purchase_rate'];
                                $isLow = ($qty <= $reorder && $reorder > 0);
                                $isOut = ($qty <= 0);
                            ?>
                                <tr style="<?= $isOut ? 'background:#fff1f2;' : ($isLow ? 'background:#fef2f2;' : '') ?>">
                                    <td style="font-family:monospace;font-weight:700;color:#1e3a8a;"><?= e($item['item_code']) ?></td>
                                    <td>
                                        <strong><?= e($item['item_name']) ?></strong>
                                    </td>
                                    <td><span class="badge" style="background:#f1f5f9;color:#334155;"><?= e($item['category'] ?: 'Stationery') ?></span></td>
                                    <td><?= e($item['unit'] ?: 'Pcs') ?></td>
                                    <td style="text-align:right;font-size:1rem;font-weight:700;color:<?= $isOut ? '#dc2626' : ($isLow ? '#b91c1c' : '#0f172a') ?>">
                                        <?= number_format($qty, 2) ?>
                                    </td>
                                    <td style="text-align:right;color:#64748b;"><?= number_format($reorder, 2) ?></td>
                                    <td style="text-align:right;">₹ <?= number_format($rate, 2) ?></td>
                                    <td style="text-align:right;font-weight:600;">₹ <?= number_format($qty * $rate, 2) ?></td>
                                    <td><?= e($item['store_location'] ?: '—') ?></td>
                                    <td style="text-align:center;">
                                        <?php if ($isOut): ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:.75rem;font-weight:700;">Out of Stock</span>
                                        <?php elseif ($isLow): ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#ffedd5;color:#9a3412;font-size:.75rem;font-weight:700;">⚠️ Low Stock</span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#d1fae5;color:#065f46;font-size:.75rem;font-weight:600;">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;gap:.35rem;justify-content:center;">
                                            <button type="button" class="btn btn-sm btn-soft" title="Quick Issue" onclick="quickIssue(<?= (int)$item['id'] ?>, '<?= e(addslashes($item['item_name'])) ?>', <?= (float)$item['current_quantity'] ?>)">
                                                ⚡ Issue
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline" onclick='editItem(<?= json_encode([
                                                'id' => $item['id'],
                                                'item_code' => $item['item_code'],
                                                'item_name' => $item['item_name'],
                                                'category' => $item['category'],
                                                'unit' => $item['unit'],
                                                'reorder_level' => $item['reorder_level'],
                                                'purchase_rate' => $item['purchase_rate'],
                                                'store_location' => $item['store_location']
                                            ]) ?>)'>Edit</button>
                                            <?php if ($canDelete || $isOwner): ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to deactivate item <?= e(addslashes($item['item_name'])) ?>?');">
                                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" style="padding:.25rem .5rem;" title="Deactivate">✕</button>
                                                </form>
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

        <!-- Recent Transactions Log -->
        <div class="panel" style="overflow:auto;margin-top:1.25rem;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Recent Stock In / Issue History</h3>
                <span style="font-size:.8rem;color:#64748b;">Last 25 entries</span>
            </div>
            <?php if (empty($recentTransactions)): ?>
                <div style="padding:2rem;text-align:center;color:#94a3b8;">No stock transactions recorded yet.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Type</th>
                                <th style="text-align:right">Quantity</th>
                                <th>Department</th>
                                <th>Issued To / Supplier</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $txn): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= e($txn['transaction_date']) ?></td>
                                    <td style="font-family:monospace;font-weight:600;"><?= e($txn['item_code']) ?></td>
                                    <td><strong><?= e($txn['item_name']) ?></strong></td>
                                    <td>
                                        <?php
                                        $badgeStyle = match($txn['transaction_type']) {
                                            'Purchase' => 'background:#dbeafe;color:#1e40af;',
                                            'Issue' => 'background:#fee2e2;color:#991b1b;',
                                            'Return' => 'background:#d1fae5;color:#065f46;',
                                            'Opening' => 'background:#f1f5f9;color:#475569;',
                                            'Adjustment' => 'background:#fef3c7;color:#92400e;',
                                            default => 'background:#f1f5f9;color:#475569;',
                                        };
                                        ?>
                                        <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;font-size:.75rem;font-weight:600;<?= $badgeStyle ?>"><?= e($txn['transaction_type']) ?></span>
                                    </td>
                                    <td style="text-align:right;font-weight:700;color:<?= $txn['transaction_type'] === 'Issue' ? '#dc2626' : '#16a34a' ?>">
                                        <?= $txn['transaction_type'] === 'Issue' ? '-' : '+' ?><?= number_format((float) $txn['quantity'], 2) ?> <?= e($txn['unit'] ?? '') ?>
                                    </td>
                                    <td><?= e($txn['department'] ?: '—') ?></td>
                                    <td><?= e($txn['issued_to'] ?: '—') ?></td>
                                    <td style="color:#64748b;font-size:.85rem;"><?= e($txn['remarks'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ ADD / EDIT ITEM MODAL ═══════════════════ -->
<div id="itemModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:620px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 id="itemModalTitle" style="margin:0;font-size:1.1rem;font-weight:700;">Add Inventory Item</h2>
            <button type="button" class="icon-btn" onclick="closeModal('itemModal')">✕</button>
        </div>
        <form method="post" id="itemForm" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="itemFormAction" value="create_item">
            <input type="hidden" name="id" id="itemFormId" value="">
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Item Code *</label>
                    <input type="text" name="item_code" id="itemFormCode" required placeholder="e.g. STAT-005" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;font-family:monospace;text-transform:uppercase;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Category</label>
                    <select name="category" id="itemFormCategory" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                        <?php foreach ($categoriesList as $cat): ?>
                            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Item Name *</label>
                <input type="text" name="item_name" id="itemFormName" required placeholder="e.g. Spiral Notebook A4 (200 Pages)" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Unit</label>
                    <select name="unit" id="itemFormUnit" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                        <option value="Pcs">Pcs</option>
                        <option value="Box">Box</option>
                        <option value="Packet">Packet</option>
                        <option value="Ream">Ream</option>
                        <option value="Set">Set</option>
                        <option value="Bundle">Bundle</option>
                        <option value="Roll">Roll</option>
                        <option value="Bottle">Bottle</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Reorder Alert Level</label>
                    <input type="number" step="0.01" min="0" name="reorder_level" id="itemFormReorder" value="10" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;" id="openingRow">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Opening Stock Qty</label>
                    <input type="number" step="0.01" min="0" name="opening_quantity" id="itemFormOpening" value="0" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Purchase Rate (₹)</label>
                    <input type="number" step="0.01" min="0" name="purchase_rate" id="itemFormRate" value="0.00" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="margin-top:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Store Rack / Shelf Location</label>
                <input type="text" name="store_location" id="itemFormLocation" placeholder="e.g. Main Stationery Almirah - Rack 2" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;gap:.5rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('itemModal')">Cancel</button>
                <button type="submit" class="btn btn-sm" id="itemFormBtn">Save Item</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ RECORD TRANSACTION MODAL ═══════════════════ -->
<div id="transactionModal" class="modal-backdrop">
    <div class="modal-box" style="max-width:620px;background:#fff;border-radius:14px;box-shadow:0 25px 60px -15px rgba(0,0,0,.35);overflow:hidden;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;font-weight:700;">Record Stock Transaction (In / Issue)</h2>
            <button type="button" class="icon-btn" onclick="closeModal('transactionModal')">✕</button>
        </div>
        <form method="post" style="padding:1.5rem;background:#fff;margin:0;">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_transaction">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Item *</label>
                <select name="item_id" id="txnItemId" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                    <option value="">-- Select Inventory Item --</option>
                    <?php foreach ($allItems as $it): ?>
                        <option value="<?= (int) $it['id'] ?>">
                            <?= e($it['item_code']) ?> – <?= e($it['item_name']) ?> (Current Stock: <?= number_format((float)$it['current_quantity'], 2) ?> <?= e($it['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Transaction Type *</label>
                    <select name="transaction_type" id="txnType" required onchange="handleTxnTypeChange()" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                        <option value="Issue">Issue (Stock Out to Staff/Class)</option>
                        <option value="Purchase">Purchase (Stock In from Vendor)</option>
                        <option value="Return">Return (Return to Stock)</option>
                        <option value="Adjustment">Adjustment / Damaged</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Quantity *</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" required placeholder="0.00" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Transaction Date *</label>
                    <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
                <div>
                    <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;" id="deptLabel">Department / Section</label>
                    <input type="text" name="department" placeholder="e.g. Primary Wing, Accounts, Science Lab" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;" id="issuedToLabel">Issued To (Staff Member / Teacher / Student)</label>
                <input type="text" name="issued_to" placeholder="e.g. Ramesh Sharma (Teacher) or Vendor Name" style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;">
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Remarks / Reason</label>
                <textarea name="remarks" rows="2" placeholder="Optional notes, bill reference, or reason..." style="width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>

            <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('transactionModal')">Cancel</button>
                <button type="submit" class="btn btn-sm">Record Transaction</button>
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
function openItemModal() {
    document.getElementById('itemFormAction').value = 'create_item';
    document.getElementById('itemFormId').value = '';
    document.getElementById('itemFormCode').value = '';
    document.getElementById('itemFormName').value = '';
    document.getElementById('itemFormOpening').value = '0';
    document.getElementById('itemFormReorder').value = '10';
    document.getElementById('itemFormRate').value = '0';
    document.getElementById('itemFormLocation').value = '';
    document.getElementById('itemModalTitle').textContent = 'Add Inventory Item';
    document.getElementById('itemFormBtn').textContent = 'Create Item';
    var opRow = document.getElementById('openingRow');
    if (opRow) opRow.style.display = 'grid';
    openModal('itemModal');
}
function openTransactionModal() {
    openModal('transactionModal');
}
function quickIssue(itemId, itemName, currentStock) {
    var sel = document.getElementById('txnItemId');
    if (sel) sel.value = itemId;
    var tType = document.getElementById('txnType');
    if (tType) tType.value = 'Issue';
    handleTxnTypeChange();
    openModal('transactionModal');
}
function handleTxnTypeChange() {
    var type = document.getElementById('txnType').value;
    var toLabel = document.getElementById('issuedToLabel');
    if (toLabel) {
        if (type === 'Purchase') {
            toLabel.textContent = 'Supplier / Vendor Name';
        } else if (type === 'Issue') {
            toLabel.textContent = 'Issued To (Staff Member / Teacher)';
        } else {
            toLabel.textContent = 'Person Involved';
        }
    }
}
function editItem(row) {
    document.getElementById('itemFormAction').value = 'update_item';
    document.getElementById('itemFormId').value = row.id;
    document.getElementById('itemFormCode').value = row.item_code;
    document.getElementById('itemFormName').value = row.item_name;
    document.getElementById('itemFormCategory').value = row.category;
    document.getElementById('itemFormUnit').value = row.unit;
    document.getElementById('itemFormReorder').value = row.reorder_level;
    document.getElementById('itemFormRate').value = row.purchase_rate;
    document.getElementById('itemFormLocation').value = row.store_location;
    var opRow = document.getElementById('openingRow');
    if (opRow) opRow.style.display = 'grid';
    document.getElementById('itemModalTitle').textContent = 'Edit Inventory Item';
    document.getElementById('itemFormBtn').textContent = 'Update Item';
    openModal('itemModal');
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
