<?php

declare(strict_types=1);

namespace core;

use PDO;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler, bool $authRequired = true, array $roles = [], ?string $moduleKey = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'authRequired' => $authRequired,
            'roles' => $roles,
            'moduleKey' => $moduleKey,
        ];
    }

    public function dispatch(string $method, string $path, array $context = []): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches) !== 1) {
                continue;
            }

            $params = array_filter($matches, static fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);

            if ($route['authRequired']) {
                $auth = new Auth($context['pdo'], $context['config']['auth']);
                $user = $auth->authenticate();
                if ($user === null) {
                    $this->logAccessDenial('unauthorized', null, $method, $path);
                    Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
                    return;
                }
                if (!empty($route['roles']) && !in_array($user['role_name'], $route['roles'], true)) {
                    $this->logAccessDenial('forbidden_role', (int) ($user['id'] ?? 0), $method, $path);
                    Response::json(['success' => false, 'message' => 'Forbidden'], 403);
                    return;
                }
                if (!$this->hasModuleAccess($context['pdo'], $user, $route['moduleKey'])) {
                    $this->logAccessDenial('forbidden_module', (int) ($user['id'] ?? 0), $method, $path);
                    Response::json(['success' => false, 'message' => 'Forbidden (module access)'], 403);
                    return;
                }
                $context['user'] = $user;
            }

            call_user_func($route['handler'], $params, $context);
            return;
        }

        Response::json(['success' => false, 'message' => 'Route not found'], 404);
    }

    private function logAccessDenial(string $reason, ?int $userId, string $method, string $path): void
    {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $actor = $userId === null ? 'anonymous' : ('user:' . $userId);
        $line = sprintf("[%s] reason=%s actor=%s method=%s path=%s\n", gmdate('c'), $reason, $actor, $method, $path);
        @file_put_contents($dir . '/access_denials.log', $line, FILE_APPEND);
    }

    private function hasModuleAccess(PDO $pdo, array $user, ?string $moduleKey): bool
    {
        if ($moduleKey === null || $moduleKey === '') {
            return true;
        }
        if (($user['role_name'] ?? '') === 'admin') {
            return true;
        }

        static $tableExists = null;
        if ($tableExists === null) {
            $stmt = $pdo->query("SHOW TABLES LIKE 'user_module_access'");
            $tableExists = (bool) $stmt->fetch();
        }
        if ($tableExists === false) {
            return true;
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM user_module_access WHERE user_id = :user_id');
        $countStmt->execute(['user_id' => $user['id']]);
        $hasRules = ((int) ($countStmt->fetch()['c'] ?? 0)) > 0;
        if (!$hasRules) {
            return true;
        }

        $stmt = $pdo->prepare(
            'SELECT can_access
             FROM user_module_access
             WHERE user_id = :user_id AND module_key = :module_key
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $user['id'],
            'module_key' => $moduleKey,
        ]);
        $row = $stmt->fetch();
        return $row && (int) $row['can_access'] === 1;
    }
}
