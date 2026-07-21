<?php

declare(strict_types=1);

namespace core;

class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($scriptDir !== '/' && str_starts_with(strtolower($path), strtolower($scriptDir))) {
            $path = substr($path, strlen($scriptDir));
        }

        return '/' . trim($path, '/');
    }

    public static function json(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || trim($body) === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? '';
        }
        if (preg_match('/Bearer\s+(.+)/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }
        return null;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function intQuery(string $key, int $default): int
    {
        $value = self::query($key, $default);
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }
}
