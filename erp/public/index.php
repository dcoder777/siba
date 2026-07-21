<?php

declare(strict_types=1);

use core\Database;
use core\Request;
use core\Response;
use core\Router;

ini_set('display_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): never {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error',
            'error' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);
    }
});

$config = require dirname(__DIR__) . '/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (Request::method() === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$router = new Router();
$pdo = Database::connection($config['db']);

$context = [
    'config' => $config,
    'pdo' => $pdo,
];

$registerApiRoutes = require dirname(__DIR__) . '/routes/api.php';
$registerApiRoutes($router, $context);

$path = Request::path();
if ($path === '/' || $path === '/index.php') {
    Response::json([
        'success' => true,
        'message' => 'SIBA School ERP API',
        'version' => 'v1',
        'base_url' => $config['app']['url'],
        'health' => '/api/v1/health',
    ]);
    exit;
}

if ($path === '/api/v1/health') {
    Response::json([
        'success' => true,
        'status' => 'ok',
        'timestamp' => date(DATE_ATOM),
    ]);
    exit;
}

$router->dispatch(Request::method(), $path, $context);
