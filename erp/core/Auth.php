<?php

declare(strict_types=1);

namespace core;

use PDO;

class Auth
{
    public function __construct(private PDO $pdo, private array $authConfig)
    {
    }

    public function login(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.password_hash, r.name AS role_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $token = bin2hex(random_bytes(40));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $this->authConfig['token_ttl_hours'] . ' hours'));

        $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)'
        )->execute([
            'user_id' => $user['id'],
            'token' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role_name'],
            ],
        ];
    }

    public function authenticate(): ?array
    {
        $plainToken = Request::bearerToken();
        if ($plainToken === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, r.name AS role_name, t.expires_at
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE t.token = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => hash('sha256', $plainToken)]);
        $tokenRecord = $stmt->fetch();

        if (!$tokenRecord || strtotime($tokenRecord['expires_at']) < time()) {
            return null;
        }

        return [
            'id' => (int) $tokenRecord['id'],
            'name' => $tokenRecord['name'],
            'email' => $tokenRecord['email'],
            'role_name' => $tokenRecord['role_name'],
        ];
    }
}
