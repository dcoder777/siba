<?php

declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        static $values = null;

        if ($values === null) {
            $values = [];
            $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            if (file_exists($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $values[trim($k)] = trim($v);
                }
            }
        }

        return $values[$key] ?? getenv($key) ?: $default;
    }
}

return [
    'app' => [
        'env' => env('APP_ENV', 'production'),
        'debug' => env('APP_DEBUG', 'false') === 'true',
        'url' => env('APP_URL', 'http://localhost'),
    ],
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', '3306'),
        'name' => env('DB_NAME', ''),
        'user' => env('DB_USER', ''),
        'pass' => env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'auth' => [
        'jwt_secret' => env('JWT_SECRET', 'unsafe-dev-secret'),
        'token_ttl_hours' => (int) env('TOKEN_TTL_HOURS', '24'),
    ],
];
