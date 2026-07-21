<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

$config = require __DIR__ . '/config/config.php';

spl_autoload_register(function (string $class): void {
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    $path = $baseDir . $relative;
    if (file_exists($path)) {
        require_once $path;
    }
});

return $config;
