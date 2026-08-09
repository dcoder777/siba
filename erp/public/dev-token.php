<?php
/**
 * SIBA ERP — Static Development Bearer Token
 * 
 * Run this ONCE to insert a long-lived dev token into the database:
 *   php erp/public/dev-token.php
 * 
 * Token (use in Authorization header):
 *   Bearer siba-dev-static-token-2026
 * 
 * This token is for DEVELOPMENT ONLY. Never use in production.
 */

declare(strict_types=1);
require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../core/Database.php';

$pdo = Database::connect($config['database']);

$plainToken = 'siba-dev-static-token-2026';
$tokenHash  = hash('sha256', $plainToken);
$expiresAt  = date('Y-m-d H:i:s', strtotime('+10 years'));

// Find or create owner user (role_id = 1 assumed to be owner/admin)
$owner = $pdo->query("SELECT id FROM users WHERE role_id = 1 LIMIT 1")->fetch();

if (!$owner) {
    // Fallback: find any active user
    $owner = $pdo->query("SELECT id FROM users WHERE is_active = 1 LIMIT 1")->fetch();
}

if (!$owner) {
    echo "ERROR: No active user found in the database. Create a user first.\n";
    exit(1);
}

$userId = (int) $owner['id'];

// Remove any existing dev token
$pdo->prepare("DELETE FROM api_tokens WHERE token = ?")->execute([$tokenHash]);

// Insert the static dev token
$pdo->prepare("INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")
    ->execute([$userId, $tokenHash, $expiresAt]);

echo "============================================\n";
echo "  SIBA ERP — Static Dev Token Created\n";
echo "============================================\n";
echo "\n";
echo "  Token:    siba-dev-static-token-2026\n";
echo "  User ID:  $userId\n";
echo "  Expires:  $expiresAt (10 years)\n";
echo "\n";
echo "  Usage (cURL):\n";
echo "  curl -H \"Authorization: Bearer siba-dev-static-token-2026\" \\\n";
echo "       https://sibapublicschool.com/erp/public/api/v1/students\n";
echo "\n";
echo "  Usage (Swagger UI):\n";
echo "  Click 'Authorize' → enter: siba-dev-static-token-2026\n";
echo "\n";
echo "  ⚠  DEVELOPMENT ONLY — remove before production deploy\n";
echo "============================================\n";
