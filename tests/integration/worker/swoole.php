<?php

// Starts the Swoole server.

spl_autoload_register(static function (string $class): void {
    $prefix = 'WorkerProbe\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Quiote\Runtime\Kernel::create([
    'app_dir' => __DIR__ . '/app',
    'env' => 'production',
    'context' => 'web',
    'worker_runtime' => 'swoole',
])->run();
