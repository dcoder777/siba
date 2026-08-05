<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "Step 1: requiring bootstrap...<br>\n";
require __DIR__ . '/bootstrap.php';
echo "Step 2: bootstrap OK<br>\n";

require_admin_login();
echo "Step 3: login OK<br>\n";

$user = admin_user();
echo "Step 4: user: " . ($user['name'] ?? 'unknown') . "<br>\n";

echo "Step 5: pdo type=" . get_class($pdo) . "<br>\n";

echo "Step 6: DB name=" . $pdo->query('SELECT DATABASE()')->fetchColumn() . "<br>\n";

echo "Step 7: SHOW TABLES...<br>\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Total tables: " . count($tables) . "<br>\n";

foreach (['fee_heads','fee_structures','fee_structure_items','expense_categories','vendors','expenses'] as $t) {
    echo "$t: " . (in_array($t, $tables) ? 'EXISTS' : 'MISSING') . "<br>\n";
}

echo "<br><b>Test passed.</b> If you see this, bootstrap and DB work fine.";
