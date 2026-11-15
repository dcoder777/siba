<?php

declare(strict_types=1);

namespace modules\Inventory;

use core\Controller;
use core\Request;
use PDO;

class InventoryController extends Controller
{
    public function listItems(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = trim((string) Request::query('q', ''));
        $category = trim((string) Request::query('category', ''));

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(item_name LIKE :q OR item_code LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($category !== '') {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM inventory_items' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $stmt = $this->pdo->prepare(
            'SELECT * FROM inventory_items' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'items');
    }

    public function createItem(): void
    {
        $data = Request::json();
        $name = trim((string) ($data['item_name'] ?? ''));
        if ($name === '') {
            $this->fail('item_name is required', 422);
            return;
        }

        $code = trim((string) ($data['item_code'] ?? ''));
        if ($code === '') {
            $code = 'ITM-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }

        $qty = (float) ($data['quantity'] ?? $data['opening_quantity'] ?? 0);
        $rate = (float) ($data['unit_price'] ?? $data['purchase_rate'] ?? 0);
        $reorder = (float) ($data['reorder_level'] ?? 5);

        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_items (
                item_code, item_name, category, unit, opening_quantity, current_quantity,
                reorder_level, purchase_rate, store_location, is_active, quantity, unit_price, status, created_at, updated_at
            ) VALUES (
                :code, :name, :cat, :unit, :open_qty, :cur_qty,
                :reorder, :rate, :loc, 1, :qty, :unit_price, "In Stock", NOW(), NOW()
            )'
        );

        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'cat' => $data['category'] ?? 'Stationery',
            'unit' => $data['unit'] ?? 'Pcs',
            'open_qty' => $qty,
            'cur_qty' => $qty,
            'reorder' => $reorder,
            'rate' => $rate,
            'loc' => $data['store_location'] ?? 'Main Store Room',
            'qty' => (int) $qty,
            'unit_price' => $rate,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->ok(['id' => $id, 'item_code' => $code], 'Inventory item created successfully');
    }

    public function getItem(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $item = $stmt->fetch();
        if (!$item) {
            $this->fail('Inventory item not found', 404);
            return;
        }

        // Fetch recent transactions
        $txStmt = $this->pdo->prepare(
            'SELECT * FROM inventory_transactions WHERE item_id = :id ORDER BY id DESC LIMIT 20'
        );
        $txStmt->execute(['id' => $id]);
        $item['recent_transactions'] = $txStmt->fetchAll();

        $this->ok(['item' => $item]);
    }

    public function updateItem(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $data = Request::json();

        $stmt = $this->pdo->prepare(
            'UPDATE inventory_items SET
                item_name = COALESCE(:name, item_name),
                category = COALESCE(:cat, category),
                unit = COALESCE(:unit, unit),
                reorder_level = COALESCE(:reorder, reorder_level),
                purchase_rate = COALESCE(:rate, purchase_rate),
                unit_price = COALESCE(:rate, unit_price),
                store_location = COALESCE(:loc, store_location),
                updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'name' => isset($data['item_name']) ? trim((string) $data['item_name']) : null,
            'cat' => isset($data['category']) ? trim((string) $data['category']) : null,
            'unit' => isset($data['unit']) ? trim((string) $data['unit']) : null,
            'reorder' => isset($data['reorder_level']) ? (float) $data['reorder_level'] : null,
            'rate' => isset($data['unit_price']) ? (float) $data['unit_price'] : (isset($data['purchase_rate']) ? (float) $data['purchase_rate'] : null),
            'loc' => isset($data['store_location']) ? trim((string) $data['store_location']) : null,
        ]);

        $this->ok(['id' => $id], 'Inventory item updated successfully');
    }

    public function deleteItem(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare('DELETE FROM inventory_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $this->ok(['id' => $id], 'Inventory item deleted successfully');
    }

    public function listTransactions(): void
    {
        [$page, $limit, $offset] = $this->pagination();
        $itemId = Request::intQuery('item_id', 0);
        $type = trim((string) Request::query('type', ''));

        $where = [];
        $params = [];
        if ($itemId > 0) {
            $where[] = 't.item_id = :item_id';
            $params['item_id'] = $itemId;
        }
        if ($type !== '') {
            $where[] = 't.transaction_type = :type';
            $params['type'] = $type;
        }

        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM inventory_transactions t' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['c'];

        $sql = 'SELECT t.*, i.item_name, i.item_code, i.unit
                FROM inventory_transactions t
                LEFT JOIN inventory_items i ON i.id = t.item_id' . $whereSql . '
                ORDER BY t.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $this->listResponse($stmt->fetchAll(), $total, $page, $limit, 'transactions');
    }

    public function createTransaction(): void
    {
        $data = Request::json();
        $itemId = (int) ($data['item_id'] ?? 0);
        $qty = (float) ($data['quantity'] ?? 0);
        $type = ucfirst(strtolower(trim((string) ($data['transaction_type'] ?? 'Issue'))));

        if ($itemId <= 0 || $qty <= 0) {
            $this->fail('item_id and valid positive quantity are required', 422);
            return;
        }

        // Validate item
        $itemStmt = $this->pdo->prepare('SELECT id, current_quantity, quantity FROM inventory_items WHERE id = :id LIMIT 1');
        $itemStmt->execute(['id' => $itemId]);
        $item = $itemStmt->fetch();
        if (!$item) {
            $this->fail('Inventory item not found', 404);
            return;
        }

        $curQty = (float) ($item['current_quantity'] ?? $item['quantity'] ?? 0);
        if ($type === 'Issue' && $qty > $curQty) {
            $this->fail("Insufficient stock! Available: $curQty, Requested: $qty", 400);
            return;
        }

        $newQty = $type === 'Issue' ? ($curQty - $qty) : ($curQty + $qty);

        // Record transaction
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_transactions (
                item_id, transaction_type, quantity, department, issued_to, transaction_date, remarks, created_by, created_at
            ) VALUES (
                :item_id, :type, :qty, :dept, :issued_to, :tx_date, :remarks, 1, NOW()
            )'
        );
        $stmt->execute([
            'item_id' => $itemId,
            'type' => $type,
            'qty' => $qty,
            'dept' => $data['department'] ?? 'General',
            'issued_to' => $data['issued_to'] ?? 'Staff',
            'tx_date' => $data['transaction_date'] ?? date('Y-m-d'),
            'remarks' => $data['remarks'] ?? '',
        ]);
        $txId = (int) $this->pdo->lastInsertId();

        // Update item stock
        $upStmt = $this->pdo->prepare(
            'UPDATE inventory_items SET current_quantity = :cur, quantity = :q, status = :st, updated_at = NOW() WHERE id = :id'
        );
        $upStmt->execute([
            'cur' => $newQty,
            'q' => (int) $newQty,
            'st' => $newQty <= 0 ? 'Out of Stock' : ($newQty <= 5 ? 'Low Stock' : 'In Stock'),
            'id' => $itemId,
        ]);

        $this->ok([
            'transaction_id' => $txId,
            'item_id' => $itemId,
            'transaction_type' => $type,
            'previous_quantity' => $curQty,
            'new_quantity' => $newQty,
        ], 'Stock transaction recorded successfully');
    }
}
