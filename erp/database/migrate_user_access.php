<?php

declare(strict_types=1);

use core\Database;

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = Database::connection($config['db']);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS user_module_access (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        module_key VARCHAR(50) NOT NULL,
        can_access TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_module (user_id, module_key),
        CONSTRAINT fk_user_module_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$defaultModules = [
    'owner' => ['students', 'academics', 'finance', 'operations', 'hr', 'reports'],
    'admin' => ['students', 'academics', 'finance', 'operations', 'hr', 'reports'],
    'parent' => ['students', 'academics', 'finance', 'reports'],
    'teacher' => ['students', 'academics', 'reports'],
    'driver' => ['operations', 'reports'],
    'finance' => ['students', 'finance', 'hr', 'reports'],
    'hr' => ['hr', 'reports'],
];

$users = $pdo->query(
    'SELECT u.id, r.name AS role_name
     FROM users u
     JOIN roles r ON r.id = u.role_id'
)->fetchAll();

$ins = $pdo->prepare(
    'INSERT IGNORE INTO user_module_access (user_id, module_key, can_access, created_at, updated_at)
     VALUES (:user_id, :module_key, 1, NOW(), NOW())'
);

foreach ($users as $u) {
    $modules = $defaultModules[$u['role_name']] ?? [];
    foreach ($modules as $module) {
        $ins->execute([
            'user_id' => $u['id'],
            'module_key' => $module,
        ]);
    }
}

echo "User access migration completed.\n";
