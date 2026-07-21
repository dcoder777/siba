<?php

declare(strict_types=1);

use core\Database;

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = Database::connection($config['db']);

$pdo->beginTransaction();
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_role_assignments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_role (user_id, role_id),
            CONSTRAINT fk_ura_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ura_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        )'
    );

    $requiredRoles = ['owner', 'admin', 'parent', 'teacher', 'driver', 'finance', 'hr'];
    $stmt = $pdo->prepare('INSERT IGNORE INTO roles (name) VALUES (:name)');
    foreach ($requiredRoles as $role) {
        $stmt->execute(['name' => $role]);
    }

    // Backfill each user's current primary role into role assignments.
    $backfill = $pdo->prepare(
        'INSERT INTO user_role_assignments (user_id, role_id, is_active, created_at, updated_at)
         VALUES (:user_id, :role_id, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()'
    );
    $users = $pdo->query('SELECT id, role_id FROM users')->fetchAll();
    foreach ($users as $user) {
        $backfill->execute([
            'user_id' => (int) $user['id'],
            'role_id' => (int) $user['role_id'],
        ]);
    }

    $pdo->commit();
    echo "R1 role migration completed.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
