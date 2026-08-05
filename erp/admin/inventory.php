<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_admin_login();

$user = admin_user();

$pageTitle = 'Inventory Management';
$error = '';
$success = '';

// ── Ensure tables exist ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_code VARCHAR(50) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        category VARCHAR(100) DEFAULT '',
        unit VARCHAR(50) DEFAULT 'Pcs',
        opening_quantity DECIMAL(12,2) DEFAULT 0,
        current_quantity DECIMAL(12,2) DEFAULT 0,
        reorder_level DECIMAL(12,2) DEFAULT 0,
        purchase_rate DECIMAL(12,2) DEFAULT 0,
        store_location VARCHAR(255) DEFAULT '',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {}

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_item') {
            $itemCode = trim((string) ($_POST['item_code'] ?? ''));
            $itemName = trim((string) ($_POST['item_name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $unit = trim((string) ($_POST['unit'] ?? 'Pcs'));
            $openingQty = (float) ($_POST['opening_quantity'] ?? 0);
            $reorderLevel = (float) ($_POST['reorder_level'] ?? 0);
            $purchaseRate = (float) ($_POST['purchase_rate'] ?? 0);
            $storeLocation = trim((string) ($_POST['store_location'] ?? ''));

            if ($itemCode === '' || $itemName === '') throw new \RuntimeException('Item code and name are required.');

            $pdo->prepare("INSERT INTO inventory_items (item_code, item_name, category, unit, opening_quantity, current_quantity, reorder_level, purchase_rate, store_location) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$itemCode, $itemName, $category, $unit, $openingQty, $openingQty, $reorderLevel, $purchaseRate, $storeLocation]);
            $success = 'Item added successfully.';
        }

        if ($action === 'update_item') {
            $id = (int) ($_POST['id'] ?? 0);
            $itemCode = trim((string) ($_POST['item_code'] ?? ''));
            $itemName = trim((string) ($_POST['item_name'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $unit = trim((string) ($_POST['unit'] ?? 'Pcs'));
            $reorderLevel = (float) ($_POST['reorder_level'] ?? 0);
            $purchaseRate = (float) ($_POST['purchase_rate'] ?? 0);
            $storeLocation = trim((string) ($_POST['store_location'] ?? ''));

            if ($id <= 0 || $itemCode === '' || $itemName === '') throw new \RuntimeException('Item code and name are required.');

            $pdo->prepare("UPDATE inventory_items SET item_code=?, item_name=?, category=?, unit=?, reorder_level=?, purchase_rate=?, store_location=?, updated_at=NOW() WHERE id=?")
                ->execute([$itemCode, $itemName, $category, $unit, $reorderLevel, $purchaseRate, $storeLocation, $id]);
            $success = 'Item updated successfully.';
        }

        if ($action === 'delete_item') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE inventory_items SET is_active=0, updated_at=NOW() WHERE id=?")->execute([$id]);
                $success = 'Item deactivated.';
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
            if ($itemId <= 0 || !in_array($txnType, $allowedTypes, true)) throw new \RuntimeException('Select a valid item and transaction type.');
            if ($quantity <= 0) throw new \RuntimeException('Quantity must be greater than zero.');

            $pdo->prepare("INSERT INTO inventory_transactions (item_id, transaction_type, quantity, department, issued_to, transaction_date, remarks, created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$itemId, $txnType, $quantity, $department, $issuedTo, $txnDate, $remarks ?: null, (int) ($user['id'] ?? 0)]);

            // Update current_quantity
            $delta = 0.0;
            if (in_array($txnType, ['Purchase', 'Return', 'Opening'], true)) {
                $delta = $quantity;
            } elseif (in_array($txnType, ['Issue', 'Adjustment'], true)) {
                $delta = -$quantity;
            }
            $pdo->prepare("UPDATE inventory_items SET current_quantity = GREATEST(current_quantity + ?, 0), updated_at = NOW() WHERE id = ?")->execute([$delta, $itemId]);

            $success = 'Transaction recorded successfully.';
        }

    } catch (\Throwable $e) {
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

// ── Fetch data ──
$items = $pdo->query("SELECT * FROM inventory_items WHERE is_active = 1 ORDER BY item_code ASC")->fetchAll(PDO::FETCH_ASSOC);

$totalItems = count($items);
$lowStockItems = 0;
$totalValue = 0.0;
foreach ($items as $item) {
    if ((float) $item['current_quantity'] <= (float) $item['reorder_level']) {
        $lowStockItems++;
    }
    $totalValue += (float) $item['current_quantity'] * (float) $item['purchase_rate'];
}

// Recent transactions
$recentTransactions = $pdo->query("
    SELECT t.*, i.item_code, i.item_name
    FROM inventory_transactions t
    JOIN inventory_items i ON i.id = t.item_id
    ORDER BY t.id DESC
    LIMIT 20
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
                <div class="stack" style="gap:.55rem">
                    <span class="eyebrow">Module 14</span>
                    <h1>Inventory Management</h1>
                    <p>Track stock levels, record purchase/issue/return transactions, and monitor low stock alerts.</p>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button class="btn btn-sm" onclick="openModal('transactionModal')">+ Record Transaction</button>
                    <button class="btn btn-sm btn-outline" onclick="openModal('itemModal')">+ Add Item</button>
                </div>
            </div>
        </section>

        <?php if ($error !== ''): ?>
            <div class="flash" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="flash" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="kpi-grid" style="margin-bottom:1.25rem;">
            <div class="kpi-card">
                <div class="kpi-label">Total Items</div>
                <div class="kpi-value"><?= $totalItems ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Low Stock Items</div>
                <div class="kpi-value" style="color:#dc2626;"><?= $lowStockItems ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Total Value</div>
                <div class="kpi-value kpi-value-currency">₹ <?= number_format($totalValue, 2) ?></div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="panel" style="overflow:auto;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                <div class="toolbar">
                    <h3 style="margin:0;font-size:1rem;">Inventory Items</h3>
                    <span style="color:#64748b;font-size:.85rem;"><?= $totalItems ?> item(s)</span>
                </div>
            </div>

            <?php if (empty($items)): ?>
                <div style="padding:2rem;text-align:center;color:#94a3b8;">No inventory items found. Add one to get started.</div>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Item Code</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th style="text-align:right">Opening Qty</th>
                                <th style="text-align:right">Current Qty</th>
                                <th style="text-align:right">Reorder Level</th>
                                <th style="text-align:right">Rate</th>
                                <th>Location</th>
                                <th style="text-align:center">Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item):
                                $isLow = (float) $item['current_quantity'] <= (float) $item['reorder_level'];
                            ?>
                                <tr style="<?= $isLow ? 'background:#fef2f2;' : '' ?>">
                                    <td style="font-family:monospace;font-weight:600;"><?= e($item['item_code']) ?></td>
                                    <td><strong><?= e($item['item_name']) ?></strong></td>
                                    <td><?= e($item['category'] ?: '—') ?></td>
                                    <td><?= e($item['unit']) ?></td>
                                    <td style="text-align:right;"><?= number_format((float) $item['opening_quantity'], 2) ?></td>
                                    <td style="text-align:right;font-weight:700;"><?= number_format((float) $item['current_quantity'], 2) ?></td>
                                    <td style="text-align:right;"><?= number_format((float) $item['reorder_level'], 2) ?></td>
                                    <td style="text-align:right;">₹ <?= number_format((float) $item['purchase_rate'], 2) ?></td>
                                    <td><?= e($item['store_location'] ?: '—') ?></td>
                                    <td style="text-align:center;">
                                        <?php if ($isLow): ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:.75rem;font-weight:600;">Low Stock</span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;background:#d1fae5;color:#065f46;font-size:.75rem;font-weight:600;">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:.3rem;flex-wrap:nowrap;">
                                            <button class="btn btn-sm btn-outline" onclick='editItem(<?= json_encode([
                                                'id' => $item['id'],
                                                'item_code' => $item['item_code'],
                                                'item_name' => $item['item_name'],
                                                'category' => $item['category'],
                                                'unit' => $item['unit'],
                                                'reorder_level' => $item['reorder_level'],
                                                'purchase_rate' => $item['purchase_rate'],
                                                'store_location' => $item['store_location']
                                            ]) ?>)'>Edit</button>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Deactivate this item?');">
                                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
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

        <!-- Recent Transactions -->
        <div class="panel" style="overflow:auto;margin-top:1.25rem;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
                <h3 style="margin:0;font-size:1rem;">Recent Transactions</h3>
            </div>
            <?php if (empty($recentTransactions)): ?>
                <div style="padding:2rem;text-align:center;color:#94a3b8;">No transactions recorded yet.</div>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Type</th>
                                <th style="text-align:right">Quantity</th>
                                <th>Department</th>
                                <th>Issued To</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $txn): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?= e($txn['transaction_date']) ?></td>
                                    <td style="font-family:monospace;"><?= e($txn['item_code']) ?></td>
                                    <td><?= e($txn['item_name']) ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = match($txn['transaction_type']) {
                                            'Purchase' => 'background:#dbeafe;color:#1e40af;',
                                            'Issue' => 'background:#fee2e2;color:#991b1b;',
                                            'Return' => 'background:#d1fae5;color:#065f46;',
                                            'Opening' => 'background:#f1f5f9;color:#475569;',
                                            'Adjustment' => 'background:#fef3c7;color:#92400e;',
                                            default => 'background:#f1f5f9;color:#475569;',
                                        };
                                        ?>
                                        <span style="display:inline-flex;padding:.2em .6em;border-radius:999px;font-size:.75rem;font-weight:600;<?= $badgeClass ?>"><?= e($txn['transaction_type']) ?></span>
                                    </td>
                                    <td style="text-align:right;font-weight:600;"><?= number_format((float) $txn['quantity'], 2) ?></td>
                                    <td><?= e($txn['department'] ?: '—') ?></td>
                                    <td><?= e($txn['issued_to'] ?: '—') ?></td>
                                    <td style="max-width:200px;white-space:normal;"><?= e($txn['remarks'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ═══════════════════ ADD/EDIT ITEM MODAL ═══════════════════ -->
<div id="itemModal" class="modal-backdrop">
    <div class="modal" style="max-width:680px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Add Inventory Item</h2>
            <button class="icon-btn" onclick="closeModal('itemModal')">✕</button>
        </div>
        <form method="post" id="itemForm">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="itemFormAction" value="create_item">
            <input type="hidden" name="id" id="itemFormId" value="">
            <div class="field-grid">
                <div><label>Item Code *</label><input type="text" name="item_code" id="itemFormCode" required></div>
                <div><label>Item Name *</label><input type="text" name="item_name" id="itemFormName" required></div>
                <div><label>Category</label><input type="text" name="category" id="itemFormCategory" placeholder="e.g. Stationery, Lab"></div>
                <div><label>Unit</label>
                    <select name="unit" id="itemFormUnit">
                        <option value="Pcs">Pcs</option>
                        <option value="Kg">Kg</option>
                        <option value="Ltr">Ltr</option>
                        <option value="Box">Box</option>
                        <option value="Set">Set</option>
                        <option value="Mtr">Mtr</option>
                        <option value="Bag">Bag</option>
                        <option value="Bundle">Bundle</option>
                    </select>
                </div>
                <div><label>Opening Quantity</label><input type="number" step="0.01" min="0" name="opening_quantity" id="itemFormOpening" value="0"></div>
                <div><label>Reorder Level</label><input type="number" step="0.01" min="0" name="reorder_level" id="itemFormReorder" value="0"></div>
                <div><label>Purchase Rate (₹)</label><input type="number" step="0.01" min="0" name="purchase_rate" id="itemFormRate" value="0"></div>
                <div><label>Store Location</label><input type="text" name="store_location" id="itemFormLocation" placeholder="e.g. Main Store, Block A"></div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm" id="itemFormBtn">Create Item</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('itemModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ RECORD TRANSACTION MODAL ═══════════════════ -->
<div id="transactionModal" class="modal-backdrop">
    <div class="modal" style="max-width:680px;">
        <div class="modal-head">
            <h2 style="margin:0;font-size:1.1rem;">Record Transaction</h2>
            <button class="icon-btn" onclick="closeModal('transactionModal')">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="record_transaction">
            <div class="field-grid">
                <div><label>Item *</label>
                    <select name="item_id" required>
                        <option value="">Select Item</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"><?= e($item['item_code']) ?> – <?= e($item['item_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Transaction Type *</label>
                    <select name="transaction_type" required>
                        <option value="Purchase">Purchase</option>
                        <option value="Issue">Issue</option>
                        <option value="Return">Return</option>
                        <option value="Opening">Opening</option>
                        <option value="Adjustment">Adjustment</option>
                    </select>
                </div>
                <div><label>Quantity *</label><input type="number" step="0.01" min="0.01" name="quantity" required></div>
                <div><label>Date *</label><input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></div>
                <div><label>Department</label><input type="text" name="department" placeholder="e.g. Science Lab, Admin"></div>
                <div><label>Issued To</label><input type="text" name="issued_to" placeholder="Person name"></div>
            </div>
            <div style="margin-top:1rem;">
                <label>Remarks</label>
                <textarea name="remarks" rows="2" placeholder="Optional remarks..."></textarea>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm">Save Transaction</button>
                <button type="button" class="btn btn-sm btn-soft" onclick="closeModal('transactionModal')">Cancel</button>
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
function editItem(row) {
    document.getElementById('itemFormAction').value = 'update_item';
    document.getElementById('itemFormId').value = row.id;
    document.getElementById('itemFormCode').value = row.item_code;
    document.getElementById('itemFormName').value = row.item_name;
    document.getElementById('itemFormCategory').value = row.category;
    document.getElementById('itemFormUnit').value = row.unit;
    document.getElementById('itemFormOpening').value = '';
    document.getElementById('itemFormReorder').value = row.reorder_level;
    document.getElementById('itemFormRate').value = row.purchase_rate;
    document.getElementById('itemFormLocation').value = row.store_location;
    document.getElementById('itemFormBtn').textContent = 'Update Item';
    document.querySelector('#itemModal .modal-head h2').textContent = 'Edit Inventory Item';
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
