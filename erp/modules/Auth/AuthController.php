<?php

declare(strict_types=1);

namespace modules\Auth;

use core\Auth;
use core\Controller;
use core\Request;

class AuthController extends Controller
{
    public function login(): void
    {
        $payload = Request::json();
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->fail('Email and password are required', 422);
            return;
        }

        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $auth = new Auth($this->pdo, $config['auth']);
        $result = $auth->login($email, $password);

        if ($result === null) {
            $this->fail('Invalid credentials', 401);
            return;
        }

        $this->ok($result, 'Login successful');
    }

    public function me(array $context): void
    {
        $this->ok(['user' => $context['user'] ?? null], 'Current user');
    }
}
